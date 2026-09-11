<?php
/**
 * CertificateNotice — the admin-header notice on a box whose own TLS
 * certificate is not going to keep working (specs/tls_and_origin_trust.md, WP11).
 *
 * A self-hosted node has no management node watching its certificate. Its
 * owner learns that renewal broke when the site dies — behind Cloudflare in
 * Full mode never, and in Full (Strict) the moment the certificate expires.
 * certbot records everything needed to say so earlier, under /etc/letsencrypt,
 * which the web user cannot read on a root-owned tree. So the host converger
 * writes a small world-readable summary of it on every run, and this reads
 * that. Information, never a gate; never a probe.
 *
 * The summary, cache/certificates.json, written by _plugin_installers_start.sh:
 *
 *   {
 *     "written":      1789141975,            unix time of the run
 *     "in_container": false,                 TLS terminates elsewhere when true
 *     "letsencrypt":  true,                  /etc/letsencrypt exists
 *     "timer_active": true,                  certbot.timer active, or /etc/cron.d/certbot present
 *     "site": {
 *       "name":           "example.com",     ServerName of this site's vhost ('' when none)
 *       "own_addresses":  ["203.0.113.5"],   the box's own addresses
 *       "apex_addresses": ["104.16.1.1"],    what the name resolves to ([] when it does not)
 *       "www_addresses":  ["104.16.1.1"]     what www.<name> resolves to ([] when it does not)
 *     },
 *     "lineages": [
 *       { "name": "example.com",                       the lineage directory
 *         "names": ["example.com","www.example.com"],  SANs of cert.pem
 *         "not_before": 1786000000, "not_after": 1793776000,
 *         "renewal_installer": "None" }                from renewal/<name>.conf
 *     ]
 *   }
 *
 * The states, decided by forState() in this order:
 *   unknown — no summary, or one older than two days (the converger is what is
 *             stale, and HostConvergerNotice already says so); or a container,
 *             whose TLS terminates on its Docker host
 *   advice  — no lineage for the site's name: how to issue, and when the name
 *             resolves to an edge, the one true limit — Cloudflare must be on
 *             Full, not Full (Strict), until the first certificate lands,
 *             because Strict refuses the placeholder the box answers with until
 *             then and the HTTP-01 challenge cannot get through
 *   unmet   — the certificate has expired; renewal is more than a day past due
 *             with the same certificate still on disk; the renewal timer is off
 *   advice  — www resolves and the certificate does not cover it: a Strict flip
 *             would take the www address dark while the apex looks healthy
 *   met     — renewing on schedule; the expiry date is stated
 *
 * @version 1.1 - the edge advice names the placeholder Strict refuses (WP12)
 * @version 1.0
 */
class CertificateNotice {

