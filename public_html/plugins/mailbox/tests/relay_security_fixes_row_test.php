<?php
/** @joinery-test
 * name: mailbox_relay_security_fixes_row
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 * covers: [public_html/plugins/mailbox/includes/InboundEmailSetupCheck.php]
 */
/**
 * The relay's security fixes on the mail page (specs/security_fixes_on_relay_and_dns.md):
 * the relay's code changes only with a relay update, so after a release key
 * rotation it refuses the newest list of early fixes, and the row says so and
 * that a relay update renews it. A relay with no hourly check reports nothing
 * and gets no row.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));

$now = strtotime('2026-11-28T12:00:00Z');
$row = function (?array $fixes) use ($now) {
	return InboundEmailSetupCheck::relaySecurityFixesResult($fixes, 'mx.example.test', '', $now);
};

section('A relay born before its hourly check');
check($row(null) === null, 'reports nothing: no row');

section('A relay that looks every hour');
$ok = $row(array('checked_at' => '2026-11-28T11:20:00Z', 'pending' => 0, 'critical' => 0, 'channel' => 4, 'fixes_installed' => 1));
check($ok['status'] === InboundEmailSetupCheck::PASS && $ok['id'] === 'host.relay_security_fixes', 'passes');
check(strpos($ok['detail'], '1 early fix(es) built ahead of Ubuntu installed') !== false, '  and counts the early fixes installed');

section('A relay that refused the newest list (RQ1)');
$refused = $row(array('checked_at' => '2026-11-28T11:20:00Z', 'channel' => 4, 'channel_refused' => 7));
check($refused['status'] === InboundEmailSetupCheck::WARN, 'warns');
check(strpos($refused['summary'], 'refused the newest list') !== false && strpos($refused['detail'], 'Update the relay') !== false,
	'  says so, and that a relay update renews it');

section('A relay whose hourly check stopped');
$stale = $row(array('checked_at' => '2026-11-28T07:00:00Z'));
check($stale['status'] === InboundEmailSetupCheck::WARN && strpos($stale['summary'], 'last three hours') !== false, 'warns after three hours');
check($row(array())['status'] === InboundEmailSetupCheck::WARN, '  and when it never looked');

section('A critical fix still waiting');
$crit = $row(array('checked_at' => '2026-11-28T11:20:00Z', 'critical' => 2));
check($crit['status'] === InboundEmailSetupCheck::WARN && strpos($crit['summary'], '2 critical') !== false, 'warns, naming how many');

harness_finish();
