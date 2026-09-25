<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_rotate_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!UserEncryptionVault::loadForUser($user->key)) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	// Rotation is authorized by a passkey that already unlocks the vault. A
	// phrase-only vault has none, so the ceremony can never succeed — say so
	// instead of minting one over credentials that open nothing.
	if (!VaultUnlock::hasPasskeyRoute((int)$user->key, UserEncryptionVault::SCOPE_USER)) {
		return LogicResult::error(
			'Rotation needs a passkey that unlocks your vault, and none does yet. Unlock with your passphrase, add a passkey from your security page, then rotate.',
			['no_passkey_route' => true]
		);
	}

	// with_root: the same touch also yields the root vault's secret, kept by
	// the browser (specs/one_vault_experience.md § R1).
	$second = !empty($input['with_root']) ? VaultScopes::prfContext(VaultScopes::ROOT_SCOPE) : null;
	try {
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, 'vault-kek',
			VaultUnlock::offerableCredentialIds((int)$user->key, UserEncryptionVault::SCOPE_USER), '', $second);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_rotate_options_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Begin vault key rotation (returns WebAuthn PRF request options); use an already-enrolled passkey. with_root also asks the same touch for the root vault\'s secret, which the browser keeps',
		'input' => [
			'with_root' => ['type' => 'bool', 'required' => false, 'label' => 'Also derive the root vault secret (kept in the browser)'],
		],
	];
}
?>
