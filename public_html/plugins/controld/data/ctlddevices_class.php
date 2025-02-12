<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldaccounts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldprofiles_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldfilters_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctldservices_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/plugins/controld/data/ctlddevice_backups_class.php');


class CtldDeviceException extends SystemClassException {}

class CtldDevice extends SystemBase {

	public static $prefix = 'cdd';
	public static $tablename = 'cdd_ctlddevices';
	public static $pkey_column = 'cdd_ctlddevice_id';
	public static $permanent_delete_actions = array(
		'cdd_ctlddevice_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	


	public static $fields = array(
		'cdd_ctlddevice_id' => 'ID of the ctlddevice',
		'cdd_device_id' => 'ID from controld',
		'cdd_device_name' => 'Name of device',
		'cdd_device_type' => 'Type of OS on the device',
		'cdd_profile_id_primary' => 'ID from controld',
		'cdd_profile_id_secondary' => 'ID from controld',
		'cdd_cdp_ctldprofile_id_primary' => 'Local foreign key',
		'cdd_cdp_ctldprofile_id_secondary' => 'Local foreign key',
		'cdd_usr_user_id' => 'User id this profile is assigned to',
		'cdd_is_active' => 'Is it active?',
		'cdd_create_time' => 'Time Created',
		'cdd_delete_time' => 'Time deleted',
		'cdd_controld_resolver' => 'Link/code to provision this device at controld',
		'cdd_deactivation_pin' => 'Pin to turn off the service',
		'cdd_timezone' => 'Timezone for this device for use in controld',
		'cdd_allow_device_edits' => 'Override for the edit restrictions',
	);

	public static $field_specifications = array(
		'cdd_ctlddevice_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdd_device_id' => array('type'=>'varchar(64)'),
		'cdd_device_name' => array('type'=>'varchar(64)'),
		'cdd_device_type' => array('type'=>'varchar(32)'),
		'cdd_profile_id_primary' => array('type'=>'varchar(64)'),
		'cdd_profile_id_secondary' => array('type'=>'varchar(64)'),
		'cdd_cdp_ctldprofile_id_primary' => array('type'=>'int4'),
		'cdd_cdp_ctldprofile_id_secondary' => array('type'=>'int4'),
		'cdd_usr_user_id' => array('type'=>'int4'),
		'cdd_is_active' => array('type'=>'bool'),
		'cdd_create_time' => array('type'=>'timestamp(6)'),
		'cdd_delete_time' => array('type'=>'timestamp(6)'),
		'cdd_controld_resolver' => array('type'=>'varchar(128)'),
		'cdd_deactivation_pin' => array('type'=>'varchar(10)'),
		'cdd_timezone' => array('type'=>'varchar(64)'),
		'cdd_allow_device_edits' => array('type'=>'int4'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdd_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'cdd_create_time' => 'now()'
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
				$device->set('cdd_cdp_ctldprofile_id_secondary', $profile2->key);
				$device->set('cdd_profile_id_secondary', $profile2->get('cdp_profile_id'));
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
		$cd = new ControlDHelper();
		if($profile_choice == 'primary'){
			$cd_profile_id = $this->get('cdd_profile_id_primary');
			$profile_id = $this->get('cdd_cdp_ctldprofile_id_primary');
		}
		else if ($profile_choice == 'secondary'){
			$cd_profile_id = $this->get('cdd_profile_id_secondary');
			$profile_id = $this->get('cdd_cdp_ctldprofile_id_secondary');			
		}
		
		$profile = new CtldProfile($profile_id, TRUE);

		//DELETE THE SCHEDULE IF PRESENT
		if($profile->get('cdp_schedule_id')){

			$result = $cd->deleteSchedule($profile->get('cdp_schedule_id'));
			if(!$result['success']){
				throw new SystemDisplayablePermanentError('Unable to delete schedule.');
				exit;
			}
			$profile->set('cdp_schedule_id', NULL);
			$profile->set('cdp_schedule_start', NULL);
			$profile->set('cdp_schedule_end', NULL);
			$profile->set('cdp_schedule_days', NULL);
			$profile->set('cdp_schedule_timezone', NULL);
			$profile->save();
		}
		$result = $cd->deleteProfile($cd_profile_id);
		if(!$result['success']){
			throw new SystemDisplayablePermanentError('Unable to delete profile.');
			exit;
		}		


		$filters = new MultiCtldFilter(
				array(
					'profile_id' => $profile_id,
				),
			);
			$filters->load();

		foreach($filters as $filter){
			$filter->permanent_delete();
		}
		
		$services = new MultiCtldService(
				array(
					'profile_id' => $profile_id,
				),
			);
			$services->load();
		foreach($services as $service){
			$service->permanent_delete();
		}

		$profile->permanent_delete();

		if($profile_choice == 'primary'){
			$this->set('cdd_profile_id_primary', NULL);	
			$this->set('cdd_cdp_ctldprofile_id_primary', NULL);	
		}
		else if ($profile_choice == 'secondary'){
			$this->set('cdd_profile_id_secondary', NULL);
			$this->set('cdd_cdp_ctldprofile_id_secondary', NULL);				
		}
				
		$this->save();
		
		return true;
	}
	
	
	function permanent_delete($debug = false){
		$cd = new ControlDHelper();
		
		//DELETE THE PROFILES
		if($this->get('cdd_profile_id_secondary')){
			$result = $this->permanent_delete_profile('secondary');
		}	
		




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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'cdd_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('profile_id', $this->options)) {
		 	$where_clauses[] = 'cdd_profile_id = ?';
		 	$bind_params[] = array($this->options['profile_id'], PDO::PARAM_INT);
		} 
			

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cdd_is_active = ' . ($this->options['active'] ? 'TRUE' : 'FALSE');
		}

		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'cdd_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cdd_ctlddevices ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cdd_ctlddevices
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cdd_ctlddevice_id ASC ";
			}
			else {
				if (array_key_exists('ctlddevice_id', $this->order_by)) {
					$sql .= ' cdd_ctlddevice_id ' . $this->order_by['ctlddevice_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new CtldDevice($row->cdd_ctlddevice_id);
			$child->load_from_data($row, array_keys(CtldDevice::$fields));
			$this->add($child);
		}
	}

}


?>
