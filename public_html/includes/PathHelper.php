<?php
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
    
    public static function requireOnce($relativePath) {
        $fullPath = self::getIncludePath($relativePath);
        if (file_exists($fullPath)) {
            require_once $fullPath;
            return true;
        }
        throw new Exception("Required file not found: $relativePath (looked for: $fullPath)");
    }
}
?>