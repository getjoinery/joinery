<?php
/**
 * ComponentBase - Abstract base class for plugins and themes
 * Provides common functionality for manifest loading, path resolution, and lifecycle management
 */
abstract class ComponentBase {
    protected $name;
    protected $manifestData = [];
    protected $manifestPath;
    protected $componentType; // 'plugin' or 'theme'
    protected $basePath;
    
    /**
     * Load component manifest from JSON file
     */
    protected function loadManifest() {
        if (file_exists($this->manifestPath)) {
            $content = file_get_contents($this->manifestPath);
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $this->manifestData = $data;
                return true;
            } else {
                // Invalid JSON - throw exception for mandatory manifests
                throw new Exception("Invalid {$this->componentType} manifest at {$this->manifestPath}: " . json_last_error_msg());
            }
        } else {
            // Manifest is mandatory - throw exception
            throw new Exception("Required {$this->componentType} manifest not found at {$this->manifestPath}");
        }
        return false;
    }
    
    /**
     * Get manifest field value
     */
    public function get($key, $default = null) {
        return $this->manifestData[$key] ?? $default;
    }
    
    /**
     * Get component name
     */
    public function getName() { 
        return $this->manifestData['name'] ?? $this->name; 
    }
    
    /**
     * Get display name
     */
    public function getDisplayName() { 
        return $this->manifestData['displayName'] ?? $this->getName(); 
    }
    
    /**
     * Get version
     */
    public function getVersion() { 
        return $this->manifestData['version'] ?? '0.0.0'; 
    }
    
    /**
     * Get description
     */
    public function getDescription() { 
        return $this->manifestData['description'] ?? ''; 
    }
    
    /**
     * Get author
     */
    public function getAuthor() { 
        return $this->manifestData['author'] ?? ''; 
    }
    
    /**
     * Get requirements
     */
    public function getRequirements() {
        return $this->manifestData['requires'] ?? [];
    }
    
    /**
     * Check if component meets system requirements
     */
    public function checkRequirements() {
        $requirements = $this->getRequirements();
        $errors = [];
        
        // Check PHP version
        if (isset($requirements['php'])) {
            // Parse version requirement (e.g., ">=7.4")
            $operator = '>=';
            $version = $requirements['php'];
            
            if (preg_match('/^([><=]+)(.+)$/', $requirements['php'], $matches)) {
                $operator = $matches[1];
                $version = $matches[2];
            }
            
            if (!version_compare(PHP_VERSION, $version, $operator)) {
                $errors[] = "PHP {$requirements['php']} required, currently " . PHP_VERSION;
            }
        }
        
        // Check Joinery version
        if (isset($requirements['joinery'])) {
            // Get current Joinery version from database or config
            $settings = Globalvars::get_instance();
            $joineryVersion = $settings->get_setting('joinery_version', true, true) ?? '1.0.0';
            
            $operator = '>=';
            $version = $requirements['joinery'];
            
            if (preg_match('/^([><=]+)(.+)$/', $requirements['joinery'], $matches)) {
                $operator = $matches[1];
                $version = $matches[2];
            }
            
            if (!version_compare($joineryVersion, $version, $operator)) {
                $errors[] = "Joinery {$requirements['joinery']} required, currently {$joineryVersion}";
            }
        }
        
        return empty($errors) ? true : $errors;
    }
    
    /**
     * Get URL path to component asset
     */
    public function getAssetUrl($path) {
        // Remove leading slash if present
        $path = ltrim($path, '/');
        return '/' . $this->basePath . '/' . $path;
    }
    
    /**
     * Get full filesystem path for component file
     */
    public function getIncludePath($path) {
        // Remove leading slash if present
        $path = ltrim($path, '/');
        return PathHelper::getIncludePath($this->basePath . '/' . $path);
    }
    
    /**
     * Include file from component with optional fallback
     */
    public function includeFile($path, $fallbackPath = null) {
        $fullPath = $this->getIncludePath($path);
        
        if (file_exists($fullPath)) {
            require_once($fullPath);
            return true;
        }
        
        if ($fallbackPath) {
            $fallbackFull = PathHelper::getIncludePath($fallbackPath);
            if (file_exists($fallbackFull)) {
                require_once($fallbackFull);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if file exists in component
     */
    public function fileExists($path) {
        return file_exists($this->getIncludePath($path));
    }
    
    /**
     * Get all files matching pattern in component
     */
    public function getFiles($pattern) {
        $basePath = $this->getIncludePath('');
        return glob($basePath . '/' . $pattern);
    }
    
    /**
     * Export manifest data as array
     */
    public function toArray() {
        return $this->manifestData;
    }
    
    /**
     * Get component type
     */
    public function getType() {
        return $this->componentType;
    }
    
    /**
     * Get base path
     */
    public function getBasePath() {
        return $this->basePath;
    }
    
    // Abstract methods that subclasses must implement
    abstract public function initialize();
    abstract public function isActive();
    abstract public function validate();
}