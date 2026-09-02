<?php
/** @joinery-test
 * name: admin_users_message
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * /admin/admin_users_message — a group send is a queued email campaign
 * (specs/group_sends_one_row.md §3.3). The group and single-user entries;
 * the event entries live in plugins/event_manager/tests/event_group_send_test.php.
 *
 * For each entry: one Email row, the right recipient groups, status QUEUED,
 * no msg_messages row, no ntf_notifications row, and no transport touched
 * during the request — a tripwire registered on EmailSender fails the test
 * if any send() runs. A zero-recipient audience reports zero and queues nothing.
 *
 * Run: php tests/run.php db --filter=admin_users_message
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/emails_class.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/group_members_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('data/notifications_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

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

function aum_groups($email) {
	$out = array();
	foreach ($email->get_recipient_groups('add') as $rg) {
		$out[] = $rg->get('erg_provider') . ':' . (int)$rg->get('erg_reference_id');
	}
	sort($out);
	return $out;
}

/** Post the form as $staff and return [LogicResult, Email|null]. */
function aum_send($staff, array $fields) {
	$_SESSION['usr_user_id'] = $staff->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = 10;
	$before = (int)DbConnector::get_instance()->get_db_link()->query('SELECT COALESCE(MAX(eml_email_id),0) FROM eml_emails')->fetchColumn();
	$result = harness_call_logic('adm/logic/admin_users_message_logic.php', 'admin_users_message_logic',
		$fields + array('eml_subject' => 'HarnessTest group send', 'eml_message' => "Hello\neveryone"), 'POST');
	$email = null;
	$emails = new MultiEmail(array('user_id' => $staff->key), array('email_id' => 'DESC'), 1, 0);
	foreach ($emails as $e) {
		if ((int)$e->key > $before) {
			$email = $e;
			harness_register_model('Email', $e->key);
		}
	}
	return array($result, $email);
}

try {
	$staff = make_user('AumStaff', 10);
	$m1 = make_user('AumM1');
	$m2 = make_user('AumM2');
	$someone = make_user('AumOne');

	$msg_before = aum_count('SELECT COUNT(*) FROM msg_messages WHERE msg_usr_user_id_sender = ?', array($staff->key));
	$ntf_before = aum_count('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_source_usr_user_id = ?', array($staff->key));

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
	list($result, $email) = aum_send($staff, array('grp_group_id' => $group->key));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	check($email !== null, 'one Email row was created');
	if ($email) {
		$expected = array('group:' . $group->key, 'user:' . $staff->key); sort($expected);
		check(aum_groups($email) === $expected, 'recipient groups: the group and the sender copy', json_encode(aum_groups($email)));
		check((int)$email->get('eml_status') === Email::EMAIL_QUEUED, 'status is QUEUED');
		check((int)$email->get('eml_usr_user_id') === (int)$staff->key, 'the sender owns the email');
		check($email->get('eml_message_template_html') === Globalvars::get_instance()->get_setting('group_email_inner_template'), 'the group inner template is set');
		check(strpos((string)$email->get('eml_message_html'), '<br') !== false, 'newlines became breaks in the HTML body');
		$n = count(new MultiEmailRecipient(array('email_id' => $email->key)));
		check($n === 3, 'recipients: two members and the sender', "got $n");
		check(($result->data['numrecipients'] ?? -1) === 3, 'the page reports the same count', json_encode($result->data['numrecipients'] ?? null));
		$sent = count(new MultiEmailRecipient(array('email_id' => $email->key, 'sent' => true)));
		check($sent === 0, 'nobody is marked sent during the request');
	}

	section('Send email to user');
	list($result, $email) = aum_send($staff, array('usr_user_id' => $someone->key));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	check($email !== null, 'one Email row was created');
	if ($email) {
		$expected = array('user:' . $someone->key, 'user:' . $staff->key); sort($expected);
		check(aum_groups($email) === $expected, 'recipient groups: the person and the sender copy', json_encode(aum_groups($email)));
		check((int)$email->get('eml_status') === Email::EMAIL_QUEUED, 'status is QUEUED: a single person goes through the queue too');
		check($email->get('eml_message_template_html') === Globalvars::get_instance()->get_setting('individual_email_inner_template'), 'the individual inner template is set');
		$n = count(new MultiEmailRecipient(array('email_id' => $email->key)));
		check($n === 2, 'recipients: the person and the sender', "got $n");
	}

	section('Empty audience');
	$empty_group = new Group(NULL);
	$empty_group->set('grp_name', 'HarnessTest empty group ' . bin2hex(random_bytes(3)));
	$empty_group->set('grp_category', 'user');
	$empty_group->save();
	$empty_group->load();
	harness_register_model('Group', $empty_group->key);
	// The sender's copy would make the audience one; test the model's answer
	// for a truly empty audience separately from the page's.
	list($result, $email) = aum_send($staff, array('grp_group_id' => $empty_group->key));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	if ($email) {
		$n = count(new MultiEmailRecipient(array('email_id' => $email->key)));
		check($n === 1, 'an empty group still delivers the sender their copy', "got $n");
	}
	$bare = new Email(NULL);
	$bare->set('eml_subject', 'HarnessTest bare');
	$bare->set('eml_status', Email::EMAIL_CREATED);
	$bare->save();
	$bare->load();
	harness_register_model('Email', $bare->key);
	$bare->add_recipient_group('group', $empty_group->key);
	check($bare->queue() === 0, 'an audience of nobody queues nothing');
	$bare->load();
	check((int)$bare->get('eml_status') === Email::EMAIL_CREATED, 'and is not reported as sent or queued');

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
	$msg_after = aum_count('SELECT COUNT(*) FROM msg_messages WHERE msg_usr_user_id_sender = ?', array($staff->key));
	$ntf_after = aum_count('SELECT COUNT(*) FROM ntf_notifications WHERE ntf_source_usr_user_id = ?', array($staff->key));
	check($msg_after === $msg_before, 'no msg_messages row was written', "$msg_before -> $msg_after");
	check($ntf_after === $ntf_before, 'no notification was written', "$ntf_before -> $ntf_after");
	check($GLOBALS['aum_sends'] === 0, 'no EmailSender::send() ran during any request', 'sends=' . $GLOBALS['aum_sends']);

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
