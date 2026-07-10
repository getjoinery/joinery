<?php
/**
 * EntityPhotoRegistry
 *
 * Entity photos attach to many kinds of records (a user, a post, a product, an
 * event…). When the first photo is uploaded for an entity the uploader needs to
 * mark it as that entity's primary photo, which means loading the entity's model
 * class. The type→class map is no longer hard-coded: core registers its own
 * types, the store registers `product`, event_manager registers `event`/`location`.
 * Unregistered types are simply not synced (the photo row is still created).
 *
 * @version 1.0.0
 */
class EntityPhotoRegistry {

    /** @var array<string,array{class:string,file:string}> type => model class + file */
    private static $entities = [];

    /**
     * Register an entity type's model class + the file that defines it.
     * Idempotent (last-wins by type).
     */
    public static function register(string $type, string $class, string $file): void {
        self::$entities[$type] = ['class' => $class, 'file' => $file];
    }

    /**
     * Return ['class','file'] for a type, or null if unregistered.
     */
    public static function get(string $type): ?array {
        return self::$entities[$type] ?? null;
    }

    /** Whether a type is registered. */
    public static function has(string $type): bool {
        return isset(self::$entities[$type]);
    }

    /** Register the core-owned entity types. */
    public static function registerCoreDefaults(): void {
        self::register('user', 'User', 'data/users_class.php');
        self::register('mailing_list', 'MailingList', 'data/mailing_lists_class.php');
        self::register('post', 'Post', 'data/posts_class.php');
        self::register('page', 'Page', 'data/pages_class.php');
        // event + location register from event_manager's serve.php; product
        // from the store's.
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$entities = [];
    }
}

// Register core-owned entity types when this file is loaded.
EntityPhotoRegistry::registerCoreDefaults();
