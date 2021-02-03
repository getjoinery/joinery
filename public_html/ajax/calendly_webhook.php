<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/orders_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormattingFunctions.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/product_details_class.php');



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

if($return['event'] == 'invitee.created'){
	$product_detail = new ProductDetail($tracking['salesforce_uuid'], TRUE);
	$product_detail->set('prd_status', ProductDetail::STATUS_BOOKED);
	$product_detail->set('prd_time_booked', $start_time_obj->format('Y-m-d H:i:s'));
	$product_detail->set('prd_link', $location);
	if($product_detail->get('prd_status') != ProductDetail::STATUS_COMPLETED){
		$product_detail->save();
	}
}

http_response_code(200);
?>