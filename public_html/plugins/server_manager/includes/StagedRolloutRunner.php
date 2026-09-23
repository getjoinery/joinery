<?php
/**
 * StagedRolloutRunner - moves a StagedRollout along, one node at a time.
 *
 * specs/agent_recipes_and_vocabulary.md, "staged_rollout {release, order}".
 * The plane takes a release (the newest one this management node has
 * published, which is what a node's apply pulls) and an
 * ordered node list, applies to one node at a time with the node's own
 * apply_update, and moves on only when that node's structured apply result
 * (JobResultProcessor::apply_result) says: job completed, deploy tier
 * passed, the release's version reported, no rollback. The first miss halts
 * the rollout and names the node and the reason. Stop takes effect between
 * nodes: an apply in flight finishes, and nothing further is queued.
 *
 * advance() is called by the AdvanceStagedRollouts task every tick and by the
 * page on load; it is idempotent, and every state it moves between is saved
 * before the next is entered, so a crash between two calls resumes where it
 * stood.
 *
 * The words a rollout needs from each node are declared here
 * (AgentVocabulary: one place, the standard state when a node lacks them).
 *
 * @version 1.1 - review 2026-09-23: one mover at a time (a PostgreSQL advisory lock around start and
 *                every step, B16); a node whose apply has not finished in APPLY_WAIT_MINUTES halts the
 *                rollout by name, and the nodes after a halt or a stop are marked skipped (B17); the
 *                release is the newest PUBLISHED version, the one a node's apply pulls, not this
 *                plane's VERSION file (B20).
 * @version 1.0
 */
class StagedRolloutRunner {

	/** The words each node in a rollout must report. */
	const DECLARED_WORDS = ['apply_update'];

	/** How long one node's apply may take, from queueing, before the rollout halts on it. */
	const APPLY_WAIT_MINUTES = 90;

	/** The advisory lock every mover takes: the task tick and the page load never race. */
	const LOCK_KEY = 7720230923;

	/** Take the rollout lock; false when another mover holds it. */
	private static function lock(): bool {
		$db = DbConnector::get_instance()->get_db_link();
		return (bool)$db->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn();
	}

	private static function unlock(): void {
		DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
	}

