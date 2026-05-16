<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * Logic for admin_page_permanent_delete
 * Handles permanent deletion of pages with smart component cascade:
 * components used only by this page are permanently deleted;
 * components shared with other active pages are left intact.
 */
function admin_page_permanent_delete_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));
	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_vars = array();

	// Handle POST - process deletion
	if (!empty($post_vars['confirm'])) {
		$pag_page_id = LibraryFunctions::fetch_variable('pag_page_id', NULL, 1, 'You must provide a page to delete.', $post_vars);

		$page = new Page($pag_page_id, TRUE);
		$page->authenticate_write(['current_user_id' => $session->get_user_id(), 'current_user_permission' => $session->get_permission()]);

		// Smart cascade: delete components not used by any other active page
		$layout = $page->get_component_layout();
		foreach ($layout as $pac_id) {
			$component = new PageContent((int)$pac_id, TRUE);
			if (!$component->key) continue;
			$contexts = $component->get_test_contexts();
			if (empty($contexts)) {
				$component->permanent_delete();
			}
		}

		$page->permanent_delete();

		return LogicResult::redirect('/admin/admin_pages');
	}

	// Handle GET - display confirmation
	$pag_page_id = LibraryFunctions::fetch_variable('pag_page_id', NULL, 1, 'You must provide a page to delete.', $get_vars);

	$page = new Page($pag_page_id, TRUE);

	$session->set_return('/admin/admin_pages');

	// Categorize components: will_delete (exclusive) vs will_keep (shared)
	$layout = $page->get_component_layout();
	$will_delete = [];
	$will_keep = [];

	foreach ($layout as $pac_id) {
		$component = new PageContent((int)$pac_id, TRUE);
		if (!$component->key) continue;
		$contexts = $component->get_test_contexts();
		if (empty($contexts)) {
			$will_delete[] = $component;
		} else {
			$will_keep[] = ['component' => $component, 'pages' => $contexts];
		}
	}

	$page_vars['page'] = $page;
	$page_vars['pag_page_id'] = $pag_page_id;
	$page_vars['will_delete'] = $will_delete;
	$page_vars['will_keep'] = $will_keep;
	$page_vars['session'] = $session;

	return LogicResult::render($page_vars);
}
