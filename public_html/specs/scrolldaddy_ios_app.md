# ScrollDaddy iPhone App — Feasibility & Spec

An iOS app that lets a user run ScrollDaddy entirely from their phone: sign
in, manage their subscription, edit their filters, have the DNS configuration
applied to the device automatically — no website login, no profile download,
no copy/paste — and hard-block selected sites at the connection level so they
won't load in any browser or app, even past DNS.

**This app is a consumer of the Joinery iOS app platform**
(`specs/ios_app_platform.md`). Everything account- and shell-shaped — native
login, password reset, account forms, server-driven navigation, settings,
authenticated webviews of `/profile` pages, the 426 upgrade gate — comes from
JoineryKit and the platform's server pieces, and is specified there. This
spec covers only what is ScrollDaddy-specific: the DNS layers, the native
filter editor, branding, and billing phasing. The DNS layer is brand-neutral
(the resolver already serves multiple deployments — a future NetworkSentry
app reuses everything but the branding).

## Feasibility findings

### Automatic local config — feasible, and simpler than requested

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
  save and no restore code to write; the deliverable here is an
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

### Hard local blocking ("this site won't load") — feasible via local packet tunnel

iOS mechanisms considered, decided once:

| Mechanism | Verdict |
|---|---|
| `NEFilterDataProvider` (Apple's content filter) | Unavailable — iOS restricts it to supervised/MDM devices |
| Safari Content Blocker extension | Safari-only; Chrome, webviews, and native apps bypass it |
| Screen Time API (FamilyControls) | Shields whole apps + Safari domains; needs Apple-granted entitlement; this is the tamper-resistance track (future spec, see Out of scope) |
| `NEPacketTunnelProvider` (local VPN, never leaves device) | **Chosen.** Connection-level blocking in every browser and app; precedent: Lockdown, AdGuard full protection |

**Design — two protection modes managed by the app:**

- **Standard mode** = DoH via `NEDNSSettingsManager` (above).
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
dialog (Face ID / passcode). The "VPN" badge appears in the status bar;
onboarding explains traffic never leaves the device.

**Costs and caveats (inherent, documented in-app, not worked around):**

- This is the heaviest engineering component of the app: a userspace
  packet-processing Network Extension under a hard ~50MB memory ceiling.
- One VPN at a time — strict mode conflicts with Tailscale, corporate, or
  privacy VPNs. The app detects an existing VPN and explains the trade.
- Modest battery overhead from packet processing.
- Same paid-developer-program entitlement family as standard mode (personal
  VPN + packet tunnel are standard Network Extension capabilities, no special
  Apple approval needed — unlike FamilyControls).

### Filter management — server side complete, app work only

The full filter surface is exposed as API actions under the
`dns_filtering/` namespace — devices (with DoH/DoT endpoints per device),
blocks, custom rules, catalog, account summary, query log, domain/URL
testing — documented in `plugins/dns_filtering/docs/overview.md` § API
Surface. The actions call the same logic functions as the web editor, so
tier gating, validation, and save semantics live in one place. The native
editor work is building the SwiftUI screens that mirror the web editor. No
new policy model.

The platform's webview layer also means the existing
`/profile/dns_filtering/*` pages are usable in-app from day one, which is
what lets the App Store release ship before the native editor (see phases).

## Architecture

Two ScrollDaddy-specific Swift packages plus the app shell, on top of
JoineryKit (from `specs/ios_app_platform.md`), developed on the Mac mini per
`specs/mac_mini_ios_development_access.md` (repo: `~/dev/scrolldaddy-ios`):

| Layer | Package | Reusable for | Contents |
|---|---|---|---|
| Core | **JoineryKit** | any app on any Joinery deployment | Specified and delivered by `specs/ios_app_platform.md` |
| DNS filtering | **DNSFilterKit** | any ScrollDaddy-style deployment (e.g. NetworkSentry) | Device list/registration, native block editor (always-on + scheduled), category/service/custom-rule screens with server-driven tier gates, `NEDNSSettingsManager` activation flow, protection-mode control |
| Hard blocking | **Packet tunnel extension** (app extension target, shared via DNSFilterKit) | same as DNSFilterKit | `NEPacketTunnelProvider`: in-tunnel DNS forwarding to the deployment's DoH resolver, SNI/IP connection blocking from the synced hard-block list |
| Brand | **ScrollDaddy app target** | — | Bundle ID, branding/theme, deployment base URL, `client_app` id (`scrolldaddy-ios`), App Store assets |

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

None beyond the platform spec's three pieces (navigation endpoint,
web-session bridge, app display mode — `specs/ios_app_platform.md`). The
ScrollDaddy-specific surface is in place: the full `dns_filtering/` action
surface including the `sbr_hard_block` column and the merged hard-block
hostname list in device responses (`docs/api.md`,
`plugins/dns_filtering/docs/overview.md` § API Surface). In-app billing —
server and client — is its own spec, `specs/mobile_app_billing.md`,
consumed post-launch.

