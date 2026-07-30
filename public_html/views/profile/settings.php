<?php
/**
 * /profile/settings — the settings hub entry point (the avatar dropdown's
 * Settings link). The hub renders as a left rail across the account pages
 * (PublicPageBase::settings_layout_start()); Account is the landing section.
 *
 * @version 1.0
 */
$session = SessionControl::get_instance();
if (!$session->is_logged_in()) {
	header('Location: /login');
	exit;
}
header('Location: /profile/account_edit');
exit;
