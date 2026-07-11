# Deletion System Documentation

## Overview

The deletion system manages cascading deletes, foreign key constraints, and referential integrity when records are permanently deleted from the database. It uses a **child-centric, declarative approach** where dependent models declare their own behavior when parent records are deleted.

> **GET-is-read-only:** `soft_delete()` and `permanent_delete()` (like `save()`) refuse to run on a GET request — a GET must not mutate data. A legitimate GET-action delete link must wrap the call in `SystemBase::$allow_get_mutation = true; try { … } finally { SystemBase::$allow_get_mutation = false; }`. See [Logic Architecture — GET-is-read-only invariant](logic_architecture.md#get-is-read-only-invariant-enforced-at-the-write-boundary).

### Key Concepts

- **Child-Centric**: Child models declare how they should be handled when their parent is deleted (not the other way around)
- **Auto-Detection**: Foreign keys are automatically detected from column naming patterns (`xxx_yyy_entity_id`)
- **Incremental Registration**: Deletion rules are registered per-model without affecting other models' rules
- **Default CASCADE**: If no behavior is specified, dependent records are deleted automatically
- **Separation of Concerns**: Core and plugin deletion rules are managed independently

## How It Works

### 1. Foreign Key Auto-Detection

The system detects foreign keys based on column naming, then looks up the
real table — it never guesses a pluralized name:

```
Pattern: {prefix}_{source_prefix}_{...}_id

Examples:
- ord_usr_user_id → references usr_users table
- odi_pro_product_id → references pro_products table
- evt_loc_location_id → references loc_locations table
- aip_rcr_run_id → references rcr_recipe_runs table
- ieg_iea_inbound_email_alias_id → references iea_inbound_email_aliases table
```

For a column on a model, the system:
1. Strips the declaring model's own `{prefix}_`
2. Takes the first segment of what's left (e.g. `usr`, `pro`, `rcr`, `iea`)
3. Looks that segment up in a registry of every loaded model's own `$prefix`
   and real `$tablename` (core and every plugin, built once and cached) — the
   column only counts as a foreign key if the remainder also contains `_id`
   and the first segment is a real, registered model prefix

A column whose first segment isn't a registered model prefix (a role name
like `owner`, a self-reference like `parent`, an external id like
`stripe_customer`) registers nothing — never a wrong guess. Give it an
explicit `source_table` (see below) if it does need to cascade.

### Escape Hatch: Columns That Don't Fit the Convention

Some foreign keys don't have their target's prefix as the first segment after
stripping the owner's own prefix — a role-named column (`rcp_owner_user_id`),
a self-reference (`agf_candidate_for` → another row in the same table), or a
suffixed/non-standard name (`mjb_created_by`). Declare the real table
explicitly in `$foreign_key_actions`:

```php
protected static $foreign_key_actions = [
    'rcp_owner_user_id' => ['action' => 'cascade', 'source_table' => 'usr_users'],
    'agf_candidate_for' => ['action' => 'cascade', 'source_table' => 'agf_agent_files'],
];
```

`source_class` (a model class name) works the same way and is resolved to
that class's `$tablename` at registration time. A declared
`$foreign_key_actions` key that resolves neither by convention nor by an
explicit `source_table`/`source_class` produces a warning during
registration/sync rather than silently registering nothing — see
`maintenance_scripts/dev_tools/validate_php_file.php`'s model-contract pass
for the same check at edit time.

### 2. Deletion Actions

Five actions are available:

| Action | Description | Use Case |
|--------|-------------|----------|
| `cascade` | Delete dependent records via flat SQL | Logs, sessions, leaf data with no children |
| `permanent_delete` | Load each record as a model and call its `permanent_delete()` | Records with custom deletion logic or their own child dependencies |
| `set_value` | Set foreign key to specific value | Set to DELETED_USER sentinel value |
| `null` | Set foreign key to NULL | Optional relationships |
| `prevent` | Block deletion if dependents exist | Critical references that can't be orphaned |

**`cascade` vs `permanent_delete`**: Use `cascade` (the default) for leaf tables that have no children and no custom deletion logic. Use `permanent_delete` when the dependent model has its own `permanent_delete()` override or has child tables that need recursive cleanup. `permanent_delete` is slower (loads each record individually) but enables multi-level cascading.

### 3. Default Behavior

If no `$foreign_key_actions` is specified:
- **Default action**: `cascade` (dependent records are deleted)
- This is safe for most relationships and eliminates configuration for common cases

