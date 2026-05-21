# Customer-Baseline Agent File Upgrades

## Problem

The platform ships a customer-facing agent template at
`maintenance_scripts/install_tools/default_agents_template.md`. At
fresh install, `create_install_sql.php` writes this content into a
**Customer baseline** row in `agf_agent_files` (via the bootstrap
SQL). After that, the customer's DB row is independent: they can
edit it through `/admin/admin_agent_files` and click "Write to disk"
to produce `CLAUDE.md`.

When we improve the template later, **existing customers never see
the improvements**. Their row was seeded once, on their install
date, from whatever the template said then. The upgrade tarball
re-ships the template file but nothing on the customer's box reads
it again — the bootstrap SQL runs on fresh install only.

This spec adds a non-destructive upgrade path so existing customers
pick up template improvements without losing local edits.

(Scope deliberately excludes the "Internal CLAUDE.md" row that lives
only on our dev test server — see `specs/implemented/agent_files_management.md`
where internal-vs-customer separation was established. Internal
content is single-instance by design; there is nothing to propagate.)

## Design

### Hash-based identity

One new column on `agf_agent_files`:

| Field | Type | Purpose |
|---|---|---|
| `agf_template_baseline_hash` | `varchar(64)` (nullable) | SHA-256 of the template content this row was last instantiated or accepted-upgrade from |

A row whose `agf_content` hash matches its
`agf_template_baseline_hash` is "unmodified relative to its
baseline." A row whose hashes differ has been edited.

`agf_template_baseline_hash` is set:
- on fresh-install seed (hash of `default_agents_template.md` at install time);
- when the admin **switches to** a candidate row (hash of the new template that produced the candidate).

Legacy rows that predate this column stay null and are treated as
"unknown" — neither auto-upgraded nor candidate-flagged, so a
customer who installed before this feature shipped doesn't get
surprise updates.

### Detection step

A new step inside `update_database`, before the existing
**Agent Files Regenerate** step:

```
-----AGENT FILES TEMPLATE CHECK-----
```

Read the template file
(`maintenance_scripts/install_tools/default_agents_template.md`) and
compute `template_hash = sha256(normalize(content))`, where
`normalize` trims trailing whitespace per line and forces `\n`
endings — so equivalent content with different line endings doesn't
register as a change.

For each non-deleted row with `agf_name = 'Customer baseline'`:

1. If `agf_template_baseline_hash IS NULL` — legacy row, skip.
2. If `template_hash == agf_template_baseline_hash` — same template
   we already know about, skip.
3. If `template_hash == sha256(normalize(agf_content))` — the
   admin's current content **is** the new template byte-for-byte
   (e.g. they pasted it in manually). Bump baseline hash; no
   candidate.
4. If `sha256(normalize(agf_content)) == agf_template_baseline_hash`
   — admin has not edited; replace `agf_content` with the new
   template, set `agf_template_baseline_hash = template_hash`.
   Auto-upgrade done. Regenerate step writes new content to disk.
5. Otherwise — admin edited *and* template changed. **Rolling
   candidate**:
   - If a candidate row already exists for this active row, update
     its `agf_content` to the new template and its
     `agf_template_baseline_hash` to `template_hash`. No new row.
   - Else create one: `agf_name = 'Upgrade candidate for #<id>'`,
     `agf_target_filenames = []`, `agf_content` = template content,
     `agf_template_baseline_hash = template_hash`,
     `agf_candidate_for = <active row id>`.

There is at most **one candidate per active row** at any time —
subsequent template versions roll the candidate forward in place.
No backlog; the admin sees "the latest upgrade is ready" or nothing.

### Schema additions

```php
// agent_files_class.php $field_specifications additions:
'agf_template_baseline_hash' => ['type' => 'varchar(64)'],
'agf_candidate_for'          => ['type' => 'int4'],

// foreign_key_actions addition:
'agf_candidate_for' => ['action' => 'cascade'],
```

`agf_candidate_for` references `agf_agent_file_id` on the same
table. Cascade so deleting an active row also deletes its pending
candidate.

