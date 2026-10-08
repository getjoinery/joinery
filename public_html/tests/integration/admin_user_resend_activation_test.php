<?php
/** @joinery-test
 * name: admin_user_resend_activation
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Resend activation email and Activate User on the admin user page are POST
 * actions: a POST acts (a fresh activation code is minted; the user is
 * activated), a GET of the same URL does nothing, a verified user is not sent
 * one, and the page offers both as posted buttons, not links.
 *
 * Mail sent here goes to the harness's test store (email_test_mode).
 *
 * Run: php tests/integration/admin_user_resend_activation_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('adm/logic/admin_user_logic.php'));

if (session_status() === PHP_SESSION_NONE) {
	@session_start();
}

$db = DbConnector::get_instance()->get_db_link();
$admin = make_user('ResendAdmin', 10);
$target = make_user('ResendTarget');
$target->set('usr_email_is_verified', false);
$target->set('usr_is_activated', false);
$target->save();

$_SESSION = array();
$_SESSION['usr_user_id'] = (int)$admin->key;
$_SESSION['loggedin']    = true;
$_SESSION['permission']  = 10;

$codes = function () use ($db, $target) {
	$q = $db->prepare('SELECT COUNT(*) FROM act_activation_codes WHERE act_usr_user_id = ?');
	$q->execute(array((int)$target->key));
	return (int)$q->fetchColumn();
};
harness_defer(function () use ($db, $target) {
	$db->prepare('DELETE FROM act_activation_codes WHERE act_usr_user_id = ?')->execute(array((int)$target->key));
});

// ---------------------------------------------------------------------------
section('The page offers it as a posted button');

$_SERVER['REQUEST_METHOD'] = 'GET';
$page = admin_user_logic(array('usr_user_id' => $target->key));
$menu = (string)($page->data['dropdown_button'] ?? '');
foreach (array('resend_activation' => 'Resend activation email', 'activate' => 'Activate User') as $action => $label) {
	check(preg_match('#<form[^>]*method="post"[^>]*action="/admin/admin_user"[^>]*>(?:(?!</form>).)*value="' . $action . '"#si', $menu) === 1
		|| preg_match('#<form[^>]*action="/admin/admin_user"[^>]*method="post"[^>]*>(?:(?!</form>).)*value="' . $action . '"#si', $menu) === 1,
		$label . ' is a posted button on the user page');
}
check(strpos($menu, 'admin_email_verify') === false && strpos($menu, 'admin_activate') === false,
	'no action in the menu is a link to a page that acts on GET');

// ---------------------------------------------------------------------------
section('A GET sends nothing; a POST sends');

$before = $codes();
$_SERVER['REQUEST_METHOD'] = 'GET';
admin_user_logic(array('action' => 'resend_activation', 'usr_user_id' => $target->key));
check($codes() === $before, 'a GET of the action URL sends nothing');

$_SERVER['REQUEST_METHOD'] = 'POST';
$result = admin_user_logic(array('action' => 'resend_activation', 'usr_user_id' => $target->key));
check($codes() === $before + 1, 'a POST sends the activation email (a fresh code is minted)');
check($result->redirect === '/admin/admin_user?usr_user_id=' . $target->key, 'and returns to the user page', (string)$result->redirect);

// ---------------------------------------------------------------------------
section('Activate User is a POST');

$_SERVER['REQUEST_METHOD'] = 'GET';
admin_user_logic(array('action' => 'activate', 'usr_user_id' => $target->key));
check(!(new User((int)$target->key, TRUE))->get('usr_is_activated'), 'a GET of the action URL activates nothing');
$_SERVER['REQUEST_METHOD'] = 'POST';
admin_user_logic(array('action' => 'activate', 'usr_user_id' => $target->key));
check((bool)(new User((int)$target->key, TRUE))->get('usr_is_activated'), 'a POST activates the user');
$target = new User((int)$target->key, TRUE);

// ---------------------------------------------------------------------------
section('A verified user is not sent one');

$target->set('usr_email_is_verified', true);
$target->save();
$before = $codes();
admin_user_logic(array('action' => 'resend_activation', 'usr_user_id' => $target->key));
check($codes() === $before, 'a verified user gets no activation email');

harness_finish();
