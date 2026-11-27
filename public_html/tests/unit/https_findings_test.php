<?php
/** @joinery-test
 * name: https_findings
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [logic/setup_https_check_logic.php, views/https-status.php, views/login.php, includes/setup_steps/https.php, maintenance_scripts/sysadmin_tools/https_check.php, maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh]
 */
/**
 * Why a site has no secure connection yet, in plain findings
 * (setup_https_findings): the one list the setup wizard's Secure connection
 * step, the public /https-status page and joinery-https-check all show. Fed
 * diagnoses by hand; nothing touches the network.
 *
 * The cases are the ones a beta tester met on 2026-11-27: a name that points
 * at another server entirely (the site belonged at a subdomain), and a cloud
 * firewall that let the certificate authority's check time out.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('logic/setup_https_check_logic.php'));

function hf_base(array $over): array {
	return array_merge(array(
		'applicable' => true, 'domain' => 'demona.org', 'https_ready' => false,
		'dns_a' => array('66.228.45.27'), 'dns_aaaa' => array(), 'server_ip4' => '66.228.45.27', 'server_ip6' => '',
		'dns_match' => true, 'points_elsewhere' => false, 'retry_armed' => true, 'stray' => array(),
		'retry' => array('checked' => 0, 'state' => '', 'detail' => ''),
	), $over);
}
function hf_text(array $findings, string $kind = ''): string {
	$out = array();
	foreach ($findings as $f) {
		if ($kind === '' || $f['kind'] === $kind) {
			$out[] = $f['text'];
		}
	}
	return implode(' | ', $out);
}

section('A name that points at another server entirely');
$f = setup_https_findings(hf_base(array('dns_a' => array('103.168.172.52', '103.168.172.37'), 'dns_match' => false,
	'points_elsewhere' => true, 'stray' => array('103.168.172.52', '103.168.172.37'), 'retry' => array('checked' => time() - 120, 'state' => 'not_reached', 'detail' => ''))));
$p = hf_text($f, 'problem');
check(strpos($p, 'points at another server (103.168.172.52, 103.168.172.37), not at this one (66.228.45.27)') !== false,
	'it names both addresses and this server', $p);
check(strpos($p, 'home.demona.org') !== false && strpos($p, 'move the site to it') !== false,
	'and says the site may belong at another name, and can be moved there (B9)', $p);

section('A name that also points somewhere else');
$f = setup_https_findings(hf_base(array('dns_a' => array('66.228.45.27', '217.70.184.38'), 'stray' => array('217.70.184.38'))));
check(strpos(hf_text($f, 'ok'), 'points at this server') !== false, 'the right record is acknowledged');
check(strpos(hf_text($f, 'problem'), 'also points at 217.70.184.38, which is not this server. Remove that record') !== false,
	'and the stray one is named, to be removed', hf_text($f));
$f = setup_https_findings(hf_base(array('dns_aaaa' => array('2001:db8::1'), 'stray' => array('2001:db8::1'))));
check(strpos(hf_text($f, 'problem'), 'also points at 2001:db8::1') !== false, 'an IPv6 record elsewhere too');

section('The certificate authority could not connect');
$f = setup_https_findings(hf_base(array('retry' => array('checked' => time() - 300, 'state' => 'failed',
	'detail' => '66.228.45.27: Fetching http://demona.org/.well-known/acme-challenge/x: Timeout during connect (likely firewall problem)'))));
$p = hf_text($f, 'problem');
check(strpos($p, 'The last certificate attempt (5 minutes ago) failed: 66.228.45.27: Fetching') !== false, 'its own reason is shown, with when', $p);
check(strpos($p, 'Linode Cloud Firewall') !== false && strpos($p, 'ports 80 and 443') !== false, 'and a firewall reason says which ports to open (B8)', $p);
$f = setup_https_findings(hf_base(array('retry' => array('checked' => time(), 'state' => 'failed', 'detail' => 'too many failed authorizations recently'))));
check(strpos(hf_text($f, 'problem'), 'too many failed authorizations') !== false && strpos(hf_text($f, 'problem'), 'firewall') === false,
	'a rate limit is shown as it is, with no firewall advice');

section('Other states');
$f = setup_https_findings(hf_base(array('dns_a' => array(), 'dns_match' => false)));
check(strpos(hf_text($f, 'problem'), 'has no address record yet. Where the name\'s DNS is managed, add an A record pointing at 66.228.45.27 for demona.org') !== false, 'no record yet');
$f = setup_https_findings(hf_base(array('dns_a' => array('192.0.2.10'), 'dns_match' => false, 'points_elsewhere' => true, 'stray' => array('192.0.2.10'), 'server_ip6' => '2001:db8::5')));
check(strpos(hf_text($f, 'problem'), 'change it to an A record pointing at 66.228.45.27 (and, if you like, an AAAA record pointing at 2001:db8::5)') !== false,
	'an A record is given the IPv4 address only, the IPv6 one goes in an AAAA record', hf_text($f));
$f = setup_https_findings(hf_base(array('retry' => array('checked' => time(), 'state' => 'edge_strict', 'detail' => ''))));
check(strpos(hf_text($f, 'problem'), 'Full (Strict)') !== false, 'a proxy on Full (Strict)');
$f = setup_https_findings(hf_base(array()));
check(hf_text($f, 'problem') === '' && strpos(hf_text($f, 'waiting'), 'every five minutes') !== false, 'all in order: only the wait is said');
$f = setup_https_findings(hf_base(array('retry_armed' => false)));
check(strpos(hf_text($f, 'problem'), 'not waiting for a certificate') !== false, 'a server that stopped waiting says so');
$f = setup_https_findings(hf_base(array('https_ready' => true)));
check(count($f) === 1 && $f[0]['kind'] === 'ok' && strpos($f[0]['text'], 'is secure') !== false, 'a secure site says only that');
$f = setup_https_findings(array('applicable' => false, 'domain' => '66.228.45.27'));
check(strpos(hf_text($f, 'problem'), 'no web address of its own') !== false, 'a site on an IP address is told it needs a name');

section('The retry writes what the page reads');
$arm = (string)file_get_contents(dirname(PathHelper::getBasePath()) . '/maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh');
foreach (array('not_reached', 'edge_strict', 'edge_no_site', 'requesting', 'failed', 'issued') as $state) {
	check(strpos($arm, 'status ' . $state) !== false, 'arm_ssl_retry.sh writes the ' . $state . ' state');
}
check(strpos($arm, 'chmod 644 "${STATUS}.tmp"') !== false && strpos($arm, 'chmod 755 /etc/joinery/ssl-retry') !== false,
	'the status file is readable by the site, and its folder too');
check(strpos($arm, '/usr/local/sbin/joinery-https-check') !== false, 'arming installs joinery-https-check');
$tmp = harness_scratch_dir('https_findings');
file_put_contents($tmp . '/x.status', "CHECKED=1795790000\nSTATE=failed\nDETAIL=Timeout during connect\n");
check(preg_match('/^(CHECKED|STATE|DETAIL)=/m', (string)file_get_contents($tmp . '/x.status')) === 1, 'the status format is the one setup_https_retry_status() reads');

section('The sign-in page warns before the first certificate');
$login = (string)file_get_contents(PathHelper::getIncludePath('views/login.php'));
check(strpos($login, 'setup_https_waiting_for_certificate()') !== false && strpos($login, '/https-status') !== false,
	'login.php warns on plain http while the server waits for a certificate, and links the reasons');

harness_finish();
