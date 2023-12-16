<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');
	

class UpgradeException extends SystemClassException {}
class UpgradeNotSentException extends UpgradeException {};

class Upgrade extends SystemBase {
	public static $prefix = 'upg';
	public static $tablename = 'upg_upgrades';
	public static $pkey_column = 'upg_upgrade_id';
	public static $permanent_delete_actions = array(
		'upg_upgrade_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'upg_upgrade_id' => 'Upgrade id',
		'upg_major_version' => 'Major Version',
		'upg_minor_version' => 'Minor Version',
		'upg_name' => 'Event id if sent to event recipients',
		'upg_release_notes' => 'Release notes',
		'upg_create_time' => 'Time_sent',
	);

	public static $field_specifications = array(
		'upg_upgrade_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'upg_major_version' => array('type'=>'int4'),
		'upg_minor_version' => array('type'=>'int4'),
		'upg_name' => array('type'=>'varchar(64)'),
		'upg_release_notes' => array('type'=>'text'),
		'upg_create_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('upg_major_version', 'upg_minor_version', 'upg_name');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array('upg_create_time'=>'now()');	

	
	
	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 8) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

	
}

class MultiUpgrade extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id_recipient', $this->options)) {
			$where_clauses[] = 'upg_usr_user_id_recipient = ?';
			$bind_params[] = array($this->options['user_id_recipient'], PDO::PARAM_INT);
		}
	
		if (array_key_exists('major_version', $this->options)) {
			$where_clauses[] = 'upg_major_version = ?';
			$bind_params[] = array($this->options['major_version'], PDO::PARAM_INT);
		}	
		
		if (array_key_exists('minor_version', $this->options)) {
			$where_clauses[] = 'upg_minor_version = ?';
			$bind_params[] = array($this->options['minor_version'], PDO::PARAM_INT);
		}	

		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM upg_upgrades ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM upg_upgrades
				' . $where_clause . '
				ORDER BY ';
			
			if (empty($this->order_by)) {
				$sql .= " upg_upgrade_id ASC ";
			}
			else {
				if (array_key_exists('upgrade_id', $this->order_by)) {
					$sql .= ' upg_upgrade_id ' . $this->order_by['upgrade_id'];
				}
				
				if (array_key_exists('major_version', $this->order_by)) {
					$sql .= ' upg_major_version ' . $this->order_by['major_version'];
				}	

				if (array_key_exists('minor_version', $this->order_by)) {
					$sql .= ' upg_minor_version ' . $this->order_by['minor_version'];
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
			$child = new Upgrade($row->upg_upgrade_id);
			$child->load_from_data($row, array_keys(Upgrade::$fields));
			$this->add($child);
		}
	}

}



?>
