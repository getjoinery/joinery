<?php
require_once(__DIR__ . '/ComponentBase.php');

/**
 * ThemeHelper - Manages theme metadata and provides helper functions
 * Extends ComponentBase for common functionality
 *
 * @version 1.1 - styleThemeRefusal(): a theme with nothing the platform can
 *                execute or render is a style theme, and the one method that
 *                decides it is shared by the installer, the sync and the test
 *                (specs/style_themes.md WP1); styleSheets() and brandTokens()
 */
class ThemeHelper extends ComponentBase {
    protected $componentType = 'theme';

    private static $instances = [];

    /**
     * What a style theme may carry, by extension. Anything else makes the
     * package a page theme — it is not refused, it is the kind every theme
     * shipped today already is.
     */
    const STYLE_THEME_EXTENSIONS = array(
        'css',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
        'svg',
        'txt', 'md',
    );

    /**
     * Why this directory is not a style theme, or null when it is one.
     *
     * A style theme holds nothing the platform can execute or render as a
     * document: no PHP, no script, no HTML or XML, no dotfile, and no
     * stylesheet that names another origin. SVG passes because the static
     * file server marks every SVG as an attachment, so a direct visit
     * downloads it while <img> and CSS url() contexts render it without
     * running its scripts. The first reason found is returned with the
     * file's path relative to the directory, so the transcript says which
     * file made it a page theme.
     *
     * A style theme's manifest must list the stylesheets to emit (`styles`),
     * because a look with no stylesheet is a mistake, and that is checked
     * here too: it is part of what makes the package a style theme.
     *
     * @param string $dir Absolute path of the theme directory
     * @return string|null
     */
    public static function styleThemeRefusal(string $dir): ?string {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return 'not a directory';
        }
        $manifest_path = $dir . '/theme.json';
        if (!is_file($manifest_path)) {
            return 'theme.json: missing';
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $rel = $it->getSubPathName();
            $base = $item->getFilename();
            if ($base !== '' && $base[0] === '.') {
                return $rel . ': nothing in a styling theme starts with a dot';
            }
            if ($item->isDir()) {
                continue;
            }
            // The manifest, and the signing record a package we published
            // carries (specs/implemented/package_signing.md).
            if ($rel === 'theme.json' || $rel === PackageSignature::MANIFEST_NAME
                || $rel === PackageSignature::SIGNATURE_NAME) {
                continue;
            }
            $ext = strtolower((string)pathinfo($base, PATHINFO_EXTENSION));
            if ($ext === 'php' || $ext === 'phtml') {
                return $rel . ': runs on the server';
            }
            if ($ext === 'js' || $ext === 'mjs') {
                return $rel . ': runs in the browser with the session, the CSRF token and the unlocked vault';
            }
            if ($ext === 'html' || $ext === 'htm' || $ext === 'xml') {
                return $rel . ': a document the browser would render from this origin';
            }
            if (!in_array($ext, self::STYLE_THEME_EXTENSIONS, true)) {
                return $rel . ': not a stylesheet, font or image';
            }
            if ($ext === 'css') {
                $why = self::stylesheetRefusal((string)@file_get_contents($item->getPathname()));
                if ($why !== null) {
                    return $rel . ': ' . $why;
                }
            }
        }

