#!/bin/bash
# Build "Joinery Drive.app" (universal2) and the .dmg it ships in. Runs on a Mac.
#
#   bash sync/packaging/macos/build.sh       -> sync/target/packaging/macos/
#
# Two artifacts: the .dmg a person installs from, and the update payload the
# installed client's updater applies (`joinery-drive-release pack` of the
# finished app). The feed manifest that lists them, and its signature, are the
# publisher's step (docs/drive_sync.md § Updates).
#
# The app is a menu-bar app (LSUIElement): its main executable is the tray,
# `joinery-drive-tray`, and the daemon `joinery-drive` sits beside it in
# Contents/MacOS. Beside, because the tray starts the daemon from its own
# directory, and because `joinery-drive autostart on` registers whatever path it
# is run from — so the LaunchAgent names the copy inside the installed app.
#
# Signing is ad hoc unless an identity is configured, which is enough for the
# app to run on Apple Silicon (the kernel refuses unsigned arm64 code) and not
# enough for Gatekeeper to open a downloaded copy without the user approving it
# in System Settings. With a Developer ID the same script signs with the
# hardened runtime, notarizes, and staples:
#
#   MACOS_SIGN_IDENTITY   "Developer ID Application: Name (TEAMID)"
#   MACOS_NOTARY_PROFILE  a notarytool keychain profile, made once with
#                         `xcrun notarytool store-credentials`; the credentials
#                         stay in the keychain, never in the environment
#
# Optional: OUT_DIR, CARGO_TARGET_DIR, CARGO_BUILD_JOBS.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JD_SYNC_DIR="$(cd "$HERE/../.." && pwd)"
export PATH="$HOME/.cargo/bin:$PATH"
# shellcheck source=../version.sh
source "$HERE/../version.sh"

APP_NAME="Joinery Drive"
# The identifier Apple registers against the Developer ID. jd-platform's
# BUNDLE_ID (com.joinery.drive) names the LaunchAgent, the support folders and
# the keychain service, which are already on disk on every Mac running the
# client; see the packaging notes before making the two agree.
BUNDLE_ID="com.getjoinery.drive"
# The oldest macOS the binaries are linked for. One value for both slices,
# because Info.plist can only state one.
MIN_MACOS="11.0"
TARGETS=(aarch64-apple-darwin x86_64-apple-darwin)

TARGET_DIR="${CARGO_TARGET_DIR:-$JD_SYNC_DIR/target}"
OUT="${OUT_DIR:-$TARGET_DIR/packaging/macos}"
STAGE="$OUT/stage"
APP="$STAGE/$APP_NAME.app"
DMG="$OUT/JoineryDrive-$JD_VERSION-macos-universal.dmg"
PAYLOAD="$OUT/JoineryDrive-$JD_VERSION-macos-universal.update.tar.gz"
# The release tool runs on this Mac, so the slice for this Mac's processor.
case "$(uname -m)" in
	arm64) HOST_TARGET=aarch64-apple-darwin ;;
	*) HOST_TARGET=x86_64-apple-darwin ;;
esac

if [ -n "${MACOS_NOTARY_PROFILE:-}" ] && [ -z "${MACOS_SIGN_IDENTITY:-}" ]; then
	echo "error: notarization needs a Developer ID; set MACOS_SIGN_IDENTITY too" >&2
	exit 1
fi

echo "=== Joinery Drive $JD_VERSION for macOS (universal2) ==="

for target in "${TARGETS[@]}"; do
	if ! rustup target list --installed | grep -qx "$target"; then
		echo "error: the $target standard library is not installed: rustup target add $target" >&2
		exit 1
	fi
	echo "--- cargo build $target ---"
	MACOSX_DEPLOYMENT_TARGET="$MIN_MACOS" cargo build --release --locked \
		--manifest-path "$JD_SYNC_DIR/Cargo.toml" --target-dir "$TARGET_DIR" \
		--target "$target" -p jd-daemon -p jd-shell
done

rm -rf "$STAGE"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"

echo "--- lipo ---"
for bin in joinery-drive joinery-drive-tray; do
	lipo -create -output "$APP/Contents/MacOS/$bin" \
		"$TARGET_DIR/aarch64-apple-darwin/release/$bin" \
		"$TARGET_DIR/x86_64-apple-darwin/release/$bin"
	lipo "$APP/Contents/MacOS/$bin" -verify_arch arm64 x86_64
done
jd_check_binary_version "$APP/Contents/MacOS/joinery-drive" joinery-drive
jd_check_binary_version "$APP/Contents/MacOS/joinery-drive-tray" joinery-drive-tray

