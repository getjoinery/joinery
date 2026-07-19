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
 * @version 1.0
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
	 *   root_pass       string  root password (required; generate, do not store)
	 *   authorized_keys array   SSH public keys to install for root
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
	 * Delete an instance. Used only for cleaning up a failed provision that
	 * this pipeline itself created — never for customer-initiated teardown.
	 * @throws CloudComputeException on any API failure.
	 */
	public function deleteInstance(string $instance_id): void;

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
