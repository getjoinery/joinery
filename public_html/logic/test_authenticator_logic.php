<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Logic for /profile/test-authenticator.
 *
 * Answers one question: does the app on your phone still produce codes this
 * account accepts? An account can carry an authenticator nobody remembers
 * enrolling, and there is no safe way to find out at the sign-in prompt — that
 * is where being wrong locks you out.
 *
 * A match consumes the code's time step exactly as a real verification does
 * (User::verify_totp advances usr_totp_last_used_step). That is deliberate: a
 * code that has proved itself here must not still be replayable at sign-in.
 *
 * Backup codes are not accepted. They are single-use, and spending one to learn
 * that it works destroys the thing it proved.
 *
 * @version 1.0
 */
function test_authenticator_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$user = new User($session->get_user_id(), TRUE);

	// Nothing to test without an enrolled app, and the setup path lives on the
	// Security page rather than being duplicated here.
	if (!$user->has_totp_enabled()) {
		return LogicResult::redirect('/profile/security');
	}

	$page_vars = array(
		'session'           => $session,
		'settings'          => Globalvars::get_instance(),
		'user'              => $user,
		'totp_enabled_time' => $user->get('usr_totp_enabled_time'),
		// null until a code is submitted, then 'match' or 'no_match'.
		'result'            => null,
	);

	if (($input['action'] ?? '') === 'test_totp') {
		// Its own rate-limit bucket. Sharing the sign-in bucket would let a
		// person testing their app lock themselves out of the thing they were
		// checking they could still do.
		if (!RequestLogger::check_rate_limit('totp_test', 10, 300, false)) {
			return LogicResult::error('Too many attempts. Wait five minutes and try again.');
		}

		$code = preg_replace('/[\s-]+/', '', (string)($input['totp_code'] ?? ''));
		$matched = $user->verify_totp($code);
		$page_vars['result'] = $matched ? 'match' : 'no_match';

		RequestLogger::log('totp_test', 'test_attempt', $matched, array(
			'user_id' => (int)$user->key,
		));
	}

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	$session->clear_clearable_messages();
	return LogicResult::render($page_vars);
}
