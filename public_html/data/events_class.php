<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
$settings = Globalvars::get_instance();
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('data/files_class.php'));
require_once(PathHelper::getIncludePath('data/content_versions_class.php'));
require_once(PathHelper::getIncludePath('data/groups_class.php'));
require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));

require_once(PathHelper::getIncludePath('includes/calendar-links/Link.php'));
require_once(PathHelper::getIncludePath('includes/calendar-links/Generator.php'));
require_once(PathHelper::getIncludePath('includes/calendar-links/Generators/Google.php'));
require_once(PathHelper::getIncludePath('includes/calendar-links/Generators/Ics.php'));
require_once(PathHelper::getIncludePath('includes/calendar-links/Generators/Yahoo.php'));
require_once(PathHelper::getIncludePath('includes/calendar-links/Generators/WebOutlook.php'));
use Spatie\CalendarLinks\Link;

class EventException extends SystemBaseException {}
class DisplayableEventException extends EventException implements DisplayableErrorMessage {}
class DisplayablePermanentEventException extends EventException implements DisplayablePermanentErrorMessage {}

/*
class EventUnviewableDisplayException extends EventException implements CustomErrorPage {
	function __construct($title, $error_message) {
		$this->title = $title;
		$this->error_message = $error_message;
		parent::__construct($error_message);
	}

	function display_error_page() {
		PublicPage::OutputGenericPublicPage(
			$this->title,
			$this->title,
			$this->error_message,
			array(
				'noindex' => TRUE
			));
	}
}
*/

class Event extends SystemBase {	public static $prefix = 'evt';
	public static $tablename = 'evt_events';
	public static $pkey_column = 'evt_event_id';
	public static $url_namespace = 'event';  //SUBDIRECTORY WHERE ITEMS ARE LOCATED EXAMPLE: DOMAIN.COM/URL_NAMESPACE/THIS_ITEM

	protected static $foreign_key_actions = [
		'evt_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'evt_usr_user_id_leader' => ['action' => 'set_value', 'value' => User::USER_DELETED],
		'evt_ety_event_type_id' => ['action' => 'prevent', 'message' => 'Cannot delete event type - events exist'],
		'evt_loc_location_id' => ['action' => 'null']
	];

	const STATUS_ACTIVE = 1;
	const STATUS_COMPLETED = 2;
	const STATUS_CANCELED = 3;
	
	const DISPLAY_CONDENSED = 1;
	const DISPLAY_SEPARATE = 2;
	
	const VISIBILITY_PRIVATE = 0;
	const VISIBILITY_PUBLIC = 1;
	const VISIBILITY_PUBLIC_UNLISTED = 2;	

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
	    'evt_event_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    'evt_name' => array('type'=>'varchar(255)', 'required'=>true),
	    'evt_description' => array('type'=>'text'),
	    'evt_short_description' => array('type'=>'text'),
	    'evt_usr_user_id_leader' => array('type'=>'int4'),
	    'evt_location' => array('type'=>'varchar(255)'),
	    'evt_start_time' => array('type'=>'timestamp(6)'),
	    'evt_start_time_local' => array('type'=>'timestamp(6)'),
	    'evt_end_time' => array('type'=>'timestamp(6)'),
	    'evt_end_time_local' => array('type'=>'timestamp(6)'),
	    'evt_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'evt_external_register_link' => array('type'=>'varchar(255)'),
	    'evt_timezone' => array('type'=>'varchar(32)', 'default'=>'America/New_York'),
	    'evt_is_accepting_signups' => array('type'=>'bool'),
	    'evt_picture_link' => array('type'=>'varchar(255)'),
	    'evt_collect_extra_info' => array('type'=>'bool'),
	    'evt_grp_group_id' => array('type'=>'int4'),
	    'evt_private_info' => array('type'=>'text'),
	    'evt_status' => array('type'=>'int4', 'default'=>1),
	    'evt_max_signups' => array('type'=>'int4'),
	    'evt_allow_waiting_list' => array('type'=>'bool'),
	    'evt_session_display_type' => array('type'=>'int4'),
	    'evt_visibility' => array('type'=>'int4', 'default'=>0),
	    'evt_fil_file_id' => array('type'=>'int4'),
	    'evt_link' => array('type'=>'varchar(255)'),
	    'evt_show_add_to_calendar_link' => array('type'=>'bool', 'default'=>true),
	    'evt_ety_event_type_id' => array('type'=>'int4'),
	    'evt_delete_time' => array('type'=>'timestamp(6)'),
	    'evt_svy_survey_id' => array('type'=>'int4'),
	    'evt_survey_required' => array('type'=>'int2'),
	    'evt_loc_location_id' => array('type'=>'int4'),
	); 

