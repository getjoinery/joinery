<?php
/**
 * InstallJobExecutor — the plane-side bootstrap runner for install_node jobs.
 *
 * A machine we create has no agent yet, so its first install cannot travel the
 * agent channel. This runs that one job from the plane, over the root password
 * the provision sealed onto its row (specs/keyless_provisioning.md) — the
 * minimal capability that lets everything after it (the agent joins, is
 * approved, takes over) happen.
 *
 * Deliberately narrow, so it can never grow into a general executor by accident:
 *
 *   - It runs ONE job type, install_node, and nothing else. install_node jobs
 *     are created in status 'queued' (ManagementJob::createJob), which the
 *     node-agent local queue's claim ('pending' only) never matches, so the
 *     two never contend for the same job.
 *   - It handles the fresh-install shape: 'local' steps run here on the plane,
 *     'ssh' steps run on the target over the sealed password. A from_backup
 *     job (scp transfers, steps addressed to a different source node) is
 *     refused with a clear message rather than half-run — that is a later
 *     increment.
 *   - The password is unsealed only in memory and handed to ssh through the
 *     SSHPASS environment variable, never on a command line.
 *
 * It writes the same mjb_output / mjb_status contract the agent's runner wrote,
 * so JobResultProcessor::process_install_node reads a completed job unchanged.
 *
 * @version 1.1 - a job whose parameters ask for from_backup or bare-metal is refused up front
 * @version 1.0
 */

class InstallJobExecutorException extends Exception {}

class InstallJobExecutor {

	/**
	 * Atomically claim the oldest queued install_node job. Returns a
	 * ManagementJob (now 'running') or null when there is nothing to do.
	 * FOR UPDATE SKIP LOCKED lets several workers run side by side without ever
	 * handing the same job to two of them.
	 */
	public static function claim_next() {
		$db = DbConnector::get_instance()->get_db_link();
		$db->beginTransaction();
		try {
			$sel = $db->query(
				"SELECT mjb_id FROM mjb_management_jobs " .
				"WHERE mjb_status = 'queued' AND mjb_job_type = 'install_node' " .
				"AND mjb_delete_time IS NULL " .
				"ORDER BY mjb_id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
			);
			$row = $sel->fetch(PDO::FETCH_ASSOC);
			if (!$row) { $db->commit(); return null; }

			$upd = $db->prepare(
				"UPDATE mjb_management_jobs " .
				"SET mjb_status = 'running', mjb_started_time = now(), mjb_update_time = now() " .
				"WHERE mjb_id = ? AND mjb_status = 'queued'"
			);
			$upd->execute([$row['mjb_id']]);
			$claimed = $upd->rowCount() === 1;
			$db->commit();
			if (!$claimed) { return null; }
			return new ManagementJob((int)$row['mjb_id'], TRUE);
		} catch (Exception $e) {
			$db->rollBack();
			throw $e;
		}
	}

	/**
	 * Claim and run one job. Returns true if a job was run (so a caller can loop
	 * until it returns false), false when the queue is empty.
	 */
	public static function run_once() {
		$job = self::claim_next();
		if ($job === null) { return false; }
		(new self())->execute($job);
		return true;
	}

