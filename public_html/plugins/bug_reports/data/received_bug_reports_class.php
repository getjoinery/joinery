<?php
/**
 * ReceivedBugReport — one problem report another site sent to this one.
 *
 * Sites that upgrade from this one send a report when a member reports an
 * error, and, when their operator allows it, on their own when an unexpected
 * error happens (core ProblemReport::send()). The intake action files it here
 * with the verdict of a call back to the claimed site, and the admin pages
 * group the same fault across sites by its fingerprint
 * (ProblemReportBundle::fingerprint()), or its hash for reports filed before
 * fingerprints.
 *
 * An automatic report of a fault already stored for the same site and version
 * is not stored again: its count is added to rbr_occurrences.
 *
 * Everything in a report is untrusted input from another machine: it is
 * stored as text, escaped on display, and never executed, resolved, or used
 * as a path (specs/implemented/agent_on_node_architecture.md §3.5 rule 8).
 *
 * Verdicts:
 *   verified          the claimed site answered with the claimed version
 *   version_mismatch  the claimed site answered with a different version
 *   unverified        the claimed site did not answer as a Joinery site
 *                     (refused, timed out, private address, or no header —
 *                     a site behind an IP allowlist lands here too)
 *
 * Statuses, which a person sets on a whole fault (triage_group()), the same
 * choices an incident offers:
 *   new       needs a person; counted in the admin notice
 *   snoozed   quiet until rbr_snooze_until, then reads as new again
 *   resolved  dealt with; a recurrence makes it new, and the retention sweep
 *             deletes it after the window
 *   ignored   quiet while it recurs on the versions already reported
 *             (rbr_ignored_through, the newest at the time); the first report
 *             from a newer version makes the fault new again. Kept by the sweep
 *
 * @version 1.4.0 - Resolve, Ignore, Snooze and Reopen on a fault, as an incident offers; seen, closed and muted
 *                  are gone (seen became new, closed resolved, muted ignored, migration br_001)
 * @version 1.3.0 - muted: a fault can be muted across every site and version
 * @version 1.2.0 - the admin notice yields to a management node's incident line, which counts new reports
 *                  for the superadmins who see it (incident_triage.md Q2)
 * @version 1.1.0 - fingerprint grouping; automatic reports and their counts
 * @version 1.0.0
 */

class ReceivedBugReportException extends SystemBaseException {}

class ReceivedBugReport extends SystemBase {
	public static $prefix = 'rbr';
	public static $tablename = 'rbr_received_bug_reports';
	public static $access_class = 'personal';
	public static $pkey_column = 'rbr_received_bug_report_id';

	const VERDICT_VERIFIED   = 'verified';
	const VERDICT_MISMATCH   = 'version_mismatch';
	const VERDICT_UNVERIFIED = 'unverified';

	const STATUS_NEW      = 'new';
	const STATUS_SNOOZED  = 'snoozed';
	const STATUS_RESOLVED = 'resolved';
	const STATUS_IGNORED  = 'ignored';

	const STATUSES = array(self::STATUS_NEW, self::STATUS_SNOOZED, self::STATUS_RESOLVED, self::STATUS_IGNORED);

	/** The snoozes a person picks from, in hours, with their words: an incident's. */
	const SNOOZES = array(
		4   => '4 hours',
		24  => '1 day',
		72  => '3 days',
		168 => '1 week',
	);

	/** The button that sets each state. */
	const VERBS = array(
		self::STATUS_RESOLVED => 'Resolve',
		self::STATUS_IGNORED  => 'Ignore',
		self::STATUS_NEW      => 'Reopen',
	);

	/** A row that needs a person: new, or a snooze that ended. */
	const NEEDS_YOU_SQL = "(rbr_status = 'new' OR (rbr_status = 'snoozed' AND rbr_snooze_until <= now() AT TIME ZONE 'UTC'))";
	/** A row in a snooze that has not ended. */
	const SNOOZING_SQL = "(rbr_status = 'snoozed' AND rbr_snooze_until > now() AT TIME ZONE 'UTC')";

