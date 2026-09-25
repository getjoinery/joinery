<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * vault_client_resume — the server's half of reopening a browser-held vault
 * after a reload of the same tab (specs/client_custody_mail.md § R4a;
 * includes/VaultClientResume.php).
 *
 *   op=put   {scope, tab, share}  keep this tab's half for the scope
 *   op=get   {scope, tab}         answer it, with the vault's public keys;
 *                                 `share` is null when there is none
 *   op=drop  {scope, tab}         forget it (the vault was locked in the browser)
 *
 * `tab` is a random id the tab makes, so two tabs of one session keep their
 * own halves.
 *
 * The share is half of a key; alone it opens nothing, and it lives only in
 * this PHP session. Nothing here logs it.
 *
 * @version 1.0
 */
function vault_client_resume_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();
	// Writes $_SESSION: auth.session_write keeps the session open through this call.

	$op = (string)($input['op'] ?? '');
	$scope = (string)($input['scope'] ?? '');
	$tab = (string)($input['tab'] ?? '');
	try {
		if ($op === 'put') {
			VaultClientResume::put($user_id, $scope, $tab, (string)($input['share'] ?? ''));
			return LogicResult::render(array('kept' => true));
		}
		if ($op === 'get') {
			$got = VaultClientResume::get($user_id, $scope, $tab);
			return LogicResult::render($got ?? array('share' => null));
		}
		if ($op === 'drop') {
			VaultClientResume::drop($scope, $tab !== '' ? $tab : null);
			return LogicResult::render(array('dropped' => true));
		}
		return LogicResult::error('Unknown resume operation.');
	} catch (VaultClientResumeException $e) {
		return LogicResult::error($e->getMessage());
	}
}

function vault_client_resume_logic_descriptor() {
	return [
		'requires_session' => true,
		'mutates' => true,
		'auth' => array('requires_browser_session' => true, 'session_write' => true),
		'description' => 'Keep, return or drop this session\'s half of the key that lets a browser-held (client-custody) '
			. 'vault reopen after a reload of the same tab. The half alone opens nothing and lives only in the session.',
		'input' => [
			'op'    => ['type' => 'string', 'required' => true, 'label' => 'put, get or drop'],
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
			'tab'   => ['type' => 'string', 'required' => false, 'label' => 'The tab\'s random id (hex)'],
			'share' => ['type' => 'string', 'required' => false, 'label' => 'The session half (put only; 32 bytes, base64)'],
		],
	];
}
?>
