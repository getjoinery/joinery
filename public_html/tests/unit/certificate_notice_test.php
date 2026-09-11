<?php
/** @joinery-test
 * name: certificate_notice
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * A self-hosted node tells its owner that its certificate has stopped
 * renewing before the site dies of it (specs/tls_and_origin_trust.md WP11).
 * The verdict is decided from the summary the host converger writes
 * (cache/certificates.json) and a fixed clock, so every state is pinned here
 * from a fixture: nothing touches the network or /etc/letsencrypt.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/CertificateNotice.php'));
require_once(PathHelper::getIncludePath('includes/VaultHealth.php'));

$day  = 86400;
$now  = 1800000000;
$edge = ['104.16.1.1', '104.16.2.2'];
$own  = ['203.0.113.5'];

/** A summary for one lineage state. */
$summary = function (array $over = [], array $lineage_over = []) use ($now, $day, $own, $edge): array {
	$lineage = $lineage_over + [
		'name' => 'example.com', 'names' => ['example.com', 'www.example.com'],
		'not_before' => $now - 10 * $day, 'not_after' => $now + 80 * $day, 'renewal_installer' => 'None',
	];
	return $over + [
		'written' => $now - 600, 'in_container' => false, 'letsencrypt' => true, 'timer_active' => true,
		'site' => ['name' => 'example.com', 'own_addresses' => $own, 'apex_addresses' => $edge, 'www_addresses' => $edge],
		'lineages' => [$lineage],
	];
};
$verdict = function (?array $facts, ?int $at = null) use ($now) {
	return CertificateNotice::forState($facts, $at ?? $now, 'example.com');
};

section('No summary, a stale one, or a container says unknown, never a false pass');
$r = $verdict(null);
check($r['state'] === 'unknown', 'no summary at all is unknown', $r['lead']);
$r = $verdict($summary(['written' => $now - 3 * $day]));
check($r['state'] === 'unknown', 'a summary three days old is unknown', $r['lead']);
check(strpos($r['body'], 'converger') !== false, 'and points at the converger, which is the stale thing');
$r = $verdict($summary(['written' => $now - 1.5 * $day]));
check($r['state'] === 'met', 'a summary a day and a half old is still current');
$r = $verdict($summary(['in_container' => true]));
check($r['state'] === 'unknown', 'a container is unknown: TLS terminates on its Docker host', $r['lead']);
check(strpos($r['body'], 'Docker host') !== false, 'and says where the certificate lives');
$r = $verdict($summary(['site' => ['name' => '', 'own_addresses' => [], 'apex_addresses' => [], 'www_addresses' => []]]));
check($r['state'] === 'met', 'a summary with no site name falls back to the name the site configured');
$r = CertificateNotice::forState($summary(['site' => ['name' => '']]), $now, '');
check($r['state'] === 'unknown', 'no name anywhere is unknown', $r['lead']);

section('A healthy lineage is met and states the expiry');
$r = $verdict($summary());
check($r['state'] === 'met', 'a certificate renewed ten days ago is met', $r['lead']);
check(strpos($r['lead'], gmdate('Y-m-d', $now + 80 * $day)) !== false, 'and the lead carries the expiry date');
check($r['expires'] === $now + 80 * $day, 'and expires is the unix notAfter for callers that want it');

section('No lineage for the name: how to issue, and the Strict-before-first-certificate limit');
$r = $verdict($summary(['lineages' => []]));
check($r['state'] === 'advice', 'no lineage with the name resolving to an edge is advice', $r['lead']);
check(strpos($r['body'], 'Full (Strict)') !== false && strpos($r['body'], 'HTTP-01') !== false,
	'and says to keep the edge on Full until the first certificate lands, because the challenge cannot get through Strict');
check(strpos($r['command'], 'setup_ssl.sh example.com') !== false, 'and the command is setup_ssl.sh for the name', $r['command']);
$r = $verdict($summary(['lineages' => [], 'site' => ['name' => 'example.com', 'own_addresses' => $own, 'apex_addresses' => $own, 'www_addresses' => []]]));
check($r['state'] === 'advice' && strpos($r['body'], 'Strict') === false,
	'a name resolving direct gets the issue advice without the edge sentence', $r['body']);
$r = $verdict($summary(['lineages' => [], 'site' => ['name' => 'example.com', 'own_addresses' => $own, 'apex_addresses' => [], 'www_addresses' => []]]));
check($r['state'] === 'advice' && strpos($r['body'], 'does not resolve') !== false,
	'a name that resolves nowhere says to point DNS and that the retry timer issues on its own', $r['body']);
$r = $verdict($summary(['lineages' => [['name' => 'other.example', 'names' => ['other.example'], 'not_before' => $now - $day, 'not_after' => $now + 89 * $day]]]));
check($r['state'] === 'advice' && strpos($r['body'], 'other.example') !== false,
	'a lineage for some other name does not count, and the other name is listed', $r['body']);
