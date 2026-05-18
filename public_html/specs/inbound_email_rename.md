# Rename: Email Forwarding plugin → Inbound Email

## Overview

The Email Forwarding plugin has outgrown its name. It is becoming the
platform's **inbound email** subsystem — the receiving counterpart to the
existing outbound sending (`SystemMailer` etc.). Forwarding remains *one
feature* of it, alongside local mailbox delivery (see
`inbound_email_local_mailbox.md`) and whatever comes later.

This spec renames the **plugin-identity layer** to `inbound_email` and
deliberately **keeps** the **forwarding-feature layer** named for forwarding.
It is a mechanical rename: the plugin is not deployed anywhere except the dev
box, so there is no `upgrade.php` migration to survive and no production data
at risk.

## The rule

- **Change** anything that names the *plugin / subsystem*.
- **Keep** anything that names the *forwarding feature specifically* — code
  that only runs when mail is relayed onward (SRS, the outbound relay, forward
  counters/statuses, the forward methods).

A renamed table or class may still legitimately contain the token `forward`
(e.g. `iea_forward_count`). That is correct, not a missed replacement.

## Naming scheme

| Layer | Old | New |
|-------|-----|-----|
| Plugin directory | `email_forwarding` | `inbound_email` |
| Class family | `EmailForwarding*` / `EmailForwarder` | `InboundEmail*` / `InboundEmailRouter` |
| Table prefixes | `efa_` `efd_` `efl_` | `iea_` `ied_` `iel_` |
| Settings prefix | `email_forwarding_` | `inbound_email_` (feature settings sub-namespaced, e.g. `inbound_email_forwarding_*`) |

---

## 1. Plugin identity & directory

| Part | Old | New | Action |
|------|-----|-----|--------|
| Plugin directory | `plugins/email_forwarding/` | `plugins/inbound_email/` | **CHANGE** |
| `plugin.json` `name` | `Email Forwarding` | `Inbound Email` | **CHANGE** |
| `plugin.json` `description` | "Self-hosted email forwarding…" | "Self-hosted inbound email: forwarding, local mailboxes, DKIM/SRS" | **CHANGE** |
| `plugin.json` `tags` | `["email","forwarding","smtp"]` | add `"inbound"`; keep the rest | **CHANGE (additive)** |
| `adminMenu` slug | `incoming` | `incoming` | **KEEP** — already generic |
| `adminMenu` title | `Incoming` | `Incoming` | **KEEP** |
| `adminMenu` url | `/plugins/email_forwarding/admin/admin_email_forwarding` | `/plugins/inbound_email/admin/admin_inbound_email` | **CHANGE** |
| `adminMenu` settingActivate | `email_forwarding_enabled` | `inbound_email_enabled` | **CHANGE** |

## 2. Pipe entry point

| Part | Old | New | Action |
|------|-----|-----|--------|
| Postfix pipe script | `utils/email_forwarder.php` | `utils/inbound_email_handler.php` | **CHANGE** — handles *all* inbound mail (forward and store), not just forwarding |

## 3. Core classes — `includes/`

| Part | Old | New | Action |
|------|-----|-----|--------|
| Orchestrator class | `EmailForwarder` | `InboundEmailRouter` | **CHANGE** — it routes (forward vs. store) |
| Health class | `EmailForwardingHealth` | `InboundEmailHealth` | **CHANGE** |
| `EmailForwardingHealth::checkForwardingRelay()` | method name | `checkForwardingRelay()` | **KEEP** — checks the forwarding relay specifically |
| `EmailForwardingHealth::checkDomainDns()` | method name | `checkDomainDns()` | **KEEP** — generic |
| SRS class | `SRSRewriter` | `SRSRewriter` | **KEEP** — SRS is a forwarding mechanism named after its standard |
| `includes/SRSRewriter.php` | file | unchanged | **KEEP** |
| Forwarding methods on the router (`forwardEmail`, `forwardToCatchAll`, `handleSRSBounce`) | method names | unchanged | **KEEP** — forwarding-specific logic |
| Generic methods (`parseEmail`, `verifyDKIM`, `lookupAlias`, `checkAliasRateLimit`, `logTransaction`, …) | method names | unchanged | **KEEP** — internal, not identity |

`includes/EmailForwarder.php` → `includes/InboundEmailRouter.php`;
`includes/EmailForwardingHealth.php` → `includes/InboundEmailHealth.php`.

