<?php
/**
 * CloudComputeProvider - Contract for creating compute instances on a
 * customer's own cloud account.
 *
 * A driver is constructed with a bearer access token scoped to the customer's
 * account (obtained via the platform OAuth2 flow), so every operation acts on
 * — and is billed to — that account. Drivers are pure API wrappers: no
 * Joinery models, no persistence, no install logic.
 *
 * Instance arrays returned by createInstance()/getInstance() are normalized:
 *   id     string  provider instance id
 *   status string  provider status ('provisioning', 'booting', 'running', ...)
 *   ip     string  first public IPv4, '' until assigned
 *   label  string  provider-side label
 *
 * @version 1.7 - CloudAccountCleanup: an account's own company name, its volumes, and deleting one; each
 *                listed instance carries its tags (specs/test_cloud_account_and_prod_management.md WP3)
 * @version 1.6 - CloudMachineTransfer: an account's instances and each one's transfer this month
 *                (specs/node_outbound_and_transfer.md WP1)
 * @version 1.5 - CloudAccountIdentity: the provider's own name for the account a token reaches
 * @version 1.4 - CloudAddressSwap: the optional capability to swap two instances' IPv4 addresses
 *                (specs/site_copy.md WP12)
 * @version 1.3 - CloudInstanceTransfers: the optional capability to hand an instance to another
 *                account of the same provider (specs/managed_to_self_hosted_transfer.md)
 * @version 1.2 - shutdownInstance()/bootInstance()/getTransfer(): the plane's only lever over a
 *                hosted instance is power, and the account's transfer pool is what it watches.
 * @version 1.1 - rebuildInstance(): replace an instance's contents in place.
 */

interface CloudComputeProvider {

	/**
	 * Create an instance on the customer's account.
	 *
	 * $opts:
	 *   label           string  instance label (required)
	 *   region          string  provider region id (required)
	 *   type            string  provider plan/type id (required)
	 *   image           string  provider image id (required)
	 *   root_pass       string  root password (generate, do not store; a driver
	 *                           mints one when absent and the provider insists)
	 *   authorized_keys array   SSH public keys to install for root
	 *   user_data       string  first-boot script (cloud-init user-data) the
	 *                           instance runs once, as root - how a relay is
	 *                           born configured (specs/relay_without_a_shell.md)
	 *   stackscript_id  string  provider-side first-boot script for a region
	 *                           whose metadata service cannot carry user_data
	 *   stackscript_data array  its named fields
	 *
	 * @return array Normalized instance array.
	 * @throws CloudComputeException on any API failure.
	 */
	public function createInstance(array $opts): array;

	/**
	 * Fetch current instance state.
	 * @return array Normalized instance array.
	 * @throws CloudComputeException on any API failure (including not-found).
	 */
	public function getInstance(string $instance_id): array;

	/**
	 * Replace an existing instance's contents in place: wipe every disk and
	 * redeploy the image, keeping the instance itself and — critically — its
	 * public IPv4.
	 *
	 * That address preservation is the whole reason this method exists rather
	 * than delete-then-create. A relay's address is what an MX record points at,
	 * so a rebuild is a few minutes of downtime while a recreate is a DNS change
	 * plus propagation on the record whose job is to be stable.
	 *
	 * A provider that cannot rebuild in place cannot host a relay: silently
	 * degrading to delete-and-create would move the address without saying so.
	 * Such a driver must throw rather than approximate.
	 *
	 * $opts:
	 *   image           string  provider image id (required)
	 *   root_pass       string  root password (as createInstance)
	 *   authorized_keys array   SSH public keys to install for root
	 *   user_data, stackscript_id, stackscript_data   as createInstance: a
	 *                           relay's update is a re-image with fresh user-data
	 *
	 * @return array Normalized instance array.
	 * @throws CloudComputeException on any API failure.
	 */
	public function rebuildInstance(string $instance_id, array $opts): array;

