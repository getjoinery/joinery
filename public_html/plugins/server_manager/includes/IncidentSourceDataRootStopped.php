<?php
/**
 * IncidentSourceDataRootStopped — plane:data_root_stopped: a machine's data
 * root was stopped by hand (joinery_data_root.sh stop), as its last host
 * report says (host_report data_root_stopped; reviewer2 B17 D2).
 *
 * A stop by hand is deliberate, so it is not an alarm: the site on the
 * machine, and every site container on it, does not answer and its data root
 * reads as closed, and IncidentSourceSiteDown and IncidentSourceDataRootLocked
 * stand down for them while the record holds (stopped_for()). It stays a
 * warning on the machine itself, so a stop nobody started again is not
 * forgotten. The record goes when the data root is started again or the
 * machine boots, and the next host report clears this.
 *
 * The plane learns of a stop from the machine's next host report, so a site
 * that stops answering after the machine's last report asks for one: the
 * uptime task asks the machine every REBOOT_REFRESH_SECONDS while
 * report_wanted() holds, and the site-down and locked alarms wait for it
 * (awaiting_report()), for HOLD_SECONDS at most, so an agent that cannot
 * answer still raises them within minutes (reviewer2 F1).
 *
 * @version 1.0
 */
class IncidentSourceDataRootStopped implements IncidentSource {

	const NAME = 'plane:data_root_stopped';

	/** The longest the site-down and locked alarms wait for the machine's report. */
	const HOLD_SECONDS = 600;

	public function name(): string {
		return self::NAME;
	}

	/**
	 * The machine a node's data root lives on: its own, or for a site
	 * container its host's node. null when that cannot be found.
	 */
	public static function machine_of(ManagedNode $node): ?ManagedNode {
		if (trim((string)$node->get('mgn_container_name')) === '' || !(int)$node->get('mgn_mgh_managed_host_id')) {
			return $node;
		}
		try {
			return (new ManagedHost((int)$node->get('mgn_mgh_managed_host_id'), TRUE))->host_node();
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * The stop by hand a node's data root is under, {at, services}, from the
	 * last host report of the machine it lives on. null when there is none.
	 */
	public static function stopped_for(ManagedNode $node): ?array {
		$machine = self::machine_of($node);
		return $machine ? self::stopped_on($machine) : null;
	}

	/** A UTC time column as a timestamp, or null when empty. */
	private static function time_of(ManagedNode $node, string $column): ?int {
		$v = trim((string)$node->get($column));
		$t = $v === '' ? false : strtotime($v . ' UTC');
		return $t ? $t : null;
	}

	/**
	 * Whether a machine's sites have gone down, or read as locked, since its
	 * last host report, so the report is wanted soon: it says whether the data
	 * root was stopped by hand. The machine's own site, and every site
	 * container placed on it. Only for HOLD_SECONDS after the newest of them:
	 * the alarms open by then, and a machine whose agent cannot answer is not
	 * asked every two minutes for as long as its sites stay down.
	 */
	public static function report_wanted(ManagedNode $machine): bool {
		$since = array();
		foreach (array('mgn_uptime_down_since', 'mgn_uptime_locked_since') as $column) {
			if (($t = self::time_of($machine, $column)) !== null) { $since[] = $t; }
		}
		if ($machine->key) {
			$q = DbConnector::get_instance()->get_db_link()->prepare(
				"SELECT GREATEST(MAX(n.mgn_uptime_down_since), MAX(n.mgn_uptime_locked_since))
				   FROM mgn_managed_nodes n JOIN mgh_managed_hosts h ON n.mgn_mgh_managed_host_id = h.mgh_managed_host_id
				  WHERE h.mgh_mgn_managed_node_id = ? AND h.mgh_delete_time IS NULL
				    AND n.mgn_delete_time IS NULL AND n.mgn_enabled = true AND n.mgn_managed_node_id <> ?");
			$q->execute(array((int)$machine->key, (int)$machine->key));
			$newest = $q->fetchColumn();
			if ($newest && ($t = strtotime($newest . ' UTC'))) { $since[] = $t; }
		}
		if (!$since || time() - max($since) >= self::HOLD_SECONDS) {
			return false;
		}
		$report = self::time_of($machine, 'mgn_last_host_report_time');
		return $report === null || $report < max($since);
	}

	/**
	 * Whether a node's alarm ($since_column: when it went down, or locked)
	 * waits for its machine's host report: the machine's agent reports, no
	 * report has come since, and HOLD_SECONDS have not passed.
	 */
	public static function awaiting_report(ManagedNode $node, string $since_column): bool {
		$since = self::time_of($node, $since_column);
		if ($since === null || time() - $since >= self::HOLD_SECONDS) {
			return false;
		}
		$machine = self::machine_of($node);
		if (!$machine || !$machine->get('mgn_enabled') || !JobCommandBuilder::has_primitive($machine, 'host_report')) {
			return false;
		}
		$report = self::time_of($machine, 'mgn_last_host_report_time');
		return $report === null || $report < $since;
	}

	/**
	 * The stop by hand a machine's own last host report names, or null. A
	 * report older than JobResultProcessor::STATUS_STALE_AFTER_SECONDS is not
	 * trusted for it: a stop it names may be long over, and must not keep a
	 * real outage quiet.
	 */
	public static function stopped_on(ManagedNode $machine): ?array {
		$at = self::time_of($machine, 'mgn_last_host_report_time');
		if ($at === null || $at < time() - JobResultProcessor::STATUS_STALE_AFTER_SECONDS) {
			return null;
		}
		return self::recorded_on($machine);
	}

	/** The stop by hand a machine's last host report names, however old. */
	private static function recorded_on(ManagedNode $machine): ?array {
		$hr = $machine->get('mgn_last_host_report');
		$hr = is_string($hr) ? json_decode($hr, true) : $hr;
		return is_array($hr) ? JobResultProcessor::host_report_data_root_stopped($hr['data_root_stopped'] ?? null) : null;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!$node->get('mgn_enabled')) {
			return null;
		}
		$stopped = self::stopped_on($node);
		if ($stopped === null) {
			return null;
		}
		$detail = array('Stopped' => $stopped['at'] . ' UTC');
		if ($stopped['services']) {
			$detail['Stopped with it'] = implode(', ', $stopped['services']);
		}
		$detail['What is down'] = 'every site and service kept on its data root, until it is started again';
		$detail['Start it'] = 'on the machine, as root: joinery_data_root.sh start (a boot starts it too)';
		return array(
			'title'    => 'Its data root was stopped by hand',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (!$node->get('mgn_enabled')) {
			return 'This node was disabled, so its data root is no longer watched.';
		}
		if (self::recorded_on($node) !== null) {
			return 'Its machine\'s last host report is too old to say whether its data root is still stopped.';
		}
		return 'Its data root was started again.';
	}
}
?>
