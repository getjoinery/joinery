<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');
PathHelper::requireOnce('includes/systemmailer.php');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class QueuedEmailException extends SystemClassException {}

class QueuedEmail extends SystemBase {
	public static $prefix = 'equ';
	public static $tablename = 'equ_queued_emails';
	public static $pkey_column = 'equ_queued_email_id';
	public static $permanent_delete_actions = array(
		'equ_queued_email_id' => 'delete',	
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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

	public static $field_specifications = array(
		'equ_queued_email_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'equ_from_name' => array('type'=>'varchar(70)'),
		'equ_from' => array('type'=>'varchar(64)'),
		'equ_to' => array('type'=>'varchar(64)'),
		'equ_to_name' => array('type'=>'varchar(70)'),
		'equ_body' => array('type'=>'text'),
		'equ_subject' => array('type'=>'varchar(128)'),
		'equ_timestamp' => array('type'=>'timestamp(6)'),
		'equ_status' => array('type'=>'int2'),
		'equ_ers_recurring_email_log_id' => array('type'=>'int4'),
	);

	public static $required_fields = array('equ_from_name', 'equ_from', 'equ_to', 'equ_to_name', 'equ_body', 'equ_subject');

	public static $field_constraints = array();	
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	'equ_timestamp' => 'now()', 'equ_status'=> 0);

	function get_status() {
		return self::$status_to_text[$this->get('equ_status')];
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
		$mailer->isHTML(true);
		$mailer->Subject = $this->get('equ_subject');
		$mailer->Body = $this->get('equ_body');
		$mailer->setFrom($this->get('equ_from'), $this->get('equ_from_name'));
		$mailer->addAddress($this->get('equ_to'), $this->get('equ_to_name'));
		
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

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['status'])) {
			$filters['equ_status'] = [$this->options['status'], PDO::PARAM_INT];
		}

		if (isset($this->options['multi_status'])) {
			$status_conditions = [];
			foreach($this->options['multi_status'] as $status) {
				$status_conditions[] = 'equ_status = '.$status;
			}
			$filters['equ_status'] = '('.implode(' OR ', $status_conditions).')';
		}

		return $this->_get_resultsv2('equ_queued_emails', $filters, $this->order_by, $only_count, $debug);
	}

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new QueuedEmail($row->equ_queued_email_id);
			$child->load_from_data($row, array_keys(QueuedEmail::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
