<?php
/**
 * Inbound Email - Per-attachment download endpoint
 *
 * On success the logic streams the attachment bytes and exit()s, so the code
 * below only runs when retrieval failed — it renders an honest "not available"
 * message rather than an error.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_attachment_logic.php'));

$page_vars = process_logic(admin_inbound_email_attachment_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
		'Mailbox' => '/plugins/inbound_email/admin/admin_inbound_email_reader',
		'Attachment' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Mailboxes');

echo '<div class="alert alert-warning">' . htmlspecialchars($error ?? 'Attachment unavailable.') . '</div>';
if (!empty($reader_url)) {
	echo '<a class="btn btn-outline-primary" href="' . htmlspecialchars($reader_url) . '">Back to mailbox</a>';
}

$page->admin_footer();
?>
