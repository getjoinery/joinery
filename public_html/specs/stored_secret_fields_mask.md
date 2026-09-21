# Stored credentials show a mask; the mask means keep, blank means remove

**Status:** Draft, 2026-09-20. Owner decision, in the owner's words: show
all asterisks to represent that a value is already entered; submitting the
asterisks unchanged means *do not change*; submitting empty means *delete
the value*; anything else is an update. Not built.

**Depends on:** nothing unbuilt. **Touches:** one method in FormWriter, one
new helper, `SettingsWriter`, the rule test, and the twenty hand-drawn
credential fields listed in §6.

---

## 1. What changes for the person at the keyboard

Today a field for a stored credential renders empty with a grey
"(stored — leave blank to keep)" hint, and removing the value takes a
separate *Clear* checkbox — three different kinds of checkbox, depending on
the page. Nobody reading the form can tell an empty field that means
"nothing stored" from one that means "hidden", except by the hint, and the
owner did not find the hint.

After this spec, a credential field is one of two things:

| The field shows | It means | Submitting it unchanged does |
|---|---|---|
| twelve dots (the mask) | a value is stored | nothing — the value is kept |
| nothing | no value is stored | nothing |

And what the person types decides the write, with no checkbox anywhere:

| Submitted | Written |
|---|---|
| the mask, unchanged | keep the stored value |
| empty | remove the stored value (a no-op when none is stored) |
| anything else | store it, replacing what was there |

Every credential on the platform behaves this way: declared settings on
the settings pages and the wizard, and the hand-drawn credential fields on
the Server Manager, backup, mailbox and OAuth pages. The three Clear
conventions (`clear__<setting>`, `ncp_promotion_code_clear`,
`node_creds_remove`) retire.

## 2. The rule that does not change

**The real value never reaches the page.** A password field emits exactly
one of two values: the mask, or nothing. Never the stored secret, never
what was just submitted (a form re-rendered after a validation error shows
the mask again if something is stored, or nothing). This is rule A of
`tests/integration/password_field_no_value_test.php` today and it stays,
tightened: the only non-empty `value` a password field may carry is the
mask, byte for byte.

## 3. The mask

`FormWriterV2Base::STORED_MASK`, twelve asterisks. Twelve always: the mask
never reveals the length of what is stored. A password input renders it as
twelve dots whatever the browser; a textarea credential (a PEM key, a
service-account JSON) shows the twelve asterisks as its whole content.

Two consequences, accepted:

- A secret that is itself exactly twelve asterisks cannot be stored; the
  writer treats it as *keep*. No real credential looks like that.
- A browser password manager may overwrite the mask with a saved password
  before submit; the writer then sees "anything else" and stores it. Every
  credential field carries `autocomplete="new-password"` (the renderer
  already sets it; FormWriter sets it for every password field that shows
  the mask), which is the one lever a page has against that, and the same
  exposure a typed value has today.

## 4. One helper interprets a submission

`includes/StoredSecret.php`:

```php
final class StoredSecret {
    const KEEP = 'keep'; const CLEAR = 'clear'; const SET = 'set';
    /** @return array{0:string,1:?string}  [KEEP|CLEAR|SET, value when SET] */
    public static function interpret(?string $submitted, bool $has_stored): array;
    /** The value a field should carry: the mask when stored, '' when not. */
    public static function fieldValue(bool $has_stored): string;
}
```

`interpret()`: submitted equals `STORED_MASK` → `KEEP`; submitted is `''`
→ `CLEAR` when something is stored, `KEEP` when nothing is (so a blank
field on a page that stores nothing writes nothing); otherwise `SET` with
the trimmed value. Every write path below calls it and nothing else decides.

## 5. Where it lands

**FormWriter.** `preparePasswordData()` (every password field funnels
through it, and `passwordinput()` cannot be bypassed because
`registerField()` is total) sets `value` to `StoredSecret::fieldValue($has_stored)`
and drops the "(stored — leave blank to keep)" placeholder. The JSON form
writer, which never serialises a password value, emits `"stored": true`
instead of the mask; a native client renders its own mask and submits
`STORED_MASK` to mean keep, the same contract over the wire.

**`SettingsFieldRenderer::secretField()`** stops forcing `value=''` and
stops emitting the Clear checkbox; the `clearable` option is removed and
`refuseUnknownOption()` will name any page still passing it. The
textarea branch carries the mask as its content. `buildVisibilityRules()`
no longer pairs a Clear box.

**`SettingsWriter::write()`** replaces its blank-keeps / `clear__` block
with one call to `interpret()` per declared secret: `KEEP` skips, `CLEAR`
writes `''` (reported in `cleared_secrets`), `SET` falls through to the
ordinary changed-value path. `kept_secrets` is still reported. The
`clear__` prefix stays reserved in `Setting::isReservedName()` so an old
form cannot mint a row, and nothing reads it.

**The sealed write paths** — `ProvisioningSetup::writeSecret()` callers,
`OAuth2ProviderConfig::save()`, `DnsInstallCredential` is `managed` and
unaffected — call `interpret()` before sealing: `KEEP` skips, `CLEAR`
writes an empty sealed value, `SET` seals the new one.

