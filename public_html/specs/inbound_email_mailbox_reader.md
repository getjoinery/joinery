# Gmail-Style Mailbox Reader for the Inbound Email Plugin

## Overview

Replace the Inbound Email plugin's flat "Mailbox" table with a **Gmail-style
mail reader**: a conversation list, a reading pane, threading, read/unread and
star state, and search — a real inbox UI over the messages the plugin already
stores in `iem_inbound_email_messages`.

Critically, the reader is **multi-mailbox from day one**. Each address is its own
mailbox (beth@, legal@), a user can hold several, and a mailbox can be **shared
by several users** (a team `legal@` that Beth and Bob both work). Access is an
explicit **grant** of a user to an address; the left-rail switcher lets a viewer
flip between the mailboxes they've been granted, each scoped and badged
independently.

> Assumes the `inbound_email` rename and `inbound_email_local_mailbox` features
> are in place (they are — plugin is at v1.8.0, the `iem` store and admin
> Mailbox tab exist). This spec supersedes the current
> `admin_inbound_email_mailbox.php` table view.

## Motivation

The current Mailbox tab (`admin/admin_inbound_email_mailbox.php`) is a paged
table: one row per message, click through to a separate detail page. For a team
using local mailboxes as their actual inboxes that is painful — no threading, no
read/unread, no reading pane, no star, no real search, and no notion that
`beth@` and `legal@` are *different accounts* belonging to *different people*.

A Gmail-style reader makes the stored mailboxes usable as day-to-day inboxes, and
the grant-based access model makes "who can read which address" a real,
enforced thing rather than "every admin sees everything."

## Core model

Three decisions define the whole design; everything else follows mechanically.

**1. A mailbox *is* an address (alias).** There is no separate "mailbox"
container entity — the alias *is* the mailbox. `beth@` and `legal@` are two
mailboxes because they are two aliases. (Aggregating several addresses into one
named inbox — `legal@` + `lawsuits@` → one "Legal" view — is a deliberate
non-goal; see Out of scope.)

**2. Access is a many-to-many grant.** A new `ieg` grant table links **users ↔
aliases**. `beth@` has one grant (Beth); `legal@` has two (Beth, Bob). A viewer's
accessible mailboxes are the aliases they hold a grant for. Permission-10
superadmins additionally get an **all-access** oversight view (every mailbox plus
a merged "All mail"), without needing a grant row per address.

**3. Read/star state lives on the message row, shared per mailbox.** This is the
key simplification, and it falls directly out of how inbound mail is already
stored:

- The router processes mail **per envelope recipient** and writes **one `iem`
  row per (message, recipient)**, each linked to exactly one alias via
  `iem_iea_inbound_email_alias_id` (dedup is `UNIQUE (iem_message_id_header,
  iem_recipient)`). An email to both `beth@` and `legal@` becomes **two rows**,
  one per mailbox — a row never lives in two mailboxes.
- Read state on a shared mailbox is **shared** among everyone with access (team-
  inbox semantics: you can see what a colleague already handled).

Because each row belongs to exactly one mailbox and that mailbox's accessors
share its state, read/star is simply **a property of the message row** — three
columns on `iem`, no per-viewer state table, no owner-key, no join, no lazy-row
bookkeeping. Mark-read is a plain `UPDATE`; a mailbox's unread badge is a
`COUNT(*)` over its rows.

## Architecture

