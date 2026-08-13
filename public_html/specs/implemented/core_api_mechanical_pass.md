# Core API mechanical pass — bugs, mechanical rewrites, and fully verifiable changes

**Status: BUILT 2026-08-13 — complete. Item 4c was withdrawn and replaced by
`specs/display_message_render_consumes.md`, which is built (see below).
Companion: `specs/core_api_simplification.md` (the design and behavior-change
items from the same review). Origin: the 2026-08-13 core API review sweep (model
layer, logic/API layer, helper surfaces, core→plugin reaches).**

## What was found during implementation

Five things differed from the spec's premises. Each is recorded because the
number in the spec is what a later reader would check against.

**The `deleted` filter count.** 73 per-class implementations were byte-identical
modulo prefix and were deleted. The remaining 12 are not: `passkeys`,
`vault_entries`, `user_encryption_wrappings`, `seo_page_metadata`,
`dns_filtering/scheduled_blocks`, and six `joinery_ai` collections apply the
filter **by default** when the option is omitted, and `conversations` has a
second implementation on its join-query path. Deleting those would change
default behavior, which this spec's own non-goal forbids, so they stayed.

**`class_exists()` and the autoloader.** Making classes resolve by name changes
what `class_exists('X')` means at four sites that used it as "has this been
declared yet?" around a conditional `class X extends ...` — `includes/PublicPage.php`,
`includes/AdminPage.php`, `views/404.php`, `includes/SealedEgressGuard.php`
(plus two test fixtures). Under an autoloader these resolved the core file
instead of declaring the conditional subclass, which silently gave every admin
page the public theme's `PublicPage`. All six now pass `false` as the second
argument. The autoloader also resolves `includes/` classes through the theme
chain rather than straight to core, because `PublicPage` and `FormWriter` are
the classes a theme replaces by shipping its own file.

**ScrollDaddy's FormWriter.** `theme/scrolldaddy/includes/FormWriter.php` was
not comment-only — it declared `$button_primary_class` and
`$button_secondary_class`. Both are read by nothing anywhere in the tree
(FormWriter V1 leftovers), so the file was empty in substance and was deleted
with the other 13.

**`convert_time()` call sites.** 111 sites matched the exact
`$obj->get('field')` form and were rewritten to `get_local()`. The remaining 31
pass a plain variable or an array element, which is the case the spec says
`convert_time()` remains for.

**GET-action handlers — and why 6b was replaced rather than shipped.** 3g and
6b were built as written: a session-bound token on ~40 action links, checked by
`SystemBase::as_get_action()`. It worked end to end. It was also the wrong
shape, and the codebase already said so — `AdminPage::action_button()` exists
and is used at 69 sites, rendering a destructive action as a POST form.

A token in a URL is a weaker token than a hidden form field: it lands in
browser history, `Referer` headers and access logs. And `as_get_action()` fused
two unrelated concerns — the GET-write tripwire (a lint about misfiring form
guards) with CSRF (a security control) — which is why it needed a sibling that
was the same wrapper minus the security check. Two functions differing by a
boolean is a concern split in the wrong place.

So the links became POSTs instead. `altlinks` entries now accept an array
describing a `post` action and render as a submit button
(`PublicPageBase::renderActionEntry`); 43 link sites and 69 handler wrappers
converted; `as_get_action()`, `LibraryFunctions::get_action_url()`,
`SessionControl::get_action_token()` and the token parameter are all deleted.
`SameSite=Lax` (6c) is what makes this safe, and it is why 6c matters more than
it looked.

What remains is one wrapper, `SystemBase::server_initiated_write()` — named for
the question that actually decides whether it applies, who initiated the write —
at 14 production sites across eight files: error and request logging, API key
last-used, the OAuth callback, the payment-gateway return, three reconciliation
sites. Every one is a write the server makes on its own during a page view,
with no user action to convert.
That is a normal invariant with a normal escape hatch. Its callers are
enumerated in `tests/unit/core_api_mechanical_test.php`, which fails on a new
one: reaching for it is nearly always the wrong fix for a refused save, so
adding a caller is made a deliberate act rather than a line that slips in.

