<?php
/** @joinery-test
 * name: double_opt_in
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Double opt-in (spec email_lists_plugin §10).
 *
 * What is pinned here:
 *  - a visitor's request creates no account and no subscriber, only a
 *    pending request;
 *  - opening the confirmation link confirms nothing; the Confirm POST
 *    subscribes, records time, IP, source and address, creates the account
 *    and verifies its address;
 *  - a subscription row cannot be written without its consent (a REST
 *    create), nor brought back after leaving without a fresh one;
 *  - the signed-in owner of a verified address is confirmed at once;
 *  - one confirmation email per address per day; a repeat request does not
 *    add lists to the link already sent; per-requester and site-wide limits;
 *  - expired requests are pruned;
 *  - with double opt-in off, a signup confirms at once (single_opt_in);
 *  - campaign audiences never include a pending person, nor a subscriber
 *    whose address changed without proof;
 *  - registration's box waits for the account's address to be verified, an
 *    admin's manual verify confirms nothing, the activation does;
 *  - an admin can mark a pending person confirmed, recorded under their name;
 *  - a confirmation link does not verify an account that already existed;
 *  - a subscription cannot be moved to another list;
 *  - a registration request never mailed is mailed by the next request.
 *
 * Settings are set in memory; email_dry_run is on, so nothing is sent.
 *
 * Run: php tests/run.php db --filter=double_opt_in
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

if (!PluginHelper::isPluginActive('email_lists')) {
	section('Double opt-in');
	harness_skip('email_lists active', 'plugin inactive on this deployment');
	harness_finish();
	return;
}

if (session_id() === '') @session_start();
harness_set_setting_mem('email_dry_run', '1');
harness_set_setting_mem('email_lists_double_opt_in', '1');
harness_set_setting_mem('email_lists_confirm_limit_per_ip', '1000');
harness_set_setting_mem('email_lists_confirm_limit_per_site', '100000');
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';

$db = DbConnector::get_instance()->get_db_link();
$run = bin2hex(random_bytes(3));

$make_list = function (string $name) use ($run) {
	$list = new MailingList(NULL);
	$list->set('mlt_name', 'HarnessTest DOI ' . $name . ' ' . $run);
	$list->set('mlt_link', 'harnesstest-doi-' . strtolower($name) . '-' . $run);
	$list->set('mlt_is_active', true);
	$list->set('mlt_visibility', MailingList::VISIBILITY_PUBLIC);
	$list->save();
	$list->load();
	harness_register_row('mlt_mailing_lists', 'mlt_mailing_list_id', $list->key);
	return $list;
};
$a = $make_list('A');
$b = $make_list('B');

$address = function (string $label) use ($run) {
	return strtolower('doi_' . $label . '_' . $run . '@' . HARNESS_FIXTURE_DOMAIN);
};
$pending_rows = function (string $email) use ($db): array {
	$q = $db->prepare('SELECT * FROM mlp_mailing_list_pending WHERE mlp_email = ? ORDER BY mlp_mailing_list_pending_id');
	$q->execute(array($email));
	return $q->fetchAll(PDO::FETCH_ASSOC);
};
/** Give a request a known token, as if its link were in hand. */
$known_token = function (string $email) use ($db): string {
	$token = bin2hex(random_bytes(32));
	$db->prepare('UPDATE mlp_mailing_list_pending SET mlp_token_hash = ? WHERE mlp_email = ?')->execute(array(hash('sha256', $token), $email));
	return $token;
};
$cleanup_address = function (string $email) use ($db) {
	harness_defer(function () use ($email, $db) {
		$db->prepare('DELETE FROM mlp_mailing_list_pending WHERE mlp_email = ?')->execute(array($email));
		$u = User::GetByEmail($email);
		if ($u) {
			$u->permanent_delete();
		}
	});
};

section('A visitor\'s request waits');
$v = $address('visitor');
$cleanup_address($v);
$_SESSION = array();
$result = EmailListSubscriptions::request(null, $v, array($a->key), EmailListSubscriptions::FROM_FORM, array('first_name' => 'Doi', 'last_name' => 'Visitor'));
check($result['pending'] === array((int)$a->key) && $result['sent'], 'it is pending and its confirmation email went', json_encode($result));
check(User::GetByEmail($v) === false || User::GetByEmail($v) === null, 'no account is made for the address');
check(count($pending_rows($v)) === 1, 'one pending row');

