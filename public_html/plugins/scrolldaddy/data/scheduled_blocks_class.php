<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/includes/ScrollDaddyHelper.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/scheduled_block_filters_class.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/scheduled_block_services_class.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/scheduled_block_rules_class.php'));

class SdScheduledBlockException extends SystemBaseException {}

class SdScheduledBlock extends SystemBase {

	public static $prefix = 'sdb';
	public static $tablename = 'sdb_scheduled_blocks';
	public static $pkey_column = 'sdb_scheduled_block_id';

	public static $field_specifications = array(
	    'sdb_scheduled_block_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sdb_sdd_device_id' => array('type'=>'int4'),
	    'sdb_name' => array('type'=>'varchar(64)'),
	    'sdb_is_always_on' => array('type'=>'bool', 'default'=>false),
	    'sdb_schedule_start' => array('type'=>'varchar(5)'),
	    'sdb_schedule_end' => array('type'=>'varchar(5)'),
	    'sdb_schedule_days' => array('type'=>'varchar(128)'),
	    'sdb_schedule_timezone' => array('type'=>'varchar(64)'),
	    'sdb_is_active' => array('type'=>'bool', 'default'=>true),
	    'sdb_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'sdb_delete_time' => array('type'=>'timestamp(6)'),
	);

	function prepare() {}

	function authenticate_write($data) {
		// Load the device to verify ownership
		require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
		$device = new SdDevice($this->get('sdb_sdd_device_id'), TRUE);
		$device->authenticate_write($data);
	}

	function authenticate_read($data) {
		require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
		$device = new SdDevice($this->get('sdb_sdd_device_id'), TRUE);
		$device->authenticate_read($data);
	}

	/**
	 * Update filter rules for this scheduled block.
	 * Each filter can be: not present (no change), action=0 (block), action=1 (allow).
	 *
	 * @param array $newvalues POST data with keys like 'rule_filter_key' => '0'|'1'|''
	 * @return int Number of changes made
	 */
	function update_filters($newvalues) {
		$numchanges = 0;
		$all_filters = ScrollDaddyHelper::$filters;

		// Load existing filter rules for this block
		$existing = new MultiSdScheduledBlockFilter(
			array('block_id' => $this->key)
		);
		$existing->load();
		$cached = array();
		foreach ($existing as $item) {
			$cached[$item->get('sbf_filter_key')] = $item;
		}

		foreach ($all_filters as $filter_key => $filter_desc) {
			$post_key = 'rule_' . $filter_key;
			$has_value = isset($newvalues[$post_key]) && $newvalues[$post_key] !== '';

			if ($has_value) {
				$action = (int)$newvalues[$post_key]; // 0=block, 1=allow
				if (isset($cached[$filter_key])) {
					// Update existing if changed
					if ($cached[$filter_key]->get('sbf_action') != $action) {
						$cached[$filter_key]->set('sbf_action', $action);
						$cached[$filter_key]->save();
						$numchanges++;
					}
				} else {
					// Create new
					$new_rule = new SdScheduledBlockFilter(NULL);
					$new_rule->set('sbf_sdb_scheduled_block_id', $this->key);
					$new_rule->set('sbf_filter_key', $filter_key);
					$new_rule->set('sbf_action', $action);
					$new_rule->save();
					$numchanges++;
				}
			} else {
				// No value = remove rule if it exists
				if (isset($cached[$filter_key])) {
					$cached[$filter_key]->permanent_delete();
					$numchanges++;
				}
			}
		}
		return $numchanges;
	}

