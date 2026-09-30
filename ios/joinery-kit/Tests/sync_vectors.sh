#!/bin/bash
# Copy the platform's shared port vectors into the test bundles' Fixtures/.
# The originals (written by the PHP and browser suites) are the source of
# truth; `--check` exits non-zero when a copy has drifted, so a gate can refuse
# to test against stale vectors. specs/fortress_mobile_apps.md § WP1.
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
PH="$HERE/../../../public_html"
KIT="$HERE/JoineryKitTests/Fixtures"
MAIL="$HERE/JoineryMailKitTests/Fixtures/vectors"
mkdir -p "$MAIL/mime"
pairs=(
  "$PH/tests/vault/fixtures/edge_vector.json|$KIT/edge_vector.json"
  "$PH/tests/vault/fixtures/device_handoff_vector.json|$KIT/device_handoff_vector.json"
  "$PH/plugins/mailbox/tests/fixtures/relay_pin_vector.json|$KIT/relay_pin_vector.json"
  "$PH/tests/vault/fixtures/device_handoff_vector.json|$MAIL/device_handoff_vector.json"
  "$PH/plugins/mailbox/tests/fixtures/relay_pin_vector.json|$MAIL/relay_pin_vector.json"
  "$PH/plugins/mailbox/tests/fixtures/search_core_vectors.json|$MAIL/search_core_vectors.json"
  "$PH/plugins/mailbox/tests/fixtures/device_hits_vector.json|$MAIL/device_hits_vector.json"
  "$PH/plugins/mailbox/tests/fixtures/device_ai_vectors.json|$MAIL/device_ai_vectors.json"
  "$PH/plugins/mailbox/tests/fixtures/filter_match_cases.json|$MAIL/filter_match_cases.json"
)
mkdir -p "$MAIL/fortress_api"
for f in "$PH"/plugins/mailbox/tests/fixtures/fortress_api/*.json; do
  pairs+=("$f|$MAIL/fortress_api/$(basename "$f")")
done
for f in "$PH"/plugins/mailbox/tests/fixtures/mime/*; do
  pairs+=("$f|$MAIL/mime/$(basename "$f")")
done
# The digest's encoded-word cases, decoded by PHP itself (iconv_mime_decode,
# what the server's digest does): the list is device_ai_digest_parity_test.php's.
WORDS_OUT="$MAIL/digest_words_vector.json"
WORDS_TMP="$(mktemp)"
php -r '
$src = file_get_contents($argv[1]);
preg_match("/[$]words = array\((.*?)\n\);/s", $src, $m);
$words = eval("return array(" . $m[1] . ");");
$out = array("_about" => "PHP iconv_mime_decode(CONTINUE_ON_ERROR, UTF-8) of the encoded-word cases in plugins/mailbox/tests/device_ai_digest_parity_test.php, written by ios/joinery-kit/Tests/sync_vectors.sh. Outputs as base64 (some are not valid UTF-8).", "cases" => array());
foreach ($words as $w) { $d = @iconv_mime_decode($w, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, "UTF-8"); $out["cases"][] = array("input" => $w, "expected_b64" => base64_encode($d === false ? "" : $d)); }
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' "$PH/plugins/mailbox/tests/device_ai_digest_parity_test.php" > "$WORDS_TMP"

drift=0
if [ "$1" = "--check" ]; then
  cmp -s "$WORDS_TMP" "$WORDS_OUT" || { echo "stale vector: $WORDS_OUT"; drift=1; }
else
  cp "$WORDS_TMP" "$WORDS_OUT"
fi
rm -f "$WORDS_TMP"
for p in "${pairs[@]}"; do
  src="${p%%|*}"; dst="${p##*|}"
  if [ "$1" = "--check" ]; then
    cmp -s "$src" "$dst" || { echo "stale vector: $dst"; drift=1; }
  else
    cp "$src" "$dst"
  fi
done
exit $drift
