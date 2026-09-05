#!/usr/bin/env bash
#
# direct_wire_gate.sh — prove Go and PHP sign the SAME BYTES.
#
# This is the one check that matters most about the relay's Direct support, and
# the one nothing else can catch. An instance signature is only worth anything
# if both ends agree byte for byte on what was covered, and a drift between
# includes/joinery_direct/DirectProtocol.php and direct_protocol.go would not
# throw anywhere: it would simply make every delivery from a Joinery box fail
# signature verification at the relay, which a sender reads as "the peer is
# unreachable" and silently downgrades to SMTP. Mail would keep flowing, nothing
# would be marked verified, and nobody would notice.
#
# So: PHP emits the signing bytes for a fixed envelope and manifest, Go emits
# them for the same input, and the two are diffed. Also checked in the same run:
# a signature Go makes verifies in PHP and vice versa, over both byte-forms.
#
# The RELAY API's envelope (relay_protocol.go against RelayProtocol.php) is
# pinned here too, by its own fixture: a drift there would fail every spool pull
# silently, which is the failure this relay has had before.
#
# Run from anywhere:
#   bash direct_wire_gate.sh
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PUBLIC_HTML="$(cd "${HERE}/../../../.." && pwd)"
WORK="$(mktemp -d)"
# The throwaway Go test below is written INTO the package directory, so it goes
# on the trap too: a run killed mid-`go test` (or one that fails under set -e)
# must not leave a file behind that the next run, as another user, cannot
# overwrite.
trap 'rm -rf "${WORK}"; rm -f "${HERE}/zz_wire_gate_test.go"' EXIT

command -v go >/dev/null   || { echo "SKIP: no go toolchain"; exit 0; }
command -v php >/dev/null  || { echo "SKIP: no php"; exit 0; }

# The fixture. Deliberately awkward: mixed case (must lowercase), an uppercase
# NON-ASCII letter in each address (PHP's strtolower is byte-wise ASCII-only and
# leaves it; Go's strings.ToLower would fold it, so case-folding must be ASCII-only
# on both sides or these addresses sign differently), a slash and an ampersand in a
# filename (PHP does not escape them and Go does by default), a non-ASCII character
# in the filename (neither may \u-escape it), and an empty second manifest entry
# (an empty string must stay "" on both sides).
cat > "${WORK}/fixture.json" <<'JSON'
{
  "envelope": {
    "protocol_version": 1,
    "kind": "mail",
    "sender": "Île.SÉNDER@Exämple.COM",
    "recipient": "İbrahim.BOB@Receiver.TEST",
    "key_id": "k1",
    "nonce": "abcdef0123456789abcdef0123456789",
    "timestamp": "2026-08-12 10:00:00"
  },
  "manifest": [
    {"role":"body_text","content_type":"text/plain; charset=utf-8","filename":"","content_id":"","is_inline":false,"size":12},
    {"role":"attachment","content_type":"application/pdf","filename":"a/b & c — ü.pdf","content_id":"cid1","is_inline":true,"size":900}
  ],
  "nonce": "abcdef0123456789abcdef0123456789",
  "hashes": ["aa11","bb22"],
  "relay_envelope": {
    "protocol_version": 1,
    "tenant": "main",
    "method": "get",
    "request_uri": "/relay/spool/1757080000-0a1b.seal?after=17570%2Fx&limit=200&note=a/b&ü",
    "body_sha256": "E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855",
    "nonce": "q83vASNFZ4mrze/+ASNFZw==",
    "timestamp": "2026-09-05 14:03:11"
  },
  "birth_report": {
    "run_id": "17",
    "public_ip": "203.0.113.5",
    "identity_public_key": "MCowBQYDK2VwAyEAabcdefghijklmnopqrstuvwxyz0123456789ABCDEF=",
    "identity_fingerprint": "gkS4mL+/0Wc9lP7bQ2bXk8pDq0QwG2v7L3r1n5Yx8aE=",
    "relay_version": "3.0",
    "postfix": "ok",
    "listener_443": "ok"
  }
}
JSON

