# Stored credentials show a locked field with a Reset button

**Status:** Implemented 2026-09-22 (WP1–WP3). See §11 for where the build
differs from the text below. Owner decisions: a stored value
is visibly present; leaving it alone keeps it; removing it needs no
checkbox; anything typed replaces it; and **all of it lives in FormWriter**
— a page passes one argument when it draws the field and calls one method
when it saves, and does nothing else. The form follows the established
pattern for write-only secrets (Grafana's secret field): a stored value
shows as a locked field, and a Reset button unlocks an empty one. An
editable row of asterisks meaning "keep" was considered and rejected: a
partly edited placeholder gets saved as the credential.

**Depends on:** nothing unbuilt. **Touches:** FormWriter (base, HTML5 and
JSON writers), one new script under `assets/js/`, `SettingsFieldRenderer`,
`SettingsWriter`, the rule test, and the twenty hand-drawn credential
fields listed in §6.

---

## 1. What changes for the person at the keyboard

Today a field for a stored credential renders empty with a grey
"(stored — leave blank to keep)" hint, and removing the value takes a
separate *Clear* checkbox — three different kinds of checkbox, depending on
the page. Nobody reading the form can tell an empty field that means
"nothing stored" from one that means "hidden", except by the hint, and the
owner did not find the hint.

After this spec, a credential field is in one of three states:

| State | The person sees | Saving the form does |
|---|---|---|
| Nothing stored | an empty field | stores what was typed; nothing when left blank |
| Stored | a locked field showing `••••••••••••`, with a **Reset** button beside it | keeps the stored value |
| Stored, after Reset | an empty field, focused, with **Undo** beside it | stores what was typed; **removes** the value when left blank |

**Undo** returns the field to the locked state, so a Reset clicked by
mistake costs nothing. Clicking Reset changes nothing by itself; only
saving the form does.

Every credential on the platform behaves this way: declared settings on
the settings pages and the wizard, and the hand-drawn credential fields on
the Server Manager, backup, mailbox and OAuth pages. The three Clear
conventions (`clear__<setting>`, `ncp_promotion_code_clear`,
`node_creds_remove`) retire.

## 2. The rule that does not change

**The real value never reaches the page.** A password field carries no
`value`, ever — not the stored secret, not what was just submitted, not a
placeholder string standing in for it. The dots in the locked field are
the input's `placeholder`, which the browser never submits and the person
cannot edit. This is rule A of
`tests/integration/password_field_no_value_test.php` today and it stays.

## 3. The whole contract a page sees

**Drawing.** `passwordinput()` takes one new option:

```php
$fw->passwordinput('ncp_api_key', 'Namecheap API key', [
    'stored' => $domains['key_present'],   // bool: is a value stored?
]);
```

`stored` is the only way FormWriter learns a value exists. The bound value
(`value`, `set_values()`, `set_model()`) is never consulted for a password
field: it is discarded as today, and it no longer switches on a
placeholder. That matters on a re-render — a form rebuilt from the
submitted request would otherwise read "the person typed something" as
"something is stored", or read a locked field's absence as "nothing is
stored" and offer an unlocked blank field whose save would delete the
value.

A multi-line credential (a PEM key, a service-account JSON) is the same
call with `'rows' => N`; FormWriter draws a textarea with the same three
states. Credentials stop going through `textbox()`.

**Saving.** One static method, beside `process_datetimeinput()` and
`process_repeater_data()`:

```php
[$action, $value] = FormWriterV2Base::process_secretinput($post, 'ncp_api_key', $has_stored);
// $action: 'keep' | 'clear' | 'set';  $value: the trimmed text when 'set'
```

The page acts on the answer — skip, write empty, write the value — in its
own storage call, which it makes today anyway. Nothing else on the page
changes: no helper text about blanks, no clear control, no script.

## 4. How FormWriter tells keep from remove

A browser does not submit a disabled field. The locked field is a
disabled input, so a form saved without touching it leaves that field out
of the request, and **absent means keep**. Reset enables the input; from
then on it is submitted like any other field.

`process_secretinput()`:

| The request carries | Answer |
|---|---|
| no such field | `keep` |
| the field, empty after trimming | `clear` when `$has_stored`; `keep` when not |
| the field, with text | `set`, with the trimmed text |

It takes the request array and the field name, not the value, because
absent and empty are different answers and a caller that did
`$post[$name] ?? ''` first would erase the difference.

Consequences, accepted:

- A field hidden by a `visibility_rules` rule is still submitted (the
  rules only hide it). Locked, it is disabled and absent: keep. Reset and
  then hidden, it is submitted empty: remove — the person asked for that
  with Reset.
- Any string at all can be stored, asterisks included; nothing is
  reserved.
- A browser password manager does not fill a disabled field. The unlocked
  and nothing-stored fields carry `autocomplete="new-password"`, set by
  FormWriter.
- Without JavaScript a stored credential cannot be changed (the field
  stays locked). The admin interface requires JavaScript already.
- Two tabs open on one form: a tab drawn before a value was stored shows
  an empty, unlocked field, and saving it blank removes the value the
  other tab stored. This is the ordinary last-save-wins every form on the
  platform has; it is not guarded.
- A save refused for another field's error loses what was typed into a
  credential, and the re-rendered field comes back locked. FormWriter does
  not read the request, so it cannot tell "typed and refused" from
  "untouched" on the re-render; saying so on the page would need the page
  to pass it, which this spec rules out. The person re-types it, as for
  every password field today.

## 5. Inside FormWriter

**`FormWriterV2Base::preparePasswordData()`** (every password field funnels
through it; `passwordinput()` cannot be bypassed because `registerField()`
is total) reads `$options['stored']`, which by that act becomes a known
option for `refuseUnknownOption()`. It stops inferring "stored" from the
bound value and drops the "(stored — leave blank to keep)" placeholder.
When `stored` is true the prepared data carries `disabled`, the dots as
`placeholder` (twelve, whatever the length stored; a caller's own
placeholder is ignored), `stored => true`, and `required` moved aside so
the locked field does not fail the browser check. `rows` selects the
textarea form. `value` is `''` in every case.

**`FormWriterV2Base::validate()`** skips a password field registered with
`stored` when the submission does not carry it: a locked field satisfies
`required` by what is stored. A field that was unlocked and submitted is
validated like any other.

**`FormWriterV2Base::process_secretinput()`**, as in §4. Static, so a save
handler with no form instance calls it.

**`FormWriterV2HTML5::renderPasswordInput()`** draws, for a stored field,
the disabled input (or textarea) with `data-stored-secret` and
`data-required` when it was required, then a `type="button"` Reset button
in the same `form-group`. It calls a once-per-request asset emitter, on the
model of `emitEditorAssets()`, that outputs
`<script src="/assets/js/stored-secret.js">`. The script self-initialises
from the data attributes, so no inline script is emitted and the page
stays clean under the Content-Security-Policy. Reset enables and empties
the field, restores `required`, focuses it, and relabels itself Undo;
Undo reverses that. `joinery-validate.js` already skips disabled fields.

**`FormWriterV2JSON`**, which never serialises a password value, emits
`"stored": true` for a stored field. A native client renders its own
locked state and follows the same wire contract: omit the field to keep,
send it empty to remove, send text to replace.

## 6. Outside FormWriter: argument and method only

**`SettingsFieldRenderer::secretField()`** stops forcing `value=''` and its
own placeholder, stops emitting the Clear checkbox, stops drawing textarea
credentials through `textbox()`, and passes `'stored' => $has_stored`
(and `'rows'` for a textarea declaration) to `passwordinput()`. The
`clearable` option is removed; `buildVisibilityRules()` no longer pairs a
Clear box. The 47 declared secrets need no page work.

**`SettingsWriter::write()`** only sees submitted names, so a locked
secret never becomes a candidate and is kept without code. Its blank /
`clear__` block becomes one call to `process_secretinput()` per submitted
declared secret: `clear` writes `''` (reported in `cleared_secrets`),
`keep` skips, `set` falls through to the ordinary changed-value path
(`kept_secrets` is gone: nothing read it, and a locked field never arrives to
be counted). The `clear__` prefix
stays reserved in `Setting::isReservedName()` so an old form cannot mint a
row, and nothing reads it.

**The hand-drawn fields.** From the inventory of 2026-09-20. None of them
binds its stored value today; each already computes whether one exists,
and passes that as `stored`. Each save handler calls
`process_secretinput()` and loses its bespoke clear control and its "leave
blank to keep" helptext. Sealed paths (`ProvisioningSetup::writeSecret()`
callers, `OAuth2ProviderConfig::save()`) act on the answer before sealing;
`DnsInstallCredential` is `managed` and unaffected. A key **id** (B2
keyID, an S3 access key id, a public API key) is not a secret and renders
as an ordinary `textinput()` with its value, which it does not today.

