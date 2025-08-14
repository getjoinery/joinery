<?php
// plugins/items/serve.php - Uses RouteHelper for consistent routing

/*
 * PLUGIN UNIFIED ROUTING SYSTEM DOCUMENTATION
 * 
 * Route types and their options (same as main serve.php):
 * 
 * DYNAMIC ROUTES - Unified system for all dynamic content (views + models)
 * Model-based routes:
 * '/item/{slug}' => ['model' => 'Item', 'model_file' => 'plugins/items/data/items_class']  // Plugin model + theme view
 * '/item/{id}' => ['model' => 'Item', 'model_file' => 'plugins/items/data/items_class', 'valid_page' => false]  // Don't count for stats
 * '/custom/{slug}' => ['model' => 'Custom', 'model_file' => 'plugins/items/data/customs_class', 'check_setting' => 'custom_active']
 * 
 * Simple view routes:
 * '/items/list' => ['view' => 'views/itemslist']  // Standard theme-overridden view
 * '/items/custom' => ['view' => 'views/itemscustom', 'default_view' => 'views/itemsdefault']  // With fallback
 * 
 * CUSTOM ROUTES - Complex logic with PHP closures
 * '/items' => function($params, $settings, $session, $template_directory) {
 *     // Custom logic for items listing with tag support
 *     // Return true if handled, false if not
 * }
 * 
 * PLUGIN PATH RESOLUTION RULES:
 * - Model routes: Plugin models + standard theme-overridden views (automatic view path from model name)
 * - View routes: Use standard views/ files (theme overrides apply automatically)
 * - Admin routes: '/plugins/plugin/admin/*' -> plugins/plugin/admin/{path}.php (plugin admin files only)
 * 
 * AUTOMATIC FEATURES:
 * - Database URL redirect checking (before route processing)
 * - Path validation with helpful error messages
 * - $is_valid_page = true (unless 'valid_page' => false)
 * - Theme override checking (theme files before plugin files, then base files)
 * - Parameter extraction from {slug}, {id}, etc.
 * - Model loading and instantiation
 * - No plugin activation checks needed (already active if this file runs)
 */

// Define plugin routes - RouteHelper will automatically load these
$routes = [
    // Dynamic routes (unified views + models)
    'dynamic' => [
        '/item/{slug}' => [
            'model' => 'Item',
            'model_file' => 'plugins/items/data/items_class',
        ],
    ],
    
    // Custom routes with complex logic
    'custom' => [
        // Items listing with tag support
        '/items' => function($params, $settings, $session, $template_directory) {
            // Check if it's main items page or tag page
            if($params[1] && $params[1] != 'tag') return false;
            
            // Use ThemeHelper for consistent theme override support
            return ThemeHelper::includeThemeFile('views/items.php');
        },
    ],
];