`plugins/store/logic/cart_charge_logic.php` keeps its hand-written try/finally:
its guarded region is an 880-line function body with its own `return`s.

Two things the conversion turned up, both fixed: eleven admin pages build their
own dropdown from `$options['altlinks']` by hand rather than through
`renderDropdown()`, and each had to be routed through `renderActionEntry()`;
and `GeneralError::logError()` was writing its row during GETs without the
opt-in, so every error on a page view logged a second entry complaining about
the first.

## Inclusion rule

Every item in this spec is one of: a clear bug, a 100% mechanical rewrite, or a
change whose correctness is establishable by enumeration plus tests — no
behavior choices, no new extension surfaces, no rollout phases. Anything
needing a design decision or a staged flip lives in the companion spec. Each
item names its verification; an item is not done until its verification is.

Two constraints apply throughout:

- **CLAUDE.md is never edited on disk.** Items touching it produce the exact
  replacement text and the owner applies it through `/admin/admin_agent_files`.
- **Every item updates the `/docs/` page that teaches the old ceremony**, in
  current-state voice (no "previously", no "replaces").

---

## Work item 1 — bugs

**1a. Admin "undelete question" re-deletes it.**
`adm/logic/admin_question_logic.php:31` — the undelete branch calls
`$question->soft_delete()`, a copy-paste of the delete branch above it. Change
to `undelete()`. Regression check lands in the tests item.

**1b. Two unguarded plugin requires fatal when the plugin is absent.**
`adm/admin_analytics_attribution.php:13` requires a store data class and
`logic/items_logic.php:10` requires an items data class with no
`file_exists`/`isPluginActive` guard. Guard both with the same
active-plugin-check idiom `logic/booking_logic.php` uses, returning a graceful
"plugin is not installed" result.

## Work item 2 — a class autoloader (add only; no mass require deletion)

Register one `spl_autoload_register` callback fed by the class→file map core
can already build: `includes/` classes are 1:1 by filename, and
`LibraryFunctions::load_models_from_directory()` already tokenizes every
`data/*_class.php` (core and plugin) into an exact `class => filepath` map
without executing anything. The map covers core `includes/`, core `data/`, and
every active plugin's `includes/` and `data/`. Cache it in APCu (the platform
hard-requires `ext-apcu`); on a lookup miss, rebuild the map once and retry
before failing, so a newly created class never needs a cache flush.

Existing `require_once(PathHelper::getIncludePath(...))` lines keep working as
no-ops and are **not** mass-deleted here — that codemod is deferred to the
companion spec so it cannot collide with the uncommitted vault work. New code
stops writing the ceremony immediately.

Docs: the File Include Rules section (and `docs/plugin_developer_guide.md`)
teaches "classes just resolve; `PathHelper` remains for non-class file paths".
`maintenance_scripts/dev_tools/validate_php_file.php` must not flag a missing
require for an autoloadable class.

Verification: a unit test asserting every class in the built map resolves via
the autoloader alone; safe tier green with no other change.

## Work item 3 — the model-layer gotcha pack

Each sub-item deletes a documented gotcha by making it stop being true.

**3a. Constructors get defaults.** `SystemBase::__construct($key = NULL,
$and_load = FALSE)` so `new Product()` means "new record". Exactly 16 model
classes redeclare `__construct` and every one is a pure pass-through
(`data/{device_links, drive_usage, file_changes, file_share_links,
file_versions, file_access_grants, file_key_grants, file_uploads, folders,
passkeys, passkey_ceremonies, recovery_verifications, sync_devices,
user_encryption_wrappings, user_encryption_vaults, emails}_class.php`, plus
`plugins/vault/data/{vault_keyring, vault_entries}_class.php`) — give each the
same defaults or delete it. `tests/unit/system_base_lifecycle_test.php:63-65`
currently pins the fatal and is rewritten to pin the default.

