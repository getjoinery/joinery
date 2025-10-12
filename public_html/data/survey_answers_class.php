<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

class SurveyAnswerException extends SystemBaseException {}

class SurveyAnswer extends SystemBase {	public static $prefix = 'sva';
	public static $tablename = 'sva_survey_answers';
	public static $pkey_column = 'sva_survey_answer_id';

	protected static $foreign_key_actions = [
		'sva_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
	];

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
	    'sva_survey_answer_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'sva_svy_survey_id' => array('type'=>'int4', 'is_nullable'=>false, 'required'=>true, 'unique_with'=>array (
  0 => 'sva_qst_question_id',
  1 => 'sva_usr_user_id',
)),
	    'sva_qst_question_id' => array('type'=>'int4', 'required'=>true),
	    'sva_usr_user_id' => array('type'=>'int4'),
	    'sva_answer' => array('type'=>'text'),
	    'sva_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $field_constraints = array();	

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
	protected static $model_class = 'SurveyAnswer';
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

}

?>
