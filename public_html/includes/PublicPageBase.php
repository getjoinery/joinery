<?php
require_once(__DIR__ . '/PathHelper.php');

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/ThemeHelper.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/public_menus_class.php'));

abstract class PublicPageBase {

	protected $rowcount;

	/**
	 * Whether this render includes the vault lock chip (set during
	 * global_includes_top for signed-in users with a set-up vault). Header
	 * renderers consult it via render_vault_lock_slot() so no slot markup is
	 * emitted for users who will never mount a chip.
	 */
	protected $vault_lock_enabled = false;

	/**
	 * Header-menu providers, keyed by the $menu_data key they populate (e.g.
	 * 'cart'). A provider is `function(SessionControl $session): ?array` and is
	 * registered from a plugin's request bootstrap. Returning null contributes
	 * nothing. Last registration wins per key (idempotent).
	 *
	 * @var array<string,callable>
	 */
	protected static $header_menu_providers = array();

	/** Register a header-menu provider (e.g. the store's cart). */
	public static function register_header_menu_provider(string $key, callable $provider): void {
		self::$header_menu_providers[$key] = $provider;
	}

	/**
	 * Emit the vault lock chip's mount point (docs/sealed_vault.md § The lock
	 * chip). Page classes call this from their header icon cluster;
	 * vault-lock.js mounts the padlock into it. Emits nothing when the user
	 * has no vault, so the slot never leaves an empty gap in the header.
	 */
	public function render_vault_lock_slot(string $tag = 'span', string $class = ''): void {
		if (!$this->vault_lock_enabled) { return; }
		$class_attr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
		echo '<' . $tag . $class_attr . ' data-vault-lock-slot></' . $tag . '>';
	}

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
				header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
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
		// www.paypal.com, www.google.com, www.hcaptcha.com,
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
	 * Whether to render the site chrome — header, navigation, and footer.
	 * False for app-context web sessions (started by the /app_bridge webview
	 * bridge): the native shell supplies titles and navigation, so pages show
	 * content only. Every theme wraps its chrome markup in this check:
	 *
	 *   <?php if ($this->show_site_chrome()): ?> …nav/footer… <?php endif; ?>
	 *
	 * Scripts, stylesheets, and content stay outside the check — only the
	 * visual chrome is conditional. See docs/mobile_apps.md.
	 */
	public function show_site_chrome() {
		return !SessionControl::get_instance()->is_app_session();
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
	 * Path of the current request, without the query string.
	 */
	protected function request_path() {
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		return $path ?: '/';
	}

	/**
	 * Whether the current request is inside the member area — the pages the
	 * member section nav moves between. Delegates to the shared boundary
	 * definition (RouteHelper::isMemberAreaPath()).
	 */
	protected function in_member_area() {
		require_once(PathHelper::getIncludePath('includes/RouteHelper.php'));
		return RouteHelper::isMemberAreaPath($this->request_path());
	}

	/**
	 * The member section links: the seeded profile menu minus Home, Sign out
	 * and the admin launcher items, which belong in the user dropdown instead.
	 *
	 * Returns an empty array whenever the nav should not appear at all (signed
	 * out, or outside the member area), so a renderer can treat "no items" as
	 * "emit nothing".
	 *
	 * @param array|null $menu_data Pre-fetched get_menu_data() payload. Header
	 *                              renderers already hold one — pass it so this
	 *                              does not repeat the menu queries.
	 * @return array List of user-menu items (label, link, icon, slug).
	 */
	public function member_subnav_items($menu_data = NULL) {
		if ($menu_data === NULL) {
			$menu_data = $this->get_menu_data();
		}
		if (empty($menu_data['user_menu']['is_logged_in']) || !$this->in_member_area()) {
			return array();
		}

		$items = array();
		foreach (($menu_data['user_menu']['member_nav'] ?? array()) as $item) {
			$slug = $item['slug'] ?? '';
			if ($slug === 'core-home' || $slug === 'core-signout' || self::isAdminMenuItem($item)) {
				continue;
			}
			// A parented row (AI Memory under AI, Devices under Filtering) lives
			// inside its section, not on the top-level nav.
			if (!empty($item['parent'])) {
				continue;
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Emit the member section nav — the menu a signed-in member uses to move
	 * between the account pages (Profile, Email, Calendar, Drive, AI, ...).
	 *
	 * Every header renderer calls this immediately after its site header, so
	 * the nav is present on every theme without each theme owning the list or
	 * its permission/setting gates. A theme that wants different markup or a
	 * different position overrides this method; one that wants it suppressed
	 * overrides it to return early. Styling for the default markup ships in the
	 * shared kit stylesheet (`.jy-member-subnav` in joinery-styles.css), which
	 * every theme loads.
	 *
	 * @param array|null $menu_data Pre-fetched get_menu_data() payload.
	 */
	public function render_member_subnav($menu_data = NULL) {
		$items = $this->member_subnav_items($menu_data);
		if (empty($items)) {
			return;
		}

		$request_path = $this->request_path();
		echo '<div class="jy-member-subnav"><nav class="jy-member-subnav-inner" aria-label="Profile sections">';
		foreach ($items as $item) {
			$active = ($item['link'] === $request_path) ? ' active' : '';
			echo '<a href="' . htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') . '" class="jy-member-subnav-link' . $active . '">' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</a>';
		}
		echo '</nav></div>';
	}

	/**
	 * The member settings rail: seeded 'member_settings' menu rows — the core
	 * account pages plus plugin-contributed sections (declared as settingsMenu
	 * in plugin.json), permission- and setting-gated like every menu location.
	 *
	 * @return array List of items (label, link, icon, slug).
	 */
	public static function member_settings_items() {
		$session = SessionControl::get_instance();
		if (!$session->is_logged_in()) {
			return array();
		}
		require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));
		try {
			$rows = MultiAdminMenu::get_user_dropdown_items(true, $session->get_permission(), 'member_settings');
		} catch (PDOException $e) {
			// Location not yet seeded (before update_database has run).
			return array();
		}
		$items = array();
		foreach ($rows as $row) {
			$items[] = array(
				'label' => $row->get('amu_menudisplay'),
				'link'  => $row->get('amu_defaultpage'),
				'icon'  => $row->get('amu_icon'),
				'slug'  => $row->get('amu_slug'),
			);
		}
		return $items;
	}

	/**
	 * Open the settings hub layout: the left rail listing every member
	 * settings section, plus the content column the caller's page body
	 * renders into. Close with settings_layout_end().
	 *
	 * @param string|null $active_link Rail link to mark active; defaults to
	 *                                 the current request path (pass one when
	 *                                 the page is a sub-page of a section,
	 *                                 e.g. the authenticator test under
	 *                                 /profile/security).
	 */
	public static function settings_layout_start($active_link = NULL) {
		if ($active_link === NULL) {
			$active_link = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		}
		$output = '<div class="jy-settings-layout">';
		$output .= '<nav class="jy-settings-nav" aria-label="Settings sections">';
		foreach (self::member_settings_items() as $item) {
			$active = ($item['link'] === $active_link) ? ' active' : '';
			$output .= '<a href="' . htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8') . '" class="jy-settings-nav-link' . $active . '">'
				. htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</a>';
		}
		$output .= '</nav>';
		$output .= '<div class="jy-settings-content">';
		return $output;
	}

	public static function settings_layout_end() {
		return '</div></div><!-- /.jy-settings-layout -->';
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
			} catch (Exception $e) {
				$menu_data['user_menu']['display_name'] = 'User';
			}
			// Optional avatar asset — a theme without an avatar.png must not
			// discard the real display name resolved above, so keep this out of
			// that try and let a missing file return null instead of throwing.
			$menu_data['user_menu']['avatar_url'] = PathHelper::getThemeFilePath('avatar.png', 'assets/images', 'web', NULL, NULL, false, false);
		}

		require_once(PathHelper::getIncludePath('data/admin_menus_class.php'));
		$user_permission = $is_logged_in ? $session->get_permission() : 0;
		try {
			$rows = MultiAdminMenu::get_user_dropdown_items($is_logged_in, $user_permission);
			$nav_items = array();
			foreach ($rows as $row) {
				$nav_items[] = [
					'label'  => $row->get('amu_menudisplay'),
					'link'   => $row->get('amu_defaultpage'),
					'icon'   => $row->get('amu_icon'),
					'slug'   => $row->get('amu_slug'),
					'parent' => $row->get('amu_parent_menu_id'),
				];
			}
		} catch (PDOException $e) {
			// Columns missing during initial deploy / before update_database has run.
			$nav_items = array();
		}

		// The seeded profile-menu rows drive two surfaces: the member section
		// nav (member_nav) and the admin launcher (launcher_items). The user
		// dropdown itself is identity-only — account, settings, sign out — so
		// it stops mirroring the section nav.
		$menu_data['user_menu']['member_nav'] = $nav_items;
		$menu_data['user_menu']['launcher_items'] = array_values(array_filter($nav_items, function ($item) {
			return self::isAdminLauncherItem($item);
		}));
		if ($is_logged_in) {
			$menu_data['user_menu']['items'] = [
				['label' => 'Dashboard', 'link' => '/profile', 'icon' => 'user', 'slug' => 'core-profile'],
				['label' => 'Settings', 'link' => '/profile/settings', 'icon' => 'cog', 'slug' => 'core-settings'],
				['label' => 'Sign out', 'link' => '/logout', 'icon' => 'sign-out', 'slug' => 'core-signout'],
			];
		} else {
			$menu_data['user_menu']['items'] = $nav_items;
		}

		// 3. Header menu providers (cart, etc.)
		// Plugins contribute header menu payloads (the store contributes the
		// cart) by registering a provider from their request bootstrap. With no
		// provider registered — e.g. store inactive — the key simply isn't set,
		// and themes fall back via isset($menu_data['cart']).
		foreach (self::$header_menu_providers as $key => $provider) {
			try {
				$data = $provider($session);
				if ($data !== null) {
					$menu_data[$key] = $data;
				}
			} catch (Exception $e) {
				// A misbehaving provider must not take down the page header.
			}
		}

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
		$site_url = 'https://' . $webDir;
		$site_name = $settings->get_setting('site_name');
		$og_site_name = $site_name;

		require_once(PathHelper::getIncludePath('data/seo_page_metadata_class.php'));

		// Canonical URL (absolute, query-string stripped, pagination removed)
		$canonical_url = $this->get_canonical_url();
		$canonical_url = SeoPageMetadata::absolutize_url($canonical_url, $site_url);
		echo '<link rel="canonical" href="' . htmlspecialchars($canonical_url, ENT_QUOTES, 'UTF-8') . '">' . "\n";

		// Layer 2b — resolve SEO via DB override → $options → inferred → site setting
		$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
		$canonical_path = SeoPageMetadata::canonicalize_path($request_path);
		$override = SeoPageMetadata::find_for_path($canonical_path);
		$inferred = SeoPageMetadata::infer_for_request($canonical_path, $options);

		// Accept 'description' as an alias for 'meta_description' (legacy views)
		if (empty($options['meta_description']) && !empty($options['description'])) {
			$options['meta_description'] = $options['description'];
		}

		$page_title = ($override && $override->get('spm_title'))
			? $override->get('spm_title')
			: (!empty($options['title']) ? $options['title']
				: ($inferred['title'] ?? $site_name));

		$meta_description = ($override && $override->get('spm_meta_description'))
			? $override->get('spm_meta_description')
			: (!empty($options['meta_description']) ? $options['meta_description']
				: ($inferred['meta_description'] ?? $settings->get_setting('site_description')));

		$og_title = ($override && $override->get('spm_og_title'))
			? $override->get('spm_og_title')
			: (!empty($options['og_title']) ? $options['og_title'] : $page_title);

		$og_description = ($override && $override->get('spm_og_description'))
			? $override->get('spm_og_description')
			: (!empty($options['og_description']) ? $options['og_description'] : $meta_description);

		$preview_image = ($override && $override->get('spm_preview_image_url'))
			? $override->get('spm_preview_image_url')
			: (!empty($options['preview_image_url']) ? $options['preview_image_url']
				: ($inferred['preview_image'] ?? $settings->get_setting('preview_image')));

		$og_type = ($override && $override->get('spm_og_type'))
			? $override->get('spm_og_type')
			: (!empty($options['og_type']) ? $options['og_type']
				: ($inferred['og_type'] ?? 'website'));

		$noindex = (isset($options['is_valid_page']) && $options['is_valid_page'] === false)
			|| !empty($options['noindex'])
			|| ($override && $override->get('spm_noindex'));

		// Apply site-wide title format (skipped when title already equals site_name)
		$page_title = SeoPageMetadata::apply_title_format($page_title, $site_name);

		// Strip HTML / multi-byte safe truncate for descriptions
		if ($meta_description) {
			$meta_description = strip_tags($meta_description);
			if (mb_strlen($meta_description) > 200) {
				$meta_description = mb_substr($meta_description, 0, 197) . '...';
			}
		}
		if ($og_description) {
			$og_description = strip_tags($og_description);
			if (mb_strlen($og_description) > 200) {
				$og_description = mb_substr($og_description, 0, 197) . '...';
			}
		}

		// Single emission point for <title>, <meta name="description">, og:*, twitter:*
		echo '<title>' . htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') . '</title>' . "\n";

		if ($meta_description) {
			echo '<meta name="description" content="' . htmlspecialchars($meta_description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}

		if ($noindex) {
			echo '<meta name="robots" content="noindex" />' . "\n";
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

		$emitted_og_image = null;
		if ($preview_image) {
			$og_image = SeoPageMetadata::absolutize_url($preview_image, $site_url);
			$increment = $options['preview_image_increment']
				?? $settings->get_setting('preview_image_increment', false, true)
				?? 1;
			$emitted_og_image = $og_image . (strpos($og_image, '?') === false ? '?' : '&') . $increment;
			echo '<meta property="og:image" content="' . htmlspecialchars($emitted_og_image, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}

		// Twitter Card type auto-selected by image presence
		$twitter_card = $emitted_og_image ? 'summary_large_image' : 'summary';
		echo '<meta name="twitter:card" content="' . $twitter_card . '" />' . "\n";
		echo '<meta name="twitter:title" content="' . htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		if ($og_description) {
			echo '<meta name="twitter:description" content="' . htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}
		if ($emitted_og_image) {
			echo '<meta name="twitter:image" content="' . htmlspecialchars($emitted_og_image, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
		}

		// Lazy auto-create row for any new public path (admin previews included)
		if (!$override) {
			SeoPageMetadata::lazy_auto_create($canonical_path, $options);
		}

		// API CSRF token for signed-in users: page JS reads this meta tag and
		// sends it as the X-Joinery-Csrf header to authenticate /api/v1 calls
		// with the browser session (see ApiAuth). Session-wide token, distinct
		// from FormWriter's per-form tokens.
		//
		// joinery-api.js (window.joineryApi) is the single transport for those
		// calls — emitted on every page, before any inline page script, and
		// outside render_base_assets() so themes that override that method
		// still get it.
		echo '<script src="/assets/js/joinery-api.js?v='
			. $this->asset_mtime('assets/js/joinery-api.js') . '"></script>' . "\n";
		$session = SessionControl::get_instance();
		if ($session->is_logged_in()) {
			echo '<meta name="joinery-api-csrf" content="'
				. htmlspecialchars($session->get_api_csrf_token(), ENT_QUOTES, 'UTF-8') . '" />' . "\n";

			// Vault presence (specs/mailbox_security_levels.md § The Unlock Window):
			// while an unlock window is open, every page beats vault_heartbeat so
			// presence means "on Joinery", not "on the mail page". The meta flag
			// tells the beacon a window was open at render time (a cheap APCu
			// check); the script itself is inert without the flag or a
			// 'joinery:vault-unlocked' event from an in-page unlock ceremony.
			require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
			$vault_window_open = VaultUnlock::isOpen((int)$session->get_user_id());
			if ($vault_window_open) {
				echo '<meta name="joinery-vault-window" content="open" />' . "\n";
			}
			echo '<script src="/assets/js/vault-presence.js?v='
				. $this->asset_mtime('assets/js/vault-presence.js') . '"></script>' . "\n";

			// Vault lock chip (docs/sealed_vault.md § The lock chip): a user
			// with a set-up server-custody vault gets the padlock on every
			// page — a fixed place to see the locked/unlocked state and to run
			// the unlock or lock ceremony from anywhere. Users without a vault
			// never load any of it.
			require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
			if (UserEncryptionVault::loadForUser((int)$session->get_user_id())) {
				$this->vault_lock_enabled = true;
				$idle_minutes = (int)$settings->get_setting('vault_unlock_idle_minutes');
				if ($idle_minutes <= 0) { $idle_minutes = 30; }
				echo '<meta name="joinery-vault" content="' . ($vault_window_open ? 'open' : 'locked')
					. '" data-idle-minutes="' . $idle_minutes . '" />' . "\n";
				echo '<link rel="stylesheet" href="/assets/css/vault-lock.css?v='
					. $this->asset_mtime('assets/css/vault-lock.css') . '">' . "\n";
				echo '<script src="/assets/js/passkeys.js?v='
					. $this->asset_mtime('assets/js/passkeys.js') . '"></script>' . "\n";
				echo '<script src="/assets/js/vault-lock.js?v='
					. $this->asset_mtime('assets/js/vault-lock.js') . '"></script>' . "\n";
			}
		}

		$this->render_base_assets();
		// Active plugins' declared stylesheets — after the kit, before the theme
		// stylesheet (which loads once this method returns), so plugin rules sit
		// on the kit tokens and the theme can still override.
		echo PluginHelper::renderActivePluginStyleLinks();
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
		echo '<link rel="stylesheet" href="/assets/css/base.css?v=' . $this->asset_mtime('assets/css/base.css') . '">' . "\n";
		echo '<link rel="stylesheet" href="/assets/css/joinery-styles.css?v=' . $this->asset_mtime('assets/css/joinery-styles.css') . '">' . "\n";
		echo '<script defer src="/assets/js/base.js?v=' . $this->asset_mtime('assets/js/base.js') . '"></script>' . "\n";
	}

	/**
	 * Cache-bust token for a global asset: the file's modification time, so the
	 * edge CDN (which keys on the query string) re-fetches whenever the file
	 * changes. Falls back to '1' if the path can't be resolved.
	 */
	protected function asset_mtime($relative_path) {
		$full = PathHelper::getIncludePath($relative_path);
		return (is_string($full) && is_file($full)) ? filemtime($full) : '1';
	}

	/**
	 * The theme whose stylesheet renders THIS page. Defaults to the configured
	 * public theme (theme_template). A page class that renders with a fixed theme
	 * regardless of theme_template (e.g. the admin theme) overrides this so its
	 * brand tokens resolve against the theme actually on screen.
	 */
	protected function get_render_theme() {
		$settings = Globalvars::get_instance();
		return $settings->get_setting('theme_template', true, true);
	}

	/**
	 * Emit brand-token overrides for the kit's :root custom properties.
	 *
	 * Resolution per token, lowest to highest precedence:
	 *   1. kit default (joinery-styles.css :root) — left untouched if nothing overrides it here
	 *   2. the rendering theme's theme.json "brand_tokens" (developer-declared default)
	 *   3. a matching stg_settings value, if an admin set one (admin override wins)
	 *
	 * Nothing is copied into the database: an empty setting simply defers to the
	 * theme's declared brand, so switching themes picks up the new brand with no
	 * stale rows to reconcile. Token keys use the setting-style name
	 * (jy_color_primary) and map to the CSS property by --{name-with-dashes}.
	 */
	protected function render_brand_token_overrides() {
		$settings = Globalvars::get_instance();

		// Layer 2: brand tokens declared by the rendering theme.
		$resolved = [];
		try {
			$theme = ThemeHelper::getInstance($this->get_render_theme());
			$declared = $theme->get('brand_tokens', []);
			if (is_array($declared)) {
				foreach ($declared as $name => $val) {
					if (is_string($name)) { $resolved[$name] = $val; }
				}
			}
		} catch (Exception $e) {
			// No resolvable theme/manifest — fall through to settings only.
		}

		// Layer 3: admin overrides. Only the settings-backed colour tokens have
		// stg_settings rows; a non-empty value wins over the theme's declaration.
		$setting_backed = [
			'jy_color_primary', 'jy_color_primary_hover', 'jy_color_primary_text',
			'jy_color_surface', 'jy_color_bg',
		];
		foreach ($setting_backed as $name) {
			$val = $settings->get_setting($name, false, true);
			if (is_string($val) && $val !== '') { $resolved[$name] = $val; }
		}

		// Emit, validating each name and value for the CSS context.
		$overrides = [];
		foreach ($resolved as $name => $val) {
			if (!is_string($name) || !preg_match('/^jy_[a-z0-9_]+$/', $name)) { continue; }
			if (!self::is_safe_css_token_value($val)) { continue; }
			$prop = '--' . str_replace('_', '-', $name);
			$overrides[] = '  ' . $prop . ': ' . trim($val) . ';';
		}

		if (empty($overrides)) { return; }
		echo '<style id="jy-brand-tokens">:root {' . "\n" . implode("\n", $overrides) . "\n" . '}</style>' . "\n"; // jy-allow-style: server-computed brand tokens, validated above
	}

	/**
	 * Whether a brand-token value is safe to emit inside a <style> declaration.
	 * Allows only the conservative CSS value charset used by colours, lengths,
	 * numbers, keywords, var() references, and font stacks — which excludes the
	 * characters ( ; { } < > \ @ and control chars) that could break out of the
	 * declaration or inject markup.
	 */
	private static function is_safe_css_token_value($val): bool {
		if (!is_string($val)) { return false; }
		$val = trim($val);
		if ($val === '' || strlen($val) > 200) { return false; }
		return (bool) preg_match('~^[#A-Za-z0-9 ,.\'"()%/-]+$~', $val);
	}

	public function public_header_common($options=array()) {
		$_GLOBALS['page_header_loaded'] = true;
		
		if(!isset($options['is_404'])){
			$options['is_404'] = 0;
		}		
		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();
		
		// SECURITY HEADERS — must run before ANY output below (the admin-bar
		// <style>/<script> echoed for admins), or header() warns "headers already sent".
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
				header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
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

		// App display mode: tag the body so page CSS can adapt to chrome-less
		// rendering (the jy-app-mode hook). Themes omit the chrome server-side
		// via show_site_chrome(); this class is the styling hook.
		if (!$this->show_site_chrome()) {
			echo '<script>document.addEventListener("DOMContentLoaded",function(){document.body.classList.add("jy-app-mode");});</script>' . "\n";
		}

		// Floating Admin chip: gives a permission-5+ user browsing the public
		// site a one-click path into the admin area. Member pages already carry
		// an Admin header button and admin pages are the destination, so
		// neither shows the chip (see should_show_admin_chip()).
		if ($this->should_show_admin_chip()) {
			$this->render_admin_chip_css();
			echo '<script>document.addEventListener("DOMContentLoaded", function() {'
				. 'document.body.insertAdjacentHTML("beforeend", ' . json_encode($this->get_admin_chip_html()) . ');'
				. '});</script>';
		}

		// NOTE: Do not default $options['title'] / $options['meta_description'] here.
		// global_includes_top() runs the full precedence chain (override → options →
		// inferred → site setting). Pre-populating $options would short-circuit inference.

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
	/**
	 * A pasteable value with a Copy button: a readonly input (click selects
	 * all, so manual copy still works) beside a button wired to the base.js
	 * data-jy-copy handler. Use wherever the user is expected to carry a value
	 * into another system (DNS records, keys, URLs).
	 *
	 * @return string HTML
	 */
	static function copy_field($value) {
		$esc = htmlspecialchars($value);
		return '<span class="jy-copyfield">'
			. '<input type="text" class="form-control form-control-sm" readonly value="' . $esc . '" onclick="this.select()">'
			. '<button type="button" class="btn btn-sm btn-outline-secondary" data-jy-copy="' . $esc . '">Copy</button>'
			. '</span>';
	}

	static function action_button($label, $url, $options = []) {
		$hidden_fields = isset($options['hidden']) ? $options['hidden'] : [];
		$confirm_msg   = isset($options['confirm']) ? $options['confirm'] : '';
		$typed_phrase  = isset($options['confirm_typed']) ? $options['confirm_typed'] : '';
		$extra_class   = isset($options['class'])   ? ' ' . $options['class'] : '';

		$btn_onclick = '';
		if ($confirm_msg && $typed_phrase) {
			// Irreversible action: the modal demands the exact phrase be typed
			// before the confirm button enables.
			$escaped        = addslashes(htmlspecialchars($confirm_msg, ENT_QUOTES));
			$escaped_phrase = addslashes(htmlspecialchars($typed_phrase, ENT_QUOTES));
			$btn_onclick = ' onclick="var f=this.closest(\'form\'); JoineryModal.confirmTyped(\'' . $escaped . '\', \'' . $escaped_phrase . '\', function(){ f.submit(); });"';
		} else if ($confirm_msg) {
			$escaped = addslashes(htmlspecialchars($confirm_msg, ENT_QUOTES));
			$btn_onclick = ' onclick="var f=this.closest(\'form\'); JoineryModal.confirm(\'' . $escaped . '\', function(){ f.submit(); });"';
		}

		$html = '<form method="POST" action="' . htmlspecialchars($url) . '" style="display:inline;">';
		foreach ($hidden_fields as $name => $value) {
			$html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
		}
		$btn_class = ($extra_class !== '') ? ltrim($extra_class) : 'btn btn-soft-default btn-sm';
		$btn_type  = $confirm_msg ? 'button' : 'submit';
		$html .= '<button type="' . $btn_type . '" class="' . $btn_class . '"' . $btn_onclick . '>' . htmlspecialchars($label) . '</button>';
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
	 * Get the admin chip HTML as a string for JavaScript injection.
	 */
	private function get_admin_chip_html() {
		if (!$this->should_show_admin_chip()) {
			return '';
		}
		ob_start();
		$this->render_admin_chip();
		return ob_get_clean();
	}


	function tableheader($headers, $options=array(), $pager=NULL){
		// Store options for use in endtable
		$this->current_table_options = $options;
		// Store headers so disprow() can stamp each cell with its column label,
		// which the stacked-card mobile layout renders as "Header: value" pairs.
		$this->current_table_headers = array_values($headers);

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

		// Column headers captured in tableheader(), used as per-cell labels for the
		// stacked-card mobile layout. The data-label attribute and cell-primary class
		// are inert at desktop width (no CSS consumes them there) and on themes that
		// lack the mobile card styles — they only take effect below 767px.
		$headers = isset($this->current_table_headers) ? $this->current_table_headers : array();
		$i = 0;
		foreach ($dataarray as $value) {
			if ($value == "") {
				$value = "&nbsp";
			}
			$label = isset($headers[$i]) ? trim(strip_tags((string)$headers[$i])) : '';
			$attr  = $label !== '' ? ' data-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' : '';
			$cls   = $i === 0 ? ' class="cell-primary"' : '';
			printf('<td%s%s>%s</td>', $cls, $attr, $value);
			$i++;
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
			// `in_card` tells the renderer whether the surrounding container
			// has no horizontal padding and the pager must supply its own.
			// `endtable()` is always invoked for a table box, whose card-body
			// uses `p-0` so the table can run edge-to-edge — so the pager
			// always needs padding here, regardless of the caller's `card`
			// option.
			$pagination_data = [
				'num_records'   => $pager->num_records(),
				'current_page'  => $pager->current_page(),
				'total_pages'   => $pager->total_pages(),
				'show_controls' => ($pager->num_records() > $pager->num_per_page()),
				'in_card'       => true,
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

	// =====================================================================
	// Box variants — saying what a panel IS
	// =====================================================================
	// A box rendered inside another box is, by default, indistinguishable from
	// its siblings: same header bar, same width, same flat surface. Nothing in
	// the markup says "this one belongs to the panel above" or "this one is the
	// thing you are doing". Pass 'variant' to begin_box() to say so:
	//
	//   'nested' — a panel that belongs to the one containing it. Indented and
	//              tied to its parent by a spine down its left edge.
	//   'focus'  — the task at hand, lifted off the page onto its own surface
	//              on a recessed stage. A modal that stayed in the flow of the
	//              page, so what surrounds it is still readable.
	//
	// Presentation lives in the shared kit stylesheet (assets/css/joinery-styles.css),
	// so every theme that links it gets the same two shapes for free.

	/** The boxes currently open, innermost last. */
	protected $_box_open_stack = array();

	/**
	 * Note a box being opened, and hand back its variant for rendering.
	 *
	 * A STACK, NOT A FIELD, because end_box() is called with no arguments almost
	 * everywhere: what a box was opened as cannot be read back from what closes
	 * it, and boxes nest — which is the entire point of the variant option.
	 */
	protected function pushBoxVariant($options) {
		$variant = isset($options['variant']) ? (string)$options['variant'] : '';
		if ($variant !== 'nested' && $variant !== 'focus') {
			$variant = '';
		}
		$this->_box_open_stack[] = array(
			'variant' => $variant,
			'card'    => (isset($options['card']) && $options['card'] === true),
		);
		return $variant;
	}

	/** The variant of the innermost open box, removing it from the stack. */
	protected function popBoxVariant() {
		if (empty($this->_box_open_stack)) {
			return '';
		}
		$open = array_pop($this->_box_open_stack);
		return (string)$open['variant'];
	}

	/**
	 * Whether the box being closed opened a card, WITHOUT popping it.
	 *
	 * A caller that hands end_box() the options it opened with decides for
	 * itself — that is how every table box works, and it must keep working
	 * exactly as it did. The stack answers for the rest: `card` opens three
	 * elements and closes one, so a bare end_box() reading only its own empty
	 * arguments would leave the card element open and swallow the remainder of
	 * the page into it.
	 */
	protected function boxClosesCard($options) {
		if (array_key_exists('card', $options)) {
			return ($options['card'] === true);
		}
		$open = end($this->_box_open_stack);
		return (is_array($open) && !empty($open['card']));
	}

	/** Opening wrapper for a variant box. A 'focus' box also gets its stage. */
	protected function renderBoxVariantOpen($variant) {
		if ($variant === 'focus') {
			echo '<div class="jy-box-stage"><div class="jy-box jy-box-focus">';
		} elseif ($variant === 'nested') {
			echo '<div class="jy-box jy-box-nested">';
		}
	}

	/** Closing wrapper, matching renderBoxVariantOpen. */
	protected function renderBoxVariantClose($variant) {
		if ($variant === 'focus') {
			echo '</div></div>';
		} elseif ($variant === 'nested') {
			echo '</div>';
		}
	}

	/**
	 * Render the opening markup for a content box/card
	 * Override in subclasses for framework-specific markup
	 */
	protected function renderBoxOpen($options) {
		$use_card = isset($options['card']) && $options['card'] === true;
		$this->renderBoxVariantOpen($this->pushBoxVariant($options));

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
		$use_card = $this->boxClosesCard($options);

		if ($use_card) {
			echo '</div>';
			echo '</div>';
		} else {
			echo '</div>';
		}

		$this->renderBoxVariantClose($this->popBoxVariant());
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
	 * Whether the floating Admin chip should render on this page.
	 * Requires permission 5+ and the show_admin_bar setting (default: on).
	 * Suppressed in app webviews, in the member area (its header already has an
	 * Admin button), and on admin pages (the chip's destination).
	 */
	protected function should_show_admin_chip() {
		$session = SessionControl::get_instance();
		// The chip is site chrome — never shown inside app webviews.
		if ($session->is_app_session()) {
			return false;
		}
		if ($session->get_permission() < 5) {
			return false;
		}
		if ($this->in_member_area()) {
			return false;
		}
		$path = $this->request_path();
		if (str_starts_with($path, '/admin') || preg_match('#^/plugins/[^/]+/admin(/|$)#', $path)) {
			return false;
		}
		$settings = Globalvars::get_instance();
		$setting = $settings->get_setting('show_admin_bar', false, true);
		// Default to enabled if setting doesn't exist
		return ($setting === null || $setting === '' || $setting);
	}

	/**
	 * Render the floating Admin chip CSS.
	 */
	protected function render_admin_chip_css() {
		if (!$this->should_show_admin_chip()) {
			return;
		}
		?>
		<style id="joinery-admin-chip-css">
			#joinery-admin-chip {
				position: fixed;
				bottom: 16px;
				left: 16px;
				z-index: 9999;
				display: inline-flex;
				align-items: center;
				gap: 6px;
				background: #23282d;
				color: #eee;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				font-size: 13px;
				font-weight: 500;
				line-height: 1;
				padding: 8px 14px;
				border-radius: 999px;
				text-decoration: none;
				box-shadow: 0 2px 8px rgba(0,0,0,.25);
				opacity: .85;
			}
			#joinery-admin-chip:hover {
				opacity: 1;
				color: #fff;
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
	 * Render the floating Admin chip — a single link into the admin area.
	 */
	public function render_admin_chip() {
		if (!$this->should_show_admin_chip()) {
			return;
		}
		?>
		<a id="joinery-admin-chip" href="/admin" title="Admin area">
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
			Admin
		</a>
		<?php
	}
}

?>
