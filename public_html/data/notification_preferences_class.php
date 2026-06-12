<?php
/**
 * NotificationPreference and MultiNotificationPreference classes
 *
 * Per-user, per-hook-point preferences for the notification hooks system.
 * One row per (user, hook point) the user has explicitly configured — absence
 * of a row means defaults apply. Two meaningful booleans: subscribe/mute and
 * also-email-me.
 *
 * @version 1.0
 */

class NotificationPreferenceException extends SystemBaseException {}

class NotificationPreference extends SystemBase {
	public static $prefix = 'ntp';

	// REST API per-record scope: only the owner (or staff, permission >= 5) may read or write this row via the API.
	function authenticate_read($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
			}
		}
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}
	public static $tablename = 'ntp_notification_preferences';
	public static $pkey_column = 'ntp_notification_preference_id';

	protected static $foreign_key_actions = [
		'ntp_usr_user_id' => ['action' => 'permanent_delete'],
	];

	public static $field_specifications = array(
		'ntp_notification_preference_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'ntp_usr_user_id'   => array('type' => 'int4', 'required' => true),
		'ntp_hook_point'    => array('type' => 'varchar(100)', 'required' => true),
		'ntp_subscribed'    => array('type' => 'bool', 'default' => true),
		'ntp_email_enabled' => array('type' => 'bool', 'default' => false),
		'ntp_create_time'   => array('type' => 'timestamp(6)'),
		'ntp_delete_time'   => array('type' => 'timestamp(6)'),
	);

	/**
	 * Load the preference row for a (user, hook point) pair, or NULL if none.
	 */
	public static function get_for($user_id, $hook_point) {
		$multi = new MultiNotificationPreference(
			array('user_id' => $user_id, 'hook_point' => $hook_point, 'deleted' => false),
			array(),
			1
		);
		$multi->load();
		foreach ($multi as $pref) {
			return $pref;
		}
		return NULL;
	}
}

class MultiNotificationPreference extends SystemMultiBase {
	protected static $model_class = 'NotificationPreference';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['ntp_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['hook_point'])) {
			$filters['ntp_hook_point'] = array($this->options['hook_point'], PDO::PARAM_STR);
		}

		if (isset($this->options['subscribed'])) {
			$filters['ntp_subscribed'] = $this->options['subscribed'] ? "= true" : "= false";
		}

		if (isset($this->options['deleted'])) {
			$filters['ntp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('ntp_notification_preferences', $filters, $this->order_by, $only_count, $debug);
	}
}
