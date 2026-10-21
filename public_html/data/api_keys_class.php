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

	protected static $foreign_key_actions = array(
		'apk_usr_user_id' => array('action' => 'permanent_delete'),
	);

	// Key types: machine keys are admin-provisioned integration credentials with
	// slow-hashed secrets; session keys are user-minted device credentials
	// (auth/login) with SHA-256-hashed high-entropy secrets; app passwords are
	// person-minted credentials for ONE purpose (apk_purpose, declared under a
	// plugin's `appAccess` — see AppAccess), for a program that cannot do a
	// passkey sign-in (a mail app). An app password is one KIND of app
	// credential: every rule about app credentials is keyed to apk_purpose and
	// the key's id, never to this type (specs/mail_apps_plugin.md § Core: app
	// access).
	const TYPE_MACHINE = 'machine';
	const TYPE_SESSION = 'session';
	const TYPE_APP_PASSWORD = 'app_password';

	/**
	 * The logged key types whose traffic is metered per person, after
	 * authentication (apiv1.php), rather than against the address it came from:
	 * a browser session ('browser', what ApiAuth logs for one), a session key (a
	 * linked desktop, a phone app) and an app credential. Kept here so the
	 * request log reads the list without naming a kind itself.
	 */
	const PER_PERSON_TYPES = array('browser', self::TYPE_SESSION, self::TYPE_APP_PASSWORD);

	/** What an app password looks like when shown: jm-{public}-{secret}. */
	const APP_PASSWORD_PREFIX = 'jm';

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
	    // 1 read, 2 write only, 3 read + write, 4 read + write + delete (the
	    // capability gate in ApiAuth::authorize()). Any other number is
	    // refused: a value past 4 would pass every gate. NULL stays legal for
	    // rows that predate the rule (the gate reads it as read-only).
	    'apk_permission' => array('type'=>'int4', 'allowed_values'=>array(1, 2, 3, 4)),
	    'apk_ip_restriction' => array('type'=>'varchar(255)'),
	    'apk_scope' => array('type'=>'text'),
	    'apk_start_time' => array('type'=>'timestamp(6)'),
	    'apk_expires_time' => array('type'=>'timestamp(6)'),
	    'apk_last_used_time' => array('type'=>'timestamp(6)'),
	    // The address the key was last used from (IPv4 or IPv6, whichever the
	    // request arrived on), stamped beside apk_last_used_time.
	    'apk_last_used_ip' => array('type'=>'inet', 'is_nullable'=>true),
	    // An app credential's purpose (AppAccess), null for machine and session
	    // keys. A credential with a purpose reaches only the actions declaring
	    // that purpose (ApiAuth::authorize()).
	    'apk_purpose' => array('type'=>'varchar(32)', 'is_nullable'=>true),
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
	 * Mint an app password: a credential for ONE declared purpose, for one
	 * device the person names. Returns the saved key and the password as the
	 * person sees it, `jm-{public}-{secret}` — the only time the secret exists
	 * outside the program it is typed into.
	 *
	 * The caller has already confirmed the person (browser session + step-up);
	 * this only refuses a purpose no active plugin declares, the same way
	 * Setting::put() refuses an undeclared name.
	 *
	 * @return array ['api_key' => ApiKey, 'password' => string]
	 * @throws ApiKeyException
	 */
	public static function CreateAppPassword(int $user_id, string $purpose, string $device_name) {
		if (!AppAccess::isDeclared($purpose)) {
			throw new ApiKeyException('No active plugin declares the app purpose "' . $purpose . '".');
		}
		if (!AppAccess::isAvailable($purpose)) {
			throw new ApiKeyException(AppAccess::label($purpose) . ' are turned off on this site.');
		}
		$device_name = trim(preg_replace('/\s+/', ' ', $device_name));
		if ($device_name === '') {
			throw new ApiKeyException('Name the device this password is for.');
		}

		// Lowercase base32 without padding: no characters a person could misread
		// for another (no 0/1/8/9), and nothing a mail program mangles.
		$public_key = self::base32(random_bytes(10));            // 16 characters
		$secret_plaintext = self::base32(random_bytes(32));      // 52 characters, 256 bits

		$api_key = new ApiKey(NULL);
		$api_key->set('apk_usr_user_id', $user_id);
		$api_key->set('apk_name', mb_substr($device_name, 0, 32));
		$api_key->set('apk_public_key', self::APP_PASSWORD_PREFIX . $public_key);
		$api_key->set('apk_secret_key', hash('sha256', $secret_plaintext));
		$api_key->set('apk_type', self::TYPE_APP_PASSWORD);
		$api_key->set('apk_purpose', $purpose);
		$api_key->set('apk_permission', 4);
		$api_key->set('apk_is_active', TRUE);
		$api_key->save();
		$api_key->load();

		return array(
			'api_key'  => $api_key,
			'password' => self::APP_PASSWORD_PREFIX . '-' . $public_key . '-' . $secret_plaintext,
		);
	}

	/**
	 * Split an app password as typed into its public and secret halves, or null
	 * when it is not shaped like one. Spaces and case are forgiven: the page
	 * shows the password in groups, and people retype what they see.
	 *
	 * @return array{public_key:string, secret_key:string}|null
	 */
	public static function SplitAppPassword(string $typed): ?array {
		$typed = strtolower(preg_replace('/\s+/', '', $typed));
		if (!preg_match('/^' . self::APP_PASSWORD_PREFIX . '-([a-z2-7]{16})-([a-z2-7]{52})$/', $typed, $m)) {
			return null;
		}
		return array('public_key' => self::APP_PASSWORD_PREFIX . $m[1], 'secret_key' => $m[2]);
	}

	/** RFC 4648 base32, lowercase, no padding. */
	private static function base32(string $bytes): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$bits = '';
		foreach (str_split($bytes) as $c) {
			$bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
		}
		$out = '';
		foreach (str_split($bits, 5) as $chunk) {
			$out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
		}
		return $out;
	}

	/**
	 * The purpose this credential is limited to, or null for a machine or
	 * session key. Kind-blind: an app credential of any kind answers here.
	 */
	function purpose(): ?string {
		$purpose = $this->get('apk_purpose');
		return ($purpose === null || $purpose === '') ? null : (string)$purpose;
	}

	/**
	 * How this credential signs in, in words for a listing ("app password"), or
	 * null for a machine or session key. Pages show the kind through this, so
	 * none of them has to name one.
	 */
	function kind_label(): ?string {
		if ($this->get('apk_type') === self::TYPE_APP_PASSWORD) {
			return 'app password';
		}
		return null;
	}

	/** True for a credential limited to one app purpose. */
	function is_app_credential(): bool {
		return $this->purpose() !== null;
	}

	/**
	 * What a record entry in a scope may let a key do to a model, one per
	 * CRUD verb: read (GET one or a list), create (POST), update (PUT),
	 * delete (DELETE).
	 */
	const RECORD_VERBS = array('read', 'create', 'update', 'delete');

	/** An action entry: '{action}' or '{plugin}/{action}'. */
	const SCOPE_ACTION_PATTERN = '~^[a-z0-9_]+(/[a-z0-9_]+)?$~D';

	/** A record entry: '{ModelClass}:{verb}'. */
	const SCOPE_RECORD_PATTERN = '~^([A-Z][A-Za-z0-9_]*):(read|create|update|delete)$~D';

	/**
	 * The entries of apk_scope (comma separated). Empty means unscoped: the
	 * key reaches whatever its permission and its user allow. A scoped key
	 * reaches only what its entries name — see ApiAuth::authorize(). Two
	 * kinds of entry:
	 *
	 *   dns_filtering/resolver_snapshot   an action it may call
	 *   EventRegistrant:read              a model it may read (or create,
	 *                                     update, delete) through the record
	 *                                     endpoints, one verb per entry
	 *
	 * @return string[]
	 */
	function scope() {
		$raw = (string)$this->get('apk_scope');
		if (trim($raw) === '') {
			return array();
		}
		return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
	}

	function is_scoped() {
		return $this->scope() !== array();
	}

	/**
	 * The sandboxed plugin this key is the credential of, or null. Such a key
	 * is made and removed with its plugin (SandboxCredentials), not by hand.
	 */
	function sandbox_plugin(): ?string {
		if (!$this->key) {
			return null;
		}
		foreach (new MultiSandboxInstall(array('sbi_apk_api_key_id' => (int)$this->key)) as $install) {
			return (string)$install->get('sbi_plugin');
		}
		return null;
	}

	/** @return string[] the action entries of the scope */
	function scope_actions(): array {
		return array_values(array_filter($this->scope(), function ($entry) {
			return strpos($entry, ':') === false;
		}));
	}

	/** @return array<string,string[]> model class => the verbs the scope gives it */
	function scope_records(): array {
		$records = array();
		foreach ($this->scope() as $entry) {
			if (preg_match(self::SCOPE_RECORD_PATTERN, $entry, $m)) {
				$records[$m[1]][] = $m[2];
			}
		}
		return $records;
	}

	function scope_allows_action(string $action): bool {
		return in_array($action, $this->scope_actions(), true);
	}

	function scope_allows_record(string $model, string $verb): bool {
		$records = $this->scope_records();
		return isset($records[$model]) && in_array($verb, $records[$model], true);
	}

	/**
	 * Why an entry is not one a scope can hold, or null when it is. A record
	 * entry must name a model class this site has (a typo, or a name that is
	 * not a model, such as a route family, is refused). An action entry is
	 * held to its shape only: an action that does not exist is simply never
	 * matched.
	 */
	public static function scope_entry_problem(string $entry): ?string {
		if (preg_match(self::SCOPE_ACTION_PATTERN, $entry)) {
			return null;
		}
		if (preg_match(self::SCOPE_RECORD_PATTERN, $entry, $m)) {
			return self::is_model_class($m[1]) ? null : "'" . $m[1] . "' in '" . $entry . "' is not a model on this site.";
		}
		return "'" . $entry . "' is neither an action name nor Model:" . implode('|', self::RECORD_VERBS) . '.';
	}

	/** Whether a name is a data model class (loading it if it is one). */
	public static function is_model_class(string $name): bool {
		return preg_match('~^[A-Z][A-Za-z0-9_]*$~D', $name) === 1
			&& class_exists($name) && is_subclass_of($name, 'SystemBase');
	}

	/**
	 * The lowest apk_permission that lets every entry through the capability
	 * gate (ApiAuth::authorize()), so a scoped key's permission is never a
	 * second, contradicting answer to what it may do. Reading needs anything
	 * but 2, writing 2 or more, deleting 4. $action_capabilities maps each
	 * action entry to the capability its descriptor declares.
	 */
	public static function permission_for_scope(array $entries, array $action_capabilities): int {
		$needs = array('read' => false, 'write' => false, 'delete' => false);
		foreach ($entries as $entry) {
			if (preg_match(self::SCOPE_RECORD_PATTERN, $entry, $m)) {
				$capability = $m[2] === 'read' ? 'read' : ($m[2] === 'delete' ? 'delete' : 'write');
			} else {
				// An action declaring capability null gates on no permission.
				$capability = array_key_exists($entry, $action_capabilities) ? $action_capabilities[$entry] : 'write';
			}
			if ($capability !== null && isset($needs[$capability])) {
				$needs[$capability] = true;
			}
		}
		if ($needs['delete']) {
			return 4;
		}
		if ($needs['write']) {
			return $needs['read'] ? 3 : 2;
		}
		return 1;
	}

	/** The scope in words, for admin pages and refusals. An entry that parses as neither kind is shown as stored. */
	function scope_label(): string {
		$parts = array();
		foreach ($this->scope() as $entry) {
			if (strpos($entry, ':') === false || !preg_match(self::SCOPE_RECORD_PATTERN, $entry)) {
				$parts[] = $entry;
			}
		}
		foreach ($this->scope_records() as $model => $verbs) {
			$parts[] = $model . ': ' . implode(', ', $verbs);
		}
		return implode('; ', $parts);
	}

	/**
	 * Only a machine key may be scoped: a session key is the user's own device
	 * credential and carries the user's whole reach by design. Every entry
	 * must be one ApiAuth can match, so a typo is refused here rather than
	 * stored as a key that silently reaches nothing.
	 */
	function save($debug = false) {
		// An unsaved key with no type takes the column default, machine.
		$type = $this->get('apk_type') ?: self::TYPE_MACHINE;
		if ($this->is_scoped() && $type !== self::TYPE_MACHINE) {
			throw new ApiKeyException('Only a machine key may be scoped.');
		}
		// The scope is checked when it is set or changed, not on every save:
		// revoking a key must work after a model its scope names has gone
		// (an uninstalled plugin's), and that save does not change the scope.
		if ($this->scope_is_new_or_changed()) {
			if (trim((string)$this->get('apk_scope')) !== '' && !$this->is_scoped()) {
				throw new ApiKeyException('This scope cannot be saved: it names nothing, which would leave the key unscoped.');
			}
			foreach ($this->scope() as $entry) {
				$problem = self::scope_entry_problem($entry);
				if ($problem !== null) {
					throw new ApiKeyException('This scope cannot be saved: ' . $problem);
				}
			}
		}
		$result = parent::save($debug);
		// A deactivated app credential stops reading at once: the window it
		// borrowed ends with it, as on revocation.
		if ($this->key && $this->is_app_credential() && !$this->get('apk_is_active')) {
			VaultUnlock::lockCredentialWindow((int)$this->get('apk_usr_user_id'), (int)$this->key);
		}
		return $result;
	}

	/** Whether apk_scope differs from what is stored, or the key is new. */
	private function scope_is_new_or_changed(): bool {
		if (!$this->key) {
			return true;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT apk_scope FROM apk_api_keys WHERE apk_api_key_id = ?');
		$q->execute(array((int)$this->key));
		$stored = $q->fetchColumn();
		return $stored === false || (string)$stored !== (string)$this->get('apk_scope');
	}

	/**
	 * End the borrowed windows of every live credential of $purpose — the
	 * purpose was switched off or its plugin deactivated, so none of them may
	 * read any more, and none should leave a key in memory until it idles out.
	 */
	public static function LockBorrowedWindowsForPurpose(string $purpose): void {
		foreach (new MultiApiKey(array('purpose' => $purpose, 'deleted' => false)) as $key) {
			VaultUnlock::lockCredentialWindow((int)$key->get('apk_usr_user_id'), (int)$key->key);
		}
	}

	/**
	 * Verify the presented secret. The only type-conditional in the credential
	 * path: session and app-password secrets are 256-bit random values hashed
	 * with SHA-256 (no per-request KDF cost — a mail app checks every 30
	 * seconds); machine secrets keep the slow phpass hash (appropriate for
	 * lower-entropy admin-chosen secrets).
	 */
	function check_secret_key($key) {
		if ($this->is_session() || $this->get('apk_type') === self::TYPE_APP_PASSWORD) {
			return hash_equals($this->get('apk_secret_key'), hash('sha256', $key));
		}
		require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->CheckPassword($key, $this->get('apk_secret_key'));
	}

	/**
	 * Record that this key was just used, and from where, writing at most once
	 * per hour (or when the address changes) to avoid a database write on every
	 * request.
	 */
	function touch_last_used($source_ip = null) {
		$now_utc = gmdate('Y-m-d H:i:s');
		$last = $this->get('apk_last_used_time');
		$ip = self::normalizeIp($source_ip);
		$ip_changed = $ip !== null && !self::sameIp($ip, $this->get('apk_last_used_ip'));
		if (!$ip_changed && $last && LibraryFunctions::time_shift($last, '1 hour', 'Y-m-d H:i:s') > $now_utc) {
			return;
		}
		$columns = array('apk_last_used_time' => $now_utc);
		if ($ip !== null) {
			$columns['apk_last_used_ip'] = $ip;
		}
		foreach ($columns as $column => $value) {
			$this->set($column, $value);
		}
		// Usage tracking persists on GET API requests too. Only these columns
		// are written: this runs inside authentication, where a full save()
		// would re-validate the whole row and could refuse a key over a rule
		// newer than the row, cutting the key off mid-request.
		$id = (int)$this->key;
		SystemBase::server_initiated_write(function () use ($id, $columns) {
			self::updateColumns($id, $columns);
		});
	}

	/** An address in canonical text form, IPv4 or IPv6, or null when it is not one. */
	private static function normalizeIp($ip): ?string {
		$ip = trim((string)$ip);
		if ($ip === '') {
			return null;
		}
		$packed = @inet_pton($ip);
		return $packed === false ? null : inet_ntop($packed);
	}

	/** Compare two addresses as addresses, not text. */
	private static function sameIp(?string $a, $b): bool {
		$b = self::normalizeIp($b);
		return $a !== null && $b !== null && inet_pton($a) === inet_pton($b);
	}

	/**
	 * A revoked key takes its device with it. A linked computer or phone is the
	 * identity that owns a session key (SyncDevice); once the key is gone the
	 * device can reach nothing, and leaving its row live would list a signed-out
	 * phone as still holding the user's keys.
	 */
	function soft_delete() {
		$result = parent::soft_delete();
		if ($result && $this->key && $this->is_app_credential()) {
			// A revoked app credential ends any unlock it was borrowing, now
			// rather than at the window's own end.
			VaultUnlock::lockCredentialWindow((int)$this->get('apk_usr_user_id'), (int)$this->key);
		}
		if ($result && $this->key) {
			$device = SyncDevice::for_api_key((int)$this->key);
			if ($device) {
				$device->soft_delete();
			}
		}
		return $result;
	}

	/**
	 * Soft-delete every active session key and app credential owned by a user.
	 * Called from User::save() when the password hash changes — machine keys
	 * survive. App credentials go with the session keys: a person changing their
	 * password because something leaked expects every device signed in as them
	 * to sign in again.
	 */
	public static function RevokeSessionKeysForUser($user_id) {
		$keys = new MultiApiKey(array(
			'user_id' => $user_id,
			'deleted' => false,
		));
		$keys->load();
		foreach ($keys as $key) {
			if ($key->is_session() || $key->is_app_credential()) {
				$key->soft_delete();
			}
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

        if (isset($this->options['purpose'])) {
            $filters['apk_purpose'] = [$this->options['purpose'], PDO::PARAM_STR];
        }

        // Any app credential, whatever its kind or purpose.
        if (isset($this->options['app_credential'])) {
            $filters['apk_purpose'] = $this->options['app_credential'] ? 'IS NOT NULL' : 'IS NULL';
        }


        return $this->_get_resultsv2('apk_api_keys', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
