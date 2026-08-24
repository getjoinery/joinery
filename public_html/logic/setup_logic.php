<?php
/**
 * /setup — the first-login setup wizard (specs/setup_wizard.md).
 *
 * A sequential, chrome-less presentation of the SetupSteps registry. Every
 * step mounts an existing ceremony or panel; this logic owns only the shell:
 * step resolution, dismissal, "not now" decisions, and the welcome save.
 *
 * @version 2.3
 * @changelog 2.3 - backup_task_activate and the run_backup / save_recovery_key
 *   forwarding are gone with the wizard's section 3 and by-hand fold; the
 *   backups step posts only target actions and the unproven-state forms.
 * @version 2.2
 * @changelog 2.2 - Nightly backups turn themselves on (BackupNightly) when the
 *   target and the proven key both exist; backup_task_activate remains only as
 *   the fallback switch.
 * @version 2.1
 * @changelog 2.1 - mail_send_move refuses a lived-in domain
 *   (DnsRelocation::foreignUse) — the same rule that hides the offer on the
 *   page holds when the POST arrives anyway.
 * @version 2.0
 * @changelog 2.0 - Email is one step, sending and receiving: the save also
 *   provisions the owner's mailbox from the From address (domain row, store
 *   alias, grant — the one address question is the whole consent), the DNS
 *   actions publish the FULL mail plan (MX and the receiving stack included),
 *   and Refresh re-grades the receiving domain's verdict alongside the
 *   provider check. The separate receiving step no longer exists.
 * @changelog 1.9 - A successful move persists as dns_move_pending (domain,
 *   target, nameservers, copied records) so the handover survives reloads
 *   while delegation propagates; mail_send_move_cancel forgets it.
 * @changelog 1.8 - mail_send_move runs the guided DNS move: seeds the chosen
 *   open-API host's zone (deployment records + what the domain visibly
 *   answers) before the operator's one nameserver change, via DnsRelocation.
 * @changelog 1.7 - Save and register also ensure the mailbox receiving-domain
 *   row exists (domain only, no alias, no MX) — authority is what lets the
 *   Direct signing identity mint, which puts the two Joinery Direct records
 *   into the sending step's DNS plan.
 * @changelog 1.6 - The sending step does the provider's dashboard errand
 *   itself: mail_send_save derives mailgun_domain from the From address and
 *   registers it at the provider (SendingDomainRegistrar); new actions
 *   mail_send_register / mail_send_verify / mail_send_publish drive the DNS
 *   stage — publish writes the sendingDnsPlan() records APPLY_ADDITIVE
 *   through a DNS driver with a credential used once and never stored.
 * @changelog 1.5 - mail_send_save maps the wizard's single From question
 *   (wizard_from_address) to defaultemail and defaults an empty
 *   defaultemailname to the owner's name.
 * @changelog 1.4 - Runs the HTTPS step's diagnosis server-side when that step
 *   is on screen: the step exists because the site is on plain HTTP, where the
 *   API face correctly refuses to answer — so the page cannot fetch it itself.
 */