## 4. Data classes — `data/`

| Old file | New file | Old classes | New classes | Action |
|----------|----------|-------------|-------------|--------|
| `email_forwarding_alias_class.php` | `inbound_email_alias_class.php` | `EmailForwardingAlias`, `MultiEmailForwardingAlias`, `EmailForwardingAliasException` | `InboundEmailAlias`, `MultiInboundEmailAlias`, `InboundEmailAliasException` | **CHANGE** — aliases now forward *or* store |
| `email_forwarding_domain_class.php` | `inbound_email_domain_class.php` | `EmailForwardingDomain` + Multi/Exception | `InboundEmailDomain` + Multi/Exception | **CHANGE** |
| `email_forwarding_log_class.php` | `inbound_email_log_class.php` | `EmailForwardingLog` + Multi/Exception | `InboundEmailLog` + Multi/Exception | **CHANGE** — logs forwards *and* stores |

Domain/alias/log model conventions inside each class:

| Part | Old | New | Action |
|------|-----|-----|--------|
| `$prefix` | `efa` / `efd` / `efl` | `iea` / `ied` / `iel` | **CHANGE** |
| `$tablename` | `efa_email_forwarding_aliases` etc. | `iea_inbound_email_aliases` etc. | **CHANGE** |
| `$pkey_column` | `efa_email_forwarding_alias_id` etc. | `iea_inbound_email_alias_id` etc. | **CHANGE** |
| Every `efX_*` column | `efX_*` | `ieX_*` | **CHANGE** — mechanical prefix swap |
| `efa_forward_count`, `efa_last_forward_time` | columns | `iea_forward_count`, `iea_last_forward_time` | **CHANGE prefix, KEEP `forward` token** — forwarding counters |
| FK key in `$foreign_key_actions` (`efa_efd_…`, `efl_efa_…`) | | `iea_ied_…`, `iel_iea_…` | **CHANGE** |
| `getMultiResults()` filter *option keys* (`domain`, `alias`, `enabled`, `deleted`, …) | option keys | unchanged | **KEEP** — public filter API, not column names |
| `getMultiResults()` filter *column references* | `efX_*` | `ieX_*` | **CHANGE** |
| Log `STATUS_*` constant names | `STATUS_FORWARDED`, `STATUS_BOUNCE_FORWARDED`, `STATUS_REJECTED`, … | unchanged | **KEEP** — `STATUS_FORWARDED`/`STATUS_BOUNCE_FORWARDED` are forwarding-feature values; others generic |
| Static helpers (`GetByAddress`, `GetByDomain`, `CreateEntry`, `record_forward`, …) | method names | unchanged | **KEEP** |

## 5. Database tables

| Old table | New table | Action |
|-----------|-----------|--------|
| `efa_email_forwarding_aliases` | `iea_inbound_email_aliases` | **CHANGE** — drop, recreate via `update_database` / plugin sync |
| `efd_email_forwarding_domains` | `ied_inbound_email_domains` | **CHANGE** |
| `efl_email_forwarding_logs` | `iel_inbound_email_logs` | **CHANGE** |

Tables are recreated from the data-class `$field_specifications` by **"Sync
with Filesystem"** on the admin Plugins page. The old tables are dropped
manually (a `DROP TABLE` — needs explicit confirmation per repo DB rules).
Existing rows are dev test data and are not migrated.

## 6. Settings

All settings move to the `inbound_email_` prefix. Plugin-level settings sit
directly under it; forwarding-feature settings keep a `forwarding` token,
sub-namespaced under the plugin prefix.