## Using $foreign_key_actions in Models

### Basic Examples

**Most Common: Set to Deleted User**
```php
class Order extends SystemBase {
    public static $tablename = 'ord_orders';

    protected static $foreign_key_actions = [
        'ord_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
    ];
}
```

**Prevent Deletion**
```php
class OrderItem extends SystemBase {
    public static $tablename = 'odi_order_items';

    protected static $foreign_key_actions = [
        'odi_pro_product_id' => [
            'action' => 'prevent',
            'message' => 'Cannot delete product - order items exist'
        ]
    ];
}
```

**Set to NULL**
```php
class Event extends SystemBase {
    public static $tablename = 'evt_events';

    protected static $foreign_key_actions = [
        'evt_loc_location_id' => ['action' => 'null']
    ];
}
```

**No Configuration Needed (Cascade)**
```php
class UserActivityLog extends SystemBase {
    public static $tablename = 'ual_user_activity_logs';

    // No $foreign_key_actions needed!
    // ual_usr_user_id will automatically cascade delete
}
```

### Multiple Foreign Keys

Handle different foreign keys with different actions:

```php
class Message extends SystemBase {
    public static $tablename = 'msg_messages';

    protected static $foreign_key_actions = [
        'msg_usr_sender_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
        'msg_usr_recipient_id' => ['action' => 'set_value', 'value' => User::USER_DELETED],
        'msg_thread_id' => ['action' => 'cascade']  // Optional: explicit cascade
    ];
}
```

## Deletion Rule Registration Lifecycle

### Core Models

Core model deletion rules are registered by `update_database.php`:

```php
// In /utils/update_database.php (Step 3.5)
DeletionRule::registerModelsFromDiscovery([
    'include_plugins' => false,  // Core only
    'verbose' => $verbose
]);
```

**When**: Every time update_database.php runs

### Plugin Models

Plugin deletion rules are registered/removed through PluginManager lifecycle operations:

1. **Plugin Activate**: `PluginManager::activate()` (`onActivate()`) registers rules for that plugin
2. **Plugin Deactivate**: `PluginManager::deactivate()` (`onDeactivate()`) removes rules for that plugin
3. **Plugin Uninstall**: `PluginManager::uninstall()` removes rules for that plugin

### Manual Registration

To manually register deletion rules for all active plugins:

```php
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
$warnings = PluginHelper::registerAllActiveDeletionRules();
```

Registration is idempotent: re-registering a model replaces that model's
rules atomically (its existing rows are deleted, then the fresh set is
inserted), so running it repeatedly converges rather than accumulating
duplicates.

### Orphaned Rule Pruning

`PluginManager::sync()` calls `DeletionRule::pruneOrphanedRules()` after
registering every active plugin's rules — it deletes any rule whose source or
target table matches no currently-loaded model (core or plugin, active or
not; discovery scans the filesystem, not activation state). This is what
clears out rules left behind by a renamed or removed table, since nothing
else ever revisits an already-registered rule once its owning column is gone.
Safe to call at any time — it only ever removes rules referencing a table
nothing declares.

## How Deletion Works

### Dry Run Preview

Before deleting, check what will be affected:

```php
$user = new User($user_id, TRUE);
$dry_run = $user->permanent_delete_dry_run();

// Returns:
// [
//     'primary' => ['table' => 'usr_users', 'key_column' => 'usr_user_id', 'key' => 123],
//     'dependencies' => [
//         ['table' => 'ord_orders', 'column' => 'ord_usr_user_id', 'count' => 5,
//          'action' => 'set_value', 'action_value' => 3],
//         ['table' => 'ual_user_activity_logs', 'column' => 'ual_usr_user_id',
//          'count' => 150, 'action' => 'cascade']
//     ],
//     'total_affected' => 156,
//     'can_delete' => true,
//     'blocking_reasons' => []
// ]
```

### Permanent Delete

The system handles dependencies automatically:

```php
$user = new User($user_id, TRUE);
$user->authenticate_write(['current_user_id' => $session_id, 'current_user_permission' => 10]);
$user->permanent_delete();

// Automatically:
// 1. Updates orders to set usr_user_id = 3 (DELETED_USER)
// 2. Cascades delete of user activity logs
// 3. Handles all other dependencies per their rules
// 4. Deletes the user record
// 5. Commits transaction
```

### Custom Deletion Logic

Models can override `permanent_delete()` for custom behavior:

```php
class User extends SystemBase {
    public function permanent_delete($debug=false) {
        // Custom pre-deletion work
        $this->remove_from_mailing_lists();
        $this->remove_group_memberships();

        // Call parent to handle dependencies and delete
        parent::permanent_delete($debug);

        return true;
    }
}
```

**Important**: Custom methods should call `parent::permanent_delete()` to use the deletion system.

## Database Structure

Deletion rules are stored in the `del_deletion_rules` table:

```sql
CREATE TABLE del_deletion_rules (
    del_id BIGSERIAL PRIMARY KEY,
    del_source_table VARCHAR(255),      -- Parent table (e.g., 'usr_users')
    del_target_table VARCHAR(255),      -- Child table (e.g., 'ord_orders')
    del_target_column VARCHAR(255),     -- Foreign key column (e.g., 'ord_usr_user_id')
    del_action VARCHAR(50),             -- 'cascade', 'set_value', 'null', 'prevent'
    del_action_value VARCHAR(255),      -- Value for 'set_value' action
    del_message TEXT,                   -- Message for 'prevent' action
    del_plugin VARCHAR(255)             -- Plugin name (NULL for core)
);
```

## Troubleshooting

### Check Current Rules

```sql
-- See all deletion rules
SELECT * FROM del_deletion_rules ORDER BY del_source_table, del_target_table;

-- Rules for a specific table
SELECT * FROM del_deletion_rules WHERE del_source_table = 'usr_users';

-- Plugin rules only
SELECT * FROM del_deletion_rules WHERE del_plugin IS NOT NULL;

-- Count by action type
SELECT del_action, COUNT(*) FROM del_deletion_rules GROUP BY del_action;
```

### Common Issues

**Problem**: Deletion rules not registered for plugin
**Solution**:
- Check if plugin is active (`plg_active = 1`)
- Deactivate and re-activate the plugin — activation re-registers deletion rules
- Or from CLI: `PluginHelper::registerAllActiveDeletionRules()`

**Problem**: Wrong action being applied
**Solution**:
- Check `$foreign_key_actions` in your model class
- Verify column name matches pattern: `{prefix}_{source_prefix}_{entity}_id`
- Re-register rules by syncing or reactivating plugin

**Problem**: "Cannot delete" error
**Solution**:
- Check for `'prevent'` actions in `del_deletion_rules` for that source table
- Use `permanent_delete_dry_run()` to see what's blocking deletion
- Either remove dependencies or change action from 'prevent' to another action

**Problem**: Nested transaction error
**Solution**:
- Already fixed in SystemBase - it checks `inTransaction()` before starting new transaction
- If you see this, you may have custom code starting transactions

### Debug Tools

**See what will be deleted:**
```php
$obj = new SomeModel($id, TRUE);
$preview = $obj->permanent_delete_dry_run();
print_r($preview);
```

**Test in debug mode (no actual deletion):**
```php
$obj->permanent_delete($debug = true);  // Prints SQL without executing
```

## Technical Implementation

### Key Classes

**DeletionRule** (`/data/deletion_rule_class.php`)
- `registerModelsFromDiscovery($options)` - Discover and register model rules; returns warning strings for unresolvable declared overrides
- `registerModelRules($model_class)` - Register one model's rules incrementally; returns the same kind of warnings
- `pruneOrphanedRules()` - Delete rules whose source or target table matches no currently-loaded model

**SystemBase** (`/includes/SystemBase.php`)
- `permanent_delete_dry_run()` - Preview deletion impact
- `permanent_delete($debug)` - Execute deletion with dependency handling

**PluginHelper** (`/includes/PluginHelper.php`)
- `registerAllActiveDeletionRules()` - Register rules for all active plugins
- `removePluginDeletionRules()` - Remove rules for one plugin

### Algorithm

When `permanent_delete()` is called:

1. **Start transaction** (if not already in one)
2. **Query deletion rules** from `del_deletion_rules` for this source table
3. **For each dependent table**:
   - Count how many dependent records exist
   - If count > 0, apply the action:
     - **cascade**: DELETE dependent records
     - **set_value**: UPDATE dependent records to set value
     - **null**: UPDATE dependent records to NULL
     - **prevent**: THROW error and rollback
4. **Delete the primary record**
5. **Commit transaction**

All operations use prepared statements and are wrapped in try/catch with automatic rollback on error.

## Designing a Deletion Strategy for New Models

When creating a new model with parent-child relationships, plan for **both** soft delete and permanent delete:

### 1. Permanent Delete (`$foreign_key_actions`)

Declare on the **child** model what happens when its parent is permanently deleted:

```php
// Child model — alias belongs to a domain
class InboundEmailAlias extends SystemBase {
    protected static $foreign_key_actions = [
        'iea_ied_inbound_email_domain_id' => ['action' => 'cascade'],
    ];
}

// Grandchild model — log references an alias, preserve for auditing
class InboundEmailLog extends SystemBase {
    protected static $foreign_key_actions = [
        'iel_iea_inbound_email_alias_id' => ['action' => 'null'],
    ];
}
```

### 2. Soft Delete Cascading

`$foreign_key_actions` only applies to `permanent_delete()`. **Soft-delete cascading must be implemented manually** in your deletion logic. When a parent is soft-deleted, children often need to be soft-deleted too:

```php
// In admin logic — soft-delete domain cascades to aliases
$domain->soft_delete();

$aliases = new MultiInboundEmailAlias([
    'domain_id' => $domain->key,
    'deleted' => false,
]);
$aliases->load();
foreach ($aliases as $alias) {
    $alias->soft_delete();
}
```

### 3. Undelete with Cascade Awareness

When restoring a soft-deleted parent, only restore children that were deleted **at the same time or after** the parent. Children independently deleted before the parent should remain deleted:

```php
$domain_delete_time = $domain->get('efd_delete_time');
$domain->undelete();

// Restore only aliases deleted when/after the domain was deleted
$sql = "UPDATE efa_email_forwarding_aliases
        SET efa_delete_time = NULL
        WHERE efa_efd_email_forwarding_domain_id = ?
        AND efa_delete_time >= ?";
$q = $dblink->prepare($sql);
$q->execute([$domain->key, $domain_delete_time]);
```

### Checklist for New Models

- [ ] Define `$foreign_key_actions` on child models for permanent delete behavior
- [ ] Implement soft-delete cascade in the admin/logic layer if parent-child relationship exists
- [ ] Implement undelete logic that respects independently-deleted children
- [ ] Consider whether logs/audit records should use `'action' => 'null'` to preserve history
- [ ] Require appropriate permission level for permanent delete (typically 10)

### Worked example: Drive trash retention

The member Drive (see [Drive](drive.md)) is a full example of both halves plus a
timed purge:

- **Soft-delete cascade** stamps the folder *first* so it holds the earliest
  `delete_time` in its cascade; every descendant folder and file follows.
- **Selective restore** captures the folder's `delete_time` before `undelete()` and
  restores only descendants with `delete_time >=` it — a child trashed
  independently *earlier* stays in the trash.
- **Timed purge** — the `DrivePurgeTrash` scheduled task calls `permanent_delete()`
  on items trashed longer than its window (default 30 days); blob reference counts
  reclaim the shared bytes. A file's `fil_fol_folder_id` uses `'action' => 'null'`
  so a raw folder permanent-delete *orphans* files to the root rather than
  destroying them — the destructive path goes through the trash logic, not the
  bare deletion rule.

## Best Practices

1. **Use constants for sentinel values**: `User::USER_DELETED` instead of hardcoded `3`
2. **Add messages for prevent actions**: Help users understand why deletion failed
3. **Test deletion impact**: Use `permanent_delete_dry_run()` before actual deletion
4. **Prefer CASCADE for logs and temporary data**: Default behavior is usually correct
5. **Use PREVENT sparingly**: Only for truly critical references that can't be orphaned
6. **Document custom permanent_delete()**: Explain any special pre/post-deletion logic

## Migration from Old System

The old system used `$permanent_delete_actions` in parent models:

```php
// OLD (deprecated)
class User extends SystemBase {
    public static $permanent_delete_actions = [
        'ord_usr_user_id' => User::USER_DELETED  // Parent declares child behavior
    ];
}
```

New system uses `$foreign_key_actions` in child models:

```php
// NEW (current)
class Order extends SystemBase {
    protected static $foreign_key_actions = [
        'ord_usr_user_id' => ['action' => 'set_value', 'value' => User::USER_DELETED]
    ];
}
```

**Why the change?**
- Child models know their own requirements better than parents
- Prevents tight coupling between unrelated models
- Allows plugins to define behavior without modifying core
- More explicit action specification
- Supports prevent/null actions that didn't exist before

All `$permanent_delete_actions` declarations have been removed in favor of `$foreign_key_actions`.
