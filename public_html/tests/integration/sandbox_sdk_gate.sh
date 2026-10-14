#!/bin/bash
# @joinery-test
# name: sandbox_sdk
# tier: safe
# env: dev-only
# needs: []
# timeout: 600
#
# The sandboxed plugin author kit (sandbox_sdk/, specs/plugin_tiers.md WP8):
# the Rust example builds against the Rust crate, and the JavaScript example
# against the JS kit, and each module exports what its manifest needs and
# imports only what the plugin host gives (SandboxModule, the check install
# makes).
#
# It runs where releases are built (dev-only), and there both halves must run:
# a toolchain this machine lacks fails the gate rather than passing it. The
# JavaScript half's toolchain is pinned here (extism-js and binaryen, each
# checked against its published sha256) and fetched into a cache the first
# time; the Rust half needs cargo with the wasm32-unknown-unknown target.
set -uo pipefail

EXTISM_JS_VERSION="v1.7.0"
EXTISM_JS_SHA256="63b72da2f5e88655522dc21477de549f238a2f40546a69ce4e0fce7e78654035"
BINARYEN_VERSION="version_133"
BINARYEN_SHA256="2dc9c7813f5375db93d96ead4b78222fcc3e2677bbb832297af4797782a37489"
TOOLS="/var/tmp/joinery-sandbox-sdk-tools-$(id -u)"

# The pinned JS toolchain on the PATH, fetched once; prints why when it cannot be.
js_toolchain() {
    [ "$(uname -m)" = "x86_64" ] || { echo "the JS toolchain is pinned for x86_64 only"; return 1; }
    mkdir -p "${TOOLS}" || return 1
    local js="${TOOLS}/extism-js-${EXTISM_JS_VERSION}" bin="${TOOLS}/binaryen-${BINARYEN_VERSION}/bin"
    if [ ! -x "${js}" ]; then
        local gz="${TOOLS}/extism-js.gz"
        curl -fsSL --max-time 120 -o "${gz}" "https://github.com/extism/js-pdk/releases/download/${EXTISM_JS_VERSION}/extism-js-x86_64-linux-${EXTISM_JS_VERSION}.gz" \
            || { echo "extism-js ${EXTISM_JS_VERSION} could not be fetched"; return 1; }
        [ "$(sha256sum "${gz}" | cut -d' ' -f1)" = "${EXTISM_JS_SHA256}" ] || { rm -f "${gz}"; echo "extism-js did not match its pinned sha256"; return 1; }
        gunzip -c "${gz}" > "${js}.tmp" && chmod 755 "${js}.tmp" && mv "${js}.tmp" "${js}" && rm -f "${gz}"
    fi
    if [ ! -x "${bin}/wasm-merge" ]; then
        local tgz="${TOOLS}/binaryen.tar.gz"
        curl -fsSL --max-time 300 -o "${tgz}" "https://github.com/WebAssembly/binaryen/releases/download/${BINARYEN_VERSION}/binaryen-${BINARYEN_VERSION}-x86_64-linux.tar.gz" \
            || { echo "binaryen ${BINARYEN_VERSION} could not be fetched"; return 1; }
        [ "$(sha256sum "${tgz}" | cut -d' ' -f1)" = "${BINARYEN_SHA256}" ] || { rm -f "${tgz}"; echo "binaryen did not match its pinned sha256"; return 1; }
        tar -xzf "${tgz}" -C "${TOOLS}" && rm -f "${tgz}"
    fi
    mkdir -p "${TOOLS}/path" && ln -sf "${js}" "${TOOLS}/path/extism-js"
    PATH="${TOOLS}/path:${bin}:${PATH}"
    command -v extism-js >/dev/null && command -v wasm-merge >/dev/null
}

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SDK="${HERE}/../../../sandbox_sdk"
PUBLIC_HTML="${HERE}/../.."
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

pass=0
fail=0
skip=0
ok()   { echo "  PASS: $1"; pass=$((pass+1)); }
bad()  { echo "  FAIL: $1"; fail=$((fail+1)); }

# The module at $1 against the manifest at $2, as install judges it.
judge() {
    php -r 'require $argv[1] . "/includes/PathHelper.php";
        $m = SandboxManifest::fromFile($argv[3]);
        $p = SandboxModule::fromFile($argv[2])->problems($m);
        echo $p ? implode(" | ", $p) : "ok";' "${PUBLIC_HTML}" "$1" "$2" 2>&1 | tail -n1
}

echo "== The Rust example =="
if command -v cargo >/dev/null 2>&1 && rustup target list --installed 2>/dev/null | grep -q wasm32-unknown-unknown; then
    if (cd "${SDK}/examples/notifier" && CARGO_TARGET_DIR="${WORK}/rust" nice -n 19 cargo build --quiet --release --target wasm32-unknown-unknown >/dev/null 2>&1); then
        ok "the notifier builds against joinery-plugin"
        verdict="$(judge "${WORK}/rust/wasm32-unknown-unknown/release/notifier.wasm" "${SDK}/examples/notifier/manifest.json")"
        [ "${verdict}" = "ok" ] && ok "and is what its manifest says, asking the host for nothing else" || bad "the notifier module: ${verdict}"
    else
        bad "the notifier does not build"
        (cd "${SDK}/examples/notifier" && CARGO_TARGET_DIR="${WORK}/rust" cargo build --release --target wasm32-unknown-unknown 2>&1 | tail -20)
    fi
else
    bad "no cargo with the wasm32-unknown-unknown target here (rustup target add wasm32-unknown-unknown)"
fi

echo "== The JavaScript example =="
# Run here, not in a subshell: it puts the toolchain on this shell's PATH.
if js_toolchain > "${WORK}/js_why" 2>&1; then
    if bash "${SDK}/js/build.sh" "${SDK}/examples/greeter-js/plugin.js" "${WORK}/greeter.wasm" >/dev/null 2>&1; then
        ok "the greeter builds with the JS kit"
        verdict="$(judge "${WORK}/greeter.wasm" "${SDK}/examples/greeter-js/manifest.json")"
        [ "${verdict}" = "ok" ] && ok "and is what its manifest says, asking the host for nothing else" || bad "the greeter module: ${verdict}"
    else
        bad "the greeter does not build"
    fi
else
    bad "the JS toolchain is not here: $(cat "${WORK}/js_why")"
fi

for f in rust/joinery-plugin/src/lib.rs js/joinery-sdk.js js/joinery.d.ts js/build.sh README.md; do
    [ -f "${SDK}/${f}" ] || bad "sandbox_sdk/${f} is missing"
done
bash -n "${SDK}/js/build.sh" && ok "the kit's files are there"

echo
echo "================================"
echo "sandbox_sdk [safe]"
echo "PASSED: ${pass}   FAILED: ${fail}   SKIPPED: ${skip}   ($((pass+fail)) checks)"
[ "${fail}" -eq 0 ] || exit 1
exit 0
