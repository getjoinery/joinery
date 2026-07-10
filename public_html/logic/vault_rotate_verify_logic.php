<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Vault key rotation shell. WHO may rotate lives here (gates, acknowledgment,
 * WebAuthn verification, ownership); WHAT rotation does lives in
 * VaultCeremonies::rotate() — including completion mode, which finishes an
 * interrupted rotation instead of minting another generation. A wrapping can
 * only be re-created from a KEK the ceremony can re-derive right now: the
 * authorizing passkey's PRF output, a resupplied passphrase, and fresh
 * recovery codes. Anything else is invalidated rather than left dangling;
 * the response lists what needs re-adding.
 */
function vault_rotate_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (empty($input['acknowledged'])) {
		return LogicResult::error(
			'You must acknowledge that any passkey not used in this rotation, and your passphrase '
			. 'unless you re-enter it, will need to be re-added afterward - only unlockers presented '
			. 'here carry forward. Your recovery codes are always replaced.'
		);
	}

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	try {
		$ceremonies = new VaultCeremonies();
		$result = $ceremonies->rotate($user, $vault, (int)$passkey->key, $passkey->get('pkc_label'), $prf_output, $passphrase);
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render([
		'rotated'                => true,
		'completed_pending'      => $result['completed_pending'],
		'key_generation'         => $result['key_generation'],
		'recovery_codes'         => $result['recovery_codes'],
		'regenerate_recommended' => $result['regenerate_recommended'],
		'passphrase_reenrolled'  => $result['passphrase_reenrolled'],
		'dropped_passkeys'       => $result['dropped_passkeys'],
		'key_file'               => $result['key_file'],
	]);
}

function vault_rotate_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete vault key rotation: fresh keypair, every consumer re-seals its content, recovery codes replaced, other unlockers must be re-added',
	];
}
?>
