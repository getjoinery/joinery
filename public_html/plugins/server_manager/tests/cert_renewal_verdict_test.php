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
 *
 * The trigger built on that arithmetic is pinned too: renewal more than a day
 * overdue alerts on its own, and a www that reaches the origin without a
 * covering certificate is its own reason. Both are decided from injected
 * certificate arrays; nothing here touches the network.
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

$ver = new ReflectionMethod('RunNodeUptimeChecks', 'cert_alert_verdict');
$ver->setAccessible(true);
$verdict = function (int $now, int $nb, int $na, int $warn = 21) use ($ver, $task) { return $ver->invoke($task, $now, $nb, $na, $warn); };

$gap = new ReflectionMethod('RunNodeUptimeChecks', 'www_coverage_gap');
$gap->setAccessible(true);
$www_gap = function (?array $cert, string $www) use ($gap, $task) { return $gap->invoke($task, $cert, $www); };

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

section('The alert triggers on renewal overdue, not only on expiry approaching');
// The ScrollDaddy cert again: due 2026-08-31, 21-day warn threshold.
$day = 86400;
check($verdict(strtotime('2026-08-30 12:00:00 UTC'), $nb, $na) === null,
	'the day before renewal is due there is nothing to say');
check($verdict($d + 12 * 3600, $nb, $na) === null,
	'twelve hours past due is inside the one-day grace, still nothing');
check($verdict($d + $day + 3600, $nb, $na) === 'overdue',
	'a day and an hour past due, with the same certificate on the wire, is overdue');
check($verdict(strtotime('2026-09-10 12:00:00 UTC'), $nb, $na) === 'overdue',
	'the live case (9 days overdue, 20 days left) alerts as overdue, nine days earlier than the expiry threshold');
// A replacement carries a fresh notBefore, so its own due date lies ahead:
// no fingerprint is needed to know the wire certificate is the new one.
$new_nb = strtotime('2026-09-01 00:00:00 UTC');
$new_na = $new_nb + 90 * $day;
check($verdict(strtotime('2026-09-10 12:00:00 UTC'), $new_nb, $new_na) === null,
	'a certificate renewed on time is quiet even though the old one would have been overdue');
// A 60-day lineage is due 20 days before expiry, so "25 days left, due in 5"
// is a real state (for a 90-day one, 25 days left is already 5 days overdue).
$sixty_nb = strtotime('2026-09-01 00:00:00 UTC');
$sixty_na = $sixty_nb + 60 * $day;
check($verdict($sixty_na - 25 * $day, $sixty_nb, $sixty_na) === null,
	'25 days left with renewal due in 5 is quiet');
check($verdict($sixty_na - 20 * $day, $sixty_nb, $sixty_na) === 'expiring',
	'20 days left (due today) falls under the 21-day threshold before the window has been missed by a day');
check($verdict($new_na - 25 * $day, $new_nb, $new_na) === 'overdue',
	'the same 25 days left on a 90-day lineage is 5 days overdue, and says so');
check($verdict($new_na - 20 * $day, 0, $new_na) === 'expiring',
	'with no usable issue date the expiry threshold is the only trigger, and it still fires');
check($verdict($new_na - 40 * $day, 0, $new_na) === null,
	'with no usable issue date and 40 days left there is nothing to say');
check($verdict($new_na + $day, $new_nb, $new_na) === 'overdue',
	'an expired certificate is reported as overdue (the mail headline says EXPIRED from days_left)');

section('www that reaches the origin uncovered is its own alert reason');
$apex_only = ['subject' => ['CN' => 'jeremytunnell.com'],
	'extensions' => ['subjectAltName' => 'DNS:jeremytunnell.com']];
$both      = ['subject' => ['CN' => 'jeremytunnell.com'],
	'extensions' => ['subjectAltName' => 'DNS:jeremytunnell.com, DNS:www.jeremytunnell.com']];
$wild      = ['subject' => ['CN' => '*.jeremytunnell.com'],
	'extensions' => ['subjectAltName' => 'DNS:*.jeremytunnell.com']];
$stranger  = ['subject' => ['CN' => 'developers.getjoinery.com'],
	'extensions' => ['subjectAltName' => 'DNS:developers.getjoinery.com']];
$r = $www_gap($apex_only, 'www.jeremytunnell.com');
check($r !== '' && strpos($r, 'www.jeremytunnell.com') === 0 && strpos($r, 'Strict') !== false,
	'a single-name apex certificate presented for www names www and says Strict would reject it', $r);
check($www_gap($both, 'www.jeremytunnell.com') === '', 'a certificate carrying www is quiet');
check($www_gap($wild, 'www.jeremytunnell.com') === '', 'a wildcard covering www is quiet');
check($www_gap($stranger, 'www.jeremytunnell.com') !== '',
	'the shared fallback certificate for another name is a gap, not a pass');
check($www_gap(null, 'www.jeremytunnell.com') === '',
	'a failed handshake says nothing: reachability is the uptime probe\'s job');

section('An origin answering with somebody else\'s certificate is its own alert reason');
$cn = new ReflectionMethod('RunNodeUptimeChecks', 'cert_names');
$cn->setAccessible(true);
$ua = new ReflectionMethod('RunNodeUptimeChecks', 'uncovered_alert_text');
$ua->setAccessible(true);
$names = $cn->invoke($task, $stranger);
check($names === ['developers.getjoinery.com'], 'the names a certificate carries are read once, CN and SANs deduplicated', implode(',', $names));
check($cn->invoke($task, $both) === ['jeremytunnell.com', 'www.jeremytunnell.com'], 'and every SAN is listed');
$text = $ua->invoke($task, 'getjoinery', 'getjoinery.com', $names);
check(strpos($text['subject'], 'does not cover getjoinery.com') !== false, 'the subject names the uncovered host', $text['subject']);
check(strpos($text['body'], 'certificate for developers.getjoinery.com, not for getjoinery.com; Strict would take the site down') !== false,
    'the body says which names were presented, which was wanted, and that Strict would take the site down');
check(strpos($text['body'], 'issue_origin_cert.sh getjoinery.com') !== false, 'and names the tool that fixes it');
check(strpos($ua->invoke($task, 'n', 'h.example', [])['body'], 'no name at all') !== false, 'a certificate with no names is said plainly');
// The check itself: a foreign certificate reaches the alert path and stores nothing.
$src = (string)file_get_contents(PathHelper::getIncludePath('plugins/server_manager/tasks/RunNodeUptimeChecks.php'));
$fn = '';
if (preg_match('/private function check_cert_expiry\(.*?\n\t\}\n/s', $src, $m)) { $fn = $m[0]; }
$uncovered_at = strpos($fn, 'send_uncovered_alert');
$store_at     = strpos($fn, "set('mgn_cert_expiry_ts'");
check($uncovered_at !== false && $store_at !== false && $uncovered_at < $store_at,
    'a certificate that does not cover the name alerts before, and instead of, storing an expiry that is not this node\'s');

section('The issuer reads as a name a person recognises');
check($issuer(['issuer' => ['O' => "Let's Encrypt", 'CN' => 'YE2']]) === "Let's Encrypt YE2",
	'organisation and common name are joined');
check($issuer(['issuer' => ['CN' => 'YE2']]) === 'YE2', 'a CN alone is enough');
check($issuer(['issuer' => ['O' => 'ACME', 'CN' => 'ACME']]) === 'ACME',
	'a repeated value is not printed twice');
check($issuer([]) === '', 'no issuer fields yields an empty string, and the line is dropped');

harness_finish();
