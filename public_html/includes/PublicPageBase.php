<?php
require_once('PathHelper.php');
require_once('Globalvars.php');
require_once('SessionControl.php');
require_once('ShoppingCart.php');

PathHelper::requireOnce('data/users_class.php');
PathHelper::requireOnce('data/public_menus_class.php');

class PublicPageBase {

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
		
		// Auto-inject admin bar CSS in head
		if ($this->should_show_admin_bar()) {
			$this->render_admin_bar_css();
			// Add JavaScript to inject admin bar HTML after page loads
			echo '<script>
			document.addEventListener("DOMContentLoaded", function() {
				document.body.classList.add("joinery-admin-bar-active");
				var adminBarHtml = ' . json_encode($this->get_admin_bar_html()) . ';
				document.body.insertAdjacentHTML("afterbegin", adminBarHtml);
				
				// Add theme switcher functionality
				window.joineryAdminBarSwitchTheme = function(theme) {
					fetch("/ajax/theme_switch_ajax", {
						method: "POST",
						headers: {
							"Content-Type": "application/x-www-form-urlencoded",
						},
						body: "theme=" + encodeURIComponent(theme),
						credentials: "same-origin"
					})
					.then(response => {
						if (!response.ok) {
							throw new Error("Network response was not ok: " + response.status);
						}
						return response.text();
					})
					.then(text => {
						try {
							const data = JSON.parse(text);
							if (data.success) {
								window.location.reload();
							} else {
								alert("Failed to switch theme: " + (data.message || "Unknown error"));
							}
						} catch (e) {
							console.error("Response was not JSON:", text);
							alert("Server error: " + text.substring(0, 200));
						}
					})
					.catch(error => {
						console.error("Theme switch error:", error);
						alert("Error switching theme: " + error.message);
					});
				};
			});
			</script>';
		}
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

	/**
	 * Auto-inject admin bar after <body> tag - call this early in body content
	 */
	public function auto_inject_admin_bar() {
		if ($this->should_show_admin_bar()) {
			echo '<script>document.body.classList.add("joinery-admin-bar-active");</script>';
			$this->render_admin_bar();
		}
	}

	/**
	 * Get admin bar HTML as string for JavaScript injection
	 */
	private function get_admin_bar_html() {
		if (!$this->should_show_admin_bar()) {
			return '';
		}
		
		ob_start();
		$this->render_admin_bar();
		$html = ob_get_clean();
		return $html;
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

	/**
	 * Check if admin bar should be displayed
	 */
	protected function should_show_admin_bar() {
		$session = SessionControl::get_instance();
		return ($session->get_permission() == 10);
	}

	/**
	 * Render the admin bar CSS
	 */
	protected function render_admin_bar_css() {
		if (!$this->should_show_admin_bar()) {
			return;
		}
		?>
		<style id="joinery-admin-bar-css">
			/* Admin Bar Styles */
			body.joinery-admin-bar-active {
				margin-top: 32px !important;
			}
			
			/* Fix for Falcon theme sticky navbar */
			body.joinery-admin-bar-active .navbar-top {
				top: 32px !important;
			}
			
			/* Fix for any other sticky/fixed elements */
			body.joinery-admin-bar-active .sticky-top {
				top: 32px !important;
			}
			
			body.joinery-admin-bar-active .fixed-top {
				top: 32px !important;
			}
			
			#joinery-admin-bar {
				background: #23282d !important;
				height: 32px !important;
				position: fixed !important;
				top: 0 !important;
				left: 0 !important;
				right: 0 !important;
				z-index: 99999 !important;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
				font-size: 13px !important;
				line-height: 32px !important;
				box-shadow: 0 1px 0 rgba(0,0,0,.1) !important;
			}
			
			#joinery-admin-bar * {
				margin: 0 !important;
				padding: 0 !important;
				box-sizing: border-box !important;
				color: #eee !important;
				text-decoration: none !important;
			}
			
			.joinery-admin-bar-left,
			.joinery-admin-bar-right {
				display: inline-block !important;
			}
			
			.joinery-admin-bar-left {
				float: left !important;
			}
			
			.joinery-admin-bar-right {
				float: right !important;
			}
			
			.joinery-admin-bar-logo,
			.joinery-admin-bar-site-name,
			.joinery-admin-bar-new,
			.joinery-admin-bar-template,
			.joinery-admin-bar-admin,
			.joinery-admin-bar-user {
				display: inline-block !important;
				padding: 0 15px !important;
				height: 32px !important;
				line-height: 32px !important;
			}
			
