<?php
/**
 * NodeReverseDns - set the reverse-DNS (PTR) hostname for a cloud-born
 * managed node through the cloud account its instance lives on.
 *
 * Whose account that is follows the provision's hosting mode: a hosted
 * (Managed) instance lives in one of OUR accounts and is reached with the
 * credential this plane holds for that account (HeldCloudAccounts); a
 * bring-your-own-cloud instance lives on the customer's account and is
 * reached through their grant.
 *
 * Both of the node's addresses are named: its IPv4, whose A record must
 * already resolve to it, and its IPv6 when the name's AAAA resolves to that.
 * Providers (Linode included) validate the forward record and reject the
 * update otherwise, so it is checked here first to give an actionable error
 * instead of a provider 400.
 *
 * Grant note: Linode access tokens are short-lived with no refresh token, so
 * a stale grant surfaces as NodeReverseDnsException with reconnect=true — the
 * caller should send the operator to /profile/server_manager/connect_cloud
 * and retry. A hosted instance has no grant, so it never raises reconnect: a
 * missing or refused credential is fixed on the Hosting tab of Provisioning Setup.
 *
 * A TRANSFERRED instance — a Managed site handed to its customer's own Linode
 * account (specs/managed_to_self_hosted_transfer.md) — is on an account this
 * plane holds no grant for, so its reverse DNS is the customer's to set in
 * their own Cloud Manager. That is said plainly, with no reconnect: there is
 * no grant to reconnect.
 *
 * @version 1.6 - the node's IPv6 is named too, when the name's AAAA gives it (it was left with no PTR while
 *               the site's AAAA pointed at it); a caller that has seen the public resolvers passes the
 *               addresses they gave, not a yes, so each address is checked against its own record
 * @version 1.5 - the forward check is skipped by a caller that has seen the public resolvers agree (a settled
 *               site copy move by DNS), not only by tests
 * @version 1.4 - the driver is the provision's provider's (ComputeDriverRegistry), any provider with a driver
 *               (site_copy_anything_to_anything D2)
 * @version 1.3 - a server in one of our accounts uses that account's credential (cloud_accounts_list)
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
	public static function setQuietly($node, $hostname, $driver = null, ?array $named = null) {
		try {
			$r = self::set($node, $hostname, $driver, $named);
			return array('ok' => true, 'message' => self::summary($r), 'reconnect' => false);
		} catch (NodeReverseDnsException $e) {
			return array('ok' => false, 'message' => $e->getMessage(), 'reconnect' => $e->reconnect);
		} catch (Exception $e) {
			return array('ok' => false, 'message' => $e->getMessage(), 'reconnect' => false);
		}
	}

	/**
	 * Set the PTR of the node's provisioned addresses to $hostname: its IPv4,
	 * and its IPv6 when the name's AAAA gives that address. A name with only
	 * an A record leaves the IPv6 as it was, and the result says so: a
	 * provider takes only a name that resolves to the address. The IPv4 is
	 * the one that must take; a provider refusing only the IPv6 is reported,
	 * not thrown.
	 *
	 * @param ManagedNode $node
	 * @param string $hostname   FQDN the addresses should reverse-resolve to.
	 * @param CloudComputeProvider|null $driver  Injectable for tests.
	 * @param array|null $named  The addresses the caller has seen the public resolvers give the name (a
	 *                           settled site copy move), so this machine's own resolver, which may lag, is
	 *                           not asked; the provider checks. Null asks this machine's resolver.
	 * @return array {ip: the IPv4, rdns, set: every address set, not_set: address => why it was not}
	 * @throws NodeReverseDnsException with an operator-actionable message.
	 */
	public static function set($node, $hostname, $driver = null, ?array $named = null) {
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
		$ip6 = (string)$provision->get('cvp_instance_ipv6');

		// The provider rejects rDNS values whose forward record does not point
		// at the address; check first so the error names the real fix.
		if ($named === null) {
			$named = array();
			try {
				$named = (array)DnsResolver::getA($hostname);
			} catch (Exception $e) {
			}
			if ($ip6 !== '') {
				try {
					$named = array_merge($named, (array)DnsResolver::getAaaa($hostname));
				} catch (Exception $e) {
				}
			}
		}
		if (!self::names($named, $ip)) {
			$a_records = array_values(array_filter($named, function ($a) { return strpos((string)$a, ':') === false; }));
			$found = count($a_records) ? implode(', ', $a_records) : 'none';
			throw new NodeReverseDnsException(
				"Create the A record first: {$hostname} must resolve to {$ip} before the provider will accept it as reverse DNS (currently resolves to: {$found}).");
		}
		$not_set = array();
		if ($ip6 !== '' && !self::names($named, $ip6)) {
			$not_set[$ip6] = "{$hostname} has no AAAA record giving it";
		}

		if ($driver === null) {
			$driver = self::driverForProvision($provision);
		}

		$instance_id = (string)$provision->get('cvp_instance_id');
		try {
			$r = $driver->setReverseDns($instance_id, $ip, $hostname);
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 401 && $provision->is_operator_hosted()) {
				$account = HeldCloudAccounts::provision_account($provision);
				throw new NodeReverseDnsException('The provider refused the credential for '
					. ($account ? $account->label() : 'our cloud account')
					. '. Replace it on the Hosting tab of Provisioning Setup, then try again.');
			}
			if ((int)$e->getCode() === 401) {
				throw NodeReverseDnsException::reconnect(
					'The cloud account grant has expired. Re-connect it, then try again.');
			}
			throw new NodeReverseDnsException('Provider rejected the update: ' . $e->getMessage());
		}
		$set = array($ip);
		if ($ip6 !== '' && !isset($not_set[$ip6])) {
			try {
				$driver->setReverseDns($instance_id, $ip6, $hostname);
				$set[] = $ip6;
			} catch (CloudComputeException $e) {
				$not_set[$ip6] = 'the provider refused it: ' . $e->getMessage();
			}
		}
		return array('ip' => (string)($r['ip'] ?? $ip), 'rdns' => (string)($r['rdns'] ?? $hostname),
			'set' => $set, 'not_set' => $not_set);
	}

	/**
	 * One sentence for what set() did: the addresses that answer the name
	 * now, then any it left as they were and why.
	 */
	public static function summary(array $r): string {
		$set = (array)($r['set'] ?? array($r['ip'] ?? ''));
		$out = implode(' and ', $set) . (count($set) > 1 ? ' now answer ' : ' now answers ') . ($r['rdns'] ?? '');
		foreach ((array)($r['not_set'] ?? array()) as $address => $why) {
			$out .= '; ' . $address . ' keeps its reverse DNS: ' . $why;
		}
		return $out;
	}

	/** Is $address among $named, compared as addresses (an IPv6 has many spellings)? */
	private static function names(array $named, string $address): bool {
		$want = @inet_pton($address);
		if ($want === false) {
			return false;
		}
		foreach ($named as $a) {
			if (@inet_pton((string)$a) === $want) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a driver for the account the provision's instance lives on: the
	 * credential of the account of ours it names, else the customer's grant,
	 * refreshed when the provider supports it.
	 *
	 * @param string|null $operator_token  Tests only; null reads the credential of the server's own account.
	 */
	public static function driverForProvision($provision, ?string $operator_token = null) {
		if ($provision->is_transferred()) {
			throw new NodeReverseDnsException(self::TRANSFERRED_MESSAGE);
		}
		$provider = (string)$provision->get('cvp_provider');
		if (!ComputeDriverRegistry::known($provider)) {
			throw new NodeReverseDnsException("No reverse-DNS driver for provider '{$provider}'.");
		}

		if ($provision->is_operator_hosted()) {
			$held = $operator_token !== null ? array('token' => $operator_token, 'reason' =>
				'This management node holds no credential for the cloud account this server is in. Add it on the Hosting tab of '
				. 'Provisioning Setup, then try again.')
				: HeldCloudAccounts::provision_token($provision);
			if ($held['token'] === '') {
				throw new NodeReverseDnsException($held['reason']);
			}
			return OperatorCloudAccount::driver_for($held['token'], $held['provider'] ?? $provider);
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

		return ComputeDriverRegistry::driver((string)$account->get('cca_provider'), $fresh->getAccessToken());
	}
}
