# OAuth2 Core (platform authorization-code client + provider catalog)

## Overview

A **platform-level OAuth2 client** that any feature can use to obtain and keep a
valid access token for a third-party account, plus a small **catalog of concrete
providers** (Google, Microsoft to start). It implements the OAuth2
**authorization-code grant with refresh** once, correctly, in core
(`includes/oauth/`) — consent-URL building, `code`→token exchange, token refresh,
signed single-use `state` (CSRF), and a **single generic callback** that
dispatches the resulting token back to whichever feature initiated the flow.

This is deliberately **provider- and consumer-agnostic**. The grant mechanics and
the provider endpoint definitions are general; *what scope you request* and *what
you do with the token* belong to the consuming feature. The first consumer is the
[Inbound IMAP provider](inbound_imap_provider.md); social login and OAuth-based
outbound SMTP are anticipated consumers (see "Integration inventory").

It replaces the one-off pattern of `includes/AcuitySchedulingOAuth.php` (endpoints
hardwired to a single product) with a reusable seam.

## Why this is a core abstraction, not a plugin feature

The OAuth2 authorization-code flow is byte-for-byte identical regardless of *why*
you want a token — only the **provider** (endpoints, app credentials) and the
**scope + token consumer** differ. Building it inside the inbound-email plugin
would bake in IMAP assumptions (mail scope, token storage on an IMAP row, an
IMAP-specific callback) and force the next consumer to copy-paste. So the grant
engine and provider catalog live in **core**; each feature plugs in as a
*consumer*.

A second decisive reason: **the redirect URI must be pre-registered** in the
Google Cloud / Azure app and match exactly. A single core callback
(`/oauth/callback`) means you register **one** redirect URI per provider per
environment, forever — adding a new consumer never requires touching the cloud
app registration.

## Integration inventory (decide the seam once)

The abstraction is sized for every consumer we can foresee, so the interface is
settled up front rather than grown per feature:

| Consumer | Provider(s) | Scope(s) | Token use | Status |
|----------|-------------|----------|-----------|--------|
| **Inbound IMAP poll** | Google, Microsoft | `https://mail.google.com/` · `IMAP.AccessAsUser.All offline_access` | XOAUTH2 IMAP login | this round ([spec](inbound_imap_provider.md)) |
| Social login ("Sign in with…") | Google, Microsoft | `openid email profile` | identity | anticipated |
| Outbound SMTP (XOAUTH2 send) | Google, Microsoft | mail send scope | XOAUTH2 SMTP | anticipated |
| Gmail API / Graph transports | Google, Microsoft | API scopes | REST calls | future |

| Provider | Added now | Extensible to |
|----------|-----------|---------------|
| Google / Google Workspace | ✅ | — |
| Microsoft (Azure AD, `common`/tenant) | ✅ | — |
| Apple, GitHub, generic OIDC | — | new `OAuth2Provider` class only |

The interface below is shaped so that **a new consumer is a new
`OAuth2Consumer`** (a purpose key + a token-granted handler) and **a new provider
is a new `OAuth2Provider`** (endpoints + app-credential settings) — nothing else
changes.

## Architecture

```
  Feature page (e.g. IMAP account edit)
      │  OAuth2Client::beginConsent(provider, scopes, purpose, payload, returnUrl)
      ▼
  OAuth2State::issue(...) ── persists single-use nonce, binds to session
      │  → signed `state` param + provider consent URL
      ▼
  [ browser → provider consent screen ]
      │  provider redirects to /oauth/callback?code=…&state=…
      ▼
  Generic callback (serve.php route /oauth/callback)
      ├─ OAuth2State::validate(state)      (signature · expiry · single-use · session)
      ├─ provider = OAuth2ProviderRegistry::get(state.provider)
      ├─ token    = OAuth2Client::exchangeCode(provider, code, redirectUri)
      ├─ consumer = OAuth2ConsumerRegistry::get(state.purpose)
      ├─ redirect = consumer->onTokenGranted(token, state.payload)   ← feature stores token
      └─ mark state consumed → redirect
              │
              ▼ (later, on demand)
  OAuth2Client::ensureFresh(provider, token) → refresh if near expiry → fresh access token
```

