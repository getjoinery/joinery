<?php
	require_once('../includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;
	
	//IF WE ARE ACTING AS A SERVER, AND SOMEONE REQUESTS THE INFO FOR UPGRADING
	if($_GET['serve-upgrade'] && $settings->get_setting('upgrade_server_active')){
		$response = array();
		$response['system_version'] = $settings->get_setting('system_version');
		//TODO:  MAKE UPGRADE LOCATION NOT STATIC USING THE UPGRADE_LOCATION SETTING
		//$response['upgrade_location'] = $settings->get_setting('webDir').$settings->get_setting('upgrade_location');
		$response['upgrade_location'] = $settings->get_setting('webDir').'/static_files/current_upgrade.upg.zip';
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;		
	}
	
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	//GET THE UPGRADE INFO
	$upgrade_source = $settings->get_setting('upgrade_source').'/utils/upgrade?request-upgrade=1';
	$access_token = '';	
	$curl=curl_init();
	curl_setopt_array($curl, array(
	  CURLOPT_URL => $upgrade_source,
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_ENCODING => '',
	  CURLOPT_MAXREDIRS => 10,
	  CURLOPT_TIMEOUT => 0,
	  CURLOPT_FOLLOWLOCATION => true,
	  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	  CURLOPT_CUSTOMREQUEST => 'GET',
	));	
	$response = curl_exec($curl);
	curl_close($curl);
	print_r( json_decode($response, true));
	exit;
	
	
	$sourceFile     = 'https://jeremytunnell.com/static_files/current_upgrade.zip';
	$target_location = $full_site_dir.'/uploads/current_upgrade.zip';
	$stage_location = $full_site_dir.'/uploads/upgrades/';
	$live_directory = $full_site_dir. 'public_html';
	$backup_directory = $full_site_dir. 'public_html_last';
	$stage_directory = $stage_location. 'public_html_stage';
	$theme_directory = $full_site_dir.'/theme';
	
	//GET THE UPGRADE FILE
	echo 'Getting: '. $sourceFile.'<br>';;
	/*
	if(saveFileByUrl($sourceFile, $target_location)){
		echo 'Upgrade downloaded...<br>';
	}
	else{
		echo 'Unable to download upgrade...<br>';
		exit;
	}
	*/

	/*
	if(download_file($sourceFile, $target_location)){
		echo 'Upgrade downloaded...<br>';
	}
	else{
		echo 'Unable to download upgrade...<br>';
		exit;
	}
	*/

	$new_file = fopen($target_location, "w") or die("cannot open" . $target_location);

	// Setting the curl operations
	$cd = curl_init();
	curl_setopt($cd, CURLOPT_URL, $sourceFile);
	curl_setopt($cd, CURLOPT_FILE, $new_file);
	curl_setopt($cd, CURLOPT_TIMEOUT, 30); // timeout is 30 seconds, to download the large files you may need to increase the timeout limit.

	curl_exec($cd);
	if (curl_errno($cd)) {
	  echo "the cURL error is : " . curl_error($cd);
	  exit;
	} 
	else {
	  $status = curl_getinfo($cd);
	  if($status["http_code"] == 200){
		echo "The upgrade is downloaded...<br>";
	  }
		else{
			echo "The error code is : " . $status["http_code"];
			exit;
		}	
	  // the http status 200 means everything is going well. the error codes can be 401, 403 or 404.
	}

	// close and finalize the operations.
	curl_close($cd);
	fclose($new_file);	
	
	
	
	
	/*
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,$sourceFile);
	$fp = fopen($target_location, 'w');
	curl_setopt($ch, CURLOPT_FILE, $fp);
	curl_exec ($ch);
	curl_close ($ch);
	fclose($fp);
	*/
	chmod($target_location, 0777);
	

	//CLEAR OLD STAGED FILES 
	exec ("rm -rf $stage_location");
	
	//$result = array();
	//system("unzip $target_location $stage_location");

	$zip = new ZipArchive;
	if ($zip->open($target_location)){
	  $zip->extractTo($stage_location);
	  $zip->close();
	  echo 'Upgrade unzipped...<br>';
	} 
	else {
	  echo 'Unable to unzip upgrade<br>';
	  exit;
	}			
	
	//TODO: DO BACKUPS
	
	
	//TODO:  THIS IS A HACK, FIX IT
	rename($stage_location.'var/www/html/jeremytunnell/public_html_stage', $stage_directory);
	
	//COPY THE THEME FILES 
	exec("cp -r $theme_directory $stage_directory");
	
	//RUN THE DEPLOY
	exec ("rm -rf $backup_directory");
	rename($live_directory, $backup_directory);
	rename($stage_directory, $live_directory);
	
	//DO THE MIGRATION
	require_once($_SERVER['DOCUMENT_ROOT'].'/utils/update_database.php');
	
	
	
	//UNTAR IT 
	/*
	try {
		$phar = new PharData($targetLocation);
		$phar->extractTo($stage_location); // extract all files
	} catch (Exception $e) {
		print_r($e);
	}	
	*/		

	function download_file ($url, $path) {

	  $newfilename = $path;
	  $file = fopen ($url, "rb");
	  if ($file) {
		$newfile = fopen ($newfilename, "wb");

		if ($newfile)
		while(!feof($file)) {
		  fwrite($newfile, fread($file, 1024 * 8 ), 1024 * 8 );
		}
		else{
			return false;
		}
	  }
	  else{
		  return false;
	  }

	  return true;
	 }			


	function saveFileByUrl ( $source, $destination ) {

		   return file_put_contents($destination, file_get_contents($source));
		
	}

	function deleteDir($dirPath) {
		if (! is_dir($dirPath)) {
			throw new InvalidArgumentException("$dirPath must be a directory");
		}
		if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}
		$files = glob($dirPath . '*', GLOB_MARK);
		foreach ($files as $file) {
			if (is_dir($file)) {
				deleteDir($file);
			} else {
				unlink($file);
			}
		}
		rmdir($dirPath);
	}
	
	function deleteallfiles($directory){
			$files = glob($directory); // get all file names
		foreach($files as $file){ // iterate files
		  if(is_file($file)) {
			unlink($file); // delete file
		  }
		}			
	}


?>