| Old key | New key | Action / rationale |
|---------|---------|--------------------|
| `email_forwarding_enabled` | `inbound_email_enabled` | **CHANGE** — plugin master switch |
| `email_forwarding_log_retention_days` | `inbound_email_log_retention_days` | **CHANGE** — logs are plugin-level |
| `email_forwarding_max_destinations` | `inbound_email_forwarding_max_destinations` | **CHANGE** — caps a forward alias; keeps `forwarding` |
| `email_forwarding_rate_limit_per_alias` | `inbound_email_forwarding_rate_limit_per_alias` | **CHANGE** — limits forwarding only |
| `email_forwarding_rate_limit_per_domain` | `inbound_email_forwarding_rate_limit_per_domain` | **CHANGE** |
| `email_forwarding_rate_limit_window` | `inbound_email_forwarding_rate_limit_window` | **CHANGE** |
| `email_forwarding_srs_enabled` | `inbound_email_srs_enabled` | **CHANGE** prefix; `srs` token kept |
| `email_forwarding_srs_secret` | `inbound_email_srs_secret` | **CHANGE** |
| `email_forwarding_smtp_host` | `inbound_email_forwarding_smtp_host` | **CHANGE** — relay used only to forward |
| `email_forwarding_smtp_port` | `inbound_email_forwarding_smtp_port` | **CHANGE** |
| `email_forwarding_smtp_username` | `inbound_email_forwarding_smtp_username` | **CHANGE** |
| `email_forwarding_smtp_password` | `inbound_email_forwarding_smtp_password` | **CHANGE** |

Keys are declared in `plugin.json` `settings` and auto-seed into `stg_settings`.
Old rows linger as orphans — delete them as a cleanup step. Touch points: every
`get_setting()` call (mainly `InboundEmailRouter`, `SRSRewriter`,
`settings_form.php`), the `adminMenu.settingActivate`, and `settings_form.php`.

## 7. Admin pages & logic

| Old file | New file | Action |
|----------|----------|--------|
| `admin/admin_email_forwarding.php` | `admin/admin_inbound_email.php` | **CHANGE** |
| `admin/admin_email_forwarding_alias.php` | `admin/admin_inbound_email_alias.php` | **CHANGE** |
| `admin/admin_email_forwarding_domains.php` | `admin/admin_inbound_email_domains.php` | **CHANGE** |
| `admin/admin_email_forwarding_logs.php` | `admin/admin_inbound_email_logs.php` | **CHANGE** |
| `logic/admin_email_forwarding_logic.php` | `logic/admin_inbound_email_logic.php` | **CHANGE** |
| `logic/admin_email_forwarding_alias_logic.php` | `logic/admin_inbound_email_alias_logic.php` | **CHANGE** |
| `logic/admin_email_forwarding_domains_logic.php` | `logic/admin_inbound_email_domains_logic.php` | **CHANGE** |

Each renamed page also gets internal-link, tab-URL, and `require_once` path
updates. The Logs tab title shown to users stays plain ("Logs"); the
underlying page name changes.

## 8. Scheduled tasks — `tasks/`

| Part | Old | New | Action |
|------|-----|-----|--------|
| Task class + file | `PurgeOldForwardingLogs.php` / `PurgeOldForwardingLogs` | `PurgeOldInboundEmailLogs.php` / `PurgeOldInboundEmailLogs` | **CHANGE** — purges the inbound-email log table |
| Task config | `PurgeOldForwardingLogs.json` | `PurgeOldInboundEmailLogs.json` | **CHANGE** — and its registered class reference |

## 9. Provisioning — `provisioning/`

| Part | Old | New | Action |
|------|-----|-----|--------|
| `install_email.sh` filename | `install_email.sh` | `install_email.sh` | **KEEP** — not forwarding-named |
| `install_email.sh` `PIPE_SCRIPT` path | `…/utils/email_forwarder.php` | `…/utils/inbound_email_handler.php` | **CHANGE** |
| `install_email.sh` "run from inside the email_forwarding plugin directory" guard | text + check | `inbound_email` | **CHANGE** |
| `install_email.sh` table check / `GRANT` on `efd_email_forwarding_domains` | table name | `ied_inbound_email_domains` | **CHANGE** |
| `install_email.sh` pgsql-map role `efwd_map_<dbname>` | role name | `iemap_<dbname>` | **CHANGE** — role is recreated by the script |
| `install_email.sh` doc-path comments (`plugins/email_forwarding/docs/…`) | comments | `plugins/inbound_email/docs/…` | **CHANGE** |
| `install_email.sh` stale-script cleanup (`setup_email_forwarding.sh`) | reference | unchanged | **KEEP** — it cleans up a legacy artifact by its real old name |
| `render_pgsql_map.php` query `SELECT efd_domain FROM efd_email_forwarding_domains` | table/columns | `SELECT ied_domain FROM ied_inbound_email_domains` | **CHANGE** |
| Postfix `joinery` pipe transport name | `joinery` | `joinery` | **KEEP** — not forwarding-named |
| `/etc/postfix/joinery-domains.cf` map file | filename | unchanged | **KEEP** |