	public static $field_specifications = array(
		'rbr_received_bug_report_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'rbr_received_time'          => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
		// The sender's address: the one personal datum kept, for abuse
		// handling. It goes when the row goes.
		'rbr_sender_ip'              => array('type' => 'varchar(64)', 'is_nullable' => true),
		'rbr_claimed_host'           => array('type' => 'varchar(255)', 'is_nullable' => false),
		'rbr_claimed_version'        => array('type' => 'varchar(64)', 'is_nullable' => false),
		'rbr_verdict'                => array('type' => 'varchar(32)', 'is_nullable' => false),
		'rbr_verdict_reason'         => array('type' => 'text', 'is_nullable' => true),
		'rbr_error_hash'             => array('type' => 'varchar(32)', 'is_nullable' => true, 'index' => true),
		'rbr_error_kind'             => array('type' => 'varchar(255)', 'is_nullable' => true),
		'rbr_error_location'         => array('type' => 'text', 'is_nullable' => true),
		'rbr_error_message'          => array('type' => 'text', 'is_nullable' => true),
		'rbr_comment'                => array('type' => 'text', 'is_nullable' => false),
		'rbr_bundle'                 => array('type' => 'text', 'is_nullable' => false),
		'rbr_fil_file_id'            => array('type' => 'int8', 'is_nullable' => true),
		// Why an attached image was not kept, when it was not.
		'rbr_image_note'             => array('type' => 'text', 'is_nullable' => true),
		'rbr_status'                 => array('type' => 'varchar(16)', 'is_nullable' => false, 'default' => 'new'),
		// Who resolved it, and when: the retention window counts from then.
		'rbr_closed_usr_user_id'     => array('type' => 'int4', 'is_nullable' => true),
		'rbr_closed_time'            => array('type' => 'timestamp(6)', 'is_nullable' => true),
		// Snoozed until; a snooze that ended reads as new.
		'rbr_snooze_until'           => array('type' => 'timestamp(6)', 'is_nullable' => true),
		// Ignored: the newest version reported when it was ignored. A report
		// from a newer version makes the fault new again.
		'rbr_ignored_through'        => array('type' => 'varchar(64)', 'is_nullable' => true),
		// The same-fault key, computed here from the bundle's error section.
		'rbr_fingerprint'            => array('type' => 'varchar(32)', 'is_nullable' => true, 'index' => true),
		// Sent by the site on its own, and how many times the fault happened
		// there on that version (the report itself and every count update).
		'rbr_automatic'              => array('type' => 'bool', 'is_nullable' => false, 'default' => 'false'),
		'rbr_occurrences'            => array('type' => 'int4', 'is_nullable' => false, 'default' => 1),
		'rbr_last_seen_time'         => array('type' => 'timestamp(6)', 'is_nullable' => true),
	);

	public static $index_specifications = array(
		array('columns' => array('rbr_status')),
		array('columns' => array('rbr_received_time')),
		array('columns' => array('rbr_sender_ip', 'rbr_received_time')),
	);

	protected static $foreign_key_actions = array(
		'rbr_fil_file_id'        => array('action' => 'null'),
		'rbr_closed_usr_user_id' => array('action' => 'null', 'source_table' => 'usr_users'),
	);

	// Retention: closed reports past the window go, with their images.
	public static $retention_policy = array(
		'label'          => 'Closed bug reports',
		'purge_method'   => 'purgeExpiredClosed',
		'window_setting' => 'bug_reports_retention_days',
	);

	const PURGE_MAX_PER_RUN = 500;

	/** The bundle, decoded; an empty array when it does not decode. */
	public function bundle(): array {
		$decoded = json_decode((string)$this->get('rbr_bundle'), true);
		return is_array($decoded) ? $decoded : array();
	}

