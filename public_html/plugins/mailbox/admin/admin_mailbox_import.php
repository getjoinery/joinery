<?php
/**
 * Inbound Email - Import a mail archive.
 *
 * Sits in the Accounts tree beside IMAP feeds, because it answers the same
 * question from the other direction: a feed pulls from a live account, an import
 * reads a dead export. Between them there is a way in from any provider.
 *
 * The operator mount, so the mailbox picker covers every mailbox rather than only
 * the ones the operator personally holds. Everything else — the panel, the
 * actions, the permission checks — is shared with the member page.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_import_page_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mail_import_panel.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$page_vars = process_logic(mailbox_import_page_logic(
	array_merge($_GET, $_POST, $params ?? array(),
		array('_return' => '/plugins/mailbox/admin/admin_mailbox_import'))));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'Import archive' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

$page->begin_box(array('title' => 'Import a mail archive'));

if (!$import_enabled) {
	echo '<div class="alert alert-warning">Mail archive import is switched off in Settings.</div>';
} else {
	mailbox_render_import_panel($page, $page_vars);
}

$page->end_box();
$page->admin_footer();
?>
