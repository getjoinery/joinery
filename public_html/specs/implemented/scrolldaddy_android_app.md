# ScrollDaddy Android App — Feasibility & Spec

The Android counterpart to `specs/scrolldaddy_ios_app.md`: sign in, manage
the subscription, edit filters, apply the DNS configuration to the device
automatically with safe revert, and hard-block selected sites at the
connection level.

**This app is a consumer of the Joinery Android app platform**
(`specs/implemented/android_app_platform.md`). Everything account- and shell-shaped —
native login, password reset, account forms, server-driven navigation,
settings, authenticated webviews of `/profile` pages, the 426 upgrade gate —
comes from `joinery-android` and the platform's server pieces, and is
specified there. This spec covers only what is ScrollDaddy-specific: the
VpnService DNS layer, the native filter editor, branding, and billing
phasing. The DNS layer is brand-neutral (a future NetworkSentry app reuses
everything but the branding).

## Feasibility findings — where Android differs from iOS

Android is the *easier* platform for every DNS part. The differences:

### Standard and strict mode collapse into one mechanism

Android's `VpnService` is the standard, Play-Store-accepted way to filter
traffic locally (Blokada, AdGuard, NextDNS all use it), and it covers both
the DNS part and the hard-blocking part:

- **Standard mode** — the tunnel claims only DNS traffic (routes just the
  tunnel's virtual DNS address) and forwards queries to the ScrollDaddy DoH
  resolver with the device UID. Equivalent to iOS's `NEDNSSettingsManager`
  path, same server-side policy, no resolver changes.
- **Strict mode** — the tunnel claims all traffic and additionally drops
  connections by TLS SNI / destination IP from the synced hard-block list,
  stripping HTTPS/SVCB records in-tunnel so Encrypted Client Hello can't hide
  the SNI. Equivalent to iOS's packet tunnel — but it's a mode switch inside
  one service, not a second mechanism.

**Consent is a single in-app dialog** (the system VPN prompt) — no trip to
Settings at all, which beats iOS's one-time Settings step.

**Revert is structural**, same as iOS: the tunnel layers over the device's
network configuration, which apps cannot read or modify; stopping the service
or uninstalling the app restores original behavior automatically. Nothing to
save, nothing to restore.

**Native tamper-resistance bonus:** Android offers **always-on VPN** with
**lockdown** ("Block connections without VPN") as user-set system toggles. The
app deep-links to the toggle and explains it; for ScrollDaddy's recovery
audience this is real friction-to-disable that iOS cannot offer without the
FamilyControls entitlement. Guidance only — the app doesn't (and can't)
enforce it.

**Alternative no-VPN path — Private DNS (DoT).** Android 9+ has a system-wide
encrypted-DNS setting, and the resolver already identifies devices by DoT SNI
subdomain (`{uid}.{base_domain}`, `internal/dot/server.go:19`). But apps
cannot set Private DNS programmatically — the user must type the hostname in
Settings, which violates the zero-copy/paste requirement. Position: documented
in-app as an opt-in for users who need their VPN slot for something else
(Tailscale, work VPN); the headline path is the VpnService.

