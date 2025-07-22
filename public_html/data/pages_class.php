<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/Validator.php');

PathHelper::requireOnce('data/page_contents_class.php');

class PageException extends SystemClassException {}

class Page extends SystemBase {
	public static $prefix = 'pag';
	public static $tablename = 'pag_pages';
	public static $pkey_column = 'pag_page_id';
	public static $url_namespace = 'page';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(
		'pag_page_id' => 'delete',	
		'pac_pag_page_id' => 'delete',
		'com_pag_page_id' => 'null'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'pag_page_id' => 'ID of the url',
		'pag_title' => 'Name of page',
		'pag_link' => 'Link to the page',
		'pag_body' => 'Body of this page',
		'pag_usr_user_id' => 'User this page is associated with',
		'pag_published_time' => 'Time published',
		'pag_create_time' => 'Time Created',
		'pag_script_filename' => 'Filename to look for if we want to run a script before rendering',
		'pag_delete_time' => 'Time of deletion',
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
		'pag_page_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pag_title' => array('type'=>'varchar(255)'),
		'pag_link' => array('type'=>'varchar(255)'),
		'pag_body' => array('type'=>'text'),
		'pag_usr_user_id' => array('type'=>'int4'),
		'pag_published_time' => array('type'=>'timestamp(6)'),
		'pag_create_time' => array('type'=>'timestamp(6)'),
		'pag_script_filename' => array('type'=>'varchar(255)'),
		'pag_delete_time' => array('type'=>'timestamp(6)'),
	);
	
	public static $required_fields = array();

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('pag_create_time' => 'now()'
		);				


	function get_filled_content(){

		//LOOK FOR THE SCRIPT FILE AND REPLACE CONTENT PLACEHOLDERS {{}}
		if($this->get('pag_script_filename')){
			$logic_path = LibraryFunctions::get_logic_file_path($this->get('pag_script_filename'));
			require_once ($logic_path);

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
	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Page($row->pag_page_id);
			$child->load_from_data($row, array_keys(Page::$fields));
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
