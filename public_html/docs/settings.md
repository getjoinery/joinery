# Settings System Documentation

## Overview

A setting is declared once, rendered by shared code, and validated on write
regardless of which page it came from.

Every setting lives in the `stg_settings` table, and every setting is described
by a **declaration** — an entry in `settings.json` at the `public_html/` root for
core settings, or in a plugin's `plugin.json` under `settings` for plugin-owned
ones. The declaration carries the setting's default *and* its field spec: label,
type, options, validation rules, whether it is a credential, whether it needs an
unlocked vault.

**If it is not declared, it is not a setting.** Three rules follow:

1. No page draws a settings field of its own. Pages ask `SettingsFieldRenderer`
   for a group.
2. Validation, credential handling and vault gating belong to the declaration,
   so every write path enforces the same rules.
3. A page may **wrap** declared fields with context — state lines, gating,
   conditional groups, connection tests — but may never introduce a field the
   manifest does not describe.

Duplication is allowed and total: the same declared group may appear on the core
settings tab and on a plugin's own page. That is one field shown twice, not two
fields that can drift.

There is no opt-out. Every non-`managed` setting renders on the settings pages,
so a declared setting always has at least one place it can be edited.

## How the Settings System Works

### Core components

| Piece | Job |
|---|---|
| `settings.json` / `plugin.json` | The declarations. The source of truth for what exists and what its rules are. |
| `SettingsDeclarations` | Reads the manifests and answers questions about them. |
| `SettingsFieldRenderer` | The only code that turns a setting into a form field. |
| `SettingsWriter` | Writes what an operator submitted on the settings page. |
| `Setting::put($name, $value)` | Writes one value from code — a setting the platform mints for itself. Refuses an undeclared name. |
| `Globalvars::get_setting()` | Reads a value, from the config file or the database. |
| `Setting` / `MultiSetting` | Row-level CRUD, used by the writers and by seeding. |

### Writing a setting from code

Some settings are not typed by anyone: a generated key id, the protocol the site
was last reached on, a cutover marker. `Setting::put()` is how code records one.

```php
Setting::put('backup_recovery_public_key', $b64);
```

The name has to be declared in `settings.json` or a plugin's `plugin.json`, and
`put()` throws otherwise — the same rule the settings page enforces. That
refusal is the point: an undeclared name writes a row nothing reads and no page
shows, so a misspelling becomes a setting that silently never takes effect.

Reading back is immediate. `get_setting()` re-reads a blank value from the
database rather than trusting its in-request copy, so there is no cache to
invalidate.

Raw SQL against `stg_settings` remains correct in two places, both marked with a
comment saying why: a conditional write whose `WHERE` clause is the point (an
idempotent no-op, a race guard), and installer or bootstrap code that runs
before declarations are loadable.

### The declaration

```json
{
  "name": "mailbox_forwarding_rate_limit_window",
  "default": "3600",
  "group": "forwarding",
  "label": "Rate-limit window (seconds)",
  "type": "number",
  "validation": { "number": true, "min": 1, "messages": { "min": "Must be 1 or more." } },
  "helptext": "Rolling window the per-alias and per-domain limits are counted over."
}
```

| Key | Meaning |
|---|---|
| `name` | The `stg_name`. Plugin settings must start with the plugin's directory name. |
| `default` | Seed value. Always a string — `"0"`/`"1"` for booleans, `"42"` for numbers. |
| `group` | Which box the field renders in. Pages request groups by name. Ungrouped core fields fall into `general`; ungrouped plugin fields fall into a group named after the plugin. |
| `label` | Field label. Required for anything renderable. |
| `type` | `text`, `number`, `checkbox`, `select`, `password`, `textarea`. Drives the FormWriter call. |
| `options` | Literal `value: label` map, for `select`. |
| `options_from` | `Class::method` returning a `value: label` map, for options that are discovered rather than fixed. |
| `options_include` | Path to the file defining that class, when it is not one of the always-loaded core classes. |
| `validation` | A FormWriter validation rule array, verbatim. |
| `show_when` | `{ "other_setting": "value" }`. Compiles to FormWriter `visibility_rules`. |
| `secret` | A credential: never emits its stored value, and only a non-empty submission is written. |
| `vault_gated` | Changing it requires an open vault unlock window. |
| `managed` | Machine-written. Never rendered on a form. Mutually exclusive with `label`. |
| `rows` | Rows for a `textarea`. |
| `helptext` | Explanation shown under the field. |

