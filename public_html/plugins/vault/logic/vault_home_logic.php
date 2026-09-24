<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Page logic for the password manager at /profile/vault. Nothing sensitive
 * happens here - every byte of crypto is client-side. This just gates on a
 * signed-in session and hands the view the flags the manager JS needs. How long
 * the vault stays unlocked is core's (vault_client_autolock_minutes, on the
 * page's joinery-vault meta), not this plugin's.
 *
 * @version 1.2 - the idle lock setting and the passkey rule are core's; not read here
 */
function vault_home_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	$settings = Globalvars::get_instance();
	$clipboard = (int)$settings->get_setting('vault_clipboard_clear_seconds');

	return LogicResult::render([
		'clipboard_clear_seconds' => $clipboard > 0 ? $clipboard : 30,
	]);
}
?>