**Column-backed credentials** (backup target credential blobs, the node
credential, a managed node's API secret, an IMAP account password) do the
same in their save handlers: the secret half of the blob is decided by
`interpret()`; a key **id** (B2 keyID, an S3 access key id, a public API
key) is not a secret and renders as an ordinary text field with its value,
which it does not today. Only the secret half is masked.

## 6. The twenty hand-drawn fields, by page

From the inventory of 2026-09-20. Each moves onto `interpret()`, keeps its
field name, and loses its bespoke clear control where it had one.

| Page | Fields | Today | After |
|---|---|---|---|
| `plugins/server_manager/views/admin/provisioning_setup.php` | `ncp_api_key`, `ncp_promotion_code` (+ `ncp_promotion_code_clear`), `operator_cloud_token`, `hosted_smtp2go_master_key`, `smtp2go_webhook_secret` | blank keeps; one bespoke clear box on the promotion code, clear wins over a typed value | mask; the clear box goes; `admin_provisioning_setup_logic.php` calls `interpret()` for each before `writeSecret()` |
| `plugins/server_manager/views/admin/targets.php` | `cred_app_key`, `cred_s3_secret_key`, `cred_linode_secret_key`, `node_cred_app_key`, `node_cred_s3_secret_key` (+ `node_creds_remove`) | blank keeps, reuses the stored blob verbatim and skips the B2 re-authorise; a remove box that wins over a typed value | mask; the remove box goes — a blank node secret removes the node credential; the key ids render with their values. (The node credential itself retires under `services_phase2_platform.md` §3; until then this is its form.) |
| `adm/admin_backups.php` | `secret_key`, `access_key` | blank keeps both; the access key id is a text field that is never prefilled | mask on the secret; the access key id shows its value |
| `plugins/server_manager/includes/node_detail_tabs/api_keys.php` | `mgn_api_secret_key` (+ the separate `clear_api_credential` action) | placeholder says leave blank; blank plus blank public key wipes; a second form removes | mask; blank removes; the separate remove form goes |
| `plugins/mailbox/admin/admin_mailbox_imap_edit.php` | `imap_password` | blank keeps on edit | mask on edit; blank removes the stored password (the account then fails its next sync with "no password", which is the truthful state) |
| `plugins/mailbox/admin/admin_mailbox_settings.php` | `mailbox_fleet_api_secret_key` | feeds the renderer the literal `'stored'` as the value, forwards `clear__` | passes the boolean the renderer needs; nothing to forward |
| `adm/admin_cloud_storage.php` | `cloud_storage_secret_key` | `clearable => false`; blank re-reads the stored key so the live bucket test runs | mask; `KEEP` re-reads the stored key for the test as today; `CLEAR` writes `''` and skips the test with the sentence "no key to test" |
| `adm/admin_oauth_providers.php`, `plugins/mailbox/admin/admin_mailbox_connect.php` | `oauth_*_client_secret` | `clearable => false`; blank keeps in `OAuth2ProviderConfig` | mask; `interpret()` in `OAuth2ProviderConfig::save()`; blank removes |

The 47 declared secrets need no page work: the renderer and the writer
carry them.

## 7. Tests

`tests/integration/password_field_no_value_test.php` is rewritten to the
new contract and keeps its name:

- **A.** No password field emits a value other than the mask, whatever the
  caller passes — explicit `value`, `set_values()` binding, a textarea
  credential. The control `textinput` still round-trips.
- **B.** The mask is present exactly when something is stored, absent
  when not; no placeholder text either way.
- **C.** Unchanged: every credential-shaped setting is declared `secret`,
  with the same pattern and the same public-by-design list.
- **D.** Flipped, against the live logic: mask → kept; blank → removed;
  typed → replaced; the mask submitted for a setting with nothing stored →
  nothing written; `clear__smtp_password` submitted → ignored and no row
  minted. The non-secret control can still be blanked.
- **E.** New: `StoredSecret::interpret()` table-tested; and each of the
  §6 save handlers driven once through `harness_call_logic` with the mask,
  blank and a value, asserting keep / clear / set on the stored column or
  sealed setting. Sealed values are asserted through their `readSecret`
  path, never by reading the row.

## 8. Docs

`docs/settings.md` § Credentials, the `secret` flag line, the render and
write tables, the troubleshooting entries and the checklist: the mask
contract, current state only, no Clear box. `docs/formwriter.md` gains a
"Stored credentials" section stating the one rule of §2 and the mask, and
its incidental "Currently set — leave blank to keep" example goes.

## 9. Out of scope, and one bug found on the way

- Which settings are sealed versus plain is unchanged. **B1, recorded on
  the running list:** the five `oauth_*_client_secret` settings and the six
  `server_manager_*` credentials render on two surfaces with two write
  paths — the General settings tab writes them **plain** through
  `SettingsWriter` over a sealed blob, while their own pages seal. Readers
  tolerate both. That is a separate fix (the plain path should seal for
  any `sealed_secrets` locator) and this spec does not widen it.
- Rotating a credential's sealing key, and the `managed` secrets nobody
  renders.

## 10. Work packages

- **WP1** `StoredSecret`, the FormWriter mask, `SettingsFieldRenderer`
  without the Clear box, `SettingsWriter` on `interpret()`, the rule test
  rewritten (A–D). No page changes; every declared secret is already right.
- **WP2** The §6 pages onto `interpret()`, their clear controls removed,
  the key ids shown; test E.
- **WP3** Docs.
