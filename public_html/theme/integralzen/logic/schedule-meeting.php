<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');

$replace_values = array('doshin_appt' => '');

$session = SessionControl::get_instance();
if($user_id = $session->get_user_id()){
	$user = new User($user_id, TRUE);
	$group = Group::get_by_name('Existing_Students', 'users');
	if($group->is_member_in_group($user->key)){
		$replace_values['doshin_appt'] = 'yes';
	}
	else{
		$replace_values['doshin_appt'] =  'no';	
	}
}
else
{
	$replace_values['doshin_appt'] =  'login';
}
?> 