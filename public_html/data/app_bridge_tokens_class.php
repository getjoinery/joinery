<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class AppBridgeTokenException extends SystemBaseException {}

/**
 * App bridge tokens — the single-use, short-TTL tokens that let a native app
 * turn its API session key into a web session for the in-app webview.
 *
 * POST /api/v1/auth/web_session mints one (Mint()); loading /app_bridge?token=…
 * consumes it (ClaimByToken(), atomic so a token can never start two sessions).
 * Only the SHA-256 hash of the token is stored; the plaintext exists once, in
 * the mint response. See docs/mobile_apps.md.
 *
 * @version 1.0.0
 */
class AppBridgeToken extends SystemBase {	public static $prefix = 'abt';
	public static $tablename = 'abt_app_bridge_tokens';
	public static $pkey_column = 'abt_app_bridge_token_id';

	// Seconds from mint to expiry. The webview loads the bridge URL immediately
	// after minting, so the window only needs to absorb app-side latency.
	const TTL_SECONDS = 60;

	protected static $foreign_key_actions = [
		'abt_apk_api_key_id' => ['action' => 'delete'],
		'abt_usr_user_id' => ['action' => 'delete'],
	];

	// Rows are transient (used or expired within a minute); nothing references them.
	public static $permanent_delete_actions = array();

	public static $field_specifications = array(
	    'abt_app_bridge_token_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'abt_apk_api_key_id' => array('type'=>'int8', 'is_nullable'=>false),
	    'abt_usr_user_id' => array('type'=>'int4', 'is_nullable'=>false),
	    'abt_token_hash' => array('type'=>'varchar(64)', 'is_nullable'=>false, 'unique'=>true),
	    'abt_target_path' => array('type'=>'varchar(512)', 'is_nullable'=>false),
	    'abt_client_app' => array('type'=>'varchar(64)'),
	    'abt_expires_time' => array('type'=>'timestamp(6)', 'is_nullable'=>false),
	    'abt_used_time' => array('type'=>'timestamp(6)'),
	    'abt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'abt_delete_time' => array('type'=>'timestamp(6)'),
	);

	// Staff-only if ever exposed; the API surface never reads these rows directly.
	function authenticate_read($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
		}
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	/**
	 * Mint a bridge token for an authenticated session key. Returns the token
	 * plaintext (the only time it exists server-side) and the saved row.
	 *
	 * @param ApiKey $api_key The authenticated session key
	 * @param string $target_path Same-origin relative path to 302 to on consumption
	 * @param string $client_app The client_app header value, recorded onto the web session
	 * @return array ['token' => string plaintext, 'bridge_token' => AppBridgeToken]
	 */
	public static function Mint($api_key, $target_path, $client_app = '') {
		self::PurgeStale();

		$token_plaintext = bin2hex(random_bytes(32));

		$bridge_token = new AppBridgeToken(NULL);
		$bridge_token->set('abt_apk_api_key_id', $api_key->key);
		$bridge_token->set('abt_usr_user_id', $api_key->get('apk_usr_user_id'));
		$bridge_token->set('abt_token_hash', hash('sha256', $token_plaintext));
		$bridge_token->set('abt_target_path', $target_path);
		$bridge_token->set('abt_client_app', substr(trim((string)$client_app), 0, 64));
		$bridge_token->set('abt_expires_time', LibraryFunctions::time_shift(
			gmdate('Y-m-d H:i:s'), self::TTL_SECONDS . ' seconds', 'Y-m-d H:i:s'));
		$bridge_token->save();
		$bridge_token->load();

		return array('token' => $token_plaintext, 'bridge_token' => $bridge_token);
	}

	/**
	 * Consume a bridge token: atomically stamp it used if (and only if) it is
	 * unused and unexpired, then return the loaded row. The single UPDATE is
	 * what makes tokens single-use — two concurrent loads of the same bridge
	 * URL can never both claim it.
	 *
	 * @param string $token_plaintext The token from the bridge URL
	 * @return AppBridgeToken|null The claimed row, or null (unknown/used/expired)
	 */
	public static function ClaimByToken($token_plaintext) {
		if (!is_string($token_plaintext) || !preg_match('/^[0-9a-f]{64}$/', $token_plaintext)) {
			return null;
		}

		$dblink = DbConnector::get_instance()->get_db_link();
		$q = $dblink->prepare(
			"UPDATE abt_app_bridge_tokens
			 SET abt_used_time = now()
			 WHERE abt_token_hash = ?
			   AND abt_used_time IS NULL
			   AND abt_delete_time IS NULL
			   AND abt_expires_time > now()
			 RETURNING abt_app_bridge_token_id");
		$q->execute([hash('sha256', $token_plaintext)]);
		$row = $q->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			return null;
		}

		return new AppBridgeToken($row['abt_app_bridge_token_id'], TRUE);
	}

	/**
	 * Delete tokens that expired over a day ago. Called opportunistically from
	 * Mint() so the table never accumulates.
	 */
	public static function PurgeStale() {
		$dblink = DbConnector::get_instance()->get_db_link();
		$dblink->exec("DELETE FROM abt_app_bridge_tokens WHERE abt_expires_time < now() - interval '1 day'");
	}

}

class MultiAppBridgeToken extends SystemMultiBase {
	protected static $model_class = 'AppBridgeToken';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$items[$entry->key] = '('.$entry->key.') '.$entry->get('abt_client_app');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['api_key_id'])) {
            $filters['abt_apk_api_key_id'] = [$this->options['api_key_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['user_id'])) {
            $filters['abt_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['unused'])) {
            $filters['abt_used_time'] = $this->options['unused'] ? "IS NULL" : "IS NOT NULL";
        }

        if (isset($this->options['deleted'])) {
            $filters['abt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('abt_app_bridge_tokens', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