Group headings come from a `settingsGroups` map alongside `settings` in the same
manifest:

```json
"settingsGroups": {
    "forwarding": "Forwarding",
    "retention": "Retention and limits"
},
```

A plugin page can also show a group declared elsewhere, with
`"settingsMirrorGroups": ["inbound_provider"]`. The mirrored group is rendered
*and* writable from that page — it is the same field in a second place.

### Validation

`validation` is a FormWriter rule array, passed through unchanged and enforced by
`FormWriterV2Base::validate()`. Same code path, same vocabulary (`required`,
`min`, `max`, `minlength`, `maxlength`, `pattern`, `email`, `url`, `matches`,
`custom`, …), same error output as every other form on the platform. Anything
exotic registers through the static `FormWriterV2Base::registerValidator()`.

Three things fall out of that: rules declared once drive both the browser check
and the server check; failures render through the standard error path; and there
is nothing new to learn.

`SettingsWriter` seeds the rule set from the **declarations** for the names being
written, not from the fields the page happened to draw — so a page that never
rendered a field still cannot write past its rule. A `pattern` rule needs regex
delimiters (`"#^[a-z]+$#i"`), the same as anywhere else.

Only *changed* values are validated. A settings form posts every field on the
page, so validating unchanged re-submissions would let one stored value that
predates its rule veto every save on that page — an invisible failure.

Rules about a *combination* of settings (a plugin theme requires an active theme
plugin) and *side effects* of a change (a changed preview image bumps the
cache-busting index) are not per-field, and stay in the page's logic file.

### Credentials

A field declared `secret` never carries its stored value into the page.
`FormWriterV2Base::preparePasswordData()` discards any bound value and shows a
`(stored — leave blank to keep)` placeholder instead, so no caller can put a
credential into page source. The matching write rule is that a blank submission
keeps what is stored.

The two rules only work together: a field that renders empty, saved by a path
that takes an empty submission literally, blanks every credential on the page.

A `secret` normally renders as a password input. Declare `"type": "textarea"`
alongside it for a genuinely multi-line credential — a PEM private key, a
service-account JSON. The value is withheld either way.

**Removing a credential.** A blank field cannot mean both "I did not touch this"
and "delete this", so removal is said out loud. A credential that has something
stored renders with a **Clear** checkbox beside it, named `clear__<setting>`.
Three cases, and the writer honours all three:

| What the admin does | What happens |
|---|---|
| types a value | that value is written |
| leaves it blank | the stored value is kept |
| leaves it blank and ticks Clear | the stored value is wiped |

A typed value wins over a ticked Clear box, so pasting a new key after changing
your mind cannot silently throw it away. The checkbox appears only when there is
something to clear, and `clear__*` is a reserved name — it is an instruction
about a setting, never a setting.

`SettingsFieldRenderer::secretField()` draws the field and its Clear box
together. A page that still draws its own credential field calls it directly so
the contract is the same everywhere:

```php
SettingsFieldRenderer::secretField($formwriter, 'myplugin_api_key', 'API Key',
    $settings->get_setting('myplugin_api_key'));
```

It also picks up the declared `validation` rules, so the browser check on that
page matches what the writer enforces on save.

### Machine-written settings

A value written by code rather than by an admin is declared `managed`. It is
seeded and readable like any other setting, never appears on a form, and is
refused by the writer however the request arrives. Schema versions, cron
heartbeats, one-shot markers and keys minted on first use all belong here.

