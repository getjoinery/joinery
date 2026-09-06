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
	 * this pipeline itself created — never for customer-initiated teardown.
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

class CloudComputeException extends Exception {}
