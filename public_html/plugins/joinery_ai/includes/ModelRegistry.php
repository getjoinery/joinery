<?php
/**
 * Discovers data-model classes that have opted into AI reads.
 *
 * A model opts in by declaring `public static $ai_readable = true` on a
 * SystemBase-derived class. The registry scans `data/*_class.php` and every
 * `plugins/{plugin}/data/*_class.php` once per request, requires each file,
 * and indexes opted-in classes by their class name.
 *
 * Companion to ModelSchemaBuilder (turns the spec into JSON schema for the
 * describe_models tool) and ModelQueryExecutor (the read-side security
 * boundary for query_model).
 */
class ModelRegistry {

    /** @var array<string, array>|null  class name => metadata bag */
    private static $models = null;

    /**
     * Return [class_name => metadata] for every model with $ai_readable = true.
     * Metadata: { class, description, excluded_fields }.
     */
    public static function all(): array {
        if (self::$models === null) self::scan();
        return self::$models;
    }

    public static function get(string $class): ?array {
        $map = self::all();
        return $map[$class] ?? null;
    }

    private static function scan(): void {
        self::$models = [];

        // Phase 1: require every *_class.php under data/ and plugin data/.
        // We don't track per-file class diffs because cross-model
        // require_once side-effects mean a model declared inside its own
        // file might appear in the diff of an *earlier* file's scan and
        // never in its own — leaving it uncatalogued. Easier to require
        // everything first and inspect declared classes once.
        self::requireAll(PathHelper::getIncludePath('data'));

        $plugins_dir = PathHelper::getIncludePath('plugins');
        if (is_dir($plugins_dir)) {
            foreach (scandir($plugins_dir) as $plugin) {
                if ($plugin === '.' || $plugin === '..') continue;
                self::requireAll($plugins_dir . '/' . $plugin . '/data');
            }
        }

        // Phase 2: index every SystemBase subclass with $ai_readable = true.
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, 'SystemBase')) continue;
            if (!self::isOptedIn($class)) continue;
            self::$models[$class] = [
                'class'           => $class,
                'description'     => self::staticOr($class, 'ai_description', ''),
                'excluded_fields' => self::staticOr($class, 'ai_excluded_fields', []),
            ];
        }
    }

    private static function requireAll(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*_class.php') as $file) {
            require_once($file);
        }
    }

    private static function isOptedIn(string $class): bool {
        $rc = new ReflectionClass($class);
        if (!$rc->hasProperty('ai_readable')) return false;
        $prop = $rc->getProperty('ai_readable');
        if (!$prop->isStatic() || !$prop->isPublic()) return false;
        return $class::$ai_readable === true;
    }

    private static function staticOr(string $class, string $prop_name, $default) {
        $rc = new ReflectionClass($class);
        if (!$rc->hasProperty($prop_name)) return $default;
        $prop = $rc->getProperty($prop_name);
        if (!$prop->isStatic() || !$prop->isPublic()) return $default;
        return $class::${$prop_name};
    }

}
