<?php

/**
 * ComposerValidator - Validates Composer installation and dependencies
 */
class ComposerValidator {
    
    private $composerPath;
    private $errors = [];
    private $warnings = [];
    
    public function __construct() {
        $settings = Globalvars::get_instance();
        $this->composerPath = $settings->get_setting('composerAutoLoad');
    }
    
    /**
     * Run all validation checks
     * @return bool True if all checks pass
     */
    public function validate() {
        $this->errors = [];
        $this->warnings = [];

        // Check 1: Composer path is configured
        if (!$this->validateComposerPathConfigured()) {
            return false;
        }

        // Check 2: Autoload file exists
        if (!$this->validateAutoloadExists()) {
            return false;
        }

        // Check 3: composer.json exists
        if (!$this->validateComposerJsonExists()) {
            return false;
        }

        // Check 4: Required packages are installed
        if (!$this->validateRequiredPackages()) {
            return false;
        }

        // Check 5: Vendor directory consistency
        if (!$this->validateVendorDirConsistency()) {
            return false;
        }

        return true;
    }
    
    /**
     * Check if composer path is configured
     */
    private function validateComposerPathConfigured() {
        if (empty($this->composerPath)) {
            $this->errors[] = "composerAutoLoad setting is not configured in database";
            return false;
        }
        return true;
    }
    
    /**
     * Check if autoload.php exists
     */
    private function validateAutoloadExists() {
        $autoloadPath = $this->composerPath . 'autoload.php';
        if (!file_exists($autoloadPath)) {
            $this->errors[] = "Composer autoload.php not found at: " . $autoloadPath;
            $this->errors[] = "Run 'composer install' in the project directory";
            return false;
        }
        return true;
    }
    
    /**
     * Check if composer.json exists
     */
    private function validateComposerJsonExists() {
        // First try project root
        $basePath = PathHelper::getBasePath();
        $composerJsonPath = $basePath . '/composer.json';
        
        // If not in project root, try relative to vendor directory
        if (!file_exists($composerJsonPath) && $this->composerPath) {
            // Go up one level from vendor directory
            $composerDir = dirname(rtrim($this->composerPath, '/'));
            $composerJsonPath = $composerDir . '/composer.json';
        }
        
        if (!file_exists($composerJsonPath)) {
            $this->warnings[] = "composer.json not found in project root or near vendor directory";
            $this->warnings[] = "Cannot verify required packages";
            // This is a warning, not an error - don't return false
        }
        
        return true;
    }
    
    /**
     * Check if required packages are installed
     */
    private function validateRequiredPackages() {
        // Always prioritize project-specific composer files
        $basePath = PathHelper::getBasePath();
        $projectComposerJson = $basePath . '/composer.json';
        $projectComposerLock = $basePath . '/composer.lock';
        
        // Use project files if composer.json exists, otherwise fall back to shared vendor location
        if (file_exists($projectComposerJson)) {
            $composerJsonPath = $projectComposerJson;
            $composerLockPath = $projectComposerLock;
        } else if ($this->composerPath) {
            // Fall back to shared vendor directory
            $composerDir = dirname(rtrim($this->composerPath, '/'));
            $composerJsonPath = $composerDir . '/composer.json';
            $composerLockPath = $composerDir . '/composer.lock';
        } else {
            $composerJsonPath = $projectComposerJson;
            $composerLockPath = $projectComposerLock;
        }
        // If no composer.json, we can't check
        if (!file_exists($composerJsonPath)) {
            return true; // Already warned about this
        }
        
        // Parse composer.json
        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        if (!$composerJson || !isset($composerJson['require'])) {
            $this->warnings[] = "Unable to parse composer.json";
            return true;
        }
        
        // Check if composer.lock exists
        if (!file_exists($composerLockPath)) {
            $this->errors[] = "composer.lock not found at: $composerLockPath";
            $this->errors[] = "Run 'composer install' in the project directory";
            return false;
        }
        
        // Parse composer.lock to see what's actually installed
        $composerLock = json_decode(file_get_contents($composerLockPath), true);
        if (!$composerLock || !isset($composerLock['packages'])) {
            $this->warnings[] = "Unable to parse composer.lock";
            return true;
        }
        
        // Build list of installed packages
        $installedPackages = [];
        foreach ($composerLock['packages'] as $package) {
            $installedPackages[$package['name']] = $package['version'];
        }
        
        // Check each required package
        $missingPackages = [];
        foreach ($composerJson['require'] as $packageName => $version) {
            // Skip PHP version requirement
            if ($packageName === 'php') {
                continue;
            }
            
            if (!isset($installedPackages[$packageName])) {
                $missingPackages[] = $packageName;
            }
        }
        
        if (!empty($missingPackages)) {
            $this->errors[] = "Missing required packages: " . implode(', ', $missingPackages);
            $this->errors[] = "Checked composer.json: $composerJsonPath";
            $this->errors[] = "Checked composer.lock: $composerLockPath";
            $this->errors[] = "Run 'composer install' to install missing packages";
            return false;
        }
        
        // Check specific critical packages that the system needs
        $criticalPackages = [
            'phpmailer/phpmailer' => 'PHPMailer (for email functionality)',
            'stripe/stripe-php' => 'Stripe (for payment processing)',
            'mailgun/mailgun-php' => 'Mailgun (for bulk email)'
        ];
        
        foreach ($criticalPackages as $package => $description) {
            if (!isset($installedPackages[$package])) {
                $this->warnings[] = "Critical package missing: $package - $description";
            }
        }
        
        return true;
    }

