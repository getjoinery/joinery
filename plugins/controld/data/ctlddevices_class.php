<?php
// PathHelper is already loaded by the time this file is included

require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('plugins/controld/data/ctldprofiles_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldfilters_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldservices_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldrules_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctlddevice_backups_class.php'));

class CtldDeviceException extends SystemBaseException {}

class CtldDevice extends SystemBase {

	public static $prefix = 'cdd';
	public static $tablename = 'cdd_ctlddevices';
	public static $pkey_column = 'cdd_ctlddevice_id';
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
	    'cdd_ctlddevice_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cdd_device_id' => array('type'=>'varchar(64)'),
	    'cdd_device_name' => array('type'=>'varchar(64)'),
	    'cdd_device_type' => array('type'=>'varchar(32)'),
	    'cdd_profile_id_primary' => array('type'=>'varchar(64)'),
	    'cdd_profile_id_secondary' => array('type'=>'varchar(64)'),
	    'cdd_cdp_ctldprofile_id_primary' => array('type'=>'int4'),
	    'cdd_cdp_ctldprofile_id_secondary' => array('type'=>'int4'),
	    'cdd_usr_user_id' => array('type'=>'int4'),
	    'cdd_is_active' => array('type'=>'bool'),
	    'cdd_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'cdd_delete_time' => array('type'=>'timestamp(6)'),
	    'cdd_controld_resolver' => array('type'=>'varchar(128)'),
	    'cdd_deactivation_pin' => array('type'=>'varchar(10)'),
	    'cdd_timezone' => array('type'=>'varchar(64)'),
	    'cdd_allow_device_edits' => array('type'=>'int4'),
	    'cdd_activate_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array(
		/*'cdd_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	

	function prepare() {
		/*
		if(CtldDevice::GetByColumn('cdd_device_id', $this->get('cdd_device_id')) && !$this->key){
			throw new CtldDeviceException('That profile id already exists.');
		}	
*/		
		
	}	
	
	function authenticate_read($data) {
		if ($this->get('cdd_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to see this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
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

	static function createDevice($device, $profile1, $profile2, $post_vars){
			$cd = new ControlDHelper();
			$user = new User($profile1->get('cdp_usr_user_id'), TRUE);

			$deactivation_pin = rand(100000, 999999);
			
			//CREATE THE DEVICE
			$device_name = 'user'.$user->key . '-' . trim(preg_replace("/[^a-zA-Z0-9\s'-]/", "", $post_vars['device_name']));
			$device_type = trim(preg_replace("/[^a-zA-Z0-9\s'-]/", "", $post_vars['device_type']));;
			$data = array(
				'name' => $device_name,
				'icon' => $post_vars['device_type'],
				'profile_id' => $profile1->get('cdp_profile_id'),
				'stats' => 0,
				'desc' => 'User '.$user->key . '-' . $user->get('usr_last_name'),
				'deactivation_pin' => $deactivation_pin,
				
			);

			$result = $cd->createDevice($data);
			$success = $result['success'];			
			
			if($success){
			
				//CREATE A NEW DEVICE LOCALLY
				$device->set('cdd_timezone',strip_tags($post_vars['cdd_timezone']));
				$device->set('cdd_usr_user_id', $user->key);
				$device->set('cdd_is_active', false);
				$device->set('cdd_allow_device_edits', $post_vars['cdd_allow_device_edits']);
				$device->set('cdd_deactivation_pin', $deactivation_pin);
				$device->set('cdd_cdp_ctldprofile_id_primary', $profile1->key);
				$device->set('cdd_profile_id_primary', $profile1->get('cdp_profile_id'));
				if(isset($profile2->key)){
					$device->set('cdd_cdp_ctldprofile_id_secondary', $profile2->key);
					$device->set('cdd_profile_id_secondary', $profile2->get('cdp_profile_id'));
				}
				$device->set('cdd_device_id', $result['body']['PK']);
				$device->set('cdd_device_type', $post_vars['device_type']);
				$device->set('cdd_device_name', $device_name);
				$device->set('cdd_controld_resolver', $result['body']['resolvers']['uid']);
				
				$device->prepare();
				$device->save();	
				return $device;
			}
			else{
				throw new SystemDisplayablePermanentError('Unable to create a device.');
				exit;
			}
	}
	
	function check_activate(){
		if(!$this->get('cdd_is_active')){
			$cd = new ControlDHelper();
			$result = $cd->listDevice($this->get('cdd_device_id'));
			$cd_device = $result['body']['devices'][0];
			if($cd_device['status'] == 1){
				$this->set('cdd_is_active', 1);
				$this->set('cdd_activate_time', 'now()');
				$this->save();
				$this->load();
			}
		}
		return true;
	}

	function get_time_to_active_profile($profile_choice){
		if(!$this->get('cdd_cdp_ctldprofile_id_secondary')){
			return [
				'hours'   => 0,
				'minutes' => 0
			];		
		}	

		$profile = new CtldProfile($this->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		
		if(!$profile->get('cdp_schedule_start') || !$profile->get('cdp_schedule_end')){
			return '';
		}

		if($profile_choice == 'primary'){
			$tz = new DateTimeZone($profile->get('cdp_schedule_timezone'));
			$now = new DateTime('now', $tz);
			$currentDay = strtolower($now->format('D')); // e.g., "mon", "tue", etc.

			// Create DateTime objects for today's start and end times in the given timezone.
			$todayStart = DateTime::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $profile->get('cdp_schedule_start'), $tz);
			$todayEnd   = DateTime::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $profile->get('cdp_schedule_end'), $tz);

			// If today is a scheduled day...
			if (in_array($currentDay, unserialize($profile->get('cdp_schedule_days')))) {
				// If the current time is before today's end, then today's scheduled period will end at $todayEnd.
				if ($now < $todayEnd) {
					$diff = $now->diff($todayEnd);
					return [
						'hours'   => $diff->h + ($diff->days * 24),
						'minutes' => $diff->i
					];
				}
			}
			else{
				return [
						'hours'   => 0,
						'minutes' => 0
					];
			}

		}
		else if ($profile_choice == 'secondary'){
			$tz = new DateTimeZone($profile->get('cdp_schedule_timezone'));
			$now = new DateTime('now', $tz);
			$currentDay = strtolower($now->format('D')); // e.g., "mon", "tue", etc.

			// Create DateTime objects for today's start and end times in the given timezone.
			$todayStart = DateTime::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $profile->get('cdp_schedule_start'), $tz);
			$todayEnd   = DateTime::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $profile->get('cdp_schedule_end'), $tz);

			// If today is a scheduled day...
			if (in_array($currentDay, unserialize($profile->get('cdp_schedule_days')))) {
				if ($now < $todayStart) {
					// Before the scheduled start today.
					$diff = $now->diff($todayStart);
					return [
						'hours'   => $diff->h + ($diff->days * 24),
						'minutes' => $diff->i
					];
				} elseif ($now >= $todayStart && $now < $todayEnd) {
					// Currently within the scheduled period.
					return [
						'hours'   => 0,
						'minutes' => 0
					];
				}
			}

			// Otherwise, find the next scheduled day (up to 7 days ahead).
			for ($i = 1; $i <= 7; $i++) {
				$nextDay = clone $now;
				$nextDay->modify("+{$i} days");
				$nextDayAbbrev = strtolower($nextDay->format('D'));

				if (in_array($nextDayAbbrev, unserialize($profile->get('cdp_schedule_days')))) {
					// Set the start time for that day.
					$nextStart = DateTime::createFromFormat('Y-m-d H:i', $nextDay->format('Y-m-d') . ' ' . $profile->get('cdp_schedule_start'), $tz);
					$diff = $now->diff($nextStart);
					return [
						'hours'   => $diff->h + ($diff->days * 24),
						'minutes' => $diff->i
					];
				}
			}						
		}

	}

	function get_schedule_string($profile_choice){
		if(!$this->get('cdd_cdp_ctldprofile_id_secondary')){
			return '';		
		}		

		$profile = new CtldProfile($this->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		
		if(!$profile->get('cdp_schedule_start') || !$profile->get('cdp_schedule_end')){
			return '';
		}
		
		$all_days = array('mon', 'tue','wed','thu','fri','sat','sun');

		if($profile_choice == 'primary'){
			$string = LibraryFunctions::convertToAmPmManual($profile->get('cdp_schedule_end')) . ' - ' . LibraryFunctions::convertToAmPmManual($profile->get('cdp_schedule_start')) . ' ' . implode(', ', unserialize($profile->get('cdp_schedule_days')));
			$string .= ', All day '.implode(', ', array_diff($all_days,  unserialize($profile->get('cdp_schedule_days'))));
			return $string;
			
		}
		else if ($profile_choice == 'secondary'){
					
			return LibraryFunctions::convertToAmPmManual($profile->get('cdp_schedule_start')) . ' - ' . LibraryFunctions::convertToAmPmManual($profile->get('cdp_schedule_end')) . ' ' . implode(', ', unserialize($profile->get('cdp_schedule_days')));					
		}

	}
	
	function get_active_profile($readable=false){
		if(!$this->get('cdd_cdp_ctldprofile_id_secondary')){
			if($readable){
				return 'Default blocklist';
			}
			else{
				return 'primary';
			}			
		}

		$profile = new CtldProfile($this->get('cdd_cdp_ctldprofile_id_secondary'), TRUE);
		
		if(!$profile->get('cdp_schedule_start') || !$profile->get('cdp_schedule_end')){
			return 'primary';
		}

		// Get current time and day
		$current_time = date("H:i");  // Current time in 24-hour format
		$current_day = strtolower(date("D")); // Current day in 'mon', 'tue', etc.

		// Check if today is in the active days list
		if (!in_array($current_day, unserialize($profile->get('cdp_schedule_days')))) {
			return false;
		}

		// Convert times to comparable formats
		$start_timestamp = $profile->get('cdp_schedule_start');
		$end_timestamp = $profile->get('cdp_schedule_end');
		$current_timestamp = strtotime($current_time);

		// Handle overnight schedules (e.g., 22:00 - 04:00)
		if ($end_timestamp < $start_timestamp) {
			// If current time is **after start OR before end**, it is active
			return ($current_timestamp >= $start_timestamp || $current_timestamp < $end_timestamp);
		}

		// Normal time range check
		$is_in_schedule = ($current_timestamp >= $start_timestamp && $current_timestamp < $end_timestamp);
		
		if($is_in_schedule){
			if($readable == 'readable'){
				return 'Scheduled blocklist';
			}
			else{
				return 'secondary';
			}
		}
		else{
			if($readable){
				return 'Default blocklist';
			}
			else{
				return 'primary';
			}
		}
	}
	
	function get_readable_name(){
		return preg_replace('/^user\d+-/', '', $this->get('cdd_device_name'));
		
	}
	
	function are_filters_editable(){
		
		if($this->get('cdd_allow_device_edits')){
			return true;
		}
		
		//IF PROFILES HAVE NOT BEEN CREATED, ALLOW EDITING
		if(!$this->get('cdd_cdp_ctldprofile_id_primary')){
			return true;
		}

		// IF WITHIN 24 HOURS OF ACTIVATION
		if($this->get('cdd_activate_time')){
			$current_timestamp = time();
			$future_24_hours = $current_timestamp + (24 * 60 * 60);
			if($this->get('cdd_activate_time') > $current_timestamp && $this->get('cdd_activate_time') <= $future_24_hours){
				return true;
			}
		}

		//IF IT IS THE DAY OF CREATION
		if(date('Y-m-d', strtotime($this->get('cdd_create_time'))) === date("Y-m-d")){
			return true;
		}

		//IF IT IS SUNDAY 
		$weekday = 'Sunday';
		if($this->get('cdd_timezone')){
			$timezone = $this->get('cdd_timezone');
		}
		else{
			$timezone = 'America/New_York';
		}

		date_default_timezone_set($timezone);

		$weekday = strtolower($weekday);
		// Get the current day in the specified timezone, in lowercase
		$currentDay = strtolower(date('l')); // 'l' returns full weekday name

		// Check if the input day matches the current day
		return $weekday === $currentDay;

	}

	//PROFILE CHOICE IS PRIMARY OR SECONDARY
	function permanent_delete_profile($profile_choice){
		if($profile_choice == 'primary'){
			$profile_id = $this->get('cdd_cdp_ctldprofile_id_primary');
		}
		else if ($profile_choice == 'secondary'){
			$profile_id = $this->get('cdd_cdp_ctldprofile_id_secondary');				
		}
		
		$profile = new CtldProfile($profile_id, TRUE);

		$profile->permanent_delete();
		
		return true;
	}

	function permanent_delete($debug = false){
		$cd = new ControlDHelper();
		
		//DELETE THE PROFILES
		if($this->get('cdd_profile_id_secondary')){
			$result = $this->permanent_delete_profile('secondary');
		}	
		
		$result = $this->permanent_delete_profile('primary');

		//NOW DELETE THE DEVICE AT REMOTE
		$result = $cd->deleteDevice($this->get('cdd_device_id'));	
		if($result['success']){
			
			if($this->get('cdd_profile_id_primary')){
				$result = $this->permanent_delete_profile('primary');
			}
		
			//COPY THE PIN AND SAVE IT
			$device_backup = new CtldDeviceBackup(NULL);
			$device_backup->set('cdb_device_backup_name', $this->get('cdd_device_name'));		
			$device_backup->set('cdb_usr_user_id', $this->get('cdd_usr_user_id'));	
			$device_backup->set('cdb_deactivation_pin', $this->get('cdd_deactivation_pin'));
			$device_backup->save();				

			parent::permanent_delete();		
			return true;			
		}
		else{
			throw new SystemDisplayablePermanentError('Unable to delete device.');
			exit;			
		}

	}
	
}

class MultiCtldDevice extends SystemMultiBase {
	protected static $model_class = 'CtldDevice';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctlddevice) {
			$items['('.$ctlddevice->key.') '.$ctlddevice->get('cdd_ctlddevice')] = $ctlddevice->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['user_id'])) {
            $filters['cdd_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['profile_id'])) {
            $filters['cdd_profile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cdd_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }
        
        if (isset($this->options['deleted'])) {
            $filters['cdd_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('cdd_ctlddevices', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
