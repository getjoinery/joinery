<?php
/**
 * Scheduled Tasks Admin Logic
 *
 * Handles task discovery, activation, deactivation, configuration,
 * and run-now functionality.
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_scheduled_tasks_logic($get_vars, $post_vars) {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/scheduled_tasks_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$page_regex = '/\/admin\/admin_scheduled_tasks/';

	// Handle POST actions
	if ($post_vars && isset($post_vars['action'])) {
		$action = $post_vars['action'];
		$message = null;
		$error = null;

		if ($action === 'activate') {
			$result = _handle_activate($post_vars);
			$message = $result['message'] ?? null;
			$error = $result['error'] ?? null;
		}
		elseif ($action === 'deactivate' && isset($post_vars['sct_scheduled_task_id'])) {
			$task = new ScheduledTask($post_vars['sct_scheduled_task_id'], true);
			$task->set('sct_is_active', false);
			$task->save();
			$message = 'Task "' . $task->get('sct_name') . '" deactivated.';
		}
		elseif ($action === 'reactivate' && isset($post_vars['sct_scheduled_task_id'])) {
			$task = new ScheduledTask($post_vars['sct_scheduled_task_id'], true);
			$task->set('sct_is_active', true);
			$task->save();
			$message = 'Task "' . $task->get('sct_name') . '" reactivated.';
		}
		elseif ($action === 'run_now' && isset($post_vars['sct_scheduled_task_id'])) {
			$result = _handle_run_now($post_vars['sct_scheduled_task_id']);
			$message = $result['message'] ?? null;
			$error = $result['error'] ?? null;
		}
		elseif ($action === 'save' && isset($post_vars['sct_scheduled_task_id'])) {
			$result = _handle_save($post_vars);
			$message = $result['message'] ?? null;
			$error = $result['error'] ?? null;
		}

		// Save message/error to session
		if ($message) {
			$session->save_message(new DisplayMessage(
				$message, 'Success', $page_regex,
				DisplayMessage::MESSAGE_ANNOUNCEMENT,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}
		if ($error) {
			$session->save_message(new DisplayMessage(
				$error, 'Error', $page_regex,
				DisplayMessage::MESSAGE_ERROR,
				DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
			));
		}

		// Redirect to avoid form resubmission
		return LogicResult::redirect('/admin/admin_scheduled_tasks');
	}

	// Discover available tasks
	$discovered_tasks = _discover_tasks();

	// Load active tasks from DB
	$active_tasks_multi = new MultiScheduledTask(
		array('deleted' => false),
		array('sct_name' => 'ASC')
	);
	$active_tasks_multi->load();

	// Build list of active task classes for cross-referencing
	$active_task_classes = array();
	$active_tasks = array();
	foreach ($active_tasks_multi as $task) {
		$active_task_classes[$task->get('sct_task_class')] = $task;
		$active_tasks[] = $task;
	}

	// Build available (not yet activated) tasks
	$available_tasks = array();
	foreach ($discovered_tasks as $class_name => $task_info) {
		if (!isset($active_task_classes[$class_name])) {
			$available_tasks[$class_name] = $task_info;
		}
	}

	// Check cron status
	$settings = Globalvars::get_instance();
	$last_cron_run = $settings->get_setting('scheduled_tasks_last_cron_run');
	$cron_is_active = false;
	if ($last_cron_run) {
		$last_run_dt = new DateTime($last_cron_run, new DateTimeZone('UTC'));
		$now_dt = new DateTime('now', new DateTimeZone('UTC'));
		$diff_seconds = $now_dt->getTimestamp() - $last_run_dt->getTimestamp();
		$cron_is_active = $diff_seconds < 1800; // 30 minutes
	}

	// Load mailing lists for config field dropdowns
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
	$mailing_lists = new MultiMailingList(array('deleted' => false), array('mlt_name' => 'ASC'));
	$mailing_lists->load();

	// Get site timezone for display
	$site_timezone = $settings->get_setting('default_timezone');
	if (!$site_timezone) {
		$site_timezone = 'America/New_York';
	}

	// Get session messages
	$display_messages = $session->get_messages('/admin/admin_scheduled_tasks');

	return LogicResult::render(array(
		'session' => $session,
		'active_tasks' => $active_tasks,
		'available_tasks' => $available_tasks,
		'discovered_tasks' => $discovered_tasks,
		'cron_is_active' => $cron_is_active,
		'last_cron_run' => $last_cron_run,
		'mailing_lists' => $mailing_lists,
		'site_timezone' => $site_timezone,
		'display_messages' => $display_messages,
	));
}

/**
 * Discover tasks by scanning /tasks/ and plugin task directories.
 *
 * @return array  Keyed by class name, value is array with json data and source path
 */
function _discover_tasks() {
	$tasks = array();

	// Scan /tasks/
	$core_tasks_dir = PathHelper::getIncludePath('tasks');
	if (is_dir($core_tasks_dir)) {
		$json_files = glob($core_tasks_dir . '/*.json');
		foreach ($json_files as $json_file) {
			$class_name = basename($json_file, '.json');
			$php_file = dirname($json_file) . '/' . $class_name . '.php';
			if (file_exists($php_file)) {
				$json_data = json_decode(file_get_contents($json_file), true);
				if ($json_data) {
					$tasks[$class_name] = array(
						'json' => $json_data,
						'source' => 'core',
						'json_path' => $json_file,
						'php_path' => $php_file,
					);
				}
			}
		}
	}

	// Scan plugin task directories
	$plugins_dir = PathHelper::getIncludePath('plugins');
	if (is_dir($plugins_dir)) {
		$plugin_task_jsons = glob($plugins_dir . '/*/tasks/*.json');
		foreach ($plugin_task_jsons as $json_file) {
			$class_name = basename($json_file, '.json');
			$php_file = dirname($json_file) . '/' . $class_name . '.php';
			if (file_exists($php_file)) {
				$json_data = json_decode(file_get_contents($json_file), true);
				if ($json_data) {
					// Extract plugin name from path
					$path_parts = explode('/', dirname($json_file));
					$plugin_name = $path_parts[count($path_parts) - 2];
					$tasks[$class_name] = array(
						'json' => $json_data,
						'source' => 'plugin:' . $plugin_name,
						'json_path' => $json_file,
						'php_path' => $php_file,
					);
				}
			}
		}
	}

	return $tasks;
}

