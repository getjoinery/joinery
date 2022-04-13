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

class FormErrorException extends SystemClassException {}

class FormError extends SystemBase {
	public static $prefix = 'lfe';
	public static $tablename = 'lfe_log_form_errors';
	public static $pkey_column = 'lfe_log_form_error_id';
	public static $permanent_delete_actions = array(
		'lfe_log_form_error_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'lfe_log_form_error_id' => 'ID of the lfe_log_form_error',
		'lfe_error' => 'error',
		'lfe_usr_user_id' => 'User this lfe_log_form_error is associated with',
		'lfe_log_time' => 'Time added',
		'lfe_user_agent' => 'User Agent string',
		'lfe_page' => 'The page this log form error occured on',
		'lfe_url' => 'The URL of the page this happened on',
		'lfe_form' => 'The full form',
		'lfe_context' => 'The DOM selector form the form (in case more than one form on the page)',
	);
	
	public static $field_specifications = array(
		'lfe_log_form_error_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'lfe_error' => array('type'=>'text'),
		'lfe_usr_user_id' => array('type'=>'int4'),
		'lfe_log_time' => array('type'=>'timestamp(6)'),
		'lfe_user_agent' => array('type'=>'varchar(255)'),
		'lfe_page' => array('type'=>'varchar(100)'),
		'lfe_url' =>  array('type'=>'varchar(255)'),
		'lfe_form' =>  array('type'=>'text'),
		'lfe_context' =>  array('type'=>'varchar(255)'),
	);


	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'lfe_log_time'=> 'now()',);
	

	
	function display_time($session) {
		return LibraryFunctions::convert_time(
			$this->get('lfe_log_time'), 'UTC', $session->get_timezone(), '%a, %d %b %Y %R:%S');
	}	

	public static function LogFormError($session, $request) { 
		$obj = new FormError(NULL);
		$obj->set('lfe_usr_user_id', $session->get_user_id());
		$obj->set('lfe_error', $request['messages']);
		$obj->set('lfe_log_time', 'NOW()');
		$obj->set('lfe_page', $request['page']);
		$obj->set('lfe_url', $request['url']);
		$obj->set('lfe_form', $request['formfields']);
		$obj->set('lfe_context', $request['context']);
		$obj->set('lfe_user_agent', $_SERVER['HTTP_USER_AGENT']);
		$obj->save();
	}

	
}

class MultiFormError extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (isset($this->options['user_id'])) {
		 	$where_clauses[] = 'lfe_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM lfe_log_form_errors ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM lfe_log_form_errors
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " lfe_log_form_error_id ASC ";
			}
			else {
				if (array_key_exists('log_form_error_id', $this->order_by)) {
					$sql .= ' lfe_log_form_error_id ' . $this->order_by['log_form_error_id'];
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
			$child = new FormError($row->lfe_log_form_error_id);
			$child->load_from_data($row, array_keys(FormError::$fields));
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
