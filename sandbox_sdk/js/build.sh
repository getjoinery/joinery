#!/usr/bin/env bash
#
# build.sh - compile a JavaScript sandboxed plugin into its module.
#
#   bash build.sh PLUGIN_JS OUT_WASM
#
# PLUGIN_JS defines the exports it answers (on_ui, on_signal, on_schedule) and
# ends with `module.exports = { on_ui, … }`. Needs extism-js and binaryen's
# wasm-merge and wasm-opt on the PATH (https://github.com/extism/js-pdk).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ $# -eq 2 ]] || { echo "usage: build.sh PLUGIN_JS OUT_WASM" >&2; exit 2; }
command -v extism-js >/dev/null || { echo "build.sh: extism-js is not on the PATH" >&2; exit 1; }

# The exports are the ones module.exports names, among the three a plugin may
# answer; extism-js needs the interface to name exactly those.
EXPORTS="$(grep -oE 'module\.exports[[:space:]]*=[[:space:]]*\{[^}]*\}' "$1" | grep -oE '\bon_(ui|signal|schedule)\b' | sort -u)"
[[ -n "${EXPORTS}" ]] || { echo "build.sh: $1 has no module.exports = { on_ui, on_signal, on_schedule }" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
cat "${HERE}/joinery-sdk.js" "$1" > "${WORK}/plugin.js"
{
    cat "${HERE}/joinery.d.ts"
    echo 'declare module "main" {'
    for e in ${EXPORTS}; do echo "  export function ${e}(): I32;"; done
    echo '}'
} > "${WORK}/plugin.d.ts"
extism-js "${WORK}/plugin.js" -i "${WORK}/plugin.d.ts" -o "$2"
echo "built: $2 (exports: $(echo ${EXPORTS}))"
