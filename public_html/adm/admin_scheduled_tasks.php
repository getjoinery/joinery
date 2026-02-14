<?php
/**
 * Scheduled Tasks Admin Page
 *
 * Shows active tasks and available (discovered) tasks.
 * Supports activate, deactivate, configure, and run-now actions.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_scheduled_tasks_logic.php'));

$page_vars = process_logic(admin_scheduled_tasks_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'scheduled-tasks',
	'page_title' => 'Scheduled Tasks',
	'readable_title' => 'Scheduled Tasks',
	'breadcrumbs' => array(
		'System' => '/admin/admin_settings',
		'Scheduled Tasks' => '',
	),
	'session' => $session,
));

// Day of week labels
$day_labels = array(
	0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
	4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
);

$day_options = array('' => 'Daily (every day)');
foreach ($day_labels as $num => $label) {
	$day_options[$num] = $label;
}

// Frequency options
$frequency_options = array(
	'every_run' => 'Every 15 minutes (every cron run)',
	'hourly' => 'Hourly',
	'daily' => 'Daily',
	'weekly' => 'Weekly',
);

$frequency_labels = array(
	'every_run' => 'Every 15 min',
	'hourly' => 'Hourly',
	'daily' => 'Daily',
	'weekly' => 'Weekly',
);

// =====================================================
// CRON STATUS WARNING
// =====================================================
if (!$cron_is_active) {
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	echo '<div style="border: 2px solid #856404; padding: 15px; margin-bottom: 20px; background-color: #fff3cd; color: #856404; border-radius: 4px;">';
	echo '<strong>Cron Not Detected</strong> — ';
	if ($last_cron_run) {
		echo 'Last run: ' . htmlspecialchars($last_cron_run) . '. ';
	} else {
		echo 'The cron runner has never executed. ';
	}
	echo 'Add this to the <strong>www-data</strong> user\'s crontab (<code>sudo crontab -e -u www-data</code>):';
	echo '<pre style="background-color: #f8f0d4; padding: 10px; margin-top: 10px; border-radius: 4px; overflow-x: auto;">*/15 * * * * php /var/www/html/' . htmlspecialchars($site_template) . '/public_html/utils/process_scheduled_tasks.php &gt;&gt; /var/www/html/' . htmlspecialchars($site_template) . '/logs/cron_scheduled_tasks.log 2&gt;&amp;1</pre>';
	echo '</div>';
}

// =====================================================
// SUCCESS / ERROR MESSAGES (from session)
// =====================================================
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		$alert_class = 'alert-info';
		if ($msg->display_type == DisplayMessage::MESSAGE_ERROR) {
			$alert_class = 'alert-danger';
		} elseif ($msg->display_type == DisplayMessage::MESSAGE_WARNING) {
			$alert_class = 'alert-warning';
		} elseif ($msg->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
			$alert_class = 'alert-success';
		}
		echo '<div class="alert ' . $alert_class . ' alert-dismissible fade show" role="alert">';
		if ($msg->message_title) {
			echo '<strong>' . htmlspecialchars($msg->message_title) . ':</strong> ';
		}
		echo htmlspecialchars($msg->message);
		echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
		echo '</div>';
	}
	$session->clear_clearable_messages();
}

// =====================================================
// ACTIVE TASKS
// =====================================================
$pageoptions = array('title' => 'Active Tasks');
$page->begin_box($pageoptions);

