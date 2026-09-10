<?php
/**
 * admin_require_second_factor — the one-button fix behind the admin-header
 * notice (AdminSecondFactorNotice): switch totp_require_admins on, so every
 * admin without a second factor is sent to enrol one at their next page.
 *
 * A POST from a superadmin, nothing else. It only ever switches the
 * requirement on; switching it off is a considered act on the settings page.
 *
 * @version 1.0
 */
$session = SessionControl::get_instance();
$session->check_permission(10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	Setting::put('totp_require_admins', '1');
	$session->save_message(new DisplayMessage(
		'Every admin is now required to hold a second factor. Those without one are sent to enrol it at their next page.',
		'Second factor required', '~^/admin/~'));
}

$returnurl = $session->get_return() ?: '/admin/admin_settings';
header('Location: ' . $returnurl);
exit();
