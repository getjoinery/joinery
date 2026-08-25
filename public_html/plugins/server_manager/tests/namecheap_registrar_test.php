<?php
/** @joinery-test
 * name: namecheap_registrar
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The Namecheap registrar driver, against a mocked transport. No live calls in
 * any tier — a passing test must never buy a domain.
 *
 * Three things here are worth more than the rest:
 *
 *  - **The buyer is the registrant.** Namecheap wants four contact sets and
 *    fills them from the account if you let it. A create call that quietly
 *    named the operator would produce a working domain the buyer does not
 *    legally own, which is the one promise this whole feature makes. So the
 *    test reads the outgoing parameters and asserts all four are the buyer's.
 *  - **Transient versus terminal.** A 500 retried next tick costs nothing; a
 *    refusal retried forever spams a registrar with a request it will always
 *    decline, and a transient failure treated as terminal parks a paid order
 *    for a human over a five-second blip. Both directions are asserted.
 *  - **"Not in this account" is a value, not an error.** It is the signal that
 *    a hand-over completed. Thrown instead of returned, graduation would never
 *    be noticed and the buyer would keep being told to finish a step they had
 *    already finished.
 *
 * Sections: availability parsing; pricing; registration parameters; expiry and
 * custody; error classification; the sandbox switch; phone normalization.
 *
 * Run: php plugins/server_manager/tests/namecheap_registrar_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/domain_registrar/NamecheapRegistrar.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;

// Credentials the driver needs to think it is configured. In memory only.
harness_set_setting_mem('server_manager_namecheap_api_user', 'testoperator');
harness_set_setting_mem('server_manager_namecheap_api_key', 'not-a-real-key');
harness_set_setting_mem('server_manager_namecheap_client_ip', '203.0.113.10');
harness_set_setting_mem('server_manager_namecheap_sandbox', '');

/** A registrar wired to a scripted queue of responses, plus the request log. */
function ncp_driver(array $responses): array {
	$mock = new MockHandler($responses);
	$stack = HandlerStack::create($mock);
	// ArrayObject, not a plain array: the history middleware appends through
	// the reference it captured, and returning a copied array would hand back
	// the empty snapshot taken here.
	$log = new ArrayObject();
	$stack->push(Middleware::history($log));
	return array(new NamecheapRegistrar(new Client(array('handler' => $stack))), $log);
}

/** The query/form parameters of a logged request. */
function ncp_params($transaction): array {
	$request = $transaction['request'];
	$query = array();
	parse_str((string)$request->getUri()->getQuery(), $query);
	$body = (string)$request->getBody();
	if ($body !== '') {
		$form = array();
		parse_str($body, $form);
		$query = array_merge($query, $form);
	}
	return $query;
}

function ncp_xml(string $inner, string $status = 'OK'): Response {
	return new Response(200, array('Content-Type' => 'text/xml'),
		'<?xml version="1.0" encoding="utf-8"?>'
		. '<ApiResponse Status="' . $status . '" xmlns="">' . $inner . '</ApiResponse>');
}

function ncp_pricing_xml(string $tld, string $your_price): string {
	return '<CommandResponse Type="namecheap.users.getPricing"><UserGetPricingResult>'
		. '<ProductType Name="domains"><ProductCategory Name="register">'
		. '<Product Name="' . $tld . '">'
		. '<Price Duration="1" DurationType="YEAR" Price="13.98" RegularPrice="13.98" '
		. 'YourPrice="' . $your_price . '" Currency="USD"/>'
		. '</Product></ProductCategory></ProductType></UserGetPricingResult></CommandResponse>';
}

// ---------------------------------------------------------------------------
section('Availability, read from the registry answer');

list($driver, $log) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.check">'
		. '<DomainCheckResult Domain="free-name.com" Available="true" ErrorNo="0" '
		. 'IsPremiumName="false" IcannFee="0.18"/>'
		. '<DomainCheckResult Domain="taken-name.com" Available="false" ErrorNo="0" '
		. 'IsPremiumName="false" IcannFee="0.18"/>'
		. '</CommandResponse>'),
	ncp_xml(ncp_pricing_xml('com', '10.28')),
));
$answers = $driver->checkAvailability(array('free-name.com', 'taken-name.com'));

