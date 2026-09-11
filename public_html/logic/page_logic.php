<?php
function page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = [];
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('page_contents_active')){
		return LogicResult::error('This feature is turned off');
	}

	$page = null;
	if (!empty($input['slug'])) {
		$page = Page::get_by_link($input['slug']);
	}
	if (!$page || !$page->key) {
		require_once(LibraryFunctions::display_404_page());
	}
	$page_vars['page'] = $page;

	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$page->get('pag_published_time') || $page->get('pag_delete_time')){
			require_once(LibraryFunctions::display_404_page());
		}
	}


	return LogicResult::render($page_vars);
}
?>