	/** The status as a person reads it: a snooze that ended is new. */
	public function status(): string {
		$status = (string)$this->get('rbr_status');
		if ($status === self::STATUS_SNOOZED) {
			$until = strtotime((string)$this->get('rbr_snooze_until') . ' UTC');
			if ($until === false || $until <= time()) {
				return self::STATUS_NEW;
			}
		}
		return $status;
	}

	/**
	 * What a person can choose to do, as one value each: a state, or a snooze
	 * with its length (snooze_24). The pages speak these.
	 */
	public static function do_options(): array {
		$out = self::VERBS;
		foreach (self::SNOOZES as $h => $words) {
			$out['snooze_' . $h] = 'Snooze ' . $words;
		}
		return $out;
	}

	/**
	 * One "do" value as [state, snooze hours].
	 *
	 * @throws ReceivedBugReportException for a value that is not offered
	 */
	public static function parse_do(string $do): array {
		if (preg_match('/^snooze_(\d+)$/', $do, $m) && isset(self::SNOOZES[(int)$m[1]])) {
			return array(self::STATUS_SNOOZED, (int)$m[1]);
		}
		if (isset(self::VERBS[$do])) {
			return array($do, 0);
		}
		throw new ReceivedBugReportException('That is not something a report can be set to.');
	}

	/**
	 * Set this report's fault to a "do" value: every report of it when it has
	 * an error to group by, else this report alone. How many rows changed.
	 */
	public function triage(string $do, int $user_id): int {
		$group = $this->group_key();
		if (self::is_group_key($group)) {
			return self::triage_group($group, $do, $user_id);
		}
		return self::write_triage('rbr_received_bug_report_id = ?', array((int)$this->key), $do, $user_id,
			array((string)$this->get('rbr_claimed_version')));
	}