**Re-run `install_email.sh` after the rename** so Postfix's pipe transport
points at the new script path and the pgsql map at the new table — otherwise
inbound mail flow breaks.

## 10. Provisioner keys — `plugin.json`

| Key | Action |
|-----|--------|
| `inbound_mail_server` | **KEEP** — already generic and correct |
| `outbound_forwarding_relay` | **KEEP** — forwarding-specific, correct |
| `domain_dns_records` | **KEEP** — generic |
| `check.call` `EmailForwardingHealth::checkForwardingRelay` | **CHANGE class, KEEP method** → `InboundEmailHealth::checkForwardingRelay` |
| `script` `provisioning/install_email.sh` | **KEEP** |

## 11. Migrations

| Part | Action |
|------|--------|
| `migrations/migrations.php` | **KEEP** — returns `[]`; no schema or seeds live here |

## 12. Documentation & external references

| Part | Action |
|------|--------|
| `plugins/email_forwarding/docs/overview.md` → `plugins/inbound_email/docs/overview.md` | **CHANGE** — path (moves with the directory) and content |
| `CLAUDE.md` doc-index line "Email Forwarding Plugin" + link | **CHANGE** — title and `plugins/inbound_email/docs/overview.md` path |
| `CLAUDE.md` inbound-email testing instructions | **CHANGE** — handled by the local-mailbox spec / Mailgun retirement |
| `specs/implemented/email_forwarding_*` (e.g. `email_forwarding_pgsql_credential.md`) | **KEEP** — frozen historical specs, never edited |

## 13. The local-mailbox spec

`specs/email_forwarding_local_mailbox.md` is still unimplemented. Rename it to
`specs/inbound_email_local_mailbox.md` and update its identifiers to the new
scheme: class `InboundEmailMessage`, table `iem_inbound_email_messages`
(prefix `iem`), settings `inbound_email_mailbox_retention_days` /
`inbound_email_mailbox_max_per_window`, files under `plugins/inbound_email/`.

## 14. Things explicitly KEPT (the forwarding-feature layer)

Consolidated, so a future reader does not mistake these for missed renames:

- `SRSRewriter` class and `includes/SRSRewriter.php`
- `forwardEmail()`, `forwardToCatchAll()`, `handleSRSBounce()` methods
- `*_forward_count`, `*_last_forward_time` columns (with the new prefix)
- `record_forward()` method
- `STATUS_FORWARDED`, `STATUS_BOUNCE_FORWARDED` log-status values
- `outbound_forwarding_relay` provisioner key
- `inbound_email_forwarding_*` settings (SMTP relay, rate limits, max destinations)
- `EmailForwardingHealth::checkForwardingRelay()` → method name kept on the renamed class
- `joinery` Postfix transport, `joinery-domains.cf`

## 15. Mailgun pathway — no coupling to this rename

The future local-mailbox message table uses prefix `iem` (table
`iem_inbound_email_messages`), and the Mailgun inbound-test table is
`iem_inbound_emails`. These are **distinct table names** — they coexist in
Postgres without conflict, and the shared three-letter model prefix is
cosmetic, not a database-level collision.

Therefore this rename does **not** need to touch or sequence around the Mailgun
pathway at all. Mailgun retirement belongs entirely with the local-mailbox
feature (it is that feature's functional replacement) and stays a clean
follow-up there — see `inbound_email_local_mailbox.md`, "Retiring the Mailgun
Inbound Pathway". The Mailgun pathway (`inbox.joinerytest.site` →
`iem_inbound_emails`) is left untouched by this migration.

## 16. Migration & Testing Plan

This rename is executed on the dev box as a **clean reinstall**. Existing
configuration is intentionally discarded — it is *not* migrated:

- **Aliases:** the two forwarding aliases `test` and `info` (both →
  `jeremy.tunnell+forwardtest@gmail.com`) are dropped and not recreated.
- **Domains:** `testforward.example.com` and `joinerytest.site` are dropped.

The plugin comes back configured for a single inbound domain,
**`dev.getjoinery.com`**.

### Phase 0 — Pre-flight

- Capture rollback state: `pg_dump` the three `ef*` tables and the
  `email_forwarding_*` rows of `stg_settings`; record the current plugin
  version (1.4.x).
