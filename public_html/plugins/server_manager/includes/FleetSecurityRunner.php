<?php
/**
 * FleetSecurityRunner - moves a FleetSecurityRun along, one machine or site
 * container at a time (specs/fleet_security_patching.md WP5).
 *
 * Install security updates everywhere: each node in turn runs its own
 * install_security_updates (the updates its automatic-update policy allows,
 * now), and the run moves on when that job has finished. Machines go first,
 * then each site container after its host. A run stops at the first node whose
 * updater failed, naming it; one with nothing to install, or another package
 * run in the way, is recorded and passed. Stop takes effect between nodes.
 *
 * A site container whose host is replacing its containers (the host's
 * nightly run, started by the hourly check for a critical fix too) is passed:
 * left out of the plan, or skipped while its install is open. The new
 * container carries the fix, and the install inside the old one would die
 * with it and halt the run an hour later as a lost job (reviewer2 c).
 *
 * advance() is called by the AdvanceFleetSecurityRuns task every tick and by
 * the page on load; it is idempotent, and each state is saved before the next
 * is entered, so a crash between two calls resumes where it stood.
 *
 * @version 1.2 - a node whose agent moved away is not part of the plan, not even as left out
 * @version 1.1 - a container whose host is replacing its containers is left out, or skipped while its install is
 *                open, instead of waiting out a job that dies with the container (reviewer2 c)
 * @version 1.0
 */
class FleetSecurityRunner {

	/** The word each node in a run must report. */
	const DECLARED_WORDS = ['install_security_updates'];

	/** How long one node's updates may take, from queueing, before the run halts on it (the word's own 60 minutes, and slack). */
	const JOB_WAIT_MINUTES = 70;

	/** The advisory lock every mover takes: the task tick and the page load never race. */
	const LOCK_KEY = 7720231022;

	private static function lock(): bool {
		$db = DbConnector::get_instance()->get_db_link();
		return (bool)$db->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn();
	}

	private static function unlock(): void {
		DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
	}

