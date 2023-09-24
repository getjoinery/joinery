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



class EmailTemplateStoreException extends SystemClassException {}

class EmailTemplateStore extends SystemBase {
	public static $prefix = 'emt';
	public static $tablename = 'emt_email_templates';
	public static $pkey_column = 'emt_email_template_id';
	public static $permanent_delete_actions = array(
		'emt_email_template_id' => 'delete',
		'mlt_emt_email_template_id' => 'prevent'
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	const TEMPLATE_TYPE_OUTER = 1;
	const TEMPLATE_TYPE_INNER = 2;
	const TEMPLATE_TYPE_FOOTER = 3;

	public static $fields = array(
		'emt_email_template_id' => 'ID of the email_template',
		'emt_name' => 'Name',
		'emt_type' => 'Type of template - outer, inner, footer',
		'emt_body' => 'Body of the template',
		'emt_create_time' => 'Created',
		'emt_update_time' => 'Updated',
		'emt_delete_time' => 'Is this email_template deleted?',
	);

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
				'Current user does not have permission to edit this entry in '. $this->tablename);
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

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('email_template_id', $this->options)) {
			$where_clauses[] = 'emt_email_template_id = ?';
			$bind_params[] = array($this->options['email_template_id'], PDO::PARAM_INT);
		}		

		if (array_key_exists('email_template_name', $this->options)) {
			$where_clauses[] = 'emt_name = ?';
			$bind_params[] = array($this->options['email_template_name'], PDO::PARAM_STR);
		}	
	
		if (array_key_exists('template_type', $this->options)) {
			$where_clauses[] = 'emt_type = ?';
			$bind_params[] = array($this->options['template_type'], PDO::PARAM_STR);
		}
	
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM emt_email_templates ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM emt_email_templates
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " emt_email_template_id ASC ";
			}
			else {
				if (array_key_exists('email_template_id', $this->order_by)) {
					$sql .= ' emt_email_template_id ' . $this->order_by['email_template_id'];
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
			$child = new EmailTemplateStore($row->emt_email_template_id);
			$child->load_from_data($row, array_keys(EmailTemplateStore::$fields));
			$this->add($child);
		}
	}

}


?>
