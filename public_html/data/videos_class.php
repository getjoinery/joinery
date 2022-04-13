<?php
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

	public static $fields = array(
		'vid_video_id' => 'ID of the video',
		'vid_title' => 'Video Title',
		'vid_description' => 'Description',
		'vid_usr_user_id' => 'User this video is associated with',
		'vid_source' => 'Website of video',
		'vid_video_number' => 'Website video identifier',
		'vid_create_time' => 'Time added',
		'vid_video_text'=>'Original code',
		'vid_version' => 'Code version for turnhere videos',
		'vid_delete_time' => 'Time of deletion',
	);

	public static $field_specifications = array(
		'vid_video_id' => array('type'=>'int8', 'serial'=>true, 'is_nullable'=>false),
		'vid_title' => array('type'=>'varchar(255)'),
		'vid_description' => array('type'=>'text'),
		'vid_usr_user_id' =>  array('type'=>'int4'),
		'vid_source' =>  array('type'=>'int2'),
		'vid_video_number' =>  array('type'=>'varchar(255)'),
		'vid_create_time' =>  array('type'=>'timestamp(6)'),
		'vid_video_text'=> array('type'=>'text'),
		'vid_version' =>  array('type'=>'int2'),
		'vid_delete_time' => array('type'=>'timestamp(6)'),
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

	/*
	function get_swfobject_html($src_link, $vidwidth, $vidheight) { 
		return '<script type="text/javascript">swfobject.registerObject("vid_' . $this->key . '", "9.0.115", "/theme/flash/expressInstall.swf");</script>
			<object id="vid_' . $this->key . '" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" width="' .  $vidwidth . '" height="' . $vidheight . '">
			<param name="movie" value="' . $src_link . '" />
			<!--[if !IE]>-->
			<object type="application/x-shockwave-flash" data="' . $src_link . '" width="' .  $vidwidth . '" height="' . $vidheight . '">
			<!--<![endif]-->
			<p>Flash is required to view this video. <a href="http://get.adobe.com/flashplayer/">Get Flash</a>.</p>
			<!--[if !IE]>-->
			</object>
			<!--<![endif]-->
			</object>';
	}
	*/
	
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
				$vid_video_number = $matches[1];
			}
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)/([A-Za-z0-9]+)(&|$|"))', $vid_url, $matches)) {
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
	
	
	function authenticate_write($session, $other_data=NULL) {
		$current_user = $session->get_user_id();
		if ($this->get('vid_usr_user_id') != $current_user) {
			// If the user's ID doesn't match , we have to make
			// sure they have admin access, otherwise denied.
			if ($session->get_permission() < 5) {
				throw new SystemAuthenticationError(
					'Current user does not have permission to edit this video.');
			}
		}
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

function _get_results($only_count=FALSE, $debug = false) { 
		$where_clauses = array();
		$bind_params = array();

		if (array_key_exists('user_id', $this->options)) {
		 	$where_clauses[] = 'vid_usr_user_id = ?';
		 	$bind_params[] = array($this->options['user_id'], PDO::PARAM_INT);
		} 

		if (array_key_exists('deleted', $this->options)) {
			$where_clauses[] = 'vid_delete_time IS ' . ($this->options['deleted'] ? 'NOT NULL' : 'NULL');
		}	

		if (array_key_exists('source', $this->options)) {
		 	$where_clauses[] = 'vid_source = ?';
		 	$bind_params[] = array($this->options['source'], PDO::PARAM_INT);
		} 	
				
		
		if ($where_clauses) {
			$where_clause = 'WHERE ' . implode(' '.$this->operation.' ', $where_clauses) . ' ';
		} else {
			$where_clause = '';
		}

		if ($only_count) {
			$sql = 'SELECT COUNT(1) FROM vid_videos ' . $where_clause;
		} 
		else {
			$sql = 'SELECT * FROM vid_videos
				' . $where_clause . '
				ORDER BY ';

			if (!$this->order_by) {
				$sql .= " vid_video_id ASC ";
			}
			else {
				if (array_key_exists('video_id', $this->order_by)) {
					$sql .= ' vid_video_id ' . $this->order_by['video_id'];
				}			
			}
			
			$sql .= ' '.$this->generate_limit_and_offset();	
		}

		$q = DbConnector::GetPreparedStatement($sql);

		if($debug){
			echo $sql. "<br>\n";
			print_r($this->options);
		}

		$total_params = count($bind_params);
		for ($i=0; $i<$total_params; $i++) {
			list($param, $type) = $bind_params[$i];
			$q->bindValue($i+1, $param, $type);
		}
		$q->execute();
		$q->setFetchMode(PDO::FETCH_OBJ);

		return $q;
	}

	function load($debug = false) {
		$q = $this->_get_results();
		foreach($q->fetchAll() as $row) {
			$child = new Video($row->vid_video_id);
			$child->load_from_data($row, array_keys(Video::$fields));
			$this->add($child);
		}
	}

	function count_all($debug = false) {
		$q = $this->_get_results(TRUE);
		$counter = $q->fetch();
		return $counter->count;
	}
	
}


?>