function setup_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));

	$session = SessionControl::get_instance();
	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login');
	}
	$settings = Globalvars::get_instance();
	$viewer = SetupSteps::viewerUser();
	if (!$viewer || !$viewer->key) {
		return LogicResult::redirect('/login');
	}
	$permission = (int)$session->get_permission();
	$steps = SetupSteps::stepsForViewer($permission, $viewer);
	if (!$steps) {
		return LogicResult::redirect('/');
	}

	$error = '';
	$action = (string)($input['action'] ?? '');

	// "Finish later" — the one thing dismissal stores (honest friction: the
	// dialog listed what is outstanding and required the checkbox).
	if ($action === 'dismiss') {
		if (empty($input['understand'])) {
			$error = 'Check "I understand" to finish later.';
		} else {
			$viewer->set('usr_setup_dismissed_time', gmdate('Y-m-d H:i:s'));
			$viewer->save();
			SetupSteps::invalidateSessionCache();
			return LogicResult::redirect($permission >= 10 ? '/admin' : '/profile');
		}
	}

	// "Not now" on an optional step: records a decision, never completion.
	// Only steps that declare a decision scope accept one, and the step's own
	// can_decline predicate is asked here, server-side — a refused decline
	// re-renders the step rather than settling it.
	if ($action === 'decline_step') {
		$key = (string)($input['step_key'] ?? '');
		$step = SetupSteps::get($key);
		if ($step && !empty($step['decision'])) {
			if (SetupSteps::canDecline($step, $viewer)) {
				$user_id = ($step['decision'] === 'user') ? (int)$viewer->key : NULL;
				SetupSteps::recordDecision($key, $user_id);
				SetupSteps::invalidateSessionCache();
				return LogicResult::redirect('/setup?step=' . urlencode(_setup_next_key($steps, $key)));
			}
			$error = "This step can't be skipped — your account is able to complete it.";
		} else {
			return LogicResult::redirect('/setup');
		}
	}

	// Welcome: name + timezone, and the site name for the owner.
	if ($action === 'welcome_save') {
		$first = strip_tags(trim((string)($input['usr_first_name'] ?? '')));
		$last = strip_tags(trim((string)($input['usr_last_name'] ?? '')));
		$tz = preg_replace('/[^a-zA-Z0-9\/_+-]/', '', (string)($input['usr_timezone'] ?? ''));
		if ($first === '') {
			$error = 'Enter your name.';
		} else {
			try {
				new DateTimeZone($tz);
			} catch (Exception $e) {
				$tz = '';
			}
			if ($tz === '') {
				$error = 'Choose a valid timezone.';
			}
		}
		if ($error === '') {
			$viewer->set('usr_first_name', $first);
			if ($last !== '') {
				$viewer->set('usr_last_name', $last);
			}
			$viewer->set('usr_timezone', $tz);
			$viewer->save();
			$session->set_timezone($tz);
			if ($permission >= 10) {
				$site_name = trim((string)($input['site_name'] ?? ''));
				if ($site_name !== '') {
					require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
					$write = SettingsWriter::write(array('site_name' => $site_name), array(
						'page' => 'setup',
						'source' => 'core',
						'names' => array('site_name'),
					));
					if (!empty($write['errors'])) {
						$error = reset($write['errors'])[0] ?? 'The site name could not be saved.';
					}
				}
			}
		}
		if ($error === '') {
			SetupSteps::invalidateSessionCache();
			return LogicResult::redirect('/setup?step=' . urlencode(_setup_next_key($steps, 'welcome')));
		}
	}

	// Backups: the wizard submits the backups page's own actions — including
	// the RecoveryKeySetupPanel's unproven-state forms — and this forwards
	// them whole. The logic enforces permission 10 itself; return_to bounces
	// its redirect back here.
	$backup_actions = array('save_target', 'test_target',
		'verify_recovery_key', 'clear_recovery_key');
	if (in_array($action, $backup_actions, true)) {
		require_once(PathHelper::getIncludePath('adm/logic/admin_backups_logic.php'));
		$result = admin_backups_logic(array_merge($input, array('return_to' => '/setup')));
		if ($action === 'save_target') {
			if ((int)$settings->get_setting('backup_target_id') === 0) {
				// One-go: a first target becomes the scheduled target immediately,
				// instead of leaving a second choice for later.
				$targets = new MultiBackupTarget(array('deleted' => false, 'enabled' => true), array('bkt_id' => 'DESC'), 1);
				foreach ($targets as $target) {
					Setting::put('backup_target_id', (string)(int)$target->key);
					break;
				}
			}
			// The target may have been the last missing half (key already
			// proven) — nightly runs need no button of their own.
			BackupNightly::maybe_activate();
		}
		SetupSteps::invalidateSessionCache();
		return $result;
	}

	// Generic declared-settings save for steps that declare their own
	// allow-list (settings_source + settings_names on the step definition) —
	// the plugin that owns the settings owns the list.
	if ($action === 'step_settings_save') {
		$key = (string)($input['step_key'] ?? '');
		$step = SetupSteps::get($key);
		if ($step && !empty($step['settings_source']) && !empty($step['settings_names'])) {
			require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
			$write = SettingsWriter::write($input, array(
				'page' => 'setup',
				'source' => (string)$step['settings_source'],
				'names' => (array)$step['settings_names'],
			));
			if (!empty($write['errors'])) {
				$first = reset($write['errors']);
				$error = is_array($first) ? (string)reset($first) : (string)$first;
			} else {
				SetupSteps::invalidateSessionCache();
				return LogicResult::redirect('/setup?step=' . urlencode($key) . '&saved=1');
			}
		}
	}

	// Calendar: the .ics import (the same IcsImporter path the calendar page
	// uses) and the reminder preferences (forwarded to the calendar_settings
	// action — one ceremony, two mounts).
	$calendar_import_summary = null;
	if ($action === 'calendar_import') {
		require_once(PathHelper::getIncludePath('includes/calendar/IcsImporter.php'));
		require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
		$tmp = (string)($_FILES['ics_file']['tmp_name'] ?? '');
		if (empty($_FILES['ics_file']) || $tmp === '' || !is_uploaded_file($tmp)) {
			$error = 'Choose an .ics file to import.';
		} elseif ($_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
			$error = 'The file could not be uploaded. Please try again.';
		} elseif ($_FILES['ics_file']['size'] > 5 * 1024 * 1024) {
			$error = 'That file is too large (the limit is 5 MB).';
		} else {
			$contents = file_get_contents($tmp);
			if ($contents === false || stripos($contents, 'BEGIN:VCALENDAR') === false) {
				$error = 'That does not look like a calendar (.ics) file.';
			} else {
				$parsed = IcsImporter::parse($contents);
				$calendar_import_summary = IcsImporter::import($parsed,
					CalendarSubject::user((int)$viewer->key), $session->get_timezone());
				SetupSteps::invalidateSessionCache();
			}
		}
	}

	if ($action === 'calendar_prefs') {
		require_once(PathHelper::getThemeFilePath('calendar_settings_logic.php', 'logic'));
		$cal = calendar_settings_logic(array_merge($input, array('action' => 'save')));
		if ($cal->error) {
			$error = $cal->error;
		} else {
			SetupSteps::invalidateSessionCache();
			return LogicResult::redirect('/setup?step=' . urlencode(_setup_next_key($steps, 'calendar')));
		}
	}

	// Sending email: the same declared settings the email settings page
	// writes, validated first exactly as that page does.
	if ($action === 'mail_send_save') {
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));
		require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
		// The wizard asks one question about the From identity — the address —
		// and derives the rest: defaultemail comes from the answer, and an
		// empty defaultemailname defaults to the owner's name (site name for a
		// nameless account). Both stay editable on the email settings page.
		$from_address = trim((string)($input['wizard_from_address'] ?? ''));
		if ($from_address !== '') {
			$input['defaultemail'] = $from_address;
			if (trim((string)$settings->get_setting('defaultemailname')) === '') {
				$from_name = trim(trim((string)$viewer->get('usr_first_name')) . ' ' . trim((string)$viewer->get('usr_last_name')));
				if ($from_name === '') {
					$from_name = trim((string)$settings->get_setting('site_name'));
				}
				if ($from_name !== '') {
					$input['defaultemailname'] = $from_name;
				}
			}
		}
		// Credentials arrive in this same POST, so write first, then validate
		// the chosen service against what was just saved.
		$service = trim((string)($input['email_service'] ?? ''));
		// The Mailgun sending domain is the From address's domain — the wizard
		// asks no separate question for it.
		if ($service === 'mailgun' && $from_address !== '' && strrpos($from_address, '@') !== false) {
			$input['mailgun_domain'] = strtolower(rtrim(substr($from_address, strrpos($from_address, '@') + 1), '.'));
		}
		$names = array('email_service', 'defaultemail', 'defaultemailname');
		foreach (EmailSender::getDiscoveredProviders() as $provider_key => $provider_class) {
			$names = array_merge($names, SettingsFieldRenderer::namesFor('email_provider_' . $provider_key, 'core'));
		}
		$write = SettingsWriter::write($input, array(
			'page' => 'setup',
			'source' => 'core',
			'names' => $names,
		));
		if (!empty($write['errors'])) {
			$first = reset($write['errors']);
			$error = is_array($first) ? (string)reset($first) : (string)$first;
		} elseif ($service !== '') {
			$validation = EmailSender::validateService($service);
			if (empty($validation['valid'])) {
				$error = 'That service is not fully configured: ' . implode(', ', $validation['errors'] ?? array());
			}
		}
		if ($error === '') {
			// The provider's dashboard errand, done by the platform: register
			// the From domain as a sending domain when the provider's API can.
			// A failure is not fatal to the save — the DNS stage names it and
			// offers a retry button.
			$sending_domain = _setup_sending_domain();
			// The address is also the owner's mailbox: domain row, store alias
			// and grant, provisioned now so the DNS published next routes mail
			// that has somewhere to land. Every provider, registrar or not.
			_setup_ensure_receiving_mailbox($viewer);
			$provider_class = EmailSender::getDiscoveredProviders()[$service] ?? null;
			if ($sending_domain !== '' && $provider_class !== null
					&& in_array('SendingDomainRegistrar', class_implements($provider_class) ?: array(), true)) {
				$reg = $provider_class::createSendingDomain($sending_domain);
				$_SESSION['setup_mail_send_result'] = array(
					'registered' => (($reg['status'] ?? '') === 'ok'),
					'register_error' => (string)($reg['error'] ?? ''),
					'publish_summary' => '', 'publish_error' => '', 'checked_state' => '',
				);
			}
			SetupSteps::invalidateSessionCache();
			return LogicResult::redirect('/setup?step=mail_send');
		}
	}

	// The sending step's DNS stage: three buttons against the From domain.
	// Register re-runs the provider-side registration; verify asks the
	// provider to re-check DNS now; publish writes the sending records through
	// a DNS driver with a credential used once and never stored. State is
	// always re-derived on render — nothing here trusts the POST beyond which
	// button was pressed.
	if (in_array($action, array('mail_send_register', 'mail_send_verify', 'mail_send_publish',
			'mail_send_move', 'mail_send_move_cancel'), true) && $permission >= 10) {
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		$notice = array('registered' => null, 'register_error' => '',
			'publish_summary' => '', 'publish_error' => '', 'checked_state' => '', 'move' => null);
		$sending_domain = _setup_sending_domain();
		$service = EmailSender::activeServiceKey();
		$provider_class = ($service !== '') ? (EmailSender::getDiscoveredProviders()[$service] ?? null) : null;
		$registrar = $provider_class !== null
			&& in_array('SendingDomainRegistrar', class_implements($provider_class) ?: array(), true);
		if ($sending_domain === '' || !$registrar) {
			return LogicResult::redirect('/setup?step=mail_send');
		}

		// Idempotent, and cheap once the rows exist — run for every button so
		// a site that reached this stage without a wizard save (or before this
		// code existed) still gains the mailbox and, with it, the Direct
		// records on its next press.
		_setup_ensure_receiving_mailbox($viewer);

		if ($action === 'mail_send_register') {
			$reg = $provider_class::createSendingDomain($sending_domain);
			$notice['registered'] = (($reg['status'] ?? '') === 'ok');
			$notice['register_error'] = (string)($reg['error'] ?? '');
		}

		if ($action === 'mail_send_verify') {
			$notice['checked_state'] = is_callable(array($provider_class, 'verifySendingDomain'))
				? (string)$provider_class::verifySendingDomain($sending_domain)
				: (string)$provider_class::getSendingDomainState($sending_domain);
			// One Refresh answers for the whole step: the receiving verdict
			// (MX and friends) is re-graded now, not left to the daily pass.
			_setup_refresh_receiving_verdict($sending_domain);
		}

		if ($action === 'mail_send_publish') {
			if (!class_exists('InboundEmailSetupCheck')) {
				$notice['publish_error'] = 'Publishing needs the mailbox plugin — add the records at your DNS host instead.';
			} else {
				$plan = _setup_wizard_dns_plan($sending_domain);
				if ($plan === null || $plan->isEmpty()) {
					$notice['publish_error'] = 'No records to publish yet — press Refresh and try again.';
				} else {
					require_once(PathHelper::getIncludePath('includes/dns/DnsDriverRegistry.php'));
					require_once(PathHelper::getIncludePath('includes/dns/DnsReconciler.php'));
					$driver_class = DnsDriverRegistry::get(trim((string)($input['dns_provider'] ?? '')));
					if ($driver_class === null) {
						$notice['publish_error'] = 'Choose a DNS provider to publish the records.';
					} elseif ($driver_class::credentialMode() !== DnsProvider::CREDENTIAL_API) {
						$notice['publish_error'] = $driver_class::getLabel()
							. ' publishes through a sign-in consent flow — use the mail Setup tab for that, or add the records yourself.';
					} else {
						$credential = array('account_id' => trim((string)($input['dns_account'] ?? '')));
						$missing = false;
						foreach ($driver_class::credentialFields() as $field => $spec) {
							$credential[$field] = trim((string)($input['dns_cred_' . $field] ?? ''));
							if ($credential[$field] === '' && empty($spec['optional'])
									&& $field !== 'session_token' && $field !== 'client_ip') {
								$missing = true;
							}
						}
						if ($missing) {
							$notice['publish_error'] = 'Enter the ' . $driver_class::getLabel() . ' credential to publish.';
						} else {
							$publish = DnsPublishBox::publish($driver_class, $credential, $plan,
								array(), DnsReconciler::APPLY_ADDITIVE);
							unset($credential);   // the only copy, gone before the response is built
							$notice['publish_summary'] = DnsPublishBox::summarizeResults($publish);
							if (!empty($publish['error'])) {
								$notice['publish_error'] = (string)$publish['error'];
							}
							// A publish worth doing is worth checking: ask the
							// provider to look at the fresh records right away.
							if ($notice['publish_error'] === '' && is_callable(array($provider_class, 'verifySendingDomain'))) {
								$notice['checked_state'] = (string)$provider_class::verifySendingDomain($sending_domain);
							}
						}
					}
				}
			}
		}

		// The guided move to an open-API DNS host: create and seed the
		// destination zone — the deployment's records plus everything the
		// domain visibly answers — BEFORE the operator's one manual step, the
		// nameserver change at their registrar. Idempotent; the credential
		// lives inside this request only.
		if ($action === 'mail_send_move') {
			require_once(PathHelper::getIncludePath('includes/dns/DnsRelocation.php'));
			$targets = DnsRelocation::targets();
			$target = trim((string)($input['dns_move_target'] ?? ''));
			if (!isset($targets[$target])) {
				$notice['move'] = array('error' => 'Choose where your DNS should live.');
			} else {
				$target_class = $targets[$target];
				$credential = array('account_id' => '');
				$missing = false;
				foreach ($target_class::credentialFields() as $field => $spec) {
					$credential[$field] = trim((string)($input['move_cred_' . $field] ?? ''));
					if ($credential[$field] === '' && empty($spec['optional'])
							&& $field !== 'session_token' && $field !== 'client_ip') {
						$missing = true;
					}
				}
				if ($missing) {
					$notice['move'] = array('error' => 'Enter the ' . $target_class::getLabel()
						. ' credential to set up your DNS there.');
				} elseif (($setup_foreign = DnsRelocation::foreignUse($sending_domain,
						_setup_wizard_dns_plan($sending_domain))) !== '') {
					// The page hides the offer for a lived-in domain; a POST
					// that arrives anyway (a stale page, a changed domain) is
					// refused for the same reason it was hidden.
					$notice['move'] = array('error' => 'Moving this domain\'s DNS is not offered: '
						. $setup_foreign . '. Add the records by hand instead — a move cannot see '
						. 'records on names it does not know about, and would leave them behind.');
				} else {
					$own_plan = _setup_wizard_dns_plan($sending_domain)
						?? new DnsRecordPlan($sending_domain, 'setup_wizard');
					$move = DnsRelocation::seed($target_class, $credential, $sending_domain, $own_plan);
					unset($credential);   // the only copy, gone before the response is built
					$move['target'] = $target;
					$move['target_label'] = $target_class::getLabel();
					$notice['move'] = $move;
					if (($move['error'] ?? '') === '') {
						// The move now outlives this page load: delegation takes
						// hours to days, so the handover face must survive every
						// reload until the domain's NS records answer from the
						// target (the dns stage clears this when they do) or the
						// operator cancels. Nothing here is secret — it is the
						// same record data the manual tab prints.
						Setting::put('dns_move_pending', json_encode(array(
							'domain'       => $sending_domain,
							'target'       => $target,
							'target_label' => $target_class::getLabel(),
							'nameservers'  => $move['nameservers'],
							'copied'       => $move['copied'],
							'summary'      => $move['summary'],
							'time'         => gmdate('Y-m-d H:i:s'),
						)));
					}
				}
			}
		}

		// The operator changed their mind mid-move: forget the pending state
		// and the dns stage asks its question fresh. The seeded zone stays at
		// the destination — records at a host nothing delegates to are inert.
		if ($action === 'mail_send_move_cancel') {
			Setting::put('dns_move_pending', '');
		}

		$_SESSION['setup_mail_send_result'] = $notice;
		SetupSteps::invalidateSessionCache();
		return LogicResult::redirect('/setup?step=mail_send');
	}

	// Test send goes to the acting user; success is recorded only when they
	// confirm it arrived — provider acceptance is not delivery.
	if ($action === 'mail_send_test') {
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		$blocker = EmailSender::transactionalSendBlocker();
		if ($blocker !== null) {
			$error = $blocker;
		} elseif ((string)$settings->get_setting('email_dry_run') === '1') {
			$error = 'Email dry-run mode is on (Settings → Email), so nothing can really send.';
		} else {
			$to = (string)$viewer->get('usr_email');
			// A failed send is this step's expected weather, not an exceptional
			// condition — a throw here must land as the step's error, never
			// escape the view (an escaped exception renders as the 404 page).
			try {
				$ok = EmailSender::quickSend($to, 'Test: your site can send email',
					"This is the setup wizard's test message. If you are reading it, sending works — go back and press \"It arrived\".");
			} catch (Throwable $e) {
				$ok = false;
				$error = 'The test send failed: ' . $e->getMessage();
			}
			if ($ok) {
				return LogicResult::redirect('/setup?step=mail_send&sent=1');
			}
			if ($error === '') {
				$error = 'The test send failed — check the provider credentials and try again.';
			}
		}
	}

	if ($action === 'mail_send_confirm') {
		require_once(PathHelper::getIncludePath('data/settings_class.php'));
		Setting::put('email_test_send_last_success', gmdate('Y-m-d H:i:s'));
		SetupSteps::invalidateSessionCache();
		return LogicResult::redirect('/setup?step=mail_send');
	}

	// TOTP enrollment forwards to the security page's own logic — one
	// ceremony, two mounts. Only the fields the wizard renders are pulled
	// from its result. force_render_step keeps the step's partial on screen
	// after success: the backup codes are shown once and must not be skipped
	// just because the step went green.
	$totp_forward = array();
	$force_render_step = '';
	if ($action === 'start_enable' || $action === 'confirm_enable') {
		require_once(PathHelper::getThemeFilePath('security_logic.php', 'logic'));
		$sec = security_logic($input);
		if ($sec->redirect) {
			return $sec;
		}
		$totp_forward = array(
			'totp_setup_in_progress' => !empty($sec->data['setup_in_progress']),
			'totp_secret'            => (string)($sec->data['secret'] ?? ''),
			'totp_qr_uri'            => (string)($sec->data['qr_uri'] ?? ''),
			'totp_backup_codes'      => is_array($sec->data['backup_codes'] ?? null) ? $sec->data['backup_codes'] : array(),
			'totp_just_enabled'      => !empty($sec->data['just_enabled']),
		);
		if ($action === 'confirm_enable' && empty($sec->data['just_enabled'])) {
			$error = "That code didn't match — check your authenticator app and try again.";
		}
		if (!empty($sec->data['just_enabled'])) {
			$force_render_step = 'signin_security';
			SetupSteps::invalidateSessionCache();
		}
	}

	// Live statuses for every step in scope, plus the steps that are green
	// only because the viewer answered "not now" — those still render their
	// controls, so the choice can be changed in place.
	$statuses = array();
	$declined = array();
	foreach ($steps as $step) {
		$statuses[$step['key']] = SetupSteps::statusFor($step, $viewer);
		$declined[$step['key']] = SetupSteps::isDeclinedOnly($step, $viewer);
	}

	// Current step: an explicit ?step= wins ('done' is the final checklist);
	// otherwise the first non-green step, auto-advancing past finished ones.
	$keys = array_map(function ($s) { return $s['key']; }, $steps);
	$requested = (string)($input['step'] ?? '');
	$current_key = '';
	if ($requested === 'done') {
		$current_key = 'done';
	} elseif ($requested !== '' && in_array($requested, $keys, true)) {
		$current_key = $requested;
	} else {
		foreach ($steps as $step) {
			if ($statuses[$step['key']] !== SetupSteps::STATUS_GREEN) {
				$current_key = $step['key'];
				break;
			}
		}
		if ($current_key === '') {
			$current_key = 'done';
		}
	}

	$current_index = ($current_key === 'done') ? count($keys) : (int)array_search($current_key, $keys, true);

	// The HTTPS step's live diagnosis (DNS, TLS probe, retry-timer state) —
	// run only when its panel will actually render, since the checks talk to
	// the network and take a few seconds. "Check again" is simply a reload of
	// this page: every render re-runs the checks fresh.
	$https_diagnosis = null;
	if ($current_key === 'https' && $permission >= 10
		&& (($statuses['https'] ?? '') !== SetupSteps::STATUS_GREEN || !empty($declined['https']))) {
		require_once(PathHelper::getIncludePath('logic/setup_https_check_logic.php'));
		$https_diagnosis = setup_https_diagnose();
	}

	// The done screen surfaces the scheduled-task heartbeat only when it is
	// broken — imports, reminders, and backups all silently stall without it.
	$heartbeat_warning = '';
	if ($current_key === 'done') {
		$last_tick = (string)$settings->get_setting('scheduled_tasks_last_cron_run');
		$stale = true;
		if ($last_tick !== '') {
			$stale = (time() - strtotime($last_tick . ' UTC')) > 1800;
		}
		if ($stale) {
			$heartbeat_warning = 'The background task runner has not ticked in over 30 minutes. '
				. 'Imports, email reminders, and backups will not run until it does — check the cron setup on the server.';
		}
	}

	return LogicResult::render(array_merge(array(
		'session' => $session,
		'settings' => $settings,
		'viewer' => $viewer,
		'permission' => $permission,
		'steps' => $steps,
		'statuses' => $statuses,
		'declined' => $declined,
		'current_key' => $current_key,
		'current_index' => $current_index,
		'total' => count($keys),
		'error' => $error,
		'heartbeat_warning' => $heartbeat_warning,
		'force_render_step' => $force_render_step,
		'calendar_import_summary' => $calendar_import_summary,
		'https_diagnosis' => $https_diagnosis,
	), $totp_forward));
}

