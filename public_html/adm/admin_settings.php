<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/settings_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/email_templates_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/pages_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

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
		$script_dir = $_SERVER['DOCUMENT_ROOT'].'/plugins/'.$plugin.'/admin/';
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
		
	
		$(document).ready(function() {
			set_choices();
			set_booking_choices();
			set_blog_choices();
			check_social_content(); // Check content before setting visibility
			set_social_choices();
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
		});
	
		
		</script>
		<?php
	
	$validation_rules = array();
	$validation_rules['stg_value']['required']['value'] = 'true';
	$validation_rules['stg_name']['required']['value'] = 'true';	
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_settings');
	
	if($_SESSION['permission'] == 10){
		
		echo '<b>NOTE: These settings will not override the settings if they are located in the Globalvars_site.php file in the /config directory</b><br>';
		if($_SESSION['test_mode'] || $settings->get_setting('debug')){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}		
		
		echo '<h3>System Settings</h3>';

		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("Force HTTPS", "force_https", '', $optionvals, $settings->get_setting('force_https'), '', FALSE);	

		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("Payment Debug Mode ", "debug", '', $optionvals, $settings->get_setting('debug'), '', FALSE);

		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("CSS Debug Mode ", "debug_css", '', $optionvals, $settings->get_setting('debug_css'), '', FALSE);
		
		$optionvals = array("Yes (show to screen)"=>1, 'No (logged)' => 0);
		echo $formwriter->dropinput("Show errors", "show_errors", '', $optionvals, $settings->get_setting('show_errors'), '', FALSE);		
		
		$theme_dir = $_SERVER['DOCUMENT_ROOT'].'/theme/';
		$directories = LibraryFunctions::list_directories_in_directory($theme_dir, 'filename');
		$optionvals = array();
		foreach($directories as $directory){
			$optionvals[$directory] = $directory;
		}
		
		
		//echo $formwriter->textinput("Alternate theme (optional theme other than default)", 'theme_template', '', 20, $settings->get_setting('theme_template'), "" , 255, "");
		echo $formwriter->dropinput("Active theme", "theme_template", '', $optionvals, $settings->get_setting('theme_template'), '', FALSE);
		
		echo $formwriter->textinput("Base Path", 'baseDir', '', 20, $settings->get_setting('baseDir'), "" , 255, "");
		echo $formwriter->textinput("Site folder (The site we are running, basically the folder at /var/www/html/x)", 'site_template', '', 20, $settings->get_setting('site_template'), "" , 255, "");
		
		echo $formwriter->textinput("Web URL (Example: https://getjoinery.com)", 'webDir', '', 20, $settings->get_setting('webDir'), "" , 255, "");
		
		echo '<div style="border: 3px solid black; padding: 10px; margin: 10px;">NOTE: The following values are loaded from Globalvars_site.php</b>';
		
		echo '<p><b>Site path: </b> '.$settings->get_setting('siteDir').'</p>';
		echo '<p><b>Static files path: </b> '.$settings->get_setting('static_files_dir').'</p>';
		echo '<p><b>Upload path: </b> '.$settings->get_setting('upload_dir').'</p>';
		echo '<p><b>Upload web directory: </b> '.$settings->get_setting('upload_web_dir').'</p>';
		
		/*echo $formwriter->textinput("Site Path (Default: ".$settings->get_setting('siteDir').")", 'siteDir', '', 20, $settings->get_setting('siteDir', false), "" , 255, "");
		echo $formwriter->textinput("Static Files Path (Default: ".$settings->get_setting('static_files_dir').")", 'static_files_dir', '', 20, $settings->get_setting('static_files_dir', false), "" , 255, "");
		echo $formwriter->textinput("Upload Path (Default: ".$settings->get_setting('upload_dir').")", 'upload_dir', '', 20, $settings->get_setting('upload_dir', false), "" , 255, "");
		echo $formwriter->textinput("Upload Web URL (Default: ".$settings->get_setting('upload_web_dir').")", 'upload_web_dir', '', 20, $settings->get_setting('upload_web_dir', false), "" , 255, "");
		*/
		echo '</div>';
		
		
		
		
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
		echo '<div style="height: 280px; padding: 15px; background-color: #f5f5f5; border-radius: 5px; overflow-y: auto;">';
		
		$composer_path = $settings->get_setting('composerAutoLoad');
		if ($composer_path && !empty(trim($composer_path))) {
			$autoload_path = rtrim($composer_path, '/') . '/autoload.php';
			$composer_lock = rtrim($composer_path, '/') . '/../composer.lock';
			$composer_json = rtrim($composer_path, '/') . '/../composer.json';
			
			if (file_exists($autoload_path)) {
				echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>✓ Valid Composer installation</strong></div>';
				
				// Get installed packages from composer.lock
				$packages = [];
				if (file_exists($composer_lock)) {
					$lock_content = @file_get_contents($composer_lock);
					if ($lock_content) {
						$lock_data = json_decode($lock_content, true);
						if (isset($lock_data['packages'])) {
							foreach ($lock_data['packages'] as $package) {
								$packages[] = [
									'name' => $package['name'],
									'version' => $package['version'] ?? 'unknown'
								];
							}
						}
					}
				}
				
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
					echo '<div>';
					foreach ($packages as $package) { // Show all packages
						$version = $package['version'];
						// Don't add 'v' if version already starts with 'v'
						$version_display = (strpos($version, 'v') === 0) ? $version : 'v' . $version;
						echo '<div style="font-size: 11px; color: #333; margin-bottom: 2px; padding: 2px 5px; background: white; border-radius: 3px;">';
						echo '<code style="color: #007bff;">' . htmlspecialchars($package['name']) . '</code> ';
						echo '<span style="color: #666;">' . htmlspecialchars($version_display) . '</span>';
						echo '</div>';
					}
					echo '</div>';
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
		
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<hr style="margin: 20px 0;">';
		
		echo $formwriter->textinput("Apache Error Log Path (Example: /var/www/html/test/public_html/logs/error.log)", 'apache_error_log', '', 20, $settings->get_setting('apache_error_log'), "" , 255, "");
		
		echo $formwriter->textinput("Standard Error Message", 'standard_error', '', 20, $settings->get_setting('standard_error'), "" , 255, "");
		
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
		echo '<hr style="margin: 20px 0;">';
		
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
		echo '<hr style="margin: 20px 0;">';

		echo $formwriter->textinput("Mailchimp API Key", 'mailchimp_api_key', '', 20, $settings->get_setting('mailchimp_api_key'), "" , 255, "");

		echo $formwriter->textinput("Acuity API Key (Example: 7d97bfea536935sgd8b14d266b105ab1)", 'acuity_api_key', '', 20, $settings->get_setting('acuity_api_key'), "" , 255, "");
		echo $formwriter->textinput("Acuity User ID (Example: 18623423)", 'acuity_user_id', '', 20, $settings->get_setting('acuity_user_id'), "" , 255, "");

		echo $formwriter->textinput("Stripe API Key (Example: sk_live_xxxx)", 'stripe_api_key', '', 20, $settings->get_setting('stripe_api_key'), "" , 255, "");
		echo $formwriter->textinput("Stripe API Private Key (Example: pk_live_xxxx)", 'stripe_api_pkey', '', 20, $settings->get_setting('stripe_api_pkey'), "" , 255, "");
		echo $formwriter->textinput("Test Stripe API Key (Example: sk_test_xxxx)", 'stripe_api_key_test', '', 20, $settings->get_setting('stripe_api_key_test'), "" , 255, "");
		echo $formwriter->textinput("Test Stripe Private Key (Example: pk_test_xxxx)", 'stripe_api_pkey_test', '', 20, $settings->get_setting('stripe_api_pkey_test'), "" , 255, "");
		echo $formwriter->textinput("Stripe Endpoint Secret (Example: whsec_xxxx)", 'stripe_endpoint_secret', '', 20, $settings->get_setting('stripe_endpoint_secret'), "" , 255, "");
		
		//TODO: FIX STRIPE CHECKOUT WEBHOOK FOR NEW API VERSION
		$optionvals = array("Stripe Regular"=>'stripe_regular', 'Stripe Checkout' => 'stripe_checkout', 'None' => 'none'); 
		echo $formwriter->dropinput("Checkout Type", "checkout_type", '', $optionvals, $settings->get_setting('checkout_type'), '', FALSE);

		$optionvals = array("Yes"=>1, 'No' => 0);
		echo $formwriter->dropinput("Enable Paypal Checkout", "use_paypal_checkout", '', $optionvals, $settings->get_setting('use_paypal_checkout'), '', FALSE);
		echo $formwriter->textinput("Paypal Client ID (Example: ATF46g-L-ler2xxxx)", 'paypal_api_key', '', 20, $settings->get_setting('paypal_api_key'), "" , 255, "");
		echo $formwriter->textinput("Paypal Client Secret (Example: ELTF_ie6uGhueKxxxx)", 'paypal_api_secret', '', 20, $settings->get_setting('paypal_api_secret'), "" , 255, "");
		echo $formwriter->textinput("Test Paypal Client ID (Example: ATF46g-L-ler2xxxx)", 'paypal_api_key_test', '', 20, $settings->get_setting('paypal_api_key_test'), "" , 255, "");
		echo $formwriter->textinput("Test Paypal Client Secret (Example: ELTF_ie6uGhueKxxxx)", 'paypal_api_secret_test', '', 20, $settings->get_setting('paypal_api_secret_test'), "" , 255, "");



		$optionvals = array("Version 2.X"=>'1', 'Version 3.X' => '2');
		echo $formwriter->dropinput("Mailgun Version", "mailgun_version", '', $optionvals, $settings->get_setting('mailgun_version'), '', FALSE);	
		echo $formwriter->textinput("Mailgun API Key (Example: key-6eac34eed3afb3df055f81aa20d878e4)", 'mailgun_api_key', '', 20, $settings->get_setting('mailgun_api_key'), "" , 255, "");
		echo $formwriter->textinput("Mailgun Domain (Example: mg.domain.net)", 'mailgun_domain', '', 20, $settings->get_setting('mailgun_domain'), "" , 255, "");
		echo $formwriter->textinput("Mailgun EU API Link (Example: https://api.eu.mailgun.net)", 'mailgun_eu_api_link', '', 20, $settings->get_setting('mailgun_eu_api_link'), "" , 255, "");

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
	echo $formwriter->textinput("Form styling (blank for default)", "form_style", '', 20, $settings->get_setting('form_style'), "" , 255, "");	

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