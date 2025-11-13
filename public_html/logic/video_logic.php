<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function video_logic($get_vars, $post_vars, $video, $params){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/videos_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('videos_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}
	
	$page_vars['video'] = $video;

	if($params[0] != 'video' || !$video){
		require_once(LibraryFunctions::display_404_page());	
	}
	
	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$video->authenticate_read($session)){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	
	
	return LogicResult::render($page_vars);
}
?>

