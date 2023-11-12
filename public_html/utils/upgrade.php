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
	$upgrade_source = $settings->get_setting('upgrade_source').'/utils/upgrade?serve-upgrade=1';
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
	$decode_response = json_decode($response, true);
	if($decode_response['system_version']){
		echo 'Upgrade available: '. $decode_response['system_version'] . '<br>';
		echo 'Current local version: '.$settings->get_setting('system_version').'<br>';
		
		//TODO: SYSTEM VERSIONS ONLY INCREMENT WITH MIGRATIONS
		/*
		if($decode_response['system_version'] >= $settings->get_setting('system_version') && !$_GET['force-upgrade']){
			echo 'You are up-to-date and do not need an upgrade.<br>';
			exit;
		}
		*/
	}
	else{
		if(!$settings->get_setting('upgrade_source')){
			echo 'Upgrade server not set.  Go to the settings and enter one.<br>';
			exit;			
		}
		else{
			echo 'Unable to reach upgrade server: '.$upgrade_source.'<br>';
			exit;
		}
	}
	
	$sourceFile = $decode_response['upgrade_location'];
	
	
	//$sourceFile     = 'https://jeremytunnell.com/static_files/current_upgrade.zip';
	$file_download_location = $full_site_dir.'/uploads/current_upgrade.upg.zip';
	$stage_location = $full_site_dir.'/uploads/upgrades/';
	$live_directory = $full_site_dir. '/public_html';
	$backup_directory = $full_site_dir. '/public_html_last';
	$stage_directory = $stage_location. 'public_html_stage';
	$failed_directory = $stage_location. 'public_html_fail';
	$theme_directory = $full_site_dir.'/theme';
	
	
	
	
	//GET THE UPGRADE FILE
	echo 'Getting: '. $sourceFile.'<br>';;


	$new_file = fopen($file_download_location, "w") or die("cannot open" . $file_download_location);

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
			//echo "The upgrade is downloaded...<br>";
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

	if(file_exists($file_download_location)){
		echo "The upgrade is downloaded...<br>";
	}
	else{
		echo "The upgrade failed to download...aborting.<br>";
		exit;
	}
	
	//chmod($file_download_location, 0777);
	
	

	//CLEAR OLD STAGED FILES 
	exec ("rm -rf $stage_location");
	if(file_exists($stage_location)){
		echo "Failed to clear staging location...aborting.<br>";
		exit;
	}
	
	//CREATE NEW STAGE LOCATION
	echo 'Creating '.$stage_location.'<br>';
	mkdir($stage_location, 0777);
	chmod($stage_location, 0777);
	if(!file_exists($stage_location)){
		echo "Failed to create new staging location...aborting.<br>";
		exit;
	}
	
	//$result = array();
	//system("unzip $file_download_location $stage_location");

	$zip = new ZipArchive;
	if ($zip->open($file_download_location)){
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
	$location_of_themes = $stage_directory.'/theme';
	if(!file_exists($location_of_themes)){
		echo "Failed to move theme files...aborting.<br>";
		exit;
	}
	
	//RUN THE DEPLOY
	echo 'Removing '.$backup_directory.'<br>';
	exec ("rm -rf $backup_directory");
	if(file_exists($backup_directory)){ 
		
		echo "Failed to remove old backup files...aborting.<br>";
		echo 'Permissions of '.$backup_directory.': '.substr(sprintf('%o', fileperms($backup_directory)), -4).'<br>';
		exit;
	}		
	
	echo 'Copying '.$live_directory. ' to '. $backup_directory.'<br>';
	echo 'Copying '.$stage_directory. ' to '. $live_directory.'<br>';
	
		
	rename($live_directory, $backup_directory);
	rename($stage_directory, $live_directory);

	if(file_exists($live_directory) && file_exists($backup_directory)){
		echo 'Copied upgrade files.<br>';
		exit;
	}
	else if(!file_exists($live_directory)){
		//FAILED, LETS LOAD FROM BACKUP 
		echo 'Upgrade failed, loading from backup.<br>';
		rename($backup_directory, $live_directory);
	}
	
	
	//DO THE MIGRATION
	$noautorun = 1;  //DO NOT AUTORUN THE update_database include
	require_once('update_database.php');
	$migration_result = update_database($classes, $migrations, $verbose, $upgrade, $cleanup);
	if(!$migration_result){
		echo 'Migration failed...reverting upgrade.<br>';
		rename($live_directory, $failed_directory);
		rename($backup_directory, $live_directory);		
	}
	else{
		echo 'Upgrade complete.<br>';
	}
	
	//UNTAR IT 
	/*
	try {
		$phar = new PharData($targetLocation);
		$phar->extractTo($stage_location); // extract all files
	} catch (Exception $e) {
		print_r($e);
	}	
	*/		



	


?>