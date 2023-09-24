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

require_once($siteDir.'/data/users_class.php');

	
class MailingListRegistrantException extends SystemClassException {}

class MailingListRegistrant extends SystemBase {
	public static $prefix = 'mlr';
	public static $tablename = 'mlr_mailing_list_registrants';
	public static $pkey_column = 'mlr_mailing_list_registrant_id';
	public static $permanent_delete_actions = array(
		'mlr_mailing_list_registrant_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	public static $fields = array(
		'mlr_mailing_list_registrant_id' => 'ID of the group member',
		'mlr_mlt_mailing_list_id' => 'Mailing list id',
		'mlr_usr_user_id' => 'Foreign key pointing to the member in this group',
		'mlr_change_time' => 'Time created',
		'mlr_delete_time' => 'Time created',
	);

	public static $field_specifications = array(
		'mlr_mailing_list_registrant_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'mlr_mlt_mailing_list_id' => array('type'=>'int4'),
		'mlr_usr_user_id' => array('type'=>'int4'),
		'mlr_change_time' => array('type'=>'timestamp(6)'),
		'mlr_delete_time' => array('type'=>'timestamp(6)'),
	);	

	public static $required_fields = array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array(
		'mlr_change_time' => 'now()');		

	public static function CheckIfExists($user_id, $mailing_list_id) {
		
		$count = new MultiMailingListRegistrant(array(
			'user_id' => $user_id,
			'mailing_list_id' => $mailing_list_id,
		));
		 
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return false;
	}	

	function prepare() {	
		
		if(!$this->key){
			if($this->check_for_duplicate(array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id'))){
				throw new MailingListRegistrantException('This is a duplicate mailing list registrant:'. $this->get('mlr_usr_user_id'));
			}
		}
		

	}
	
	function authenticate_write($data) {
		if ($this->get($this->prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. $this->tablename);
			}
		}
	}

	function save($debug=false) {
		if(!$this->key){
			if($this->check_for_duplicate(array('mlr_mlt_mailing_list_id', 'mlr_usr_user_id'))){
				return FALSE;
			}			
		}
		parent::save($debug);
	}
}

class MultiMailingListRegistrant extends SystemMultiBase {

	
	function get_dropdown_array($include_new=FALSE) {
		return false;
	}
	
	
	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();
		
		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'mlr_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('mailing_list_id', $this->options)) {
			$where_clauses[] = 'mlr_mlt_mailing_list_id = ?';
			$bind_params[] = array($this->options['mailing_list_id'], PDO::PARAM_INT);
		}	

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'mlr_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM mlr_mailing_list_registrants ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM mlr_mailing_list_registrants
				' . $where_clause . '
				ORDER BY ';
				
			if (!$this->order_by) {
				$sql .= " mlr_mailing_list_registrant_id ASC ";
			}
			else {
				if (array_key_exists('mailing_list_registrant_id', $this->order_by)) {
					$sql .= ' mlr_mailing_list_registrant_id ' . $this->order_by['mailing_list_registrant_id'];
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
			$child = new MailingListRegistrant($row->mlr_mailing_list_registrant_id);
			$child->load_from_data($row, array_keys(MailingListRegistrant::$fields));
			$this->add($child);
		}
	}

}


?>