### Admin UI changes

`/admin/admin_agent_files` list view gains a **Status** column:

| State | Badge |
|---|---|
| `agf_template_baseline_hash` matches content | `In sync` (gray) |
| Edited locally (content hash != baseline hash) | `Edited` (yellow) |
| Edited locally + candidate exists | `Edited · Update available` (yellow + accent) |
| Pending candidate row | `Candidate for #<n>` (blue) |
| Legacy (null baseline hash) | nothing — column blank |

Active rows with a candidate render an inline panel:

> **An updated agent template is available.** [Compare] [Switch to new version]

- **Switch to new version**: moves `agf_target_filenames` from the
  active row to the candidate, archives the previously-active row
  (`agf_target_filenames = []`, `agf_name` prefixed `Archived — `),
  then calls `write_to_disk()` on the now-active candidate.
- **Compare**: opens a read-only side-by-side view — two
  `<textarea readonly>` panes, no diff library. Add a real diff
  later if scanning side-by-side isn't enough.

No Dismiss action. The candidate either gets switched in or rolls
forward to the next template version. This keeps the lifecycle
simple (active ↔ candidate, archived after switch) and avoids the
"I dismissed v2, do I get re-notified when v3 ships?" problem.

Permission 10 only.

### Seed change

`create_install_sql.php` already builds the Customer baseline INSERT
from `default_agents_template.md`. Add one column: set
`agf_template_baseline_hash` to the normalized SHA-256 of the
template content at install time. New customers start with their
baseline hash matching their content — they're "in sync" from day
one.

### Edge cases

- **Customer has edited `agf_target_filenames`** (e.g. added
  `GEMINI.md`). On switch, the candidate inherits the active row's
  target set, not the seed's defaults — the customer's
  customization wins.
- **Customer permanently deleted "Customer baseline"** — no row
  matches the name filter, detection step does nothing.
- **Multiple rows named "Customer baseline"** — shouldn't happen
  (customers don't generally clone the row), but if it does, all
  matching rows run through detection independently. Each gets its
  own candidate.

## Files

### To modify
| File | Change |
|---|---|
| `data/agent_files_class.php` | Add `agf_template_baseline_hash` + `agf_candidate_for` fields; helpers `is_unmodified()`, `current_candidate()`, `switch_to_candidate()` |
| `utils/update_database.php` | New "Agent Files Template Check" step before "Agent Files Regenerate" |
| `utils/create_install_sql.php` | Set `agf_template_baseline_hash` on the seeded Customer baseline row |
| `adm/admin_agent_files.php` + logic | Status column; candidate panel; Compare / Switch actions |

### Schema
Schema additions applied automatically by `update_database` from the
data class. Existing rows get null baseline hashes (legacy state).

## Testing

- **Unmodified auto-upgrade** — seed a row with content=v1,
  baseline=hash(v1). Ship v2 template. Run update_database.
  Expect: content=v2, baseline=hash(v2), no candidate.
- **Edited triggers candidate** — same seed but edit content so
  hash != baseline. Ship v2. Expect: active row unchanged, one
  candidate row with v2 content and hash(v2) baseline.
- **Rolling forward** — with existing candidate at v2, ship v3.
  Expect: still one candidate, content now v3, baseline now
  hash(v3).
- **Switch** — call switch_to_candidate. Expect: target filenames
  moved from active → candidate; previously-active row archived;
  new active row's `write_to_disk()` ran.
- **Legacy row** — null baseline_hash. Ship v2. Expect: no action,
  no candidate.
- **Idempotent re-run** — run update_database twice with same
  template. Expect: no duplicate candidates, no churn.
- **Equivalent content** (v1 content with `\r\n` line endings vs
  `\n`) hashes to the same normalized value — no spurious upgrades.

## Documentation

`docs/deploy_and_upgrade.md` gains a short "Agent file upgrades"
section: how the customer-baseline row receives template updates,
when the admin will see a candidate, how the Switch action works.