The managed-domain settings are the same idea across two machines. A
deployment whose domain was registered for its owner at checkout carries
`managed_domain_name`, `managed_domain_expiry_time`, `managed_domain_state`
and `managed_domain_manage_url`, written over SSH by the management node that
sold the domain and read by `ManagedDomainNotice` to decide whether — and how
urgently — to show the owner that the domain's renewal is about to become
theirs. Empty `managed_domain_state` renders nothing, which is what every
deployment that did not buy a domain this way has. Declaring them `managed`
is what keeps a local admin from editing a value only the management node can
know. See [Server Manager § Managed Domain Registration](../plugins/server_manager/docs/overview.md).

### Settings a management node writes

A deployment somebody else looks after carries settings its own admins do not
decide: the managed-domain notice, the hosting banner, the mail credentials the
management node minted for it. Each set is written by its own primitive, and
each of those carries **values only** — the setting names live in a script on
the node (`utils/managed_domain_notice.php`, `utils/hosted_plan_notice.php`,
`utils/hosted_mail_settings.php`, `utils/fleet_enroll.php`), where the
management node cannot reach them.

**There is no general settings writer, and that is the design.** A primitive
that took a name and a value would let whatever is on the other end of that
channel write any row in `stg_settings` — which is where the credentials are,
and where the mail settings are. A site whose outbound mail can be redirected is
a site whose password-reset email can be redirected, so the ability to *name* a
setting is the ability to take over accounts on every node a management node
manages. Each new set of pushed settings therefore costs a small node-side
script and an entry in the agent's vocabulary. That cost is the feature.

**What that bounds, and what it does not.** Compiled names decide *which*
settings a management node can write. They do not decide *which nodes* it can
write them on: any deployment whose agent offers a primitive can be handed that
primitive's values by the management node it joined — a site the operator hosts,
a site on the buyer's own cloud account, or a site somebody self-hosts and
enrolled for management. The `hosted_mail_settings` primitive is the one where
that matters, because pointing a site's outbound mail somewhere else also points
its password-reset email somewhere else.

That reach is accepted deliberately. Enrolling a node already grants its
management node `apply_update`, the three restores and `decommission_site` — the
power to replace the site's code outright, which subsumes redirecting its mail.
The distinction weighed against it is that those powers are loud and a
redirected mail server is quiet, and the judgement made was that a node's
operator has already extended total trust to the management node it joined. It
is written down here so the acceptance is explicit rather than assumed.

Two consequences worth knowing:

- **A pushed setting is not automatically off-limits locally.** The mail
  settings are ordinary, editable fields: an owner who outgrows a hosted
  allowance moves to their own mail account by editing them. The operator writes
  them once; the owner owns them. Only the `hosted_plan_*` banner values are
  also `managed`, because nothing local has an opinion about them.
- **Every push converges rather than merges.** A value the management node stops
  sending is *cleared*, which is what retires a stale banner and what hands a
  site back to its owner's own mail account. A setting the primitive does not
  carry is never touched.

### Registrar credentials

The domain-registration leg's credentials
(`server_manager_namecheap_api_user`, `_api_key`, `_client_ip`, `_sandbox`,
and `server_manager_domain_tlds`) are declared by the server_manager plugin
but entered on **Server Manager → Provisioning**, not on the settings page —
the card there seals the API key at rest through `ProvisioningSetup::
writeSecret()` and shows the account-eligibility and IP-allowlist constraints
beside the field they apply to. `secret: true` on a declaration marks a value
as one not to display; it does not encrypt it, which is why the credential
has a writer that does.

### Missing settings

`Globalvars::get_setting('name')` for a row that does not exist:

- returns an empty string (`''`)
- logs `Settings: Returning empty default for missing setting 'name'`
- does not cache the empty value, and does not throw

Pass the `$fail_silently` flag to suppress the log where absence is expected.

### Reserved names

Not every field in a POST is a setting. The form machinery contributes a CSRF
token, submit buttons, captcha responses and the routing parameter, and the
General page renders `*_readonly` mirrors of paths that come from
`Globalvars_site.php`. `Setting::isReservedName()` names that boundary — the
fixed list, the `submit_*` and `clear__*` prefixes, and the `*_readonly` suffix.
Those names are never written and never created.

