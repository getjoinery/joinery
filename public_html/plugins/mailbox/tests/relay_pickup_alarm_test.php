<?php
/** @joinery-test
 * name: relay_pickup_alarm
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * Mail that has stopped arriving announces itself.
 *
 * A relay keeps ACCEPTING mail while this server cannot collect it, so senders
 * are told it was delivered and nothing brings it here. Three things make that
 * visible without anyone opening a tab, and each is pinned here:
 *
 *   1. MailboxRelay::pickupTransition() — the reconcile pass raises "stopped"
 *      once, after PICKUP_STALL_SECONDS without a pull that reached the relay,
 *      and "recovered" once when one does. A single failed pass is not news; a
 *      skipped pull says nothing either way.
 *   2. RelaySpoolConsumer::pull() / RelayMapSync::push() answer ERROR, not
 *      skipped, for a relay row with no identity pin — "does not apply" was how
 *      a pinless relay's outage read as health.
 *   3. MailboxAttentionNotice — the admin-header notice, from stored facts
 *      only: the pickup alarm first, a failed ping second, nothing otherwise.
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAttentionNotice.php'));

$now   = 1_800_000_000;
$stall = MailboxRelay::PICKUP_STALL_SECONDS;
$ago   = function (int $seconds) use ($now) { return gmdate('Y-m-d H:i:s', $now - $seconds); };

section('pickupTransition: stopped is raised once, after the stall window');
check(MailboxRelay::pickupTransition('error', $ago($stall + 60), false, $now) === 'stopped',
	'a failed pull with the last good one older than the window fires stopped');
check(MailboxRelay::pickupTransition('error', null, false, $now) === 'stopped',
	'a failed pull on a relay never pulled fires stopped');
check(MailboxRelay::pickupTransition('error', '', false, $now) === 'stopped',
	'...and an empty last-pull reads as never');
check(MailboxRelay::pickupTransition('error', $ago(120), false, $now) === 'none',
	'one failed pass two minutes after a good pull is not news');
check(MailboxRelay::pickupTransition('error', $ago($stall - 1), false, $now) === 'none',
	'just inside the window stays quiet');
check(MailboxRelay::pickupTransition('error', $ago($stall + 60), true, $now) === 'none',
	'a relay that stays broken is not announced again');
check(MailboxRelay::pickupTransition('error', 'not a time', false, $now) === 'stopped',
	'an unreadable last-pull time is treated as never');

section('pickupTransition: recovered is raised once, when a pull reaches the relay');
check(MailboxRelay::pickupTransition('success', $ago(10), true, $now) === 'recovered',
	'a successful pull while the alarm stands fires recovered');
check(MailboxRelay::pickupTransition('success', $ago(10), false, $now) === 'none',
	'a successful pull with no alarm is silence');
check(MailboxRelay::pickupTransition('skipped', $ago($stall + 60), false, $now) === 'none',
	'a skipped pull (another pull running, no address yet) says nothing');
check(MailboxRelay::pickupTransition('skipped', $ago(10), true, $now) === 'none',
	'...and cannot clear an alarm either');

section('A relay row without an identity pin is an error, not a skip');
$pinless = new MailboxRelay(NULL);
$pinless->set('mrl_name', 'relay1');
$pinless->set('mrl_public_ip', '192.0.2.10');
$pinless->set('mrl_is_enabled', true);
$pull = (new RelaySpoolConsumer($pinless))->pull(5);
check(($pull['status'] ?? '') === 'error', 'pull() answers error for a pinless relay', json_encode($pull));
check(stripos((string)($pull['message'] ?? ''), 'identity pin') !== false, 'the message names the missing pin');
$push = RelayMapSync::push($pinless);
check(($push['status'] ?? '') === 'error', 'push() answers error for a pinless relay', json_encode($push));

section('The admin-header notice: quiet for a healthy relay');
$fine = new MailboxRelay(NULL);
$fine->set('mrl_name', 'relay1');
$fine->set('mrl_is_enabled', true);
$fine->set('mrl_last_pull_time', $ago(60));
check(MailboxAttentionNotice::forRelay($fine) === '', 'a relay with a recent pull and no failure says nothing');
$disabled = new MailboxRelay(NULL);
$disabled->set('mrl_name', 'relay1');
$disabled->set('mrl_is_enabled', false);
$disabled->set('mrl_pickup_alarm_time', $ago(60));
check(MailboxAttentionNotice::forRelay($disabled) === '', 'a disabled relay is not doing the job, so its alarm is not this notice\'s');

section('The admin-header notice: the pickup alarm first');
$stopped = new MailboxRelay(NULL);
$stopped->set('mrl_name', 'relay1');
$stopped->set('mrl_is_enabled', true);
$stopped->set('mrl_last_pull_time', '2026-09-07 15:05:00');
$stopped->set('mrl_pickup_alarm_time', $ago(60));
$stopped->set('mrl_last_health_failure', 'no_identity');
$html = MailboxAttentionNotice::forRelay($stopped);
check(strpos($html, 'Mail is not arriving.') !== false, 'the headline is the consequence', $html);
check(strpos($html, '2026-09-07 15:05:00 UTC') !== false, 'the body names the last successful pickup');
check(strpos($html, 'senders are told it was delivered') !== false, 'the body says why it matters');
check(strpos($html, 'alert-danger') !== false && strpos($html, 'role="alert"') !== false, 'rendered as a red alert');
check(strpos($html, MailboxAttentionNotice::SETUP_URL) !== false, 'it links to mail setup');
check(strpos($html, 'cannot reach') === false, 'the ping failure is not repeated underneath the alarm');

section('The admin-header notice: a failed ping when no alarm stands');
$unreached = new MailboxRelay(NULL);
$unreached->set('mrl_mx_hostname', 'mx.example.net');
$unreached->set('mrl_is_enabled', true);
$unreached->set('mrl_last_health_failure', 'no_identity');
$html = MailboxAttentionNotice::forRelay($unreached);
check(strpos($html, 'cannot reach mx.example.net') !== false, 'the headline names the relay by its MX hostname when it has no name', $html);
check(strpos($html, 'waits on the relay') !== false, 'the body says mail is waiting');
$never = new MailboxRelay(NULL);
$never->set('mrl_name', 'relay1');
$never->set('mrl_is_enabled', true);
$never->set('mrl_pickup_alarm_time', $ago(60));
check(strpos(MailboxAttentionNotice::forRelay($never), 'since it was set up') !== false,
	'a relay never pulled from says so instead of a date');

harness_finish();
