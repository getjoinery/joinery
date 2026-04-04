<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/includes/ScrollDaddyHelper.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/filters_class.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/devices_class.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/services_class.php'));
require_once(PathHelper::getIncludePath('plugins/scrolldaddy/data/rules_class.php'));

class SdProfileException extends SystemBaseException {}

class SdProfile extends SystemBase {

	public static $prefix = 'sdp';
	public static $tablename = 'sdp_profiles';
	public static $pkey_column = 'sdp_profile_id';

	public static $field_specifications = array(
	    'sdp_profile_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sdp_usr_user_id' => array('type'=>'int4'),
	    'sdp_is_active' => array('type'=>'bool'),
	    'sdp_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'sdp_delete_time' => array('type'=>'timestamp(6)'),
	    'sdp_schedule_start' => array('type'=>'varchar(5)'),
	    'sdp_schedule_end' => array('type'=>'varchar(5)'),
	    'sdp_schedule_days' => array('type'=>'varchar(128)'),
	    'sdp_schedule_timezone' => array('type'=>'varchar(64)'),
	    'sdp_safesearch' => array('type'=>'bool', 'default'=>false),
	    'sdp_safeyoutube' => array('type'=>'bool', 'default'=>false),
	);

	function prepare() {}

	function authenticate_write($data) {
		if ($this->get('sdp_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}

	function get_device_for_profile(){
		$device = SdDevice::GetByColumn('sdd_sdp_profile_id_primary', $this->key);
		if(!$device){
			$device = SdDevice::GetByColumn('sdd_sdp_profile_id_secondary', $this->key);
		}
		if($device){
			return $device;
		}
		else{
			return false;
		}
	}

	function is_primary_or_secondary(){
		$device = SdDevice::GetByColumn('sdd_sdp_profile_id_primary', $this->key);
		if($device){
			return 'primary';
		}

		$device = SdDevice::GetByColumn('sdd_sdp_profile_id_secondary', $this->key);
		if($device){
			return 'secondary';
		}
		return false;
	}

	function delete_profile_from_device(){
		$device = SdDevice::GetByColumn('sdd_sdp_profile_id_primary', $this->key);
		if($device){
			$device->set('sdd_sdp_profile_id_primary', NULL);
			$device->save();
			return true;
		}

		$device = SdDevice::GetByColumn('sdd_sdp_profile_id_secondary', $this->key);
		if($device){
			$device->set('sdd_sdp_profile_id_secondary', NULL);
			$device->save();
			return true;
		}

		return false;
	}

	static function createProfile($name, $user){
		$profile = new SdProfile(NULL);
		$profile->set('sdp_usr_user_id', $user->key);
		$profile->set('sdp_is_active', true);
		$profile->prepare();
		$profile->save();
		$profile->load();
		return $profile;
	}

	function count_blocks(){
		$filters = new MultiSdFilter(
			array(
				'profile_id' => $this->key,
				'active' => true,
			),
		);
		$num_blocks = $filters->count_all();

		$services = new MultiSdService(
			array(
				'profile_id' => $this->key,
				'active' => true,
			),
		);
		$num_blocks += $services->count_all();

		$rules = new MultiSdRule(
			array(
				'profile_id' => $this->key,
				'rule_action' => 0,
			),
		);
		$num_blocks += $rules->count_all();

		return $num_blocks;
	}

	function add_rule($hostname, $action){
		// Strip http/https scheme
		$hostname = preg_replace('/^https?:\/\//', '', $hostname);

		function isValidUrlWithoutScheme($url) {
			$testUrl = "http://$url";
			if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
				return false;
			}
			$parsedUrl = parse_url($testUrl);
			if (!isset($parsedUrl['host'])) {
				return false;
			}
			$domainPattern = '/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/';
			return preg_match($domainPattern, $parsedUrl['host']) === 1;
		}

		if(!isValidUrlWithoutScheme($hostname)){
			return false;
		}

		$rule = new SdRule(NULL);
		$rule->set('sdr_sdp_profile_id', $this->key);
		$rule->set('sdr_hostname', $hostname);
		$rule->set('sdr_is_active', 1);
		$rule->set('sdr_action', $action);
		$rule->prepare();
		$rule->save();
		$rule->load();
		return $rule;
	}

	function delete_rule($sdr_rule_id){
		$rule = new SdRule($sdr_rule_id, TRUE);
		$rule->permanent_delete();
		return true;
	}

	function permanent_delete_all_rules(){
		$rules = new MultiSdRule(
			array(
				'profile_id' => $this->key,
			),
		);
		$rules->load();
		foreach($rules as $rule){
			$rule->permanent_delete();
		}
	}

	function permanent_delete_all_filters(){
		$filters = new MultiSdFilter(
			array(
				'profile_id' => $this->key,
			),
		);
		$filters->load();
		foreach($filters as $filter){
			$filter->permanent_delete();
		}
		return true;
	}

	function permanent_delete_all_services(){
		$services = new MultiSdService(
			array(
				'profile_id' => $this->key,
			),
		);
		$services->load();
		foreach($services as $service){
			$service->permanent_delete();
		}
		return true;
	}

	function add_or_edit_schedule($device, $post_vars){
		if($post_vars['start_time'] != '' && $post_vars['end_time'] != '' && count($post_vars['days_blocked'])){
			if($post_vars['start_time'] >= $post_vars['end_time']){
				return false;
			}

			// Check if schedule fields have actually changed before saving
			if($this->get('sdp_schedule_start') != $post_vars['start_time']
				|| $this->get('sdp_schedule_end') != $post_vars['end_time']
				|| json_encode($post_vars['days_blocked']) != $this->get('sdp_schedule_days')
				|| $device->get('sdd_timezone') != $this->get('sdp_schedule_timezone'))
			{
				$this->set('sdp_schedule_start', strip_tags($post_vars['start_time']));
				$this->set('sdp_schedule_end', strip_tags($post_vars['end_time']));
				$this->set('sdp_schedule_days', json_encode($post_vars['days_blocked']));
				$this->set('sdp_schedule_timezone', $device->get('sdd_timezone'));
				$this->save();
			}
			return true;
		}
		else{
			// User didn't provide complete schedule data, do nothing
			return false;
		}
	}

	function permanent_delete_schedule(){
		if($this->get('sdp_schedule_start') || $this->get('sdp_schedule_days')){
			$this->set('sdp_schedule_start', NULL);
			$this->set('sdp_schedule_end', NULL);
			$this->set('sdp_schedule_days', NULL);
			$this->set('sdp_schedule_timezone', NULL);
			$this->save();
		}
		return true;
	}

	function permanent_delete($debug = false){
		$this->permanent_delete_schedule();
		$this->permanent_delete_all_rules();
		$this->permanent_delete_all_filters();
		$this->permanent_delete_all_services();
		$this->delete_profile_from_device();
		parent::permanent_delete();
		return true;
	}

	function update_remote_filters($newvalues){
		$numchanges = 0;
		$all_filters = ScrollDaddyHelper::$filters;

		$filters = new MultiSdFilter(
			array(
				'profile_id' => $this->get('sdp_profile_id'),
			),
		);
		$filters->load();
		$cached_filters = array();
		foreach($filters as $filter){
			$cached_filters[$filter->get('sdf_filter_key')] = $filter->get('sdf_is_active');
		}

		foreach($all_filters as $all_filter_key => $all_filter_desc){
			if(isset($newvalues['block_'.$all_filter_key])){
				if(isset($cached_filters[$all_filter_key])){
					if($cached_filters[$all_filter_key] != $newvalues['block_'.$all_filter_key]){
						foreach($filters as $filter){
							if($filter->get('sdf_filter_key') == $all_filter_key){
								$filter->set('sdf_is_active', $newvalues['block_'.$all_filter_key]);
								$filter->prepare();
								$filter->save();
							}
						}
						$numchanges++;
					}
				}
				else{
					$new_cached_filter = new SdFilter(NULL);
					$new_cached_filter->set('sdf_sdp_profile_id', $this->key);
					$new_cached_filter->set('sdf_filter_key', $all_filter_key);
					$new_cached_filter->set('sdf_is_active', $newvalues['block_'.$all_filter_key]);
					$new_cached_filter->prepare();
					$new_cached_filter->save();
					$numchanges++;
				}
			}
			else{
				if(isset($cached_filters[$all_filter_key]) && $cached_filters[$all_filter_key]){
					foreach($filters as $filter){
						if($filter->get('sdf_filter_key') == $all_filter_key){
							$filter->set('sdf_is_active', 0);
							$filter->prepare();
							$filter->save();
						}
					}
					$numchanges++;
				}
			}
		}
		return $numchanges;
	}

	function update_remote_services($newvalues){
		$numchanges = 0;

		$all_services = [];
		foreach (ScrollDaddyHelper::$services as $category => $items) {
			$all_services = array_merge($all_services, $items);
		}

		$services = new MultiSdService(
			array(
				'profile_id' => $this->get('sdp_profile_id'),
			),
		);
		$services->load();
		$cached_services = array();
		foreach($services as $service){
			$cached_services[$service->get('sds_service_key')] = $service->get('sds_is_active');
		}

		foreach($all_services as $all_service_key => $all_service_desc){
			if(isset($newvalues['block_'.$all_service_key])){
				if(isset($cached_services[$all_service_key])){
					if($cached_services[$all_service_key] != $newvalues['block_'.$all_service_key]){
						foreach($services as $service){
							if($service->get('sds_service_key') == $all_service_key){
								$service->set('sds_is_active', $newvalues['block_'.$all_service_key]);
								$service->prepare();
								$service->save();
							}
						}
						$numchanges++;
					}
				}
				else{
					$new_cached_service = new SdService(NULL);
					$new_cached_service->set('sds_sdp_profile_id', $this->key);
					$new_cached_service->set('sds_service_key', $all_service_key);
					$new_cached_service->set('sds_is_active', $newvalues['block_'.$all_service_key]);
					$new_cached_service->prepare();
					$new_cached_service->save();
					$numchanges++;
				}
			}
			else{
				if(isset($cached_services[$all_service_key]) && $cached_services[$all_service_key]){
					foreach($services as $service){
						if($service->get('sds_service_key') == $all_service_key){
							$service->set('sds_is_active', 0);
							$service->prepare();
							$service->save();
						}
					}
					$numchanges++;
				}
			}
		}
		return $numchanges;
	}

}

class MultiSdProfile extends SystemMultiBase {
	protected static $model_class = 'SdProfile';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $sdprofile) {
			$items[$sdprofile->key] = '('.$sdprofile->key.') '.$sdprofile->get('sdp_profile_id');
		}
		if ($include_new) {
			$items['Enter New Below'] = 'new';
		}
		return $items;
	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['sdp_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['sdp_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['deleted'])) {
            $filters['sdp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        return $this->_get_resultsv2('sdp_profiles', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
