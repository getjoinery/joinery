# Declared Settings

## Problem

A setting can be edited from more than one page, and the pages disagree about the
rules. Nothing enforces that a declared setting is reachable, that a reachable
setting is declared, or that two forms writing the same row apply the same
validation.

The state as measured on dev, 2026-07-26:

- `stg_settings` holds **361 rows**. **339** are declared in `settings.json` or a
  `plugin.json`. **27 are declared nowhere.**
- Plugins declare **137** settings. The Plugin Settings tab
  (`/admin/admin_settings_plugins`) renders **46** of them. Every plugin is either
  partially rendered or absent: dns_filtering 7 of 8, joinery_ai 22 of 35,
  mailbox 16 of 32, store 1 of 37, and server_manager (16), event_manager (6),
  vault (2) and bookings (1) render none at all.
- Eight mailbox settings are editable from **two** pages that enforce **different
  rules**. `admin_mailbox_settings.php` clamps `mailbox_forwarding_rate_limit_window`
  to `min 1` and the retention values to `min 0`; `plugins/mailbox/settings_form.php`
  renders the same names as plain text inputs with no constraint. Saving the second
  page writes values the first one refuses.
- The same two pages disagree about secrets. `settings_form.php` renders
  `mailbox_forwarding_smtp_password` with `'value' => $settings->get_setting(...)`,
  putting the stored secret in the HTML; `admin_mailbox_settings.php` renders the
  fleet secret as a `(stored — leave blank to keep)` placeholder and only writes a
  non-empty submission.
- Two write rules exist. The Plugin Settings tab writes only names the submitting
  plugin declares. `admin_settings.php` and `admin_settings_email.php` walk the
  whole POST and auto-create any non-reserved name. `Setting::isReservedName()` and
  `migrations/purge_reserved_setting_rows.php` (db version 155) fenced off the form
  plumbing that leaked in through the second rule, and auto-create was deliberately
  left live because ~15 settings the General page renders are declared nowhere.
  That fix recorded the intended next step: **declare first, then remove.** This
  spec is that step.

## Policy

**A setting is declared once, rendered by shared code, and validated on write
regardless of which page it came from.**

1. Every setting is declared in `settings.json` or a `plugin.json`, with its field
   spec. If it is not declared, it is not a setting.
2. No page hand-draws a settings field. Pages ask the shared renderer for a group.
3. Validation, secret handling and vault gating belong to the declaration, not to
   the page, so every write path enforces the same rules.
4. A page may **wrap** declared fields with context — state lines, gating,
   conditional groups, connection tests — but may never introduce a field of its own.

Duplication is allowed and total: the same declared group may appear on the core
tab and on a plugin's own page. It is one field shown twice, not two fields that
can drift.

There is no opt-out. Every non-`managed` setting renders on the core settings pages;
a plugin page mirrors what it chooses to. A declared setting therefore always has at
least one home, without anyone having to remember to give it one.

Machine-written values are declared `managed`: seeded and readable, never on a
form, shown read-only at most.

## The declaration

`settings.json` and `plugin.json` already carry `name` and `default`, and
`settings.json` already accepts an optional `helptext`. The field spec extends
that shape; every new key is optional, so an undeclared-today entry keeps working
until it is filled in.

```json
{
  "name": "mailbox_forwarding_rate_limit_window",
  "default": "3600",
  "group": "forwarding",
  "label": "Rate-limit window (seconds)",
  "type": "number",
  "min": 1,
  "helptext": "Rolling window the per-alias and per-domain limits are counted over."
}
```

Keys:

| Key | Meaning |
|---|---|
| `group` | Which box the field renders in. Pages request groups by name. Ungrouped fields fall into a default group. |
| `label` | Field label. Required for anything renderable. |
| `type` | `text`, `number`, `checkbox`, `select`, `password`, `textarea`. Drives both the FormWriter call and the write-time coercion. |
| `options` | Literal `value: label` map for `select`. |
| `options_from` | `Class::method` returning a `value: label` map, for options that are discovered rather than fixed. |
| `validation` | A FormWriter validation rule array, verbatim — `required`, `min`, `max`, `minlength`, `maxlength`, `pattern`, `email`, `url`, `matches`, `custom`. No new vocabulary; see below. |
| `show_when` | `{ "other_setting": "value" }`. Compiles to FormWriter `visibility_rules` — never a hand-rolled JS toggle. |
| `secret` | Renders as a password field, never emits the stored value, and only writes a non-empty submission. |
| `vault_gated` | Write requires an unlocked vault. Replaces the plugin-level `vaultGatedSettings` array. |
| `managed` | Machine-written. Never rendered on a form. Mutually exclusive with `label`. |

