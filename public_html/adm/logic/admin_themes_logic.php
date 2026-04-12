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

						// Gate activation on theme's requires.joinery (if any). Fail closed with
						// a clear error that matches the badge format on the themes list page.
						$req_path = PathHelper::getAbsolutePath("theme/{$theme_name}/theme.json");
						if (file_exists($req_path)) {
							$req_manifest = json_decode(file_get_contents($req_path), true);
							if (!empty($req_manifest['requires']['joinery'])) {
								require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
								$jv = LibraryFunctions::get_joinery_version();
								$required = $req_manifest['requires']['joinery'];
								$op = '>=';
								$ver = $required;
								if (preg_match('/^([><=]+)(.+)$/', $required, $m)) { $op = $m[1]; $ver = $m[2]; }
								if ($jv === '') {
									throw new Exception("Cannot activate theme '$theme_name': requires Joinery $required, but installed Joinery version could not be determined.");
								}
								if (!version_compare($jv, $ver, $op)) {
									throw new Exception("Cannot activate theme '$theme_name': requires Joinery $required, this site is $jv.");
								}
							}
						}

						$theme_manager->activate($theme_name);
						$message = "Theme '$theme_name' activated successfully.";

						// Warn if activating a deprecated theme
						$manifest_path = PathHelper::getAbsolutePath("theme/{$theme_name}/theme.json");
						if (file_exists($manifest_path)) {
							$manifest = json_decode(file_get_contents($manifest_path), true);
							if (!empty($manifest['deprecated'])) {
								$replacement = $manifest['superseded_by'] ?? null;
								$message .= $replacement
									? " Warning: this theme is deprecated. Use '$replacement' instead."
									: " Warning: this theme is deprecated.";
							}
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

					case 'sync_filesystem':
						$result = $theme_manager->sync();

						$parts = [];
						if (!empty($result['added'])) {
							$parts[] = count($result['added']) . ' new theme(s) discovered';
						}
						if (!empty($result['updated'])) {
							$parts[] = count($result['updated']) . ' theme(s) updated';
						}
						if (!empty($result['components'])) {
							$c = $result['components'];
							$component_parts = [];
							if (!empty($c['created']) && $c['created'] > 0) $component_parts[] = $c['created'] . ' created';
							if (!empty($c['updated']) && $c['updated'] > 0) $component_parts[] = $c['updated'] . ' updated';
							if (!empty($c['deactivated']) && $c['deactivated'] > 0) $component_parts[] = $c['deactivated'] . ' deactivated';
							if (!empty($component_parts)) {
								$parts[] = 'components: ' . implode(', ', $component_parts);
							}
						}

						if (empty($parts)) {
							$message = 'Sync complete. Everything is up to date.';
						} else {
							$message = 'Sync complete: ' . implode(', ', $parts) . '.';
						}
						break;
				}
			}
		} catch (Exception $e) {
			$error = $e->getMessage();
		}
	}

	// Load current themes (filesystem + database merge)
	$themes = Theme::get_all_themes_with_status();

	return LogicResult::render(array(
		'message' => $message,
		'error' => $error,
		'themes' => $themes
	));
}
?>
