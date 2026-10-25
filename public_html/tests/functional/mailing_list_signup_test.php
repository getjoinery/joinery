<?php
/** @joinery-test
 * name: mailing_list_signup
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Public mailing-list signup (/list/{slug} through list_logic, /lists through
 * lists_logic): typing an address proves nothing about owning it.
 *
 *   - A visitor typing the email of an existing account does not subscribe that
 *     account (and so sends it no welcome mail); they are asked to sign in.
 *   - A visitor typing a brand-new email still signs up: the account is made
 *     and subscribed.
 *   - A signed-in member subscribes themselves.
 *
 * Run: php tests/run.php db --only=tests/functional/mailing_list_signup_test.php
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

if (session_id() === '') { @session_start(); }
if (!isset($_SERVER['REQUEST_URI'])) { $_SERVER['REQUEST_URI'] = '/'; }
if (!isset($_SERVER['REMOTE_ADDR'])) { $_SERVER['REMOTE_ADDR'] = '198.51.100.7'; }

$RUN = bin2hex(random_bytes(4));

// Lists on, bot defences that would call out (CAPTCHA) off; the anti-spam
// answer is typed the way a person types it. In memory only.
harness_set_setting_mem('mailing_lists_active', '1');
harness_set_setting_mem('use_honeypot', '1');
harness_set_setting_mem('use_captcha', '0');
harness_set_setting_mem('email_dry_run', '1');
$ANSWER = (string)Globalvars::get_instance()->get_setting('anti_spam_answer');

$list = new MailingList(NULL);
$list->set('mlt_name', 'HarnessTest List ' . $RUN);
$list->set('mlt_link', 'harnesstest-list-' . $RUN);
$list->set('mlt_is_active', true);
$list->set('mlt_visibility', MailingList::VISIBILITY_PUBLIC);
$list->save();
$list->load();
harness_register_row('mlt_mailing_lists', 'mlt_mailing_list_id', $list->key);

// Registrants and the visitor accounts the logic made, swept by list and email.
harness_defer(function () use ($list, $RUN) {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		$db->prepare('DELETE FROM mlr_mailing_list_registrants WHERE mlr_mlt_mailing_list_id = ?')->execute(array($list->key));
		foreach (array('mls1_' . $RUN . '@dev.getjoinery.com', 'mls2_' . $RUN . '@dev.getjoinery.com') as $email) {
			$u = User::GetByEmail($email);
			if ($u) { $u->permanent_delete(); }
		}
	} catch (\Throwable $e) {
		echo '  WARNING: mailing list sweep failed: ' . $e->getMessage() . "\n";
	}
});

function mls_signin($user) {
	if ($user === null) {
		unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
		return;
	}
	$_SESSION['usr_user_id'] = $user->key;
	$_SESSION['loggedin'] = TRUE;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
}

function mls_list_post($list, array $extra) {
	global $ANSWER;
	return harness_call_logic('logic/list_logic.php', 'list_logic', array_merge(array(
		'slug' => $list->get('mlt_link'),
		'usr_first_name' => 'Harness',
		'usr_last_name' => 'Signup',
		'antispam_question' => $ANSWER,
		'mlt_mailing_list_id_subscribe' => $list->key,
	), $extra), 'POST');
}

function mls_lists_post($list, array $extra) {
	global $ANSWER;
	return harness_call_logic('logic/lists_logic.php', 'lists_logic', array_merge(array(
		'usr_first_name' => 'Harness',
		'usr_last_name' => 'Signup',
		'antispam_question' => $ANSWER,
		'new_list_subscribes' => array($list->key),
	), $extra), 'POST');
}

$member = make_user('MlsMember');

// ---------------------------------------------------------------------------
section('A visitor cannot subscribe an existing account');

mls_signin(null);
$res = mls_list_post($list, array('usr_email' => $member->get('usr_email')));
check($res->error !== null && stripos((string)$res->error, 'sign in') !== false,
	'list: a visitor typing a member\'s email is asked to sign in', var_export($res->error, true));
check(!$list->is_user_in_list($member->key), 'list: the member was not subscribed');

$res = mls_lists_post($list, array('usr_email' => strtoupper($member->get('usr_email'))));
check($res->error !== null && stripos((string)$res->error, 'sign in') !== false,
	'lists: a visitor typing a member\'s email (any case) is asked to sign in', var_export($res->error, true));
check(!$list->is_user_in_list($member->key), 'lists: the member was not subscribed');

// ---------------------------------------------------------------------------
section('A brand-new email still signs up');

$new1 = 'mls1_' . $RUN . '@dev.getjoinery.com';
$res = mls_list_post($list, array('usr_email' => $new1));
$u1 = User::GetByEmail($new1);
check($res->error === null && $u1 && $list->is_user_in_list($u1->key),
	'list: a new email makes an account and subscribes it', var_export($res->error, true));

$new2 = 'mls2_' . $RUN . '@dev.getjoinery.com';
$res = mls_lists_post($list, array('usr_email' => $new2));
$u2 = User::GetByEmail($new2);
check($res->error === null && $u2 && $list->is_user_in_list($u2->key),
	'lists: a new email makes an account and subscribes it', var_export($res->error, true));

// ---------------------------------------------------------------------------
section('A signed-in member subscribes themselves');

mls_signin($member);
$res = mls_list_post($list, array('usr_email' => ''));
mls_signin(null);
check($res->error === null && $list->is_user_in_list($member->key),
	'list: the signed-in member is subscribed', var_export($res->error, true));

harness_finish();