The callback knows nothing about IMAP, social login, or any feature — it
dispatches purely on `state.purpose` through the consumer registry.

## Components — `includes/oauth/` (new)

### `OAuth2Provider` (interface)
A provider's static identity. Endpoints are constants; credentials are read from
settings so they are entered once in admin, never hardcoded.

```php
interface OAuth2Provider {
    public static function getKey(): string;             // 'google' | 'microsoft'
    public static function getLabel(): string;           // 'Google' | 'Microsoft'
    public static function getAuthorizeEndpoint(): string;
    public static function getTokenEndpoint(): string;
    public static function getClientId(): string;        // from settings (see below)
    public static function getClientSecret(): string;    // from settings (decrypted via SecretBox)
    public static function isConfigured(): bool;          // client id + secret present
    /** Provider quirks merged into the authorize query (e.g. Google access_type=offline&prompt=consent). */
    public static function extraAuthorizeParams(array $scopes): array;
}
```

### `OAuth2ProviderRegistry`
Interface-based discovery, mirroring `InboundProviderRegistry`: `require_once`
every file in `includes/oauth/providers/`, walk `get_declared_classes()` for
`OAuth2Provider` implementations, key by `getKey()`. `get($key)` / `all()` /
`configured()` (only those with credentials present).

### Concrete providers — `includes/oauth/providers/`
- **`GoogleOAuthProvider`** — authorize `https://accounts.google.com/o/oauth2/v2/auth`,
  token `https://oauth2.googleapis.com/token`; `extraAuthorizeParams` adds
  `access_type=offline` and `prompt=consent` (required to receive a refresh
  token reliably).
- **`MicrosoftOAuthProvider`** — endpoints templated on the configured tenant
  (`oauth_microsoft_tenant`, default `common`):
  `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/{authorize,token}`;
  scopes must include `offline_access` for a refresh token.

### `OAuth2Token` (value object)
`access_token`, `refresh_token` (nullable on refresh responses that omit it —
keep the prior one), `expires_at` (absolute UTC `Y-m-d H:i:s`, computed from
`expires_in`), `scope`, `token_type`. `isExpired($skew = 60)` returns true within
`$skew` seconds of expiry. Immutable; `withRefreshedAccess(...)` returns a copy.

### `OAuth2Client`
The grant engine — implemented **directly on Guzzle** (already vendored; no new
dependency). Provider-agnostic; no feature knowledge. The flow is two
standards-compliant token-endpoint POSTs plus consent-URL assembly, so no OAuth
library is used.
- `beginConsent(string $providerKey, array $scopes, string $purpose, array $payload, string $returnUrl): string`
  — issues state, returns the provider consent URL to redirect to.
- `exchangeCode(OAuth2Provider $p, string $code, string $redirectUri): OAuth2Token`
  — `authorization_code` grant.
- `refresh(OAuth2Provider $p, string $refreshToken): OAuth2Token`
  — `refresh_token` grant.
- `ensureFresh(OAuth2Provider $p, OAuth2Token $t): OAuth2Token`
  — returns `$t` if still valid, else refreshes and returns the new token (caller
  persists). On refresh failure, throws `OAuth2Exception` (consumer records
  status; never crashes a batch job).

