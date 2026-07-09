<?php

/**
 * ComposerValidator - Validates Composer installation and dependencies.
 *
 * Also owns the plugin composer tier (spec plugin_dependency_installation):
 * plugins declare PHP library dependencies in plugin.json under
 * requires.composer ({"vendor/pkg": "constraint"}), and this class reconciles
 * them into the single root composer.json via `composer require`. Reconciled
 * packages are recorded in composer.json extra.joinery-plugin-packages so
 * utils/list_dependencies.php --orphans can report packages no plugin
 * declares anymore.
 *
 * @version 1.1 - Plugin-declared composer packages: validation of active
 *                plugins' requires.composer + reconcilePluginPackages().
 */
class ComposerValidator {

    private $composerPath;
    private $errors = [];
    private $warnings = [];
    private $packageConflicts = [];

    public function __construct() {
        $this->composerPath = PathHelper::getComposerVendorPath();
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

        // Check 5: Active plugins' declared composer packages are installed
        if (!$this->validatePluginPackages()) {
            return false;
        }

        // Check 6: Vendor directory consistency
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
     * Checks both the database setting location AND the composer.json configured location
     */
    private function validateAutoloadExists() {
        $basePath = PathHelper::getBasePath();
        $composerJsonPath = $basePath . '/composer.json';

        // Determine expected vendor location from composer.json
        $expectedVendorPath = null;
        if (file_exists($composerJsonPath)) {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);
            if ($composerJson && isset($composerJson['config']['vendor-dir'])) {
                $vendorDir = rtrim($composerJson['config']['vendor-dir'], '/');
                if (substr($vendorDir, 0, 1) === '/') {
                    // Absolute path
                    $expectedVendorPath = $vendorDir . '/';
                } else {
                    // Relative path
                    $expectedVendorPath = rtrim($basePath, '/') . '/' . $vendorDir . '/';
                }
            }
        }

        // Check database setting location first
        $autoloadPath = $this->composerPath . 'autoload.php';
        if (file_exists($autoloadPath)) {
            return true;
        }

        // If not found at database location, check composer.json location
        if ($expectedVendorPath && file_exists($expectedVendorPath . 'autoload.php')) {
            return true;
        }

        // Neither location has autoload.php
        $this->errors[] = "Composer autoload.php not found at: " . $autoloadPath;
        if ($expectedVendorPath && $expectedVendorPath !== $this->composerPath) {
            $this->errors[] = "Also checked composer.json location: " . $expectedVendorPath . 'autoload.php';
        }
        $this->errors[] = "Run 'composer install' in the project directory";
        return false;
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
            // Skip the PHP version requirement itself.
            if ($packageName === 'php') {
                continue;
            }

            // Platform packages (ext-*, lib-*, hhvm) are never vendor packages
            // Composer installs into composer.lock's packages array - they're
            // satisfied by the runtime itself. Verify an ext-* requirement
            // against the actually-loaded extension instead.
            if (strpos($packageName, 'ext-') === 0) {
                if (!extension_loaded(substr($packageName, 4))) {
                    $missingPackages[] = $packageName;
                }
                continue;
            }
            if (strpos($packageName, 'lib-') === 0 || $packageName === 'hhvm') {
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
     * Collect requires.composer declarations ({"vendor/pkg": "constraint"})
     * from plugin manifests. Scope is the ACTIVE plugins plus any names in
     * $include_plugins (used at activation time, when the activating plugin
     * is not active yet). Database unavailability degrades to just
     * $include_plugins - a fresh install has no active plugins anyway.
     *
     * @param array|null $include_plugins Additional plugin names to include
     * @return array package name (lowercased) => constraint
     */
    public function collectPluginComposerRequires($include_plugins = null) {
        $this->packageConflicts = [];
        $names = is_array($include_plugins) ? $include_plugins : [];
        try {
            $dblink = DbConnector::get_instance()->get_db_link();
            $stmt = $dblink->prepare("SELECT plg_name FROM plg_plugins WHERE plg_status = 'active'");
            $stmt->execute();
            $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            // No database (early install) - scope stays at $include_plugins.
        }

        $packages = [];
        $declared_by = []; // package => first declaring plugin (for conflict messages)
        $plugins_root = PathHelper::getBasePath() . '/plugins/';
        foreach (array_unique($names) as $plugin_name) {
            // Plugin names are directory names; refuse anything path-like.
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', (string)$plugin_name)) {
                continue;
            }
            $manifest_path = $plugins_root . $plugin_name . '/plugin.json';
            if (!file_exists($manifest_path)) {
                continue;
            }
            $manifest = json_decode(file_get_contents($manifest_path), true);
            $declared = $manifest['requires']['composer'] ?? [];
            if (!is_array($declared)) {
                continue;
            }
            foreach ($declared as $package => $constraint) {
                if (!is_string($package) || !is_string($constraint)
                        || !preg_match('#^[a-z0-9]([_.\-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#i', $package)) {
                    continue;
                }
                $package = strtolower($package);
                // Root composer.json holds ONE constraint per package, so two
                // plugins declaring different constraints would silently
                // last-win and composer would never see the disagreement.
                // Surface it instead; identical constraints are fine.
                if (isset($packages[$package]) && $packages[$package] !== $constraint) {
                    $this->packageConflicts[] = "$package: '{$declared_by[$package]}' requires {$packages[$package]}, '{$plugin_name}' requires {$constraint}";
                    continue;
                }
                if (!isset($packages[$package])) {
                    $declared_by[$package] = $plugin_name;
                }
                $packages[$package] = $constraint;
            }
        }
        return $packages;
    }

    /**
     * Cross-plugin constraint disagreements found by the last
     * collectPluginComposerRequires() call.
     * @return array of human-readable conflict descriptions
     */
    public function getPackageConflicts() {
        return $this->packageConflicts;
    }

    /**
     * Check that every active plugin's declared composer packages are in
     * composer.lock. Missing packages are an install-fixable error (the
     * reconcile step in installIfNeeded() resolves them).
     */
    private function validatePluginPackages() {
        $declared = $this->collectPluginComposerRequires();
        // Constraint disagreements between active plugins are surfaced as a
        // warning here (blocking update_database outright would brick a site
        // over a fixable manifest edit); activation of a new conflicting
        // plugin is refused hard in reconcilePluginPackages().
        if (!empty($this->packageConflicts)) {
            $this->warnings[] = "Conflicting plugin composer constraints: " . implode('; ', $this->packageConflicts);
        }
        if (empty($declared)) {
            return true;
        }

        $installed = $this->getInstalledPackages();
        if ($installed === null) {
            return true; // lock missing/unparseable - already reported by earlier checks
        }

        $missing = [];
        foreach ($declared as $package => $constraint) {
            if (!isset($installed[$package])) {
                $missing[] = $package;
            }
        }
        if (!empty($missing)) {
            $this->errors[] = "Missing plugin-declared packages: " . implode(', ', $missing);
            $this->errors[] = "Declared in plugin.json requires.composer; run update_database (or composer_install_if_needed.php) to reconcile";
            return false;
        }
        return true;
    }

    /**
     * Installed vendor packages from composer.lock, or null when the lock is
     * missing or unparseable.
     * @return array|null package name => version
     */
    private function getInstalledPackages() {
        $lock_path = PathHelper::getBasePath() . '/composer.lock';
        if (!file_exists($lock_path)) {
            return null;
        }
        $lock = json_decode(file_get_contents($lock_path), true);
        if (!$lock || !isset($lock['packages'])) {
            return null;
        }
        $installed = [];
        foreach ($lock['packages'] as $package) {
            $installed[strtolower($package['name'])] = $package['version'];
        }
        return $installed;
    }

    /**
     * Bring the root composer install in line with plugin requires.composer
     * declarations: `composer require` anything missing, then record the
     * managed set in composer.json extra.joinery-plugin-packages.
     *
     * Composer itself is the conflict resolver - an unsatisfiable constraint
     * set fails the require, and the failure output lands in errors for the
     * caller to surface (activation refusal message).
     *
     * @param array|null $include_plugins Additional plugin names beyond the active set
     * @return bool True when every declared package is installed
     */
    public function reconcilePluginPackages($include_plugins = null) {
        $declared = $this->collectPluginComposerRequires($include_plugins);
        if (!empty($this->packageConflicts)) {
            $this->errors[] = "Conflicting plugin composer constraints: " . implode('; ', $this->packageConflicts);
            $this->errors[] = "Align the constraints in the plugins' plugin.json requires.composer blocks";
            return false;
        }
        if (empty($declared)) {
            return true;
        }

        $base_path = PathHelper::getBasePath();
        if (!file_exists($base_path . '/composer.json')) {
            $this->errors[] = "Cannot reconcile plugin packages: composer.json not found at $base_path";
            return false;
        }
        $root = json_decode(file_get_contents($base_path . '/composer.json'), true) ?: [];
        $root_require = array_change_key_case($root['require'] ?? [], CASE_LOWER);
        $installed = $this->getInstalledPackages() ?? [];

        $original_dir = getcwd();
        chdir($base_path);
        $all_ok = true;
        $newly_managed = [];
        $env_prefix = $this->composerEnvPrefix();
        foreach ($declared as $package => $constraint) {
            $satisfied = isset($installed[$package]) && ($root_require[$package] ?? null) === $constraint;
            if ($satisfied) {
                continue;
            }
            $output = [];
            $return_code = 0;
            exec($env_prefix . 'composer require ' . escapeshellarg($package . ':' . $constraint)
                . ' --no-dev --no-interaction --optimize-autoloader 2>&1', $output, $return_code);
            if ($return_code !== 0) {
                $this->errors[] = "composer require failed for $package:$constraint";
                $this->errors[] = implode("\n", array_slice($output, -12));
                $all_ok = false;
                continue;
            }
            $newly_managed[] = $package;
        }

        // Record the managed set so list_dependencies.php --orphans can
        // report packages no plugin declares anymore.
        if (!empty($newly_managed)) {
            $managed = array_values(array_unique(array_merge(
                array_map('strtolower', $root['extra']['joinery-plugin-packages'] ?? []),
                $newly_managed
            )));
            sort($managed);
            exec($env_prefix . 'composer config extra.joinery-plugin-packages --json '
                . escapeshellarg(json_encode($managed)) . ' 2>&1');
        }
        chdir($original_dir);

        return $all_ok;
    }

    /**
     * Environment prefix for shelling out to composer.
     *
     * Composer needs COMPOSER_HOME (or a writable HOME) for its config/cache.
     * Root CLI runs (upgrade, site init) have always had a writable HOME and
     * are left untouched. The one process without one is www-data during
     * activation-time reconcile; for it, use the site's existing cache
     * directory ({site root}/cache, chowned to www-data at container start)
     * rather than inventing a new location.
     */
    private function composerEnvPrefix() {
        if (getenv('COMPOSER_HOME')) {
            return '';
        }
        $home = getenv('HOME');
        if ($home && is_dir($home) && is_writable($home)) {
            return '';
        }
        $composer_home = dirname(PathHelper::getBasePath()) . '/cache/composer_home';
        if (!is_dir($composer_home)) {
            @mkdir($composer_home, 0770, true);
        }
        return 'COMPOSER_HOME=' . escapeshellarg($composer_home) . ' ';
    }

    /**
     * Normalize a path by resolving .. and . segments
     * @param string $path The path to normalize
     * @return string Normalized path
     */
    private function normalizePath($path) {
        $parts = explode('/', $path);
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return '/' . implode('/', $normalized) . '/';
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

        // Get configured vendor dir from composer.json and normalize to absolute path
        $configuredVendorDir = rtrim($composerJson['config']['vendor-dir'], '/');
        if (substr($configuredVendorDir, 0, 1) === '/') {
            // Absolute path - use as-is
            $expectedPath = $configuredVendorDir . '/';
        } else {
            // Relative path - resolve relative to base path
            $expectedPath = rtrim($basePath, '/') . '/' . $configuredVendorDir . '/';
        }

        // Get current setting path and normalize to absolute path
        $settingVendorDir = rtrim($this->composerPath, '/');
        if (substr($settingVendorDir, 0, 1) === '/') {
            // Absolute path - use as-is
            $settingPath = $settingVendorDir . '/';
        } else {
            // Relative path - resolve relative to base path
            $settingPath = rtrim($basePath, '/') . '/' . $settingVendorDir . '/';
        }

        // Normalize paths by resolving .. and removing double slashes
        $expectedPath = preg_replace('#/+#', '/', $expectedPath); // Remove double slashes
        $settingPath = preg_replace('#/+#', '/', $settingPath);

        // Resolve .. in paths
        $expectedPath = $this->normalizePath($expectedPath);
        $settingPath = $this->normalizePath($settingPath);

        // Detect change
        if ($expectedPath !== $settingPath) {
            return [
                'changed' => true,
                'old_path' => $this->composerPath, // Return original for display
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
            // Check if vendor exists at the new location
            if ($changeInfo['new_path'] && file_exists($changeInfo['new_path'] . 'autoload.php')) {
                // Vendor exists at new location - this is OK, just a warning
                $this->warnings[] = "Vendor directory location mismatch:";
                $this->warnings[] = "  Database setting: " . $changeInfo['old_path'];
                $this->warnings[] = "  composer.json config: " . $changeInfo['new_path'];
                $this->warnings[] = "  Vendor exists at new location - OK to proceed";
                return true;
            }

            // Mismatch AND vendor doesn't exist at new location - this is an error
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
        // Note: 'autoload.php not found' handles fresh installs where vendor directory doesn't exist
        $installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch', 'autoload.php not found', 'Missing plugin-declared packages'];
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
        exec($this->composerEnvPrefix() . 'composer install --no-dev --optimize-autoloader --no-interaction 2>&1', $output, $returnCode);

        chdir($originalDir);

        if ($returnCode !== 0) {
            $this->errors[] = "Composer install failed: " . implode("\n", $output);
            return false;
        }

        // Reconcile plugin-declared packages (requires.composer) into the
        // root install - `composer install` alone only covers what root
        // composer.json already lists.
        if (!$this->reconcilePluginPackages()) {
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