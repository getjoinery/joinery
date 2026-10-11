<?php
/**
 * Boards - import a board (/profile/boards/import).
 *
 * Full-bleed app chrome (the same 'app' => true surface the messages app and the
 * calendar use). The page embeds what it needs as JSON so it paints with no round
 * trip; boards.js draws everything and talks to the boards_* API actions.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/boards/logic/boards_page_logic.php'));

$page_vars = process_logic(boards_page_logic(array_merge($_GET, $_POST, $params ?? [], ['__page' => 'import'])));
extract($page_vars);

// A board the member cannot open is a 404: the same answer as one that does not exist.
if (!empty($not_found)) {
	LibraryFunctions::display_404_page();
	return;
}

$page = new PublicPage();
$hoptions = array(
	'title' => $title ?? 'Boards',
	'breadcrumbs' => array('Boards' => ''),
);
$page->public_header($hoptions, NULL);

$hoptions['app'] = true;
$hoptions['header_action'] = '<a class="btn btn-outline btn-sm" href="/profile/boards">All boards</a>';
echo PublicPage::BeginPage($title ?? 'Boards', $hoptions);

if (!empty($unavailable)) {
	echo '<p>' . htmlspecialchars($unavailable, ENT_QUOTES, 'UTF-8') . '</p>';
	echo PublicPage::EndPage($hoptions);
	$page->public_footer();
	return;
}
?>
<div class="jy-ui jy-boards" id="boards-app" data-page="import">
	<noscript><p>Boards needs JavaScript.</p></noscript>
	<div class="jy-boards-import" id="boards-import">
		<p>Import a board from another kanban tool's JSON export. Choose the exported file, check what it holds, then import.</p>
		<?php
		// The file chooser is a FormWriter field; boards.js intercepts the submit.
		$formwriter = $page->getFormWriter('boards_import_form', array('enctype' => 'multipart/form-data', 'action' => '/profile/boards/import', 'method' => 'POST'));
		$formwriter->begin_form();
		$formwriter->fileinput('file', 'Exported board (.json)', array('accept' => '.json,application/json'));
		$formwriter->submitbutton('preview', 'Check the file');
		$formwriter->end_form();
		?>
		<div id="boards-import-result"></div>
	</div>
</div>
<div class="jy-boards-live" id="boards-live" role="status" aria-live="polite"></div>
<script id="boards-boot" type="application/json"><?php
	// Card text is member-written and can contain anything, including the
	// characters that would end this element early. Escaping every '<' as a JSON
	// unicode escape keeps the payload inside its own tag whatever the content.
	echo str_replace('<', '\\u003C',
		json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
?></script>
<script src="/assets/js/joinery-poll.js?v=<?php echo @filemtime(PathHelper::getIncludePath('assets/js/joinery-poll.js')) ?: '1'; ?>"></script>
<script src="/plugins/boards/assets/js/boards.js?v=<?php echo @filemtime(PathHelper::getIncludePath('plugins/boards/assets/js/boards.js')) ?: '1'; ?>"></script>
<?php
echo PublicPage::EndPage($hoptions);
$page->public_footer(['track' => TRUE]);
?>
