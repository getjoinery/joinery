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
	public $prefix = 'prg';
	public $tablename = 'prg_product_groups';
	public $pkey_column = 'prg_product_group_id';
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

	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	
	function get_url() {
		return '/products/' . str_replace(' ', '-', $this->get('prg_name')) . '/' . $this->key;
	}

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS prg_product_groups_prg_product_group_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."prg_product_groups" (
		  "prg_product_group_id" int4 NOT NULL DEFAULT nextval(\'prg_product_groups_prg_product_group_id_seq\'::regclass),
		  "prg_max_items" int4 NOT NULL,
		  "prg_error" text COLLATE "pg_catalog"."default",
		  "prg_name" varchar(100) COLLATE "pg_catalog"."default",
		  "prg_description" text COLLATE "pg_catalog"."default"
		)
		;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."prg_product_groups" ADD CONSTRAINT "prg_product_groups_pkey" PRIMARY KEY ("prg_product_group_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new ProductGroup($row->prg_product_group_id);
			$child->load_from_data($row, array_keys(ProductGroup::$fields));
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
