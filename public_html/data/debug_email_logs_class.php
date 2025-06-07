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

class DebugEmailLogException extends SystemClassException {}

class DebugEmailLog extends SystemBase {
	public static $prefix = 'del';
	public static $tablename = 'del_debug_email_logs';
	public static $pkey_column = 'del_debug_email_log_id';
	public static $permanent_delete_actions = array(
		'del_debug_email_log_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'del_debug_email_log_id' => 'ID of the debug_email_log',
		'del_subject' => 'subject of the email',
		'del_recipient_email' => 'recipient email',
		'del_body' => 'Body of the email',
		'del_create_time' => 'Time added',
	);

	public static $field_specifications = array(
		'del_debug_email_log_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'del_subject' => array('type'=>'varchar(255)'),
		'del_recipient_email' => array('type'=>'varchar(255)'),
		'del_body' => array('type'=>'text'),
		'del_create_time' =>  array('type'=>'timestamp(6)'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'del_create_time'=> 'now()',);
	
}

class MultiDebugEmailLog extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['del_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['event'])) {
            $filters['del_event'] = [$this->options['event'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('del_debug_email_logs', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new DebugEmailLog($row->del_debug_email_log_id);
			$child->load_from_data($row, array_keys(DebugEmailLog::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>