# OAuth2 Core

A platform-level OAuth2 client that any feature can use to obtain and keep a
valid access token for a third-party account, plus a catalog of concrete
providers. It implements the OAuth2 **authorization-code grant with refresh**
once, in core (`includes/oauth/`): consent-URL building, code→token exchange,
token refresh, single-use session `state`, and one generic callback that
dispatches the resulting token back to whichever feature started the flow.

The grant mechanics and provider endpoints are general. *What scope you request*
and *what you do with the token* belong to the consuming feature. A new feature
that needs OAuth is a new **consumer**; a new identity provider is a new
**provider**. Nothing else changes.

## The pieces

| Class | Role |
|-------|------|
| `OAuth2Client` | The grant engine (on Guzzle): `beginConsent`, `exchangeCode`, `refresh`, `ensureFresh`. Provider- and feature-agnostic. |
| `OAuth2Provider` (interface) | A provider's static identity: endpoints + which settings hold its credentials. Implementations live in `includes/oauth/providers/`. |
| `OAuth2ProviderRegistry` | Discovers providers by interface. `get($key)`, `all()`, `configured()`. |
| `OAuth2Token` | Immutable token value object: access/refresh tokens, absolute UTC expiry, scope. `isExpired()`, `withRefreshedAccess()`. |
| `OAuth2State` | Session-stored, single-use CSRF + dispatch carrier. The `state` param is an opaque random nonce; all flow data lives server-side in `$_SESSION['oauth_flows']`. |
| `OAuth2Consumer` (interface) | How a feature receives its token: a purpose key + `onTokenGranted()`. Discovered across core `includes/oauth/consumers/` and active-plugin `includes/oauth_consumers/`. |
| `OAuth2ConsumerRegistry` | Discovers consumers by interface. |
| [`SecretBox`](secret_box.md) | Authenticated encryption (`includes/SecretBox.php`) for secrets at rest — client secrets and refresh tokens. General-purpose, not OAuth-specific. |

## The flow

```
Feature page
  └─ $client->beginConsent($providerKey, $scopes, $purpose, $payload, $returnUrl)
       → stores the flow in $_SESSION under a single-use nonce, returns the consent URL
  browser → provider consent screen → Allow / Deny
       Allow → /oauth_callback?code=…&state=…
       Deny  → /oauth_callback?error=access_denied&state=…   (no code)
  /oauth_callback (views/oauth_callback.php + logic — resolved by auto-discovery, no route)
       1. OAuth2State::validate(state)         — expiry · single-use · session-intrinsic
       2. error / no code → redirect to flow.returnUrl?oauth=cancelled   (no token exchange)
       3. else exchangeCode → consumer->onTokenGranted(token, payload) → redirect to success URL
```

The callback knows nothing about any feature; it dispatches purely on the
validated flow's `purpose`.

### `beginConsent` arguments

- `$providerKey` — `'google'` | `'microsoft'` | …
- `$scopes` — array of scopes to request (space-joined into the authorize URL).
- `$purpose` — the consumer key that will receive the token (e.g. `'inbound_imap'`).
- `$payload` — opaque data the consumer needs to store the token against (e.g.
  `['account_id' => 7]`). Round-trips through the session, never the browser.
- `$returnUrl` — the **cancel/error** destination (a same-site path). The
  **success** destination is the consumer's job (`onTokenGranted` return value).

## The single shared redirect URI

Every provider and consumer uses one redirect URI:
`LibraryFunctions::get_absolute_url('/oauth_callback')` (the same helper Stripe
and PayPal use). The `/oauth_callback` path resolves to `views/oauth_callback.php`
by view auto-discovery — there is no serve.php route. It resolves the origin from
the **`webDir` setting** + protocol,
not raw `HTTP_HOST`, so it is stable and identical across requests. You register
**one** redirect URI per provider per environment, forever — adding a consumer
never touches the cloud app registration.

`webDir` must be set correctly per environment; dev and prod each register their
own redirect URI in their own cloud app.

## Add a provider

