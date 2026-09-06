<?php
/**
 * InstallJobExecutor — the plane-side bootstrap runner.
 *
 * A machine we create has no agent yet, so its first install cannot travel the
 * agent channel. This runs the bootstrap from the plane, over the install
 * password the provision sealed onto its row (specs/keyless_provisioning.md) —
 * the minimal capability that lets everything after it (the agent joins, is
 * approved, takes over) happen — and, once the agent is admitted, the one job
 * that retires that same password.
 *
 * Deliberately narrow, so it can never grow into a general executor by accident:
 *
 *   - It runs the bootstrap job types (ManagementJob::BOOTSTRAP_JOB_TYPES:
 *     install_node and retire_install_password) and nothing else. Both are
 *     created in status 'queued' (ManagementJob::createJob), which the
 *     node-agent local queue's claim ('pending' only) never matches, so the
 *     two never contend for the same job.
 *   - A retire_install_password job is not done when its steps succeed. The
 *     executor then tries the password once more and requires the machine to
 *     REFUSE it; only a refusal completes the job, and only a completed job
 *     lets the provision pipeline erase the password. A doubt keeps it.
 *   - It runs two step types: 'local' steps here on the plane, 'ssh' steps
 *     on the target over the sealed password. build_install_node emits one of
 *     each — the release pre-flight, then the single bootstrap session — for
 *     every shape (fresh, from_backup, bare; docker or bare-metal). A clone
 *     pulls its source over HTTPS inside that session, so there is no scp
 *     and no step addressed to another machine (specs/ssh_single_bootstrap.md).
 *   - The password is unsealed only in memory and handed to ssh through the
 *     SSHPASS environment variable, never on a command line.
 *   - A step may declare `stdin` => 'admin_password'. That is a NAME, not a
 *     value: the executor unseals the site admin account's first password from
 *     the provision row and writes it to that session's stdin, where the
 *     bootstrap's first line reads it into JOINERY_ADMIN_PASSWORD. Same reason
 *     as SSHPASS — mjb_commands is readable on the plane and job output is
 *     logged, so the one place a secret may travel is a pipe. A step that asks
 *     for it and cannot get it FAILS: an install that was meant to carry the
 *     buyer's password must not silently fall back to one nobody holds.
 *
 * It writes the same mjb_output / mjb_status contract the agent's runner wrote,
 * so JobResultProcessor::process_install_node reads a completed job unchanged.
 *
 * @version 1.6 - a step may ask for the site admin password on stdin, unsealed from the provision row
 *                and written to that one session (specs/hosted_trial_provisioning.md B1)
 * @version 1.5 - retire_install_password: the second bootstrap job type, claimed the same way, and
 *                completed only after a fresh login with the password is refused by the machine
 * @version 1.4 - every install shape runs: the shape refusal and the scp/other-node refusals are gone,
 *                since the bootstrap is one session and a clone travels over HTTPS
 * @version 1.3 - processes the job result itself once the job is finished, as the channel endpoint does
 *                for an agent-run job; a retried install otherwise completes and clears nothing
 * @version 1.2 - waits for the target to answer SSH before the first remote step: a provider reports
 *                'running' before sshd listens, and the install starts seconds after that
 * @version 1.1 - a job whose parameters ask for from_backup or bare-metal is refused up front
 * @version 1.0
 */

class InstallJobExecutorException extends Exception {}

class InstallJobExecutor {

