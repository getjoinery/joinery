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
 * @version 1.1
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

section('family() and network()');
check(IpAddress::family('203.0.113.7') === 4 && IpAddress::family('::ffff:203.0.113.7') === 4
	&& IpAddress::family('2001:db8::1') === 6 && IpAddress::family('nope') === 0,
	'IPv4, IPv4-mapped (as IPv4), IPv6, and not an address');
check(IpAddress::network('203.0.113.7') === '203.0.113.7', 'an IPv4 address is its own unit');
check(IpAddress::network('2001:DB8:1:2:aaaa:bbbb:cccc:dddd') === '2001:db8:1:2::/64'
	&& IpAddress::network('2001:db8:1:2::ffff') === '2001:db8:1:2::/64',
	'two IPv6 addresses in one /64 are one unit, in canonical text');
check(IpAddress::network('2001:db8:1:3::1') !== IpAddress::network('2001:db8:1:2::1'), 'the next /64 is another unit');
check(IpAddress::network('::ffff:192.0.2.1') === '192.0.2.1'
	&& IpAddress::network('::ffff:192.0.2.1') !== IpAddress::network('::ffff:198.51.100.7'),
	'IPv4-mapped callers are their IPv4 addresses, not one shared ::/64');
check(IpAddress::network('') === 'unknown' && IpAddress::network('garbage') === 'unknown', 'not an address is "unknown"');
check(strlen(IpAddress::network('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')) <= 45, 'the widest unit fits a varchar(45)');

section('inPrefix()');
check(IpAddress::inPrefix('2001:db8:abcd:123f::1', '2001:db8:abcd:1230::/60')
	&& !IpAddress::inPrefix('2001:db8:abcd:1240::1', '2001:db8:abcd:1230::/60'),
	'a /60 admits exactly a /60 (not a multiple of eight bits)');
check(IpAddress::inPrefix('198.51.100.200', '198.51.100.128/25') && !IpAddress::inPrefix('198.51.100.127', '198.51.100.128/25'),
	'an IPv4 /25');
check(IpAddress::inPrefix('2001:db8::1', '::/0') && IpAddress::inPrefix('8.8.8.8', '0.0.0.0/0')
	&& !IpAddress::inPrefix('8.8.8.8', '::/0') && !IpAddress::inPrefix('2001:db8::1', '0.0.0.0/0'),
	'a /0 admits its whole family and nothing of the other');
check(IpAddress::inPrefix('2001:db8::1', '2001:db8::1/128') && !IpAddress::inPrefix('2001:db8::2', '2001:db8::1/128')
	&& IpAddress::inPrefix('192.0.2.1', '192.0.2.1/32') && !IpAddress::inPrefix('192.0.2.2', '192.0.2.1/32'),
	'a /128 and an IPv4 /32 admit exactly one address');
check(IpAddress::inPrefix('2001:DB8:ABCD::1', '2001:db8:abcd::/48') && IpAddress::inPrefix('2001:db8:abcd::1', '2001:DB8:ABCD::/48'),
	'case does not matter on either side');
check(IpAddress::inPrefix('192.0.2.9', '::ffff:192.0.2.0/120') && IpAddress::inPrefix('::ffff:192.0.2.9', '192.0.2.0/24'),
	'IPv4-mapped addresses and prefixes are read as IPv4');
check(!IpAddress::inPrefix('192.0.2.1', '192.0.2.0/33') && !IpAddress::inPrefix('2001:db8::1', '2001:db8::/129')
	&& !IpAddress::inPrefix('192.0.2.1', '192.0.2.0/abc') && !IpAddress::inPrefix('192.0.2.1', 'nope/8'),
	'a malformed prefix matches nothing');
check(IpAddress::inPrefix('192.0.2.1', '192.0.2.1') && !IpAddress::inPrefix('192.0.2.1', '192.0.2.2'), 'a bare address is an exact match');

