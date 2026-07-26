<?php
require_once(PathHelper::getIncludePath('includes/mailing_list_providers/MailingListProvider.php'));
require_once(PathHelper::getIncludePath('includes/mailing_list_providers/AbstractMailingListProvider.php'));
require_once(PathHelper::getIncludePath('includes/mailing_list_providers/MailingListProviderException.php'));

/**
 * MailingListService — provider discovery + factory.
 *
 * Auto-discovers provider classes in includes/mailing_list_providers/ and
 * provides a configured-provider lookup based on the mailing_list_provider
 * setting. Mirrors EmailSender::discoverProviders().
 */
class MailingListService {
    /** @var array|null Cached provider registry: key => class name */
    private static $providers = null;

    /**
     * Scan includes/mailing_list_providers/ for classes implementing
     * MailingListProvider. Skips the abstract base class and the exception.
     * Cached for the lifetime of the request.
     */
    private static function discoverProviders(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }

        self::$providers = [];
        $provider_dir = PathHelper::getIncludePath('includes/mailing_list_providers/');

        foreach (glob($provider_dir . '*Provider.php') as $file) {
            require_once($file);
            $class = basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            if (!in_array('MailingListProvider', class_implements($class) ?: [])) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                continue; // skip the abstract base
            }
            $key = $class::getKey();
            self::$providers[$key] = $class;
        }

        return self::$providers;
    }

    /**
     * Get the configured provider instance, or null if no provider is configured
     * or the configured provider class cannot be found.
     *
     * Pass a key explicitly to bypass the configured-default lookup.
     */
    public static function getProvider(?string $key = null): ?MailingListProvider {
        if ($key === null) {
            $settings = Globalvars::get_instance();
            $key = $settings->get_setting('mailing_list_provider');
            if (empty($key)) {
                return null;
            }
        }

        $providers = self::discoverProviders();
        if (!isset($providers[$key])) {
            return null;
        }
        $class = $providers[$key];
        return new $class();
    }

    /**
     * Return all discovered providers as ['key' => 'Label'] for dropdowns.
     */
    public static function getAvailableServices(): array {
        $services = [];
        foreach (self::discoverProviders() as $key => $class) {
            $services[$key] = $class::getLabel();
        }
        return $services;
    }

    /**
     * The settings a provider is configured with, as declared in settings.json.
     *
     * The mailing list providers share one group, each field carrying a
     * show_when on the selected provider, so a page renders the group and the
     * right credentials appear.
     */
    public static function getProviderSettings(string $key): array {
        $providers = self::discoverProviders();
        if (!isset($providers[$key])) {
            return [];
        }
        require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

        $fields = array();
        foreach (SettingsDeclarations::forGroup('mailing_list', 'core') as $declaration) {
            if (($declaration['show_when']['mailing_list_provider'] ?? null) === $key) {
                $fields[] = $declaration;
            }
        }
        return $fields;
    }

    /**
     * Return the discovered providers registry (for admin page iteration).
     * Returns ['key' => 'ClassName', ...]
     */
    public static function getDiscoveredProviders(): array {
        return self::discoverProviders();
    }

    /**
     * Reset the cached provider list (useful for testing).
     */
    public static function resetProviderCache(): void {
        self::$providers = null;
    }
}
