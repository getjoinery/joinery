<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/files_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/content_versions_class.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Link.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generator.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/Google.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/Ics.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/Yahoo.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/calendar-links/Generators/WebOutlook.php');
use Spatie\CalendarLinks\Link;

class EventException extends SystemClassException {}
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

class Event extends SystemBase {

	const STATUS_ACTIVE = 1;
	const STATUS_COMPLETED = 2;
	const STATUS_CANCELLED = 3;
	
	const DISPLAY_CONDENSED = 1;
	const DISPLAY_SEPARATE = 2;
	
	const VISIBILITY_PRIVATE = 0;
	const VISIBILITY_PUBLIC = 1;
	const VISIBILITY_PUBLIC_UNLISTED = 2;	
	
	const TYPE_LIVE_ONLINE = 1;
	const TYPE_SELF_PACED_ONLINE = 2;
	const TYPE_RETREAT = 3;
	const TYPE_IN_PERSON = 4;

	public static $fields = array(
		'evt_event_id' => 'event ID',
		'evt_name' => 'Name',
		'evt_description' => 'Description',
		'evt_short_description' => 'Short description',
		'evt_usr_user_id_leader' => 'Who is leading the retreat',
		'evt_location' => 'Location of the retreat',
		'evt_start_time' => 'Timestamp when this event begins',
		'evt_start_time_local' => 'Stored local start time',
		'evt_end_time' => 'Timestamp when this event ends',
		'evt_end_time_local' => 'Stored local end time',
		'evt_create_time' => 'Timestamp when this request was created',
		'evt_external_register_link' => 'Link to register if the event is not being registered here',
		'evt_timezone' => 'Timezone where the event is happening',
		'evt_is_accepting_signups' => 'Are we taking signups',
		'evt_picture_link' => 'If present, is the promo picture for the event', //DEPRECATED
		'evt_collect_extra_info' => 'extra info',
		'evt_grp_group_id' => 'Group for the event registrants',
		'evt_private_info' => 'Information displayed only to registrants',
		'evt_status' => '1: active, 2: completed, 3: cancelled', 
		'evt_max_signups' => 'Max amount of signups',
		'evt_allow_waiting_list' => 'If true, waiting list is active',
		'evt_session_display_type' => '1=condensed, 2=separate pages for each session',
		'evt_visibility'=>'0=private, 1=public,2=public but unlisted',
		'evt_fil_file_id' => 'File id of the picture attached',
		'evt_link' => 'Link for the event',
		'evt_show_add_to_calendar_link' => 'Whether to show the calendar link',
		'evt_type' => 'Type of event, see above',
		'evt_delete_time' => 'Time of deletion',
	); 

	public static $generated_fields = array(
		//'evt_is_expired' => 'Is this request expired?'
	);

	public static $constants = array(
		//'evt_usr_user_id'
		);

	public static $required = array(
		'evt_name'
	);
	
	public static $initial_default_values = array(
		'evt_create_time' => NOW, 'evt_visibility' => 0, 'evt_status' => 1, 'evt_show_add_to_calendar_link' => true
	);	

	public static $field_constraints = array(
		'evt_name' => array(
			array('WordLength', 0, 255),
			'NoCaps',
			),
		//'evt_description' => array(
		//	array('WordLength', 50, 100000),
		//	'NoCaps',
		//	),
		);
		
	public $prefix = 'evt';

	public static $public_actions = array(
		'togglepublic' => array('w' => TRUE),
	);
	
	static function get_by_link($link){
		$params = explode("/", $link);
		if($params[0] == 'event'){
			$lookup = $params[1];
		}
		else{
			$lookup = $params[0];
		}
		$results = new MultiEvent(array('link' => $lookup, 'deleted'=>false));
		$results->load();
	
		if($results->count()){	
			return $results->get(0);	
		}
		else{
			//THIS IS THE OLD URL SCHEME
			try{
				$event = new Event((int)$params[1], TRUE);
				return $event;
			}
			catch(exception $e){
				return false;
			}
		}
	}	
	
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
			return $file->get_url($type);
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
			$webDir = $settings->get_setting('webDir_SSL');	
			$cal_link = $webDir.'/profile/event_sessions?evt_event_id='.$this->key;
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
	
	function create_url() {
		$tmp = strtolower(str_replace(' ', '-', $this->get('evt_name')));
		$tmp = preg_replace("/[^a-zA-Z0-9-]/", "", $tmp);
		$tmp = preg_replace('/-{2,}/', '-', $tmp);
		
		//NO DUPLICATES
		$increment=1;
		$tmp_orig = $tmp;
		while(Event::get_by_link($tmp)){
			$tmp = $tmp_orig . $increment;
			$increment++;
		}
		return $tmp;
	}
	
	function get_url() {
		return '/event/' . $this->get('evt_link');
	}
	
