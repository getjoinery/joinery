#!/bin/bash
# Run soak campaigns back to back, for as long as nobody stops it.
#
# The rig's idle time was never the campaigns -- it was the gaps between them,
# waiting for a person to type the next command. This closes those gaps and
# nothing else: it changes no code, makes no judgement, and decides nothing.
# Every campaign it runs is the campaign that was going to be run anyway.
#
# On a no-loss failure it FREEZES THE EVIDENCE and keeps going. It used to stop
# the line instead. Stopping was the right instinct -- a lost file needs its
# disks preserved before the next reset wipes them -- but four of the last five
# no-loss failures were the oracle mis-keying a claim through a folder rename,
# and each one cost a whole night of runs to a file that was never lost. Once
# the evidence is frozen there is nothing left to protect by idling.
#
# Usage:  setsid nohup /root/soak_forever.sh > /soak/forever.log 2>&1 < /dev/null &
# Stop:   touch /soak/STOP     (finishes the campaign in flight, then exits)

set -u

# One driver, always. Two of these ran side by side once: a deploy relaunched
# the line while an earlier driver was still looping on a failed smoke test, and
# both then waited to start the next run against the same rig. The second one
# refuses here instead, so a hand-relaunch after a stumble cannot leave a shadow
# driver behind. Held for the life of the process; released when it exits.
exec 9>/soak/.driver.lock
if ! flock -n 9; then
    echo "[$(date +%H:%M:%S)] another driver already holds the line; exiting"
    exit 0
fi

LEDGER=/soak/ledger.txt
COUNTER=/soak/run_counter
STOP=/soak/STOP
FLEET=/soak/fleet.json

# Campaign shape. Kept in one place so the ledger and the runs cannot disagree
# about what was run.
CYCLES=6
STORM=120
SETTLE=900
FAULTS=45
PACE=2000
SEED=21

note() { echo "[$(date -u +%H:%M:%S)] $*"; }

# Seconds of CPU a device's daemon has burned. A daemon blocked on a dead
# socket stays alive and answers every health check while syncing nothing; the
# only cheap tell is that it stops using any CPU at all. Run 44 sat at 12s over
# three and a half hours. Recorded every run so the next one is caught the
# morning it happens rather than eight builds later.
cpu_seconds() {
    local ns
    ns=$(systemctl show "soak-device@$1.service" -p CPUUsageNSec --value 2>/dev/null)
    case "$ns" in ''|'[not set]') echo "?" ;; *) echo $((ns / 1000000000)) ;; esac
}

freeze_evidence() {
    # Two statements, deliberately. A single `local run=$1 dest=...$run...`
    # expands $run before bash has assigned it, and under `set -u` that is not
    # a warning -- it kills the driver mid-freeze. It did, on the one path that
    # only runs when something has already gone wrong: the campaign that found
    # a loss was also the campaign whose evidence was never written, and the
    # line stayed down for hours afterwards.
    local run=$1
    local kind=${2:-loss}
    local dest=/root/run$run-$kind
    # A freeze is roughly 130MB and this rig's volume is 25GB. Filling it is not
    # a missed freeze but a dead database: a full disk killed postgres on
    # 2026-08-24 and the campaign went on writing green for seven hours after.
    local free_mb
    free_mb=$(df -Pm / | awk 'NR==2 {print $4}')
    if [ "${free_mb:-0}" -lt 3000 ]; then
        note "NOT freezing $dest -- only ${free_mb}MB free, and a full disk kills the database"
        return 1
    fi
    note "freezing evidence to $dest before anything resets"
    mkdir -p "$dest"
    cp /soak/device-a/home/state/state.db "$dest/device-a-state.db" 2>/dev/null
    cp /soak/device-b/home/state/state.db "$dest/device-b-state.db" 2>/dev/null
    cp -r /soak/journal "$dest/journal" 2>/dev/null
    tar czf "$dest/trees.tar.gz" -C /soak device-a/root device-b/root 2>/dev/null
    tar czf "$dest/trashes.tar.gz" -C /var/lib \
        soak-a/.local/share/Trash soak-b/.local/share/Trash 2>/dev/null
    # The daemon log and the violation bundles are what a loss is diagnosed
    # from, and both are destroyed by the next run reset. Three losses were
    # frozen without them and none of the three could be explained afterwards:
    # the state database says where the file ended up, and only these say how.
    cp /soak/device-a/home/logs/daemon.log "$dest/device-a-daemon.log" 2>/dev/null
    cp /soak/device-b/home/logs/daemon.log "$dest/device-b-daemon.log" 2>/dev/null
    cp "/soak/run$run.log" "$dest/run.log" 2>/dev/null
    tar czf "$dest/bundles.tar.gz" -C /soak bundles 2>/dev/null
    du -sh "$dest" 2>/dev/null | awk '{print "  frozen: "$1}'
}