Costs and caveats: one VPN at a time (same trade as iOS, mitigated by the
Private DNS alternative); a persistent foreground-service notification while
filtering (Android requirement); OEM battery managers can kill background
services — onboarding requests a battery-optimization exemption and the
service auto-restarts (`START_STICKY`, boot receiver when always-on isn't set).

### Filter management — server side complete, app work only

The same `dns_filtering/` API actions the iOS app consumes
(`plugins/dns_filtering/docs/overview.md` § API Surface); Compose screens
mirroring the iOS editor, same server-driven tier gates, same "Allow = no
row" submit semantics living server-side in the shared logic functions.

The iOS build delivered the whole native surface
(`ios/joinery-kit/Sources/JoineryDNSFilterKit`) — port it 1:1 rather than
re-deriving it: models over the API shapes (`DNSFilterModels.swift`), the
typed action client (`DNSFilterAPI.swift`), the stores
(`ProtectionStore`/`DeviceListStore`/`BlockEditorStore`), the screens
(`ProtectionScreen`/`DevicesScreen`/`AlwaysOnEditorScreen`), and the
unit-tested hard-block engine (`TunnelHardBlock.swift`: `HardBlockList`
subdomain matching + `TLSClientHello` SNI parsing, with its test suite in
`Tests/JoineryDNSFilterKitTests`). **Port the fixes from § Executor
guardrails below, not the defects.**

The platform's webview layer also means the existing
`/profile/dns_filtering/*` pages are usable in-app from day one, which is
what lets the Play Store release ship before the native editor (see phases).

## Architecture

One ScrollDaddy-specific module plus the app shell, on top of
`joinery-android` (from `specs/implemented/android_app_platform.md`).
Android source lives in the main repo's `android/` Gradle workspace:
`joinery-android` core, feature modules named `joinery-android-{feature}`
(member, mail, calendar, ai-chat), and thin per-brand app modules
(`joinery-member-android` is the reference). The DNS layer is a new
`joinery-android-dnsfilter` feature module; the ScrollDaddy app module is
`scrolldaddy-android` in the same workspace. Builds run on the Mac mini over
SSH (rsync'd build area, AVD `joinery_test` — see Claude memory
`reference_mac_mini_android`); the workspace in the main repo is the source
of truth.

| Layer | Module | Reusable for | Contents |
|---|---|---|---|
| Core | **`joinery-android`** | any app on any Joinery deployment | Specified and delivered by `specs/implemented/android_app_platform.md` |
| DNS filtering | **`joinery-android-dnsfilter`** | any ScrollDaddy-style deployment (e.g. NetworkSentry) | Device registration, native block editor screens (always-on + scheduled), category/service/custom-rule screens with server-driven tier gates, the `VpnService` (standard + strict modes), protection-level control |
| Brand | **`scrolldaddy-android` app module** | — | Application ID, theme, deployment base URL, `client_app` id (`scrolldaddy-android`), Play Store assets |

Integration points, all delivered: the app module mirrors
`joinery-member-android`'s `MainActivity` (a `JoineryConfig` with
`clientApp = "scrolldaddy-android"`, `registrationEnabled = false`, and the
test-override intent extras) and registers the DNS screens at launch via
`NativeScreenRegistry.register(...)` (`joinery-android`'s
`NativeScreenRegistry.kt`), exactly as `joinery-android-member`'s
`registerScreens()` does. **Screen names are already live server-side** —
the dns_filtering `profileMenu` entries declare `nativeScreen:
"dns_protection"` and `"dns_devices"` (plugin.json 1.1.2, shipped with the
iOS app) and the navigation endpoint is client-agnostic, so Android must
register those exact names; unknown names fall back to the
`/profile/dns_filtering/*` webviews automatically.

Design rules carried over from the web product:

- **Tier gating is server-enforced.** The client renders locked/upsell states
  from the feature flags in API responses; the server rejects gated writes
  regardless.
- **"Allow" on the always-on block means "no row"** — the app submits the
  same semantics as the web editor; the API actions reuse the existing logic
  functions precisely so this invariant lives in one place.

## Server-side work

The `dns_filtering/` action surface, the `nativeScreen` menu entries, and
the hard-block hostname list in device responses are all in place (iOS
pass). Three items remain:

1. **`device_edit` API-context create contract.** The create path is
   web-form shaped: it fires only on `$_POST['device_name']`
   (`device_edit_logic.php`), reads `device_name` / `device_type` /
   `sdd_timezone` / `sdd_allow_device_edits` (`devices_class.php:69`), and
   on success returns a redirect with no data — the app has no reliable way
   to learn which device it just created. Fix at the server: in API context
   (`$session->is_api_context()`), a create returns
   `ScrollDaddyHelper::exportDevice()` of the new device (`device_id`,
   `doh_url`, the lot) instead of the redirect. Field names stay the web
   form's — the client conforms to the existing contract. This also
   retrofits the iOS client, whose registration is broken against the real
   contract (see § Executor guardrails).
2. **`app_navigation` seed.** Add a `scrolldaddy-android` key to the
   `app_navigation` default in `settings.json` (mirror the `scrolldaddy-ios`
   entry: `["dns-filtering", "dns-filtering-devices", "core-profile"]`).
3. **Dev deployment steps** (settings seeds only fill *missing* settings):
   edit the live `app_navigation` setting via `/admin/admin_settings` to add
   both ScrollDaddy keys, and run "Sync with Filesystem" on the
   dns_filtering plugin so `amu_native_screen` seeds — both still
   outstanding on dev from the iOS pass (verified 2026-07-08: the live
   setting has only `default`, and `amu_native_screen` is empty).

