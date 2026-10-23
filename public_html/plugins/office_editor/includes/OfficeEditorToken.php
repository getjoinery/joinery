<?php
/**
 * OfficeEditorToken — "user U may read (or write) file F until time T".
 *
 * The editor carries one of these on every request it makes to the site
 * (CheckFileInfo, GetFile, PutFile). It is HMAC-signed under its own key,
 * office_editor_token_key, SecretBox-sealed in stg_settings and minted on first
 * use; deleting that row rotates the key and ends every open editing session.
 *
 * The token answers who and which file. Whether that person may still do it is
 * asked again on every request (DriveHelper::can_read / can_write), so a share
 * revoked mid-session stops the next save, not the token's expiry.
 *
 * Form: base64url(json payload) "." base64url(hmac-sha256). Every character is
 * URL-safe, so the editor puts it in a query string unchanged — which matters,
 * because the proof it signs covers that query string byte for byte.
 *
 * @version 0.1.0
 */
class OfficeEditorToken {

	const KEY_SETTING = 'office_editor_token_key';

	/** How long a token lives: one long working session (spec §4.2). */
	const TTL_SECONDS = 28800;

	/** @var string|null|false the opened key; false = looked, none stored */
	private static $key = null;

	/**
	 * Mint a token for $user_id on $file_id.
	 *
	 * @return array{token:string, expires:int}
	 */
	/**
	 * $origin is the page origin the browser opened the editor from
	 * (https://example.org): the editor posts its messages to it, and the
	 * editor's own requests to the site arrive over loopback, where the
	 * browser's origin cannot be read.
	 */
	public static function mint($user_id, $file_id, $can_write, $origin, $ttl = self::TTL_SECONDS) {
		$expires = time() + max(1, (int)$ttl);
		$payload = array(
			'v' => 1,
			'u' => (int)$user_id,
			'f' => (int)$file_id,
			'w' => $can_write ? 1 : 0,
			'e' => $expires,
			'o' => (string)$origin,
		);
		$body = self::b64(json_encode($payload));
		$sig = self::b64(hash_hmac('sha256', $body, self::key(true), true));
		return array('token' => $body . '.' . $sig, 'expires' => $expires);
	}

	/**
	 * The token's claims when it is well formed, correctly signed, unexpired and
	 * for $file_id; null for anything else.
	 *
	 * @return array{user_id:int, file_id:int, can_write:bool, expires:int, origin:string}|null
	 */
	public static function verify($token, $file_id) {
		if (!is_string($token) || strlen($token) > 1024 || substr_count($token, '.') !== 1) {
			return null;
		}
		list($body, $sig) = explode('.', $token);
		$key = self::key(false);
		if ($key === null || $body === '' || $sig === '') {
			return null;
		}
		$expected = self::b64(hash_hmac('sha256', $body, $key, true));
		if (!hash_equals($expected, $sig)) {
			return null;
		}
		$p = json_decode((string)self::unb64($body), true);
		if (!is_array($p) || ($p['v'] ?? null) !== 1) {
			return null;
		}
		if ((int)($p['e'] ?? 0) < time() || (int)($p['f'] ?? 0) !== (int)$file_id || (int)($p['u'] ?? 0) <= 0) {
			return null;
		}
		$origin = (string)($p['o'] ?? '');
		if (!preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+$#', $origin)) {
			return null;
		}
		return array(
			'user_id'   => (int)$p['u'],
			'file_id'   => (int)$p['f'],
			'can_write' => !empty($p['w']),
			'expires'   => (int)$p['e'],
			'origin'    => $origin,
		);
	}

	/** Mint the key now if none is stored (the sealed-secret reprovision hook). */
	public static function provisionKey() {
		return self::key(true) !== null;
	}

	/** Forget the memoized key (tests that rotate it). */
	public static function forgetKey() {
		self::$key = null;
	}

	/**
	 * The raw 32-byte key, minted on first use with a write that only fills an
	 * empty row: two first-mints racing must not each keep a key the other
	 * overwrote, or the loser's tokens fail on their first save.
	 */
	private static function key($create) {
		if (is_string(self::$key)) {
			return self::$key;
		}
		if (self::$key === false && !$create) {
			return null;
		}
		$settings = Globalvars::get_instance();
		$stored = (string)$settings->get_setting(self::KEY_SETTING);
		if ($stored === '') {
			if (!$create) {
				self::$key = false;
				return null;
			}
			$sealed = (string)Setting::sealForStorage(self::KEY_SETTING, base64_encode(random_bytes(32)));
			$dblink = DbConnector::get_instance()->get_db_link();
			$q = $dblink->prepare(
				"INSERT INTO stg_settings
					(stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
				 VALUES (?, ?, 1, NOW(), NOW(), 'office_editor')
				 ON CONFLICT (stg_name) DO UPDATE
					SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()
					WHERE stg_settings.stg_value IS NULL OR stg_settings.stg_value = ''");
			$q->execute(array(self::KEY_SETTING, $sealed));
			$settings->forget_setting(self::KEY_SETTING);
			$stored = (string)$settings->get_setting(self::KEY_SETTING);
			if ($stored === '') {
				throw new RuntimeException('The document editor token key could not be provisioned.');
			}
		}
		$raw = base64_decode($stored, true);
		if ($raw === false || strlen($raw) !== 32) {
			// Dead (sealed under another deployment's key) or damaged: no tokens
			// verify, and the reconciler re-mints it on its next cold pass.
			self::$key = false;
			return null;
		}
		self::$key = $raw;
		return self::$key;
	}

	private static function b64($bytes) {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}

	private static function unb64($s) {
		return base64_decode(strtr($s, '-_', '+/'), true);
	}
}
