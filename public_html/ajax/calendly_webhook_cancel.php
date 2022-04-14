<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/FormattingFunctions.php');
	require_once( __DIR__ . '/../data/orders_class.php');
	require_once( __DIR__ . '/../data/product_details_class.php');


$payload = @file_get_contents('php://input');
$return = json_decode($payload, true);


$payload = $return['payload'];
$tracking = $payload['tracking'];
$event = $payload['event'];
$invitee = $payload['invitee'];
$start_time = $event['invitee_start_time'];

/*
$dbhelper = DbConnector::get_instance();
$dblink = $dbhelper->get_db_link();
$q = $dblink->prepare('INSERT INTO err_general_errors (err_context) VALUES (?)');
$q->bindValue(1, print_r($return, true), PDO::PARAM_STR);
$q->execute();
*/

if($return['event'] == 'invitee.canceled'){
	if(!$invitee['is_reschedule']){
		$product_detail = new ProductDetail($tracking['salesforce_uuid'], TRUE);
		$product_detail->set('prd_status', ProductDetail::STATUS_UNBOOKED);
		$product_detail->set('prd_time_booked', NULL);
		if($product_detail->get('prd_status') != ProductDetail::STATUS_COMPLETED){
			$product_detail->save();
		}
	}
}

http_response_code(200);
?>