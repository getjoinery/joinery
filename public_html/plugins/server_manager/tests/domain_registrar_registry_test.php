<?php
/** @joinery-test
 * name: domain_registrar_registry
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The registrar registry, and the name gates every checkout surface shares.
 *
 * Discovery is interface-based on purpose: adding a second registrar has to be
 * a class in one directory and nothing else, or the seam is decorative. So the
 * test asserts that nothing anywhere names a registrar in a list — the shipped
 * one is found by walking declared classes for the interface — and that
 * configured() stays empty on a deployment with no credentials, which is the
 * gate the checkout field reads before it offers to sell anything.
 *
 * The name gates matter for a different reason. The checkout field, the live
 * availability action and the provisioning phase all have to agree about what
 * is even askable; three separate copies of a regex would drift, and the drift
 * would show up as a name quoted at checkout and refused at registration.
 *
 * Sections: discovery; configuration gate; name normalization; the TLD gate.
 *
 * Run: php plugins/server_manager/tests/domain_registrar_registry_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php'));

section('Discovery finds registrars by interface, not by list');

DomainRegistrarRegistry::reset();
$all = DomainRegistrarRegistry::all();
check(isset($all['namecheap']), 'the shipped Namecheap registrar is discovered');
check(($all['namecheap'] ?? '') === 'NamecheapRegistrar', 'it is keyed by getKey(), not by filename',
	'got: ' . var_export($all['namecheap'] ?? null, true));
foreach ($all as $key => $class) {
	check(in_array('DomainRegistrarProvider', class_implements($class) ?: array(), true),
		$class . ' implements the seam');
	check($class::getLabel() !== '', $class . ' has a human label');
}

$options = DomainRegistrarRegistry::options();
check(($options['namecheap'] ?? '') === 'Namecheap', 'options() is key => label for a chooser');

DomainRegistrarRegistry::reset();
check(count(DomainRegistrarRegistry::all()) === count($all), 'reset() re-scans to the same set');

section('A registrar with no credentials is not offered');

$configured = DomainRegistrarRegistry::configured();
$namecheap_ready = NamecheapRegistrar::isConfigured();
check($namecheap_ready === isset($configured['namecheap']),
	'configured() reflects isConfigured(), not mere presence');
if (!$namecheap_ready) {
	check(DomainRegistrarRegistry::firstConfigured() === null,
		'firstConfigured() is null with no credentials — the checkout gate closes');
} else {
	check(DomainRegistrarRegistry::firstConfigured() instanceof DomainRegistrarProvider,
		'firstConfigured() hands back a usable instance');
}

section('A typed name is normalized the same way everywhere');

$cases = array(
	'SmithFamily.COM'          => 'smithfamily.com',
	'  smithfamily.com  '      => 'smithfamily.com',
	'https://smithfamily.com/' => 'smithfamily.com',
	'http://smithfamily.com'   => 'smithfamily.com',
	'smithfamily.com.'         => 'smithfamily.com',
	'smithfamily.com/about'    => 'smithfamily.com',
);
foreach ($cases as $raw => $expected) {
	check(DomainRegistrarRegistry::normalizeName($raw) === $expected,
		'"' . $raw . '" normalizes to ' . $expected,
		'got: ' . DomainRegistrarRegistry::normalizeName($raw));
}

section('Only a registrable name gets through');

foreach (array('smithfamily.com', 'a-b.co.uk', 'x1.example.org') as $good) {
	check(DomainRegistrarRegistry::isRegistrableName($good), $good . ' is registrable');
}
foreach (array('', 'nodot', '-lead.com', 'trail-.com', 'x.c', 'has space.com',
		'under_score.com', 'double..dot.com') as $bad) {
	check(!DomainRegistrarRegistry::isRegistrableName($bad),
		var_export($bad, true) . ' is refused');
}

section('The TLD gate follows the setting');

$tlds = DomainRegistrarRegistry::offeredTlds();
check(count($tlds) > 0, 'a deployment always offers something (com net org by default)');
check($tlds === array_values(array_unique($tlds)), 'the list is de-duplicated');
foreach ($tlds as $tld) {
	check(strpos($tld, '.') !== 0, '"' . $tld . '" carries no leading dot');
	check($tld === strtolower($tld), '"' . $tld . '" is lowercase');
}

$first = $tlds[0];
check(DomainRegistrarRegistry::tldOffered('example.' . $first), '.' . $first . ' is offered');
check(!DomainRegistrarRegistry::tldOffered('example.thistldisnotoffered'),
	'an ending outside the list is refused');
check(DomainRegistrarRegistry::tldOf('a.co.uk') === 'co.uk',
	'a multi-label ending is read whole', 'got: ' . DomainRegistrarRegistry::tldOf('a.co.uk'));

$phrase = DomainRegistrarRegistry::offeredTldsPhrase();
check(strpos($phrase, '.' . $first) !== false, 'the phrase names the offered endings');
if (count($tlds) > 1) {
	check(strpos($phrase, ' or ') !== false, 'the phrase reads as a sentence, not a CSV dump',
		'got: ' . $phrase);
}

harness_finish();
