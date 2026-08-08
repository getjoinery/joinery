<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));

/**
 * Discovers and looks up pipeline jobs.
 *
 * Jobs live in `plugins/{plugin}/pipeline_jobs/` (any plugin — not just
 * joinery_ai). On first use, the registry scans every plugin's
 * pipeline_jobs/ directory, requires each PHP file, and indexes the
 * discovered classes by their declared id(). Cache lives in a static so
 * subsequent lookups in the same request are free; the registry rebuilds on
 * the next request. Same shape as RecipeToolRegistry, for the sibling
 * pipeline_jobs/ convention.
 */
class PipelineJobRegistry {

    /** @var array<string, string>|null  job id => class name */
    private static $jobs = null;

    /**
     * Return [job_id => class_name] for every discovered job.
     */
    public static function all(): array {
        if (self::$jobs === null) self::scan();
        return self::$jobs;
    }

    /**
     * Get a job implementation by id. Returns null if unknown.
     */
    public static function get(string $id): ?PipelineJobInterface {
        $map = self::all();
        if (!isset($map[$id])) return null;
        $class = $map[$id];
        return new $class();
    }

    private static function scan(): void {
        self::$jobs = [];

        $plugins_dir = PathHelper::getIncludePath('plugins');
        if (!is_dir($plugins_dir)) return;

        foreach (scandir($plugins_dir) as $plugin) {
            if ($plugin === '.' || $plugin === '..') continue;
            $jobs_dir = $plugins_dir . '/' . $plugin . '/pipeline_jobs';
            if (!is_dir($jobs_dir)) continue;

            foreach (glob($jobs_dir . '/*.php') as $file) {
                $declared_before = get_declared_classes();
                require_once($file);
                $declared_after = get_declared_classes();

                foreach (array_diff($declared_after, $declared_before) as $class) {
                    if (!is_subclass_of($class, 'PipelineJobInterface')) continue;
                    // A job file may pull in a shared abstract base (e.g.
                    // EmailPipelineJobBase); only concrete jobs register.
                    if (!(new ReflectionClass($class))->isInstantiable()) continue;
                    $id = (new $class())->id();
                    if (isset(self::$jobs[$id])) {
                        // Duplicate — first scan order wins; warn via error log.
                        error_log("[joinery_ai] Duplicate pipeline job id '$id': "
                            . self::$jobs[$id] . " vs $class. Keeping the first.");
                        continue;
                    }
                    self::$jobs[$id] = $class;
                }
            }
        }
    }

}
