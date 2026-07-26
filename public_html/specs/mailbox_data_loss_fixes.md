# Mailbox — data-loss hardening

## Status

Active. Decided fixes below are ready to build. Open items are resolved one at a
time with the owner and moved up into "Decided" as they're settled.

## Motivation

Before the owner migrates their personal mail onto the mailbox plugin, we audited
every path that could lose mail: inbound reception (before durable storage),
purge/deletion tasks, the seal/unseal lifecycle, and cascade deletes. The
reception, sealing, and IMAP paths are sound (synchronous store-before-ack;
never-plaintext sealing; poll-only IMAP that never deletes its source). This spec
collects the concrete gaps that turn "a mailbox" back into "loses mail," and
neutralizes them.

The through-line for most of these: the local-mailbox store was originally built
as **test-capture** — "store the body somewhere queryable" for the inbound-email
test harness (`specs/implemented/inbound_email_local_mailbox.md` § Motivation). It
grew into a real durable mailbox, but several housekeeping behaviors from the
test-capture era rode along unchanged and are wrong for real mail.

---

## Decided fixes (no open questions)

### Fix 1 — Remove the retention purge entirely

**Problem.** `PurgeOldMailboxMessages` **hard-deletes** (irreversible per-row
`permanent_delete()` — reclaims attachment File bytes and the raw object too)
*every* stored message older than `mailbox_retention_days`, which **defaults to
14** and is `14` on the live deployment. No exemption for read/unread, starred,
Sent copies, or drafts. It was modelled on the log-purge task
(`specs/implemented/inbound_email_local_mailbox.md` § Scheduled Task) — log-style
housekeeping for a table of throwaway test captures, which is exactly wrong for a
personal archive. The task is not currently in `sct_scheduled_tasks`, but its
`.json` descriptor advertises `default_frequency: daily` / `03:45`, so it is one
"discover tasks" click away from silently deleting everything older than two
weeks.

**Decision.** Delete the retention-purge feature outright — task, descriptor, and
setting. If age-based retention is ever wanted it will be redesigned as opt-in,
per-mailbox, soft-delete (tracked as a future spec, not here).

**Changes (live tree only — never touch `specs/implemented/*`):**

- Delete `plugins/mailbox/tasks/PurgeOldMailboxMessages.php`.
- Delete `plugins/mailbox/tasks/PurgeOldMailboxMessages.json` (the discovery
  descriptor — removing it prevents re-registration).
- Remove the `mailbox_retention_days` setting declaration from
  `plugins/mailbox/plugin.json` (the `retention` group; keep `mailbox_max_per_window`
  — see Fix 3).
- Remove the number input from `plugins/mailbox/admin/admin_mailbox_settings.php:94-95`.
- Remove `'mailbox_retention_days' => 0` from the `$int_keys` clamp map in
  `plugins/mailbox/logic/admin_mailbox_settings_logic.php:37`.
- Remove the `mailbox_retention_days` / `PurgeOldMailboxMessages` line from
  `plugins/mailbox/docs/overview.md:720`.
- Migration in `plugins/mailbox/migrations/migrations.php` (or a standalone
  migration): `DELETE FROM stg_settings WHERE stg_name = 'mailbox_retention_days'`
  and `UPDATE sct_scheduled_tasks SET sct_delete_time = now() WHERE
  sct_task_class = 'PurgeOldMailboxMessages' AND sct_delete_time IS NULL`
  (defensive — removes any registration that exists on some deployment).

**Verification.** `grep -rn 'PurgeOldMailboxMessages\|mailbox_retention_days'`
over the live tree returns nothing outside `specs/implemented/`. Settings page
renders without the field. `php tests/run.php safe` green.

### Fix 2 — Postfix pipe handler must catch `\Throwable`, not `Exception`

**Problem.** `plugins/mailbox/utils/inbound_email_handler.php:51` wraps the router
call in `catch (Exception $e)`. A PHP `Error` (e.g. `TypeError`, an OOM-adjacent
fatal in a dependency) is not an `Exception`, so it escapes the handler and the
process exits 255. Postfix does not read 255 as its tempfail code (75), so it can
treat the delivery as a **permanent failure and bounce it** instead of retrying —
mail the box would have accepted on the next attempt is lost.

