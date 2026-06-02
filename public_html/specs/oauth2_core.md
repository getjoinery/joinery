# OAuth2 Core (platform authorization-code client + provider catalog)

## Overview

A **platform-level OAuth2 client** that any feature can use to obtain and keep a
valid access token for a third-party account, plus a small **catalog of concrete
providers** (Google, Microsoft to start). It implements the OAuth2
**authorization-code grant with refresh** once, correctly, in core
(`includes/oauth/`) — consent-URL building, `code`→token exchange, token refresh,
session-stored single-use `state` (CSRF), and a **single generic callback** that
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
  OAuth2State::issue(...) ── stores flow in $_SESSION under a single-use nonce
      │  → opaque `state` nonce + provider consent URL
      ▼
  [ browser → provider consent screen → Allow / Deny ]
      │  Allow → /oauth/callback?code=…&state=…
      │  Deny  → /oauth/callback?error=access_denied&state=…   (no code)
      ▼
  Generic callback (serve.php route /oauth/callback)
      ├─ OAuth2State::validate(state)      (expiry · single-use · session-intrinsic)
      │       (any error path below still consumes the state)
      ├─ if `error`/no `code` (user denied or provider error):
      │       └─ redirect to state.returnUrl?oauth=cancelled   ← NO token exchange
      ├─ provider = OAuth2ProviderRegistry::get(state.provider)
      ├─ token    = OAuth2Client::exchangeCode(provider, code, redirectUri)
      ├─ consumer = OAuth2ConsumerRegistry::get(state.purpose)
      └─ redirect = consumer->onTokenGranted(token, state.payload)   ← feature stores token, returns success URL
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
The grant engine — implemented **directly on Guzzle**. Provider-agnostic; no
feature knowledge. The flow is two standards-compliant token-endpoint POSTs plus
consent-URL assembly, so no OAuth library is used.
- `beginConsent(string $providerKey, array $scopes, string $purpose, array $payload, string $returnUrl): string`
  — issues state, returns the provider consent URL to redirect to. `$returnUrl` is
  the **cancel/error** destination (a same-site path): where the callback sends the
  user if they deny consent or the provider returns an error — i.e. any path with
  no token. The **success** destination is the consumer's job (`onTokenGranted`
  return value), not `$returnUrl`.
- `exchangeCode(OAuth2Provider $p, string $code, string $redirectUri): OAuth2Token`
  — `authorization_code` grant.
- `refresh(OAuth2Provider $p, string $refreshToken): OAuth2Token`
  — `refresh_token` grant.
- `ensureFresh(OAuth2Provider $p, OAuth2Token $t): OAuth2Token`
  — returns `$t` if still valid, else refreshes and returns the new token (caller
  persists). On refresh failure, throws `OAuth2Exception` (consumer records
  status; never crashes a batch job).

### `OAuth2State` — session-stored, single-use
The CSRF and dispatch carrier, built on the **same mechanism as the existing CSRF
tokens** (`FormWriterV2Base`): a single-use, expiring entry held server-side in
`$_SESSION`. The opaque `state` query param is just an unguessable random nonce
(`LibraryFunctions::str_rand(64)`); all flow data lives in the session entry it
keys — never in the browser. No HMAC and no signing are needed, because the value
never carries data the client could tamper with: the session itself is the trust
anchor.

Session entry (`$_SESSION['oauth_flows'][$nonce]`): `provider`, `purpose`,
`payload` (consumer's opaque data, e.g. `{account_id: N}`), `returnUrl`, `scopes`,
`expires` (issue + 10 min).
- `issue(...)` generates the nonce, writes the entry, and prunes any expired
  entries (mirroring `cleanExpiredCSRFTokens`). Returns the nonce as the `state`
  param.
- `validate($state)`: look up `$_SESSION['oauth_flows'][$state]`; reject if absent
  or expired (no token exchange). On success, **unset the entry** (single-use) and
  return the decoded flow.

Session binding is **intrinsic** — the entry exists only in the session that issued
it, so a callback arriving in any other session simply finds no match. There is no
`session_id` to compare and no DB row at all. Consequently the callback needs **no
permission gate**; the originating page's permission check (e.g. the IMAP admin page
at permission 10) governs who can ever start a flow, and a public consumer (future
social login) works through the identical mechanism without special-casing.

