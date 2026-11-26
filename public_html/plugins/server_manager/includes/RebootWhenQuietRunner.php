<?php
/**
 * RebootWhenQuietRunner - reboot a machine that owes a reboot at the first moment
 * nobody is busy on it (specs/automatic_reboot_when_quiet.md WP2).
 *
 * A kernel fix protects a machine only once it restarts, and nothing restarted
 * ours unless a person pressed Reboot. Each tick (the RebootWhenQuiet task,
 * every cron run):
 *
 *   - Nothing starts while a reboot is awaited on any node of this plane
 *     (JobResultProcessor::reboot_awaited()), one came back less than
 *     SETTLE_SECONDS ago, or a plane:reboot_not_back incident is open: one
 *     machine at a time, and a machine that did not come back stops the rest.
 *   - A candidate is a machine (not a site container) whose host report says
 *     a reboot has been owed OWED_SECONDS or more, whose switch
 *     (mgn_auto_reboot) is not 'never', and that reboot_machine would not
 *     refuse (JobCommandBuilder::reboot_refusal()).
 *   - 'quiet' (the default): quiet_check is asked of the machine and of every
 *     site container it hosts, each at most every ASK_SECONDS; when every
 *     answer is quiet and younger than FRESH_SECONDS, it is rebooted.
 *   - The deadline: in the nightly window (WINDOW_HOUR UTC), a 'quiet' machine
 *     that has owed a reboot DEADLINE_SECONDS or more is rebooted, quiet or
 *     not; a 'window' machine is rebooted there whenever it owes one.
 *   - This plane's own machine (ManagedNode::self_node()) goes last: only when
 *     it is the only candidate left, only when quiet, never by the deadline
 *     (owner Q2).
 *
 * The jobs are the record: each quiet_check job holds its answer (why a
 * machine waits), and the reboot_machine job it starts carries
 * 'automatic' => 'reboot_when_quiet' in its parameters. decide() is pure; the
 * test drives it with arrays.
 *
 * @version 1.2 - hosted_containers finds a machine's sites through the host group naming it (mgh_mgn_managed_node_id), as tick() does
 * @version 1.1 - waiting_words finds the machine's hosted sites itself (Fleet Security passed none) and names a hosted site whose agent cannot answer quiet_check, as tick() skips that machine
 * @version 1.0
 */
class RebootWhenQuietRunner {

	/** A reboot owed less than this is left alone: an install may still be finishing. */
	const OWED_SECONDS = 3600;

	/** A machine is asked whether it is quiet at most this often. */
	const ASK_SECONDS = 600;

	/** An answer older than this is not acted on. */
	const FRESH_SECONDS = 300;

	/** A pending ask younger than this is waited for, not asked again. */
	const PENDING_SECONDS = 1800;

	/** After a machine came back, the next reboot waits this long. */
	const SETTLE_SECONDS = 600;

	/** A machine never quiet reboots in the window once it has owed one this long. */
	const DEADLINE_SECONDS = 86400;

	/** The nightly window: this hour, UTC (owner Q1: 04:00-05:00). */
	const WINDOW_HOUR = 4;

	const MODES = array('quiet', 'window', 'never');

