<?php
require_once('Globalvars.php');
require_once('SessionControl.php');
require_once('ShoppingCart.php');

$settings = Globalvars::get_instance();
$siteDir = $settings->get_setting('siteDir');
require_once($siteDir . '/data/users_class.php');
require_once($siteDir . '/data/public_menus_class.php');

class PublicPageMaster {

	protected $rowcount;

	protected static $header_defaults = array(
		//'title' => '',
		'showheader' => TRUE,
		'noindex' => FALSE,
		'nofollow' => FALSE,
	);

	protected static $footer_defaults = array(
		'track' => TRUE,
	);
	
	
	//SECURE ARGUMENT HAS BEEN DEPRECATED
	public function __construct($secure=FALSE) {
		$this->rowcount = 0;

		$settings = Globalvars::get_instance();
		$session = SessionControl::get_instance();
		$this->debug_css = $settings->get_setting('debug_css');


		
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
		// Check protocol_mode for HTTPS redirect
		$protocol_mode = $settings->get_setting('protocol_mode', false, true); // fail_silently = true
		if($protocol_mode === 'https_redirect'){
			require_once('LibraryFunctions.php');
			if(!LibraryFunctions::isSecure()){
				$location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
				header('HTTP/1.1 301 Moved Permanently');
				header('Location: ' . $location);
				exit;
			}
			
			//header('Strict-Transport-Security: max-age=3153600');
			//header("Content-Security-Policy: default-src https: youtube.com vimeo.com fonts.googleapis.com fonts.gstatic.com; style-src https: 'unsafe-inline'; script-src https: 'unsafe-inline'");
			//header("Content-Security-Policy-Report-Only: default-src https:");
		}
		//header('X-Frame-Options: SAMEORIGIN');
		//header('X-Content-Type-Options: nosniff');
		//header('Referrer-Policy: unsafe-url');
		
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

	public static function BeginPanel($options=array()) {
		$output = ''; 
		return $output;
	}



	public static function EndPanel($options=array()) {
		$output = '
		'; 
		return $output;
	}
	
	static function tab_menu($tab_menus, $current=NULL){
	
		
		$output = '';
		

		$output .= '<div class="container"><div class="filter-menu filter-menu-active">

								';
									foreach($tab_menus as $name => $link){
										if($name == 'Edit Address' || $name == 'Edit Phone Number'){
											continue;
										}
										if($name == $current){
											$output .= '
											<button data-filter="*" class="tab-btn active" type="button">'.$name.'</button>
											
											';
										}
										else{
											$output .= '
											<a href="'.$link.'"><button data-filter=".cat5" class="tab-btn" type="button">'.$name.'</button></a>
											
											';
										}
									}
                             $output .= '       
                                
                        </div></div>';
		

		
		return $output;
		
	}
	
	public function global_includes_top($options=array()){
		$settings = Globalvars::get_instance();

		
		
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
		// Check protocol_mode for HTTPS redirect (duplicate check for safety)
		$protocol_mode = $settings->get_setting('protocol_mode', false, true); // fail_silently = true
		if($protocol_mode === 'https_redirect'){
			require_once('LibraryFunctions.php');
			if(!LibraryFunctions::isSecure()){
				$location = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
				header('HTTP/1.1 301 Moved Permanently');
				header('Location: ' . $location);
				exit;
			}
			
			//header('Strict-Transport-Security: max-age=3153600');
			//header("Content-Security-Policy: default-src https: youtube.com vimeo.com fonts.googleapis.com fonts.gstatic.com; style-src https: 'unsafe-inline'; script-src https: 'unsafe-inline'");
			//header("Content-Security-Policy-Report-Only: default-src https:");
		}
		//header('X-Frame-Options: SAMEORIGIN');
		//header('X-Content-Type-Options: nosniff');
		//header('Referrer-Policy: unsafe-url');


		if(!isset($options['title']) || !$options['title']){
			$options['title'] = $settings->get_setting('site_name');
		}
		
		if(!isset($options['meta_description']) || !$options['meta_description']){
			$options['meta_description'] = $settings->get_setting('site_description');
		}

		if(empty($options['noheader']) && !$options['is_404'] && $options['is_valid_page'] ){ 
			//TRACKING
			if(!$_SESSION['permission'] || $_SESSION['permission'] == 0){
				$session->save_visitor_event(1, $options['is_404']);
			}
		}
		
		return $options;
	}
	
	static function alert($title, $content, $type){
		if($type == 'error'){
			$output = '<div class="alert alert-danger" role="alert">
			  <h4 class="alert-heading">'.$title.'</h4>
			  <p>'.$content.'</p>
			</div>';
		}
		else if($type == 'warn'){
			$output = '<div class="alert alert-warning" role="alert">
			  <h4 class="alert-heading">'.$title.'</h4>
			  <p>'.$content.'</p>
			</div>';	
		}
		else if($type == 'success'){
			$output = '<div class="alert alert-success" role="alert">
			  <h4 class="alert-heading">'.$title.'</h4>
			  <p>'.$content.'</p>
			</div>';
		}
		
		return $output;
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
