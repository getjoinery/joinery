<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');

class QueuedEmailException extends SystemClassException {}

class QueuedEmail extends SystemBase {
	// The various states an email can be in
	const QUEUED = 1; // Queued, but not approved yet
	const READY_TO_SEND = 2; // Queued and approved, ready to send
	const LOCKED = 3; // In the process of sending
	const SENT = 4; // Email is successfully sent
	const DELETED = 5; // Email is deleted
	const ERROR_SENDING = 6; // There was an error sending the email
	const NORMAL_MAILER_ERROR = 7; // This was an error email from the non-recurring mailer

	public static $status_to_text = array(
		self::QUEUED => 'Queued',
		self::READY_TO_SEND => 'Ready To Send',
		self::LOCKED => 'Locked',
		self::SENT => 'Sent',
		self::ERROR_SENDING => 'Error Sending',
		self::DELETED => 'Deleted',
		self::NORMAL_MAILER_ERROR => 'Non-Recurring Email Error',
	);

	public static $fields = array(
		'equ_queued_email_id' => 'ID for the email',
		'equ_from_name' => 'Name the email is from',
		'equ_from' => 'Address the email is from',
		'equ_to' => 'Address the email is to',
		'equ_to_name' => 'Name the email is to',
		'equ_body' => 'Body of the email',
		'equ_subject' => 'Subject of the email',
		'equ_timestamp' => 'Timestamp the email was created',
		'equ_status' => 'Status the email is in',
		'equ_ers_recurring_email_log_id' => 'Log ID this is linked with',
	);

	function get_status() {
		return self::$status_to_text[$this->get('equ_status')];
	}

	function load() {
		parent::load();

		$this->data = SingleRowFetch('equ_queued_emails', 'equ_queued_email_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);

		if ($this->data === NULL) {
			throw new QueuedEmailException('Invalid queued email ID');
		}
	}

	function get_stats() {
		return SingleRowFetch('ers_recurring_email_logs', 'ers_recurring_email_log_id',
			$this->get('equ_ers_recurring_email_log_id'), PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
	}

	function update_stats_sent() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'UPDATE ers_recurring_email_logs SET ers_send_time = \'NOW()\'
			WHERE ers_recurring_email_log_id = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $this->get('equ_ers_recurring_email_log_id'), PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			throw new QueuedEmailException(
				'Could not update email stats associated with ' . $this->key . ': ' . $e->getMessage());
		}
	}

	function send() {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$dblink->beginTransaction();

		// Load the email and double check its status before we send it
		$this->load();
		if ($this->get('equ_status') !== self::READY_TO_SEND) {
			throw new QueuedEmailException(
				'Attemping to send a Queued Email which is not in the correct state.  Aborting...');
		}

		// Set this email to locked
		$this->set('equ_status', self::LOCKED);
		$this->save();

		$dblink->commit();

		// Now send it!
		$mailer = new systemmailer();
		$mailer->IsHTML(true);
		$mailer->Subject = $this->get('equ_subject');
		$mailer->Body = $this->get('equ_body');
		$mailer->From = $this->get('equ_from');
		$mailer->FromName = $this->get('equ_from_name');
		$mailer->AddAddress($this->get('equ_to'), $this->get('equ_to_name'));
		
		$dblink->beginTransaction();

		$this->load();

		if ($mailer->send()) {
			$this->set('equ_status', self::SENT);
			$this->update_stats_sent();
		} else {
			$this->set('equ_status', self::ERROR_SENDING);
		}

		$dblink->commit();
	}

	function save() {
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('equ_queued_email_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			$rowdata['equ_timestamp'] = 'NOW';
			// Creating a new record
			unset($rowdata['equ_queued_email_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "equ_queued_emails", $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['equ_queued_email_id'];
	}
	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS equ_queued_emails_equ_queued_email_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."equ_queued_emails" (
			  "equ_queued_email_id" int4 NOT NULL DEFAULT nextval(\'equ_queued_emails_equ_queued_email_id_seq\'::regclass),
			  "equ_from_name" varchar(70) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "equ_from" varchar(64) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "equ_to" varchar(64) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "equ_to_name" varchar(70) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "equ_body" text COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "equ_subject" varchar(32) COLLATE "pg_catalog"."default" NOT NULL DEFAULT \'\'::character varying,
			  "equ_timestamp" timestamp(6) NOT NULL DEFAULT now(),
			  "equ_status" int2 DEFAULT (0)::smallint,
			  "equ_ers_recurring_email_log_id" int4
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."equ_queued_emails" ADD CONSTRAINT "equ_queued_email_id_pkey" PRIMARY KEY ("equ_queued_email_id");';
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


class MultiQueuedEmail extends SystemMultiBase {

	public static function EveryStatusChange($old_status, $new_status) {
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'UPDATE equ_queued_emails SET equ_status = ? WHERE equ_status = ?';

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $new_status, PDO::PARAM_INT);
			$q->bindValue(2, $old_status, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
	}

	private function _get_results($only_count=FALSE) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('status', $this->options)) {
			$where_clauses[] = 'equ_status = ?';
			$bind_params[] = array($this->options['status'], PDO::PARAM_INT);
		}

		if (array_key_exists('multi_status', $this->options)) {
			$sub_where_clauses = array();
			foreach($this->options['multi_status'] as $status) {
				$sub_where_clauses[] = 'equ_status = ?';
				$bind_params[] = array($status, PDO::PARAM_INT);
			}
			$where_clauses[] = '(' . implode(' OR ' , $sub_where_clauses) . ')';
		}

		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM equ_queued_emails
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM equ_queued_emails
				' . $where_clause . '
				ORDER BY equ_queued_email_id' . $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);

			$total_params = count($bind_params);
			for($i=0;$i<$total_params;$i++) {
				list($param, $type) = $bind_params[$i];
				$q->bindValue($i+1, $param, $type);
			}
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load() {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new QueuedEmail($row->equ_queued_email_id);
			$child->load_from_data($row, array_keys(QueuedEmail::$fields));
			$this->add($child);
		}
	}

	function count_all() {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}

?>
