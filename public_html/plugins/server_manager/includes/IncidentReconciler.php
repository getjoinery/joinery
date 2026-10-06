<?php
/**
 * IncidentReconciler — turns what this management node's own detectors
 * stored into incidents (incident_triage.md, The reconciler; WP2).
 *
 * For each registered IncidentSource and each operational node, every tick:
 *   - the condition holds and no incident of that source is active: open one,
 *     or reopen the newest if it cleared within REOPEN_WINDOW (flapping is
 *     one incident, not one per flap), and tell the superadmins;
 *   - it holds and one is active: refresh its title, severity and detail when
 *     they changed, with no event and no notice;
 *   - it is gone and one is active: clear it, with an event.
 * Then a cleared incident nobody had settled (new, or snoozed) is resolved,
 * saying it cleared before anyone looked: needs-you holds only what is still
 * happening, and the open signal already told the superadmins (settle_cleared).
 * This covers every way an incident clears, the agent's own case close too.
 * And a fix awaiting its proof (resolved, still active) that has waited
 * PROOF_WINDOW since it was resolved goes back to new and tells the
 * superadmins again: someone said it was fixed and the source still sees it
 * (return_unproven). Without this, resolving a condition that never clears
 * would hide it for good.
 * An active incident whose node is no longer watched (removed, disabled, or
 * in an install state such as a dormant copy or a site held stopped) is
 * cleared, saying so. On a node removed from the dashboard that holds for
 * every source, an agent's
 * case too: its agent is refused once the node is gone, so nothing would ever
 * report the case closed (clear_removed). An
 * incident whose node row is gone altogether is deleted with its timeline: a
 * node deleted outside its model (raw SQL, as some test fixtures do) skips the
 * deletion rules, and nothing would ever close or show that incident.
 *
 * A DISABLED NODE IS NOT WATCHED. Disabling a node (mgn_enabled false) is a
 * person saying this plane no longer looks after it — a site whose owner told
 * us to stop managing it, for one. Several sources read columns that froze
 * when its agent went (its script trust, its last host report), so watching it
 * would reopen the same incidents every tick for a machine nobody here can act
 * on.
 *
 * Only a scheduled task runs this (ReconcileIncidents): a page view never
 * writes. One advisory lock, so two overlapping ticks never open twice.
 *
 * Notification: one signal per open, per reopen and per unproven fix sent back, never per tick:
 * incident.opened for a warning, incident.opened_critical for a critical one,
 * each addressed to every superadmin. Notify gives each the bell, and email
 * by the signal's default (critical: on) or their own preference.
 *
 * @version 1.6 - the not-watched words name a site held stopped (multi_tenant_docker_hosts WP7)
 * @version 1.5 - settle_cleared(): a cleared incident still new or snoozed is resolved, by nobody, saying so
 * @version 1.4 - return_unproven(): resolved and still active a day later goes back to new and notifies
 * @version 1.3 - each full pass clears every active incident, of any source, on a removed node (clear_removed)
 * @version 1.2 - each full pass deletes incidents whose node row is gone (removed); ManagedNode's
 *                permanent_delete() takes LOCK_KEY (site_copy.md B41)
 * @version 1.1 - a disabled node is not watched; close_for_node() closes a node's open incidents with a reason
 * @version 1.0
 */
class IncidentReconciler {

	const LOCK_KEY = 7720231002;

	/** A condition that returns this soon after clearing reopens the same incident. */
	const REOPEN_WINDOW = 3600;

	/** How long a fix waits for its condition to clear before it is news again. A day covers nightly jobs. */
	const PROOF_WINDOW = 86400;

	/** The timeline's words when a cleared incident nobody had settled is resolved. */
	const SETTLED_TEXT = 'It cleared before anyone looked, so nothing needs doing.';

	/** The timeline's words when an unproven fix goes back to new. */
	const UNPROVEN_TEXT = 'Still happening 24 hours after it was resolved.';

	const SIGNAL_WARNING  = 'incident.opened';
	const SIGNAL_CRITICAL = 'incident.opened_critical';

