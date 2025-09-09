<?php
// PathHelper is already loaded by the time this file is included

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');
PathHelper::requireOnce('plugins/controld/includes/ControlDHelper.php');
PathHelper::requireOnce('plugins/controld/data/ctldfilters_class.php');
PathHelper::requireOnce('plugins/controld/data/ctlddevices_class.php');
PathHelper::requireOnce('plugins/controld/data/ctldservices_class.php');
PathHelper::requireOnce('plugins/controld/data/ctldrules_class.php');

class CtldProfileException extends SystemBaseException {}

class CtldProfile extends SystemBase {

	public static $prefix = 'cdp';
	public static $tablename = 'cdp_ctldprofiles';
	public static $pkey_column = 'cdp_ctldprofile_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'cdp_ctldprofile_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cdp_profile_id' => array('type'=>'varchar(64)'),
	    'cdp_usr_user_id' => array('type'=>'int4'),
	    'cdp_is_active' => array('type'=>'bool'),
	    'cdp_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'cdp_delete_time' => array('type'=>'timestamp(6)'),
	    'cdp_schedule_start' => array('type'=>'varchar(5)'),
	    'cdp_schedule_end' => array('type'=>'varchar(5)'),
	    'cdp_schedule_days' => array('type'=>'varchar(128)'),
	    'cdp_schedule_timezone' => array('type'=>'varchar(64)'),
	    'cdp_schedule_id' => array('type'=>'varchar(64)'),
	);

	public static $field_constraints = array(
		/*'cdp_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	

	function prepare() {
		/*
		if(CtldProfile::GetByColumn('cdp_profile_id', $this->get('cdp_profile_id')) && !$this->key){
			throw new CtldProfileException('That profile id already exists.');
		}
*/		
		
	}	

	function authenticate_write($data) {
		if ($this->get('cdd_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}
	
	function get_device_for_profile(){
		$device = CtldDevice::GetByColumn('cdd_cdp_ctldprofile_id_primary', $this->key);
		if(!$device){
			$device = CtldDevice::GetByColumn('cdd_cdp_ctldprofile_id_secondary', $this->key);
		}
		if($device){
			return $device;
		}
		else{
			return false;
		}
	}
	
	function is_primary_or_secondary(){
		$device = CtldDevice::GetByColumn('cdd_cdp_ctldprofile_id_primary', $this->key);
		if($device){
			return 'primary';
		}
		
		$device = CtldDevice::GetByColumn('cdd_cdp_ctldprofile_id_secondary', $this->key);
		if($device){
			return 'secondary';
		}
		return false;
	}
	
	function delete_profile_from_device(){
		$device = CtldDevice::GetByColumn('cdd_cdp_ctldprofile_id_primary', $this->key);
		if($device){
			$device->set('cdd_profile_id_primary', NULL);	
			$device->set('cdd_cdp_ctldprofile_id_primary', NULL);	
			$device->save();
			return true;
		}
		
		$device = CtldDevice::GetByColumn('cdd_cdp_ctldprofile_id_secondary', $this->key);
		if($device){
			$device->set('cdd_profile_id_secondary', NULL);	
			$device->set('cdd_cdp_ctldprofile_id_secondary', NULL);	
			$device->save();
			return true;
		}		
		
		return false;
		
	}
	
	static function createProfile($name, $user){
			$cd = new ControlDHelper();
			$result = $cd->createProfile($name);
			if(!$result['success']){
				throw new SystemDisplayablePermanentError('Unable to create profile.');
				exit;
			}
			$profile1_key = $result['body']['profiles'][0]['PK'];
			
			//SET THE BLOCK RESPONSE TO AN IP 
			/* [0] => 0.0.0.0 / ::
			[3] => NXDOMAIN
			[5] => REFUSED
			[7] => Custom
			[9] => Branded
			*/
			$result = $cd->modifyProfileOptions($profile1_key, 'b_resp', 1, 3, NULL);
			
			if($result['success']){
				$profile1 = new CtldProfile(NULL);
				$profile1->set('cdp_profile_id', $profile1_key);
				$profile1->set('cdp_usr_user_id', $user->key);
				$profile1->set('cdp_is_active', true);
				$profile1->prepare();
				$profile1->save();
				$profile1->load();	
				return $profile1;
			}
			else{
				throw new SystemDisplayablePermanentError('Unable to create a profile.');
				exit;
			}
	}
	
	function count_blocks(){
		$filters = new MultiCtldFilter(
			array(
				'profile_id' => $this->key,
				'active' => true,
			),
		);
		$num_blocks = $filters->count_all();

		$services = new MultiCtldService(
			array(
				'profile_id' => $this->key,
				'active' => true,
			),
		);
		$num_blocks += $services->count_all();	
		
		$rules = new MultiCtldRule(
			array(
				'profile_id' => $this->key,
				'rule_action' => 0,
			),
		);
		$num_blocks += $rules->count_all();	

		return $num_blocks;
		
	}
	
	function add_rule($hostname, $action){
			//STRIP HTTP, HTTPS
			$hostname = preg_replace('/^https?:\/\//', '', $hostname);
			function isValidUrlWithoutScheme($url) {
				// Add http:// to validate properly
				$testUrl = "http://$url";

				// Validate using FILTER_VALIDATE_URL
				if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
					return false;
				}

				// Extract host and validate domain format
				$parsedUrl = parse_url($testUrl);
				if (!isset($parsedUrl['host'])) {
					return false;
				}

				// Check valid domain pattern (allows subdomains)
				$domainPattern = '/^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/';
				return preg_match($domainPattern, $parsedUrl['host']) === 1;
			}
		
			if(!isValidUrlWithoutScheme($hostname)){
				return false;
			}
		
			$hostnames_array = array();
			$hostnames_array[] = $hostname;
			$cd = new ControlDHelper();
			$result = $cd->createRule($this->get('cdp_profile_id'), 1, $hostnames_array, null, $action);
			if($result['success']){
				$rule = new CtldRule(NULL);
				$rule->set('cdr_cdp_ctldprofile_id', $this->key);
				$rule->set('cdr_rule_hostname', $hostname);
				$rule->set('cdr_is_active', $status);
				$rule->set('cdr_rule_action', $action);
				$rule->prepare();
				$rule->save();
				$rule->load();	
				return $rule;
			}
			else{
				return false;
			}
			
	}
	
	function delete_rule($cdr_ctldrule_id){
		$cd = new ControlDHelper();
		$rule = new CtldRule($cdr_ctldrule_id, TRUE);
		$result = $cd->deleteRule($this->get('cdp_profile_id'), $rule->get('cdr_rule_hostname'));
		if($result['success']){
			$rule->permanent_delete();
			return true;
		}
		else{
			return false;
		}
	}
	
	function permanent_delete_all_rules(){
		$rules = new MultiCtldRule(
				array(
					'profile_id' => $this->key,
				),
			);
		$rules->load();		
		foreach($rules as $rule){
			$result = $this->delete_rule($rule->key);
			if(!$result){
				throw new SystemDisplayablePermanentError('Unable to delete custom rule.');
				exit;
			}
		}			
	}
	
	function permanent_delete_all_filters(){
		$filters = new MultiCtldFilter(
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
		$services = new MultiCtldService(
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
		$user = new User($this->get('cdp_usr_user_id'), TRUE);
		$cd = new ControlDHelper();

		if(!$this->get('cdp_schedule_id')){
			//CREATE A SCHEDULE
			if($post_vars['start_time'] != '' && $post_vars['end_time'] != '' && count($post_vars['days_blocked'])){
				if($post_vars['start_time'] >= $post_vars['end_time']){
					return false;
				}

				$name = $user->key . '-' . $user->get('usr_last_name') .'-'. $this->key;
				$result = $cd->createSchedule($this->get('cdp_profile_id'), $device->get('cdd_device_id'), $name, 1, $post_vars['start_time'], $post_vars['end_time'], $device->get('cdd_timezone'), $post_vars['days_blocked']);
				
				if($result['success']){
					$this->set('cdp_schedule_start', strip_tags($post_vars['start_time']));
					$this->set('cdp_schedule_end', strip_tags($post_vars['end_time']));
					$this->set('cdp_schedule_id', $result['body']['PK']);
					$this->set('cdp_schedule_days', serialize($post_vars['days_blocked']));
					$this->set('cdp_schedule_timezone', $device->get('cdd_timezone'));
					$this->save();
				}
				return true;
			}
			else{
				//USER DIDN'T PUT IN A WHOLE SCHEDULE, DO NOTHING
				return false;
			}
		}
		else{

			if($post_vars['start_time'] != '' && $post_vars['end_time'] != '' && count($post_vars['days_blocked'])){
				if($post_vars['start_time'] >= $post_vars['end_time']){
					return false;
				}
				//EDIT THE SCHEDULE IF NECESSARY
				if($post_vars['start_time'] != $this->get('cdp_schedule_start') || $post_vars['end_time'] != $this->get('cdp_schedule_end') || serialize($post_vars['days_blocked']) != $this->get('cdp_schedule_days') || $device->get('cdd_timezone') != $this->get('cdp_schedule_timezone')) {

						$result = $cd->modifySchedule($this->get('cdp_schedule_id'), 1, $post_vars['start_time'], $post_vars['end_time'], $device->get('cdd_timezone'), $post_vars['days_blocked']);
						
						if($result['success']){
							$this->set('cdp_schedule_start', strip_tags($post_vars['start_time']));
							$this->set('cdp_schedule_end', strip_tags($post_vars['end_time']));
							$this->set('cdp_schedule_days', serialize($post_vars['days_blocked']));
							$this->set('cdp_schedule_timezone', $device->get('cdd_timezone'));
							$this->save();	
						}							
					}
				return true;
			}
			else{
				//USER DIDN'T PUT IN A WHOLE SCHEDULE, DO NOTHING
				return false;
			}			
		}
		
	}

	function permanent_delete_schedule(){
		$cd = new ControlDHelper();
		//DELETE THE SCHEDULE IF PRESENT
		if($this->get('cdp_schedule_id')){

			$result = $cd->deleteSchedule($this->get('cdp_schedule_id'));
			if(!$result['success']){
				throw new SystemDisplayablePermanentError('Unable to delete schedule.');
				exit;
			}
			$this->set('cdp_schedule_id', NULL);
			$this->set('cdp_schedule_start', NULL);
			$this->set('cdp_schedule_end', NULL);
			$this->set('cdp_schedule_days', NULL);
			$this->set('cdp_schedule_timezone', NULL);
			$this->save();
		}	

		return true;		
	}
	
	function permanent_delete($debug = false){
		$cd = new ControlDHelper();
		//DELETE THE SCHEDULE IF PRESENT
		$this->permanent_delete_schedule();
		
		//DELETE THE CUSTOM RULES
		$this->permanent_delete_all_rules();	

		$result = $cd->deleteProfile($this->get('cdp_profile_id'));
		if(!$result['success']){
			throw new SystemDisplayablePermanentError('Unable to delete profile.');
			exit;
		}		

		$this->permanent_delete_all_filters();
		
		$this->permanent_delete_all_services();
		
		$this->delete_profile_from_device();

		parent::permanent_delete();	
		return true;
	}
	
	function update_remote_filters($newvalues){
		$numchanges = 0;
		$cd = new ControlDHelper();
		$all_filters = ControlDHelper::$filters;
		
		//FIRST WE DO CACHED FILTERS
		$filters = new MultiCtldFilter(
			array(
				'profile_id' => $this->get('cdp_ctldprofile_id'),
			),
		);
		//$num_filters = $filters->count_all();
		$filters->load();
		$cached_filters = array();
		foreach($filters as $filter){
			$cached_filters[$filter->get('cdf_filter_pk')] = $filter->get('cdf_is_active');
		}
/*
$result = $cd->modifyProfileFilter('689209jfkncn', 'ai_malware', 1);
require_once($_SERVER['DOCUMENT_ROOT'].'/plugins/controld/includes/ControlDHelper.php');
	$cd = new ControlDHelper();
	print_r($cached_filters);
	print_r( $cd->listNativeFilters('689209jfkncn'));
	
	exit;

//$result = $cd->modifyProfileFilter('689209jfkncn', 'malware', 1);
exit;
*/
		foreach($all_filters as $all_filter_key=>$all_filter_desc){
			if(isset($newvalues['block_'.$all_filter_key])){
				//echo 'Found block_'.$all_filter_key."<br>\n";
				//FORM VALUE WAS SUBMITTED
				if(isset($cached_filters[$all_filter_key])){
					//CACHED FILTER EXISTS
					//echo 'Cached filter '.$all_filter_key."<br>\n";
					if($cached_filters[$all_filter_key] != $newvalues['block_'.$all_filter_key]){
						//CHANGED, UPDATE REMOTE AND LOCAL
						//echo 'Changed, update remote and local'."<br>\n";
						$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, $newvalues['block_'.$all_filter_key]);
						if($result['success']){
							foreach($filters as $filter){
								if($filter->get('cdf_filter_pk') == $all_filter_key){
									$filter->set('cdf_is_active',$newvalues['block_'.$all_filter_key]);
									$filter->prepare();
									$filter->save();								
								}
							}
						}
						$numchanges++;
					}
					else{
						//NO NEED TO DO ANYTHING
						//echo 'Skipping'."<br>\n";
					}
				}
				else{
					//CACHED FILTER DOES NOT EXIST, UPDATE REMOTE FIRST AND THEN ADD LOCALLY
					//echo 'Cache does not exist '.$all_filter_key.', update remote then local'."<br>\n";
					$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, $newvalues['block_'.$all_filter_key]);
					if($result['success']){
						$new_cached_filter = new CtldFilter(NULL);
						$new_cached_filter->set('cdf_cdp_ctldprofile_id',$this->key);
						$new_cached_filter->set('cdf_filter_pk',$all_filter_key);
						$new_cached_filter->set('cdf_is_active',$newvalues['block_'.$all_filter_key]);
						$new_cached_filter->prepare();
						$new_cached_filter->save();
						$numchanges++;
					}
				}
			}
			else{
				//POST VALUE WAS NOT SUBMITTED. IT IS "OFF"
				if(isset($cached_filters[$all_filter_key])){
					//CACHED FILTER EXISTS
					if($cached_filters[$all_filter_key]){
						//echo 'Cached filter exists '.$all_filter_key."<br>\n";
						//CACHED IS NOT ZERO, SO UPDATE CACHE AND UPDATE REMOTE
						//echo 'Update local and remote'."<br>\n";
						$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, 0);
						if($result['success']){
							foreach($filters as $filter){
								if($filter->get('cdf_filter_pk') == $all_filter_key){
									$filter->set('cdf_is_active',0);
									$filter->prepare();
									$filter->save();								
								}
							}
						}
						$numchanges++;						
						
					}
					else{
						//NO NEED TO DO ANYTHING
						//echo 'Skipping'."<br>\n";
					}
				}
			}

		}
		return $numchanges;
		
	}

	function update_remote_services($newvalues){
		$numchanges = 0;
		$cd = new ControlDHelper();
		
		$all_services = [];
		foreach (ControlDHelper::$services as $category => $items) {
			$all_services = array_merge($all_services, $items);
		}		

		//FIRST WE DO CACHED FILTERS
		$services = new MultiCtldService(
			array(
				'profile_id' => $this->get('cdp_ctldprofile_id'),
			),
		);
		//$num_services = $services->count_all();
		$services->load();
		$cached_services = array();
		foreach($services as $service){
			$cached_services[$service->get('cds_service_pk')] = $service->get('cds_is_active');
		}

		foreach($all_services as $all_service_key=>$all_service_desc){
			if(isset($newvalues['block_'.$all_service_key])){
				
				//FORM VALUE WAS SUBMITTED
				if(isset($cached_services[$all_service_key])){
					//CACHED FILTER EXISTS
					if($cached_services[$all_service_key] != $newvalues['block_'.$all_service_key]){
						//CHANGED, UPDATE REMOTE AND LOCAL
						$result = $cd->modifyService($this->get('cdp_profile_id'), $all_service_key, $newvalues['block_'.$all_service_key]);
						if($result['success']){
							foreach($services as $service){
								if($service->get('cds_service_pk') == $all_service_key){
									$service->set('cds_is_active',$newvalues['block_'.$all_service_key]);
									$service->prepare();
									$service->save();								
								}
							}
							$numchanges++;
						}
					}
					else{
						//NO NEED TO DO ANYTHING
					}
				}
				else{
					//CACHED FILTER DOES NOT EXIST, UPDATE REMOTE FIRST AND THEN ADD LOCALLY
					$result = $cd->modifyService($this->get('cdp_profile_id'), $all_service_key, $newvalues['block_'.$all_service_key]);
					if($result['success']){
						$new_cached_service = new CtldService(NULL);
						$new_cached_service->set('cds_cdp_ctldprofile_id',$this->key);
						$new_cached_service->set('cds_service_pk',$all_service_key);
						$new_cached_service->set('cds_is_active',$newvalues['block_'.$all_service_key]);
						$new_cached_service->prepare();
						$new_cached_service->save();
						$numchanges++;
					}
				}
			}
			else{
				//POST VALUE WAS NOT SUBMITTED. IT IS "OFF"
				if(isset($cached_services[$all_service_key])){
					//CACHED FILTER EXISTS
					if($cached_services[$all_service_key]){
						//CACHED IS NOT ZERO, SO UPDATE CACHE AND UPDATE REMOTE
						$result = $cd->modifyService($this->get('cdp_profile_id'), $all_service_key, 0);
						if($result['success']){
							foreach($services as $service){
								if($service->get('cds_service_pk') == $all_service_key){
									$service->set('cds_is_active',0);
									$service->prepare();
									$service->save();								
								}
							}
							$numchanges++;						
						}
					}
					else{
						//NO NEED TO DO ANYTHING
					}
				}
			}

		}
		return $numchanges;
		
	}
	
}

class MultiCtldProfile extends SystemMultiBase {
	protected static $model_class = 'CtldProfile';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldprofile) {
			$items['('.$ctldprofile->key.') '.$ctldprofile->get('cdp_ctldprofile')] = $ctldprofile->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['user_id'])) {
            $filters['cdp_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['profile_id_primary'])) {
            $filters['cdp_profile_id_primary'] = [$this->options['profile_id_primary'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['profile_id_secondary'])) {
            $filters['cdp_profile_id_secondary'] = [$this->options['profile_id_secondary'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cdp_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }
        
        if (isset($this->options['deleted'])) {
            $filters['cdp_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('cdp_ctldprofiles', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
