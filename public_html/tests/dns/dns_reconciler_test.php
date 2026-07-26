<?php
/** @joinery-test
 * name: dns_reconciler
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The diff and the apply — the whole safety rail, against an in-memory host.
 *
 * Every case here is one of the spec's acceptance criteria: a record the
 * platform does not own is never modified without an explicit choice, an MX
 * change that redirects live mail needs its own confirmation, one failed record
 * does not take the others down, re-publishing a correct domain writes nothing,
 * and a domain that already matches is adopted with no DNS write at all.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
require_once(PathHelper::getIncludePath('tests/dns/fixtures/FakeDnsDriver.php'));

const ZONE = 'example.com';

/** A fresh plan of the shape a mail domain wants. */
function mail_plan(): DnsRecordPlan {
	$plan = new DnsRecordPlan(ZONE, 'mailbox');
	$plan->addRecord('MX', ZONE, 'mail.example.com', null, 10, 'Inbound mail');
	$plan->addRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all', null, null, 'SPF');
	$plan->addRecord('TXT', '_dmarc.example.com', 'v=DMARC1; p=none', null, null, 'DMARC');
	return $plan;
}

/** @return array{0:FakeDnsDriver,1:DnsReconciler} */
function fixture(array $seed = array()): array {
	$driver = new FakeDnsDriver(array('api_token' => 'unused'));
	$driver->zones[ZONE] = array();
	$driver->seed(ZONE, $seed);
	return array($driver, new DnsReconciler(new MemoryDnsOwnershipStore()));
}

/** Index apply()/diff results by the record they describe. */
function by_record(array $rows): array {
	$out = array();
	foreach ($rows as $row) {
		$out[$row['record']->describe()] = $row;
	}
	return $out;
}

// ---------------------------------------------------------------------------
section('An empty zone: everything is missing, and one action publishes it');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture();
$plan = mail_plan();

$diff = by_record($reconciler->diffAgainstProvider($driver, ZONE, $plan));
check(count($diff) === 3, 'the diff has one row per planned record');
foreach ($diff as $describe => $row) {
	check($row['outcome'] === DnsReconciler::MISSING, 'missing: ' . $describe);
	check($row['cutover'] === false, 'creating a record where none exists is never a cutover');
}

$results = by_record($reconciler->apply($driver, ZONE, $plan));
foreach ($results as $describe => $result) {
	check($result['action'] === 'created' && $result['ok'], 'created: ' . $describe, $result['reason']);
}
check(count($driver->writes) === 3, 'exactly three writes reached the provider', implode(' | ', $driver->writes));
check($driver->after_publish_calls === 1, 'the post-write verification hook ran once');

// ---------------------------------------------------------------------------
section('Re-running publish on a correct domain writes nothing');
// ---------------------------------------------------------------------------

$before = count($driver->writes);
$again = by_record($reconciler->apply($driver, ZONE, $plan));
check(count($driver->writes) === $before, 'no further writes reached the provider');
foreach ($again as $describe => $result) {
	check($result['action'] === 'unchanged', 'unchanged: ' . $describe, $result['action']);
}

// ---------------------------------------------------------------------------
section('A domain that already matches is adopted with no DNS write');
// ---------------------------------------------------------------------------

// The deployment that did the work by hand: every record correct, none created
// by the platform. Ownership strictly from authorship would leave all of these
// permanently unowned and unactionable.
list($driver, $reconciler) = fixture(array(
	new DnsRecord('MX', ZONE, 'mail.example.com', 3600, 10),
	new DnsRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all', 3600),
	new DnsRecord('TXT', '_dmarc.example.com', 'v=DMARC1; p=none', 3600),
));
$plan = mail_plan();

$diff = by_record($reconciler->diffAgainstProvider($driver, ZONE, $plan));
foreach ($diff as $describe => $row) {
	check($row['outcome'] === DnsReconciler::MATCHES, 'matches despite a TTL the plan never asked for: ' . $describe);
}

$results = by_record($reconciler->apply($driver, ZONE, $plan));
foreach ($results as $describe => $result) {
	check($result['action'] === 'adopted', 'adopted: ' . $describe, $result['action']);
}
check(empty($driver->writes), 'not a single DNS write happened', implode(' | ', $driver->writes));

