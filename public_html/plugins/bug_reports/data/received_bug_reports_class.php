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
 * @version 1.1.0 - fingerprint grouping; automatic reports and their counts
 * @version 1.0.0
 */

class ReceivedBugReportException extends SystemBaseException {}

class ReceivedBugReport extends SystemBase {
	public static $prefix = 'rbr';
	public static $tablename = 'rbr_received_bug_reports';
	public static $pkey_column = 'rbr_received_bug_report_id';

	const VERDICT_VERIFIED   = 'verified';
	const VERDICT_MISMATCH   = 'version_mismatch';
	const VERDICT_UNVERIFIED = 'unverified';

	const STATUS_NEW    = 'new';
	const STATUS_SEEN   = 'seen';
	const STATUS_CLOSED = 'closed';

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
		'rbr_closed_usr_user_id'     => array('type' => 'int4', 'is_nullable' => true),
		'rbr_closed_time'            => array('type' => 'timestamp(6)', 'is_nullable' => true),
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

	function authenticate_read($data) {
		if (($data['current_user_permission'] ?? 0) < 9) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}

	function authenticate_write($data) {
		if (($data['current_user_permission'] ?? 0) < 9) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/** The bundle, decoded; an empty array when it does not decode. */
	public function bundle(): array {
		$decoded = json_decode((string)$this->get('rbr_bundle'), true);
		return is_array($decoded) ? $decoded : array();
	}

	/** Mark closed by $user_id. */
	public function close(int $user_id): void {
		$this->set('rbr_status', self::STATUS_CLOSED);
		$this->set('rbr_closed_usr_user_id', $user_id);
		$this->set('rbr_closed_time', gmdate('Y-m-d H:i:s'));
		$this->save();
	}

	/** Open it again. */
	public function reopen(): void {
		$this->set('rbr_status', self::STATUS_SEEN);
		$this->set('rbr_closed_usr_user_id', null);
		$this->set('rbr_closed_time', null);
		$this->save();
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
			  WHERE rbr_status = 'closed'
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

	/** This report's group key. */
	public function group_key(): string {
		return (string)($this->get('rbr_fingerprint') ?: ($this->get('rbr_error_hash') ?: ''));
	}

	/**
	 * The same fault grouped across reports: one row per group key, with how
	 * many sites and reports, how many times it happened, the newest version
	 * seen, the first and last time, and how many are still open.
	 */
	public static function groups(int $limit = 100): array {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT " . self::GROUP_KEY . " AS hash,
			        MAX(rbr_error_kind) AS kind,
			        MAX(rbr_error_location) AS location,
			        MAX(rbr_error_message) AS message,
			        COUNT(DISTINCT rbr_claimed_host) AS sites,
			        COUNT(*) AS reports,
			        SUM(rbr_occurrences) AS occurrences,
			        SUM(CASE WHEN rbr_status <> 'closed' THEN 1 ELSE 0 END) AS open,
			        MAX(rbr_claimed_version) AS newest_version,
			        MIN(rbr_received_time) AS first_time,
			        MAX(COALESCE(rbr_last_seen_time, rbr_received_time)) AS last_time
			   FROM rbr_received_bug_reports
			  GROUP BY " . self::GROUP_KEY . "
			  ORDER BY SUM(CASE WHEN rbr_status <> 'closed' THEN 1 ELSE 0 END) > 0 DESC, MAX(COALESCE(rbr_last_seen_time, rbr_received_time)) DESC
			  LIMIT :limit");
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * The admin notice: "N new problem reports" above every admin page while
	 * any report has not been opened yet. Silent otherwise. Shown to the
	 * admins who can read the reports (permission 9). Registered by the
	 * plugin's bootstrap.
	 */
	public static function admin_notice(): string {
		try {
			$session = SessionControl::get_instance();
			if ((int)$session->get_permission() < 9) {
				return '';
			}
			$new = count(new MultiReceivedBugReport(array('status' => self::STATUS_NEW)));
		} catch (\Throwable $e) {
			// The table is not built yet: nothing to say.
			return '';
		}
		if ($new === 0) {
			return '';
		}
		return '<div class="alert alert-info" role="alert">'
			. $new . ' new problem report' . ($new === 1 ? '' : 's') . ' from sites that upgrade from this one. '
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
		if (!empty($this->options['open'])) {
			$filters['rbr_status'] = "<> 'closed'";
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
