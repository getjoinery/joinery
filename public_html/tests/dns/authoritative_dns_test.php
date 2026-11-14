<?php
/** @joinery-test
 * name: authoritative_dns
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * Asking a zone's own nameservers (AuthoritativeDns, DnsResolver::preferAuthoritative).
 *
 * The defect: right after a publish, this box's resolver keeps the old answers
 * for their TTL, or "no such record" for up to 30 minutes for a name looked up
 * before it existed. Every check read through it said a finished domain was
 * broken (specs/send_protection_one_press.md WP0). These checks stand a fake
 * nameserver in for the real ones, so nothing here touches the network.
 *
 * @version 1.1 - lookupAll()
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
require_once(PathHelper::getIncludePath('includes/dns/AuthoritativeDns.php'));

/** A name in wire format. */
function ad_name(string $name): string {
	$out = '';
	foreach (explode('.', rtrim($name, '.')) as $l) { $out .= chr(strlen($l)) . $l; }
	return $out . "\0";
}

/**
 * A reply to $query. $answers: [[name, type, rdata]], or rdata given as
 * ['ptr' => offset-of-name-in-this-message] is not needed: a test that wants
 * compression builds it by hand. $flags defaults to response + AA.
 */
function ad_reply(string $query, array $answers, int $flags = 0x8400, int $rcode = 0): string {
	$id = substr($query, 0, 2);
	$qend = 12;
	while (ord($query[$qend]) !== 0) { $qend += 1 + ord($query[$qend]); }
	$question = substr($query, 12, $qend + 5 - 12);
	$msg = $id . pack('nnnnn', $flags | $rcode, 1, count($answers), 0, 0) . $question;
	foreach ($answers as $a) {
		list($name, $type, $rdata) = $a;
		$msg .= ad_name($name) . pack('nnNn', $type, 1, 300, strlen($rdata)) . $rdata;
	}
	return $msg;
}

function ad_txt(string ...$strings): string {
	$out = '';
	foreach ($strings as $s) { $out .= chr(strlen($s)) . $s; }
	return $out;
}

/** Run with a fake nameserver for example.test that answers through $answer(qname, qtype, query). */
function ad_with(callable $answer): void {
	AuthoritativeDns::reset();
	AuthoritativeDns::setNameservers('example.test', array('192.0.2.53', '192.0.2.54'));
	AuthoritativeDns::$sender = function ($ip, $packet) use ($answer) {
		$qend = 12; $labels = array();
		while (ord($packet[$qend]) !== 0) {
			$labels[] = substr($packet, $qend + 1, ord($packet[$qend]));
			$qend += 1 + ord($packet[$qend]);
		}
		$qtype = unpack('n', substr($packet, $qend + 1, 2))[1];
		return $answer(implode('.', $labels), $qtype, $packet, $ip);
	};
}

// ---------------------------------------------------------------------------
section('The query asks for no recursion and carries EDNS0, so a DKIM record fits in UDP');

$q = AuthoritativeDns::query('mailk1._domainkey.example.test', 16, 0x1234);
$h = unpack('nid/nflags/nqd/nan/nns/nar', substr($q, 0, 12));
check($h['id'] === 0x1234 && $h['qd'] === 1, 'one question under the given id');
check(($h['flags'] & 0x0100) === 0, 'recursion is not asked of an authoritative server');
check($h['ar'] === 1 && strpos($q, pack('nn', 41, AuthoritativeDns::EDNS_PAYLOAD)) !== false,
	'an OPT record advertises a payload larger than 512 bytes');
check(AuthoritativeDns::query('a..b', 16) === null, 'an empty label is not a name');

// ---------------------------------------------------------------------------
section('A clean authoritative answer is read in dns_get_record\'s shape');

