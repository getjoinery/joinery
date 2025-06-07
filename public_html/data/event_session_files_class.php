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

class EventSessionFileException extends SystemClassException {}

class EventSessionFile extends SystemBase {
	public static $prefix = 'esf';
	public static $tablename = 'esf_event_session_files';
	public static $pkey_column = 'esf_event_session_file_id';
	public static $permanent_delete_actions = array(
		'esf_event_session_file_id' => 'delete',		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'esf_event_session_file_id' => 'ID of the event_session_file',
		'esf_evs_event_session_id' => 'see above',
		'esf_fil_file_id' => 'User this event_session_file is associated with',
	);

	public static $field_specifications = array(
		'esf_event_session_file_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'esf_evs_event_session_id' => array('type'=>'int4'),
		'esf_fil_file_id' => array('type'=>'int4'),
	);
			
	public static $required_fields = array('esf_evs_event_session_id', 'esf_fil_file_id');
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array();
	

	
}

class MultiEventSessionFile extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['file_id'])) {
            $filters['esf_fil_file_id'] = [$this->options['file_id'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('esf_event_session_files', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new EventSessionFile($row->esf_event_session_file_id);
			$child->load_from_data($row, array_keys(EventSessionFile::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>