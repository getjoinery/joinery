<?php
/** @joinery-test
 * name: setup_https_address_plan
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The setup wizard's Secure connection screen can write the site's own
 * address record through the domain's DNS host. setup_https_address_plan()
 * decides whether that is safe to offer: a plan for a name that answers
 * nothing (or already answers this server), none for a name that answers
 * from somewhere else — pointing it here would replace whatever is there.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('logic/setup_https_check_logic.php'));

section('A name that answers nothing gets a plan for this server');
$plan = setup_https_address_plan('home.smith.org', array(), array(), '198.51.100.7', '');
check($plan !== null && $plan->count() === 1, 'one record when the server has only an IPv4 address', $plan ? $plan->count() : 'null');
$records = $plan ? $plan->getRecords() : array();
check($records && $records[0]->type === DnsRecord::TYPE_A && $records[0]->name === 'home.smith.org' && $records[0]->value === '198.51.100.7',
	'the record is an A at the site name pointing at the server', json_encode($records ? $records[0]->toArray() : null));

$plan = setup_https_address_plan('Smith.ORG.', array(), array(), '198.51.100.7', '2001:db8::7');
check($plan !== null && $plan->count() === 2 && $plan->getDomain() === 'smith.org', 'A and AAAA when the server has both, name normalised', $plan ? $plan->getDomain() : 'null');

section('A name already pointing at this server needs nothing');
$plan = setup_https_address_plan('smith.org', array('198.51.100.7'), array(), '198.51.100.7', '');
check($plan !== null && $plan->count() === 1, 'a plan still comes back (the box reports it settled); the caller skips it on dns_match');

section('A name that answers from somewhere else is never offered');
check(setup_https_address_plan('smith.org', array('203.0.113.10'), array(), '198.51.100.7', '') === null,
	'an A record for another server withholds the plan');
check(setup_https_address_plan('smith.org', array(), array('2001:db8::99'), '198.51.100.7', '2001:db8::7') === null,
	'an AAAA record for another server withholds the plan');
check(setup_https_address_plan('smith.org', array('198.51.100.7', '203.0.113.10'), array(), '198.51.100.7', '') === null,
	'this server plus another address is still in use elsewhere');

section('Nothing to offer without a public address or a real name');
check(setup_https_address_plan('smith.org', array(), array(), '', '') === null, 'no public address, no plan');
check(setup_https_address_plan('localhost', array(), array(), '198.51.100.7', '') === null, 'localhost is not a domain');
check(setup_https_address_plan('198.51.100.7', array(), array(), '198.51.100.7', '') === null, 'an IP is not a domain');
check(setup_https_address_plan('', array(), array(), '198.51.100.7', '') === null, 'an empty name is not a domain');

harness_finish();