check(!empty($answers['free-name.com']['available']), 'a free name reads as available');
check(empty($answers['taken-name.com']['available']), 'a taken name reads as unavailable');
check($answers['free-name.com']['price_year'] === '10.46',
	'the price is the wholesale price plus the ICANN fee',
	'got: ' . var_export($answers['free-name.com']['price_year'], true));
check($answers['taken-name.com']['price_year'] === null, 'a taken name is not priced');
check(trim((string)$answers['taken-name.com']['message']) !== '',
	'a taken name says so in words the buyer can read');
check(count($log) === 2, 'one check call plus one pricing call', 'calls: ' . count($log));
check(ncp_params($log[0])['DomainList'] === 'free-name.com,taken-name.com',
	'both names go in one check call');

section('A name the API said nothing about is never assumed free');

list($driver) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.check"></CommandResponse>'),
));
$answers = $driver->checkAvailability(array('silent-name.com'));
check(isset($answers['silent-name.com']), 'the name still gets an answer row');
check(empty($answers['silent-name.com']['available']),
	'silence is unavailable — never "sure, sell it"');

section('A premium name is declined, and says why');

list($driver) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.check">'
		. '<DomainCheckResult Domain="jewel.com" Available="true" ErrorNo="0" '
		. 'IsPremiumName="true" PremiumRegistrationPrice="4500.00" IcannFee="0.18"/>'
		. '</CommandResponse>'),
));
$answers = $driver->checkAvailability(array('jewel.com'));
check(empty($answers['jewel.com']['available']), 'a premium name is not sold');
check(!empty($answers['jewel.com']['premium']), 'it is reported as premium, not merely taken');
check(stripos((string)$answers['jewel.com']['message'], 'premium') !== false,
	'the message names the real reason');

section('The per-TLD price is looked up once, then reused');

list($driver, $log) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.check">'
		. '<DomainCheckResult Domain="one.com" Available="true" ErrorNo="0" IsPremiumName="false" IcannFee="0"/>'
		. '<DomainCheckResult Domain="two.com" Available="true" ErrorNo="0" IsPremiumName="false" IcannFee="0"/>'
		. '</CommandResponse>'),
	ncp_xml(ncp_pricing_xml('com', '9.00')),
));
$answers = $driver->checkAvailability(array('one.com', 'two.com'));
check($answers['one.com']['price_year'] === '9.00' && $answers['two.com']['price_year'] === '9.00',
	'both .com names get the same price');
check(count($log) === 2, 'the pricing endpoint is called once for the TLD, not once per name',
	'calls: ' . count($log));

// ---------------------------------------------------------------------------
section('Registration names the BUYER as owner, in all four contact sets');

$buyer = array(
	'first_name' => 'Jane', 'last_name' => 'Smith',
	'address1' => '14 Elm Street', 'city' => 'Springfield',
	'state_province' => 'IL', 'postal_code' => '62704',
	'country' => 'us', 'phone' => '+1 555 123 4567',
	'email' => 'jane@example.com',
);

list($driver, $log) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.create">'
		. '<DomainCreateResult Domain="smithfamily.com" Registered="true" ChargedAmount="10.46" '
		. 'DomainID="1234" OrderID="99" TransactionID="88" WhoisguardEnable="true" '
		. 'NonRealTimeDomain="false"/></CommandResponse>'),
	ncp_xml('<CommandResponse Type="namecheap.domains.getInfo">'
		. '<DomainGetInfoResult Status="Ok" DomainName="smithfamily.com">'
		. '<DomainDetails><CreatedDate>08/25/2026</CreatedDate>'
		. '<ExpiredDate>08/25/2027</ExpiredDate></DomainDetails>'
		. '<Whoisguard Enabled="True"><ID>777</ID></Whoisguard>'
		. '</DomainGetInfoResult></CommandResponse>'),
));
$result = $driver->register('smithfamily.com', $buyer, 1);
$sent = ncp_params($log[0]);

