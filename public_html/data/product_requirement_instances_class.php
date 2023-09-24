<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');

require_once($siteDir . '/data/products_class.php');
require_once($siteDir . '/data/product_requirements_class.php');

class ProductRequirementInstanceException extends SystemClassException {}
class DisplayableProductRequirementInstanceException extends ProductRequirementInstanceException implements DisplayableErrorMessage {}
class DisplayablePermanentProductRequirementInstanceException extends ProductRequirementInstanceException implements DisplayablePermanentErrorMessage {}


class ProductRequirementInstance extends SystemBase {
	public static $prefix = 'pri';
	public static $tablename = 'pri_product_requirement_instances';
	public static $pkey_column = 'pri_product_requirement_instance_id';
	public static $permanent_delete_actions = array(
		'pri_product_requirement_instance_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'pri_product_requirement_instance_id' => 'Product Requirement Instance ID',
		'pri_pro_product_id' => 'Product it is attached to',
		'pri_prq_product_requirement_id' => 'Product Requirement it is attached to',
		'pri_delete_time' => 'Time deleted'
		); 

	public static $field_specifications = array(
		'pri_product_requirement_instance_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pri_pro_product_id' => array('type'=>'int4'),
		'pri_prq_product_requirement_id' => array('type'=>'int4'),
		'pri_delete_time' => array('type'=>'timestamp(6)'),
		); 
			 	
	public static $required_fields = array(
		'pri_pro_product_id', 'pri_prq_product_requirement_id'
	);
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(

	);	

	public static $field_constraints = array(
	/*
		'prq_name' => array(
			array('WordLength', 0, 255),
			'NoCaps',
			),
		'prq_description' => array(
			array('WordLength', 50, 100000),
			'NoCaps',
			),
					*/
		);
		
	


	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}	


}

class MultiProductRequirementInstance extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$option_display = $item->get('prq_title'); 
			$items[$option_display] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}




	function _get_results($only_count=FALSE, $debug = false) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('product_id', $this->options)) {
			$where_clauses[] = 'pri_pro_product_id = ?';
			$bind_params[] = array($this->options['product_id'], PDO::PARAM_INT);
		}
		

		if (array_key_exists('product_requirement_id', $this->options)) {
			$where_clauses[] = 'pri_prq_product_requirement_id = ?';
			$bind_params[] = array($this->options['product_requirement_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'pri_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}		
			
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM pri_product_requirement_instances
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM pri_product_requirement_instances
				' . $where_clause . ' ORDER BY ';

			if ($this->order_by === NULL) {
				$sql .= 'pri_product_requirement_instance_id DESC';
			} else {
				$sort_clauses = array();

				if (array_key_exists('product_requirement_instance_id', $this->order_by)) {
					$sort_clauses[] = 'pri_product_requirement_instance_id ' . $this->order_by['product_requirement_instance_id'];
				}	
							
				
				$sql .= implode(',', $sort_clauses);
			}
			$sql .= $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);
			
			if($debug){
				echo $sql. "<br>\n";
				print_r($this->options);
			}

			$total_params = count($bind_params);
			for($i=0;$i<$total_params;$i++) {
				list($param, $type) = $bind_params[$i];
				$q->bindValue($i+1, $param, $type);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ProductRequirementInstance($row->pri_product_requirement_instance_id);
			$child->load_from_data($row, array_keys(ProductRequirementInstance::$fields));
			$this->add($child);
		}
	}

}

?>
