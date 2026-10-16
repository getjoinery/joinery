<?php
/** @joinery-test
 * name: support_panel
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The site side of the Help panel (support_chatbot.md, The site side):
 * SupportChatClient, SiteSupportThread and support_origin_confirm, against a
 * stand-in support desk.
 *
 * Pins: nothing is asked before the consent question is answered; a new
 * thread carries a one-time code the site stored, which confirms once and
 * then never again; the site's facts travel only with consent yes, and live
 * reads only with the facts; the desk's thread id and token are kept and sent
 * with every later call; a desk without the support desk reads as "not
 * offered"; a desk's refusal keeps its reason; a reply's unread mark lands on
 * the site's row and clears when the thread is opened; the transcript the
 * panel draws escapes what the desk sent and marks a person's reply.
 *
 * Writes spt_support_threads rows and removes them.
 * Run: php tests/integration/support_panel_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** The serving node, as the site's client sees it. */
class SppStubDesk extends SafeHttpClient {
	public $calls = array();
	public $answers = array();
	public function post(string $url, string $body, array $headers = array()): SafeHttpResponse {
		$this->calls[] = array('url' => $url, 'body' => json_decode($body, true), 'headers' => $headers);
		$a = array_shift($this->answers) ?: array(200, array('api_version' => '1.0', 'data' => array()));
		return new SafeHttpResponse($a[0], array(), json_encode($a[1]), $url);
	}
	public function last(): array { return $this->calls[count($this->calls) - 1]; }
}

$desk = new SppStubDesk();
SupportChatClient::useClient($desk);
require_once(PathHelper::getIncludePath('logic/support_origin_confirm_logic.php'));
harness_set_setting_mem('support_url', 'https://desk.example.org');
// A blank value in memory reads through to the stored one, so the consent
// settings are written as stored rows and put back as they were found.
$spp_stored = array('support_chat_consent' => get_setting_raw('support_chat_consent'),
	'support_chat_live_reads' => get_setting_raw('support_chat_live_reads'));
harness_defer(function () use ($spp_stored) {
	foreach ($spp_stored as $k => $v) { set_setting_raw($k, (string)$v); }
});
set_setting_raw('support_chat_consent', '');
set_setting_raw('support_chat_live_reads', 'no');
Globalvars::get_instance()->forget_setting('support_chat_consent');
$made = array();
harness_defer(function () use (&$made) {
	foreach ($made as $id) {
		$t = new SiteSupportThread($id, TRUE);
		if ($t->key) { $t->permanent_delete(); }
	}
	DbConnector::get_instance()->get_db_link()->exec("DELETE FROM spt_support_threads WHERE spt_destination = 'desk.example.org'");
});

$admin = make_user('spp_admin', 10);
$session = SessionControl::get_instance();
$session->set_api_user((int)$admin->key, null);

// ---------------------------------------------------------------------------
section('Nothing goes before consent');

try {
	SupportChatClient::ask($session, 0, 'hello', '/admin/admin_users');
	check(false, 'an unanswered site is asked first');
} catch (SupportChatException $e) {
	check($e->reason === 'consent' && count($desk->calls) === 0, 'an unanswered site is asked first, and nothing is sent');
}
check(SupportChatClient::destinationHost() === 'desk.example.org', 'support_url names the desk');
check(SupportChatClient::cleanRoute('/admin/admin_users?usr=5') === '/admin/admin_users'
	&& SupportChatClient::cleanRoute('setup:mail_send') === 'setup:mail_send'
	&& SupportChatClient::cleanRoute('javascript:alert(1)') === '', 'a route is a path or a setup step, nothing else');

// ---------------------------------------------------------------------------
section('A new thread: code, facts, token');

set_setting_raw('support_chat_consent', 'yes');
set_setting_raw('support_chat_live_reads', 'yes');
Globalvars::get_instance()->forget_setting('support_chat_consent');
Globalvars::get_instance()->forget_setting('support_chat_live_reads');
$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('thread_id' => 501, 'token' => str_repeat('t', 48),
	'user_message_id' => 9, 'message_id' => 10, 'state' => 'open', 'status' => 'running')));
$r = SupportChatClient::ask($session, 0, 'How do I verify my sender domain?', '/admin/admin_settings_email');
$sent = $desk->last();
$local = SiteSupportThread::mine((int)$r['thread_id']);
$made[] = (int)$local->key;
check(strpos($sent['url'], 'https://desk.example.org/api/v1/action/support_desk/desk_ask') === 0, 'asks the desk\'s desk_ask');
check(($sent['body']['site']['host'] ?? '') === ServicesClient::host() && !empty($sent['body']['site']['version']), 'names this site and its release');
check(isset($sent['body']['bundle']['site']) && ($sent['body']['bundle']['support']['route'] ?? '') === '/admin/admin_settings_email'
	&& ($sent['body']['consent'] ?? null) === array('bundle' => true, 'live_reads' => true), 'with consent: the facts, the route and the flags');
