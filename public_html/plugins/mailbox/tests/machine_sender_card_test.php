<?php
/** @joinery-test
 * name: machine_sender_card
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The automated mail identity (machine sender) card family
 * (specs/mailbox_machine_sender_card.md):
 *
 *  - transactionalSendBlocker(): empty / invalid / eligible / protected.
 *  - Derived on/off (machineSenderDomainFor): subdomain, equal, unrelated,
 *    multi-label.
 *  - Card states via machineSenderRows(): incident red (blocker, no posture),
 *    grey off-state offer, on-state escalation to REQUIRED, and the
 *    subdomain-of-registered-domain suppression.
 *  - Row placement: the family lands in Sending and never in Receiving.
 *  - A refused ambient send throws AND writes one email_send_refused
 *    EventLog row per From per day (the dedup), exercising the
 *    MultiEventLog created_since filter.
 *  - CalendarEmailEngine reports delivery failures in its 'failed' count and
 *    never retries a claimed message.
 *
 * Provider-API-dependent states (registered/unverified at Mailgun, DKIM
 * record match, the register button) are exercised by the live gate — the
 * jeremytunnell identity flip runs THROUGH the card and is its acceptance
 * test (see project_live_verification_queue).
 *
 * Run: php tests/run.php test-db --filter=machine_sender_card
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_setup_scope.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('data/event_logs_class.php'));

// ── Fixture domains (test DB only) ──────────────────────────────────────────
// Reuse-or-create: the test database persists between runs, so a second run
// must not trip the unique domain constraint. Deleted at teardown either way.
function make_domain($name, $protected = false) {
	$d = InboundEmailDomain::GetByDomain($name);
	if (!$d) {
		$d = new InboundEmailDomain(NULL);
		$d->set('ied_domain', $name);
		$d->prepare();
	}
	$d->set('ied_is_enabled', true);
	$d->set('ied_reject_unmatched', true);
	$d->set('ied_is_protected_identity', $protected);
	$d->save();
	harness_defer(function () use ($d) {
		try { $d->permanent_delete(); } catch (\Throwable $e) { /* best effort */ }
	});
	return $d;
}
$parent    = make_domain('msc-parent.test');
$prot      = make_domain('msc-fortress.test', true);
$child     = make_domain('mail.msc-parent.test');

$checker = new InboundEmailSetupCheck();
$rows_of = function ($domain, $model) use ($checker) {
	$m = new ReflectionMethod('InboundEmailSetupCheck', 'machineSenderRows');
	return $m->invoke($checker, $domain, $model);
};

// ── transactionalSendBlocker ────────────────────────────────────────────────
section('transactionalSendBlocker verdicts');
check(EmailSender::transactionalSendBlocker('') !== null, 'empty address is blocked');
check(EmailSender::transactionalSendBlocker('not-an-address') !== null, 'invalid address is blocked');
check(EmailSender::transactionalSendBlocker('x@msc-parent.test') === null, 'unprotected domain is eligible');
$blocker = EmailSender::transactionalSendBlocker('robot@msc-fortress.test');
check($blocker !== null, 'protected domain is blocked');
check(stripos((string)$blocker, 'protected identity') !== false, 'blocker names the protected identity',
	(string)$blocker);

// ── Derived on/off ──────────────────────────────────────────────────────────
section('machineSenderDomainFor derivation');
harness_set_setting_mem('defaultemail', 'notifications@mail.msc-parent.test');
check($checker->machineSenderDomainFor('msc-parent.test') === 'mail.msc-parent.test',
	'subdomain defaultemail turns the machine sender on');
check($checker->machineSenderDomainFor('mail.msc-parent.test') === '',
	'the machine domain itself is not its own parent');
check($checker->machineSenderDomainFor('msc-fortress.test') === '',
	'an unrelated domain is off');
harness_set_setting_mem('defaultemail', 'a@x.y.msc-parent.test');
check($checker->machineSenderDomainFor('msc-parent.test') === 'x.y.msc-parent.test',
	'multi-label subdomains derive too');
harness_set_setting_mem('defaultemail', 'a@msc-parent.test');
check($checker->machineSenderDomainFor('msc-parent.test') === '',
	'defaultemail on the bare domain is off');

// ── Card states ─────────────────────────────────────────────────────────────
section('Off-state and incident card states');
harness_set_setting_mem('defaultemail', 'a@msc-parent.test');
$rows = $rows_of('msc-parent.test', $parent);
check(count($rows) === 1 && $rows[0]['status'] === InboundEmailSetupCheck::OPTIONAL,
	'bare-domain system mail with no blocker renders the grey off-state card',
	json_encode(array_map(function ($r) { return $r['id'] . ':' . $r['status']; }, $rows)));
check($rows[0]['severity'] === InboundEmailSetupCheck::RECOMMENDED, 'off-state card never escalates');

harness_set_setting_mem('defaultemail', 'robot@msc-fortress.test');
$rows = $rows_of('msc-fortress.test', $prot);
check(count($rows) === 1 && $rows[0]['status'] === InboundEmailSetupCheck::FAIL
	&& $rows[0]['severity'] === InboundEmailSetupCheck::REQUIRED,
	'a protected domain still carrying system mail renders RED even though the feature is off');
check(stripos($rows[0]['summary'], 'cannot send') !== false, 'the incident card leads with the consequence',
	$rows[0]['summary']);

check($rows_of('msc-parent.test', $parent) === array(),
	'a domain unrelated to system mail gets no card at all');

