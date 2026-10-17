# Joinery Drive packaging

One script per operating system turns the workspace into the installable
artifacts for that OS. Each runs on the OS it builds for and writes to
`sync/target/packaging/<os>/` (override with `OUT_DIR`, or `-OutDir` on
Windows; `CARGO_TARGET_DIR` is honoured).

| OS | Script | Artifacts |
|---|---|---|
| macOS | `macos/build.sh` | `JoineryDrive-<v>-macos-universal.dmg` (universal2: arm64 + x86_64), `JoineryDrive-<v>-macos-universal.update.tar.gz` |
| Windows | `windows/build.ps1` | `JoineryDrive-<v>-windows-{x64,arm64}-setup.exe`, `JoineryDrive-<v>-windows-{x64,arm64}.update.tar.gz` |
| Linux | `linux/build.sh` | `joinery-drive_<v>-1_amd64.deb`, `joinery-drive-<v>-1.x86_64.rpm`, `joinery-drive-<v>-linux-x86_64.tar.gz`, `joinery-drive-<v>-linux-x86_64.update.tar.gz` |

Each output directory also gets a `SHA256SUMS`.

The `.update.tar.gz` files are update payloads: what an installed client's
updater downloads and applies, made with `joinery-drive-release pack` (built
with the daemon) from exactly what the installer carries — the signed and
stapled app on macOS, the two programs on Windows and in the tarball. A
`.deb`/`.rpm` install is updated by its package manager instead. The feed
manifest that lists installers and payloads, and its signature, are the
publisher's step, with the update key (docs/drive_sync.md § Updates); the
scripts sign nothing with it.

## The version

The workspace version in `sync/Cargo.toml` (`[workspace.package] version`) is
the only place a version is written: `MAJOR.MINOR.PATCH`, optionally
`-alpha.N`, `-beta.N` or `-rc.N`. Every crate inherits it; both programs print
it (`joinery-drive --version`, `joinery-drive-tray --version`) and the daemon
sends it to the server's upgrade gate as `client-version`. The scripts read it
through `cargo metadata` (`version.sh`, and the same lookup in `build.ps1`),
refuse to package a binary that reports any other version, and stamp it into:

- macOS: `CFBundleShortVersionString` and `CFBundleVersion` (numbers only), the
  full string as `JoineryDriveVersion`, and the file name;
- Windows: the installer's version resource, the Apps & Features
  `DisplayVersion`, and the file name;
- Linux: the package version (a pre-release becomes `0.1.0~beta.1`, which sorts
  before `0.1.0` in both dpkg and rpm) and the file names.

The upgrade gate tells platforms apart by `client-app`: `joinery-sync-macos`,
`joinery-sync-windows` or `joinery-sync-linux`, decided by the compile target
(`jd-proto` `CLIENT_APP`). `api_min_client_versions` can hold back one platform
without touching the other two.

## macOS

Prerequisites: Xcode command-line tools; rustup with `aarch64-apple-darwin` and
`x86_64-apple-darwin`.

```
bash sync/packaging/macos/build.sh
```

`Joinery Drive.app` is a menu-bar app (`LSUIElement`): its main executable is
the tray, `joinery-drive-tray`, and the daemon `joinery-drive` sits beside it in
`Contents/MacOS`. `joinery-drive autostart on`, run from the installed app,
writes two LaunchAgents: `com.joinery.drive` for the bundled daemon and
`com.joinery.drive.tray`, which opens the menu-bar app at each graphical login;
`autostart off` removes both. It refuses to register a copy macOS is running
from a translocated download, which disappears at restart. The
disk image holds the app and an `Applications` link to drag it onto. The binaries
are linked for macOS 11 and later. The app icon is rendered from
`icons/joinery-drive.svg` at build time.

From another machine, the established pattern is to copy the workspace to the
Mac and run the script there:

```
rsync -az --exclude target/ sync/ macmini:<dir>/
ssh macmini 'cd <dir> && bash packaging/macos/build.sh'
```

**Signing.** Without configuration the app is signed ad hoc, which Apple
Silicon requires for it to run at all; Gatekeeper still asks the user to approve
a downloaded copy in System Settings > Privacy & Security. With a Developer ID:

| Variable | Effect |
|---|---|
| `MACOS_SIGN_IDENTITY` | `Developer ID Application: Name (TEAMID)`: the daemon, the app and the dmg are signed with the hardened runtime and a secure timestamp |
| `MACOS_NOTARY_PROFILE` | a `notarytool` keychain profile (`xcrun notarytool store-credentials`): the app is notarized and stapled, then the dmg is notarized and stapled |

## Windows

