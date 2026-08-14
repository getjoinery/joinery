<?php
/**
 * Member Mailbox — the Mailbox Reader mounted at /profile/mailbox/mailbox,
 * scoped to the mailboxes the signed-in member holds grants for. Same reader UI
 * as the admin mount (includes/mailbox_reader_mount.php); this page supplies the
 * theme chrome, the member attachment endpoint, and no detail-page deep links.
 *
 * @version 1.4.0
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

$hoptions['app'] = true;
// Actions menu, matching the calendar's. Importing old mail is a LINK rather
// than a modal because it is a multi-step run — pick an archive, wait for it to
// be read, then choose what to bring — where the calendar's is one file input.
$hoptions['header_action'] = '<details class="jy-ui jy-actions-dropdown">'
	. '<summary class="btn btn-secondary">Actions</summary>'
	. '<div class="jy-actions-menu">'
	. '<a href="/profile/mailbox/import">Import old mail from another provider&hellip;</a>'
	. '</div></details>';

// The area AI panel (joinery_ai's general component): an AI button beside
// Actions opening the recipes drawer for the mailbox open in the rail. Only
// this member mount carries it — the admin oversight reader spans mailboxes
// the viewer holds no grant on, so it deliberately does not. With the plugin
// inactive the button simply doesn't exist.
$ai_panel_active = PluginHelper::isPluginActive('joinery_ai');
if ($ai_panel_active) {
	$hoptions['header_action'] = '<span id="mbx-ai-panel-anchor"></span>' . $hoptions['header_action'];
}
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
	));

	if ($ai_panel_active) {
		$aip_ver = function ($rel) {
			$path = PathHelper::getIncludePath('plugins/joinery_ai/assets/' . $rel);
			return '/plugins/joinery_ai/assets/' . $rel . '?v=' . (is_file($path) ? filemtime($path) : '1');
		};
		echo '<link rel="stylesheet" href="' . htmlspecialchars($aip_ver('ai_panel.css')) . '">';
		echo '<script src="' . htmlspecialchars($aip_ver('ai_panel.js')) . '"></script>';
		?>
		<script>
		JoineryAiPanel.mount({
			area: 'mailbox',
			getContext: function () {
				return { mailbox: window.MailboxReader ? window.MailboxReader.currentAddress() : '' };
			},
			anchor: document.getElementById('mbx-ai-panel-anchor')
		});
		</script>
		<?php
	}
}

echo PublicPage::EndPage($hoptions);
$page->public_footer();
?>
