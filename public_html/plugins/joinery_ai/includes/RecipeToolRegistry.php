<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));

/**
 * Discovers and looks up recipe tools.
 *
 * Tools live in `plugins/{plugin}/recipe_tools/` (any plugin — not just
 * joinery_ai). On first use, the registry scans every plugin's recipe_tools/
 * directory, requires each PHP file, and indexes the discovered classes by
 * their declared name(). Cache lives in a static so subsequent lookups in the
 * same request are free; the registry rebuilds on the next request.
 *
 * Caching the registry across requests (APCu, file cache) is deferred per spec
 * — fine at v1 plugin counts; revisit when measurable.
 */
class RecipeToolRegistry {

    /** @var array<string, string>|null  tool name => class name */
    private static $tools = null;

    /**
     * Return [tool_name => class_name] for every discovered tool.
     */
    public static function all(): array {
        if (self::$tools === null) self::scan();
        return self::$tools;
    }

    /**
     * Get a tool implementation by name. Returns null if unknown.
     */
    public static function get(string $name): ?RecipeToolInterface {
        $map = self::all();
        if (!isset($map[$name])) return null;
        $class = $map[$name];
        return new $class();
    }

    /**
     * Build the `tools` array for the Anthropic Messages API for a given list
     * of allowed tool names. Tools the runtime doesn't know are silently
     * skipped (the runner logs a warning to the trace).
     *
     * Schema shape per Anthropic API: { name, description, input_schema }.
     * camelCase `inputSchema` is for the official PHP SDK only — raw HTTP wants
     * snake_case.
     */
    public static function schemasFor(array $allowed_names): array {
        $map = self::all();
        $schemas = [];
        foreach ($allowed_names as $name) {
            if (!isset($map[$name])) continue;
            $class = $map[$name];
            $schemas[] = [
                'name'         => $class::name(),
                'description'  => $class::description(),
                'input_schema' => $class::inputSchema(),
            ];
        }
        return $schemas;
    }

    /**
     * Names of tools whose classes were not found at scan time. Useful for
     * the runner to surface in the trace as a warning when a recipe lists
     * an unknown tool.
     */
    public static function unknown(array $requested): array {
        $map = self::all();
        return array_values(array_diff($requested, array_keys($map)));
    }

    private static function scan(): void {
        self::$tools = [];

        $plugins_dir = PathHelper::getIncludePath('plugins');
        if (!is_dir($plugins_dir)) return;

        foreach (scandir($plugins_dir) as $plugin) {
            if ($plugin === '.' || $plugin === '..') continue;
            $tools_dir = $plugins_dir . '/' . $plugin . '/recipe_tools';
            if (!is_dir($tools_dir)) continue;

            foreach (glob($tools_dir . '/*.php') as $file) {
                $declared_before = get_declared_classes();
                require_once($file);
                $declared_after = get_declared_classes();

                foreach (array_diff($declared_after, $declared_before) as $class) {
                    if (!is_subclass_of($class, 'RecipeToolInterface')) continue;
                    $name = $class::name();
                    if (isset(self::$tools[$name])) {
                        // Duplicate — first scan order wins; warn via error log.
                        error_log("[joinery_ai] Duplicate tool name '$name': "
                            . self::$tools[$name] . " vs $class. Keeping the first.");
                        continue;
                    }
                    self::$tools[$name] = $class;
                }
            }
        }
    }

}
