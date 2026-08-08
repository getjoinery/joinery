# AI Recipes: Multi-Mailbox Bindings + the Area AI Panel

## What this is, in plain terms

Two connected changes:

1. **A recipe can watch more than one mailbox.** Today each email recipe
   (security scan, triage, schedule) is pointed at exactly one address from the
   recipes dashboard. After this change a recipe carries an explicit list of
   addresses, and both the dashboard and the email page's AI panel edit that
   same list.

2. **An AI panel on the email page.** A slide-over panel, opened from the mail
   reader's header, that shows the signed-in user's AI recipes relevant to
   email and lets them switch each one on or off *for the mailbox they are
   currently looking at* — without visiting the recipes dashboard. The panel is
   a general component: the calendar and drive pages mount the same thing later
   with their own context. It also reserves the layout slot for a future
   "type in a task" composer (designed here, not built here).

Phases 1 and 2 below are the build; Phase 3 is design-only.

---

## Phase 1 — Multi-mailbox recipe bindings

### The config shape

`rcp_source_config` for the three email jobs replaces the single `mailbox_alias`
key with:

```json
{
  "mailbox_aliases": ["a@x.com", "b@y.com"]
}
```

The recipe covers exactly this list — nothing implicit. There is deliberately
**no "all my mailboxes" mode**: coverage is edited from two surfaces (the
dashboard and the email page's AI panel), and that only stays comprehensible if
both read and write the same explicit list. A standing "all" mode with
per-mailbox opt-outs would let the dashboard claim "all" while panel toggles
had quietly made that untrue. The cost is that a newly granted mailbox is not
covered automatically — it shows as "off" in the panel and in the dashboard's
checkbox list until someone turns it on, which is the discoverable behavior.

No new table. The binding is still job config, edited through the same
`configDescriptor()` → FormWriter → `validateConfig()` path; the panel's toggle
(Phase 2) edits the same config through the same validation.

`MailboxAliasConfig::descriptorField()` gains a multi-select variant: a
checkbox list of the owner's granted mailboxes. Checkboxes carry no
silent-default hazard (nothing is pre-checked, an untouched form posts an empty
list), so shipped templates naturally stay unbound. An empty list is legal —
the recipe simply covers nothing and finds no candidates; the dashboard shows
"not on any mailboxes".

### Resolving what a run may read

New shared resolver in `MailboxAliasConfig` (plain values in, plain values out,
mailbox-side of the dependency line as today):

```php
resolveBoundAliases(array $config, int $owner_user_id): array
// -> [alias_id => address] for every address the recipe covers RIGHT NOW:
//    grant held by owner (live check — grants can be revoked after save),
//    alias enabled + store-capable, domain enabled.
```

`EmailJobCandidates` takes the resolved set and selects the newest unhandled
message **across the union** — same rules as today (parsed, unread, non-spam,
non-draft, not in this recipe's log), one query with `alias_id IN (...)`. The
per-(recipe, item) processing log needs no change: item keys are message ids,
which are unique across mailboxes.

Sealed mailboxes keep their existing live fail-closed check, evaluated **per
address**: a sealed address contributes candidates only when the owner's vault
window is open in this request, and only when its domain has opted in to AI
processing. A sealed address that fails either check silently contributes
nothing; the standard addresses in the same set are unaffected.

### Mixed sealed/standard sets: the scheduling split

Today `requiresVaultScope()` is all-or-nothing: non-null means the recipe never
runs from cron and drains only in-window. A multi-mailbox recipe can legally
span both postures, so the split becomes per-subset:

- `requiresVaultScope(config)` returns the vault scope iff the bound set
  **contains any** sealed address — this is what registers the recipe as an
  in-window deferred-work consumer, so its sealed subset drains in slices while
  the owner's vault is open, exactly as today.
- `RecipeDispatcher` stops skipping every recipe with a non-null scope. It
  skips only a recipe with **no standard-address binding**. A mixed recipe is
  scheduled normally; on a cron worker the sealed addresses fail closed out of
  the candidate set and the run drains the standard subset.
- `hasWork()` answers for the caller's subset: the vault heartbeat asks about
  the sealed addresses, the dispatcher's path about the standard ones. Both
  stay single indexed queries — same query, different `alias_id IN` set, using
  the resolver above filtered by posture.
- `RecipeWorkerSpawner`'s refusal likewise becomes "refuse only when the
  recipe has no standard binding" (Run Now on a mixed recipe drains the
  standard subset; the sealed subset waits for the window, as it must).

The result: a user with one Fortress mailbox and two standard mailboxes on one
triage recipe's list gets everything covered, with each message read on the
only path allowed to read it.

### Validation

`validateConfig()` loops the listed addresses with one rule for all of them:
each must resolve to a real, enabled, store-capable mailbox, the owner must
hold a grant on it (`validateOwnerGrant`, per address), and a **sealed**
address whose domain has not opted in to AI processing keeps today's loud
save-time refusal (`assertAiProcessingAllowed`) — every address on the list
was chosen by name, so refusing by name is always right. A grant revoked
*after* save drops that address out at resolve time; the run tally notes the
dropped address so the gap is visible, not silent.

### The one write door, per item

Each job's `recordVerdict()` guard changes from "message must be on the
configured mailbox" to "message must be on one of the addresses this recipe
resolves to right now" — re-resolved at verdict time, same principle: model
output can never steer a write to a mailbox the config doesn't cover.

### Triage label enum across mailboxes

`email_triage` builds its verdict enum from the mailbox owner's live labels.
Across several mailboxes the enum becomes the **union** of live label names
across the bound set (still built fresh per run, still reserving `none`).
`recordVerdict()` applies the label only if it exists for the judged message's
own mailbox; a label that doesn't exist there is skipped exactly like today's
deleted-label case (summary still records). This keeps the system prompt a
stable per-run prefix (cacheable) instead of rebuilding it per item.

### Migration and seeder

- Pre-launch, but dev instances hold real recipe rows: one **data migration**
  rewrites any `rcp_source_config` carrying the legacy `mailbox_alias` key to
  `{"mailbox_aliases":[<value>]}`. No runtime compat shim — the new code reads
  only the new shape.
- `recipes.json` shipped templates seed with an empty `mailbox_aliases` list
  (they already ship disabled and unbound). Template help text updates to
  describe the list.

### Out of scope for Phase 1

- **Per-mailbox fairness.** Newest-first across the union means a flooding
  mailbox can push a quiet one behind it within a batch. The batch size and
  schedule frequency bound the damage; round-robin fairness is deferred until
  it is observed to matter.
- **Egress posture.** This spec does not change what leaves the box or how
  verdict fields are stored; the known sealed-egress defects (pipeline save()
  on sealed rows, `iem_ai_scan` stored unsealed, locality pin) belong to the
  sealed egress review spec and are neither fixed nor worsened here.

---

## Phase 2 — The AI panel

### What the user sees

An **AI** button in the mail reader's header (beside Actions) opens a
right-side slide-over drawer. Inside, one card per relevant recipe:

- The recipe's name and a plain-language status line: what the job does, last
  run outcome and time (e.g. "Sorts new mail into your labels — last ran 20
  minutes ago, 4 messages").
- A single on/off toggle meaning **"runs on this mailbox"** — the mailbox
  currently selected in the reader's rail. Switching mailboxes in the rail
  refreshes the panel to that mailbox's state.
- When the recipe also covers other mailboxes, a quiet "also on N other
  mailboxes" line, linking (for superadmins) to the recipe on the dashboard.

The toggle's semantics, chosen to match the user's mental model rather than the
storage model:

- **Turning ON**: add the current mailbox's address to the recipe's list — and
  if the recipe is globally disabled (`rcp_enabled` false), enable it. If the recipe is
  tainted-capable and has not yet accepted tainted writes, the panel shows the
  same `TaintGate::explain()` text in a `<dialog>` confirm first; accepting
  writes `rcp_allow_tainted_writes` — the panel is a full-fledged place to make
  that acceptance, since "turn triage on for my inbox" is exactly the moment it
  should be offered. Declining leaves everything off.
- **Turning OFF**: remove the current mailbox's address from the recipe's
  list. The recipe keeps running on its other mailboxes; `rcp_enabled` is
  untouched. A recipe whose last mailbox is removed simply has an empty list —
  it is not auto-disabled.

Sealed mailboxes: when the current mailbox's domain is sealed and has not opted
in to AI processing, the toggle renders disabled with the domain-opt-in message
— the same wording `assertAiProcessingAllowed` uses — instead of failing on
click.

A user who owns no recipes sees the panel's empty state: one line naming the
recipes dashboard (superadmin) or nothing to configure (member). No explainer
prose beyond that.

### The general component

The panel is owned by the joinery_ai plugin and mounted by host pages:

- `plugins/joinery_ai/assets/ai_panel.js` + `ai_panel.css` — vanilla JS/CSS,
  jy-ui styling, no framework. Host contract:

  ```js
  JoineryAiPanel.mount({
      area: 'mailbox',                       // 'calendar', 'drive' later
      getContext: function () {              // called on open and on refresh
          return { mailbox: state.currentAddress };
      },
      anchor: headerButtonElement            // where the AI button renders
  });
  ```

- The mailbox reader mounts it only when the joinery_ai plugin is active
  (the mount include checks `PluginHelper`); with the plugin inactive the AI
  button simply doesn't exist. Calendar and drive pages later call the same
  `mount()` with their own area and context — no per-area panel code.

### How recipes declare area relevance

A new **optional** interface in joinery_ai (optional so existing and
third-party jobs are untouched until they opt in):

```php
interface AreaScopedJobInterface {
    /** Which area page this job's recipes belong to: 'mailbox', 'calendar', ... */
    public function area(): string;

    /** Does this recipe's config cover the given context right now? */
    public function coversContext(array $config, array $context, Recipe $recipe): bool;

    /** Return updated config with the context bound or unbound.
     *  The caller runs the result through validateConfig() before saving. */
    public function bindContext(array $config, array $context, bool $on): array;
}
```

All three email jobs implement it through one shared helper (the scope/list
edit logic lives once, per the no-duplicate-paths rule), with `area() ===
'mailbox'` and `$context = ['mailbox' => $address]`. Agent-mode recipes have no
context binding and never appear in the panel in this phase.

### API surface

Two new logic actions with `_logic_descriptor()`, called from the panel over
`/api/v1` with the browser-session credential and `X-Joinery-Csrf` header (no
new `/ajax/` endpoints):

- **`ai_panel_state`** (read) — input `{area, context}`; returns, for each of
  the *signed-in user's* recipes whose job implements the interface with a
  matching area: recipe id, name, job label, covered-here flag, globally-enabled
  flag, tainted-acceptance state, blocked reason (sealed opt-in missing, no
  grant), other-mailbox count, last-run summary.
- **`ai_panel_toggle`** (write) — input `{recipe_id, area, context, enabled,
  accept_tainted_writes?}`. Owner-scoped: the recipe must belong to the session
  user. Applies the toggle semantics above via `bindContext()` +
  `validateConfig()`; returns the refreshed row. Turning ON a tainted-capable
  recipe without acceptance and without `accept_tainted_writes` returns the
  `TaintGate::explain()` text as a confirm-required response rather than an
  error, so the panel can render the dialog from server truth.

Both are member-callable (the mail page is member-facing); ownership scoping is
the authorization. No permission-10 gate — a member who owns recipes manages
them; a member who owns none gets an empty list.

### Tests

- `EmailJobCandidates` multi-mailbox selection: union ordering, per-address
  sealed fail-closed, revoked grant drops an address live.
- Dispatcher/spawner split: mixed recipe schedules and drains standard subset
  on cron; sealed-only recipe still refuses cron.
- `validateConfig`: per-address grant check, sealed-without-opt-in loud
  refusal, empty list legal.
- Panel logic actions: state shape, owner scoping (someone else's recipe id →
  refused), toggle round-trips including last-mailbox removal, taint confirm
  handshake.
- Shipped-template seeding still lands unbound with the new descriptor.

All db tier alongside `shipped_recipes_test.php`.

---

## Phase 3 — The task composer (design only, not built now)

The drawer reserves a composer strip at its bottom edge: a single-line input
("Ask AI to do something with this mailbox…"). It is **not rendered** until the
feature exists — no dead controls — but the drawer's layout (recipe list
scrolls, composer slot pinned) is built in Phase 2 so adding it later changes
no structure.

Intended wiring, recorded so the panel and API are shaped for it: submitting
creates/continues a **chat conversation** (ChatRunner — the existing
`/profile/joinery_ai/chat` machinery, per the unify-recipes-and-chat
direction), seeded with the area context (the current mailbox) so the model's
tool scope starts where the user is standing. The panel is then the one AI
surface per area: standing automations (recipes) above, one-off asks (chat)
below, sharing AgentLoop, providers, and prompt assembly. No recipe-side
"task" mechanism is added — the composer is a chat entry point, not a fourth
recipe mode.

---

## Documentation (updated when built, current-state voice)

- `plugins/joinery_ai/docs/overview.md` — Registered jobs section (the
  mailbox list, union candidate selection, label-enum union), the in-window section
  (per-subset scheduling split), new "AI panel" section (component contract,
  `AreaScopedJobInterface`, the two API actions).
- `plugins/mailbox/docs/overview.md` — one paragraph: the reader's AI button
  and what mounts it.
- `docs/api.md` — the two panel actions in the endpoint list.

## Version bumps

joinery_ai plugin minor bump; touched file `@version` bumps; mailbox reader JS
version bump for the mount.
