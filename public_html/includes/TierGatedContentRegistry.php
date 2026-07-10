<?php
/**
 * TierGatedContentRegistry
 *
 * The subscription-tier admin shows, per tier, how many pieces of each content
 * type are gated to require at least that tier. Which content types exist is not
 * fixed: core ships posts/pages/files/videos, the store adds products, and
 * event_manager adds events. Each provider declares one row — a human label plus
 * the table and the two columns the summary counts against — and the summary
 * iterates the registry instead of a hard-coded table map.
 *
 * Fail soft: a content type whose plugin is inactive simply isn't registered and
 * drops out of the summary; nothing errors on a missing table.
 *
 * @version 1.0.0
 */
class TierGatedContentRegistry {

    /** @var array<string,array{label:string,table:string,level_column:string,delete_column:string}> keyed by table */
    private static $types = [];

    /**
     * Register a gated content type. Idempotent (last-wins by table name).
     */
    public static function register(string $label, string $table, string $level_column, string $delete_column): void {
        self::$types[$table] = [
            'label'         => $label,
            'table'         => $table,
            'level_column'  => $level_column,
            'delete_column' => $delete_column,
        ];
    }

    /**
     * All registered content types, in registration order.
     *
     * @return array<int,array{label:string,table:string,level_column:string,delete_column:string}>
     */
    public static function all(): array {
        return array_values(self::$types);
    }

    /** Register the core-owned gated content types. */
    public static function registerCoreDefaults(): void {
        self::register('Posts', 'pst_posts', 'pst_tier_min_level', 'pst_delete_time');
        self::register('Pages', 'pag_pages', 'pag_tier_min_level', 'pag_delete_time');
        self::register('Files', 'fil_files', 'fil_tier_min_level', 'fil_delete_time');
        self::register('Videos', 'vid_videos', 'vid_tier_min_level', 'vid_delete_time');
        // Events register from event_manager's serve.php; Products from the store's.
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$types = [];
    }
}

// Register core-owned content types when this file is loaded.
TierGatedContentRegistry::registerCoreDefaults();
