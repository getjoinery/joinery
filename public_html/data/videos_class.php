<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FieldConstraints.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SingleRowAccessor.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SystemClass.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Validator.php');

class VideoException extends SystemClassException {}

class Video extends SystemBase {

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

	public static $required_fields = array();
	
	public static $field_constraints = array();
	
	public static $zero_variables = array();
	
	public static $initial_default_values = array('vid_create_time' => 'now()');

	function load() {
		parent::load();
		$this->data = SingleRowFetch('vid_videos', 'vid_video_id',
			$this->key, PDO::PARAM_INT, SINGLE_ROW_ALL_COLUMNS);
		if ($this->data === NULL) {
			throw new VideoException(
				'This video does not exist');
		}
	}
	

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
			$elink = 'https://vimeo.com/';
			$link = '<iframe src="https://player.vimeo.com/video/'.$this->get('vid_video_number').'" width="'.$vidwidth.'" height="'.$vidheight.'" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>';
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
			else if (preg_match('(http[s]?://[www\.]?vimeo\.com/(\d+)/(\w+)(&|$|"))', $vid_url, $matches)) {
				$vid_video_number = $matches[1];
			}
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

	function save() {
		parent::save();
		$rowdata = array();
		foreach(array_keys(self::$fields) as $field) {
			$rowdata[$field] = $this->get($field);
		}

		if ($this->key) {
			$p_keys = array('vid_video_id' => $this->key);
			// Editing an existing 
		} else {
			$p_keys = NULL;
			// Creating a new 
			unset($rowdata['vid_video_id']);
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$p_keys_return = LibraryFunctions::edit_table(
			$dbhelper, $dblink, 'vid_videos', $p_keys, $rowdata, FALSE, 0);

		$this->key = $p_keys_return['vid_video_id'];
	}
		
	function soft_delete(){
		$this->set('vid_delete_time', 'now()');
		$this->save();
		return true;
	}
	
	function undelete(){
		$this->set('vid_delete_time', NULL);
		$this->save();	
		return true;
	}
	

	function permanent_delete() {
		
		$dbhelper = DbConnector::get_instance(); 
		$dblink = $dbhelper->get_db_link();

		$this_transaction = false;
		/*
		if(!$dblink->inTransaction()){
			$dblink->beginTransaction();
			$this_transaction = true;
		}
		*/

		$sql = 'DELETE FROM vid_videos WHERE vid_video_id=:vid_video_id';

		try{
			$q = $dblink->prepare($sql);
			$q->bindParam(':vid_video_id', $this->key, PDO::PARAM_INT);
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

		return TRUE;
		
	}	

	static function InitDB($mode='structure'){
	
		try{
			$sql = '
				CREATE SEQUENCE IF NOT EXISTS vid_videos_vid_video_id_seq
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
			CREATE TABLE IF NOT EXISTS "public"."vid_videos" (
			  "vid_video_id" int4 NOT NULL DEFAULT nextval(\'vid_videos_vid_video_id_seq\'::regclass),
			  "vid_source" int2,
			  "vid_video_number" varchar(255) COLLATE "pg_catalog"."default",
			  "vid_create_time" timestamp(6) DEFAULT now(),
			  "vid_usr_user_id" int4,
			  "vid_video_text" text COLLATE "pg_catalog"."default",
			  "vid_version" int2,
			  "vid_title" varchar(255) COLLATE "pg_catalog"."default",
			  "vid_description" text COLLATE "pg_catalog"."default",
			  "vid_delete_time" timestamp(6)
			)
			;';
		$q = $dblink->prepare($sql);
		$success = $q->execute();
		
		try{		
			$sql = 'ALTER TABLE "public"."vid_videos" ADD CONSTRAINT "vid_videos_pkey" PRIMARY KEY ("vid_video_id");';
			$q = $dblink->prepare($sql);
			$success = $q->execute();
		}
		catch  (Exception $e){
			//SKIP
		}

		try{		
			$sql = 'COMMENT ON COLUMN "public"."vid_videos"."vid_source" IS \'1 - youtube
			2 - google vid
			3 - liveleak
			4 - vimeo
			5 - blip\';';
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

private function _get_results($only_count=FALSE) { 
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
			$child = new Video($row->vid_video_id);
			$child->load_from_data($row, array_keys(Video::$fields));
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
