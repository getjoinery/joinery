<?php
/** @joinery-test
 * name: session_network
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The session's network check (specs/ipv6_address_handling.md D2): a session
 * used from another network is a hijack indicator, in both families. An IPv4
 * /16 and an IPv6 /32 are one network; the session keeps one address per family,
 * letting the first use of the other family through; and
 * the address the check reads cannot be forged with a forwarding header.
 *
 * @version 1.1
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$control = (new ReflectionClass('SessionControl'))->newInstanceWithoutConstructor();
$is_change = new ReflectionMethod('SessionControl', '_is_major_ip_change');
$is_change->setAccessible(true);
$changed = function (string $stored, string $current) use ($control, $is_change): bool {
	return (bool)$is_change->invoke($control, $stored, $current);
};

section('IPv4: a /16 is one network');
check(!$changed('203.0.113.7', '203.0.113.7'), 'the same address');
check(!$changed('203.0.113.7', '203.0.9.200'), 'another address in the same /16');
check($changed('203.0.113.7', '203.1.113.7'), 'a different /16');

section('IPv6: a /32 is one network');
check(!$changed('2001:db8:1::1', '2001:db8:1:ffff::1'), 'same /32, different /64: stays signed in');
check(!$changed('2001:DB8::1', '2001:db8:0:0::1'), 'one address in two spellings');
check($changed('2001:db8::1', '2001:db9::1'), 'a different /32');

section('a change of family is not a change of network');
check(!$changed('203.0.113.7', '2001:db8::1') && !$changed('2001:db8::1', '203.0.113.7'),
	'IPv4 to IPv6 and back are let through');
check(!$changed('::ffff:192.0.2.1', '192.0.2.1') && !$changed('192.0.2.1', '::ffff:192.0.200.9'),
	'an IPv4 address written IPv4-mapped is IPv4');
check($changed('::ffff:192.0.2.1', '192.9.2.1'), 'and is compared as IPv4');
check(!$changed('', '203.0.113.7') && !$changed('garbage', '2001:db8::1'), 'nothing to compare is not a change');

section('one stored address per family');
$stored_for = new ReflectionMethod('SessionControl', '_stored_address_for');
$stored_for->setAccessible(true);
$session_before = $_SESSION ?? array();
$_SESSION = array('ip_address' => '203.0.113.7');
check($stored_for->invoke($control, '203.0.9.9') === '203.0.113.7', 'a request of the sign-in family is compared with the sign-in address');
check($stored_for->invoke($control, '2001:db8:1::1') === '2001:db8:1::1'
	&& ($_SESSION['ip_address_other_family'] ?? null) === '2001:db8:1::1',
	'the first request from the other family passes and its address is kept');
check($stored_for->invoke($control, '2001:db8:1:ffff::9') === '2001:db8:1::1'
	&& !$changed($stored_for->invoke($control, '2001:db8:1:ffff::9'), '2001:db8:1:ffff::9'),
	'a later IPv6 request in the same /32 is compared with it and passes');
check($changed($stored_for->invoke($control, '2001:db9::1'), '2001:db9::1'),
	'an IPv6 request from another /32 is now a change of network');
check($_SESSION['ip_address'] === '203.0.113.7' && $stored_for->invoke($control, '203.0.113.50') === '203.0.113.7',
	'the sign-in address is never replaced');
$_SESSION = array();
check($stored_for->invoke($control, '203.0.113.7') === null, 'no stored address: nothing to compare');
$_SESSION = $session_before;

section('the address the check reads');
$server_before = $_SERVER;
$client = new ReflectionMethod('SessionControl', '_get_client_ip');
$client->setAccessible(true);

$_SERVER['REMOTE_ADDR'] = '198.51.100.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::7';
check($client->invoke($control) === '198.51.100.5',
	'forged X-Forwarded-For and CF-Connecting-IP from a peer that is not a Cloudflare edge are ignored',
	(string)$client->invoke($control));

$edge = null;
foreach (SessionControl::cloudflare_edge_ranges() as $range) {
	if (strpos($range, ':') === false) {
		$edge = long2ip(ip2long(explode('/', $range)[0]) + 1);
		break;
	}
}
if ($edge !== null) {
	$_SERVER['REMOTE_ADDR'] = $edge;
	check($client->invoke($control) === '2001:db8::7', 'CF-Connecting-IP is believed from a Cloudflare edge', $edge);
}
$_SERVER = $server_before;

harness_finish();