// mail.msc-parent.test is registered AND is a subdomain of a registered
// domain — when system mail sends as it directly, its own focused view stays
// quiet (the parent's on-state card owns the question).
harness_set_setting_mem('defaultemail', 'x@mail.msc-parent.test');
check($rows_of('mail.msc-parent.test', $child) === array(),
	'the off-state card is suppressed on a domain that is itself a subdomain of a registered domain');

section('On-state escalation');
// The fixture machine domain is not registered anywhere, so whatever provider
// this deployment really runs, the identity row must land on an honest
// non-PASS state: INFO (provider not verifiable), FAIL (the provider's API
// answered not-registered), or UNKNOWN (its API did not answer). Never a
// fabricated PASS. The SPF row — checkable from here — escalates to REQUIRED
// and fails on the missing record.
harness_set_setting_mem('defaultemail', 'notifications@mail.msc-parent.test');
harness_set_setting_mem('defaultreplyto', '');
$rows = $rows_of('msc-parent.test', $parent);
$by_id = array();
foreach ($rows as $r) { $by_id[$r['id']] = $r; }
check(isset($by_id['domain.machine_sender'])
	&& $by_id['domain.machine_sender']['status'] !== InboundEmailSetupCheck::PASS,
	'an unregistered machine domain never fabricates a passing identity row',
	json_encode($by_id['domain.machine_sender'] ?? null));
check(isset($by_id['domain.machine_sender.spf'])
	&& $by_id['domain.machine_sender.spf']['severity'] === InboundEmailSetupCheck::REQUIRED,
	'while on, the SPF row is REQUIRED — on-but-misconfigured is red');
check(isset($by_id['domain.machine_sender.replyto'])
	&& $by_id['domain.machine_sender.replyto']['status'] === InboundEmailSetupCheck::INFO,
	'an unset Reply-To is a hint, never red');

section('Row placement: Sending, never Receiving');
$sample = array('id' => 'domain.machine_sender.spf', 'layer' => 'domain', 'status' => 'fail', 'scope' => 'x');
check(_setup_is_sending_row($sample) === true, 'machine rows land in the Sending group');
check(_setup_is_receiving_row($sample) === false,
	'machine rows are excluded from Receiving despite being domain-layer');
check(_setup_is_receiving_row(array('id' => 'domain.mx', 'layer' => 'domain', 'status' => 'pass', 'scope' => 'x')) === true,
	'other domain-layer rows still reach Receiving');

// ── Refusal logging ─────────────────────────────────────────────────────────
section('A refused send throws and logs once per From per day');
require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
$count_refusals = function () {
	$multi = new MultiEventLog(array('event' => 'email_send_refused',
		'created_since' => gmdate('Y-m-d 00:00:00')));
	$multi->load();
	$n = 0;
	foreach ($multi as $row) {
		if (strpos((string)$row->get('evl_note'), 'from=cron@msc-fortress.test ') === 0) { $n++; }
	}
	return $n;
};
$send_refused = function () {
	$msg = EmailMessage::create('nobody@example.com', 'Refusal test', 'plain body');
	$msg->from('cron@msc-fortress.test');
	try {
		(new EmailSender())->send($msg);
		return false;
	} catch (Exception $e) {
		return stripos($e->getMessage(), 'protected identity') !== false;
	}
};
check($send_refused(), 'the ambient send from a protected From still throws');
check($count_refusals() === 1, 'the first refusal writes one email_send_refused row');
check($send_refused(), 'a repeat refusal also throws');
check($count_refusals() === 1, 'the repeat refusal does not write a second row (once per From per day)');

// ── Engine failure honesty ──────────────────────────────────────────────────
section('CalendarEmailEngine reports delivery failures');
require_once(PathHelper::getIncludePath('includes/calendar/CalendarEmailEngine.php'));
require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_preference_class.php'));
require_once(PathHelper::getIncludePath('data/calendar_email_class.php'));
$u = make_user('mscfail');
$u->set('usr_timezone', 'America/Chicago');
$u->save();
$pref = CalendarPreference::get_for((int)$u->key);
$pref->set('cpr_summary_frequency', 'none');
$pref->set('cpr_reminder_default_minutes', 30);
$pref->save();
$entry = new CalendarEntry(NULL);
$entry->set('cal_subject_type', 'user');
$entry->set('cal_subject_id', (int)$u->key);
$entry->set('cal_type', 'personal');
$entry->set('cal_title', 'Failing send');
$entry->set('cal_timezone', 'America/Chicago');
$entry->set('cal_start_utc', '2026-08-10 13:20:00');
$entry->set('cal_end_utc', '2026-08-10 14:20:00');
$entry->save();
harness_defer(function () use ($entry) {
	try { $entry->permanent_delete(); } catch (\Throwable $e) { /* best effort */ }
});

$engine = new CalendarEmailEngine('2026-08-10 13:00:00');
$engine->sender = function () { return false; };   // every delivery fails
$result = $engine->run(false);
check(array_key_exists('failed', $result), "run() reports a 'failed' count");
check($result['failed'] >= 1, 'a failed delivery is counted, not silently dropped',
	json_encode($result));
check($result['reminders'] === 0, 'a failed delivery is not counted as sent');

// The ledger claim stands (at-most-once): a second run must not retry.
$result2 = $engine->run(false);
check($result2['failed'] === 0 && $result2['reminders'] === 0,
	'the claim stands — a failed send is never retried by the engine');

harness_finish();
