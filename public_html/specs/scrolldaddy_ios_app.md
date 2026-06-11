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

### Part 1 (reusable Joinery account module) — server side complete, app work only

The platform's action API exposes every account flow the app will ever need
**session-less or sessioned**: `register`, `password_reset_1`/`password_reset_2`,
`password_edit`, `account_edit`, `contact_preferences`, `change_tier`.
Authentication is self-provisioned session keys: `auth/login` with
email/password mints a session-type API key pair, and the app authenticates
with the standard key headers (see `docs/api.md`). The account forms are
served as JSON definitions (`GET /api/v1/form/{action}`), so when in-app
account management ships (post-launch — see "Billing strategy"), JoineryKit
adds one generic form renderer instead of a screen per form, and form
changes never require an app release. At launch the module is login-only.

Billing is the one genuinely constrained area: **Apple requires In-App Purchase
for digital subscriptions sold inside an app.** See "Billing strategy" below —
phased, with IAP as the end state.

### Part 2 (filter management) — server side complete, app work only

The full filter surface is exposed as API actions under the
`dns_filtering/` namespace — devices (with DoH/DoT endpoints per device),
blocks, custom rules, catalog, account summary, query log, domain/URL
testing — documented in `plugins/dns_filtering/docs/overview.md` § API
Surface. The actions call the same logic functions as the web editor, so
tier gating, validation, and save semantics live in one place. The work is
building the SwiftUI screens that mirror the web editor. No new policy
model.

## Architecture

Three Swift packages plus the app shell, developed on the Mac mini per
`specs/mac_mini_ios_development_access.md` (repo: `~/dev/scrolldaddy-ios`):

| Layer | Package | Reusable for | Contents |
|---|---|---|---|
| Account | **JoineryKit** | any app on any Joinery deployment | API client (base URL + branding injected), session-key auth + Keychain storage, native login screen, subscription status screen. Post-launch additions: **one generic server-driven form renderer** (schema in `docs/formwriter.md` § JSON output mode) driving register, forgot/reset password, account edit, and contact preferences from server JSON definitions; billing entry point |
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

## Server-side work

None. The API surface the app consumes is in place: session-key auth
(`auth/login`/`auth/session`/`auth/logout`, revocation on password change),
server-driven account forms, and the full `dns_filtering/` action surface
including the `sbr_hard_block` column and the merged hard-block hostname
list in device responses (`docs/api.md`,
`plugins/dns_filtering/docs/overview.md` § API Surface). In-app billing —
server and client — is its own spec, `specs/mobile_app_billing.md`,
consumed post-launch.

## Billing strategy (the one real constraint)

Apple's rules for digital subscriptions:

1. **At launch — login-only.** Accounts are created on the website (free or
   paid); the app signs users in, displays subscription status, and every
   tier gets its full function (category blocking on the always-on block —
   the free-tier floor stays intact). No purchase and no registration inside
   the app (login-only pattern: NextDNS, Netflix). Because the app offers no
   account creation, Apple's in-app account-deletion requirement is not
   triggered. This ships the full filter + DNS experience with zero IAP work
   and no account screens beyond login.
2. **Later phase — in-app account management.** Registration, password
   reset, account edit, and contact preferences via the generic server-driven
   form renderer (the endpoints exist; this is client work). Adding
   registration triggers Apple's account-deletion requirement, so in-app
   deletion ships in the same phase.
3. **Later phase — StoreKit 2 IAP.** In-app subscribe/upgrade/downgrade via
   Apple, per `specs/mobile_app_billing.md`. Independent of the
   account-management phase and of Phase 4.

## App flows

**Onboarding (the whole point — minutes, zero copy/paste):**
1. Sign up on the website (free or paid), download the app, log in
   (JoineryKit login screen).
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

### Phase 1 — Account (JoineryKit, login-only)

App work against the existing auth endpoints: JoineryKit package with a
native login screen, Keychain key storage, and a subscription status
screen. Accounts are created on the website; a "Forgot password?" link
opens the website's reset page in the browser. The generic server-driven
form renderer and the in-app account screens it drives (registration,
password reset, account edit, contact preferences) are the post-launch
account-management phase — see "Billing strategy." JoineryKit sends the
`client-app`/`client-version` headers on every request (hyphen form —
underscore header names are dropped by proxy_fcgi stacks; see the client
headers section of `docs/api.md`) and renders any 426 `UpgradeRequired`
response as a blocking upgrade screen with an App Store deep link.
ScrollDaddy app target exists but is a thin shell.

**Gate:** XCUITest suite in the Simulator — log in/out with a
website-created account, session keys revoked on password change,
invalid-credential and rate-limit paths render correctly. Server-side: the
auth endpoints are covered in `/tests/` by their own specs.

### Phase 2 — Filters (editor UI)

App work against the existing `dns_filtering/` actions: DNSFilterKit
screens — device registration, always-on editor, scheduled blocks, custom
rules, server-driven tier gates.

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

App work against the existing hard-block surface (`block_rule_add`'s
`hard_block` flag and the `hard_block_hostnames` list in device responses):
packet tunnel extension, protection-level control, VPN-conflict detection.
Nothing in Phases 1–3 depends on this phase; it can land in any later
release without touching the earlier surfaces.

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

1. A user with a website-created account can log in, enable filtering, and
   confirm a blocked category fails to resolve — with no further website
   visits after signup.
2. The login screen's "Forgot password?" link opens the website's reset
   page, and the app signs in with the new password afterward (session keys
   from the old password are revoked).
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

- `plugins/dns_filtering/docs/overview.md` — the app as a config delivery
  channel alongside mobileconfig.
- New `docs/mobile_apps.md` — JoineryKit integration guide for future apps
  (repo location, configuration surface, screens provided).