/** The key of the step after $after_key in flow order, or 'done'. */
/**
 * Ensure the mailbox plugin's receiving-domain row exists for the sending
 * domain (idempotent, domain only — no alias, no MX record, so where the
 * domain's mail arrives does not move). The row is what makes this deployment
 * authoritative for the domain, which is what lets a Joinery Direct signing
 * identity be minted, which is what puts the two Direct records (SRV +
 * signing key) into the sending step's DNS plan — the operator hands over a
 * DNS credential once, so those ride along. Without the mailbox plugin this
 * quietly does nothing.
 */
function _setup_ensure_receiving_domain(string $domain): void {
	if ($domain === '' || !class_exists('InboundEmailDomain')) {
		return;
	}
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/provisioning.php'));
	$result = mailbox_provision_domain($domain);
	if (!empty($result['error'])) {
		error_log('setup_logic: could not ensure receiving domain ' . $domain . ': ' . $result['error']);
	}
}

/**
 * Ensure the owner's mailbox exists for the stored From address: the domain
 * row, the store-mode alias, and the grant, in one idempotent call (grants
 * are added by union, so nobody's existing access narrows). The wizard's one
 * address question is the whole receiving consent — mail to that address
 * arrives at this site — so the mailbox switch flips on with it. Quietly
 * nothing without the mailbox plugin or a stored From address.
 */
