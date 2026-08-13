# Core API simplification — design and behavior items from the 2026-08-13 review

**Status: DEFERRED 2026-08-13 (owner) — see R6. Prerequisites are met and the
spec is buildable; it is deferred on value, not readiness. The one live defect
it contained was split out and shipped as
`specs/api_action_feature_gate.md`.**

**Companions:** `specs/core_api_mechanical_pass.md` and
`specs/plugin_bootstrap_key.md` (both built and committed — a3f575a0 lineage;
in `specs/implemented/`), `specs/plugin_routes_registry.md` (deferred past the
release — see R5), and `specs/api_action_feature_gate.md` (built; carries work
item 1's second bullet).

## Intent

The same review that produced the mechanical pass surfaced items that need a
design decision, change observable behavior, or add a new extension surface.
They share one goal with the vault consumer platform work: a third-party
developer should meet the smallest possible API, and every fact should have
one declared home. Items are priority-ordered but independent unless stated.
This spec starts only after `specs/plugin_bootstrap_key.md` is built and the
pending big commit (vault consumer platform + bootstrap key) has landed.

## Work item 1 — the descriptor becomes the single source of truth

The API face already resolves the descriptor, enforces `requires_session`,
and type-coerces the declared `input` schema; the page face
(`process_logic()`) ignores descriptors entirely, which is why 109 of 208
descriptor-carrying logic files re-check the session by hand and 43 re-guard
fields their schema already declares required.

- `process_logic()` resolves the descriptor for the action it runs, enforces
  `requires_session` (page face: redirect to login; API face keeps 401), and
  runs the same `DescriptorValidator::coerce()` over the input before the
  logic body sees it.
- ~~A new descriptor key `requires_setting`~~ — **SHIPPED separately** as
  `specs/api_action_feature_gate.md`. The key exists, is enforced on the API
  face at both dispatch chokepoints as a 403, and filters the discovery
  endpoint. It was split out because it was the only part of this spec fixing a
  live defect: seven Drive actions were callable with Drive switched off, which
  this item had described only as a discovery-listing cosmetic problem.
  Remaining here, unbuilt: unifying serve.php's `check_setting` and plugin
  `settingActivate` as aliases of the descriptor so the fact has one home, and
  retiring the 69 hand-written body guards.
- After enforcement lands, the redundant body guards and field re-checks are
  deleted (that mop-up is mechanical, but it is gated on this design change,
  which is why it lives here and not in the mechanical pass).
- The management API's `_handler_api()` companion (7 sites, different key
  vocabulary) is unified onto the descriptor so "describe a thing to the API"
  has one answer.

Docs: `docs/logic_architecture.md`'s "Permission Check Pattern" and
"Missing/Invalid Parameter Pattern" sections are replaced by "declare it in
the descriptor"; `docs/api.md` documents `requires_setting`.

## Work item 2 — CSRF verification becomes the default

Decision made 2026-08-13: default-on, shadow first. The mechanical pass ships
the shadow logging, the enforced tokens on GET-mutation links, and the
explicit SameSite pin; this item owns the enforcement design:

- **Per-session tokens replace one-time-per-form tokens.** The current
  implementation consumes a token on successful validation
  (`FormWriterV2Base.php:331-334`), which breaks a form open in two tabs. A
  per-session synchronizer token (rotated on login/privilege change) is the
  standard shape and removes the multi-tab and back-button failure modes.
- **The non-FormWriter form sweep.** The documented exception — single-button
  action forms with only hidden inputs — carries no token today. They get a
  one-line token helper (or FormWriter grows a minimal action-form builder),
  and the sweep enumerates every hand-written `<form>` in the tree.
- **Failure UX.** An expired-session or token-mismatch POST re-renders the
  form with a clear message and the user's input preserved, never a bare
  refusal.
- **The flip.** Verification moves from shadow to enforce inside the same
  chokepoint once the shadow log has been quiet across a full db-tier run and
  a manual pass of the main flows. Opt-out remains `['csrf' => false]` per
  form. CLAUDE.md's "handles CSRF automatically" claim becomes true and is
  restored.

## Work item 3 — a missing row fails loud at the constructor

`new X($id, TRUE)` on a missing row currently yields an object whose `get()`
returns null for everything — the documented silent-empty-form trap. Nobody
checks `load()`'s false return (zero sites); 16 sites pay a redundant
`check_if_exists()` query to get a loud failure first.

