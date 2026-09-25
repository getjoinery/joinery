<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Enroll another unlocker (a second passkey, or the optional passphrase) on an
 * existing client-custody vault. The browser unlocked, re-wrapped the same
 * secret key under the new unlocker's KEK, and posts the opaque blob. Gated on
 * a recent step-up - adding an unlocker is a credential change.
 */
function vault_client_add_wrapping_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('includes/VaultScopes.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	$wrapping = isset($input['wrapping']) && is_array($input['wrapping']) ? $input['wrapping'] : null;
	if (!$wrapping) {
		return LogicResult::error('Missing the new wrapping.');
	}

	try {
		VaultClientCustody::assertClientScope($scope);
		$vault = VaultClientCustody::loadVault($user_id, $scope);
		if (!$vault) {
			return LogicResult::error('Your vault is not set up.');
		}
		VaultClientCustody::assertNoPendingRotation($vault);

		// A `root` wrapping adds a way in through the vault the person just
		// opened and can remove nothing, so it needs no fresh step-up. Nor does
		// a root-vault passkey wrapping for a passkey that already opens the
		// account vault: it grants that passkey nothing it lacks, and is how a
		// passkey enrolled before the root existed catches up
		// (specs/one_vault_experience.md § R3). Every other unlocker does.
		//
		// A root passkey wrapping REPLACES that passkey's existing one. The
		// server cannot tell a good blob from a bad one; a replacement makes a
		// bad one heal itself: the passkey's next unlock fails to open the
		// root, the ceremony opens it another way, and posts a good one here.
		$type = (string)($wrapping['unlocker_type'] ?? '');
		$exempt = ($type === UserEncryptionWrapping::TYPE_ROOT);
		$replaces = array();
		if ($type === UserEncryptionWrapping::TYPE_PASSKEY && $scope === VaultScopes::ROOT_SCOPE) {
			$credential_internal_id = VaultClientCustody::resolveOwnedPrfPasskeyId($user_id, (string)($wrapping['credential_id'] ?? ''));
			foreach (new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => $type,
					'credential_id' => $credential_internal_id]) as $old) {
				$replaces[] = $old;
			}
			$account = UserEncryptionVault::loadForUser($user_id);
			$exempt = $account && (new MultiUserEncryptionWrapping(['vault_id' => $account->key,
				'unlocker_type' => $type, 'credential_id' => $credential_internal_id]))->count() > 0;
		}
		if (!$exempt && $session->step_up_outstanding(null, 300)) {
			return LogicResult::error('Confirm with your passkey before changing your vault unlockers.', ['requires_stepup' => true]);
		}

		$db = DbConnector::get_instance()->get_db_link();
		$db->beginTransaction();
		try {
			foreach ($replaces as $old) {
				$old->soft_delete();
			}
			VaultClientCustody::persistWrappings($user_id, $vault, [$wrapping]);
			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			throw $e;
		}
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['added' => true]);
}

// @version 1.1 - `root` wrappings, and a root passkey wrapping (replacing that passkey's) for a passkey the account vault already has, need no step-up
function vault_client_add_wrapping_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Add an unlocker wrapping (browser-produced opaque blob) to an existing client-custody vault; requires a recent step-up',
		'input' => [
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
			'wrapping' => ['type' => 'object', 'required' => true, 'label' => 'Browser-produced wrapping blob'],
		],
	];
}
?>