        $manifest = json_decode((string)@file_get_contents($manifest_path), true);
        if (!is_array($manifest)) {
            return 'theme.json: not valid JSON';
        }
        $styles = $manifest['styles'] ?? null;
        if (!is_array($styles) || $styles === array()) {
            return 'theme.json: names no styles; a styling theme lists the stylesheets to emit';
        }
        foreach ($styles as $rel) {
            $rel = ltrim((string)$rel, '/');
            if ($rel === '' || strpos($rel, '..') !== false) {
                return 'theme.json: styles names a path outside the theme';
            }
            if (!is_file($dir . '/' . $rel)) {
                return 'theme.json: styles names ' . $rel . ', which is not in the theme';
            }
            if (strtolower((string)pathinfo($rel, PATHINFO_EXTENSION)) !== 'css') {
                return 'theme.json: styles names ' . $rel . ', which is not a stylesheet';
            }
        }
        return null;
    }

    /**
     * Why a stylesheet's text may not be in a style theme, or null.
     *
     * An `@import` or `url()` naming another origin is a beacon: it reports
     * every page visit to that origin, and with attribute selectors it can
     * read some form values. A relative path, a same-origin absolute path
     * and a `data:` URL pass. Comments are stripped first so a reference
     * inside one is neither refused nor hidden behind one that is.
     */
    public static function stylesheetRefusal(string $css): ?string {
        $css = (string)preg_replace('~/\*.*?\*/~s', '', $css);
        $refs = array();
        if (preg_match_all('~url\(\s*(["\']?)(.*?)\1\s*\)~is', $css, $m)) {
            foreach ($m[2] as $ref) { $refs[] = array('url()', $ref); }
        }
        if (preg_match_all('~@import\s+(?!url\()(["\'])(.*?)\1~is', $css, $m)) {
            foreach ($m[2] as $ref) { $refs[] = array('@import', $ref); }
        }
        foreach ($refs as $pair) {
            list($what, $ref) = $pair;
            $ref = trim($ref);
            if ($ref === '') { continue; }
            if (strpos($ref, '//') === 0) {
                return $what . ' names another origin (' . $ref . ')';
            }
            if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $ref, $s)) {
                if (strtolower($s[1]) === 'data') { continue; }
                return $what . ' names another origin (' . $ref . ')';
            }
        }
        return null;
    }

    /**
     * The stylesheets a style theme emits, in manifest order, as paths
     * relative to the theme directory. Only files that exist are returned.
     */
    public function styleSheets(): array {
        $out = array();
        $styles = $this->manifestData['styles'] ?? array();
        if (!is_array($styles)) { return $out; }
        foreach ($styles as $rel) {
            $rel = ltrim((string)$rel, '/');
            if ($rel === '' || strpos($rel, '..') !== false) { continue; }
            $full = PathHelper::getIncludePath($this->basePath . '/' . $rel);
            if (is_string($full) && is_file($full)) {
                $out[] = $rel;
            }
        }
        return $out;
    }

    /** The manifest's brand tokens, name => value, or an empty array. */
    public function brandTokens(): array {
        $declared = $this->manifestData['brand_tokens'] ?? array();
        if (!is_array($declared)) { return array(); }
        $out = array();
        foreach ($declared as $name => $val) {
            if (is_string($name)) { $out[$name] = $val; }
        }
        return $out;
    }
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct($themeName) {
        $this->name = $themeName;
        $this->basePath = "theme/{$themeName}";
        $this->manifestPath = PathHelper::getIncludePath("{$this->basePath}/theme.json");
        
        // Load manifest (will throw exception if not found/invalid)
        $this->loadManifest();
    }
    
    /**
     * Get ThemeHelper instance for a theme (singleton pattern)
     */
    public static function getInstance($themeName = null) {
        // Use current theme if not specified
        if (!$themeName) {
            $settings = Globalvars::get_instance();
            $themeName = $settings->get_setting('theme_template', true, true);
            
            if (!$themeName) {
                throw new Exception("No theme specified and no default theme configured");
            }
        }
        
        // Return cached instance or create new one
        if (!isset(self::$instances[$themeName])) {
            self::$instances[$themeName] = new self($themeName);
        }

        return self::$instances[$themeName];
    }

    /**
     * Drop the cached instance for a theme, so the next getInstance() reads
     * its manifest off disk again: after an install, a sync, or a test that
     * wrote the directory.
     */
    public static function forget(string $themeName): void {
        unset(self::$instances[$themeName]);
    }
    
    /**
     * Validate theme structure and requirements
     */
    public function validate() {
        $errors = [];
        
        // Check requirements
        $reqCheck = $this->checkRequirements();
        if ($reqCheck !== true) {
            $errors = array_merge($errors, $reqCheck);
        }
        
        // Check for theme directory
        if (!is_dir(PathHelper::getIncludePath($this->basePath))) {
            $errors[] = "Theme directory not found: {$this->basePath}";
        }
        
        // Manifest is mandatory and already validated in loadManifest()
        // If we got here, manifest exists and is valid
        
        // Check for required manifest fields
        if (empty($this->manifestData['name'])) {
            $errors[] = "Theme manifest missing required field: name";
        }

        // Maturity status is honest labeling only and gates nothing, but an
        // unknown value is a manifest error, not a silently ignored string.
        if (isset($this->manifestData['status'])
            && !in_array($this->manifestData['status'], array('experimental', 'beta', 'stable', 'deprecated'), true)) {
            $errors[] = "Unknown status '" . $this->manifestData['status'] . "' — must be one of: experimental, beta, stable, deprecated";
        }
        
        // An audience is a list of site domains. A string or object here would
        // silently hide the theme from every catalog, so it fails loudly.
        if (isset($this->manifestData['audience'])) {
            $audience = $this->manifestData['audience'];
            $audience_valid = is_array($audience) && $audience === array_values($audience);
            if ($audience_valid) {
                foreach ($audience as $audience_entry) {
                    if (!is_string($audience_entry) || trim($audience_entry) === '') {
                        $audience_valid = false;
                        break;
                    }
                }
            }
            if (!$audience_valid) {
                $errors[] = "Invalid audience — must be a list of site domains, e.g. [\"example.com\"]";
            }
        }

        return empty($errors) ? true : $errors;
    }
    
    /**
     * Get CSS framework used by theme
     */
    public function getCssFramework() {
        return $this->manifestData['cssFramework'] ?? null;
    }
    
    // === STATIC HELPER METHODS ===
    // These provide convenient access without needing the instance
    
    /**
     * Get theme configuration value
     */
    public static function config($key, $default = null, $themeName = null) {
        try {
            $instance = self::getInstance($themeName);
            return $instance->get($key, $default);
        } catch (Exception $e) {
            return $default;
        }
    }
    
    /**
     * Get all available themes with their helpers
     */
    public static function getAvailableThemes() {
        $themes = [];
        $themeDir = PathHelper::getIncludePath('theme');
        
        if (is_dir($themeDir)) {
            $directories = glob($themeDir . '/*', GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                $themeName = basename($dir);
                try {
                    $themes[$themeName] = self::getInstance($themeName);
                } catch (Exception $e) {
                    // Skip themes without valid manifests
                    error_log("Theme {$themeName} skipped: " . $e->getMessage());
                    continue;
                }
            }
        }
        
        return $themes;
    }
    
    /**
     * Check if theme exists and has valid manifest
     */
    public static function themeExists($themeName) {
        try {
            self::getInstance($themeName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get active theme name
     */
    public static function getActive() {
        $settings = Globalvars::get_instance();
        return $settings->get_setting('theme_template', true, true);
    }
    
}