<?php
/**
 * OperatorCloudConsumer - Receives a Linode sign-in that adds a cloud account
 * of ours: the Connect with Linode method on the Hosting tab of Provisioning
 * Setup (the cloud_accounts_list spec, D2).
 *
 * The Hosting tab calls OAuth2Client::beginConsent(..., 'operator_cloud',
 * ['user_id' => N, 'account_id' => id or 0], ...). The shared /oauth_callback
 * exchanges the code and dispatches here. A new entry is made, or an expired
 * one reconnected, with the grant sealed on it and its facts read from the
 * provider. The account is this management node's, never the signed-in
 * person's: a customer connects their own account from their profile, and
 * that is CustomerCloudConsumer.
 *
 * A Linode grant lasts two hours and cannot be refreshed: the entry says so,
 * and is offered nowhere once it expires.
 *
 * Discovered by interface from this plugin's includes/oauth_consumers/.
 *
 * @version 1.1 - names its provider (a sign-in is Linode's) when it asks for a driver
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));

class OperatorCloudConsumer implements OAuth2Consumer {

	const RETURN_URL = '/admin/server_manager/provisioning_setup?tab=hosting';

	public static function getPurpose(): string {
		return 'operator_cloud';
	}

	public function onTokenGranted(OAuth2Token $token, array $payload): string {
		$user_id = (int)($payload['user_id'] ?? 0);
		$user = $user_id > 0 ? new User($user_id, TRUE) : null;
		// Only a superadmin adds an account to this node; the flow began on a
		// superadmin page, and the grant is not stored for anyone else.
		if (!$user || !$user->key || (int)$user->get('usr_permission') < 10) {
			return self::RETURN_URL . '&connect_error=permission';
		}

		$account_id = (int)($payload['account_id'] ?? 0);
		if ($account_id > 0) {
			$account = new OperatorCloudAccount($account_id, TRUE);
			if (!$account->key || $account->removed()) {
				return self::RETURN_URL . '&connect_error=gone';
			}
			// A reconnect replaces the grant of the same account, never another:
			// every server that names this entry would be pointed elsewhere.
			$was = trim((string)$account->get('oca_account_name'));
			$now = self::account_name($token->getAccessToken());
			if ($was !== '' && $now !== '' && $was !== $now) {
				return self::RETURN_URL . '&connect_error=other_account';
			}
		} else {
			$account = new OperatorCloudAccount(NULL);
			$account->set('oca_provider', 'linode');
			$account->set('oca_method', OperatorCloudAccount::METHOD_CONNECT);
			$account->set('oca_added_by_usr_user_id', $user_id);
		}
		$account->store_credential($token->getAccessToken(), $token->getExpiresAt());
		$account->set('oca_status', OperatorCloudAccount::STATUS_ACTIVE);
		$account->save();
		$account->load();
		$account->check();
		return self::RETURN_URL . '&connected=' . (int)$account->key;
	}

	/** The provider's own name for the account a grant is for, or '' when it cannot be read. */
	private static function account_name(string $access_token): string {
		try {
			return OperatorCloudAccount::driver_for($access_token, 'linode')->accountName();
		} catch (Exception $e) {
			return '';
		}
	}
}
