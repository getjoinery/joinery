<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_file_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();

	$file = new File($get_vars['fil_file_id'], TRUE);
	$user = new User($file->get('fil_usr_user_id'), TRUE);

	// Handle actions
	if($post_vars['action'] == 'remove'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->permanent_delete();

		return LogicResult::redirect('/admin/admin_files');
	}
	else if($post_vars['action'] == 'fileremove'){
		$event_session = new EventSession($post_vars['evs_event_session_id'], TRUE);
		$event_session->remove_file($post_vars['fil_file_id']);

		return LogicResult::redirect('/admin/admin_file?fil_file_id='.$file->key);
	}
	else if($post_vars['action'] == 'fileadd'){
		$event_session = new EventSession($post_vars['evs_event_session_id'], TRUE);
		$event_session->add_file($post_vars['fil_file_id']);

		return LogicResult::redirect('/admin/admin_file?fil_file_id='.$file->key);
	}
	else if($get_vars['action'] == 'delete'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->soft_delete();

		return LogicResult::redirect('/admin/admin_files');
	}
	else if($get_vars['action'] == 'undelete'){
		$file->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$file->undelete();

		return LogicResult::redirect('/admin/admin_files');
	}

	// Build dropdown actions
	$options['altlinks'] = array();
	$options['altlinks'] += array('Edit File' => '/admin/admin_file_edit?fil_file_id='.$file->key);
	if($file->get('fil_delete_time')){
		$options['altlinks'] += array('Undelete' => '/admin/admin_file?action=undelete&fil_file_id='.$file->key);
	}
	else{
		$options['altlinks'] += array('Soft Delete' => '/admin/admin_file?action=delete&fil_file_id='.$file->key);
	}
	if($session->get_user_id() == 1){
		$options['altlinks'] += array('Permanently Delete' => '/admin/admin_file_delete?fil_file_id='.$file->key);
	}

	// Build dropdown button from altlinks
	$dropdown_button = '';
	if (!empty($options['altlinks'])) {
		$dropdown_button = '<div class="dropdown">';
		$dropdown_button .= '<button class="btn btn-soft-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">Actions</button>';
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
	if($file->get('fil_grp_group_id')){
		$group = new Group($file->get('fil_grp_group_id'), TRUE);
		$permission_text .= 'Only logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event = true;
	}
	if($file->get('fil_evt_event_id')){
		$event = new Event($file->get('fil_evt_event_id'), TRUE);
		$permission_text .= 'Only logged in users registered for the "'.$event->get('evt_name').'" event ';
		$group_or_event = true;
	}
	if($group_or_event){
		if($file->get('fil_min_permission') > 0){
			$permission_text .= 'with minimum permission ('.$file->get('fil_min_permission').') ';
		}
	}
	else{
		if($file->get('fil_min_permission') === NULL){
			$permission_text .= 'Anyone ';
		}
		else if($file->get('fil_min_permission') === 0){
			$permission_text .= 'Anyone logged in';
		}
		else{
			$permission_text .= 'Minimum permission ('.$file->get('fil_min_permission').') ';
		}
	}
	$permission_text .= 'can access this file.';

	// Load group and event if they exist
	$group = null;
	$event = null;
	if($file->get('fil_grp_group_id')){
		$group = new Group($file->get('fil_grp_group_id'), TRUE);
	}
	if($file->get('fil_evt_event_id')){
		$event = new Event($file->get('fil_evt_event_id'), TRUE);
	}

	$page_vars = array(
		'session' => $session,
		'settings' => $settings,
		'file' => $file,
		'user' => $user,
		'dropdown_button' => $dropdown_button,
		'permission_text' => $permission_text,
		'group_or_event' => $group_or_event,
		'group' => $group,
		'event' => $event,
	);

	return LogicResult::render($page_vars);
}
