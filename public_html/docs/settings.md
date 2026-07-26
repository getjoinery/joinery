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
| `SettingsWriter` | The only path that writes a setting. |
| `Globalvars::get_setting()` | Reads a value, from the config file or the database. |
| `Setting` / `MultiSetting` | Row-level CRUD, used by the writer and by seeding. |

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
| `password` | password input | never emits a value |
| plus `secret: true` | password input, or textarea with `type` | never emits a value; blank keeps |

### Options that are discovered rather than fixed

```json
{ "name": "mailbox_provider", "default": "postfix", "group": "inbound",
  "label": "Active inbound provider", "type": "select",
  "options_from": "InboundProviderRegistry::labels",
  "options_include": "plugins/mailbox/includes/InboundProviderRegistry.php" }
```

The method returns a `value => label` map. Adding a provider class adds its
option — nothing else changes.

### Conditional fields

Declare `show_when` on the field that should appear, naming the setting and value
that reveals it:

```json
{ "name": "joinery_ai_local_model", "group": "provider", "label": "Local Model",
  "type": "text", "show_when": { "joinery_ai_llm_provider": "local" } }
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
what happened.

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