```
                    MailboxViewer::fromSession($session)
                     ├─ accessibleAliasIds()    → which mailboxes (grants, or all for superadmin)
                     ├─ scopeAliasIds(?aliasId)  → alias ids this request may touch ([] = none)
                     └─ canCompose()             → false (reader is read-only for now)
                              │
   Reader UI (vanilla JS) ─fetch─►  plugins/inbound_email/ajax/mailbox_*.php
   (admin Mailbox tab,                       │
    left-rail switcher)              MailboxService(viewer)
                                     ├─ listMailboxes()  ─┐ switcher: per-alias unread/total
                                     ├─ listThreads()     │
                                     ├─ getThread()       │ all scope-checked
                                     │  (full bodies)      │ against viewer.scopeAliasIds()
                                     ├─ markRead()        │ (UPDATE iem)
                                     ├─ setStarred()      │
                                     └─ softDelete()     ─┘
                                              │
              iem_inbound_email_messages
                (+ iem_thread_key                                    ← threading)
                (+ iem_is_read, iem_is_starred, iem_read_time         ← state, per row)
              ieg_inbound_email_mailbox_grants  (user ↔ alias access)
```

`storeMessage()` (unchanged trigger path) gains thread-key computation so
conversations group correctly. No other change to how mail is received or stored.

## Data model changes

### 1. Message row — `data/inbound_email_message_class.php` (modify)

Add one threading column plus state columns. `parseEmail()` already parses
`in-reply-to` and `references` into `$parsed['headers']`; `computeThreadKey()`
consumes them in-memory at store time and persists only its verdict — the raw
headers are **not** stored (nothing reads them; they're recoverable from
`iem_raw_message` if a future re-threading pass ever needs them):

| Field | Type | Notes |
|-------|------|-------|
| `iem_thread_key` | `varchar(255)` | nullable — computed conversation root; **indexed**; the only threading column |
| `iem_is_read` | `bool` | `default => false` |
| `iem_is_starred` | `bool` | `default => false` |
| `iem_read_time` | `timestamp(6)` | nullable — set when first marked read |

Add to `MultiInboundEmailMessage::getMultiResults()`:

- `thread_key` (exact match), `subject` (ILIKE), `body` (ILIKE over
  `iem_body_plain` / `iem_body_html`), `is_read`, `is_starred` filters.
- `alias_ids` (IN-list) and `domain_ids` (IN-list) so the viewer's scope (an
  alias-id list) can constrain visibility to a set of mailboxes in one query.

Bump `@version`.

### 2. Mailbox access grant — `data/inbound_email_mailbox_grant_class.php` (new)

`InboundEmailMailboxGrant` (`SystemBase`) + `MultiInboundEmailMailboxGrant`
(`SystemMultiBase`). Prefix `ieg`, table `ieg_inbound_email_mailbox_grants`.

| Field | Type | Notes |
|-------|------|-------|
| `ieg_inbound_email_mailbox_grant_id` | `int8` | serial PK |
| `ieg_iea_inbound_email_alias_id` | `int4` | not null; FK to alias; `'unique_with' => ['ieg_usr_user_id']` |
| `ieg_usr_user_id` | `int4` | not null; FK to user |
| `ieg_create_time` | `timestamp(6)` | `default => now()` |

The `unique_with` makes `(alias_id, user_id)` UNIQUE — one grant per pair.

`$foreign_key_actions`: **cascade** on both `ieg_iea_inbound_email_alias_id` and
`ieg_usr_user_id` — a grant dies when either its mailbox or its user is deleted
(deleting `legal@` removes its grants; deleting a user removes theirs). Confirm
both cascades exist at the DB level after sync.

`MultiInboundEmailMailboxGrant::getMultiResults()` filters: `user_id`,
`alias_id`, `alias_ids` (IN-list — used to fetch all grantees of a set of
mailboxes for the admin editor).

## Router changes — `includes/InboundEmailRouter.php`

`storeMessage()` populates `iem_thread_key` from the already-parsed headers via a
new private
`computeThreadKey(array $parsed, ?string $message_id_header): ?string`:

1. If `References` is present → the **first** Message-ID token in it (thread root).
2. Else if `In-Reply-To` is present → that Message-ID.
3. Else → the message's own `Message-ID` (a singleton thread).
4. If there is no `Message-ID` at all → `null` (reader treats a null thread key
   as a singleton keyed by message id).