    /**
     * Detect if vendor directory location has changed between composer.json and database setting
     * @return array ['changed' => bool, 'old_path' => string|null, 'new_path' => string|null]
     */
    private function detectVendorDirChange() {
        $basePath = PathHelper::getBasePath();
        $composerJsonPath = $basePath . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return ['changed' => false, 'old_path' => null, 'new_path' => null];
        }

        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        if (!$composerJson || !isset($composerJson['config']['vendor-dir'])) {
            return ['changed' => false, 'old_path' => null, 'new_path' => null];
        }

        // Get configured vendor dir from composer.json
        $configuredVendorDir = rtrim($composerJson['config']['vendor-dir'], '/');
        if (substr($configuredVendorDir, 0, 1) === '/') {
            // Absolute path - use as-is
            $expectedPath = $configuredVendorDir . '/';
        } else {
            // Relative path - resolve relative to base path
            $expectedPath = rtrim($basePath, '/') . '/' . $configuredVendorDir . '/';
        }

        // Get current setting path
        $settingPath = rtrim($this->composerPath, '/') . '/';

        // Detect change
        if ($expectedPath !== $settingPath) {
            return [
                'changed' => true,
                'old_path' => $settingPath,
                'new_path' => $expectedPath
            ];
        }

        return ['changed' => false, 'old_path' => null, 'new_path' => null];
    }

    /**
     * Check if composerAutoLoad setting matches vendor-dir in composer.json
     * Uses string normalization for performance (avoids expensive realpath() calls)
     */
    private function validateVendorDirConsistency() {
        $changeInfo = $this->detectVendorDirChange();

        if ($changeInfo['changed']) {
            $this->errors[] = "Vendor directory mismatch detected:";
            $this->errors[] = "  Database setting: " . $changeInfo['old_path'];
            $this->errors[] = "  composer.json config: " . $changeInfo['new_path'];
            $this->errors[] = "  Run 'composer install' to install to new location";
            return false;
        }

        return true;
    }

    /**
     * Get validation errors
     * @return array
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Get validation warnings
     * @return array
     */
    public function getWarnings() {
        return $this->warnings;
    }
    
    /**
     * Install dependencies if validation fails due to missing or mismatched composer files
     * @return bool True if install succeeded or wasn't needed, false if install failed
     */
    public function installIfNeeded() {
        // Run validation first
        if ($this->validate()) {
            return true; // Already valid, no install needed
        }

        // Check if the errors are composer-install-fixable
        $installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch'];
        $canFix = false;

        foreach ($this->errors as $error) {
            foreach ($installFixableErrors as $fixableError) {
                if (strpos($error, $fixableError) !== false) {
                    $canFix = true;
                    break 2;
                }
            }
        }

        if (!$canFix) {
            return false; // Validation failed for reasons composer install won't fix
        }

        // Try to run composer install
        $basePath = PathHelper::getBasePath();
        $composerJsonPath = $basePath . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return false; // No composer.json to install from
        }

        // Change to project directory and run composer install
        $originalDir = getcwd();
        chdir($basePath);

        $output = [];
        $returnCode = 0;
        exec('composer install --no-dev --optimize-autoloader --no-interaction 2>&1', $output, $returnCode);

        chdir($originalDir);

        if ($returnCode !== 0) {
            $this->errors[] = "Composer install failed: " . implode("\n", $output);
            return false;
        }

        // Clear previous validation results and re-validate
        $this->errors = [];
        $this->warnings = [];

        return $this->validate();
    }
    
    /**
     * Get formatted output for command line
     * @return string
     */
    public function getFormattedOutput() {
        $output = "";

        if (!empty($this->errors)) {
            $output .= "\n\033[31mCOMPOSER ERRORS:\033[0m\n";
            foreach ($this->errors as $error) {
                $output .= "  ✗ " . $error . "\n";
            }
        }

        if (!empty($this->warnings)) {
            $output .= "\n\033[33mCOMPOSER WARNINGS:\033[0m\n";
            foreach ($this->warnings as $warning) {
                $output .= "  ⚠ " . $warning . "\n";
            }
        }

        if (empty($this->errors) && empty($this->warnings)) {
            $output .= "\n\033[32m✓ Composer validation passed\033[0m\n";
        }

        return $output;
    }

}
?>