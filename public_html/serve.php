<?php
// serve.php - Hybrid routing system with RouteHelper
// Core dependencies (PathHelper, Globalvars, SessionControl) are loaded by RouteHelper after static route check
// @version 1.4.0

// RouteHelper handles all routing and dependency loading
require_once(__DIR__ . '/includes/RouteHelper.php');

/*
 * UNIFIED ROUTING SYSTEM DOCUMENTATION
 * 
 * IMPORTANT: 
 * - Routes should be unique across all categories (static, dynamic, custom)
 * - The system processes routes in order: static → plugins → custom → dynamic → view fallback → 404
 * - If the same pattern exists in multiple categories, only the first match will be processed
 * - NEVER include .php extensions in route configurations - RouteHelper adds them automatically
 * 
 * Route types and their options:
 * 
 * STATIC ROUTES - Serve ONLY static assets (CSS, JS, images, fonts) with caching
 * '/favicon.ico' => ['cache' => 43200]         // Static asset file
 * '/theme/{theme}/assets/*' => ['cache' => 43200]            // Theme assets with caching
 * '/static_files/*' => ['cache' => 43200, 'exclude_from_cache' => ['.upg.zip']]  // Don't cache upgrade files
 * '/plugins/{plugin}/assets/*' => ['cache' => 43200]  // Plugin activation always automatic (non-overridable)
 * NOTE: Static routes should NEVER serve PHP files or dynamic content
 * 
 * DYNAMIC ROUTES - View-based routing with optional URL placeholders
 * '/login' => ['view' => 'views/login']        // Simple view file
 * '/robots.txt' => ['view' => 'views/robots']  // Dynamic content (PHP-generated)
 * '/api/v1/*' => ['view' => 'api/apiv1']       // Explicit view file
 * '/admin/*' => ['view' => 'adm/{path}']       // {path} placeholder for dynamic part
 * '/profile/*' => ['view' => 'views/profile/{path}']
 * '/ajax/*' => ['view' => 'ajax/{file}']       // Plugin override automatic
 * '/utils/*' => ['view' => 'utils/{file}']     // Plugin override automatic
 * '/page/{slug}' => ['view' => 'views/page', 'check_setting' => 'page_contents_active']  // {slug} captured into $params['slug']
 * '/event/{slug}/{date}' => ['view' => 'views/event']  // Multiple URL placeholders
 *
 * NOTE: All routes set $is_valid_page = true by default
 * Use ['valid_page' => false] to override for non-tracked pages
 *
 * CUSTOM ROUTES - Complex logic with PHP closures
 * '/complex' => function($params, $settings, $session, $template_directory) {
 *     // Custom logic here
 *     // Return true if handled, false if not
 * }
 *
 * PATH RESOLUTION RULES:
 * - {path} placeholder: /admin/users/edit with 'adm/{path}' -> adm/users/edit
 * - {file} placeholder: /ajax/endpoint with 'ajax/{file}' -> ajax/endpoint
 * - {slug}, {id}, etc.: extracted into $params and made available to the view
 * - Static files -> serve directly with proper MIME types and caching
 * - Plugin overrides: ajax/utils routes automatically check plugins first, then main files
 * - View directory fallback: /login -> theme/falcon/views/login (theme) OR views/login (base)
 *
 * AUTOMATIC FEATURES:
 * - Plugin activation checking (automatic for ALL /plugins/* paths - non-overridable)
 * - Database URL redirect checking (before route processing)
 * - Path validation with helpful error messages (prevents common path mistakes)
 * - $is_valid_page = true (unless 'valid_page' => false)
 * - Theme override checking (theme files before base files)
 * - Plugin override checking (plugins checked first for all routes)
 * - Parameter extraction from {slug}, {id}, etc. into $params
 * - Feature flag checking via 'check_setting'
 * - MIME type detection and HTTP caching headers
 * - View directory fallback (automatic theme-aware lookup for any path)
 *
 * ROUTE OPTIONS:
 * Static routes:
 * - 'cache' => 43200 - Cache time in seconds for static files
 * - 'exclude_from_cache' => ['.ext'] - File extensions to not cache (short cache instead)
 *
 * Dynamic routes:
 * - 'view' => 'path/file' - View file to serve (required)
 * - 'check_setting' => 'setting_name' - Only serve if setting is active
 * - 'valid_page' => false - Don't count this route for statistics (default: true)
 * - 'min_permission' => 10 - Minimum permission level required to access route (uses SessionControl)
 *
 * Custom routes:
 * - PHP closure that returns true if handled, false otherwise
 */