if (empty($active_tasks)) {
	echo '<p>No tasks are currently activated. Activate a task from the "Available Tasks" section below.</p>';
} else {
	echo '<table class="table table-striped">';
	echo '<thead><tr>';
	echo '<th>Name</th><th>Schedule</th><th>Last Run</th><th>Status</th><th>Active</th><th>Actions</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ($active_tasks as $task) {
		$task_class = $task->get('sct_task_class');
		$is_active = $task->get('sct_is_active');

		// Schedule display
		$frequency = $task->get('sct_frequency') ?: 'daily';
		$dow = $task->get('sct_schedule_day_of_week');
		$time = $task->get('sct_schedule_time');
		$freq_label = $frequency_labels[$frequency] ?? $frequency;

		if ($frequency === 'every_run') {
			$schedule_display = 'Every 15 min';
		} elseif ($frequency === 'hourly') {
			$schedule_display = 'Hourly';
		} elseif ($frequency === 'weekly' && $dow !== null && $dow !== '') {
			$schedule_display = ($day_labels[(int)$dow] ?? 'Day ' . $dow) . ' at ' . $time . ' ' . htmlspecialchars($site_timezone);
		} else {
			$schedule_display = 'Daily at ' . $time . ' ' . htmlspecialchars($site_timezone);
		}

		// Last run display
		$last_run = $task->get('sct_last_run_time');
		$last_run_display = $last_run ? htmlspecialchars($last_run) : '<em>Never</em>';

		// Status badge
		$status = $task->get('sct_last_run_status');
		$status_display = '';
		if ($status === 'success') {
			$status_display = '<span class="badge bg-success" style="background-color: #28a745; color: #fff; padding: 3px 8px; border-radius: 3px;">Success</span>';
		} elseif ($status === 'error') {
			$status_display = '<span class="badge bg-danger" style="background-color: #dc3545; color: #fff; padding: 3px 8px; border-radius: 3px;">Error</span>';
		} elseif ($status === 'skipped') {
			$status_display = '<span class="badge bg-secondary" style="background-color: #6c757d; color: #fff; padding: 3px 8px; border-radius: 3px;">Skipped</span>';
		} else {
			$status_display = '<span style="color: #999;">—</span>';
		}

		// Active toggle
		$active_display = $is_active
			? '<span style="color: #28a745; font-weight: bold;">Active</span>'
			: '<span style="color: #dc3545;">Inactive</span>';

		echo '<tr>';
		echo '<td><strong>' . htmlspecialchars($task->get('sct_name')) . '</strong><br><small class="text-muted">' . htmlspecialchars($task_class) . '</small></td>';
		echo '<td>' . $schedule_display . '</td>';
		echo '<td>' . $last_run_display . '</td>';
		echo '<td>' . $status_display . '</td>';
		echo '<td>' . $active_display . '</td>';
		echo '<td>';

		// Edit button
		echo '<a href="/admin/admin_scheduled_tasks?edit=' . $task->key . '" class="btn btn-sm btn-outline-primary" style="margin-right: 4px;">Edit</a>';

		// Run Now button
		echo '<form method="post" action="/admin/admin_scheduled_tasks" style="display: inline;">';
		echo '<input type="hidden" name="action" value="run_now">';
		echo '<input type="hidden" name="sct_scheduled_task_id" value="' . $task->key . '">';
		echo '<button type="submit" class="btn btn-sm btn-outline-secondary" style="margin-right: 4px;">Run Now</button>';
		echo '</form>';

		// Deactivate/Reactivate button
		if ($is_active) {
			echo '<form method="post" action="/admin/admin_scheduled_tasks" style="display: inline;">';
			echo '<input type="hidden" name="action" value="deactivate">';
			echo '<input type="hidden" name="sct_scheduled_task_id" value="' . $task->key . '">';
			echo '<button type="submit" class="btn btn-sm btn-outline-warning">Deactivate</button>';
			echo '</form>';
		} else {
			echo '<form method="post" action="/admin/admin_scheduled_tasks" style="display: inline;">';
			echo '<input type="hidden" name="action" value="reactivate">';
			echo '<input type="hidden" name="sct_scheduled_task_id" value="' . $task->key . '">';
			echo '<button type="submit" class="btn btn-sm btn-outline-success">Reactivate</button>';
			echo '</form>';
		}

		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

$page->end_box();

// =====================================================
// EDIT FORM (shown when ?edit=ID is present)
// =====================================================
if (isset($_GET['edit'])) {
	$edit_task_id = (int)$_GET['edit'];
	$edit_task = new ScheduledTask($edit_task_id, true);

	if ($edit_task->key) {
		$task_class = $edit_task->get('sct_task_class');
		$task_config = $edit_task->get_task_config();

		// Get config_fields from JSON
		$config_fields = array();
		if (isset($discovered_tasks[$task_class])) {
			$config_fields = $discovered_tasks[$task_class]['json']['config_fields'] ?? array();
		}

		$pageoptions = array('title' => 'Edit Task: ' . htmlspecialchars($edit_task->get('sct_name')));
		$page->begin_box($pageoptions);

		$formwriter = $page->getFormWriter('edit_form');
		echo $formwriter->begin_form('edit_form', 'post', '/admin/admin_scheduled_tasks');

		$formwriter->hiddeninput('action', '', array('value' => 'save'));
		$formwriter->hiddeninput('sct_scheduled_task_id', '', array('value' => $edit_task->key));

		// Schedule fields
		$formwriter->dropinput('sct_frequency', 'Frequency', array(
			'options' => $frequency_options,
			'value' => $edit_task->get('sct_frequency') ?: 'daily',
		));

		$current_dow = $edit_task->get('sct_schedule_day_of_week');
		$formwriter->dropinput('sct_schedule_day_of_week', 'Schedule Day', array(
			'options' => $day_options,
			'value' => ($current_dow !== null && $current_dow !== '') ? $current_dow : '',
			'helptext' => 'Only applies to weekly frequency',
		));

		$formwriter->textinput('sct_schedule_time', 'Schedule Time', array(
			'value' => $edit_task->get('sct_schedule_time'),
			'helptext' => 'Time of day in HH:MM:SS format (' . htmlspecialchars($site_timezone) . '). Only applies to daily and weekly frequencies.',
		));

		// Task-specific config fields
		foreach ($config_fields as $field_name => $field_def) {
			$field_type = $field_def['type'] ?? 'text';
			$field_label = $field_def['label'] ?? $field_name;
			$field_value = $task_config[$field_name] ?? '';

			if ($field_type === 'mailing_list') {
				$ml_options = array();
				foreach ($mailing_lists as $ml) {
					$ml_options[$ml->key] = $ml->get('mlt_name');
				}
				$formwriter->dropinput('config_' . $field_name, $field_label, array(
					'options' => $ml_options,
					'value' => $field_value,
					'empty_option' => '-- Select --',
				));
			} elseif ($field_type === 'number') {
				$formwriter->textinput('config_' . $field_name, $field_label, array(
					'value' => $field_value,
					'validation' => array('number' => true),
				));
			} elseif ($field_type === 'boolean') {
				$formwriter->checkboxinput('config_' . $field_name, $field_label, array(
					'checked' => !empty($field_value),
				));
			} else {
				$formwriter->textinput('config_' . $field_name, $field_label, array(
					'value' => $field_value,
				));
			}
		}

		$formwriter->submitbutton('btn_save', 'Save Changes');
		echo $formwriter->end_form();
		$page->end_box();
	}
}

// =====================================================
// AVAILABLE TASKS (not yet activated)
// =====================================================
$pageoptions = array('title' => 'Available Tasks');
$page->begin_box($pageoptions);

if (empty($available_tasks)) {
	echo '<p>All discovered tasks have been activated, or no task files were found.</p>';
	echo '<p><small>Tasks are discovered from <code>/tasks/</code> and <code>/plugins/*/tasks/</code> directories.</small></p>';
} else {
	echo '<table class="table table-striped">';
	echo '<thead><tr>';
	echo '<th>Name</th><th>Description</th><th>Source</th><th>Action</th>';
	echo '</tr></thead>';
	echo '<tbody>';

	foreach ($available_tasks as $class_name => $task_info) {
		$json = $task_info['json'];
		$source = $task_info['source'];
		$config_fields = $json['config_fields'] ?? array();
		$has_required_config = false;
		foreach ($config_fields as $field_def) {
			if (!empty($field_def['required'])) {
				$has_required_config = true;
				break;
			}
		}

		echo '<tr>';
		echo '<td><strong>' . htmlspecialchars($json['name'] ?? $class_name) . '</strong><br><small class="text-muted">' . htmlspecialchars($class_name) . '</small></td>';
		echo '<td>' . htmlspecialchars($json['description'] ?? '') . '</td>';
		echo '<td>' . htmlspecialchars($source) . '</td>';
		echo '<td>';

		echo '<form method="post" action="/admin/admin_scheduled_tasks">';
		echo '<input type="hidden" name="action" value="activate">';
		echo '<input type="hidden" name="task_class" value="' . htmlspecialchars($class_name) . '">';

		// Show config fields for required fields during activation
		if ($has_required_config) {
			foreach ($config_fields as $field_name => $field_def) {
				if (!empty($field_def['required'])) {
					$field_type = $field_def['type'] ?? 'text';
					$field_label = $field_def['label'] ?? $field_name;

					if ($field_type === 'mailing_list') {
						echo '<label style="font-size: 0.85em; display: block; margin-bottom: 4px;">' . htmlspecialchars($field_label) . ':</label>';
						echo '<select name="config_' . htmlspecialchars($field_name) . '" class="form-control form-control-sm" style="margin-bottom: 8px;">';
						echo '<option value="">-- Select --</option>';
						foreach ($mailing_lists as $ml) {
							echo '<option value="' . $ml->key . '">' . htmlspecialchars($ml->get('mlt_name')) . '</option>';
						}
						echo '</select>';
					} else {
						echo '<label style="font-size: 0.85em; display: block; margin-bottom: 4px;">' . htmlspecialchars($field_label) . ':</label>';
						echo '<input type="text" name="config_' . htmlspecialchars($field_name) . '" class="form-control form-control-sm" style="margin-bottom: 8px;">';
					}
				}
			}
		}

		echo '<button type="submit" class="btn btn-sm btn-primary">Activate</button>';
		echo '</form>';

		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

$page->end_box();

$page->admin_footer();
