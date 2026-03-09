<?php
	// Detect CLI mode early to avoid loading unnecessary UI components
	$is_cli = (php_sapi_name() === 'cli');

	require_once( __DIR__ . '/../includes/PathHelper.php');

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	if (!$is_cli) {
		require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	}
	require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

	$settings = Globalvars::get_instance();
	$baseDir = $settings->get_setting('baseDir');
	$site_template = $settings->get_setting('site_template');
	$full_site_dir = $baseDir.$site_template;

	if($baseDir == '' || !$baseDir){
		echo '$baseDir is empty.  Aborting upgrade.' . ($is_cli ? "\n" : '<br>');
		exit;
	}

	if($site_template == '' || !$site_template){
		echo '$site_template is empty.  Aborting upgrade.' . ($is_cli ? "\n" : '<br>');
		exit;
	}
	$verbose = false;
	$force_upgrade = false;
	$confirm_downgrade_cli = false;
	$dry_run = false;

	if ($is_cli) {
		// Parse command line arguments
		$options = getopt("", ["verbose", "force-upgrade", "confirm-downgrade", "dry-run"]);
		$verbose = isset($options['verbose']);
		$force_upgrade = isset($options['force-upgrade']);
		$confirm_downgrade_cli = isset($options['confirm-downgrade']);
		$dry_run = isset($options['dry-run']);
	} else {
		// Use existing $_GET/$_POST parsing
		$verbose = isset($_REQUEST['verbose']);
		$force_upgrade = isset($_REQUEST['force-upgrade']);
		$dry_run = isset($_REQUEST['dry-run']);
	}

	// Helper function to output and flush immediately
	function upgrade_echo($message) {
		echo $message;
		if (ob_get_level() > 0) {
			ob_flush();
		}
		flush();
	}

	$stage_location = $full_site_dir.'/uploads/upgrades/';
	$live_directory = $full_site_dir. '/public_html';
	$backup_directory = $full_site_dir. '/public_html_last';
	$live_directory_contents = $live_directory.'/*';
	$backup_directory_contents = $backup_directory.'/';
	$stage_directory = $stage_location. 'public_html';
	$stage_directory_contents = $stage_directory.'/*';

	//IF WE ARE ACTING AS A SERVER, AND SOMEONE REQUESTS THE INFO FOR UPGRADING
	if(isset($_GET['serve-upgrade']) && $_GET['serve-upgrade'] && $settings->get_setting('upgrade_server_active')){
		require_once(PathHelper::getIncludePath('/data/upgrades_class.php'));
		$response = array();
		$response['system_version'] = $settings->get_setting('system_version');
		$major = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'));
		$major->load();
		$upgrade =  $major->get(0);
		$version = $upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version');
		$response['system_version'] = $version;
		$response['upgrade_name'] = $upgrade->get('upg_name');
		$response['release_date'] = $upgrade->get('upg_create_time');
		$response['release_notes'] = $upgrade->get('upg_release_notes');

		// Core archive location (upg_name now stores core filename)
		$response['core_location'] = LibraryFunctions::get_absolute_url('/static_files/' . $upgrade->get('upg_name'));

		// Theme/plugin download endpoint
		$response['theme_endpoint'] = LibraryFunctions::get_absolute_url('/utils/publish_theme');

		// Required system themes/plugins — these must be downloaded even if
		// the target site doesn't have them installed yet
		$response['required_themes'] = get_system_required_themes($live_directory . '/theme');
		$response['required_plugins'] = get_system_required_plugins($live_directory . '/plugins');

		header("Content-Type: application/json");
		http_response_code(200);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;
	}

	//CHECK FOR EXISTENCE OF ALL NEEDED DIRECTORIES
	if(!file_exists($live_directory)){
		echo $live_directory. ' (live_directory) does not exist or is not readable by www-data.';
		exit;
	}

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
		echo $live_directory . ' (live_directory) must be writable by user1.  Aborting upgrade.<br>';
		echo 'Instead, it is owned by '.posix_getpwuid(fileowner($live_directory))['name'].' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
		exit;
	}

	$session = SessionControl::get_instance();
	if (!$is_cli) {
		$session->check_permission(8);
	}

	// Handle refresh archives request
	if (isset($_POST['refresh_archives']) && $_POST['refresh_archives'] == '1') {
		header('Content-Type: application/json');
		$result = request_archive_refresh();
		echo json_encode($result);
		exit;
	}

	$dbhelper = DbConnector::get_instance();
	$dblink = $dbhelper->get_db_link();

	//GET THE UPGRADE INFO
	$upgrade_source = $settings->get_setting('upgrade_source').'/utils/upgrade?serve-upgrade=1';
	$access_token = '';
	$curl = curl_init();
	curl_setopt_array($curl, array(
	  CURLOPT_URL => $upgrade_source,
	  CURLOPT_RETURNTRANSFER => true,
	  CURLOPT_ENCODING => '',
	  CURLOPT_MAXREDIRS => 10,
	  CURLOPT_TIMEOUT => 30, // 30 second timeout for info request
	  CURLOPT_CONNECTTIMEOUT => 10, // 10 second connection timeout
	  CURLOPT_FOLLOWLOCATION => true,
	  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
	  CURLOPT_CUSTOMREQUEST => 'GET',
	));
	$response = curl_exec($curl);
	$curl_error = curl_error($curl);
	$curl_errno = curl_errno($curl);
	curl_close($curl);

	// Validate cURL request succeeded
	$upgrade_server_error = null;
	$decode_response = null;

	if ($curl_errno !== 0) {
		if ($is_cli) {
			echo "ERROR: Unable to reach upgrade server: $curl_error\n";
			exit(1);
		}
		$upgrade_server_error = 'Unable to reach upgrade server. Error: ' . htmlspecialchars($curl_error) . ' — Server: ' . htmlspecialchars($upgrade_source);
	} else {
		// Validate JSON response
		$decode_response = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			if ($is_cli) {
				echo "ERROR: Invalid JSON response from upgrade server: " . json_last_error_msg() . "\n";
				exit(1);
			}
			$upgrade_server_error = 'Upgrade server returned invalid data. JSON Error: ' . htmlspecialchars(json_last_error_msg());
			$decode_response = null;
		}
	}

	// Validate required fields in response
	$sourceFile = $decode_response['upgrade_location'] ?? null;

	if (($_POST && $_POST['confirm']) || $is_cli){

		// Abort if upgrade server connection failed
		if ($upgrade_server_error) {
			echo '<div class="alert alert-danger"><strong>Connection Error:</strong> ' . $upgrade_server_error . '</div>';
			exit(1);
		}

		// Check ownership (relaxed for test/debug environments)
		$current_owner = posix_getpwuid(fileowner($live_directory))['name'];
		$is_debug = $settings->get_setting('debug');

		if($current_owner != 'www-data' && !$is_debug){
			echo $live_directory . ' (live_directory) must be owned by www-data.  Aborting upgrade.<br>';
			echo 'Instead, it is owned by '.$current_owner.' and has permissions '.substr(sprintf('%o', fileperms($live_directory)), -3).'<br>';
			exit;
		}
		else if($current_owner != 'www-data' && $is_debug){
			echo '<div style="border: 2px solid #856404; padding: 15px; margin: 20px 0; background-color: #fff3cd; color: #856404;">';
			echo '<strong>⚠️ TEST ENVIRONMENT:</strong> Files owned by '.$current_owner.' instead of www-data (allowed in debug mode)<br>';
			echo '</div>';
		}

		// Display dry-run banner if enabled
		if($dry_run){
			if($is_cli){
				echo "\n";
				echo "╔═══════════════════════════════════════════════════════════╗\n";
				echo "║                      DRY RUN MODE                         ║\n";
				echo "║  No changes will be made to files or database            ║\n";
				echo "║  This is a simulation of the upgrade process             ║\n";
				echo "╚═══════════════════════════════════════════════════════════╝\n";
				echo "\n";
			}
			else{
				echo '<div style="border: 3px solid #0066cc; padding: 20px; margin: 20px 0; background-color: #e7f3ff; color: #004085;">';
				echo '<h2 style="margin-top: 0; color: #0066cc;">🔍 DRY RUN MODE</h2>';
				echo '<p><strong>No changes will be made to files or database.</strong></p>';
				echo '<p>This is a simulation of the upgrade process to show what would happen.</p>';
				echo '</div>';
			}
		}

		if($decode_response['system_version']){
			// Parse versions for proper semantic comparison
			$current_version = $settings->get_setting('system_version');
			$server_version = $decode_response['system_version'];

			// Compare versions properly (handles semantic versioning)
			$version_compare = version_compare($current_version, $server_version);

			if($version_compare > 0 && !$force_upgrade){
				// Current version is HIGHER than server version - this is a downgrade attempt
				echo '<div style="border: 2px solid #dc3545; padding: 20px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
				echo '<h3 style="margin-top: 0; color: #721c24;">⚠️ DOWNGRADE DETECTED</h3>';
				echo '<p><strong>You are attempting to downgrade your system:</strong></p>';
				echo '<ul>';
				echo '<li>Current version: <strong>' . htmlspecialchars($current_version) . '</strong></li>';
				echo '<li>Target version: <strong>' . htmlspecialchars($server_version) . '</strong></li>';
				echo '</ul>';
				echo '<p><strong>Downgrades can cause data loss, compatibility issues, and unexpected behavior.</strong></p>';
				echo '<p>If you need to downgrade, add <code>--force-upgrade</code> (CLI) or <code>?force-upgrade=1</code> (web) to your request.</p>';
				echo '</div>';
				exit;
			}
			else if($version_compare > 0 && $force_upgrade){
				// Downgrade with force flag - show warning and require confirmation
				$downgrade_confirmed = false;

				if($is_cli){
					// CLI: Check for --confirm-downgrade flag
					if(!$confirm_downgrade_cli){
						echo "⚠️  FORCED DOWNGRADE - CONFIRMATION REQUIRED\n\n";
						echo "You are forcing a downgrade:\n";
						echo "  Current version: " . $current_version . "\n";
						echo "  Target version:  " . $server_version . "\n\n";
						echo "WARNING: Downgrades can cause:\n";
						echo "  • Data loss or corruption\n";
						echo "  • Database schema conflicts\n";
						echo "  • Plugin/theme incompatibilities\n";
						echo "  • Loss of recent features and bug fixes\n\n";
						echo "To proceed with this downgrade, add --confirm-downgrade to your command:\n";
						echo "  php upgrade.php --force-upgrade --confirm-downgrade\n";
						exit(1);
					}
					else{
						$downgrade_confirmed = true;
					}
				}
				else{
					// Web: Check for confirm_downgrade POST field
					if(!isset($_POST['confirm_downgrade']) || $_POST['confirm_downgrade'] !== 'DOWNGRADE'){
						echo '<div style="border: 2px solid #dc3545; padding: 20px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
						echo '<h3 style="margin-top: 0; color: #721c24;">⚠️ FORCED DOWNGRADE - CONFIRMATION REQUIRED</h3>';
						echo '<p><strong>You are forcing a downgrade:</strong></p>';
						echo '<ul>';
						echo '<li>Current version: <strong>' . htmlspecialchars($current_version) . '</strong></li>';
						echo '<li>Target version: <strong>' . htmlspecialchars($server_version) . '</strong></li>';
						echo '</ul>';
						echo '<p><strong style="color: #721c24;">WARNING: Downgrades can cause:</strong></p>';
						echo '<ul>';
						echo '<li>Data loss or corruption</li>';
						echo '<li>Database schema conflicts</li>';
						echo '<li>Plugin/theme incompatibilities</li>';
						echo '<li>Loss of recent features and bug fixes</li>';
						echo '</ul>';
						echo '<form method="POST" action="/utils/upgrade">';
						echo '<input type="hidden" name="confirm" value="1">';
						echo '<input type="hidden" name="force-upgrade" value="1">';
						echo '<p><strong>To proceed with this downgrade, type <code>DOWNGRADE</code> below:</strong></p>';
						echo '<input type="text" name="confirm_downgrade" style="padding: 10px; font-size: 16px; width: 300px;" placeholder="Type DOWNGRADE" required>';
						echo '<br><br>';
						echo '<button type="submit" style="background-color: #dc3545; color: white; padding: 10px 20px; font-size: 16px; border: none; cursor: pointer;">Proceed with Downgrade</button>';
						echo ' ';
						echo '<a href="/utils/upgrade" style="padding: 10px 20px; font-size: 16px; background-color: #6c757d; color: white; text-decoration: none; display: inline-block;">Cancel</a>';
						echo '</form>';
						echo '</div>';
						exit;
					}
					else{
						$downgrade_confirmed = true;
					}
				}

				if($downgrade_confirmed){
					// Confirmed downgrade
					if($is_cli){
						echo "⚠️  DOWNGRADE IN PROGRESS: " . $current_version . " → " . $server_version . "\n";
					}
					else{
						echo '<div style="border: 2px solid #856404; padding: 15px; margin: 20px 0; background-color: #fff3cd; color: #856404;">';
						echo '<strong>⚠️ DOWNGRADE IN PROGRESS:</strong> '. htmlspecialchars($current_version) . ' → ' . htmlspecialchars($server_version) . '<br>';
						echo '</div>';
					}
				}
			}
			else if($version_compare == 0){
				// Same version
				echo '<div style="border: 2px solid #0c5460; padding: 15px; margin: 20px 0; background-color: #d1ecf1; color: #0c5460;">';
				echo '<strong>ℹ️ SAME VERSION:</strong> Current version (' . htmlspecialchars($current_version) . ') is the same as server version.<br>';
				echo '</div>';
			}
			else{
				// Normal upgrade
				echo '<div style="border: 2px solid #155724; padding: 15px; margin: 20px 0; background-color: #d4edda; color: #155724;">';
				echo '<strong>✓ UPGRADE:</strong> '. htmlspecialchars($current_version) . ' → ' . htmlspecialchars($server_version) . '<br>';
				echo '</div>';
			}
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

		// Check disk space before download (require at least 500MB free)
		$free_space = disk_free_space($full_site_dir.'/uploads/');
		$min_required = 500 * 1024 * 1024; // 500MB
		if ($free_space !== false && $free_space < $min_required) {
			$free_mb = round($free_space / 1024 / 1024);
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
			echo "<strong>❌ Insufficient Disk Space:</strong> Only {$free_mb}MB available, need at least 500MB.<br>";
			echo 'Free up disk space before upgrading.<br>';
			echo '</div>';
			exit(1);
		}

		// Download core + individual themes/plugins
		$sourceFile = $decode_response['core_location'] ?? null;
		$theme_endpoint = $decode_response['theme_endpoint'] ?? null;

		if (!$sourceFile) {
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
			echo '<strong>❌ Invalid Response:</strong> Upgrade server did not provide a core_location.<br>';
			echo '</div>';
			exit(1);
		}

		// =====================================================
		// SELF-UPDATE RESUME DETECTION
		// =====================================================
		// If a previous run self-updated deployment files and asked for a re-run,
		// detect the marker and skip download/extraction (staging already has files).
		$self_update_marker = $stage_location . '.self_update_ready';
		$resuming_after_self_update = false;

		if (file_exists($self_update_marker)) {
			$marker_data = json_decode(file_get_contents($self_update_marker), true);

			if ($marker_data && json_last_error() === JSON_ERROR_NONE) {
				$marker_age = time() - strtotime($marker_data['created_time']);
				$max_age = 86400; // 24 hours
				$versions_match = ($marker_data['target_version'] === $decode_response['system_version']);
				$staging_has_files = is_dir($stage_directory) && !is_dir_empty($stage_directory);

				if ($marker_age <= $max_age && $versions_match && $staging_has_files) {
					$resuming_after_self_update = true;

					if ($is_cli) {
						echo "\n✓ Resuming upgrade after self-update.\n";
						echo "  Updated files: " . implode(', ', $marker_data['files_updated']) . "\n\n";
					} else {
						echo '<div style="border: 2px solid #0c5460; padding: 15px; margin: 20px 0; background-color: #d1ecf1; color: #0c5460;">';
						echo '<strong>✓ Resuming upgrade after self-update.</strong> ';
						echo 'Updated files: ' . htmlspecialchars(implode(', ', $marker_data['files_updated']));
						echo '</div>';
					}

					// Clean up marker now that we've consumed it
					@unlink($self_update_marker);
				} else {
					// Stale or mismatched marker — clean up and do fresh download
					if ($verbose) {
						$reason_parts = [];
						if ($marker_age > $max_age) $reason_parts[] = 'expired (' . round($marker_age/3600, 1) . 'h old)';
						if (!$versions_match) $reason_parts[] = 'version mismatch';
						if (!$staging_has_files) $reason_parts[] = 'staging empty';
						upgrade_echo('Found stale self-update marker (' . implode(', ', $reason_parts) . '). Starting fresh download.<br>');
					}
					@unlink($self_update_marker);
					if (file_exists($stage_location)) {
						exec("chmod -R 770 " . escapeshellarg($stage_location));
						exec("rm -rf " . escapeshellarg($stage_location) . "/*");
					}
				}
			} else {
				// Corrupt marker
				@unlink($self_update_marker);
			}
		}

		// Determine which themes/plugins to download based on what's installed
		$themes_to_download = get_installed_stock_themes($live_directory . '/theme');
		$plugins_to_download = get_installed_stock_plugins($live_directory . '/plugins');

		// Get detailed info for status display
		$all_themes_info = get_all_themes_info($live_directory . '/theme');
		$all_plugins_info = get_all_plugins_info($live_directory . '/plugins');

		if (!$resuming_after_self_update) {

		upgrade_echo('<h3>Downloading Upgrade Components</h3>');

		// Display theme status
		if ($is_cli) {
			echo "\n=== INSTALLED THEMES ===\n";
		} else {
			upgrade_echo('<h4>Installed Themes</h4>');
		}
		output_component_status($all_themes_info, 'theme', $is_cli);

		// Display plugin status
		if ($is_cli) {
			echo "=== INSTALLED PLUGINS ===\n";
		} else {
			upgrade_echo('<h4>Installed Plugins</h4>');
		}
		output_component_status($all_plugins_info, 'plugin', $is_cli);

		// Summary
		$total_themes = count($all_themes_info);
		$stock_themes = count($themes_to_download);
		$total_plugins = count($all_plugins_info);
		$stock_plugins = count($plugins_to_download);

		if ($is_cli) {
			echo "Summary: {$stock_themes}/{$total_themes} themes and {$stock_plugins}/{$total_plugins} plugins will be upgraded\n\n";
		} else {
			upgrade_echo("<p><strong>Summary:</strong> {$stock_themes}/{$total_themes} themes and {$stock_plugins}/{$total_plugins} plugins will be upgraded</p>");
		}

		upgrade_echo('Downloading core archive: ' . htmlspecialchars($sourceFile) . '<br>');
		flush();

		$file_download_location = $full_site_dir . '/uploads/' . basename($sourceFile);

		// Download core archive
		$new_file = fopen($file_download_location, "w");
		if (!$new_file) {
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
			echo '<strong>❌ File Error:</strong> Cannot create download file at ' . htmlspecialchars($file_download_location) . '<br>';
			echo '</div>';
			exit(1);
		}

		$cd = curl_init();
		curl_setopt($cd, CURLOPT_URL, $sourceFile);
		curl_setopt($cd, CURLOPT_FILE, $new_file);
		curl_setopt($cd, CURLOPT_TIMEOUT, 300);
		curl_setopt($cd, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($cd, CURLOPT_FOLLOWLOCATION, true);
		$curl_success = curl_exec($cd);
		$curl_error = curl_error($cd);
		$curl_errno = curl_errno($cd);
		$status = curl_getinfo($cd);
		curl_close($cd);
		fclose($new_file);

		if ($curl_errno !== 0 || $status["http_code"] != 200) {
			@unlink($file_download_location);
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
			echo '<strong>❌ Core Download Failed:</strong> ' . htmlspecialchars($curl_error ?: "HTTP " . $status["http_code"]) . '<br>';
			echo '</div>';
			exit(1);
		}

		$core_size_mb = round(filesize($file_download_location) / 1024 / 1024, 2);
		upgrade_echo("✓ Core archive downloaded ({$core_size_mb} MB)<br>");

		//CLEAR OLD STAGED FILES
		if($verbose) upgrade_echo('Clearing staging area: '.$stage_location.'<br>');
		if(file_exists($stage_location)){
			exec("chmod -R 770 $stage_location");
			exec ("rm -rf $stage_location".'/.git');  //REMOVE LATENT GIT FILES
			exec ("rm -rf $stage_location".'/.gitignore');  //REMOVE LATENT GIT FILES
			exec ("rm -rf $stage_location".'/*');
			if(!is_dir_empty($stage_location)){
				echo 'Failed to clear staging location:'.$stage_location.'...aborting.<br>';
				echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';
				exit;
			}
			else{
				if($verbose) upgrade_echo('Staging area cleared<br>');
			}
		}
		else{
			// Create staging directory if it doesn't exist
			if(!mkdir($stage_location, 0770, true)){
				echo 'Failed to create staging location: '.$stage_location.'...aborting.<br>';
				exit;
			}
			upgrade_echo('Staging area created<br>');
		}

		// EXTRACT THE ARCHIVE (supports both tar.gz and legacy zip)
		$file_ext = pathinfo($file_download_location, PATHINFO_EXTENSION);
		$is_tar_gz = (strpos(basename($file_download_location), '.tar.gz') !== false);

		if ($is_tar_gz) {
			// Extract tar.gz archive
			if($verbose) upgrade_echo('Extracting tar.gz archive...<br>');
			$tar_cmd = sprintf(
				'tar -xzf %s -C %s 2>&1',
				escapeshellarg($file_download_location),
				escapeshellarg($stage_location)
			);
			$tar_output = [];
			$tar_exit = 0;
			exec($tar_cmd, $tar_output, $tar_exit);

			if ($tar_exit !== 0) {
				echo 'Unable to extract tar.gz upgrade from '.$file_download_location.' <br>';
				echo 'Error: ' . implode('<br>', $tar_output) . '<br>';
				exit;
			}
			if($verbose) upgrade_echo('Upgrade at '.$file_download_location. ' extracted to '.$stage_location.'<br>');
		}
		else {
			// Extract legacy zip archive
			upgrade_echo('Extracting legacy zip archive...<br>');
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
		}

		// =====================================================
		// SELF-UPDATE CHECK
		// =====================================================
		// Compare key deployment files between staged and live versions.
		// If any differ, copy the new versions to live and request a re-run
		// so the new code executes from the start.
		$self_update_files = [
			'utils/upgrade.php',
			'utils/update_database.php',
			'includes/DatabaseUpdater.php',
			'includes/DeploymentHelper.php',
		];

		$files_needing_update = [];
		foreach ($self_update_files as $rel_path) {
			$staged_file = $stage_directory . '/' . $rel_path;
			$live_file = $live_directory . '/' . $rel_path;

			if (file_exists($staged_file)) {
				if (!file_exists($live_file) || md5_file($staged_file) !== md5_file($live_file)) {
					$files_needing_update[] = $rel_path;
				}
			}
		}

		if (!empty($files_needing_update)) {
			if ($is_cli) {
				echo "\n=== SELF-UPDATE REQUIRED ===\n";
				echo "The following deployment files have changed in the new version:\n";
			} else {
				upgrade_echo('<br><h3>Self-Update Required</h3>');
				echo 'The following deployment files have changed in the new version:<br>';
			}
			foreach ($files_needing_update as $f) {
				echo ($is_cli ? '  - ' : '  &bull; ') . htmlspecialchars($f) . ($is_cli ? "\n" : '<br>');
			}

			if (!$dry_run) {
				// Copy new versions over live files
				$copy_errors = [];
				foreach ($files_needing_update as $rel_path) {
					$staged_file = $stage_directory . '/' . $rel_path;
					$live_file = $live_directory . '/' . $rel_path;

					$target_dir = dirname($live_file);
					if (!is_dir($target_dir)) {
						mkdir($target_dir, 0770, true);
					}

					if (copy($staged_file, $live_file)) {
						// Invalidate opcache for the updated file so the re-run loads fresh bytecode
						if (function_exists('opcache_invalidate')) {
							opcache_invalidate($live_file, true);
						}
					} else {
						$copy_errors[] = $rel_path;
					}
				}

				if (!empty($copy_errors)) {
					echo ($is_cli ? "\n" : '<div style="border: 2px solid #856404; padding: 15px; margin: 20px 0; background-color: #fff3cd; color: #856404;">');
					echo 'Warning: Failed to copy some files: ' . implode(', ', $copy_errors) . '. Continuing with current versions.';
					echo ($is_cli ? "\n" : '</div>');
					// Don't abort — proceed with old code, which is better than failing entirely
				} else {
					// Write marker file so re-run skips download/extraction
					$marker_data = [
						'created_time' => gmdate('c'),
						'source_version' => $settings->get_setting('system_version'),
						'target_version' => $decode_response['system_version'],
						'files_updated' => $files_needing_update,
					];

					file_put_contents($self_update_marker, json_encode($marker_data, JSON_PRETTY_PRINT));

					// Ask user to re-run
					if ($is_cli) {
						echo "\n";
						echo "════════════════════════════════════════════════════════════\n";
						echo "  SELF-UPDATE COMPLETE — PLEASE RE-RUN THE UPGRADE\n";
						echo "════════════════════════════════════════════════════════════\n";
						echo "\n";
						echo "  Deployment tools have been updated. The upgrade will\n";
						echo "  resume automatically from where it left off.\n";
						echo "\n";
						echo "  Re-run with the same command to continue.\n";
						echo "\n";
					} else {
						echo '<div style="border: 3px solid #0066cc; padding: 20px; margin: 20px 0; background-color: #e7f3ff; color: #004085;">';
						echo '<h2 style="margin-top: 0; color: #0066cc;">Self-Update Complete</h2>';
						echo '<p>Deployment infrastructure has been updated to the latest version.</p>';
						echo '<p><strong>Please click the button below to continue the upgrade.</strong> ';
						echo 'The download step will be skipped automatically.</p>';
						echo '<form method="POST" action="/utils/upgrade">';
						echo '<input type="hidden" name="confirm" value="1">';
						if ($force_upgrade) echo '<input type="hidden" name="force-upgrade" value="1">';
						if ($verbose) echo '<input type="hidden" name="verbose" value="1">';
						if ($dry_run) echo '<input type="hidden" name="dry-run" value="1">';
						echo '<button type="submit" style="background-color: #0066cc; color: white; padding: 12px 24px; font-size: 16px; border: none; cursor: pointer; border-radius: 4px;">Continue Upgrade</button>';
						echo '</form>';
						echo '</div>';
					}

					exit(0);
				}
			} else {
				// Dry run — just report what would happen
				echo ($is_cli ? "\n" : '<div style="border: 2px solid #0066cc; padding: 15px; margin: 10px 0; background-color: #e7f3ff; color: #004085;">');
				echo ($is_cli ? '' : '<strong>') . 'DRY RUN:' . ($is_cli ? '' : '</strong>') . ' These files would be self-updated and the upgrade would require a re-run.';
				echo ($is_cli ? "\n\n" : '</div>');
			}
		} else {
			if ($verbose) {
				upgrade_echo('Self-update check: all deployment files are current.<br>');
			}
		}

		} // end if (!$resuming_after_self_update)

		// =====================================================
		// CHECK FOR REQUIRED THEMES/PLUGINS NOT YET INSTALLED
		// =====================================================
		// The upgrade server response may include required_themes — system themes
		// that must be present for core code to function (e.g., joinery-system).
		// Download these even if they aren't currently installed on this site.
		$required_themes = $decode_response['required_themes'] ?? [];
		foreach ($required_themes as $req_theme) {
			if (!in_array($req_theme, $themes_to_download)) {
				$theme_dir_check = $live_directory . '/theme/' . $req_theme;
				if (!is_dir($theme_dir_check)) {
					upgrade_echo("Adding required system theme: {$req_theme} (not currently installed)<br>");
				} else {
					if ($verbose) {
						upgrade_echo("Adding required system theme: {$req_theme} (ensuring latest version)<br>");
					}
				}
				$themes_to_download[] = $req_theme;
			}
		}

		$required_plugins = $decode_response['required_plugins'] ?? [];
		foreach ($required_plugins as $req_plugin) {
			if (!in_array($req_plugin, $plugins_to_download)) {
				$plugin_dir_check = $live_directory . '/plugins/' . $req_plugin;
				if (!is_dir($plugin_dir_check)) {
					upgrade_echo("Adding required system plugin: {$req_plugin} (not currently installed)<br>");
				} else {
					if ($verbose) {
						upgrade_echo("Adding required system plugin: {$req_plugin} (ensuring latest version)<br>");
					}
				}
				$plugins_to_download[] = $req_plugin;
			}
		}

		// =====================================================
		// DOWNLOAD INDIVIDUAL THEMES AND PLUGINS (new method only)
		// =====================================================
		if (!empty($themes_to_download) || !empty($plugins_to_download)) {
			upgrade_echo('<br><h3>Downloading Individual Themes and Plugins</h3>');

			// Download themes
			foreach ($themes_to_download as $theme_name) {
				$theme_url = $theme_endpoint . '?download=' . urlencode($theme_name);
				upgrade_echo("Downloading theme: {$theme_name}...");
				flush();

				$result = download_and_extract($theme_url, $stage_directory . '/theme/');
				if ($result['success']) {
					upgrade_echo(" ✓<br>");
				} else {
					upgrade_echo(" ❌ " . htmlspecialchars($result['error']) . "<br>");
					echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 10px 0; background-color: #f8d7da; color: #721c24;">';
					echo '<strong>❌ Theme Download Failed:</strong> ' . htmlspecialchars($theme_name) . '<br>';
					echo 'Error: ' . htmlspecialchars($result['error']) . '<br>';
					echo '</div>';
					exec("rm -rf " . escapeshellarg($stage_location) . "/*");
					exit(1);
				}
			}

			// Download plugins
			foreach ($plugins_to_download as $plugin_name) {
				$plugin_url = $theme_endpoint . '?download=' . urlencode($plugin_name) . '&type=plugin';
				upgrade_echo("Downloading plugin: {$plugin_name}...");
				flush();

				$result = download_and_extract($plugin_url, $stage_directory . '/plugins/');
				if ($result['success']) {
					upgrade_echo(" ✓<br>");
				} else {
					upgrade_echo(" ❌ " . htmlspecialchars($result['error']) . "<br>");
					echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 10px 0; background-color: #f8d7da; color: #721c24;">';
					echo '<strong>❌ Plugin Download Failed:</strong> ' . htmlspecialchars($plugin_name) . '<br>';
					echo 'Error: ' . htmlspecialchars($result['error']) . '<br>';
					echo '</div>';
					exec("rm -rf " . escapeshellarg($stage_location) . "/*");
					exit(1);
				}
			}

			$total_items = count($themes_to_download) + count($plugins_to_download);
			upgrade_echo("✓ Downloaded {$total_items} theme/plugin archives<br>");
		}

		// ============================================
		// PRE-DEPLOYMENT VALIDATION
		// ============================================
		upgrade_echo('<br><h3>Pre-deployment Validation</h3>');

		// Validate tarball structure (heuristic check for obvious issues)
		$result = DeploymentHelper::validateTarballStructure($stage_directory, $verbose);
		if (!$result['success']) {
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 10px 0; background-color: #f8d7da; color: #721c24;">';
			echo '<strong>❌ Tarball Validation Failed:</strong> The upgrade package does not have the expected structure.<br>';
			echo '<ul>';
			foreach ($result['errors'] as $error) {
				echo '<li>' . htmlspecialchars($error) . '</li>';
			}
			echo '</ul>';
			echo 'This may indicate a corrupted download or wrong file.<br>';
			echo '</div>';
			// Clean up staging
			exec("rm -rf " . escapeshellarg($stage_location) . "/*");
			exit(1);
		}
		echo "✓ Tarball structure validation passed<br>";
		if (!empty($result['warnings'])) {
			echo '<div style="border: 2px solid #856404; padding: 10px; margin: 10px 0; background-color: #fff3cd; color: #856404;">';
			echo '<strong>⚠️ Warnings:</strong><br>';
			foreach ($result['warnings'] as $warning) {
				echo '• ' . htmlspecialchars($warning) . '<br>';
			}
			echo '</div>';
		}

		// Check that active theme is available
		$active_theme = $settings->get_setting('theme');
		if ($active_theme) {
			$staged_theme_path = $stage_directory . '/theme/' . $active_theme;
			$live_theme_path = $live_directory . '/theme/' . $active_theme;

			if (!is_dir($staged_theme_path)) {
				// Theme not in staging - check if it's a custom theme in live that will be preserved
				$theme_will_be_preserved = false;
				$preservation_reason = '';

				if (is_dir($live_theme_path)) {
					// Theme exists in live - check if it's custom
					$manifest_path = $live_theme_path . '/theme.json';
					if (file_exists($manifest_path)) {
						$manifest = json_decode(file_get_contents($manifest_path), true);
						if (isset($manifest['is_stock']) && $manifest['is_stock'] === false) {
							$theme_will_be_preserved = true;
							$preservation_reason = 'marked as custom (is_stock=false)';
						}
					}
				}

				if ($theme_will_be_preserved) {
					echo '<div style="border: 2px solid #856404; padding: 15px; margin: 10px 0; background-color: #fff3cd; color: #856404;">';
					echo "<strong>⚠️ NOTE:</strong> Active theme '<strong>" . htmlspecialchars($active_theme) . "</strong>' is not in the upgrade package.<br>";
					echo "However, it will be preserved because it is " . $preservation_reason . ".<br>";
					echo '</div>';
				} else {
					echo '<div style="border: 2px solid #dc3545; padding: 20px; margin: 10px 0; background-color: #f8d7da; color: #721c24;">';
					echo '<h3 style="margin-top: 0; color: #721c24;">❌ UPGRADE BLOCKED: Active Theme Missing</h3>';
					echo "<p>The currently active theme '<strong>" . htmlspecialchars($active_theme) . "</strong>' is not included in this upgrade package.</p>";
					echo '<p>If the upgrade proceeds, your site would lose its theme and may become unusable.</p>';
					echo '<p><strong>To fix this:</strong></p>';
					echo '<ul>';
					echo '<li>Republish the upgrade with the "' . htmlspecialchars($active_theme) . '" theme selected, OR</li>';
					echo '<li>Switch to a different theme before upgrading, OR</li>';
					echo '<li>Mark the theme as custom by adding <code>"is_stock": false</code> to its theme.json</li>';
					echo '</ul>';
					echo '</div>';

					// Clean up staging
					echo 'Cleaning up staging area...<br>';
					exec("rm -rf " . escapeshellarg($stage_location) . "/*");
					exit(1);
				}
			} else {
				echo "✓ Active theme '" . htmlspecialchars($active_theme) . "' found in upgrade package<br>";
			}
		}

		// Validate PHP syntax
		$result = DeploymentHelper::validatePHPSyntax($stage_directory, $verbose);
		if (!$result['success']) {
			echo "<strong>PHP syntax validation FAILED</strong> - {$result['files_checked']} files checked<br>";
			echo count($result['errors']) . " errors found:<br>";
			foreach ($result['errors'] as $error) {
				echo "  • " . htmlspecialchars($error['file']) .
					 " (line {$error['line']}): " . htmlspecialchars($error['message']) . "<br>";
			}
			echo '<br><strong>Rolling back deployment...</strong><br>';
			$rollback = DeploymentHelper::performRollback($site_template, true, $verbose);
			if ($rollback['success']) {
				echo "✓ Rollback completed successfully<br>";
			}
			exit(1);
		} else {
			echo "✓ PHP syntax validation passed ({$result['files_checked']} files)<br>";
		}

		// Test plugin loading
		$result = DeploymentHelper::testPluginLoading($stage_directory, $verbose);
		if (!$result['success']) {
			echo "<strong>Plugin loading tests FAILED</strong><br>";
			foreach ($result['errors'] as $error) {
				$type_label = ($error['type'] === 'syntax') ? 'SYNTAX' : strtoupper($error['type']);
				echo "  • [$type_label] " . htmlspecialchars($error['file']) . ": " .
					 htmlspecialchars($error['message']) . "<br>";
			}
			echo '<br><strong>Rolling back deployment...</strong><br>';
			$rollback = DeploymentHelper::performRollback($site_template, true, $verbose);
			if ($rollback['success']) {
				echo "✓ Rollback completed successfully<br>";
			}
			exit(1);
		} else {
			echo "✓ Plugin loading tests passed ({$result['files_checked']} plugins)<br>";
		}

		// Test bootstrap
		$result = DeploymentHelper::testBootstrap($stage_directory, $verbose);
		if (!$result['success']) {
			echo "<strong>Bootstrap test FAILED:</strong> " . htmlspecialchars($result['error']) . "<br>";
			echo '<br><strong>Rolling back deployment...</strong><br>';
			$rollback = DeploymentHelper::performRollback($site_template, true, $verbose);
			if ($rollback['success']) {
				echo "✓ Rollback completed successfully<br>";
			}
			exit(1);
		} else {
			echo "✓ Bootstrap test passed (loaded: " . implode(', ', $result['components_loaded']) . ")<br>";
		}

		upgrade_echo('<br><h3>Preserving Custom Themes/Plugins</h3>');

		// Copy custom themes/plugins from live into staging BEFORE the mv
		// This ensures custom themes are included when staging moves to live
		// - Themes/plugins with is_stock=false are copied
		// - Themes/plugins not in staging (uploaded directly) are copied
		// - Stock themes/plugins are left alone (will be updated from staging)
		$result = DeploymentHelper::copyCustomToStaging($live_directory, $stage_directory, $verbose);
		if ($result['success']) {
			echo "✓ Themes: {$result['themes_copied']} custom preserved, {$result['themes_skipped']} stock (will update)<br>";
			echo "✓ Plugins: {$result['plugins_copied']} custom preserved, {$result['plugins_skipped']} stock (will update)<br>";
		} else {
			echo "Theme/Plugin preservation had errors:<br>";
			foreach ($result['errors'] as $error) {
				echo "  • " . htmlspecialchars($error) . "<br>";
			}
			// Don't abort on preservation errors, but log them
		}

		// ============================================
		// DEPLOYMENT
		// ============================================
		upgrade_echo('<br><h3>Deploying Upgrade</h3>');

		if($dry_run){
			echo '<div style="border: 2px solid #0066cc; padding: 15px; margin: 10px 0; background-color: #e7f3ff; color: #004085;">';
			echo '<strong>🔍 DRY RUN:</strong> Skipping file deployment (files remain in staging area)<br>';
			echo '</div>';
		}
		else{
			//CLEAR OR CREATE BACKUP AREA
			if($verbose) echo 'Clearing backup area: '.$backup_directory.'<br>';
			if (!is_dir($backup_directory)) {
				if (!mkdir($backup_directory, 0770, true)) {
					echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 10px 0; background-color: #f8d7da; color: #721c24;">';
					echo '<strong>❌ Error:</strong> Could not create backup directory: ' . htmlspecialchars($backup_directory) . '<br>';
					echo '</div>';
					exit(1);
				}
				if($verbose) echo 'Backup area created<br>';
			} else {
				exec ("rm -rf $backup_directory".'/*');
				exec ("rm -rf $backup_directory".'/.git');  //REMOVE LATENT GIT FILES
				exec ("rm -rf $backup_directory".'/.gitignore');  //REMOVE LATENT GIT FILES
			}
			if(is_dir_empty($backup_directory)){
				if($verbose) echo 'Backup area cleared<br>';
			}
			else{
				echo "Failed to remove old backup files...aborting.<br>";
				echo 'Permissions of '.$backup_directory.': '.substr(sprintf('%o', fileperms($backup_directory)), -4).'<br>';
				$x=1;
				$files = scandir($backup_directory);
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
			if($verbose) echo 'Setting '.$stage_location.' to 770<br>';
			exec("chmod -R 770 $stage_location");
			if($verbose) echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';

			if($verbose) echo 'Moving '.$live_directory. ' to '. $backup_directory.'<br>';
			if($verbose) echo 'Moving '.$stage_directory. ' to '. $live_directory.'<br>';

			// Move live to backup
			$mv_output = [];
			$mv_exit = 0;
			exec("mv $live_directory_contents $backup_directory 2>&1", $mv_output, $mv_exit);
			if ($mv_exit !== 0) {
				echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
				echo '<strong>❌ Backup Failed:</strong> Could not move live files to backup.<br>';
				echo 'Error: ' . htmlspecialchars(implode(' ', $mv_output)) . '<br>';
				echo '</div>';
				exit(1);
			}

			// Move staged to live
			$mv_output = [];
			$mv_exit = 0;
			exec("mv $stage_directory_contents $live_directory 2>&1", $mv_output, $mv_exit);
			if ($mv_exit !== 0) {
				echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
				echo '<strong>❌ Deployment Failed:</strong> Could not move staged files to live.<br>';
				echo 'Error: ' . htmlspecialchars(implode(' ', $mv_output)) . '<br>';
				echo 'Attempting rollback...<br>';
				echo '</div>';
				// Attempt to restore from backup
				exec("mv $backup_directory_contents $live_directory 2>&1");
				exit(1);
			}

			// Fix permissions using centralized script (production mode)
			$fix_permissions_script = $full_site_dir . '/maintenance_scripts/install_tools/fix_permissions.sh';
			if(file_exists($fix_permissions_script)) {
				if($verbose) echo 'Setting permissions using fix_permissions.sh --production<br>';
				exec("$fix_permissions_script " . escapeshellarg($site_template) . " --production 2>&1", $perm_output, $perm_exit);
				if($perm_exit !== 0) {
					echo 'Warning: fix_permissions.sh failed, falling back to chmod<br>';
					exec("chmod -R 770 $live_directory");
				}
			} else {
				echo 'Warning: fix_permissions.sh not found, using fallback chmod<br>';
				exec("chmod -R 770 $live_directory");
			}
			exec("chmod -R 770 $backup_directory");

			// Check if deployment succeeded
			$deployment_failed = false;
			if(file_exists($live_directory) && file_exists($backup_directory)){
				echo 'Copied upgrade files.<br>';
			}
			else if(!file_exists($live_directory)){
				//FAILED, LETS LOAD FROM BACKUP
				echo '<strong>Upgrade failed, loading from backup.</strong><br>';
				$deployment_failed = true;
				$rollback = DeploymentHelper::performRollback($site_template, true, $verbose);
				if ($rollback['success']) {
					echo "✓ Rollback completed successfully<br>";
					if ($rollback['failed_dir']) {
						echo "  Failed deployment preserved at: " . $rollback['failed_dir'] . "<br>";
					}
				} else {
					echo "✗ Rollback FAILED: " . htmlspecialchars($rollback['error']) . "<br>";
				}
				exit(1);
			}
		}

		//CLEAR OLD STAGED FILES
		if($dry_run){
			echo '<div style="border: 2px solid #0066cc; padding: 15px; margin: 10px 0; background-color: #e7f3ff; color: #004085;">';
			echo '<strong>🔍 DRY RUN:</strong> Keeping staging files at: ' . htmlspecialchars($stage_location) . '<br>';
			echo 'You can inspect the extracted upgrade before running a real upgrade.<br>';
			echo '</div>';
		}
		else{
			if($verbose) echo 'Clearing staging area: '.$stage_location.'...<br>';
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
					if($verbose) echo 'Staging area cleared<br>';
				}
			}
		}

		// ============================================
		// COMPOSER VALIDATION
		// ============================================
		upgrade_echo('<br><h3>Validating Composer Dependencies</h3>');

		if($dry_run){
			echo '<div style="border: 2px solid #0066cc; padding: 15px; margin: 10px 0; background-color: #e7f3ff; color: #004085;">';
			echo '<strong>🔍 DRY RUN:</strong> Skipping composer installation<br>';
			echo '</div>';
		}
		else{
			$composer_script = $live_directory . '/utils/composer_install_if_needed.php';
			if (file_exists($composer_script)) {
				$composer_output = [];
				$composer_return = 0;
				exec("php " . escapeshellarg($composer_script) . " 2>&1", $composer_output, $composer_return);

				// Note: composer_install_if_needed.php uses CORRECT exit codes (0 = success, 1 = failure)
				if ($composer_return !== 0) {
					echo "<strong>ERROR: Composer dependency setup failed.</strong><br>";
					echo implode('<br>', $composer_output) . "<br>";
					echo '<br><strong>Rolling back deployment...</strong><br>';
					$rollback = DeploymentHelper::performRollback($site_template, true, $verbose);
					if ($rollback['success']) {
						echo "✓ Rollback completed successfully<br>";
					}
					exit(1);
				} else {
					echo "✓ Composer dependencies validated<br>";
					if ($verbose) {
						echo implode('<br>', $composer_output) . "<br>";
					}
				}
			} else {
				echo "⚠ Composer validation script not found (skipping)<br>";
			}
		}

		// ============================================
		// OPCACHE RESET
		// ============================================
		// After file swap, clear opcache so the subprocess (update_database.php) loads
		// fresh bytecode from the new files. Also needed for any subsequent page loads.
		if (!$dry_run) {
			if (function_exists('opcache_reset')) {
				opcache_reset();
			}
			clearstatcache(true);
			if($verbose) upgrade_echo('Cleared PHP opcache and stat cache after file deployment<br>');
		}

		// ============================================
		// DATABASE MIGRATION
		// ============================================
		upgrade_echo('<br><h3>Running Database Migrations</h3>');

		if($dry_run){
			echo '<div style="border: 2px solid #0066cc; padding: 15px; margin: 10px 0; background-color: #e7f3ff; color: #004085;">';
			echo '<strong>🔍 DRY RUN:</strong> Skipping database migrations<br>';
			echo '<p>In a real upgrade, the following would happen:</p>';
			echo '<ul>';
			echo '<li>Run update_database.php with --upgrade flag</li>';
			echo '<li>Apply any pending migrations</li>';
			echo '<li>Update system version to ' . htmlspecialchars($decode_response['system_version']) . '</li>';
			echo '</ul>';
			echo '</div>';
		}
		else{
			// Run update_database.php as a SUBPROCESS to ensure a clean PHP process.
			// This is critical because upgrade.php pre-loads data model classes (via
			// AdminPage → SessionControl → ShoppingCart → products_class.php → etc.)
			// BEFORE the file swap. Those stale class definitions are locked in memory
			// by require_once and cannot be refreshed. Running in a subprocess guarantees
			// the schema updater reads the NEW class files from disk.
			$update_db_script = $live_directory . '/utils/update_database.php';
			$update_cmd = '/usr/bin/php ' . escapeshellarg($update_db_script) . ' --upgrade';
			if ($verbose) $update_cmd .= ' --verbose';

			$update_output = [];
			$update_return = 0;
			exec($update_cmd . ' 2>&1', $update_output, $update_return);

			// Display the subprocess output
			$update_output_str = implode("\n", $update_output);
			echo nl2br(htmlspecialchars($update_output_str)) . "<br>\n";

			// update_database.php uses standard exit codes: 0 = success, 1 = failure
			$migration_result = ($update_return === 0);

			if(!$migration_result){
				echo '<strong>Migration failed...reverting upgrade.</strong><br>';
				$rollback = DeploymentHelper::performRollback($site_template, true, $verbose);
				if ($rollback['success']) {
					echo "✓ Rollback completed successfully<br>";
				}
				exit(1);
			}
			else{
				echo '✓ Database migration completed successfully<br>';
			}

			//UPDATE THE SYSTEM VERSION
			try{
				$sql = "UPDATE stg_settings SET stg_value = :version WHERE stg_name = 'system_version'";
				$q = $dblink->prepare($sql);
				$q->execute([':version' => $decode_response['system_version']]);
				if($verbose){
					echo 'System version now ' . htmlspecialchars($decode_response['system_version']) . "<br>\n";
				}
			}
			catch(PDOException $e){
				echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
				echo '<strong>❌ Database Error:</strong> Failed to update system version.<br>';
				echo 'Error: ' . htmlspecialchars($e->getMessage()) . '<br>';
				echo '</div>';
				exit(1);
			}
		}

		// ============================================
		// THEME AND PLUGIN SYNC
		// ============================================
		if(!$dry_run){
			upgrade_echo('<br><h3>Syncing Themes and Plugins</h3>');

			try {
				require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
				require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

				// Sync themes
				$theme_manager = ThemeManager::getInstance();
				$theme_result = $theme_manager->sync();
				$theme_parts = array();
				if (!empty($theme_result['added'])) {
					$theme_parts[] = count($theme_result['added']) . " added";
				}
				if (!empty($theme_result['updated'])) {
					$theme_parts[] = count($theme_result['updated']) . " updated";
				}
				if (empty($theme_parts)) {
					upgrade_echo("✓ Themes synced (no changes)<br>");
				} else {
					upgrade_echo("✓ Themes synced: " . implode(", ", $theme_parts) . "<br>");
				}

				// Sync plugins
				$plugin_manager = PluginManager::getInstance();
				$plugin_result = $plugin_manager->sync();
				$plugin_parts = array();
				if (!empty($plugin_result['added'])) {
					$plugin_parts[] = count($plugin_result['added']) . " added";
				}
				if (!empty($plugin_result['updated'])) {
					$plugin_parts[] = count($plugin_result['updated']) . " updated";
				}
				if (empty($plugin_parts)) {
					upgrade_echo("✓ Plugins synced (no changes)<br>");
				} else {
					upgrade_echo("✓ Plugins synced: " . implode(", ", $plugin_parts) . "<br>");
				}
			} catch (Exception $e) {
				upgrade_echo("⚠ Theme/Plugin sync warning: " . htmlspecialchars($e->getMessage()) . "<br>");
			}
		}

		if($dry_run){
			echo '<br><div style="border: 3px solid #0066cc; padding: 20px; margin: 20px 0; background-color: #e7f3ff; color: #004085;">';
			echo '<h2 style="margin-top: 0; color: #0066cc;">✓ Dry Run Complete!</h2>';
			echo '<p><strong>The upgrade was simulated successfully.</strong></p>';
			echo '<p>No changes were made to your production system.</p>';
			echo '<p>Validation results:</p>';
			echo '<ul>';
			echo '<li>✓ Upgrade downloaded and extracted to staging</li>';
			echo '<li>✓ PHP syntax validated</li>';
			echo '<li>✓ Plugin loading tested</li>';
			echo '<li>✓ Bootstrap tested</li>';
			echo '<li>✓ Custom themes/plugins would be preserved</li>';
			echo '</ul>';
			echo '<p><strong>Target version:</strong> ' . htmlspecialchars($decode_response['system_version']) . '</p>';
			echo '<p><strong>Staging location:</strong> ' . htmlspecialchars($stage_location) . '</p>';
			echo '<p>To perform the actual upgrade, run without the --dry-run flag.</p>';
			echo '</div>';
		}
		else{
			upgrade_echo('<br><h2>✓ Upgrade Complete!</h2>');
			upgrade_echo('System upgraded to version: ' . $decode_response['system_version'] . '<br>');
		}
	}
	else{

		$session = SessionControl::get_instance();

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

		// Show upgrade server error if connection failed
		if ($upgrade_server_error) {
			echo '<div class="alert alert-danger"><strong>Connection Error:</strong> ' . $upgrade_server_error . '</div>';
		}

		$pageoptions['title'] = 'System Upgrades';
		$page->begin_box($pageoptions);

		// Get FormWriter from AdminPage (which loads the correct theme-specific FormWriter)
		$formwriter = $page->getFormWriter('form1', ['action' => '/utils/upgrade', 'method' => 'post']);
		$formwriter->begin_form();

		echo 'Local system Version: '.$settings->get_setting('system_version').'<br>';

		echo '<fieldset><h4>Confirm Upgrade</h4>';
			echo '<div class="fields full">';
			echo '<p><b>Checking upgrade source: '.htmlspecialchars($settings->get_setting('upgrade_source')).'</b></p>';
			if(!$decode_response || !isset($decode_response['system_version']) || !$decode_response['system_version']){
				echo '<div class="alert alert-danger">Unable to get the latest upgrade from the server.</div>';
			}
			else if(version_compare($decode_response['system_version'], $settings->get_setting('system_version'), '>')){
				$friendly_date = date('F j, Y', strtotime($decode_response['release_date']));
				echo '<div class="alert alert-info"><strong>Upgrade available:</strong> '. htmlspecialchars($decode_response['system_version']) . ' ('.htmlspecialchars($decode_response['upgrade_name']).') released on '. $friendly_date .' — '.htmlspecialchars($decode_response['release_notes']).'</div>';
				$formwriter->hiddeninput("confirm", '', ['value' => 1]);

				echo '<div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
				echo '<label style="display: flex; align-items: center; cursor: pointer;">';
				echo '<input type="checkbox" name="dry-run" value="1" style="margin-right: 10px; width: 18px; height: 18px;">';
				echo '<span><strong>Dry Run Mode</strong> - Download and validate the upgrade without making any changes to production</span>';
				echo '</label>';
				echo '<p style="margin: 10px 0 0 28px; color: #6c757d; font-size: 0.9em;">Files will be downloaded and validated, but not deployed. Database migrations will be skipped.</p>';
				echo '</div>';

				$formwriter->submitbutton('btn_submit', 'Submit');

			}
			else if(version_compare($decode_response['system_version'], $settings->get_setting('system_version'), '==')){
				$friendly_date = date('F j, Y', strtotime($decode_response['release_date']));
				echo '<div class="alert alert-success"><strong>Up to date.</strong> Version '. htmlspecialchars($settings->get_setting('system_version')). ' is the latest version.</div>';
				echo '<p>Latest release: '. htmlspecialchars($decode_response['system_version']) . ' ('.htmlspecialchars($decode_response['upgrade_name']).') released on '. $friendly_date .' — '.htmlspecialchars($decode_response['release_notes']).'</p>';
				$formwriter->hiddeninput("confirm", '', ['value' => 1]);

				echo '<div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
				echo '<label style="display: flex; align-items: center; cursor: pointer;">';
				echo '<input type="checkbox" name="dry-run" value="1" style="margin-right: 10px; width: 18px; height: 18px;">';
				echo '<span><strong>Dry Run Mode</strong> - Download and validate the upgrade without making any changes to production</span>';
				echo '</label>';
				echo '<p style="margin: 10px 0 0 28px; color: #6c757d; font-size: 0.9em;">Files will be downloaded and validated, but not deployed. Database migrations will be skipped.</p>';
				echo '</div>';

				$formwriter->submitbutton('btn_submit', 'Upgrade anyway');

			}
			else{
				echo '<div class="alert alert-success"><strong>Up to date.</strong> Version '. htmlspecialchars($settings->get_setting('system_version')). ' is current.</div>';
			}

			echo '</div>';
		echo '</fieldset>';
		echo $formwriter->end_form();

		// Refresh Server Archives section
		echo '<fieldset style="margin-top: 30px;"><h4>Server Archive Management</h4>';
		echo '<div class="fields full">';
		echo '<p>If you\'ve made changes to themes or plugins on the upgrade server, you can request the server to regenerate its archives.</p>';
		echo '<div id="refresh-result"></div>';
		echo '<button type="button" id="refresh-archives-btn" class="btn btn-secondary" onclick="refreshServerArchives()">';
		echo '<i class="fas fa-sync-alt"></i> Refresh Server Archives';
		echo '</button>';
		echo '</div>';
		echo '</fieldset>';

		// Add JavaScript to disable submit button after click to prevent double submission
		echo '<script>
		document.addEventListener("DOMContentLoaded", function() {
			var form = document.getElementById("form");
			if (form) {
				form.addEventListener("submit", function(e) {
					var submitButton = form.querySelector("button[type=\'submit\'], input[type=\'submit\']");
					if (submitButton && !submitButton.disabled) {
						submitButton.disabled = true;
						submitButton.style.opacity = "0.6";
						submitButton.style.cursor = "not-allowed";
						var originalText = submitButton.textContent || submitButton.value;
						if (submitButton.textContent !== undefined) {
							submitButton.textContent = "Processing...";
						} else {
							submitButton.value = "Processing...";
						}
					}
				});
			}
		});

		function refreshServerArchives() {
			var btn = document.getElementById("refresh-archives-btn");
			var resultDiv = document.getElementById("refresh-result");

			// Disable button and show loading state
			btn.disabled = true;
			btn.innerHTML = "<i class=\"fas fa-spinner fa-spin\"></i> Refreshing...";
			resultDiv.innerHTML = "";

			fetch("/utils/upgrade", {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded",
				},
				body: "refresh_archives=1"
			})
			.then(response => response.json())
			.then(data => {
				btn.disabled = false;
				btn.innerHTML = "<i class=\"fas fa-sync-alt\"></i> Refresh Server Archives";

				if (data.success) {
					resultDiv.innerHTML = "<div style=\"padding: 15px; margin: 10px 0; background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;\">" +
						"<strong>✓ Success:</strong> " + data.message +
						(data.version ? " (Version: " + data.version + ")" : "") +
						"</div>";
				} else {
					resultDiv.innerHTML = "<div style=\"padding: 15px; margin: 10px 0; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;\">" +
						"<strong>❌ Error:</strong> " + (data.error || "Unknown error") +
						"</div>";
				}
			})
			.catch(error => {
				btn.disabled = false;
				btn.innerHTML = "<i class=\"fas fa-sync-alt\"></i> Refresh Server Archives";
				resultDiv.innerHTML = "<div style=\"padding: 15px; margin: 10px 0; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;\">" +
					"<strong>❌ Error:</strong> " + error.message +
					"</div>";
			});
		}
		</script>';

		$page->end_box();

		$page->admin_footer();

	}

	function is_dir_empty($dir) {
		if (!is_dir($dir)) {
			return true; // Non-existent directory is considered empty
		}
		$files = scandir($dir);
		if ($files === false) {
			return true; // Unable to read directory, treat as empty
		}
		$numfiles = count($files);
		if($numfiles == 0 || $numfiles == 2){
			return true;
		}
		else{
			return false;
		}
	}

	/**
	 * Get list of installed stock themes (those with is_stock=true in theme.json)
	 */
	function get_installed_stock_themes($theme_dir) {
		$themes = [];
		foreach (glob($theme_dir . '/*/theme.json') as $json_file) {
			$theme_data = json_decode(file_get_contents($json_file), true);
			if ($theme_data) {
				$is_stock = $theme_data['is_stock'] ?? false;
				if ($is_stock) {
					$themes[] = basename(dirname($json_file));
				}
			}
		}
		return $themes;
	}

	/**
	 * Get list of stock themes marked as system-required (system=true in theme.json)
	 * These themes must be present on every site for core functionality to work.
	 */
	function get_system_required_themes($theme_dir) {
		$themes = [];
		foreach (glob($theme_dir . '/*/theme.json') as $json_file) {
			$theme_data = json_decode(file_get_contents($json_file), true);
			if ($theme_data) {
				$is_stock = $theme_data['is_stock'] ?? false;
				$is_system = $theme_data['system'] ?? false;
				if ($is_stock && $is_system) {
					$themes[] = basename(dirname($json_file));
				}
			}
		}
		return $themes;
	}

	/**
	 * Get list of stock plugins marked as system-required (system=true in plugin.json)
	 */
	function get_system_required_plugins($plugin_dir) {
		$plugins = [];
		foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) {
			$plugin_data = json_decode(file_get_contents($json_file), true);
			if ($plugin_data) {
				$is_stock = $plugin_data['is_stock'] ?? false;
				$is_system = $plugin_data['system'] ?? false;
				if ($is_stock && $is_system) {
					$plugins[] = basename(dirname($json_file));
				}
			}
		}
		return $plugins;
	}

	/**
	 * Get list of installed stock plugins (those with is_stock=true in plugin.json)
	 */
	function get_installed_stock_plugins($plugin_dir) {
		$plugins = [];

		// Get list of uninstalled plugins from database
		$uninstalled = [];
		try {
			require_once(PathHelper::getIncludePath('data/plugins_class.php'));
			$all_plugins = new MultiPlugin();
			$all_plugins->load();
			foreach ($all_plugins as $plugin) {
				if ($plugin->get('plg_status') === 'uninstalled') {
					$uninstalled[] = $plugin->get('plg_name');
				}
			}
		} catch (Exception $e) {
			// If we can't check database, proceed with all stock plugins
		}

		foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) {
			$plugin_data = json_decode(file_get_contents($json_file), true);
			if ($plugin_data) {
				$is_stock = $plugin_data['is_stock'] ?? false;
				$plugin_name = basename(dirname($json_file));
				// Skip uninstalled plugins
				if ($is_stock && !in_array($plugin_name, $uninstalled)) {
					$plugins[] = $plugin_name;
				}
			}
		}
		return $plugins;
	}

	/**
	 * Get detailed info about all installed themes
	 * Returns array with theme name as key and metadata as value
	 */
	function get_all_themes_info($theme_dir) {
		$themes = [];
		foreach (glob($theme_dir . '/*/theme.json') as $json_file) {
			$theme_data = json_decode(file_get_contents($json_file), true);
			$theme_name = basename(dirname($json_file));
			$themes[$theme_name] = [
				'name' => $theme_name,
				'display_name' => $theme_data['display_name'] ?? $theme_name,
				'version' => $theme_data['version'] ?? 'unknown',
				'is_stock' => $theme_data['is_stock'] ?? false,
				'will_upgrade' => ($theme_data['is_stock'] ?? false) === true
			];
		}
		ksort($themes);
		return $themes;
	}

	/**
	 * Get detailed info about all installed plugins
	 * Returns array with plugin name as key and metadata as value
	 */
	function get_all_plugins_info($plugin_dir) {
		$plugins = [];

		// Get list of uninstalled plugins from database
		$uninstalled = [];
		try {
			require_once(PathHelper::getIncludePath('data/plugins_class.php'));
			$all_plugins = new MultiPlugin();
			$all_plugins->load();
			foreach ($all_plugins as $plugin) {
				if ($plugin->get('plg_status') === 'uninstalled') {
					$uninstalled[] = $plugin->get('plg_name');
				}
			}
		} catch (Exception $e) {
			// If we can't check database, proceed without status info
		}

		foreach (glob($plugin_dir . '/*/plugin.json') as $json_file) {
			$plugin_data = json_decode(file_get_contents($json_file), true);
			$plugin_name = basename(dirname($json_file));
			$is_stock = $plugin_data['is_stock'] ?? false;
			$is_uninstalled = in_array($plugin_name, $uninstalled);
			$plugins[$plugin_name] = [
				'name' => $plugin_name,
				'display_name' => $plugin_data['display_name'] ?? $plugin_name,
				'version' => $plugin_data['version'] ?? 'unknown',
				'is_stock' => $is_stock,
				'is_uninstalled' => $is_uninstalled,
				'will_upgrade' => $is_stock && !$is_uninstalled
			];
		}
		ksort($plugins);
		return $plugins;
	}

	/**
	 * Output theme/plugin status table (works for both CLI and web)
	 */
	function output_component_status($components, $type, $is_cli) {
		if (empty($components)) {
			if ($is_cli) {
				echo "No {$type}s installed.\n";
			} else {
				echo "No {$type}s installed.<br>";
			}
			return;
		}

		if ($is_cli) {
			// CLI table output
			echo str_pad("Name", 25) . str_pad("Version", 12) . str_pad("Stock", 8) . "Status\n";
			echo str_repeat("-", 60) . "\n";
			foreach ($components as $info) {
				$is_uninstalled = $info['is_uninstalled'] ?? false;
				if ($info['will_upgrade']) {
					$status = '✓ Will upgrade';
				} elseif ($is_uninstalled) {
					$status = '⊗ Uninstalled (skipped)';
				} else {
					$status = '⊘ Skipped (not stock)';
				}
				$stock = $info['is_stock'] ? 'Yes' : 'No';
				echo str_pad($info['name'], 25) . str_pad($info['version'], 12) . str_pad($stock, 8) . $status . "\n";
			}
			echo "\n";
		} else {
			// HTML table output
			echo '<table style="border-collapse: collapse; width: 100%; margin: 10px 0; font-family: monospace;">';
			echo '<tr style="background-color: #f8f9fa; border-bottom: 2px solid #dee2e6;">';
			echo '<th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Name</th>';
			echo '<th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Version</th>';
			echo '<th style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">Stock</th>';
			echo '<th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Status</th>';
			echo '</tr>';
			foreach ($components as $info) {
				$is_uninstalled = $info['is_uninstalled'] ?? false;
				if ($info['will_upgrade']) {
					$row_style = 'background-color: #d4edda;';
					$status = '<span style="color: #155724;">✓ Will upgrade</span>';
				} elseif ($is_uninstalled) {
					$row_style = 'background-color: #e2e3e5;';
					$status = '<span style="color: #495057;">⊗ Uninstalled (skipped)</span>';
				} else {
					$row_style = 'background-color: #fff3cd;';
					$status = '<span style="color: #856404;">⊘ Skipped (not stock)</span>';
				}
				$stock = $info['is_stock']
					? '<span style="color: #155724;">Yes</span>'
					: '<span style="color: #6c757d;">No</span>';
				echo '<tr style="' . $row_style . '">';
				echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($info['name']) . '</td>';
				echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($info['version']) . '</td>';
				echo '<td style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">' . $stock . '</td>';
				echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . $status . '</td>';
				echo '</tr>';
			}
			echo '</table>';
		}
	}

	/**
	 * Download and extract a tar.gz archive to a target directory
	 */
	function download_and_extract($url, $target_dir, $item_name = null) {
		// Create temp file for download
		$temp_file = tempnam(sys_get_temp_dir(), 'joinery_dl_');

		// Download the file
		$ch = curl_init();
		$fp = fopen($temp_file, 'w');
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_FILE => $fp,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
		]);
		$success = curl_exec($ch);
		$error = curl_error($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);

		if (!$success || $http_code !== 200) {
			@unlink($temp_file);
			return ['success' => false, 'error' => "Download failed: $error (HTTP $http_code)"];
		}

		// Verify file size
		if (filesize($temp_file) < 100) {
			@unlink($temp_file);
			return ['success' => false, 'error' => 'Downloaded file too small'];
		}

		// Create target directory if needed
		if (!is_dir($target_dir)) {
			mkdir($target_dir, 0755, true);
		}

		// Extract the archive
		$tar_cmd = sprintf(
			'tar -xzf %s -C %s 2>&1',
			escapeshellarg($temp_file),
			escapeshellarg($target_dir)
		);
		$output = [];
		$exit_code = 0;
		exec($tar_cmd, $output, $exit_code);

		// Clean up temp file
		@unlink($temp_file);

		if ($exit_code !== 0) {
			return ['success' => false, 'error' => 'Extract failed: ' . implode("\n", $output)];
		}

		return ['success' => true];
	}

	/**
	 * Request the upgrade server to refresh its archives
	 * This triggers regeneration of all archives for the current version
	 *
	 * @return array Response with 'success', 'message' or 'error', and optionally 'version'
	 */
	function request_archive_refresh() {
		$settings = Globalvars::get_instance();

		$upgrade_source = $settings->get_setting('upgrade_source');

		if (empty($upgrade_source)) {
			return ['success' => false, 'error' => 'Upgrade source not configured'];
		}

		$url = rtrim($upgrade_source, '/') . '/utils/publish_upgrade?refresh-archives=1';

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120, // Archive refresh may take time
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Force IPv4 for consistent IP whitelisting
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error) {
			return ['success' => false, 'error' => 'Connection failed: ' . $curl_error];
		}

		if ($http_code !== 200) {
			$error = json_decode($response, true);
			return ['success' => false, 'error' => $error['error'] ?? 'Unknown error (HTTP ' . $http_code . ')'];
		}

		$result = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['success' => false, 'error' => 'Invalid response from server'];
		}

		return $result;
	}

?>
