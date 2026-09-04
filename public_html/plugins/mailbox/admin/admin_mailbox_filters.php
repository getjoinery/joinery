<?php
/**
 * Inbound Email - Filters (Gmail-parity inbound rules).
 *
 * The operator mount: every mailbox on the deployment, plus the domain-wide
 * buckets. The panel and the logic are shared with the member page at
 * /profile/mailbox/filters, which sees only the mailboxes that member holds.
 *
 * @see specs/implemented/inbound_email_filters.md
 * @see specs/inbound_email_filter_import.md
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_filters_panel.php'));

$base = '/plugins/mailbox/admin/admin_mailbox_filters';

$page_vars = process_logic(mailbox_filters_logic(
	array_merge($_GET, $_POST, $params ?? []),
	array('base' => $base, 'operator' => true)
));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'Filters' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Filters');

mailbox_render_filters_panel($page, $page_vars, $base);

$page->admin_footer();
?>
