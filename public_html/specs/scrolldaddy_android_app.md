# ScrollDaddy Android App — Feasibility & Spec

The Android counterpart to `specs/scrolldaddy_ios_app.md`: identical features —
reusable Joinery account module, filter management, automatic local DNS
configuration with safe revert, and connection-level hard blocking — delivered
in the same four phases with a test gate after each.

**Server side.** None needed. The platform-neutral API surface both apps
consume is in place: session-key auth, server-driven account forms, and the
full `dns_filtering/` action surface including the hard-block hostname list
(`docs/api.md`, `plugins/dns_filtering/docs/overview.md` § API Surface).
Every phase is pure client work; in-app billing — server and client — is
its own spec, `specs/mobile_app_billing.md`, consumed post-launch.

## Feasibility findings — where Android differs from iOS

Android is the *easier* platform for every part. The differences:

### Parts 3 & 4 collapse into one mechanism

Android's `VpnService` is the standard, Play-Store-accepted way to filter
traffic locally (Blokada, AdGuard, NextDNS all use it), and it covers both the
DNS part and the hard-blocking part:

- **Standard mode** — the tunnel claims only DNS traffic (routes just the
  tunnel's virtual DNS address) and forwards queries to the ScrollDaddy DoH
  resolver with the device UID. Equivalent to iOS Part 3, same server-side
  policy, no resolver changes.
- **Strict mode** — the tunnel claims all traffic and additionally drops
  connections by TLS SNI / destination IP from the synced hard-block list,
  stripping HTTPS/SVCB records in-tunnel so Encrypted Client Hello can't hide
  the SNI. Equivalent to iOS Part 4 — but it's a mode switch inside one
  service, not a second mechanism.

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

### Part 1 — same module shape as JoineryKit

A reusable **`joinery-android`** Kotlin library over the platform's
session-key auth: API client with injected base URL + branding, key storage
in Keystore-backed `EncryptedSharedPreferences`, a native Compose login
screen, and subscription status. At launch the module is login-only —
accounts are created on the website, and "Forgot password?" opens the
website's reset page. The post-launch account-management phase adds **one
generic server-driven form renderer** (schema in `docs/formwriter.md` §
JSON output mode) driving register, forgot/reset password, account edit,
and contact preferences from server JSON definitions, plus the billing
entry point. Like JoineryKit, it sends the `client-app`/`client-version`
headers on every request and renders any 426 `UpgradeRequired` response as
a blocking upgrade screen with a Play Store deep link (see `docs/api.md`).
Same reuse target as JoineryKit: any app on any Joinery deployment.

Decision: native Kotlin + Compose, no Kotlin Multiplatform sharing with the
iOS packages. The reusable boundary between the platforms is the server API,
not shared client code — KMP would couple the two codebases for little gain at
this app size.

### Part 2 — pure client work

The same `dns_filtering/` API actions the iOS app consumes; Compose screens
mirroring the iOS editor, same server-driven tier gates, same "Allow = no
row" submit semantics living server-side in the shared logic functions.

### Billing — same constraint, Google flavor

Google Play requires **Play Billing** for digital subscriptions, with the same
15–30% cut and the same launch strategy:

1. **At launch — login-only.** Accounts are created on the website (free or
   paid); the app signs in, displays status, and every tier gets its full
   function. No in-app purchase and no in-app registration — which also
   means Play's account-deletion-in-app policy (triggered by in-app account
   creation) does not apply.
2. **Later phase — in-app account management.** Registration, password
   reset, account edit, and contact preferences via the generic
   server-driven form renderer (the endpoints exist; this is client work).
   In-app account deletion ships in the same phase, per Play policy.
3. **Later phase — Play Billing.** In-app subscribe/change via Google, per
   `specs/mobile_app_billing.md` (which also covers the Android-only
   F-Droid/direct-APK escape hatch). Independent of the account-management
   phase and of Phase 4.

## Architecture

| Layer | Module | Reusable for | Contents |
|---|---|---|---|
| Account | **`joinery-android`** | any app on any Joinery deployment | API client, session-key auth + Keystore storage, native login screen, subscription status. Post-launch: generic server-driven form renderer, billing entry point |
| DNS filtering | **`dnsfilter-android`** | any ScrollDaddy-style deployment | Device registration, block editor screens, tier-gate rendering, the `VpnService` (standard + strict modes), protection-level control |
| Brand | **ScrollDaddy app module** | — | Application ID, theme, deployment base URL, Play Store assets |

Repo: `~/dev/scrolldaddy-android` on the Mac mini (Android Studio CLI tools +
Gradle + emulator run fine on Apple Silicon; the SSH workflow from
`specs/mac_mini_ios_development_access.md` carries over — `./gradlew build`,
`adb`, headless emulator, screenshots via `adb exec-out screencap`).

## App flows

**Onboarding:** sign up on the website, download the app, log in → app
registers the phone as a device (server returns UID) → one tap on the system
VPN consent dialog → "Protected" status (verified by resolving a test
hostname through the tunnel) → always-on block editor. After signup: zero
copy/paste, zero Settings visits.

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

Same four phases and ordering as the iOS spec, sequential with accumulating
regression suites. A difference in Android's favor: **the emulator supports
VpnService**, so all four gates run in the emulator; physical-device passes
are added where OEM behavior matters.

### Phase 1 — Account (`joinery-android`)

**Gate:** Compose UI test suite in the emulator against `dev.getjoinery.com` —
login/logout with a website-created account, session-key revocation on
password change, error and rate-limit rendering.

### Phase 2 — Filters

**Gate:** emulator — app edits visible in the web editor and vice versa; tier
gates server-rejected and rendered locked; resolver picks up an app-made
change within its reload window.

### Phase 3 — Automatic DNS (first Play Store release)

Standard-mode VpnService, onboarding, status verification, disable flow,
account-exists billing. Play submission closes the phase.

**Gate:** emulator for the functional suite (onboarding to "Protected" with
zero copy/paste; blocked category fails to resolve; disable/uninstall restore
normal DNS), plus a **physical-device pass** for what the emulator can't
prove: Doze/battery-manager survival overnight, foreground-service behavior,
reboot recovery. Then closed testing track, then review.

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
2. The login screen's "Forgot password?" link opens the website's reset
   page, and the app signs in with the new password afterward (session keys
   from the old password are revoked).
3. Filter edits in the app are visible in the web editor and vice versa.
4. Disabling in-app and uninstalling both restore normal DNS immediately.
5. Tier gates server-rejected and rendered locked; hard-block flags rejected
   without `scrolldaddy_custom_rules`.
6. Strict mode: hard-blocked site fails under DNS bypass; fallback on disable
   is standard protection, not unprotected.
7. Filtering survives an overnight Doze cycle and a reboot (with always-on
   enabled) on at least one aggressive-OEM physical device.
8. `joinery-android` builds standalone with no ScrollDaddy imports and works
   against a second Joinery deployment unchanged.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — Android section: `joinery-android` integration
  guide alongside JoineryKit.
- `plugins/dns_filtering/docs/overview.md` — the Android app as a config
  delivery channel; Private DNS (DoT SNI subdomain) as a supported manual
  path.
