# TLS Certificate Expiry Monitoring & Node Alerting — Spec

Turn a silent, weeks-long certificate-renewal failure into an early warning
that reaches an operator long before users are affected. Prompted by the
2026-07-02 ScrollDaddy DNS outage (postmortem below), but the fix is a
platform capability for **every managed node**, not a ScrollDaddy patch: the
control plane already tracks a fleet of nodes and already has the scheduled
task and email-alert plumbing — it just wasn't watching the one thing that
took the service down.

## Postmortem — 2026-07-02 ScrollDaddy DNS outage

### What users saw

ScrollDaddy devices resolve DNS over HTTPS (DoH) against
`https://dns.scrolldaddy.app/resolve/<uid>` on port 443. Both DNS servers
(`45.56.103.84` primary, `97.107.131.227` secondary) stopped answering, so
every device on the service lost name resolution — indistinguishable from
"the internet stopped working." Detected only when the operator's own iPad
went offline, roughly nine hours after the failure began. No automated alert
fired.

### Root cause

The TLS certificate for `dns.scrolldaddy.app` expired around **13:15 UTC on
2026-07-02**. With an expired cert the DoH TLS handshake fails, so no query
ever reaches the resolver. The resolver process and Caddy were both healthy
the entire time — the break was TLS-only, which is why nothing looked
obviously wrong on a surface check.

Caddy fronts the resolver and auto-renews the cert using a **DNS-01 challenge
via Cloudflare** (required because the hostname has two A records, one per
server, which makes HTTP-01 unreliable). Renewal had been failing on both
servers for the entire ~30-day pre-expiry window because the **Cloudflare API
token** Caddy uses for the challenge was being rejected:

```
HTTP 403: Invalid access token (Code 9109)     ← Caddy renewal log
{"success":false,"errors":[{"code":1000,"message":"Invalid API Token"}]}  ← token verify
```

The token stored on both servers
(`/etc/systemd/system/caddy.service.d/cloudflare.conf`) had been invalidated
at some point (rotation, or a token with an expiration date) and the deployed
copy was never updated. The Cloudflare dashboard still showed *a* token as
"Active" — but a token's secret is shown only once at creation and cannot be
read back, so the dashboard's "Active" said nothing about whether the value
on disk still matched.

### Why it went undetected for weeks

Three gaps, each independently sufficient to hide the failure:

1. **Uptime monitoring was switched off fleet-wide.** The `RunNodeUptimeChecks`
   scheduled task is active and runs every ~15 min, but `mgn_uptime_enabled`
   is `false` on **every** node, including both DNS servers — so the task
   iterates and skips everything. Even had it been on, it only alerts at the
   moment of outage, not during the weeks of failed renewals leading up to it.
2. **Certificate-expiry tracking is blind to Caddy.** The existing SSL
   detection (`check_status` job → `mgn_ssl_state`, `ssl_expiry_ts`) reads
   certbot files under `/etc/letsencrypt/live/{domain}/`. The DNS servers use
   Caddy with its own cert store (`/var/lib/caddy/.local/share/caddy/`), so
   both DNS nodes have an empty `mgn_ssl_state` — their certs were never
   tracked at all.
3. **No one watches Caddy logs.** The renewal errors were logged clearly and
   repeatedly, to a place no human or system reads.

### Impact

Total loss of DNS resolution for all ScrollDaddy DoH users for ~9 hours
(from expiry to manual fix). No data loss.

### Contributing / systemic factors

- **Correlated failure.** Both servers serve the same cert identity and share
  the same Cloudflare token, so they fail together — the redundant second
  server provides no protection against this failure class.
