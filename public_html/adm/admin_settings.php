<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_settings_logic.php'));

	$page_vars = process_logic(admin_settings_logic($_GET, $_POST));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$run_validation = $page_vars['run_validation'];

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
	if($settings->get_setting('upgrade_server_active')){
		$pageoptions['altlinks'] += array('Publish Upgrade'=>'/utils/publish_upgrade');
	}

	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	// Tab menu for settings pages
	$tab_menus = array(
		'General Settings' => '/admin/admin_settings',
		'Payment Settings' => '/admin/admin_settings_payments',
		'Email Settings' => '/admin/admin_settings_email',
	);
	echo AdminPage::tab_menu($tab_menus, 'General Settings');

	$formwriter = $page->getFormWriter('form1');

		?>
		<script type="text/javascript">

		function set_booking_choices(){
			const bookingsActive = document.getElementById('bookings_active');
			const value = bookingsActive ? bookingsActive.value : '';

			const containers = [
				'calendly_organization_uri_container',
				'calendly_organization_name_container',
				'calendly_api_key_container',
				'calendly_api_token_container'
			];

			const display = (value == 0 || value == '') ? 'none' : 'block';

			containers.forEach(function(containerId) {
				const container = document.getElementById(containerId);
				if (container) {
					container.style.display = display;
				}
			});
		}

		function set_blog_choices(){
			const blogActive = document.getElementById('blog_active');
			const value = blogActive ? blogActive.value : '';

			const containers = [
				'show_comments_container',
				'comments_active_container',
				'comments_unregistered_users_container',
				'default_comment_status_container',
				'comment_notification_emails_container',
				'anti_spam_answer_comments_container',
				'use_captcha_comments_container',
				'blog_footer_text_container'
			];

			const display = (value == 0 || value == '') ? 'none' : 'block';

			containers.forEach(function(containerId) {
				const container = document.getElementById(containerId);
				if (container) {
					container.style.display = display;
				}
			});
		}

		function check_social_content(){
			// Check if any social field has content
			const social_field_ids = [
				'social_facebook_link',
				'social_instagram_link',
				'social_soundcloud_link',
				'social_spotify_link',
				'social_youtube_link',
				'social_mixcloud_link',
				'social_discord_link',
				'social_google_link',
				'social_linkedin_link',
				'social_pinterest_link',
				'social_stack_link',
				'social_telegram_link',
				'social_tiktok_link',
				'social_snapchat_link',
				'social_slack_link',
				'social_github_link',
				'social_reddit_link',
				'social_whatsapp_link',
				'social_twitch_link'
			];

			let has_content = false;
			for(let i = 0; i < social_field_ids.length; i++){
				const field = document.getElementById(social_field_ids[i]);
				if(field && field.value && field.value.trim() !== ''){
					has_content = true;
					break;
				}
			}

			// Set the dropdown value based on content
			const socialSettingsActive = document.getElementById('social_settings_active');
			if (socialSettingsActive) {
				socialSettingsActive.value = has_content ? '1' : '0';
			}
		}

		function set_social_choices(){
			const socialSettingsActive = document.getElementById('social_settings_active');
			const value = socialSettingsActive ? socialSettingsActive.value : '';

			const containers = [
				'social_facebook_link_container',
				'social_instagram_link_container',
				'social_soundcloud_link_container',
				'social_spotify_link_container',
				'social_youtube_link_container',
				'social_mixcloud_link_container',
				'social_discord_link_container',
				'social_google_link_container',
				'social_linkedin_link_container',
				'social_pinterest_link_container',
				'social_stack_link_container',
				'social_telegram_link_container',
				'social_tiktok_link_container',
				'social_snapchat_link_container',
				'social_slack_link_container',
				'social_github_link_container',
				'social_reddit_link_container',
				'social_whatsapp_link_container',
				'social_twitch_link_container'
			];

			const display = (value == 0 || value == '') ? 'none' : 'block';

			containers.forEach(function(containerId) {
				const container = document.getElementById(containerId);
				if (container) {
					container.style.display = display;
				}
			});
		}

		function set_tracking_choices(){
			const tracking = document.getElementById('tracking');
			const value = tracking ? tracking.value : '';

			const trackingCodeContainer = document.getElementById('tracking_code_container');
			if (trackingCodeContainer) {
				trackingCodeContainer.style.display = (value == 'custom') ? 'block' : 'none';
			}
		}

		function set_plugin_theme_choices(){
			const themeTemplate = document.getElementById('theme_template');
			const value = themeTemplate ? themeTemplate.value : '';

			const pluginThemeSelector = document.getElementById('plugin_theme_selector');
			if (pluginThemeSelector) {
				pluginThemeSelector.style.display = (value === 'plugin') ? 'block' : 'none';
			}
		}

		function isValidEmail(email) {
			const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			return re.test(email);
		}

		document.addEventListener('DOMContentLoaded', function() {

			set_booking_choices();
			set_blog_choices();
			check_social_content(); // Check content before setting visibility
			set_social_choices();
			set_tracking_choices();
			set_plugin_theme_choices();

			const bookingsActive = document.getElementById('bookings_active');
			if (bookingsActive) {
				bookingsActive.addEventListener('change', set_booking_choices);
			}

			const blogActive = document.getElementById('blog_active');
			if (blogActive) {
				blogActive.addEventListener('change', set_blog_choices);
			}

			const socialSettingsActive = document.getElementById('social_settings_active');
			if (socialSettingsActive) {
				socialSettingsActive.addEventListener('change', set_social_choices);
			}

			const tracking = document.getElementById('tracking');
			if (tracking) {
				tracking.addEventListener('change', set_tracking_choices);
			}

			const themeTemplate = document.getElementById('theme_template');
			if (themeTemplate) {
				themeTemplate.addEventListener('change', set_plugin_theme_choices);
			}

		});

		</script>
		<?php

	$formwriter->begin_form();
	
	if($_SESSION['permission'] == 10){
		
		echo '<b>NOTE: These settings will not override the settings if they are located in the Globalvars_site.php file in the /config directory</b><br>';
		if(StripeHelper::isTestMode()){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}		
		
		echo '<h3>System Settings</h3>';

		// Path Configuration Section
		echo '<h5>Path Configuration</h5>';
		
		// Read Globalvars_site.php to determine what's actually hardcoded
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

		// Base path - check if hardcoded in Globalvars_site.php
		if (isset($globalvars_hardcoded['baseDir'])) {
			$formwriter->textinput('baseDir_readonly', 'Base path (Loaded from Globalvars_site.php)', [
				'value' => $settings->get_setting('baseDir'),
				'readonly' => true
			]);
		} else {
			$formwriter->textinput('baseDir', 'Base path', [
				'value' => $settings->get_setting('baseDir')
			]);
		}

		// Site path - always calculated, read-only
		$formwriter->textinput('siteDir_readonly', 'Site path (Auto-calculated)', [
			'value' => $settings->get_setting('siteDir'),
			'readonly' => true
		]);

		// Static files path - always calculated, read-only
		$formwriter->textinput('static_files_dir_readonly', 'Static files path (Auto-calculated)', [
			'value' => $settings->get_setting('static_files_dir'),
			'readonly' => true
		]);

		// Upload path - always calculated, read-only
		$formwriter->textinput('upload_dir_readonly', 'Upload path (Auto-calculated)', [
			'value' => $settings->get_setting('upload_dir'),
			'readonly' => true
		]);

		// Upload web directory - check if hardcoded in Globalvars_site.php
		if (isset($globalvars_hardcoded['upload_web_dir'])) {
			$formwriter->textinput('upload_web_dir_readonly', 'Upload web directory (Loaded from Globalvars_site.php)', [
				'value' => $settings->get_setting('upload_web_dir'),
				'readonly' => true
			]);
		} else {
			$formwriter->textinput('upload_web_dir', 'Upload web directory', [
				'value' => $settings->get_setting('upload_web_dir'),
				'placeholder' => 'Usually just "uploads" - relative path visible on web'
			]);
		}
		
		// Create dropdown for site folder based on directories under base path
		// Note: baseDir is loaded from Globalvars_site.php and is not editable through admin
		$base_path = $settings->get_setting('baseDir');
		$site_optionvals = array();
		$site_folder_error = '';
		
		if ($base_path && is_dir($base_path)) {
			$site_directories = LibraryFunctions::list_directories_in_directory($base_path, 'filename');
			foreach($site_directories as $site_directory){
				// Skip hidden directories and common system directories
				if (substr($site_directory, 0, 1) !== '.' && $site_directory !== 'lost+found') {
					$site_optionvals[$site_directory] = $site_directory;
				}
			}
			// Add current value if it's not in the list
			$current_site_template = $settings->get_setting('site_template');
			if ($current_site_template && !isset($site_optionvals[$current_site_template])) {
				$site_optionvals[$current_site_template] = $current_site_template . ' (missing)';
			}
		} else {
			// Base path is invalid, show error
			$site_folder_error = '';
			$site_optionvals[''] = 'Base path not configured or invalid';
		}
		
		// Site location - check if it's hardcoded in Globalvars_site.php
		if (isset($globalvars_hardcoded['site_template'])) {
			// It's hardcoded, make it read-only
			$formwriter->textinput('site_template_readonly', 'Site location (Loaded from Globalvars_site.php)', [
				'value' => $settings->get_setting('site_template'),
				'readonly' => true
			]);
		} else {
			// It's database-driven, make it editable
			$formwriter->dropinput('site_template', "Site location (The site we are running, basically the folder at " . htmlspecialchars($base_path) . ")", [
				'options' => $site_optionvals,
				'value' => $settings->get_setting('site_template')
			]);
		}

		// Web URL - check if hardcoded in Globalvars_site.php
		$current_webDir = $settings->get_setting('webDir');
		$webDir_valid = true;

		// Validate webDir format regardless of source (for display purposes)
		if ($current_webDir && (preg_match('/^https?:\/\//', $current_webDir) || substr($current_webDir, -1) === '/')) {
			$webDir_valid = false;
		}

		if (isset($globalvars_hardcoded['webDir'])) {
			$readonly_label = $webDir_valid ? "Web Domain (Loaded from Globalvars_site.php)" : "Web Domain (Loaded from Globalvars_site.php - INVALID FORMAT)";
			$formwriter->textinput('webDir_readonly', $readonly_label, [
				'value' => $current_webDir,
				'readonly' => true
			]);
			if (!$webDir_valid) {
				echo '<div class="text-danger small">webDir should contain domain only (e.g. \'example.com\' or \'localhost:8080\'). Protocol is set by Protocol Mode.</div>';
			}
		} else {
			$formwriter->textinput('webDir', 'Web Domain', [
				'value' => $current_webDir,
				'placeholder' => 'Enter domain only (e.g. example.com or localhost:8080). Protocol is set by Protocol Mode below.',
				'validation' => ['weburl' => true]
			]);
		}
		
		$optionvals = array(
			'auto' => 'Auto-detect',
			'http' => 'HTTP only',
			'https' => 'HTTPS only',
			'https_redirect' => 'HTTPS with redirects'
		);
		// Handle case where protocol_mode setting doesn't exist or is empty
		$protocol_mode_value = $settings->get_setting('protocol_mode', true, true); // fail_silently = true
		if (empty($protocol_mode_value)) {
			$protocol_mode_value = 'auto'; // Default value
		}
		$formwriter->dropinput('protocol_mode', 'Protocol Mode', [
			'options' => $optionvals,
			'value' => $protocol_mode_value,
			'help' => 'Controls protocol for generated URLs and redirect behavior'
		]);

		$formwriter->dropinput('debug_css', 'CSS Debug Mode (tailwind themes only)', [
			'options' => [1 => 'Yes', 0 => 'No'],
			'value' => $settings->get_setting('debug_css')
		]);

		$formwriter->dropinput('show_errors', 'Show errors', [
			'options' => [1 => 'Yes (show to screen)', 0 => 'No (logged)'],
			'value' => $settings->get_setting('show_errors')
		]);		
		
		// Get themes from directory only
		$directory_themes = ThemeHelper::getAvailableThemes();

		// Build options array
		$optionvals = array();

		// Add directory themes only
		foreach($directory_themes as $theme_name => $theme_helper) {
			$display_name = $theme_helper->get('display_name', $theme_name);
			$optionvals[$theme_name] = $display_name;  // FormWriter format: [value => label]
		}

		$formwriter->dropinput('theme_template', 'Active theme', [
			'options' => $optionvals,
			'value' => $settings->get_setting('theme_template')
		]);

		// Always render plugin selector dropdown, JavaScript will control visibility
		// Use existing method to get available plugins
		$available_plugins = PluginHelper::getAvailablePlugins();

		// Create FormWriter dropdown following existing admin_settings pattern
		$current_plugin = $settings->get_setting('active_theme_plugin');

		// Build options array for FormWriter
		$plugin_options = array('' => '-- Select Plugin --');
		foreach ($available_plugins as $plugin_name => $plugin_helper) {
			// PluginHelper::getAvailablePlugins returns array of PluginHelper instances
			$display_name = $plugin_helper->getPluginName();
			$plugin_options[$plugin_name] = $display_name;  // FormWriter format: [value => label]
		}

		// Wrap in a div that JavaScript can show/hide
		// Note: The dropdown inside needs to be ignored by validation when hidden
		$current_theme = $settings->get_setting('theme_template');
		$initial_display = ($current_theme === 'plugin') ? 'block' : 'none';
		echo '<div id="plugin_theme_selector" style="display: ' . $initial_display . ';">';
		$formwriter->dropinput('active_theme_plugin', 'Active Theme Plugin', [
			'options' => $plugin_options,
			'value' => $current_plugin,
			'help' => 'Select which plugin provides the user interface'
		]);
		echo '</div>';

		$formwriter->textinput('webmaster_email', 'Webmaster Email', [
			'value' => $settings->get_setting('webmaster_email')
		]);
		$formwriter->textinput('defaultemail', 'Default Email', [
			'value' => $settings->get_setting('defaultemail')
		]);
		$formwriter->textinput('defaultemailname', 'Default Email Name', [
			'value' => $settings->get_setting('defaultemailname')
		]);

		$formwriter->textinput('site_name', 'Site Name', [
			'value' => $settings->get_setting('site_name')
		]);
		$formwriter->textinput('site_description', 'Site Description', [
			'value' => $settings->get_setting('site_description')
		]);

		$formwriter->textinput('logo_link', 'Link to Logo', [
			'value' => $settings->get_setting('logo_link'),
			'validation' => [
				'remote' => [
					'url' => '/ajax/validate_file_ajax',
					'message' => 'Must start with / and file must exist'
				]
			]
		]);

		$formwriter->textinput('node_dir', 'Node Path (Example: /var/www/html/test/node)', [
			'value' => $settings->get_setting('node_dir')
		]);
		
		// Composer section with two-column layout and package validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Composer Settings</h5>';
		$formwriter->textinput('composerAutoLoad', 'Composer Path (Example: /home/user1/vendor/)', [
			'value' => $settings->get_setting('composerAutoLoad')
		]);
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
		
		$site_template = $settings->get_setting('site_template');
		$formwriter->textinput('apache_error_log', "Apache Error Log Path (Example: /var/www/html/{$site_template}/logs/error.log)", [
			'value' => $settings->get_setting('apache_error_log'),
			'validation' => [
				'remote' => [
					'url' => '/ajax/validate_file_ajax',
					'message' => 'File does not exist or is not readable'
				]
			]
		]);

		$formwriter->textinput('standard_error', 'Standard Error Message', [
			'value' => $settings->get_setting('standard_error')
		]);
		
		echo '<div style="margin: 50px 0;"></div>';
		
		// hCaptcha section with two-column layout
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>hCaptcha Settings</h5>';
		$formwriter->textinput('hcaptcha_public', 'HCaptcha Public Key', [
			'value' => $settings->get_setting('hcaptcha_public')
		]);
		$formwriter->textinput('hcaptcha_private', 'HCaptcha Private Key', [
			'value' => $settings->get_setting('hcaptcha_private')
		]);
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live Preview</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">';
		
		// Show live hCaptcha preview if both keys are configured
		if($settings->get_setting('hcaptcha_public') && $settings->get_setting('hcaptcha_private')) {
			echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ hCaptcha is configured</strong></div>';
			// Use the same captcha rendering method from FormWriter
			echo "<script src='https://www.hCaptcha.com/1/api.js' async defer></script>";
			echo '<div class="h-captcha" data-sitekey="'.$settings->get_setting('hcaptcha_public').'"></div>';
		} else if($settings->get_setting('hcaptcha_public')) {
			echo '<div style="color: #ffc107;"><strong>⚠ Only public key configured</strong></div>';
			echo '<div style="color: #666; font-size: 14px; margin-top: 5px;">Enter private key to complete setup</div>';
		} else {
			echo '<div style="color: #666; text-align: center; padding: 20px;">Enter both keys to see preview</div>';
		}
		
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';
		
		// Google Captcha section with two-column layout
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Google reCAPTCHA Settings</h5>';
		$formwriter->textinput('captcha_public', 'Google Captcha Public Key', [
			'value' => $settings->get_setting('captcha_public')
		]);
		$formwriter->textinput('captcha_private', 'Google Captcha Private Key', [
			'value' => $settings->get_setting('captcha_private')
		]);
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live Preview</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">';
		
		// Show live Google Captcha preview if both keys are configured
		if($settings->get_setting('captcha_public') && $settings->get_setting('captcha_private')) {
			echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Google reCAPTCHA is configured</strong></div>';
			// Use the same captcha rendering method from FormWriter
			echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
			echo '<div class="g-recaptcha" data-sitekey="'.$settings->get_setting('captcha_public').'"></div>';
		} else if($settings->get_setting('captcha_public')) {
			echo '<div style="color: #ffc107;"><strong>⚠ Only public key configured</strong></div>';
			echo '<div style="color: #666; font-size: 14px; margin-top: 5px;">Enter private key to complete setup</div>';
		} else {
			echo '<div style="color: #666; text-align: center; padding: 20px;">Enter both keys to see preview</div>';
		}
		
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

		// Acuity API Key and User ID with validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		$formwriter->textinput('acuity_api_key', 'Acuity API Key (Example: 7d97bfea536935sgd8b14d266b105ab1)', [
			'value' => $settings->get_setting('acuity_api_key')
		]);
		$formwriter->textinput('acuity_user_id', 'Acuity User ID (Example: 18623423)', [
			'value' => $settings->get_setting('acuity_user_id')
		]);
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px;">';
		
		if ($run_validation) {
			$acuity_api_key = $settings->get_setting('acuity_api_key');
			$acuity_user_id = $settings->get_setting('acuity_user_id');
			
			if (!empty($acuity_api_key) && !empty($acuity_user_id)) {
			echo '<h5>API Status</h5>';
			try {
				require_once(PathHelper::getIncludePath('/includes/AcuityScheduling.php'));
				
				$acuity = new AcuityScheduling(array(
					'userId' => $acuity_user_id,
					'apiKey' => $acuity_api_key
				));
				
				// Test the connection by getting account information
				$account = $acuity->request('/me');
				
				if ($account && is_array($account) && isset($account['email'])) {
					echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Connected successfully!</strong></div>';
					echo '<strong>Account:</strong> ' . htmlspecialchars($account['email']) . '<br>';
					if (isset($account['firstName']) && isset($account['lastName'])) {
						echo '<strong>Name:</strong> ' . htmlspecialchars($account['firstName']) . ' ' . htmlspecialchars($account['lastName']) . '<br>';
					}
					if (isset($account['company'])) {
						echo '<strong>Company:</strong> ' . htmlspecialchars($account['company']) . '<br>';
					}
					if (isset($account['timezone'])) {
						echo '<strong>Timezone:</strong> ' . htmlspecialchars($account['timezone']) . '<br>';
					}
				} else {
					echo '<div style="color: #ffc107; margin-bottom: 10px;"><strong>⚠ Connection failed or invalid response</strong></div>';
					echo 'Unable to verify API credentials. Please check your API key and User ID.';
				}
			} catch (Exception $e) {
				echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ Connection failed</strong></div>';
				echo 'Error: ' . htmlspecialchars($e->getMessage());
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter both API Key and User ID to validate connection</div>';
			}
		} else {
			// Show placeholder with "Run Validation" button
			echo '<div style="text-align: center; padding: 40px;">';
			echo '<p style="color: #666; margin-bottom: 15px;">API validation not run yet</p>';
			echo '<a href="?run_validation=1" class="btn btn-primary btn-sm">Run All Validations</a>';
			echo '</div>';
		}
		
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

	}

	echo '<h3>General Settings</h3>';

	$yes_no_options = [1=>"Yes", 0=>'No'];

	$formwriter->textbox('custom_css', 'Custom CSS', [
		'value' => $settings->get_setting('custom_css'),
		'rows' => 10,
		'cols' => 80,
		'htmlmode' => 'no'
	]);

	$formwriter->textinput('preview_image', 'Preview image (for facebook, google, etc)', [
		'value' => $settings->get_setting('preview_image'),
		'validation' => [
			'remote' => [
				'url' => '/ajax/validate_file_ajax',
				'message' => 'File does not exist or is not readable'
			]
		]
	]);

	$formwriter->dropinput('tracking', 'Visit tracking', [
		'options' => ["Use built in tracking"=>'internal', 'Use custom tracking' => 'custom'],
		'value' => $settings->get_setting('tracking')
	]);
	$formwriter->textinput('tracking_code', 'Tracking code', [
		'value' => $settings->get_setting('tracking_code')
	]);

	$formwriter->dropinput('register_active', 'Registration active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('register_active')
	]);

	$formwriter->dropinput('subscriptions_active', 'Subscriptions active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('subscriptions_active')
	]);

	$formwriter->dropinput('default_timezone', 'Default timezone', [
		'options' => Address::get_timezone_drop_array(),
		'value' => $settings->get_setting('default_timezone')
	]);

	$formwriter->textinput('nickname_display_as', 'Nickname display as (blank for no nicknames)', [
		'value' => $settings->get_setting('nickname_display_as')
	]);

	$formwriter->dropinput('activation_required_login', 'Require email activation to log on', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('activation_required_login')
	]);

	$formwriter->dropinput('newsletter_active', 'Newsletter active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('newsletter_active')
	]);

	$formwriter->textinput('subscription_notification_emails', 'Emails to receive subscription notifications (separate with comma)', [
		'value' => $settings->get_setting('subscription_notification_emails')
	]);

	$formwriter->textinput('single_purchase_notification_emails', 'Emails to receive one time purchase notifications (separate with comma)', [
		'value' => $settings->get_setting('single_purchase_notification_emails')
	]);

	$formwriter->dropinput('site_currency', 'Site Currency', [
		'options' => ["US Dollar"=>'usd', 'Euro' => 'eur'],
		'value' => $settings->get_setting('site_currency')
	]);

	$formwriter->textbox('robots_text', 'Robots.txt entry', [
		'value' => $settings->get_setting('robots_text'),
		'rows' => 10,
		'cols' => 80,
		'htmlmode' => 'no'
	]);

	echo '<h3>Survey Settings</h3>';
	$formwriter->dropinput('surveys_active', 'Survey module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('surveys_active')
	]);

	echo '<h3>Blog Settings</h3>';
	$formwriter->dropinput('blog_active', 'Blog module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('blog_active')
	]);

	/*DEPRECATED
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Use blog as homepage", "use_blog_as_homepage", '', $optionvals, $settings->get_setting('use_blog_as_homepage'), '', FALSE);
*/	

	$formwriter->dropinput('show_comments', 'Show comments', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('show_comments')
	]);

	$formwriter->dropinput('comments_active', 'Allow comments', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('comments_active')
	]);

	$formwriter->dropinput('comments_unregistered_users', 'Allow comments from unregistered users', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('comments_unregistered_users')
	]);

	$formwriter->dropinput('default_comment_status', 'Default comment status', [
		'options' => ["Approved"=>'approved', 'Unapproved' => 'unapproved'],
		'value' => $settings->get_setting('default_comment_status')
	]);

	$formwriter->textinput('comment_notification_emails', 'Emails to receive comment notifications (separate with comma)', [
		'value' => $settings->get_setting('comment_notification_emails')
	]);

	$formwriter->textinput('anti_spam_answer_comments', 'Comment anti spam word (blank for none)', [
		'value' => $settings->get_setting('anti_spam_answer')
	]);

	$formwriter->dropinput('use_captcha_comments', 'Use captcha on comments', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('use_captcha_comments')
	]);

	$formwriter->textbox('blog_footer_text', 'Blog footer text', [
		'value' => $settings->get_setting('blog_footer_text'),
		'rows' => 10,
		'cols' => 80,
		'htmlmode' => 'no'
	]);	
 
	echo '<hr>';
 
 	echo '<h3>Spam Settings</h3>';
	$formwriter->dropinput('use_honeypot', 'Use form honeypots', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('use_honeypot')
	]);

	$formwriter->textinput('anti_spam_answer', 'Anti spam word (blank for none)', [
		'value' => $settings->get_setting('anti_spam_answer')
	]);

	$formwriter->dropinput('use_captcha', 'Use captcha', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('use_captcha')
	]);	
	
	echo '<hr>';

 	echo '<h3>Social Settings</h3>';

	$formwriter->dropinput('social_settings_active', 'Social settings active', [
		'options' => $yes_no_options,
		'value' => '0'
	]);

	$formwriter->textinput('social_facebook_link', 'Facebook link', [
		'value' => $settings->get_setting('social_facebook_link')
	]);

	$formwriter->textinput('social_instagram_link', 'Instagram link', [
		'value' => $settings->get_setting('social_instagram_link')
	]);

	$formwriter->textinput('social_soundcloud_link', 'Soundcloud link', [
		'value' => $settings->get_setting('social_soundcloud_link')
	]);

	$formwriter->textinput('social_spotify_link', 'Spotify link', [
		'value' => $settings->get_setting('social_spotify_link')
	]);

	$formwriter->textinput('social_youtube_link', 'Youtube link', [
		'value' => $settings->get_setting('social_youtube_link')
	]);

	$formwriter->textinput('social_mixcloud_link', 'Mixcloud link', [
		'value' => $settings->get_setting('social_mixcloud_link')
	]);

	$formwriter->textinput('social_discord_link', 'Discord link', [
		'value' => $settings->get_setting('social_discord_link')
	]);

	$formwriter->textinput('social_google_link', 'Google link', [
		'value' => $settings->get_setting('social_google_link')
	]);

	$formwriter->textinput('social_linkedin_link', 'Linkedin link', [
		'value' => $settings->get_setting('social_linkedin_link')
	]);

	$formwriter->textinput('social_pinterest_link', 'Pinterest link', [
		'value' => $settings->get_setting('social_pinterest_link')
	]);

	$formwriter->textinput('social_stack_link', 'Stack Overflow link', [
		'value' => $settings->get_setting('social_stack_link')
	]);

	$formwriter->textinput('social_telegram_link', 'Telegram link', [
		'value' => $settings->get_setting('social_telegram_link')
	]);

	$formwriter->textinput('social_tiktok_link', 'Tiktok link', [
		'value' => $settings->get_setting('social_tiktok_link')
	]);

	$formwriter->textinput('social_snapchat_link', 'Snapchat link', [
		'value' => $settings->get_setting('social_snapchat_link')
	]);

	$formwriter->textinput('social_slack_link', 'Slack link', [
		'value' => $settings->get_setting('social_slack_link')
	]);
	$formwriter->textinput('social_github_link', 'Github link', [
		'value' => $settings->get_setting('social_github_link')
	]);
	$formwriter->textinput('social_reddit_link', 'Reddit link', [
		'value' => $settings->get_setting('social_reddit_link')
	]);
	$formwriter->textinput('social_whatsapp_link', 'Whatsapp link', [
		'value' => $settings->get_setting('social_whatsapp_link')
	]);
	$formwriter->textinput('social_twitch_link', 'Twitch link', [
		'value' => $settings->get_setting('social_twitch_link')
	]);

	echo '<h3>Booking Settings</h3>';

	$formwriter->dropinput('bookings_active', 'Booking module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('bookings_active')
	]);

	$formwriter->textinput('calendly_organization_uri', 'Calendly Organization URI (Example: https://api.calendly.com/organizations/EHDBUSLIPJFCKXAE)', [
		'value' => $settings->get_setting('calendly_organization_uri')
	]);
	$formwriter->textinput('calendly_organization_name', 'Calendly Organization Name (Example: test-organization)', [
		'value' => $settings->get_setting('calendly_organization_name')
	]);
	$formwriter->textinput('calendly_api_key', 'Calendly API Key (Example: INEEMNBGGN53553SDFGBESNICRDW74)', [
		'value' => $settings->get_setting('calendly_api_key')
	]);
	$formwriter->textbox('calendly_api_token', 'Calendly API Token (Example: eyJraWQiOiIxY2UxZT...ZWJjY)', [
		'value' => $settings->get_setting('calendly_api_token'),
		'rows' => 10,
		'cols' => 80,
		'htmlmode' => 'no'
	]);

 	echo '<h3>Events Settings</h3>';
	$formwriter->dropinput('events_active', 'Event module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('events_active')
	]);

	$formwriter->textinput('events_label', 'Events label', [
		'value' => $settings->get_setting('events_label')
	]);

 	echo '<h3>Product Settings</h3>';
	$formwriter->dropinput('products_active', 'Product module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('products_active')
	]);

	$formwriter->dropinput('products_list_items_active', 'List regular products on product index', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('products_list_items_active')
	]);

	$formwriter->dropinput('products_list_events_active', 'List event products on product index', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('products_list_events_active')
	]);

	$formwriter->dropinput('coupons_active', 'Allow coupon codes', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('coupons_active')
	]);

	$formwriter->dropinput('pricing_page', 'Activate pricing (/pricing) page', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('pricing_page')
	]);

	$max_subscriptions_per_user = 0;
	if($settings->get_setting('max_subscriptions_per_user')){
		$max_subscriptions_per_user = $settings->get_setting('max_subscriptions_per_user');
	}
	$formwriter->textinput('max_subscriptions_per_user', 'Max number of subscriptions per user (0 for no limit)', [
		'value' => $max_subscriptions_per_user
	]);

	// Subscription Tier Management Settings
	echo '<h4>Subscription Tier Management</h4>';
	echo '<p class="text-muted">Control how users can change their subscription tiers</p>';

	$formwriter->dropinput('subscription_downgrades_enabled', 'Allow subscription downgrades', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('subscription_downgrades_enabled'),
		'help' => 'Allow users to downgrade to lower subscription tiers'
	]);

	$formwriter->dropinput('subscription_downgrade_timing', 'Downgrade timing', [
		'options' => ["Immediate"=>'immediate', 'End of billing period' => 'end_of_period'],
		'value' => $settings->get_setting('subscription_downgrade_timing'),
		'help' => 'When downgrades take effect (only applies if downgrades are enabled)'
	]);

	$formwriter->dropinput('subscription_cancellation_enabled', 'Allow subscription cancellations', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('subscription_cancellation_enabled'),
		'help' => 'Allow users to cancel their subscriptions'
	]);

	$formwriter->dropinput('subscription_cancellation_timing', 'Cancellation timing', [
		'options' => ["Immediate"=>'immediate', 'End of billing period' => 'end_of_period'],
		'value' => $settings->get_setting('subscription_cancellation_timing'),
		'help' => 'When cancellations take effect'
	]);

	$formwriter->dropinput('subscription_cancellation_prorate', 'Issue prorated refunds on cancellation', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('subscription_cancellation_prorate'),
		'help' => 'Issue refunds for unused time (only for immediate cancellations)'
	]);

	$formwriter->dropinput('subscription_reactivation_enabled', 'Allow subscription reactivation', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('subscription_reactivation_enabled'),
		'help' => 'Allow users to reactivate cancelled subscriptions before they expire'
	]);
	
	echo '<hr>';

	echo '<h3>File Hosting Settings</h3>';
	$formwriter->dropinput('files_active', 'File hosting module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('files_active')
	]);

	if(!$settings->get_setting('allowed_upload_extensions')){
		$allowed_upload_extensions = 'gif,jpeg,jpg,png,pdf,xls,doc,xlsx,docx,mp3,mp4,m4a';
	} else {
		$allowed_upload_extensions = $settings->get_setting('allowed_upload_extensions');
	}
	$formwriter->textinput('allowed_upload_extensions', 'Allowed file upload extensions (comma separated)', [
		'value' => $allowed_upload_extensions
	]);

	echo '<h3>Cookie Consent</h3>';
	$formwriter->dropinput('cookie_consent_mode', 'Cookie consent mode', [
		'options' => [
			'off' => 'Off',
			'auto' => 'Auto-detect by location (Recommended)',
			'gdpr' => 'GDPR (Opt-in required)',
			'ccpa' => 'CCPA (Opt-out)'
		],
		'value' => $settings->get_setting('cookie_consent_mode'),
		'help' => 'Auto detects visitor location. GDPR requires consent before setting cookies. CCPA allows opt-out.'
	]);
	$formwriter->textinput('cookie_privacy_policy_link', 'Privacy policy URL', [
		'value' => $settings->get_setting('cookie_privacy_policy_link'),
		'prepend' => rtrim($settings->get_setting('webDir'), '/') . '/',
		'help' => 'Path to your privacy policy page (shown in consent banner)'
	]);

	echo '<h3>Video Settings</h3>';
	$formwriter->dropinput('videos_active', 'Video module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('videos_active')
	]);

	echo '<h3>CMS Settings</h3>';
	$formwriter->dropinput('page_contents_active', 'CMS module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('page_contents_active')
	]);

	$pages = new MultiPage(
		array('deleted' => false, 'published' => true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$pages->load();
	$optionvals = $pages->get_dropdown_array_link();
	// Prepend "Page - " to each page label for clarity
	foreach ($optionvals as $url => $label) {
		$optionvals[$url] = 'Page - ' . $label;
	}
	$optionvals['/blog'] = 'Blog homepage';
	$formwriter->dropinput('alternate_homepage', 'Alternate page to use as homepage (optional)', [
		'options' => $optionvals,
		'value' => $settings->get_setting('alternate_homepage'),
		'empty_option' => true
	]);

	$formwriter->textinput('alternate_loggedin_homepage', 'Alternate page to use as logged in homepage (optional)', [
		'value' => $settings->get_setting('alternate_loggedin_homepage')
	]);

	echo '<h3>Url Rewrite Settings</h3>';
	$formwriter->dropinput('urls_active', 'Url rewrite module active', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('urls_active')
	]);

	echo '<h3>Upgrade Settings</h3>';
	$formwriter->dropinput('upgrade_server_active', 'Act as upgrade server', [
		'options' => $yes_no_options,
		'value' => $settings->get_setting('upgrade_server_active')
	]);
	if(!$upgrade_source = $settings->get_setting('upgrade_source')){
		$upgrade_source = 'https://getjoinery.com';
	}
	$formwriter->textinput('upgrade_source', 'Upgrade source', [
		'value' => $upgrade_source
	]);

	echo '<hr><h2>Plugin Settings</h2>';

	// Scan and include plugin settings forms directly in this page
	$plugins = LibraryFunctions::list_plugins();
	foreach($plugins as $plugin) {
		$settings_form = PathHelper::getIncludePath("plugins/$plugin/settings_form.php");
		if(file_exists($settings_form)) {
			echo "<div class='plugin-settings-section'>";
			echo "<h4>" . ucfirst($plugin) . " Plugin</h4>";
			include($settings_form);
			echo "</div>";
		}
	}

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>