# ---------------------------------------------------------------------------
# PHP side
# ---------------------------------------------------------------------------
cat > "${WORK}/php_side.php" <<'PHP'
<?php
require_once(getenv('PUBLIC_HTML') . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectProtocol.php'));

$f = json_decode(file_get_contents(getenv('FIXTURE')), true);
file_put_contents(getenv('WORK') . '/php_preflight.bin',
    DirectProtocol::preflightSigningBytes($f['envelope'], $f['manifest']));
file_put_contents(getenv('WORK') . '/php_transfer.bin',
    DirectProtocol::transferSigningBytes($f['nonce'], $f['hashes']));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayProtocol.php'));
file_put_contents(getenv('WORK') . '/php_relay_request.bin',
    RelayProtocol::requestSigningBytes($f['relay_envelope']));
file_put_contents(getenv('WORK') . '/php_relay_born.bin',
    RelayProtocol::bornSigningBytes($f['birth_report']));

// A keypair, and PHP's signature over both forms — Go verifies these.
$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
file_put_contents(getenv('WORK') . '/php_keys.json', json_encode(array(
    'public'    => base64_encode(sodium_crypto_sign_publickey($pair)),
    'preflight' => base64_encode(sodium_crypto_sign_detached(
        file_get_contents(getenv('WORK') . '/php_preflight.bin'), $secret)),
    'transfer'  => base64_encode(sodium_crypto_sign_detached(
        file_get_contents(getenv('WORK') . '/php_transfer.bin'), $secret)),
    'relay_request' => base64_encode(sodium_crypto_sign_detached(
        file_get_contents(getenv('WORK') . '/php_relay_request.bin'), $secret)),
    'relay_born' => base64_encode(sodium_crypto_sign_detached(
        file_get_contents(getenv('WORK') . '/php_relay_born.bin'), $secret)),
)));

// And the sealed-size ceiling, which the relay must agree with or every honest
// sealed delivery aborts for arriving exactly as it was asked to.
$ceilings = array();
foreach (array(0, 1, 17, 1000, 1048576) as $n) {
    $ceilings[(string)$n] = DirectProtocol::sealedSizeCeiling($n);
}
file_put_contents(getenv('WORK') . '/php_ceilings.json', json_encode($ceilings));
PHP

PUBLIC_HTML="${PUBLIC_HTML}" FIXTURE="${WORK}/fixture.json" WORK="${WORK}" php "${WORK}/php_side.php"

# ---------------------------------------------------------------------------
# Go side — a throwaway test in the package, so it sees the unexported symbols
# ---------------------------------------------------------------------------
cat > "${HERE}/zz_wire_gate_test.go" <<'GO'
package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"os"
	"strconv"
	"testing"
)

