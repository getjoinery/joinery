<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');

require_once($siteDir . '/data/groups_class.php');
require_once($siteDir . '/data/address_class.php');
require_once($siteDir . '/data/phone_number_class.php'); 
require_once($siteDir . '/data/activation_codes_class.php'); 
require_once($siteDir . '/data/visitor_events_class.php');
require_once($siteDir . '/data/contact_types_class.php');
require_once($siteDir . '/data/mailing_lists_class.php');

class UserException extends SystemClassException {}
class DisplayableUserException extends UserException implements DisplayableErrorMessage {}

class User extends SystemBase {
	public static $prefix = 'usr';
	public static $tablename = 'usr_users';
	public static $pkey_column = 'usr_user_id';

	public static $permanent_delete_actions = array(
		'usr_user_id' => 'delete',
		'act_usr_user_id' => 'delete',
		'lfe_usr_user_id' => 'delete',
		'log_usr_user_id' => 'delete',
		'evl_usr_user_id' => 'delete',
		'ers_usr_user_id' => 'delete',
		'ord_usr_user_id' => User::USER_DELETED,
		'odi_usr_user_id' => User::USER_DELETED,
		'eml_usr_user_id' => User::USER_DELETED,
		'erc_usr_user_id' => 'delete',
		'evt_usr_user_id' => User::USER_DELETED,
		'evr_usr_user_id' => 'delete',
		'pst_usr_user_id' => User::USER_DELETED,
		'phn_usr_user_id' => 'delete',
		'usa_usr_user_id' => 'delete',
		'vid_usr_user_id' => User::USER_DELETED,
		'fil_usr_user_id' => User::USER_DELETED,
		'msg_usr_user_id_recipient' => 'delete',
		'msg_usr_user_id_sender' => User::USER_DELETED,
		'grp_usr_user_id_created' => User::USER_DELETED,
		'bkn_usr_user_id_booked' => User::USER_DELETED,
		'bkn_usr_user_id_client' => User::USER_DELETED,
		'cls_usr_user_id_logged_in' => 'delete',
		'cls_usr_user_id_billing' => User::USER_DELETED,
		'cmt_usr_user_id' => 'delete',
		'cnv_usr_user_id' => User::USER_DELETED,
		'err_usr_user_id' => User::USER_DELETED,
		'evt_usr_user_id_leader' => User::USER_DELETED,
		'pac_usr_user_id' => User::USER_DELETED,
		'sev_usr_user_id' => 'delete',
		'siv_usr_user_id' => User::USER_DELETED,
		'stg_usr_user_id' => User::USER_DELETED,
		'sva_usr_user_id' => User::USER_DELETED,
		'vse_usr_user_id' => User::USER_DELETED,
		'prd_usr_user_id' => User::USER_DELETED,
		'mlr_usr_user_id' => 'delete'

	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value	
	
	// Constants for contact preferences
	const NEWSLETTER = 1; 
	const TRANSACTIONAL = 2;

	//SPECIAL USER IDS
	const USER_SYSTEM = 2;
	const USER_DELETED = 3;


	public static $fields = array(
		'usr_user_id' => 'User id',
		'usr_first_name' => 'First Name',
		'usr_last_name' => 'Last Name',
		'usr_email' => 'Email Address',
		'usr_signup_date' => 'Signup date',
		'usr_password' => 'Password hash',
		'usr_permission' => 'Permission level',
		'usr_timezone' => 'String of timezone',
		'usr_email_is_verified' => 'Is their email verified?',
		'usr_email_is_verified_time' => 'Timestamp when email was verified',
		'usr_is_activated' => 'Is their account activated?',
		'usr_is_disabled' => 'Is their account disabled?',
		'usr_lastlogin_time' => 'Time of last login',
		'usr_pic_picture_id' => 'Profile picture ID',
		'usr_phn_phone_number_id' => 'Default phone number',
		'usr_contact_preferences' => 'User\'s contact preferences',  //THIS IS SOON DEPRECATED
		'usr_disabled_time' => 'When user disabled',
		'usr_nickname' => 'Nickname if exists',
		'usr_authhash' => 'first 8 characters of sha256 hash of user id and a salt, used for one click unsubscribe',
		'usr_stripe_customer_id' => 'Stripe customer id for the api',
		'usr_mailchimp_user_id' => 'User id for the mailchimp service',
		'usr_signup_ip' => 'ip of the user when they signed up',
		'usr_contact_preference_last_changed' => 'last time contact preferences was changed',
		'usr_organization_name' => 'Organization instead of person',
		'usr_delete_time' => 'Time of deletion',
		'usr_password_recovery_disabled' => 'When TRUE, password recovery is disabled.',
		'usr_urbit_ship_name' => 'If using urbit login, this is the user ship name',
		'usr_calendly_uri' => 'Uri for user for calendly integration',
		//'usr_contact_type_unsubscribes' => 'Contains a serialized array of contact types that the user has unsubscribed from',
	);

	public static $field_specifications = array(
		'usr_user_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'usr_first_name' => array('type'=>'varchar(32)'),
		'usr_last_name' => array('type'=>'varchar(32)'),
		'usr_email' => array('type'=>'varchar(64)'),
		'usr_signup_date' => array('type'=>'date'),
		'usr_password' => array('type'=>'character(34)'),
		'usr_permission' => array('type'=>'int4'),
		'usr_timezone' => array('type'=>'varchar(32)'),
		'usr_email_is_verified' => array('type'=>'bool'),
		'usr_email_is_verified_time' => array('type'=>'timestamp(6)'),
		'usr_is_activated' => array('type'=>'bool'),
		'usr_is_disabled' => array('type'=>'bool'),
		'usr_lastlogin_time' => array('type'=>'timestamp(6)'),
		'usr_pic_picture_id' => array('type'=>'int4'),
		'usr_phn_phone_number_id' => array('type'=>'int4'),
		'usr_contact_preferences' => array('type'=>'varchar(32)'),
		'usr_disabled_time' => array('type'=>'timestamp(6)'),
		'usr_nickname' => array('type'=>'varchar(32)'),
		'usr_authhash' => array('type'=>'varchar(32)'),
		'usr_stripe_customer_id' => array('type'=>'varchar(32)'),
		'usr_mailchimp_user_id' => array('type'=>'varchar(64)'),
		'usr_signup_ip' => array('type'=>'varchar(64)'),
		'usr_contact_preference_last_changed' => array('type'=>'timestamp(6)'),
		'usr_organization_name' => array('type'=>'varchar(32)'),
		'usr_delete_time' => array('type'=>'timestamp(6)'),
		'usr_password_recovery_disabled' => array('type'=>'bool'),
		'usr_urbit_ship_name' => array('type'=>'varchar(128)'),
		'usr_calendly_uri' => array('type'=>'varchar(255)'),
		//'usr_contact_type_unsubscribes' => array('type'=>'varchar(255)'),
	);
	
	public static $timestamp_fields = array(
		'usr_email_is_verified_time', 'usr_lastlogin_time', 'usr_admin_disabled_time',
		'usr_signup_date');


	public static $required_fields = array(
		'usr_first_name', 'usr_first_name', 'usr_email');

	public static $field_constraints = array(
	/*
		'usr_first_name' => array(
			array('WordLength', 2, 64)
			),
		'usr_last_name' => array(
			array('WordLength', 2, 64)
			),
			*/
	);
	
	public static $zero_variables = array(
				'usr_permission');
				
	public static $initial_default_values = array(
		'usr_timezone'=> 'America/New_York',
		'usr_is_activated' => FALSE,
		'usr_is_disabled' => FALSE,
		'usr_email_is_verified' => FALSE,
		'usr_signup_date' => 'now()',
		'usr_lastlogin_time' => 'now()',
	);


	private static function UcName($string) {
		$test_string = preg_replace('/[^A-Za-z]/', '', $string);
		$string = preg_replace('/[^A-Za-z\'-]/', '', $string);
		if(ctype_lower($test_string) || ctype_upper($test_string) ){
		    $string =ucwords(strtolower($string));

		    foreach (array('-', '\'') as $delimiter) {
		      if (strpos($string, $delimiter)!==false) {
		        $string =implode($delimiter, array_map('ucfirst', explode($delimiter, $string)));
		      }
		    }
		}
	    return $string;
	}
	
	//RETURNS AN ARRAY OF CONTACT TYPES THE USER HAS UNSUBSCRIBED FROM
	public function get_contact_type_unsubscribes(){
		return json_decode($this->get('usr_contact_type_unsubscribes'));
	}
	
	//WILL RETURN TRUE IF THE USER IS UNSUBSCRIBED FROM THAT CONTACT TYPE
	public function is_unsubscribed_to_contact_type($contact_type_id){
		$unsubscribes = json_decode($this->get('usr_contact_type_unsubscribes'));
		if(in_array($contact_type_id, $unsubscribes)){
			return true;
		}
		else{
			return false;
		}
	}
	
	//ADDS AN ENTRY TO usr_contact_type_unsubscribes
	public function unsubscribe_from_contact_type($contact_type_id){
		$unsubscribes = json_decode($this->get('usr_contact_type_unsubscribes'));
		if(!in_array($contact_type_id, $unsubscribes)){
			$unsubscribes[] = $contact_type_id;
		}
		$this->set('usr_contact_type_unsubscribes', json_encode($unsubscribes));
		$this->set('usr_contact_preference_last_changed', 'NOW()');
		$this->save();
		
		return true;
	}
	
	//REMOVES THE AN ENTRY FROM usr_contact_type_unsubscribes
	public function subscribe_to_contact_type($contact_type_id){
		$unsubscribes = json_decode($this->get('usr_contact_type_unsubscribes'));
		if(($key = array_search($contact_type_id, $unsubscribes)) !== false){
			unset($unsubscribes[$key]);
		}		
		$this->set('usr_contact_type_unsubscribes', json_encode($unsubscribes));
		$this->set('usr_contact_preference_last_changed', 'NOW()');
		$this->save();
		
		return true;
	}


	
	public function add_user_to_automatic_groups(){

		if(!$group_all_users = Group::get_by_name("All users")){
			$group_all_users = Group::add_group('All users', $this->key, 'user');
		}
		if(!$group_us_users = Group::get_by_name("US users")){
			$group_us_users = Group::add_group('US users', $this->key, 'user');
		}
		if(!$group_nus_users = Group::get_by_name("Non-US users")){
			$group_nus_users = Group::add_group('Non-US users', $this->key, 'user');
		} 

		$group_all_users->add_member($this->key);
		
		if($address = $this->address()){
			if($address->get('usa_cco_country_code_id') == 1){
				$group_us_users->add_member($this->key);
			}
			else{
				$group_nus_users->add_member($this->key);
			}
		}		
	}


	static function CreateNewUser($first_name, $last_name, $email, $password, $send_emails=TRUE){   
			
			//PREVENT DUPLICATES
			if($user = User::GetByEmail($email)){
				return $user;
			}
	
			if($password){
					$email_fill = array(
					'password_temporary' => false,
					'password' => $password
					);
					$temp_password_hashed = User::GeneratePassword($password);
			}
			else{
					$temp_password = substr(md5(time()), 0, 5);
					$temp_password_hashed = User::GeneratePassword($temp_password);
					$email_fill = array(
					'password_temporary' => true,
					'password' => $temp_password
					);				
			}
	
			$user = new User(NULL);
			$user->set('usr_email', trim(strtolower($email)));
			$user->set('usr_first_name', trim($first_name));
			$user->set('usr_last_name', trim($last_name));	
			$user->set('usr_password', $temp_password_hashed);	
			$user->set('usr_signup_ip', $_SERVER['REMOTE_ADDR']);			
			
			$user->prepare();
			$user->save();
			$user->load();
			
			if($send_emails){
				$settings = Globalvars::get_instance();
				$siteDir = $settings->get_setting('siteDir');	
				require_once($siteDir . '/includes/EmailTemplate.php');
				require_once($siteDir . '/includes/Activation.php');
				
				//SEND NEW USER WELCOME EMAIL
				$welcome_email = new EmailTemplate('new_account_content', $user);
				$welcome_email->fill_template($email_fill);
				$welcome_email->send();	
				
				//SEND ACTIVATION EMAIL
				Activation::email_activate_send($user);
			}
			
			return $user;
	}



	public function export_as_array() {
		$user_data = parent::export_as_array();

		$user_data['usr_day_since_register'] = LibraryFunctions::DatetimeIntoDaysAgo(
			date_create($this->get('usr_signup_date')));

		unset($user_data['usr_password']);

		$user_data['display_name'] = $this->display_name();

		$user_data['user_activation_key'] = LibraryFunctions::encode($this->key, 'activation_key');

		$user_data['user_activation_key_qs'] = 'uak=' . $user_data['user_activation_key'];

		
		$user_data['contact_preferences'] = $this->get_contact_type_unsubscribes();
		

		$phone = $this->phone();
		$user_data['phone'] = $phone ? $phone->export_as_array() : NULL;

		$address = $this->address();
		$user_data['address'] = $address ? $address->export_as_array() : NULL;

		//$picture = $this->picture();
		//$user_data['picture_50_src'] = ($picture && $picture->src(Picture::SIZE_50)) ? $picture->src(Picture::SIZE_50) : NULL;
		//$user_data['picture_91_src'] = $picture ? $picture->src(Picture::SIZE_91) : NULL;

		$user_data['NEWSLETTER'] = self::NEWSLETTER;
		//$user_data['EMAIL_OFFERS'] = self::EMAIL_OFFERS;
		//$user_data['EMAIL_UPDATES'] = self::EMAIL_UPDATES;
		//$user_data['EMAIL_USER_FEEDBACK'] = self::EMAIL_USER_FEEDBACK;
		
		/*
		$last_recurring_email_date = RecurringMailer::GetDaysSinceLastEmail(
			$this->key, array('craigslist_reminder_email', 'request_expiry'));
			*/

		if ($last_recurring_email_date) {
			$user_data['usr_days_since_last_email'] = LibraryFunctions::DatetimeIntoDaysAgo(
				date_create($last_recurring_email_date));
		} else {
			$user_data['usr_days_since_last_email'] = $user_data['usr_day_since_register'];
		}
		
		//$user_data['usr_num_upsell_emails_sent'] = count(RecurringMailer::GetSentEmails($this->key, 'put'));

		// Output the top 3 progress items for this user
		// and select only those progress items that are marked to
		// be shown in the upsell email
		/*
		$base_progress = Progress::GetFilteredProgressList(Progress::SHOW_IN_UPSELL_EMAIL);
		$progress = Progress::GetNextProgressItems($this->key, $base_progress, 3);
		$user_data['progress_1'] = @$progress[0];
		$user_data['progress_2'] = @$progress[1];
		$user_data['progress_3'] = @$progress[2];
		*/

		//$user_data['usr_member_level_text'] = $this->get_member_level_text();

		return $user_data;
	}

	public function prepare() {
		if ($this->key === NULL) {
			

			//CHECK FOR DUPLICATES
			if(User::GetByEmail($this->get('usr_email'))){
				throw new DisplayableUserException(
					'Sorry, that email address "'.$this->get('usr_email').'" has already been used.  Please go back and try again.');				
			}
		}

		if (!LibraryFunctions::IsValidEmail($this->get('usr_email'))) {
			throw new DisplayableUserException(
				'Sorry, that email address "'.$this->get('usr_email').'" you entered is invalid.  Please go back and try again.');
		}

		//CAPITALIZATION
		$this->set('usr_first_name', User::UcName($this->get('usr_first_name')));
		$this->set('usr_last_name', User::UcName($this->get('usr_last_name')));
	}

	public static function GetByEmail($email) {
		$data = SingleRowFetch('usr_users', 'LOWER(usr_email)',
			trim(strtolower($email)), PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$fields));
		return $user;
	}

