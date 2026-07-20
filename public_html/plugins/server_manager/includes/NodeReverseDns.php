<?php
/**
 * NodeReverseDns - set the reverse-DNS (PTR) hostname for a cloud-born
 * managed node through the provision's cloud-account grant.
 *
 * The forward A record must already resolve to the node's IP — providers
 * (Linode included) validate this and reject the update otherwise, so it is
 * checked here first to give a actionable error instead of a provider 400.
 *
 * Grant note: Linode access tokens are short-lived with no refresh token, so
 * a stale grant surfaces as NodeReverseDnsException with reconnect=true — the
 * caller should send the operator to /profile/server_manager/connect_cloud
 * and retry.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class NodeReverseDnsException extends Exception {
	/** @var bool True when the fix is re-connecting the cloud account grant. */
	public $reconnect = false;

	public static function reconnect($message) {
		$e = new self($message);
		$e->reconnect = true;
		return $e;
	}
}

class NodeReverseDns {

	/**
	 * The provision row that birthed this node, or null if the node was not
	 * cloud-born (manually enrolled nodes have no provision linkage).
	 */
	public static function provisionForNode($node) {
		$multi = new MultiCustomerCloudProvision(['node_id' => (int)$node->key, 'deleted' => false]);
		$multi->load();
		foreach ($multi as $provision) {
			if ($provision->get('cvp_instance_id') && $provision->get('cvp_instance_ip')) {
				return $provision;
			}
		}
		return null;
	}

	/**
	 * Best-effort variant for pipeline hooks (e.g. the first SSL-active
	 * confirmation): never throws. A manual node, stale grant, or provider
	 * refusal just returns ok=false — the mailbox Setup tab's PTR check
	 * remains the operator's checklist item for those cases.
	 *
	 * @return array {ok: bool, message: string}
	 */
	public static function setQuietly($node, $hostname, $driver = null, $skip_forward_check = false) {
		try {
			$r = self::set($node, $hostname, $driver, $skip_forward_check);
			return array('ok' => true, 'message' => $r['ip'] . ' now answers ' . $r['rdns']);
		} catch (Exception $e) {
			return array('ok' => false, 'message' => $e->getMessage());
		}
	}

	/**
	 * Set the PTR for the node's provisioned IP to $hostname.
	 *
	 * @param ManagedNode $node
	 * @param string $hostname   FQDN the IP should reverse-resolve to.
	 * @param CloudComputeProvider|null $driver  Injectable for tests.
	 * @param bool $skip_forward_check           Tests only.
	 * @return array {ip, rdns}
	 * @throws NodeReverseDnsException with an operator-actionable message.
	 */
	public static function set($node, $hostname, $driver = null, $skip_forward_check = false) {
		$hostname = strtolower(trim((string)$hostname));
		if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $hostname)) {
			throw new NodeReverseDnsException('Enter a fully-qualified hostname, e.g. mail.example.com.');
		}

		$provision = self::provisionForNode($node);
		if (!$provision) {
			throw new NodeReverseDnsException(
				'This node has no cloud-provision record, so its reverse DNS cannot be managed here — set it in the hosting provider\'s panel.');
		}

		$ip = (string)$provision->get('cvp_instance_ip');

		// The provider rejects rDNS values whose forward record does not point
		// at the address; check first so the error names the real fix.
		if (!$skip_forward_check) {
			try {
				$a_records = DnsResolver::getA($hostname);
			} catch (Exception $e) {
				$a_records = array();
			}
			if (!in_array($ip, $a_records, true)) {
				$found = count($a_records) ? implode(', ', $a_records) : 'none';
				throw new NodeReverseDnsException(
					"Create the A record first: {$hostname} must resolve to {$ip} before the provider will accept it as reverse DNS (currently resolves to: {$found}).");
			}
		}

		if ($driver === null) {
			$driver = self::driverForProvision($provision);
		}

		try {
			return $driver->setReverseDns((string)$provision->get('cvp_instance_id'), $ip, $hostname);
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 401) {
				throw NodeReverseDnsException::reconnect(
					'The cloud account grant has expired. Re-connect it, then try again.');
			}
			throw new NodeReverseDnsException('Provider rejected the update: ' . $e->getMessage());
		}
	}

	/**
	 * Build a driver from the provision's account grant, refreshing the token
	 * when the provider supports it.
	 */
	private static function driverForProvision($provision) {
		if ($provision->get('cvp_provider') !== 'linode') {
			throw new NodeReverseDnsException(
				"No reverse-DNS driver for provider '{$provision->get('cvp_provider')}'.");
		}

		$account_id = (int)$provision->get('cvp_cca_account_id');
		$account = $account_id ? new CustomerCloudAccount($account_id, TRUE) : null;
		if (!$account || !$account->key || $account->get('cca_status') !== 'active') {
			throw NodeReverseDnsException::reconnect(
				'The cloud account link for this node is missing or inactive. Re-connect it, then try again.');
		}

		$token = $account->getToken();
		if ($token === null) {
			throw NodeReverseDnsException::reconnect(
				'No stored token on the cloud account link. Re-connect it, then try again.');
		}

		$provider_class = OAuth2ProviderRegistry::get($account->get('cca_provider'));
		if ($provider_class === null) {
			throw new NodeReverseDnsException("Unknown OAuth provider '{$account->get('cca_provider')}'.");
		}

		try {
			$fresh = (new OAuth2Client())->ensureFresh($provider_class, $token);
		} catch (OAuth2Exception $e) {
			$account->set('cca_status', 'refresh_failed');
			$account->save();
			throw NodeReverseDnsException::reconnect(
				'The cloud account grant has expired. Re-connect it, then try again.');
		}

		if ($fresh->getAccessToken() !== $token->getAccessToken()) {
			$account->storeToken($fresh);
			$account->save();
		}

		return new LinodeComputeDriver($fresh->getAccessToken());
	}
}
