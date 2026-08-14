#!/bin/bash
#
# Persona Browser — Mac setup
# ===========================
# Run this on the Mac that will hold the logged-in browser session. It:
#   1. installs a self-contained Node runtime under ~/persona-browser/node,
#   2. copies the reader service and its helpers into ~/persona-browser,
#   3. installs Playwright + the Firefox engine,
#   4. writes config.json (binding to your Tailscale address) with a token,
#   5. installs a launchd agent that keeps the service running and starts it,
#   6. prints the endpoint + token to paste into the Joinery plugin settings.
#
# What this does NOT do (on purpose): log you into the site. That needs a real
# window and a human — run ./login.sh once after this finishes.
#
# Re-running is safe: an existing token is kept, so you don't have to re-paste
# it into Joinery. Override the install location with PERSONA_BROWSER_DIR=... .

set -euo pipefail

NODE_VERSION="v24.19.0"
PORT=8899
LABEL="com.joinery.personabrowser"
APP_DIR="${PERSONA_BROWSER_DIR:-$HOME/persona-browser}"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
PLIST="$HOME/Library/LaunchAgents/$LABEL.plist"

echo "==> Persona Browser setup  ($APP_DIR)"

# --- 1. platform detection --------------------------------------------------
case "$(uname -m)" in
  arm64)  NODE_ARCH="darwin-arm64" ;;
  x86_64) NODE_ARCH="darwin-x64" ;;
  *) echo "Unsupported CPU: $(uname -m)"; exit 1 ;;
esac

# Bind to the Tailscale address so only your tailnet can reach the service.
find_tailscale_ip() {
  if command -v tailscale >/dev/null 2>&1; then
    tailscale ip -4 2>/dev/null | head -1 && return
  fi
  /Applications/Tailscale.app/Contents/MacOS/Tailscale ip -4 2>/dev/null | head -1
}
BIND_IP="$(find_tailscale_ip || true)"
if [ -z "${BIND_IP:-}" ]; then
  echo "!! Could not find a Tailscale IP (is Tailscale running and logged in?)."
  echo "!! Binding to 127.0.0.1 for now — edit \"bind\" in $APP_DIR/config.json"
  echo "!! to your tailnet address so Joinery can reach it."
  BIND_IP="127.0.0.1"
fi

mkdir -p "$APP_DIR"

# --- 2. self-contained Node -------------------------------------------------
if [ "$("$APP_DIR/node/bin/node" --version 2>/dev/null || true)" != "$NODE_VERSION" ]; then
  echo "==> Downloading Node $NODE_VERSION ($NODE_ARCH)"
  TARBALL="node-$NODE_VERSION-$NODE_ARCH.tar.xz"
  curl -fsSL "https://nodejs.org/dist/$NODE_VERSION/$TARBALL" -o "/tmp/$TARBALL"
  rm -rf "$APP_DIR/node"
  mkdir -p "$APP_DIR/node"
  tar -xJf "/tmp/$TARBALL" -C "$APP_DIR/node" --strip-components=1
  rm -f "/tmp/$TARBALL"
else
  echo "==> Node $NODE_VERSION already present"
fi
export PATH="$APP_DIR/node/bin:$PATH"

# --- 3. service files -------------------------------------------------------
echo "==> Installing service files"
for f in server.js login.js login.sh read_feed.js package.json; do
  cp "$SRC_DIR/$f" "$APP_DIR/$f"
done
chmod +x "$APP_DIR/login.sh"

# --- 4. dependencies + Firefox engine --------------------------------------
echo "==> Installing dependencies (this downloads Playwright's Firefox)"
( cd "$APP_DIR" && npm install --no-audit --no-fund && npx --yes playwright install firefox )

# --- 5. config.json (keep an existing token) --------------------------------
TOKEN=""
if [ -f "$APP_DIR/config.json" ]; then
  TOKEN="$("$APP_DIR/node/bin/node" -e 'try{process.stdout.write(String(require(process.argv[1]).token||""))}catch(e){}' "$APP_DIR/config.json")"
fi
NEW_TOKEN=0
if [ -z "$TOKEN" ]; then
  TOKEN="$(openssl rand -hex 32)"
  NEW_TOKEN=1
fi

cat > "$APP_DIR/config.json" <<JSON
{
  "bind": "$BIND_IP",
  "port": $PORT,
  "token": "$TOKEN",
  "profilesDir": "$APP_DIR/profiles",
  "personas": {
    "facebook": { "url": "https://www.facebook.com/", "loginUrl": "https://www.facebook.com/" }
  }
}
JSON
chmod 600 "$APP_DIR/config.json"

# --- 6. launchd agent -------------------------------------------------------
echo "==> Installing launchd agent"
mkdir -p "$(dirname "$PLIST")"
cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>            <string>$LABEL</string>
  <key>ProgramArguments</key>
  <array>
    <string>$APP_DIR/node/bin/node</string>
    <string>$APP_DIR/server.js</string>
  </array>
  <key>WorkingDirectory</key> <string>$APP_DIR</string>
  <key>RunAtLoad</key>        <true/>
  <key>KeepAlive</key>        <true/>
  <key>StandardOutPath</key>  <string>$APP_DIR/server.log</string>
  <key>StandardErrorPath</key><string>$APP_DIR/server.log</string>
</dict>
</plist>
PLIST

launchctl bootout "gui/$(id -u)/$LABEL" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$PLIST"
launchctl kickstart -k "gui/$(id -u)/$LABEL" 2>/dev/null || true

# --- 7. health check + instructions ----------------------------------------
sleep 2
echo "==> Health check"
if curl -fsS "http://$BIND_IP:$PORT/health" >/dev/null 2>&1; then
  echo "   service is up on http://$BIND_IP:$PORT"
else
  echo "   service not answering yet — check $APP_DIR/server.log"
fi

cat <<DONE

==================================================================
 Persona Browser service installed.

 In Joinery, open the Persona Browser plugin settings and set:
   Service Endpoint:  http://$BIND_IP:$PORT
   Service Token:     $TOKEN
DONE
if [ "$NEW_TOKEN" = "0" ]; then
  echo "   (existing token kept — no need to change it in Joinery)"
fi
cat <<DONE

 Then log into the site ONCE, in a real window on this Mac:
   cd $APP_DIR && ./login.sh
 Log in, clear any 2FA, wait until your feed shows, close the window.

 Check a login stuck with:  cd $APP_DIR && node read_feed.js
==================================================================
DONE
