# Mac Mini iOS Development Access — Setup Spec

Enable Claude (running on this dev box) to SSH into the Mac mini to develop and
test iPhone apps: edit project files, build with `xcodebuild`, and run/verify apps
in the iOS Simulator headlessly via `xcrun simctl` — including pulling screenshots
back here for visual review.

This is developer-environment infrastructure, like the ScrollDaddy DNS boxes — it
is not part of the Joinery platform. Once working, connection details and the
day-to-day command reference go into Claude memory (`reference_mac_mini_ios.md`),
not `/docs/`.

## Inventory of moving parts

All integration points, decided up front:

| Concern | Decision |
|---|---|
| Network path | Tailscale on both the Mac mini and this dev box (no port forwarding, stable hostname) |
| Authentication | Existing `~/.ssh/id_ed25519_claude` key, added to the mini's `authorized_keys` |
| File editing | sshfs mount of the mini's project dir onto this box, so native Read/Edit tools work; git as fallback if sshfs proves flaky |
| Building | `xcodebuild` over SSH on the mini |
| Running/testing | iOS Simulator via `xcrun simctl` (fully headless; no signing required) |
| Visual verification | `simctl io booted screenshot` written into the sshfs-mounted dir (or `scp` back), then viewed locally |
| Source control | Git repo per app on the mini; remote (GitHub) optional, added when wanted |
| Physical-device testing | Requires a one-time Apple ID sign-in in Xcode's GUI; out of scope for initial setup |
| App Store / TestFlight | Requires paid Apple Developer Program; out of scope for initial setup |

## Prerequisites (user actions)

These need either the Mac's GUI or sudo on this dev box, so they are user steps:

1. **Tailscale on the Mac mini** — install, log in, note the tailnet hostname
   (e.g. `mac-mini.tailXXXX.ts.net` or its 100.x.y.z IP).
2. **Tailscale on this dev box** — `user1` has no passwordless sudo, so the user
   runs: `curl -fsSL https://tailscale.com/install.sh | sh` then
   `sudo tailscale up`. (Suggest typing these as `! <command>` in a Claude session
   so output lands in the conversation.)
3. **sshfs on this dev box** — `sudo apt install sshfs`, and confirm `user1` is in
   the `fuse`-allowed config (`/etc/fuse.conf` default is fine; no `allow_other`
   needed since only `user1` mounts it).
4. **Xcode on the Mac mini** — install from the App Store (GUI-only step). Sign in
   to the App Store with an Apple ID first if not already.
5. **Remote Login on the Mac mini** — System Settings → General → Sharing →
   Remote Login → on, allowing the mini's user account.

## Mac mini configuration (over SSH once reachable)

After the user provides the tailnet hostname and macOS username, Claude performs
these from this box (first connection will need the user to paste the public key
into `~/.ssh/authorized_keys` on the mini, or temporarily enable password auth):

1. **Authorize the key** — append the `id_ed25519_claude.pub` line to
   `~/.ssh/authorized_keys` on the mini; `chmod 600` it.
2. **Keep the machine awake** — `sudo pmset -a sleep 0 displaysleep 10` and
   `sudo pmset -a autorestart 1` so the box survives power blips and never sleeps
   away mid-build.
3. **Finish Xcode CLI setup**:
   ```bash
   sudo xcode-select -s /Applications/Xcode.app
   sudo xcodebuild -license accept
   xcodebuild -runFirstLaunch
   xcodebuild -downloadPlatform iOS   # simulator runtime
   ```
4. **Verify the toolchain**: `xcodebuild -version`, `xcrun simctl list devices`
   (should show iPhone simulators with an installed iOS runtime).
5. **Create the project root** — `mkdir -p ~/dev` ; each app lives at
   `~/dev/<app-name>` as its own git repo.

## Dev box configuration

1. **SSH config entry** in `~/.ssh/config`:
   ```
   Host macmini
       HostName <tailnet-hostname>
       User <mac-username>
       IdentityFile ~/.ssh/id_ed25519_claude
       ServerAliveInterval 30
   ```
2. **sshfs mount point** — `mkdir -p ~/macmini-dev`, mounted with:
   ```bash
   sshfs macmini:dev ~/macmini-dev -o reconnect,ServerAliveInterval=15,IdentityFile=~/.ssh/id_ed25519_claude
   ```
   Add a small helper script (`~/bin/mount-macmini`) so the mount is one command
   after a reboot of either machine. The mount is on-demand, not fstab — it should
   never block this box's boot.
3. **Smoke test** — `ssh macmini 'xcodebuild -version'` and a file round-trip
   through the mount.

## Development workflow (the loop this enables)

Once set up, a typical iteration:

```bash
# Edit: native Read/Edit tools against ~/macmini-dev/<app>/...

# Build for simulator
ssh macmini 'cd dev/<app> && xcodebuild -scheme <App> \
  -destination "platform=iOS Simulator,name=iPhone 16" build'

# Boot a simulator (persists across commands), install, launch
ssh macmini 'xcrun simctl boot "iPhone 16" || true'
ssh macmini 'xcrun simctl install booted <path/to/App.app>'
ssh macmini 'xcrun simctl launch --console booted <bundle.id>'

# Visual check — screenshot lands in the mounted dir, viewable locally
ssh macmini 'xcrun simctl io booted screenshot dev/<app>/screenshot.png'

# Logs and automated tests
ssh macmini 'xcrun simctl spawn booted log stream --predicate "subsystem == \"<bundle.id>\"" --timeout 10'
ssh macmini 'cd dev/<app> && xcodebuild test -scheme <App> \
  -destination "platform=iOS Simulator,name=iPhone 16"'
```

Notes:

- The Simulator runs headless under CoreSimulator — no GUI session or Simulator.app
  needed for boot/install/launch/screenshot, and **no code signing** is required
  for simulator builds.
- `simctl` can also drive the app for testing: `simctl openurl` (deep links),
  `simctl push` (simulated push notifications), `simctl status_bar override`
  (clean screenshots), `simctl privacy` (grant photo/location permissions).
- For interaction-level UI verification beyond screenshots, use XCUITest via
  `xcodebuild test` rather than trying to tap the simulator remotely.

## Out of scope (future work, separate decisions)

- **On-device testing** — needs an Apple ID added in Xcode's GUI once (free
  personal team suffices); afterwards device builds work over CLI with
  `xcodebuild -allowProvisioningUpdates`.
- **TestFlight / App Store distribution** — needs the paid Apple Developer
  Program and signing assets; spec separately when the first app is ready.
- **CI-style automation** (Fastlane, scheduled builds) — not needed for the
  initial single-developer loop.

## Acceptance checklist

1. `ssh macmini 'xcodebuild -version'` succeeds from this box with no password.
2. `~/macmini-dev` mount survives an editing round-trip (create, edit, delete a file).
3. A "Hello World" SwiftUI app created entirely from this box builds via
   `xcodebuild`, launches in the simulator, and a screenshot of it is viewed
   locally on this box.
4. `xcodebuild test` runs a trivial XCTest to completion.
5. Connection details + command crib sheet saved to Claude memory
   (`reference_mac_mini_ios.md`).
