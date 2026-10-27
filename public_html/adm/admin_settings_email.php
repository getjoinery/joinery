<?php
/** @joinery-admin class=config */
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
	$pageoptions['altlinks'] += array('Updates'=>'/admin/admin_updates');

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

	if(Access::permits(10, $session)){

		if((isset($_SESSION['test_mode']) && $_SESSION['test_mode']) || $settings->get_setting('debug')){
			echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Test or debug mode is on.</div>';
		}

		SettingsFieldRenderer::renderGroups($formwriter, array('email_identity'), array(
			'heading_level' => 'h5',
		));

		// Can the system sender actually send? One check, the same predicates
		// the runtime guard uses — rendered right beside the field that decides
		// it, so a blocked sender is never a silent state.
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		$sender_blocker = EmailSender::transactionalSendBlocker();
		if ($sender_blocker !== null) {
			echo '<div class="alert alert-danger">'
				. '<strong>Automated mail from this site cannot send.</strong> '
				. htmlspecialchars($sender_blocker)
				. ' Every scheduled and transactional email (reminders, receipts, notifications) is refused '
				. 'and logged until this is fixed. The mailbox Setup tab\'s Automated mail identity card '
				. 'walks the fix.</div>';
		}

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
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		$service_labels = EmailSender::getAvailableServices();

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
				echo '<span class="text-success">✓ ' . htmlspecialchars($service_labels[$current_service] ?? $current_service) . ' configured</span>';
			} else {
				echo '<span class="text-danger">✗ ' . htmlspecialchars(($service_labels[$current_service] ?? $current_service) . ' - ' . implode(', ', $primary_validation['errors'])) . '</span>';
				// A service set up somewhere other than this page (connecting an
				// account) says where; its fields here cannot finish it.
				$primary_class = EmailSender::getDiscoveredProviders()[$current_service] ?? null;
				if ($primary_class && method_exists($primary_class, 'getSetupUrl')) {
					echo ' <a href="' . htmlspecialchars($primary_class::getSetupUrl()) . '">Set it up</a>';
				}
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
				echo '<span class="text-success">✓ ' . htmlspecialchars($service_labels[$fallback_service] ?? $fallback_service) . ' configured</span>';
			} else {
				echo '<span class="text-warning">⚠ ' . htmlspecialchars(($service_labels[$fallback_service] ?? $fallback_service) . ' - ' . implode(', ', $fallback_validation['errors'])) . '</span>';
				$fallback_class = EmailSender::getDiscoveredProviders()[$fallback_service] ?? null;
				if ($fallback_class && method_exists($fallback_class, 'getSetupUrl')) {
					echo ' <a href="' . htmlspecialchars($fallback_class::getSetupUrl()) . '">Set it up</a>';
				}
			}
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';

		// DNS Authentication Status (on-demand)
		// The domain checked is the From address's: it is the one DMARC judges,
		// whichever service sends.
		$run_dns_check = isset($_GET['run_dns_check']);
		$default_email_for_dns = (string)$settings->get_setting('defaultemail');
		$dns_domain = strpos($default_email_for_dns, '@') !== false
			? strtolower(substr($default_email_for_dns, strpos($default_email_for_dns, '@') + 1))
			: '';
		if ($dns_domain === '' && $current_service === 'mailgun') {
			$dns_domain = (string)$settings->get_setting('mailgun_domain');
		}

		if ($dns_domain) {
			echo '<div class="row mt-3">';
			echo '<div class="col-md-12">';
			echo '<h5>Will other mail servers trust mail from ' . htmlspecialchars($dns_domain) . '?';
			if (!$run_dns_check) {
				echo ' <a href="?run_dns_check=1' . ($run_validation ? '&run_validation=1' : '') . '" class="btn btn-outline-primary btn-sm ms-2">Check now</a>';
			}
			echo '</h5>';

			if ($run_dns_check) {
				require_once(PathHelper::getIncludePath('includes/DnsAuthChecker.php'));
				// A DKIM key lives at a name its service chose (SMTP2GO's s<id>,
				// Mailgun's own selector), which no list of common names finds.
				// Ask the sending service first; the common names stay for a
				// service that cannot say.
				$provider_selectors = array();
				$primary_class = EmailSender::getDiscoveredProviders()[$current_service] ?? null;
				if ($primary_class && is_callable(array($primary_class, 'getDkimStatus'))) {
					try {
						foreach ($primary_class::getDkimStatus($dns_domain)['records'] ?? array() as $record) {
							$at = strpos((string)($record['name'] ?? ''), '._domainkey.');
							if ($at) $provider_selectors[] = substr($record['name'], 0, $at);
						}
					} catch (Throwable $e) {
						error_log('admin_settings_email: getDkimStatus(' . $dns_domain . ') failed: ' . $e->getMessage());
					}
				}
				$dkim_selectors = array_values(array_unique(array_merge(
					$provider_selectors, array('mx', 'default', 'mail', 'dkim', 'key1', 'selector1'))));
				$dns_results = DnsAuthChecker::quickCheck($dns_domain, $dkim_selectors);

				// Each check in words an owner can act on; the protocol name and the
				// raw record stay in small print for whoever needs them.
				$spf = $dns_results['spf'];
				$dkim = $dns_results['dkim'];
				$dmarc = $dns_results['dmarc'];
				$service_name = $service_labels[$current_service] ?? 'your email service';
				$signed_by_service = $dkim['status'] === 'pass' && in_array($dkim['selector'], $provider_selectors, true);

				if ($dkim['status'] !== 'pass') {
					$verdict = array('danger', '&#10007;', 'Not yet. Your mail is not signed with a key other servers can find, so it is likely to land in spam.');
				} elseif ($spf['status'] === 'pass' && $dmarc['status'] === 'pass') {
					$verdict = array('success', '&#10003;', 'Yes. Your mail is signed, and your domain tells other servers what to do with mail that only pretends to be from you.');
				} else {
					$verdict = array('warning', '&#9888;', 'Mostly. Your mail is signed; one setting below could be stronger.');
				}

				$rows = array();
				$rows[] = array(
					'Signature', 'DKIM', $dkim['status'] === 'pass' ? 'success' : 'danger',
					$dkim['status'] === 'pass'
						? ($signed_by_service
							? 'Your mail is signed by ' . htmlspecialchars($service_name) . ', so other servers can confirm it really came from you.'
							: 'A signing key is published for this domain, so other servers can confirm mail signed with it came from you.')
						: 'No signing key found for this domain. ' . htmlspecialchars($service_name) . ' lists the record to add when you set up your sending domain there.',
					$dkim['selector'] !== '' ? 'key ' . $dkim['selector'] . '._domainkey.' . $dns_domain : 'checked ' . count($dkim['selectors_checked']) . ' key names',
				);
				$spf_rule = strpos($spf['record'], '-all') !== false ? 'and asks other servers to refuse mail from anywhere else'
					: (strpos($spf['record'], '~all') !== false ? 'and asks other servers to treat mail from anywhere else with suspicion' : '');
				$rows[] = array(
					'Approved senders', 'SPF', $spf['status'] === 'pass' ? 'success' : ($spf['status'] === 'warn' ? 'warning' : 'danger'),
					$spf['status'] === 'pass'
						? 'Your domain lists the servers allowed to send its mail' . ($spf_rule !== '' ? ', ' . $spf_rule : '') . '.'
						: ($spf['detail'] === 'Multiple SPF records found (only one allowed)'
							? 'Your domain has more than one approved-senders list, and other servers ignore both. Merge them into one.'
							: ($spf['status'] === 'warn'
								? 'Your domain\'s approved-senders list lets any server send as you, so it protects nothing.'
								: 'Your domain publishes no approved-senders list. Signed mail is still trusted, but publishing one is recommended.')),
					$spf['record'] !== '' ? $spf['record'] : $spf['detail'],
				);
				$dmarc_text = array(
					'reject'     => 'Other servers are told to refuse mail that claims to be from you but fails these checks.',
					'quarantine' => 'Other servers are told to put mail that claims to be from you but fails these checks in spam.',
					'none'       => 'Other servers are told to deliver mail that fails these checks anyway. Fine while setting up; stricter protects you from forgeries.',
				);
				$rows[] = array(
					'Forgery policy', 'DMARC', $dmarc['status'] === 'pass' ? 'success' : ($dmarc['status'] === 'warn' ? 'warning' : 'danger'),
					$dmarc_text[$dmarc['policy']] ?? ($dmarc['status'] === 'fail'
						? 'Your domain gives no instructions for mail that pretends to be from you, so each server decides for itself.'
						: 'Your domain\'s forgery policy is not one other servers recognise.'),
					$dmarc['record'] !== '' ? $dmarc['record'] : $dmarc['detail'],
				);

				$marks = array('success' => '&#10003;', 'warning' => '&#9888;', 'danger' => '&#10007;');
				// One wrapper: the admin theme lays an alert's children out in a row.
				echo '<div class="alert alert-light border"><div>';
				echo '<p class="text-' . $verdict[0] . '"><strong>' . $verdict[1] . ' ' . $verdict[2] . '</strong></p>';
				foreach ($rows as $row) {
					list($title, $tech, $tone, $text, $raw) = $row;
					echo '<div class="mb-2"><span class="text-' . $tone . '">' . $marks[$tone] . '</span> <strong>' . $title . ':</strong> ' . $text
						. '<br><small class="text-muted">' . $tech . ': ' . htmlspecialchars($raw) . '</small></div>';
				}
				echo '<div class="mt-2">';
				echo '<a href="/utils/email_setup_check?domain=' . urlencode($dns_domain) . '" class="btn btn-outline-secondary btn-sm">Detailed Analysis</a>';
				echo '</div>';
				echo '</div></div>';
			}

			echo '</div>';
			echo '</div>';
		}

		// One section per sending service. The fields come from the service's
		// declared group, so a new provider appears here by declaring settings
		// in settings.json — this loop never learns its field names.
		//
		// Only the services the two pickers name are on screen, and choosing
		// another shows its section at once. Hidden sections still submit, so
		// switching away and back loses nothing.
		$discovered_providers = EmailSender::getDiscoveredProviders();
		$chosen_services = array((string)$current_service, (string)$fallback_service);
		foreach ($discovered_providers as $provider_key => $provider_class) {
			$provider_label = $provider_class::getLabel();
			$provider_group = 'email_provider_' . $provider_key;
			$has_api_validation = method_exists($provider_class, 'validateApiConnection');

			if (!SettingsFieldRenderer::namesFor($provider_group, 'core')) continue;

			echo '<div class="email-provider-section" data-provider="' . htmlspecialchars($provider_key) . '"'
				. (in_array($provider_key, $chosen_services, true) ? '' : ' style="display: none;"') . '>';
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
			echo '</div>';
		}
		$formwriter->addReadyScript('(function () {
			var pickers = [document.getElementById("email_service"), document.getElementById("email_fallback_service")];
			function showChosenEmailServices() {
				var chosen = pickers.map(function (p) { return p ? p.value : ""; });
				document.querySelectorAll(".email-provider-section").forEach(function (section) {
					section.style.display = chosen.indexOf(section.getAttribute("data-provider")) !== -1 ? "" : "none";
				});
			}
			pickers.forEach(function (p) { if (p) p.addEventListener("change", showChosenEmailServices); });
		})();');

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