## Adding a setting

### Core

Add an entry to `settings.json`:

```json
{ "name": "my_feature_enabled", "default": "0", "group": "general",
  "label": "My feature enabled", "type": "select",
  "options": { "1": "Yes", "0": "No" } }
```

Run `update_database` from admin utilities to seed the row. It appears on the
settings pages automatically.

### Plugin

Add an entry to the plugin's `plugin.json` under `settings`. The name must start
with the plugin's directory name:

```json
"settings": [
    { "name": "myplugin_api_url", "default": "", "group": "connection",
      "label": "API URL", "type": "text",
      "helptext": "Base URL of the service." },
    { "name": "myplugin_api_key", "default": "", "group": "connection",
      "label": "API Key", "secret": true }
],
"settingsGroups": { "connection": "Connection" }
```

Run "Sync with Filesystem" from the admin Plugins page. The plugin gets its own
section on **Admin → Settings → Plugin Settings**, one independent form with its
own Save. Nothing else is needed — there is no form file to write.

The manifest is also the write scope: a save writes only the names the submitting
plugin declares.

### Available field types

| `type` | Renders as | Notes |
|---|---|---|
| `text` (default) | text input | |
| `number` | number input | declared `min`/`max` also drive the browser spinner |
| `checkbox` | checkbox | value is `"1"` or `"0"` |
| `select` | dropdown | needs `options` or `options_from` |
| `textarea` | multi-line box | `rows` sets the height |
| `color` | colour picker | stores the hex value |
| `password` | password input | never emits a value |
| plus `secret: true` | password input, or textarea with `type` | never emits a value; blank keeps |

A `checkbox` is drawn with a hidden `0` of the same name in front of it, so an
unticked box still submits. Without that, a browser sends nothing for an unticked
box, "absent" is indistinguishable from "not on this page", and the setting could
be turned on but never off.

### Options that are discovered rather than fixed

```json
{ "name": "mailbox_provider", "default": "postfix", "group": "inbound",
  "label": "Active inbound provider", "type": "select",
  "options_from": "InboundProviderRegistry::labels",
  "options_include": "plugins/mailbox/includes/InboundProviderRegistry.php" }
```

The method returns a `value => label` map. Adding a provider class adds its
option — nothing else changes.

Core lists live in `CoreSettingOptions`: themes, theme plugins, timezones, site
folders, homepage candidates, email and mailing list services, email templates,
connected mail accounts. Anything whose choices can be written down belongs in
the manifest as a literal `options` map instead.

An option list keys on **what gets stored**, which is whatever the code that
reads the setting looks the value up by. The email template settings key on the
template *name*, because `EmailTemplate` filters on `emt_name`; keying them on
the row id would produce a dropdown that reads correctly and stores a value no
consumer can resolve. Where a stored value is not in the list, `CoreSettingOptions`
keeps it and labels it rather than dropping it, so a wrong value stays visible
and survives a save instead of being quietly swapped for whichever option sorted
first.

### Conditional fields

Declare `show_when` on the field that should appear, naming the setting and value
that reveals it:

```json
{ "name": "comments_active", "group": "blog", "label": "Allow comments",
  "type": "select", "options": { "1": "Yes", "0": "No" },
  "show_when": { "blog_active": "1" } }
```

The renderer inverts this into FormWriter `visibility_rules` on the trigger
field, across the whole page — so a picker in one box can reveal fields in a
later one. Never hand-roll a JS toggle.

## Wrapping declared fields with context

Some pages reason about the deployment rather than about a field. The mailbox
settings tab decides whether a spam scanner is present, whether the deployment
receives through a relay, and what happens when outbound mode flips. None of that
is field metadata.

The split:

- **Value-driven visibility** — "show the SRS secret when SRS is on" — is
  `show_when` in the declaration, and behaves identically on every page.
