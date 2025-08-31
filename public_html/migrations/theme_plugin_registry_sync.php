<?php
function theme_plugin_registry_sync() {
    PathHelper::requireOnce('data/themes_class.php');
    PathHelper::requireOnce('data/plugins_class.php');
    PathHelper::requireOnce('includes/ThemeManager.php');
    PathHelper::requireOnce('includes/PluginManager.php');
    
    echo "Syncing themes with database registry...\n";
    $theme_manager = ThemeManager::getInstance();
    $sync_result = $theme_manager->sync();
    
    $added_count = count($sync_result['added']);
    $updated_count = count($sync_result['updated']);
    
    if ($added_count > 0) {
        echo "Added $added_count new themes.\n";
    }
    if ($updated_count > 0) {
        echo "Updated $updated_count existing themes.\n";  
    }
    if ($added_count == 0 && $updated_count == 0) {
        echo "All themes already synchronized.\n";
    }
    
    echo "Syncing plugins with stock/custom status...\n";
    $plugin_manager = new PluginManager();
    $synced_plugins = $plugin_manager->syncWithFilesystem();
    echo "Synced " . count($synced_plugins) . " new plugins.\n";
    
    // Update existing plugins with stock/custom status
    $existing_plugins = new MultiPlugin();
    $existing_plugins->load();
    $updated_count = 0;
    
    foreach ($existing_plugins as $plugin) {
        $old_stock_status = $plugin->get('plg_is_stock');
        $plugin->load_stock_status();
        $new_stock_status = $plugin->get('plg_is_stock');
        
        if ($old_stock_status != $new_stock_status) {
            $plugin->save();
            $updated_count++;
            echo "Updated stock status for plugin: " . $plugin->get('plg_name') . "\n";
        }
    }
    
    echo "Updated stock/custom status for $updated_count existing plugins.\n";
    
    // Set current theme as active in database
    PathHelper::requireOnce('includes/Globalvars.php');
    $settings = Globalvars::get_instance();
    $current_theme = $settings->get_setting('theme_template');
    
    if ($current_theme) {
        echo "Setting current theme '$current_theme' as active...\n";
        
        $theme = Theme::get_by_theme_name($current_theme);
        if ($theme) {
            $theme->activate();
            echo "Theme '$current_theme' activated.\n";
        } else {
            echo "Warning: Current theme '$current_theme' not found in registry.\n";
        }
    }
    
    return true;
}
?>