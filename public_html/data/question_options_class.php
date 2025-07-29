<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/content_versions_class.php');
PathHelper::requireOnce('data/groups_class.php');

class QuestionOptionException extends SystemClassException {}

class QuestionOption extends SystemBase {
	public static $prefix = 'qop';
	public static $tablename = 'qop_question_options';
	public static $pkey_column = 'qop_question_option_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'qop_question_option_id' => 'Primary key - QuestionOption ID',
		'qop_qst_question_id' => 'Question id for the options',
		'qop_question_option_label' => 'The question_option',
		'qop_question_option_value' => 'The coded value',
		'qop_edited_time' => 'Last edit',
		'qop_create_time' => 'Time Created',
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
		'qop_question_option_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'qop_qst_question_id' => array('type'=>'int4'),
		'qop_question_option_label' => array('type'=>'varchar(255)'),
		'qop_question_option_value' => array('type'=>'varchar(255)'),
		'qop_edited_time' => array('type'=>'timestamp(6)'),
		'qop_create_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('qop_qst_question_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'qop_create_time' => 'now()', 
	'qop_edited_time' => 'now()'
	);	

	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		// Note: Invalid filters removed - qop_usr_user_id and qop_is_published fields do not exist in model

		if (isset($this->options['question_id'])) {
			$filters['qop_qst_question_id'] = [$this->options['question_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('qop_question_options', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new QuestionOption($row->qop_question_option_id);
			$child->load_from_data($row, array_keys(QuestionOption::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}
}


?>