**Decision / change.** Change the catch to `\Throwable` and keep the `exit(75)`
(temp failure → Postfix retries). One-line fix; mirrors the webhook dispatcher,
which already catches `\Throwable` and returns 503.

**Verification.** Force a `TypeError` in the router under the pipe path (or unit-
assert the catch type); confirm exit code 75.

### Fix 3 — Store volume cap: off by default, and defer instead of drop

**Problem.** `mailbox_max_per_window` (default `500`/hour, live `500`) caps stores
per domain within the forwarding window. When the count is at/over the cap,
`InboundEmailRouter::handleStoreOnly` logs `STATUS_STORE_CAPPED` and `return 0`
(accepted) — the sender/MTA is told "delivered," the message is **not stored and
never retried**. It bites exactly during an initial bulk import or any legitimate
burst.

**Decision (owner: "both").** Off by default, and any cap that *is* set defers
rather than drops — so no configuration can silently lose mail.

**Changes:**

- `plugins/mailbox/plugin.json`: change `mailbox_max_per_window` default `"500"` →
  `"0"` (0 = disabled).
- Migration: `UPDATE stg_settings SET stg_value = '0' WHERE stg_name =
  'mailbox_max_per_window' AND stg_value = '500'` — adopt the new default only on
  deployments still on the old default; a deliberately-customized cap is left
  alone. (Pre-launch, no production data to preserve — see
  `project_no_production_users`.)
- `InboundEmailRouter::handleStoreOnly`: when `$count >= $cap`, return **75**
  (temp failure) instead of `0`. Via the existing exit-code→HTTP mapping this is
  Postfix retry (75) and webhook 503 (provider retry) — the cap throttles, never
  drops. A sustained over-cap flood then bounces at the *sender* after its retry
  window (sender is informed; we never silently lose).
- Logging: the current `STATUS_STORE_CAPPED` row would now repeat on every retry.
  Mirror the transient-DB-failure path in `handleStoreOnly` (which deliberately
  does **not** log per-retry to avoid noise) — suppress the per-retry log, or log
  `STORE_CAPPED` once on entry into the capped state. Implementation detail; no
  decision needed.

**Verification.** With a cap of 1 and two messages in the window, the second
returns 75 (pipe) / 503 (webhook) and is retried, not dropped; it stores once the
window rolls. Fresh install seeds `mailbox_max_per_window = 0`.

### Fix 4 — Make the message store atomic (row + attachments/raw in one transaction)

**Problem.** `InboundEmailRouter::storeMessage` commits the message row (body
present, `iem_raw_message` empty) and only *then* runs `persistRawAndManifest()`
to split attachments into Files / write the raw. If the **process dies** (kill,
OOM, deploy restart, power loss) between the commit and the completion of
attachment persistence, the row survives without its attachments. Because dedup
keys on `(iem_message_id_header, iem_recipient, iem_direction)`, the sender's retry
hits the unique violation and `storeMessage` returns `dedup=true` **before**
`persistRawAndManifest()` — so the retry, which carries the attachments, is
discarded and the row can never be repaired. On the sealed lean-record happy path
the Files are the *only* copy of the attachments, so this is real content loss.

**Decision (owner: single transaction).** Wrap the entire store — insert, seal (if
sealing), and `persistRawAndManifest()` — in one DB transaction that commits only
once the message is fully materialized. Process death before commit rolls the whole
unit back (no row), so the sender's retry rebuilds from a clean slate. The dedup
short-circuit then becomes correct: a committed row always has its attachments, so
a dedup hit genuinely means "fully stored."

**Changes (`InboundEmailRouter.php`):**

- Non-sealing path (today autocommit): open a transaction before `CreateEntry`
  and commit after `persistRawAndManifest()` returns.
- Sealing path: it already opens a transaction for insert+seal but commits at the
  current `$db->commit()` (≈line 466) **before** attachments. Move that commit to
  **after** `persistRawAndManifest()` so attachments join the same unit. The
  in-memory DEK is already in scope for the whole method, so sealed attachments
  seal within the transaction with no key recovery needed.
- Dedup (23505): on unique violation, `rollBack()` then return `dedup=true` (as
  today). No change to dedup semantics.
- `persistRawAndManifest()` keeps its internal never-abort fallback chain (lean →
  raw-to-disk → inline) unchanged — it simply now runs before the commit. Its
  `File`/manifest inserts and the `extractAttachmentsToFiles` rollback
  (`permanent_delete`, nesting-aware via `!$db->inTransaction()`) all join the open
  transaction.

