<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');

PathHelper::requireOnce('data/mailing_list_registrants_class.php');
PathHelper::requireOnce('data/users_class.php');
PathHelper::requireOnce('data/files_class.php');

$settings = Globalvars::get_instance();

$composer_dir = $settings->get_setting('composerAutoLoad');	
require $composer_dir.'autoload.php';
use MailchimpAPI\Mailchimp;

class MailingListException extends SystemClassException {}
class DisplayableMailingListException extends MailingListException implements DisplayableErrorMessage {}
class DisplayablePermanentMailingListException extends MailingListException implements DisplayablePermanentErrorMessage {}


class MailingList extends SystemBase {
	public static $prefix = 'mlt';
	public static $tablename = 'mlt_mailing_lists';
	public static $pkey_column = 'mlt_mailing_list_id';
	public static $url_namespace = 'list';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM
	public static $permanent_delete_actions = array(		'mlr_mlt_mailing_list_id' => 'prevent',
		
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prmailing_list', or a value to set to that value
	
	const VISIBILITY_PRIVATE = 0;  //NOT LISTED ANYWHERE FOR SUBSCRIPTION, MUST BE SIGNED UP BY AN ADMIN
	const VISIBILITY_PUBLIC = 1;  //LISTED ON /LISTS
	const VISIBILITY_PUBLIC_UNLISTED = 2;  //NOT LISTED ON /LISTS BUT AVAILABLE TO REGISTER WITH THE LINK

	public static $fields = array(		'mlt_name' => 'Name',
		'mlt_description' => 'Description',
		'mlt_mailchimp_list_id' => 'Mailchimp list id for sync',
		'mlt_visibility'=>'0=private, 1=public,2=public but unlisted',
		'mlt_is_active' => 'Active or disabled',
		'mlt_link' => 'Link for the list',
		'mlt_create_time' => 'Time of creation',
		'mlt_delete_time' => 'Time of deletion',
		'mlt_emt_email_template_id' => 'Email template if the user gets a welcome email',
		'mlt_fil_file_id' => 'File to be sent upon subscription',
		'mlt_ctt_contact_type_id' => 'Contact type, if we are allowing unsubscribe by type',
	); 

	/**
	 * Field specifications define database column properties and schema constraints
	 * Available options:
	 *   'type' => 'varchar(255)' | 'int4' | 'int8' | 'text' | 'timestamp(6)' | 'numeric(10,2)' | 'bool' | etc.
	 *   'serial' => true/false - Auto-incrementing field
	 *   'is_nullable' => true/false - Whether NULL values are allowed
	 *   'unique' => true - Field must be unique (single field constraint)
	 *   'unique_with' => array('field1', 'field2') - Composite unique constraint with other fields
	 */
	public static $field_specifications = array(
		'mlt_mailing_list_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'mlt_name' => array('type'=>'varchar(255)'),
		'mlt_description' =>   array('type'=>'varchar(255)'),
		'mlt_mailchimp_list_id' => array('type'=>'varchar(255)'),
		'mlt_visibility'=> array('type'=>'int2'),
		'mlt_is_active' => array('type'=>'bool'),
		'mlt_link' => array('type'=>'varchar(255)'),
		'mlt_create_time' => array('type'=>'timestamp(6)'),
		'mlt_delete_time' => array('type'=>'timestamp(6)'),
		'mlt_emt_email_template_id' => array('type'=>'int4'),
		'mlt_fil_file_id' => array('type'=>'int4'),
		'mlt_ctt_contact_type_id' => array('type'=>'int4'),
	); 
			
	public static $required_fields = array(
		'mlt_name', 'mlt_link'
	);

	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'mlt_create_time' => 'now()', 'mlt_visibility' => 0
	);	

	public static $field_constraints = array(
		'mlt_name' => array(
			array('WordLength', 0, 255),
			'NoCaps',
			),
		);
	
	
	function get_subscribed_users($return='object'){
		$searches = array();
		$searches['deleted'] = false;
		$searches['mailing_list_id'] = $this->key;
		$registrants = new MultiMailingListRegistrant($searches);
		$registrants->load();	
		
		if($return == 'object'){
			$users = new MultiUser(NULL);
			foreach($registrants as $registrant) {
				$user = new User($registrant->get('mlr_usr_user_id'), TRUE);
				$users->add($user);
			}
			return $users;
		}
		else{
			//RETURN AN ARRAY
			$users = array();
			foreach($registrants as $registrant) {
				$users[] = $registrant->get('mlr_usr_user_id');
			}
			return $users;
		}
	}
	
