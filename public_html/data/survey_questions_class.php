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

require_once($siteDir.'/data/users_class.php');

	
class SurveyQuestionException extends SystemClassException {}

class SurveyQuestion extends SystemBase {
	public $prefix = 'srq';
	public $tablename = 'srq_survey_questions';
	public $pkey_column = 'srq_survey_question_id';
	public static $permanent_delete_actions = array(
		'srq_survey_question_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'srq_survey_question_id' => 'ID of the survey question',
		'srq_svy_survey_id' => 'Survey id',
		'srq_qst_question_id' => 'Question id',
		'srq_order' => 'Order of the questions',
		'srq_delete_time' => 'Time of deletion',
	);

	public static $required_fields = array('srq_svy_survey_id', 'srq_qst_question_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		);		
		
	
	private function _check_for_duplicates() {
		
		$count = new MultiSurveyQuestion(array(
			'survey_id' => $this->get('srq_svy_survey_id'),
			'question_id' => $this->get('srq_qst_question_id')
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}	
	
	

	function prepare() {	
		
		if(!$this->key){
			if($this->_check_for_duplicates()){
				throw new SurveyQuestionException('This is a duplicate.');
			}
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
		if(!$this->key){
			if($this->_check_for_duplicates()){
				return FALSE;
			}			
		}
		parent::save();
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
			  "srq_svy_survey_id" int4 NOT NULL,
			  "srq_qst_question_id" int4 NOT NULL,
			  "srq_order" int4,
			  "srq_delete_time" timestamp(6)
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
	
	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('survey_id', $this->options)) {
			$where_clauses[] = 'srq_svy_survey_id = ?';
			$bind_params[] = array($this->options['survey_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('question_id', $this->options)) {
			$where_clauses[] = 'srq_qst_question_id = ?';
			$bind_params[] = array($this->options['question_id'], PDO::PARAM_INT);
		}	
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'srq_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
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
			$child = new SurveyQuestion($row->srq_survey_question_id);
			$child->load_from_data($row, array_keys(SurveyQuestion::$fields));
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