- The Mailgun inbound-test pathway (`inbox.joinerytest.site` →
  `iem_inbound_emails`) is **separate and untouched** by this migration; it is
  retired later, with the local-mailbox feature.
- Branch off `main`.

### Phase 1 — Code rename

- Rename `plugins/email_forwarding/` → `plugins/inbound_email/` and the files
  within it, per §§1–13.
- Search-and-replace all identifiers (classes, `$prefix`/`$tablename`/`$pkey`,
  columns, settings keys, admin URLs, `require_once` paths, `plugin.json`).
- Validate: `php -l` + `validate_php_file.php` on every changed PHP file. Then
  grep the whole plugin for stragglers — `efa_`, `efd_`, `efl_`,
  `EmailForwarding`, `EmailForwarder`, `email_forwarding` — since raw SQL
  strings are not caught by the validator.
- `chmod` new files/dirs per repo rules.

### Phase 2 — Plugin reinstall

- On the admin Plugins page, **deactivate then uninstall** the old
  `Email Forwarding` plugin (clears its admin menu, settings, PluginManager
  registration).
- The renamed directory surfaces as a new plugin, `Inbound Email` — **install
  and activate** it.
- Run **"Sync with Filesystem"** (or `update_database`) to create the
  `iea_/ied_/iel_` tables; the `inbound_email_*` settings auto-seed from
  `plugin.json`.
- **DB cleanup — needs explicit confirmation per repo DB rules:** `DROP` the
  old `efa_/efd_/efl_` tables; `DELETE` any orphaned `email_forwarding_*` rows
  the uninstall left in `stg_settings`.
- Result: the two aliases and two domains are gone — the intended clean slate.

### Phase 3 — Configure receiving for `dev.getjoinery.com`

- **DNS (manual, on `getjoinery.com`):** add an `MX` record for
  `dev.getjoinery.com` pointing at this host, plus an SPF `TXT`. This is
  independent of the website's `A` record — mail routes here even if/before
  the site itself moves to that domain.
- In **Admin > Emails > Incoming > Domains**, add `dev.getjoinery.com`, enabled.
- Set `inbound_email_enabled = 1`; set `inbound_email_srs_secret` and enable
  SRS if forwarding will be used.
- Re-run `provisioning/install_email.sh` from inside `plugins/inbound_email/`
  so Postfix's pipe transport points at `utils/inbound_email_handler.php` and
  the pgsql map at `ied_inbound_email_domains`.
- Confirm the Plugins-page provisioner badges: inbound mail server up, DNS
  records green for `dev.getjoinery.com`.

> With both old aliases discarded and the local-mailbox feature not yet built,
> a freshly-configured `dev.getjoinery.com` has **no delivery target** —
> unmatched mail is rejected/discarded per `efd_reject_unmatched`. This plan
> sets up *reception*; deciding what received mail does next (recreate
> forwarding aliases, or ship the local-mailbox catch-all) is a follow-on.

### Phase 4 — Verification

- Syntax + validator pass clean (Phase 1); `logs/error.log` shows no `Fatal`
  or `InboundEmail` errors.
- Admin: the Incoming menu loads under the new URLs; Domains / Aliases / Logs
  tabs render.
- DNS: `dig MX dev.getjoinery.com` resolves to this host.
- **End-to-end** (temporary test alias): create one alias
  `hello@dev.getjoinery.com → jeremy.tunnell+ietest@gmail.com`, send it a
  message from an external account, and confirm it arrives at the destination
  and writes an `iel_inbound_email_logs` row with `STATUS_FORWARDED`. Remove
  the test alias afterward — or keep it as the first deliberate real alias.
- Postfix: `/var/log/mail.log` shows the message accepted for
  `dev.getjoinery.com` and piped to `inbound_email_handler.php`.
- The `PurgeOldInboundEmailLogs` scheduled task appears in the task runner.

### Rollback

Before Phase 2's table drop, rollback is cheap: revert the branch, restore the
directory, reactivate `Email Forwarding`. **Do not drop the old `ef*` tables
until Phases 1–3 verify green** — after the drop, rollback requires restoring
them from the Phase 0 `pg_dump`.

### Sequencing

Run this migration as its own discrete change, **before** implementing the
local-mailbox feature, so that feature is built on the final names and no new
code is ever written under the old ones. After this lands, rename and update
`specs/inbound_email_local_mailbox.md` to match.
