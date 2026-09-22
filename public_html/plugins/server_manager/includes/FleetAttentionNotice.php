<?php
/**
 * FleetAttentionNotice — four admin-header notices on the management node,
 * from what the fleet has said (specs/agent_tier1_recipes.md, WP3 and slice 7;
 * specs/disk_headroom_and_unit_diagnosis.md §3):
 *
 *   - failed_units:    a node whose latest host_report names a failed unit;
 *   - failing_recipes: a node whose agent said, at its last poll, that a
 *                      recipe's check fails;
 *   - open_cases:      a node with an open case nobody here has marked read;
 *   - failed_backups:  a node whose last scheduled backup failed. A backup is
 *                      the one thing that must not be quietly wrong, and the
 *                      node page is not where anybody looks every day.
 *
 * Rendered through the admin-header registry (AdminNotices) on every admin
 * page, so all three read STORED facts and never probe: the host report
 * column a job stamped, the recipe list the claim stored, and the incident
 * record rows the poll intake wrote. A fleet with nothing wrong renders ''.
 *
 * WHO IS HOSTILE: every node that wrote what these read. Unit names, recipe
 * names and case reasons are node-supplied text, capped on intake and
 * escaped here; the one link in each notice is the plane's own node page by
 * id.
 *
 * @version 1.3 - failing_recipes says a check-only recipe (disk_headroom) repairs nothing and opens its
 *                case at once;
 *                failed_backups: a node whose last scheduled backup failed (MultiManagedNode
 *                reports_failed_backup), with the run's own reason and a link to its job
 * @version 1.2 - failing_recipes: a node whose stored recipe list says a check last failed, loaded by
 *                the database (MultiManagedNode reports_failing_recipe); between a check failing and
 *                a case, which is twenty minutes to an hour armed, nothing else on the plane says so
 * @version 1.1 - render_failed_units loads only the nodes whose report names a failed unit
 *                (MultiManagedNode reports_failed_units), not every node's report on every page
 * @version 1.0
 */
class FleetAttentionNotice {

	/** Nodes named in one notice before "and N more". */
	const NAMED = 5;

	public static function render_failed_units(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$failing = [];
		// Only nodes whose stored report names a failed unit are loaded: the
		// database answers that from the JSON, so a healthy fleet costs one
		// empty query per admin page and no decoding.
		foreach (new MultiManagedNode(['deleted' => false, 'reports_failed_units' => true], ['mgn_name' => 'ASC']) as $node) {
			$report = $node->get('mgn_last_host_report');
			if (is_string($report)) { $report = json_decode($report, true); }
			if (!is_array($report)) { continue; }
			$units = JobResultProcessor::sanitise_host_report($report)['failed_units'];
			if (is_array($units) && count($units) > 0) {
				$failing[(int)$node->key] = ['name' => (string)$node->get('mgn_name'), 'units' => $units];
			}
		}
		return self::failed_units_for($failing);
	}

	/**
	 * The notice for a set of failing nodes: node id => ['name', 'units'].
	 * Public and pure so the wording can be tested.
	 */
	public static function failed_units_for(array $failing): string {
		if (count($failing) === 0) {
			return '';
		}
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
		$parts = [];
		foreach (array_slice($failing, 0, self::NAMED, true) as $node_id => $f) {
			$parts[] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$node_id . '&amp;tab=overview">' . $e($f['name']) . '</a>'
				. ' (' . $e(implode(', ', array_map('strval', $f['units']))) . ')';
		}
		$more = count($failing) - count($parts);
		$lead = count($failing) === 1 ? 'A node reports a failed unit.' : count($failing) . ' nodes report failed units.';
		return self::css()
			. '<div class="jy-fleet-notice jy-fleet-notice--warn" role="status"><strong>' . $e($lead) . '</strong> '
			. 'As of each node\'s latest host report: ' . implode('; ', $parts)
			. ($more > 0 ? '; and ' . $more . ' more' : '') . '.</div>';
	}