- **Topology-driven gating** — "no scanner on this box, so disable learning" —
  stays in the page, which decides *whether to render a group at all*, may
  disable fields within it, and may print state lines around it.

```php
require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));

if ($scanner_present) {
    $page->begin_box(array('title' => 'Spam filtering'));
    echo '<p>' . htmlspecialchars($scanner_state) . '</p>';
    SettingsFieldRenderer::renderGroup($form, 'spam', array(
        'source'   => 'mailbox',
        'disabled' => $learning_available ? array() : array('mailbox_spam_learning_enabled'),
    ));
    $page->end_box();
}
```

A page may skip a group, gate a group, or annotate a group. It may not render a
field the manifest does not declare.

### What the renderer takes

| Method | Use |
|---|---|
| `renderGroup($form, $group, $options)` | one group, no heading — the page supplies its own box |
| `renderGroups($form, [$group, …], $options)` | several groups, each under its declared heading; this is how the settings tabs are built |
| `renderSource($form, $plugin)` | every group a plugin declares, in manifest order — what the Plugin Settings tab hands each plugin |
| `secretField($form, $name, $label, $stored)` | one credential, when a page needs it outside a group |

`$options`:

| Key | Effect |
|---|---|
| `source` | `core` or a plugin name |
| `only` | render just these names — a page splitting one group across two boxes calls `only` twice rather than declaring the group twice |
| `skip` | leave these out |
| `disabled` | render these, but not editable |
| `values` | show these values instead of the stored ones |
| `field_options` | extra FormWriter options per field, for page context. Two keys are read by the renderer: `helptext_append` adds to the declared help rather than replacing it, and `clearable => false` drops a credential's Clear box on a page whose save cannot honour it |
| `heading_level` | tag for `renderGroups` headings, default `h4` |

`only` and `skip` both narrow a set the manifest decided; neither can add a field.

### If a page draws one anyway

Every FormWriter render method funnels through `registerField()`, so that is the
one place that sees every field however its name was computed — the case a grep
cannot catch. Drawing a declared setting outside the renderer throws on a box
with `debug` on, naming the setting, its group, and the manifest file to edit. In
production it is logged and the field is drawn: a live site refusing to render a
settings page over a manifest problem would be the worse failure.

## Saving

Every settings page posts through `SettingsWriter`:

```php
$write = SettingsWriter::write($input, array(
    'page'   => 'admin_mailbox_settings',   // for the log and messages
    'source' => 'mailbox',                  // restrict to one plugin's declarations
));
SettingsWriter::reportTo($write, '~/plugins/mailbox/admin/~');
```

It returns what happened:

| Key | Meaning |
|---|---|
| `written` | names whose stored value changed |
| `refused` | submitted names that are not writable settings |
| `vault_blocked` | names held back for want of an unlock window |
| `errors` | `name => messages` from the declared validation |
| `kept_secrets` | credentials left alone because the field came back blank |
| `cleared_secrets` | credentials wiped because the field came back blank with Clear ticked |

`reportTo()` turns that into the messages an admin sees. A refused name is a
manifest bug, not admin error, and it is said out loud — silence is how junk rows
accumulated for two years.

Behaviour worth knowing:

- **An unchanged value is not a write.** The forms post every field on the page,
  so writing unchanged values would re-stamp `stg_update_time` on a hundred-odd
  rows and destroy the only record of when a value actually changed.
- **A validation failure writes nothing at all**, rather than writing the valid
  half and leaving the admin to guess.
- **The vault gate blocks the change, not the save.** Everything else on the page
  still saves, and the admin is told what was held back.

### Enforcement and shadow mode

`SettingsWriter::ENFORCE_SCOPE` controls whether an *undeclared* name submitted
to an unscoped save is refused or written-and-logged. It is a code constant, not
a setting — a setting governing settings writes is a circularity nobody wants to
debug.

