<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

/**
 * admin_themes_logic — the Themes page.
 *
 * @version 1.3 - an upload of an installed name shows the replace panel
 *                (Replace / Discard) instead of queueing; the Local fork badge
 *                with the shipped version beside it (specs/package_replace_on_upload.md WP2, WP3)
 * @version 1.2 - Apply and Remove for a style theme: the look slot beside the
 *                page theme (specs/style_themes.md WP4)
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
	// Set when an upload names an installed theme and the operator is asked
	// to replace it or discard the upload (specs/package_replace_on_upload.md).
	$replace_panel = null;

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
								? "The next deploy will replace your copy of '$theme_name' with the version Joinery ships."
								: "Theme '$theme_name' is a local fork: deploys leave it alone until Allow upgrade is pressed.";
						}
						break;

					case 'upload':
						// Unpacked and checked here, under uploads/staging,
						// outside the tree. Root is then asked to install it,
						// and root verifies it against the release key before
						// it moves a byte (RootRequest::PACKAGE_KIND): ours
						// installs, anything else comes back as the warning.
						// An installed name is not queued: the replace panel
						// asks first.
						if (isset($_FILES['theme_zip']) && $_FILES['theme_zip']['error'] === UPLOAD_ERR_OK) {
							$outcome = PackageInstallPage::upload('theme', $_FILES['theme_zip']['tmp_name'], (int)$session->get_user_id());
							if ($outcome['outcome'] === 'pending_replace') {
								$why = '';
								$replace_panel = PackageInstallPage::pending('theme', $outcome['staged_dir'], $why);
								if ($replace_panel === null) {
									throw new Exception($why);
								}
							} else {
								$root_request_id = $outcome['request_id'];
								$message = "Theme '" . $outcome['name'] . "' was unpacked and checked, and root is asked to verify and install it.";
							}
						} else {
							$error = "Upload failed. Please check the file and try again.";
						}
						break;

					case 'replace_staged':
					case 'discard_staged':
						// The replace panel's two answers. Replace queues the
						// request with replace: true; Discard removes the
						// staged upload. Both carry the panel's token and the
						// staged directory, checked against staging.
						$formwriter = new FormWriterV2HTML5(PackageInstallPage::REPLACE_FORM_ID);
						$staged_dir = (string)($input['staged_dir'] ?? '');
						if (!$formwriter->validateCSRF($input)) {
							throw new Exception('Invalid or expired request token. Please try again.');
						}
						if ($action === 'replace_staged') {
							$root_request_id = PackageInstallPage::confirmReplace('theme', $staged_dir, (int)$session->get_user_id());
							$message = 'Root is asked to verify the uploaded theme and replace the installed copy with it; the previous copy is kept beside it.';
						} else {
							$name = PackageInstallPage::discard('theme', $staged_dir);
							$message = "The uploaded copy of '$name' is discarded. Nothing was changed.";
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

					// The look slot: a style theme layers over the active page
					// theme instead of replacing it. Both writes go through the
					// vault gate the theme_look setting declares.
					case 'apply_look':
						$theme_name = (string)($input['theme_name'] ?? '');
						$theme_manager->applyLook($theme_name);
						$message = "'$theme_name' is applied as the look. Your page theme stays active.";
						break;

					case 'remove_look':
						$theme_manager->removeLook();
						$message = 'The look is removed. Pages use the active theme\'s styling alone.';
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
						if (!empty($result['look_cleared'])) {
							$parts[] = 'look cleared (' . $result['look_cleared'] . ')';
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

	// The version the source ships for each forked theme, so the Local fork
	// badge can say how far behind the fork is. From the day-old copy, looked
	// up only when a fork exists; null means nothing is known. The root node's
	// catalog is its own tree, so there it would compare a fork with itself.
	$fork_versions = null;
	foreach ($themes as $entry) {
		if (MarketplaceClient::is_root()) {
			break;
		}
		if (!empty($entry['theme']) && $entry['directory_exists'] && !$entry['theme']->receives_upgrades()) {
			try {
				$fork_versions = MarketplaceClient::published_versions('themes');
			} catch (Throwable $e) {
				error_log('admin_themes_logic: catalog version lookup failed: ' . $e->getMessage());
			}
			break;
		}
	}

	return LogicResult::render(array(
		'message' => $message,
		'error' => $error,
		'themes' => $themes,
		'replace_panel' => $replace_panel,
		'fork_versions' => $fork_versions,
		// The two slots: the page theme and the look (specs/style_themes.md).
		'page_theme' => (string)Globalvars::get_instance()->get_setting('theme_template', true, true),
		'look' => $theme_manager->activeLook(),
		'root_request_id' => $root_request_id,
		'unsigned_warning' => $unsigned_warning,
		'root_actor_notice' => AdminPage::root_actor_notice()
	));
}
?>
