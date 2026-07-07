# Passkeys Core — Executor Package

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/passkeys_core.md` (v1.2) — the *why*. This document is
the *how*: exact files, schema, signatures, endpoints, and acceptance checks, at a
level a non-judgment executor can follow. Where the two disagree, the design spec
governs intent; fix this file.
**Consumed by (do NOT build here):** `specs/mailbox_encryption_at_rest.md`. The
mail secret-key wrapping, recovery codes, optional passphrase, the APCu unlock window,
and the `vault-kek` unlock endpoints all live in the encryption package. This package
stops at: the credential store, the four ceremonies (register / authenticate / step-up
/ PRF-derive), the JS helper, and the revocation-veto hook. The mail package *calls*
`PasskeyService` and *subscribes* to the veto hook.

## Scope boundary (read first)

Build in this package:
1. `data/passkeys_class.php` — the credential store (`Passkey` + `MultiPasskey`).
2. `includes/PasskeyService.php` — the single owner of all WebAuthn ceremonies,
   wrapping `web-auth/webauthn-lib`.
3. Logic/API endpoints: passkey **sign-in** (sessionless), **enrollment** (sessioned +
   step-up), **step-up**, **rename**, **revoke**. PRF-derive is a *service method +
   JS-helper capability*, not an endpoint here — the mail package owns its endpoint.
4. `assets/js/passkeys.js` — the browser helper (create/get, base64url, PRF wiring,
   capability probe).
5. Login-page and profile-security UI (FormWriter + the JS helper).
6. Settings, secret provisioning, docs.

Do NOT build here: anything that unwraps or holds the mail secret key; recovery codes;
the passphrase unlocker; APCu; the unlocker floor's *policy* (this package only fires
the veto hook — the mail consumer decides whether to veto).

## Reference files to mirror (open these before writing)

- **Data class:** `docs/example_class.php` (annotated template) and
  `data/coupon_codes_class.php` (concrete). Copy the header requires, the three table
  statics, `$field_specifications` syntax, `$permanent_delete_actions`, the API-exposure
  block, and the `MultiX`/`getMultiResults()` shape from these.
- **A sibling security logic file with an `_logic_api()` opt-in:**
  `logic/security_logic.php` (2FA settings — session-based, same shape as our
  endpoints). Copy the `LogicResult` return conventions and the `_logic_api()` block
  from here rather than inventing method names. Read `docs/logic_architecture.md` for
  the `LogicResult` surface.
- **Crypto-helper style:** `includes/SecretBox.php` — instance class, key pulled from a
  `Globalvars` setting in the constructor, `throw new RuntimeException` on misconfig
  (fail closed), `function_exists()`-guarded sodium, versioned self-describing string
  output, base64url helpers. `PasskeyService` is not itself a crypto helper (the library
  does the crypto), but any small helper it needs follows this shape.
- **API auth path:** `includes/ApiAuth.php` (`authenticate` / `authorize`, the
  `x_joinery_csrf` browser-session check) and `api/apiv1.php` (`api_resolve_logic_path`).
  You do not edit these — you conform to them.

## Phase 0 — Preflight

0.1 Branch: `git checkout -b passkeys-core`.

0.2 Add the library. Edit `public_html/composer.json`, add to `require`:
```json
"web-auth/webauthn-lib": "^5.0"
```
Run `composer install` from `public_html/` (vendor resolves to the site-root
`../vendor/` per the `vendor-dir` config). Confirm `../vendor/web-auth/webauthn-lib`
exists. This is the **pure-PHP** library (not the Symfony bundle) — chosen for
first-class PRF support (`PseudoRandomFunctionInputExtensionBuilder`); reference its
PRF docs at `webauthn-doc.spomky-labs.com` for the ceremony internals, and **pin the
exact installed minor** in the doc you write in Phase 8. `lbuchs/WebAuthn` was rejected:
no PRF support.

0.3 ext-sodium: confirmed present (SecretBox depends on it). Passkeys needs no sodium
directly (the library owns crypto); the mail package will introduce
`sodium_crypto_box_seal` etc. Nothing to do here beyond noting the dependency.

0.4 Provision no new secret in this package. (RP ID and origin come from existing site
config — see 2.2. The mail KEK secret is the encryption package's concern.)

0.5 Add the feature flag. Edit `public_html/settings.json`, append to the `settings`
array (values are strings):
```json
{ "name": "passkeys_enabled", "default": "0", "helptext": "Master switch for passkey sign-in, enrollment, and PRF-based secret derivation. Off until the feature is verified on the deployment." }
```
Read it at runtime with `Globalvars::get_instance()->get_setting('passkeys_enabled')`
(truthy `"1"`/`"0"`). Every endpoint and UI entry in this package gates on it and
fails closed (404/hidden) when off. It ships default `"0"`; flip to `"1"` per
deployment after Phase 9 passes.

## Phase 1 — The credential store

Create `data/passkeys_class.php`, `chmod 666`. Class `Passkey extends SystemBase`,
`class PasskeyException extends SystemBaseException {}`, and `MultiPasskey extends
SystemMultiBase`. Header requires copied verbatim from `coupon_codes_class.php`.

Table statics:
```php
public static $prefix      = 'pkc';
public static $tablename   = 'pkc_passkey_credentials';
public static $pkey_column = 'pkc_passkey_credential_id';
```

`$field_specifications` (schema is auto-synced from this — no migration):
```php
public static $field_specifications = array(
    'pkc_passkey_credential_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
    'pkc_usr_user_id'           => array('type'=>'int8', 'is_nullable'=>false, 'index'=>true,
                                          'foreign_key'=>array('table'=>'usr_users', 'column'=>'usr_user_id', 'on_delete'=>'CASCADE')),
    // Raw credential id, base64url text — the lookup key on every assertion.
    'pkc_credential_id'         => array('type'=>'text', 'is_nullable'=>false),
    // Authoritative: the library's serialized PublicKeyCredentialSource (JSON). All
    // verification round-trips through this; the columns below are denormalized-on-write
    // conveniences for lookup/UI. Not exported over the API (see floors).
    'pkc_source_json'           => array('type'=>'text', 'is_nullable'=>false),
    'pkc_sign_count'            => array('type'=>'int8', 'is_nullable'=>false, 'default'=>0),
    'pkc_transports'            => array('type'=>'text', 'is_nullable'=>true),   // JSON array
    'pkc_aaguid'                => array('type'=>'varchar(64)', 'is_nullable'=>true),
    'pkc_prf_capable'           => array('type'=>'bool', 'is_nullable'=>false, 'default'=>false),
    'pkc_label'                 => array('type'=>'varchar(255)', 'is_nullable'=>true),
    'pkc_created_time'          => array('type'=>'timestamp(6)', 'default'=>'now()'),
    'pkc_last_used_time'        => array('type'=>'timestamp(6)', 'is_nullable'=>true),
    'pkc_delete_time'           => array('type'=>'timestamp(6)', 'is_nullable'=>true),
);
```

Partial-unique on the live credential id (a revoked row keeps its id; a re-enroll of a
fresh credential must still be free to insert):
```php
public static $index_specifications = array(
    array('columns'=>array('pkc_credential_id'), 'unique'=>true, 'where'=>'pkc_delete_time IS NULL'),
);
```
Verify the exact `$index_specifications` entry keys against `example_class.php`'s
partial-unique example before finalizing.

Soft delete: the `pkc_delete_time` column enables `->soft_delete()`. Declare (required,
even if empty):
```php
public static $permanent_delete_actions = array();
```

API exposure — read is owner-scoped and free; writes go through actions (revoke has a
veto hook), so the model is **not** CRUD-writable:
```php
public static $api_readable = true;    // GET /api/v1/Passkeys — owner-or-staff by default (do NOT set $api_public_read)
public static $api_writable = false;   // rename/revoke are actions, not raw CRUD
public static $api_unreadable_fields = array('pkc_source_json'); // internal library state — never ship it
public static $api_unwritable_fields = array();
public static $api_derived_fields    = array();
```
Ownership: the conventional `pkc_usr_user_id` column makes the `SystemBase` default
(owner-or-staff) correct with no override. Do not override `authenticate_read`.

AI surface: leave `$ai_readable` unset (default off) — credential rows are not AI
content.

`MultiPasskey`:
```php
protected static $model_class = 'Passkey';
protected function getMultiResults($only_count=false, $debug=false) {
    $filters = [];
    if (isset($this->options['user_id']))
        $filters['pkc_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
    if (isset($this->options['credential_id']))
        $filters['pkc_credential_id'] = [$this->options['credential_id'], PDO::PARAM_STR];
    if (isset($this->options['prf_capable']))
        $filters['pkc_prf_capable'] = "= " . ($this->options['prf_capable'] ? 'TRUE' : 'FALSE');
    // Default: live rows only, unless caller asks for deleted.
    $filters['pkc_delete_time'] = (isset($this->options['deleted']) && $this->options['deleted']) ? "IS NOT NULL" : "IS NULL";
    return $this->_get_resultsv2('pkc_passkey_credentials', $filters, $this->order_by, $only_count, $debug);
}
```

Run `update_database` (admin utilities) or rely on the deploy sync to create the table.
`php -l` and `validate_php_file.php` the file.

## Phase 2 — PasskeyService

Create `includes/PasskeyService.php`, `chmod 666`. One class, `PasskeyService`, no
namespace, loaded via `require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));`.
Load the composer autoloader at the top of the constructor (or a private `boot()`)
before referencing any `Webauthn\` class:
```php
require_once(PathHelper::getComposerAutoloadPath());
```

### 2.1 Responsibilities (the public surface consumers call)

```php
class PasskeyService {
    public function __construct();

