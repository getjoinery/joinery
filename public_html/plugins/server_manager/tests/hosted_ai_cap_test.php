<?php
/** @joinery-test
 * name: hosted_ai_cap
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The AI credit money model (specs/hosted_ai_credit.md §3.1, §8 Unit), as
 * HostedAiCap's pure functions, with no database and no provider.
 *
 * The rule that matters most: free usage is a TOP-UP to $1 a month, never an
 * addition, and spend past the cap is taken from the next top-ups, so over
 * time no site has more than $1 of free usage a month.
 *
 * Run: php plugins/server_manager/tests/hosted_ai_cap_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

$F = 1000000; // $1

section('The cap');
check(HostedAiCap::cap(0, $F, 0) === $F, 'a new site is capped at its free usage');
check(HostedAiCap::cap(3000000, $F, 5000000) === 9000000, 'the cap is U0 + A + B');

section('Spend comes out of free usage first');
check(HostedAiCap::allowance_used(400000, $F) === 400000 && HostedAiCap::purchased_used(400000, $F) === 0,
	'under the allowance, no bought credit is used');
check(HostedAiCap::allowance_used(1500000, $F) === $F && HostedAiCap::purchased_used(1500000, $F) === 500000,
	'past the allowance, the rest comes out of bought credit');
check(HostedAiCap::unspent_purchased(1500000, $F, 2000000) === 1500000, 'unspent bought credit is B less what it paid for');

section('The month roll');
$r = HostedAiCap::roll(400000, 0, $F, 0, 0, $F, true);
check($r['allowance'] === $F && $r['purchased'] === 0 && $r['owed'] === 0 && $r['u0'] === 400000,
	'free usage left unused: next month is $1, not more', json_encode($r));
$r = HostedAiCap::roll(0, 0, $F, 0, 0, $F, true);
check($r['allowance'] === $F, 'nothing spent: still only $1', json_encode($r));
$r = HostedAiCap::roll(1500000, 0, $F, 2000000, 0, $F, true);
check($r['purchased'] === 1500000 && $r['allowance'] === $F && $r['owed'] === 0,
	'bought credit carries over less what it paid for', json_encode($r));
$r = HostedAiCap::roll(1030000, 0, $F, 0, 0, $F, true);
check($r['over'] === 30000 && $r['allowance'] === 970000 && $r['owed'] === 0,
	'a 3-cent overshoot comes off next month\'s top-up', json_encode($r));
$r = HostedAiCap::roll(2500000, 0, $F, 0, 0, $F, true);
check($r['over'] === 1500000 && $r['allowance'] === 0 && $r['owed'] === 500000,
	'an overshoot over $1: next month gets nothing and still owes 50 cents', json_encode($r));
$r2 = HostedAiCap::roll(2500000, $r['u0'], $r['allowance'], $r['purchased'], $r['owed'], $F, true);
check($r2['allowance'] === 500000 && $r2['owed'] === 0,
	'...and the month after gets the rest of its $1 back', json_encode($r2));
$r = HostedAiCap::roll(400000, 0, $F, 3000000, 0, $F, false);
check($r['allowance'] === 0 && $r['purchased'] === 3000000, 'a site not entitled at the roll gets no free usage, keeps bought credit', json_encode($r));
$r = HostedAiCap::roll(2500000, 0, $F, 0, 200000, $F, false);
check($r['allowance'] === 0 && $r['owed'] === 1700000, 'not entitled: what is owed stays owed', json_encode($r));
// A year in which every month spends 5 cents past its cap (in-flight
// overshoot), with no purchases. Everything spent was free; what is still
// owed at the end, and the shortfall of the next month's top-up below $1,
// are free usage being taken back. Spent less both is the free usage given,
// and it must be at most $1 a month.
$u0 = 0; $a = $F; $d = 0; $u = 0;
for ($m = 0; $m < 12; $m++) {
	$u += $a + 50000;
	$r = HostedAiCap::roll($u, $u0, $a, 0, $d, $F, true);
	$u0 = $r['u0']; $a = $r['allowance']; $d = $r['owed'];
}
check($u - $d - ($F - $a) <= 12 * $F, 'over a year of overshoots, free usage given stays within $1 a month',
	'spent ' . ($u / 1e6) . ', owed ' . ($d / 1e6) . ', next top-up ' . ($a / 1e6));

section('The free figure');
$threw = false;
try { HostedAiCap::roll(0, 0, 0, 0, 0, 1000001, true); } catch (HostedAiCapException $e) { $threw = true; }
check($threw, 'a free figure above $1.00 is refused');
check(HostedAiCap::micro('1.00') === $F && HostedAiCap::micro('0.5') === 500000, 'dollars read as microdollars');

section('Purchases and refunds');
check(HostedAiCap::credit_for_money(20.00, 10) === 18181818, 'a $20 purchase at 10% is $18.18 of spend');
check(HostedAiCap::credit_for_money(5.00, 0) === 5000000, 'no markup: dollar for dollar');
check(HostedAiCap::credit_for_refund(11.00, 10, 50000000) === 10000000, 'a $11 refund at 10% takes $10 of credit');
check(HostedAiCap::credit_for_refund(11.00, 25, 50000000) === 8800000, 'a refund uses the purchase\'s own markup, not today\'s');
check(HostedAiCap::credit_for_refund(22.00, 10, 4000000) === 4000000, 'a refund partly spent takes off only what is unspent');
check(HostedAiCap::dollars(18181818) === '$18.18' && HostedAiCap::dollars(4200) === '$0.0042', 'microdollars read as dollars');

harness_finish();
