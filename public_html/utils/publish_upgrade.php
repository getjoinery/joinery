<?php
	// PathHelper, Globalvars, SessionControl are pre-loaded - no need to require them
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/upgrades_class.php'));

	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;
	
	//IF WE ARE ACTING AS A SERVER
	if(!$settings->get_setting('upgrade_server_active')){
		echo 'Setting is turned off.';
		exit;		
	}
	
	$session = SessionControl::get_instance();
	$session->check_permission(8);

	// Increase execution time for large zip file creation (5 minutes)
	set_time_limit(300);

	if(isset($_REQUEST['version_major']) && isset($_REQUEST['version_minor'])){

		$version_major = $_REQUEST['version_major'];
		$version_minor = $_REQUEST['version_minor'];
		$verbose = isset($_GET['verbose']) ? true : false;

		// Check if this version already exists in the database
		$existing = new MultiUpgrade(
			array('major_version' => $version_major, 'minor_version' => $version_minor),
			array(),
			1
		);
		$existing->load();

		if ($existing->count() > 0) {
			echo "Version {$version_major}.{$version_minor} already exists. Please use a different version number.<br>";
			exit;
		}

		$filename = 'current_upgrade'.$version_major.'-'.$version_minor.'.upg.zip';
		
		$file_output_folder = $full_site_dir.'/static_files';
		$file_output_location = $full_site_dir.'/static_files/'.$filename;


		//CHECK ALL FILE Permissions and owners
		$perms = fileperms($file_output_folder);
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
		
			echo $file_output_folder . ' must have user write permission.  Aborting upgrade.<br>';
			echo 'Instead, it is owned by '.posix_getpwuid(fileowner($file_output_folder))['name'].' and has permissions '.substr(sprintf('%o', fileperms($file_output_folder)), -3).'<br>';
			exit;
		}
		
		if(posix_getpwuid(fileowner($file_output_folder))['name'] != 'www-data'){
			echo $file_output_folder . ' must be owned by www-data.  Aborting upgrade.<br>';
			echo 'Instead, it is owned by '.posix_getpwuid(fileowner($file_output_folder))['name'].' and has permissions '.substr(sprintf('%o', fileperms($file_output_folder)), -3).'<br>';
			exit;		
		}		
		 
		

		$file = fopen($file_output_location, 'w') or die("can't open file");
		fclose($file);

		
		$files_list = array();
		$exclude_folder_names = array('.git', '.gitignore');
		$files_list = getDirContents($full_site_dir.'/public_html', $exclude_folder_names);
		
		echo 'Creating zip: '.$file_output_location.'<br>';
		$exclude_filenames = array('.git', '.gitignore');
		$remove_relative_path = 'var/www/html/'.$site_template.'/';
		$zip_result = create_zip($files_list, $file_output_location, $exclude_filenames, $remove_relative_path, true, $verbose);

		if(!$zip_result) {
			// Clean up failed file
			if(file_exists($file_output_location)) {
				@unlink($file_output_location);
			}
			echo "Failed to create zip file<br>";
			exit;
		}

		if(!file_exists($file_output_location) || filesize($file_output_location) == 0){
			// Clean up empty/partial file
			if(file_exists($file_output_location)) {
				@unlink($file_output_location);
			}
			echo "Failed to write the zip file: $file_output_location...aborting.<br>";
			exit;
		}
		
		//STORE THE INFO IN THE DATABASE
		$upgrade = new Upgrade(NULL);
		$upgrade->set('upg_major_version', $version_major);
		$upgrade->set('upg_minor_version', $version_minor);
		$upgrade->set('upg_name', $filename);
		$upgrade->set('upg_release_notes', $_REQUEST['release_notes']);
		$upgrade->prepare();
		$upgrade->save();
		
		
		//GET THE UPGRADE FILE
		echo 'Exported: '. $file_output_location.'<br>';

	}
	else{
		$breadcrumbs = array();


		$page = new AdminPage();
		$page->admin_header(
		array(
			'menu-id'=> 'settings',
			'page_title' => 'Publish Upgrade',
			'readable_title' => 'Publish Upgrade',
			'breadcrumbs' => $breadcrumbs,
			'session' => $session,
		)
		);
		
		$pageoptions['title'] = "Publish Upgrade";
		$page->begin_box($pageoptions);
		
		echo '<h4>Upgrade History</h4>';
		
		$upgrades = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 10, 0);
		$upgrades->load();
		foreach ($upgrades as $upgrade){
			$version_string = 'Version '.$upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version'). ' - '. LibraryFunctions::convert_time($upgrade->get('upg_create_time'), 'UTC', $session->get_timezone()) . ' - '. substr($upgrade->get('upg_release_notes'), 0, 30);

			// Check if zip file exists
			$zip_filename = $upgrade->get('upg_name');
			$zip_path = $full_site_dir.'/static_files/'.$zip_filename;
			if (!file_exists($zip_path)) {
				$version_string .= ' <span style="color: red; font-weight: bold;">[ZIP FILE MISSING]</span>';
			}

			echo $version_string.'<br />';
		}
		echo '<br><br>';


		// Get FormWriter using theme-aware pattern
		$formwriter = $page->getFormWriter('form1');	
		
		
		echo $formwriter->begin_form('form1', 'POST', '/utils/publish_upgrade');

		
		$major = new MultiUpgrade(array(), array('major_version' => 'DESC'));
		$major->load();
		$count = $major->count_all();
		if($count){
			$major_temp =  $major->get(0);
			$major_version = $major_temp->get('upg_major_version');
		}
		else{
			$major_version = 0;
		}

		$minor = new MultiUpgrade(array(), array('minor_version' => 'DESC'));
		$minor->load();
		$count = $minor->count_all();
		if($count){
			$minor_temp =  $minor->get(0);
			$minor_version = $minor_temp->get('upg_minor_version') + 1;
		}
		else{
			$minor_version = 0;
		}

		
		echo $formwriter->textinput('Major Version', 'version_major', NULL, 100, $major_version, '', 255, '');
		echo $formwriter->textinput('Minor Version', 'version_minor', NULL, 100, $minor_version, '', 255, '');
		echo $formwriter->textbox('Release notes', 'release_notes', 'ctrlHolder', 10, 80, NULL, '', 'no');
		
	 
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo $formwriter->end_form();

		// Add JavaScript to disable submit button after click to prevent double submission
		echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var form = document.getElementById("form1");
			if (form) {
				form.addEventListener("submit", function(e) {
					var submitButton = form.querySelector("button[type=\'submit\'], input[type=\'submit\']");
					if (submitButton && !submitButton.disabled) {
						submitButton.disabled = true;
						submitButton.style.opacity = "0.6";
						submitButton.style.cursor = "not-allowed";
						var originalText = submitButton.textContent || submitButton.value;
						if (submitButton.textContent !== undefined) {
							submitButton.textContent = "Publishing...";
						} else {
							submitButton.value = "Publishing...";
						}
					}
				});
			}
		});
		</script>';

		$page->end_box();

		$page->admin_footer();		
		
		
	}
	
	function getDirContents($dir, $exclude_folder_names = array(), &$results = array()) {
		$files = scandir($dir);

		foreach ($files as $key => $value) {
			$path = realpath($dir . DIRECTORY_SEPARATOR . $value);
			if (!is_dir($path)) {
				$results[] = $path;
			} 
			else if ($value != "." && $value != "..") {
				if(!in_array(basename($path), $exclude_folder_names)){
					getDirContents($path, $exclude_folder_names, $results);
					//$results[] = $path;
				}
			}
		}

		return $results;
	}
	
	function create_zip($files = array(),$destination = '', $exclude_filenames = array(), $remove_relative_path = '', $overwrite = false, $verbose = false) {
		//if the zip file already exists and overwrite is false, return false
		if(file_exists($destination) && !$overwrite) {
			echo 'File already exists: '.$destination;
			return false;
		}

		$numfiles = 0;

		//if we have good files...
		if(count($files)) {
			//create the archive
			$zip = new ZipArchive();
			if($zip->open($destination,$overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
				echo 'Failed to create zip file: '.$destination;
				return false;
			}
			//add the files
			foreach($files as $file) {
				$numfiles++;
				if($numfiles % 500 == 0){
					if($verbose) {
						echo 'Writing to zip file...<br>';
					}
					//HANDLE THE MAX LIMIT OF FILES FOR ZIPARCHIVE
					if(!$zip->close()){
						echo 'Zip file failed to close.';
						return false;
					}

					if($zip->open($destination, ZIPARCHIVE::CREATE) !== true) {
						echo 'Failed to create zip file: '.$destination;
						return false;
					}
				}
				//SKIP EXCLUDED FILES
				if(in_array(basename($file), $exclude_filenames)){
					if($verbose) {
						echo 'Excluded file: '.$file.'<br>';
					}
					continue;
				}
				else if (is_dir($file)){
					if($verbose) {
						echo 'Excluded directory: '.$file.'<br>';
					}
					continue;
				}
				else if(!file_exists($file) || !is_readable($file)){
					if($verbose) {
						echo 'Excluded nonexistent or unreadable file: '.$file.'<br>';
					}
					continue;
				}
				else{
					if($verbose) {
						echo $numfiles.' Adding file: '.$file.'<br>';
					} else {
						echo '.';
						// Add line break and flush every 100 files
						if($numfiles % 100 == 0) {
							echo '<br>';
							flush();
						}
					}
					$zip->addFile(realpath($file),ltrim(str_replace($remove_relative_path, '', $file), '/'));

				}
			}

			//debug
			if($verbose) {
				echo 'The zip archive contains ',$zip->numFiles,' files with a status of ',$zip->getStatusString().'<br>';
			} else {
				echo '<br>The zip archive contains ',$zip->numFiles,' files with a status of ',$zip->getStatusString().'<br>';
			}
			
			//close the zip -- done!

			if($zip->close()){
				return true;
			}
			else{
				echo 'Zip file failed to close.';
				return false;
			}

			//check to make sure the file exists
			if(file_exists($destination)){
				return true;
			}
			else{
				echo 'Zip file failed to save.';
				return false;
			}
		}
		else
		{
				echo 'There are no valid files for the zip file.';
				return false;
		}
	}	


?>