<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	// What the one-vault ceremony needs besides this vault
	// (specs/one_vault_experience.md): the root vault's keyring view (its salt
	// keys every code and phrase derivation, its wrappings open it), each held
	// content vault's (opened through the root), and whether a passphrase is
	// allowed (R8). Opaque blobs only, as vault_client_status returns them.
	$content = array();
	foreach (VaultScopes::contentScopes() as $scope) {
		try {
			if (VaultClientCustody::loadVault((int)$user->key, $scope)) {
				$content[$scope] = VaultClientCustody::statusPayload((int)$user->key, $scope);
			}
		} catch (Exception $e) {
			// an unregistered scope has no vault
		}
	}
	$root = VaultClientCustody::statusPayload((int)$user->key, VaultScopes::ROOT_SCOPE);
	$common = array(
		'passphrase_allowed' => VaultClientCustody::passphraseAllowed((int)$user->key),
		'root_set_up'        => !empty($root['set_up']),
		'root'               => $root,
		'content_scopes'     => array_keys($content),
		'content'            => $content,
	);

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::render(['set_up' => false] + $common);
	}

	$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
	$wrappings->load();
	$wrapping_list = [];
	$unused_recovery = 0;
	$passkey_count = 0;
	$has_passphrase = false;
	foreach ($wrappings as $w) {
		$type = $w->get('uew_unlocker_type');
		if ($type === UserEncryptionWrapping::TYPE_RECOVERY && !$w->get('uew_is_used')) {
			$unused_recovery++;
		}
		if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
			$passkey_count++;
		}
		if ($type === UserEncryptionWrapping::TYPE_PASSPHRASE) {
			$has_passphrase = true;
		}
		$wrapping_list[] = [
			'id'            => (int)$w->key,
			'unlocker_type' => $type,
			'credential_id' => $w->get('uew_pkc_passkey_credential_id') ? (int)$w->get('uew_pkc_passkey_credential_id') : null,
			'label'         => $w->get('uew_label'),
			'is_used'       => (bool)$w->get('uew_is_used'),
			'created_time'  => $w->get('uew_create_time'),
		];
	}

	return LogicResult::render($common + [
		'set_up'                    => true,
		'unlocked'                  => VaultUnlock::isOpen($user->key, UserEncryptionVault::SCOPE_USER),
		// False: codes made on the server before sets existed, which the next
		// passkey unlock replaces with a browser-made set (§ R6).
		'has_code_set'              => UserEncryptionWrapping::hasCodeSet((int)$vault->key),
		'key_generation'            => (int)$vault->get('uev_key_generation'),
		'passkey_wrapping_count'    => $passkey_count,
		'unused_recovery_code_count'=> $unused_recovery,
		'has_passphrase'            => $has_passphrase,
		'regenerate_recommended'    => $unused_recovery < 3,
		'wrappings'                 => $wrapping_list,
	]);
}

// @version 1.1 - root and content keyring views, has_code_set, passphrase_allowed
function vault_status_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Report the current user\'s vault setup/unlock status and enrolled unlockers (no secret material), with the root vault and every held content vault (opaque keyring views: public key, salt, wrappings), whether the codes are a browser-made set, and whether a passphrase is allowed',
	];
}
?>
