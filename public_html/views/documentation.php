<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('logic/documentation_logic.php'));

$page_vars = process_logic(documentation_logic(array_merge($_GET, $params ?? [])));

$session = $page_vars['session'];
$doc_tree = $page_vars['doc_tree'];
$selected_doc = $page_vars['selected_doc'];
$rendered_html = $page_vars['rendered_html'];
$page_title = $page_vars['page_title'];
$error = $page_vars['error'];
$base_url = $page_vars['base_url'];
$meta_description = $page_vars['meta_description'];

$page = new PublicPage();
$header_options = array(
	'is_valid_page' => $is_valid_page ?? true,
	'title' => empty($selected_doc) ? 'Documentation' : ($page_title . ' | Documentation'),
);
if (!empty($meta_description)) {
	$header_options['meta_description'] = $meta_description;
} elseif (empty($selected_doc)) {
	$header_options['meta_description'] = 'Platform documentation.';
}
$page->public_header($header_options);
?>

<div class="jy-ui">

<!-- Generated subsystem CSS (markdown renderer + docs viewer layout); not hand-written
     page styling, so it remains inline rather than moving into the kit stylesheet. -->
<style>
	<?php echo MarkdownRenderer::get_css(); ?>
	<?php echo DocsScanner::get_layout_css(); ?>
</style>

<section class="jy-docs-section">
	<div class="container">
		<?php echo DocsScanner::render_viewer($doc_tree, $selected_doc, $rendered_html, $error, $base_url); ?>
	</div>
</section>

</div>

<?php
$page->public_footer(array('track' => true));
?>
