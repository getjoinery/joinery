<?php
/** @joinery-test
 * name: pretend_registrar
 * tier: db
 * env: any
 * needs: []
 * covers: [plugins/server_manager/includes/domain_registrar/PretendRegistrar.php, plugins/server_manager/includes/domain_registrar/DomainRegistrarRegistry.php]
 */
/**
 * The pretend registrar answers the name check and refuses everything that
 * would buy or look up a real name. It is the only registrar offered while the
 * site's config file turns it on, and never offered otherwise.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

DomainRegistrarRegistry::reset();
DomainRegistrarRegistry::all();
$fake = new PretendRegistrar();

section('The name check');
$answer = $fake->checkAvailability(array(' Example.COM ', 'site-taken.com', ''));
check(count($answer) === 2, 'one answer per name, blanks skipped', json_encode(array_keys($answer)));
check(!empty($answer['example.com']['available']) && $answer['example.com']['price_year'] === PretendRegistrar::PRICE_YEAR
	&& $answer['example.com']['renew_year'] === PretendRegistrar::RENEW_YEAR, 'an ordinary name is free, with a price and a renewal');
check(empty($answer['site-taken.com']['available']) && $answer['site-taken.com']['price_year'] === null
	&& $answer['site-taken.com']['message'] !== '', 'a name containing taken is taken, and says so');
check($fake->normalizeRegistrantPhone('+1 555 123 4567') === NamecheapRegistrar::normalizePhone('+1 555 123 4567'),
	'phones are checked as the real registrar checks them');

section('Nothing real is bought or looked up');
foreach (array(
	'register'          => function () use ($fake) { $fake->register('example.com', array(), 1); },
	'applyWhoisPrivacy' => function () use ($fake) { $fake->applyWhoisPrivacy('example.com'); },
	'getExpiry'         => function () use ($fake) { $fake->getExpiry('example.com'); },
	'inAccount'         => function () use ($fake) { $fake->inAccount('example.com'); },
) as $name => $call) {
	$refused = null;
	try {
		$call();
	} catch (DomainRegistrarException $e) {
		$refused = $e;
	}
	check($refused !== null && !$refused->transient, "$name refuses, terminally");
}

section('Offered only when the config file turns it on');
$configured = DomainRegistrarRegistry::configured();
if (PretendRegistrar::on()) {
	check(array_keys($configured) === array('pretend'), 'on: it is the only registrar offered', json_encode(array_keys($configured)));
} else {
	check(!isset($configured['pretend']), 'off: it is not offered', json_encode(array_keys($configured)));
}
check(Globalvars::get_instance()->config_file_setting('registrar_pretend', false) === true || !PretendRegistrar::on(),
	'on() reads the config file alone');

harness_finish();