	public static function GetByStripeCustomerId($id) {
		$data = SingleRowFetch('usr_users', 'usr_stripe_customer_id',
			$id, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$fields));
		return $user;
	}

	public static function GetByCalendlyUri($uri) {
		$data = SingleRowFetch('usr_users', 'usr_calendly_uri',
			$uri, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$fields));
		return $user;
	}
	
	public static function GeneratePassword($password) {
		if (strlen($password) < 5) {
			throw new DisplayableUserException('Your password must be at least 5 characters');
		}

		if (strstr(' ', $password) !== FALSE) {
			throw new DisplayableUserException('Your password cannot contain spaces.');
		}

		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		require_once($siteDir . '/includes/PasswordHash.php');
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->HashPassword($password);
	}

	function email_verify_user($use_transaction=TRUE, $and_save=TRUE) {
		if ($use_transaction) {
			DbConnector::BeginTransaction();
		}

		$this->set('usr_is_activated', TRUE);
		$this->set('usr_email_is_verified', TRUE);
		$this->set('usr_email_is_verified_time', 'now');

		if ($and_save) {
			$this->save();
		}

		if ($use_transaction) {
			DbConnector::Commit();
		}
	}


	function email_unverify_bouncing_user($use_transaction=TRUE) {
		if ($use_transaction) {
			DbConnector::BeginTransaction();
		}

		$this->set('usr_is_activated', FALSE);
		$this->set('usr_email_is_verified', FALSE);
		$this->set('usr_email_bounce_unverify_time', 'now');

		$this->save();

		if ($use_transaction) {
			DbConnector::Commit();
		}
	}

	function check_password($password) {
		$settings = Globalvars::get_instance();
		$siteDir = $settings->get_setting('siteDir');
		require_once($siteDir . '/includes/PasswordHash.php');
		$hasher = new PasswordHash(8, TRUE);
		return $hasher->CheckPassword($password, $this->get('usr_password'));
	}

	function display_name() {

		if($this->get('usr_first_name') || $this->get('usr_last_name')){		
			$returnval = $this->get('usr_first_name') . ' ' . $this->get('usr_last_name');
			if($this->get('usr_nickname')){
				$returnval .= ' ('. $this->get('usr_nickname').')';
			}
		}
		else if($this->get('usr_nickname')){
			$returnval = ' ('. $this->get('usr_nickname').')';	
		}
		else if($this->get('usr_organization_name')){
			$returnval = $this->get('usr_organization_name');
		}
		else{
			$returnval = 'Unnamed User';
		}

		return $returnval;
		
	}

	/*
	function picture() {
		if ($this->get('usr_pic_picture_id')) {
			return new Picture($this->get('usr_pic_picture_id'), TRUE);
		}
		return NULL;
	}
	*/


	function actions_allowed() {
		if ($this->get('usr_is_disabled') || $this->get('usr_is_admin_disabled')) {
			return FALSE;
		}
		return TRUE;
	}

	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->key != $current_user) {
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to modify this user\'s information.');
			}
		}
	}

	function save($debug=false) {
		parent::save($debug);
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		//ADD THE USER TO ANY GROUPS NEEDED
		//TODO REMOVE FROM GROUPS NO LONGER APPLICABLE
		$this->add_user_to_automatic_groups();
		
		//THIS IS A SPECIAL CALCULATED FIELD BASED ON THE USER ID
		if($rowdata['usr_authhash'] === NULL){
			$rowdata['usr_authhash'] = substr(hash('sha256', $this->key.'izsalt'), 0, 8);
			$sql = "UPDATE usr_users SET usr_authhash = :usr_authhash WHERE usr_user_id = :usr_user_id";
		
			try{
				$q = $dblink->prepare($sql);
				$q->bindParam(':usr_authhash', $rowdata['usr_authhash'], PDO::PARAM_STR);
				$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
				$success = $q->execute();
			}
			catch(PDOException $e){
				$dbhelper->handle_query_error($e);
			}			
		}
	}

	// Set the default address for the user to the given address
	function set_default_address($address_id, $use_transaction=TRUE) {
		Address::SetDefaultAddressForUser($this->key, $address_id, $use_transaction);
	}

	function get_default_address() {
		return Address::GetDefaultAddressForUser($this->key);
	}

	function phone() {
		if ($phone = $this->get_default_phone()) {
			return $phone;
		}
		return NULL;
	}

	function address() {
		$default_address = $this->get_default_address();
		if ($default_address) {
			return new Address($default_address, TRUE);
		}
		return NULL;
	}

	function get_default_phone() {
		if($this->get('usr_phn_phone_number_id')){
			$phone = new PhoneNumber($this->get('usr_phn_phone_number_id'), TRUE);
			return $phone;
		}
		else{
			return FALSE;
		}
	}

	
	function permanent_delete($debug=false){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		if($this->key == User::USER_SYSTEM || $this->key == User::USER_DELETED){
			throw new SystemAuthenticationError(
					'You cannot delete this user.');
		}
		
		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}		
		
		//REMOVE FROM ANY MAILING LISTS
		if(!$debug){
			//GET LIST OF CONTACT TYPES
			$mailing_lists = new MultiMailingList(
				array(),
				NULL,		//SORT BY => DIRECTION
				NULL,  //NUM PER PAGE
				NULL);  //OFFSET
			$mailing_lists->load();
			foreach($mailing_lists as $mailing_list){
				if($mailing_list->is_user_in_list($this->key, false)){
					$mailing_list->remove_registrant($this->key);
				}
			}
		}
		
		//DELETE ANY GROUP MEMBERSHIPS
		$groups = Group::get_groups_in_category('user');
		foreach($groups as $group){
			$group->remove_member($this->key);
		}
		
		//DO ANY PREP ABOVE THIS LINE
		parent::permanent_delete($debug);
		
		if($this_transaction){
			$dblink->commit();
		}	
		
		return true;
		
	}
	
	//TESTS FOR THIS CLASS
	static function test($debug=false){
		parent::test($debug);
		$dbhelper = DbConnector::get_instance();
		$dbhelper->set_test_mode();
		$dblink = $dbhelper->get_db_link();		
		
		$email = LibraryFunctions::random_string(10).'@test.com';
		//NEW USER
		$user = User::CreateNewUser(LibraryFunctions::random_string(10), LibraryFunctions::random_string(10), $email , 'testpass', FALSE);
		
		$user = User::GetByEmail($email);
		if(!$user){
			$dbhelper->close_test_mode(); 
			return false;
		}
		

		$user->permanent_delete();
		
		
		
		$user = User::GetByEmail($email);
		if($user){
			$dbhelper->close_test_mode(); 
			return false;
		}
		
		$dbhelper->close_test_mode(); 

		return true;
			
	}

}