section('Opening the link confirms nothing; the button does');
$token = $known_token($v);
$res = harness_call_logic('plugins/email_lists/logic/confirm_logic.php', 'confirm_logic', array('t' => $token), 'GET');
check(($res->data['state'] ?? '') === 'confirm' && count($pending_rows($v)) === 1 && !User::GetByEmail($v), 'a GET shows the button and changes nothing');
$_SERVER['REMOTE_ADDR'] = '192.0.2.78';
$res = harness_call_logic('plugins/email_lists/logic/confirm_logic.php', 'confirm_logic', array('t' => $token), 'POST');
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
$vu = User::GetByEmail($v);
check(($res->data['state'] ?? '') === 'done' && $vu, 'the POST confirms and creates the account', json_encode($res->data['state'] ?? null));
if ($vu) {
	$reg = $a->is_user_in_list($vu->key);
	check($reg && $reg->get('mlr_confirm_source') === MailingListRegistrant::SOURCE_LINK && $reg->get('mlr_confirm_time')
		&& (string)$reg->get('mlr_confirm_ip') === '192.0.2.78' && $reg->get('mlr_confirm_email') === $v, 'with the time, IP, source and address');
	check((bool)(new User($vu->key, TRUE))->get('usr_email_is_verified'), 'and the address is verified');
	check($a->is_consented($vu->key) && count($pending_rows($v)) === 0, 'the person is in the audience and the request is gone');
	$res = harness_call_logic('plugins/email_lists/logic/confirm_logic.php', 'confirm_logic', array('t' => $token), 'GET');
	check(($res->data['state'] ?? '') === 'invalid', 'the link does not work twice');
}

section('A row cannot be written without its consent');
$raw = make_user('DoiRaw');
$row = new MailingListRegistrant(NULL);
$row->set('mlr_usr_user_id', $raw->key);
$row->set('mlr_mlt_mailing_list_id', $a->key);
$refused = false;
try { $row->prepare(); $row->save(); } catch (MailingListRegistrantException $e) { $refused = true; }
check($refused && !$a->is_user_in_list($raw->key), 'a create with no consent is refused (as a REST create is)');
check(in_array('mlr_confirm_source', MailingListRegistrant::$api_unwritable_fields, true)
	&& in_array('mlr_delete_time', MailingListRegistrant::$api_unwritable_fields, true), 'the API can write no consent field, nor the delete time');
if ($vu) {
	$a->remove_registrant($vu->key);
	$left = $a->is_user_in_list($vu->key, true);
	$left->set('mlr_delete_time', null);
	$refused = false;
	try { $left->save(); } catch (MailingListRegistrantException $e) { $refused = true; }
	check($refused && !$a->is_user_in_list($vu->key), 'a left subscription is not brought back without a fresh consent');
}

section('The signed-in owner of a verified address');
$owner = make_user('DoiOwner');
$owner->set('usr_email_is_verified', true);
$owner->save();
$_SESSION = array();
SessionControl::get_instance()->store_session_variables($owner);
EmailListSubscriptions::subscribe($owner, (int)$a->key, EmailListSubscriptions::FROM_MEMBER);
$reg = $a->is_user_in_list($owner->key);
check($reg && $reg->get('mlr_confirm_source') === MailingListRegistrant::SOURCE_VERIFIED_OWNER, 'is confirmed at once');
$_SESSION = array();

section('Limits');
$l = $address('limit');
$cleanup_address($l);
$first = EmailListSubscriptions::request(null, $l, array($a->key), EmailListSubscriptions::FROM_FORM);
$second = EmailListSubscriptions::request(null, $l, array($b->key), EmailListSubscriptions::FROM_FORM);
$rows = $pending_rows($l);
check($first['sent'] && $second['held'] && !$second['sent'], 'one confirmation email per address per day', json_encode($second));
check(count($rows) === 2 && $rows[0]['mlp_last_sent_time'] !== null && $rows[1]['mlp_last_sent_time'] === null
	&& $rows[0]['mlp_token_hash'] !== $rows[1]['mlp_token_hash'],
	'a repeat request does not add a list to the link already sent: the new list is kept, unmailed, on a link of its own');
