<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once(LibraryFunctions::get_theme_path().'/includes/FormWriterPublic.php');

class CouponCodeException extends SystemClassException {}

class CouponCode extends SystemBase {

	//public $prefix = 'ccd';  NOT USED YET
	
	//public $primary_key = 'ccd_coupon_code_id'; NOT USED YET
	
	public static $fields = array(
		'ccd_coupon_code_id' => 'ID of the coupon_code',
		'ccd_code' => 'The code',
		'ccd_amount_discount' => 'Amount in currency of the coupon',
		'ccd_percent_discount' => 'Percent of coupon',
		'ccd_start_time' => 'Start time of coupon',
		'ccd_end_time' => 'End time of coupon',
		'ccd_is_active' => 'Is it active?',
		'ccd_published_time' => 'Time published',
		'ccd_create_time' => 'Time Created',
		'ccd_delete_time' => 'Time deleted'
	);

	public static $required_fields = array(array('ccd_percent_discount', 'ccd_amount_discount'));

	public static $field_constraints = array(
		'ccd_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'ccd_create_time' => 'now()'
	);	

	static function check_if_exists($key) {
		$data = SingleRowFetch('ccd_coupon_codes', 'ccd_coupon_code_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}

	public static function get_by_name($name) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "SELECT ccd_coupon_code_id FROM ccd_coupon_codes
			WHERE ccd_code = :ccd_code";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':ccd_code', $name, PDO::PARAM_STR);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		if (!$q->rowCount()) {
			//throw new AddressException('This user doesn\'t have a default address.');
			return FALSE;
		}

		$r = $q->fetch();

		return new CouponCode($r->ccd_coupon_code_id, TRUE);
	}	
	
	function get_discount($full_price){
		if($this->get('ccd_amount_discount')){
			return $this->get('ccd_amount_discount');
		}
		else if($this->get('ccd_percent_discount')){
			return ($this->get('ccd_percent_discount') / 100) * $full_price;
		}
		else{
			return 0;
		}
	}

	function load() {
		parent::load();
		$this->data = SingleRowFetch('ccd_coupon_codes', 'ccd_coupon_code_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new CouponCodeException(
				'This coupon_code does not exist');
		}
	}
	
	function prepare() {
		
		if(CouponCode::get_by_name($this->get('ccd_code')) && !$this->key){
			throw new CouponCodeException('That coupon code already exists.');
		}		

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {

		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this coupon_code.');
		}
		
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('ccd_coupon_code_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['ccd_coupon_code_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'ccd_coupon_codes', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['ccd_coupon_code_id'];
	}

	function soft_delete(){
		$this->set('ccd_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('ccd_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$comments = new MultiComment(
		array('coupon_code_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$comments->load();
		
		foreach ($comments as $comment){
			$comment->permanent_delete();
		}

		$sql = 'DELETE FROM ccd_coupon_codes WHERE ccd_coupon_code_id=:ccd_coupon_code_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':ccd_coupon_code_id', $this->key, PDO::PARAM_INT);
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
				CREATE SEQUENCE IF NOT EXISTS ccd_coupon_codes_ccd_coupon_code_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."ccd_coupon_codes" (
			  "ccd_coupon_code_id" int4 NOT NULL DEFAULT nextval(\'ccd_coupon_codes_ccd_coupon_code_id_seq\'::regclass), 
			  "ccd_code" varchar(64),
			  "ccd_amount_discount" numeric(10,2),
			  "ccd_percent_discount" int4,
			  "ccd_start_time" timestamp(6),
			  "ccd_end_time" timestamp(6),
			  "ccd_is_active" bool DEFAULT true,
			  "ccd_create_time" timestamp(6),
			  "ccd_published_time" timestamp(6),
			  "ccd_delete_time" timestamp(6),
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."ccd_coupon_codes" ADD CONSTRAINT "ccd_coupon_codes_pkey" PRIMARY KEY ("ccd_coupon_code_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		/*
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY ccd_coupon_codes_ccd_link ON ccd_coupon_codes USING HASH (ccd_link);';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		*/
	
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}		
	
}

class MultiCouponCode extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $coupon_code) {
			$items['('.$coupon_code->key.') '.$coupon_code->get('ccd_coupon_code')] = $coupon_code->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'ccd_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'ccd_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}			

		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'ccd_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'ccd_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM ccd_coupon_codes ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM ccd_coupon_codes
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " ccd_coupon_code_id ASC ";
			}
			else {
				if (array_key_exists('coupon_code_id', $this->order_by)) {
					$sql .= ' ccd_coupon_code_id ' . $this->order_by['coupon_code_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
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
			$child = new CouponCode($row->ccd_coupon_code_id);
			$child->load_from_data($row, array_keys(CouponCode::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
