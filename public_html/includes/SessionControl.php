<?php
/**************************************
CHECKS FOR THE NEEDED PERMISSIONS TO VIEW THE PAGE AND REDIRECTS
TO A LOGIN PAGE IF NOT
***************************************/
require_once ('PathHelper.php');
require_once ('DbConnector.php');
require_once ('LibraryFunctions.php');
require_once ('ShoppingCart.php');

require_once(PathHelper::getIncludePath('data/login_class.php'));

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

	// Mirror of VisitorEvent::TYPE_PAGE_VIEW so save_visitor_event() can branch
	// on page-view vs conversion without requiring the VisitorEvent class here
	// (SessionControl is always pre-loaded; VisitorEvent is not).
	const TYPE_PAGE_VIEW = 1;

	const COUPON_PENDING_KEY = 'pending_coupon';
	const COUPON_FLASH_KEY   = 'pending_coupon_flash';

	private static $instance;
	var $currpermissioncheck;

	private function __construct(){
		// Set secure session cookie parameters before starting the session
		$is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
			|| (!empty($_SERVER['HTTP_FORWARDED']) && preg_match('/proto=https/i', $_SERVER['HTTP_FORWARDED']))
			|| (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
		session_set_cookie_params([
			'lifetime' => 0,
			'path'     => '/',
			'secure'   => $is_secure,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
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

	/**
	 * Set a cookie with modern security attributes
	 * Compatible with PHP 7.3+ (uses options array for SameSite support)
	 *
	 * @param string $name Cookie name
	 * @param string $value Cookie value
	 * @param int $expires Expiration timestamp
	 * @param bool $httponly Whether cookie is HTTP only (default true)
	 * @param string $samesite SameSite attribute: 'Strict', 'Lax', or 'None' (default 'Lax')
	 * @return bool Success
	 */
	private function set_secure_cookie($name, $value, $expires, $httponly = true, $samesite = 'Lax') {
		$secure = $this->is_secure_connection();

		// SameSite=None requires Secure flag
		if ($samesite === 'None' && !$secure) {
			$samesite = 'Lax';
		}

		// PHP 7.3+ supports options array with samesite
		if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
			return setcookie($name, $value, [
				'expires' => $expires,
				'path' => '/',
				'domain' => '',
				'secure' => $secure,
				'httponly' => $httponly,
				'samesite' => $samesite
			]);
		}

		// Fallback for PHP < 7.3 (no SameSite support)
		return setcookie($name, $value, $expires, '/', '', $secure, $httponly);
	}

	/**
	 * Delete a cookie by setting expiration in the past
	 *
	 * @param string $name Cookie name
	 * @return bool Success
	 */
	private function delete_cookie($name) {
		return $this->set_secure_cookie($name, '', time() - 3600, true, 'Lax');
	}

	/**
	 * Determine if current connection is secure (HTTPS)
	 *
	 * @return bool
	 */
	private function is_secure_connection() {
		// Direct HTTPS
		if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
			return true;
		}
		// Behind load balancer/proxy
		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
			return true;
		}
		// Forwarded header (RFC 7239)
		if (!empty($_SERVER['HTTP_FORWARDED']) && preg_match('/proto=https/i', $_SERVER['HTTP_FORWARDED'])) {
			return true;
		}
		// Common port check
		if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
			return true;
		}
		return false;
	}

	public function save_user_to_cookie() {
		if (!$this->get_user_id()) return;

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = new User($this->get_user_id(), TRUE);

		// Generate cryptographically secure random token (64 hex chars)
		$raw_token = bin2hex(random_bytes(32));
		$token_hash = hash('sha256', $raw_token);
		$expires = time() + (90 * 24 * 60 * 60); // 90 days

		// Load existing tokens, decode if string, prune expired ones
		$tokens = $user->get('usr_remember_tokens');
		if (is_string($tokens)) $tokens = json_decode($tokens, true);
		if (!is_array($tokens)) $tokens = [];
		$tokens = array_values(array_filter($tokens, fn($t) => ($t['expires'] ?? 0) > time()));

		// Append new token
		$tokens[] = [
			'hash'    => $token_hash,
			'expires' => $expires,
			'created' => time(),
		];

		$user->set('usr_remember_tokens', json_encode($tokens));
		$user->save();

		$this->set_secure_cookie('tt', $raw_token, $expires);
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

	public function save_shopping_cart($cart) {
		$_SESSION['shopping_cart'] = $cart;
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
		if (empty($USER_AGENT)) return true;

		$crawlers = array(
			'Googlebot', 'AdsBot-Google', 'Mediapartners-Google', 'FeedFetcher-Google',
			'bingbot', 'msnbot', 'BingPreview',
			'Slurp', 'Yahoo',
			'Baiduspider', 'YandexBot', 'YandexImages', 'DuckDuckBot', 'DuckDuckGo',
			'facebookexternalhit', 'Facebot', 'Twitterbot', 'LinkedInBot', 'Slackbot',
			'Discordbot', 'TelegramBot', 'WhatsApp', 'Pinterestbot', 'Applebot',
			'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot', 'SeznamBot',
			'DataForSeoBot', 'BLEXBot', 'SiteAuditBot', 'Screaming Frog',
			'HeadlessChrome', 'PhantomJS', 'Lighthouse', 'PageSpeed',
			'curl/', 'Wget', 'python-requests', 'python-urllib', 'Go-http-client', 'Java/',
			'crawler', 'spider', 'bot/', 'bot ', 'Bot/', 'Bot ', 'archiver',
			'W3C_Validator', 'feedparser',
		);

		foreach ($crawlers as $pattern) {
			if (stripos($USER_AGENT, $pattern) !== false) return true;
		}
		return false;
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
	
	
	//TYPES:  1= WEB HIT, 3..8 = conversion/diagnostic events — see VisitorEvent::TYPE_* constants
	public function save_visitor_event($type=1, $is_404=FALSE, $ref_type=NULL, $ref_id=NULL, $meta=NULL){
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

		// First-touch session stickiness: preserve the UTM that introduced this visitor
		// so conversion events fired later in the session can attribute correctly.
		if ($source   && empty($_SESSION['utm_source']))   $_SESSION['utm_source']   = $source;
		if ($campaign && empty($_SESSION['utm_campaign'])) $_SESSION['utm_campaign'] = $campaign;
		if ($medium   && empty($_SESSION['utm_medium']))   $_SESSION['utm_medium']   = $medium;
		if ($content  && empty($_SESSION['utm_content']))  $_SESSION['utm_content']  = $content;

		// For non-page-view events (conversions fired from POST handlers with empty
		// query strings), fall back to session UTM so the conversion row is attributed.
		// Page views stay landing-only — UTM describes the arrival, not subsequent nav.
		if ($type !== self::TYPE_PAGE_VIEW) {
			if (!$source   && !empty($_SESSION['utm_source']))   $source   = $_SESSION['utm_source'];
			if (!$campaign && !empty($_SESSION['utm_campaign'])) $campaign = $_SESSION['utm_campaign'];
			if (!$medium   && !empty($_SESSION['utm_medium']))   $medium   = $_SESSION['utm_medium'];
			if (!$content  && !empty($_SESSION['utm_content']))  $content  = $_SESSION['utm_content'];
		}

		$dbhelper = DbConnector::get_instance();
		$dblink = $dbhelper->get_db_link();

		$sql = 'INSERT INTO vse_visitor_events (vse_visitor_id, vse_usr_user_id, vse_type, vse_ip, vse_page, vse_referrer, vse_source, vse_campaign, vse_medium, vse_content, vse_is_404, vse_ref_type, vse_ref_id, vse_meta)
		VALUES (:vse_visitor_id, :vse_usr_user_id, :vse_type, :vse_ip, :vse_page, :vse_referrer, :vse_source, :vse_campaign, :vse_medium, :vse_content, :vse_is_404, :vse_ref_type, :vse_ref_id, :vse_meta)';

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
			$q->bindValue(':vse_medium', $medium, PDO::PARAM_STR);
			$q->bindValue(':vse_content', $content, PDO::PARAM_STR);
			$q->bindValue(':vse_is_404', $is_404, PDO::PARAM_INT);
			$q->bindValue(':vse_ref_type', $ref_type, $ref_type === NULL ? PDO::PARAM_NULL : PDO::PARAM_STR);
			$q->bindValue(':vse_ref_id', $ref_id, $ref_id === NULL ? PDO::PARAM_NULL : PDO::PARAM_INT);
			$q->bindValue(':vse_meta', $meta, $meta === NULL ? PDO::PARAM_NULL : PDO::PARAM_STR);
			$success = $q->execute();
			$q->setFetchMode(PDO::FETCH_OBJ);
		} catch(PDOException $e) {
			$dbhelper->handle_query_error($e);
		}

		// A/B testing: flush trial + reward counters for this request. The
		// bandit piggybacks on this pipeline so all counter updates inherit
		// the bot filter above.
		if (class_exists('AbTest', false)) {
			AbTest::flush_request_accounting($type);
		}
	}

	/**
	 * Marketing-coupon intake — sibling to UTM capture above. Reads ?coupon=CODE
	 * from the query string, validates against CouponCode, stashes a pending code
	 * in session for the next cart, and logs every attempt (valid or invalid) to
	 * vse_visitor_events for attribution. Invalid codes fail silently so stale
	 * marketing links don't surface errors on the homepage.
	 */
	public function capture_marketing_coupon() {
		$code = isset($_GET['coupon']) ? trim(strtolower($_GET['coupon'])) : '';
		if ($code === '' || strlen($code) > 64) {
			return;
		}

		require_once(PathHelper::getIncludePath('data/coupon_codes_class.php'));
		require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

		$coupon = CouponCode::GetByColumn('ccd_code', $code);
		$valid  = $coupon && $coupon->is_valid();

		try {
			$this->save_visitor_event(VisitorEvent::TYPE_COUPON_ATTEMPT, FALSE, NULL, NULL, $code);
		} catch (Exception $e) {
			error_log('capture_marketing_coupon log error: ' . $e->getMessage());
		}

		if (!$valid) {
			return;
		}

		$_SESSION[self::COUPON_PENDING_KEY] = $code;
		$_SESSION[self::COUPON_FLASH_KEY]   = 'Coupon <strong>' . htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') . '</strong> will be applied at checkout.';

		$cart = $this->get_shopping_cart();
		if ($cart && $cart->count_items() > 0) {
			$this->apply_pending_coupon_to_cart($cart);
		}
	}

	/**
	 * Apply a previously-captured pending coupon to a cart. Called from
	 * ShoppingCart::add_item() so newly-added items pick up the discount.
	 * Clears the pending key on success so manual removal sticks.
	 */
	public function apply_pending_coupon_to_cart($cart) {
		if (empty($_SESSION[self::COUPON_PENDING_KEY])) {
			return;
		}
		$result = $cart->add_coupon($_SESSION[self::COUPON_PENDING_KEY]);
		if ($result === 1) {
			unset($_SESSION[self::COUPON_PENDING_KEY]);
		}
	}

	/**
	 * Flash message for pricing/cart views after a ?coupon= URL lands a valid code.
	 * Returns HTML string or null; clears on read so it shows once.
	 */
	public function get_pending_coupon_flash() {
		if (empty($_SESSION[self::COUPON_FLASH_KEY])) {
			return null;
		}
		$msg = $_SESSION[self::COUPON_FLASH_KEY];
		unset($_SESSION[self::COUPON_FLASH_KEY]);
		return $msg;
	}

	public function get_user_from_cookie() {
		if (empty($_COOKIE['tt'])) return FALSE;

		$raw_token = $_COOKIE['tt'];

		// Validate format — must be exactly 64 hex chars
		if (!preg_match('/^[0-9a-f]{64}$/', $raw_token)) {
			$this->delete_cookie('tt');
			return FALSE;
		}

		$token_hash = hash('sha256', $raw_token);

		// Find the user whose token array contains this hash
		$dbconnector = DbConnector::get_instance();
		$dblink = $dbconnector->get_db_link();
		try {
			$sql = "SELECT usr_user_id FROM usr_users
					WHERE usr_remember_tokens IS NOT NULL
					AND usr_remember_tokens::jsonb @> :token_search::jsonb
					AND usr_delete_time IS NULL";
			$q = $dblink->prepare($sql);
			$q->bindValue(':token_search', json_encode([['hash' => $token_hash]]), PDO::PARAM_STR);
			$q->execute();
			$row = $q->fetch(PDO::FETCH_OBJ);
		} catch (PDOException $e) {
			// Don't delete cookie on DB errors — it may still be valid
			error_log('EXCEPTION: (remember-me lookup) ' . $e->getMessage());
			return FALSE;
		}

		if (!$row) {
			$this->delete_cookie('tt');
			return FALSE;
		}

		// Load user and verify token expiration
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		try {
			$user_obj = new User($row->usr_user_id, TRUE);
		} catch (Exception $e) {
			error_log('EXCEPTION: (on session creation) ' . $e->getMessage());
			return FALSE;
		}

		$tokens = $user_obj->get('usr_remember_tokens');
		if (is_string($tokens)) $tokens = json_decode($tokens, true);
		if (!is_array($tokens)) {
			$this->delete_cookie('tt');
			return FALSE;
		}

		$matched = null;
		foreach ($tokens as $token) {
			if (($token['hash'] ?? '') === $token_hash) {
				$matched = $token;
				break;
			}
		}

		if (!$matched || ($matched['expires'] ?? 0) < time()) {
			$this->delete_cookie('tt');
			return FALSE;
		}

		if ($user_obj->actions_allowed() !== TRUE) {
			$this->delete_cookie('tt');
			return FALSE;
		}

		// 2FA check: if user has TOTP enabled and no valid trusted-device cookie,
		// stash a pending state and redirect to /verify-totp instead of completing login.
		// Leave the 'tt' cookie alone so a successful TOTP completes the auto-login.
		if ($user_obj->has_totp_enabled() && !$this->has_valid_trusted_device_cookie($user_obj)) {
			session_regenerate_id(true);
			$_SESSION['totp_pending_user_id']  = $user_obj->key;
			$_SESSION['totp_pending_remember'] = false; // Already had a remember cookie
			$_SESSION['totp_pending_return']   = $this->get_return();
			$_SESSION['totp_pending_expires']  = time() + 600;
			header('Location: /verify-totp');
			exit();
		}

		$this->store_session_variables($user_obj);
		LoginClass::StoreUserLogin($user_obj->key, LoginClass::LOGIN_COOKIE);
		return TRUE;
	}

	/**
	 * Trusted-device cookie format: {user_id};{expiry};{hmac_sha256(user_id+expiry+enabled_time, usr_totp_hmac_key)}
	 * Allows skipping the TOTP step on devices the user has approved, for N days.
	 * Invalidated automatically if the user disables/re-enables 2FA (rotates both enabled_time and hmac_key).
	 */
	private function compute_trusted_device_hmac($user, $expiry) {
		$key = $user->get('usr_totp_hmac_key');
		if (empty($key)) {
			return null;
		}
		$enabled_time = $user->get('usr_totp_enabled_time');
		$payload = $user->key . ':' . $expiry . ':' . $enabled_time;
		return hash_hmac('sha256', $payload, $key);
	}

	public function has_valid_trusted_device_cookie($user) {
		if (empty($_COOKIE['totp_trusted'])) return false;
		$parts = explode(';', $_COOKIE['totp_trusted']);
		if (count($parts) !== 3) return false;
		[$cookie_user_id, $expiry, $sig] = $parts;
		if ((int)$cookie_user_id !== (int)$user->key) return false;
		if ((int)$expiry < time()) return false;
		if (!ctype_xdigit($sig) || strlen($sig) !== 64) return false;
		$expected = $this->compute_trusted_device_hmac($user, (int)$expiry);
		if (!$expected) return false;
		return hash_equals($expected, $sig);
	}

	public function set_trusted_device_cookie($user) {
		$settings = Globalvars::get_instance();
		$days = (int)$settings->get_setting('totp_remember_device_days');
		if ($days <= 0) return;
		$expiry = time() + ($days * 86400);
		$sig = $this->compute_trusted_device_hmac($user, $expiry);
		if (!$sig) return;
		$value = $user->key . ';' . $expiry . ';' . $sig;
		$this->set_secure_cookie('totp_trusted', $value, $expiry);
	}

	public function delete_trusted_device_cookie() {
		$this->delete_cookie('totp_trusted');
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

		// Remove this device's remember-me token from the user's token list
		if (!empty($_COOKIE['tt']) && $this->get_user_id()) {
			$raw_token = $_COOKIE['tt'];
			if (preg_match('/^[0-9a-f]{64}$/', $raw_token)) {
				try {
					require_once(PathHelper::getIncludePath('data/users_class.php'));
					$token_hash = hash('sha256', $raw_token);
					$user = new User($this->get_user_id(), TRUE);
					$tokens = $user->get('usr_remember_tokens');
					if (is_string($tokens)) $tokens = json_decode($tokens, true);
					if (is_array($tokens)) {
						$tokens = array_values(array_filter($tokens, fn($t) => ($t['hash'] ?? '') !== $token_hash));
						$user->set('usr_remember_tokens', json_encode($tokens));
						$user->save();
					}
				} catch (Exception $e) {
					// Non-fatal — session is being destroyed anyway
					error_log('EXCEPTION: (logout token cleanup) ' . $e->getMessage());
				}
			}
		}

		$_SESSION = array();

		if (isset($_COOKIE[session_name()])) {
			$this->delete_cookie(session_name());
		}

		// Kill the remember me cookie
		$this->delete_cookie('tt');

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

	/**
	 * API session simulation — sets session variables for the given user
	 * so that logic functions see a logged-in user during API calls.
	 * Stores original session state for restoration via clear_api_user().
	 *
	 * @param int $user_id The user ID associated with the API key
	 */
	public function set_api_user($user_id) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = new User($user_id, TRUE);

		$this->_api_original_session = [
			'loggedin' => $_SESSION['loggedin'] ?? null,
			'usr_user_id' => $_SESSION['usr_user_id'] ?? null,
			'permission' => $_SESSION['permission'] ?? null,
			'timezone' => $_SESSION['timezone'] ?? null,
		];
		$this->_api_context = true;

		$_SESSION['loggedin'] = TRUE;
		$_SESSION['usr_user_id'] = $user->key;
		$_SESSION['permission'] = $user->get('usr_permission');
		$_SESSION['timezone'] = $user->get('usr_timezone');
	}

	/**
	 * Restore original session state after an API call.
	 */
	public function clear_api_user() {
		if (isset($this->_api_original_session)) {
			foreach ($this->_api_original_session as $key => $value) {
				if ($value === null) {
					unset($_SESSION[$key]);
				} else {
					$_SESSION[$key] = $value;
				}
			}
			unset($this->_api_original_session);
		}
		$this->_api_context = false;
	}

	/**
	 * Check if the current request is an API context (session was simulated).
	 *
	 * @return bool
	 */
	public function is_api_context() {
		return !empty($this->_api_context);
	}

	private $_api_original_session = null;
	private $_api_context = false;

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
		if (!$this->get_user_id()) {
			return 0;
		}

		// Check for major IP change (different /16 subnet) as a session hijack indicator
		$client_ip = $this->_get_client_ip();
		if (isset($_SESSION['ip_address']) && $client_ip) {
			if ($this->_is_major_ip_change($_SESSION['ip_address'], $client_ip)) {
				error_log(sprintf(
					'IP_VIOLATION get_permission: user_id=%s stored_ip=%s current_ip=%s page=%s',
					$_SESSION['usr_user_id'] ?? 'unknown',
					$_SESSION['ip_address'],
					$client_ip,
					$_SERVER['REQUEST_URI'] ?? ''
				));
				return 0;
			}
		}

		return $_SESSION['permission'] ?? 0;
	}

	/**
	 * Get the real client IP address, accounting for Cloudflare and reverse proxies.
	 * Prefers CF-Connecting-IP (Cloudflare), falls back to X-Forwarded-For, then REMOTE_ADDR.
	 */
	private function _get_client_ip() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			return $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// X-Forwarded-For can contain multiple IPs; the first is the real client
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			return trim($ips[0]);
		}
		return $_SERVER['REMOTE_ADDR'] ?? '';
	}

	/**
	 * Detect a major IP change (different /16 subnet) that may indicate session hijacking.
	 * Allows minor changes within the same ISP (e.g., mobile carrier, load balancer).
	 * Only checks IPv4; IPv6 addresses are not compared (returns false).
	 *
	 * TODO (security): Consider tightening to /24 for IPv4 and adding IPv6 prefix
	 * comparison (first 64 bits). Current /16 tolerance and no IPv6 check reduces
	 * false positives for roaming users but leaves headroom for session hijacking
	 * within the same ISP or data center range.
	 */
	private function _is_major_ip_change($stored_ip, $current_ip) {
		if (!filter_var($stored_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
			|| !filter_var($current_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			return false;
		}
		$stored_octets = explode('.', $stored_ip);
		$current_octets = explode('.', $current_ip);
		return ($stored_octets[0] != $current_octets[0] || $stored_octets[1] != $current_octets[1]);
	}

	function check_permission($level, $msgtext=NULL){
		//IF NOT LOGGED IN OR IF IP ADDRESS HAS CHANGED FOR LOGGED IN USER, REDIRECT TO LOGIN SCREEN
		$ipchange = FALSE;
		$client_ip = $this->_get_client_ip();
		if(isset($_SESSION['loggedin']) && isset($_SESSION['ip_address']) && $client_ip) {
			$ipchange = $this->_is_major_ip_change($_SESSION['ip_address'], $client_ip);
		}

		if(!isset($_SESSION['loggedin']) || ($ipchange && ($_SESSION['permission'] ?? 0) >= 5)){
			if ($ipchange && isset($_SESSION['loggedin'])) {
				error_log(sprintf(
					'IP_VIOLATION logout: user_id=%s permission=%s stored_ip=%s current_ip=%s page=%s',
					$_SESSION['usr_user_id'] ?? 'unknown',
					$_SESSION['permission'] ?? 'unknown',
					$_SESSION['ip_address'] ?? 'unknown',
					$client_ip,
					$_SERVER['REQUEST_URI'] ?? ''
				));
			}
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
				header('Location: /login?msgtext=' . $msgtext);
				exit();
			}
			else {
				header('Location: /login');
				exit();
			}

		}
		else{
			// Check if user must change password before accessing any other page
			if ($this->must_change_password()) {
				$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
				// Don't redirect if already on the password change page or logging out
				if ($current_path !== '/change-password-required' && $current_path !== '/logout') {
					header('Location: /change-password-required');
					exit();
				}
			}

			// Enforce 2FA on admin accounts when totp_require_admins is set.
			// Exempt /profile/security (where they enable it) and /logout to avoid loops.
			if ($this->must_enable_totp_for_admin()) {
				$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
				if ($current_path !== '/profile/security' && $current_path !== '/logout') {
					$msgtxt = urlencode('Your administrator account requires two-factor authentication.');
					header('Location: /profile/security?msgtext=' . $msgtxt);
					exit();
				}
			}

			if(!isset($_SESSION['permission']) || $_SESSION['permission'] < $level){
				header("HTTP/1.1 401 Unauthorized");
				throw new SystemAuthenticationError(
					'Sorry, you do not have the needed permissions to view this page.');
			}
		}
	}

	/**
	 * Returns true if the current user has admin permission (>=5) AND the
	 * totp_require_admins setting is enabled AND TOTP is not yet enabled on
	 * their account. Used to gate admin pages until 2FA is set up.
	 */
	function must_enable_totp_for_admin() {
		if (!isset($_SESSION['usr_user_id'])) return false;
		if (($_SESSION['permission'] ?? 0) < 5) return false;
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('totp_require_admins')) return false;
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = new User($_SESSION['usr_user_id'], true);
		return !$user->has_totp_enabled();
	}

	/**
	 * Check if the current user must change their password
	 * @return bool True if password change is required
	 */
	function must_change_password() {
		if (!isset($_SESSION['usr_user_id'])) {
			return false;
		}

		// Cache the result in session to avoid repeated DB queries
		if (!isset($_SESSION['force_password_change'])) {
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			$user = new User($_SESSION['usr_user_id'], true);
			$_SESSION['force_password_change'] = (bool)$user->get('usr_force_password_change');
		}

		return $_SESSION['force_password_change'];
	}

	// Log somebody into the site and store their information in the session
	function store_session_variables($user, $mode='') {
		if (!$user->actions_allowed()) {
			throw new SystemDisplayablePermanentError(
				'This account is currently de-activated.  Please contact us to resolve the situation.');
		}

		// Regenerate session ID to prevent session fixation attacks
		session_regenerate_id(true);

		$_SESSION['loggedin'] = TRUE;
		$_SESSION['usr_user_id'] = $user->key;
		$_SESSION['ip_address'] = $this->_get_client_ip();
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
