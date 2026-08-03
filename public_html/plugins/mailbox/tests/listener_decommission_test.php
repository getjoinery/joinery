<?php
/** @joinery-test
 * name: listener_decommission
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Local mail listener decommission (specs/mailbox_listener_decommission.md):
 * the guardrail refusal matrix (pure), the recorded-state resolution, the
 * setting/reality mismatch inversion in the setup checks and the health
 * battery, and the helper runner's not-installed refusal. The
 * mailbox_local_listener setting is overridden in memory only
 * (harness_set_setting_mem) — nothing is persisted, and the root helper is
 * never invoked.
 *
 * Run: php tests/run.php db --filter=listener_decommission
 *
 * @version 1.2
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/listener_admin.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

section('guardrail refusal matrix');

$good = array(
	'relay_enabled'    => true,
	'cutover_complete' => true,
	'cutover_reason'   => '',
	'spool_error'      => '',
	'outbound_local'   => false,
	'outbound_label'   => '',
);

check(mailbox_listener_guardrail_failures($good) === array(), 'all guardrails green => no refusal');

$f = mailbox_listener_guardrail_failures(array_merge($good, array('relay_enabled' => false)));
check(count($f) === 1 && strpos($f[0], 'No enabled relay') === 0, 'no enabled relay => one refusal naming it', implode(' | ', $f));

$f = mailbox_listener_guardrail_failures(array_merge($good,
	array('cutover_complete' => false, 'cutover_reason' => 'example.com\'s MX does not point at the relay (mx.example.net) yet.')));
check(count($f) === 1 && strpos($f[0], 'example.com') !== false, 'incomplete cutover => refusal carries the blocking domain', implode(' | ', $f));

$f = mailbox_listener_guardrail_failures(array_merge($good, array('cutover_complete' => false, 'cutover_reason' => '')));
check(count($f) === 1 && strpos($f[0], 'MX') !== false, 'incomplete cutover with no reason still refuses in MX terms');

$f = mailbox_listener_guardrail_failures(array_merge($good, array('spool_error' => 'never pulled')));
check(count($f) === 1 && strpos($f[0], 'spool') !== false, 'stale spool pull => refusal');

$f = mailbox_listener_guardrail_failures(array_merge($good,
	array('outbound_local' => true, 'outbound_label' => 'SMTP via this box\'s own Postfix')));
check(count($f) === 1 && strpos($f[0], 'Postfix') !== false, 'local-Postfix outbound => refusal');

$f = mailbox_listener_guardrail_failures(array(
	'relay_enabled' => false, 'cutover_complete' => false, 'cutover_reason' => '',
	'spool_error' => 'stale', 'outbound_local' => true, 'outbound_label' => 'local sendmail',
));
check(count($f) === 2, 'no relay collapses cutover/spool into one refusal; outbound stays its own', implode(' | ', $f));

section('recorded-state resolution');

harness_set_setting_mem('mailbox_local_listener', 'decommissioned');
check(mailbox_listener_setting() === 'decommissioned', 'decommissioned is read back');

harness_set_setting_mem('mailbox_local_listener', 'garbage');
check(mailbox_listener_setting() === 'active', 'anything but decommissioned resolves to active');

harness_set_setting_mem('mailbox_local_listener', 'active');
check(mailbox_listener_setting() === 'active', 'active is read back');

section('setup-check inversion (setting vs reality)');

$listening = mailbox_listener_port25_listening();
$find = function (array $rows, string $id): ?array {
	foreach ($rows as $r) {
		if ($r['id'] === $id) { return $r; }
	}
	return null;
};

harness_set_setting_mem('mailbox_local_listener', 'decommissioned');
$rows = (new InboundEmailSetupCheck())->checkHostLayer();
$port = $find($rows, 'host.port25');
check($port !== null, 'host.port25 row present under decommissioned');
if ($port !== null) {
	check($port['status'] === ($listening ? InboundEmailSetupCheck::FAIL : InboundEmailSetupCheck::PASS),
		'decommissioned inverts port 25: listening=FAIL, silent=PASS',
		'listening=' . var_export($listening, true) . ' status=' . $port['status']);
}
$pf = $find($rows, 'host.postfix');
check($pf !== null && in_array($pf['status'], array(InboundEmailSetupCheck::PASS, InboundEmailSetupCheck::FAIL), true),
	'host.postfix row present and decided under decommissioned', $pf ? $pf['status'] : 'missing');

harness_set_setting_mem('mailbox_local_listener', 'active');
$checker = new InboundEmailSetupCheck();
$fronted = ($checker->topology()['mode'] !== 'colocated');
$port = $find($checker->checkHostLayer(), 'host.port25');
check($port !== null, 'host.port25 row present under active');
if ($port !== null) {
	$expected = $fronted ? InboundEmailSetupCheck::INFO
		: ($listening ? InboundEmailSetupCheck::PASS : InboundEmailSetupCheck::FAIL);
	check($port['status'] === $expected,
		'active keeps the topology-aware expectation (fronted=INFO, colocated tracks the listener)',
		'fronted=' . var_export($fronted, true) . ' listening=' . var_export($listening, true) . ' status=' . $port['status']);
}

section('health-battery inversion');

$relay_active = (MailboxRelay::active() !== null);
harness_set_setting_mem('mailbox_local_listener', 'decommissioned');
$threw = false;
try {
	InboundEmailHealth::checkInboundMailServer();
} catch (\Throwable $e) {
	$threw = true;
}
if ($relay_active && $listening) {
	check($threw, 'relay active + decommissioned + port 25 answering => health check fails');
} elseif ($relay_active) {
	check(!$threw, 'relay active + decommissioned + port silent => healthy');
} else {
	harness_skip('decommissioned health-check inversion', 'no active relay on this deployment');
}
harness_set_setting_mem('mailbox_local_listener', 'active');

section('cutover evaluation shape');

$state = (new InboundEmailSetupCheck())->relayCutoverState();
check(is_bool($state['complete']) && is_string($state['reason']), 'relayCutoverState returns {complete,reason}');
check($state['complete'] ? $state['reason'] === '' : $state['reason'] !== '',
	'incomplete cutover always names its blocker', var_export($state, true));

section('listener box rendering');

// A stub page satisfying the begin_box/end_box surface — the render paths are
// pure HTML emission and must not fatal for any state shape.
class ListenerBoxStubPage {
	public function begin_box(array $o): void { echo '<div class="box"><h4>' . htmlspecialchars($o['title'] ?? '') . '</h4>'; }
	public function end_box(): void { echo '</div>'; }
}
require_once(PathHelper::getIncludePath('includes/PublicPageBase.php'));
$render = function (array $state): string {
	ob_start();
	mailbox_listener_box_render(new ListenerBoxStubPage(), $state);
	return (string)ob_get_clean();
};

$html = $render(array('setting' => 'decommissioned', 'listening' => false, 'helper_installed' => true,
	'relay_enabled' => false, 'guardrail_failures' => array()));
check(strpos($html, 'Reinstall local mail') !== false && strpos($html, 'listener_restore') !== false,
	'uninstalled + no relay renders the Reinstall action');
check(strpos($html, 'nothing can deliver mail here') !== false,
	'the reinstall offer names the consequence: no inbound path at all', $html);

$html = $render(array('setting' => 'decommissioned', 'listening' => false, 'helper_installed' => true,
	'relay_enabled' => true, 'guardrail_failures' => array()));
check(trim($html) === '',
	'uninstalled while a relay still receives mail renders nothing — reinstalling has no purpose', $html);

$html = $render(array('setting' => 'decommissioned', 'listening' => true, 'helper_installed' => true,
	'relay_enabled' => true, 'guardrail_failures' => array()));
check(strpos($html, 'answers') !== false && strpos($html, 'listener_decommission') !== false,
	'uninstalled + answering port surfaces the mismatch even with a relay up');

$html = $render(array('setting' => 'decommissioned', 'listening' => true, 'helper_installed' => true,
	'relay_enabled' => false, 'guardrail_failures' => array()));
check(strpos($html, 'answers') !== false && strpos($html, 'listener_decommission') !== false,
	'the setting/reality mismatch outranks the relay question');

$html = $render(array('setting' => 'active', 'listening' => true, 'helper_installed' => true,
	'relay_enabled' => true, 'guardrail_failures' => array()));
check(strpos($html, 'Uninstall local mail') !== false && strpos($html, 'alert-warning') !== false,
	'green guardrails render the amber uninstall offer');

$html = $render(array('setting' => 'active', 'listening' => true, 'helper_installed' => true, 'relay_enabled' => false,
	'guardrail_failures' => array('No enabled relay fronts this deployment — the listener is its only way to receive mail.')));
check(trim($html) === '',
	'guardrail failures render nothing at all (the Setup rows carry the missing pieces)');

$html = $render(array('setting' => 'active', 'listening' => true, 'helper_installed' => false,
	'relay_enabled' => true, 'guardrail_failures' => array()));
check(trim($html) === '',
	'missing helper renders nothing at all');

section('the state assembler reports the relay fact');

harness_set_setting_mem('mailbox_local_listener', 'decommissioned');
$assembled = mailbox_listener_state();
check(array_key_exists('relay_enabled', $assembled) && is_bool($assembled['relay_enabled']),
	'mailbox_listener_state carries relay_enabled as a bool');
check($assembled['relay_enabled'] === (MailboxRelay::active() !== null),
	'relay_enabled tracks the active relay, not a re-derivation');
harness_set_setting_mem('mailbox_local_listener', 'active');

section('helper runner');

if (!is_file(mailbox_listener_helper_path())) {
	$run = mailbox_listener_run('off');
	check($run['ok'] === false && strpos($run['message'], 'provision_relay_main.sh') !== false,
		'missing helper refuses and prescribes the bootstrap', $run['message']);
} else {
	harness_skip('missing helper refuses and prescribes the bootstrap', 'helper installed on this box');
}

harness_finish();
