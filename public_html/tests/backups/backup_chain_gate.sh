#!/bin/bash
# @joinery-test
# name: backup_chain
# tier: db
# env: dev-only
# needs: []
# timeout: 300
#
# Incremental chains, end to end, with real tar and real openssl.
#
# The property that matters and is easy to get wrong: restoring a chain must
# reproduce the tree AS IT WAS, which means replaying DELETIONS, not just
# additions. A restore that only ever adds files looks like it works — the
# changed files are all correct — while quietly resurrecting everything the
# owner deleted since the full. So every restore point here asserts the EXACT
# file set, not just that the expected files are present.
#
# Also covered: incrementals actually being small, snapshot loss degrading to a
# new chain rather than a broken one, hash verification refusing a damaged
# artifact BEFORE anything is overwritten, and restore order being taken from
# the manifest rather than from a directory listing.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
BACKUP="$TOOLS/backup_files.sh"
RESTORE="$TOOLS/restore_chain.sh"
ENVTOOL="$TOOLS/backup_envelope.php"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

W=$(mktemp -d /tmp/jy_chain_gate_XXXXXX)
trap 'rm -rf "${W:?}"' EXIT
mkdir -p "$W/site" "$W/arts" "$W/out"

# The chain data key, minted the way a real run mints it: the tool reads the
# recovery key this site holds and has proven — nobody hands it one — and this
# gate never needs to open the envelope, only to use the data key it wrote.
php "$ENVTOOL" mint --artifact chain \
    --key-out "$W/chain.key" --sidecar-out "$W/arts/envelope.json" >/dev/null 2>&1
chk "chain key minted" "$([ -s "$W/chain.key" ] && echo yes || echo no)" "yes"

SNAR="$W/site.snar"

# tar's incremental detection works on mtime/ctime, so the fixture uses dated
# files rather than relying on same-second writes being noticed.
mk() { mkdir -p "$(dirname "$W/site/$1")"; echo "$2" > "$W/site/$1"; touch -d "$3" "$W/site/$1"; }

# ── Run 0: the full ─────────────────────────────────────────────────────────
echo "== Run 0: full =="
mk "keep.txt"        "original" "2026-01-01"
mk "will_change.txt" "before"   "2026-01-01"
mk "will_delete.txt" "doomed"   "2026-01-01"
mk "sub/nested.txt"  "nested"   "2026-01-01"

OUT=$(bash "$BACKUP" testsite --project-dir "$W/site" --output-dir "$W/arts" \
        --name files-0000 --snar "$SNAR" --key-file "$W/chain.key" 2>/dev/null)
L0=$(echo "$OUT" | grep '^LEVEL=' | cut -d= -f2)
F0=$(echo "$OUT" | grep '^ARCHIVE=' | cut -d= -f2)
B0=$(echo "$OUT" | grep '^BYTES=' | cut -d= -f2)
H0=$(echo "$OUT" | grep '^SHA256=' | cut -d= -f2)
chk "run 0 reports level 0" "$L0" "0"
chk "run 0 wrote an archive" "$([ -s "$F0" ] && echo yes || echo no)" "yes"
chk "run 0 created the snapshot" "$([ -s "$SNAR" ] && echo yes || echo no)" "yes"

# ── Run 1: a change and a DELETION ──────────────────────────────────────────
echo "== Run 1: incremental with a deletion =="
mk "will_change.txt" "after" "2026-06-01"
rm -f "$W/site/will_delete.txt"
mk "added.txt" "new file" "2026-06-01"

OUT=$(bash "$BACKUP" testsite --project-dir "$W/site" --output-dir "$W/arts" \
        --name files-0001 --snar "$SNAR" --key-file "$W/chain.key" 2>/dev/null)
L1=$(echo "$OUT" | grep '^LEVEL=' | cut -d= -f2)
F1=$(echo "$OUT" | grep '^ARCHIVE=' | cut -d= -f2)
B1=$(echo "$OUT" | grep '^BYTES=' | cut -d= -f2)
H1=$(echo "$OUT" | grep '^SHA256=' | cut -d= -f2)
chk "run 1 reports level 1" "$L1" "1"
# The whole point of an incremental: it must be materially smaller than the
# full. If this ever fails, incrementals have silently become fulls.
chk "the incremental is smaller than the full" \
    "$([ "$B1" -lt "$B0" ] && echo yes || echo no)" "yes"