harness_set_setting_mem('email_lists_confirm_limit_per_ip', '1');
$_SERVER['REMOTE_ADDR'] = '192.0.2.79';
$i1 = $address('ip1');
$i2 = $address('ip2');
$cleanup_address($i1);
$cleanup_address($i2);
$r1 = EmailListSubscriptions::request(null, $i1, array($a->key), EmailListSubscriptions::FROM_FORM);
$r2 = EmailListSubscriptions::request(null, $i2, array($a->key), EmailListSubscriptions::FROM_FORM);
check($r1['sent'] && $r2['held'], 'a requester past the per-day limit is held');
harness_set_setting_mem('email_lists_confirm_limit_per_ip', '1000');
harness_set_setting_mem('email_lists_confirm_limit_per_site', '1');
$_SERVER['REMOTE_ADDR'] = '192.0.2.80';
$s1 = $address('site1');
$cleanup_address($s1);
$r3 = EmailListSubscriptions::request(null, $s1, array($a->key), EmailListSubscriptions::FROM_FORM);
check($r3['held'], 'the site-wide limit holds too');
harness_set_setting_mem('email_lists_confirm_limit_per_site', '100000');
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';

section('A confirmation does not verify an account that already existed');
$squat = make_user('DoiSquat');
$squat->set('usr_email_is_verified', false);
$squat->save();
$sq = strtolower((string)$squat->get('usr_email'));
$cleanup_address($sq);
EmailListSubscriptions::request(null, $sq, array($b->key), EmailListSubscriptions::FROM_FORM);
$res = harness_call_logic('plugins/email_lists/logic/confirm_logic.php', 'confirm_logic', array('t' => $known_token($sq)), 'POST');
check(($res->data['state'] ?? '') === 'done' && $b->is_user_in_list($squat->key), 'the existing account is subscribed');
check(!(new User($squat->key, TRUE))->get('usr_email_is_verified'), 'but its address is not verified: that is its activation\'s job');

section('A subscription cannot be moved to another list');
if ($vu) {
	$a->add_registrant($vu->key, array('source' => MailingListRegistrant::SOURCE_ADMIN));
	$moving = $a->is_user_in_list($vu->key);
	$moving->set('mlr_mlt_mailing_list_id', $b->key);
	$refused = false;
	try { $moving->save(); } catch (MailingListRegistrantException $e) { $refused = true; }
	check($refused && in_array('mlr_mlt_mailing_list_id', MailingListRegistrant::$api_create_only_fields, true), 'refused, and the API sets the list only at creation');
}

section('A registration request that was never mailed is mailed by the next request');
$late = make_user('DoiLate');
EmailListSubscriptions::request($late, (string)$late->get('usr_email'), array($a->key), EmailListSubscriptions::FROM_REGISTER);
$le = strtolower((string)$late->get('usr_email'));
$cleanup_address($le);
$r = EmailListSubscriptions::request($late, $le, array($a->key), EmailListSubscriptions::FROM_FORM);
$rows = $pending_rows($le);
check($r['sent'] && count($rows) === 1 && $rows[0]['mlp_last_sent_time'] !== null, 'the waiting row gets its email', json_encode($r));

section('Expired requests are pruned');
$db->prepare("UPDATE mlp_mailing_list_pending SET mlp_request_time = now() - interval '31 days' WHERE mlp_email = ?")->execute(array($l));
check(MailingListPending::for_token('0' . str_repeat('a', 63)) === array(), 'an unknown token names nothing');
require_once(PathHelper::getIncludePath('plugins/email_lists/tasks/PrunePendingSignups.php'));
(new PrunePendingSignups())->run(array());
check(count($pending_rows($l)) === 0, 'a request past its link\'s lifetime is deleted');

