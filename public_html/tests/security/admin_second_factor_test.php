<?php
/** @joinery-test
 * name: admin_second_factor
 * tier: db
 * env: any
 * needs: []
 */
/**
 * Every admin holds a second factor, or the owner is told who does not
 * (specs/security_inventory.md S4; specs/security_inventory_closures_admin_2026_09.md).
 *
 * The count is exercised against a temporary admin: present with no factor,
 * gone once an authenticator app is enrolled. The notice wording is checked
 * pure for each of its states, and the requirement predicate is checked to
 * accept a passkey through the shared factor helper.
 *
 * Run: php tests/run.php db --filter=admin_second_factor
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$admin = make_user('SecondFactor' . substr(md5(uniqid('', true)), 0, 6), 5);
$id = (int)$admin->key;
$named = function () use ($id) {
	foreach (AdminSecondFactorNotice::adminsWithoutSecondFactor() as $m) {
		if ($m['id'] === $id) return $m;
	}
	return null;
};

section('The count');
$row = $named();
check($row !== null, 'a new admin with no factor is counted');
check($row && $row['name'] !== '', 'and named', json_encode($row));
$admin->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));
$admin->save();
check($named() === null, 'enrolling an authenticator app removes them from the count');
$admin->set('usr_totp_enabled_time', null);
$admin->set('usr_permission', 0);
$admin->save();
check($named() === null, 'a non-admin is never counted');

section('The predicate accepts a passkey through the shared helper');
$session = SessionControl::get_instance();
$admin->set('usr_permission', 5);
$admin->save();
$admin->load();
check($session->user_has_second_factor($admin) === false, 'no app, no passkey: no factor');
$admin->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));
$admin->save();
$admin->load();
check($session->user_has_second_factor($admin) === true, 'an authenticator app is a factor');
$src = file_get_contents(PathHelper::getIncludePath('includes/SessionControl.php'));
check(preg_match('/function must_enable_totp_for_admin\(\)[\s\S]*?user_has_second_factor\(\$user\)/', $src) === 1,
	'the admin requirement asks the shared helper, which counts a passkey too');
check(strpos($src, 'return !$user->has_totp_enabled();') === false, 'and no longer checks the authenticator app alone');

section('The notice');
$missing = array(array('id' => 11, 'name' => 'Ada Lovelace'), array('id' => 12, 'name' => 'Grace Hopper'));
check(AdminSecondFactorNotice::forState(array(), 11, false, true) === '', 'silent when every admin holds a factor');
$out = AdminSecondFactorNotice::forState($missing, 99, false, true);
check(strpos($out, '2 admins have no second factor') !== false && strpos($out, 'Ada Lovelace, Grace Hopper') !== false, 'counts and names them');
check(strpos($out, 'Enrol yours') === false, 'no "Enrol yours" for a viewer who holds a factor');
check(strpos($out, AdminSecondFactorNotice::REQUIRE_URL) !== false && strpos($out, 'Require one of every admin') !== false,
	'a superadmin gets the one-button requirement while it is off');
$out = AdminSecondFactorNotice::forState($missing, 12, false, false);
check(strpos($out, 'Enrol yours') !== false && strpos($out, AdminSecondFactorNotice::SECURITY_URL) !== false, 'a missing viewer is offered "Enrol yours"');
check(strpos($out, AdminSecondFactorNotice::REQUIRE_URL) === false, 'a permission-5 admin cannot switch the requirement on');
$out = AdminSecondFactorNotice::forState($missing, 99, true, true);
check(strpos($out, 'sent to enrol') !== false && strpos($out, AdminSecondFactorNotice::REQUIRE_URL) === false,
	'with the requirement on, the notice says they are sent to enrol and offers no button');
$many = array();
for ($i = 1; $i <= 6; $i++) $many[] = array('id' => $i, 'name' => "Admin $i");
$out = AdminSecondFactorNotice::forState($many, 99, false, true);
check(strpos($out, '6 admins') !== false && strpos($out, 'and 2 more') !== false, 'four are named, the rest counted');
$one = AdminSecondFactorNotice::forState(array($missing[0]), 99, false, true);
check(strpos($one, '1 admin has no second factor') !== false, 'singular wording');
$evil = AdminSecondFactorNotice::forState(array(array('id' => 5, 'name' => '<b>x</b>')), 99, false, true);
check(strpos($evil, '<b>x</b>') === false && strpos($evil, '&lt;b&gt;') !== false, 'names are escaped');

$ref = new ReflectionMethod('AdminNotices', 'coreRenderers');
$ref->setAccessible(true);
$core = $ref->invoke(null);
check(isset($core['second_factor']) && $core['second_factor'] === array('AdminSecondFactorNotice', 'render'), 'registered as a core notice');

harness_finish();
