<?php
	require_once( __DIR__ . '/../includes/PathHelper.php');

	require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

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

	// Parse CLI arguments if running from command line
	$is_cli = (php_sapi_name() === 'cli');
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

		$sourceFile = $decode_response['upgrade_location'];

		$file_download_location = $full_site_dir.'/uploads/'.basename($sourceFile);

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

		// ============================================
		// PRE-DEPLOYMENT VALIDATION
		// ============================================
		echo '<br><h3>Pre-deployment Validation</h3>';

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

		echo '<br><h3>Preserving Custom Themes/Plugins</h3>';

		// Preserve custom themes/plugins
		$result = DeploymentHelper::preserveCustomThemesPlugins($stage_directory, $backup_directory, $verbose);
		if ($result['success']) {
			echo "✓ Themes: {$result['themes_preserved']} preserved, {$result['themes_updated']} updated, {$result['themes_added']} added<br>";
			echo "✓ Plugins: {$result['plugins_preserved']} preserved, {$result['plugins_updated']} updated, {$result['plugins_added']} added<br>";
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
		echo '<br><h3>Deploying Upgrade</h3>';

		if($dry_run){
			echo '<div style="border: 2px solid #0066cc; padding: 15px; margin: 10px 0; background-color: #e7f3ff; color: #004085;">';
			echo '<strong>🔍 DRY RUN:</strong> Skipping file deployment (files remain in staging area)<br>';
			echo '</div>';
		}
		else{
			//CLEAR BACKUP AREA
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
			echo 'Setting '.$stage_location.' to 770<br>';
			exec("chmod -R 770 $stage_location");
			echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';

			echo 'Moving '.$live_directory. ' to '. $backup_directory.'<br>';
			echo 'Moving '.$stage_directory. ' to '. $live_directory.'<br>';

			exec("mv $live_directory_contents $backup_directory");
			exec("mv $stage_directory_contents $live_directory");
			exec("chmod -R 770 $live_directory");
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
		}

		// ============================================
		// COMPOSER VALIDATION
		// ============================================
		echo '<br><h3>Validating Composer Dependencies</h3>';

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
		// DATABASE MIGRATION
		// ============================================
		echo '<br><h3>Running Database Migrations</h3>';

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
			//DO THE MIGRATION
			$noautorun = 1;  //DO NOT AUTORUN THE update_database include
			require_once('update_database.php');

			// NOTE: Using backwards exit codes for now (Phase 2 will fix this)
			// update_database() returns bool (true = success, false = failure)
			// but the script uses exit(1) for success and exit(0) for failure
			$migration_result = update_database($verbose, true, false);

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
			echo '<br><h2>✓ Upgrade Complete!</h2>';
			echo 'System upgraded to version: ' . $decode_response['system_version'] . '<br>';
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

		$pageoptions['title'] = 'System Upgrades';
		$page->begin_box($pageoptions);

		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
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

				echo '<div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
				echo '<label style="display: flex; align-items: center; cursor: pointer;">';
				echo '<input type="checkbox" name="dry-run" value="1" style="margin-right: 10px; width: 18px; height: 18px;">';
				echo '<span><strong>Dry Run Mode</strong> - Download and validate the upgrade without making any changes to production</span>';
				echo '</label>';
				echo '<p style="margin: 10px 0 0 28px; color: #6c757d; font-size: 0.9em;">Files will be downloaded and validated, but not deployed. Database migrations will be skipped.</p>';
				echo '</div>';

				echo $formwriter->start_buttons();
				echo $formwriter->new_form_button('Submit');
				echo $formwriter->end_buttons();

			}
			else if($decode_response['system_version'] == $settings->get_setting('system_version')){
				echo '<p>Latest upgrade available: '. $decode_response['system_version'] . '('.$decode_response['upgrade_name'].') released on '. $decode_response['release_date'] .' - '.$decode_response['release_notes'].' </p>';
				echo 'Your version '. $settings->get_setting('system_version'). ' is up to date.  No upgrade needed.  ';
				echo $formwriter->hiddeninput("confirm", 1);

				echo '<div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px;">';
				echo '<label style="display: flex; align-items: center; cursor: pointer;">';
				echo '<input type="checkbox" name="dry-run" value="1" style="margin-right: 10px; width: 18px; height: 18px;">';
				echo '<span><strong>Dry Run Mode</strong> - Download and validate the upgrade without making any changes to production</span>';
				echo '</label>';
				echo '<p style="margin: 10px 0 0 28px; color: #6c757d; font-size: 0.9em;">Files will be downloaded and validated, but not deployed. Database migrations will be skipped.</p>';
				echo '</div>';

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
