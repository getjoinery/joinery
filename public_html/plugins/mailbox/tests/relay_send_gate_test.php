<?php
/** @joinery-test
 * name: relay_send_gate
 * tier: db
 * env: any
 * needs: []
 * timeout: 60
 *
 * The relay is inbound only (specs/relay_without_a_shell.md Q1), so every sent
 * message on a hidden-origin deployment leaves through the provider - and the
 * gate on that is a MEASUREMENT, not a provider class: an API provider passes
 * by construction, an SMTP path passes only once the origin-leak probe has
 * round-tripped clean within its freshness window, and everything else is
 * refused with the probe named as the remedy.
 *
 * The probe rows are fixtures in iem_inbound_email_messages, registered for
 * teardown; the provider is switched in memory only.
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));

$settings_before = harness_settings_snapshot();
harness_defer(function () use ($settings_before) { harness_settings_restore($settings_before); });

// ---------------------------------------------------------------------------
section('An API provider passes by construction');

harness_set_setting_mem('email_service', 'mailgun');
harness_set_setting_mem('mailbox_public_ip', '203.0.113.7');
$v = InboundEmailHealth::hiddenOriginSendAllowed();
check($v['allowed'] === true, 'Mailgun submits over an API: allowed without a probe', json_encode($v));

// ---------------------------------------------------------------------------
section('An SMTP provider needs a fresh clean probe');

harness_set_setting_mem('email_service', 'smtp');
$v = InboundEmailHealth::hiddenOriginSendAllowed();
check($v['allowed'] === false && strpos($v['reason'], 'origin-leak probe') !== false,
	'with no probe, SMTP is refused and the probe is named as the remedy', $v['reason']);
check(($v['probe']['state'] ?? '') === 'none' || ($v['probe']['state'] ?? '') === 'failed' || ($v['probe']['state'] ?? '') === 'passed',
	'the verdict carries the probe state');

// A clean probe, delivered just now. The header block carries the marker and
// nothing of this server. The message needs a domain to belong to.
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
$domain = new InboundEmailDomain(NULL);
$domain->set('ied_domain', 'relay-send-gate-' . substr(md5(uniqid()), 0, 6) . '.example');
$domain->set('ied_is_enabled', true);
$domain->save();
harness_register_model('InboundEmailDomain', $domain->key);
$stored = 0;
$mk = function (string $raw) use (&$stored, $domain) {
	$m = new InboundEmailMessage(NULL);
	$m->set('iem_ied_inbound_email_domain_id', intval($domain->key));
	$m->set('iem_recipient', 'probe-target@relay-send-gate.example');
	$m->set('iem_sender', 'probe-target@relay-send-gate.example');
	$m->set('iem_subject', 'origin probe');
	$m->set('iem_raw_message', $raw);
	$m->set('iem_received_time', gmdate('Y-m-d H:i:s'));
	$m->save();
	harness_register_model('InboundEmailMessage', $m->key);
	$stored++;
	return $m;
};
$clean = "Received: from relay.example (relay.example [198.51.100.9])\r\n"
	. InboundEmailHealth::ORIGIN_PROBE_HEADER . ": gate-test\r\nSubject: origin probe\r\n\r\nbody";
// The verdict reads the newest probe, so a one-second gap orders the fixtures.
$mk($clean);
$v = InboundEmailHealth::hiddenOriginSendAllowed();
check($v['allowed'] === true && ($v['probe']['state'] ?? '') === 'passed',
	'a clean probe in the window clears an SMTP path', json_encode($v['probe'] ?? null));

sleep(1);
$leaky = "Received: from box.internal (box.internal [203.0.113.7])\r\n"
	. InboundEmailHealth::ORIGIN_PROBE_HEADER . ": gate-test\r\nSubject: origin probe\r\n\r\nbody";
$mk($leaky);
$v = InboundEmailHealth::hiddenOriginSendAllowed();
check($v['allowed'] === false && ($v['probe']['state'] ?? '') === 'failed' && strpos($v['reason'], 'exposed') !== false,
	'a probe that exposed this server refuses SMTP and says what leaked', json_encode($v));

// An API provider is unaffected by a failed probe: it passes by construction.
harness_set_setting_mem('email_service', 'mailgun');
$v = InboundEmailHealth::hiddenOriginSendAllowed();
check($v['allowed'] === true, 'a failed probe does not stop an API provider');

// ---------------------------------------------------------------------------
section('The setup check reads the same verdict');

$checked = 'no exception';
try {
	// No active relay on this deployment: the check is a no-op, which is the
	// colocated answer. The verdict logic above is what a fronted deployment
	// would hit.
	InboundEmailHealth::checkOutboundTransportClass();
} catch (ProvisioningCheckFailed $e) {
	$checked = $e->getMessage();
}
check($checked === 'no exception' || strpos($checked, 'origin-leak probe') !== false,
	'checkOutboundTransportClass is a no-op without a relay, or names the probe with one', $checked);

harness_finish();