	/** A summary older than this says nothing about today. */
	const STALE_AFTER = 2 * 86400;
	/** Renewal this long past its due date, with no new certificate, is failing. */
	const OVERDUE_GRACE = 86400;

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10) {
			return '';
		}
		$facts = self::facts();
		$state = self::forState($facts, time(), self::siteName());
		if ($state['state'] !== 'unmet' && $state['state'] !== 'advice') {
			return '';
		}
		return self::css()
			. '<div class="jy-certificate-notice jy-certificate-notice--' . $state['state'] . '" role="status">'
			. '<div class="jy-certificate-notice__text"><strong>' . htmlspecialchars($state['lead'], ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($state['body'], ENT_QUOTES, 'UTF-8')
			. ($state['command'] !== ''
				? ' <code class="jy-certificate-notice__cmd">' . htmlspecialchars($state['command'], ENT_QUOTES, 'UTF-8') . '</code>'
				: '')
			. '</div></div>';
	}

	/**
	 * The stored facts: the decoded summary, or null when there is none or it
	 * does not parse. Nothing here touches the network or /etc/letsencrypt.
	 */
	public static function facts(?string $site_root = null): ?array {
		$site_root = $site_root ?? PathHelper::getSiteRoot();
		$raw = @file_get_contents($site_root . '/cache/certificates.json');
		if (!is_string($raw) || $raw === '') {
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}

	/** The name this site answers to, or '' when it has none that can carry a certificate. */
	public static function siteName(): string {
		$web = trim((string)Globalvars::get_instance()->get_setting('webDir'), " /");
		$host = parse_url('https://' . $web, PHP_URL_HOST);
		if (!is_string($host) || $host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
			return '';
		}
		return strtolower($host);
	}

	/**
	 * The verdict for one set of facts. Public and pure so every state can be
	 * pinned from a fixture (tests/unit/certificate_notice_test.php).
	 *
	 * @param array|null $facts     The decoded summary, or null when there is none.
	 * @param int        $now       The clock.
	 * @param string     $site_name The name from the site's own configuration; the
	 *                              summary's site.name wins when the converger found one.
	 * @return array{state:string,lead:string,body:string,command:string,expires:?int}
	 *         state is one of 'met' | 'unmet' | 'advice' | 'unknown'.
	 */
	public static function forState(?array $facts, int $now, string $site_name): array {
		$out = function (string $state, string $lead, string $body = '', string $command = '', ?int $expires = null): array {
			return ['state' => $state, 'lead' => $lead, 'body' => $body, 'command' => $command, 'expires' => $expires];
		};

		if ($facts === null) {
			return $out('unknown', 'No certificate summary has been written on this box yet.',
				'The host converger writes cache/certificates.json on every run; until it has, nothing can be said about the certificate from here.');
		}
		$written = (int)($facts['written'] ?? 0);
		if ($written <= 0 || $now - $written >= self::STALE_AFTER) {
			return $out('unknown', 'The certificate summary is stale.',
				'It was written ' . ($written > 0 ? gmdate('Y-m-d H:i', $written) . ' UTC' : 'at an unknown time')
				. '; the host converger that writes it is what has stopped, and its own check says so.');
		}
		if (!empty($facts['in_container'])) {
			return $out('unknown', 'This site runs in a container.',
				'TLS terminates on the Docker host, which holds and renews the certificate; a management node watching that host is what sees it.');
		}

		$site = is_array($facts['site'] ?? null) ? $facts['site'] : [];
		$name = strtolower(trim((string)($site['name'] ?? '')));
		if ($name === '') {
			$name = strtolower(trim($site_name));
		}
		if ($name === '' || $name === 'localhost' || filter_var($name, FILTER_VALIDATE_IP)) {
			return $out('unknown', 'This site has no domain name.', 'A certificate needs a name to be issued for.');
		}

		$own  = array_map('strval', (array)($site['own_addresses'] ?? []));
		$apex = array_map('strval', (array)($site['apex_addresses'] ?? []));
		$www  = array_map('strval', (array)($site['www_addresses'] ?? []));
		$resolves      = count($apex) > 0;
		$resolves_here = $resolves && count(array_intersect($apex, $own)) > 0;
		$www_resolves  = count($www) > 0;

		$issue = 'sudo bash ' . self::siteRoot() . '/maintenance_scripts/sysadmin_tools/setup_ssl.sh ' . $name;

		$lineage = self::lineageFor($facts['lineages'] ?? [], $name);
		if ($lineage === null) {
			$held = [];
			foreach ((array)($facts['lineages'] ?? []) as $l) {
				if (is_array($l) && !empty($l['name'])) { $held[] = (string)$l['name']; }
			}
			$others = count($held) > 0 ? ' The certificates on this box are for ' . implode(', ', $held) . '.' : '';
			if (!$resolves) {
				return $out('advice', 'No certificate is issued for ' . $name . ' yet.',
					'The name does not resolve anywhere, so the issuer cannot reach this box to prove control. '
					. 'Point DNS at it (directly or through an edge); the retry timer armed at install issues the certificate on its own once the name reaches here.' . $others,
					$issue);
			}
			if (!$resolves_here) {
				return $out('advice', 'No certificate is issued for ' . $name . ' yet, and the name resolves to an edge.',
					'Keep the edge on Full, not Full (Strict), until the first certificate lands: Strict refuses the placeholder certificate '
					. 'this box answers with until then, and the HTTP-01 challenge cannot get through. Then issue it on the host:' . $others,
					$issue);
			}
			return $out('advice', 'No certificate is issued for ' . $name . ' yet.',
				'The name resolves to this box, so HTTP-01 can prove control. Issue it on the host:' . $others,
				$issue);
		}

		$not_before = (int)($lineage['not_before'] ?? 0);
		$not_after  = (int)($lineage['not_after'] ?? 0);
		$expires    = $not_after > 0 ? gmdate('Y-m-d', $not_after) . ' UTC' : 'an unknown date';
		$renew      = 'sudo certbot renew';

		if ($not_after > 0 && $not_after <= $now) {
			return $out('unmet', 'The certificate for ' . $name . ' expired ' . $expires . '.',
				'Browsers, and an edge in Full (Strict), refuse the site. Renew it on the host and read what certbot says:',
				$renew, $not_after);
		}
		$due = self::renewalDue($not_before, $not_after);
		if ($due !== null && $now - $due > self::OVERDUE_GRACE) {
			return $out('unmet', 'Renewal of the certificate for ' . $name . ' is overdue since ' . gmdate('Y-m-d', $due) . ' UTC.',
				'The certificate on disk is still the one that was due for replacement, so automatic renewal is failing; it expires ' . $expires
				. '. On the host, run this and read its output:',
				$renew, $not_after);
		}
		if (array_key_exists('timer_active', $facts) && !$facts['timer_active']) {
			return $out('unmet', 'Nothing is scheduled to renew the certificate for ' . $name . '.',
				'certbot\'s timer is not active, so the certificate expires ' . $expires . ' with nothing set to replace it. On the host:',
				'sudo systemctl enable --now certbot.timer', $not_after);
		}
		$names = array_map('strtolower', array_map('strval', (array)($lineage['names'] ?? [])));
		if ($www_resolves && !self::covers($names, 'www.' . $name)) {
			return $out('advice', 'The certificate does not cover www.' . $name . '.',
				'www resolves and reaches this origin under that name, so an edge in Full (Strict) would reject it while the apex stays up. '
				. 'Re-issue for the apex and www together on the host:',
				'sudo bash ' . self::siteRoot() . '/maintenance_scripts/sysadmin_tools/issue_origin_cert.sh ' . $name, $not_after);
		}
		return $out('met', 'The certificate for ' . $name . ' renews on schedule and expires ' . $expires . '.', '', '', $not_after);
	}

	/**
	 * When a standard ACME client replaces a certificate: two thirds through its
	 * life. The same arithmetic as RunNodeUptimeChecks::renewal_due_ts(), so the
	 * node and the plane agree on the day. Null when the dates make no sense.
	 */
	public static function renewalDue(int $not_before, int $not_after): ?int {
		if ($not_before <= 0 || $not_before >= $not_after) {
			return null;
		}
		return $not_after - intdiv($not_after - $not_before, 3);
	}

	/** The lineage whose SANs cover $name, preferring an exact lineage name. */
	private static function lineageFor($lineages, string $name): ?array {
		if (!is_array($lineages)) {
			return null;
		}
		$match = null;
		foreach ($lineages as $l) {
			if (!is_array($l)) continue;
			$names = array_map('strtolower', array_map('strval', (array)($l['names'] ?? [])));
			if (strtolower((string)($l['name'] ?? '')) === $name) {
				return $l;
			}
			if ($match === null && self::covers($names, $name)) {
				$match = $l;
			}
		}
		return $match;
	}

	/** Whether a SAN list covers $host, exactly or by a single-label wildcard. */
	private static function covers(array $names, string $host): bool {
		foreach ($names as $n) {
			if ($n === $host) {
				return true;
			}
			if (strpos($n, '*.') === 0) {
				$dot = strpos($host, '.');
				if ($dot !== false && substr($host, $dot) === substr($n, 1)) {
					return true;
				}
			}
		}
		return false;
	}

	private static function siteRoot(): string {
		return class_exists('PathHelper') ? PathHelper::getSiteRoot() : '/var/www/html/<site>';
	}

	private static function css(): string {
		static $sent = false;
		if ($sent) return '';
		$sent = true;
		return '<style>'
			. '.jy-certificate-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;background:#fffbeb;color:#78350f;font-size:.95rem}'
			. '.jy-certificate-notice--unmet{border-color:#fca5a5;background:#fef2f2;color:#7f1d1d}'
			. '.jy-certificate-notice__cmd{display:inline-block;margin-top:.25rem;padding:.15rem .4rem;border-radius:4px;background:rgba(0,0,0,.06);font-size:.9em;user-select:all;overflow-wrap:anywhere}'
			. '</style>';
	}
}
