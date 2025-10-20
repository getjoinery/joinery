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
							$message = "Theme '$theme_name' activated successfully.";
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
							$message = "Theme '$theme_name' marked as stock.";
						}
						break;

					case 'mark_custom':
						$theme_name = $post['theme_name'];
						$theme = Theme::get_by_theme_name($theme_name);
						if ($theme) {
							$theme->set('thm_is_stock', false);
							$theme->save();
							$message = "Theme '$theme_name' marked as custom.";
						}
						break;

					case 'sync':
						$sync_result = $theme_manager->sync();
						$parts = array();
						if (!empty($sync_result['added'])) {
							$parts[] = count($sync_result['added']) . " added";
						}
						if (!empty($sync_result['updated'])) {
							$parts[] = count($sync_result['updated']) . " updated";
						}

						if (empty($parts)) {
							$message = "Filesystem sync completed. All themes are already up to date.";
						} else {
							$message = "Filesystem sync completed: " . implode(", ", $parts) . ".";
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
						$theme = Theme::get_by_theme_name($theme_name);
						if ($theme) {
							// Only allow deletion if theme files are missing or it's not active
							if (!$theme->theme_files_exist() || !$theme->get('thm_is_active')) {
								$theme->permanent_delete();
								$message = "Theme '$theme_name' has been deleted from the database.";
							} else {
								$error = "Cannot delete an active theme with existing files.";
							}
						} else {
							$error = "Theme not found.";
						}
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
