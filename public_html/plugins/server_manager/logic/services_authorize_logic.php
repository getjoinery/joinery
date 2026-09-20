<?php
/**
 * Logic for the authorise page (/services/authorize): link a self-hosted
 * site to the signed-in getjoinery account.
 *
 * (specs/services_phase2_platform.md §4). The site's wizard step sent its
 * owner here with three things: the site's hostname, where to send the key,
 * and a single-use state the site minted. Signed out, the visitor goes
 * through the platform's own sign-in — which offers sign-up beside it — and
 * comes back here; signed in, the page names the site and the account and
 * takes one click. The approval is a POST (a link is a GET, and a browser
 * performs a GET whenever it is told to); it mints the key, and the redirect
 * carries the pair and the state back to the site, once.
 *
 * The return address must be on the site's own host over https, so a
 * crafted link cannot send a key elsewhere; the site checks the state and
 * its own origin again on landing.
 *
 * @version 1.0
 */

function services_authorize_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');

	$host = JoineryServices::cleanHost((string)($input['site'] ?? ''));
	$state = trim((string)($input['state'] ?? ''));
	$return = $host === '' ? '' : ServicesConnect::cleanReturn((string)($input['return'] ?? ''), $host);

	$problem = '';
	if ($host === '') {
		$problem = 'This link does not name a site. Start again from your site\'s setup page.';
	} elseif ($return === '') {
		$problem = 'This link\'s return address is not on the site it names, or is not https. Start again from your site\'s setup page.';
	} elseif ($state === '' || strlen($state) > 200 || !preg_match('/^[A-Za-z0-9._~-]+$/', $state)) {
		$problem = 'This link has expired or is incomplete. Start again from your site\'s setup page.';
	}

	$self_url = '/services/authorize?' . http_build_query(array('site' => $host, 'return' => (string)($input['return'] ?? ''), 'state' => $state));

	$user_id = (int)$session->get_user_id();
	if (!$user_id && $problem === '') {
		// The platform's own sign-in, which offers sign-up beside it; both
		// handlers read the return slot and land back here.
		$session->set_return($self_url);
		return LogicResult::redirect('/login?return=' . urlencode($self_url));
	}

	$user = $user_id ? new User($user_id, TRUE) : null;
	$account = $user && $user->key ? (string)$user->get('usr_email') : '';

	if ($is_post && $problem === '') {
		$formwriter = new FormWriterV2HTML5('services_authorize');
		if (!$formwriter->validateCSRF($input)) {
			$problem = 'That request token has expired. Approve the link again.';
		} elseif ((string)($input['decision'] ?? '') === 'decline') {
			return LogicResult::redirect($return . (strpos($return, '?') === false ? '?' : '&')
				. http_build_query(array('state' => $state, 'error' => 'declined')));
		} else {
			try {
				$minted = ServicesConnect::mintKey($user_id, $host);
			} catch (\Throwable $e) {
				error_log('services_authorize: mint for ' . $host . ' failed: ' . $e->getMessage());
				$problem = 'The key could not be minted: ' . $e->getMessage();
				$minted = null;
			}
			if ($minted !== null) {
				// The one hop the secret makes: one URL, once, over TLS.
				return LogicResult::redirect(ServicesConnect::returnUrl($return, $minted['public_key'], $minted['secret_key'], $state));
			}
		}
	}

	return LogicResult::render(array(
		'session'  => $session,
		'host'     => $host,
		'return'   => (string)($input['return'] ?? ''),
		'state'    => $state,
		'account'  => $account,
		'problem'  => $problem,
		'self_url' => $self_url,
		'already'  => $host !== '' && $user_id ? count(ServiceTenant::forHost($user_id, $host)) > 0 : false,
	));
}
