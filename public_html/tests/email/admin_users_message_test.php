<?php
/** @joinery-test
 * name: admin_users_message
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * /admin/admin_users_message — a direct message to a group or one member
 * (spec email_lists_plugin §3). The event entries live in
 * plugins/event_manager/tests/event_group_send_test.php.
 *
 * What is pinned here:
 *  - each send writes one DirectMessage (no campaign Email row) with its
 *    audience, template and one recipient per address, the sender's copy
 *    included, and nothing is sent during the request;
 *  - the background pass sends each recipient once, records who it went to
 *    and who it did not, marks the message sent, and a second pass sends
 *    nothing again;
 *  - a refused send is tried again later, and not sent after its last try;
 *    a pass that died mid-send leaves nothing marked sending; a provider
 *    refusing every send stops the pass with the rest untried;
 *  - the group page's history lists the message;
 *  - no target, two targets, and an empty subject or body are refused.
 *
 * Sends run with email_dry_run on (in memory), so nothing leaves the box.
 *
 * Run: php tests/run.php db --filter=admin_users_message
 *
 * @version 2.0 - a DirectMessage and its background send, not a campaign row
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

harness_set_setting_mem('email_dry_run', '1');
$db = DbConnector::get_instance()->get_db_link();

$GLOBALS['aum_sends'] = 0;
EmailSender::registerDirectAttempt(function ($message) {
	$GLOBALS['aum_sends']++;
	return array();
});

function aum_count($sql, array $params) {
	$q = DbConnector::get_instance()->get_db_link()->prepare($sql);
	$q->execute($params);
	return (int)$q->fetchColumn();
}

/** Post the form as $staff and return [LogicResult, DirectMessage|null]. */
function aum_send($staff, array $fields) {
	$_SESSION['usr_user_id'] = $staff->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = 10;
	$result = harness_call_logic('adm/logic/admin_users_message_logic.php', 'admin_users_message_logic',
		$fields + array('eml_subject' => 'HarnessTest direct message', 'eml_message' => "Hello\neveryone"), 'POST');
	$message = $result->data['message'] ?? null;
	if ($message) {
		harness_register_row('dmg_direct_messages', 'dmg_direct_message_id', (int)$message->key);
	}
	return array($result, $message);
}

function aum_addresses(DirectMessage $message): array {
	$out = array();
	foreach (new MultiDirectMessageRecipient(array('dmr_dmg_direct_message_id' => $message->key)) as $r) {
		$out[] = (int)$r->get('dmr_usr_user_id');
	}
	sort($out);
	return $out;
}

