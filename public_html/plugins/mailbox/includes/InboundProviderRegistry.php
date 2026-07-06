<?php
/**
 * InboundProviderRegistry - Discovers inbound email provider classes by
 * interface, regardless of file path.
 *
 * Loads every file in includes/email_providers/ and in the plugin's
 * (optional) includes/inbound_providers/ directory, then walks
 * get_declared_classes() to find anything implementing
 * InboundEmailProvider. Because discovery is interface-based, the same
 * MailgunProvider class that EmailSender::getProvider('mailgun') returns
 * for outbound is the same class this registry returns for inbound.
 *
 * The active provider is selected by the `mailbox_provider`
 * setting; unknown values fall back to PostfixProvider.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));
// Some providers in includes/email_providers/ implement EmailServiceProvider too;
// load the interface up-front so the require_once below succeeds.
require_once(PathHelper::getIncludePath('includes/EmailServiceProvider.php'));

class InboundProviderRegistry {

    /** @var array<string, string>|null Cached key => class map. */
    private static $providers = null;

    /**
     * Return discovered providers as [key => class].
     */
    public static function all(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }

        // Source 1: core includes/email_providers/ — same path EmailSender uses.
        $dirs = [PathHelper::getIncludePath('includes/email_providers/')];
        $plugin_dir = PathHelper::getIncludePath('plugins/mailbox/includes/inbound_providers/');
        if (is_dir($plugin_dir)) {
            $dirs[] = $plugin_dir;
        }

        foreach ($dirs as $dir) {
            foreach (glob($dir . '*.php') as $file) {
                require_once($file);
            }
        }

        self::$providers = [];
        foreach (get_declared_classes() as $class) {
            if (in_array('InboundEmailProvider', class_implements($class) ?: [], true)) {
                $key = $class::getKey();
                if ($key !== '') {
                    self::$providers[$key] = $class;
                }
            }
        }

        return self::$providers;
    }

    /**
     * Look up a provider class by key.
     */
    public static function get(string $key): ?string {
        $providers = self::all();
        return $providers[$key] ?? null;
    }

    /**
     * Resolve the active inbound provider class via the
     * mailbox_provider setting, falling back to PostfixProvider when
     * the setting is empty or names an unknown provider.
     */
    public static function active(): string {
        $settings = Globalvars::get_instance();
        $key = trim((string)$settings->get_setting('mailbox_provider'));
        if ($key === '') {
            $key = 'postfix';
        }
        $class = self::get($key);
        if ($class !== null) {
            return $class;
        }
        $fallback = self::get('postfix');
        if ($fallback !== null) {
            return $fallback;
        }
        // Last-ditch: any provider at all.
        $all = self::all();
        if (!empty($all)) {
            return reset($all);
        }
        throw new RuntimeException('No inbound email providers discovered.');
    }

    /** Test/dev helper. Forget cached discovery so a re-scan picks up new providers. */
    public static function reset(): void {
        self::$providers = null;
    }
}