**Tradeoffs to note at build:**

- On rollback, attachment `File` bytes and any `RawMessageStore::write()` `.eml`
  already written to LOCAL disk are orphaned (their rows rolled back). Reclaimable
  garbage, never data loss — accept, or reclaim via an orphan-file sweep.
- The transaction now spans local disk I/O (attachment writes). Mail ingest is a
  cron-spool / pipe / webhook path, never a hot web request, and `write()` targets
  LOCAL only (no bucket/network I/O at ingest), so the longer transaction is
  acceptable.

**Verification.** Simulate a mid-store abort (throw after insert, before manifest)
and confirm no row survives and a re-delivery stores fully with attachments.
Sealed and non-sealed both. `php tests/run.php safe` green; extend
`inbound_raw_storage_test` / attachment-storage tests to cover the atomic rollback.

### Fix 5 — `forward_and_store`: store first, then forward

**Problem.** In `processEmail`'s forward path, `forwardEmail()` runs first and
`storeMessage()` is a best-effort tail (try/catch, `error_log`, exit stays 0). A
store failure silently loses the retained copy — the sender already saw exit 0
from the forward, so nothing retries. Built forward-first to avoid double-
forwarding on a retry. **Two silent-copy-loss paths exist today:** (a) a store
exception after a successful forward; (b) the forward-only gates that sit *before*
the store — a rate-limited forward (`checkAliasRateLimit`/`checkDomainRateLimit`,
returns 0) or a missing-`From` forward (returns 0) exits **before** the store is
reached, so the copy is never made.

**Decision (owner: store-first).** For a `forward_and_store` alias, persist the
copy before anything on the forward side runs:

1. Spam-judged → store, suppress forward, return 0 (unchanged — already handled in
   the spam-held branch).
2. **Store the copy.** On a store exception return **75** (pipe) / **503**
   (webhook) so the sender retries. The forward has not run on this pass, so the
   retry's forward is the *first* forward — no duplicate. A retry that re-stores
   dedups (23505) and proceeds.
3. **Forward-side gates** (rate limit, `From` presence) now run *after* the store
   and gate the forward only: if blocked, log the outcome and return 0 — the copy
   is already saved (this closes silent-loss path (b)).
4. **Forward** best-effort exactly as today: `forwardEmail()`, log
   `FORWARDED`/`ERROR`, `record_forward()` on all-success, return 0 regardless of
   forward outcome.

**Changes (`InboundEmailRouter::processEmail`, the `$forwards && $stores` case).**
Restructure so the store precedes the rate-limit/From gates and the forward. Pure-
forward (`$forwards && !$stores`) and pure-store (`!$forwards`) paths are
unchanged. Interacts cleanly with Fix 4: the store is the atomic unit; the forward
stays outside it.

**Cost / tradeoff.** If the store backend is genuinely down, `forward_and_store`
forwarding is *delayed* (sender retries) until it recovers, rather than forwarding
and dropping the copy — the correct priority when the copy is the point.

**Verification.** forward_and_store with a store forced to throw returns 75 and
does not forward; on retry it stores once and forwards once (no duplicate). A rate-
limited forward_and_store message is stored (not skipped). `php tests/run.php safe`
green.

### Fix 6 — Relay orphan: hold recoverable mail instead of deleting it

**Problem.** `RelaySpoolConsumer::ingestOne` returns `'orphan'` for two very
different cases, and the pull loop acks (deletes from the relay) both because they
are non-throwing outcomes (`RelaySpoolConsumer.php:103-105`):

- **Unroutable** — empty/malformed recipient, no `@` (`:158-160`). Genuinely
  undeliverable.
- **Domain disabled or not-yet-configured** — the domain row is missing or
  `ied_is_enabled = false` (`:186-188`). A *temporarily* or accidentally disabled
  domain thus has its still-sealed relay-held mail **permanently deleted** instead
  of held until the domain returns. This violates the copy-then-delete-after-store
  principle the happy path relies on.

**Decision (owner: hold-on-relay + backstop).** Split the orphan outcome:

- **Unroutable** → ack-drop as today, but with an explicit loud log line (never a
  silent drop).
