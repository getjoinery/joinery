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
		} elseif ($action === 'sync') {
			// Sync doesn't require plugin name either
			try {
				$plugin_manager = new PluginManager();
				$sync_result = $plugin_manager->sync();
				$parts = array();
				if (!empty($sync_result['added'])) {
					$parts[] = count($sync_result['added']) . " added";
				}
				if (!empty($sync_result['updated'])) {
					$parts[] = count($sync_result['updated']) . " updated";
				}

				if (empty($parts)) {
					$message = "Filesystem sync completed. All plugins are already up to date.";
				} else {
					$message = "Filesystem sync completed: " . implode(", ", $parts) . ".";
				}
				$message_type = 'success';
			} catch (Exception $e) {
				$message = 'Sync failed: ' . htmlspecialchars($e->getMessage());
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
				if ($action === 'install') {
					// Get or create plugin record
					$plugin = Plugin::get_by_plugin_name($plugin_name);
					if (!$plugin) {
						$plugin = new Plugin(null);
						$plugin->set('plg_name', $plugin_name);
					}

					$result = $plugin->install();
					if ($result['success']) {
						$message = implode('<br>', $result['messages']);
						$message_type = 'success';
					} else {
						$message = 'Installation failed:<br>' . implode('<br>', $result['errors']);
						$message_type = 'danger';
					}

				} elseif ($action === 'activate') {
					// Get plugin record
					$plugin = Plugin::get_by_plugin_name($plugin_name);
					if (!$plugin) {
						$message = 'Plugin must be installed first.';
						$message_type = 'warning';
					} else {
						try {
							$plugin->activate();
							$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" activated successfully.';
							$message_type = 'success';
						} catch (Exception $activate_error) {
							$message = 'Failed to activate plugin "' . htmlspecialchars($plugin_name) . '": ' . $activate_error->getMessage();
							$message_type = 'danger';
						}
					}

				} elseif ($action === 'deactivate') {
					$plugin = Plugin::get_by_plugin_name($plugin_name);
					if ($plugin) {
						try {
							$plugin->deactivate();
							$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" deactivated successfully.';
							$message_type = 'success';
						} catch (Exception $deactivate_error) {
							$message = 'Failed to deactivate plugin "' . htmlspecialchars($plugin_name) . '": ' . $deactivate_error->getMessage();
							$message_type = 'danger';
						}
					} else {
						$message = 'Plugin record not found.';
						$message_type = 'warning';
					}

				} elseif ($action === 'uninstall') {
					// Check if plugin is currently the active theme provider

					try {
						$plugin_helper = PluginHelper::getInstance($plugin_name);
						if ($plugin_helper->isActiveThemeProvider()) {
							$message = '<strong>Cannot Uninstall:</strong> ';
							$message .= "The plugin '{$plugin_name}' is currently the active theme provider. ";
							$message .= 'Please select a different theme in <a href="/adm/admin_settings">Settings</a> before uninstalling.';
							$message_type = 'danger';
						} else {
							// Safe to proceed with uninstall
							$plugin = Plugin::get_by_plugin_name($plugin_name);
							if ($plugin) {
								$result = $plugin->uninstall();
								if ($result['success']) {
									$message = implode('<br>', $result['messages']);
									$message_type = 'success';
								} else {
									$message = 'Uninstall failed:<br>' . implode('<br>', $result['errors']);
									$message_type = 'danger';
								}
							} else {
								$message = 'Plugin record not found.';
								$message_type = 'warning';
							}
						}
					} catch (Exception $e) {
						// Plugin helper not found - proceed with normal uninstall
						$plugin = Plugin::get_by_plugin_name($plugin_name);
						if ($plugin) {
							$result = $plugin->uninstall();
							if ($result['success']) {
								$message = implode('<br>', $result['messages']);
								$message_type = 'success';
							} else {
								$message = 'Uninstall failed:<br>' . implode('<br>', $result['errors']);
								$message_type = 'danger';
							}
						} else {
							$message = 'Plugin record not found.';
							$message_type = 'warning';
						}
					}

				} elseif ($action === 'repair_plugin') {
					// Run repair for specific plugin
					$plugin = Plugin::get_by_plugin_name($plugin_name);
					if (!$plugin) {
						$message = 'Plugin record not found.';
						$message_type = 'warning';
					} else {
						// Clear the install error and reset status to allow retry
						$plugin->set('plg_install_error', null);
						$plugin->set('plg_status', 'inactive');
						$plugin->save();

						// Run the installation process again
						$result = $plugin->install();
						if ($result['success']) {
							$message = 'Plugin "' . htmlspecialchars($plugin_name) . '" repaired successfully:<br>' . implode('<br>', $result['messages']);
							$message_type = 'success';
						} else {
							$message = 'Plugin repair failed:<br>' . implode('<br>', $result['errors']);
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

	return LogicResult::render(array(
		'system_health' => $system_health,
		'message' => $message,
		'message_type' => $message_type,
		'plugins' => $plugins
	));
}
?>
