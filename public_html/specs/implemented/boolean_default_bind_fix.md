# Spec: Boolean Default Bind Fix (SystemBase) + String-Default Normalization

**Status:** Implemented
**Scope:** Core `SystemBase::save()` boolean binding + `field_specifications` boolean default
declarations across core and plugin data classes
**Related:** `docs/logic_architecture.md` (model layer), `includes/SystemBase.php`

---

## 1. Background — the bug

`SystemBase::save()` binds boolean columns in the `data_type == 'boolean'` branch. The historical
logic was:

```php
else if($column_val==TRUE){  $dbhelper->bind_value(..., TRUE,  PDO::PARAM_BOOL); }
else if($column_val==FALSE){ $dbhelper->bind_value(..., FALSE, PDO::PARAM_BOOL); }
```

`$column_val == TRUE` uses PHP loose comparison, under which the **non-empty string `'false'` is
truthy** — so a boolean carrying the string `'false'` was bound as **TRUE**. The value reaches the
branch via the insert-time default fill (`save()`, key === NULL): for each field with a `default` and
a current value of NULL, the field is set to `$spec['default']` verbatim. So a boolean column whose
`field_specifications` default was declared as the **string** `'false'` and left unset on insert was
stored as `true`.

**Visible symptom:** every ingested inbound email arrived starred and marked read
(`iem_is_read` / `iem_is_starred`, both `'default'=>'false'`), because message ingestion inserts the
row without setting read/star, hitting the default-fill path on every message.

## 2. Why it took 15 years to surface

The faulty bind only misfires on the literal string `'false'`. The trigger therefore requires all of:
insert (key === NULL) + boolean column + field left unset + its default declared as the **string**
`'false'`.

The 15-year-old core consistently declared boolean defaults as PHP `false`/`true` or `0`/`1`, all of
which bind correctly even under the old code. The string-`'true'`/`'false'` convention is recent and
confined to newer code (the `inbound_email`, `server_manager`, `dns_filtering` plugins and the core
`seo_page_metadata`). So the branch was effectively never fed a string `'false'` until those landed.
Among those, only **inbound_email message ingestion** inserts rows without setting the boolean
(read/star are not part of receiving a message), so it was the lone path that actually exercised the
bug — and did so visibly.

## 3. Audit of existing data (read-only)

All 12 columns declaring a string `'false'` default were checked for true/false/null distribution in
production. Findings:

- **Only `iem_is_read` / `iem_is_starred` were corrupted** — already corrected (the affected rows were
  reset to false; new inserts now store false).
- **No other column shows the bug's signature.** Decisive evidence: `mgn_managed_nodes` carries three
  string-`'false'` booleans across the same 29 rows with *different* distributions — the insert bug
  would have flipped all three to true uniformly; it did not, so those values are real (set via the
  node form). `spm_noindex` is 0-true across 280 rows (no SEO impact); `mgn_tls_insecure` is mostly
  false (no security impact); `iia_needs_reauth`, `mgh_provisioning_enabled` are clean.
- Reason the others escaped: their creation flows set the boolean explicitly (forms / code), so the
  buggy default-fill branch was never reached.

**Conclusion:** no data cleanup required beyond the `iem_` rows already fixed.

## 4. The fix

Two changes, defense-in-depth + remove the foot-gun.

### 4.1 Harden the `SystemBase` boolean bind

Replace the loose-comparison branch with explicit normalization that handles every representation —
native bools, recognized truthy strings (including the Postgres `'t'`), and everything else as false:

```php
if (is_bool($column_val)) {
    $bool_val = $column_val;
} else if (is_string($column_val)) {
    $bool_val = in_array(strtolower(trim($column_val)), ['t','true','1','yes','on'], true);
} else {
    $bool_val = (bool)$column_val;
}
$dbhelper->bind_value(":$column_name", $bool_val, PDO::PARAM_BOOL);
```

Why not `filter_var(FILTER_VALIDATE_BOOLEAN)`: it does not recognize `'t'`/`'f'`, so if the PDO
driver/config ever returned Postgres-style boolean strings (`pdo_pgsql` does so when
`EMULATE_PREPARES`/`STRINGIFY_FETCHES` are on, or on older drivers) it would map `'t'` → false — a
silent true→false regression. The explicit list is environment-independent. The NULL-handling branch
above it (NULL → NULL if nullable, else FALSE) is unchanged.

### 4.2 Normalize string boolean defaults

Change every `'default'=>'false'` / `'default'=>'true'` (string) declaration to the PHP literal
`false` / `true` across core and plugin data classes (22 occurrences in 12 files). This makes the
default-fill set a native bool, so the trigger condition can no longer exist regardless of the bind
logic. (String `'true'` defaults bound correctly even before, but are normalized for consistency.)

Files touched: `data/seo_page_metadata_class.php`, `data/scheduled_tasks_class.php`,
`data/request_logs_class.php`, `plugins/inbound_email/data/{inbound_imap_account,inbound_email_domain,
inbound_email_message,inbound_email_alias,inbound_message_attachment}_class.php`,
`plugins/server_manager/data/{managed_node,managed_host,backup_target}_class.php`,
`plugins/dns_filtering/data/devices_class.php`.

No schema change: the PHP-level default value is unchanged in meaning (false stays false), so
`update_database` is not required.

## 5. Verification

- `php -l` on every changed file.
- `validate_php_file.php` on `SystemBase.php`.
- Single-model suite (`tests/models/run_all.php`) — must remain green (82 classes / 164 tests).
- Multi suite (`tests/models/run_multi.php`) — result must be identical with and without the change
  (its pre-existing failures are unrelated test-DB schema drift).
- Bind unit behavior: native `true`/`false`, strings `'true'`/`'false'`/`'t'`/`'f'`/`'1'`/`'0'`/`''`
  all map to the correct boolean.

## 6. Docs

No developer-doc change needed — this is an internal correctness fix with no API surface. The why is
captured here and in the commit; per the docs rule, docs describe current state only.
