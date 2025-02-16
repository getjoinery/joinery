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

require_once($siteDir . '/plugins/controld/data/ctlddevices_class.php');


class CtldAccountException extends SystemClassException {}

class CtldAccount extends SystemBase {

	public static $prefix = 'cda';
	public static $tablename = 'cda_ctldaccounts';
	public static $pkey_column = 'cda_ctldaccount_id';
	public static $permanent_delete_actions = array(
		'cda_ctldaccount_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	const BASIC_PLAN = 1;
	const PREMIUM_PLAN = 2;
	const PRO_PLAN = 3;
	
	const BASIC_PLAN_MAX_DEVICES = 1;
	const PREMIUM_PLAN_MAX_DEVICES = 3;
	const PRO_PLAN_MAX_DEVICES = 10;	
	
	public static $fields = array(
		'cda_ctldaccount_id' => 'ID of the ctldaccount',
		'cda_plan' => 'Plan of this user',
		'cda_plan_max_devices' => 'Max devices allowed',
		'cda_usr_user_id' => 'User id this profile is assigned to',
		'cda_is_active' => 'Is it active?',
		'cda_period_end' => 'Time this user is up for renewal',
		'cda_create_time' => 'Time Created',
		'cda_delete_time' => 'Time deleted',
	);

	public static $field_specifications = array(
		'cda_ctldaccount_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cda_plan' => array('type'=>'int4'),
		'cda_plan_max_devices' => array('type'=>'int4'),
		'cda_usr_user_id' => array('type'=>'int4'),
		'cda_is_active' => array('type'=>'bool'),
		'cda_period_end' => array('type'=>'timestamp(6)'),
		'cda_create_time' => array('type'=>'timestamp(6)'),
		'cda_delete_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cda_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'cda_create_time' => 'now()'
	);	

	
	function prepare() {
		if(CtldAccount::GetByColumn('cda_usr_user_id', $this->get('cda_usr_user_id')) && !$this->key){
			throw new CtldAccountException('That controld user id already exists.');
		}		
		
	}	
	
	
	function authenticate_write($data) {
		if ($this->get('cda_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}
	
	function readable_plan_name(){
		if($this->get('cda_plan') == CtldAccount::BASIC_PLAN){
			return 'Basic Plan';	
		}
		else if($this->get('cda_plan') == CtldAccount::PREMIUM_PLAN){
			return 'Premium Plan';	
		}
		else if($this->get('cda_plan') == CtldAccount::PRO_PLAN){
			return 'Pro Plan';	
		}			
		
		return false;
	}
	
	function is_active(){

		if(!$this->get('cda_is_active')){
			return false;
		}
		
		if($this->get('cda_period_end')){
			$end_time = strtotime($this->get('cda_period_end'));
			$current_time = time();
			if($end_time < $current_time){
				return false;
			}
		}
		
		return true;
	}
	
	
	function can_add_device(){
		if(!$this->is_active()){
			return false;
		}

		$devices = new MultiCtldDevice(
			array(
			'user_id' => $this->get('cda_usr_user_id'), 
			'deleted' => false
			), 
			
		);
		$num_devices = $devices->count_all();
		if($num_devices >= $this->get('cda_plan_max_devices')){
			return false;
		}	
		return true;
	}
	
	
	//RETURNS THE ORDER ITEM THAT CORRESPONDS TO THIS USER'S SUBSCRIPTION
	static function GetPlanOrderItem($user_id){
		
		if(!$user_id){
			throw new SystemDisplayablePermanentError('User was not provided to get Plan.');
			exit;
		}
		
		//SUBSCRIPTIONS
		$subscriptions = new MultiOrderItem(
		array('user_id' => $user_id, 'is_active_subscription' => true), //SEARCH CRITERIA
		array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
		5, //NUMBER PER PAGE
		NULL //OFFSET
		);
		$subscriptions->load();	
		
		if($subscriptions->count_all()){
			$order_item = $subscriptions->get(0);
			return $order_item;
		}
		else{
			return false;
		}

		
	}
	
}

class MultiCtldAccount extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldaccount) {
			$items['('.$ctldaccount->key.') '.$ctldaccount->get('cda_ctldaccount')] = $ctldaccount->key;
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
		 	$where_clauses[] = 'cda_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('plan', $this->options)) {
		 	$where_clauses[] = 'cda_plan = ?';
		 	$bind_params[] = array($this->options['plan'], PDO::PARAM_INT);
		} 	

		if (array_key_exists('active', $this->options)) {
		 	$where_clauses[] = 'cda_is_active = ' . ($this->options['active'] ? 'TRUE' : 'FALSE');
		}

		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'cda_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM cda_ctldaccounts ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM cda_ctldaccounts
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " cda_ctldaccount_id ASC ";
			}
			else {
				if (array_key_exists('ctldaccount_id', $this->order_by)) {
					$sql .= ' cda_ctldaccount_id ' . $this->order_by['ctldaccount_id'];
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
			$child = new CtldAccount($row->cda_ctldaccount_id);
			$child->load_from_data($row, array_keys(CtldAccount::$fields));
			$this->add($child);
		}
	}

}


?>