	public static function render_failing_recipes(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$failing = [];
		foreach (new MultiManagedNode(['deleted' => false, 'reports_failing_recipe' => true], ['mgn_name' => 'ASC']) as $node) {
			$modes = AgentChannelEndpoint::recipes_of($node);
			$recipes = [];
			foreach (AgentChannelEndpoint::recipe_verdicts_of($node) as $name => $verdict) {
				if ($verdict === 'fail') {
					$recipes[$name] = $modes[$name] ?? '';
				}
			}
			if (count($recipes) > 0) {
				$failing[(int)$node->key] = [
					'name'    => (string)$node->get('mgn_name'),
					'recipes' => $recipes,
					'polled'  => (string)$node->get('mgn_agent_last_poll'),
				];
			}
		}
		return self::failing_recipes_for($failing);
	}

	/**
	 * The notice for nodes whose agent last said a recipe's check fails:
	 * node id => ['name', 'recipes' => [recipe => mode], 'polled']. Public
	 * and pure so the wording can be tested.
	 */
	public static function failing_recipes_for(array $failing): string {
		if (count($failing) === 0) {
			return '';
		}
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
		$parts = [];
		foreach (array_slice($failing, 0, self::NAMED, true) as $node_id => $f) {
			$recipes = [];
			foreach ($f['recipes'] as $recipe => $mode) {
				$recipes[] = $e($recipe) . ($mode !== '' ? ' ' . $e($mode) : '');
			}
			$parts[] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$node_id . '&amp;tab=overview">' . $e($f['name']) . '</a>'
				. ' (' . implode(', ', $recipes) . ')';
		}
		$more = count($failing) - count($parts);
		$lead = count($failing) === 1 ? 'A node\'s recipe check is failing.' : count($failing) . ' nodes have a failing recipe check.';
		return self::css()
			. '<div class="jy-fleet-notice jy-fleet-notice--warn" role="status"><strong>' . $e($lead) . '</strong> '
			. 'As of each node\'s last poll: ' . implode('; ', $parts)
			. ($more > 0 ? '; and ' . $more . ' more' : '') . '. '
			. 'An armed recipe repairs on its own and this clears when its check passes; if it stays, the repair is not fixing it and a case follows. '
			. 'A check-only recipe (disk_headroom) repairs nothing and opens its case at once.</div>';
	}

	public static function render_open_cases(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		if (!class_exists('IncidentRecord')) {
			return '';
		}
		$rows = [];
		$names = [];
		foreach (new MultiIncidentRecord(['status' => IncidentRecord::STATUS_OPEN, 'unread' => true, 'deleted' => false],
			['inc_incident_record_id' => 'DESC'], 50) as $row) {
			$rows[] = $row;
			$node_id = (int)$row->get('inc_mgn_managed_node_id');
			if (!isset($names[$node_id])) {
				try {
					$node = new ManagedNode($node_id, TRUE);
					$names[$node_id] = (string)$node->get('mgn_name');
				} catch (Throwable $e) {
					$names[$node_id] = 'node #' . $node_id;
				}
			}
		}
		return self::open_cases_for($rows, $names);
	}

	/**
	 * The notice for a set of open, unread case rows and the names of their
	 * nodes. Public and pure so the wording can be tested against rows that
	 * were never saved.
	 */
	public static function open_cases_for(array $rows, array $node_names): string {
		if (count($rows) === 0) {
			return '';
		}
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
		$parts = [];
		foreach (array_slice($rows, 0, self::NAMED) as $row) {
			$node_id = (int)$row->get('inc_mgn_managed_node_id');
			$name = $node_names[$node_id] ?? ('node #' . $node_id);
			$parts[] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . $node_id . '&amp;tab=overview">' . $e($name) . '</a>: '
				. $e($row->get('inc_source')) . ' #' . (int)$row->get('inc_node_case_id') . ' — ' . $e($row->get('inc_reason'));
		}
		$more = count($rows) - count($parts);
		$lead = count($rows) === 1 ? 'A node has an open case.' : count($rows) . ' open cases across the fleet.';
		return self::css()
			. '<div class="jy-fleet-notice jy-fleet-notice--alert" role="alert"><strong>' . $e($lead) . '</strong> '
			. 'A recipe gave up and a person is the next actor; the node closes the case itself when its check passes. '
			. implode('; ', $parts) . ($more > 0 ? '; and ' . $more . ' more' : '') . '. Mark a case read on its node page to quiet this.</div>';
	}

