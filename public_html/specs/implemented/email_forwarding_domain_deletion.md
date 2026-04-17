# Email Forwarding Domain Deletion

## Problem

When an email forwarding domain is soft-deleted, several things go wrong:

1. **Aliases are orphaned** — aliases remain active in the database with no parent domain, invisible to the UI but present as dead rows
2. **Misleading confirmation** — the delete button says "Delete this domain and all its aliases?" but only the domain is deleted
3. **Postfix config stale** — the deleted domain remains in postfix's `virtual_mailbox_domains` until someone manually reruns the setup script
4. **No alias deletion UI** — there is no way to delete individual aliases from the admin interface at all
5. **No undelete** — soft-deleted domains cannot be recovered through the UI
6. **No `$foreign_key_actions`** — if permanent delete is ever called, behavior is undefined (defaults to cascade, which is correct but not explicit)

## Requirements

### 1. Domain soft-delete cascades to aliases

When a domain is soft-deleted:
- All non-deleted aliases belonging to that domain must be soft-deleted at the same time
- Each alias should have its own `efa_delete_time` set (not just hidden by the domain's deletion)
- This ensures aliases are properly marked as deleted if the domain is later permanently deleted or if aliases are queried independently

### 2. Domain undelete restores aliases

When a domain is undeleted:
- All aliases that were soft-deleted at the same time as the domain (or after) should be restored
- Aliases that were independently deleted before the domain deletion should remain deleted
- Implementation: restore aliases where `efa_delete_time >= domain's efd_delete_time`

### 3. Individual alias deletion

Add a delete action to the alias admin interface:
- Soft-delete button on the alias edit page
- Confirmation dialog before deletion
- Redirect back to alias listing after deletion

### 4. Postfix configuration warning

When a domain is deleted, the admin should see a clear warning that postfix configuration needs to be updated. Options (pick one):
- **Option A (recommended):** Display a persistent banner on the domains admin page when the postfix config is detected as stale (domain list in postfix doesn't match active domains in DB)
- **Option B:** Show a one-time flash message after deletion reminding the admin to rerun the setup script
- **Option C:** Auto-regenerate postfix config on domain deletion (risky — requires shell access and could fail silently)

### 5. Declare `$foreign_key_actions`

Add explicit foreign key actions to the EmailForwardingAlias model:

```php
protected static $foreign_key_actions = [
    'efa_efd_email_forwarding_domain_id' => ['action' => 'cascade']
];
```

This makes the cascade behavior explicit and ensures permanent_delete on a domain properly cleans up aliases through the built-in deletion system.

### 6. Permanent delete support (optional, lower priority)

Currently only soft-delete is used. Consider whether permanent delete should be available for domains that have been soft-deleted for a period of time. If implemented:
- The `$foreign_key_actions` cascade (requirement 5) handles alias cleanup
- Email forwarding logs referencing the domain/alias should be preserved (set foreign key to null or leave as-is since logs are informational)

## Edge Cases

### Alias with active forwarding traffic
- No special handling needed — soft-deleting the domain already causes `EmailForwarder::lookupAlias()` to return null (it checks domain enabled status and delete_time), so mail will be rejected immediately
- The forwarding count and last_forward_time on aliases are preserved for auditing

### Domain with catch-all address
- If the domain has `efd_catch_all_address` set, that catch-all stops working immediately on soft-delete since domain lookup fails
- No special handling needed beyond the standard cascade

### Re-adding a previously deleted domain
- If an admin creates a new domain with the same name as a soft-deleted one, this should work (the old record stays soft-deleted, new record is created)
- `GetByDomain()` already filters by non-deleted records, so no conflict
- Old aliases on the deleted domain remain deleted and are NOT transferred to the new domain

### Undelete with individually-deleted aliases
- If alias A was deleted on March 1, then the domain was deleted on March 10, then the domain is undeleted:
  - Alias A should remain deleted (it was deleted before the domain)
  - All other aliases should be restored
- Compare `efa_delete_time` against `efd_delete_time` to determine which aliases to restore

### Domain disabled vs deleted
- `efd_is_enabled = false` is different from soft-delete — disabled domains stay visible in admin and can be re-enabled
- Disabling a domain should NOT cascade to aliases (the admin may want to temporarily pause all forwarding)
- Only deletion should cascade

### Concurrent alias edits during domain deletion
- Not a real concern — admin operations are single-user and sequential
- No locking needed

### Email forwarding logs
- `efl_email_forwarding_logs` references aliases and domains
- Logs should NOT be deleted when domains/aliases are deleted — they're audit records
- If the EmailForwardingLog model has foreign keys to domain/alias, declare `'action' => 'null'` or leave logs untouched

## Implementation Notes

### Soft-delete cascade (not using $foreign_key_actions)

The built-in `$foreign_key_actions` system only applies to `permanent_delete()`. For soft-delete cascade, implement it in the domain deletion logic:

```php
// In admin_email_forwarding_domains_logic.php, after domain soft_delete:
$domain->soft_delete();

// Cascade soft-delete to all active aliases
$aliases = new MultiEmailForwardingAlias([
    'domain_id' => $domain->key,
    'deleted' => false
]);
$aliases->load();
foreach ($aliases as $alias) {
    $alias->soft_delete();
}
```

### Undelete cascade

```php
// Restore aliases deleted at or after domain deletion
$domain_delete_time = $domain->get('efd_delete_time');
$domain->undelete();

$aliases = new MultiEmailForwardingAlias([
    'domain_id' => $domain->key,
    // Need a custom filter: efa_delete_time >= $domain_delete_time
]);
// Restore only aliases deleted at the same time or after the domain
```

### File changes expected

- `plugins/email_forwarding/logic/admin_email_forwarding_domains_logic.php` — cascade soft-delete, undelete logic
- `plugins/email_forwarding/logic/admin_email_forwarding_alias_logic.php` — add delete action
- `plugins/email_forwarding/admin/admin_email_forwarding_alias.php` — add delete button UI
- `plugins/email_forwarding/data/email_forwarding_alias_class.php` — add `$foreign_key_actions`
- `plugins/email_forwarding/data/email_forwarding_log_class.php` — add `$foreign_key_actions` (null on delete)
- `plugins/email_forwarding/admin/admin_email_forwarding_domains.php` — add postfix warning, optional undelete UI
