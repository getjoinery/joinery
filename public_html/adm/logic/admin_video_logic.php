<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/videos_class.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/events_class.php'));

function admin_video_logic($get_vars, $post_vars) {
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$video = new Video($get_vars['vid_video_id'], TRUE);
	$user = new User($video->get('vid_usr_user_id'), TRUE);

	if($get_vars['action'] == 'remove'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->permanent_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_videos");
	}

	if($get_vars['action'] == 'delete'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->soft_delete();

		return LogicResult::redirect("/admin/admin_videos");
	}
	else if($get_vars['action'] == 'undelete'){
		$video->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$video->undelete();

		return LogicResult::redirect("/admin/admin_videos");
	}

	// Build dropdown actions
	$options['altlinks'] = array('Edit Video'=>'/admin/admin_video_edit?vid_video_id='.$video->key);
	if($video->get('vid_delete_time')){
		$options['altlinks']['Undelete'] = '/admin/admin_video?action=undelete&vid_video_id='.$video->key;
	}
	else{
		$options['altlinks']['Soft Delete'] = '/admin/admin_video?action=delete&vid_video_id='.$video->key;
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanently Delete' => '/admin/admin_video?action=remove&vid_video_id='.$video->key);
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $url) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= '<a href="' . htmlspecialchars($url) . '" class="dropdown-item' . ($is_danger ? ' text-danger' : '') . '">' . htmlspecialchars($label) . '</a>';
		}
		$dropdown_button .= '</div>';
		$dropdown_button .= '</div>';
	}

	// Get permission text
	$permission_text = '';
	$group_or_event = false;
	$group = null;
	$event = null;
	if($video->get('vid_grp_group_id')){
		$group = new Group($video->get('vid_grp_group_id'), TRUE);
		$permission_text .= 'Only logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event = true;
	}
	if($video->get('vid_evt_event_id')){
		$event = new Event($video->get('vid_evt_event_id'), TRUE);
		$permission_text .= 'Only logged in users registered for the "'.$event->get('evt_name').'" event ';
		$group_or_event = true;
	}
	if($group_or_event){
		if($video->get('vid_min_permission') > 0){
			$permission_text .= 'with minimum permission ('.$video->get('vid_min_permission').') ';
		}
	}
	else{
		if($video->get('vid_min_permission') === NULL){
			$permission_text .= 'Anyone ';
		}
		else if($video->get('vid_min_permission') === 0){
			$permission_text .= 'Anyone logged in';
		}
		else{
			$permission_text .= 'Minimum permission ('.$video->get('vid_min_permission').') ';
		}
	}
	$permission_text .= 'can access this video.';

	$page_vars = array(
		'session' => $session,
		'video' => $video,
		'user' => $user,
		'dropdown_button' => $dropdown_button,
		'permission_text' => $permission_text,
		'group_or_event' => $group_or_event,
		'group' => $group,
		'event' => $event,
	);

	return LogicResult::render($page_vars);
}