	/**
	 * The release a rollout applies: the newest version this management node
	 * has PUBLISHED, which is what a node's apply_update pulls. Null when
	 * nothing is published.
	 */
	public static function published_release() {
		foreach (new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 1) as $u) {
			return (int)$u->get('upg_major_version') . '.' . (int)$u->get('upg_minor_version') . '.' . (int)$u->get('upg_patch_version');
		}
		return null;
	}

	/**
	 * Why a node cannot be in a rollout, or null when it can.
	 */
	public static function node_refusal($node) {
		if ($node->get('mgn_delete_time')) {
			return 'it has been removed';
		}
		if (!$node->hosts_site()) {
			return 'it hosts no Joinery site, so there is no release to apply';
		}
		$missing = AgentVocabulary::missing_words($node, self::DECLARED_WORDS);
		if ($missing || !JobCommandBuilder::has_primitive($node, 'apply_update')) {
			return AgentVocabulary::needs_newer_agent_text($node, $missing ?: self::DECLARED_WORDS);
		}
		return null;
	}

	/**
	 * Start a rollout of the release this management node serves over the
	 * given nodes, in the given order.
	 *
	 * @param int[] $node_ids ordered, first applied first
	 * @throws StagedRolloutException naming what refused
	 */
	public static function start(array $node_ids, $user_id) {
		if (!self::lock()) {
			throw new StagedRolloutException('A rollout is being started or moved right now; try again in a moment.');
		}
		try {
			return self::start_locked($node_ids, $user_id);
		} finally {
			self::unlock();
		}
	}

	private static function start_locked(array $node_ids, $user_id) {
		if (StagedRollout::running()) {
			throw new StagedRolloutException('A rollout is already running; stop it or let it finish first.');
		}
		$release = (string)self::published_release();
		if (!preg_match('/^\d+\.\d+\.\d+$/', $release)) {
			throw new StagedRolloutException('This management node has published no release to roll out.');
		}
		$steps = array();
		$seen = array();
		foreach ($node_ids as $id) {
			$id = (int)$id;
			if ($id <= 0 || isset($seen[$id])) { continue; }
			$seen[$id] = true;
			try {
				$node = new ManagedNode($id, TRUE);
			} catch (Exception $e) {
				throw new StagedRolloutException("Node #{$id} does not exist.");
			}
			$why = self::node_refusal($node);
			if ($why !== null) {
				throw new StagedRolloutException("'{$node->get('mgn_name')}' cannot be in a rollout: {$why}");
			}
			$steps[] = array('node_id' => $id, 'name' => (string)$node->get('mgn_name'),
				'job_id' => null, 'verdict' => 'pending', 'reason' => '');
		}
		if (!$steps) {
			throw new StagedRolloutException('Choose at least one node.');
		}
		$rollout = new StagedRollout(NULL);
		$rollout->set('srl_release', $release);
		$rollout->set_steps($steps);
		$rollout->set('srl_position', 0);
		$rollout->set('srl_status', StagedRollout::STATUS_RUNNING);
		$rollout->set('srl_created_by', $user_id ?: null);
		$rollout->save();
		$rollout->load();
		self::advance_locked($rollout);
		return $rollout;
	}

	/** Stop between nodes: the apply in flight finishes; nothing further is queued. */
	public static function stop(StagedRollout $rollout, $user_id) {
		// Waits for a step in progress rather than racing it: a step saves the
		// whole row, and would write "running" back over a stop.
		DbConnector::get_instance()->get_db_link()->query('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')');
		try {
			$rollout->load();
			self::stop_locked($rollout, $user_id);
		} finally {
			self::unlock();
		}
	}

	private static function stop_locked(StagedRollout $rollout, $user_id) {
		if (!$rollout->is_running()) {
			return;
		}
		$rollout->set('srl_status', StagedRollout::STATUS_STOPPED);
		$rollout->set('srl_stopped_by', $user_id ?: null);
		$rollout->set('srl_finish_time', gmdate('Y-m-d H:i:s'));
		$rollout->set_steps(self::skip_pending($rollout->steps()));
		$rollout->save();
	}

	/** Move every running rollout along. What the scheduled task calls. */
	public static function advance_all() {
		$moved = 0;
		foreach (new MultiStagedRollout(array('status' => StagedRollout::STATUS_RUNNING, 'deleted' => false)) as $r) {
			self::advance($r);
			$moved++;
		}
		return $moved;
	}

	/**
	 * One step of the state machine. Each call does at most one of: queue the
	 * current node's apply, judge its finished apply, finish the rollout.
	 */
	public static function advance(StagedRollout $rollout) {
		if (!self::lock()) {
			return; // another mover is taking this step
		}
		try {
			$rollout->load();
			self::advance_locked($rollout);
		} finally {
			self::unlock();
		}
	}

	private static function advance_locked(StagedRollout $rollout) {
		if (!$rollout->is_running()) {
			return;
		}
		$steps = $rollout->steps();
		$pos = (int)$rollout->get('srl_position');
		if ($pos >= count($steps)) {
			self::finish($rollout, StagedRollout::STATUS_COMPLETED, null);
			return;
		}
		$step = $steps[$pos];

		try {
			$node = new ManagedNode((int)$step['node_id'], TRUE);
		} catch (Exception $e) {
			$node = null;
		}
		if (!$node || !$node->key) {
			self::fail_step($rollout, $steps, $pos, 'the node no longer exists');
			return;
		}

		if (empty($step['job_id'])) {
			$why = self::node_refusal($node);
			if ($why !== null) {
				self::fail_step($rollout, $steps, $pos, $why);
				return;
			}
			try {
				$built = JobCommandBuilder::build_apply_update($node);
				$job = ManagementJob::createFromBuild($node->key, 'apply_update', $built,
					array('staged_rollout_id' => (int)$rollout->key), $rollout->get('srl_created_by'));
			} catch (Exception $e) {
				self::fail_step($rollout, $steps, $pos, 'the apply could not be queued: ' . $e->getMessage());
				return;
			}
			$steps[$pos]['job_id'] = (int)$job->key;
			$steps[$pos]['verdict'] = 'running';
			$rollout->set_steps($steps);
			$rollout->save();
			return;
		}

		try {
			$job = new ManagementJob((int)$step['job_id'], TRUE);
		} catch (Exception $e) {
			self::fail_step($rollout, $steps, $pos, 'its apply job is gone');
			return;
		}
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			// Still applying - or never claimed. A node that has not finished
			// in the budget halts the rollout by name rather than holding it
			// running for ever.
			$queued = strtotime((string)$job->get('mjb_create_time') . ' UTC');
			if ($queued && time() - $queued > self::APPLY_WAIT_MINUTES * 60) {
				self::fail_step($rollout, $steps, $pos, 'its apply has not finished in ' . self::APPLY_WAIT_MINUTES
					. ' minutes (status ' . $job->get('mjb_status') . '; is its agent claiming jobs?)');
			}
			return;
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		$why = self::gate($job, (string)$rollout->get('srl_release'));
		if ($why !== null) {
			self::fail_step($rollout, $steps, $pos, $why);
			return;
		}
		$steps[$pos]['verdict'] = 'passed';
		$steps[$pos]['reason'] = '';
		$rollout->set_steps($steps);
		$rollout->set('srl_position', $pos + 1);
		$rollout->save();
		if ($pos + 1 >= count($steps)) {
			self::finish($rollout, StagedRollout::STATUS_COMPLETED, null);
		}
	}

	/**
	 * The gate between nodes: null when the apply proved good, else why not.
	 * Public so the test can hold it to the spec's four conditions.
	 */
	public static function gate($job, $release) {
		if ((string)$job->get('mjb_status') !== 'completed') {
			$err = trim((string)$job->get('mjb_error_message'));
			return 'the apply job ' . $job->get('mjb_status') . ($err !== '' ? ': ' . $err : '');
		}
		$result = json_decode((string)$job->get('mjb_result'), true);
		$apply = is_array($result) ? ($result['apply'] ?? null) : null;
		if (!is_array($apply)) {
			return 'the node sent no structured apply result, so the apply cannot be judged';
		}
		if (!empty($apply['rolled_back']['rolled_back'])) {
			return 'it rolled back at ' . ($apply['rolled_back']['step'] ?? 'an unnamed step')
				. (!empty($apply['rolled_back']['schema_ahead_of_code']) ? ', leaving the schema ahead of the code' : '');
		}
		if (($apply['outcome'] ?? '') !== 'completed') {
			return 'the apply did not complete';
		}
		if (($apply['deploy_tier']['verdict'] ?? '') !== 'passed') {
			$failed = (array)($apply['deploy_tier']['failed_tests'] ?? array());
			return 'the deploy tier ' . ($apply['deploy_tier']['verdict'] ?? 'did not run')
				. ($failed ? ' (' . implode(', ', $failed) . ')' : '');
		}
		if (($apply['version_after'] ?? null) !== $release) {
			return 'it reports version ' . ($apply['version_after'] ?? 'unknown') . ', not ' . $release;
		}
		return null;
	}

	/** Every step not yet started, marked skipped: the rollout will not reach it. */
	private static function skip_pending(array $steps): array {
		foreach ($steps as $i => $s) {
			if (($s['verdict'] ?? '') === 'pending') {
				$steps[$i]['verdict'] = 'skipped';
			}
		}
		return $steps;
	}

	private static function fail_step(StagedRollout $rollout, array $steps, $pos, $why) {
		$steps[$pos]['verdict'] = 'failed';
		$steps[$pos]['reason'] = mb_substr((string)$why, 0, 500);
		$rollout->set_steps(self::skip_pending($steps));
		self::finish($rollout, StagedRollout::STATUS_HALTED, ($steps[$pos]['name'] ?? ('node #' . $steps[$pos]['node_id'])) . ': ' . $why);
	}

	private static function finish(StagedRollout $rollout, $status, $reason) {
		$rollout->set('srl_status', $status);
		$rollout->set('srl_halt_reason', $reason !== null ? mb_substr((string)$reason, 0, 1000) : null);
		$rollout->set('srl_finish_time', gmdate('Y-m-d H:i:s'));
		$rollout->save();
	}
}