- **Domain disabled / unconfigured** → new **`hold`** outcome: do **not** ack, so
  the blob stays on the relay and the next pull after the domain is re-enabled
  stores it. `hold` is distinct from the thrown-error path (it must not inflate the
  error count or log as an error each pass).
- **Backstop** so a permanently-disabled domain can't accumulate blobs forever:
  an age check against `.meta`'s `received_utc`. Past a generous grace window
  (`mailbox_relay_orphan_grace_days`, default `30`) the blob is aged-out — ack-drop
  with a loud log. Surface a per-pull **held count** (tally of `hold` outcomes) on
  the relay row / `InboundEmailHealth` so an operator sees mail waiting on a
  disabled domain.

**Changes:**

- `RelaySpoolConsumer::ingestOne`: return `'unroutable'` (recipient case),
  `'hold'` (domain disabled/unconfigured and within grace), or `'aged_out'`
  (past grace) instead of the single `'orphan'`.
- Pull loop (`:99-110`): ack `stored`/`pending`/`dedup`/`bounce`/`unroutable`/
  `aged_out`; do **not** ack `hold`. Count `hold` outcomes and record the tally.
- Add `mailbox_relay_orphan_grace_days` (default `30`) to `plugin.json`.
- `InboundEmailHealth`: expose the held count (and, if cheap, the domain names)
  from the last pull.

**Verification.** Seal a message for a domain, disable the domain, pull → outcome
`hold`, blob remains on the relay, not acked, health shows a held count. Re-enable
→ next pull stores it. A blob older than the grace window → `aged_out`, acked, loud
log. Malformed recipient → `unroutable`, acked, logged. Relay/Fortress-path only.

### Fix 7 — Unresolvable Fortress owner: hold, don't store an invisible row

**Problem.** In `RelaySpoolConsumer::ingestOne`, the Fortress (`key_kind = 'user'`)
branch resolves the single owner from the alias, then falls back to
`ownerByPublicKey(.meta public_key)`. If both fail (`:200-207`) it stores an
**ownerless** pending row (`iem_sealed_owner_user_id = null`) and acks. The blob is
durable in `iem_relay_sealed_raw`, but `DeferredIngest::pendingIds()` selects by a
specific owner, so a null-owner row is **never parsed and never appears in the
reader** — durable but permanently invisible. And because the blob is sealed to one
vault's public key, if no vault matches it *no one* can decrypt it, so assigning a
fallback owner cannot help — "ownerless" means the grant was removed or the vault
was deleted/rotated away.

