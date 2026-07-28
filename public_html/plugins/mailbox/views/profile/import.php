<?php
/**
 * Member "Import old mail" page, mounted at /profile/mailbox/import.
 *
 * Brings an existing mailbox in from a file the member already has — a Proton
 * export, a Gmail Takeout, an mbox from Thunderbird, a folder of saved messages.
 * The counterpart to an IMAP feed, which pulls from a live account; this reads a
 * dead archive, and between them there is a way in from any provider.
 *
 * The panel itself is shared with the admin mount, so the two cannot drift.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/mailbox_import_page_logic.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mail_import_panel.php'));

$page_vars = process_logic(mailbox_import_page_logic(
	array_merge($_GET, $_POST, $params ?? array(), array('_return' => '/profile/mailbox/import'))));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Import old mail',
	'breadcrumbs' => array(
		'Email' => '/profile/mailbox/mailbox',
		'Import old mail' => '',
	),
);
$page->public_header($hoptions, NULL);

$hoptions['app'] = true;
echo PublicPage::BeginPage('Import old mail', $hoptions);

if (!$import_enabled) {
	echo '<p class="jy-muted">Importing old mail is switched off on this site.</p>';
} else {
	mailbox_render_import_panel($page, $page_vars);
}

echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