	/**
	 * Where signals go: SignalBus::dispatch unless a test stands in, so a test
	 * never rings a real superadmin's bell.
	 *
	 * @var callable|null fn(string $signal, array $payload)
	 */
	public static $dispatch = null;

	/**
	 * One pass. $sources and $node_ids narrow it (tests); by default every
	 * registered source and every node. Returns counts:
	 * opened, reopened, refreshed, cleared, removed, and busy when another
	 * pass holds the lock.
	 */
	public static function run(?array $sources = null, ?array $node_ids = null): array {
		$counts = array('opened' => 0, 'reopened' => 0, 'refreshed' => 0, 'cleared' => 0, 'removed' => 0, 'settled' => 0, 'unproven' => 0, 'busy' => false);
		$db = DbConnector::get_instance()->get_db_link();
		if (!(bool)$db->query('SELECT pg_try_advisory_lock(' . self::LOCK_KEY . ')')->fetchColumn()) {
			$counts['busy'] = true;
			return $counts;
		}
		try {
			if ($node_ids === null) {
				$counts['removed'] = self::remove_nodeless();
				$counts['cleared'] += self::clear_removed();
			}
			$sources = $sources ?? IncidentSources::all();
			if (count($sources) === 0) {
				return $counts;
			}
			$watched = array();
			$nodes = new MultiManagedNode(array('deleted' => false), array('mgn_managed_node_id' => 'ASC'));
			foreach ($nodes as $node) {
				if ($node_ids !== null && !in_array((int)$node->key, $node_ids, true)) {
					continue;
				}
				if (!$node->is_operational() || !$node->get('mgn_enabled')) {
					continue;
				}
				foreach ($sources as $source) {
					$watched[(int)$node->key . '|' . $source->name()] = true;
					try {
						$verdict = $source->evaluate($node);
					} catch (Throwable $e) {
						// A source that cannot decide leaves things as they are.
						error_log('[IncidentReconciler] ' . $source->name() . ' on node #' . (int)$node->key . ': ' . $e->getMessage());
						continue;
					}
					$active = IncidentRecord::open_for((int)$node->key, $source->name());
					if ($verdict !== null && $active === null) {
						$counts[self::open_or_reopen($node, $source->name(), $verdict)]++;
					} elseif ($verdict !== null) {
						if (self::refresh($active, $verdict)) {
							$counts['refreshed']++;
						}
					} elseif ($active !== null) {
						self::clear($active, $source->cleared_text($node));
						$counts['cleared']++;
					}
				}
			}
			// Active incidents of these sources on nodes no longer watched.
			foreach (self::active_of(array_keys($sources), $node_ids) as $inc) {
				if (isset($watched[(int)$inc->get('inc_mgn_managed_node_id') . '|' . (string)$inc->get('inc_source')])) {
					continue;
				}
				self::clear($inc, 'This node is no longer watched: it was removed or disabled, it is being installed or is a copy, or its site is held stopped.');
				$counts['cleared']++;
			}
			// Last, so a condition that cleared on this pass is settled now and
			// never sent back.
			$counts['settled'] = self::settle_cleared($node_ids);
			$counts['unproven'] = self::return_unproven($node_ids);
		} finally {
			$db->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
		}
		return $counts;
	}

