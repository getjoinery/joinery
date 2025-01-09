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
require_once($siteDir . '/plugins/controld/includes/ControlDHelper.php');
require_once($siteDir . '/plugins/controld/data/ctldfilters_class.php');
require_once($siteDir . '/plugins/controld/data/ctldservices_class.php');

class CtldProfileException extends SystemClassException {}

class CtldProfile extends SystemBase {

	public static $prefix = 'cdp';
	public static $tablename = 'cdp_ctldprofiles';
	public static $pkey_column = 'cdp_ctldprofile_id';
	public static $permanent_delete_actions = array(
		'cdp_ctldprofile_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cdp_ctldprofile_id' => 'ID of the ctldprofile',
		'cdp_profile_id' => 'ID from controld',
		'cdp_usr_user_id' => 'User id this profile is assigned to',
		'cdp_is_active' => 'Is it active?',
		'cdp_create_time' => 'Time Created',
		'cdp_delete_time' => 'Time deleted',
		'cdp_schedule_start' => 'Time this profile turns on xx:xx (24 hour time)',
		'cdp_schedule_end' => 'Time this profile turns on xx:xx (24 hour time)',
		'cdp_schedule_days' => 'Days of the week, serialized list of 3 letter abbreviations',
		'cdp_schedule_timezone' => 'Timezone for the schedule in America/New_York format',
		'cdp_schedule_id' => 'Schedule id at Controld',
	);

	public static $field_specifications = array(
		'cdp_ctldprofile_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdp_profile_id' => array('type'=>'varchar(64)'),
		'cdp_usr_user_id' => array('type'=>'int4'),
		'cdp_is_active' => array('type'=>'bool'),
		'cdp_create_time' => array('type'=>'timestamp(6)'),
		'cdp_delete_time' => array('type'=>'timestamp(6)'),
		'cdp_schedule_start' => array('type'=>'varchar(5)'),
		'cdp_schedule_end' => array('type'=>'varchar(5)'),
		'cdp_schedule_days' => array('type'=>'varchar(64)'),
		'cdp_schedule_timezone' => array('type'=>'varchar(64)'),
		'cdp_schedule_id' => array('type'=>'varchar(64)'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdp_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'cdp_create_time' => 'now()'
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


		foreach($all_filters as $all_filter_key=>$all_filter_desc){
			if(isset($newvalues['block_'.$all_filter_key])){
				//FORM VALUE WAS SUBMITTED
				if(isset($cached_filters[$all_filter_key])){
					//CACHED FILTER EXISTS
					if($cached_filters[$all_filter_key] != $newvalues['block_'.$all_filter_key]){
						//CHANGED, UPDATE REMOTE AND LOCAL
						$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, $newvalues['block_'.$all_filter_key]);
						
						foreach($filters as $filter){
							if($filter->get('cdf_filter_pk') == $all_filter_key){
								$filter->set('cdf_is_active',$newvalues['block_'.$all_filter_key]);
								$filter->prepare();
								$filter->save();								
							}
						}
						$numchanges++;
					}
					else{
						//NO NEED TO DO ANYTHING
					}
				}
				else{
					//CACHED FILTER DOES NOT EXIST, UPDATE REMOTE FIRST AND THEN ADD LOCALLY
					$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, $newvalues['block_'.$all_filter_key]);
					
					$new_cached_filter = new CtldFilter(NULL);
					$new_cached_filter->set('cdf_cdp_ctldprofile_id',$this->key);
					$new_cached_filter->set('cdf_filter_pk',$all_filter_key);
					$new_cached_filter->set('cdf_is_active',$newvalues['block_'.$all_filter_key]);
					$new_cached_filter->prepare();
					$new_cached_filter->save();
					$numchanges++;
				}
			}
			else{
				//POST VALUE WAS NOT SUBMITTED. IT IS "OFF"
				if(isset($cached_filters[$all_filter_key])){
					//CACHED FILTER EXISTS
					if($cached_filters[$all_filter_key]){
						//CACHED IS NOT ZERO, SO UPDATE CACHE AND UPDATE REMOTE
						$result = $cd->modifyProfileFilter($this->get('cdp_profile_id'), $all_filter_key, 0);
						
						foreach($filters as $filter){
							if($filter->get('cdf_filter_pk') == $all_filter_key){
								$filter->set('cdf_is_active',0);
								$filter->prepare();
								$filter->save();								
							}
						}
						$numchanges++;						
						
					}
					else{
						//NO NEED TO DO ANYTHING
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
						
						foreach($services as $service){
							if($service->get('cds_service_pk') == $all_service_key){
								$service->set('cds_is_active',$newvalues['block_'.$all_service_key]);
								$service->prepare();
								$service->save();								
							}
						}
						$numchanges++;
					}
					else{
						//NO NEED TO DO ANYTHING
					}
				}
				else{
					//CACHED FILTER DOES NOT EXIST, UPDATE REMOTE FIRST AND THEN ADD LOCALLY
					$result = $cd->modifyService($this->get('cdp_profile_id'), $all_service_key, $newvalues['block_'.$all_service_key]);
					
					$new_cached_service = new CtldService(NULL);
					$new_cached_service->set('cds_cdp_ctldprofile_id',$this->key);
					$new_cached_service->set('cds_service_pk',$all_service_key);
					$new_cached_service->set('cds_is_active',$newvalues['block_'.$all_service_key]);
					$new_cached_service->prepare();
					$new_cached_service->save();
					$numchanges++;
				}
			}
			else{
				//POST VALUE WAS NOT SUBMITTED. IT IS "OFF"
				if(isset($cached_services[$all_service_key])){
					//CACHED FILTER EXISTS
					if($cached_services[$all_service_key]){
						//CACHED IS NOT ZERO, SO UPDATE CACHE AND UPDATE REMOTE
						$result = $cd->modifyService($this->get('cdp_profile_id'), $all_service_key, 0);
						
						foreach($services as $service){
							if($service->get('cds_service_pk') == $all_service_key){
								$service->set('cds_is_active',0);
								$service->prepare();
								$service->save();								
							}
						}
						$numchanges++;						
						
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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'cdp_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('profile_id_primary', $this->options)) {
		 	$where_clauses[] = 'cdp_profile_id_primary = ?';
		 	$bind_params[] = array($this->options['profile_id_primary'], PDO::PARAM_INT);
		} 

		if (array_key_exists('profile_id_secondary', $this->options)) {
		 	$where_clauses[] = 'cdp_profile_id_secondary = ?';
		 	$bind_params[] = array($this->options['profile_id_secondary'], PDO::PARAM_INT);
		} 		

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cdp_is_active = ' . ($this->options['active'] ? 'TRUE' : 'FALSE');
		}

		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'cdp_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cdp_ctldprofiles ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cdp_ctldprofiles
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cdp_ctldprofile_id ASC ";
			}
			else {
				if (array_key_exists('ctldprofile_id', $this->order_by)) {
					$sql .= ' cdp_ctldprofile_id ' . $this->order_by['ctldprofile_id'];
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
			$child = new CtldProfile($row->cdp_ctldprofile_id);
			$child->load_from_data($row, array_keys(CtldProfile::$fields));
			$this->add($child);
		}
	}

}


?>
