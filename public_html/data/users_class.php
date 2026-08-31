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

	// Universal unreadable floor (stripped from every API export). usr_password,
	// usr_totp_secret and usr_second_factor_hmac_key are caught by
	// CREDENTIAL_FIELD_PATTERN; these are the real secrets whose names do NOT match it.
	public static $api_unreadable_fields = array(
		'usr_authhash', 'usr_remember_tokens', 'usr_totp_backup_codes',
	);

	// REST CRUD exposure (Layer 1) + write floor (Layer 3). User is readable and
	// writable, but privileged columns can never be set through a CRUD/AI write:
	// usr_permission (the escalation field) and the account-status flags. Credentials
	// (usr_password, usr_totp_*) are caught by CREDENTIAL_FIELD_PATTERN automatically.
	public static $api_readable = true;
	public static $api_writable = true;
	public static $api_unwritable_fields = array(
		'usr_permission', 'usr_is_disabled', 'usr_disabled_time',
		'usr_email_is_verified', 'usr_password_recovery_disabled',
	);
	// Derived keys export_as_array() injects that may leave over the API (fail-closed
	// allowlist). The activation-token derivation is deliberately NOT here and is removed
	// from export_as_array() — see the export_as_array() override below.
	public static $api_derived_fields = array(
		'key', 'display_name', 'usr_day_since_register', 'usr_days_since_last_email',
		'contact_preferences', 'phone', 'address',
	);

	// AI auto-discovery (read)
	public static $ai_readable        = true;
	public static $ai_owner_field     = 'usr_user_id'; // a member reads only their own user row (the pk)
	public static $ai_description     = 'Platform users — admin records, customers, members. Includes contact info, account status, and admin flags.';
	// Relevance/noise trims for the AI surface only. True secrets live in
	// $api_unreadable_fields (the shared floor); they are merged in automatically by
	// ModelSchemaBuilder::excludedFor(), so they are not repeated here.
	public static $ai_excluded_fields = [
		'usr_totp_last_used_step', 'usr_signup_ip', 'usr_allowed_ips',
		'usr_force_password_change', 'usr_password_recovery_disabled',
		'usr_mailing_list_provider_id',
	];
	public static $ai_untrusted_fields = [
		'usr_bio', 'usr_first_name', 'usr_last_name',
		'usr_nickname', 'usr_organization_name',
	];

	//SPECIAL USER IDS
	const USER_SYSTEM = 2;
	const USER_DELETED = 3;

	protected static $foreign_key_actions = [
		// 'pic' isn't a model prefix (the column stores a File id directly),
		// so it doesn't fit the {prefix}_{target_prefix}_..._id convention.
		'usr_pic_picture_id' => ['action' => 'null', 'source_table' => 'fil_files'],
		// A phone number is an attribute of the user, not its owner. Without this
		// the inferred default is 'cascade', which flat-deletes the user row when
		// their phone number is deleted — orphaning every table that hangs off it.
		'usr_phn_phone_number_id' => ['action' => 'null'],
	];

	// Password-change detection for API session key revocation. The hash as
	// loaded from the database is snapshotted on every load path; save()
	// compares against it. The suppress flag lets check_password()'s silent
	// hash upgrades (same plaintext, new hash) skip revocation.
	private $loaded_password_hash = NULL;
	private $suppress_session_key_revocation = FALSE;

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
	    'usr_email_bounce_unverify_time' => array('type'=>'timestamp(6)'),
	    'usr_is_activated' => array('type'=>'bool', 'default'=>false),
	    'usr_is_disabled' => array('type'=>'bool', 'default'=>false),
	    'usr_lastlogin_time' => array('type'=>'timestamp(6)'),
	    'usr_terms_accepted_time' => array('type'=>'timestamp(6)'),
	    // When the user chose "Finish later" on the /setup wizard. Completion is
	    // never stored — only this dismissal (specs/setup_wizard.md).
	    'usr_setup_dismissed_time' => array('type'=>'timestamp(6)'),
	    'usr_pic_picture_id' => array('type'=>'int4'),
	    'usr_phn_phone_number_id' => array('type'=>'int4'),
	    'usr_contact_preferences' => array('type'=>'varchar(32)'),
	    'usr_disabled_time' => array('type'=>'timestamp(6)'),
	    'usr_nickname' => array('type'=>'varchar(32)'),
	    'usr_authhash' => array('type'=>'varchar(32)'),
	    'usr_mailing_list_provider_id' => array('type'=>'varchar(64)'),
	    'usr_signup_ip' => array('type'=>'varchar(64)'),
	    'usr_contact_preference_last_changed' => array('type'=>'timestamp(6)'),
	    'usr_organization_name' => array('type'=>'varchar(32)'),
	    'usr_delete_time' => array('type'=>'timestamp(6)'),
	    'usr_password_recovery_disabled' => array('type'=>'bool'),
	    'usr_allowed_ips' => array('type'=>'jsonb'),
	    'usr_remember_tokens' => array('type'=>'jsonb', 'is_nullable'=>true),
	    'usr_force_password_change' => array('type'=>'bool', 'default'=>false),
	    'usr_bio' => array('type'=>'varchar(500)'),
	    'usr_date_of_birth' => array('type'=>'date'),
	    'usr_gender' => array('type'=>'varchar(30)'),
	    'usr_profile_visibility' => array('type'=>'varchar(20)', 'default'=>'members_only'),
	    'usr_totp_secret' => array('type'=>'varchar(255)'),
	    'usr_totp_backup_codes' => array('type'=>'jsonb'),
	    'usr_totp_enabled_time' => array('type'=>'timestamp(6)'),
	    'usr_totp_last_used_step' => array('type'=>'int8'),
	    // Signs trusted-device cookies (sf_trusted) for ANY second-factor method.
	    // Minted lazily when the first cookie is issued; rotating it is how every
	    // trusted device is revoked at once (specs/second_factor_ux_coherence.md).
	    'usr_second_factor_hmac_key' => array('type'=>'varchar(128)'),
	    // 2FA cadence (specs/mailbox_security_levels.md § 5.2): 'every_login' asks
	    // the second factor on each password sign-in; 'sensitive_only' signs in
	    // password-only and defers the factor to sensitive actions (step-up).
	    'usr_2fa_cadence' => array('type'=>'varchar(20)', 'default'=>'every_login'),
	    // External recovery address (specs/mailbox_security_levels.md § Password
	    // reset, Population 3): an out-of-band inbox a reset link is also sent to,
	    // so a Population-2 user whose login email is a hosted mailbox still has a
	    // path in. Only counts as a reset path once verified.
	    'usr_recovery_email' => array('type'=>'varchar(64)'),
	    'usr_recovery_email_verified_time' => array('type'=>'timestamp(6)'),
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

						// Record LIST_SIGNUP conversion event — one event per fresh subscription.
						// Idempotent re-subscribes are skipped above (is_user_in_list branch).
						require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
						$_session_ctrl = SessionControl::get_instance();
						$_session_ctrl->save_visitor_event(VisitorEvent::TYPE_LIST_SIGNUP, FALSE, 'mailing_list', $mailing_list->key);
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

						// Record LIST_SIGNUP conversion event — one event per fresh subscription.
						// Idempotent re-subscribes are skipped above (is_user_in_list branch).
						require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
						$_session_ctrl = SessionControl::get_instance();
						$_session_ctrl->save_visitor_event(VisitorEvent::TYPE_LIST_SIGNUP, FALSE, 'mailing_list', $mailing_list->key);
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

	//RETURNS AN ARRAY OF CONTACT TYPES THE USER HAS UNSUBSCRIBED FROM
	//A USER WHO HAS NEVER UNSUBSCRIBED FROM ANYTHING HAS NO STORED VALUE, WHICH
	//IS THE SAME ANSWER AS AN EMPTY LIST - SAY SO IN ONE PLACE SO EVERY CALLER
	//BELOW IS HANDED A REAL ARRAY
	public function get_contact_type_unsubscribes(){
		$unsubscribes = json_decode((string)$this->get('usr_contact_type_unsubscribes'));
		return is_array($unsubscribes) ? $unsubscribes : array();
	}

	//WILL RETURN TRUE IF THE USER IS UNSUBSCRIBED FROM THAT CONTACT TYPE
	public function is_unsubscribed_to_contact_type($contact_type_id){
		return in_array($contact_type_id, $this->get_contact_type_unsubscribes());
	}

	//ADDS AN ENTRY TO usr_contact_type_unsubscribes
	public function unsubscribe_from_contact_type($contact_type_id){
		$unsubscribes = $this->get_contact_type_unsubscribes();
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
		$unsubscribes = $this->get_contact_type_unsubscribes();
		if(($key = array_search($contact_type_id, $unsubscribes)) !== false){
			unset($unsubscribes[$key]);
		}
		$this->set('usr_contact_type_unsubscribes', json_encode(array_values($unsubscribes)));
		$this->set('usr_contact_preference_last_changed', 'NOW()');
		$this->save();
		
		return true;
	}

	/**
	 * Guess first and last name from an email address.
	 * Returns ['first_name' => string, 'last_name' => string].
	 */
	static function guessNameFromEmail($email) {
		$prefix = explode('@', $email)[0];

		// Strip trailing digits (e.g. john.smith99)
		$prefix = preg_replace('/\d+$/', '', $prefix);

		// Split on dots, underscores, or hyphens
		$parts = preg_split('/[._\-]+/', $prefix);

		// Filter out empty parts
		$parts = array_values(array_filter($parts, function($p) { return $p !== ''; }));

		if (count($parts) >= 2) {
			$first = ucfirst(strtolower($parts[0]));
			$last = ucfirst(strtolower($parts[count($parts) - 1]));
			return ['first_name' => $first, 'last_name' => $last];
		}

		// Single token — just use as first name
		$name = !empty($parts) ? ucfirst(strtolower($parts[0])) : $prefix;
		return ['first_name' => $name, 'last_name' => ''];
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
			
			$send_emails = array_key_exists('send_emails', $data) ? $data['send_emails'] : true;

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
					// Must be >= 8 chars to satisfy GeneratePassword's minimum-length check.
					// Not surfaced to the user — they reset via the forgot-password flow.
					$temp_password = bin2hex(random_bytes(8));
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
		
		$is_new_user = false;
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
				$is_new_user = true;
			}

			$dblink->commit();
		}
		// Roll back on ANY failure, not just TTClassException. CreateNew reaches
		// GeneratePassword, which throws DisplayableUserException for a password
		// that fails the rules — an ordinary, expected outcome of a bad signup.
		// Catching only TTClassException let that escape with the transaction
		// still open: a web request papered over it by dying, but any long-lived
		// process (CLI, queue worker, test run) kept a poisoned connection and
		// failed every subsequent write with "There is already an active
		// transaction".
		catch (\Throwable $e) {
			if ($dblink->inTransaction()) {
				$dblink->rollBack();
			}
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

		// Record SIGNUP conversion event — only for genuinely new users (not admin-
		// created, not re-login of existing account during guest checkout).
		if ($is_new_user) {
			require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
			$session->save_visitor_event(VisitorEvent::TYPE_SIGNUP, FALSE, 'user', $user->key);
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

		$user_data['usr_day_since_register'] = intval(time() / 86400) - intval(date_create($this->get('usr_signup_date'))->format('U') / 86400);

		unset($user_data['usr_password']);

		$user_data['display_name'] = $this->display_name();

		$user_data['contact_preferences'] = $this->get_contact_type_unsubscribes();

		// Embed children via export_for_api() (not export_as_array()) so the unreadable
		// floor is honored through the nested back door too (§5.5).
		$phone = $this->phone();
		$user_data['phone'] = $phone ? $phone->export_for_api() : NULL;

		$address = $this->address();
		$user_data['address'] = $address ? $address->export_for_api() : NULL;

		//$user_data['NEWSLETTER'] = self::NEWSLETTER;
		//$user_data['EMAIL_OFFERS'] = self::EMAIL_OFFERS;
		//$user_data['EMAIL_UPDATES'] = self::EMAIL_UPDATES;
		//$user_data['EMAIL_USER_FEEDBACK'] = self::EMAIL_USER_FEEDBACK;
		
		$user_data['usr_days_since_last_email'] = $user_data['usr_day_since_register'];

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

	/**
	 * Argon2id parameters for every password-family hash this class makes.
	 * Production: PHP's defaults (64 MB, 4 passes) — an empty array. A harness
	 * test process (JOINERY_TEST_FAST_HASH, set by harness_boot, CLI only):
	 * cheap parameters, because half a second and 64 MB per hash turned
	 * fixture users and sign-in matrices into whole seconds of every gate run.
	 * The hash string carries its own parameters, so password_verify() never
	 * cares. The counterpart rule lives in check_password(): a marked process
	 * never REHASHES, or every production hash a test verified would be
	 * silently rewritten at test cost.
	 */
	/** One suite may opt back into rehash-on-login for its OWN fixture — the
	 *  session-keys suite proves the silent upgrade preserves session keys,
	 *  which needs the upgrade to actually run. Scoped and explicit; the
	 *  default stays off so no other suite can weaken a real row by accident. */
	public static $allow_test_rehash = false;

	public static function password_hash_options() {
		if (PHP_SAPI === 'cli' && defined('JOINERY_TEST_FAST_HASH')) {
			return array('memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1);
		}
		return array();
	}

	public static function GeneratePassword($password) {
		$password = trim($password);
		if (strlen($password) < 8) {
			throw new DisplayableUserException('Your password must be at least 8 characters');
		}

		return password_hash($password, PASSWORD_ARGON2ID, self::password_hash_options());
	}

	function check_password($password) {
		$password = trim($password);
		$stored = trim($this->get('usr_password'));

		// Try modern PHP password_verify first (bcrypt or Argon2id)
		if (password_verify($password, $stored)) {
			// Silently upgrade hash to Argon2id if needed. Same plaintext, new
			// hash — not a credential change, so session keys must survive.
			// Never in a test process (password_hash_options() non-empty):
			// there every production hash "needs" a rehash by the cheap test
			// parameters, and acting on that would overwrite a real user's
			// strong hash with a weak one on the first test sign-in.
			if ((self::password_hash_options() === array() || self::$allow_test_rehash)
					&& password_needs_rehash($stored, PASSWORD_ARGON2ID)) {
				$this->suppress_session_key_revocation = TRUE;
				try {
					$this->set('usr_password', static::GeneratePassword($password));
					$this->save();
				} catch (Exception $e) {
					error_log('Password rehash failed for user ' . $this->key . ': ' . $e->getMessage());
				} finally {
					$this->suppress_session_key_revocation = FALSE;
				}
			}
			return true;
		}

		// Fall back to legacy phpass hashes and upgrade on success. Same
		// plaintext, new hash — not a credential change. Not in a test
		// process, for the same reason as above: the upgrade would write a
		// cheap-parameter hash onto a real row.
		require_once(PathHelper::getIncludePath('includes/PasswordHash.php'));
		$hasher = new PasswordHash(8, TRUE);
		if ($hasher->CheckPassword($password, $stored)) {
			if (self::password_hash_options() !== array() && !self::$allow_test_rehash) {
				return true;
			}
			$this->suppress_session_key_revocation = TRUE;
			try {
				$this->set('usr_password', static::GeneratePassword($password));
				$this->save();
			} catch (Exception $e) {
				error_log('Password rehash failed for user ' . $this->key . ': ' . $e->getMessage());
			} finally {
				$this->suppress_session_key_revocation = FALSE;
			}
			return true;
		}

		return false;
	}

	/**
	 * Check whether 2FA (TOTP) is enabled for this user
	 * Single source of truth: usr_totp_enabled_time IS NOT NULL
	 */
	function has_totp_enabled() {
		return !empty($this->get('usr_totp_enabled_time'));
	}

	/**
	 * The account's 2FA cadence (specs/mailbox_security_levels.md § 5.2):
	 * 'every_login' (the second factor is asked on each password sign-in) or
	 * 'sensitive_only' (sign-in is password-only; the factor is deferred to
	 * sensitive actions). Defaults to 'every_login' for any unrecognized value.
	 */
	function two_factor_cadence() {
		$v = strtolower(trim((string)$this->get('usr_2fa_cadence')));
		return $v === 'sensitive_only' ? 'sensitive_only' : 'every_login';
	}

	/**
	 * The account's verified external recovery address, or '' when none is set
	 * or the pending one is unverified (specs/mailbox_security_levels.md
	 * § Password reset). Only a verified address is a reset path — an unverified
	 * one is a claim, not a capability.
	 */
	function recovery_email() {
		if (empty($this->get('usr_recovery_email_verified_time'))) {
			return '';
		}
		return trim((string)$this->get('usr_recovery_email'));
	}

	/** True when the account holds a verified external recovery address. */
	function has_verified_recovery_email() {
		return $this->recovery_email() !== '';
	}

	/**
	 * True when the account has an active Sealed Vault (any Private/Fortress
	 * mailbox). The vault-holder branch of the password-reset authorizers keys
	 * off this: reset re-issues the session, never the vault, so a vault holder's
	 * passkey reset additionally demands the account's second factor
	 * (specs/mailbox_security_levels.md § Password reset).
	 */
	function has_active_vault() {
		$vault_class = PathHelper::getIncludePath('data/user_encryption_vaults_class.php');
		if (!is_file($vault_class)) {
			return false;
		}
		require_once($vault_class);
		return UserEncryptionVault::loadForUser((int)$this->key) !== null;
	}

	/**
	 * Enable TOTP for this user. Stores the Base32 secret, sets enabled time,
	 * and saves.
	 *
	 * @param string $secret Base32-encoded TOTP secret (already validated against a TOTP code)
	 */
	function enable_totp($secret) {
		$this->set('usr_totp_secret', $secret);
		$this->set('usr_totp_enabled_time', gmdate('Y-m-d H:i:s'));
		$this->set('usr_totp_last_used_step', null);
		$this->save();
	}

	/**
	 * Rotate the trusted-device HMAC key. Every outstanding trusted-device
	 * cookie embeds an HMAC under this key, so rotation signs them all out of
	 * the skip-second-factor grant at once — the shared revocation for every
	 * factor-removal event (forget trusted devices, TOTP turn-off, passkey
	 * revocation). The factors themselves are untouched.
	 */
	function rotate_second_factor_hmac_key() {
		$this->set('usr_second_factor_hmac_key', bin2hex(random_bytes(64)));
		$this->save();
	}

	/**
	 * Disable TOTP for this user. Clears all TOTP state and rotates the
	 * trusted-device HMAC key — removing a factor is the moment device trust
	 * re-earns.
	 */
	function disable_totp() {
		$this->set('usr_totp_secret', null);
		$this->set('usr_totp_enabled_time', null);
		$this->set('usr_totp_backup_codes', null);
		$this->set('usr_totp_last_used_step', null);
		$this->set('usr_second_factor_hmac_key', bin2hex(random_bytes(64)));
		$this->save();
	}

	/**
	 * Verify a 6-digit TOTP code against the stored secret.
	 * Allows +-1 time-step window (30s each) for clock drift.
	 * Rejects codes from the same or earlier step than the last accepted code (replay prevention).
	 * On success, updates usr_totp_last_used_step and saves.
	 *
	 * @param string $code 6-digit TOTP code
	 * @return bool true if valid and not replayed
	 */
	function verify_totp($code) {
		$secret = $this->get('usr_totp_secret');
		if (empty($secret)) {
			return false;
		}

		$code = preg_replace('/[\s-]+/', '', (string)$code);
		if (!preg_match('/^\d{6}$/', $code)) {
			return false;
		}

		$settings = Globalvars::get_instance();
		$composer_path = $settings->get_setting('composerAutoLoad');
		require_once($composer_path . 'autoload.php');

		$totp = \OTPHP\TOTP::createFromSecret($secret);

		$current_step = (int)floor(time() / 30);
		$last_used = (int)$this->get('usr_totp_last_used_step');

		// Try current and +-1 step
		for ($delta = -1; $delta <= 1; $delta++) {
			$candidate_step = $current_step + $delta;
			if ($candidate_step <= $last_used) {
				continue; // Replay prevention
			}
			$ts = $candidate_step * 30;
			if (hash_equals($totp->at($ts), $code)) {
				$this->set('usr_totp_last_used_step', $candidate_step);
				$this->save();
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate 10 backup codes, store Argon2id hashes in usr_totp_backup_codes,
	 * and return the plaintext codes for one-time display to the user.
	 * Display format is XXXX-XXXX (8 alphanumeric chars + dash for readability).
	 * Hashes are computed against the canonical (dash-stripped) form.
	 *
	 * @return array<string> Array of 10 plaintext codes formatted as XXXX-XXXX
	 */
	function generate_backup_codes() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Skip 0/O, 1/I/L for readability
		$display_codes = [];
		$hashes = [];
		for ($i = 0; $i < 10; $i++) {
			$raw = '';
			for ($j = 0; $j < 8; $j++) {
				$raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
			}
			$display_codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
			$hashes[] = password_hash($raw, PASSWORD_ARGON2ID, User::password_hash_options());
		}
		$this->set('usr_totp_backup_codes', json_encode($hashes));
		$this->save();
		return $display_codes;
	}

	/**
	 * Verify a backup code against the stored hashes. Strips dashes and
	 * whitespace before comparison so users can paste with or without the dash.
	 * On success, removes the used code from the array and saves.
	 *
	 * @param string $code 8-character backup code (with or without dash)
	 * @return bool true if valid
	 */
	function verify_backup_code($code) {
		$code = strtoupper(preg_replace('/[\s-]+/', '', (string)$code));
		if (!preg_match('/^[A-Z0-9]{8}$/', $code)) {
			return false;
		}

		$hashes = $this->get('usr_totp_backup_codes');
		if (is_string($hashes)) {
			$hashes = json_decode($hashes, true);
		}
		if (!is_array($hashes) || empty($hashes)) {
			return false;
		}

		foreach ($hashes as $idx => $hash) {
			if (password_verify($code, $hash)) {
				array_splice($hashes, $idx, 1);
				$this->set('usr_totp_backup_codes', json_encode($hashes));
				$this->save();
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the given IP address is allowed to login for this user
	 * Supports exact IPs, CIDR notation (e.g., 104.23.253.0/24), and wildcards (e.g., 104.23.*)
	 *
	 * @param string $ip The IP address to check
	 * @return bool True if allowed (empty whitelist = allow all), false if blocked
	 */
	function is_ip_allowed($ip) {
		$allowed_ips = $this->get('usr_allowed_ips');

		// If no whitelist is set, allow all IPs
		if (empty($allowed_ips)) {
			return true;
		}

		// Decode JSON if it's a string
		if (is_string($allowed_ips)) {
			$allowed_ips = json_decode($allowed_ips, true);
		}

		// If decoding failed or empty array, allow all
		if (!is_array($allowed_ips) || empty($allowed_ips)) {
			return true;
		}

		// Check each allowed entry
		foreach ($allowed_ips as $allowed) {
			// Exact match
			if ($ip === $allowed) {
				return true;
			}

			// CIDR notation (e.g., 192.168.1.0/24)
			if (strpos($allowed, '/') !== false) {
				if (self::ip_in_cidr($ip, $allowed)) {
					return true;
				}
			}

			// Wildcard match (e.g., 104.23.* or 104.23.253.*)
			if (strpos($allowed, '*') !== false) {
				$pattern = '/^' . str_replace(['.', '*'], ['\.', '.*'], $allowed) . '$/';
				if (preg_match($pattern, $ip)) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check if an IP is within a CIDR range
	 *
	 * @param string $ip The IP to check
	 * @param string $cidr The CIDR range (e.g., 192.168.1.0/24)
	 * @return bool True if IP is in range
	 */
	private static function ip_in_cidr($ip, $cidr) {
		list($subnet, $mask) = explode('/', $cidr);

		// Handle IPv6
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			// Simple prefix match for IPv6
			$ip_bin = inet_pton($ip);
			$subnet_bin = inet_pton($subnet);
			if ($ip_bin === false || $subnet_bin === false) {
				return false;
			}
			$mask = intval($mask);
			for ($i = 0; $i < $mask / 8; $i++) {
				if ($ip_bin[$i] !== $subnet_bin[$i]) {
					return false;
				}
			}
			return true;
		}

		// IPv4
		$ip_long = ip2long($ip);
		$subnet_long = ip2long($subnet);
		if ($ip_long === false || $subnet_long === false) {
			return false;
		}
		$mask = ~((1 << (32 - intval($mask))) - 1);
		return ($ip_long & $mask) === ($subnet_long & $mask);
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

	function actions_allowed() {
		if ($this->get('usr_is_disabled') || $this->get('usr_is_admin_disabled')) {
			return FALSE;
		}
		return TRUE;
	}

	// REST API per-record read scope: only the user themselves (or staff,
	// permission >= 5) may read this row via the API. NOTE: the row still
	// includes usr_password via export_as_array() even for the owner — see the
	// export_as_array sensitive-field concern.
	function authenticate_read($data) {
		if ($this->key != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to view this entry in '. static::$tablename);
			}
		}
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

	function load() {
		$result = parent::load();
		$this->loaded_password_hash = ($this->data !== NULL && isset($this->data->usr_password))
			? $this->data->usr_password : NULL;
		return $result;
	}

	function load_from_data($data, $fields) {
		parent::load_from_data($data, $fields);
		$this->loaded_password_hash = $this->get('usr_password');
	}

	function save($debug=false) {
		// A changed hash on an existing, previously loaded user is a credential
		// change — the single choke point for every password-changing flow.
		$password_changed = ($this->key !== NULL
			&& $this->loaded_password_hash !== NULL
			&& $this->get('usr_password') !== NULL
			&& $this->get('usr_password') !== $this->loaded_password_hash);

		parent::save($debug);
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// Revoke all active API session keys when the password changes —
		// the lost-phone / compromised-credential path. Machine keys survive
		// (an admin changing their password must not break integrations).
		if ($password_changed && !$this->suppress_session_key_revocation) {
			require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
			ApiKey::RevokeSessionKeysForUser($this->key);
		}
		$this->loaded_password_hash = $this->get('usr_password');

		//THIS IS A SPECIAL CALCULATED FIELD BASED ON THE USER ID
		if($this->get('usr_authhash') === NULL){
			$authhash = substr(hash('sha256', $this->key.'izsalt'), 0, 8);
			$sql = "UPDATE usr_users SET usr_authhash = :usr_authhash WHERE usr_user_id = :usr_user_id";

			try{
				$q = $dblink->prepare($sql);
				$q->bindParam(':usr_authhash', $authhash, PDO::PARAM_STR);
				$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
				$q->execute();
				$this->set('usr_authhash', $authhash);
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

		// A deletion that fails must ROLL BACK before it propagates. A dependent
		// rule can legitimately refuse this delete (a protected mailbox refusing
		// to lose its only member, say), and without this the refusal escaped
		// with the transaction still open — so every later statement in the
		// request ran inside a doomed transaction and quietly did nothing.
		try {
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

			//DELETE CALENDAR OWNERSHIP: schedules + native entries (polymorphic owner,
			//so not reachable by the generic FK cascade — see CalendarSubject::purge).
			if(!$debug){
				require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));
				CalendarSubject::user($this->key)->purge();
			}

			//DO ANY PREP ABOVE THIS LINE
			parent::permanent_delete($debug);
		} catch (\Throwable $e) {
			if($this_transaction && $dblink->inTransaction()){
				$dblink->rollBack();
			}
			throw $e;
		}

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

	// ===== Entity Photo Methods =====

	/**
	 * Set a photo as the primary photo for this user
	 *
	 * @param int $photo_id EntityPhoto ID to set as primary
	 */
	function set_primary_photo($photo_id) {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

		$photo = new EntityPhoto($photo_id, TRUE);
		$this->set('usr_pic_picture_id', $photo->get('eph_fil_file_id'));
		$this->save();
	}

	/**
	 * Clear the primary photo for this user
	 */
	function clear_primary_photo() {
		$this->set('usr_pic_picture_id', NULL);
		$this->save();
	}

	/**
	 * Get all photos for this user
	 *
	 * @return MultiEntityPhoto
	 */
	function get_photos() {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'user', 'entity_id' => $this->key, 'deleted' => false],
			['eph_sort_order' => 'ASC']
		);
		$photos->load();
		return $photos;
	}

	/**
	 * Get the primary photo EntityPhoto object
	 *
	 * @return EntityPhoto|null
	 */
	function get_primary_photo() {
		$file_id = $this->get('usr_pic_picture_id');
		if (!$file_id) return null;
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'user', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
			[], 1
		);
		$photos->load();
		return $photos->count() > 0 ? $photos->get(0) : null;
	}

	/**
	 * Get picture URL for display
	 *
	 * @param string $size_key Image size key (default 'avatar')
	 * @return string URL or default avatar path
	 */
	function get_picture_link($size_key = 'avatar') {
		$file_id = $this->get('usr_pic_picture_id');
		if (!$file_id) {
			return '/assets/images/blank-avatar.png';
		}
		$file = new File($file_id, TRUE);
		return $file->get_url($size_key);
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