# ── Manifest ────────────────────────────────────────────────────────────────
cat > "$W/arts/manifest.json" <<JSON
{
  "version": 1,
  "chain_id": "chain-20260802_120000",
  "slug": "testsite",
  "created": "2026-08-02T12:00:00Z",
  "updated": "2026-08-02T12:05:00Z",
  "runs": [
    {"seq": 0, "level": 0, "time": "2026-08-02T12:00:00Z",
     "artifacts": {"files": {"name": "$(basename "$F0")", "bytes": $B0, "sha256": "$H0"}}},
    {"seq": 1, "level": 1, "time": "2026-08-02T12:05:00Z",
     "artifacts": {"files": {"name": "$(basename "$F1")", "bytes": $B1, "sha256": "$H1"}}}
  ]
}
JSON

# ── Restore at run 0 ────────────────────────────────────────────────────────
echo "== Restore as at run 0 =="
rm -rf "${W:?}/out"; mkdir -p "$W/out"
bash "$RESTORE" testsite --target-dir "$W/out/site" --artifacts "$W/arts" \
    --key-file "$W/chain.key" --seq 0 --force --skip-database >/dev/null 2>&1
GOT=$(cd "$W/out/site" 2>/dev/null && find . -type f | sort | tr '\n' ' ')
chk "run 0 restores exactly the original file set" \
    "$GOT" "./keep.txt ./sub/nested.txt ./will_change.txt ./will_delete.txt "
chk "run 0 restores the ORIGINAL content" \
    "$(cat "$W/out/site/will_change.txt" 2>/dev/null)" "before"

# ── Restore at run 1: the deletion must replay ──────────────────────────────
echo "== Restore as at run 1 =="
rm -rf "${W:?}/out"; mkdir -p "$W/out"
bash "$RESTORE" testsite --target-dir "$W/out/site" --artifacts "$W/arts" \
    --key-file "$W/chain.key" --force --skip-database >/dev/null 2>&1
GOT=$(cd "$W/out/site" 2>/dev/null && find . -type f | sort | tr '\n' ' ')
chk "run 1 restores exactly the later file set" \
    "$GOT" "./added.txt ./keep.txt ./sub/nested.txt ./will_change.txt "
chk "the changed file has the NEW content" \
    "$(cat "$W/out/site/will_change.txt" 2>/dev/null)" "after"
# The assertion this whole gate exists for.
chk "the DELETED file is gone, not resurrected" \
    "$([ -e "$W/out/site/will_delete.txt" ] && echo present || echo gone)" "gone"

# ── The machine's own upload ledger survives the extraction ─────────────────
#
# The check above — "the DELETED file is gone, not resurrected" — is the same
# mechanism seen from the other side, and it is what makes this necessary.
# --incremental extraction replays deletions from each archive's directory
# listings, so ANYTHING present locally that the listings do not mention is
# removed. config/backup-ledger did not exist when these runs were taken, so it
# is not in any listing.
#
# Without the hold in restore_chain.sh, the first chain restore deletes the
# record that every LATER restore checks its archives against, and the next
# restore refuses everything with "this node has no upload ledger". The
# safety file would be missing only on the day it was needed.
echo "== The machine's own ledger survives a chain restore =="
rm -rf "${W:?}/out"; mkdir -p "$W/out/site/config/backup-ledger"
printf '{"db-0000.sql.gz.enc":{"sha256":"deadbeef","bytes":1}}'     > "$W/out/site/config/backup-ledger/manager.json"
chmod 700 "$W/out/site/config/backup-ledger"
chmod 600 "$W/out/site/config/backup-ledger/manager.json"

bash "$RESTORE" testsite --target-dir "$W/out/site" --artifacts "$W/arts" \
    --key-file "$W/chain.key" --force --skip-database >/dev/null 2>&1

chk "the upload ledger survives a chain restore" \
    "$([ -f "$W/out/site/config/backup-ledger/manager.json" ] && echo present || echo deleted)" "present"
chk "and still holds what this machine recorded, not the archive's copy" \
    "$(grep -c deadbeef "$W/out/site/config/backup-ledger/manager.json" 2>/dev/null)" "1"
# Permissions matter as much as presence: the agent refuses a ledger that is
# group- or other-writable, so a restore that put it back wide open would leave
# every later restore refusing for a different reason.
chk "and is not left writable by group or other" \
    "$(stat -c '%a' "$W/out/site/config/backup-ledger/manager.json" 2>/dev/null)" "600"

# ── Snapshot loss degrades to a new chain ───────────────────────────────────
echo "== Snapshot loss =="
rm -f "$SNAR"
OUT=$(bash "$BACKUP" testsite --project-dir "$W/site" --output-dir "$W/arts" \
        --name files-lost --snar "$SNAR" --key-file "$W/chain.key" 2>/dev/null)
