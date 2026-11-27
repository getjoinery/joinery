<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * setup_https_check — the HTTPS setup step's live diagnosis (owner only).
 *
 * Answers three questions about the site's domain, freshly, on demand:
 *   1. Does HTTPS work right now? (a real TLS probe of https://{domain}/ —
 *      the truth on every topology, including TLS terminated in front)
 *   2. Does the domain's DNS point at this server? (A/AAAA vs the server's
 *      public address per family, the same comparison the SSL retry timer
 *      makes — either family matching is enough)
 *   3. Is the automatic certificate retry armed on this box? (the timer's
 *      conf file exists — it re-checks every five minutes and issues the
 *      certificate itself the moment DNS lands)
 *
 * Pure reads; nothing here mutates. The checks talk to the network (public-IP
 * lookup, DNS, TLS probe) and can take a few seconds — they run only when the
 * wizard's HTTPS step is actually on screen, never per-pageview elsewhere.
 *
 * The wizard renders setup_https_diagnose() server-side (via setup_logic):
 * the step exists precisely because the site is on plain HTTP, and the API
 * face correctly refuses cleartext with 426 — so the page cannot fetch its
 * own diagnosis. The API action remains for secure contexts.
 *
 * @version 1.4
 * @changelog 1.4 - The diagnosis names every address the name answers with that is not this server's
 *   (stray), reads the certificate retry's last tick (/etc/joinery/ssl-retry/DOMAIN.status), and
 *   setup_https_findings() turns it into plain findings, one list for the wizard step, the public
 *   /https-status page and joinery-https-check. setup_https_diagnose_cached() serves the public page.
 * @changelog 1.3 - setup_https_ipv6_plan(): the AAAA record a connected site lacks, which setup_https_dns_plan()
 *   offers once the name points here
 * @version 1.2
 * @changelog 1.2 - points_elsewhere in the diagnosis, and setup_https_address_plan():
 *   the A/AAAA record the publish box can write for the site, withheld when the
 *   name already answers from another server.
 * @version 1.1
 * @changelog 1.1 - Diagnosis extracted to setup_https_diagnose() so the wizard
 *   can render it server-side over plain HTTP (the API face refuses cleartext).
 */

function setup_https_check_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if (!Access::decide(10, $session)) {
		return LogicResult::error('Only the site owner can run this check.');
	}

	return LogicResult::render(setup_https_diagnose());
}

/**
 * The full diagnosis, as one array — see the file header for the three
 * questions it answers. Shared by the API action and the wizard's
 * server-side render.
 */
function setup_https_diagnose(): array {
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	$domain = setup_https_site_domain();
	if ($domain === '' || $domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
		return array(
			'applicable' => false,
			'domain'     => $domain,
		);
	}

	// 1. The TLS probe — a completed handshake with a verified certificate is
	// what "HTTPS works" means; the HTTP status behind it is irrelevant.
	$probe = setup_https_probe($domain);

	// 2. DNS vs this server, per address family.
	$dns_a    = array();
	$dns_aaaa = array();
	foreach ((array)@dns_get_record($domain, DNS_A) as $rec) {
		if (!empty($rec['ip'])) {
			$dns_a[] = $rec['ip'];
		}
	}
	foreach ((array)@dns_get_record($domain, DNS_AAAA) as $rec) {
		if (!empty($rec['ipv6'])) {
			$dns_aaaa[] = $rec['ipv6'];
		}
	}
	$server_ip4 = setup_https_public_ip(4);
	$server_ip6 = setup_https_public_ip(6);
	$dns_match = ($server_ip4 !== '' && in_array($server_ip4, $dns_a, true))
		|| ($server_ip6 !== '' && in_array($server_ip6, $dns_aaaa, true));
	// The name answers with an address that is not this server's: something
	// else is being served there today, and pointing it here would replace
	// that. Said on the page, and the automatic write is withheld.
	$points_elsewhere = !$dns_match && (!empty($dns_a) || !empty($dns_aaaa));

	// 3. The deferred-certificate retry timer (arm_ssl_retry.sh) leaves its
	// conf at a fixed path; existence is the armed signal. The file itself is
	// root-only, which is fine — nothing in it is needed here.
	$retry_armed = @file_exists('/etc/joinery/ssl-retry/' . $domain . '.conf');

	// 4. Every address the name answers with that is not this server's: a
	// name that answers with two servers sends part of its traffic, and the
	// certificate authority, to the other one.
	$stray = array();
	foreach (array_merge($dns_a, $dns_aaaa) as $addr) {
		if ($addr !== $server_ip4 && $addr !== $server_ip6) {
			$stray[] = $addr;
		}
	}

	return array(
		'applicable'   => true,
		'domain'       => $domain,
		'https_ready'  => $probe['ok'],
		'https_error'  => $probe['error'],
		'cert_issuer'  => $probe['issuer'],
		'cert_expires' => $probe['expires'],
		'dns_a'        => $dns_a,
		'dns_aaaa'     => $dns_aaaa,
		'server_ip4'   => $server_ip4,
		'server_ip6'   => $server_ip6,
		'dns_match'    => $dns_match,
		'points_elsewhere' => $points_elsewhere,
		'retry_armed'  => $retry_armed,
		'stray'        => $stray,
		'retry'        => setup_https_retry_status($domain),
	);
}