	/**
	 * Run a claimed install_node job to completion, writing output and the
	 * terminal status. Never throws for a step failure — it records it and
	 * fails the job, the way the node runner did.
	 */
	public function execute($job) {
		$node = new ManagedNode((int)$job->get('mjb_mgn_node_id'), TRUE);
		if (!$node->key) {
			$this->finish($job, false, 'The install job names no live target node.');
			return;
		}

		// Shape first: only a fresh docker install is runnable here. A
		// from_backup job carries scp steps, and bare metal needs the
		// pre-stage/user-switch dance a keyless box cannot do — it would die
		// mid-run at "Pre-stage user1" with a FATAL about authorized_keys.
		// Refusing on the job's own parameters says so in one line.
		$params = json_decode((string)$job->get('mjb_parameters'), true);
		$params = is_array($params) ? $params : array();
		$mode = (string)($params['mode'] ?? 'fresh');
		$docker_mode = (string)($params['docker_mode'] ?? 'docker');
		if ($mode !== 'fresh' || $docker_mode !== 'docker') {
			$this->finish($job, false,
				"The bootstrap executor runs fresh docker installs only; this job asks for mode "
				. "'{$mode}' on '{$docker_mode}'. from_backup and bare-metal wait on a later increment "
				. "(specs/keyless_provisioning.md).");
			return;
		}

		$password = $this->resolve_password($node);
		if ($password === null) {
			$this->finish($job, false,
				'No sealed root password for node #' . $node->key . '. The bootstrap executor ' .
				'runs only keyless provisions (a password sealed on the provision row); this job ' .
				'has none — it may predate keyless provisioning or its password was already erased.');
			return;
		}

		$ctx = array(
			'job_node' => (int)$node->key,
			'host'     => trim((string)$node->get('mgn_host')),
			'port'     => (int)($node->get('mgn_ssh_port') ?: 22),
			'user'     => (string)($node->get('mgn_ssh_user') ?: 'root'),
			'password' => $password,
		);
		if ($ctx['host'] === '') {
			$this->finish($job, false, 'The target node has no host address.');
			return;
		}

		$commands = json_decode((string)$job->get('mjb_commands'), true);
		$steps = is_array($commands) && isset($commands['steps']) && is_array($commands['steps'])
			? $commands['steps'] : array();

		$main = array();
		$teardown = array();
		foreach ($steps as $s) {
			if (!empty($s['teardown'])) { $teardown[] = $s; } else { $main[] = $s; }
		}
		$total = count($main);

		$ok = true;
		$fail_message = '';
		foreach ($main as $i => $step) {
			$label = (string)($step['label'] ?? '');
			$this->append($job, "\n=== [Step " . ($i + 1) . "/{$total}] {$label} ===\n", $i);
			list($out, $code, $err) = $this->run_step($step, $ctx);
			if ($out !== '') { $this->append($job, $out . "\n", $i); }
			if ($code !== 0) {
				if (!empty($step['continue_on_error'])) {
					$this->append($job, "[ERROR (continuing): {$err}]\n", $i);
					continue;
				}
				$this->append($job, "[FAILED: {$err}]\n", $i);
				$ok = false;
				$fail_message = 'Step ' . ($i + 1) . " ({$label}) failed"
					. ($err !== '' ? ": {$err}" : '');
				break;
			}
		}

		// Teardown always runs and never changes the outcome — same as the node
		// runner. Its appends reuse the last index so progress never rewinds.
		if ($teardown) {
			$last = max(0, $total - 1);
			$this->append($job, "\n=== Teardown ===\n", $last);
			foreach ($teardown as $step) {
				$this->append($job, '--- ' . (string)($step['label'] ?? '') . " ---\n", $last);
				list($out, $code, $err) = $this->run_step($step, $ctx);
				if ($out !== '') { $this->append($job, $out . "\n", $last); }
				if ($code !== 0) { $this->append($job, "[teardown error (ignored): {$err}]\n", $last); }
			}
		}

		$this->finish($job, $ok, $fail_message);
	}

