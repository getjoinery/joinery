<?php
/**
 * /setup — the first-login setup wizard (specs/setup_wizard.md).
 *
 * A sequential, chrome-less presentation of the SetupSteps registry. Every
 * step mounts an existing ceremony or panel; this logic owns only the shell:
 * step resolution, dismissal, "not now" decisions, and the welcome save.
 *
 * @version 1.0
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
	// Only steps that declare a decision scope accept one.
	if ($action === 'decline_step') {
		$key = (string)($input['step_key'] ?? '');
		$step = SetupSteps::get($key);
		if ($step && !empty($step['decision'])) {
			$user_id = ($step['decision'] === 'user') ? (int)$viewer->key : NULL;
			SetupSteps::recordDecision($key, $user_id);
			SetupSteps::invalidateSessionCache();
			return LogicResult::redirect('/setup?step=' . urlencode(_setup_next_key($steps, $key)));
		}
		return LogicResult::redirect('/setup');
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
	// the RecoveryKeySetupPanel's — and this forwards them whole. The logic
	// enforces permission 10 itself; return_to bounces its redirect back here.
	$backup_actions = array('save_target', 'test_target', 'run_backup',
		'save_recovery_key', 'verify_recovery_key', 'clear_recovery_key');
	if (in_array($action, $backup_actions, true)) {
		require_once(PathHelper::getIncludePath('adm/logic/admin_backups_logic.php'));
		$result = admin_backups_logic(array_merge($input, array('return_to' => '/setup')));
		if ($action === 'save_target' && (int)$settings->get_setting('backup_target_id') === 0) {
			// One-go: a first target becomes the scheduled target immediately,
			// instead of leaving a second choice for later.
			require_once(PathHelper::getIncludePath('data/backup_target_class.php'));
			$targets = new MultiBackupTarget(array('deleted' => false, 'enabled' => true), array('bkt_id' => 'DESC'), 1);
			$targets->load();
			foreach ($targets as $target) {
				require_once(PathHelper::getIncludePath('data/settings_class.php'));
				Setting::put('backup_target_id', (string)(int)$target->key);
				break;
			}
		}
		SetupSteps::invalidateSessionCache();
		return $result;
	}

	// "Back up nightly": activate the BackupRun task with its declared
	// defaults, and give the archive path slug a sensible default so the
	// first run doesn't stall on a blank.
	if ($action === 'backup_task_activate' && $permission >= 10) {
		require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));
		require_once(PathHelper::getIncludePath('includes/ScheduledTaskRegistry.php'));
		$existing = new MultiScheduledTask(array('task_class' => 'BackupRun', 'deleted' => false));
		$existing->load();
		$task = null;
		foreach ($existing as $row) {
			$task = $row;
			break;
		}
		if ($task !== null) {
			$task->set('sct_is_active', true);
			$task->save();
		} else {
			$discovered = ScheduledTaskRegistry::discover();
			$json = $discovered['BackupRun']['json'] ?? array();
			$task = new ScheduledTask(NULL);
			$task->set('sct_name', $json['name'] ?? 'Backup');
			$task->set('sct_task_class', 'BackupRun');
			$task->set('sct_is_active', true);
			$task->set('sct_frequency', $json['default_frequency'] ?? 'daily');
			if (isset($json['default_time'])) {
				$task->set('sct_schedule_time', $json['default_time']);
			}
			$task->save();
		}
		if (trim((string)$settings->get_setting('backup_path_slug')) === '') {
			require_once(PathHelper::getIncludePath('data/settings_class.php'));
			$slug = preg_replace('/[^A-Za-z0-9_-]/', '', basename(PathHelper::getSiteRoot()));
			if ($slug !== '') {
				Setting::put('backup_path_slug', $slug);
			}
		}
		SetupSteps::invalidateSessionCache();
		return LogicResult::redirect('/setup?step=backups');
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
		// Credentials arrive in this same POST, so write first, then validate
		// the chosen service against what was just saved.
		$service = trim((string)($input['email_service'] ?? ''));
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
			SetupSteps::invalidateSessionCache();
			return LogicResult::redirect('/setup?step=mail_send');
		}
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
			$ok = EmailSender::quickSend($to, 'Test: your site can send email',
				"This is the setup wizard's test message. If you are reading it, sending works — go back and press \"It arrived\".");
			if ($ok) {
				return LogicResult::redirect('/setup?step=mail_send&sent=1');
			}
			$error = 'The test send failed — check the provider credentials and try again.';
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

	// Live statuses for every step in scope.
	$statuses = array();
	foreach ($steps as $step) {
		$statuses[$step['key']] = SetupSteps::statusFor($step, $viewer);
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
		'current_key' => $current_key,
		'current_index' => $current_index,
		'total' => count($keys),
		'error' => $error,
		'heartbeat_warning' => $heartbeat_warning,
		'force_render_step' => $force_render_step,
		'calendar_import_summary' => $calendar_import_summary,
	), $totp_forward));
}

/** The key of the step after $after_key in flow order, or 'done'. */
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
