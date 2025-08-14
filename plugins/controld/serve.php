<?php
// plugins/controld/serve.php - Uses RouteHelper for consistent routing

/*
 * PLUGIN UNIFIED ROUTING SYSTEM DOCUMENTATION
 * 
 * Route types and their options (same as main serve.php):
 * 
 * STATIC ROUTES - Serve ONLY static assets (CSS, JS, images, fonts) with caching
 * '/favicon.ico' => ['cache' => 43200]         // Static asset file
 * '/plugins/{plugin}/assets/*' => ['cache' => 43200]            // Plugin assets with caching
 * 
 * DYNAMIC ROUTES - Unified system for all dynamic content (views + models)
 * Simple view routes:
 * '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit']  // Standard theme-overridden view
 * '/pricing' => ['view' => 'views/pricing']  // Standard theme-overridden view
 * '/plugins/controld/admin/*' => ['view' => 'plugins/controld/admin/{path}']  // Plugin admin files only
 * 
 * Model-based routes:
 * '/item/{slug}' => ['model' => 'Item', 'model_file' => 'plugins/controld/data/items_class']  // Plugin model + theme view
 * '/custom/{slug}' => ['model' => 'Custom', 'model_file' => 'plugins/controld/data/customs_class', 'check_setting' => 'custom_active']
 * 
 * Mixed routes:
 * '/user/{action}' => ['model' => 'User', 'model_file' => 'data/users_class', 'view' => 'views/user/{action}', 'default_view' => 'views/user/profile']
 * 
 * CUSTOM ROUTES - Complex logic with PHP closures
 * '/complex' => function($params, $settings, $session, $template_directory) {
 *     // Custom logic here
 *     // Return true if handled, false if not
 * }
 * 
 * PLUGIN PATH RESOLUTION RULES:
 * - View routes: Use standard views/ files (theme overrides apply automatically)
 * - Admin routes: '/plugins/plugin/admin/*' -> plugins/plugin/admin/{path}.php (plugin admin files only)
 * - Model routes: Plugin models + standard theme-overridden views 
 * - Theme overrides: Standard theme override system applies to all plugin routes
 * 
 * AUTOMATIC FEATURES:
 * - Database URL redirect checking (before route processing)
 * - Path validation with helpful error messages
 * - $is_valid_page = true (unless 'valid_page' => false)
 * - Theme override checking (theme files before plugin files)
 * - Parameter extraction from {slug}, {id}, etc.
 * - Feature flag checking via 'check_setting'
 * - Model loading and instantiation
 * - No plugin activation checks needed (already active if this file runs)
 */

// Define plugin routes - RouteHelper will automatically load these
$routes = [
    'dynamic' => [
        '/profile/device_edit' => ['view' => 'views/profile/ctlddevice_edit'],
        '/profile/filters_edit' => ['view' => 'views/profile/ctldfilters_edit'],
        '/profile/devices' => ['view' => 'views/profile/ctlddevices'],
        '/profile/rules' => ['view' => 'views/profile/ctldrules'],
        '/profile/ctld_activation' => ['view' => 'views/profile/ctld_activation'],
        '/create_account' => ['view' => 'views/create_account'],
        '/pricing' => ['view' => 'views/pricing'],
        '/plugins/controld/admin/*' => ['view' => 'plugins/controld/admin/{path}'],
    ],
];