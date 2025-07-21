<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

PathHelper::requireOnce('includes/DbConnector.php');
PathHelper::requireOnce('includes/FieldConstraints.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/SessionControl.php');
PathHelper::requireOnce('includes/SingleRowAccessor.php');
PathHelper::requireOnce('includes/SystemClass.php');

PathHelper::requireOnce('data/event_registrants_class.php');
PathHelper::requireOnce('data/event_session_files_class.php');
PathHelper::requireOnce('data/session_analytics_class.php');

PathHelper::requireOnce('includes/calendar-links/Link.php');
PathHelper::requireOnce('includes/calendar-links/Generator.php');
PathHelper::requireOnce('includes/calendar-links/Generators/Google.php');
PathHelper::requireOnce('includes/calendar-links/Generators/Ics.php');
PathHelper::requireOnce('includes/calendar-links/Generators/Yahoo.php');
PathHelper::requireOnce('includes/calendar-links/Generators/WebOutlook.php');
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

class EventSession extends SystemBase {
	public static $prefix = 'evs';
	public static $tablename = 'evs_event_sessions';
	public static $pkey_column = 'evs_event_session_id';
	public static $permanent_delete_actions = array(
		'evs_event_session_id' => 'delete',	
		'sev_evs_event_session_id' => 'delete',
		'esf_evs_event_session_id' => 'delete',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value

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

	public static $field_specifications = array(
		'evs_event_session_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'evs_evt_event_id' => array('type'=>'int4'),
		'evs_title' => array('type'=>'varchar(255)'),
		'evs_content' => array('type'=>'text'),
		'evs_start_time' => array('type'=>'timestamp(6)'),
		'evs_start_time_local' => array('type'=>'timestamp(6)'),
		'evs_end_time' => array('type'=>'timestamp(6)'),
		'evs_end_time_local' => array('type'=>'timestamp(6)'),
		'evs_links' => array('type'=>'text'),
		'evs_picture_link' => array('type'=>'varchar(255)'),
		'evs_is_public' => array('type'=>'bool'),
		'evs_order' => array('type'=>'int2'),
		'evs_vid_video_id' => array('type'=>'int4'),
		'evs_session_number' => array('type'=>'int2'),
		'evs_delete_time' => array('type'=>'timestamp(6)'),
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


	public static function GetBySessionNumber($event_id, $session_number){
		$results = new MultiEventSessions(array('event_id' => $event_id, 'session_number' => $session_number));
		$results->load();

		if(count($results)){	
			return $results->get(0);	
		}
		else{
			return false;
		}		
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
			$cal_link = LibraryFunctions::get_absolute_url('/profile/event_sessions?evt_event_id='.$this->get('evs_evt_event_id'));
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
		if($counter['count'] == 0){
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


	function authenticate_write($data) {
		if ($data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
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




	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        
        if (isset($this->options['event_id'])) {
            $filters['evs_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['session_number'])) {
            $filters['evs_session_number'] = [$this->options['session_number'], PDO::PARAM_INT];
        }
        
        if (isset($this->options['title_like'])) {
            $filters['evs_title'] = 'ILIKE \'%'.$this->options['title_like'].'%\'';
        }
        
        if (isset($this->options['deleted'])) {
            $filters['evs_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }
        
        if (isset($this->options['future'])) {
            $filters['evs_end_time'] = '> \''.$this->options['future'].'\'';
        }
        
        if (isset($this->options['future_or_none'])) {
            $filters['evs_end_time'] = '> now() OR evs_start_time IS NULL';
        }
        
        if (isset($this->options['past'])) {
            $filters['evs_end_time'] = '< \''.$this->options['past'].'\'';
        }
        
        if (isset($this->options['past_or_none'])) {
            $filters['evs_end_time'] = '< now() OR evs_start_time IS NULL';
        }
        
        if (isset($this->options['public'])) {
            $filters['evs_is_public'] = $this->options['public'] ? "= TRUE" : "= FALSE";
        }
        
        return $this->_get_resultsv2('evs_event_sessions', $filters, $this->order_by, $only_count, $debug);
    }
    
    function load($debug = false) {
        parent::load();
        $q = $this->getMultiResults(false, $debug);
        foreach($q->fetchAll() as $row) {
            $child = new EventSession($row->evs_event_session_id);
            $child->load_from_data($row, array_keys(EventSession::$fields));
            $this->add($child);
        }
    }
    
    function count_all($debug = false) {
        $q = $this->getMultiResults(TRUE, $debug);
        return $q;
    }

}

?>
