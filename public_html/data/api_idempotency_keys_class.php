<?php
/**
 * ApiIdempotencyKey — one stored outcome of a mutating API action, keyed by the
 * client-supplied Idempotency-Key header (specs/implemented/api_contract_and_idempotency.md
 * § Change 2, pinned in docs/api.md § Contract).
 *
 * A row is created when an authenticated action request first arrives with an
 * Idempotency-Key, and finalized with the response status + body after the
 * action runs. A retry with the same key (same credential, same body) replays
 * the stored response without re-executing; a NULL aik_response_status marks an
 * in-flight original. Rows expire on the window declared in $retention_policy —
 * this is a retry-dedup window, not an archive.
 *
 * The raw client key is never stored (aik_key_hash is its SHA-256, the same
 * store-a-hash convention as session keys). aik_credential_scope pins the row
 * to the owning credential ('key:{apk id}' or 'user:{usr id}' for browser
 * sessions), so two credentials may use the same key string without colliding.
 *
 * Never exposed over CRUD or AI surfaces (defaults stay closed) — the API
 * dispatch layer (ApiLogicEndpoint) is the only reader and writer.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ApiIdempotencyKey extends SystemBase {
	public static $prefix = 'aik';
	public static $tablename = 'aik_api_idempotency_keys';
	public static $pkey_column = 'aik_api_idempotency_key_id';

	// Retention: a key only has to outlive the retry window a client would use.
	// 0 in the setting means never purge.
	public static $retention_policy = array(
		'label'          => 'API idempotency keys',
		'age_column'     => 'aik_create_time',
		'age_unit'       => 'hours',
		'window_setting' => 'idempotency_key_retention_hours',
	);

	public static $field_specifications = array(
		'aik_api_idempotency_key_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		// SHA-256 of the client-supplied Idempotency-Key header value
		'aik_key_hash' => array('type'=>'varchar(64)', 'required'=>true,
			'unique_with'=>array('aik_credential_scope')),
		// Owning credential: 'key:{apk_api_key_id}' or 'user:{usr_user_id}'
		'aik_credential_scope' => array('type'=>'varchar(40)', 'required'=>true),
		// Full action label ('{action}' or '{plugin}/{action}') — a key reused
		// across actions is a conflict, not a replay
		'aik_action' => array('type'=>'varchar(120)', 'required'=>true),
		// SHA-256 of the raw request body — same key + different body is a conflict
		'aik_body_hash' => array('type'=>'varchar(64)', 'required'=>true),
		// Stored outcome; NULL status marks the original request as in-flight
		'aik_response_status' => array('type'=>'int4'),
		'aik_response_body' => array('type'=>'text'),
		'aik_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()', 'index'=>true),
	);

	/**
	 * The credential-scope string for an authenticated principal: the API key
	 * when one was presented, else the browser session's user. Sessionless
	 * principals have no scope (NULL) — idempotency does not apply to them.
	 *
	 * @param ApiKey|null $api_entry
	 * @param User|null $api_user
	 * @return string|null
	 */
	public static function credential_scope($api_entry, $api_user) {
		if ($api_entry !== null && $api_entry->key) {
			return 'key:' . $api_entry->key;
		}
		if ($api_user !== null && $api_user->key) {
			return 'user:' . $api_user->key;
		}
		return null;
	}

	/**
	 * Load the row for a key hash within a credential scope, or null.
	 */
	public static function find($key_hash, $credential_scope) {
		$rows = new MultiApiIdempotencyKey(array(
			'key_hash' => $key_hash,
			'credential_scope' => $credential_scope,
		), NULL, 1);
		$rows->load();
		foreach ($rows as $row) {
			return $row;
		}
		return null;
	}

	/**
	 * Delete rows older than $hours (the retry-dedup window). Returns the
	 * number of rows removed. Kept for callers wanting an explicit purge; the
	 * scheduled sweep works from $retention_policy instead.
	 */
	public static function purge_older_than($hours) {
		$hours = max(1, (int) $hours);
		$cutoff = LibraryFunctions::time_shift(gmdate('Y-m-d H:i:s'), '-' . $hours . ' hours', 'Y-m-d H:i:s');
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare('DELETE FROM ' . static::$tablename . ' WHERE aik_create_time < ?');
		$stmt->execute(array($cutoff));
		return $stmt->rowCount();
	}
}

class MultiApiIdempotencyKey extends SystemMultiBase {
	protected static $model_class = 'ApiIdempotencyKey';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['key_hash'])) {
			$filters['aik_key_hash'] = [$this->options['key_hash'], PDO::PARAM_STR];
		}

		if (isset($this->options['credential_scope'])) {
			$filters['aik_credential_scope'] = [$this->options['credential_scope'], PDO::PARAM_STR];
		}

		if (isset($this->options['created_before'])) {
			$filters['aik_create_time'] = "< '" . gmdate('Y-m-d H:i:s',
				strtotime($this->options['created_before'])) . "'";
		}

		return $this->_get_resultsv2('aik_api_idempotency_keys', $filters,
			$this->order_by, $only_count, $debug);
	}
}
?>
