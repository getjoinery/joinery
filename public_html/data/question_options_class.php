<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/content_versions_class.php');
PathHelper::requireOnce('data/groups_class.php');

class QuestionOptionException extends SystemBaseException {}

class QuestionOption extends SystemBase {	public static $prefix = 'qop';
	public static $tablename = 'qop_question_options';
	public static $pkey_column = 'qop_question_option_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'qop_question_option_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'qop_qst_question_id' => array('type'=>'int4', 'required'=>true),
	    'qop_question_option_label' => array('type'=>'varchar(255)'),
	    'qop_question_option_value' => array('type'=>'varchar(255)'),
	    'qop_edited_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'qop_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	);

	public static $field_constraints = array();	

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiQuestionOption extends SystemMultiBase {
	protected static $model_class = 'QuestionOption';

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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		// Note: Invalid filters removed - qop_usr_user_id and qop_is_published fields do not exist in model

		if (isset($this->options['question_id'])) {
			$filters['qop_qst_question_id'] = [$this->options['question_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('qop_question_options', $filters, $this->order_by, $only_count, $debug);
	}
}

?>