	/**
	 * Delete an instance. Used only for cleaning up a failed provision that
	 * this pipeline itself created, and by the test-account cleanup on an
	 * account whose own name says it is disposable — never for
	 * customer-initiated teardown.
	 * @throws CloudComputeException on any API failure.
	 */
	public function deleteInstance(string $instance_id): void;

	/**
	 * Power an instance off, keeping it and its address.
	 *
	 * This is the strongest thing the platform does to a cloud instance on its
	 * own. Deletion is a person at the provider, always: an unpaid subscription
	 * or an abuse threshold is a billing fact, and no billing fact should be
	 * able to destroy somebody's data unattended. A shut-down instance still
	 * bills, and that is the price of the rule.
	 *
	 * @throws CloudComputeException on any API failure.
	 */
	public function shutdownInstance(string $instance_id): void;

	/**
	 * Power an instance back on. The other half of shutdownInstance — a
	 * customer who pays after a suspension gets their machine back without a
	 * person at the provider.
	 *
	 * @throws CloudComputeException on any API failure.
	 */
	public function bootInstance(string $instance_id): void;

	/**
	 * The ACCOUNT's outbound transfer for the current billing period.
	 *
	 * Account-wide rather than per-instance because that is how the pool is
	 * actually billed: instances contribute to one allowance and overage is
	 * charged against the pool, so a per-customer figure would be a number with
	 * no bill behind it.
	 *
	 * @return array {used_gb: float, quota_gb: float, billable_gb: float}
	 * @throws CloudComputeException on any API failure.
	 */
	public function getTransfer(): array;

	/**
	 * Set the reverse-DNS (PTR) hostname on one of the instance's IPs.
	 * Providers typically require the hostname's forward A record to already
	 * resolve to the address, and reject the update otherwise.
	 *
	 * @return array {ip: string, rdns: string} as stored by the provider.
	 * @throws CloudComputeException on any API failure.
	 */
	public function setReverseDns(string $instance_id, string $ip, string $hostname): array;
}

/**
 * The optional capability to hand an instance, running and with its addresses,
 * to another account of the same provider.
 *
 * A separate contract rather than four more methods on CloudComputeProvider:
 * most providers have no such thing, and a driver that cannot answers by not
 * implementing it — the caller asks `instanceof CloudInstanceTransfers` and
 * says "not supported" instead of a driver throwing from a stub.
 *
 * The transfer token a create returns is a bearer secret: whoever redeems it
 * gets the instance and every byte on it. Implementations never put it in an
 * exception message.
 */
interface CloudInstanceTransfers {

	/**
	 * Everything on the provider's side that stops this instance being handed
	 * over, or is worth saying first. Each entry:
	 *   key     string  stable name of the check
	 *   label   string  what was checked, in plain words
	 *   result  string  'pass' | 'blocker' | 'warning'
	 *   detail  string  what was found (empty on a pass)
	 *   fix     string  what to do about it (empty on a pass)
	 *
	 * @throws CloudComputeException when the provider cannot be asked at all.
	 */
	public function transferEligibility(string $instance_id): array;

	/**
	 * Start a transfer of one instance. Returns
	 *   token   string  the code the receiving account redeems
	 *   status  string  provider status ('pending')
	 *   expiry  string  UTC 'Y-m-d H:i:s' the code stops working
	 *
	 * @throws CloudComputeException carrying the provider's own reason on refusal.
	 */
	public function createTransfer(string $instance_id): array;

	/**
	 * Where a transfer stands:
	 *   status  string  pending | accepted | completed | failed | canceled | stale
	 *   expiry  string  UTC 'Y-m-d H:i:s', or ''
	 *
	 * @throws CloudComputeException on any API failure.
	 */
	public function getTransferStatus(string $token): array;

	/**
	 * Withdraw a transfer. Only a pending one can be withdrawn.
	 *
	 * @throws CloudComputeException on any API failure.
	 */
	public function cancelTransfer(string $token): void;
}

/**
 * Optional capability: swap the public IPv4 addresses of two instances of one
 * account in one region (a site copy's switch-over by IP swap,
 * specs/site_copy.md WP12). The address moves, with its reverse DNS, and every
 * fact bound to it (allow-lists, SPF, the A records) stays true. A driver that
 * cannot answers by not implementing it; the caller asks
 * `instanceof CloudAddressSwap`.
 */
