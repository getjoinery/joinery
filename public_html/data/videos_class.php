<?php
require_once(__DIR__ . '/../includes/PathHelper.php');
$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/includes/DbConnector.php');
require_once($siteDir . '/includes/FieldConstraints.php');
require_once($siteDir . '/includes/Globalvars.php');
require_once($siteDir . '/includes/LibraryFunctions.php');
require_once($siteDir . '/includes/SingleRowAccessor.php');
require_once($siteDir . '/includes/SystemClass.php');
require_once($siteDir . '/includes/Validator.php');

class VideoException extends SystemClassException {}

class Video extends SystemBase {
	public static $prefix = 'vid';
	public static $tablename = 'vid_videos';
	public static $pkey_column = 'vid_video_id';
	public static $permanent_delete_actions = array(
		'vid_video_id' => 'delete',	
		'evs_vid_video_id' => 'prevent',
	);  //OPTIONS ARE 'delete', 'null', 'skip', 'prevent', or a value to set to that value
	public static $url_namespace = 'video'; 
	
	public static $fields = array(
		'vid_video_id' => 'ID of the video',
		'vid_title' => 'Video Title',
		'vid_link' => 'Link to the video',
		'vid_description' => 'Description',
		'vid_usr_user_id' => 'User this video is associated with',
		'vid_source' => 'Website of video',
		'vid_video_number' => 'Website video identifier',
		'vid_create_time' => 'Time added',
		'vid_video_text'=>'Original code',
		'vid_version' => 'Code version for turnhere videos',
		'vid_delete_time' => 'Time of deletion',
		'vid_min_permission' => 'Permission level required to view video',
		'vid_grp_group_id' => 'Group with permission to see video',
		'vid_evt_event_id' => 'Event registrants with permission to see video',
		//'vid_is_listed' => 'Whether to list the video in indexes'
	);

	public static $field_specifications = array(
		'vid_video_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'vid_title' => array('type'=>'varchar(255)'),
		'vid_link' => array('type'=>'varchar(255)'),
		'vid_description' => array('type'=>'text'),
		'vid_usr_user_id' =>  array('type'=>'int4'),
		'vid_source' =>  array('type'=>'int2'),
		'vid_video_number' =>  array('type'=>'varchar(255)'),
		'vid_create_time' =>  array('type'=>'timestamp(6)'),
		'vid_video_text'=> array('type'=>'text'),
		'vid_version' =>  array('type'=>'int2'),
		'vid_delete_time' => array('type'=>'timestamp(6)'),
		'vid_min_permission' => array('type'=>'int2'),
		'vid_grp_group_id' => array('type'=>'int4'),
		'vid_evt_event_id' => array('type'=>'int4'),
		//'vid_is_listed' => array('type'=>'int2'),
	);
	
	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('vid_create_time' => 'now()');
	

	function get_embed($vidwidth = 560, $vidheight = 315) {
		if($this->get('vid_delete_time')){
			return FALSE;
		}
		
		if($this->get('vid_source') == 1) {
			$elink = 'http://www.youtube.com/v/';
			$link = '<iframe width="560" height="315" src="https://www.youtube.com/embed/'.$this->get('vid_video_number').'" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
			return $link;
			//return $this->get_swfobject_html($elink . $this->get('vid_video_number'), $vidwidth, $vidheight);
		} else if ($this->get('vid_source') == 2) {
			$elink = 'http://video.google.com/googleplayer.swf?docid=';
			return $this->get_swfobject_html($elink . $this->get('vid_video_number'), $vidwidth, $vidheight);
		} else if ($this->get('vid_source') == 3) {
			$elink = 'http://www.liveleak.com/e/';
			return $this->get_swfobject_html($elink . $this->get('vid_video_number'), $vidwidth, $vidheight);
		} else if ($this->get('vid_source') == 4) {
			//DEAL WITH THE TWO WAYS VIMEO STRUCTURES VIDEO EMBEDS
			if (preg_match('((\d+)/([A-Za-z0-9]+))', $this->get('vid_video_number'), $matches)) {
				$vid_video_number = $matches[1];
				$elink = 'https://vimeo.com/';
				$link = '<iframe src="https://player.vimeo.com/video/'.$matches[1].'?h='.$matches[2].'" width="'.$vidwidth.'" height="'.$vidheight.'" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>';
			} 
			else{
				$elink = 'https://vimeo.com/';
				$link = '<iframe src="https://player.vimeo.com/video/'.$this->get('vid_video_number').'" width="'.$vidwidth.'" height="'.$vidheight.'" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>';
			}
			return $link;
					
			/*
			$elink = 'http://vimeo.com/moogaloop.swf?clip_id=';
			return $this->get_swfobject_html($elink . $this->get('vid_video_number'), $vidwidth, $vidheight);
			*/
		}  else {
			return FALSE;
		}

		return $src;
	}


	
	function get_source() {
		if($this->get('vid_source') == 1){
			return 'Youtube';
		}
		else if($this->get('vid_source') == 2){
			return 'Google Video';
		}
		else if($this->get('vid_source') == 3){
			return 'Liveleak';
		}				
		else if($this->get('vid_source') == 4){
			return 'Vimeo';
		}
		else if($this->get('vid_source') == 5){
			return 'Blip';
		}		
	}
	
	static function extract_source_from_url($vid_url) {
		
		if (stripos($vid_url, 'youtube')) {
			$vid_source=1;
		}
		else if (stripos($vid_url, 'youtu.be')) {
			$vid_source=1;
		}		
		else if (stripos($vid_url, 'google')) {
			$vid_source=2;
		}
		else if (stripos($vid_url, 'liveleak')) {
			$vid_source=3;
		}	
		else if (stripos($vid_url, 'vimeo')) {
			$vid_source=4;
		}				
		else if (stripos($vid_url, 'blip')) {
			$vid_source=5;
		}			
		else {
			return FALSE;
		}	
		return $vid_source;	
	}

