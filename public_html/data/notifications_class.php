<?php
/**
 * Notification and MultiNotification classes
 *
 * In-app notification system for user-facing events.
 *
 * @version 1.0
 */

class NotificationException extends SystemBaseException {}

class Notification extends SystemBase {
	public static $prefix = 'ntf';
	public static $tablename = 'ntf_notifications';
	public static $pkey_column = 'ntf_notification_id';

	protected static $foreign_key_actions = [
		'ntf_usr_user_id' => ['action' => 'permanent_delete'],
		'ntf_source_usr_user_id' => ['action' => 'set_null']
	];

	public static $field_specifications = array(
		'ntf_notification_id'       => array('type' => 'int8', 'is_nullable' => false, 'serial' => true),
		'ntf_usr_user_id'           => array('type' => 'int4', 'required' => true),
		'ntf_type'                  => array('type' => 'varchar(50)', 'required' => true),
		'ntf_title'                 => array('type' => 'varchar(255)', 'required' => true),
		'ntf_body'                  => array('type' => 'text'),
		'ntf_link'                  => array('type' => 'varchar(255)'),
		'ntf_is_read'               => array('type' => 'bool', 'default' => false),
		'ntf_read_time'             => array('type' => 'timestamp(6)'),
		'ntf_source_usr_user_id'    => array('type' => 'int4'),
		'ntf_create_time'           => array('type' => 'timestamp(6)'),
		'ntf_delete_time'           => array('type' => 'timestamp(6)'),
	);

	/**
	 * Get unread notification count for a user — lightweight, no model instantiation
	 */
	public static function get_unread_count($user_id) {
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT COUNT(*) FROM ntf_notifications
				WHERE ntf_usr_user_id = ? AND ntf_is_read = false AND ntf_delete_time IS NULL";
		$q = $dblink->prepare($sql);
		$q->execute([$user_id]);
		return (int)$q->fetchColumn();
	}

	/**
	 * Factory method — creates and saves a notification
	 */
	public static function create_notification($recipient_user_id, $type, $title, $body, $link = null, $source_user_id = null) {
		require_once(PathHelper::getIncludePath('data/notifications_class.php'));
		$ntf = new Notification(NULL);
		$ntf->set('ntf_usr_user_id', $recipient_user_id);
		$ntf->set('ntf_type', $type);
		$ntf->set('ntf_title', $title);
		$ntf->set('ntf_body', $body);
		$ntf->set('ntf_link', $link);
		$ntf->set('ntf_source_usr_user_id', $source_user_id);
		$ntf->save();

		// Invalidate session cache if the recipient is the current user
		$session = SessionControl::get_instance();
		if ($session->get_user_id() == $recipient_user_id) {
			$_SESSION['notification_unread_count'] = null;
		}

		return $ntf;
	}

	function display_title() {
		return $this->get('ntf_title') ?: '';
	}
}

class MultiNotification extends SystemMultiBase {
	protected static $model_class = 'Notification';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['ntf_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['unread_only']) && $this->options['unread_only']) {
			$filters['ntf_is_read'] = "= false";
		}

		if (isset($this->options['type'])) {
			$filters['ntf_type'] = [$this->options['type'], PDO::PARAM_STR];
		}

		if (isset($this->options['deleted'])) {
			$filters['ntf_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('ntf_notifications', $filters, $this->order_by, $only_count, $debug);
	}
}
