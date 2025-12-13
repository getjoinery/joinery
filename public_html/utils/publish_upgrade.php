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

		// Generate fresh install SQL file before creating archive
		// Use form-provided version consistently for both archive and SQL filenames
		$version = $version_major . '.' . $version_minor;
		echo "Generating install SQL file (version $version)...<br>";
		flush();

		$create_sql_cmd = sprintf(
			'php %s %s',
			escapeshellarg($full_site_dir . '/public_html/utils/create_install_sql.php'),
			escapeshellarg($version)
		);

		$output = [];
		$exit_code = 0;
		exec($create_sql_cmd, $output, $exit_code);

		if ($exit_code !== 0) {
			die("ERROR: Failed to generate install SQL file:\n" . implode("\n", $output) . "\n");
		}

		// The generated file is in uploads with version number
		$sql_source = $full_site_dir . '/uploads/joinery-install-' . $version . '.sql.gz';

		if (!file_exists($sql_source)) {
			die("ERROR: Generated SQL file not found at $sql_source\n");
		}

		echo "Generated install SQL file version $version (compressed)<br>";
		flush();

		$filename = 'joinery-'.$version_major.'-'.$version_minor.'.tar.gz';

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
		 
		

		// Check that required maintenance script files exist
		$required_files = [
			'/var/www/html/joinerytest/maintenance_scripts/server_setup.sh',
			'/var/www/html/joinerytest/maintenance_scripts/deploy.sh',
			$sql_source
		];

		foreach ($required_files as $file) {
			if (!file_exists($file)) {
				die("ERROR: Required file $file not found. Cannot create archive.\n");
			}
		}

		echo "All required files present<br>";
		flush();

		// Create temporary directory for archive staging
		$temp_dir = sys_get_temp_dir() . '/joinery_archive_' . uniqid();
		if (!mkdir($temp_dir, 0755, true)) {
			die("ERROR: Failed to create temporary directory: $temp_dir\n");
		}

		echo "Creating archive structure in temporary directory...<br>";
		flush();

		// Create directory structure
		mkdir($temp_dir . '/public_html', 0755, true);
		mkdir($temp_dir . '/config', 0755, true);
		mkdir($temp_dir . '/maintenance_scripts', 0755, true);

		// Copy public_html files using rsync
		$rsync_cmd = sprintf(
			'rsync -av --exclude=.git --exclude=.gitignore %s %s 2>&1',
			escapeshellarg($full_site_dir . '/public_html/'),
			escapeshellarg($temp_dir . '/public_html/')
		);
		echo "Copying public_html files...<br>";
		flush();
		exec($rsync_cmd, $output, $exit_code);
		if ($exit_code !== 0) {
			// Clean up temp directory
			exec('rm -rf ' . escapeshellarg($temp_dir));
			die("ERROR: Failed to copy public_html files:\n" . implode("\n", $output) . "\n");
		}

		// Copy config file
		$config_source = '/var/www/html/joinerytest/maintenance_scripts/Globalvars_site_default.php';
		if (file_exists($config_source)) {
			copy($config_source, $temp_dir . '/config/Globalvars_site_default.php');
			echo "Copied config template<br>";
			flush();
		}

		// Copy maintenance scripts
		$maintenance_files = [
			'server_setup.sh',
			'deploy.sh',
			'backup_database.sh',
			'restore_database.sh',
			'restore_project.sh',
			'copy_database.sh',
			'new_account.sh',
			'remove_account.sh',
			'fix_permissions.sh',
			'fix_postgres_auth.sh',
			'Globalvars_site_default.php',
			'default_virtualhost.conf'
		];

		$maintenance_dir = '/var/www/html/joinerytest/maintenance_scripts/';
		foreach ($maintenance_files as $file) {
			if (file_exists($maintenance_dir . $file)) {
				copy($maintenance_dir . $file, $temp_dir . '/maintenance_scripts/' . $file);
				if ($verbose) {
					echo "Copied maintenance script: $file<br>";
				}
			}
		}
		echo "Copied " . count($maintenance_files) . " maintenance script files<br>";
		flush();

		// Copy install SQL file with simplified name
		copy($sql_source, $temp_dir . '/maintenance_scripts/joinery-install.sql.gz');
		echo "Added install SQL file<br>";
		flush();

		// Create tar.gz archive
		echo "Creating tar.gz archive: $file_output_location<br>";
		flush();

		$tar_cmd = sprintf(
			'tar -czf %s -C %s . 2>&1',
			escapeshellarg($file_output_location),
			escapeshellarg($temp_dir)
		);
		exec($tar_cmd, $output, $exit_code);

		// Clean up temp directory
		exec('rm -rf ' . escapeshellarg($temp_dir));

		if ($exit_code !== 0) {
			// Clean up failed file
			if(file_exists($file_output_location)) {
				@unlink($file_output_location);
			}
			die("ERROR: Failed to create tar.gz archive:\n" . implode("\n", $output) . "\n");
		}

		if(!file_exists($file_output_location) || filesize($file_output_location) == 0){
			// Clean up empty/partial file
			if(file_exists($file_output_location)) {
				@unlink($file_output_location);
			}
			echo "Failed to write the archive file: $file_output_location...aborting.<br>";
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
		$filesize_mb = round(filesize($file_output_location) / 1048576, 2);
		echo '<br><strong>Archive created successfully!</strong><br>';
		echo 'Location: '. $file_output_location.'<br>';
		echo 'Size: ' . $filesize_mb . ' MB<br>';
		echo 'Format: tar.gz<br>';
		echo '<br>Archive contents include:<br>';
		echo '- public_html/ (all application files)<br>';
		echo '- config/ (default configuration template)<br>';
		echo '- maintenance_scripts/ (deployment and setup scripts)<br>';
		echo '- maintenance_scripts/joinery-install.sql.gz (fresh install database)<br>';

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

			// Check if archive file exists (supports both old .zip and new .tar.gz)
			$archive_filename = $upgrade->get('upg_name');
			$archive_path = $full_site_dir.'/static_files/'.$archive_filename;
			if (!file_exists($archive_path)) {
				$version_string .= ' <span style="color: red; font-weight: bold;">[ARCHIVE FILE MISSING]</span>';
			} else {
				// Show file format
				if (strpos($archive_filename, '.tar.gz') !== false) {
					$version_string .= ' <span style="color: green;">[tar.gz]</span>';
				} else if (strpos($archive_filename, '.zip') !== false) {
					$version_string .= ' <span style="color: blue;">[zip - legacy]</span>';
				}
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


		echo $formwriter->textinput('version_major', 'Major Version', [
			'value' => $major_version,
			'validation' => ['required' => true]
		]);
		echo $formwriter->textinput('version_minor', 'Minor Version', [
			'value' => $minor_version,
			'validation' => ['required' => true]
		]);
		echo $formwriter->textbox('release_notes', 'Release notes', [
			'validation' => ['required' => true]
		]);


		echo $formwriter->submitbutton('submit_button', 'Publish Upgrade');

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