	static function extract_number_from_url($vid_source, $vid_url) {
		if ($vid_source==1) {
			if (preg_match('(src="http[s]?://www\.youtube\.com/v/([^"&]+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 
			else if (preg_match('(http[s]?://www\.youtube\.com/watch\?v=([^&]+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			}
			else if (preg_match('(http[s]?://www\.youtube\.com/user/(?:.+?)#p/(?:.+)/(?:.+)/([^/]+)$)', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 
			else if (preg_match('(http[s]?://www\.youtube\.com/embed/([^"&]+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 	
			else if (preg_match('(http[s]?://[www\.]?youtu\.be/([^"&]+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 				
			else {
				return FALSE;
			}
		}
		else if ($vid_source==2) {
			if (preg_match('/docid=([^&#]*)/', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 
			else {
				return FALSE;
			}
		}		
		else if ($vid_source==3) {
			if (preg_match('(src="http[s]?://www\.liveleak\.com/e/([^"&]+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 
			else if (preg_match('(http[s]?://www\.liveleak\.com/view\?i=([^"&]+)(&|$))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			}
			else {
				return FALSE;
			}
		}			
		else if ($vid_source==4) {
			if (preg_match('(src="http[s]?://vimeo.com/moogaloop.swf\?clip_id=(\d+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			} 
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)(&|$|"))', $vid_url, $matches)) {
				//vimeo.com/387usd8
				$vid_video_number = $matches[1];
			}
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)\?(.*))', $vid_url, $matches)) {
				//vimeo.com/387usd8?share=copy
				$vid_video_number = $matches[1];
			}
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)/([A-Za-z0-9]+)(&|$|"))', $vid_url, $matches)) {
				//vimeo.com/387usd8/3874ruso
				$vid_video_number = $matches[1] . '/' . $matches[2];
			}
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)/([A-Za-z0-9]+)\?(.*))', $vid_url, $matches)) {
				//vimeo.com/387usd8/3874ruso?share=copy
				$vid_video_number = $matches[1] . '/' . $matches[2];
			}
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/manage/videos/(\d+)/([A-Za-z0-9]+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1] . '/' . $matches[2];
			}
			/*
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)/(\w+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			}
			*/
			else {
				return FALSE;
			}
		}
		else if ($vid_source==5) {
			return FALSE;
		}						
		else {
			return FALSE;
		}	
		return $vid_video_number;	
	}	
	
	
	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			// If the user's ID doesn't match, we have to make
			// sure they have admin access, otherwise denied.
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}
	
	function authenticate_read($data=NULL){
		
		if(isset($data['session'])){
			$session = $data['session'];
		}
		else{
			SystemDisplayablePermanentError("Session is not present to authenticate.");
		}
		
		if($this->get('vid_delete_time')){
			return false;
		}

		//DISALLOW IF MIN PERMISSION AND USER IS NOT LOGGED IN OR DOESNT HAVE PERMISSION
		if($this->get('vid_min_permission')){
			if (!$session->get_permission()) {
				return false;
			}
			if ($session->get_permission() < $this->get('vid_min_permission')){
				return false;
			}
	
		}	
	
		if ($group_id = $this->get('vid_grp_group_id')){
			PathHelper::requireOnce('data/groups_class.php');
			//CHECK TO SEE IF USER IS IN AUTHORIZED GROUP
			$group = new Group($group_id, TRUE);
			if(!$group->is_member_in_group($session->get_user_id())){
				return false;
			}
		}
		
		if ($event_id = $this->get('vid_evt_event_id')){
			PathHelper::requireOnce('data/event_registrants_class.php');
			//CHECK TO SEE IF USER IS IN AUTHORIZED EVENT
			$searches['user_id'] = $session->get_user_id();
			$searches['event_id'] = $event_id;
			$searches['expired'] = false;
			$event_registrations = new MultiEventRegistrant(
				$searches,
				NULL, //array('event_id'=>'DESC'),
				NULL,
				NULL);
			$numeventsregistrations = $event_registrations->count_all();	

			if(!$numeventsregistrations){
				return false;
			}
		}

		return true;
	}

}

class MultiVideo extends SystemMultiBase {


	function get_video_dropdown_array($include_new=FALSE) {
		$items = array();
		foreach($this as $video) {
			$items['('.$video->key.') '.$video->get('vid_title')] = $video->key;
		}
		if ($include_new) {
			$items['new'] = 'Enter New Below';
		}
		return $items;

	}
	
	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['vid_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['group_id'])) {
            $filters['vid_grp_group_id'] = [$this->options['group_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['event_id'])) {
            $filters['vid_evt_event_id'] = [$this->options['event_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['link'])) {
            $filters['vid_link'] = [$this->options['link'], PDO::PARAM_STR];
        }

        if (isset($this->options['deleted'])) {
            $filters['vid_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
        }

        if (isset($this->options['source'])) {
            $filters['vid_source'] = [$this->options['source'], PDO::PARAM_INT];
        }

        return $this->_get_resultsv2('vid_videos', $filters, $this->order_by, $only_count, $debug);
    }

	function load($debug = false) {
		parent::load();
		$q = $this->getMultiResults(false, $debug);
		foreach($q->fetchAll() as $row) {
			$child = new Video($row->vid_video_id);
			$child->load_from_data($row, array_keys(Video::$fields));
			$this->add($child);
		}
	}
	
	function count_all($debug = false) {
		$q = $this->getMultiResults(TRUE, $debug);
		return $q;
	}

}


?>