class MultiUser extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->display_name().' - '.$item->get('usr_email')] = $item->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items; 

	}
	
	function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
			$where_clauses[] = 'usr_user_id = ?';
			$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('user_id_list', $this->options)) {
				if(count($this->options['user_id_list'])) {
					$where_clauses[] = 'usr_user_id IN ('.implode(',', $this->options['user_id_list']).')';
				}
		}

		if (array_key_exists('first_name_like', $this->options)) {
			$where_clauses[] = 'usr_first_name ILIKE ?';
			$bind_params[] = array('%'.$this->options['first_name_like'].'%', PDO::PARAM_STR);
		}

		if (array_key_exists('last_name_like', $this->options)) {
			$where_clauses[] = 'usr_last_name ILIKE ?';
			$bind_params[] = array('%'.$this->options['last_name_like'].'%', PDO::PARAM_STR);
		}
		
		if (array_key_exists('nickname_like', $this->options)) {
			$where_clauses[] = 'usr_nickname ILIKE ?';
			$bind_params[] = array('%'.$this->options['nickname_like'].'%', PDO::PARAM_STR);
		}		

		if (array_key_exists('name_like', $this->options)) {
			$fsearch = preg_replace('/[^A-Za-z0-9\s]/', ' ', $this->options['name_like']);
			$fsearch = trim(preg_replace('/\s+/', ' ', $fsearch));
			$searchwords = explode(' ', $fsearch);
			$where_clauses[] = 'usr_first_name ILIKE ? AND usr_last_name ILIKE ?';
			$bind_params[] = array('%'.$searchwords[0].'%', PDO::PARAM_STR);
			$bind_params[] = array('%'.$searchwords[1].'%', PDO::PARAM_STR);
		}

		if (array_key_exists('email_like', $this->options)) {
			$where_clauses[] = 'usr_email ILIKE ?';
			$bind_params[] = array('%'.$this->options['email_like'].'%', PDO::PARAM_STR);
		}

		if (array_key_exists('email_verified', $this->options)) {
			$where_clauses[] = 'usr_email_is_verified = ' . ($this->options['email_verified'] ? 'TRUE' : 'FALSE');
		}

		if (array_key_exists('admin_disabled', $this->options)) {
			$where_clauses[] = 'usr_is_admin_disabled = ' . ($this->options['admin_disabled'] ? 'TRUE' : 'FALSE');
		}

		if (array_key_exists('disabled', $this->options)) {
			$where_clauses[] = 'usr_is_disabled = ' . ($this->options['disabled'] ? 'TRUE' : 'FALSE');
		}
		
		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'usr_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		
		if (array_key_exists('not_system_users', $this->options)) {
			$where_clauses[] = '(usr_user_id != '.User::USER_SYSTEM.' AND usr_user_id != '.User::USER_DELETED.')';
		}

		if (array_key_exists('permission_range', $this->options)) {
			$where_clauses[] = 'usr_permission >= ? AND usr_permission <= ?';
			$bind_params[] = array($this->options['permission_range'][0], PDO::PARAM_INT);
			$bind_params[] = array($this->options['permission_range'][1], PDO::PARAM_INT);
		}
		

		//NOT INDEXED!
		if (array_key_exists('user_name_fulltext', $this->options)) {
			$fsearch = preg_replace('/[^A-Za-z0-9\s]/', ' ', $this->options['user_name_fulltext']);
			$fsearch = trim(preg_replace('/\s+/', ' ', $fsearch));
			$fsearch = str_replace(' ', ' | ', $fsearch);

			$where_clauses[] = 'to_tsvector(\'english\', usr_first_name || \' \' || usr_last_name) @@ to_tsquery(\'english\', ?)';
			$bind_params[] = array($fsearch, PDO::PARAM_STR);
		}

				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM usr_users ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM usr_users
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " usr_user_id ASC ";
			}
			else {
				if (array_key_exists('user_id', $this->order_by)) {
					$sql .= ' usr_user_id ' . $this->order_by['user_id'];
				}

				if (array_key_exists('signup_date', $this->order_by)) {
					$sql .= ' usr_signup_date ' . $this->order_by['signup_date'];
				}

				if (array_key_exists('last_name', $this->order_by)) {
					$sql .= ' usr_last_name ' . $this->order_by['last_name'];
				}

				if (array_key_exists('first_name', $this->order_by)) {
					$sql .= ' usr_first_name ' . $this->order_by['first_name'];
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
		for($i=0;$i<$total_params;$i++) {
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
			$child = new User($row->usr_user_id);
			$child->load_from_data($row, array_keys(User::$fields));
			$this->add($child);
		}
	}
}

?>
