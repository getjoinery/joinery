<?php
	$is_cli = php_sapi_name() === 'cli';

	if ($is_cli) {
		// CLI bootstrap: load core dependencies manually (session/AdminPage not available)
		require_once(__DIR__ . '/../includes/PathHelper.php');
		require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
		require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
	} else {
		// Web: PathHelper, Globalvars, SessionControl are pre-loaded
		require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		require_once(PathHelper::getIncludePath('data/upgrades_class.php'));
	}

	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;

	//IF WE ARE ACTING AS A SERVER
	if(!$settings->get_setting('upgrade_server_active')){
		echo 'Setting is turned off.';
		exit;
	}

	// =====================================================
	// CLI MODE: parse arguments and populate $_REQUEST
	// Usage: php publish_upgrade.php [major.minor] ["release notes"]
	// If version omitted, auto-detects next minor version.
	// =====================================================
	if ($is_cli) {
		$cli_args = array_slice($argv, 1);

		// Parse version argument (e.g. "3.27" or "3" "27")
		$cli_major = null;
		$cli_minor = null;
		$cli_notes = '';

		if (!empty($cli_args[0]) && strpos($cli_args[0], '.') !== false) {
			[$cli_major, $cli_minor] = explode('.', $cli_args[0], 2);
			$cli_notes = $cli_args[1] ?? 'CLI publish';
		} elseif (!empty($cli_args[0]) && is_numeric($cli_args[0])) {
			$cli_major = $cli_args[0];
			$cli_minor = $cli_args[1] ?? null;
			$cli_notes = $cli_args[2] ?? 'CLI publish';
		} else {
			$cli_notes = $cli_args[0] ?? 'CLI publish';
		}

		// Auto-detect next version if not specified
		if ($cli_major === null || $cli_minor === null) {
			$latest = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 1);
			$latest->load();
			if ($latest->count() > 0) {
				$last = $latest->get(0);
				$cli_major = $cli_major ?? $last->get('upg_major_version');
				$cli_minor = $cli_minor ?? ($last->get('upg_minor_version') + 1);
			} else {
				$cli_major = $cli_major ?? 1;
				$cli_minor = $cli_minor ?? 0;
			}
		}

		$_REQUEST['version_major'] = $cli_major;
		$_REQUEST['version_minor'] = $cli_minor;
		$_REQUEST['release_notes'] = $cli_notes;
	}

	// =====================================================
	// REMOTE ARCHIVE REFRESH HANDLER
	// =====================================================
	// Handle remote archive refresh requests (before session check - uses IP auth)
	if (!$is_cli && isset($_GET['refresh-archives']) && $_GET['refresh-archives'] == '1') {
		header('Content-Type: application/json');

		// Check if feature is enabled (accepts '1' or 'true' as enabled)
		$allow_refresh = $settings->get_setting('allow_remote_archive_refresh');
		if ($allow_refresh !== '1' && $allow_refresh !== 'true') {
			http_response_code(403);
			echo json_encode([
				'success' => false,
				'error' => 'Remote archive refresh is not enabled on this server'
			]);
			exit;
		}

		// Check IP whitelist
		$allowed_ips = json_decode($settings->get_setting('archive_refresh_allowed_ips') ?: '[]', true);
		// Get real client IP (check Cloudflare and proxy headers first)
		$client_ip = $_SERVER['HTTP_CF_CONNECTING_IP']
			?? $_SERVER['HTTP_X_FORWARDED_FOR']
			?? $_SERVER['REMOTE_ADDR'];
		// X-Forwarded-For may contain multiple IPs - use the first one
		if (strpos($client_ip, ',') !== false) {
			$client_ip = trim(explode(',', $client_ip)[0]);
		}

		if (!is_ip_in_list($client_ip, $allowed_ips)) {
			http_response_code(403);
			echo json_encode([
				'success' => false,
				'error' => 'IP address not authorized for archive refresh (your IP: ' . $client_ip . ')'
			]);
			exit;
		}

		// All checks passed - regenerate archives using existing publish logic
		try {
			$result = regenerate_current_archives($full_site_dir, $settings);

			if ($result['success']) {
				echo json_encode([
					'success' => true,
					'message' => 'Archives refreshed successfully',
					'version' => $result['version'],
					'timestamp' => date('c')
				]);
			} else {
				http_response_code(500);
				echo json_encode([
					'success' => false,
					'error' => 'Archive refresh failed',
					'details' => $result['error']
				]);
			}
		} catch (Exception $e) {
			http_response_code(500);
			echo json_encode([
				'success' => false,
				'error' => 'Archive refresh failed',
				'details' => $e->getMessage()
			]);
		}
		exit;
	}

	if ($is_cli) {
		$session = null;
	} else {
		$session = SessionControl::get_instance();
		$session->check_permission(8);
	}

	// Increase execution time for large zip file creation (5 minutes)
	set_time_limit(300);

	// Output helper: strips HTML for CLI, flushes for web
	function publish_output($text) {
		global $is_cli;
		if ($is_cli) {
			echo strip_tags(str_replace(['<br>', '<br />', '<br/>'], "\n", $text)) . "\n";
		} else {
			echo $text;
			flush();
		}
	}

	// Handle delete request - process before rendering page
	if(isset($_REQUEST['delete']) && is_numeric($_REQUEST['delete'])){
		$delete_id = intval($_REQUEST['delete']);
		$upgrade_to_delete = new Upgrade($delete_id, TRUE);

		if($upgrade_to_delete->key){
			// Get the archive filename before deleting
			$archive_filename = $upgrade_to_delete->get('upg_name');
			$archive_path = $full_site_dir.'/static_files/'.$archive_filename;

			// Delete the archive file if it exists
			if(file_exists($archive_path)){
				unlink($archive_path);
			}

			// Delete the database record using permanent_delete
			$version_string = $upgrade_to_delete->get('upg_major_version').'.'.$upgrade_to_delete->get('upg_minor_version');
			$upgrade_to_delete->permanent_delete();

			// Store success message in session and redirect to clean URL
			$page_regex = '/\/utils\/publish_upgrade/';
			$session->save_message(new DisplayMessage(
				'Upgrade version ' . $version_string . ' has been deleted.',
				'Success',
				$page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
			header('Location: /utils/publish_upgrade');
			exit;
		}
	}

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
			publish_output("Version {$version_major}.{$version_minor} already exists. Please use a different version number.");
			exit;
		}

		// Generate fresh install SQL file before creating archive
		// Use form-provided version consistently for both archive and SQL filenames
		$version = $version_major . '.' . $version_minor;
		publish_output("Generating install SQL file (version $version)...");

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

		publish_output("Generated install SQL file version $version (compressed)");

		$file_output_folder = $full_site_dir.'/static_files';

		// Check if directory is writable by current process
		if(!is_writable($file_output_folder)){
			publish_output($file_output_folder . ' must be writable.  Aborting upgrade.');
			publish_output('It is owned by '.posix_getpwuid(fileowner($file_output_folder))['name'].' and has permissions '.substr(sprintf('%o', fileperms($file_output_folder)), -3));
			exit;
		}

		// Check that required directories and files exist
		$maintenance_dir = $full_site_dir . '/maintenance_scripts/';
		$required_dirs = [
			$maintenance_dir . 'install_tools',
			$maintenance_dir . 'sysadmin_tools',
		];

		foreach ($required_dirs as $dir) {
			if (!is_dir($dir)) {
				die("ERROR: Required directory $dir not found. Cannot create archive.\n");
			}
		}

		if (!file_exists($sql_source)) {
			die("ERROR: Required file $sql_source not found. Cannot create archive.\n");
		}

		publish_output("All required directories and files present");

		// Also update the on-disk copy for Docker builds that copy directly from disk
		$ondisk_sql_path = $full_site_dir . '/maintenance_scripts/install_tools/joinery-install.sql.gz';
		if (copy($sql_source, $ondisk_sql_path)) {
			publish_output("Updated on-disk install SQL at $ondisk_sql_path");
		} else {
			publish_output("Warning: Could not update on-disk SQL at $ondisk_sql_path");
		}

		// =====================================================
		// Create CORE archive (no themes or plugins)
		// =====================================================
		publish_output("Creating core archive...");

		$core_filename = 'joinery-core-' . $version_major . '.' . $version_minor . '.tar.gz';
		$core_output_location = $file_output_folder . '/' . $core_filename;

		// Create temporary directory for core archive staging
		$core_temp_dir = sys_get_temp_dir() . '/joinery_core_' . uniqid();
		if (!mkdir($core_temp_dir, 0755, true)) {
			die("ERROR: Failed to create core temp directory<br>");
		}

		// Create directory structure
		mkdir($core_temp_dir . '/public_html', 0755, true);
		mkdir($core_temp_dir . '/config', 0755, true);
		mkdir($core_temp_dir . '/maintenance_scripts', 0755, true);

		// Copy public_html excluding themes and plugins content
		// Note: Use anchored patterns (/theme/*, /plugins/*) to only exclude top-level directories,
		// not subdirectories like assets/vendor/Trumbowyg-2-26/dist/plugins/
		$rsync_core_cmd = sprintf(
			'rsync -av --exclude=.git --exclude=.gitignore --exclude=specs --exclude=CLAUDE.md --exclude=uploads --exclude=cache --exclude=logs --exclude=backups --exclude=.playwright-mcp --exclude=tests --exclude="/theme/*" --exclude="/plugins/*" %s %s 2>&1',
			escapeshellarg($full_site_dir . '/public_html/'),
			escapeshellarg($core_temp_dir . '/public_html/')
		);
		exec($rsync_core_cmd, $output, $exit_code);

		// Create empty theme/ and plugins/ directories in the core archive
		mkdir($core_temp_dir . '/public_html/theme', 0755, true);
		mkdir($core_temp_dir . '/public_html/plugins', 0755, true);

		// Copy config template
		if (file_exists($maintenance_dir . 'install_tools/default_Globalvars_site.php')) {
			copy($maintenance_dir . 'install_tools/default_Globalvars_site.php', $core_temp_dir . '/config/default_Globalvars_site.php');
		}

		// Copy maintenance_scripts
		foreach (['install_tools', 'sysadmin_tools'] as $dir) {
			$source_dir = $maintenance_dir . $dir . '/';
			$dest_dir = $core_temp_dir . '/maintenance_scripts/' . $dir . '/';
			if (is_dir($source_dir)) {
				mkdir($dest_dir, 0755, true);
				exec(sprintf('rsync -av %s %s 2>&1', escapeshellarg($source_dir), escapeshellarg($dest_dir)));
			}
		}

		// Copy install SQL file
		if (file_exists($sql_source)) {
			copy($sql_source, $core_temp_dir . '/maintenance_scripts/install_tools/joinery-install.sql.gz');
		}

		// Create core tar.gz archive
		$tar_cmd = sprintf(
			'tar -czf %s -C %s . 2>&1',
			escapeshellarg($core_output_location),
			escapeshellarg($core_temp_dir)
		);
		exec($tar_cmd, $output, $exit_code);

		// Clean up temp directory
		exec('rm -rf ' . escapeshellarg($core_temp_dir));

		if (!file_exists($core_output_location) || filesize($core_output_location) == 0) {
			die("ERROR: Failed to create core archive<br>");
		}

		$core_size_mb = round(filesize($core_output_location) / 1048576, 2);
		publish_output("Core archive created: $core_filename ({$core_size_mb} MB)");

		// Store the version info in the database (using core filename)
		$upgrade = new Upgrade(NULL);
		$upgrade->set('upg_major_version', $version_major);
		$upgrade->set('upg_minor_version', $version_minor);
		$upgrade->set('upg_name', $core_filename);
		$upgrade->set('upg_release_notes', $_REQUEST['release_notes']);
		$upgrade->prepare();
		$upgrade->save();

		// =====================================================
		// Create individual THEME archives
		// =====================================================
		publish_output("\nCreating individual theme archives...");

		$themes_dir = $file_output_folder . '/themes';
		if (!is_dir($themes_dir)) {
			mkdir($themes_dir, 0755, true);
		}

		$theme_base_dir = $full_site_dir . '/public_html/theme';
		foreach (glob($theme_base_dir . '/*/theme.json') as $theme_json) {
			$theme_dir = dirname($theme_json);
			$theme_name = basename($theme_dir);

			// Read theme.json for version, is_stock, and deprecated
			$theme_data = json_decode(file_get_contents($theme_json), true);
			$is_stock = $theme_data['is_stock'] ?? true;

			if (!$is_stock) {
				publish_output("- Skipping {$theme_name} (not stock)");
				continue;
			}

			if (!empty($theme_data['deprecated'])) {
				publish_output("- Skipping {$theme_name} (deprecated)");
				continue;
			}

			$theme_version = $theme_data['version'] ?? '1.0.0';
			$theme_archive = $themes_dir . '/' . $theme_name . '-' . $theme_version . '.tar.gz';

			// Create tar.gz with just the theme directory
			$tar_cmd = sprintf(
				'tar -czf %s -C %s %s 2>&1',
				escapeshellarg($theme_archive),
				escapeshellarg($theme_base_dir),
				escapeshellarg($theme_name)
			);
			exec($tar_cmd, $output, $exit_code);

			if (file_exists($theme_archive)) {
				$theme_size_kb = round(filesize($theme_archive) / 1024, 1);
				publish_output("- {$theme_name}-{$theme_version}.tar.gz ({$theme_size_kb} KB)");
			} else {
				publish_output("- ERROR: Failed to create archive for {$theme_name}");
			}
		}

		// =====================================================
		// Create individual PLUGIN archives
		// =====================================================
		publish_output("\nCreating individual plugin archives...");

		$plugins_dir = $file_output_folder . '/plugins';
		if (!is_dir($plugins_dir)) {
			mkdir($plugins_dir, 0755, true);
		}

		$plugin_base_dir = $full_site_dir . '/public_html/plugins';
		foreach (glob($plugin_base_dir . '/*/plugin.json') as $plugin_json) {
			$plugin_dir = dirname($plugin_json);
			$plugin_name = basename($plugin_dir);

			// Read plugin.json for version, is_stock, and deprecated
			$plugin_data = json_decode(file_get_contents($plugin_json), true);
			$is_stock = $plugin_data['is_stock'] ?? true;

			if (!$is_stock) {
				publish_output("- Skipping {$plugin_name} (not stock)");
				continue;
			}

			if (!empty($plugin_data['deprecated'])) {
				publish_output("- Skipping {$plugin_name} (deprecated)");
				continue;
			}

			$plugin_version = $plugin_data['version'] ?? '1.0.0';
			$plugin_archive = $plugins_dir . '/' . $plugin_name . '-' . $plugin_version . '.tar.gz';

			// Create tar.gz with just the plugin directory
			$tar_cmd = sprintf(
				'tar -czf %s -C %s %s 2>&1',
				escapeshellarg($plugin_archive),
				escapeshellarg($plugin_base_dir),
				escapeshellarg($plugin_name)
			);
			exec($tar_cmd, $output, $exit_code);

			if (file_exists($plugin_archive)) {
				$plugin_size_kb = round(filesize($plugin_archive) / 1024, 1);
				publish_output("- {$plugin_name}-{$plugin_version}.tar.gz ({$plugin_size_kb} KB)");
			} else {
				publish_output("- ERROR: Failed to create archive for {$plugin_name}");
			}
		}

		publish_output("\nAll archives created successfully!");

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
		
		// Display session messages
		$display_messages = $session->get_messages('/utils/publish_upgrade');
		if (!empty($display_messages)) {
			foreach ($display_messages as $msg) {
				$alert_class = 'alert-info';
				if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
					$alert_class = 'alert-danger';
				} elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING) {
					$alert_class = 'alert-warning';
				} elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
					$alert_class = 'alert-success';
				}
				echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
				if ($msg->message_title) {
					echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
				}
				echo htmlspecialchars($msg->message);
				echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
				echo '</div>';
			}
			$session->clear_clearable_messages();
		}

		$pageoptions['title'] = "Publish Upgrade";
		$page->begin_box($pageoptions);

		echo '<h4>Upgrade History</h4>';
		
		$upgrades = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 10, 0);
		$upgrades->load();
		foreach ($upgrades as $upgrade){
			$version_string = 'Version '.$upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version'). ' - '. LibraryFunctions::convert_time($upgrade->get('upg_create_time'), 'UTC', $session->get_timezone()) . ' - '. substr($upgrade->get('upg_release_notes'), 0, 500);

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

			// Add delete link
			$delete_url = '/utils/publish_upgrade?delete=' . $upgrade->key;
			$version_label = $upgrade->get('upg_major_version') . '.' . $upgrade->get('upg_minor_version');
			$version_string .= ' <a href="' . htmlspecialchars($delete_url) . '" onclick="return confirm(\'Are you sure you want to delete version ' . $version_label . '? This will delete both the archive file and database record.\');" style="color: #dc3545; margin-left: 10px;"><i class="fas fa-trash-alt"></i> Delete</a>';

			echo $version_string.'<br />';
		}
		echo '<br><br>';


		// Get FormWriter using theme-aware pattern
		$formwriter = $page->getFormWriter('form1', ['action' => '/utils/publish_upgrade', 'method' => 'POST']);
		$formwriter->begin_form();

		
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

		echo '<p class="text-muted">Publishing creates: core archive + individual theme/plugin archives for all stock items.</p>';

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

	// =====================================================
	// IP WHITELIST FUNCTIONS FOR REMOTE ARCHIVE REFRESH
	// =====================================================

	/**
	 * Check if an IP address is in the allowed list
	 * Supports exact matches and CIDR notation
	 *
	 * @param string $ip IP address to check
	 * @param array $allowed_list List of allowed IPs/CIDRs
	 * @return bool
	 */
	function is_ip_in_list($ip, $allowed_list) {
		if (empty($allowed_list)) {
			return false; // No whitelist = deny all
		}

		foreach ($allowed_list as $allowed) {
			// Exact match
			if ($ip === $allowed) {
				return true;
			}

			// CIDR match
			if (strpos($allowed, '/') !== false) {
				if (ip_in_cidr($ip, $allowed)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if IP is within CIDR range
	 *
	 * @param string $ip IP address to check
	 * @param string $cidr CIDR notation (e.g., "10.0.0.0/24")
	 * @return bool
	 */
	function ip_in_cidr($ip, $cidr) {
		list($subnet, $bits) = explode('/', $cidr);
		$ip_long = ip2long($ip);
		$subnet_long = ip2long($subnet);
		$mask = -1 << (32 - $bits);
		return ($ip_long & $mask) === ($subnet_long & $mask);
	}

	/**
	 * Regenerate all archives for the current version
	 * Used by remote archive refresh feature
	 *
	 * @param string $full_site_dir Full path to site directory
	 * @param Globalvars $settings Settings instance
	 * @return array Result with 'success', 'version', and optionally 'error'
	 */
	function regenerate_current_archives($full_site_dir, $settings) {
		// Get the latest version from database
		$latest = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 1);
		$latest->load();

		if ($latest->count() == 0) {
			return ['success' => false, 'error' => 'No existing version found in database'];
		}

		$upgrade = $latest->get(0);
		$version_major = $upgrade->get('upg_major_version');
		$version_minor = $upgrade->get('upg_minor_version');
		$version = $version_major . '.' . $version_minor;

		$maintenance_dir = $full_site_dir . '/maintenance_scripts/';
		$file_output_folder = $full_site_dir . '/static_files';

		// Increase execution time for archive creation
		set_time_limit(300);

		// Step 1: Generate fresh install SQL file
		$create_sql_cmd = sprintf(
			'php %s %s 2>&1',
			escapeshellarg($full_site_dir . '/public_html/utils/create_install_sql.php'),
			escapeshellarg($version)
		);
		$output = [];
		$exit_code = 0;
		exec($create_sql_cmd, $output, $exit_code);

		if ($exit_code !== 0) {
			return ['success' => false, 'error' => 'Failed to generate install SQL: ' . implode("\n", $output)];
		}

		$sql_source = $full_site_dir . '/uploads/joinery-install-' . $version . '.sql.gz';
		if (!file_exists($sql_source)) {
			return ['success' => false, 'error' => 'Generated SQL file not found'];
		}

		// Also update the on-disk copy
		$ondisk_sql_path = $full_site_dir . '/maintenance_scripts/install_tools/joinery-install.sql.gz';
		@copy($sql_source, $ondisk_sql_path);

		// Step 2: Create core archive (no themes or plugins)
		$core_filename = 'joinery-core-' . $version . '.tar.gz';
		$core_output_location = $file_output_folder . '/' . $core_filename;

		// Remove existing archive to avoid permission issues when rebuilding
		// (CLI-created archives may be owned by a different user than the web process)
		if (file_exists($core_output_location)) {
			@unlink($core_output_location);
		}

		$core_temp_dir = sys_get_temp_dir() . '/joinery_core_' . uniqid();
		if (mkdir($core_temp_dir, 0755, true)) {
			mkdir($core_temp_dir . '/public_html', 0755, true);
			mkdir($core_temp_dir . '/config', 0755, true);
			mkdir($core_temp_dir . '/maintenance_scripts', 0755, true);

			// Copy public_html excluding themes and plugins
			// Note: Use anchored patterns (/theme/*, /plugins/*) to only exclude top-level directories
			$rsync_core_cmd = sprintf(
				'rsync -av --exclude=.git --exclude=.gitignore --exclude=specs --exclude=CLAUDE.md --exclude=uploads --exclude=cache --exclude=logs --exclude=backups --exclude=.playwright-mcp --exclude=tests --exclude="/theme/*" --exclude="/plugins/*" %s %s 2>&1',
				escapeshellarg($full_site_dir . '/public_html/'),
				escapeshellarg($core_temp_dir . '/public_html/')
			);
			exec($rsync_core_cmd);

			// Create empty theme/ and plugins/ directories
			mkdir($core_temp_dir . '/public_html/theme', 0755, true);
			mkdir($core_temp_dir . '/public_html/plugins', 0755, true);

			// Copy config template
			if (file_exists($maintenance_dir . 'install_tools/default_Globalvars_site.php')) {
				copy($maintenance_dir . 'install_tools/default_Globalvars_site.php', $core_temp_dir . '/config/default_Globalvars_site.php');
			}

			// Copy maintenance_scripts
			foreach (['install_tools', 'sysadmin_tools'] as $dir) {
				$source_dir = $maintenance_dir . $dir . '/';
				$dest_dir = $core_temp_dir . '/maintenance_scripts/' . $dir . '/';
				if (is_dir($source_dir)) {
					mkdir($dest_dir, 0755, true);
					exec(sprintf('rsync -av %s %s 2>&1', escapeshellarg($source_dir), escapeshellarg($dest_dir)));
				}
			}

			// Copy install SQL file
			if (file_exists($sql_source)) {
				copy($sql_source, $core_temp_dir . '/maintenance_scripts/install_tools/joinery-install.sql.gz');
			}

			// Create core tar.gz archive
			$tar_cmd = sprintf(
				'tar -czf %s -C %s . 2>&1',
				escapeshellarg($core_output_location),
				escapeshellarg($core_temp_dir)
			);
			exec($tar_cmd);
			exec('rm -rf ' . escapeshellarg($core_temp_dir));
		}

		// Step 3: Create individual theme archives
		$themes_dir = $file_output_folder . '/themes';
		if (!is_dir($themes_dir)) {
			mkdir($themes_dir, 0755, true);
		}

		$theme_base_dir = $full_site_dir . '/public_html/theme';
		foreach (glob($theme_base_dir . '/*/theme.json') as $theme_json) {
			$theme_dir = dirname($theme_json);
			$theme_name = basename($theme_dir);

			$theme_data = json_decode(file_get_contents($theme_json), true);
			$is_stock = $theme_data['is_stock'] ?? true;

			if (!$is_stock) {
				continue; // Skip non-stock themes
			}

			$theme_version = $theme_data['version'] ?? '1.0.0';
			$theme_archive = $themes_dir . '/' . $theme_name . '-' . $theme_version . '.tar.gz';

			if (file_exists($theme_archive)) {
				@unlink($theme_archive);
			}

			$tar_cmd = sprintf(
				'tar -czf %s -C %s %s 2>&1',
				escapeshellarg($theme_archive),
				escapeshellarg($theme_base_dir),
				escapeshellarg($theme_name)
			);
			exec($tar_cmd);
		}

		// Step 4: Create individual plugin archives
		$plugins_dir = $file_output_folder . '/plugins';
		if (!is_dir($plugins_dir)) {
			mkdir($plugins_dir, 0755, true);
		}

		$plugin_base_dir = $full_site_dir . '/public_html/plugins';
		foreach (glob($plugin_base_dir . '/*/plugin.json') as $plugin_json) {
			$plugin_dir = dirname($plugin_json);
			$plugin_name = basename($plugin_dir);

			$plugin_data = json_decode(file_get_contents($plugin_json), true);
			$is_stock = $plugin_data['is_stock'] ?? true;

			if (!$is_stock) {
				continue; // Skip non-stock plugins
			}

			$plugin_version = $plugin_data['version'] ?? '1.0.0';
			$plugin_archive = $plugins_dir . '/' . $plugin_name . '-' . $plugin_version . '.tar.gz';

			if (file_exists($plugin_archive)) {
				@unlink($plugin_archive);
			}

			$tar_cmd = sprintf(
				'tar -czf %s -C %s %s 2>&1',
				escapeshellarg($plugin_archive),
				escapeshellarg($plugin_base_dir),
				escapeshellarg($plugin_name)
			);
			exec($tar_cmd);
		}

		return ['success' => true, 'version' => $version];
	}


?>