# The app icon, drawn from the same SVG the Linux packages install. sips renders
# SVG at the size the file declares, with a transparent background, so the copy
# it renders from is told it is 1024 pixels square.
echo "--- icon ---"
ICONSET="$OUT/JoineryDrive.iconset"
rm -rf "$ICONSET"
mkdir -p "$ICONSET"
sed -E 's/ width="[0-9]+" height="[0-9]+"/ width="1024" height="1024"/' \
	"$HERE/../icons/joinery-drive.svg" >"$OUT/icon-1024.svg"
sips -s format png "$OUT/icon-1024.svg" --out "$OUT/icon-1024.png" >/dev/null
for size in 16 32 128 256 512; do
	sips -z "$size" "$size" "$OUT/icon-1024.png" --out "$ICONSET/icon_${size}x${size}.png" >/dev/null
	double=$((size * 2))
	sips -z "$double" "$double" "$OUT/icon-1024.png" --out "$ICONSET/icon_${size}x${size}@2x.png" >/dev/null
done
iconutil -c icns -o "$APP/Contents/Resources/JoineryDrive.icns" "$ICONSET"
rm -rf "$ICONSET" "$OUT/icon-1024.svg" "$OUT/icon-1024.png"

sed -e "s/@BUNDLE_ID@/$BUNDLE_ID/" \
	-e "s/@VERSION_NUMERIC@/$JD_VERSION_NUMERIC/g" \
	-e "s/@VERSION@/$JD_VERSION/" \
	-e "s/@MIN_MACOS@/$MIN_MACOS/" \
	"$HERE/Info.plist.in" >"$APP/Contents/Info.plist"
plutil -lint "$APP/Contents/Info.plist" >/dev/null
printf 'APPL????' >"$APP/Contents/PkgInfo"

# Inside out: the daemon first, then the bundle, whose signature seals the
# daemon's. `--deep` would sign in an order nobody chose.
echo "--- codesign ---"
if [ -n "${MACOS_SIGN_IDENTITY:-}" ]; then
	sign=(codesign --force --options runtime --timestamp --sign "$MACOS_SIGN_IDENTITY")
else
	sign=(codesign --force --sign -)
fi
# The daemon gets an identifier of its own: a signature's identifier is what a
# keychain item's access list and a notarization log name it by, and the file
# name alone is not unique to this product.
"${sign[@]}" --identifier "$BUNDLE_ID.daemon" "$APP/Contents/MacOS/joinery-drive"
"${sign[@]}" "$APP"
codesign --verify --strict --verbose=2 "$APP"

notarize() {
	xcrun notarytool submit "$1" --keychain-profile "$MACOS_NOTARY_PROFILE" --wait
}

if [ -n "${MACOS_NOTARY_PROFILE:-}" ]; then
	# The app is notarized and stapled on its own first, so the copy a user
	# drags out of the disk image carries its ticket and opens offline.
	echo "--- notarize the app ---"
	ditto -c -k --keepParent "$APP" "$OUT/notarize-app.zip"
	notarize "$OUT/notarize-app.zip"
	xcrun stapler staple "$APP"
	rm -f "$OUT/notarize-app.zip"
fi

# Packed after signing and stapling, so the copy the updater puts in place is
# byte for byte the one notarized; unpacking it must still verify.
echo "--- update payload ---"
"$TARGET_DIR/$HOST_TARGET/release/joinery-drive-release" pack --out "$PAYLOAD" "$APP"
UNPACKED="$OUT/payload-check"
rm -rf "$UNPACKED"
"$TARGET_DIR/$HOST_TARGET/release/joinery-drive-release" unpack --out "$UNPACKED" "$PAYLOAD" >/dev/null
codesign --verify --deep --strict "$UNPACKED/$APP_NAME.app"
rm -rf "$UNPACKED"

echo "--- dmg ---"
ln -s /Applications "$STAGE/Applications"
rm -f "$DMG"
hdiutil create -quiet -volname "$APP_NAME" -srcfolder "$STAGE" -fs HFS+ -format UDZO -ov "$DMG"
if [ -n "${MACOS_SIGN_IDENTITY:-}" ]; then
	codesign --force --timestamp --sign "$MACOS_SIGN_IDENTITY" "$DMG"
fi
if [ -n "${MACOS_NOTARY_PROFILE:-}" ]; then
	echo "--- notarize the dmg ---"
	notarize "$DMG"
	xcrun stapler staple "$DMG"
fi
hdiutil verify -quiet "$DMG"

(cd "$OUT" && shasum -a 256 "$(basename "$DMG")" "$(basename "$PAYLOAD")" >SHA256SUMS)
echo "built: $DMG"
echo "       $PAYLOAD"
echo "       $APP"
