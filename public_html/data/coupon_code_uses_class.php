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


class CouponCodeUseException extends SystemClassException {}

class CouponCodeUse extends SystemBase {

	public static $prefix = 'ccu';
	public static $tablename = 'ccu_coupon_code_uses';
	public static $pkey_column = 'ccu_coupon_code_use_id';
	public static $permanent_delete_actions = array(
		//'ccu_coupon_code_use_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'ccu_coupon_code_use_id' => 'ID of the coupon_code_use use',
		'ccu_ccd_coupon_code_id' => 'The ID of the coupon code',
		'ccu_amount_discount' => 'Amount in currency of the coupon at time of use',
		'ccu_percent_discount' => 'Percent of coupon at time of use',
		'ccu_odi_order_item_id' => 'Order id of use',
		'ccu_create_time' => 'Time Created',
		'ccu_delete_time' => 'Time deleted'
	);

	public static $field_specifications = array(
		'ccu_coupon_code_use_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ccu_ccd_coupon_code_id' => array('type'=>'int4'),
		'ccu_amount_discount' => array('type'=>'numeric(10,2)'),
		'ccu_percent_discount' => array('type'=>'int4'),
		'ccu_odi_order_item_id' => array('type'=>'int4'),
		'ccu_create_time' => array('type'=>'timestamp(6)'),
		'ccu_delete_time' => array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array(
		'ccu_ccd_coupon_code_id',
		'ccu_odi_order_item_id'
		);

	public static $field_constraints = array(
		/*'ccu_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'ccu_create_time' => 'now()'
	);	

	/*
	function prepare() {

	}	
	*/
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiCouponCodeUse extends SystemMultiBase {

/*
	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $coupon_code_use) {
			$items['('.$coupon_code_use->key.') '.$coupon_code_use->get('ccu_coupon_code_use')] = $coupon_code_use->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	*/

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('coupon_code_use_id', $this->options)) {
		 	$where_clauses[] = 'ccu_cco_coupon_code_use_id = ?';
		 	$bind_params[] = array($this->options['coupon_code_use_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('coupon_code_id', $this->options)) {
		 	$where_clauses[] = 'ccu_ccd_coupon_code_id = ?';
		 	$bind_params[] = array($this->options['coupon_code_id'], PDO::PARAM_INT);
		} 		
		
		if (array_key_exists('order_id', $this->options)) {
		 	$where_clauses[] = 'ccu_ord_order_id = ?';
		 	$bind_params[] = array($this->options['order_id'], PDO::PARAM_INT);
		} 		

		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'ccu_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM ccu_coupon_code_uses ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM ccu_coupon_code_uses
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " ccu_coupon_code_use_id ASC ";
			}
			else {
				if (array_key_exists('coupon_code_use_id', $this->order_by)) {
					$sql .= ' ccu_coupon_code_use_id ' . $this->order_by['coupon_code_use_id'];
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
			$child = new CouponCodeUse($row->ccu_coupon_code_use_id);
			$child->load_from_data($row, array_keys(CouponCodeUse::$fields));
			$this->add($child);
		}
	}

}


?>
