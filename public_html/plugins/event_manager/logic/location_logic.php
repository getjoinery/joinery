<?php
function location_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/page_contents_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/locations_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = [];
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('page_contents_active')){
		return LogicResult::error('This feature is turned off');
	}

	$location = null;
	if (!empty($input['slug'])) {
		$location = Location::get_by_link($input['slug']);
	}
	if (!$location || !$location->key) {
		require_once(LibraryFunctions::display_404_page());
	}
	$page_vars['location'] = $location;

	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$location->get('loc_is_published') || $location->get('loc_delete_time')){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	
	
	
	return LogicResult::render($page_vars);
}
?>

