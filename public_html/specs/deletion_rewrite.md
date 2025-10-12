# Deletion System Redesign - Final Architecture

## Overview

This document defines a new deletion architecture that solves the maintenance problems with the current system. The core principles:
1. **Foreign keys are auto-detected** from field naming patterns in `$field_specifications`
2. **Cascade delete is automatic** for all detected foreign keys
3. **Models only declare exceptions** when they need non-cascade behavior (prevent, null, set_value)
4. **Parent models know nothing** about what references them

## The Problem We're Solving

The current system requires parent models (like User) to know about every table that references them. This is:
- **Impossible with plugins** - Core can't know about plugin tables at compile time
- **Maintenance nightmare** - Every new table requires updating the parent model
- **Error prone** - Easy to miss relationships

## The Solution: Inverted Responsibility

### Core Principle

Foreign key relationships are **auto-detected** from field naming patterns during table generation. Models only need to specify **exceptions** to the default cascade behavior. Parent models know nothing about what references them.

**How it works:**
1. During `update_database`, the system scans each model's `$field_specifications`
2. Any field matching the pattern `xxx_yyy_entity_id` is recognized as a foreign key
3. Cascade delete is automatically registered for all detected foreign keys
4. Models only use `$foreign_key_actions` to override the default (prevent, null, or set_value)

**Examples:**
- **User model**: Knows NOTHING about orders, logs, events, or plugin tables
- **Log model**: Says nothing - foreign keys auto-detected, cascade applied automatically
- **Order model**: Only declares "set my user_id to USER_DELETED" (override default cascade)
- **Event model**: Only declares "prevent deletion" (override default cascade)
- **Plugin model**: Declares its own rules without core knowing it exists

### Implementation

#### 1. DeletionRule Model (Data + Business Logic)

```php
// Single class that both defines the table AND manages deletion logic
// This follows the pattern where models contain their own business logic
class DeletionRule extends SystemBase {
    // Table definition
    public static $tablename = 'del_deletion_rules';
    public static $pkey_column = 'del_id';
    public static $field_specifications = [
        'del_id' => ['type' => 'serial', 'is_primary' => true],
        'del_source_table' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_target_table' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_target_column' => ['type' => 'varchar(64)', 'is_nullable' => false],
        'del_action' => ['type' => 'varchar(32)', 'is_nullable' => false],
        'del_action_value' => ['type' => 'text'],
        'del_message' => ['type' => 'text'],
        'del_plugin' => ['type' => 'varchar(64)'],
        'del_created_time' => ['type' => 'timestamp', 'default' => 'NOW()']
    ];

    // Unique constraint on source+target+column combination
    public static $unique_constraints = [
        ['del_source_table', 'del_target_table', 'del_target_column']
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
        $fk_actions = $reflection->getStaticPropertyValue('foreign_key_actions', []);

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
}
```

#### 2. Model Self-Declaration