$store = $reconciler->getStore();
check($store->isOwned(ZONE, 'MX', ZONE), 'the platform now records itself as responsible for the MX');

$after = by_record($reconciler->diffAgainstProvider($driver, ZONE, $plan));
$conflicts = 0;
foreach ($after as $row) { if ($row['outcome'] === DnsReconciler::CONFLICTS) { $conflicts++; } }
check($conflicts === 0, 'and shows no conflicts afterwards');

// ---------------------------------------------------------------------------
section('An unowned record that differs is a conflict, never overwritten quietly');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture(array(
	new DnsRecord('TXT', ZONE, 'v=spf1 include:someone-else.example -all'),
));
$plan = new DnsRecordPlan(ZONE, 'mailbox');
$plan->addRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all', null, null, 'SPF');

$row = $reconciler->diffAgainstProvider($driver, ZONE, $plan)[0];
check($row['outcome'] === DnsReconciler::CONFLICTS, 'an existing unowned value is a conflict');
check($row['owned'] === false, 'and is reported as not ours');

$result = $reconciler->apply($driver, ZONE, $plan)[0];
check($result['action'] === 'skipped', 'applying without a choice skips it', $result['action']);
check($result['ok'] === true, 'and that is not an error — it is the rule working');
check(strpos($result['reason'], 'adopt') !== false, 'the reason names the choice that would change it', $result['reason']);
check(empty($driver->writes), 'nothing was written');

$result = $reconciler->apply($driver, ZONE, $plan, array('adopt' => array($row['key'])))[0];
check($result['action'] === 'updated', 'an explicit adopt choice overwrites it', $result['action']);
check(count($driver->writes) === 1, 'exactly one write');

// ---------------------------------------------------------------------------
section('A record the platform owns and that has drifted just differs');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture(array(
	new DnsRecord('TXT', ZONE, 'v=spf1 ip4:9.9.9.9 -all'),
));
$plan = new DnsRecordPlan(ZONE, 'mailbox');
$plan->addRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all');
$reconciler->getStore()->remember(ZONE, new DnsRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all'),
	'mailbox', 'fake', ZONE, false);

$row = $reconciler->diffAgainstProvider($driver, ZONE, $plan)[0];
check($row['outcome'] === DnsReconciler::DIFFERS, 'drift on an owned record is a difference, not a conflict');
$result = $reconciler->apply($driver, ZONE, $plan)[0];
check($result['action'] === 'updated', 'and applying puts it back with no extra choice', $result['action']);

// ---------------------------------------------------------------------------
section('Cutovers stay deliberate');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture(array(
	new DnsRecord('MX', ZONE, 'old-host.example.net', null, 10),
	new DnsRecord('A', 'mail.example.com', '9.9.9.9'),
	new DnsRecord('TXT', ZONE, 'v=spf1 ip4:9.9.9.9 -all'),
));
$plan = new DnsRecordPlan(ZONE, 'mailbox');
$plan->addRecord('MX', ZONE, 'mail.example.com', null, 10);
$plan->addRecord('A', 'mail.example.com', '1.2.3.4');
$plan->addRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all');

$diff = by_record($reconciler->diffAgainstProvider($driver, ZONE, $plan));
$mx = $diff['MX example.com → 10 mail.example.com'];
check($mx['cutover'] === true, 'replacing MX is flagged as a cutover');
check(strpos($mx['cutover_note'], 'old-host.example.net') !== false,
	'and the note names what currently receives', $mx['cutover_note']);
check(strpos($mx['cutover_note'], 'stops') !== false, 'and says it will stop', $mx['cutover_note']);

$a = $diff['A mail.example.com → 1.2.3.4'];
check($a['cutover'] === true, 'repointing an A record that already resolves is a cutover');

$txt = $diff['TXT example.com → v=spf1 ip4:1.2.3.4 -all'];
check($txt['cutover'] === false, 'changing a TXT record redirects nothing, so it is not');

$results = by_record($reconciler->apply($driver, ZONE, $plan, array('adopt' => array($mx['key'], $a['key'], $txt['key']))));
check($results['MX example.com → 10 mail.example.com']['action'] === 'skipped',
	'without its own confirmation the MX cutover is skipped even when adopted');