	/**
	 * Set every report of a fault to a "do" value (see do_options()). Ignore
	 * records the newest version its reports name. How many rows changed;
	 * 0 for a key that is no fault's.
	 *
	 * @throws ReceivedBugReportException for a value that is not offered
	 */
	public static function triage_group(string $group, string $do, int $user_id): int {
		if (!self::is_group_key($group)) {
			return 0;
		}
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"SELECT DISTINCT rbr_claimed_version FROM rbr_received_bug_reports WHERE " . self::GROUP_KEY . " = ?");
		$stmt->execute(array($group));
		return self::write_triage(self::GROUP_KEY . ' = ?', array($group), $do, $user_id, $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/** One UPDATE setting the rows $where picks to $do; $versions are the versions they name. */
	private static function write_triage(string $where, array $bind, string $do, int $user_id, array $versions): int {
		list($state, $hours) = self::parse_do($do);
		$resolved = $state === self::STATUS_RESOLVED;
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			"UPDATE rbr_received_bug_reports
			    SET rbr_status = ?, rbr_snooze_until = ?, rbr_ignored_through = ?,
			        rbr_closed_usr_user_id = ?, rbr_closed_time = ?
			  WHERE " . $where);
		$stmt->execute(array_merge(array(
			$state,
			$state === self::STATUS_SNOOZED ? gmdate('Y-m-d H:i:s', time() + $hours * 3600) : null,
			$state === self::STATUS_IGNORED ? mb_substr(self::newest_version($versions), 0, 64) : null,
			$resolved ? $user_id : null,
			$resolved ? gmdate('Y-m-d H:i:s') : null,
		), $bind));
		return $stmt->rowCount();
	}

	/** The newest of some version strings, by version_compare(); '' for none. */
	public static function newest_version(array $versions): string {
		$newest = '';
		foreach ($versions as $v) {
			$v = (string)$v;
			if ($newest === '' || version_compare($v, $newest, '>')) {
				$newest = $v;
			}
		}
		return $newest;
	}

	/**
	 * How a fault stands for a report arriving now, from $version: the status a
	 * report the site sent on its own is filed with, and the snooze or ignore
	 * it inherits. A version newer than the one an ignore covers makes the
	 * whole fault new again, here. A person's report is always new; the caller
	 * decides that.
	 *
	 * @return array{status: string, snooze_until: ?string, ignored_through: ?string}
	 */
	public static function arriving_status(string $group, string $version): array {
		$new = array('status' => self::STATUS_NEW, 'snooze_until' => null, 'ignored_through' => null);
		if (!self::is_group_key($group)) {
			return $new;
		}
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT BOOL_OR(" . self::NEEDS_YOU_SQL . ") AS needs_you,
			        MAX(CASE WHEN " . self::SNOOZING_SQL . " THEN rbr_snooze_until END) AS snooze_until,
			        BOOL_OR(rbr_status = 'ignored') AS ignored
			   FROM rbr_received_bug_reports WHERE " . self::GROUP_KEY . " = ?");
		$stmt->execute(array($group));
		$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: array();
		if (!empty($row['needs_you'])) {
			return $new;
		}
		if (!empty($row['snooze_until'])) {
			return array('status' => self::STATUS_SNOOZED, 'snooze_until' => (string)$row['snooze_until'], 'ignored_through' => null);
		}
		if (!empty($row['ignored'])) {
			$stmt = $db->prepare("SELECT DISTINCT rbr_ignored_through FROM rbr_received_bug_reports
				WHERE " . self::GROUP_KEY . " = ? AND rbr_status = 'ignored' AND rbr_ignored_through IS NOT NULL");
			$stmt->execute(array($group));
			$through = self::newest_version($stmt->fetchAll(PDO::FETCH_COLUMN));
			if ($through !== '' && !version_compare($version, $through, '>')) {
				return array('status' => self::STATUS_IGNORED, 'snooze_until' => null, 'ignored_through' => $through);
			}
			// A newer release still has it: the fault is news again.
			$db->prepare("UPDATE rbr_received_bug_reports SET rbr_status = 'new', rbr_ignored_through = NULL
				WHERE " . self::GROUP_KEY . " = ? AND rbr_status = 'ignored'")->execute(array($group));
		}
		return $new;
	}

	/**
	 * How a fault or report stands, in words: Needs you, Snoozed until a
	 * time, Resolved, or Ignored through a version. $state is a status or a
	 * GROUP_VIEWS key; times are stored UTC and shown in $tz.
	 */
	public static function state_words(string $state, ?string $snooze_until, ?string $ignored_through, string $tz): string {
		switch ($state) {
			case self::STATUS_SNOOZED:
				return 'Snoozed until ' . LibraryFunctions::convert_time($snooze_until, 'UTC', $tz, 'M j, g:i A');
			case self::STATUS_RESOLVED:
				return 'Resolved';
			case self::STATUS_IGNORED:
				return 'Ignored' . ((string)$ignored_through !== '' ? ' through ' . $ignored_through : '');
			default:
				return 'Needs you';
		}
	}

	/** How many reports need a person: the admin notice's and the incident line's count. */
	public static function needs_you_count(): int {
		return (int)DbConnector::get_instance()->get_db_link()
			->query("SELECT COUNT(*) FROM rbr_received_bug_reports WHERE " . self::NEEDS_YOU_SQL)->fetchColumn();
	}

	/** Delete the report and its image. */
	function permanent_delete($debug = false) {
		$file_id = (int)$this->get('rbr_fil_file_id');
		if ($file_id > 0 && File::check_if_exists($file_id)) {
			$file = new File($file_id, TRUE);
			$file->permanent_delete();
		}
		return parent::permanent_delete($debug);
	}

	/**
	 * The retention rule: closed reports closed longer ago than the window,
	 * each deleted through permanent_delete() so its image goes too.
	 */
	public static function purgeExpiredClosed($days) {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT rbr_received_bug_report_id FROM rbr_received_bug_reports
			  WHERE rbr_status = 'resolved'
			    AND COALESCE(rbr_closed_time, rbr_received_time) < now() - (INTERVAL '1 day' * :days)
			  ORDER BY rbr_received_time ASC
			  LIMIT :cap");
		$stmt->bindValue(':days', (int)$days, PDO::PARAM_INT);
		$stmt->bindValue(':cap', self::PURGE_MAX_PER_RUN, PDO::PARAM_INT);
		$stmt->execute();
		$removed = 0;
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
			(new ReceivedBugReport((int)$id, TRUE))->permanent_delete();
			$removed++;
		}
		return array(
			'removed' => $removed,
			'message' => $removed ? $removed . ' closed bug report(s) past the ' . (int)$days . '-day window'
				: 'no closed bug reports past the ' . (int)$days . '-day window',
		);
	}

	/** The group key: the fingerprint, else the Part 1 hash, else '' (no recorded error). */
	const GROUP_KEY = "COALESCE(rbr_fingerprint, rbr_error_hash, '')";

	/** Whether $group is a group key a fault can be triaged by: a fingerprint or a hash, never ''. */
	public static function is_group_key(string $group): bool {
		return (bool)preg_match('/^[0-9a-f]{32}$/', $group);
	}

	/** This report's group key. */
	public function group_key(): string {
		return (string)($this->get('rbr_fingerprint') ?: ($this->get('rbr_error_hash') ?: ''));
	}

	/** The views of the grouped list, by how a fault stands, with their words. */
	const GROUP_VIEWS = array(
		'needs_you' => 'Needs you',
		'snoozed'   => 'Snoozed',
		'resolved'  => 'Resolved',
		'ignored'   => 'Ignored',
		'all'       => 'All',
	);

	/** How a group stands, as SQL over its rows: needs you, else snoozed, else ignored, else resolved. */
	const GROUP_STATE_SQL = "CASE WHEN BOOL_OR(" . self::NEEDS_YOU_SQL . ") THEN 'needs_you'
		WHEN BOOL_OR(" . self::SNOOZING_SQL . ") THEN 'snoozed'
		WHEN BOOL_OR(rbr_status = 'ignored') THEN 'ignored'
		ELSE 'resolved' END";

	/**
	 * The same fault grouped across reports: one row per group key, with how
	 * many sites and reports, how many times it happened, how many need a
	 * person, the newest version seen, the first and last time, and how it
	 * stands (state: needs_you, snoozed, ignored or resolved, with the snooze's
	 * end and the version an ignore covers). $view is a GROUP_VIEWS key.
	 * Newest first.
	 */
	public static function groups(string $view = 'all', int $limit = 100): array {
		$having = isset(self::GROUP_VIEWS[$view]) && $view !== 'all' ? ' HAVING ' . self::GROUP_STATE_SQL . ' = :view' : '';
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT " . self::GROUP_KEY . " AS hash,
			        MAX(rbr_error_kind) AS kind,
			        MAX(rbr_error_location) AS location,
			        MAX(rbr_error_message) AS message,
			        COUNT(DISTINCT rbr_claimed_host) AS sites,
			        COUNT(*) AS reports,
			        SUM(rbr_occurrences) AS occurrences,
			        SUM(CASE WHEN " . self::NEEDS_YOU_SQL . " THEN 1 ELSE 0 END) AS needs_you,
			        " . self::GROUP_STATE_SQL . " AS state,
			        MAX(CASE WHEN " . self::SNOOZING_SQL . " THEN rbr_snooze_until END) AS snooze_until,
			        MAX(rbr_ignored_through) AS ignored_through,
			        MAX(rbr_claimed_version) AS newest_version,
			        MIN(rbr_received_time) AS first_time,
			        MAX(COALESCE(rbr_last_seen_time, rbr_received_time)) AS last_time
			   FROM rbr_received_bug_reports
			  GROUP BY " . self::GROUP_KEY . $having . "
			  ORDER BY MAX(COALESCE(rbr_last_seen_time, rbr_received_time)) DESC
			  LIMIT :limit");
		if ($having !== '') {
			$stmt->bindValue(':view', $view);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			// The text MAX of versions is not the newest (0.8.99 > 0.8.550): ask version_compare.
			$v = $db->prepare("SELECT DISTINCT rbr_claimed_version FROM rbr_received_bug_reports WHERE " . self::GROUP_KEY . " = ?");
			$v->execute(array($row['hash']));
			$row['newest_version'] = self::newest_version($v->fetchAll(PDO::FETCH_COLUMN));
		}
		unset($row);
		return $rows;
	}

	/** How many faults stand in each GROUP_VIEWS view. */
	public static function group_counts(): array {
		$out = array_fill_keys(array_keys(self::GROUP_VIEWS), 0);
		$rows = DbConnector::get_instance()->get_db_link()->query(
			"SELECT state, COUNT(*) FROM (SELECT " . self::GROUP_STATE_SQL . " AS state FROM rbr_received_bug_reports
			  GROUP BY " . self::GROUP_KEY . ") g GROUP BY state")->fetchAll(PDO::FETCH_KEY_PAIR);
		foreach ($rows as $state => $n) {
			$out[$state] = (int)$n;
			$out['all'] += (int)$n;
		}
		return $out;
	}

	/**
	 * The admin notice: "N problem reports need you" above every admin page
	 * while any report is new or out of its snooze. Silent otherwise. Shown to the
	 * admins who can read the reports (their read level). Registered by the
	 * plugin's bootstrap.
	 */
	public static function admin_notice(): string {
		try {
			$session = SessionControl::get_instance();
			if (!Access::permits(self::access_level('read'), $session)) {
				return '';
			}
			// On a management node, the incident line counts new reports for
			// superadmins beside the fleet's incidents: one line, not two.
			if (class_exists('IncidentNotice') && Access::permits(10, $session)) {
				return '';
			}
			$new = self::needs_you_count();
		} catch (\Throwable $e) {
			// The table is not built yet: nothing to say.
			return '';
		}
		if ($new === 0) {
			return '';
		}
		return '<div class="alert alert-info" role="alert">'
			. $new . ' problem report' . ($new === 1 ? ' needs' : 's need') . ' you, from sites that upgrade from this one. '
			. '<a href="/plugins/bug_reports/admin/admin_bug_reports">Read them</a>'
			. '</div>';
	}
}

class MultiReceivedBugReport extends SystemMultiBase {
	protected static $model_class = 'ReceivedBugReport';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['status'])) {
			$filters['rbr_status'] = array($this->options['status'], PDO::PARAM_STR);
		}
		if (!empty($this->options['needs_you'])) {
			$filters['(rbr_status'] = "= 'new' OR (rbr_status = 'snoozed' AND rbr_snooze_until <= now() AT TIME ZONE 'UTC'))";
		}
		if (isset($this->options['group'])) {
			// A group key is an md5, or '' for reports with no recorded error.
			$group = (string)$this->options['group'];
			if ($group === '') {
				$filters['(rbr_fingerprint'] = 'IS NULL AND rbr_error_hash IS NULL)';
			} elseif (preg_match('/^[0-9a-f]{32}$/', $group)) {
				$filters['(rbr_fingerprint'] = "= '" . $group . "' OR (rbr_fingerprint IS NULL AND rbr_error_hash = '" . $group . "'))";
			} else {
				$filters['rbr_received_bug_report_id'] = '< 0';
			}
		}
		if (isset($this->options['host'])) {
			$filters['rbr_claimed_host'] = array($this->options['host'], PDO::PARAM_STR);
		}
		if (isset($this->options['version'])) {
			$filters['rbr_claimed_version'] = array($this->options['version'], PDO::PARAM_STR);
		}
		if (isset($this->options['verdict'])) {
			$filters['rbr_verdict'] = array($this->options['verdict'], PDO::PARAM_STR);
		}
		return $this->_get_resultsv2('rbr_received_bug_reports', $filters, $this->order_by, $only_count, $debug);
	}
}
