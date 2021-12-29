<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
	

class PointException extends SystemClassException {}
class PointNotSentException extends PointException {};

class Point extends SystemBase {

	const POINT_TYPE_GALAXY = 1;
	const POINT_TYPE_STAR = 2;
	const POINT_TYPE_PLANET = 3;

	public static $fields = array(
		'pnt_point_id' => 'Point id',
		'pnt_name' => 'Point name',
		'pnt_clan' => 'Type',
		'pnt_sein' => 'Parent id',
		'pnt_is_active' => 'If active',
		'pnt_is_booted' => 'If booted'
	);
	
	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array();

	function load() {
		parent::load();
		$this->data = SingleRowFetch('pnt_points', 'pnt_point_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new PointException(
				'This point number does not exist');
		}
	}

	function prepare() {

	}	
	
	function authenticate_write($session, $other_data=NULL) {

	}

	public static function get_by_id($pnt_point_id) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		//SET ALL DEFAULT FOR THIS USER TO ZERO
		$sql = "SELECT pnt_point_id FROM pnt_points
			WHERE pnt_point_id = :pnt_point_id";

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':pnt_point_id', $pnt_point_id, PDO::PARAM_INT);
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

		return new Point($r->pnt_point_id, TRUE);
	}	
	
	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('pnt_point_id' => $this->key);
			// Editing an existing
		} else {
			$p_keys = NULL;
			// Creating a new
			unset($rowdata['pnt_point_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'pnt_points', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['pnt_point_id'];
	}

	/*
	function soft_delete(){
		$this->set('pnt_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('pnt_delete_time', NULL);
		$this->save();	
		return true;
	}
	*/

	/*
	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		
		//if(!$dblink->inTransaction()){
		//	$dblink->beginTransaction();
		//	$this_transaction = true;
		//}
		

		$sql = 'DELETE FROM pnt_points WHERE pnt_point_id=:pnt_point_id';

		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':pnt_point_id', $this->key, PDO::PARAM_INT);
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
	*/
	
	static function InitDB($mode='structure'){
	
		/*
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS pnt_points_pnt_point_id_seq
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
		*/		
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."pnt_points" (
			  "pnt_point_id" int4,
			  "pnt_name" varchar(14),
			  "pnt_clan" int(2),
			  "pnt_sein" int(4),
			  "pnt_is_active" bool,
			  "pnt_is_booted" bool
			);';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."pnt_points" ADD CONSTRAINT "pnt_points_pkey" PRIMARY KEY ("pnt_point_id");';
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

class MultiPoint extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('name', $this->options)) {
			$where_clauses[] = 'pnt_name = ?';
			$bind_params[] = array($this->options['name'], PDO::PARAM_STR);
		}
	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM pnt_points ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM pnt_points
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " pnt_point_id ASC ";
			}
			else {
				if (array_key_exists('point_id', $this->order_by)) {
					$sql .= ' pnt_point_id ' . $this->order_by['point_id'];
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
			$child = new Point($row->pnt_point_id);
			$child->load_from_data($row, array_keys(Point::$fields));
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
