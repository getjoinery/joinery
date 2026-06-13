<?php
function video_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/videos_class.php'));

	$session = SessionControl::get_instance();
	$page_vars = [];
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('videos_active')){
		return LogicResult::error('This feature is turned off');
	}

	$video = null;
	if (!empty($input['slug'])) {
		$video = Video::get_by_link($input['slug']);
	}
	if (!$video || !$video->key) {
		require_once(LibraryFunctions::display_404_page());
	}
	$page_vars['video'] = $video;

	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$video->is_viewable($session)){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	
	
	return LogicResult::render($page_vars);
}
?>