			.joinery-admin-bar-logo {
				background: #32373c !important;
				font-weight: bold !important;
				width: 32px !important;
				text-align: center !important;
			}
			
			#joinery-admin-bar a:hover {
				background: #32373c !important;
				color: #00b9eb !important;
			}
			
			.joinery-admin-bar-dropdown {
				display: inline-block !important;
				position: relative !important;
			}
			
			.joinery-admin-bar-dropdown-content {
				display: none !important;
				position: absolute !important;
				background: #32373c !important;
				min-width: 160px !important;
				box-shadow: 0 2px 5px rgba(0,0,0,0.2) !important;
				top: 32px !important;
				left: 0 !important;
			}
			
			.joinery-admin-bar-dropdown:hover .joinery-admin-bar-dropdown-content {
				display: block !important;
			}
			
			.joinery-admin-bar-dropdown-content a {
				display: block !important;
				padding: 0 20px !important;
				line-height: 32px !important;
			}
			
			.joinery-admin-bar-dropdown-content a:hover {
				background: #23282d !important;
			}
			
			.joinery-admin-bar-new {
				cursor: pointer !important;
			}
			
			.joinery-admin-bar-new:hover {
				background: #32373c !important;
				color: #00b9eb !important;
			}
			
			/* Theme switcher styles */
			.joinery-admin-bar-theme-dropdown {
				display: inline-block !important;
				position: relative !important;
			}
			
			.joinery-admin-bar-theme-current {
				cursor: pointer !important;
				display: inline-block !important;
				padding: 0 15px !important;
			}
			
			.joinery-admin-bar-theme-current:hover {
				background: #32373c !important;
				color: #00b9eb !important;
			}
			
			.joinery-admin-bar-theme-current:after {
				content: " ▼" !important;
				font-size: 10px !important;
				margin-left: 5px !important;
			}
			
			/* Responsive adjustments */
			@media screen and (max-width: 768px) {
				.joinery-admin-bar-template {
					display: none !important;
				}
			}
		</style>
		<?php
	}

	/**
	 * Render the admin bar HTML
	 */
	public function render_admin_bar() {
		if (!$this->should_show_admin_bar()) {
			return;
		}
		
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();
		$user = new User($session->get_user_id());
		
		$site_name = $settings->get_setting('site_name', true, true) ?: 'Joinery';
		$theme_template = $settings->get_setting('theme_template', true, true) ?: 'default';
		$user_name = $user->display_name();
		$permission = $session->get_permission();
		
		// Get available themes
		$theme_dir = PathHelper::getBasePath() . '/theme/';
		$themes = array();
		if (is_dir($theme_dir)) {
			$dirs = scandir($theme_dir);
			foreach ($dirs as $dir) {
				if ($dir != '.' && $dir != '..' && is_dir($theme_dir . $dir) && $dir != 'archive') {
					$themes[] = $dir;
				}
			}
			sort($themes);
		}
		
		?>
		<div id="joinery-admin-bar">
			<div class="joinery-admin-bar-left">
				<span class="joinery-admin-bar-logo">J</span>
				<a href="/" class="joinery-admin-bar-site-name"><?php echo htmlspecialchars($site_name); ?></a>
				<div class="joinery-admin-bar-dropdown">
					<span class="joinery-admin-bar-new">+ New</span>
					<div class="joinery-admin-bar-dropdown-content">
						<a href="/admin/admin_page_edit">Page</a>
						<a href="/admin/admin_post_edit">Post</a>
						<a href="/admin/admin_user_add">User</a>
						<a href="/admin/admin_file_upload">File</a>
					</div>
				</div>
			</div>
			<div class="joinery-admin-bar-right">
				<div class="joinery-admin-bar-theme-dropdown joinery-admin-bar-dropdown">
					<span class="joinery-admin-bar-theme-current">Theme: <?php echo htmlspecialchars($theme_template); ?></span>
					<div class="joinery-admin-bar-dropdown-content">
						<?php foreach ($themes as $theme): ?>
							<a href="#" onclick="joineryAdminBarSwitchTheme('<?php echo htmlspecialchars($theme); ?>'); return false;" 
							   <?php echo ($theme == $theme_template) ? 'style="font-weight: bold !important;"' : ''; ?>>
								<?php echo htmlspecialchars($theme); ?>
								<?php echo ($theme == $theme_template) ? ' ✓' : ''; ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
				<a href="/admin/admin_users" class="joinery-admin-bar-admin">Dashboard</a>
				<span class="joinery-admin-bar-user"><?php echo htmlspecialchars($user_name); ?> (<?php echo $permission; ?>)</span>
			</div>
		</div>
		<?php
	}
}

?>
