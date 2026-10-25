<?php
/** @joinery-test
 * name: event_waiting_list_guest
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * A visitor who is not signed in joins a full event's waiting list
 * (event_waiting_list_logic) as a web POST under the model write-path check
 * (spec declared_permissions 3.2):
 *
 *   - a new address makes the guest's account and waiting-list row, which no
 *     one is signed in to own, so the logic writes them under its own rule
 *     (the honeypot, anti-spam question and CAPTCHA admitted the request);
 *   - an address an account already uses is refused with a sign-in message,
 *     and that account is not changed: typing an address proves nothing.
 *
 * Run: php tests/run.php --filter=event_waiting_list_guest
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$settings = Globalvars::get_instance();
if (!$settings->get_setting('events_active') || $settings->get_setting('use_captcha')) {
	harness_skip('the guest waiting list', 'events are off or a CAPTCHA is required on this database');
	harness_finish();
}

$event = new Event(NULL);
$event->set('evt_name', 'HarnessTest waitlist ' . bin2hex(random_bytes(3)));
$event->set('evt_start_time', gmdate('Y-m-d H:i:s', time() + 86400));
$event->set('evt_end_time', gmdate('Y-m-d H:i:s', time() + 90000));
$event->set('evt_status', Event::STATUS_ACTIVE);
$event->set('evt_visibility', Event::VISIBILITY_PUBLIC);
$event->save();
$event->load();
harness_register_row('evt_events', 'evt_event_id', $event->key);
$member = make_user('WlMember' . bin2hex(random_bytes(3)), 0);

$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/event_waiting_list';
Access::$sapi = 'fpm-fcgi';
$session = SessionControl::get_instance();
$session->set_api_user(0, null, 0);
harness_defer(function () use ($previous_method, $session) {
	Access::$sapi = PHP_SAPI;
	if ($previous_method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previous_method;
	$session->clear_api_user();
	$_POST = array();
});
require_once(PathHelper::getIncludePath('plugins/event_manager/logic/event_waiting_list_logic.php'));

function wl_post(array $post) {
	$_POST = $post + array('antispam_question' => (string)Globalvars::get_instance()->get_setting('anti_spam_answer'),
		'usr_timezone' => 'America/New_York', 'usr_nickname' => '', 'newsletter' => '');
	try {
		return array(event_waiting_list_logic($_POST), null);
	} catch (Throwable $e) {
		return array(null, get_class($e) . ': ' . $e->getMessage());
	}
}

section('a guest with a new address joins');
$email = 'wl-guest-' . bin2hex(random_bytes(4)) . '@example.com';
list($result, $error) = wl_post(array('event_id' => $event->key, 'usr_first_name' => 'Wait', 'usr_last_name' => 'Lister', 'usr_email' => $email));
check($error === null, 'not refused', (string)$error);
$guest = User::GetByEmail($email);
if ($guest) harness_register_model('User', $guest->key);
check($guest && $guest->key, 'the guest\'s account is made');
$row = $guest ? $db->prepare('SELECT ewl_waiting_list_id FROM ewl_waiting_lists WHERE ewl_usr_user_id = ? AND ewl_evt_event_id = ?') : null;
if ($row) $row->execute(array($guest->key, $event->key));
$wl_id = $row ? $row->fetchColumn() : false;
if ($wl_id) harness_register_row('ewl_waiting_lists', 'ewl_waiting_list_id', $wl_id);
check((bool)$wl_id, 'and its waiting-list row');

section('an address an account already uses is refused, the account unchanged');
$before = $db->prepare('SELECT usr_nickname, usr_timezone FROM usr_users WHERE usr_user_id = ?');
$before->execute(array($member->key));
$before = $before->fetch(PDO::FETCH_ASSOC);
list($result, $error) = wl_post(array('event_id' => $event->key, 'usr_first_name' => 'X', 'usr_last_name' => 'Y',
	'usr_email' => $member->get('usr_email'), 'usr_nickname' => 'hijacked', 'usr_timezone' => 'Asia/Tokyo'));
check($error === null && $result && $result->error && stripos((string)$result->error, 'sign in') !== false,
	'the visitor is told to sign in', (string)($error ?? ($result->error ?? '')));
$after = $db->prepare('SELECT usr_nickname, usr_timezone FROM usr_users WHERE usr_user_id = ?');
$after->execute(array($member->key));
check($after->fetch(PDO::FETCH_ASSOC) == $before, 'the account is not changed');
$on = $db->prepare('SELECT COUNT(*) FROM ewl_waiting_lists WHERE ewl_usr_user_id = ?');
$on->execute(array($member->key));
check((int)$on->fetchColumn() === 0, 'and is not put on the list');

harness_finish();
