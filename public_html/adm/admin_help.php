<?php
/**
 * Admin Help - Documentation Viewer
 *
 * Renders markdown documentation files from docs/ with a sidebar nav and
 * content area. Mirrors the public documentation viewer at /documentation;
 * both share DocsScanner.
 */

require_once(PathHelper::getIncludePath('adm/logic/admin_help_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/DocsScanner.php'));

$page_vars = process_logic(admin_help_logic($_GET, $_POST));

$session = $page_vars['session'];
$doc_tree = $page_vars['doc_tree'];
$selected_doc = $page_vars['selected_doc'];
$rendered_html = $page_vars['rendered_html'];
$page_title = $page_vars['page_title'];
$error = $page_vars['error'];
$base_url = $page_vars['base_url'];

$breadcrumbs = array('Help' => $base_url);
if (!empty($selected_doc)) {
	$breadcrumbs[$page_title] = '';
}

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => null,
		'page_title' => 'Help' . (!empty($selected_doc) ? ' - ' . $page_title : ''),
		'readable_title' => 'Documentation',
		'breadcrumbs' => $breadcrumbs,
		'session' => $session,
		'no_page_card' => true,
	)
);

?>
<style>
	<?php echo MarkdownRenderer::get_css(); ?>
	<?php echo DocsScanner::get_layout_css(); ?>
</style>
<?php
echo DocsScanner::render_viewer($doc_tree, $selected_doc, $rendered_html, $error, $base_url);

$page->admin_footer();
?>
