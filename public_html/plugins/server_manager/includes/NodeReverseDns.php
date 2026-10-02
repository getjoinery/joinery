<?php
/**
 * NodeReverseDns - set the reverse-DNS (PTR) hostname for a cloud-born
 * managed node through the cloud account its instance lives on.
 *
 * Whose account that is follows the provision's hosting mode: a hosted
 * (Managed) instance lives on the OPERATOR's account and is reached with the
 * operator cloud token this plane holds; a bring-your-own-cloud instance lives
 * on the customer's account and is reached through their grant.
 *
 * The forward A record must already resolve to the node's IP — providers
 * (Linode included) validate this and reject the update otherwise, so it is
 * checked here first to give a actionable error instead of a provider 400.
 *
 * Grant note: Linode access tokens are short-lived with no refresh token, so
 * a stale grant surfaces as NodeReverseDnsException with reconnect=true — the
 * caller should send the operator to /profile/server_manager/connect_cloud
 * and retry. A hosted instance has no grant, so it never raises reconnect: a
 * missing or refused operator token is fixed on the Provisioning Setup page.
 *
 * A TRANSFERRED instance — a Managed site handed to its customer's own Linode
 * account (specs/managed_to_self_hosted_transfer.md) — is on an account this
 * plane holds no grant for, so its reverse DNS is the customer's to set in
 * their own Cloud Manager. That is said plainly, with no reconnect: there is
 * no grant to reconnect.
 *
 * @version 1.2 - a transferred instance's reverse DNS is the customer's, in their own Cloud Manager
 * @version 1.1 - a hosted instance is reached with the operator cloud token (it had been sent to the
 *                customer-grant path, which a hosted provision never has, so its PTR was never set);
 *                setQuietly() reports reconnect, so a pipeline can tell a dead grant from "not yet"
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_accounts_class.php'));
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

	/** What a transferred instance's reverse DNS answer is: the customer's own panel. */
	const TRANSFERRED_MESSAGE = 'This server was moved to its customer\'s own Linode account, so its reverse DNS is set '
		. 'there: Cloud Manager → the Linode → Network → the IPv4 address → Edit RDNS.';

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
	 * reconnect is true only when the customer's cloud-account grant is dead.
	 * That is not a "not yet": Linode issues no refresh token, so nothing a
	 * later tick can do will bring it back without the customer.
	 *
	 * @return array {ok: bool, message: string, reconnect: bool}
	 */
	public static function setQuietly($node, $hostname, $driver = null, $skip_forward_check = false) {
		try {
			$r = self::set($node, $hostname, $driver, $skip_forward_check);
			return array('ok' => true, 'message' => $r['ip'] . ' now answers ' . $r['rdns'], 'reconnect' => false);
		} catch (NodeReverseDnsException $e) {
			return array('ok' => false, 'message' => $e->getMessage(), 'reconnect' => $e->reconnect);
		} catch (Exception $e) {
			return array('ok' => false, 'message' => $e->getMessage(), 'reconnect' => false);
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

		if ($provision->is_transferred()) {
			throw new NodeReverseDnsException(self::TRANSFERRED_MESSAGE);
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
			if ((int)$e->getCode() === 401 && $provision->is_operator_hosted()) {
				throw new NodeReverseDnsException(
					'The provider refused the operator cloud token. Replace it on the Provisioning Setup page, then try again.');
			}
			if ((int)$e->getCode() === 401) {
				throw NodeReverseDnsException::reconnect(
					'The cloud account grant has expired. Re-connect it, then try again.');
			}
			throw new NodeReverseDnsException('Provider rejected the update: ' . $e->getMessage());
		}
	}

	/**
	 * Build a driver for the account the provision's instance lives on: the
	 * operator's token for a hosted instance, else the customer's grant,
	 * refreshed when the provider supports it.
	 *
	 * @param string|null $operator_token  Tests only; null reads the configured operator token.
	 */
	public static function driverForProvision($provision, ?string $operator_token = null) {
		if ($provision->is_transferred()) {
			throw new NodeReverseDnsException(self::TRANSFERRED_MESSAGE);
		}
		if ($provision->get('cvp_provider') !== 'linode') {
			throw new NodeReverseDnsException(
				"No reverse-DNS driver for provider '{$provision->get('cvp_provider')}'.");
		}

		if ($provision->is_operator_hosted()) {
			$token = $operator_token ?? ProvisionCustomerCloud::operator_compute_token();
			if ($token === '') {
				throw new NodeReverseDnsException(
					'This site is hosted on the operator\'s cloud account, and no operator cloud token is configured. '
					. 'Set "Operator cloud token" on the Provisioning Setup page, then try again.');
			}
			return new LinodeComputeDriver($token);
		}

		$account_id = (int)$provision->get('cvp_cca_customer_cloud_account_id');
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