With it off, an unscoped save behaves as it always did and logs what it would
have refused. That log is the evidence for turning it on: the set of names a
settings page can submit is not literal in its source (the Email tab builds its
fields from each provider's declarations), so no source sweep can answer the
question, but exercising the pages can.

Scope refusals — a name belonging to core or another plugin when the caller named
one source — are enforced unconditionally, in both modes.

## Using settings in code

```php
$settings = Globalvars::get_instance();

$url = $settings->get_setting('myplugin_api_url');

// Booleans are stored as the strings "1" and "0".
if ($settings->get_setting('myplugin_enabled') === '1') { /* … */ }

// Numbers need a cast, and a floor for the empty case.
$limit = intval($settings->get_setting('myplugin_limit')) ?: 100;

// JSON for structured values.
$config = json_decode($settings->get_setting('myplugin_config'), true) ?: array();
```

There is no `set_setting()`. Code that must write a setting goes through
`SettingsWriter`, or through `Setting` directly for a `managed` value.

## Plugin uninstall

Uninstalling a plugin deletes the rows it currently declares
(`Setting::unseed_declared()`). Rows from settings the plugin used to declare and
has since dropped are left in place by design — but they will then fail the
"every row is declared" check, so drop them in a migration when you drop the
declaration.

## OAuth provider settings

OAuth app credentials are core settings, shared across every consumer (inbound
IMAP, social login, outbound send), and are entered at **Admin → System → OAuth
Providers**:

| Setting | Notes |
|---------|-------|
| `oauth_google_client_id` | |
| `oauth_google_client_secret` | stored **encrypted** via `SecretBox` |
| `oauth_microsoft_client_id` | |
| `oauth_microsoft_client_secret` | stored **encrypted** via `SecretBox` |
| `oauth_microsoft_tenant` | `common` / `organizations` / `consumers` / a tenant id |

Client *secret* values are written through `SecretBox` before being persisted and
decrypted on read by the provider's `getClientSecret()`, so a secret is never
stored in plaintext. [`SecretBox`](secret_box.md) is keyed from `secret_box_key`
in `config/Globalvars_site.php`. See [OAuth2 Core](oauth2.md) for the OAuth
abstraction.

## System-managed settings

Some settings are changed through a dedicated screen rather than the settings
pages, because changing them has consequences the settings form cannot check.

| Setting | What it controls | Where to change |
|---|---|---|
| `theme_template` | Active visual theme | Admin → Settings |
| `active_theme_plugin` | Plugin that provides the theme | Admin → Settings |

**Do not confuse `theme_template` with `site_template` in
`config/Globalvars_site.php`.** The latter is the site installation directory
identifier and is almost never changed after setup.

## Transport security headers

Every page sent through `PublicPageBase` (public views and, through `PublicPage`, the admin) carries a set of response headers that tell the browser what it may do with the page. They are emitted once by `PublicPageBase::send_transport_headers()`, and most are switchable in the **protocol** settings group:

| Header | Setting | Default |
|---|---|---|
| `Strict-Transport-Security` | `enable_hsts` (only under `protocol_mode = https_redirect`) | off |
| `X-Content-Type-Options: nosniff` | always sent | — |
| `X-Permitted-Cross-Domain-Policies: none` | always sent | — |
| `X-Frame-Options: SAMEORIGIN` | `enable_x_frame_options` | on |
| `Referrer-Policy: strict-origin-when-cross-origin` | `enable_referrer_policy` | on |
| `Content-Security-Policy` | `enable_csp`, `csp_report_only` | off, report-only on |

**Content-Security-Policy** names the hosts that may supply scripts, styles, frames and the rest, so an injected script or a leak to an unlisted host is stopped by the browser itself. The policy is `PublicPageBase::csp_policy()`: `'self'` plus the third parties pages actually load — Stripe and PayPal (scripts, checkout frames, the PayPal redirect form), hCaptcha and reCAPTCHA, YouTube embeds, Google Fonts, and the script CDNs themes declare — with `object-src 'none'`, `base-uri 'self'` and `frame-ancestors 'self'`. It keeps `'unsafe-inline'` for scripts and styles: FormWriter output, the views and the plugins rely on inline handlers and style blocks throughout, and removing them is a separate project.

Switching it on: turn on `enable_csp` with `csp_report_only` left on. The header goes out as `Content-Security-Policy-Report-Only`, which blocks nothing and reports every violation to the browser console. Use the site — checkout, uploads, the admin — and add any host that shows up there to `csp_policy()`. Then turn `csp_report_only` off to enforce. A site that has never turned it on sends no CSP header at all. Test: `tests/security/csp_header_test.php`.

## Troubleshooting

### A setting does not appear on any page

Check that it is declared, and that it is not `managed` — a managed setting is
deliberately never rendered. Then check the plugin is active; a deactivated
plugin's rows persist but are neither shown nor writable.

### A setting does not save

Look in the error log for `SettingsWriter[<page>]`. A refused name is named
there. The usual causes: the name is not declared, it is declared `managed`, or
it belongs to a different source than the one the page named.

### A whole page refuses to save

Check the messages above the submit button: a declared validation rule failed, and
nothing is written until it passes. Only changed fields are validated, so the
offending field is one you just touched.

### A credential came back blank after a save

It should not — a blank credential submission keeps the stored value, and wiping
one takes a ticked Clear box. If a value really was lost, check that the setting
is declared `secret`; the write path keys off the declaration, not off the field
type.

### The Clear box is missing next to a credential

It only renders when something is stored. An empty credential has nothing to
clear, and an unconditional checkbox would invite an admin to tick it and wonder
what happened. A page that writes outside `SettingsWriter` can also suppress it
with `clearable => false`, because a control the save path cannot honour is worse
than no control.

### A page throws saying it may not draw its own field

The page called a FormWriter method with a declared setting's name. Ask
`SettingsFieldRenderer` for the group the exception names, and move whatever the
page was saying about the field — its label, type, help text or validation — into
the manifest, where every other page showing that setting will pick it up too.

### A dropdown shows a value labelled "not a template name"

The stored value is not one of the choices. That is deliberate: the wrong value
is shown rather than dropped, because silently selecting the first valid option
would change which template the site uses on the next save. Pick the right one.

### Empty values after a fresh install

Confirm the setting has a `default` in its manifest, and that `update_database`
(core) or plugin sync ran. Seeding never overwrites an existing row.

## Best practices

1. **Prefix plugin settings** with the plugin's directory name. Sync enforces it.
2. **Declare the field spec, not just the default.** A declaration without a
   `label` renders with its raw name — legible to you, not to an admin.
3. **Put the rule on the declaration**, not in the page. That is what makes two
   pages agree.
4. **Mark credentials `secret`.** It is what keeps them out of page source and
   what makes a blank save mean "keep". A test enforces this by name: a setting
   called `*_secret`, `*_password`, `*_token`, `*api_key*`, `*private_key*`,
   `*signing_key*` or `*service_account*` must either be `secret` or be listed
   in `$public_by_design` in
   `tests/integration/password_field_no_value_test.php` with a reason. The
   allowlist is short on purpose — today it holds only the visible halves of
   credential pairs (Stripe's publishable key, PayPal's client id, Mailjet's
   public key).
5. **Mark machine-written values `managed`.** Otherwise they show up as editable
   fields nobody should touch.
6. **Group related settings** and give the group a heading in `settingsGroups`.
7. **Handle empty defaults in code** — `get_setting()` returns `''` for anything
   unset, so supply a floor: `intval(...) ?: 3600`.

## Tests

- `tests/integration/declared_settings_test.php` — every stored row is declared,
  every declaration is well-formed, a declared rule binds every page, and the
  behaviours the write path must keep.
- `tests/integration/password_field_no_value_test.php` — no password field emits
  a value, and a blank credential submission keeps the stored one.
- `tests/integration/plugin_settings_tab_test.php` — discovery, the shared tab
  list, rendered-is-declared, and write scope.
- `tests/integration/settings_reserved_names_test.php` — the boundary between a
  setting and the form plumbing that shares its POST.
