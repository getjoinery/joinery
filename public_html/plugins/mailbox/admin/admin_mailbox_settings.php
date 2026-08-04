<?php
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
/**
 * Inbound Email - Settings (server-wide delivery policy).
 *
 * Spam filtering, forwarding limits, the forwarded-From display, and
 * retention/storage caps. One form, grouped into boxes, saved in a single POST.
 * Provisioning and server identity live on the Setup tab, not here.
 *
 * @version 1.3
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

// Every box asks for a declared group. What this page decides is which boxes
// exist, whether a control is reachable on this deployment, and what state to
// print around it — none of which is field metadata.

// --- Spam filtering ---
// One question, plus one genuinely optional capability. Where the scanning
// happens is SHOWN, not asked — it follows from the deployment's topology.
// Learning is offered only where a scanner is running (it ships with the mail
// stack, so that is every box hosting its own mail); elsewhere the control is
// disabled and the state line says why. A disabled control never posts — the
// logic writes the learning setting only while the scanner is present.
$page->begin_box(array('title' => 'Spam filtering'));
// Saved state, above the controls: below them it would read as a consequence of
// the control just changed and contradict it until the form is saved.
echo '<p class="text-muted small">' . htmlspecialchars($scanner_state) . '</p>';
SettingsFieldRenderer::renderGroup($form, 'spam', array(
	'source'   => 'mailbox',
	'only'     => array('mailbox_spam_filtering_enabled', 'mailbox_spam_learning_enabled'),
	'disabled' => $scanner_present ? array() : array('mailbox_spam_learning_enabled'),
	'values'   => array(
		'mailbox_spam_filtering_enabled' => $values['mailbox_spam_filtering_enabled'] ? '1' : '0',
		'mailbox_spam_learning_enabled'  => $values['mailbox_spam_learning_enabled'] ? '1' : '0',
	),
));
$page->end_box();

// --- Forwarding limits ---
$page->begin_box(array('title' => 'Forwarding limits'));
SettingsFieldRenderer::renderGroup($form, 'forwarding', array(
	'source' => 'mailbox',
	'only'   => array(
		'mailbox_forwarding_max_destinations',
		'mailbox_forwarding_rate_limit_per_alias',
		'mailbox_forwarding_rate_limit_per_domain',
		'mailbox_forwarding_rate_limit_window',
	),
	'values' => $values,
));
$page->end_box();

// --- Forwarded-From display ---
$page->begin_box(array('title' => 'Forwarded mail display'));
SettingsFieldRenderer::renderGroup($form, 'forwarding', array(
	'source' => 'mailbox',
	'only'   => array('mailbox_from_show_via'),
	'values' => array('mailbox_from_show_via' => $values['mailbox_from_show_via'] ? '1' : '0'),
));
$page->end_box();

// --- Retention & storage ---
$page->begin_box(array('title' => 'Retention & storage'));
SettingsFieldRenderer::renderGroup($form, 'retention', array(
	'source' => 'mailbox',
	'values' => $values,
));
$page->end_box();

// --- Relay configuration (only once the deployment receives through a relay) ---
if (!empty($show_relay_config)) {
	$page->begin_box(array('title' => 'Hosted relay connection'));
	SettingsFieldRenderer::renderGroup($form, 'fleet', array(
		'source' => 'mailbox',
		'only'   => array(
			'mailbox_fleet_service_url',
			'mailbox_fleet_api_public_key',
			'mailbox_fleet_api_secret_key',
		),
		'values' => array(
			'mailbox_fleet_service_url'    => $values['mailbox_fleet_service_url'],
			'mailbox_fleet_api_public_key' => $values['mailbox_fleet_api_public_key'],
			// Only whether something is stored, never the secret itself.
			'mailbox_fleet_api_secret_key' => !empty($fleet_secret_set) ? 'stored' : '',
		),
	));
	$page->end_box();
}

if (!empty($has_active_relay)) {
	$page->begin_box(array('title' => 'Outbound sending'));
	$is_smarthost = ($outbound_mode === 'smarthost');
	SettingsFieldRenderer::renderGroup($form, 'relay', array(
		'source' => 'mailbox',
		'only'   => array('mailbox_relay_outbound_mode'),
		'values' => array('mailbox_relay_outbound_mode' => $outbound_mode),
		'field_options' => array(
			'mailbox_relay_outbound_mode' => array(
				// One consequence line per option, shown one at a time by the
				// select above. These are notes, not fields.
				'visibility_rules' => array(
					'provider'  => array('show' => array('provider_note'),  'hide' => array('smarthost_note')),
					'smarthost' => array('show' => array('smarthost_note'), 'hide' => array('provider_note')),
				),
			),
		),
	));
	// Server-set initial display avoids a flash before the toggle script runs.
	echo '<p class="text-muted small" id="provider_note" style="display:' . ($is_smarthost ? 'none' : '') . '">'
		. 'Deliverability is your provider\'s job, and it carries the message in transit. '
		. 'The sent message\'s Received chain begins inside the provider, so this server\'s address stays hidden.</p>';
	echo '<p class="text-muted small" id="smarthost_note" style="display:' . ($is_smarthost ? '' : 'none') . '">'
		. 'No third party carries sent mail — it leaves through your own relay over the tunnel. In exchange this '
		. 'deployment owns the relay IP\'s sending reputation: warmup, blocklist monitoring, and PTR hygiene.</p>';
	$page->end_box();
}

$form->submitbutton('btn_save', 'Save settings');
echo $form->end_form();

$page->admin_footer();
?>
