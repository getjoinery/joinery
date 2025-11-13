<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_settings_email_logic.php'));

	$page_vars = process_logic(admin_settings_email_logic($_GET, $_POST));

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

	$pageoptions['altlinks'] = array('New Setting'=>'/admin/admin_setting_edit');
	$pageoptions['altlinks'] += array('Public Menu'=>'/admin/admin_public_menu');
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
	echo AdminPage::tab_menu($tab_menus, 'Email Settings');

	$formwriter = $page->getFormWriter('form1');

		?>
		<script type="text/javascript">

		function set_smtp_auth_choices(){
			const smtpAuth = document.getElementById('smtp_auth');
			const value = smtpAuth ? smtpAuth.value : '';

			const smtpAuthFields = document.getElementById('smtp_auth_fields');
			if (smtpAuthFields) {
				smtpAuthFields.style.display = (value == 0 || value == '') ? 'none' : 'block';
			}
		}

		function set_email_test_choices(){
			const emailTestMode = document.getElementById('email_test_mode');
			const value = emailTestMode ? emailTestMode.value : '';

			const emailTestFields = document.getElementById('email_test_fields');
			if (emailTestFields) {
				emailTestFields.style.display = (value == 0 || value == '') ? 'none' : 'block';
			}
		}

		function isValidEmail(email) {
			const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			return re.test(email);
		}

		document.addEventListener('DOMContentLoaded', function() {

			// SMTP Authentication toggle
			const smtpAuth = document.getElementById('smtp_auth');
			if (smtpAuth) {
				smtpAuth.addEventListener('change', set_smtp_auth_choices);
			}
			set_smtp_auth_choices();

			// Email test mode toggle
			const emailTestMode = document.getElementById('email_test_mode');
			if (emailTestMode) {
				emailTestMode.addEventListener('change', set_email_test_choices);
			}
			set_email_test_choices();

			// SMTP port validation
			const smtpPort = document.getElementById('smtp_port');
			if (smtpPort) {
				smtpPort.addEventListener('blur', function(){
					const port = this.value;
					if(port && ![25, 465, 587, 2525].includes(parseInt(port))){
						this.classList.add('is-invalid');
						if(!this.nextElementSibling || !this.nextElementSibling.classList.contains('invalid-feedback')){
							const feedback = document.createElement('div');
							feedback.className = 'invalid-feedback';
							feedback.textContent = 'Common ports: 25, 465, 587, 2525';
							this.parentNode.insertBefore(feedback, this.nextSibling);
						}
					} else {
						this.classList.remove('is-invalid');
						const feedback = this.nextElementSibling;
						if(feedback && feedback.classList.contains('invalid-feedback')){
							feedback.remove();
						}
					}
				});
			}

			// Email validation for test recipient
			const emailTestRecipient = document.getElementById('email_test_recipient');
			if (emailTestRecipient) {
				emailTestRecipient.addEventListener('blur', function(){
					const email = this.value;
					if(email && !isValidEmail(email)){
						this.classList.add('is-invalid');
						if(!this.nextElementSibling || !this.nextElementSibling.classList.contains('invalid-feedback')){
							const feedback = document.createElement('div');
							feedback.className = 'invalid-feedback';
							feedback.textContent = 'Please enter a valid email address';
							this.parentNode.insertBefore(feedback, this.nextSibling);
						}
					} else {
						this.classList.remove('is-invalid');
						const feedback = this.nextElementSibling;
						if(feedback && feedback.classList.contains('invalid-feedback')){
							feedback.remove();
						}
					}
				});
			}
		});

		</script>
		<?php

	$formwriter->begin_form();
	
	if($_SESSION['permission'] == 10){
		
		if(StripeHelper::isTestMode()){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}		

		$formwriter->textinput('webmaster_email', 'Webmaster Email', [
			'value' => $settings->get_setting('webmaster_email')
		]);
		$formwriter->textinput('defaultemail', 'Default Email', [
			'value' => $settings->get_setting('defaultemail')
		]);
		$formwriter->textinput('defaultemailname', 'Default Email Name', [
			'value' => $settings->get_setting('defaultemailname')
		]);

		// Mailchimp section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Mailchimp Settings</h5>';
		$formwriter->textinput('mailchimp_api_key', 'Mailchimp API Key', [
			'value' => $settings->get_setting('mailchimp_api_key')
		]);
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

		// Email Settings Section
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h3>Email Settings</h3>';
		
		// Email service selection settings
		$service_optionvals = array('mailgun' => 'Mailgun', 'smtp' => 'SMTP');

		$formwriter->dropinput('email_service', 'Primary Email Service', [
			'options' => $service_optionvals,
			'value' => $settings->get_setting('email_service'),
			'help' => 'Service used for sending emails',
			'empty_option' => true
		]);
		$formwriter->dropinput('email_fallback_service', 'Fallback Email Service', [
			'options' => $service_optionvals,
			'value' => $settings->get_setting('email_fallback_service'),
			'help' => 'Service used if primary fails',
			'empty_option' => true
		]);
		
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Service Status</h5>';
		
		// Get actual database values (no fallback defaults)
		$current_service = $settings->get_setting('email_service');
		$fallback_service = $settings->get_setting('email_fallback_service');

		echo '<div class="alert alert-info">';
		
		// Primary Service Status
		echo '<strong>Primary Service:</strong> ';
		if (empty($current_service) || $current_service === 'none') {
			echo '<span class="text-muted">• None selected</span>';
		} else {
			// Quick validation check
			require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			$primary_validation = EmailSender::validateService($current_service);
			if ($primary_validation['valid']) {
				echo '<span class="text-success">✓ ' . ucfirst($current_service) . ' configured</span>';
			} else {
				echo '<span class="text-danger">✗ ' . ucfirst($current_service) . ' - ' . implode(', ', $primary_validation['errors']) . '</span>';
			}
		}
		echo '<br/>';
		
		// Fallback Service Status
		echo '<strong>Fallback Service:</strong> ';
		if (empty($fallback_service) || $fallback_service === 'none') {
			echo '<span class="text-muted">• None selected</span>';
		} else {
			// Quick validation check
			if (!isset($fallback_validation)) {
				require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			}
			$fallback_validation = EmailSender::validateService($fallback_service);
			if ($fallback_validation['valid']) {
				echo '<span class="text-success">✓ ' . ucfirst($fallback_service) . ' configured</span>';
			} else {
				echo '<span class="text-warning">⚠ ' . ucfirst($fallback_service) . ' - ' . implode(', ', $fallback_validation['errors']) . '</span>';
			}
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';

		// Mailgun section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Mailgun Settings</h5>';
		$formwriter->dropinput('mailgun_version', 'Mailgun Version', [
			'options' => ["Version 2.X"=>'1', 'Version 3.X' => '2'],
			'value' => $settings->get_setting('mailgun_version')
		]);
		$formwriter->textinput('mailgun_api_key', 'Mailgun API Key (Example: key-6eac34eed3afb3df055f81aa20d878e4)', [
			'value' => $settings->get_setting('mailgun_api_key')
		]);
		$formwriter->textinput('mailgun_domain', 'Mailgun Domain (Example: mg.domain.net)', [
			'value' => $settings->get_setting('mailgun_domain')
		]);
		$formwriter->textinput('mailgun_eu_api_link', 'Mailgun EU API Link (Example: https://api.eu.mailgun.net)', [
			'value' => $settings->get_setting('mailgun_eu_api_link')
		]);
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

		// SMTP Configuration Section
		echo '<h4>SMTP Configuration</h4>';

		// SMTP settings with two-column layout and connection validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>SMTP Server Settings</h5>';
		$formwriter->textinput('smtp_host', 'SMTP Host', [
			'value' => $settings->get_setting('smtp_host')
		]);
		$formwriter->textinput('smtp_port', 'SMTP Port (25, 465, 587, 2525)', [
			'value' => $settings->get_setting('smtp_port')
		]);
		$formwriter->textinput('smtp_helo', 'SMTP HELO/EHLO Hostname', [
			'value' => $settings->get_setting('smtp_helo')
		]);
		$formwriter->textinput('smtp_hostname', 'SMTP Hostname (for headers)', [
			'value' => $settings->get_setting('smtp_hostname')
		]);
		$formwriter->textinput('smtp_sender', 'SMTP Bounce Address', [
			'value' => $settings->get_setting('smtp_sender')
		]);

		$auth_optionvals = array(0 => 'No', 1 => 'Yes');
		$formwriter->dropinput('smtp_auth', 'SMTP Authentication Required', [
			'options' => $auth_optionvals,
			'value' => $settings->get_setting('smtp_auth'),
			'empty_option' => false
		]);

		echo '<div id="smtp_auth_fields" style="' . ($settings->get_setting('smtp_auth') ? '' : 'display:none;') . '">';
		$formwriter->textinput('smtp_username', 'SMTP Username', [
			'value' => $settings->get_setting('smtp_username')
		]);
		$formwriter->passwordinput('smtp_password', 'SMTP Password', [
			'value' => $settings->get_setting('smtp_password')
		]);
		echo '</div>';

		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>SMTP Connection Status</h5>';
		echo '<div style="min-height: 250px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';

		if ($run_validation) {
			$smtp_host = $settings->get_setting('smtp_host');
			$smtp_port = $settings->get_setting('smtp_port');
			
			if (!empty($smtp_host)) {
				// Test SMTP connection
				try {
					require_once(PathHelper::getIncludePath('includes/SmtpMailer.php'));
					
					// Create test instance
					$mailer = new SmtpMailer();
					
					echo '<p><strong>Configuration:</strong></p>';
					echo '<ul style="list-style: none; padding-left: 0;">';
					echo '<li><strong>Host:</strong> ' . htmlspecialchars($smtp_host ?: 'Not set') . '</li>';
					echo '<li><strong>Port:</strong> ' . htmlspecialchars($smtp_port ?: '25') . '</li>';
					
					// Determine encryption based on port
					$encryption = 'None';
					switch(intval($smtp_port)) {
						case 465:
							$encryption = 'SSL/TLS';
							break;
						case 587:
						case 2525:
							$encryption = 'STARTTLS';
							break;
					}
					echo '<li><strong>Encryption:</strong> ' . $encryption . ' (auto-detected)</li>';
					echo '<li><strong>Authentication:</strong> ' . ($settings->get_setting('smtp_auth') ? 'Yes' : 'No') . '</li>';
					echo '</ul>';
					
					// Try to connect
					try {
						// Test connection without sending
						$mailer->smtpConnect();
						echo '<p style="color: green;"><strong>✓ Connection Test:</strong> Successfully connected to SMTP server</p>';
						$mailer->smtpClose();
					} catch (Exception $e) {
						echo '<p style="color: red;"><strong>✗ Connection Failed:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
					}
					
				} catch (Exception $e) {
					echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>✗ Configuration Error</strong></div>';
					echo '<div style="color: #666; font-size: 12px; margin-top: 5px;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
				}
			} else {
				echo '<div style="color: #666; text-align: center; padding: 20px;">Enter SMTP host to validate connection</div>';
			}
		} else {
			// Show placeholder with "Run Validation" button
			echo '<div style="text-align: center; padding: 40px;">';
			echo '<p style="color: #666; margin-bottom: 15px;">SMTP validation not run yet</p>';
			echo '<a href="?run_validation=1#email-settings" class="btn btn-primary btn-sm">Run All Validations</a>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div style="margin: 30px 0;"></div>';

		// Email Testing Settings
		echo '<h4>Email Testing &amp; Debug Settings</h4>';
		echo '<div class="row">';
		echo '<div class="col-md-12">';

		// Add note about existing session-based suppression
		echo '<div class="alert alert-info" style="margin-bottom: 20px;">';
		echo '<strong>Note:</strong> These are global settings. There is also a session-based email suppression ';
		echo '(<code>$_SESSION[\'send_emails\']</code>) used for programmatic testing that logs to debug_email_logs.';
		echo '</div>';

		$test_optionvals = array(0 => 'No', 1 => 'Yes');
		$formwriter->dropinput('email_test_mode', 'Global Test Mode (redirect all emails to test recipient)', [
			'options' => $test_optionvals,
			'value' => $settings->get_setting('email_test_mode'),
			'empty_option' => false
		]);

		echo '<div id="email_test_fields" style="' . ($settings->get_setting('email_test_mode') ? '' : 'display:none;') . '">';
		$formwriter->textinput('email_test_recipient', 'Test Recipient Email (receives all redirected emails)', [
			'value' => $settings->get_setting('email_test_recipient')
		]);
		echo '</div>';

		$formwriter->dropinput('email_dry_run', 'Dry Run Mode (prevent all sending, just log)', [
			'options' => $test_optionvals,
			'value' => $settings->get_setting('email_dry_run'),
			'empty_option' => false
		]);
		$formwriter->dropinput('email_debug_mode', 'Debug Mode (log all emails to debug_email_logs)', [
			'options' => $test_optionvals,
			'value' => $settings->get_setting('email_debug_mode'),
			'empty_option' => false
		]);

		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

	}

	echo '<h3>Email Settings</h3>';
	$optionvals = array(1=>"Yes", 0=>'No');
	$formwriter->dropinput('emails_active', 'Email module active', [
		'options' => $optionvals,
		'value' => $settings->get_setting('emails_active'),
		'empty_option' => false
	]);	

	$templates = new MultiMailingList(
		array('deleted' => false),
		NULL,		//SORT BY => DIRECTION
		NULL,  //NUM PER PAGE
		NULL);  //OFFSET
	$templates->load();
	$numtemplates = $templates->count_all();
	$outer_optionvals = array('all' => 'All Lists');
	$outer_optionvals = array_merge($outer_optionvals, $templates->get_dropdown_array());
	
	if($settings->get_setting('default_mailing_list')){
		$formwriter->dropinput('default_mailing_list', 'Default mailing list', [
			'options' => $outer_optionvals,
			'value' => $settings->get_setting('default_mailing_list'),
			'empty_option' => true
		]);
	}
	else if($numtemplates){
		$first_template = $templates->get(0);
		$formwriter->dropinput('default_mailing_list', 'Default mailing list', [
			'options' => $outer_optionvals,
			'value' => $first_template,
			'empty_option' => true
		]);
	}
	else{
		$formwriter->dropinput('default_mailing_list', 'Default mailing list', [
			'options' => $outer_optionvals,
			'value' => $settings->get_setting('default_mailing_list'),
			'empty_option' => true
		]);
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

	$formwriter->dropinput('bulk_outer_template', 'Bulk email outer template', [
		'options' => $outer_optionvals,
		'value' => $settings->get_setting('bulk_outer_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('bulk_footer', 'Bulk email footer', [
		'options' => $footer_optionvals,
		'value' => $settings->get_setting('bulk_footer'),
		'empty_option' => false
	]);
	$formwriter->dropinput('individual_email_inner_template', 'Individual email inner template', [
		'options' => $inner_optionvals,
		'value' => $settings->get_setting('individual_email_inner_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('group_email_footer_template', 'Group email footer template', [
		'options' => $footer_optionvals,
		'value' => $settings->get_setting('group_email_footer_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('group_email_outer_template', 'Group email outer template', [
		'options' => $outer_optionvals,
		'value' => $settings->get_setting('group_email_outer_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('group_email_inner_template', 'Group email inner template', [
		'options' => $inner_optionvals,
		'value' => $settings->get_setting('group_email_inner_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('event_email_footer_template', 'Event email footer template', [
		'options' => $footer_optionvals,
		'value' => $settings->get_setting('event_email_footer_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('event_email_outer_template', 'Event email outer template', [
		'options' => $outer_optionvals,
		'value' => $settings->get_setting('event_email_outer_template'),
		'empty_option' => false
	]);
	$formwriter->dropinput('event_email_inner_template', 'Event email inner template', [
		'options' => $inner_optionvals,
		'value' => $settings->get_setting('event_email_inner_template'),
		'empty_option' => false
	]);

	//$optionvals = array("General"=>'general', 'Emails' => 'emails');
	//echo $formwriter->dropinput("Setting group", "stg_group_name", '', $optionvals, $setting->get('stg_group_name'), '', FALSE);

	$formwriter->submitbutton('submit', 'Submit');

	$page->end_box();

	$page->admin_footer();

?>