	function get_register_url() {
		$products = new MultiProduct(
		array('event_id' => $this->get('evt_event_id')));
		$numproducts = $products->count_all();

		if($this->get('evt_external_register_link')){
			return $this->get('evt_external_register_link');	
		}
		else if($numproducts){
			$products->load();
			$product = $products->get(0);
			return '/product?product_id=' . $product->key;	
		}
		else{
			throw new SystemDisplayablePermanentError(
				'The event ' . $this->get('evt_name') . ' is missing a register link or a product.');
				exit();	;
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
	
	function add_registrant($usr_user_id, $ord_order_id = NULL, $days_until_expire=NULL){
		if($event_registrant = EventRegistrant::check_if_registrant_exists($usr_user_id, $this->get('evt_event_id'))){
			return $event_registrant;
		}
		else{
			$event_registrant = new EventRegistrant(NULL);
			$event_registrant->set('evr_usr_user_id', $usr_user_id);
			if($ord_order_id){
				$event_registrant->set('evr_ord_order_id', $ord_order_id);
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
	
	
	
	public static $json_vars = array(
		'evt_event_id', 'evt_name', 'evt_description');




	
	function output_product_dropdown($formwriter, $currentvalue, $extra_data=array()) {
		require_once($_SERVER['DOCUMENT_ROOT'] . '/data/products_class.php');

		$products = new MultiProduct(
			array(
			)); 
		$products->load();
		if ($products) {
			$version_dropdown = array();
			foreach ($products as $product) {
				$output_string = $product->get('pro_name');
				$version_dropdown[$output_string] = $product->key;
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

	function load() {
		parent::load();

		//$sql = 'SELECT *, (COALESCE(evt_expires_time, NOW()) < NOW()) as evt_is_expired FROM evt_events WHERE evt_event_id = ?';
		$sql = 'SELECT * FROM evt_events WHERE evt_event_id = ?';

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
			
		try {
			$q = $dblink->prepare($sql);
			$q->bindValue(1, $this->key, PDO::PARAM_INT);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		if (!$q->rowCount()) {
			throw new DisplayablePermanenteventException('Sorry, this request doesn\'t exist.');
		}

		$this->data = $q->fetch();
	}

	function prepare() {
		if ($this->data === NULL) {
			throw new eventException('This request has no data.');
		}

		$this->check_field_constraints();
		
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


	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($session->get_permission() < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this item.');
		}
	}	
	
	function save() {
		parent::save();
		// Saving requires some session control for authentication checking and whatnot
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('evt_event_id' => $this->key);
			//$rowdata['evt_lastedit_time'] = 'NOW()';
			// Editing an existing record
			
			//SAVE THE OLD VERSION IN THE CONTENT_VERSION TABLE
			ContentVersion::NewVersion(ContentVersion::TYPE_EVENT, $this->key, $this->get('evt_description'), $this->get('evt_name'), $this->get('evt_name'));			
		} else {
			$p_keys = NULL;
			// Creating a new record
			unset($rowdata['evt_event_id']);
			$rowdata['evt_create_time'] = 'NOW()';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, "evt_events", $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['evt_event_id'];
	}	
	
	
	function copy() { 
		// Returns a copy of this event, with history, etc reset so it can be used
		$event = new event(NULL);
		foreach (self::$fields as $field => $description) { 
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
	
	function soft_delete(){
		$this->set('evt_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('evt_delete_time', NULL);
		$this->save();	
		return true;
	}
	
	function permanent_delete(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}

		
		$event_registrants = new MultiEventRegistrant(
		array('event_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$event_registrants->load();
		
		foreach ($event_registrants as $event_registrant){
			$event_registrant->remove();
		}

		$event_sessions = new MultiEventSessions(
		array('event_id'=>$this->key),
		NULL,
		NULL,
		NULL);
		$event_sessions->load();
		
		foreach ($event_sessions as $event_session){
			$event_session->permanent_delete();
		}

		$sql = 'DELETE FROM evt_events WHERE evt_event_id=:evt_event_id';
		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':evt_event_id', $this->key, PDO::PARAM_INT);
			$count = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}
			
		if($this_transaction){
			$dblink->commit();
		}
		$this->key = NULL;
		
		return true;		
	}	
	

	static public function GetPublicActions() { 
		return self::$public_actions;
	}

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS evt_events_evt_event_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."evt_events" (
			  "evt_event_id" int4 NOT NULL DEFAULT nextval(\'evt_events_evt_event_id_seq\'::regclass),
			  "evt_name" varchar(128) COLLATE "pg_catalog"."default",
			  "evt_description" text COLLATE "pg_catalog"."default",
			  "evt_create_time" timestamp(6) DEFAULT now(),
			  "evt_start_time" timestamp(6),
			  "evt_start_time_local" timestamp(6),
			  "evt_end_time" timestamp(6),
			  "evt_end_time_local" timestamp(6),
			  "evt_is_accepting_signups" bool,
			  "evt_short_description" text COLLATE "pg_catalog"."default",
			  "evt_location" varchar(255) COLLATE "pg_catalog"."default",
			  "evt_external_register_link" varchar(255) COLLATE "pg_catalog"."default" DEFAULT NULL::character varying,
			  "evt_timezone" varchar(32) COLLATE "pg_catalog"."default",
			  "evt_usr_user_id_leader" int4,
			  "evt_picture_link" varchar(255) COLLATE "pg_catalog"."default",
			  "evt_collect_extra_info" bool DEFAULT false,
			  "evt_grp_group_id" int4,
			  "evt_private_info" text COLLATE "pg_catalog"."default",
			  "evt_status" int4,
			  "evt_max_signups" int4,
			  "evt_allow_waiting_list" bool,
			  "evt_session_display_type" int4 DEFAULT 1,
			  "evt_visibility" int4,
			  "evt_link" varchar(255) COLLATE "pg_catalog"."default",
			  "evt_show_add_to_calendar_link" bool, 
			  "evt_type" int4,
			  "evt_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."evt_events" ADD CONSTRAINT "evt_events_pkey" PRIMARY KEY ("evt_event_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		try{		
			$sql = 'CREATE INDEX CONCURRENTLY evt_events_evt_link ON evt_events USING HASH (evt_link);';
			$q = $dburl->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}		

}

class Multievent extends SystemMultiBase {

	function get_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $entry) {
			$option_display = LibraryFunctions::convert_time($entry->get('evt_start_time'), "UTC", "UTC", 'M j, Y') . ' ' . $entry->get('evt_name'); 
			$items[$option_display] = $entry->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}

	private function _get_results($only_count=FALSE) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}
		
		if (array_key_exists('name_like', $this->options)) {
			$where_clauses[] = 'evt_name ILIKE ?';
			$bind_params[] = array('%'.$this->options['name_like'].'%', PDO::PARAM_STR);
		}			
		
		if (array_key_exists('user_id_leader', $this->options)) {
			$where_clauses[] = 'evt_usr_user_id_leader = ?';
			$bind_params[] = array($this->options['user_id_leader'], PDO::PARAM_INT);
		}

		if (array_key_exists('link', $this->options)) {
			$where_clauses[] = 'evt_link = ?';
			$bind_params[] = array($this->options['link'], PDO::PARAM_STR);
		}

		if (array_key_exists('status', $this->options)) {
			$where_clauses[] = 'evt_status = ?';
			$bind_params[] = array($this->options['status'], PDO::PARAM_INT);
		}

		if (array_key_exists('type', $this->options)) {
			$where_clauses[] = 'evt_type = ?';
			$bind_params[] = array($this->options['type'], PDO::PARAM_INT);
		}

		if (array_key_exists('status_not_cancelled', $this->options)) {
			$where_clauses[] = '(evt_status = 1 OR evt_status = 2)';
		}

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'evt_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		/*
		if (array_key_exists('expired', $this->options)) {
			$where_clauses[] = 'evt_expires_time ' . ($this->options['expired'] ? '<' : '>') . ' now()';
		}	
		*/		
		
		
		if (array_key_exists('past', $this->options)) {
			$where_clauses[] = '((evt_end_time ' . ($this->options['past'] ? '<' : '>') . ' now()) OR evt_end_time is null)';
		}	
				
		
		if (array_key_exists('name', $this->options)) {
			$where_clauses[] = 'evt_name ILIKE ?';
			$bind_params[] = array('%'.$this->options['name'].'%', PDO::PARAM_STR);
		}		
	
		if (array_key_exists('visibility', $this->options)) {
			$where_clauses[] = 'evt_visibility = ?';
			$bind_params[] = array($this->options['visibility'], PDO::PARAM_INT);
		}
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM evt_events ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM evt_events
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " evt_event_id ASC ";
			}
			else {
				if (array_key_exists('event_id', $this->order_by)) {
					$sql .= ' evt_event_id ' . $this->order_by['event_id'];
				}		

				if (array_key_exists('name', $this->order_by)) {
					$sql .= ' evt_name ' . $this->order_by['name'];
				}	
				
				if (array_key_exists('created_time', $this->order_by)) {
					$sql .= ' evt_created_time ' . $this->order_by['created_time'];
				}			

				if (array_key_exists('start_time', $this->order_by)) {
					$sql .= ' evt_start_time ' . $this->order_by['start_time'];
				}	

				if (array_key_exists('end_time', $this->order_by)) {
					$sql .= ' evt_end_time ' . $this->order_by['end_time'];
				}				

				if (array_key_exists('status', $this->order_by)) {
					$sql .= ' evt_status ' . $this->order_by['status'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);
		/*
		if($_SESSION['permission'] == 10){
			print_r($sql);
		}
		*/

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
			$child = new Event($row->evt_event_id);
			$child->load_from_data($row, array_keys(Event::$fields));
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
