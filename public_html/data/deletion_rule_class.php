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

    /**
     * Scan all loaded models and register their foreign key actions
     * This truncates and rebuilds the entire rules table to ensure consistency
     */
    public static function registerAllModels() {
        $db = DbConnector::get_instance()->get_db_link();

        // Truncate the table to rebuild from scratch
        // This ensures no stale rules persist
        $db->exec("TRUNCATE TABLE del_deletion_rules");

        // Clear the cache since we're rebuilding
        self::$rules_cache = [];

        // Now register all model rules
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, 'SystemBase')) {
                self::registerModelRules($class);
            }
        }
    }

    /**
     * Register a specific model's foreign key actions
     * Auto-detects foreign keys from field_specifications and applies cascade as default
     */
    public static function registerModelRules($model_class) {
        $reflection = new ReflectionClass($model_class);
        $table = $reflection->getStaticPropertyValue('tablename');

        // Get field specifications to auto-detect foreign keys
        $field_specs = $reflection->getStaticPropertyValue('field_specifications', []);

        // Get any explicit foreign key actions
        try {
            $fk_actions = $reflection->getStaticPropertyValue('foreign_key_actions', []);
        } catch (ReflectionException $e) {
            // Property doesn't exist, which is fine - most models won't have it
            $fk_actions = [];
        }

        // Auto-detect foreign keys from field names
        foreach ($field_specs as $column => $spec) {
            // Pattern: xxx_yyy_entity_id indicates foreign key
            if (preg_match('/^[a-z]+_[a-z]+_[a-z]+_id$/i', $column)) {
                $source_table = self::getSourceTableFromColumn($column);

                // Skip if we can't determine the source table
                if ($source_table === null) {
                    continue;
                }

                // Determine action: explicit override or default cascade
                if (isset($fk_actions[$column])) {
                    // Use explicitly defined action
                    $rule = $fk_actions[$column];
                } else {
                    // Default to cascade for auto-detected foreign keys
                    $rule = ['action' => 'cascade'];
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
        }
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
     * Derive source table name from foreign key column name
     * e.g., ord_usr_user_id -> usr_users
     *      evt_usr_user_id -> usr_users
     *      cdd_pro_product_id -> pro_products
     */
    private static function getSourceTableFromColumn($column) {
        // Pattern: xxx_yyy_entity_id -> yyy_entitys
        // Extract the middle prefix (yyy) and entity name
        if (preg_match('/^[a-z]+_([a-z]+)_([a-z]+)_id$/i', $column, $matches)) {
            $prefix = $matches[1];  // e.g., 'usr', 'pro', 'evt'
            $entity = $matches[2];  // e.g., 'user', 'product', 'event'

            // Build table name with pluralized entity
            return $prefix . '_' . self::pluralizeEntity($entity);
        }
        return null;
    }

    /**
     * Simple pluralization for entity names
     * Handles common cases in the system
     */
    private static function pluralizeEntity($entity) {
        // Special cases
        $irregular = [
            'address' => 'addresses',
            'category' => 'categories',
            'entry' => 'entries',
            'query' => 'queries',
        ];

        if (isset($irregular[$entity])) {
            return $irregular[$entity];
        }

        // Standard rules
        if (substr($entity, -1) === 'y') {
            // entity -> entities
            return substr($entity, 0, -1) . 'ies';
        } elseif (substr($entity, -1) === 's' || substr($entity, -2) === 'ss') {
            // address -> addresses, class -> classes
            return $entity . 'es';
        } else {
            // Default: just add 's'
            // user -> users, product -> products, event -> events
            return $entity . 's';
        }
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
