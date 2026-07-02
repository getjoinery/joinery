# Mobile App Platform

The server-side foundation for native apps (iOS and Android) on any Joinery
deployment. An app is a native shell — login, account forms, navigation,
settings — around the authenticated web surface: every `/profile` page renders
inside the app through a chrome-less webview, and any surface can later be
promoted to a native screen without auth or navigation rework.

Three server pieces make this work, all client-agnostic:

1. **Navigation endpoint** — `GET /api/v1/app/navigation` returns the user's
   menu as a routing table for the app's tab bar and More list.
2. **Web-session bridge** — `POST /api/v1/auth/web_session` + `/app_bridge`
   turn the app's API session key into a web session for the webview, with no
   second login.
3. **App display mode** — bridged web sessions render pages without site
   chrome (header, navigation, footer).

Everything else an app consumes is the standard API surface: session keys
(`docs/api.md` § Auth Endpoints), actions, and form JSON
(`docs/formwriter.md` § JSON Output Mode).

## Navigation endpoint

`GET /api/v1/app/navigation` — requires an app **session key** (machine keys
and browser sessions get 403). Served by `includes/ApiAppEndpoint.php`.

The source is the seeded profile menu store (`amu_admin_menus`, location
`user_dropdown`) — the same store every web theme renders — so adding a core
entry in `admin_menus.json` or a plugin `profileMenu` entry makes it appear in
every shipped app with no release. Standard store filters apply: the entry's
`permission` vs the user's, `visibility` (app users are always signed in),
`settingActivate`, and `disable`. Entries the native shell owns (sign in/out,
sign up, forgot password) are excluded.

Response shape:

```json
{
    "data": {
        "tabs": ["core-profile", "core-calendar", "inbound-email-mailbox"],
        "entries": [
            {
                "slug": "core-profile",
                "title": "My Profile",
                "icon": "user",
                "order": 50,
                "destination": { "type": "web", "url": "/profile" }
            }
        ]
    }
}
```

- **`entries`** — every menu entry this user gets, ordered. Each has a
  `destination` the client resolves version-safely: `{type: "web", url}`
  renders in the authenticated webview; `{type: "native", screen,
  fallback_url}` renders the named native screen when the shipped client
  recognizes `screen`, otherwise loads `fallback_url`. Promoting a surface to
  native is a destination change — old app versions keep working through the
  fallback.
- **`tabs`** — the slugs pinned to this app's tab bar, in order; everything
  else belongs in the More list. Configured by the `app_navigation` setting: a
  JSON map of `client_app` (the client header value) → ordered slug list, with
  a `default` key for apps without their own entry. Slugs the user did not
  receive are dropped.

## Web-session bridge

The app authenticates once (`POST /api/v1/auth/login`) and holds a session
key. When a webview needs the web surface, the app derives a web session from
that key — the user never sees a web login page.

**Minting:** `POST /api/v1/auth/web_session` with the session key. Body:
`{"target": "/profile/calendar"}` — a same-origin relative path (absolute
URLs, protocol-relative `//host` forms, backslashes, and raw whitespace are
rejected 400; omitted defaults to `/`). Session keys only: browser sessions
already are a web session (403), and machine keys are integration
credentials, not devices (403). Returns:

```json
{ "data": { "bridge_url": "/app_bridge?token=…64 hex…", "expires_in": 60 } }
```

**Consumption:** the webview loads `bridge_url`. `/app_bridge`
(`logic/app_bridge_logic.php`) claims the token atomically — single-use by
construction — verifies the originating key is still live, starts a normal
web session for the key's user (session id regenerated), marks it
**app-context** with the originating `apk_api_keys` id and `client_app`, and
302s to the target. Invalid, used, or expired tokens render an "expired link"
page (HTTP 410); the app's recovery is minting a fresh token.

Tokens live in `abt_app_bridge_tokens` (`data/app_bridge_tokens_class.php`):
60-second TTL, stored as SHA-256 hashes, purged opportunistically on mint.
The plaintext exists only in the mint response and is never logged.

**Lifetime coupling:** an app-context web session is valid only while its
originating API key is. `SessionControl` re-verifies the key (throttled to
`app_bridge_key_check_seconds`, default 60; `0` = every request) and ends the
session when the key is revoked, expired, or gone. Revoking the key — app
logout, the App Sessions page at `/profile/security`, or a password change —
therefore kills both the app's API access and its webview session in one
gesture.

**App behavior:** bridge on first webview use; the webview cookie store
persists the session across launches; on detecting a logged-out response the
shell silently re-bridges and retries once before surfacing anything.

## App display mode

App-context sessions render pages without the site header, navigation, and
footer — page content and the `jy-ui` design system only. The native shell
supplies titles and back navigation.

The decision lives in one place: `PublicPageBase::show_site_chrome()` (false
for app-context sessions). Every theme wraps its chrome markup in it:

```php
<?php if ($this->show_site_chrome()): ?>
<nav class="site-nav">…</nav>
<?php endif; ?>
```

Only the visual chrome is conditional — stylesheets, scripts, and content
wrappers stay unconditional. `PublicPageBase` also adds a `jy-app-mode` body
class as a CSS hook and suppresses the admin bar for app-context sessions.
Themes must apply the same wrap to their header and footer blocks; the check
is a constant `true` for all ordinary traffic, so wrapped chrome renders
byte-identically outside app mode.

## Settings

| Setting | Default | Purpose |
|---|---|---|
| `app_navigation` | `{"default": ["core-profile", "core-calendar", "inbound-email-mailbox"]}` | Per-app tab-bar pinning (JSON map of `client_app` → ordered slugs) |
| `app_bridge_key_check_seconds` | `60` | How often a bridged session re-verifies its originating key |
| `api_min_client_versions` | `{}` | Per-app minimum client versions (HTTP 426 gate, `docs/api.md` § Client Versioning) |
| `api_session_key_lifetime_days` | `365` | Session key expiry |

## Standing up a new branded app

1. Pick a `client_app` identifier (e.g. `joinery-member-ios`); the app sends
   it with `client-version` on every API request (hyphen header form).
2. Add a tab list for it to the `app_navigation` setting (or rely on
   `default`).
3. Optionally set its minimum version in `api_min_client_versions` once
   shipped.
4. The client work (JoineryKit Swift package, app targets) lives outside this
   codebase — see `specs/ios_app_platform.md` for the platform architecture
   and phases.

## Tests

`tests/functional/api/app_platform_test.php` — navigation filtering and tab
pinning, bridge minting and target validation, single-use and expiry, app
display mode, and lifetime coupling (key revocation and password change).
Runs against dev with curl; see the harness header for usage.
