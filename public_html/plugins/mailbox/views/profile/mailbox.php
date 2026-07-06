<?php
/**
 * Member Mailbox — the Mailbox Reader mounted at /profile/mailbox/mailbox,
 * scoped to the mailboxes the signed-in member holds grants for. Same reader UI
 * as the admin mount (includes/mailbox_reader_mount.php); this page supplies the
 * theme chrome, the member attachment endpoint, and no detail-page deep links.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getThemeFilePath('profile_mailbox_logic.php', 'logic', 'system', null, 'mailbox'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/mailbox_reader_mount.php'));

$page_vars = process_logic(profile_mailbox_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$hoptions = array(
	'title' => 'Email',
	'breadcrumbs' => array(
		'Email' => '',
	),
);
$page->public_header($hoptions, NULL);

echo PublicPage::BeginPage('Email', $hoptions);

if (!$has_mailboxes) {
	?>
	<div class="mbx-empty-state">
		<h2>No mailboxes yet</h2>
		<p>No mailboxes are assigned to your account.</p>
	</div>
	<?php
} else {
	mailbox_render_mailbox_reader($page, array(
		'csrf_token'          => $csrf_token,
		'initial_mailboxes'   => $initial_mailboxes,
		'attachment_url_base' => '/profile/mailbox/attachment',
		'message_detail_base' => null,
	));
}

echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