check($results['A mail.example.com → 1.2.3.4']['action'] === 'skipped',
	'so is the A cutover');
check($results['TXT example.com → v=spf1 ip4:1.2.3.4 -all']['action'] === 'updated',
	'while the record that redirects nothing still lands');

$results = by_record($reconciler->apply($driver, ZONE, $plan, array(
	'adopt'   => array($mx['key'], $a['key']),
	'cutover' => array($mx['key'], $a['key']),
)));
check($results['MX example.com → 10 mail.example.com']['action'] === 'updated',
	'confirmed, the cutover lands');

// ---------------------------------------------------------------------------
section('Applying is per-record and best-effort');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture();
$plan = mail_plan();
$driver->fail_value = 'v=spf1 ip4:1.2.3.4 -all';

$results = by_record($reconciler->apply($driver, ZONE, $plan));
check($results['TXT example.com → v=spf1 ip4:1.2.3.4 -all']['action'] === 'failed',
	'the record that failed reports failure');
check($results['TXT example.com → v=spf1 ip4:1.2.3.4 -all']['ok'] === false, 'and is marked not ok');
check($results['MX example.com → 10 mail.example.com']['action'] === 'created',
	'the others still landed');
check($results['TXT _dmarc.example.com → v=DMARC1; p=none']['action'] === 'created',
	'all of them, not just the ones before the failure');

// Re-running converges the remainder, then writes nothing.
$driver->fail_value = '';
$results = by_record($reconciler->apply($driver, ZONE, $plan));
check($results['TXT example.com → v=spf1 ip4:1.2.3.4 -all']['action'] === 'created',
	're-running publish converges what was left');
$writes = count($driver->writes);
$reconciler->apply($driver, ZONE, $plan);
check(count($driver->writes) === $writes, 'and a third run writes nothing at all');

// ---------------------------------------------------------------------------
section('A provider-managed record refuses by name');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture();
$driver->fail_value = 'mail.example.com';
$driver->fail_feature = 'Cloudflare Email Routing';
$plan = new DnsRecordPlan(ZONE, 'mailbox');
$plan->addRecord('MX', ZONE, 'mail.example.com', null, 10);

$result = $reconciler->apply($driver, ZONE, $plan)[0];
check($result['action'] === 'failed', 'the record fails');
check(strpos($result['reason'], 'Cloudflare Email Routing') !== false,
	'and the reason names the feature to disable', $result['reason']);

// ---------------------------------------------------------------------------
section('Adding a domain publishes only what cannot take anything away');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture(array(
	new DnsRecord('MX', ZONE, 'old-host.example.net', null, 10),
	new DnsRecord('TXT', ZONE, 'v=spf1 include:someone-else.example -all'),
));
$plan = mail_plan();

$results = by_record($reconciler->apply($driver, ZONE, $plan, array(), DnsReconciler::APPLY_ADDITIVE));
check($results['TXT _dmarc.example.com → v=DMARC1; p=none']['action'] === 'created',
	'a record the zone does not have at all is created with no second step');
check($results['MX example.com → 10 mail.example.com']['action'] === 'skipped',
	'a cutover is not performed');
check($results['TXT example.com → v=spf1 ip4:1.2.3.4 -all']['action'] === 'skipped',
	'a conflicting unowned record is not overwritten');
check(count($driver->writes) === 1, 'exactly one write — the safe one', implode(' | ', $driver->writes));

// ---------------------------------------------------------------------------
section('Withdrawal removes what the platform owns, and nothing else');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture(array(
	new DnsRecord('A', 'www.example.com', '5.5.5.5'),      // somebody's website
));
$plan = mail_plan();
$reconciler->apply($driver, ZONE, $plan);
check(count($driver->zones[ZONE]) === 4, 'the zone holds the website record plus three of ours');

check($reconciler->zoneHoldsOnlyOurRecords($driver, ZONE, ZONE) === false,
	'a zone holding a foreign record is never eligible for deletion');

$results = $reconciler->withdraw($driver, ZONE, ZONE);
check(count($results) === 3, 'withdrawal touches exactly the three records we own');
$remaining = $driver->zones[ZONE];
check(count($remaining) === 1, 'one record is left');
check($remaining[0]->name === 'www.example.com', 'and it is the one the platform never owned');
check($reconciler->zoneHoldsOnlyOurRecords($driver, ZONE, ZONE) === false,
	'the zone still holds a foreign record, so it still cannot be deleted');

