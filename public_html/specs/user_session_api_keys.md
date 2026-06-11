# User Session API Keys (Core Platform)

Per-user authentication for the REST API: a user logs in with email + password
and receives an API key pair their device stores and presents on every
request — the same `public_key`/`secret_key` headers and the same `apk_api_keys`
table the existing machine keys use, distinguished by a type column. This is
the prerequisite for the mobile apps (`specs/scrolldaddy_ios_app.md`,
`specs/scrolldaddy_android_app.md`) and is app-agnostic: any future client on
any Joinery deployment uses the same surface.

Today API keys are admin-provisioned only, which a shipped client cannot use
(no self-provisioning, and an embedded key is extractable and would act as one
fixed user). Everything downstream of authentication already works for a user
identity — auth resolves to `$api_user` and the CRUD/action pipeline, session
simulation, object-level authorization, rate limiting, and request logging all
hang off it. This spec adds a way for a user to *mint their own key* by
proving their identity; the request path is unchanged.

## Design decisions

| Decision | Choice | Why |
|---|---|---|
| Storage | Same `apk_api_keys` table, new `apk_type` column (`machine` / `session`) | One table, one model, one auth path, one documentation story. The codebase has a single maintainer; type-discipline is enforced by centralizing type logic in the model and pinning the security boundary with tests, not by table separation |
| Credential format | Standard key pair: random public key (lookup handle) + random secret (credential), both `random_bytes()`-derived | Reuses the existing auth flow in `apiv1.php` byte-for-byte — phones send the same two headers machine integrations send. No new auth branch |
| Secret hashing | Session keys: SHA-256 (fast, deterministic). Machine keys: unchanged (slow phpass hash) | Slow hashes are for low-entropy human secrets; session secrets are 256-bit random, so SHA-256 is safe and removes per-request KDF cost for high-volume phone traffic. The branch lives in exactly one place: `ApiKey::check_secret_key()` |
| Lifecycle | One long-lived revocable pair per login; **no refresh-token machinery** | Native apps hold credentials in Keychain/Keystore; rotation protocols buy nothing there. Revocation is the control |
| Expiry | `api_session_key_lifetime_days` setting, factory default 365 (declared in `settings.json`), written to the existing `apk_expires_time` | Existing expiry enforcement in `apiv1.php` applies unchanged |
| Permission | `apk_permission = 4` set at creation; object-level authorization is the effective gate, unchanged | The session key *is* the user; the existing permission plumbing just works |
| Revocation model | Existing `apk_delete_time` soft delete | Already enforced by the auth path; matches platform conventions |
| Management API | **Excluded**: `ManagementApiRouter` additionally requires `apk_type = machine` | A superadmin logging into a phone app must not mint a control-plane credential. This boundary is pinned by a dedicated test (see Tests) — treat that test as load-bearing |

## Schema changes (existing class, auto-synced)

In `data/api_keys_class.php` `$field_specifications`:

- `apk_type` — `varchar(16)`, not null, default `'machine'`. Model constants
  `ApiKey::TYPE_MACHINE` / `ApiKey::TYPE_SESSION`; no string literals outside
  the class.
- `apk_last_used_time` — `timestamp(6)`, nullable. Updated by the auth path at
  most once per hour (avoid a write per request). Maintained for both types —
  it answers "is this machine key still used?" too.
- `apk_secret_key` — widen to `varchar(64)` (SHA-256 hex; existing 34-char
  phpass hashes fit unchanged).

`apk_name` doubles as the session's device label (e.g. "Jeremy's iPhone").
`apk_ip_restriction` and `apk_start_time` stay null on session keys — phones
roam networks by design; the existing enforcement code is simply inert on
null. `MultiApiKey::getMultiResults()` gains a `type` filter key.

## Model changes (`ApiKey`)

- `CreateSessionKey($user_id, $device_label)` — factory minting a session row:
  random public key, random secret (returned in plaintext exactly once),
  SHA-256 secret hash, `apk_type = session`, `apk_permission = 4`,
  `apk_expires_time` from the lifetime setting.
