<?php
/**
 * InboundEmailFilterDeviceProgress - one owner's place in a mail rule's
 * "apply to existing mail" walk over their end-to-end rows
 * (specs/fortress_mobile_apps.md § R14, MailboxDeviceRules).
 *
 * The server's own walk cannot read end-to-end rows, so each row's owner's
 * device walks them. A domain-wide rule reaches several owners' mailboxes, so
 * each owner keeps their own place: one row per (rule, owner), for the request
 * the rule last made (`ifp_request_time` against the rule's
 * `ief_device_backlog_requested_time`). Held in its own table, not on the rule,
 * because a rule row is saved whole — by the backfill task, by an edit — and a
 * save from a copy read before a device moved on would put its place back.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class InboundEmailFilterDeviceProgressException extends SystemBaseException {}

class InboundEmailFilterDeviceProgress extends SystemBase {
	public static $prefix = 'ifp';
	public static $tablename = 'ifp_inbound_email_filter_device_progress';
	public static $pkey_column = 'ifp_inbound_email_filter_device_progress_id';

	protected static $foreign_key_actions = array(
		'ifp_ief_inbound_email_filter_id' => array('action' => 'cascade'),
		'ifp_usr_user_id'                 => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'ifp_inbound_email_filter_device_progress_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'ifp_ief_inbound_email_filter_id' => array('type'=>'int8', 'is_nullable'=>false,
			'unique_with'=>array('ifp_usr_user_id')),
		'ifp_usr_user_id'   => array('type'=>'int4', 'is_nullable'=>false),
		// The rule's request this place belongs to; an older one is a walk to start again.
		'ifp_request_time'  => array('type'=>'timestamp(6)', 'is_nullable'=>false),
		// The highest row this owner's device has covered.
		'ifp_cursor'        => array('type'=>'int8', 'default'=>'0', 'is_nullable'=>false),
		'ifp_done'          => array('type'=>'bool', 'default'=>'false', 'is_nullable'=>false),
		'ifp_update_time'   => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/**
	 * Record $user_id's place in $filter_id's walk for the request made at
	 * $request_time, in one statement (two owners' devices never touch each
	 * other's row, and a device's own two calls cannot interleave a stale value).
	 */
	static function record(int $filter_id, int $user_id, string $request_time, int $cursor, bool $done): void {
		DbConnector::get_instance()->get_db_link()->prepare(
			"INSERT INTO ifp_inbound_email_filter_device_progress
				(ifp_ief_inbound_email_filter_id, ifp_usr_user_id, ifp_request_time, ifp_cursor, ifp_done, ifp_update_time)
			 VALUES (?, ?, ?, ?, ?, now())
			 ON CONFLICT (ifp_ief_inbound_email_filter_id, ifp_usr_user_id) DO UPDATE
			 SET ifp_request_time = EXCLUDED.ifp_request_time, ifp_cursor = EXCLUDED.ifp_cursor,
				 ifp_done = EXCLUDED.ifp_done, ifp_update_time = now()")
			->execute(array($filter_id, $user_id, $request_time, $cursor, $done ? 'true' : 'false'));
	}

	/**
	 * $user_id's place in the walk the rule's current request asked for:
	 * array{cursor:int, done:bool}; a row from an older request counts as none.
	 */
	static function placeFor(int $filter_id, int $user_id, string $request_time): array {
		$stmt = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT ifp_cursor, ifp_done FROM ifp_inbound_email_filter_device_progress
			 WHERE ifp_ief_inbound_email_filter_id = ? AND ifp_usr_user_id = ? AND ifp_request_time = ?');
		$stmt->execute(array($filter_id, $user_id, $request_time));
		$r = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$r) {
			return array('cursor' => 0, 'done' => false);
		}
		$done = $r['ifp_done'];
		return array('cursor' => intval($r['ifp_cursor']),
			'done' => ($done === true || $done === 't' || $done === 1 || $done === '1'));
	}
}

class MultiInboundEmailFilterDeviceProgress extends SystemMultiBase {
	protected static $model_class = 'InboundEmailFilterDeviceProgress';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();
		if (isset($this->options['filter_id'])) {
			$filters['ifp_ief_inbound_email_filter_id'] = array(intval($this->options['filter_id']), PDO::PARAM_INT);
		}
		if (isset($this->options['user_id'])) {
			$filters['ifp_usr_user_id'] = array(intval($this->options['user_id']), PDO::PARAM_INT);
		}
		return $this->_get_resultsv2('ifp_inbound_email_filter_device_progress', $filters, $this->order_by, $only_count, $debug);
	}
}
