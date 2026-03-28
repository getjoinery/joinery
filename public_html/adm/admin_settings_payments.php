<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$settings = Globalvars::get_instance();

	// Check if validation should run (performance optimization)
	$run_validation = isset($_GET['run_validation']) && $_GET['run_validation'] == '1';

	if($_POST){

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

	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	// Tab menu for settings pages
	$tab_menus = array(
		'General Settings' => '/admin/admin_settings',
		'Payment Settings' => '/admin/admin_settings_payments',
		'Email Settings' => '/admin/admin_settings_email',
	);
	echo AdminPage::tab_menu($tab_menus, 'Payment Settings');

	$formwriter = $page->getFormWriter('form1');

		?>
		<script type="text/javascript">

		function set_choices(){
			const controlField = document.getElementById('use_paypal_checkout');
			const value = controlField ? controlField.value : '';

			const containers = ['paypal_api_key_container', 'paypal_api_secret_container',
			                   'paypal_api_key_test_container', 'paypal_api_secret_test_container',
			                   'use_venmo_checkout_container'];
			const display = (value == 0 || value == '') ? 'none' : 'block';

			containers.forEach(function(containerId) {
				const container = document.getElementById(containerId);
				if (container) {
					container.style.display = display;
				}
			});
		}

		document.addEventListener('DOMContentLoaded', function() {
			set_choices();

			const controlField = document.getElementById('use_paypal_checkout');
			if (controlField) {
				controlField.addEventListener('change', function() {
					set_choices();
				});
			}

		});

		</script>
		<?php

	// Note: Validation rules moved to individual field definitions in V2

	// Note: Validation rules are now specified directly in field options (FormWriter V2)

	$formwriter->begin_form();

		if(StripeHelper::isTestMode()){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}		

		$optionvals = array(1=>"Yes", 0=>'No');
		$formwriter->dropinput('debug', 'Payment Debug Mode', [
			'options' => $optionvals,
			'value' => $settings->get_setting('debug'),
			'empty_option' => false
		]);

		// Stripe Configuration Section
		echo '<h4>Stripe Configuration</h4>';
		
		// Stripe Live API section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>Stripe Live API Settings</h5>';
		$formwriter->textinput('stripe_api_key', 'Stripe Publishable Key (Example: pk_live_xxxx)', [
			'value' => $settings->get_setting('stripe_api_key'),
			'validation' => [
				'pattern' => '^pk_(live|test)_[a-zA-Z0-9]{24,}$',
				'messages' => ['pattern' => 'Must start with pk_live_ or pk_test_ (not sk_)']
			],
			'help_text' => 'Must start with pk_live_ or pk_test_ (not sk_)'
		]);
		$formwriter->textinput('stripe_api_pkey', 'Stripe Secret/Private Key (Example: sk_live_xxxx)', [
			'value' => $settings->get_setting('stripe_api_pkey'),
			'validation' => [
				'pattern' => '^sk_(live|test)_[a-zA-Z0-9]{24,}$',
				'messages' => ['pattern' => 'Must start with sk_live_ or sk_test_ (not pk_)']
			],
			'help_text' => 'Must start with sk_live_ or sk_test_ (not pk_)'
		]);
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Live API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$stripe_api_key = $settings->get_setting('stripe_api_key');
			$stripe_api_pkey = $settings->get_setting('stripe_api_pkey');
			
			if (!empty($stripe_api_key)) {
			// Test Stripe Live API connection
			$autoload_path = PathHelper::getComposerAutoloadPath();
			if (file_exists($autoload_path)) {
				try {
					require_once($autoload_path);
					
					// Create a temporary StripeHelper instance for live API
					$original_test_mode = $_SESSION['test_mode'] ?? null;
					$_SESSION['test_mode'] = false; // Force live mode
					
					require_once(PathHelper::getIncludePath('/includes/StripeHelper.php'));
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
		$formwriter->textinput('stripe_api_key_test', 'Test Stripe Publishable Key (Example: pk_test_xxxx)', [
			'value' => $settings->get_setting('stripe_api_key_test'),
			'validation' => [
				'pattern' => '^pk_(live|test)_[a-zA-Z0-9]{24,}$',
				'messages' => ['pattern' => 'Must start with pk_live_ or pk_test_ (not sk_)']
			],
			'help_text' => 'Must start with pk_live_ or pk_test_ (not sk_)'
		]);
		$formwriter->textinput('stripe_api_pkey_test', 'Test Stripe Secret/Private Key (Example: sk_test_xxxx)', [
			'value' => $settings->get_setting('stripe_api_pkey_test'),
			'validation' => [
				'pattern' => '^sk_(live|test)_[a-zA-Z0-9]{24,}$',
				'messages' => ['pattern' => 'Must start with sk_live_ or sk_test_ (not pk_)']
			],
			'help_text' => 'Must start with sk_live_ or sk_test_ (not pk_)'
		]);
		echo '</div>';
		echo '<div class="col-md-6">';
		echo '<h5>Test API Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';
		
		if ($run_validation) {
			$stripe_api_key_test = $settings->get_setting('stripe_api_key_test');
			$stripe_api_pkey_test = $settings->get_setting('stripe_api_pkey_test');
			
			if (!empty($stripe_api_key_test)) {
			// Test Stripe Test API connection
			$autoload_path = PathHelper::getComposerAutoloadPath();
			if (file_exists($autoload_path)) {
				try {
					require_once($autoload_path);
					
					// Create a temporary StripeHelper instance for test API
					$original_test_mode = $_SESSION['test_mode'] ?? null;
					$_SESSION['test_mode'] = true; // Force test mode
					
					require_once(PathHelper::getIncludePath('/includes/StripeHelper.php'));
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
		$formwriter->textinput('stripe_endpoint_secret', 'Stripe Endpoint Secret (Example: whsec_xxxx)', [
			'value' => $settings->get_setting('stripe_endpoint_secret')
		]);
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
		$optionvals = array('stripe_regular'=>"Stripe Regular", 'stripe_checkout' => 'Stripe Checkout', 'none' => 'None');
		$formwriter->dropinput('checkout_type', 'Checkout Type', [
			'options' => $optionvals,
			'value' => $settings->get_setting('checkout_type'),
			'empty_option' => false
		]);
		echo '<div style="margin: 50px 0;"></div>';
		
		// PayPal Configuration Section
		echo '<h4>PayPal Configuration</h4>';
		$optionvals = array(1=>"Yes", 0=>'No');
		$formwriter->dropinput('use_paypal_checkout', 'Enable Paypal Checkout', [
			'options' => $optionvals,
			'value' => $settings->get_setting('use_paypal_checkout'),
			'empty_option' => false
		]);

		$formwriter->dropinput('use_venmo_checkout', 'Enable Venmo at Checkout', [
			'options' => array(1 => "Yes", 0 => 'No'),
			'value' => $settings->get_setting('use_venmo_checkout'),
			'empty_option' => false
		]);

		// PayPal Live API section with two-column layout and API validation
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		echo '<h5>PayPal Live API Settings</h5>';
		$formwriter->textinput('paypal_api_key', 'Paypal Client ID (Example: ATF46g-L-ler2xxxx)', [
			'value' => $settings->get_setting('paypal_api_key')
		]);
		$formwriter->textinput('paypal_api_secret', 'Paypal Client Secret (Example: ELTF_ie6uGhueKxxxx)', [
			'value' => $settings->get_setting('paypal_api_secret')
		]);
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
				
				require_once(PathHelper::getIncludePath('/includes/PaypalHelper.php'));
				
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
		$formwriter->textinput('paypal_api_key_test', 'Test Paypal Client ID (Example: ATF46g-L-ler2xxxx)', [
			'value' => $settings->get_setting('paypal_api_key_test')
		]);
		$formwriter->textinput('paypal_api_secret_test', 'Test Paypal Client Secret (Example: ELTF_ie6uGhueKxxxx)', [
			'value' => $settings->get_setting('paypal_api_secret_test')
		]);
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
				
				require_once(PathHelper::getIncludePath('/includes/PaypalHelper.php'));
				
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

	$optionvals = array('usd'=>"US Dollar", 'eur' => 'Euro');
	$formwriter->dropinput('site_currency', 'Site Currency', [
		'options' => $optionvals,
		'value' => $settings->get_setting('site_currency'),
		'empty_option' => false
	]);

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>