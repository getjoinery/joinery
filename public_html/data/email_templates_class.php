<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class EmailTemplateStoreException extends SystemBaseException {}

class EmailTemplateStore extends SystemBase {	public static $prefix = 'emt';
	public static $tablename = 'emt_email_templates';
	public static $pkey_column = 'emt_email_template_id';

	const TEMPLATE_TYPE_OUTER = 1;
	const TEMPLATE_TYPE_INNER = 2;
	const TEMPLATE_TYPE_FOOTER = 3;

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
	    'emt_email_template_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'emt_name' => array('type'=>'varchar(100)', 'required'=>true),
	    'emt_type' => array('type'=>'int2'),
	    'emt_subject' => array('type'=>'varchar(255)'),
	    'emt_body' => array('type'=>'text'),
	    'emt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'emt_update_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'emt_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	private function _check_for_duplicate_email_template() {
		$count = new MultiEmailTemplateStore(array(
			'email_template_name' => $this->get('emt_name'),
		));
		
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}		

	function prepare() {
		
		//CHECK FOR DUPLICATES
		if(!$this->key){
			if($this->_check_for_duplicate_email_template()){
				throw new EmailTemplateStoreException(
				'This email_template already exists');
			}
		}

	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 10) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

	function save($debug=false) {
		if($this->key){
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_EMAIL_TEMPLATE, $this->key, $this->get('emt_body'), $this->get('emt_name'), $this->get('emt_name'));
		}
		
		parent::save($debug);
	}

}

class MultiEmailTemplateStore extends SystemMultiBase {
	protected static $model_class = 'EmailTemplateStore';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('emt_name'); 
			$items[$option_display] = $entry->get('emt_name');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['email_template_id'])) {
            $filters['emt_email_template_id'] = [$this->options['email_template_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['email_template_name'])) {
            $filters['emt_name'] = [$this->options['email_template_name'], PDO::PARAM_STR];
        }
    
        if (isset($this->options['template_type'])) {
            $filters['emt_type'] = [$this->options['template_type'], PDO::PARAM_STR];
        }

        return $this->_get_resultsv2('emt_email_templates', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
