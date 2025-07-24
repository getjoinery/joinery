<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class UrlException extends SystemClassException {}

class Url extends SystemBase {
	public static $prefix = 'url';
	public static $tablename = 'url_urls';
	public static $pkey_column = 'url_url_id';
	public static $permanent_delete_actions = array(	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(		'url_incoming' => 'Incoming url',
		'url_redirect_url' => 'Url to redirect to',
		'url_redirect_file' => 'File to load',
		'url_type' => 'Type of redirect - 301, 302, etc',
		'url_create_time' => 'Time added',
		'url_delete_time' => 'Time deleted',
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
		'url_url_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'url_incoming' => array('type'=>'varchar(255)'),
		'url_redirect_url' => array('type'=>'varchar(255)'),
		'url_redirect_file' => array('type'=>'varchar(255)'),
		'url_type' => array('type'=>'int2'),
		'url_create_time' => array('type'=>'timestamp(6)'),
		'url_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array('url_incoming');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('url_create_time' => 'now()'
		);		
	

	
	function get_type_text() {
		if($this->get('url_type') == 301){
			return 'HTTP/1.1 301 Moved Permanently';
		}
		else if($this->get('url_type') == 302){
			return 'HTTP/1.1 302 Found';
		}		
	}
	
	
	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

}

class MultiUrl extends SystemMultiBase {

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['incoming'])) {
            $filters['url_incoming'] = [$this->options['incoming'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('url_urls', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new Url($row->url_url_id);
            $child->load_from_data($row, array_keys(Url::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }
}


?>