section('sharePrefix()');
check(IpAddress::sharePrefix('2001:db8:1::1', '2001:db8:1:ffff::1', 16, 32) === true, 'same /32, different /64: shared');
check(IpAddress::sharePrefix('2001:db8::1', '2001:db9::1', 16, 32) === false, 'a different /32: not shared');
check(IpAddress::sharePrefix('203.0.113.7', '203.0.9.9', 16, 32) === true
	&& IpAddress::sharePrefix('203.0.113.7', '203.1.113.7', 16, 32) === false, 'IPv4 compared at its own length');
check(IpAddress::sharePrefix('::ffff:192.0.2.1', '192.0.200.1', 16, 32) === true, 'IPv4-mapped is IPv4');
check(IpAddress::sharePrefix('192.0.2.1', '2001:db8::1', 16, 32) === null && IpAddress::sharePrefix('x', '192.0.2.1', 16, 32) === null,
	'different families, or not an address: no answer');

section('matchesEntry()');
check(IpAddress::matchesEntry('2001:db8:0:0::1', '2001:DB8::1'), 'an address entry matches any spelling');
check(IpAddress::matchesEntry('2001:db8:abcd:1::5', '2001:db8:abcd:1::/64') && !IpAddress::matchesEntry('2001:db8:abcd:2::5', '2001:db8:abcd:1::/64'),
	'an IPv6 prefix entry');
check(IpAddress::matchesEntry('104.23.253.9', '104.23.*') && IpAddress::matchesEntry('104.23.253.9', '104.23.253.*')
	&& !IpAddress::matchesEntry('104.24.1.1', '104.23.*'), 'the IPv4 wildcard form keeps working');
check(!IpAddress::matchesEntry('2001:db8::1', '104.23.*') && !IpAddress::matchesEntry('2001:db8::1', '192.0.2.0/24')
	&& !IpAddress::matchesEntry('192.0.2.1', '2001:db8::/32'), 'an entry of the other family is not a match');
check(IpAddress::matchesEntry('::ffff:104.23.1.1', '104.23.*'), 'an IPv4-mapped caller matches its IPv4 wildcard');
check(!IpAddress::matchesEntry('192.0.2.1', '') && !IpAddress::matchesEntry('', '192.0.2.1'), 'empty matches nothing');
check(IpAddress::matchesEntry('104.25.9.9', '104.2*') && IpAddress::matchesEntry('104.2.9.9', '104.2*')
	&& !IpAddress::matchesEntry('104.3.9.9', '104.2*'), 'a partial-octet wildcard keeps matching as it always has');
check(IpAddress::matchesEntry('2001:db8::1', '*') && IpAddress::matchesEntry('192.0.2.1', '*.*.*.*'),
	'an entry of stars alone admits every address, IPv6 included');

section('entryProblem()');
foreach (array('192.0.2.1', '2001:db8::1', '192.0.2.0/24', '2001:db8:abcd::/48', '104.23.*', '104.23.253.*', '::/0', '104.2*', '*') as $ok) {
	check(IpAddress::entryProblem($ok) === null, "accepted: $ok");
}
foreach (array('2001:db8::/129', '192.0.2.0/33', 'fe80::1%eth0', '[2001:db8::1]', '10.0.0.300', '', '192.0.2.0/', '104.23.*.x', 'example.com',
	'::ffff:0:0/96', '::ffff:1.2.3.4/90', '1*2.3.4.5', '104.256.*') as $bad) {
	$problem = IpAddress::entryProblem($bad);
	check($problem !== null && ($bad === '' || strpos($problem, $bad) !== false), "refused, naming it: '$bad'", (string)$problem);
}

section('detectPublicIpv6()');
$mine = IpAddress::detectPublicIpv6();
check($mine === '' || (filter_var($mine, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && IpAddress::isPublic($mine)),
	'this machine\'s IPv6 is a public IPv6, or none', $mine);

harness_finish();