section('Double opt-in off');
harness_set_setting_mem('email_lists_double_opt_in', '0');
$single = make_user('DoiSingle');
EmailListSubscriptions::subscribe($single, (int)$a->key, EmailListSubscriptions::FROM_FORM);
$reg = $a->is_user_in_list($single->key);
check($reg && $reg->get('mlr_confirm_source') === MailingListRegistrant::SOURCE_SINGLE_OPT_IN, 'a signup confirms at once, marked single_opt_in');
harness_set_setting_mem('email_lists_double_opt_in', '1');

section('Audiences');
$p = make_user('DoiPending');
EmailListSubscriptions::subscribe($p, (int)$a->key, EmailListSubscriptions::FROM_FORM);
harness_defer(function () use ($db, $p) {
	$db->prepare('DELETE FROM mlp_mailing_list_pending WHERE mlp_usr_user_id = ?')->execute(array($p->key));
});
$audience = $a->get_subscribed_users('array');
check(!in_array((int)$p->key, array_map('intval', $audience), true), 'a pending person is not in the audience');
check(in_array((int)$owner->key, array_map('intval', $audience), true), 'a confirmed one is');
$owner->set('usr_email', $address('moved'));
$owner->save();
check(!in_array((int)$owner->key, array_map('intval', $a->get_subscribed_users('array')), true),
	'an address changed without proof drops the person until the new one confirms');
check(in_array($a->get('mlt_name'), EmailListPreferences::waiting_names(new User($owner->key, TRUE)), true), 'and their preferences page says so');

section('Registration');
$reg_user = make_user('DoiRegister');
$reg_user->set('usr_email_is_verified', false);
$reg_user->save();
harness_set_setting_mem('default_mailing_list', (string)$b->key);
SignupFieldRegistry::resetCache();
EmailListPreferences::register_signup_field();
SignupFieldRegistry::handle($reg_user, array('newsletter' => 'yes'), SignupFieldRegistry::CONTEXT_REGISTER);
$rows = $pending_rows(strtolower($reg_user->get('usr_email')));
harness_defer(function () use ($db, $reg_user) {
	$db->prepare('DELETE FROM mlp_mailing_list_pending WHERE mlp_usr_user_id = ?')->execute(array($reg_user->key));
});
check(count($rows) === 1 && $rows[0]['mlp_source'] === EmailListSubscriptions::FROM_REGISTER && $rows[0]['mlp_last_sent_time'] === null,
	'the box makes a pending request and sends no list email');
EmailListSubscriptions::on_email_verified('account.email_verified', array('user_id' => $reg_user->key, 'email' => $reg_user->get('usr_email'), 'method' => 'admin'));
EmailListSubscriptions::on_email_verified('account.email_verified', array('user_id' => $reg_user->key, 'email' => $reg_user->get('usr_email'), 'method' => 'some_future_import'));
check(!$b->is_user_in_list($reg_user->key), 'an admin marking the address verified, or an unlisted method, confirms nothing');
EmailListSubscriptions::on_email_verified('account.email_verified', array('user_id' => $reg_user->key, 'email' => $reg_user->get('usr_email'), 'method' => 'activation'));
$reg = $b->is_user_in_list($reg_user->key);
check($reg && $reg->get('mlr_confirm_source') === MailingListRegistrant::SOURCE_ACTIVATION, 'the activation confirms it');

section('An admin marks a pending person confirmed');
$admin = make_user('DoiAdmin', 10);
$m = $address('marked');
$cleanup_address($m);
EmailListSubscriptions::request(null, $m, array($b->key), EmailListSubscriptions::FROM_FORM);
$rows = $pending_rows($m);
if ($rows) {
	$why_not = EmailListSubscriptions::admin_confirm(new MailingListPending((int)$rows[0]['mlp_mailing_list_pending_id'], TRUE), $admin);
	check($why_not === null, 'admin_confirm says it is done', (string)$why_not);
	$mu = User::GetByEmail($m);
	$reg = $mu ? $b->is_user_in_list($mu->key) : null;
	check($reg && $reg->get('mlr_confirm_source') === MailingListRegistrant::SOURCE_ADMIN && (int)$reg->get('mlr_confirm_by_usr_user_id') === (int)$admin->key,
		'recorded as admin, under the admin\'s id');
}

$_SESSION = array();
harness_finish();
