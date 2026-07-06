<?php
/**
 * Inbound Email - Mailbox Reader (Gmail-style).
 *
 * Two-pane Gmail layout: a left sidebar (mailbox switcher — the addresses this
 * viewer has been granted, each independently badged; "All mail" for
 * superadmins — plus filters + search) and a single main pane that shows EITHER
 * the conversation list OR an opened conversation full-width (with a back
 * arrow), never both side-by-side. HTML bodies render in a sandboxed iframe.
 *
 * All data and mutations go through the scoped AJAX endpoints
 * (/ajax/mailbox_*). Vanilla JS only. The single-message detail page is kept
 * for raw MIME / .eml download / deep links.
 *
 * The reader UI itself is the shared mount (includes/mailbox_reader_mount.php),
 * also used by the member page at /profile/mailbox/mailbox — this page
 * supplies the admin chrome and the admin endpoint URLs.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_reader_mount.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_reader_logic.php'));

$page_vars = process_logic(admin_mailbox_reader_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
			'Mailbox' => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Mailboxes');

mailbox_render_mailbox_reader($page, array(
	'csrf_token'          => $csrf_token,
	'initial_mailboxes'   => $initial_mailboxes,
	'attachment_url_base' => '/plugins/mailbox/admin/admin_mailbox_attachment',
	'message_detail_base' => '/plugins/mailbox/admin/admin_mailbox_message',
));

$page->admin_footer();
?>