check((int)$local->get('spt_remote_id') === 501 && $local->token() === str_repeat('t', 48) && $r['message_id'] === 10,
	'the desk\'s thread id and token are kept; the poll handle comes back');
check(!array_key_exists('token', $r), 'the token stays on the server: the panel\'s answer does not carry it');
$nonce = (string)($sent['body']['origin_nonce'] ?? '');
check(strlen($nonce) >= 32, 'the first question carries a one-time code');
// The site's row kept only its hash, and the desk's open cleared it; issue one
// as a fresh thread would, and confirm it the way the desk's call back does.
$fresh = SiteSupportThread::begin((int)$admin->key, 'desk.example.org', 'fresh');
$made[] = (int)$fresh->key;
$code = $fresh->issueNonce();
check(SiteSupportThread::consumeNonce($code) === 'desk.example.org', 'the code this site issued confirms, naming the desk it was issued for');
check(SiteSupportThread::consumeNonce($code) === null, 'and confirms only once');
check(SiteSupportThread::consumeNonce(str_repeat('x', 48)) === null, 'a code this site never issued does not');
$old = SiteSupportThread::begin((int)$admin->key, 'desk.example.org', 'old');
$made[] = (int)$old->key;
$old_code = $old->issueNonce();
SiteSupportThread::updateColumns((int)$old->key, array('spt_nonce_time' => gmdate('Y-m-d H:i:s', time() - 3600)));
check(SiteSupportThread::consumeNonce($old_code) === null, 'an hour-old code does not');
$confirm = support_origin_confirm_logic(array('nonce' => 'nope-nope-nope-nope-nope'));
check(empty($confirm->data['confirmed']) && ($confirm->data['version'] ?? '') === '' && ($confirm->data['destination'] ?? '') === '',
	'the confirm action says nothing about the site to a wrong code');

// ---------------------------------------------------------------------------
section('Later calls carry the token');

$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('status' => 'complete', 'state' => 'open',
	'message' => array('id' => 10, 'role' => 'assistant', 'author' => 'assistant', 'content' => "Add the **DKIM** record. <script>alert(1)</script>",
		'status' => 'complete', 'time' => gmdate('Y-m-d H:i:s'), 'feedback' => '', 'reads' => array(array('word' => 'site_log', 'job' => 77, 'ok' => true))))));
$p = SupportChatClient::about('desk_poll', (int)$local->key, array('message_id' => 10));
$sent = $desk->last();
check(($sent['body']['thread_id'] ?? 0) === 501 && ($sent['body']['token'] ?? '') === str_repeat('t', 48), 'a poll names the desk\'s thread with its token');
check(strpos($p['html'], '<strong>DKIM</strong>') !== false && strpos($p['html'], '<script>') === false,
	'the answer is drawn as Markdown with the desk\'s raw HTML escaped');
check(strpos($p['html'], 'read site_log (job #77)') !== false && strpos($p['html'], 'data-verdict="down"') !== false,
	'the reads it made and the thumbs are on the bubble');

// An answer a poll brings is timed in the asker's timezone, as the thread's
// first load draws it, not in UTC.
$admin->set('usr_timezone', 'America/New_York');
$admin->save();
$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('status' => 'complete', 'state' => 'open',
	'message' => array('id' => 11, 'role' => 'assistant', 'author' => 'assistant', 'content' => 'Timed.',
		'status' => 'complete', 'time' => '2026-10-16 19:08:40', 'feedback' => '', 'reads' => array()))));
$p = SupportChatClient::about('desk_poll', (int)$local->key, array('message_id' => 11));
check(strpos($p['html'], '3:08 PM') !== false && strpos($p['html'], '7:08 PM') === false,
	'a polled answer shows the asker\'s local time (19:08 UTC reads 3:08 PM in New York)');

// ---------------------------------------------------------------------------
section('Refusals keep their reason');

$desk->answers[] = array(404, array('api_version' => '1.0', 'errortype' => 'ActionError', 'error' => 'Unknown action: support_desk/desk_ask'));
try {
	SupportChatClient::about('desk_escalate', (int)$local->key, array());
	check(false, 'a node without the desk');
} catch (SupportChatException $e) {
	check($e->reason === 'not_offered', 'a node without the support desk reads as not offered', $e->getMessage());
}
$desk->answers[] = array(422, array('api_version' => '1.0', 'errortype' => 'ActionError', 'error' => 'Your site has used the support desk\'s answers for today.', 'data' => array('reason' => 'budget')));
try {
	SupportChatClient::ask($session, (int)$local->key, 'again', '/admin');
	check(false, 'a budget refusal');
} catch (SupportChatException $e) {
	check($e->reason === 'budget' && strpos($e->getMessage(), 'for today') !== false, 'the desk\'s refusal comes through in its words');
}
try {
	SupportChatClient::about('desk_poll', 999999999, array('message_id' => 1));
	check(false, 'another thread');
} catch (SupportChatException $e) {
	check($e->reason === 'no_thread', 'a thread this site does not hold is refused here, before any call');
}

