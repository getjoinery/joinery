<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

class ProductGroupException extends SystemClassException {}

class ProductGroup extends SystemBase {
	public static $prefix = 'prg';
	public static $tablename = 'prg_product_groups';
	public static $pkey_column = 'prg_product_group_id';
	public static $permanent_delete_actions = array(
		'prg_product_group_id' => 'delete',	
		'pro_prg_product_group_id' => 'prevent'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'prg_product_group_id' => 'ID for this product group',
		'prg_max_items' => 'Max # of items allowed in the cart from this product group.',
		'prg_error' => 'Error message associated with too many items in the cart',
		'prg_name' => 'Name of the product group',
		'prg_description' => 'Description of the product group',
	);

	public static $field_specifications = array(
		'prg_product_group_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'prg_max_items' => array('type'=>'int4'),
		'prg_error' => array('type'=>'text'),
		'prg_name' => array('type'=>'varchar(100)'),
		'prg_description' => array('type'=>'text'),
	);

	public static $required_fields = array('prg_max_items');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	
	function get_url() {
		return '/products/' . str_replace(' ', '-', $this->get('prg_name')) . '/' . $this->key;
	}

}

class MultiProductGroup extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->get('prg_name')] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) {
		$where_clauses = array();
		$bind_params = array();

		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM prg_product_groups
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM prg_product_groups
				' . $where_clause . '
				ORDER BY prg_product_group_id ASC' . $this->generate_limit_and_offset();
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
			$child = new ProductGroup($row->prg_product_group_id);
			$child->load_from_data($row, array_keys(ProductGroup::$fields));
			$this->add($child);
		}
	}

}

?>
