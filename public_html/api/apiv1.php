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

	try{
		$api_entry = ApiKey::GetByColumn('apk_public_key', $public_key);
	}
	catch (Exception $e){
		$response = array(
		  'api_version' => '1.0',
		  'data' => 'Error: Unable to find the api key'
		);
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;
	}

	try{
		$api_user = new User($api_entry->get('apk_usr_user_id'), TRUE);

	}
	catch (Exception $e){
		$response = array(
		  'api_version' => '1.0',
		  'data' => 'Error: Unable find the api user'
		);
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;
	}	
	
	if(!$api_user->key){
		$response = array(
		  'api_version' => '1.0',
		  'data' => 'Error: Unable find the api user'
		);
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;		
	}
	
	if($api_user->get('usr_delete_time')){
		$response = array(
		  'api_version' => '1.0',
		  'data' => 'Error: API user has been deleted'
		);
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;		
	}
	

	if($api_entry === NULL){
		//$error = error_get_last();
		http_response_code(401);
		exit;		
	}
	
	if($authorized_ips = $api_entry->get('apk_ip_restriction')){
		$ip_list = fgetcsv($authorized_ips);
		$ip_list = array_map('trim', $ip_list);
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
	
	$operation = ucwords($params[2]);


	$response = NULL;
	if(in_array($operation, $classes)){
		$class_name = $operation;
		
		
		if(strtolower($_SERVER['REQUEST_METHOD']) == 'get'){
			
			if($api_entry->get('apk_permission') == 2){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to fetch object, insufficient api permission'
				);
				header("Content-Type: application/json");
				http_response_code(403);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;				
			}			
			
			//IT IS A QUERY FOR A SINGLE OBJECT
			try{
				$object = new $class_name($params[3], TRUE);
				$object->authenticate_read(array('current_user_id'=>$api_user->key, 'current_user_permission'=>$api_user->get('usr_permission')));				
				$response = array(
				  'api_version' => '1.0',
				  'data' => $object->export_as_array()
				);
			}
			catch (Exception $e){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to fetch object ('.$e->getMessage().')'
				);
				header("Content-Type: application/json");
				http_response_code(400);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;
			}
		}
		else if(strtolower($_SERVER['REQUEST_METHOD']) == 'put'){
			//IT IS AN UPDATE TO A SINGLE OBJECT		
			if($api_entry->get('apk_permission') < 2){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to update object, insufficient api permission'
				);
				header("Content-Type: application/json");
				http_response_code(403);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;				
			}
			
			
			parse_str($_SERVER['QUERY_STRING'], $url_parts);

			try{
				$object = new $class_name($params[3], TRUE);	
				foreach($url_parts as $key=>$value){
					$object->set($key, $value);
				}
				$object->prepare();
				$object->authenticate_write(array('current_user_id'=>$api_user->key, 'current_user_permission'=>$api_user->get('usr_permission')));	
				$object->save();	

				//IT IS A QUERY FOR A SINGLE OBJECT
				$response = array(
				  'api_version' => '1.0',
				  'data' => $object->export_as_array()
				);
			}
			catch (Exception $e){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to update object ('.$e->getMessage().')'
				);
				header("Content-Type: application/json");
				http_response_code(400);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;
			}
		}
		else if(strtolower($_SERVER['REQUEST_METHOD']) == 'post'){
			//IT IS A NEW OBJECT
			if($api_entry->get('apk_permission') < 2 ){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to create object, insufficient api permission'
				);
				header("Content-Type: application/json");
				http_response_code(403);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;				
			}
			

			try{
				$object = new $class_name(NULL);	
				foreach($_POST as $key=>$value){
					$object->set($key, $value);
				}
				$object->prepare();
				$object->authenticate_write(array('current_user_id'=>$api_user->key, 'current_user_permission'=>$api_user->get('usr_permission')));	
				$object->save();	

				//IT IS A QUERY FOR A SINGLE OBJECT
				$response = array(
				  'api_version' => '1.0',
				  'data' => $object->export_as_array()
				);
			}
			catch (Exception $e){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to create object ('.$e->getMessage().')'
				);
				header("Content-Type: application/json");
				http_response_code(400);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;
			}
		}
		else if(strtolower($_SERVER['REQUEST_METHOD']) == 'delete'){
			//DELETE THE OBJECT
			if($api_entry->get('apk_permission') < 4){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to delete object, insufficient api permission'
				);
				header("Content-Type: application/json");
				http_response_code(403);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;				
			}
			

			try{
				$object = new $class_name($params[3], TRUE);
				$object->authenticate_write(array('current_user_id'=>$api_user->key, 'current_user_permission'=>$api_user->get('usr_permission')));		
				$object->soft_delete();	
				$object = new $class_name($params[3], TRUE);	

				//IT IS A QUERY FOR A SINGLE OBJECT
				$response = array(
				  'api_version' => '1.0',
				  'data' => $object->export_as_array()
				);
			}
			catch (Exception $e){
				$response = array(
				  'api_version' => '1.0',
				  'data' => 'Error: Unable to delete object ('.$e->getMessage().')'
				);
				header("Content-Type: application/json");
				http_response_code(400);

				$response = json_encode($response);
				echo $response . PHP_EOL;
				exit;
			}
		}
	}
	else if(in_array(substr($operation, 0, -1), $classes)){
		if($api_entry->get('apk_permission') == 2 || !$objects->authenticate_read(array('current_user_id'=>$api_user->key, 'current_user_permission'=>$api_user->get('usr_permission')))){
			$response = array(
			  'api_version' => '1.0',
			  'data' => 'Error: Unable to fetch objects, insufficient permission'
			);
			header("Content-Type: application/json");
			http_response_code(403);

			$response = json_encode($response);
			echo $response . PHP_EOL;
			exit;				
		}	
			
		//IT IS A QUERY FOR A LIST OF OBJECTS
		$class_name = substr($operation, 0, -1);
		$multiclassname = 'Multi'.$class_name;
		
		parse_str($_SERVER['QUERY_STRING'], $url_parts);

		if(isset($url_parts['page'])){
			$page = $url_parts['page'];
			unset($url_parts['page']);
		}
		else{
			$page = 0;
		}

		if(isset($url_parts['numperpage'])){
			$numperpage = $url_parts['numperpage'];
			unset($url_parts['numperpage']);
		}
		else{
			$numperpage = 3;
		}
		
		if(isset($url_parts['sort'])){
			$sort = $url_parts['sort'];
			unset($url_parts['sort']);
		}
		else{
			$sort = NULL;
		}
		
		if(isset($url_parts['sdirection'])){
			$sdirection = $url_parts['sdirection'];
			unset($url_parts['sdirection']);
		}
		else{
			$sdirection = 'ASC';
		}
		
		if($sort && $sdirection){
			$sortarray = array($sort=>$sdirection);
		}
		else{
			$sortarray = NULL;
		}
		
		$offset = $numperpage * $page;
		
		$objects = new $multiclassname(
			$url_parts,
			$sortarray,
			$numperpage,
			$offset);	
		$numobjects = $objects->count_all();	
		$objects->load();
		
		$response_array = array();
		foreach($objects as $object){
			$response_array[] = $object->export_as_array();
		}

		$response = array(
		  'api_version' => '1.0',
		  'num_results' => $numobjects,
		  'page' => $page,
		  'numperpage' => $numperpage,
		  'data' => $response_array
		);
	}

	if($response !== NULL){
		
		header("Content-Type: application/json");
		http_response_code(200);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;
	}
	else{
		$response = array(
		  'api_version' => '1.0',
		  'data' => 'Error: Invalid object or list ('.$operation.')'
		);
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;
	}

?>
