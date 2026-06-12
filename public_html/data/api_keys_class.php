<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ApiKeyException extends SystemBaseException {}

class ApiKey extends SystemBase {	public static $prefix = 'apk';

	// REST API per-record read scope: only the owner (or staff, permission >= 5) may read this row via the API.
	function authenticate_read($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
			}
		}
	}
	public static $tablename = 'apk_api_keys';
	public static $pkey_column = 'apk_api_key_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	// Key types: machine keys are admin-provisioned integration credentials with
	// slow-hashed secrets; session keys are user-minted device credentials
	// (auth/login) with SHA-256-hashed high-entropy secrets.
	const TYPE_MACHINE = 'machine';
	const TYPE_SESSION = 'session';

		/**
	 * Field specifications define database column properties and validation rules
	 *
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 *
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 *
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'apk_api_key_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'apk_usr_user_id' => array('type'=>'int4'),
	    'apk_name' => array('type'=>'varchar(32)'),
	    'apk_public_key' => array('type'=>'varchar(32)'),
	    'apk_secret_key' => array('type'=>'varchar(64)'),
	    'apk_type' => array('type'=>'varchar(16)', 'is_nullable'=>false, 'default'=>'machine'),
	    'apk_permission' => array('type'=>'int4'),
	    'apk_ip_restriction' => array('type'=>'varchar(255)'),
	    'apk_start_time' => array('type'=>'timestamp(6)'),
	    'apk_expires_time' => array('type'=>'timestamp(6)'),
	    'apk_last_used_time' => array('type'=>'timestamp(6)'),
	    'apk_is_active' => array('type'=>'bool'),
	    'apk_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'apk_delete_time' => array('type'=>'timestamp(6)'),
	);

public static function GenerateKey($key) {
		require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->HashPassword($key);
	}

	/**
	 * Mint a session key for a user who has just proven their identity
	 * (POST /api/v1/auth/login). Returns the saved ApiKey and the secret
	 * plaintext — the only time the plaintext ever exists outside the client.
	 *
	 * @param int $user_id Owner of the session
	 * @param string|null $device_label Stored in apk_name (e.g. "Jeremy's iPhone")
	 * @return array ['api_key' => ApiKey, 'secret_key' => string plaintext]
	 */
	public static function CreateSessionKey($user_id, $device_label = NULL) {
		$settings = Globalvars::get_instance();
		$lifetime_days = (int)($settings->get_setting('api_session_key_lifetime_days') ?: 365);

		$public_key = 'sess_' . bin2hex(random_bytes(12));
		$secret_plaintext = bin2hex(random_bytes(32));

		$api_key = new ApiKey(NULL);
		$api_key->set('apk_usr_user_id', $user_id);
		$api_key->set('apk_name', $device_label !== NULL && trim($device_label) !== ''
			? substr(trim($device_label), 0, 32) : 'App session');
		$api_key->set('apk_public_key', $public_key);
		$api_key->set('apk_secret_key', hash('sha256', $secret_plaintext));
		$api_key->set('apk_type', self::TYPE_SESSION);
		$api_key->set('apk_permission', 4);
		$api_key->set('apk_is_active', TRUE);
		$api_key->set('apk_expires_time', LibraryFunctions::time_shift(
			gmdate('Y-m-d H:i:s'), $lifetime_days . ' days', 'Y-m-d H:i:s'));
		$api_key->save();
		$api_key->load();

		return array('api_key' => $api_key, 'secret_key' => $secret_plaintext);
	}

	function is_session() {
		return $this->get('apk_type') === self::TYPE_SESSION;
	}

	/**
	 * Verify the presented secret. The only type-conditional in the credential
	 * path: session secrets are 256-bit random values hashed with SHA-256
	 * (no per-request KDF cost); machine secrets keep the slow phpass hash
	 * (appropriate for lower-entropy admin-chosen secrets).
	 */
	function check_secret_key($key) {
		if ($this->is_session()) {
			return hash_equals($this->get('apk_secret_key'), hash('sha256', $key));
		}
		require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->CheckPassword($key, $this->get('apk_secret_key'));
	}

	/**
	 * Record that this key was just used, writing at most once per hour to
	 * avoid a database write on every request.
	 */
	function touch_last_used() {
		$now_utc = gmdate('Y-m-d H:i:s');
		$last = $this->get('apk_last_used_time');
		if ($last && LibraryFunctions::time_shift($last, '1 hour', 'Y-m-d H:i:s') > $now_utc) {
			return;
		}
		$this->set('apk_last_used_time', $now_utc);
		// Usage tracking persists on GET API requests too
		SystemBase::$allow_get_mutation = true;
		try {
			$this->save();
		} finally {
			SystemBase::$allow_get_mutation = false;
		}
	}

	/**
	 * Soft-delete every active session key owned by a user. Called from
	 * User::save() when the password hash changes — machine keys survive.
	 */
	public static function RevokeSessionKeysForUser($user_id) {
		$keys = new MultiApiKey(array(
			'user_id' => $user_id,
			'type' => self::TYPE_SESSION,
			'deleted' => false,
		));
		$keys->load();
		foreach ($keys as $key) {
			$key->soft_delete();
		}
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

}

class MultiApiKey extends SystemMultiBase {
	protected static $model_class = 'ApiKey';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $api_key) {
			$items[$api_key->key] = '('.$api_key->key.') '.$api_key->get('apk_api_key');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['apk_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['public_key'])) {
            $filters['apk_public_key'] = [$this->options['public_key'], PDO::PARAM_STR];
        }

        if (isset($this->options['type'])) {
            $filters['apk_type'] = [$this->options['type'], PDO::PARAM_STR];
        }

        if (isset($this->options['published'])) {
            $filters['apk_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['apk_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('apk_api_keys', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
