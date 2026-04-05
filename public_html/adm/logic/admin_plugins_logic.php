<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_plugins_logic($get, $post) {
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
	$action = isset($post['action']) ? $post['action'] : (isset($get['action']) ? $get['action'] : '');
	$plugin_name = isset($post['plugin_name']) ? $post['plugin_name'] : (isset($get['plugin_name']) ? $get['plugin_name'] : '');
	if ($action || $post) {

		// Handle upload action separately as it doesn't require plugin_name
		if ($action === 'upload') {
			try {
				if (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
					$plugin_manager = new PluginManager();
					$installed_plugin_name = $plugin_manager->installPlugin($_FILES['plugin_zip']['tmp_name']);
					$message = "Plugin '$installed_plugin_name' installed successfully.";
					$message_type = 'success';
				} else {
					$message = "Upload failed. Please check the file and try again.";
					$message_type = 'danger';
				}
			} catch (Exception $e) {
				$message = 'Upload failed: ' . htmlspecialchars($e->getMessage());
				$message_type = 'danger';
			}
		} elseif ($action === 'check_updates') {
			// Check for updates action
			$message = 'Check for updates functionality will be implemented in a future update.';
			$message_type = 'info';
		} elseif (!$plugin_name || !Plugin::is_valid_plugin_name($plugin_name)) {
			// Other actions require valid plugin name
			$message = 'Invalid plugin name.';
			$message_type = 'danger';
		} else {
			try {
				$plugin_manager = new PluginManager();

				if ($action === 'install') {
					try {
						$plugin_manager->install($plugin_name);
						$message = "Plugin '$plugin_name' installed successfully.";
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
							$plugin_manager->install($plugin_name);
							$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" repaired successfully.';
							$message_type = 'success';
						} catch (Exception $e) {
							$message = 'Plugin repair failed: ' . htmlspecialchars($e->getMessage());
							$message_type = 'danger';
						}
					}

				} elseif ($action === 'permanent_delete') {
					// Permanently delete plugin files and database record
					$plugin = Plugin::get_by_plugin_name($plugin_name);

					if ($plugin) {
						// Use the model's permanent_delete_with_files method
						$result = $plugin->permanent_delete_with_files();
					} else {
						// No DB record - just delete files directly
						$plugin_dir = PathHelper::getAbsolutePath('plugins/' . $plugin_name);
						$result = array('success' => false, 'errors' => array(), 'messages' => array());

						if (is_dir($plugin_dir)) {
							// Pre-flight permission check
							$perm_check = LibraryFunctions::check_directory_deletable($plugin_dir);
							if (!$perm_check['can_delete']) {
								$result['errors'][] = "Permission denied. Cannot delete: " . implode(', ', array_slice($perm_check['errors'], 0, 3));
							} else {
								if (LibraryFunctions::delete_directory($plugin_dir)) {
									$result['success'] = true;
									$result['messages'][] = "Plugin files deleted";
								} else {
									$result['errors'][] = "Failed to delete plugin files";
								}
							}
						} else {
							$result['success'] = true;
							$result['messages'][] = "Plugin directory already removed";
						}
					}

					if ($result['success']) {
						$message = "Plugin '$plugin_name' permanently deleted.<br>" . implode('<br>', $result['messages']);
						$message_type = 'success';
					} else {
						$message = 'Permanent delete failed:<br>' . implode('<br>', $result['errors']);
						$message_type = 'danger';
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

	return LogicResult::render(array(
		'system_health' => $system_health,
		'message' => $message,
		'message_type' => $message_type,
		'plugins' => $plugins
	));
}
?>
