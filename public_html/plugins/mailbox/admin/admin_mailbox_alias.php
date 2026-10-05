<?php
/**
 * Inbound Email - Create/Edit Alias
 *
 * @version 1.12 - store-only where the mailbox's level does not offer forwarding; each
 *   forwarding destination's confirmation state with Resend (specs/relay_receive_only_forwarding.md)
 * @version 1.11 - the protection level shows as its cards, open; another card links to where it is chosen;
 *   the help text's example address uses the mailbox's own domain
 * @version 1.10 - the protection badge carries the domain's add-ons in force
 * @version 1.9
 * @changelog 1.9 - the protection badge states the MAILBOX's level and links to
 *   wherever that level is decided
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_alias_logic.php'));

$page_vars = process_logic(admin_mailbox_alias_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_edit = ($alias->key) ? true : false;

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
			($is_edit ? 'Edit Alias' : 'New Alias') => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

// How mail reaches this server is a deployment fact, not a question every page
// has to have answered first: an undecided deployment receives directly and
// works. The choice lives in the Setup tab's Advanced section
// (specs/mailbox_relay_surface_simplification.md).
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$pageoptions['title'] = $is_edit ? 'Edit Alias' : 'New Alias';
$page->begin_box($pageoptions);

// Build domain dropdown options
$domain_options = array();
$domains->load();
foreach ($domains as $d) {
	$domain_options[$d->key] = $d->get('ied_domain');
}
// The example address names the mailbox's own domain; a new mailbox, the first listed.
$alias_domain_choice = intval($alias->get('iea_ied_inbound_email_domain_id'));
$example_domain = $domain_options[$alias_domain_choice]
	?? (!empty($domain_options) ? reset($domain_options) : 'example.com');

$formwriter = $page->getFormWriter('form1', [
	'model' => $alias,
	'edit_primary_key_value' => $alias->key,
]);

echo $formwriter->begin_form();

$formwriter->dropinput('iea_ied_inbound_email_domain_id', 'Domain', [
	'options' => $domain_options,
	'validation' => ['required' => true],
]);

// Mail protection for a hosted mailbox is a property of the DOMAIN, because MX,
// SPF, DMARC and DKIM are — surface the level here with a path to where it is
// decided, so this page is never a dead end for someone looking to change it. A
// pulled-in mailbox decides for itself, in the mailbox editor
// (specs/mailbox_connect_flow.md § D), so its cards point there instead.
if ($is_edit && $alias->get('iea_ied_inbound_email_domain_id')) {
	$alias_domain_id = intval($alias->get('iea_ied_inbound_email_domain_id'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
	$alias_domain = new InboundEmailDomain($alias_domain_id, TRUE);
	if ($alias_domain->key) {
		$alias_level = $alias->security_level();
		$level_is_own = (bool)$alias_domain->get('ied_is_imap_source');
		$level_url = $level_is_own
			? ('/plugins/mailbox/admin/admin_mailbox_imap_edit?domain_id=' . $alias_domain_id
				. '&alias_id=' . intval($alias->key))
			: ('/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id=' . $alias_domain_id);
		// The level's cards, shown open: the one in force marked, each other
		// card a link to the editor that decides it, with that level chosen,
		// so the ceremony runs where it always does. The domain's add-ons in
		// force are named on the current card (a pulled-in mailbox has none:
		// they belong to a domain this deployment hosts).
		$alias_addons = (!$level_is_own && $alias->seals_content()) ? $alias_domain->addon_labels() : array();
		echo ProtectionLevelPicker::renderLinked($alias_level, array(
			'service'   => ProtectionLevelPicker::SERVICE_MAIL,
			'levels'    => $level_is_own
				? array(InboundEmailDomain::LEVEL_STANDARD, InboundEmailDomain::LEVEL_PRIVATE)
				: InboundEmailDomain::SETTABLE_LEVELS,
			'label'     => 'Mail protection',
			'addons_on' => $alias_addons,
			'url'       => function ($level) use ($level_url) {
				return $level_url . '&target_level=' . rawurlencode($level);
			},
			'helptext'  => $level_is_own
				? 'Set on this mailbox. Choose another level to change it.'
				: 'Set on the domain, for every mailbox on ' . $alias_domain->get('ied_domain')
					. '. Choose another level to change it there.',
		));
	}
}

$formwriter->textinput('iea_alias', 'Mailbox', [
	'validation' => ['required' => true],
	'placeholder' => 'info',
	'helptext' => 'The name before the @ — for example, "info" creates the mailbox info@' . $example_domain . '.',
]);

// A mailbox sealed to a key this server does not hold is store-only, and says why.
$forwarding_offered = !$alias->get('iea_ied_inbound_email_domain_id') || $alias->forwarding_offered();
$mode_options = [
	// Store is listed first so it is what a new mailbox gets: a dropdown with no
	// matching value selects its first option, and a new alias carries none.
	'store'             => 'Store locally (no forwarding)',
	'forward'           => 'Forward to destination address(es)',
	'forward_and_store' => 'Forward and store a copy',
];
if (!$forwarding_offered) {
	$mode_options = ['store' => 'Store locally (no forwarding)'];
}
$formwriter->dropinput('iea_delivery_mode', 'Delivery Mode', [
	'options' => $mode_options,
	'helptext' => $forwarding_offered
		? 'Store mode keeps messages in the local mailbox (visible on the Mailbox tab) '
			. 'and does not forward. Forward mode requires at least one destination. '
			. 'Nothing is forwarded to an address until the person at it confirms.'
		: 'This mailbox keeps its mail here and cannot forward: its mail is sealed to a key this server '
			. 'does not hold (Fortress, or Seal at the relay), and a forward would hand the receiving '
			. 'provider a readable copy.',
	'visibility_rules' => [
		'store'             => ['show' => [], 'hide' => ['iea_destinations']],
		'forward'           => ['show' => ['iea_destinations'], 'hide' => []],
		'forward_and_store' => ['show' => ['iea_destinations'], 'hide' => []],
	],
]);

$formwriter->textbox('iea_destinations', 'Destination Addresses', [
	'rows' => 4,
	'htmlmode' => 'no',
	'helptext' => 'Required for Forward / Forward and store modes. Enter one full email address per line, '
		. 'or separate them with commas — for example, you@gmail.com. Every address is validated when you save. '
		. 'Leave empty for "Store locally" mode.',
]);

$formwriter->textinput('iea_description', 'Notes', [
	'helptext' => 'Optional. A private label so you remember what this mailbox is for '
		. '(e.g. "Main contact form inbox"). Never shown to anyone sending mail.',
]);

$formwriter->checkboxinput('iea_is_enabled', 'Enabled', []);

// Mailbox access grants. Read/star state on a shared mailbox is shared among
// everyone listed here (team-inbox semantics). Empty = nobody is granted; a
// permission-10 superadmin still sees every mailbox without a grant.
if (!empty($user_options)) {
	$formwriter->checkboxList('users_with_access', 'Users with access', [
		'options' => $user_options,
		'checked' => $granted_user_ids ?? [],
		'helptext' => 'Staff who can read this mailbox in the Mailbox reader. Read and star '
			. 'state is shared among everyone granted access. Superadmins always see every mailbox.',
	]);
} else {
	echo '<div class="alert alert-info">No staff users are available to grant mailbox access to yet.</div>';
}

$formwriter->submitbutton('btn_submit', 'Save Alias');

echo $formwriter->end_form();

// Each forwarding destination's confirmation state, outside the form: every
// Resend is a form of its own.
if ($is_edit && $forwarding_offered && $alias->mode_forwards()) {
	echo mailbox_forward_confirmation_panel(intval($alias->get('iea_ied_inbound_email_domain_id')), intval($alias->key),
		$alias->get_destinations_array(), '/plugins/mailbox/admin/admin_mailbox_alias',
		array('iea_inbound_email_alias_id' => intval($alias->key)));
}

$page->end_box();
$page->admin_footer();
?>
