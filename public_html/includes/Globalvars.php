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

	public function get_setting($setting){
		if($this->settings[$setting]){
			return $this->settings[$setting];
		}
		else{
			require_once($this->settings['siteDir'] . '/data/settings_class.php');
			$search_criteria['setting_name'] = $setting;
			$user_settings = new MultiSetting(
			$search_criteria,
			NULL,
			NULL,
			NULL,
			NULL);
			$found = $user_settings->count_all();

			if($found){
				$user_settings->load();
				$this_setting = $user_settings->get(0);
				return $this_setting->get('stg_value');
			}
			else{
				return false;
			}
		}
	}
}

?>
