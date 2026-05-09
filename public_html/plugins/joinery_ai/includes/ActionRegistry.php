<?php
/**
 * Discovers AI-callable actions — logic files that declare a
 * {basename}_logic_descriptor() function. Each descriptor declares
 *   {description, requires_session, mutates, input}.
 *
 * The registry scans logic/*_logic.php in the core directory and any
 * plugin's logic/ directory. Files are required up front because the
 * descriptor function only exists once the file is loaded.
 *
 * Used by:
 *   - DescribeActionsTool — emits descriptors for actions in the recipe's
 *     rcp_allowed_actions allow-list.
 *   - InvokeActionTool — looks up the action's descriptor and _logic()
 *     function, validates input, runs the call.
 *   - admin/edit.php — renders the action checkbox list with mutates
 *     badges and "wrapped-by" cross-references against models.
 */
class ActionRegistry {

    /** @var array<string, array>|null  action name => metadata */
    private static $actions = null;

    /**
     * Return [action_name => metadata] for every discovered action.
     * Metadata: { name, descriptor, logic_function }.
     */
    public static function all(): array {
        if (self::$actions === null) self::scan();
        return self::$actions;
    }

    public static function get(string $name): ?array {
        $map = self::all();
        return $map[$name] ?? null;
    }

    /**
     * Names of actions whose descriptor declares mutates=true. Used by the
     * save-time taint gate to identify the write-tool surface and by the
     * edit page to badge action checkboxes.
     */
    public static function mutatingActionNames(): array {
        $out = [];
        foreach (self::all() as $name => $info) {
            if (!empty($info['descriptor']['mutates'])) $out[] = $name;
        }
        return $out;
    }

    private static function scan(): void {
        self::$actions = [];

        self::scanDir(PathHelper::getIncludePath('logic'));

        $plugins_dir = PathHelper::getIncludePath('plugins');
        if (is_dir($plugins_dir)) {
            foreach (scandir($plugins_dir) as $plugin) {
                if ($plugin === '.' || $plugin === '..') continue;
                self::scanDir($plugins_dir . '/' . $plugin . '/logic');
            }
        }
    }

    private static function scanDir(string $dir): void {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*_logic.php') as $file) {
            $basename = basename($file, '.php');           // e.g. "register_logic"
            $action_name = substr($basename, 0, -6);       // "register"
            $descriptor_fn = $basename . '_descriptor';
            $logic_fn = $basename;

            // Cheap pre-load probe — only require files that actually declare
            // a descriptor function. Avoids polluting global state for files
            // that don't expose to the AI surface.
            $contents = @file_get_contents($file);
            if ($contents === false) continue;
            if (!preg_match('/function\s+' . preg_quote($descriptor_fn, '/') . '\s*\(/', $contents)) {
                continue;
            }

            require_once($file);
            if (!function_exists($descriptor_fn) || !function_exists($logic_fn)) continue;

            try {
                $descriptor = call_user_func($descriptor_fn);
            } catch (Throwable $e) {
                error_log("[joinery_ai] $descriptor_fn() threw: " . $e->getMessage());
                continue;
            }
            if (!is_array($descriptor)) continue;

            self::$actions[$action_name] = [
                'name'           => $action_name,
                'descriptor'     => $descriptor,
                'logic_function' => $logic_fn,
            ];
        }
    }

}
