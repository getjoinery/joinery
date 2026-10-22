<?php
/**
 * FleetSecurityRunner - moves a FleetSecurityRun along, one machine or site
 * container at a time (specs/fleet_security_patching.md WP5).
 *
 * Install security updates everywhere: each machine in turn runs its own
 * install_security_updates (the updates its automatic-update policy allows,
 * now), and the run moves on when that job has finished. Machines go first.
 * Then each container host that replaces its site containers (its nightly
 * refresh is on, and its agent has refresh_site_containers) does so once, as
 * its Refresh now: a fresh base when a fix waits, then each site due, down
 * about 12 s each (Q7, owner 2026-10-22). The fix lasts, where one installed
 * inside a container lasts only until the container is next replaced. A site
 * container whose host does not replace its containers installs its own
 * updates in place, after its host. A run stops at the first node whose
 * updater failed, or whose host could not replace its containers, naming it;
 * one with nothing to install, or another package run in the way, is recorded
 * and passed. Stop takes effect between nodes.
 *
 * A site container patched in place whose host is replacing its containers
 * after all is passed: left out of the plan, or skipped while its install is
 * open. The new container carries the fix, and the install inside the old one
 * would die with it and halt the run an hour later as a lost job (reviewer2 c).
 *
 * advance() is called by the AdvanceFleetSecurityRuns task every tick and by
 * the page on load; it is idempotent, and each state is saved before the next
 * is entered, so a crash between two calls resumes where it stood.
 *
 * @version 1.3 - a container host replaces its site containers once, as its Refresh now, in place of an install in each
 *                (Q7); its containers are patched in place only where the host does not replace them
 * @version 1.2 - a node whose agent moved away is not part of the plan, not even as left out
 * @version 1.1 - a container whose host is replacing its containers is left out, or skipped while its install is
 *                open, instead of waiting out a job that dies with the container (reviewer2 c)
 * @version 1.0
 */
class FleetSecurityRunner {

	/** The word each node in a run must report. */
	const DECLARED_WORDS = ['install_security_updates'];

	/** The word a container host replaces its site containers with (its Refresh now). */
	const REFRESH_WORD = 'refresh_site_containers';

	/** A replacement already under way when asked began before this run: the host is asked again this long after. */
	const REFRESH_ASK_AGAIN_SECONDS = 300;

	/** While a host replaces its containers, a fresh host report this often, which says when it has ended. */
	const REFRESH_REPORT_SECONDS = 300;

	/** A host's clock may run this far behind the management node's, where its run's start is compared with the asking. */
	const REFRESH_CLOCK_SLACK_SECONDS = 120;

	/**
	 * A host report taken this long after the asking that shows no run under
	 * way and none recorded since is a run that ended without recording what
	 * it did (killed part way: site_refresh.sh writes its outcome only at its
	 * end), or never began (reviewer2).
	 */
	const REFRESH_ENDED_GRACE_SECONDS = 600;

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
	 * Every operational node a run covers, in order, the steps that cover
	 * them, and the ones it leaves out with why. Steps: each machine's install
	 * (by name), then each container host's replacement of its site
	 * containers (by name, naming the containers), then each site container
	 * patched in place, after its host. A node whose agent lacks the word, or
	 * that is switched off, is left out by name, so "everywhere" never
	 * quietly means "some".
	 *
	 * @param ManagedNode[]|null $candidates the nodes to consider; null is every node on record
	 * @return array{steps: array<int, array{node: ManagedNode, word: string, covers: string[]}>, nodes: ManagedNode[],
	 *               left_out: array<int, array{name: string, reason: string}>}
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
		$covered = array();
		$hosts = array();
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
			$is_container = (trim((string)$n->get('mgn_container_name')) !== '');
			if ($is_container && ($host = self::refreshing_host($n, $hosts)) !== null) {
				$covered[(int)$host->key][] = $n;
				continue;
			}
			if (!JobCommandBuilder::has_primitive($n, 'install_security_updates')) {
				$left_out[] = array('name' => $name, 'reason' => AgentVocabulary::needs_newer_agent_text($n, self::DECLARED_WORDS));
				continue;
			}
			if ($is_container) {
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
		$refreshes = array();
		foreach ($covered as $host_id => $sites) {
			usort($sites, $by_name);
			$refreshes[] = array('host' => $hosts['node:' . $host_id], 'sites' => $sites);
		}
		usort($refreshes, function ($a, $b) use ($by_name) { return $by_name($a['host'], $b['host']); });

		$steps = array();
		$nodes = $machines;
		foreach ($machines as $n) {
			$steps[] = array('node' => $n, 'word' => 'install_security_updates', 'covers' => array());
		}
		foreach ($refreshes as $r) {
			$steps[] = array('node' => $r['host'], 'word' => self::REFRESH_WORD,
				'covers' => array_map(function ($n) { return (string)$n->get('mgn_name'); }, $r['sites']));
			$nodes = array_merge($nodes, $r['sites']);
		}
		foreach ($containers as $n) {
			$steps[] = array('node' => $n, 'word' => 'install_security_updates', 'covers' => array());
			$nodes[] = $n;
		}
		return array('steps' => $steps, 'nodes' => $nodes, 'left_out' => $left_out);
	}

