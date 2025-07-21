<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class ActivationCodeException extends SystemClassException {}

class ActivationCode extends SystemBase {
	public static $prefix = 'act';
	public static $tablename = 'act_activation_codes';
	public static $pkey_column = 'act_activation_code_id';
	public static $permanent_delete_actions = array(
		'act_activation_code_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'act_activation_code_id' => 'ID of the activation_code',
		'act_usr_email' => 'Email of the user',
		'act_code' => 'The code',
		'act_expires_time' => 'Code expires at this time',
		'act_usr_user_id' => 'User attached to the code',
		'act_purpose' => 'Purpose',
		'act_created_time' => 'Created at',
		'act_phn_phone_number_id' => 'Phone number for text messages',
		'act_deleted' => 'Is it deleted',
	);

	public static $field_specifications = array(
		'act_activation_code_id' => array('type'=>'int8', 'serial'=>true),
		'act_usr_email' => array('type'=>'varchar(128)'),
		'act_code' => array('type'=>'varchar(64)'),
		'act_expires_time' => array('type'=>'timestamp(6)'),
		'act_usr_user_id' => array('type'=>'int4'),
		'act_purpose' => array('type'=>'int2'),
		'act_created_time' => array('type'=>'timestamp(6)'),
		'act_phn_phone_number_id' => array('type'=>'int4'),
		'act_deleted' => array('type'=>'bool'),
	);


	public static $required_fields = array('act_code');
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('act_deleted' => false, 'act_created_time' => 'now()', 'act_purpose' => 0);
	

	
}

class MultiActivationCode extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['code'])) {
            $filters['act_code'] = [$this->options['code'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('act_activation_codes', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new ActivationCode($row->act_activation_code_id);
			$child->load_from_data($row, array_keys(ActivationCode::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>