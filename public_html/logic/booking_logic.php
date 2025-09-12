<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function booking_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/SessionControl.php');

	require_once(PathHelper::getIncludePath('plugins/bookings/data/bookings_class.php'));
	require_once(PathHelper::getIncludePath('plugins/bookings/data/booking_types_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('bookings_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	$booking_type_id = $get_vars['booking_type_id'];
	$booking_type = new BookingType($booking_type_id, TRUE);
	$client_user = new User($session->get_user_id(), TRUE);
	$page_vars['booking_type'] = $booking_type;
	$page_vars['client_user'] = $client_user;	
	
	if ($session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if($booking_type->get('bkt_delete_time') || !$booking_type->get('bkt_status')){
			require_once(LibraryFunctions::display_404_page());	
		}
	}	


	return $page_vars;
}
?>

