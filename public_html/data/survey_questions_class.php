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

	
class SurveyQuestionException extends SystemClassException {}

class SurveyQuestion extends SystemBase {
	public static $prefix = 'srq';
	public static $tablename = 'srq_survey_questions';
	public static $pkey_column = 'srq_survey_question_id';
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
		'srq_survey_question_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'srq_svy_survey_id' => array('type'=>'int4', 'is_nullable'=>false),
		'srq_qst_question_id' => array('type'=>'int4'),
		'srq_order' => array('type'=>'int4'),
		'srq_delete_time' => array('type'=>'timestamp(6)'),
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

	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	function save($debug=false) {
		if(!$this->key){
			if($this->_check_for_duplicates()){
				return FALSE;
			}			
		}
		parent::save($debug);
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
	
	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['survey_id'])) {
            $filters['srq_svy_survey_id'] = [$this->options['survey_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['question_id'])) {
            $filters['srq_qst_question_id'] = [$this->options['question_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['deleted'])) {
            $filters['srq_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        return $this->_get_resultsv2('srq_survey_questions', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new SurveyQuestion($row->srq_survey_question_id);
            $child->load_from_data($row, array_keys(SurveyQuestion::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