check((string)$sent['Years'] === '1', 'exactly one year is bought — no upfront padding');
check(strtolower((string)$sent['AddFreeWhoisguard']) === 'yes', 'free WHOIS privacy is requested');
check(strtolower((string)$sent['WGEnabled']) === 'yes', 'and switched on in the same call');
foreach (array('Registrant', 'Tech', 'Admin', 'AuxBilling') as $role) {
	check(($sent[$role . 'FirstName'] ?? '') === 'Jane' && ($sent[$role . 'LastName'] ?? '') === 'Smith',
		$role . ' is the buyer, not the operator');
	check(($sent[$role . 'EmailAddress'] ?? '') === 'jane@example.com',
		$role . ' email is the buyer\'s');
	check(($sent[$role . 'Phone'] ?? '') === '+1.5551234567',
		$role . ' phone is normalized to +CC.NNNN', 'got: ' . ($sent[$role . 'Phone'] ?? ''));
	check(($sent[$role . 'Country'] ?? '') === 'US', $role . ' country is upper-case ISO-2');
}
check($result['expiry'] === '2027-08-25 00:00:00',
	'the expiry is read from the registrar, not computed from today',
	'got: ' . var_export($result['expiry'], true));

section('A registration the registrar did not confirm is a failure');

list($driver) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.create">'
		. '<DomainCreateResult Domain="smithfamily.com" Registered="false"/></CommandResponse>'),
));
$threw = null;
try { $driver->register('smithfamily.com', $buyer, 1); }
catch (DomainRegistrarException $e) { $threw = $e; }
check($threw !== null, 'Registered="false" throws rather than reporting success');
check($threw && $threw->transient === false, 'and it is terminal, not retried forever');

// ---------------------------------------------------------------------------
section('Expiry and custody');

list($driver) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.getInfo">'
		. '<DomainGetInfoResult Status="Ok" DomainName="smithfamily.com">'
		. '<DomainDetails><ExpiredDate>03/04/2028</ExpiredDate></DomainDetails>'
		. '</DomainGetInfoResult></CommandResponse>'),
));
$read_expiry = $driver->getExpiry('smithfamily.com');
check($read_expiry === '2028-03-04 00:00:00',
	'the m/d/Y expiry is stored as UTC', 'got: ' . var_export($read_expiry, true));

list($driver) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.getInfo">'
		. '<DomainGetInfoResult Status="Ok" DomainName="ours.com">'
		. '<DomainDetails><ExpiredDate>03/04/2028</ExpiredDate></DomainDetails>'
		. '</DomainGetInfoResult></CommandResponse>'),
));
check($driver->inAccount('ours.com') === true, 'a domain we hold reports in-account');

list($driver) = ncp_driver(array(
	ncp_xml('<Errors><Error Number="2019166">Domain not found</Error></Errors>', 'ERROR'),
));
$graduated = null;
try { $graduated = $driver->inAccount('gone.com'); }
catch (DomainRegistrarException $e) { $graduated = 'threw: ' . $e->getMessage(); }
check($graduated === false,
	'"domain not found" is the graduation signal — a value, never an exception',
	'got: ' . var_export($graduated, true));

list($driver) = ncp_driver(array(
	ncp_xml('<Errors><Error Number="2019166">Domain not found</Error></Errors>', 'ERROR'),
));
check($driver->getExpiry('gone.com') === null, 'a domain we no longer hold has no expiry to read');

section('Some other refusal is still a refusal');

list($driver) = ncp_driver(array(
	ncp_xml('<Errors><Error Number="1011102">API Key is invalid</Error></Errors>', 'ERROR'),
));
$threw = null;
try { $driver->inAccount('ours.com'); }
catch (DomainRegistrarException $e) { $threw = $e; }
check($threw !== null, 'a bad credential is not mistaken for a completed hand-over');
check($threw && strpos($threw->getMessage(), 'API Key is invalid') !== false,
	'the registrar\'s own words survive to the operator');

// ---------------------------------------------------------------------------
section('Transient and terminal are told apart');

