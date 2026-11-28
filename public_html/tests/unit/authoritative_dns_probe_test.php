<?php
/** @joinery-test
 * name: authoritative_dns_probe
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * AuthoritativeDns::probe(): every nameserver's answer with why it was not
 * clean and how long it took (specs/mailbox_diagnosis_words.md WP1), and
 * lookupAll() over it unchanged. A fake sender stands in for the nameservers,
 * so nothing here touches the network.
 *
 * @version 1.1 - needsTcp(): which UDP results are asked again over TCP
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/AuthoritativeDns.php'));

function adp_name(string $name): string {
	$out = '';
	foreach (explode('.', rtrim($name, '.')) as $l) { $out .= chr(strlen($l)) . $l; }
	return $out . "\0";
}

/** A reply to $query with $answers [[name, type, rdata]] and the given flags. */
function adp_reply(string $query, array $answers, int $flags = 0x8400): string {
	$qend = 12;
	while (ord($query[$qend]) !== 0) { $qend += 1 + ord($query[$qend]); }
	$msg = substr($query, 0, 2) . pack('nnnnn', $flags, 1, count($answers), 0, 0) . substr($query, 12, $qend + 5 - 12);
	foreach ($answers as list($name, $type, $rdata)) {
		$msg .= adp_name($name) . pack('nnNn', $type, 1, 300, strlen($rdata)) . $rdata;
	}
	return $msg;
}

$txt = chr(11) . 'v=spf1 -all';
$name = 'example.test';
$ips = array('192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4', '192.0.2.5', '192.0.2.6', '192.0.2.7', '2001:db8::8');

/** What each address replies with. */
$behaviour = array(
	'192.0.2.1' => function ($q) use ($txt, $name) { return adp_reply($q, array(array($name, 16, $txt))); },
	'192.0.2.2' => function ($q) { return null; },
	'192.0.2.3' => function ($q) { return adp_reply($q, array(), 0x0400); },           // AA, not a response
	'192.0.2.4' => function ($q) { return adp_reply($q, array(), 0x8600); },           // truncated
	'192.0.2.5' => function ($q) { return adp_reply($q, array(), 0x8000); },           // not authoritative
	'192.0.2.6' => function ($q) { return adp_reply($q, array(), 0x8402); },           // SERVFAIL
	'192.0.2.7' => function ($q) use ($name) { return adp_reply($q, array(array($name, 5, adp_name('other.test')))); },
	'2001:db8::8' => function ($q) { return "\x00\x01"; },                              // too short: malformed
);

$asked = array();
AuthoritativeDns::reset();
AuthoritativeDns::setNameservers($name, $ips);
AuthoritativeDns::$sender = function (string $ip, string $packet) use (&$asked, $behaviour) {
	$asked[] = $ip;
	if ($ip === '192.0.2.2') { usleep(20000); }
	return $behaviour[$ip]($packet);
};

// ---------------------------------------------------------------------------
section('Each reason word comes out of the reply that causes it');

$p = AuthoritativeDns::probe($name, DNS_TXT, $name);
$expect = array(
	'192.0.2.1' => null, '192.0.2.2' => 'no_reply', '192.0.2.3' => 'not_response', '192.0.2.4' => 'truncated',
	'192.0.2.5' => 'not_authoritative', '192.0.2.6' => 'error_code', '192.0.2.7' => 'cname', '2001:db8::8' => 'malformed',
);
check(array_keys($p) === $ips, 'every address is asked, uncapped, in order, IPv6 included', implode(',', array_keys($p)));
foreach ($expect as $ip => $reason) {
	check(array_key_exists($ip, $p) && $p[$ip]['reason'] === $reason, $ip . ' reads as ' . var_export($reason, true),
		var_export($p[$ip] ?? null, true));
}
check(is_array($p['192.0.2.1']['records']) && ($p['192.0.2.1']['records'][0]['txt'] ?? '') === 'v=spf1 -all',
	'the clean answer carries its records');
$all_int = true;
foreach ($p as $row) { $all_int = $all_int && is_int($row['ms']) && $row['ms'] >= 0; }
check($all_int, 'every row has ms as a whole number');
check($p['192.0.2.2']['ms'] >= 15, 'ms is the wall time of that send', (string)$p['192.0.2.2']['ms']);
check($p['192.0.2.2']['records'] === null && $p['192.0.2.3']['records'] === null, 'records are null when not clean');

// ---------------------------------------------------------------------------
section('$skip leaves an address unasked');

$asked = array();
$p = AuthoritativeDns::probe($name, DNS_TXT, $name, array('192.0.2.2', '2001:db8::8'));
check(!isset($p['192.0.2.2']) && !isset($p['2001:db8::8']) && count($p) === 6, 'skipped addresses have no row');
check(!in_array('192.0.2.2', $asked, true), 'and nothing is sent to them');

// ---------------------------------------------------------------------------
section('lookupAll() over probe(): its shape unchanged');

$all = AuthoritativeDns::lookupAll($name, DNS_TXT, $name);
check(array_keys($all) === $ips, 'more than 6 addresses, IPv6 among them: every one answered for');
check(is_array($all['192.0.2.1']) && $all['192.0.2.2'] === null && $all['192.0.2.7'] === null,
	'ip => records or null, as before');
$all = AuthoritativeDns::lookupAll($name, DNS_TXT, $name, array('192.0.2.1'));
check(!array_key_exists('192.0.2.1', $all) && count($all) === 7, 'lookupAll() still honours $skip');
check(AuthoritativeDns::probe('', DNS_TXT, $name) === array() && AuthoritativeDns::lookupAll($name, 9999, $name) === array(),
	'a bad name or type is []');

AuthoritativeDns::reset();
section('needsTcp(): a UDP result asked again over TCP');
$q = AuthoritativeDns::query('home.example.org', 15, 0x4242);
check(AuthoritativeDns::needsTcp(null, 0), 'no reply at once (a firewall here refused the send) is asked over TCP');
check(!AuthoritativeDns::needsTcp(null, AuthoritativeDns::TIMEOUT_SECONDS * 1000), 'no reply after the whole timeout (a silent server) is not asked again');
check(AuthoritativeDns::needsTcp(adp_reply($q, array(), 0x8600), 3), 'a truncated reply is asked over TCP');
check(!AuthoritativeDns::needsTcp(adp_reply($q, array(), 0x8400), 3), 'a whole reply is kept');

harness_finish();
