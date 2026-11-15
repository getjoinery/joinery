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
 * The connection itself — the three settings, the JSON POST with the key pair
 * in the headers, the error contract — is the core ServiceClient; this class
 * is the relay-specific half: what to send on enrol, and what to do with the
 * coordinates that come back.
 *
 * Ownership challenges are filed automatically (fileDomainClaims): on
 * enrollment for every already-registered hosted domain, and on domain
 * registration while a slot exists. The Setup tab's ownership row re-verifies
 * them on every check pass — publishing the TXT record is all the user does.
 *
 * @version 1.8 - autoEnroll() works from the end state each pass (enrolled, relay mode, domain registered,
 *                ownership proven) and finishes whatever is missing, so a step that failed is taken again
 * @version 1.7 - autoEnroll(): a site that can receive only through a relay and was seeded by its operator
 *                enrolls, registers its own domain and receives through the slot with no person; such a
 *                site's hosted relay row is born enabled (hosting_launch S5)
 * @version 1.6 - extends the core ServiceClient: configured(), call() and the exception live there
 * @version 1.5 - the ssh era is over: enrollment sends the relay client public key only, and
 *                a shard's identity pin plus address are the whole coordinate set
 *                (specs/relay_without_a_shell.md)
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));

class FleetClient extends ServiceClient {

	public function __construct() {
		parent::__construct('mailbox_fleet_service_url', 'mailbox_fleet_api_public_key',
			'mailbox_fleet_api_secret_key', 'mailbox');
	}

	protected function label(): string {
		return 'Fleet service';
	}

	protected function notConfiguredMessage(): string {
		return 'Fleet service is not configured — set the service URL and API keys on the relay page.';
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
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_client_identities_class.php'));
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
				require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
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

	/** How long autoEnroll() waits after a pass that left something undone (a refusal, a claim still unproven). */
	const AUTO_ENROLL_RETRY_SECONDS = 300;

	/**
	 * Set up receiving with no person, where only the hosted relay can take
	 * this site's mail in: a site with no mail server of its own (a site
	 * container) whose operator seeded the three fleet settings.
	 *
	 * Each pass looks at the end state and does whatever is still missing:
	 * enrolled, receive mode relay, the site's own domain registered for
	 * receiving, and that domain's ownership claim filed and proven (its TXT
	 * record is published by the operator or the buyer; this asks the fleet
	 * to check it). A pass that leaves something undone waits
	 * AUTO_ENROLL_RETRY_SECONDS before the next; once everything is in place
	 * it records mailbox_fleet_auto_enroll_done_time and does nothing more.
	 * Returns a one-line outcome, or '' when it does not apply or has nothing
	 * to do. Throws ServiceClientException when the fleet refuses.
	 */
	public function autoEnroll(): string {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
		if (!$this->configured() || !mailbox_needs_relay()) {
			return '';
		}
		$settings = Globalvars::get_instance();
		$relay = $this->hostedRelayRow();
		if ($relay !== null && (string)$settings->get_setting('mailbox_fleet_auto_enroll_done_time', true, true) !== '') {
			return '';
		}
		$waiting = (string)$settings->get_setting('mailbox_fleet_auto_enroll_failed_time', true, true);
		if ($waiting !== '' && strtotime($waiting . ' UTC') > time() - self::AUTO_ENROLL_RETRY_SECONDS) {
			return '';
		}

		$done = array();
		$owed = array();
		try {
			if ($relay === null) {
				$data = $this->enroll();
				$done[] = 'enrolled in hosted relay slot ' . (string)($data['slug'] ?? '?');
			}
			if ((string)$settings->get_setting('mailbox_receive_mode', true, true) !== 'relay') {
				Setting::put('mailbox_receive_mode', 'relay');
				$done[] = 'receive mode set to relay';
			}
			$domain = self::siteDomain();
			if ($domain === '') {
				$owed[] = 'this site has no domain name of its own to receive for';
			} else {
				require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
				$registered = (bool)InboundEmailDomain::GetByDomain($domain);
				if (!$registered) {
					// Registering files the domain's ownership claim (a slot exists now).
					require_once(PathHelper::getIncludePath('plugins/mailbox/includes/provisioning.php'));
					$result = mailbox_provision_domain($domain);
					$registered = $result['error'] === null;
					if ($registered) {
						$done[] = $domain . ' registered for receiving';
					} else {
						$owed[] = $domain . ' not registered: ' . $result['error'];
					}
				}
				if ($registered && !$this->siteClaimProven($domain)) {
					$owed[] = 'waiting for ' . $domain . '\'s ownership record in DNS';
				}
			}
		} catch (ServiceClientException $e) {
			Setting::put('mailbox_fleet_auto_enroll_failed_time', gmdate('Y-m-d H:i:s'));
			throw $e;
		}

		if ($owed) {
			Setting::put('mailbox_fleet_auto_enroll_failed_time', gmdate('Y-m-d H:i:s'));
		} else {
			Setting::put('mailbox_fleet_auto_enroll_failed_time', '');
			Setting::put('mailbox_fleet_auto_enroll_done_time', gmdate('Y-m-d H:i:s'));
			$done[] = 'receiving through the hosted relay';
		}
		return 'Hosted relay: ' . implode('; ', array_merge($done, $owed)) . '.';
	}

	/**
	 * Whether the domain's ownership claim on this site's slot is proven,
	 * filing it when missing and asking the fleet to check a pending one.
	 */
	private function siteClaimProven(string $domain): bool {
		$claim = null;
		$status = $this->status();
		foreach ((array)($status['claims'] ?? array()) as $row) {
			if (strtolower(trim((string)($row['domain'] ?? ''))) === $domain) {
				$claim = $row;
				break;
			}
		}
		if ($claim === null) {
			$claim = $this->claimDomain($domain);
		}
		if ((string)($claim['status'] ?? '') === 'verified') {
			return true;
		}
		$verdict = $this->verifyDomain(intval($claim['claim_id'] ?? 0));
		return !empty($verdict['verified']);
	}

	/** This site's own domain, from webDir (no scheme, port, path or leading www.), or '' for an address or a bare name. */
	public static function siteDomain(): string {
		$host = strtolower(trim((string)Globalvars::get_instance()->get_setting('webDir')));
		$host = preg_replace('#^https?://#', '', $host);
		$host = preg_replace('#[/:].*$#', '', $host);
		$host = preg_replace('#^www\.#', '', $host);
		if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || strpos($host, '.') === false) {
			return '';
		}
		return $host;
	}

	/**
	 * Upsert this deployment's hosted MailboxRelay row from the slot
	 * coordinates. Created DISABLED (like a self-provisioned relay) — enabling
	 * it is the admin's explicit act once the setup checks pass — except on a
	 * site that can receive only through a relay (mailbox_needs_relay), which
	 * has no other way in: its row is born enabled, and the cutover check
	 * reports the DNS still to move.
	 */
	public function applyCoordinates(array $c): MailboxRelay {
		$relay = $this->hostedRelayRow();
		$is_new = ($relay === null);
		if ($is_new) {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
			$relay = new MailboxRelay(NULL);
			$relay->set('mrl_is_enabled', mailbox_needs_relay());
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
}
