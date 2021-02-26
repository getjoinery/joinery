<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/content_versions_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/groups_class.php');

class AnswerException extends SystemClassException {}

class Answer extends SystemBase {

	public static $fields = array(
		'ans_answer_id' => 'ID of the answer',
		'ans_qst_question_id' => 'Question id that was answered',
		'ans_answer' => 'The answer',
		'ans_usr_user_id' => 'User this answer is associated with',
		'ans_is_deleted' => 'Is this answer deleted?',
		'ans_edited_time' => 'Last edit',
		'ans_create_time' => 'Time Created',
	);

	public static $constants = array();

	public static $required = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $default_values = array(
	'ans_create_time' => 'now()', 
	'ans_edited_time' => 'now()', 
	'ans_is_deleted' => false
	);	

	static function check_if_exists($key) {
		$data = SingleRowFetch('ans_answers', 'ans_answer_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	

	function load() {
		parent::load();
		$this->data = SingleRowFetch('ans_answers', 'ans_answer_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new AnswerException(
				'This answer does not exist');
		}
	}
	
	function prepare() {
		if ($this->data === NULL) {
			throw new AnswerException('This has no data.');
		}


		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					$this->set($variable, 0);
				}
			}

		}
		
		if ($this->key === NULL) {
			foreach (static::$default_values as $variable=>$value) {
				if ($this->key === NULL && $this->get($variable) === NULL) { 
					$this->set($variable, $value);
				}
			}
		}		

		CheckRequiredFields($this, self::$required, self::$fields);

		foreach (self::$field_constraints as $field => $constraints) {
			foreach($constraints as $constraint) {
				if (gettype($constraint) == 'array') {
					$params = array();
					$params[] = self::$fields[$field];
					$params[] = $this->get($field);
					for($i=1;$i<count($constraint);$i++) {
						$params[] = $constraint[$i];
					}
					call_user_func_array($constraint[0], $params);
				} else {
					call_user_func($constraint, self::$fields[$field], $this->get($field));
				}
			}
		}		

	}	
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('ans_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this answer.');
			}
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('ans_answer_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['ans_answer_id']);
			$rowdata['ans_create_time'] = 'now()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'ans_answers', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['ans_answer_id'];
	}

	function soft_delete(){
		$this->set('ans_is_deleted', TRUE);
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('ans_is_deleted', FALSE);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM ans_answers WHERE ans_answer_id=:ans_answer_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':ans_answer_id', $this->key, PDO::PARAM_INT);
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
				CREATE SEQUENCE IF NOT EXISTS ans_answers_ans_answer_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."ans_answers" (
			  "ans_answer_id" int4 NOT NULL DEFAULT nextval(\'ans_answers_ans_answer_id_seq\'::regclass),
			  "ans_qst_question_id" int4 NOT NULL,
			  "ans_usr_user_id" int4,
			  "ans_answer" text COLLATE "pg_catalog"."default",
			  "ans_edited_time" timestamp(6),
			  "ans_is_deleted" bool DEFAULT false,
			  "ans_create_time" timestamp(6),
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."ans_answers" ADD CONSTRAINT "ans_answers_pkey" PRIMARY KEY ("ans_answer_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		/*
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY ans_answers_ans_link ON ans_answers USING HASH (ans_link);';
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

class MultiAnswer extends SystemMultiBase {


	function get_answer_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $answer) {
			$items['('.$answer->key.') '.$answer->get('ans_title')] = $answer->key;
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
		 	$where_clauses[] = 'ans_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'ans_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}			

		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'ans_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('deleted', $this->options)) {
		 	$where_clauses[] = 'ans_is_deleted = ' . ($this->options['deleted'] ? 'TRUE' : 'FALSE');
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM ans_answers ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM ans_answers
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " ans_answer_id ASC ";
			}
			else {
				if (array_key_exists('answer_id', $this->order_by)) {
					$sql .= ' ans_answer_id ' . $this->order_by['answer_id'];
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
			$child = new Answer($row->ans_answer_id);
			$child->load_from_data($row, array_keys(Answer::$fields));
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