There is precedent for all of this in the tree: the email provider classes already
expose `getSettingsFields()` returning `key` / `label` / `type` / `helptext` /
`show_when`, and `adm/admin_settings_email.php:394-422` already renders whatever
they return. This spec generalises that loop and moves the declarations from PHP
methods into the manifests.

## Validation is the existing one

No new validator. `validation` in a declaration is a FormWriter rule array, passed
through unchanged, and enforced by `FormWriterV2Base::validate()` — the same code
path, the same vocabulary (`required`, `min`, `max`, `minlength`, `maxlength`,
`pattern`, `email`, `url`, `matches`, `custom`, …) and the same `$this->errors`
output that the rest of the platform uses. Anything exotic registers through
`FormWriterV2Base::registerValidator()` rather than being invented here.

Three things fall out of that for free: rules declared once drive both the browser
check and the server check; failures render through the standard error path, which
`specs/validation_error_summary.md` already summarises above the submit button; and
there is nothing new for a developer to learn.

**The one adjustment.** `FormWriter::validate()` checks the fields registered on
that form instance — that is, whatever the page happened to draw. That is page
scope, which is exactly the drift being fixed. So `SettingsWriter` seeds the rule
set from the *declarations* for the names being written, not from what the page
registered, and hands it to the same engine. Same validator, authoritative source.

**Why settings can't use the usual route.** Validation normally lives on a data
model's `$field_specifications`, and FormWriter picks it up via
`getModelValidation()`. That cannot work here: every setting is a row in
`stg_settings` with the same `stg_name` / `stg_value` shape, so there is no
per-setting column to hang rules on. The manifest is the only place they can live.

## How the policy enforces itself

Documentation is the last line, not the first. Three of the four rules can be
enforced where the violation happens, on the developer's machine, the first time
the page is loaded. The remainder needs a test, for a specific and known reason.

### Structural: no page can draw a settings field

Every FormWriter render method — all eight of `textinput`, `numberinput`,
`passwordinput`, `dropinput`, `checkboxinput`, `radioinput`, `dateinput`, `textbox`
— funnels through `registerField()`. That makes it a true choke point: a check there
sees every field, however the name was computed, which is exactly the case that
defeats grep-based checking.

`registerField()` gains a rule: if the field name matches a declared setting and the
caller is not `SettingsFieldRenderer`, throw in a development environment and log in
production. A developer who hand-draws a settings field finds out the moment they
load their own page, with a message naming the setting and pointing at the manifest.

The renderer identifies itself with a flag it sets while emitting a group, rather
than by inspecting the call stack.

### Structural: an undeclared name cannot become a setting

Two layers. Removing auto-create means no *form* can mint a row for an undeclared
name. `Setting::prepare()` then refuses to create a row whose name is neither
declared nor reserved, which covers the paths that never touch a form — plugin sync,
`ThemeManager.php:216`, `OAuth2ProviderConfig.php:84`.

### Why one test is still load-bearing

The model is not a complete choke point. `data/files_class.php:1019-1025` inserts
`file_signed_url_key` with raw SQL and `ON CONFLICT DO NOTHING`, deliberately, for
race-safe first-mint provisioning. That is a legitimate pattern and it bypasses
`Setting::prepare()` entirely. Migrations do the same by design.

So the **every row is declared** test is not belt-and-braces — it is the only thing
standing between a raw-SQL insert and a silent undeclared row. It is the check that
would have caught all 27.

### What no mechanism can enforce

- **Nothing, on orphan declarations.** With no opt-out, every non-`managed` setting
  renders on the core pages by virtue of being declared. A setting that is editable
  nowhere cannot exist — there is no state in which it would be omitted. This was a
  test before the opt-out question was settled; it is now a property.
- **Fresh-install round-trip** — only reproducible on a database built from the
  manifests alone.
- **Judgement inside a wrap** — a page can still gate a group badly. Rule 4 stops it
  inventing fields, not reasoning poorly, and that is the right place to stop.

## The two new pieces

### `SettingsFieldRenderer`

