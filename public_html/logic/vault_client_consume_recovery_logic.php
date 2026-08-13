<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Mark a recovery-key wrapping as used. Recovery keys are one-time: the browser
 * unlocked with it (unwrapping the secret locally), then calls this so the same
 * key can't be used twice. The server only flips the used flag - it never sees
 * the key or what it unwrapped. Returns whether the user is now low on codes.
 */
function vault_client_consume_recovery_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	$wrapping_id = isset($input['wrapping_id']) ? (int)$input['wrapping_id'] : 0;
	if (!$wrapping_id) {
		return LogicResult::error('Missing the recovery key to consume.');
	}

	try {
		VaultClientCustody::assertClientScope($scope);
		$vault = VaultClientCustody::loadVault($user_id, $scope);
		if (!$vault) {
			return LogicResult::error('Your vault is not set up.');
		}

		$wrapping = new UserEncryptionWrapping($wrapping_id, TRUE);
		if (!$wrapping->key
				|| (int)$wrapping->get('uew_uev_user_encryption_vault_id') !== (int)$vault->key
				|| $wrapping->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_RECOVERY) {
			return LogicResult::error('That recovery key does not belong to your vault.');
		}

		if (!$wrapping->get('uew_is_used')) {
			$wrapping->set('uew_is_used', true);
			$wrapping->set('uew_used_time', gmdate('Y-m-d H:i:s'));
			$wrapping->save();

			// Notify the account. The server cannot verify code knowledge (that
			// would break zero-knowledge), so this action is callable by any
			// signed-in session - visibility is the defense: a real recovery
			// unlock and a malicious burn of the codes both surface here.
			// Best-effort: a failed alert never blocks the unlock.
			try {
				require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
				require_once(PathHelper::getIncludePath('data/users_class.php'));
				$user = new User($user_id, TRUE);
				$to = (string)$user->get('usr_email');
				if ($to !== '') {
					$settings = Globalvars::get_instance();
					$site = (string)$settings->get_setting('site_name');
					EmailSender::quickSend(
						$to,
						trim($site . ' security alert'),
						"A recovery key for your encrypted vault (" . $scope . ") was just used on your account. "
						. "Each recovery key works only once. If this was you, consider regenerating your recovery keys "
						. "from the vault page. If this was NOT you, change your password immediately from a device you "
						. "trust and regenerate your recovery keys."
					);
				}
			} catch (\Throwable $e) {
				error_log('vault_client_consume_recovery: alert email failed for user ' . $user_id . ': ' . $e->getMessage());
			}
		}

		$remaining = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
		]);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['consumed' => true, 'regenerate_recommended' => $remaining->count_all() < 3]);
}

function vault_client_consume_recovery_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Mark a one-time recovery-key wrapping as used after a client-custody recovery unlock',
	];
}
?>