In-app billing — server and client — is its own spec,
`specs/mobile_app_billing.md`, consumed post-launch.

## API contract notes (verified against the live server)

The shapes the client must code to — confirmed against
`ScrollDaddyHelper::exportDevice`/`exportBlock` and the logic files, and
unit-tested on iOS (`DNSFilterModelParsingTests` — port those tests):

- **`devices`** → `data.devices[]`: `device_id` (int), `doh_url`,
  `dot_hostname`, `resolver_uid`, `hard_block_hostnames` (string list),
  `blocks[]` summaries (`block_id`, `is_always_on`, `active_now`,
  `rule_count`, `schedule`). `last_seen` is an **object or null**
  (`{seen: ...}` proxied from the DNS server), not a string.
- **`scheduled_block_edit`** read (no `action` key, `device_id` +
  `block_id`) → `data.block` with `filters` / `services` as `{key: action}`
  maps — **PHP serializes an empty map as `[]`**, so the parser must accept
  both object and empty-array. `rules[]` carry `rule_id`, `hostname`,
  `action` (0 block / 1 allow), `hard_block`.
- **`block_filter_set`**: `block_id`, `type` (`filter`|`service`), `key`,
  `action` as a **string** — `"0"` block, `"1"` allow, `""` removes the row
  (Allow = no row).
- **`block_rule_add`**: `block_id`, `hostname`, `action`, optional
  `hard_block` (JSON bool) → the created rule directly in `data`.
  `hard_block` is server-restricted to block-action rules on the always-on
  block and rides the `scrolldaddy_custom_rules` gate.
- **`account_summary`** → `tier_name`, `features.scrolldaddy_*` (five
  flags), `device_count`, `device_max`.
- **`device_edit` requires the user to have a subscription tier** — a
  tier-less account gets `"You do not have an active subscription."` before
  the create path is even reached (reads like `devices` work tier-less).
  The gate fixture account (`~/.joinery_app_test_creds`) must carry a tier
  before the DNS gates run.

## Executor guardrails — defects found verifying the iOS build

The iOS implementation passed its simulator gates and 15 unit tests and
still shipped five defects (post-implementation review, 2026-07-08; see the
iOS spec § Post-implementation review). All are since fixed, so the iOS
sources now demonstrate the corrected patterns — but the rules below exist
because gates and unit tests didn't catch them the first time. Each has an
Android equivalent; build to the rule, and gate what the rule claims.

1. **Device registration must use the real create contract.** iOS
   originally sent `sdd_device_name`/`sdd_device_type` — field names
   guessed from the column prefixes — but the server's create path fires
   only on `device_name`/`device_type`, so registration returned 200 and
   created nothing, then "pinned the newest device on the account" as this
   phone. Android: send the web form's field names, and identify the new
   device from the create response (server item 1; until that lands, use
   the before/after ID diff the fixed iOS `registerThisPhone` uses) —
   **never** a newest-id heuristic. Gate this with a live-API test:
   register against dev, assert the device count went up by one and the
   pinned `device_id` is the one that appeared.
2. **No stubs on a shipping path.** iOS's packet tunnel compiles with a
   `forward()` that is an explicit no-op seam — enabling it on a real
   device would black-hole all traffic. On Android this failure mode is
   *worse and earlier*: standard mode already requires a working in-tunnel
   forwarder (the VpnService owns the DNS address it advertises; an
   unimplemented forwarder = no DNS at all). Every mode the app can switch
   on must move real packets before the phase closes; the Phase 2 gate
   proves it in the emulator by resolving a **non-blocked** hostname
   through the tunnel (positive path), not just observing a blocked one
   fail.
3. **Don't expose a mode the transport can't serve.** iOS originally
   rendered the Strict option while Phase 4 was unimplemented — one tap on
   an entitled build kills connectivity. It is now gated behind
   `DNSFilterConfig.strictModeAvailable` (default false); mirror that: the
   Android protection-level control must not offer strict mode until the
   enforcement path exists end-to-end — gate visibility on the build
   actually implementing it, not on a TODO.
