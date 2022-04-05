<?php
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SessionControl.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');

require_once($siteDir . '/data/event_registrants_class.php');

require_once($siteDir . '/includes/calendar-links/Link.php');
require_once($siteDir . '/includes/calendar-links/Generator.php');
require_once($siteDir . '/includes/calendar-links/Generators/Google.php');
require_once($siteDir . '/includes/calendar-links/Generators/Ics.php');
require_once($siteDir . '/includes/calendar-links/Generators/Yahoo.php');
require_once($siteDir . '/includes/calendar-links/Generators/WebOutlook.php');
use Spatie\CalendarLinks\Link;

class EventSessionsException extends SystemClassException {}
class DisplayableEventSessionsException extends EventSessionsException implements DisplayableErrorMessage {}
class DisplayablePermanentEventSessionsException extends EventSessionsException implements DisplayablePermanentErrorMessage {}

/*
class EventSessionsUnviewableDisplayException extends EventSessionsException implements CustomErrorPage {
	function __construct($title, $error_message) {
		$this->title = $title;
		$this->error_message = $error_message;
		parent::__construct($error_message);
	}

	function display_error_page() {
		PublicPageTW::OutputGenericPublicPage(
			$this->title,
			$this->title,
			$this->error_message,
			array(
				'noindex' => TRUE
			));
	}
}
*/

class EventSession extends SystemBase {
	public $prefix = 'evs';
	public $tablename = 'evs_event_sessions';
	public $pkey_column = 'evs_event_session_id';

	public static $fields = array(
		'evs_event_session_id' => 'event session ID',
		'evs_evt_event_id' => 'event ID',
		'evs_title' => 'Session title',
		'evs_content' => 'Page content',
		'evs_start_time' => 'start time',
		'evs_start_time_local' => 'Stored local start time',
		'evs_end_time' => 'end time',
		'evs_end_time_local' => 'Stored local start time',
		'evs_links' => 'html box for a list of links',
		'evs_picture_link' => 'link to a picture',
		'evs_is_public' => 'Is this request public?',
		'evs_order' => 'sort order',
		'evs_vid_video_id' => 'Video attached to session',
		'evs_session_number' => 'Optional number for ordering the sessions',
		'evs_delete_time' => 'Time of deletion',
		); 


	public static $required_fields = array(
		'evs_evt_event_id'
	);
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array(
		'evs_is_public' => FALSE
	);	

	public static $field_constraints = array(
	/*
		'evs_name' => array(
			array('WordLength', 0, 255),
			'NoCaps',
			),
		'evs_description' => array(
			array('WordLength', 50, 100000),
			'NoCaps',
			),
					*/
		);


	public static $public_actions = array(
		'togglepublic' => array('w' => TRUE),
	);
	
