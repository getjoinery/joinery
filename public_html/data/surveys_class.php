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

class SurveyException extends SystemClassException {}

class Survey extends SystemBase {

	public static $fields = array(
		'svy_survey_id' => 'ID of the survey',
		'svy_name' => 'The survey',
		'svy_edited_time' => 'Last edit',
		'svy_create_time' => 'Time Created',
		'svy_delete_time' => 'Time of deletion',
	);

	public static $required_fields = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'svy_create_time' => 'now()', 
	'svy_edited_time' => 'now()'
	);	

	static function check_if_exists($key) {
		$data = SingleRowFetch('svy_surveys', 'svy_survey_id',
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
		$this->data = SingleRowFetch('svy_surveys', 'svy_survey_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new SurveyException(
				'This survey does not exist');
		}
	}
	
	function prepare() {
		
	}	
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('svy_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this survey.');
			}
		}
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('svy_survey_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['svy_survey_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'svy_surveys', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['svy_survey_id'];
	}

	function soft_delete(){
		$this->set('svy_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('svy_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		require_once($siteDir . '/data/survey_answers_class.php');
		$survey_answers = new SurveyAnswer(
		array('survey_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$survey_answers->load();
		
		foreach ($survey_answers as $survey_answer){
			$survey_answer->permanent_delete();
		}

		require_once($siteDir . '/data/survey_questions_class.php');
		$survey_questions = new SurveyAnswer(
		array('survey_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$survey_questions->load();
		
		foreach ($survey_questions as $survey_question){
			$survey_question->permanent_delete();
		}


		$sql = 'DELETE FROM svy_surveys WHERE svy_survey_id=:svy_survey_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':svy_survey_id', $this->key, PDO::PARAM_INT);
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
				CREATE SEQUENCE IF NOT EXISTS svy_surveys_svy_survey_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."svy_surveys" (
			  "svy_survey_id" int4 NOT NULL DEFAULT nextval(\'svy_surveys_svy_survey_id_seq\'::regclass),
			  "svy_name" varchar(255) COLLATE "pg_catalog"."default",
			  "svy_edited_time" timestamp(6),
			  "svy_create_time" timestamp(6),
			  "svy_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."svy_surveys" ADD CONSTRAINT "svy_surveys_pkey" PRIMARY KEY ("svy_survey_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		/*
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY svy_surveys_svy_link ON svy_surveys USING HASH (svy_link);';
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

class MultiSurvey extends SystemMultiBase {


	function get_survey_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $survey) {
			$items['('.$survey->key.') '.$survey->get('svy_title')] = $survey->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'svy_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM svy_surveys ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM svy_surveys
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " svy_survey_id ASC ";
			}
			else {
				if (array_key_exists('survey_id', $this->order_by)) {
					$sql .= ' svy_survey_id ' . $this->order_by['survey_id'];
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
			$child = new Survey($row->svy_survey_id);
			$child->load_from_data($row, array_keys(Survey::$fields));
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
