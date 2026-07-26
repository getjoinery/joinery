<?php
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
/**
 * Inbound Email - Settings (server-wide delivery policy).
 *
 * Spam filtering, forwarding limits, the forwarded-From display, and
 * retention/storage caps. One form, grouped into boxes, saved in a single POST.
 * Provisioning and server identity live on the Setup tab, not here.
 *
 * @version 1.2
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
// One question, plus one genuinely optional capability. Where the scanning
// happens is SHOWN, not asked — it follows from the deployment's topology.
// Learning is offered only where a scanner is running (it ships with the mail
// stack, so that is every box hosting its own mail); elsewhere the checkbox is
// disabled and the state line says why. A disabled checkbox never posts — the
// logic writes the learning setting only while the scanner is present.
$page->begin_box(array('title' => 'Spam filtering'));
// Saved state, above the controls: below them it would read as a consequence of
// the checkbox just ticked and contradict it until the form is saved.
echo '<p class="text-muted small">' . htmlspecialchars($scanner_state) . '</p>';
$form->checkboxinput('mailbox_spam_filtering_enabled', 'Move suspected spam to the Spam view', array(
	'checked'   => $values['mailbox_spam_filtering_enabled'],
	'helptext'  => 'Suspected spam is moved out of the inbox and is not forwarded. It is never '
		. 'rejected, bounced or deleted, so a wrong guess costs a click.',
	'visibility_rules' => array(
		'checked'   => array('show' => array('mailbox_spam_learning_enabled')),
		'unchecked' => array('hide' => array('mailbox_spam_learning_enabled')),
	),
));
$form->checkboxinput('mailbox_spam_learning_enabled', 'Learn from what users mark as spam', array(
	'checked'   => $values['mailbox_spam_learning_enabled'],
	'disabled'  => !$scanner_present,
	'helptext'  => 'Corrections in the reader train a spam filter on this deployment\'s own mail. '
		. 'What it learns is yours alone — a shared relay is deliberately stateless and cannot '
		. 'learn for you.',
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
	'helptext'  => 'Off shows just the original sender name. The From address is the site\'s verified '
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

// --- Relay configuration (only once the deployment receives through a relay) ---
if (!empty($show_relay_config)) {
	$page->begin_box(array('title' => 'Hosted relay connection'));
	$form->textinput('mailbox_fleet_service_url', 'Relay service URL', array(
		'value' => $values['mailbox_fleet_service_url'], 'placeholder' => 'https://getjoinery.com',
		'helptext'  => 'The service this deployment rents its relay spot from. Enrollment itself happens on the Setup tab.',
	));
	$form->textinput('mailbox_fleet_api_public_key', 'API public key', array(
		'value' => $values['mailbox_fleet_api_public_key'],
	));
	SettingsFieldRenderer::secretField($form, 'mailbox_fleet_api_secret_key', 'API secret key',
		!empty($fleet_secret_set) ? 'stored' : '');
	$page->end_box();
}

if (!empty($has_active_relay)) {
	$page->begin_box(array('title' => 'Outbound sending'));
	$is_smarthost = ($outbound_mode === 'smarthost');
	$form->dropinput('mailbox_relay_outbound_mode', 'Sent mail leaves through:', array(
		'value'   => $outbound_mode,
		'options' => array(
			'provider'  => 'Your email provider (recommended)',
			'smarthost' => 'The relay (advanced)',
		),
		'visibility_rules' => array(
			'provider'  => array('show' => array('provider_note'),  'hide' => array('smarthost_note')),
			'smarthost' => array('show' => array('smarthost_note'), 'hide' => array('provider_note')),
		),
	));
	// One consequence line per option, shown one-at-a-time by the select above.
	// Server-set initial display avoids a flash before the toggle script runs.
	echo '<p class="text-muted small" id="provider_note" style="display:' . ($is_smarthost ? 'none' : '') . '">'
		. 'Deliverability is your provider\'s job, and it carries the message in transit. '
		. 'The sent message\'s Received chain begins inside the provider, so this server\'s address stays hidden.</p>';
	echo '<p class="text-muted small" id="smarthost_note" style="display:' . ($is_smarthost ? '' : 'none') . '">'
		. 'No third party carries sent mail — it leaves through the relay over the tunnel. In exchange this '
		. 'deployment owns the relay IP\'s sending reputation: warmup, blocklist monitoring, and PTR hygiene.</p>';
	$page->end_box();
}

$form->submitbutton('btn_save', 'Save settings');
echo $form->end_form();

$page->admin_footer();
?>