// ---------------------------------------------------------------------------
section('A record written moments ago is not missing');
// ---------------------------------------------------------------------------

// Public DNS lags a write, so "never published" and "published a minute ago"
// look identical to a resolver and mean opposite things. Reporting the second
// as missing invites a re-publish, which reads as the first one having failed.
$store = new MemoryDnsOwnershipStore();
$reconciler = new DnsReconciler($store);
$plan = new DnsRecordPlan(ZONE, 'mailbox');
$plan->addRecord('TXT', ZONE, 'v=spf1 ip4:1.2.3.4 -all');
$planned = $plan->getRecords()[0];

// The public resolver answers nothing for this zone — the state a domain is in
// the instant after its records are written. Doubled so the suite stays offline
// and deterministic.
DnsResolver::setBackend(new class { public function getRecords($n, $t) { return array(); } });
harness_defer(function () { DnsResolver::clearBackend(); });

// Nothing written, nothing resolving: genuinely missing.
$rows = $reconciler->diffAgainstPublicDns($plan);
check($rows[0]['outcome'] === DnsReconciler::MISSING,
	'with no write receipt an unresolvable record is missing', $rows[0]['outcome']);
check(DnsReconciler::settled($rows) === false, 'so there is still work to do');

// Same record, just written here.
$store->remember(ZONE, $planned, 'mailbox', 'fake', ZONE, false);
$rows = $reconciler->diffAgainstPublicDns($plan);
check($rows[0]['outcome'] === DnsReconciler::PENDING,
	'with a fresh write receipt it reads as written, not missing', $rows[0]['outcome']);
check($rows[0]['written'] !== '', 'and the row carries the receipt timestamp');
check(DnsReconciler::settled($rows) === true,
	'settled — the operator has nothing left to press');
check(DnsReconciler::allGreen($rows) === false,
	'but not all-green: the record still does not resolve');
check(DnsReconciler::lastWritten($rows) === $rows[0]['written'],
	'and the box can read the receipt back for its summary');

// A write old enough that propagation has stopped being a credible excuse.
$aged_store = new MemoryDnsOwnershipStore();
$aged_store->remember(ZONE, $planned, 'mailbox', 'fake', ZONE, false);
$aged_store->ageWriteForTest(ZONE, 'TXT', ZONE, '-2 days');
$rows = (new DnsReconciler($aged_store))->diffAgainstPublicDns($plan);
check($rows[0]['outcome'] === DnsReconciler::MISSING,
	'a two-day-old receipt no longer excuses a record that does not resolve', $rows[0]['outcome']);
check(DnsReconciler::settled($rows) === false, 'and it becomes work again');

// A record we own whose planned value has since changed is drift, not flight.
$drifted = new DnsRecordPlan(ZONE, 'mailbox');
$drifted->addRecord('TXT', ZONE, 'v=spf1 ip4:9.9.9.9 -all');
$rows = $reconciler->diffAgainstPublicDns($drifted);
check($rows[0]['outcome'] === DnsReconciler::MISSING,
	'a receipt for a different value does not make new work look in-flight',
	$rows[0]['outcome']);

DnsResolver::clearBackend();

// ---------------------------------------------------------------------------
section('Summaries read the way the page shows them');
// ---------------------------------------------------------------------------

list($driver, $reconciler) = fixture(array(
	new DnsRecord('TXT', ZONE, 'v=spf1 include:someone-else.example -all'),
));
$rows = $reconciler->diffAgainstProvider($driver, ZONE, mail_plan());
$counts = DnsReconciler::summarize($rows);
check($counts[DnsReconciler::MISSING] === 2, 'two missing', json_encode($counts));
check($counts[DnsReconciler::CONFLICTS] === 1, 'one conflict', json_encode($counts));
check(DnsReconciler::allGreen($rows) === false, 'so the box is not all-green');

list($driver, $reconciler) = fixture();
$plan = mail_plan();
$reconciler->apply($driver, ZONE, $plan);
check(DnsReconciler::allGreen($reconciler->diffAgainstProvider($driver, ZONE, $plan)) === true,
	'after a successful publish it is');

harness_finish();
