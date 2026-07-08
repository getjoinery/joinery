<?php
/**
 * External recovery-address verification consume step
 * (specs/mailbox_security_levels.md § Password reset, Population 3).
 *
 * The verify link mailed to a candidate recovery address (Activation::
 * email_recovery_verify_send) lands here. Possession of the code proves control
 * of the target inbox, so this stamps usr_recovery_email_verified_time and
 * promotes the candidate to the account's live recovery path. No login is
 * required — the code is the capability, exactly as an activation link is, and
 * the address was chosen from a step-up-gated session (security_logic).
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../includes/PathHelper.php');

function recovery_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = array('session' => $session, 'settings' => Globalvars::get_instance());

	$act_code = isset($input['act_code']) ? trim((string)$input['act_code']) : '';
	if ($act_code === '') {
		$page_vars['ok'] = false;
		$page_vars['message'] = 'This confirmation link is missing its code.';
		return LogicResult::render($page_vars);
	}

	// checkTempCode enforces act_deleted = FALSE (and expiry); getTempCodeInfo does
	// not. Gating on it here makes deleteTempCode effective — a link cannot be
	// replayed after use, and its full 2-day TTL no longer keeps it live.
	if (!Activation::checkTempCode($act_code, Activation::RECOVERY_VERIFY)) {
		$page_vars['ok'] = false;
		$page_vars['message'] = 'This confirmation link has expired or has already been used. Please request a new one from your security settings.';
		return LogicResult::render($page_vars);
	}

	$record = Activation::getTempCodeInfo($act_code, Activation::RECOVERY_VERIFY);
	if (!$record) {
		$page_vars['ok'] = false;
		$page_vars['message'] = 'This confirmation link has expired or is invalid. Please request a new one from your security settings.';
		return LogicResult::render($page_vars);
	}

	$user = new User((int)$record->act_usr_user_id, TRUE);
	if (!$user || !$user->key) {
		$page_vars['ok'] = false;
		$page_vars['message'] = 'The account for this confirmation link no longer exists.';
		return LogicResult::render($page_vars);
	}

	$recovery_email = strtolower(trim((string)$record->act_usr_email));

	// Reconcile against the account's CURRENT pending candidate. If the user later
	// changed the recovery address to something else, or removed it, this stale
	// link no longer matches and is refused — it can neither resurrect a removed
	// address nor revert a newer choice.
	$current = strtolower(trim((string)$user->get('usr_recovery_email')));
	if ($current === '' || $current !== $recovery_email) {
		Activation::deleteTempCode($act_code);
		$page_vars['ok'] = false;
		$page_vars['message'] = 'This confirmation link no longer matches your current recovery address. Please request a fresh one from your security settings.';
		return LogicResult::render($page_vars);
	}

	$user->set('usr_recovery_email_verified_time', gmdate('Y-m-d H:i:s'));
	$user->save();

	Activation::deleteTempCode($act_code);

	$page_vars['ok'] = true;
	$page_vars['recovery_email'] = $recovery_email;
	$page_vars['message'] = 'Your recovery address is confirmed. Password reset links will also be sent there.';
	return LogicResult::render($page_vars);
}
?>
