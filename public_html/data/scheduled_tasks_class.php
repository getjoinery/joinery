<?php
/**
 * ScheduledTask and MultiScheduledTask
 *
 * Data model for the scheduled tasks system. Rows are created when
 * an admin activates a discovered task, not via migrations.
 *
 * @version 1.3
 */

require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class ScheduledTaskException extends SystemBaseException {}

class ScheduledTask extends SystemBase {
	public static $prefix = 'sct';
	public static $tablename = 'sct_scheduled_tasks';
	public static $pkey_column = 'sct_scheduled_task_id';

	public static $field_specifications = array(
		'sct_scheduled_task_id'    => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
		'sct_name'                 => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'sct_task_class'           => array('type'=>'varchar(255)', 'is_nullable'=>false),
		'sct_is_active'            => array('type'=>'bool', 'is_nullable'=>false, 'default'=>'true'),
		'sct_frequency'            => array('type'=>'varchar(20)', 'is_nullable'=>false, 'default'=>"'daily'"),
		'sct_schedule_day_of_week' => array('type'=>'int4', 'is_nullable'=>true),
		'sct_schedule_time'        => array('type'=>'time', 'is_nullable'=>false, 'default'=>"'09:00:00'"),
		'sct_task_config'          => array('type'=>'jsonb', 'is_nullable'=>true),
		'sct_last_run_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'sct_last_run_status'      => array('type'=>'varchar(50)', 'is_nullable'=>true),
		'sct_last_run_message'     => array('type'=>'varchar(500)', 'is_nullable'=>true),
		'sct_create_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true, 'default'=>'now()'),
		'sct_delete_time'          => array('type'=>'timestamp(6)', 'is_nullable'=>true),
		'sct_plugin_name'          => array('type'=>'varchar(100)', 'is_nullable'=>true),
	);

	/**
	 * Check whether this task is due to run.
	 *
	 * Behavior depends on sct_frequency:
	 * - every_run: Always due (runs every cron invocation)
	 * - hourly: Due if not already run in the current hour
	 * - daily: Due if past schedule time and not already run today
	 * - weekly: Due if correct day of week, past schedule time, not run today
	 *
	 * @return bool
	 */
	public function is_due() {
		// Must be active
		if (!$this->get('sct_is_active')) {
			return false;
		}

		$frequency = $this->get('sct_frequency') ?: 'daily';

		// every_run: always due
		if ($frequency === 'every_run') {
			return true;
		}

		// Get site timezone
		$settings = Globalvars::get_instance();
		$site_tz_string = $settings->get_setting('default_timezone');
		if (!$site_tz_string) {
			$site_tz_string = 'America/New_York';
		}
		$site_tz = new DateTimeZone($site_tz_string);
		$now = new DateTime('now', $site_tz);

		$last_run = $this->get('sct_last_run_time');
		$last_run_dt = null;
		if ($last_run) {
			$last_run_dt = new DateTime($last_run);
			$last_run_dt->setTimezone($site_tz);
		}

		// hourly: not already run in the current hour
		if ($frequency === 'hourly') {
			if ($last_run_dt && $last_run_dt->format('Y-m-d H') === $now->format('Y-m-d H')) {
				return false;
			}
			return true;
		}

		// weekly: check day of week
		if ($frequency === 'weekly') {
			$day_of_week = $this->get('sct_schedule_day_of_week');
			if ($day_of_week !== null && $day_of_week !== '') {
				$today_dow = (int)$now->format('w'); // 0=Sunday
				if ($today_dow !== (int)$day_of_week) {
					return false;
				}
			}
		}

		// daily + weekly: check if past schedule time today
		$schedule_time = $this->get('sct_schedule_time');
		if ($schedule_time) {
			$schedule_today = new DateTime($now->format('Y-m-d') . ' ' . $schedule_time, $site_tz);
			if ($now < $schedule_today) {
				return false;
			}
		}

		// daily + weekly: check if already run today
		if ($last_run_dt && $last_run_dt->format('Y-m-d') === $now->format('Y-m-d')) {
			return false;
		}

		return true;
	}

