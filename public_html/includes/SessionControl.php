<?php
/**************************************
CHECKS FOR THE NEEDED PERMISSIONS TO VIEW THE PAGE AND REDIRECTS
TO A LOGIN PAGE IF NOT
***************************************/
require_once ('PathHelper.php');
require_once ('DbConnector.php');
require_once ('LibraryFunctions.php');
require_once ('ShoppingCart.php');

PathHelper::requireOnce('data/login_class.php');

class DisplayMessage {

	const MESSAGE_ANNOUNCEMENT = 1;
	const MESSAGE_WARNING = 2;
	const MESSAGE_ERROR = 3;

	const MESSAGE_DISPLAY_GLOBAL = 1;
	const MESSAGE_DISPLAY_IN_PAGE = 2;

	public $message; // message text
	public $message_title; // message text
	public $page_regex;  // NULL for any,	DEFAULT NULL
	public $display_type; // MESSAGE_ANNOUNCEMENT, MESSAGE_WARNING, MESSAGE_ERROR, DEFAULT ANNOUNCEMENT
	public $display_location; // MESSAGE_DISPLAY_GLOBAL, MESSAGE_DISPLAY_IN_PAGE, DEFAULT IN PAGE
	public $identifier; // OPTIONAL, FOR INDICATING WHERE THE ERROR IS TO DISPLAY ON THE PAGE, DEFAULT NULL
	public $clearable; // OPTIONAL,(T/F), DEFAULT TRUE

	function __construct($message, $message_title, $page_regex=NULL, $display_type=DisplayMessage::MESSAGE_ANNOUNCEMENT, $display_location=DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, $identifier=NULL, $clearable=TRUE) {
		$this->message = $message;
		$this->message_title = $message_title;
		$this->page_regex = $page_regex;
		$this->display_type = $display_type;
		$this->display_location = $display_location;
		$this->identifier = $identifier;
		$this->clearable = $clearable;
	}

	function get_message_class() {
		if($this->display_type == DisplayMessage::MESSAGE_ANNOUNCEMENT) {
			return 'success';
		} else if($this->display_type == DisplayMessage::MESSAGE_WARNING) {
			return 'warn';
		} else if($this->display_type == DisplayMessage::MESSAGE_ERROR) {
			return 'error';
		}
	}
}

class SessionControl{

	private static $instance;
	var $currpermissioncheck;

	private function __construct(){
		session_start();
		if(!isset($_SESSION['saved_messages'])) {
			$_SESSION['saved_messages'] = array();
		}
		
		$this->get_uniqid();

		if (isset($_SESSION['loggedin']) && $_SESSION['loggedin']) {
			// If the user is logged in, don't do anything else
			return;
		} else {
			// If not, try to pull their info from a cookie
			$this->get_user_from_cookie();
		}
	}
	
	public function get_uniqid(){
		if(!isset($_SESSION['uniqid']) || !$_SESSION['uniqid']){
			$_SESSION['uniqid'] = uniqid();
		}	
		return $_SESSION['uniqid'];
	}

	public function send_emails() {
		return !isset($_SESSION['send_emails']) || $_SESSION['send_emails'];
	}

	public function save_user_to_cookie() {
		/*
		//MAXIMUM SECURITY
		if ($this->get_user_id()) {
			$ip = explode('.', $_SERVER['REMOTE_ADDR']);
			// Lets store the first segment of their IP, so we can only allow people from the same
			// segment to log back in from a "Remember me" (which for 99% of people will work, and
			// prevent a lot of cookie stealing attacks)
			$first_segment = $ip[0];
			// This remember me cookie only lasts for 90 days
			$expire_time = time() + (90 * 24 * 60 * 60);
			setcookie(
				'tt',
				implode(';',
					array(
						LibraryFunctions::Encode($this->get_user_id(), 'user_id'),
						LibraryFunctions::Encode($first_segment, 'ip_address'),
						LibraryFunctions::Encode($expire_time, 'expiration_date'),
						sha1(
							$this->get_user_id() . $first_segment . $expire_time .
							'Ifz4lU5Bmwmbi17f2W4CW1I3XKrJmrWmc19bDAUBMNqyPVDEBfvBLUHQqxCk261')
						)),
				$expire_time);
		}
		*/
		//MEDIUM SECURITY
		if ($this->get_user_id()) {
			// This remember me cookie lasts for 365 days
			$expire_time = time() + (365 * 24 * 60 * 60);
			setcookie(
				'tt',
				implode(';',
					array(
						LibraryFunctions::Encode($this->get_user_id(), 'user_id'),
						LibraryFunctions::Encode($expire_time, 'expiration_date'),
						sha1(
							$this->get_user_id() . $expire_time .
							'Ifz4lU5Bmwmbi17f2W4CW1I3XKrJmrWmc19bDAUBMNqyPVDEBfvBLUHQqxCk261')
						)),
				$expire_time);
		}		
	}

