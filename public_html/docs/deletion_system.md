# Deletion System Documentation

## Overview

The deletion system manages cascading deletes, foreign key constraints, and referential integrity when records are permanently deleted from the database. It uses a **child-centric, declarative approach** where dependent models declare their own behavior when parent records are deleted.

> **GET-is-read-only:** `soft_delete()` and `permanent_delete()` (like `save()`) refuse to run on a GET request — a GET must not mutate data. A legitimate GET-action delete link must wrap the call in `SystemBase::$allow_get_mutation = true; try { … } finally { SystemBase::$allow_get_mutation = false; }`. See [Logic Architecture — GET-is-read-only invariant](logic_architecture.md#get-is-read-only-invariant-enforced-at-the-write-boundary).

### Key Concepts

- **Child-Centric**: Child models declare how they should be handled when their parent is deleted (not the other way around)
- **Auto-Detection**: Foreign keys are automatically detected from column naming patterns (`xxx_yyy_entity_id`)
- **Incremental Registration**: Deletion rules are registered per-model without affecting other models' rules
- **Declared, never guessed**: every detected foreign key must carry a declared action; an undeclared relationship registers as `prevent` and refuses the referenced row's deletion, naming the model and column to declare
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

**Ambiguous prefixes.** A prefix can be claimed by two models (`bkt` is both
BookingType and BackupTarget; `cnv` is both Conversation and ContentVersion).
The column name embeds the singular entity — `bkn_bkt_booking_type_id` names
`bkt_booking_types` — so resolution matches that against the candidates'
table names and accepts only an exact singular/plural match. A column that
matches none of the candidates (`bkh_bkt_target_id` — the entity part is
abbreviated) stays unrecognized, and its declaration must name
`source_table`/`source_class` explicitly.

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

### Choosing an action

Ask one question first: **does the referenced row OWN this row, or is it
merely referenced by it?** Both look identical in the schema — an integer
`_id` column — which is why the engine never guesses. An order owns its order
items (they should die with it); a phone number does not own the user who
references it (deleting the number must never delete the user).

| Relationship | Child's shape | Action |
|---|---|---|
| Owned | has children of its own, or a `permanent_delete()` override | `permanent_delete` |
| Owned | true leaf — no children, no custom cleanup | `cascade` |
| Mere reference | link is optional; row stays meaningful without it | `null` (nullable columns only) or `set_value` with a sentinel |
| Load-bearing reference | row is broken or dangerous without it (access gates, financial records) | `prevent` with a message |

Two traps worth naming:

- **`cascade` on a child that has children strands the grandchildren.**
  `cascade` is a flat SQL `DELETE` — none of the child's own deletion rules
  run. If the target table appears as a source table anywhere in
  `del_deletion_rules`, the action must be `permanent_delete`, not `cascade`.
- **`null` on an access gate silently opens the gate.** A group that gates
  events, files, or products must `prevent`, not `null` — clearing the FK
  would make the content public.

When genuinely unsure, declare `prevent`: a wrong `prevent` fails loudly with
the model and column named and is a one-line fix; a wrong `cascade` silently
destroys data.

### 3. Default Behavior

Every foreign-key-shaped column must have an entry in
`$foreign_key_actions` — ModelTester fails the `db` tier for any model with a
detected foreign key and no declared action. A column that reaches
registration undeclared registers as `prevent` with a message naming the
model and column, so an undeclared delete path fails loudly at delete time
instead of quietly deleting rows nobody intended.

## Database-Level Foreign Keys (the integrity backstop)

The PHP deletion doctrine above runs only when deletion goes through the
models. Raw SQL, a crashed process, or a killed test run bypasses it — and a
child row that survives its parent is worse than clutter: if the parent's
primary key is ever reallocated, the stale child attaches to the new owner.
For hard ownership edges, a real database constraint closes that hole.

A field spec declares one with the `foreign_key` key:

```php
'uew_uev_user_encryption_vault_id' => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true,
    'foreign_key'=>array('table'=>'uev_user_encryption_vaults',
                         'column'=>'uev_user_encryption_vault_id',
                         'on_delete'=>'CASCADE')),
```

`update_database` (and plugin sync) materializes every declaration as a real
`FOREIGN KEY ... ON DELETE ...` constraint: missing constraints are created,
and an existing constraint whose target or `ON DELETE` action differs from the
declaration is dropped and recreated — the declaration is the single source of
truth. If orphan rows block creation, the sync reports the table, relation,
and orphan count as a loud error and refuses to continue silently; clean the
orphans (a data migration), then re-run. `on_delete` accepts `CASCADE`,
`SET NULL`, `RESTRICT`, and `NO ACTION`.

**When to declare one:** the child row is meaningless or dangerous without its
parent — encryption wrappings without their vault, passkey credentials without
their user. Soft-delete flows are unaffected (soft delete never removes parent
rows), and the PHP sweeps delete children before parents, so the constraint is
a no-op behind them — the two layers cannot fight. Ordinary relationships stay
on the PHP doctrine alone; it handles sentinel values, `prevent`, and
per-model logic that a DB constraint cannot express.

The `referential_integrity` test (`tests/schema/`, tier `safe`) verifies in
every gate run that each declaration is materialized, that no declared
relation has orphan rows, and that no serial sequence sits behind its table's
`MAX(pkey)`.

## Using $foreign_key_actions in Models

A child model declares what happens to its own rows when a parent goes away. The
model holding the reference is the one that knows whether losing its parent means
it should vanish, be reassigned, or block the delete outright — so that decision
lives with it rather than in a list kept by the parent. This also lets a plugin
define behaviour for its own tables without editing a core model.

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

**Cascade (owned leaf rows)**
```php
class UserActivityLog extends SystemBase {
    public static $tablename = 'ual_user_activity_logs';

    protected static $foreign_key_actions = [
        // Log rows are owned by the user and have no children of their own
        'ual_usr_user_id' => ['action' => 'cascade']
    ];
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
        'msg_thread_id' => ['action' => 'cascade']  // Messages die with their thread
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
$user->assert_can_write($session);
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
    del_action VARCHAR(50),             -- 'cascade', 'permanent_delete', 'set_value', 'null', 'prevent'
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
     - **permanent_delete**: load each dependent as its model and call `permanent_delete()` on it
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
// Child model — alias belongs to a domain. Aliases have children of their
// own (grants, filters, IMAP accounts), so the action is permanent_delete:
// each alias is loaded and deleted through its own rules. A flat 'cascade'
// here would delete the alias rows and strand everything hanging off them.
class InboundEmailAlias extends SystemBase {
    protected static $foreign_key_actions = [
        'iea_ied_inbound_email_domain_id' => ['action' => 'permanent_delete'],
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
- **Timed purge** — the daily retention sweep calls `File::purgeExpiredTrash()`,
  which calls `permanent_delete()`
  on items trashed longer than its window (default 30 days); blob reference counts
  reclaim the shared bytes. A file's `fil_fol_folder_id` uses `'action' => 'null'`
  so a raw folder permanent-delete *orphans* files to the root rather than
  destroying them — the destructive path goes through the trash logic, not the
  bare deletion rule.

### Worked example: mail trash retention

Mail (see [Mailbox](../plugins/mailbox/docs/overview.md#trash-and-retention)) has the
same soft-delete / restore / timed-purge shape with no cascade at all — a message has no
descendants, so there is nothing to restore selectively:

- **Soft delete is one column.** `iem_delete_time`, stamped by
  `MailboxService::softDelete()`. Trash is a *view* over that column, and every other
  read and mutation pins it NULL, so a trashed row is unreachable rather than merely
  hidden.
- **The reclaim lives in the model.** `InboundEmailMessage::permanent_delete()` frees the
  attachment `fil_` Files and the stored raw object (local file or cloud object) before
  the row goes. This is why the purge loops row by row through the model: a bulk `DELETE`
  would satisfy the schema and leak the bytes.
- **Timed purge** — `PurgeMailboxTrash` calls `permanent_delete()` on messages trashed
  longer than `mailbox_trash_retention_days` (default 30) ago, capped per run so a large
  backlog drains over several passes.

## Best Practices

1. **Use constants for sentinel values**: `User::USER_DELETED` instead of hardcoded `3`
2. **Add messages for prevent actions**: Help users understand why deletion failed
3. **Test deletion impact**: Use `permanent_delete_dry_run()` before actual deletion
4. **Check the child for children before choosing `cascade`**: if the target table is itself a source table in `del_deletion_rules`, use `permanent_delete`
5. **When unsure, `prevent`**: it fails loudly and is a one-line fix; a wrong `cascade` silently destroys data
6. **Document custom permanent_delete()**: Explain any special pre/post-deletion logic
