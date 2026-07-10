<?php
/**
 * MessageContextRegistry
 *
 * A message can be attached to some other entity as its "context" (a generic
 * type + id pair stored on the message row). When admin renders a message it
 * wants to show that context as a friendly label and link — but only the plugin
 * that owns the entity knows how to resolve it. Each owner registers a resolver
 * for its type; with no resolver the context renders as plain "{type} #{id}".
 *
 * @version 1.0.0
 */
class MessageContextRegistry {

    /** @var array<string,callable> type => function(int $id): ?array{label:string,url:?string} */
    private static $resolvers = [];

    /**
     * Register a context resolver for a type. Idempotent (last-wins).
     * Resolver returns ['label' => string, 'url' => ?string] or null if the
     * referenced entity no longer exists.
     */
    public static function register(string $type, callable $resolver): void {
        self::$resolvers[$type] = $resolver;
    }

    /**
     * Resolve a context to ['label','url'], or null when no resolver is
     * registered for the type or the resolver reports the entity is gone.
     */
    public static function resolve(string $type, int $id): ?array {
        if (!isset(self::$resolvers[$type])) {
            return null;
        }
        return (self::$resolvers[$type])($id);
    }

    /** Register core-owned context resolvers. */
    public static function registerCoreDefaults(): void {
        // MOVED-TO-PLUGIN (phase 4): the `event` resolver moves to
        // event_manager serve.php once events are a plugin. Kept here while the
        // events table is still core so message context still resolves.
        self::register('event', function (int $id): ?array {
            require_once(PathHelper::getIncludePath('data/events_class.php'));
            try {
                $event = new Event($id, TRUE);
            } catch (\Throwable $e) {
                return null;
            }
            if (!$event->key) {
                return null;
            }
            return [
                'label' => '(' . $event->key . ') ' . $event->get('evt_name'),
                'url'   => '/admin/admin_event?evt_event_id=' . $event->key,
            ];
        });
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$resolvers = [];
    }
}

// Register core-owned resolvers when this file is loaded.
MessageContextRegistry::registerCoreDefaults();
