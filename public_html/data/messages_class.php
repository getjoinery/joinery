<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');
	

class MessageException extends SystemClassException {}
class MessageNotSentException extends MessageException {};

class Message extends SystemBase {
	public static $prefix = 'msg';
	public static $tablename = 'msg_messages';
	public static $pkey_column = 'msg_message_id';
	public static $permanent_delete_actions = array(
		'msg_message_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	

	public static $fields = array(
		'msg_message_id' => 'Message id',
		'msg_usr_user_id_recipient' => 'Message recipient',
		'msg_usr_user_id_sender' => 'Where is the message from',
		'msg_evt_event_id' => 'Event id if sent to event recipients',
		'msg_body' => 'The message',
		'msg_sent_time' => 'Time_sent',
		'msg_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'msg_message_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'msg_usr_user_id_recipient' => array('type'=>'int4'),
		'msg_usr_user_id_sender' => array('type'=>'int4'),
		'msg_evt_event_id' => array('type'=>'int4'),
		'msg_body' => array('type'=>'text'),
		'msg_sent_time' => array('type'=>'timestamp(6)'),
		'msg_delete_time' => array('type'=>'timestamp(6)'),
	);

	public static $required_fields = array('msg_body', 'msg_sent_time');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();	

	public static $initial_default_values = array();	
	
	function display_title(){
		if($this->get('msg_body')){
			return substr(strip_tags($this->get('msg_body')), 0, 100);
		}
		else{
			return '';
		}
	}
	

	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('msg_usr_user_id') != $current_user) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this message.');
			}
		}
	}

	
}

class MultiMessage extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id_recipient', $this->options)) {
			$where_clauses[] = 'msg_usr_user_id_recipient = ?';
			$bind_params[] = array($this->options['user_id_recipient'], PDO::PARAM_INT);
		}
	
		if (array_key_exists('user_id_sender', $this->options)) {
			$where_clauses[] = 'msg_usr_user_id_sender = ?';
			$bind_params[] = array($this->options['user_id_sender'], PDO::PARAM_INT);
		}	
		
		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'msg_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}	

		
		if (array_key_exists('event_id_only', $this->options)) {
			$where_clauses[] = 'msg_evt_event_id = ? and msg_usr_user_id_recipient is NULL';
			$bind_params[] = array($this->options['event_id_only'], PDO::PARAM_INT);
		}	
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'msg_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	 		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}


		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM msg_messages ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM msg_messages
				' . $where_clause . '
				ORDER BY ';
			
			if (!$this->order_by) {
				$sql .= " msg_message_id ASC ";
			}
			else {
				if (array_key_exists('message_id', $this->order_by)) {
					$sql .= ' msg_message_id ' . $this->order_by['message_id'];
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
		$q = $this->_get_results(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Message($row->msg_message_id);
			$child->load_from_data($row, array_keys(Message::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE, $debug);
		$counter = $q->fetch();
		return $counter->count;
	}
}



?>
