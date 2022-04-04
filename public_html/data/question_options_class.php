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

require_once($siteDir . '/data/content_versions_class.php');
require_once($siteDir . '/data/groups_class.php');

class QuestionOptionException extends SystemClassException {}

class QuestionOption extends SystemBase {

	public static $fields = array(
		'qop_question_option_id' => 'ID of the question_option',
		'qop_qst_question_id' => 'Question id for the options',
		'qop_question_option_label' => 'The question_option',
		'qop_question_option_value' => 'The coded value',
		'qop_edited_time' => 'Last edit',
		'qop_create_time' => 'Time Created',
	);


	public static $required_fields = array(
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'qop_create_time' => 'now()', 
	'qop_edited_time' => 'now()'
	);	

	static function check_if_exists($key) {
		$data = SingleRowFetch('qop_question_options', 'qop_question_option_id',
			$key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($data === NULL) {
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	

	function load($debug = false) {
		parent::load();
		$this->data = SingleRowFetch('qop_question_options', 'qop_question_option_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new QuestionOptionException(
				'This question_option does not exist');
		}
	}
	
	function prepare() {
		
	}	
	
	
	function authenticate_write($session, $other_data=NULL) {
		// If the user's ID doesn't match , we have to make
		// sure they have admin access, otherwise denied.
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this question_option.');
		}
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('qop_question_option_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['qop_question_option_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'qop_question_options', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['qop_question_option_id'];
	}

	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'DELETE FROM qop_question_options WHERE qop_question_option_id=:qop_question_option_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':qop_question_option_id', $this->key, PDO::PARAM_INT);
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
				CREATE SEQUENCE IF NOT EXISTS qop_question_options_qop_question_option_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."qop_question_options" (
			  "qop_question_option_id" int4 NOT NULL DEFAULT nextval(\'qop_question_options_qop_question_option_id_seq\'::regclass),
			  "qop_qst_question_id" int4 NOT NULL,
			  "qop_question_option_label" varchar(255) COLLATE "pg_catalog"."default",
			  "qop_question_option_value" varchar(255) COLLATE "pg_catalog"."default",
			  "qop_edited_time" timestamp(6),
			  "qop_create_time" timestamp(6),
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."qop_question_options" ADD CONSTRAINT "qop_question_options_pkey" PRIMARY KEY ("qop_question_option_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		/*
		try{		
			$sql = 'CREATE INDEX CONCURRENTLY qop_question_options_qop_link ON qop_question_options USING HASH (qop_link);';
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

class MultiQuestionOption extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $question_option) {
			$items[$question_option->get('qop_question_option_label')] = $question_option->get('qop_question_option_value');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'qop_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
		
		if (array_key_exists('question_id', $this->options)) {
			$where_clauses[] = 'qop_qst_question_id = ?';
			$bind_params[] = array($this->options['question_id'], PDO::PARAM_STR);
		}			

		if (array_key_exists('published', $this->options)) {
		 	$where_clauses[] = 'qop_is_published = ' . ($this->options['published'] ? 'TRUE' : 'FALSE');
		}

				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM qop_question_options ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM qop_question_options
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " qop_question_option_id ASC ";
			}
			else {
				if (array_key_exists('question_option_id', $this->order_by)) {
					$sql .= ' qop_question_option_id ' . $this->order_by['question_option_id'];
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new QuestionOption($row->qop_question_option_id);
			$child->load_from_data($row, array_keys(QuestionOption::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}


?>
