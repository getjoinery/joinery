<?php
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
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
		'evt_loc_location_id' => ['action' => 'null'],
		'evt_fil_file_id' => ['action' => 'null'],
	];

	const STATUS_ACTIVE = 1;
	const STATUS_COMPLETED = 2;
	const STATUS_CANCELED = 3;
	
	const DISPLAY_CONDENSED = 1;
	const DISPLAY_SEPARATE = 2;
	
	const VISIBILITY_PRIVATE = 0;
	const VISIBILITY_PUBLIC = 1;
	const VISIBILITY_PUBLIC_UNLISTED = 2;

	// Fields to skip when copying parent to materialized instance
	const RECURRENCE_FIELDS = [
		'evt_recurrence_type', 'evt_recurrence_interval', 'evt_recurrence_days_of_week',
		'evt_recurrence_week_of_month', 'evt_recurrence_end_date'
	];

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
	    'evt_grp_group_id' => array('type'=>'int4'),
	    'evt_private_info' => array('type'=>'text'),
	    'evt_status' => array('type'=>'int4', 'default'=>1),
	    'evt_max_signups' => array('type'=>'int4'),
	    'evt_allow_waiting_list' => array('type'=>'bool'),
	    'evt_session_display_type' => array('type'=>'int4'),
	    'evt_visibility' => array('type'=>'int4', 'default'=>1),
	    'evt_fil_file_id' => array('type'=>'int4'),
	    'evt_link' => array('type'=>'varchar(255)'),
	    'evt_show_add_to_calendar_link' => array('type'=>'bool', 'default'=>true),
	    'evt_ety_event_type_id' => array('type'=>'int4'),
	    'evt_delete_time' => array('type'=>'timestamp(6)'),
	    'evt_svy_survey_id' => array('type'=>'int4'),
	    'evt_survey_display' => array('type'=>'varchar(30)', 'default'=>'none'),
	    'evt_loc_location_id' => array('type'=>'int4'),
	    'evt_custom_registration_message' => array('type'=>'varchar(255)', 'is_nullable'=>true),

	    // Recurrence pattern fields (set on parent events)
	    'evt_recurrence_type' => array('type'=>'varchar(20)', 'is_nullable'=>true),
	    'evt_recurrence_interval' => array('type'=>'integer', 'default'=>1),
	    'evt_recurrence_days_of_week' => array('type'=>'varchar(20)', 'is_nullable'=>true),
	    'evt_recurrence_week_of_month' => array('type'=>'integer', 'is_nullable'=>true),
	    'evt_recurrence_end_date' => array('type'=>'date', 'is_nullable'=>true),

	    // Instance relationship fields (set on materialized instances)
	    'evt_parent_event_id' => array('type'=>'integer', 'is_nullable'=>true),
	    'evt_materialized_instance_date' => array('type'=>'date', 'is_nullable'=>true),
	    'evt_tier_min_level' => array('type'=>'int4', 'is_nullable'=>true),
	    'evt_tier_public_after_hours' => array('type'=>'int4', 'is_nullable'=>true),
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
	
	/**
	 * Get picture URL for display
	 *
	 * @param string $size_key Image size key (default 'original')
	 * @return string|false URL or false if no picture
	 */
	function get_picture_link($size_key='original'){
		if($this->get('evt_fil_file_id')){
			$file = new File($this->get('evt_fil_file_id'), TRUE);
			return $file->get_url($size_key, 'full');
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
			$start_time_obj = new DateTime($this->get_event_start_time($session->get_timezone()), new DateTimeZone($session->get_timezone()));
			$end_time_obj = new DateTime($this->get_event_end_time($session->get_timezone()), new DateTimeZone($session->get_timezone()));

			// Build location: Location object address preferred, fallback to evt_location text
			$address = '';
			if ($this->get('evt_loc_location_id')) {
				require_once(PathHelper::getIncludePath('data/locations_class.php'));
				if (Location::check_if_exists($this->get('evt_loc_location_id'))) {
					$loc = new Location($this->get('evt_loc_location_id'), TRUE);
					$loc_addr = $loc->get('loc_address');
					if ($loc_addr) {
						$address = $loc_addr;
					}
				}
			}
			if (!$address && $this->get('evt_location')) {
				$address = $this->get('evt_location');
			}
			if (!$address) {
				$address = LibraryFunctions::get_absolute_url($this->get_url());
			}

			$link = Link::create($this->get('evt_name'), $start_time_obj, $end_time_obj)
				->description($this->get('evt_short_description'))
				->address($address);
			$calendar_links['google'] =  $link->google();
			$calendar_links['yahoo'] = $link->yahoo();
			$calendar_links['outlook'] = $link->webOutlook();
			// Use downloadable .ics endpoint instead of Spatie data URI
			$calendar_links['ics'] = '/event/' . $this->get('evt_link') . '.ics';
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
			array('start_time'=>'ASC'), 1,
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
		// Convert from UTC to target timezone
		if($tz == 'event' || !$tz){
			return LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $this->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $tz, $format);
		}
	}

	function get_event_end_time($tz='event', $format='M j, Y g:i a T') {
		// Convert from UTC to target timezone
		if($tz == 'event' || !$tz){
			return LibraryFunctions::convert_time($this->get('evt_end_time'), 'UTC', $this->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evt_end_time'), 'UTC', $tz, $format);
		}
	}	
	
	function get_time_string($tz='event', $dayformat = 'D, M j,', $timeformat = 'g:i a'){

		if(!$this->get('evt_start_time') && !$this->get('evt_end_time')){
			return '';
		}

		// Convert from UTC to target timezone
		if($tz == 'event' || !$tz){
			$target_tz = $this->get('evt_timezone');
		}
		else{
			$target_tz = $tz;
		}

		$start_day = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $target_tz, $dayformat);
		$start_time = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $target_tz, $timeformat);
		$timezone = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $target_tz, 'T');

		if($this->get('evt_end_time')){
			$end_day = LibraryFunctions::convert_time($this->get('evt_end_time'), 'UTC', $target_tz, $dayformat);
			$end_time = LibraryFunctions::convert_time($this->get('evt_end_time'), 'UTC', $target_tz, $timeformat);
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
				'evt_pro_product_id',
				'Registration product for this event',
				array(
					'options' => $version_dropdown,
					'value' => $currentvalue,
					'required' => TRUE
				));
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

		// Populate local time fields from UTC times (for timezone edge cases like DST)
		if ($this->get('evt_start_time') && $this->get('evt_timezone')) {
			$local_start = LibraryFunctions::convert_time(
				$this->get('evt_start_time'),
				'UTC',
				$this->get('evt_timezone'),
				'Y-m-d H:i:s'
			);
			$this->set('evt_start_time_local', $local_start);
		}

		if ($this->get('evt_end_time') && $this->get('evt_timezone')) {
			$local_end = LibraryFunctions::convert_time(
				$this->get('evt_end_time'),
				'UTC',
				$this->get('evt_timezone'),
				'Y-m-d H:i:s'
			);
			$this->set('evt_end_time_local', $local_end);
		}

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

	// ===== Entity Photo Methods =====

	/**
	 * Set a photo as the primary photo for this event
	 *
	 * @param int $photo_id EntityPhoto ID to set as primary
	 */
	function set_primary_photo($photo_id) {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));

		$photo = new EntityPhoto($photo_id, TRUE);
		$this->set('evt_fil_file_id', $photo->get('eph_fil_file_id'));
		$this->save();
	}

	/**
	 * Clear the primary photo for this event
	 */
	function clear_primary_photo() {
		$this->set('evt_fil_file_id', NULL);
		$this->save();
	}

	/**
	 * Get all photos for this event
	 *
	 * @return MultiEntityPhoto
	 */
	function get_photos() {
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'event', 'entity_id' => $this->key, 'deleted' => false],
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
		$file_id = $this->get('evt_fil_file_id');
		if (!$file_id) return null;
		require_once(PathHelper::getIncludePath('data/entity_photos_class.php'));
		$photos = new MultiEntityPhoto(
			['entity_type' => 'event', 'entity_id' => $this->key, 'file_id' => $file_id, 'deleted' => false],
			[], 1
		);
		$photos->load();
		return $photos->count() > 0 ? $photos->get(0) : null;
	}

	// ===== Recurring Event Methods =====

	/**
	 * Check if this is the parent/template of a recurring series
	 *
	 * @return bool
	 */
	public function is_recurring_parent() {
		return !empty($this->get('evt_recurrence_type'));
	}

	/**
	 * Check if this event is a materialized instance of a recurring series
	 *
	 * @return bool
	 */
	public function is_instance() {
		return !empty($this->get('evt_parent_event_id'));
	}

	/**
	 * Get the parent event if this is a materialized instance
	 *
	 * @return Event|null
	 */
	public function get_parent_event() {
		if (!$this->is_instance()) {
			return null;
		}
		$parent = new Event($this->get('evt_parent_event_id'), TRUE);
		return $parent;
	}

	/**
	 * Check if a specific date matches the recurrence pattern
	 *
	 * @param string $date Date to check (Y-m-d)
	 * @return bool
	 */
	public function date_matches_pattern($date) {
		if (!$this->is_recurring_parent()) {
			return false;
		}

		$tz = $this->get('evt_timezone') ?: 'America/New_York';
		$start_date = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $tz, 'Y-m-d');
		$check_date = date('Y-m-d', strtotime($date));

		// Can't be before start date
		if ($check_date < $start_date) {
			return false;
		}

		// Can't be after end date
		$end_date = $this->get('evt_recurrence_end_date');
		if ($end_date && $check_date > $end_date) {
			return false;
		}

		$type = $this->get('evt_recurrence_type');
		$interval = (int) $this->get('evt_recurrence_interval') ?: 1;

		$start_dt = new DateTime($start_date);
		$check_dt = new DateTime($check_date);

		switch ($type) {
			case 'daily':
				$diff_days = (int) $start_dt->diff($check_dt)->days;
				return ($diff_days % $interval) === 0;

			case 'weekly':
				$days_of_week = $this->get('evt_recurrence_days_of_week');
				$check_dow = (int) $check_dt->format('w'); // 0=Sun, 6=Sat

				if ($days_of_week) {
					$allowed_days = array_map('intval', explode(',', $days_of_week));
					if (!in_array($check_dow, $allowed_days)) {
						return false;
					}
				} else {
					// Default to the same day of week as start
					$start_dow = (int) $start_dt->format('w');
					if ($check_dow !== $start_dow) {
						return false;
					}
				}

				// Check interval: weeks since start
				$diff_days = (int) $start_dt->diff($check_dt)->days;
				$start_week = (int) floor($start_dt->format('U') / (7 * 86400));
				$check_week = (int) floor($check_dt->format('U') / (7 * 86400));
				// Use ISO week calculation for proper interval checking
				$start_week_num = (int) ($start_dt->diff(new DateTime('1970-01-05'))->days / 7);
				$check_week_num = (int) ($check_dt->diff(new DateTime('1970-01-05'))->days / 7);
				$week_diff = abs($check_week_num - $start_week_num);
				return ($week_diff % $interval) === 0;

			case 'monthly':
				$week_of_month = $this->get('evt_recurrence_week_of_month');

				if ($week_of_month !== null && $week_of_month !== '') {
					// By week: e.g., 2nd Tuesday
					$start_dow = (int) $start_dt->format('w');
					$check_dow = (int) $check_dt->format('w');
					if ($check_dow !== $start_dow) {
						return false;
					}

					$check_wom = $this->_get_week_of_month($check_dt);
					$target_wom = (int) $week_of_month;

					// Handle -1 (last)
					if ($target_wom === -1) {
						// Check if this is the last occurrence of this weekday in the month
						$next_week = clone $check_dt;
						$next_week->modify('+7 days');
						if ($next_week->format('m') === $check_dt->format('m')) {
							return false; // Not the last one
						}
					} else {
						if ($check_wom !== $target_wom) {
							return false;
						}
					}
				} else {
					// By date: same day of month as start
					$start_day = (int) $start_dt->format('j');
					$check_day = (int) $check_dt->format('j');
					$days_in_month = (int) $check_dt->format('t');

					// Handle months shorter than start day
					$target_day = min($start_day, $days_in_month);
					if ($check_day !== $target_day) {
						return false;
					}
				}

				// Check interval: months since start
				$month_diff = ((int) $check_dt->format('Y') - (int) $start_dt->format('Y')) * 12
					+ ((int) $check_dt->format('n') - (int) $start_dt->format('n'));
				return ($month_diff % $interval) === 0;

			case 'yearly':
				$start_month = (int) $start_dt->format('n');
				$start_day = (int) $start_dt->format('j');
				$check_month = (int) $check_dt->format('n');
				$check_day = (int) $check_dt->format('j');

				if ($check_month !== $start_month) {
					return false;
				}

				// Handle leap year: Feb 29 -> Feb 28 in non-leap years
				$days_in_month = (int) $check_dt->format('t');
				$target_day = min($start_day, $days_in_month);
				if ($check_day !== $target_day) {
					return false;
				}

				$year_diff = (int) $check_dt->format('Y') - (int) $start_dt->format('Y');
				return ($year_diff % $interval) === 0;

			default:
				return false;
		}
	}

	/**
	 * Get the week-of-month number for a date (1=first, 2=second, etc.)
	 *
	 * @param DateTime $dt
	 * @return int
	 */
	private function _get_week_of_month($dt) {
		$day = (int) $dt->format('j');
		return (int) ceil($day / 7);
	}

	/**
	 * Compute the next N occurrence dates from a starting point
	 *
	 * @param string $from_date Starting date (Y-m-d)
	 * @param int $count Number of dates to compute
	 * @return array Array of date strings (Y-m-d)
	 */
	public function compute_occurrence_dates($from_date, $count) {
		if (!$this->is_recurring_parent() || $count <= 0) {
			return [];
		}

		$dates = [];
		$type = $this->get('evt_recurrence_type');
		$interval = (int) $this->get('evt_recurrence_interval') ?: 1;
		$end_date = $this->get('evt_recurrence_end_date');
		$tz = $this->get('evt_timezone') ?: 'America/New_York';
		$start_date = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $tz, 'Y-m-d');

		// Start from whichever is later: from_date or start_date
		$current = new DateTime(max($from_date, $start_date));
		$max_iterations = $count * 50; // Safety limit
		$iterations = 0;

		while (count($dates) < $count && $iterations < $max_iterations) {
			$iterations++;
			$date_str = $current->format('Y-m-d');

			// Check end date
			if ($end_date && $date_str > $end_date) {
				break;
			}

			if ($this->date_matches_pattern($date_str)) {
				$dates[] = $date_str;
			}

			// Advance by one day for daily/weekly, or strategically for monthly/yearly
			if ($type === 'daily') {
				$current->modify('+1 day');
			} elseif ($type === 'weekly') {
				$current->modify('+1 day');
			} elseif ($type === 'monthly') {
				$current->modify('+1 day');
			} elseif ($type === 'yearly') {
				// Jump months at a time for yearly
				if (count($dates) > 0) {
					$current->modify('+11 months');
				} else {
					$current->modify('+1 day');
				}
			} else {
				$current->modify('+1 day');
			}
		}

		return $dates;
	}

	/**
	 * Create a virtual instance object for a given date
	 *
	 * @param string $instance_date The occurrence date (Y-m-d)
	 * @return stdClass Virtual event object
	 */
	public function create_virtual_instance($instance_date) {
		$virtual = new stdClass();
		$virtual->is_virtual = true;
		$virtual->parent_event_id = $this->key;
		$virtual->instance_date = $instance_date;
		$virtual->evt_event_id = null;

		// Copy all display fields from parent
		foreach (self::$field_specifications as $field => $spec) {
			$virtual->$field = $this->get($field);
		}

		// Adjust start/end times to the instance date (timezone-aware)
		if ($this->get('evt_start_time')) {
			$tz = $this->get('evt_timezone') ?: 'America/New_York';
			$event_tz = new DateTimeZone($tz);
			$utc_tz = new DateTimeZone('UTC');

			// Convert parent start to event timezone to extract local time-of-day
			$parent_start = new DateTime($this->get('evt_start_time'), $utc_tz);
			$parent_start->setTimezone($event_tz);
			$parent_local_date = $parent_start->format('Y-m-d');
			$parent_local_time = $parent_start->format('H:i:s');

			// Build instance start in event timezone, then convert to UTC
			$inst_start = new DateTime($instance_date . ' ' . $parent_local_time, $event_tz);
			$inst_start->setTimezone($utc_tz);
			$virtual->evt_start_time = $inst_start->format('Y-m-d H:i:s');

			if ($this->get('evt_end_time')) {
				$parent_end = new DateTime($this->get('evt_end_time'), $utc_tz);
				$parent_end->setTimezone($event_tz);
				$day_diff = (new DateTime($parent_local_date))->diff(new DateTime($parent_end->format('Y-m-d')))->days;
				$end_date = new DateTime($instance_date);
				if ($day_diff > 0) {
					$end_date->modify('+' . $day_diff . ' days');
				}
				$inst_end = new DateTime($end_date->format('Y-m-d') . ' ' . $parent_end->format('H:i:s'), $event_tz);
				$inst_end->setTimezone($utc_tz);
				$virtual->evt_end_time = $inst_end->format('Y-m-d H:i:s');
			}
		}

		// Set the parent slug for URL generation
		$virtual->evt_link = $this->get('evt_link');

		// Clear recurrence fields on virtual instance
		foreach (self::RECURRENCE_FIELDS as $field) {
			$virtual->$field = null;
		}

		// Set parent reference
		$virtual->evt_parent_event_id = $this->key;
		$virtual->evt_materialized_instance_date = $instance_date;

		return $virtual;
	}

	/**
	 * Get all materialized instances from the database
	 *
	 * @param string $start_date Optional start filter (Y-m-d)
	 * @param string $end_date Optional end filter (Y-m-d)
	 * @return MultiEvent
	 */
	public function get_materialized_instances($start_date = null, $end_date = null) {
		$options = ['parent_event_id' => $this->key, 'deleted' => false];
		$instances = new MultiEvent($options, ['evt_materialized_instance_date' => 'ASC']);
		$instances->load();

		// Filter by date range if specified
		if ($start_date || $end_date) {
			$filtered = [];
			foreach ($instances as $instance) {
				$inst_date = $instance->get('evt_materialized_instance_date');
				if ($start_date && $inst_date < $start_date) continue;
				if ($end_date && $inst_date > $end_date) continue;
				$filtered[] = $instance;
			}
			return $filtered;
		}

		return $instances;
	}

	/**
	 * Compute virtual instances for a date range, merged with materialized instances
	 *
	 * @param string $start_date Start of range (Y-m-d)
	 * @param string $end_date End of range (Y-m-d)
	 * @return array Array of Event objects (materialized) and stdClass objects (virtual)
	 */
	public function get_instances_for_range($start_date, $end_date) {
		if (!$this->is_recurring_parent()) {
			return [];
		}

		// 1. Compute all occurrence dates in the range
		$dates = [];
		$current = new DateTime($start_date);
		$end_dt = new DateTime($end_date);
		$tz = $this->get('evt_timezone') ?: 'America/New_York';
		$event_start = LibraryFunctions::convert_time($this->get('evt_start_time'), 'UTC', $tz, 'Y-m-d');

		// Don't generate dates before event creation
		if ($current->format('Y-m-d') < $event_start) {
			$current = new DateTime($event_start);
		}

		$max_iterations = 1000; // Safety limit
		$iterations = 0;
		while ($current <= $end_dt && $iterations < $max_iterations) {
			$iterations++;
			$date_str = $current->format('Y-m-d');

			// Check end date
			$rec_end = $this->get('evt_recurrence_end_date');
			if ($rec_end && $date_str > $rec_end) {
				break;
			}

			if ($this->date_matches_pattern($date_str)) {
				$dates[] = $date_str;
			}
			$current->modify('+1 day');
		}

		// 2. Load materialized instances in this range
		$materialized_list = $this->get_materialized_instances($start_date, $end_date);
		$materialized_by_date = [];
		if (is_array($materialized_list)) {
			foreach ($materialized_list as $instance) {
				$materialized_by_date[$instance->get('evt_materialized_instance_date')] = $instance;
			}
		} else {
			foreach ($materialized_list as $instance) {
				$materialized_by_date[$instance->get('evt_materialized_instance_date')] = $instance;
			}
		}

		// 3. Merge: use materialized if exists, otherwise virtual
		$instances = [];
		foreach ($dates as $date) {
			if (isset($materialized_by_date[$date])) {
				$instances[] = $materialized_by_date[$date];
			} else {
				$instances[] = $this->create_virtual_instance($date);
			}
		}

		return $instances;
	}

	/**
	 * Materialize a virtual instance into a real database record
	 *
	 * @param string $instance_date The occurrence date to materialize (Y-m-d)
	 * @return Event The materialized Event object
	 */
	public function materialize_instance($instance_date) {
		if (!$this->is_recurring_parent()) {
			throw new EventException('Cannot materialize: this event is not a recurring parent.');
		}

		// Check if already materialized
		$existing = $this->_get_materialized_instance_for_date($instance_date);
		if ($existing) {
			return $existing;
		}

		// Verify date matches pattern
		if (!$this->date_matches_pattern($instance_date)) {
			throw new EventException('Date does not match recurrence pattern: ' . $instance_date);
		}

		// Copy all parent fields except recurrence fields (same pattern as Event::copy())
		$instance = new Event(NULL);
		foreach (self::$field_specifications as $field => $spec) {
			if (in_array($field, self::RECURRENCE_FIELDS)) {
				continue;
			}
			if ($field === 'evt_event_id' || $field === 'evt_create_time' || $field === 'evt_delete_time') {
				continue;
			}
			$instance->set($field, $this->get($field));
		}

		// Explicitly clear recurrence fields (avoid empty string vs NULL issues)
		foreach (self::RECURRENCE_FIELDS as $rf) {
			$instance->set($rf, null);
		}

		// Set instance-specific fields
		$instance->set('evt_parent_event_id', $this->key);
		$instance->set('evt_materialized_instance_date', $instance_date);

		// Adjust start/end times to the instance date (timezone-aware)
		if ($this->get('evt_start_time')) {
			$tz = $this->get('evt_timezone') ?: 'America/New_York';
			$event_tz = new DateTimeZone($tz);
			$utc_tz = new DateTimeZone('UTC');

			// Convert parent start to event timezone to extract local time-of-day
			$parent_start = new DateTime($this->get('evt_start_time'), $utc_tz);
			$parent_start->setTimezone($event_tz);
			$parent_local_date = $parent_start->format('Y-m-d');
			$parent_local_time = $parent_start->format('H:i:s');

			// Build instance start in event timezone, then convert to UTC
			$inst_start = new DateTime($instance_date . ' ' . $parent_local_time, $event_tz);
			$inst_start->setTimezone($utc_tz);
			$instance->set('evt_start_time', $inst_start->format('Y-m-d H:i:s'));

			if ($this->get('evt_end_time')) {
				$parent_end = new DateTime($this->get('evt_end_time'), $utc_tz);
				$parent_end->setTimezone($event_tz);
				$day_diff = (new DateTime($parent_local_date))->diff(new DateTime($parent_end->format('Y-m-d')))->days;
				$end_date = new DateTime($instance_date);
				if ($day_diff > 0) {
					$end_date->modify('+' . $day_diff . ' days');
				}
				$inst_end = new DateTime($end_date->format('Y-m-d') . ' ' . $parent_end->format('H:i:s'), $event_tz);
				$inst_end->setTimezone($utc_tz);
				$instance->set('evt_end_time', $inst_end->format('Y-m-d H:i:s'));
			}
		}

		// Generate unique link for the instance
		$instance->set('evt_link', $instance->create_url($this->get('evt_name') . '-' . $instance_date));

		$instance->prepare();
		$instance->save();
		$instance->load();

		return $instance;
	}

	/**
	 * Get a materialized instance for a specific date
	 *
	 * @param string $date Y-m-d
	 * @return Event|null
	 */
	public function _get_materialized_instance_for_date($date) {
		// Validate date format to prevent SQL errors from bot-generated garbage URLs
		if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return null;
		}
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		$sql = "SELECT evt_event_id FROM evt_events WHERE evt_parent_event_id = ? AND evt_materialized_instance_date = ? AND evt_delete_time IS NULL LIMIT 1";
		$q = $dblink->prepare($sql);
		$q->execute([$this->key, $date]);
		$result = $q->fetch(PDO::FETCH_ASSOC);
		if ($result) {
			return new Event($result['evt_event_id'], TRUE);
		}
		return null;
	}

	/**
	 * End the recurring series from a given date forward
	 *
	 * @param string $end_date Stop generating instances on/after this date (Y-m-d). NULL = today
	 */
	public function end_series($end_date = null) {
		if (!$this->is_recurring_parent()) {
			return;
		}
		if ($end_date === null) {
			$end_date = date('Y-m-d');
		}
		$this->set('evt_recurrence_end_date', $end_date);
		$this->save();
	}

	/**
	 * Get a human-readable description of the recurrence pattern
	 *
	 * @return string
	 */
	public function get_recurrence_description() {
		if (!$this->is_recurring_parent()) {
			return '';
		}

		$type = $this->get('evt_recurrence_type');
		$interval = (int) $this->get('evt_recurrence_interval') ?: 1;
		$parts = [];

		switch ($type) {
			case 'daily':
				$parts[] = ($interval === 1) ? 'Every day' : 'Every ' . $interval . ' days';
				break;

			case 'weekly':
				$prefix = ($interval === 1) ? 'Every week' : 'Every ' . $interval . ' weeks';
				$days_of_week = $this->get('evt_recurrence_days_of_week');
				if ($days_of_week) {
					$day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
					$day_nums = array_map('intval', explode(',', $days_of_week));
					$day_labels = array_map(function($d) use ($day_names) { return $day_names[$d]; }, $day_nums);

					if (count($day_labels) > 1) {
						$last = array_pop($day_labels);
						$parts[] = $prefix . ' on ' . implode(', ', $day_labels) . ' and ' . $last;
					} else {
						$parts[] = $prefix . ' on ' . $day_labels[0];
					}
				} else {
					$start_dow = date('l', strtotime($this->get('evt_start_time')));
					$parts[] = $prefix . ' on ' . $start_dow;
				}
				break;

			case 'monthly':
				$prefix = ($interval === 1) ? 'Every month' : 'Every ' . $interval . ' months';
				$week_of_month = $this->get('evt_recurrence_week_of_month');
				if ($week_of_month !== null && $week_of_month !== '') {
					$ordinals = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'last'];
					$ordinal = isset($ordinals[(int)$week_of_month]) ? $ordinals[(int)$week_of_month] : $week_of_month . 'th';
					$dow = date('l', strtotime($this->get('evt_start_time')));
					$parts[] = $prefix . ' on the ' . $ordinal . ' ' . $dow;
				} else {
					$day = date('jS', strtotime($this->get('evt_start_time')));
					$parts[] = $prefix . ' on the ' . $day;
				}
				break;

			case 'yearly':
				$prefix = ($interval === 1) ? 'Every year' : 'Every ' . $interval . ' years';
				$date_str = date('F jS', strtotime($this->get('evt_start_time')));
				$parts[] = $prefix . ' on ' . $date_str;
				break;
		}

		// Add end date
		$end_date = $this->get('evt_recurrence_end_date');
		if ($end_date) {
			$parts[] = 'until ' . date('M j, Y', strtotime($end_date));
		}

		return implode(' ', $parts);
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
            $filters['(evt_status'] = '= 1 OR evt_status = 2)';
        }

        if (isset($this->options['deleted'])) {
            $filters['evt_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        if (isset($this->options['upcoming']) && $this->options['upcoming']) {
            $filters['(evt_end_time'] = "> now() OR (evt_end_time IS NULL AND evt_start_time > now()))";
        }

        if (isset($this->options['past'])) {
            if ($this->options['past']) {
                $filters['(evt_end_time'] = "< now() OR (evt_end_time IS NULL AND evt_start_time < now()))";
            } else {
                $filters['(evt_end_time'] = "> now() OR (evt_end_time IS NULL AND evt_start_time > now()) OR (evt_end_time IS NULL AND evt_start_time IS NULL))";
            }
        }
        
        if (isset($this->options['name'])) {
            $filters['evt_name'] = 'ILIKE \'%'.$this->options['name'].'%\'';
        }
    
        if (isset($this->options['visibility'])) {
            $filters['evt_visibility'] = [$this->options['visibility'], PDO::PARAM_INT];
        }

        if (isset($this->options['parent_event_id'])) {
            $filters['evt_parent_event_id'] = [$this->options['parent_event_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['exclude_recurring_parents']) && $this->options['exclude_recurring_parents']) {
            $filters['(evt_recurrence_type'] = "IS NULL OR evt_recurrence_type = '')";
        }

        if (isset($this->options['only_recurring_parents']) && $this->options['only_recurring_parents']) {
            $filters['evt_recurrence_type'] = "IS NOT NULL AND evt_recurrence_type != ''";
        }

        if (isset($this->options['exclude_materialized_instances']) && $this->options['exclude_materialized_instances']) {
            $filters['evt_parent_event_id'] = "IS NULL";
        }

        if (isset($this->options['exclude_past_materialized']) && $this->options['exclude_past_materialized']) {
            $filters['(evt_parent_event_id'] = "IS NULL OR evt_start_time > now() OR evt_start_time IS NULL)";
        }

        if (isset($this->options['recurring_or_future']) && $this->options['recurring_or_future']) {
            $filters['((evt_recurrence_type'] = "IS NOT NULL AND evt_recurrence_type != '') OR evt_end_time > now() OR (evt_end_time IS NULL AND evt_start_time > now()) OR (evt_end_time IS NULL AND evt_start_time IS NULL))";
        }

        if (isset($this->options['max_visible_tier_level'])) {
            $level = intval($this->options['max_visible_tier_level']);
            $filters['(evt_tier_min_level'] = "<= {$level} OR evt_tier_min_level IS NULL)";
        }

        return $this->_get_resultsv2('evt_events', $filters, $this->order_by, $only_count, $debug);
    }

	/**
	 * Get events with repeating (recurring) series expanded into individual instances.
	 *
	 * Returns a merged, sorted array of standalone Event objects, materialized
	 * instances, and virtual stdClass instances. Handles all deduplication so
	 * callers don't need to know about recurring internals.
	 *
	 * @param array  $options    MultiEvent filter options (deleted, visibility, status, upcoming, past, etc.)
	 *                           Do NOT pass exclude_recurring_parents/exclude_materialized_instances/only_recurring_parents — they are managed internally.
	 * @param string $range_end  End date for recurring expansion (Y-m-d). Default: +6 months from today.
	 * @param int    $limit      Max events to return. Default: null (no limit).
	 * @return array  Mixed array of Event objects and virtual stdClass instances, sorted by start time ASC.
	 */
	static function getWithRepeatingEvents($options = [], $range_end = null, $limit = null) {
		if (!$range_end) {
			$range_end = date('Y-m-d', strtotime('+6 months'));
		}
		$range_start = date('Y-m-d');

		// 1. Get standalone events (exclude parents and materialized instances)
		$standalone_options = $options;
		$standalone_options['exclude_recurring_parents'] = true;
		$standalone_options['exclude_materialized_instances'] = true;
		$standalone_events = new MultiEvent($standalone_options, ['evt_start_time' => 'ASC']);
		$standalone_events->load();
		$all_events = iterator_to_array($standalone_events);

		// 2. Get recurring parents and expand into instances
		$parent_options = [
			'deleted' => $options['deleted'] ?? false,
			'visibility' => $options['visibility'] ?? null,
			'only_recurring_parents' => true,
			'status' => Event::STATUS_ACTIVE,
		];
		// Remove null values
		$parent_options = array_filter($parent_options, function($v) { return $v !== null; });

		$parents = new MultiEvent($parent_options, []);
		$parents->load();

		foreach ($parents as $parent) {
			$parent_pic = $parent->get_picture_link();
			$instances = $parent->get_instances_for_range($range_start, $range_end);
			foreach ($instances as $instance) {
				$is_virtual = is_object($instance) && isset($instance->is_virtual) && $instance->is_virtual;
				if ($is_virtual) {
					$instance->_picture_link = $parent_pic;
				}
				$all_events[] = $instance;
			}
		}

		// 3. Sort by start time
		usort($all_events, function($a, $b) {
			$a_virtual = is_object($a) && isset($a->is_virtual) && $a->is_virtual;
			$b_virtual = is_object($b) && isset($b->is_virtual) && $b->is_virtual;
			$a_time = $a_virtual ? $a->evt_start_time : $a->get('evt_start_time');
			$b_time = $b_virtual ? $b->evt_start_time : $b->get('evt_start_time');
			return strcmp($a_time, $b_time);
		});

		// 4. Apply limit
		if ($limit) {
			$all_events = array_slice($all_events, 0, $limit);
		}

		return $all_events;
	}

}

?>
