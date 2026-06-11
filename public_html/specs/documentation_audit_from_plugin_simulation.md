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
- **Reality:** **The single-`array $input` form is correct and universal.** Sampled
  `logic/register_logic.php:6`, `logic/items_logic.php:4`, `logic/checkout_logic.php:4`,
  `adm/logic/admin_oauth_providers_logic.php:15`, `adm/logic/admin_order_delete_logic.php:12`,
  `plugins/server_manager/logic/admin_marketplace_logic.php:4`,
  `plugins/inbound_email/logic/admin_inbound_email_reader_logic.php:15` — **all** use
  `(array $input): LogicResult`. Views call `process_logic(foo_logic(array_merge($_GET,$_POST)))`.
- **Impact:** The two-arg examples are stale. A developer cannot tell which is current and may
  write `($get_vars,$post_vars)` handlers that never receive POST data the way the framework
  passes it. The simulation hit this first (Assumption #1).
- **Fix:** Rewrite **all** `($get_vars, $post_vars)` examples in `docs/logic_architecture.md` and
  `docs/admin_pages.md` to the single-`$input` signature, and change the call sites to
  `array_merge($_GET, $_POST)`. This is the highest-volume staleness in the docs after 1.1.

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

### 2.3 Permission level for an ordinary logged-in member is never defined

- **Docs:** `docs/routing.md:122` lists `min_permission` as "Integer permission level required"
  and the plugin-routes example (`docs/plugin_developer_guide.md:167`) uses
  `'min_permission' => 0` for a member profile page. Admin docs define 5/7/9/10 but nothing
  defines what a normal member has, or what `0` vs `1` mean at the gate.
- **Reality:** `min_permission` calls `SessionControl::check_permission()`
  (`includes/RouteHelper.php:312`). Non-logged-in users have permission `0`; logged-in members
  are `1-4`; `5+` is admin. So `min_permission => 0` is effectively "public," and
  `min_permission => 1` is "any logged-in user." The plugin example's `=> 0` on a member page is
  therefore **wrong** — it would let anonymous users through.
- **Impact:** A developer copying `min_permission => 0` for a profile route ships an
  unauthenticated page. The simulation guessed correctly (Assumption #3) but only by reasoning
  past the example.
- **Fix:** Add a permission-level table to `docs/routing.md` (0 = public/anonymous, 1–4 =
  member, 5/7/9 = admin tiers, 10 = superadmin) and correct the plugin-routes example to
  `'min_permission' => 1`.

---

## Severity 3 — Real platform bugs surfaced by the exercise

### 3.1 `profileMenu` slug rule is unsatisfiable for any plugin with an underscore in its name

- **Docs:** `docs/plugin_developer_guide.md:387-388` — *"Must start with `<plugin-name>-` … for
  `profileMenu`, it is required by validation"* — and slugs must match `[a-z0-9-]`.
- **Reality:** `includes/PluginHelper.php:121` enforces `^[a-z0-9][a-z0-9-]*$` (no underscores)
  **and** `:200` enforces `strpos($slug, $this->name . '-') === 0`. For a plugin directory named
  `dns_filtering` or `inbound_email`, the required prefix is `dns_filtering-` / `inbound_email-`,
  which **contains an underscore and therefore can never match the slug pattern.** The two rules
  are mutually exclusive for any underscore-containing plugin name.
- **Evidence it bites in practice:** the shipped `plugins/dns_filtering/plugin.json` declares a
  profileMenu slug of `dns-filtering` (underscore → hyphen) — which **fails** the
  `must start with 'dns_filtering-'` check at `PluginHelper.php:200`. Either that plugin's menu
  validation is being bypassed, or the rule is not actually run on it.
- **Impact:** A new developer who (a) follows the documented convention of underscores for
  multi-word plugin names *and* (b) adds a `profileMenu` cannot pass validation. The simulation
  avoided it only by choosing a single-word name (`linkvault`) and documenting the reasoning.
- **Fix (code, not docs):** normalize the plugin name to a hyphenated form before building the
  required prefix (`str_replace('_', '-', $this->name) . '-'`), and accept slugs against that
  normalized prefix. Then update the doc to state the prefix is the *hyphenated* plugin name.

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

### 3.3 FormWriter model-validation auto-detection does not see plugin models

- **Docs:** `docs/formwriter.md:228-253` and `docs/validation.md` — FormWriter "automatically
  detects and applies validation rules from model `field_specifications`" by mapping a field
  prefix (`usr_`) to its model class. Presented as working for any model.
- **Reality:** The prefix→model map is built by globbing **core only**:
  `glob(PathHelper::getIncludePath('data/*_class.php'))` — `FormWriterV2Base.php:638`. Plugin
  data classes under `plugins/{plugin}/data/` are **not** scanned, so a plugin field like
  `lvb_url` never resolves to `LinkvaultBookmark` and gets **no** auto-validation.
- **Impact:** A plugin developer expecting client-side validation to appear automatically from
  `$field_specifications` (as the docs promise) gets silently unvalidated fields. They must pass
  `validation` options explicitly on every plugin form field. The simulation assumed auto-mapping
  worked for plugin models (Assumption #5) — wrong.
- **Fix:** Either extend the glob to include `plugins/*/data/*_class.php` (code), or document
  clearly in the Plugin Developer Guide that plugin models do **not** get FormWriter
  auto-validation and fields must declare `validation` inline.

### 3.4 CRUD API has no record-level authorization on most core models

- **Docs:** `docs/api.md:224` — *"Any SystemBase model class is available via the API"* — and
  `docs/api.md:29`, which says session keys are *"Always permission 4; object-level authorization
  is the effective gate."* That second line asserts a protection that, for most models, is not
  implemented.
- **Reality (re-verified against code, June 2026):**
  - **The gate.** `GET /api/v1/{ClassName}/{id}` loads the row and calls
    `$object->authenticate_read($auth_data)` before returning it (`api/apiv1.php:446-447`). That
    call is the only per-record gate. The `SystemBase` default is a literal no-op:
    `function authenticate_read($data) {}` (`includes/SystemBase.php:1479`). Writes/deletes go
    through `authenticate_write()` (`apiv1.php:477`), also a no-op by default.
  - **Who overrides it.** Only four core models implement a real check: `videos`, `files`,
    `stripe_invoices`, `orders` (`data/*_class.php`). Every other advertised model — `User`,
    `Message`, `Survey`, `SurveyAnswer`, `Comment`, `Group`, `Post`, … — returns any row by id to
    any active key with read permission (level 1, 3, or 4+; level 2 is write-only, blocked at
    `apiv1.php:440`). `GET /api/v1/Message/5` returns another user's private message;
    `GET /api/v1/User/123` returns another user's PII.
  - **Session-key angle.** `auth/login` mints a permission-4 key for **any** logged-in user
    (`docs/api.md:29`), and permission 4 includes delete. So an ordinary member's mobile-app
    token can read and soft-delete arbitrary rows of any unprotected core model.
- **Two corrections to the original draft of this finding:**
  1. **Plugin models are NOT exposed.** The API calls `discover_model_classes()` with no options
     (`apiv1.php:271`), so `include_plugins` defaults to `false`. The earlier claim conflated the
     deletion-system call site (which passes `include_plugins => true`). Plugin models like
     `LinkvaultBookmark` are unreachable via CRUD — the blast radius is **core models only**.
  2. **There is no opt-in allowlist; exposure is default-on.** The only opt-*out* is overriding
     `authenticate_read`. So the concern is not "any model" but "every core model that hasn't
     wired up the hook" — which is most of them.
- **Impact:** The platform's object-auth design relies on each model implementing
  `authenticate_read`/`authenticate_write`, but only four do. The docs promise object-level
  authorization that mostly does not exist. This is **not** safely "by design" the way CSRF is —
  the doc's own wording (`docs/api.md:29`) describes a gate that isn't there.
- **Fix (split):**
  - *Docs:* make `authenticate_read()` / `authenticate_write()` first-class in `docs/api.md` and
    the Plugin Developer Guide's Data Models section — document them as the required scoping hook,
    state plainly that an unoverridden model returns all rows to any read-capable key, and show an
    ownership-check override example. Correct the "object-level authorization is the effective
    gate" line to say the gate exists only where a model implements it.
  - *Architecture (maintainer decision):* consider flipping the default to deny — e.g. a base
    `authenticate_read` that requires an explicit per-model opt-in (mirroring the `$ai_readable`
    default-deny pattern the joinery_ai plugin already uses) — so a newly declared model is not
    silently world-readable. This is a design call, not a doc change.

---

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

### 4.5 `deprecated` / `superseded_by` consumption is partial

- **Docs:** `docs/plugin_developer_guide.md:233-256` describes `deprecated`/`superseded_by` with
  several behaviors (badge, sort-to-bottom, activation warning, exclusion from new-install
  archives).
- **Reality:** The admin UI consumes them (`adm/admin_plugins.php:174-177`, activation warning at
  `adm/logic/admin_plugins_logic.php:129-132`), but the parallel claims about *theme* deprecation
  and archive exclusion were not located in code during this audit. The fields are real and
  partly wired; not all documented effects were confirmed.
- **Fix:** Verify each documented effect (especially "excluded from deployment archives for new
  installs" and the theme-side behavior) against the publish pipeline, and trim any effect that
  isn't actually implemented.

### 4.6 Several plugin.json metadata keys are documented but never read

- **Docs:** `docs/plugin_developer_guide.md:210-230` shows `author`, `license`, `homepage`,
  `provides`, `tags` in the "complete" manifest example.
- **Reality:** `PluginManager`/`PluginHelper` load the manifest but only consume `name`,
  `version`, `requires`, `depends`/`conflicts`, `settings`, `adminMenu`, `profileMenu`,
  `provisioners`, `receives_upgrades`, `included_in_publish` (and `deprecated`/`superseded_by`
  per 4.5). `author`, `license`, `homepage`, `provides`, `tags` are inert.
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
| **3.4** | CRUD API: no record-level auth on most core models; `authenticate_read` default is a no-op, only 4 models override | **Verified** | Highest-severity. Plugin models *not* exposed (corrected). Needs a maintainer call on default-deny — see bucket C. Doc tail: document the hook. |
| **3.1** | `profileMenu` slug rule unsatisfiable for any underscore-named plugin; shipped `dns_filtering` slug fails its own check | **Verified** | Two validation rules are mutually exclusive (`PluginHelper.php:121` vs `:200`). Either bypassed in practice or a latent block. Doc tail: state the prefix is hyphenated. |
| **3.3** | FormWriter auto-validation globs core `data/` only; plugin models silently get no validation | **Verified** | `FormWriterV2Base.php:638`. Either extend the glob (code) or document the limitation (doc). Borderline B/architecture. |
| **2.3** | Plugin-routes example uses `min_permission => 0` on a member page → ships an anonymous-readable page if copied | **Verified** | The *example* is the bug, not the engine. Mostly a doc fix (bucket B) but flagged here because copying it is a real security slip. |

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
| **2.3** | (doc tail) add a permission-level table to `docs/routing.md` | Missing doc |
| **3.4** | (doc tail) document `authenticate_read`/`authenticate_write` as the required scoping hook | Missing doc |

### C. Architecture / design decisions (needs a maintainer call, not just an edit)

| ID | Decision to make | Why it's architectural |
|---|---|---|
| **3.4** | Should the CRUD API default to **deny** (per-model opt-in to read, like `$ai_readable`) instead of default-expose? | Flipping the default is a breaking, system-wide behavior change with security implications — not a doc edit. The doc fix is necessary either way; this is the deeper question. |
| **3.1** | Normalize plugin names to hyphenated form for slug prefixes, or change the documented naming convention? | Touches the plugin-naming contract and existing shipped plugins; needs a consistent rule across PluginHelper, the convention, and existing manifests. |
| **3.3** | Should FormWriter auto-discover plugin models for validation, or is core-only intentional (perf/coupling)? | Either extend discovery (couples FormWriter to every plugin's `data/`) or accept the limitation and document it. A design tradeoff, not an obvious fix. |
| **Process** | The distributable agent template (`default_agents_template.md`) drifted from the internal CLAUDE.md (4.1, 4.2). What keeps them in sync? | A regeneration/sync process question, not a one-time content fix. |

### Drift items (template vs. internal record) — bucket B mechanically, but note the process

- **4.1** — index links non-existent `plugins/email_forwarding/`; should be `plugins/inbound_email/`.
- **4.2** — inbound-email test table named `iem_inbound_emails`; current is `iem_inbound_email_messages`.
- **4.5** — `deprecated`/`superseded_by`: partly wired (admin UI consumes it); the archive-exclusion
  and theme-side claims were **not** confirmed in code — verify before trusting the doc.

---

## Recommended fix order

1. **3.4 (API scoping)** first — the one remaining finding where the docs could actively lead a
   developer into a security gap (still needs the API-visibility-gate verification noted above).
   **3.2 (CSRF) is resolved as a by-design choice** — docs-only tone fix, low priority.
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
