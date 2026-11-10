<?php
/** @joinery-test
 * name: email_lists_wiring
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 */
/**
 * The email_lists plugin's hooks into core pages (spec email_lists_plugin
 * §4) and its campaign send.
 *
 * What is pinned here:
 *  - G5: the registration form's box names the default list; ticking it asks
 *    for the list, and verifying the account's address subscribes it;
 *  - G1: the member dashboard section lists the member's lists;
 *  - G2/G3: the admin user panel asks the member for a list it ticks, and
 *    takes them off one it unticks;
 *  - G4: a group page's campaigns panel lists a campaign to the group and
 *    leaves out a past direct message core already lists;
 *  - B6: a campaign is not sent to someone who left its list after it was
 *    queued;
 *  - G11: deleting an account deletes its subscriptions.
 *
 * Sends run with email_dry_run on (in memory).
 *
 * Run: php tests/run.php db --filter=email_lists_wiring
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

if (!PluginHelper::isPluginActive('email_lists')) {
	section('Email lists wiring');
	harness_skip('email_lists active', 'plugin inactive on this deployment');
	harness_finish();
	return;
}

// The plugin's hooks register from its serve.php, which the front controller
// loads on every web request; a CLI test loads it itself.
require_once(PathHelper::getIncludePath('plugins/email_lists/serve.php'));

if (session_id() === '') @session_start();
harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('mailing_lists_active', '1');

$run = bin2hex(random_bytes(3));
$list = new MailingList(NULL);
$list->set('mlt_name', 'HarnessTest Wiring ' . $run);
$list->set('mlt_link', 'harnesstest-wiring-' . $run);
$list->set('mlt_is_active', true);
$list->set('mlt_visibility', MailingList::VISIBILITY_PUBLIC);
$list->save();
$list->load();
harness_register_row('mlt_mailing_lists', 'mlt_mailing_list_id', $list->key);
harness_set_setting_mem('default_mailing_list', (string)$list->key);

$on_list = function (User $u) use ($list): bool {
	return (bool)$list->is_user_in_list($u->key);
};

section('G5: the registration box');
ob_start();
$fw = new FormWriterV2HTML5('form1');
SignupFieldRegistry::render($fw, SignupFieldRegistry::CONTEXT_REGISTER, array());
$html = ob_get_clean() . $fw->end_form();
check(strpos($html, 'Please add me to the ' . $list->get('mlt_name') . ' mailing list') !== false, 'the box names the default list');
$joiner = make_user('WiringJoiner');
SignupFieldRegistry::handle($joiner, array('newsletter' => 'yes'), SignupFieldRegistry::CONTEXT_REGISTER);
check(!$on_list($joiner) && in_array((int)$list->key, EmailListSubscriptions::pending_list_ids((string)$joiner->get('usr_email')), true),
	'ticking it asks for the list, waiting for the address to be verified');
EmailListSubscriptions::on_email_verified('account.email_verified', array('user_id' => $joiner->key, 'email' => $joiner->get('usr_email'), 'method' => 'activation'));
check($on_list($joiner), 'and verifying the address subscribes the new account');
$skipper = make_user('WiringSkipper');
SignupFieldRegistry::handle($skipper, array(), SignupFieldRegistry::CONTEXT_REGISTER);
check(!$on_list($skipper), 'leaving it unticked subscribes no one');

section('G1: the member dashboard');
$section = EmailListDashboard::section($joiner);
$names = $section ? array_map(function ($i) { return $i->title; }, $section->items) : array();
check($section && in_array($list->get('mlt_name'), $names, true), 'the section lists the member\'s lists', json_encode($names));

section('G2/G3: the admin user panel');
$admin = make_user('WiringAdmin', 10);
$_SESSION = array();
SessionControl::get_instance()->store_session_variables($admin);
$panel = new MailingListsUserPanel();
$panel->handle('update_mailing_lists', $skipper, array('new_list_subscribes' => array((string)$list->key)));
check(!$on_list($skipper) && in_array((int)$list->key, EmailListSubscriptions::pending_list_ids((string)$skipper->get('usr_email')), true),
	'the panel asks the member, who confirms by email');
harness_defer(function () use ($skipper) {
	DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM mlp_mailing_list_pending WHERE mlp_usr_user_id = ?')->execute(array($skipper->key));
});
$list->add_registrant($skipper->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));
$panel->handle('update_mailing_lists', $skipper, array());
check(!$on_list($skipper), 'and unticking takes them off');
check(strpos($panel->render($joiner, new AdminPage()), $list->get('mlt_name')) !== false, 'the panel shows the lists');

section('G4: campaigns to a group');
$group = new Group(NULL);
$group->set('grp_name', 'HarnessTest wiring group ' . $run);
$group->set('grp_category', 'user');
$group->save();
$group->load();
harness_register_model('Group', $group->key);
$campaign = new Email(NULL);
$campaign->set('eml_subject', 'HarnessTest wiring campaign ' . $run);
$campaign->set('eml_status', Email::EMAIL_SENT);
$campaign->save();
$campaign->load();
harness_register_model('Email', $campaign->key);
$campaign->add_recipient_group('group', (int)$group->key);
$copied = new Email(NULL);
$copied->set('eml_subject', 'HarnessTest wiring copied ' . $run);
$copied->set('eml_status', Email::EMAIL_SENT);
$copied->save();
$copied->load();
harness_register_model('Email', $copied->key);
$copied->add_recipient_group('group', (int)$group->key);
$dm = new DirectMessage(NULL);
$dm->set('dmg_subject', 'HarnessTest wiring copied ' . $run);
$dm->set('dmg_message_html', '<p>x</p>');
$dm->set('dmg_provider', 'group');
$dm->set('dmg_reference_id', (int)$group->key);
$dm->set('dmg_eml_email_id', (int)$copied->key);
$dm->save();
$dm->load();
harness_register_row('dmg_direct_messages', 'dmg_direct_message_id', $dm->key);
$html = AdminEntityPanelRegistry::renderAll('group', $group, new AdminPage());
check(strpos($html, 'HarnessTest wiring campaign ' . $run) !== false, 'the panel lists the campaign to the group');
check(strpos($html, 'HarnessTest wiring copied ' . $run) === false, 'and leaves out the past direct message core lists');

section('B6: no send to someone who left the list');
$stays = make_user('WiringStays');
$leaves = make_user('WiringLeaves');
$list->add_registrant($stays->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));
$list->add_registrant($leaves->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));
$queued = CampaignSender::queue_to_list((int)$list->key, 'HarnessTest wiring send ' . $run, '<p>Hello</p>', 'blank_template');
check($queued !== null, 'a campaign to the list is queued');
if ($queued) {
	harness_register_model('Email', $queued->key);
	$list->remove_registrant($leaves->key);
	$result = CampaignSender::send_email($queued);
	check($result['skipped'] >= 1 && $result['failed'] === 0, 'the one who left is skipped', json_encode($result));
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT erc_status FROM erc_email_recipients WHERE erc_eml_email_id = ? AND erc_usr_user_id = ?');
	$q->execute(array($queued->key, $leaves->key));
	check((int)$q->fetchColumn() === EmailRecipient::UNSUBSCRIBED, 'and recorded as unsubscribed, not sent');
	$q->execute(array($queued->key, $stays->key));
	check((int)$q->fetchColumn() === EmailRecipient::EMAIL_SENT, 'the one who stayed is sent');
}

section('G11: deleting an account deletes its subscriptions');
$gone = make_user('WiringGone');
$list->add_registrant($gone->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));
$gone_id = (int)$gone->key;
$gone->permanent_delete();
$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT count(*) FROM mlr_mailing_list_registrants WHERE mlr_usr_user_id = ?');
$q->execute(array($gone_id));
check((int)$q->fetchColumn() === 0, 'no subscription row is left');

$_SESSION = array();
harness_finish();