$r = $verdict($summary(['lineages' => [['name' => 'star', 'names' => ['*.example.com', 'example.com'], 'not_before' => $now - $day, 'not_after' => $now + 89 * $day]]]));
check($r['state'] === 'met', 'a lineage whose SANs cover the name counts whatever its directory is called');

section('Expired, overdue, and no timer are unmet with the fix on the host');
$r = $verdict($summary([], ['not_before' => $now - 100 * $day, 'not_after' => $now - $day]));
check($r['state'] === 'unmet' && strpos($r['lead'], 'expired') !== false, 'an expired certificate is unmet', $r['lead']);
check($r['command'] === 'sudo certbot renew', 'and the command is certbot renew');
// Due at two thirds of a 90-day life: day 60. Renewed 61 days ago is one day overdue (inside the grace); 62 is past it.
$r = $verdict($summary([], ['not_before' => $now - 61 * $day, 'not_after' => $now + 29 * $day]));
check($r['state'] === 'met', 'renewal due yesterday, exactly a day ago, is inside the grace', $r['lead']);
$r = $verdict($summary([], ['not_before' => $now - 62 * $day, 'not_after' => $now + 28 * $day]));
check($r['state'] === 'unmet' && strpos($r['lead'], 'overdue since') !== false, 'renewal due two days ago with the same certificate is unmet', $r['lead']);
check(strpos($r['lead'], gmdate('Y-m-d', $now - 2 * $day)) !== false, 'and names the day it was due', $r['lead']);
check($r['command'] === 'sudo certbot renew', 'and says to run certbot renew and read its output');
check(CertificateNotice::renewalDue($now - 62 * $day, $now + 28 * $day) === $now - 2 * $day,
	'the due date is two thirds through the life, the plane\'s arithmetic');
check(CertificateNotice::renewalDue(0, $now) === null && CertificateNotice::renewalDue($now, $now) === null,
	'senseless dates produce no due date and therefore no overdue claim');
$r = $verdict($summary([], ['not_before' => 0, 'not_after' => $now + 28 * $day]));
check($r['state'] === 'met', 'a lineage with no readable issue date is not called overdue');
$r = $verdict($summary(['timer_active' => false]));
check($r['state'] === 'unmet' && strpos($r['command'], 'certbot.timer') !== false, 'a healthy certificate with no timer is unmet, naming the timer', $r['command']);
$r = $verdict($summary([], ['not_before' => $now - 62 * $day, 'not_after' => $now + 28 * $day]) + ['timer_active' => false]);
check(strpos($r['lead'], 'overdue') !== false, 'overdue outranks the timer: the certificate is the thing to fix first');

section('www that resolves and is not covered is advice naming the re-issue');
$r = $verdict($summary([], ['names' => ['example.com']]));
check($r['state'] === 'advice' && strpos($r['lead'], 'www.example.com') !== false, 'apex-only certificate with www resolving is advice', $r['lead']);
check(strpos($r['body'], 'Strict') !== false, 'and says a Strict flip would reject it');
check(strpos($r['command'], 'issue_origin_cert.sh example.com') !== false, 'and the command is issue_origin_cert.sh', $r['command']);
$r = $verdict($summary(['site' => ['name' => 'example.com', 'own_addresses' => $own, 'apex_addresses' => $edge, 'www_addresses' => []]], ['names' => ['example.com']]));
check($r['state'] === 'met', 'www that does not resolve is not mentioned');
$r = $verdict($summary([], ['names' => ['*.example.com', 'example.com']]));
check($r['state'] === 'met', 'a wildcard covers www');

section('The health row is the same verdict in the panel\'s shape');
$row = VaultHealth::checkCertificates($summary(), $now, 'example.com');
check($row['key'] === 'certificates' && $row['state'] === 'verified' && $row['reason'] === '', 'met reads as verified with no reason');
$row = VaultHealth::checkCertificates($summary(['timer_active' => false]), $now, 'example.com');
check($row['state'] === 'unmet' && strpos($row['reason'], 'certbot.timer') !== false, 'unmet carries the lead, body and command as the reason');
$row = VaultHealth::checkCertificates($summary([], ['names' => ['example.com']]), $now, 'example.com');
check($row['state'] === 'unmet' && strpos($row['reason'], 'www') !== false, 'advice reads as unmet in the panel: it is a thing to do');
$row = VaultHealth::checkCertificates(null, $now, 'example.com');
check($row['state'] === 'unknown', 'no summary reads as unknown');
$live = VaultHealth::checkCertificates();
check(in_array($live['state'], ['verified', 'unmet', 'unknown'], true), 'the live row on this box reports a valid state', $live['state']);

section('The notice renders only what an admin can act on');
$_SESSION['permission'] = 10;
$html = CertificateNotice::render();
check(is_string($html), 'render returns a string for a superadmin on the live box');
$_SESSION['permission'] = 5;
check(CertificateNotice::render() === '', 'and nothing for anyone below superadmin');
unset($_SESSION['permission']);

harness_finish();
