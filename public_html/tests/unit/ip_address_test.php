<?php
/** @joinery-test
 * name: ip_address
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * IpAddress: addresses compared as addresses, IPv4 and IPv6 alike (project
 * rule, 2026-09-29). An IPv6 address has many spellings, an IPv4 can be
 * written IPv4-mapped, and anything that is not an address matches nothing.
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('same()');
check(IpAddress::same('203.0.113.7', '203.0.113.7'), 'the same IPv4');
check(!IpAddress::same('203.0.113.7', '203.0.113.78'), 'a different IPv4');
check(IpAddress::same('2001:DB8:0:0:0:0:0:5', '2001:db8::5'), 'IPv6 in its long and folded spellings, any case');
check(IpAddress::same('2001:db8::5/128', '[2001:db8::5]'), 'a /128 and brackets are ignored');
check(IpAddress::same('::ffff:203.0.113.7', '203.0.113.7'), 'an IPv4-mapped IPv6 is its IPv4');
check(!IpAddress::same('2001:db8::5', '2001:db8::6'), 'a different IPv6');
check(!IpAddress::same('', '') && !IpAddress::same('localhost', 'localhost') && !IpAddress::same('12:34:56', '12:34:56'),
	'nothing that is not an address matches, not even itself');
check(IpAddress::in('2001:DB8::5', array('198.51.100.1', '2001:db8:0::5')), 'in() finds an address in a list, by value');

section('isPublic()');
check(IpAddress::isPublic('2600:3c03::1') && IpAddress::isPublic('8.8.8.8'), 'a global IPv6 and a global IPv4 are public');
check(!IpAddress::isPublic('fe80::1') && !IpAddress::isPublic('fd00::1') && !IpAddress::isPublic('::1')
	&& !IpAddress::isPublic('10.0.0.1') && !IpAddress::isPublic('127.0.0.1'),
	'link-local, unique-local, loopback and private are not');

section('ipv6Tokens()');
$tokens = IpAddress::ipv6Tokens('Received: from relay ([2600:3c02::2000:16ff:fe09:883]) at 12:34:56; also ::1');
check(in_array('2600:3c02::2000:16ff:fe09:883', $tokens, true) && in_array('::1', $tokens, true),
	'finds the IPv6 addresses in a header line');
check(!in_array('12:34:56', $tokens, true), 'and not a clock time');

section('detectPublicIpv6()');
$mine = IpAddress::detectPublicIpv6();
check($mine === '' || (filter_var($mine, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && IpAddress::isPublic($mine)),
	'this machine\'s IPv6 is a public IPv6, or none', $mine);

harness_finish();
