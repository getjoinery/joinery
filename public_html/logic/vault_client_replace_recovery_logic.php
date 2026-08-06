<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Regenerate recovery keys for a client-custody vault: retire every existing
 * recovery wrapping and store a fresh browser-produced set. The browser
 * unlocked, generated new keys, and wrapped the same secret key under each -
 * the server stores the opaque blobs. Gated on a recent step-up. Done in one
 * transaction so a failure never strands the vault between old and new sets.
 */
function vault_client_replace_recovery_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	$wrappings = isset($input['wrappings']) && is_array($input['wrappings']) ? $input['wrappings'] : [];
	$fresh_recovery = 0;
	foreach ($wrappings as $w) {
		if (($w['unlocker_type'] ?? '') === UserEncryptionWrapping::TYPE_RECOVERY) $fresh_recovery++;
	}
	if ($fresh_recovery < 1) {
		return LogicResult::error('No new recovery keys were provided.');
	}

	try {
		VaultClientCustody::assertClientScope($scope);
		$vault = VaultClientCustody::loadVault($user_id, $scope);
		if (!$vault) {
			return LogicResult::error('Your vault is not set up.');
		}

		if ($session->step_up_outstanding(null, 300)) {
			return LogicResult::error('Confirm with your passkey before regenerating recovery keys.', ['requires_stepup' => true]);
		}

		$db = DbConnector::get_instance()->get_db_link();
		try {
			$db->beginTransaction();

			$existing = new MultiUserEncryptionWrapping([
				'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY,
			]);
			$existing->load();
			foreach ($existing as $old) {
				$old->soft_delete();
			}

			// Only recovery wrappings are accepted here - a passkey/passphrase
			// slipping into this call would bypass the add-wrapping path.
			$recovery_only = array_filter($wrappings, function ($w) {
				return ($w['unlocker_type'] ?? '') === UserEncryptionWrapping::TYPE_RECOVERY;
			});
			VaultClientCustody::persistWrappings($user_id, $vault, array_values($recovery_only));

			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			throw $e;
		}
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		error_log('Client vault recovery regen failed for user ' . $user_id . ': ' . $e->getMessage());
		return LogicResult::error('Could not regenerate your recovery keys - nothing was changed. Try again.');
	}

	return LogicResult::render(['replaced' => true, 'recovery_code_count' => $fresh_recovery]);
}

function vault_client_replace_recovery_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Replace all recovery-key wrappings on a client-custody vault with a fresh browser-produced set; requires a recent step-up',
	];
}
?>
