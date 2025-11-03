<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/address_class.php'));
require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
require_once(PathHelper::getIncludePath('data/activation_codes_class.php'));
require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
require_once(PathHelper::getIncludePath('data/contact_types_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

class UserException extends SystemBaseException {}
class DisplayableUserException extends UserException implements DisplayableErrorMessage {}

class User extends SystemBase {	public static $prefix = 'usr';
	public static $tablename = 'usr_users';
	public static $pkey_column = 'usr_user_id';

	//SPECIAL USER IDS
	const USER_SYSTEM = 2;
	const USER_DELETED = 3;

		/**
	 * Field specifications define database column properties and validation rules
	 * 
	 * Database schema properties (used by update_database):
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp' | 'bool' | etc.
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'serial' => true/false - Auto-incrementing field
	 * 
	 * Validation and behavior properties (used by SystemBase):
	 *   'required' => true/false - Field must have non-empty value on save
	 *   'default' => mixed - Default value for new records (applied on INSERT only)
	 *   'zero_on_create' => true/false - Set to 0 when creating if NULL (INSERT only)
	 * 
	 * Note: Timestamp fields are auto-detected based on type for smart_get() and export_as_array()
	 */
	public static $field_specifications = array(
	    'usr_user_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'usr_first_name' => array('type'=>'varchar(32)', 'required'=>true),
	    'usr_last_name' => array('type'=>'varchar(32)'),
	    'usr_email' => array('type'=>'varchar(64)', 'required'=>true, 'validation' => array('email' => true)),
	    'usr_signup_date' => array('type'=>'date', 'default'=>'now()'),
	    'usr_password' => array('type'=>'varchar(255)'),
	    'usr_permission' => array('type'=>'int4', 'zero_on_create'=>true),
	    'usr_timezone' => array('type'=>'varchar(32)', 'required'=>true, 'default'=>'America/New_York'),
	    'usr_email_is_verified' => array('type'=>'bool', 'default'=>false),
	    'usr_email_is_verified_time' => array('type'=>'timestamp(6)'),
	    'usr_is_activated' => array('type'=>'bool', 'default'=>false),
	    'usr_is_disabled' => array('type'=>'bool', 'default'=>false),
	    'usr_lastlogin_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
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
	    'usr_calendly_uri' => array('type'=>'varchar(255)'),
	    'usr_stripe_customer_id_test' => array('type'=>'varchar(32)'),
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

	public function add_user_to_mailing_lists($mailing_list_ids){
		if(empty($mailing_list_ids)){
			$mailing_list_ids = array();
		}
		else if($mailing_list_ids == 'all'){
			$mailing_list_ids = 'all';
		}	
		else if(!is_array($mailing_list_ids)){
			$mailing_list_ids = array($mailing_list_ids);
		}

		$search_criteria = array();
		$mailing_lists = new MultiMailingList(
			$search_criteria,
			array('name'=>'ASC'));	
		$mailing_lists->load();		

		$messages = array();
		$thismessage = array();
		foreach ($mailing_lists as $mailing_list){
					
			if($mailing_list_ids == 'all'){
				if($mailing_list->is_user_in_list($this->key)){
					//IF USER IS ALREADY SUBSCRIBED
					$thismessage['message_type'] = 'warn';
					$thismessage['message_title'] = 'Notice';
					$thismessage['message'] = 'You are already SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
				else{
					//IF USER IS NOT SUBSCRIBED
					$status = $mailing_list->add_registrant($this->key);
					if($status){
						$thismessage['message_type'] = 'success';
						$thismessage['message_title'] = 'Success';
						$thismessage['message'] = 'You are SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
					else{
						$thismessage['message_type'] = 'error';
						$thismessage['message_title'] = 'Error';
						$thismessage['message'] = 'There was an error adding you to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
				}				
			}
			else if(in_array($mailing_list->key, $mailing_list_ids)){
				//IF IT IS A CHOICE AND SELECTED
				if($mailing_list->is_user_in_list($this->key)){
					//IF USER IS ALREADY SUBSCRIBED
					$thismessage['message_type'] = 'warn';
					$thismessage['message_title'] = 'Notice';
					$thismessage['message'] = 'You are already SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
					$messages[] = $thismessage;
				}
				else{
					//IF USER IS NOT SUBSCRIBED
					$status = $mailing_list->add_registrant($this->key);
					if($status){
						$thismessage['message_type'] = 'success';
						$thismessage['message_title'] = 'Success';
						$thismessage['message'] = 'You are SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
					else{
						$thismessage['message_type'] = 'error';
						$thismessage['message_title'] = 'Error';
						$thismessage['message'] = 'There was an error adding you to the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
				}
			}
			else{

				//IF IT IS A CHOICE AND NOT SELECTED
				if($mailing_list->is_user_in_list($this->key)){
					//IF USER IS SUBSCRIBED
					$status = $mailing_list->remove_registrant($this->key);
					if($status){
						$thismessage['message_type'] = 'success';
						$thismessage['message_title'] = 'Success';
						$thismessage['message'] = 'You are UNSUBSCRIBED from the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
					else{
						$thismessage['message_type'] = 'error';
						$thismessage['message_title'] = 'Error';
						$thismessage['message'] = 'There was an error removing you from the following lists: ' . $mailing_list->get('mlt_name');
						$messages[] = $thismessage;
					}
				}	
			}				
		}		
		
		return $messages;

	}

	//ALL CONTACT TYPE FUNCTIONS BELOW ARE UNUSED
	
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

	static function CreateNew($data){   
	
			if(!$first_name = $data['usr_first_name']){
				throw new SystemDisplayablePermanentError("Missing first name in create user.");
			}
			
			if(!$last_name = $data['usr_last_name']){
				throw new SystemDisplayablePermanentError("Missing last name in create user.");
			}
				
			if(!$email = $data['usr_email']){
				throw new SystemDisplayablePermanentError("Missing email in create user.");
			}
					
			if(!$password = $data['password']){
				$password = NULL;
			}
			
			if(!$send_emails = $data['send_emails']){
				$send_emails = true;
			}

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
			$user->set('usr_email', strip_tags(trim(strtolower($email))));
			$user->set('usr_first_name', strip_tags(trim($first_name)));
			$user->set('usr_last_name', strip_tags(trim($last_name)));	
			$user->set('usr_password', $temp_password_hashed);	
			$user->set('usr_signup_ip', $_SERVER['REMOTE_ADDR']);
			if($data['usr_nickname']){
				$user->set('usr_nickname', strip_tags(trim($data['usr_nickname'])));
			}		

			if($data['usr_timezone']){
				try {
					new DateTimeZone($data['usr_timezone']);
					$user->set('usr_timezone', $data['usr_timezone']);
				} catch (Exception $e) {
					require_once(__DIR__ . '/../includes/Exceptions/ValidationException.php');
					throw new ValidationException('The timezone you entered is invalid.');
				}
			}
			
			$user->prepare();
			$user->save();
			$user->load();
			
			if($send_emails){
				$settings = Globalvars::get_instance();
				require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
				require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
				require_once(PathHelper::getIncludePath('includes/Activation.php'));
				
				//SEND NEW USER WELCOME EMAIL
				EmailSender::sendTemplate('new_account_content',
					$user->get('usr_email'),
					array_merge($email_fill, ['recipient' => $user->export_as_array()])
				);	
				
				//SEND ACTIVATION EMAIL
				Activation::email_activate_send($user);
			}
			
			if($user){
				return $user;
			}
			else{
				throw new SystemDisplayablePermanentError("Failed to create user.");
			}
	}

	static function CreateCompleteNew($data, $send_emails, $log_in, $set_cookie){
		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$dblink->beginTransaction();
		
		try {
		
			$user = User::GetByEmail(trim($data['usr_email']));
			if(!$user){
				$tdata = array(
					'usr_first_name' => $data['usr_first_name'],
					'usr_last_name' => $data['usr_last_name'],
					'usr_email' => $data['usr_email'],
					'send_emails' => $send_emails
				);
				
				if($data['password']){
					$tdata['password'] = $data['password'];
				}
				
				if($data['usr_nickname']){
					$tdata['usr_nickname'] = $data['usr_nickname'];
				}			
				
				if($data['usr_timezone']){
					$tdata['usr_timezone'] = $data['usr_timezone'];
				}			

				$user = User::CreateNew($tdata);
				
			}
		
			$dblink->commit();
		} 
		catch (TTClassException $e) {
			$dblink->rollBack();
			throw $e;
		}

		/*
		$address = new Address(NULL);
		$address->set('usa_city', $zip_data->zip_city);
		$address->set('usa_state', $zip_data->zip_state);
		$address->set('usa_zip_code_id', $zip_data->zip_code_id);
		$address->set('usa_type', 'HM');
		$address->set('usa_usr_user_id', $user->key);
		$address->set('usa_is_default', TRUE);
		$address->set('usa_privacy', 2);
		$address->save();
		$address->update_coordinates();
		*/

		if($log_in){
			$session->clear_formfields();
			$session->store_session_variables($user);
			$session->set_initial_user_id($user->key);
			if ($set_cookie) {
				$session->save_user_to_cookie();
			}
		}

		//ADD TO THE MAILING LIST IF CHOSEN
		if(isset($data['newsletter']) && $data['newsletter']){
			if($settings->get_setting('default_mailing_list')){
				$messages = $user->add_user_to_mailing_lists($settings->get_setting('default_mailing_list'));
				//$status = $user->subscribe_to_contact_type($settings->get_setting('default_mailing_list'));		
			}
		}		

		//IF THE USER ENTERED A PHONE NUMBER, SAVE THAT
		if(!$user->phone() && $data['phn_phone_number']){
			$phone_number = PhoneNumber::CreateFromForm($data, $user->key, NULL, FALSE);
		}
		
		//IF THE USER ENTERED AN ADDRESS, SAVE THAT
		if(!$user->address() && $data['address']){
			$address = $data['address'];
			if(!$address->get('usa_usr_user_id')){
				$address->set('usa_usr_user_id', $user->key);
				$address->save();
			}
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

		//$user_data['NEWSLETTER'] = self::NEWSLETTER;
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
					'Sorry, that email address "'.$this->get('usr_email').'" has already been used.  Please try again.');				
			}
		}

		if (!LibraryFunctions::IsValidEmail($this->get('usr_email'))) {
			throw new DisplayableUserException(
				'Sorry, that email address "'.$this->get('usr_email').'" you entered is invalid.  Please try again.');
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
		$user->load_from_data($data, array_keys(User::$field_specifications));
		return $user;
	}

	public static function GetByStripeCustomerId($id) {
		$data = SingleRowFetch('usr_users', 'usr_stripe_customer_id',
			$id, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$field_specifications));
		return $user;
	}

	public static function GetByCalendlyUri($uri) {
		$data = SingleRowFetch('usr_users', 'usr_calendly_uri',
			$uri, PDO::PARAM_STR, SINGLE_ROW_ALL_COLUMNS);

		if ($data === NULL) {
			return NULL;
		}

		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$field_specifications));
		return $user;
	}
	
	public static function GeneratePassword($password) {
		$password = trim($password);
		if (strlen($password) < 5) {
			throw new DisplayableUserException('Your password must be at least 5 characters');
		}

		if (strstr(' ', $password) !== FALSE) {
			throw new DisplayableUserException('Your password cannot contain spaces.');
		}

		return password_hash($password, PASSWORD_BCRYPT);
	}

	function check_password($password) {
		$password = trim($password);
		
		//USE THE NEW VERSION FIRST, IF THAT FAILS TRY THE OLD VERSION
		if(password_verify($password, trim($this->get('usr_password')))){
			return true;
		}
		else{
			$settings = Globalvars::get_instance();
			require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
			$hasher = new PasswordHash(8, TRUE);
			return $hasher->CheckPassword($password, trim($this->get('usr_password')));			
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

	function authenticate_write($data) {
		if ($this->key != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename.'-'.$data['current_user_permission'] );
			}
		}
	}

	function save($debug=false) {
		parent::save($debug);
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
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
		$groups = Group::get_groups_in_category('user', false, 'objects');
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
	static function test($debug=false, $verbose=false, $read_only=false){
		parent::test($debug, $verbose, $read_only);
		
		// Skip test database operations in read-only mode
		if ($read_only) {
			return true;
		}
		
		$dbhelper = DbConnector::get_instance();
		$dbhelper->set_test_mode();
		$dblink = $dbhelper->get_db_link();		
		
		$email = LibraryFunctions::random_string(10).'@test.com';
		//NEW USER
		
		$data = array(
			'usr_first_name' => LibraryFunctions::random_string(10),
			'usr_last_name' => LibraryFunctions::random_string(10),
			'usr_email' => $email,
			'password' => 'testpass',
			'send_emails' => false
		);
		$user = User::CreateNew($data);
	
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
	protected static $model_class = 'User';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->key] = $item->display_name().' - '.$item->get('usr_email');
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['user_id_list'])) {
            if(count($this->options['user_id_list'])) {
                $filters['usr_user_id'] = 'IN ('.implode(',', $this->options['user_id_list']).')';
            }
        }

        if (isset($this->options['first_name_like'])) {
            $filters['usr_first_name'] = 'ILIKE \'%'.$this->options['first_name_like'].'%\'';
        }

        if (isset($this->options['last_name_like'])) {
            $filters['usr_last_name'] = 'ILIKE \'%'.$this->options['last_name_like'].'%\'';
        }
        
        if (isset($this->options['nickname_like'])) {
            $filters['usr_nickname'] = 'ILIKE \'%'.$this->options['nickname_like'].'%\'';
        }

        if (isset($this->options['name_like'])) {
            $fsearch = preg_replace('/[^A-Za-z0-9\s]/', ' ', $this->options['name_like']);
            $fsearch = trim(preg_replace('/\s+/', ' ', $fsearch));
            $searchwords = explode(' ', $fsearch);
            if (count($searchwords) >= 2) {
                $filters['usr_first_name'] = 'ILIKE \'%'.$searchwords[0].'%\' AND usr_last_name ILIKE \'%'.$searchwords[1].'%\'';
                unset($filters['usr_last_name']); // Prevent duplicate condition
            }
        }

        if (isset($this->options['email_like'])) {
            $filters['usr_email'] = 'ILIKE \'%'.$this->options['email_like'].'%\'';
        }

        if (isset($this->options['email_verified'])) {
            $filters['usr_email_is_verified'] = $this->options['email_verified'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['admin_disabled'])) {
            $filters['usr_is_admin_disabled'] = $this->options['admin_disabled'] ? "= TRUE" : "= FALSE";
        }

        if (isset($this->options['disabled'])) {
            $filters['usr_is_disabled'] = $this->options['disabled'] ? "= TRUE" : "= FALSE";
        }
        
        if (isset($this->options['deleted'])) {
            $filters['usr_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        if (isset($this->options['not_system_users'])) {
            $filters['usr_user_id'] = '!= '.User::USER_SYSTEM.' AND usr_user_id != '.User::USER_DELETED;
        }

        if (isset($this->options['permission_range'])) {
            $filters['usr_permission'] = '>= '.$this->options['permission_range'][0].' AND usr_permission <= '.$this->options['permission_range'][1];
        }

        if (isset($this->options['user_name_fulltext'])) {
            $fsearch = preg_replace('/[^A-Za-z0-9\s]/', ' ', $this->options['user_name_fulltext']);
            $fsearch = trim(preg_replace('/\s+/', ' ', $fsearch));
            $fsearch = str_replace(' ', ' | ', $fsearch);
            $filters['to_tsvector(\'english\', usr_first_name || \' \' || usr_last_name)'] = '@@ to_tsquery(\'english\', \''.$fsearch.'\')';
        }

        return $this->_get_resultsv2('usr_users', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
