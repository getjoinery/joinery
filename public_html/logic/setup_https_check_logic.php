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
 * @version 1.1
 * @changelog 1.1 - Diagnosis extracted to setup_https_diagnose() so the wizard
 *   can render it server-side over plain HTTP (the API face refuses cleartext).
 */

function setup_https_check_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
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

	// 3. The deferred-certificate retry timer (arm_ssl_retry.sh) leaves its
	// conf at a fixed path; existence is the armed signal. The file itself is
	// root-only, which is fine — nothing in it is needed here.
	$retry_armed = @file_exists('/etc/joinery/ssl-retry/' . $domain . '.conf');

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
		'retry_armed'  => $retry_armed,
	);
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
	curl_close($ch);
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
		curl_close($ch);
		if ($answer !== '' && filter_var($answer, FILTER_VALIDATE_IP)) {
			return $answer;
		}
	}
	return '';
}

function setup_https_check_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'auth'             => array('requires_browser_session' => true),
		'mutates'          => false,
		'description'      => 'Diagnose the site\'s HTTPS state for the setup wizard: TLS probe, DNS-vs-server comparison, and whether the automatic certificate retry is armed (owner only).',
	);
}
?>
