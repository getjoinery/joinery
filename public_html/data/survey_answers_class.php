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

	
class SurveyAnswerException extends SystemClassException {}

class SurveyAnswer extends SystemBase {
	public static $prefix = 'sva';
	public static $tablename = 'sva_survey_answers';
	public static $pkey_column = 'sva_survey_answer_id';
	public static $permanent_delete_actions = array(
		'sva_survey_answer_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'sva_survey_answer_id' => 'ID of the survey question',
		'sva_svy_survey_id' => 'Survey id',
		'sva_qst_question_id' => 'Question id',
		'sva_usr_user_id' => 'User who is answering',
		'sva_answer' => 'Text answer of the question',
		'sva_create_time' => 'Time of answer'
	);
	
	public static $field_specifications = array(
		'sva_survey_answer_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'sva_svy_survey_id' => array('type'=>'int4'),
		'sva_qst_question_id' => array('type'=>'int4'),
		'sva_usr_user_id' => array('type'=>'int4'),
		'sva_answer' => array('type'=>'text'),
		'sva_create_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array('sva_svy_survey_id', 'sva_qst_question_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('sva_create_time'=>'now()');		
		
	
	function check_for_duplicates() {
		
		$count = new MultiSurveyAnswer(array(
			'survey_id' => $this->get('sva_svy_survey_id'),
			'question_id' => $this->get('sva_qst_question_id'),
			'user_id' => $this->get('sva_usr_user_id'),
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return false;
	}	
	
	static function get_answer($survey_id, $question_id, $user_id) {
		
		$answer = new MultiSurveyAnswer(array(
			'survey_id' => $survey_id,
			'question_id' => $question_id,
			'user_id' => $user_id,
		));
		 
		if ($answer->count_all() > 0) {
			$answer->load();
			return $answer->get(0);
		}
		return false;
	}	
	

	function prepare() {	
		if(!$this->key){
			if($this->check_for_duplicates()){
				throw new SurveyAnswerException('This is a duplicate.');
			}
		}
		
	}

	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		// If the user's ID doesn't match , we have to make
		// sure they have admin access, otherwise denied.
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this survey_answer.');
		}
	}

	function save() {
		if(!$this->key){
			if($this->check_for_duplicates()){
				return FALSE;
			}			
		}
		parent::save();
	}
	
}

class MultiSurveyAnswer extends SystemMultiBase {
	function get_user_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$user = new User($item->get('sva_usr_user_id'), TRUE);
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
			$where_clauses[] = 'sva_svy_survey_id = ?';
			$bind_params[] = array($this->options['survey_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('question_id', $this->options)) {
			$where_clauses[] = 'sva_qst_question_id = ?';
			$bind_params[] = array($this->options['question_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'sva_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM sva_survey_answers ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM sva_survey_answers
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " sva_survey_answer_id ASC ";
			}
			else {
				if (array_key_exists('survey_answer_id', $this->order_by)) {
					$sql .= ' sva_survey_answer_id ' . $this->order_by['survey_answer_id'];
				}
				
				if (array_key_exists('question_id', $this->order_by)) {
					$sql .= ' sva_qst_question_id ' . $this->order_by['question_id'];
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
			$child = new SurveyAnswer($row->sva_survey_answer_id);
			$child->load_from_data($row, array_keys(SurveyAnswer::$fields));
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
