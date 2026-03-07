<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/ThemeHelper.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/public_menus_class.php'));

abstract class PublicPageBase {

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
	
	/**
	 * Get a FormWriter instance appropriate for this page
	 * Uses PathHelper's standard theme/plugin override pattern for cleaner view code
	 *
	 * @param string $form_id The form identifier (default: 'form1')
	 * @param string $version The FormWriter version: 'v1' (default) or 'v2'
	 * @param array $options Configuration options for V2 FormWriter (model, action, etc.)
	 * @return FormWriter|FormWriterV2Bootstrap The appropriate FormWriter instance
	 */
	public function getFormWriter($form_id = 'form1', $options = []) {
        // FormWriter v2 is now the default and only supported version
        require_once(PathHelper::getIncludePath('includes/FormWriterV2Bootstrap.php'));
        return new FormWriterV2Bootstrap($form_id, $options);
    }
	
	public static function get_public_menu(){
		require_once(PathHelper::getIncludePath('data/public_menus_class.php'));
		return MultiPublicMenu::get_sorted_array();
	}

	/**
	 * Get comprehensive menu data for all menu types
	 * Consolidates menu logic from various theme implementations
	 *
	 * @return array Complete menu data structure
	 */
	public function get_menu_data() {
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();

		// Initialize return array
		$menu_data = [
			'main_menu' => [],
			'user_menu' => [],
			'cart' => [],
			'notifications' => [],
			'site_info' => [],
			'mobile_menu' => []
		];

		// 1. Process main navigation menu from database
		try {
			$menus = self::get_public_menu();

			// Filter out invalid menu items - only show parent menu items that are properly configured
			$filtered_menus = [];
			foreach ($menus as $menu_item) {
				if (isset($menu_item['parent']) && $menu_item['parent'] === true) {
					$filtered_menus[] = $menu_item;
				}
			}

			$menu_data['main_menu'] = $filtered_menus;

			// Add current page detection
			$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
			foreach ($menu_data['main_menu'] as &$menu_item) {
				$menu_item['is_active'] = ($menu_item['link'] === $current_path);
				if (!empty($menu_item['submenu'])) {
					foreach ($menu_item['submenu'] as &$submenu_item) {
						$submenu_item['is_active'] = ($submenu_item['link'] === $current_path);
						if ($submenu_item['is_active']) {
							$menu_item['is_active'] = true; // Parent is active if child is
						}
					}
				}
			}
		} catch (Exception $e) {
			$menu_data['main_menu'] = [];
		}

		// 2. Build user menu based on login state
		$is_logged_in = $session->is_logged_in();
		$menu_data['user_menu'] = [
			'is_logged_in' => $is_logged_in,
			'user_id' => $is_logged_in ? $session->get_user_id() : null,
			'user_name' => null,
			'display_name' => null,
			'permission_level' => $session->get_permission(),
			'avatar_url' => null,
			'login_link' => '/login',
			'register_link' => $settings->get_setting('register_active', false, true) ? '/register' : null,
			'items' => []
		];

		if ($is_logged_in) {
			// Get user information
			if ($session->get_user_id()) {
				try {
					$user = new User($session->get_user_id(), TRUE);
					$menu_data['user_menu']['user_name'] = $user->get('usr_email');
					$menu_data['user_menu']['display_name'] = $user->display_name();

					// Default avatar path
					$menu_data['user_menu']['avatar_url'] = PathHelper::getThemeFilePath('avatar.png', 'assets/images', 'web');
				} catch (Exception $e) {
					// User load failed, use session data only
					$menu_data['user_menu']['display_name'] = 'User';
				}
			}

			// Logged in menu items - only include items that should be shown
			$menu_data['user_menu']['items'] = [
				// Navigation
				[
					'label' => 'Home',
					'link' => '/',
					'icon' => 'home'
				],
				[
					'label' => 'My Profile',
					'link' => '/profile',
					'icon' => 'user'
				],

				// E-commerce related
				[
					'label' => 'Orders',
					'link' => '/profile#orders',
					'icon' => 'shopping-bag'
				],
				[
					'label' => 'Subscriptions',
					'link' => '/profile/subscriptions',
					'icon' => 'refresh'
				],

				// Event related
				[
					'label' => 'My Events',
					'link' => '/profile#events',
					'icon' => 'calendar'
				],
				[
					'label' => 'Event Sessions',
					'link' => '/profile/event_sessions',
					'icon' => 'clock'
				],

				// Authentication
				[
					'label' => 'Sign out',
					'link' => '/logout',
					'icon' => 'sign-out'
				]
			];

			// Add admin items based on permission level (checked here, not in array)
			$permission = $session->get_permission();
			if ($permission >= 5) {
				// Insert admin items before logout
				array_splice($menu_data['user_menu']['items'], -1, 0, [
					[
						'label' => 'Admin Dashboard',
						'link' => '/admin/admin_users',
						'icon' => 'dashboard'
					]
				]);

				// Advanced admin items for permission > 5
				if ($permission > 5) {
					array_splice($menu_data['user_menu']['items'], -1, 0, [
						[
							'label' => 'Admin Settings',
							'link' => '/admin/admin_settings',
							'icon' => 'wrench'
						],
						[
							'label' => 'Admin Utilities',
							'link' => '/admin/admin_utilities',
							'icon' => 'tools'
						]
					]);
				}

				// Help available to all admin users
				array_splice($menu_data['user_menu']['items'], -1, 0, [
					[
						'label' => 'Admin Help',
						'link' => '/admin/admin_help',
						'icon' => 'question-circle'
					]
				]);
			}
		} else {
			// Logged out menu items
			$register_active = $settings->get_setting('register_active', false, true);

			$menu_data['user_menu']['items'] = [
				[
					'label' => 'Home',
					'link' => '/',
					'icon' => 'home'
				],
				[
					'label' => 'Sign in',
					'link' => '/login',
					'icon' => 'sign-in'
				],
				[
					'label' => 'Forgot Password',
					'link' => '/password-reset-1',
					'icon' => 'key'
				]
			];

			if ($register_active) {
				$menu_data['user_menu']['items'][] = [
					'label' => 'Sign up',
					'link' => '/register',
					'icon' => 'user-plus'
				];
			}
		}

		// 3. Process shopping cart data
		// Shopping cart is always available - no setting controls it
		$cart = null;
		$item_count = 0;

		try {
			$cart = $session->get_shopping_cart();
			if ($cart) {
				$item_count = $cart->count_items();
			}
		} catch (Exception $e) {
			// Cart not available
			$item_count = 0;
		}

		$menu_data['cart'] = [
			'enabled' => true, // Cart is always enabled in the system
			'count' => $item_count, // Primary count field for themes
			'item_count' => $item_count,
			'total_items' => $item_count, // Could be different if we track quantity
			'subtotal' => null, // Future: calculate subtotal
			'link' => '/cart',
			'has_items' => ($item_count > 0)
		];

		// 4. Notifications (placeholder for future implementation)
		// No notifications system exists yet
		$menu_data['notifications'] = [
			'enabled' => false,
			'count' => 0,
			'unread_count' => 0,
			'items' => []
		];

		// 5. Site information
		$menu_data['site_info'] = [
			'site_name' => $settings->get_setting('site_name', 'Joinery', true),
			'site_description' => $settings->get_setting('site_description', '', true),
			'logo_link' => $settings->get_setting('logo_link', null, true),
			'theme' => $settings->get_setting('theme_template', 'falcon', true),
			'register_enabled' => $settings->get_setting('register_active', false, true)
		];

		// 6. Mobile menu configuration
		$menu_data['mobile_menu'] = [
			'enabled' => true // Always enabled by default
		];

		return $menu_data;
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
		$output = '<div style="max-width: 1140px; margin: 0 auto; padding: 2rem 1rem;">';
		if($title){
			$output .= '<h2>'.$title.'</h2>';
			if(isset($options['subtitle']) && $options['subtitle']){
				$output .= '<p>'.$options['subtitle'].'</p>';
			}
		}
		return $output;
	}