	/**
	 * Every operational node a run covers, in order, and the ones it leaves
	 * out with why: machines first (by name), then site containers, each after
	 * its host. A node whose agent lacks the word, or that is switched off, is
	 * left out by name, so "everywhere" never quietly means "some".
	 *
	 * @param ManagedNode[]|null $candidates the nodes to consider; null is every node on record
	 * @return array{nodes: ManagedNode[], left_out: array<int, array{name: string, reason: string}>}
	 */
	public static function plan(?array $candidates = null): array {
		if ($candidates === null) {
			$candidates = array();
			foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $n) {
				$candidates[] = $n;
			}
		}
		$machines = array();
		$containers = array();
		$left_out = array();
		foreach ($candidates as $n) {
			// A machine whose agent moved away is patched from where it is managed now.
			if (!$n->is_operational() || $n->agent_moved_away() !== null) {
				continue;
			}
			$name = (string)$n->get('mgn_name');
			if (!$n->get('mgn_enabled')) {
				$left_out[] = array('name' => $name, 'reason' => 'switched off');
				continue;
			}
			if (!JobCommandBuilder::has_primitive($n, 'install_security_updates')) {
				$left_out[] = array('name' => $name, 'reason' => AgentVocabulary::needs_newer_agent_text($n, self::DECLARED_WORDS));
				continue;
			}
			if (trim((string)$n->get('mgn_container_name')) !== '') {
				if (($why = self::host_replacing($n)) !== null) {
					$left_out[] = array('name' => $name, 'reason' => $why);
					continue;
				}
				$containers[] = $n;
			} else {
				$machines[] = $n;
			}
		}
		$by_name = function ($a, $b) { return strcasecmp((string)$a->get('mgn_name'), (string)$b->get('mgn_name')); };
		usort($machines, $by_name);
		usort($containers, function ($a, $b) use ($by_name) {
			return array((int)$a->get('mgn_mgh_managed_host_id'), (string)$a->get('mgn_name')) <=> array((int)$b->get('mgn_mgh_managed_host_id'), (string)$b->get('mgn_name'));
		});
		return array('nodes' => array_merge($machines, $containers), 'left_out' => $left_out);
	}

	/** Start a run over the plan's nodes. Throws when one is running or there is nothing to do. */
	public static function start($user_id, ?array $candidates = null): FleetSecurityRun {
		if (!self::lock()) {
			throw new FleetSecurityRunException('A run is being started or moved right now; try again in a moment.');
		}
		try {
			if (FleetSecurityRun::running()) {
				throw new FleetSecurityRunException('A run is already under way; stop it or let it finish first.');
			}
			$plan = self::plan($candidates);
			$steps = array();
			foreach ($plan['nodes'] as $n) {
				$steps[] = array('node_id' => (int)$n->key, 'name' => (string)$n->get('mgn_name'), 'job_id' => null,
					'verdict' => 'pending', 'outcome' => '', 'reason' => '', 'changed' => null,
					'reloaded' => array(), 'still_on_old_code' => array(), 'reboot_required' => false);
			}
			if (!$steps) {
				throw new FleetSecurityRunException('No node can install its security updates from here.');
			}
			$run = new FleetSecurityRun(NULL);
			$run->set_steps($steps);
			$run->set('fsr_position', 0);
			$run->set('fsr_status', FleetSecurityRun::STATUS_RUNNING);
			$run->set('fsr_created_by', $user_id ?: null);
			$run->save();
			$run->load();
			self::advance_locked($run);
			return $run;
		} finally {
			self::unlock();
		}
	}

	/** Stop between nodes: the node in hand finishes, nothing further is queued. */
	public static function stop(FleetSecurityRun $run, $user_id): void {
		if (!self::lock()) {
			throw new FleetSecurityRunException('The run is being moved right now; try again in a moment.');
		}
		try {
			$run->load();
			if (!$run->is_running()) {
				return;
			}
			$run->set_steps(self::skip_pending($run->steps()));
			$run->set('fsr_stopped_by', $user_id ?: null);
			self::finish($run, FleetSecurityRun::STATUS_STOPPED, 'stopped by hand');
		} finally {
			self::unlock();
		}
	}

	public static function advance_all(): int {
		$moved = 0;
		foreach (new MultiFleetSecurityRun(array('status' => FleetSecurityRun::STATUS_RUNNING, 'deleted' => false)) as $r) {
			self::advance($r);
			$moved++;
		}
		return $moved;
	}

	public static function advance(FleetSecurityRun $run): void {
		if (!self::lock()) {
			return;
		}
		try {
			$run->load();
			self::advance_locked($run);
		} finally {
			self::unlock();
		}
	}

	/**
	 * One step: queue the current node's updates, or judge its finished job.
	 * A judged step that passes moves on and queues the next at once.
	 */
	private static function advance_locked(FleetSecurityRun $run): void {
		while ($run->is_running()) {
			$steps = $run->steps();
			$pos = (int)$run->get('fsr_position');
			if ($pos >= count($steps)) {
				self::finish($run, FleetSecurityRun::STATUS_COMPLETED, null);
				return;
			}
			$step = $steps[$pos];
			try {
				$node = new ManagedNode((int)$step['node_id'], TRUE);
			} catch (Exception $e) {
				$node = null;
			}
			if (!$node || !$node->key || $node->get('mgn_delete_time')) {
				self::pass_step($run, $steps, $pos, 'skipped', '', 'the node no longer exists');
				continue;
			}
			if (empty($step['job_id'])) {
				try {
					$built = JobCommandBuilder::build_install_security_updates($node);
					$job = ManagementJob::createFromBuild($node->key, 'install_security_updates', $built,
						array('fleet_security_run_id' => (int)$run->key), $run->get('fsr_created_by'));
				} catch (Exception $e) {
					self::pass_step($run, $steps, $pos, 'skipped', '', 'its updates could not be queued: ' . $e->getMessage());
					continue;
				}
				$steps[$pos]['job_id'] = (int)$job->key;
				$steps[$pos]['verdict'] = 'running';
				$run->set_steps($steps);
				$run->save();
				return;
			}
			try {
				$job = new ManagementJob((int)$step['job_id'], TRUE);
			} catch (Exception $e) {
				self::fail_step($run, $steps, $pos, 'its job is gone');
				return;
			}
			if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
				// Its container being replaced: the install inside it dies with it.
				if (($why = self::host_replacing($node)) !== null) {
					self::pass_step($run, $steps, $pos, 'skipped', '', $why);
					continue;
				}
				$queued = strtotime((string)$job->get('mjb_create_time') . ' UTC');
				if ($queued && time() - $queued > self::JOB_WAIT_MINUTES * 60) {
					self::fail_step($run, $steps, $pos, 'its updates have not finished in ' . self::JOB_WAIT_MINUTES
						. ' minutes (status ' . $job->get('mjb_status') . '; is its agent claiming jobs?)');
				}
				return;
			}
			JobResultProcessor::process_if_due($job);
			$job->load();
			$verdict = self::verdict($job);
			$steps[$pos] = array_merge($steps[$pos], $verdict['fields']);
			if ($verdict['verdict'] === 'failed') {
				self::fail_step($run, $steps, $pos, $verdict['reason']);
				return;
			}
			self::pass_step($run, $steps, $pos, $verdict['verdict'], $verdict['fields']['outcome'], $verdict['reason']);
		}
	}

	/**
	 * Why a site container's install is not worth waiting for: its host's last
	 * report shows the host replacing its site containers (site_refresh
	 * running) or this one being replaced. Null for a machine, or a container
	 * whose host is not replacing anything.
	 */
	public static function host_replacing(ManagedNode $node): ?string {
		$cname = trim((string)$node->get('mgn_container_name'));
		$group = (int)$node->get('mgn_mgh_managed_host_id');
		if ($cname === '' || $group <= 0) {
			return null;
		}
		try {
			$host = (new ManagedHost($group, TRUE))->host_node();
		} catch (Exception $e) {
			return null;
		}
		if (!$host) {
			return null;
		}
		$report = $host->get('mgn_last_host_report');
		if (is_string($report)) { $report = json_decode($report, true); }
		if (!is_array($report)) {
			return null;
		}
		$hr = JobResultProcessor::sanitise_host_report($report);
		$sr = $hr['site_refresh'] ?? null;
		if (is_array($sr) && $sr['running']) {
			return 'its host is replacing its containers; the new one carries the fix';
		}
		foreach (is_array($hr['containers'] ?? null) ? $hr['containers'] : array() as $c) {
			if ($c['name'] === $cname && !empty($c['replacing'])) {
				return 'its container is being replaced; the new one carries the fix';
			}
		}
		return null;
	}

	/**
	 * What a finished install_security_updates job means for the run: failed
	 * (the updater erred, or the job did) halts it; anything else passes, with
	 * the outcome, what changed, what was reloaded and what still serves old
	 * code kept for the table.
	 *
	 * @return array{verdict: string, reason: string, fields: array}
	 */
	public static function verdict(ManagementJob $job): array {
		$r = json_decode((string)$job->get('mjb_result'), true);
		$fields = array('outcome' => '', 'changed' => null, 'reloaded' => array(), 'still_on_old_code' => array(), 'reboot_required' => false);
		if ((string)$job->get('mjb_status') !== 'completed' || !is_array($r) || empty($r['read'])) {
			return array('verdict' => 'failed', 'reason' => 'its job ' . $job->get('mjb_status') . ' with no result to read', 'fields' => $fields);
		}
		$fields['outcome'] = (string)$r['outcome'];
		$fields['changed'] = is_int($r['upgraded_count'] ?? null) ? $r['upgraded_count'] : null;
		$fields['reloaded'] = is_array($r['reloaded'] ?? null) ? $r['reloaded'] : array();
		$fields['still_on_old_code'] = is_array($r['still_on_old_code'] ?? null) ? $r['still_on_old_code'] : array();
		$fields['reboot_required'] = !empty($r['reboot_required']);
		switch ($r['outcome']) {
			case 'installed':
			case 'nothing_to_install':
				$reason = $fields['still_on_old_code'] ? 'still serving old code: ' . implode(', ', $fields['still_on_old_code']) : '';
				return array('verdict' => 'done', 'reason' => $reason, 'fields' => $fields);
			case 'busy':
			case 'unavailable':
				return array('verdict' => 'skipped', 'reason' => (string)($r['reason'] ?? $r['outcome']), 'fields' => $fields);
			default:
				return array('verdict' => 'failed', 'reason' => 'its updater ' . ($r['outcome'] === 'failed' ? 'reported an error' : 'gave no outcome')
					. ((string)($r['reason'] ?? '') !== '' ? ': ' . $r['reason'] : ''), 'fields' => $fields);
		}
	}

	private static function pass_step(FleetSecurityRun $run, array $steps, int $pos, string $verdict, string $outcome, string $reason): void {
		$steps[$pos]['verdict'] = $verdict;
		$steps[$pos]['outcome'] = $outcome;
		$steps[$pos]['reason'] = mb_substr($reason, 0, 500);
		$run->set_steps($steps);
		$run->set('fsr_position', $pos + 1);
		$run->save();
		if ($pos + 1 >= count($steps)) {
			self::finish($run, FleetSecurityRun::STATUS_COMPLETED, null);
		}
	}

	private static function skip_pending(array $steps): array {
		foreach ($steps as $i => $s) {
			if (($s['verdict'] ?? '') === 'pending') {
				$steps[$i]['verdict'] = 'skipped';
			}
		}
		return $steps;
	}

	private static function fail_step(FleetSecurityRun $run, array $steps, int $pos, string $why): void {
		$steps[$pos]['verdict'] = 'failed';
		$steps[$pos]['reason'] = mb_substr($why, 0, 500);
		$run->set_steps(self::skip_pending($steps));
		self::finish($run, FleetSecurityRun::STATUS_HALTED, ($steps[$pos]['name'] ?? ('node #' . $steps[$pos]['node_id'])) . ': ' . $why);
	}

	private static function finish(FleetSecurityRun $run, string $status, ?string $reason): void {
		$run->set('fsr_status', $status);
		$run->set('fsr_halt_reason', $reason !== null ? mb_substr($reason, 0, 1000) : null);
		$run->set('fsr_finish_time', gmdate('Y-m-d H:i:s'));
		$run->save();
	}
}
?>
