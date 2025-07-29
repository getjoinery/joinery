<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');



class EmailTemplateStoreException extends SystemClassException {}

class EmailTemplateStore extends SystemBase {
	public static $prefix = 'emt';
	public static $tablename = 'emt_email_templates';
	public static $pkey_column = 'emt_email_template_id';
	public static $permanent_delete_actions = array(		'mlt_emt_email_template_id' => 'prevent'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	const TEMPLATE_TYPE_OUTER = 1;
	const TEMPLATE_TYPE_INNER = 2;
	const TEMPLATE_TYPE_FOOTER = 3;

	public static $fields = array(
		'emt_email_template_id' => 'Primary key - EmailTemplateStore ID',
		'emt_name' => 'Name',
		'emt_type' => 'Type of template - outer, inner, footer',
		'emt_body' => 'Body of the template',
		'emt_create_time' => 'Created',
		'emt_update_time' => 'Updated',
		'emt_delete_time' => 'Is this email_template deleted?',
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
		'emt_email_template_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'emt_name' => array('type'=>'varchar(100)'),
		'emt_type' => array('type'=>'int2'),
		'emt_body' => array('type'=>'text'),
		'emt_create_time' => array('type'=>'timestamp(6)'),
		'emt_update_time' => array('type'=>'timestamp(6)'),
		'emt_delete_time' => array('type'=>'timestamp(6)'),
	);
				
	public static $required_fields = array(
		'emt_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'emt_create_time' => 'now()', 
		'emt_update_time' => 'now()'
		);		
	
	
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

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new EmailTemplateStore($row->emt_email_template_id);
			$child->load_from_data($row, array_keys(EmailTemplateStore::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