// ROUTE DEFINITIONS - Hybrid approach with proper asset/dynamic separation
$routes = [
    // Static file routes - ONLY for actual assets (CSS, JS, images, fonts, etc.)
    'static' => [
        '/assets/*' => ['cache' => 43200],  // Global system assets
        // Semantic placeholders for clear segment control
        '/plugins/{plugin}/assets/*' => ['cache' => 43200],
        '/theme/{theme}/assets/*' => ['cache' => 43200],
        '/static_files/*' => ['cache' => 43200, 'exclude_from_cache' => ['.upg.zip']],
        '/favicon.ico' => ['cache' => 43200],
        // TEMPORARY: content staging area for copy editing — remove when done
        '/content_staging/*' => ['cache' => 0],
        // REMOVED: '/plugins/ * /includes/*' - All plugins now use /assets/
        // REMOVED: '/includes/*' - No static files should be in /includes/ anymore
        // REMOVED: '/adm/includes/*' - Admin should use proper asset organization
        // REMOVED: '/theme/*' - Too broad, use specific /theme/{theme}/assets/* instead
    ],
    
    // Dynamic routes (unified content + simple routes)
    'dynamic' => [
        // Slug-based content routes
        '/post/{slug}'         => ['view' => 'views/post', 'check_setting' => 'blog_active'],
        '/page/{slug}'         => ['view' => 'views/page', 'check_setting' => 'page_contents_active'],
        // Drive public share links (anonymous-safe; the link is the grant).
        '/s/{token}'           => ['view' => 'views/share', 'check_setting' => 'drive_active'],
        // event_manager: ICS handler routes — MUST precede '/event/{slug}' (which
        // would otherwise capture the '.ics' URLs with the suffix in {slug}).
        '/event/{slug}/{date}.ics' => ['handler' => 'includes/ics_event_route',    'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/event/{slug}.ics'        => ['handler' => 'includes/ics_event_route',    'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/events/calendar.ics'     => ['handler' => 'includes/ics_calendar_route', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/event/{slug}/{date}' => ['view' => 'views/event', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/event/{slug}'        => ['view' => 'views/event', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/location/{slug}'     => ['view' => 'views/location', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/product/{slug}'      => ['view' => 'views/product', 'plugin' => 'store', 'check_setting' => 'products_active'],
        '/list/{slug}'         => ['view' => 'views/list'],
        '/video/{slug}'        => ['view' => 'views/video', 'check_setting' => 'videos_active'],
        '/book/{slug}'         => ['view' => 'plugins/bookings/views/book', 'check_setting' => 'bookings_active'],
        '/booking/manage'      => ['view' => 'plugins/bookings/views/booking_manage', 'check_setting' => 'bookings_active'],

        // ---- store: view routes (files live in plugins/store/views) ----
        '/products'    => ['view' => 'views/products',    'plugin' => 'store', 'check_setting' => 'products_list_items_active'],
        '/cart'        => ['view' => 'views/cart',        'plugin' => 'store', 'check_setting' => 'products_active'],
        '/checkout'    => ['view' => 'views/checkout',    'plugin' => 'store', 'check_setting' => 'products_active'],
        '/pricing'     => ['view' => 'views/pricing',     'plugin' => 'store', 'check_setting' => 'subscriptions_active'],
        // Purchase-flow endpoints — Stripe success_url / card-form POST land on
        // /cart_charge, which redirects to /cart_confirm; /cart_clear empties the cart.
        '/cart_charge'  => ['view' => 'views/cart_charge',  'plugin' => 'store', 'check_setting' => 'products_active'],
        '/cart_confirm' => ['view' => 'views/cart_confirm', 'plugin' => 'store', 'check_setting' => 'products_active'],
        '/cart_clear'   => ['view' => 'views/cart_clear',   'plugin' => 'store', 'check_setting' => 'products_active'],

        // Simple view routes (explicit view files)
        '/robots.txt' => ['view' => 'views/robots'],
        '/sitemap.xml' => ['view' => 'views/sitemap'],
        '/index' => ['view' => 'views/index'],
        '/register' => ['view' => 'views/register'],
        
        // System routes with placeholders
        '/api/v1/*' => ['view' => 'api/apiv1'],
        '/admin/*' => ['view' => 'adm/{path}', 'min_permission' => 5],
        '/ajax/*' => ['view' => 'ajax/{file}'],
        '/utils/upgrade' => ['view' => 'utils/upgrade'],              // Public API: own auth via plugin activation / CLI
        '/utils/*' => ['view' => 'utils/{file}'],
        '/tests/*' => ['view' => 'tests/{path}', 'min_permission' => 10],
        
        // Optional: Explicit route for views directory access (if needed)
        '/views/*' => ['view' => 'views/{path}'],
        
        // Routes with special features
        '/profile' => ['view' => 'views/profile/profile'],
        // Recurring calendar occurrence edit — must precede the wildcard.
        '/profile/calendar/entry/{parent_id}/occurrence/{occurrence_date}' => ['view' => 'views/profile/calendar'],
        '/profile/calendar/entry/{entry_id}' => ['view' => 'views/profile/calendar'],
        // ---- store: profile view routes (BEFORE the /profile/* wildcard) ----
        '/profile/orders'                  => ['view' => 'views/profile/orders',                  'plugin' => 'store', 'check_setting' => 'products_active'],
        '/profile/billing'                 => ['view' => 'views/profile/billing',                 'plugin' => 'store', 'check_setting' => 'subscriptions_active'],
        '/profile/subscriptions'           => ['view' => 'views/profile/subscriptions',           'plugin' => 'store', 'check_setting' => 'subscriptions_active'],
        '/profile/change-tier'             => ['view' => 'views/profile/change-tier',             'plugin' => 'store', 'check_setting' => 'subscriptions_active'],
        '/profile/orders_recurring_action' => ['view' => 'views/profile/orders_recurring_action', 'plugin' => 'store', 'check_setting' => 'subscriptions_active'],
        // event_manager: profile view routes (BEFORE the /profile/* wildcard)
        '/profile/events'                => ['view' => 'views/profile/events',                'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/profile/event_sessions'        => ['view' => 'views/profile/event_sessions',        'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/profile/event_sessions_course' => ['view' => 'views/profile/event_sessions_course', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/profile/event_withdraw'        => ['view' => 'views/profile/event_withdraw',        'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/profile/*' => ['view' => 'views/profile/{path}'],
        '/events' => ['view' => 'views/events', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        '/event_waiting_list' => ['view' => 'views/event_waiting_list', 'plugin' => 'event_manager', 'check_setting' => 'events_active'],
        
        // NOTE: Simple routes like '/login', '/register', '/logout', '/products', '/pricing', 
        // '/lists', '/booking', '/cart', '/survey', '/password-reset-1', '/password-reset-2', 
        // '/password-set', '/site-directory', '/rss20_feed' are now UNNECESSARY - handled by view directory fallback.
        // They will automatically resolve to views/login.php, views/products.php, etc.
    ],
    
    // Routes with custom handling (complex logic preserved)
    'custom' => [
        // Admin landing: /admin has no page of its own — the users list is
        // the entry point (permission is enforced by the target page).
        '/admin' => function($params, $settings, $session, $template_directory) {
            header('Location: /admin/admin_users');
            exit;
        },
        // Plugin admin discovery
        '/plugins/{plugin}/admin/*' => function($params, $settings, $session, $template_directory) {
            // $params is URL segments: [0]="", [1]="plugins", [2]="scrolldaddy", [3]="admin", [4]="admin_ctld_account"
            $plugin = $params[2] ?? '';
            $admin_page = $params[4] ?? 'index';
            $admin_file = "plugins/{$plugin}/admin/{$admin_page}.php";

            if (file_exists($admin_file)) {
                $is_valid_page = true;
                require_once($admin_file);
                return true;
            }
            return false;
        },

        // Plugin tests discovery (requires superadmin)
        '/plugins/{plugin}/tests/*' => function($params, $settings, $session, $template_directory) {
            // Require superadmin permission for tests (level 10)
            $session->check_permission(10);

            $plugin = $params[2] ?? '';
            $test_page = $params[4] ?? 'run';
            $test_file = "plugins/{$plugin}/tests/{$test_page}.php";

            if (file_exists($test_file)) {
                $is_valid_page = true;
                require_once($test_file);
                return true;
            }
            return false;
        },
        
        // Homepage with alternate-homepage routing
        // The alternate_loggedin_homepage / alternate_homepage settings can hold:
        //   - "/blog"          -> render blog view here
        //   - "/page/{slug}"   -> render that page's contents here
        //   - any other URL    -> 302 redirect to it (so the front controller routes it normally)
        '/' => function($params, $settings, $session, $template_directory) {
            $alternate_page = '';
            if($session->is_logged_in()){
                $alternate_page = (string)$settings->get_setting('alternate_loggedin_homepage');
            }
            if($alternate_page === ''){
                $alternate_page = (string)$settings->get_setting('alternate_homepage');
            }

            $template_file = '';
            $base_file = '';

            if($alternate_page !== ''){
                $page_pieces = explode('/', $alternate_page);
                $first_segment = $page_pieces[1] ?? '';
                if($first_segment === 'blog'){
                    $template_file = $template_directory.'/views/blog.php';
                    $base_file = PathHelper::getIncludePath('views/blog.php');
                } else if($first_segment === 'page'){
                    if($session->is_logged_in() || $settings->get_setting('page_contents_active')){
                        require_once(PathHelper::getIncludePath('data/pages_class.php'));
                        $page = Page::get_by_link($page_pieces[2] ?? '', true);
                        $template_file = $template_directory.'/views/page.php';
                        $base_file = PathHelper::getIncludePath('views/page.php');
                    }
                } else if($alternate_page !== '/' && $alternate_page[0] === '/'){
                    header('Location: '.$alternate_page, true, 302);
                    return true;
                }
            }

            // Default homepage: also used as fallback when the alternate settings
            // don't resolve to a real file (prevents silent blank 200 responses).
            if($template_file === '' || !file_exists($template_file)){
                $template_file = $template_directory.'/views/index.php';
            }
            if($base_file === '' || !file_exists($base_file)){
                $base_file = PathHelper::getIncludePath('views/index.php');
            }

            // RouteHelper automatically sets $is_valid_page = true when a route matches

            if(file_exists($template_file)){
                require_once($template_file);
            } else if(file_exists($base_file)){
                require_once($base_file);
            }
            return true; // Handled
        },
        
        // Uploads with authentication
        '/uploads/*' => function($params, $settings, $session) {
            if(!$settings->get_setting('files_active')) return false;

            $upload_dir = $settings->get_setting('upload_dir');
            $fast_dir = dirname($upload_dir) . '/static_files/uploads';
            // Build the full path from all params after "uploads"
            // params[0] is empty, params[1] is "uploads", params[2+] is the subpath
            $subpath_parts = array_slice($params, 2);
            $subpath = implode('/', $subpath_parts);
            $basename = basename($subpath);

            require_once(PathHelper::getIncludePath('data/files_class.php'));

            // Cloud-stored files: bytes live in a bucket.
            //  - PUBLIC files honor /uploads/<filename> URLs by 302-redirecting
            //    to the world-readable bucket URL (browser caches the redirect
            //    24h, so subsequent hits skip PHP).
            //  - PRIVATE files are NEVER 302'd: the bucket URL is forbidden and
            //    exposing it is itself the leak. They are streamed through PHP
            //    from the verified-private bucket, gated by the same is_viewable()
            //    check the local path uses — the bytes never bypass the gate.
            $file_obj = File::get_by_name($basename);

            // Size key from the URL path: '/uploads/<size>/<file>' or '/uploads/<file>'.
            $size_key = (count($subpath_parts) > 1) ? $subpath_parts[0] : 'original';

            // Signed request? A valid, unexpired signature authorizes on its
            // own — minting is the authorization statement (see
            // docs/file_signed_urls.md). Anything less falls through to the
            // normal is_viewable() gate, never an error of its own.
            $signed_ok = false;
            if ($file_obj && isset($_GET['expires'], $_GET['sig'])) {
                $signed_ok = $file_obj->verify_signed_request($size_key, $_GET['expires'], $_GET['sig']);
            }

            if ($file_obj && $file_obj->storage_driver() === 'cloud') {
                require_once(PathHelper::getIncludePath('includes/cloud_storage/CloudStorageDriverFactory.php'));

                if ($file_obj->is_public()) {
                    $driver = CloudStorageDriverFactory::default();
                    if ($driver) {
                        $url = $driver->url($file_obj->remote_key_for($size_key));
                        header('Cache-Control: public, max-age=86400');
                        header('Location: ' . $url, true, 302);
                        return true;
                    }
                    // Fall through to local check if driver isn't available.
                } else {
                    // Private cloud file: gate first (404, never 403, to avoid
                    // confirming existence — same as the local restricted path).
                    if (!$signed_ok && !$file_obj->is_viewable($session)) {
                        require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
                        LibraryFunctions::display_404_page();
                        return true;
                    }
                    $driver = CloudStorageDriverFactory::forVisibilityWithFallback('private');
                    if ($driver) {
                        $tmp = tempnam(sys_get_temp_dir(), 'fil_priv_');
                        $got = false;
                        if ($tmp !== false) {
                            try {
                                $driver->get($file_obj->remote_key_for($size_key), $tmp);
                                $got = true;
                            } catch (Exception $e) {
                                error_log('Private cloud serve: GET failed for fil=' . $file_obj->key . ' — ' . $e->getMessage());
                            }
                        }
                        if ($got) {
                            $file_obj->serve_from_path($tmp, 'private, max-age=0, no-store');
                            @unlink($tmp);
                            return true;
                        }
                        if ($tmp !== false) { @unlink($tmp); }
                        require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
                        LibraryFunctions::display_404_page();
                        return true;
                    }
                    // Private driver unconfigured: fall through to local check (degraded).
                }
            }

            // Resolve the local bytes through the blob (keyed on the physical
            // stored name), so a dedup secondary — whose fil_name has no file of
            // its own — still finds the shared bytes. Fall back to the raw URL
            // subpath only when there is no File row to resolve through.
            $file = null;
            if ($file_obj) {
                $candidate = $file_obj->get_filesystem_path($size_key);
                if (file_exists($candidate)) {
                    $file = $candidate;
                }
            }
            if ($file === null) {
                if (file_exists($upload_dir . '/' . $subpath)) {
                    $file = $upload_dir . '/' . $subpath;
                } elseif (file_exists($fast_dir . '/' . $subpath)) {
                    $file = $fast_dir . '/' . $subpath;
                }
            }

            if($file){
                if(!$file_obj){
                    $file_obj = File::get_by_name($basename);
                }

                if($file_obj && ($signed_ok || $file_obj->is_viewable($session))){
                    if ($signed_ok) {
                        // Signed grant: the response must not outlive it, so
                        // stream with no-store instead of a cacheable posture
                        // (same as the private-cloud branch).
                        $file_obj->serve_from_path($file, 'private, no-store');
                        return true;
                    }
                    // Gated (or not-yet-offloaded) local file: File owns the
                    // serve-back headers; permission-restricted bytes must not
                    // land in shared caches.
                    $cache_control = $file_obj->is_public() ? 'public, max-age=43200' : 'private, max-age=43200';
                    $file_obj->serve_from_path($file, $cache_control);
                    return true;
                } else {
                    require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
                    LibraryFunctions::display_404_page();
                    return true;
                }
            }
            return false;
        },
        
        
        // Blog with tag support
        '/blog/*' => function($params, $settings, $session, $template_directory) {
            if(!$settings->get_setting('blog_active')) return false;

            $is_valid_page = true; // Already in scope from earlier
            // $params already in scope
            require_once(PathHelper::getThemeFilePath('blog.php', 'views'));
            return true;
        },
    ],
];

// Emit Joinery version header so HTTP health checks can detect upgrade availability
// without needing a full API or SSH check. Read directly from VERSION to avoid loading
// LibraryFunctions before it's needed.
$_vjv_file = __DIR__ . '/VERSION';
if (is_file($_vjv_file) && ($v = trim(@file_get_contents($_vjv_file))) !== '') {
    header('X-Joinery-Version: ' . $v);
}
unset($_vjv_file, $v);

// ROUTE PROCESSING - All logic moved to RouteHelper::processRoutes()
RouteHelper::processRoutes($routes, $_REQUEST['__route'] ?? '');