Prerequisites: rustup with `x86_64-pc-windows-msvc` and
`aarch64-pc-windows-msvc`; Visual Studio 2022 Build Tools with the MSVC tools
for both architectures, the Windows SDK, and the Clang component (ring builds
its ARM64 assembly with clang; the script finds Visual Studio's copy); NSIS 3
(`makensis` on PATH, or `NSIS_HOME` naming its folder; the portable zip is
enough).

```
powershell -ExecutionPolicy Bypass -File sync\packaging\windows\build.ps1 [-Arch x64,arm64]
```

The installer is NSIS and per-user: no administrator, no UAC prompt. It
installs to `%LOCALAPPDATA%\Programs\Joinery Drive`, adds a Start menu entry,
registers an Apps & Features entry under `HKCU`, and on a first install runs
`joinery-drive autostart on`, which writes one logon entry
(`HKCU\...\Run\JoineryDrive`): the tray with `--start-daemon`, which starts the
daemon with no console window and then shows its icon. An upgrade leaves that
choice as the user set it. Uninstalling stops both programs, runs `autostart
off`, removes everything it installed, and leaves the synced folder, settings
and sync record alone.

Installing over, or uninstalling, a running copy waits for it: the daemon is
asked to stop (it stops at its next safe point, usually at once; hashing or
encrypting a large file holds it until that step ends), the tray is closed,
and the installer waits up to a minute for both files to be free. Still in
use, it asks to wait again or stop; a silent run stops, and exits with code 2.
Stopping changes no file and brings the tray back, but the stop already asked
of the daemon still lands, so the installer's message says: "No files were
changed. Joinery Drive will stop as soon as it safely can; start it again from
its icon's menu." After a silent
upgrade that completes, what was running is started again from the new files.
`AllowSkipFiles off`: a file that cannot be written is never skipped. An ARM64 installer refuses to run on an x64 PC; the x64
one runs anywhere, through emulation on ARM.

The tray's icon and version resource (the name and publisher Explorer and Task
Manager show) come from `jd-shell`'s build script, which draws the app icon
from `icons/joinery-drive.svg` into a multi-size `.ico` and links it into
`joinery-drive-tray.exe`; `build.ps1` hands the same `.ico` to the installer.
`jd-daemon`'s build script gives `joinery-drive.exe` a version resource of its
own ("Joinery Drive sync", what Task Manager shows for the background
process). Both resource files are written by `windows/resource.rs`.

**Signing.** Set `WINDOWS_SIGN_CERT_SHA1` to the thumbprint of a code-signing
certificate in the current user's store (optional: `WINDOWS_SIGN_TIMESTAMP_URL`,
`SIGNTOOL`). Both programs are then signed before they are packed, the
uninstaller while makensis writes it, and the installer last. Unsigned, the
installer works, and SmartScreen warns on a downloaded copy until the user
chooses "Run anyway".

## Linux

Prerequisites: rustup with `x86_64-unknown-linux-musl`; a C compiler for musl
(`musl-gcc`, from the `musl-tools` package on Debian and Ubuntu, or set
`CC_x86_64_unknown_linux_musl`); `curl` and `sha256sum` for the first run, which
fetches a pinned, checksum-verified nfpm (or set `NFPM`). No root.

```
bash sync/packaging/linux/build.sh
```

Every file in every Linux artifact carries one time, the last commit's
(`SOURCE_DATE_EPOCH`, read from git when not set; the epoch outside a
checkout), so building the same commit twice gives the same bytes.

The binaries are static (musl), so the same two files serve the tarball, the
.deb and the .rpm, run on any x86_64 distribution, and the packages declare no
library dependencies. The .deb and the .rpm come from one nfpm description and
install:

| Path | What |
|---|---|
| `/usr/bin/joinery-drive`, `/usr/bin/joinery-drive-tray` | the daemon and command line, the tray |
| `/usr/lib/systemd/user/joinery-drive.service` | the daemon's user unit, printed by the same function `joinery-drive autostart on` uses (`jd-platform` example `systemd_unit`) |
| `/usr/share/applications/joinery-drive.desktop` | the menu entry |
| `/usr/share/icons/hicolor/scalable/apps/joinery-drive*.svg` | the app icon and the tray's four state icons |

Nothing starts at login for an account until that user asks: `joinery-drive
autostart on` (or "start at login" in the setup) enables the packaged unit for
them and writes their own `~/.config/autostart/joinery-drive-tray.desktop` for
the tray; `autostart off` removes both. `systemctl --user enable --now
joinery-drive` also works on the packaged unit. The tarball holds the two
binaries, the menu entry, the icons and an `INSTALL.txt`.

**Signing.** The packages are unsigned; an apt or dnf repository signs its
metadata, which is where Linux package managers check.

## Icons

`icons/` holds the SVG sources, and the only copy of the art:
`joinery-drive.svg` (the app) and `joinery-drive-{idle,busy,attention,stopped}.svg`
(the tray's states). The Linux packages install them for icon-theme lookup;
the macOS build renders the app icon into `JoineryDrive.icns` with `sips`;
`jd-shell`'s build script renders the state icons into the tray (Windows draws
them, Linux offers them beside the theme names) and, for Windows, the app icon
and version resource. Nothing rendered is committed.
