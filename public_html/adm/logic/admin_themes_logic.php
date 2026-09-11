<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_themes_logic(array $input): LogicResult {
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
	// Set when an action was queued for the root actor rather than done here.
	$root_request_id = '';
	// Set when an upload was staged and the operator has to finish it in a shell.
	$staged_command = '';

	// Handle form submissions and GET actions
	$action = isset($input['action']) ? $input['action'] : (isset($input['action']) ? $input['action'] : null);
	if ($action || $input) {
		try {
			if ($action) {
				switch ($action) {
					case 'activate':
						$theme_name = $input['theme_name'];

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

					// The flag lives in two places because two readers need it:
					// the database row, which the admin list shows, and
					// theme.json, which is what the upgrade actually consults
					// on the target site. The manifest is in the code tree, so
					// writing it is a root request (specs/read_only_tree.md);
					// the row is written here and now so the page tells the
					// truth immediately.
					case 'mark_upgradable':
					case 'mark_preserved':
						$theme_name = $input['theme_name'];
						$upgradable = ($action === 'mark_upgradable');
						$theme = Theme::get_by_theme_name($theme_name);
						if ($theme) {
							$theme->set('thm_receives_upgrades', $upgradable);
							$theme->save();
							$root_request_id = RootRequest::submit('set_receives_upgrades',
								array('type' => 'theme', 'name' => $theme_name, 'value' => $upgradable),
								(int)$session->get_user_id());
							$message = $upgradable
								? "Theme '$theme_name' will be replaced from the upgrade payload during deploy."
								: "Theme '$theme_name' will be preserved on deploy (receives_upgrades=false).";
						}
						break;

					case 'upload':
						// Unpacked and checked here, under uploads/staging,
						// outside the tree. Installed from a shell: a theme is
						// PHP, and this queue is www-data-writable, so a kind
						// that installed a staged directory would turn one
						// file-write bug into code the site runs. See
						// RootRequest::NO_PACKAGE_KIND.
						if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
							$staged = $theme_manager->stage($_FILES['theme_zip']['tmp_name']);
							$staged_command = 'sudo -u ' . escapeshellarg(PluginManager::tree_owner_name()) . ' php '
								. escapeshellarg(PathHelper::getIncludePath('utils/install_extension.php'))
								. ' theme --staged=' . escapeshellarg($staged['dir']);
							$message = "Theme '" . $staged['name'] . "' was unpacked and checked. Installing an "
								. 'uploaded package writes code into the tree, which is done from a shell '
								. 'rather than from this page — run:';
						} else {
							$error = "Upload failed. Please check the file and try again.";
						}
						break;

					case 'delete':
						$theme_name = $input['theme_name'];
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
		'themes' => $themes,
		'root_request_id' => $root_request_id,
		'staged_command' => $staged_command,
		'root_actor_notice' => AdminPage::root_actor_notice()
	));
}
?>
