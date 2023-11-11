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

class ProductVersionException extends SystemClassException {}

class ProductVersion extends SystemBase {
	public static $prefix = 'esf';
	public static $tablename = 'prv_product_versions';
	public static $pkey_column = 'prv_product_version_id';
	
	// Constants for prv_status
	const ACTIVE = 1;
	const INACTIVE = 2;
	
	public static $permanent_delete_actions = array(
		'prv_product_version_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'prv_product_version_id' => 'ID of the product_version',
		'prv_pro_product_id' => 'Product this version is attached to',
		'prv_version_name' => 'Name of the product version',
		'prv_version_price' => 'Price of this version',
		'prv_status' => 'Status',
		'prv_order' => 'Order of display',
		'prv_percent_tax_deductible' => 'Percent that is tax deductible',
	);

	public static $field_specifications = array(
		'prv_product_version_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prv_pro_product_id' => array('type'=>'int4'),
		'prv_version_name' => array('type'=>'varchar(100)'),
		'prv_version_price' => array('type'=>'numeric(10,2)'),
		'prv_status' => array('type'=>'int2'),
		'prv_order' => array('type'=>'int4'),
		'prv_percent_tax_deductible' => array('type'=>'int4'),
	);
	
	
	public static $required_fields = array('prv_pro_product_id', 'prv_version_name', 'prv_version_price', 'prv_status');
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	
	public static function StoreProductVersion($product_id, $version_name, $version_price, $state) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'INSERT INTO prv_product_versions ' .
			'(prv_pro_product_id, prv_version_name, prv_version_price, prv_status)
				VALUES (?, ?, ?, ?)';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->bindValue(2, $version_name, PDO::PARAM_STR);
			$q->bindValue(3, $version_price, PDO::PARAM_STR);	
			$q->bindValue(4, $state, PDO::PARAM_INT);				
			
			$q->execute();
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function GetActiveProductVersion($product_id, $product_version_id) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM prv_product_versions WHERE 
			prv_pro_product_id = ? AND prv_product_version_id = ? AND prv_status = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->bindValue(2, $product_version_id, PDO::PARAM_INT);
			$q->bindValue(3, self::ACTIVE, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);

			if ($q->rowCount()) {
				return $q->fetch();
			} else {
				return NULL;
			}
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function GetAnyProductVersion($product_id, $product_version_id) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM prv_product_versions WHERE 
			prv_pro_product_id = ? AND prv_product_version_id = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->bindValue(2, $product_version_id, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);

			if ($q->rowCount()) {
				return $q->fetch();
			} else {
				return NULL;
			}
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function ChangeProductVersionState($product_id, $product_version_id, $new_state) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'UPDATE prv_product_versions SET prv_status = ? WHERE 
			prv_pro_product_id = ? AND prv_product_version_id = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $new_state, PDO::PARAM_INT);
			$q->bindValue(2, $product_id, PDO::PARAM_INT);
			$q->bindValue(3, $product_version_id, PDO::PARAM_INT);
			$q->execute();
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	public static function GetProductVersionsForProduct($product_id, $valid_states=NULL) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT * FROM prv_product_versions
			WHERE prv_pro_product_id = ? ORDER BY prv_product_version_id ASC';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $product_id, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		$versions = array();
		foreach ($q->fetchall() as $product_version) {
			if ($valid_states === NULL || in_array($product_version->prv_status, $valid_states)) {
				$versions[] = $product_version;
			}
		}
		return $versions;
	}
	
}

class MultiProductVersion extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('file_id', $this->options)) {
		 	$where_clauses[] = 'prv_fil_file_id = ?';
		 	$bind_params[] = array($this->options['file_id'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM prv_product_versions ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM prv_product_versions
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " prv_product_version_id ASC ";
			}
			else {
				if (array_key_exists('product_version_id', $this->order_by)) {
					$sql .= ' prv_product_version_id ' . $this->order_by['product_version_id'];
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
			$child = new ProductVersion($row->prv_product_version_id);
			$child->load_from_data($row, array_keys(ProductVersionFile::$fields));
			$this->add($child);
		}
	}

}


?>