4. **Never tear down working protection before its replacement is
   confirmed up.** iOS's `enableStrict` originally removed the
   standard-mode DoH profile *before* starting the tunnel; a designed
   failure (VPN conflict) left the user unprotected — violating the
   acceptance rule that fallback is standard protection, not nothing. (The
   fixed version keeps standard installed first, then starts the tunnel.)
   Android's single-service mode switch mostly avoids this, but the same
   rule applies to any stop/reconfigure/restart sequence: reconfigure in
   place, or revert to the prior mode on failure.
5. **Claim both address families.** The iOS tunnel routes only IPv4 — on
   an IPv6 network, traffic (and DNS) bypasses enforcement entirely. The
   Android tunnel must add IPv6 routes and a v6 tunnel address in both
   modes, and strict mode must handle UDP/443 (QUIC carries its ClientHello
   encrypted — block/drop UDP 443 to force TCP fallback, the standard
   commercial-filter move).
6. **Documented intents only.** iOS originally deep-linked Settings via the
   private `App-prefs:` scheme — an App Store rejection trigger (now
   `openSettingsURLString`). Android has legitimate equivalents; use only
   documented ones: `Settings.ACTION_VPN_SETTINGS` (always-on/lockdown
   guidance) and `ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` (with the
   Play-policy justification in the listing).
7. **Status enums must be reachable.** iOS originally defined a
   `vpnConflict` status its refresh path could never produce, so the
   conflict UI was dead code. Derive every rendered state from a real
   detection path, and unit-test the state machine's transitions.
8. **Parse against server truth, not assumptions.** The API-contract
   section above exists because two iOS model fields drifted (`last_seen`
   type; create response). Port the iOS parsing test suite to JVM tests
   with fixtures copied from the section above, including the `[]`-empty-map
   quirk, and keep the hard-block engine (subdomain matching + SNI
   extraction, truncation fuzz) unit-tested outside the service, exactly as
   iOS structured it — that part was verified clean and is worth copying
   faithfully.

## Billing strategy — same constraint, Google flavor

Google Play requires **Play Billing** for digital subscriptions, with the
same launch strategy as iOS:

1. **At launch — login-only.** Accounts are created on the website (free or
   paid); the app signs in (`joinery-android`'s `registrationEnabled` config
   stays false), displays status, and every tier gets its full function (the
   free-tier floor stays intact). No in-app purchase and no in-app
   registration — which also means Play's account-deletion-in-app policy
   (triggered by in-app account creation) does not apply.
2. **Later phase — registration on.** Flipping the platform's registration
   toggle triggers Play's account-deletion policy, so in-app deletion ships
   in the same release.
3. **Later phase — Play Billing.** In-app subscribe/change via Google, per
   `specs/mobile_app_billing.md` (which also covers the Android-only
   F-Droid/direct-APK escape hatch). Independent of the registration phase
   and of strict mode.

## App flows

**Onboarding:** sign up on the website, download the app, log in
(`joinery-android` login screen) → app registers the phone as a device
(server returns UID) → one tap on the system VPN consent dialog →
"Protected" status (verified by resolving a test hostname through the
tunnel) → always-on block editor. After signup: zero copy/paste, zero
Settings visits.

**Daily use:** identical to iOS — categories, services, custom rules,
scheduled blocks, per tier.

**Strict mode:** the protection-level control switches the tunnel's routing
scope and enables SNI enforcement — no new consent needed (same VPN). The
always-on + lockdown system toggles are offered here as optional
friction-to-disable, with a deep link.

**Uninstall/disable:** stop the service in-app or uninstall; Android restores
original network behavior automatically. If the user enabled always-on VPN,
uninstall clears it.

## Delivery phases & test gates

**Phase 0 (dependency) — delivered.** `joinery-android`, the server pieces,
and the native member screens are implemented and emulator-gate-tested
(`specs/implemented/android_app_platform.md`,
`specs/implemented/android_native_member_screens.md`). The one
platform-level prerequisite still open is the Play release pipeline: the
member app's release (`specs/android_member_app_release.md`) establishes the
Play App Signing, Console listing, and closed-testing process that
ScrollDaddy's Phase 2 reuses.

The ScrollDaddy phases are strictly sequential after that; each gate re-runs
earlier suites as regression. A difference in Android's favor: **the emulator
supports VpnService**, so every gate runs in the emulator; physical-device
passes are added where OEM behavior matters.

### Phase 1 — Branded shell (webview filters)

