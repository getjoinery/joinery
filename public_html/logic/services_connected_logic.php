<?php
/**
 * services_connected_logic — where the operator sends this site's key back
 * (/services_connected; specs/services_phase2_platform.md §4).
 *
 * The return is a top-level GET on this site's own origin (SameSite=Lax
 * means the session cookie — and so the state — is only there on one). The
 * state the Connect button minted is consumed; a redirect that lands twice,
 * or with a spent state, is refused and the owner told to connect again. On
 * success the key pair is sealed into the services settings, the account is
 * read from the operator, and the owner lands back on the wizard step they
 * left. The secret exists on this site only in that one URL and its sealed
 * setting.
 *
 * Permission 10: only the site's owner links it. A signed-out landing (the
 * session expired on the way) is sent to sign in and back here — the state
 * is still in the session it belongs to, or is not, and validate() decides.
 *
 * @version 1.0
 */
function services_connected_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		$session->set_return('/services_connected?' . http_build_query(array_intersect_key($input,
			array_flip(array('public_key', 'secret_key', 'state', 'error')))));
		return LogicResult::redirect('/login');
	}
	if ((int)$session->get_permission() < 10) {
		return LogicResult::render(array('error' => 'Only the site\'s owner can connect it to a getjoinery account.'));
	}
	if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
		return LogicResult::render(array('error' => 'The connection return is a link, not a form.'));
	}
	// Our own origin: the Host header names this site, and where the browser
	// says so, the fetch is a navigation.
	$host = strtolower(trim((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))));
	if ($host !== '' && $host !== ServicesClient::host()) {
		return LogicResult::render(array('error' => 'This connection return was not addressed to this site.'));
	}
	$dest = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
	if ($dest !== '' && $dest !== 'document') {
		return LogicResult::render(array('error' => 'The connection return has to be opened as a page.'));
	}

	$result = ServicesClient::finishConnect($input);
	$_SESSION['setup_services_connect_result'] = $result;
	return LogicResult::redirect('/setup?step=' . urlencode((string)$result['step']));
}
