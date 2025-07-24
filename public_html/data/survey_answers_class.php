<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/users_class.php');

	
class SurveyAnswerException extends SystemClassException {}

class SurveyAnswer extends SystemBase {
	public static $prefix = 'sva';
	public static $tablename = 'sva_survey_answers';
	public static $pkey_column = 'sva_survey_answer_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'sva_svy_survey_id' => 'Survey id',
		'sva_qst_question_id' => 'Question id',
		'sva_usr_user_id' => 'User who is answering',
		'sva_answer' => 'Text answer of the question',
		'sva_create_time' => 'Time of answer'
	);

	
/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'sva_survey_answer_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'sva_svy_survey_id' => array('type'=>'int4', 'is_nullable'=>false, 'unique_with' => array('sva_qst_question_id', 'sva_usr_user_id')),
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
	

	// Unique constraints now handled automatically by SystemBase
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
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
