# ScrollDaddy iOS — Activation Verification & App Store Release — Spec

Ships ScrollDaddy for iPhone. The app is built
(`specs/implemented/scrolldaddy_ios_app.md`): the branded shell, the native
protection/devices/editor screens, and the standard-mode DNS layer
(`NEDNSSettingsManager`) are implemented and simulator-verified (unit tests,
builds, shell UITest). What has *not* run is anything a Network Extension
gate needs — NE does not run in the Simulator — so this spec covers the
device-verified activation path (register → activate → protected) and
everything between that and a listed, reviewable app.

The app ships login-only (registration toggle off), so Apple's in-app
account-deletion requirement is not triggered. Standard mode only — strict
mode (the packet-tunnel transport) is a future spec; `strictModeAvailable`
stays false.

## Work items

### Prerequisites

- **Paid Apple Developer Program enrollment** (team `J634NTDX3D`). The DNS
  Settings capability (`com.apple.developer.networking.dns-settings`) is not
  available to free personal teams — this blocks every device gate below.
- **A subscribed fixture account.** `dns_filtering/device_edit` tier-gates
  before its create path, so the gate account (`~/.joinery_app_test_creds`
  pattern) needs a ScrollDaddy tier with `scrolldaddy_custom_rules` for the
  hard-block legs. Extend the gate fixtures accordingly.
- **A registered physical iPhone** for the device gates.

### Device gate 1 — register & activate (the product's core promise)

On a physical iPhone against dev: sign in → register this phone
(live-API assertion: device count +1 and the pinned `device_id` is the row
the create returned) → save the DoH configuration → the guided one-time
Settings enable → status shows Protected. Then:

- A blocked category fails to resolve; a non-blocked hostname resolves
  (positive path — proves the DoH config actually serves).
- Disable in-app and delete-the-app both restore normal DNS immediately.
- VPN-active and network-switch edge cases behave as documented (an active
  VPN supersedes the DNS setting; the app reports the state honestly).

### Device gate 2 — editor round-trip

The native always-on editor against the live account: edits made natively
appear in the web editor and vice versa; "Allow = no row" verified at the
database level; a hard-block flag save is rejected without
`scrolldaddy_custom_rules` and accepted with it; the resolver picks up an
app-made change within its ~60s reload window.

### Deployment target

The app points at `dev.getjoinery.com`. A shipped app needs the production
ScrollDaddy deployment as its base URL, with `scrolldaddy-ios` entries in
that deployment's `api_min_client_versions` and `app_navigation` settings,
and the dns_filtering plugin menu entries carrying their `nativeScreen`
values (plugin sync).

### Branding & listing

- App icon (full size set), accent polish, launch screen — app target only;
  JoineryDNSFilterKit stays brand-free.
- App Store Connect record: name, subtitle, category, description, keywords,
  screenshots, support/marketing URLs.
- Privacy nutrition labels: session credential + account data + DNS queries
  processed server-side; no tracking.
- **VPN/DNS review posture:** apps installing DNS configurations get extra
  review scrutiny. Review notes state plainly what the DoH configuration
  does, that filtering policy is user-configured, and that the app is the
  service's own client (NextDNS precedent). Include a subscribed demo
  account — a login-only app must hand App Review working credentials.

### App-context web polish

Sweep the `/profile/dns_filtering/*` pages in app display mode (cookie
banner, marketing prompts, links that assume site chrome) — shared item with
`specs/ios_member_app_release.md`; whichever ships first does the sweep.

### TestFlight

Archive + upload from the Mac mini, internal-tester group, at least one full
register→activate→protected pass via a TestFlight build before submission.

## Gate

On a physical iPhone: install from TestFlight → sign in → register →
activate → Protected → blocked category fails, non-blocked resolves →
disable restores DNS; App Store review passes.

## Dependencies

- `specs/implemented/scrolldaddy_ios_app.md` — the build (delivered).
- `specs/ios_member_app_release.md` — establishes the signing/Connect/
  TestFlight pipeline; whichever app ships first pays the one-time setup.

## Out of scope

- Strict mode (packet-tunnel transport, SNI enforcement, IPv6/QUIC stance) —
  its own future spec; the engine is unit-tested and waiting.
- In-app billing (`specs/mobile_app_billing.md`) and in-app registration.
- Tamper-resistance (Screen Time / supervised devices).

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — mark the ScrollDaddy iOS activation path as
  device-verified; add any release-pipeline deltas the member release doc
  doesn't already cover.
