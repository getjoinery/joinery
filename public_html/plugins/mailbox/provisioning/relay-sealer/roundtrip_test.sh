#!/usr/bin/env bash
#
# roundtrip_test.sh - CI gate for the relay sealer's wire compatibility.
#
# Proves the pentest-brief invariant: a message sealed by the Go binary
# (box.SealAnonymous) opens with PHP sodium_crypto_box_seal_open via the same
# SealedBox::openDek the deferred-ingest consumer uses. If this passes, sealed
# spool blobs the relay writes are guaranteed openable by the main box.
#
# And the browser format (key_kind=client, specs/client_custody_mail.md WP7):
# the DEK the sealer puts in .meta opens with SealedBox::openEdge and the .seal
# body with aeadDecryptGcm under AD mail:relay:{spool_id} — the same bytes
# vault-crypto.js opens.
#
# Usage:  bash roundtrip_test.sh
# Requires: go, php with ext-sodium.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEALEDBOX="$(cd "${SCRIPT_DIR}/../../../.." && pwd)/includes/SealedBox.php"

if [[ ! -f "${SEALEDBOX}" ]]; then
    echo "FAIL: SealedBox.php not found at ${SEALEDBOX}" >&2
    exit 1
fi

command -v go >/dev/null || { echo "FAIL: go toolchain not found" >&2; exit 1; }
php -r 'exit(extension_loaded("sodium")?0:1);' || { echo "FAIL: php ext-sodium missing" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

echo "== building relay-sealer =="
( cd "${SCRIPT_DIR}" && go build -o "${WORK}/relay-sealer" . )

echo "== go unit tests =="
( cd "${SCRIPT_DIR}" && go vet ./... && go test ./... )

echo "== generating a vault keypair (PHP SealedBox) =="
WORK="${WORK}" SEALEDBOX="${SEALEDBOX}" php -r '
require getenv("SEALEDBOX");
$box = new SealedBox();
$kp = $box->generateKeypair();
file_put_contents(getenv("WORK")."/pub", $kp["public"]);
file_put_contents(getenv("WORK")."/sec", $kp["secret"]);
'
PUB="$(cat "${WORK}/pub")"

echo "== generating a browser-format keypair (standard base64 public key) =="
WORK="${WORK}" php -r '
$kp = sodium_crypto_box_keypair();
file_put_contents(getenv("WORK")."/cpub", base64_encode(sodium_crypto_box_publickey($kp)));
file_put_contents(getenv("WORK")."/csec", rtrim(strtr(base64_encode(sodium_crypto_box_secretkey($kp)), "+/", "-_"), "="));
'
CPUB="$(cat "${WORK}/cpub")"

echo "== writing routing map =="
cat > "${WORK}/routing.json" <<JSON
{
  "version": 7,
  "generated_utc": "2026-07-07T00:00:00Z",
  "srs_secret": "",
  "recipients": {
    "vault@relay.test": {
      "public_key": "${PUB}",
      "key_kind": "user",
      "mode": "store",
      "destinations": [],
      "forwarding_domain": "relay.test"
    },
    "fortress@relay.test": {
      "public_key": "${CPUB}",
      "key_kind": "client",
      "key_scope": "mail",
      "key_generation": 2,
      "mode": "store",
      "destinations": [],
      "forwarding_domain": "relay.test"
    }
  },
  "domains": {
    "relay.test": { "catch_all_mode": "none", "reject_unmatched": true }
  }
}
JSON

MSG=$'From: alice@example.com\r\nTo: vault@relay.test\r\nMessage-ID: <abc123@example.com>\r\nSubject: hush\r\n\r\nThe body that must survive the seal.\r\n'

echo "== running the sealer =="
mkdir -p "${WORK}/spool"
printf '%s' "${MSG}" | \
    JOINERY_RELAY_ROUTING="${WORK}/routing.json" \
    JOINERY_RELAY_SPOOL="${WORK}/spool" \
    "${WORK}/relay-sealer" vault@relay.test alice@example.com

SEAL_FILE="$(find "${WORK}/spool" -maxdepth 1 -name '*.seal' | head -1)"
META_FILE="$(find "${WORK}/spool" -maxdepth 1 -name '*.meta' | head -1)"
[[ -n "${SEAL_FILE}" ]] || { echo "FAIL: no .seal produced" >&2; exit 1; }
[[ -n "${META_FILE}" ]] || { echo "FAIL: no .meta produced" >&2; exit 1; }

echo "== opening the seal with PHP sodium_crypto_box_seal_open (SealedBox::openDek) =="
SEAL_FILE="${SEAL_FILE}" META_FILE="${META_FILE}" WORK="${WORK}" EXPECT="${MSG}" SEALEDBOX="${SEALEDBOX}" php -r '
require getenv("SEALEDBOX");
$box = new SealedBox();
$sealed = trim(file_get_contents(getenv("SEAL_FILE")));
$secret = trim(file_get_contents(getenv("WORK")."/sec"));
$plain  = $box->openDek($sealed, $secret);
$expect = getenv("EXPECT");
if ($plain !== $expect) {
    fwrite(STDERR, "FAIL: decrypted plaintext does not match original\n");
    exit(1);
}
$meta = json_decode(file_get_contents(getenv("META_FILE")), true);
if (($meta["recipient"] ?? "") !== "vault@relay.test" || ($meta["key_kind"] ?? "") !== "user") {
    fwrite(STDERR, "FAIL: .meta sidecar missing expected fields\n");
    exit(1);
}
if (isset($meta["subject"])) {
    fwrite(STDERR, "FAIL: .meta must not carry the subject (deferred-parse invariant)\n");
    exit(1);
}
echo "OK: seal round-trips and .meta carries only operational metadata\n";
'

echo "== running the sealer for a client-custody (Fortress) recipient =="
mkdir -p "${WORK}/cspool"
printf '%s' "${MSG}" | \
    JOINERY_RELAY_ROUTING="${WORK}/routing.json" \
    JOINERY_RELAY_SPOOL="${WORK}/cspool" \
    "${WORK}/relay-sealer" fortress@relay.test alice@example.com

CSEAL_FILE="$(find "${WORK}/cspool" -maxdepth 1 -name '*.seal' | head -1)"
CMETA_FILE="$(find "${WORK}/cspool" -maxdepth 1 -name '*.meta' | head -1)"
[[ -n "${CSEAL_FILE}" && -n "${CMETA_FILE}" ]] || { echo "FAIL: no client .seal/.meta produced" >&2; exit 1; }

echo "== opening the browser format with SealedBox::openEdge + aeadDecryptGcm =="
CSEAL_FILE="${CSEAL_FILE}" CMETA_FILE="${CMETA_FILE}" WORK="${WORK}" EXPECT="${MSG}" SEALEDBOX="${SEALEDBOX}" php -r '
require getenv("SEALEDBOX");
$box  = new SealedBox();
$meta = json_decode(file_get_contents(getenv("CMETA_FILE")), true);
$body = trim(file_get_contents(getenv("CSEAL_FILE")));
if (($meta["key_kind"] ?? "") !== "client" || ($meta["key_scope"] ?? "") !== "mail" || ($meta["key_generation"] ?? 0) !== 2) {
    fwrite(STDERR, "FAIL: client .meta missing key_kind/key_scope/key_generation\n");
    exit(1);
}
$prefix = "v1.edgeseal.mail.";
if (strncmp($meta["sealed_dek"] ?? "", $prefix, strlen($prefix)) !== 0 || strncmp($body, "v1.edge.", 8) !== 0) {
    fwrite(STDERR, "FAIL: client artifacts are not framed v1.edgeseal.mail. / v1.edge.\n");
    exit(1);
}
$dek = $box->openEdge(substr($meta["sealed_dek"], strlen($prefix)), trim(file_get_contents(getenv("WORK")."/csec")),
    trim(file_get_contents(getenv("WORK")."/cpub")));
$plain = $box->aeadDecryptGcm(substr($body, 8), $dek, "mail:relay:" . $meta["spool_id"]);
if ($plain !== getenv("EXPECT")) {
    fwrite(STDERR, "FAIL: the browser-format body does not open to the original\n");
    exit(1);
}
echo "OK: the browser format opens with the PHP (and so the browser) primitives\n";
'

echo "PASS: relay-sealer wire-compatible with SealedBox::openDek and SealedBox::openEdge"
