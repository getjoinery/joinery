<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemBase.php');
PathHelper::requireOnce('includes/Validator.php');

class PageContentException extends SystemBaseException {}

class PageContent extends SystemBase {	public static $prefix = 'pac';
	public static $tablename = 'pac_page_contents';
	public static $pkey_column = 'pac_page_content_id';
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
	    'pac_page_content_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pac_pag_page_id' => array('type'=>'int4'),
	    'pac_com_component_id' => array('type'=>'int4'),
	    'pac_location_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'pac_title' => array('type'=>'varchar(255)'),
	    'pac_link' => array('type'=>'varchar(255)', 'required'=>true),
	    'pac_usr_user_id' => array('type'=>'int4'),
	    'pac_body' => array('type'=>'text'),
	    'pac_is_published' => array('type'=>'bool', 'default'=>false),
	    'pac_published_time' => array('type'=>'timestamp(6)'),
	    'pac_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'pac_script_filename' => array('type'=>'varchar(255)'),
	    'pac_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	function get_content(){
		if($this->get('pac_published_time') && !$this->get('pac_delete_time')){
			return $this->get('pac_body');
		}
	}
	
	function get_filled_content(){

		//LOOK FOR THE SCRIPT FILE AND REPLACE CONTENT PLACEHOLDERS {{}}
		if($this->get('pac_script_filename')){
			// Include the logic file using ThemeHelper
			ThemeHelper::includeThemeFile('logic/' . $this->get('pac_script_filename'));

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
	protected static $model_class = 'PageContent';

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

	// NEW: Added count_all method

}

?>
