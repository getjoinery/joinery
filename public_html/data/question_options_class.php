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
	public static $prefix = 'qop';
	public static $tablename = 'qop_question_options';
	public static $pkey_column = 'qop_question_option_id';
	public static $permanent_delete_actions = array(
		'qop_question_option_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'qop_question_option_id' => 'ID of the question_option',
		'qop_qst_question_id' => 'Question id for the options',
		'qop_question_option_label' => 'The question_option',
		'qop_question_option_value' => 'The coded value',
		'qop_edited_time' => 'Last edit',
		'qop_create_time' => 'Time Created',
	);

	public static $field_specifications = array(
		'qop_question_option_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'qop_qst_question_id' => array('type'=>'int4'),
		'qop_question_option_label' => array('type'=>'varchar(255)'),
		'qop_question_option_value' => array('type'=>'varchar(255)'),
		'qop_edited_time' => array('type'=>'timestamp(6)'),
		'qop_create_time' => array('type'=>'timestamp(6)'),
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
	
	
	function authenticate_write($session, $other_data=NULL) {
		// If the user's ID doesn't match , we have to make
		// sure they have admin access, otherwise denied.
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this question_option.');
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
