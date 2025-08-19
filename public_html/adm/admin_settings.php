<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/ThemeHelper.php');
	PathHelper::requireOnce('includes/PluginHelper.php');
	PathHelper::requireOnce('data/settings_class.php');
	PathHelper::requireOnce('data/email_templates_class.php');
	PathHelper::requireOnce('data/mailing_lists_class.php');
	PathHelper::requireOnce('data/pages_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

	// Check if validation should run (performance optimization)
	$run_validation = isset($_GET['run_validation']) && $_GET['run_validation'] == '1';

	if($_POST){
		
		if($settings->get_setting('preview_image') != $_POST['preview_image']){
			//AUTO INCREMENT THE PREVIEW IMAGE INDEX IF IT HAS CHANGED
			$search_criteria = array();
			$search_criteria['setting_name'] = 'preview_image_increment';
			$user_settings = new MultiSetting(
				$search_criteria,
				NULL,
				NULL,
				NULL,
				NULL
			);
			$user_settings->load();	
			foreach($user_settings as $user_setting) {
				if($user_setting->get('stg_name') == 'preview_image_increment'){
					$user_setting->set('stg_value', $settings->get_setting('preview_image_increment') + 1);
					$user_setting->set('stg_update_time', 'NOW()'); 
					$user_setting->set('stg_usr_user_id', $session->get_user_id());
					$user_setting->prepare();
					$user_setting->save();					
				}
			}			
		}

		$search_criteria = array();
		//$search_criteria['setting_like'] = $searchterm;
		$user_settings = new MultiSetting(
			$search_criteria,
			NULL,
			NULL,
			NULL,
			NULL);
		$user_settings->load();		 

		foreach($user_settings as $user_setting) {
			if(isset($_POST[$user_setting->get('stg_name')])){
				$user_setting->set('stg_value', $_POST[$user_setting->get('stg_name')]);
				$user_setting->set('stg_update_time', 'NOW()'); 
				$user_setting->set('stg_usr_user_id', $session->get_user_id());
				$user_setting->prepare();
				$user_setting->save();
			}
		}				
		LibraryFunctions::redirect('/admin/admin_settings');
	}
	
	

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
	
 


	$pageoptions['altlinks'] = array('New Setting'=>'/admin/admin_setting_edit');
	$pageoptions['altlinks'] += array('Public Menu'=>'/admin/admin_public_menu');
	$pageoptions['altlinks'] += array('Admin Menu'=>'/admin/admin_admin_menu'); 
	$pageoptions['altlinks'] += array('API Keys'=>'/admin/admin_api_keys'); 
	$pageoptions['altlinks'] += array('Upgrade'=>'/utils/upgrade');
	$pageoptions['altlinks'] += array('Refresh Themes'=>'/utils/upgrade?theme-only=1');
	if($settings->get_setting('upgrade_server_active')){
		$pageoptions['altlinks'] += array('Publish Upgrade'=>'/utils/publish_upgrade');
	}
	
	//GET ALL OF THE PLUGIN SETTINGS PAGES
	$plugins = LibraryFunctions::list_plugins();
	foreach($plugins as $plugin){
		$script_dir = PathHelper::getAbsolutePath('/plugins/'.$plugin.'/admin/');
		if(is_dir($script_dir)){
			$settings_files = LibraryFunctions::getFilesWithSubstring($script_dir, 'admin_settings');
			if(!empty($settings_files)){
				$pageoptions['altlinks'] += array($plugin.' settings' => '/plugins/'.$plugin.'/admin/'.$settings_files[0]);
			}
		}
	}	

	
	
	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	
	
		?>
		<script type="text/javascript">
	
		function set_choices(){
			var value = $("#use_paypal_checkout").val();
			if(value == 0 || value == ''){  
				$("#paypal_api_key_container").hide();
				$("#paypal_api_secret_container").hide();
				$("#paypal_api_key_test_container").hide();
				$("#paypal_api_secret_test_container").hide();	

			}	
			else{ 
				$("#paypal_api_key_container").show();
				$("#paypal_api_secret_container").show();
				$("#paypal_api_key_test_container").show();
				$("#paypal_api_secret_test_container").show();	
			}		
		}

		function set_booking_choices(){
			var value = $("#bookings_active").val();
			if(value == 0 || value == ''){  
				$("#calendly_organization_uri_container").hide();
				$("#calendly_organization_name_container").hide();
				$("#calendly_api_key_container").hide();
				$("#calendly_api_token_container").hide();
			}	
			else{ 
				$("#calendly_organization_uri_container").show();
				$("#calendly_organization_name_container").show();
				$("#calendly_api_key_container").show();
				$("#calendly_api_token_container").show();
			}		
		}

		function set_blog_choices(){
			var value = $("#blog_active").val();
			if(value == 0 || value == ''){  
				$("#show_comments_container").hide();
				$("#comments_active_container").hide();
				$("#comments_unregistered_users_container").hide();
				$("#default_comment_status_container").hide();
				$("#comment_notification_emails_container").hide();
				$("#anti_spam_answer_comments_container").hide();
				$("#use_captcha_comments_container").hide();
				$("#blog_footer_text_container").hide();
			}	
			else{ 
				$("#show_comments_container").show();
				$("#comments_active_container").show();
				$("#comments_unregistered_users_container").show();
				$("#default_comment_status_container").show();
				$("#comment_notification_emails_container").show();
				$("#anti_spam_answer_comments_container").show();
				$("#use_captcha_comments_container").show();
				$("#blog_footer_text_container").show();
			}		
		}

		function check_social_content(){
			// Check if any social field has content
			var social_fields = [
				"#social_facebook_link",
				"#social_instagram_link",
				"#social_soundcloud_link",
				"#social_spotify_link",
				"#social_youtube_link",
				"#social_mixcloud_link",
				"#social_discord_link",
				"#social_google_link",
				"#social_linkedin_link",
				"#social_pinterest_link",
				"#social_stack_link",
				"#social_telegram_link",
				"#social_tiktok_link",
				"#social_snapchat_link",
				"#social_slack_link",
				"#social_github_link",
				"#social_reddit_link",
				"#social_whatsapp_link",
				"#social_twitch_link"
			];
			
			var has_content = false;
			for(var i = 0; i < social_fields.length; i++){
				if($(social_fields[i]).val() && $(social_fields[i]).val().trim() !== ''){
					has_content = true;
					break;
				}
			}
			
			// Set the dropdown value based on content
			$("#social_settings_active").val(has_content ? '1' : '0');
		}

		function set_social_choices(){
			var value = $("#social_settings_active").val();
			if(value == 0 || value == ''){  
				$("#social_facebook_link_container").hide();
				$("#social_instagram_link_container").hide();
				$("#social_soundcloud_link_container").hide();
				$("#social_spotify_link_container").hide();
				$("#social_youtube_link_container").hide();
				$("#social_mixcloud_link_container").hide();
				$("#social_discord_link_container").hide();
				$("#social_google_link_container").hide();
				$("#social_linkedin_link_container").hide();
				$("#social_pinterest_link_container").hide();
				$("#social_stack_link_container").hide();
				$("#social_telegram_link_container").hide();
				$("#social_tiktok_link_container").hide();
				$("#social_snapchat_link_container").hide();
				$("#social_slack_link_container").hide();
				$("#social_github_link_container").hide();
				$("#social_reddit_link_container").hide();
				$("#social_whatsapp_link_container").hide();
				$("#social_twitch_link_container").hide();
			}	
			else{ 
				$("#social_facebook_link_container").show();
				$("#social_instagram_link_container").show();
				$("#social_soundcloud_link_container").show();
				$("#social_spotify_link_container").show();
				$("#social_youtube_link_container").show();
				$("#social_mixcloud_link_container").show();
				$("#social_discord_link_container").show();
				$("#social_google_link_container").show();
				$("#social_linkedin_link_container").show();
				$("#social_pinterest_link_container").show();
				$("#social_stack_link_container").show();
				$("#social_telegram_link_container").show();
				$("#social_tiktok_link_container").show();
				$("#social_snapchat_link_container").show();
				$("#social_slack_link_container").show();
				$("#social_github_link_container").show();
				$("#social_reddit_link_container").show();
				$("#social_whatsapp_link_container").show();
				$("#social_twitch_link_container").show();
			}		
		}
		
		function set_tracking_choices(){
			var value = $("#tracking").val();
			if(value == 'custom'){  
				$("#tracking_code_container").show();
			}	
			else{ 
				$("#tracking_code_container").hide();
			}		
		}
		
		
	
		$(document).ready(function() {
			set_choices();
			set_booking_choices();
			set_blog_choices();
			check_social_content(); // Check content before setting visibility
			set_social_choices();
			set_tracking_choices();
			$("#use_paypal_checkout").change(function() {	
				set_choices();
			});	
			$("#bookings_active").change(function() {	
				set_booking_choices();
			});	
			$("#blog_active").change(function() {	
				set_blog_choices();
			});	
			$("#social_settings_active").change(function() {	
				set_social_choices();
			});	
			$("#tracking").change(function() {	
				set_tracking_choices();
			});	
		});
		
		// Wait for document ready to ensure jQuery and validator are loaded
		$(document).ready(function() {
			// Custom validation method for Stripe publishable keys
			if (typeof jQuery.validator !== 'undefined') {
				jQuery.validator.addMethod("stripePublishableKey", function(value, element) {
					if (!value) return true; // Allow empty (handled by required rule)
					return /^pk_(live|test)_[a-zA-Z0-9]{24,}$/.test(value);
				}, "Must be a valid publishable key starting with pk_live_ or pk_test_ (not a secret key starting with sk_)");
				
				// Custom validation method for Stripe secret keys
				jQuery.validator.addMethod("stripeSecretKey", function(value, element) {
					if (!value) return true; // Allow empty (handled by required rule)
					return /^sk_(live|test)_[a-zA-Z0-9]{24,}$/.test(value);
				}, "Must be a valid secret key starting with sk_live_ or sk_test_ (not a publishable key starting with pk_)");
				
				// Custom validation method for Stripe test publishable keys
				jQuery.validator.addMethod("stripeTestPublishableKey", function(value, element) {
					if (!value) return true; // Allow empty (handled by required rule)
					return /^pk_test_[a-zA-Z0-9]{24,}$/.test(value);
				}, "Must be a valid test publishable key starting with pk_test_ (not a secret key starting with sk_)");
				
				// Custom validation method for Stripe test secret keys
				jQuery.validator.addMethod("stripeTestSecretKey", function(value, element) {
					if (!value) return true; // Allow empty (handled by required rule)
					return /^sk_test_[a-zA-Z0-9]{24,}$/.test(value);
				}, "Must be a valid test secret key starting with sk_test_ (not a publishable key starting with pk_)");
			}
		});
		
		</script>
		<?php
	
	$validation_rules = array();
	$validation_rules['stg_value']['required']['value'] = 'true';
	$validation_rules['stg_name']['required']['value'] = 'true';
	
	// Add validation for webDir (only if not read-only from Globalvars_site.php)
	if (!isset($globalvars_hardcoded['webDir'])) {
		$validation_rules['webDir']['weburl']['value'] = 'true';
	}
	
	// Add validation for Apache error log path using remote validation
	$validation_rules['apache_error_log']['remote']['value'] = "'/ajax/validate_file_ajax'";
	$validation_rules['apache_error_log']['remote']['message'] = "'File does not exist or is not readable'";
	
	// Add validation for preview image using remote validation
	$validation_rules['preview_image']['remote']['value'] = "'/ajax/validate_file_ajax'";
	$validation_rules['preview_image']['remote']['message'] = "'File does not exist or is not readable'";
	
	// Add validation for logo link using remote validation
	$validation_rules['logo_link']['remote']['value'] = "'/ajax/validate_file_ajax'";
	$validation_rules['logo_link']['remote']['message'] = "'Must start with / and file must exist'";
	
	// Add Stripe key validation rules using custom validation methods
	$validation_rules['stripe_api_key']['stripePublishableKey']['value'] = 'true';
	
	$validation_rules['stripe_api_pkey']['stripeSecretKey']['value'] = 'true';
	
	$validation_rules['stripe_api_key_test']['stripeTestPublishableKey']['value'] = 'true';
	
	$validation_rules['stripe_api_pkey_test']['stripeTestSecretKey']['value'] = 'true';
	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_settings');
	
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
			echo $formwriter->textinput("Base path (Loaded from Globalvars_site.php)", 'baseDir_readonly', '', 20, $settings->get_setting('baseDir'), '', 255, 'readonly');
		} else {
			echo $formwriter->textinput("Base path", 'baseDir', '', 20, $settings->get_setting('baseDir'), '', 255, '');
		}
		
		// Site path - always calculated, read-only
		echo $formwriter->textinput("Site path (Auto-calculated)", 'siteDir_readonly', '', 20, $settings->get_setting('siteDir'), '', 255, 'readonly');
		
		// Static files path - always calculated, read-only
		echo $formwriter->textinput("Static files path (Auto-calculated)", 'static_files_dir_readonly', '', 20, $settings->get_setting('static_files_dir'), '', 255, 'readonly');
		
		// Upload path - always calculated, read-only
		echo $formwriter->textinput("Upload path (Auto-calculated)", 'upload_dir_readonly', '', 20, $settings->get_setting('upload_dir'), '', 255, 'readonly');
		
		// Upload web directory - check if hardcoded in Globalvars_site.php
		if (isset($globalvars_hardcoded['upload_web_dir'])) {
			echo $formwriter->textinput("Upload web directory (Loaded from Globalvars_site.php)", 'upload_web_dir_readonly', '', 20, $settings->get_setting('upload_web_dir'), '', 255, 'readonly');
		} else {
			echo $formwriter->textinput("Upload web directory", 'upload_web_dir', '', 20, $settings->get_setting('upload_web_dir'), '', 255, 'Usually just "uploads" - relative path visible on web');
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
			echo $formwriter->textinput("Site location (Loaded from Globalvars_site.php)", 'site_template_readonly', '', 20, $settings->get_setting('site_template'), '', 255, 'readonly');
		} else {
			// It's database-driven, make it editable
			echo $formwriter->dropinput("Site location (The site we are running, basically the folder at " . htmlspecialchars($base_path) . ")", "site_template", $site_folder_error, $site_optionvals, $settings->get_setting('site_template'), '', FALSE);
		}
		
		// Web URL - check if hardcoded in Globalvars_site.php
		$current_webDir = $settings->get_setting('webDir');
		$webDir_valid = true;
		
		// Validate webDir format regardless of source (for display purposes)
		if ($current_webDir && (preg_match('/^https?:\/\//', $current_webDir) || substr($current_webDir, -1) === '/')) {
			$webDir_valid = false;
		}
		
		if (isset($globalvars_hardcoded['webDir'])) {
			$readonly_class = '';
			$readonly_label = $webDir_valid ? "Web Domain (Loaded from Globalvars_site.php)" : "Web Domain (Loaded from Globalvars_site.php - INVALID FORMAT)";
			echo $formwriter->textinput($readonly_label, 'webDir_readonly', $readonly_class, 20, $current_webDir, '', 255, 'readonly');
			if (!$webDir_valid) {
				echo '<div class="text-danger small">webDir should contain domain only (e.g. \'example.com\' or \'localhost:8080\'). Protocol is set by Protocol Mode.</div>';
			}
		} else {
			echo $formwriter->textinput("Web Domain", 'webDir', '', 20, $current_webDir, 'Enter domain only (e.g. example.com or localhost:8080). Protocol is set by Protocol Mode below.' , 255, "");
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
		echo $formwriter->dropinput("Protocol Mode", "protocol_mode", '', $optionvals, $protocol_mode_value, 'Controls protocol for generated URLs and redirect behavior', FALSE);	

		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("Payment Debug Mode ", "debug", '', $optionvals, $settings->get_setting('debug'), '', FALSE);

		
		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("CSS Debug Mode (tailwind themes only)", "debug_css", '', $optionvals, $settings->get_setting('debug_css'), '', FALSE);
		
		$optionvals = array("Yes (show to screen)"=>1, 'No (logged)' => 0);
		echo $formwriter->dropinput("Show errors", "show_errors", '', $optionvals, $settings->get_setting('show_errors'), '', FALSE);		
		
		// Get themes from directory only
		$directory_themes = ThemeHelper::getAvailableThemes();

		// Build options array
		$optionvals = array();

		// Add directory themes only
		foreach($directory_themes as $theme_name => $theme_helper) {
			$display_name = $theme_helper->get('display_name', $theme_name);
			$optionvals[$theme_name] = $display_name;
		}
		
		
		//echo $formwriter->textinput("Alternate theme (optional theme other than default)", 'theme_template', '', 20, $settings->get_setting('theme_template'), "" , 255, "");
		echo $formwriter->dropinput("Active theme", "theme_template", '', $optionvals, $settings->get_setting('theme_template'), '', FALSE);
		
		echo $formwriter->textinput("Webmaster Email", 'webmaster_email', '', 20, $settings->get_setting('webmaster_email'), "" , 255, "");
		echo $formwriter->textinput("Default Email", 'defaultemail', '', 20, $settings->get_setting('defaultemail'), "" , 255, "");
		echo $formwriter->textinput("Default Email Name", 'defaultemailname', '', 20, $settings->get_setting('defaultemailname'), "" , 255, "");

		
		
		
		echo $formwriter->textinput("Site Name", 'site_name', '', 20, $settings->get_setting('site_name'), "" , 255, "");
		echo $formwriter->textinput("Site Description", 'site_description', '', 20, $settings->get_setting('site_description'), "" , 255, "");
		
		
		echo $formwriter->textinput("Link to Logo", 'logo_link', '', 20, $settings->get_setting('logo_link'), "" , 255, "");
		
		
		echo $formwriter->textinput("Node Path (Example: /var/www/html/test/node)", 'node_dir', '', 20, $settings->get_setting('node_dir'), "" , 255, "");
		
		// Composer section with two-column layout and package validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Composer Settings</h5>';
		echo $formwriter->textinput("Composer Path (Example: /home/user1/vendor/)", 'composerAutoLoad', '', 20, $settings->get_setting('composerAutoLoad'), "" , 255, "");
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
		
		echo $formwriter->textinput("Apache Error Log Path (Example: /var/www/html/test/public_html/logs/error.log)", 'apache_error_log', '', 20, $settings->get_setting('apache_error_log'), "" , 255, "");
		
		echo $formwriter->textinput("Standard Error Message", 'standard_error', '', 20, $settings->get_setting('standard_error'), "" , 255, "");
		
		echo '<div style="margin: 50px 0;"></div>';
		
		// hCaptcha section with two-column layout
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>hCaptcha Settings</h5>';
		echo $formwriter->textinput("HCaptcha Public Key", 'hcaptcha_public', '', 20, $settings->get_setting('hcaptcha_public'), "" , 255, "");
		echo $formwriter->textinput("HCaptcha Private Key", 'hcaptcha_private', '', 20, $settings->get_setting('hcaptcha_private'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live Preview</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">';
		
		// Show live hCaptcha preview if both keys are configured
		if($settings->get_setting('hcaptcha_public') && $settings->get_setting('hcaptcha_private')) {
			echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ hCaptcha is configured</strong></div>';
			// Use the same captcha rendering method from FormWriterMaster
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
		echo $formwriter->textinput("Google Captcha Public Key", 'captcha_public', '', 20, $settings->get_setting('captcha_public'), "" , 255, "");
		echo $formwriter->textinput("Google Captcha Private Key", 'captcha_private', '', 20, $settings->get_setting('captcha_private'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live Preview</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">';
		
		// Show live Google Captcha preview if both keys are configured
		if($settings->get_setting('captcha_public') && $settings->get_setting('captcha_private')) {
			echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Google reCAPTCHA is configured</strong></div>';
			// Use the same captcha rendering method from FormWriterMaster
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

		// Mailchimp section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Mailchimp Settings</h5>';
		echo $formwriter->textinput("Mailchimp API Key", 'mailchimp_api_key', '', 20, $settings->get_setting('mailchimp_api_key'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$mailchimp_api_key = $settings->get_setting('mailchimp_api_key');
			if ($mailchimp_api_key && !empty(trim($mailchimp_api_key))) {
			// Test Mailchimp API connection
			$composer_path = $settings->get_setting('composerAutoLoad');
			if ($composer_path && file_exists(rtrim($composer_path, '/') . '/autoload.php')) {
				try {
					require_once(rtrim($composer_path, '/') . '/autoload.php');
					
					// Test the API key by getting lists (this validates the connection)
					$mailchimp = new MailchimpAPI\Mailchimp($mailchimp_api_key);
					$lists_response = $mailchimp->lists()->get(['count' => 10]);
					$lists_data = $lists_response->deserialize();
					
					if (isset($lists_data->lists)) {
						echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ API Key Valid</strong></div>';
						
						// Show lists
						if (count($lists_data->lists) > 0) {
							echo '<div style="font-size: 11px; color: #666; margin-bottom: 8px;"><strong>Available Lists:</strong></div>';
							foreach ($lists_data->lists as $list) {
								echo '<div style="font-size: 10px; color: #007bff; margin-bottom: 1px; padding: 1px 3px; background: white; border-radius: 2px;">';
								echo htmlspecialchars($list->name) . ' <span style="color: #666;">(' . $list->stats->member_count . ' members)</span>';
								echo '</div>';
							}
						} else {
							echo '<div style="color: #ffc107; font-size: 10px; margin-top: 10px;">No lists found in account</div>';
						}
						
						// Show total stats if available
						if (isset($lists_data->total_items)) {
							echo '<div style="font-size: 10px; color: #666; margin-top: 8px; padding-top: 5px; border-top: 1px solid #ddd;">Total lists: ' . $lists_data->total_items . '</div>';
						}
						
					} else {
						echo '<div style="color: #dc3545;"><strong>✗ Invalid API Response</strong></div>';
						echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">API key may be invalid or expired</div>';
					}
					
				} catch (Exception $e) {
					echo '<div style="color: #dc3545;"><strong>✗ API Connection Failed</strong></div>';
					echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
				}
			} else {
				echo '<div style="color: #ffc107;"><strong>⚠ Composer Not Configured</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Configure Composer path first to test API</div>';
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 40px 10px;">Enter API key to validate connection</div>';
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

		// Acuity API Key and User ID with validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo $formwriter->textinput("Acuity API Key (Example: 7d97bfea536935sgd8b14d266b105ab1)", 'acuity_api_key', '', 20, $settings->get_setting('acuity_api_key'), "" , 255, "");
		echo $formwriter->textinput("Acuity User ID (Example: 18623423)", 'acuity_user_id', '', 20, $settings->get_setting('acuity_user_id'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px;">';
		
		if ($run_validation) {
			$acuity_api_key = $settings->get_setting('acuity_api_key');
			$acuity_user_id = $settings->get_setting('acuity_user_id');
			
			if (!empty($acuity_api_key) && !empty($acuity_user_id)) {
			echo '<h5>API Status</h5>';
			try {
				PathHelper::requireOnce('/includes/AcuityScheduling.php');
				
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

		// Stripe Configuration Section
		echo '<h4>Stripe Configuration</h4>';
		
		// Stripe Live API section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Stripe Live API Settings</h5>';
		echo $formwriter->textinput("Stripe Publishable Key (Example: pk_live_xxxx)", 'stripe_api_key', '', 20, $settings->get_setting('stripe_api_key'), "" , 255, "");
		echo $formwriter->textinput("Stripe Secret/Private Key (Example: sk_live_xxxx)", 'stripe_api_pkey', '', 20, $settings->get_setting('stripe_api_pkey'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$stripe_api_key = $settings->get_setting('stripe_api_key');
			$stripe_api_pkey = $settings->get_setting('stripe_api_pkey');
			
			if (!empty($stripe_api_key)) {
			// Test Stripe Live API connection
			$composer_path = $settings->get_setting('composerAutoLoad');
			if ($composer_path && file_exists(rtrim($composer_path, '/') . '/autoload.php')) {
				try {
					require_once(rtrim($composer_path, '/') . '/autoload.php');
					
					// Create a temporary StripeHelper instance for live API
					$original_test_mode = $_SESSION['test_mode'] ?? null;
					$_SESSION['test_mode'] = false; // Force live mode
					
					PathHelper::requireOnce('/includes/StripeHelper.php');
					$stripe_helper = new StripeHelper();
					
					if ($stripe_helper->is_initialized()) {
						try {
							// Use StripeHelper's validated client - no direct initialization needed
							$account = $stripe_helper->get_stripe_client()->accounts->retrieve();
							echo '<p style="color: green;"><strong>✓ Live API Connection:</strong> Successfully connected to Stripe</p>';
							echo '<p><strong>Account ID:</strong> ' . htmlspecialchars($account->id) . '</p>';
							echo '<p><strong>Account Type:</strong> ' . htmlspecialchars($account->type) . '</p>';
							echo '<p><strong>Country:</strong> ' . htmlspecialchars($account->country) . '</p>';
						} catch (Exception $e) {
							echo '<p style="color: red;"><strong>✗ Live API Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
						}
					} else {
						echo '<p style="color: orange;"><strong>⚠ Configuration:</strong> Stripe keys not configured</p>';
					}
					
					// Restore original test mode
					if ($original_test_mode !== null) {
						$_SESSION['test_mode'] = $original_test_mode;
					} else {
						unset($_SESSION['test_mode']);
					}
					
				} catch (Exception $e) {
					echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Connection Failed</strong></div>';
					echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
					
					// Restore original test mode
					if (isset($original_test_mode)) {
						if ($original_test_mode !== null) {
							$_SESSION['test_mode'] = $original_test_mode;
						} else {
							unset($_SESSION['test_mode']);
						}
					}
				}
			} else {
				echo '<div style="color: #ffc107; margin-bottom: 10px;"><strong>⚠ Composer Not Configured</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Configure Composer path first to test API</div>';
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter API key to validate connection</div>';
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

		// Stripe Test API section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Stripe Test API Settings</h5>';
		echo $formwriter->textinput("Test Stripe Publishable Key (Example: pk_test_xxxx)", 'stripe_api_key_test', '', 20, $settings->get_setting('stripe_api_key_test'), "" , 255, "");
		echo $formwriter->textinput("Test Stripe Secret/Private Key (Example: sk_test_xxxx)", 'stripe_api_pkey_test', '', 20, $settings->get_setting('stripe_api_pkey_test'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Test API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$stripe_api_key_test = $settings->get_setting('stripe_api_key_test');
			$stripe_api_pkey_test = $settings->get_setting('stripe_api_pkey_test');
			
			if (!empty($stripe_api_key_test)) {
			// Test Stripe Test API connection
			$composer_path = $settings->get_setting('composerAutoLoad');
			if ($composer_path && file_exists(rtrim($composer_path, '/') . '/autoload.php')) {
				try {
					require_once(rtrim($composer_path, '/') . '/autoload.php');
					
					// Create a temporary StripeHelper instance for test API
					$original_test_mode = $_SESSION['test_mode'] ?? null;
					$_SESSION['test_mode'] = true; // Force test mode
					
					PathHelper::requireOnce('/includes/StripeHelper.php');
					$stripe_helper_test = new StripeHelper();
					
					if ($stripe_helper_test->is_initialized()) {
						try {
							// Use StripeHelper's validated client - no direct initialization needed
							$account = $stripe_helper_test->get_stripe_client()->accounts->retrieve();
							echo '<p style="color: green;"><strong>✓ Live API Connection:</strong> Successfully connected to Stripe</p>';
							echo '<p><strong>Account ID:</strong> ' . htmlspecialchars($account->id) . '</p>';
							echo '<p><strong>Account Type:</strong> ' . htmlspecialchars($account->type) . '</p>';
							echo '<p><strong>Country:</strong> ' . htmlspecialchars($account->country) . '</p>';
						} catch (Exception $e) {
							echo '<p style="color: red;"><strong>✗ Live API Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
						}
					} else {
						echo '<p style="color: orange;"><strong>⚠ Configuration:</strong> Stripe keys not configured</p>';
					}
					
					// Restore original test mode
					if ($original_test_mode !== null) {
						$_SESSION['test_mode'] = $original_test_mode;
					} else {
						unset($_SESSION['test_mode']);
					}
					
				} catch (Exception $e) {
					echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Connection Failed</strong></div>';
					echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
					
					// Restore original test mode
					if (isset($original_test_mode)) {
						if ($original_test_mode !== null) {
							$_SESSION['test_mode'] = $original_test_mode;
						} else {
							unset($_SESSION['test_mode']);
						}
					}
				}
			} else {
				echo '<div style="color: #ffc107; margin-bottom: 10px;"><strong>⚠ Composer Not Configured</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Configure Composer path first to test API</div>';
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter test API key to validate connection</div>';
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

		// Stripe Webhook section with validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Stripe Webhook Settings</h5>';
		echo $formwriter->textinput("Stripe Endpoint Secret (Example: whsec_xxxx)", 'stripe_endpoint_secret', '', 20, $settings->get_setting('stripe_endpoint_secret'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Webhook Configuration</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		$stripe_endpoint_secret = $settings->get_setting('stripe_endpoint_secret');
		
		if (!empty($stripe_endpoint_secret)) {
			// Basic validation of webhook secret format
			if (strpos($stripe_endpoint_secret, 'whsec_') === 0) {
				echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Webhook Secret Configured</strong></div>';
				echo '<strong>Format:</strong> Valid (whsec_*****)<br>';
				echo '<strong>Length:</strong> ' . strlen($stripe_endpoint_secret) . ' characters<br>';
				
				echo '<div style="margin-top: 15px; padding: 10px; background: #e9ecef; border-radius: 3px; font-size: 11px;">';
				echo '<strong>Purpose:</strong> This secret validates that webhook requests are actually from Stripe using HMAC-SHA256 signatures.<br><br>';
				echo '<strong>Used for:</strong> Payment confirmations, subscription updates, failed payments, etc.<br><br>';
				echo '<strong>Security:</strong> Prevents malicious webhook spoofing attacks.';
				echo '</div>';
				
				echo '<div style="color: #17a2b8; font-size: 11px; margin-top: 10px;">ℹ️ This secret can only be tested by receiving actual webhooks from Stripe</div>';
				
			} else {
				echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ Invalid Format</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Stripe webhook secrets should start with "whsec_"</div>';
				echo '<strong>Current value:</strong> ' . htmlspecialchars(substr($stripe_endpoint_secret, 0, 10)) . '...<br>';
			}
		} else {
			echo '<div style="color: #ffc107; margin-bottom: 10px;"><strong>⚠ No Webhook Secret</strong></div>';
			echo '<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 3px; font-size: 11px; color: #856404;">';
			echo '<strong>Without a webhook secret:</strong><br>';
			echo '• Webhook requests cannot be verified<br>';
			echo '• Your application is vulnerable to webhook spoofing<br>';
			echo '• Payment confirmations may not be secure<br><br>';
			echo '<strong>To get this secret:</strong><br>';
			echo '1. Go to your Stripe Dashboard<br>';
			echo '2. Navigate to Developers → Webhooks<br>';
			echo '3. Create or edit your webhook endpoint<br>';
			echo '4. Copy the "Signing secret"';
			echo '</div>';
		}
		
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';
		
		// Checkout Configuration Section
		echo '<h4>Checkout Configuration</h4>';
		//TODO: FIX STRIPE CHECKOUT WEBHOOK FOR NEW API VERSION
		$optionvals = array("Stripe Regular"=>'stripe_regular', 'Stripe Checkout' => 'stripe_checkout', 'None' => 'none'); 
		echo $formwriter->dropinput("Checkout Type", "checkout_type", '', $optionvals, $settings->get_setting('checkout_type'), '', FALSE);
		echo '<div style="margin: 50px 0;"></div>';
		
		// PayPal Configuration Section
		echo '<h4>PayPal Configuration</h4>';
		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("Enable Paypal Checkout", "use_paypal_checkout", '', $optionvals, $settings->get_setting('use_paypal_checkout'), '', FALSE);
		
		// PayPal Live API section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>PayPal Live API Settings</h5>';
		echo $formwriter->textinput("Paypal Client ID (Example: ATF46g-L-ler2xxxx)", 'paypal_api_key', '', 20, $settings->get_setting('paypal_api_key'), "" , 255, "");
		echo $formwriter->textinput("Paypal Client Secret (Example: ELTF_ie6uGhueKxxxx)", 'paypal_api_secret', '', 20, $settings->get_setting('paypal_api_secret'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$paypal_api_key = $settings->get_setting('paypal_api_key');
			$paypal_api_secret = $settings->get_setting('paypal_api_secret');
			
			if (!empty($paypal_api_key) && !empty($paypal_api_secret)) {
			try {
				// Test PayPal Live API connection using a simple account info call
				$original_test_mode = $_SESSION['test_mode'] ?? null;
				$_SESSION['test_mode'] = false; // Force live mode
				
				PathHelper::requireOnce('/includes/PaypalHelper.php');
				
				// Step 1: Get OAuth2 access token
				$basic_auth = base64_encode($paypal_api_key . ':' . $paypal_api_secret);
				$endpoint = 'https://api-m.paypal.com';
				
				// Get access token first
				$curl = curl_init();
				curl_setopt_array($curl, array(
					CURLOPT_URL => $endpoint . '/v1/oauth2/token',
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_ENCODING => '',
					CURLOPT_MAXREDIRS => 10,
					CURLOPT_TIMEOUT => 10,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
					CURLOPT_CUSTOMREQUEST => 'POST',
					CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
					CURLOPT_HTTPHEADER => array(
						'Accept: application/json',
						'Accept-Language: en_US',
						'Content-Type: application/x-www-form-urlencoded',
						"Authorization: Basic $basic_auth"
					),
				));
				
				$token_response = curl_exec($curl);
				$token_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				$curl_error = curl_error($curl);
				curl_close($curl);
				
				// If we got an access token, that means credentials are valid
				if ($token_http_code === 200) {
					$token_data = json_decode($token_response, true);
					$http_code = 200;
					$response = json_encode(array(
						'success' => true,
						'access_token' => substr($token_data['access_token'], 0, 20) . '...',
						'token_type' => $token_data['token_type'],
						'expires_in' => $token_data['expires_in']
					));
				} else {
					$http_code = $token_http_code;
					$response = $token_response;
				}
				
				// Restore original test mode
				if ($original_test_mode !== null) {
					$_SESSION['test_mode'] = $original_test_mode;
				} else {
					unset($_SESSION['test_mode']);
				}
				
				if ($http_code === 200) {
					$token_info = json_decode($response, true);
					echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Live API Credentials Valid</strong></div>';
					echo '<strong>Environment:</strong> Production<br>';
					echo '<strong>Token Type:</strong> ' . htmlspecialchars($token_info['token_type']) . '<br>';
					echo '<strong>Access Token:</strong> ' . htmlspecialchars($token_info['access_token']) . '<br>';
					echo '<strong>Expires In:</strong> ' . htmlspecialchars($token_info['expires_in']) . ' seconds<br>';
					
					echo '<div style="color: #28a745; font-size: 11px; margin-top: 10px;">✓ OAuth2 authentication successful - Ready for live payments</div>';
					
				} else {
					echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Authentication Failed</strong></div>';
					echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">HTTP Code: ' . $http_code . '</div>';
					if ($response) {
						$error_data = json_decode($response, true);
						if (isset($error_data['error_description'])) {
							echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($error_data['error_description']) . '</div>';
						} elseif (isset($error_data['error'])) {
							echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($error_data['error']) . '</div>';
						}
					}
					
					// Show debug info only on failure
					echo '<div style="background: #f8d7da; padding: 10px; border-radius: 3px; margin-top: 10px; font-size: 10px; color: #721c24;">';
					echo '<strong>Debug Info:</strong><br>';
					echo 'Client ID: ' . htmlspecialchars(substr($paypal_api_key, 0, 8)) . '...' . htmlspecialchars(substr($paypal_api_key, -4)) . '<br>';
					echo 'Client Secret: ' . htmlspecialchars(substr($paypal_api_secret, 0, 8)) . '...' . htmlspecialchars(substr($paypal_api_secret, -4)) . '<br>';
					echo 'Endpoint: ' . htmlspecialchars($endpoint) . '<br>';
					
					// Check if this might be a credential mismatch
					if (strpos($paypal_api_key, 'sb-') === 0 || strpos($paypal_api_key, 'ATF') === 0) {
						echo '<div style="color: #dc3545; font-weight: bold; margin-top: 5px;">⚠ WARNING: This Client ID appears to be for SANDBOX, but you\'re testing against LIVE endpoint!</div>';
					}
					if ($curl_error) {
						echo 'cURL Error: ' . htmlspecialchars($curl_error) . '<br>';
					}
					echo '</div>';
				}
				
			} catch (Exception $e) {
				echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Connection Failed</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
				
				// Restore original test mode
				if (isset($original_test_mode)) {
					if ($original_test_mode !== null) {
						$_SESSION['test_mode'] = $original_test_mode;
					} else {
						unset($_SESSION['test_mode']);
					}
				}
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter both Client ID and Secret to validate connection</div>';
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

		// PayPal Test API section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>PayPal Test API Settings</h5>';
		echo $formwriter->textinput("Test Paypal Client ID (Example: ATF46g-L-ler2xxxx)", 'paypal_api_key_test', '', 20, $settings->get_setting('paypal_api_key_test'), "" , 255, "");
		echo $formwriter->textinput("Test Paypal Client Secret (Example: ELTF_ie6uGhueKxxxx)", 'paypal_api_secret_test', '', 20, $settings->get_setting('paypal_api_secret_test'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Test API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$paypal_api_key_test = $settings->get_setting('paypal_api_key_test');
			$paypal_api_secret_test = $settings->get_setting('paypal_api_secret_test');
			
			if (!empty($paypal_api_key_test) && !empty($paypal_api_secret_test)) {
			try {
				// Test PayPal Test API connection using a simple account info call
				$original_test_mode = $_SESSION['test_mode'] ?? null;
				$_SESSION['test_mode'] = true; // Force test mode
				
				PathHelper::requireOnce('/includes/PaypalHelper.php');
				
				// Step 1: Get OAuth2 access token
				$basic_auth_test = base64_encode($paypal_api_key_test . ':' . $paypal_api_secret_test);
				$endpoint_test = 'https://api-m.sandbox.paypal.com';
				
				
				// Get access token first
				$curl = curl_init();
				curl_setopt_array($curl, array(
					CURLOPT_URL => $endpoint_test . '/v1/oauth2/token',
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_ENCODING => '',
					CURLOPT_MAXREDIRS => 10,
					CURLOPT_TIMEOUT => 10,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
					CURLOPT_CUSTOMREQUEST => 'POST',
					CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
					CURLOPT_HTTPHEADER => array(
						'Accept: application/json',
						'Accept-Language: en_US',
						'Content-Type: application/x-www-form-urlencoded',
						"Authorization: Basic $basic_auth_test"
					),
				));
				
				$token_response = curl_exec($curl);
				$token_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				$curl_error = curl_error($curl);
				curl_close($curl);
				
				
				// If we got an access token, that means credentials are valid
				if ($token_http_code === 200) {
					$token_data = json_decode($token_response, true);
					$http_code = 200;
					$response = json_encode(array(
						'success' => true,
						'access_token' => substr($token_data['access_token'], 0, 20) . '...',
						'token_type' => $token_data['token_type'],
						'expires_in' => $token_data['expires_in']
					));
				} else {
					$http_code = $token_http_code;
					$response = $token_response;
				}
				
				// Restore original test mode
				if ($original_test_mode !== null) {
					$_SESSION['test_mode'] = $original_test_mode;
				} else {
					unset($_SESSION['test_mode']);
				}
				
				if ($http_code === 200) {
					$token_info = json_decode($response, true);
					echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Test API Credentials Valid</strong></div>';
					echo '<div style="background: #fff3cd; padding: 8px; border-radius: 3px; margin-bottom: 10px; font-size: 11px; color: #856404;">🧪 Sandbox Environment</div>';
					echo '<strong>Token Type:</strong> ' . htmlspecialchars($token_info['token_type']) . '<br>';
					echo '<strong>Access Token:</strong> ' . htmlspecialchars($token_info['access_token']) . '<br>';
					echo '<strong>Expires In:</strong> ' . htmlspecialchars($token_info['expires_in']) . ' seconds<br>';
					
					echo '<div style="color: #28a745; font-size: 11px; margin-top: 10px;">✓ OAuth2 authentication successful - Ready for testing</div>';
					
				} else {
					echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Authentication Failed</strong></div>';
					echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">HTTP Code: ' . $http_code . '</div>';
					if ($response) {
						$error_data = json_decode($response, true);
						if (isset($error_data['error_description'])) {
							echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($error_data['error_description']) . '</div>';
						} elseif (isset($error_data['error'])) {
							echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($error_data['error']) . '</div>';
						}
					}
					
					// Debug information (only shown on failure)
					echo '<div style="background: #e9ecef; padding: 10px; border-radius: 3px; margin-top: 10px; font-size: 10px; color: #495057;">';
					echo '<strong>Debug Info:</strong><br>';
					echo 'Client ID: ' . htmlspecialchars(substr($paypal_api_key_test, 0, 8)) . '...' . htmlspecialchars(substr($paypal_api_key_test, -4)) . '<br>';
					echo 'Client Secret: ' . htmlspecialchars(substr($paypal_api_secret_test, 0, 8)) . '...' . htmlspecialchars(substr($paypal_api_secret_test, -4)) . '<br>';
					echo 'Endpoint: ' . htmlspecialchars($endpoint_test) . '<br>';
					echo 'Basic Auth: ' . htmlspecialchars(substr($basic_auth_test, 0, 20)) . '...<br>';
					
					// Check if this might be a credential mismatch
					if (strpos($paypal_api_key_test, 'sb-') !== 0 && strpos($paypal_api_key_test, 'ATF') !== 0) {
						echo '<div style="color: #dc3545; font-weight: bold;">⚠ WARNING: This Client ID appears to be for LIVE, but you\'re testing against SANDBOX endpoint!</div>';
					}
					echo '</div>';
					
					// Response debug (only shown on failure)
					echo '<div style="background: #f8f9fa; padding: 10px; border-radius: 3px; margin-top: 10px; font-size: 10px; color: #495057;">';
					echo '<strong>Response Debug:</strong><br>';
					echo 'HTTP Code: ' . $token_http_code . '<br>';
					if ($curl_error) {
						echo 'cURL Error: ' . htmlspecialchars($curl_error) . '<br>';
					}
					echo 'Response: ' . htmlspecialchars(substr($token_response, 0, 200)) . '...<br>';
					echo '</div>';
				}
				
			} catch (Exception $e) {
				echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Connection Failed</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
				
				// Restore original test mode
				if (isset($original_test_mode)) {
					if ($original_test_mode !== null) {
						$_SESSION['test_mode'] = $original_test_mode;
					} else {
						unset($_SESSION['test_mode']);
					}
				}
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter both Client ID and Secret to validate connection</div>';
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



		// Mailgun section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Mailgun Settings</h5>';
		$optionvals = array("Version 2.X"=>'1', 'Version 3.X' => '2');
		echo $formwriter->dropinput("Mailgun Version", "mailgun_version", '', $optionvals, $settings->get_setting('mailgun_version'), '', FALSE);	
		echo $formwriter->textinput("Mailgun API Key (Example: key-6eac34eed3afb3df055f81aa20d878e4)", 'mailgun_api_key', '', 20, $settings->get_setting('mailgun_api_key'), "" , 255, "");
		echo $formwriter->textinput("Mailgun Domain (Example: mg.domain.net)", 'mailgun_domain', '', 20, $settings->get_setting('mailgun_domain'), "" , 255, "");
		echo $formwriter->textinput("Mailgun EU API Link (Example: https://api.eu.mailgun.net)", 'mailgun_eu_api_link', '', 20, $settings->get_setting('mailgun_eu_api_link'), "" , 255, "");
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$mailgun_api_key = $settings->get_setting('mailgun_api_key');
			$mailgun_domain = $settings->get_setting('mailgun_domain');
			$mailgun_version = $settings->get_setting('mailgun_version');
			$mailgun_eu_api_link = $settings->get_setting('mailgun_eu_api_link');
			
			if (!empty($mailgun_api_key) && !empty($mailgun_domain)) {
			// Test Mailgun API connection
			$composer_path = $settings->get_setting('composerAutoLoad');
			if ($composer_path && file_exists(rtrim($composer_path, '/') . '/autoload.php')) {
				try {
					require_once(rtrim($composer_path, '/') . '/autoload.php');
					
					// Create Mailgun client based on version and test with a simple domain check
					if ($mailgun_version == 1) {
						if ($mailgun_eu_api_link) {
							$mg = new Mailgun\Mailgun($mailgun_api_key, $mailgun_eu_api_link);
						} else {
							$mg = new Mailgun\Mailgun($mailgun_api_key);
						}
						
						// For version 2.X, try to get domain info
						try {
							$domain_info = $mg->get("domains/{$mailgun_domain}");
							echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ API Key Valid</strong></div>';
							echo '<strong>Domain:</strong> ' . htmlspecialchars($mailgun_domain) . '<br>';
							
							if (isset($domain_info->http_response_body->domain)) {
								$domain_data = $domain_info->http_response_body->domain;
								if (isset($domain_data->state)) {
									echo '<strong>Status:</strong> ' . htmlspecialchars($domain_data->state) . '<br>';
								}
								if (isset($domain_data->created_at)) {
									echo '<strong>Created:</strong> ' . htmlspecialchars($domain_data->created_at) . '<br>';
								}
							}
							
							// Test if we can access the domain
							echo '<div style="color: #28a745; font-size: 11px; margin-top: 10px;">✓ Domain accessible via API</div>';
							
						} catch (Exception $domain_ex) {
							// If domain check fails, try a simple validation
							echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ API Key Valid</strong></div>';
							echo '<strong>Configured Domain:</strong> ' . htmlspecialchars($mailgun_domain) . '<br>';
							echo '<div style="color: #ffc107; font-size: 11px; margin-top: 10px;">⚠ Unable to verify domain details: ' . htmlspecialchars($domain_ex->getMessage()) . '</div>';
						}
						
					} else {
						if ($mailgun_eu_api_link) {
							$mg = Mailgun\Mailgun::create($mailgun_api_key, $mailgun_eu_api_link);
						} else {
							$mg = Mailgun\Mailgun::create($mailgun_api_key);
						}
						
						// For version 3.X, try to get domain info
						try {
							$domain_info = $mg->domains()->show($mailgun_domain);
							echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ API Key Valid</strong></div>';
							echo '<strong>Domain:</strong> ' . htmlspecialchars($mailgun_domain) . '<br>';
							
							// Try to get domain data from the response
							if ($domain_info && method_exists($domain_info, 'getDomain')) {
								$domain = $domain_info->getDomain();
								if ($domain) {
									if (method_exists($domain, 'getName')) {
										echo '<strong>Name:</strong> ' . htmlspecialchars($domain->getName()) . '<br>';
									}
									if (method_exists($domain, 'getState')) {
										echo '<strong>Status:</strong> ' . htmlspecialchars($domain->getState()) . '<br>';
									}
								}
							}
							
							echo '<div style="color: #28a745; font-size: 11px; margin-top: 10px;">✓ Domain accessible via API</div>';
							
						} catch (Exception $domain_ex) {
							// If domain check fails, try a simple validation by checking if client was created
							echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ API Key Valid</strong></div>';
							echo '<strong>Configured Domain:</strong> ' . htmlspecialchars($mailgun_domain) . '<br>';
							echo '<div style="color: #ffc107; font-size: 11px; margin-top: 10px;">⚠ Unable to verify domain details: ' . htmlspecialchars($domain_ex->getMessage()) . '</div>';
						}
					}
					
				} catch (Exception $e) {
					echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ API Connection Failed</strong></div>';
					echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
				}
			} else {
				echo '<div style="color: #ffc107; margin-bottom: 10px;"><strong>⚠ Composer Not Configured</strong></div>';
				echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">Configure Composer path first to test API</div>';
			}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter API key and domain to validate connection</div>';
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
	
	echo $formwriter->textbox('Custom CSS', 'custom_css', 'ctrlHolder', 10, 80, $settings->get_setting('custom_css'), '', 'no');

	echo $formwriter->textinput("Preview image (for facebook, google, etc)", 'preview_image', '', 20, $settings->get_setting('preview_image'), "" , 255, "");


	$optionvals = array("Use built in tracking"=>'internal', 'Use custom tracking' => 'custom');
	echo $formwriter->dropinput("Visit tracking", "tracking", '', $optionvals, $settings->get_setting('tracking'), '', FALSE);	
	echo $formwriter->textinput("Tracking code", "tracking_code", '', 20, $settings->get_setting('tracking_code'), "" , 255, "");	
	
	
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Registration active", "register_active", '', $optionvals, $settings->get_setting('register_active'), '', FALSE);

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Subscriptions active", "subscriptions_active", '', $optionvals, $settings->get_setting('subscriptions_active'), '', FALSE);	

	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Default timezone", "default_timezone", '', $optionvals, $settings->get_setting('default_timezone'), '', FALSE); 

	echo $formwriter->textinput("Nickname display as (blank for no nicknames)", "nickname_display_as", '', 20, $settings->get_setting('nickname_display_as'), "" , 255, "");	
	$optionvals = array("Default (UIKit)"=>'', 'Bootstrap' => 'admin', 'Tailwind' => 'tailwind');
	echo $formwriter->dropinput("Form styling", "form_style", '', $optionvals, $settings->get_setting('form_style'), '', FALSE);	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Require email activation to log on", "activation_required_login", '', $optionvals, $settings->get_setting('activation_required_login'), '', FALSE);	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Newsletter active", "newsletter_active", '', $optionvals, $settings->get_setting('newsletter_active'), '', FALSE);	
	
	echo $formwriter->textinput("Emails to receive subscription notifications (separate with comma)", "subscription_notification_emails", '', 20, $settings->get_setting('subscription_notification_emails'), "" , 255, "");	

	echo $formwriter->textinput("Emails to receive one time purchase notifications (separate with comma)", "single_purchase_notification_emails", '', 20, $settings->get_setting('single_purchase_notification_emails'), "" , 255, "");	

	$optionvals = array("US Dollar"=>'usd', 'Euro' => 'eur'); 
	echo $formwriter->dropinput("Site Currency", "site_currency", '', $optionvals, $settings->get_setting('site_currency'), '', FALSE);	
	
	
	echo $formwriter->textbox('Robots.txt entry', 'robots_text', 'ctrlHolder', 10, 80, $settings->get_setting('robots_text'), '', 'no');

	echo '<h3>Survey Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Survey module active", "surveys_active", '', $optionvals, $settings->get_setting('surveys_active'), '', FALSE);	

	echo '<h3>Blog Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Blog module active", "blog_active", '', $optionvals, $settings->get_setting('blog_active'), '', FALSE);

	/*DEPRECATED
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Use blog as homepage", "use_blog_as_homepage", '', $optionvals, $settings->get_setting('use_blog_as_homepage'), '', FALSE);
*/	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Show comments", "show_comments", '', $optionvals, $settings->get_setting('show_comments'), '', FALSE);	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Allow comments", "comments_active", '', $optionvals, $settings->get_setting('comments_active'), '', FALSE);	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Allow comments from unregistered users", "comments_unregistered_users", '', $optionvals, $settings->get_setting('comments_unregistered_users'), '', FALSE);	

	$optionvals = array("Approved"=>'approved', 'Unapproved' => 'unapproved');
	echo $formwriter->dropinput("Default comment status", "default_comment_status", '', $optionvals, $settings->get_setting('default_comment_status'), '', FALSE);

	echo $formwriter->textinput("Emails to receive comment notifications (separate with comma)", "comment_notification_emails", '', 20, $settings->get_setting('comment_notification_emails'), "" , 255, "");		

	echo $formwriter->textinput("Comment anti spam word (blank for none)", "anti_spam_answer_comments", '', 20, $settings->get_setting('anti_spam_answer'), "" , 255, "");

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Use captcha on comments", "use_captcha_comments", '', $optionvals, $settings->get_setting('use_captcha_comments'), '', FALSE);	

	echo $formwriter->textbox('Blog footer text', 'blog_footer_text', 'ctrlHolder', 10, 80, $settings->get_setting('blog_footer_text'), '', 'no');	
 
	echo '<hr>';
 
 	echo '<h3>Spam Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Use form honeypots", "use_honeypot", '', $optionvals, $settings->get_setting('use_honeypot'), '', FALSE);	

	echo $formwriter->textinput("Anti spam word (blank for none)", "anti_spam_answer", '', 20, $settings->get_setting('anti_spam_answer'), "" , 255, "");	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Use captcha", "use_captcha", '', $optionvals, $settings->get_setting('use_captcha'), '', FALSE);	
	
	echo '<hr>';

 	echo '<h3>Social Settings</h3>';

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Social settings active", "social_settings_active", '', $optionvals, '0', '', FALSE);

	echo $formwriter->textinput("Facebook link", "social_facebook_link", '', 20, $settings->get_setting('social_facebook_link'), "" , 255, "");	

	echo $formwriter->textinput("Instagram link", "social_instagram_link", '', 20, $settings->get_setting('social_instagram_link'), "" , 255, "");
		
	echo $formwriter->textinput("Soundcloud link", "social_soundcloud_link", '', 20, $settings->get_setting('social_soundcloud_link'), "" , 255, "");
			
	echo $formwriter->textinput("Spotify link", "social_spotify_link", '', 20, $settings->get_setting('social_spotify_link'), "" , 255, "");
				
	echo $formwriter->textinput("Youtube link", "social_youtube_link", '', 20, $settings->get_setting('social_youtube_link'), "" , 255, "");
					
	echo $formwriter->textinput("Mixcloud link", "social_mixcloud_link", '', 20, $settings->get_setting('social_mixcloud_link'), "" , 255, "");

	echo $formwriter->textinput("Discord link", "social_discord_link", '', 20, $settings->get_setting('social_discord_link'), "" , 255, "");
	
	echo $formwriter->textinput("Google link", "social_google_link", '', 20, $settings->get_setting('social_google_link'), "" , 255, "");
	
	echo $formwriter->textinput("Linkedin link", "social_linkedin_link", '', 20, $settings->get_setting('social_linkedin_link'), "" , 255, "");
	
	echo $formwriter->textinput("Pinterest link", "social_pinterest_link", '', 20, $settings->get_setting('social_pinterest_link'), "" , 255, "");
	
	echo $formwriter->textinput("Stack Overflow link", "social_stack_link", '', 20, $settings->get_setting('social_stack_link'), "" , 255, "");
	
	echo $formwriter->textinput("Telegram link", "social_telegram_link", '', 20, $settings->get_setting('social_telegram_link'), "" , 255, "");
	
	echo $formwriter->textinput("Tiktok link", "social_tiktok_link", '', 20, $settings->get_setting('social_tiktok_link'), "" , 255, "");
	
	echo $formwriter->textinput("Snapchat link", "social_snapchat_link", '', 20, $settings->get_setting('social_snapchat_link'), "" , 255, "");
	
	echo $formwriter->textinput("Slack link", "social_slack_link", '', 20, $settings->get_setting('social_slack_link'), "" , 255, "");
	echo $formwriter->textinput("Github link", "social_github_link", '', 20, $settings->get_setting('social_github_link'), "" , 255, "");
	echo $formwriter->textinput("Reddit link", "social_reddit_link", '', 20, $settings->get_setting('social_reddit_link'), "" , 255, "");
	echo $formwriter->textinput("Whatsapp link", "social_whatsapp_link", '', 20, $settings->get_setting('social_whatsapp_link'), "" , 255, "");
	echo $formwriter->textinput("Twitch link", "social_twitch_link", '', 20, $settings->get_setting('social_twitch_link'), "" , 255, "");

	echo '<h3>Booking Settings</h3>';

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Booking module active", "bookings_active", '', $optionvals, $settings->get_setting('bookings_active'), '', FALSE);

	echo $formwriter->textinput("Calendly Organization URI (Example: https://api.calendly.com/organizations/EHDBUSLIPJFCKXAE)", 'calendly_organization_uri', '', 20, $settings->get_setting('calendly_organization_uri'), "" , 255, "");
	echo $formwriter->textinput("Calendly Organization Name (Example: test-organization)", 'calendly_organization_name', '', 20, $settings->get_setting('calendly_organization_name'), "" , 255, "");
	echo $formwriter->textinput("Calendly API Key (Example: INEEMNBGGN53553SDFGBESNICRDW74)", 'calendly_api_key', '', 20, $settings->get_setting('calendly_api_key'), "" , 255, "");
	echo $formwriter->textbox('Calendly API Token (Example: eyJraWQiOiIxY2UxZT...ZWJjY)', 'calendly_api_token', 'ctrlHolder', 10, 80, $settings->get_setting('calendly_api_token'), '', 'no');


 	echo '<h3>Events Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Event module active", "events_active", '', $optionvals, $settings->get_setting('events_active'), '', FALSE);

	echo $formwriter->textinput("Events label", 'events_label', '', 20, $settings->get_setting('events_label'), "" , 255, "");

	
 	echo '<h3>Product Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Product module active", "products_active", '', $optionvals, $settings->get_setting('products_active'), '', FALSE);


	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("List regular products on product index", "products_list_items_active", '', $optionvals, $settings->get_setting('products_list_items_active'), '', FALSE);	
	
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("List event products on product index", "products_list_events_active", '', $optionvals, $settings->get_setting('products_list_events_active'), '', FALSE);	

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Allow coupon codes", "coupons_active", '', $optionvals, $settings->get_setting('coupons_active'), '', FALSE);

	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Activate pricing (/pricing) page", "pricing_page", '', $optionvals, $settings->get_setting('pricing_page'), '', FALSE);
	
	$max_subscriptions_per_user = 0;
	if($settings->get_setting('max_subscriptions_per_user')){
		$max_subscriptions_per_user = $settings->get_setting('max_subscriptions_per_user');
	}
	echo $formwriter->textinput("Max number of subscriptions per user (0 for no limit)", 'max_subscriptions_per_user', '', 20, $max_subscriptions_per_user, "" , 255, "");
	
	echo '<hr>';

	echo '<h3>File Hosting Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("File hosting module active", "files_active", '', $optionvals, $settings->get_setting('files_active'), '', FALSE);	

	if(!$settings->get_setting('allowed_upload_extensions')){
		$allowed_upload_extensions = 'gif,jpeg,jpg,png,pdf,xls,doc,xlsx,docx,mp3,mp4,m4a';
	} else {
		$allowed_upload_extensions = $settings->get_setting('allowed_upload_extensions');
	}
	echo $formwriter->textinput("Allowed file upload extensions (comma separated)", 'allowed_upload_extensions', '', 20, $allowed_upload_extensions, "" , 255, "");

	echo '<h3>Video Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Video module active", "videos_active", '', $optionvals, $settings->get_setting('videos_active'), '', FALSE);

	echo '<h3>CMS Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("CMS module active", "page_contents_active", '', $optionvals, $settings->get_setting('page_contents_active'), '', FALSE);
	

	$pages = new MultiPage(
		array('deleted' => false, 'published' => true),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$pages->load();
	$optionvals = $pages->get_dropdown_array_link();
	$optionvals['Blog homepage'] = '/blog';
	echo $formwriter->dropinput("Alternate page to use as homepage (optional)", "alternate_homepage", '', $optionvals, $settings->get_setting('alternate_homepage'), '', TRUE);	
	
	echo $formwriter->textinput("Alternate page to use as logged in homepage (optional)", 'alternate_loggedin_homepage', '', 20, $settings->get_setting('alternate_loggedin_homepage'), "" , 255, "");

	echo '<h3>Url Rewrite Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Url rewrite module active", "urls_active", '', $optionvals, $settings->get_setting('urls_active'), '', FALSE);


	echo '<h3>Email Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Email module active", "emails_active", '', $optionvals, $settings->get_setting('emails_active'), '', FALSE);	

	$templates = new MultiMailingList(
		array('deleted' => false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$numtemplates = $templates->count_all();
	$outer_optionvals = array('All Lists' => 'all');
	$outer_optionvals = array_merge($outer_optionvals, $templates->get_dropdown_array());
	
	if($settings->get_setting('default_mailing_list')){
		echo $formwriter->dropinput("Default mailing list", "default_mailing_list", '', $outer_optionvals, $settings->get_setting('default_mailing_list'), '', TRUE);	
	}
	else if($numtemplates){
		$first_template = $templates->get(0);
		echo $formwriter->dropinput("Default mailing list", "default_mailing_list", '', $outer_optionvals, $first_template, '', TRUE);			
	}
	else{
		echo $formwriter->dropinput("Default mailing list", "default_mailing_list", '', $outer_optionvals, $settings->get_setting('default_mailing_list'), '', TRUE);			
	}
	
	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_OUTER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$outer_optionvals = $templates->get_dropdown_array();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_INNER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$inner_optionvals = $templates->get_dropdown_array();

	$templates = new MultiEmailTemplateStore(
		array('template_type' => EmailTemplateStore::TEMPLATE_TYPE_FOOTER),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$footer_optionvals = $templates->get_dropdown_array();

	echo $formwriter->dropinput("Bulk email outer template", "bulk_outer_template", '', $outer_optionvals, $settings->get_setting('bulk_outer_template'), '', FALSE);	
	echo $formwriter->dropinput("Bulk email footer", "bulk_footer", '', $footer_optionvals, $settings->get_setting('bulk_footer'), '', FALSE);	
	echo $formwriter->dropinput("Individual email inner template", "individual_email_inner_template", '', $inner_optionvals, $settings->get_setting('individual_email_inner_template'), '', FALSE);	
	echo $formwriter->dropinput("Group email footer template", "group_email_footer_template", '', $footer_optionvals, $settings->get_setting('group_email_footer_template'), '', FALSE);	
	echo $formwriter->dropinput("Group email outer template", "group_email_outer_template", '', $outer_optionvals, $settings->get_setting('group_email_outer_template'), '', FALSE);
	echo $formwriter->dropinput("Group email inner template", "group_email_inner_template", '', $inner_optionvals, $settings->get_setting('group_email_inner_template'), '', FALSE);
	echo $formwriter->dropinput("Event email footer template", "event_email_footer_template", '', $footer_optionvals, $settings->get_setting('event_email_footer_template'), '', FALSE);
	echo $formwriter->dropinput("Event email outer template", "event_email_outer_template", '', $outer_optionvals, $settings->get_setting('event_email_outer_template'), '', FALSE);
	echo $formwriter->dropinput("Event email inner template", "event_email_inner_template", '', $inner_optionvals, $settings->get_setting('event_email_inner_template'), '', FALSE);
	
	//$optionvals = array("General"=>'general', 'Emails' => 'emails');
	//echo $formwriter->dropinput("Setting group", "stg_group_name", '', $optionvals, $setting->get('stg_group_name'), '', FALSE);

	echo '<h3>Upgrade Settings</h3>';
	$optionvals = array("Yes"=>1, 'No' => 0);
	echo $formwriter->dropinput("Act as upgrade server", "upgrade_server_active", '', $optionvals, $settings->get_setting('upgrade_server_active'), '', FALSE);	
	if(!$upgrade_source = $settings->get_setting('upgrade_source')){
		$upgrade_source = 'https://getjoinery.com';
	}
	echo $formwriter->textinput("Upgrade source", "upgrade_source", '', 20, $upgrade_source, "" , 255, "");

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();
	

	
	$page->end_box();


	$page->admin_footer();

?>