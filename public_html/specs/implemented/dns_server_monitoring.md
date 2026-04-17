# DNS Server Monitoring Spec

Add the two ScrollDaddy DNS servers to the Server Manager dashboard so they're visible alongside the Joinery fleet — basic health, disk, memory, and load.

## Scope

**In:**
- DNS servers appear as managed nodes on the dashboard with health dots
- Disk / memory / load / uptime shown on the Overview tab
- Check Status and Test Connection buttons work
- Nodes added through the existing Add Node form

**Out:**
- DNS-specific probes (port 53 listening, zone count, service status, SOA sync). Can be added later if we find we want them.
- Deploy / zones / config tabs. Existing CLI flow (`make release` + scp from CLAUDE.md) is fine.
- A node-type abstraction. Two if-checks are cheaper than a framework; revisit if a third non-Joinery server class shows up.

## Schema change

One column on `mgn_managed_nodes`:

- **`mgn_skip_joinery_checks`** — `boolean`, default `false`, not null.

Existing rows default cleanly. No data migration needed.

## Code changes

### `JobCommandBuilder::build_check_status()`

Four steps rely on Joinery-specific plumbing (either `get_db_credentials_script()` or the `$web_root`-derived logs path):

- `Check PostgreSQL` (`pg_isready`)
- `Check Joinery version` (reads `stg_settings` via db credentials)
- `Recent errors` (greps `dirname($web_root)/logs/error.log`)
- `List databases` (queries Postgres via db credentials)

Wrap all four in one flag check:

```php
if (!$node->get('mgn_skip_joinery_checks')) {
    // existing four Joinery-specific steps
}
```

Everything else (`df -h /`, `free -m`, `uptime`, container stats) works on any Linux host.

### `JobResultProcessor` — no changes needed

`process_check_status()` parses output with independent `preg_match` calls; when the Joinery-specific steps don't run, the corresponding keys (`postgres_status`, `joinery_version`, `current_db`, `db_list`) simply don't appear in `$result`. Downstream consumers already `isset`-guard these fields.

### Dashboard — no changes needed

The node-card color logic in `views/admin/index.php` already uses `isset()` on every status field, and the "upgrade available" badge is already gated on `if ($node_version)`. DNS nodes render cleanly with whatever subset of fields they produce.

### Add Node form

Add a single checkbox: **"Skip Joinery-specific checks"** (for DNS servers, generic Linux boxes, etc.). Defaults unchecked. When checked, `mgn_web_root` and `mgn_container_name` become optional instead of required.

### Node detail tabs

The Backups / Database / Updates tabs depend on Joinery-specific operations (`backup_database.sh`, `upgrade.php`) that won't work on a DNS server. Options, in order of tidiness:

1. **Hide them** when `mgn_skip_joinery_checks` is true. Cleanest; one `if` in `node_detail.php`.
2. Leave them visible with action buttons that fail — ugly but zero extra code.

Go with option 1.

## Adding the DNS servers

Manual, through the Add Node form:

| Field | Primary DNS | Secondary DNS |
|-------|-------------|---------------|
| Display Name | ScrollDaddy DNS Primary | ScrollDaddy DNS Secondary |
| Slug | scrolldaddy-dns-primary | scrolldaddy-dns-secondary |
| SSH Host | 45.56.103.84 | 97.107.131.227 |
| SSH User | root | root |
| SSH Key Path | /home/user1/.ssh/id_ed25519_claude | (same) |
| Skip Joinery checks | ✓ | ✓ |
| Web Root | _(blank)_ | _(blank)_ |
| Container | _(blank)_ | _(blank)_ |

Run Check Status from the node card; the dashboard should show disk/memory/load within a minute.

## Implementation order

1. Add `mgn_skip_joinery_checks` column to the data class.
2. Gate the four Joinery-specific steps in `build_check_status()`.
3. Add the checkbox to the Add Node form; make web_root/container optional when checked.
4. Hide the Backups / Database / Updates tabs on node detail when the flag is set.
5. Add the two ScrollDaddy DNS nodes and run Check Status to verify.

Total: ~15–20 lines of new code across four files.

## If this grows

If we later want real DNS-specific probes (port 53, service status, zone count, peer sync), the cheapest next step is **more individual flags or extra step arrays behind the same flag**, not a framework. Only when a third non-Joinery server class arrives — and we can see what the three have in common — is it worth considering a proper abstraction. Build it then, with real requirements instead of guessed ones.

## Documentation

Extend `docs/server_manager.md` with a short note on the "Skip Joinery checks" option: what it does, when to use it, which tabs/buttons are affected.
