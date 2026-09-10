<?php
/**
 * AdminSecondFactorNotice — the admin-header notice that names the admins
 * who hold no second factor (specs/security_inventory.md S4).
 *
 * A stolen session is the most likely route into any deployment, and an
 * admin account with a password alone is where phishing lands. This notice
 * says which admins are in that state, wherever an admin is looking, with the
 * fix in place: enrol your own factor, or require one of every admin with
 * one click. It is information, never a gate — an owner who reads it and
 * leaves it is making their own call, and the requirement setting is the
 * gate when they want one.
 *
 * Reads stored facts only: the admin rows, their factor columns, and one
 * setting. Silent when every admin holds a factor.
 *
 * @version 1.0
 */
class AdminSecondFactorNotice {

	const SECURITY_URL = '/profile/security';
	const REQUIRE_URL  = '/admin/admin_require_second_factor';

	/** Admins named in the notice before it says "and N more". */
	const NAMED = 4;

	public static function render(): string {
		$session = SessionControl::get_instance();
		$missing = self::adminsWithoutSecondFactor();
		$required = (bool)Globalvars::get_instance()->get_setting('totp_require_admins', false, true);
		return self::forState($missing, (int)$session->get_user_id(), $required,
			(int)($_SESSION['permission'] ?? 0) >= 10);
	}

	/**
	 * Admins (permission 5 and above, live) with no usable second factor: no
	 * authenticator app, and no passkey while passkey sign-in is enabled.
	 *
	 * @return array<int, array{id:int, name:string}>
	 */
	public static function adminsWithoutSecondFactor(): array {
		$db = DbConnector::get_instance()->get_db_link();
		$passkeys_count = (bool)Globalvars::get_instance()->get_setting('passkeys_enabled', false, true);
		// The platform's own accounts are not people and cannot enrol anything.
		$sql = "SELECT usr_user_id, usr_first_name, usr_last_name, usr_email
			FROM usr_users
			WHERE usr_permission >= 5 AND usr_delete_time IS NULL
			  AND usr_user_id NOT IN (" . (int)User::USER_SYSTEM . ", " . (int)User::USER_DELETED . ")
			  AND COALESCE(usr_is_disabled, FALSE) = FALSE
			  AND usr_totp_enabled_time IS NULL";
		if ($passkeys_count) {
			$sql .= " AND NOT EXISTS (SELECT 1 FROM pkc_passkey_credentials p WHERE p.pkc_usr_user_id = usr_users.usr_user_id)";
		}
		$sql .= " ORDER BY usr_permission DESC, usr_user_id";
		$out = array();
		foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$name = trim((string)$r['usr_first_name'] . ' ' . (string)$r['usr_last_name']);
			if ($name === '') $name = (string)$r['usr_email'];
			$out[] = array('id' => (int)$r['usr_user_id'], 'name' => $name);
		}
		return $out;
	}

	/**
	 * The notice for one list of admins. Public and pure so the wording can
	 * be tested without rows.
	 *
	 * @param array<int, array{id:int, name:string}> $missing
	 * @param int  $viewer_id   the admin looking at the page
	 * @param bool $required    totp_require_admins is on
	 * @param bool $can_require the viewer may switch the requirement on
	 */
	public static function forState(array $missing, int $viewer_id, bool $required, bool $can_require): string {
		if (empty($missing)) {
			return '';
		}
		$n = count($missing);
		$viewer_missing = false;
		$names = array();
		foreach ($missing as $m) {
			if ((int)$m['id'] === $viewer_id) $viewer_missing = true;
			if (count($names) < self::NAMED) $names[] = (string)$m['name'];
		}
		$list = implode(', ', $names);
		if ($n > count($names)) $list .= ' and ' . ($n - count($names)) . ' more';

		$lead = $n === 1 ? '1 admin has no second factor.' : $n . ' admins have no second factor.';
		$body = 'A password alone is what phishing steals; an authenticator app or a passkey is what stops it. Without one: ' . $list . '.';

		$actions = '';
		if ($viewer_missing) {
			$actions .= '<a class="jy-2fa-notice__action" href="' . self::SECURITY_URL . '">Enrol yours</a>';
		}
		if ($required) {
			$body .= ' Each of them is sent to enrol one at their next page.';
		} elseif ($can_require) {
			$actions .= '<form method="POST" action="' . self::REQUIRE_URL . '" class="jy-2fa-notice__form">'
				. '<button type="submit" class="jy-2fa-notice__action jy-2fa-notice__action--quiet">Require one of every admin</button>'
				. '</form>';
		}

		return self::css()
			. '<div class="jy-2fa-notice" role="status">'
			. '<div class="jy-2fa-notice__text"><strong>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</div>'
			. $actions
			. '</div>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-2fa-notice{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem}'
			. '.jy-2fa-notice__text{flex:1 1 20rem}'
			. '.jy-2fa-notice__form{display:inline;margin:0}'
			. '.jy-2fa-notice__action{display:inline-block;padding:.35rem .9rem;border:0;border-radius:4px;background:#b45309;color:#fff;text-decoration:none;font-weight:600;cursor:pointer;font:inherit;font-weight:600}'
			. '.jy-2fa-notice__action--quiet{background:transparent;color:#78350f;border:1px solid #d97706}'
			. '</style>';
	}
}
