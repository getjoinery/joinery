<?php
/**
 * OAuth2ProviderRegistry - Discovers OAuth2 provider classes by interface.
 *
 * Mirrors InboundProviderRegistry: require_once every file in
 * includes/oauth/providers/, then walk get_declared_classes() for anything
 * implementing OAuth2Provider, keyed by getKey(). Because discovery is
 * interface-based, a test fixture provider already required by a test bootstrap
 * is discovered too (call reset() to force a re-scan).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Provider.php'));
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));

class OAuth2ProviderRegistry {

    /** @var array<string,string>|null Cached key => class map. */
    private static $providers = null;

    /** All discovered providers as [key => class]. */
    public static function all(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $dir = PathHelper::getIncludePath('includes/oauth/providers/');
        if (is_dir($dir)) {
            foreach (glob($dir . '*.php') as $file) {
                require_once($file);
            }
        }

        self::$providers = [];
        foreach (get_declared_classes() as $class) {
            if (in_array('OAuth2Provider', class_implements($class) ?: [], true)) {
                $key = $class::getKey();
                if ($key !== '') {
                    self::$providers[$key] = $class;
                }
            }
        }

        return self::$providers;
    }

    /** Provider class-string for a key, or null. */
    public static function get(string $key): ?string {
        $providers = self::all();
        return $providers[$key] ?? null;
    }

    /** Only providers whose client id + secret are present. */
    public static function configured(): array {
        $out = [];
        foreach (self::all() as $key => $class) {
            if ($class::isConfigured()) {
                $out[$key] = $class;
            }
        }
        return $out;
    }

    /** Test/dev helper: forget cached discovery so a re-scan picks up new classes. */
    public static function reset(): void {
        self::$providers = null;
    }
}