chk "a lost snapshot starts a new chain with a full" \
    "$(echo "$OUT" | grep '^LEVEL=' | cut -d= -f2)" "0"
chk "and the snapshot is rebuilt" "$([ -s "$SNAR" ] && echo yes || echo no)" "yes"

: > "$SNAR"
OUT=$(bash "$BACKUP" testsite --project-dir "$W/site" --output-dir "$W/arts" \
        --name files-empty --snar "$SNAR" --key-file "$W/chain.key" 2>/dev/null)
chk "an EMPTY snapshot is treated the same way, not as a valid baseline" \
    "$(echo "$OUT" | grep '^LEVEL=' | cut -d= -f2)" "0"

# ── Damaged artifacts are refused BEFORE anything is written ────────────────
echo "== Verification refuses damaged chains =="
cp -r "$W/arts" "$W/arts_bad"
truncate -s -200 "$W/arts_bad/$(basename "$F1")"
rm -rf "${W:?}/out"; mkdir -p "$W/out/site"
echo "sentinel" > "$W/out/site/PREEXISTING.txt"
OUT=$(bash "$RESTORE" testsite --target-dir "$W/out/site" --artifacts "$W/arts_bad" \
        --key-file "$W/chain.key" --force --skip-database 2>&1)
RC=$?
chk "a truncated artifact fails the restore" "$([ $RC -ne 0 ] && echo yes || echo no)" "yes"
chk "and says it is incomplete" "$(echo "$OUT" | grep -ci 'incomplete')" "1"
chk "and the target tree was NOT touched" \
    "$([ -f "$W/out/site/PREEXISTING.txt" ] && echo intact || echo clobbered)" "intact"

cp -r "$W/arts" "$W/arts_hash"
python3 - "$W/arts_hash/manifest.json" <<'PY'
import json,sys
p=sys.argv[1]
m=json.load(open(p))
m['runs'][1]['artifacts']['files']['sha256']='0'*64
json.dump(m,open(p,'w'))
PY
OUT=$(bash "$RESTORE" testsite --target-dir "$W/out/site2" --artifacts "$W/arts_hash" \
        --key-file "$W/chain.key" --force --skip-database 2>&1)
RC=$?
chk "a hash mismatch fails the restore" "$([ $RC -ne 0 ] && echo yes || echo no)" "yes"
chk "and says it is damaged" "$(echo "$OUT" | grep -ci 'does not match its recorded hash')" "1"

# A chain whose full is missing is not a partial backup, it is no backup.
cp -r "$W/arts" "$W/arts_nofull"
rm -f "$W/arts_nofull/$(basename "$F0")"
OUT=$(bash "$RESTORE" testsite --target-dir "$W/out/site3" --artifacts "$W/arts_nofull" \
        --key-file "$W/chain.key" --force --skip-database 2>&1)
chk "a chain missing its full is refused" "$(echo "$OUT" | grep -ci 'missing backup artifact')" "1"

# ── Dry run changes nothing ─────────────────────────────────────────────────
echo "== Dry run =="
rm -rf "${W:?}/out"; mkdir -p "$W/out/site"
echo "sentinel" > "$W/out/site/PREEXISTING.txt"
OUT=$(bash "$RESTORE" testsite --target-dir "$W/out/site" --artifacts "$W/arts" \
        --dry-run --skip-database 2>&1)
chk "a dry run reports a plan" "$(echo "$OUT" | grep -c 'RESTORE_PLAN_OK')" "1"
chk "a dry run writes nothing" \
    "$(cd "$W/out/site" && find . -type f | sort | tr '\n' ' ')" "./PREEXISTING.txt "
chk "and needs no key" "$(echo "$OUT" | grep -ci 'key-file is required')" "0"

# ── A target the archive cannot land in is refused ──────────────────────────
#
# tar recreates the directory the archive carries, so extraction goes to
# dirname(target) plus THAT name — the last segment of --target-dir is not free.
# Unenforced, a restore aimed at a scratch directory wrote to the sibling named
# after the project and still reported success. On a real box that sibling is the
# live site, and extraction runs with tar --incremental, which DELETES files the
# archive does not list. Observed live 2026-08-06.
echo "== A mismatched target is refused =="
rm -rf "${W:?}/out"; mkdir -p "$W/out/wrongname"
OUT=$(bash "$RESTORE" testsite --target-dir "$W/out/wrongname" --artifacts "$W/arts" \
        --key-file "$W/chain.key" --force --skip-database 2>&1) && RC=0 || RC=$?
chk "a target whose last segment is not the archive's directory fails" "$RC" "1"
chk "and the error names the path that would work" \
    "$(echo "$OUT" | grep -c -- "--target-dir $W/out/site")" "1"