interface CloudAddressSwap {

	/**
	 * The instance as the provider reports it, with what a swap needs:
	 *   id, status, region, label
	 *   ipv4_public    string[]  the public IPv4 addresses
	 *   ipv6           string    the instance's own IPv6 address, or ''
	 *   network_helper bool|null whether the provider configures the
	 *                            address at boot (null: could not be read)
	 *
	 * @throws CloudComputeException
	 */
	public function addressReport(string $instance_id): array;

	/**
	 * One address as the provider records it: address, instance_id, rdns.
	 *
	 * @throws CloudComputeException
	 */
	public function ipAddress(string $address): array;

	/**
	 * Move the addresses at once: [address => instance id, ...], every
	 * instance keeping at least one public IPv4.
	 *
	 * @throws CloudComputeException carrying the provider's reason
	 */
	public function assignIpv4(string $region, array $assignments): void;

	/** The provider firewalls attached to an instance, as [id => label]. @throws CloudComputeException */
	public function instanceFirewalls(string $instance_id): array;

	/** Reboot a running instance; a stopped one is booted with bootInstance(). @throws CloudComputeException */
	public function rebootInstance(string $instance_id): void;
}

class CloudComputeException extends Exception {}

/**
 * Optional capability: each machine's outbound transfer this billing month
 * (specs/node_outbound_and_transfer.md WP1). An account's allowance is pooled,
 * so the pool alone hides one machine running far past its own share; this
 * reads the share. The caller asks `instanceof CloudMachineTransfer`.
 */
interface CloudMachineTransfer {

	/**
	 * Every instance on the account, for matching the plane's nodes to them:
	 *   id, status, label, region   as the normalized instance array
	 *   ipv4_public  string[]  every public IPv4
	 *   ipv6         string    the instance's own IPv6, or ''
	 *   created      string    UTC 'Y-m-d H:i:s'
	 *   tags         string[]  the provider-side tags
	 *
	 * @throws CloudComputeException
	 */
	public function listInstances(): array;

	/**
	 * The instance's transfer this billing month:
	 *   used_bytes   int    counted this month (the provider's figure, not the interface's)
	 *   quota_gb     float  the allowance it adds to the pool this month (prorated
	 *                       for an instance created during the month)
	 *   billable_gb  float  past the allowance
	 *
	 * @throws CloudComputeException
	 */
	public function getInstanceTransfer(string $instance_id): array;
}

/**
 * Optional capability: the provider's own name for the account a token
 * reaches, so a page offering accounts names each as the provider does rather
 * than by who connected it. The caller asks `instanceof CloudAccountIdentity`.
 */
interface CloudAccountIdentity {

	/**
	 * A short name the account's owner recognizes at the provider.
	 *
	 * @throws CloudComputeException when the token cannot read it
	 */
	public function accountName(): string;
}

/**
 * Optional capability: what the test-account cleanup needs beyond listing and
 * deleting instances (specs/test_cloud_account_and_prod_management.md WP3).
 * The caller asks `instanceof CloudAccountCleanup`.
 */
interface CloudAccountCleanup {

	/**
	 * The account's own company (organization) name exactly as its owner set
	 * it at the provider, '' when none is set. The cleanup's safety catch
	 * compares it to a fixed name, so this never falls back to anything else.
	 *
	 * @throws CloudComputeException when the token cannot read the account
	 */
	public function accountCompany(): string;

	/**
	 * Every storage volume on the account:
	 *   id, label    string
	 *   created      string    UTC 'Y-m-d H:i:s'
	 *   attached_to  string    the instance id it is attached to, or ''
	 *   tags         string[]
	 *
	 * @throws CloudComputeException
	 */
	public function listVolumes(): array;

	/** Delete a volume that is attached to nothing. @throws CloudComputeException */
	public function deleteVolume(string $volume_id): void;
}
