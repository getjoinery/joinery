<?php
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

/**
 * DeletionRule - Manages foreign key deletion rules for the system
 *
 * This model stores and manages rules for how dependent records should be handled
 * when a parent record is permanently deleted. Rules are auto-registered during
 * database updates by scanning all model classes for foreign key patterns.
 *
 * @version 1.2 - pruneOrphanedRules() also drops a rule whose table is declared by a model
 *   on disk but is not in this database (an inactive or uninstalled plugin's): the engine
 *   counts rows in every rule's table before a delete, and a table that is not there
 *   fails the whole delete. A plugin's rules come back when it activates.
 * @version 1.1 - pluralForms(): the shared-prefix tie-break accepts every plural the
 *   validator's pkey check does (+s, +es, y -> ies, uncountable), from one definition
 */
class DeletionRule extends SystemBase {
    public static $prefix = 'del';
    public static $tablename = 'del_deletion_rules';
    public static $pkey_column = 'del_deletion_rule_id';

    public static $field_specifications = [
        'del_deletion_rule_id' => ['type' => 'int8', 'is_nullable' => false, 'serial' => true],
        'del_source_table' => ['type' => 'varchar(64)', 'is_nullable' => false, 'unique_with' => ['del_target_table', 'del_target_column']],
        'del_target_table' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_target_column' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_action' => ['type' => 'varchar(32)', 'is_nullable' => false],
        'del_action_value' => ['type' => 'text'],
        'del_message' => ['type' => 'text'],
        'del_plugin' => ['type' => 'varchar(64)'],
        'del_create_time' => ['type' => 'timestamp', 'default' => 'NOW()']
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

            // An undeclared relationship registers as 'prevent', never a guessed
            // destructive action. The engine cannot tell ownership from mere
            // reference (both are just an _id column), and guessing 'cascade'
            // here once made deleting a phone number delete the user who
            // referenced it. An undeclared delete path now fails loudly at
            // delete time, naming the model and column to declare.
            $rule = $override ?? [
                'action' => 'prevent',
                'message' => "- no deletion action is declared for this relationship. "
                    . "Add '$column' to $model_class::\$foreign_key_actions.",
            ];

            // A typo here used to register silently and then no-op at delete time,
            // so a misspelled 'prevent' permitted the very deletion it was meant to
            // block. Refuse to register it and tell the developer which name is bad.
            if (!in_array($rule['action'], SystemBase::$valid_deletion_actions, true)) {
                $warnings[] = "$model_class: \$foreign_key_actions['$column'] declares unknown action "
                    . "'{$rule['action']}' - this rule was NOT registered. Valid actions: "
                    . implode(', ', SystemBase::$valid_deletion_actions) . ".";
                continue;
            }

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
                    ORDER BY del_deletion_rule_id";
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
     * e.g. aip_rcr_recipe_run_id (on AipRecipeItemLog, prefix 'aip') -> strip
     * 'aip_' -> 'rcr_recipe_run_id' -> first segment 'rcr' is RecipeRun's registered
     * prefix -> its real table, rcr_recipe_runs.
     *
     * Public because ModelTester's declaration gate must apply the exact same
     * recognition rule - a second implementation would drift.
     *
     * @param string $column The column name being examined
     * @param string|null $own_prefix The declaring model's own $prefix
     * @return string|null The real source table, or null if the column isn't
     *   recognized as a foreign key by convention (never a guess)
     */
    public static function getSourceTableFromColumn($column, $own_prefix) {
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
        $candidates = $registry['prefix_to_tables'][$first_segment] ?? [];
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if (count($candidates) === 0) {
            return null;
        }

        // Two models declare this prefix (none do today; the scaffolder only
        // warns on a taken prefix). The column name embeds the singular entity
        // ({own}_{prefix}_{entity}_id), so match that against the candidate
        // table names instead of taking whichever model was discovered first:
        // a pst_fil_file_id would name fil_files. Resolve only on an
        // exact singular/plural match - a column that matches none of the
        // candidates stays unrecognized rather than guessed, and a declared
        // override for it must name 'source_table'/'source_class'.
        $entity = substr($remainder, 0, strpos($remainder, '_id'));
        $forms = self::pluralForms($entity);
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $forms, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * The table names a singular entity may pluralize to: itself (an
     * uncountable table, bkh_backup_history), +s, +es, and y -> ies. This is
     * the one definition of "the singular in a key and the plural in a table
     * name are the same word" - the model-contract pass in
     * validate_php_file.php checks a primary key against its table with it,
     * and the tie-break above resolves a shared prefix with it. A correct
     * English plural is always accepted; nothing ever derives one.
     *
     * @param string $entity e.g. 'mie_mail_import_entry'
     * @return string[]
     */
    public static function pluralForms($entity) {
        $forms = array($entity, $entity . 's', $entity . 'es');
        if (substr($entity, -1) === 'y') {
            $forms[] = substr($entity, 0, -1) . 'ies';
        }
        return $forms;
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
            $prefix_to_tables = [];
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
                    // Keep every table claiming the prefix. Every prefix has one
                    // owner today; the scaffolder only warns on a taken one, so
                    // getSourceTableFromColumn() still disambiguates a pair.
                    if ($prefix && !in_array($table, $prefix_to_tables[$prefix] ?? [], true)) {
                        $prefix_to_tables[$prefix][] = $table;
                    }
                }
            }

            self::$model_registry = [
                'prefix_to_tables' => $prefix_to_tables,
                'all_tables' => $all_tables,
            ];
        }