- `check_secret_key()` — branches on type: session → `hash_equals()` against
  SHA-256; machine → existing slow-hash verify. The only type-conditional in
  the credential path.
- `is_session()` convenience accessor for the consumers below.

## Endpoints

All under `/api/v1/auth/*`, dispatched **before** the key-header requirement —
same interception pattern as the management router (`api/apiv1.php:274`) but
ahead of authentication, since `login` is the unauthenticated entry point.
HTTPS enforcement and both rate limiters (`apiv1.php:111-132`) run first,
unchanged.

### `POST /api/v1/auth/login` — unauthenticated

Body: `email`, `password`, `device_label` (optional). Verifies via the
existing `User::check_password()`. Failures log through `RequestLogger` with
feature `api_auth`, counting toward the existing failed-auth limit (10 per
15 min per IP). Deleted users rejected identically to the existing auth path.

Success response data: `public_key`, `secret_key` (the only time the secret
plaintext is ever returned), `expires_time`, and the same user/tier summary
`auth/session` returns. The client thereafter authenticates with the standard
key headers — no special client-side auth code beyond storing two strings.

### `GET /api/v1/auth/session` — key-authenticated (either type)

Returns the user summary: user id, name, email, permission, subscription tier,
tier feature flags. The app's "who am I / what may I do" call on launch.

### `POST /api/v1/auth/logout` — session-key-authenticated

Revokes the presented key (`apk_delete_time`). Machine keys get a 403 here —
they are revoked from the admin page, not by themselves.

### Unauthenticated sessionless actions

A first-launch phone has no credentials yet, but must call `register` and
`password_reset_1`/`password_reset_2` — today every action endpoint requires
key headers. Fix at the dispatch layer: actions whose `_api()` companion
declares `requires_session => false` are dispatched **without** key headers,
riding the same pre-auth dispatch as `auth/*`. HTTPS enforcement and both
rate limiters apply unchanged, and failed attempts log through
`RequestLogger` like `auth/login` failures. Actions requiring a session are
untouched. (The matching rule for *fetching* those actions' form definitions
lives in `specs/formwriter_json_forms.md`.)

### Client version handshake

Thin clients mean the server moves while shipped binaries linger — so the
ability to retire an old binary must exist in the first release; a binary
shipped without it can never be told to upgrade.

- Apps send two headers on every request: `client_app` (e.g.
  `scrolldaddy-ios`) and `client_version` (semver). Requests without them
  (machine integrations, curl) are wholly unaffected.
- Setting `api_min_client_versions` (declared in `settings.json`, factory
  default `{}`): a JSON map of `client_app` → minimum version, edited from
  the admin settings page.
- Enforcement in the `apiv1.php` dispatch, after HTTPS and rate limiting,
  before everything else: if the request names a `client_app` that has a
  minimum and `version_compare()` puts `client_version` below it, respond
  **HTTP 426** with errortype `UpgradeRequired` and a message. The account
  module renders any 426 as a blocking upgrade screen with a store deep
  link.

## Request path

None. Session keys authenticate through the existing flow in `apiv1.php`
untouched — lookup by `apk_public_key`, active/expiry checks, user-state
checks, secret verification (now type-aware inside the model), `$api_user`,
and everything downstream. Request logging gains the key type so audit
queries can separate machine from session traffic.

## Consumers that become type-aware

Inventoried up front — these four places, and no others, branch on type:

1. **`ApiKey::check_secret_key()`** — hash scheme (above).
2. **`ManagementApiRouter`** — requires `TYPE_MACHINE` in addition to the
   existing superadmin gate. Fails closed via the pinned test.
3. **Revoke-on-password-change** — when `usr_password` changes on an existing
   user, all active **session** keys for that user are soft-deleted; machine
   keys survive (an admin changing their password must not break the Server
   Manager integration). Implemented in `User::save()` — not `prepare()`
   (not guaranteed to run), and not in the four separate password-changing
   logic files (the model is the single choke point).