	public function save_session_item($key, $value) {
		$_SESSION['temporary_storage'][$key] = $value;
	}

	public function get_saved_item($key) {
		if (isset($_SESSION['temporary_storage'][$key])) {
			return $_SESSION['temporary_storage'][$key];
		}
		return array();
	}

	public function get_shopping_cart() {
		if (!isset($_SESSION['shopping_cart'])) {
			$_SESSION['shopping_cart'] = new ShoppingCart();
		}
		return $_SESSION['shopping_cart'];
	}
	

	static function getOS() { 

		$user_agent = $_SERVER['HTTP_USER_AGENT'];

		$os_platform  = "Unknown OS Platform";

		$os_array     = array(
							  '/windows nt 10/i'      =>  'Windows 10',
							  '/windows nt 6.3/i'     =>  'Windows 8.1',
							  '/windows nt 6.2/i'     =>  'Windows 8',
							  '/windows nt 6.1/i'     =>  'Windows 7',
							  '/windows nt 6.0/i'     =>  'Windows Vista',
							  '/windows nt 5.2/i'     =>  'Windows Server 2003/XP x64',
							  '/windows nt 5.1/i'     =>  'Windows XP',
							  '/windows xp/i'         =>  'Windows XP',
							  '/windows nt 5.0/i'     =>  'Windows 2000',
							  '/windows me/i'         =>  'Windows ME',
							  '/win98/i'              =>  'Windows 98',
							  '/win95/i'              =>  'Windows 95',
							  '/win16/i'              =>  'Windows 3.11',
							  '/macintosh|mac os x/i' =>  'Mac OS X',
							  '/mac_powerpc/i'        =>  'Mac OS 9',
							  '/linux/i'              =>  'Linux',
							  '/ubuntu/i'             =>  'Ubuntu',
							  '/iphone/i'             =>  'iPhone',
							  '/ipod/i'               =>  'iPod',
							  '/ipad/i'               =>  'iPad',
							  '/android/i'            =>  'Android',
							  '/blackberry/i'         =>  'BlackBerry',
							  '/webos/i'              =>  'Mobile'
						);

		foreach ($os_array as $regex => $value)
			if (preg_match($regex, $user_agent))
				$os_platform = $value;

		return $os_platform;
	}	
	
