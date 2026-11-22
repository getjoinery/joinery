<?php

/**
 * ComposerValidator - Validates Composer installation and dependencies.
 *
 * The site's composer.json, composer.lock and vendor/ are core's alone. A
 * plugin that needs a library ships it in its own vendor/, which its
 * signature covers (specs/plugin_own_vendor.md); vendorOverlaps() checks that
 * two copies of one library agree.
 *
 * @version 1.14 - constraints with a space after the operator (">= 8.1") and hyphen ranges ("8.1 - 8.5") are read
 *                right; an ext-* requirement a package in the tree provides is met; an extension is matched as
 *                composer names it (ext-zend-opcache); an unreadable sandbox state skips the plugin (reviewer2 C4-C7)
 * @version 1.13 - a plugin's own vendor/ is checked: pluginVendorProblems() (the composer.json keys, no
 *                platform_check.php, its own autoloader class, fresh against its lock, platform needs this PHP
 *                meets) and vendorOverlaps() (one library at two builds); validate() warns on an active plugin's
 *                clash; ignoredVendorFiles(): library files git ignores under a plugin's vendor/
 *                (specs/plugin_own_vendor.md WP3)
 * @version 1.12 - the plugin composer tier is gone: no reconcile, no pluginPackagesPresent(), no plugin-declared
 *                packages check; the prune's keep set is the shipped lock alone (specs/plugin_own_vendor.md WP4)
 * @version 1.11 - a prune reads the active plugins' packages once and every step reuses that answer, so the
 *                database dropping mid-prune cannot shrink the keep set (reviewer2 B24)
 * @version 1.10 - the prune stops when the active plugins cannot be read (it read that as "none" and would
 *                remove their packages); the swap marker is unique per run and a stale one is removed first;
 *                a fallback swap whose renames all fail puts vendor.prune-old back (reviewer2 B10, B19)
 * @version 1.9 - pruneUnneededPackages(): installed packages the shipped code no longer needs leave vendor/,
 *                by copying vendor/, installing into the copy and swapping it in, so vendor/ never stops
 *                loading; unneededPackages() is the one keep-set rule the prune and validate()'s warning share;
 *                reconcilePluginPackages() can work on another vendor directory, and passes require
 *                --update-no-dev (it passed --no-dev, which require refuses, so no plugin package ever
 *                installed); Mailgun is no longer a critical package (specs/email_provider_sdk_removal.md WP8, B3)
 * @version 1.8 - root's composer home is /var/lib/joinery/composer_home, root-owned, not the web user's
 *                cache/composer_home (specs/shell_command_audit.md A11)
 * @version 1.7 - a composerAutoLoad that PathHelper refuses is a validate() warning: it is never loaded
 * @version 1.6 - a plugin's packages install with --no-plugins: no package code runs as root, whatever allow-plugins lists
 * @version 1.5 - composer runs under umask 022: what it writes into vendor/ is never group- or world-writable,
 *                whatever the caller's umask (a deploy's read-only-tree gate reverted a release over it)
 * @version 1.4 - the active plugins are the ones with plg_active = 1; plg_status can read 'stale'
 *                while a plugin runs, and its packages were left out
 * @version 1.3 - Presence is not enough: an installed package whose version
 *                differs from composer.lock is reported and is install-fixable.
 *                The truth source for what IS installed stays
 *                vendor/composer/installed.json; the lock states what SHOULD be.
 */
class ComposerValidator {

    private $composerPath;
    private $errors = [];
    private $warnings = [];

    /**
     * @param string|null $vendor_path Vendor directory override (trailing
     *        slash), for tests; defaults to the configured composerAutoLoad path.
     */
    public function __construct($vendor_path = null) {
        $this->composerPath = $vendor_path !== null ? $vendor_path : PathHelper::getComposerVendorPath();
        // A setting PathHelper refuses is never loaded (it falls back to
        // ../vendor/); validate() says so as a warning.
        $this->refused = $vendor_path !== null ? null : PathHelper::composerSettingProblem();
    }

    /** Why PathHelper refused the configured path, or null. */
    private $refused = null;

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

        // Check 6: Vendor directory consistency
        if (!$this->validateVendorDirConsistency()) {
            return false;
        }

        // Check 7: installed packages nothing needs. A warning, never an
        // error: an upgrade run as root removes them, and nothing that reads
        // validate() should turn a node red over them.
        $unneeded = $this->unneededPackages();
        if (!empty($unneeded)) {
            $this->warnings[] = "Packages not needed by this code: " . implode(', ', $unneeded)
                . " (the next upgrade run as root removes them)";
        }

        // Check 8: an active plugin's vendor/ holds a library core or another
        // active plugin also holds, at another build. A warning: a core
        // upgrade can move a shared library under an active plugin, and core's
        // copy is the one loaded (specs/plugin_own_vendor.md).
        foreach ($this->activePluginVendorClashes() as $clash) {
            $this->warnings[] = "Plugin library clash: " . $clash;
        }

