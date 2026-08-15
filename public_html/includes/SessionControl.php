<?php
/**************************************
CHECKS FOR THE NEEDED PERMISSIONS TO VIEW THE PAGE AND REDIRECTS
TO A LOGIN PAGE IF NOT
***************************************/
require_once ('PathHelper.php');
require_once ('DbConnector.php');
require_once ('LibraryFunctions.php');

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

	/**
	 * Whether this message is spent once it has been shown. TRUE for the
	 * ordinary one-shot message; FALSE for one that should keep appearing
	 * until something else removes it. The author's intent, fixed at
	 * construction.
	 */
	public $clearable;

	/**
	 * Whether this message has actually been rendered to a page yet. The
	 * runtime fact, and a different question from $clearable — which is why
	 * they are separate fields. Reading a message must not spend it; only
	 * showing it does.
	 */
	public $shown = FALSE;

	function __construct($message, $message_title, $page_regex=NULL, $display_type=DisplayMessage::MESSAGE_ANNOUNCEMENT, $display_location=DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, $identifier=NULL, $clearable=TRUE) {
		$this->message = $message;
		$this->message_title = $message_title;
		$this->page_regex = $page_regex;
		$this->display_type = $display_type;
		$this->display_location = $display_location;
		$this->identifier = $identifier;
		$this->clearable = $clearable;
		$this->shown = FALSE;
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

	private static $instance;
	var $currpermissioncheck;

	private function __construct(){
		// No web session in CLI: there is no cookie, no browser, and nothing to
		// persist. Actor identity for CLI workers (e.g. recipe runs) is set
		// in-memory via set_api_user(), which manipulates $_SESSION directly and
		// needs no started session. Starting a real session here only creates a
		// throwaway session file and emits "headers already sent" warnings once
		// anything has printed to stdout. php_sapi_name() is set by the runtime,
		// never by request input, and is never 'cli' under a web server — so this
		// branch can never affect a real HTTP request's authentication.
		if (php_sapi_name() === 'cli') {
			if (!isset($_SESSION)) $_SESSION = array();
			return;
		}

		// Set secure session cookie parameters before starting the session
		$is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
			|| (!empty($_SERVER['HTTP_FORWARDED']) && preg_match('/proto=https/i', $_SERVER['HTTP_FORWARDED']))
			|| (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
		// Keep server-side session files alive for 2 hours so idle carts survive.
		// The default php.ini gc_maxlifetime of 1440 s (24 min) was silently
		// expiring sessions while the browser still held a valid cookie.
		ini_set('session.gc_maxlifetime', 7200);
		// samesite=Lax is stated rather than inherited from the browser default,
		// which differs by browser and version. Lax and not Strict because
		// sign-in links, payment-gateway returns and password reset links all
		// arrive as top-level navigation from another site and must find the
		// session; Lax withholds the cookie from cross-site POSTs, which is the
		// case worth stopping. It does NOT cover cross-site GET navigation,
		// which is why an action a user triggers is a POST button rather than a
		// link (PublicPageBase::renderActionEntry).
		session_set_cookie_params([
			'lifetime' => 0,
			'path'     => '/',
			'secure'   => $is_secure,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		session_start();
		self::shadow_verify_form_csrf();
		if(!isset($_SESSION['saved_messages'])) {
			$_SESSION['saved_messages'] = array();
		}
		
		$this->get_uniqid();
		$this->sync_api_csrf_cookie();

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

	/**
	 * Mirror the session's API CSRF token into a JS-readable cookie. Pages
	 * served from the static page cache are shared HTML with no per-visitor
	 * meta tag, so guest-reachable page JS reads the token from this cookie
	 * instead (docs/api.md § Authentication). Distribution only — ApiAuth
	 * validates the X-Joinery-Csrf header against the raw session value,
	 * never against this cookie, so the cookie is not a trust anchor.
	 */
	private function sync_api_csrf_cookie() {
		if (headers_sent()) {
			return;
		}
		$token = $this->get_api_csrf_token();
		if (!isset($_COOKIE['joinery_api_csrf']) || $_COOKIE['joinery_api_csrf'] !== $token) {
			$this->set_secure_cookie('joinery_api_csrf', $token, 0, false, 'Lax');
			$_COOKIE['joinery_api_csrf'] = $token;
		}
	}

	/**
	 * Re-open a session previously released with session_write_close() so
	 * $_SESSION writes persist again. Used by API actions declaring
	 * auth.session_write (the browser credential releases the session lock
	 * right after reading identity — see ApiAuth::authenticateBrowserSession).
	 * The restart reuses the request's existing session id; use_cookies is
	 * suppressed so no redundant session-id Set-Cookie is emitted.
	 */
	public function reopen() {
		if (php_sapi_name() === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
			return;
		}
		session_start(['use_cookies' => 0]);
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

		// 2FA check: if the user holds ANY second factor (TOTP or a step-up-capable
		// passkey), the cadence asks it at sign-in, and there is no valid
		// trusted-device cookie, stash a pending state and redirect to /verify-totp
		// instead of completing the cookie auto-login. Keying on user_has_second_factor
		// (not has_totp_enabled) closes the passkey-only-Fortress quirk
		// (specs/mailbox_security_levels.md § 5.4). Leave the 'tt' cookie alone so a
		// successful factor completes the auto-login.
		if ($this->user_has_second_factor($user_obj) && $user_obj->two_factor_cadence() === 'every_login'
				&& !$this->has_valid_trusted_device_cookie($user_obj)) {
			// Stash the pending state once per pending login. This runs on every
			// request until the factor is proved, and re-stashing would rotate the
			// session id each time — the id the browser was just handed is the one
			// carrying the pending state.
			if (empty($_SESSION['totp_pending_user_id'])
					|| (int)$_SESSION['totp_pending_user_id'] !== (int)$user_obj->key
					|| empty($_SESSION['totp_pending_expires'])
					|| $_SESSION['totp_pending_expires'] < time()) {
				session_regenerate_id(true);
				$_SESSION['totp_pending_user_id']  = $user_obj->key;
				$_SESSION['totp_pending_remember'] = false; // Already had a remember cookie
				$_SESSION['totp_pending_return']   = $this->get_return();
				$_SESSION['totp_pending_expires']  = time() + 600;
			}
			// Never divert a request that is already how the factor gets proved, or
			// how the user backs out of it: sending the factor page to itself is an
			// infinite redirect, and sending /logout there traps the browser with no
			// way out. Return not-logged-in and let the request run.
			if (!self::is_second_factor_handoff()) {
				header('Location: /verify-totp');
				exit();
			}
			return FALSE;
		}

		$this->store_session_variables($user_obj);
		LoginClass::StoreUserLogin($user_obj->key, LoginClass::LOGIN_COOKIE);
		return TRUE;
	}

	/**
	 * Is this request part of proving — or abandoning — a pending second factor?
	 *
	 * These are the only requests a pending user is allowed to make before the
	 * factor is proved: the page that collects it, the two passkey actions that
	 * page calls instead of a code, and the way out. Everything else is diverted
	 * to the factor page.
	 */
	private static function is_second_factor_handoff() {
		$path = rtrim(strtok($_SERVER['REQUEST_URI'] ?? '', '?'), '/');
		return in_array($path, array(
			'/verify-totp',
			'/logout',
			'/api/v1/action/login_2fa_passkey_options',
			'/api/v1/action/login_2fa_passkey_verify',
		), true);
	}

	/**
	 * Trusted-device cookie format: {user_id};{expiry};{hmac_sha256(user_id+expiry, usr_second_factor_hmac_key)}
	 * Skips the second-factor ask at sign-in on devices the user chose to trust,
	 * for N days — regardless of which factor method (TOTP or passkey) proved
	 * the trust. Rotating the key (User::rotate_second_factor_hmac_key) is the
	 * revocation: it happens on forget-trusted-devices, TOTP turn-off, and
	 * passkey revocation (specs/second_factor_ux_coherence.md).
	 */
	private function compute_trusted_device_hmac($user, $expiry) {
		$key = $user->get('usr_second_factor_hmac_key');
		if (empty($key)) {
			return null;
		}
		$payload = $user->key . ':' . $expiry;
		return hash_hmac('sha256', $payload, $key);
	}

	public function has_valid_trusted_device_cookie($user) {
		if (empty($_COOKIE['sf_trusted'])) return false;
		$parts = explode(';', $_COOKIE['sf_trusted']);
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
		// The signing key is minted lazily by the first trust grant — it exists
		// exactly when at least one trusted device could.
		if (empty($user->get('usr_second_factor_hmac_key'))) {
			$user->rotate_second_factor_hmac_key();
		}
		$expiry = time() + ($days * 86400);
		$sig = $this->compute_trusted_device_hmac($user, $expiry);
		if (!$sig) return;
		$value = $user->key . ';' . $expiry . ';' . $sig;
		$this->set_secure_cookie('sf_trusted', $value, $expiry);
	}

	public function delete_trusted_device_cookie() {
		$this->delete_cookie('sf_trusted');
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

		// End this session's vault window on logout (specs/mailbox_security_levels.md
		// § The Unlock Window: session end — the window never outlives its session).
		if ($this->get_user_id()) {
			try {
				require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
				VaultUnlock::close($this->get_user_id(), 'user', VaultAudit::REASON_LOGOUT);
			} catch (\Throwable $e) {
				error_log('logout: vault window close failed: ' . $e->getMessage());
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

	/**
	 * Record — without acting on it — every form POST whose CSRF token would
	 * not check out.
	 *
	 * Forms already carry a token; nothing yet refuses a submission that lacks
	 * one. Before that can change, the size and shape of the problem has to be
	 * known: how many real submissions arrive with no token at all (a form
	 * built outside FormWriter, a page cached past the token's life) versus a
	 * mismatched one. This writes that to the error log and changes nothing
	 * else — same request, same outcome, token or no token.
	 *
	 * Non-consuming on purpose: FormWriter's own validateCSRF() clears the
	 * token on a successful check, and this must not take that away from it.
	 */
	private static function shadow_verify_form_csrf() {
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			return;
		}

		// Every FormWriter form posts this field; a POST without it is not a
		// FormWriter submission and is not this measurement's business.
		$field = '_csrf_token';
		if (!array_key_exists($field, $_POST)) {
			return;
		}

		$presented = is_string($_POST[$field]) ? $_POST[$field] : '';
		$stored = isset($_SESSION['csrf_tokens']) && is_array($_SESSION['csrf_tokens'])
			? $_SESSION['csrf_tokens'] : array();

		$reason = null;
		if ($presented === '') {
			$reason = 'absent';
		} else {
			$matched = false;
			$expired = false;
			foreach ($stored as $entry) {
				if (!is_array($entry) || !isset($entry['token'])) {
					continue;
				}
				if (hash_equals((string)$entry['token'], $presented)) {
					$matched = true;
					$expired = isset($entry['expires']) && $entry['expires'] < time();
					break;
				}
			}
			if (!$matched) {
				$reason = $stored ? 'mismatched' : 'no-token-in-session';
			} elseif ($expired) {
				$reason = 'expired';
			}
		}

		if ($reason !== null) {
			error_log(sprintf(
				'[CSRF_SHADOW] would-have-failed POST: reason=%s path=%s form_ids_in_session=%s user_id=%s',
				$reason,
				$_SERVER['REQUEST_URI'] ?? '?',
				$stored ? implode(',', array_keys($stored)) : '(none)',
				$_SESSION['usr_user_id'] ?? 'anonymous'
			));
		}
	}

	// DISPLAY MESSAGES
	function save_message(DisplayMessage $message) {
		$_SESSION['saved_messages'][] = $message;
	}

	/**
	 * The pending messages addressed to this page — a pure read.
	 *
	 * Reading does not spend a message; rendering does (see mark_shown(), and
	 * PublicPageBase::render_messages(), which is the thing that calls it).
	 * That separation is what lets any code ask what is pending without
	 * destroying a message it was never going to display.
	 *
	 * @param string $page_url The URL to match each message's page_regex against.
	 * @param int|null $display_location Filter to one location, or NULL for all.
	 * @param string|null $identifier Filter to messages addressed to one named
	 *                                slot on the page. NULL means every slot.
	 * @return DisplayMessage[]
	 */
	function get_messages($page_url = NULL, $display_location = DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, $identifier = NULL) {
		$messages_out = array();

		if(!isset($_SESSION['saved_messages'])) {
			return $messages_out;
		}

		foreach ($_SESSION['saved_messages'] AS $current_message) {
			if(!($current_message instanceof DisplayMessage)) {
				error_log('SessionControl.php: Bad DisplayMessage object: ' . print_r($current_message, TRUE));
				continue;
			}

			if($current_message->page_regex && !preg_match($current_message->page_regex, $page_url)) {
				continue;
			}
			if($display_location && $current_message->display_location != $display_location) {
				continue;
			}
			if($identifier !== NULL && $current_message->identifier != $identifier) {
				continue;
			}

			$messages_out[] = $current_message;
		}

		return $messages_out;
	}

	/**
	 * Record that these messages have been rendered to the page. Called by
	 * whatever emitted them; the footer then clears the spent ones.
	 *
	 * @param DisplayMessage[] $messages
	 */
	function mark_shown(array $messages) {
		foreach ($messages as $message) {
			if ($message instanceof DisplayMessage) {
				$message->shown = TRUE;
			}
		}
	}

	/**
	 * Drop the messages that have been shown and were meant to be one-shot.
	 * A message that was read but never rendered is left pending, which is the
	 * whole point: a page that does not display a message must not consume it.
	 */
	function clear_clearable_messages() {
		if(!isset($_SESSION['saved_messages'])) {
			return TRUE;
		}

		$remaining = array();
		foreach ($_SESSION['saved_messages'] as $current_message) {
			if (!($current_message instanceof DisplayMessage)) {
				continue;
			}
			if ($current_message->shown && $current_message->clearable) {
				continue;
			}
			$remaining[] = $current_message;
		}

		$_SESSION['saved_messages'] = $remaining;
	}

	/**
	 * The session-wide CSRF token that authenticates this browser session to
	 * /api/v1 (sent as the X-Joinery-Csrf header; validated by ApiAuth).
	 * Minted once per session on first request and stable thereafter — it
	 * survives session_regenerate_id() because session data carries over.
	 * Separate from FormWriter's per-form tokens, which are unchanged.
	 *
	 * Minted at session construction (sync_api_csrf_cookie) so every web
	 * request — including cache HITs served by RouteHelper — carries the
	 * mirror cookie; PublicPageBase additionally emits it as a meta tag for
	 * logged-in pages. Minting during an API request is harmless: ApiAuth
	 * validates the presented header against this stored value, and a
	 * cross-site attacker can read neither the session nor the cookie.
	 *
	 * @return string 64-char hex token
	 */
	public function get_api_csrf_token() {
		if (empty($_SESSION['api_csrf_token'])) {
			$_SESSION['api_csrf_token'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['api_csrf_token'];
	}


	/**
	 * API session simulation — sets session variables for the given user
	 * so that logic functions see a logged-in user during API calls.
	 * Stores original session state for restoration via clear_api_user().
	 *
	 * @param int $user_id The user ID associated with the API key
	 * @param int|null $api_key_id The presented ApiKey's id (null for the
	 *        browser-session credential, which has no ApiKey row)
	 */
	public function set_api_user($user_id, $api_key_id = null) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = new User($user_id, TRUE);

		$this->_api_original_session = [
			'loggedin' => $_SESSION['loggedin'] ?? null,
			'usr_user_id' => $_SESSION['usr_user_id'] ?? null,
			'permission' => $_SESSION['permission'] ?? null,
			'timezone' => $_SESSION['timezone'] ?? null,
			'api_key_id' => $_SESSION['api_key_id'] ?? null,
		];
		$this->_api_context = true;

		$_SESSION['loggedin'] = TRUE;
		$_SESSION['usr_user_id'] = $user->key;
		$_SESSION['permission'] = $user->get('usr_permission');
		$_SESSION['timezone'] = $user->get('usr_timezone');
		$_SESSION['api_key_id'] = $api_key_id;
	}

	/**
	 * The ApiKey id that authenticated the current API request, or null for
	 * the browser-session credential (no ApiKey row) or outside API context.
	 *
	 * @return int|null
	 */
	public function get_api_key_id() {
		return $_SESSION['api_key_id'] ?? null;
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

	/**
	 * Mark this web session app-context: it was started by the web-session
	 * bridge (/app_bridge) from a native app's API session key, so pages render
	 * without site chrome (PublicPageBase::show_site_chrome()) and the session
	 * lives only as long as its originating key (validate_app_context()).
	 *
	 * @param int $api_key_id The originating apk_api_keys id
	 * @param string $client_app The app's client_app identifier
	 */
	public function mark_app_context($api_key_id, $client_app = '') {
		$_SESSION['app_context'] = array(
			'api_key_id' => (int)$api_key_id,
			'client_app' => (string)$client_app,
			'checked_time' => time(),
		);
	}

	/**
	 * Whether this web session was bridged from a native app's session key.
	 */
	public function is_app_session() {
		return !empty($_SESSION['app_context']);
	}

	/**
	 * The app-context record (api_key_id, client_app, checked_time), or NULL
	 * for ordinary web sessions.
	 */
	public function get_app_context() {
		return $_SESSION['app_context'] ?? NULL;
	}

	/**
	 * Lifetime coupling for bridged sessions: an app-context web session is
	 * valid only while its originating API key is. Revoking the key — app
	 * logout, the App Sessions page, or a password change — ends this session
	 * at its next check. Checked at most once per request, throttled to
	 * app_bridge_key_check_seconds (default 60) between database loads.
	 */
	private function validate_app_context() {
		$this->_app_context_checked = true;

		$raw = Globalvars::get_instance()->get_setting('app_bridge_key_check_seconds', false, true);
		$interval = ($raw === null || $raw === '') ? 60 : (int)$raw;

		$last = (int)($_SESSION['app_context']['checked_time'] ?? 0);
		if ($interval > 0 && (time() - $last) < $interval) {
			return;
		}
		$_SESSION['app_context']['checked_time'] = time();

		$alive = false;
		try {
			require_once(PathHelper::getIncludePath('data/api_keys_class.php'));
			$api_key = new ApiKey($_SESSION['app_context']['api_key_id'], TRUE);
			$alive = !$api_key->get('apk_delete_time')
				&& $api_key->get('apk_is_active')
				&& (!$api_key->get('apk_expires_time')
					|| gmdate('Y-m-d H:i:s') <= $api_key->get('apk_expires_time'));
		} catch (Exception $e) {
			// Row gone = revoked and purged
			$alive = false;
		}

		if (!$alive) {
			$this->logout();
		}
	}

	private $_app_context_checked = false;

	/**
	 * Bridged sessions die with their originating API key — run the coupling
	 * check before answering any identity question (get_user_id,
	 * is_logged_in, check_permission), so no caller can act on a stale
	 * logged-in answer. The flag makes this once per request and lets
	 * logout()'s own identity calls pass through.
	 */
	private function ensure_app_context_valid() {
		if (!$this->_app_context_checked && !empty($_SESSION['app_context'])) {
			$this->validate_app_context();
		}
	}

	function get_user_id($initial_user=FALSE) {
		$this->ensure_app_context_valid();

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
		$this->ensure_app_context_valid();
		if(isset($_SESSION['loggedin'])){
			return true;
		}
		return false;
	}


	// ====================================================================
	// Second-factor step-up (specs/mailbox_security_levels.md § 5.5)
	//
	// A sensitive ADMINISTRATION action re-confirms the account's second factor
	// (TOTP or passkey) and stamps a session-scoped marker. Distinct from the
	// vault unlock window: the vault gates plaintext redirection; the second
	// factor gates administration. Passkey and TOTP step-ups share ONE marker
	// (pks_passkey_ceremonies kind='stepup'), so one recency check covers both —
	// a passkey step-up already writes it (PasskeyService::verifyStepUp); a TOTP
	// step-up writes it via stamp_second_factor().
	// ====================================================================

	/** True when the user holds a USABLE second factor: TOTP, or ≥1 live passkey
	 *  while passkey sign-in is enabled site-wide. Passkeys stop counting when
	 *  passkeys_enabled is off — every consumer (the sign-in divert, step-up
	 *  gates) offers only ceremonies the user can actually run, so disabling
	 *  passkeys never strands a passkey-only account at a factor prompt with no
	 *  completion path. */
	function user_has_second_factor($user): bool {
		return $this->_count_usable_factors($user, 1);
	}

	/** True when the user holds a second factor INDEPENDENT of any single
	 *  passkey: TOTP, or at least two live passkeys. The Fortress enrollment
	 *  gate keys on this — the vault-holder password reset excludes the passkey
	 *  that authorized it and demands another factor, so enrollment must
	 *  guarantee one credential is never both the authorizer and its own
	 *  confirmation (specs/security_levels_review_fixes.md Fix 1). */
	function user_has_independent_second_factor($user): bool {
		return $this->_count_usable_factors($user, 2);
	}

	private function _count_usable_factors($user, int $passkeys_needed): bool {
		if (!$user || !$user->key) {
			return false;
		}
		if ($user->has_totp_enabled()) {
			return true;
		}
		$settings = Globalvars::get_instance();
		if (!$settings->get_setting('passkeys_enabled')) {
			return false;
		}
		require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
		$creds = new MultiPasskey(array('user_id' => (int)$user->key));
		$creds->load();
		return count($creds) >= $passkeys_needed;
	}

	/** True when THIS session re-confirmed a second factor within $ttl seconds. */
	function has_recent_second_factor(int $ttl = 300): bool {
		$sid = session_id();
		if (!$sid) {
			return false;
		}
		require_once(PathHelper::getIncludePath('data/passkey_ceremonies_class.php'));
		$markers = new MultiPasskeyCeremony(array('session_id' => $sid, 'kind' => 'stepup'));
		$markers->load();
		$cutoff = time() - $ttl;
		foreach ($markers as $m) {
			$t = strtotime($m->get('pks_created_time') . ' UTC');
			if ($t && $t >= $cutoff) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when $user still OWES a step-up before a sensitive action.
	 *
	 * This is the question a gate means to ask, and it differs from
	 * has_recent_second_factor() in exactly one case — the case that makes it
	 * necessary. An account with no second factor has nothing to step up WITH:
	 * `/verify-stepup` knows that and returns immediately, so an action gated on
	 * the bare "was there a recent confirmation?" refuses such an account
	 * forever. The client bounces to the ceremony, the ceremony bounces
	 * straight back, and the action never becomes possible — usually while
	 * naming a passkey the user does not own.
	 *
	 * require_recent_second_factor() applies this rule for actions that can
	 * answer with a redirect. This is the same rule for actions that must
	 * answer an API caller instead, and it is the whole of the gate: a caller
	 * needs nothing from PasskeyService (and so does not drag the WebAuthn
	 * library into contexts that never do a ceremony).
	 *
	 * @param User|null $user the account to judge; loaded from this session
	 *   when omitted.
	 */
	function step_up_outstanding($user = null, int $ttl = 300): bool {
		if ($user === null) {
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			$user = new User($this->get_user_id(), TRUE);
		}
		if (!$this->user_has_second_factor($user)) {
			return false;
		}
		return !$this->has_recent_second_factor($ttl);
	}

	/** Stamp a fresh second-factor confirmation for this session (the TOTP path;
	 *  the passkey path stamps its own via PasskeyService::verifyStepUp). */
	function stamp_second_factor(string $purpose = 'stepup_verified'): void {
		$sid = session_id();
		if (!$sid) {
			return;
		}
		require_once(PathHelper::getIncludePath('data/passkey_ceremonies_class.php'));
		$marker = new PasskeyCeremony(NULL);
		$marker->set('pks_session_id', $sid);
		$marker->set('pks_kind', 'stepup');
		$marker->set('pks_purpose', $purpose);
		$marker->set('pks_expires_time', gmdate('Y-m-d H:i:s', time() + 3600));
		$marker->save();
	}

	/**
	 * Gate a sensitive action on a recent second-factor step-up. Returns a
	 * LogicResult redirect to the step-up ceremony (which returns to $return_url)
	 * when confirmation is needed, or NULL to proceed. A no-op for an account
	 * with no second factor — there is nothing to step up with (2FA is optional
	 * below Fortress); the action's own enrollment rules decide whether a factor
	 * must exist. When $force is true (e.g. recovery-code unlock) the gate fires
	 * regardless of cadence but still only when a factor is enrolled.
	 *
	 * @return LogicResult|null
	 */
	function require_recent_second_factor(string $return_url, int $ttl = 300) {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$uid = $this->get_user_id();
		$user = $uid ? new User($uid, TRUE) : null;
		if (!$this->user_has_second_factor($user)) {
			return null;
		}
		if ($this->has_recent_second_factor($ttl)) {
			return null;
		}
		require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
		// Only same-site relative returns (leading single slash) — never an open redirect.
		if ($return_url === '' || $return_url[0] !== '/' || (isset($return_url[1]) && $return_url[1] === '/')) {
			$return_url = '/profile';
		}
		return LogicResult::redirect('/verify-stepup?return=' . rawurlencode($return_url));
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
				// Network identity change also ends this session's vault window
				// (specs/mailbox_security_levels.md § The Unlock Window): a laptop that
				// leaves the cafe re-locks when it reappears on another network.
				if (!empty($_SESSION['usr_user_id'])) {
					try {
						require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
						VaultUnlock::close((int)$_SESSION['usr_user_id'], 'user', VaultAudit::REASON_IP_CHANGE);
					} catch (\Throwable $e) {}
				}
				return 0;
			}
		}

		return $_SESSION['permission'] ?? 0;
	}

	/**
	 * Get the real client IP address, accounting for Cloudflare and reverse proxies.
	 *
	 * Default mode prefers CF-Connecting-IP, falls back to X-Forwarded-For, then
	 * REMOTE_ADDR — right for heuristics (hijack detection, logging) where a
	 * spoofed header costs nothing.
	 *
	 * $for_auth mode is for security decisions (e.g. API-key IP restrictions):
	 * CF-Connecting-IP is honored ONLY when the TCP peer is a verified
	 * Cloudflare edge address, and X-Forwarded-For is never trusted — otherwise
	 * a direct-to-origin request could spoof any allowed IP with one header.
	 */
	public static function get_client_ip(bool $for_auth = false) {
		$remote = $_SERVER['REMOTE_ADDR'] ?? '';
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])
				&& (!$for_auth || self::ip_is_cloudflare_edge($remote))) {
			return $_SERVER['HTTP_CF_CONNECTING_IP'];
		}
		if (!$for_auth && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// X-Forwarded-For can contain multiple IPs; the first is the real client
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			return trim($ips[0]);
		}
		return $remote;
	}

	private function _get_client_ip() {
		return self::get_client_ip();
	}

	/** True when $ip is inside Cloudflare's published edge ranges (www.cloudflare.com/ips). */
	public static function ip_is_cloudflare_edge(string $ip): bool {
		static $ranges = array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
			'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		);
		$packed = @inet_pton($ip);
		if ($packed === false) {
			return false;
		}
		foreach ($ranges as $range) {
			list($net, $bits) = explode('/', $range);
			$net_packed = @inet_pton($net);
			if ($net_packed === false || strlen($net_packed) !== strlen($packed)) {
				continue;
			}
			$bits = (int)$bits;
			$bytes = intdiv($bits, 8);
			$remainder = $bits % 8;
			if ($bytes > 0 && strncmp($packed, $net_packed, $bytes) !== 0) {
				continue;
			}
			if ($remainder > 0) {
				$mask = 0xFF << (8 - $remainder) & 0xFF;
				if ((ord($packed[$bytes]) & $mask) !== (ord($net_packed[$bytes]) & $mask)) {
					continue;
				}
			}
			return true;
		}
		return false;
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
		$this->ensure_app_context_valid();

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
			$request_uri = $_SERVER['REQUEST_URI'] ?? '';
			if (count($_POST)) {
				$query_string = http_build_query($_POST);
			} else {
				$query_string = parse_url($request_uri, PHP_URL_QUERY);
			}
			$this->set_return(
				parse_url($request_uri, PHP_URL_PATH) . '?' . $query_string);

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
			// Both checks below end in a browser redirect, and on the CLI there is
			// no browser and no REQUEST_URI. Unguarded, a scheduled task or
			// maintenance script running as a user who still owes a password change
			// or a terms acceptance read an undefined REQUEST_URI, called header()
			// into the void, and then exit()ed mid-run — the script died with
			// nothing said about why. A fresh install reaches this immediately: the
			// admin account the installer creates carries force_password_change
			// from birth, so on a newly built node it was every CLI entry point,
			// not an edge case.
			//
			// Resolved once, so the two checks cannot disagree about what page is
			// being viewed. NULL means "not a request", which is not the same as a
			// request whose path happens to be empty.
			$current_path = (PHP_SAPI === 'cli')
				? NULL
				: parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

			// Check if user must change password before accessing any other page
			if ($current_path !== NULL && $this->must_change_password()) {
				// Don't redirect if already on the password change page or logging out
				if ($current_path !== '/change-password-required' && $current_path !== '/logout') {
					header('Location: /change-password-required');
					exit();
				}
			}

			// Check if user must accept terms before accessing any other page
			if ($current_path !== NULL && $this->must_accept_terms()) {
				if ($current_path !== '/terms-accept' && $current_path !== '/logout') {
					header('Location: /terms-accept');
					exit();
				}
			}

			// First-login setup wizard (specs/setup_wizard.md): an account that
			// has never dismissed the wizard and has outstanding setup steps is
			// taken to /setup. Sits BEFORE the 2FA gates on purpose — the wizard
			// mounts the same enrollment ceremonies, so a fresh admin enrolls
			// there; dismissing without enrolling lands on the stricter gates
			// below. Same /api/v1/ exemption as those gates: the wizard's own
			// enrollment fetches must survive this.
			if ($current_path !== NULL && $current_path !== '/setup'
					&& $current_path !== '/logout'
					&& strpos((string)$current_path, '/api/v1/') !== 0) {
				require_once(PathHelper::getIncludePath('includes/SetupSteps.php'));
				if (SetupSteps::shouldInterrupt()) {
					header('Location: /setup');
					exit();
				}
			}

			// Enforce 2FA on admin accounts when totp_require_admins is set.
			// Exempt /profile/security (where they enable it), /setup (which
			// mounts the same enrollment) and /logout to avoid loops, and ALL
			// /api/v1/ requests: the gate governs page navigation, but the
			// security page does its enrollment through /api/v1 fetches
			// (passkey_register_*, TOTP setup) that call check_permission()
			// themselves — an HTML redirect inside a JSON fetch would make
			// enrollment impossible. Protected content over the API is already
			// independently vault-gated.
			if ($this->must_enable_totp_for_admin()) {
				$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
				if ($current_path !== '/profile/security' && $current_path !== '/setup'
						&& $current_path !== '/logout'
						&& strpos((string)$current_path, '/api/v1/') !== 0) {
					$msgtxt = urlencode('Your administrator account requires two-factor authentication.');
					header('Location: /profile/security?msgtext=' . $msgtxt);
					exit();
				}
			}

			// Fortress mandatory-2FA enrollment (specs/mailbox_security_levels.md § 5.3):
			// a user who owns or holds a grant on a Fortress domain is blocked until a
			// second factor is enrolled. Same surface + exemptions as the admin gate
			// (including the /api/v1/ exemption, so passkey/TOTP enrollment works).
			if ($this->must_enroll_2fa_for_fortress()) {
				$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
				if ($current_path !== '/profile/security' && $current_path !== '/setup'
						&& $current_path !== '/logout'
						&& strpos((string)$current_path, '/api/v1/') !== 0) {
					$msgtxt = urlencode('A domain on your account uses the Fortress level, which requires a second factor that is separate from any single passkey. Add an authenticator app or a second passkey to continue.');
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
	 * True when the current user touches a Fortress-level domain (owns one or
	 * holds a grant on one) but has no INDEPENDENT second factor enrolled (TOTP
	 * or a second passkey — one credential must never be both the reset
	 * authorizer and its own confirmation) — the Fortress mandatory-2FA gate
	 * (specs/mailbox_security_levels.md § 5.3). The heavy posture lookup is
	 * cached in session; the factor check stays live so enrolling clears the
	 * gate immediately without busting the cache.
	 */
	function must_enroll_2fa_for_fortress() {
		if (!isset($_SESSION['usr_user_id'])) {
			return false;
		}
		if (!isset($_SESSION['max_security_level'])) {
			$_SESSION['max_security_level'] = 'standard';
			$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
			if (is_file($domain_class)) {
				require_once($domain_class);
				if (class_exists('InboundEmailDomain')) {
					try {
						$_SESSION['max_security_level'] =
							InboundEmailDomain::maxSecurityLevelForUser((int)$_SESSION['usr_user_id']);
					} catch (\Throwable $e) {
						$_SESSION['max_security_level'] = 'standard';
					}
				}
			}
		}
		if ($_SESSION['max_security_level'] !== 'fortress') {
			return false;
		}
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$user = new User($_SESSION['usr_user_id'], true);
		return !$this->user_has_independent_second_factor($user);
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

	/**
	 * Check whether the current user still owes terms acceptance.
	 * Cached in session to avoid per-request DB hits; the cache is
	 * cleared on /terms-accept submit and on logout.
	 */
	function must_accept_terms() {
		if (!isset($_SESSION['usr_user_id'])) {
			return false;
		}

		if (!isset($_SESSION['terms_accepted'])) {
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			$user = new User($_SESSION['usr_user_id'], true);
			$_SESSION['terms_accepted'] = !empty($user->get('usr_terms_accepted_time'));
		}

		return !$_SESSION['terms_accepted'];
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
		$_SESSION['terms_accepted'] = !empty($user->get('usr_terms_accepted_time'));

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