**Decision (owner: fold into Fix 6's hold).** Do not store ownerless rows. When
neither the alias nor the seal key resolves an owner, return the **`hold`** outcome
(Fix 6): don't ack, surface in health, age out after the grace window with a loud
log. A restored grant or re-enrolled vault lets a later pull resolve the owner and
store it correctly; otherwise it ages out loudly instead of sitting invisibly
stuck. No invisible state, no owner-assignment admin UI (which couldn't decrypt a
no-matching-vault blob anyway).

**Changes:**

- `ingestOne` user branch: owner resolves → `storeRelayPending` as today; owner is
  null → return `hold` (do not store, do not ack), keeping the loud log. The pull
  path therefore never stores an ownerless row.
- One-time cleanup for any pre-existing null-owner pending rows (their relay blobs
  are already gone, so they can't be re-held): retry `ownerByPublicKey` per row and
  assign+drain if a vault now matches; otherwise log for the operator. Pre-launch
  there are likely none — best-effort, low priority.

**Verification.** Pull a Fortress blob whose alias has no single owner and whose
seal key matches no vault → outcome `hold`, not stored, not acked, health shows it.
Add a matching grant/vault → next pull stores and it drains. Relay/Fortress-path
only.

### Fix 8 — IMAP mirror deletion: explicit keep/remove choice, never silent orphan

**Problem.** IMAP-mirrored (`iem_raw_storage_driver = 'remote'`) messages store the
decoded body text in the row but fetch attachments/raw on demand via the
account+uid+folder locator (`storeExtracted`, `InboundEmailRouter.php:1142-1156`).
The message FK `iem_iia_inbound_email_account_id → 'null'`
(`inbound_email_message_class.php:159`) nulls that locator when the account is
deleted, leaving rows whose **attachments no longer load**. And deleting an *alias*
permanent-deletes its IMAP account (`inbound_imap_account_class.php:118`), silently
orphaning every mirrored message it owned. Because IMAP is a mirror, the source
server still holds the full mail and the body text remains locally — so this is
degraded/confusing rows, not destruction, but it happens silently.

**Decision (owner: explicit choice).** Replace the silent null-orphan cascade with
a guided choice at delete time. When deleting an IMAP account — or an alias that
owns one — that has reference-backed messages, require a choice (FormWriter,
guided controls, no explainer prose):

- **Keep (default/recommended)** → *materialize*: while the account is still
  connected, fetch each mirrored message's full RFC822 and store a self-contained
  copy (lean record / `local`, attachments as Files) via the existing
  persist path, then delete the account. Messages stay fully functional.
- **Remove** → permanent-delete the mirrored message rows along with the account
  (per-row, so attachment File/raw reclaim runs). The mail remains safe on the
  source server.

Never leave null-orphaned rows.

**Changes:**

- Admin delete actions for the IMAP account (`admin_mailbox_imap` /
  `admin_mailbox_imap_edit` logic) and the alias (`admin_mailbox_alias` /
  `admin_mailbox_domains` logic): detect reference-backed messages and present the
  keep/remove choice; the alias→account cascade routes through the same prompt.
- Materialize helper (in `ImapSyncer`/`ImapIngestor` or a `MailboxService` method):
  per `remote` message, fetch full raw and convert to self-contained. **Requires
  the account connectable** — if it can't connect, refuse the delete with a clear
  message rather than proceeding. **Preserves the message's sealed/plaintext
  state**; a sealed message needs the owner's unlock window for the DEK, so
  materialize runs in-window or defers attachment sealing (reuse the deferred-
  ingest posture) — build detail, no decision.
- The residual `iem_iia_inbound_imap_account_id → 'null'` FK stays only as a safety
  net; the explicit path handles messages first so it is no longer the primary
  behavior.

**Verification.** Delete an IMAP account with mirrored messages → prompted; Keep →
messages become self-contained and attachments still load after deletion; Remove →
rows gone, source unaffected. Deleting the owning alias routes through the same
prompt. Disconnected account → delete refused with a clear message.

### Fix 9 — Gmail filter import: flag delete-action filters, import them disabled

**Problem.** The Gmail filter importer maps `shouldTrash → fil_action_delete`
(`inbound_email_filter_class.php`). An imported delete filter fires immediately on
ingest (Postfix + webhook), auto-trashing matching new mail; saving one with "apply
to existing" can retroactively soft-delete existing mail. The delete is a *soft*
delete, and with Fix 1 removing the purge it stays recoverable — so this is a
surprise, not loss. But a "delete on arrival" rule silently switching on during a
migration is unwanted.

**Decision (owner: flag + default-off).** At import, flag filters that carry a
delete/trash action distinctly and import them **disabled**, pending an explicit
enable. All other filters import normally (enabled as before). No delete rule
silently activates.

**Changes:**

- Gmail filter import path: when a mapped filter has `fil_action_delete`, create it
  with its enabled flag OFF and mark it for review; leave all other imported
  filters unchanged.
- Import result surface: show the flagged set — "N imported filters delete mail;
  review and enable" — guided, minimal helptext (no explainer prose).

**Verification.** Import a filter set containing a trash rule → the trash rule lands
disabled and flagged; non-delete rules land enabled. Enabling it manually then
soft-deletes matching mail (recoverable). `php tests/run.php safe` green.

---

## Resolution

All audited findings are resolved into Fixes 1–9 above. Suggested build order (a
few have dependencies):

1. **Fix 1** (delete the retention purge) and **Fix 3** (cap off + defer) — these
   also carry the immediate live-setting safety for migration
   (`mailbox_retention_days` and `mailbox_max_per_window` → `0`), so land them
   first.
2. **Fix 2** (Postfix `\Throwable`) — trivial, independent.
3. **Fix 4** (atomic store) → then **Fix 5** (store-first forward_and_store), which
   builds on the atomic store.
4. **Fix 6** (relay orphan hold) → then **Fix 7** (ownerless Fortress), which reuses
   Fix 6's hold/health/grace mechanism.
5. **Fix 8** (IMAP mirror delete choice) and **Fix 9** (filter import flag) —
   independent, lower severity.

Fixes 6–8 are relay/Fortress/IMAP-path-specific; Fixes 1–5 and 9 apply to the
colocated Postfix path the owner's personal mail will land on first.
