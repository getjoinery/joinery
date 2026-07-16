# Joinery Member App for Android — Play Store Release — Spec

Ships the reference member app to Google Play. The platform underneath it —
server endpoints, the `joinery-android` core library, the reference app
module, and the emulator test gates — is implemented
(`specs/implemented/android_app_platform.md`). This spec covers everything
between a green emulator gate and a listed, reviewable app.

The app ships login-only (registration toggle off), so Google Play's in-app
account-deletion requirement is not triggered.

## Work items

### Deployment target

The app currently points at `dev.getjoinery.com`. A shipped app needs a
production Joinery deployment as its base URL, with the app-platform server
pieces live there (navigation endpoint, web-session bridge, app display
mode) and a `client_app` id + minimum-version entry (`api_min_client_versions`)
configured for it.

### Branding

- Final display name and application ID for `joinery-member-android`.
- App icon (adaptive icon: foreground + background layers, full density set),
  theme/accent colors, splash screen.
- These live in the app module only — `joinery-android` stays brand-free
  (the core library builds with no brand imports; the app module consumes it
  unchanged).

### App-context web polish

- The cookie-consent banner greets every fresh install inside app-context
  webviews. Decide whether app-mode sessions should suppress it (the app is
  a signed-in, first-party surface) and implement accordingly.
- Sweep the `/profile` surface in app display mode for anything else that
  reads wrong inside an app (marketing prompts, links that assume site
  chrome).

### Signing & devices

- Play App Signing: generate an upload key, enroll the app, hand Google the
  app-signing key management.
- `versionCode` / `versionName` scheme and release build config for the app
  module.
- Register a physical device for the device gate. The Moto G 5G (2025) is the
  intended target; the ZTE never enumerated over USB (charge-only cable / worn
  port — see Claude memory), so a known-good device or cable is a
  prerequisite for this gate.

### Google Play Console

- App record: title, short + full description, category, screenshots
  (required form factors), feature graphic, app icon.
- Data safety form and content rating questionnaire.
- Privacy: privacy policy URL and the data-collection disclosures (session
  credential + account data; no tracking).
- Review notes with a demo account — a login-only app must hand Play review
  working credentials.

### Closed testing

- Upload the signed AAB (`bundleRelease`) to a closed testing track,
  tester list, at least one full install-and-use pass on a physical device
  via the testing track before submission for review.

## Gate

On a physical device — install → sign in → every navigation entry reachable
and chrome-less; Play review passes.

## Dependencies

- `specs/implemented/android_app_platform.md` — Phases 1–2 delivered and
  gate-tested on the emulator (`joinery-android` core library, navigation
  shell, authenticated webview).
- `specs/mac_mini_ios_development_access.md` — the Mac mini also hosts the
  Android build + emulator toolchain (see Claude memory `reference_mac_mini_android`).

## Out of scope

- In-app billing (`specs/implemented/mobile_app_billing.md` — built) —
  the member app still sells nothing in-app. What v1 ships from that work
  is only the Subscriptions screen's source routing: a member whose
  subscription is billed through a store app sees a "Manage in Google
  Play / App Store" deep link instead of the web rows. No release action
  needed.
- Push notifications, native content screens, iOS — see the platform spec's
  future list.
- ScrollDaddy Android release (`specs/implemented/scrolldaddy_android_app.md`) — its own
  spec, adds the VpnService DNS-filtering layer on this platform.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — add the release process: Play App Signing setup,
  bundle / closed-testing / submission pipeline, and the Play Console
  checklist for standing up a new branded app's listing.
