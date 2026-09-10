<?php
/** @joinery-test
 * name: cert_renewal_verdict
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * "Expires in 20 days" does not say whether anything is broken — a healthy
 * 90-day certificate spends every day of its life expiring. The cert alert
 * decides that by working out when a standard ACME client would have replaced
 * the certificate (two thirds through its life) and comparing that to now.
 *
 * The arithmetic is load-bearing: get it wrong and the mail states with
 * confidence that renewal is overdue when it is not, or stays quiet while a
 * node drifts toward a hard outage. These cases pin it, including the real
 * ScrollDaddy certificate whose renewal date is independently known: Caddy's
 * own ARI window on that node opened 2026-08-31 00:42 UTC and its first failed
 * attempt was logged 2026-08-31 22:07 UTC. The agreement is to the day, not the
 * minute — a CA jitters each certificate's window, so only the day is stable.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));

$task = new RunNodeUptimeChecks();
$ref  = new ReflectionMethod('RunNodeUptimeChecks', 'renewal_due_ts');
$ref->setAccessible(true);
$due = function (int $nb, int $na) use ($ref, $task) { return $ref->invoke($task, $nb, $na); };

$iss = new ReflectionMethod('RunNodeUptimeChecks', 'describe_issuer');
$iss->setAccessible(true);
$issuer = function (array $cert) use ($iss, $task) { return $iss->invoke($task, $cert); };

section('A 90-day certificate is due for renewal 30 days before it expires');
$nb = strtotime('2026-07-02 21:58:46 UTC');
$na = strtotime('2026-09-30 21:58:45 UTC');
$d  = $due($nb, $na);
check($d !== null, 'a well-formed pair produces a renewal date');
check(gmdate('Y-m-d', $d) === '2026-08-31',
	'the ScrollDaddy cert was due 2026-08-31, matching Caddy\'s own ARI window',
	'got ' . gmdate('Y-m-d H:i', (int)$d));
check(($na - $d) === intdiv($na - $nb, 3),
	'the gap to expiry is exactly one third of the lifetime');

section('The proportion holds for lifetimes either side of 90 days');
$short_nb = strtotime('2026-09-01 00:00:00 UTC');
$short_na = $short_nb + (6 * 86400);          // a 6-day short-lived cert
check($due($short_nb, $short_na) === $short_na - (2 * 86400),
	'a 6-day certificate is due two days before expiry');
$year_nb = strtotime('2026-01-01 00:00:00 UTC');
$year_na = $year_nb + (390 * 86400);
check($due($year_nb, $year_na) === $year_na - intdiv(390 * 86400, 3),
	'a 390-day certificate is due 130 days before expiry');

section('A certificate whose dates make no sense produces no claim');
check($due(0, $na) === null, 'a missing notBefore yields null, not a guessed date');
check($due($na, $nb) === null, 'notBefore after notAfter yields null');
check($due($na, $na) === null, 'a zero-length lifetime yields null');

section('Overdue is measured from the renewal date, not the expiry date');
// The live case: 20 days still left on the clock, yet renewal is long overdue.
$now         = strtotime('2026-09-10 12:00:00 UTC');
$days_left   = (int)floor(($na - $now) / 86400);
$overdue     = (int)floor(($now - $d) / 86400);
check($days_left === 20, 'the certificate still has 20 days left', "days_left=$days_left");
check($overdue === 9, 'and renewal is nonetheless 9 days overdue', "overdue=$overdue");
check($overdue >= 0 && $days_left > 0,
	'so a cert can be both healthy-looking and provably not renewing — the case the alert exists for');

section('The issuer reads as a name a person recognises');
check($issuer(['issuer' => ['O' => "Let's Encrypt", 'CN' => 'YE2']]) === "Let's Encrypt YE2",
	'organisation and common name are joined');
check($issuer(['issuer' => ['CN' => 'YE2']]) === 'YE2', 'a CN alone is enough');
check($issuer(['issuer' => ['O' => 'ACME', 'CN' => 'ACME']]) === 'ACME',
	'a repeated value is not printed twice');
check($issuer([]) === '', 'no issuer fields yields an empty string, and the line is dropped');

harness_finish();