/**
 * Handle task activation.
 */
function _handle_activate($post_vars) {
	$class_name = $post_vars['task_class'] ?? null;
	if (!$class_name) {
		return array('error' => 'No task class specified.');
	}

	// Discover to get JSON config
	$discovered = _discover_tasks();
	if (!isset($discovered[$class_name])) {
		return array('error' => 'Task class not found on disk.');
	}

	$json_data = $discovered[$class_name]['json'];

	// Check if already activated
	$existing = new MultiScheduledTask(array('task_class' => $class_name, 'deleted' => false));
	if ($existing->count_all() > 0) {
		return array('error' => 'Task is already activated.');
	}

	// Create the DB row
	$task = new ScheduledTask(null);
	$task->set('sct_name', $json_data['name'] ?? $class_name);
	$task->set('sct_task_class', $class_name);
	$task->set('sct_is_active', true);

	// Set defaults from JSON
	if (isset($json_data['default_frequency'])) {
		$task->set('sct_frequency', $json_data['default_frequency']);
	}
	if (isset($json_data['default_day_of_week'])) {
		$task->set('sct_schedule_day_of_week', $json_data['default_day_of_week']);
	}
	if (isset($json_data['default_time'])) {
		$task->set('sct_schedule_time', $json_data['default_time']);
	}

	// Set config from POST if provided
	$config = array();
	$config_fields = $json_data['config_fields'] ?? array();
	foreach ($config_fields as $field_name => $field_def) {
		if (isset($post_vars['config_' . $field_name])) {
			$config[$field_name] = $post_vars['config_' . $field_name];
		}
	}
	if (!empty($config)) {
		$task->set('sct_task_config', json_encode($config));
	}

	$task->save();

	return array('message' => 'Task "' . $task->get('sct_name') . '" activated.');
}

/**
 * Handle "Run Now" action.
 */
function _handle_run_now($task_id) {
	require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

	$task = new ScheduledTask($task_id, true);
	$task_class = $task->get('sct_task_class');
	$task_file = $task->resolve_task_file();

	if (!$task_file) {
		return array('error' => 'Could not resolve class file for ' . $task_class);
	}

	try {
		require_once($task_file);

		if (!class_exists($task_class)) {
			return array('error' => 'Class ' . $task_class . ' not found.');
		}

		$task_instance = new $task_class();

		if (!($task_instance instanceof ScheduledTaskInterface)) {
			return array('error' => 'Class does not implement ScheduledTaskInterface.');
		}

		$config = $task->get_task_config();
		$result = $task_instance->run($config);

		// Parse result (supports string or array with status+message)
		if (is_array($result)) {
			$status = $result['status'] ?? 'error';
			$run_message = $result['message'] ?? null;
		} else {
			$status = $result;
			$run_message = null;
		}

		$task->set('sct_last_run_time', 'now()');
		$task->set('sct_last_run_status', $status);
		$task->set('sct_last_run_message', $run_message);
		$task->save();

		$display = 'Task "' . $task->get('sct_name') . '" ran with result: ' . $status;
		if ($run_message) {
			$display .= ' — ' . $run_message;
		}
		return array('message' => $display);
	} catch (Exception $e) {
		$task->set('sct_last_run_time', 'now()');
		$task->set('sct_last_run_status', 'error');
		$task->set('sct_last_run_message', substr($e->getMessage(), 0, 500));
		$task->save();
		return array('error' => 'Task error: ' . $e->getMessage());
	}
}

/**
 * Handle saving schedule and config changes.
 */
function _handle_save($post_vars) {
	$task = new ScheduledTask($post_vars['sct_scheduled_task_id'], true);

	// Update schedule
	if (isset($post_vars['sct_frequency'])) {
		$valid_frequencies = array('every_run', 'hourly', 'daily', 'weekly');
		$freq = $post_vars['sct_frequency'];
		if (in_array($freq, $valid_frequencies)) {
			$task->set('sct_frequency', $freq);
		}
	}

	if (isset($post_vars['sct_schedule_day_of_week'])) {
		$dow = $post_vars['sct_schedule_day_of_week'];
		$task->set('sct_schedule_day_of_week', ($dow === '' || $dow === 'daily') ? null : (int)$dow);
	}

	if (isset($post_vars['sct_schedule_time'])) {
		$task->set('sct_schedule_time', $post_vars['sct_schedule_time']);
	}

	// Update task config
	$task_class = $task->get('sct_task_class');
	$discovered = _discover_tasks();
	$config_fields = array();
	if (isset($discovered[$task_class])) {
		$config_fields = $discovered[$task_class]['json']['config_fields'] ?? array();
	}

	$config = $task->get_task_config();
	foreach ($config_fields as $field_name => $field_def) {
		if (isset($post_vars['config_' . $field_name])) {
			$config[$field_name] = $post_vars['config_' . $field_name];
		}
	}
	$task->set('sct_task_config', json_encode($config));

	$task->save();

	return array('message' => 'Task "' . $task->get('sct_name') . '" updated.');
}
