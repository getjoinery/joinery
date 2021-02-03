<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
	

class ProductDetailException extends SystemClassException {}
class ProductDetailNotSentException extends ProductDetailException {};

class ProductDetail extends SystemBase {


	public static $fields = array(
		'prd_product_detail_id' => 'ProductDetail id',
		'prd_pro_product_id' => 'Product id',
		'prd_prv_product_version_id' => 'Product version',
		'prd_usr_user_id' => 'Person who purchased the item',
		'prd_num_sessions' => 'Number of sessions purchased',
		'prd_num_used' => 'Number of sessions used',
		'prd_notes' => 'notes',
	);
	


	function load() {
		parent::load();
		$this->data = SingleRowFetch('prd_product_details', 'prd_product_detail_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new ProductDetailException(
				'This product_detail number does not exist');
		}
	}

	function prepare() {
		if ($this->data === NULL) {
			throw new ProductDetailException('This product_detail has no data.');
		}
	}	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();

	}

	
	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('prd_product_detail_id' => $this->key);
			// Editing an existing
		} else {
			$p_keys = NULL;
			// Creating a new
			unset($rowdata['prd_product_detail_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'prd_product_details', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['prd_product_detail_id'];
	}

	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM prd_product_details WHERE prd_product_detail_id=:prd_product_detail_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':prd_product_detail_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		$this->key = NULL;
		
		return true;		
	}

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS prd_product_details_prd_product_detail_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."prd_product_details" (
			  "prd_product_detail_id" int4 NOT NULL DEFAULT nextval(\'prd_product_details_prd_product_detail_id_seq\'::regclass),
			  "prd_pro_product_id" int4,
			  "prd_prv_product_version_id" int4,
			  "prd_usr_user_id" int4 NOT NULL,
			  "prd_num_sessions" int4,
			  "prd_num_used" int4 NOT NULL DEFAULT 0,
			  "prd_notes" text COLLATE "pg_catalog"."default"
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."prd_product_details" ADD CONSTRAINT "prd_product_details_pkey" PRIMARY KEY ("prd_product_detail_id");';
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

class MultiProductDetail extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
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

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new ProductDetail($row->prd_product_detail_id);
			$child->load_from_data($row, array_keys(ProductDetail::$fields));
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
