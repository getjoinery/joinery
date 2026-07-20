<?php
/**
 * InboundEmailSetupCheck - the single verification engine for the Inbound
 * Email plugin's guided setup.
 *
 * run() produces an ordered list of structured check results spanning the
 * host software, this server's mail-host identity, per-domain DNS, plugin
 * configuration, and an end-to-end test. The Setup tab, the Domains page and
 * InboundEmailHealth's provisioning checks all consume this — there is no
 * second copy of detection logic.
 *
 * Side-effect-free and time-bounded: DNS goes through DnsResolver, host state
 * through bounded exec()/socket probes.
 *
 * Each result is an associative array:
 *   id, scope, layer, label, severity, status, summary, detail, fix, recheckable
 * where fix is null or ['text'=>, 'command'=>?, 'dns_record'=>['type','name','value']?].
 *
 * TOPOLOGY-AWARE (specs/mailbox_setup_topology_aware.md): every prescription
 * derives from the deployment's receive topology — colocated (the box is the
 * MX), self-hosted relay, or hosted fleet slot. A MailboxRelay row's existence
 * (enabled or not) flips prescriptions to relay targets: the checklist walks
 * the user TO the relay end state, so mid-cutover guidance already names the
 * relay. Topology is deployment-level; security level is per-domain.
 *
 * @version 1.22
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundProviderRegistry.php'));

class InboundEmailSetupCheck {

	// status values
	const PASS = 'pass';
	const FAIL = 'fail';
	const WARN = 'warn';
	const UNKNOWN = 'unknown';
	// INFO is a neutral status: "we legitimately can't tell yet" — rendered
	// without the pass/warn/fail traffic-light alarm. Introduced for the
	// inbound-verification capability check (configured but not yet confirmed).
	const INFO = 'info';

	// severity values
	const REQUIRED = 'required';
	const RECOMMENDED = 'recommended';

	private $settings;
	private $publicIp = '';
	private $publicIpIsPrivate = false;
	private $mailHostname = '';
	private $relay_info = null;
	private $router = null;
	private $spf_mechanisms = array();
	private $dkim_statuses = array();
	private $topology = null;
	private $fleet_state = null;

	function __construct() {
		$this->settings = Globalvars::get_instance();
		$this->mailHostname = strtolower(trim((string)$this->settings->get_setting('mailbox_mail_hostname')));
		list($this->publicIp, $this->publicIpIsPrivate) = $this->detectPublicIp();
	}

	/** The public IP the engine is verifying against (may be ''). */
	public function getPublicIp() { return $this->publicIp; }

	/** Whether the detected public IP is actually an RFC1918/reserved address. */
	public function publicIpIsPrivate() { return $this->publicIpIsPrivate; }

	/** The canonical mail-server hostname in use (configured setting, may be ''). */
	public function getMailHostname() { return $this->mailHostname; }

	/**
	 * The deployment's receive topology, resolved once per run from the
	 * MailboxRelay row — no new state. A relay row created disabled during
	 * cutover already switches every prescription to relay targets; only
	 * deleting/releasing the relay returns colocated guidance.
	 *
	 * @return array{mode:string,relay:?MailboxRelay,mx_hostname:string,public_ip:string,enabled:bool}
	 *         mode is 'colocated' | 'self_hosted' | 'fleet'.
	 */
	public function topology(): array {
		if ($this->topology !== null) {
			return $this->topology;
		}
		$this->topology = array('mode' => 'colocated', 'relay' => null,
			'mx_hostname' => '', 'public_ip' => '', 'enabled' => false);
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
			$multi = new MultiMailboxRelay(array('deleted' => false));
			$multi->load();
			foreach ($multi as $relay) {
				$this->topology = array(
					'mode'        => ((bool)$relay->get('mrl_is_hosted')) ? 'fleet' : 'self_hosted',
					'relay'       => $relay,
					'mx_hostname' => strtolower(trim((string)$relay->get('mrl_mx_hostname'))),
					'public_ip'   => trim((string)$relay->get('mrl_public_ip')),
					'enabled'     => (bool)$relay->get('mrl_is_enabled'),
				);
				break; // at most one relay per deployment
			}
		} catch (\Throwable $e) {
			// Relay table absent (before update_database) — colocated.
		}
		return $this->topology;
	}

	/** Whether a relay or fleet slot fronts this deployment. */
	private function fronted(): bool {
		return $this->topology()['mode'] !== 'colocated';
	}

	/**
	 * One conversation with the fleet service per check run (live, never
	 * cached across runs): slot state plus this deployment's ownership
	 * proofs, keyed by domain. The buttonless ownership contract
	 * (specs/mailbox_setup_topology_aware.md Decision 4) is served here:
	 * a registered domain with no challenge gets one filed (idempotent),
	 * and every pending challenge is re-verified — an idempotent
	 * operator-side DNS lookup — so publishing the record is all the user
	 * ever does. On API failure 'ok' is false and 'error' carries the
	 * user-facing message; the caller renders one UNKNOWN row and the rest
	 * of the page is unaffected.
	 *
	 * @return array{ok:bool,error:string,enrolled:bool,claims:array<string,array>}
	 */
	private function fleetState(): array {
		if ($this->fleet_state !== null) {
			return $this->fleet_state;
		}
		$state = array('ok' => false, 'error' => '', 'enrolled' => false, 'claims' => array());
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
			$client = new FleetClient();
			// status() folds fresh slot coordinates into the relay row —
			// server-side reconciliation, deliberate on a GET view.
			$was_allowed = SystemBase::$allow_get_mutation;
			SystemBase::$allow_get_mutation = true;
			try {
				$status = $client->status();
			} finally {
				SystemBase::$allow_get_mutation = $was_allowed;
			}
			$state['ok'] = true;
			$state['enrolled'] = !empty($status['enrolled']);
			foreach ((array)($status['claims'] ?? array()) as $claim) {
				$domain = strtolower(trim((string)($claim['domain'] ?? '')));
				if ($domain !== '') {
					$state['claims'][$domain] = $claim;
				}
			}
			// Re-run verification for every pending challenge; a claim the
			// operator has since verified flips to proven on this same pass.
			foreach ($state['claims'] as $domain => $claim) {
				if ((string)($claim['status'] ?? '') !== 'pending') {
					continue;
				}
				try {
					$verdict = $client->verifyDomain(intval($claim['claim_id'] ?? 0));
					if (!empty($verdict['verified'])) {
						$state['claims'][$domain]['status'] = 'verified';
					}
				} catch (\Throwable $e) {
					// Verification refusals (e.g. claimed elsewhere meanwhile)
					// surface on the row via the still-pending claim.
					error_log('InboundEmailSetupCheck: fleet verify for ' . $domain . ' failed: ' . $e->getMessage());
				}
			}
		} catch (\Throwable $e) {
			$state['error'] = $e->getMessage();
		}
		$this->fleet_state = $state;
		return $state;
	}

	/**
	 * The fleet's ownership proof for one domain, filing the challenge if none
	 * exists yet (idempotent — registration/enrollment normally files it, this
	 * self-heals the gap so the row always carries a publishable record).
	 */
	private function fleetClaimFor(string $domain): ?array {
		$state = $this->fleetState();
		if (!$state['ok']) {
			return null;
		}
		if (isset($state['claims'][$domain])) {
			return $state['claims'][$domain];
		}
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
			$claim = (new FleetClient())->claimDomain($domain);
			$this->fleet_state['claims'][$domain] = $claim;
			return $claim;
		} catch (\Throwable $e) {
			error_log('InboundEmailSetupCheck: fleet challenge filing for ' . $domain . ' failed: ' . $e->getMessage());
			return array('status' => 'error', 'error' => $e->getMessage());
		}
	}

	/**
	 * Run the full check suite.
	 *
	 * @param string|null $domain  Focus a single domain (the wizard). Null = every registered domain.
	 * @param string|null $address Focus a single address for the alias/e2e checks.
	 * @return array[] Ordered list of result rows.
	 */
	public function run($domain = null, $address = null) {
		$results = array();

		// Provider-supplied checks: Host / Mail-host / per-domain DNS.
		// PostfixProvider returns the same Host + Mail-host + per-domain layers
		// that used to live here; MailgunProvider returns its own catalogue.
		$provider = InboundProviderRegistry::active();

		$domain = $domain ? strtolower(trim($domain)) : null;
		if ($domain) {
			foreach ($provider::getSetupChecks($domain) as $r) { $results[] = $r; }
		} else {
			// Provider-wide checks (host/mailhost) — pass once with null domain.
			foreach ($provider::getSetupChecks(null) as $r) { $results[] = $r; }
			$multi = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
			$multi->load();
			foreach ($multi as $d) {
				foreach ($provider::getSetupChecks($d->get('ied_domain')) as $r) {
					// Drop duplicate host/mailhost layers when iterating multiple domains.
					if ($r['layer'] === 'host' || $r['layer'] === 'mailhost') {
						continue;
					}
					$results[] = $r;
				}
			}
		}

		foreach ($this->checkPlugin() as $r) { $results[] = $r; }

		// Inbound-verification capability: provider-agnostic, so it runs for
		// every provider (not delegated to the provider's host layer).
		$results[] = $this->checkInboundVerification();

		if ($address) {
			foreach ($this->checkAddress($address) as $r) { $results[] = $r; }
		}

		return $results;
	}

	/**
	 * Run only the per-domain DNS checks — for the InboundEmailHealth
	 * provisioner, which must not pay for the host/relay checks.
	 *
	 * @param string|null $domain One domain, or null for every enabled domain.
	 * @return array[]
	 */
	public function runDomainChecks($domain = null) {
		if ($domain) {
			return $this->checkDomain(strtolower(trim($domain)), true);
		}
		$results = array();
		$multi = new MultiInboundEmailDomain(array('deleted' => false, 'enabled' => true), array('ied_domain' => 'ASC'));
		$multi->load();
		foreach ($multi as $d) {
			foreach ($this->checkDomain($d->get('ied_domain'), false) as $r) { $results[] = $r; }
		}
		return $results;
	}

	// Public access to the legacy host/mailhost layers so the PostfixProvider's
	// getSetupChecks() can delegate without resorting to reflection at runtime.
	// These remain provider-specific implementations of the layer; the active
	// provider chooses whether to expose them.
	public function checkHostLayer() {
		return $this->checkHost();
	}
	public function checkMailHostLayer() {
		return $this->checkMailHost();
	}

	/** Roll a result list up into a one-line summary: counts per status (required only for fail). */
	public static function summarize(array $results) {
		$counts = array(self::PASS => 0, self::FAIL => 0, self::WARN => 0, self::UNKNOWN => 0, self::INFO => 0);
		foreach ($results as $r) {
			if (!isset($counts[$r['status']])) { $counts[$r['status']] = 0; }
			$counts[$r['status']]++;
		}
		return $counts;
	}

	// ===================================================================
	// Host layer
	// ===================================================================

	private function checkHost() {
		$out = array();

		// The recorded listener state (specs/mailbox_listener_decommission.md):
		// once decommissioned, the expectation for the local Postfix stack and
		// port 25 inverts — gone is healthy, present is a mismatch.
		$decommissioned = (strtolower(trim((string)$this->settings->get_setting('mailbox_local_listener'))) === 'decommissioned');

		exec('which postfix 2>/dev/null', $o1, $e1);
		$installed = ($e1 === 0);
		exec('pgrep -x master 2>/dev/null', $o2, $e2);
		$running = ($e2 === 0);
		if ($decommissioned) {
			$out[] = $running
				? $this->r('host.postfix', '', 'host', 'Postfix installed and running', self::REQUIRED, self::FAIL,
					'The local listener is recorded as decommissioned, but Postfix is running.',
					'Something restarted it outside this platform. Decommission again from the Relay section, '
					. 'or Restore to make the record honest.')
				: $this->r('host.postfix', '', 'host', 'Postfix installed and running', self::RECOMMENDED, self::PASS,
					'Postfix is stopped — the local listener is decommissioned; the relay receives all mail.');
		} elseif ($installed && $running) {
			$out[] = $this->r('host.postfix', '', 'host', 'Postfix installed and running', self::REQUIRED, self::PASS,
				'Postfix is installed and running.');
		} else {
			$out[] = $this->r('host.postfix', '', 'host', 'Postfix installed and running', self::REQUIRED, self::FAIL,
				$installed ? 'Postfix is installed but not running.' : 'Postfix is not installed.',
				'', $this->installerFix());
		}

		// The transport must not only exist — its argv must run an existing
		// inbound_email handler with a usable php binary. A stale path (e.g.
		// left by a plugin rename) bounces every inbound message, so verify
		// where it actually points, not just that the line is present.
		$t = array();
		exec('postconf -M joinery/unix 2>/dev/null', $t);
		$out[] = $this->transportResult(trim(implode(' ', $t)));

		$vmd = array();
		exec('postconf -h virtual_mailbox_domains 2>/dev/null', $vmd);
		$vmdLine = trim(implode('', $vmd));
		$vmdOk = (strpos($vmdLine, 'pgsql:') !== false && strpos($vmdLine, 'joinery-domains.cf') !== false);
		$out[] = $vmdOk
			? $this->r('host.domain_map', '', 'host', 'Live domain map', self::REQUIRED, self::PASS,
				'Postfix reads inbound domains live from the database.')
			: $this->r('host.domain_map', '', 'host', 'Live domain map', self::REQUIRED, self::FAIL,
				'virtual_mailbox_domains is not wired to the database map.',
				'Found: ' . ($vmdLine !== '' ? $vmdLine : '(empty)'), $this->installerFix());

		exec('which opendkim 2>/dev/null', $o3, $e3);
		$dkInstalled = ($e3 === 0);
		exec('pgrep -x opendkim 2>/dev/null', $o4, $e4);
		$dkRunning = ($e4 === 0);
		if ($decommissioned) {
			$out[] = $dkRunning
				? $this->r('host.opendkim', '', 'host', 'opendkim (DKIM signing)', self::RECOMMENDED, self::WARN,
					'The local listener is recorded as decommissioned, but opendkim is running.',
					'Decommission again from the Relay section, or Restore to make the record honest.')
				: $this->r('host.opendkim', '', 'host', 'opendkim (DKIM signing)', self::RECOMMENDED, self::PASS,
					'opendkim is stopped — decommissioned with the listener; sent mail is signed on its own outbound path.');
		} else {
		$out[] = ($dkInstalled && $dkRunning)
			? $this->r('host.opendkim', '', 'host', 'opendkim (DKIM signing)', self::RECOMMENDED, self::PASS,
				'opendkim is installed and running.')
			: $this->r('host.opendkim', '', 'host', 'opendkim (DKIM signing)', self::RECOMMENDED, self::WARN,
				$dkInstalled ? 'opendkim is installed but not running.'
				             : 'opendkim is not installed — outbound DKIM signing is disabled.',
				'Forwarding still works without it; only outbound DKIM signing is affected.',
				$this->installerFix());
		}

		$p = @stream_socket_client('tcp://127.0.0.1:25', $en, $es, 2);
		$listening = (bool)$p;
		if ($p) { @fclose($p); }
		if ($decommissioned) {
			// Expectation inverted: the listener is recorded as gone, so an
			// answering port 25 is a real mismatch, and silence is the healthy state.
			$out[] = $listening
				? $this->r('host.port25', '', 'host', 'SMTP port 25 listening', self::REQUIRED, self::FAIL,
					'The local listener is recorded as decommissioned, but port 25 answers on this box.',
					'Something reopened it outside this platform. Decommission again from the Relay section, '
					. 'or Restore to make the record honest.')
				: $this->r('host.port25', '', 'host', 'SMTP port 25 listening', self::RECOMMENDED, self::PASS,
					'Port 25 is closed — the local listener is decommissioned; the relay is the only mail door.');
		} elseif ($this->fronted()) {
			// The relay is the public MX; the box's own listener is dead weight
			// awaiting decommission (specs/mailbox_listener_decommission.md) —
			// its state is informational, never a required condition.
			$out[] = $this->r('host.port25', '', 'host', 'SMTP port 25 listening', self::RECOMMENDED, self::INFO,
				'The relay is this deployment\'s public MX; this box\'s own mail listener is pending decommission.',
				'Port 25 ' . ($listening ? 'is' : 'is not') . ' listening locally. Once every domain\'s MX points '
				. 'at the relay, decommission the listener from the Setup tab\'s Relay section.');
		} elseif ($listening) {
			$out[] = $this->r('host.port25', '', 'host', 'SMTP port 25 listening', self::REQUIRED, self::PASS,
				'Port 25 is listening locally.',
				'Reachability from the internet is confirmed only by the end-to-end test below — some ISPs block inbound port 25.');
		} else {
			$out[] = $this->r('host.port25', '', 'host', 'SMTP port 25 listening', self::REQUIRED, self::FAIL,
				'Nothing is listening on port 25 locally.', '', $this->installerFix());
		}

		return $out;
	}

	// ===================================================================
	// Inbound-verification capability (host layer)
	// ===================================================================

	/**
	 * Is inbound mail actually being authentication-verified?
	 *
	 * This is the visible warning an operator sees whenever inbound SPF/DKIM/
	 * DMARC are recorded as 'unverified' — so a missing or broken verifier
	 * surfaces as an explained warning, not a silent gap. It stays useful after
	 * the milters are first provisioned: if they break again (package upgrade,
	 * socket drift), this check catches it.
	 *
	 * Two dimensions decide the status:
	 *   (a) does the SELECTED inbound provider have a verification path we
	 *       support; and
	 *   (b) for a supported provider, is verification actually happening.
	 * Package/config presence alone is not sufficient for (b) — a milter can be
	 * wired but unreachable — so the authoritative signal is BEHAVIORAL: have we
	 * seen any iem_auth_source = 'milter' mail recently? That probe is a DB query,
	 * always available to the web user even when /etc host config is not readable.
	 */
	private function checkInboundVerification() {
		$label = 'Inbound authentication verified';
		$provider = strtolower(trim((string)$this->settings->get_setting('mailbox_provider'))) ?: 'postfix';
		$fix = $this->installerFix();

		// (a) Provider-support probe. Postfix verifies via milters; the webhook
		// providers (Mailgun/SendGrid/SES) read verdicts from their own upstream
		// verification — see specs/inbound_mailgun_verification.md. Anything else
		// has no inbound verification path.
		if ($provider !== 'postfix') {
			$webhook_verifiers = array('mailgun', 'sendgrid', 'ses');
			if (in_array($provider, $webhook_verifiers, true)) {
				return $this->checkWebhookInboundVerification($provider, $label);
			}
			return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::WARN,
				'Inbound authentication isn\'t being verified for the selected mail provider (' . $provider . '); '
				. 'messages are recorded as "unverified".',
				'This provider has no inbound authentication-verification path in the plugin.',
				null);
		}

		// (b) Behavioral probe — the source of truth. Any milter-stamped mail in
		// the recent window proves verification is live.
		$milterSeen = false;
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$milterSeen = (bool)$db->query(
				"SELECT 1 FROM iem_inbound_email_messages
				 WHERE iem_auth_source = 'milter'
				 AND iem_received_time > NOW() - INTERVAL '30 days' LIMIT 1"
			)->fetchColumn();
		} catch (\Throwable $e) {
			// Table absent on a brand-new install, etc. — treat as "none seen".
			$milterSeen = false;
		}

		if ($milterSeen) {
			return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::PASS,
				'Inbound mail is being authentication-verified — recent messages carry milter-stamped '
				. 'SPF/DKIM/DMARC results.', '', null, true);
		}

		// No milter mail seen — enrich the reason from the host config if we can
		// read it. Unreadable host config is NOT a failure (the web user may lack
		// access to /etc); fall back to a neutral "can't confirm".
		$confReadable = is_readable('/etc/opendkim.conf');
		if (!$confReadable) {
			return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::INFO,
				'Can\'t confirm inbound verification from here yet.',
				'No recently-received mail carries a milter verdict, and this server\'s mail config '
				. 'isn\'t readable by the web user — so we can\'t tell whether verification is wired. '
				. 'Send a test message and re-check, or run the installer on the host.',
				$fix, true);
		}

		// Config readable — assess drift. Any of these unmet means verification is broken.
		$issues = array();

		exec('which opendmarc 2>/dev/null', $o1, $e1);
		if ($e1 !== 0) { $issues[] = 'opendmarc is not installed'; }
		else {
			exec('pgrep -x opendmarc 2>/dev/null', $o2, $e2);
			if ($e2 !== 0) { $issues[] = 'opendmarc is not running'; }
		}

		$milters = array();
		exec('postconf -h smtpd_milters 2>/dev/null', $milters);
		$miltersLine = trim(implode(' ', $milters));
		if (strpos($miltersLine, '8891') === false) { $issues[] = 'opendkim milter (port 8891) not in smtpd_milters'; }
		if (strpos($miltersLine, '8893') === false) { $issues[] = 'opendmarc milter (port 8893) not in smtpd_milters'; }

		// opendkim must actually be reachable on the port Postfix dials — this is
		// the exact wired-but-unreachable drift we found.
		$sock = @stream_socket_client('tcp://127.0.0.1:8891', $en, $es, 1);
		if ($sock) { @fclose($sock); } else { $issues[] = 'nothing is listening on opendkim milter port 8891'; }

		$conf = (string)@file_get_contents('/etc/opendkim.conf');
		if (!preg_match('/^\s*Mode\s+\S*v/mi', $conf)) { $issues[] = 'opendkim Mode does not include verify (v)'; }
		if (!preg_match('/^\s*AuthservID\s+\S+/mi', $conf)) { $issues[] = 'opendkim AuthservID is not set'; }

		if (!empty($issues)) {
			return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::WARN,
				'Inbound mail is not being authentication-verified — SPF/DKIM/DMARC are recorded as "unverified".',
				'Detected: ' . implode('; ', $issues) . '. Run the installer, then send a test message to confirm '
				. 'an Authentication-Results header appears.',
				$fix, true);
		}

		// Config looks correct but no milter mail has arrived yet to prove it.
		return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::INFO,
			'Verification is configured; no recently-received mail yet to confirm it.',
			'The opendkim/opendmarc milters look correctly wired, but no message in the last 30 days carries '
			. 'a milter verdict. Send a test message and re-check to confirm end-to-end.',
			null, true);
	}

	/**
	 * Inbound-verification status for a webhook provider that reads upstream
	 * verdicts (Mailgun/SendGrid/SES). These providers verify SPF/DKIM/DMARC on
	 * their own infrastructure and hand the verdicts in over their authenticated
	 * delivery path, so there is no host milter to inspect. The authoritative
	 * signal is the same behavioral one used for Postfix: has any message stamped
	 * with this provider's iem_auth_source arrived recently?
	 */
	private function checkWebhookInboundVerification($provider, $label) {
		$names = array('mailgun' => 'Mailgun', 'sendgrid' => 'SendGrid', 'ses' => 'Amazon SES');
		$name = $names[$provider] ?? ucfirst($provider);

		$seen = false;
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare(
				"SELECT 1 FROM iem_inbound_email_messages
				 WHERE iem_auth_source = ?
				 AND iem_received_time > NOW() - INTERVAL '30 days' LIMIT 1"
			);
			$stmt->execute(array($provider));
			$seen = (bool)$stmt->fetchColumn();
		} catch (\Throwable $e) {
			$seen = false;
		}

		if ($seen) {
			return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::PASS,
				'Inbound mail is being authentication-verified — recent messages carry SPF/DKIM/DMARC '
				. 'verdicts from ' . $name . '.', '', null, true);
		}

		return $this->r('host.inbound_verification', '', 'host', $label, self::REQUIRED, self::INFO,
			$name . ' supplies SPF/DKIM/DMARC verdicts on each inbound message; none received yet to confirm it.',
			'When ' . $name . ' delivers a message through the inbound webhook, its verdicts are recorded '
			. '(iem_auth_source = "' . $provider . '"). Send a test message and re-check to confirm end-to-end.',
			null, true);
	}

	// ===================================================================
	// Mail-host identity layer
	// ===================================================================

	private function checkMailHost() {
		$out = array();

		$hn = array();
		exec('postconf -h myhostname 2>/dev/null', $hn);
		$myhostname = strtolower(trim(implode('', $hn)));
		$isFqdn = ($myhostname !== '' && strpos($myhostname, '.') !== false
			&& $myhostname !== 'localhost' && $myhostname !== 'localhost.localdomain');

		$out[] = $isFqdn
			? $this->r('mailhost.hostname_set', '', 'mailhost', 'Mail server hostname', self::REQUIRED, self::PASS,
				'Postfix myhostname is a fully-qualified name (' . $myhostname . ').')
			: $this->r('mailhost.hostname_set', '', 'mailhost', 'Mail server hostname', self::REQUIRED, self::FAIL,
				'Postfix myhostname is not a fully-qualified name.',
				'Found: ' . ($myhostname !== '' ? $myhostname : '(empty)'), $this->hostnameFix());

		// Canonical mail host: the configured setting, else myhostname if usable.
		$canonical = $this->mailHostname !== '' ? $this->mailHostname : ($isFqdn ? $myhostname : '');

		if ($this->mailHostname === '') {
			$out[] = $this->r('mailhost.hostname_matches', '', 'mailhost', 'Mail hostname configured', self::RECOMMENDED, self::WARN,
				'No mail hostname is configured for the plugin yet.',
				'Set it at the top of this page so every DNS check has a canonical name to verify against.',
				array('text' => 'Enter your mail server hostname in the Setup form above.'));
		} elseif ($isFqdn && strcasecmp($myhostname, $this->mailHostname) === 0) {
			$out[] = $this->r('mailhost.hostname_matches', '', 'mailhost', 'Mail hostname configured', self::REQUIRED, self::PASS,
				'Postfix myhostname matches the configured mail hostname.');
		} else {
			$out[] = $this->r('mailhost.hostname_matches', '', 'mailhost', 'Mail hostname configured', self::REQUIRED, self::FAIL,
				'Postfix myhostname (' . ($myhostname !== '' ? $myhostname : 'unset')
				. ') does not match the configured mail hostname (' . $this->mailHostname . ').',
				'', $this->hostnameFix());
		}

		// Under a fronted topology the public mail-host identity is the RELAY's:
		// its MX hostname must resolve to its IP and its PTR must name it. The
		// box's own A/PTR rows drop — nothing receives or sends directly from
		// the box, so prescribing its records would re-publish the address the
		// relay exists to hide.
		if ($this->fronted()) {
			foreach ($this->frontedMailHostResults() as $r) { $out[] = $r; }
			return $out;
		}

		// A record for the mail host.
		if ($canonical === '') {
			$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Mail host A record', self::REQUIRED, self::UNKNOWN,
				'Cannot check — no mail hostname is configured yet.');
			$out[] = $this->r('mailhost.a_matches_ip', '', 'mailhost', 'Mail host A record points here', self::REQUIRED, self::UNKNOWN,
				'Cannot check — no mail hostname is configured yet.');
		} else {
			list($aRecords, $aOk) = $this->dns(function () use ($canonical) { return DnsResolver::getA($canonical); });
			if (!$aOk) {
				$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Mail host A record', self::REQUIRED, self::UNKNOWN,
					'DNS lookup for ' . $canonical . ' failed — try again.');
				$out[] = $this->r('mailhost.a_matches_ip', '', 'mailhost', 'Mail host A record points here', self::REQUIRED, self::UNKNOWN,
					'DNS lookup for ' . $canonical . ' failed — try again.');
			} elseif (empty($aRecords)) {
				$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Mail host A record', self::REQUIRED, self::FAIL,
					$canonical . ' has no A record.', '',
					$this->dnsFix('A', $canonical, $this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP'));
				$out[] = $this->r('mailhost.a_matches_ip', '', 'mailhost', 'Mail host A record points here', self::REQUIRED, self::FAIL,
					'No A record to compare against this server.', '',
					$this->dnsFix('A', $canonical, $this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP'));
			} else {
				$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Mail host A record', self::REQUIRED, self::PASS,
					$canonical . ' resolves to ' . implode(', ', $aRecords) . '.');
				$out[] = $this->ipMatchResult('mailhost.a_matches_ip', '', 'mailhost', 'Mail host A record points here',
					$aRecords, $canonical . "'s A record");
			}
		}

		// Reverse DNS for the public IP.
		if ($this->publicIp === '') {
			$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Reverse DNS (PTR)', self::REQUIRED, self::UNKNOWN,
				'Cannot check — the server public IP could not be determined.');
		} else {
			list($ptr, $ptrOk) = $this->dns(function () { return DnsResolver::getPtr($this->publicIp); });
			if (!$ptrOk) {
				$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Reverse DNS (PTR)', self::REQUIRED, self::UNKNOWN,
					'Reverse-DNS lookup for ' . $this->publicIp . ' failed — try again.');
			} elseif (empty($ptr)) {
				$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Reverse DNS (PTR)', self::REQUIRED, self::FAIL,
					$this->publicIp . ' has no PTR record.',
					'Reverse DNS is set by whoever owns the IP — for Linode nodes, in the Cloud Manager, not your DNS provider.',
					array('text' => 'In the Linode Cloud Manager, open the Linode for this server, go to the Network tab, '
						. 'and set Reverse DNS (RDNS) for ' . $this->publicIp . ' to '
						. ($canonical !== '' ? $canonical : 'your mail hostname') . '. '
						. 'Linode only accepts a name that already has an A record pointing back to ' . $this->publicIp . '.'));
			} else {
				$ptrName = strtolower($ptr[0]);
				// FCrDNS: the PTR name should forward-resolve back to the same IP.
				list($fwd, $fwdOk) = $this->dns(function () use ($ptrName) { return DnsResolver::getA($ptrName); });
				if ($fwdOk && in_array($this->publicIp, $fwd, true)) {
					$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Reverse DNS (PTR)', self::REQUIRED, self::PASS,
						$this->publicIp . ' → ' . $ptrName . ', forward-confirmed.');
				} elseif ($fwdOk) {
					$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Reverse DNS (PTR)', self::REQUIRED, self::FAIL,
						'PTR ' . $ptrName . ' does not forward-resolve back to ' . $this->publicIp . ' (broken FCrDNS).',
						'Forward A record of ' . $ptrName . ': ' . (empty($fwd) ? '(none)' : implode(', ', $fwd)),
						array('text' => 'Make ' . $ptrName . ' resolve (A record) to ' . $this->publicIp . ', or change the PTR.'));
				} else {
					$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Reverse DNS (PTR)', self::REQUIRED, self::UNKNOWN,
						'PTR is ' . $ptrName . ' but the forward lookup failed — try again.');
				}
				// PTR / mail-hostname alignment (recommended).
				if ($canonical !== '') {
					$out[] = (strcasecmp($ptrName, $canonical) === 0)
						? $this->r('mailhost.ptr_matches', '', 'mailhost', 'PTR matches mail hostname', self::RECOMMENDED, self::PASS,
							'Reverse DNS matches the mail hostname.')
						: $this->r('mailhost.ptr_matches', '', 'mailhost', 'PTR matches mail hostname', self::RECOMMENDED, self::WARN,
							'PTR (' . $ptrName . ') differs from the mail hostname (' . $canonical . ').',
							'Aligning HELO name and PTR improves deliverability of forwarded mail.',
							array('text' => 'If this server was provisioned through Server Manager, use the Reverse DNS panel on its '
								. 'node detail page (control plane, Overview tab) — it sets the PTR through the cloud account '
								. 'grant in one step. Otherwise set Reverse DNS for ' . $this->publicIp . ' to ' . $canonical . ' '
								. 'in your hosting provider\'s panel. Either way the provider only accepts ' . $canonical . ' '
								. 'if it already has an A record pointing to ' . $this->publicIp . '.'));
				}
			}
		}

		return $out;
	}

	/**
	 * The mail-host identity rows for a fronted deployment: the relay MX
	 * hostname's A record and the relay IP's PTR. Who can act decides the
	 * severity — a fleet slot's records are OPERATOR-published (INFO, no
	 * tenant fix); a self-hosted relay's zone is the tenant's own (REQUIRED,
	 * with the fix).
	 */
	private function frontedMailHostResults() {
		$out = array();
		$t = $this->topology();
		$is_fleet = ($t['mode'] === 'fleet');
		$host = $t['mx_hostname'];
		$relay_ip = $t['public_ip'];

		if ($host === '') {
			$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Relay MX hostname A record', self::REQUIRED, self::UNKNOWN,
				'The relay\'s MX hostname is not recorded yet.',
				'Reload the Setup tab once — its Relay section records the hostname from the relay\'s provisioning job — then re-check.');
			return $out;
		}

		list($aRecords, $aOk) = $this->dns(function () use ($host) { return DnsResolver::getA($host); });
		if (!$aOk) {
			$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Relay MX hostname A record', self::REQUIRED, self::UNKNOWN,
				'DNS lookup for ' . $host . ' failed — try again.');
		} elseif (empty($aRecords) || ($relay_ip !== '' && !in_array($relay_ip, $aRecords, true))) {
			$resolved = empty($aRecords) ? 'does not resolve' : 'resolves to ' . implode(', ', $aRecords);
			if ($is_fleet) {
				$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Relay MX hostname A record', self::RECOMMENDED, self::INFO,
					$host . ' ' . $resolved . ' — the fleet operator\'s DNS is not in place yet.',
					'This record is published by the fleet operator (' . $host . ' → '
					. ($relay_ip !== '' ? $relay_ip : 'the relay IP') . '); no action needed on your side.');
			} else {
				$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Relay MX hostname A record', self::REQUIRED, self::FAIL,
					$host . ' ' . $resolved . ', not the relay (' . ($relay_ip !== '' ? $relay_ip : 'IP unknown') . ').', '',
					$this->dnsFix('A', $host, $relay_ip !== '' ? $relay_ip : 'YOUR_RELAY_IP'));
			}
		} else {
			$out[] = $this->r('mailhost.a_record', '', 'mailhost', 'Relay MX hostname A record', self::REQUIRED, self::PASS,
				$host . ' resolves to the relay (' . implode(', ', $aRecords) . ').');
		}

		// Reverse DNS for the relay IP — receiving MTAs judge the relay, not the box.
		if ($relay_ip === '') {
			$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Relay reverse DNS (PTR)', self::REQUIRED, self::UNKNOWN,
				'Cannot check — the relay\'s public IP is not recorded.');
			return $out;
		}
		list($ptr, $ptrOk) = $this->dns(function () use ($relay_ip) { return DnsResolver::getPtr($relay_ip); });
		$ptrName = (!empty($ptr)) ? strtolower(rtrim((string)$ptr[0], '.')) : '';
		if (!$ptrOk) {
			$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Relay reverse DNS (PTR)', self::REQUIRED, self::UNKNOWN,
				'Reverse-DNS lookup for ' . $relay_ip . ' failed — try again.');
		} elseif ($ptrName === $host) {
			$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Relay reverse DNS (PTR)', self::REQUIRED, self::PASS,
				$relay_ip . ' → ' . $ptrName . ' — the relay names itself.');
		} elseif ($is_fleet) {
			$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Relay reverse DNS (PTR)', self::RECOMMENDED, self::INFO,
				$relay_ip . ($ptrName !== '' ? ' → ' . $ptrName : ' has no PTR record')
				. ' — expected ' . $host . '.',
				'The relay\'s reverse DNS is set by the fleet operator; no action needed on your side.');
		} else {
			$out[] = $this->r('mailhost.ptr', '', 'mailhost', 'Relay reverse DNS (PTR)', self::REQUIRED, self::FAIL,
				$relay_ip . ($ptrName !== '' ? ' → ' . $ptrName : ' has no PTR record')
				. ' — receiving servers expect ' . $host . '.',
				'Reverse DNS is set by whoever owns the relay\'s IP — the relay host\'s provider panel, not your DNS zone.',
				array('text' => 'Set Reverse DNS (RDNS) for ' . $relay_ip . ' to ' . $host
					. ' at the relay\'s hosting provider. The provider only accepts it once ' . $host
					. ' has an A record pointing to ' . $relay_ip . '.'));
		}
		return $out;
	}

	// ===================================================================
	// Per-domain DNS layer
	// ===================================================================

	private function checkDomain($domain, $isFocus) {
		$out = array();
		$domain = strtolower(trim($domain));
		$canonical = $this->mailHostname !== '' ? $this->mailHostname : 'your mail hostname';

		// Is the domain registered in the plugin?
		$model = InboundEmailDomain::GetByDomain($domain);
		if ($model && $model->get('ied_is_enabled')) {
			$out[] = $this->r('domain.row', $domain, 'domain', 'Domain registered in the plugin', self::REQUIRED, self::PASS,
				$domain . ' is registered and enabled.');
		} elseif ($model) {
			$out[] = $this->r('domain.row', $domain, 'domain', 'Domain registered in the plugin', self::REQUIRED, self::FAIL,
				$domain . ' is registered but disabled.', '',
				array('text' => 'Enable ' . $domain . ' on the Domains tab.'));
		} else {
			$out[] = $this->r('domain.row', $domain, 'domain', 'Domain registered in the plugin', self::REQUIRED, self::FAIL,
				$domain . ' is not registered as an inbound domain yet.', '',
				array('text' => 'Add ' . $domain . ' with the "Add this domain" button below, or on the Domains tab.',
				      'action' => array('action' => 'add_domain', 'domain' => $domain)));
		}

		// MX — one check covering all four conditions: an MX record exists,
		// its target is not a CNAME, the target resolves, and the target's A
		// record points to the topology's MX host (the relay when fronted,
		// this server when colocated). The first unmet condition wins.
		$fronted = $this->fronted();
		$mx_prescribed = $fronted ? $this->topology()['mx_hostname'] : $canonical;
		list($mx, $mxOk) = $this->dns(function () use ($domain) { return DnsResolver::getMx($domain); });
		if ($fronted && $mx_prescribed === '') {
			$out[] = $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				'The relay\'s MX hostname is not recorded yet, so there is no target to verify against.',
				'Reload the Setup tab once — its Relay section records the hostname from the relay\'s provisioning job — then re-check.');
		} elseif (!$mxOk) {
			$out[] = $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				'DNS lookup for ' . $domain . ' MX failed — try again.');
		} elseif (empty($mx)) {
			$out[] = $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$domain . ' has no MX record.', '',
				$this->dnsFix('MX', $domain, $mx_prescribed, 10));
		} elseif ($fronted) {
			$out[] = $this->frontedMxResult($domain, strtolower(rtrim($mx[0]['host'], '.')), $mx[0]['pri']);
		} else {
			$out[] = $this->mxResult($domain, strtolower(rtrim($mx[0]['host'], '.')), $mx[0]['pri'], $canonical);
		}

		// Ownership proof (hosted fleet only): the fleet accepts no mail for a
		// domain until its owner proves control by publishing a TXT code. The
		// row behaves like every other DNS row — publish the record, the check
		// goes green (challenges are filed and re-verified automatically).
		if ($this->topology()['mode'] === 'fleet') {
			$out[] = $this->ownershipResult($domain);
		}

		// A protected sending identity (specs/mailbox_outbound_send_protection.md)
		// inverts the correct DNS shape: SPF must NOT authorize the box, DMARC
		// must be strict (p=reject; aspf=s; adkim=s), the DKIM record must match
		// the in-app sealed key's public half (not opendkim's on-disk key), the
		// forwarding subdomain's SPF must authorize the box, and the domain must
		// not be relay-provider-verified. Non-protected domains keep the ambient
		// shape below.
		//
		// The security level is the single branching key
		// (specs/mailbox_security_levels.md § Setup/health branching): a Fortress
		// domain expects the protected shape from the moment the level is chosen —
		// before the verify-gated protect ceremony flips ied_is_protected_identity —
		// so the Setup tab guides the operator to publish the inverted records and
		// reads as incomplete until the ceremony verifies.
		$protected = ($model && ($model->is_protected_identity()
			|| $model->security_level() === InboundEmailDomain::LEVEL_FORTRESS));

		// SPF — fetch the domain's TXT once; both branches read it.
		list($txt, $txtOk) = $this->dns(function () use ($domain) { return DnsResolver::getTxt($domain); });
		$spf = $txtOk ? $this->extractSpf($txt) : '';

		if ($protected) {
			foreach ($this->protectedShapeResults($model, $domain, $txtOk, $spf) as $r) {
				$out[] = $r;
			}
		} else {
			$plan = $this->spfPlan($domain);
			if (!$txtOk) {
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::UNKNOWN,
					'DNS TXT lookup for ' . $domain . ' failed — try again.');
			} elseif ($plan['prescribe'] === 'switch_provider') {
				// No record can both authorize this outbound path and keep the
				// box's address out of DNS — the fix is the outbound path itself.
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
					'Sent mail leaves from this server itself (' . $plan['label'] . ') — no SPF record can '
					. 'authorize that without publishing the address the relay hides.',
					'Under a relay, outbound mail must ride a provider with its own sending range.',
					array('text' => 'Switch the outbound email provider on the Settings page to an API provider '
						. 'with its own sending range (e.g. Mailgun or SES), then re-check.'));
			} elseif ($plan['prescribe'] === 'unknown') {
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::UNKNOWN,
					'Could not determine ' . $plan['label'] . '\'s SPF mechanism for ' . $domain
					. ' — its API did not answer. Try again.');
			} elseif ($spf === '') {
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
					$domain . ' has no SPF (v=spf1) record.', '', $this->dnsFix('TXT', $domain, $plan['value']));
			} else {
				$out[] = $this->spfResult($domain, $spf, $plan);
			}

			// DKIM — rows per signing path this domain's mail actually rides
			// (specs/mailbox_provider_dkim.md): local opendkim when mail leaves
			// through local Postfix, the outbound provider's own records when
			// composed mail rides its API, an honest gap under the relay
			// smarthost. A locally generated key is never prescribed for a path
			// it doesn't sign.
			$dkim_plan = $this->dkimPlan();
			if ($dkim_plan['smarthost']) {
				$out[] = $this->r('domain.dkim', $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::WARN,
					'Sent mail leaves through the relay smarthost without a DKIM signature.',
					'Deliverability rides on SPF alone. Switching the relay outbound mode to '
					. 'provider (with an API provider) restores DKIM signing.');
			}
			if ($dkim_plan['local']) {
				// Local opendkim signs what this box submits itself. One check
				// covering both that a signing key exists on this server and
				// that the matching record is published in DNS; the first unmet
				// condition wins.
				$keyFile = '/etc/opendkim/keys/' . $domain . '/mail.txt';
				$localKey = $this->readDkimKey($keyFile);
				if ($localKey === '') {
					$out[] = $this->r('domain.dkim', $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::WARN,
						'No DKIM key has been generated for ' . $domain . ' on this server.',
						'Forwarding works without DKIM; generating a key improves outbound deliverability. '
						. 'The command below generates the key, wires opendkim, and prints the DNS record to publish.',
						$this->dkimFix($domain));
				} else {
					$out[] = $this->dkimResult($domain, $localKey);
				}
			}
			if ($dkim_plan['provider']) {
				$id_base = $dkim_plan['local'] ? 'domain.dkim_provider' : 'domain.dkim';
				foreach ($this->providerDkimRows($dkim_plan['class'], $dkim_plan['label'], $domain, $id_base) as $r) {
					$out[] = $r;
				}
			}

			// DMARC
			list($dmarcTxt, $dmarcOk) = $this->dns(function () use ($domain) {
				return DnsResolver::getTxt('_dmarc.' . $domain);
			});
			if (!$dmarcOk) {
				$out[] = $this->r('domain.dmarc', $domain, 'domain', 'DMARC record', self::RECOMMENDED, self::UNKNOWN,
					'DNS lookup for _dmarc.' . $domain . ' failed — try again.');
			} else {
				$hasDmarc = false;
				foreach ($dmarcTxt as $t) { if (stripos($t, 'v=DMARC1') === 0) { $hasDmarc = true; break; } }
				$out[] = $hasDmarc
					? $this->r('domain.dmarc', $domain, 'domain', 'DMARC record', self::RECOMMENDED, self::PASS,
						'A DMARC record is published.')
					: $this->r('domain.dmarc', $domain, 'domain', 'DMARC record', self::RECOMMENDED, self::WARN,
						$domain . ' has no DMARC record.',
						'DMARC is optional but recommended once SPF and DKIM pass.',
						$this->dnsFix('TXT', '_dmarc.' . $domain, 'v=DMARC1; p=none; rua=mailto:postmaster@' . $domain));
			}
		}

		// Unsealed mail on a protected domain (specs/mailbox_protection_ceremony.md
		// § 3): the ceremony seals the backlog at raise time and the mutation-point
		// refusals keep every mailbox sealable, so this row firing means protection
		// silently degraded (a vault deleted after the raise, a legacy state) — the
		// one outcome the ceremony exists to end, so it must be loud.
		if ($model && $model->seals_content()) {
			$unsealed = 0;
			try {
				$db = DbConnector::get_instance()->get_db_link();
				$stmt = $db->prepare(
					"SELECT COUNT(*) FROM iem_inbound_email_messages
					 WHERE iem_ied_inbound_email_domain_id = ?
					   AND iem_content_sealed = false AND iem_delete_time IS NULL");
				$stmt->execute(array(intval($model->key)));
				$unsealed = intval($stmt->fetchColumn());
			} catch (\Throwable $e) {
				// Count unavailable — say nothing rather than fabricate a verdict.
				$unsealed = -1;
			}
			if ($unsealed > 0) {
				$out[] = $this->r('domain.sealed_backlog', $domain, 'domain', 'Mail sealed at rest',
					self::REQUIRED, self::FAIL,
					$unsealed . ' message(s) on this protected domain are stored unsealed.',
					'Every message on a Private or Fortress domain should be sealed to its mailbox '
					. 'owner\'s vault. Unsealed rows mean the owner\'s vault is missing or was deleted '
					. 'after the level was raised.',
					array('text' => 'Open the domain editor — the sealing pass resumes automatically once '
						. 'every mailbox owner has a vault.'));
			} elseif ($unsealed === 0) {
				$out[] = $this->r('domain.sealed_backlog', $domain, 'domain', 'Mail sealed at rest',
					self::REQUIRED, self::PASS,
					'Every stored message on this protected domain is sealed.');
			}
		}

		// mydestination conflict
		$md = array();
		exec('postconf -h mydestination 2>/dev/null', $md);
		$mdLine = implode(' ', $md);
		$inMyDest = false;
		foreach (preg_split('/[\s,]+/', $mdLine) as $tok) {
			if (strcasecmp(trim($tok), $domain) === 0) { $inMyDest = true; break; }
		}
		$out[] = $inMyDest
			? $this->r('domain.mydestination', $domain, 'domain', 'No mydestination conflict', self::REQUIRED, self::FAIL,
				$domain . ' is in Postfix mydestination — this outranks virtual delivery and breaks inbound mail.',
				'', $this->installerFix())
			: $this->r('domain.mydestination', $domain, 'domain', 'No mydestination conflict', self::REQUIRED, self::PASS,
				$domain . ' is not in Postfix mydestination.');

		return $out;
	}

	// ===================================================================
	// Plugin-config layer
	// ===================================================================

	private function checkPlugin() {
		$out = array();

		$enabled = (string)$this->settings->get_setting('mailbox_enabled');
		$out[] = ($enabled === '1')
			? $this->r('plugin.enabled', '', 'plugin', 'Inbound email enabled', self::REQUIRED, self::PASS,
				'The mailbox_enabled master switch is on.')
			: $this->r('plugin.enabled', '', 'plugin', 'Inbound email enabled', self::REQUIRED, self::FAIL,
				'The mailbox_enabled master switch is off — inbound mail is accepted but not processed.',
				'', array('text' => 'Turn it on with the "Enable inbound email" button below, or in Settings.',
				          'action' => array('action' => 'enable_plugin')));

		$srsOn = ((string)$this->settings->get_setting('mailbox_srs_enabled') === '1');
		$srsSecret = trim((string)$this->settings->get_setting('mailbox_srs_secret'));
		$srsLabel = 'SRS (Sender Rewriting Scheme)';
		$srsWhat = 'SRS rewrites the envelope sender of forwarded mail so it still passes SPF '
			. 'at the final destination — without it, forwarded mail is often rejected or marked as spam.';
		if (!$srsOn) {
			$out[] = $this->r('plugin.srs_secret', '', 'plugin', $srsLabel, self::RECOMMENDED, self::WARN,
				'SRS is turned off.',
				$srsWhat . ' Enabling it is recommended whenever the forwarding feature is used.',
				array('text' => 'Press the button below — it turns SRS on and generates a signing secret in one step.',
				      'action' => array('action' => 'enable_srs', 'label' => 'Enable SRS')));
		} elseif ($srsSecret === '') {
			$out[] = $this->r('plugin.srs_secret', '', 'plugin', $srsLabel, self::REQUIRED, self::FAIL,
				'SRS is on but no signing secret is set — SRS addresses cannot be signed.',
				$srsWhat,
				array('text' => 'Press the button below to generate the SRS signing secret.',
				      'action' => array('action' => 'enable_srs', 'label' => 'Generate SRS secret')));
		} else {
			$out[] = $this->r('plugin.srs_secret', '', 'plugin', $srsLabel, self::REQUIRED, self::PASS,
				'SRS is on and a signing secret is set.');
		}

		// Outbound relay reachability — verifies the resolved relay (the active
		// provider's credential when provider-relay is active, else the SMTP
		// relay), so a healthy provider API key reads PASS even with empty smtp_*.
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
			InboundEmailHealth::checkForwardingRelay();
			$relay = (new InboundEmailRouter())->describeRelay();
			$summary = ($relay['mode'] === 'provider')
				? 'Forwarding relays through ' . $relay['label'] . ', reusing its credential.'
				: 'The outbound SMTP relay is reachable.';
			$out[] = $this->r('plugin.relay', '', 'plugin', 'Outbound forwarding relay', self::REQUIRED, self::PASS,
				$summary);
		} catch (\Throwable $e) {
			$out[] = $this->r('plugin.relay', '', 'plugin', 'Outbound forwarding relay', self::REQUIRED, self::FAIL,
				'The outbound forwarding relay could not be verified.', $e->getMessage(),
				array('text' => 'Check the active email provider credential, or the forwarding SMTP relay settings, on the Settings page.'));
		}

		// Cutover progress: relays are born enabled, so this row reports how
		// far the DNS move has come (and flags the one bad state — cutover
		// complete while the relay sits emergency-disabled).
		if ($this->fronted()) {
			$out[] = $this->relayEnableResult();
		}

		return $out;
	}

	/**
	 * Whether DNS has fully cut over to the relay: every enabled, non-IMAP
	 * hosted domain's MX names the relay MX hostname and, on a fleet slot,
	 * every ownership proof is verified. THE shared evaluation — the
	 * cutover-completion row and the listener-decommission guardrails
	 * (specs/mailbox_listener_decommission.md) both read it; there is no
	 * second copy.
	 *
	 * @return array{complete:bool,reason:string} reason names the first
	 *         blocker ('' when complete).
	 */
	public function relayCutoverState(): array {
		$t = $this->topology();
		$expected = $t['mx_hostname'];
		$is_fleet = ($t['mode'] === 'fleet');
		if ($expected === '') {
			return $this->recordCutover(array('complete' => false, 'reason' => 'The relay has no MX hostname recorded yet.'));
		}

		$domains = array();
		try {
			$multi = new MultiInboundEmailDomain(array('deleted' => false, 'enabled' => true), array('ied_domain' => 'ASC'));
			$multi->load();
			foreach ($multi as $d) {
				if (!(bool)$d->get('ied_is_imap_source')) {
					$domains[] = strtolower(trim((string)$d->get('ied_domain')));
				}
			}
		} catch (\Throwable $e) {
			return $this->recordCutover(array('complete' => false, 'reason' => 'The hosted domains could not be read.'));
		}
		if (empty($domains)) {
			return $this->recordCutover(array('complete' => false, 'reason' => 'No enabled hosted domain is registered yet.'));
		}

		foreach ($domains as $domain) {
			list($mx, $ok) = $this->dns(function () use ($domain) { return DnsResolver::getMx($domain); });
			if (!$ok) {
				return $this->recordCutover(array('complete' => false, 'reason' => 'The MX lookup for ' . $domain . ' failed.'));
			}
			if (empty($mx) || strtolower(rtrim($mx[0]['host'], '.')) !== $expected) {
				return $this->recordCutover(array('complete' => false, 'reason'
					=> $domain . '\'s MX does not point at the relay (' . $expected . ') yet.'));
			}
			if ($is_fleet) {
				$claim = $this->fleetClaimFor($domain);
				if ($claim === null || (string)($claim['status'] ?? '') !== 'verified') {
					return $this->recordCutover(array('complete' => false, 'reason'
						=> $domain . '\'s ownership proof is not published yet.'));
				}
			}
		}
		return $this->recordCutover(array('complete' => true, 'reason' => ''));
	}

	/**
	 * Persist the computed cutover verdict (mailbox_relay_cutover_complete) so
	 * per-send and per-check consumers — outbound doctrine enforcement, the
	 * origin-hidden health check — can read it without DNS lookups. Recorded
	 * on every evaluation; check passes and Setup page loads keep it fresh.
	 */
	private function recordCutover(array $state): array {
		try {
			require_once(PathHelper::getIncludePath('data/settings_class.php'));
			$value = $state['complete'] ? '1' : '0';
			$existing = new MultiSetting(array('setting_name' => 'mailbox_relay_cutover_complete'));
			$existing->load();
			$setting = count($existing) ? $existing->get(0) : null;
			if ($setting === null || (string)$setting->get('stg_value') !== $value) {
				$was_allowed = SystemBase::$allow_get_mutation;
				SystemBase::$allow_get_mutation = true;
				try {
					if ($setting === null) {
						$setting = new Setting(NULL);
						$setting->set('stg_name', 'mailbox_relay_cutover_complete');
					}
					$setting->set('stg_value', $value);
					$setting->save();
				} finally {
					SystemBase::$allow_get_mutation = $was_allowed;
				}
			}
		} catch (\Throwable $e) {
			error_log('InboundEmailSetupCheck::recordCutover failed: ' . $e->getMessage());
		}
		return $state;
	}

	/**
	 * The 'plugin.relay_enable' row — cutover progress. Relays are born
	 * enabled, so the normal life is: INFO with the first incomplete reason
	 * while DNS moves, PASS once every enabled hosted domain's MX points at
	 * the relay (and, on a fleet slot, every ownership proof is published).
	 * The one bad state: cutover complete while the relay sits disabled (the
	 * emergency stop was left on) — mail arrives with no consumer, REQUIRED
	 * FAIL.
	 */
	private function relayEnableResult() {
		$t = $this->topology();
		$expected = $t['mx_hostname'];
		$is_fleet = ($t['mode'] === 'fleet');
		$state = $this->relayCutoverState();

		if ($state['complete'] && !$t['enabled']) {
			return $this->r('plugin.relay_enable', '', 'plugin', 'Relay cutover', self::REQUIRED, self::FAIL,
				'DNS is cut over but the relay is disabled — mail is arriving at the relay with no consumer.',
				'Every hosted domain\'s MX points at ' . $expected
				. ($is_fleet ? ' and every ownership proof is published.' : '.'),
				array('text' => 'Re-enable the relay in the Relay section above.'));
		}
		if ($state['complete']) {
			return $this->r('plugin.relay_enable', '', 'plugin', 'Relay cutover', self::REQUIRED, self::PASS,
				'DNS is cut over — the relay fronts every hosted domain.');
		}
		if (!$t['enabled']) {
			return $this->r('plugin.relay_enable', '', 'plugin', 'Relay cutover', self::RECOMMENDED, self::INFO,
				'The relay is disabled, and the DNS cutover is not finished: ' . $state['reason'],
				'Re-enable the relay in the Relay section above; it should stay enabled through the cutover.');
		}
		return $this->r('plugin.relay_enable', '', 'plugin', 'Relay cutover', self::RECOMMENDED, self::INFO,
			'The DNS cutover is not finished yet: ' . $state['reason'],
			'The relay is running and ready — mail moves to it as each domain\'s MX'
			. ($is_fleet ? ' and ownership rows' : ' rows') . ' go green.');
	}

	// ===================================================================
	// Address-scoped checks (alias + end-to-end)
	// ===================================================================

	private function checkAddress($address) {
		$out = array();
		$address = strtolower(trim($address));

		$alias = InboundEmailAlias::GetByAddress($address);
		$catchAll = false;
		$catchAllMode = 'forward';
		$parts = explode('@', $address, 2);
		if (count($parts) === 2) {
			$domain = InboundEmailDomain::GetByDomain($parts[1]);
			if ($domain) {
				$catchAllMode = $domain->get('ied_catch_all_mode') ?: 'forward';
				if ($catchAllMode === 'store') {
					// Store catch-all captures every recipient on the domain.
					$catchAll = true;
				} elseif ($domain->get('ied_catch_all_address')) {
					$catchAll = true;
				}
			}
		}
		if ($alias) {
			$mode = $alias->get('iea_delivery_mode') ?: 'forward';
			$label = ($mode === 'store') ? ' (store-only)'
				: (($mode === 'forward_and_store') ? ' (forward + store)' : '');
			$out[] = $this->r('address.alias', $address, 'address', 'Delivery target exists', self::REQUIRED, self::PASS,
				$address . ' resolves to an enabled alias' . $label . '.');
		} elseif ($catchAll) {
			$label = ($catchAllMode === 'store') ? ' (store catch-all)' : '';
			$out[] = $this->r('address.alias', $address, 'address', 'Delivery target exists', self::REQUIRED, self::PASS,
				$address . ' is covered by the domain catch-all' . $label . '.');
		} else {
			$out[] = $this->r('address.alias', $address, 'address', 'Delivery target exists', self::REQUIRED, self::FAIL,
				$address . ' has no alias and the domain has no catch-all — mail to it would be rejected.',
				'', array('text' => 'Create an alias for ' . $address . ' on the Forwarding Aliases tab.'));
		}

		// End-to-end: has a real inbound message for this address been logged?
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$stmt = $db->prepare(
				'SELECT iel_create_time, iel_status FROM iel_inbound_email_logs '
				. 'WHERE lower(iel_to_address) = ? ORDER BY iel_create_time DESC LIMIT 1');
			$stmt->execute(array($address));
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row) {
				$out[] = $this->r('e2e.test_message', $address, 'e2e', 'End-to-end delivery', self::REQUIRED, self::PASS,
					'Inbound mail for ' . $address . ' has been received and logged (last: '
					. $row['iel_create_time'] . ', status ' . $row['iel_status'] . ').', '', null, true);
			} else {
				$out[] = $this->r('e2e.test_message', $address, 'e2e', 'End-to-end delivery', self::REQUIRED, self::WARN,
					'No inbound message for ' . $address . ' has been logged yet.',
					'This is the only real proof that inbound port 25 is reachable from the internet.',
					array('text' => 'Send a test email to ' . $address
						. ' from any external account, then press Re-check.'), true);
			}
		} catch (\Throwable $e) {
			$out[] = $this->r('e2e.test_message', $address, 'e2e', 'End-to-end delivery', self::REQUIRED, self::UNKNOWN,
				'Could not read the inbound email log.', $e->getMessage());
		}

		return $out;
	}

	// ===================================================================
	// Helpers
	// ===================================================================

	private function r($id, $scope, $layer, $label, $severity, $status, $summary, $detail = '', $fix = null, $recheckable = true) {
		return array(
			'id' => $id, 'scope' => $scope, 'layer' => $layer, 'label' => $label,
			'severity' => $severity, 'status' => $status, 'summary' => $summary,
			'detail' => $detail, 'fix' => $fix, 'recheckable' => $recheckable,
		);
	}

	/** Run a DnsResolver call; returns [value, true] or [null, false] on resolver failure. */
	private function dns(callable $fn) {
		try {
			return array($fn(), true);
		} catch (DnsLookupException $e) {
			return array(null, false);
		}
	}

	/** Build a pass/fail/unknown result comparing a set of resolved IPs to the server's public IP. */
	private function ipMatchResult($id, $scope, $layer, $label, array $ips, $what) {
		if ($this->publicIp === '') {
			return $this->r($id, $scope, $layer, $label, self::REQUIRED, self::UNKNOWN,
				'Cannot check — the server public IP could not be determined.');
		}
		if (in_array($this->publicIp, $ips, true)) {
			$note = $this->publicIpIsPrivate
				? 'Note: ' . $this->publicIp . ' is a private address — set mailbox_public_ip to the real public IP if this server is behind NAT.'
				: '';
			return $this->r($id, $scope, $layer, $label, self::REQUIRED,
				$this->publicIpIsPrivate ? self::WARN : self::PASS,
				ucfirst($what) . ' points to this server (' . $this->publicIp . ').', $note);
		}
		return $this->r($id, $scope, $layer, $label, self::REQUIRED, self::FAIL,
			ucfirst($what) . ' resolves to ' . implode(', ', $ips) . ', not this server (' . $this->publicIp . ').',
			'', array('text' => 'Point it at ' . $this->publicIp . '.'));
	}

	/**
	 * Evaluate an MX target through the CNAME / resolves / points-here chain
	 * and return a single 'domain.mx' result. Called when the domain has an MX;
	 * the first unmet condition determines the status.
	 *
	 * The suggested fix depends on who owns the MX target: a target the
	 * operator controls (this deployment's mail host, or any name under the
	 * domain itself) is fixed by correcting its A record; a third-party target
	 * (a previous mail provider) is fixed by changing the domain's MX to this
	 * deployment's mail host — its A record is not ours to point.
	 */
	private function mxResult($domain, $mxTarget, $mxPri, $canonical) {
		$lead = $domain . ' MX → ' . $mxTarget . ' (priority ' . $mxPri . ')';
		$owned_target = ($mxTarget === $canonical)
			|| ($mxTarget === $domain)
			|| (substr($mxTarget, -strlen('.' . $domain)) === '.' . $domain);
		$mx_swap_fix = $this->dnsFix('MX', $domain, $canonical, 10);
		$mx_swap_fix['text'] = 'Replace the MX record at your DNS provider (and remove the old one).';

		// MX target must not be a CNAME (RFC 2181).
		list($cname, $cnameOk) = $this->dns(function () use ($mxTarget) { return DnsResolver::getCname($mxTarget); });
		if (!$cnameOk) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				$lead . '. DNS lookup for ' . $mxTarget . ' failed — try again.');
		}
		if ($cname !== null) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ', but ' . $mxTarget . ' is a CNAME (' . $cname . ') — RFC 2181 forbids an MX target that is a CNAME.',
				'', $owned_target
					? array('text' => 'Point the MX at a hostname that has its own A record, not a CNAME.')
					: $mx_swap_fix);
		}

		// MX target must resolve, and resolve to this server.
		list($mxA, $mxAOk) = $this->dns(function () use ($mxTarget) { return DnsResolver::getA($mxTarget); });
		if (!$mxAOk) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				$lead . '. DNS lookup for ' . $mxTarget . ' failed — try again.');
		}
		if (empty($mxA)) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ', but ' . $mxTarget . ' has no A record.', '',
				$owned_target
					? $this->dnsFix('A', $mxTarget, $this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP')
					: $mx_swap_fix);
		}
		if ($this->publicIp === '') {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				$lead . ' → ' . implode(', ', $mxA) . '. Cannot confirm it points here — the server public IP could not be determined.');
		}
		if (!in_array($this->publicIp, $mxA, true)) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ' → ' . implode(', ', $mxA) . ', not this server (' . $this->publicIp . ')'
				. ($owned_target ? '.' : ' — mail is still being delivered to the old provider.'), '',
				$owned_target ? $this->dnsFix('A', $mxTarget, $this->publicIp) : $mx_swap_fix);
		}
		if ($this->publicIpIsPrivate) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::WARN,
				$lead . ' → ' . $this->publicIp . ', this server.',
				'Note: ' . $this->publicIp . ' is a private address — set mailbox_public_ip to the real public IP if this server is behind NAT.');
		}
		return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::PASS,
			$lead . ' → ' . $this->publicIp . ' — a plain hostname resolving to this server.');
	}

	/**
	 * MX evaluation under a fronted topology: the target must string-equal the
	 * relay MX hostname AND that name must resolve to the relay's public IP.
	 * The owned-target heuristic does not apply — for a fleet slot the hostname
	 * lives in the operator's zone, so exact match is the only correct target.
	 */
	private function frontedMxResult($domain, $mxTarget, $mxPri) {
		$t = $this->topology();
		$expected = $t['mx_hostname'];
		$relay_ip = $t['public_ip'];
		$lead = $domain . ' MX → ' . $mxTarget . ' (priority ' . $mxPri . ')';
		$fix = $this->dnsFix('MX', $domain, $expected, 10);
		$fix['text'] = 'Replace the MX record at your DNS provider (and remove the old one).';

		if ($mxTarget !== $expected) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ', not the relay (' . $expected . ') — mail is still being delivered to the old target.',
				'', $fix);
		}
		list($mxA, $mxAOk) = $this->dns(function () use ($mxTarget) { return DnsResolver::getA($mxTarget); });
		if (!$mxAOk) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				$lead . '. DNS lookup for ' . $mxTarget . ' failed — try again.');
		}
		$resolves = !empty($mxA) && ($relay_ip === '' || in_array($relay_ip, $mxA, true));
		if (!$resolves) {
			$resolved = empty($mxA) ? 'does not resolve' : 'resolves to ' . implode(', ', $mxA);
			if ($t['mode'] === 'fleet') {
				// The A record behind the MX target is operator-published; the
				// tenant's half (the MX record itself) is already correct.
				return $this->r('domain.mx', $domain, 'domain', 'MX record', self::RECOMMENDED, self::INFO,
					$lead . ' — correct target, but it ' . $resolved
					. '. The fleet operator\'s A record is not in place yet; no action needed on your side.');
			}
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ' — correct target, but it ' . $resolved
				. ', not the relay (' . ($relay_ip !== '' ? $relay_ip : 'IP unknown') . ').', '',
				$this->dnsFix('A', $mxTarget, $relay_ip !== '' ? $relay_ip : 'YOUR_RELAY_IP'));
		}
		return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::PASS,
			$lead . ' → ' . ($relay_ip !== '' ? $relay_ip : implode(', ', $mxA)) . ' — the relay.');
	}

	/**
	 * The 'domain.ownership' row (hosted fleet only): publish-the-record proof
	 * of domain control, with no user-facing claim/verify vocabulary — the
	 * challenge is filed automatically and re-verified on every check pass
	 * (specs/mailbox_setup_topology_aware.md Decision 4).
	 */
	private function ownershipResult($domain) {
		$label = 'Domain ownership';
		$state = $this->fleetState();
		if (!$state['ok']) {
			return $this->r('domain.ownership', $domain, 'domain', $label, self::REQUIRED, self::UNKNOWN,
				'Could not reach the fleet service to check the ownership proof.',
				$state['error']);
		}
		if (!$state['enrolled']) {
			return $this->r('domain.ownership', $domain, 'domain', $label, self::REQUIRED, self::FAIL,
				'This deployment no longer holds a fleet slot, so the relay accepts no mail for ' . $domain . '.',
				'', array('text' => 'Re-enroll in the Setup tab\'s Relay section.'));
		}
		$claim = $this->fleetClaimFor($domain);
		if ($claim === null || (string)($claim['status'] ?? '') === 'error') {
			return $this->r('domain.ownership', $domain, 'domain', $label, self::REQUIRED, self::UNKNOWN,
				'Could not obtain the ownership record for ' . $domain . ' from the fleet service.',
				(string)($claim['error'] ?? ''));
		}
		if ((string)$claim['status'] === 'verified') {
			return $this->r('domain.ownership', $domain, 'domain', $label, self::REQUIRED, self::PASS,
				'Ownership proven.');
		}
		return $this->r('domain.ownership', $domain, 'domain', $label, self::REQUIRED, self::FAIL,
			'Prove you own ' . $domain . ': publish this record at your DNS provider.',
			'The relay accepts no mail for this domain until this record is published. '
			. 'It is re-checked automatically on every pass — publish it and re-check.',
			$this->dnsFix('TXT', (string)$claim['txt_host'], (string)$claim['txt_value']));
	}

	/**
	 * Evaluate an existing SPF record against the topology's plan and return a
	 * single 'domain.spf' result. Colocated: the record must authorize this
	 * server and carry the outbound mechanism. Fronted: the record must carry
	 * the plan's mechanism and must NOT authorize this server — a record still
	 * naming the box republishes the address the relay hides.
	 */
	private function spfResult($domain, $spf, array $plan) {
		$fix = $this->dnsFix('TXT', $domain, $plan['value']);

		if ($this->fronted()) {
			if ($this->publicIp !== '') {
				$boxEval = $this->spfAuthorizes($spf, $domain);
				if ($boxEval === 'pass') {
					return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
						'SPF authorizes this server (' . $this->publicIp . ') — it publishes the address the relay hides.',
						'Record: ' . $spf, $fix);
				}
			}
			if ($plan['mechanism'] !== '' && !$this->spfCoversMechanism($spf, $plan['mechanism'])) {
				return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::WARN,
					'SPF does not authorize the outbound path (' . $plan['label'] . ') — sent mail will fail SPF.',
					'Record: ' . $spf, $fix);
			}
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::PASS,
				'SPF authorizes the outbound path (' . $plan['label'] . ') and does not name this server.');
		}

		if ($this->publicIp === '') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::UNKNOWN,
				'SPF record present, but cannot confirm it authorizes this server — the public IP could not be determined.');
		}
		$spfEval = $this->spfAuthorizes($spf, $domain);
		if ($spfEval === 'pass') {
			// The server is authorized — but mail also leaves through the
			// outbound provider, so a record that omits its mechanism still
			// produces SPF failures at recipients.
			if ($plan['mechanism'] !== '' && !$this->spfCoversMechanism($spf, $plan['mechanism'])) {
				return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::WARN,
					'SPF authorizes this server (' . $this->publicIp . '), but not the outbound provider ('
					. $plan['label'] . ') — mail sent through it will fail SPF.',
					'Record: ' . $spf, $fix);
			}
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::PASS,
				'SPF record present; authorizes ' . $this->publicIp
				. ($plan['mechanism'] !== '' ? ' and the ' . $plan['label'] . ' path.' : '.'));
		}
		if ($spfEval === 'unverified') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::WARN,
				'SPF record present, but its policy could not be fully verified (a DNS lookup failed or the 10-lookup limit was hit).',
				'Record: ' . $spf,
				array('text' => 'Confirm the policy covers ' . $this->publicIp . ', or add ip4:' . $this->publicIp . ' directly.'));
		}
		return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
			'SPF record present, but it does not authorize ' . $this->publicIp . ' (include: policies expanded).',
			'Record: ' . $spf, $fix);
	}

	/**
	 * Evaluate DKIM for a domain that already has a local signing key: is the
	 * matching record published in DNS and does it match. Returns a single
	 * 'domain.dkim' result.
	 */
	private function dkimResult($domain, $localKey) {
		$rrName = 'mail._domainkey.' . $domain;
		list($dkimTxt, $dkimOk) = $this->dns(function () use ($rrName) {
			return DnsResolver::getTxt($rrName);
		});
		if (!$dkimOk) {
			return $this->r('domain.dkim', $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::UNKNOWN,
				'A DKIM key exists on this server, but the DNS lookup for ' . $rrName . ' failed — try again.');
		}
		$published = '';
		foreach ($dkimTxt as $t) {
			if (stripos($t, 'v=DKIM1') !== false || strpos($t, 'p=') !== false) { $published .= $t; }
		}
		$pubP = $this->extractDkimP($published);
		if ($pubP === '') {
			return $this->r('domain.dkim', $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::WARN,
				'A DKIM key exists on this server, but no record is published at ' . $rrName . '.', '',
				$this->dnsFix('TXT', $rrName, $localKey));
		}
		if ($pubP === $this->extractDkimP($localKey)) {
			return $this->r('domain.dkim', $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::PASS,
				'A DKIM key exists on this server and the published record matches it.');
		}
		return $this->r('domain.dkim', $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::WARN,
			'A DKIM key exists on this server, but the published record does not match the local key.', '',
			$this->dnsFix('TXT', $rrName, $localKey));
	}

	// ===================================================================
	// Protected sending identity (specs/mailbox_outbound_send_protection.md)
	// ===================================================================

	/**
	 * Run just the protected-identity DNS checks for a domain model, regardless
	 * of whether its ied_is_protected_identity flag is set yet. The enable
	 * ceremony calls this to verify the published DNS shape BEFORE flipping the
	 * flag (publish → verify → activate), so the flag never turns on against an
	 * unpublished record. Returns the same result rows checkDomain emits.
	 *
	 * @return array result rows
	 */
	public function protectedDomainChecks(InboundEmailDomain $model): array {
		$domain = strtolower(trim((string)$model->get('ied_domain')));
		list($txt, $txtOk) = $this->dns(function () use ($domain) { return DnsResolver::getTxt($domain); });
		$spf = $txtOk ? $this->extractSpf($txt) : '';
		return $this->protectedShapeResults($model, $domain, $txtOk, $spf);
	}

	/** The first v=spf1 record in a TXT record set, or ''. */
	private function extractSpf(array $txt) {
		foreach ($txt as $t) {
			if (stripos($t, 'v=spf1') === 0) { return $t; }
		}
		return '';
	}

	/**
	 * The one assembly of the protected-identity shape — checkDomain (for an
	 * already-enforced domain) and protectedDomainChecks (the ceremony's
	 * pre-activation verify) both emit exactly this list, so the ceremony can
	 * never pass a shape the Setup tab would fail, or vice versa.
	 */
	private function protectedShapeResults(InboundEmailDomain $model, $domain, $txtOk, $spf) {
		list($dmarcTxt, $dmarcOk) = $this->dns(function () use ($domain) {
			return DnsResolver::getTxt('_dmarc.' . $domain);
		});
		return array(
			$this->protectedSpfResult($domain, $txtOk, $spf),
			$this->providerVerificationResult($domain, $txtOk, $spf),
			$this->forwardingSubdomainSpfResult($model),
			$this->forwardingSubdomainMxResult($model),
			$this->protectedDkimResult($domain, $model),
			$this->protectedDmarcResult($domain, $dmarcOk, $dmarcOk ? $dmarcTxt : array()),
		);
	}

	/**
	 * SPF for a protected domain — INVERTED: the correct shape is one that does
	 * NOT authorize the box. PASS when SPF excludes the box (or there is no SPF),
	 * FAIL when it authorizes the box. REQUIRED: a box-authorizing SPF hands an
	 * attacker an aligned ambient send path with no key at all.
	 */
	private function protectedSpfResult($domain, $txtOk, $spf) {
		$suggested = 'v=spf1 -all';
		if (!$txtOk) {
			return $this->r('domain.spf', $domain, 'domain', 'SPF excludes this server', self::REQUIRED, self::UNKNOWN,
				'DNS TXT lookup for ' . $domain . ' failed — try again.');
		}
		if ($spf === '') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF excludes this server', self::REQUIRED, self::PASS,
				'No SPF record authorizes this server for ' . $domain . '.',
				'Publishing an explicit ' . $suggested . ' makes the exclusion unambiguous.',
				$this->dnsFix('TXT', $domain, $suggested));
		}
		$eval = $this->spfAuthorizes($spf, $domain);
		if ($eval === 'pass') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF excludes this server', self::REQUIRED, self::FAIL,
				'SPF authorizes this server (' . $this->publicIp . ') — a protected identity must exclude it, so a locked box cannot send SPF-aligned mail.',
				'Record: ' . $spf,
				$this->dnsFix('TXT', $domain, $suggested));
		}
		if ($eval === 'unverified') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF excludes this server', self::REQUIRED, self::WARN,
				'SPF policy could not be fully verified — could not confirm the box (' . $this->publicIp . ') is excluded.',
				'Record: ' . $spf,
				array('text' => 'Confirm no included policy authorizes ' . $this->publicIp . '; the tightest shape is ' . $suggested . '.'));
		}
		return $this->r('domain.spf', $domain, 'domain', 'SPF excludes this server', self::REQUIRED, self::PASS,
			'SPF does not authorize this server — correct for a protected identity.');
	}

	/**
	 * Closure 4: the protected domain must not be a relay-provider-verified
	 * sending domain. The DNS-visible proxy is a provider include in the SPF —
	 * a resting relay API key that could send for the domain is a resting send
	 * capability, exactly like a resting DKIM key.
	 */
	private function providerVerificationResult($domain, $txtOk, $spf) {
		if (!$txtOk) {
			return $this->r('domain.provider', $domain, 'domain', 'No relay provider authorized', self::REQUIRED, self::UNKNOWN,
				'DNS TXT lookup for ' . $domain . ' failed — try again.');
		}
		$provider_hosts = array('mailgun.org', 'sendgrid.net', 'amazonses.com', 'spf.protection.outlook.com',
			'_spf.google.com', 'sparkpostmail.com', 'mailchimp.com', 'servers.mcsv.net', 'sendinblue.com', 'postmarkapp.com');
		$found = '';
		if ($spf !== '') {
			foreach (preg_split('/\s+/', trim($spf)) as $term) {
				$term = ltrim($term, '+');
				if (stripos($term, 'include:') !== 0 && stripos($term, 'redirect=') !== 0) {
					continue;
				}
				$host = strtolower(substr($term, strpos($term, ':') !== false ? strpos($term, ':') + 1 : strpos($term, '=') + 1));
				foreach ($provider_hosts as $ph) {
					if (strpos($host, $ph) !== false) { $found = $host; break 2; }
				}
			}
		}
		if ($found !== '') {
			return $this->r('domain.provider', $domain, 'domain', 'No relay provider authorized', self::REQUIRED, self::FAIL,
				'SPF authorizes a relay provider (' . $found . ') — a protected identity must not be provider-verified, or a resting API key can send as the domain.',
				'Record: ' . $spf,
				array('text' => 'Remove the provider include from ' . $domain . '\'s SPF and de-verify the domain at the provider. Automated mail belongs on a separate sending subdomain.'));
		}
		return $this->r('domain.provider', $domain, 'domain', 'No relay provider authorized', self::REQUIRED, self::PASS,
			'No relay provider is authorized in ' . $domain . '\'s SPF.');
	}

	/**
	 * The forwarding subdomain's SPF must authorize whatever forwards the mail:
	 * this box when colocated, the RELAY when a relay/fleet slot fronts the
	 * deployment (forwards leave direct from its IP). Alias forwarding runs
	 * while locked and needs an SPF-passing envelope for bounce routing. Under
	 * the bare domain's aspf=s this subdomain can never align the identity, so
	 * it adds no spoofing capability.
	 */
	private function forwardingSubdomainSpfResult($model) {
		$domain = (string)$model->get('ied_domain');
		$sub = $model->forwarding_subdomain();
		if ($sub === '' || strcasecmp($sub, $domain) === 0) {
			// REQUIRED: without a dedicated subdomain the SRS envelope falls
			// back to the bare domain, whose protected SPF is v=spf1 -all —
			// every alias-forwarded message would hard-fail SPF at the
			// destination. Activation must block on this.
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::FAIL,
				'No dedicated forwarding subdomain is configured for ' . $domain . '.',
				'A protected domain routes its SRS forwarding envelope through a subdomain (e.g. fwd.' . $domain . ') so forwarding and bounces keep working while locked.',
				array('text' => 'Set the forwarding subdomain on the Protect page.'));
		}

		// Who forwards decides the authorized sender.
		$fronted = $this->fronted();
		if ($fronted) {
			$t = $this->topology();
			$sender_ip = $t['public_ip'];
			$sender_label = 'the relay';
			$fwd_mech = '';
			$spfValue = 'v=spf1 ip4:' . ($sender_ip !== '' ? $sender_ip : 'YOUR_RELAY_IP') . ' -all';
		} else {
			$sender_ip = $this->publicIp;
			$sender_label = 'this server';
			$relay = $this->relayInfo();
			$fwd_mech = $this->providerMechanism($relay['provider_class'], $domain);
			$spfValue = 'v=spf1 ip4:' . ($sender_ip !== '' ? $sender_ip : 'YOUR_SERVER_IP')
				. ($fwd_mech !== '' ? ' ' . $fwd_mech : '') . ' -all';
		}

		list($txt, $ok) = $this->dns(function () use ($sub) { return DnsResolver::getTxt($sub); });
		if (!$ok) {
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::UNKNOWN,
				'DNS TXT lookup for ' . $sub . ' failed — try again.');
		}
		$spf = '';
		foreach ($txt as $t) { if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; } }
		if ($spf === '') {
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::FAIL,
				$sub . ' has no SPF record — forwarded mail will fail SPF at the destination.', '',
				$this->dnsFix('TXT', $sub, $spfValue));
		}
		if ($sender_ip === '') {
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::UNKNOWN,
				$sub . ' has an SPF record, but the forwarding sender\'s IP could not be determined.');
		}
		$eval = $this->spfAuthorizes($spf, $sub, $sender_ip);
		if ($eval === 'pass') {
			// Colocated forwards also leave through the outbound relay
			// provider, so its mechanism is part of the subdomain's shape too.
			if ($fwd_mech !== '' && !$this->spfCoversMechanism($spf, $fwd_mech)) {
				return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::WARN,
					$sub . ' SPF authorizes ' . $sender_label . ', but not the outbound relay ('
					. $this->relayInfo()['label'] . ') — forwarded mail sent through it will fail SPF.',
					'Record: ' . $spf,
					$this->dnsFix('TXT', $sub, $spfValue));
			}
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::PASS,
				$sub . ' SPF authorizes ' . $sender_label . ' (' . $sender_ip . ').');
		}
		if ($eval === 'unverified') {
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::WARN,
				$sub . ' SPF policy could not be fully verified — could not confirm it authorizes ' . $sender_label
				. ' (' . $sender_ip . ').', 'Record: ' . $spf,
				array('text' => 'Confirm the policy covers ' . $sender_ip . ', or add ip4:' . $sender_ip . ' directly.'));
		}
		return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::FAIL,
			$sub . ' SPF does not authorize ' . $sender_label . ' (' . $sender_ip . ').', 'Record: ' . $spf,
			$this->dnsFix('TXT', $sub, $spfValue));
	}

	/**
	 * The forwarding subdomain must RECEIVE mail, not just send it: remote DSNs
	 * are addressed to the SRS envelope (SRS0=...@<subdomain>), so without an MX
	 * pointing back at this deployment's MX host — the relay when fronted, this
	 * box when colocated — bounce handling silently dies and original senders
	 * never learn their mail failed. REQUIRED for the protected shape.
	 */
	private function forwardingSubdomainMxResult($model) {
		$domain = (string)$model->get('ied_domain');
		$sub = $model->forwarding_subdomain();
		$fronted = $this->fronted();
		$t = $this->topology();
		$mxTarget = $fronted
			? ($t['mx_hostname'] !== '' ? $t['mx_hostname'] : 'YOUR_RELAY_MX_HOST')
			: ($this->mailHostname !== '' ? $this->mailHostname : 'YOUR_MAIL_HOST');
		$expect_ip = $fronted ? $t['public_ip'] : $this->publicIp;
		$expect_label = $fronted ? 'the relay' : 'this server';
		if ($sub === '' || strcasecmp($sub, $domain) === 0) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::FAIL,
				'No dedicated forwarding subdomain is configured for ' . $domain . '.', '',
				array('text' => 'Set the forwarding subdomain on the Protect page.'));
		}
		list($mx, $mxOk) = $this->dns(function () use ($sub) { return DnsResolver::getMx($sub); });
		if (!$mxOk) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::UNKNOWN,
				'DNS MX lookup for ' . $sub . ' failed — try again.');
		}
		if (empty($mx)) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::FAIL,
				$sub . ' has no MX record — delivery-failure notices sent to the SRS envelope cannot reach ' . $expect_label . '.', '',
				$this->dnsFix('MX', $sub, $mxTarget, 10));
		}
		$host = strtolower(rtrim($mx[0]['host'], '.'));
		list($mxA, $mxAOk) = $this->dns(function () use ($host) { return DnsResolver::getA($host); });
		if (!$mxAOk) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::UNKNOWN,
				$sub . ' MX → ' . $host . '. DNS lookup for ' . $host . ' failed — try again.');
		}
		if ($expect_ip === '') {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::UNKNOWN,
				$sub . ' MX → ' . $host . '. Cannot confirm it points at ' . $expect_label . ' — its IP could not be determined.');
		}
		if (!in_array($expect_ip, $mxA, true)) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::FAIL,
				$sub . ' MX → ' . $host . ' → ' . implode(', ', $mxA) . ', not ' . $expect_label . ' (' . $expect_ip . ').', '',
				$this->dnsFix('MX', $sub, $mxTarget, 10));
		}
		return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::PASS,
			$sub . ' MX → ' . $host . ' → ' . $expect_label . '; SRS bounces route back to the router.');
	}

	/**
	 * DKIM for a protected domain: the published selector record must match the
	 * in-app sealed key's public half (ied_dkim_public_dns), NOT opendkim's
	 * on-disk key (which is removed at cutover). REQUIRED — the signature is the
	 * only path to DMARC acceptance for a protected identity.
	 */
	private function protectedDkimResult($domain, $model) {
		$selector = trim((string)$model->get('ied_dkim_selector'));
		$expected = trim((string)$model->get('ied_dkim_public_dns'));
		if ($selector === '' || $expected === '') {
			return $this->r('domain.dkim', $domain, 'domain', 'DKIM record (in-app key)', self::REQUIRED, self::FAIL,
				'Protection is enabled but no in-app DKIM key is provisioned for ' . $domain . '.',
				'Run the Enable protection ceremony from the Domains tab to generate and seal the key.');
		}
		return $this->dkimRecordResult($domain, $selector, $expected, 'domain.dkim', 'DKIM record (in-app key)');
	}

	/**
	 * The staged-rotation gate: PASS only when the PENDING selector's published
	 * DNS record matches the pending sealed key's public half. The rotation
	 * ceremony refuses to cut over (pending → live) until this passes, so the
	 * live, DNS-proven key keeps signing throughout.
	 */
	public function pendingDkimResult(InboundEmailDomain $model): array {
		$domain = strtolower(trim((string)$model->get('ied_domain')));
		$selector = trim((string)$model->get('ied_dkim_pending_selector'));
		$expected = trim((string)$model->get('ied_dkim_pending_public_dns'));
		if ($selector === '' || $expected === '') {
			return $this->r('domain.dkim_pending', $domain, 'domain', 'DKIM record (pending rotation key)', self::REQUIRED, self::FAIL,
				'No rotation is staged for ' . $domain . '.');
		}
		return $this->dkimRecordResult($domain, $selector, $expected, 'domain.dkim_pending', 'DKIM record (pending rotation key)');
	}

	/** Match one published DKIM selector record against an expected value. */
	private function dkimRecordResult($domain, $selector, $expected, $checkId, $label) {
		$rrName = $selector . '._domainkey.' . $domain;
		list($dkimTxt, $ok) = $this->dns(function () use ($rrName) { return DnsResolver::getTxt($rrName); });
		if (!$ok) {
			return $this->r($checkId, $domain, 'domain', $label, self::REQUIRED, self::UNKNOWN,
				'DNS lookup for ' . $rrName . ' failed — try again.');
		}
		$published = '';
		foreach ($dkimTxt as $t) {
			if (stripos($t, 'v=DKIM1') !== false || strpos($t, 'p=') !== false) { $published .= $t; }
		}
		$pubP = $this->extractDkimP($published);
		if ($pubP === '') {
			return $this->r($checkId, $domain, 'domain', $label, self::REQUIRED, self::FAIL,
				'No DKIM record is published at ' . $rrName . '.', '',
				$this->dnsFix('TXT', $rrName, $expected));
		}
		if ($pubP === $this->extractDkimP($expected)) {
			return $this->r($checkId, $domain, 'domain', $label, self::REQUIRED, self::PASS,
				'The published DKIM record matches the sealed key at selector ' . $selector . '.');
		}
		return $this->r($checkId, $domain, 'domain', $label, self::REQUIRED, self::FAIL,
			'The published DKIM record at ' . $rrName . ' does not match the sealed key.', '',
			$this->dnsFix('TXT', $rrName, $expected));
	}

	/**
	 * DMARC for a protected domain: parse and REQUIRE p=reject; aspf=s; adkim=s.
	 * Strict alignment is load-bearing — with relaxed alignment the forwarding
	 * subdomain's box-authorizing SPF would align the bare domain and hand the
	 * ambient capability right back.
	 */
	private function protectedDmarcResult($domain, $ok, $dmarcTxt) {
		$strict = 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@' . $domain;
		if (!$ok) {
			return $this->r('domain.dmarc', $domain, 'domain', 'Strict DMARC (p=reject; aspf=s; adkim=s)', self::REQUIRED, self::UNKNOWN,
				'DNS lookup for _dmarc.' . $domain . ' failed — try again.');
		}
		$record = '';
		foreach ($dmarcTxt as $t) { if (stripos($t, 'v=DMARC1') === 0) { $record = $t; break; } }
		if ($record === '') {
			return $this->r('domain.dmarc', $domain, 'domain', 'Strict DMARC (p=reject; aspf=s; adkim=s)', self::REQUIRED, self::FAIL,
				$domain . ' has no DMARC record — a protected identity requires a strict policy.', '',
				$this->dnsFix('TXT', '_dmarc.' . $domain, $strict));
		}
		$tags = array();
		foreach (explode(';', $record) as $pair) {
			$pair = trim($pair);
			if ($pair === '' || strpos($pair, '=') === false) { continue; }
			list($k, $v) = explode('=', $pair, 2);
			$tags[strtolower(trim($k))] = strtolower(trim($v));
		}
		$problems = array();
		if (($tags['p'] ?? '') !== 'reject') { $problems[] = 'p must be reject (is "' . ($tags['p'] ?? '(absent)') . '")'; }
		if (($tags['aspf'] ?? '') !== 's')   { $problems[] = 'aspf must be s (is "' . ($tags['aspf'] ?? '(absent)') . '")'; }
		if (($tags['adkim'] ?? '') !== 's')  { $problems[] = 'adkim must be s (is "' . ($tags['adkim'] ?? '(absent)') . '")'; }
		if (empty($problems)) {
			return $this->r('domain.dmarc', $domain, 'domain', 'Strict DMARC (p=reject; aspf=s; adkim=s)', self::REQUIRED, self::PASS,
				$domain . ' publishes a strict DMARC policy (p=reject; aspf=s; adkim=s).');
		}
		return $this->r('domain.dmarc', $domain, 'domain', 'Strict DMARC (p=reject; aspf=s; adkim=s)', self::REQUIRED, self::FAIL,
			$domain . ' DMARC is not strict enough: ' . implode('; ', $problems) . '.',
			'Record: ' . $record,
			$this->dnsFix('TXT', '_dmarc.' . $domain, $strict));
	}

	/**
	 * Evaluate the joinery pipe transport: it must exist, run an existing
	 * inbound_email handler script, and use an executable php binary. Returns
	 * a single 'host.transport' result; the first unmet condition wins.
	 */
	private function transportResult($transportLine) {
		$label = 'Joinery pipe transport';
		if ($transportLine === '') {
			return $this->r('host.transport', '', 'host', $label, self::REQUIRED, self::FAIL,
				'The joinery pipe transport is missing from master.cf.', '', $this->installerFix());
		}
		$argvPhp = '';
		$argvScript = '';
		if (preg_match('/argv=(\S+)\s+(\S+)/', $transportLine, $m)) {
			$argvPhp = $m[1];
			$argvScript = $m[2];
		}
		$suffix = 'plugins/mailbox/utils/inbound_email_handler.php';
		if ($argvScript === '') {
			return $this->r('host.transport', '', 'host', $label, self::REQUIRED, self::FAIL,
				'The joinery pipe transport has no runnable command (argv) in master.cf.',
				'Found: ' . $transportLine, $this->installerFix());
		}
		if (substr($argvScript, -strlen($suffix)) !== $suffix) {
			return $this->r('host.transport', '', 'host', $label, self::REQUIRED, self::FAIL,
				'The joinery pipe transport runs the wrong handler — inbound mail is piped to a path '
				. 'that is not this plugin (often a stale path left by a plugin rename).',
				'Runs: ' . $argvScript, $this->installerFix());
		}
		if (!is_file($argvScript)) {
			return $this->r('host.transport', '', 'host', $label, self::REQUIRED, self::FAIL,
				'The joinery pipe transport points at a handler script that does not exist — '
				. 'every inbound message bounces.',
				'Missing: ' . $argvScript, $this->installerFix());
		}
		if ($argvPhp !== '' && !is_executable($argvPhp)) {
			return $this->r('host.transport', '', 'host', $label, self::REQUIRED, self::FAIL,
				'The joinery pipe transport runs a php binary that is not executable here.',
				'php: ' . $argvPhp, $this->installerFix());
		}
		return $this->r('host.transport', '', 'host', $label, self::REQUIRED, self::PASS,
			'The joinery pipe transport runs the inbound email handler.');
	}

	private function installerFix() {
		return array(
			'text' => 'Run the base mail installer on the server as root.',
			'command' => 'sudo bash ' . PathHelper::getIncludePath('plugins/mailbox/provisioning/install_email.sh'),
		);
	}

	private function hostnameFix() {
		$target = $this->mailHostname !== '' ? $this->mailHostname : 'mail.example.com';
		return array(
			'text' => 'Set the Postfix HELO hostname on the server as root.',
			'command' => 'sudo postconf -e "myhostname=' . $target . '" && sudo postfix reload',
		);
	}

	private function dkimFix($domain) {
		return array(
			'text' => 'Generate a DKIM signing key for ' . $domain . ' on the server as root.',
			'command' => 'sudo bash '
				. PathHelper::getIncludePath('plugins/mailbox/provisioning/provision_dkim.sh')
				. ' ' . $domain,
		);
	}

	/**
	 * Describe the resolved outbound relay once per run:
	 * ['mode', 'label', 'provider_class'] from InboundEmailRouter::describeRelay(),
	 * or all-empty values when the router cannot be built.
	 */
	private function relayInfo() {
		if ($this->relay_info === null) {
			$this->relay_info = array('mode' => '', 'label' => '', 'provider_class' => '');
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
				$this->router = new InboundEmailRouter();
				$this->relay_info = $this->router->describeRelay();
			} catch (\Throwable $e) {
				// Relay unresolvable — the plugin.relay check reports that; SPF
				// prescriptions just omit the mechanism.
			}
		}
		return $this->relay_info;
	}

	/** The active outbound provider's class (email_service setting), or null. */
	private function activeProviderClass(): ?string {
		try {
			require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			$service = (string)$this->settings->get_setting('email_service') ?: 'mailgun';
			return EmailSender::getDiscoveredProviders()[$service] ?? null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * A provider class's SPF mechanism for a domain, cached per (class, domain)
	 * for the run — per-account providers answer from their API, so each pair
	 * is fetched once.
	 */
	private function providerMechanism(?string $class, string $domain): string {
		if ($class === null || $class === '') {
			return '';
		}
		$key = $class . '|' . $domain;
		if (!array_key_exists($key, $this->spf_mechanisms)) {
			$mech = '';
			try {
				$mech = trim((string)$class::getSpfMechanism($domain));
			} catch (\Throwable $e) {
				error_log('InboundEmailSetupCheck: getSpfMechanism(' . $class . ', ' . $domain . ') failed: '
					. $e->getMessage());
			}
			$this->spf_mechanisms[$key] = $mech;
		}
		return $this->spf_mechanisms[$key];
	}

	/**
	 * The SPF this deployment prescribes for a sending domain, by topology
	 * (specs/mailbox_setup_topology_aware.md Decision 5). Returns:
	 *   'prescribe' — 'record' (value is copy-ready), 'switch_provider' (no
	 *                 record can both work and hide the origin), or 'unknown'
	 *                 (the provider's API did not answer).
	 *   'value'     — the copy-ready record ('' unless prescribe = record).
	 *   'mechanism' — the outbound mechanism(s) a published record must cover.
	 *   'label'     — what carries the mail, for row text.
	 *
	 * Colocated: the box's own IP plus every outbound mechanism (forwarding
	 * relay + compose provider). Fronted, provider outbound: the provider's
	 * mechanism alone — the box IP is exactly what the relay hides. Fronted,
	 * smarthost outbound: the relay's IP.
	 */
	private function spfPlan(string $domain): array {
		if (!$this->fronted()) {
			$terms = array('v=spf1', 'ip4:' . ($this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP'));
			$mechanisms = array();
			$relay = $this->relayInfo();
			$label = $relay['label'];
			foreach (array($relay['provider_class'], $this->activeProviderClass()) as $class) {
				$mech = $this->providerMechanism($class, $domain);
				foreach ($mech === '' ? array() : preg_split('/\s+/', $mech) as $term) {
					if (!in_array($term, $mechanisms, true)) { $mechanisms[] = $term; }
				}
			}
			$value = implode(' ', array_merge($terms, $mechanisms, array('-all')));
			return array('prescribe' => 'record', 'value' => $value,
				'mechanism' => implode(' ', $mechanisms), 'label' => $label);
		}

		$t = $this->topology();
		$outbound_mode = (strtolower(trim((string)$this->settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
			? 'smarthost' : 'provider';
		if ($outbound_mode === 'smarthost') {
			$mech = 'ip4:' . ($t['public_ip'] !== '' ? $t['public_ip'] : 'YOUR_RELAY_IP');
			return array('prescribe' => 'record', 'value' => 'v=spf1 ' . $mech . ' -all',
				'mechanism' => $mech, 'label' => 'the relay smarthost');
		}

		$class = $this->activeProviderClass();
		$label = ($class !== null) ? $class::getLabel() : 'the configured provider';
		$is_api = ($class !== null)
			&& in_array('ApiSubmissionRelay', class_implements($class) ?: array(), true);
		if (!$is_api) {
			// Compose under a fronted topology refuses non-API providers (the
			// origin would leak) — the SPF fix IS switching providers.
			return array('prescribe' => 'switch_provider', 'value' => '', 'mechanism' => '', 'label' => $label);
		}
		$mech = $this->providerMechanism($class, $domain);
		if ($mech === '') {
			return array('prescribe' => 'unknown', 'value' => '', 'mechanism' => '', 'label' => $label);
		}
		return array('prescribe' => 'record', 'value' => 'v=spf1 ' . $mech . ' -all',
			'mechanism' => $mech, 'label' => $label);
	}

	/**
	 * Which DKIM signing paths carry a domain's mail, by topology
	 * (specs/mailbox_provider_dkim.md). Returns:
	 *   'local'     — local opendkim signs what this box submits itself
	 *                 (colocated only; a fronted deployment's compose never
	 *                 rides local Postfix).
	 *   'provider'  — composed mail rides the outbound provider's API, so the
	 *                 correct record is the one the PROVIDER issues.
	 *   'smarthost' — fronted with the relay smarthost carrying sends, which
	 *                 sign nothing (honest-gap row).
	 *   'class'     — the provider class when it can report its records
	 *                 (DkimRecordSource), else null (generic guidance).
	 *   'label'     — the provider's display label.
	 */
	private function dkimPlan(): array {
		$class = $this->activeProviderClass();
		$label = ($class !== null) ? $class::getLabel() : 'the configured provider';
		$source = ($class !== null)
			&& in_array('DkimRecordSource', class_implements($class) ?: array(), true);

		if (!$this->fronted()) {
			return array('local' => true, 'smarthost' => false,
				'provider' => $source, 'class' => $source ? $class : null, 'label' => $label);
		}

		$smarthost = strtolower(trim((string)$this->settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost';
		if ($smarthost) {
			return array('local' => false, 'smarthost' => true,
				'provider' => false, 'class' => null, 'label' => $label);
		}
		return array('local' => false, 'smarthost' => false,
			'provider' => true, 'class' => $source ? $class : null, 'label' => $label);
	}

	/**
	 * The DKIM rows for a domain whose mail is provider-signed: one row per
	 * record the provider requires, each verified against live DNS. A provider
	 * without the DkimRecordSource capability ($class null) gets one generic
	 * row naming it — never a prescription for a local opendkim key.
	 */
	private function providerDkimRows(?string $class, string $label, string $domain, string $id_base): array {
		if ($class === null) {
			return array($this->r($id_base, $domain, 'domain', 'DKIM record', self::RECOMMENDED, self::WARN,
				'Sent mail is DKIM-signed by ' . $label . ' — publish the DKIM record it issues for ' . $domain . '.',
				$label . ' does not report its required records to this deployment; find them in its dashboard.',
				array('text' => 'Publish the DKIM record ' . $label . ' provides for ' . $domain
					. ' at your DNS provider, then re-check.')));
		}

		$status = $this->providerDkimStatus($class, $domain);
		if ($status['status'] === 'unreachable') {
			return array($this->r($id_base, $domain, 'domain', 'DKIM record (' . $label . ')', self::RECOMMENDED, self::UNKNOWN,
				'Could not determine ' . $label . '\'s DKIM records for ' . $domain . ' — its API did not answer. Try again.'));
		}
		if ($status['status'] === 'not_registered') {
			return array($this->r($id_base, $domain, 'domain', 'DKIM record (' . $label . ')', self::RECOMMENDED, self::WARN,
				$domain . ' is not registered as a sending domain at ' . $label
				. ' — mail sent from ' . $domain . ' addresses is signed as another domain and fails DMARC alignment.',
				'',
				array('text' => 'Add ' . $domain . ' as a sending domain in the ' . $label
					. ' dashboard, publish the records it provides, then re-check.')));
		}
		if (empty($status['records'])) {
			return array($this->r($id_base, $domain, 'domain', 'DKIM record (' . $label . ')', self::RECOMMENDED, self::PASS,
				$label . ' reports DKIM configured for ' . $domain . ' with no records left to publish.'));
		}

		$rows = array();
		$i = 0;
		foreach ($status['records'] as $rec) {
			$rows[] = $this->providerDkimRecordRow($id_base . ($i > 0 ? '.' . $i : ''), $label, $domain, $rec);
			$i++;
		}
		return $rows;
	}

	/** Verify one provider-required DKIM record (TXT by p= body, CNAME by target) against live DNS. */
	private function providerDkimRecordRow(string $id, string $label, string $domain, array $rec): array {
		$type = strtoupper(trim((string)($rec['type'] ?? 'TXT')));
		$name = rtrim(trim((string)($rec['name'] ?? '')), '.');
		$value = trim((string)($rec['value'] ?? ''));
		$row_label = 'DKIM record (' . $label . ')';

		if ($type === 'CNAME') {
			list($target, $ok) = $this->dns(function () use ($name) { return DnsResolver::getCname($name); });
			if (!$ok) {
				return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::UNKNOWN,
					'The DNS lookup for ' . $name . ' failed — try again.');
			}
			if ($target === null || $target === '') {
				return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::WARN,
					$label . ' requires a DKIM record at ' . $name . ' that is not published yet.', '',
					$this->dnsFix('CNAME', $name, $value));
			}
			if (strcasecmp(rtrim((string)$target, '.'), rtrim($value, '.')) === 0) {
				return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::PASS,
					'The ' . $label . ' DKIM record at ' . $name . ' is published.');
			}
			return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::WARN,
				'The record at ' . $name . ' does not match what ' . $label . ' requires.',
				'Published: ' . $target, $this->dnsFix('CNAME', $name, $value));
		}

		list($txt, $ok) = $this->dns(function () use ($name) { return DnsResolver::getTxt($name); });
		if (!$ok) {
			return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::UNKNOWN,
				'The DNS lookup for ' . $name . ' failed — try again.');
		}
		$published = '';
		foreach ($txt as $t) {
			if (stripos($t, 'v=DKIM1') !== false || strpos($t, 'p=') !== false) { $published .= $t; }
		}
		if ($published === '') {
			return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::WARN,
				$label . ' requires a DKIM record at ' . $name . ' that is not published yet.', '',
				$this->dnsFix('TXT', $name, $value));
		}
		$pubP = $this->extractDkimP($published);
		$wantP = $this->extractDkimP($value);
		$match = ($wantP !== '') ? ($pubP === $wantP)
			: (preg_replace('/\s+/', '', $published) === preg_replace('/\s+/', '', $value));
		if ($match) {
			return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::PASS,
				'The ' . $label . ' DKIM record at ' . $name . ' is published and matches.');
		}
		return $this->r($id, $domain, 'domain', $row_label, self::RECOMMENDED, self::WARN,
			'The record at ' . $name . ' does not match what ' . $label . ' requires.',
			'Published: ' . $published, $this->dnsFix('TXT', $name, $value));
	}

	/**
	 * A provider class's DKIM status for a domain, cached per (class, domain)
	 * for the run — the answer comes from the provider's API.
	 */
	private function providerDkimStatus(string $class, string $domain): array {
		$key = $class . '|' . $domain;
		if (!array_key_exists($key, $this->dkim_statuses)) {
			$status = array('status' => 'unreachable', 'records' => array());
			try {
				$status = $class::getDkimStatus($domain);
			} catch (\Throwable $e) {
				error_log('InboundEmailSetupCheck: getDkimStatus(' . $class . ', ' . $domain . ') failed: '
					. $e->getMessage());
			}
			$this->dkim_statuses[$key] = $status;
		}
		return $this->dkim_statuses[$key];
	}

	/**
	 * Whether a published SPF policy covers every term of a prescribed
	 * mechanism ('include:x', 'a:host', 'ip4:...', possibly several
	 * space-separated), expanding the include:/redirect= chain within the
	 * shared 10-lookup budget.
	 */
	private function spfCoversMechanism($spf, $mechanism) {
		foreach (preg_split('/\s+/', trim($mechanism)) as $needed) {
			if ($needed === '') { continue; }
			$lookups = 0;
			if (!$this->spfChainHasTerm($spf, $needed, $lookups)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an SPF policy (or anything its include:/redirect= chain reaches,
	 * within the shared 10-lookup budget) carries $needed as a term. An
	 * include:/redirect= term also matches a needed include: by target, so
	 * 'include:mailgun.org' is found whether it appears directly or as the
	 * redirect target of an intermediate policy.
	 */
	private function spfChainHasTerm($spf, $needed, &$lookups) {
		$needed_norm = strtolower(ltrim(rtrim($needed, '.'), '+'));
		$needed_target = '';
		if (stripos($needed_norm, 'include:') === 0) { $needed_target = substr($needed_norm, 8); }
		foreach (preg_split('/\s+/', trim($spf)) as $term) {
			$term = ltrim($term, '+');
			if ($term === '' || $term[0] === '-' || $term[0] === '~' || $term[0] === '?') { continue; }
			$term_norm = strtolower(rtrim($term, '.'));
			if ($term_norm === $needed_norm) { return true; }
			$target = '';
			if (stripos($term, 'include:') === 0) { $target = substr($term, 8); }
			elseif (stripos($term, 'redirect=') === 0) { $target = substr($term, 9); }
			if ($target === '') { continue; }
			if ($needed_target !== '' && strcasecmp(rtrim($target, '.'), $needed_target) === 0) { return true; }
			if (++$lookups > 10) { continue; }
			list($txt, $ok) = $this->dns(function () use ($target) { return DnsResolver::getTxt($target); });
			if (!$ok) { continue; }
			$sub_spf = $this->extractSpf($txt);
			if ($sub_spf !== '' && $this->spfChainHasTerm($sub_spf, $needed, $lookups)) { return true; }
		}
		return false;
	}

	private function dnsFix($type, $name, $value, $priority = null) {
		$record = array('type' => $type, 'name' => $name, 'value' => $value);
		if ($priority !== null) {
			// MX priority is its own field in every DNS panel — never baked
			// into the pasteable value.
			$record['priority'] = $priority;
		}
		return array(
			'text' => 'Publish this record at your DNS provider.',
			'dns_record' => $record,
		);
	}

	/** Read an opendkim mail.txt key file and return the assembled record value, or '' . */
	private function readDkimKey($file) {
		if (!is_readable($file)) { return ''; }
		$raw = @file_get_contents($file);
		if ($raw === false) { return ''; }
		// mail.txt is a BIND TXT fragment: "v=DKIM1; k=rsa; p=..." possibly split across quoted strings.
		if (preg_match_all('/"([^"]*)"/', $raw, $m) && !empty($m[1])) {
			return trim(implode('', $m[1]));
		}
		return trim($raw);
	}

	/** Extract the base64 public-key body (p=) from a DKIM record, whitespace-stripped. */
	private function extractDkimP($record) {
		if (preg_match('/p=([A-Za-z0-9+\/=\s]+)/', $record, $m)) {
			return preg_replace('/\s+/', '', $m[1]);
		}
		return '';
	}

	/**
	 * Evaluate whether an SPF record authorizes an IP (the server's public IP
	 * by default; callers pass the relay's IP to test the fronted shape),
	 * expanding include:/redirect= policies recursively within RFC 7208's
	 * 10-DNS-mechanism budget so the verdict is definitive whenever DNS
	 * cooperates. Returns 'pass', 'fail', or 'unverified' (the budget ran out
	 * or a lookup failed before a definitive answer was possible).
	 */
	private function spfAuthorizes($spf, $domain, $ip = null) {
		$ip = ($ip === null) ? $this->publicIp : $ip;
		if ($ip === '') { return 'fail'; }
		$lookups = 0;
		return $this->spfPolicyAuthorizes($spf, $domain, $ip, $lookups);
	}

	/** Recursive worker for spfAuthorizes; $lookups is the shared DNS budget. */
	private function spfPolicyAuthorizes($spf, $domain, $ip, &$lookups) {
		$unverified = false;
		foreach (preg_split('/\s+/', trim($spf)) as $term) {
			$term = ltrim($term, '+');
			if ($term === '' || stripos($term, 'v=spf1') === 0) { continue; }
			// Fail/softfail/neutral-qualified mechanisms never authorize.
			if ($term[0] === '-' || $term[0] === '~' || $term[0] === '?') { continue; }
			$sub_target = '';
			if (stripos($term, 'include:') === 0) { $sub_target = substr($term, 8); }
			elseif (stripos($term, 'redirect=') === 0) { $sub_target = substr($term, 9); }
			if ($sub_target !== '') {
				if (++$lookups > 10) { $unverified = true; continue; }
				list($txt, $ok) = $this->dns(function () use ($sub_target) { return DnsResolver::getTxt($sub_target); });
				if (!$ok) { $unverified = true; continue; }
				$sub_spf = $this->extractSpf($txt);
				if ($sub_spf === '') { continue; } // no policy there — authorizes nothing
				$sub = $this->spfPolicyAuthorizes($sub_spf, $sub_target, $ip, $lookups);
				if ($sub === 'pass') { return 'pass'; }
				if ($sub === 'unverified') { $unverified = true; }
				continue;
			}
			if (stripos($term, 'ip4:') === 0) {
				if ($this->ip4InCidr($ip, substr($term, 4))) { return 'pass'; }
			} elseif (stripos($term, 'ip6:') === 0) {
				if (strcasecmp($ip, substr($term, 4)) === 0) { return 'pass'; }
			} elseif ($term === 'a' || stripos($term, 'a:') === 0) {
				if (++$lookups > 10) { $unverified = true; continue; }
				$host = ($term === 'a') ? $domain : substr($term, 2);
				list($ips, $ok) = $this->dns(function () use ($host) { return DnsResolver::getA($host); });
				if (!$ok) { $unverified = true; continue; }
				if (in_array($ip, $ips, true)) { return 'pass'; }
			} elseif ($term === 'mx' || stripos($term, 'mx:') === 0) {
				if (++$lookups > 10) { $unverified = true; continue; }
				$host = ($term === 'mx') ? $domain : substr($term, 3);
				list($mx, $ok) = $this->dns(function () use ($host) { return DnsResolver::getMx($host); });
				if (!$ok) { $unverified = true; continue; }
				foreach ($mx as $rec) {
					list($mxips, $ok2) = $this->dns(function () use ($rec) {
						return DnsResolver::getA(rtrim($rec['host'], '.'));
					});
					if (!$ok2) { $unverified = true; continue; }
					if (in_array($ip, $mxips, true)) { return 'pass'; }
				}
			}
		}
		return $unverified ? 'unverified' : 'fail';
	}

	/** IPv4 membership test for a literal address or a CIDR block. */
	private function ip4InCidr($ip, $cidr) {
		$cidr = trim($cidr);
		if (strpos($cidr, '/') === false) {
			return $ip === $cidr;
		}
		list($subnet, $bits) = explode('/', $cidr, 2);
		$bits = (int)$bits;
		$ipL = ip2long($ip);
		$subL = ip2long(trim($subnet));
		if ($ipL === false || $subL === false || $bits < 0 || $bits > 32) { return false; }
		if ($bits === 0) { return true; }
		$mask = -1 << (32 - $bits);
		return ($ipL & $mask) === ($subL & $mask);
	}

	/** Detect the server's public IP. Returns [ip, isPrivate]. */
	private function detectPublicIp() {
		$configured = trim((string)$this->settings->get_setting('mailbox_public_ip'));
		if ($configured !== '') {
			return array($configured, $this->isPrivateIp($configured));
		}
		$ip = '';
		$sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
		if ($sock) {
			$name = @stream_socket_get_name($sock, false);
			@fclose($sock);
			if ($name && strrpos($name, ':') !== false) {
				$ip = substr($name, 0, strrpos($name, ':'));
			}
		}
		return array($ip, $ip !== '' && $this->isPrivateIp($ip));
	}

	private function isPrivateIp($ip) {
		return $ip !== ''
			&& filter_var($ip, FILTER_VALIDATE_IP) !== false
			&& filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
	}
}
