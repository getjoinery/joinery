<?php
// serve.php - Hybrid routing system with RouteHelper
// Core dependencies (PathHelper, Globalvars, SessionControl) are loaded by RouteHelper after static route check

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
 * DYNAMIC ROUTES - Unified system for all dynamic content (views + models)
 * Simple view routes:
 * '/login' => ['view' => 'views/login']        // Simple view file
 * '/robots.txt' => ['view' => 'views/robots']  // Dynamic content (PHP-generated)
 * '/api/v1/*' => ['view' => 'api/apiv1']       // Explicit view file
 * '/admin/*' => ['view' => 'adm/{path}']       // {path} placeholder for dynamic part
 * '/profile/*' => ['view' => 'views/profile/{path}', 'default_view' => 'views/profile/profile']  // With fallback
 * '/ajax/*' => ['view' => 'ajax/{file}']       // Plugin override automatic
 * '/utils/*' => ['view' => 'utils/{file}']     // Plugin override automatic
 *
 * Model-based routes (optional model loading):
 * '/page/{slug}' => ['model' => 'Page', 'model_file' => 'data/pages_class']  // Auto-determined view: views/page
 * '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class', 'check_setting' => 'blog_active']  // With feature flag check
 * '/item/{id}' => ['model' => 'Item', 'model_file' => 'data/items_class', 'valid_page' => false]  // Don't count for stats
 * '/custom/{slug}' => ['model' => 'Custom', 'model_file' => 'plugins/myplugin/data/customs_class']  // Plugin-specific model
 * '/item/{slug}' => ['model' => 'Item', 'model_file' => 'data/items_class', 'view' => 'views/profile/item']  // Custom view path
 *
 * Mixed routes (model + path placeholders + fallbacks):
 * '/user/{action}' => ['model' => 'User', 'model_file' => 'data/users_class', 'view' => 'views/user/{action}', 'default_view' => 'views/user/profile']
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
 * - Model routes: /page/{slug} with model 'Page' -> data/pages_class + views/page
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
 * - Parameter extraction from {slug}, {id}, etc.
 * - Feature flag checking via 'check_setting'
 * - Model loading and instantiation
 * - MIME type detection and HTTP caching headers
 * - View directory fallback (automatic theme-aware lookup for any path)
 * 
 * ROUTE OPTIONS:
 * Static routes:
 * - 'cache' => 43200 - Cache time in seconds for static files
 * - 'exclude_from_cache' => ['.ext'] - File extensions to not cache (short cache instead)
 *
 * Dynamic routes:
 * - 'view' => 'path/file' - Explicit view file to serve (required unless model specified)
 * - 'model' => 'ClassName' - Load model class and instantiate object (optional)
 * - 'model_file' => 'path/to/model_class' - Explicit model file path (required when model specified)
 * - 'check_setting' => 'setting_name' - Only serve if setting is active
 * - 'valid_page' => false - Don't count this route for statistics (default: true)
 * - 'default_view' => 'path/file' - Fallback view when no specific file matches
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
        // REMOVED: '/plugins/ * /includes/*' - All plugins now use /assets/
        // REMOVED: '/includes/*' - No static files should be in /includes/ anymore
        // REMOVED: '/adm/includes/*' - Admin should use proper asset organization
        // REMOVED: '/theme/*' - Too broad, use specific /theme/{theme}/assets/* instead
    ],
    
    // Dynamic routes (unified content + simple routes)
    'dynamic' => [
        // Model-based content routes
        '/post/{slug}' => ['model' => 'Post', 'model_file' => 'data/posts_class', 'check_setting' => 'blog_active'],
        '/page/{slug}' => ['model' => 'Page', 'model_file' => 'data/pages_class', 'check_setting' => 'page_contents_active'],
        '/event/{slug}' => ['model' => 'Event', 'model_file' => 'data/events_class', 'check_setting' => 'events_active'],
        '/location/{slug}' => ['model' => 'Location', 'model_file' => 'data/locations_class', 'check_setting' => 'events_active'],
        '/product/{slug}' => ['model' => 'Product', 'model_file' => 'data/products_class', 'check_setting' => 'products_active'],
        '/list/{slug}' => ['model' => 'MailingList', 'model_file' => 'data/mailinglists_class'],
		'/video/{slug}' => ['model' => 'Video', 'model_file' => 'data/videos_class', 'check_setting' => 'videos_active'],
        
        // Simple view routes (explicit view files)
        '/robots.txt' => ['view' => 'views/robots'],
        '/sitemap.xml' => ['view' => 'views/sitemap'],
        '/index' => ['view' => 'views/index'],
        '/register' => ['view' => 'views/register'],
        
        // System routes with placeholders
        '/api/v1/*' => ['view' => 'api/apiv1'],
        '/admin/*' => ['view' => 'adm/{path}'],
        '/ajax/*' => ['view' => 'ajax/{file}'],
        '/utils/*' => ['view' => 'utils/{file}'],
        '/tests/*' => ['view' => 'tests/{path}', 'min_permission' => 10],  // Test routes probably shouldn't be in production
        
        // Optional: Explicit route for views directory access (if needed)
        '/views/*' => ['view' => 'views/{path}'],
        
        // Routes with special features
        '/profile/*' => ['view' => 'views/profile/{path}', 'default_view' => 'views/profile/profile'],
        '/events' => ['view' => 'views/events', 'check_setting' => 'events_active'],
        
        // NOTE: Simple routes like '/login', '/register', '/logout', '/products', '/pricing', 
        // '/lists', '/booking', '/cart', '/survey', '/password-reset-1', '/password-reset-2', 
        // '/password-set', '/site-directory', '/rss20_feed' are now UNNECESSARY - handled by view directory fallback.
        // They will automatically resolve to views/login.php, views/products.php, etc.
    ],
    
    // Routes with custom handling (complex logic preserved)
    'custom' => [
        // Plugin admin discovery
        '/plugins/{plugin}/admin/*' => function($params, $settings, $session, $template_directory) {
            // $params is URL segments: [0]="", [1]="plugins", [2]="controld", [3]="admin", [4]="admin_ctld_account"
            $plugin = $params[2] ?? '';
            $admin_page = $params[4] ?? 'index';
            $admin_file = "plugins/{$plugin}/admin/{$admin_page}.php";
            
            // Debug: will show in route debug logs when enabled
            error_log("Plugin admin route: plugin={$plugin}, admin_page={$admin_page}, file={$admin_file}, exists=" . (file_exists($admin_file) ? 'yes' : 'no'));
            
            if (file_exists($admin_file)) {
                $is_valid_page = true;
                require_once($admin_file);
                return true;
            }
            return false;
        },
        
        // Homepage with complex alternate logic
        '/' => function($params, $settings, $session, $template_directory) {
            $alternate_page = $settings->get_setting('alternate_loggedin_homepage');
            if($alternate_page && $session->is_logged_in()){
                // Complex homepage logic for logged-in users
                $page_pieces = explode('/', $alternate_page);
                if($page_pieces[1] == 'blog'){
                    $template_file = $template_directory.'/views/blog.php';
                    $base_file = PathHelper::getIncludePath('views/blog.php');
                } else if($page_pieces[1] == 'page'){
                    require_once(PathHelper::getIncludePath('data/pages_class.php'));
                    $page = Page::get_by_link($page_pieces[2], true);
                    $template_file = $template_directory.'/views/page.php';
                    $base_file = PathHelper::getIncludePath('views/page.php');
                } else {
                    $template_file = $template_directory.$alternate_page;
                    $base_file = PathHelper::getRootDir().$alternate_page;
                }
            } else if($alternate_page = $settings->get_setting('alternate_homepage')) {
                // Complex homepage logic for non-logged-in users
                $page_pieces = explode('/', $alternate_page);
                if($page_pieces[1] == 'blog'){
                    $template_file = $template_directory.'/views/blog.php';
                    $base_file = PathHelper::getIncludePath('views/blog.php');
                } else if($page_pieces[1] == 'page'){
                    if($settings->get_setting('page_contents_active')){
                        require_once(PathHelper::getIncludePath('data/pages_class.php'));
                        $page = Page::get_by_link($page_pieces[2], true);
                        $template_file = $template_directory.'/views/page.php';
                        $base_file = PathHelper::getIncludePath('views/page.php');
                    }
                } else {
                    $template_file = $template_directory.$alternate_page;
                    $base_file = PathHelper::getRootDir().$alternate_page;
                }
            } else {
                $template_file = $template_directory.'/views/index.php';
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
            // Build the full path from all params after "uploads"
            // params[0] is empty, params[1] is "uploads", params[2+] is the subpath
            $subpath_parts = array_slice($params, 2);
            $subpath = implode('/', $subpath_parts);
            $file = $upload_dir . '/' . $subpath;

            if(file_exists($file)){
                require_once(PathHelper::getIncludePath('data/files_class.php'));
                $file_obj = File::get_by_name(basename($file));

                if($file_obj && $file_obj->authenticate_read(array('session'=>$session))){
                    RouteHelper::serveStaticFile($file, 43200);
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

// ROUTE PROCESSING - All logic moved to RouteHelper::processRoutes()
RouteHelper::processRoutes($routes, $_REQUEST['__route'] ?? '');