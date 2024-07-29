<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SystemClass.php');

require_once($siteDir . '/data/email_recipients_class.php');	
require_once($siteDir . '/data/email_recipient_groups_class.php');	
require_once($siteDir . '/data/users_class.php');	

class EmailException extends SystemClassException {}
class EmailNotSentException extends EmailException {};

class Email extends SystemBase {
	public static $prefix = 'eml';
	public static $tablename = 'eml_emails';
	public static $pkey_column = 'eml_email_id';
	public static $permanent_delete_actions = array(
		'eml_email_id' => 'delete',
		'erc_eml_email_id' => 'delete',
		'erg_eml_email_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	
	
	const FORMAT_HTML = 1;
	const FORMAT_PLAINTEXT = 2;
	
	const EMAIL_DELETED = 0;
	const EMAIL_TEMP_CREATED = 2;
	const EMAIL_CREATED = 3;
	const EMAIL_QUEUED = 5;
	const EMAIL_SENT = 10;
	
	const TYPE_TRANSACTIONAL = 1;
	const TYPE_MARKETING = 2;

	public $webdir = '';
	public $cdn = '';

	public static $fields = array(
		'eml_email_id' => 'Email id',
		'eml_description' => 'Description of the email',
		'eml_usr_user_id' => 'Email creator, can be NULL',
		'eml_from_address' => 'From address',
		'eml_from_name' => 'From name',
		'eml_subject' => 'Subject line',
		'eml_preview_text' => 'Preview text for the first line in email readers',
		'eml_reply_to' => 'Reply address', // usually the user's name/address who was sending the email
		'eml_message_html' => 'The message HTML, before being merged with recipient',
		'eml_message_plain' => 'The message text, before being merged with recipient',
		'eml_message_template_html' => 'HTML body template',
		'eml_message_template_plain' => 'Source for the plaintext template, defaults to NULL',
		'eml_sent_time' => 'Time_sent',
		'eml_status' => 'Status see above',
		'eml_scheduled_time' => 'Scheduled time to send',
		'eml_type' => 'Type of email for opt out purposes',
		'eml_delete_time' => 'Time of deletion',
		'eml_ctt_contact_type_id' => 'The contact type of this email for unsubscribes',  
		'eml_mlt_mailing_list_id' => 'The mailing list for this email, null for custom',
	);

	public static $field_specifications = array(
		'eml_email_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'eml_description' => array('type'=>'varchar(255)'),
		'eml_usr_user_id' => array('type'=>'int4'),
		'eml_from_address' =>  array('type'=>'varchar(255)'),
		'eml_from_name' =>  array('type'=>'varchar(255)'),
		'eml_subject' => array('type'=>'varchar(255)'),
		'eml_preview_text' => array('type'=>'varchar(255)'),
		'eml_reply_to' => array('type'=>'varchar(255)'),
		'eml_message_html' => array('type'=>'text'),
		'eml_message_plain' => array('type'=>'text'),
		'eml_message_template_html' => array('type'=>'text'),
		'eml_message_template_plain' => array('type'=>'text'),
		'eml_sent_time' => array('type'=>'timestamp(6)'),
		'eml_status' =>  array('type'=>'int2'),
		'eml_scheduled_time' => array('type'=>'timestamp(6)'),
		'eml_type' => array('type'=>'int2'),
		'eml_delete_time' => array('type'=>'timestamp(6)'),
		'eml_ctt_contact_type_id' => array('type'=>'int4'),
		'eml_mlt_mailing_list_id' => array('type'=>'int4'),
	);
			
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
	);	
	
	public function __construct($key, $load=FALSE) { 
		parent::__construct($key, $load);

		// Store a few things here for easy passthrough into the 
		// email templates
		$settings = Globalvars::get_instance();
		$this->webdir = $settings->get_setting('webDir');
	}
	
	
	//THIS ADDS AN ENTRY TO THE RECIPIENT GROUPS TABLE WITH THE EVENT OR GROUP TO ADD
	//THE OP FIELD TELLS THE MAILER WHETHER TO ADD THESE RECIPIENTS TO THE EMAIL OR SUBTRACT THEM WHEN IT'S TIME TO QUEUE THE EMAIL
	function add_recipient_group($evt_event_id, $grp_group_id, $op='add'){
		
		//MAKE SURE THERE'S ONLY TWO OPERATIONS
		if($op == 'remove'){
			$op == 'remove';
		}
		else{
			$op == 'add';
		}
		
		$email_recipient_group = new EmailRecipientGroup(NULL);
		$email_recipient_group->set('erg_eml_email_id', $this->key);
		$email_recipient_group->set('erg_evt_event_id', $evt_event_id);
		$email_recipient_group->set('erg_grp_group_id', $grp_group_id);
		$email_recipient_group->set('erg_operation', $op);
		
		//DON'T ADD IT AGAIN IF IT'S ALREADY THERE
		if(!$email_recipient_group->check_for_duplicate(array('erg_grp_group_id', 'erg_evt_event_id', 'erg_eml_email_id', 'erg_operation'))){
			$email_recipient_group->prepare();
			$email_recipient_group->save();
		}
		
		return true;
	}
	
