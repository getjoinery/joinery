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
StartServers            2
MinSpareServers         2
MaxSpareServers         4
MaxRequestWorkers       50
MaxConnectionsPerChild  2000
```

Expected saving: ~100–150 MB across the fleet. Applied via the base Dockerfile / mpm_prefork.conf, rebuild on next upgrade cycle. No functional change for sites at current traffic levels.

### 2. Shrink Postgres `shared_buffers` on small sites (low effort, medium risk)

Current: every container has `shared_buffers = 128 MB`, `max_connections = 100`.

8 × 128 MB = 1 GB pre-reserved for Postgres page cache, most of it wasted on tiny databases. Low-traffic sites could drop to 32–64 MB with no measurable performance impact.

Requires a Postgres restart per site. Best done in the same rebuild window as #1.

### 3. Consolidate to a single shared Postgres instance (high effort)

Each container runs its own walwriter, checkpointer, autovacuum launcher, logical-replication launcher — ~25–30 MB of duplicated postmaster overhead per site. Collapsing 8 instances to 1 would save 200+ MB.

Trade-offs:
- One bad query or lock affects every site.
- Shared restart / upgrade windows.
- Backup / restore tooling has to change.
- Cross-site isolation via roles + `pg_hba.conf` becomes a real security boundary that has to be audited.

Not low-hanging. Revisit if the host ever becomes memory-constrained.

### 4. Migrate from mod_php + mpm_prefork to PHP-FPM + mpm_event (high effort)

Biggest long-term per-request efficiency win, but it's a real migration: base image rebuild, Apache config rewrite, php-fpm pool tuning per site, testing every site's request lifecycle. Only worth it if we're also doing other infrastructure work at the same time.

## Recommendation

Do #1 now as a Dockerfile tweak — 10 minutes of work, pays off on every future site rebuild, and fixes the latent `MaxConnectionsPerChild = 0` leak-accumulation issue as a bonus. Do #2 opportunistically in the same rebuild window. Leave #3 and #4 alone until there's actual memory pressure or another reason to touch that layer.
