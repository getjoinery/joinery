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
 * @version 1.7
 */

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));

class InboundEmailSetupCheck {

	// status values
	const PASS = 'pass';
	const FAIL = 'fail';
	const WARN = 'warn';
	const UNKNOWN = 'unknown';

	// severity values
	const REQUIRED = 'required';
	const RECOMMENDED = 'recommended';

	private $settings;
	private $publicIp = '';
	private $publicIpIsPrivate = false;
	private $mailHostname = '';

	function __construct() {
		$this->settings = Globalvars::get_instance();
		$this->mailHostname = strtolower(trim((string)$this->settings->get_setting('inbound_email_mail_hostname')));
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

		foreach ($this->checkHost() as $r)     { $results[] = $r; }
		foreach ($this->checkMailHost() as $r) { $results[] = $r; }

		$domain = $domain ? strtolower(trim($domain)) : null;
		if ($domain) {
			foreach ($this->checkDomain($domain, true) as $r) { $results[] = $r; }
		} else {
			$multi = new MultiInboundEmailDomain(array('deleted' => false), array('ied_domain' => 'ASC'));
			$multi->load();
			foreach ($multi as $d) {
				foreach ($this->checkDomain($d->get('ied_domain'), false) as $r) { $results[] = $r; }
			}
		}

		foreach ($this->checkPlugin() as $r) { $results[] = $r; }

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

	/** Roll a result list up into a one-line summary: counts per status (required only for fail). */
	public static function summarize(array $results) {
		$counts = array(self::PASS => 0, self::FAIL => 0, self::WARN => 0, self::UNKNOWN => 0);
		foreach ($results as $r) { $counts[$r['status']]++; }
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
							array('text' => 'In the Linode Cloud Manager, open the Linode for this server, go to the Network tab, '
								. 'and set Reverse DNS (RDNS) for ' . $this->publicIp . ' to ' . $canonical . '. '
								. 'Linode only accepts ' . $canonical . ' if it already has an A record pointing to ' . $this->publicIp . '.'));
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
				$this->dnsFix('MX', $domain, '10 ' . $canonical));
		} else {
			$out[] = $this->mxResult($domain, strtolower(rtrim($mx[0]['host'], '.')), $mx[0]['pri']);
		}

		// SPF — one check covering both that a v=spf1 record exists and that it
		// authorizes this server. The first unmet condition wins.
		list($txt, $txtOk) = $this->dns(function () use ($domain) { return DnsResolver::getTxt($domain); });
		$spfValue = 'v=spf1 ip4:' . ($this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP') . ' -all';
		if (!$txtOk) {
			$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::UNKNOWN,
				'DNS TXT lookup for ' . $domain . ' failed — try again.');
		} else {
			$spf = '';
			foreach ($txt as $t) {
				if (stripos($t, 'v=spf1') === 0) { $spf = $t; break; }
			}
			if ($spf === '') {
				$out[] = $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
					$domain . ' has no SPF (v=spf1) record.', '', $this->dnsFix('TXT', $domain, $spfValue));
			} else {
				$out[] = $this->spfResult($domain, $spf, $spfValue);
			}
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

		$enabled = (string)$this->settings->get_setting('inbound_email_enabled');
		$out[] = ($enabled === '1')
			? $this->r('plugin.enabled', '', 'plugin', 'Inbound email enabled', self::REQUIRED, self::PASS,
				'The inbound_email_enabled master switch is on.')
			: $this->r('plugin.enabled', '', 'plugin', 'Inbound email enabled', self::REQUIRED, self::FAIL,
				'The inbound_email_enabled master switch is off — inbound mail is accepted but not processed.',
				'', array('text' => 'Turn it on with the "Enable inbound email" button below, or in Settings.',
				          'action' => array('action' => 'enable_plugin')));

		$srsOn = ((string)$this->settings->get_setting('inbound_email_srs_enabled') === '1');
		$srsSecret = trim((string)$this->settings->get_setting('inbound_email_srs_secret'));
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

		// Outbound relay reachability.
		try {
			require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailHealth.php'));
			InboundEmailHealth::checkForwardingRelay();
			$out[] = $this->r('plugin.relay', '', 'plugin', 'Outbound forwarding relay', self::REQUIRED, self::PASS,
				'The outbound SMTP relay is reachable.');
		} catch (\Throwable $e) {
			$out[] = $this->r('plugin.relay', '', 'plugin', 'Outbound forwarding relay', self::REQUIRED, self::FAIL,
				'The outbound SMTP relay could not be reached.', $e->getMessage(),
				array('text' => 'Check the SMTP relay settings on the Settings page.'));
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
		$parts = explode('@', $address, 2);
		if (count($parts) === 2) {
			$domain = InboundEmailDomain::GetByDomain($parts[1]);
			if ($domain && $domain->get('ied_catch_all_address')) { $catchAll = true; }
		}
		if ($alias) {
			$out[] = $this->r('address.alias', $address, 'address', 'Delivery target exists', self::REQUIRED, self::PASS,
				$address . ' resolves to an enabled alias.');
		} elseif ($catchAll) {
			$out[] = $this->r('address.alias', $address, 'address', 'Delivery target exists', self::REQUIRED, self::PASS,
				$address . ' is covered by the domain catch-all.');
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
				? 'Note: ' . $this->publicIp . ' is a private address — set inbound_email_public_ip to the real public IP if this server is behind NAT.'
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
	 */
	private function mxResult($domain, $mxTarget, $mxPri) {
		$lead = $domain . ' MX → ' . $mxTarget . ' (priority ' . $mxPri . ')';

		// MX target must not be a CNAME (RFC 2181).
		list($cname, $cnameOk) = $this->dns(function () use ($mxTarget) { return DnsResolver::getCname($mxTarget); });
		if (!$cnameOk) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				$lead . '. DNS lookup for ' . $mxTarget . ' failed — try again.');
		}
		if ($cname !== null) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ', but ' . $mxTarget . ' is a CNAME (' . $cname . ') — RFC 2181 forbids an MX target that is a CNAME.',
				'', array('text' => 'Point the MX at a hostname that has its own A record, not a CNAME.'));
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
				$this->dnsFix('A', $mxTarget, $this->publicIp !== '' ? $this->publicIp : 'YOUR_SERVER_IP'));
		}
		if ($this->publicIp === '') {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::UNKNOWN,
				$lead . ' → ' . implode(', ', $mxA) . '. Cannot confirm it points here — the server public IP could not be determined.');
		}
		if (!in_array($this->publicIp, $mxA, true)) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::FAIL,
				$lead . ' → ' . implode(', ', $mxA) . ', not this server (' . $this->publicIp . ').', '',
				$this->dnsFix('A', $mxTarget, $this->publicIp));
		}
		if ($this->publicIpIsPrivate) {
			return $this->r('domain.mx', $domain, 'domain', 'MX record', self::REQUIRED, self::WARN,
				$lead . ' → ' . $this->publicIp . ', this server.',
				'Note: ' . $this->publicIp . ' is a private address — set inbound_email_public_ip to the real public IP if this server is behind NAT.');
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
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::PASS,
				'SPF record present and authorizes ' . $this->publicIp . '.');
		}
		if ($spfEval === 'include') {
			return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::WARN,
				'SPF record present, but it uses include:/redirect mechanisms — could not fully verify ' . $this->publicIp . '.',
				'Record: ' . $spf,
				array('text' => 'Confirm the included policy covers ' . $this->publicIp . ', or add ip4:' . $this->publicIp . ' directly.'));
		}
		return $this->r('domain.spf', $domain, 'domain', 'SPF record', self::REQUIRED, self::FAIL,
			'SPF record present, but it does not authorize ' . $this->publicIp . '.',
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
		$suffix = 'plugins/inbound_email/utils/inbound_email_handler.php';
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
			'command' => 'sudo bash ' . PathHelper::getIncludePath('plugins/inbound_email/provisioning/install_email.sh'),
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
				. PathHelper::getIncludePath('plugins/inbound_email/provisioning/provision_dkim.sh')
				. ' ' . $domain,
		);
	}

	private function dnsFix($type, $name, $value) {
		return array(
			'text' => 'Publish this record at your DNS provider.',
			'dns_record' => array('type' => $type, 'name' => $name, 'value' => $value),
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
	 * Evaluate whether an SPF record authorizes the server's public IP.
	 * Returns 'pass', 'fail', or 'include' (has include/redirect — not fully verifiable here).
	 */
	private function spfAuthorizes($spf, $domain) {
		if ($this->publicIp === '') { return 'fail'; }
		$hasInclude = false;
		foreach (preg_split('/\s+/', trim($spf)) as $term) {
			$term = ltrim($term, '+');
			if ($term === '' || stripos($term, 'v=spf1') === 0) { continue; }
			if (stripos($term, 'include:') === 0 || stripos($term, 'redirect=') === 0) {
				$hasInclude = true;
				continue;
			}
			if (stripos($term, 'ip4:') === 0) {
				if ($this->ip4InCidr($this->publicIp, substr($term, 4))) { return 'pass'; }
			} elseif (stripos($term, 'ip6:') === 0) {
				if (strcasecmp($this->publicIp, substr($term, 4)) === 0) { return 'pass'; }
			} elseif ($term === 'a' || stripos($term, 'a:') === 0) {
				$host = ($term === 'a') ? $domain : substr($term, 2);
				list($ips, $ok) = $this->dns(function () use ($host) { return DnsResolver::getA($host); });
				if ($ok && in_array($this->publicIp, $ips, true)) { return 'pass'; }
			} elseif ($term === 'mx' || stripos($term, 'mx:') === 0) {
				$host = ($term === 'mx') ? $domain : substr($term, 3);
				list($mx, $ok) = $this->dns(function () use ($host) { return DnsResolver::getMx($host); });
				if ($ok) {
					foreach ($mx as $rec) {
						list($mxips, $ok2) = $this->dns(function () use ($rec) {
							return DnsResolver::getA(rtrim($rec['host'], '.'));
						});
						if ($ok2 && in_array($this->publicIp, $mxips, true)) { return 'pass'; }
					}
				}
			}
		}
		return $hasInclude ? 'include' : 'fail';
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
		$configured = trim((string)$this->settings->get_setting('inbound_email_public_ip'));
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