```php
// User model - completely unaware of what references it
class User extends SystemBase {
    public static $tablename = 'usr_users';
    public static $field_specifications = [
        'usr_user_id' => ['type' => 'serial', 'is_primary' => true],
        'usr_email' => ['type' => 'varchar(255)'],
        // ... other user fields ...
    ];
    // NO deletion rules here! Parent models know nothing about their dependents
}

// Order model - only declares special behavior
class Order extends SystemBase {
    public static $tablename = 'ord_orders';
    public static $field_specifications = [
        'ord_order_id' => ['type' => 'serial', 'is_primary' => true],
        'ord_usr_user_id' => ['type' => 'int4'],  // Foreign key auto-detected!
        // ... other order fields ...
    ];

    // Only specify non-default behavior (cascade would be automatic)
    protected static $foreign_key_actions = [
        'ord_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
    ];
}

// Log tables - NO configuration needed!
class UserActivityLog extends SystemBase {
    public static $tablename = 'ual_user_activity_logs';
    public static $field_specifications = [
        'ual_log_id' => ['type' => 'serial', 'is_primary' => true],
        'ual_usr_user_id' => ['type' => 'int4'],  // Auto-detected, cascade by default
        'ual_action' => ['type' => 'varchar(100)'],
        // ... other log fields ...
    ];
    // NO foreign_key_actions needed - cascade is automatic!
}

// Event model - only specify when preventing deletion
class Event extends SystemBase {
    public static $tablename = 'evt_events';
    public static $field_specifications = [
        'evt_event_id' => ['type' => 'serial', 'is_primary' => true],
        'evt_usr_user_id_owner' => ['type' => 'int4'],  // Foreign key auto-detected
        // ... other event fields ...
    ];

    // Only needed to prevent deletion (override default cascade)
    protected static $foreign_key_actions = [
        'evt_usr_user_id_owner' => ['action' => 'prevent', 'message' => 'User owns events']
    ];
}

// Message model - different actions for multiple FKs
class Message extends SystemBase {
    public static $tablename = 'msg_messages';
    public static $field_specifications = [
        'msg_message_id' => ['type' => 'serial', 'is_primary' => true],
        'msg_usr_user_id_sender' => ['type' => 'int4'],     // Both auto-detected
        'msg_usr_user_id_recipient' => ['type' => 'int4'],  // as foreign keys
        'msg_content' => ['type' => 'text'],
    ];

    // Only specify non-cascade behaviors
    protected static $foreign_key_actions = [
        'msg_usr_user_id_sender' => ['action' => 'set_value', 'value' => User::USER_DELETED],
        'msg_usr_user_id_recipient' => ['action' => 'null']
        // If we wanted cascade for both, we wouldn't need this array at all!
    ];
}

// Plugin model - only declares exceptions to cascade
class CtldDevice extends SystemBase {
    public static $tablename = 'cdd_ctlddevices';
    public static $field_specifications = [
        'cdd_device_id' => ['type' => 'serial', 'is_primary' => true],
        'cdd_usr_user_id' => ['type' => 'int4'],      // Auto-detected
        'cdd_pro_product_id' => ['type' => 'int4'],   // Auto-detected
        // ... other device fields ...
    ];

    // Only specify non-cascade actions
    protected static $foreign_key_actions = [
        'cdd_usr_user_id' => [
            'action' => 'prevent',
            'message' => 'User has Control D devices',
            'plugin' => 'controld'
        ],
        'cdd_pro_product_id' => [
            'action' => 'null',
            'plugin' => 'controld'
        ]
    ];
}
```

#### 3. Updated SystemBase Methods