### `OAuth2State` — signed, single-use, session-bound
The CSRF and dispatch carrier. The opaque `state` query param is
`base64url(payload_json) . '.' . hmac_sha256(payload_json, key)` where the key is
`oauth_state_secret` (see Settings). Payload: `nonce`, `provider`, `purpose`,
`payload` (consumer's opaque data, e.g. `{account_id: N}`), `session_id`,
`expires` (issue + 10 min).
- `issue(...)` signs the payload **and** persists the nonce (table below) so it
  can be consumed exactly once.
- `validate($state)`: verify HMAC (constant-time `hash_equals`), not expired,
  nonce exists-and-unconsumed, `session_id` equals the current session. Any
  failure → reject (no token exchange). On success, atomically mark the nonce
  consumed and return the decoded payload.

Session binding means an admin-initiated flow can only be completed in the same
browser session that started it — so the callback itself needs **no permission
gate**; the originating page's permission check (e.g. the IMAP admin page at
permission 10) governs who can ever start a flow, and a public consumer (future
social login) works through the identical mechanism without special-casing.

### `OAuth2Consumer` (interface) + `OAuth2ConsumerRegistry`
How a feature receives its token. Discovered by interface across **core
`includes/oauth/consumers/` and active-plugin `includes/oauth_consumers/`**
(same scan-and-walk pattern as the provider registry, extended to plugin paths so
plugins can register consumers).

```php
interface OAuth2Consumer {
    public static function getPurpose(): string;  // 'inbound_imap', 'social_login', …
    /** Persist the token for this purpose's payload; return the URL to redirect the admin/user to. */
    public function onTokenGranted(OAuth2Token $token, array $payload): string;
}
```

The IMAP consumer (defined in the inbound plugin) stores the encrypted tokens on
the bound IMAP account and returns the IMAP-accounts admin URL — see the IMAP
spec. The callback never references it directly.

### `SecretBox` — `includes/SecretBox.php` (new core helper)
Encrypt/decrypt secrets at rest. Authenticated encryption via libsodium
(`sodium_crypto_secretbox`) when the extension is present, else
`openssl_encrypt('aes-256-gcm')`; output is a self-describing string
(`v1.<algo>.<nonce>.<ciphertext>`, base64url parts) so the algorithm is recorded
with the value. Keyed from **`secret_box_key`** in `config/Globalvars_site.php`
(32 random bytes, base64). `encrypt($plaintext): string` / `decrypt($blob):
string`; `decrypt` throws on tamper/auth failure. No DB dependency; reusable by
any caller that stores a credential (OAuth client secrets, refresh tokens, the
IMAP passwords in the IMAP spec). **Never logs or echoes plaintext.**

> Build note: `secret_box_key` is generated once per environment and added to
> `Globalvars_site.php` (alongside DB creds). The installer/`_site_init.sh`
> should generate it; document manual generation
> (`base64_encode(random_bytes(32))`) for existing sites. If the key is absent,
> `SecretBox` throws on construction — fail closed, never store plaintext.

### State store — `data/oauth_state_class.php` (new)
`OauthState` (`SystemBase`) + Multi, prefix `oas`, table `oas_oauth_states`.
Minimal single-use/replay record (the signed payload carries the data; this table
enforces *consumed-once* + expiry):

| Field | Type | Notes |
|-------|------|-------|
| `oas_oauth_state_id` | int8 serial PK | |
| `oas_nonce` | varchar(64) | unique; the random nonce in the signed payload |
| `oas_purpose` | varchar(40) | for debugging/audit |
| `oas_session_id` | varchar(128) | session the flow was issued in |
| `oas_consumed_time` | timestamp(6) | null until consumed (single-use) |
| `oas_expires_time` | timestamp(6) | issue + 10 min |
| `oas_create_time` | timestamp(6) | |

`getMultiResults()` filters: `nonce`, `unconsumed`, `expired`. Expired/consumed
rows are pruned by an existing cleanup task or a tiny step in a maintenance task
(see Scheduled tasks).

## Settings (core — `settings.json`)

OAuth **app** credentials are shared across all consumers (IMAP, social login,
send…), so they are core, not plugin-owned — consistent with "settings live with
their owner; the owner here is the cross-cutting OAuth core."

