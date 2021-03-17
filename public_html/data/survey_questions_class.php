<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	
class SurveyQuestionException extends SystemClassException {}

class SurveyQuestion extends SystemBase {

	public static $fields = array(
		'srq_survey_question_id' => 'ID of the survey question',
		'srq_srv_survey_id' => 'Survey id',
		'srq_qst_question_id' => 'Question id',
		'srq_order' => 'Order of the questions'
	);
	
	public static $constants = array();

	public static $required = array('srq_srv_survey_id', 'srq_qst_question_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $default_values = array(
		);		
		
	
	private function _check_for_duplicates() {
		
		$count = new MultiSurveyQuestion(array(
			'survey_id' => $this->get('srq_srv_survey_id'),
			'question_id' => $this->get('srq_qst_question_id')
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}	
	
	function remove(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM srq_survey_questions WHERE srq_survey_question_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		
		$success = $q->execute();
		
		return $success;		
	}	
	

	function prepare() {	
		if ($this->data === NULL) {
			throw new SurveyQuestionException('This has no data.');
		}

		
		if(!$this->key){
			if($this->_check_for_duplicates()){
				throw new SurveyQuestionException('This is a duplicate.');
			}
		}
		

		if ($this->key === NULL) {
			foreach (static::$zero_variables as $variable) {
				if ($this->key === NULL && $this->get($variable) === NULL) {
					echo $variable;
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

	function load() {
		parent::load();
		$this->data = SingleRowFetch('srq_survey_questions', 'srq_survey_question_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new VideoException(
				'This survey_question does not exist');
		}
	}
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		// If the user's ID doesn't match , we have to make
		// sure they have admin access, otherwise denied.
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this survey_question.');
		}
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('srq_survey_question_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['srq_survey_question_id']);
			//$rowdata['srq_create_time'] = 'now()';
			
			if($this->_check_for_duplicates()){
				return FALSE;
			}
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'srq_survey_questions', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['srq_survey_question_id'];
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS srq_survey_questions_srq_survey_question_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."srq_survey_questions" (
			  "srq_survey_question_id" int4 NOT NULL DEFAULT nextval(\'srq_survey_questions_srq_survey_question_id_seq\'::regclass),
			  "srq_srv_survey_id" int4 NOT NULL,
			  "srq_qst_question_id" int4 NOT NULL,
			  "srq_order" int4;
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."srq_survey_questions" ADD CONSTRAINT "srq_survey_questions_pkey" PRIMARY KEY ("srq_survey_question_id");';
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

class MultiSurveyQuestion extends SystemMultiBase {
	function get_user_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$user = new User($item->get('srq_usr_user_id'), TRUE);
			$items[$user->display_name()] = $user->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;
	}
	
	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('survey_id', $this->options)) {
			$where_clauses[] = 'srq_srv_survey_id = ?';
			$bind_params[] = array($this->options['survey_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('question_id', $this->options)) {
			$where_clauses[] = 'srq_qst_question_id = ?';
			$bind_params[] = array($this->options['question_id'], PDO::PARAM_INT);
		}	

		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM srq_survey_questions ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM srq_survey_questions
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " srq_survey_question_id ASC ";
			}
			else {
				if (array_key_exists('survey_question_id', $this->order_by)) {
					$sql .= ' srq_survey_question_id ' . $this->order_by['survey_question_id'];
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
			$child = new SurveyQuestion($row->srq_survey_question_id);
			$child->load_from_data($row, array_keys(SurveyQuestion::$fields));
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