```php
class SystemBase {
    /**
     * Perform a dry run of deletion to see what would be affected
     * Returns structured array of all actions that would be taken
     */
    public function permanent_delete_dry_run() {
        $db = DbConnector::get_instance()->get_db_link();
        $results = [
            'primary' => [
                'table' => static::$tablename,
                'key' => static::$pkey_column,
                'value' => $this->key,
                'action' => 'delete'
            ],
            'dependencies' => [],
            'total_affected' => 1,  // Start with the primary record
            'can_delete' => true,
            'blocking_reasons' => []
        ];

        // Get all deletion rules for this table from the database
        $sql = "SELECT * FROM del_deletion_rules
                WHERE del_source_table = ?
                ORDER BY del_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([static::$tablename]);

        // Process each dependent relationship
        while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dep_table = $rule['del_target_table'];
            $dep_column = $rule['del_target_column'];

            // Check if records exist
            $count_sql = "SELECT COUNT(*) FROM {$dep_table} WHERE {$dep_column} = ?";
            $count_stmt = $db->prepare($count_sql);
            $count_stmt->execute([$this->key]);
            $count = $count_stmt->fetchColumn();

            if ($count > 0) {
                $dependency = [
                    'table' => $dep_table,
                    'column' => $dep_column,
                    'count' => $count,
                    'action' => $rule['del_action'],
                    'action_value' => $rule['del_action_value'],
                    'message' => $rule['del_message']
                ];

                // Check if this would prevent deletion
                if ($rule['del_action'] === 'prevent') {
                    $results['can_delete'] = false;
                    $results['blocking_reasons'][] = $rule['del_message'] ??
                        "Cannot delete: {$count} record(s) in {$dep_table} depend on this record";
                    $dependency['blocks_deletion'] = true;
                } else {
                    // Add to total affected count
                    if ($rule['del_action'] === 'cascade') {
                        $results['total_affected'] += $count;
                    }
                }

                $results['dependencies'][] = $dependency;
            }
        }

        return $results;
    }

    /**
     * Perform the actual permanent deletion
     */
    public function permanent_delete() {
        $db = DbConnector::get_instance()->get_db_link();
        $db->beginTransaction();

        try {
            // Get all deletion rules for this table from the database
            // This is much more efficient than scanning information_schema
            $sql = "SELECT * FROM del_deletion_rules
                    WHERE del_source_table = ?
                    ORDER BY del_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([static::$tablename]);

            // Process each dependent relationship
            while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dep_table = $rule['del_target_table'];
                $dep_column = $rule['del_target_column'];

                // Check if records exist
                $count_sql = "SELECT COUNT(*) FROM {$dep_table} WHERE {$dep_column} = ?";
                $count_stmt = $db->prepare($count_sql);
                $count_stmt->execute([$this->key]);
                $count = $count_stmt->fetchColumn();

                if ($count > 0) {
                    switch ($rule['del_action']) {
                        case 'prevent':
                            throw new SystemDisplayableError(
                                "Cannot delete: $count records in {$dep_table} column {$dep_column} " .
                                ($rule['del_message'] ?? 'depend on this record')
                            );

                        case 'cascade':
                            // Default action - delete dependent records
                            $del_sql = "DELETE FROM {$dep_table} WHERE {$dep_column} = ?";
                            $del_stmt = $db->prepare($del_sql);
                            $del_stmt->execute([$this->key]);
                            break;

                        case 'null':
                            $null_sql = "UPDATE {$dep_table} SET {$dep_column} = NULL WHERE {$dep_column} = ?";
                            $null_stmt = $db->prepare($null_sql);
                            $null_stmt->execute([$this->key]);
                            break;

                        case 'set_value':
                            $value = $rule['del_action_value'];
                            $set_sql = "UPDATE {$dep_table} SET {$dep_column} = ? WHERE {$dep_column} = ?";
                            $set_stmt = $db->prepare($set_sql);
                            $set_stmt->execute([$value, $this->key]);
                            break;
                    }
                }
            }

            // Delete the main record
            $sql = "DELETE FROM " . static::$tablename . " WHERE " . static::$pkey_column . " = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$this->key]);

            $db->commit();

        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
}
```

### System Integration

```php
// In update_database script - runs automatically when database is updated
class DatabaseUpdater {
    public function update() {
        // ... existing database update logic ...
        // ... table creation happens here first ...

        // After ALL tables are created, register deletion rules
        // This ensures del_deletion_rules table exists before we try to use it
        DeletionRule::registerAllModels();
    }
}

// In PluginHelper - runs automatically when plugins are installed/uninstalled
class PluginHelper {
    public static function installPlugin($plugin_name) {
        // ... existing plugin installation logic ...

        // Register deletion rules for all models (including new plugin models)
        DeletionRule::registerAllModels();
    }

    public static function uninstallPlugin($plugin_name) {
        // Remove plugin's deletion rules
        DeletionRule::removePluginRules($plugin_name);

        // ... existing plugin uninstall logic ...
    }
}

// DeletionRule needs this additional method for plugin cleanup
class DeletionRule extends SystemBase {
    // ... existing code ...

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
```

## Implementation Notes

### Bootstrap Order
The system must run in this order:
1. DatabaseUpdater creates all tables (including `del_deletion_rules`)
2. All model classes are loaded
3. `DeletionRule::registerAllModels()` is called to populate rules
4. Only then can `permanent_delete()` be used

### Foreign Key Detection
- Pattern: `xxx_yyy_entity_id` (e.g., `ord_usr_user_id`, `evt_pro_product_id`)
- Fields not matching this pattern are ignored
- Tables with no matching fields will have no deletion rules (safe to delete)

### Dry Run Implementation
- Uses identical query logic as actual deletion
- No transaction needed since it's read-only
- Returns structured data suitable for UI display
- Can be called multiple times without side effects

