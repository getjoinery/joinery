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
        // No core context resolvers. The `event` resolver registers from
        // event_manager's serve.php; with that plugin absent, an event-context
        // message renders as plain "event #id" text.
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$resolvers = [];
    }
}

// Register core-owned resolvers when this file is loaded.
MessageContextRegistry::registerCoreDefaults();