// ---------------------------------------------------------------------------
section('The unread mark');

$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('threads' => array(
	array('thread_id' => 501, 'title' => 'x', 'state' => 'answered_by_person', 'unread' => true, 'updated' => gmdate('Y-m-d H:i:s'))))));
$before = SiteSupportThread::unreadCount();
$list = SupportChatClient::threads((int)$admin->key);
check(count($list['threads']) === 1 && $list['threads'][0]['unread'] && SiteSupportThread::unreadCount() === $before + 1,
	'a person\'s reply marks the site\'s row unread');
$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('threads' => array(), 'thread' => array('state' => 'answered_by_person'),
	'messages' => array(array('id' => 11, 'role' => 'assistant', 'author' => 'person', 'content' => 'From the desk.', 'status' => 'complete', 'time' => gmdate('Y-m-d H:i:s'))))));
$open = SupportChatClient::threads((int)$admin->key, (int)$local->key);
check(strpos($open['html'], 'From a person') !== false && strpos($open['html'], 'data-verdict') === false,
	'a person\'s reply is marked and carries no thumbs');
check(SiteSupportThread::unreadCount() === $before, 'opening the thread clears its mark');
check(($open['thread']['remote_id'] ?? 0) === 501 && ($open['thread']['desk'] ?? '') === 'desk.example.org',
	'an open thread names its id at the desk and the desk, for a bug report to quote');

// ---------------------------------------------------------------------------
section('An account\'s threads from before this site had a row');

$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('threads' => array(
	array('thread_id' => 777, 'title' => 'Asked before the reinstall', 'state' => 'open', 'unread' => true,
		'updated' => gmdate('Y-m-d H:i:s'), 'account' => true)))));
$list = SupportChatClient::threads((int)$admin->key);
$adopted = null;
foreach (new MultiSiteSupportThread(array('destination' => 'desk.example.org', 'remote_id' => 777)) as $t) {
	$adopted = $t;
}
check($adopted !== null && $adopted->token() === '' && (string)$adopted->get('spt_title') === 'Asked before the reinstall'
	&& count(array_filter($list['threads'], function ($t) use ($adopted) { return $t['thread_id'] === (int)$adopted->key; })) === 1,
	'the account\'s thread is kept here with no token and listed');
$desk->answers[] = array(200, array('api_version' => '1.0', 'data' => array('threads' => array())));
SupportChatClient::threads((int)$admin->key);
$asked_for = array_column((array)($desk->last()['body']['threads'] ?? array()), 'token', 'thread_id');
check(array_key_exists(777, $asked_for) && $asked_for[777] === '', 'later calls name it with no token: the account\'s key opens it');
check(SiteSupportThread::adopt('desk.example.org', 777, 'again')->key === $adopted->key, 'it is kept once');

// ---------------------------------------------------------------------------
section('No desk set, no Help panel');

harness_set_setting_mem('support_url', 'not an address');
$calls_before = count($desk->calls);
check(!SupportChatClient::enabled() && SupportChatClient::destinationUrl() === '', 'without support_url naming a desk the panel is off');
$refused = SupportPanel::guard();
check($refused !== null && ($refused->data['reason'] ?? '') === 'off', 'the panel\'s actions refuse');
try {
	SupportChatClient::call('desk_threads', array());
	check(false, 'no call goes out');
} catch (SupportChatException $e) {
	check($e->reason === 'off' && count($desk->calls) === $calls_before, 'and nothing is sent anywhere');
}

// Blank asks the upgrade source, where problem reports go; with that not an
// https node either, the panel is off. A blank in memory reads through to the
// stored row, so the stored support_url is blanked and put back as found.
$spp_url = get_setting_raw('support_url');
harness_defer(function () use ($spp_url) { set_setting_raw('support_url', (string)$spp_url); });
set_setting_raw('support_url', '');
Globalvars::get_instance()->forget_setting('support_url');
harness_set_setting_mem('upgrade_source', 'https://source.example.net/');
check(SupportChatClient::enabled() && SupportChatClient::destinationUrl() === 'https://source.example.net'
	&& SupportChatClient::destinationHost() === 'source.example.net', 'a blank support_url asks the upgrade source');
harness_set_setting_mem('upgrade_source', 'http://plain.example.net');
check(!SupportChatClient::enabled(), 'an upgrade source that is not https is no desk: the panel is off');
set_setting_raw('support_url', (string)$spp_url);
Globalvars::get_instance()->forget_setting('support_url');
harness_set_setting_mem('support_url', 'https://desk.example.org');

$session->clear_api_user();
harness_finish();