Truncate to 255. Out-of-order arrivals still converge because `References`
normally carries the root id. `iem_is_read` / `iem_is_starred` default false on
insert — no special handling. Bump `@version`.

**Subject-based grouping** for mail carrying no threading headers is a deliberate
non-goal — such messages show as singletons. Noted as a possible future
enhancement; not built.

## The viewer seam — `includes/MailboxViewer.php` (new, plugin)

A single small value object answering *who is looking and what may they touch*.
There is no separate scope class — a "scope" is just the list of alias ids a
request may touch, so the viewer returns that list directly.

```php
final class MailboxViewer {
    public static function fromSession(SessionControl $session): MailboxViewer;
    public function accessibleAliasIds(): array;     // mailboxes this viewer may read
    public function isAllAccess(): bool;             // superadmin oversight (perm 10)
    public function canAccess(int $aliasId): bool;
    public function scopeAliasIds(?int $aliasId): array; // request ∩ access; [] = nothing visible
    public function canCompose(): bool;              // false for now (read-only)
}
```

- `accessibleAliasIds()` — for a permission-10 superadmin, every alias id (all-
  access). Otherwise, the alias ids the viewer holds a grant for
  (`MultiInboundEmailMailboxGrant` filtered by `user_id`).
- `scopeAliasIds(?int $aliasId)` — the single chokepoint where audience becomes a
  query filter. If `$aliasId` is given **and accessible**, returns `[$aliasId]`;
  if null, returns the full accessible set (the merged "All mail" view); if the
  alias is not accessible, returns `[]` — which the service treats as "matches
  nothing," never an error path the client can distinguish from "no messages."
  **One exception, handled in the service, not here:** a superadmin viewing "All
  mail" (`$aliasId === null && isAllAccess()`) is *unconstrained* — see the
  unmatched-mail note below. That case is gated by the existing `isAllAccess()`
  flag, so `scopeAliasIds()` still only ever returns plain arrays.

`MailboxService` feeds the returned ids straight into the `alias_ids` filter on
`MultiInboundEmailMessage`. Audience logic lives **only** in this method —
nowhere else in the reader.

## Service layer — `includes/MailboxService.php` (new, plugin)

Constructed with a `MailboxViewer`; **every** method funnels through
`$viewer->scopeAliasIds(...)` so a viewer can never read or mutate a message outside its
grants. List/thread reads are complex joins+aggregations and use `DbConnector`
directly with prepared statements (per CLAUDE.md guidance for queries that don't
fit a model); single-message load and state writes use the model.

| Method | Returns / does |
|--------|----------------|
| `listMailboxes()` | Switcher data: for each accessible alias — address, domain, **unread count**, total, **any-starred**. When `isAllAccess()`, prepends an **"All mail"** pseudo-mailbox and shows an **"Unmatched"** count (rows with `iem_iea_inbound_email_alias_id IS NULL`) so a superadmin can see unrouted mail exists |
| `listThreads($aliasId, $filters, $page, $perpage)` | Conversation list within scope: groups `iem` by `iem_thread_key`. Per thread: thread key, latest subject, senders, message count, latest received time, **unread count**, **any-starred**. Latest-first. Honors filters: sender, subject, body, `unread_only`, `starred_only`. `$aliasId` null = all accessible; for a superadmin that is **unconstrained** (includes NULL-alias unmatched mail) |
| `getThread($aliasId, $thread_key)` | All in-scope messages in the thread, chronological, each with its read/star flags **and its HTML/plain body** (rendered client-side in the sandboxed iframe). Empty if the thread is outside scope |
| `messageIdsInThread($aliasId, $thread_key)` | The thread → its in-scope message ids; the only thread-expansion logic, reused by every thread-level action |
| `markRead(array $message_ids, bool $read)` | `UPDATE` in-scope rows; set `iem_read_time` on first read; returns count |
| `setStarred(array $message_ids, bool $starred)` | `UPDATE` in-scope rows; returns count |
| `softDelete(array $message_ids)` | Set `iem_delete_time` on in-scope rows; returns count |