The loading constructor (`$and_load === TRUE`) throws
`SystemBaseRowNotFoundError` when the row is absent. `load()` keeps its
boolean return for callers who probe. The 16 pre-check ceremonies collapse
into the constructor call. Rollout: the missing-row path already
`error_log`s today — that log is the shadow; the build audits it across a
soak period plus a full db tier before flipping, and page-face handling maps
the new exception to a 404 rather than a blank edit form (which is the bug
this fixes). Open sub-decision at build time: whether admin list/edit pages
want a shared "record not found" redirect helper rather than each catching
the exception.

## Work item 4 — remaining core→plugin reaches

Each gets the idiom that already exists for its shape:

- **Mailbox policy in core auth paths** (`logic/register_logic.php:180`,
  `logic/security_logic.php:137`, `logic/account_edit_logic.php:99`,
  `includes/OutboundTransport.php:180,199`): a hosted-address policy hook and
  a mail-transport policy hook in the `MailIdentityGuard::registerProtectedDomainCheck()`
  shape — the right idiom is already in core and simply unused by these
  sites. Mailbox registers from its bootstrap
  (`specs/plugin_bootstrap_key.md` gives every plugin one).
- **Email providers**: `ConnectedMailboxProvider` and `PostfixProvider` (core
  files unusable without mailbox) move to
  `plugins/mailbox/includes/email_providers/`, and the provider scan extends
  to plugin directories the way `OAuth2ConsumerRegistry` scans. The
  `CoreSettingOptions::connectedAccounts()` dropdown resolves through an
  options-provider callback registered the same way.
- **`AdminPage.php:53` hardcoded store Payment Settings tab**: a
  `settingsTabs` declaration in `plugin.json` (compose with the existing
  `settingsMenu` key rather than adding a near-duplicate — implementation
  reconciles the two).
- **Inline admin cross-links that bypass registries core already iterates**
  (`adm/logic/admin_user_logic.php:155`, `adm/admin_user.php:292`,
  `adm/admin_survey.php:123`, `adm/admin_users_message.php:34`,
  `adm/admin_subscription_tiers.php:181`): migrate to
  `AdminUserPanelRegistry` / the matching page registries; where no registry
  fits, the link declaration joins the plugin's `plugin.json` menu surface.
- **`IcsHelper.php:207` and `views/site-directory.php:14`** resolve through
  `CalendarItemSourceRegistry`; implementation first confirms the registry
  surface carries what IcsHelper needs (location enrichment) and extends the
  source interface if not.
- **`tier_gate_prompt.php:68`**: a tier-upgrade-offer provider registered by
  the store, alongside the existing `TierGatedContentRegistry`.
- **`DeploymentHelper.php:41`**: replace the plugin-name predicate with a
  capability declaration (`"provides": ["fleet_deployment"]`-style) in
  `plugin.json`.
- **Event/store reaches in admin logic** (`admin_users_message_logic`,
  `admin_file_upload_process_logic`, `admin_shadow_session_edit_logic`,
  `admin_analytics_attribution` beyond its mechanical guard): each either
  moves into the owning plugin or resolves through an existing registry;
  enumerated individually at build.

## Work item 5 — `fileSources`: a plugin's file type without a core edit

Four of the eight `File::SOURCE_*` constants and their `source_catalog()`
rows (`data/files_class.php:83-134`) are plugin-owned declarations living in
core. A `fileSources` key in `plugin.json` carries `label` / `internal` /
`default_view` per tag, merged like the vault registries (collision refused,
core wins). Core-owned sources keep their constants; the four plugin tags move
out. Combined with the bootstrap key
(`specs/plugin_bootstrap_key.md`), the upload integration collapses from six
steps (two of them core edits) to: declare `fileSources`, register the purpose
and hooks in your bootstrap, mint signed URLs.

## Work item 6 — page-face flow and the error channel

- **The router runs the logic.** 229 views open with the same four-line
  preamble, and the two input spellings differ only in whether they remember
  `$params` — the 110 sites that omit it will silently lose route
  placeholders the day one is added. The router (which set `$params`, knows
  the view, and already auto-loads the logic file) pre-populates `$page_vars`
  by convention, with an explicit opt-out for views composing multiple logic
  calls. `process_logic()` remains for the opt-out path.
- **One failure channel.** `LogicResult::fail($message, $data = [])` for "the
  action ran and declined" (HTTP 200, explicit `success: false`) versus
  `error()` for "the action could not run" — replacing the convention where
  36 files hand-build `success => false` render payloads that the API face
  reports as *"completed successfully"*. The `process_logic()` behavior fork
  that turns an error into a flash-message-and-render when `data` happens to
  be non-empty becomes an explicit property on the result, not an inference
  from array emptiness.
- **The per-field validation channel is kept and documented** (decision
  2026-08-13): `addValidationError()` is the one way to return a business-rule
  failure tied to a field — FormWriter's model-spec validation covers the
  rest. Documented in `docs/logic_architecture.md` and `docs/validation.md`;
  adopted opportunistically when a logic file is touched, the `/ajax/`
  precedent. No wholesale migration.