function get_leader() {
		if($this->get('evt_usr_user_id_leader')){
			$leader = new User($this->get('evt_usr_user_id_leader'), TRUE);
			return $leader->display_name();
		}
		else{
			return 'TBA';
		}
	}	
	
	function get_picture_link($type='standard'){
		if($this->get('evt_fil_file_id')){
			$file = new File($this->get('evt_fil_file_id'), TRUE);
			return $file->get_url($type, 'full');
		}
		else if($this->get('evt_picture_link')){
			return $this->get('evt_picture_link');
		}
		else{
			return false;
		}
	}
	
	function get_add_to_calendar_links(){
		$session = SessionControl::get_instance();
		$calendar_links = array();

		//CALENDAR LINKS
		//FROM https://github.com/spatie/calendar-links	
		if($this->get('evt_start_time') && $this->get('evt_show_add_to_calendar_link')){
			$start_time_obj = LibraryFunctions::get_time_obj($this->get_event_start_time($session->get_timezone()), $session->get_timezone());	
			$end_time_obj = LibraryFunctions::get_time_obj($this->get_event_end_time($session->get_timezone()), $session->get_timezone());
			$settings = Globalvars::get_instance();
			$cal_link = LibraryFunctions::get_absolute_url('/profile/event_sessions?evt_event_id='.$this->key);
			$link = Link::create($this->get('evt_name'), $start_time_obj, $end_time_obj)
				->description($this->get('evt_short_description'))
				->address($cal_link);
				//->address('Kruikstraat 22, 2018 Antwerpen');
			$calendar_links['google'] =  $link->google();
			$calendar_links['yahoo'] = $link->yahoo();
			$calendar_links['outlook'] = $link->webOutlook();
			$calendar_links['ics'] = $link->ics();	
		}	
		
		return $calendar_links;
	}
	
	function get_register_url() {
		$products = new MultiProduct(
		array('event_id' => $this->get('evt_event_id'), 'is_active' => true));
		$numproducts = $products->count_all();

		if($this->get('evt_external_register_link')){
			return $this->get('evt_external_register_link');	
		}
		else if($numproducts == 1){
			$products->load();
			$product = $products->get(0);
			if($product->is_sold_out()){
				return NULL;
			}
			else{
				return $product->get_url();	
			}
		}
		else if($numproducts > 1){
			$products->load();			
			$product_list = array();
			foreach ($products as $product){
				$product_temp = array();
				if($product->is_sold_out()){
					$product_temp['label'] = $product->get('pro_name');
					$product_temp['link'] = NULL;					
				}
				else{
					$product_temp['label'] = $product->get('pro_name');
					$product_temp['link'] = $product->get_url();
				}
				$product_list[] = $product_temp;
			}
			return $product_list;	
		}
		else{
			return false;
		}
	}		
	
	function get_next_session(){
		$searches = array();
		$searches['event_id'] = $this->key;
		$searches['future'] = 'now()';
		$event_sessions_future = new MultiEventSessions($searches,
			array('start_time'=>'ASC'), $limit,
		0);
		$num_future_sessions = $event_sessions_future->count_all();
		$event_sessions_future->load();	
		if($num_future_sessions){
			return $event_sessions_future->get(0);
		}
		else{
			return false;
		}
	}
	
	function get_number_of_sessions(){
		$searches = array();
		$searches['event_id'] = $this->key;
		$searches['deleted'] = false;
		$event_sessions = new MultiEventSessions($searches);
		return $event_sessions->count_all();
	}

	function get_lowest_session_number(){
		$searches = array();
		$searches['event_id'] = $this->key;
		$searches['deleted'] = false;
		$event_sessions = new MultiEventSessions($searches,
			array('evs_session_number'=>'ASC', 'evs_title'=>'ASC'));
		$num_sessions = $event_sessions->count_all();
		$event_sessions->load();	
		if($num_sessions){
			return $event_sessions->get(0)->get('evs_session_number');
		}
		else{
			return false;
		}				
	}
	
	public function get_all_valid_session_numbers(){
		$results = new MultiEventSessions(array('event_id' => $this->key, 'deleted' => false));
		$results->load();

		$existing_numbers = array();
		$available_numbers = array();
		foreach ($results as $result){
			if($result->get('evs_session_number')){
				array_push($existing_numbers, $result->get('evs_session_number'));
			}
		}
		
		//NOW GET THE AVAILABLE ONESl
		if(empty($existing_numbers)){
			$max_value = 1;
		}
		else{
			$max_value = max($existing_numbers)+1;
		}

		for($x=1; $x<=$max_value; $x++){
			if(!in_array($x, $existing_numbers)){
				array_push($available_numbers, $x);
			}
		}
		
		$out_array = array();
		foreach($available_numbers as $available_number){
			$out_array[$available_number] = $available_number;
		}

		return $out_array;

	}
	
	function add_registrant($usr_user_id, $order_item=NULL, $bundle_id=NULL, $days_until_expire=NULL){
		$order = NULL;
		if($order_item){
			$order = $order_item->get_order();
		}
		
		if($event_registrant = EventRegistrant::check_if_registrant_exists($usr_user_id, $this->get('evt_event_id'))){
			//PLAIN PERMANENT PURCHASES GET PRIORITY, THEN BUNDLES AND SUBSCRIPTIONS
			if($order_item && !$order_item->get('odi_is_subscription') && !$bundle_id){
				//STRAIGHT PURCHASE...REPLACE OLD ORDER WITH NEW INFO
				$event_registrant->set('evr_ord_order_id', $order->key);
				$event_registrant->set('evr_odi_order_item_id', $order_item->key);
				$event_registrant->set('evr_grp_group_id', NULL);
			}
			
			//IF THE REGISTRANT HAD EXPIRED, SET A NEW EXPIRATION DATE OR REMOVE EXPIRATION DATE
			if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
				if($days_until_expire){
					$date = new DateTime();
					$date->add(new DateInterval('P'.$days_until_expire.'D'));
					$event_registrant->set('evr_expires_time', $date->format('Y-m-d g:i:s'));	
				}
				else{
					$event_registrant->set('evr_expires_time', NULL);
				}
			}
			
			$event_registrant->set('evr_ord_order_id', $order->key);
			$event_registrant->save();
			return $event_registrant;
		}
		else{
			$event_registrant = new EventRegistrant(NULL);
			$event_registrant->set('evr_usr_user_id', $usr_user_id);
			
			if($bundle_id){
				$event_registrant->set('evr_grp_group_id', $bundle_id);
			}
			
			if($order){
				$event_registrant->set('evr_ord_order_id', $order->key);
				$event_registrant->set('evr_odi_order_item_id', $order_item->key);
			}
			$event_registrant->set('evr_evt_event_id', $this->get('evt_event_id'));		

			if($days_until_expire){
				$date = new DateTime();
				$date->add(new DateInterval('P'.$days_until_expire.'D'));
				$event_registrant->set('evr_expires_time', $date->format('Y-m-d g:i:s'));	
			}
			$event_registrant->save();	
			$event_registrant->load();	
			return $event_registrant;
		}	
		
	}
	
	function remove_registrant($usr_user_id){
		if($event_registrant = EventRegistrant::check_if_registrant_exists($usr_user_id, $this->get('evt_event_id'))){
			return $event_registrant->remove();
		}
		return FALSE;
	}	

	function get_event_start_time($tz='event', $format='M j, Y g:i a T') {
		/*
		if($tz == 'event' || !$tz){
			$utc_time = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $this->get('evt_timezone'), $format);
		}
		else{
			$utc_time = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $tz, $format);
		}
		*/
		
		//WE ARE NOW USING LOCAL TIME TO DISPLAY
		if($tz == 'event' || !$tz){
			return LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $tz, $format);
		}		
	}

	function get_event_end_time($tz='event', $format='M j, Y g:i a T') {
		/*
		if($tz == 'event' || !$tz){
			return LibraryFunctions::convert_time($this->get('evt_end_time'), 'UTC', $this->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evt_end_time'), 'UTC', $tz, $format);
		}
		*/
		
		if($tz == 'event' || !$tz){
			return LibraryFunctions::convert_time($this->get('evt_end_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evt_end_time_local'), $this->get('evt_timezone'), $tz, $format);
		}
		
	}	
	
	function get_time_string($tz='event', $dayformat = 'M j,', $timeformat = 'g:i a'){
		
		if(!$this->get('evt_start_time') && !$this->get('evt_end_time')){
			return '';
		}

		if($tz == 'event' || !$tz){
			$start_day =  LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), $dayformat);
			$start_time =  LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), $timeformat);
			if($this->get('evt_end_time')){
				$end_day =  LibraryFunctions::convert_time($this->get('evt_end_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), $dayformat);
				$end_time =  LibraryFunctions::convert_time($this->get('evt_end_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), $timeformat);
			}
			$timezone = LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $this->get('evt_timezone'), 'T');
		}
		else{
			$start_day =  LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $tz, $dayformat);
			$start_time =  LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $tz, $timeformat);
			if($this->get('evt_end_time')){
				$end_day =  LibraryFunctions::convert_time($this->get('evt_end_time_local'), $this->get('evt_timezone'), $tz, $dayformat);
				$end_time =  LibraryFunctions::convert_time($this->get('evt_end_time_local'), $this->get('evt_timezone'), $tz, $timeformat);
			}
			$timezone = LibraryFunctions::convert_time($this->get('evt_start_time_local'), $this->get('evt_timezone'), $tz, 'T');
		}
		
		if(!$this->get('evt_end_time')){
			return $start_day . ' ' . $start_time . ' ' . $timezone;
		}
		else if($start_day == $end_day){
			return $start_day . ' ' . $start_time . ' - ' . $end_time . ' ' . $timezone;
		}
		else{
			return $start_day . ' ' . $start_time . ' - ' . $end_day . ' ' . $end_time . ' ' . $timezone;
		}
		
	}	


	function output_product_dropdown($formwriter, $currentvalue, $extra_data=array()) {
		require_once(PathHelper::getIncludePath('data/products_class.php'));

		$products = new MultiProduct(
			array(
			)); 
		$products->load();
		if ($products) {
			$version_dropdown = array();
			foreach ($products as $product) {
				$output_string = $product->get('pro_name');
				$version_dropdown[$product->key] = $output_string;
			}
			
			echo $formwriter->dropinput(
				'Registration product for this event',
				'evt_pro_product_id',
				'ctrlHolder',
				$version_dropdown,
				$currentvalue,
				'',
				TRUE);
		}

		/*
		$form_javascript = array();
		foreach ($this->get_product_requirements() as $product_requirement) {
			$product_requirement->get_form($formwriter, $user);	
		}
		*/
		
		return TRUE;
	}	

	function prepare() {
		if ($this->data === NULL) {
			throw new eventException('This request has no data.');
		}
		
		//TODO MAKE SURE PRODUCT IS ATTACHED BEFORE REGISTRATION
		
		/*
		if (!$this->get('evt_travel_type')) {
			throw new DisplayableeventException('You must select a travel preference.');
		}

		if ($this->get('evt_expires_time') != $old_expiry->format(DATE_ATOM)) { 
			$this->set('evt_expiry_email_sent', FALSE);
		}
		*/
	}

	function export_as_array($session=NULL) { 
		$output_array = parent::export_as_array();
		//$output_array['travel_text'] = $this->get_travel_text();
		return $output_array;
	}

	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}	
	
	function save($debug=false) {
		
		if ($this->key) {
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_EVENT, $this->key, $this->get('evt_description'), $this->get('evt_name'), $this->get('evt_name'));				
		}
		
		parent::save($debug);

	}	
	
	/*
	function permanent_delete($debug=false){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}		
		
		//DELETE ANY REGISTRATIONS
		$event_registrants = new MultiEventRegistrant(array('event_id' => $this->key), NULL);
		$event_registrants->load();
		foreach($event_registrants as $event_registrant){
			$event_registrant->remove();
		}	
		
		//DELETE WAITING LIST
		$event_registrants = new MultiWaitingList(array('event_id' => $this->key), NULL);
		$event_registrants->load();
		foreach($event_registrants as $event_registrant){
			$event_registrant->remove();
		}	
		
		parent::permanent_delete($debug);
		
		if($this_transaction){
			$dblink->commit();
		}	
		
		return true;
	}
	*/

	function copy() { 
		// Returns a copy of this event, with history, etc reset so it can be used
		$event = new event(NULL);
		foreach (self::$field_specifications as $field => $spec) { 
			$event->set($field, $this->get($field));
		}
		$event->set('evt_created_time', 'NOW');
		$event->set('evt_expires_time', NULL);
		$event->set('evt_needed_time', NULL);
		$event->set('evt_cl_post_link', NULL);
		$event->set('evt_close_reason', NULL);
		$event->set('evt_status', event::ACTIVE);
		$event->set('evt_copy_evt_event_id', $this->key);
		$event->prepare();
		$event->save();
		$event->load();

		return $event;
	}

}