| Setting | Default | Notes |
|---------|---------|-------|
| `oauth_google_client_id` | `""` | |
| `oauth_google_client_secret` | `""` | stored via `SecretBox`; entered in admin |
| `oauth_microsoft_client_id` | `""` | |
| `oauth_microsoft_client_secret` | `""` | `SecretBox` |
| `oauth_microsoft_tenant` | `common` | `common`/`organizations`/`consumers`/a tenant id |
| `oauth_state_secret` | `""` | HMAC key for `state`; auto-generated on first admin save if blank |

> Secret-at-rest nuance: `stg_settings` stores strings. The client *secret* values
> are written through `SecretBox` before persisting and decrypted in
> `getClientSecret()`. `oauth_state_secret` is an HMAC key, not user-facing — seed
> it with random bytes the first time the admin saves provider config if empty.

## Admin UI — `adm/admin_oauth_providers.php` (+ logic)

A core admin page (permission 10), linked under Settings, to enter OAuth app
credentials once per provider:
- Per provider (Google, Microsoft): client id, client secret (password field),
  Microsoft tenant; a read-only **Redirect URI** to copy into the cloud console
  (`https://{site}/oauth/callback`); a **configured?** indicator.
- FormWriter only; secrets via password fields, written through `SecretBox`, never
  echoed back (render a "•••• set" affordance, not the value).
- Links out to `docs/oauth2.md` setup steps.

## Routing — `serve.php`

One route: `/oauth/callback` → the generic callback handler
(`views/oauth_callback.php` or `ajax/oauth_callback.php`). **No `min_permission`**
(state session-binding governs auth). The handler does only: validate state →
exchange code → dispatch to consumer → redirect. On any validation failure it
shows a neutral error and logs server-side (no token/secret in the message).

## Security

- **Secrets at rest:** client secrets and all refresh tokens go through
  `SecretBox`; refresh tokens grant long-lived access and are never logged,
  echoed, or returned to the browser.
- **CSRF / replay:** `state` is HMAC-signed, single-use (DB nonce), session-bound,
  and 10-minute-expiring. A tampered, replayed, foreign-session, or expired state
  is rejected before any token exchange.
- **Open-redirect safety:** the consumer-supplied `returnUrl` is validated to be a
  same-site path (leading `/`, no scheme/host) before redirecting.
- **Scope minimization:** consumers request only the scope they need; the admin
  setup docs state plainly what each scope grants.
- **No fabricated success:** any failure in validate/exchange/refresh surfaces as
  an error or a recorded status — never a partial/fake token.

## Files

### To create
| File | Purpose |
|------|---------|
| `includes/oauth/OAuth2Provider.php` | provider interface |
| `includes/oauth/OAuth2ProviderRegistry.php` | discover providers by interface |
| `includes/oauth/providers/GoogleOAuthProvider.php` | Google endpoints + offline-access params |
| `includes/oauth/providers/MicrosoftOAuthProvider.php` | Microsoft endpoints + tenant |
| `includes/oauth/OAuth2Token.php` | token value object |
| `includes/oauth/OAuth2Client.php` | grant engine (beginConsent/exchange/refresh/ensureFresh) |
| `includes/oauth/OAuth2State.php` | signed single-use session-bound state |
| `includes/oauth/OAuth2Consumer.php` | consumer interface |
| `includes/oauth/OAuth2ConsumerRegistry.php` | discover consumers (core + plugin paths) |
| `includes/oauth/OAuth2Exception.php` | typed failure for grant/refresh errors |
| `includes/SecretBox.php` | encrypt/decrypt secrets at rest (general core helper) |
| `data/oauth_state_class.php` | `OauthState` + Multi (single-use nonce store) |
| `adm/admin_oauth_providers.php` (+ `logic/admin_oauth_providers_logic.php`) | provider app-credential admin |
| `views/oauth_callback.php` (+ logic) | generic callback handler |
| `tests/integration/oauth/oauth2_client_test.php` | exchange + refresh against a mock token endpoint |
| `tests/integration/oauth/oauth2_state_test.php` | signature/expiry/replay/session-binding/return-url rejection |
| `tests/models/oauth_state_test.php` | model CRUD + filters |
| `tests/integration/oauth/secret_box_test.php` | encrypt/decrypt round-trip, tamper detection, missing-key fail-closed |

