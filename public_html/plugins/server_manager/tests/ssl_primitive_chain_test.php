<?php
/** @joinery-test
 * name: ssl_primitive_chain
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The primitive SSL chain's decisions, as pure functions.
 *
 * Two of these guard failures that already happened on this fleet:
 *
 * - 188 provision_ssl jobs failed over ten days reporting CF_ROUTING_UNVERIFIED
 *   on a node whose DNS was correct throughout. The probe writes a file into
 *   the webroot, and a Joinery site routes every request through serve.php, so
 *   until the node's core had a route for it the token could not be fetched on
 *   any node at all. The version gate refuses that case by name instead of
 *   blaming Cloudflare for it.
 *
 * - setup_ssl.sh returns 0 whether or not it issued anything. Reading
 *   'completed' as success would flip a node to SSL-active while it holds no
 *   certificate, which is worse than failing, because nothing looks wrong.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionPendingSsl.php'));

// Real output shapes, colour codes and all — this is what the script emits.
$G = "\033[0;32m"; $B = "\033[0;34m"; $Y = "\033[1;33m"; $N = "\033[0m";

$issued_http = "{$G}[STEP]{$N} Domain a.example.com points at this server — using LE HTTP-01 challenge\n"
             . "Successfully received certificate.\n"
             . "{$G}[OK]{$N} Issued LE certificate for a.example.com (HTTP-01)\n"
             . "Apache reloaded.\n";

$issued_dns = "{$Y}[WARN]{$N} HTTP-01 failed; trying DNS-01\n"
            . "{$G}[STEP]{$N} Domain a.example.com resolves to cloudflare; using DNS-01\n"
            . "{$G}[OK]{$N} Issued LE certificate for a.example.com (DNS-01 via cloudflare)\n"
            . "Apache reloaded.\n";

$no_creds = "{$B}[INFO]{$N} No origin cert issued for a.example.com.\n"
          . "{$B}[INFO]{$N}   Drop credentials at /etc/letsencrypt/cloudflare.ini and re-run "
          . "sysadmin_tools/setup_ssl.sh a.example.com to enable origin SSL via DNS-01.\n"
          . "Apache reloaded.\n";

$no_path = "{$B}[INFO]{$N} No origin cert issued for a.example.com (no LE challenge path available).\n"
         . "Apache reloaded.\n";

$dns_failed = "{$Y}[WARN]{$N} HTTP-01 failed; trying DNS-01\n"
            . "{$G}[STEP]{$N} Domain a.example.com resolves to cloudflare; using DNS-01\n"
            . "{$Y}[WARN]{$N} DNS-01 via cloudflare failed\n"
            . "Apache reloaded.\n";

$apache_broken = "WARNING: apache2ctl configtest failed — review the vhost manually.\n";

$state = function ($output) { return SslProvisionOutcome::classify($output)['state']; };

section('A certificate job says what it did, and exit status is not it');

check($state($issued_http) === SslProvisionOutcome::ISSUED,
	'an HTTP-01 issue is read as issued');
check($state($issued_dns) === SslProvisionOutcome::ISSUED,
	'a DNS-01 issue after a failed HTTP-01 is still issued',
	'precedence matters: the run warns before it succeeds, and reading the warning first would call a working node broken');
check($state($no_creds) === SslProvisionOutcome::NO_DNS_CREDENTIALS,
	'a missing provider credentials file is its own state, not a generic failure');
check($state($no_path) === SslProvisionOutcome::NO_CHALLENGE_PATH,
	'no available challenge path is its own state');
check($state($dns_failed) === SslProvisionOutcome::CHALLENGE_FAILED,
	'an attempted challenge that did not validate is a failure, not a missing credential');
check($state($apache_broken) === SslProvisionOutcome::APACHE_CONFIG_BROKEN,
	'the script refusing to leave Apache broken is reported as that');

section('Nothing unrecognised is ever mistaken for a certificate');

check($state('') === SslProvisionOutcome::UNREADABLE, 'empty output is unreadable, not success');
check($state('some unrelated text') === SslProvisionOutcome::UNREADABLE, 'unrecognised output is unreadable');
foreach ([['unreadable', ''], ['no credentials', $no_creds], ['no path', $no_path],
          ['failed challenge', $dns_failed], ['broken apache', $apache_broken]] as $case) {
	check(!SslProvisionOutcome::is_issued($state($case[1])),
		"a completed job that {$case[0]} is not treated as having a certificate",
		'this is the flip that would report SSL live on a node holding nothing');
}
check(SslProvisionOutcome::is_issued($state($issued_http)), 'and a real issue is');

section('The two states a person has to act on are separated from the rest');

check(SslProvisionOutcome::needs_operator(SslProvisionOutcome::NO_DNS_CREDENTIALS),
	'missing credentials needs a person — retrying produces the same answer forever');
check(SslProvisionOutcome::needs_operator(SslProvisionOutcome::NO_CHALLENGE_PATH),
	'no challenge path needs a person');
check(!SslProvisionOutcome::needs_operator(SslProvisionOutcome::CHALLENGE_FAILED),
	'a failed challenge can be transient, so it stays on the retry path');
check(strpos(SslProvisionOutcome::classify($no_creds)['detail'], '/etc/letsencrypt/cloudflare.ini') !== false,
	'the missing-credentials message names the exact file to create',
	'the script already worked the path out; making someone rediscover it is the whole cost of this state');

section('Which challenge answered is recorded, because reverse DNS turns on it');

check(SslProvisionOutcome::classify($issued_http)['challenge'] === SslProvisionOutcome::CHALLENGE_HTTP_01,
	'an HTTP-01 issue names HTTP-01',
	'HTTP-01 cannot have succeeded unless the domain resolves to this box — the PTR precondition');
check(SslProvisionOutcome::classify($issued_dns)['challenge'] === SslProvisionOutcome::CHALLENGE_DNS_01,
	'a DNS-01 issue names DNS-01',
	'DNS-01 proves control of the zone and nothing about where the name points, so it must not set a PTR');
check(SslProvisionOutcome::classify($no_creds)['challenge'] === '',
	'a run that issued nothing names no challenge');
foreach ([$issued_http, $issued_dns, $no_creds, $no_path, $dns_failed, $apache_broken, ''] as $sample) {
	check(array_key_exists('challenge', SslProvisionOutcome::classify($sample)),
		'every classification carries a challenge key, so no caller has to guess');
}

section('A primitive result envelope is opened, not pattern-matched raw');

$envelope = json_encode(['api_version' => '1.0', 'data' => [
	'output' => $issued_http, 'output_bytes' => strlen($issued_http)]])
	. "\n\n=== Agent log ===\nran setup_ssl.sh\n";
check($state($envelope) === SslProvisionOutcome::ISSUED,
	'the script text is read out of the agent result envelope');

$envelope_no_creds = json_encode(['api_version' => '1.0', 'data' => ['output' => $no_creds]]);
check($state($envelope_no_creds) === SslProvisionOutcome::NO_DNS_CREDENTIALS,
	'and so is a non-issuing one');

section('An old node is refused by name, never as a routing failure');

$too_old = ProvisionPendingSsl::probe_gate('0.8.303');
check($too_old['ok'] === false, 'a node predating the probe route may not be probed');
check(strpos($too_old['reason'], '0.8.303') !== false && strpos($too_old['reason'], 'upgrade') !== false,
	'and the refusal names the version and the fix');
check(stripos($too_old['reason'], 'cloudflare') === false && strpos($too_old['reason'], 'CF_ROUTING') === false,
	'the refusal never blames Cloudflare',
	'188 jobs failed as CF_ROUTING_UNVERIFIED for exactly this condition; that is the message being replaced');

$unknown = ProvisionPendingSsl::probe_gate('');
check($unknown['ok'] === false, 'an unknown core version is refused rather than guessed at');
check(stripos($unknown['reason'], 'refresh') !== false,
	'and it says how to make it knowable');

check(ProvisionPendingSsl::probe_gate(ProvisionPendingSsl::PROBE_MIN_CORE_VERSION)['ok'] === true,
	'the first release carrying the route passes');
check(ProvisionPendingSsl::probe_gate('0.8.351')['ok'] === true, 'and anything later passes');
check(ProvisionPendingSsl::PROBE_MIN_CORE_VERSION === '0.8.304',
	'the gate is pinned to the release that added the /sm-ssl-probe.txt route',
	'serve.php 1.6.0 landed 2026-08-19 11:53; 0.8.303 published at 11:16, 0.8.304 at 15:18');

section('A certificate the machine already reports is observed, not requested');

// A machine this plane installs issues its own certificate — during the
// install, or on the host's retry timer once DNS points here — and its
// check_status enumerates every lineage. A covering, CA-issued, unexpired
// lineage flips the node active with no job at all.
$reports = function ($certs, $extra = []) {
	return array_merge(['ssl_certificate_count' => count($certs), 'ssl_certificates' => $certs], $extra);
};
$live = ['domains' => ['a.example.com'], 'not_after' => gmdate('Y-m-d H:i:s', time() + 86400 * 60)];
check(ProvisionPendingSsl::status_reports_certificate($reports([$live]), 'a.example.com'),
	'a live lineage covering the domain is observed');
check(ProvisionPendingSsl::status_reports_certificate($reports([['domains' => ['*.example.com'], 'not_after' => gmdate('Y-m-d H:i:s', time() + 86400)]]), 'a.example.com'),
	'a wildcard one level up covers it');
check(!ProvisionPendingSsl::status_reports_certificate($reports([['domains' => ['b.example.com']]]), 'a.example.com'),
	'a lineage for another name does not');
check(!ProvisionPendingSsl::status_reports_certificate($reports([array_merge($live, ['self_signed' => true])]), 'a.example.com'),
	'a self-signed placeholder is not a certificate');
check(!ProvisionPendingSsl::status_reports_certificate($reports([['domains' => ['a.example.com'], 'not_after' => '2020-01-01 00:00:00']]), 'a.example.com'),
	'an expired lineage is not one either — a lapsed renewer is what this task exists to notice');
check(!ProvisionPendingSsl::status_reports_certificate($reports([$live], ['ssl_certificates_unreadable' => true]), 'a.example.com'),
	'a report that could not read the directory says nothing');
check(!ProvisionPendingSsl::status_reports_certificate(['status' => 'ok'], 'a.example.com'),
	'a report from a transport that cannot see certificates says nothing');
check(!ProvisionPendingSsl::status_reports_certificate(null, 'a.example.com'), 'no report says nothing');

section('Routing misses are counted the way the lanes expect');

$place = function ($status, $params = null) {
	return ['mjb_job_type' => ProvisionPendingSsl::JOB_PROBE_PLACE, 'mjb_status' => $status,
	        'mjb_parameters' => $params === null ? null : json_encode($params)];
};

check(ProvisionPendingSsl::routing_miss_count([]) === 0, 'no jobs is no misses');
check(ProvisionPendingSsl::routing_miss_count([$place('completed', ['routing_verified' => false])]) === 1,
	'a placement whose fetch came back wrong is a miss');
check(ProvisionPendingSsl::routing_miss_count([$place('completed', ['routing_verified' => true])]) === 0,
	'a placement that proved routing is not');
check(ProvisionPendingSsl::routing_miss_count([$place('failed')]) === 1,
	'a placement that never got the token there is also a miss',
	'the token not arriving and the token not coming back are the same "did not prove routing" for pacing');
check(ProvisionPendingSsl::routing_miss_count([$place('completed')]) === 0,
	'a placement not yet checked is not counted as a miss');
check(ProvisionPendingSsl::routing_miss_count([
		['mjb_job_type' => ProvisionPendingSsl::JOB_CERTIFICATE, 'mjb_status' => 'failed', 'mjb_parameters' => null],
	]) === 0,
	'a failed certificate is not a routing miss');

section('Retry pacing follows the reason, not just the failure');

check(ProvisionPendingSsl::retry_gap(true, 0, null) === ProvisionPendingSsl::ROUTING_FAST_GAP,
	'a fresh routing wait uses the fast lane');
check(ProvisionPendingSsl::retry_gap(true, ProvisionPendingSsl::ROUTING_FAST_ATTEMPTS, null) === ProvisionPendingSsl::ROUTING_SLOW_GAP,
	'a long routing wait drops to the slow lane');
check(ProvisionPendingSsl::retry_gap(false, 0, ['state' => SslProvisionOutcome::NO_DNS_CREDENTIALS]) === ProvisionPendingSsl::ROUTING_SLOW_GAP,
	'a certificate waiting on a person also drops to the slow lane',
	'asking hourly gets the same answer every hour and buries the one line saying what to fix');
check(ProvisionPendingSsl::retry_gap(false, 0, ['state' => SslProvisionOutcome::CHALLENGE_FAILED]) === ProvisionPendingSsl::ROUTING_FAST_GAP,
	'a failed challenge keeps trying hourly');
check(ProvisionPendingSsl::retry_gap(false, 0, null) === ProvisionPendingSsl::ROUTING_FAST_GAP,
	'and so does a plain failure');

section('Certificate attempts give up, but only on their own clock');

$now  = time();
$cert = function ($age_seconds, $status = 'failed', $output = '') use ($now) {
	return ['mjb_job_type' => ProvisionPendingSsl::JOB_CERTIFICATE, 'mjb_status' => $status,
	        'mjb_output' => $output,
	        'mjb_create_time' => gmdate('Y-m-d H:i:s', $now - $age_seconds)];
};

check(ProvisionPendingSsl::certificate_give_up_due([], $now) === false,
	'a node that has never attempted a certificate has not given up');
check(ProvisionPendingSsl::certificate_give_up_due([$cert(3600)], $now) === false,
	'an hour of failures is not enough');
check(ProvisionPendingSsl::certificate_give_up_due([$cert(57601)], $now) === true,
	'16+ hours of certificate failures is');
check(ProvisionPendingSsl::certificate_give_up_due([$cert(999999, 'completed', $issued_http)], $now) === false,
	'a node that did get a certificate never gives up');
check(ProvisionPendingSsl::certificate_give_up_due([$place('failed'), $place('failed'), $cert(60)], $now) === false,
	'time spent waiting on routing does not burn the certificate window',
	'a domain parked at Cloudflare for days must get a full window once it repoints');

harness_finish();