	/**
	 * Run one step. Returns [output, exit_code, error_message].
	 * A non-fresh shape (scp, or an ssh step addressed to another node) is
	 * refused rather than half-attempted.
	 */
	private function run_step($step, $ctx) {
		$type = (string)($step['type'] ?? '');
		$timeout = (int)($step['timeout'] ?? 1800);

		if ($type === 'local') {
			list($out, $code) = $this->shell((string)($step['cmd'] ?? ''), array(), $timeout);
			return array($out, $code, $code !== 0 ? "local command exited {$code}" : '');
		}

		if ($type === 'ssh') {
			if (!empty($step['node_id']) && (int)$step['node_id'] !== $ctx['job_node']) {
				return array('', 1,
					'a step addressed to another node is not supported by the bootstrap executor yet '
					. '(this is a from_backup transfer); run a fresh install for now');
			}
			$ssh = 'sshpass -e ssh'
				. ' -o StrictHostKeyChecking=accept-new'
				. ' -o UserKnownHostsFile=/dev/null'
				. ' -o ConnectTimeout=20'
				. ' -p ' . escapeshellarg((string)$ctx['port'])
				. ' ' . escapeshellarg($ctx['user'] . '@' . $ctx['host'])
				. ' ' . escapeshellarg((string)($step['cmd'] ?? ''));
			list($out, $code) = $this->shell($ssh, array('SSHPASS' => $ctx['password']), $timeout);
			return array($out, $code, $code !== 0 ? "ssh step exited {$code}" : '');
		}

		if ($type === 'scp') {
			return array('', 1,
				'scp transfers are not supported by the bootstrap executor yet (from_backup); '
				. 'run a fresh install for now');
		}

		return array('', 1, "unknown step type '{$type}'");
	}

	/**
	 * Run a shell command with a wall-clock timeout, merging stderr into the
	 * captured output. Extra environment (SSHPASS) is passed to the child only,
	 * never exported here or placed on a command line.
	 */
	private function shell($cmd, array $env_extra, $timeout_secs) {
		$timeout_secs = max(1, (int)$timeout_secs);
		$wrapped = 'timeout ' . $timeout_secs . ' bash -c ' . escapeshellarg($cmd . ' 2>&1');

		$env = array();
		foreach ($_ENV as $k => $v) { $env[$k] = $v; }
		if (empty($env['PATH'])) { $env['PATH'] = '/usr/local/bin:/usr/bin:/bin'; }
		foreach ($env_extra as $k => $v) { $env[$k] = $v; }

		$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$proc = @proc_open($wrapped, $descriptors, $pipes, null, $env);
		if (!is_resource($proc)) {
			return array('could not start a subprocess for this step', 127);
		}
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		return array($out . $err, $code);
	}

	/**
	 * The node's sealed root password, unsealed, or null when there is none or
	 * it cannot be read back.
	 */
	private function resolve_password($node) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT cvp_root_pass_sealed FROM cvp_customer_cloud_provisions " .
			"WHERE cvp_mgn_node_id = ? AND cvp_delete_time IS NULL " .
			"ORDER BY cvp_id DESC LIMIT 1"
		);
		$q->execute([$node->key]);
		$sealed = $q->fetchColumn();
		if (!$sealed) { return null; }
		$box = new SecretBox();
		$opened = $box->open($sealed);
		return $opened['state'] === 'ok' ? $opened['value'] : null;
	}

	/** Append to mjb_output and move the progress counter — the runner contract. */
	private function append($job, $text, $step_index) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"UPDATE mjb_management_jobs " .
			"SET mjb_output = COALESCE(mjb_output, '') || ?, mjb_current_step = ?, mjb_update_time = now() " .
			"WHERE mjb_id = ?"
		);
		$q->execute([$text, (int)$step_index, $job->key]);
	}

	/** Write the terminal status. JobResultProcessor parses the output later. */
	private function finish($job, $ok, $message) {
		$db = DbConnector::get_instance()->get_db_link();
		if ($ok) {
			$db->prepare(
				"UPDATE mjb_management_jobs SET mjb_status = 'completed', " .
				"mjb_completed_time = now(), mjb_update_time = now() WHERE mjb_id = ?"
			)->execute([$job->key]);
		} else {
			$db->prepare(
				"UPDATE mjb_management_jobs SET mjb_status = 'failed', mjb_error_message = ?, " .
				"mjb_completed_time = now(), mjb_update_time = now() WHERE mjb_id = ?"
			)->execute([mb_substr((string)$message, 0, 4000), $job->key]);
		}
	}
}
