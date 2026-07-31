<?php
/**
 * CustomerCloudConsumer - Receives an OAuth2 grant for a customer's cloud
 * provider account.
 *
 * The Connect page calls OAuth2Client::beginConsent(..., 'customer_cloud',
 * ['user_id' => N, 'provider' => 'linode'], ...). The shared /oauth_callback
 * exchanges the code and dispatches here. This consumer stores the token set
 * (encrypted) on the user's CustomerCloudAccount and flips any of their
 * pending_connect provisions to ready — the provisioning task takes
 * it from there.
 *
 * Discovered by interface from this plugin's includes/oauth_consumers/;
 * no registration call is needed.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));

class CustomerCloudConsumer implements OAuth2Consumer {

	const CONNECT_URL = '/profile/server_manager/connect_cloud';

	public static function getPurpose(): string {
		return 'customer_cloud';
	}

	/**
	 * Upsert the user's account link for the provider, store the granted
	 * tokens, and mark the user's waiting provisions ready.
	 */
	public function onTokenGranted(OAuth2Token $token, array $payload): string {
		$user_id  = intval($payload['user_id'] ?? 0);
		$provider = (string)($payload['provider'] ?? 'linode');
		if ($user_id <= 0) {
			return self::CONNECT_URL;
		}

		$account = CustomerCloudAccount::get_for_user($user_id, $provider);
		if ($account === null) {
			$account = new CustomerCloudAccount(NULL);
			$account->set('cca_usr_user_id', $user_id);
			$account->set('cca_provider', $provider);
		}
		$account->storeToken($token);
		$account->set('cca_status', 'active');
		$account->save();

		$waiting = new MultiCustomerCloudProvision(array(
			'user_id' => $user_id,
			'status'  => 'pending_connect',
			'deleted' => false,
		));
		$waiting->load();
		foreach ($waiting as $provision) {
			if ($provision->get('cvp_provider') !== $provider) {
				continue;
			}
			$provision->set('cvp_cca_account_id', $account->key);
			$provision->set('cvp_status', 'ready');
			$provision->save();
		}

		return self::CONNECT_URL . '?connected=1';
	}
}
?>