**3b. Unknown property reads on collections throw.** `SystemMultiBase::__get()`
throws with a message naming the fix ("collections are iterated directly:
`foreach ($multi as $item)`"). Safe because `SystemMultiBase` declares all its
real public properties explicitly and zero production code reads an undefined
property on a Multi. **Deliberately NOT added to `SystemBase`** — single models
legitimately carry dynamic properties (`data/conversations_class.php:401-405`,
`data/users_class.php:1057`). `tests/unit/system_base_lifecycle_test.php:293`
is rewritten from "silently yields nothing" to "throws".

**3c. Collections load lazily.** `getIterator()` and `count()` run
`if (!$this->loaded && $this->loadable) $this->load();`. `add()` sets
`$this->loaded = TRUE` — that covers all 6 manually-populated collection sites
in the tree (`data/posts_class.php:234`, `data/groups_class.php:202`,
`data/mailing_lists_class.php:88`, `data/conversations_class.php:408`,
`plugins/event_manager/data/event_sessions_class.php:366`,
`includes/SystemBase.php:895`). Explicit `->load()` calls remain valid no-ops
after first load. Verification includes an enumerated audit of the ~150
construction sites that do NOT follow the construct-then-load-within-6-lines
pattern, confirming none relied on iterating an unloaded collection as a
silent no-op. This retires both the `count()`-before-`load()` gotcha and the
two-line load dance (801 sites simplify opportunistically, not by codemod).

**3d. Core absorbs the `deleted` filter.** `_get_resultsv2()` implements the
`deleted` option for any model whose `$field_specifications` contains
`{prefix}_delete_time` — all 85 per-class implementations are byte-identical
modulo prefix and are deleted. Four models gain the previously-missing filter
(`data/content_versions_class.php`, `data/direct_spool_class.php`,
`data/email_templates_class.php`, `data/subscription_tiers_class.php`).
**Explicit non-goal:** no change to default behavior when `deleted` is omitted
— that flip is a separate post-release decision.

**3e. Filter keys accept real column names.** An option key not in
`known_option_keys()` that IS a declared column of the collection's model
becomes a parameterized filter with the PDO type from the field spec; same for
the bare `{prefix}`-less suffix. Zero risk: both forms throw
`UnknownMultiOptionException` today, so nothing can depend on the current
behavior — and core already performs exactly this prefix inference for ORDER
BY. Join/view collections are exempt via the same carve-out
`assert_sortable_column()` uses. CLAUDE.md gotcha #2 is rewritten from a
warning into a statement of what works.

**3f. `assert_can_write($session)` / `assert_can_read($session)`.** Additive
methods that build the `current_user_id`/`current_user_permission` array from
the session and call the existing `authenticate_*` — every subclass override
keeps working unchanged (`authenticate_tier()` already takes the session
object, so this restores internal consistency). Mechanically rewrite the 86
byte-identical literal call sites. Fix the `docs/deletion_system.md` example
that hardcodes permission 10 — the copy-paste hazard this closes.

**3g. `SystemBase::as_get_action(callable)`.** Owns the
`$allow_get_mutation` flag and the try/finally; mechanically rewrite the ~56
call sites (the block the work-item-1a bug was copy-pasted from). This rewrite
also carries work item 6b, since it touches both ends of every GET-mutation
link.

## Work item 4 — logic-layer mechanical fixes

**4a. One metadata companion.** Codemod the 120 legacy `_logic_api()`
functions to `_logic_descriptor()`; in the 19 files defining both, the
descriptor already silently wins — delete the dead `_logic_api()`. Then remove
the two-spelling fallback chains (`ApiLogicEndpoint.php:78-84`,
`api/apiv1.php:692`) and normalize `requires_session` to its majority top-level
spelling, dropping the dual-location read. Pre-release, no third-party plugins
exist, so no compatibility shim survives. Verification: grep zero
`_logic_api(` remaining; db tier green.

**4b. A missing descriptor fails loud.** When the logic file resolved and
`{action}_logic()` exists but no descriptor does, the API answers with an
error naming the fix ("exists but is not exposed — add
`{action}_logic_descriptor()`") instead of `404 Unknown action`. The check
sits after file resolution, preserving the 404-hides-plugin-existence property
at `apiv1.php:171`. Same clarification for the form face
(`resolveForm`). 74 files currently produce the misleading 404.

**4c. `process_logic()` injects `display_messages` — WITHDRAWN. Replaced by
`specs/display_message_render_consumes.md`, which is BUILT.**

The enumeration this item made a condition of shipping came back against it.
229 pages call `process_logic()`; 18 views render `display_messages`; and
`PublicPageBase::public_footer()` calls `clear_clearable_messages()` on **every**
public page. Because `get_messages()` marks what it returns, injecting on every
page would consume — and the footer would destroy — a pending message on the
211 pages that never show one.

The item was misfiled. Injecting a consuming read into 211 pages is a behavior
change, which this spec's own inclusion rule excludes; it read as mechanical
only because the neighbouring `validation_errors` injection has the same shape,
and fetching a validation error does not destroy it. The duplication 4c
targeted was a symptom: the framework made displaying a message the caller's
job *and* made reading one destroy it, so no page dared fetch a message it
would not show.

The replacement fixes that instead — a message is spent when it is **rendered**,
`get_messages()` becomes a pure read, and one `render_messages($slot)` call
replaces 18 hand-rolled loops. 4c's original goal, deleting the 20 duplicated
fetch lines, falls out as a consequence: a view that renders a slot does not
want the array, and a view that does not render one should never have received
it. No injection into `process_logic()` is needed or wanted.

## Work item 5 — display-time helper

`SystemBase::get_local($field, $format = 'M j, Y g:i A T')` — the display-side
twin of the UTC-to-local conversion FormWriter already performs on the input
side (`FormWriterV2Base::convertDateTimeFieldsToLocalTime()`), reading the
session timezone internally. Mechanically rewrite the 160 call sites of the
form `LibraryFunctions::convert_time($obj->get('field'), 'UTC',
$session->get_timezone(), $fmt)`. `convert_time()` remains for non-model
values and non-session timezones. Docs: the Time/Date section leads with
`get_local()`.

## Work item 6 — CSRF groundwork (no enforcement flip)

The enforcement flip is the companion spec's; this item ships everything that
is mechanical and immediately safe:

**6a. Shadow verification.** At the form-submission chokepoint, run a
non-consuming variant of `validateCSRF()` and `error_log` every would-have-
failed POST (form id, action, absent-vs-mismatched token) — no behavior
change. The exact hook is pinned at implementation (candidates: inside
`process_logic()` keyed on the CSRF field's presence in `$_POST`, or a
FormWriter processing path); the requirement is that every FormWriter-rendered
form's POST gets shadow-checked.

**6b. GET-mutation links get a real token, enforced now.** SameSite cookies do
not cover top-level GET navigation, so the ~28 delete/undelete-style GET
action links are CSRF-exposed regardless of the form story. Both ends are
in-tree and enumerable, and work item 3g rewrites every handler anyway: link
generation gains a session-bound token parameter, and `as_get_action()`
verifies it and refuses without it. Enforced immediately — no third-party
callers exist, and every rewritten link/handler pair is verified together.

**6c. Pin `session.cookie_samesite = Lax` explicitly** where session cookie
params are set, converting an inherited browser default into a decision.

**6d. Correct the CLAUDE.md claim** that FormWriter "handles CSRF
automatically" to state what is true (tokens emitted; verification per the
current docs) — superseded again when the companion spec flips enforcement.

## Work item 7 — FormWriter mechanical fixes

**7a. Delete the empty theme override chain.** All 14 theme `FormWriter.php`
files are empty subclasses (0 function declarations; confirm
`theme/scrolldaddy/includes/FormWriter.php`, the only 16-line one, is
comment-only before deleting). Collapse `PublicPageBase::getFormWriter()` to
instantiate `FormWriterV2HTML5` directly, matching `AdminPage`; the unused
`formWriterBase` manifest key and `ThemeHelper::getFormWriterBase()` go with
it. The three-case "which FormWriter" doc rule collapses to one line.

**7b. Default `edit_primary_key_value` from the model.** When `model` is
passed with a saved key and `edit_primary_key_value` is absent, default it to
`$model->key` — forgetting it currently turns an edit form into a silent
duplicate-insert. Verification: enumerate every form passing a loaded model
without the option and confirm none is an intentional duplicate-record form;
an explicit value always wins, so any found opt out by passing it.

## Work item 8 — `Setting::put($name, $value)`

The programmatic settings write path that 38 files currently hand-roll as raw
SQL against `stg_settings` (core's own `DirectSettings.php:125` documents the
workaround in a comment). `put()` refuses names not declared in
`settings.json`/plugin declarations, the same rule `SettingsWriter` enforces —
misspelled names fail loud instead of minting orphan rows. Migrate the plain
upsert sites; each site's outcome is enumerated in implementation, and a site
writing an undeclared name either gets the name declared or stays on raw SQL
with a comment saying why (installer/bootstrap contexts where declarations may
not be loaded stay raw). Docs: `docs/settings.md` and the CLAUDE.md settings
note teach `put()`.

## Work item 9 — small registry and client cleanups

**9a. `DnsDriverRegistry` scans plugin directories** the way
`OAuth2ConsumerRegistry` (which cites it as the same pattern) already does —
without this a plugin cannot ship a DNS provider driver.

**9b. Migrate the 3 copy-paste fetch sites** to `joineryApi`
(`plugins/mailbox/admin/admin_mailbox_domains.php`,
`plugins/mailbox/includes/mailbox_reader_mount.php`,
`plugins/mailbox/includes/mail_import_panel.php`). The remaining hand-rolled
fetch sites (multipart uploads, WebAuthn flows) are legitimate per
`docs/api.md` and stay.

**9c. Dead doc pointer.** CLAUDE.md points at `includes/SystemMultiBase.php`,
which does not exist — the class lives in `includes/SystemBase.php`. Corrected
alongside the gotcha rewrites (3a/3b/3c/3e make CLAUDE.md's three model
gotchas false; the section is rewritten to describe what now works).

## Work item 10 — tests

- New unit coverage per item: constructor defaults, Multi `__get` throw, lazy
  load (including the 6 `add()` sites' pattern), core `deleted` filter parity
  against a hand-written implementation, column-name filter keys (both forms,
  plus the join-collection exemption), `assert_can_write` delegation,
  `as_get_action` (including the GET token refusal), missing-descriptor error
  shape, `get_local()` timezone/format behavior, `Setting::put()` refusal of
  undeclared names, autoloader full-map resolution.
- Rewritten pins: `tests/unit/system_base_lifecycle_test.php` (constructor
  :63, `->results` :293).
- Regression: admin question undelete undeletes.
- Gate: full db tier green before this spec is called done.

## Non-goals

- No behavior flips that need a rollout: CSRF POST enforcement, loading-
  constructor-throws, `set()`-undeclared-throws, `deleted`-by-default — all in
  `specs/core_api_simplification.md`.
- No new extension surfaces — `fileSources` and policy-hook registries live
  in `specs/core_api_simplification.md`; the plugin `bootstrap` key is its
  own spec to build next (`specs/plugin_bootstrap_key.md`); the routes
  registry is its own deferred spec (`specs/plugin_routes_registry.md`).
- No mass deletion of existing `require_once` lines (companion spec, after the
  uncommitted vault work lands).
- No change to Multi option semantics for keys that work today.