## Work item 7 — `set()` on an undeclared field throws

Highest doc value per line changed, unbounded blast radius: today the value
is stored anyway after an `error_log`, so the mistake reads back as if it
worked while `save()` drops it. The existing error_log is the shadow — the
build greps it across a soak period, fixes what it names, then flips
`$check_existance = TRUE` to throw. The documented escape hatch
(`set($k, $v, FALSE)`) is unchanged and is what `load_from_data()` already
uses. Sequenced last among the behavior flips because its failure sites are
by construction invisible to grep.

## Work item 8 — the require_once mass deletion

After the vault work is committed and the mechanical pass's autoloader has
soaked: one codemod deleting the ~6,957
`require_once(PathHelper::getIncludePath(...))` lines whose targets are
autoloadable classes (requires of non-class files stay). Purely mechanical,
verified by `php -l` + validator over every touched file and a full db tier.
Deferred here solely so its diff cannot collide with in-flight work.

## Resolved decisions

- **R1 — Per-field validation channel: keep and adopt opportunistically**
  (owner, 2026-08-13). It is finished, additive infrastructure whose only gap
  was documentation; FormWriter covers spec-derived validation, this covers
  business rules. Deleting it would foreclose better member-facing form UX to
  save one doc paragraph.
- **R2 — CSRF: default-on, shadow first** (owner, 2026-08-13). SameSite-by-
  browser-default is an accident, not a decision, and does not cover the
  GET-mutation links at all. Shadow logging and the GET fix land in the
  mechanical pass; the enforcement flip lands here once the log is quiet.
- **R3 — Autoloader now, require deletion later** (owner, 2026-08-13). The
  addition is small and verifiable; the deletion is a giant diff that must
  not collide with the uncommitted vault work.
- **R4 — The bootstrap key builds immediately with the vault commit** (owner,
  2026-08-13, twice adjusted). The owner chose folding it into the vault
  spec; that spec completed and moved to `specs/implemented/` the same day,
  which is never modified — so it became this spec's first work item, and was
  then split to its own spec, `specs/plugin_bootstrap_key.md`, to be built
  next and land in the same commit as the vault work.
- **R5 — The routes registry is deferred past the release** (owner,
  2026-08-13, resolving D1). Deferral costs capability delay only, never
  compatibility debt, and the alternative was front-controller work at the
  riskiest possible time. It lives as its own spec —
  `specs/plugin_routes_registry.md`, intended as the first post-release
  build — which records the deferral rationale and the declined
  collision-check middle path. Nothing in this spec depends on it.

- **R6 — The spec is deferred; its one live defect ships alone** (owner,
  2026-08-13). Both blocking prerequisites landed (`554a23f6` vault +
  bootstrap key, `4d910a33` mechanical pass), so this was buildable. It is
  deferred anyway: the bulk of it buys internal consistency rather than
  user-visible capability, and it tightens 208 previously-permissive
  enforcement paths immediately before a release. The exception —
  `requires_setting` — was carved out and built, because it closed an actual
  hole. Nothing here expires; it is picked up post-release.

## Count corrections (audit, 2026-08-13)

Verified against the tree while deciding R6. Most cited figures hold — 229
views with the preamble (237 tree-wide), 16 redundant `check_if_exists`
(exactly 16), 69 body setting-guards (exactly 69), ~6,957 require lines
(6,984). Two need adjusting before this spec is scheduled:

- **Work item 1's "109 of 208" is 167.** Counting strictly — descriptor
  declares `requires_session: true` *and* the body re-checks anyway — 167 of
  208 files are redundant, not 109. The mop-up is roughly half again the size
  budgeted.
- **Work item 6's "36 files" is 33 tree-wide but only 4 logic files.** The
  claimed harm (the API reporting a declined action as *"completed
  successfully"*, `api/apiv1.php:114`) can only occur on files reachable
  through the API face. The other ~29 are views and ajax handlers the API never
  sees. The `fail()`/`error()` design argument stands; its stated blast radius
  does not.

## Open decisions

- **D2 — Work item 3's page-face shape:** shared record-not-found redirect
  helper vs per-page exception handling. Decidable at build.

## Non-goals

- No change to the `deleted`-filter default (omitting the key keeps returning
  all rows) — a separate post-release decision.
- No adoption sweep for the validation channel, `get_local()`, or lazy
  collection loading beyond what other items touch — opportunistic by rule.
- Nothing in this spec re-opens the vault consumer platform's decisions; work
  item 1 only relocates the loader that work built.
