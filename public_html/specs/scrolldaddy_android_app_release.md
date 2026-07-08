# ScrollDaddy Android — Activation Verification & Play Store Release — Spec

Ships ScrollDaddy for Android. The app is built
(`specs/implemented/scrolldaddy_android_app.md`): the branded shell, the
native protection/devices/editor screens, and the standard-mode `VpnService`
DoH forwarder are implemented, unit-tested (22 JVM tests), and Phase-1
gate-tested in the emulator (shell + native screens + webview fallback).
What has *not* run is the live datapath end-to-end — the tunnel is
unit-tested at the packet layer but no gate has yet resolved a hostname
through it — so this spec covers the activation gates (register → consent →
protected), the physical-device passes, and everything between that and a
listed, reviewable app.

The app ships login-only (registration toggle off), so Play's in-app
account-deletion policy is not triggered. Standard mode only — strict mode
(all-traffic routing + SNI enforcement) is a future spec;
`strictModeAvailable` stays false.

## Work items

### Prerequisites

- **A subscribed fixture account.** `dns_filtering/device_edit` tier-gates
  before its create path, so the gate account (`~/.joinery_app_test_creds`
  pattern) needs a ScrollDaddy tier with `scrolldaddy_custom_rules` for the
  hard-block legs. Extend the gate fixtures accordingly.
- **Consent automation.** The system VPN consent dialog is not the app's UI;
  the emulator gate drives it with UiAutomator (or pre-grants via
  `adb shell appops` where supported) so the suite stays unattended.
- **A registered physical device** for the OEM/Doze gates (Moto G 5G per
  `specs/android_member_app_release.md`; same known-good-cable caveat).

### Emulator gate 1 — register & activate (the product's core promise)

The emulator supports VpnService, so this gate runs unattended against dev:
sign in → register this phone (live-API assertion: device count +1 and the
pinned `device_id` is the row the create returned) → notification permission
+ one-tap VPN consent → status shows Protected. Then:

- A **non-blocked hostname resolves through the tunnel** (positive path —
  proves the forwarder moves real packets, the no-stubs guardrail's gate).
- A blocked category fails to resolve; both checks repeated over IPv6 where
  the emulator network allows it.
- Disable in-app and uninstall both restore normal DNS immediately.
- `onRevoke` (another VPN takes the slot) lands the app in a truthful
  unprotected state.

### Emulator gate 2 — editor round-trip

The native always-on editor against the live account: edits made natively
appear in the web editor and vice versa; "Allow = no row" verified at the
database level; a hard-block flag save is rejected without
`scrolldaddy_custom_rules` and accepted with it; the resolver picks up an
app-made change within its ~60s reload window.

### Physical-device pass

What the emulator can't prove: filtering survives an overnight Doze cycle;
reboot recovery via the boot receiver (and with the system always-on VPN
toggle set); foreground-notification behavior; battery within norms under
normal use on at least one aggressive-OEM device.

### Deployment target

The app points at `dev.getjoinery.com`. A shipped app needs the production
ScrollDaddy deployment as its base URL, with `scrolldaddy-android` entries
in that deployment's `api_min_client_versions` and `app_navigation`
settings, and the dns_filtering plugin menu entries carrying their
`nativeScreen` values (plugin sync).

### Branding & listing

- Adaptive app icon (foreground/background, full density set), splash,
  final display name for the `scrolldaddy-android` module — app module only;
  `joinery-android-dnsfilter` stays brand-free.
- Play App Signing: upload key, enrollment (or reuse the pipeline the member
  release establishes).
- Play Console record: title, descriptions, category, screenshots, feature
  graphic; data safety form (session credential + account data + DNS queries
  processed server-side; no tracking); content rating.
- **VpnService declaration:** Play requires apps using `VpnService` to
  complete the VPN declaration form — core functionality here is
  user-configured DNS content filtering (the service's own client). Review
  notes state it plainly and include a subscribed demo account — a
  login-only app must hand Play review working credentials.

### App-context web polish

Sweep the `/profile/dns_filtering/*` pages in app display mode (cookie
banner, marketing prompts, links that assume site chrome) — shared item with
`specs/android_member_app_release.md`; whichever ships first does the sweep.

### Closed testing

Signed AAB (`bundleRelease`) to a closed testing track; at least one full
register→consent→protected pass on a physical device via the track before
submission.

## Gate

On a physical device: install from the testing track → sign in → register →
consent → Protected → non-blocked resolves, blocked category fails →
disable/uninstall restore DNS → overnight Doze + reboot survival; Play
review passes.

## Dependencies

- `specs/implemented/scrolldaddy_android_app.md` — the build (delivered).
- `specs/android_member_app_release.md` — establishes the Play App
  Signing/Console/closed-testing pipeline; whichever app ships first pays
  the one-time setup.

## Out of scope

- Strict mode (all-traffic routing, SNI/IP enforcement, UDP-443/QUIC
  stance) — its own future spec; the engine is unit-tested and waiting.
- In-app billing (`specs/mobile_app_billing.md`, Play Billing flavor) and
  in-app registration.
- Private DNS (DoT) guidance UI, always-on/lockdown onboarding flows beyond
  the existing deep link; Wear OS; F-Droid.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — mark the ScrollDaddy Android activation path as
  emulator/device-verified; add any release-pipeline deltas the member
  release doc doesn't already cover.