/**
 * The certificate retry's last tick for $domain (arm_ssl_retry.sh writes it,
 * world-readable): checked (epoch, 0 when never), state (not_reached,
 * edge_strict, edge_no_site, requesting, failed, issued, or '' when there is
 * no record) and detail (the certificate authority's reason on a failure).
 */
function setup_https_retry_status(string $domain): array {
	$out = array('checked' => 0, 'state' => '', 'detail' => '');
	if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
		return $out;
	}
	$raw = @file_get_contents('/etc/joinery/ssl-retry/' . $domain . '.status');
	if (!is_string($raw)) {
		return $out;
	}
	foreach (explode("\n", $raw) as $line) {
		if (preg_match('/^(CHECKED|STATE|DETAIL)=(.*)$/', $line, $m)) {
			$key = strtolower($m[1]);
			$out[$key] = $key === 'checked' ? (int)$m[2] : trim($m[2]);
		}
	}
	return $out;
}

/**
 * Whether this server is still waiting for the site's first certificate: the
 * retry is armed, or its last tick did not end in one. Local file reads only,
 * so the sign-in page can ask on every view.
 */
function setup_https_waiting_for_certificate(): bool {
	$domain = setup_https_site_domain();
	if ($domain === '' || $domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
		return false;
	}
	if (@file_exists('/etc/joinery/ssl-retry/' . $domain . '.conf')) {
		return true;
	}
	$r = setup_https_retry_status($domain);
	return $r['state'] !== '' && $r['state'] !== 'issued';
}

/**
 * The diagnosis for the public /https-status page, reused for 60 seconds: the
 * page needs no sign-in, and each fresh diagnosis makes outside lookups, so
 * nobody can make the server repeat them on every request.
 */
function setup_https_diagnose_cached(int $ttl = 60): array {
	$domain = setup_https_site_domain();
	$file = rtrim(sys_get_temp_dir(), '/') . '/joinery_https_status_' . md5($domain) . '.json';
	$st = @stat($file);
	// A certificate attempt since the cached answer makes it stale at once.
	$tick = preg_match('/^[a-z0-9.-]+$/', $domain) ? (int)@filemtime('/etc/joinery/ssl-retry/' . $domain . '.status') : 0;
	if ($st && $st['mtime'] > time() - $ttl && $tick <= $st['mtime']) {
		$cached = json_decode((string)@file_get_contents($file), true);
		if (is_array($cached)) {
			return $cached;
		}
	}
	$d = setup_https_diagnose();
	@file_put_contents($file . '.' . getmypid(), json_encode($d));
	@rename($file . '.' . getmypid(), $file);
	return $d;
}

/**
 * The diagnosis as plain findings, in the order to read them: each is
 * array('kind' => ok|problem|waiting, 'text' => plain words). Shared by the
 * wizard step, the public page and joinery-https-check, so all three say the
 * same thing. Nothing here names a command: the console adds the exact one.
 */