	/**
	 * The host that replaces a site container's container, as its Refresh
	 * now: a host node in operation and switched on, whose agent has the word
	 * and whose last report shows its nightly refresh on. Null where there is
	 * none, and the container is patched in place. $cache holds each group's
	 * answer and each host node by 'node:ID', for one plan.
	 */
	private static function refreshing_host(ManagedNode $container, array &$cache): ?ManagedNode {
		$group = (int)$container->get('mgn_mgh_managed_host_id');
		if ($group <= 0) {
			return null;
		}
		if (!array_key_exists('group:' . $group, $cache)) {
			$cache['group:' . $group] = null;
			try {
				$host = (new ManagedHost($group, TRUE))->host_node();
			} catch (Exception $e) {
				$host = null;
			}
			if ($host && $host->is_operational() && $host->agent_moved_away() === null && $host->get('mgn_enabled')
					&& JobCommandBuilder::has_primitive($host, self::REFRESH_WORD)) {
				$sr = self::host_site_refresh($host);
				if (is_array($sr) && $sr['scheduled']) {
					$cache['group:' . $group] = (int)$host->key;
					$cache['node:' . (int)$host->key] = $host;
				}
			}
		}
		return $cache['group:' . $group] !== null ? $cache['node:' . $cache['group:' . $group]] : null;
	}

	/** A host's last report, through the processor's sanitiser, or null. */
	private static function host_report_of(ManagedNode $host): ?array {
		$report = $host->get('mgn_last_host_report');
		if (is_string($report)) { $report = json_decode($report, true); }
		return is_array($report) ? JobResultProcessor::sanitise_host_report($report) : null;
	}