function _setup_ensure_receiving_mailbox(User $viewer): void {
	$email = trim((string)Globalvars::get_instance()->get_setting('defaultemail'));
	$at = strrpos($email, '@');
	if ($at === false || !class_exists('InboundEmailDomain')) {
		return;
	}
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/provisioning.php'));
	$result = mailbox_provision_mailbox(
		strtolower(rtrim(substr($email, $at + 1), '.')),
		substr($email, 0, $at),
		(int)$viewer->key
	);
	if (!empty($result['error'])) {
		error_log('setup_logic: could not ensure the mailbox for ' . $email . ': ' . $result['error']);
		return;
	}
	if ((string)Globalvars::get_instance()->get_setting('mailbox_enabled') !== '1') {
		Setting::put('mailbox_enabled', '1');
	}
}

/**
 * The wizard's DNS plan for the mail domain: the FULL mail shape — MX and
 * the receiving stack included — because the wizard sets up email as one
 * thing and the operator hands over a DNS credential exactly once. The
 * outbound-only slice remains for a domain that cannot receive here: no
 * enabled receiving row, or an IMAP source whose mail arrives by pull.
 * Null without the mailbox plugin.
 */
function _setup_wizard_dns_plan(string $domain): ?DnsRecordPlan {
	if ($domain === '' || !class_exists('InboundEmailSetupCheck')) {
		return null;
	}
	$checker = new InboundEmailSetupCheck();
	$model = InboundEmailDomain::GetByDomain($domain);
	if ($model && $model->get('ied_is_enabled') && !$model->get('ied_is_imap_source')) {
		return $checker->dnsPlan($domain);
	}
	return $checker->sendingDnsPlan($domain);
}