	/**
	 * Update service rules for this scheduled block.
	 *
	 * @param array $newvalues POST data with keys like 'rule_servicename' => '0'|'1'|''
	 * @return int Number of changes made
	 */
	function update_services($newvalues) {
		$numchanges = 0;

		$all_services = [];
		foreach (ScrollDaddyHelper::$services as $category => $items) {
			$all_services = array_merge($all_services, $items);
		}

		// Load existing service rules for this block
		$existing = new MultiSdScheduledBlockService(
			array('block_id' => $this->key)
		);
		$existing->load();
		$cached = array();
		foreach ($existing as $item) {
			$cached[$item->get('sbs_service_key')] = $item;
		}

		foreach ($all_services as $service_key => $service_desc) {
			$post_key = 'rule_' . $service_key;
			$has_value = isset($newvalues[$post_key]) && $newvalues[$post_key] !== '';

			if ($has_value) {
				$action = (int)$newvalues[$post_key]; // 0=block, 1=allow
				if (isset($cached[$service_key])) {
					if ($cached[$service_key]->get('sbs_action') != $action) {
						$cached[$service_key]->set('sbs_action', $action);
						$cached[$service_key]->save();
						$numchanges++;
					}
				} else {
					$new_rule = new SdScheduledBlockService(NULL);
					$new_rule->set('sbs_sdb_scheduled_block_id', $this->key);
					$new_rule->set('sbs_service_key', $service_key);
					$new_rule->set('sbs_action', $action);
					$new_rule->save();
					$numchanges++;
				}
			} else {
				if (isset($cached[$service_key])) {
					$cached[$service_key]->permanent_delete();
					$numchanges++;
				}
			}
		}
		return $numchanges;
	}

	/**
	 * Check if this scheduled block is currently active based on time and day.
	 * Always-on blocks are active at all times.
	 */
	function is_active_now() {
		if (!$this->get('sdb_is_active')) {
			return false;
		}

		if ($this->get('sdb_is_always_on')) {
			return true;
		}

		$start = $this->get('sdb_schedule_start');
		$end = $this->get('sdb_schedule_end');
		$days_json = $this->get('sdb_schedule_days');
		$tz_string = $this->get('sdb_schedule_timezone');

		if (!$start || !$end || !$days_json || !$tz_string) {
			return false;
		}

		$days = json_decode($days_json, true);
		if (!is_array($days) || empty($days)) {
			return false;
		}

		try {
			$tz = new DateTimeZone($tz_string);
		} catch (Exception $e) {
			return false;
		}

		$now = new DateTime('now', $tz);
		$today = strtolower($now->format('D'));
		$current_time = $now->format('H:i');

		$start_ts = strtotime($start);
		$end_ts = strtotime($end);
		$current_ts = strtotime($current_time);

		if ($end_ts < $start_ts) {
			// Overnight block
			if (in_array($today, $days) && $current_ts >= $start_ts) {
				return true;
			}
			// Check if yesterday was a scheduled day and we're in the continuation
			$yesterday = strtolower($now->modify('-1 day')->format('D'));
			if (in_array($yesterday, $days) && $current_ts < $end_ts) {
				return true;
			}
			return false;
		} else {
			// Normal daytime block
			if (in_array($today, $days) && $current_ts >= $start_ts && $current_ts < $end_ts) {
				return true;
			}
			return false;
		}
	}

	/**
	 * Get a human-readable schedule display string.
	 * e.g. "Mon-Fri, 9:00 PM - 7:00 AM" or "Every day, 9:00 PM - 7:00 AM"
	 */
	function get_schedule_display() {
		$start = $this->get('sdb_schedule_start');
		$end = $this->get('sdb_schedule_end');
		$days_json = $this->get('sdb_schedule_days');

		if (!$start || !$end || !$days_json) {
			return '';
		}

		$days = json_decode($days_json, true);
		if (!is_array($days) || empty($days)) {
			return '';
		}

		// Format times to 12-hour
		$start_display = date('g:i A', strtotime($start));
		$end_display = date('g:i A', strtotime($end));

		// Format days
		$all_days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
		$weekdays = ['mon', 'tue', 'wed', 'thu', 'fri'];

		if (count($days) == 7) {
			$days_display = 'Every day';
		} elseif ($days == $weekdays) {
			$days_display = 'Mon–Fri';
		} else {
			$day_labels = ['mon'=>'Mon','tue'=>'Tue','wed'=>'Wed','thu'=>'Thu','fri'=>'Fri','sat'=>'Sat','sun'=>'Sun'];
			$labels = [];
			foreach ($all_days as $d) {
				if (in_array($d, $days)) {
					$labels[] = $day_labels[$d];
				}
			}
			$days_display = implode(', ', $labels);
		}

		return $days_display . ', ' . $start_display . ' – ' . $end_display;
	}

	/**
	 * Count the number of rules (filter + service rules) in this block.
	 */
	function count_rules() {
		$filter_count = (new MultiSdScheduledBlockFilter(['block_id' => $this->key]))->count_all();
		$service_count = (new MultiSdScheduledBlockService(['block_id' => $this->key]))->count_all();
		return $filter_count + $service_count;
	}