    // --- Enrollment (add a credential to a user) ---
    // Returns creation-options as a JSON-ready array for the JS helper; stashes the
    // challenge + purpose in the web session (single-use, short TTL).
    public function getRegistrationOptions(User $user, bool $prf_capable_requested=false): array;
    // Verifies the attestation response, persists a Passkey row, returns it.
    public function verifyRegistration(string $client_response_json, string $label): Passkey;

    // --- Authentication (sign-in) ---
    // $email null => usernameless (empty allowCredentials); else scope to that user's creds.
    public function getAuthenticationOptions(?string $email=null): array;
    // Verifies the assertion; returns the matched User; updates sign count + last-used.
    public function verifyAuthentication(string $client_response_json): User;

    // --- Step-up (re-confirm before a sensitive action) ---
    public function getStepUpOptions(User $user): array;
    // Verifies, then marks the session step-up-verified (see 2.4).
    public function verifyStepUp(string $client_response_json, User $user): void;
    public function hasRecentStepUp(int $max_age_seconds=300): bool;

    // --- PRF secret derivation (consumed by the mail package) ---
    // Attaches the PRF extension with the per-context salt; $context is allowlisted.
    public function getDerivationOptions(User $user, string $context): array;
    // Verifies the assertion AND returns the browser-supplied PRF output (raw 32 bytes).
    // The output rides in the client response's extension results; this method validates
    // the assertion (freshness/possession) and hands back the bytes for the consumer to
    // use as a KEK. Returns [User $user, Passkey $credential, string $prf_output_32].
    public function verifyDerivation(string $client_response_json, string $context): array;