Takes a group name (or a list of them) and a FormWriter, resolves the declarations
for that group across core and plugin manifests, and emits the fields. Resolves
`options_from`, compiles `show_when` into `visibility_rules`, passes `validation`
through to the field, and suppresses the stored value for `secret` fields. This is
the only code in the platform that turns a setting into a form field.

### `SettingsWriter`

Takes a submitted array and a group scope, and writes only declared, non-`managed`
names within that scope. Runs the declared `validation` through FormWriter's
validator, applies type coercion, the leave-blank-to-keep rule for `secret`, and the
vault gate for `vault_gated`. Every settings page posts through it.

What is genuinely new here is only the *scope* — deciding which names may be written
and finding their rules. The checking itself is borrowed. `VaultGatedSettings` is
today the only central guard on a settings write, and `Setting::isReservedName()`
stays as the belt-and-braces boundary for form plumbing.

## Where the context lives

Rule 4 exists because some pages reason about the deployment, not about the field.
Mailbox's settings tab decides whether a spam scanner is present, whether the
deployment receives through a relay, and what happens on the side when outbound
mode flips to smarthost. None of that is field metadata.

The split:

- **Value-driven visibility** — "show the SRS secret when SRS is on" — is
  `show_when` in the declaration, and works identically on every page.
- **Topology-driven gating** — "no scanner on this box, so disable learning" — stays
  in the page, which decides *whether to render a group at all* and may disable
  fields within it and print state lines around it.

A page may therefore skip a group, gate a group, or annotate a group. It may not
render a field the manifest does not declare.

## Integration points, decided once

Every surface that renders or writes a setting today, and what happens to it:

| Surface | Fields | Disposition |
|---|---|---|
| `adm/admin_settings.php` (General) | 117 | Convert to groups. 1339 lines, only 117 draw fields — the rest is page context and stays. |
| `adm/admin_settings_email.php` | 31 | Convert to groups. Provider `getSettingsFields()` declarations move into `settings.json` with a `show_when` on the active provider. |
| `adm/admin_settings_plugins.php` | — | Becomes a renderer host: every non-`managed` plugin group, in manifest order. |
| `plugins/dns_filtering/settings_form.php` | 7 | Fully static. Straight lift to declarations, file deleted. |
| `plugins/joinery_ai/settings_form.php` | 22 | Fully static. Straight lift, file deleted. |
| `plugins/mailbox/settings_form.php` | 16 | Lift; the inbound-provider dropdown becomes `options_from: InboundProviderRegistry::labels`, and the active provider's fields become declared entries with `show_when` on `mailbox_provider`. File deleted. |
| `plugins/store/settings_form.php` | 1 | Lift; the donation-product list becomes `options_from`. File deleted. |
| `plugins/mailbox/admin/admin_mailbox_settings.php` | 14 | Keeps its boxes, tabs, scanner state line and relay gating; each box asks the renderer for its group. Its logic file posts through `SettingsWriter`. |
| `plugins/store/admin/admin_settings_payments.php` | 15 | 719 lines, only 15 draw fields. The live connection tests and test/live toggling are page context and stay untouched. |
| `plugins/server_manager` | 0 of 16 | No UI today. Declare and group; they appear on the Plugin Settings tab. |
| `plugins/event_manager` | 0 of 6 | Same. |
| `plugins/vault` | 0 of 2 | Same. |
| `plugins/bookings` | 0 of 1 | Same. |
| `PluginHelper::getSettingsForms()` | — | Deleted with the last `settings_form.php`. Discovery becomes manifest groups, not file existence. |

## The 27 undeclared rows

Three piles. Nothing here is ambiguous once the dead integrations are removed.

**Machine-written — declare `managed`:** `database_version`, `db_migration_version`,
`system_version`, `scheduled_tasks_last_cron_run`, `mailgun_version`,
`file_signed_url_key`, `server_manager_escrow_public_key_proven_fpr`,
`_store_event_autoactivate_v1`, `joinery_ai_last_failure_email_recipe_2`.

**Rendered on General today — declare with their existing labels:** `webDir`,
`baseDir`, `siteDir`, `node_dir`, `upload_dir`, `static_files_dir`,
`apache_error_log`.

**Live and undeclared — declare:** `comment_notification_emails`,
`single_purchase_notification_emails`, `subscription_notification_emails`,
`allow_remote_archive_refresh`, `archive_refresh_allowed_ips`,
`upgrade_server_active`, `joinery_ai_chat_default_tools`.

