<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('data/mailing_list_registrants_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/email_templates_class.php'));
require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

$autoload_path = PathHelper::getComposerAutoloadPath();
if (file_exists($autoload_path)) {
    require_once($autoload_path);
}
use MailchimpAPI\Mailchimp;

class MailingListException extends SystemBaseException {}
class DisplayableMailingListException extends MailingListException implements DisplayableErrorMessage {}
class DisplayablePermanentMailingListException extends MailingListException implements DisplayablePermanentErrorMessage {}

class MailingList extends SystemBase {	public static $prefix = 'mlt';
	public static $tablename = 'mlt_mailing_lists';
	public static $pkey_column = 'mlt_mailing_list_id';
	public static $url_namespace = 'list';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

	protected static $foreign_key_actions = [
		'mlt_emt_email_template_id' => ['action' => 'prevent', 'message' => 'Cannot delete email template - mailing lists exist'],
		'mlt_fil_file_id' => ['action' => 'null'],
	];
	
	const VISIBILITY_PRIVATE = 0;  //NOT LISTED ANYWHERE FOR SUBSCRIPTION, MUST BE SIGNED UP BY AN ADMIN
	const VISIBILITY_PUBLIC = 1;  //LISTED ON /LISTS
	const VISIBILITY_PUBLIC_UNLISTED = 2;  //NOT LISTED ON /LISTS BUT AVAILABLE TO REGISTER WITH THE LINK

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
	    'mlt_mailing_list_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'mlt_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'mlt_description' => array('type'=>'varchar(255)'),
	    'mlt_mailchimp_list_id' => array('type'=>'varchar(255)'),
	    'mlt_visibility' => array('type'=>'int2', 'default'=>0),
	    'mlt_is_active' => array('type'=>'bool'),
	    'mlt_link' => array('type'=>'varchar(255)', 'required'=>true),
	    'mlt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'mlt_delete_time' => array('type'=>'timestamp(6)'),
	    'mlt_emt_email_template_id' => array('type'=>'int4'),
	    'mlt_fil_file_id' => array('type'=>'int4'),
	    'mlt_ctt_contact_type_id' => array('type'=>'int4'),
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

			if($this->get('mlt_emt_email_template_id')){
				//SEND WELCOME EMAIL
				$user = new User($usr_user_id, TRUE);
				$template = new EmailTemplateStore($this->get('mlt_emt_email_template_id'), TRUE);
				EmailSender::sendTemplate($template->get('emt_machine_name'),
					$user->get('usr_email'),
					[
						'subject' => $template->get('emt_subject'),
						'mailing_list' => $this,
						'recipient' => $user->export_as_array()
					]
				);
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

	// ===== Entity Photo Methods =====

	/**
	 * Set a photo as the primary photo for this mailing list
	 *
	 * @param int $photo_id EntityPhoto ID to set as primary
	 */
	function set_primary_photo($photo_id) {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

		$photo = new EntityPhoto($photo_id, TRUE);
		$this->set('mlt_fil_file_id', $photo->get('eph_fil_file_id'));
		$this->save();
	}

	/**
	 * Clear the primary photo for this mailing list
	 */
	function clear_primary_photo() {
		$this->set('mlt_fil_file_id', NULL);
		$this->save();
	}

	/**
	 * Get all photos for this mailing list
	 *
	 * @return MultiEntityPhoto
	 */
	function get_photos() {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'mailing_list', 'entity_id' => $this->key, 'deleted' => false],
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
		$file_id = $this->get('mlt_fil_file_id');
		if (!$file_id) return null;
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'mailing_list', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
			[], 1
		);
		$photos->load();
		return $photos->count() > 0 ? $photos->get(0) : null;
	}

	/**
	 * Get picture URL for display
	 *
	 * @param string $size_key Image size key (default 'content')
	 * @return string|false URL or false if no picture
	 */
	function get_picture_link($size_key = 'content') {
		$file_id = $this->get('mlt_fil_file_id');
		if (!$file_id) {
			return false;
		}
		$file = new File($file_id, TRUE);
		return $file->get_url($size_key);
	}

}

class MultiMailingList extends SystemMultiBase {
	protected static $model_class = 'MailingList';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = $entry->get('mlt_name');
			if($entry->get('mlt_description')){
				$option_display .= ' - ' . $entry->get('mlt_description');
			}
			$items[$entry->key] = $option_display;
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

	// NEW: Added count_all method

}

?>
