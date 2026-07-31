#!/bin/bash
# @joinery-test
# name: drive_ranged_download
# tier: live
# env: dev-only
# needs: [curl]
# timeout: 120
#
# Proves the ranged-download contract over real HTTP against the dev site.
#
# The unit test covers Range header PARSING; this covers what a resuming sync
# client actually experiences — status codes, Content-Range, and the bytes
# themselves — because the parsing being right does not prove the response is.
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -euo pipefail
BASE="${JOINERY_TEST_BASE_URL:-https://dev.getjoinery.com}"
PASS=0
FAIL=0

ok()   { echo "  ok  - $1"; PASS=$((PASS+1)); }
bad()  { echo "  NOT ok - $1"; echo "        $2"; FAIL=$((FAIL+1)); }

if ! command -v curl >/dev/null 2>&1; then
	echo "FAIL: curl unavailable — cannot exercise the ranged-download contract"
	exit 1
fi

# The signed URL to exercise. Minted by whoever runs the gate (drive_stat with
# urls:true, or the Drive UI) and passed in, because minting one here would mean
# holding a credential in the gate.
if [ -z "${JOINERY_RANGE_URL:-}" ]; then
	echo "SKIP: set JOINERY_RANGE_URL to a signed Drive download URL (drive_stat with urls:true) to run this gate"
	exit 0
fi
URL="$JOINERY_RANGE_URL"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# --- full GET still behaves, and now advertises range support ----------------
curl -sS -D "$TMP/full.h" -o "$TMP/full.bin" "$URL"
STATUS="$(awk 'NR==1{print $2}' "$TMP/full.h" | tr -d '\r')"
[ "$STATUS" = "200" ] && ok "a full GET is still 200" || bad "a full GET is still 200" "got $STATUS"
grep -qi '^accept-ranges: *bytes' "$TMP/full.h" \
	&& ok "the response advertises Accept-Ranges: bytes" \
	|| bad "the response advertises Accept-Ranges: bytes" "header absent"

TOTAL="$(wc -c < "$TMP/full.bin" | tr -d ' ')"
if [ "$TOTAL" -lt 16 ]; then
	echo "SKIP: JOINERY_RANGE_URL points at a $TOTAL-byte object; use something larger"
	exit 0
fi

# --- a bounded range is 206, with the right bytes ----------------------------
curl -sS -H "Range: bytes=0-9" -D "$TMP/r1.h" -o "$TMP/r1.bin" "$URL"
STATUS="$(awk 'NR==1{print $2}' "$TMP/r1.h" | tr -d '\r')"
[ "$STATUS" = "206" ] && ok "a bounded range returns 206" || bad "a bounded range returns 206" "got $STATUS"
GOT="$(wc -c < "$TMP/r1.bin" | tr -d ' ')"
[ "$GOT" = "10" ] && ok "the ranged response is exactly the requested length" \
	|| bad "the ranged response is exactly the requested length" "got $GOT bytes, wanted 10"
grep -qi "^content-range: *bytes 0-9/$TOTAL" "$TMP/r1.h" \
	&& ok "Content-Range names the span and the full size" \
	|| bad "Content-Range names the span and the full size" "$(grep -i '^content-range' "$TMP/r1.h" || echo 'header absent')"
head -c 10 "$TMP/full.bin" > "$TMP/expect1.bin"
cmp -s "$TMP/r1.bin" "$TMP/expect1.bin" \
	&& ok "the ranged bytes match the same span of the whole object" \
	|| bad "the ranged bytes match the same span of the whole object" "content differs"

# --- an open-ended range resumes to the end ----------------------------------
OFFSET=$((TOTAL - 8))
curl -sS -H "Range: bytes=$OFFSET-" -D "$TMP/r2.h" -o "$TMP/r2.bin" "$URL"
STATUS="$(awk 'NR==1{print $2}' "$TMP/r2.h" | tr -d '\r')"
[ "$STATUS" = "206" ] && ok "an open-ended range returns 206" || bad "an open-ended range returns 206" "got $STATUS"
GOT="$(wc -c < "$TMP/r2.bin" | tr -d ' ')"
[ "$GOT" = "8" ] && ok "an open-ended range runs to the end of the object" \
	|| bad "an open-ended range runs to the end of the object" "got $GOT bytes, wanted 8"
tail -c 8 "$TMP/full.bin" > "$TMP/expect2.bin"
cmp -s "$TMP/r2.bin" "$TMP/expect2.bin" \
	&& ok "the resumed tail matches the whole object's tail" \
	|| bad "the resumed tail matches the whole object's tail" "content differs"

# --- a range past the end is refused, and says how big the object is ---------
BEYOND=$((TOTAL + 100))
curl -sS -H "Range: bytes=$BEYOND-" -D "$TMP/r3.h" -o /dev/null "$URL"
STATUS="$(awk 'NR==1{print $2}' "$TMP/r3.h" | tr -d '\r')"
[ "$STATUS" = "416" ] && ok "a range beyond the object returns 416" || bad "a range beyond the object returns 416" "got $STATUS"
grep -qi "^content-range: *bytes \*/$TOTAL" "$TMP/r3.h" \
	&& ok "the 416 reports the object's real size" \
	|| bad "the 416 reports the object's real size" "$(grep -i '^content-range' "$TMP/r3.h" || echo 'header absent')"

echo
echo "passed: $PASS   failed: $FAIL"
[ "$FAIL" -eq 0 ]
