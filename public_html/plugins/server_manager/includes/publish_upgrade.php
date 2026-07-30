<?php
	$is_cli = php_sapi_name() === 'cli';

	if ($is_cli) {
		// CLI bootstrap: load core dependencies manually (session/AdminPage not available)
		require_once(__DIR__ . '/../../../includes/PathHelper.php');
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

	// Resolved here, in the scope that owns $full_site_dir, so the log helpers
	// below never have to assume this file was included at global scope.
	$GLOBALS['publish_log_dir'] = $full_site_dir . '/logs/publish';

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
		$cli_patch = null;
		$cli_notes = '';

		if (!empty($cli_args[0]) && preg_match('/^\d+\.\d+(\.\d+)?$/', $cli_args[0])) {
			// Strictly numeric dotted version — anything else with a dot
			// ("install.sh fixed...") is release notes, not a version.
			$parts = explode('.', $cli_args[0], 3);
			$cli_major = $parts[0];
			$cli_minor = $parts[1] ?? null;
			$cli_patch = $parts[2] ?? null;
			$cli_notes = $cli_args[1] ?? 'CLI publish';
		} elseif (!empty($cli_args[0]) && is_numeric($cli_args[0])) {
			$cli_major = $cli_args[0];
			$cli_minor = $cli_args[1] ?? null;
			$cli_patch = $cli_args[2] ?? null;
			$cli_notes = $cli_args[3] ?? 'CLI publish';
		} else {
			$cli_notes = $cli_args[0] ?? 'CLI publish';
		}

		// Auto-detect next version if not specified. Prefer the VERSION file as source of
		// truth (it's authoritative for "what's currently published"); fall back to the
		// last upg_upgrades row if VERSION doesn't exist yet.
		if ($cli_major === null || $cli_minor === null || $cli_patch === null) {
			require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
			$current = LibraryFunctions::get_joinery_version();
			if ($current !== '' && preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $current, $m)) {
				$cli_major = $cli_major ?? $m[1];
				$cli_minor = $cli_minor ?? $m[2];
				$cli_patch = $cli_patch ?? ($m[3] + 1);
			} else {
				$latest = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 1);
				$latest->load();
				if ($latest->count() > 0) {
					$last = $latest->get(0);
					$cli_major = $cli_major ?? $last->get('upg_major_version');
					$cli_minor = $cli_minor ?? $last->get('upg_minor_version');
					$cli_patch = $cli_patch ?? ($last->get('upg_patch_version') + 1);
				} else {
					$cli_major = $cli_major ?? 0;
					$cli_minor = $cli_minor ?? 8;
					$cli_patch = $cli_patch ?? 1;
				}
			}
		}

		$_REQUEST['version_major'] = $cli_major;
		$_REQUEST['version_minor'] = $cli_minor;
		$_REQUEST['version_patch'] = $cli_patch;
		$_REQUEST['release_notes'] = $cli_notes;
	}

	if ($is_cli) {
		$session = null;
	} else {
		$session = SessionControl::get_instance();
		$session->check_permission(8);
	}

	// Increase execution time for large zip file creation (5 minutes)
	set_time_limit(300);

	// Keep a durable record of this run. Registered before any work so that an
	// early exit or a fatal is logged as faithfully as a clean finish — those
	// are the runs whose explanation is otherwise lost with the terminal.
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/PublishLog.php'));
	PublishLog::start($GLOBALS['publish_log_dir']);
	register_shutdown_function(function () {
		PublishLog::write(error_get_last());
	});

	// Output helper: strips HTML for CLI, flushes for web
	function publish_output($text) {
		global $is_cli;
		$plain = strip_tags(str_replace(['<br>', '<br />', '<br/>'], "\n", $text));
		PublishLog::record($plain);
		if ($is_cli) {
			echo $plain . "\n";
		} else {
			echo nl2br(htmlspecialchars($text)) . "<br>\n";
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
			$version_string = $upgrade_to_delete->get('upg_major_version').'.'.$upgrade_to_delete->get('upg_minor_version').'.'.$upgrade_to_delete->get('upg_patch_version');
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
			header('Location: /admin/server_manager/publish');
			exit;
		}
	}

	if(isset($_REQUEST['version_major']) && isset($_REQUEST['version_minor']) && isset($_REQUEST['version_patch'])){

		$version_major = $_REQUEST['version_major'];
		$version_minor = $_REQUEST['version_minor'];
		$version_patch = $_REQUEST['version_patch'];
		$verbose = isset($_GET['verbose']) ? true : false;

		// Check if this version already exists in the database
		$existing = new MultiUpgrade(
			array('major_version' => $version_major, 'minor_version' => $version_minor, 'patch_version' => $version_patch),
			array(),
			1
		);
		$existing->load();

		if ($existing->count() > 0) {
			publish_output("Version {$version_major}.{$version_minor}.{$version_patch} already exists. Please use a different version number.");
			exit;
		}

		// Use form-provided version consistently for both archive and SQL filenames
		$version = $version_major . '.' . $version_minor . '.' . $version_patch;
		PublishLog::setVersion($version);

		// Downgrade guard: refuse if the new version is less than what's in VERSION. Cheap
		// safeguard against accidentally re-publishing a lower number than the file already
		// has (e.g. someone bumped it manually out-of-band).
		require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
		$current_version = LibraryFunctions::get_joinery_version();
		if ($current_version !== '' && version_compare($version, $current_version, '<')) {
			publish_output("Refusing to publish {$version} — VERSION file is already at {$current_version}. Publish a higher version or update the VERSION file first.");
			exit;
		}

		// =====================================================
		// License preflight
		// =====================================================
		// Every install performed by the published one-liner gets its license
		// text from the archive and nowhere else, so a release without one ships
		// code nobody has been told the terms for. Checked here, alongside the
		// agent guard, because nothing has been written to the tree yet.
		$license_source = $full_site_dir . '/LICENSE.md';
		if (!is_file($license_source) || trim((string)file_get_contents($license_source)) === '') {
			publish_output("\nRefusing to publish {$version} — LICENSE.md is missing or empty at {$license_source}.");
			publish_output("Every archive carries the license text. Nothing has been written.");
			exit;
		}
		publish_output("License present at {$license_source}");

		// =====================================================
		// Bundle the management agent artifact (release channel)
		// =====================================================
		// Runs here — before the VERSION file, the core archive, the release
		// row or any plugin archive — because a bundle this box owed and could
		// not build is a reason to publish nothing at all. Refusing at this
		// point leaves the tree exactly as it was found.
		//
		// It also still precedes plugin archive creation, so a freshly built
		// agent_dist is captured in the server_manager archive and its tree hash.
		publish_output("Bundling management agent artifact...");
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentDistPublisher.php'));
		$agent_bundle = AgentDistPublisher::publish($full_site_dir, 'publish_output');

		if ($agent_bundle['status'] === AgentDistPublisher::STATUS_FAILED) {
			publish_output("\nRefusing to publish {$version} — the agent bundle is at v"
				. ($agent_bundle['bundled_version'] ?: 'none')
				. " but this box has agent source at v" . ($agent_bundle['source_version'] ?: '?')
				. ", and the rebuild failed. Publishing now would ship an agent known to be stale.");
			publish_output("Fix the agent build and publish again. Nothing has been written.");
			exit;
		}

		// Write the new version to public_html/VERSION so it ships in the tarball and
		// becomes the authoritative version for sites upgrading to it.
		$version_file = PathHelper::getIncludePath('VERSION');
		if (file_put_contents($version_file, $version . "\n") === false) {
			publish_output("ERROR: Could not write version to $version_file (permissions?).");
			exit;
		}
		publish_output("Wrote version $version to $version_file");

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

		$core_filename = 'joinery-core-' . $version . '.tar.gz';
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

		// Build agent file exclusion list — covers any filename managed by the agent files admin.
		// Hardcoded fallback set keeps the common names excluded even if the DB query fails.
		$agent_file_excludes = array('CLAUDE.md', 'GEMINI.md', 'AGENTS.md');
		try {
			$_agf_db = DbConnector::get_instance()->get_db_link();
			$_agf_q  = $_agf_db->prepare("SELECT agf_target_filenames FROM agf_agent_files WHERE agf_delete_time IS NULL");
			$_agf_q->execute();
			while ($_agf_row = $_agf_q->fetch(PDO::FETCH_ASSOC)) {
				$_agf_raw = $_agf_row['agf_target_filenames'];
				if (!$_agf_raw) continue;
				$_agf_targets = is_array($_agf_raw) ? $_agf_raw : json_decode($_agf_raw, true);
				if (is_array($_agf_targets)) {
					foreach ($_agf_targets as $_agf_name) {
						if (is_string($_agf_name) && $_agf_name !== '' && strpos($_agf_name, '/') === false && strpos($_agf_name, '\\') === false) {
							$agent_file_excludes[] = $_agf_name;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			// agf_agent_files table not yet present or DB unavailable — fallback set still applies.
		}
		$agent_file_excludes = array_unique($agent_file_excludes);
		$agent_excludes_str = '';
		foreach ($agent_file_excludes as $_agf_name) {
			$agent_excludes_str .= ' --exclude=' . escapeshellarg($_agf_name);
		}

		// Copy public_html excluding themes and plugins content
		// Note: Use anchored patterns (/theme/*, /plugins/*) to only exclude top-level directories,
		// not subdirectories like assets/vendor/Trumbowyg-2-26/dist/plugins/
		$rsync_core_cmd = sprintf(
			'rsync -av --exclude=.git --exclude=.gitignore --exclude=.claude --exclude=specs%s --exclude=uploads --exclude=cache --exclude=logs --exclude=backups --exclude=.playwright-mcp --exclude=theme-sources --exclude="/theme/*" --exclude="/plugins/*" %s %s 2>&1',
			$agent_excludes_str,
			escapeshellarg($full_site_dir . '/public_html/'),
			escapeshellarg($core_temp_dir . '/public_html/')
		);
		exec($rsync_core_cmd, $output, $exit_code);

		// Ensure empty theme/ and plugins/ directories exist in the core archive.
		// rsync leaves the parent dirs in place even when their contents are excluded,
		// so check before creating to avoid "File exists" warnings.
		if (!is_dir($core_temp_dir . '/public_html/theme')) {
			mkdir($core_temp_dir . '/public_html/theme', 0755, true);
		}
		if (!is_dir($core_temp_dir . '/public_html/plugins')) {
			mkdir($core_temp_dir . '/public_html/plugins', 0755, true);
		}

		// Carry the license into public_html rather than the archive root.
		// upgrade.php deploys only two things from a staged archive — it swaps
		// public_html and rsyncs maintenance_scripts. A root-level file would be
		// laid down once by install.sh and never refreshed, so every upgraded site
		// would keep whatever license it was born with. The canonical copy stays at
		// the repo root, where GitHub and license scanners look for it.
		if (!copy($license_source, $core_temp_dir . '/public_html/LICENSE.md')) {
			publish_output("ERROR: Failed to copy LICENSE.md into the core archive.");
			exit;
		}

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
		$upgrade->set('upg_patch_version', $version_patch);
		$upgrade->set('upg_name', $core_filename);
		$upgrade->set('upg_release_notes', $_REQUEST['release_notes']);
		$upgrade->prepare();
		$upgrade->save();

		// Write system_version on the publish server so its own version is current immediately
		// (rather than waiting for the next update_database self-heal). Both writers always
		// write get_joinery_version() → can't disagree.
		try {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'system_version'");
			$q->execute([$version]);
			if ($q->rowCount() === 0) {
				$q = $db->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_group_name, stg_create_time) VALUES ('system_version', ?, 'general', now())");
				$q->execute([$version]);
			}
			publish_output("Updated stg_settings.system_version to $version");
		} catch (Exception $e) {
			publish_output("Warning: could not update system_version: " . $e->getMessage());
		}

		// =====================================================
		// Component version integrity bookkeeping
		// =====================================================
		// Baseline = the most recent prior release row that carries a parseable,
		// non-empty component-state snapshot. Rows without one (every pre-existing
		// row when this ships, or an aborted publish) are skipped, so we never
		// compare against a half-written or absent snapshot. If none is found, the
		// run re-baselines: every component hits "no last entry" (rule 1) and is
		// recorded as-is with no bump.
		$baseline_state = array('themes' => array(), 'plugins' => array());
		$prior_releases = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 1000, 0);
		$prior_releases->load();
		foreach ($prior_releases as $prior) {
			if ($prior->key == $upgrade->key) continue; // skip the row we just created
			$raw = $prior->get('upg_component_state');
			if (empty($raw)) continue;
			$decoded = json_decode($raw, true);
			if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) continue;
			$baseline_state = array(
				'themes'  => $decoded['themes']  ?? array(),
				'plugins' => $decoded['plugins'] ?? array(),
			);
			break;
		}

		// Accumulated snapshot for this release, written onto the new row after
		// both archive loops complete. Auto-bumped manifests and regression
		// warnings are reported in the publish summary.
		$new_state = array('themes' => array(), 'plugins' => array());
		$bumped_components = array();
		$publish_warnings = array();

		// =====================================================
		// Create individual THEME archives
		// =====================================================
		publish_output("\nCreating individual theme archives...");

		$themes_dir = $file_output_folder . '/themes';
		if (!is_dir($themes_dir)) {
			mkdir($themes_dir, 0755, true);
		}

		// Wipe existing theme archives so static_files/themes/ mirrors current
		// source after regeneration. Cleans up old versions, renamed themes,
		// and themes removed from public_html/theme/.
		$wiped = 0;
		foreach (glob($themes_dir . '/*.tar.gz') ?: [] as $stale) {
			if (@unlink($stale)) $wiped++;
		}
		if ($wiped > 0) {
			publish_output("Wiped {$wiped} existing theme archive(s) before regeneration");
		}

		$theme_base_dir = $full_site_dir . '/public_html/theme';
		foreach (glob($theme_base_dir . '/*/theme.json') as $theme_json) {
			$theme_dir = dirname($theme_json);
			$theme_name = basename($theme_dir);

			// Read theme.json for version, included_in_publish, and deprecated
			$theme_data = json_decode(file_get_contents($theme_json), true);
			$published = $theme_data['included_in_publish'] ?? true;

			if (!$published) {
				publish_output("- Skipping {$theme_name} (included_in_publish=false)");
				if (isset($baseline_state['themes'][$theme_name])) {
					$new_state['themes'][$theme_name] = $baseline_state['themes'][$theme_name];
				}
				continue;
			}

			if (!empty($theme_data['deprecated'])) {
				publish_output("- Skipping {$theme_name} (deprecated)");
				if (isset($baseline_state['themes'][$theme_name])) {
					$new_state['themes'][$theme_name] = $baseline_state['themes'][$theme_name];
				}
				continue;
			}

			$theme_version = $theme_data['version'] ?? '1.0.0';

			// Version integrity: read manifest -> compute hash -> decide -> (maybe) bump.
			$current_hash = component_tree_hash($theme_dir, 'theme.json');
			$last = $baseline_state['themes'][$theme_name] ?? null;
			if ($last === null) {
				// Rule 1: no baseline entry (first publish under this system or a
				// new component). Record as-is, no bump.
			} elseif (version_compare($theme_version, $last['version'], '>')) {
				// Rule 2: author bumped deliberately. Respect and record.
			} elseif (version_compare($theme_version, $last['version'], '<')) {
				// Rule 3: version went backward. Record/archive as-is, but warn.
				$publish_warnings[] = "theme {$theme_name}: version went backward ({$last['version']} -> {$theme_version}); recorded and archived as-is";
				publish_output("- WARNING: {$theme_name} version went backward: {$last['version']} -> {$theme_version}");
			} else {
				// Rule 4: equal version. Compare hashes.
				if (!isset($last['tree_hash']) || $last['tree_hash'] !== $current_hash) {
					// Content changed without a bump — auto patch-bump the manifest.
					$new_version = component_bump_manifest_version($theme_json, $theme_version);
					publish_output("- {$theme_name}: content changed since {$theme_version}, auto-bumped to {$new_version}");
					$bumped_components[] = "{$theme_name} ({$theme_version} -> {$new_version})";
					$theme_version = $new_version;
				}
			}
			$new_state['themes'][$theme_name] = array('version' => $theme_version, 'tree_hash' => $current_hash);

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

		$wiped = 0;
		foreach (glob($plugins_dir . '/*.tar.gz') ?: [] as $stale) {
			if (@unlink($stale)) $wiped++;
		}
		if ($wiped > 0) {
			publish_output("Wiped {$wiped} existing plugin archive(s) before regeneration");
		}

		$plugin_base_dir = $full_site_dir . '/public_html/plugins';
		foreach (glob($plugin_base_dir . '/*/plugin.json') as $plugin_json) {
			$plugin_dir = dirname($plugin_json);
			$plugin_name = basename($plugin_dir);

			// Read plugin.json for version, included_in_publish, and deprecated
			$plugin_data = json_decode(file_get_contents($plugin_json), true);
			$published = $plugin_data['included_in_publish'] ?? true;

			if (!$published) {
				publish_output("- Skipping {$plugin_name} (included_in_publish=false)");
				if (isset($baseline_state['plugins'][$plugin_name])) {
					$new_state['plugins'][$plugin_name] = $baseline_state['plugins'][$plugin_name];
				}
				continue;
			}

			if (!empty($plugin_data['deprecated'])) {
				publish_output("- Skipping {$plugin_name} (deprecated)");
				if (isset($baseline_state['plugins'][$plugin_name])) {
					$new_state['plugins'][$plugin_name] = $baseline_state['plugins'][$plugin_name];
				}
				continue;
			}

			$plugin_version = $plugin_data['version'] ?? '1.0.0';

			// Version integrity: read manifest -> compute hash -> decide -> (maybe) bump.
			$current_hash = component_tree_hash($plugin_dir, 'plugin.json');
			$last = $baseline_state['plugins'][$plugin_name] ?? null;
			if ($last === null) {
				// Rule 1: no baseline entry. Record as-is, no bump.
			} elseif (version_compare($plugin_version, $last['version'], '>')) {
				// Rule 2: author bumped deliberately. Respect and record.
			} elseif (version_compare($plugin_version, $last['version'], '<')) {
				// Rule 3: version went backward. Record/archive as-is, but warn.
				$publish_warnings[] = "plugin {$plugin_name}: version went backward ({$last['version']} -> {$plugin_version}); recorded and archived as-is";
				publish_output("- WARNING: {$plugin_name} version went backward: {$last['version']} -> {$plugin_version}");
			} else {
				// Rule 4: equal version. Compare hashes.
				if (!isset($last['tree_hash']) || $last['tree_hash'] !== $current_hash) {
					// Content changed without a bump — auto patch-bump the manifest.
					$new_version = component_bump_manifest_version($plugin_json, $plugin_version);
					publish_output("- {$plugin_name}: content changed since {$plugin_version}, auto-bumped to {$new_version}");
					$bumped_components[] = "{$plugin_name} ({$plugin_version} -> {$new_version})";
					$plugin_version = $new_version;
				}
			}
			$new_state['plugins'][$plugin_name] = array('version' => $plugin_version, 'tree_hash' => $current_hash);

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

		// Persist the accumulated component snapshot onto this release row. Done
		// in one update after both loops so an aborted publish leaves the row
		// snapshot-less and the baseline rule skips it on the next run.
		$upgrade->set('upg_component_state', json_encode($new_state));
		$upgrade->save();

		if (!empty($bumped_components)) {
			publish_output("\nAuto-bumped component versions (commit these manifest edits):");
			foreach ($bumped_components as $b) {
				publish_output("  - $b");
			}
		}
		if (!empty($publish_warnings)) {
			publish_output("\nWarnings:");
			foreach ($publish_warnings as $w) {
				publish_output("  - $w");
			}
		}

		// Name the agent version this release actually carries. Without this the
		// only way to know is to unpack the server_manager archive.
		$agent_shipped = $agent_bundle['source_version'] ?: $agent_bundle['bundled_version'];
		publish_output("\nManagement agent shipped in this release: v"
			. ($agent_shipped ?: 'none')
			. " ({$agent_bundle['status']})");

		publish_output("\nAll archives created successfully!");

		// =====================================================
		// Retention: reclaim old core archives
		// =====================================================
		// Deletes archive files only — every release row survives as history.
		// Never touches the newest N, anything flagged Keep, or a version a
		// managed node is running (that archive is its rollback target).
		try {
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/UpgradeRetention.php'));
			$retention = UpgradeRetention::prune();
			if ($retention['keep_count'] === 0) {
				publish_output("\nRetention: keeping all archives (retention count set to 0).");
			} elseif (empty($retention['removed']) && empty($retention['failed'])) {
				publish_output("\nRetention: nothing to reclaim (keeping newest {$retention['keep_count']}).");
			} else {
				$freed = UpgradeRetention::formatBytes($retention['bytes']);
				$count = count($retention['removed']);
				publish_output("\nRetention: removed {$count} old archive(s), reclaimed {$freed}. Release history kept.");
				foreach ($retention['removed'] as $v) {
					publish_output("  - archive for $v removed");
				}
				foreach ($retention['failed'] as $v) {
					publish_output("  - WARNING: could not remove archive for $v");
				}
			}
		} catch (Exception $e) {
			publish_output("\nRetention skipped: " . $e->getMessage());
		}

		publish_output("\nPublish log: " . PublishLog::path());

	}
	else{
		$page = new AdminPage();
		$page->admin_header(
		array(
			'menu-id'=> 'server-manager',
			'page_title' => 'Publish Upgrade',
			'readable_title' => 'Publish Upgrade',
			'breadcrumbs' => array(
				'Server Manager' => '/admin/server_manager',
				'Publish Upgrade' => '',
			),
			'session' => $session,
		)
		);
		
		// Display session messages
		$display_messages = $session->get_messages('/admin/server_manager/publish');
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
				echo '<div class="alert ' . $alert_class . '" role="alert">';
				if ($msg->message_title) {
					echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
				}
				echo htmlspecialchars($msg->message);
				echo '<button type="button" class="alert-close" aria-label="Close">&times;</button>';
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
			$version_string = 'Version '.$upgrade->get('upg_major_version'). '.'. $upgrade->get('upg_minor_version'). '.'. $upgrade->get('upg_patch_version'). ' - '. LibraryFunctions::convert_time($upgrade->get('upg_create_time'), 'UTC', $session->get_timezone()) . ' - '. substr($upgrade->get('upg_release_notes'), 0, 500);

			// Check if archive file exists (supports both old .zip and new .tar.gz)
			$archive_filename = $upgrade->get('upg_name');
			$archive_path = $full_site_dir.'/static_files/'.$archive_filename;
			if (!file_exists($archive_path)) {
				$version_string .= ' <span class="svm-tag-missing">[ARCHIVE FILE MISSING]</span>';
			} else {
				// Show file format
				if (strpos($archive_filename, '.tar.gz') !== false) {
					$version_string .= ' <span class="svm-tag-targz">[tar.gz]</span>';
				} else if (strpos($archive_filename, '.zip') !== false) {
					$version_string .= ' <span class="svm-tag-zip">[zip - legacy]</span>';
				}
			}

			// Add delete link
			$delete_url = '/admin/server_manager/publish?delete=' . $upgrade->key;
			$version_label = $upgrade->get('upg_major_version') . '.' . $upgrade->get('upg_minor_version') . '.' . $upgrade->get('upg_patch_version');
			$version_string .= ' <a href="#" onclick="JoineryModal.confirm(\'Delete version ' . $version_label . '? This will delete both the archive file and database record.\', function(){ window.location=\'' . htmlspecialchars($delete_url, ENT_QUOTES) . '\'; })" class="svm-delete-link"><i class="fas fa-trash-alt"></i> Delete</a>';

			echo $version_string.'<br />';
		}
		echo '<br><br>';


		// Get FormWriter using theme-aware pattern
		$formwriter = $page->getFormWriter('form1', ['action' => '/admin/server_manager/publish', 'method' => 'POST']);
		$formwriter->begin_form();

		
		$latest = new MultiUpgrade(array(), array('upgrade_id' => 'DESC'), 1);
		$latest->load();
		if ($latest->count() > 0) {
			$last_upgrade = $latest->get(0);
			$major_version = $last_upgrade->get('upg_major_version');
			$minor_version = $last_upgrade->get('upg_minor_version');
			$patch_version = $last_upgrade->get('upg_patch_version') + 1;
		} else {
			$major_version = 0;
			$minor_version = 8;
			$patch_version = 1;
		}

		echo $formwriter->textinput('version_major', 'Major Version', [
			'value' => $major_version,
			'validation' => ['required' => true]
		]);
		echo $formwriter->textinput('version_minor', 'Minor Version', [
			'value' => $minor_version,
			'validation' => ['required' => true]
		]);
		echo $formwriter->textinput('version_patch', 'Patch Version', [
			'value' => $patch_version,
			'validation' => ['required' => true]
		]);
		echo $formwriter->textbox('release_notes', 'Release notes', [
			'validation' => ['required' => true]
		]);

		echo '<p class="text-muted">Publishing creates: core archive + individual theme/plugin archives for every theme/plugin with included_in_publish=true.</p>';

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
	
	/**
	 * Content hash of a component working tree, git-independent and deterministic
	 * across boxes. Walks every regular file except the archive-exclusion set
	 * (.git/ anywhere, any .gitignore — the same set the P1.8 tar excludes use),
	 * and sha256s the sorted concatenation of "relative_path \0 sha256(contents)".
	 * Ignores permissions, mtimes, and directory ordering.
	 *
	 * The manifest (theme.json / plugin.json) is hashed with its `version` member
	 * removed, so the hash measures content-minus-version: a patch auto-bump never
	 * changes the hash and the decision rule never has to reason about it.
	 *
	 * @param string $dir Absolute path to the component directory
	 * @param string|null $manifest_filename Top-level manifest name to version-strip
	 * @return string Lowercase hex sha256
	 */
	function component_tree_hash($dir, $manifest_filename = null) {
		$entries = array();
		$rii = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($rii as $file) {
			if ($file->isDir()) continue;

			$abs = $file->getPathname();
			$rel = ltrim(str_replace('\\', '/', substr($abs, strlen($dir))), '/');

			// Mirror the tar excludes: drop anything under a .git directory and
			// any .gitignore, at any depth.
			if (in_array('.git', explode('/', $rel), true)) continue;
			if (basename($rel) === '.gitignore') continue;

			if ($manifest_filename !== null && $rel === $manifest_filename) {
				$decoded = json_decode(file_get_contents($abs), true);
				if (is_array($decoded)) {
					unset($decoded['version']);
					$content_hash = hash('sha256', json_encode($decoded));
				} else {
					$content_hash = hash_file('sha256', $abs);
				}
			} else {
				$content_hash = hash_file('sha256', $abs);
			}
			$entries[$rel] = $content_hash;
		}

		ksort($entries);
		$blob = '';
		foreach ($entries as $rel => $h) {
			$blob .= $rel . "\0" . $h . "\n";
		}
		return hash('sha256', $blob);
	}

	/**
	 * Auto-bump a component manifest's patch version via targeted string
	 * replacement of the existing "version" member — never a json_decode/encode
	 * round-trip, which would churn the whole file's formatting in the
	 * component's own repo. Tolerant of whitespace around the colon.
	 *
	 * @param string $manifest_path Absolute path to theme.json / plugin.json
	 * @param string $current_version The version currently in the manifest
	 * @return string The new (patch-incremented) version
	 * @throws Exception if the version can't be parsed, found, or written
	 */
	function component_bump_manifest_version($manifest_path, $current_version) {
		if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', trim($current_version), $vm)) {
			throw new Exception("Cannot auto-bump non-semver version '$current_version' in $manifest_path");
		}
		$next = $vm[1] . '.' . $vm[2] . '.' . ($vm[3] + 1);

		$contents = file_get_contents($manifest_path);
		if ($contents === false) {
			throw new Exception("Could not read manifest for auto-bump: $manifest_path");
		}

		$pattern = '/("version"\s*:\s*")' . preg_quote($current_version, '/') . '(")/';
		$new_contents = preg_replace($pattern, '${1}' . $next . '${2}', $contents, 1, $count);
		if ($count < 1) {
			throw new Exception("Could not find version member to auto-bump in $manifest_path (expected \"version\": \"$current_version\")");
		}
		if (file_put_contents($manifest_path, $new_contents) === false) {
			throw new Exception("Could not write auto-bumped manifest: $manifest_path");
		}
		return $next;
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