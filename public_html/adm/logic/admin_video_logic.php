<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/videos_class.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));

function admin_video_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$video = new Video($input['vid_video_id'], TRUE);
	$user = new User($video->get('vid_usr_user_id'), TRUE);

	if($input['action'] == 'remove'){
		$video->assert_can_write($session);
		$video->permanent_delete();

		//$returnurl = $session->get_return();
		return LogicResult::redirect("/admin/admin_videos");
	}

	if($input['action'] == 'delete'){
		$video->assert_can_write($session);
		$video->soft_delete();

		return LogicResult::redirect("/admin/admin_videos");
	}
	else if($input['action'] == 'undelete'){
		$video->assert_can_write($session);
		$video->undelete();

		return LogicResult::redirect("/admin/admin_videos");
	}

	// Build dropdown actions
	$options['altlinks'] = array('Edit Video'=>'/admin/admin_video_edit?vid_video_id='.$video->key);
	if($video->get('vid_delete_time')){
		$options['altlinks']['Undelete'] = array('post' => '/admin/admin_video', 'hidden' => array('action' => 'undelete', 'vid_video_id' => $video->key));
	}
	else{
		$options['altlinks']['Soft Delete'] = array('post' => '/admin/admin_video', 'hidden' => array('action' => 'delete', 'vid_video_id' => $video->key));
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanently Delete' => array('post' => '/admin/admin_video', 'hidden' => array('action' => 'remove', 'vid_video_id' => $video->key)));
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
		$dropdown_button .= '<div class="dropdown-menu dropdown-menu-end py-0">';
		foreach ($options['altlinks'] as $label => $entry) {
			$is_danger = strpos($label, 'Delete') !== false;
			$dropdown_button .= AdminPage::renderActionEntry($label, $entry, 'dropdown-item' . ($is_danger ? ' text-danger' : ''));
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
	if($video->get('vid_access_provider')){
		require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
		$gate = AccessGateRegistry::get($video->get('vid_access_provider'));
		$gate_ref_label = $gate ? ($gate->options()[$video->get('vid_access_ref')] ?? $video->get('vid_access_ref')) : $video->get('vid_access_ref');
		$gate_kind = $gate ? $gate->label() : $video->get('vid_access_provider');
		$permission_text .= 'Only logged in users passing the '.$gate_kind.' gate for "'.$gate_ref_label.'" ';
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

	$access_gate_label = null;
	if($video->get('vid_access_provider')){
		require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
		$gate = AccessGateRegistry::get($video->get('vid_access_provider'));
		if($gate){
			$ref_label = $gate->options()[$video->get('vid_access_ref')] ?? $video->get('vid_access_ref');
			$access_gate_label = $gate->label().': '.$ref_label;
		} else {
			$access_gate_label = $video->get('vid_access_provider').' #'.$video->get('vid_access_ref');
		}
	}

	$page_vars = array(
		'session' => $session,
		'video' => $video,
		'user' => $user,
		'dropdown_button' => $dropdown_button,
		'permission_text' => $permission_text,
		'group_or_event' => $group_or_event,
		'group' => $group,
		'access_gate_label' => $access_gate_label,
	);

	return LogicResult::render($page_vars);
}
