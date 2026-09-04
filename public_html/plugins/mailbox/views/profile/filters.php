<?php
/**
 * Member "Filters" page, mounted at /profile/mailbox/filters.
 *
 * Rules that act on mail as it arrives — label it, star it, archive it, send it
 * to spam or never to spam, forward it on. The member manages them for the
 * mailboxes they hold; the panel and the logic are shared with the admin tab,
 * so the two cannot drift.
 *
 * Reached from the gear menu on the mailbox itself, which is where somebody
 * looking at a message they did not want thinks to ask for a rule about it.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_filters_panel.php'));

$base = '/profile/mailbox/filters';

$page_vars = process_logic(mailbox_filters_logic(
	array_merge($_GET, $_POST, $params ?? array()),
	array('base' => $base, 'operator' => false)
));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Filters',
	'breadcrumbs' => array(
		'Email' => '/profile/mailbox/mailbox',
		'Filters' => '',
	),
);
$page->public_header($hoptions, NULL);

$hoptions['app'] = true;
echo PublicPage::BeginPage('Filters', $hoptions);

// A refused action says why here — a sealed mailbox's filters cannot change
// until its owner unlocks their vault, and the owner is the person on this page.
echo $page->render_messages();

mailbox_render_filters_panel($page, $page_vars, $base);

echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
