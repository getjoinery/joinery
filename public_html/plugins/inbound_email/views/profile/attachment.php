<?php
/**
 * Member per-attachment download endpoint (/profile/inbound_email/attachment).
 *
 * On success the logic streams the attachment bytes and exit()s, so the code
 * below only runs when retrieval failed — it renders an honest "not available"
 * message rather than an error.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_attachment_logic.php', 'logic', 'system', null, 'inbound_email'));

$page_vars = process_logic(profile_attachment_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Attachment',
	'breadcrumbs' => array(
		'Email' => $reader_url ?? '/profile/inbound_email/mailbox',
		'Attachment' => '',
	),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Attachment', $hoptions);
echo '<p>' . htmlspecialchars($error ?? 'Attachment unavailable.') . '</p>';
if (!empty($reader_url)) {
	echo '<p><a href="' . htmlspecialchars($reader_url) . '">Back to mailbox</a></p>';
}
echo PublicPage::EndPage($hoptions);

$page->public_footer();
?>
