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

require_once($siteDir . '/plugins/controld/includes/ControlDHelper.php');


class CtldServiceException extends SystemClassException {}

class CtldService extends SystemBase {

	public static $prefix = 'cds';
	public static $tablename = 'cds_ctldservices';
	public static $pkey_column = 'cds_ctldservice_id';
	public static $permanent_delete_actions = array(
		'cds_ctldservice_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cds_ctldservice_id' => 'ID of the ctldservice',
		'cds_cdp_ctldprofile_id' => 'Foreign key to profile',
		'cds_service_pk' => 'Primary key at controld',
		'cds_is_active' => 'Is it active?',
	);

	public static $field_specifications = array(
		'cds_ctldservice_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cds_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
		'cds_service_pk' => array('type'=>'varchar(32)'),
		'cds_is_active' => array('type'=>'int2'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cds_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	);	

	
}

class MultiCtldService extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldservice) {
			$items['('.$ctldservice->key.') '.$ctldservice->get('cds_service_pk')] = $ctldservice->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['service'])) {
            $filters['cds_service_pk'] = [$this->options['service'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['profile_id'])) {
            $filters['cds_cdp_ctldprofile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cds_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }
        
        return $this->_get_resultsv2('cds_ctldservices', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new CtldService($row->cds_ctldservice_id);
            $child->load_from_data($row, array_keys(CtldService::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