$request = new Request('GET', 'https://api.namecheap.com/xml.response');
list($driver) = ncp_driver(array(
	new RequestException('server error', $request, new Response(500)),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && $threw->transient === true, 'a 500 is transient — retried next tick');

list($driver) = ncp_driver(array(
	new RequestException('rate limited', $request, new Response(429)),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && $threw->transient === true, 'a 429 is transient');

list($driver) = ncp_driver(array(
	new RequestException('connection refused', $request),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && $threw->transient === true, 'a network failure is transient');

list($driver) = ncp_driver(array(
	new RequestException('bad request', $request, new Response(400)),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && $threw->transient === false, 'a 400 is terminal — repeating it changes nothing');

list($driver) = ncp_driver(array(
	ncp_xml('<Errors><Error Number="1">Too many requests</Error></Errors>', 'ERROR'),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && $threw->transient === true, 'a rate-limit-shaped API error is transient too');

list($driver) = ncp_driver(array(
	ncp_xml('<Errors><Error Number="1011150">Parameter ClientIp is not a valid IP</Error></Errors>', 'ERROR'),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && $threw->transient === false, 'an ordinary API refusal is terminal');

list($driver) = ncp_driver(array(
	ncp_xml('<Errors><Error Number="1011147">IP not in whitelist</Error></Errors>', 'ERROR'),
));
$threw = null;
try { $driver->getExpiry('a.com'); } catch (DomainRegistrarException $e) { $threw = $e; }
check($threw && strpos($threw->getMessage(), '203.0.113.10') !== false,
	'the allowlist error names the address the operator has to add',
	'got: ' . ($threw ? $threw->getMessage() : ''));

// ---------------------------------------------------------------------------
section('The sandbox setting really changes where calls go');

list($driver, $log) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.getInfo">'
		. '<DomainGetInfoResult Status="Ok"><DomainDetails><ExpiredDate>01/01/2028</ExpiredDate>'
		. '</DomainDetails></DomainGetInfoResult></CommandResponse>'),
));
$driver->getExpiry('a.com');
check(strpos((string)$log[0]['request']->getUri(), 'api.namecheap.com') !== false,
	'production calls go to the live endpoint');

harness_set_setting_mem('server_manager_namecheap_sandbox', '1');
list($driver, $log) = ncp_driver(array(
	ncp_xml('<CommandResponse Type="namecheap.domains.getInfo">'
		. '<DomainGetInfoResult Status="Ok"><DomainDetails><ExpiredDate>01/01/2028</ExpiredDate>'
		. '</DomainDetails></DomainGetInfoResult></CommandResponse>'),
));
$driver->getExpiry('a.com');
check(strpos((string)$log[0]['request']->getUri(), 'api.sandbox.namecheap.com') !== false,
	'with the sandbox on, nothing reaches the real registrar',
	'went to: ' . (string)$log[0]['request']->getUri());
harness_set_setting_mem('server_manager_namecheap_sandbox', '');

// ---------------------------------------------------------------------------
section('A phone number is never guessed at');

$good = array(
	'+1 555 123 4567'   => '+1.5551234567',
	'+1.5551234567'     => '+1.5551234567',
	'(+1) 555-123-4567' => '+1.5551234567',
	'+442079460000'     => '+44.2079460000',
	'+44 20 7946 0000'  => '+44.2079460000',
	'001 555 123 4567'  => '+1.5551234567',
);
foreach ($good as $raw => $expected) {
	check(NamecheapRegistrar::normalizePhone($raw) === $expected,
		'"' . $raw . '" becomes ' . $expected,
		'got: ' . NamecheapRegistrar::normalizePhone($raw));
}

// This is the important one. "5551234567" is a US number to whoever typed it
// and a Brazilian one to a prefix table; inferring would stamp a stranger's
// country code onto a public WHOIS record. Refusing costs one correction.
foreach (array('5551234567', '555 123 4567', '', 'abc', '+1 555', '+') as $bad) {
	check(NamecheapRegistrar::normalizePhone($bad) === '',
		var_export($bad, true) . ' is refused rather than guessed',
		'got: ' . NamecheapRegistrar::normalizePhone($bad));
}

check((new NamecheapRegistrar())->normalizeRegistrantPhone('+1 555 123 4567') === '+1.5551234567',
	'the seam method and the static agree');

section('DNS stays with the DNS stack');

$driver = new NamecheapRegistrar();
check($driver->dnsDriverKey() === 'namecheap',
	'the registrar names a DnsDriverRegistry key rather than publishing records itself');
$credential = $driver->dnsCredential();
check(isset($credential['api_user'], $credential['api_key'], $credential['client_ip']),
	'and hands over exactly the fields NamecheapDnsDriver::credentialFields() declares');
check($driver->graduationMechanism() === 'account_push',
	'custody moves by account push, so the pipeline queues an operator task');

harness_finish();