## Benefits

1. **Zero Maintenance for Parent Models**
   - User doesn't need to know about orders, logs, events, or plugins
   - New tables can be added without touching existing models

2. **Natural Plugin Support**
   - Plugins declare their own deletion behavior
   - Core never needs to know about plugin tables
   - Works immediately upon plugin activation

3. **Minimal Configuration**
   - Foreign keys auto-detected from field names
   - Cascade is automatic for all detected foreign keys
   - Only specify exceptions (prevent, null, set_value)

4. **Clear Ownership**
   - Each model is responsible only for itself
   - Easy to understand what will happen during deletion

5. **Database Flexibility**
   - Rules stored in database can be queried
   - Foundation for future admin UI
   - Can be modified at runtime if needed

6. **Performance Improvement**
   - No more scanning information_schema on every deletion
   - Direct query to del_deletion_rules table is much faster
   - Especially beneficial when deleting multiple records

7. **Dry Run Capability**
   - Preview exactly what will be affected before deletion
   - Clear visibility in admin confirmation dialogs
   - Prevents accidental data loss
   - Shows blocking reasons upfront

## Actions Supported

- **cascade** (default) - Delete the dependent record
- **prevent** - Block the deletion with a clear error message
- **null** - Set the foreign key to NULL
- **set_value** - Set the foreign key to a specific value (e.g., USER_DELETED)

## Migration Path

### Phase 1: Manual Migration (This Spec)

**Note: This is an immediate migration with no backward compatibility.**

1. Create the DeletionRule model class (table auto-created by update_database)
2. Replace SystemBase::permanent_delete() with new implementation
3. For each model:
   - Remove the model from parent's `$permanent_delete_actions`
   - If the action was 'delete' or cascade - do nothing (auto-detected)
   - If the action was 'prevent', 'null', or set to a value - add to model's `$foreign_key_actions`
4. Run update_database (which automatically calls `DeletionRule::registerAllModels()`)
5. Remove all `$permanent_delete_actions` arrays from parent models

### Phase 2: Automated Migration (Future)

The following will be implemented in Phase 2 to automate the migration process:
- Script to scan all models for existing `$permanent_delete_actions` arrays
- Automatic mapping of rules to appropriate child models
- Automated file editing to add `$foreign_key_actions` to child models
- Validation tool to verify migration completeness
- Bulk testing tool to dry-run all deletions and compare old vs new behavior
- **Handle models with custom permanent_delete() overrides** - Some models may have overridden permanent_delete() with custom logic (e.g., additional cleanup, API calls, cache clearing). Phase 2 must identify these and either:
  - Preserve the custom logic by calling parent::permanent_delete()
  - Convert custom logic to hooks/events
  - Flag for manual review

**Migration Example:**
```php
// BEFORE: In User class
public static $permanent_delete_actions = array(
    'ord_usr_user_id' => User::USER_DELETED,
    'ual_usr_user_id' => 'delete',
    'evt_usr_user_id_owner' => 'prevent'
);

// AFTER:
// User class - remove all $permanent_delete_actions

// Order class - add override
protected static $foreign_key_actions = [
    'ord_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
];

// UserActivityLog class - nothing needed (cascade is automatic)

// Event class - add override
protected static $foreign_key_actions = [
    'evt_usr_user_id_owner' => ['action' => 'prevent', 'message' => 'User owns events']
];
```

## Usage Examples

### Dry Run in Admin Delete Confirmation

