<?php
/** @joinery-test
 * name: event_group_send
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * "Email registrants" and "Email waiting list" on the event admin page queue
 * one email campaign each (specs/group_sends_one_row.md §3.3, §3.4):
 *
 *  - registrants: ('event', id) + the sender's copy + the leader's copy;
 *  - waiting list: ('event_waiting_list', id) + the sender's copy, no leader;
 *  - nothing sent in the request, no message or notification rows;
 *  - the event admin logic lists each email under the right box.
 *
 * Run: php tests/run.php db --filter=event_group_send
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

if (!PluginHelper::isPluginActive('event_manager')) {
	section('Event group send');
	harness_skip('event_manager active', 'plugin inactive on this deployment');
	harness_finish();
	return;
}

require_once(PathHelper::getIncludePath('data/emails_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_waiting_lists_class.php'));
// The event and waiting-list providers register from the plugin's serve.php.
require_once(PathHelper::getIncludePath('plugins/event_manager/serve.php'));

$GLOBALS['egs_sends'] = 0;
EmailSender::registerDirectAttempt(function ($message) {
	$GLOBALS['egs_sends']++;
	return array();
});

function egs_groups($email) {
	$out = array();
	foreach ($email->get_recipient_groups('add') as $rg) {
		$out[] = $rg->get('erg_provider') . ':' . (int)$rg->get('erg_reference_id');
	}
	sort($out);
	return $out;
}

function egs_send($staff, array $fields) {
	$_SESSION['usr_user_id'] = $staff->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = 10;
	$before = (int)DbConnector::get_instance()->get_db_link()->query('SELECT COALESCE(MAX(eml_email_id),0) FROM eml_emails')->fetchColumn();
	$result = harness_call_logic('adm/logic/admin_users_message_logic.php', 'admin_users_message_logic',
		$fields + array('eml_subject' => 'HarnessTest event send', 'eml_message' => 'See you there'), 'POST');
	$email = null;
	foreach (new MultiEmail(array('user_id' => $staff->key), array('email_id' => 'DESC'), 1, 0) as $e) {
		if ((int)$e->key > $before) {
			$email = $e;
			harness_register_model('Email', $e->key);
		}
	}
	return array($result, $email);
}

function egs_ids($emails) {
	$ids = array();
	foreach ($emails as $e) { $ids[] = (int)$e->key; }
	return $ids;
}

try {
	$staff = make_user('EgsStaff', 10);
	$leader = make_user('EgsLeader');
	$r1 = make_user('EgsR1');
	$r2 = make_user('EgsR2');
	$w1 = make_user('EgsW1');

	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare('SELECT COUNT(*) FROM msg_messages WHERE msg_usr_user_id_sender = ?');
	$q->execute(array($staff->key));
	$msg_before = (int)$q->fetchColumn();

	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest send event ' . bin2hex(random_bytes(3)));
	$event->set('evt_start_time', gmdate('Y-m-d H:i:s', time() + 86400));
	$event->set('evt_end_time', gmdate('Y-m-d H:i:s', time() + 90000));
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->set('evt_visibility', Event::VISIBILITY_PUBLIC);
	$event->set('evt_usr_user_id_leader', $leader->key);
	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	foreach (array($r1, $r2) as $u) {
		$reg = new EventRegistrant(NULL);
		$reg->set('evr_evt_event_id', $event->key);
		$reg->set('evr_usr_user_id', $u->key);
		$reg->save();
		$reg->load();
		harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $reg->key);
	}
	$wl = new WaitingList(NULL);
	$wl->set('ewl_evt_event_id', $event->key);
	$wl->set('ewl_usr_user_id', $w1->key);
	$wl->save();
	$wl->load();
	harness_register_row('ewl_event_waiting_lists', 'ewl_waiting_list_id', $wl->key);

	section('Email registrants');
	list($result, $reg_email) = egs_send($staff, array('evt_event_id' => $event->key));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	check($reg_email !== null, 'one Email row was created');
	if ($reg_email) {
		$expected = array('event:' . $event->key, 'user:' . $staff->key, 'user:' . $leader->key); sort($expected);
		check(egs_groups($reg_email) === $expected, 'recipient groups: the event, the sender copy, the leader copy', json_encode(egs_groups($reg_email)));
		check((int)$reg_email->get('eml_status') === Email::EMAIL_QUEUED, 'status is QUEUED');
		check($reg_email->get('eml_message_template_html') === Globalvars::get_instance()->get_setting('event_email_inner_template'), 'the event inner template is set');
		$n = count(new MultiEmailRecipient(array('email_id' => $reg_email->key)));
		check($n === 4, 'recipients: two registrants, the leader, the sender', "got $n");
		check(($result->data['numrecipients'] ?? -1) === 4, 'the page reports the same count');
		$w_on = count(new MultiEmailRecipient(array('email_id' => $reg_email->key, 'user_id' => $w1->key)));
		check($w_on === 0, 'the waiting list is not on the registrants email');
	}

	section('Email waiting list');
	list($result, $wl_email) = egs_send($staff, array('evt_event_id' => $event->key, 'waiting_list' => 1));
	check(!$result->error, 'the send is accepted', (string)$result->error);
	check($wl_email !== null, 'one Email row was created');
	if ($wl_email) {
		$expected = array('event_waiting_list:' . $event->key, 'user:' . $staff->key); sort($expected);
		check(egs_groups($wl_email) === $expected, 'recipient groups: the waiting list and the sender copy, no leader', json_encode(egs_groups($wl_email)));
		$n = count(new MultiEmailRecipient(array('email_id' => $wl_email->key)));
		check($n === 2, 'recipients: the one waiting and the sender', "got $n");
	}

	section('Where the record shows');
	$to_registrants = egs_ids(new MultiEmail(array('recipient_group' => array('provider' => 'event', 'reference_id' => $event->key))));
	$to_waiting = egs_ids(new MultiEmail(array('recipient_group' => array('provider' => 'event_waiting_list', 'reference_id' => $event->key))));
	check($reg_email && in_array((int)$reg_email->key, $to_registrants, true), 'the registrants email lists under Emails to Registrants');
	check($reg_email && !in_array((int)$reg_email->key, $to_waiting, true), 'and not under Emails to Waiting List');
	check($wl_email && in_array((int)$wl_email->key, $to_waiting, true), 'the waiting-list email lists under Emails to Waiting List');
	check($wl_email && !in_array((int)$wl_email->key, $to_registrants, true), 'and not under Emails to Registrants');

	$_SESSION['usr_user_id'] = $staff->key; $_SESSION['loggedin'] = true; $_SESSION['permission'] = 10;
	$page = harness_call_logic('plugins/event_manager/admin/logic/admin_event_logic.php', 'admin_event_logic',
		array('evt_event_id' => $event->key), 'GET');
	check(!$page->error, 'the event admin logic renders', (string)$page->error);
	if (!$page->error) {
		check(isset($page->data['registrant_emails']) && in_array((int)$reg_email->key, egs_ids($page->data['registrant_emails']), true),
			'the admin page carries the registrants email');
		check(isset($page->data['waiting_list_emails']) && in_array((int)$wl_email->key, egs_ids($page->data['waiting_list_emails']), true),
			'the admin page carries the waiting-list email');
	}

	section('Nothing else happened');
	$q->execute(array($staff->key));
	check((int)$q->fetchColumn() === $msg_before, 'no msg_messages row was written');
	check($GLOBALS['egs_sends'] === 0, 'no EmailSender::send() ran during any request', 'sends=' . $GLOBALS['egs_sends']);

} catch (\Throwable $e) {
	check(false, 'no exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
