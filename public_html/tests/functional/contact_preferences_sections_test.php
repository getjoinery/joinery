<?php
/** @joinery-test
 * name: contact_preferences_sections
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * The member's email preferences page hosts sections plugins contribute
 * (spec email_lists_plugin G6, ContactPreferencesRegistry).
 *
 * What is pinned here:
 *  - a signed-in member's page renders every section, and a POST hands each
 *    section the posted input, with via_link false;
 *  - an email footer's link (user + hash) opens the page signed out, its form
 *    carries the user and hash, and saving through it works, with via_link true;
 *  - through the link the list section offers only lists the person is on
 *    and can take them off, never add one;
 *  - a wrong hash is refused and saves nothing.
 *
 * Run: php tests/run.php db --filter=contact_preferences_sections
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

if (session_id() === '') @session_start();

$saved = array();
ContactPreferencesRegistry::register('hts_prefs', array(
	'render' => function ($fw, User $user, array $context) {
		$fw->checkboxinput('hts_pref', 'HarnessTest preference' . (!empty($context['via_link']) ? ' (link)' : ''), array('value' => 'yes'));
	},
	'save' => function (User $user, array $input, array $context) use (&$saved): array {
		$saved[] = array('user' => (int)$user->key, 'value' => $input['hts_pref'] ?? null, 'via_link' => !empty($context['via_link']));
		return array(array('message_title' => 'Saved', 'message' => 'HarnessTest saved', 'message_type' => 'success'));
	},
));

$member = make_user('PrefSections');
$member->load();

section('Signed in');
$_SESSION = array();
SessionControl::get_instance()->store_session_variables($member);
$res = harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic', array(), 'GET');
check(!empty($res->data['has_sections']) && empty($res->data['via_link']), 'the page has sections and is not a link visit');
ob_start();
$fw = new FormWriterV2HTML5('form1');
contact_preferences_logic_form($fw, $member, array());
$html = ob_get_clean() . $fw->end_form();
check(strpos($html, 'HarnessTest preference') !== false && strpos($html, 'name="hash"') === false,
	'the form renders the section and carries no hash');

$res = harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic', array('hts_pref' => 'yes'), 'POST');
check(count($saved) === 1 && $saved[0]['user'] === (int)$member->key && $saved[0]['value'] === 'yes' && $saved[0]['via_link'] === false,
	'a POST hands the section its input', json_encode($saved));
$said = array_column($res->data['messages'] ?? array(), 'message');
check(in_array('HarnessTest saved', $said, true), 'and shows what it said');

section('Through the footer link, signed out');
$saved = array();
$_SESSION = array();
$hash = (string)$member->get('usr_authhash');
check($hash !== '', 'the member has a link hash');
$link = array('user' => (int)$member->key, 'hash' => $hash);
$res = harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic', $link, 'GET');
check(!empty($res->data['via_link']) && (int)$res->data['user']->key === (int)$member->key, 'the link opens the member\'s page');
ob_start();
$fw = new FormWriterV2HTML5('form2');
contact_preferences_logic_form($fw, $member, $link);
$html = ob_get_clean() . $fw->end_form();
check(strpos($html, 'HarnessTest preference (link)') !== false && strpos($html, 'name="hash"') !== false && strpos($html, 'name="user"') !== false,
	'the form tells the section it is a link visit and carries the user and hash');
harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic', $link + array('hts_pref' => 'yes'), 'POST');
check(count($saved) === 1 && $saved[0]['via_link'] === true, 'saving through the link works, with via_link', json_encode($saved));

section('Through the link, the list section only stops mail');
if (class_exists('MailingList')) {
	// The section is the email_lists plugin's, registered from its serve.php.
	require_once(PathHelper::getIncludePath('plugins/email_lists/serve.php'));
	$run = bin2hex(random_bytes(3));
	$make_list = function (string $name) use ($run) {
		$list = new MailingList(NULL);
		$list->set('mlt_name', 'HarnessTest prefs ' . $name . ' ' . $run);
		$list->set('mlt_link', 'harnesstest-prefs-' . strtolower($name) . '-' . $run);
		$list->set('mlt_is_active', true);
		$list->set('mlt_visibility', MailingList::VISIBILITY_PUBLIC);
		$list->save();
		$list->load();
		harness_register_row('mlt_mailing_lists', 'mlt_mailing_list_id', $list->key);
		return $list;
	};
	$on = $make_list('On');
	$off = $make_list('Off');
	$on->add_registrant($member->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));
	$is_on = function ($list) use ($member): bool {
		return count(new MultiMailingListRegistrant(array('deleted' => false, 'user_id' => $member->key, 'mailing_list_id' => $list->key))) > 0;
	};
	ob_start();
	$fw = new FormWriterV2HTML5('form3');
	contact_preferences_logic_form($fw, $member, $link);
	$html = ob_get_clean() . $fw->end_form();
	check(strpos($html, $on->get('mlt_name')) !== false && strpos($html, $off->get('mlt_name')) === false,
		'the link\'s form offers the lists the person is on, not others');
	harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic',
		$link + array('new_list_subscribes' => array((string)$on->key, (string)$off->key)), 'POST');
	check($is_on($on) && !$is_on($off), 'a list posted by hand through the link subscribes no one');
	harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic',
		$link + array('new_list_subscribes' => array()), 'POST');
	check(!$is_on($on), 'unchecking a list through the link takes the person off it');
}

section('A wrong hash');
$saved = array();
$res = harness_call_logic('logic/contact_preferences_logic.php', 'contact_preferences_logic',
	array('user' => (int)$member->key, 'hash' => $hash . 'x', 'hts_pref' => 'yes'), 'POST');
check(!empty($res->error) && $saved === array(), 'is refused and saves nothing', var_export($res->error ?? null, true));

harness_finish();
