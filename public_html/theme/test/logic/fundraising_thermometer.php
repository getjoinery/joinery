<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/order_items_class.php');
	$replace_values = array('total_raised' => '');

	$initial_raised = 2846;
	$recent_raised = 0;
	
	$order_items = new MultiOrderItem(array('order_date_after' => '2020-11-6'));
	$order_items->load();
	
	foreach($order_items as $order_item){
		if($order_item->get('odi_pro_product_id') ==2){
			$recent_raised += $order_item->get('odi_price');
		}
		elseif($order_item->get('odi_pro_product_id') ==3){
			$recent_raised += $order_item->get('odi_price') * 12;
		}
	}
	
	//GO BACK TO NO MATCH AFTER 15000
	if($recent_raised + $initial_raised >= 15000){ 
		$replace_values['total_raised'] = 15000 + $recent_raised + $initial_raised;
	}
	else{
		$replace_values['total_raised'] = ($initial_raised + $recent_raised) * 2;
	}
	
?>