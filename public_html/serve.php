<?php
// serve.php - Hybrid routing system with smart path inference
require_once(__DIR__ . '/includes/PathHelper.php');
require_once(__DIR__ . '/includes/RouteHelper.php');

PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('includes/LibraryFunctions.php');
PathHelper::requireOnce('includes/ThemeHelper.php');
PathHelper::requireOnce('includes/PluginHelper.php');

$params = explode("/", $_REQUEST['path']);
$full_path = $_REQUEST['path'];
$static_routes_path = ltrim(rtrim($_REQUEST['path'], '/'), '/');

$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();
$theme_template = $settings->get_setting('theme_template');

// Try directory theme first, then plugin
if (ThemeHelper::themeExists($theme_template)) {
	// Existing directory-based theme logic
	$template_directory = PathHelper::getIncludePath('theme/'.$theme_template);
	$is_plugin_theme = false;
} elseif (PluginHelper::isPluginActive($theme_template)) {
	// This is a plugin acting as theme
	$plugin = PluginHelper::getInstance($theme_template);
	$template_directory = PathHelper::getIncludePath('plugins/'.$theme_template);
	$is_plugin_theme = true;
} else {
	// No valid theme found - let individual file lookups handle fallbacks to base files
	$template_directory = null;
	$theme_template = null;
	$is_plugin_theme = false;
}

//FOR STATS.  WE WILL ONLY RECORD HITS TO ACTUAL PAGES.
$is_valid_page = false;

//ALLOW CURRENT SITE TO OVERRIDE OR ADD ROUTES
$template_file = $template_directory.'/serve.php';
if(file_exists($template_file)){
	require_once($template_file);
}

/*
 * ROUTING SYSTEM DOCUMENTATION
 * 
 * Route types and their options:
 * 
 * STATIC ROUTES - Serve files with caching
 * 'robots.txt' => ['view' => 'views/robots.php']  // Explicit view file
 * 'favicon.ico' => ['cache' => 43200]         // Custom cache time
 * 'includes/*' => ['cache' => 43200]         // Static files with caching
 * 'static_files/*' => ['cache' => 43200, 'exclude_from_cache' => ['.upg.zip']]  // Don't cache upgrade files
 * 'plugins/[name]/includes/[file]' => ['require_plugin_active' => true, 'cache' => 43200]  // Plugin files with activation check
 * 
 * CONTENT ROUTES - Model-view pattern with theme overrides
 * 'page/{slug}' => ['model' => 'Page']                           // -> data/pages_class.php, views/page.php
 * 'post/{slug}' => ['model' => 'Post', 'check_setting' => 'blog_active']  // With feature flag check
 * 'item/{id}' => ['model' => 'Item', 'valid_page' => false]      // Don't count for stats
 * 
 * NOTE: All routes set $is_valid_page = true by default
 * Use ['valid_page' => false] to override for non-tracked pages
 * 
 * SIMPLE ROUTES - Direct file serving with smart path inference
 * 'api/v1/*' => []                           // -> api/apiv1.php
 * 'admin/*' => []                            // -> adm/{path}.php
 * 'profile/*' => ['default_view' => 'profile/profile.php']  // /profile/edit -> views/profile/edit.php, /profile -> views/profile/profile.php
 * 'ajax/*' => []  // Automatically checks plugins/{name}/ajax/{file}.php before ajax/{file}.php
 * 'utils/*' => []  // Automatically checks plugins/{name}/utils/{file}.php before utils/{file}.php
 * 
 * CUSTOM ROUTES - Complex logic with PHP closures
 * '/complex' => function($params, $settings, $session, $template_directory) {
 *     // Custom logic here
 *     // Return true if handled, false if not
 * }
 * 
 * PATH INFERENCE RULES:
 * - /profile/edit -> views/profile/edit.php
 * - /admin/settings -> adm/settings.php 
 * - /plugins/name/admin/page -> plugins/name/admin/page.php
 * - /page/{slug} with model 'Page' -> data/pages_class.php + views/page.php
 * - Static files -> serve directly with proper MIME types and caching
 * 
 * AUTOMATIC FEATURES:
 * - Database URL redirect checking (before route processing)
 * - Path validation with helpful error messages (prevents common path mistakes)
 * - $is_valid_page = true (unless 'valid_page' => false)
 * - Theme override checking (theme files before base files)
 * - Plugin override checking (plugins checked first for all routes)
 * - Parameter extraction from {slug}, {id}, etc.
 * - Feature flag checking via 'check_setting'
 * - Model loading and instantiation
 * - MIME type detection and HTTP caching headers
 * 
 * ROUTE OPTIONS:
 * - 'model' => 'ClassName' - Load model class and instantiate object (content routes)
 * - 'check_setting' => 'setting_name' - Only serve if setting is active
 * - 'valid_page' => false - Don't count this route for statistics (default: true)
 * - 'cache' => 43200 - Cache time in seconds for static files
 * - 'exclude_from_cache' => ['.ext'] - File extensions to not cache (short cache instead)
 * - 'require_plugin_active' => true - Only serve if plugin is active
 * - 'default_view' => 'path/file.php' - Fallback view when no specific file matches
 * - 'view' => 'path/file.php' - Explicit view file to serve (validated for correct format)
 */

