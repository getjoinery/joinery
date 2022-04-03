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

$settings = Globalvars::get_instance();
$composer_dir = $settings->get_setting('composerAutoLoad');	
require $composer_dir.'autoload.php';
use MailchimpAPI\Mailchimp;

class UserException extends SystemClassException {}
class DisplayableUserException extends UserException implements DisplayableErrorMessage {}

class User extends SystemBase {

	// Constants for contact preferences
	const NEWSLETTER = 1; 
	//const EMAIL_OFFERS = 2;
	//const EMAIL_UPDATES = 4;
	//const EMAIL_USER_FEEDBACK = 8;
	// This needs to be updated if you add any new email types
	const EMAIL_ALL_PREFERENCES = 15;

	// Flags for usr_signup_source
	const SIGNUP_TYPE_NON_SEARCH_WEB = 2;
	const SIGNUP_TYPE_SEND_TO_FRIEND = 3;
	const SIGNUP_TYPE_NO_ENTRY = 4;
	const SIGNUP_TYPE_SEND_TO_FRIEND_DIFFERENT_EMAIL = 6;
	const SIGNUP_TYPE_DIRECT_TRAFFIC = 9;

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
		'usr_contact_preferences' => 'User\'s contact preferences',
		'usr_disabled_time' => 'When user disabled',
		'usr_nickname' => 'Nickname if exists',
		'usr_authhash' => 'first 8 characters of sha256 hash of user id and a salt, used for one click unsubscribe',
		'usr_stripe_customer_id' => 'Stripe customer id for the api',
		'usr_mailchimp_user_id' => 'User id for the mailchimp service',
		'usr_signup_ip' => 'ip of the user when they signed up',
		'usr_contact_preference_last_changed' => 'last time contact preferences was changed',
		'usr_organization_name' => 'Organization instead of person',
		'usr_delete_time' => 'Time of deletion',
		'usr_password_recovery_disabled' => 'When TRUE, password recovery is disabled.'
	);

	public static $timestamp_fields = array(
		'usr_email_is_verified_time', 'usr_lastlogin_time', 'usr_admin_disabled_time',
		'usr_signup_date');

	public static $signup_type_to_description = array(
		self::SIGNUP_TYPE_NON_SEARCH_WEB => 'Web Non Search',
		self::SIGNUP_TYPE_SEND_TO_FRIEND => 'Send To Friend - Email Match',
		self::SIGNUP_TYPE_NO_ENTRY => 'Unknown',
		self::SIGNUP_TYPE_SEND_TO_FRIEND_DIFFERENT_EMAIL => 'Send To Friend - No Email Match',
		self::SIGNUP_TYPE_DIRECT_TRAFFIC => 'Direct Traffic',
	);


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
		'usr_contact_preferences' => 0,
		'usr_signup_date' => 'now()',
		'usr_lastlogin_time' => 'now()',
	);

	public static $public_actions = array(
		'defaultaddressforsession' => array(),
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


	
	public function add_user_to_automatic_groups(){

		if(!$group_all_users = Group::get_by_name("All users")){
			$group_all_users = Group::add_group('All users', $this->key, Group::GROUP_TYPE_USER);
		}
		if(!$group_us_users = Group::get_by_name("US users")){
			$group_us_users = Group::add_group('US users', $this->key, Group::GROUP_TYPE_USER);
		}
		if(!$group_nus_users = Group::get_by_name("Non-US users")){
			$group_nus_users = Group::add_group('Non-US users', $this->key, Group::GROUP_TYPE_USER);
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


	function lock_for_user($editor_id, $type) {

		if($type != User::LOCK_DOJ) {
			throw new UserError("Invalid lock column.");
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		//CLEAR ANY LOCKS
		$sql = "UPDATE usr_users SET $type=NULL WHERE $type=:editor";
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':editor', $editor_id, PDO::PARAM_INT);
			$success = $q->execute();
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		//SET THE LOCK
		$sql = "UPDATE usr_users SET $type=:editor WHERE usr_user_id=:usr_user_id";
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':editor', $editor_id, PDO::PARAM_INT);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$success = $q->execute();
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
	}

	public function get_signup_description() {
		if ($this->get('usr_signup_source')) {
			return self::$signup_type_to_description[$this->get('usr_signup_source')];
		}
		return '';
	}

	public function export_as_array() {
		$user_data = parent::export_as_array();

		$user_data['usr_day_since_register'] = LibraryFunctions::DatetimeIntoDaysAgo(
			date_create($this->get('usr_signup_date')));

		unset($user_data['usr_password']);

		$user_data['display_name'] = $this->display_name();

		$user_data['user_activation_key'] = LibraryFunctions::encode($this->key, 'activation_key');

		$user_data['user_activation_key_qs'] = 'uak=' . $user_data['user_activation_key'];

		if ($this->get('usr_contact_preferences') === NULL) {
			$user_data['contact_preferences'] = User::EMAIL_ALL_PREFERENCES;
		} else {
			$user_data['contact_preferences'] = $this->get('usr_contact_preferences');
		}

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

	function email_verify_user_from_uak_code($code) {
		if (!$this->get('usr_email_is_verified')) {
			$user_code = LibraryFunctions::decode($code, 'activation_key');
			if ($user_code == $this->key) {
				// If the user passed in the key that matches their user account,
				// then lets verify them and reload their user object.
				$this->email_verify_user(TRUE, TRUE);
				$this->load();
			}
		}
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
	
	
	function add_to_mailing_list() {

		
		//TODO NEED TO HANDLE ALL CONTACT PREFERENCE POSSIBILITIES
		$this->set('usr_contact_preferences', 1);
		if($this->get('usr_contact_preference_last_changed') != 1){
			$this->set('usr_contact_preference_last_changed', 'NOW()');
		}
		
		//NOW ADD THE USER TO MAILCHIMP
		try {
		$settings = Globalvars::get_instance();
			if($settings->get_setting('mailchimp_api_key')){
				$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));

				$merge_values = [
					"FNAME" => $this->get('usr_first_name'),
					"LNAME" => $this->get('usr_last_name'),
					"MMERGE3" => 'Yes',
				];

				$post_params = [
					"email_address" => $this->get('usr_email'),
					"status" => "subscribed", 
					"email_type" => "html", 
					"merge_fields" => $merge_values,
				];

				
				$return = $mailchimp 
					->lists($settings->get_setting('mailchimp_list_id'))
					->members()
					->post($post_params);

						
				$status = $return->deserialize();
				
				$mailchimp_user_id = $status->id;
				$this->set('usr_mailchimp_user_id', $mailchimp_user_id);
				$this->save();
				
				return $status;
			}
		} 
		catch (Exception $e) {
			$this->save();
			return FALSE;
		}
		return TRUE;

	}	

	function resubscribe_to_mailing_list() {

		//TODO NEED TO HANDLE ALL CONTACT PREFERENCE POSSIBILITIES
		$this->set('usr_contact_preferences', 1);
		if($this->get('usr_contact_preference_last_changed') != 1){
			$this->set('usr_contact_preference_last_changed', 'NOW()');
		}

		$settings = Globalvars::get_instance();
		if($settings->get_setting('mailchimp_api_key')){
			$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));

			$merge_values = [
				"FNAME" => $this->get('usr_first_name'),
				"LNAME" => $this->get('usr_last_name'),
				"MMERGE3" => 'Yes',
			];

			$post_params = [
				"status" => "subscribed", 
				"merge_fields" => $merge_values,
			];

			try {
				$return = $mailchimp 
					->lists($settings->get_setting('mailchimp_list_id'))
					->members(md5($this->get('usr_email')))
					->patch($post_params);
			} catch (Exception $e) {
				throw new SystemDisplayablePermanentError(
				'There was an error and we were unable to update your contact preferences.');
				exit();	
			}	
		
		}
		$this->save();
		return TRUE;
	}


	function unsubscribe_from_mailing_list() {

		//TODO NEED TO HANDLE ALL CONTACT PREFERENCE POSSIBILITIES
		$this->set('usr_contact_preferences', 0);
		if($this->get('usr_contact_preference_last_changed') != 1){
			$this->set('usr_contact_preference_last_changed', 'NOW()');
		}
		
		$settings = Globalvars::get_instance();
		if($settings->get_setting('mailchimp_api_key')){
			$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));


			$post_params = [
				"status" => "unsubscribed", 
			];

			try {
				$return = $mailchimp 
					->lists($settings->get_setting('mailchimp_list_id'))
					->members(md5($this->get('usr_email')))
					->patch($post_params);
			} catch (Exception $e) {
				throw new SystemDisplayablePermanentError(
				'There was an error and we were unable to update your contact preferences.');
				exit();	
			}			

			$this->save();
			return TRUE;
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

	function load($for_update=FALSE) {
		parent::load();

		$this->data = SingleRowFetch('usr_users', 'usr_user_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS, $for_update);

		if ($this->data === NULL) {
			throw new UserException('Invalid user ID');
		}
	}

	function actions_allowed() {
		if ($this->get('usr_is_disabled') || $this->get('usr_is_admin_disabled')) {
			return FALSE;
		}
		return TRUE;
	}

	function authenticate_write($session) {
		$current_user = $session->get_user_id();
		if ($this->key != $current_user) {
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to modify this user\'s information.');
			}
		}
	}

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('usr_user_id' => $this->key);
			// Editing an existing record
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['usr_user_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "usr_users", $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['usr_user_id'];
		
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

	function soft_delete(){
		$this->set('usr_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('usr_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
	
		if($this->key == User::USER_SYSTEM || $this->key == User::USER_DELETED){
			throw new SystemAuthenticationError(
					'You cannot delete this user.');
		}
	
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}

		$sql = 'DELETE FROM usr_users WHERE usr_user_id=:usr_user_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		$sql = 'DELETE FROM act_activation_codes WHERE act_usr_user_id=:usr_user_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	

		$sql = 'DELETE FROM lfe_log_form_errors WHERE lfe_usr_user_id=:usr_user_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	

		$sql = 'DELETE FROM log_logins WHERE log_usr_user_id=:usr_user_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}


		$sql = 'DELETE FROM evl_event_logs WHERE evl_usr_user_id=:usr_user_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}	
		
		$sql = 'DELETE FROM ers_recurring_email_logs WHERE ers_usr_user_id=:usr_user_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}		


		require_once($siteDir . '/data/orders_class.php');
		$orders = new MultiOrder(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$orders->load();
		
		foreach ($orders as $order){
			$order->permanent_delete();
		}

		require_once($siteDir . '/data/emails_class.php');
		$emails = new MultiEmail(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$emails->load();
		
		foreach ($emails as $email){
			$email->set('eml_usr_user_id', User::USER_DELETED);  //40 IS THE USER ID OF THE SYSTEM DELETED USER
			$email->save();
		}	

		require_once($siteDir . '/data/email_recipients_class.php');
		$email_recipients = new MultiEmailRecipient(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$email_recipients->load();
		
		foreach ($email_recipients as $email_recipient){
			$email_recipient->permanent_delete();
		}

		require_once($siteDir . '/data/events_class.php');
		$events = new MultiEvent(
		array('user_id_leader'=>$this->key),
		NULL,
		NULL,
		NULL);
		$events->load();
		
		foreach ($events as $event){
			$event->set('evt_usr_user_id', User::USER_DELETED);  //40 IS THE USER ID OF THE SYSTEM DELETED USER
			$event->save();
		}	

		require_once($siteDir . '/data/event_registrants_class.php');
		$event_registrants = new MultiEventRegistrant(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$event_registrants->load();
		
		foreach ($event_registrants as $event_registrant){
			$event_registrant->remove();
		}		


		require_once($siteDir . '/data/posts_class.php');
		$posts = new MultiPost(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$posts->load();
		
		foreach ($posts as $post){
			$post->set('pst_usr_user_id', User::USER_DELETED);  //40 IS THE USER ID OF THE SYSTEM DELETED USER
			$post->save();
		}

		require_once($siteDir . '/data/phone_number_class.php');
		$phone_numbers = new MultiPhoneNumber(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$phone_numbers->load();
		
		foreach ($phone_numbers as $phone_number){
			$phone_number->permanent_delete();
		}

		require_once($siteDir . '/data/address_class.php');
		$addresses = new MultiAddress(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$addresses->load();
		
		foreach ($addresses as $address){
			$address->permanent_delete();
		}

		require_once($siteDir . '/data/videos_class.php');
		$videos = new MultiVideo(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$videos->load();
		
		foreach ($videos as $video){
			$video->set('vid_usr_user_id', User::USER_DELETED);  //40 IS THE USER ID OF THE SYSTEM DELETED USER
			$video->save();
		}	
		
		require_once($siteDir . '/data/files_class.php');
		$files = new MultiFile(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$files->load();
		
		foreach ($files as $file){
			$file->set('fil_usr_user_id', User::USER_DELETED);  //40 IS THE USER ID OF THE SYSTEM DELETED USER
			$file->save();
		}	
	
		require_once($siteDir . '/data/messages_class.php');
		$messages = new MultiMessage(
		array('user_id_recipient'=>$this->key),
		NULL,
		NULL,
		NULL);
		$messages->load();
		
		foreach ($messages as $message){
			$message->permanent_delete();
		}

		$messages = new MultiMessage(
		array('user_id_sender'=>$this->key),
		NULL,
		NULL,
		NULL);
		$messages->load();
		
		foreach ($messages as $message){
			$message->set('user_id_sender', User::USER_DELETED);  //40 IS THE USER ID OF THE SYSTEM DELETED USER
			$message->save();
		}		

		/*
		$details = new MultiProductDetail(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$details->load();
		foreach ($details as $detail){
			$detail->permanent_delete();
		}
		*/		


		$group_members = new MultiGroupMember(
		array('user_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$group_members->load();
		
		foreach ($group_members as $group_member){
			$group_member->remove();
		}			

		$groups = new MultiGroup(
		array('grp_usr_user_id_created'=>$this->key),
		NULL,
		NULL,
		NULL);
		$groups->load();
		
		foreach ($groups as $group){
			$group->set('grp_usr_user_id_created', User::USER_DELETED); //40 is the system deleted user
			$group->save();
		}
		
		
		if($this_transaction){
			$dblink->commit();
		}	

		$this->unsubscribe_from_mailing_list();
		$this->key = NULL;
		
		return true;
		
	}

	static function GetPublicActions() {
		return self::$public_actions;
	}

	static function DefaultAddressForSession($session, $request) {
		if ($session->get_user_id()) {
			$address_id = Address::GetDefaultAddressForUser($session->get_user_id());
			if ($address_id) {
				return new Address($address_id, TRUE);
			} else {
				throw new SystemDisplayableUserException('No default address for this user', -1);
			}
		} else {
			throw new UserException("Invalid session for this action.");
		}
	}
	
	//BECAUSE USERS IS THE PRIMARY CLASS, WE PUT ALL OF THE NECESSARY TABLES HERE
	//TODO: THIS IS A WORK IN PROGRESS
	static function InitDB($mode='structure'){



		//USERS
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS usr_users_usr_user_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."usr_users" (
			  "usr_user_id" int8 NOT NULL DEFAULT nextval(\'usr_users_usr_user_id_seq\'::regclass),
			  "usr_first_name" varchar(32) COLLATE "pg_catalog"."default",
			  "usr_last_name" varchar(32) COLLATE "pg_catalog"."default",
			  "usr_email" varchar(64) COLLATE "pg_catalog"."default",
			  "usr_signup_date" date,
			  "usr_password" char(34) COLLATE "pg_catalog"."default",
			  "usr_permission" int4,
			  "usr_timezone" varchar(32) COLLATE "pg_catalog"."default",
			  "usr_email_is_verified" bool,
			  "usr_is_activated" bool,
			  "usr_is_disabled" bool NOT NULL DEFAULT false,
			  "usr_lastlogin_time" timestamp(6),
			  "usr_pic_picture_id" int8,
			  "usr_phn_phone_number_id" int8,
			  "usr_contact_preferences" varchar(32) COLLATE "pg_catalog"."default",
			  "usr_disabled_time" timestamp(6),
			  "usr_email_is_verified_time" timestamp(6),
			  "usr_nickname" varchar(128) COLLATE "pg_catalog"."default",
			  "usr_stripe_customer_id" varchar(32) COLLATE "pg_catalog"."default",
			  "usr_authhash" varchar(32) COLLATE "pg_catalog"."default",
			  "usr_mailchimp_user_id" varchar(64) COLLATE "pg_catalog"."default",
			  "usr_signup_ip" varchar(64) COLLATE "pg_catalog"."default",
			  "usr_contact_preference_last_changed" timestamp(6),
			  "usr_delete_time" timestamp(6),
			  "usr_password_recovery_disabled" boolean,
			  "usr_organization_name" varchar(32)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."usr_users" ADD CONSTRAINT "usr_users_pkey" PRIMARY KEY ("usr_user_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		
		try{
			$sql = 'CREATE UNIQUE INDEX "act_code_unique_non_deleted_index" ON "public"."act_activation_codes" USING btree (
			  "act_code" COLLATE "pg_catalog"."default" "pg_catalog"."bpchar_ops" ASC NULLS LAST
			) WHERE act_deleted = false;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}	

		
		
		//ACTIVATION CODES
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."act_activation_codes" (
			  "act_usr_email" varchar(128) COLLATE "pg_catalog"."default",
			  "act_code" char(12) COLLATE "pg_catalog"."default" NOT NULL,
			  "act_expires_time" timestamp(6),
			  "act_usr_user_id" int4,
			  "act_purpose" int2 NOT NULL DEFAULT 0,
			  "act_created_time" timestamp(6) NOT NULL DEFAULT now(),
			  "act_phn_phone_number_id" int4,
			  "act_deleted" bool NOT NULL DEFAULT false
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'COMMENT ON COLUMN "public"."act_activation_codes"."act_purpose" IS \'0=none
			1=pic upload
			2=email verify
			3=phone verify\';';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		
		try{
			$sql = 'CREATE UNIQUE INDEX "act_code_unique_non_deleted_index" ON "public"."act_activation_codes" USING btree (
			  "act_code" COLLATE "pg_catalog"."default" "pg_catalog"."bpchar_ops" ASC NULLS LAST
			) WHERE act_deleted = false;';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}		

		//VISITOR EVENTS
		
		$sql = '
			CREATE TABLE IF NOT EXISTS "public"."vse_visitor_events" (
		  "vse_visitor_id" varchar(20) COLLATE "pg_catalog"."default",
		  "vse_usr_user_id" int4,
		  "vse_type" int2,
		  "vse_ip" varchar(64) COLLATE "pg_catalog"."default",
		  "vse_page" varchar(255) COLLATE "pg_catalog"."default",
		  "vse_referrer" varchar(255) COLLATE "pg_catalog"."default",
		  "vse_source" varchar(255) COLLATE "pg_catalog"."default",
		  "vse_campaign" varchar(255) COLLATE "pg_catalog"."default",
		  "vse_timestamp" timestamp(6) DEFAULT now(),
		  "vse_medium" varchar(255) COLLATE "pg_catalog"."default",
		  "vse_content" varchar(255) COLLATE "pg_catalog"."default",
		  "vse_is_404" bool
		)
		;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();

		

	
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
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
	
	private function _get_results($only_count=FALSE) { 
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
			$sql = 'SELECT COUNT(1) FROM usr_users ' . $where_clause;
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
			$child = new User($row->usr_user_id);
			$child->load_from_data($row, array_keys(User::$fields));
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