	function count_subscribed_users() {
		$count = new MultiMailingListRegistrant(array(
			'mailing_list_id' => $this->key,
			'deleted' => false,
		));
		
		$numrecords = $count->count_all();
		return $numrecords;
	}
	
	function is_user_in_list($user_id, $search_deleted = false){
		$searches = array();
		$searches['user_id'] = $user_id;
		$searches['mailing_list_id'] = $this->key;
		if(!$search_deleted){
			$searches['deleted'] = false;
		}
		$subscriptions = new MultiMailingListRegistrant($searches);
		$count = $subscriptions->count_all();
		if ($count == 0) {
			return false;
		}
		else{
			$subscriptions->load();
			return $subscriptions->get(0);
		}
		
	}

	
	function add_registrant($usr_user_id){
		if(!$this->get('mlt_is_active')){
			throw new MailingListException('You cannot subscribe to an inactive list.');
		}
		
		if($registrant = $this->is_user_in_list($usr_user_id, true)){
			//IF DELETED, UNDELETE
			if($registrant->get('mlr_delete_time')){
				$registrant->set('mlr_change_time', 'now()');
				$registrant->set('mlr_delete_time', NULL);
				$registrant->prepare();
				$registrant->save();
				$registrant->load();

				$status = true;
				if($this->get('mlt_mailchimp_list_id')){
					$status = $this->subscribe_to_mailchimp_list($usr_user_id);
				}				
			}
			else{
				//IF USER IS ALREADY REGISTERED
				throw new MailingListException('This user is already subscribed to this list.');
			}
		}
		else{
			//IF USER IS NOT REGISTERED
			$registrant = new MailingListRegistrant(NULL);
			$registrant->set('mlr_usr_user_id', $usr_user_id);
			$registrant->set('mlr_mlt_mailing_list_id', $this->key);
			$registrant->set('mlr_change_time', 'now()');
			$registrant->set('mlr_delete_time', NULL);
			$registrant->prepare();
			$registrant->save();
			$registrant->load();
			
			if($this->get('mlt_send_welcome_email')){
				//SEND WELCOME EMAIL
				$user = new User($usr_user_id, TRUE);
				$welcome_email = new EmailTemplate('mailing_list_subscribe', $user);
				
				
				$email_fill = array(
					'subject' => 'Welcome to our mailing list',
					//'utm_source' => 'email', //use defaults
					'utm_medium' => 'email', //use defaults
					'utm_campaign' => $mailing_list_string, 
					'utm_content' => urlencode($email->get('eml_subject')), 
					'mailing_list_id' => $mailing_list_id,
					'mailing_list_string' => $mailing_list_string,
				);
				//CHECK TO SEE IF THE USER GETS A FREE GIFT
				if($this->get('mlt_fil_file_id')){
					$file = new File($this->get('mlt_fil_file_id'), TRUE);
					$email_fill['file_link'] = LibraryFunctions::get_absolute_url('/uploads/'.$file->get('fil_name'));
					$email_fill['file_name'] = $file->get('fil_name');
				}

				$welcome_email->fill_template($email_fill);
				$welcome_email->send();	
			}
			
			$status = true;
			if($this->get('mlt_mailchimp_list_id')){
				$status = $this->subscribe_to_mailchimp_list($usr_user_id);
			}
		}
		return $status;			
	}
	
	function remove_registrant($usr_user_id){
	
		if(!$registrant = $this->is_user_in_list($usr_user_id)){
			//IF USER IS ALREADY REGISTERED
			throw new MailingListException('This user is already unsubscribed from this list.');
		}
		else{

			$search_criteria = array('user_id' => $usr_user_id, 'mailing_list_id'=>$this->key );
			$registrants = new MultiMailingListRegistrant(
				$search_criteria);	
			$registrants->load();
			$registrant = $registrants->get(0);

			$registrant->set('mlr_change_time', 'now()');
			$registrant->save();
			$registrant->soft_delete();
			$status = true;
			if($this->get('mlt_mailchimp_list_id')){
				$status = $this->unsubscribe_from_mailchimp_list($usr_user_id);
			}
			
			return $status;	
		}
	}	
	
