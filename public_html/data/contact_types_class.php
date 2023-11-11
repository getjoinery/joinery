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


class ContactTypeException extends SystemClassException {}

class ContactType extends SystemBase {
	public static $prefix = 'ctt';
	public static $tablename = 'ctt_contact_types';
	public static $pkey_column = 'ctt_contact_type_id';
	public static $permanent_delete_actions = array(
		'ctt_contact_type_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

	public static $fields = array(
		'ctt_contact_type_id' => 'ID of the contact_type',
		'ctt_name' => 'The contact_type',
		'ctt_description' => 'Description of this contact type',
		'ctt_delete_time' => 'Time of deletion',
		'ctt_mailchimp_list_id' => 'If mailchimp integration, the list id of the list.',
	);

	public static $field_specifications = array(
		'ctt_contact_type_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'ctt_name' => array('type'=>'varchar(255)'),
		'ctt_description' => array('type'=>'varchar(255)'),
		'ctt_delete_time' => array('type'=>'timestamp(6)'),
		'ctt_mailchimp_list_id' => array('type'=>'varchar(255)'),
	);
	
	public static $required_fields = array('ctt_name'
		);

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(

	);	
	
	public static function ToReadable($ctt_contact_type_id){
		$contact_type = ContactType::GetByColumn('ctt_contact_type_id', (int)$ctt_contact_type_id);
		return $contact_type->get('ctt_name');
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}

class MultiContactType extends SystemMultiBase {


	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $contact_type) {
			$items[$contact_type->get('ctt_name')] = $contact_type->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'ctt_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM ctt_contact_types ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM ctt_contact_types
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " ctt_contact_type_id ASC ";
			}
			else {
				if (array_key_exists('contact_type_id', $this->order_by)) {
					$sql .= ' ctt_contact_type_id ' . $this->order_by['contact_type_id'];
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
			$child = new ContactType($row->ctt_contact_type_id);
			$child->load_from_data($row, array_keys(ContactType::$fields));
			$this->add($child);
		}
	}

}


?>
