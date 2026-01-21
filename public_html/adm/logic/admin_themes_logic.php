<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_themes_logic($get, $post) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/themes_class.php'));
	require_once(PathHelper::getIncludePath('includes/ThemeManager.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10); // System admin only
	$session->set_return();

	$theme_manager = ThemeManager::getInstance();
	$message = '';
	$error = '';

	// Handle form submissions and GET actions
	$action = isset($post['action']) ? $post['action'] : (isset($get['action']) ? $get['action'] : null);
	if ($action || $post) {
		try {
			if ($action) {
				switch ($action) {
					case 'activate':
						$theme_name = $post['theme_name'];
						$theme = Theme::get_by_theme_name($theme_name);
						if ($theme) {
							$theme->activate();
							// Re-sync component types for new theme
							$component_result = $theme_manager->syncComponentTypes();
							$message = "Theme '$theme_name' activated successfully.";
							if ($component_result['created'] > 0 || $component_result['updated'] > 0 || $component_result['deactivated'] > 0) {
								$message .= " Components: {$component_result['created']} added, {$component_result['updated']} updated, {$component_result['deactivated']} deactivated.";
							}
						} else {
							$error = "Theme not found.";
						}
						break;

					case 'mark_stock':
						$theme_name = $post['theme_name'];
						$theme = Theme::get_by_theme_name($theme_name);
						if ($theme) {
							$theme->set('thm_is_stock', true);
							$theme->save();
							// Write back to manifest to keep in sync
							$theme_manager->writeManifestStockStatus($theme_name, true);
							$message = "Theme '$theme_name' marked as stock. It will receive updates during deployments.";
						}
						break;

					case 'mark_custom':
						$theme_name = $post['theme_name'];
						$theme = Theme::get_by_theme_name($theme_name);
						if ($theme) {
							// Block marking system themes as custom
							if ($theme->get('thm_is_system')) {
								$error = "Cannot mark system theme '$theme_name' as custom. System themes must always receive updates.";
								break;
							}
							$theme->set('thm_is_stock', false);
							$theme->save();
							// Write back to manifest to keep in sync
							$theme_manager->writeManifestStockStatus($theme_name, false);
							$message = "Theme '$theme_name' marked as custom. It will be preserved during deployments.";
						}
						break;

					case 'sync':
						$sync_result = $theme_manager->sync();
						$parts = array();
						if (!empty($sync_result['added'])) {
							$parts[] = count($sync_result['added']) . " themes added";
						}
						if (!empty($sync_result['updated'])) {
							$parts[] = count($sync_result['updated']) . " themes updated";
						}
						// Component sync results (included in sync() call)
						if (!empty($sync_result['components'])) {
							$c = $sync_result['components'];
							if ($c['created'] > 0 || $c['updated'] > 0 || $c['deactivated'] > 0) {
								$comp_parts = array();
								if ($c['created'] > 0) $comp_parts[] = "{$c['created']} added";
								if ($c['updated'] > 0) $comp_parts[] = "{$c['updated']} updated";
								if ($c['deactivated'] > 0) $comp_parts[] = "{$c['deactivated']} deactivated";
								$parts[] = "components: " . implode(", ", $comp_parts);
							}
						}

						if (empty($parts)) {
							$message = "Filesystem sync completed. Everything is up to date.";
						} else {
							$message = "Sync completed: " . implode("; ", $parts) . ".";
						}
						break;

					case 'upload':
						if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
							$theme_name = $theme_manager->installTheme($_FILES['theme_zip']['tmp_name']);
							$message = "Theme '$theme_name' installed successfully.";
						} else {
							$error = "Upload failed. Please check the file and try again.";
						}
						break;

					case 'delete':
						$theme_name = $post['theme_name'];
						// Use ThemeManager::deleteTheme() which handles files AND database record
						// It also enforces system theme protection and active theme checks
						$theme_manager->deleteTheme($theme_name);
						$message = "Theme '$theme_name' has been completely removed (files and database record).";
						break;
				}
			}
		} catch (Exception $e) {
			$error = $e->getMessage();
		}
	}

	// Load current themes
	$themes = new MultiTheme(array(), array('thm_name' => 'ASC'));
	$themes->load();

	return LogicResult::render(array(
		'message' => $message,
		'error' => $error,
		'themes' => $themes
	));
}
?>
