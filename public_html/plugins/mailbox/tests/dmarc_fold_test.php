<?php
/** @joinery-test
 * name: dmarc_fold
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * A planned DMARC record keeps what the owner set (InboundEmailSetupCheck::foldDmarc).
 *
 * The plan used to write a fixed record, so publishing it removed any report
 * address or tag the owner had added. It now starts from the live record and
 * changes only the policy its stage is about. Plan matching is exact text, so
 * a record it built must fold back to itself, or the diff would never settle.
 *
 * @version 1.2 - a subdomain with no record of its own starts from its parent domain's, and keeps a strict shape its parent asks for
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$fold = function (bool $strict, string $live) { return InboundEmailSetupCheck::foldDmarc('example.test', $strict, $live); };

section('No live record: the plan\'s own record');
check($fold(false, '') === 'v=DMARC1; p=none; rua=mailto:postmaster@example.test', 'ordinary');
check($fold(true, '') === 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test', 'strict');

section('The owner\'s own settings are kept');
$live = 'v=DMARC1; p=none; rua=mailto:reports@dmarc.example; ruf=mailto:f@dmarc.example; fo=1; sp=reject';
$strict = $fold(true, $live);
check($strict === 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test,mailto:reports@dmarc.example; ruf=mailto:f@dmarc.example; fo=1; sp=reject',
	'strict keeps another report address, ruf, fo and sp; postmaster comes first');
check($fold(true, $strict) === $strict, 'a record it built folds back to itself, so the diff settles');
check($fold(false, $fold(false, $live)) === $fold(false, $live), 'the same holds for the ordinary record');
check(strpos($fold(true, 'v=DMARC1; p=none; rua=mailto:POSTMASTER@example.test'), 'POSTMASTER') === false,
	'postmaster is not listed twice when the owner wrote it in capitals');

section('Only this stage\'s settings change');
check($fold(true, 'v=DMARC1; p=quarantine; pct=50; adkim=r') === 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test',
	'strict sets the policy and alignment and drops a pct, which would only quarantine the rest');
check($fold(true, 'v=DMARC1; p=quarantine; sp=none; np=quarantine; t=y')
	=== 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test',
	'strict drops a weaker sp or np and t, which would let forged subdomain or test-mode mail through');
check($fold(true, 'v=DMARC1; p=none; pct=100; t=n') === 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test; pct=100; t=n',
	'but keeps pct=100 and t=n, which the strict check passes, so check and diff agree');
check($fold(false, 'v=DMARC1; p=bogus; adkim=s') === 'v=DMARC1; p=none; rua=mailto:postmaster@example.test; adkim=s',
	'ordinary replaces a policy receivers would not recognize');
check($fold(true, 'v=DMARC1; p=none; sp=reject; np=reject')
	=== 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test; sp=reject; np=reject',
	'but keeps an sp or np of reject');
check($fold(false, 'v=DMARC1; p=quarantine; adkim=s; pct=50; rua=mailto:postmaster@example.test')
	=== 'v=DMARC1; p=quarantine; rua=mailto:postmaster@example.test; adkim=s; pct=50',
	'ordinary keeps the owner\'s own policy and alignment');
$lifted = $fold(false, 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test,mailto:r@dmarc.example; fo=1');
check($lifted === 'v=DMARC1; p=none; rua=mailto:postmaster@example.test,mailto:r@dmarc.example; fo=1',
	'switching protection off undoes the strict shape (policy and alignment) and keeps the rest');
check($fold(false, $lifted) === $lifted, 'and that settles too');

section('The first DMARC record in a set is the one read');
check(InboundEmailSetupCheck::firstDmarc(array('google-site-verification=x', 'v=DMARC1; p=none')) === 'v=DMARC1; p=none', 'found among other TXT');
check(InboundEmailSetupCheck::firstDmarc(array('v=spf1 -all')) === '', 'none');

section('The strict check fails what the strict fold drops');
$checker = (new ReflectionClass('InboundEmailSetupCheck'))->newInstanceWithoutConstructor();
$strict_row = function (array $txt) use ($checker) {
	$m = new ReflectionMethod('InboundEmailSetupCheck', 'protectedDmarcResult');
	return $m->invoke($checker, 'example.test', true, $txt);
};
$good = $fold(true, '');
check($strict_row(array($good))['status'] === 'pass', 'the folded strict record passes');
foreach (array('sp=none', 'np=quarantine', 't=y', 'pct=50') as $weak) {
	check($strict_row(array($good . '; ' . $weak))['status'] === 'fail', 'a strict record with ' . $weak . ' fails');
}
check($strict_row(array($good . '; sp=reject'))['status'] === 'pass', 'an sp of reject passes');
check($strict_row(array($fold(true, 'v=DMARC1; pct=100; t=n')))['status'] === 'pass', 'pct=100 and t=n pass');
$two = $strict_row(array($good, 'v=DMARC1; p=none'));
check($two['status'] === 'fail' && strpos($two['summary'], '2 DMARC records') !== false,
	'two DMARC records fail, naming the count, whichever order they arrive in');

section('A failed lookup leaves the record out of the plan');
$plan_value = new ReflectionMethod('InboundEmailSetupCheck', 'dmarcPlanValue');
DnsResolver::setBackend(new class { public function getRecords($n, $t) { throw new DnsLookupException('down'); } });
check($plan_value->invoke($checker, 'example.test', true) === '',
	'nothing to publish when the live record cannot be read, so the owner\'s settings are never overwritten blind');
DnsResolver::setBackend(new class { public function getRecords($n, $t) { return array(array('txt' => 'v=DMARC1; p=none; ruf=mailto:f@dmarc.example')); } });
check($plan_value->invoke($checker, 'example.test', true) === 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@example.test; ruf=mailto:f@dmarc.example',
	'and the live record is folded when it can');

section('A subdomain with no record of its own starts from its parent domain\'s');
check(DnsAuthChecker::dmarcForSubdomain('v=DMARC1; p=reject; sp=quarantine; np=reject; rua=mailto:r@example.test; fo=1')
	=== 'v=DMARC1; p=quarantine; rua=mailto:r@example.test; fo=1', 'sp becomes the policy; sp and np are left out; the rest is kept');
check(DnsAuthChecker::dmarcForSubdomain('v=DMARC1; p=reject') === 'v=DMARC1; p=reject' && DnsAuthChecker::dmarcForSubdomain('') === '',
	'with no sp the parent policy carries over; no record gives none');
$parent_only = function (array $map) {
	return new class($map) {
		private $map;
		public function __construct(array $map) { $this->map = $map; }
		public function getRecords($n, $t) {
			if (!array_key_exists($n, $this->map)) { return array(); }
			if ($this->map[$n] === false) { throw new DnsLookupException('down'); }
			return array(array('txt' => $this->map[$n]));
		}
	};
};
DnsResolver::setBackend($parent_only(array('_dmarc.example.test' => 'v=DMARC1; p=quarantine; rua=mailto:owner@example.test')));
$sub_plan = $plan_value->invoke($checker, 'mail.example.test', false);
check($sub_plan === 'v=DMARC1; p=quarantine; rua=mailto:postmaster@mail.example.test,mailto:owner@example.test',
	'the ordinary plan keeps the parent policy and report address, with postmaster first');
DnsResolver::setBackend($parent_only(array('_dmarc.mail.example.test' => $sub_plan, '_dmarc.example.test' => 'v=DMARC1; p=reject')));
check($plan_value->invoke($checker, 'mail.example.test', false) === $sub_plan, 'once published, its own record is what folds, so the diff settles');
DnsResolver::setBackend($parent_only(array('_dmarc.example.test' => 'v=DMARC1; p=reject; rua=mailto:owner@example.test')));
check($plan_value->invoke($checker, 'mail.example.test', true) === 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:postmaster@mail.example.test,mailto:owner@example.test',
	'the strict plan keeps the parent report address too');
DnsResolver::setBackend($parent_only(array('_dmarc.example.test' => false)));
check($plan_value->invoke($checker, 'mail.example.test', false) === '', 'a parent record that cannot be read leaves the record out of the plan');
$parent_strict = 'v=DMARC1; p=reject; aspf=s; adkim=s; rua=mailto:owner@example.test';
$sub_strict = 'v=DMARC1; p=reject; rua=mailto:postmaster@mail.example.test,mailto:owner@example.test; aspf=s; adkim=s';
DnsResolver::setBackend($parent_only(array('_dmarc.example.test' => $parent_strict)));
check($plan_value->invoke($checker, 'mail.example.test', false) === $sub_strict,
	'a parent that asks for the strict shape: the ordinary plan keeps it, where relaxing would publish less than the parent asks');
DnsResolver::setBackend($parent_only(array('_dmarc.mail.example.test' => $sub_strict, '_dmarc.example.test' => $parent_strict)));
check($plan_value->invoke($checker, 'mail.example.test', false) === $sub_strict, 'and it still does once the subdomain has published it');
DnsResolver::setBackend($parent_only(array('_dmarc.mail.example.test' => $sub_strict, '_dmarc.example.test' => 'v=DMARC1; p=none')));
check($plan_value->invoke($checker, 'mail.example.test', false) === 'v=DMARC1; p=none; rua=mailto:postmaster@mail.example.test,mailto:owner@example.test',
	'under a parent that does not, the strict shape goes back to p=none as it does on a main domain');
DnsResolver::setBackend($parent_only(array('_dmarc.mail.example.test' => $sub_strict, '_dmarc.example.test' => false)));
check($plan_value->invoke($checker, 'mail.example.test', false) === '', 'and with the parent unreadable the record is left out of the plan');
DnsResolver::setBackend($parent_only(array()));
check($plan_value->invoke($checker, 'mail.example.test', false) === 'v=DMARC1; p=none; rua=mailto:postmaster@mail.example.test',
	'with no record on either name the plan writes its own');
DnsResolver::clearBackend();

harness_finish();