	/** How long a freshly created instance is given to start answering SSH. */
	const SSH_READY_TIMEOUT_SECONDS = 300;
	/** Seconds between readiness probes. */
	const SSH_READY_PROBE_INTERVAL = 10;
	/** How long a retire job waits for the machine to refuse the password it just retired. */
	const REFUSAL_CONFIRM_TIMEOUT_SECONDS = 90;
	/** Seconds between refusal probes. */
	const REFUSAL_PROBE_INTERVAL = 5;

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
			$types = "'" . implode("','", ManagementJob::BOOTSTRAP_JOB_TYPES) . "'";
			$sel = $db->query(
				"SELECT mjb_id FROM mjb_management_jobs " .
				"WHERE mjb_status = 'queued' AND mjb_job_type IN ({$types}) " .
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
	 * Run a claimed bootstrap job to completion, writing output and the
	 * terminal status. Never throws for a step failure — it records it and
	 * fails the job, the way the node runner did.
	 */
	public function execute($job) {
		$type = (string)$job->get('mjb_job_type');
		if (!in_array($type, ManagementJob::BOOTSTRAP_JOB_TYPES, true)) {
			$this->finish($job, false, "The bootstrap executor runs only " . implode(' and ', ManagementJob::BOOTSTRAP_JOB_TYPES)
				. " jobs; '{$type}' is not one of them.");
			return;
		}
		$node = new ManagedNode((int)$job->get('mjb_mgn_node_id'), TRUE);
		if (!$node->key) {
			$this->finish($job, false, 'The install job names no live target node.');
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
			// Unsealed once, for the length of this job, and only used by a step
			// that declared it needs it. Absent is the ordinary case.
			'admin_password' => $this->resolve_admin_password($node),
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
		$ssh_ready = false;
		foreach ($main as $i => $step) {
			$label = (string)($step['label'] ?? '');
			$this->append($job, "\n=== [Step " . ($i + 1) . "/{$total}] {$label} ===\n", $i);
			// A provider says 'running' before sshd is listening, and this job
			// is created in the same tick that sees 'running' — so the first
			// remote step would race the machine's boot. Wait for it, once.
			if (($step['type'] ?? '') === 'ssh' && !$ssh_ready) {
				$waited = $this->wait_for_ssh($job, $ctx, $i);
				if ($waited === null) {
					$ok = false;
					$fail_message = 'Step ' . ($i + 1) . " ({$label}) failed: the target did not accept SSH within "
						. (int)(self::SSH_READY_TIMEOUT_SECONDS / 60) . ' minutes of the install starting';
					break;
				}
				$ssh_ready = true;
			}
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

		// Retiring the password is proven by the machine, not by the step's exit
		// code: a fresh login with the password has to be refused. A machine that
		// still accepts it, or one that cannot be asked, fails the job — and a
		// failed job keeps the password, which is the safe side of this doubt.
		if ($ok && $type === 'retire_install_password') {
			$refusal = $this->confirm_password_refused($job, $ctx, max(0, $total - 1));
			if ($refusal !== '') {
				$ok = false;
				$fail_message = $refusal;
			}
		}

		$this->finish($job, $ok, $fail_message);

		// The runner contract ends with the result PROCESSED, not merely
		// written. For an agent-run job the channel endpoint does that on
		// receipt; here the executor is the one who knows the job is done. The
		// provision pipeline also processes an unprocessed result when it is
		// watching the job, but a retry from the node page is watched by
		// nothing — it completed and cleared no install state until this.
		$job->load();
		try {
			JobResultProcessor::process($job);
		} catch (\Throwable $e) {
			error_log('InstallJobExecutor: result processing for job #' . $job->key . ' failed: ' . $e->getMessage());
		}
	}

	/**
	 * Probe the target over SSH until it answers, up to the readiness budget.
	 * Returns the seconds waited, or null when the budget ran out. Each probe
	 * is a real login with the sealed password, so 'ready' means the whole
	 * path works — sshd up, password accepted — not merely that port 22 opens.
	 */
	private function wait_for_ssh($job, $ctx, $step_index) {
		$started = time();
		$budget = $this->ssh_ready_timeout();
		$attempt = 0;
		while (true) {
			$attempt++;
			$probe = array('type' => 'ssh', 'cmd' => 'echo SSH_READY', 'timeout' => 30);
			list($out, $code) = $this->run_step($probe, $ctx);
			if ($code === 0 && strpos($out, 'SSH_READY') !== false) {
				$waited = time() - $started;
				if ($attempt > 1) {
					$this->append($job, "[target answered SSH after {$waited}s]\n", $step_index);
				}
				return $waited;
			}
			if ($attempt === 1) {
				$this->append($job, "[waiting for the target to accept SSH — a new instance boots for a minute or two after the provider reports it running]\n", $step_index);
			}
			if (time() - $started >= $budget) {
				$this->append($job, "[target still not answering SSH after {$attempt} attempts: " . trim((string)$out) . "]\n", $step_index);
				return null;
			}
			sleep(min(self::SSH_READY_PROBE_INTERVAL, max(1, $budget - (time() - $started))));
		}
	}

	/** The readiness budget in seconds; tests shorten it through the environment. */
	private function ssh_ready_timeout() {
		$env = getenv('JOINERY_INSTALL_SSH_READY_TIMEOUT');
		return ($env !== false && (int)$env > 0) ? (int)$env : self::SSH_READY_TIMEOUT_SECONDS;
	}

	/** The refusal-confirmation budget in seconds; tests shorten it through the environment. */
	private function refusal_confirm_timeout() {
		$env = getenv('JOINERY_INSTALL_REFUSAL_CONFIRM_TIMEOUT');
		return ($env !== false && (int)$env > 0) ? (int)$env : self::REFUSAL_CONFIRM_TIMEOUT_SECONDS;
	}

	/**
	 * Try the install password once more and require the machine to refuse it.
	 * Returns '' when the machine said "Permission denied" — the only answer
	 * that proves password login is off — and otherwise a failure message.
	 *
	 * sshd is restarted by host-harden moments before this, so the first probe
	 * may not connect at all; that is a wait, not a verdict. A probe that logs
	 * in is a verdict: the password still works and the job must fail. A probe
	 * that never gets an answer inside the budget is a doubt, and a doubt fails
	 * the job too — nothing erases a password the machine was not seen to refuse.
	 */
	private function confirm_password_refused($job, $ctx, $step_index) {
		$started = time();
		$budget = $this->refusal_confirm_timeout();
		$this->append($job, "\n=== Confirming the machine refuses the install password ===\n", $step_index);
		$last = '';
		while (true) {
			$probe = array('type' => 'ssh', 'cmd' => 'echo STILL_ACCEPTED', 'timeout' => 30);
			list($out, $code) = $this->run_step($probe, $ctx);
			if ($code === 0 && strpos($out, 'STILL_ACCEPTED') !== false) {
				$this->append($job, "[the machine STILL ACCEPTED the install password]\n", $step_index);
				return 'The machine still accepted the install password after host-harden; the password is kept. '
					. 'Check sshd_config on the machine (PasswordAuthentication, PermitRootLogin) and re-run this job.';
			}
			if (stripos($out, 'Permission denied') !== false) {
				$this->append($job, "[the machine refused the install password: retired]\n", $step_index);
				return '';
			}
			$last = trim((string)$out);
			if (time() - $started >= $budget) {
				$this->append($job, "[could not get an answer from the machine: " . $last . "]\n", $step_index);
				return 'Could not confirm that the machine refuses the install password (no answer within '
					. $budget . 's: ' . $last . '); the password is kept. Re-run this job once the machine answers.';
			}
			sleep(min(self::REFUSAL_PROBE_INTERVAL, max(1, $budget - (time() - $started))));
		}
	}

	/**
	 * Run one step. Returns [output, exit_code, error_message]. Only 'local'
	 * and 'ssh' exist; anything else is a builder defect and fails by name.
	 */
	private function run_step($step, $ctx) {
		$type = (string)($step['type'] ?? '');
		$timeout = (int)($step['timeout'] ?? 1800);

		if ($type === 'local') {
			list($out, $code) = $this->shell((string)($step['cmd'] ?? ''), array(), $timeout);
			return array($out, $code, $code !== 0 ? "local command exited {$code}" : '');
		}

		if ($type === 'ssh') {
			// A step that named a secret for its stdin gets it, or fails. The
			// only name is the admin password; anything else is a builder defect
			// and is refused rather than run without what it asked for.
			$stdin = null;
			$wants = (string)($step['stdin'] ?? '');
			if ($wants !== '') {
				if ($wants !== 'admin_password') {
					return array('', 1, "unknown stdin source '{$wants}'");
				}
				if (trim((string)($ctx['admin_password'] ?? '')) === '') {
					return array('', 1, 'this install asks for the site admin password on stdin, and the '
						. 'provision row holds none that can be read back — the site would be born with a '
						. 'password nobody has');
				}
				$stdin = $ctx['admin_password'] . "\n";
			}
			$ssh = 'sshpass -e ssh'
				. ' -o StrictHostKeyChecking=accept-new'
				. ' -o UserKnownHostsFile=/dev/null'
				. ' -o ConnectTimeout=20'
				. ' -p ' . escapeshellarg((string)$ctx['port'])
				. ' ' . escapeshellarg($ctx['user'] . '@' . $ctx['host'])
				. ' ' . escapeshellarg((string)($step['cmd'] ?? ''));
			list($out, $code) = $this->shell($ssh, array('SSHPASS' => $ctx['password']), $timeout, $stdin);
			return array($out, $code, $code !== 0 ? "ssh step exited {$code}" : '');
		}

		return array('', 1, "unknown step type '{$type}'");
	}

	/**
	 * Run a shell command with a wall-clock timeout, merging stderr into the
	 * captured output. Extra environment (SSHPASS) is passed to the child only,
	 * never exported here or placed on a command line.
	 */
	private function shell($cmd, array $env_extra, $timeout_secs, $stdin = null) {
		$timeout_secs = max(1, (int)$timeout_secs);
		$wrapped = 'timeout ' . $timeout_secs . ' bash -c ' . escapeshellarg($cmd . ' 2>&1');

		$env = array();
		foreach ($_ENV as $k => $v) { $env[$k] = $v; }
		if (empty($env['PATH'])) { $env['PATH'] = '/usr/local/bin:/usr/bin:/bin'; }
		foreach ($env_extra as $k => $v) { $env[$k] = $v; }

		$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		if ($stdin !== null) { $descriptors[0] = array('pipe', 'r'); }
		$proc = @proc_open($wrapped, $descriptors, $pipes, null, $env);
		if (!is_resource($proc)) {
			return array('could not start a subprocess for this step', 127);
		}
		// Written and closed before the output is read. Everything that travels
		// this way is one short line — well under a pipe buffer — so there is no
		// deadlock to interleave against, and closing is what lets the remote
		// `read` return instead of waiting for a line that never comes.
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
			fclose($pipes[0]);
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
		return $this->unseal_provision_column($node, 'cvp_root_pass_sealed');
	}

	/**
	 * The site admin account's first password, unsealed, or null when the
	 * provision holds none — a bare instance, a provision that predates the
	 * column, or one whose buyer already revealed it (the reveal erases it).
	 */
	private function resolve_admin_password($node) {
		return $this->unseal_provision_column($node, 'cvp_admin_pass_sealed');
	}

	/** One sealed column of the node's newest live provision, opened, or null. */
	private function unseal_provision_column($node, string $column) {
		// The column name is a literal from this file's own two callers, never
		// anything that arrived from outside.
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT {$column} FROM cvp_customer_cloud_provisions " .
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
