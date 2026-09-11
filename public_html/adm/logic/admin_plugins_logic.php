<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_plugins_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/plugins_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10); // System admin only
	$session->set_return();

	$message = '';
	$message_type = '';
	// Set when an action was queued for the root actor rather than done here;
	// the view renders the live transcript panel for it.
	$root_request_id = '';
	// Set when an upload was staged and the operator has to finish it in a shell.
	$staged_command = '';

	// Check if plugin system is properly set up
	$system_health = null;
	try {
		// Basic health check - just verify plugin directory exists
		$plugin_dir = PathHelper::getAbsolutePath('plugins');
		if (!is_dir($plugin_dir)) {
			$system_health = [
				'overall_status' => 'needs_repair',
				'issues' => ['Plugin directory does not exist'],
				'recommendations' => ['Create /plugins/ directory with proper permissions']
			];
		} else {
			$system_health = ['overall_status' => 'ok'];
		}
	} catch (Exception $e) {
		$system_health = [
			'overall_status' => 'error',
			'issues' => ['Failed to check system health: ' . $e->getMessage()],
			'recommendations' => ['Contact system administrator']
		];
	}

	// Handle form submissions and GET actions
	$action = isset($input['action']) ? $input['action'] : (isset($input['action']) ? $input['action'] : '');
	$plugin_name = isset($input['plugin_name']) ? $input['plugin_name'] : (isset($input['plugin_name']) ? $input['plugin_name'] : '');
	if ($action || $input) {

		// Handle upload action separately as it doesn't require plugin_name
		if ($action === 'upload') {
			// The upload is unpacked and checked here, under uploads/staging,
			// outside the tree — a path escape or a symlink fails as www-data,
			// where it can do nothing. Installing it is a SHELL command, not a
			// root request: this queue is www-data-writable, so a request file
			// proves only that something running as the web user wrote it, and
			// a kind that installed a staged directory would turn one
			// file-write bug into root code execution (a staged
			// migrations/migrations.php is included as root). See
			// RootRequest::NO_PACKAGE_KIND.
			try {
				if (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
					$plugin_manager = new PluginManager();
					$staged = $plugin_manager->stage($_FILES['plugin_zip']['tmp_name']);
					$staged_command = 'sudo -u ' . escapeshellarg(PluginManager::tree_owner_name()) . ' php '
						. escapeshellarg(PathHelper::getIncludePath('utils/install_extension.php'))
						. ' plugin --staged=' . escapeshellarg($staged['dir']);
					$message = 'Plugin "' . htmlspecialchars($staged['name'])
						. '" was unpacked and checked. Installing an uploaded package writes code into '
						. 'the tree, which is done from a shell rather than from this page — run:';
					$message_type = 'warning';
				} else {
					$message = "Upload failed. Please check the file and try again.";
					$message_type = 'danger';
				}
			} catch (Exception $e) {
				$message = 'Upload failed: ' . htmlspecialchars($e->getMessage());
				$message_type = 'danger';
			}
		} elseif ($action === 'sync_filesystem') {
			try {
				$plugin_manager = new PluginManager();
				$result = $plugin_manager->sync();

				$parts = [];
				if (!empty($result['added'])) {
					$parts[] = count($result['added']) . ' new plugin(s) discovered';
				}
				if (!empty($result['updated'])) {
					$parts[] = count($result['updated']) . ' plugin(s) updated';
				}
				if (!empty($result['table_messages'])) {
					$parts[] = count($result['table_messages']) . ' table change(s)';
				}
				if (!empty($result['migration_messages'])) {
					$parts[] = count($result['migration_messages']) . ' migration(s) applied';
				}
				if (!empty($result['deletion_rule_messages'])) {
					$parts[] = count($result['deletion_rule_messages']) . ' deletion-rule notice(s)';
				}

				if (empty($parts)) {
					$message = 'Sync complete. Everything is up to date.';
				} else {
					$message = 'Sync complete: ' . implode(', ', $parts) . '.';
				}
				if (!empty($result['table_messages'])) {
					$message .= '<br><small>' . htmlspecialchars(implode('; ', $result['table_messages'])) . '</small>';
				}
				if (!empty($result['migration_messages'])) {
					$message .= '<br><small>Migrations: ' . htmlspecialchars(implode('; ', $result['migration_messages'])) . '</small>';
				}
				if (!empty($result['deletion_rule_messages'])) {
					$message .= '<br><small>Deletion rules: ' . htmlspecialchars(implode('; ', $result['deletion_rule_messages'])) . '</small>';
				}
				$message_type = 'success';
			} catch (Exception $e) {
				$message = 'Sync failed: ' . htmlspecialchars($e->getMessage());
				$message_type = 'danger';
			}
		} elseif (!$plugin_name || !Plugin::is_valid_plugin_name($plugin_name)) {
			// Other actions require valid plugin name
			$message = 'Invalid plugin name.';
			$message_type = 'danger';
		} else {
			try {
				$plugin_manager = new PluginManager();

				if ($action === 'install') {
					// Installing by name fetches the plugin's files from the
					// upgrade source and writes them into the tree, which a web
					// request cannot do. Root does it and reports back.
					try {
						$root_request_id = RootRequest::submit('install_plugin',
							array('name' => $plugin_name), (int)$session->get_user_id());
						$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" is queued for installation.';
						$message_type = 'success';
					} catch (Exception $e) {
						$message = 'Installation failed: ' . htmlspecialchars($e->getMessage());
						$message_type = 'danger';
					}

				} elseif ($action === 'activate') {
					try {
						$plugin_manager->activate($plugin_name);
						$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" activated successfully.';
						$message_type = 'success';

						// Warn if activating a deprecated plugin
						$manifest_path = PathHelper::getAbsolutePath("plugins/{$plugin_name}/plugin.json");
						if (file_exists($manifest_path)) {
							$manifest = json_decode(file_get_contents($manifest_path), true);
							if (!empty($manifest['deprecated'])) {
								$replacement = $manifest['superseded_by'] ?? null;
								$message .= $replacement
									? ' Warning: this plugin is deprecated. Use "' . htmlspecialchars($replacement) . '" instead.'
									: ' Warning: this plugin is deprecated.';
								$message_type = 'warning';
							}
						}
					} catch (PluginComposerNeedsRootException $e) {
						// Not a failure the operator can act on by retrying:
						// the packages have to be installed by root, so queue
						// that and tell them to come back to the button.
						$root_request_id = RootRequest::submit('reconcile_composer',
							array('plugin' => $plugin_name), (int)$session->get_user_id());
						$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" needs composer packages that '
							. 'only root installs here. They are queued; activate it again when this finishes.';
						$message_type = 'warning';
					} catch (Exception $e) {
						$message = 'Failed to activate plugin "' . htmlspecialchars($plugin_name) . '": ' . htmlspecialchars($e->getMessage());
						$message_type = 'danger';
					}

				} elseif ($action === 'deactivate') {
					try {
						$plugin_manager->deactivate($plugin_name);
						$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" deactivated successfully.';
						$message_type = 'success';
					} catch (Exception $e) {
						$message = 'Failed to deactivate plugin "' . htmlspecialchars($plugin_name) . '": ' . htmlspecialchars($e->getMessage());
						$message_type = 'danger';
					}

				} elseif ($action === 'uninstall') {
					try {
						$plugin_manager->uninstall($plugin_name);
						$message = "Plugin '$plugin_name' uninstalled successfully.";
						$message_type = 'success';
					} catch (Exception $e) {
						$message = 'Uninstall failed: ' . htmlspecialchars($e->getMessage());
						$message_type = 'danger';
					}

				} elseif ($action === 'repair_plugin') {
					// Clear the install error and reset status, then re-run install
					$plugin = Plugin::get_by_plugin_name($plugin_name);
					if (!$plugin) {
						$message = 'Plugin record not found.';
						$message_type = 'warning';
					} else {
						$plugin->set('plg_install_error', null);
						$plugin->set('plg_status', 'inactive');
						$plugin->save();

						try {
							$root_request_id = RootRequest::submit('install_plugin',
								array('name' => $plugin_name), (int)$session->get_user_id());
							$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" is queued for repair.';
							$message_type = 'success';
						} catch (Exception $e) {
							$message = 'Plugin repair failed: ' . htmlspecialchars($e->getMessage());
							$message_type = 'danger';
						}
					}

				} else {
					$message = 'Invalid action.';
					$message_type = 'danger';
				}
			} catch (Exception $e) {
				$message = 'Error: ' . htmlspecialchars($e->getMessage());
				$message_type = 'danger';
			}
		}
	}

	// Get all plugins with their status
	$plugins = MultiPlugin::get_all_plugins_with_status();

	// Determine which active plugins declare provisioners. This only reads
	// plugin.json manifests — no provisioning checks are run here; those run
	// asynchronously via ajax/check_provisioning.php after the page renders.
	$provisioning_plugins = array();
	try {
		require_once(PathHelper::getIncludePath('includes/PluginProvisioning.php'));
		$provisioning_plugins = array_keys(PluginProvisioning::getProvisioners());
	} catch (Throwable $e) {
		// Non-fatal: the setup indicator simply will not appear.
		error_log('admin_plugins_logic: provisioner discovery failed: ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'system_health' => $system_health,
		'message' => $message,
		'message_type' => $message_type,
		'plugins' => $plugins,
		'provisioning_plugins' => $provisioning_plugins,
		'root_request_id' => $root_request_id,
		'staged_command' => $staged_command,
		'root_actor_notice' => AdminPage::root_actor_notice()
	));
}
?>
