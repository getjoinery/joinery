<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

class SurveyQuestionException extends SystemBaseException {}

class SurveyQuestion extends SystemBase {	public static $prefix = 'srq';
	public static $tablename = 'srq_survey_questions';
	public static $pkey_column = 'srq_survey_question_id';

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'srq_survey_question_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'srq_svy_survey_id' => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true),
	    'srq_qst_question_id' => array('type'=>'int4', 'required'=>true),
	    'srq_order' => array('type'=>'int4'),
	    'srq_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

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
	protected static $model_class = 'SurveyQuestion';
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

}

?>
