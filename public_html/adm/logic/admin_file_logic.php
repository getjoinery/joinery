<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_file_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$settings = Globalvars::get_instance();

	$file = new File($input['fil_file_id'], TRUE);
	$user = new User($file->get('fil_usr_user_id'), TRUE);

	// Handle actions
	$post_action = $input['action'] ?? null;
	$get_action = $input['action'] ?? null;

	if($post_action == 'remove'){
		$file->assert_can_write($session);
		$file->permanent_delete();

		return LogicResult::redirect('/admin/admin_files');
	}
	else if($get_action == 'delete'){
		$file->assert_can_write($session);
		$file->soft_delete();

		return LogicResult::redirect('/admin/admin_files');
	}
	else if($get_action == 'undelete'){
		$file->assert_can_write($session);
		$file->undelete();

		return LogicResult::redirect('/admin/admin_files');
	}

	// Build dropdown actions
	$options['altlinks'] = array();
	$options['altlinks'] += array('Edit File' => '/admin/admin_file_edit?fil_file_id='.$file->key);
	if($file->get('fil_delete_time')){
		$options['altlinks'] += array('Undelete' => array('post' => '/admin/admin_file', 'hidden' => array('action' => 'undelete', 'fil_file_id' => $file->key)));
	}
	else{
		$options['altlinks'] += array('Soft Delete' => array('post' => '/admin/admin_file', 'hidden' => array('action' => 'delete', 'fil_file_id' => $file->key)));
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
	if($file->get('fil_grp_group_id')){
		$group = new Group($file->get('fil_grp_group_id'), TRUE);
		$permission_text .= 'Only logged in users in the "'.$group->get('grp_name').'" group ';
		$group_or_event = true;
	}
	if($file->get('fil_access_provider')){
		require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
		$gate = AccessGateRegistry::get($file->get('fil_access_provider'));
		$gate_ref_label = $gate ? ($gate->options()[$file->get('fil_access_ref')] ?? $file->get('fil_access_ref')) : $file->get('fil_access_ref');
		$gate_kind = $gate ? $gate->label() : $file->get('fil_access_provider');
		$permission_text .= 'Only logged in users passing the '.$gate_kind.' gate for "'.$gate_ref_label.'" ';
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

	// Load group if it exists; resolve the access gate to a display label.
	$group = null;
	if($file->get('fil_grp_group_id')){
		$group = new Group($file->get('fil_grp_group_id'), TRUE);
	}
	$access_gate_label = null;
	if($file->get('fil_access_provider')){
		require_once(PathHelper::getIncludePath('includes/AccessGateRegistry.php'));
		$gate = AccessGateRegistry::get($file->get('fil_access_provider'));
		if($gate){
			$ref_label = $gate->options()[$file->get('fil_access_ref')] ?? $file->get('fil_access_ref');
			$access_gate_label = $gate->label().': '.$ref_label;
		} else {
			$access_gate_label = $file->get('fil_access_provider').' #'.$file->get('fil_access_ref');
		}
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
		'access_gate_label' => $access_gate_label,
	);

	return LogicResult::render($page_vars);
}
