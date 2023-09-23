<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../utils/class_list.php');

	$settings = Globalvars::get_instance();
	$siteDir = $settings->get_setting('siteDir');
	require_once($siteDir . '/data/api_keys_class.php');


	$source_ip = $_SERVER['REMOTE_ADDR'];
	$headers = getallheaders();
	$public_key = $headers['public_key'];
	$secret_key = $headers['secret_key'];

	$api_entry = ApiKey::GetByColumn('apk_public_key', $public_key);

	if($api_entry === NULL){
		http_response_code(401);
		exit;		
	}
	
	if($authorized_ips = $api_entry->get('apk_ip_restriction')){
		$ip_list = fgetcsv($authorized_ips);
		if(count($ip_list)){
			if(!in_array($_SERVER['REMOTE_ADDR'], $ip_list)){
				http_response_code(401);
				exit;
			}
		}
	}
	
	
	
	if(!$api_entry->check_secret_key($secret_key)){
		http_response_code(401);
		exit;
	}
	
	
	//TODO MAKE MORE EFFICIENT
	$object = new $params[2]($params[3], TRUE);
	
	// Collection object
	$response = array(
	  'api_version' => '1.0',
	  'data' => $object->export_as_array()
	);
	
	header("Content-Type: application/json");
	http_response_code(200);

	$response = json_encode($response);
	echo $response . PHP_EOL;

?>