	static function getBrowser() {

		$user_agent = $_SERVER['HTTP_USER_AGENT'];

		$browser        = "Unknown Browser";

		$browser_array = array(
								'/msie/i'      => 'Internet Explorer',
								'/firefox/i'   => 'Firefox',
								'/safari/i'    => 'Safari',
								'/chrome/i'    => 'Chrome',
								'/edge/i'      => 'Edge',
								'/opera/i'     => 'Opera',
								'/netscape/i'  => 'Netscape',
								'/maxthon/i'   => 'Maxthon',
								'/konqueror/i' => 'Konqueror',
								'/mobile/i'    => 'Handheld Browser'
						 );

		foreach ($browser_array as $regex => $value)
			if (preg_match($regex, $user_agent))
				$browser = $value;

		return $browser;
	}	
	
	
	public function crawlerDetect($USER_AGENT){
		$crawlers = array(
			  'Google' => 'Google',
			  'MSN' => 'msnbot',
			  'Rambler' => 'Rambler',
			  'Yahoo' => 'Yahoo',
			  'Bing' => 'Bing',
			  'AbachoBOT' => 'AbachoBOT',
			  'accoona' => 'Accoona',
			  'AcoiRobot' => 'AcoiRobot',
			  'ASPSeek' => 'ASPSeek',
			  'CrocCrawler' => 'CrocCrawler',
			  'Dumbot' => 'Dumbot',
			  'FAST-WebCrawler' => 'FAST-WebCrawler',
			  'GeonaBot' => 'GeonaBot',
			  'Gigabot' => 'Gigabot',
			  'Lycos spider' => 'Lycos',
			  'MSRBOT' => 'MSRBOT',
			  'Altavista robot' => 'Scooter',
			  'AltaVista robot' => 'Altavista',
			  'ID-Search Bot' => 'IDBot',
			  'eStyle Bot' => 'eStyle',
			  'Scrubby robot' => 'Scrubby',
			  'Baidu' => 'Baidu',
			  'Facebook' => 'facebookexternalhit',
		  );

		   $crawlers_agents = implode('|',$crawlers);
		  if (strpos($crawlers_agents, $USER_AGENT) === false)
			  return false;
			else {
				return TRUE;
		  }
	}
	