/**
 * Re-grade the receiving domain's DNS verdict now — the same check entry
 * point and the same grading rules as the daily CheckDomainSetup pass — so
 * one Refresh answers for arriving mail as well as for the provider.
 */
function _setup_refresh_receiving_verdict(string $domain): void {
	if ($domain === '' || !class_exists('InboundEmailSetupCheck')) {
		return;
	}
	$model = InboundEmailDomain::GetByDomain($domain);
	if (!$model || $model->get('ied_is_imap_source')) {
		return;
	}
	require_once(PathHelper::getIncludePath('plugins/mailbox/tasks/CheckDomainSetup.php'));
	try {
		$status = CheckDomainSetup::verdictFor((new InboundEmailSetupCheck())->runDomainChecks($domain));
	} catch (Throwable $e) {
		return;
	}
	// Nothing evaluable is no new information — keep the standing verdict.
	if ($status === 'unknown' && (string)$model->get('ied_setup_status') !== '') {
		return;
	}
	$model->set('ied_setup_status', $status);
	$model->set('ied_setup_checked_time', gmdate('Y-m-d H:i:s'));
	$model->save();
}

/**
 * Domain of the stored From address — what the sending step registers at the
 * provider and publishes DNS for. Empty when no From address is stored yet.
 */
function _setup_sending_domain(): string {
	$email = trim((string)Globalvars::get_instance()->get_setting('defaultemail'));
	$at = strrpos($email, '@');
	return ($at !== false) ? strtolower(rtrim(substr($email, $at + 1), '.')) : '';
}

function _setup_next_key(array $steps, string $after_key): string {
	$found = false;
	foreach ($steps as $step) {
		if ($found) {
			return $step['key'];
		}
		if ($step['key'] === $after_key) {
			$found = true;
		}
	}
	return 'done';
}