	function subscribe_to_mailchimp_list($user_id) {
		$user = new User($user_id, TRUE);
		
		if(!$this->get('mlt_mailchimp_list_id')){
			throw new SystemDisplayableError('There is no mailchimp list id for this list:'. $this->get('mlt_name'));
			exit;
		}
		
		
		//NOW ADD THE USER TO MAILCHIMP
		try {
		$settings = Globalvars::get_instance();
			if($settings->get_setting('mailchimp_api_key')){
				$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));
				
				//IF WE HAVE A MAILCHIMP ID STORED, THEN LOOK THE USER UP, DON'T ADD HIM
				$user_to_update = NULL;
				if($user->get('usr_mailchimp_user_id')){
					$user_to_update = md5($user->get('usr_email'));
				}

				$merge_values = [
					"FNAME" => $user->get('usr_first_name'),
					"LNAME" => $user->get('usr_last_name'),
					"MMERGE3" => 'Yes',
				];

				$post_params = [
					"email_address" => $user->get('usr_email'),
					"status" => "subscribed", 
					"email_type" => "html", 
					"merge_fields" => $merge_values,
				];
		
				$return = $mailchimp 
					->lists($this->get('mlt_mailchimp_list_id'))
					->members($user_to_update)
					->post($post_params);

						
				$status = $return->deserialize();
				
				$mailchimp_user_id = $status->id;
				$user->set('usr_mailchimp_user_id', $mailchimp_user_id);
				$user->save();
				
				return $status;
			}
		} 
		catch (Exception $e) {
			return FALSE;
		}
		return TRUE;

	}	

	/*
	function resubscribe_to_mailing_list() {

		//TODO NEED TO HANDLE ALL CONTACT PREFERENCE POSSIBILITIES
		$this->set('usr_contact_preferences', 1);
		if($this->get('usr_contact_preference_last_changed') != 1){
			$this->set('usr_contact_preference_last_changed', 'NOW()');
		}
		$this->subscribe_to_contact_type(User::NEWSLETTER);

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
	*/


	function unsubscribe_from_mailchimp_list($user_id) {
		
		$user = new User($user_id, TRUE);
		
		if(!$this->get('mlt_mailchimp_list_id')){
			throw new SystemDisplayableError('There is no mailchimp list id for this list:'. $this->get('mlt_name'));
			exit;
		}

		
		$settings = Globalvars::get_instance();
		if($settings->get_setting('mailchimp_api_key')){
			$mailchimp = new Mailchimp($settings->get_setting('mailchimp_api_key'));


			$post_params = [
				"status" => "unsubscribed", 
			];

			try {
				$return = $mailchimp 
					->lists($this->get('mlt_mailchimp_list_id'))
					->members(md5($user->get('usr_email')))
					->patch($post_params);
			} catch (Exception $e) {
				throw new SystemDisplayablePermanentError(
				'There was an error and we were unable to update your list unsubscribe.');
				exit();	
			}			

			return TRUE;
		}
	}	
	
	function prepare() {
		if ($this->data === NULL) {
			throw new MailingListException('This request has no data.');
		}
		
	}


	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiMailingList extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('mlt_name');
			if($entry->get('mlt_description')){
				$option_display .= ' - ' . $entry->get('mlt_description'); 
			}
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];

		if (isset($this->options['mailing_list_id'])) {
			$filters['mlt_mailing_list_id'] = [$this->options['mailing_list_id'], PDO::PARAM_INT];
		}

		if (isset($this->options['deleted'])) {
			$filters['mlt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		if (isset($this->options['visibility'])) {
			$filters['mlt_visibility'] = [$this->options['visibility'], PDO::PARAM_INT];
		}

		if (isset($this->options['link'])) {
			$filters['mlt_link'] = [$this->options['link'], PDO::PARAM_STR];
		}

		return $this->_get_resultsv2('mlt_mailing_lists', $filters, $this->order_by, $only_count, $debug);
	}

	// CHANGED: Updated load method
	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new MailingList($row->mlt_mailing_list_id);
			$child->load_from_data($row, array_keys(MailingList::$fields));
			$this->add($child);
		}
	}

	// NEW: Added count_all method
	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}

?>
