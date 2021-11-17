<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ShoppingCart.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/data/public_menus_class.php');

class PublicPageMaster {

	protected $rowcount;
	protected $theme_url;

	protected static $header_defaults = array(
		//'title' => '',
		'showheader' => TRUE,
		'noindex' => FALSE,
		'nofollow' => FALSE,
	);

	protected static $footer_defaults = array(
		'track' => TRUE,
	);
	
	public function __construct($secure=FALSE) {
		$this->rowcount = 0;
		$this->secure = $secure;
		$this->server = $_SERVER['PHP_SELF'];
		$this->remote_addr = $_SERVER['REMOTE_ADDR'];

		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();

		$this->debug = $settings->get_setting('debug');
		if ($this->debug == 1) {
			$secure = FALSE;
			$this->secure = FALSE;
		}

		// If secure is on, they are not HTTPS and on port 80, forward them to SSL
		/*
		if ($secure && $_SERVER["SERVER_PORT"] == 80) {
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: https://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
			exit;
		} else if (!$secure && $_SERVER["SERVER_PORT"] == 443) {
			// Likewise if they aren't secure and reading an SSLed page, redirect them to non-SSL
			header("HTTP/1.1 301 Moved Permanently");
			header("Location: http://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"]);
			exit;
		}
		*/

		$this->cdn = $settings->get_setting($this->secure ? 'CDN_SSL' : 'CDN');
		$this->protocol = $this->secure ? 'https://' : 'http://';
		$this->secure_prefix = ($this->debug == 0) ? $settings->get_setting('webDir_SSL') : $settings->get_setting('webDir');

		
		$this->location_data = $session->get_location_data();

		// This is for apache specific logging, so we have to check to make sure we are
		// serving off apache before we can set the userid.
		if (function_exists('apache_note') && $session->get_user_id(TRUE)) {
			apache_note('user_id', $session->get_user_id(TRUE));
		}

		if ($session->get_user_id()) {
			$this->user = new User($session->get_user_id(), TRUE);
		}
		
		//https://blog.vnaik.com/posts/web-attacks.html
		if($settings->get_setting('force_https')){
			header('Strict-Transport-Security: max-age=3153600');
			header("Content-Security-Policy: default-src https: youtube.com vimeo.com fonts.googleapis.com fonts.gstatic.com; style-src https: 'unsafe-inline'; script-src https: 'unsafe-inline'");
			//header("Content-Security-Policy-Report-Only: default-src https:");
		}
		header('X-Frame-Options: SAMEORIGIN');
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: unsafe-url');

		$this->debug = $settings->get_setting('debug');
		if ($this->debug == 1) {
			$secure = FALSE;
			$this->secure = FALSE;
		}
		
		$this->theme_url = LibraryFunctions::get_theme_path('web');
		
		
		if(empty($options['noheader'])){
			//TRACKING
			if(!$_SESSION['permission'] || $_SESSION['permission'] == 0){
				if(!isset($options['is_404'])){
					$options['is_404'] = 0;
				}

				$session->save_visitor_event(1, $options['is_404']);
			}
		}
		
	}
	
	public static function get_public_menu(){
		return MultiPublicMenu::get_sorted_array();
	}

	public static function OutputGenericPublicPage($title, $header, $body, $options=array()) {
		$page = new PublicPage();
		$page->public_header(
			array_merge(
				array(
					'title' => $title,
					'showheader' => TRUE
				),
				$options));
		echo PublicPage::BeginPage($header);
	
		echo '<p>'.$body.'</p>';
		
		echo PublicPage::EndPage();
		$page->public_footer();
		exit;
	}
	
	public static function BeginPage($title='', $options=array()) {
		$output = '';
		if($title){
			$output .= '<h2>'.$title.'</h2>';
			if($options['subtitle']){
				$output .= '<p>'.$options['subtitle'].'</p>';
			}
			$output .= '';
		}
		return $output;
	}	

	public static function EndPage($options=array()) {
		$output = ''; 
		return $output;
	}	
	
	public function global_includes_top($options=array()){
		$settings = Globalvars::get_instance();
		?>
		<script src="<?php echo $this->theme_url; ?>/includes/jquery-3.4.1.min.js"></script>
		<script type="text/javascript" src="<?php echo $this->theme_url; ?>/includes/jquery.validate-1.9.1.js"></script>	
		<!--<link type="text/css" href="<?php echo $this->theme_url; ?>/includes/jquery-ui-1.7.custom_5.css" rel="stylesheet" />-->
		
		<!--<link type="text/css" href="<?php echo $this->theme_url; ?>/css/default_theme.css" rel="stylesheet" />
		<link type="text/css" href="<?php echo $this->theme_url; ?>/css/site_styles.css" rel="stylesheet" />-->
		
		<?php
		//CHECK TO SEE IF WE PASSED IN A PREVIEW IMAGE
		if(isset($options['preview_image_url']) && $options['preview_image_url']){
			//IF NO INCREMENT IS PROVIDED, USE 1
			if(!isset($options['preview_image_increment'])){
				!$options['preview_image_increment'] = 1;
			}
			echo '<meta property="og:image" content="'.$options['preview_image_url'].'?'.$options['preview_image_increment'].'" />';			
		}
		else{
			//IF NOT, USE THE DEFAULT ONE
			$preview_image_url = $settings->get_setting('preview_image');
			if($preview_image_url){
				echo '<meta property="og:image" content="'.$settings->get_setting('preview_image').'?'.$settings->get_setting('preview_image_increment').'" />';
			}
		}
		
		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}
	}

	public function public_header_common($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		
		if(!isset($options['is_404'])){
			$options['is_404'] = 0;
		}		
		
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();
		if($settings->get_setting('force_https')){
			header('Strict-Transport-Security: max-age=3153600');
			header("Content-Security-Policy: default-src https: youtube.com vimeo.com fonts.googleapis.com fonts.gstatic.com; style-src https: 'unsafe-inline'; script-src https: 'unsafe-inline'");
			//header("Content-Security-Policy-Report-Only: default-src https:");
		}
		header('X-Frame-Options: SAMEORIGIN');
		header('X-Content-Type-Options: nosniff');
		header('Referrer-Policy: unsafe-url');


		if(!isset($options['title']) || !$options['title']){
			$options['title'] = $settings->get_setting('site_name');
		}
		
		if(!isset($options['meta_description']) || !$options['meta_description']){
			$options['meta_description'] = $settings->get_setting('site_description');
		}
		
		if(empty($options['noheader']) && !$options['is_404'] && $options['title']){ 
			//TRACKING
			if(!$_SESSION['permission'] || $_SESSION['permission'] == 0){
				print_r($options);
				exit;
				$session->save_visitor_event(1, $options['is_404']);
			}
		}
		
		return $options;
	}

	public function public_footer($options=array()) {
		$session = SessionControl::get_instance();
		$session->clear_clearable_messages();
	}


	function tableheader($headers, $class='table cart-table', $id='table1'){
		echo '<table class="'.$class.'" id="'.$id.'" cellspacing="0">
			<thead><tr>';

		foreach ($headers as $value) {
			printf('<th scope="col" abbr="%s">%s</th>', $value, $value);
		}
		echo '</tr></thead><tbody>';
	}

	function disprow($dataarray){

		echo '<tr>';

		foreach ($dataarray as $value) {
			if ($value == "") {
				$value = "&nbsp";
			}


			printf('<td>%s</td>', $value);

		}
		echo "</tr>\n";
		$this->rowcount++;
	}

	function endtable(){
		echo '</tbody></table>';
	}
}

?>
