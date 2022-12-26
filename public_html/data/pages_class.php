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

class PageException extends SystemClassException {}

class Page extends SystemBase {
	public static $prefix = 'pag';
	public static $tablename = 'pag_pages';
	public static $pkey_column = 'pag_page_id';
	public static $permanent_delete_actions = array(
		'pag_page_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'pag_page_id' => 'ID of the url',
		'pag_title' => 'Name of page',
		'pag_link' => 'Link to the page',
		'pag_published_time' => 'Time published',
		'pag_create_time' => 'Time Created',
		'pag_script_filename' => 'Filename to look for if we want to run a script before rendering',
		'pag_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'pag_page_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'pag_title' => array('type'=>'varchar(255)'),
		'pag_link' => array('type'=>'varchar(255)'),
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
	
	


}

class MultiPage extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'pag_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
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