One class in `includes/oauth/providers/` implementing `OAuth2Provider`, plus two
settings for its credentials declared in `settings.json`. Endpoints are
constants; credentials read from settings via the registry. See
`GoogleOAuthProvider` / `MicrosoftOAuthProvider` for the shape.
`getClientSecret()` reads its setting through `SecretBox` so the stored value is
encrypted at rest.

Use `DeclaresOAuthConfigFields` and write a `configGuide()` — that is what makes
the provider appear on the admin page and collectable in place wherever it is
needed. See [A provider declares its own fields](#a-provider-declares-its-own-fields).

Use `DeclaresNoOAuthIdentity` too, unless the provider can say **who** signed in —
see below.

## Identity: who signed in

A consumer that connects a *person's* account needs to know which account it got.
Asking the operator to type it invites a mismatch that surfaces much later as an
opaque authentication failure, because the address is used verbatim as the
credential's username.

A provider that can report it declares three things, and the client does the rest:

| Method | Answers | Google | Microsoft |
|---|---|---|---|
| `identityScopes()` | extra scopes the lookup costs | `openid`, `email` | `User.Read` |
| `getIdentityEndpoint()` | the profile URL to GET | `…/oauth2/v3/userinfo` | `…/v1.0/me` |
| `identityFromProfile($p)` | the address in the payload | `email` | `mail`, else `userPrincipalName` |

`OAuth2Client::fetchIdentity($providerClass, $token)` makes one bearer GET against
the declared endpoint and hands the decoded payload back to the provider to read.
It never throws: a provider that reports no identity, an endpoint that errors, and
a payload with no address all return `null`, and the caller falls back to asking —
with the token already in hand, so losing the convenience never loses the
connection.

**A provider with no endpoint is the ordinary case.** Most providers here are
reached for a capability rather than for a person — a DNS provider's grant is about
a zone — so `DeclaresNoOAuthIdentity` supplies the "cannot" answers in one `use`
line, and only Google and Microsoft override.

The mechanics stay in the client and the knowledge stays in the provider, exactly
as with the token endpoints: the grant engine learns nothing provider-specific.

## Add a consumer

```php
class MyConsumer implements OAuth2Consumer {
    public static function getPurpose(): string { return 'my_feature'; }
    public function onTokenGranted(OAuth2Token $token, array $payload): string {
        // persist $token (encrypt the refresh token with SecretBox) against $payload
        // return the same-site success URL to send the user to
        return '/my-feature/connected';
    }
}
```

Drop it in core `includes/oauth/consumers/` or, for a plugin, the plugin's
`includes/oauth_consumers/`. Then start a flow from your page:

```php
$client = new OAuth2Client();
$url = $client->beginConsent('google', ['https://mail.google.com/'],
    'my_feature', ['account_id' => $id], '/my-feature/edit?id=' . $id);
LibraryFunctions::Redirect($url);
```

Keep a token fresh before use:

```php
$token = $client->ensureFresh(OAuth2ProviderRegistry::get('google'), $token);
// persist $token if it changed
```

### First consumer: Inbound IMAP

The Mailbox plugin's IMAP transport is the first consumer (purpose
`inbound_imap`, in `plugins/mailbox/includes/oauth_consumers/`). One consent
grants **both directions** — IMAP read for the inbound feed and SMTP send for
outbound — so the scopes requested are:

- **Google:** `https://mail.google.com/` (this single scope authorizes IMAP *and*
  SMTP send — no separate send scope).
- **Microsoft:** `https://outlook.office365.com/IMAP.AccessAsUser.All`,
  `https://outlook.office365.com/SMTP.Send`, and `offline_access`.

The provider's own `identityScopes()` ride along, so the flow learns which address
consented rather than trusting one typed in advance.

