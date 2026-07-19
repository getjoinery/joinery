<?php
/**
 * FleetClient - the tenant-side client of the operator's fleet service.
 *
 * (specs/mailbox_relay_shared_fleet.md § Enrollment). Runs on a TENANT
 * deployment: calls the operator's /api/v1/action/mailbox/fleet_* actions with
 * the customer account's API key (settings: mailbox_fleet_service_url +
 * mailbox_fleet_api_public_key / mailbox_fleet_api_secret_key), and folds the
 * returned slot coordinates into this deployment's MailboxRelay row — after
 * which the relay consumers (spool pull, fragment push, health checks) run
 * exactly as against a self-hosted relay. Hosted vs self-hosted differs only
 * in where the coordinates came from.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));

class FleetClientException extends Exception {}

class FleetClient {

	/** @var Globalvars */
	private $settings;

	public function __construct() {
		$this->settings = Globalvars::get_instance();
	}

	/** True when the three fleet-service settings are filled in. */
	public function configured(): bool {
		return trim((string)$this->settings->get_setting('mailbox_fleet_service_url')) !== ''
			&& trim((string)$this->settings->get_setting('mailbox_fleet_api_public_key')) !== ''
			&& trim((string)$this->settings->get_setting('mailbox_fleet_api_secret_key')) !== '';
	}

	/**
	 * Enroll this deployment: send our WireGuard + pull public keys, store the
	 * returned coordinates. Returns the coordinates array. Idempotent (the
	 * fleet returns the existing slot).
	 */
	public function enroll(): array {
		$wg_pubkey = trim((string)$this->settings->get_setting('mailbox_relay_wg_public_key'));
		if ($wg_pubkey === '') {
			throw new FleetClientException(
				'No main-box WireGuard key — run provision_relay_main.sh (sudo) on this server first.');
		}
		$pull_pubkey = trim((string)@file_get_contents(RelaySsh::pullKeyPath() . '.pub'));
		if ($pull_pubkey === '') {
			throw new FleetClientException(
				'No relay pull key — run provision_relay_main.sh (sudo) on this server first.');
		}

		$data = $this->call('fleet_enroll', array(
			'wg_public_key'   => $wg_pubkey,
			'pull_public_key' => $pull_pubkey,
		));
		$this->applyCoordinates($data);
		return $data;
	}

	/** Poll the slot; re-applies coordinates so a re-shard lands automatically. */
	public function status(): array {
		$data = $this->call('fleet_status', array());
		if (!empty($data['enrolled']) && !empty($data['coordinates'])) {
			$this->applyCoordinates($data['coordinates']);
		}
		return $data;
	}

	public function claimDomain(string $domain): array {
		return $this->call('fleet_claim_domain', array('domain' => $domain));
	}

	public function verifyDomain(int $claim_id): array {
		return $this->call('fleet_verify_domain', array('claim_id' => $claim_id));
	}

	public function release(): array {
		return $this->call('fleet_release', array());
	}

	/**
	 * Upsert this deployment's hosted MailboxRelay row from the slot
	 * coordinates. Created DISABLED (like a self-provisioned relay) — enabling
	 * it is the admin's explicit act once the setup checks pass.
	 */
	public function applyCoordinates(array $c): MailboxRelay {
		$relay = $this->hostedRelayRow();
		$is_new = ($relay === null);
		if ($is_new) {
			$relay = new MailboxRelay(NULL);
			$relay->set('mrl_is_enabled', false);
		}
		$relay->set('mrl_is_hosted', true);
		$relay->set('mrl_name', 'Hosted fleet slot');
		$relay->set('mrl_tenant_slug', substr((string)($c['slug'] ?? ''), 0, 28));
		$relay->set('mrl_mx_hostname', substr((string)($c['mx_hostname'] ?? ''), 0, 255));
		$relay->set('mrl_fleet_slot_id', intval($c['slot_id'] ?? 0) ?: null);
		// The tunnel: the shard listens at .1; we dial out to its endpoint.
		$relay->set('mrl_host', (string)($c['relay_tunnel_ip'] ?? '10.99.0.1'));
		$relay->set('mrl_public_ip', substr((string)($c['shard_public_ip'] ?? ''), 0, 64));
		$relay->set('mrl_wg_endpoint', substr((string)($c['wg_endpoint'] ?? ''), 0, 255));
		$relay->set('mrl_wg_public_key', substr((string)($c['wg_public_key'] ?? ''), 0, 255));
		$relay->set('mrl_wg_ip', (string)($c['relay_tunnel_ip'] ?? '10.99.0.1'));
		$relay->set('mrl_ssh_user', substr((string)($c['ssh_user'] ?? ''), 0, 50));
		$relay->set('mrl_spool_path', substr((string)($c['spool_path'] ?? ''), 0, 500));
		$pull_key = RelaySsh::pullKeyPath();
		if (is_file($pull_key)) {
			$relay->set('mrl_ssh_key_path', $pull_key);
		}
		$relay->save();

		// Apply the fleet-allocated tunnel address to this box's interface
		// (self-hosted keeps the 10.99.0.2 default; a hosted allocation can be
		// any address in the shard's subnet). Narrow root helper installed by
		// provision_relay_main.sh; best-effort like the peer add below.
		$tunnel_ip = trim((string)($c['tunnel_ip'] ?? ''));
		if ($tunnel_ip !== '' && preg_match('/^10\.99\.0\.\d{1,3}$/', $tunnel_ip)) {
			$addr_out = array(); $addr_code = 1;
			exec('sudo -n /usr/local/sbin/joinery-relay-addr ' . escapeshellarg($tunnel_ip) . ' 2>&1',
				$addr_out, $addr_code);
			if ($addr_code !== 0) {
				error_log('FleetClient: tunnel address set failed (' . $addr_code . '): '
					. implode(' ', $addr_out) . ' — run provision_relay_main.sh on this box');
			}
		}

		// Peer the shard on this box's WireGuard interface (the tenant dials
		// out), through the narrow root helper provision_relay_main.sh installs.
		// Best-effort: on failure the tunnel checks go red and say what to run.
		$wg_key = trim((string)($c['wg_public_key'] ?? ''));
		$endpoint = trim((string)($c['wg_endpoint'] ?? ''));
		if ($wg_key !== '' && $endpoint !== '') {
			$peer_cmd = 'sudo -n /usr/local/sbin/joinery-relay-peer '
				. escapeshellarg($wg_key) . ' ' . escapeshellarg($endpoint) . ' 2>&1';
			$peer_out = array(); $peer_code = 1;
			exec($peer_cmd, $peer_out, $peer_code);
			if ($peer_code !== 0) {
				error_log('FleetClient: WireGuard peer add failed (' . $peer_code . '): '
					. implode(' ', $peer_out) . ' — run provision_relay_main.sh on this box');
			}
		}

		return $relay;
	}

	/** This deployment's hosted relay row (enabled or not), or null. */
	public function hostedRelayRow(): ?MailboxRelay {
		$multi = new MultiMailboxRelay(array('deleted' => false));
		$multi->load();
		foreach ($multi as $relay) {
			if ((bool)$relay->get('mrl_is_hosted')) {
				return $relay;
			}
		}
		return null;
	}

	/**
	 * One fleet API call. Returns the response's data array; throws
	 * FleetClientException with a user-facing message on any failure.
	 */
	public function call(string $action, array $payload): array {
		if (!$this->configured()) {
			throw new FleetClientException(
				'Fleet service is not configured — set the service URL and API keys on the relay page.');
		}
		$base = rtrim(trim((string)$this->settings->get_setting('mailbox_fleet_service_url')), '/');
		$url = $base . '/api/v1/action/mailbox/' . $action;

		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($payload),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				// Dash spelling: Apache→FPM stacks silently drop header names
				// containing underscores (see api/apiv1.php's normalization).
				'public-key: ' . trim((string)$this->settings->get_setting('mailbox_fleet_api_public_key')),
				'secret-key: ' . trim((string)$this->settings->get_setting('mailbox_fleet_api_secret_key')),
			),
		));
		$body = curl_exec($ch);
		$err = curl_error($ch);
		$http = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
		curl_close($ch);

		if ($body === false) {
			throw new FleetClientException('Could not reach the fleet service: ' . $err);
		}
		$decoded = json_decode((string)$body, true);
		if (!is_array($decoded)) {
			throw new FleetClientException('Fleet service returned an unreadable response (HTTP ' . $http . ').');
		}
		if ($http !== 200) {
			$msg = (string)($decoded['error'] ?? $decoded['error_message'] ?? $decoded['message'] ?? ('HTTP ' . $http));
			throw new FleetClientException('Fleet service: ' . $msg);
		}
		return is_array($decoded['data'] ?? null) ? $decoded['data'] : array();
	}
}