    // --- Consumer helpers ---
    public function listCredentials(User $user): MultiPasskey;   // live rows
    public function revoke(int $credential_id, User $actor): void; // fires the veto hook (2.5)
    public function rename(int $credential_id, User $actor, string $label): void;
}
```

### 2.2 RP entity / origin

Relying-Party ID = the site's registrable domain from existing site config (the same
value pages already know as the host; pull it the way other core code reads the site
domain — do not add a setting). Origin and rpId hash are validated on **every** ceremony
by the library's response validators; reject cross-origin. Attestation conveyance =
`none` (do not require attestation). RP name = the site name from config.

### 2.3 Challenge lifecycle

Stash the pending challenge in a dedicated `pks_passkey_ceremonies` table keyed by the
browser-session id (`session_id()`; the anonymous session exists pre-login, which is
what lets sign-in carry a challenge across the two sessionless calls). **Not in
`$_SESSION`:** browser-session API requests are read-only on the web session —
`ApiAuth::authenticateBrowserSession()` calls `session_write_close()` before dispatch,
so `$_SESSION` writes made inside a sessioned action are silently discarded. Store: the
challenge, a purpose tag (`register` / `authenticate` / `stepup` / `derive:{context}`),
and an expiry (now + 120s). One in-flight ceremony per session (a new stash replaces any
pending challenge); sweep expired rows on stash. On verify: load, **delete it before
validating** (single-use; a replay finds no challenge), then check purpose + expiry.

### 2.4 Session establishment & step-up flag

- `verifyAuthentication` success must establish the login **exactly as a password login
  does** — call the same `SessionControl` path the password flow uses (find it in the
  password-login logic). Do not hand-roll session creation.
- `verifyStepUp` writes a step-up marker row to `pks_passkey_ceremonies` (kind
  `stepup`, keyed by the browser-session id — same reason as 2.3: sessioned API
  actions cannot write `$_SESSION`). `hasRecentStepUp($n)` returns true iff a marker
  row for this session was created within `$n` seconds.

### 2.5 Revocation veto hook (the only unlocker-floor touchpoint here)

Before soft-deleting a credential, `revoke()` dispatches a **synchronous pre-revoke
signal** carrying the user id and the credential id. Any subscriber may veto by throwing
`PasskeyRevocationVetoException($reason)`. If thrown, `revoke()` does **not** delete and
re-throws so the caller surfaces `$reason` to the user. If no veto, soft-delete the row.
Wire this to the platform signal bus (the `signal_bus_debug` setting confirms one
exists — read its dispatch API and use a synchronous, vetoable dispatch; if the bus
cannot veto synchronously, fall back to a small registry of veto callbacks
`PasskeyService::onPreRevoke(callable)` consulted in `revoke()`). Define
`PasskeyRevocationVetoException` in this file. The mail consumer subscribes and vetoes
when deleting the wrapping would breach its unlocker floor (encryption package).

### 2.6 Persistence mapping

On `verifyRegistration`: from the validated `PublicKeyCredentialSource`, write
`pkc_credential_id` (base64url of the raw credential id), `pkc_source_json` (the
library's serialization of the source — authoritative), `pkc_sign_count`,
`pkc_transports` (JSON), `pkc_aaguid`, `pkc_prf_capable` (from whether PRF was requested
*and* the authenticator reported the extension available), `pkc_label`,
`pkc_usr_user_id`. On every successful assertion: reload the source from
`pkc_source_json`, let the library update its counter, re-serialize back to
`pkc_source_json`, and denormalize `pkc_sign_count` + `pkc_last_used_time`.

Sign-count regression: enforce where provided, but **flag, don't hard-fail** (synced
passkeys legitimately report 0) — log a warning on regression, do not reject the
assertion solely for a zero/last-equal counter. This matches the design spec's Security
Notes.

## Phase 3 — API endpoints (logic files)

Each is `logic/{name}_logic.php`, `chmod 666`, defining `function {name}_logic(array
$input): LogicResult` plus the `{name}_logic_api()` opt-in. Copy `LogicResult`
conventions from `security_logic.php`. Every endpoint first checks
`get_setting('passkeys_enabled')` and returns an action error (or 404-equivalent) when
off. Segment names must match `^[a-z0-9_]+$` (dispatcher guard) — all names below do.

| File (`logic/…_logic.php`) | Endpoint | `requires_session` | Notes |
|---|---|---|---|
| `passkey_login_options`  | `POST /api/v1/action/passkey_login_options`  | **false** | Body `{email?}`. Returns request options; stashes challenge in anon session. Sessionless: no CSRF, rate-limited, challenge-bound. |
| `passkey_login_verify`   | `POST /api/v1/action/passkey_login_verify`   | **false** | Body = assertion client JSON. On success establishes the session and returns the user summary (mirror `auth/login`'s `data`). |
| `passkey_register_options` | `POST /api/v1/action/passkey_register_options` | true | Enrollment always demands proof beyond the session cookie — a session thief must not enroll quietly. With ≥1 credential: requires a **fresh step-up** (`hasRecentStepUp()`), else refuse. First passkey (nothing to step up with): requires the account password (`current_password` in the body, `User::check_password()`); accounts with no password (OAuth-only) fall back to the session anchor. Returns creation options with `excludeCredentials` = the user's live credentials. The UI always sends `prf_capable_requested` (PRF is enabled only at creation time; vault consumers need it). |
| `passkey_register_verify` | `POST /api/v1/action/passkey_register_verify` | true | Body = attestation client JSON + `label`. Persists; returns the new credential row (through `export_for_api()`). |
| `passkey_stepup_options` | `POST /api/v1/action/passkey_stepup_options` | true | Assertion options scoped to the current user. |
| `passkey_stepup_verify`  | `POST /api/v1/action/passkey_stepup_verify`  | true | Sets the step-up marker. |
| `passkey_rename`         | `POST /api/v1/action/passkey_rename`         | true | Body `{credential_id, label}`; owner-scoped. |
| `passkey_revoke`         | `POST /api/v1/action/passkey_revoke`         | true | Body `{credential_id}`; fires the veto hook; surfaces the veto reason as an action error. |

Reads (the credential list) use the free CRUD read surface: `GET /api/v1/Passkeys`
(owner-scoped by the model default). No logic file needed for listing.

`_logic_api()` block shape (from `security_logic.php`), e.g. for a sessioned action:
```php
function passkey_register_options_logic_api() {
    return [
        'requires_session' => true,
        'description' => 'Begin passkey enrollment (returns WebAuthn creation options)',
    ];
}
```
For the two sign-in actions use `'requires_session' => false`.

**PRF-derive has no endpoint here.** The mail package defines
`logic/{its_unlock}_logic.php`, calls `PasskeyService::getDerivationOptions()` /
`verifyDerivation()`, and does the KEK unwrap. This package only ships the service
methods (2.1) and the JS `deriveSecret` capability (Phase 4).

## Phase 4 — JS helper

Create `assets/js/passkeys.js`, `chmod 666`. Vanilla JS, no framework. Standalone file
(there is no enqueue registry) — the views that need it echo a `defer` script tag with
`?v=<mtime>` cache-busting (Phase 5). Public functions on a small namespace
(e.g. `window.JoineryPasskeys`):

- `isSupported()` → `!!window.PublicKeyCredential`.
- `isPrfLikely()` → best-effort async probe of platform-authenticator + conditional-UI
  availability; never load-bearing (no feature *requires* PRF).
- `register(optionsJson)` → base64url-decode the challenge/user.id/excludeCredentials
  ids, call `navigator.credentials.create()`, return the attestation response
  re-encoded (ids base64url) as JSON for `passkey_register_verify`.
- `authenticate(optionsJson)` → decode, `navigator.credentials.get()`, return the
  assertion response JSON for the verify endpoints.
- `derive(optionsJson)` → like `authenticate` but reads `getClientExtensionResults().prf`
  and returns `{ response, prfOutput }` (prfOutput base64url). Consumed by the mail
  package's unlock JS.
- base64url encode/decode helpers (ArrayBuffer ⇄ string).

The CSRF header for sessioned calls: read `document.querySelector('meta[name="joinery-api-csrf"]').content`
and send it as `X-Joinery-Csrf` (per `docs/api.md`). Sign-in calls are sessionless — no
CSRF header.

## Phase 5 — UI

Vanilla HTML5 + FormWriter (never hand-rolled forms). Load `passkeys.js` from the views
below with `asset_mtime()`-style cache-busting, mirroring how
`theme/getjoinery/includes/PublicPage.php` loads `joinery-validate.js`.

5.1 **Login page.** Add a "Sign in with a passkey" button (shown only when
`passkeys_enabled` and `isSupported()`). Flow: `passkey_login_options` →
`authenticate()` → `passkey_login_verify` → on success redirect as password login does.
Password login stays; passkeys are additive.

5.2 **Profile security section** (near the existing 2FA section — see where
`security_logic.php` renders). A credentials list (from `GET /api/v1/Passkeys`) with:
**Add a passkey** (triggers step-up if none recent → `passkey_register_options` →
`register()` → `passkey_register_verify`), **Rename**, **Revoke** (surfaces the veto
reason verbatim when the mail floor refuses). Each row shows label, created, last-used.
Empty helptext, guided controls — no explainer prose (self-documenting-pages rule).

## Phase 6 — Settings, secret, docs

6.1 Settings: `passkeys_enabled` added in Phase 0. No RP-ID/origin settings (from site
config).

6.2 Docs (current-state voice only — no "previously/now/replaces"):
- New `docs/passkeys.md`: the service, the four ceremonies, the consumer contract
  (contexts, per-credential PRF outputs, the revocation veto hook), the JS helper, the
  capability-probe fallback rules, and the **pinned `web-auth/webauthn-lib` minor**.
- `docs/api.md`: add the eight action rows + the `Passkey` model to the surfaces.
- `plugins/mailbox/docs/overview.md` (or its renamed successor if the rename spec
  ran first — coordinate): the mail-unlock section references this core service rather
  than describing WebAuthn.

## Phase 7 — Verification (acceptance gate)

7.1 `php -l` every new/edited PHP file; run
`maintenance_scripts/dev_tools/validate_php_file.php` on each and resolve every flag.

7.2 `update_database` created `pkc_passkey_credentials` with the partial-unique index;
confirm via `\d pkc_passkey_credentials`.

7.3 Browser round-trip on `dev.getjoinery.com` (feature flag on for a test user):
- **Enroll:** profile → Add a passkey → step-up → create → row appears.
- **Sign in:** log out → passkey sign-in → lands logged in as that user.
- **PRF derive:** using a throwaway consumer call (or the mail package once built),
  `getDerivationOptions('vault-kek')` → `derive()` → `verifyDerivation()` returns a stable
  32-byte output for the same credential across two runs, and a *different* output for a
  second enrolled credential (per-credential outputs — the design spec's requirement).
- **Revoke veto:** with a mail consumer subscribed and at the floor, revoke is refused
  with the reason surfaced; below the floor, revoke succeeds and the row soft-deletes.
- **Sign-count / replay:** a replayed assertion (reused challenge) is rejected
  (challenge already consumed).

7.4 Provide `batcat` commands for each created file (do not run them).

## Open items the executor confirms against the running system (not decisions)

- The exact `SessionControl` raw setter name for stashing the challenge, and the exact
  password-login session-establishment call to reuse in `verifyAuthentication`.
- The site-domain accessor to use for the RP ID (existing config, not a new setting).
- The signal-bus dispatch API and whether it supports synchronous veto; else use the
  `onPreRevoke` callback registry fallback (2.5).
- The installed `web-auth/webauthn-lib` minor and its exact serializer/validator class
  names (v5 line) — follow the library's own docs; pin the minor in `docs/passkeys.md`.
- PRF availability across the deployment's actual browser/device set (design spec's
  remaining open item) — a support-matrix check, not a code decision.