```php
// In admin_users_permanent_delete.php
$user_id = $_GET['id'];
$user = new User($user_id, TRUE);

// Perform dry run to see what would be affected
$dry_run = $user->permanent_delete_dry_run();

if (!$dry_run['can_delete']) {
    // Show error - deletion is prevented
    echo "<div class='alert alert-danger'>";
    echo "<h4>Cannot Delete User</h4>";
    foreach ($dry_run['blocking_reasons'] as $reason) {
        echo "<p>• " . htmlspecialchars($reason) . "</p>";
    }
    echo "</div>";
    exit;
}

// Show confirmation with details
echo "<div class='delete-confirmation'>";
echo "<h3>Confirm Deletion</h3>";
echo "<p>Are you sure you want to permanently delete this user?</p>";

echo "<h4>This will affect:</h4>";
echo "<ul>";
echo "<li><strong>Primary record:</strong> User #{$user_id} will be deleted</li>";

foreach ($dry_run['dependencies'] as $dep) {
    $action_desc = '';
    switch ($dep['action']) {
        case 'cascade':
            $action_desc = "will be deleted";
            break;
        case 'null':
            $action_desc = "will have their reference set to NULL";
            break;
        case 'set_value':
            $action_desc = "will have their reference set to '{$dep['action_value']}'";
            break;
    }
    echo "<li><strong>{$dep['count']}</strong> record(s) in <code>{$dep['table']}</code> {$action_desc}</li>";
}
echo "</ul>";

echo "<p class='text-danger'><strong>Total records affected: {$dry_run['total_affected']}</strong></p>";

echo "<form method='POST'>";
echo "<button type='submit' name='confirm' value='1' class='btn btn-danger'>Yes, Delete Permanently</button>";
echo "<a href='admin_users.php' class='btn btn-default'>Cancel</a>";
echo "</form>";
echo "</div>";

// If confirmed, perform the actual deletion
if ($_POST['confirm'] == '1') {
    try {
        $user->permanent_delete();
        header("Location: admin_users.php?deleted=1");
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}
```

### Dry Run Result Structure

```php
// Example dry run result for deleting a user
[
    'primary' => [
        'table' => 'usr_users',
        'key' => 'usr_user_id',
        'value' => 123,
        'action' => 'delete'
    ],
    'dependencies' => [
        [
            'table' => 'ord_orders',
            'column' => 'ord_usr_user_id',
            'count' => 5,
            'action' => 'set_value',
            'action_value' => '-1',  // USER_DELETED constant
            'message' => null
        ],
        [
            'table' => 'ual_user_activity_logs',
            'column' => 'ual_usr_user_id',
            'count' => 147,
            'action' => 'cascade',
            'action_value' => null,
            'message' => null
        ],
        [
            'table' => 'evt_events',
            'column' => 'evt_usr_user_id_owner',
            'count' => 2,
            'action' => 'prevent',
            'action_value' => null,
            'message' => 'User owns events',
            'blocks_deletion' => true
        ]
    ],
    'total_affected' => 155,  // 1 user + 5 orders (modified) + 147 logs (deleted) + 2 events (blocked)
    'can_delete' => false,  // Because events prevent deletion
    'blocking_reasons' => [
        'User owns events'
    ]
]
```

## Model Examples

### Typical Log Table (No Configuration Needed)
```php
class UserActivityLog extends SystemBase {
    public static $field_specifications = [
        'ual_usr_user_id' => ['type' => 'int4'],  // Auto-detected as FK
        // ... other fields ...
    ];
    // NO foreign_key_actions needed - cascade is automatic!
}
```

### Order Table (Special Behavior)
```php
class Order extends SystemBase {
    protected static $foreign_key_actions = [
        'ord_usr_user_id' => [
            'action' => 'set_value',
            'value' => User::USER_DELETED
        ]
    ];
}
```

### Plugin Table
```php
class CtldDevice extends SystemBase {
    protected static $foreign_key_actions = [
        'cdd_usr_user_id' => [
            'action' => 'prevent',
            'message' => 'User has Control D devices - deactivate devices first',
            'plugin' => 'controld'  // Important for plugin cleanup
        ]
    ];
}
```

## Conclusion

This architecture completely solves the maintenance problem through auto-detection and inverted responsibility:

1. **Zero configuration for most models** - Foreign keys are auto-detected, cascade is automatic
2. **Parent models stay clean** - They never know about their dependents
3. **Plugin support is natural** - Plugins work exactly like core models
4. **Exceptions are explicit** - Only non-cascade behaviors need configuration
5. **Performance is improved** - No more schema scanning on every delete
6. **Safe deletion workflow** - Dry run shows exactly what will happen before committing

The result is a system that "just works" for 90% of cases while remaining flexible for special requirements and providing full visibility into deletion consequences.