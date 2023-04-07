<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/FormattingFunctions.php');
	require_once( __DIR__ . '/../data/orders_class.php');
	require_once( __DIR__ . '/../data/bookings_class.php');

	header("HTTP/1.0 404 Not Found");
	echo 'Feature turned off';
	exit;


$payload = @file_get_contents('php://input');
$return = json_decode($payload, true);


$payload = $return['payload'];
$tracking = $payload['tracking'];
$event = $payload['event'];
$start_time = $event['invitee_start_time'];

if($event['location']){
	$location = $event['location'];
}
else{
	$location = NULL;
}


//CONVERT TIMEZONE
$tz = substr($start_time, -6);
$start_time_obj = new DateTime($start_time, new DateTimeZone($tz));
$start_time_obj->setTimezone(new DateTimeZone('UTC'));


/*
$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();
$q = $dblink->prepare('INSERT INTO err_general_errors (err_context) VALUES (?)');
$q->bindValue(1, print_r($return, true), PDO::PARAM_STR);
$q->execute();
*/
// Reference:  https://calendly.stoplight.io/docs/api-docs/c2NoOjIxNDU0NTQ2-webhook-payload
if($return['event'] == 'invitee.created'){
	$booking = new Booking($tracking['salesforce_uuid'], TRUE);
	$booking->set('prd_status', Booking::BOOKING_STATUS_BOOKED);
	$booking->set('prd_time', $start_time_obj->format('Y-m-d H:i:s'));
	$booking->set('prd_link', $location);
	if($booking->get('prd_status') != Booking::BOOKING_STATUS_COMPLETED){
		$booking->save();
	}
}

http_response_code(200);
?>