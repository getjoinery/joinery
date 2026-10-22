<?php
/**
 * StagedRolloutRunner - moves a StagedRollout along, stage by stage.
 *
 * specs/agent_recipes_and_vocabulary.md, "staged_rollout {release, order}".
 * The plane takes a release (the newest one this management node has
 * published, which is what a node's apply pulls) and the nodes in stages,
 * applies with each node's own apply_update, and counts a node done only when
 * its structured apply result (JobResultProcessor::apply_result) says: job
 * completed, deploy tier passed, the release's version reported, no rollback.
 * A stage starts only when every node of the one before is done. Within a
 * stage the nodes go at once, one at a time on each machine
 * (FleetBackupPolicy::machine_key: container sites share their host): an
 * upgrade's syntax sweep, unpacking and tests load the machine it runs on.
 *
 * A rollout started by hand gives every node a stage of its own, so it goes
 * one node at a time in the order given. Deploy to all managed nodes has two:
 * the nodes marked Test releases here first, then the rest (deploy_all_plan).
 *
 * The first miss halts the rollout and names the node and the reason: nothing
 * further is started, the applies already under way are still judged, and it
 * ends halted once none is left running. Stop ends it at once: an apply in
 * flight finishes on its node, and nothing further is queued.
 *
 * advance() is called by the AdvanceStagedRollouts task every tick and by the
 * page on load; it is idempotent, and every state it moves between is saved
 * before the next is entered, so a crash between two calls resumes where it
 * stood.
 *
 * The words a rollout needs from each node are declared here
 * (AgentVocabulary: one place, the standard state when a node lacks them).
 *
 * @version 1.10 - stages: a stage's nodes go at once, one per machine, and the next stage waits for all of them;
 *                deploy to all managed nodes tests a release on the nodes marked mgn_rollout_first (or, with
 *                none marked, the first site) before the rest. A miss lets the applies under way finish and be
 *                judged before the rollout halts (srl_halt_reason is set while it is still running)
 * @version 1.9 - deploy_all_plan() does not list a node whose agent moved away
 * @version 1.8 - copy_hold(): a node's step waits while a copy of its site is being made, switching over, or
 *                switched and not yet kept, so the copy and its site keep one release
 * @version 1.7 - the page_probe gate: a rollout may name up to MAX_PROBE_PAGES pages; each node renders them before
 *                its apply and again after it passes the apply gate, and halts when a page got worse (it answers an
 *                error status it did not before, raises more PHP errors, or fails to load an asset it loaded)
 * @version 1.6 - gate(): a node that reports a release past the rollout's (it follows a newer upgrade source) passes
 * @version 1.5 - a node whose agent offers upgrade_preflight is asked whether an upgrade would stop before it
 *                started, before its apply is queued; a failing check halts the rollout at that node with the
 *                check's reason, with nothing changed (specs/release_file_repair.md)
 * @version 1.4 - gate(): a node that refused to run its upgrade script as modified (or with an unusable manifest)
 *                halts with a reason that names the file and says another release will not fix it
 * @version 1.3 - deploy to all managed nodes: a publish job carrying deploy_all starts a rollout over every
 *                eligible node once it completes (start_pending_deploy_all, deploy_all_for_publish)
 * @version 1.2 - node_refusal() refuses a node in an install state (ManagedNode::is_operational())
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

	/** How long a node may take to answer its readiness check before the rollout halts at it. */
	const PREFLIGHT_WAIT_MINUTES = 15;

	/** How many pages a rollout may check on each node. */
	const MAX_PROBE_PAGES = 5;

	/** How long one round of a node's page checks may take before the rollout halts at it. */
	const PROBE_WAIT_MINUTES = 15;

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
	 * Why a node's update waits, or null when it need not: a copy of its site is
	 * being made, or is switching over, or has switched and is not yet kept. The
	 * copy installs the release the site runs and must run that release, and while
	 * a switch-over's way back is open the old server would go back to data a
	 * newer release wrote. A dormant or stopped copy holds nothing: a release makes
	 * it stale, and its tab says so.
	 */
	public static function copy_hold($node): ?string {
		if (!$node || !$node->key) {
			return null;
		}
		$copy = SiteCopy::live_for_source((int)$node->key);
		if (!$copy || in_array($copy->status(), array(SiteCopy::STATUS_DORMANT, SiteCopy::STATUS_HALTED), true)) {
			return null;
		}
		return 'held: a copy of this site is ' . strtolower($copy->status_label())
			. ', so its update waits until the copy is dormant or the switch-over is kept';
	}

	/**
	 * Why a node cannot be in a rollout, or null when it can.
	 */
	public static function node_refusal($node, bool $checks_pages = false) {
		if ($node->get('mgn_delete_time')) {
			return 'it has been removed';
		}
		if (!$node->hosts_site()) {
			return 'it hosts no Joinery site, so there is no release to apply';
		}
		if (!$node->is_operational()) {
			return 'it is not a working site (' . $node->install_state_label() . ')';
		}
		$missing = AgentVocabulary::missing_words($node, self::DECLARED_WORDS);
		if ($missing || !JobCommandBuilder::has_primitive($node, 'apply_update')) {
			return AgentVocabulary::needs_newer_agent_text($node, $missing ?: self::DECLARED_WORDS);
		}
		if ($checks_pages && !JobCommandBuilder::has_primitive($node, 'page_probe')) {
			return 'it cannot render a page to check: ' . AgentVocabulary::needs_newer_agent_text($node, ['page_probe']);
		}
		return null;
	}

	/**
	 * The pages a rollout checks, from what the operator typed: one per line,
	 * a path, optionally after its viewer ("admin /admin/admin_users"; the
	 * viewer is anonymous otherwise). Each is held to page_probe's own shape.
	 *
	 * @return array<int, array{page: string, viewer: string}>
	 * @throws StagedRolloutException naming the line that is not a page
	 */
	public static function parse_probe_pages(string $text): array {
		$pages = array();
		foreach (preg_split('/\r?\n/', $text) as $line) {
			$line = trim($line);
			if ($line === '') { continue; }
			$parts = preg_split('/\s+/', $line);
			$viewer = (count($parts) > 1) ? strtolower($parts[0]) : 'anonymous';
			$page = (count($parts) > 1) ? $parts[1] : $parts[0];
			try {
				$built = JobCommandBuilder::build_page_probe_primitive(null, $page, $viewer);
			} catch (Exception $e) {
				throw new StagedRolloutException('Pages to check: ' . $e->getMessage());
			}
			$key = $built['params']['viewer'] . ' ' . $built['params']['page'];
			$pages[$key] = array('page' => $built['params']['page'], 'viewer' => $built['params']['viewer']);
		}
		if (count($pages) > self::MAX_PROBE_PAGES) {
			throw new StagedRolloutException('Pages to check: at most ' . self::MAX_PROBE_PAGES . ' pages, so each node\'s check stays short.');
		}
		return array_values($pages);
	}

	/**
	 * Start a rollout of the release this management node serves over the
	 * given nodes, in the given order, one node at a time.
	 *
	 * @param int[] $node_ids ordered, first applied first
	 * @throws StagedRolloutException naming what refused
	 */
	public static function start(array $node_ids, $user_id, array $probe_pages = array()) {
		return self::start_stages(array_map(function ($id) { return array($id); }, array_values($node_ids)), $user_id, $probe_pages);
	}

	/**
	 * Start a rollout in stages: each stage's nodes at once, one per machine,
	 * and each stage only once every node of the one before has taken the
	 * release well.
	 *
	 * @param int[][] $stages the node ids of each stage, first stage first
	 * @throws StagedRolloutException naming what refused
	 */
	public static function start_stages(array $stages, $user_id, array $probe_pages = array()) {
		if (!self::lock()) {
			throw new StagedRolloutException('A rollout is being started or moved right now; try again in a moment.');
		}
		try {
			return self::start_locked($stages, $user_id, $probe_pages);
		} finally {
			self::unlock();
		}
	}

	private static function start_locked(array $stages, $user_id, array $probe_pages = array()) {
		if (StagedRollout::running()) {
			throw new StagedRolloutException('A rollout is already running; stop it or let it finish first.');
		}
		$release = (string)self::published_release();
		if (!preg_match('/^\d+\.\d+\.\d+$/', $release)) {
			throw new StagedRolloutException('This management node has published no release to roll out.');
		}
		$steps = array();
		$seen = array();
		$stage = 0;
		foreach ($stages as $node_ids) {
			$added = false;
			foreach ((array)$node_ids as $id) {
				$id = (int)$id;
				if ($id <= 0 || isset($seen[$id])) { continue; }
				$seen[$id] = true;
				try {
					$node = new ManagedNode($id, TRUE);
				} catch (Exception $e) {
					throw new StagedRolloutException("Node #{$id} does not exist.");
				}
				$why = self::node_refusal($node, (bool)$probe_pages);
				if ($why !== null) {
					throw new StagedRolloutException("'{$node->get('mgn_name')}' cannot be in a rollout: {$why}");
				}
				$steps[] = array('node_id' => $id, 'name' => (string)$node->get('mgn_name'),
					'stage' => $stage, 'machine' => FleetBackupPolicy::machine_key($node),
					'job_id' => null, 'verdict' => 'pending', 'reason' => '');
				$added = true;
			}
			if ($added) {
				$stage++;
			}
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
		$rollout->set('srl_probe_pages', $probe_pages ? array_values($probe_pages) : null);
		$rollout->save();
		$rollout->load();
		self::advance_locked($rollout);
		return $rollout;
	}

	/** How long after a deploy-all publish completes its rollout may still start: a late start is a surprise, not a convenience. */
	const DEPLOY_ALL_WINDOW_HOURS = 24;

	/**
	 * The nodes "Deploy to all managed nodes" rolls a release out to, in its
	 * two stages, and the ones it leaves out with why. This management node is
	 * the release's source, a node that hosts no site has nothing to apply, a
	 * node whose agent moved away is managed elsewhere, and a test's fixture
	 * node is nobody's site; those are not listed at all. A disabled node or
	 * one the rollout would refuse is listed as left out, so "all" never
	 * quietly means "some".
	 *
	 * The first stage is the nodes marked Test releases here first
	 * (mgn_rollout_first); with none marked, the first site, so a release is
	 * never applied everywhere untried. The second is the rest: plain sites,
	 * then management nodes, so on a machine that carries both a management
	 * node's turn comes last (one that breaks takes its own fleet's dashboard
	 * with it).
	 *
	 * @param ManagedNode[]|null $candidates the nodes to consider; null is every node on record
	 * @return array{nodes: ManagedNode[], first: ManagedNode[], rest: ManagedNode[], left_out: array<int, array{name: string, reason: string}>}
	 */
	public static function deploy_all_plan(?array $candidates = null): array {
		if ($candidates === null) {
			$candidates = array();
			foreach (new MultiManagedNode(array('deleted' => false), array('mgn_name' => 'ASC')) as $node) {
				if ($node->is_self() || ManagedNode::is_fixture_name((string)$node->get('mgn_name'))) { continue; }
				$candidates[] = $node;
			}
		}
		$first = array();
		$sites = array();
		$managers = array();
		$left_out = array();
		foreach ($candidates as $node) {
			if (!$node->hosts_site() || $node->agent_moved_away() !== null) { continue; }
			$name = (string)$node->get('mgn_name');
			if (!$node->get('mgn_enabled')) {
				$left_out[] = array('name' => $name, 'reason' => 'it is disabled');
				continue;
			}
			$why = self::node_refusal($node);
			if ($why !== null) {
				$left_out[] = array('name' => $name, 'reason' => $why);
				continue;
			}
			if ($node->get('mgn_rollout_first')) {
				$first[] = $node;
			} elseif ($node->is_management_node()) {
				$managers[] = $node;
			} else {
				$sites[] = $node;
			}
		}
		if (!$first) {
			$first = $sites ? array(array_shift($sites)) : ($managers ? array(array_shift($managers)) : array());
		}
		$rest = array_merge($sites, $managers);
		return array('nodes' => array_merge($first, $rest), 'first' => $first, 'rest' => $rest, 'left_out' => $left_out);
	}

	/**
	 * What Deploy to all managed nodes will do, in a sentence, for the pages
	 * that offer it: the nodes of each stage, and (unless the page lists them
	 * itself) the nodes it leaves out.
	 */
	public static function deploy_all_description(array $plan, bool $with_left_out = true): string {
		$names = function (array $nodes) {
			return implode(', ', array_map(function ($n) { return (string)$n->get('mgn_name'); }, $nodes));
		};
		if (!$plan['nodes']) {
			$text = 'No managed node can take a rollout right now.';
		} else {
			$text = 'Once the publish completes, the release is applied to ' . $names($plan['first'])
				. (count($plan['first']) > 1 ? ' first, at once' : ' first');
			if ($plan['rest']) {
				$text .= ', and when ' . (count($plan['first']) > 1 ? 'they have' : 'it has') . ' taken it well, to '
					. $names($plan['rest']) . ' at once, one at a time on each machine';
			}
			$text .= '. The first upgrade that fails stops anything not yet started.';
		}
		foreach ($with_left_out ? $plan['left_out'] : array() as $lo) {
			$text .= ' Left out: ' . $lo['name'] . ' (' . $lo['reason'] . ').';
		}
		return $text;
	}

	/**
	 * Start the rollout of every completed deploy-all publish that has not had
	 * one yet. What the scheduled task calls each tick. Only this management
	 * node's own publishes count — a publish is a job of its own agent — and
	 * only those that finished within DEPLOY_ALL_WINDOW_HOURS.
	 */
	public static function start_pending_deploy_all() {
		$self = ManagedNode::self_node();
		if (!$self) {
			return 0;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs
			WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'publish_upgrade' AND mjb_status = 'completed'
			  AND mjb_delete_time IS NULL AND mjb_parameters->>'deploy_all' = 'true'
			  AND mjb_parameters->>'deploy_all_outcome' IS NULL
			  AND mjb_completed_time > now() AT TIME ZONE 'UTC' - make_interval(hours => ?)
			ORDER BY mjb_management_job_id");
		$q->execute(array((int)$self->key, self::DEPLOY_ALL_WINDOW_HOURS));
		$started = 0;
		foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
			if (self::deploy_all_for_publish(new ManagementJob((int)$id, TRUE))) {
				$started++;
			}
		}
		return $started;
	}

	/**
	 * Start the rollout a completed deploy-all publish asked for, and write
	 * what became of it onto the publish job (deploy_all_outcome: started or
	 * refused, with the rollout, the nodes left out, or the reason). Waits —
	 * returns false and records nothing — while the publish has not completed
	 * or another rollout is running; the next tick tries again.
	 *
	 * @param ManagedNode[]|null $candidates passed to deploy_all_plan(); null is every node on record
	 * @return bool whether a rollout was started
	 */
	public static function deploy_all_for_publish(ManagementJob $job, ?array $candidates = null): bool {
		if (!self::lock()) {
			return false;
		}
		try {
			$job->load();
			$params = self::job_params($job);
			if (empty($params['deploy_all']) || isset($params['deploy_all_outcome'])
				|| (string)$job->get('mjb_status') !== 'completed') {
				return false;
			}
			if (StagedRollout::running()) {
				return false;
			}
			$version = (string)($params['version'] ?? '');
			$release = (string)self::published_release();
			if ($version === '' || $release !== $version) {
				self::record_deploy_all($job, $params, array('deploy_all_outcome' => 'refused',
					'deploy_all_reason' => 'the newest published release is ' . ($release ?: 'nothing')
						. ', not ' . ($version ?: 'the version this publish named') . ', so this publish is not what a node would apply'));
				return false;
			}
			$plan = self::deploy_all_plan($candidates);
			$key = function ($n) { return (int)$n->key; };
			$ids = array_map($key, $plan['nodes']);
			try {
				$rollout = self::start_locked(array(array_map($key, $plan['first']), array_map($key, $plan['rest'])),
					$job->get('mjb_created_by'));
			} catch (StagedRolloutException $e) {
				self::record_deploy_all($job, $params, array('deploy_all_outcome' => 'refused',
					'deploy_all_reason' => $ids ? $e->getMessage() : 'no managed node can take a rollout',
					'deploy_all_left_out' => $plan['left_out']));
				return false;
			}
			self::record_deploy_all($job, $params, array('deploy_all_outcome' => 'started',
				'deploy_all_rollout_id' => (int)$rollout->key, 'deploy_all_left_out' => $plan['left_out']));
			return true;
		} finally {
			self::unlock();
		}
	}

	/** A job's recorded parameters, decoded. */
	public static function job_params($job): array {
		$params = $job->get('mjb_parameters');
		if (is_string($params)) {
			$params = json_decode($params, true);
		}
		return is_array($params) ? $params : array();
	}

	private static function record_deploy_all(ManagementJob $job, array $params, array $outcome) {
		$job->set('mjb_parameters', json_encode(array_merge($params, $outcome)));
		$job->save();
	}

	/** Stop now: an apply in flight finishes on its node; nothing further is queued. */
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
	 * One tick of the state machine, over the current stage: every step under
	 * way is moved (judged, or left waiting); then, while nothing has failed,
	 * a step not yet begun starts on each machine with none running; then the
	 * stage is done when every step passed, and the rollout when the last
	 * stage is. A failed step stops anything further from starting, and the
	 * rollout ends halted once no step of the stage is still running.
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
		$stage = (int)$rollout->get('srl_position');
		$in_stage = array();
		$later = false;
		foreach ($steps as $i => $s) {
			$at = self::step_stage($s, $i);
			if ($at === $stage) {
				$in_stage[] = $i;
			} elseif ($at > $stage) {
				$later = true;
			}
		}
		if (!$in_stage) {
			self::finish($rollout, StagedRollout::STATUS_COMPLETED, null);
			return;
		}

		$busy = array();
		foreach ($in_stage as $i) {
			if (self::under_way($steps[$i])) {
				self::advance_step($rollout, $steps, $i);
				if (self::under_way($steps[$i])) {
					$busy[self::step_machine($steps[$i])] = true;
				}
			}
		}
		foreach ($in_stage as $i) {
			if (self::halting($rollout)) {
				break;
			}
			$v = (string)$steps[$i]['verdict'];
			$begun = $v === 'pending' || ($v === 'running' && !self::under_way($steps[$i]));
			$machine = self::step_machine($steps[$i]);
			if (!$begun || isset($busy[$machine])) {
				continue;
			}
			self::advance_step($rollout, $steps, $i);
			if (self::under_way($steps[$i])) {
				$busy[$machine] = true;
			}
		}

		$open = false;
		$all_passed = true;
		foreach ($in_stage as $i) {
			$open = $open || self::under_way($steps[$i]);
			$all_passed = $all_passed && $steps[$i]['verdict'] === 'passed';
		}
		if (self::halting($rollout)) {
			if (!$open) {
				$rollout->set_steps(self::skip_pending($steps));
				self::finish($rollout, StagedRollout::STATUS_HALTED, (string)$rollout->get('srl_halt_reason'));
			}
			return;
		}
		if ($all_passed) {
			$rollout->set('srl_position', $stage + 1);
			$rollout->save();
			if (!$later) {
				self::finish($rollout, StagedRollout::STATUS_COMPLETED, null);
			}
		}
	}

	/** A step's stage; a step recorded before stages existed is a stage of its own. */
	private static function step_stage(array $step, $index): int {
		return isset($step['stage']) ? (int)$step['stage'] : (int)$index;
	}

	/** The machine a step's apply loads; one recorded before stages carried none is its node's own. */
	private static function step_machine(array $step): string {
		return (string)($step['machine'] ?? ('node:' . (int)$step['node_id']));
	}

	/**
	 * Whether something of this step is running on its node: its readiness
	 * check, its page checks or its apply. A step held by a copy, or not yet
	 * begun, is not: it occupies no machine.
	 */
	private static function under_way(array $step): bool {
		return ($step['verdict'] ?? '') === 'running'
			&& (!empty($step['job_id']) || !empty($step['preflight_job_id']) || !empty($step['probes_before']));
	}

	/** Whether a step has failed and the rollout is only waiting for the applies under way. */
	private static function halting(StagedRollout $rollout): bool {
		return $rollout->is_running() && (string)$rollout->get('srl_halt_reason') !== '';
	}

	/**
	 * Move one step along by at most one of: wait while a copy holds it, ask
	 * its node whether it can take an upgrade, render its pages before, queue
	 * its apply, judge the finished apply, render its pages after, pass it.
	 * A failure marks it failed (fail_step); the rollout is ended by
	 * advance_locked().
	 */
	private static function advance_step(StagedRollout $rollout, array &$steps, $pos) {
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
			$held = self::copy_hold($node);
			if ($held !== null) {
				if (($steps[$pos]['reason'] ?? '') !== $held) {
					$steps[$pos]['verdict'] = 'running';
					$steps[$pos]['reason'] = $held;
					$rollout->set_steps($steps);
					$rollout->save();
				}
				return;
			}
			$why = self::node_refusal($node, (bool)$rollout->probe_pages());
			if ($why !== null) {
				self::fail_step($rollout, $steps, $pos, $why);
				return;
			}
			$pre = self::preflight($rollout, $node, $steps, $pos);
			if ($pre === 'wait') {
				return;
			}
			if ($pre !== null) {
				self::fail_step($rollout, $steps, $pos, $pre);
				return;
			}
			// The pages as they render before the apply: what the check after
			// it is measured against.
			$probe = self::probe_round($rollout, $node, $steps, $pos, 'probes_before');
			if ($probe === 'wait') {
				return;
			}
			if ($probe !== null) {
				self::fail_step($rollout, $steps, $pos, $probe);
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
			$steps[$pos]['reason'] = '';
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
		// The same pages after the apply, held against how they rendered before.
		$probe = self::probe_round($rollout, $node, $steps, $pos, 'probes_after');
		if ($probe === 'wait') {
			return;
		}
		if ($probe !== null) {
			self::fail_step($rollout, $steps, $pos, $probe);
			return;
		}
		if ($rollout->probe_pages()) {
			$why = self::probe_verdict(self::probe_jobs($steps[$pos]['probes_before'] ?? array()),
				self::probe_jobs($steps[$pos]['probes_after'] ?? array()), $rollout->probe_pages());
			if ($why !== null) {
				self::fail_step($rollout, $steps, $pos, $why);
				return;
			}
		}
		$steps[$pos]['verdict'] = 'passed';
		$steps[$pos]['reason'] = '';
		$rollout->set_steps($steps);
		$rollout->save();
	}

	/**
	 * Ask a node whether an upgrade would stop before it started, before its
	 * apply is queued. Null: carry on and queue the apply (the node passed, or
	 * its agent has no readiness word and is applied as before). 'wait': the
	 * check is queued or still running, look again on the next tick. A string:
	 * the reason to halt at this node, with nothing yet changed on it.
	 */
	private static function preflight(StagedRollout $rollout, ManagedNode $node, array &$steps, $pos) {
		$step = $steps[$pos];
		if (!empty($step['preflight_passed']) || !JobCommandBuilder::has_primitive($node, 'upgrade_preflight')) {
			return null;
		}

		if (empty($step['preflight_job_id'])) {
			try {
				$built = JobCommandBuilder::build_upgrade_preflight($node);
				$job = ManagementJob::createFromBuild($node->key, 'upgrade_preflight', $built,
					array('staged_rollout_id' => (int)$rollout->key), $rollout->get('srl_created_by'));
			} catch (Exception $e) {
				return 'the readiness check could not be queued: ' . $e->getMessage();
			}
			$steps[$pos]['preflight_job_id'] = (int)$job->key;
			$steps[$pos]['verdict'] = 'running';
			$steps[$pos]['reason'] = 'checking it can take an upgrade';
			$rollout->set_steps($steps);
			$rollout->save();
			return 'wait';
		}

		try {
			$job = new ManagementJob((int)$step['preflight_job_id'], TRUE);
		} catch (Exception $e) {
			return 'its readiness check is gone';
		}
		if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
			$queued = strtotime((string)$job->get('mjb_create_time') . ' UTC');
			if ($queued && time() - $queued > self::PREFLIGHT_WAIT_MINUTES * 60) {
				return 'its readiness check has not finished in ' . self::PREFLIGHT_WAIT_MINUTES
					. ' minutes (status ' . $job->get('mjb_status') . '; is its agent claiming jobs?)';
			}
			return 'wait';
		}
		JobResultProcessor::process_if_due($job);
		$job->load();
		$why = self::preflight_verdict($job);
		if ($why !== null) {
			return $why;
		}
		$steps[$pos]['preflight_passed'] = true;
		$steps[$pos]['reason'] = '';
		$rollout->set_steps($steps);
		$rollout->save();
		return null;
	}

	/**
	 * One round of a node's page checks ($round: probes_before or
	 * probes_after): queue a page_probe for each of the rollout's pages, then
	 * wait for all of them. Null: the round is done, or the rollout checks no
	 * pages. 'wait': look again on the next tick. A string: halt here. A
	 * probe that failed is not a halt by itself; probe_verdict() reads it.
	 */
	private static function probe_round(StagedRollout $rollout, ManagedNode $node, array &$steps, $pos, string $round) {
		$pages = $rollout->probe_pages();
		if (!$pages || !empty($steps[$pos][$round . '_done'])) {
			return null;
		}
		$when = ($round === 'probes_before') ? 'before' : 'after';
		if (empty($steps[$pos][$round])) {
			$ids = array();
			try {
				foreach ($pages as $p) {
					$built = JobCommandBuilder::build_page_probe($node, $p['page'], $p['viewer']);
					$job = ManagementJob::createFromBuild($node->key, 'page_probe', $built,
						array('staged_rollout_id' => (int)$rollout->key), $rollout->get('srl_created_by'));
					$ids[] = (int)$job->key;
				}
			} catch (Exception $e) {
				return 'its page checks could not be queued: ' . $e->getMessage();
			}
			$steps[$pos][$round] = $ids;
			$steps[$pos]['verdict'] = 'running';
			$steps[$pos]['reason'] = 'checking its pages ' . $when . ' the apply';
			$rollout->set_steps($steps);
			$rollout->save();
			return 'wait';
		}
		$oldest = null;
		foreach ($steps[$pos][$round] as $id) {
			try {
				$job = new ManagementJob((int)$id, TRUE);
			} catch (Exception $e) {
				return 'one of its page checks is gone';
			}
			if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
				$queued = strtotime((string)$job->get('mjb_create_time') . ' UTC');
				$oldest = ($oldest === null) ? $queued : min($oldest, $queued);
				continue;
			}
			JobResultProcessor::process_if_due($job);
		}
		if ($oldest !== null) {
			if (time() - $oldest > self::PROBE_WAIT_MINUTES * 60) {
				return 'its page checks ' . $when . ' the apply have not finished in ' . self::PROBE_WAIT_MINUTES
					. ' minutes (is its agent claiming jobs?)';
			}
			return 'wait';
		}
		$steps[$pos][$round . '_done'] = true;
		$steps[$pos]['reason'] = '';
		$rollout->set_steps($steps);
		$rollout->save();
		return null;
	}

	/** Job ids to the jobs they name, loaded; a gone job is left out. */
	private static function probe_jobs(array $ids): array {
		$jobs = array();
		foreach ($ids as $id) {
			try {
				$jobs[] = new ManagementJob((int)$id, TRUE);
			} catch (Exception $e) {
				// Gone: probe_verdict reads it as no check.
			}
		}
		return $jobs;
	}

	/**
	 * The page gate: null when no page got worse with the apply, else why.
	 * A page worse after is one that answers an error status (400 or more, or
	 * none) it did not answer before, raises more PHP errors than it did, or
	 * fails to load an asset it loaded. A page the node could not render after
	 * the apply halts too; one it could not render before has nothing to be
	 * held against, so after must be clean on its own. Public so the test can
	 * hold it to those rules.
	 *
	 * @param ManagementJob[] $before page_probe jobs, in any order
	 * @param ManagementJob[] $after  page_probe jobs, in any order
	 * @param array<int, array{page: string, viewer: string}> $pages
	 */
	public static function probe_verdict(array $before, array $after, array $pages) {
		$read = function (array $jobs) {
			$by = array();
			foreach ($jobs as $job) {
				$r = json_decode((string)$job->get('mjb_result'), true);
				if ((string)$job->get('mjb_status') === 'completed' && is_array($r) && !empty($r['read'])) {
					$by[($r['viewer'] ?? '') . ' ' . ($r['page'] ?? '')] = $r;
				}
			}
			return $by;
		};
		$b = $read($before);
		$a = $read($after);
		$errors = function ($r) {
			$n = 0;
			foreach ((array)($r['warnings'] ?? array()) as $w) {
				if (is_array($w) && ($w['type'] ?? '') === 'error') { $n++; }
			}
			return $n;
		};
		$bad = function ($status) { return !is_int($status) || $status <= 0 || $status >= 400; };
		foreach ($pages as $p) {
			$key = $p['viewer'] . ' ' . $p['page'];
			$label = $p['page'] . ' (as ' . $p['viewer'] . ')';
			if (!isset($a[$key])) {
				return 'its page check of ' . $label . ' after the apply did not run, so the apply cannot be judged by it';
			}
			$after_r = $a[$key];
			$before_r = $b[$key] ?? null;
			if ($bad($after_r['status'] ?? null) && ($before_r === null || !$bad($before_r['status'] ?? null))) {
				return $label . ' answers ' . (int)($after_r['status'] ?? 0)
					. ($before_r !== null ? ' after the apply; it answered ' . (int)$before_r['status'] . ' before' : ' after the apply');
			}
			$errs_after = $errors($after_r);
			$errs_before = ($before_r !== null) ? $errors($before_r) : 0;
			if ($errs_after > $errs_before) {
				return $label . ' raises ' . $errs_after . ' PHP error' . ($errs_after === 1 ? '' : 's')
					. ' after the apply (' . $errs_before . ' before)';
			}
			$assets_before = array();
			foreach ((array)($before_r['failed_assets'] ?? array()) as $fa) {
				if (is_array($fa)) { $assets_before[(string)($fa['path'] ?? '')] = true; }
			}
			$new_assets = array();
			foreach ((array)($after_r['failed_assets'] ?? array()) as $fa) {
				if (is_array($fa) && !isset($assets_before[(string)($fa['path'] ?? '')])) { $new_assets[] = (string)$fa['path']; }
			}
			if ($new_assets || (int)($after_r['failed_assets_unnamed'] ?? 0) > (int)($before_r['failed_assets_unnamed'] ?? 0)) {
				return $label . ' fails to load ' . ($new_assets ? implode(', ', array_slice($new_assets, 0, 5)) : 'assets')
					. ' after the apply';
			}
		}
		return null;
	}

	/**
	 * The verdict on a finished readiness check: null when every check passed,
	 * else why the node cannot take an upgrade yet. Public so the test holds it
	 * to the checks the agent reports.
	 */
	public static function preflight_verdict($job) {
		if ((string)$job->get('mjb_status') !== 'completed') {
			$err = trim((string)$job->get('mjb_error_message'));
			return 'its readiness check ' . $job->get('mjb_status') . ($err !== '' ? ': ' . $err : '');
		}
		$result = json_decode((string)$job->get('mjb_result'), true);
		if (!is_array($result) || empty($result['read'])) {
			return 'the node sent no readable readiness answer, so it cannot be judged';
		}
		if (!empty($result['ok'])) {
			return null;
		}
		$failing = array();
		foreach ((array)($result['checks'] ?? array()) as $c) {
			if (is_array($c) && empty($c['ok'])) {
				$failing[] = ($c['check'] ?? '?') . ': ' . ($c['reason'] ?? '');
			}
		}
		return 'it cannot take an upgrade yet (' . ($failing ? implode('; ', $failing) : 'a check failed') . '); nothing has been changed on it';
	}

	/**
	 * The gate between nodes: null when the apply proved good, else why not.
	 * Public so the test can hold it to the spec's four conditions.
	 */
	public static function gate($job, $release) {
		if ((string)$job->get('mjb_status') !== 'completed') {
			$err = trim((string)$job->get('mjb_error_message'));
			// The node's agent refused to run the upgrade script: a different
			// problem from an upgrade that ran and failed, with a different
			// remedy, so it is named.
			$trust = NodeMonitorHealth::classify_script_trust($err);
			if ($trust === 'untrusted_file') {
				$file = NodeMonitorHealth::refused_path($err);
				return 'the node refused to run its upgrade: ' . ($file !== '' ? $file : 'a deployment file')
					. ' on the node is not the file the installed release signed. Another release will not fix it;'
					. ' that file has to be put back to the installed release\'s version on the node, then the rollout started again';
			}
			if ($trust === 'untrusted_manifest') {
				$err .= ' (the node\'s release manifest cannot be used; its agent fetches a good one from this management node, so check the node\'s trust state before starting again)';
			}
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
		// A node upgrades from the source IT is configured with (apply_update takes
		// no version), so one that follows a newer source - the developers site
		// follows dev - legitimately lands past the release this rollout offers.
		// That is a good apply; only a node short of the release, or one that
		// reports none, is not.
		$after = $apply['version_after'] ?? null;
		if (!is_string($after) || !preg_match('/^\d+\.\d+\.\d+$/', $after) || version_compare($after, $release, '<')) {
			return 'it reports version ' . ($after ?? 'unknown') . ', not ' . $release . ' or later';
		}
		return null;
	}

	/**
	 * Every step not yet started, marked skipped: the rollout will not reach
	 * it. A step a copy held, with nothing queued on its node, is one of them.
	 */
	private static function skip_pending(array $steps): array {
		foreach ($steps as $i => $s) {
			$v = $s['verdict'] ?? '';
			if ($v === 'pending' || ($v === 'running' && !self::under_way($s))) {
				$steps[$i]['verdict'] = 'skipped';
			}
		}
		return $steps;
	}

	/**
	 * Mark a step failed. The first failure is the rollout's halt reason, and
	 * from then on nothing further starts; advance_locked() ends the rollout
	 * once no step is still running.
	 */
	private static function fail_step(StagedRollout $rollout, array &$steps, $pos, $why) {
		$steps[$pos]['verdict'] = 'failed';
		$steps[$pos]['reason'] = mb_substr((string)$why, 0, 500);
		$rollout->set_steps($steps);
		if (!self::halting($rollout)) {
			$rollout->set('srl_halt_reason', mb_substr(($steps[$pos]['name'] ?? ('node #' . $steps[$pos]['node_id'])) . ': ' . $why, 0, 1000));
		}
		$rollout->save();
	}

	private static function finish(StagedRollout $rollout, $status, $reason) {
		$rollout->set('srl_status', $status);
		$rollout->set('srl_halt_reason', $reason !== null ? mb_substr((string)$reason, 0, 1000) : null);
		$rollout->set('srl_finish_time', gmdate('Y-m-d H:i:s'));
		$rollout->save();
	}
}
