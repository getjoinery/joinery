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

require_once($siteDir . '/data/page_contents_class.php');

class PageException extends SystemClassException {}

class Page extends SystemBase {
	public static $prefix = 'pag';
	public static $tablename = 'pag_pages';
	public static $pkey_column = 'pag_page_id';
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

	function get_url($format='short') {
		if($format == 'full'){
			$settings = Globalvars::get_instance();
			return $settings->get_setting('webDir').'/page/' . $this->get('pag_link');
		}
		else{
			return '/page/' . $this->get('pag_link');
		}
	}			

	
	function create_url($input_url) {
		if($input_url){
			$tmp = $input_url;
		}
		else{
			$tmp = $this->get('pag_title');
		}
		$tmp = strtolower(str_replace(' ', '-', $tmp));
		$tmp = preg_replace("/[^a-zA-Z0-9-]/", "", $tmp);
		$tmp = preg_replace('/-{2,}/', '-', $tmp);
		
		//NO DUPLICATES
		$increment=1;
		$tmp_orig = $tmp;
		while($result = Page::get_by_link($tmp, true)){
			if($result->key == $this->key){
				//IF WE FOUND THIS ONE, IT'S OKAY
				return $tmp;
			}
			else{
				$tmp = $tmp_orig . $increment;
				$increment++;
			}
		}
		return $tmp;
	}

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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'pag_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}

		if (array_key_exists('has_link', $this->options)) {
			$where_clauses[] = 'LENGTH(pag_link) > 0';
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'pag_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM pag_pages ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM pag_pages
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " pag_page_id ASC ";
			}
			else {
				if (array_key_exists('page_id', $this->order_by)) {
					$sql .= ' pag_page_id ' . $this->order_by['page_id'];
				}			
			}
				
			$sql .= ' '.$this->generate_limit_and_offset();	

		}			
		

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		parent::load();
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Page($row->pag_page_id);
			$child->load_from_data($row, array_keys(Page::$fields));
			$this->add($child);
		}
	}
}


?>