| Page | Fields | `stored` comes from | Retires |
|---|---|---|---|
| `plugins/server_manager/views/admin/provisioning_setup.php` (+ `admin_provisioning_setup_logic.php`) | `ncp_api_key`, `ncp_promotion_code`, `operator_cloud_token`, `hosted_smtp2go_master_key`, `smtp2go_webhook_secret` | `$domains['key_present']`, `['promotion_present']`, `$hosted['token_present']`, `['smtp2go_present']`, `['webhook_present']` | `ncp_promotion_code_clear` |
| `plugins/server_manager/views/admin/targets.php` | `cred_app_key`, `cred_s3_secret_key`, `cred_linode_secret_key`, `node_cred_app_key`, `node_cred_s3_secret_key` | `$is_edit` (the target's blob), `$has_node_creds` | `node_creds_remove`; `keep` still skips the B2 re-authorise. (The node credential itself retires under `services_phase2_platform.md` §3; until then this is its form.) |
| `adm/admin_backups.php` | `secret_key` (and `access_key` shown as text) | `$editing` | the "leave blank" helptext |
| `plugins/server_manager/includes/node_detail_tabs/api_keys.php` | `mgn_api_secret_key` | `$has_api_sec` | the separate `clear_api_credential` form |
| `plugins/mailbox/admin/admin_mailbox_imap_edit.php` | `imap_password` | whether the account holds one | the "leave blank" helptext; Reset + blank removes it (the account then fails its next sync with "no password", the truthful state) |
| `plugins/mailbox/admin/admin_mailbox_connect.php` | `imap_password`, `oauth_*_client_secret` | the account; the setting | `clearable => false` |
| `plugins/mailbox/admin/admin_mailbox_settings.php` | `mailbox_fleet_api_secret_key` | the setting (the renderer gets a boolean, not the literal `'stored'`) | forwarding `clear__` |
| `adm/admin_cloud_storage.php` | `cloud_storage_secret_key` | the setting | `clearable => false`; `keep` re-reads the stored key for the live bucket test as today; `clear` writes `''` and skips the test with "no key to test" |
| `adm/admin_oauth_providers.php` | `oauth_*_client_secret` | the setting | `clearable => false` |

## 7. Tests

`tests/integration/password_field_no_value_test.php` is rewritten to the
new contract and keeps its name:

- **A.** No password field emits a `value`, whatever the caller passes —
  explicit `value`, `set_values()` binding, `set_model()`, `rows`. The
  control `textinput` still round-trips.
- **B.** `stored => true` draws the field `disabled`, with the dots
  placeholder, `data-stored-secret` and a Reset button, without
  `required`, and one `stored-secret.js` tag per page however many fields;
  `stored => false` or absent draws an enabled, empty field with no Reset,
  **even when a non-empty value is bound**. A caller's placeholder does
  not leak onto a stored field. The JSON writer emits `"stored": true` and
  no value.
- **C.** Unchanged: every credential-shaped setting is declared `secret`,
  with the same pattern and the same public-by-design list.
- **D.** `process_secretinput()` table-tested: absent → keep; empty →
  clear or keep by `$has_stored`; whitespace-only → as empty; text →
  set, trimmed; a value of only asterisks → set. `validate()` passes a
  required stored field that is absent and fails it when submitted empty.
- **E.** Against the live logic: `SettingsWriter` with a secret absent →
  kept, empty → removed, typed → replaced, empty with nothing stored →
  nothing written, `clear__smtp_password` → ignored, no row minted; the
  non-secret control can still be blanked. Each §6 save handler driven
  once through `harness_call_logic` with the field absent, empty and a
  value, asserting keep / clear / set on the stored column or sealed
  setting (sealed values through their `readSecret` path, never the row).
- **F.** Browser, once, on the settings page: Reset unlocks and empties the
  field, Undo relocks it, a save with the field locked keeps the value,
  a save after Reset with it blank removes it.
- **G.** A source scan: no file outside `includes/FormWriterV2*.php`
  mentions `data-stored-secret` or `stored-secret.js`, and no view passes a
  "leave blank" placeholder or helptext to a password field.

## 8. Docs

`docs/formwriter.md` gains a "Stored credentials" section: the `stored`
and `rows` options, the three states, `process_secretinput()` and its
three answers, the rule of §2, and the wire contract for native clients.
Its incidental "Currently set — leave blank to keep" example goes.
`docs/settings.md` § Credentials, the `secret` flag line, the render and
write tables, the troubleshooting entries and the checklist point to it,
current state only, no Clear box.

## 9. Out of scope, and one bug found on the way

- Which settings are sealed versus plain is unchanged. **B1, recorded on
  the running list:** the five `oauth_*_client_secret` settings and the six
  `server_manager_*` credentials render on two surfaces with two write
  paths — the General settings tab writes them **plain** through
  `SettingsWriter` over a sealed blob, while their own pages seal. Readers
  tolerate both. That is a separate fix (the plain path should seal for
  any `sealed_secrets` locator) and this spec does not widen it.
- Login, registration, change-password and the vault's passphrase fields
  never pass `stored`, and draw exactly as today.
- Rotating a credential's sealing key, and the `managed` secrets nobody
  renders.

## 10. Work packages

- **WP1** FormWriter: `stored` and `rows`, the locked render and Reset,
  `stored-secret.js` and its emitter, `validate()`, `process_secretinput()`,
  the JSON writer. `SettingsFieldRenderer` and `SettingsWriter` onto them.
  Tests A–E (the `SettingsWriter` half), F. Every declared secret is right
  at the end of WP1 with no page touched.
- **WP2** The §6 pages: pass `stored`, call `process_secretinput()`, drop
  their clear controls and helptext, show the key ids. Test E (the
  handlers), G.
- **WP3** Docs.

## 11. As built (2026-09-22)

Where the build differs from, or adds to, the text above:

- **Cloud storage.** Reset and save blank does not write `''`: the save needs a
  key to prove the bucket, so it fails as "Secret key is required." A bucket
  with no key is not a state that page stores.
- **Mailbox settings.** The view keeps passing its `'stored'` stand-in (any
  non-empty value means stored). The real fix was in the logic: it forwarded
  `$input[...] ?? ''` to `SettingsWriter`, which under this contract would have
  removed the relay secret on every save; it now forwards only when posted.
- **Node API keys.** Clearing the public key and saving removes the secret with
  it (a secret without its public half is useless); that replaces the separate
  Clear form.
- **Core backup target form.** The region and endpoint also show their stored
  values (they were blank on edit, so an S3 edit wiped the region). A changed
  Backblaze key is asked for its own region and endpoint.
- **Stored state from the saved row.** The targets page and the IMAP edit page
  read "is something stored" from a fresh load, so a refused save never draws a
  typed key as saved.
- **`SettingsFieldRenderer`** routes a declared `type: password` through
  `secretField()` too; `DeclaresOAuthConfigFields` drops its "Leave blank to
  keep" help text; the kit stylesheet gains `.jy-stored-secret` spacing.
- **Tests.** `password_field_no_value_test.php` 2.0 covers A–E and G (65
  checks). E drives `SettingsWriter`, `OAuth2ProviderConfig`, the provisioning
  card and the core backup target form. Not automated: the Server Manager
  targets page (its handler is inline in the view and exits), the node API
  keys action and the IMAP edit page — each uses the same
  `process_secretinput()` call. F was run once in the dev browser against a
  FormWriter-rendered form with the served script: locked fields are absent
  from the submission, Reset unlocks and focuses, Undo relocks.