**Dead — delete:** `acuity_api_key`, `acuity_user_id`, `urbit_endpoint`,
`urbit_endpoint_password`. See below.

## Dead integration removal

Confirmed by search on 2026-07-26:

- **Urbit** — `urbit_endpoint` and `urbit_endpoint_password` have zero readers.
  The galactictribune theme's Urbit views query Azimuth data directly and never
  read these settings.
- **Acuity** — a closed loop. `includes/AcuityScheduling.php` and
  `includes/AcuitySchedulingOAuth.php` exist, but the only code that instantiates
  the client is `adm/admin_settings.php:798-812`, the block that tests the Acuity
  connection. The consumer widget was removed in the legacy-logic cleanup; nothing
  books, reads or syncs.

Remove: both `Acuity*` classes, `adm/admin_settings.php:784-812`, and
`embed.acuityscheduling.com` from the origins list in the `PublicPageBase.php` CSP
comment. Drop the four rows in a migration, following
`purge_reserved_setting_rows.php` so production nodes clean themselves on upgrade.

`specs/external_scheduling_integrations.md` is an active spec whose Phases A-C are
Calendly and an Acuity proxy provider. It needs rewriting to whatever replaces
them; that is a product decision and out of scope here.

## Build plan

The work splits into two halves that are worth keeping straight, because only one
of them stops the mess coming back.

**What prevents drift** is writing every setting down, routing every save through
one piece of code, and refusing to mint a row for anything undeclared. After that,
a developer who adds a field without declaring it finds out immediately — the field
does not save on their own machine — instead of it going unnoticed for two years.
That is Phases 1-4 below.

**What is cleanup** is converting the existing forms to draw from the declarations.
It buys a consistent look, removes the duplicated field definitions, and makes it
possible to check that everything on screen is declared. It is worth doing, and it
can trail at any pace, because rendering and writing are independent: a page can
keep its hand-drawn fields and still save through the shared writer. That is
Phases 5-6.

Phases 1 and 2 are independent of each other and of everything else.

---

**Phase 1 — the password fix.** Independent of the rest of this spec and the most
urgent thing in it. Ships alone. See the section below.

**Phase 2 — dead code.** Acuity and Urbit removal plus the four-row migration.
Takes the undeclared count from 27 to 23.

**Phase 3 — write everything down.** The manifest schema, schema validation during
plugin sync, and the declaration sweep itself: field specs for the settings that
have them today, declarations for the 23 undeclared rows (9 `managed`, 7 paths,
7 live), and the email providers' `getSettingsFields()` moved into `settings.json`
with `show_when` on the active provider. Nothing changes for an admin. Nothing is
enforced yet. At the end of this phase the question "what settings exist?" has an
answer that can be looked up.

**Phase 4 — one save path, then no auto-create.** `SettingsWriter` lands and every
settings page posts through it — core, plugin tab, and the two plugin pages — while
still drawing their own fields. This is where the validation split and the
secret-write rules become uniform, and where the mailbox pages stop disagreeing.
Auto-create then comes out, behind the shadow-mode procedure below. This phase
carries all the risk in the spec and gets its own section.

**Phase 5 — the renderer, page by page.** `SettingsFieldRenderer` lands and pages
convert at whatever pace suits. Order by payoff: the four `settings_form.php` files
first (dns_filtering and joinery_ai are static lifts; store and mailbox need
`options_from`), each deleted as its declarations render; then
`admin_mailbox_settings.php` and `admin_settings_payments.php`, which keep their
boxes, state lines and connection tests and ask the renderer for groups;
`getSettingsForms()` goes with the last `settings_form.php`. Then General and Email.
Each conversion is safe in isolation because the save path is already shared.

**Phase 6 — the orphans get a UI.** server_manager, event_manager, vault, bookings
and the rest of the settings that no page renders today. They are already declared
after Phase 3 and already saveable after Phase 4, so this is purely giving them a
place to be edited. First time any of them are reachable without a database write.

## The password fix (Phase 1)

Ten `passwordinput` call sites pass the stored secret as the field's value.
`preparePasswordData()` delegates to `prepareTextData()`, which takes
`$options['value']`, and `renderPasswordInput()` delegates to `renderTextInput()`,
which emits `value="..."`. The stored credential is therefore in the page HTML.

