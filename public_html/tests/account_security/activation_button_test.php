<?php
/** @joinery-test
 * name: activation_button
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * Activation is a button (spec email_lists_plugin Q7) and proving an address
 * says so (G16, account.email_verified).
 *
 * Mail scanners open the links in incoming mail. Opening the activation link
 * must verify nothing and leave the code usable; pressing "Verify my email"
 * (a POST) verifies and fires account.email_verified once, with the method.
 *
 * What is pinned here:
 *  - /login?act_code= and /profile?act_code= send the code to /verify-email;
 *    the ScrollDaddy profile does the same and verifies nothing itself;
 *  - a GET of /verify-email shows the button, verifies nothing, fires nothing
 *    and leaves the code valid;
 *  - the POST verifies, fires account.email_verified once (method activation)
 *    and pressing it again fires nothing more;
 *  - a password reset fires it with method password_reset;
 *  - a confirmed address change fires it with method email_change, even for
 *    an account verified before.
 *
 * Signals are observed the way a receiver sees them: a delivery row queued for
 * a sandboxed plugin's receiver, for a plugin that does not exist, so the
 * minute's drain sends nothing anywhere.
 *
 * Run: php tests/run.php db --filter=activation_button
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

if (session_id() === '') @session_start();

harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('register_active', '1');

$db = DbConnector::get_instance()->get_db_link();

$receiver = new SignalReceiver(NULL);
$receiver->set('sgr_kind', SignalReceiver::KIND_SANDBOX);
$receiver->set('sgr_plugin', 'hts_' . bin2hex(random_bytes(3)));
$receiver->set('sgr_name', 'HarnessTest email_verified');
$receiver->set('sgr_signals', array('account.email_verified'));
$receiver->set('sgr_is_active', true);
$receiver->save();
$receiver->load();
harness_register_row('sgr_signal_receivers', 'sgr_signal_receiver_id', (int)$receiver->key);
SignalDeliveryQueue::reset_cache();

$queued = function (int $after) use ($db, $receiver): array {
	$q = $db->prepare("SELECT sgd_payload FROM sgd_signal_deliveries
		WHERE sgd_sgr_signal_receiver_id = ? AND sgd_signal = 'account.email_verified' AND sgd_signal_delivery_id > ?
		ORDER BY sgd_signal_delivery_id");
	$q->execute(array((int)$receiver->key, $after));
	return array_map(function ($p) { return json_decode((string)$p, true); }, $q->fetchAll(PDO::FETCH_COLUMN));
};
$mark = function () use ($db): int {
	return (int)$db->query('SELECT COALESCE(MAX(sgd_signal_delivery_id), 0) FROM sgd_signal_deliveries')->fetchColumn();
};

/** An account whose address is not yet verified, and an activation code for it. */
$unverified = function (string $label) {
	$user = make_user($label);
	$user->set('usr_is_activated', false);
	$user->set('usr_email_is_verified', false);
	$user->save();
	$code = Activation::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));
	harness_defer(function () use ($code) {
		$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM act_activation_codes WHERE act_code = ?');
		$q->execute(array(strtolower($code)));
	});
	return array($user, $code);
};
$is_verified = function ($user): bool {
	$fresh = new User($user->key, TRUE);
	return (bool)$fresh->get('usr_email_is_verified');
};

section('Declared');
$catalog = SignalBus::signals();
check(isset($catalog['account.email_verified']['payload']['method']), 'account.email_verified is in the catalog with a method');

section('The old links send the code to the button');
list($u1, $c1) = $unverified('ActBtnLogin');
$_SESSION = array();
$res = harness_call_logic('logic/login_logic.php', 'login_logic', array('act_code' => $c1), 'GET');
check($res->redirect === '/verify-email?act_code=' . rawurlencode($c1), '/login?act_code= goes to /verify-email', var_export($res->redirect, true));
$res = harness_call_logic('logic/profile_logic.php', 'profile_logic', array('act_code' => $c1), 'GET');
check($res->redirect === '/verify-email?act_code=' . rawurlencode($c1) . '&then=profile', '/profile?act_code= goes to /verify-email, then back to the profile', var_export($res->redirect, true));
check(!$is_verified($u1), 'and neither verified the address');
$dns = file_get_contents(PathHelper::getIncludePath('plugins/dns_filtering/logic/profile_logic.php'));
check(strpos($dns, 'ActivateUser') === false && strpos($dns, "'/verify-email?act_code='") !== false,
	'the ScrollDaddy profile sends the code to /verify-email and verifies nothing itself');

section('Opening the link verifies nothing');
$m = $mark();
$res = harness_call_logic('logic/verify_email_logic.php', 'verify_email_logic', array('act_code' => $c1), 'GET');
check(($res->data['state'] ?? '') === 'confirm', 'a GET shows the Verify button', var_export($res->data['state'] ?? null, true));
check(!$is_verified($u1), 'the address is still unverified');
check(Activation::checkTempCode($c1, Activation::EMAIL_VERIFY), 'the code is still usable');
check($queued($m) === array(), 'nothing fired');

section('Pressing the button verifies, once');
$_SESSION = array();
$res = harness_call_logic('logic/verify_email_logic.php', 'verify_email_logic', array('act_code' => $c1), 'POST');
check($is_verified($u1), 'the POST verifies the address');
check($res->redirect === '/page/verify-email-confirm', 'and continues as the link did', var_export($res->redirect, true));
$got = $queued($m);
check(count($got) === 1 && ($got[0]['user_id'] ?? 0) === (int)$u1->key && ($got[0]['method'] ?? '') === 'activation'
	&& ($got[0]['email'] ?? '') === $u1->get('usr_email'), 'account.email_verified fired once, method activation', json_encode($got));
harness_call_logic('logic/verify_email_logic.php', 'verify_email_logic', array('act_code' => $c1), 'POST');
check(count($queued($m)) === 1, 'pressing it again fires nothing more');

section('Registration closed');
harness_set_setting_mem('register_active', '0');
list($u4, $c4) = $unverified('ActBtnClosed');
$_SESSION = array();
harness_call_logic('logic/verify_email_logic.php', 'verify_email_logic', array('act_code' => $c4), 'POST');
check($is_verified($u4), 'an admin-added member still verifies on a site that takes no sign-ups');
harness_set_setting_mem('register_active', '1');

section('A password reset proves the address too');
list($u2, $c2) = $unverified('ActBtnReset');
$m = $mark();
$user = Activation::ActivateUser($c2, NULL, 'password_reset');
$got = $queued($m);
check($user && $is_verified($u2) && count($got) === 1 && ($got[0]['method'] ?? '') === 'password_reset',
	'method password_reset', json_encode($got));

section('A confirmed address change fires, verified before or not');
$u3 = make_user('ActBtnChange');
$u3->set('usr_email_is_verified', true);
$u3->save();
$new_email = harness_fixture_email('ActBtnChangeNew');
$code3 = Activation::getTempCode($u3->key, '30 days', Activation::EMAIL_CHANGE, NULL, $new_email);
$m = $mark();
$changed = Activation::ChangeEmailUser($code3);
$got = $queued($m);
check($changed && count($got) === 1 && ($got[0]['method'] ?? '') === 'email_change' && ($got[0]['email'] ?? '') === strtolower($new_email),
	'method email_change, with the new address', json_encode($got));

harness_finish();
