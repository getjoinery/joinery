# Documentation & Agent-File Audit (from a plugin-build simulation)

## Purpose

This audit catalogs every erroneous assumption, documentation gap, and contradiction discovered
while writing `specs/linkvault_plugin.md` — a complete plugin spec produced by a *simulated new
developer* using only the distributable agent file
(`maintenance_scripts/install_tools/default_agents_template.md`) and the `/docs/` guides, with
no access to platform source.

After the spec was written, every load-bearing claim in it was checked against the real code
(`includes/`, `data/`, `plugins/`, `adm/`, `api/`). The findings below are grouped by severity.
Each item names the doc(s) at fault, what the doc says, what the code actually does (with
`file:line` evidence), and the recommended fix.

The distributable agent file is edited through the admin agent-files editor (the "Internal
CLAUDE.md" / customer-baseline records), **never** on disk — see the Agent Files rule. Doc fixes
under `/docs/` are ordinary edits.

---

## Implementation status (executed 2026-06-12)

Everything below has been implemented except two items deliberately left as maintainer
decisions (noted ⏸). Verification: every modified PHP file passes `php -l` and
`validate_php_file.php` (0 pattern violations); the `authenticate_*` methods are reached only
from `api/apiv1.php` (confirmed — zero web-render risk), and the list endpoint already skips
rows that throw, so owner-scoping is non-breaking.

**Documentation fixes — done:** 1.1 (`getFormWriter 'v2'`, 16 call sites), 1.2 (`new Session`),
1.3 (`set_validate`/V1 positional purge in validation.md + example_class.php), 2.1 (all 13
two-arg logic signatures → `array $input`, bodies + call sites), 2.2 (`get_setting` → `''`),
2.3 (permission-ladder table + `min_permission => 0` note in routing.md), 3.1-docs (slug rule
restated to what `syncMenus()` enforces), 3.2 (CSRF reframed opt-in), 4.3 (`LogicResult::success`),
4.4 (`get_permission_level`), 4.6 (inert manifest keys noted). Gaps **G1-G6** all
written (Multi-class authoring, `$prefix`, email-send link from scheduled-tasks, SessionControl
method table, two admin surfaces, plugin asset cache-busting). The Data Models field-spec example
dialect (`'type'=>'int'`/`'length'`) was corrected to the real dialect while adding G2.

**Code fixes — done:**
- **3.3:** `FormWriterV2Base::detectModelFromFieldName()` now resolves against the model passed to
  `getFormWriter(['model' => $obj])` before the core-only prefix glob, so plugin form fields get
  auto-detected client-side validation. Server-side validation was always unaffected.
- **4.1/4.2 + email name:** distributable `default_agents_template.md` re-synced
  (`inbound_email` link, `iem_inbound_email_messages` table, `EmailSender` not `SystemMailer`).

**Left as maintainer decisions (not executed):**
- ⏸ **3.1 code hygiene** — delete vs. fix-and-wire the dead `PluginHelper::validate()`. Low stakes;
  the docs no longer describe its contradictory rule as enforced, so nothing is broken either way.

**Agent files — done.** The internal "Internal CLAUDE.md" record (`agf_agent_files` id 2) had the
same `SystemMailer` drift; corrected via the `AgentFile` model (`save()` + `write_to_disk()`, the
same path the admin editor uses), which regenerated on-disk `CLAUDE.md` and `GEMINI.md`. The
distributable `default_agents_template.md` and the internal record are now consistent on the
email class name, the inbound-email plugin link, and the `iem_inbound_email_messages` table.

---

## Severity 1 — Wrong code in docs (will not run / will silently corrupt)

### 1.1 `getFormWriter('form1', 'v2')` — the second argument is an options array, not `'v2'`

- **Docs:** `docs/formwriter.md` (lines 56, 121, 153, 426, 493, …), `docs/admin_pages.md`
  (lines 319, 623), `docs/logic_architecture.md` examples. All show
  `$page->getFormWriter('form1', 'v2', [...])` — a three-argument call with `'v2'` as the
  second argument.
- **Reality:** The real signature is two arguments:
  `getFormWriter($form_id = 'form1', $options = [])` — `includes/PublicPageBase.php:106` and
  `includes/AdminPage.php:33`. The second argument is `array_merge`-d with defaults. Passing the
  string `'v2'` is wrong; passing a third positional argument is wrong. Every real call site in
  `adm/` uses the two-arg form: `$page->getFormWriter('form1', [...])`
  (e.g. `adm/admin_address_edit.php:23`, `adm/admin_component_edit.php:229`).
- **Impact:** A developer copying the documented call passes `'v2'` where an array is expected.
  At best it's ignored; at worst it triggers an `array_merge()` type warning. This is the single
  most-repeated error in the docs and the simulation reproduced it (Assumption #8).
- **Fix:** Global search-and-replace `getFormWriter('…', 'v2', ` → `getFormWriter('…', ` and
  `getFormWriter('…', 'v2')` → `getFormWriter('…')` across `docs/formwriter.md`,
  `docs/admin_pages.md`, `docs/logic_architecture.md`, and the agent file's FormWriter snippets.

### 1.2 `new Session($settings)` does not exist

- **Docs:** `docs/plugin_developer_guide.md:99` — *"`$session = new Session($settings);`"* in the
  Core File Guarantees example.
- **Reality:** There is no `Session` class. The only session entry point is
  `SessionControl::get_instance()` (`includes/SessionControl.php`). The agent file and every
  other doc use `SessionControl::get_instance()` correctly — this one example is an outlier.
- **Impact:** `Fatal error: class 'Session' not found`. A new developer's first plugin file
  (the canonical "how to use core classes in a plugin" snippet) crashes.
- **Fix:** Replace the snippet with `$session = SessionControl::get_instance();` and
  `if (!$session->is_logged_in()) { … }`.

### 1.3 `set_validate()` example contradicts its own removal note

- **Docs:** `docs/validation.md:96` correctly states `set_validate()` was removed — then
  `docs/validation.md:540-575` presents a full worked admin form built around
  `echo $formwriter->set_validate($validation_rules);` plus V1 positional `textinput(label,
  name, class, size, value, …)` and `new_form_button()`/`start_buttons()`.
- **Reality:** `set_validate()` exists nowhere (`grep` across `includes/` is empty; confirmed).
  `textinput` is `($name, $label, $options)` in `FormWriterV2Base` (line 818) — the V1 positional
  order is dead. `new_form_button`, `start_buttons`, `end_buttons` are not V2 methods.
- **Impact:** The "Complete Validation Example" (Section 4) is uncopyable end-to-end; a developer
  following it writes a form that fatals on the first `set_validate()` call.
- **Fix:** Replace the entire Section 4 example with a V2 `field_specifications` + bare
  `textinput($name,$label,[…])` + `submitbutton()` form. Delete the "Legacy V1 `set_validate()`"
  blocks in Section 6 — they describe a removed API.

---

## Severity 2 — Contradictions that force a guess

### 2.1 Logic-function signature: one doc says `array $input`, the rest demonstrate two args

- **Docs:** `docs/plugin_developer_guide.md:289` and `docs/logic_architecture.md:24-26` declare
  the single canonical signature `function foo_logic(array $input): LogicResult` — *"There is no
  second variant."* Yet `docs/logic_architecture.md` itself (lines 79, 94, 106, 190, 450),
  `docs/admin_pages.md` (the "Complete Template" line 63 and every example), and the
  Form-Processing / Edit-Form patterns all use `function foo_logic($get_vars, $post_vars)`.
- **Reality (counted, June 2026):** the single-`array $input` form is **universal** —
  **172 real logic files** across `logic/`, `adm/logic/`, and `plugins/*/logic/` use
  `_logic(array $input)`; **zero** use `($get_vars, $post_vars)`. (Two one-offs use a single arg
  under a different name — `documentation_logic($vars)`, `list_signup_logic($config)` — still
  single-argument.) Views invoke them with one merged bundle:
  `process_logic(foo_logic(array_merge($_GET, $_POST, $params ?? [])))`.
- **The docs contradict their own stated rule.** The two-arg form appears **11 times in
  `docs/logic_architecture.md`** and **once in `docs/admin_pages.md`** (counted) — a few
  paragraphs below the line declaring "there is no second variant."
- **Impact — not cosmetic; can actually break.** A developer who copies the two-arg signature
  writes `function mything_logic($get_vars, $post_vars)`, but the framework/views call logic with
  a **single** bundle. The second parameter never arrives → either a fatal "Too few arguments,"
  or (if defaulted) a silent misread where the whole merged bundle is treated as `$get_vars` and
  POST handling misfires. The simulation hit this first (Assumption #1).
- **Fix (docs only, but the highest-volume staleness here):** rewrite all 12 two-arg example
  functions in `docs/logic_architecture.md` and `docs/admin_pages.md` to the single-`$input`
  signature, and update their call sites to `array_merge($_GET, $_POST, $params ?? [])`. It is the
  foundational page pattern shown wrong a dozen times in the two most-read guides — fix before the
  smaller snippet items.

### 2.2 `get_setting()` for a missing key: `''` vs `null`

- **Docs:** `docs/settings.md:26,32` says missing settings return empty string `''`. The agent
  file's Plugin Settings section (`default_agents_template.md`, "Blank defaults" paragraph in the
  Plugin Developer Guide) says `get_setting()` returns `null` before first save.
- **Reality:** Returns `''` (empty string) and `error_log`s, unless called with a
  `$fail_silently` flag — `includes/Globalvars.php:94`.
- **Impact:** Minor, but it pushed the simulation to defensively use `?:` everywhere
  (Assumption #12). Worth pinning so developers don't write `=== null` checks that never fire.
- **Fix:** Correct the Plugin Developer Guide's "Blank defaults" note to say `''`, matching
  `docs/settings.md`.

### 2.3 The permission ladder and the counterintuitive `min_permission => 0` rule are undocumented (NOT a security slip — corrected)

- **Docs:** `docs/routing.md:122` lists `min_permission` as "Integer permission level required";
  the plugin-routes example (`docs/plugin_developer_guide.md:167`) uses `'min_permission' => 0`
  on a member profile page. Admin docs define 5/7/9/10 but nothing defines what a normal member
  has, or what `0` vs omitting the key means.
- **Reality (re-verified June 2026 — reverses the original draft of this finding):** the router
  enforces with `if (isset($config['min_permission']))` (`includes/RouteHelper.php:312`) — `isset`
  is **true even when the value is `0`** — then calls `SessionControl::check_permission($level)`.
  And `check_permission()` (`includes/SessionControl.php`) **redirects any not-logged-in user to
  `/login` first, regardless of `$level`**, and only afterward enforces
  `$_SESSION['permission'] < $level`. Therefore:
  - **`min_permission => 0` = "must be logged in, any rank"** — it does **not** make a page public.
  - **`min_permission => 5` = "logged in and admin."**
  - **Truly public = omit the key entirely** (then `isset` is false and no check runs).
- **The original claim was backwards.** A prior draft of this finding (and the first-pass agent)
  said `=> 0` meant "public" and that the doc example shipped an anonymous-readable page. **False.**
  `=> 0` requires login; the doc example is safe and correct for a profile page.
- **Failure mode is safe.** A developer who misreads `0` as "public" and uses it gets the
  *opposite* — a login requirement. The page ends up **more** locked than intended, never more
  open. No data exposure. This is the reassuring direction to be wrong in.
- **The real (gentler) problem:** the docs never explain the permission ladder, and never explain
  the genuinely counterintuitive split — **`0` = logged-in, *omit* = public** — which is exactly
  backwards from every reader's intuition ("0 = no requirement = open"). A confusing-but-safe
  trap. **Documentation gap, not a security bug.**
- **Fix (docs only):** add a short table to `docs/routing.md` covering the levels (0 = any
  logged-in user, 1–4 = member tiers, 5/7/9 = admin tiers, 10 = superadmin) **and** the critical
  "`min_permission => 0` requires login; omit the key for a truly public page" distinction. Leave
  the plugin example's `=> 0` as-is — it is correct.

---

## Severity 3 — Real platform bugs surfaced by the exercise

### 3.1 Docs describe a `profileMenu` slug rule that is internally contradictory AND never enforced (dead validator)

- **Docs:** `docs/plugin_developer_guide.md:387-388` — *"Must start with `<plugin-name>-` … for
  `profileMenu`, it is required by validation"* — and slugs must match `[a-z0-9-]`.
- **The rule as written is self-contradictory for underscore-named plugins.**
  `includes/PluginHelper.php:121` requires slugs to match `^[a-z0-9][a-z0-9-]*$` (no underscores)
  **and** `:200` requires `strpos($slug, $this->name . '-') === 0`. For a plugin directory named
  `dns_filtering` or `inbound_email`, the required prefix is `dns_filtering-` / `inbound_email-`,
  which contains an underscore and so can never match the no-underscore pattern. No slug
  satisfies both.
- **…but that validator is dead code — it is never called (re-verified June 2026).**
  `PluginHelper::validate()` (`includes/PluginHelper.php:86`) is the only place those two rules
  live, and a codebase-wide search finds **zero** call sites (`grep "->validate("` across
  `includes/`, `adm/`, `utils/`, `ajax/`, `serve.php` is empty for it; it's an abstract from
  `ComponentBase` that's implemented but never invoked).
- **What actually runs at menu sync is much looser.** `PluginManager::syncMenus()`'s validation
  closure (`includes/PluginManager.php:1331-1364`) checks only: `slug`/`title`/`order` present,
  `slug` a non-empty string, and (via a separate gate) that non-core plugins don't use a `core-*`
  slug. It does **not** enforce the `[a-z0-9-]` pattern or the `<plugin-name>-` prefix.
- **Confirmed live:** the shipped `dns_filtering` plugin uses profileMenu slug `dns-filtering`
  and its row exists in `amu_admin_menus` (`amu_location = user_dropdown`). That slug would
  **fail** the strict `PluginHelper.php:200` rule — it works precisely because that rule never
  runs.
- **Impact (corrected — milder than first drafted):** No plugin is actually blocked. The harm is
  (a) the docs describe an enforced rule that isn't enforced, and as written is impossible to obey
  for underscore-named plugins — a careful developer constrains themselves for nothing (the
  simulation chose a single-word name `linkvault` specifically to dodge it); and (b) a dead
  validator sits in the tree that, if ever wired up as-is, would reject every underscore-named
  plugin.
- **Fix (split):**
  - *Docs (primary):* drop the "must start with `<plugin-name>-`" requirement, or restate it as
    the *hyphenated* plugin name so it is at least satisfiable, and stop describing it as
    "required by validation" when the live path doesn't enforce it. Describe the rules
    `syncMenus()` actually applies.
  - *Code hygiene (optional):* either delete the unused `PluginHelper::validate()` (it misleads
    anyone reading the code for the "real" rules), or — if it's meant to be wired up — fix the
    underscore contradiction first (`str_replace('_', '-', $this->name) . '-'`) before invoking it.

### 3.2 CSRF docs oversell an opt-in helper (NOT a security gap — see resolution)

- **Docs:** `docs/formwriter.md:31` and §9 — *"Automatic CSRF protection — Every form gets a
  security token"* — strongly implying the server rejects bad/missing tokens automatically. The
  only server-side example is a *manual* `$formwriter->validateCSRF($_POST)` call.
- **Reality:** `validateCSRF()` exists (`FormWriterV2Base.php:282`) but **nothing calls it in the
  request path.** `grep` finds zero `validateCSRF` calls in `logic/`, `adm/logic/`, or
  `plugins/*/logic/`; `RouteHelper.php` and `serve.php` do not verify tokens. The only callers
  are `utils/forms_example_*.php`. So tokens are *emitted* automatically but only *checked* when a
  developer opts in by calling `validateCSRF()`.
- **Resolution (project decision, confirmed with maintainer):** This is **by design, not a gap.**
  CSRF token *checking* is an optional feature, available when a form wants it. The platform does
  **not** apply it to admin areas or anything behind a login, and it is needed only rarely. The
  absence of framework-wide enforcement is intentional; do not treat it as a vulnerability and do
  not "fix" it by wiring CSRF into the dispatch path.
- **The actual issue is documentation tone, not security.** The word "Automatic" overstates what
  is really an opt-in helper, which is what led the simulation to assume server-side verification
  happened for free (Assumption #7).
- **Fix (docs only, low priority):** Soften `docs/formwriter.md` §1 and §9 — describe CSRF as an
  **optional** per-form protection a handler can enable with `validateCSRF($input)`, note that
  the token is emitted automatically but verification is opt-in, and state that it is generally
  unnecessary for authenticated/admin forms. Remove the "Automatic CSRF protection" framing.

### 3.3 FormWriter's automatic **client-side** validation doesn't see plugin models (server-side validation is unaffected)

- **Docs:** `docs/formwriter.md:228-253` and `docs/validation.md` — FormWriter "automatically
  detects and applies validation rules from model `field_specifications`" by mapping a field
  prefix (`usr_`) to its model class. Presented as working for any model.
- **Reality (re-verified June 2026):** The prefix→model map is built by globbing **core only** —
  `glob(PathHelper::getIncludePath('data/*_class.php'))` (`FormWriterV2Base.php:638`). Plugin data
  classes under `plugins/{plugin}/data/` are never scanned, so a plugin field like `lvb_url` does
  not resolve to `LinkvaultBookmark` and picks up no auto-detected rules.
- **Scope of the impact is narrower than "unvalidated":**
  - This auto-detection only feeds **client-side** (in-browser JS) validation rules emitted by
    `end_form()`. **Server-side validation is unaffected** — it runs in the model's
    `prepare()`/`save()` regardless of where the form came from, so plugin data is still validated
    before it hits the database. This is a front-end-convenience / docs-overpromise gap, **not** a
    data-integrity hole.
  - **Passing the model to the form does not help, and that's the surprising part.** Field value
    auto-fill and validation auto-detection are wired separately: a `model`/`values` passed to
    `getFormWriter()` only feeds **values** (`registerField()` value-fill at
    `FormWriterV2Base.php:2283-2285`); validation detection runs **solely** through the core-only
    prefix map (`detectModelFromFieldName()` → `getModelPrefixMap()`, used at `:2312-2313`). So a
    developer who passes the model and reasonably expects rules to come with it is wrong — the
    simulation assumed exactly this (Assumption #5).
  - **Workaround that works today:** pass `validation` options inline on each plugin field; they
    merge over the (empty) auto-detected base (`FormWriterV2Base.php:2324-2326`).
- **Fix (best first):**
  1. *Targeted code fix (recommended):* let the `model` object already passed to `getFormWriter()`
     drive validation detection for fields whose prefix matches that model — i.e. use the passed
     model's `$field_specifications`, not just the core prefix map. Matches developer expectation
     exactly (it's what the simulation assumed), needs no per-render plugin scan, and sidesteps
     prefix collisions.
  2. *Broader code fix:* extend the glob to `plugins/*/data/*_class.php`. Simpler to state but
     loads every plugin's models on every form render (perf) and risks cross-plugin prefix clashes.
  3. *Docs-only:* state in the Plugin Developer Guide that plugin form fields do **not** get
     auto-detected client-side validation and must declare `validation` inline — and that
     server-side validation still applies regardless.

### 3.4 CRUD API per-record authorization — MOVED to its own spec

> This finding (the CRUD surface handing out any row by id because `authenticate_read`/
> `authenticate_write` defaulted to no-ops) grew past a single audit item. It is now owned
> in full by **`specs/api_crud_resource_authorization.md`**, which establishes opt-in resource
> exposure (`$api_readable`/`$api_writable`), deny-by-default owner-or-staff row scope (flipping
> the `SystemBase` default), and symmetric read/write field floors shared with the AI surface.
> The interim per-model hooks added during this audit, and the deferred "flip the default"
> decision, are both superseded there. See that spec for the current design and status.


## Severity 4 — Stale references & smaller gaps

### 4.1 Renamed/missing plugins in the documentation index

- The agent file's Documentation Index (`default_agents_template.md:99`) links
  **`plugins/email_forwarding/docs/overview.md`** as the "Email Forwarding Plugin." No such
  directory exists; the on-disk plugin is `plugins/inbound_email/` and the file
  `plugins/email_forwarding/docs/overview.md` is absent. The *internal* CLAUDE.md (DB record)
  already lists `inbound_email` — so the distributable template lags the internal one.
- **Fix:** Update the distributable agent template's index entry to
  `plugins/inbound_email/docs/overview.md` (Inbound Email), matching the internal record.

### 4.2 Inbound-email test table name drift in the agent template

- `default_agents_template.md:248` tells developers inbound emails land in **`iem_inbound_emails`**
  (with column `iem_received_time`). The internal CLAUDE.md and current system use
  **`iem_inbound_email_messages`** (the internal record was updated; the distributable one was
  not).
- **Fix:** Sync the distributable template's inbound-email testing note to
  `iem_inbound_email_messages` and the `iem_body_plain/html/raw` columns.

### 4.3 `LogicResult::success()` is referenced but does not exist

- **Docs:** `docs/formwriter.md:1234` — `return LogicResult::success(['message' => …]);`.
- **Reality:** `includes/LogicResult.php` defines only `render()`, `redirect()`, `error()`. No
  `success()`. A copied snippet fatals.
- **Fix:** Replace with `LogicResult::render([...])`.

### 4.4 `get_permission_level()` vs `get_permission()`

- **Docs:** `docs/logic_architecture.md:178` uses `$session->get_permission_level()`.
- **Reality:** The method is `get_permission()` (`includes/SessionControl.php:907`); no
  `get_permission_level()` exists.
- **Fix:** Change the example to `get_permission()`.

### 4.5 `deprecated` / `superseded_by` — documented behaviors are real (correction: the doc was right; this finding's earlier skepticism was wrong)

- **Docs:** `docs/plugin_developer_guide.md:233-256` describes `deprecated`/`superseded_by` with
  several behaviors (badge, sort-to-bottom, activation warning, exclusion from publish archives).
- **Reality (re-verified June 2026):** all the documented effects are actually implemented.
  - Admin UI badge + "replaced by" note: `adm/admin_plugins.php:174-177`.
  - Activation warning: `adm/logic/admin_plugins_logic.php:129-132`.
  - **Archive exclusion — confirmed for BOTH plugins and themes:**
    `plugins/server_manager/includes/publish_upgrade.php:408-409` skips deprecated **themes**
    and `:465-466` skips deprecated **plugins** from the publish archive.
- **Correction:** an earlier draft of this finding said the archive-exclusion and theme-side
  behaviors "were not located in code" and told the reader to verify before trusting the doc.
  That skepticism was wrong — they are implemented. **No fix needed; the docs are accurate.** This
  one is noted only to flag that the first-pass audit erred in the *cautious* direction here (the
  opposite of the overstatement pattern elsewhere) — a reminder that the un-rechecked items can be
  wrong in either direction.

### 4.6 Several plugin.json metadata keys are documented but never read

- **Docs:** `docs/plugin_developer_guide.md:210-230` shows `author`, `license`, `homepage`,
  `provides`, `tags` in the "complete" manifest example.
- **Reality (re-verified June 2026):** `PluginManager`/`PluginHelper` load the manifest but only
  consume `name`, `version`, `requires`, `depends`/`conflicts`, `settings`, `adminMenu`,
  `profileMenu`, `provisioners`, `receives_upgrades`, `included_in_publish`,
  `deprecated`/`superseded_by` (per 4.5). Confirmed inert (0 code references):
  `author`, `license`, `homepage`, `tags`. **`provides` is referenced exactly once**
  (`includes/PluginHelper.php:108`) — but only to reject a manifest declaring `provides: ['theme']`,
  and that check lives inside `PluginHelper::validate()`, which is **dead code** (never called —
  see 3.1). So `provides` is effectively inert too, just not literally zero-reference.
- **Impact:** Low — harmless metadata. Worth a one-line note so developers don't expect behavior
  (e.g. a dependency effect from `provides`) that isn't there.
- **Fix:** Mark these keys as "informational only / not consumed by the system" in the manifest
  reference.

---

## Documentation gaps (things a new developer needed and could not find)

These are not contradictions — they are simply absent, and the simulation had to invent answers.

- **G1. How to write a `getMultiResults()` / Multi class.** Docs repeatedly say "each Multi class
  defines its filter keys in `getMultiResults()`; read it directly" but never show how to *write*
  one — what `SystemMultiBase` requires, how option keys map to SQL, the constructor arg order
  (`criteria, order_by, limit, offset`). A plugin author building a collection class has no
  reference. *(Simulation invented the `MultiLinkvaultBookmark` key list by analogy.)*
- **G2. The `$prefix` static property.** Used in every model example, never documented as
  required or explained. It is in fact required (`SystemBase.php:59` throws without it) and is the
  key FormWriter uses for prefix→model mapping. Needs a sentence in the Data Models section.
- **G3. `SystemMailer` usage.** Listed under Key Integration Points with zero API surface. The
  digest task in the spec had to hand-wave sending. A minimal "send a templated email" example
  belongs in `docs/email_system.md` and should be linked from the scheduled-tasks digest example.
- **G4. `get_user_id()` and the SessionControl surface.** No doc enumerates `SessionControl`'s
  methods. `get_user_id()` exists (`SessionControl.php:828`) and is essential for any
  member-scoped plugin, but a developer can only discover it by reading source — which this
  exercise was meant to avoid. A short SessionControl method table would close G2/4.4/G4 at once.
- **G5. Plugin admin: two surfaces, no guidance on which.** Both `/plugins/{plugin}/admin/{page}`
  (AdminPage discovery route) and `/admin/{plugin}/*` → `plugins/{plugin}/views/admin/*.php`
  (auto-discovery) work. The docs describe both without saying when to use which. State the
  recommended one (AdminPage discovery route, mirroring `/adm/` + `/adm/logic/`).
- **G6. Plugin asset cache-busting.** `/plugins/{plugin}/assets/*` is a documented static route,
  but whether `ThemeHelper::asset()` cache-busting applies to plugin assets (or how a plugin
  references its own CSS/JS with a cache-bust query) is unspecified.

---

## Triage — sorted into work types

Every finding above, classified by the kind of work it actually needs. An item can carry a tail
in a second bucket (e.g. a bug fix that also wants a doc update); the **primary** bucket is where
the real work lives. Status reflects verification against current code.

### A. Verified or potential bugs (code is wrong or risky)

| ID | One-line | Status | Notes |
|---|---|---|---|
| **3.4** | CRUD API per-record authorization — *moved* to `specs/api_crud_resource_authorization.md` | **Relocated** | Owned in full by the dedicated CRUD-authorization spec (opt-in exposure, deny-by-default row scope, read/write field floors). No longer tracked here. |
| **3.1** | Docs describe a `profileMenu` slug rule (`<plugin-name>-` prefix) that is self-contradictory for underscore names AND never enforced — the validator (`PluginHelper::validate()`) is dead code | **Verified — milder than drafted** | Primary fix is **docs** (rule isn't enforced; live `syncMenus()` is looser). Code tail: delete or fix the dead validator. Not a blocking bug — `dns_filtering` works today. |
| **3.3** | FormWriter's **client-side** auto-validation globs core `data/` only, so plugin form fields get no in-browser rules; **server-side validation still works**. Passing the model doesn't help (value-fill and validation are wired separately) | **Verified — narrower than drafted** | `FormWriterV2Base.php:638`. Not a data hole. Best fix: let the passed `model` drive validation (recommended), or document the inline-`validation` workaround. |

### B. Documentation gaps & errors (code is fine; docs are wrong, stale, or missing)

| ID | One-line | Type |
|---|---|---|
| **1.1** | `getFormWriter('form1','v2',[…])` — second arg is an options array, not `'v2'`; dozens of occurrences | Wrong code in docs |
| **1.2** | `new Session($settings)` — class doesn't exist; use `SessionControl::get_instance()` | Wrong code in docs |
| **1.3** | "Complete Validation Example" built on removed `set_validate()` + V1 positional signatures | Wrong code in docs |
| **2.1** | Logic signature: one doc declares `array $input` canonical; most examples still show `($get_vars,$post_vars)` | Self-contradiction / high-volume staleness |
| **2.2** | `get_setting()` missing-key return: `''` (Settings doc) vs `null` (Plugin guide) | Contradiction |
| **3.2** | CSRF described as "Automatic"; it's an opt-in helper, not used behind login | Tone overstatement (resolved by-design) |
| **4.3** | `LogicResult::success()` referenced; only render/redirect/error exist | Wrong code in docs |
| **4.4** | `get_permission_level()` referenced; method is `get_permission()` | Wrong code in docs |
| **4.6** | `author`/`license`/`homepage`/`provides`/`tags` documented as manifest keys but never consumed | Dead-key documentation |
| **G1** | No reference for *writing* a `getMultiResults()` / Multi class | Missing doc |
| **G2** | `$prefix` static — required (`SystemBase.php:59`) but never documented | Missing doc |
| **G3** | `SystemMailer` listed with zero usage/API surface | Missing doc |
| **G4** | `SessionControl` method surface (incl. `get_user_id()`) never enumerated | Missing doc (closes G2/2.2/4.4 region) |
| **G5** | Two plugin-admin URL surfaces documented, no guidance on which to use | Missing guidance |
| **G6** | Plugin asset cache-busting story (`ThemeHelper::asset()` for plugins?) unspecified | Missing doc |
| **2.3** | Permission ladder undocumented; `min_permission => 0` means "logged in" while *omitting* it means "public" — counterintuitive but **safe** (corrected: not a security slip). Add a levels table + the 0-vs-omit note to `docs/routing.md` | Missing/confusing doc |

### C. Architecture / design decisions (needs a maintainer call, not just an edit)

| ID | Decision to make | Why it's architectural |
|---|---|---|
| **3.1** | Is `PluginHelper::validate()` meant to be live? If yes, fix the underscore contradiction and wire it in; if no, delete it. | Not a live design tension anymore (the validator is dead code, so nothing enforces the contradictory rule today). The only decision is keep-and-fix vs. delete — low stakes. |
| **3.3** | Recommended fix (let the passed `model` drive validation) is low-risk and mechanical — likely not a real architecture decision. Only the *broader* "scan all plugin `data/`" option carries perf/coupling tradeoffs. | Mostly resolved: the targeted fix avoids the design tension entirely. Listed here only so the broader-scan option isn't chosen by default. |
| **Process** | The distributable agent template (`default_agents_template.md`) drifted from the internal CLAUDE.md (4.1, 4.2). What keeps them in sync? | A regeneration/sync process question, not a one-time content fix. |

### Drift items (template vs. internal record) — bucket B mechanically, but note the process

- **4.1** — index links non-existent `plugins/email_forwarding/`; should be `plugins/inbound_email/`.
- **4.2** — inbound-email test table named `iem_inbound_emails`; current is `iem_inbound_email_messages`.
- **4.5** — `deprecated`/`superseded_by`: partly wired (admin UI consumes it); the archive-exclusion
  and theme-side claims were **not** confirmed in code — verify before trusting the doc.

---

## Recommended fix order

1. **3.2 (CSRF)** is resolved as a by-design choice — docs-only tone fix, low priority. (CRUD
   API scoping, formerly 3.4, is now tracked in `specs/api_crud_resource_authorization.md`.)
2. **1.1 / 1.2 / 1.3 / 4.3 / 4.4** — broken code in docs; cheap, mechanical, high-frequency.
3. **2.1 (logic signature)** — highest-volume staleness; rewrite examples wholesale.
4. **3.1 (slug rule)** and **3.3 (plugin FormWriter validation)** — code fixes with doc follow-up.
5. **2.2 / 2.3** and the **gaps G1–G6** — clarity improvements.
6. **4.1 / 4.2** — re-sync the distributable agent template with the internal CLAUDE.md (these
   already diverged once, which is itself a signal the template isn't being regenerated alongside
   the internal record).

> **Process note:** Several Severity-4 items (4.1, 4.2) are cases where the *internal* CLAUDE.md
> was updated but the *distributable* `default_agents_template.md` was not. The two are supposed
> to share a baseline (`agf_template_baseline_hash`). A new customer installs from the
> distributable template, so template-only staleness is exactly what a new developer trips over.
> Whatever process updates the internal record should also refresh the template.
