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

class EmailRecipientGroupException extends SystemClassException {}

class EmailRecipientGroup extends SystemBase {
	public static $prefix = 'erc';
	public static $tablename = 'erg_email_recipient_groups';
	public static $pkey_column = 'erg_email_recipient_group_id';
	public static $permanent_delete_actions = array(
		'erg_email_recipient_group_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	

	public static $fields = array(
		'erg_email_recipient_group_id' => 'EmailRecipientGroup id',
		'erg_grp_group_id' => 'Group for recipients to be added',
		'erg_evt_event_id' => 'Event for recipients to be added',
		'erg_eml_email_id' => 'Email foreign key',
		'erg_operation' => 'Add or remove'
	);
	
	public static $field_specifications = array(
		'erg_email_recipient_group_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'erg_grp_group_id' => array('type'=>'int4'),
		'erg_evt_event_id' => array('type'=>'int4'),
		'erg_eml_email_id' => array('type'=>'int4'),
		'erg_operation' => array('type'=>'varchar(6)'),
	);

	public static $required_fields = array(
		'erg_eml_email_id');
		
	public static $zero_variables = array();

	public static $field_constraints = array();	
	
	public static $initial_default_values = array();
	
	
	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}
	
}


class MultiEmailRecipientGroup extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('group_id', $this->options)) {
			$where_clauses[] = 'erg_grp_group_id = ?';
			$bind_params[] = array($this->options['group_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('email_id', $this->options)) {
			$where_clauses[] = 'erg_eml_email_id = ?';
			$bind_params[] = array($this->options['email_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'erg_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('operation', $this->options)) {
			$where_clauses[] = 'erg_operation = ?';
			$bind_params[] = array($this->options['operation'], PDO::PARAM_STR);
		}		

		if (array_key_exists('sent', $this->options)) {
			if ($this->options['sent']) { 
				$where_clauses[] = 'erg_sent_time IS NOT NULL';
			} else { 
				$where_clauses[] = 'erg_sent_time IS NULL';
			}
		}
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM erg_email_recipient_groups ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM erg_email_recipient_groups
				' . $where_clause . '
				ORDER BY ';

			if (empty($this->order_by)) {
				$sql .= " erg_email_recipient_group_id ASC ";
			}
			else {
				if (array_key_exists('email_recipient_group_id', $this->order_by)) {
					$sql .= ' erg_email_recipient_group_id ' . $this->order_by['email_recipient_group_id'];
				}	
				else if (array_key_exists('email_id', $this->order_by)) {
					$sql .= ' erg_eml_email_id ' . $this->order_by['email_id'];
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
			$child = new EmailRecipientGroup($row->erg_email_recipient_group_id);
			$child->load_from_data($row, array_keys(EmailRecipientGroup::$fields));
			$this->add($child);
		}
	}

}

?>