	public static function is_valid_page($page){
		$page_parts = pathinfo($page);
		
		switch($page_parts['extension']){
			
			case 'gif':
			return false;

			case 'jpg':
			return false;

			case 'jpeg':
			return false;

			case 'png':
			return false;

			case 'css':
			return false;

			case 'js':
			return false;		

			case 'js':
			return false;

			case 'xml':
			return false;

			case 'ico':
			return false;
		
		}
		
		return true;
	}	
	
	
	//TYPES:  1= WEB HIT,
	public function save_visitor_event($type=1, $is_404=FALSE){
		if(!$_SESSION['uniqid']){
			$_SESSION['uniqid'] = uniqid();
		}
		
		//IF A CRAWLER EXIT
		if($this->crawlerDetect($_SERVER["HTTP_USER_AGENT"])){
			return false;
		}
		
		//TURN OFF 404 PAGES
		if($is_404){
			return false;
		}		
		/*
		if(!SessionControl::is_valid_page($_SERVER["REQUEST_URI"])){
			return false;
		}
		
		if (!filter_var('http://fillerurl.com'.$_SERVER["REQUEST_URI"], FILTER_VALIDATE_URL)) {
			return false;
		}

		//REMOVE INVALID URL
		$page = strtok($_SERVER["REQUEST_URI"],'?');
		if($page == '/api'){
			return false;
		}
		
		//REMOVE UNSAFE ENCODING
		if(strpos($_SERVER["REQUEST_URI"], '%')){
			return false;
		}
		*/
		
		//DROP URLS THAT ARE TOO LONG
		if(strlen($_SERVER["REQUEST_URI"]) > 254){
			return false;
		}	
		
		
		$source = NULL;
		$campaign = NULL;
		$medium = NULL;
		$content = NULL;
		if($_SERVER['QUERY_STRING']){
			parse_str($_SERVER['QUERY_STRING'], $qvars); 
			foreach ($qvars as $qvar=>$qval){
				if($qvar == 'vs' || $qvar == 'utm_source'){
					$source = $qval;
				}
				else if($qvar == 'vc' || $qvar == 'utm_campaign'){
					$campaign = $qval;
				}
				else if($qvar == 'vm' || $qvar == 'utm_medium'){
					$medium = $qval;
				}
				else if($qvar == 'vt' || $qvar == 'utm_content'){
					$content = $qval;
				}				
			}
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'INSERT INTO vse_visitor_events (vse_visitor_id, vse_usr_user_id, vse_type, vse_ip, vse_page, vse_referrer, vse_source, vse_campaign, vse_medium, vse_content, vse_is_404) 
		VALUES (:vse_visitor_id, :vse_usr_user_id, :vse_type, :vse_ip, :vse_page, :vse_referrer, :vse_source, :vse_campaign, :vse_medium, :vse_content, :vse_is_404)';

		$referer = '';
		if(isset($_SESSION['HTTP_REFERER'])){
			$referer = $_SESSION['HTTP_REFERER'];
		}

		try{
			$q = $dblink->prepare($sql);
			$q->bindValue(':vse_visitor_id', $_SESSION['uniqid'], PDO::PARAM_STR);
			$q->bindValue(':vse_usr_user_id', $this->get_user_id(), PDO::PARAM_INT);
			$q->bindValue(':vse_type', $type, PDO::PARAM_INT);
			$q->bindValue(':vse_ip', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
			$q->bindValue(':vse_page', strtok($_SERVER["REQUEST_URI"],'?'), PDO::PARAM_STR);
			$q->bindValue(':vse_referrer', $referer, PDO::PARAM_STR);
			$q->bindValue(':vse_source', $source, PDO::PARAM_STR);
			$q->bindValue(':vse_campaign', $campaign, PDO::PARAM_STR);
			$q->bindValue(':vse_medium', $source, PDO::PARAM_STR);
			$q->bindValue(':vse_content', $campaign, PDO::PARAM_STR);
			$q->bindValue(':vse_is_404', $is_404, PDO::PARAM_INT);		
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}		
		
		
	}

	public function get_user_from_cookie() {
		if (!empty($_COOKIE['tt'])) {
			$cookie_contents = explode(';', $_COOKIE['tt']);
			if (count($cookie_contents) == 4) {
				//MAXIMUM SECURITY
				list($user_hash, $ip_segment_hash, $expire_time_hash, $hash) = explode(';', $_COOKIE['tt']);
				$user = LibraryFunctions::decode($user_hash, 'user_id');
				$ip_segment = LibraryFunctions::decode($ip_segment_hash, 'ip_address');
				$expire_time = LibraryFunctions::decode($expire_time_hash, 'expiration_date');
				

				$ip = explode('.', $_SERVER['REMOTE_ADDR']);
				$first_segment = $ip[0];

				$check_hash = sha1(
					$user . $ip_segment . $expire_time .
					'Ifz4lU5Bmwmbi17f2W4CW1I3XKrJmrWmc19bDAUBMNqyPVDEBfvBLUHQqxCk261');

				if ($user === FALSE || $ip_segment === FALSE || $expire_time === FALSE || $check_hash != $hash || $expire_time < time() || $ip_segment != $first_segment) {
					// If the user, ip segment, expire time or hash are invalid, or the cookie has expired, delete it
					// Also, if the user is signing in with a different first segment of their IP, also kill it
					setcookie('tt', '', time() - 3600);
					return FALSE;
				}

				PathHelper::requireOnce('data/users_class.php');
				// Now one last check to make sure this is a valid user
				try {
					$user_obj = new User($user, TRUE);
				} catch (UserException $e) {
					// Invalid user
					setcookie('tt', '', time() - 3600);
					return FALSE;
				} catch (Exception $e) {
					// Because any other exceptions will perpetuate out from here and try to regain
					// the session (which will recursively lead to problems), lets just catch them
					// here and say we can't get the user at this point in time.
					// Don't reset the cookie though, because it could very well valid and we are
					// having database issues for example.
					error_log('EXCEPTION: (on session creation)' . $e->getTraceAsString() . ' | ' . $e->getCode() . ' | ' . $e->getMessage());
					return FALSE;
				}

				if ($user_obj->actions_allowed() === TRUE) {
					// If they get to this point, its valid so log them in
					$this->store_session_variables($user_obj);
					LoginClass::StoreUserLogin($user_obj->key, LoginClass::LOGIN_COOKIE);
					return TRUE;
				} else {
					setcookie('tt', '', time() - 3600);
					return FALSE;
				}
			}
			else if(count($cookie_contents) == 3){
				//MEDIUM SECURITY
				list($user_hash, $expire_time_hash, $hash) = explode(';', $_COOKIE['tt']);
				$user = LibraryFunctions::decode($user_hash, 'user_id');
				$expire_time = LibraryFunctions::decode($expire_time_hash, 'expiration_date');

				$check_hash = sha1(
					$user . $expire_time .
					'Ifz4lU5Bmwmbi17f2W4CW1I3XKrJmrWmc19bDAUBMNqyPVDEBfvBLUHQqxCk261');

				if ($user === FALSE  || $expire_time === FALSE || $check_hash != $hash || $expire_time < time()) {
					// If the user, expire time or hash are invalid, or the cookie has expired, delete it
					setcookie('tt', '', time() - 3600);
					return FALSE;
				}

				PathHelper::requireOnce('data/users_class.php');
				// Now one last check to make sure this is a valid user
				try {
					$user_obj = new User($user, TRUE);
				} catch (UserException $e) {
					// Invalid user
					setcookie('tt', '', time() - 3600);
					return FALSE;
				} catch (Exception $e) {
					// Because any other exceptions will perpetuate out from here and try to regain
					// the session (which will recursively lead to problems), lets just catch them
					// here and say we can't get the user at this point in time.
					// Don't reset the cookie though, because it could very well valid and we are
					// having database issues for example.
					error_log('EXCEPTION: (on session creation)' . $e->getTraceAsString() . ' | ' . $e->getCode() . ' | ' . $e->getMessage());
					return FALSE;
				}

				if ($user_obj->actions_allowed() === TRUE) {
					// If they get to this point, its valid so log them in
					$this->store_session_variables($user_obj);
					LoginClass::StoreUserLogin($user_obj->key, LoginClass::LOGIN_COOKIE);
					return TRUE;
				} else {
					setcookie('tt', '', time() - 3600);
					return FALSE;
				}				
			}
			else{				
				// Cookie is invalid, delete it!
				setcookie('tt', '', time() - 3600);
				return FALSE;
			}
		}
		return FALSE;
	}

	public static function get_instance(){
		if (!self::$instance instanceof self) {
			self::$instance = new self;
		}
		return(self::$instance);
	}

	function logout() {
		if($this->get_user_id()) {
			LoginClass::StoreUserLogout($this->get_user_id());
		}

		$_SESSION = array();

		if (isset($_COOKIE[session_name()])) {
			setcookie(session_name(), '', time()-42000, '/');
		}

		// Kill the remember me cookie
		setcookie('tt', '', time() - 3600);

		session_destroy();
		session_write_close();
	}

	// DISPLAY MESSAGES
	function save_message(DisplayMessage $message) {
		$_SESSION['saved_messages'][] = $message;
	}

	function get_messages($page_url = NULL, $display_location = DisplayMessage::MESSAGE_DISPLAY_IN_PAGE) {
		$messages_out = array();

		if(!isset($_SESSION['saved_messages'])) {
			return $messages_out;
		}

		foreach ($_SESSION['saved_messages'] AS &$current_message) {
			if(!($current_message instanceof DisplayMessage)) {
				error_log('SessionControl.php: Bad DisplayMessage object: ' . print_r($current_message, TRUE));
				continue;
			}

			if(!$current_message->page_regex || preg_match($current_message->page_regex, $page_url)) {
				if($display_location) {
					if($current_message->display_location == $display_location) {
						$messages_out[] = $current_message;
						$current_message->clearable = TRUE;
					}
				} else {
					$messages_out[] = $current_message;
					$current_message->clearable = TRUE;
				}
			}
		}

		return $messages_out;
	}

	function clear_clearable_messages() {
		if(!isset($_SESSION['saved_messages'])) {
			return TRUE;
		}

		$nummessages = count($_SESSION['saved_messages']);
		for($i=0; $i < $nummessages; $i++) {
			$current_message = $_SESSION['saved_messages'][$i];
			if($current_message->clearable) {
				unset($_SESSION['saved_messages'][$i]);
			}
		}

		$_SESSION['saved_messages'] = array_values($_SESSION['saved_messages']);
	}

	function get_user_id($initial_user=FALSE) {
		if ($initial_user && $this->get_initial_user_id() !== NULL) {
			return $this->get_initial_user_id();
		}

		if (isset($_SESSION['usr_user_id']) && isset($_SESSION['loggedin']) &&	$_SESSION['loggedin']) {
			return intval($_SESSION['usr_user_id']);
		}
		return NULL;
	}

	function set_initial_user_id($user_id) {
		$_SESSION['initial_usr_user_id'] = $user_id;
		return true;
	}
	
	function get_initial_user_id() {
		return isset($_SESSION['initial_usr_user_id']) ? $_SESSION['initial_usr_user_id'] : NULL;
	}

	function set_timezone($timezone) {
		$_SESSION['timezone'] = $timezone;
	}

	function get_timezone($default=NULL) {
		if (isset($_SESSION['timezone'])) {
			// First attempt to get the timezone set on login
			return $_SESSION['timezone'];
		}

		// If we can't get that, fallback to any search they may have done
		if ($location_data = $this->get_location_data()) {
			$timezone = $location_data['timezone'];
			// It is possible this is set to FALSE if we couldn't get
			// the timezone from the search, in which case we need to
			// fallback to the default timezone :(
			if ($timezone) {
				return $timezone;
			}
		}

		// Otherwise fallback to the default (if given) or PST
		return $default ?: 'America/New_York';
	}

	function get_timezone_abbrev() {
		$tz = new DateTime('now', new DateTimeZone($this->get_timezone()));
		return $tz->format('T');
	}

	function set_location_data($disp_addr, $timezone) {
		$location_info = array(
			'disp_addr' => $disp_addr,
			'timezone' => $timezone,
		);
		$_SESSION['location_info'] = $location_info;

		// We are also going to cache the results of this location search in the in-memory
		// APC cache, so that we don't have to redo all the work if we need it again.
		//LibraryFunctions::StoreLocationInfoInCache($location_info);
	}

	function _set_location_data_array($location_info) {
		$_SESSION['location_info'] = $location_info;
	}

	function get_location_data() {
		return isset($_SESSION['location_info']) ? $_SESSION['location_info'] : FALSE;
	}


	function is_logged_in(){
		if(isset($_SESSION['loggedin'])){
			return true;
		}
		return false;
	}


	function get_permission() {
		
		return $_SESSION['permission'] ?? 0;	
		
		// If there is no logged in user or the user's IP doesn't match the one they logged in with
		// they have a permission level of 0
		//TODO REMOVED TEMPORARILY
		if (!$this->get_user_id() || $_SERVER['REMOTE_ADDR'] != $_SESSION['ip_address']) {
			return 0;
		}
		// Otherwise return their permission level
		return $_SESSION['permission'];
	}

	function check_permission($level, $msgtext=NULL){
		//IF NOT LOGGED IN OR IF IP ADDRESS HAS CHANGED FOR LOGGED IN USER, REDIRECT TO LOGIN SCREEN
		$ipchange = FALSE;
		/*
		//TEMPORARILY DISABLE IP CHANGE CHECKING ON ADMIN
		if(isset($_SESSION['loggedin'])) {
			if(filter_var($_SESSION['ip_address'], FILTER_VALIDATE_IP) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
				$ips = str_split('.', $_SESSION['ip_address']);
				$ipr = str_split('.', $_SERVER['REMOTE_ADDR']);
				if ($ips[0] != $ipr[0] || $ips[1] != $ipr[1]) {
					$ipchange = TRUE;
				}
			}
			else {
			  $ipchange = FALSE;
			}
		}


		if(!isset($_SESSION['loggedin']) || ($ipchange && $_SESSION['permission'] >= 5)){
		*/
		if(!isset($_SESSION['loggedin'])){
			if (count($_POST)) {
				$query_string = http_build_query($_POST);
			} else {
				$query_string = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
			}
			$this->set_return(
				parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?' . $query_string);

			//REDIRECT TO THE LOGIN PAGE
			if($msgtext) {
				$msgtext= urlencode($msgtext);
				header("HTTP/1.1 401 Unauthorized");
				require_once(PathHelper::getThemeFilePath('login.php', 'views', 'system').'?msgtext='.$msgtext);			
				exit();
			}
			else {
				header("HTTP/1.1 401 Unauthorized");
				require_once(PathHelper::getThemeFilePath('login.php', 'views', 'system'));	
				exit();
			}

		}
		else{
			if(!isset($_SESSION['permission']) || $_SESSION['permission'] < $level){
				header("HTTP/1.1 401 Unauthorized");
				throw new SystemAuthenticationError(
					'Sorry, you do not have the needed permissions to view this page.');
			}
		}
	}

	// Log somebody into the site and store their information in the session
	function store_session_variables($user, $mode='') {
		if (!$user->actions_allowed()) {
			throw new SystemDisplayablePermanentError(
				'This account is currently de-activated.  Please contact us to resolve the situation.');
		}

		$_SESSION['loggedin'] = TRUE;
		$_SESSION['usr_user_id'] = $user->key;
		$_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
		$_SESSION['timezone'] = $user->get('usr_timezone');

		if ($mode === 'admin') {
			$_SESSION['permission'] = 10;
		} else {
			$_SESSION['permission'] = $user->get('usr_permission');
			// Store the original user
			$_SESSION['initial_usr_user_id'] = $user->key;
		}
	}



	function get_raw($key) {
		return isset($_SESSION[$key]) ? $_SESSION[$key] : NULL;
	}

	function set_raw($key, $val) {
		$_SESSION[$key] = $val;
	}


	//SETS THE SESSION VARIABLES FOR WHERE A USER IS REDIRECTED AFTER AN ACTION
	function set_return($returnlocation=""){
		if(!$returnlocation){
			$_SESSION['returnurl'] = $_SERVER['REQUEST_URI'];
		} else {
			$_SESSION['returnurl'] = $returnlocation;
		}

		if(strstr($_SESSION['returnurl'], '/admin/')) {
			$_SESSION['admin_last_url'] = $_SESSION['returnurl'];
		}
	}

	function get_return() {
		if(isset($_SESSION['returnurl']) && strlen($_SESSION['returnurl']) > 0){
			return $_SESSION['returnurl'];
		}
		return FALSE;
	}

	function get_last_admin() {
		if(isset($_SESSION['admin_last_url']) && strlen($_SESSION['admin_last_url']) > 0){
			return $_SESSION['admin_last_url'];
		}
		return FALSE;
	}

	//THE FORMFIELDS FUNCTIONS STORE A FORM FOR LATER RETRIEVAL (AFTER AN ERROR FOR EXAMPLE)
	//TO USE, CALL SET_FORMFIELDS SAVE ON THE FORM THAT NEEDS TO BE SAVED, THEN CALL SAVE_FORMFIELDS FROM THE DATA FILE FOR THAT FORM
	function set_formfields_save($formname){
		$_SESSION['formname'] = $formname;
	}

	function save_formfields($formname=''){

			if($formname != ''){
				$_SESSION['formname'] = $formname;
			}
			$_SESSION['formfields'] = serialize($_POST);

	}

	function get_formfields($formname){

			if(isset($_SESSION['formname']) && isset($_SESSION['formfields']) && $_SESSION['formname'] == $formname && $_SESSION['formfields'] != ''){
				return((object)unserialize($_SESSION['formfields']));
			}
			else{
				return FALSE;
			}
	}

	function get_formfields_array($formname){

			if(isset($_SESSION['formname']) && isset($_SESSION['formfields']) && $_SESSION['formname'] == $formname && $_SESSION['formfields'] != ''){
				return(unserialize($_SESSION['formfields']));
			}
			else{
				return FALSE;
			}

	}

	function clear_formfields(){

			$_SESSION['formname'] = "";
			$_SESSION['formfields'] = "";

	}

	//SETS THE SESSION VARIABLES FOR SEARCHING AGAIN
	function set_last_query($tempquery){

			$_SESSION['lastquery'] = $tempquery;

	}

	function get_last_query(){

			return($_SESSION['lastquery']);

	}

}
?>