	/**
	 * What to do now. Pure.
	 *
	 * @param array $machines each: id, is_self (bool), mode (MODES), owed_since (int|null),
	 *                        containers (node ids), can_ask (bool: it and every container has
	 *                        quiet_check), refusal (string|null)
	 * @param array $asks     node id => the latest quiet_check job: asked_at (int), done (bool),
	 *                        failed (bool), quiet (bool), completed_at (int|null)
	 * @param array $hold     awaited (bool), last_back_at (int|null), not_back (bool)
	 * @return array{ask: int[], reboot: ?int, why: string}
	 */
	public static function decide(array $machines, array $asks, array $hold, int $now): array {
		$out = array('ask' => array(), 'reboot' => null, 'why' => '');
		if (!empty($hold['not_back'])) {
			$out['why'] = 'a machine did not come back from its reboot; nothing reboots until that incident is cleared';
			return $out;
		}
		if (!empty($hold['awaited'])) {
			$out['why'] = 'a reboot is under way';
			return $out;
		}
		if (!empty($hold['last_back_at']) && $now - (int)$hold['last_back_at'] < self::SETTLE_SECONDS) {
			$out['why'] = 'a machine came back less than ten minutes ago';
			return $out;
		}
		$candidates = array();
		foreach ($machines as $m) {
			if (!in_array($m['mode'], array('quiet', 'window'), true) || $m['owed_since'] === null
					|| $now - (int)$m['owed_since'] < self::OWED_SECONDS || $m['refusal'] !== null) {
				continue;
			}
			$candidates[] = $m;
		}
		// Owed longest first; this plane's own machine last.
		usort($candidates, function ($a, $b) {
			return array((int)$a['is_self'], (int)$a['owed_since'], (int)$a['id']) <=> array((int)$b['is_self'], (int)$b['owed_since'], (int)$b['id']);
		});
		$in_window = (int)gmdate('G', $now) === self::WINDOW_HOUR;
		$others = count(array_filter($candidates, function ($m) { return !$m['is_self']; }));
		foreach ($candidates as $m) {
			$owed = $now - (int)$m['owed_since'];
			if ($out['reboot'] === null && !$m['is_self'] && $in_window && ($m['mode'] === 'window' || $owed >= self::DEADLINE_SECONDS)) {
				$out['reboot'] = (int)$m['id'];
				$out['why'] = $m['mode'] === 'window' ? 'its nightly window' : 'it has owed a reboot over a day and the nightly window is open';
				return $out;
			}
			if ($m['mode'] !== 'quiet' || !$m['can_ask']) {
				continue;
			}
			$ids = array_merge(array((int)$m['id']), array_map('intval', $m['containers']));
			$quiet = true;
			foreach ($ids as $id) {
				$a = $asks[$id] ?? null;
				$fresh = $a !== null && $a['done'] && $a['completed_at'] !== null && $now - (int)$a['completed_at'] <= self::FRESH_SECONDS;
				if (!$fresh || !$a['quiet']) { $quiet = false; }
				$pending = $a !== null && !$a['done'] && !$a['failed'] && $now - (int)$a['asked_at'] < self::PENDING_SECONDS;
				if (!$pending && ($a === null || $now - (int)$a['asked_at'] >= self::ASK_SECONDS)) {
					$out['ask'][] = $id;
				}
			}
			if ($quiet && $out['reboot'] === null && (!$m['is_self'] || $others === 0)) {
				$out['reboot'] = (int)$m['id'];
				$out['why'] = 'nobody is busy on it';
			}
		}
		if ($out['reboot'] !== null) {
			$out['ask'] = array();
		}
		return $out;
	}

