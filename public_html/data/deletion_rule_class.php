<?php
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * DeletionRule - Manages foreign key deletion rules for the system
 *
 * This model stores and manages rules for how dependent records should be handled
 * when a parent record is permanently deleted. Rules are auto-registered during
 * database updates by scanning all model classes for foreign key patterns.
 */
class DeletionRule extends SystemBase {
    public static $prefix = 'del';
    public static $tablename = 'del_deletion_rules';
    public static $pkey_column = 'del_id';

    public static $field_specifications = [
        'del_id' => ['type' => 'int8', 'is_nullable' => false, 'serial' => true],
        'del_source_table' => ['type' => 'varchar(64)', 'is_nullable' => false, 'unique_with' => ['del_target_table', 'del_target_column']],
        'del_target_table' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_target_column' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_action' => ['type' => 'varchar(32)', 'is_nullable' => false],
        'del_action_value' => ['type' => 'text'],
        'del_message' => ['type' => 'text'],
        'del_plugin' => ['type' => 'varchar(64)'],
        'del_created_time' => ['type' => 'timestamp', 'default' => 'NOW()']
    ];

    // Cache for loaded rules
    private static $rules_cache = [];

    // Cache for the prefix -> tablename / known-tablename lookups built from
    // every model on disk (core + all plugins, regardless of which models
    // are currently being registered). See getModelRegistry().
    private static $model_registry = null;

    /**
     * Register deletion rules for a set of model classes
     * Discovers and loads model classes, then registers each one incrementally
     *
     * @param array $options Options to pass to discover_model_classes:
     *   - 'include_plugins' => bool - Whether to include plugin models
     *   - 'plugin_filter' => string - Specific plugin name to filter to
     *   - 'verbose' => bool - Show progress output
     * @return array Warning strings for any declared $foreign_key_actions
     *   override that could not be registered (unresolvable column shape and
     *   no 'source_table'/'source_class' override given)
     */
    public static function registerModelsFromDiscovery($options = []) {
        // Use LibraryFunctions to discover and load model classes
        $classes = LibraryFunctions::discover_model_classes(array_merge([
            'require_tablename' => true,
            'require_field_specifications' => true,
        ], $options));

        // Register rules for each discovered model
        $warnings = [];
        foreach ($classes as $class) {
            $warnings = array_merge($warnings, self::registerModelRules($class));
        }
        return $warnings;
    }

    /**
     * Register a specific model's foreign key actions
     * Auto-detects foreign keys from field_specifications and applies cascade as default
     * Incrementally updates only this model's rules without affecting other models
     *
     * @return array Warning strings (see registerModelsFromDiscovery())
     */
    public static function registerModelRules($model_class) {
        $reflection = new ReflectionClass($model_class);
        $table = $reflection->getStaticPropertyValue('tablename');
        $own_prefix = $reflection->hasProperty('prefix') ? $reflection->getStaticPropertyValue('prefix') : null;
        $pkey_column = $reflection->hasProperty('pkey_column') ? $reflection->getStaticPropertyValue('pkey_column') : null;

        $db = DbConnector::get_instance()->get_db_link();

        // Delete existing rules for this target table only
        // This allows us to rebuild rules for one model without affecting others
        $stmt = $db->prepare("DELETE FROM del_deletion_rules WHERE del_target_table = ?");
        $stmt->execute([$table]);

        // Clear cache for any source tables that pointed to this target
        self::$rules_cache = [];

        // Get field specifications to auto-detect foreign keys
        $field_specs = $reflection->getStaticPropertyValue('field_specifications', []);

        // Get any explicit foreign key actions
        try {
            $fk_actions = $reflection->getStaticPropertyValue('foreign_key_actions', []);
        } catch (ReflectionException $e) {
            // Property doesn't exist, which is fine - most models won't have it
            $fk_actions = [];
        }

        $warnings = [];

        foreach ($field_specs as $column => $spec) {
            // The primary key is never itself a foreign key
            if ($pkey_column !== null && $column === $pkey_column) {
                continue;
            }

            $override = $fk_actions[$column] ?? null;
            $source_table = null;

            if ($override !== null && (isset($override['source_table']) || isset($override['source_class']))) {
                // Explicit override always wins over convention.
                if (isset($override['source_table'])) {
                    $source_table = $override['source_table'];
                } else {
                    $source_class = $override['source_class'];
                    $source_table = (class_exists($source_class) && property_exists($source_class, 'tablename'))
                        ? $source_class::$tablename
                        : null;
                }
            } else {
                $source_table = self::getSourceTableFromColumn($column, $own_prefix);
            }

            if ($source_table === null) {
                // A declared override that still couldn't resolve is a
                // configuration bug worth surfacing. An FK-shaped column with
                // no declaration at all is just not a recognized relationship
                // (e.g. a role-named or external-ID column) - nothing to warn about.
                if ($override !== null) {
                    $warnings[] = "$model_class: \$foreign_key_actions['$column'] does not resolve to a known "
                        . "source table by naming convention, and no 'source_table' or 'source_class' override "
                        . "resolved either - this rule was NOT registered.";
                }
                continue;
            }

            // Determine action: explicit override or default cascade
            $rule = $override ?? ['action' => 'cascade'];

            // Store in database
            $deletion_rule = new DeletionRule(NULL);
            $deletion_rule->set('del_source_table', $source_table);
            $deletion_rule->set('del_target_table', $table);
            $deletion_rule->set('del_target_column', $column);
            $deletion_rule->set('del_action', $rule['action']);
            $deletion_rule->set('del_action_value', $rule['value'] ?? null);
            $deletion_rule->set('del_message', $rule['message'] ?? null);
            $deletion_rule->set('del_plugin', $rule['plugin'] ?? null);
            $deletion_rule->save();

            // Clear cache for this source table
            unset(self::$rules_cache[$source_table]);
        }

        return $warnings;
    }