	/** A host's site_refresh, as its last report gives it, or null. */
	private static function host_site_refresh(ManagedNode $host): ?array {
		$hr = self::host_report_of($host);
		$sr = $hr['site_refresh'] ?? null;
		return is_array($sr) ? $sr : null;
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
			foreach ($plan['steps'] as $p) {
				$steps[] = array('node_id' => (int)$p['node']->key, 'name' => (string)$p['node']->get('mgn_name'),
					'word' => $p['word'], 'covers' => $p['covers'], 'job_id' => null,
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
			if (($step['word'] ?? '') === self::REFRESH_WORD) {
				if (self::advance_refresh($run, $steps, $pos, $node)) {
					continue;
				}
				return;
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
		$hr = $host ? self::host_report_of($host) : null;
		if ($hr === null) {
			return null;
		}
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
	 * A container host's step: its site containers replaced, as its Refresh
	 * now (Q7). The word only starts site_refresh.sh's run under systemd; what
	 * the run did arrives in the host's report, so the step waits for a report
	 * whose run began after the host was asked and has ended, asking for a
	 * fresh report while it waits. A run already under way when asked began
	 * before this one, and may predate the fix, so the host is asked again
	 * once it has ended.
	 *
	 * @return bool true when the step is passed and the run moves on
	 */
	private static function advance_refresh(FleetSecurityRun $run, array $steps, int $pos, ManagedNode $host): bool {
		$step = $steps[$pos];
		$covers = is_array($step['covers'] ?? null) ? $step['covers'] : array();
		$limit = self::refresh_wait_seconds(count($covers));
		$first = (string)($step['first_asked_at'] ?? '');
		if ($first !== '' && time() - (strtotime($first . ' UTC') ?: time()) > $limit) {
			self::fail_step($run, $steps, $pos, 'its site containers have not been replaced in ' . (int)round($limit / 60)
				. ' minutes, the bounds of its own run (is its agent claiming jobs, and is it reporting?)');
			return false;
		}

		if (empty($step['job_id'])) {
			if (time() < (int)($step['ask_again_at'] ?? 0)) {
				return false;
			}
			try {
				$job = ManagementJob::createFromBuild($host->key, self::REFRESH_WORD, JobCommandBuilder::build_refresh_site_containers($host),
					array('fleet_security_run_id' => (int)$run->key), $run->get('fsr_created_by'));
			} catch (Exception $e) {
				self::pass_step($run, $steps, $pos, 'skipped', '', 'its site containers could not be replaced: ' . $e->getMessage());
				return true;
			}
			$steps[$pos]['job_id'] = (int)$job->key;
			$steps[$pos]['verdict'] = 'running';
			$steps[$pos]['first_asked_at'] = $first !== '' ? $first : gmdate('Y-m-d H:i:s');
			unset($steps[$pos]['ask_again_at']);
			$run->set_steps($steps);
			$run->save();
			return false;
		}

		if (empty($step['replacing_since'])) {
			try {
				$job = new ManagementJob((int)$step['job_id'], TRUE);
			} catch (Exception $e) {
				self::fail_step($run, $steps, $pos, 'its job is gone');
				return false;
			}
			if (!in_array((string)$job->get('mjb_status'), JobResultProcessor::TERMINAL_STATUSES, true)) {
				return false;
			}
			JobResultProcessor::process_if_due($job);
			$job->load();
			$r = json_decode((string)$job->get('mjb_result'), true);
			if ((string)$job->get('mjb_status') !== 'completed' || !is_array($r) || empty($r['read'])) {
				self::fail_step($run, $steps, $pos, 'its job ' . $job->get('mjb_status') . ' with no result to read');
				return false;
			}
			if (empty($r['started'])) {
				self::fail_step($run, $steps, $pos, 'its host did not start replacing its site containers'
					. ((string)($r['said'] ?? '') !== '' ? ': ' . $r['said'] : ''));
				return false;
			}
			if (strpos((string)$r['said'], 'already under way') !== false) {
				$steps[$pos]['job_id'] = null;
				$steps[$pos]['ask_again_at'] = time() + self::REFRESH_ASK_AGAIN_SECONDS;
				$steps[$pos]['reason'] = 'a replacement begun before this run is under way; the host is asked again when it ends';
				$run->set_steps($steps);
				$run->save();
				return false;
			}
			$steps[$pos]['replacing_since'] = (string)($job->get('mjb_started_time') ?: $job->get('mjb_create_time'));
			$steps[$pos]['reason'] = '';
			$run->set_steps($steps);
			$run->save();
			$step = $steps[$pos];
		}

		$hr = self::host_report_of($host);
		$sr = is_array($hr['site_refresh'] ?? null) ? $hr['site_refresh'] : null;
		$began = (is_array($sr) && $sr['ran_at'] !== '') ? strtotime($sr['ran_at']) : false;
		$asked = strtotime((string)$step['replacing_since'] . ' UTC');
		$ours = ($began !== false && $asked !== false && $began >= $asked - self::REFRESH_CLOCK_SLACK_SECONDS);
		if ($ours && !$sr['running']) {
			$verdict = self::refresh_verdict($sr);
			$steps[$pos] = array_merge($steps[$pos], $verdict['fields']);
			if ($verdict['verdict'] === 'failed') {
				self::fail_step($run, $steps, $pos, $verdict['reason']);
				return false;
			}
			self::pass_step($run, $steps, $pos, $verdict['verdict'], $verdict['fields']['outcome'], $verdict['reason']);
			return true;
		}
		$generated = is_int($hr['generated_at'] ?? null) ? $hr['generated_at'] : null;
		if (!$ours && is_array($sr) && !$sr['running'] && $asked !== false && $generated !== null
				&& $generated >= $asked + self::REFRESH_ENDED_GRACE_SECONDS) {
			self::fail_step($run, $steps, $pos, 'its host\'s replacement ended without recording what it did, or never began: its report '
				. (int)round(($generated - $asked) / 60) . ' minutes after it was asked shows none under way and none since'
				. ' (journalctl -u joinery-site-refresh on the host says why)');
			return false;
		}
		if (JobCommandBuilder::has_primitive($host, 'host_report')
				&& !ManagementJob::activeOrRecentForNode((int)$host->key, 'host_report', self::REFRESH_REPORT_SECONDS)) {
			try {
				ManagementJob::createFromBuild($host->key, 'host_report', JobCommandBuilder::build_host_report($host), null, $run->get('fsr_created_by'));
			} catch (Exception $e) {
				// The host's own hourly report says the same, later.
			}
		}
		return false;
	}

	/**
	 * How long a host's replacement may take before the run halts on it:
	 * site_refresh.sh's own bounds (an hour for the base, 45 minutes a site),
	 * and ten minutes for asking and reporting.
	 */
	public static function refresh_wait_seconds(int $sites): int {
		return 3600 + 2700 * max(1, $sites) + 600;
	}

	/**
	 * What a host's finished replacement (its report's site_refresh) means for
	 * the run. A run that replaced what was due, or found nothing due, passes,
	 * noting a site passed over or held and a fix its new base still waits for.
	 * A check for fixes that failed, a base that did not build, and a run that
	 * stopped (a second site rolled back on one base, or a site left down)
	 * halt it.
	 *
	 * @return array{verdict: string, reason: string, fields: array}
	 */
	public static function refresh_verdict(array $sr): array {
		$fields = array('outcome' => $sr['run'], 'changed' => count($sr['replaced']), 'reloaded' => array(),
			'still_on_old_code' => array(), 'reboot_required' => false);
		$notes = array();
		foreach ($sr['passed_over'] as $p) {
			$notes[] = $p['site'] . ' passed over (' . str_replace('_', ' ', $p['why']) . ')';
		}
		foreach ($sr['holds'] as $h) {
			$notes[] = $h['site'] . ' held (' . ($h['outcome'] === 'down' ? 'left down' : 'rolled back') . ($h['reason'] !== '' ? ': ' . $h['reason'] : '') . ')';
		}
		if ($sr['run_error'] !== '') {
			$notes[] = $sr['run_error'];
		}
		if ($sr['check'] === 'failed') {
			return array('verdict' => 'failed', 'reason' => 'its host could not check its base for fixes'
				. ($sr['check_error'] !== '' ? ': ' . $sr['check_error'] : ''), 'fields' => $fields);
		}
		switch ($sr['run']) {
			case 'replaced':
			case 'nothing_due':
			case 'incomplete':
				if (is_int($sr['pending_security']) && $sr['pending_security'] > 0) {
					$notes[] = 'its base still waits for ' . $sr['pending_security'] . ' security fix' . ($sr['pending_security'] === 1 ? '' : 'es');
				}
				return array('verdict' => 'done', 'reason' => implode('; ', $notes), 'fields' => $fields);
			case 'held':
				return array('verdict' => 'failed', 'reason' => 'its host stopped replacing its site containers'
					. ($notes ? ': ' . implode('; ', $notes) : ''), 'fields' => $fields);
			case 'base_failed':
				return array('verdict' => 'failed', 'reason' => 'its host could not build a fresh base'
					. ($sr['run_error'] !== '' ? ': ' . $sr['run_error'] : ''), 'fields' => $fields);
			default:
				return array('verdict' => 'failed', 'reason' => 'its host\'s replacement gave no outcome', 'fields' => $fields);
		}
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
