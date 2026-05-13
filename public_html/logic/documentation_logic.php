<?php
/**
 * Public Documentation Page - Logic
 *
 * Mirrors adm/logic/admin_help_logic.php but with no permission check and a
 * /documentation base URL. Uses the shared DocsScanner.
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));
require_once(PathHelper::getIncludePath('includes/DocsScanner.php'));

function documentation_logic($vars) {

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	$docs_dir = PathHelper::getIncludePath('docs');
	$base_url = '/documentation';

	$doc_tree = DocsScanner::scan($docs_dir);

	$selected_doc = isset($vars['doc']) ? $vars['doc'] : '';
	$rendered_html = '';
	$page_title = 'Documentation';
	$error = '';
	$meta_description = '';

	if (!empty($selected_doc)) {
		$result = DocsScanner::load_doc($selected_doc, $docs_dir);
		if ($result['error']) {
			$error = $result['error'];
		} else {
			$rendered_html = MarkdownRenderer::render($result['content']);
			$rendered_html = MarkdownRenderer::rewrite_doc_links($rendered_html, $docs_dir, $base_url);
			$page_title = $result['title'];
			$meta_description = DocsScanner::extract_description($docs_dir . '/' . $selected_doc . '.md');
		}
	} else {
		$index_path = $docs_dir . '/index.md';
		if (file_exists($index_path) && is_readable($index_path)) {
			$rendered_html = MarkdownRenderer::render(file_get_contents($index_path));
			$rendered_html = MarkdownRenderer::rewrite_doc_links($rendered_html, $docs_dir, $base_url);
		} else {
			$rendered_html = DocsScanner::render_landing($doc_tree, $base_url);
		}
	}

	$page_vars = array();
	$page_vars['settings'] = $settings;
	$page_vars['session'] = $session;
	$page_vars['doc_tree'] = $doc_tree;
	$page_vars['selected_doc'] = $selected_doc;
	$page_vars['rendered_html'] = $rendered_html;
	$page_vars['page_title'] = $page_title;
	$page_vars['error'] = $error;
	$page_vars['meta_description'] = $meta_description;
	$page_vars['base_url'] = $base_url;

	return LogicResult::render($page_vars);
}
?>