	/**
	 * Get all filter rules for this block as an associative array.
	 * @return array ['filter_key' => action_int, ...]
	 */
	function get_filter_rules() {
		$rules = new MultiSdScheduledBlockFilter(['block_id' => $this->key]);
		$rules->load();
		$result = [];
		foreach ($rules as $rule) {
			$result[$rule->get('sbf_filter_key')] = (int)$rule->get('sbf_action');
		}
		return $result;
	}

	/**
	 * Get all service rules for this block as an associative array.
	 * @return array ['service_key' => action_int, ...]
	 */
	function get_service_rules() {
		$rules = new MultiSdScheduledBlockService(['block_id' => $this->key]);
		$rules->load();
		$result = [];
		foreach ($rules as $rule) {
			$result[$rule->get('sbs_service_key')] = (int)$rule->get('sbs_action');
		}
		return $result;
	}

	/**
	 * Add a custom domain rule to this scheduled block.
	 */
	function add_rule($hostname, $action) {
		$hostname = preg_replace('/^https?:\/\//', '', $hostname);

		$testUrl = "http://$hostname";
		if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
			return false;
		}
		$parsedUrl = parse_url($testUrl);
		if (!isset($parsedUrl['host'])) {
			return false;
		}
		$domainPattern = '/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/';
		if (preg_match($domainPattern, $parsedUrl['host']) !== 1) {
			return false;
		}

		$rule = new SdScheduledBlockRule(NULL);
		$rule->set('sbr_sdb_scheduled_block_id', $this->key);
		$rule->set('sbr_hostname', $hostname);
		$rule->set('sbr_is_active', 1);
		$rule->set('sbr_action', $action);
		$rule->save();
		$rule->load();
		return $rule;
	}

	function delete_rule($rule_id) {
		$rule = new SdScheduledBlockRule($rule_id, TRUE);
		$rule->permanent_delete();
		return true;
	}

	function permanent_delete_all_rules() {
		$rules = new MultiSdScheduledBlockRule(['block_id' => $this->key]);
		$rules->load();
		foreach ($rules as $rule) {
			$rule->permanent_delete();
		}
	}

	function permanent_delete_all_filters() {
		$filters = new MultiSdScheduledBlockFilter(['block_id' => $this->key]);
		$filters->load();
		foreach ($filters as $filter) {
			$filter->permanent_delete();
		}
	}

	function permanent_delete_all_services() {
		$services = new MultiSdScheduledBlockService(['block_id' => $this->key]);
		$services->load();
		foreach ($services as $service) {
			$service->permanent_delete();
		}
	}

	function permanent_delete($debug = false) {
		$this->permanent_delete_all_rules();
		$this->permanent_delete_all_filters();
		$this->permanent_delete_all_services();
		parent::permanent_delete($debug);
		return true;
	}

	/**
	 * Get or create the always-on block for a device.
	 * Every device has exactly one always-on block, created on demand if missing.
	 */
	static function getOrCreateAlwaysOnBlock($device_id) {
		$existing = new MultiSdScheduledBlock(
			array('device_id' => $device_id, 'is_always_on' => true),
			array('sdb_scheduled_block_id' => 'ASC'),
			1
		);
		$existing->load();
		if (count($existing) > 0) {
			return $existing->get(0);
		}

		$block = new SdScheduledBlock(NULL);
		$block->set('sdb_sdd_device_id', $device_id);
		$block->set('sdb_name', 'Always-On Rules');
		$block->set('sdb_is_always_on', true);
		$block->set('sdb_is_active', true);
		$block->save();
		$block->load();
		return $block;
	}

}

class MultiSdScheduledBlock extends SystemMultiBase {
	protected static $model_class = 'SdScheduledBlock';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['device_id'])) {
            $filters['sdb_sdd_device_id'] = [$this->options['device_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['is_active'])) {
            $filters['sdb_is_active'] = $this->options['is_active'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['is_always_on'])) {
            $filters['sdb_is_always_on'] = $this->options['is_always_on'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['sdb_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        } else {
			// Default: exclude deleted
			$filters['sdb_delete_time'] = "IS NULL";
		}

        return $this->_get_resultsv2('sdb_scheduled_blocks', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
