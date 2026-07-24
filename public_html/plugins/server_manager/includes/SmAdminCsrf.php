<?php
/**
 * SmAdminCsrf — a per-session CSRF token for Server Manager admin mutations.
 *
 * State-changing admin actions are POST single-button forms carrying this
 * token; the handler validates it before acting. A GET link can be triggered
 * cross-site (an <img>/<a> to ?action=delete), so mutations must be POST + a
 * token an attacker's page cannot read.
 *
 * The token is session-scoped and long-lived (not one-time), which is the
 * standard CSRF model: the secret is bound to the session, not the form.
 *
 * @version 1.0
 */

class SmAdminCsrf {
	const FIELD = '_sm_csrf';

	/** The session token, minted on first use. */
	public static function token() {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		if (empty($_SESSION['sm_admin_csrf'])) {
			$_SESSION['sm_admin_csrf'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['sm_admin_csrf'];
	}

	/** A hidden input carrying the token, for embedding in a POST form. */
	public static function field() {
		return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token()) . '">';
	}

	/** True if the submitted token matches the session token (constant-time). */
	public static function valid() {
		$submitted = $_POST[self::FIELD] ?? '';
		return is_string($submitted) && $submitted !== ''
			&& !empty($_SESSION['sm_admin_csrf'])
			&& hash_equals($_SESSION['sm_admin_csrf'], $submitted);
	}
}