chk "nothing is written to the requested target" \
    "$(find "$W/out/wrongname" -mindepth 1 | wc -l | tr -d ' ')" "0"
chk "and nothing is written to the sibling it used to pick silently" \
    "$([ -e "$W/out/site" ] && echo present || echo absent)" "absent"

# A key that cannot open the archive is caught before anything is applied,
# rather than extracting nothing and reporting success.
echo "== A key that does not open the chain is caught up front =="
rm -rf "${W:?}/out"; mkdir -p "$W/out"
head -c 32 /dev/urandom | base64 > "$W/badkey"
OUT=$(bash "$RESTORE" testsite --target-dir "$W/out/site" --artifacts "$W/arts" \
        --key-file "$W/badkey" --force --skip-database 2>&1) && RC=0 || RC=$?
chk "a wrong key fails the restore" "$RC" "1"
chk "and says so, rather than leaving an empty tree behind" \
    "$(echo "$OUT" | grep -ci "could not read the archive")" "1"

# ── Version 2: data and code, each with its own chain ───────────────────────
#
# A version-2 chain archives the site's data (the directory less public_html)
# and its code (public_html) separately, each level recorded per artifact. An
# upgrade-style swap re-bases the code alone while the data keeps incrementing.
# restore_chain.sh's plan must match BackupChain::restore_plan: the data from
# the chain's full, the code from its re-base — and the restored tree must be
# the source, exactly, at every run.
echo "== Version 2: data and code chains =="
V=$W/v2; mkdir -p "$V/src/testsite/public_html/lib" "$V/src/testsite/uploads/u1" "$V/src/testsite/config" "$V/arts" "$V/at"
echo one  > "$V/src/testsite/public_html/lib/a.php"
echo idx  > "$V/src/testsite/public_html/index.php"
echo up1  > "$V/src/testsite/uploads/u1/photo.jpg"
echo cfg  > "$V/src/testsite/config/site.conf"
enc() { openssl enc -aes-256-cbc -salt -pbkdf2 -pass fd:3 3< "$W/chain.key"; }
v2_data() { tar --listed-incremental="$V/data.snar" --anchored --exclude='testsite/public_html' --no-anchored \
               -czf - -C "$V/src" testsite | enc > "$V/arts/data-$1.tar.gz.enc"; }
v2_code() { tar --listed-incremental="$V/code.snar" -czf - -C "$V/src/testsite" public_html | enc > "$V/arts/code-$1.tar.gz.enc"; }
v2_data 0000; v2_code 0000; cp -a "$V/src/testsite" "$V/at/0"
# Run 1: an upgrade swaps public_html (the code re-bases), and the site gains
# and loses an upload (the data increments).
mkdir -p "$V/stage/public_html/lib"; echo two > "$V/stage/public_html/lib/a.php"; echo new > "$V/stage/public_html/lib/b.php"
mv "$V/src/testsite/public_html" "$V/src/testsite/public_html_last"; mv "$V/stage/public_html" "$V/src/testsite/public_html"
rm -rf "${V:?}/src/testsite/public_html_last"
mkdir -p "$V/src/testsite/uploads/u2"; echo up2 > "$V/src/testsite/uploads/u2/photo.jpg"; rm -rf "${V:?}/src/testsite/uploads/u1"
rm -f "$V/code.snar"; v2_data 0001; v2_code 0001; cp -a "$V/src/testsite" "$V/at/1"
# Run 2: ordinary changes to both.
echo three > "$V/src/testsite/public_html/lib/a.php"; rm -f "$V/src/testsite/public_html/lib/b.php"; echo more > "$V/src/testsite/config/extra.conf"
v2_data 0002; v2_code 0002; cp -a "$V/src/testsite" "$V/at/2"

python3 - "$V/arts" <<'PY'
import hashlib, json, os, sys
d = sys.argv[1]
def art(name, level=None):
    p = os.path.join(d, name)
    e = {'name': name, 'bytes': os.path.getsize(p), 'sha256': hashlib.sha256(open(p, 'rb').read()).hexdigest()}
    if level is not None: e['level'] = level
    return e
levels = {0: (0, 0), 1: (1, 0), 2: (1, 1)}
runs = []
for seq, (dl, cl) in levels.items():
    runs.append({'seq': seq, 'level': 0 if (dl, cl) == (0, 0) else 1, 'time': '2026-09-26T04:00:0%dZ' % seq,
                 'artifacts': {'data': art('data-%04d.tar.gz.enc' % seq, dl), 'code': art('code-%04d.tar.gz.enc' % seq, cl)}})