function setup_https_findings(array $d): array {
	$f = array();
	if (empty($d['applicable'])) {
		$f[] = array('kind' => 'problem', 'text' => 'This site has no web address of its own (it answers at an IP address), so no certificate can be issued for it. Give it a domain name first.');
		return $f;
	}
	$domain = (string)$d['domain'];
	if (!empty($d['https_ready'])) {
		$f[] = array('kind' => 'ok', 'text' => 'The connection to ' . $domain . ' is secure.');
		return $f;
	}
	$here = array_values(array_filter(array((string)($d['server_ip4'] ?? ''), (string)($d['server_ip6'] ?? ''))));
	$here_text = $here ? implode(' and ', $here) : 'this server';
	// What to type: an A record takes the IPv4 address; an AAAA record the IPv6.
	$ip4 = (string)($d['server_ip4'] ?? '');
	$ip6 = (string)($d['server_ip6'] ?? '');
	$record_text = $ip4 !== '' ? 'an A record pointing at ' . $ip4 . ($ip6 !== '' ? ' (and, if you like, an AAAA record pointing at ' . $ip6 . ')' : '')
		: ($ip6 !== '' ? 'an AAAA record pointing at ' . $ip6 : 'a record pointing at this server');
	$answers = array_merge((array)($d['dns_a'] ?? array()), (array)($d['dns_aaaa'] ?? array()));
	$stray = (array)($d['stray'] ?? array());
	if (!$answers) {
		$f[] = array('kind' => 'problem', 'text' => $domain . ' has no address record yet. Where the name\'s DNS is managed, add ' . $record_text . ' for ' . $domain . '.');
	} elseif (empty($d['dns_match'])) {
		$f[] = array('kind' => 'problem', 'text' => $domain . ' points at another server (' . implode(', ', $stray) . '), not at this one (' . $here_text . '). Either change it to ' . $record_text
			. ', or, if the site belongs at a different name (such as home.' . $domain . '), point that name here and move the site to it.');
	} else {
		$f[] = array('kind' => 'ok', 'text' => $domain . ' points at this server (' . $here_text . ').');
		if ($stray) {
			$f[] = array('kind' => 'problem', 'text' => $domain . ' also points at ' . implode(', ', $stray) . ', which is not this server. Remove ' . (count($stray) > 1 ? 'those records' : 'that record')
				. ': visitors, and the certificate authority, are sent there part of the time (always, for an IPv6 address).');
		}
	}
	$r = (array)($d['retry'] ?? array());
	$state = (string)($r['state'] ?? '');
	$when = !empty($r['checked']) ? setup_https_ago((int)$r['checked']) : '';
	if ($state === 'failed') {
		$reason = (string)($r['detail'] ?? '');
		$f[] = array('kind' => 'problem', 'text' => 'The last certificate attempt' . ($when ? ' (' . $when . ')' : '') . ' failed'
			. ($reason !== '' ? ': ' . rtrim($reason, '. ') . '.' : '.')
			. (stripos($reason, 'firewall') !== false || stripos($reason, 'timeout') !== false
				? ' A firewall in front of the server (a cloud provider\'s, such as a Linode Cloud Firewall) must allow incoming connections on ports 80 and 443.' : ''));
	} elseif ($state === 'edge_strict') {
		$f[] = array('kind' => 'problem', 'text' => 'The name goes through a proxy (such as Cloudflare) set to Full (Strict). Set it to Full until the first certificate is in place.');
	} elseif ($state === 'edge_no_site') {
		$f[] = array('kind' => 'problem', 'text' => 'The name goes through a proxy (such as Cloudflare), but the proxy\'s secure connection does not reach this server.');
	} elseif ($state === 'requesting') {
		$f[] = array('kind' => 'waiting', 'text' => 'A certificate is being requested now.');
	} elseif ($state === 'not_reached' && !empty($d['dns_match']) && !$stray) {
		$f[] = array('kind' => 'waiting', 'text' => 'The last check' . ($when ? ' (' . $when . ')' : '') . ' could not reach this server through ' . $domain . ' yet; DNS changes can take a while to spread.');
	}
	if (!empty($d['retry_armed'])) {
		$f[] = array('kind' => 'waiting', 'text' => 'The server tries again every five minutes and gets the certificate by itself once the name reaches it.');
	} elseif ($state !== 'issued') {
		$f[] = array('kind' => 'problem', 'text' => 'This server is not waiting for a certificate for ' . $domain . '. Its owner can ask for one again with setup_ssl.sh.');
	}
	return $f;
}

function setup_https_ago(int $epoch): string {
	$s = max(0, time() - $epoch);
	if ($s < 90) {
		return 'just now';
	}
	if ($s < 5400) {
		return round($s / 60) . ' minutes ago';
	}
	return round($s / 3600) . ' hours ago';
}

/**
 * The record the wizard can add for the operator: this server's address at
 * the site's name, for the DNS publish box to write through the domain's own
 * host. Null when there is nothing safe to offer — no public address, a name
 * that is not a real domain, or a name that already answers with someone
 * else's address (pointing it here would take down whatever is there; that
 * is a decision for the operator, made by hand).
 */
function setup_https_address_plan(string $domain, array $dns_a, array $dns_aaaa, string $ip4, string $ip6): ?DnsRecordPlan {
	require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
	$domain = DnsRecord::normalizeName($domain);
	if ($domain === '' || $domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)
			|| strpos($domain, '.') === false) {
		return null;
	}
	if ($ip4 === '' && $ip6 === '') {
		return null;
	}
	foreach (array_merge($dns_a, $dns_aaaa) as $live) {
		if ($live !== $ip4 && $live !== $ip6) {
			return null;
		}
	}
	$plan = new DnsRecordPlan($domain, 'setup_wizard');
	if ($ip4 !== '') {
		$plan->addRecord(DnsRecord::TYPE_A, $domain, $ip4);
	}
	if ($ip6 !== '') {
		$plan->addRecord(DnsRecord::TYPE_AAAA, $domain, $ip6);
	}
	return $plan;
}

