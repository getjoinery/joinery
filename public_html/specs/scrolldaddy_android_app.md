# ScrollDaddy Android App — Feasibility & Spec

The Android counterpart to `specs/scrolldaddy_ios_app.md`: sign in, manage
the subscription, edit filters, apply the DNS configuration to the device
automatically with safe revert, and hard-block selected sites at the
connection level.

**This app is a consumer of the Joinery Android app platform**
(`specs/android_app_platform.md`). Everything account- and shell-shaped —
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

The platform's webview layer also means the existing
`/profile/dns_filtering/*` pages are usable in-app from day one, which is
what lets the Play Store release ship before the native editor (see phases).

## Architecture

One ScrollDaddy-specific module plus the app shell, on top of
`joinery-android` (from `specs/android_app_platform.md`). Repo:
`~/dev/scrolldaddy-android` on the Mac mini (toolchain and SSH workflow per
the platform spec).

| Layer | Module | Reusable for | Contents |
|---|---|---|---|
| Core | **`joinery-android`** | any app on any Joinery deployment | Specified and delivered by `specs/android_app_platform.md` |
| DNS filtering | **`dnsfilter-android`** | any ScrollDaddy-style deployment (e.g. NetworkSentry) | Device registration, native block editor screens (always-on + scheduled), category/service/custom-rule screens with server-driven tier gates, the `VpnService` (standard + strict modes), protection-level control |
| Brand | **ScrollDaddy app module** | — | Application ID, theme, deployment base URL, `client_app` id (`scrolldaddy-android`), Play Store assets |

Design rules carried over from the web product:

- **Tier gating is server-enforced.** The client renders locked/upsell states
  from the feature flags in API responses; the server rejects gated writes
  regardless.
- **"Allow" on the always-on block means "no row"** — the app submits the
  same semantics as the web editor; the API actions reuse the existing logic
  functions precisely so this invariant lives in one place.

## Server-side work

None beyond the platform's pieces (delivered by `specs/ios_app_platform.md`,
consumed via `specs/android_app_platform.md`). The ScrollDaddy surface is in
place: the full `dns_filtering/` action surface including the hard-block
hostname list in device responses. In-app billing — server and client — is
its own spec, `specs/mobile_app_billing.md`, consumed post-launch.

## Billing strategy — same constraint, Google flavor

Google Play requires **Play Billing** for digital subscriptions, with the
same launch strategy as iOS:

1. **At launch — login-only.** Accounts are created on the website (free or
   paid); the app signs in (`joinery-android`'s registration toggle stays
   off), displays status, and every tier gets its full function (the
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

**Phase 0 (dependency): the Android platform ships first** —
`joinery-android` and its gates per `specs/android_app_platform.md` (which
itself depends on the server pieces from `specs/ios_app_platform.md`).

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

`dnsfilter-android`'s device registration and standard-mode VpnService:
one-tap consent onboarding, protected-status verification, disable flow.
Billing: login-only model. Play submission closes this phase.

**Gate:** emulator for the functional suite (onboarding to "Protected" with
zero copy/paste; blocked category fails to resolve; disable/uninstall restore
normal DNS), plus a **physical-device pass** for what the emulator can't
prove: Doze/battery-manager survival overnight, foreground-service behavior,
reboot recovery. Then closed testing track, then review.

### Phase 3 — Native filter editor

`dnsfilter-android`'s Compose editor screens — always-on editor, scheduled
blocks, custom rules, server-driven tier gates — flipping the navigation
routes from the webview pages to native, one route at a time (the platform's
version-skew rule keeps older shipped versions on the webviews).

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
7. `dnsfilter-android` builds with no ScrollDaddy imports and works against a
   second ScrollDaddy-style deployment unchanged (branding aside).

## Documentation deliverables (on implementation)

- `plugins/dns_filtering/docs/overview.md` — the Android app as a config
  delivery channel; Private DNS (DoT SNI subdomain) as a supported manual
  path.
- `docs/mobile_apps.md` (owned by the platform specs) — add ScrollDaddy
  Android as a consuming app with its `dnsfilter-android` layer.
