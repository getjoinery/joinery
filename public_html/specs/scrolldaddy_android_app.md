# ScrollDaddy Android App — Feasibility & Spec

The Android counterpart to `specs/scrolldaddy_ios_app.md`: identical features —
reusable Joinery account module, filter management, automatic local DNS
configuration with safe revert, and connection-level hard blocking — delivered
in the same four phases with a test gate after each.

**Shared server work.** All server-side deliverables are platform-neutral and
defined elsewhere: user session-key auth in `specs/user_session_api_keys.md`
and server-driven forms in `specs/formwriter_json_forms.md` (both implemented
before the app specs), and the ScrollDaddy plugin API actions +
`sbr_hard_block` flag in the iOS spec. This spec adds exactly one server item
(Play Billing). If the iOS spec is implemented first, Android Phases 1–2 are
pure client work; otherwise the shared plugin items are built under whichever
app spec goes first.

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

### Part 1 — same server gap, same module shape

Session-key auth and server-driven forms (shared deliverables) plus a
reusable **`joinery-android`** Kotlin library: API client with injected base
URL + branding, key storage in Keystore-backed `EncryptedSharedPreferences`,
a native Compose login screen, **one generic server-driven form renderer**
(per `specs/formwriter_json_forms.md`) driving register, forgot/reset
password, account edit, and contact preferences from server JSON
definitions, plus subscription status and billing entry point. Like
JoineryKit, it sends the `client_app`/`client_version` headers on every
request and renders any 426 `UpgradeRequired` response as a blocking upgrade
screen with a Play Store deep link (per `specs/user_session_api_keys.md`).
Same reuse target as JoineryKit: any app on any Joinery deployment.

Decision: native Kotlin + Compose, no Kotlin Multiplatform sharing with the
iOS packages. The reusable boundary between the platforms is the server API,
not shared client code — KMP would couple the two codebases for little gain at
this app size.

### Part 2 — pure client work

Same plugin API actions; Compose screens mirroring the iOS editor, same
server-driven tier gates, same "Allow = no row" submit semantics living
server-side in the shared logic functions.

### Billing — same constraint, Google flavor

Google Play requires **Play Billing** for digital subscriptions, with the same
15–30% cut and the same launch strategy:

1. **At launch — account-exists model.** Sign-in, status display, full
   free-tier function; no in-app purchase offered.
2. **Post-launch — Play Billing.** Server: `GooglePlayHelper` parallel to
   `StripeHelper`/`AppStoreHelper` — purchase verification via the Play
   Developer API, Real-Time Developer Notifications (Pub/Sub) webhook for
   renewals/cancellations/refunds, admin-configured product-ID → tier mapping.
   Subscription source becomes a three-way exclusive: Stripe, App Store, or
   Play Store; the server records the source and routes manage/cancel
   accordingly.

Escape hatch unique to Android (noted, not planned): direct APK / F-Droid
distribution is legal and skips Play Billing entirely.

## Architecture

| Layer | Module | Reusable for | Contents |
|---|---|---|---|
| Account | **`joinery-android`** | any app on any Joinery deployment | API client, session-key auth + Keystore storage, native login screen, generic server-driven form renderer, subscription status |
| DNS filtering | **`dnsfilter-android`** | any ScrollDaddy-style deployment | Device registration, block editor screens, tier-gate rendering, the `VpnService` (standard + strict modes), protection-level control |
| Brand | **ScrollDaddy app module** | — | Application ID, theme, deployment base URL, Play Store assets |

Repo: `~/dev/scrolldaddy-android` on the Mac mini (Android Studio CLI tools +
Gradle + emulator run fine on Apple Silicon; the SSH workflow from
`specs/mac_mini_ios_development_access.md` carries over — `./gradlew build`,
`adb`, headless emulator, screenshots via `adb exec-out screencap`).

## App flows

**Onboarding:** register/login → app registers the phone as a device (server
returns UID) → one tap on the system VPN consent dialog → "Protected" status
(verified by resolving a test hostname through the tunnel) → always-on block
editor. Zero copy/paste, zero Settings visits.

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

### Phase 1 — Account (`joinery-android` + shared session-key auth)

**Gate:** Compose UI test suite in the emulator against `dev.getjoinery.com` —
register, login/logout, password-reset round-trip, account edit, session-key
revocation on password change, error and rate-limit rendering.

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

Tunnel routing-scope switch + SNI/IP enforcement from the shared
`sbr_hard_block` list; always-on/lockdown guidance. Independent of Phases 1–3.

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

1. A new user can register, enable filtering, and confirm a blocked category
   fails to resolve — entirely on the phone, without visiting the website.
2. Password reset round-trips through the existing reset email in-app.
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
- Play Billing additions to whichever doc hosts the IAP/AppStoreHelper
  documentation when that lands.