The affected secrets: `mailbox_forwarding_smtp_password`, `mailbox_srs_secret`,
both `dns_filtering_*_api_key`, `joinery_ai_local_api_key`,
`joinery_ai_anthropic_api_key`, `joinery_ai_fireworks_api_key`,
`joinery_ai_brave_search_api_key`, `joinery_ai_market_data_api_key`, and — via the
generic provider loop at `admin_settings_email.php:427` — whichever mail provider's
credentials are configured.

**The fix, in `FormWriterV2Base`:** `preparePasswordData()` discards any incoming
`value` and sets a `(stored — leave blank to keep)` placeholder when a value exists.
One change, every password field in the platform, no per-call-site edits.

**The coupling that must land in the same change.** Those fields work today
*because* they pre-fill: an unchanged save re-submits the same secret. Remove the
pre-fill without the matching write rule and the next save on each of those pages
blanks every credential on it. So every write path that handles a password field
must treat an empty submission as "leave the stored value alone" before, or in the
same commit as, the renderer change. `admin_mailbox_settings_logic.php` already does
this for the fleet secret and is the model.

Rotate the affected credentials after the fix — anyone who has opened those pages
has the values in browser history or cache.

## Phase 4 in detail

### What today's write path actually does

`admin_settings_logic.php` saves in two loops:

- **Lines 121-154 — the update loop.** It iterates `$user_settings`, the rows that
  already exist in `stg_settings`, and writes each one whose name appears in the
  POST. The write set is therefore driven by **what is in the database**, not by
  what is declared.
- **Lines 175-201 — the auto-create loop.** Any submitted, non-reserved name with
  no row yet gets one minted, logged at `error_log` level.

Switching to `SettingsWriter` changes the first loop's authority from the database
to the manifest, and deletes the second. That produces two different failures, not
one, and they have different blast radii.

### Failure mode A — undeclared existing rows stop saving

Applies to **every deployment, immediately.** A row that exists and is rendered on
General but is declared nowhere currently saves fine through the update loop, and
stops the moment the writer becomes declaration-driven. This is the 23 rows. It is
reproducible on dev, and the declaration sweep is the whole fix.

### Failure mode B — fresh installs only

Applies to **no existing box, and every new one.** A setting that is rendered but
declared nowhere and has no row can never come into existence once auto-create is
gone: the admin fills the field, saves, and the value evaporates. Dev cannot
reproduce this, because dev already has all 361 rows. Neither can any upgraded
production node. It appears only on an install built after the change.

This is precisely the failure the 2026-07-25 note predicted — "removing auto-create
before declaring those would break them on a fresh install" — and it is why that
note ended with *declare first, then remove*.

Both modes report success to the admin. Nothing in either loop has a concept of
"you submitted something I refused to write."

### Why a static sweep cannot finish the job

The set of names a settings page renders is not literal in its source. The Email
tab builds its fields from every discovered provider's `getSettingsFields()`, and
mailbox renders the active inbound provider's fields the same way. Grepping
`admin_settings_email.php` enumerates none of those names. Any sweep based on
reading page source will report clean and still be wrong.

So the sweep has to be driven by the same discovery the pages use, and confirmed
empirically.

### Shadow mode

`SettingsWriter` ships with enforcement **off** and reporting **on**. In that state
it writes exactly what today's loops write — including auto-create — and logs every
submitted, non-reserved name that it *would* have refused, with the page and the
group that submitted it.

The sequence, with Phase 3's declaration sweep already landed:

1. Ship the writer in shadow mode. Every settings page posts through it — core,
   plugin tab, and the two plugin pages — while still drawing its own fields.
2. Exercise it: save every settings page on dev and on at least one production node,
   cycling through each email provider and each inbound provider so the
   discovery-driven fields are actually rendered and submitted.
3. Harvest the refusal log, declare what it names, repeat until a full window
   passes clean.
4. Flip enforcement and delete the auto-create loop.

Enforcement is a code constant, not a setting — a settings row governing settings
writes is a circularity nobody wants to debug. Rollback is reverting that constant.

### Making it loud, permanently

After enforcement, a refused name is a manifest bug, not admin error. `SettingsWriter`
logs it and surfaces a `DisplayMessage` naming the refused fields on the page that
submitted them, the same way the vault gate already reports blocked settings at
`admin_settings_logic.php:156-167`. This is the part that matters long-term: the
junk rows this all started with accumulated for roughly two years because nothing
ever said a word.

### Behaviour that must survive the rewrite

Three things in the current loop are load-bearing and easy to lose:

- **An unchanged value is not a write** (lines 140-147). The form posts every
  setting on the page; without the guard, one Save re-stamps `stg_update_time` on
  ~160 rows and destroys the audit trail. The comment explaining this must move with
  the code.
- **The vault gate** (lines 131-136) gates only a genuine *change*, and reports what
  it blocked while saving everything else. Same semantics in the writer.
- **The `webDir` normalisation** (lines 137-139) strips the scheme and trailing
  slash. It does not survive: per open decision 3 it becomes a `pattern` rule that
  rejects such a value instead of quietly rewriting it. Deleting the transform
  without adding the rule leaves the setting accepting a value the rest of the
  platform cannot use, so the two land together.

### Proving it

Two checks land with this phase, and neither depends on local database state:

- **Every row is declared.** Compare `stg_settings` against the manifests and fail
  on anything undeclared that is not a reserved name. This is the permanent backstop,
  and it is load-bearing rather than belt-and-braces: `ThemeManager.php:216` and
  `OAuth2ProviderConfig.php:84` mint setting rows directly, without any form, so
  removing auto-create does not by itself guarantee every row stays declared.
- **Fresh install saves.** On a database built from `settings.json` and the plugin
  manifests alone, submit each settings page and assert every submitted field
  round-trips. This is the only check that can catch failure mode B.

A third check becomes possible once the renderer exists in Phase 5 — **rendered ⊆
declared**: drive `SettingsFieldRenderer` over every group with each provider and
plugin combination selected, collect the names it emits, and assert every one is
declared. That is the static sweep done correctly, by rendering rather than by
grepping, and it closes the gap that makes source-reading unreliable. It is not
available during Phase 4, which is why shadow mode carries the burden there.

## Files to change

**Create:** `includes/SettingsFieldRenderer.php`, `includes/SettingsWriter.php`,
`migrations/purge_dead_integration_settings.php`,
`tests/integration/declared_settings_test.php`,
`tests/integration/password_field_no_value_test.php`.

**Modify first (Phase 1, independent):** `includes/FormWriterV2Base.php`
(`preparePasswordData()`), plus the write path of every page carrying a password
field — `adm/logic/admin_settings_email_logic.php`,
`adm/logic/admin_settings_plugins_logic.php` — for the leave-blank-to-keep rule.

**Delete:** `includes/AcuityScheduling.php`, `includes/AcuitySchedulingOAuth.php`,
`plugins/dns_filtering/settings_form.php`, `plugins/joinery_ai/settings_form.php`,
`plugins/mailbox/settings_form.php`, `plugins/store/settings_form.php`.

**Modify:** `settings.json`, all nine `plugins/*/plugin.json` that declare settings,
`adm/admin_settings.php`, `adm/admin_settings_email.php`,
`adm/admin_settings_plugins.php` and its logic, `adm/logic/admin_settings_logic.php`,
`adm/logic/admin_settings_email_logic.php`,
`plugins/mailbox/admin/admin_mailbox_settings.php` and its logic,
`plugins/store/admin/admin_settings_payments.php`, `includes/PluginHelper.php`,
`includes/PublicPageBase.php`, `includes/VaultGatedSettings.php`,
`tests/integration/plugin_settings_tab_test.php`,
`tests/integration/settings_reserved_names_test.php`.

## Documentation

`docs/settings.md` is the home for this. Rewrite the Overview and Auto-Creation
sections to describe declared settings as the only mechanism — the field spec, the
group model, the renderer and writer, `managed`, `secret`, `vault_gated`, and the
rule that no page draws its own field. Per the documentation rules, it reads as
though it always worked this way: no "now", "previously", or migration narrative.

`docs/plugin_developer_guide.md` § Plugin Settings loses the two-step warning about
`settings_form.php` entirely, and gains the field-spec table plus how a plugin admin
page requests a group and wraps it with context.

`docs/admin_pages.md` gains a short rule: settings fields come from the renderer,
never from a FormWriter call in the page.

`docs/validation.md` gains one paragraph in § FormWriter Integration: settings carry
their rules in the manifest rather than on a model's `$field_specifications`, because
every setting shares one table row shape. The rule vocabulary is unchanged.

## Tests

`tests/integration/declared_settings_test.php`, db tier, extending the pattern in
`plugin_settings_tab_test.php` (snapshot every row it touches, restore after):

