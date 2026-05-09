<?php
/**
 * Discovers data-model classes that have opted into AI reads (and
 * optionally writes). A model opts in by declaring
 *   public static $ai_readable = true;
 * on a SystemBase-derived class. Writes are opt-in via
 *   public static $ai_writable_fields = ['col1', 'col2'];  // non-empty
 *
 * Two configuration rules are enforced at scan time:
 *   1. $ai_writable_fields requires $ai_readable = true. Writable-without-
 *      readable is structurally unusable: the LLM can't construct correct
 *      writes without seeing the schema.
 *   2. $ai_writable_fields ∩ $ai_excluded_fields are stripped from the
 *      writable surface. Excluded fields are hands-off both directions.
 *
 * Violations produce error_log entries and are surfaced via warnings()
 * for the recipe edit page.
 */
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ModelSchemaBuilder.php'));

class ModelRegistry {

    /** @var array<string, array>|null  class name => metadata bag */
    private static $models = null;

    /** @var array<int, array>  scan-time warnings, surfaced via warnings() */
    private static $warnings = [];

    /**
     * Return [class_name => metadata] for every model with $ai_readable = true.
     * Metadata: { class, description, excluded_fields, untrusted_fields,
     * writable_fields }. writable_fields is the post-strip allowlist (or []
     * if the model isn't write-opted-in).
     */
    public static function all(): array {
        if (self::$models === null) self::scan();
        return self::$models;
    }

    public static function get(string $class): ?array {
        $map = self::all();
        return $map[$class] ?? null;
    }

    /**
     * List of scan-time warnings — model misconfigurations that the edit
     * page surfaces in a "Models with configuration issues" alert. Each
     * entry: { class, kind, message }.
     */
    public static function warnings(): array {
        if (self::$models === null) self::scan();
        return self::$warnings;
    }

    /**
     * Names of models declaring a non-empty $ai_writable_fields. Subset of
     * all() — read-only models aren't here.
     */
    public static function writableModels(): array {
        $out = [];
        foreach (self::all() as $name => $info) {
            if (!empty($info['writable_fields'])) $out[] = $name;
        }
        return $out;
    }

    private static function scan(): void {
        self::$models = [];
        self::$warnings = [];

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

        // Phase 2: index every SystemBase subclass. Catalog reads via
        // $ai_readable, but also notice misconfigured write-only models so
        // we can emit warnings.
        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, 'SystemBase')) continue;
            self::indexClass($class);
        }
    }

    private static function indexClass(string $class): void {
        $is_readable = self::staticBool($class, 'ai_readable');
        $writable_decl = self::staticOr($class, 'ai_writable_fields', []);
        if (!is_array($writable_decl)) $writable_decl = [];

        // Writable-without-readable: warn but don't register either surface.
        if (!empty($writable_decl) && !$is_readable) {
            self::$warnings[] = [
                'class'   => $class,
                'kind'    => 'writable_requires_readable',
                'message' => '$ai_writable_fields is set but $ai_readable is not true. '
                          . 'Write surface not registered. Set $ai_readable = true to enable.',
            ];
            error_log("[joinery_ai] $class: \$ai_writable_fields is set but \$ai_readable "
                    . "is not true. Write surface not registered. Set \$ai_readable = true "
                    . "to enable.");
            return;
        }

        if (!$is_readable) return;

        $excluded = self::staticOr($class, 'ai_excluded_fields', []);
        if (!is_array($excluded)) $excluded = [];

        // Strip writable ∩ excluded; warn on any conflict.
        $field_specs = isset($class::$field_specifications) ? $class::$field_specifications : [];
        $known_fields = array_keys($field_specs);
        $writable_clean = [];
        $conflicts = [];
        foreach ($writable_decl as $f) {
            if (!is_string($f) || $f === '') continue;
            if (!in_array($f, $known_fields, true)) {
                self::$warnings[] = [
                    'class'   => $class,
                    'kind'    => 'writable_unknown_field',
                    'message' => "$f appears in \$ai_writable_fields but is not a defined "
                              . "field on the model. Removed from the writable surface.",
                ];
                continue;
            }
            if (in_array($f, $excluded, true)) {
                $conflicts[] = $f;
                continue;
            }
            // Auto-block regex strips obvious credential names defensively.
            if (preg_match(ModelSchemaBuilder::AUTO_BLOCK_PATTERN, $f)) {
                self::$warnings[] = [
                    'class'   => $class,
                    'kind'    => 'writable_auto_blocked',
                    'message' => "$f matches the auto-block regex (password/secret/key/"
                              . "token/hash). Stripped from the writable surface.",
                ];
                continue;
            }
            $writable_clean[] = $f;
        }
        if (!empty($conflicts)) {
            $list = implode(', ', $conflicts);
            self::$warnings[] = [
                'class'   => $class,
                'kind'    => 'writable_excluded_intersect',
                'message' => "$list appears in both \$ai_writable_fields and "
                          . "\$ai_excluded_fields. Field(s) stripped from the writable "
                          . "surface; remove from one list to silence this warning.",
            ];
            error_log("[joinery_ai] $class: $list appears in both \$ai_writable_fields "
                    . "and \$ai_excluded_fields. Field(s) will be stripped from writes; "
                    . "remove from one list to silence this warning.");
        }

        $untrusted = self::staticOr($class, 'ai_untrusted_fields', []);
        if (!is_array($untrusted)) $untrusted = [];

        self::$models[$class] = [
            'class'            => $class,
            'description'      => self::staticOr($class, 'ai_description', ''),
            'excluded_fields'  => $excluded,
            'untrusted_fields' => $untrusted,
            'writable_fields'  => $writable_clean,
        ];
    }

    private static function requireAll(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*_class.php') as $file) {
            require_once($file);
        }
    }

    private static function staticBool(string $class, string $prop_name): bool {
        $rc = new ReflectionClass($class);
        if (!$rc->hasProperty($prop_name)) return false;
        $prop = $rc->getProperty($prop_name);
        if (!$prop->isStatic() || !$prop->isPublic()) return false;
        return $class::${$prop_name} === true;
    }

    private static function staticOr(string $class, string $prop_name, $default) {
        $rc = new ReflectionClass($class);
        if (!$rc->hasProperty($prop_name)) return $default;
        $prop = $rc->getProperty($prop_name);
        if (!$prop->isStatic() || !$prop->isPublic()) return $default;
        return $class::${$prop_name};
    }

}
