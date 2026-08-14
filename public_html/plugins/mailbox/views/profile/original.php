<?php
/**
 * Member message-export endpoint (/profile/mailbox/original).
 *
 * On success the logic emits the .eml download or the print sheet and exit()s,
 * so the code below only runs when the export was refused or unavailable — it
 * renders an honest "not available" message rather than an error.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_original_logic.php', 'logic', 'system', null, 'mailbox'));

$page_vars = process_logic(profile_original_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Message',
	'breadcrumbs' => array(
		'Email' => $reader_url ?? '/profile/mailbox/mailbox',
		'Message' => '',
	),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Message', $hoptions);
echo '<p>' . htmlspecialchars($error ?? 'This message is unavailable.') . '</p>';
if (!empty($reader_url)) {
	echo '<p><a href="' . htmlspecialchars($reader_url) . '">Back to mailbox</a></p>';
}
echo PublicPage::EndPage($hoptions);

$page->public_footer();
?>
