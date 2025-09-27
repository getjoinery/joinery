<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/FieldConstraints.php'));
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

require_once(PathHelper::getIncludePath('data/page_contents_class.php'));

class PageException extends SystemBaseException {}

class Page extends SystemBase {	public static $prefix = 'pag';
	public static $tablename = 'pag_pages';
	public static $pkey_column = 'pag_page_id';
	public static $url_namespace = 'page';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(		'pac_pag_page_id' => 'delete',
		'com_pag_page_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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
	    'pag_page_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'pag_title' => array('type'=>'varchar(255)'),
	    'pag_link' => array('type'=>'varchar(255)'),
	    'pag_body' => array('type'=>'text'),
	    'pag_usr_user_id' => array('type'=>'int4'),
	    'pag_published_time' => array('type'=>'timestamp(6)'),
	    'pag_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'pag_script_filename' => array('type'=>'varchar(255)'),
	    'pag_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();	

	function get_filled_content(){

		//LOOK FOR THE SCRIPT FILE AND REPLACE CONTENT PLACEHOLDERS {{}}
		if($this->get('pag_script_filename')){
			// Include the logic file using ThemeHelper
			require_once(PathHelper::getThemeFilePath($this->get('pag_script_filename'), 'logic'));

			$content_out = $this->get('pag_body');
			
			foreach($replace_values as $var=>$val){
				$content_out = str_replace('{{'.$var.'}}', $val, $content_out);
			}

		}
		else{
			$content_out = $this->get('pag_body');
		}
		
		//LOOK FOR PAGE CONTENTS AND REPLACE
		
		$search_criteria = array();
		$search_criteria['page_id'] = $this->key;
		$page_contents = new MultiPageContent(
			$search_criteria,
			//array($sort=>$sdirection),
			//$numperpage,
			//$offset
			);	
		$numrecords = $page_contents->count_all();	
		$page_contents->load();		

		foreach($page_contents as $page_content){
			if($temp_content = $page_content->get_content()){
				$content_out = str_replace('*!**'.$page_content->get('pac_link').'**!*', $temp_content, $content_out);
			}
		}		
		
		return $content_out;
	}
	
	function save($debug=false) {
		
		//CHECK FOR DUPLICATES
		if($this->check_for_duplicate('pag_link')){
			throw new SystemAuthenticationError(
					'This page link is a duplicate.');
		}

		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_PAGE, $this->key, $this->get('pag_body'), $this->get('pag_title'), $this->get('pag_title'));			
		}
		
		parent::save($debug);
	}
	
}

class MultiPage extends SystemMultiBase {
	protected static $model_class = 'Page';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('pag_title'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function get_dropdown_array_link($include_new=FALSE) {
		$items = array();
		foreach($this as $page) {
			$items[$page->get('pag_title')] = $page->get_url();
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['link'])) {
			$filters['pag_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		if (isset($this->options['has_link'])) {
			$filters['pag_link'] = "LENGTH(pag_link) > 0";
		}

		if (isset($this->options['deleted'])) {
			$filters['pag_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['published'])) {
			$filters['pag_published_time'] = $this->options['published'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('pag_pages', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method

	// NEW: Added count_all method
}

?>