> **Requirement — session cookie must stay `SameSite=Lax` (not `Strict`).** The
> provider redirect back to `/oauth/callback` is a cross-site top-level GET
> navigation. `Lax` (the current `SessionControl` default) sends the session cookie
> on exactly that; `Strict` would withhold it, the callback would start a fresh
> session, find no `oauth_flows` entry, and **every** flow would fail. Session-backed
> state depends on this — don't tighten the session cookie to `Strict`.

### `OAuth2Consumer` (interface) + `OAuth2ConsumerRegistry`
How a feature receives its token. Discovered by interface across **core
`includes/oauth/consumers/` and active-plugin `includes/oauth_consumers/`**
(same scan-and-walk pattern as the provider registry, extended to plugin paths so
plugins can register consumers).

```php
interface OAuth2Consumer {
    public static function getPurpose(): string;  // 'inbound_imap', 'social_login', …
    /** Persist the token for this purpose's payload; return the SUCCESS redirect URL
     *  (same-site). Only called when a token was actually granted — the deny/error
     *  path never reaches here and uses the flow's returnUrl instead. */
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

### State store — none (server-side session)
There is **no state table and no state data class.** Issued flows live in
`$_SESSION['oauth_flows']`, are consumed (unset) on validate, and expired entries
are pruned on the next `issue()` — exactly how the CSRF-token store self-cleans.
Nothing to migrate, no rows to sweep.

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

> Secret-at-rest nuance: `stg_settings` stores strings. The client *secret* values
> are written through `SecretBox` before persisting and decrypted in
> `getClientSecret()`.

## Admin UI — `adm/admin_oauth_providers.php` (+ logic)

A core admin page (permission 10), linked under Settings, to enter OAuth app
credentials once per provider:
- Per provider (Google, Microsoft): client id, client secret (password field),
  Microsoft tenant; a read-only **Redirect URI** to copy into the cloud console; a
  **configured?** indicator.
- FormWriter only; secrets via password fields, written through `SecretBox`, never
  echoed back (render a "•••• set" affordance, not the value).
- Links out to `docs/oauth2.md` setup steps.

**Redirect URI derivation.** The callback's `redirect_uri` is
`LibraryFunctions::get_absolute_url('/oauth/callback')` — the same helper Stripe and
PayPal use for their return URLs. It resolves the origin from the **`webDir`
setting** (the canonical configured host) + `protocol_mode`, *not* raw `HTTP_HOST`,
so the value is stable and identical across requests. The admin page's read-only
"Redirect URI" field renders this exact call, guaranteeing the string the admin
pastes into the Google/Azure console byte-for-byte matches what `exchangeCode`
sends (providers reject any mismatch). `exchangeCode`'s `$redirectUri` argument is
this same value. **Implication:** `webDir` must be set correctly per environment
(dev vs prod each register their own redirect URI in their own cloud app).

## Routing — `serve.php`

One route: `/oauth/callback` → the generic callback handler
(`views/oauth_callback.php` + logic — a view, not `ajax/`, since it's a top-level
browser navigation that must render a neutral error page on failure). **No
`min_permission`** (state session-binding governs auth). Handler order:
1. `OAuth2State::validate(state)` first — always. A forged/expired/foreign-session
   state has no flow to trust (no `returnUrl`, no `purpose`), so it renders a
   neutral error page and logs server-side — never redirects anywhere it was told.
2. With a valid flow, branch on the provider's response: if `error` is present or
   `code` is absent (user denied, or provider error), **skip token exchange** and
   redirect to the flow's `returnUrl` with `?oauth=cancelled`.
3. Otherwise exchange the code, dispatch to the consumer, and redirect to the URL
   `onTokenGranted` returns (the success destination).

No token or secret ever appears in an error message or the URL.

## Security

- **Secrets at rest:** client secrets and all refresh tokens go through
  `SecretBox`; refresh tokens grant long-lived access and are never logged,
  echoed, or returned to the browser.
- **CSRF / replay:** `state` is an unguessable random nonce that exists only in the
  initiating browser's server-side session; it's single-use (consumed on validate)
  and 10-minute-expiring. A forged, replayed, foreign-session, or expired state has
  no matching session entry and is rejected before any token exchange.
- **Open-redirect safety:** every redirect target — both the originating page's
  `returnUrl` (cancel/error) and the consumer's `onTokenGranted` return (success) —
  is validated to be a same-site path (leading `/`, no scheme/host) before
  redirecting.
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
| `includes/oauth/OAuth2State.php` | session-stored single-use state (CSRF + dispatch) |
| `includes/oauth/OAuth2Consumer.php` | consumer interface |
| `includes/oauth/OAuth2ConsumerRegistry.php` | discover consumers (core + plugin paths) |
| `includes/oauth/OAuth2Exception.php` | typed failure for grant/refresh errors |
| `includes/SecretBox.php` | encrypt/decrypt secrets at rest (general core helper) |
| `adm/admin_oauth_providers.php` (+ `logic/admin_oauth_providers_logic.php`) | provider app-credential admin |
| `views/oauth_callback.php` (+ logic) | generic callback handler |
| `tests/integration/oauth/oauth2_client_test.php` | exchange + refresh against a mock token endpoint |
| `tests/integration/oauth/oauth2_state_test.php` | expiry/replay/cross-session/return-url rejection |
| `tests/integration/oauth/secret_box_test.php` | encrypt/decrypt round-trip, tamper detection, missing-key fail-closed |
| `tests/integration/oauth/fixtures/TestEchoConsumer.php` | stub `OAuth2Consumer` (`purpose: test_echo`) that records the token and returns a fixed same-site URL |
| `tests/integration/oauth/fixtures/TestOAuthProvider.php` | stub `OAuth2Provider` (`key: test`) pointing at the mock OAuth2 server |

### To modify
| File | Change |
|------|--------|
| `settings.json` | add the five `oauth_*` settings |
| `serve.php` | add `/oauth/callback` route (no permission gate) |
| `config/Globalvars_site.php` (+ installer / `_site_init.sh`) | add `secret_box_key`; generate on install |
| `admin_menus.json` | add "OAuth Providers" item under Settings |
| `composer.json` | declare `guzzlehttp/guzzle: ^7.4` (currently only transitive via `aws/aws-sdk-php`) as a direct dependency |

### Schema
**No schema changes.** OAuth state lives in `$_SESSION`, not a table. `secret_box_key`
(config) and the `oauth_*` settings are config/settings, not schema.

## Scheduled tasks

None. Expired state entries are pruned in-process on the next `OAuth2State::issue()`
(the same self-cleaning approach as the CSRF-token store), so there is no DB table to
sweep and no scheduled task to add.

## Testing

- **Client** (mock token endpoint): `exchangeCode` parses access/refresh/expiry;
  `refresh` updates the access token and preserves a prior refresh token when the
  response omits one; `ensureFresh` refreshes only within the skew window; a
  non-2xx token response raises `OAuth2Exception`.
- **State:** valid round-trip; rejects expired, replayed (consumed), a nonce that
  isn't in the current session, and a `returnUrl` with a scheme/host.
- **SecretBox:** round-trip; ciphertext ≠ plaintext and varies per call (nonce);
  tampered blob fails to decrypt; missing key throws.
- **Provider registry:** discovers Google + Microsoft; `configured()` excludes a
  provider with blank credentials; Microsoft endpoints reflect the tenant setting.
- **Callback (integration):** a forged/expired state never reaches token exchange
  (renders the neutral error, redirects nowhere); a valid state + `code` dispatches
  to a stub consumer and redirects to its same-site success URL; a valid state with
  `error=access_denied` (no `code`) skips exchange and redirects to the flow's
  `returnUrl` with `?oauth=cancelled`; an off-site `returnUrl` or success URL is
  rejected.

Run `php -l` + `validate_php_file.php` on every created/modified PHP file. Live
end-to-end (real Google/Microsoft consent) is a manual checklist in
`docs/oauth2.md`.

### Standalone testability (no feature integration required)

This core is exercised end-to-end with **no IMAP, social login, or other consumer
present** — the consumer registry is the seam that makes it self-testing. Two
fixtures, both living under `tests/integration/oauth/fixtures/`, stand in for a
real feature and a real cloud app:

- **Test consumer** — an `OAuth2Consumer` with `getPurpose() === 'test_echo'` whose
  `onTokenGranted()` records the granted token to a test sink (temp file or a
  scratch row) and returns a fixed same-site URL. Because the callback dispatches
  purely on `state.purpose`, this drives the full callback path without any product
  code. The fixture is loaded only by the test bootstrap, never registered in a
  live consumer directory.
- **Test provider** — an `OAuth2Provider` (`getKey() === 'test'`) whose endpoints
  point at the mock server below and whose credentials come from test settings.

**Layer 1 — no network.** All grant mechanics run against Guzzle's `MockHandler`
(canned token-endpoint responses), so `exchangeCode`/`refresh`/`ensureFresh`,
`OAuth2State`, `SecretBox`, and the provider registry are covered with zero live
HTTP. The callback test forges/validates `state` and dispatches to the test
consumer with a mocked exchange.

**Layer 2 — real consent loop, no Google/Azure.** Point the test provider at a
self-hosted mock OAuth2 server to exercise the genuine
`beginConsent → redirect → code → exchangeCode → consumer → redirect` path:
- **`navikt/mock-oauth2-server`** (single container) is preferred — it
  **auto-approves consent** and issues real tokens against the test client id +
  the `/oauth/callback` redirect URI, so the loop completes with no human
  interaction. The Playwright MCP drives the single consent navigation; the
  callback then runs the real code exchange and the test consumer records the
  token. (Keycloak/Dex/Hydra work too if a real consent screen is wanted.)
- Google's OAuth Playground is **not** usable here — it cannot redirect to
  `/oauth/callback`, so it does not test this code path.

The only behavior these fixtures cannot reproduce is provider-specific
refresh-token policy (Google's `access_type=offline`+`prompt=consent`, Microsoft's
tenant endpoints + `offline_access`) — that stays the manual `docs/oauth2.md`
checklist above. Everything else is automated and consumer-free.

## Documentation

- New **`docs/oauth2.md`**: the abstraction (provider catalog, client, state,
  consumers, SecretBox), how to **add a provider** (one `OAuth2Provider` class +
  two settings), how to **add a consumer** (one `OAuth2Consumer` + a
  `beginConsent` call), the single shared redirect URI, and step-by-step Google
  Cloud + Azure app registration (scopes, redirect URI, where to paste
  credentials).
- `docs/settings.md`: the `oauth_*` settings and that client secrets are stored
  encrypted.

## Versioning

- New core files start at `@version 1.0`.
- Bump `@version` on each modified file.
- **No OAuth library:** `OAuth2Client` is implemented directly on Guzzle. The grant
  engine is two standards-compliant HTTP POSTs (`authorization_code`,
  `refresh_token`) plus consent-URL assembly; an OAuth library
  (`league/oauth2-client`) is intentionally not used — it would add three packages
  and a second HTTP layer while covering none of the app-specific security work
  (`OAuth2State`, `SecretBox`, callback dispatch) this core owns. Everything routes
  through `OAuth2Client`, so a library could be wrapped in later without touching
  providers or consumers.
- **Declare Guzzle explicitly.** Guzzle is already installed but only as a
  *transitive* dependency (pulled in by `aws/aws-sdk-php` and others); it is not in
  `composer.json`. Since `OAuth2Client` uses `GuzzleHttp\Client` directly, add
  `guzzlehttp/guzzle: ^7.4` to the `require` block so the dependency is owned, not
  borrowed — otherwise dropping/upgrading the AWS SDK could silently remove or bump
  it. This adds nothing to `vendor/` (the satisfying version is already present); it
  only records the requirement in `composer.json`/lock.

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
