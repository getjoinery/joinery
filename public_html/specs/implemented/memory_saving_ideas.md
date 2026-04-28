# docker-prod memory saving ideas

Snapshot taken 2026-04-23. Host: 3.8 GB total, 1.1 GB used, 2.7 GB available, 39 MB swap in use. Not memory-pressured — these are opportunities, not emergencies.

## Per-site memory (docker stats, cgroup RSS)

| Container | Memory |
|---|---|
| getjoinery | 103 MB |
| phillyzouk | 69 MB |
| scrolldaddy | 63 MB |
| empoweredhealthtn | 62 MB |
| galactictribune | 61 MB |
| jeremytunnell | 59 MB |
| mapsofwisdom | 57 MB |
| joinerydemo | 53 MB |
| **Total** | **~527 MB** |

Inside each container the breakdown is roughly: Apache prefork workers (summed RSS ~200–370 MB, lots of shared pages), Postgres (~65–115 MB), plus cron/shell.

## Low-hanging fruit

Ordered by effort vs. payoff.

### 1. Right-size Apache MPM prefork (low effort, low risk)

Every container currently ships with:

```
StartServers            5
MinSpareServers         5
MaxSpareServers         10
MaxRequestWorkers       150
MaxConnectionsPerChild  0
```

Issues:
- 5–10 idle workers per site × 8 sites = a lot of idle RSS on sites that see almost no traffic (joinerydemo, galactictribune, mapsofwisdom, jeremytunnell, phillyzouk).
- `MaxConnectionsPerChild = 0` means workers are never recycled, so any PHP-side leak accumulates forever.

Proposed low-traffic profile:

```
ServerLimit             50
StartServers            2
MinSpareServers         2
MaxSpareServers         4
MaxRequestWorkers       50
MaxConnectionsPerChild  2000
```

`ServerLimit` must match `MaxRequestWorkers` — without it, prefork allocates shared memory for the compiled default of 256 slots regardless.

Expected saving: ~100–150 MB across the fleet. Applied via the base Dockerfile / mpm_prefork.conf, rebuild on next upgrade cycle. No functional change for sites at current traffic levels.

### 2. Shrink Postgres `shared_buffers` on small sites (low effort, medium risk)

Current: every container has `shared_buffers = 128 MB`, `max_connections = 100`.

8 × 128 MB = 1 GB pre-reserved for Postgres page cache, most of it wasted on tiny databases. Use `shared_buffers = 64 MB` as a safe universal floor — enough headroom for real queries, half the current allocation.

Requires a Postgres restart per site. Best done in the same rebuild window as #1.

## Implementation

Both changes are applied in two places:

### install.sh (future containers)

`install.sh` version 2.18 writes the MPM prefork config and sets `shared_buffers`:

- After the Apache config block: writes `/etc/apache2/mods-available/mpm_prefork.conf` with the profile above.
- In the PostgreSQL config block: adds `sed -i "s/shared_buffers = 128MB/shared_buffers = 64MB/"` alongside the existing `max_wal_size` tweak.

All future `build-base` runs and new site deployments pick this up automatically.

### Live containers (applied 2026-04-28)

All 8 running containers were patched in-place via `docker exec`:
- Overwrote `mpm_prefork.conf` + `apache2ctl graceful` (no dropped connections)
- Patched `shared_buffers` in `postgresql.conf` + Postgres restart per container