	public static function render_failed_backups(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$failing = [];
		foreach (new MultiManagedNode(['deleted' => false, 'reports_failed_backup' => true], ['mgn_name' => 'ASC']) as $node) {
			$f = [
				'name'   => (string)$node->get('mgn_name'),
				'time'   => (string)$node->get('mgn_last_backup_time'),
				'reason' => '',
				'job_id' => 0,
			];
			// The reason is looked up only for the nodes the notice names. The
			// newest backup_run job carries it when that job is the failure; a
			// failure the status check copied from the node's own history has
			// no job here, and the notice says so by linking the Backups tab.
			if (count($failing) < self::NAMED) {
				foreach (new MultiManagementJob(['node_id' => (int)$node->key, 'job_type' => 'backup_run'],
					['mjb_management_job_id' => 'DESC'], 1) as $job) {
					$result = $job->get('mjb_result');
					if (is_string($result)) { $result = json_decode($result, true); }
					$status = is_array($result) ? (string)($result['backup_status'] ?? '') : '';
					if ($status !== '' && $status !== 'success' && $status !== 'warning' && $status !== 'skipped') {
						$f['job_id'] = (int)$job->key;
						$f['reason'] = (string)($result['message'] ?? '');
					}
				}
			}
			$failing[(int)$node->key] = $f;
		}
		return self::failed_backups_for($failing);
	}

	/**
	 * The notice for nodes whose last scheduled backup failed: node id =>
	 * ['name', 'time', 'reason', 'job_id']. A job id of 0 links the node's
	 * Backups tab instead of a run. Public and pure so the wording can be tested.
	 */
	public static function failed_backups_for(array $failing): string {
		if (count($failing) === 0) {
			return '';
		}
		$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
		$parts = [];
		foreach (array_slice($failing, 0, self::NAMED, true) as $node_id => $f) {
			$when = '';
			$ts = ($f['time'] ?? '') !== '' ? strtotime($f['time'] . ' UTC') : false;
			if ($ts !== false) {
				$when = ' at ' . gmdate('Y-m-d H:i', $ts) . ' UTC';
			}
			$reason = trim((string)($f['reason'] ?? ''));
			if (strlen($reason) > 200) {
				$reason = substr($reason, 0, 197) . '...';
			}
			$link = (int)($f['job_id'] ?? 0) > 0
				? '<a href="/admin/server_manager/job_detail?job_id=' . (int)$f['job_id'] . '">the run</a>'
				: '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$node_id . '&amp;tab=backups">Backups</a>';
			$parts[] = '<a href="/admin/server_manager/node_detail?mgn_managed_node_id=' . (int)$node_id . '&amp;tab=backups">' . $e($f['name']) . '</a>'
				. $e($when) . ($reason !== '' ? ' — ' . $e($reason) : '') . ' (' . $link . ')';
		}
		$more = count($failing) - count($parts);
		$lead = count($failing) === 1 ? 'A node\'s last scheduled backup failed.' : count($failing) . ' nodes\' last scheduled backups failed.';
		return self::css()
			. '<div class="jy-fleet-notice jy-fleet-notice--alert" role="alert"><strong>' . $e($lead) . '</strong> '
			. 'Nothing new of theirs is in backup storage until a run succeeds; this clears when one does. '
			. implode('; ', $parts) . ($more > 0 ? '; and ' . $more . ' more' : '') . '.</div>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-fleet-notice{margin:0 0 1rem;padding:.75rem 1rem;border-radius:6px;font-size:.95rem;overflow-wrap:anywhere}'
			. '.jy-fleet-notice--warn{border:1px solid #fcd34d;background:#fffbeb;color:#78350f}'
			. '.jy-fleet-notice--alert{border:1px solid #fca5a5;background:#fef2f2;color:#7f1d1d}'
			. '</style>';
	}
}
?>
