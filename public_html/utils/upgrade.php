<?php
	/**
	 * EXTENSION FLAG MODEL (themes and plugins)
	 *
	 *   receives_upgrades   — operator on the *target* site says: replace this on
	 *                         upgrade. Default true. Set false to keep a local fork.
	 *                         Lives in the on-disk manifest (theme.json/plugin.json)
	 *                         and is mirrored to the DB column (thm_/plg_receives_upgrades).
	 *                         The admin "Mark Preserved/Upgradable" buttons write both.
	 *
	 *   included_in_publish — operator on the *source* site says: include this in the
	 *                         published archives. Default true. Set false for dev-only
	 *                         or deprecated extensions.
	 *
	 *   is_system           — flagged in theme.json/plugin.json as "must always be
	 *                         present on every site" (e.g. the admin theme). Always
	 *                         pulled fresh on upgrade regardless of receives_upgrades.
	 */

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

	if ($is_cli) {
		// Parse command line arguments
		$options = getopt("", ["verbose", "force-upgrade", "confirm-downgrade"]);
		$verbose = isset($options['verbose']);
		$force_upgrade = isset($options['force-upgrade']);
		$confirm_downgrade_cli = isset($options['confirm-downgrade']);
	} else {
		// Use existing $_GET/$_POST parsing
		$verbose = isset($_REQUEST['verbose']);
		$force_upgrade = isset($_REQUEST['force-upgrade']);
	}

	// Helper function to output and flush immediately
	function upgrade_echo($message) {
		echo $message;
		if (ob_get_level() > 0) {
			ob_flush();
		}
		flush();
	}

	// Section header: emits as plain heading on CLI, as <h3> for web.
	function out_step($title) {
		global $is_cli;
		if ($is_cli) {
			echo "\n=== " . strtoupper($title) . " ===\n";
		} else {
			echo '<br><h3>' . htmlspecialchars($title) . '</h3>';
		}
	}

	// Non-fatal styled message: warning, info, or success. Emits as plain prefix on CLI.
	// $body may contain raw HTML (caller is responsible for any escaping it needs).
	function out_alert($level, $title, $body = '') {
		global $is_cli;
		$prefix = ['warning' => '[WARN] ', 'info' => '[INFO] ', 'success' => '[OK] '];
		if ($is_cli) {
			echo ($prefix[$level] ?? '') . $title . "\n";
			if ($body !== '') echo "  " . strip_tags(str_replace('<br>', "\n  ", $body)) . "\n";
			return;
		}
		$styles = [
			'warning' => 'border:2px solid #856404;background:#fff3cd;color:#856404',
			'info'    => 'border:2px solid #0c5460;background:#d1ecf1;color:#0c5460',
			'success' => 'border:2px solid #155724;background:#d4edda;color:#155724',
		];
		$style = $styles[$level] ?? $styles['info'];
		echo '<div style="padding:10px;margin:10px 0;' . $style . '">';
		echo '<strong>' . htmlspecialchars($title) . '</strong>';
		if ($body !== '') echo '<br>' . $body;
		echo '</div>';
	}

	// Abort the upgrade with a styled error message; clears staging unless told not to.
	// Use for pre-deployment failures (validation, missing prerequisites, bad downloads).
	// Post-deployment failures should use DeploymentHelper::performRollback() instead.
	function upgrade_abort($title, $detail = '', $clear_staging = true) {
		global $is_cli, $stage_location;
		if ($is_cli) {
			echo "ERROR: $title\n";
			if ($detail !== '') echo "  $detail\n";
		} else {
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 10px 0; background-color: #f8d7da; color: #721c24;">';
			echo '<strong>' . htmlspecialchars($title) . '</strong>';
			if ($detail !== '') echo '<br>' . $detail;
			echo '</div>';
		}
		if ($clear_staging && !empty($stage_location) && is_dir($stage_location)) {
			exec('rm -rf ' . escapeshellarg($stage_location) . '/*');
		}
		exit(1);
	}

	$stage_location = $full_site_dir.'/uploads/upgrades/';
	$live_directory = $full_site_dir. '/public_html';
	$backup_directory = $full_site_dir. '/public_html_last';
	$stage_directory = $stage_location. 'public_html';

	//IF WE ARE ACTING AS A SERVER, AND SOMEONE REQUESTS THE INFO FOR UPGRADING
	$is_upgrade_server = $settings->get_setting('upgrade_server_active') || PluginHelper::isPluginActive('server_manager');
	if(isset($_GET['serve-upgrade']) && $_GET['serve-upgrade'] && $is_upgrade_server){
		require_once(PathHelper::getIncludePath('/data/upgrades_class.php'));
		$response = array();
		$response['system_version'] = $settings->get_setting('system_version');
		$major = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'));
		$major->load();
		$upgrade =  $major->get(0);
		$version = $upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version'). '.'. $upgrade->get('upg_patch_version');
		$response['system_version'] = $version;
		$response['upgrade_name'] = $upgrade->get('upg_name');
		$response['release_date'] = $upgrade->get('upg_create_time');
		$response['release_notes'] = $upgrade->get('upg_release_notes');

		// Core archive location (upg_name now stores core filename)
		$response['core_location'] = LibraryFunctions::get_absolute_url('/static_files/' . $upgrade->get('upg_name'));

		// Required system themes/plugins — these must be downloaded even if
		// the target site doesn't have them installed yet
		$response['required_themes']  = get_system_required_extensions($live_directory . '/theme',   'theme');
		$response['required_plugins'] = get_system_required_extensions($live_directory . '/plugins', 'plugin');

		// Published archives manifest: every plugin/theme this source has published
		// as a tar.gz (i.e., manifest had included_in_publish=true at publish time).
		// Prod uses this list as the source of truth for which archives to download
		// — independent of prod's current state — so renames and additions flow
		// through cleanly. See specs/upgrade_pipeline_rename_gap.md.
		// publish_upgrade.php writes archives to {site_dir}/static_files/
		// (sibling of public_html, not under it).
		$static_files_dir = $full_site_dir . '/static_files';
		$response['published_plugins'] = get_published_archives($static_files_dir . '/plugins', 'plugins');
		$response['published_themes']  = get_published_archives($static_files_dir . '/themes',  'themes');

		header("Content-Type: application/json");
		http_response_code(200);

		$response = json_encode($response);
		echo $response . PHP_EOL;
		exit;
	}

	//CHECK FOR EXISTENCE OF ALL NEEDED DIRECTORIES
	if(!file_exists($live_directory)){
		echo $live_directory. ' (live_directory) does not exist or is not readable by the current process.';
		exit;
	}

	if (!is_writable($live_directory)) {
		echo $live_directory . ' (live_directory) is not writable by the current process. Aborting upgrade.<br>';
		echo 'Owner: ' . posix_getpwuid(fileowner($live_directory))['name'] . '; permissions: ' . substr(sprintf('%o', fileperms($live_directory)), -3) . '<br>';
		exit;
	}

	$session = SessionControl::get_instance();
	if (!$is_cli) {
		$session->check_permission(8);
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
						out_alert('warning', 'DOWNGRADE IN PROGRESS', htmlspecialchars($current_version) . ' → ' . htmlspecialchars($server_version));
					}
				}
			}
			else if($version_compare == 0){
				out_alert('info', 'SAME VERSION', 'Current version (' . htmlspecialchars($current_version) . ') is the same as server version.');
			}
			else{
				out_alert('success', 'UPGRADE', htmlspecialchars($current_version) . ' → ' . htmlspecialchars($server_version));
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

		if (!$sourceFile) {
			echo '<div style="border: 2px solid #dc3545; padding: 15px; margin: 20px 0; background-color: #f8d7da; color: #721c24;">';
			echo '<strong>❌ Invalid Response:</strong> Upgrade server did not provide a core_location.<br>';
			echo '</div>';
			exit(1);
		}

		// =====================================================
		// SELF-UPDATE RESUME DETECTION
		// =====================================================
		// If staging already has the target version's tarball extracted, a previous
		// run must have self-updated deployment files and asked for a re-run.
		// Skip download/extraction; pick up from the self-update check below.
		$resuming_after_self_update = false;
		$staged_version_file = $stage_directory . '/VERSION';
		if (file_exists($staged_version_file) && is_dir($stage_directory) && !is_dir_empty($stage_directory)) {
			$staged_version = trim(file_get_contents($staged_version_file));
			if ($staged_version !== '' && $staged_version === ($decode_response['system_version'] ?? '')) {
				$resuming_after_self_update = true;
				out_alert('info', 'Resuming upgrade after self-update', 'Staging already contains version ' . htmlspecialchars($staged_version) . '.');
			}
		}

		// Determine which themes/plugins to download.
		//
		// Source-of-truth is the source's published-archives manifest
		// (published_themes / published_plugins in the ?serve-upgrade response)
		// — see specs/upgrade_pipeline_rename_gap.md. Each entry has its own URL,
		// so a rename or addition on the source side flows through cleanly.
		$source_published_themes  = $decode_response['published_themes']  ?? [];
		$source_published_plugins = $decode_response['published_plugins'] ?? [];

		// Download all locally-installed extensions that the source has published.
		// The receives_upgrades flag is NOT consulted here — the staged manifest
		// decides preservation in copyPreservedToStaging(). Filtering by the live
		// flag here would create a bootstrapping deadlock where a theme could never
		// receive a receives_upgrades change via an upgrade package.
		$source_published_theme_names  = array_column($source_published_themes,  'name');
		$source_published_plugin_names = array_column($source_published_plugins, 'name');
		$themes_to_download  = array_values(array_intersect(
			get_installed_extension_names($live_directory . '/theme',   'theme'),
			$source_published_theme_names
		));
		$plugins_to_download = array_values(array_intersect(
			get_installed_extension_names($live_directory . '/plugins', 'plugin'),
			$source_published_plugin_names
		));
		// Map name → archive URL for the download loop.
		$theme_url_by_name  = array_column($source_published_themes,  'url', 'name');
		$plugin_url_by_name = array_column($source_published_plugins, 'url', 'name');

		// Get detailed info for status display.
		// Pass published names so will_upgrade reflects the package, not the live flag.
		$all_themes_info  = get_installed_extension_info($live_directory . '/theme',   'theme', $source_published_theme_names);
		$all_plugins_info = get_installed_extension_info($live_directory . '/plugins', 'plugin', $source_published_plugin_names);

		if (!$resuming_after_self_update) {

		out_step('Downloading Upgrade Components');

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
		$themes_to_upgrade_count = count($themes_to_download);
		$total_plugins = count($all_plugins_info);
		$plugins_to_upgrade_count = count($plugins_to_download);

		if ($is_cli) {
			echo "Summary: {$themes_to_upgrade_count}/{$total_themes} themes and {$plugins_to_upgrade_count}/{$total_plugins} plugins will be upgraded\n\n";
		} else {
			upgrade_echo("<p><strong>Summary:</strong> {$themes_to_upgrade_count}/{$total_themes} themes and {$plugins_to_upgrade_count}/{$total_plugins} plugins will be upgraded</p>");
		}

		upgrade_echo('Downloading core archive: ' . htmlspecialchars($sourceFile) . '<br>');
		flush();

		$file_download_location = $full_site_dir . '/uploads/' . basename($sourceFile);

		// Download core archive
		$new_file = fopen($file_download_location, "w");
		if (!$new_file) {
			upgrade_abort('File Error', 'Cannot create download file at ' . htmlspecialchars($file_download_location), false);
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
			upgrade_abort('Core Download Failed', htmlspecialchars($curl_error ?: 'HTTP ' . $status['http_code']), false);
		}

		$core_size_mb = round(filesize($file_download_location) / 1024 / 1024, 2);
		upgrade_echo("✓ Core archive downloaded ({$core_size_mb} MB)<br>");

		// =====================================================
		// EARLY SELF-UPDATE — refresh upgrade.php first
		// =====================================================
		// Extract just utils/upgrade.php from the tarball and self-update
		// BEFORE any failure-prone step (staging-clear, extract, sync, etc).
		// This guarantees that any bug in upgrade.php is one upgrade attempt
		// away from being fixed automatically — no manual intervention needed.
		//
		// Cost: one extra tarball download per upgrade-with-self-update (the
		// re-run will download again, since staging is still empty). Worth it
		// to keep the pipeline self-healing.
		//
		// The post-extract self-update block below still handles the other
		// deployment files (DatabaseUpdater, DeploymentHelper, update_database).
		$early_su_tmp = sys_get_temp_dir() . '/joinery_su_' . getmypid();
		@mkdir($early_su_tmp, 0770, true);
		exec(sprintf(
			'tar -xzf %s -C %s utils/upgrade.php 2>&1',
			escapeshellarg($file_download_location),
			escapeshellarg($early_su_tmp)
		), $early_su_out, $early_su_exit);

		$early_su_staged = $early_su_tmp . '/utils/upgrade.php';
		$live_upgrade_php = $live_directory . '/utils/upgrade.php';

		if ($early_su_exit === 0
			&& file_exists($early_su_staged)
			&& file_exists($live_upgrade_php)
			&& md5_file($early_su_staged) !== md5_file($live_upgrade_php)
		) {
			if (@copy($early_su_staged, $live_upgrade_php)) {
				if (function_exists('opcache_invalidate')) {
					opcache_invalidate($live_upgrade_php, true);
				}
				@unlink($early_su_staged);
				@rmdir($early_su_tmp . '/utils');
				@rmdir($early_su_tmp);

				if ($is_cli) {
					echo "\n════════════════════════════════════════════════════════════\n";
					echo "  UPGRADE PIPELINE REFRESHED — PLEASE RE-RUN THE UPGRADE\n";
					echo "════════════════════════════════════════════════════════════\n\n";
					echo "  utils/upgrade.php has been refreshed from the source.\n";
					echo "  Re-run the same command to continue with the new orchestrator.\n\n";
				} else {
					out_step('Upgrade Pipeline Refreshed');
					echo '<div style="border: 3px solid #0066cc; padding: 20px; margin: 20px 0; background-color: #e7f3ff; color: #004085;">';
					echo '<h2 style="margin-top: 0; color: #0066cc;">Upgrade Pipeline Refreshed</h2>';
					echo '<p>utils/upgrade.php has been refreshed from the source.</p>';
					echo '<p><strong>Please click the button below to continue with the new orchestrator.</strong></p>';
					echo '<form method="POST" action="/utils/upgrade">';
					echo '<input type="hidden" name="confirm" value="1">';
					if ($force_upgrade) echo '<input type="hidden" name="force-upgrade" value="1">';
					if ($verbose) echo '<input type="hidden" name="verbose" value="1">';
					echo '<button type="submit" style="background-color: #0066cc; color: white; padding: 12px 24px; font-size: 16px; border: none; cursor: pointer; border-radius: 4px;">Continue Upgrade</button>';
					echo '</form>';
					echo '</div>';
				}
				exit(0);
			} else {
				error_log('Early self-update: copy of upgrade.php failed — proceeding with live version');
			}
		}
		// Cleanup tmp regardless
		@unlink($early_su_staged);
		@rmdir($early_su_tmp . '/utils');
		@rmdir($early_su_tmp);

		//CLEAR OLD STAGED FILES — bulletproof: nuke the directory entirely
		//and recreate empty. Robust against dotfiles, restrictive perms,
		//weird ownership, anything short of an immutable bit on the parent.
		if($verbose) upgrade_echo('Clearing staging area: '.$stage_location.'<br>');
		if(file_exists($stage_location)){
			exec('rm -rf ' . escapeshellarg($stage_location) . ' 2>&1', $clear_out, $clear_exit);
			if (file_exists($stage_location)) {
				echo 'Failed to clear staging location:'.$stage_location.'...aborting.<br>';
				echo 'Permissions of '.$stage_location.': '.substr(sprintf('%o', fileperms($stage_location)), -4).'<br>';
				if (!empty($clear_out)) {
					echo 'rm output: ' . htmlspecialchars(implode(' | ', $clear_out)) . '<br>';
				}
				exit;
			}
		}
		if(!mkdir($stage_location, 0770, true)){
			echo 'Failed to create staging location: '.$stage_location.'...aborting.<br>';
			exit;
		}
		if($verbose) upgrade_echo('Staging area cleared<br>');

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

		// Prefer the VERSION file baked into the tarball over the server's JSON response.
		// If it exists, it's authoritative. The JSON response stays as back-compat for
		// tarballs published before VERSION was introduced.
		$staged_version_file = $stage_directory . '/VERSION';
		if (file_exists($staged_version_file)) {
			$staged_version = trim(file_get_contents($staged_version_file));
			if ($staged_version !== '' && preg_match('/^\d+\.\d+\.\d+$/', $staged_version)) {
				if (!empty($decode_response['system_version']) && $decode_response['system_version'] !== $staged_version) {
					if ($verbose) upgrade_echo("Version mismatch: server said " . htmlspecialchars($decode_response['system_version']) . ", tarball VERSION says " . htmlspecialchars($staged_version) . ". Trusting tarball.<br>");
				}
				$decode_response['system_version'] = $staged_version;
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
				out_step('Self-Update Required');
				echo 'The following deployment files have changed in the new version:<br>';
			}
			foreach ($files_needing_update as $f) {
				echo ($is_cli ? '  - ' : '  &bull; ') . htmlspecialchars($f) . ($is_cli ? "\n" : '<br>');
			}

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
				out_alert('warning', 'Failed to copy some files: ' . implode(', ', $copy_errors),
					'Continuing with current versions.');
				// Don't abort — proceed with old code, which is better than failing entirely
			} else {
				// Resume detection on re-run finds the staged VERSION file matching the
				// target system_version and skips the download step. No marker needed.

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
					echo '<button type="submit" style="background-color: #0066cc; color: white; padding: 12px 24px; font-size: 16px; border: none; cursor: pointer; border-radius: 4px;">Continue Upgrade</button>';
					echo '</form>';
					echo '</div>';
				}

				exit(0);
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
			out_step('Downloading Individual Themes and Plugins');

			$skipped_items = [];
			$downloaded_count  = download_extension_set($themes_to_download,  $theme_url_by_name,  'theme',  'theme',   $stage_directory, $skipped_items);
			$downloaded_count += download_extension_set($plugins_to_download, $plugin_url_by_name, 'plugin', 'plugins', $stage_directory, $skipped_items);

			upgrade_echo("✓ Downloaded {$downloaded_count} theme/plugin archives<br>");

			if (!empty($skipped_items)) {
				$body = '(not available from upgrade server)<br><ul style="margin-bottom: 0;">';
				foreach ($skipped_items as $item) {
					$body .= '<li>' . htmlspecialchars($item) . '</li>';
				}
				$body .= '</ul><br><small>These items were not updated. If they are deprecated, consider removing them.</small>';
				out_alert('warning', count($skipped_items) . ' item(s) skipped', $body);
			}
		}

		// ============================================
		// PRE-DEPLOYMENT VALIDATION
		// ============================================
		out_step('Pre-deployment Validation');

		// Validate tarball structure (heuristic check for obvious issues)
		$result = DeploymentHelper::validateTarballStructure($stage_directory, $verbose);
		if (!$result['success']) {
			$detail = 'The upgrade package does not have the expected structure (likely corrupted download or wrong file):<br>'
				. '<ul>' . implode('', array_map(function($e){ return '<li>' . htmlspecialchars($e) . '</li>'; }, $result['errors'])) . '</ul>';
			upgrade_abort('Tarball Validation Failed', $detail);
		}
		echo "✓ Tarball structure validation passed<br>";
		if (!empty($result['warnings'])) {
			$body = implode('<br>', array_map(function($w){ return '• ' . htmlspecialchars($w); }, $result['warnings']));
			out_alert('warning', 'Warnings', $body);
		}

		// Check that active theme is available
		$active_theme = $settings->get_setting('theme_template');
		if ($active_theme) {
			$staged_theme_path = $stage_directory . '/theme/' . $active_theme;
			$live_theme_path = $live_directory . '/theme/' . $active_theme;

			if (!is_dir($staged_theme_path)) {
				// Theme not in staging - check if it's preserved-on-deploy in live
				$theme_will_be_preserved = false;
				$preservation_reason = '';

				if (is_dir($live_theme_path)) {
					// Theme exists in live - check the receives_upgrades flag
					$manifest_path = $live_theme_path . '/theme.json';
					if (file_exists($manifest_path)) {
						$manifest = json_decode(file_get_contents($manifest_path), true);
						if (isset($manifest['receives_upgrades']) && $manifest['receives_upgrades'] === false) {
							$theme_will_be_preserved = true;
							$preservation_reason = 'marked preserved-on-deploy (receives_upgrades=false)';
						}
					}
				}

				if ($theme_will_be_preserved) {
					out_alert('warning', "Active theme '$active_theme' is not in the upgrade package",
						'However, it will be preserved because it is ' . $preservation_reason . '.');
				} else {
					$detail = "The currently active theme '<strong>" . htmlspecialchars($active_theme) . "</strong>' is not included in this upgrade package. "
						. "If the upgrade proceeds, your site would lose its theme.<br><br>"
						. "To fix: republish the upgrade with the theme selected, switch to a different theme first, "
						. "or mark the theme preserved with <code>\"receives_upgrades\": false</code> in its theme.json.";
					upgrade_abort('UPGRADE BLOCKED: Active Theme Missing', $detail);
				}
			} else {
				echo "✓ Active theme '" . htmlspecialchars($active_theme) . "' found in upgrade package<br>";
			}
		}

		// Validate PHP syntax
		$result = DeploymentHelper::validatePHPSyntax($stage_directory, $verbose);
		if (!$result['success']) {
			$lines = [];
			foreach ($result['errors'] as $error) {
				$lines[] = '• ' . htmlspecialchars($error['file']) . ' (line ' . $error['line'] . '): ' . htmlspecialchars($error['message']);
			}
			upgrade_abort('PHP Syntax Validation FAILED (' . $result['files_checked'] . ' files checked, ' . count($result['errors']) . ' errors)', implode('<br>', $lines));
		} else {
			echo "✓ PHP syntax validation passed ({$result['files_checked']} files)<br>";
		}

		// Test plugin loading
		$result = DeploymentHelper::testPluginLoading($stage_directory, $verbose);
		if (!$result['success']) {
			$lines = [];
			foreach ($result['errors'] as $error) {
				$type_label = ($error['type'] === 'syntax') ? 'SYNTAX' : strtoupper($error['type']);
				$lines[] = "• [$type_label] " . htmlspecialchars($error['file']) . ': ' . htmlspecialchars($error['message']);
			}
			upgrade_abort('Plugin Loading Tests FAILED', implode('<br>', $lines));
		} else {
			echo "✓ Plugin loading tests passed ({$result['files_checked']} plugins)<br>";
		}

		// Test bootstrap
		$result = DeploymentHelper::testBootstrap($stage_directory, $verbose);
		if (!$result['success']) {
			upgrade_abort('Bootstrap Test FAILED', htmlspecialchars($result['error']));
		} else {
			echo "✓ Bootstrap test passed (loaded: " . implode(', ', $result['components_loaded']) . ")<br>";
		}

		out_step('Preserving Themes/Plugins');

		// Copy preserved-on-deploy themes/plugins from live into staging BEFORE the mv
		// This ensures preserved extensions are carried into staging before the swap
		// - Themes/plugins with receives_upgrades=false are copied
		// - Themes/plugins not in staging (uploaded directly) are copied
		// - Themes/plugins with receives_upgrades=true are left alone (will be updated from staging)
		$result = DeploymentHelper::copyPreservedToStaging($live_directory, $stage_directory, $verbose);
		if ($result['success']) {
			echo "✓ Themes: {$result['themes_copied']} preserved, {$result['themes_skipped']} will update from staging<br>";
			echo "✓ Plugins: {$result['plugins_copied']} preserved, {$result['plugins_skipped']} will update from staging<br>";
		} else {
			$lines = [];
			foreach ($result['errors'] as $error) {
				$lines[] = '• ' . htmlspecialchars($error);
			}
			$lines[] = '';
			$lines[] = 'Preserved themes/plugins could not be carried over. Deploying without them would cause data loss.';
			upgrade_abort('Theme/Plugin Preservation Failed', implode('<br>', $lines));
		}

		// ============================================
		// DEPLOYMENT
		// ============================================
		out_step('Deploying Upgrade');

		//CLEAR OR CREATE BACKUP AREA
		if($verbose) echo 'Clearing backup area: '.$backup_directory.'<br>';
			if (!is_dir($backup_directory)) {
				if (!mkdir($backup_directory, 0770, true)) {
					upgrade_abort('Could not create backup directory', htmlspecialchars($backup_directory), false);
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

			// Move CONTENTS of live → backup. Use find rather than a `mv glob`
			// so dotfiles (.htaccess, .well-known, etc.) come along, and so
			// the bind-mounted directory itself stays in place. mv handles
			// cross-device fallback (copy+unlink) per file automatically.
			$mv_output = [];
			$mv_exit = 0;
			exec(sprintf(
				'find %s -mindepth 1 -maxdepth 1 -exec mv -t %s {} + 2>&1',
				escapeshellarg($live_directory),
				escapeshellarg($backup_directory)
			), $mv_output, $mv_exit);
			if ($mv_exit !== 0) {
				upgrade_abort('Backup Failed', 'Could not move live files to backup. Error: ' . htmlspecialchars(implode(' ', $mv_output)), false);
			}

			// Move CONTENTS of stage → live (same approach).
			$mv_output = [];
			$mv_exit = 0;
			exec(sprintf(
				'find %s -mindepth 1 -maxdepth 1 -exec mv -t %s {} + 2>&1',
				escapeshellarg($stage_directory),
				escapeshellarg($live_directory)
			), $mv_output, $mv_exit);
			if ($mv_exit !== 0) {
				// Attempt to restore from backup before aborting. Same find
				// approach so dotfiles come back too. (The outer rollback path
				// below also calls performRollback() if the deploy is judged
				// failed; this inline restore is a fast in-place repair when
				// only the second mv failed.)
				exec(sprintf(
					'find %s -mindepth 1 -maxdepth 1 -exec mv -t %s {} + 2>&1',
					escapeshellarg($backup_directory),
					escapeshellarg($live_directory)
				));
				upgrade_abort('Deployment Failed', 'Could not move staged files to live. Error: ' . htmlspecialchars(implode(' ', $mv_output)) . '. Rollback attempted.', false);
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

		//CLEAR OLD STAGED FILES — same bulletproof rm -rf + recreate as
		//the pre-deploy clear above. Non-fatal here (deploy already succeeded).
		if($verbose) echo 'Clearing staging area: '.$stage_location.'...<br>';
			if(file_exists($stage_location)){
				exec('rm -rf ' . escapeshellarg($stage_location) . ' 2>&1');
				if (file_exists($stage_location)) {
					out_alert('warning', 'Failed to clear staging location: ' . htmlspecialchars($stage_location),
						'Permissions: ' . substr(sprintf('%o', fileperms($stage_location)), -4) . '<br>'
						. 'Continuing with upgrade — staging cleanup can be done manually later.');
				} else {
					@mkdir($stage_location, 0770, true);
					if($verbose) echo 'Staging area cleared<br>';
				}
			}

			// Remove this run's downloaded core archive (staging is cleared and we're
			// past the point of needing the tarball). isset() guards the self-update
			// resume path, which skips the download block and never sets this.
			if (isset($file_download_location) && file_exists($file_download_location)) {
				if (@unlink($file_download_location)) {
					if ($verbose) upgrade_echo('Removed downloaded core archive: ' . basename($file_download_location) . '<br>');
				}
			}

		// ============================================
		// COMPOSER VALIDATION
		// ============================================
		out_step('Validating Composer Dependencies');

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

		// ============================================
		// OPCACHE RESET
		// ============================================
		// After file swap, clear opcache so the subprocess (update_database.php) loads
		// fresh bytecode from the new files. Also needed for any subsequent page loads.
		if (function_exists('opcache_reset')) {
			opcache_reset();
		}
		clearstatcache(true);
		if($verbose) upgrade_echo('Cleared PHP opcache and stat cache after file deployment<br>');

		// ============================================
		// DATABASE MIGRATION
		// ============================================
		out_step('Running Database Migrations');

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

			//UPDATE THE SYSTEM VERSION (upsert — fresh installs have no existing row)
			// Requires the unique constraint on stg_name — ensured by update_database running first.
			try{
				$sql = "INSERT INTO stg_settings (stg_name, stg_value) VALUES ('system_version', :version)
				        ON CONFLICT (stg_name) DO UPDATE SET stg_value = EXCLUDED.stg_value";
				$q = $dblink->prepare($sql);
				$q->execute([':version' => $decode_response['system_version']]);
				if($verbose){
					echo 'System version now ' . htmlspecialchars($decode_response['system_version']) . "<br>\n";
				}
			}
			catch(PDOException $e){
				// Log the error but don't roll back — deployment and DB updates already succeeded.
				// system_version was already set correctly by update_database above.
				out_alert('warning', 'Could not re-confirm system version after upgrade (non-fatal — update_database already set it)',
					'Error: ' . htmlspecialchars($e->getMessage()));
			}

		// ============================================
		// THEME AND PLUGIN SYNC
		// ============================================
		out_step('Syncing Themes and Plugins');

			try {
				require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));
				require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

				// Sync themes — pass source_manifest so the manager can mark
				// any receives_upgrades=true theme no longer in the source as 'stale'.
				$theme_manager = ThemeManager::getInstance();
				$theme_result = $theme_manager->sync([
					'source_manifest' => array_column($source_published_themes, 'name'),
				]);
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

				// Sync plugins — pass source_manifest so the manager can mark
				// any receives_upgrades=true plugin no longer in the source as 'stale'.
				$plugin_manager = PluginManager::getInstance();
				$plugin_result = $plugin_manager->sync([
					'source_manifest' => array_column($source_published_plugins, 'name'),
				]);
				$plugin_parts = array();
				if (!empty($plugin_result['added'])) {
					$plugin_parts[] = count($plugin_result['added']) . " added";
				}
				if (!empty($plugin_result['updated'])) {
					$plugin_parts[] = count($plugin_result['updated']) . " updated";
				}
				if (!empty($plugin_result['table_messages'])) {
					$plugin_parts[] = count($plugin_result['table_messages']) . " table change(s)";
				}
				if (!empty($plugin_result['migration_messages'])) {
					$plugin_parts[] = count($plugin_result['migration_messages']) . " migration(s)";
				}
				if (empty($plugin_parts)) {
					upgrade_echo("✓ Plugins synced (no changes)<br>");
				} else {
					upgrade_echo("✓ Plugins synced: " . implode(", ", $plugin_parts) . "<br>");
				}
				if (!empty($plugin_result['table_messages'])) {
					foreach ($plugin_result['table_messages'] as $tm) {
						upgrade_echo("  Table: " . htmlspecialchars($tm) . "<br>");
					}
				}
				if (!empty($plugin_result['migration_messages'])) {
					foreach ($plugin_result['migration_messages'] as $mm) {
						upgrade_echo("  Migration: " . htmlspecialchars($mm) . "<br>");
					}
				}
				// Stale reconciliation — see specs/upgrade_pipeline_rename_gap.md.
				// Plugins/themes this site is set to receive upgrades for, but
				// that the source's published-archive manifest no longer advertises
				// were flagged 'stale' inside sync() above. Surface the totals.
				$plugin_marked = (int)($plugin_result['stale_marked'] ?? 0);
				$theme_marked  = (int)($theme_result['stale_marked']  ?? 0);
				if ($plugin_marked > 0 || $theme_marked > 0) {
					upgrade_echo("⚠ Stale: {$plugin_marked} plugin(s), {$theme_marked} theme(s) no longer in source manifest — preserved and flagged for admin review<br>");
				} else if ($verbose) {
					upgrade_echo("✓ No stale plugins/themes detected<br>");
				}
			} catch (\Throwable $e) {
				// Sync is a post-deployment step — deployment and DB migration already succeeded.
				// Do not roll back; just warn. Re-run update_database to retry sync.
				out_alert('warning', 'Theme/Plugin sync failed (non-fatal — deployment and DB updates succeeded)',
					'Error: ' . htmlspecialchars($e->getMessage()) . '<br>'
					. 'To retry: run update_database from the admin utilities page.');
			}

		// Flush static page cache so new code's renders aren't masked by
		// pre-deploy cached pages. See specs/upgrade_pipeline_rename_gap.md.
		$cache_dir = $full_site_dir . '/cache/static_pages';
		if (is_dir($cache_dir)) {
			$cleared = 0;
			exec('find ' . escapeshellarg($cache_dir) . ' -mindepth 1 -delete 2>&1', $cache_out, $cache_exit);
			if ($cache_exit === 0) {
				if ($verbose) upgrade_echo("✓ Static page cache flushed<br>");
			} else {
				upgrade_echo("⚠ Static page cache flush failed (non-fatal)<br>");
			}
		}

		// Remove the rollback backup — upgrade succeeded, no longer needed.
		if (is_dir($backup_directory)) {
			exec('rm -rf ' . escapeshellarg($backup_directory) . ' 2>&1', $rm_out, $rm_exit);
			if ($rm_exit === 0) {
				if ($verbose) upgrade_echo("✓ Removed rollback backup (public_html_last)<br>");
			} else {
				upgrade_echo("⚠ Could not remove rollback backup (non-fatal): " . htmlspecialchars(implode(' ', $rm_out)) . "<br>");
			}
		}

		upgrade_echo('<br><h2>✓ Upgrade Complete!</h2>');
		upgrade_echo('System upgraded to version: ' . $decode_response['system_version'] . '<br>');
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

				$formwriter->submitbutton('btn_submit', 'Submit');

			}
			else if(version_compare($decode_response['system_version'], $settings->get_setting('system_version'), '==')){
				$friendly_date = date('F j, Y', strtotime($decode_response['release_date']));
				echo '<div class="alert alert-success"><strong>Up to date.</strong> Version '. htmlspecialchars($settings->get_setting('system_version')). ' is the latest version.</div>';
				echo '<p>Latest release: '. htmlspecialchars($decode_response['system_version']) . ' ('.htmlspecialchars($decode_response['upgrade_name']).') released on '. $friendly_date .' — '.htmlspecialchars($decode_response['release_notes']).'</p>';
				$formwriter->hiddeninput("confirm", '', ['value' => 1]);

				$formwriter->submitbutton('btn_submit', 'Upgrade anyway');

			}
			else{
				echo '<div class="alert alert-success"><strong>Up to date.</strong> Version '. htmlspecialchars($settings->get_setting('system_version')). ' is current.</div>';
			}

			echo '</div>';
		echo '</fieldset>';
		echo $formwriter->end_form();

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
	 * Get all installed extension names of a given type ('theme' or 'plugin'),
	 * regardless of their receives_upgrades flag.
	 *
	 * Used to build the download list: any locally-installed extension that the
	 * source has published will be downloaded. The staged manifest then decides
	 * whether to preserve or upgrade (copyPreservedToStaging). Reading the live
	 * manifest here would create a bootstrapping deadlock where a theme can never
	 * receive a receives_upgrades flag change via an upgrade package.
	 */
	function get_installed_extension_names($extension_dir, $type) {
		$names = [];
		foreach (glob($extension_dir . '/*/' . $type . '.json') as $json_file) {
			$names[] = basename(dirname($json_file));
		}
		return $names;
	}

	/**
	 * @deprecated Use get_installed_extension_names() for the download list.
	 * Kept for callers that genuinely need to know the live receives_upgrades flag.
	 */
	function get_upgradable_extensions($extension_dir, $type) {
		$names = [];
		foreach (glob($extension_dir . '/*/' . $type . '.json') as $json_file) {
			$data = json_decode(file_get_contents($json_file), true);
			if (($data['receives_upgrades'] ?? false) === true) {
				$names[] = basename(dirname($json_file));
			}
		}
		return $names;
	}

	/**
	 * Get extensions of a given type ('theme' or 'plugin') marked is_system=true
	 * in their manifest. These must be present on every site for core functionality
	 * to work and are downloaded even if not currently installed, regardless of
	 * the local site's receives_upgrades setting.
	 */
	function get_system_required_extensions($extension_dir, $type) {
		$names = [];
		foreach (glob($extension_dir . '/*/' . $type . '.json') as $json_file) {
			$data = json_decode(file_get_contents($json_file), true);
			if (!empty($data['is_system'])) {
				$names[] = basename(dirname($json_file));
			}
		}
		return $names;
	}

	/**
	 * Enumerate published archive tarballs for a kind ('plugins'|'themes').
	 * Reads $archives_dir for files matching {name}-{version}.tar.gz and returns
	 * one entry per archive: ['name' => ..., 'version' => ..., 'url' => ...].
	 *
	 * Used by the ?serve-upgrade response to advertise which plugins/themes the
	 * source has published (had included_in_publish=true at publish time), so prod
	 * can download the full set without first consulting its own state. See
	 * specs/upgrade_pipeline_rename_gap.md.
	 */
	function get_published_archives($archives_dir, $url_subpath) {
		$out = [];
		if (!is_dir($archives_dir)) return $out;

		foreach (glob($archives_dir . '/*.tar.gz') ?: [] as $path) {
			$basename = basename($path, '.tar.gz');
			// Filename pattern is {name}-{version}; version may contain dots.
			// Split on the LAST hyphen so multi-word names (e.g. dns_filtering)
			// keep their underscores and version captures everything after the
			// final hyphen.
			$pos = strrpos($basename, '-');
			if ($pos === false) continue;
			$name    = substr($basename, 0, $pos);
			$version = substr($basename, $pos + 1);
			if ($name === '' || $version === '') continue;

			$out[] = [
				'name'    => $name,
				'version' => $version,
				'url'     => LibraryFunctions::get_absolute_url('/static_files/' . $url_subpath . '/' . basename($path)),
			];
		}
		return $out;
	}

	/**
	 * Get detailed info about all installed extensions of a given type.
	 * $type is 'theme' or 'plugin'. Returns name-keyed metadata.
	 *
	 * `will_upgrade` is true when the source has published the extension (it will
	 * be downloaded; the staged manifest then decides preservation). Pass
	 * $published_names as the list from the source's published-archives manifest.
	 * If omitted, falls back to the live receives_upgrades flag (legacy behaviour).
	 */
	function get_installed_extension_info($extension_dir, $type, $published_names = null) {
		$published_set = ($published_names !== null) ? array_flip($published_names) : null;
		$info = [];
		foreach (glob($extension_dir . '/*/' . $type . '.json') as $json_file) {
			$data = json_decode(file_get_contents($json_file), true) ?: [];
			$name = basename(dirname($json_file));
			$receives_upgrades = $data['receives_upgrades'] ?? false;
			$will_upgrade = ($published_set !== null)
				? isset($published_set[$name])
				: ($receives_upgrades === true);
			$info[$name] = [
				'name' => $name,
				'display_name' => $data['display_name'] ?? $name,
				'version' => $data['version'] ?? 'unknown',
				'receives_upgrades' => $receives_upgrades,
				'will_upgrade' => $will_upgrade,
			];
		}
		ksort($info);
		return $info;
	}

	/**
	 * Download a set of extension archives into the stage directory.
	 * Returns the count successfully downloaded; appends per-item failures
	 * to $skipped_items by reference.
	 *
	 * @param string[]    $names           extension names to download
	 * @param array       $url_lookup      name => archive URL (from source's published_* manifest)
	 * @param string      $type            'theme' or 'plugin' (used in user-facing messages)
	 * @param string      $target_subdir   subdir under $stage_directory ('theme' or 'plugins')
	 * @param string      $stage_directory stage root
	 * @param string[]   &$skipped_items   ref-appended skip messages
	 */
	function download_extension_set($names, $url_lookup, $type, $target_subdir, $stage_directory, &$skipped_items) {
		$type_label = ucfirst($type);
		$count = 0;
		foreach ($names as $name) {
			$url = $url_lookup[$name] ?? null;
			if (!$url) {
				$skipped_items[] = "{$type_label}: {$name} — no URL in source manifest";
				continue;
			}
			upgrade_echo("Downloading {$type}: {$name}...");
			flush();
			$result = download_and_extract($url, $stage_directory . '/' . $target_subdir . '/');
			if ($result['success']) {
				upgrade_echo(" ✓<br>");
				$count++;
			} else {
				upgrade_echo(" ⚠ skipped (" . htmlspecialchars($result['error']) . ")<br>");
				$skipped_items[] = "{$type_label}: {$name} — " . $result['error'];
			}
		}
		return $count;
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

		// 'will_upgrade' means: this component will be refreshed during upgrade.
		// For plugins, that's "installed" (has a plg_plugins row). For themes,
		// it tracks the on-disk receives_upgrades flag.
		if ($is_cli) {
			echo str_pad("Name", 25) . str_pad("Version", 12) . str_pad("Upgrades", 10) . "Status\n";
			echo str_repeat("-", 60) . "\n";
			foreach ($components as $info) {
				if ($info['will_upgrade']) {
					$status = '✓ Will upgrade';
				} else {
					$status = '⊘ Skipped';
				}
				$upgrades = $info['receives_upgrades'] ? 'Yes' : 'No';
				echo str_pad($info['name'], 25) . str_pad($info['version'], 12) . str_pad($upgrades, 10) . $status . "\n";
			}
			echo "\n";
		} else {
			echo '<table style="border-collapse: collapse; width: 100%; margin: 10px 0; font-family: monospace;">';
			echo '<tr style="background-color: #f8f9fa; border-bottom: 2px solid #dee2e6;">';
			echo '<th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Name</th>';
			echo '<th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Version</th>';
			echo '<th style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">Upgrades</th>';
			echo '<th style="padding: 8px; text-align: left; border: 1px solid #dee2e6;">Status</th>';
			echo '</tr>';
			foreach ($components as $info) {
				if ($info['will_upgrade']) {
					$row_style = 'background-color: #d4edda;';
					$status = '<span style="color: #155724;">✓ Will upgrade</span>';
				} else {
					$row_style = 'background-color: #fff3cd;';
					$status = '<span style="color: #856404;">⊘ Skipped</span>';
				}
				$upgrades = $info['receives_upgrades']
					? '<span style="color: #155724;">Yes</span>'
					: '<span style="color: #6c757d;">No</span>';
				echo '<tr style="' . $row_style . '">';
				echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($info['name']) . '</td>';
				echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($info['version']) . '</td>';
				echo '<td style="padding: 8px; text-align: center; border: 1px solid #dee2e6;">' . $upgrades . '</td>';
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

?>