// ROUTE DEFINITIONS - Hybrid approach with simple routes and custom handlers
$routes = [
    // Simple static file routes (RouteHelper knows the standard paths)
    'static' => [
        'robots.txt' => ['view' => 'views/robots.php'],  // Explicit view file
        'favicon.ico' => ['cache' => 43200],
        'sitemap.xml' => ['cache' => 43200],
        'includes/*' => ['cache' => 43200],
        'theme/*' => ['cache' => 43200],
        'static_files/*' => ['cache' => 43200, 'exclude_from_cache' => ['.upg.zip']],  // Don't cache upgrade files
        'plugins/*/includes/*' => ['require_plugin_active' => true, 'cache' => 43200],
        'plugins/*/assets/*' => ['require_plugin_active' => true, 'cache' => 43200],
        'adm/includes/*' => ['cache' => 43200],
    ],
    
    // Simple content routes (RouteHelper auto-builds paths from route patterns)
    'content' => [
        'post/{slug}' => ['model' => 'Post', 'check_setting' => 'blog_active'],
        'page/{slug}' => ['model' => 'Page', 'check_setting' => 'page_contents_active'],
        'event/{slug}' => ['model' => 'Event', 'check_setting' => 'events_active'],
        'location/{slug}' => ['model' => 'Location', 'check_setting' => 'events_active'],
        'product/{slug}' => ['model' => 'Product', 'check_setting' => 'products_active'],
        'list/{slug}' => ['model' => 'MailingList'],
        'video/{slug}' => ['model' => 'Video', 'check_setting' => 'videos_active'],
    ],
    
    // Routes with custom handling (complex logic preserved)
    'custom' => [
        // Homepage with complex alternate logic
        '' => function($params, $settings, $session, $template_directory) {
            $alternate_page = $settings->get_setting('alternate_loggedin_homepage');
            if($alternate_page && $session->is_logged_in()){
                // Complex homepage logic for logged-in users
                $page_pieces = explode('/', $alternate_page);
                if($page_pieces[1] == 'blog'){
                    $template_file = $template_directory.'/views/blog.php';
                    $base_file = PathHelper::getIncludePath('views/blog.php');
                } else if($page_pieces[1] == 'page'){
                    if($settings->get_setting('page_contents_active')){
                        PathHelper::requireOnce('data/pages_class.php');
                        $page = Page::get_by_link($page_pieces[2], true);
                        $template_file = $template_directory.'/views/page.php';
                        $base_file = PathHelper::getIncludePath('views/page.php');
                    }
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
                        PathHelper::requireOnce('data/pages_class.php');
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
            global $is_valid_page;
            $is_valid_page = true;
            
            if(file_exists($template_file)){
                require_once($template_file);
            } else if(file_exists($base_file)){
                require_once($base_file);
            }
            return true; // Handled
        },
        
        // Uploads with authentication
        'uploads/*' => function($params, $settings, $session) {
            if(!$settings->get_setting('files_active')) return false;
            
            $upload_dir = $settings->get_setting('upload_dir');
            $file = $params[2] ? $upload_dir.'/'.$params[1].'/'.$params[2] : $upload_dir.'/'.$params[1];
            
            if(file_exists($file)){
                PathHelper::requireOnce('data/files_class.php');
                $file_obj = File::get_by_name(basename($file));
                
                if($file_obj && $file_obj->authenticate_read(array('session'=>$session))){
                    RouteHelper::serveStaticFile($file, 43200);
                    return true;
                } else {
                    LibraryFunctions::display_404_page();
                    return true;
                }
            }
            return false;
        },
        
        // Posts with special condition
        'posts/*' => function($params, $settings, $session, $template_directory) {
            if(!$settings->get_setting('blog_active')) return false;
            if($params[1] && $params[1] != 'tag') return false;
            
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/blog.php');
        },
        
        // Blog
        'blog' => function($params, $settings, $session, $template_directory) {
            if(!$settings->get_setting('blog_active')) return false;
            
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/blog.php');
        },
        
        // Forums
        'forums' => function($params, $settings, $session, $template_directory) {
            if(!$settings->get_setting('forums_active')) return false;
            
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/forums.php');
        },
        
        // Search
        'search' => function($params, $settings, $session, $template_directory) {
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/search.php');
        },
        
        // Calendar
        'calendar' => function($params, $settings, $session, $template_directory) {
            if(!$settings->get_setting('events_active')) return false;
            
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/calendar.php');
        },
        
        // Products listing
        'products' => function($params, $settings, $session, $template_directory) {
            if(!$settings->get_setting('products_active')) return false;
            
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/products.php');
        },
        
        // Lists
        'lists' => function($params, $settings, $session, $template_directory) {
            global $is_valid_page;
            $is_valid_page = true;
            
            return ThemeHelper::includeThemeFile('views/lists.php');
        },
    ],
    
    // Simple routes (RouteHelper derives paths from route patterns)
    'simple' => [
        'api/v1/*' => [],  // -> api/apiv1.php
        'admin/*' => [],   // -> adm/{path}.php
        'ajax/*' => [],    // -> plugins/{name}/ajax/{file}.php, then ajax/{file}.php (automatic)
        'utils/*' => [],   // -> plugins/{name}/utils/{file}.php, then utils/{file}.php (automatic)
        'tests/*' => [],   // -> tests/{path}.php
        'profile/*' => ['default_view' => 'views/profile/profile.php'],  // -> views/profile/{path}.php
        'preferences/*' => ['default_view' => 'views/preferences/preferences.php'],
        'adm/*' => [],  // Admin pages
        'login' => [],     // -> views/login.php
        'events' => [],    // -> views/events.php
        'register' => [],  // -> views/register.php
        'index' => [],     // -> views/index.php
        'event' => [],     // -> views/event.php
    ],
];

// Check for database-stored URL redirects (handled by RouteHelper)
if (RouteHelper::checkUrlRedirects($static_routes_path, $settings)) {
    exit(); // Redirect handled
}

// ROUTE PROCESSING - Process routes in order
// 1. Check static routes (simple configuration)
if ($route = RouteHelper::matchRoute($static_routes_path, $routes['static'])) {
    if (RouteHelper::handleStaticRoute($route, $params, $template_directory)) {
        exit();
    }
}

// 2. Check custom routes (complex logic)
foreach ($routes['custom'] as $pattern => $handler) {
    if (RouteHelper::matchesPattern($pattern, $static_routes_path)) {
        if ($handler($params, $settings, $session, $template_directory)) {
            exit();
        }
    }
}

// 3. Check content routes (model-view pattern)
if ($route = RouteHelper::matchRoute($static_routes_path, $routes['content'])) {
    if (!$route['check_setting'] || $settings->get_setting($route['check_setting'])) {
        if (RouteHelper::handleContentRoute($route, $params, $template_directory)) {
            exit();
        }
    }
}

// 4. Check simple routes
if ($route = RouteHelper::matchRoute($static_routes_path, $routes['simple'])) {
    if (RouteHelper::handleSimpleRoute($route, $params, $template_directory, $is_plugin_theme)) {
        exit();
    }
}

// 4.5. Fallback mechanism for unmatched routes
if (RouteHelper::handleFallback($static_routes_path, $template_directory)) {
    exit();
}

// 5. Allow plugins to handle routes via their serve.php
$activePlugins = PluginHelper::getActivePlugins();
foreach ($activePlugins as $pluginName => $pluginHelper) {
    if ($pluginHelper->hasCustomRouting()) {
        $plugin_serve = PathHelper::getAbsolutePath('plugins/' . $pluginName . '/serve.php');
        if (file_exists($plugin_serve)) {
            // Set variables that plugins expect
            $full_path = $static_routes_path;
            require_once($plugin_serve);
            // Plugin serve.php will exit if it handles the route
        }
    }
}

// 6. Check if the current route is directly handled by a page
// Allow pages by id
if($params[0] == 'page' && is_numeric($params[1])){
    if($settings->get_setting('page_contents_active')){
        PathHelper::requireOnce('data/pages_class.php');
        $page = new Page($params[1], true);
        
        if($page->key){
            $is_valid_page = true;
            
            $template_file = $template_directory.'/views/page.php';
            $base_file = PathHelper::getIncludePath('views/page.php');
            
            if(file_exists($template_file)){
                require_once($template_file);
                exit();
            }
            else if(file_exists($base_file)){
                require_once($base_file); 
                exit();		
            }
        }
    }
}

// Check mailing list redirects (special handling)
if($params[0] == 'list' && $params[1]){
    PathHelper::requireOnce('data/mailinglists_class.php');
    $mailing_list = MailingList::get_by_link($params[1], true);
    
    if($mailing_list){
        if($mailing_list->get('mal_redirect_url')){
            header("Location: ".$mailing_list->get('mal_redirect_url'));
            exit();	
        }
        else if($mailing_list->get('mal_redirect_file')){
            $template_file = $template_directory.$mailing_list->get('mal_redirect_file');
            $base_file = PathHelper::getRootDir().$mailing_list->get('mal_redirect_file');
            if(file_exists($template_file)){
                require_once($template_file);
                exit();
            }
            else if(file_exists($base_file)){
                require_once($base_file); 
                exit();		
            }	
        }
    }
}

// Handle legacy utils directory (backward compatibility)
if($params[0] == 'utils'){
    if($params[1]){
        //LOAD THE UTILS FILES FROM THE PLUGINS
        $plugins = LibraryFunctions::list_plugins();
        foreach($plugins as $plugin){
            $plugin_file = ensure_extension(PathHelper::getIncludePath('plugins/'.$plugin.'/utils/'.$params[1]), 'php');
            if(file_exists($plugin_file)){
                // Check if plugin is active before loading utils file
                PathHelper::requireOnce('data/plugins_class.php');
                
                if(Plugin::is_plugin_active($plugin)){
                    check_plugin_version_if_needed($plugin);
                    $is_valid_page = true;
                    require_once($plugin_file);
                    exit();
                }
            }
        }	
        
        $base_file = ensure_extension(PathHelper::getIncludePath('utils/'.$params[1]),'php');
        if(file_exists($base_file)){
            $is_valid_page = true;
            require_once($base_file); 
            exit();		
        }
    }
}

// Handle special admin include files
if($params[0] == 'adm' && $params[1] == 'includes'){
    $base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
    if(file_exists($base_file)){
        RouteHelper::serveStaticFile($base_file, 43200);
        exit();
    }
}

// Handle direct theme includes (backward compatibility)
if($params[0] == 'theme' && $params[1] && $params[2] == 'includes'){
    $base_file = PathHelper::getRootDir().$_SERVER['REQUEST_URI'];
    if(file_exists($base_file)){
        RouteHelper::serveStaticFile($base_file, 43200);
        exit();
    }
}

// Handle direct plugin admin pages
if($params[0] == 'plugins' && $params[1] && $params[2] == 'admin'){
    $plugin_name = $params[1];
    if(PluginHelper::isPluginActive($plugin_name)){
        $admin_file = ensure_extension(PathHelper::getIncludePath('plugins/'.$plugin_name.'/admin/'.$params[3]), 'php');
        if(file_exists($admin_file)){
            $is_valid_page = true;
            require_once($admin_file);
            exit();
        }
    }
}

// Helper functions
function ensure_extension($file, $extension){
    if(substr($file, -1 * (strlen($extension) + 1)) != '.'.$extension){
        $file = $file.'.'.$extension;	
    }
    return $file;
}

function mime_type($filename) {
    return RouteHelper::getMimeType($filename);
}

function check_plugin_version_if_needed($plugin_name) {
    // Plugin version checking logic if needed
    // This function can be expanded to handle plugin version management
}

// 7. Final fallback - 404 page
LibraryFunctions::display_404_page();
exit();