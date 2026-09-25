<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_passkey_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	try {
		VaultCeremonies::assertNoSecondPrfOutput($credential);
		// A first unified unlock moves the account onto a browser-made code set
		// (specs/one_vault_experience.md § R6): wrapped in this same request,
		// under the key this passkey opens, and only when the account has none.
		$adopt = isset($input['adopt_code_set']) ? VaultCeremonies::codeSet($input['adopt_code_set']) : null;
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	$wrappings = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => $passkey->key,
	]);
	$wrappings->load();
	if ($wrappings->count() === 0) {
		return LogicResult::error('This passkey does not unlock your vault.');
	}
	// Normally one live wrapping per credential. After a partial rotation
	// (re-seal failure) two generations are live — prefer the CURRENT
	// generation deterministically (new arrivals seal to it), falling back to
	// the lowest; either way the state converges when the rotation is re-run.
	$current_generation = (int)$vault->get('uev_key_generation');
	$wrapping = null;
	foreach ($wrappings as $w) {
		$generation = (int)$w->get('uew_key_generation');
		if ($generation === $current_generation) {
			$wrapping = $w;
			break;
		}
		if ($wrapping === null || $generation < (int)$wrapping->get('uew_key_generation')) {
			$wrapping = $w;
		}
	}

	// The unwrap happens inside the key holder: what comes back is a window,
	// never the secret (docs/sealed_vault.md § The unlock window).
	$adopted = false;
	try {
		// Not during an unfinished rotation: new codes could only wrap the key the
		// drain is about to retire (the same rule regenerating codes keeps).
		if ($adopt !== null && !UserEncryptionWrapping::hasCodeSet((int)$vault->key)
				&& count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) === 1) {
			// The root vault comes into being in the same transaction, with the
			// same codes: the new codes are salted with its salt, and must open
			// both halves from the first moment (§ R6).
			$root_input = is_array($input['root'] ?? null) ? $input['root'] : array();
			$adopted = UserEncryptionWrapping::adoptCodeSet($vault, $adopt, function (array $wrap_under) use ($user, $wrapping, $prf_output) {
				return VaultUnlock::open($user->key, $wrapping->unlocker($prf_output), $wrap_under,
					UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_PASSKEY);
			}, function () use ($user, $adopt, $root_input) {
				if (VaultClientCustody::loadVault((int)$user->key, VaultScopes::ROOT_SCOPE)) {
					VaultClientCustody::replaceRootRecovery((int)$user->key, $adopt,
						array_values((array)($root_input['wrappings'] ?? array())));
				} else {
					VaultClientCustody::createVault((int)$user->key, VaultScopes::ROOT_SCOPE, $root_input, $adopt);
				}
			});
		} else {
			VaultUnlock::open($user->key, $wrapping->unlocker($prf_output), [], UserEncryptionVault::SCOPE_USER,
				null, VaultAudit::VIA_PASSKEY);
		}
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Exception $e) {
		return LogicResult::error('Could not unlock your vault with this passkey.');
	}

	// A passphrase is only for an account whose passkeys cannot hold a key
	// (specs/one_vault_experience.md § R8). This passkey just did: the phrase
	// goes from both vaults in one transaction, the root's first (its half
	// opens the end-to-end content). A failure removes neither and is logged;
	// the next unlock tries again.
	$passphrase_removed = false;
	if (!VaultClientCustody::passphraseAllowed((int)$user->key)) {
		$phrases = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
		$root_vault = UserEncryptionVault::loadForUser((int)$user->key, VaultScopes::ROOT_SCOPE);
		$root_phrases = $root_vault ? (new MultiUserEncryptionWrapping(['vault_id' => $root_vault->key,
			'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]))->count() : 0;
		if ($phrases->count() > 0 || $root_phrases > 0) {
			$db = DbConnector::get_instance()->get_db_link();
			$db->beginTransaction();
			try {
				VaultClientCustody::replaceRootPassphrase((int)$user->key, array());
				foreach ($phrases as $phrase) {
					$phrase->soft_delete();
				}
				$db->commit();
				$passphrase_removed = true;
			} catch (Throwable $e) {
				if ($db->inTransaction()) $db->rollBack();
				error_log('vault_unlock_passkey: could not remove the passphrase of user ' . (int)$user->key . ': ' . $e->getMessage());
			}
		}
	}

	return LogicResult::render([
		'unlocked'           => true,
		'code_set_adopted'   => $adopted,
		'passphrase_removed' => $passphrase_removed,
	]);
}

// @version 1.1 - one-vault: no second PRF output, code set adoption with the root vault, R8 phrase removal
function vault_unlock_passkey_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete unlocking the vault with a passkey',
		'input' => [
			'credential' => ['type' => 'object', 'required' => true, 'label' => 'WebAuthn credential response (the first PRF output only)'],
			'adopt_code_set' => ['type' => 'object', 'required' => false, 'label' => 'A browser-made code set {id, entries:[{index, kek}]} to replace codes made before sets existed'],
			'root' => ['type' => 'object', 'required' => false, 'label' => 'With adopt_code_set: the root vault the browser made ({public_key, salt, kdf_params, wrappings}), created with the new codes'],
		],
	];
}
?>
