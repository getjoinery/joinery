# Joinery Member App — App Store Release — Spec

Ships the reference member app to the App Store. The platform underneath it
— server endpoints, JoineryKit, the reference app target, and the Simulator
test gates — is implemented (`specs/implemented/ios_app_platform.md`). This
spec covers everything between a green Simulator gate and a listed,
reviewable app.

The app ships login-only (registration toggle off), so Apple's in-app
account-deletion requirement is not triggered.

## Work items

### Deployment target

The app currently points at `dev.getjoinery.com`. A shipped app needs a
production Joinery deployment as its base URL, with the app-platform server
pieces live there (navigation endpoint, web-session bridge, app display
mode) and a `client_app` id + minimum-version entry configured for it.

### Branding

- Final display name and bundle identifier.
- App icon (full size set), accent color, launch screen.
- These live in the app target only — JoineryKit stays brand-free.

### App-context web polish

- The cookie-consent banner greets every fresh install inside app-context
  webviews. Decide whether app-mode sessions should suppress it (the app is
  a signed-in, first-party surface) and implement accordingly.
- Sweep the `/profile` surface in app display mode for anything else that
  reads wrong inside an app (marketing prompts, links that assume site
  chrome).

### Signing & devices

- One-time Apple ID sign-in in Xcode on the Mac mini (team `J634NTDX3D`);
  distribution certificate and provisioning via automatic signing.
- Register a physical iPhone for the device gate.

### App Store Connect

- App record: name, subtitle, category, description, keywords, screenshots
  (required device sizes), support URL, marketing URL.
- Privacy: privacy policy URL and the data-collection nutrition labels
  (session credential + account data; no tracking).
- Review notes with a demo account — a login-only app must hand App Review
  working credentials.

### TestFlight

- Archive + upload pipeline from the mini (`xcodebuild archive` /
  `-exportArchive` or Xcode Organizer), internal-tester group, at least one
  full install-and-use pass via TestFlight before submission.

## Gate

On a physical iPhone — install → sign in → every navigation entry reachable
and chrome-less; App Store review passes.

## Dependencies

- `specs/implemented/ios_app_platform.md` — Phases 1–3 delivered and
  gate-tested (`tests/functional/ios/phase2_gate.sh`, `phase3_gate.sh`).
- `specs/implemented/api_contract_and_idempotency.md` — contract audit
  complete; the client already sends `Idempotency-Key` on mutating calls
  (JoineryKit `APIClient`).
- `specs/mac_mini_ios_development_access.md` — build environment.

## Out of scope

- In-app billing (`specs/mobile_app_billing.md`) — login-only v1 sells
  nothing in-app.
- Push notifications, native content screens, Android — see the platform
  spec's future list.
- ScrollDaddy iOS release (`specs/implemented/scrolldaddy_ios_app.md`) — its own spec.

## Documentation deliverables (on implementation)

- `docs/mobile_apps.md` — add the release process: signing setup, archive /
  TestFlight / submission pipeline, and the App Store Connect checklist for
  standing up a new branded app's listing.
