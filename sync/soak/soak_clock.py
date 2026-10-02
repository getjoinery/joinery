#!/usr/bin/env python3
"""How far the current client build is along the release bar.

The release bar (spec drive_sync_soak.md, S8) is one unbroken stretch of clean
campaigns on one build: 7 days, at least 1M actor ops and 100 daemon kills,
zero violations. This reads the ledger soak_forever.sh writes and reports the
stretch the newest build is on: from the last run that was not clean, or from
the build's first run, to now.

A run is clean only when the ledger calls it 'no-loss green' AND it counted
zero violations. A smoke failure or a run that checked nothing breaks the
stretch like any failure: a stretch that skipped its bad runs would be a
clock that only ticks.

The bar's other terms (all personas and drills, three filesystem
personalities, every deadline miss diagnosed) are not in the ledger; the
filesystems this rig runs are printed so nobody reads 7 days on ext4 as the
whole bar.

Usage:  soak_clock.py [ledger]      (default /soak/ledger.txt)
"""
import datetime as dt
import glob
import os
import re
import sys

LEDGER = sys.argv[1] if len(sys.argv) > 1 else "/soak/ledger.txt"
BAR_DAYS, BAR_OPS, BAR_KILLS = 7, 1_000_000, 100


def field(line, name):
    m = re.search(r"\| " + name + r" ([^|]+?) \|", line + " |")
    return m.group(1).strip() if m else None


def ended(run, line):
    """When the run ended: the ledger's own stamp, else its log's mtime."""
    stamp = field(line, "ended")
    if stamp:
        return dt.datetime.strptime(stamp, "%Y-%m-%dT%H:%MZ").replace(tzinfo=dt.timezone.utc)
    log = f"/soak/run{run}.log"
    if os.path.exists(log):
        return dt.datetime.fromtimestamp(os.path.getmtime(log), dt.timezone.utc)
    return None


def counted(run, line, name, pattern):
    """A run's ops or kills: the ledger's, else its archived report's, else None."""
    value = field(line, name)
    if value and value != "?":
        return int(value)
    for report in glob.glob(f"/root/soak-evidence/run{run}-*/journal/report.txt"):
        found = re.findall(pattern, open(report).read(), re.M)
        if found:
            return sum(int(n) for n in found)
    return None


runs = []
for line in open(LEDGER):
    m = re.match(r"run (\d+) \|", line)
    if m and field(line, "client"):
        runs.append((int(m.group(1)), line.rstrip("\n")))
if not runs:
    sys.exit(f"no runs in {LEDGER}")

build = field(runs[-1][1], "client")
stretch = []
for run, line in reversed(runs):
    if field(line, "client") != build:
        break
    if not (line.endswith("| no-loss green") and field(line, "violations") == "0"):
        break
    stretch.append((run, line))
stretch.reverse()

print(f"build            client {build}")
if not stretch:
    print(f"stretch          none: run {runs[-1][0]} was not clean -- {runs[-1][1].split(' | ')[-1]}")
    sys.exit(0)

first, last = stretch[0][0], stretch[-1][0]
before = next(((r, l) for r, l in reversed(runs) if r < first), None)
broke = before[0] if before and field(before[1], "client") == build else None
start = ended(*stretch[0])
end = ended(*stretch[-1])
days = (end - start).total_seconds() / 86400 if start and end else 0.0
ops = [counted(r, l, "ops", r"^Actor operations (\d+)") for r, l in stretch]
kills = [counted(r, l, "kills", r"^\s*kill \(device-[a-z]+\)\s+(\d+)") for r, l in stretch]
unknown = sum(1 for o in ops if o is None)

print(f"stretch          runs {first}-{last} ({len(stretch)} clean in a row)"
      + (f", since run {broke} failed" if broke else ", from the build's first run"))
print(f"window           {start:%Y-%m-%d %H:%M}Z to {end:%Y-%m-%d %H:%M}Z")
print()
mark = lambda ok: "met " if ok else "open"
print(f"[{mark(days >= BAR_DAYS)}] days             {days:.2f} of {BAR_DAYS}")
print(f"[{mark(sum(o or 0 for o in ops) >= BAR_OPS)}] actor ops        "
      f"{sum(o or 0 for o in ops):,} of {BAR_OPS:,}"
      + (f"  ({unknown} run(s) uncounted: older than the ledger's ops field and no archive)" if unknown else ""))
print(f"[{mark(sum(k or 0 for k in kills) >= BAR_KILLS)}] daemon kills     {sum(k or 0 for k in kills):,} of {BAR_KILLS}")
print("[met ] violations       0 (every run in the stretch)")
print("[open] filesystems      ext4 only on this rig; the bar wants three personalities")
print("[----] personas/drills  not in the ledger; read the newest report's 'By persona'")
