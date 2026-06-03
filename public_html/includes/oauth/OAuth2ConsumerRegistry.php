<?php
/**
 * OAuth2ConsumerRegistry - Discovers OAuth2 consumer classes by interface.
 *
 * Same scan-and-walk pattern as OAuth2ProviderRegistry, extended to plugin
 * paths: require_once every file in core includes/oauth/consumers/ and in each
 * active plugin's includes/oauth_consumers/, then walk get_declared_classes()
 * for OAuth2Consumer implementations keyed by getPurpose(). get() returns a
 * fresh instance (onTokenGranted is an instance method); a test fixture
 * consumer already required by a test bootstrap is discovered too.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Consumer.php'));

class OAuth2ConsumerRegistry {

    /** @var array<string,string>|null Cached purpose => class map. */
    private static $consumers = null;

    /** All discovered consumers as [purpose => class]. */
    public static function all(): array {
        if (self::$consumers !== null) {
            return self::$consumers;
        }

        $dirs = [PathHelper::getIncludePath('includes/oauth/consumers/')];

        try {
            foreach (PluginHelper::getActivePlugins() as $name => $plugin) {
                $dirs[] = PathHelper::getIncludePath('plugins/' . $name . '/includes/oauth_consumers/');
            }
        } catch (Throwable $e) {
            error_log('OAuth2ConsumerRegistry: error enumerating active plugins: ' . $e->getMessage());
        }

        foreach ($dirs as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '*.php') as $file) {
                    require_once($file);
                }
            }
        }

        self::$consumers = [];
        foreach (get_declared_classes() as $class) {
            if (in_array('OAuth2Consumer', class_implements($class) ?: [], true)) {
                $purpose = $class::getPurpose();
                if ($purpose !== '') {
                    self::$consumers[$purpose] = $class;
                }
            }
        }

        return self::$consumers;
    }

    /** A fresh consumer instance for a purpose, or null if none registered. */
    public static function get(string $purpose): ?OAuth2Consumer {
        $consumers = self::all();
        if (!isset($consumers[$purpose])) {
            return null;
        }
        $class = $consumers[$purpose];
        return new $class();
    }

    /** Test/dev helper: forget cached discovery so a re-scan picks up new classes. */
    public static function reset(): void {
        self::$consumers = null;
    }
}
