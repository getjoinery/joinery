<?php
/**
 * PushDestination — one place a member can be alerted outside the site
 * (psh_push_destinations): a browser they allowed, or a phone app.
 *
 * One table for every kind. `psh_kind` names the driver that sends to it
 * (PushSender::driver()); `psh_endpoint` is what that driver addresses — a
 * browser's push-service URL, a phone's token — and is unique, because whoever
 * holds the browser or phone is the one alerted. `psh_details` carries what
 * differs by kind (a browser's p256dh key, auth secret and user agent; a
 * phone's app id).
 *
 * A destination is retired, never soft-deleted: a retired browser has nothing
 * to restore, and a soft-deleted row would keep the endpoint's unique slot.
 * PushSender::retire() is the one path that removes rows — sign-out, a key
 * revoked, a push service saying the destination is gone, a re-minted sending
 * key.
 *
 * Not exposed through the REST API: the endpoint and keys are what let a
 * server alert this browser, and only the push actions handle them.
 *
 * @version 1.0.0
 */
class PushDestinationException extends SystemBaseException {}

class PushDestination extends SystemBase {
	public static $prefix = 'psh';
	public static $tablename = 'psh_push_destinations';
	public static $access_class = 'personal';
	public static $pkey_column = 'psh_push_destination_id';

	const KIND_WEB     = 'web';
	const KIND_IOS     = 'ios';
	const KIND_ANDROID = 'android';

	// A destination means nothing without its owner, or without the app key it
	// was registered under.
	protected static $foreign_key_actions = array(
		'psh_usr_user_id'    => array('action' => 'permanent_delete'),
		'psh_apk_api_key_id' => array('action' => 'permanent_delete'),
	);

	public static $field_specifications = array(
		'psh_push_destination_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'psh_usr_user_id'     => array('type' => 'int4', 'is_nullable' => false, 'required' => true, 'index' => true),
		'psh_kind'            => array('type' => 'varchar(16)', 'is_nullable' => false, 'required' => true),
		'psh_endpoint'        => array('type' => 'varchar(1024)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		// The app key a phone registered under; empty for a browser.
		'psh_apk_api_key_id'  => array('type' => 'int8', 'is_nullable' => true, 'index' => true),
		// sha256 of the device cookie the browser was given when it registered,
		// so signing out on that browser retires it.
		'psh_device_hash'     => array('type' => 'varchar(64)', 'is_nullable' => true, 'index' => true),
		'psh_details'         => array('type' => 'jsonb', 'is_nullable' => true),
		// Sends that failed in a row; a success resets it.
		'psh_failure_count'   => array('type' => 'int4', 'is_nullable' => false, 'default' => 0),
		'psh_last_seen_time'  => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'psh_last_sent_time'  => array('type' => 'timestamp(6)', 'is_nullable' => true),
		'psh_create_time'     => array('type' => 'timestamp(6)', 'is_nullable' => false, 'default' => 'now()'),
	);

	/** The kinds a destination may be. */
	public static function kinds(): array {
		return array(self::KIND_WEB, self::KIND_IOS, self::KIND_ANDROID);
	}

	/** psh_details as an array, whether just set or read back from the database. */
	public function details(): array {
		$value = $this->get('psh_details');
		if (is_array($value)) {
			return $value;
		}
		$decoded = is_string($value) ? json_decode($value, true) : null;
		return is_array($decoded) ? $decoded : array();
	}

	/** The destination registered at this endpoint, or null. */
	public static function for_endpoint(string $endpoint) {
		$q = DbConnector::GetPreparedStatement(
			'SELECT psh_push_destination_id FROM psh_push_destinations WHERE psh_endpoint = ?');
		$q->execute(array($endpoint));
		$id = $q->fetchColumn();
		return ($id === false) ? null : new self((int)$id, true);
	}

}

class MultiPushDestination extends SystemMultiBase {
	protected static $model_class = 'PushDestination';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['psh_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		if (isset($this->options['kind'])) {
			$filters['psh_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}

		return $this->_get_resultsv2('psh_push_destinations', $filters, $this->order_by, $only_count, $debug);
	}
}
