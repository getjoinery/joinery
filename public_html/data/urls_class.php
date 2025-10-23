<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class UrlException extends SystemBaseException {}

class Url extends SystemBase {
	public static $prefix = 'url';
	public static $tablename = 'url_urls';
	public static $pkey_column = 'url_url_id';
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
	    'url_url_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'url_incoming' => array('type'=>'varchar(255)', 'required'=>true),
	    'url_redirect_url' => array('type'=>'varchar(255)'),
	    'url_redirect_file' => array('type'=>'varchar(255)'),
	    'url_type' => array('type'=>'int2'),
	    'url_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'url_delete_time' => array('type'=>'timestamp(6)'),
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
	protected static $model_class = 'Url';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['incoming'])) {
            $filters['url_incoming'] = [$this->options['incoming'], PDO::PARAM_STR];
        }
        
        return $this->_get_resultsv2('url_urls', $filters, $this->order_by, $only_count, $debug);
    }
}

?>