The consumer handles two shapes of grant. A payload carrying `account_id` is a
**reconnect**: the mailbox exists, so the token is stored on it. A payload carrying
the operator's *intent* (`provider_key`, `reader_user_id`, `security_level`) and no
ids is a **first connection**: the mailbox does not exist yet, and the consumer
creates it from the address the provider reports, through
`ImapFeedProvisioner::provision()`. That inversion is what lets the connect wizard
ask its questions in the order the answers exist — see
[Connecting a mailbox](/plugins/mailbox/docs/overview.md#connecting-a-mailbox).

Either way the granted tokens (encrypted) and scopes are stored on the IMAP
account, and `ensureFresh()` keeps the XOAUTH2 bearer valid for both the poll
and the SMTP send. See
[Receiving by IMAP poll](/plugins/mailbox/docs/overview.md#receiving-by-imap-poll)
and [Email System → Two send modes](/docs/email_system.md#two-send-modes--smtpconfig).
The cloud-app registration (Google Cloud / Azure, the shared redirect URI, pasting
client id/secret) is documented here once; the IMAP overview links to it and adds
only the per-account "Connect" step.

### Consumer: Customer Cloud (Server Manager)

The Server Manager plugin's customer-cloud fulfillment mode (purpose
`customer_cloud`, in `plugins/server_manager/includes/oauth_consumers/`) lets a
hosting buyer grant access to their own Linode account so the provisioning
pipeline can create their server there — billed by Linode to the buyer. Scope
requested: `linodes:read_write` only (instance management, no account/billing
access). The consumer stores the token set (encrypted) on the buyer's
`CustomerCloudAccount` and releases their waiting provisions. See
[Server Manager → Customer-Cloud Fulfillment](/plugins/server_manager/docs/overview.md#customer-cloud-fulfillment).

### Consumer: Relay Cloud (Mailbox) — the grant-per-act shape

The Mailbox plugin's relay cloud provisioning (purpose `relay_cloud`, in
`plugins/mailbox/includes/oauth_consumers/`) uses a different custody shape:
**grant-per-act**. No account link is created and no refresh token is kept —
the consumer seals the access token onto the specific `RelayCloudProvision`
run it was granted for, the run uses it for that one act (create/build or
destroy an instance), and every terminal state erases it. A later act starts
a fresh consent. Use this shape whenever a token's power (here: create and
destroy servers) outweighs the convenience of a standing connection. Scope
requested: `linodes:read_write` only.

This consumer is the *one-click branch* of the credential step: it runs only
when the `linode` OAuth client is configured. Deployments without one get the
same custody through a just-in-time pasted API token instead — the flow's
universal floor.

## Settings

| Setting | Default | Notes |
|---------|---------|-------|
| `oauth_google_client_id` | `""` | |
| `oauth_google_client_secret` | `""` | stored via SecretBox |
| `oauth_linode_client_id` | `""` | |
| `oauth_linode_client_secret` | `""` | stored via SecretBox |
| `oauth_microsoft_client_id` | `""` | |
| `oauth_microsoft_client_secret` | `""` | stored via SecretBox |
| `oauth_microsoft_tenant` | `common` | `common` / `organizations` / `consumers` / a tenant id |
| `oauth_digitalocean_client_id` | `""` | |
| `oauth_digitalocean_client_secret` | `""` | stored via SecretBox |
| `oauth_dnsimple_client_id` | `""` | |
| `oauth_dnsimple_client_secret` | `""` | stored via SecretBox |

Enter them at **Admin → System → OAuth Providers** (permission 10). The page
shows the exact redirect URI to paste into each cloud console and never displays
a stored secret back.

### A provider declares its own fields

`configFields()` names the settings a provider's app registration is made of, and
every surface that collects one renders from that declaration:

```php
class MyOAuthProvider implements OAuth2Provider {
    use DeclaresOAuthConfigFields;   // the whole declaration, for the usual pair
    // …
}
```

The trait derives `oauth_{key}_client_id` and `oauth_{key}_client_secret` from
`getKey()`, which the interface already fixes as the settings prefix. A provider
needing more overrides `configFields()` and calls `self::defaultConfigFields()`
to keep the pair — `MicrosoftOAuthProvider` does this to add its tenant.

Adding a provider is therefore one file. The admin page iterates
`OAuth2ProviderRegistry::all()`, and `OAuth2ProviderConfig::save()` is the single
writer both it and the DNS publish box use, so a provider cannot exist in the
registry yet be unconfigurable, and the two surfaces cannot disagree about how a
secret is stored.

`configGuide()` carries the steps that produce the credential, in the same shape
as FormWriter's `help_modal` — a title, ordered steps, an https deep link, and
the callback URL as a click-to-copy row, since every vendor's registration form
asks for it and it has to match byte-for-byte. It renders as a **How do I get
this?** link on the provider's first field.

### Registering an app while using it

An OAuth app registration is collected wherever it is needed, not only on the
admin page. The DNS publish box renders the same `configFields()` inline when the
provider it needs is unconfigured, saves through the same helper, and continues
to consent in that one request — so a publish is never interrupted by a trip to a
settings page.

Two properties make that safe rather than merely convenient:

- **An app registration is not a write credential.** It cannot act alone; a
  per-publish user grant is still required, and that grant is still discarded
  with the request that used it.
- **It is global.** `oauth_google_client_id` is the same value Google sign-in
  uses, so overwriting it affects every consumer. Saving one requires permission
  10 even where the surrounding page opens at 5; a lesser admin is told what is
  missing rather than shown a control that would be refused.

### `secret_box_key`

Client secrets and refresh tokens are encrypted at rest with
[`SecretBox`](secret_box.md), which is keyed from `secret_box_key` in
`config/Globalvars_site.php` (generated per environment by the installer). If the
key is absent, `SecretBox` fails closed and OAuth credentials cannot be stored.
See the [SecretBox doc](secret_box.md) for the key format, generating one for an
existing site, and the full API — it's a general-purpose helper, not OAuth-specific.

## Register the cloud apps

### Google Cloud

1. **APIs & Services → Credentials → Create credentials → OAuth client ID.**
2. Application type **Web application**.
3. Under **Authorized redirect URIs**, paste the exact value from the OAuth
   Providers admin page (`https://<your-host>/oauth_callback`).
4. Copy the **Client ID** and **Client secret** into the admin page.
5. Consent screen: add the scopes your consumer requests. For IMAP/SMTP use
   `https://mail.google.com/`; for sign-in use `openid email profile`.

Google returns a refresh token reliably only when the authorize request includes
`access_type=offline` and `prompt=consent` — `GoogleOAuthProvider` adds both.

### Microsoft (Azure AD)

1. **Azure portal → App registrations → New registration.**
2. Supported account types: choose to match your `oauth_microsoft_tenant`
   (`common` for any Microsoft account, a tenant id for a single org).
3. **Redirect URI** → platform **Web** → paste the value from the admin page.
4. **Certificates & secrets → New client secret**; copy the value into the admin
   page (Azure shows it once).
5. **API permissions**: add the scopes your consumer requests. Include
   `offline_access` so Microsoft issues a refresh token. For inbound IMAP +
   outbound SMTP, add `IMAP.AccessAsUser.All` and `SMTP.Send` (Office 365 Exchange
   Online). Note M365 tenants may disable SMTP AUTH org-wide — sending then needs a
   tenant admin to enable it, or a relay-class provider (Mailgun/SES).

### Linode

1. **Linode Cloud Manager → Profile → OAuth Apps → Create OAuth App.**
2. Leave **Public** unchecked (the platform is a confidential server-side
   client).
3. **Callback URL**: paste the exact value from the OAuth Providers admin page
   (`https://<your-host>/oauth_callback`).
4. Copy the **client ID** and **client secret** into the admin page (Linode
   shows the secret once).

Linode access tokens expire in two hours and the code grant includes **no
refresh token** (the token response carries `refresh_token: null`). A stored
Linode grant is a two-hour credential: `ensureFresh()` throws once it expires,
and the consumer must send the user back through consent. No extra authorize
params are needed.

## Scope minimization

Consumers request only the scope they need. State plainly what each scope grants
when documenting a new consumer.

## Out of scope

- **Token use** — formatting XOAUTH2, calling Gmail/Graph: the consumer's
  responsibility. The core hands back a valid `OAuth2Token`. (Establishing
  *identity* is the exception — it is a provider declaration plus one client call,
  because otherwise every consumer that needs it builds the same lookup again. See
  [Identity: who signed in](#identity-who-signed-in).)
- **PKCE / public clients** — all current consumers are confidential server-side
  clients. PKCE can be added to `OAuth2Client` later without changing the
  provider/consumer seams.
- **Auto-provisioning cloud apps** — the admin registers the app once and pastes
  credentials; the platform never creates cloud apps.
