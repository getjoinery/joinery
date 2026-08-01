<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Recovery Readiness — the must-save list: every secret that has to exist
 * outside this platform to prevent permanent data loss, each with a verify
 * tool and the password-manager label to file it under.
 *
 * Verify actions are step-up gated (when the account has a second factor) and
 * rate-limited: a verify tool must never be a better guessing oracle than the
 * real recovery flow it mirrors.
 *
 * @version 1.0.0
 */
function admin_recovery_readiness_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/RecoveryReadiness.php'));

	$settings = Globalvars::get_instance();
	$session = SessionControl::get_instance();

	$session->check_permission(10);

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session'] = $session;

	$page_regex = '/\/admin\/admin_recovery_readiness/';

	if (LibraryFunctions::isFormSubmission() && isset($input['action'])) {
		$stepup = $session->require_recent_second_factor('/admin/admin_recovery_readiness');
		if ($stepup) {
			return $stepup;
		}
		if (!RequestLogger::check_rate_limit('recovery_readiness_verify', 10, 900, false)) {
			return LogicResult::error('Too many verification attempts. Wait a few minutes and try again.', $page_vars);
		}

		switch ($input['action']) {
			case 'verify_item':
				$outcome = RecoveryReadiness::verifyItem(
					(string)($input['item_key'] ?? ''), $input, $session);
				break;

			case 'record_client_dry_run':
				$outcome = RecoveryReadiness::recordClientDryRun(
					(int)$session->get_user_id(),
					(string)($input['scope'] ?? ''),
					($input['passed'] ?? '') === '1',
					$session);
				break;

			default:
				$outcome = array('ok' => false, 'message' => 'Unknown action.');
		}

		RequestLogger::log('recovery_readiness_verify', (string)$input['action'], $outcome['ok'],
			array('user_id' => $session->get_user_id(), 'item' => (string)($input['item_key'] ?? ($input['scope'] ?? ''))));

		if ($outcome['ok']) {
			$session->save_message(new DisplayMessage(
				$outcome['message'] !== '' ? $outcome['message'] : 'Verified.',
				'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			return LogicResult::redirect('/admin/admin_recovery_readiness');
		}
		// Failed verifies re-render with the reason; the failure is already
		// ledgered by verifyItem, so refreshing cannot hide it.
		$page_vars['items'] = RecoveryReadiness::items($session);
		$page_vars['stale_days'] = RecoveryReadiness::STALE_DAYS;
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$account = new User($session->get_user_id(), TRUE);
		$page_vars['account_email'] = (string)$account->get('usr_email');
		$passkeys = new MultiPasskey(array('user_id' => $session->get_user_id()));
		$page_vars['stepup'] = array(
			'needed'  => $session->user_has_second_factor($account) && !$session->has_recent_second_factor(),
			'passkey' => $passkeys->count_all() > 0,
		);
		return LogicResult::error($outcome['message'], $page_vars);
	}

	$page_vars['items'] = RecoveryReadiness::items($session);
	$page_vars['stale_days'] = RecoveryReadiness::STALE_DAYS;

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
	$account = new User($session->get_user_id(), TRUE);
	$page_vars['account_email'] = (string)$account->get('usr_email');

	// Inline step-up: when a confirmation will be demanded, the page runs the
	// passkey ceremony in place and then submits — one click, no redirect
	// round-trip that loses the POST. Same server gate either way.
	$passkeys = new MultiPasskey(array('user_id' => $session->get_user_id()));
	$page_vars['stepup'] = array(
		'needed'  => $session->user_has_second_factor($account) && !$session->has_recent_second_factor(),
		'passkey' => $passkeys->count_all() > 0,
	);

	// Visibility only: other users one lost passphrase away from permanent
	// data loss. Metadata a superadmin can already see — never code material.
	$page_vars['vault_aggregate'] = RecoveryReadiness::vaultAggregate($session->get_user_id());

	return LogicResult::render($page_vars);
}
?>
