<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
	

class MessageException extends SystemClassException {}
class MessageNotSentException extends MessageException {};

class Message extends SystemBase {


	public static $fields = array(
		'msg_message_id' => 'Message id',
		'msg_usr_user_id_recipient' => 'Message recipient',
		'msg_usr_user_id_sender' => 'Where is the message from',
		'msg_evt_event_id' => 'Event id if sent to event recipients',
		'msg_body' => 'The message',
		'msg_sent_time' => 'Time_sent',
		'msg_delete_time' => 'Time of deletion',
	);
	
	function display_title(){
		if($this->get('msg_body')){
			return substr(strip_tags($this->get('msg_body')), 0, 100);
		}
		else{
			return '';
		}
	}
	


	function load() {
		parent::load();
		$this->data = SingleRowFetch('msg_messages', 'msg_message_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new MessageException(
				'This message number does not exist');
		}
	}

	function prepare() {
		if ($this->data === NULL) {
			throw new MessageException('This message has no data.');
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

	
	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('msg_message_id' => $this->key);
			// Editing an existing
		} else {
			$p_keys = NULL;
			// Creating a new
			unset($rowdata['msg_message_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'msg_messages', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['msg_message_id'];
	}

	function soft_delete(){
		$this->set('msg_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('msg_delete_time', NULL);
		$this->save();	
		return true;
	}
	

	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		/*
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}
		*/

		$sql = 'DELETE FROM msg_messages WHERE msg_message_id=:msg_message_id';

		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':msg_message_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		if($this_transaction){
			$dblink->commit();
		}
		
		$this->key = NULL;

		return TRUE;
		
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS msg_messages_msg_message_id_seq
				INCREMENT BY 1
				NO MAXVALUE
				NO MINVALUE
				CACHE 1;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}			
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."msg_messages" (
			  "msg_message_id" int4 NOT NULL DEFAULT nextval(\'msg_messages_msg_message_id_seq\'::regclass),
			  "msg_usr_user_id_recipient" int4,
			  "msg_usr_user_id_sender" int4,
			  "msg_evt_event_id" int4,
			  "msg_body" text COLLATE "pg_catalog"."default" NOT NULL,
			  "msg_sent_time" timestamp(6) NOT NULL DEFAULT now(),
			  "msg_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."msg_messages" ADD CONSTRAINT "msg_messages_pkey" PRIMARY KEY ("msg_message_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}	




}

class MultiMessage extends SystemMultiBase {

	private function _get_results($only_count=FALSE) { 
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

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Message($row->msg_message_id);
			$child->load_from_data($row, array_keys(Message::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
}



?>