	/**
	 * Calculate the next scheduled run time for this task.
	 *
	 * Uses the same scheduling logic as is_due() to determine when the
	 * task will next be eligible to run.
	 *
	 * @return DateTime|null  Next run time in site timezone, or null for every_run tasks
	 */
	public function get_next_run_time() {
		$frequency = $this->get('sct_frequency') ?: 'daily';

		// every_run tasks don't have a meaningful "next run"
		if ($frequency === 'every_run') {
			return null;
		}

		// Get site timezone
		$settings = Globalvars::get_instance();
		$site_tz_string = $settings->get_setting('default_timezone') ?: 'America/New_York';
		$site_tz = new DateTimeZone($site_tz_string);
		$now = new DateTime('now', $site_tz);

		$schedule_time = $this->get('sct_schedule_time') ?: '09:00:00';
		$last_run = $this->get('sct_last_run_time');
		$last_run_dt = null;
		if ($last_run) {
			$last_run_dt = new DateTime($last_run);
			$last_run_dt->setTimezone($site_tz);
		}

		if ($frequency === 'hourly') {
			$current_hour = new DateTime($now->format('Y-m-d H:00:00'), $site_tz);
			if ($last_run_dt && $last_run_dt->format('Y-m-d H') === $now->format('Y-m-d H')) {
				// Already ran this hour, next is start of next hour
				$next = clone $current_hour;
				$next->modify('+1 hour');
				return $next;
			}
			// Due this hour
			return $current_hour;
		}

		if ($frequency === 'daily') {
			$today_at = new DateTime($now->format('Y-m-d') . ' ' . $schedule_time, $site_tz);
			if ($last_run_dt && $last_run_dt->format('Y-m-d') === $now->format('Y-m-d')) {
				// Already ran today, next is tomorrow
				$next = clone $today_at;
				$next->modify('+1 day');
				return $next;
			}
			// Due today (may be in the past if overdue, or future if not yet time)
			return $today_at;
		}

		if ($frequency === 'weekly') {
			$dow = $this->get('sct_schedule_day_of_week');

			if ($dow === null || $dow === '') {
				// No specific day set — behave like daily
				$today_at = new DateTime($now->format('Y-m-d') . ' ' . $schedule_time, $site_tz);
				if ($last_run_dt && $last_run_dt->format('Y-m-d') === $now->format('Y-m-d')) {
					$next = clone $today_at;
					$next->modify('+1 day');
					return $next;
				}
				return $today_at;
			}

			$target_dow = (int)$dow;
			$today_dow = (int)$now->format('w'); // 0=Sunday

			if ($today_dow === $target_dow) {
				// Today is the scheduled day
				$today_at = new DateTime($now->format('Y-m-d') . ' ' . $schedule_time, $site_tz);
				if ($last_run_dt && $last_run_dt->format('Y-m-d') === $now->format('Y-m-d')) {
					// Already ran today, next is in 7 days
					$next = clone $today_at;
					$next->modify('+7 days');
					return $next;
				}
				return $today_at;
			}

			// Calculate days until next occurrence of target day
			$days_until = ($target_dow - $today_dow + 7) % 7;
			$next = new DateTime($now->format('Y-m-d') . ' ' . $schedule_time, $site_tz);
			$next->modify('+' . $days_until . ' days');
			return $next;
		}

		return null;
	}

	/**
	 * Resolve the PHP class file for this task.
	 * Searches /tasks/ then plugin task directories.
	 *
	 * @return string|null  Full file path, or null if not found
	 */
	public function resolve_task_file() {
		$class_name = $this->get('sct_task_class');
		if (!$class_name) {
			return null;
		}

		// Check /tasks/ first
		$core_path = PathHelper::getIncludePath('tasks/' . $class_name . '.php');
		if (file_exists($core_path)) {
			return $core_path;
		}

		// Check /plugins/*/tasks/
		$plugins_dir = PathHelper::getIncludePath('plugins');
		if (is_dir($plugins_dir)) {
			$plugin_dirs = glob($plugins_dir . '/*/tasks/' . $class_name . '.php');
			if (!empty($plugin_dirs)) {
				return $plugin_dirs[0];
			}
		}

		return null;
	}

	/**
	 * Get the task config as an associative array.
	 *
	 * @return array
	 */
	public function get_task_config() {
		$config = $this->get('sct_task_config');
		if ($config && is_string($config)) {
			$decoded = json_decode($config, true);
			return is_array($decoded) ? $decoded : array();
		}
		if (is_array($config)) {
			return $config;
		}
		return array();
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}
}

class MultiScheduledTask extends SystemMultiBase {
	protected static $model_class = 'ScheduledTask';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['active'])) {
			$filters['sct_is_active'] = $this->options['active'] ? '= true' : '= false';
		}

		if (isset($this->options['deleted'])) {
			$filters['sct_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['task_class'])) {
			$filters['sct_task_class'] = [$this->options['task_class'], PDO::PARAM_STR];
		}

		if (isset($this->options['plugin_name'])) {
			$filters['sct_plugin_name'] = [$this->options['plugin_name'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('sct_scheduled_tasks', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