	/**
	 * One tick: read the nodes and their jobs, decide, queue the asks and the
	 * reboot. Returns a line for the task's message.
	 */
	public static function tick(?int $now = null): string {
		$now = $now ?? time();
		$nodes = array();
		$host_of_group = array();
		foreach (new MultiManagedNode(array('deleted' => false), array('mgn_managed_node_id' => 'ASC')) as $n) {
			if (!$n->is_operational() || !$n->get('mgn_enabled') || $n->agent_moved_away() !== null
					|| trim((string)$n->get('mgn_agent_public_key')) === '') {
				continue;
			}
			$nodes[(int)$n->key] = $n;
		}
		$self = ManagedNode::self_node();
		$containers = array();
		foreach ($nodes as $id => $n) {
			$group = (int)$n->get('mgn_mgh_managed_host_id');
			if (trim((string)$n->get('mgn_container_name')) === '' || $group <= 0) { continue; }
			if (!array_key_exists($group, $host_of_group)) {
				$host_of_group[$group] = null;
				try {
					$h = (new ManagedHost($group, TRUE))->host_node();
					$host_of_group[$group] = $h ? (int)$h->key : null;
				} catch (Exception $e) {
					$host_of_group[$group] = null;
				}
			}
			if ($host_of_group[$group] !== null) { $containers[$host_of_group[$group]][] = $id; }
		}
		$machines = array();
		$awaited = false;
		$last_back = null;
		$not_back = false;
		foreach ($nodes as $id => $n) {
			if (trim((string)$n->get('mgn_container_name')) !== '') { continue; }
			if (JobResultProcessor::reboot_awaited($n)) { $awaited = true; }
			$last = JobResultProcessor::last_reboot($n);
			if ($last !== null && !empty($last['came_back_at'])) {
				$t = strtotime($last['came_back_at'] . ' UTC');
				if ($t !== false) { $last_back = max((int)$last_back, $t); }
			}
			if (IncidentRecord::open_for($id, IncidentSourceRebootNotBack::NAME) !== null) { $not_back = true; }
			$hr = $n->get('mgn_last_host_report');
			if (is_string($hr)) { $hr = json_decode($hr, true); }
			$hr = is_array($hr) ? JobResultProcessor::sanitise_host_report($hr) : array();
			$owed = (($hr['reboot_required'] ?? null) === true && is_int($hr['reboot_required_since'] ?? null)) ? $hr['reboot_required_since'] : null;
			$can_ask = JobCommandBuilder::has_primitive($n, 'quiet_check');
			foreach ($containers[$id] ?? array() as $cid) {
				if (!JobCommandBuilder::has_primitive($nodes[$cid], 'quiet_check')) { $can_ask = false; }
			}
			$machines[] = array(
				'id'         => $id,
				'is_self'    => $self !== null && (int)$self->key === $id,
				'mode'       => self::mode($n),
				'owed_since' => $owed,
				'containers' => $containers[$id] ?? array(),
				'can_ask'    => $can_ask,
				'refusal'    => $owed !== null ? JobCommandBuilder::reboot_refusal($n) : null,
			);
		}
		$ids = array();
		foreach ($machines as $m) {
			$ids = array_merge($ids, array($m['id']), $m['containers']);
		}
		$d = self::decide($machines, self::latest_asks($ids), array('awaited' => $awaited, 'last_back_at' => $last_back, 'not_back' => $not_back), $now);
		foreach ($d['ask'] as $id) {
			try {
				ManagementJob::createFromBuild($id, 'quiet_check', JobCommandBuilder::build_quiet_check($nodes[$id]), array('automatic' => 'reboot_when_quiet'), null);
			} catch (Exception $e) {
				error_log('[RebootWhenQuiet] could not ask node #' . $id . ': ' . $e->getMessage());
			}
		}
		if ($d['reboot'] !== null) {
			$n = $nodes[$d['reboot']];
			try {
				ManagementJob::createFromBuild($d['reboot'], 'reboot_machine', JobCommandBuilder::build_reboot_machine($n),
					array('automatic' => 'reboot_when_quiet', 'why' => $d['why']), null);
				return 'Rebooting ' . $n->get('mgn_name') . ': ' . $d['why'] . '.';
			} catch (Exception $e) {
				return 'Could not reboot ' . $n->get('mgn_name') . ': ' . $e->getMessage();
			}
		}
		if ($d['why'] !== '') {
			return 'Waiting: ' . $d['why'] . '.';
		}
		return $d['ask'] ? 'Asked ' . count($d['ask']) . ' node(s) whether they are quiet.' : 'No machine owes a reboot it can take now.';
	}

	/** A machine's switch: 'quiet' unless set to 'window' or 'never'. */
	public static function mode($node): string {
		$m = (string)$node->get('mgn_auto_reboot');
		return in_array($m, self::MODES, true) ? $m : 'quiet';
	}