/**
 * The IPv6 record a connected site still lacks: the name answers with this
 * server's IPv4 address, this server has a public IPv6 address, and the name
 * has no AAAA record at all. Null otherwise — including a name whose AAAA
 * answers with some other address, which is the operator's to change by hand.
 * The site works without it; with it, visitors on IPv6 reach it directly.
 */
function setup_https_ipv6_plan(array $d): ?DnsRecordPlan {
	if (empty($d['applicable']) || empty($d['dns_match']) || (string)($d['server_ip6'] ?? '') === ''
			|| !empty($d['dns_aaaa']) || !in_array((string)$d['server_ip4'], (array)$d['dns_a'], true)) {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/dns/DnsRecordPlan.php'));
	$domain = DnsRecord::normalizeName((string)$d['domain']);
	if ($domain === '' || strpos($domain, '.') === false) {
		return null;
	}
	$plan = new DnsRecordPlan($domain, 'setup_wizard');
	$plan->addRecord(DnsRecord::TYPE_AAAA, $domain, (string)$d['server_ip6']);
	return $plan;
}

/**
 * The plan for the running site, from a fresh diagnosis: its address records
 * while the name does not point here, and once it does, the IPv6 record it
 * still lacks.
 */
function setup_https_dns_plan(): ?DnsRecordPlan {
	$d = setup_https_diagnose();
	if (empty($d['applicable'])) {
		return null;
	}
	if (!empty($d['dns_match'])) {
		return setup_https_ipv6_plan($d);
	}
	return setup_https_address_plan((string)$d['domain'], (array)$d['dns_a'], (array)$d['dns_aaaa'],
		(string)$d['server_ip4'], (string)$d['server_ip6']);
}

/**
 * The site's canonical hostname: webDir when configured, else the request
 * host — lowercased, protocol and port stripped.
 */
function setup_https_site_domain(): string {
	$web = trim((string)Globalvars::get_instance()->get_setting('webDir'));
	$host = $web !== ''
		? preg_replace('#^https?://#', '', rtrim($web, '/'))
		: (string)($_SERVER['HTTP_HOST'] ?? '');
	$host = preg_replace('/:\d+$/', '', (string)$host);
	return strtolower(trim($host));
}

/**
 * TLS-probe https://{domain}/ with full certificate verification.
 * Returns ok, the failure text when not ok, and the certificate's issuer
 * organisation and expiry when the handshake completed.
 */
function setup_https_probe(string $domain): array {
	$out = array('ok' => false, 'error' => '', 'issuer' => '', 'expires' => '');
	$ch = curl_init('https://' . $domain . '/');
	curl_setopt_array($ch, array(
		CURLOPT_NOBODY         => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_TIMEOUT        => 10,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_CERTINFO       => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_USERAGENT      => 'Joinery-Setup-HTTPS-Check',
	));
	$ok = curl_exec($ch) !== false;
	if ($ok) {
		$out['ok'] = true;
		$certs = curl_getinfo($ch, CURLINFO_CERTINFO);
		$leaf = is_array($certs) && isset($certs[0]) ? $certs[0] : array();
		if (!empty($leaf['Issuer']) && preg_match('/O\s*=\s*([^,\/]+)/', $leaf['Issuer'], $m)) {
			$out['issuer'] = trim($m[1]);
		}
		if (!empty($leaf['Expire date'])) {
			$ts = strtotime($leaf['Expire date']);
			$out['expires'] = $ts ? gmdate('M j, Y', $ts) : '';
		}
	} else {
		$out['error'] = curl_error($ch);
	}
	return $out;
}

/**
 * This server's public address for one family, asked per family explicitly —
 * the same discipline as the SSL retry script: a dual-stack box answers a
 * bare "what is my IP" with whichever family it prefers, which then never
 * matches a domain that only has the other record.
 */
function setup_https_public_ip(int $family): string {
	foreach (array('https://ifconfig.me', 'https://icanhazip.com') as $service) {
		$ch = curl_init($service);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => 5,
			CURLOPT_IPRESOLVE      => ($family === 6) ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
			CURLOPT_USERAGENT      => 'Joinery-Setup-HTTPS-Check',
		));
		$answer = trim((string)curl_exec($ch));
		if ($answer !== '' && filter_var($answer, FILTER_VALIDATE_IP)) {
			return $answer;
		}
	}
	return '';
}

function setup_https_check_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'auth'             => array('min_user_permission' => 0, 'requires_browser_session' => true),
		'mutates'          => false,
		'description'      => 'Diagnose the site\'s HTTPS state for the setup wizard: TLS probe, DNS-vs-server comparison, and whether the automatic certificate retry is armed (owner only).',
	);
}
?>