4. **Admin → API Keys page** — defaults to machine keys; a type filter shows
   session keys (read + revoke). Admins get fleet-wide session visibility on
   the page they already know.

## Profile: active sessions view

A minimal "App sessions" section (FormWriter single-button action forms) on
the profile/security page: the user's active session keys (device label,
created, last used) with per-row Revoke and "Revoke all". This is the
lost-phone path and ships with the feature. Lists session keys only; web
login sessions are out of scope.

## Security notes

- Secret plaintext is never logged (request logging already excludes bodies
  and secrets) and never re-displayed after login.
- `auth/login` is the one place passwords transit the API; it rides existing
  HTTPS enforcement and failed-auth rate limiting.
- Session keys are not IP-restricted — the sessions view's device label +
  last-used time is the compensating visibility.
- Password reset (`password_reset_1`/`2`) stays unauthenticated as today;
  completing a reset changes `usr_password`, which revokes all session keys
  via the save hook.

## Tests

`/tests/` additions:

- **Management boundary (load-bearing):** a session key owned by a
  permission-10 user gets 403 on every `management/*` endpoint; a machine key
  owned by the same user still works. This test is the enforcement mechanism
  for the single-table design — never delete it.
- Model: `CreateSessionKey` row shape; SHA-256 verify path; machine-key
  verify path unchanged; expiry honored.
- Login happy path returns a working pair; wrong password counts toward the
  auth rate limit and locks out at the threshold.
- Session-key requests reach CRUD and action endpoints as the right user;
  object ownership enforced across users.
- Expired and revoked keys get 401; `logout` revokes only the presented key
  and refuses machine keys; password change revokes all session keys and no
  machine keys.
- Sessioned action smoke test: `account_edit` via session key persists, with
  session simulation cleaned up.
- Version handshake: a request whose `client_version` is below its app's
  configured minimum gets 426 on every endpoint including `auth/login`; a
  request with no client headers is unaffected by the setting.

## Acceptance checklist

1. `auth/login` → key pair → `auth/session` round-trip works with no
   pre-provisioned credentials.
2. An existing action (`account_edit`) and a CRUD read
   (`GET /api/v1/User/{own id}`) both work via a session key, acting as the
   logged-in user.
3. Password change from the web UI immediately invalidates a phone's key;
   machine keys owned by the same user are untouched.
4. Revoking from the profile sessions view kills exactly the chosen device;
   the admin API Keys page can find and revoke the same row.
5. Ten bad logins from one IP lock the IP out per the existing rate limit.
6. A superadmin's session key cannot reach the management API (the pinned
   test passes).
7. Full validator pass (`php -l` + `validate_php_file.php`) on all touched
   files.

## Documentation deliverables (on implementation)

All changes land in `docs/api.md` — it is the only doc that covers API
authentication, and this feature deliberately adds no new subsystem:

- **Authentication section** — describe the two key types and their
  provisioning paths (admin page vs. `auth/login`), the secret-hashing
  difference, expiry, and lifecycle. One auth story, two provisioning paths.
- **Endpoint reference** — document `auth/login`, `auth/session`,
  `auth/logout`, including the rule that `logout` refuses machine keys.
- **Error-code tables** — add the new auth cases (bad login credentials,
  machine key on `logout`, session key on `management/*`).
- **Management API section** — state the machine-key-only rule.
- **Client headers** — `client_app`/`client_version` and the 426
  `UpgradeRequired` response, in the error-code tables and a short
  client-versioning note.
- **Settings** — `api_session_key_lifetime_days` and
  `api_min_client_versions` documented in their `settings.json`
  declarations (helptext) and mentioned in `docs/api.md` where session-key
  expiry and client versioning are described.

No other docs are affected: `docs/settings.md` documents the settings
*system*, not individual settings; the profile sessions view and the admin
API Keys type filter are self-documenting pages whose developer-facing
behavior is covered by the `docs/api.md` lifecycle section.