### To modify
| File | Change |
|------|--------|
| `settings.json` | add the six `oauth_*` settings |
| `serve.php` | add `/oauth/callback` route (no permission gate) |
| `config/Globalvars_site.php` (+ installer / `_site_init.sh`) | add `secret_box_key`; generate on install |
| `admin_menus.json` | add "OAuth Providers" item under Settings |

### Schema
`oas_oauth_states` is created by `update_database` from the data class — no
migration. `oauth_state_secret` / `secret_box_key` are config/settings, not
schema.

## Scheduled tasks

Pruning expired/consumed `oas_oauth_states` is a one-line delete; fold it into an
existing maintenance task (e.g. the request-log/retention sweep) rather than a new
task — note it in `docs/scheduled_tasks.md`.

## Testing

- **Client** (mock token endpoint): `exchangeCode` parses access/refresh/expiry;
  `refresh` updates the access token and preserves a prior refresh token when the
  response omits one; `ensureFresh` refreshes only within the skew window; a
  non-2xx token response raises `OAuth2Exception`.
- **State:** valid round-trips; rejects tampered HMAC, expired, replayed
  (consumed), foreign-session, and a `returnUrl` with a scheme/host.
- **SecretBox:** round-trip; ciphertext ≠ plaintext and varies per call (nonce);
  tampered blob fails to decrypt; missing key throws.
- **Provider registry:** discovers Google + Microsoft; `configured()` excludes a
  provider with blank credentials; Microsoft endpoints reflect the tenant setting.
- **Callback (integration):** a forged/expired state never reaches token
  exchange; a valid state dispatches to a stub consumer and redirects to its
  same-site URL.

Run `php -l` + `validate_php_file.php` on every created/modified PHP file. Live
end-to-end (real Google/Microsoft consent) is a manual checklist in
`docs/oauth2.md`.

## Documentation

- New **`docs/oauth2.md`**: the abstraction (provider catalog, client, state,
  consumers, SecretBox), how to **add a provider** (one `OAuth2Provider` class +
  two settings), how to **add a consumer** (one `OAuth2Consumer` + a
  `beginConsent` call), the single shared redirect URI, and step-by-step Google
  Cloud + Azure app registration (scopes, redirect URI, where to paste
  credentials).
- `docs/settings.md`: the `oauth_*` settings and that client secrets are stored
  encrypted.
- `docs/scheduled_tasks.md`: the state-pruning step.

## Versioning

- New core files start at `@version 1.0`.
- Bump `@version` on each modified file. **No new Composer dependency:**
  `OAuth2Client` is implemented directly on Guzzle (already vendored). The grant
  engine is two standards-compliant HTTP POSTs (`authorization_code`,
  `refresh_token`) plus consent-URL assembly; an OAuth library
  (`league/oauth2-client`) is intentionally not used — it would add three packages
  and a second HTTP layer while covering none of the app-specific security work
  (`OAuth2State`, `SecretBox`, callback dispatch) this core owns. Everything routes
  through `OAuth2Client`, so a library could be wrapped in later without touching
  providers or consumers.

## Out of scope / future

- **Token use is the consumer's job.** This core hands back a valid
  `OAuth2Token`; formatting XOAUTH2, calling Gmail/Graph, or establishing identity
  is the consumer's responsibility.
- **PKCE / public clients.** All current consumers are confidential server-side
  clients (client secret held server-side). PKCE can be added to `OAuth2Client`
  later without changing the consumer/provider seams.
- **Auto-provisioning cloud apps.** The admin registers the Google/Azure app once
  and pastes credentials; the platform never creates cloud apps.
- **Per-user multiple providers / account linking UI** beyond what a consumer
  needs — a consumer concern, not the core's.