        return self::$model_registry;
    }

    /**
     * Delete every registered rule that names a table the engine could not
     * consult: one no model on disk declares (core or plugin, active or not -
     * discovery scans the filesystem), or one that is not in this database
     * (a model of a plugin that is inactive or uninstalled here). Safe to call
     * at any time. permanent_delete() runs a COUNT against every rule's table
     * before it deletes anything, so one rule about an absent table refuses
     * every delete of the source - deleting a file, say - on the whole site.
     * A plugin's rules are registered again when it activates.
     *
     * @return array Human-readable messages describing what was pruned
     */
    public static function pruneOrphanedRules() {
        $known_tables = self::getModelRegistry()['all_tables'];

        $db = DbConnector::get_instance()->get_db_link();
        $stmt = $db->query("SELECT del_deletion_rule_id, del_source_table, del_target_table, del_target_column FROM del_deletion_rules");

        $orphaned = [];
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $absent = self::tablesAbsentFromDatabase(array_unique(array_merge(
            array_column($rows, 'del_source_table'), array_column($rows, 'del_target_table'))));
        foreach ($rows as $row) {
            if (!isset($known_tables[$row['del_source_table']]) || !isset($known_tables[$row['del_target_table']])) {
                $row['why'] = 'no model declares the table';
                $orphaned[] = $row;
            } elseif (isset($absent[$row['del_source_table']]) || isset($absent[$row['del_target_table']])) {
                $row['why'] = 'table is not in this database';
                $orphaned[] = $row;
            }
        }

        if (empty($orphaned)) {
            return [];
        }

        $ids = array_column($orphaned, 'del_deletion_rule_id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete_stmt = $db->prepare("DELETE FROM del_deletion_rules WHERE del_deletion_rule_id IN ($placeholders)");
        $delete_stmt->execute($ids);

        self::$rules_cache = [];

        return array_map(function ($row) {
            return "pruned orphaned deletion rule: {$row['del_source_table']} -> "
                . "{$row['del_target_table']}.{$row['del_target_column']} ({$row['why']})";
        }, $orphaned);
    }

    /**
     * Which of these table names have no table in this database, as a set
     * (name => true). One query, whatever the count.
     */
    public static function tablesAbsentFromDatabase(array $tables) {
        $tables = array_values(array_filter($tables, 'strlen'));
        if (!$tables) {
            return [];
        }
        $db = DbConnector::get_instance()->get_db_link();
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $db->prepare("SELECT t.name FROM unnest(ARRAY[$placeholders]::text[]) AS t(name)
            WHERE to_regclass(t.name) IS NULL");
        $stmt->execute($tables);
        $absent = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $absent[$name] = true;
        }
        return $absent;
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
    public static $table_primary_key = 'del_deletion_rule_id';
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
            $sorts = ['del_deletion_rule_id' => 'ASC'];
        }

        return $this->_get_resultsv2(self::$table_name, $filters, $sorts, $only_count, $debug);
    }
}
?>