	function get_url() {
		return '/event/' . $this->key . '/' . str_replace(' ', '-', $this->get('evs_name'));
	}
		
	
	function get_start_time($tz='event', $format='M j, Y g:i a T') {
		$event = new Event($this->get('evs_evt_event_id'), TRUE);
		if($tz == 'event' || !$tz){
			
			return LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $tz, $format);
		}
	}

	function get_end_time($tz='event', $format='M j, Y g:i a T') {
		$event = new Event($this->get('evs_evt_event_id'), TRUE);
		if($tz == 'event' || !$tz){
			
			return LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), $format);
		}
		else{
			return LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $tz, $format);
		}
	}

	function get_time_string($tz='event', $dayformat = 'M j,', $timeformat = 'g:i a'){
		$event = new Event($this->get('evs_evt_event_id'), TRUE);
		if($tz == 'event' || !$tz){
			$start_day =  LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), $dayformat);
			$start_time =  LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), $timeformat);
			if($this->get('evs_end_time')){
				$end_day =  LibraryFunctions::convert_time($this->get('evs_end_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), $dayformat);
				$end_time =  LibraryFunctions::convert_time($this->get('evs_end_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), $timeformat);
			}
			$timezone = LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $event->get('evt_timezone'), 'T');
		}
		else{
			$start_day =  LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $tz, $dayformat);
			$start_time =  LibraryFunctions::convert_time($this->get('evs_start_time_local'), $event->get('evt_timezone'), $tz, $timeformat);
			if($this->get('evs_end_time')){
				$end_day =  LibraryFunctions::convert_time($this->get('evs_end_time_local'), $event->get('evt_timezone'), $tz, $dayformat);
				$end_time =  LibraryFunctions::convert_time($this->get('evs_end_time_local'), $event->get('evt_timezone'), $tz, $timeformat);
			}
			$timezone = LibraryFunctions::convert_time($this->get('evs_end_time_local'), $event->get('evt_timezone'), $tz, 'T');
		}
		
		if(!$this->get('evs_end_time')){
			return $start_day . ' ' . $start_time . ' ' . $timezone;
		}
		else if($start_day == $end_day){
			return $start_day . ' ' . $start_time . ' - ' . $end_time . ' ' . $timezone;
		}
		else{
			return $start_day . ' ' . $start_time . ' - ' . $end_day . ' ' . $end_time . ' ' . $timezone;
		}
		
	}
	
	function get_add_to_calendar_links(){
		$session = SessionControl::get_instance();
		$calendar_links = array();

		//CALENDAR LINKS
		//FROM https://github.com/spatie/calendar-links	
		if($this->get('evs_start_time')){
			$start_time_obj = LibraryFunctions::get_time_obj($this->get_start_time($session->get_timezone()), $session->get_timezone());	
			$end_time_obj = LibraryFunctions::get_time_obj($this->get_end_time($session->get_timezone()), $session->get_timezone());
			$settings = Globalvars::get_instance();
			$webDir = $settings->get_setting('webDir_SSL');	
			$cal_link = $webDir.'/profile/event_sessions?evt_event_id='.$this->get('evs_evt_event_id');
			$link = Link::create($this->get('evs_title'), $start_time_obj, $end_time_obj)
				->description($this->get('evs_title'))
				->address($cal_link);
				//->address('Kruikstraat 22, 2018 Antwerpen');
			$calendar_links['google'] =  $link->google();
			$calendar_links['yahoo'] = $link->yahoo();
			$calendar_links['outlook'] = $link->webOutlook();
			$calendar_links['ics'] = $link->ics();	
		}	
		
		return $calendar_links;
	}
	
	public static $json_vars = array(
		'evs_event_id', 'evs_name', 'evs_description');





	function prepare() {
		if ($this->data === NULL) {
			throw new eventException('This request has no data.');
		}

		$this->check_field_constraints();
		
		//TODO MAKE SURE PRODUCT IS ATTACHED BEFORE REGISTRATION
		
		/*
		if (!$this->get('evs_travel_type')) {
			throw new DisplayableeventException('You must select a travel preference.');
		}

		if ($this->get('evs_expires_time') != $old_expiry->format(DATE_ATOM)) { 
			$this->set('evs_expiry_email_sent', FALSE);
		}
		*/
	}
	
	function record_analytic($user_id, $type=1){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$q = $dblink->prepare('INSERT INTO sev_session_analytics (sev_evs_event_session_id, sev_evt_event_id, sev_type, sev_usr_user_id, sev_time) VALUES (?, ?, ?, ?, ?)');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->bindValue(2, $this->get('evs_evt_event_id'), PDO::PARAM_INT);
		$q->bindValue(3, $type, PDO::PARAM_INT);
		$q->bindValue(4, $user_id, PDO::PARAM_INT);
		$q->bindValue(5, 'now()', PDO::PARAM_STR);		
		$q->execute();	
		
		return true;
	}

	function get_last_visited_session_id_for_user($user_id){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$q = $dblink->prepare('SELECT sev_evs_event_session_id FROM sev_session_analytics WHERE sev_usr_user_id=? AND sev_evt_event_id=? ORDER BY sev_evs_event_session_id DESC');
		$q->bindValue(1, $user_id, PDO::PARAM_INT);
		$q->bindValue(2, $this->get('evs_evt_event_id'), PDO::PARAM_INT);
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
		
		$results = $q->fetch();
		
		return $results->sev_evs_event_session_id;
	}
	
	function get_last_visited_time_for_user($user_id){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$q = $dblink->prepare('SELECT sev_time FROM sev_session_analytics WHERE sev_usr_user_id=? AND sev_evt_event_id=? AND sev_evs_event_session_id=? ORDER BY sev_time DESC');
		$q->bindValue(1, $user_id, PDO::PARAM_INT);
		$q->bindValue(2, $this->get('evs_evt_event_id'), PDO::PARAM_INT);
		$q->bindValue(3, $this->key, PDO::PARAM_INT);

		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
		
		$results = $q->fetch();
		
		if($results){
			return $results->sev_time;
		}
		else{
			return false;
		}
	}			
	
	function get_number_visits_for_user($user_id){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
	
		$q = $dblink->prepare('SELECT count(*) as totalcount FROM sev_session_analytics WHERE sev_usr_user_id=? AND sev_evt_event_id=? AND sev_evs_event_session_id=?');
		$q->bindValue(1, $user_id, PDO::PARAM_INT);
		$q->bindValue(2, $this->get('evs_evt_event_id'), PDO::PARAM_INT);
		$q->bindValue(3, $this->key, PDO::PARAM_INT);

		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);
		
		$results = $q->fetch();

		return $results->totalcount;

	}	
	
	
	function add_file($fil_file_id){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('SELECT esf_fil_file_id FROM esf_event_session_files WHERE esf_evs_event_session_id=? AND esf_fil_file_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->bindValue(2, $fil_file_id, PDO::PARAM_INT);
		$q->execute();
		
		$results = $q->fetchAll();
		
		if($results){
			//DON'T DO IT TWICE
			return false;
		}
		else{
			$q = $dblink->prepare('INSERT INTO esf_event_session_files (esf_evs_event_session_id, esf_fil_file_id) VALUES (?, ?)');
			$q->bindValue(1, $this->key, PDO::PARAM_INT);
			$q->bindValue(2, $fil_file_id, PDO::PARAM_INT);
			$q->execute();			
		}
		return true;
	}

	function remove_all_files(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM esf_event_session_files WHERE esf_evs_event_session_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$success = $q->execute();
		
		return $success;
	}
	
	function remove_file($fil_file_id){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		
		$q = $dblink->prepare('DELETE FROM esf_event_session_files WHERE esf_evs_event_session_id=? AND esf_fil_file_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->bindValue(2, $fil_file_id, PDO::PARAM_INT);
		$success = $q->execute();
		
		return $success;
	}	
	
	function get_files(){
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$q = $dblink->prepare('SELECT count(1) FROM esf_event_session_files WHERE esf_evs_event_session_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();	
		$counter = $q->fetch();
		if($counter[count] == 0){
			return false;
		}
		
		$q = $dblink->prepare('SELECT esf_fil_file_id FROM esf_event_session_files WHERE esf_evs_event_session_id=?');
		$q->bindValue(1, $this->key, PDO::PARAM_INT);
		$q->execute();
		
		$results = $q->fetchAll();

		$multilist = new MultiFile();
		foreach ($results as $result){
			$multilist_item = new File($result['esf_fil_file_id'], TRUE);	
			$multilist->add($multilist_item);
		}
		
		return $multilist;

		/*
		$file_list = array();
		foreach($results as $result) {
			$file_list[] = $result['esf_fil_file_id'];
		}
		return $file_list;
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

	
	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS evs_event_sessions_evs_event_session_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."evs_event_sessions" (
			  "evs_event_session_id" int4 NOT NULL DEFAULT nextval(\'evs_event_sessions_evs_event_session_id_seq\'::regclass),
			  "evs_evt_event_id" int4 NOT NULL,
			  "evs_content" text COLLATE "pg_catalog"."default",
			  "evs_video" text COLLATE "pg_catalog"."default",
			  "evs_start_time" timestamp(6),
			  "evs_start_time_local" timestamp(6),
			  "evs_end_time" timestamp(6),
			  "evs_end_time_local" timestamp(6),
			  "evs_links" text COLLATE "pg_catalog"."default",
			  "evs_picture_link" varchar(255) COLLATE "pg_catalog"."default",
			  "evs_is_public" bool,
			  "evs_order" int2,
			  "evs_vid_video_id" int4,
			  "evs_title" varchar(255) COLLATE "pg_catalog"."default",
			  "evs_session_number" int2,
			  "evs_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."evs_event_sessions" ADD CONSTRAINT "evs_event_sessions_pkey" PRIMARY KEY ("evs_event_session_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}


		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS esf_event_session_files_esf_event_session_file_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."esf_event_session_files" (
			  "esf_event_session_file_id" int4 NOT NULL DEFAULT nextval(\'esf_event_session_files_esf_event_session_file_id_seq\'::regclass),
			  "esf_evs_event_session_id" int4 NOT NULL,
			  "esf_fil_file_id" int4 NOT NULL
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."esf_event_session_files" ADD CONSTRAINT "esf_event_session_files_pkey" PRIMARY KEY ("esf_event_session_file_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}
		
		
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS sev_session_analytics_sev_session_analytic_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."sev_session_analytics" (
			  "sev_session_analytic_id" int4 NOT NULL DEFAULT nextval(\'sev_session_analytics_sev_session_analytic_id_seq\'::regclass),
			  "sev_usr_user_id" int4,
			  "sev_evt_event_id" int4,
			  "sev_evs_event_session_id" int4,
			  "sev_type" int2,
			  "sev_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."sev_session_analytics" ADD CONSTRAINT "sev_session_analytics_pkey" PRIMARY KEY ("sev_session_analytic_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}		
		
		//FOR FUTURE
		//ALTER TABLE table_name ADD COLUMN IF NOT EXISTS column_name INTEGER;
	}		

}

class MultiEventSessions extends SystemMultiBase {

	function get_sessions_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $session) {
			$event = new Event($session->get('evs_evt_event_id'), TRUE);
			$option_display = $event->get('evt_name').' - '.$session->get('evs_title'); 
			$items[$option_display] = $session->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}




	function _get_results($only_count=FALSE, $debug = false) {
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('event_id', $this->options)) {
			$where_clauses[] = 'evs_evt_event_id = ?';
			$bind_params[] = array($this->options['event_id'], PDO::PARAM_INT);
		}

		if (array_key_exists('session_number', $this->options)) {
			$where_clauses[] = 'evs_session_number = ?';
			$bind_params[] = array($this->options['session_number'], PDO::PARAM_INT);
		}
	
		if (array_key_exists('title_like', $this->options)) {
			$where_clauses[] = 'evs_title ILIKE ?';
			$bind_params[] = array('%'.$this->options['title_like'].'%', PDO::PARAM_STR);
		}		

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'evs_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	
		
		if (array_key_exists('future', $this->options)) {
			$where_clauses[] = 'evs_end_time > ?';
			$bind_params[] = array($this->options['future'], PDO::PARAM_STR);
		}

		if (array_key_exists('future_or_none', $this->options)) {
			$where_clauses[] = '(evs_end_time > now() OR evs_start_time IS NULL)';
		}

		if (array_key_exists('past', $this->options)) {
			$where_clauses[] = 'evs_end_time < ?';
			$bind_params[] = array($this->options['past'], PDO::PARAM_STR);
		}		
	
		if (array_key_exists('past_or_none', $this->options)) {
			$where_clauses[] = '(evs_end_time < now() OR evs_start_time IS NULL)';
		}	
		/*
		if (array_key_exists('expired', $this->options)) {
			$where_clauses[] = 'evs_expires_time ' . ($this->options['expired'] ? '<' : '>') . ' now()';
		}	
		*/		
		

		if (array_key_exists('public', $this->options)) {
			$where_clauses[] = 'evs_is_public = ' . ($this->options['public'] ? 'TRUE' : 'FALSE');
		}
		
			
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		if ($only_count) {
			$sql = 'SELECT COUNT(1) as count_all FROM evs_event_sessions
				' . $where_clause;
		} else {
			$sql = 'SELECT * FROM evs_event_sessions
				' . $where_clause . ' ORDER BY ';

			if ($this->order_by === NULL) {
				$sql .= 'evs_event_session_id DESC';
			} else {
				$sort_clauses = array();
				if (array_key_exists('evs_evt_event_id', $this->order_by)) {
					$sort_clauses[] = 'evs_evt_event_id ' . $this->order_by['evs_evt_event_id'];
				}
				
				if (array_key_exists('title', $this->order_by)) {
					$sort_clauses[] = 'evs_title ' . $this->order_by['title'];
				}
				
				if (array_key_exists('session_number', $this->order_by)) {
					$sort_clauses[] = 'evs_session_number ' . $this->order_by['session_number'];
				}						
				
				if (array_key_exists('start_time', $this->order_by)) {
					$sort_clauses[] = 'evs_start_time ' . $this->order_by['start_time'];
				}
				
				if (array_key_exists('end_time', $this->order_by)) {
					$sort_clauses[] = 'evs_end_time ' . $this->order_by['end_time'];
				}

				if (array_key_exists('session_number_then_title', $this->order_by)) {
					$sort_clauses[] = 'evs_session_number '. $this->order_by['session_number_then_title'].', evs_title '. $this->order_by['session_number_then_title'];
				}
				
				if (array_key_exists('time_then_session_number', $this->order_by)) {
					$sort_clauses[] = 'evs_start_time '. $this->order_by['time_then_session_number'].' , evs_session_number '. $this->order_by['time_then_session_number'];
				}							
				
				$sql .= implode(',', $sort_clauses);
			}
			$sql .= $this->generate_limit_and_offset();
		}

		try {
			$q = $dblink->prepare($sql);
			
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
		}
		catch(PDOException $e){
			$dbhelper->handle_query_error($e);
		}

		return $q;
	}

	function load($debug = false) {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new EventSession($row->evs_event_session_id);
			$child->load_from_data($row, array_keys(EventSession::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count_all;
	}
}

?>