if [ ! -f "$COUNTER" ]; then echo 38 > "$COUNTER"; fi
touch "$LEDGER"

while true; do
    if [ -f "$STOP" ]; then note "STOP file present -- exiting"; exit 0; fi
    if pgrep -x jd-soak >/dev/null; then
        note "a campaign is already running; waiting"
        sleep 120
        continue
    fi

    # Never be the thing that kills the database. Every run provisions a fresh
    # account and nothing is ever cleaned up, so the volume only falls -- and on
    # 2026-08-24 it reached zero, took postgres down, and the line then wrote
    # 'no-loss green' every five minutes for seven hours because a dead server
    # produces no verdicts to contradict. Waiting here is not lost work: a run
    # started on a full disk measures nothing anyway, and freeing space lets the
    # line pick straight back up.
    FREE_MB=$(df -Pm / | awk 'NR==2 {print $4}')
    if [ "${FREE_MB:-0}" -lt 1500 ]; then
        note "DISK FLOOR: only ${FREE_MB}MB free -- not starting a run. Free space and the line resumes."
        sleep 300
        continue
    fi

    PREV=$(cat "$COUNTER")
    RUN=$((PREV + 1))
    note "=== run $RUN starting (archiving run $PREV) ==="

    # Any killer left over from a previous campaign doubles the fault dose and
    # silently invalidates the comparison. It has leaked on four runs running,
    # so check every time rather than trusting the last one to have tidied up.
    for pid in $(ps -eo pid,cmd | grep "soak_kill_onl[y]" | awk '{print $1}'); do
        note "killing leaked killer $pid"
        kill "$pid" 2>/dev/null
    done

    # A partition the chaos agent installed outlives the process that made it.
    # The orchestrator lifts them before every settle, which covers every case
    # except the one that matters here: a campaign killed mid-storm, which is
    # what deploying a binary does. What is left behind is a daemon that starts,
    # says it cannot reach the server and syncs nothing, on a box where curl
    # works -- so the smoke test fails every run and the line stays down.
    bash /root/soak_clear_partitions.sh | sed "s/^/  /"

    bash /root/soak_reset.sh "run$PREV" >/dev/null 2>&1 || { note "reset failed"; sleep 300; continue; }
    # One archive per run and nothing ever removed them: 311 directories and
    # 2.5GB by run 209, on a 25GB volume, which is how the disk reached zero and
    # took the database with it. Forty is far more than any investigation has
    # reached back for, and a run that FAILED freezes its own evidence separately
    # under /root/run<N>-loss or -audited, which this never touches.
    ls -1dt /root/soak-evidence/*/ 2>/dev/null | tail -n +41 | while read -r old_archive; do
        rm -rf "$old_archive"
    done
    # A failed run's own evidence is about 200MB and was never removed either:
    # 49 of them and 8.2GB by run 1623, with 6GB left. The newest 25 cover every
    # open investigation by a wide margin. Newest by run number, not by date:
    # reading a state store in one touches its directory.
    for frozen in /root/run*-loss /root/run*-audited; do
        [ -d "$frozen" ] || continue
        n=${frozen#/root/run}
        echo "${n%%-*} $frozen"
    done | sort -n | head -n -25 | while read -r _ old_frozen; do
        rm -rf "$old_frozen"
    done

    # Every run provisions a fresh account and none of them were ever reclaimed,
    # so the SERVED deployment grew about 150MB a run forever -- the real reason
    # the volume reached zero on 2026-08-24 and took postgres with it. Retiring a
    # few of the oldest each time drains the backlog and then holds flat.
    #
    # Here, before the smoke test, because no campaign is running yet: the purge
    # is heavy database work and doing it alongside a campaign would contend for
    # the very server the run is measuring, which manufactures violations that
    # are about the rig rather than the client. It goes through
    # File::permanent_delete so the blob layer releases and the bytes actually
    # leave; deleting rows would strand every blob instead.
    if docker cp /root/purge_soak.php drivetest:/tmp/purge_soak.php >/dev/null 2>&1; then
        docker exec drivetest php /tmp/purge_soak.php 25 --apply --max-accounts=8 2>&1 \
            | tail -2 | sed "s/^/  /"
    fi

    ACCOUNT=$(grep -o "soak-rig-[0-9]*" /etc/jd-soak.env | head -1)
    CLIENT=$(md5sum /usr/local/bin/joinery-drive | cut -c1-8)
    note "account $ACCOUNT, client $CLIENT"

    # Prove the server answers before spending two and a half hours on it, and
    # leave a second version behind so the no-loss oracle's version preflight
    # has something to ask about.
    D=/soak/device-a/root/smoke$RUN
    mkdir -p "$D" && echo "smoke v1" > "$D/probe.txt" && chown -R soak-a:soak-a "$D"
    sleep 45
    echo "smoke v2" > "$D/probe.txt" && chown soak-a:soak-a "$D/probe.txt"
    sleep 45
    if ! sqlite3 /soak/device-a/home/state/state.db \
        "SELECT 1 FROM entries WHERE remote_name = 'probe.txt' AND server_id > 0 AND local_status = 'synced' LIMIT 1;" | grep -q 1; then
        note "SMOKE TEST FAILED -- not starting run $RUN"
        echo "run $RUN | $ACCOUNT | client $CLIENT | SMOKE FAILED" >> "$LEDGER"
        sleep 300
        continue
    fi

    # A campaign seed of its own, rather than one seed for the life of the
    # rig. The first 159 runs all ran schedule 21: device-b killed twice and
    # frozen once, device-a only ever partitioned, at the same moments every
    # time. A fault the rig cannot reach is a fault the rig cannot report, and
    # a hundred repeats of one schedule buy far less than a hundred schedules.
    # Recorded in the ledger, so a run that finds something can be re-run.
    RUN_SEED=$((SEED + RUN))
    note "launching run $RUN (seed $RUN_SEED)"
    # Which client this run is about, in the run log itself. The ledger has
    # carried it for a long time; the per-run report did not, and six days of
    # loss reports were read as being about the current build when the binary
    # on the box was a week old. A stale binary should be a line in the report
    # rather than something somebody notices.
    {
        echo "client $CLIENT (built $(date -r /usr/local/bin/joinery-drive +%Y-%m-%d\ %H:%M))"
        echo "orchestrator $(md5sum /usr/local/bin/jd-soak | cut -c1-8)"
    } > "/soak/run$RUN.log"
    env $(cat /etc/jd-soak.env | xargs) jd-soak orchestrate "$FLEET" \
        --cycles $CYCLES --storm-seconds $STORM --settle-seconds $SETTLE \
        --fault-seconds $FAULTS --pace-ms $PACE --seed $RUN_SEED \
        >> "/soak/run$RUN.log" 2>&1
    RC=$?

    echo "$RUN" > "$COUNTER"

    VIOL=$(grep -oP "INVARIANT VIOLATIONS: \K[0-9]+" /soak/journal/report.txt 2>/dev/null || echo "?")
    LOSS=$(grep -h "FAIL no-loss" /soak/bundles/*/verdicts.txt 2>/dev/null | head -1)
    # Did the run actually CHECK anything? An absent FAIL line is not a pass:
    # when the server is down the orchestrator gets nowhere and reading that
    # silence as green is how a seven-hour database outage went on writing
    # 'no-loss green' into this ledger once every five minutes.
    #
    # Read from the report, NOT from the violation bundles. A bundle is only
    # written for a cycle that BROKE something, so a clean run has no bundles at
    # all -- counting those called run 210 a non-run when it had committed 4,934
    # operations and passed 36 assertions.
    ASSERTIONS=$(grep -oP "Assertions passed \K[0-9]+" /soak/journal/report.txt 2>/dev/null | head -1)
    ASSERTIONS=${ASSERTIONS:-0}
    A_STUCK=$(sqlite3 /soak/device-a/home/state/state.db "SELECT COUNT(*) FROM ops WHERE state != 'done';" 2>/dev/null)
    B_STUCK=$(sqlite3 /soak/device-b/home/state/state.db "SELECT COUNT(*) FROM ops WHERE state != 'done';" 2>/dev/null)
    IDEM=$(sqlite3 /soak/device-b/home/state/state.db "SELECT COUNT(*) FROM ops WHERE last_error LIKE '%Idempotency-Key%';" 2>/dev/null)
    CPU_A=$(cpu_seconds a)
    CPU_B=$(cpu_seconds b)

    # What the release bar counts (spec drive_sync_soak.md, S8): actor ops and
    # daemon kills, plus when the run ended, so soak_clock.sh can add up a
    # streak without the evidence archives, which keep only the last 40 runs.
    OPS=$(grep -oP "Actor operations \K[0-9]+" /soak/journal/report.txt 2>/dev/null | head -1)
    KILLS=$(grep -oP "^\s*kill \(device-[a-z]+\)\s+\K[0-9]+" /soak/journal/report.txt 2>/dev/null \
        | awk '{n += $1} END {print n + 0}')
    ENDED=$(date -u +%Y-%m-%dT%H:%MZ)

    # Freeze first: the next iteration's reset is what would destroy this.
    # Any FAIL that is not a loss -- audited-green above all. These were never
    # frozen, so the next run's reset destroyed them and the class could not be
    # studied; and the ledger called the run green because only no-loss was
    # consulted, with the violation count the sole hint anything was wrong.
    OTHERFAIL=$(grep -h "^FAIL " /soak/bundles/*/verdicts.txt 2>/dev/null | grep -v "^FAIL no-loss" | head -1)

    if [ -n "$LOSS" ]; then
        if freeze_evidence "$RUN" loss; then
            note "NO-LOSS FAILED -- evidence frozen at /root/run$RUN-loss, continuing the line"
        fi
    elif [ -n "$OTHERFAIL" ]; then
        if freeze_evidence "$RUN" audited; then
            note "INVARIANT FAILED (not a loss) -- evidence frozen at /root/run$RUN-audited"
        fi
    fi

    if [ -n "$LOSS" ]; then
        STATUS="$LOSS"
    elif [ -n "$OTHERFAIL" ]; then
        STATUS="$OTHERFAIL"
    elif [ "$ASSERTIONS" -gt 0 ]; then
        STATUS="no-loss green"
    else
        STATUS="NO VERDICT: the run checked nothing, 0 assertions (orchestrator rc=$RC) -- not a pass"
    fi

    echo "run $RUN | $ACCOUNT | client $CLIENT | seed $RUN_SEED | violations $VIOL | stuck a=$A_STUCK b=$B_STUCK | cpu a=${CPU_A}s b=${CPU_B}s | idem $IDEM | ended $ENDED | ops ${OPS:-?} | kills $KILLS | $STATUS" >> "$LEDGER"
    note "run $RUN done: $VIOL violations, cpu a=${CPU_A}s b=${CPU_B}s"
done
