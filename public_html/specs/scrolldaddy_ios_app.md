# ScrollDaddy iPhone App — Feasibility & Spec

An iOS app that lets a user run ScrollDaddy entirely from their phone: sign up,
manage their subscription, edit their filters, have the DNS configuration
applied to the device automatically — no website login, no profile download, no
copy/paste — and hard-block selected sites at the connection level so they
won't load in any browser or app, even past DNS.

The app is structured so the account layer is a **general, reusable Joinery
module** usable by any future app built on any Joinery deployment, and the DNS
layer is **brand-neutral** (the resolver already serves multiple deployments —
a future NetworkSentry app reuses everything but the branding).

## Feasibility findings

### Part 3 (automatic local config) — feasible, and simpler than requested

iOS 14+ provides `NEDNSSettingsManager`, the native API for apps to install a
system-wide **encrypted DNS** configuration (DoH or DoT). This is how NextDNS,
AdGuard, and Cloudflare ship their iOS apps, so App Store approval is
well-trodden ground.

- The resolver **already serves DoH and DoT** (`internal/doh/`, `internal/dot/`
  in `/home/user1/scrolldaddy-dns/`) with per-device identification baked into
  the URL: `https://{dns_host}/resolve/{sdd_resolver_uid}` — the exact format
  the existing `.mobileconfig` generator emits (`logic/mobileconfig_logic.php:32`).
  **No resolver changes are needed.** The app saves that URL as a DoH
  configuration and the device is filtered.
- **One unavoidable manual step:** after the app saves the configuration, iOS
  requires the user to enable it once in Settings → General → VPN, DNS & Device
  Management → DNS. This is an OS security gate no app can bypass; the app
  detects the state (`isEnabled`) and shows a guided "tap here, flip this"
  onboarding step with a deep link into Settings. After that one tap, every
  subsequent change (new UID, server change) applies silently. No downloads, no
  copy/paste — requirement met.
- **Saving the old config is unnecessary — iOS does it structurally.** The DoH
  configuration *layers over* the network's DNS rather than replacing it. Apps
  cannot read or modify the underlying network DNS settings at all (sandbox).
  Disabling the configuration, removing it, or uninstalling the app reverts the
  device to its prior DNS automatically and losslessly. There is nothing to
  save and no restore code to write; the spec's deliverable here is an
  uninstall/disable flow in the app that calls `removeFromPreferences` and
  tells the user their original settings are back.
- **Entitlement requirement:** the DNS Settings capability
  (`com.apple.developer.networking.dns-settings`) requires a **paid Apple
  Developer Program membership** ($99/yr) — Network Extension entitlements are
  not available to free personal teams. This is needed for App Store
  distribution anyway; it just means the paid account is a prerequisite for
  this app even in development.

Known platform caveats (inherent to iOS, same for every DNS app — documented in
onboarding, not worked around): an active VPN that does its own DNS takes
precedence; the user can disable the configuration in Settings at any time.
Tamper-resistance for recovery users (Screen Time API / supervised devices) is
a future spec, not this one.

### Part 4 (hard local blocking — "this site won't load") — feasible via local packet tunnel

iOS mechanisms considered, decided once:

| Mechanism | Verdict |
|---|---|
| `NEFilterDataProvider` (Apple's content filter) | Unavailable — iOS restricts it to supervised/MDM devices |
| Safari Content Blocker extension | Safari-only; Chrome, webviews, and native apps bypass it |
| Screen Time API (FamilyControls) | Shields whole apps + Safari domains; needs Apple-granted entitlement; this is the tamper-resistance track (future spec, see Out of scope) |
| `NEPacketTunnelProvider` (local VPN, never leaves device) | **Chosen.** Connection-level blocking in every browser and app; precedent: Lockdown, AdGuard full protection |

**Design — two protection modes managed by the app:**

- **Standard mode** = Part 3 as specified (DoH via `NEDNSSettingsManager`).
- **Strict mode** = an on-device `NEPacketTunnelProvider`. All traffic enters a
  local tunnel that (a) answers DNS by forwarding to the ScrollDaddy DoH
  resolver with the device UID — policy stays server-side, unchanged — and
  (b) enforces the device's **hard-block list** by dropping connections whose
  TLS SNI (or destination IP, for non-TLS) matches a blocked hostname. SNI
  enforcement is what makes this "not just DNS": an app that bypasses DNS with
  its own hardcoded DoH still can't complete a connection. In-tunnel DNS also
  strips HTTPS/SVCB records so Encrypted Client Hello can't hide the SNI —
  the standard countermeasure commercial filters use.
- The two modes are mutually exclusive at the OS level (an active VPN
  supersedes installed DNS settings); the app presents one "protection level"
  control and handles the switch.

**Consent:** enabling strict mode shows the standard in-app iOS VPN permission
dialog (Face ID / passcode) — simpler than Part 3's Settings trip. The
"VPN" badge appears in the status bar; onboarding explains traffic never
leaves the device.

**Costs and caveats (inherent, documented in-app, not worked around):**

- This is the heaviest engineering component of the app: a userspace
  packet-processing Network Extension under a hard ~50MB memory ceiling.
- One VPN at a time — strict mode conflicts with Tailscale, corporate, or
  privacy VPNs. The app detects an existing VPN and explains the trade.
- Modest battery overhead from packet processing.
- Same paid-developer-program entitlement family as Part 3 (personal VPN +
  packet tunnel are standard Network Extension capabilities, no special
  Apple approval needed — unlike FamilyControls).

### Part 1 (reusable Joinery account module) — feasible with one server-side gap

The platform's action API already exposes every account flow the app needs
**session-less or sessioned**: `register`, `password_reset_1`/`password_reset_2`,
`password_edit`, `account_edit`, `contact_preferences`, `change_tier`. The gap
is **authentication**: API keys are admin-provisioned — a phone app cannot
ship one. The server needs **self-provisioned session keys** (login with
email/password → key pair). This is core platform work, reusable by every
future app — see "Server-side work."

Billing is the one genuinely constrained area: **Apple requires In-App Purchase
for digital subscriptions sold inside an app.** See "Billing strategy" below —
phased, with IAP as the end state.

### Part 2 (filter management) — feasible, pure API-surface work

All filter business logic (block model, tier gating, validation) already exists
in the plugin's logic layer. The work is exposing it as API actions and
building the SwiftUI screens that mirror the web editor. No new policy model.

## Architecture

Three Swift packages plus the app shell, developed on the Mac mini per
`specs/mac_mini_ios_development_access.md` (repo: `~/dev/scrolldaddy-ios`):

| Layer | Package | Reusable for | Contents |
|---|---|---|---|
| Account | **JoineryKit** | any app on any Joinery deployment | API client (base URL + branding injected), session-key auth + Keychain storage, native login screen, **one generic server-driven form renderer** (per `specs/formwriter_json_forms.md`) that renders register, forgot/reset password, account edit, and contact preferences from server JSON definitions, subscription status screen, billing entry point |
| DNS filtering | **DNSFilterKit** | any ScrollDaddy-style deployment (e.g. NetworkSentry) | Device list/registration, block editor (always-on + scheduled), category/service/custom-rule screens with server-driven tier gates, `NEDNSSettingsManager` activation flow, protection-mode control |
| Hard blocking | **Packet tunnel extension** (app extension target, shared via DNSFilterKit) | same as DNSFilterKit | `NEPacketTunnelProvider`: in-tunnel DNS forwarding to the deployment's DoH resolver, SNI/IP connection blocking from the synced hard-block list |
| Brand | **ScrollDaddy app target** | — | Bundle ID, branding/theme, deployment base URL, App Store assets |

Design rules carried over from the web product:

- **Tier gating is server-enforced.** The API returns the user's feature flags
  (`scrolldaddy_max_devices`, `scrolldaddy_custom_rules`, etc.) with relevant
  responses; the client renders locked/upsell states from those flags but the
  server rejects gated writes regardless.
- **"Allow" on the always-on block means "no row"** — the app submits the same
  semantics as the web editor (see `plugins/dns_filtering/docs/overview.md`,
  Editor UI). The API actions reuse the existing logic functions precisely so
  this invariant lives in one place.

## Server-side work (all in this repo)

### 1. User session keys (core platform — prerequisite spec)

Specified separately in **`specs/user_session_api_keys.md`** and implemented
before this spec: `auth/login` mints a session-type API key pair in the
existing `apk_api_keys` table; the app then authenticates with the standard
key headers through the unchanged `apiv1.php` flow. Includes
`auth/session`/`auth/logout`, revocation on password change, and a profile
sessions view. Phase 1 consumes that surface; it adds no auth work of its
own.

Also implemented before this spec: **server-driven forms**
(`specs/formwriter_json_forms.md`) — the account forms (register,
password reset, account edit, contact preferences) are served as JSON
definitions, so JoineryKit ships one generic form renderer instead of a
screen per form, and form changes never require an app release.

### 2. ScrollDaddy API actions (plugin)

Expose the existing logic via `_api()` opt-ins, adding thin logic functions
where today's surface is AJAX-only:

- Devices: list, create (auto-creates always-on block), rename, delete; each
  device response includes its DoH URL (`https://{dns_host}/resolve/{uid}`).
- Blocks: list per device, read one (filters + services + rules + schedule),
  save (same submit semantics as `scheduled_block_edit_logic.php`).
- Custom rules: add/delete (today `ajax/block_rule_add.php` / `block_rule_delete.php`
  — convert to logic functions the web AJAX endpoints and the API both call).
- Catalog: filter categories + services list from `ScrollDaddyHelper`
  (so the app never hardcodes the category list), with advanced-filter
  keys flagged for tier gating.
- Account summary: tier, feature flags, device count vs. max.
- **Hard-block flag:** add `sbr_hard_block` (boolean, default false) to
  `sbr_scheduled_block_rules` via the plugin's `$field_specifications`
  (schema syncs automatically). A block-action custom rule with the flag set
  is additionally enforced at connection level by the tunnel; the device API
  responses include the merged hard-block hostname list so the app syncs it
  into the tunnel extension. The flag rides the existing custom-rules tier
  gate (`scrolldaddy_custom_rules`). The resolver ignores the column —
  DNS-level behavior is unchanged.

### 3. Apple IAP integration (core platform — post-launch)

A payment integration parallel to `StripeHelper`:

- `AppStoreHelper` validating StoreKit 2 transactions via the App Store Server
  API; webhook endpoint in `/ajax/` for App Store Server Notifications V2
  (renewals, cancellations, refunds) — same role Stripe webhooks play today.
- Product-ID → subscription-tier mapping (admin-configured), driving the same
  tier assignment path `change_tier` uses.
- Reconciliation rule: a user has one subscription source (Stripe **or** App
  Store); the server records the source and routes manage/cancel accordingly.

## Billing strategy (the one real constraint)

Apple's rules for digital subscriptions:

1. **At launch — account-exists model.** The app signs users in, displays
   subscription status, and free-tier users get full free-tier function
   (category blocking on the always-on block — the free-tier floor stays
   intact). Paid-tier purchase is *not offered inside the app*; in the US
   storefront an external purchase link to the website is permitted, elsewhere
   the app simply doesn't sell (reader-app pattern: NextDNS, Netflix). This
   ships the full filter + DNS experience with zero IAP work.
2. **Post-launch — StoreKit 2 IAP.** In-app subscribe/upgrade/downgrade via
   Apple, reconciled server-side per "Apple IAP integration" above. Apple
   takes 15–30%; price the IAP tiers accordingly (admin price per product ID).
   This is its own work item, schedulable any time after the Phase 3 release,
   independent of Phase 4.

## App flows

**Onboarding (the whole point — minutes, zero copy/paste):**
1. Launch → JoineryKit login/register screen (register hits the existing
   `register` action; new users land on the free tier).
2. App registers this phone as a device (`devices` action; server returns the
   DoH URL).
3. App saves the DoH configuration via `NEDNSSettingsManager`, then shows the
   guided one-tap enable step (deep link to Settings, live status check).
4. Status screen confirms "Protected" (the app verifies by checking
   `isEnabled` and resolving a known test hostname through the configured DNS).
5. User lands on the always-on block editor — same Block/Allow categories as
   the web.

**Daily use:** edit categories/services/custom rules per tier; add scheduled
blocks; switch devices (a user's *other* devices are still managed via
mobileconfig/manual setup from the web — this app applies config only to the
phone it runs on).

**Strict mode:** a protection-level control offers Standard (DNS) or Strict
(tunnel). Switching to Strict shows the iOS VPN consent dialog, marks selected
custom rules as hard blocks, and syncs the hard-block list to the tunnel on
every policy change. If another VPN is active, the app explains the conflict
instead of silently failing.

**Uninstall/disable:** an in-app "Turn off ScrollDaddy" action removes the DNS
configuration (`removeFromPreferences`) and explains that deleting the app does
the same thing automatically; either way iOS reverts to the network's original
DNS with no residue.

## Delivery phases & test gates

Four phases, strictly sequential, each ending in a test gate that must pass
before the next phase starts. Test suites accumulate: every later gate re-runs
the earlier phases' suites as regression. Phases 1–2 are fully testable in the
iOS Simulator against `dev.getjoinery.com`; Phases 3–4 require a **physical
iPhone**, because Network Extensions (DNS settings and packet tunnels) do not
run in the Simulator.

### Phase 1 — Account (JoineryKit + server session-key auth)

Server: session-key auth and server-driven forms already in place per
`specs/user_session_api_keys.md` and `specs/formwriter_json_forms.md`
(implemented before this spec). App: JoineryKit package with a native login
screen, the generic form renderer driving register, forgot/reset password,
account edit, and contact preferences from server definitions, a
subscription status screen, and Keychain key storage. JoineryKit sends the
`client_app`/`client_version` headers on every request and renders any 426
`UpgradeRequired` response as a blocking upgrade screen with an App Store
deep link (per `specs/user_session_api_keys.md`). ScrollDaddy app
target exists but is a thin shell.

**Gate:** XCUITest suite in the Simulator — register a new user, log in/out,
password-reset round-trip via the reset email, account edit persists,
session keys revoked on password change, invalid-credential and rate-limit
paths render correctly. Server-side: the auth and form endpoints are covered
in `/tests/` by their own specs.

### Phase 2 — Filters (ScrollDaddy API actions + editor UI)

Server: plugin API actions (devices, blocks, rules, catalog, account summary).
App: DNSFilterKit screens — device registration, always-on editor, scheduled
blocks, custom rules, server-driven tier gates.

**Gate:** XCUITest in the Simulator — edits made in the app appear in the web
editor and vice versa; tier gates server-rejected and rendered locked;
"Allow = no row" semantics verified at the database level; resolver picks up
an app-made change within its reload window.

### Phase 3 — Automatic DNS (first App Store release)

App: `NEDNSSettingsManager` activation with guided enable step, protected
status verification, disable/uninstall flow. Billing: account-exists model.
App Store submission closes this phase.

**Gate:** on a physical iPhone — full onboarding from install to "Protected"
with zero copy/paste; a blocked category fails to resolve; disable and
app-deletion both restore normal DNS immediately; VPN-active and
network-switch edge cases behave as documented. Then TestFlight, then review.

### Phase 4 — Strict mode (may ship later)

Server: `sbr_hard_block` column + hard-block list in device API responses.
App: packet tunnel extension, protection-level control, VPN-conflict
detection. Nothing in Phases 1–3 depends on this phase; it can land in any
later release without touching the earlier surfaces.

**Gate:** on a physical iPhone — a hard-blocked site fails in Safari and in a
third-party browser using its own DoH (DNS bypass); non-blocked sites load
normally; disabling strict mode falls back to standard DNS protection;
tunnel memory stays under the extension ceiling under sustained browsing.

## Out of scope (future specs)

- Tamper-resistance / accountability-partner locking (Screen Time API,
  supervised mode).
- Push notifications (e.g., "schedule started") and query-log viewing in-app.
- Android app (the server-side work in this spec is what makes it cheap later).
- Applying config to non-iOS devices from the app.

## Acceptance checklist

1. A new user can register, subscribe (Phase 1: free tier; Phase 2: paid via
   IAP), enable filtering, and confirm a blocked category fails to resolve —
   entirely on the phone, without visiting the website.
2. Password reset round-trips through the existing reset email from inside the
   app.
3. Filter edits in the app are visible in the web editor and vice versa
   (single source of truth; resolver picks changes up within its ~60s reload).
4. Disabling in-app and uninstalling the app both restore normal DNS
   resolution immediately.
5. Tier gates: a free user cannot save a custom rule or exceed device limits
   via the API (server-rejected), and the app renders the locked states.
   Hard-block flags are rejected for users without `scrolldaddy_custom_rules`.
6. Strict mode: a hard-blocked site fails to load in Safari **and** in a
   third-party browser configured with its own DoH (DNS bypass), while
   non-blocked sites load normally; disabling strict mode falls back to
   standard DNS protection, not to unprotected.
7. JoineryKit builds as a standalone package with no ScrollDaddy imports, and
   session-key auth works against a second Joinery deployment unchanged.

## Documentation deliverables (on implementation)

- `docs/api.md` — auth additions are covered by
  `specs/user_session_api_keys.md`'s deliverables.
- `plugins/dns_filtering/docs/overview.md` — API actions; the app as a config
  delivery channel alongside mobileconfig.
- New `docs/mobile_apps.md` — JoineryKit integration guide for future apps
  (repo location, configuration surface, screens provided).