	/** The latest quiet_check job of each node in the last hour, as decide() reads it. */
	public static function latest_asks(array $ids): array {
		$out = array();
		if (!$ids) { return $out; }
		foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
			$job = self::latest_answer_job($id, 3600);
			if ($job === null) { continue; }
			$st = (string)$job->get('mjb_status');
			$r = json_decode((string)$job->get('mjb_result'), true);
			$done_at = (string)$job->get('mjb_completed_time');
			$out[$id] = array(
				'asked_at'     => (int)strtotime((string)$job->get('mjb_create_time') . ' UTC'),
				'done'         => $st === 'completed',
				'failed'       => in_array($st, array('failed', 'cancelled'), true),
				'quiet'        => $st === 'completed' && is_array($r) && ($r['quiet'] ?? false) === true,
				'completed_at' => $done_at !== '' ? (int)strtotime($done_at . ' UTC') : null,
			);
		}
		return $out;
	}

	/** A node's latest quiet_check job within $seconds, or null. */
	public static function latest_answer_job(int $node_id, int $seconds) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare("SELECT mjb_management_job_id FROM mjb_management_jobs
			WHERE mjb_mgn_managed_node_id = ? AND mjb_job_type = 'quiet_check' AND mjb_delete_time IS NULL
			  AND mjb_create_time > now() AT TIME ZONE 'UTC' - make_interval(secs => ?)
			ORDER BY mjb_management_job_id DESC LIMIT 1");
		$q->execute(array($node_id, $seconds));
		$id = $q->fetchColumn();
		return $id ? new ManagementJob((int)$id, TRUE) : null;
	}

	/**
	 * Why a machine that owes a reboot has not had it, in words, for the
	 * pages: its switch, a refusal, the busy signals of its latest answer (or
	 * a container's), or that it has not been asked yet. The site containers
	 * it hosts are found here, so every page judges the same set tick() does.
	 */
	public static function waiting_words($node): string {
		$container_ids = self::hosted_containers($node);
		$mode = self::mode($node);
		if ($mode === 'never') {
			return 'set to never reboot automatically';
		}
		$refusal = JobCommandBuilder::reboot_refusal($node);
		if ($refusal !== null) {
			return $refusal;
		}
		if ($mode === 'window') {
			return 'set to reboot in the nightly window (04:00-05:00 UTC)';
		}
		if (!JobCommandBuilder::has_primitive($node, 'quiet_check')) {
			return 'its agent is too old to say whether it is quiet; it reboots in the nightly window once it has owed one a day';
		}
		// tick() asks a machine only when every site it hosts can answer too.
		foreach ($container_ids as $cid) {
			try {
				$c = new ManagedNode($cid, TRUE);
			} catch (Exception $e) {
				continue;
			}
			if (!JobCommandBuilder::has_primitive($c, 'quiet_check')) {
				return 'a site it hosts (' . $c->get('mgn_name') . ') has an agent too old to say whether it is quiet; it reboots in the nightly window once it has owed one a day';
			}
		}
		foreach (array_merge(array((int)$node->key), array_map('intval', $container_ids)) as $id) {
			$job = self::latest_answer_job($id, 3600);
			if ($job === null || (string)$job->get('mjb_status') !== 'completed') { continue; }
			$r = json_decode((string)$job->get('mjb_result'), true);
			if (is_array($r) && ($r['quiet'] ?? false) !== true) {
				return 'busy: ' . JobResultProcessor::quiet_check_words($r) . ($id !== (int)$node->key ? ' (on a site it hosts)' : '');
			}
		}
		return 'reboots by itself at the first quiet moment';
	}

	/**
	 * The ids of the site containers a machine hosts, as tick() pairs them: the
	 * containers of every host group whose host machine (mgh_mgn_managed_node_id)
	 * it is. The host machine itself need not carry the group's id.
	 */
	public static function hosted_containers($node): array {
		if (trim((string)$node->get('mgn_container_name')) !== '') {
			return array();
		}
		$ids = array();
		foreach (new MultiManagedHost(array('mgh_mgn_managed_node_id' => (int)$node->key, 'deleted' => false)) as $host) {
			foreach (new MultiManagedNode(array('deleted' => false, 'mgn_mgh_managed_host_id' => (int)$host->key)) as $c) {
				if (trim((string)$c->get('mgn_container_name')) !== '') { $ids[] = (int)$c->key; }
			}
		}
		return array_values(array_unique($ids));
	}
}
?>
