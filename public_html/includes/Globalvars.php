<?php

class Globalvars {
	private static $instance_map = array();
	private $settings;

	private function __construct() {
		require_once(dirname(__DIR__, 2).'/config/Globalvars_site.php');		
	}

	public static function get_instance(){
		$doc_root = $_SERVER['DOCUMENT_ROOT'];
		// Check to see if we have a global vars instance for the particular document root
		// this is being read from.
		if (!array_key_exists($doc_root, self::$instance_map)) {
			// If not, create the new one and add it to the array
			self::$instance_map[$doc_root] = new self;
		}
		return self::$instance_map[$doc_root];
	}

	public function get_setting($setting, $calculated_values=true){
		$found = 0;
		if(isset($this->settings[$setting])){
			if($this->settings[$setting] || $this->settings[$setting] === 0){
				//FOUND A SETTING THAT IS NOT BLANK
				$found = 1;
				return $this->settings[$setting];
			}
			else{
				//FOUND A SETTING THAT IS BLANK
				$found = 1;
			}
		}
		
		require_once('DbConnector.php');
		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();
		$sql = 'SELECT stg_value FROM stg_settings WHERE stg_name = :stg_name';
		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':stg_name', $setting);
			$q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} 
		catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}
		$result = $q->fetch();
		
		if(isset($result->stg_value)){
			$found = 1;
			$setting_value = $result->stg_value;
			if($setting_value || $setting_value === 0){
				//FOUND A SETTING THAT IS NOT BLANK
				$this->settings[$setting] = $setting_value;
				return $setting_value;
			}
			else{
				//FOUND A SETTING THAT IS BLANK
			}
		}
		else{
			//SKIP AHEAD, DIDN'T FIND ANYTHING IN THE DATABASE
		}
		
		if($calculated_values){
			//SPECIAL CASES OF DEFAULT CALCULATED SETTINGS.  USE THESE VALUES IF THEY ARE NOT SET IN THE CONFIG FILE OR DB
			if($setting == 'siteDir'){
				return $this->get_setting('baseDir') . $this->get_setting('site_template'). '/public_html';  
			}
			else if($setting == 'upload_dir'){
				return $this->get_setting('baseDir') . $this->get_setting('site_template'). '/uploads';  
			}
			else if($setting == 'upload_web_dir'){
				return 'uploads'; 
			}
			else if($setting == 'static_files_dir'){
				return $this->get_setting('baseDir') . $this->get_setting('site_template'). '/static_files'; 
			}
		}	
		
		if(!$found){
			throw new Exception('Setting '.$setting.' does not exist.');
			exit;	
		}
		else{
			return NULL;
		}
	
	}
}

?>