- **Shared-hostname health checks can't see a single dead node.** Both DNS
  nodes carry the same `mgn_health_check_url` (`https://dns.scrolldaddy.app/health`).
  A check against that hostname hits whichever A record DNS returns, so one
  dead server hides behind its live partner (see fix #2).

### Immediate remediation (completed 2026-07-02)

A fresh Cloudflare API token (Zone → DNS → Edit, scoped to `scrolldaddy.app`)
was deployed to both servers over stdin, Caddy fully restarted (which also
cleared a stale in-memory staging-ACME account left from an earlier
edit-without-restart), and both servers issued a valid production Let's
Encrypt cert (valid through 2026-09-30). Verified end-to-end with a live
RFC 8484 wire-format DoH query against each server pinned to its own IP.
See memory `reference_scrolldaddy_cert_outage.md` for the recovery runbook.

## Primary-problem fix (durability of renewal)

The immediate token swap restores service; these make the renewal itself
robust:

1. **Non-expiring token.** Recreate/confirm the Cloudflare token has **no
   expiration date**. A token TTL turns automatic renewal into a scheduled
   time bomb. Scope stays minimal: Zone → DNS → Edit on `scrolldaddy.app`
   only.
2. **Monitoring is the real backstop.** Root cause could equally have been a
   revoked token, a Cloudflare API outage, an ACME rate-limit, or a config
   error. Rather than defend against each cause, monitor the **outcome** —
   how many days of validity the served certificate has left — which catches
   *any* renewal failure weeks ahead. That is the bulk of this spec.

Note: DNS-01-via-Cloudflare is inherent to the dual-A-record design and is
not being changed. The correlated-failure risk is accepted and mitigated by
monitoring both nodes independently, not by re-architecting issuance.

## Monitoring & notification improvements (the platform work)

Everything here builds on existing infrastructure — the `RunNodeUptimeChecks`
scheduled task, the `mgn_managed_nodes` model, and the alert-email recipient
fallback chain. No new notification system.

### 1. Turn on what already exists (configuration)

- Set `mgn_uptime_enabled = true` on both DNS nodes and on every production
  node. Monitoring that ships disabled is monitoring that isn't there.
- Set `server_manager_provisioning_admin_alert_email` (currently empty) to a
  monitored operator address so alerts have a definite destination. The
  fallback chain (→ `webmaster_email` → first permission-10 user) stays as a
  safety net.

This alone restores the at-outage down/recovered alert for the DNS servers
via the existing task.

### 2. Per-node health checks must target the node, not the hostname

`RunNodeUptimeChecks::check_http_status()` curls `mgn_health_check_url`
directly, so for a multi-A-record hostname it tests whichever server DNS
happens to return — a dead node hides behind a live one.

**Change:** when a node has a host IP, pin the health request to it while
preserving SNI — `CURLOPT_RESOLVE` = `{host}:{port}:{mgn_host}` (the curl
`--resolve` mechanism, exactly how each server was verified during
remediation). Each node is then checked as itself. This is a general
correctness fix for any node behind round-robin DNS, load balancing, or a
shared hostname — not DNS-specific.

### 3. Certificate-expiry alerting for self-renewed nodes (new capability)

**A fleet probe reshaped this from the original design — read this before
implementing.** Probing every managed node's origin over the wire
(`mgn_host:443`, correct SNI) revealed two distinct node classes:

- **Directly-exposed, self-renewed nodes** — the two ScrollDaddy DNS servers.
  Public DNS points straight at `mgn_host`; the probe returns exactly the cert
  we renew (`CN=dns.scrolldaddy.app`, Let's Encrypt). This is the class that
  caused the outage, and it has **no expiry monitoring or alerting today** —
  the existing certbot-file detection looks under `/etc/letsencrypt/live/`,
  where Caddy stores nothing, so `mgn_ssl_state` for both DNS nodes is empty.
- **Cloudflare-fronted nodes** — every other production site. Public DNS
  points at Cloudflare; the user-facing cert is Cloudflare's edge cert, which
  Cloudflare auto-renews. Probing their origin `mgn_host:443` returns the host
  Apache **default-vhost fallback cert** (`CN=orgs.getjoinery.com`) for seven
  different domains — because the origin has no per-domain cert for them at
  all. Monitoring that origin cert's expiry would be monitoring the wrong,
  shared cert.

The design consequence: **cert-expiry alerting applies only to self-renewed,
directly-exposed nodes** — the ones where our renewer is the single point of
failure. Today that set is exactly the two DNS servers; any future
directly-exposed node joins it automatically. Cloudflare-fronted edge certs
are explicitly out of scope (Cloudflare renews them; that's not our failure
surface).

**The mechanism already half-exists.** `check_status` jobs already parse an
LE cert's expiry into `mgn_last_status_data.ssl_expiry_ts`, and the node
detail SSL tile already renders a "Expires …" warning badge under 30 days
(`node_detail.php:731`). What's missing is (a) it only refreshes on a manual
`check_status` job, not continuously; (b) it never fires an **alert**, just a
passive badge nobody watches; (c) it's blank for the Caddy/DNS nodes. So the
work is to **add continuous refresh + alerting**, not build a new monitoring
subsystem.

**Change:**

- On each uptime tick, for a node that is directly-exposed (its
  `mgn_host` appears in the public A records for its hostname — the
  non-Cloudflare case), open a TLS connection to `mgn_host:443` with SNI =
  the site hostname (`stream_socket_client` + `openssl_x509_parse`), read the
  peer cert `notAfter`, and **verify the cert's SAN actually covers the
  hostname**. The SAN check is the guard that makes this safe: a
  Cloudflare-fronted origin returns the fallback cert (SAN mismatch) → treated
  as "no monitorable self-renewed cert here" → skipped, never alerted on.
- Store the observed expiry in a dedicated `mgn_cert_expiry_ts` column — **not**
  the existing `ssl_expiry_ts` inside `mgn_last_status_data`. Reusing that JSON
  was the original plan, but it is rewritten wholesale by `check_status` jobs
  (which rebuild the blob and only include `ssl_expiry_ts` when they find a
  certbot cert on disk). Two writers with different key sets would clobber each
  other, so the wire probe gets its own column. The overview surfaces it on its
  own line (see UI) since the SSL tile doesn't render for these nodes anyway.
- When days-remaining drops below a threshold (**default 21 days**, new plugin
  setting `server_manager_cert_expiry_warn_days`), send a **warning** email
  through the existing recipient chain. Track the alert with one timestamp
  (`mgn_cert_alerted_ts`): alert once on crossing the threshold, re-alert on a
  coarse cadence (every 3 days) while still under it, and clear when a fresh
  cert pushes the date back out — so a stuck renewal nags without spamming
  every 15 minutes.

Why over-the-wire for this class:

- **Cert-manager-agnostic where it matters.** Reads what clients receive, so
  it works for Caddy (the DNS nodes) exactly as for certbot — the current
  file-based detection structurally cannot see the Caddy cert, which is why
  this outage was invisible.
- **One signal catches all renewal failures.** A healthy auto-renewer keeps
  the served cert 30–90 days out; if it slips under the threshold and stays
  there, renewal is broken — token, rate-limit, ACME account, or otherwise.
  No per-cause special-casing.

### 4. Optional defense-in-depth (not required for the fix)

- **Renewal-failure signal for Caddy nodes.** A check that greps
  `journalctl -u caddy` for cert-renewal errors, or verifies the deployed
  Cloudflare token against `GET /client/v4/user/tokens/verify`, surfaces a
  broken renewer even earlier than the expiry threshold. The wire-based
  expiry warning already covers the outcome, so treat this as an
  enhancement, not a dependency.

## Design principles

1. **Monitor outcomes, not causes.** One "days of cert validity remaining"
   number catches every way renewal can break. Enumerating failure modes and
   guarding each is the band-aid; watching the served cert is the cause-level
   fix.
2. **Read what the client reads — for the certs we renew.** Over-the-wire
   inspection is immune to which cert manager or storage path, so it sees the
   Caddy cert the file-based check can't. It is *not* universal, though: for
   Cloudflare-fronted origins it returns a shared fallback cert, so a SAN-match
   guard scopes it to self-renewed, directly-exposed nodes.
3. **Reuse the existing rails.** Scheduled task, node model, and alert
   recipient chain all already exist. The new work is a probe on the tick plus
   an alert — not a monitoring subsystem. (Expiry lands in a dedicated column
   rather than the shared `ssl_expiry_ts` JSON only because `check_status`
   rewrites that blob wholesale and the two writers would clobber each other.)
4. **Alerting that ships on.** Defaults enabled, with a real recipient
   configured, or it protects nothing.

## Data model changes

Add to `mgn_managed_nodes` (via `$field_specifications`, auto-applied — no
migration). Two columns: the observed expiry and the alert bookkeeping. (Expiry
gets its own column rather than reusing `mgn_last_status_data.ssl_expiry_ts`
because `check_status` rewrites that JSON wholesale — see the fix-#3 note.)

| Column | Type | Purpose |
|---|---|---|
| `mgn_cert_expiry_ts` | timestamp, nullable | `notAfter` of the served cert, from the wire probe |
| `mgn_cert_alerted_ts` | timestamp, nullable | Last time a cert-expiry warning was sent, for re-alert cadence; cleared when a fresh cert pushes the date back past threshold |

New plugin setting (declared in `plugin.json`, seeded automatically):

| Setting | Default | Purpose |
|---|---|---|
| `server_manager_cert_expiry_warn_days` | `21` | Warn when a self-renewed node's served cert has fewer days remaining |

## UI

The node detail overview shows a "TLS cert: expires … (N days)" line
(warning-styled under threshold) whenever `mgn_cert_expiry_ts` is set. This is a
new line rather than reuse of the existing SSL tile, because that tile only
renders when `mgn_ssl_state` (certbot provisioning) is populated — which it
never is for the Caddy DNS nodes, exactly the class this feature exists for.

## Rollout & verification

1. Apply schema (two columns) + setting (Sync with Filesystem / `update_database`).
2. Enable `mgn_uptime_enabled` on DNS + production nodes; set the alert email.
   *(Done for the two DNS nodes on 2026-07-02; both verified `up`.)*
3. Verify the tick records `ssl_expiry_ts` for both DNS nodes (Caddy certs —
   the case the old detection missed) and **verify a Cloudflare-fronted node is
   correctly skipped** (SAN mismatch on the origin fallback cert → no expiry
   written, no alert).
4. **Failure-path test:** temporarily set `server_manager_cert_expiry_warn_days`
   above the DNS cert's current days-remaining and confirm exactly one warning
   email arrives, that it re-alerts on cadence, and that it clears when the
   threshold is restored.
5. **Multi-node test:** stop Caddy on the secondary only; confirm the
   per-node (IP-pinned) check flags the secondary down while the primary stays
   up — proving the shared-hostname blind spot is closed.

## Docs to update

- `plugins/server_manager/docs/overview.md` — extend the **Uptime
  Monitoring** and **SSL Management** sections: the over-the-wire cert-expiry
  check, the new columns/setting, IP-pinned per-node health checks, and that
  expiry tracking now covers all cert managers (not just certbot).
- `docs/scheduled_tasks.md` — note the cert-expiry warning behavior of the
  `RunNodeUptimeChecks` task if the check lands there.