	function get_recipient_groups($op=NULL){

		$searches = array(
			'email_id' => $this->key,
		);
		
		//MAKE SURE THERE'S ONLY TWO OPERATIONS
		if($op == 'remove' || $op == 'add'){
			$searches['operation'] = $op;
		}

		$email_recipient_groups = new MultiEmailRecipientGroup($searches);
		
		$email_recipient_groups->load();
		return $email_recipient_groups;
		
	}	
	

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}	


	function get_status_text() {
		if($this->get('eml_status') == Email::EMAIL_DELETED) {
			return 'Deleted';
		} else if($this->get('eml_status') == Email::EMAIL_TEMP_CREATED) {
			return 'Temp Created';
		} else if($this->get('eml_status') == Email::EMAIL_CREATED) {
			return 'Created';
		} else if($this->get('eml_status') == Email::EMAIL_QUEUED) {
			return 'Queued';
		} else if($this->get('eml_status') == Email::EMAIL_SENT) {
			return 'Sent';
		}
	}
	
	function mark_all_recipients_sent(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'UPDATE erc_email_recipients SET erc_status='.EmailRecipient::EMAIL_SENT.' WHERE erc_eml_email_id=:erc_eml_email_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':erc_eml_email_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
		
		return true;		
	}		
	

	function get_user() { 
		if (!array_key_exists('user', $this->cached_references)) { 
			$user = new User($this->get('eml_usr_user_id'), TRUE);
			$this->cached_references['user'] = $user;
		} 
		return $this->cached_references['user'];
	}

	function get_unsent_recipients() { 
		return MultiEmailRecipient::GetUnsentRecipientsForEmail($this->key);
	}


	function get_tracking_code() { 
		$medium = 'email';
		$source = 'marketing';
		$content = $this->key;
		$campaign = 'sendtofriend';
		return "utm_campaign=$campaign&utm_medium=$medium&utm_source=$source&utm_content=$content";
	}

	function preview($recipient=NULL, $format=self::FORMAT_HTML) { 
		if (is_numeric($recipient)) { 
			$recipient = new EmailRecipient($recipient, TRUE);
		}
		return $this->merge_recipient($recipient, $format);
	}
	
	static function HtmlToText($temphtml) {
		$tempplain = $temphtml;
		$search = "/<style>.*<\/style>/smU";
		$tempplain = preg_replace($search, "", $tempplain);
		$tempplain = str_replace("<br>", "\n", $tempplain);
		$tempplain = str_replace("<br />", "\n", $tempplain);
		$tempplain = str_replace("</p>", "\n", $tempplain);
		$tempplain = str_replace("<BR>", "\n", $tempplain);
		$tempplain = str_replace("<BR />", "\n", $tempplain);
		$tempplain = str_replace("</P>", "\n", $tempplain);
		$tempplain = str_replace("&nbsp;", " ", $tempplain);
		
		$tempplain = str_replace("<h1>", "\n\n", $tempplain);
		$tempplain = str_replace("<h2>", "\n\n", $tempplain);
		$tempplain = str_replace("<h3>", "\n\n", $tempplain);
		$tempplain = str_replace("<h4>", "\n\n", $tempplain);
		$tempplain = str_replace("<li>", "*", $tempplain);
		$tempplain = str_replace("</li>", "\n", $tempplain);
		$tempplain = str_replace("</ul>", "\n\n", $tempplain);	
		$tempplain = str_replace("\t", "", $tempplain);

		$tempplain = preg_replace("/<a.*?href=\"(.*?)\".*?>(.*?)<\/a>/smi", "$2 ($1)", $tempplain);
		
		$tempplain = strip_tags($tempplain);
	
		return $tempplain;
	}	
	
}

class MultiEmail extends SystemMultiBase {

	const SCHEDULED_PAST = 1;
	const SCHEDULED_FUTURE = 2;

	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'eml_usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['status'])) {
			$where_clauses[] = 'eml_status = ?';
			$bind_params[] = array($this->options['status'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'eml_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		
		if (isset($this->options['scheduleddate']) && $this->options['scheduleddate'] == self::SCHEDULED_PAST) {
			$where_clauses[] = 'eml_scheduled_time < NOW()';
		} elseif (isset($this->options['scheduleddate']) && $this->options['scheduleddate'] == self::SCHEDULED_FUTURE) {
			$where_clauses[] = 'eml_scheduled_time > NOW()';
		}		
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}
		
		if (array_key_exists('email_id', $this->order_by)) {
			$sql .= ' eml_email_id ' . $this->order_by['email_id'];
		}			

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM eml_emails ' . $where_clause;
		} else {
			$sql = 'SELECT * FROM eml_emails
				' . $where_clause . '
				ORDER BY eml_email_id DESC ' . $this->generate_limit_and_offset();
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
			$child = new Email($row->eml_email_id);
			$child->load_from_data($row, array_keys(Email::$fields));
			$this->add($child);
		}
	}

}

?>
