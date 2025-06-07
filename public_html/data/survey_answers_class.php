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
		'sva_svy_survey_id' => array('type'=>'int4', 'is_nullable'=>false),
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

	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	function save($debug=false) {
		if(!$this->key){
			if($this->check_for_duplicates()){
				return FALSE;
			}			
		}
		parent::save($debug);
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
	
	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['survey_id'])) {
            $filters['sva_svy_survey_id'] = [$this->options['survey_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['question_id'])) {
            $filters['sva_qst_question_id'] = [$this->options['question_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['user_id'])) {
            $filters['sva_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }
        
        return $this->_get_resultsv2('sva_survey_answers', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new SurveyAnswer($row->sva_survey_answer_id);
            $child->load_from_data($row, array_keys(SurveyAnswer::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