	/**
	 * Clear every active incident, whatever its source, on a node removed from
	 * the dashboard (soft-deleted). $node_ids narrows it (tests). Returns how
	 * many.
	 */
	public static function clear_removed(?array $node_ids = null): int {
		$ids = DbConnector::get_instance()->get_db_link()->query(
			"SELECT inc_incident_record_id, inc_mgn_managed_node_id FROM inc_incident_records
			 JOIN mgn_managed_nodes ON mgn_managed_node_id = inc_mgn_managed_node_id
			 WHERE mgn_delete_time IS NOT NULL AND inc_status = 'open' AND inc_delete_time IS NULL")->fetchAll(PDO::FETCH_ASSOC);
		$cleared = 0;
		foreach ($ids as $row) {
			if ($node_ids !== null && !in_array((int)$row['inc_mgn_managed_node_id'], $node_ids, true)) {
				continue;
			}
			$inc = new IncidentRecord((int)$row['inc_incident_record_id'], TRUE);
			if ($inc->key) {
				self::clear($inc, 'The node was removed from the dashboard, so nothing reports on it any more.');
				$cleared++;
			}
		}
		return $cleared;
	}

	/**
	 * Delete every incident whose node row no longer exists, through the model
	 * so its timeline goes too. Returns how many.
	 */
	public static function remove_nodeless(): int {
		$ids = DbConnector::get_instance()->get_db_link()->query(
			'SELECT inc_incident_record_id FROM inc_incident_records
			 LEFT JOIN mgn_managed_nodes ON mgn_managed_node_id = inc_mgn_managed_node_id
			 WHERE mgn_managed_node_id IS NULL')->fetchAll(PDO::FETCH_COLUMN);
		$removed = 0;
		foreach ($ids as $id) {
			$inc = new IncidentRecord((int)$id, TRUE);
			if ($inc->key) {
				$inc->permanent_delete();
				$removed++;
			}
		}
		return $removed;
	}

	/**
	 * Resolve every cleared incident still new or snoozed, with no person and a
	 * timeline line. Reopening within the hour still makes it new again.
	 * $node_ids narrows it (tests). Returns how many.
	 */
	public static function settle_cleared(?array $node_ids = null): int {
		$ids = DbConnector::get_instance()->get_db_link()->query(
			"SELECT inc_incident_record_id, inc_mgn_managed_node_id FROM inc_incident_records
			 WHERE inc_status = 'closed' AND inc_triage IN ('new', 'snoozed') AND inc_delete_time IS NULL")->fetchAll(PDO::FETCH_ASSOC);
		$settled = 0;
		foreach ($ids as $row) {
			if ($node_ids !== null && !in_array((int)$row['inc_mgn_managed_node_id'], $node_ids, true)) {
				continue;
			}
			$inc = new IncidentRecord((int)$row['inc_incident_record_id'], TRUE);
			if (!$inc->key) {
				continue;
			}
			$from = (string)$inc->get('inc_triage');
			$now = gmdate('Y-m-d H:i:s');
			$inc->set('inc_triage', IncidentRecord::TRIAGE_RESOLVED);
			$inc->set('inc_triage_time', $now);
			$inc->set('inc_triage_usr_user_id', null);
			$inc->set('inc_snooze_until', null);
			$inc->save();
			IncidentEvent::record((int)$inc->key, IncidentEvent::KIND_TRIAGE, $now, null, self::SETTLED_TEXT,
				array('from' => $from, 'to' => IncidentRecord::TRIAGE_RESOLVED, 'settled' => true));
			$settled++;
		}
		return $settled;
	}

	/**
	 * Put back to new every fix awaiting its proof (resolved, still active)
	 * resolved at least PROOF_WINDOW ago, with a timeline line and a notice.
	 * Any source, agent cases too: the node's own check is what clears those.
	 * $node_ids narrows it (tests). Returns how many.
	 */
	public static function return_unproven(?array $node_ids = null): int {
		$cutoff = time() - self::PROOF_WINDOW;
		$returned = 0;
		foreach (new MultiIncidentRecord(array('view' => 'waiting', 'deleted' => false)) as $inc) {
			$node_id = (int)$inc->get('inc_mgn_managed_node_id');
			if ($node_ids !== null && !in_array($node_id, $node_ids, true)) {
				continue;
			}
			$resolved = strtotime((string)$inc->get('inc_triage_time') . ' UTC');
			if ($resolved === false || $resolved > $cutoff) {
				continue;
			}
			$now = gmdate('Y-m-d H:i:s');
			$inc->set('inc_triage', IncidentRecord::TRIAGE_NEW);
			$inc->set('inc_triage_time', $now);
			$inc->set('inc_triage_usr_user_id', null);
			$inc->set('inc_snooze_until', null);
			$inc->save();
			IncidentEvent::record((int)$inc->key, IncidentEvent::KIND_TRIAGE, $now, null, self::UNPROVEN_TEXT,
				array('from' => IncidentRecord::TRIAGE_RESOLVED, 'to' => IncidentRecord::TRIAGE_NEW, 'unproven' => true));
			$node = new ManagedNode($node_id, TRUE);
			if ($node->key) {
				self::notify($inc, $node, self::UNPROVEN_TEXT . ' On ' . self::node_label($node) . '.');
			}
			$returned++;
		}
		return $returned;
	}

	/** Open a new incident, or reopen one that cleared within the window. Returns 'opened' or 'reopened'. */
	private static function open_or_reopen(ManagedNode $node, string $source, array $verdict): string {
		$now = gmdate('Y-m-d H:i:s');
		$node_id = (int)$node->key;
		$recent = null;
		foreach (new MultiIncidentRecord(array('node_id' => $node_id, 'source' => $source, 'status' => IncidentRecord::STATUS_CLOSED, 'deleted' => false),
			array('inc_incident_record_id' => 'DESC'), 1) as $row) {
			$recent = $row;
		}
		$closed = $recent ? strtotime((string)$recent->get('inc_closed_time') . ' UTC') : false;
		if ($recent !== null && $closed !== false && $closed >= time() - self::REOPEN_WINDOW) {
			$was = $recent->triage();
			$recent->set('inc_status', IncidentRecord::STATUS_OPEN);
			$recent->set('inc_closed_time', null);
			$recent->set('inc_close_reason', null);
			self::apply_verdict($recent, $verdict);
			// It is news again, unless a person said it never is.
			if ($was !== IncidentRecord::TRIAGE_IGNORED) {
				$recent->set('inc_triage', IncidentRecord::TRIAGE_NEW);
				$recent->set('inc_snooze_until', null);
			}
			$recent->set('inc_last_seen_time', $now);
			$recent->save();
			IncidentEvent::record((int)$recent->key, IncidentEvent::KIND_REOPENED, $now, null,
				'It came back within an hour of clearing.', array('triage_was' => $was));
			if ($was !== IncidentRecord::TRIAGE_IGNORED) {
				self::notify($recent, $node, 'It came back on ' . self::node_label($node) . ' at ' . gmdate('H:i') . ' UTC.');
			}
			return 'reopened';
		}

		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT COALESCE(MAX(inc_node_case_id), 0) + 1 FROM inc_incident_records WHERE inc_mgn_managed_node_id = ? AND inc_source = ?');
		$q->execute(array($node_id, $source));
		$inc = new IncidentRecord(NULL);
		$inc->set('inc_mgn_managed_node_id', $node_id);
		$inc->set('inc_source', $source);
		$inc->set('inc_node_case_id', (int)$q->fetchColumn());
		$inc->set('inc_status', IncidentRecord::STATUS_OPEN);
		$inc->set('inc_opened_time', $now);
		$inc->set('inc_triage', IncidentRecord::TRIAGE_NEW);
		$inc->set('inc_first_seen_time', $now);
		$inc->set('inc_last_seen_time', $now);
		self::apply_verdict($inc, $verdict);
		$inc->save();
		IncidentEvent::record((int)$inc->key, IncidentEvent::KIND_OPENED, $now);
		self::notify($inc, $node, 'It started on ' . self::node_label($node) . ' at ' . gmdate('H:i') . ' UTC.');
		return 'opened';
	}

	/** Refresh an active incident from the source's verdict. True when anything changed. */
	private static function refresh(IncidentRecord $inc, array $verdict): bool {
		$before = array((string)$inc->get('inc_title'), (string)$inc->get('inc_severity'), self::detail_json($inc->get('inc_detail')));
		self::apply_verdict($inc, $verdict);
		$after = array((string)$inc->get('inc_title'), (string)$inc->get('inc_severity'), self::detail_json($inc->get('inc_detail')));
		if ($before === $after) {
			return false;
		}
		$inc->save();
		return true;
	}

	/**
	 * Close every open incident on one node, each saying why. For a person's
	 * act that ends the watching (Stop managing); the reconciler's own skip of
	 * a disabled node keeps them closed. Returns how many were closed.
	 */
	public static function close_for_node(int $node_id, string $why): int {
		$closed = 0;
		foreach (new MultiIncidentRecord(array('node_id' => $node_id, 'status' => IncidentRecord::STATUS_OPEN,
				'deleted' => false)) as $inc) {
			self::clear($inc, $why);
			$closed++;
		}
		return $closed;
	}

	private static function clear(IncidentRecord $inc, string $why): void {
		$now = gmdate('Y-m-d H:i:s');
		$inc->set('inc_status', IncidentRecord::STATUS_CLOSED);
		$inc->set('inc_closed_time', $now);
		$inc->set('inc_close_reason', $why);
		$inc->save();
		IncidentEvent::record((int)$inc->key, IncidentEvent::KIND_CLEARED, $now, null, $why);
	}

	private static function apply_verdict(IncidentRecord $inc, array $verdict): void {
		$severity = ($verdict['severity'] ?? '') === IncidentRecord::SEVERITY_CRITICAL
			? IncidentRecord::SEVERITY_CRITICAL : IncidentRecord::SEVERITY_WARNING;
		$detail = array();
		foreach ((array)($verdict['detail'] ?? array()) as $k => $v) {
			$detail[mb_substr((string)$k, 0, 60)] = mb_substr(is_scalar($v) ? (string)$v : json_encode($v), 0, 1000);
		}
		$inc->set('inc_title', mb_substr(trim((string)($verdict['title'] ?? '')) ?: (string)$inc->get('inc_source'), 0, 200));
		$inc->set('inc_severity', $severity);
		$inc->set('inc_detail', $detail ?: null);
	}

	/** A stored or set detail as comparable JSON (key order kept, as jsonb sorts it). */
	private static function detail_json($d): string {
		if (is_string($d)) {
			$d = json_decode($d, true);
		}
		if (!is_array($d) || !$d) {
			return '';
		}
		ksort($d);
		return json_encode($d);
	}

	/** Active incidents of these sources (optionally on these nodes). */
	private static function active_of(array $source_names, ?array $node_ids): array {
		$out = array();
		foreach ($source_names as $name) {
			foreach (new MultiIncidentRecord(array('source' => $name, 'status' => IncidentRecord::STATUS_OPEN, 'deleted' => false)) as $inc) {
				if ($node_ids !== null && !in_array((int)$inc->get('inc_mgn_managed_node_id'), $node_ids, true)) {
					continue;
				}
				$out[] = $inc;
			}
		}
		return $out;
	}

	private static function node_label(ManagedNode $node): string {
		return '#' . (int)$node->key . ' ' . (string)$node->get('mgn_name');
	}

	/** Tell every superadmin: the bell, and email by the signal's default or their choice. $summary says what happened. */
	private static function notify(IncidentRecord $inc, ManagedNode $node, string $summary): void {
		$recipients = array();
		foreach (new MultiUser(array('permission_range' => array(10, 10), 'deleted' => false, 'not_system_users' => true)) as $u) {
			$recipients[] = (int)$u->key;
		}
		if (count($recipients) === 0) {
			return;
		}
		$payload = array(
			'incident_id' => (int)$inc->key,
			'node_id'     => (int)$node->key,
			'node_name'   => self::node_label($node),
			'title'       => $inc->title(),
			'severity'    => (string)$inc->get('inc_severity'),
			'summary'     => $summary,
			'link'        => IncidentViews::url((int)$inc->key),
			'recipients'  => $recipients,
		);
		$signal = $inc->is_critical() ? self::SIGNAL_CRITICAL : self::SIGNAL_WARNING;
		if (self::$dispatch !== null) {
			call_user_func(self::$dispatch, $signal, $payload);
		} else {
			SignalBus::dispatch($signal, $payload);
		}
	}
}
?>
