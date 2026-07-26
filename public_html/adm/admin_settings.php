<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_settings_logic.php'));

	$page_vars = process_logic(admin_settings_logic(array_merge($_GET, $_POST)));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$run_validation = $page_vars['run_validation'];
	$error_message = $page_vars['error_message'] ?? null;

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> NULL,
		'page_title' => 'Settings',
		'readable_title' => 'Settings',
		'breadcrumbs' => array(
			'Settings'=>'',
		),
		'session' => $session,
	)
	);

	$pageoptions['altlinks'] = array('Public Menu'=>'/admin/admin_public_menu');
	$pageoptions['altlinks'] += array('Admin Menu'=>'/admin/admin_admin_menu');
	$pageoptions['altlinks'] += array('API Keys'=>'/admin/admin_api_keys');
	$pageoptions['altlinks'] += array('Upgrade'=>'/utils/upgrade');
	$pageoptions['altlinks'] += array('Refresh Themes'=>'/utils/upgrade?theme-only=1');

	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	// Cloudflare Flexible SSL detection — if CF is terminating SSL at the edge
	// but proxying to origin over HTTP, surface a warning. The Apache vhost has
	// a CF-Visitor guard that prevents the redirect loop, but CF<->origin
	// traffic is unencrypted and the operator probably wants to know.
	$cf_visitor    = $_SERVER['HTTP_CF_VISITOR'] ?? '';
	$cf_says_https = $cf_visitor !== '' && strpos($cf_visitor, '"scheme":"https"') !== false;
	$origin_is_http = empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off';
	if ($cf_says_https && $origin_is_http) {
		echo '<div class="alert alert-warning" role="alert">'
		   . '<strong>Cloudflare is in Flexible SSL mode.</strong> '
		   . 'Cloudflare is serving HTTPS to visitors but talking to this origin over plain HTTP. '
		   . 'The site keeps running thanks to a compatibility guard in the Apache vhost, '
		   . 'but Cloudflare&harr;origin traffic is unencrypted and a missing guard would cause an infinite redirect loop. '
		   . 'Recommended fix: in the Cloudflare dashboard for this zone, set <strong>SSL/TLS &rarr; Overview</strong> to '
		   . '<strong>Full (strict)</strong>. The origin already has a valid Let&rsquo;s Encrypt certificate, so the switch should be immediate.'
		   . '</div>';
	}

	echo AdminPage::settings_tab_menu('General Settings');

	$formwriter = $page->getFormWriter('form1');

	$formwriter->begin_form();

	if ($error_message): ?>
		<div class="alert alert-danger">
			<?php echo htmlspecialchars($error_message); ?>
		</div>
	<?php endif;

	if($_SESSION['permission'] == 10){

		echo '<b>NOTE: These settings will not override the settings if they are located in the Globalvars_site.php file in the /config directory</b><br>';
		if((isset($_SESSION['test_mode']) && $_SESSION['test_mode']) || $settings->get_setting('debug')){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}

		echo '<h3>System Settings</h3>';

		// A path pinned in Globalvars_site.php cannot be changed from here, so
		// the page shows it read-only under a reserved *_readonly name and asks
		// the renderer to leave the editable field out. Which paths those are is
		// a property of this deployment, not of the setting.
		$globalvars_site_path = dirname(__DIR__, 2) . '/config/Globalvars_site.php';
		$globalvars_hardcoded = array();

		if (file_exists($globalvars_site_path)) {
			$globalvars_content = file_get_contents($globalvars_site_path);

			// Parse the file to find $this->settings assignments
			if (preg_match_all('/\$this->settings\[\'([^\']+)\'\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $globalvars_content, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$globalvars_hardcoded[$match[1]] = $match[2];
				}
			}

			// Also check for $this->settings["key"] = "value" format
			if (preg_match_all('/\$this->settings\["([^"]+)"\]\s*=\s*[\'"]([^\'"]*)[\'"];/', $globalvars_content, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$globalvars_hardcoded[$match[1]] = $match[2];
				}
			}
		}

		$pinned_paths = array_intersect(
			array('baseDir', 'webDir', 'site_template', 'upload_web_dir'),
			array_keys($globalvars_hardcoded)
		);

		SettingsFieldRenderer::renderGroups($formwriter, array('paths'), array(
			'heading_level' => 'h5',
			'skip'          => $pinned_paths,
			'field_options' => array(
				'site_template' => array(
					'helptext_append' => 'Folders found under ' . htmlspecialchars((string)$settings->get_setting('baseDir')) . '.',
				),
				'apache_error_log' => array(
					'helptext_append' => 'For example /var/www/html/'
						. htmlspecialchars((string)$settings->get_setting('site_template')) . '/logs/error.log.',
					'validation' => array(
						'remote' => array(
							'url' => '/api/v1/action/validate_server_file',
							'dataFieldName' => 'value',
							'data' => array('field' => 'apache_error_log'),
							'message' => 'File does not exist or is not readable',
						),
					),
				),
			),
		));

		// Paths this deployment does not let anyone edit: the ones Globalvars
		// calculates, and the ones Globalvars_site.php pins.
		$readonly_paths = array(
			'siteDir'          => 'Site path (auto-calculated)',
			'static_files_dir' => 'Static files path (auto-calculated)',
			'upload_dir'       => 'Upload path (auto-calculated)',
		);
		foreach ($pinned_paths as $name) {
			$declaration = SettingsDeclarations::get($name);
			$readonly_paths[$name] = ($declaration['label'] ?? $name) . ' (loaded from Globalvars_site.php)';
		}
		foreach ($readonly_paths as $name => $label) {
			$formwriter->textinput($name . '_readonly', $label, array(
				'value'    => $settings->get_setting($name),
				'readonly' => true,
			));
		}

		// webDir is what every absolute URL is built from, so a value the rest
		// of the platform cannot use is worth saying out loud even when the
		// field itself is not editable here.
		$current_webDir = $settings->get_setting('webDir');
		if (in_array('webDir', $pinned_paths, true) && $current_webDir
			&& (preg_match('/^https?:\/\//', $current_webDir) || substr($current_webDir, -1) === '/')) {
			echo '<div class="text-danger small">webDir should be the domain only (e.g. example.com or '
			   . 'localhost:8080). The protocol comes from Protocol Mode.</div>';
		}

		SettingsFieldRenderer::renderGroups($formwriter, array('protocol', 'debug', 'diagnostics', 'theme'), array(
			'heading_level' => 'h5',
		));

		echo '<h3>Brand &amp; Appearance</h3>';
		echo '<p class="text-muted">Override the UI kit brand colors. Leave blank to use the active theme&rsquo;s declared brand (shown under each field), or the kit default if the theme declares none.</p>';

		// Active public theme's declared brand tokens — surfaced as the effective
		// default so a blank field still shows what color is actually in use.
		$theme_brand = [];
		try {
			$theme_brand = ThemeHelper::getInstance()->get('brand_tokens', []);
			if (!is_array($theme_brand)) { $theme_brand = []; }
		} catch (Throwable $e) { /* no resolvable theme — kit defaults apply */ }

		$brand_notes = array();
		foreach (SettingsFieldRenderer::namesFor('brand', 'core') as $token) {
			if (isset($theme_brand[$token]) && is_string($theme_brand[$token])) {
				$brand_notes[$token] = array('helptext_append' => 'Theme default: ' . $theme_brand[$token] . '.');
			}
		}
		SettingsFieldRenderer::renderGroup($formwriter, 'brand', array(
			'source'        => 'core',
			'field_options' => $brand_notes,
		));

		// Composer section with two-column layout and package validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		SettingsFieldRenderer::renderGroups($formwriter, array('composer'), array('heading_level' => 'h5'));
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Installed Packages</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';

		if ($run_validation) {
			$composer_path = $settings->get_setting('composerAutoLoad');
			if ($composer_path && !empty(trim($composer_path))) {
				$autoload_path = rtrim($composer_path, '/') . '/autoload.php';
				$composer_lock = rtrim($composer_path, '/') . '/../composer.lock';
				$composer_json = rtrim($composer_path, '/') . '/../composer.json';

				if (file_exists($autoload_path)) {
					echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Valid Composer installation</strong></div>';

				// Get direct dependencies from composer.json
				$direct_dependencies = [];
				if (file_exists($composer_json)) {
					$json_content = @file_get_contents($composer_json);
					if ($json_content) {
						$json_data = json_decode($json_content, true);
						if (isset($json_data['require'])) {
							$direct_dependencies = array_keys($json_data['require']);
						}
					}
				}

				// Get all installed packages from composer.lock
				$all_packages = [];
				$direct_packages = [];
				$sub_packages = [];

				if (file_exists($composer_lock)) {
					$lock_content = @file_get_contents($composer_lock);
					if ($lock_content) {
						$lock_data = json_decode($lock_content, true);
						if (isset($lock_data['packages'])) {
							foreach ($lock_data['packages'] as $package) {
								$pkg_info = [
									'name' => $package['name'],
									'version' => $package['version'] ?? 'unknown'
								];

								$all_packages[] = $pkg_info;

								// Separate direct vs sub-dependencies
								if (in_array($package['name'], $direct_dependencies)) {
									$direct_packages[] = $pkg_info;
								} else {
									$sub_packages[] = $pkg_info;
								}
							}
						}
					}
				}

				// For backward compatibility, keep $packages as all packages
				$packages = $all_packages;

				// Show key packages we use FIRST
				$key_packages = ['mailgun/mailgun-php', 'stripe/stripe-php', 'phpmailer/phpmailer'];
				$found_key_packages = array_filter($packages, function($pkg) use ($key_packages) {
					return in_array($pkg['name'], $key_packages);
				});

				if (!empty($found_key_packages)) {
					echo '<div style="margin-bottom: 12px; padding: 8px; background: #d4edda; border-radius: 4px;">';
					echo '<div style="font-size: 12px; color: #155724; font-weight: bold; margin-bottom: 4px;">Key packages detected:</div>';
					foreach ($found_key_packages as $pkg) {
						$version = $pkg['version'];
						// Don't add 'v' if version already starts with 'v'
						$version_display = (strpos($version, 'v') === 0) ? $version : 'v' . $version;
						echo '<div style="font-size: 11px; color: #155724;">✓ ' . htmlspecialchars($pkg['name']) . ' <span style="color: #666;">' . htmlspecialchars($version_display) . '</span></div>';
					}
					echo '</div>';
				} else if (!empty($packages)) {
					echo '<div style="margin-bottom: 12px; padding: 8px; background: #fff3cd; border-radius: 4px;">';
					echo '<div style="font-size: 11px; color: #856404;">⚠ No key packages detected (mailgun, stripe, phpmailer)</div>';
					echo '</div>';
				}

				if (!empty($packages)) {
					echo '<div style="font-size: 12px; color: #666; margin-bottom: 8px;"><strong>' . count($packages) . ' total packages installed:</strong></div>';
					echo '<div style="font-size: 11px; color: #666; margin-bottom: 8px;">' . count($direct_packages) . ' direct dependencies, ' . count($sub_packages) . ' sub-dependencies</div>';

					// Show direct dependencies first
					if (!empty($direct_packages)) {
						echo '<div style="margin-bottom: 12px;">';
						echo '<div style="font-size: 11px; color: #495057; font-weight: bold; margin-bottom: 4px; padding: 2px 5px; background: #e9ecef; border-radius: 3px;">📦 Direct Dependencies</div>';
						foreach ($direct_packages as $package) {
							$version = $package['version'];
							$version_display = (strpos($version, 'v') === 0) ? $version : 'v' . $version;
							echo '<div style="font-size: 11px; color: #333; margin-bottom: 2px; padding: 2px 5px; background: white; border-radius: 3px; border-left: 3px solid #007bff;">';
							echo '<code style="color: #007bff;">' . htmlspecialchars($package['name']) . '</code> ';
							echo '<span style="color: #666;">' . htmlspecialchars($version_display) . '</span>';
							echo '</div>';
						}
						echo '</div>';
					}

					// Show sub-dependencies (collapsed by default if many)
					if (!empty($sub_packages)) {
						$show_all_sub = count($sub_packages) <= 10;
						echo '<div style="margin-bottom: 8px;">';
						echo '<div style="font-size: 11px; color: #6c757d; font-weight: bold; margin-bottom: 4px; padding: 2px 5px; background: #f8f9fa; border-radius: 3px;">🔗 Sub-Dependencies</div>';

						if ($show_all_sub) {
							// Show all if 10 or fewer
							foreach ($sub_packages as $package) {
								$version = $package['version'];
								$version_display = (strpos($version, 'v') === 0) ? $version : 'v' . $version;
								echo '<div style="font-size: 10px; color: #6c757d; margin-bottom: 1px; padding: 1px 5px; background: #f8f9fa; border-radius: 2px;">';
								echo '<code style="color: #6c757d;">' . htmlspecialchars($package['name']) . '</code> ';
								echo '<span style="color: #999;">' . htmlspecialchars($version_display) . '</span>';
								echo '</div>';
							}
						} else {
							// Show first 5 and collapse button
							foreach (array_slice($sub_packages, 0, 5) as $package) {
								$version = $package['version'];
								$version_display = (strpos($version, 'v') === 0) ? $version : 'v' . $version;
								echo '<div style="font-size: 10px; color: #6c757d; margin-bottom: 1px; padding: 1px 5px; background: #f8f9fa; border-radius: 2px;">';
								echo '<code style="color: #6c757d;">' . htmlspecialchars($package['name']) . '</code> ';
								echo '<span style="color: #999;">' . htmlspecialchars($version_display) . '</span>';
								echo '</div>';
							}
							echo '<div style="font-size: 10px; color: #999; margin-top: 4px; font-style: italic;">... and ' . (count($sub_packages) - 5) . ' more sub-dependencies</div>';
						}
						echo '</div>';
					}
				} else {
					echo '<div style="color: #ffc107; font-size: 12px;">No packages found in composer.lock</div>';
				}

			} else {
				echo '<div style="color: #dc3545;"><strong>✗ Invalid path</strong></div>';
				echo '<div style="color: #666; font-size: 12px; margin-top: 5px;">Could not find: <code>' . htmlspecialchars($autoload_path) . '</code></div>';
				echo '<div style="color: #666; font-size: 11px; margin-top: 8px;">Make sure the path points to the vendor directory containing autoload.php</div>';
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 30px 10px;">Enter Composer path to see installed packages</div>';
			}
		} else {
			// Show placeholder with "Run Validation" button
			echo '<div style="text-align: center; padding: 40px;">';
			echo '<p style="color: #666; margin-bottom: 15px;">Package validation not run yet</p>';
			echo '<a href="?run_validation=1" class="btn btn-primary btn-sm">Run All Validations</a>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

		// Captcha keys, with a live widget beside them so a wrong key is
		// obvious without leaving the page.
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		SettingsFieldRenderer::renderGroups($formwriter, array('captcha'), array('heading_level' => 'h5'));
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live Preview</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">';

		if($settings->get_setting('hcaptcha_public') && $settings->get_setting('hcaptcha_private')) {
			echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ hCaptcha is configured</strong></div>';
			echo "<script src='https://www.hCaptcha.com/1/api.js' async defer></script>";
			echo '<div class="h-captcha" data-sitekey="'.htmlspecialchars($settings->get_setting('hcaptcha_public')).'"></div>';
		} else if($settings->get_setting('hcaptcha_public')) {
			echo '<div style="color: #ffc107;"><strong>⚠ hCaptcha: only the site key is set</strong></div>';
			echo '<div style="color: #666; font-size: 14px; margin-top: 5px;">Enter the secret key to complete setup</div>';
		}

		if($settings->get_setting('captcha_public') && $settings->get_setting('captcha_private')) {
			echo '<div style="color: #28a745; margin: 10px 0;"><strong>✓ Google reCAPTCHA is configured</strong></div>';
			echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
			echo '<div class="g-recaptcha" data-sitekey="'.htmlspecialchars($settings->get_setting('captcha_public')).'"></div>';
		} else if($settings->get_setting('captcha_public')) {
			echo '<div style="color: #ffc107; margin-top: 10px;"><strong>⚠ reCAPTCHA: only the site key is set</strong></div>';
			echo '<div style="color: #666; font-size: 14px; margin-top: 5px;">Enter the secret key to complete setup</div>';
		}

		if(!$settings->get_setting('hcaptcha_public') && !$settings->get_setting('captcha_public')) {
			echo '<div style="color: #666; text-align: center; padding: 20px;">Enter a site key and its secret to see a preview</div>';
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

		SettingsFieldRenderer::renderGroups($formwriter, array(
			'email_identity',
			'api',
			'api_rate_limits',
			'logging',
			'upgrade',
			'cloud_storage',
			'oauth',
			'dns',
			'mobile_apps',
		), array('heading_level' => 'h5'));

		echo '<hr>';
	}

	SettingsFieldRenderer::renderGroups($formwriter, array(
		'site_identity',
		'general',
		'tracking',
		'notifications',
		'surveys',
		'blog',
		'spam',
		'social',
		'files',
		'drive',
		'videos',
		'cms',
		'urls',
		'messaging',
		'two_factor',
		'passkeys',
		'vault_unlock',
		'cookie_consent',
		'tier_gating',
	), array(
		'heading_level' => 'h3',
		'field_options' => array(
			'cookie_privacy_policy_link' => array(
				'prepend' => rtrim((string)$settings->get_setting('webDir'), '/') . '/',
			),
		),
	));

	echo '<hr>';

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