try {
	$staff = make_user('AumStaff', 10);
	$m1 = make_user('AumM1');
	$m2 = make_user('AumM2');
	$someone = make_user('AumOne');

	$eml_before = aum_count('SELECT COUNT(*) FROM eml_emails WHERE eml_usr_user_id = ?', array($staff->key));

	$group = new Group(NULL);
	$group->set('grp_name', 'HarnessTest send group ' . bin2hex(random_bytes(3)));
	$group->set('grp_category', 'user');
	$group->save();
	$group->load();
	harness_register_model('Group', $group->key);
	foreach (array($m1, $m2) as $u) {
		$gm = new GroupMember(NULL);
		$gm->set('grm_grp_group_id', $group->key);
		$gm->set('grm_foreign_key_id', $u->key);
		$gm->save();
		$gm->load();
		harness_register_model('GroupMember', $gm->key);
	}

	section('Email group');
	list($result, $message) = aum_send($staff, array('grp_group_id' => $group->key));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	check($message !== null, 'one DirectMessage was written');
	if ($message) {
		check($message->get('dmg_provider') === 'group' && (int)$message->get('dmg_reference_id') === (int)$group->key, 'its audience is the group');
		check((int)$message->get('dmg_status') === DirectMessage::QUEUED, 'status is queued');
		check((int)$message->get('dmg_usr_user_id_sender') === (int)$staff->key, 'the sender is recorded');
		check($message->get('dmg_inner_template') === Globalvars::get_instance()->get_setting('group_email_inner_template'), 'the group inner template is set');
		check(strpos((string)$message->get('dmg_message_html'), '<br') !== false, 'newlines became breaks in the HTML body');
		$expected = array((int)$m1->key, (int)$m2->key, (int)$staff->key); sort($expected);
		check(aum_addresses($message) === $expected, 'recipients: two members and the sender', json_encode(aum_addresses($message)));
		check(($result->data['numrecipients'] ?? -1) === 3 && (int)$message->get('dmg_recipient_count') === 3, 'the page and the row report the same count');
		check($message->delivery_counts() === array('sent' => 0, 'failed' => 0, 'waiting' => 3), 'nobody is sent during the request');

		section('The background send');
		$done = DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key);
		check($done === array('sent' => 3, 'failed' => 0), 'the pass sends each recipient', json_encode($done));
		$message->load();
		check((int)$message->get('dmg_status') === DirectMessage::SENT && $message->get('dmg_sent_time'), 'and marks the message sent');
		check(DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key) === array('sent' => 0, 'failed' => 0), 'a second pass sends nothing again');

		section('The group page lists it');
		$history = AdminPage::direct_messages_to('group', (int)$group->key, 20, 0);
		$ids = array();
		foreach ($history as $h) { $ids[] = (int)$h->key; }
		check(in_array((int)$message->key, $ids, true), 'the group\'s history has the message');
	}

	section('Send email to user');
	list($result, $message) = aum_send($staff, array('usr_user_id' => $someone->key));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	if ($message) {
		check($message->get('dmg_provider') === 'user' && (int)$message->get('dmg_reference_id') === (int)$someone->key, 'its audience is the person');
		check($message->get('dmg_inner_template') === Globalvars::get_instance()->get_setting('individual_email_inner_template'), 'the individual inner template is set');
		$expected = array((int)$someone->key, (int)$staff->key); sort($expected);
		check(aum_addresses($message) === $expected, 'recipients: the person and the sender');
	}

	section('A recipient the provider refuses');
	list($result, $message) = aum_send($staff, array('usr_user_id' => $someone->key));
	if ($message) {
		$db->prepare("UPDATE dmr_direct_message_recipients SET dmr_email = 'not an address' WHERE dmr_dmg_direct_message_id = ? AND dmr_usr_user_id = ?")
			->execute(array($message->key, $someone->key));
		$done = DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key);
		check($done === array('sent' => 1, 'failed' => 0), 'the refused one is not given up on the first try', json_encode($done));
		check($message->delivery_counts() === array('sent' => 1, 'failed' => 0, 'waiting' => 1), 'it waits for a later try');
		check(DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key) === array('sent' => 0, 'failed' => 0),
			'and is not tried again before its retry time');
		$db->prepare("UPDATE dmr_direct_message_recipients SET dmr_next_try_time = NULL, dmr_attempts = ? WHERE dmr_dmg_direct_message_id = ? AND dmr_usr_user_id = ?")
			->execute(array(DirectMessage::MAX_ATTEMPTS - 1, $message->key, $someone->key));
		$done = DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key);
		check($done === array('sent' => 0, 'failed' => 1), 'its last try fails it for good', json_encode($done));
		check($message->delivery_counts() === array('sent' => 1, 'failed' => 1, 'waiting' => 0), 'and the message says one sent, one not');
		$message->load();
		check((int)$message->get('dmg_status') === DirectMessage::SENT, 'with nothing waiting, the message is done');
	}

	section('A pass that died mid-send');
	list($result, $message) = aum_send($staff, array('usr_user_id' => $someone->key));
	if ($message) {
		$db->prepare("UPDATE dmr_direct_message_recipients SET dmr_status = ?, dmr_claim_time = now() - interval '1 hour' WHERE dmr_dmg_direct_message_id = ?")
			->execute(array(DirectMessageRecipient::SENDING, $message->key));
		DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key);
		check($message->delivery_counts() === array('sent' => 0, 'failed' => 2, 'waiting' => 0), 'its stale claims are not sent, never sent twice', json_encode($message->delivery_counts()));
		$message->load();
		check((int)$message->get('dmg_status') === DirectMessage::SENT, 'and the message no longer reads as sending');
	}

	section('A provider refusing every send stops the pass');
	$many = new Group(NULL);
	$many->set('grp_name', 'HarnessTest refused group ' . bin2hex(random_bytes(3)));
	$many->set('grp_category', 'user');
	$many->save();
	$many->load();
	harness_register_model('Group', $many->key);
	for ($i = 0; $i < DirectMessage::STOP_AFTER_FAILURES + 2; $i++) {
		$u = make_user('AumMany' . $i);
		$gm = new GroupMember(NULL);
		$gm->set('grm_grp_group_id', $many->key);
		$gm->set('grm_foreign_key_id', $u->key);
		$gm->save();
		$gm->load();
		harness_register_model('GroupMember', $gm->key);
	}
	list($result, $message) = aum_send($staff, array('grp_group_id' => $many->key));
	if ($message) {
		$db->prepare("UPDATE dmr_direct_message_recipients SET dmr_email = 'not an address' WHERE dmr_dmg_direct_message_id = ?")
			->execute(array($message->key));
		DirectMessage::send_pending(DirectMessage::SEND_BATCH, (int)$message->key);
		$q = $db->prepare('SELECT COUNT(*) FROM dmr_direct_message_recipients WHERE dmr_dmg_direct_message_id = ? AND COALESCE(dmr_attempts, 0) = 0');
		$q->execute(array($message->key));
		check((int)$q->fetchColumn() === (int)$message->get('dmg_recipient_count') - DirectMessage::STOP_AFTER_FAILURES,
			'after ' . DirectMessage::STOP_AFTER_FAILURES . ' refusals in a row the rest are left untried');
	}

	section('Empty audience');
	$empty_group = new Group(NULL);
	$empty_group->set('grp_name', 'HarnessTest empty group ' . bin2hex(random_bytes(3)));
	$empty_group->set('grp_category', 'user');
	$empty_group->save();
	$empty_group->load();
	harness_register_model('Group', $empty_group->key);
	list($result, $message) = aum_send($staff, array('grp_group_id' => $empty_group->key));
	check($message && (int)$message->get('dmg_recipient_count') === 1, 'an empty group still delivers the sender their copy');

	section('Refusals');
	$r = harness_call_logic('adm/logic/admin_users_message_logic.php', 'admin_users_message_logic', array(), 'GET');
	check((bool)$r->error, 'no target is refused');
	$r = harness_call_logic('adm/logic/admin_users_message_logic.php', 'admin_users_message_logic',
		array('grp_group_id' => $group->key, 'usr_user_id' => $someone->key), 'GET');
	check((bool)$r->error, 'two targets are refused');
	$r = harness_call_logic('adm/logic/admin_users_message_logic.php', 'admin_users_message_logic',
		array('grp_group_id' => $group->key, 'eml_subject' => '', 'eml_message' => ''), 'POST');
	check((bool)$r->error, 'an empty subject and body are refused');

	section('Nothing else happened');
	check(aum_count('SELECT COUNT(*) FROM eml_emails WHERE eml_usr_user_id = ?', array($staff->key)) === $eml_before, 'no campaign Email row was written');

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