$long = str_repeat('A', 255);
ad_with(function ($name, $type, $packet) use ($long) {
	if ($name === 'mailk1._domainkey.example.test' && $type === 16) {
		return ad_reply($packet, array(array($name, 16, ad_txt('v=DKIM1; k=rsa; p=' . substr($long, 0, 237), 'BBBB'))));
	}
	if ($name === 'example.test' && $type === 15) {
		// MX target compressed: points at the question name (offset 12) under a "relay" label.
		$rdata = pack('n', 10) . chr(5) . 'relay' . "\xc0\x0c";
		return ad_reply($packet, array(array($name, 15, $rdata)));
	}
	return ad_reply($packet, array(), 0x8400, 3);
});
$txt = AuthoritativeDns::lookup('mailk1._domainkey.example.test', DNS_TXT, 'example.test');
check(is_array($txt) && count($txt) === 1 && $txt[0]['txt'] === 'v=DKIM1; k=rsa; p=' . substr($long, 0, 237) . 'BBBB',
	'a TXT record split across strings is joined, as dns_get_record joins it');
check(isset($txt[0]['entries']) && count($txt[0]['entries']) === 2 && $txt[0]['host'] === 'mailk1._domainkey.example.test',
	'with its strings and owner name kept');
$mx = AuthoritativeDns::lookup('example.test', DNS_MX, 'example.test');
check(is_array($mx) && ($mx[0]['target'] ?? '') === 'relay.example.test' && ($mx[0]['pri'] ?? 0) === 10,
	'an MX target behind a compression pointer is read in full');
check(AuthoritativeDns::lookup('nope.example.test', DNS_TXT, 'example.test') === array(),
	'NXDOMAIN from the zone itself is an answer: no such record');

// ---------------------------------------------------------------------------
section('Anything short of a clean authoritative answer falls back');

ad_with(function ($name, $type, $packet) {
	if ($name === 'noaa.example.test')   { return ad_reply($packet, array(array($name, 16, ad_txt('x'))), 0x8000); }
	if ($name === 'tc.example.test')     { return ad_reply($packet, array(), 0x8600); }
	if ($name === 'cname.example.test')  { return ad_reply($packet, array(array($name, 5, ad_name('dkim.provider.example')))); }
	if ($name === 'servfail.example.test') { return ad_reply($packet, array(), 0x8400, 2); }
	return null;
});
check(AuthoritativeDns::lookup('noaa.example.test', DNS_TXT, 'example.test') === null,
	'no AA bit (a referral to a delegated subzone, or a cache) falls back');
check(AuthoritativeDns::lookup('tc.example.test', DNS_TXT, 'example.test') === null, 'a truncated reply falls back');
check(AuthoritativeDns::lookup('cname.example.test', DNS_TXT, 'example.test') === null,
	'a CNAME standing in for a TXT falls back: its target may be in another zone');
check(AuthoritativeDns::lookup('servfail.example.test', DNS_TXT, 'example.test') === null, 'SERVFAIL falls back');
check(AuthoritativeDns::lookup('silent.example.test', DNS_TXT, 'example.test') === null,
	'nameservers that never answer fall back');

$asked = 0;
ad_with(function ($name, $type, $packet) use (&$asked) { $asked++; return null; });
AuthoritativeDns::lookup('a.example.test', DNS_TXT, 'example.test');
AuthoritativeDns::lookup('b.example.test', DNS_TXT, 'example.test');
check($asked === 2, 'a zone whose two nameservers did not answer is not asked again in the same request');

// ---------------------------------------------------------------------------
section('Every fallback is logged once per zone and reason, naming the zone only');