Each check is tagged with the phase it can first run in — several are meaningless
before their machinery exists, and one would fail everywhere if written too early.

- **Passwords never carry a value** *(Phase 1)*. No password field emits a `value`
  attribute, whatever the caller passes. Paired with: an empty password submission
  leaves the stored value intact, a non-empty one replaces it. These two must be
  asserted together — the first without the second is what blanks every credential.
- **No orphan rows** *(Phase 3, enforced from Phase 4)*. Every row in `stg_settings`
  is declared, or is a reserved name. This is the permanent backstop and the test
  that would have caught all 27. It must keep passing even though
  `ThemeManager.php:216` and `OAuth2ProviderConfig.php:84` create rows outside any
  form.
- **Schema** *(Phase 3)*. Every manifest entry validates: `managed` implies no
  `label`, `select` implies `options` or `options_from`, `options_from` resolves to
  a callable.
- **One rule per setting** *(Phase 4)*. For each declared `validation` rule, a
  violating write through `SettingsWriter` is rejected regardless of which page
  submitted it — including a page that never registered that field on its form.
  This is the check that distinguishes declaration scope from form scope.
- **Vault gate** *(Phase 4)*. Writing a `vault_gated` name with a locked vault fails
  and leaves the row unchanged — carried over from the existing coverage, which is
  the only coverage `VaultGatedSettings` has.
- **Fresh install saves** *(Phase 4)*. Described above. The only check that catches
  failure mode B.
- **Rendered ⊆ declared** *(Phase 5)*. Every name the renderer emits, across every
  provider and plugin combination, is declared.
- **No hand-drawn fields** *(after the last Phase 5 conversion)*. `registerField()`
  throws when a page draws a declared setting outside the renderer. Written earlier
  this fires on every unconverted page, so the throw is enabled last — it is the lock
  on the door, not the door. The test asserts the throw happens, rather than
  re-implementing the check by grepping.
- **Schema.** Every manifest entry validates: `managed` implies no `label`, `select`
  implies `options` or `options_from`, `options_from` resolves to a callable.

`settings_reserved_names_test.php` extends to assert both directions of the new
boundary at Phase 6: declared names still write, undeclared names do not.

## Open decisions

1. ~~Can a group opt out of the core tab?~~ **Resolved 2026-07-26: no.** There is no
   opt-out key. Every non-`managed` setting renders on the core settings pages, and a
   plugin page may additionally mirror any group it wants. One rule, no per-group
   branching, and "declared but editable nowhere" becomes impossible by construction
   rather than by test.
2. ~~What replaces Calendly/Acuity.~~ **Resolved 2026-07-26.** Calendly is deferred
   indefinitely and `specs/external_scheduling_integrations.md` stays as written.
   Acuity is dead: the code deletion in Phase 2 stands.
3. ~~Silent value rewriting.~~ **Resolved 2026-07-26: reject, do not rewrite.**
   `webDir` currently has its scheme and trailing slash stripped on save
   (`admin_settings_logic.php:137-139`) — the only place in the settings write paths
   where a submitted value is silently changed rather than accepted or rejected. It
   becomes a `pattern` rule that refuses a value carrying `http://`, `https://` or a
   trailing slash, with a message saying so. Nothing new to build, and what is stored
   is what was typed. No `normalize` concept enters the declaration; validation
   remains the only thing a declaration can say about a value.

   Two related behaviours that are *not* per-field and stay in the pages, named here
   so they are not lost in the conversion: **rules about combinations** (the plugin
   theme requires an active theme plugin; a new theme's required plugins must be
   active) and **side effects on change** (a changed preview image bumps
   `preview_image_increment`; changed homepage settings invalidate the page cache).
   Both are at the top of `admin_settings_logic.php` and neither belongs to a single
   setting.
4. **`stg_group_name` is vestigial.** 325 of 361 rows say `general`, it is written on
   create and never read — the only consumer is a commented-out dropdown at
   `admin_settings_email.php:661`. The manifest `group` is a separate concept and
   should not be wired to this column. Worth dropping the column, but not as part of
   this work.

## Out of scope

- `Globalvars_site.php` file-based config. Untouched — it is not a setting row.
- The `*_readonly` path mirrors on the General page. They stay display-only and
  reserved.
- Per-setting permissions or audit logging. Reasonable to want, not part of this.
- Settings UI redesign. Groups map to the boxes that exist today; this spec changes
  where fields come from, not what the pages look like.
