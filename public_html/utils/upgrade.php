<?php
	require_once( __DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Globalvars.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	
	
	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;
	
	if($baseDir == '' || !$baseDir){
		echo '$baseDir is empty.  Aborting upgrade.<br>';
		exit;
	}
	
	if($site_template == '' || !$site_template){
		echo '$site_template is empty.  Aborting upgrade.<br>';
		exit;
	}
	
	
	$stage_location = $full_site_dir.'/uploads/upgrades/';
	$live_directory = $full_site_dir. '/public_html';
	$backup_directory = $full_site_dir. '/public_html_last';
	$live_directory_contents = $live_directory.'/*';
	$backup_directory_contents = $backup_directory.'/';
	$stage_directory = $stage_location. 'public_html';
	$stage_directory_contents = $stage_directory.'/*';
	$theme_directory = $full_site_dir.'/theme';
	$theme_directory_contents = $theme_directory.'/*';
	$location_of_themes = $stage_directory.'/theme';
	$live_themes = $live_directory.'/theme';

	$plugin_directory = $full_site_dir.'/plugins';
	$plugin_directory_contents = $plugin_directory.'/*';
	$location_of_plugins = $stage_directory.'/plugins';
	$live_plugins = $live_directory.'/plugins';
	





	//THIS SECTION RELOADS THEMES AND PLUGINS ONLY
	if(isset($_GET['theme-only']) && $_GET['theme-only']){
		
		function is_writable_by_www_data($directory) {
			// Get file permissions
			$perms = fileperms($directory);
			
			// Get file owner information
			$ownerInfo = posix_getpwuid(fileowner($directory));
			$ownerName = $ownerInfo['name'];

			// Get group permissions (for `www-data` group access)
			$group_read = ($perms & 0x0020) ? true : false;
			$group_write = ($perms & 0x0010) ? true : false;
			
			// Check if owner is `www-data`
			$isOwnedByWwwData = ($ownerName === 'www-data');

			// Check if `www-data` has write permissions (owner OR group)
			$isWritableByWwwData = ($isOwnedByWwwData && ($perms & 0x0080)) || $group_write;

			// Return result as a boolean
			return $isWritableByWwwData;
		}


		if (!is_writable_by_www_data($theme_directory)) {
			echo "$theme_directory must be writable by www-data. Aborting upgrade.<br>";
			echo "Instead, it is owned by " . posix_getpwuid(fileowner($theme_directory))['name'] . 
				 " and has permissions " . substr(sprintf('%o', fileperms($theme_directory)), -3) . "<br>";
			exit;
		} 
	
		if (!is_writable_by_www_data($plugin_directory)) {
			echo "$plugin_directory must be writable by www-data. Aborting upgrade.<br>";
			echo "Instead, it is owned by " . posix_getpwuid(fileowner($plugin_directory))['name'] . 
				 " and has permissions " . substr(sprintf('%o', fileperms($plugin_directory)), -3) . "<br>";
			exit;
		} 		
		
		
		
		//REMOVE OLD THEMES
		//exec ("rm -rf $live_themes".'/*');
		
		//COPY THE THEME FILES 
		exec("cp -r $theme_directory_contents $live_themes");
		if(!file_exists($live_themes)){
			echo "Failed to move theme files ($theme_directory to $live_themes...aborting.<br>";
			
			if(substr(sprintf('%o', fileperms($live_themes)), -3) != '770' || substr(sprintf('%o', fileperms($live_themes)), -3) != '777'){
				echo $live_themes . ' (live_themes) must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
				echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_themes))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_themes)), -3).'<br>';
				exit;
			}
			if(posix_getpwuid(fileowner($live_themes))['name'] != 'www-data'){
				echo $live_themes . ' (live_themes) must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
				echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_themes))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_themes)), -3).'<br>';
				exit;		
			}
			exit;
		}
		else{
			echo "Theme files copied from $theme_directory to $live_themes.<br>";
		}


		//REMOVE OLD PLUGINS
		//exec ("rm -rf $live_plugins".'/*');
		//COPY THE PLUGIN FILES 
		if(file_exists($plugin_directory)){
			exec("cp -r $plugin_directory_contents $live_plugins");
			if(!file_exists($live_plugins)){
				echo "Failed to move plugin files ($plugin_directory to $live_plugins...aborting.<br>";
				
				if(substr(sprintf('%o', fileperms($live_plugins)), -3) != '770' || substr(sprintf('%o', fileperms($live_plugins)), -3) != '777'){
					echo $live_plugins . ' (live_plugins) must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
					echo 'Instead, it has permissions '.substr(sprintf('%o', fileperms($live_plugins)), -3).'<br>';
					exit;
				}
				if(posix_getpwuid(fileowner($live_plugins))['name'] != 'www-data'){
					echo $live_plugins . ' (live_plugins) must be owned by www-data and have permissions of 770.  Aborting upgrade.<br>';
					echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_plugins))['name'].'<br>';
					exit;		
				}
				exit;
			}
			else{
				echo "Plugin files copied from $plugin_directory to $live_plugins.<br>";
			}
		}
		else{
			echo "Plugin files are empty and nothing was copied.<br>";
		}

		exit;
	}
	
	//IF WE ARE ACTING AS A SERVER, AND SOMEONE REQUESTS THE INFO FOR UPGRADING
	if($_GET['serve-upgrade'] && $settings->get_setting('upgrade_server_active')){
		PathHelper::requireOnce('/data/upgrades_class.php');
		$response = array();
		$response['system_version'] = $settings->get_setting('system_version');
		$major = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'));
		$major->load();
		$upgrade =  $major->get(0);
		$response['system_version'] = $upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version');
		$response['upgrade_name'] = $upgrade->get('upg_name');
		$response['release_date'] = $upgrade->get('upg_create_time');
		$response['release_notes'] = $upgrade->get('upg_release_notes');
		$response['upgrade_location'] = LibraryFunctions::get_absolute_url('/static_files/'.$upgrade->get('upg_name'));
		header("Content-Type: application/json");
		http_response_code(400);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;		
	}
	
	
	//CHECK FOR EXISTENCE OF ALL NEEDED DIRECTORIES 
	if(!file_exists($live_directory)){
		echo $live_directory. ' (live_directory) does not exist or is not readable by www-data.';
		exit;
	}
	if(!file_exists($theme_directory)){
		echo $theme_directory. ' (theme_directory) does not exist or is not readable by www-data.';
		exit;
	}
	if (is_dir_empty($theme_directory)) {
		echo $theme_directory. ' (theme_directory) is empty.';
		exit;
	}
	
	/*
	if(!file_exists($plugin_directory)){
		echo $plugin_directory. ' (plugin_directory) does not exist or is not readable by www-data.';
		exit;
	}
	if (is_dir_empty($plugin_directory)) {
		echo $plugin_directory. ' (plugin_directory) is empty.';
		exit;
	}	
	*/
	
	/*
	$perms = fileperms($stage_location);

	// Owner
	$info .= (($perms & 0x0100) ? 'r' : '-');
	$info .= (($perms & 0x0080) ? 'w' : '-');
	$info .= (($perms & 0x0040) ?
				(($perms & 0x0800) ? 's' : 'x' ) :
				(($perms & 0x0800) ? 'S' : '-'));

	// Group
	$info .= (($perms & 0x0020) ? 'r' : '-');
	$info .= (($perms & 0x0010) ? 'w' : '-');
	$info .= (($perms & 0x0008) ?
				(($perms & 0x0400) ? 's' : 'x' ) :
				(($perms & 0x0400) ? 'S' : '-'));

	// World
	$info .= (($perms & 0x0004) ? 'r' : '-');
	$info .= (($perms & 0x0002) ? 'w' : '-');
	$info .= (($perms & 0x0001) ?
				(($perms & 0x0200) ? 't' : 'x' ) :
				(($perms & 0x0200) ? 'T' : '-'));

	echo $info;
	*/


		
		$perms = fileperms($live_directory);
	$user_read = (($perms & 0x0100) ? 'r' : '-');
	$user_write = (($perms & 0x0080) ? 'w' : '-');
	$user_ex = (($perms & 0x0040) ?
				(($perms & 0x0800) ? 's' : 'x' ) :
				(($perms & 0x0800) ? 'S' : '-'));

	// Group
	$group_read = (($perms & 0x0020) ? 'r' : '-');
	$group_write = (($perms & 0x0010) ? 'w' : '-');
	$group_ex = (($perms & 0x0008) ?
				(($perms & 0x0400) ? 's' : 'x' ) :
				(($perms & 0x0400) ? 'S' : '-'));

	// World
	$world_read = (($perms & 0x0004) ? 'r' : '-');
	$world_write = (($perms & 0x0002) ? 'w' : '-');
	$world_ex = (($perms & 0x0001) ?
				(($perms & 0x0200) ? 't' : 'x' ) :
				(($perms & 0x0200) ? 'T' : '-'));
	if(!($user_read && $user_write)){
		echo $live_directory . ' (live_directory)must be writable by user1.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
		exit;
	}
	
	
	if(posix_getpwuid(fileowner($live_directory))['name'] != 'www-data'){
		echo $live_directory . ' (live_directory) must be owned by www-data.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
		exit;		
	}	
	
	

	
	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();	


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
	$sourceFile = $decode_response['upgrade_location'];
		
	
	if ($_POST && $_POST['confirm']){
	
		if($decode_response['system_version']){
			if($settings->get_setting('system_version') > $decode_response['system_version']){
				echo 'Your system is up to date.  No upgrade needed.';
				exit;
			}
			else{
				echo 'Upgrade available: '. $decode_response['system_version'] . '<br>';
				echo 'Current local version: '.$settings->get_setting('system_version').'<br>';
			}
			
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
		$file_download_location = $full_site_dir.'/uploads/'.basename($sourceFile);//$full_site_dir.'/uploads/current_upgrade.upg.zip';
		
		
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
		echo 'Clearing staging area: '.$stage_location.'<br>';
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
		  echo 'Upgrade at '.$file_download_location. ' unzipped to '.$stage_location.'<br>';
		} 
		else {
		  echo 'Unable to unzip upgrade from '.$file_download_location.' <br>';
		  exit;
		}			
		

		//TODO: DO BACKUPS

		
		//COPY THE THEME FILES 
		//$location_of_themes = $stage_directory.'/theme';
		//print_r($location_of_themes);
		//echo 'copying '. $theme_directory. ' to ' .$stage_directory ;
		
		//CHECK PERMISSION OF DESTINATION THEME FOLDER
		/*
		$perms = fileperms($live_directory);
		$user_read = (($perms & 0x0100) ? 'r' : '-');
		$user_write = (($perms & 0x0080) ? 'w' : '-');
		$user_ex = (($perms & 0x0040) ?
					(($perms & 0x0800) ? 's' : 'x' ) :
					(($perms & 0x0800) ? 'S' : '-'));

		// Group
		$group_read = (($perms & 0x0020) ? 'r' : '-');
		$group_write = (($perms & 0x0010) ? 'w' : '-');
		$group_ex = (($perms & 0x0008) ?
					(($perms & 0x0400) ? 's' : 'x' ) :
					(($perms & 0x0400) ? 'S' : '-'));

		// World
		$world_read = (($perms & 0x0004) ? 'r' : '-');
		$world_write = (($perms & 0x0002) ? 'w' : '-');
		$world_ex = (($perms & 0x0001) ?
					(($perms & 0x0200) ? 't' : 'x' ) :
					(($perms & 0x0200) ? 'T' : '-'));
		if(!($user_read && $user_write && $group_read && $group_write && $world_read && $world_write)){
			echo $live_directory . ' (theme_directory) must be writable by all (777).  Aborting upgrade.<br>';
			echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
			exit;
		}
		*/
		//echo 'Creating directory: '.$location_of_themes."\n<br>";
		//mkdir($location_of_themes, 0777);
		//exec("mkdir $location_of_themes");

		if(!file_exists($location_of_themes)){
			echo 'Directory does not exist: '.$location_of_themes. "\n<br>";
			echo "Failed to move theme files ($theme_directory to $location_of_themes...aborting.<br>";
			exit;
		}


		exec("cp -r $theme_directory $stage_directory");
		if (is_dir_empty($location_of_themes)) {
			echo $location_of_themes. ' (theme_directory) failed to copy.';
			exit;
		}
		else{
			echo "Theme files copied from $theme_directory to $stage_directory.<br>";
		}



		if(file_exists($location_of_plugins)){
			exec("cp -r $plugin_directory $stage_directory");
			if (is_dir_empty($location_of_plugins)) {
				echo $location_of_plugins. ' (plugin_directory) failed to copy.';
				exit;
			}
			else{
				echo "Theme files copied from $plugin_directory to $stage_directory.<br>";
			}
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
		require_once('update_database.php');
		$migration_result = update_database($migrations, $verbose, $upgrade, $cleanup);
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
	}
	else{
		
		$session = SessionControl::get_instance();
		//$session->set_return("/admin/admin_users");

		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 'users-list',
			'page_title' => 'Upgrade',
			'readable_title' => 'Upgrade',
			'breadcrumbs' => array(
				'Settings'=>'/admin/admin_settings', 
				'Upgrade' => '',
			),
			'session' => $session,
		)
		);

		
		$pageoptions['title'] = 'System Upgrades';
		$page->begin_box($pageoptions);


		$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
		echo $formwriter->begin_form("form", "post", "/utils/upgrade");

		echo 'Local system Version: '.$settings->get_setting('system_version').'<br>';
		echo 'Database Version: '.$settings->get_setting('database_version').'<br>';


		echo '<fieldset><h4>Confirm Upgrade</h4>';
			echo '<div class="fields full">';
			echo '<p><b>Checking upgrade source: '.$settings->get_setting('upgrade_source').'</b></p>';
			if(!$decode_response['system_version']){
				echo '<p>Unable to get the latest upgrade.  Response:</p>';
				print_r($response);
			}
			else if($decode_response['system_version'] > $settings->get_setting('system_version')){
				echo '<p>Latest upgrade available: '. $decode_response['system_version'] . '('.$decode_response['upgrade_name'].') released on '. $decode_response['release_date'] .' - '.$decode_response['release_notes'].' </p>';
				echo $formwriter->hiddeninput("confirm", 1);

				echo $formwriter->start_buttons();
				echo $formwriter->new_form_button('Submit');
				echo $formwriter->end_buttons();	

			}
			else if($decode_response['system_version'] == $settings->get_setting('system_version')){
				echo '<p>Latest upgrade available: '. $decode_response['system_version'] . '('.$decode_response['upgrade_name'].') released on '. $decode_response['release_date'] .' - '.$decode_response['release_notes'].' </p>';
				echo 'Your version '. $settings->get_setting('system_version'). ' is up to date.  No upgrade needed.  ';
				echo $formwriter->hiddeninput("confirm", 1);

				echo $formwriter->start_buttons();
				echo $formwriter->new_form_button('Upgrade anyway');
				echo $formwriter->end_buttons();	

			}
			else{
				echo 'Your version '. $decode_response['system_version']. ' is up to date.  ';
			}



			echo '</div>';
		echo '</fieldset>';
		echo $formwriter->end_form();

		$page->end_box();

		$page->admin_footer();


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