<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/upgrades_class.php');
	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;
	
	//IF WE ARE ACTING AS A SERVER, AND SOMEONE REQUESTS THE INFO FOR UPGRADING
	if($_GET['serve-upgrade'] && $settings->get_setting('upgrade_server_active')){
		$response = array();
		$response['system_version'] = $settings->get_setting('system_version');
		$major = new MultiUpgrade(array(), array('major_version' => 'DESC', 'minor_version' => 'DESC'));
		$major->load();
		$upgrade =  $major->get(0);
		$response['system_version'] = $upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version');
		$response['release_notes'] = $upgrade->get('upg_release_notes');
		$response['upgrade_location'] = $settings->get_setting('webDir').'/static_files/'.$upgrade->get('upg_name');
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
	$live_directory_contents = $live_directory.'/*';
	$backup_directory_contents = $backup_directory.'/';
	$stage_directory = $stage_location. 'public_html_stage';
	$stage_directory_contents = $stage_directory.'/*';
	$theme_directory = $full_site_dir.'/theme';
	
	//CHECK ALL FILE Permissions and owners
	if(substr(sprintf('%o', fileperms($stage_location)), -3) != '770'){
		echo $stage_location . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($stage_location))['name'].' and has permissions '.substr(sprintf('%o', fileperms($stage_location)), -3).'<br>';
		exit;
	}
	if(posix_getpwuid(fileowner($stage_location))['name'] != 'www-data'){
		echo $stage_location . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($stage_location))['name'].' and has permissions '.substr(sprintf('%o', fileperms($stage_location)), -3).'<br>';
		exit;		
	}

	if(substr(sprintf('%o', fileperms($live_directory)), -3) != '770'){
		echo $live_directory . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
		exit;
	}
	if(posix_getpwuid(fileowner($live_directory))['name'] != 'www-data'){
		echo $live_directory . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
		exit;		
	}
	
	if(substr(sprintf('%o', fileperms($backup_directory)), -3) != '770'){
		echo $backup_directory . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($backup_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($backup_directory)), -3).'<br>';
		exit;
	}
	if(posix_getpwuid(fileowner($backup_directory))['name'] != 'www-data'){
		echo $backup_directory . ' must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($backup_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($backup_directory)), -3).'<br>';
		exit;		
	}
	
	
	//GET THE UPGRADE FILE
	echo 'Getting: '. $sourceFile.'<br>';


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
	echo 'Clearing staging area: '.$stage_location.'...<br>';
	exec("chmod -R 770 $stage_location");
	exec ("rm -rf $stage_location".'/.git');  //REMOVE LATENT GIT FILES
	exec ("rm -rf $stage_location".'/.gitignore');  //REMOVE LATENT GIT FILES
	if(file_exists($stage_location)){
		exec ("rm -rf $stage_location".'/*');
		if(!is_dir_empty($stage_location)){
			echo 'Failed to clear staging location:'.$stage_location.'...aborting.<br>';
			echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';
			exit;
		}
		else{
			echo 'Staging area cleared<br>';
		}
	}	
	
	//UNZIP THE FILE
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
	else{
		echo "Theme files copied.<br>";
	}

	//RUN THE DEPLOY
	echo 'Clearing backup area: '.$backup_directory.'<br>';
	exec ("rm -rf $backup_directory".'/*');
	exec ("rm -rf $backup_directory".'/.git');  //REMOVE LATENT GIT FILES
	exec ("rm -rf $backup_directory".'/.gitignore');  //REMOVE LATENT GIT FILES
	if(is_dir_empty($backup_directory)){
		echo 'Backup area cleared<br>';
	}
	else{
		
		echo "Failed to remove old backup files...aborting.<br>";
		echo 'Permissions of '.$backup_directory.': '.substr(sprintf('%o', fileperms($backup_directory)), -4).'<br>';
		$x=1;
		$files = scandir($dir);
		foreach($files as $file){
			echo $file.'<br>';
			$x++;
			if($x == 10){
				break;
			}
		}
		exit;
	}
	

	//SET PERMISSIONS FOR NEW FILES 
	echo 'Setting '.$stage_location.' to 770<br>';
	exec("chmod -R 770 $stage_location");
	echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';

	//echo 'Setting '.$stage_location.' to user1<br>';
	//exec ("chown -R user1 $stage_location");
	//echo posix_getpwuid(fileowner($stage_location))['name'];
	//exit;
	echo 'Moving '.$live_directory. ' to '. $backup_directory.'<br>';
	echo 'Moving '.$stage_directory. ' to '. $live_directory.'<br>';

	//chmod($live_directory, 0770);
	//rename($live_directory, $backup_directory);
	//rename($stage_directory, $live_directory);
	exec("mv $live_directory_contents $backup_directory");
	exec("mv $stage_directory_contents $live_directory");
	exec("chmod -R 770 $live_directory");
	exec("chmod -R 770 $backup_directory");

	if(file_exists($live_directory) && file_exists($backup_directory)){
		echo 'Copied upgrade files.<br>';
	}
	else if(!file_exists($live_directory)){
		//FAILED, LETS LOAD FROM BACKUP 
		echo 'Upgrade failed, loading from backup.<br>';
		exec("mv $backup_directory_contents $live_directory");
		exit;
	}
	
	//CLEAR OLD STAGED FILES 
	echo 'Clearing staging area: '.$stage_location.'...<br>';
	exec("chmod -R 770 $stage_location");
	if(file_exists($stage_location)){
		exec ("rm -rf $stage_location".'/*');
		exec ("rm -rf $stage_location".'/.git');  //REMOVE LATENT GIT FILES
		exec ("rm -rf $stage_location".'/.gitignore');  //REMOVE LATENT GIT FILES
		if(!is_dir_empty($stage_location)){
			echo 'Failed to clear staging location:'.$stage_location.'...aborting.<br>';
			echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';
			exit;
		}
		else{
			echo 'Staging area cleared<br>';
		}
	}	
	
	

	//DO THE MIGRATION
	$noautorun = 1;  //DO NOT AUTORUN THE update_database include
	require_once(__DIR__.'/update_database.php');
	$migration_result = update_database($classes, $migrations, $verbose, $upgrade, $cleanup);
	if(!$migration_result){
		echo 'Migration failed...reverting upgrade.<br>';
		exec("mv $backup_directory_contents $live_directory");
	
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
	
	//UPDATE THE SYSTEM VERSION
	$sql = "UPDATE stg_settings set stg_value='".$decode_response['system_version']."' WHERE stg_name='system_version'";
	try{
		$q = $dblink->prepare($sql);
		$q->execute();
		if($verbose){
			echo 'System version now '.$decode_response['system_version']."<br>\n";
		}
		
	}
	catch(PDOException $e){
		echo $e->getMessage();
		echo 'ABORTING MIGRATIONS.  Failed to set system version: '. $decode_response['system_version'] ."<br>\n";
		exit;
	}	

	function is_dir_empty($dir) {
		$numfiles = count(scandir($dir));
		if($numfiles == 0 || $numfiles == 2){
			return true;
		}
		else{
			return false;
		}
	}

	


?>