	public static function EndPage($options=array()) {
		return '</div>';
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
	
	/**
	 * Generate canonical URL for SEO
	 * Strips pagination parameters and uses configured domain
	 *
	 * @return string Canonical URL
	 */
	private function get_canonical_url() {
		$settings = Globalvars::get_instance();

		// Get current path without query string
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

		// Define parameters to strip (these don't create unique content)
		$strip_params = ['offset', 'page', 'page_offset', 'p', '__route'];

		// Get all query parameters except those to strip
		$filtered_params = [];
		foreach ($_GET as $key => $value) {
			if (!in_array($key, $strip_params)) {
				$filtered_params[$key] = $value;
			}
		}

		// Get domain from webDir setting (contains domain only, e.g. 'example.com')
		$webDir = $settings->get_setting('webDir');
		$canonical_domain = 'https://' . $webDir;

		// Build canonical URL
		$canonical = $canonical_domain . $path;

		// Add back non-pagination query parameters if any
		if (!empty($filtered_params)) {
			$canonical .= '?' . http_build_query($filtered_params);
		}

		return $canonical;
	}

	public function global_includes_top($options=array()){
		$settings = Globalvars::get_instance();
		$webDir = $settings->get_setting('webDir');

		// Output canonical tag for SEO
		$canonical_url = $this->get_canonical_url();
		echo '<link rel="canonical" href="' . htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') . '">' . "\n";

		// Open Graph meta tags
		$og_title = !empty($options['title']) ? $options['title'] : $settings->get_setting('site_name');
		$og_description = !empty($options['meta_description']) ? $options['meta_description'] : $settings->get_setting('site_description');
		$og_type = !empty($options['og_type']) ? $options['og_type'] : 'website';
		$og_site_name = $settings->get_setting('site_name');

		// Strip HTML and truncate description
		if ($og_description) {
			$og_description = strip_tags($og_description);
			if (mb_strlen($og_description) > 200) {
				$og_description = mb_substr($og_description, 0, 197) . '...';
			}
		}

		echo '<meta property="og:title" content="' . htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		if ($og_description) {
			echo '<meta property="og:description" content="' . htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}
		echo '<meta property="og:url" content="' . htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		echo '<meta property="og:type" content="' . htmlspecialchars($og_type, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		if ($og_site_name) {
			echo '<meta property="og:site_name" content="' . htmlspecialchars($og_site_name, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}

		// og:image - page-specific or site default
		if(isset($options['preview_image_url']) && $options['preview_image_url']){
			$og_image = $options['preview_image_url'];
			if (strpos($og_image, 'http') !== 0) {
				$og_image = 'https://' . $webDir . $og_image;
			}
			$increment = isset($options['preview_image_increment']) ? $options['preview_image_increment'] : 1;
			echo '<meta property="og:image" content="' . htmlspecialchars($og_image, ENT_QUOTES, 'UTF-8') . '?' . htmlspecialchars($increment, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}
		else{
			$preview_image_url = $settings->get_setting('preview_image');
			if($preview_image_url){
				if (strpos($preview_image_url, 'http') !== 0) {
					$preview_image_url = 'https://' . $webDir . $preview_image_url;
				}
				echo '<meta property="og:image" content="' . htmlspecialchars($preview_image_url, ENT_QUOTES, 'UTF-8') . '?' . htmlspecialchars($settings->get_setting('preview_image_increment'), ENT_QUOTES, 'UTF-8') . '" />' . "\n";
			}
		}

		// Base CSS/JS provides framework-agnostic styles and interactions for fallback views
		// Themes that include Bootstrap will naturally override these
		echo '<link rel="stylesheet" href="/assets/css/base.css">' . "\n";
		echo '<script defer src="/assets/js/base.js"></script>' . "\n";

		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}

		// Render tracking code (wrapped for consent if enabled)
		echo $this->renderTrackingCode();

		// Render cookie consent banner (if enabled) - JS waits for DOMContentLoaded
		echo $this->renderConsentBanner();
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
	 * Render tracking code with consent wrapping
	 * Should be called in the footer before closing </body> tag
	 *
	 * @return string Tracking code wrapped for consent compliance
	 */
	public function renderTrackingCode() {
		$settings = Globalvars::get_instance();
		$tracking_code = $settings->get_setting('tracking_code');

		if (empty($tracking_code)) return '';

		require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));
		$consent = ConsentHelper::get_instance();
		return $consent->wrapTrackingCode($tracking_code, 'analytics');
	}

	/**
	 * Render cookie consent banner
	 * Should be called at the end of the page, before closing </body> tag
	 *
	 * @return string Consent banner HTML/CSS/JS
	 */
	public function renderConsentBanner() {
		require_once(PathHelper::getIncludePath('includes/ConsentHelper.php'));
		$consent = ConsentHelper::get_instance();
		return $consent->renderConsentBanner();
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


	function tableheader($headers, $options=array(), $pager=NULL){
		// Store options for use in endtable
		$this->current_table_options = $options;

		$this->begin_box($options);
		
		if(!$pager){
			$pager = new Pager();
		}

		$sortoptions = null;
		if(isset($options['sortoptions'])){
			$sortoptions = $options['sortoptions'];
		}
		
		$filteroptions = null;
		if(isset($options['filteroptions'])){
			$filteroptions = $options['filteroptions'];
		}
		
		$search_on = null;
		if(isset($options['search_on'])){
			$search_on = $options['search_on'];
		}

		// Get theme-specific CSS classes
		$css = $this->getTableClasses();

		echo '<div class="row justify-content-end justify-content-end gx-3 gy-0 px-3">';

		if($sortoptions){
			echo '<div class="col-sm-auto">';
			printf('<form method="get" ACTION="%s">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('sort', 'sdirection'));
			echo '<label for="'.$pager->prefix().'sort'.'">Sort: </label><select name="'.$pager->prefix().'sort'.'">';
			foreach ($sortoptions as $key => $value) {
				if($pager->get_sort() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';
			

			echo '<label for="'.$pager->prefix().'sdirection'.'"> </label><select name="'.$pager->prefix().'sdirection'.'">';
			$diroptions = array('Descending'=>'DESC', 'Ascending'=>'ASC');
			foreach ($diroptions as $key => $value) {
				if($pager->sort_direction() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';

						
			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
			}

			echo '<input type="submit" value="sort" /></form>'; 
			echo '</div>';
		}

		if($filteroptions){
			echo '<div class="col-sm-auto">';
			printf('<form method="get" ACTION="%s">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('filter'));
			echo '<label for="'.$pager->prefix().'filter'.'">Show: </label><select name="'.$pager->prefix().'filter'.'">';
			foreach ($filteroptions as $key => $value) {
				if($pager->get_filter() == $value){
					echo "<option value='$value' selected=selected>$key";
				}
				else{
					echo "<option value='$value'>$key";
				}
			}
			echo '</select>';

						
			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
			}

			echo '<input type="submit" value="submit" /></form>'; 
			echo '</div>';
		}

		if($search_on){
			echo '<div class="col-sm-auto">';
			$formwriter = $this->getFormWriter('search_form');

			echo $formwriter->begin_form("search_form", "get", $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('searchterm'));
			echo '<label for="searchterm">Search: </label>
						  <input name="'.$pager->prefix().'searchterm" id="'.$pager->prefix().'searchterm" value="'.$pager->search_term().'" size="20" type="text" class="textInput" maxlength="">';	
			
			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
			}	

			echo '<input type="submit" value="Search" />';
			echo $formwriter->end_form();
			echo '</div>';
		}

		echo '</div>';

		// Use theme-specific wrapper and table classes
		$wrapperClass = isset($css['wrapper']) ? $css['wrapper'] : 'table-responsive';
		$tableClass = isset($css['table']) ? $css['table'] : 'table';
		
		echo '<div class="'.$wrapperClass.'">
	  <table class="'.$tableClass.'">
			<tr>';

			foreach ($headers as $value) {
				echo '<th>'.$value.'</th>';
			}

		echo '</tr>';
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
	}

	function endtable($pager=NULL){
		if(!$pager){
			$pager = new Pager();
		}
		echo '</table></div>';

		// Get stored options to check if we're in a card
		$options = isset($this->current_table_options) ? $this->current_table_options : array();
		$use_card = isset($options['card']) && $options['card'] === true;

		//PAGE
		if($pager->num_records()){
			// Add padding for card layout
			$padding_class = $use_card ? 'px-3 pb-3' : '';
			$text_padding_class = $use_card ? 'ps-3' : '';
			echo '<div class="d-flex align-items-center justify-content-center position-relative mt-3 ' . $padding_class . '">';
						echo '<div class="position-absolute start-0 mb-0 fs-10 ' . $text_padding_class . '"> '.$pager->num_records().' records, Page '.$pager->current_page() .' of '.$pager->total_pages().'</div>';

						echo '<div><div class="d-flex justify-content-center mt-3">';

						if($pager->num_records() > $pager->num_per_page()){
							if($page_number = $pager->is_valid_page('-10')){
								echo '<a href="'.$pager->get_url($page_number).'"><button class="btn btn-sm btn-falcon-default me-1" type="button" title="Previous 10" data-list-pagination="prev"><svg class="svg-inline--fa fa-chevron-left fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-left" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M34.52 239.03L228.87 44.69c9.37-9.37 24.57-9.37 33.94 0l22.67 22.67c9.36 9.36 9.37 24.52.04 33.9L131.49 256l154.02 154.75c9.34 9.38 9.32 24.54-.04 33.9l-22.67 22.67c-9.37 9.37-24.57 9.37-33.94 0L34.52 272.97c-9.37-9.37-9.37-24.57 0-33.94z"></path></svg><!-- <span class="fas fa-chevron-left"></span> Font Awesome fontawesome.com --></button></a>';
							}
							else{
								echo '<button class="btn btn-sm btn-falcon-default me-1 disabled" type="button" title="Previous 10" data-list-pagination="prev" disabled=""><svg class="svg-inline--fa fa-chevron-left fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-left" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M34.52 239.03L228.87 44.69c9.37-9.37 24.57-9.37 33.94 0l22.67 22.67c9.36 9.36 9.37 24.52.04 33.9L131.49 256l154.02 154.75c9.34 9.38 9.32 24.54-.04 33.9l-22.67 22.67c-9.37 9.37-24.57 9.37-33.94 0L34.52 272.97c-9.37-9.37-9.37-24.57 0-33.94z"></path></svg><!-- <span class="fas fa-chevron-left"></span> Font Awesome fontawesome.com --></button>';
							}

							echo '<ul class="pagination mb-0">';
							for($x=4; $x>=1;$x--){
								if($page_number = $pager->is_valid_page('-'.$x)){
									echo '<a href="'.$pager->get_url($page_number).'"><button class="page btn btn-sm btn-falcon-default" type="button" data-i="2" data-page="5">'.$page_number.'</button></a> ';
								}
							}

							echo '<li class="active"><button class="page btn btn-sm btn-falcon-default disabled" type="button" disabled="">'.$pager->current_page().'</button></li> ';

							for($x=1; $x<=4;$x++){
								if($page_number = $pager->is_valid_page('+'.$x)){
									echo '<a href="'.$pager->get_url($page_number).'"><button class="page btn btn-sm btn-falcon-default" type="button" data-i="2" data-page="5">'.$page_number.'</button></a> ';
								}
							}
							echo '</ul>';

							if($page_number = $pager->is_valid_page('+10')){
								echo '<a href="'.$pager->get_url($page_number).'"><button class="btn btn-sm btn-falcon-default ms-1" type="button" title="Next 10" data-list-pagination="next"><svg class="svg-inline--fa fa-chevron-right fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-right" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"></path></svg><!-- <span class="fas fa-chevron-right"></span> Font Awesome fontawesome.com --></button></a>';
							}
							else{
								echo '<button class="btn btn-sm btn-falcon-default ms-1 disabled" type="button" title="Next 10" data-list-pagination="next" disabled=""><svg class="svg-inline--fa fa-chevron-right fa-w-10" aria-hidden="true" focusable="false" data-prefix="fas" data-icon="chevron-right" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512" data-fa-i2svg=""><path fill="currentColor" d="M285.476 272.971L91.132 467.314c-9.373 9.373-24.569 9.373-33.941 0l-22.667-22.667c-9.357-9.357-9.375-24.522-.04-33.901L188.505 256 34.484 101.255c-9.335-9.379-9.317-24.544.04-33.901l22.667-22.667c9.373-9.373 24.569-9.373 33.941 0L285.475 239.03c9.373 9.372 9.373 24.568.001 33.941z"></path></svg><!-- <span class="fas fa-chevron-right"></span> Font Awesome fontawesome.com --></button>';
							}
						}


			echo '</div></div>';
			echo '</div>';

		}

		// Pass stored options to end_box
		$options = isset($this->current_table_options) ? $this->current_table_options : array();
		$this->end_box($options);
	}

	function begin_box($options=NULL){
		if(!is_array($options)){
			$options = array();
		}

		// Check if card wrapping is requested
		$use_card = isset($options['card']) && $options['card'] === true;

		if ($use_card) {
			echo '<div class="card mb-3">';

			// Add card header if title is provided
			if (!empty($options['title'])) {
				echo '<div class="card-header bg-body-tertiary">';
				echo '<h6 class="mb-0">' . htmlspecialchars($options['title']) . '</h6>';
				echo '</div>';
			}

			echo '<div class="card-body p-0">';
		} else {
			echo '<div>';
		}

		$this->dropdown_or_buttons($options);
	}

	function end_box($options=NULL){
		if(!is_array($options)){
			$options = array();
		}

		// Check if card wrapping was used
		$use_card = isset($options['card']) && $options['card'] === true;

		if ($use_card) {
			echo '</div>'; // Close card-body
			echo '</div>'; // Close card
		} else {
			echo '</div>';
		}
	}

	function dropdown_or_buttons($options=array()){
		if(!is_array($options)){
			$options = array();
		}

		if(!isset($options['options_label'])){
			$options['options_label'] = 'Options';
		}		

		if(isset($options['altlinks']) && is_array($options['altlinks'])){
			echo '<div class="row justify-content-end justify-content-end gx-3 gy-0 px-3"><div class="col-sm-auto">';
			if(count($options['altlinks']) > 2){
				echo '<div class="dropdown font-sans-serif d-inline-block mb-2"><button class="btn btn-falcon-default dropdown-toggle" id="dropdownMenuButton" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.$options['options_label'].'</button><div class="dropdown-menu dropdown-menu-end py-0" aria-labelledby="dropdownMenuButton">';
				foreach($options['altlinks'] as $label=>$link){
					echo '<a href="'.$link.'" class="dropdown-item">'.$label.'</a></li>';
				}	
				echo '</div></div>';	    
										
			}
			else if(count($options['altlinks']) > 0){
				
				foreach($options['altlinks'] as $label=>$link){
					echo '<a href="'.$link.'"><button class="btn btn-outline-secondary me-1 mb-1" type="button">'.$label.'</button></a>';
				}
				
			}
			echo '</div></div>';
		}			
	}

	/**
	 * Get theme-specific CSS classes for table styling
	 * @return array Array of CSS class mappings
	 */
	abstract protected function getTableClasses();

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
			
			/* Fix for Falcon theme admin sidebar */
			body.joinery-admin-bar-active .navbar-vertical {
				top: 32px !important;
				height: calc(100vh - 32px) !important;
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
				padding-right: 10px !important;
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
			
			#joinery-admin-bar .joinery-admin-bar-user {
				padding-right: 10px !important;
			}
			
			.joinery-admin-bar-logo {
				background: #32373c !important;
				font-weight: bold !important;
				width: 32px !important;
				text-align: center !important;
			}
			
			#joinery-admin-bar > div > a {
				padding: 0 15px !important;
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
				padding: 4px 0 !important;
			}
			
			.joinery-admin-bar-dropdown:hover .joinery-admin-bar-dropdown-content {
				display: block !important;
			}
			
			#joinery-admin-bar .joinery-admin-bar-dropdown-content a {
				display: block !important;
				padding: 3px 12px !important;
				line-height: 20px !important;
				white-space: nowrap !important;
			}
			
			#joinery-admin-bar .joinery-admin-bar-dropdown-content a:hover {
				background: #23282d !important;
			}
			
			.joinery-admin-bar-new {
				cursor: pointer !important;
				padding: 0 15px !important;
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
		
		// Get themes from directory only
		$directory_themes = ThemeHelper::getAvailableThemes();
		
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
						<?php 
						// Display directory themes
						foreach ($directory_themes as $theme_key => $theme_obj): 
							if (method_exists($theme_obj, 'get')) {
								$display_name = $theme_obj->get('display_name', $theme_key);
							} else {
								$display_name = $theme_key;
							}
							?>
							<a href="#" onclick="joineryAdminBarSwitchTheme('<?php echo htmlspecialchars($theme_key); ?>'); return false;" 
							   <?php echo ($theme_key == $theme_template) ? 'style="font-weight: bold !important;"' : ''; ?>>
								<?php echo htmlspecialchars($display_name); ?>
								<?php echo ($theme_key == $theme_template) ? ' ✓' : ''; ?>
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
