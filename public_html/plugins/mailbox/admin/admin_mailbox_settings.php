<?php
/**
 * Inbound Email - Settings (server-wide delivery policy).
 *
 * Spam filtering, forwarding limits, the forwarded-From display, and
 * retention/storage caps. One form, grouped into boxes, saved in a single POST.
 * Provisioning and server identity live on the Setup tab, not here.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_settings_logic.php'));

$page_vars = process_logic(admin_mailbox_settings_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'Settings' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Settings');

$form = $page->getFormWriter('settings_form', array('action' => $base));
echo $form->begin_form();
$form->hiddeninput('save_settings', '', array('value' => '1'));

// --- Spam filtering ---
$page->begin_box(array('title' => 'Spam filtering'));
$form->checkboxinput('mailbox_spam_filtering_enabled', 'Move spam to the spam folder', array(
	'checked'   => $values['mailbox_spam_filtering_enabled'],
	'help_text' => 'Acts on the SPF/DKIM/DMARC verdicts already recorded for each message. '
		. 'Suspected spam is moved to the Spam view and is not forwarded — it is never rejected or deleted.',
));
$page->end_box();

// --- Forwarding limits ---
$page->begin_box(array('title' => 'Forwarding limits'));
$form->numberinput('mailbox_forwarding_max_destinations', 'Max destinations per alias', array(
	'value' => $values['mailbox_forwarding_max_destinations'], 'min' => 1,
));
$form->numberinput('mailbox_forwarding_rate_limit_per_alias', 'Forwards per alias, per window', array(
	'value' => $values['mailbox_forwarding_rate_limit_per_alias'], 'min' => 0,
));
$form->numberinput('mailbox_forwarding_rate_limit_per_domain', 'Forwards per domain, per window', array(
	'value' => $values['mailbox_forwarding_rate_limit_per_domain'], 'min' => 0,
));
$form->numberinput('mailbox_forwarding_rate_limit_window', 'Rate-limit window (seconds)', array(
	'value' => $values['mailbox_forwarding_rate_limit_window'], 'min' => 1,
));
$page->end_box();

// --- Forwarded-From display ---
$page->begin_box(array('title' => 'Forwarded mail display'));
$form->checkboxinput('mailbox_from_show_via', 'Show "via Site Name" in the From line of forwarded mail', array(
	'checked'   => $values['mailbox_from_show_via'],
	'help_text' => 'Off shows just the original sender name. The From address is the site\'s verified '
		. 'address either way (required for deliverability); the original sender stays in Reply-To.',
));
$page->end_box();

// --- Retention & storage ---
$page->begin_box(array('title' => 'Retention & storage'));
$form->numberinput('mailbox_log_retention_days', 'Transaction-log retention (days)', array(
	'value' => $values['mailbox_log_retention_days'], 'min' => 0,
));
$form->numberinput('mailbox_retention_days', 'Stored-message retention (days)', array(
	'value' => $values['mailbox_retention_days'], 'min' => 0,
));
$form->numberinput('mailbox_max_per_window', 'Max stored messages per domain, per window', array(
	'value' => $values['mailbox_max_per_window'], 'min' => 0,
));
$page->end_box();

$form->submitbutton('btn_save', 'Save settings');
echo $form->end_form();

$page->admin_footer();
?>