        return true;
    }
    
    /**
     * Check if composer path is configured
     */
    private function validateComposerPathConfigured() {
        if ($this->refused !== null) {
            $this->warnings[] = $this->refused;
        }
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
        
        // composer.lock still needs to exist — composer install reads it for
        // deterministic versions. Its absence is install-fixable.
        if (!file_exists($composerLockPath)) {
            $this->errors[] = "composer.lock not found at: $composerLockPath";
            $this->errors[] = "Run 'composer install' in the project directory";
            return false;
        }

        // What is ACTUALLY installed in the vendor tree — never the lock file.
        $installedPackages = $this->getInstalledPackages();
        if ($installedPackages === null) {
            $this->errors[] = "Missing required packages: vendor tree at {$this->composerPath} was not composer-installed (no composer/installed.json)";
            $this->errors[] = "Run 'composer install' to build the vendor directory";
            return false;
        }
        
        // What the lock says SHOULD be installed, to catch a vendor tree that
        // carries the right package names at the wrong versions.
        $lockedPackages = $this->getLockedPackages($composerLockPath);

        // Check each required package
        $missingPackages = [];
        $mismatchedPackages = [];
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

            $key = strtolower($packageName);
            if (!isset($installedPackages[$key])) {
                $missingPackages[] = $packageName;
                continue;
            }

            // Presence alone says nothing about whether the deployed code can
            // run against what is on disk: a package can sit at a major version
            // whose whole namespace the source no longer matches. Compare against
            // the lock rather than the composer.json constraint — the lock ships
            // with the source, states an exact version, and needs no semver
            // evaluation to check.
            if (isset($lockedPackages[$key]) && $lockedPackages[$key] !== $installedPackages[$key]) {
                $mismatchedPackages[] = $packageName
                    . ' (installed ' . $installedPackages[$key]
                    . ', lock ' . $lockedPackages[$key] . ')';
            }
        }

        if (!empty($missingPackages)) {
            $this->errors[] = "Missing required packages: " . implode(', ', $missingPackages);
            $this->errors[] = "Checked composer.json: $composerJsonPath";
            $this->errors[] = "Checked composer.lock: $composerLockPath";
            $this->errors[] = "Run 'composer install' to install missing packages";
            return false;
        }

        if (!empty($mismatchedPackages)) {
            $this->errors[] = "Package version mismatch: " . implode(', ', $mismatchedPackages);
            $this->errors[] = "Checked composer.lock: $composerLockPath";
            $this->errors[] = "Run 'composer install' to match the lock file";
            return false;
        }

        // Check specific critical packages that the system needs
        $criticalPackages = [
            'phpmailer/phpmailer' => 'PHPMailer (for email functionality)',
            'stripe/stripe-php' => 'Stripe (for payment processing)',
        ];
        
        foreach ($criticalPackages as $package => $description) {
            if (!isset($installedPackages[strtolower($package)])) {
                $this->warnings[] = "Critical package missing: $package - $description";
            }
        }
        
        return true;
    }




    /**
     * Exact versions composer.lock pins, so a vendor tree can be compared
     * against what the shipped source expects. Empty when the lock is missing
     * or unparseable, which leaves the version comparison silent rather than
     * failing an upgrade on an unreadable file.
     * @return array package name => version
     */
    private function getLockedPackages($lockPath) {
        if (!file_exists($lockPath)) {
            return [];
        }
        $data = json_decode(file_get_contents($lockPath), true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return [];
        }
        // packages-dev is deliberately excluded: deploys install with --no-dev,
        // so a dev package is expected to be absent rather than mismatched.
        $locked = [];
        foreach ($data['packages'] as $package) {
            if (isset($package['name'])) {
                $locked[strtolower($package['name'])] = $package['version'] ?? '';
            }
        }
        return $locked;
    }

    /**
     * Vendor packages actually present on disk, or null when the vendor tree
     * was never composer-installed.
     * @return array|null package name => version
     */
    private function getInstalledPackages(?string $vendor_dir = null) {
        $list = $this->installedPackageList($vendor_dir);
        if ($list === null) {
            return null;
        }
        $installed = [];
        foreach ($list as $package) {
            if (isset($package['name'])) {
                $installed[strtolower($package['name'])] = $package['version'] ?? '';
            }
        }
        return $installed;
    }

    /**
     * installed.json's package entries for a vendor directory (default: this
     * validator's), or null when it was never composer-installed.
     */
    private function installedPackageList(?string $vendor_dir = null) {
        $vendor = $vendor_dir !== null ? rtrim($vendor_dir, '/') . '/' : $this->composerPath;
        // composer.lock is deliberately not consulted here: the lock ships
        // with the source and records what SHOULD be installed. installed.json
        // is written by composer into the vendor tree itself, so it reflects
        // what IS on disk — a vendor tree that was never composer-installed
        // (or was hand-assembled) has no installed.json and reports null.
        $installed_path = $vendor . 'composer/installed.json';
        if (!file_exists($installed_path)) {
            return null;
        }
        $data = json_decode(file_get_contents($installed_path), true);
        if (!is_array($data)) {
            return null;
        }
        // Composer 2 wraps the list under 'packages'; Composer 1 is a bare array.
        $list = isset($data['packages']) ? $data['packages'] : $data;
        return is_array($list) ? $list : null;
    }

    // ── A plugin's own vendor/ (specs/plugin_own_vendor.md) ────────────

    /**
     * The packages a vendor tree holds, from its composer/installed.json:
     * name => [version, reference, shasum, require]. Null when the tree has
     * no installed.json.
     */
    public static function vendorPackages(string $vendor_dir): ?array {
        $path = rtrim($vendor_dir, '/') . '/composer/installed.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }
        $list = isset($data['packages']) ? $data['packages'] : $data;
        return is_array($list) ? self::packageMap($list) : null;
    }

    /** The packages (never packages-dev) a composer.lock pins, in vendorPackages()'s shape. */
    public static function lockPackages(string $lock_path): ?array {
        if (!is_file($lock_path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($lock_path), true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return null;
        }
        return self::packageMap($data['packages']);
    }

    private static function packageMap(array $list): array {
        $map = [];
        foreach ($list as $package) {
            if (!is_array($package) || !isset($package['name'])) {
                continue;
            }
            $map[strtolower((string)$package['name'])] = [
                'version'   => (string)($package['version'] ?? ''),
                'reference' => (string)($package['dist']['reference'] ?? ($package['source']['reference'] ?? '')),
                'shasum'    => (string)($package['dist']['shasum'] ?? ''),
                'require'   => (array)($package['require'] ?? []),
                // What a package stands in for (symfony/polyfill-mbstring
                // provides ext-mbstring): a requirement another package in
                // the same tree provides is met (reviewer2 C5).
                'provide'   => array_change_key_case((array)($package['provide'] ?? []) + (array)($package['replace'] ?? []), CASE_LOWER),
            ];
        }
        return $map;
    }

    /**
     * Packages a plugin's vendor/ holds at another build than one of
     * $others holds. A build is the version and, where both sides record
     * them, the dist reference and shasum: two builds can share a version.
     * The same build is allowed, since the code is identical.
     *
     * @param string $plugin_dir the plugin's folder
     * @param array  $others     label => package map (vendorPackages() / lockPackages())
     * @return string[] one line per clash, naming the package and both builds
     */
    public static function vendorOverlaps(string $plugin_dir, array $others): array {
        $mine = self::vendorPackages(rtrim($plugin_dir, '/') . '/vendor');
        if ($mine === null) {
            return [];
        }
        $clashes = [];
        foreach ($others as $label => $theirs) {
            foreach ((array)$theirs as $name => $build) {
                if (!isset($mine[$name])) {
                    continue;
                }
                if (!self::sameBuild($mine[$name], $build)) {
                    $clashes[] = $name . ': ' . basename(rtrim($plugin_dir, '/')) . ' has '
                        . self::describeBuild($mine[$name]) . ', ' . $label . ' has ' . self::describeBuild($build);
                }
            }
        }
        return $clashes;
    }

    private static function sameBuild(array $a, array $b): bool {
        if ($a['version'] !== $b['version']) {
            return false;
        }
        foreach (['reference', 'shasum'] as $k) {
            if ($a[$k] !== '' && $b[$k] !== '' && $a[$k] !== $b[$k]) {
                return false;
            }
        }
        return true;
    }

    private static function describeBuild(array $b): string {
        return $b['version'] . ($b['reference'] !== '' ? ' (' . substr($b['reference'], 0, 12) . ')' : '');
    }

    /**
     * Why a plugin's own vendor/ cannot be published or activated, as lines;
     * empty when it can, or when the plugin has no vendor/. The checks:
     * its composer.json sets version, config.platform-check false and
     * config.autoloader-suffix JoineryPlugin_<name>; composer's
     * platform_check.php is absent (it writes a 500 into the response before
     * it throws); autoload_real.php declares the plugin's own init class;
     * installed.json matches the plugin's composer.lock packages; and, with
     * $platform, every package's php and ext-* needs are met by this PHP,
     * read as data with nothing executed.
     */
    public static function pluginVendorProblems(string $plugin_dir, bool $platform = true): array {
        $dir = rtrim($plugin_dir, '/');
        $name = basename($dir);
        if (!is_dir($dir . '/vendor')) {
            return [];
        }
        $problems = [];
        $suffix = 'JoineryPlugin_' . $name;

        $json = is_file($dir . '/composer.json') ? json_decode((string)file_get_contents($dir . '/composer.json'), true) : null;
        if (!is_array($json)) {
            $problems[] = "it has a vendor/ but no readable composer.json";
        } else {
            if (!isset($json['version']) || !is_string($json['version']) || $json['version'] === '') {
                $problems[] = 'its composer.json sets no "version"';
            }
            if (($json['config']['platform-check'] ?? null) !== false) {
                $problems[] = 'its composer.json does not set "config": {"platform-check": false}';
            }
            if (($json['config']['autoloader-suffix'] ?? null) !== $suffix) {
                $problems[] = 'its composer.json does not set "config": {"autoloader-suffix": "' . $suffix . '"}';
            }
        }

        if (file_exists($dir . '/vendor/composer/platform_check.php')) {
            $problems[] = 'vendor/composer/platform_check.php exists: rebuild with platform-check false';
        }

        $real = $dir . '/vendor/composer/autoload_real.php';
        $declared = (is_file($real) && preg_match('/\bclass\s+(ComposerAutoloaderInit\w+)/', (string)file_get_contents($real), $m)) ? $m[1] : '';
        if ($declared !== 'ComposerAutoloaderInit' . $suffix) {
            $problems[] = 'vendor/composer/autoload_real.php declares ' . ($declared !== '' ? $declared : 'no autoloader class')
                . ', not ComposerAutoloaderInit' . $suffix;
        }

        $installed = self::vendorPackages($dir . '/vendor');
        $locked = self::lockPackages($dir . '/composer.lock');
        if ($installed === null) {
            $problems[] = 'vendor/composer/installed.json is missing or unreadable';
        } elseif ($locked === null) {
            $problems[] = 'it has a vendor/ but no readable composer.lock';
        } else {
            $stale = [];
            foreach ($locked as $pkg => $b) {
                if (!isset($installed[$pkg]) || $installed[$pkg]['version'] !== $b['version']) {
                    $stale[] = $pkg;
                }
            }
            foreach (array_diff(array_keys($installed), array_keys($locked)) as $pkg) {
                $stale[] = $pkg;
            }
            if ($stale) {
                sort($stale);
                $problems[] = 'vendor/ does not match composer.lock (' . implode(', ', array_unique($stale))
                    . '): run composer install --no-dev in plugins/' . $name . ' and commit';
            }
        }

        if ($platform && $installed !== null) {
            foreach (self::platformShortfalls($installed) as $line) {
                $problems[] = $line;
            }
        }
        return $problems;
    }

    /**
     * What this PHP lacks of the php and ext-* requirements in a package
     * map. composer/semver is not in core's vendor/, so constraints are read
     * by satisfiesConstraint(), which knows *, exact, >, >=, <, <=, !=, ^ and
     * ~, space- or comma-separated (all must hold) and || (either).
     */
    public static function platformShortfalls(array $packages, ?string $php_version = null, ?callable $has_ext = null): array {
        $php_version = $php_version ?? PHP_VERSION;
        // Composer names an extension as PHP lists it, lowercased, spaces as
        // dashes: Zend OPcache is ext-zend-opcache (reviewer2 C6).
        $has_ext = $has_ext ?? function ($ext) {
            if (extension_loaded($ext)) {
                return true;
            }
            foreach (get_loaded_extensions() as $loaded) {
                if (str_replace(' ', '-', strtolower($loaded)) === $ext) {
                    return true;
                }
            }
            return false;
        };
        $provided = [];
        foreach ($packages as $b) {
            foreach (array_keys((array)($b['provide'] ?? [])) as $name) {
                $provided[strtolower((string)$name)] = true;
            }
        }
        $out = [];
        foreach ($packages as $pkg => $b) {
            foreach ((array)($b['require'] ?? []) as $req => $constraint) {
                $req = strtolower((string)$req);
                if ($req === 'php' || $req === 'php-64bit') {
                    if (!self::satisfiesConstraint($php_version, (string)$constraint)) {
                        $out[] = $pkg . ' needs PHP ' . $constraint . '; this is ' . $php_version;
                    }
                } elseif (strpos($req, 'ext-') === 0) {
                    $ext = substr($req, 4);
                    if (!isset($provided[$req]) && !$has_ext($ext)) {
                        $out[] = $pkg . ' needs the ' . $ext . ' extension, which this PHP does not have';
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Does $version meet a composer-style constraint? See platformShortfalls(). */
    public static function satisfiesConstraint(string $version, string $constraint): bool {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) as $alt) {
            $alt = trim($alt);
            // A hyphen range, A - B: at least A, and at most B (a partial B
            // covers everything it names, as composer reads it).
            if (preg_match('/^v?([0-9][0-9.]*)\s+-\s+v?([0-9][0-9.]*)$/', $alt, $r)) {
                $upper = explode('.', rtrim($r[2], '.'));
                if (count($upper) < 3) {
                    $upper[count($upper) - 1] = (string)((int)$upper[count($upper) - 1] + 1);
                    $alt = '>=' . $r[1] . ' <' . implode('.', $upper);
                } else {
                    $alt = '>=' . $r[1] . ' <=' . $r[2];
                }
            }
            // An operator written apart from its version (">= 8.1") stays
            // with it, so the version is never read as an exact match on its
            // own (reviewer2 C4).
            $alt = preg_replace('/(\^|~|>=|<=|!=|==|>|<|=)\s+/', '$1', $alt);
            $ok = true;
            foreach (preg_split('/\s*,\s*|\s+/', $alt) as $part) {
                if ($part === '' || $part === '*') {
                    continue;
                }
                if (!self::satisfiesOne($version, $part)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    private static function satisfiesOne(string $version, string $part): bool {
        $v = self::normalVersion($version);
        if (!preg_match('/^(\^|~|>=|<=|>|<|!=|==|=)?\s*v?([0-9][0-9.]*)(?:[-@].*)?(\.\*)?$/', $part, $m)) {
            return true; // not a form we read: never refuse on it
        }
        $op = $m[1] ?? '';
        $target = rtrim($m[2], '.');
        $t = self::normalVersion($target);
        $parts = explode('.', $target);
        switch ($op) {
            case '>=': return version_compare($v, $t, '>=');
            case '>':  return version_compare($v, $t, '>');
            case '<=': return version_compare($v, $t, '<=');
            case '<':  return version_compare($v, $t, '<');
            case '!=': return version_compare($v, $t, '!=');
            case '^':
                $major = (int)$parts[0];
                if ($major > 0) {
                    $upper = ($major + 1) . '.0.0';
                } elseif (isset($parts[1]) && (int)$parts[1] > 0) {
                    $upper = '0.' . ((int)$parts[1] + 1) . '.0';
                } else {
                    $upper = '0.0.' . ((int)($parts[2] ?? 0) + 1);
                }
                return version_compare($v, $t, '>=') && version_compare($v, $upper, '<');
            case '~':
                if (count($parts) === 1) {
                    $upper = ((int)$parts[0] + 1) . '.0.0';
                } else {
                    $head = array_slice($parts, 0, count($parts) - 1);
                    $head[count($head) - 1] = (string)((int)$head[count($head) - 1] + 1);
                    $upper = implode('.', $head) . '.0';
                }
                return version_compare($v, $t, '>=') && version_compare($v, self::normalVersion($upper), '<');
            default:
                if (!empty($m[3])) {
                    // 8.1.* : the same leading parts
                    return strpos($v . '.', $target . '.') === 0;
                }
                return version_compare($v, $t, '==');
        }
    }

    private static function normalVersion(string $v): string {
        $v = preg_replace('/[^0-9.].*$/', '', ltrim($v, 'v'));
        $parts = array_slice(array_pad(explode('.', $v), 3, '0'), 0, 3);
        return implode('.', array_map('intval', $parts));
    }

    /**
     * Files under a plugin's vendor/ that git ignores in the working
     * repository $repo, as paths relative to it. A library's own .gitignore
     * inside vendor/ overrides the ignore files' negation, so such a file is
     * on the author's disk and missing from the commit, and would not ship.
     * Null when $repo is not a git checkout or git cannot be asked.
     */
    public static function ignoredVendorFiles(string $repo, string $plugin_name): ?array {
        $repo = rtrim($repo, '/');
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $plugin_name) || !file_exists($repo . '/.git')) {
            return null;
        }
        $out = [];
        $rc = 0;
        exec('git -C ' . escapeshellarg($repo) . ' ls-files --others --ignored --exclude-standard -- '
            . escapeshellarg('public_html/plugins/' . $plugin_name . '/vendor') . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            return null;
        }
        return array_values(array_filter(array_map('trim', $out), 'strlen'));
    }

    /**
     * Each active, non-sandboxed plugin with a vendor/, as folder => package
     * map. Empty when the active set cannot be read.
     */
    public static function activePluginVendors(?string $except = null): array {
        $out = [];
        try {
            $names = class_exists('PluginHelper') ? (array)PluginHelper::activePluginNames() : [];
        } catch (Throwable $e) {
            return [];
        }
        sort($names);
        foreach ($names as $name) {
            $name = (string)$name;
            if ($name === $except || !preg_match('/^[a-zA-Z0-9_\-]+$/', $name)) {
                continue;
            }
            // Sandboxed, or unknown because the question cannot be asked: skip (reviewer2 C7).
            if (!class_exists('SandboxPlugins') || SandboxPlugins::isSandboxed($name)) {
                continue;
            }
            $dir = PathHelper::getBasePath() . '/plugins/' . $name;
            $packages = self::vendorPackages($dir . '/vendor');
            if ($packages !== null) {
                $out[$dir] = $packages;
            }
        }
        return $out;
    }

    /**
     * Clashes a plugin about to be activated would bring: against core's
     * vendor/ and each other active plugin's.
     */
    public function pluginActivationClashes(string $plugin_dir): array {
        $others = [];
        $core = self::vendorPackages($this->composerPath);
        if ($core !== null) {
            $others['core'] = $core;
        }
        foreach (self::activePluginVendors(basename(rtrim($plugin_dir, '/'))) as $dir => $packages) {
            $others['plugin ' . basename($dir)] = $packages;
        }
        return self::vendorOverlaps($plugin_dir, $others);
    }

    /** Clashes among the active plugins and core, for validate()'s warning. */
    private function activePluginVendorClashes(): array {
        $vendors = self::activePluginVendors();
        if (!$vendors) {
            return [];
        }
        $core = self::vendorPackages($this->composerPath);
        $clashes = [];
        $seen = [];
        foreach ($vendors as $dir => $packages) {
            $others = $core !== null ? ['core' => $core] : [];
            foreach ($seen as $other_dir => $other_packages) {
                $others['plugin ' . basename($other_dir)] = $other_packages;
            }
            $clashes = array_merge($clashes, self::vendorOverlaps($dir, $others));
            $seen[$dir] = $packages;
        }
        return $clashes;
    }

    // ── Pruning: packages the shipped code no longer needs ─────────────

    /**
     * Installed packages the shipped composer.lock (packages and
     * packages-dev) does not hold, sorted. The site's vendor/ is core's
     * alone: a plugin ships its own libraries in its own vendor/
     * (specs/plugin_own_vendor.md). The prune and validate()'s warning both
     * ask this, so they never disagree. Empty when either file is missing.
     *
     * @param string|null $vendor_dir Vendor directory to read (default: this validator's)
     * @param string|null $lock_path  composer.lock to read (default: the shipped one)
     */
    public function unneededPackages(?string $vendor_dir = null, ?string $lock_path = null): array {
        $list = $this->installedPackageList($vendor_dir);
        $lock_path = $lock_path ?? $this->pruneLock ?? PathHelper::getBasePath() . '/composer.lock';
        if ($list === null || !is_file($lock_path)) {
            return [];
        }
        $lock = json_decode(file_get_contents($lock_path), true);
        if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages'])) {
            return [];
        }

        $installed = [];
        foreach ($list as $package) {
            if (isset($package['name'])) {
                $installed[] = strtolower($package['name']);
            }
        }
        $keep = [];
        foreach (array_merge($lock['packages'], (array)($lock['packages-dev'] ?? [])) as $package) {
            if (isset($package['name'])) {
                $keep[strtolower($package['name'])] = true;
            }
        }

        $unneeded = array_values(array_diff($installed, array_keys($keep)));
        sort($unneeded);
        return $unneeded;
    }

    /** What the prune's last run said, step by step. */
    public function getPruneLog(): array {
        return $this->pruneLog;
    }

    private $pruneLog = [];



    /** The marker this run's copy carries, unique per run. */
    private $pruneToken = '';

    /** @var callable|null tests: stand-in for exec() of the swap's mv */
    private $pruneMv = null;

    /** @var callable|null tests: stand-in for composer install into the copy (step 3) */
    private $pruneComposer = null;

    /** @var string|null tests: stop after this step (1-5) to prove what is left */
    private $pruneStopAfter = null;

    /** @var string|null tests: the composer.lock the keep set is read from */
    private $pruneLock = null;



    /**
     * Test seams for the prune, CLI only: 'mv' => fn(string $cmd): int,
     * 'composer' => fn(string $copy): bool, 'stop_after' => step number,
     * 'lock' => composer.lock path.
     */
    public function usePruneSeamsForTest(array $seams): void {
        if (php_sapi_name() !== 'cli') {
            throw new LogicException('usePruneSeamsForTest is for test processes only.');
        }
        $this->pruneMv = $seams['mv'] ?? null;
        $this->pruneComposer = $seams['composer'] ?? null;
        $this->pruneStopAfter = isset($seams['stop_after']) ? (int)$seams['stop_after'] : null;
        $this->pruneLock = $seams['lock'] ?? null;
    }

    /**
     * Remove installed packages the shipped code no longer needs, without
     * ever leaving vendor/ in a state that fails to load. Runs as root, after
     * the upgrade's last rollback point.
     *
     *   1. Recover a vendor/ an interrupted swap left as vendor.prune-old,
     *      then remove any leftover vendor.prune-*.
     *   2. Copy vendor/ to vendor.prune-new, with a .prune-new marker in it.
     *   3. composer install into the copy (COMPOSER_VENDOR_DIR at it): it removes what the lock does not
     *      hold and rewrites the copy's autoloader.
     *   4. Check the copy: a fresh PHP loads its autoload.php, and nothing in
     *      it is unneeded.
     *   5. Give the copy vendor/'s owner and modes.
     *   6. Swap: mv --exchange, checked by the marker; where mv cannot
     *      exchange, two renames.
     *
     * Nothing touches the live vendor/ before step 6, so a prune that stops
     * earlier leaves the site loading exactly as before. The caller reloads
     * PHP afterwards. Returns true when there was nothing to do or the prune
     * completed; false with the reason in getPruneLog().
     *
     * @param string|null $vendor_dir The live vendor directory (default: this validator's)
     */
    public function pruneUnneededPackages(?string $vendor_dir = null): bool {
        $this->pruneLog = [];
        $vendor = rtrim($vendor_dir ?? $this->composerPath, '/');
        $new = $vendor . '.prune-new';
        $old = $vendor . '.prune-old';

        // Step 1: recover, then clean up.
        if (!file_exists($vendor) && is_dir($old) && !is_link($old)) {
            if (!@rename($old, $vendor)) {
                $this->pruneLog[] = "vendor/ is missing and $old could not be renamed back";
                return false;
            }
            $this->pruneLog[] = 'Put back vendor/ from an interrupted prune';
        }
        foreach ([$new, $old, $vendor . '/.prune-new'] as $leftover) {
            if (file_exists($leftover) || is_link($leftover)) {
                $this->runShell('rm -rf -- ' . escapeshellarg($leftover));
                $this->pruneLog[] = 'Removed leftover ' . basename($leftover);
            }
        }
        if ($this->pruneStopAfter === 1) { return false; }

        $unneeded = $this->unneededPackages($vendor . '/');
        if (empty($unneeded)) {
            return true;
        }
        $this->pruneLog[] = 'Not needed: ' . implode(', ', $unneeded);

        // Step 2: copy, with the marker the swap is checked by.
        if ($this->runShell('cp -a -- ' . escapeshellarg($vendor) . ' ' . escapeshellarg($new)) !== 0
                || @file_put_contents($new . '/.prune-new', $this->pruneToken = bin2hex(random_bytes(16))) === false) {
            return $this->pruneFailed($new, 'could not copy vendor/');
        }
        if ($this->pruneStopAfter === 2) { return false; }

        // Step 3: install into the copy.
        if ($this->pruneComposer !== null) {
            $installed = (bool)($this->pruneComposer)($new);
        } else {
            $out = [];
            $rc = 0;
            exec('cd ' . escapeshellarg(PathHelper::getBasePath()) . ' && ' . $this->composerEnvPrefix()
                . 'COMPOSER_VENDOR_DIR=' . escapeshellarg($new) . ' '
                . 'composer install --no-dev --optimize-autoloader --no-interaction --no-plugins 2>&1', $out, $rc);
            $installed = ($rc === 0);
            if (!$installed) {
                $this->pruneLog[] = 'composer install into the copy failed: ' . implode(' ', array_slice($out, -6));
            }
        }
        if (!$installed) {
            return $this->pruneFailed($new, 'the copy could not be installed');
        }
        if ($this->pruneStopAfter === 3) { return false; }

        // Step 4: the copy loads, and holds nothing unneeded.
        $out = [];
        $rc = 0;
        exec('php -r ' . escapeshellarg('require $argv[1]; exit(0);') . ' -- '
            . escapeshellarg($new . '/autoload.php') . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            return $this->pruneFailed($new, 'the copy does not load: ' . implode(' ', array_slice($out, -3)));
        }
        $left = $this->unneededPackages($new . '/');
        if (!empty($left)) {
            return $this->pruneFailed($new, 'the copy still holds ' . implode(', ', $left));
        }
        if ($this->pruneStopAfter === 4) { return false; }

        // Step 5: vendor/'s owner and the executable set's modes.
        if (!$this->matchOwnerAndModes($vendor, $new)) {
            return $this->pruneFailed($new, 'could not set the copy\'s owner and modes');
        }
        if ($this->pruneStopAfter === 5) { return false; }

        // Step 6: swap.
        if (!$this->swapIn($vendor, $new, $old)) {
            return false;
        }
        @unlink($vendor . '/.prune-new');
        $this->runShell('rm -rf -- ' . escapeshellarg($new) . ' ' . escapeshellarg($old));
        $this->pruneLog[] = 'Removed ' . count($unneeded) . ' package(s): ' . implode(', ', $unneeded);
        return true;
    }

    /**
     * Put $new in vendor/'s place. Tries mv --exchange, one atomic rename,
     * and believes only the marker: the swap counts when vendor/.prune-new
     * exists and vendor/vendor.prune-new does not. A mv that took the flag
     * but did a plain move put the copy inside vendor/; that is moved back
     * out and the two renames run. mv's version is never asked: Ubuntu
     * 26.04's default mv is uutils, which has differed from GNU before.
     */
    private function swapIn(string $vendor, string $new, string $old): bool {
        $nested = $vendor . '/' . basename($new);
        // -T: GNU mv reads an existing directory target as "move into it"
        // and tries to exchange with vendor/vendor.prune-new, which fails.
        $rc = $this->runMv('mv --exchange -T -- ' . escapeshellarg($new) . ' ' . escapeshellarg($vendor));
        if ($rc === 0) {
            if ($this->markerIsOurs($vendor) && !file_exists($nested)) {
                $this->pruneLog[] = 'Swapped in the pruned copy (mv --exchange)';
                return true;
            }
            if (file_exists($nested) && !file_exists($new)) {
                if (!@rename($nested, $new)) {
                    $this->pruneLog[] = 'mv --exchange moved the copy inside vendor/ and it could not be moved back out';
                    return false;
                }
                $this->pruneLog[] = 'mv --exchange did a plain move; moved the copy back out';
            } else {
                $this->pruneLog[] = 'mv --exchange reported success but neither tree is where it should be';
                return false;
            }
        }
        if (!is_dir($vendor) || !is_dir($new)) {
            $this->pruneLog[] = 'vendor/ or the copy is missing before the fallback swap';
            return false;
        }
        $rc = $this->runMv('mv -T -- ' . escapeshellarg($vendor) . ' ' . escapeshellarg($old)
            . ' && mv -T -- ' . escapeshellarg($new) . ' ' . escapeshellarg($vendor)
            . ' || mv -T -- ' . escapeshellarg($old) . ' ' . escapeshellarg($vendor));
        if ($this->markerIsOurs($vendor)) {
            $this->pruneLog[] = 'Swapped in the pruned copy (two renames)';
            return true;
        }
        // All three renames can fail; do not report vendor/ as it was when
        // it is missing. Put it back here: the converger's tick is held off
        // while the upgrade holds its lock.
        if (!file_exists($vendor) && is_dir($old) && @rename($old, $vendor)) {
            $this->pruneLog[] = 'The swap did not complete (mv exit ' . $rc . '); renamed vendor.prune-old back, vendor/ is as it was';
        } elseif (!file_exists($vendor)) {
            $this->pruneLog[] = 'The swap did not complete (mv exit ' . $rc . ') and vendor/ is MISSING; rename ' . $old . ' back to ' . $vendor;
        } else {
            $this->pruneLog[] = 'The swap did not complete (mv exit ' . $rc . '); vendor/ is as it was';
        }
        return false;
    }

    /** Does vendor/ carry this run's marker (not a stale one from an earlier run)? */
    private function markerIsOurs(string $vendor): bool {
        return $this->pruneToken !== '' && is_file($vendor . '/.prune-new')
            && @file_get_contents($vendor . '/.prune-new') === $this->pruneToken;
    }

    /** Give $copy the owner and group of $vendor, and the executable set's modes. */
    private function matchOwnerAndModes(string $vendor, string $copy): bool {
        $owner = @fileowner($vendor);
        $group = @filegroup($vendor);
        if ($owner === false || $group === false) {
            return false;
        }
        // Only what is wrong is changed, as fix_permissions.sh does.
        $c = escapeshellarg($copy);
        $rc = 0;
        $rc |= $this->runShell("find $c -type d -not -perm 755 -exec chmod 755 {} +");
        $rc |= $this->runShell("find $c -type f -name '*.sh' -not -perm 755 -exec chmod 755 {} +");
        $rc |= $this->runShell("find $c -type f -not -name '*.sh' -not -perm 644 -exec chmod 644 {} +");
        $rc |= $this->runShell("find $c \\( -type f -o -type d \\) \\( -not -uid $owner -o -not -gid $group \\) -exec chown $owner:$group {} +");
        return $rc === 0;
    }

    private function pruneFailed(string $new, string $why): bool {
        $this->runShell('rm -rf -- ' . escapeshellarg($new));
        $this->pruneLog[] = 'Prune stopped, vendor/ untouched: ' . $why;
        return false;
    }

    private function runMv(string $cmd): int {
        if ($this->pruneMv !== null) {
            return (int)($this->pruneMv)($cmd);
        }
        return $this->runShell($cmd);
    }

    private function runShell(string $cmd): int {
        $out = [];
        $rc = 0;
        exec($cmd . ' 2>&1', $out, $rc);
        return $rc;
    }



    /**
     * Environment prefix for shelling out to composer.
     *
     * Two things composer needs that a root request does not get for free.
     *
     * COMPOSER_HOME is always set, to the site's own cache/composer_home. It
     * used to be set only when nothing else provided one, which meant composer
     * wrote its cache into whichever HOME the caller happened to have — root's
     * during an upgrade, www-data's during an activation — so the same site had
     * two caches and root left files in /root that the next run could not read.
     * One directory beside the site is the whole of it.
     *
     * COMPOSER_ALLOW_SUPERUSER is set when this is running as root, as the
     * upgrade's composer step and its prune do. Composer refuses to run as root
     * without it and prints a warning that reads like a failure; every command
     * here passes --no-plugins, so no package code runs as root.
     */
    private function composerEnvPrefix() {
        // Composer reads its global config.json, auth.json and plugin list from
        // COMPOSER_HOME. When root runs it (an upgrade's composer step), that
        // directory is root's own under /var/lib, never cache/, which the web
        // user owns and could seed (specs/shell_command_audit.md A11). The web
        // user's own validation runs keep the site's cache/ copy.
        $is_root = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $composer_home = $is_root
            ? '/var/lib/joinery/composer_home'
            : dirname(PathHelper::getBasePath()) . '/cache/composer_home';
        if (!is_dir($composer_home)) {
            @mkdir($composer_home, $is_root ? 0700 : 0770, true);
        }
        if ($is_root && (is_link($composer_home) || fileowner($composer_home) !== 0)) {
            throw new RuntimeException('composer home ' . $composer_home . ' is not a root-owned directory');
        }
        // umask 022 first: vendor/ is part of the executable set
        // (specs/read_only_tree.md), and a package composer writes under a
        // permissive caller umask arrives group- or world-writable.
        $prefix = 'umask 022 && COMPOSER_HOME=' . escapeshellarg($composer_home) . ' ';
        if ($is_root) {
            $prefix .= 'COMPOSER_ALLOW_SUPERUSER=1 ';
        }
        return $prefix;
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
        $installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Package version mismatch', 'Vendor directory mismatch', 'autoload.php not found'];
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