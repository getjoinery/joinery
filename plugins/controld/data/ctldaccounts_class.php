<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('plugins/controld/data/ctlddevices_class.php');

class CtldAccountException extends SystemBaseException {}

class CtldAccount extends SystemBase {

	public static $prefix = 'cda';
	public static $tablename = 'cda_ctldaccounts';
	public static $pkey_column = 'cda_ctldaccount_id';
	public static $permanent_delete_actions = array(
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	const BASIC_PLAN = 1;
	const PREMIUM_PLAN = 2;
	const PRO_PLAN = 3;
	
	const BASIC_PLAN_MAX_DEVICES = 1;
	const PREMIUM_PLAN_MAX_DEVICES = 3;
	const PRO_PLAN_MAX_DEVICES = 10;	

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
	    'cda_ctldaccount_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'cda_plan' => array('type'=>'int4'),
	    'cda_plan_max_devices' => array('type'=>'int4'),
	    'cda_usr_user_id' => array('type'=>'int4'),
	    'cda_is_active' => array('type'=>'bool'),
	    'cda_period_end' => array('type'=>'timestamp(6)'),
	    'cda_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'cda_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array(
		/*'cda_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
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
	protected static $model_class = 'CtldAccount';

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

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['user_id'])) {
            $filters['cda_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['plan'])) {
            $filters['cda_plan'] = [$this->options['plan'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cda_is_active'] = $this->options['active'] ? "= TRUE" : "= FALSE";
        }
        
        if (isset($this->options['deleted'])) {
            $filters['cda_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('cda_ctldaccounts', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
