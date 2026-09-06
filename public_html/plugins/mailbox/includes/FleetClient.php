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
 * Ownership challenges are filed automatically (fileDomainClaims): on
 * enrollment for every already-registered hosted domain, and on domain
 * registration while a slot exists. The Setup tab's ownership row re-verifies
 * them on every check pass — publishing the TXT record is all the user does.
 *
 * @version 1.5 - the ssh era is over: enrollment sends the relay client public key only, and
 *                a shard's identity pin plus address are the whole coordinate set
 *                (specs/relay_without_a_shell.md)
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

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
		// The one key a shard needs: this deployment's relay client identity,
		// minted on first use. Its public half goes into the shard's registry;
		// nothing that could read a spool ever leaves this box.
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identity_class.php'));
		$keys = array('public_key' => RelayClientIdentity::publicKey(RelayClientIdentity::KIND_CLIENT));

		$data = $this->call('fleet_enroll', $keys);
		$this->applyCoordinates($data);
		// Every already-registered hosted domain gets its ownership challenge
		// filed now, so the Setup tab's ownership rows carry a publishable
		// record from the first render.
		$this->fileDomainClaims();
		return $data;
	}

	/**
	 * File the ownership challenge for one hosted domain — or, with no
	 * argument, for every registered hosted (non-IMAP-source) domain.
	 * Idempotent (the fleet returns an existing live claim) and best-effort:
	 * failures are logged, never fatal, because the setup check self-heals a
	 * missing challenge on its next pass.
	 */
	public function fileDomainClaims(?string $only_domain = null): void {
		if (!$this->configured() || $this->hostedRelayRow() === null) {
			return;
		}
		$domains = array();
		if ($only_domain !== null) {
			$domains[] = strtolower(trim($only_domain));
		} else {
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
				$multi = new MultiInboundEmailDomain(array('deleted' => false));
				$multi->load();
				foreach ($multi as $d) {
					if (!(bool)$d->get('ied_is_imap_source')) {
						$domains[] = strtolower(trim((string)$d->get('ied_domain')));
					}
				}
			} catch (\Throwable $e) {
				error_log('FleetClient::fileDomainClaims: domain listing failed: ' . $e->getMessage());
				return;
			}
		}
		foreach ($domains as $domain) {
			if ($domain === '') {
				continue;
			}
			try {
				$this->claimDomain($domain);
			} catch (\Throwable $e) {
				error_log('FleetClient::fileDomainClaims: challenge for ' . $domain . ' failed: ' . $e->getMessage());
			}
		}
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
		$relay->set('mrl_authserv_id', substr((string)($c['authserv_id'] ?? ''), 0, 255));
		$relay->set('mrl_fleet_slot_id', intval($c['slot_id'] ?? 0) ?: null);
		$relay->set('mrl_public_ip', substr((string)($c['shard_public_ip'] ?? ''), 0, 64));
		// The shard's identity pin is the whole coordinate set: the tenant
		// reaches it over its API at the public address. A changed pin (the shard
		// was updated) is simply written - the next pull uses it.
		$relay->set('mrl_identity_fingerprint', substr(trim((string)($c['identity_fingerprint'] ?? '')), 0, 64));
		$relay->save();
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
