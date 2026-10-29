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
require_once(PathHelper::getIncludePath('data/users_addrs_class.php'));
require_once(PathHelper::getIncludePath('data/phone_numbers_class.php'));
require_once(PathHelper::getIncludePath('data/activation_codes_class.php'));
require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));
require_once(PathHelper::getIncludePath('data/contact_types_class.php'));
require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

class UserException extends SystemBaseException {}
class DisplayableUserException extends UserException implements DisplayableErrorMessage {}

class User extends SystemBase {	public static $prefix = 'usr';
	public static $tablename = 'usr_users';
	public static $access_class = 'personal';
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
		'usr_email_is_verified', 'usr_password_recovery_disabled', 'usr_session_generation',
	);
	// The sign-in identifiers change only through the account page, behind a
	// second-factor re-check (and, for an email, a link mailed to the new address:
	// account_edit_logic, email_change), never by an API update that skips it.
	public static $api_create_only_fields = array('usr_email', 'usr_username');

	// The model tester fills the email: an account needs an email or a username
	// (check_identifiers()), a rule no single field's spec can say.
	public static $test_fixture = array('generate' => array('usr_email'));

	// Each sign-in identifier is unique regardless of capitals among live accounts;
	// check_identifiers() also keeps a deleted account's identifiers taken.
	public static $index_specifications = array(
		array('columns' => array('LOWER(usr_email)'), 'unique' => true, 'where' => 'usr_delete_time IS NULL'),
		array('columns' => array('LOWER(usr_username)'), 'unique' => true, 'where' => 'usr_delete_time IS NULL'),
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
		'usr_mailing_list_provider_id', 'usr_session_generation',
	];
	public static $ai_untrusted_fields = [
		'usr_bio', 'usr_first_name', 'usr_last_name',
		'usr_nickname', 'usr_organization_name',
	];

	//SPECIAL USER IDS
	const USER_SYSTEM = 2;
	const USER_DELETED = 3;

	/** What anyone is told when an address they typed belongs to a deleted account. */
	const CLOSED_ACCOUNT_MESSAGE = 'That address belongs to a closed account. Contact us to reopen it.';

	/**
	 * A signed-in person with no email who asked for something that needs one
	 * (checkout, a mailing list) goes to their account page, where they can add
	 * an address, and the page says why ($why: "to check out ..."). Plain text.
	 */
	public static function add_email_first(string $why): LogicResult {
		SessionControl::get_instance()->save_message(new DisplayMessage(
			'Add an email address to your account ' . $why . '.', 'Email needed',
			'/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_WARNING,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE));
		return LogicResult::redirect('/profile/account_edit');
	}

	/** Why checkout needs one. */
	const CHECKOUT_NEEDS_EMAIL = 'to check out: your receipt and what you buy are sent there';

	/**
	 * The system and deleted-user rows stand in for "nobody in particular": a
	 * task runs as the system user, a departed member's rows are re-owned by the
	 * deleted user. Neither is a person. Their email addresses are placeholders
	 * on a domain nobody here controls, so anything that talks TO a user — a
	 * notification, a queued email — has nobody to talk to and must skip them.
	 */
	public static function is_placeholder($user_id) {
		$user_id = (int)$user_id;
		return $user_id === self::USER_SYSTEM || $user_id === self::USER_DELETED;
	}

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
	    'usr_email' => array('type'=>'varchar(64)', 'validation' => array('email' => true)),
	    // An account has an email, a username, or both (check_identifiers()).
	    'usr_username' => array('type'=>'varchar(32)'),
	    'usr_signup_date' => array('type'=>'date', 'default'=>'now()'),
	    'usr_password' => array('access_class' => 'restricted', 'type'=>'varchar(255)'),
	    'usr_permission' => array('type'=>'int4', 'zero_on_create'=>true),
	    'usr_timezone' => array('type'=>'varchar(32)', 'required'=>true, 'default'=>'America/New_York'),
	    'usr_email_is_verified' => array('type'=>'bool', 'default'=>false),
	    'usr_email_is_verified_time' => array('type'=>'timestamp(6)'),
	    'usr_email_bounce_unverify_time' => array('type'=>'timestamp(6)'),
	    'usr_is_activated' => array('type'=>'bool', 'default'=>false),
	    'usr_is_disabled' => array('type'=>'bool', 'default'=>false),
	    'usr_lastlogin_time' => array('type'=>'timestamp(6)'),
	    'usr_terms_accepted_time' => array('type'=>'timestamp(6)'),
	    // When the user chose "Finish later" on the /setup wizard: the login
	    // interrupt and the header pill both stop. Completion is never stored
	    // (specs/setup_wizard.md).
	    'usr_setup_dismissed_time' => array('type'=>'timestamp(6)'),
	    // When the user reached the wizard's final checklist and left for the
	    // site: the login interrupt stops, the header pill stays until every
	    // step is green.
	    'usr_setup_reviewed_time' => array('type'=>'timestamp(6)'),
	    // The wizard steps this user had in front of them when they last left it
	    // (step keys). A step not listed that applies to them now and is not done
	    // brings the wizard back once, at just that step (SetupSteps::unseenSteps).
	    'usr_setup_seen_steps' => array('type'=>'jsonb', 'is_nullable'=>true),
	    'usr_pic_picture_id' => array('type'=>'int4'),
	    'usr_phn_phone_number_id' => array('type'=>'int4'),
	    'usr_contact_preferences' => array('type'=>'varchar(32)'),
	    'usr_disabled_time' => array('type'=>'timestamp(6)'),
	    'usr_nickname' => array('type'=>'varchar(32)'),
	    'usr_authhash' => array('access_class' => 'restricted', 'type'=>'varchar(32)'),
	    'usr_mailing_list_provider_id' => array('type'=>'varchar(64)'),
	    'usr_signup_ip' => array('type'=>'varchar(64)'),
	    'usr_contact_preference_last_changed' => array('type'=>'timestamp(6)'),
	    'usr_organization_name' => array('type'=>'varchar(32)'),
	    'usr_delete_time' => array('type'=>'timestamp(6)'),
	    'usr_password_recovery_disabled' => array('type'=>'bool'),
	    'usr_allowed_ips' => array('access_class' => 'config', 'type'=>'jsonb'),
	    'usr_remember_tokens' => array('access_class' => 'restricted', 'type'=>'jsonb', 'is_nullable'=>true),
	    // Moves on with every real password change; a browser session stamped
	    // with an older value is signed out (SessionControl::end_other_sessions()).
	    'usr_session_generation' => array('type'=>'int4', 'default'=>0),
	    'usr_force_password_change' => array('type'=>'bool', 'default'=>false),
	    'usr_bio' => array('type'=>'varchar(500)'),
	    'usr_date_of_birth' => array('type'=>'date'),
	    'usr_gender' => array('type'=>'varchar(30)'),
	    'usr_profile_visibility' => array('type'=>'varchar(20)', 'default'=>'members_only'),
	    // A SecretBox blob (TOTP_SECRET_LOCATOR), never plain text: totp_secret() opens it.
	    'usr_totp_secret' => array('access_class' => 'keys', 'type'=>'text'),
	    'usr_totp_backup_codes' => array('access_class' => 'restricted', 'type'=>'jsonb'),
	    'usr_totp_enabled_time' => array('type'=>'timestamp(6)'),
	    'usr_totp_last_used_step' => array('type'=>'int8'),
	    // Signs trusted-device cookies (sf_trusted) for ANY second-factor method.
	    // Minted lazily when the first cookie is issued; rotating it is how every
	    // trusted device is revoked at once (specs/second_factor_ux_coherence.md).
	    // A SecretBox blob (HMAC_KEY_LOCATOR): second_factor_hmac_key() opens it.
	    'usr_second_factor_hmac_key' => array('access_class' => 'keys', 'type'=>'text'),
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
	    // Where this member's Private content may be read by AI: the most
	    // permissive endpoint trust class it may reach — local | trusted | cloud
	    // (PrivateContentConsent). Starts at local: private content never
	    // travels until the member says so.
	    'usr_private_ai_consent' => array('type'=>'varchar(10)', 'is_nullable'=>false, 'default'=>'local'),
	);

/**
	 * A name as a person writes it: every character kept (accents, spaces,
	 * any script), spacing tidied, and re-cased only when it was typed all
	 * lower or all upper case ("josé" and "JOSÉ" become "José"; "McDonald"
	 * and "van der Berg" are left as they are).
	 */
	private static function UcName($string) {
		$string = trim(preg_replace('/\s+/u', ' ', (string)$string));
		$letters = preg_replace('/[^\p{L}]/u', '', $string);
		if ($letters === '' || (mb_strtolower($letters, 'UTF-8') !== $letters && mb_strtoupper($letters, 'UTF-8') !== $letters)) {
			return $string;
		}
		$string = mb_convert_case(mb_strtolower($string, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
		// A part after a hyphen or an apostrophe is a name of its own (Smith-Jones, O'Brien).
		return preg_replace_callback("~(?<=[-'])\\p{Ll}~u", function ($m) {
			return mb_strtoupper($m[0], 'UTF-8');
		}, $string);
	}

	/**
	 * Subscribe this user to $mailing_list_ids and unsubscribe them from the
	 * other lists. $scope limits both to the lists a page offered (null: every
	 * list, for staff); a list outside it is left as it is.
	 */
	public function add_user_to_mailing_lists($mailing_list_ids, ?array $scope = null){
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
			if ($scope !== null && !in_array((int)$mailing_list->key, array_map('intval', $scope), true)) {
				continue;
			}
					
			if($mailing_list_ids == 'all'){
				// "All lists" means every list that can be joined: a deleted list
				// is gone, and add_registrant() refuses an inactive one.
				if(!$mailing_list->get('mlt_is_active') || $mailing_list->get('mlt_delete_time')){
					continue;
				}
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
			
			// A last name is optional: one name is a whole name for many people,
			// and an address alone (bob@example.com) gives only one.
			$last_name = (string)($data['usr_last_name'] ?? '');
				
			$email = strtolower(trim((string)($data['usr_email'] ?? '')));
			$username = trim((string)($data['usr_username'] ?? ''));
			if ($email === '' && $username === '') {
				throw new SystemDisplayablePermanentError("Missing email or username in create user.");
			}
					
			if(!$password = ($data['password'] ?? null)){
				$password = NULL;
			}
			
			$send_emails = array_key_exists('send_emails', $data) ? $data['send_emails'] : true;

			//PREVENT DUPLICATES. A closed account's address is refused, never reused:
			// reopening an account is a person's decision, not a side effect.
			if($email !== '' && $user = User::GetByEmail($email)){
				if ($user->get('usr_delete_time')) {
					throw new DisplayableUserException(self::CLOSED_ACCOUNT_MESSAGE);
				}
				return $user;
			}
	
			// The welcome email is told only whether the member has a password of
			// their own. The password itself is never one of its values, so no
			// template, shipped or edited, can mail it.
			if($password){
					$email_fill = array('password_temporary' => false);
					$temp_password_hashed = User::GeneratePassword($password);
			}
			else{
					// Must be >= 8 chars to satisfy GeneratePassword's minimum-length check.
					// Not surfaced to the user — they reset via the forgot-password flow.
					$temp_password = bin2hex(random_bytes(8));
					$temp_password_hashed = User::GeneratePassword($temp_password);
					$email_fill = array('password_temporary' => true);
			}
	
			$user = new User(NULL);
			$user->set('usr_email', $email !== '' ? strip_tags($email) : NULL);
			$user->set('usr_username', $username !== '' ? $username : NULL);
			// Activation proves control of an address; with none, there is nothing to prove.
			if ($email === '') {
				$user->set('usr_is_activated', true);
				$send_emails = false;
			}
			$user->set('usr_first_name', strip_tags(trim($first_name)));
			$last_name = strip_tags(trim($last_name));
			$user->set('usr_last_name', $last_name !== '' ? $last_name : null);
			$user->set('usr_password', $temp_password_hashed);	
			$user->set('usr_signup_ip', $_SERVER['REMOTE_ADDR']);
			if(!empty($data['usr_nickname'])){
				$user->set('usr_nickname', strip_tags(trim($data['usr_nickname'])));
			}		

			if(!empty($data['usr_timezone'])){
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
				require_once(PathHelper::getIncludePath('includes/EmailTemplateRenderer.php'));
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

			$user = User::GetByEmail(trim((string)($data['usr_email'] ?? '')));
			if ($user && $user->get('usr_delete_time')) {
				throw new DisplayableUserException(self::CLOSED_ACCOUNT_MESSAGE);
			}
			if(!$user){
				$tdata = array(
					'usr_first_name' => $data['usr_first_name'],
					'usr_last_name' => $data['usr_last_name'] ?? '',
					'usr_email' => $data['usr_email'] ?? '',
					'usr_username' => $data['usr_username'] ?? '',
					'send_emails' => $send_emails
				);

				if(!empty($data['password'])){
					$tdata['password'] = $data['password'];
				}

				if(!empty($data['usr_nickname'])){
					$tdata['usr_nickname'] = $data['usr_nickname'];
				}

				if(!empty($data['usr_timezone'])){
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
		if(!$user->phone() && !empty($data['phn_phone_number'])){
			$phone_number = PhoneNumber::CreateFromForm($data, $user->key, NULL, FALSE);
		}
		
		//IF THE USER ENTERED AN ADDRESS, SAVE THAT
		if(!$user->address() && !empty($data['address'])){
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
		// Uniqueness and the email-or-username rule are save()'s (check_identifiers()),
		// which every write passes through; this is the friendly address check.
		if ($this->get('usr_email') !== NULL && !LibraryFunctions::IsValidEmail($this->get('usr_email'))) {
			throw new DisplayableUserException(
				'Sorry, that email address "'.$this->get('usr_email').'" you entered is invalid.  Please try again.');
		}

		//CAPITALIZATION
		$this->set('usr_first_name', User::UcName($this->get('usr_first_name')));
		$this->set('usr_last_name', User::UcName($this->get('usr_last_name')));
	}

	/**
	 * The account holding $email, the live one first: a deleted account and a
	 * live one can share an address, and the live one is the one that answers
	 * to it. A deleted account is returned only when no live one has the
	 * address, so a closed account's address still reads as taken.
	 */
	public static function GetByEmail($email) {
		$email = strtolower(trim((string)$email));
		if ($email === '') {
			return NULL;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT * FROM usr_users WHERE LOWER(usr_email) = ?
			  ORDER BY usr_delete_time IS NOT NULL, usr_user_id LIMIT 1');
		$q->execute(array($email));
		$data = $q->fetch(PDO::FETCH_OBJ);

		if ($data === FALSE) {
			return NULL;
		}

		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$field_specifications));
		return $user;
	}

	/**
	 * The account holding $username, regardless of capitals, the live one first.
	 * A deleted account is returned only with $include_deleted, for the check
	 * that keeps a closed account's username taken.
	 */
	public static function GetByUsername($username, $include_deleted = false) {
		$username = strtolower(trim((string)$username));
		if ($username === '') {
			return NULL;
		}
		$q = DbConnector::get_instance()->get_db_link()->prepare(
			'SELECT * FROM usr_users WHERE LOWER(usr_username) = ?'
			. ($include_deleted ? '' : ' AND usr_delete_time IS NULL')
			. ' ORDER BY usr_delete_time IS NOT NULL, usr_user_id LIMIT 1');
		$q->execute(array($username));
		$data = $q->fetch(PDO::FETCH_OBJ);
		if ($data === FALSE) {
			return NULL;
		}
		$user = new User($data->usr_user_id);
		$user->load_from_data($data, array_keys(User::$field_specifications));
		return $user;
	}

	/**
	 * The live account a person signing in means by what they typed: text with
	 * an @ is an email, anything else a username (a username never holds an @).
	 * Never a deleted account. Every sign-in path that starts from typed text
	 * asks this; GetByEmail() is for "who has this address".
	 */
	public static function GetByLogin($identifier) {
		$identifier = trim((string)$identifier);
		if ($identifier === '') {
			return NULL;
		}
		$user = strpos($identifier, '@') !== false
			? self::GetByEmail($identifier)
			: self::GetByUsername($identifier);
		return ($user && !$user->get('usr_delete_time')) ? $user : NULL;
	}

	/** 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit. */
	public static function IsValidUsername($username) {
		return is_string($username) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,31}$/', $username) === 1;
	}

	/** Who this account is, for a list or a picker: the email, else @username. */
	function login_label() {
		$email = (string)$this->get('usr_email');
		return $email !== '' ? $email : '@' . $this->get('usr_username');
	}

	/** A blank sign-in identifier is stored as NULL, never '', so blanks never collide. */
	function set($key, $value, $check_existance=TRUE) {
		if (($key === 'usr_email' || $key === 'usr_username') && is_string($value) && trim($value) === '') {
			$value = NULL;
		}
		parent::set($key, $value, $check_existance);
	}

	/**
	 * The rules every save keeps for the sign-in identifiers: an account has an
	 * email, a username, or both; a username has the allowed form; and neither
	 * may be one another account holds, a deleted account included, so a closed
	 * account's identifiers are never handed to someone else. Only an identifier
	 * this save sets (or a new row's) is checked, so an old row is never refused
	 * for a value it already had.
	 */
	private function check_identifiers() {
		$email = $this->get('usr_email');
		$username = $this->get('usr_username');
		if ($email === NULL && $username === NULL) {
			throw new DisplayableUserException('An account needs an email address or a username.');
		}
		if ($username !== NULL && $this->identifier_set_here('usr_username')) {
			if (!self::IsValidUsername($username)) {
				throw new DisplayableUserException('A username is 3 to 32 letters, digits, dots, dashes or underscores, starting with a letter or digit.');
			}
			$holder = self::GetByUsername($username, true);
			if ($holder && (int)$holder->key !== (int)$this->key) {
				throw new DisplayableUserException('The username "' . $username . '" is taken. Please choose another.');
			}
		}
		if ($email !== NULL && $this->identifier_set_here('usr_email')) {
			$holder = self::GetByEmail($email);
			if ($holder && (int)$holder->key !== (int)$this->key) {
				throw new DisplayableUserException($holder->get('usr_delete_time')
					? self::CLOSED_ACCOUNT_MESSAGE
					: 'Sorry, that email address "' . $email . '" has already been used.  Please try again.');
			}
		}
	}

	/**
	 * A deleted account comes back only while its identifiers are still its own:
	 * an old row can share an address with a live account (accounts made before
	 * the save rules), and the live-only unique index would refuse it with a
	 * database error instead of saying so.
	 */
	function undelete() {
		$email = self::GetByEmail((string)$this->get('usr_email'));
		$username = self::GetByUsername((string)$this->get('usr_username'));
		foreach (array('email address' => $email, 'username' => $username) as $what => $holder) {
			if ($holder && (int)$holder->key !== (int)$this->key && !$holder->get('usr_delete_time')) {
				throw new DisplayableUserException('This account cannot be restored: its ' . $what
					. ' now belongs to another account (user ' . (int)$holder->key . '). Change that account first.');
			}
		}
		return parent::undelete();
	}

	private function identifier_set_here($column) {
		if ($this->key === NULL) {
			return true;
		}
		return in_array($column, $this->changed_columns(), true)
			&& strtolower((string)$this->access_stored($column)) !== strtolower((string)$this->get($column));
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

	/**
	 * Spend the time a password check takes, for a sign-in naming no account:
	 * without it an unknown email is answered faster than a known one with a
	 * wrong password, which says which emails have accounts. The hash is of a
	 * random string nobody knows, made with the production Argon2id
	 * parameters, so the work matches a real check and never succeeds.
	 */
	public static function spend_password_check($password): void {
		password_verify(trim((string)$password),
			'$argon2id$v=19$m=65536,t=4,p=1$TU1xTDgvU3U4cktERjVvVQ$GpdtAY06bygnogDhbfbjplUHJ3XD0E/OAck099I7eVk');
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
					// The server's own decision, made before anyone is signed in:
					// the password just proved is the authority, not a session.
					SystemBase::server_initiated_write(function () { $this->save(); });
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
	 * True when the account has an active Sealed Vault (any Private
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
		$this->set('usr_totp_secret', (new SecretBox())->seal(self::TOTP_SECRET_LOCATOR, (string)$secret));
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
		$this->set('usr_second_factor_hmac_key', self::new_sealed_hmac_key());
		$this->save();
	}

	/** A fresh contact-preferences link token: 128 random bits as 32 hex characters. */
	public static function new_authhash(): string {
		return bin2hex(random_bytes(16));
	}

	/** Where the two second-factor secrets live, as declared in settings.json sealed_secrets. */
	const TOTP_SECRET_LOCATOR = 'usr_users.usr_totp_secret';
	const HMAC_KEY_LOCATOR = 'usr_users.usr_second_factor_hmac_key';

	/** A fresh trusted-device signing key, sealed for storage. */
	private static function new_sealed_hmac_key(): string {
		return (new SecretBox())->seal(self::HMAC_KEY_LOCATOR, bin2hex(random_bytes(64)));
	}

	/**
	 * A stored second-factor secret, opened. Null when none is stored or it can no
	 * longer be opened (the database moved to a site with another secret_box_key);
	 * the caller treats that as not set. A value from before these were sealed reads
	 * as itself until the reconciler's next cold pass seals it.
	 */
	private function open_secret_column(string $column): ?string {
		$opened = (new SecretBox())->open($this->get($column));
		return in_array($opened['state'], array(SecretBox::OPEN_OK, SecretBox::OPEN_PLAINTEXT), true)
			&& (string)$opened['value'] !== '' ? (string)$opened['value'] : null;
	}

	/** The TOTP shared secret (Base32), or null. */
	function totp_secret(): ?string {
		return $this->open_secret_column('usr_totp_secret');
	}

	/** The key that signs this user's trusted-device cookies, or null until the first trust grant. */
	function second_factor_hmac_key(): ?string {
		return $this->open_secret_column('usr_second_factor_hmac_key');
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
		$this->set('usr_second_factor_hmac_key', self::new_sealed_hmac_key());
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
		$secret = $this->totp_secret();
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
				// Before anyone is signed in: the code just proved is the authority.
				SystemBase::server_initiated_write(function () { $this->save(); });
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
				// Before anyone is signed in: the backup code just proved is the authority.
				SystemBase::server_initiated_write(function () { $this->save(); });
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the given IP address is allowed to login for this user.
	 * Each entry is an address in any spelling, a prefix of either family
	 * (104.23.253.0/24, 2001:db8:abcd::/48) or an IPv4 wildcard (104.23.*);
	 * IpAddress::matchesEntry() reads them.
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

		foreach ($allowed_ips as $allowed) {
			if (IpAddress::matchesEntry((string)$ip, (string)$allowed)) {
				return true;
			}
		}
		return false;
	}

	function email_verify_user($use_transaction=TRUE, $and_save=TRUE) {
		$verify = function () use ($and_save) {
			$this->set('usr_is_activated', TRUE);
			$this->set('usr_email_is_verified', TRUE);
			$this->set('usr_email_is_verified_time', 'now');

			if ($and_save) {
				$this->save();
			}
		};
		$use_transaction ? DbConnector::transaction($verify) : $verify();
	}

	function email_unverify_bouncing_user($use_transaction=TRUE) {
		$unverify = function () {
			$this->set('usr_is_activated', FALSE);
			$this->set('usr_email_is_verified', FALSE);
			$this->set('usr_email_bounce_unverify_time', 'now');

			$this->save();
		};
		$use_transaction ? DbConnector::transaction($unverify) : $unverify();
	}

	function display_name() {

		if($this->get('usr_first_name') || $this->get('usr_last_name')){		
			$returnval = trim($this->get('usr_first_name') . ' ' . $this->get('usr_last_name'));
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

	/** A user owns their own row: the owner rule reads the key, not a column. */
	public function access_owner_is($user_id): bool {
		return $this->key !== NULL && (int)$user_id > 0 && (int)$this->key === (int)$user_id;
	}

	function authenticate_write($data) {
		$actor = (int)$data['current_user_permission'];
		if (!$this->access_owner_is($data['current_user_id'] ?? 0)) {
			if (!Access::allows(static::access_level('write'), $actor)) {
				throw new SystemAuthenticationError(
					'Changing a user needs level ' . static::access_level('write') . '.');
			}
			// The target rule: staff change only a user strictly below their own
			// level, so a 9 cannot reset a 10's password and sign in as them. 10 is
			// exempt, so two owners can manage each other.
			if (!Access::allows(10, $actor) && $this->key && Access::allows($actor, $this->stored_permission())) {
				throw new SystemAuthenticationError(
					'Only a user above level ' . $this->stored_permission() . ' may change this user.');
			}
		}
		// And grant at most their own level, to anyone, themselves included.
		$granted = (int)$this->get('usr_permission');
		if (!Access::allows(10, $actor) && $granted !== $this->stored_permission()
			&& !Access::allows($granted, $actor)) {
			throw new SystemAuthenticationError(
				'A user at level ' . $actor . ' cannot grant level ' . $granted . '.');
		}
	}

	/** The level this user holds in the database (0 for one not saved yet). */
	private function stored_permission(): int {
		if (!$this->key) {
			return 0;
		}
		if ($this->loaded_permission === NULL) {
			$db = DbConnector::get_instance()->get_db_link();
			$q = $db->prepare('SELECT usr_permission FROM usr_users WHERE usr_user_id = ?');
			$q->execute(array($this->key));
			$this->loaded_permission = (int)$q->fetchColumn();
		}
		return $this->loaded_permission;
	}

	/** usr_permission as loaded; NULL until a load (stored_permission() reads it then). */
	private $loaded_permission = NULL;

	function load() {
		$result = parent::load();
		$this->loaded_password_hash = ($this->data !== NULL && isset($this->data->usr_password))
			? $this->data->usr_password : NULL;
		$this->remember_profile();
		return $result;
	}

	function load_from_data($data, $fields) {
		parent::load_from_data($data, $fields);
		$this->loaded_password_hash = $this->get('usr_password');
		$this->remember_profile();
	}

	/** The profile fields whose change is the account.updated signal. */
	const PROFILE_FIELDS = array('first_name', 'last_name', 'nickname', 'organization_name', 'email', 'timezone');

	/** The profile as loaded, to tell what a save changed; null for a user not loaded. */
	private $loaded_profile = NULL;

	private function remember_profile(): void {
		$this->loaded_profile = array();
		foreach (self::PROFILE_FIELDS as $field) {
			$this->loaded_profile[$field] = (string)$this->get('usr_' . $field);
		}
		$this->loaded_permission = $this->data !== NULL ? (int)$this->get('usr_permission') : NULL;
		$this->loaded_could_approve = Access::allows(SandboxConsent::APPROVER_PERMISSION, (int)$this->get('usr_permission'))
			&& !$this->get('usr_is_disabled');
	}

	/** Whether, as loaded, this user's approval of a sandboxed plugin stood. */
	private $loaded_could_approve = FALSE;

	function save($debug=false) {
		// A changed hash on an existing, previously loaded user is a credential
		// change — the single choke point for every password-changing flow.
		$password_changed = ($this->key !== NULL
			&& $this->loaded_password_hash !== NULL
			&& $this->get('usr_password') !== NULL
			&& $this->get('usr_password') !== $this->loaded_password_hash);

		// Only what this save set counts as a change: the copy is read back
		// after the save, so a field another save changed since this copy was
		// loaded is not this save's change.
		$set_by_caller = $this->changed_columns();

		$this->check_identifiers();
		parent::save($debug);
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// A superadmin demoted or disabled by this save: the sandboxed plugins
		// they approved stop acting as them (SandboxCredentials).
		if ($this->loaded_profile !== NULL && $this->loaded_could_approve
				&& (!Access::allows(SandboxConsent::APPROVER_PERMISSION, (int)$this->get('usr_permission')) || $this->get('usr_is_disabled'))) {
			$this->loaded_could_approve = FALSE;
			SandboxCredentials::lapseApprovalsOf((int)$this->key);
		}

		// A loaded user whose profile this save changed: the account.updated
		// signal, naming what changed.
		if ($this->loaded_profile !== NULL) {
			$changed = array();
			foreach (self::PROFILE_FIELDS as $field) {
				if (in_array('usr_' . $field, $set_by_caller, true)
						&& (string)$this->get('usr_' . $field) !== $this->loaded_profile[$field]) {
					$changed[] = $field;
				}
			}
			if ($changed) {
				$this->remember_profile();
				SignalBus::dispatch('account.updated', array(
					'user_id'        => (int)$this->key,
					'display_name'   => $this->display_name(),
					'email'          => (string)$this->get('usr_email'),
					'changed'        => implode(',', $changed),
					'source_user_id' => isset($_SESSION['usr_user_id']) ? (int)$_SESSION['usr_user_id'] : null,
				));
			}
		}

		// Revoke all active API session keys when the password changes —
		// the lost-phone / compromised-credential path. Machine keys survive
		// (an admin changing their password must not break integrations).
		if ($password_changed && !$this->suppress_session_key_revocation) {
			require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
			ApiKey::RevokeSessionKeysForUser($this->key);
			// And every other way back in: the other browser sessions, and the
			// remembered devices but this one.
			SessionControl::get_instance()->end_other_sessions($this);
		}
		$this->loaded_password_hash = $this->get('usr_password');
		$this->loaded_permission = (int)$this->get('usr_permission');

		// The token in this user's mailing-list footer links (contact preferences):
		// random, so no one can work out another user's link.
		if($this->get('usr_authhash') === NULL){
			$authhash = self::new_authhash();
			$sql = "UPDATE usr_users SET usr_authhash = :usr_authhash WHERE usr_user_id = :usr_user_id";

			try{
				$q = $dblink->prepare($sql);
				$q->bindParam(':usr_authhash', $authhash, PDO::PARAM_STR);
				$q->bindParam(':usr_user_id', $this->key, PDO::PARAM_INT);
				$q->execute();
				$this->set_as_stored('usr_authhash', $authhash);
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

	/**
	 * A soft-deleted user's keys stop authenticating, so the same refusal as
	 * permanent_delete() applies: see refuse_while_scoped_key_is_live(). The
	 * sandboxed plugins they approved lapse, as on a demotion.
	 */
	function soft_delete(){
		if($this->key === NULL || $this->key === ''){
			return parent::soft_delete();
		}
		$this->refuse_while_scoped_key_is_live();
		$result = parent::soft_delete();
		if($result){
			SandboxCredentials::lapseApprovalsOf((int)$this->key);
		}
		return $result;
	}

	/**
	 * Refuse to delete an account that owns a live scoped machine key. Such a
	 * key is another machine's credential for one job (a DNS server reading
	 * its snapshot, an integration reading one model), and it acts as this
	 * account. Deleting the account would silently cut that machine off, and
	 * permanent deletion would take the key with it; the key is deleted
	 * first, on its own page.
	 *
	 * A sandboxed plugin's key is not refused: deleting the admin who approved
	 * the plugin ends that approval, as demoting them does, and the plugin
	 * waits on another admin's (SandboxConsent::approver()).
	 */
	private function refuse_while_scoped_key_is_live(){
		require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
		$keys = new MultiApiKey(array(
			'user_id' => $this->key,
			'type' => ApiKey::TYPE_MACHINE,
			'deleted' => false,
		));
		$now_utc = gmdate('Y-m-d H:i:s');
		foreach($keys as $key){
			// A key that is switched off or past its expiry is in use by nothing.
			$expired = $key->get('apk_expires_time') && $key->get('apk_expires_time') < $now_utc;
			if($key->is_scoped() && $key->get('apk_is_active') && !$expired && $key->sandbox_plugin() === null){
				throw new SystemDisplayableError(
					'This account owns the key "' . $key->get('apk_name') . '", which is limited to '
					. $key->scope_label() . ' and is in use by another machine. Delete that key first, at '
					. '/admin/admin_api_key?apk_api_key_id=' . (int)$key->key
					. '; deleting the account would cut that machine off.');
			}
		}
	}

	function permanent_delete($debug=false){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		// No key, no row: the delete already happened, or the object was never
		// saved. Either way there is nothing left to remove, and the cascade
		// prep below cannot run without one — it would ask Group to drop member
		// '' and get back an exception about a missing id, which describes the
		// empty key rather than the double delete that produced it.
		if($this->key === NULL || $this->key === ''){
			return true;
		}

		if(User::is_placeholder($this->key)){
			throw new SystemAuthenticationError(
					'You cannot delete this user.');
		}

		$this->refuse_while_scoped_key_is_live();
		// Asked before the mailing-list unsubscribes and the cascade below, which
		// the parent's delete would ask only after they ran.
		$this->guard_raw_write('permanent_delete');
		
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

			//DELETE THE DRIVE. A member's Drive is owned content — private storage
			//that is meaningless without its owner — so it goes with them. Left to
			//the generic FK rules, folders and Drive files were re-homed under the
			//USER_DELETED tombstone, where they piled up (17 folders and 644 files
			//on dev by 2026-09-02) and collided on the per-owner sibling-name
			//index, so the delete itself failed. The tree walk releases blob
			//references the way "delete forever" does; files from other sources
			//(uploads referenced by surviving content) keep the set_value rule.
			if(!$debug){
				require_once(PathHelper::getIncludePath('includes/DriveHelper.php'));
				$q = $dblink->prepare("SELECT fol_folder_id FROM fol_folders WHERE fol_usr_user_id = ? AND fol_parent_folder_id IS NULL");
				$q->execute(array($this->key));
				foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fol_id) {
					$root_folder = DriveHelper::load_folder((int)$fol_id);
					if ($root_folder) {
						DriveHelper::permanent_delete_tree(DriveHelper::ENTITY_FOLDER, $root_folder);
					}
				}
				$q = $dblink->prepare("SELECT fil_file_id FROM fil_files WHERE fil_usr_user_id = ? AND fil_source = ? AND fil_fol_folder_id IS NULL");
				$q->execute(array($this->key, File::SOURCE_DRIVE));
				foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fil_id) {
					$root_file = DriveHelper::load_file((int)$fil_id);
					if ($root_file) {
						DriveHelper::permanent_delete_tree(DriveHelper::ENTITY_FILE, $root_file);
					}
				}
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
		// A photo whose record or bytes are gone is the blank avatar, not a broken image.
		if (!$file->key || $file->get('fil_delete_time') || !$file->bytes_present()) {
			return '/assets/images/blank-avatar.png';
		}
		return $file->get_url($size_key);
	}

}

class MultiUser extends SystemMultiBase {
	protected static $model_class = 'User';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $item) {
			$items[$item->key] = $item->display_name().' - '.$item->login_label();
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
            $filters['usr_first_name'] = 'ILIKE '.DbConnector::get_instance()->get_db_link()->quote('%'.$this->options['first_name_like'].'%');
        }

        if (isset($this->options['last_name_like'])) {
            $filters['usr_last_name'] = 'ILIKE '.DbConnector::get_instance()->get_db_link()->quote('%'.$this->options['last_name_like'].'%');
        }
        
        if (isset($this->options['nickname_like'])) {
            $filters['usr_nickname'] = 'ILIKE '.DbConnector::get_instance()->get_db_link()->quote('%'.$this->options['nickname_like'].'%');
        }

        if (isset($this->options['username_like'])) {
            $filters['usr_username'] = 'ILIKE '.DbConnector::get_instance()->get_db_link()->quote('%'.$this->options['username_like'].'%');
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
            $filters['usr_email'] = 'ILIKE '.DbConnector::get_instance()->get_db_link()->quote('%'.$this->options['email_like'].'%');
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
            // COALESCE: a person with no last name is still found by their first.
            $filters['to_tsvector(\'english\', COALESCE(usr_first_name, \'\') || \' \' || COALESCE(usr_last_name, \'\'))'] = '@@ to_tsquery(\'english\', \''.$fsearch.'\')';
        }

        return $this->_get_resultsv2('usr_users', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