m = {'version': 2, 'chain_id': 'chain-20260926_040000', 'slug': 'testsite', 'started_because': 'no_chain',
     'created': '2026-09-26T04:00:00Z', 'updated': '2026-09-26T04:00:02Z', 'envelope': {}, 'runs': runs}
json.dump(m, open(os.path.join(d, 'manifest.json'), 'w'))
PY

OUT=$(bash "$RESTORE" testsite --target-dir "$V/out/testsite" --artifacts "$V/arts" --dry-run --skip-database 2>&1)
chk "a version-2 plan applies the data from the full and the code from its re-base" \
    "$(echo "$OUT" | grep -o '[a-z]*-000[0-9].tar.gz.enc' | tr '\n' ' ')" \
    "data-0000.tar.gz.enc data-0001.tar.gz.enc data-0002.tar.gz.enc code-0001.tar.gz.enc code-0002.tar.gz.enc "
PHP_PLAN=$(php -r 'require "'"$ROOT"'/public_html/includes/PathHelper.php"; require "'"$ROOT"'/public_html/includes/BackupChain.php";
    $m = BackupChain::read($argv[1]); foreach (BackupChain::plan_artifacts(BackupChain::restore_plan($m)) as $i) echo $i["entry"]["name"], " ";' \
    "$V/arts/manifest.json" 2>&1)
chk "and BackupChain::restore_plan plans the same, in the same order" "$PHP_PLAN" \
    "data-0000.tar.gz.enc data-0001.tar.gz.enc data-0002.tar.gz.enc code-0001.tar.gz.enc code-0002.tar.gz.enc "

for seq in 0 1 2; do
    rm -rf "${V:?}/out"; mkdir -p "$V/out"
    bash "$RESTORE" testsite --target-dir "$V/out/testsite" --artifacts "$V/arts" --key-file "$W/chain.key" \
        --seq "$seq" --force --skip-database --skip-reconcile >/dev/null 2>&1
    chk "restoring version-2 run $seq reproduces the tree exactly" \
        "$(diff -r "$V/at/$seq" "$V/out/testsite" >/dev/null 2>&1 && echo same || echo differs)" "same"
done

# Over a live tree that has drifted: deletions replay in both kinds.
rm -rf "${V:?}/out"; mkdir -p "$V/out"; cp -a "$V/at/0" "$V/out/testsite"
echo junk > "$V/out/testsite/uploads/u1/junk.txt"; echo junk > "$V/out/testsite/public_html/junk.php"
bash "$RESTORE" testsite --target-dir "$V/out/testsite" --artifacts "$V/arts" --key-file "$W/chain.key" \
    --force --skip-database --skip-reconcile >/dev/null 2>&1
chk "restoring over a drifted live tree leaves exactly the newest run" \
    "$(diff -r "$V/at/2" "$V/out/testsite" >/dev/null 2>&1 && echo same || echo differs)" "same"

# A physical database backup is refused before anything is written.
python3 - "$V/arts/manifest.json" <<'PY'
import json, sys
m = json.load(open(sys.argv[1]))
last = m['runs'][2]['artifacts']
last['pgdata'] = dict(last['data'], level=0)
json.dump(m, open(sys.argv[1].replace('manifest.json', 'manifest.pg.json'), 'w'))
m['version'] = 3
json.dump(m, open(sys.argv[1].replace('manifest.json', 'manifest.v3.json'), 'w'))
PY
mkdir -p "$V/arts_pg" "$V/arts_v3"; cp "$V/arts/"*.enc "$V/arts_pg/"; cp "$V/arts/"*.enc "$V/arts_v3/"
mv "$V/arts/manifest.pg.json" "$V/arts_pg/manifest.json"; mv "$V/arts/manifest.v3.json" "$V/arts_v3/manifest.json"
rm -rf "${V:?}/out"; mkdir -p "$V/out/testsite"; echo sentinel > "$V/out/testsite/KEEP"
OUT=$(bash "$RESTORE" testsite --target-dir "$V/out/testsite" --artifacts "$V/arts_pg" --key-file "$W/chain.key" --force 2>&1) && RC=0 || RC=$?
chk "a pgdata run without --skip-database is refused" "$RC" "1"
chk "before anything is written" "$(ls "$V/out/testsite" | tr '\n' ' ')" "KEEP "
OUT=$(bash "$RESTORE" testsite --target-dir "$V/out/testsite" --artifacts "$V/arts_v3" --dry-run --skip-database 2>&1) && RC=0 || RC=$?
chk "a manifest version this script does not know is refused by name" "$(echo "$OUT" | grep -c 'unsupported chain manifest version 3')" "1"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
