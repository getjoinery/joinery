<?php
require_once('ThemeHelper.php');
require_once('PluginHelper.php');

class PathHelper {
    private static $root_dir = null;
    
    public static function getRootDir() {
        if (self::$root_dir === null) {
            // Calculate root from this file's location
            self::$root_dir = dirname(__DIR__);
        }
        return self::$root_dir;
    }
    
    public static function getIncludePath($relativePath) {
        return self::getRootDir() . '/' . ltrim($relativePath, '/');
    }
    
    public static function getBasePath() {
        return self::getRootDir();
    }
    
    public static function getAbsolutePath($relativePath) {
        return self::getRootDir() . '/' . ltrim($relativePath, '/');
    }
    
    public static function requireOnce($relativePath) {
        $fullPath = self::getIncludePath($relativePath);
        if (file_exists($fullPath)) {
            require_once $fullPath;
            return true;
        }
        throw new Exception("Required file not found: $relativePath (looked for: $fullPath)");
    }
    
    /**
     * Get theme file path with fallback to base
     * Moved from LibraryFunctions for proper architectural separation
     */
    public static function getThemeFilePath($filename, $subdirectory='', $path_format='system', $theme_name=NULL, $debug = false){
        $settings = Globalvars::get_instance();
        $siteDir = PathHelper::getBasePath();
        
        //SUBDIRECTORY WORKS WITH OR WITHOUT SLASH
        if (substr($subdirectory, 0, 1) !== '/') {
            $subdirectory = '/' . $subdirectory; // Add a forward slash if it doesn't exist
        }
        
        if($theme_name){
            $theme_template = $theme_name;
            
            // Check if it's a directory theme first, then plugin
            if(is_dir($siteDir.'/theme/'.$theme_template)){
                // It's a directory theme - existing logic
                $is_plugin_theme = false;
            } elseif(PluginHelper::isPluginActive($theme_template)) {
                // It's a plugin theme
                $is_plugin_theme = true;
            } else {
                throw new SystemDisplayablePermanentError('Could not find the specified theme: '. $theme_name);
            }
        }
        else{
            // Try to get theme template, but handle cases where database might not be available
            try {
                $theme_template = $settings->get_setting('theme_template', true, true);
                
                // Determine if it's a plugin theme
                if($theme_template) {
                    if(is_dir($siteDir.'/theme/'.$theme_template)){
                        $is_plugin_theme = false;
                    } elseif(PluginHelper::isPluginActive($theme_template)) {
                        $is_plugin_theme = true;
                    } else {
                        // Invalid theme, set to null
                        $theme_template = null;
                        $is_plugin_theme = false;
                    }
                } else {
                    $is_plugin_theme = false;
                }
            } catch (Exception $e) {
                // If database is not available (e.g., during update_database.php), use fallback
                $theme_template = null;
                $is_plugin_theme = false;
            }
        }
        
        // Build file paths based on theme type
        if($theme_template) {
            if($is_plugin_theme) {
                $theme_file = $siteDir.'/plugins/'.$theme_template.$subdirectory.'/'.$filename;
            } else {
                $theme_file = $siteDir.'/theme/'.$theme_template.$subdirectory.'/'.$filename;
            }
        } else {
            $theme_file = null;
        }
        $default_file = $siteDir.$subdirectory.'/'.$filename;
        
        if($debug){
            echo 'Theme template: '.$theme_template.'<br>';
            echo 'Theme file: '.$theme_file.'<br>';
            echo 'Default file: '.$default_file.'<br>';
        }
        
        if($theme_file && file_exists($theme_file)){
            if($path_format == 'system'){
                return $theme_file;
            }
            else{
                if($is_plugin_theme) {
                    return '/plugins/'.$theme_template.$subdirectory.'/'.$filename;
                } else {
                    return '/theme/'.$theme_template.$subdirectory.'/'.$filename;
                }
            }
        }
        else if(file_exists($default_file)){
            if($path_format == 'system'){
                return $default_file;
            }
            else{
                return $subdirectory.'/'.$filename;
            }
        }
        
        return false;
    }
}
?>