<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * admin_themes_logic — the Themes page.
 *
 * @version 1.1 - an upload is a root request that root verifies; a refused
 *                one shows the warning and Install anyway (specs/package_signing.md WP6)
 */
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
	// Set when root refused an uploaded package as not ours and the operator
	// is looking at the warning (specs/package_signing.md WP6).
	$unsigned_warning = null;

	// Handle form submissions and GET actions
	$action = isset($input['action']) ? $input['action'] : (isset($input['action']) ? $input['action'] : null);
	if (isset($input['unsigned'])) {
		// Root refused an upload as not ours: show the warning for it.
		$why = '';
		$unsigned_warning = PackageInstallPage::refused('theme', (string)$input['unsigned'], $session, $why);
		if ($unsigned_warning === null) {
			$error = $why;
		}
	} elseif ($action || $input) {
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
						// outside the tree. Root is then asked to install it,
						// and root verifies it against the release key before
						// it moves a byte (RootRequest::PACKAGE_KIND): ours
						// installs, anything else comes back as the warning.
						if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
							$queued = PackageInstallPage::upload('theme', $_FILES['theme_zip']['tmp_name'], (int)$session->get_user_id());
							$root_request_id = $queued['request_id'];
							$message = "Theme '" . $queued['name'] . "' was unpacked and checked, and root is asked to verify and install it.";
						} else {
							$error = "Upload failed. Please check the file and try again.";
						}
						break;

					case 'install_anyway':
						// The owner has read the warning. Behind the
						// second-factor step-up; then the acknowledgement is
						// minted and root is asked again, this time with it.
						$formwriter = new FormWriterV2HTML5(PackageInstallPage::FORM_ID);
						$request_id = (string)($input['request'] ?? '');
						if (!$formwriter->validateCSRF($input)) {
							throw new Exception('Invalid or expired request token. Please try again.');
						}
						$outcome = PackageInstallPage::acknowledge('theme', $request_id, $session,
							'/admin/admin_themes?unsigned=' . rawurlencode($request_id));
						if ($outcome instanceof LogicResult) {
							return $outcome;            // confirm the second factor, then press again
						}
						$root_request_id = $outcome;
						$message = 'Acknowledged. Root is asked to install the unsigned theme under the unsigned restrictions.';
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
		'unsigned_warning' => $unsigned_warning,
		'root_actor_notice' => AdminPage::root_actor_notice()
	));
}
?>
