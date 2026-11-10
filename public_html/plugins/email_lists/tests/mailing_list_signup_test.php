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
 *   - A visitor typing the email of an existing account does not subscribe
 *     it, and is given the same answer as for any address (B8).
 *   - A brand-new email gets no account (B9): a confirmation waits for it.
 *   - The signed-in owner of a verified address subscribes at once.
 *
 * Run: php tests/run.php db --filter=mailing_list_signup
 *
 * @version 2.0 - double opt-in (spec email_lists_plugin §10)
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
require_once(__DIR__ . '/../../../tests/lib/logic.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/users_class.php'));

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
	return harness_call_logic('plugins/email_lists/logic/list_logic.php', 'list_logic', array_merge(array(
		'slug' => $list->get('mlt_link'),
		'usr_first_name' => 'Harness',
		'usr_last_name' => 'Signup',
		'antispam_question' => $ANSWER,
		'mlt_mailing_list_id_subscribe' => $list->key,
	), $extra), 'POST');
}

function mls_lists_post($list, array $extra) {
	global $ANSWER;
	return harness_call_logic('plugins/email_lists/logic/lists_logic.php', 'lists_logic', array_merge(array(
		'usr_first_name' => 'Harness',
		'usr_last_name' => 'Signup',
		'antispam_question' => $ANSWER,
		'new_list_subscribes' => array($list->key),
	), $extra), 'POST');
}

$member = make_user('MlsMember');

$pending = function (string $email) {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT count(*) FROM mlp_mailing_list_pending WHERE mlp_email = ?');
	$q->execute(array(strtolower($email)));
	return (int)$q->fetchColumn();
};
harness_defer(function () use ($RUN) {
	DbConnector::get_instance()->get_db_link()->prepare("DELETE FROM mlp_mailing_list_pending WHERE mlp_email LIKE ?")->execute(array('%' . $RUN . '%'));
});
// Distinct requesters, so the per-requester limit never decides a case here.
$ip = 0;
$fresh_ip = function () use (&$ip) { $_SERVER['REMOTE_ADDR'] = '198.51.100.' . (10 + (++$ip)); };

// ---------------------------------------------------------------------------
section('A visitor cannot subscribe an existing account, nor learn it has one');

mls_signin(null);
$fresh_ip();
$res = mls_list_post($list, array('usr_email' => $member->get('usr_email')));
check($res->error === null && stripos(json_encode($res->data['messages'] ?? array()), 'check your email') !== false,
	'list: a member\'s email gets the same "check your email" answer as any (B8)', var_export($res->error, true));
check(!$list->is_user_in_list($member->key), 'list: the member was not subscribed');
$fresh_ip();
$res = mls_lists_post($list, array('usr_email' => strtoupper($member->get('usr_email'))));
check($res->error === null && !$list->is_user_in_list($member->key), 'lists: the same, in any case');

// ---------------------------------------------------------------------------
section('A brand-new email waits for its owner');

$new1 = 'mls1_' . $RUN . '@dev.getjoinery.com';
$fresh_ip();
$res = mls_list_post($list, array('usr_email' => $new1));
check($res->error === null && !User::GetByEmail($new1) && $pending($new1) === 1,
	'list: no account is made for it (B9); a confirmation is pending', var_export($res->error, true));

$new2 = 'mls2_' . $RUN . '@dev.getjoinery.com';
$fresh_ip();
$res = mls_lists_post($list, array('usr_email' => $new2));
check($res->error === null && !User::GetByEmail($new2) && $pending($new2) === 1,
	'lists: the same', var_export($res->error, true));

// ---------------------------------------------------------------------------
section('A signed-in member asks for themselves');

$member->set('usr_email_is_verified', true);
$member->save();
mls_signin($member);
// The verified owner check reads the session's user.
SessionControl::get_instance()->store_session_variables($member);
$res = mls_list_post($list, array('usr_email' => ''));
mls_signin(null);
check($res->error === null && $list->is_user_in_list($member->key),
	'list: the signed-in owner of a verified address is subscribed at once', var_export($res->error, true));

harness_finish();
