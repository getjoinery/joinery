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


class CtldFilterException extends SystemClassException {}

class CtldFilter extends SystemBase {

	public static $prefix = 'cdf';
	public static $tablename = 'cdf_ctldfilters';
	public static $pkey_column = 'cdf_ctldfilter_id';
	public static $permanent_delete_actions = array(
		'cdf_ctldfilter_id' => 'delete', 
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'cdf_ctldfilter_id' => 'ID of the ctldfilter',
		'cdf_cdp_ctldprofile_id' => 'Foreign key to profile',
		'cdf_filter_pk' => 'Primary key at controld',
		'cdf_is_active' => 'Is it active?',
	);

	public static $field_specifications = array(
		'cdf_ctldfilter_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'cdf_cdp_ctldprofile_id' => array('type'=>'varchar(64)'),
		'cdf_filter_pk' => array('type'=>'varchar(32)'),
		'cdf_is_active' => array('type'=>'int2'),
	);
			
	public static $required_fields = array();

	public static $field_constraints = array(
		/*'cdf_code' => array(
			array('WordLength', 0, 64),
			'NoCaps',
			),*/
	);	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	);	

	
}

class MultiCtldFilter extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $ctldfilter) {
			$items['('.$ctldfilter->key.') '.$ctldfilter->get('cdf_filter_pk')] = $ctldfilter->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['filter'])) {
            $filters['cdf_filter_pk'] = [$this->options['filter'], PDO::PARAM_STR];
        }
        
        if (isset($this->options['profile_id'])) {
            $filters['cdf_cdp_ctldprofile_id'] = [$this->options['profile_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['active'])) {
            $filters['cdf_is_active'] = $this->options['active'] ? "= 1" : "= 0";
        }
        
        return $this->_get_resultsv2('cdf_ctldfilters', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new CtldFilter($row->cdf_ctldfilter_id);
            $child->load_from_data($row, array_keys(CtldFilter::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}


?>
