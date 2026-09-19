<?php
/**
 * Logic for the start page (/server_manager/start) — step 1 of a Managed site.
 *
 * A visitor who pressed "Set up your site" without a session used to be
 * handed the plain sign-in page, with nothing saying why. This page says why:
 * step 1 of three is an account, because that is where the site's first
 * password is shown to them and where they manage the site later. It offers
 * signing in and creating an account side by side, with no preference.
 *
 * Both forms post back here, and both are handled by the platform's own
 * sign-in and sign-up handlers — login_logic and register_logic — so every
 * gate they hold (the throttles, the activation rule, the second-factor
 * divert, the bot defences) holds here too. This page only keeps the visitor
 * on its own chrome when a handler refuses, and lets every success land on
 * the configure page, which both handlers do by reading the session's return
 * slot that this page sets.
 *
 * @version 1.0 - specs/managed_hosting_phase1_purchase.md §4.1 (the account step)
 */

function start_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ManagedSiteDraft.php'));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	$next = ManagedSiteDraft::CONFIGURE_URL;

	// A member is past step 1.
	if ($session->get_user_id()) {
		return LogicResult::redirect($next);
	}

	// Every way out of this page lands on the configure page: the sign-in and
	// the sign-up handlers both read this slot.
	$session->set_return($next);

	$is_post = (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST');
	$form = $is_post ? (string)($input['form'] ?? '') : '';
	$error = '';

	if ($form === 'login') {
		require_once(PathHelper::getThemeFilePath('login_logic.php', 'logic'));
		$result = login_logic($input);
		if ($result->redirect !== null && strpos($result->redirect, '/login') === 0) {
			// The handler's own refusals are a bounce back to the sign-in page
			// with the reason in the query; here the reason is shown in place.
			$error = start_logic_login_refusal($result->redirect);
		} elseif ($result->redirect !== null) {
			return LogicResult::redirect($result->redirect);   // the configure page, or the second-factor step
		} elseif ($result->error !== null) {
			$error = $result->error;
		}
	} elseif ($form === 'register') {
		require_once(PathHelper::getThemeFilePath('register_logic.php', 'logic'));
		$result = register_logic($input);
		if ($result->redirect !== null) {
			return LogicResult::redirect($result->redirect);
		}
		if ($result->error !== null) {
			$error = $result->error;
		}
	}

	$product = ManagedSiteDraft::managed_product();
	return LogicResult::render(array(
		'session'          => $session,
		'settings'         => $settings,
		'next'             => $next,
		'form'             => $form,
		'error'            => $error,
		'values'           => $is_post ? $input : array(),
		'register_active'  => (bool)$settings->get_setting('register_active'),
		'passkeys_enabled' => (bool)$settings->get_setting('passkeys_enabled'),
		'price_sentence'   => ManagedSiteDraft::price_sentence($product),
		'steps_html'       => ManagedSiteDraft::steps_html(1),
	));
}

/** The sentence behind a sign-in bounce (/login?retry=1..., ?ip_blocked=1&ip=...). */
function start_logic_login_refusal(string $redirect): string {
	$query = array();
	parse_str((string)parse_url($redirect, PHP_URL_QUERY), $query);
	if (!empty($query['ip_blocked'])) {
		$ip = htmlspecialchars((string)($query['ip'] ?? 'unknown'));
		return 'Login from your IP address (' . $ip . ') is not permitted for this account. '
			. 'Please contact an administrator if you believe this is an error.';
	}
	return 'Your email or password was incorrect. Please try again, or create an account on the right '
		. 'if you do not have one. If you forgot your password, <a href="/password-reset-1">click here</a> '
		. 'and we will send you a new one.';
}
