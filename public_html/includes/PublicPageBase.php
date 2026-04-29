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

			// Only set HSTS if explicitly enabled in settings
			if ($settings->get_setting('enable_hsts', false, true)) {
				header('Strict-Transport-Security: max-age=86400; includeSubDomains');
			}
		}
		// X-Content-Type-Options is always sent (prevents MIME sniffing)
		header('X-Content-Type-Options: nosniff');
		// X-Permitted-Cross-Domain-Policies is always sent (prevents Flash/PDF cross-domain requests)
		header('X-Permitted-Cross-Domain-Policies: none');

		// X-Frame-Options only if enabled in settings (prevents clickjacking)
		if ($settings->get_setting('enable_x_frame_options', false, true)) {
			header('X-Frame-Options: SAMEORIGIN');
		}

		// Referrer-Policy only if enabled in settings (controls URL leakage)
		if ($settings->get_setting('enable_referrer_policy', false, true)) {
			header('Referrer-Policy: strict-origin-when-cross-origin');
		}

		// TODO (security): Implement Content-Security-Policy.
		// The codebase has ~28 inline <script> blocks across views and includes, so
		// strict CSP (no unsafe-inline) requires either:
		//   (a) A nonce system: generate a per-request nonce, inject it into every
		//       inline <script> tag via the page object, and include it in the header.
		//   (b) Move all inline scripts to external .js files.
		// Recommended approach: add Content-Security-Policy-Report-Only first with a
		// strict policy and a report-uri endpoint to identify violations in production
		// before enforcing. Known external origins to allowlist: js.stripe.com,
		// www.paypal.com, embed.acuityscheduling.com, www.google.com, www.hcaptcha.com,
		// cdn.tailwindcss.com, cdnjs.cloudflare.com, cdn.jsdelivr.net,
		// fonts.googleapis.com, fonts.gstatic.com.

	}

	/**
	 * Get a FormWriter instance appropriate for this page
	 * Loads the theme's FormWriter via the standard theme override chain
	 *
	 * @param string $form_id The form identifier (default: 'form1')
	 * @param array $options Configuration options for FormWriter
	 * @return FormWriter The theme-appropriate FormWriter instance
	 */
	public function getFormWriter($form_id = 'form1', $options = []) {
        require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));
        return new FormWriter($form_id, $options);
    }
	
	public static function get_public_menu(){
		require_once(PathHelper::getIncludePath('data/public_menus_class.php'));
		return MultiPublicMenu::get_sorted_array();
	}

	/**
	 * Whether a user-menu item belongs in the admin launcher (9-dots / nine-dots dropdown).
	 * Includes the home and profile shortcuts plus every core admin item.
	 */
	protected static function isAdminLauncherItem(array $item): bool {
		$slug = $item['slug'] ?? '';
		return $slug === 'core-home'
			|| $slug === 'core-profile'
			|| str_starts_with($slug, 'core-admin-');
	}

	/**
	 * Whether a user-menu item is a core admin item (Dashboard, Settings, etc.).
	 * Used by user-dropdown renderers to exclude admin items from the regular dropdown.
	 */
	protected static function isAdminMenuItem(array $item): bool {
		return str_starts_with($item['slug'] ?? '', 'core-admin-');
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

		if ($is_logged_in && $session->get_user_id()) {
			try {
				$user = new User($session->get_user_id(), TRUE);
				$menu_data['user_menu']['user_name'] = $user->get('usr_email');
				$menu_data['user_menu']['display_name'] = $user->display_name();
				$menu_data['user_menu']['avatar_url'] = PathHelper::getThemeFilePath('avatar.png', 'assets/images', 'web');
			} catch (Exception $e) {
				$menu_data['user_menu']['display_name'] = 'User';
			}
		}

		require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));
		$user_permission = $is_logged_in ? $session->get_permission() : 0;
		try {
			$rows = MultiAdminMenu::get_user_dropdown_items($is_logged_in, $user_permission);
			$items = array();
			foreach ($rows as $row) {
				$items[] = [
					'label' => $row->get('amu_menudisplay'),
					'link'  => $row->get('amu_defaultpage'),
					'icon'  => $row->get('amu_icon'),
					'slug'  => $row->get('amu_slug'),
				];
			}
			$menu_data['user_menu']['items'] = $items;
		} catch (PDOException $e) {
			// Columns missing during initial deploy / before update_database has run.
			$menu_data['user_menu']['items'] = [];
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

		// 4. Notifications
		$menu_data['notifications'] = [
			'enabled' => false,
			'unread_count' => 0,
			'view_all_link' => '/notifications',
		];

		if ($is_logged_in) {
			try {
				$unread_count = isset($_SESSION['notification_unread_count']) ? $_SESSION['notification_unread_count'] : null;
				if ($unread_count === null) {
					// Cache miss — single COUNT query, no object loading
					require_once(PathHelper::getIncludePath('data/notifications_class.php'));
					$unread_count = Notification::get_unread_count($session->get_user_id());
					$_SESSION['notification_unread_count'] = $unread_count;
				}

				$menu_data['notifications'] = [
					'enabled' => true,
					'unread_count' => (int)$unread_count,
					'view_all_link' => '/notifications',
				];
			} catch (Exception $e) {
				// Notification system not yet installed or query failed — keep disabled
			}
		}

		// 5. Messages
		$menu_data['messages'] = [
			'enabled' => false,
			'unread_count' => 0,
			'view_all_link' => '/profile/conversations',
		];

		if ($is_logged_in) {
			try {
				$msg_unread = isset($_SESSION['message_unread_count']) ? $_SESSION['message_unread_count'] : null;
				if ($msg_unread === null) {
					require_once(PathHelper::getIncludePath('data/conversations_class.php'));
					$msg_unread = Conversation::get_unread_count($session->get_user_id());
					$_SESSION['message_unread_count'] = $msg_unread;
				}
				$menu_data['messages'] = [
					'enabled' => true,
					'unread_count' => (int)$msg_unread,
					'view_all_link' => '/profile/conversations',
				];
			} catch (Exception $e) {
				// Conversation system not yet installed — keep disabled
			}
		}

		// 6. Site information
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
		return static::renderTabMenu($tab_menus, $current);
	}

	/**
	 * Render tab navigation menu
	 * Override in subclasses for framework-specific markup
	 *
	 * @param array $tab_menus Associative array of tab_name => url
	 * @param string|null $current Currently active tab name
	 * @return string HTML output
	 */
	protected static function renderTabMenu($tab_menus, $current=NULL){
		$output = '<nav class="tabs" aria-label="Tabs">';
		foreach($tab_menus as $name => $link){
			if($name == 'Edit Address' || $name == 'Edit Phone Number'){
				continue;
			}
			if($name == $current){
				$output .= '<span class="tab active" aria-current="page">' . htmlspecialchars($name) . '</span>';
			} else {
				$output .= '<a href="' . htmlspecialchars($link) . '" class="tab">' . htmlspecialchars($name) . '</a>';
			}
		}
		$output .= '</nav>';
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

		// SEO + social metadata — shared source values
		$page_title = !empty($options['title']) ? $options['title'] : $settings->get_setting('site_name');
		$meta_description = !empty($options['meta_description']) ? $options['meta_description'] : $settings->get_setting('site_description');
		$og_type = !empty($options['og_type']) ? $options['og_type'] : 'website';
		$og_site_name = $settings->get_setting('site_name');

		// Strip HTML and truncate description
		if ($meta_description) {
			$meta_description = strip_tags($meta_description);
			if (mb_strlen($meta_description) > 200) {
				$meta_description = mb_substr($meta_description, 0, 197) . '...';
			}
		}

		// Optional social-copy overrides — fall through to SEO copy when unset
		$og_title = !empty($options['og_title']) ? $options['og_title'] : $page_title;
		$og_description = !empty($options['og_description']) ? $options['og_description'] : $meta_description;
		if (!empty($options['og_description'])) {
			$og_description = strip_tags($og_description);
			if (mb_strlen($og_description) > 200) {
				$og_description = mb_substr($og_description, 0, 197) . '...';
			}
		}

		// SEO: standard meta description
		if ($meta_description) {
			echo '<meta name="description" content="' . htmlspecialchars($meta_description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
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
		echo '<meta property="og:locale" content="en_US" />' . "\n";

		// og:image - page-specific or site default
		$emitted_og_image = null;
		if(isset($options['preview_image_url']) && $options['preview_image_url']){
			$og_image = $options['preview_image_url'];
			if (strpos($og_image, 'http') !== 0) {
				$og_image = 'https://' . $webDir . $og_image;
			}
			$increment = isset($options['preview_image_increment']) ? $options['preview_image_increment'] : 1;
			$emitted_og_image = $og_image . '?' . $increment;
			echo '<meta property="og:image" content="' . htmlspecialchars($emitted_og_image, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}
		else{
			$preview_image_url = $settings->get_setting('preview_image');
			if($preview_image_url){
				if (strpos($preview_image_url, 'http') !== 0) {
					$preview_image_url = 'https://' . $webDir . $preview_image_url;
				}
				$emitted_og_image = $preview_image_url . '?' . $settings->get_setting('preview_image_increment');
				echo '<meta property="og:image" content="' . htmlspecialchars($emitted_og_image, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
			}
		}

		// Twitter Card tags — mirror the og values
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		if ($og_description) {
			echo '<meta name="twitter:description" content="' . htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}
		if ($emitted_og_image) {
			echo '<meta name="twitter:image" content="' . htmlspecialchars($emitted_og_image, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}

		$this->render_base_assets();
		$this->render_brand_token_overrides();

		if($settings->get_setting('custom_css')){
			echo '<style>'.$settings->get_setting('custom_css').'</style>';
		}

		// Render tracking code (wrapped for consent if enabled)
		echo $this->renderTrackingCode();

		// Render cookie consent banner (if enabled) - JS waits for DOMContentLoaded
		echo $this->renderConsentBanner();
	}

	/**
	 * Render base CSS/JS assets. Loaded before theme-specific assets so themes
	 * can override via the cascade.
	 *
	 * Themes that provide their own complete CSS (e.g. PublicPageJoinerySystem)
	 * should override this method with an empty body to prevent conflicts.
	 */
	protected function render_base_assets() {
		echo '<link rel="stylesheet" href="/assets/css/base.css?v=3">' . "\n";
		echo '<link rel="stylesheet" href="/assets/css/joinery-styles.css?v=1">' . "\n";
		echo '<script defer src="/assets/js/base.js?v=2"></script>' . "\n";
	}

	protected function render_brand_token_overrides() {
		$settings = Globalvars::get_instance();
		$map = [
			'jy_color_primary'       => '--jy-color-primary',
			'jy_color_primary_hover' => '--jy-color-primary-hover',
			'jy_color_primary_text'  => '--jy-color-primary-text',
			'jy_color_surface'       => '--jy-color-surface',
			'jy_color_bg'            => '--jy-color-bg',
		];
		$overrides = [];
		foreach ($map as $setting => $token) {
			$val = $settings->get_setting($setting, false, true);
			if ($val !== '' && $val !== null && preg_match('/^#[0-9a-fA-F]{3,6}$/', $val)) {
				$overrides[] = '  ' . $token . ': ' . htmlspecialchars($val, ENT_QUOTES) . ';';
			}
		}
		if (empty($overrides)) return;
		echo '<style id="jy-brand-tokens">:root {' . "\n" . implode("\n", $overrides) . "\n" . '}</style>' . "\n";
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

			// Only set HSTS if explicitly enabled in settings
			if ($settings->get_setting('enable_hsts', false, true)) {
				header('Strict-Transport-Security: max-age=86400; includeSubDomains');
			}
		}
		// X-Content-Type-Options is always sent (prevents MIME sniffing)
		header('X-Content-Type-Options: nosniff');
		// X-Permitted-Cross-Domain-Policies is always sent (prevents Flash/PDF cross-domain requests)
		header('X-Permitted-Cross-Domain-Policies: none');

		// X-Frame-Options only if enabled in settings (prevents clickjacking)
		if ($settings->get_setting('enable_x_frame_options', false, true)) {
			header('X-Frame-Options: SAMEORIGIN');
		}

		// Referrer-Policy only if enabled in settings (controls URL leakage)
		if ($settings->get_setting('enable_referrer_policy', false, true)) {
			header('Referrer-Policy: strict-origin-when-cross-origin');
		}

		if(!isset($options['title']) || !$options['title']){
			$options['title'] = $settings->get_setting('site_name');
		}
		
		if(!isset($options['meta_description']) || !$options['meta_description']){
			$options['meta_description'] = $settings->get_setting('site_description');
		}

		if(empty($options['noheader']) && !$options['is_404'] && ($options['is_valid_page'] ?? false) ){
			//TRACKING
			if(!($_SESSION['permission'] ?? 0) || ($_SESSION['permission'] ?? 0) == 0){
				$session->save_visitor_event(1, $options['is_404']);
			}
		}
		
		return $options;
	}
	
	static function alert($title, $content, $type){
		return static::renderAlert($title, $content, $type);
	}

	/**
	 * Render the site logo (image + text fallback)
	 * Override in theme-specific PublicPage subclasses for custom markup
	 */
	public function get_logo() {
		$settings = Globalvars::get_instance();
		if ($settings->get_setting('logo_link')) {
			echo '<img src="' . htmlspecialchars($settings->get_setting('logo_link'), ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($settings->get_setting('site_name'), ENT_QUOTES, 'UTF-8') . '" class="logo-img">';
		}
		echo '<span class="logo-text">' . htmlspecialchars($settings->get_setting('site_name'), ENT_QUOTES, 'UTF-8') . '</span>';
	}

	/**
	 * Render an alert/notification message
	 * Override in subclasses for framework-specific markup
	 *
	 * @param string $title Alert title
	 * @param string $content Alert body content
	 * @param string $type Alert type: 'error', 'warn', 'success'
	 * @return string HTML output
	 */
	protected static function renderAlert($title, $content, $type){
		$type_class = $type;
		if ($type === 'warn') $type_class = 'warning';

		$output = '<div class="alert alert-' . $type_class . '" role="alert">';
		if ($title) {
			$output .= '<h4>' . htmlspecialchars($title) . '</h4>';
		}
		$output .= '<p>' . $content . '</p>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Render a small styled action button (POST form submit) for use inside table rows.
	 *
	 * @param string $label   Button text
	 * @param string $url     Form action URL (may include query params)
	 * @param array  $options Optional:
	 *   'hidden'  => array of name=>value hidden fields to include
	 *   'confirm' => string JS confirm message before submitting
	 *   'class'   => additional CSS classes for the button
	 * @return string HTML
	 */
	static function action_button($label, $url, $options = []) {
		$hidden_fields = isset($options['hidden']) ? $options['hidden'] : [];
		$confirm_msg   = isset($options['confirm']) ? $options['confirm'] : '';
		$extra_class   = isset($options['class'])   ? ' ' . $options['class'] : '';

		$onsubmit = '';
		if ($confirm_msg) {
			$escaped = htmlspecialchars($confirm_msg, ENT_QUOTES);
			$onsubmit = ' onsubmit="return confirm(\'' . $escaped . '\');"';
		}

		$html = '<form method="POST" action="' . htmlspecialchars($url) . '" style="display:inline;"' . $onsubmit . '>';
		foreach ($hidden_fields as $name => $value) {
			$html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
		}
		$btn_class = ($extra_class !== '') ? ltrim($extra_class) : 'btn btn-soft-default btn-sm';
		$html .= '<button type="submit" class="' . $btn_class . '">' . htmlspecialchars($label) . '</button>';
		$html .= '</form>';

		return $html;
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

		// Signal to renderBoxOpen that this box contains a table (affects card-body padding)
		if (property_exists($this, '_is_table_box')) {
			$this->_is_table_box = true;
		}
		$this->begin_box($options);
		if (property_exists($this, '_is_table_box')) {
			$this->_is_table_box = false;
		}

		if(!$pager){
			$pager = new Pager();
		}

		$sort_data = isset($options['sortoptions']) ? $options['sortoptions'] : null;
		$filter_data = isset($options['filteroptions']) ? $options['filteroptions'] : null;
		$search_on = isset($options['search_on']) ? $options['search_on'] : null;

		$this->renderToolbar($sort_data, $filter_data, $search_on, $pager);

		// Get theme-specific CSS classes
		$css = $this->getTableClasses();
		$wrapperClass = isset($css['wrapper']) ? $css['wrapper'] : 'table-wrapper';
		$tableClass = isset($css['table']) ? $css['table'] : 'styled-table';
		$headerClass = isset($css['header']) ? $css['header'] : '';

		echo '<div class="' . $wrapperClass . '">';
		echo '<table class="' . $tableClass . '">';
		if ($headerClass !== '') {
			echo '<thead class="' . $headerClass . '"><tr>';
		} else {
			echo '<thead><tr>';
		}

		foreach ($headers as $value) {
			echo '<th>'.$value.'</th>';
		}

		echo '</tr></thead><tbody>';
	}

	/**
	 * Render sort/filter/search toolbar above a table
	 * Override in subclasses for framework-specific markup
	 *
	 * @param array|null $sort_data Sort options (display_name => column)
	 * @param array|null $filter_data Filter options (display_name => value)
	 * @param bool|null $search_on Whether to show search
	 * @param Pager $pager Pager instance
	 */
	protected function renderToolbar($sort_data, $filter_data, $search_on, $pager) {
		if (!$sort_data && !$filter_data && !$search_on) return;

		echo '<div class="table-toolbar">';

		if($sort_data){
			printf('<form method="get" action="%s" class="toolbar-form">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('sort', 'sdirection'));
			echo '<label for="'.$pager->prefix().'sort">Sort: </label>';
			echo '<select name="'.$pager->prefix().'sort">';
			foreach ($sort_data as $key => $value) {
				$selected = ($pager->get_sort() == $value) ? ' selected' : '';
				echo '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($key) . '</option>';
			}
			echo '</select>';

			echo ' <select name="'.$pager->prefix().'sdirection">';
			$diroptions = array('Descending'=>'DESC', 'Ascending'=>'ASC');
			foreach ($diroptions as $key => $value) {
				$selected = ($pager->sort_direction() == $value) ? ' selected' : '';
				echo '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($key) . '</option>';
			}
			echo '</select>';

			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">';
			}
			echo ' <button type="submit">Sort</button></form>';
		}

		if($filter_data){
			printf('<form method="get" action="%s" class="toolbar-form">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('filter'));
			echo '<label for="'.$pager->prefix().'filter">Show: </label>';
			echo '<select name="'.$pager->prefix().'filter">';
			foreach ($filter_data as $key => $value) {
				$selected = ($pager->get_filter() == $value) ? ' selected' : '';
				echo '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($key) . '</option>';
			}
			echo '</select>';

			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">';
			}
			echo ' <button type="submit">Filter</button></form>';
		}

		if($search_on){
			printf('<form method="get" action="%s" class="toolbar-form">', $pager->base_url());
			echo $pager->url_vars_as_hidden_input(array('searchterm'));
			echo '<label for="'.$pager->prefix().'searchterm">Search: </label>';
			echo '<input name="'.$pager->prefix().'searchterm" id="'.$pager->prefix().'searchterm" value="'.htmlspecialchars($pager->search_term()).'" size="20" type="text" maxlength="">';

			foreach($pager->url_vars() as $key=>$value){
				echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'">';
			}
			echo ' <button type="submit">Search</button></form>';
		}

		echo '</div>';
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
		echo '</tbody></table></div>';

		// Build pagination data structure
		$options = isset($this->current_table_options) ? $this->current_table_options : array();

		if($pager->num_records()){
			$pagination_data = [
				'num_records'   => $pager->num_records(),
				'current_page'  => $pager->current_page(),
				'total_pages'   => $pager->total_pages(),
				'show_controls' => ($pager->num_records() > $pager->num_per_page()),
				'in_card'       => (isset($options['card']) && $options['card'] === true),
				'prev_10_url'   => null,
				'next_10_url'   => null,
				'pages'         => [],
			];

			if ($pagination_data['show_controls']) {
				// Previous 10 pages
				$p = $pager->is_valid_page('-10');
				if ($p) $pagination_data['prev_10_url'] = $pager->get_url($p);

				// Next 10 pages
				$p = $pager->is_valid_page('+10');
				if ($p) $pagination_data['next_10_url'] = $pager->get_url($p);

				// Surrounding pages (4 before, current, 4 after)
				for($x=4; $x>=1; $x--){
					$p = $pager->is_valid_page('-'.$x);
					if($p){
						$pagination_data['pages'][] = ['number' => $p, 'url' => $pager->get_url($p), 'is_current' => false];
					}
				}
				$pagination_data['pages'][] = ['number' => $pager->current_page(), 'url' => null, 'is_current' => true];
				for($x=1; $x<=4; $x++){
					$p = $pager->is_valid_page('+'.$x);
					if($p){
						$pagination_data['pages'][] = ['number' => $p, 'url' => $pager->get_url($p), 'is_current' => false];
					}
				}
			}

			$this->renderPagination($pagination_data);
		}

		$this->end_box($options);
	}

	/**
	 * Render pagination controls
	 * Override in subclasses for framework-specific markup
	 *
	 * @param array $data Pagination data structure with keys:
	 *   num_records, current_page, total_pages, show_controls, in_card,
	 *   prev_10_url, next_10_url, pages (array of [number, url, is_current])
	 */
	protected function renderPagination($data) {
		echo '<nav class="pagination-wrapper" aria-label="Pagination">';
		echo '<span class="pagination-info">' . $data['num_records'] . ' records, Page ' . $data['current_page'] . ' of ' . $data['total_pages'] . '</span>';

		if ($data['show_controls']) {
			echo '<ul class="pagination">';

			if ($data['prev_10_url']) {
				echo '<li><a href="' . htmlspecialchars($data['prev_10_url']) . '" title="Previous 10">&laquo;</a></li>';
			} else {
				echo '<li class="disabled"><span>&laquo;</span></li>';
			}

			foreach ($data['pages'] as $page) {
				if ($page['is_current']) {
					echo '<li class="active" aria-current="page"><span>' . $page['number'] . '</span></li>';
				} else {
					echo '<li><a href="' . htmlspecialchars($page['url']) . '">' . $page['number'] . '</a></li>';
				}
			}

			if ($data['next_10_url']) {
				echo '<li><a href="' . htmlspecialchars($data['next_10_url']) . '" title="Next 10">&raquo;</a></li>';
			} else {
				echo '<li class="disabled"><span>&raquo;</span></li>';
			}

			echo '</ul>';
		}

		echo '</nav>';
	}

	function begin_box($options=NULL){
		if(!is_array($options)){
			$options = array();
		}
		$this->renderBoxOpen($options);
		$this->dropdown_or_buttons($options);
	}

	function end_box($options=NULL){
		if(!is_array($options)){
			$options = array();
		}
		$this->renderBoxClose($options);
	}

	function dropdown_or_buttons($options=array()){
		if(!is_array($options)){
			$options = array();
		}

		if(!isset($options['altlinks']) || !is_array($options['altlinks']) || count($options['altlinks']) == 0){
			return;
		}

		$label = isset($options['options_label']) ? $options['options_label'] : 'Options';
		$links = $options['altlinks'];

		if(count($links) > 2){
			$this->renderDropdown($label, $links);
		} else {
			$this->renderButtonGroup($links);
		}
	}

	/**
	 * Render the opening markup for a content box/card
	 * Override in subclasses for framework-specific markup
	 */
	protected function renderBoxOpen($options) {
		$use_card = isset($options['card']) && $options['card'] === true;

		if ($use_card) {
			echo '<div class="content-box">';
			if (!empty($options['title'])) {
				echo '<div class="content-box-header"><h6>' . htmlspecialchars($options['title']) . '</h6></div>';
			}
			echo '<div class="content-box-body">';
		} else {
			echo '<div>';
		}
	}

	/**
	 * Render the closing markup for a content box/card
	 * Override in subclasses for framework-specific markup
	 */
	protected function renderBoxClose($options) {
		$use_card = isset($options['card']) && $options['card'] === true;

		if ($use_card) {
			echo '</div>';
			echo '</div>';
		} else {
			echo '</div>';
		}
	}

	/**
	 * Render a dropdown menu for action links (>2 links)
	 * Override in subclasses for framework-specific markup
	 */
	protected function renderDropdown($label, $links) {
		echo '<div class="action-buttons">';
		echo '<details class="dropdown">';
		echo '<summary>' . htmlspecialchars($label) . '</summary>';
		echo '<ul class="dropdown-menu">';
		foreach($links as $link_label => $link_url){
			echo '<li><a href="' . htmlspecialchars($link_url) . '">' . htmlspecialchars($link_label) . '</a></li>';
		}
		echo '</ul>';
		echo '</details>';
		echo '</div>';
	}

	/**
	 * Render inline buttons for action links (1-2 links)
	 * Override in subclasses for framework-specific markup
	 */
	protected function renderButtonGroup($links) {
		echo '<div class="action-buttons">';
		foreach($links as $label => $link){
			echo '<a href="' . htmlspecialchars($link) . '" class="btn btn-outline">' . htmlspecialchars($label) . '</a> ';
		}
		echo '</div>';
	}

	/**
	 * Get theme-specific CSS classes for table styling
	 * @return array Array of CSS class mappings
	 */
	abstract protected function getTableClasses();

	/**
	 * Check if admin bar should be displayed.
	 * Requires permission level 10 and the show_admin_bar setting to be enabled (default: on).
	 */
	protected function should_show_admin_bar() {
		$session = SessionControl::get_instance();
		if ($session->get_permission() < 10) {
			return false;
		}
		$settings = Globalvars::get_instance();
		$setting = $settings->get_setting('show_admin_bar', false, true);
		// Default to enabled if setting doesn't exist
		return ($setting === null || $setting === '' || $setting);
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

			/* Fix for joinery-system theme sidebar and topbar */
			body.joinery-admin-bar-active .sidebar {
				top: 32px !important;
				height: calc(100vh - 32px) !important;
			}
			body.joinery-admin-bar-active .topbar {
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
	 * Render the notification bell icon for the header.
	 * Called from each theme's top_right_menu(). Themes can override for custom markup.
	 */
	public function render_message_icon($menu_data = null) {
		if ($menu_data === null) {
			$menu_data = $this->get_menu_data();
		}
		$messages = $menu_data['messages'];
		if (!$messages['enabled']) {
			return;
		}
		$unread = (int)$messages['unread_count'];
		echo '<a href="' . htmlspecialchars($messages['view_all_link'], ENT_QUOTES, 'UTF-8') . '" class="header-messages-link" title="Messages">';
		echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 4l-10 8L2 4"/></svg>';
		if ($unread > 0) {
			echo '<span class="messages-count">' . $unread . '</span>';
		}
		echo '</a>';
	}

	public function render_notification_icon($menu_data = null) {
		if ($menu_data === null) {
			$menu_data = $this->get_menu_data();
		}
		$notifications = $menu_data['notifications'];
		if (!$notifications['enabled']) {
			return;
		}
		$unread = (int)$notifications['unread_count'];
		echo '<a href="' . htmlspecialchars($notifications['view_all_link'], ENT_QUOTES, 'UTF-8') . '" class="header-notifications-link" title="Notifications">';
		echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
		if ($unread > 0) {
			echo '<span class="notifications-count">' . $unread . '</span>';
		}
		echo '</a>';
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
								$is_plugin_theme = $theme_obj->get('is_plugin_theme', false);
							} else {
								$display_name = $theme_key;
								$is_plugin_theme = false;
							}
							$is_active = ($theme_key == $theme_template);
							if ($is_plugin_theme): ?>
								<a href="/admin/admin_settings"
								   <?php echo $is_active ? 'style="font-weight: bold !important;"' : ''; ?>>
									<?php echo htmlspecialchars($display_name); ?> &#x2192;
									<?php echo $is_active ? ' ✓' : ''; ?>
								</a>
							<?php else: ?>
							<a href="#" onclick="joineryAdminBarSwitchTheme('<?php echo htmlspecialchars($theme_key); ?>'); return false;"
							   <?php echo $is_active ? 'style="font-weight: bold !important;"' : ''; ?>>
								<?php echo htmlspecialchars($display_name); ?>
								<?php echo $is_active ? ' ✓' : ''; ?>
							</a>
							<?php endif; ?>
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
