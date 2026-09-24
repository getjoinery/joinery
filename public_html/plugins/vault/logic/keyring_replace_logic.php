<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Replace the store DEK's sealed copy during a rotation of the password
 * vault's key. keyring_save stays create-only, because the sealed blob is the
 * only copy of the store key; this is the one overwrite, and it is accepted
 * only while the caller's passwords vault has a rotation pending — the browser
 * opened the store key with the old vault key and sealed it to the new one
 * (plugins/vault/assets/js/vault-reseal.js).
 *
 * @version 1.0
 */
function keyring_replace_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/vault/data/vault_keyring_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$vault = VaultClientCustody::loadVault($user_id, 'passwords');
	if (!$vault || $vault->get('uev_pending_key_generation') === null) {
		return LogicResult::error('The password vault store key can be replaced only while its vault key is being rotated.');
	}

	$wrapped_dek = isset($input['wrapped_dek']) ? trim((string)$input['wrapped_dek']) : '';
	if ($wrapped_dek === '') {
		return LogicResult::error('Missing the sealed store key.');
	}

	$keyring = VaultKeyring::loadForUser($user_id);
	if (!$keyring) {
		return LogicResult::error('Your vault store key is not set up.');
	}
	$keyring->set('vlk_wrapped_dek', $wrapped_dek);
	$keyring->save();

	return LogicResult::render(['replaced' => true]);
}

function keyring_replace_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'mutates' => true,
		'description' => 'Replace the password store DEK sealed copy with one sealed to the vault\'s new key, only while a rotation of the passwords vault key is pending',
		'input' => [
			'wrapped_dek' => ['type' => 'text', 'required' => true, 'label' => 'Store key sealed to the new vault public key'],
		],
	];
}
?>
