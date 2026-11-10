<?php
/** @joinery-test
 * name: direct_message_history_copy
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * migrations/copy_direct_messages_to_history.php — past direct messages,
 * written as campaign Email rows, are copied into the direct-message history
 * the group, event and user pages read (spec email_lists_plugin §6).
 *
 * What is pinned here:
 *  - a sent Email to a group plus the sender's copy is copied once, with the
 *    group as its audience and each recipient's outcome;
 *  - a run again copies nothing more;
 *  - an Email to a mailing list (a campaign) is not copied;
 *  - an Email not yet sent is not copied.
 *
 * Run: php tests/run.php db --filter=direct_message_history_copy
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('migrations/copy_direct_messages_to_history.php'));
if (!class_exists('Email')) {
	section('Past direct messages copied into the history');
	harness_skip('the copy', 'the email_lists plugin is not active here, so there are no campaign rows to copy from');
	harness_finish();
	return;
}
$db = DbConnector::get_instance()->get_db_link();

$staff = make_user('DmhStaff', 10);
$member = make_user('DmhMember');

$group = new Group(NULL);
$group->set('grp_name', 'HarnessTest history group ' . bin2hex(random_bytes(3)));
$group->set('grp_category', 'user');
$group->save();
$group->load();
harness_register_model('Group', $group->key);

/** A campaign-style Email row with recipient groups and recipients. */
$make_email = function (int $status, ?int $list_id, array $groups, array $recipients) use ($staff) {
	$email = new Email(NULL);
	$email->set('eml_usr_user_id', $staff->key);
	$email->set('eml_subject', 'HarnessTest history ' . bin2hex(random_bytes(3)));
	$email->set('eml_message_html', '<p>Hello</p>');
	$email->set('eml_message_template_html', 'group_email_inner');
	$email->set('eml_status', $status);
	$email->set('eml_scheduled_time', gmdate('Y-m-d H:i:s'));
	if ($status === Email::EMAIL_SENT) {
		$email->set('eml_sent_time', gmdate('Y-m-d H:i:s'));
	}
	if ($list_id !== null) {
		$email->set('eml_mlt_mailing_list_id', $list_id);
	}
	$email->save();
	$email->load();
	harness_register_model('Email', $email->key);
	foreach ($groups as $g) {
		$email->add_recipient_group($g[0], $g[1]);
	}
	foreach ($recipients as $r) {
		$rc = new EmailRecipient(NULL);
		$rc->set('erc_eml_email_id', $email->key);
		$rc->set('erc_usr_user_id', $r[0]->key);
		$rc->set('erc_email', $r[0]->get('usr_email'));
		$rc->set('erc_name', $r[0]->display_name());
		$rc->set('erc_status', $r[1]);
		$rc->save();
	}
	return $email;
};
$copies_of = function (Email $email) use ($db): array {
	$q = $db->prepare('SELECT * FROM dmg_direct_messages WHERE dmg_eml_email_id = ?');
	$q->execute(array($email->key));
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		harness_register_row('dmg_direct_messages', 'dmg_direct_message_id', (int)$row['dmg_direct_message_id']);
	}
	return $rows;
};

$sent = $make_email(Email::EMAIL_SENT, null, array(array('group', (int)$group->key), array('user', (int)$staff->key)),
	array(array($member, EmailRecipient::EMAIL_SENT), array($staff, EmailRecipient::SEND_FAILURE)));
$unsent = $make_email(Email::EMAIL_QUEUED, null, array(array('group', (int)$group->key)), array());
$list = null;
if (class_exists('MailingList')) {
	$any_list = (int)$db->query('SELECT COALESCE(MIN(mlt_mailing_list_id), 0) FROM mlt_mailing_lists')->fetchColumn();
	if ($any_list > 0) {
		$list = $make_email(Email::EMAIL_SENT, $any_list, array(array('group', (int)$group->key)), array());
	}
}

section('A sent message to a group is copied');
ob_start();
$ok = copy_direct_messages_to_history();
ob_end_clean();
check($ok === true, 'the migration succeeds');
$rows = $copies_of($sent);
check(count($rows) === 1, 'one copy', (string)count($rows));
if ($rows) {
	$row = $rows[0];
	check($row['dmg_provider'] === 'group' && (int)$row['dmg_reference_id'] === (int)$group->key, 'its audience is the group, not the sender\'s copy');
	check((int)$row['dmg_recipient_count'] === 2 && (int)$row['dmg_status'] === DirectMessage::SENT, 'two recipients, sent');
	$message = new DirectMessage((int)$row['dmg_direct_message_id'], TRUE);
	check($message->delivery_counts() === array('sent' => 1, 'failed' => 1, 'waiting' => 0), 'each recipient\'s outcome is kept', json_encode($message->delivery_counts()));
}

section('Run again');
ob_start();
copy_direct_messages_to_history();
ob_end_clean();
check(count($copies_of($sent)) === 1, 'nothing is copied twice');

section('What is not copied');
check(count($copies_of($unsent)) === 0, 'a message not yet sent');
if ($list) {
	check(count($copies_of($list)) === 0, 'a campaign to a mailing list');
}

harness_finish();
