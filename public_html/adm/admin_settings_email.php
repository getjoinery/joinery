<?php
	require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	require_once(PathHelper::getIncludePath('data/pages_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_settings_email_logic.php'));

	$page_vars = process_logic(admin_settings_email_logic(array_merge($_GET, $_POST)));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	$run_validation = $page_vars['run_validation'];
	$errors = isset($page_vars['errors']) ? $page_vars['errors'] : array();

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

	$pageoptions['title'] = "Settings";
	$page->begin_box($pageoptions);

	echo AdminPage::settings_tab_menu('Email Settings');

	// Display validation errors if any
	if (!empty($errors)) {
		echo '<div class="alert alert-danger" role="alert">';
		echo '<strong>Settings not saved:</strong><ul style="margin-bottom: 0;">';
		foreach ($errors as $error) {
			echo '<li>' . htmlspecialchars($error) . '</li>';
		}
		echo '</ul></div>';
	}

	$formwriter = $page->getFormWriter('form1');

	$formwriter->begin_form();

	if($_SESSION['permission'] == 10){

		if((isset($_SESSION['test_mode']) && $_SESSION['test_mode']) || $settings->get_setting('debug')){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}

		SettingsFieldRenderer::renderGroups($formwriter, array('email_identity'), array(
			'heading_level' => 'h5',
		));

		// Mailing list provider, with its connection status beside it. Which
		// provider's credentials are on screen follows the declared show_when,
		// so adding a provider is a manifest change, not a page change.
		require_once(PathHelper::getIncludePath('includes/MailingListService.php'));
		$current_mailing_list_provider = $settings->get_setting('mailing_list_provider');

		echo '<div class="row">';
		echo '<div class="col-md-6">';
		SettingsFieldRenderer::renderGroups($formwriter, array('mailing_list'), array(
			'heading_level' => 'h5',
		));
		echo '</div>';

		echo '<div class="col-md-6">';
		echo '<h5>Connection Status</h5>';
		echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';

		if ($run_validation) {
			$provider_instance = $current_mailing_list_provider
				? MailingListService::getProvider($current_mailing_list_provider)
				: null;
			if (!$provider_instance) {
				echo '<div style="color: #666; text-align: center; padding: 40px 10px;">No mailing list provider selected</div>';
			} elseif (!$provider_instance::validateConfiguration()['valid']) {
				echo '<div style="color: #666; text-align: center; padding: 40px 10px;">Credentials not configured &mdash; validation skipped</div>';
			} else {
				$result = $provider_instance->validateApiConnection();
				if (!empty($result['success'])) {
					echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>&#10003; ' . htmlspecialchars($result['label']) . '</strong></div>';
					if (!empty($result['details']) && is_array($result['details'])) {
						echo '<div style="font-size: 11px; color: #666; margin-bottom: 8px;"><strong>Details:</strong></div>';
						foreach ($result['details'] as $key => $value) {
							echo '<div style="font-size: 10px; color: #007bff; margin-bottom: 1px; padding: 1px 3px; background: white; border-radius: 2px;">';
							echo htmlspecialchars((string)$key) . ': <span style="color: #666;">' . htmlspecialchars((string)$value) . '</span>';
							echo '</div>';
						}
					}
				} else {
					echo '<div style="color: #dc3545;"><strong>&#10007; ' . htmlspecialchars($result['label'] ?? 'Connection Failed') . '</strong></div>';
					if (!empty($result['error'])) {
						echo '<div style="color: #666; font-size: 10px; margin-top: 5px;">' . htmlspecialchars($result['error']) . '</div>';
					}
				}
			}
		} else {
			echo '<div style="text-align: center; padding: 40px;">';
			echo '<p style="color: #666; margin-bottom: 15px;">API validation not run yet</p>';
			echo '<a href="?run_validation=1" class="btn btn-primary btn-sm">Run All Validations</a>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

		// Which services send, with the status of each beside them.
		echo '<div class="row">';
		echo '<div class="col-md-6">';
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		SettingsFieldRenderer::renderGroups($formwriter, array('email_delivery'), array(
			'heading_level' => 'h3',
		));
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

		// DNS Authentication Status (on-demand)
		$run_dns_check = isset($_GET['run_dns_check']);
		$mailgun_domain_for_dns = $settings->get_setting('mailgun_domain');
		$default_email_for_dns = $settings->get_setting('defaultemail');
		$dns_domain = $mailgun_domain_for_dns;
		if (!$dns_domain && $default_email_for_dns && strpos($default_email_for_dns, '@') !== false) {
			$dns_domain = substr($default_email_for_dns, strpos($default_email_for_dns, '@') + 1);
		}

		if ($dns_domain) {
			echo '<div class="row mt-3">';
			echo '<div class="col-md-12">';
			echo '<h5>Email Authentication Status';
			if (!$run_dns_check) {
				echo ' <a href="?run_dns_check=1' . ($run_validation ? '&run_validation=1' : '') . '" class="btn btn-outline-primary btn-sm ms-2">Check DNS</a>';
			}
			echo '</h5>';

			if ($run_dns_check) {
				require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));
				$dns_results = DnsAuthChecker::quickCheck($dns_domain);

				echo '<div class="alert alert-light border">';
				echo '<strong>Domain:</strong> ' . htmlspecialchars($dns_domain) . '<br/>';

				// SPF
				$spf = $dns_results['spf'];
				echo '<strong>SPF:</strong> ';
				if ($spf['status'] === 'pass') {
					echo '<span class="text-success">&#10003; ' . htmlspecialchars($spf['detail']) . '</span>';
				} elseif ($spf['status'] === 'warn') {
					echo '<span class="text-warning">&#9888; ' . htmlspecialchars($spf['detail']) . '</span>';
				} else {
					echo '<span class="text-danger">&#10007; ' . htmlspecialchars($spf['detail']) . '</span>';
				}
				echo '<br/>';

				// DKIM
				$dkim = $dns_results['dkim'];
				echo '<strong>DKIM:</strong> ';
				if ($dkim['status'] === 'pass') {
					echo '<span class="text-success">&#10003; ' . htmlspecialchars($dkim['detail']) . '</span>';
				} else {
					echo '<span class="text-danger">&#10007; ' . htmlspecialchars($dkim['detail']) . '</span>';
				}
				echo '<br/>';

				// DMARC
				$dmarc = $dns_results['dmarc'];
				echo '<strong>DMARC:</strong> ';
				if ($dmarc['status'] === 'pass') {
					echo '<span class="text-success">&#10003; ' . htmlspecialchars($dmarc['detail']) . '</span>';
				} elseif ($dmarc['status'] === 'warn') {
					echo '<span class="text-warning">&#9888; ' . htmlspecialchars($dmarc['detail']) . '</span>';
				} else {
					echo '<span class="text-danger">&#10007; ' . htmlspecialchars($dmarc['detail']) . '</span>';
				}
				echo '<br/>';

				echo '<div class="mt-2">';
				echo '<a href="/utils/email_setup_check?domain=' . urlencode($dns_domain) . '" class="btn btn-outline-secondary btn-sm">Detailed Analysis</a>';
				echo '</div>';
				echo '</div>';
			}

			echo '</div>';
			echo '</div>';
		}

		// One section per sending service. The fields come from the service's
		// declared group, so a new provider appears here by declaring settings
		// in settings.json — this loop never learns its field names.
		$discovered_providers = EmailSender::getDiscoveredProviders();
		foreach ($discovered_providers as $provider_key => $provider_class) {
			$provider_label = $provider_class::getLabel();
			$provider_group = 'email_provider_' . $provider_key;
			$has_api_validation = method_exists($provider_class, 'validateApiConnection');

			if (!SettingsFieldRenderer::namesFor($provider_group, 'core')) continue;

			echo '<div class="row">';
			echo '<div class="col-md-6">';
			echo '<h5>' . htmlspecialchars($provider_label) . ' Settings</h5>';
			SettingsFieldRenderer::renderGroup($formwriter, $provider_group, array('source' => 'core'));
			echo '</div>';

			// API validation column
			echo '<div class="col-md-6">';
			if ($has_api_validation) {
				echo '<h5>' . htmlspecialchars($provider_label) . ' Connection Status</h5>';
				echo '<div style="min-height: 150px; padding: 20px; background-color: #f5f5f5; border: 1px solid #ddd; border-radius: 5px; overflow-y: auto;">';

				// "Credentials present" defaults to a passing config check, but a
				// provider may override via hasCredentials() when valid config does
				// not imply entered credentials (e.g. SES IAM-role fallback).
				$provider_has_credentials = method_exists($provider_class, 'hasCredentials')
					? $provider_class::hasCredentials()
					: $provider_class::validateConfiguration()['valid'];

				if ($run_validation && !$provider_has_credentials) {
					echo '<div style="text-align: center; padding: 40px;">';
					echo '<p style="color: #666;">Credentials not configured &mdash; validation skipped</p>';
					echo '</div>';
				} elseif ($run_validation) {
					$api_result = $provider_class::validateApiConnection();

					if ($api_result['success']) {
						echo '<div style="color: #28a745; margin-bottom: 10px;"><strong>&#10003; ' . htmlspecialchars($api_result['label']) . '</strong></div>';
					} else {
						echo '<div style="color: #dc3545; margin-bottom: 10px;"><strong>&#10007; ' . htmlspecialchars($api_result['label']) . '</strong></div>';
					}

					// Show detail key-value pairs
					if (!empty($api_result['details'])) {
						foreach ($api_result['details'] as $detail_label => $detail_value) {
							echo '<strong>' . htmlspecialchars($detail_label) . ':</strong> ' . htmlspecialchars($detail_value) . '<br>';
						}
					}

					// Show error message
					if (!empty($api_result['error']) && !$api_result['success']) {
						echo '<div style="color: #dc3545; font-size: 11px; margin-top: 10px;">Error: ' . htmlspecialchars($api_result['error']) . '</div>';
					}
				} else {
					echo '<div style="text-align: center; padding: 40px;">';
					echo '<p style="color: #666; margin-bottom: 15px;">Validation not run yet</p>';
					echo '<a href="?run_validation=1" class="btn btn-primary btn-sm">Run All Validations</a>';
					echo '</div>';
				}

				echo '</div>';
			}
			echo '</div>';
			echo '</div>';
			echo '<div style="margin: 50px 0;"></div>';
		}

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

		SettingsFieldRenderer::renderGroup($formwriter, 'email_testing', array('source' => 'core'));

		echo '</div>';
		echo '</div>';
		echo '<div style="margin: 50px 0;"></div>';

	}

	// Templates the sending paths reach for. The event templates are declared
	// by the event_manager plugin and shown here too — one field, two places,
	// never two fields that can drift.
	SettingsFieldRenderer::renderGroups($formwriter, array('email_module', 'email_templates'), array(
		'heading_level' => 'h3',
	));
	SettingsFieldRenderer::renderGroups($formwriter, array('email'), array(
		'source'        => 'event_manager',
		'heading_level' => 'h3',
	));

	$formwriter->submitbutton('btn_submit', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>