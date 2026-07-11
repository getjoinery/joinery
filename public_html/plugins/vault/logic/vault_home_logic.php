<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Page logic for the password manager at /profile/vault. Nothing sensitive
 * happens here - every byte of crypto is client-side. This just gates on a
 * signed-in session and hands the view the flags the manager JS needs.
 */
function vault_home_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$settings = Globalvars::get_instance();
	$autolock = (int)$settings->get_setting('vault_autolock_minutes');
	$clipboard = (int)$settings->get_setting('vault_clipboard_clear_seconds');

	return LogicResult::render([
		'passkeys_enabled'        => (bool)$settings->get_setting('passkeys_enabled'),
		'autolock_minutes'        => $autolock > 0 ? $autolock : 15,
		'clipboard_clear_seconds' => $clipboard > 0 ? $clipboard : 30,
	]);
}
?>
