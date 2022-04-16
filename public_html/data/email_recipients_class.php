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

class EmailRecipientException extends SystemClassException {}

class EmailRecipient extends SystemBase {
	public static $prefix = 'erc';
	public static $tablename = 'erc_email_recipients';
	public static $pkey_column = 'erc_email_recipient_id';
	public static $permanent_delete_actions = array(
		'erc_email_recipient_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	// Status codes
	const EMAIL_SENT = 1;
	const UNSUBSCRIBED = 2;
	const SEND_FAILURE = 3;

	public static $fields = array(
		'erc_email_recipient_id' => 'EmailRecipient id',
		'erc_usr_user_id' => 'Owner of the recipient - user id (optional)',
		'erc_email' => 'Recipient email address',
		'erc_name' => 'Recipient name, if available',
		'erc_eml_email_id' => 'Email foreign key',
		'erc_sent_time' => 'Sent time',
		'erc_status' => 'Status'
	);
	
	public static $field_specifications = array(
		'erc_email_recipient_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'erc_usr_user_id' => array('type'=>'int4'),
		'erc_email' => array('type'=>'varchar(64)'),
		'erc_name' => array('type'=>'varchar(70)'),
		'erc_eml_email_id' => array('type'=>'int4'),
		'erc_sent_time' =>  array('type'=>'timestamp(6)'),
		'erc_status' => array('type'=>'int2'),
	);

	public static $required_fields = array(
		'erc_email', 'erc_eml_email_id');
		
	public static $zero_variables = array();

	public static $field_constraints = array();	
	
	public static $initial_default_values = array();
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('erc_usr_user_id') != $current_user) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this email_recipient.');
			}
		}
	}
	
	
	function check_for_duplicates() {
		$count = new MultiEmailRecipient(array(
			'email_id' => $this->get('erc_eml_email_id'),
			'user_email' => $this->get('erc_email')
		));
		
		if ($count->count_all() > 0) {
			$count->load();
			return $count->get(0);
		}
		return NULL;
	}	

	function get_name() { 
		if ($this->get('erc_name')) { 
			return $this->get('erc_name');	
		} else { 
			return '';
		}
	}

	function get_email() { 
		if ($this->get('erc_email')) { 
			return $this->get('erc_email');	
		} else { 
			return '';
		}
	}

	function is_sent() { 
		return $this->get('erc_status') == self::EMAIL_SENT;
	}

	function url_tracking_code() { 
		return 'erc=' . $this->tracking_code();
	}

	function tracking_code() { 
		return self::TrackingCodeForRecipient($this->key);
	}
	
	static function CheckIfExists($erc_eml_email_id, $erc_email) {
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT COUNT(1) FROM erc_email_recipients WHERE erc_eml_email_id=:erc_eml_email_id AND LOWER(erc_email)=LOWER(:erc_email)';

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':erc_eml_email_id', $erc_eml_email_id, PDO::PARAM_INT);
			$q->bindValue(':erc_email', $erc_email, PDO::PARAM_STR);

			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		return $q->fetch()->count;
	}
	
	static function CheckIfEmailed($erc_email, $time_since=NULL) {
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'SELECT COUNT(1) FROM erc_email_recipients 
		INNER JOIN eml_emails ON erc_email_recipients.erc_eml_email_id = eml_emails.eml_email_id 
		WHERE LOWER(erc_email)=LOWER(:erc_email)';

		if($time_since) {
			$sql .= ' AND eml_sent_time > :eml_sent_time';
		}
		
		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':erc_email', $erc_email, PDO::PARAM_STR);
			if($time_since) {
				$q->bindValue(':eml_sent_time', $time_since, PDO::PARAM_STR);
			}
			
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		return $q->fetch()->count;
	}	
	

	static function DeleteAll($erc_eml_email_id) {
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = "DELETE FROM erc_email_recipients WHERE erc_eml_email_id=:erc_eml_email_id";

		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(':erc_eml_email_id', $erc_eml_email_id, PDO::PARAM_INT);
			$q->execute();
		} catch (PDOException $e) {
			$dbhelper->handle_query_error($e);
		}	
	}	
	
	

	static function TrackingCodeForRecipient($erc_email_recipient_id) { 
		// Checksummed tracking code for this recipient
		return LibraryFunctions::EncodeWithChecksum($erc_email_recipient_id);
	}

	static function RecipientIdFromTrackingCode($code) { 
		return LibraryFunctions::DecodeWithChecksum($code);
	}
	
}


class MultiEmailRecipient extends SystemMultiBase {

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'erc_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('email_id', $this->options)) {
			$where_clauses[] = 'erc_eml_email_id = ?';
			$bind_params[] = array($this->options['email_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('user_email', $this->options)) {
			$where_clauses[] = 'erc_email = ?';
			$bind_params[] = array($this->options['user_email'], PDO::PARAM_STR);
		}		

		if (array_key_exists('sent', $this->options)) {
			if ($this->options['sent']) { 
				$where_clauses[] = 'erc_sent_time IS NOT NULL';
			} else { 
				$where_clauses[] = 'erc_sent_time IS NULL';
			}
		}
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM erc_email_recipients ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM erc_email_recipients
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " erc_email_recipient_id ASC ";
			}
			else {
				if (array_key_exists('email_recipient_id', $this->order_by)) {
					$sql .= ' erc_email_recipient_id ' . $this->order_by['email_recipient_id'];
				}	
				else if (array_key_exists('email_id', $this->order_by)) {
					$sql .= ' erc_eml_email_id ' . $this->order_by['email_id'];
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
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new EmailRecipient($row->erc_email_recipient_id);
			$child->load_from_data($row, array_keys(EmailRecipient::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}

	public static function GetUnsentRecipientsForEmail($eml_email_id) { 
		$recipients = new MultiEmailRecipient(array('email_id' => $eml_email_id, 'sent' => FALSE));
		$recipients->load();
		return $recipients;
	}
}

?>
