<?php
/**
 * App Bridge — the web side of the app web-session bridge.
 *
 * A native app POSTs /api/v1/auth/web_session with its session key and gets a
 * single-use bridge URL; its webview loads that URL here. This logic claims
 * the token (atomic, single-use), verifies the originating API key is still
 * live, starts a normal web session for the key's user, marks it app-context
 * (chrome-less rendering + lifetime coupled to the key), and redirects to the
 * requested target path. See docs/mobile_apps.md.
 *
 * On any failure the view renders an honest "expired link" page — the app's
 * recovery is simply minting a fresh token and re-bridging.
 *
 * @version 1.0.0
 */

function app_bridge_logic($params) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/app_bridge_tokens_class.php'));
	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$failure = array('bridge_failed' => true);

	// Tokens are HTTPS-only: never consume one over an insecure transport
	// (the site-wide HTTPS redirect runs later, in page rendering).
	if (!LibraryFunctions::isSecure()) {
		return LogicResult::render($failure);
	}

	$token = isset($params['token']) ? (string)$params['token'] : '';
	if ($token === '') {
		return LogicResult::render($failure);
	}

	// Atomic claim: unknown, already-used, and expired tokens all land here.
	$bridge_token = AppBridgeToken::ClaimByToken($token);
	if (!$bridge_token) {
		return LogicResult::render($failure);
	}

	// The bridged session derives from the API key, so the key must still be
	// live at bridge time (same checks ApiAuth applies per request).
	try {
		$api_key = new ApiKey($bridge_token->get('abt_apk_api_key_id'), TRUE);
	} catch (Exception $e) {
		return LogicResult::render($failure);
	}
	$now_utc = gmdate('Y-m-d H:i:s');
	if ($api_key->get('apk_delete_time')
		|| !$api_key->get('apk_is_active')
		|| ($api_key->get('apk_expires_time') && $api_key->get('apk_expires_time') < $now_utc)) {
		return LogicResult::render($failure);
	}

	try {
		$user = new User($api_key->get('apk_usr_user_id'), TRUE);
	} catch (Exception $e) {
		return LogicResult::render($failure);
	}
	if (!$user->key || $user->get('usr_delete_time')) {
		return LogicResult::render($failure);
	}

	$session = SessionControl::get_instance();
	try {
		// Regenerates the session id (fixation-safe) and sets the standard
		// logged-in session variables; throws for de-activated accounts.
		$session->store_session_variables($user);
	} catch (Exception $e) {
		return LogicResult::render($failure);
	}

	$session->mark_app_context($api_key->key, $bridge_token->get('abt_client_app'));

	return LogicResult::redirect($bridge_token->get('abt_target_path'));
}

?>
