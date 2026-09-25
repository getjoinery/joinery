<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Replace the vault's recovery codes with a set the browser made and is
 * showing (specs/one_vault_experience.md § R6). The codes never reach this
 * server: `code_set` carries the account half of each code's KEK, and
 * `root_wrappings` the root vault's twin wrappings of the same codes, so one
 * set keeps opening both. Both sides change in one transaction, under a fresh
 * unlocker presented in this same request (specs/unseal_daemon.md B1); the
 * old codes stay live until the new set is stored, so one of them can be the
 * unlocker that replaces them all.
 *
 * @version 2.0
 */
function vault_regenerate_codes_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Set up your vault first.');
	}

	if ($session->step_up_outstanding($user)) {
		return LogicResult::error('Please re-confirm with an existing passkey before regenerating your recovery codes.');
	}

	// A wrapping must be tagged with a single truthful generation, and in a
	// partially-rotated vault the in-window secret's generation is ambiguous.
	if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
		return LogicResult::error('Your vault has an unfinished key rotation. Run the rotation again to complete it, then regenerate your codes.');
	}

	try {
		$code_set = VaultCeremonies::codeSet($input['code_set'] ?? null);
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}

	// The root vault's twin of the set, required: the codes are salted with
	// the root's salt, and codes that opened one half would strand the other.
	// An account from before the root existed gets it at its next passkey
	// unlock, which also replaces its codes.
	$root_wrappings = isset($input['root_wrappings']) && is_array($input['root_wrappings']) ? array_values($input['root_wrappings']) : array();
	if (VaultClientCustody::loadVault((int)$user->key, VaultScopes::ROOT_SCOPE) === null) {
		return LogicResult::error('Unlock your vault with your passkey first: that finishes setting it up, with new codes.');
	}
	if (!$root_wrappings) {
		return LogicResult::error('Unlock your vault on this page first, so your new codes open all of it.', ['root_required' => true]);
	}

	$ceremonies = new VaultCeremonies();
	try {
		UserEncryptionWrapping::adoptCodeSet($vault, $code_set,
			function (array $wrap_under) use ($ceremonies, $user, $vault, $input) {
				return $ceremonies->openWithUnlocker($user, $vault, $input['unlocker'] ?? null, $wrap_under);
			},
			function () use ($user, $code_set, $root_wrappings) {
				VaultClientCustody::replaceRootRecovery((int)$user->key, $code_set, $root_wrappings);
			});
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage(), ['unlocker_required' => true]);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		error_log('Recovery code regeneration: could not replace the codes for vault ' . (int)$vault->key . ': ' . $e->getMessage());
		return LogicResult::error('Could not regenerate your recovery codes - nothing was changed and your existing codes still work. Try again.');
	}

	return LogicResult::render(['replaced' => true, 'recovery_code_count' => count($code_set['entries'])]);
}

function vault_regenerate_codes_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Replace every recovery code with a browser-made set (account halves of each code KEK, plus the root vault\'s wrappings of the same codes); requires a recent step-up and a fresh unlocker (unlocker: {credential} from vault_unlock_options, {passphrase_kek} or {code_kek}) in the same request',
		'input' => [
			'code_set' => ['type' => 'object', 'required' => true, 'label' => 'Browser-made code set {id, entries:[{index, kek}]} (account halves only)'],
			'root_wrappings' => ['type' => 'array', 'required' => true, 'items' => ['type' => 'object'], 'label' => 'The root vault\'s recovery wrappings of the same codes (code_set, code_index, wrapped_secret_key, salt)'],
			'unlocker' => ['type' => 'object', 'required' => false, 'label' => 'Fresh unlocker: {credential} from vault_unlock_options, {passphrase_kek} or {code_kek}'],
		],
	];
}
?>