class MultiEvent extends SystemMultiBase {
	protected static $model_class = 'Event';

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = LibraryFunctions::convert_time($entry->get('evt_start_time'), "UTC", "UTC", 'M j, Y') . ' ' . $entry->get('evt_name');
			$items[$entry->key] = $option_display;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['event_id'])) {
            $filters['evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['name_like'])) {
            $filters['evt_name'] = 'ILIKE \'%'.$this->options['name_like'].'%\'';
        }
        
        if (isset($this->options['user_id_leader'])) {
            $filters['evt_usr_user_id_leader'] = [$this->options['user_id_leader'], PDO::PARAM_INT];
        }

        if (isset($this->options['link'])) {
            $filters['evt_link'] = [$this->options['link'], PDO::PARAM_STR];
        }

        if (isset($this->options['status'])) {
            $filters['evt_status'] = [$this->options['status'], PDO::PARAM_INT];
        }

        if (isset($this->options['type'])) {
            $filters['evt_ety_event_type_id'] = [$this->options['type'], PDO::PARAM_INT];
        }

        if (isset($this->options['status_not_cancelled'])) {
            $filters['evt_status'] = '= 1 OR evt_status = 2';
        }

        if (isset($this->options['deleted'])) {
            $filters['evt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        if (isset($this->options['past'])) {
            $filters['evt_end_time'] = ($this->options['past'] ? '< now() OR evt_end_time IS NULL' : '> now() OR evt_end_time IS NULL');
        }
        
        if (isset($this->options['name'])) {
            $filters['evt_name'] = 'ILIKE \'%'.$this->options['name'].'%\'';
        }
    
        if (isset($this->options['visibility'])) {
            $filters['evt_visibility'] = [$this->options['visibility'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('evt_events', $filters, $this->order_by, $only_count, $debug);
    }

}

?>