The ScrollDaddy app module on `joinery-android`: branding, base URL,
navigation tabs pinned for ScrollDaddy (server-side `app_navigation` entry
for `scrolldaddy-android`), with the filter editor served as webviews of the
existing `/profile/dns_filtering/*` pages. Full product function, no
DNS-specific native code yet.

**Gate:** Compose UI tests in the emulator — log in with a website-created
account; edit a block in the in-app webview and see it in the web editor and
vice versa; tier gates render locked; platform regression suite passes under
ScrollDaddy branding.

### Phase 2 — Automatic DNS (first Play Store release)

`joinery-android-dnsfilter`'s device registration and standard-mode VpnService:
one-tap consent onboarding, protected-status verification, disable flow.
Billing: login-only model. Play submission closes this phase, following the
checklist established by `specs/android_member_app_release.md` (Play App
Signing, listing, data safety form, demo-account review notes for a
login-only app, closed-testing pass) — plus a production deployment base URL
with `scrolldaddy-android` entries in `api_min_client_versions` and
`app_navigation`.

**Gate:** emulator for the functional suite — onboarding to "Protected" with
zero copy/paste; registration asserted against the live API (device count +1,
returned `device_id` is the pinned one — guardrail 1); a **non-blocked
hostname resolves through the tunnel** (positive path, guardrail 2) and a
blocked category fails; disable/uninstall restore normal DNS. Plus a
**physical-device pass** for what the emulator can't prove:
Doze/battery-manager survival overnight, foreground-service behavior, reboot
recovery. Then closed testing track, then review.

### Phase 3 — Native filter editor

`joinery-android-dnsfilter`'s Compose editor screens — always-on editor,
scheduled blocks, custom rules, server-driven tier gates. The server side is
already done: the dns_filtering `profileMenu` entries declare
`nativeScreen: "dns_protection"` / `"dns_devices"` (shipped with the iOS
app), and the navigation endpoint serves `{type: "native", screen,
fallback_url}` to every client. The work is registering those two names in
`NativeScreenRegistry` — a build that registers them gets native screens
immediately; builds that don't keep the webview via the fallback.

**Gate:** emulator — edits made natively appear in the web editor and vice
versa; tier gates server-rejected and rendered locked; "Allow = no row"
semantics verified at the database level; resolver picks up an app-made
change within its reload window.

### Phase 4 — Strict mode (may ship later)

Tunnel routing-scope switch + SNI/IP enforcement from the
`hard_block_hostnames` list in device API responses; always-on/lockdown
guidance. Independent of Phases 1–3.

**Gate:** a hard-blocked site fails in Chrome and in a browser using its own
DoH (DNS bypass); non-blocked sites load normally; disabling strict mode falls
back to standard DNS protection; sustained-browsing memory and battery within
norms on a physical device.

## Out of scope (future specs)

- Enforced tamper-resistance beyond the system always-on/lockdown toggles
  (device-owner/MDM modes).
- Push notifications and in-app query log (same as iOS).
- Wear OS, tablet-optimized layouts, F-Droid distribution.
- Applying config to non-Android devices from the app.

## Acceptance checklist

1. A user with a website-created account can log in, enable filtering, and
   confirm a blocked category fails to resolve — with no further website
   visits after signup.
2. Filter edits in the app are visible in the web editor and vice versa
   (single source of truth; resolver picks changes up within its ~60s reload).
3. Disabling in-app and uninstalling both restore normal DNS immediately.
4. Tier gates server-rejected and rendered locked; hard-block flags rejected
   without `scrolldaddy_custom_rules`.
5. Strict mode: hard-blocked site fails under DNS bypass; fallback on disable
   is standard protection, not unprotected.
6. Filtering survives an overnight Doze cycle and a reboot (with always-on
   enabled) on at least one aggressive-OEM physical device.
7. `joinery-android-dnsfilter` builds with no ScrollDaddy imports and works against a
   second ScrollDaddy-style deployment unchanged (branding aside).

## Documentation deliverables (on implementation)

- `plugins/dns_filtering/docs/overview.md` — the Android app as a config
  delivery channel; Private DNS (DoT SNI subdomain) as a supported manual
  path.
- `docs/mobile_apps.md` (owned by the platform specs) — add ScrollDaddy
  Android as a consuming app with its `joinery-android-dnsfilter` layer.
