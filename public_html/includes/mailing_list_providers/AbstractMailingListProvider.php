<?php
require_once(PathHelper::getIncludePath('includes/mailing_list_providers/MailingListProvider.php'));
require_once(PathHelper::getIncludePath('includes/mailing_list_providers/MailingListProviderException.php'));

/**
 * AbstractMailingListProvider
 *
 * Provider classes extend this base instead of implementing MailingListProvider
 * directly. The base provides default throwing implementations for non-universal
 * methods, keeping forward additions non-breaking for existing provider classes.
 *
 * Universal methods (every provider can satisfy them) live on the interface and
 * remain abstract here — concrete providers must implement them.
 *
 * Non-universal methods (sequences, broadcasts, list stats, etc.) get default
 * throwing bodies here as they are added to the system. Consumers of those
 * methods wrap calls in try/catch BadMethodCallException.
 */
abstract class AbstractMailingListProvider implements MailingListProvider {
    /**
     * Get available lists/audiences from the remote provider.
     * Returns [['id' => string, 'name' => string, 'member_count' => int]]
     *
     * Default: throws \BadMethodCallException. Providers that support list
     * enumeration override this method. Consumers (e.g. a future list-picker
     * UI) wrap calls in try/catch to handle providers that don't override.
     */
    public function getLists(): array {
        throw new \BadMethodCallException(
            static::class . ' has not implemented getLists()'
        );
    }
}
