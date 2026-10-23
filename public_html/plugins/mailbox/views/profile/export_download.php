<?php
/**
 * A mailbox download (/profile/mailbox/export_download?run_id=N).
 *
 * On success the logic streams the zip and exit()s, so the code below only
 * runs when the download was refused — it says why, and links back to the
 * downloads page.
 *
 * @version 1.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/profile_export_download_logic.php'));

$page_vars = process_logic(profile_export_download_logic(array_merge($_GET, $params ?? array())));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Download a mailbox',
	'breadcrumbs' => array(
		'Email' => '/profile/mailbox/mailbox',
		'Download a mailbox' => $page_url ?? '/profile/mailbox/export',
	),
);
$page->public_header($hoptions, NULL);
echo PublicPage::BeginPage('Download a mailbox', $hoptions);
echo '<p>' . htmlspecialchars($error ?? 'This download is unavailable.') . '</p>';
echo '<p><a href="' . htmlspecialchars($page_url ?? '/profile/mailbox/export') . '">Back to your downloads</a></p>';
echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
