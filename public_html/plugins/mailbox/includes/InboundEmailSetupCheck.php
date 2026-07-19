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
 * @version 1.16
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

		exec('which postfix 2>/dev/null', $o1, $e1);
		$installed = ($e1 === 0);
		exec('pgrep -x master 2>/dev/null', $o2, $e2);
		$running = ($e2 === 0);
		if ($installed && $running) {
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
		$out[] = ($dkInstalled && $dkRunning)
			? $this->r('host.opendkim', '', 'host', 'opendkim (DKIM signing)', self::RECOMMENDED, self::PASS,
				'opendkim is installed and running.')
			: $this->r('host.opendkim', '', 'host', 'opendkim (DKIM signing)', self::RECOMMENDED, self::WARN,
				$dkInstalled ? 'opendkim is installed but not running.'
				             : 'opendkim is not installed — outbound DKIM signing is disabled.',
				'Forwarding still works without it; only outbound DKIM signing is affected.',
				$this->installerFix());

		$p = @stream_socket_client('tcp://127.0.0.1:25', $en, $es, 2);
		if ($p) {
			@fclose($p);
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
		// record points to this server. The first unmet condition wins.
		list($mx, $mxOk) = $this->dns(function () use ($domain) { return DnsResolver::getMx($domain); });
		if (!$mxOk) {
			$out[] = $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				'DNS lookup for ' . $domain . ' MX failed — try again.');
		} elseif (empty($mx)) {
			$out[] = $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$domain . ' has no MX record.', '',
				$this->dnsFix('MX', $domain, $canonical, 10));
		} else {
			$out[] = $this->mxResult($domain, strtolower(rtrim($mx[0]['host'], '.')), $mx[0]['pri'], $canonical);
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
			$spfValue = $this->prescribedSpf();
			if (!$txtOk) {
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::UNKNOWN,
					'DNS TXT lookup for ' . $domain . ' failed — try again.');
			} elseif ($spf === '') {
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
					$domain . ' has no SPF (v=spf1) record.', '', $this->dnsFix('TXT', $domain, $spfValue));
			} else {
				$out[] = $this->spfResult($domain, $spf, $spfValue);
			}

			// DKIM — one check covering both that a signing key exists on this
			// server and that the matching record is published in DNS. The first
			// unmet condition wins.
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

		return $out;
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
	 * Evaluate an existing SPF record and return a single 'domain.spf' result:
	 * whether it authorizes this server's public IP. Called when the domain
	 * already has a v=spf1 record.
	 */
	private function spfResult($domain, $spf, $spfValue) {
		if ($this->publicIp === '') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::UNKNOWN,
				'SPF record present, but cannot confirm it authorizes this server — the public IP could not be determined.');
		}
		$spfEval = $this->spfAuthorizes($spf, $domain);
		if ($spfEval === 'pass') {
			// The server is authorized — but mail also leaves through the
			// outbound relay, so a record that omits the relay's include still
			// produces SPF failures at recipients.
			$relay = $this->relayInfo();
			if ($relay['spf_include'] !== '') {
				$lookups = 0;
				if (!$this->spfChainIncludes($spf, $relay['spf_include'], $lookups)) {
					return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::WARN,
						'SPF authorizes this server (' . $this->publicIp . '), but not the outbound relay ('
						. $relay['label'] . ') — mail sent through it will fail SPF.',
						'Record: ' . $spf,
						$this->dnsFix('TXT', $domain, $spfValue));
				}
				return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::PASS,
					'SPF record present; authorizes ' . $this->publicIp . ' and the ' . $relay['label'] . ' relay.');
			}
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::PASS,
				'SPF record present and authorizes ' . $this->publicIp . '.');
		}
		if ($spfEval === 'unverified') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::WARN,
				'SPF record present, but its policy could not be fully verified (a DNS lookup failed or the 10-lookup limit was hit).',
				'Record: ' . $spf,
				array('text' => 'Confirm the policy covers ' . $this->publicIp . ', or add ip4:' . $this->publicIp . ' directly.'));
		}
		return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
			'SPF record present, but it does not authorize ' . $this->publicIp . ' (include: policies expanded).',
			'Record: ' . $spf,
			$this->dnsFix('TXT', $domain, $spfValue));
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
	 * The forwarding subdomain's SPF must authorize the box — alias forwarding
	 * runs while locked and needs an SPF-passing envelope for bounce routing.
	 * Under the bare domain's aspf=s this subdomain can never align the identity,
	 * so it adds no spoofing capability.
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
		$spfValue = $this->prescribedSpf();
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
		$eval = $this->spfAuthorizes($spf, $sub);
		if ($eval === 'pass') {
			// Forwarded mail leaves through the relay with an SRS envelope on
			// this subdomain, so the relay's include is part of its shape too.
			$relay = $this->relayInfo();
			if ($relay['spf_include'] !== '') {
				$lookups = 0;
				if (!$this->spfChainIncludes($spf, $relay['spf_include'], $lookups)) {
					return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::WARN,
						$sub . ' SPF authorizes this server, but not the outbound relay (' . $relay['label']
						. ') — forwarded mail sent through it will fail SPF.', 'Record: ' . $spf,
						$this->dnsFix('TXT', $sub, $spfValue));
				}
				return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::PASS,
					$sub . ' SPF authorizes this server (' . $this->publicIp . ') and the ' . $relay['label'] . ' relay.');
			}
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::PASS,
				$sub . ' SPF authorizes this server (' . $this->publicIp . ').');
		}
		if ($eval === 'unverified') {
			return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::WARN,
				$sub . ' SPF policy could not be fully verified — could not confirm it authorizes ' . $this->publicIp . '.', 'Record: ' . $spf,
				array('text' => 'Confirm the policy covers ' . $this->publicIp . ', or add ip4:' . $this->publicIp . ' directly.'));
		}
		return $this->r('domain.fwd_spf', $domain, 'domain', 'Forwarding subdomain SPF', self::REQUIRED, self::FAIL,
			$sub . ' SPF does not authorize this server (' . $this->publicIp . ').', 'Record: ' . $spf,
			$this->dnsFix('TXT', $sub, $spfValue));
	}

	/**
	 * The forwarding subdomain must RECEIVE mail, not just send it: remote DSNs
	 * are addressed to the SRS envelope (SRS0=...@<subdomain>), so without an MX
	 * pointing back at this box, bounce handling silently dies and original
	 * senders never learn their mail failed. REQUIRED for the protected shape.
	 */
	private function forwardingSubdomainMxResult($model) {
		$domain = (string)$model->get('ied_domain');
		$sub = $model->forwarding_subdomain();
		$mxTarget = $this->mailHostname !== '' ? $this->mailHostname : 'YOUR_MAIL_HOST';
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
				$sub . ' has no MX record — delivery-failure notices sent to the SRS envelope cannot reach this server.', '',
				$this->dnsFix('MX', $sub, $mxTarget, 10));
		}
		$host = strtolower(rtrim($mx[0]['host'], '.'));
		list($mxA, $mxAOk) = $this->dns(function () use ($host) { return DnsResolver::getA($host); });
		if (!$mxAOk) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::UNKNOWN,
				$sub . ' MX → ' . $host . '. DNS lookup for ' . $host . ' failed — try again.');
		}
		if ($this->publicIp === '') {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::UNKNOWN,
				$sub . ' MX → ' . $host . '. Cannot confirm it points here — the server public IP could not be determined.');
		}
		if (!in_array($this->publicIp, $mxA, true)) {
			return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::FAIL,
				$sub . ' MX → ' . $host . ' → ' . implode(', ', $mxA) . ', not this server (' . $this->publicIp . ').', '',
				$this->dnsFix('MX', $sub, $mxTarget, 10));
		}
		return $this->r('domain.fwd_mx', $domain, 'domain', 'Forwarding subdomain MX', self::REQUIRED, self::PASS,
			$sub . ' MX → ' . $host . ' → this server; SRS bounces route back to the router.');
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
	 * ['mode', 'label', 'spf_include'] from InboundEmailRouter::describeRelay(),
	 * or all-empty values when the router cannot be built.
	 */
	private function relayInfo() {
		if ($this->relay_info === null) {
			$this->relay_info = array('mode' => '', 'label' => '', 'spf_include' => '');
			try {
				require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailRouter.php'));
				$this->relay_info = (new InboundEmailRouter())->describeRelay();
			} catch (\Throwable $e) {
				// Relay unresolvable — the plugin.relay check reports that; SPF
				// prescriptions just omit the include.
			}
		}
		return $this->relay_info;
	}

	/**
	 * The SPF record this deployment prescribes for a sending domain: the
	 * server's own IP plus, when outbound rides a relay provider with a fixed
	 * SPF range, that provider's include — mail leaves through both paths.
	 */
	private function prescribedSpf() {
		$terms = array('v=spf1', 'ip4:' . ($this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP'));
		$relay = $this->relayInfo();
		if ($relay['spf_include'] !== '') {
			$terms[] = 'include:' . $relay['spf_include'];
		}
		$terms[] = '-all';
		return implode(' ', $terms);
	}

	/**
	 * Whether an SPF policy's include:/redirect= chain reaches $needle,
	 * within the shared 10-lookup budget. Used to confirm a published record
	 * covers the outbound relay provider.
	 */
	private function spfChainIncludes($spf, $needle, &$lookups) {
		foreach (preg_split('/\s+/', trim($spf)) as $term) {
			$term = ltrim($term, '+');
			if ($term === '' || $term[0] === '-' || $term[0] === '~' || $term[0] === '?') { continue; }
			$target = '';
			if (stripos($term, 'include:') === 0) { $target = substr($term, 8); }
			elseif (stripos($term, 'redirect=') === 0) { $target = substr($term, 9); }
			if ($target === '') { continue; }
			if (strcasecmp(rtrim($target, '.'), rtrim($needle, '.')) === 0) { return true; }
			if (++$lookups > 10) { continue; }
			list($txt, $ok) = $this->dns(function () use ($target) { return DnsResolver::getTxt($target); });
			if (!$ok) { continue; }
			$sub_spf = $this->extractSpf($txt);
			if ($sub_spf !== '' && $this->spfChainIncludes($sub_spf, $needle, $lookups)) { return true; }
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
	 * Evaluate whether an SPF record authorizes the server's public IP,
	 * expanding include:/redirect= policies recursively within RFC 7208's
	 * 10-DNS-mechanism budget so the verdict is definitive whenever DNS
	 * cooperates. Returns 'pass', 'fail', or 'unverified' (the budget ran out
	 * or a lookup failed before a definitive answer was possible).
	 */
	private function spfAuthorizes($spf, $domain) {
		if ($this->publicIp === '') { return 'fail'; }
		$lookups = 0;
		return $this->spfPolicyAuthorizes($spf, $domain, $lookups);
	}

	/** Recursive worker for spfAuthorizes; $lookups is the shared DNS budget. */
	private function spfPolicyAuthorizes($spf, $domain, &$lookups) {
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
				$sub = $this->spfPolicyAuthorizes($sub_spf, $sub_target, $lookups);
				if ($sub === 'pass') { return 'pass'; }
				if ($sub === 'unverified') { $unverified = true; }
				continue;
			}
			if (stripos($term, 'ip4:') === 0) {
				if ($this->ip4InCidr($this->publicIp, substr($term, 4))) { return 'pass'; }
			} elseif (stripos($term, 'ip6:') === 0) {
				if (strcasecmp($this->publicIp, substr($term, 4)) === 0) { return 'pass'; }
			} elseif ($term === 'a' || stripos($term, 'a:') === 0) {
				if (++$lookups > 10) { $unverified = true; continue; }
				$host = ($term === 'a') ? $domain : substr($term, 2);
				list($ips, $ok) = $this->dns(function () use ($host) { return DnsResolver::getA($host); });
				if (!$ok) { $unverified = true; continue; }
				if (in_array($this->publicIp, $ips, true)) { return 'pass'; }
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
					if (in_array($this->publicIp, $mxips, true)) { return 'pass'; }
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