## Billing strategy (the one real constraint)

Apple's rules for digital subscriptions:

1. **At launch — login-only.** Accounts are created on the website (free or
   paid); the app signs users in (JoineryKit's registration toggle stays
   off), displays subscription status, and every tier gets its full function
   (category blocking on the always-on block — the free-tier floor stays
   intact). No purchase and no registration inside the app (login-only
   pattern: NextDNS, Netflix). Because the app offers no account creation,
   Apple's in-app account-deletion requirement is not triggered.
2. **Later phase — registration on.** Flipping JoineryKit's registration
   toggle triggers Apple's account-deletion requirement, so in-app deletion
   ships in the same release.
3. **Later phase — StoreKit 2 IAP.** In-app subscribe/upgrade/downgrade via
   Apple, per `specs/mobile_app_billing.md`. Independent of the registration
   phase and of strict mode.

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

**Phase 0 (dependency): the platform ships first** — JoineryKit and the
server pieces per `specs/ios_app_platform.md`, through its own gates.

The ScrollDaddy phases are strictly sequential after that; each gate re-runs
earlier suites as regression. Phase 1 runs in the iOS Simulator against
`dev.getjoinery.com`; Phases 2 and 4 require a **physical iPhone**, because
Network Extensions (DNS settings and packet tunnels) do not run in the
Simulator.

### Phase 1 — Branded shell (webview filters)

The ScrollDaddy app target on JoineryKit: branding, base URL, navigation
tabs pinned for ScrollDaddy (server-side `app_navigation` entry for
`scrolldaddy-ios`), with the filter editor served as webviews of the
existing `/profile/dns_filtering/*` pages. Full product function, no
DNS-specific native code yet.

**Gate:** XCUITest in the Simulator — log in with a website-created account;
edit a block in the in-app webview and see it in the web editor and vice
versa; tier gates render locked; platform regression suite passes under
ScrollDaddy branding.

### Phase 2 — Automatic DNS (first App Store release)

DNSFilterKit's device registration and `NEDNSSettingsManager` activation:
guided enable step, protected-status verification, disable/uninstall flow.
Billing: login-only model. App Store submission closes this phase.

**Gate:** on a physical iPhone — full onboarding from install to "Protected"
with zero copy/paste; a blocked category fails to resolve; disable and
app-deletion both restore normal DNS immediately; VPN-active and
network-switch edge cases behave as documented. Then TestFlight, then review.

### Phase 3 — Native filter editor

DNSFilterKit's SwiftUI editor screens — always-on editor, scheduled blocks,
custom rules, server-driven tier gates — flipping the navigation routes from
the webview pages to native, one route at a time (the platform's
version-skew rule keeps older shipped versions on the webviews).

**Gate:** XCUITest — edits made natively appear in the web editor and vice
versa; tier gates server-rejected and rendered locked; "Allow = no row"
semantics verified at the database level; resolver picks up an app-made
change within its reload window.

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
- Android app (`specs/scrolldaddy_android_app.md`; the platform's server
  pieces are what make it cheap).
- Applying config to non-iOS devices from the app.

## Acceptance checklist

1. A user with a website-created account can log in, enable filtering, and
   confirm a blocked category fails to resolve — with no further website
   visits after signup.
2. Filter edits in the app are visible in the web editor and vice versa
   (single source of truth; resolver picks changes up within its ~60s reload).
3. Disabling in-app and uninstalling the app both restore normal DNS
   resolution immediately.
4. Tier gates: a free user cannot save a custom rule or exceed device limits
   via the API (server-rejected), and the app renders the locked states.
   Hard-block flags are rejected for users without `scrolldaddy_custom_rules`.
5. Strict mode: a hard-blocked site fails to load in Safari **and** in a
   third-party browser configured with its own DoH (DNS bypass), while
   non-blocked sites load normally; disabling strict mode falls back to
   standard DNS protection, not to unprotected.
6. DNSFilterKit builds with no ScrollDaddy imports and works against a
   second ScrollDaddy-style deployment unchanged (branding aside).

## Documentation deliverables (on implementation)

- `plugins/dns_filtering/docs/overview.md` — the app as a config delivery
  channel alongside mobileconfig.
- `docs/mobile_apps.md` (owned by the platform spec) — add ScrollDaddy as a
  consuming app with its DNSFilterKit layers.
