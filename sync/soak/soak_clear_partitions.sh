#!/bin/bash
# Lift any network partition the chaos agent left behind.
#
# A partition is an iptables OUTPUT rule dropping one device account traffic to
# the server. The orchestrator lifts them before every settle -- but only while
# it is alive. Kill a campaign mid-storm, which is exactly what deploying a new
# binary does, and the rule outlives the process that made it.
#
# What that looks like afterwards is not a firewall problem. It is a daemon that
# starts, reports Cannot reach the server, and syncs nothing, on a box where
# curl works fine as root. The smoke test then fails every run, forever, and the
# line sits down. It cost an hour once; it does not need to cost another.
set -u
SERVER_IP=$(getent hosts drivetest.getjoinery.com | awk "{print \$1}" | head -1)
[ -n "$SERVER_IP" ] || { echo "cannot resolve the server host"; exit 1; }
removed=0
for user in soak-a soak-b; do
    uid=$(id -u "$user" 2>/dev/null) || continue
    # Repeated: iptables keeps duplicates, and a delete that finds nothing fails,
    # which is how the loop knows to stop.
    for _ in 1 2 3 4 5 6; do
        iptables -D OUTPUT -d "$SERVER_IP/32" -m owner --uid-owner "$uid" -j DROP 2>/dev/null || break
        echo "  lifted a partition on $user (uid $uid)"
        removed=$((removed + 1))
    done
done
[ "$removed" -eq 0 ] && echo "  no partitions were left behind"
exit 0