Every mutation takes a **scope-checked id array** and returns a count — uniform
surface, no per-action thread variant. A thread-level action is just
`markRead(messageIdsInThread($aliasId, $key), true)`; single-message is
`setStarred([$id], true)`. A thread is **unread** if any of its messages is
unread (`iem_is_read = false`). Every mutation re-checks scope server-side — a
crafted id/thread for an un-granted mailbox affects nothing.

**Unmatched mail (no alias).** The router stores catch-all / store-only /
unmatched recipients with `iem_iea_inbound_email_alias_id = NULL` — rows that
belong to no mailbox. Because the reader filters by `alias_ids IN (...)`, those
rows are invisible to grant-scoped staff (correct — it isn't their address). They
remain reachable through the superadmin **"All mail"** view, which for an
all-access viewer drops the alias filter entirely (`WHERE iem_delete_time IS
NULL`), so NULL-alias rows surface there. This is the single place a query is
unconstrained, gated by `isAllAccess()`; it replaces the old flat table as the
home for triaging unrouted mail.

## AJAX endpoints — `plugins/inbound_email/ajax/` (new)

Plugin ajax routes resolve via the front controller's `/ajax/{file}` rule
(plugins checked first — confirmed in `serve.php`). Each endpoint builds
`MailboxViewer::fromSession($session)`, **enforces `$session->check_permission(5)`**
(reader is staff-only in v1), and lets the viewer's scope constrain everything.

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/ajax/mailbox_mailboxes` | GET | JSON switcher: accessible mailboxes + unread counts |
| `/ajax/mailbox_list` | GET | JSON thread list; params: `alias_id` (optional), filters, page |
| `/ajax/mailbox_thread` | GET | JSON messages for `?thread_key=` (+ optional `alias_id`), each **including its HTML/plain body** for client-side sandboxed rendering |
| `/ajax/mailbox_action` | POST | mark read/unread, star/unstar, delete; accepts `ids[]` **or** a `thread_key` (expanded server-side via `messageIdsInThread`) — **CSRF-protected** |

Raw MIME and `.eml` download continue to be served by the existing
`admin_inbound_email_message.php` detail page, which the reading pane links to
("View raw" / "Download .eml").

## Reader UI — `admin/admin_inbound_email_reader.php` + logic (new)

Becomes the **Mailbox tab**. The plugin's admin pages currently hand-roll an
identical `<ul class="nav nav-tabs">` strip in each file; this spec replaces that
duplication with the platform helper `AdminPage::tab_menu($tabs, $current)` (the
same one core settings pages use — theme-aware via `renderTabMenu()`), fed by a
**single shared tab list** so the "Mailbox" entry — now pointing at the reader —
is defined in **one** place. The old `admin_inbound_email_mailbox.php` table view
is removed (the reader subsumes its filter/list/delete/purge). The single-message
detail page is kept for raw/`.eml`/deep-link.

Layout — Gmail-style, with the mailbox switcher promoted into the left rail:

- **Left rail, top — mailbox switcher:** the accessible addresses (beth@,
  legal@, …), each with its own **unread badge**; "All mail" appears for all-
  access viewers. Selecting one scopes the entire reader to that mailbox.
- **Left rail, below — within-mailbox filters:** quick filters (All / Unread /
  Starred) and a debounced search box. Room for labels/folders later.
- **Center:** conversation list — sender(s), subject, snippet, time, **bold when
  unread**, star toggle, message-count badge for multi-message threads.
- **Right (or full-width on narrow screens):** reading pane — opens a thread,
  messages stacked chronologically, each expandable, HTML rendered in a
  **sandboxed `<iframe sandbox="" srcdoc=...>` (no `allow-scripts`)** exactly as
  the current detail page does (stored mail is fully attacker-controlled).

**Vanilla JS only** (platform rule — no jQuery/Bootstrap JS framework code).
`fetch()` against the endpoints; render client-side. Custom CSS for the panes and
switcher in a plugin asset; Bootstrap layout primitives are available in admin
but the pane chrome is bespoke. Opening a thread calls `mailbox_action` to mark
read — which, because state is per-mailbox-shared, marks it read for **everyone**
with access to that mailbox, as intended. Responsive: panes collapse to a single
column on mobile, switcher to a dropdown.

## Grant management — `admin/admin_inbound_email_alias.php` (modify)

The alias editor is where a mailbox's access list is managed. Add a **"Users with
access"** control (FormWriter multi-select of users; use `visibility_rules` /
standard FormWriter patterns — never hand-rolled HTML) that reads and writes the
`ieg` grants for that alias:

- On load: pre-select users who currently hold a grant for this alias
  (`MultiInboundEmailMailboxGrant` by `alias_id`).
- On save: diff against the submitted set — insert new grants, soft/hard-delete
  removed ones. `legal@` gets Beth + Bob; `beth@` gets Beth only.

This is the only surface for assigning mailbox access in v1; member self-service
provisioning is deferred (see Future). Bump `@version`.

## Access & permissions

- The reader, its endpoints, and grant management are all **permission-5 (admin)**
  in v1 — staff-only. Grants partition *which* mailboxes each staff member sees:
  Beth sees beth@ and legal@; Bob sees only legal@.
- **Permission-10 superadmins** get **all-access**: every mailbox in the switcher
  plus a merged **"All mail"** view, for oversight — no per-address grant needed.
- Scope is enforced **server-side** in `MailboxService` on every read and
  mutation; the client never decides visibility.

## Security considerations

- **Trust posture tightened, not loosened.** Today every admin sees all stored
  mail. With grants, a permission-5 admin sees only mailboxes granted to them;
  superadmins retain full visibility. Stored secrets (password-reset tokens, etc.)
  in a mailbox are visible to that mailbox's grantees — scope this deliberately
  when granting.
- **HTML bodies stay sandboxed.** The reading pane uses the same
  `sandbox=""` / no-`allow-scripts` iframe the detail page uses. Never live-render
  stored HTML.
- **CSRF** on `mailbox_action` (the only state-changing reader endpoint).
- **Scope is enforced server-side** — a crafted request for an un-granted
  `id`/`thread_key`/`alias_id` returns nothing and mutates nothing.
- Apply the platform's untrusted-input markers
  (`specs/implemented/joinery_ai_untrusted_input_markers.md`) to any path that
  surfaces stored content to an AI agent.

## Future (explicitly deferred)

The grant + scope model is the real architecture; these build *on top of* it
without reworking it:

1. **Member self-service** — open the reader (or a member-area mount in the public
   theme, tier-gated, vanilla) to non-admin members reading their own granted
   mailboxes; replace the hardcoded permission-5 endpoint gate with the viewer's
   scope. The `MailboxService`, endpoints, and grant table are reused unchanged.
   **This requires no schema change or data migration** — the grant table, the
   per-row state, and the viewer/scope seam are all keyed on the user generically
   and carry no admin-vs-member distinction. Adding non-admin users is purely
   additive code: relax the endpoint gate and add a member-area mount.
2. **Address provisioning** — UI for members to claim/manage addresses, which
   simply writes `ieg` grants.
3. **Per-person read state on a shared mailbox** — if ever wanted (Beth's
   "unread" differing from Bob's on the same `legal@` message), this is the one
   thing the per-row state model would need reworking for: reintroduce a
   per-(message, user) state table. Explicitly *not* wanted now — shared state is
   the chosen semantics.
4. **Compose / reply / forward** — outbound already exists via `SystemMailer`;
   threading is built so a reply slots in. `MailboxViewer::canCompose()` is the
   seam, returning `false` today.
5. **Aggregated mailboxes** — a named inbox spanning several addresses; would add
   a container entity above aliases.

## Testing

- **Grant model** (`plugins/inbound_email/tests/inbound_email_mailbox_grant_test.php`) — CRUD;
  the `(alias_id, user_id)` UNIQUE constraint; cascade delete with both the alias
  and the user.
- **Viewer / scope** — `accessibleAliasIds()` returns granted aliases for a
  permission-5 user and all aliases for a permission-10 superadmin;
  `scopeAliasIds($ownAlias)` returns `[$ownAlias]`; `scopeAliasIds($foreignAlias)`
  is `[]`; `scopeAliasIds(null)` is the union of accessible; `getThread()` on an
  un-granted thread returns empty.
- **Thread key** — `computeThreadKey()` for: `References` present (root = first
  token), `In-Reply-To` only, own `Message-ID` only, and no `Message-ID` (null).
- **Unmatched mail** — a NULL-alias row is invisible to a grant-scoped staff
  viewer and to a single-mailbox selection, but appears in a superadmin's "All
  mail" view and is reflected in the "Unmatched" count.
- **listMailboxes / listThreads** — correct per-mailbox unread counts; grouping
  by thread key; any-starred flags; search filters (subject/body/sender,
  unread_only, starred_only).
- **Mutations** — `markRead`/`setStarred` flip the row and are visible to every
  grantee of that mailbox (shared state); marking a thread read clears its unread
  count; soft delete hides from the list; all reject out-of-scope ids.
- **Endpoints** — permission-5 gate; CSRF rejection on `mailbox_action`;
  out-of-scope id/thread/alias returns empty; JSON shapes stable.
- **Grant editor** — saving the alias diffs the user set correctly (adds/removes
  grants); `legal@` shared by two users; `beth@` private to one.
- **Router regression** — existing `storeMessage()`/dedup tests still pass; new
  threading + state columns populate without disturbing the dedup path.

Run `php -l` and `validate_php_file.php` on every created/modified PHP file.

## Files

**Self-contained invariant:** every file below — code, assets, and tests — lives
under `plugins/inbound_email/`. The feature touches **no core code**. It reuses
core read-only (`AdminPage::tab_menu()`, `SessionControl`, `FormWriter`,
`SystemBase`), routes its ajax plugin-first through the existing `/ajax/{file}`
rule (no `serve.php` edit), and applies schema via plugin sync (no core
migration). The only cross-boundary element is the grant table's FK/cascade onto
the core `users` table, declared plugin-side. (The declarative-tabs core
enhancement is intentionally *not* here — see `specs/declarative_admin_tabs.md`.)

### To create
| File | Purpose |
|------|---------|
| `plugins/inbound_email/data/inbound_email_mailbox_grant_class.php` | `InboundEmailMailboxGrant` + Multi; user↔alias access grants |
| `plugins/inbound_email/includes/MailboxViewer.php` | Viewer seam: accessible mailboxes + `scopeAliasIds()` from session + grants (single class, no separate scope object) |
| `plugins/inbound_email/includes/admin_tabs.php` | `inbound_email_admin_tabs()` — the one shared `['Title' => '/url']` tab list rendered via `AdminPage::tab_menu()`; "Mailbox" → reader |
| `plugins/inbound_email/includes/MailboxService.php` | Scope-checked switcher/list/thread/message reads + state mutations |
| `plugins/inbound_email/ajax/mailbox_mailboxes.php` | JSON switcher (accessible mailboxes + unread) |
| `plugins/inbound_email/ajax/mailbox_list.php` | JSON thread list |
| `plugins/inbound_email/ajax/mailbox_thread.php` | JSON messages in a thread |
| `plugins/inbound_email/ajax/mailbox_action.php` | POST: read/star/delete (CSRF) |
| `plugins/inbound_email/admin/admin_inbound_email_reader.php` | Gmail-style reader shell |
| `plugins/inbound_email/logic/admin_inbound_email_reader_logic.php` | Reader page logic (CSRF token, initial switcher data) |
| `plugins/inbound_email/admin/assets/mailbox_reader.js` | Vanilla-JS reader |
| `plugins/inbound_email/admin/assets/mailbox_reader.css` | Pane + switcher styling |
| `plugins/inbound_email/tests/inbound_email_mailbox_grant_test.php` | Grant model + UNIQUE + cascade |
| `plugins/inbound_email/tests/mailbox_reader_test.php` | Viewer/scope, listThreads, endpoints, grant editor |

### To modify
| File | Change |
|------|--------|
| `plugins/inbound_email/data/inbound_email_message_class.php` | Add `iem_thread_key` (indexed), `iem_is_read`, `iem_is_starred`, `iem_read_time`; add `thread_key`/`subject`/`body`/`is_read`/`is_starred`/`alias_ids`/`domain_ids` filters; bump `@version` |
| `plugins/inbound_email/includes/InboundEmailRouter.php` | `storeMessage()` populates threading columns; `computeThreadKey()` helper; bump `@version` |
| `plugins/inbound_email/admin/admin_inbound_email_alias.php` | Add "Users with access" multi-select; read/write `ieg` grants on load/save; bump `@version` |
| `plugins/inbound_email/admin/admin_inbound_email_setup.php`, `admin_inbound_email.php`, `admin_inbound_email_domains.php`, `admin_inbound_email_alias.php`, `admin_inbound_email_logs.php`, `admin_inbound_email_message.php` | Replace each page's hand-rolled `<ul class="nav nav-tabs">` with `echo AdminPage::tab_menu(inbound_email_admin_tabs(), '<active>')`; the shared tab list points "Mailbox" at `admin_inbound_email_reader` |
| `plugins/inbound_email/plugin.json` | Bump `version` (minor → 1.9.0) |

### To delete
| File | Reason |
|------|--------|
| `plugins/inbound_email/admin/admin_inbound_email_mailbox.php` + its logic | Superseded by the reader (filter/list/delete/purge all subsumed) |

### Schema
Applied by **"Sync with Filesystem"** on the admin Plugins page (or
`update_database`) once the data classes are updated — no migration. Confirm the
new `iem` columns, the `iem_thread_key` index, and both `ieg` FK cascades
(→ alias, → user) exist after sync.

## Documentation

- Add a **"Mailbox Reader"** section to `plugins/inbound_email/docs/overview.md`
  (after "Local Mailbox"): the reader UI, the mailbox-per-address model, grants
  and the switcher, threading, shared read/star state, and the permission model
  (perm-5 grants, perm-10 all-access). Spell out the `MailboxViewer` seam
  (`accessibleAliasIds()` / `scopeAliasIds()`) and the deferred member-mount path so a
  future developer opens it to members without re-deriving the design.
- Cross-reference the grant-based mailbox model from `docs/email_system.md`.

## Versioning

- `plugin.json`: minor bump (new feature, backward compatible — existing messages
  thread as singletons until re-stored; default unread/unstarred; grants start
  empty so superadmins still see everything) → **1.9.0**.
- Bump `@version` on each modified data/include/admin file.

## Out of scope

- **Member self-service / public-area mount** — see Future; only the grant + scope
  seams it reuses are built.
- **Per-person read state on a shared mailbox** — shared-per-mailbox is the chosen
  semantics; the per-row model would need a state table to change this.
- **Compose / reply / forward** — read-only for now; `canCompose()` is the seam.
- **Aggregated mailboxes** (one named inbox over several addresses) — mailbox ≡
  alias here.
- **Labels/folders beyond All/Unread/Starred** — left rail has room; not built.
- **Subject-based threading fallback** for header-less mail.
- **Attachment extraction** — unchanged from `inbound_email_local_mailbox.md`:
  attachments live in `iem_raw_message`; the `.eml` download covers them.