$ad_log = tempnam(sys_get_temp_dir(), 'adlog');
$ad_prev_log = ini_get('error_log');
ini_set('error_log', $ad_log);
ad_with(function ($name, $type, $packet) {
	if (strpos($name, 'cname') === 0) { return ad_reply($packet, array(array($name, 5, ad_name('dkim.provider.example')))); }
	if (strpos($name, 'tc') === 0)    { return ad_reply($packet, array(), 0x8600); }
	if (strpos($name, 'noaa') === 0)  { return ad_reply($packet, array(array($name, 16, ad_txt('secret-value'))), 0x8000); }
	return null;
});
AuthoritativeDns::lookup('cname1.example.test', DNS_TXT, 'example.test');
AuthoritativeDns::lookup('cname2.example.test', DNS_TXT, 'example.test');
AuthoritativeDns::lookup('tc.example.test', DNS_TXT, 'example.test');
AuthoritativeDns::lookup('noaa.example.test', DNS_TXT, 'example.test');
AuthoritativeDns::lookup('silent.example.test', DNS_TXT, 'example.test');
AuthoritativeDns::reset();
AuthoritativeDns::setNameservers('nons.test', array());
AuthoritativeDns::lookup('x.nons.test', DNS_TXT, 'nons.test');
ini_set('error_log', $ad_prev_log === false ? '' : $ad_prev_log);
$ad_lines = array_values(array_filter(array_map(function ($l) {
	$at = strpos($l, AuthoritativeDns::FALLBACK_LOG_PREFIX);
	return $at === false ? null : substr($l, $at);
}, (array)file($ad_log, FILE_IGNORE_NEW_LINES))));
@unlink($ad_log);
$ad_prefix = AuthoritativeDns::FALLBACK_LOG_PREFIX;
check($ad_lines === array(
		$ad_prefix . 'example.test: CNAME answer',
		$ad_prefix . 'example.test: reply truncated',
		$ad_prefix . 'example.test: reply not authoritative',
		$ad_prefix . 'example.test: no nameserver answered',
		$ad_prefix . 'nons.test: no nameserver addresses found',
	), 'one line per zone and reason, in the order met, with two CNAME answers logged once', implode("\n", $ad_lines));
check(strpos(implode("\n", $ad_lines), 'secret-value') === false && strpos(implode("\n", $ad_lines), 'cname1') === false,
	'a line names the zone, never the record asked for or its value');
$ad_reasons = array();
foreach ($ad_lines as $l) { $ad_reasons[] = substr($l, strrpos($l, ': ') + 2); }
check(!array_diff($ad_reasons, AuthoritativeDns::FALLBACK_REASONS), 'every reason is from the closed set');

// ---------------------------------------------------------------------------
section('lookupAll asks every nameserver, and a silent one marks nothing dead');

ad_with(function ($name, $type, $packet, $ip) {
	if ($ip === '192.0.2.54') { return null; }
	return ad_reply($packet, array(array($name, 16, ad_txt('v=DKIM1; p=AAAA'))));
});
$all = AuthoritativeDns::lookupAll('mailk1._domainkey.example.test', DNS_TXT, 'example.test');
check(count($all) === 2 && ($all['192.0.2.53'][0]['txt'] ?? '') === 'v=DKIM1; p=AAAA' && $all['192.0.2.54'] === null,
	'one answer per nameserver, null for the one that did not reply');
$again = AuthoritativeDns::lookupAll('mailk1._domainkey.example.test', DNS_TXT, 'example.test');
check(count($again) === 2, 'asked again: a missed reply does not stop the next poll');

// ---------------------------------------------------------------------------
section('DnsResolver asks a registered zone first, and only that zone');

$seen = array();
ad_with(function ($name, $type, $packet) use (&$seen) {
	$seen[] = $name;
	if ($name === 'example.test' && $type === 16) {
		return ad_reply($packet, array(array($name, 16, ad_txt('v=spf1 -all'))));
	}
	return ad_reply($packet, array(), 0x8400, 3);
});
DnsResolver::clearBackend();
DnsResolver::clearAuthoritative();
DnsResolver::preferAuthoritative('example.test');
check(DnsResolver::getTxt('example.test') === array('v=spf1 -all'),
	'a registered zone\'s record is the zone\'s own answer, which no cache can hold back');
check(DnsResolver::getTxt('_dmarc.example.test') === array() && in_array('_dmarc.example.test', $seen, true),
	'names under the zone are asked of it too');
$seen = array();
try { DnsResolver::getTxt('unrelated.invalid'); } catch (Throwable $e) { /* the system resolver's answer is not under test */ }
check(!in_array('unrelated.invalid', $seen, true), 'a name outside every registered zone never reaches the zone\'s nameservers');

// A test double still wins, so every suite that fakes DNS keeps its fake.
$double = new class { public function getRecords($n, $t) { return array(array('txt' => 'from the double')); } };
DnsResolver::setBackend($double);
check(DnsResolver::getTxt('example.test') === array('from the double'), 'a test double set with setBackend() still wins');
DnsResolver::clearBackend();
DnsResolver::clearAuthoritative();
AuthoritativeDns::reset();

harness_finish();
