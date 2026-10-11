#!/usr/bin/env python3
"""Summarise a rig server imap.log (or the IMAP lines of Thunderbird's MOZ_LOG).

  analyze.py imap.log [--from 'MARK text'] [--to 'MARK text']

Prints: connections and the folders each selected, every distinct command
shape Thunderbird sent (numbers collapsed), and counts.
"""
import re
import sys
from collections import Counter, defaultdict


def main():
    path = sys.argv[1]
    start = stop = None
    args = sys.argv[2:]
    if "--from" in args:
        start = args[args.index("--from") + 1]
    if "--to" in args:
        stop = args[args.index("--to") + 1]
    active = start is None
    selects = defaultdict(list)
    shapes = Counter()
    conns = []
    for line in open(path, errors="replace"):
        if "MARK:" in line:
            if start and start in line:
                active = True
            elif stop and stop in line:
                active = False
            continue
        if not active:
            continue
        m = re.match(r"\S+ \[c(\d+)\] (C:|S:|--) (.*)", line.rstrip("\n"))
        if not m:
            if "connect from" in line:
                pass
            continue
        conn, d, text = m.groups()
        if d != "C:" or text.startswith("<"):
            continue
        parts = text.split(" ", 1)
        if len(parts) < 2:
            continue
        cmd = parts[1]
        up = cmd.upper()
        if up.startswith(("SELECT", "EXAMINE")):
            selects[conn].append(cmd.split(" ", 1)[1])
        shape = re.sub(r"\b\d+(:\d+|:\*)?(,\d+(:\d+)?)*\b", "N", cmd)
        shape = re.sub(r'"[^"]*"', '"…"', shape) if up.startswith(("SELECT", "STATUS", "LIST", "LSUB", "APPEND", "CREATE", "UID COPY", "UID MOVE", "ID")) else shape
        shapes[shape] += 1
    for line in open(path, errors="replace"):
        m = re.search(r"\[c(\d+)\] connect", line)
        if m:
            conns.append(m.group(1))
    print("connections opened (whole log):", len(conns))
    print("\nfolders selected per connection (in range):")
    for c, l in sorted(selects.items(), key=lambda x: int(x[0])):
        print("  c%s: %s" % (c, " -> ".join(l)))
    print("\ncommand shapes (in range):")
    for s, n in shapes.most_common():
        print("  %4d  %s" % (n, s[:220]))


if __name__ == "__main__":
    main()
