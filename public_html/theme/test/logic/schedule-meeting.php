<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');

$replace_values = array('doshin_appt' => '');

$session = SessionControl::get_instance();
if($user_id = $session->get_user_id()){
	$user = new User($user_id, TRUE);
	$group = Group::get_by_name('Existing_Students');
	if($group->is_user_in_group($user->key)){
		$replace_values['doshin_appt'] = '<p>If you have been invited to make an appointment:<br /><br /><a class="et_pb_button" href="https://integralzen.as.me/?appointmentType=category:Session%20with%20Doshin">Schedule a meeting with Doshin</a></p>';
	}
	else{
		$replace_values['doshin_appt'] =  '<p>Meetings with Doshin are available to existing students only.</p>';	
	}
}
else
{
	$replace_values['doshin_appt'] =  '<p>Please <a href="/login">log in</a> to book a session with Doshin.</p>';
}
?> 