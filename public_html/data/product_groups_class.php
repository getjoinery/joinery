<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class ProductGroupException extends SystemClassException {}

class ProductGroup extends SystemBase {
	public static $fields = array(
		'prg_product_group_id' => 'ID for this product group',
		'prg_max_items' => 'Max # of items allowed in the cart from this product group.',
		'prg_error' => 'Error message associated with too many items in the cart',
		'prg_name' => 'Name of the product group',
		'prg_description' => 'Description of the product group',
	);

	function get_url() {
		return '/products/' . str_replace(' ', '-', $this->get('prg_name')) . '/' . $this->key;
	}

	function load() {
		parent::load();

		$this->data = SingleRowFetch('prg_product_groups', 'prg_product_group_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

		if ($this->data === NULL) {
			throw new ProductGroupException('Invalid product ID');
		}
	}

	function save() {
		// Saving requires some session control for authentication checking and whatnot
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('prg_product_group_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['prg_product_group_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "prg_product_groups", $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['prg_product_group_id'];
	}
	
	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		/*
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}
		*/

		$sql = 'DELETE FROM prg_product_groups WHERE prg_product_group_id=:prg_product_group_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':prg_product_group_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		if($this_transaction){
			$dblink->commit();
		}
		
		$this->key = NULL;

		return TRUE;
		
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

	private function _get_results($only_count=FALSE) {
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

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new ProductGroup($row->prg_product_group_id);
			$child->load_from_data($row, array_keys(ProductGroup::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}

?>
