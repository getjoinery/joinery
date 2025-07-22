<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

class PageContentException extends SystemClassException {}

class PageContent extends SystemBase {
	public static $prefix = 'pac';
	public static $tablename = 'pac_page_contents';
	public static $pkey_column = 'pac_page_content_id';
	public static $permanent_delete_actions = array(
		'pac_page_content_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	
	
	public static $fields = array(
		'pac_page_content_id' => 'ID of the page_content',
		'pac_pag_page_id' => 'ID of the page the content is part of',
		'pac_com_component_id' => 'ID of the component that the page content is part of',
		'pac_location_name' => 'Location of the content',
		'pac_title' => 'PageContent Title',
		'pac_link' => 'Link of the page_content, if it is a standalone page',
		'pac_usr_user_id' => 'User this page_content is associated with',
		'pac_body' => 'Body of the page_content',
		'pac_is_published' => 'Is this page_content published?',
		'pac_published_time' => 'Time published',
		'pac_create_time' => 'Time Created',
		'pac_script_filename' => 'Filename to look for if we want to run a script before rendering',
		'pac_delete_time' => 'Time of deletion',
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
		'pac_page_content_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pac_pag_page_id' => array('type'=>'int4'),
		'pac_com_component_id' => array('type'=>'int4'),
		'pac_location_name' => array('type'=>'varchar(255)'),
		'pac_title' => array('type'=>'varchar(255)'),
		'pac_link' => array('type'=>'varchar(255)'),
		'pac_usr_user_id' => array('type'=>'int4'),
		'pac_body' => array('type'=>'text'),
		'pac_is_published' => array('type'=>'bool'),
		'pac_published_time' => array('type'=>'timestamp(6)'),
		'pac_create_time' => array('type'=>'timestamp(6)'),
		'pac_script_filename' => array('type'=>'varchar(255)'),
		'pac_delete_time' => array('type'=>'timestamp(6)'),
	);
			 
	public static $required_fields = array(
		'pac_link',
		'pac_location_name',
	);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'pac_create_time' => 'now()', 'pac_is_published' => FALSE
		);
		
	
	function get_content(){
		if($this->get('pac_published_time') && !$this->get('pac_delete_time')){
			return $this->get('pac_body');
		}
	}
	
	function get_filled_content(){

		//LOOK FOR THE SCRIPT FILE AND REPLACE CONTENT PLACEHOLDERS {{}}
		if($this->get('pac_script_filename')){
			$logic_path = LibraryFunctions::get_logic_file_path($this->get('pac_script_filename'));
			require_once ($logic_path);

			$content_out = $this->get_content();
			
			foreach($replace_values as $var=>$val){
				$content_out = str_replace('{{'.$var.'}}', $val, $content_out);
			}

			return $content_out;
		}
		else{
			return $this->get('pac_body');
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

	function save($debug=false) {
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate('pac_link')){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
		}
		
		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_PAGE_CONTENT, $this->key, $this->get('pac_body'), $this->get('pac_title'), $this->get('pac_title'));			
		}
		parent::save($debug);
	}

	
}

class MultiPageContent extends SystemMultiBase {



	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['user_id'])) {
			$filters['pac_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['page_id'])) {
			$filters['pac_pag_page_id'] = [$this->options['page_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['link'])) {
			$filters['pac_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		if (isset($this->options['has_link'])) {
			$filters['pac_link'] = "LENGTH(pac_link) > 0";
		}

		if (isset($this->options['deleted'])) {
			$filters['pac_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['published'])) {
			$filters['pac_is_published'] = $this->options['published'] ? "= TRUE" : "= FALSE";
		}

		return $this->_get_resultsv2('pac_page_contents', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method
	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new PageContent($row->pac_page_content_id);
			$child->load_from_data($row, array_keys(PageContent::$fields));
			$this->add($child);
		}
	}

	// NEW: Added count_all method
	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