// TestWireGate is generated by direct_wire_gate.sh and removed after it runs.
func TestWireGate(t *testing.T) {
	work := os.Getenv("JOINERY_WIRE_GATE_WORK")
	if work == "" {
		t.Skip("not running under direct_wire_gate.sh")
	}

	raw, err := os.ReadFile(os.Getenv("JOINERY_WIRE_GATE_FIXTURE"))
	if err != nil {
		t.Fatalf("fixture: %v", err)
	}
	var f struct {
		Envelope      directEnvelope        `json:"envelope"`
		Manifest      []directManifestEntry `json:"manifest"`
		Nonce         string                `json:"nonce"`
		Hashes        []string              `json:"hashes"`
		RelayEnvelope relayEnvelope         `json:"relay_envelope"`
		BirthReport   relayBirthReport      `json:"birth_report"`
	}
	if err := json.Unmarshal(raw, &f); err != nil {
		t.Fatalf("fixture parse: %v", err)
	}

	preflight, err := preflightSigningBytes(f.Envelope, f.Manifest)
	if err != nil {
		t.Fatalf("preflight: %v", err)
	}
	transfer, err := transferSigningBytes(f.Nonce, f.Hashes)
	if err != nil {
		t.Fatalf("transfer: %v", err)
	}
	if err := os.WriteFile(work+"/go_preflight.bin", preflight, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(work+"/go_transfer.bin", transfer, 0o600); err != nil {
		t.Fatal(err)
	}
	relayRequest, err := relayRequestSigningBytes(f.RelayEnvelope)
	if err != nil {
		t.Fatalf("relay request: %v", err)
	}
	relayBorn, err := relayBirthSigningBytes(f.BirthReport)
	if err != nil {
		t.Fatalf("relay born: %v", err)
	}
	if err := os.WriteFile(work+"/go_relay_request.bin", relayRequest, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(work+"/go_relay_born.bin", relayBorn, 0o600); err != nil {
		t.Fatal(err)
	}

	// PHP's signatures must verify here, over Go's own byte-forms. That is the
	// end-to-end claim: a box signs, a relay verifies.
	keysRaw, err := os.ReadFile(work + "/php_keys.json")
	if err != nil {
		t.Fatalf("php keys: %v", err)
	}
	var keys struct {
		Public       string `json:"public"`
		Preflight    string `json:"preflight"`
		Transfer     string `json:"transfer"`
		RelayRequest string `json:"relay_request"`
		RelayBorn    string `json:"relay_born"`
	}
	if err := json.Unmarshal(keysRaw, &keys); err != nil {
		t.Fatal(err)
	}
	if !verifyInstanceSignature(preflight, keys.Preflight, keys.Public) {
		t.Fatal("a PHP-made preflight signature does not verify in Go")
	}
	if !verifyInstanceSignature(transfer, keys.Transfer, keys.Public) {
		t.Fatal("a PHP-made transfer signature does not verify in Go")
	}
	if !verifyInstanceSignature(relayRequest, keys.RelayRequest, keys.Public) {
		t.Fatal("a PHP-made relay request signature does not verify in Go")
	}
	if !verifyInstanceSignature(relayBorn, keys.RelayBorn, keys.Public) {
		t.Fatal("a PHP-made birth report signature does not verify in Go")
	}

	// The sealed-size ceiling must agree exactly.
	ceilRaw, err := os.ReadFile(work + "/php_ceilings.json")
	if err != nil {
		t.Fatalf("php ceilings: %v", err)
	}
	var ceilings map[string]int64
	if err := json.Unmarshal(ceilRaw, &ceilings); err != nil {
		t.Fatal(err)
	}
	for sizeStr, want := range ceilings {
		size, _ := strconv.ParseInt(sizeStr, 10, 64)
		if got := sealedSizeCeiling(size); got != want {
			t.Fatalf("sealed-size ceiling for %d: Go %d, PHP %d", size, got, want)
		}
	}

	// The reverse direction: Go SIGNS the same byte-forms so PHP can verify a
	// GO-made signature. That is the guarantee a relay-PRODUCED container's
	// signatures re-verify at the box (A1-g2) — the relay holds no signing key in
	// production, but the box must accept anything correctly signed as the relay
	// would forward it.
	goPub, goPriv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("go keygen: %v", err)
	}
	goKeys := map[string]string{
		"public":        base64.StdEncoding.EncodeToString(goPub),
		"preflight":     base64.StdEncoding.EncodeToString(ed25519.Sign(goPriv, preflight)),
		"transfer":      base64.StdEncoding.EncodeToString(ed25519.Sign(goPriv, transfer)),
		"relay_request": base64.StdEncoding.EncodeToString(ed25519.Sign(goPriv, relayRequest)),
		"relay_born":    base64.StdEncoding.EncodeToString(ed25519.Sign(goPriv, relayBorn)),
	}
	goKeysJSON, err := json.Marshal(goKeys)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(work+"/go_keys.json", goKeysJSON, 0o600); err != nil {
		t.Fatal(err)
	}
}
GO

# Run from inside the module: `go test <dir>` still needs a module context, and
# this gate is invoked from the platform's test runner with an unrelated cwd.
(
  cd "${HERE}"
  JOINERY_WIRE_GATE_WORK="${WORK}" \
  JOINERY_WIRE_GATE_FIXTURE="${WORK}/fixture.json" \
    go test -run TestWireGate -count=1 . >/dev/null
)
gate_status=$?
rm -f "${HERE}/zz_wire_gate_test.go"
if [ "${gate_status}" -ne 0 ]; then
  echo "FAIL: the Go side of the gate did not run"
  echo "RESULT: FAIL"
  exit 1
fi

# ---------------------------------------------------------------------------
fail=0
for form in preflight transfer relay_request relay_born; do
  if cmp -s "${WORK}/php_${form}.bin" "${WORK}/go_${form}.bin"; then
    echo "PASS: ${form} signing bytes are identical in PHP and Go"
  else
    echo "FAIL: ${form} signing bytes DIFFER — every delivery (Direct) or pull (relay) would fail verification"
    echo "--- PHP ---"; cat "${WORK}/php_${form}.bin"; echo
    echo "--- Go  ---"; cat "${WORK}/go_${form}.bin"; echo
    fail=1
  fi
done

echo "PASS: PHP-made signatures verify in Go, over all four byte-forms"
echo "PASS: the sealed-size ceiling agrees on both sides"

# The reverse: a GO-made signature must verify in PHP, over the byte-forms just
# proven identical. This is the box's side of the relay path — a container the
# relay forwards carries the sender's signatures, and the box re-verifies them;
# proving Go-signed → PHP-verifies is that guarantee at the wire level (A1-g2).
cat > "${WORK}/php_verify.php" <<'PHP'
<?php
require_once(getenv('PUBLIC_HTML') . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectIdentity.php'));
$keys = json_decode(file_get_contents(getenv('WORK') . '/go_keys.json'), true);
$pre = DirectSigningIdentity::verify(
    file_get_contents(getenv('WORK') . '/php_preflight.bin'), $keys['preflight'], $keys['public']);
$xfer = DirectSigningIdentity::verify(
    file_get_contents(getenv('WORK') . '/php_transfer.bin'), $keys['transfer'], $keys['public']);
// The relay side of the same claim: a relay-signed birth report verifies on the
// plane, and a relay would accept what the plane signs.
$rreq = DirectSigningIdentity::verify(
    file_get_contents(getenv('WORK') . '/php_relay_request.bin'), $keys['relay_request'], $keys['public']);
$born = DirectSigningIdentity::verify(
    file_get_contents(getenv('WORK') . '/php_relay_born.bin'), $keys['relay_born'], $keys['public']);
exit(($pre && $xfer && $rreq && $born) ? 0 : 1);
PHP
if PUBLIC_HTML="${PUBLIC_HTML}" WORK="${WORK}" php "${WORK}/php_verify.php"; then
  echo "PASS: Go-made signatures verify in PHP, over all four byte-forms"
else
  echo "FAIL: a Go-made signature did not verify in PHP — a relay-produced container would be rejected at the box"
  fail=1
fi

if [ "${fail}" -ne 0 ]; then
  echo "RESULT: FAIL"
  exit 1
fi
echo "RESULT: PASS"
