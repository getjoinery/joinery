<?php
/**
 * MailboxSpamKeys — the two per-deployment secrets the spam filter keys its
 * hashes with (spam_learning_in_core.md):
 *
 *   mailbox_sender_fingerprint_key  HMAC key for sender fingerprints
 *                                   (iem_sender_fingerprint, imc_sender_fingerprint,
 *                                   isr_ rows)
 *   mailbox_spam_token_key          HMAC key for corpus tokens (ibt_ rows)
 *
 * Two keys, so a fingerprint and a token can never be compared with each other.
 * Both are declared `managed` in plugin.json: machine-written, never rendered on
 * a form, so no blank submission can remove one. Backups and site copies carry
 * them with every other setting.
 *
 * A key is minted on first use, and minting REFUSES to replace a value already
 * stored: a new fingerprint key would silently orphan every sender record and
 * contact fingerprint, a new token key every corpus row. The write is one
 * compare-and-set statement, so two first uses racing each other agree on
 * whichever key landed first.
 *
 * @version 1.0
 */

class MailboxSpamKeys {

	const FINGERPRINT = 'mailbox_sender_fingerprint_key';
	const TOKEN       = 'mailbox_spam_token_key';

	/** @var array<string,string> name => raw key bytes, per process */
	private static $cache = array();

	/** The raw key bytes for one of the two settings, minting it if absent. */
	public static function get(string $name): string {
		if (isset(self::$cache[$name])) {
			return self::$cache[$name];
		}
		$hex = trim((string)Globalvars::get_instance()->get_setting($name));
		if (!self::wellFormed($hex)) {
			$hex = self::mint($name);
			// Minted inside a caller's transaction, the key commits or rolls back
			// with it. Not remembered then: after a rollback the next use reads the
			// row again rather than hashing under a key that was never stored.
			if (DbConnector::get_instance()->get_db_link()->inTransaction()) {
				return hex2bin($hex);
			}
		}
		return self::$cache[$name] = hex2bin($hex);
	}

	/**
	 * Store a fresh key unless one is already there, and return the key that is
	 * stored. Never overwrites a well-formed value: the conditional update only
	 * touches a row whose value is empty, and the stored row is read back after.
	 */
	public static function mint(string $name): string {
		if ($name !== self::FINGERPRINT && $name !== self::TOKEN) {
			throw new InvalidArgumentException('MailboxSpamKeys: unknown key ' . $name);
		}
		$db = DbConnector::get_instance()->get_db_link();
		$fresh = bin2hex(random_bytes(32));
		$db->prepare(
			"INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
			 VALUES (?, ?, 1, NOW(), NOW(), 'general')
			 ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value, stg_update_time = NOW()
			 WHERE stg_settings.stg_value IS NULL OR stg_settings.stg_value = ''")
			->execute(array($name, $fresh));
		$read = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
		$read->execute(array($name));
		$stored = trim((string)$read->fetchColumn());
		Globalvars::get_instance()->forget_setting($name);
		if (!self::wellFormed($stored)) {
			// Something other than a key sits in the row (a hand edit). Refuse to
			// guess: replacing it is exactly what minting must never do.
			throw new RuntimeException('MailboxSpamKeys: ' . $name . ' holds a value that is not a key; '
				. 'clear it to let a new one be minted.');
		}
		return $stored;
	}

	/** Forget the per-process copies (tests that swap keys). */
	public static function reset(): void {
		self::$cache = array();
	}

	private static function wellFormed(string $hex): bool {
		return strlen($hex) === 64 && ctype_xdigit($hex);
	}
}
?>
