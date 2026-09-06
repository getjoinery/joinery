<?php
/**
 * Admin Help Documentation Viewer - Logic
 *
 * Thin wrapper over the shared DocsScanner. Scans docs/, validates the
 * ?doc= parameter, renders markdown for display.
 */

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/MarkdownRenderer.php'));
require_once(PathHelper::getIncludePath('includes/DocsScanner.php'));

function admin_help_logic(array $input): LogicResult {

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$settings = Globalvars::get_instance();
	$docs_dir = PathHelper::getIncludePath('docs');
	$base_url = '/admin/admin_help';

	$doc_tree = DocsScanner::scan_all($docs_dir);

	$selected_doc = isset($input['doc']) ? $input['doc'] : '';
	$rendered_html = '';
	$page_title = 'Documentation';
	$error = '';
	$meta_description = '';

	if (!empty($selected_doc)) {
		$result = DocsScanner::load_doc($selected_doc, $docs_dir);
		if ($result['error']) {
			$error = $result['error'];
		} else {
			$current_doc_dir = $docs_dir;
			if (strpos($selected_doc, 'plugin/') === 0) {
				$parts = explode('/', $selected_doc);
				$current_doc_dir = PathHelper::getIncludePath('plugins/' . $parts[1] . '/docs');
			}
			$rendered_html = MarkdownRenderer::render($result['content']);
			$rendered_html = MarkdownRenderer::rewrite_doc_links($rendered_html, $current_doc_dir, $base_url);
			$page_title = $result['title'];
			$meta_description = $result['description'];
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

	// The docs are source files that ship inside the release archive, so an edit
	// made on a site that consumes releases is overwritten by its next upgrade
	// with nothing to show for it. Only the origin -- the deployment the code is
	// written on -- offers the editor, and only to a superadmin.
	$edit_url = '';
	if (empty($error) && DeploymentHelper::isOriginNode() && $session->get_permission() >= 10) {
		// No ?doc= is the Overview, which renders docs/index.md.
		$edit_key = empty($selected_doc) ? 'index' : $selected_doc;
		$resolved = DocsScanner::resolve_path($edit_key, $docs_dir);
		if ($resolved['error'] === '') {
			$edit_url = '/admin/admin_help_edit?doc=' . $edit_key;
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
	$page_vars['edit_url'] = $edit_url;

	return LogicResult::render($page_vars);
}
?>
