<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');
	

class ProductDetailException extends SystemClassException {}
class ProductDetailNotSentException extends ProductDetailException {};

class ProductDetail extends SystemBase {
	public static $prefix = 'prd';
	public static $tablename = 'prd_product_details';
	public static $pkey_column = 'prd_product_detail_id';
	public static $permanent_delete_actions = array(
		'prd_product_detail_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'prd_product_detail_id' => 'ProductDetail id',
		'prd_pro_product_id' => 'Product id',
		'prd_prv_product_version_id' => 'Product version',
		'prd_usr_user_id' => 'Person who purchased the item',
		'prd_num_sessions' => 'Number of sessions purchased',
		'prd_num_used' => 'Number of sessions used',
		'prd_notes' => 'notes',
	);

	public static $field_specifications = array(
		'prd_product_detail_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prd_pro_product_id' => array('type'=>'int4'),
		'prd_prv_product_version_id' => array('type'=>'int4'),
		'prd_usr_user_id' => array('type'=>'int4'),
		'prd_num_sessions' => array('type'=>'int4'),
		'prd_num_used' => array('type'=>'int4'),
		'prd_notes' => array('type'=>'text'),
	);	
	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();

	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();

	}



}

class MultiProductDetail extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('product_id', $this->options)) {
			$where_clauses[] = 'prd_pro_product_id = ?';
			$bind_params[] = array($this->options['product_id'], PDO::PARAM_INT);
		}
	
		if (array_key_exists('product_version_id', $this->options)) {
			$where_clauses[] = 'prd_prv_product_version_id = ?';
			$bind_params[] = array($this->options['product_version_id'], PDO::PARAM_INT);
		}	
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM prd_product_details
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM prd_product_details
				' . $where_clause . ' ORDER BY ';

			if ($this->order_by === NULL) {
				$sql .= 'prd_product_detail_id DESC';
			} else {
				$sort_clauses = array();
				if (array_key_exists('product_detail_id', $this->order_by)) {
					$sort_clauses[] = 'prd_product_detail_id ' . $this->order_by['product_detail_id'];
				}
				
				$sql .= implode(',', $sort_clauses);
			}
			$sql .= $this->generate_limit_and_offset();
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new ProductDetail($row->prd_product_detail_id);
			$child->load_from_data($row, array_keys(ProductDetail::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}



?>