    /**
     * Get the action for a specific foreign key relationship
     * Note: This method is kept for potential future use but is not
     * currently called since permanent_delete() queries the table directly
     */
    public static function getAction($source_table, $target_table, $column) {
        // Load rules from database (cached per request)
        $rules = self::loadRules($source_table);

        // Check for explicit rule
        if (isset($rules[$target_table][$column])) {
            return $rules[$target_table][$column];
        }

        // No default action - only registered relationships are processed
        return null;
    }

    /**
     * Load all deletion rules for a source table from database
     */
    private static function loadRules($source_table) {
        if (!isset(self::$rules_cache[$source_table])) {
            $db = DbConnector::get_instance()->get_db_link();

            $sql = "SELECT * FROM del_deletion_rules
                    WHERE del_source_table = ?
                    ORDER BY del_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([$source_table]);

            self::$rules_cache[$source_table] = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $target = $row['del_target_table'];
                $column = $row['del_target_column'];

                self::$rules_cache[$source_table][$target][$column] = [
                    'action' => $row['del_action'],
                    'value' => $row['del_action_value'],
                    'message' => $row['del_message']
                ];
            }
        }
        return self::$rules_cache[$source_table];
    }

    /**
     * Derive the real source table name from a foreign-key-shaped column name
     * by looking up the declaring model's own table registry - never guessing
     * a pluralized form.
     *
     * e.g. aip_rcr_run_id (on AipRecipeItemLog, prefix 'aip') -> strip
     * 'aip_' -> 'rcr_run_id' -> first segment 'rcr' is RecipeRun's registered
     * prefix -> its real table, rcr_recipe_runs.
     *
     * @param string $column The column name being examined
     * @param string|null $own_prefix The declaring model's own $prefix
     * @return string|null The real source table, or null if the column isn't
     *   recognized as a foreign key by convention (never a guess)
     */
    private static function getSourceTableFromColumn($column, $own_prefix) {
        if (!$own_prefix) {
            return null;
        }

        $own_prefix_str = $own_prefix . '_';
        if (strpos($column, $own_prefix_str) !== 0) {
            return null;
        }

        $remainder = substr($column, strlen($own_prefix_str));
        if (strpos($remainder, '_id') === false) {
            return null;
        }

        $first_segment = strstr($remainder, '_', true);
        if ($first_segment === false || $first_segment === '') {
            return null;
        }

        $registry = self::getModelRegistry();
        return $registry['prefix_to_table'][$first_segment] ?? null;
    }

    /**
     * Build (and cache) the authoritative registry of every model on disk -
     * core and every plugin, regardless of which models are currently being
     * registered - keyed by both $prefix and $tablename.
     *
     * This is intentionally independent of whatever (possibly
     * plugin-filtered) class list the caller of registerModelsFromDiscovery()
     * is working with: a foreign key can target a table declared by a
     * different plugin, or by core, than the model being registered.
     *
     * Mirrors SystemBase::getModelClassForTable()'s caching pattern.
     */
    private static function getModelRegistry() {
        if (self::$model_registry === null) {
            $prefix_to_table = [];
            $all_tables = [];

            $classes = LibraryFunctions::discover_model_classes([
                'require_tablename' => true,
                'require_field_specifications' => true,
                'include_plugins' => true,
            ]);

            foreach ($classes as $class) {
                $reflection = new ReflectionClass($class);
                $table = $reflection->getStaticPropertyValue('tablename');
                $all_tables[$table] = true;

                if ($reflection->hasProperty('prefix')) {
                    $prefix = $reflection->getStaticPropertyValue('prefix');
                    if ($prefix && !isset($prefix_to_table[$prefix])) {
                        $prefix_to_table[$prefix] = $table;
                    }
                }
            }

            self::$model_registry = [
                'prefix_to_table' => $prefix_to_table,
                'all_tables' => $all_tables,
            ];
        }

        return self::$model_registry;
    }

    /**
     * Delete every registered rule whose source or target table matches no
     * currently-loaded model (core or plugin, active or not - discovery scans
     * the filesystem, not activation state). Safe to call at any time: it
     * only ever removes rules that reference a table nothing declares, which
     * can only happen from a stale guess or a renamed/removed table.
     *
     * @return array Human-readable messages describing what was pruned
     */
    public static function pruneOrphanedRules() {
        $known_tables = self::getModelRegistry()['all_tables'];

        $db = DbConnector::get_instance()->get_db_link();
        $stmt = $db->query("SELECT del_id, del_source_table, del_target_table, del_target_column FROM del_deletion_rules");

        $orphaned = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($known_tables[$row['del_source_table']]) || !isset($known_tables[$row['del_target_table']])) {
                $orphaned[] = $row;
            }
        }

        if (empty($orphaned)) {
            return [];
        }

        $ids = array_column($orphaned, 'del_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete_stmt = $db->prepare("DELETE FROM del_deletion_rules WHERE del_id IN ($placeholders)");
        $delete_stmt->execute($ids);

        self::$rules_cache = [];

        return array_map(function ($row) {
            return "pruned orphaned deletion rule: {$row['del_source_table']} -> "
                . "{$row['del_target_table']}.{$row['del_target_column']} (table does not exist)";
        }, $orphaned);
    }

    /**
     * Remove all deletion rules registered by a specific plugin
     */
    public static function removePluginRules($plugin_name) {
        $db = DbConnector::get_instance()->get_db_link();
        $sql = "DELETE FROM del_deletion_rules WHERE del_plugin = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$plugin_name]);

        // Clear cache
        self::$rules_cache = [];
    }
}

/**
 * Multi class for DeletionRule collections
 */
class MultiDeletionRule extends SystemMultiBase {
    public static $table_name = 'del_deletion_rules';
    public static $table_primary_key = 'del_id';
    public static $model_class = 'DeletionRule';

    protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];
        $sorts = [];

        // Handle common filter options
        if (isset($this->options['source_table'])) {
            $filters['del_source_table'] = [$this->options['source_table'], PDO::PARAM_STR];
        }

        if (isset($this->options['target_table'])) {
            $filters['del_target_table'] = [$this->options['target_table'], PDO::PARAM_STR];
        }

        if (isset($this->options['plugin'])) {
            $filters['del_plugin'] = [$this->options['plugin'], PDO::PARAM_STR];
        }

        // Default sort by ID
        if (!empty($this->order_by)) {
            $sorts = $this->order_by;
        } else {
            $sorts = ['del_id' => 'ASC'];
        }

        return $this->_get_resultsv2(self::$table_name, $filters, $sorts, $only_count, $debug);
    }
}
?>
