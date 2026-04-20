# Specification: Real client IP inside docker-prod containers

## Overview

Every site on docker-prod sits behind a two-tier Apache chain: **host Apache** reverse-proxies `public IP:80/443` → `127.0.0.1:{port}` → **container Apache** → PHP. Inside the container, `$_SERVER['REMOTE_ADDR']` is always `172.17.0.1` — the Docker bridge gateway, i.e., the host itself. Every layer of IP-based behavior in the application (API key `apk_ip_restriction`, `RequestLogger` rate limiting, analytics, audit logs) breaks silently in ways that are easy to miss until someone tries to use them.

Enable Apache's `mod_remoteip` inside each container and configure it to trust the `X-Forwarded-For` header when it arrives from the Docker bridge. The host proxy already writes that header; the container just isn't reading it. `mod_remoteip` rewrites `REMOTE_ADDR` in place before PHP sees the request, so **zero application code changes**.

Fix the one site where the proxy config doesn't explicitly override `X-Forwarded-For` (manage_domain.sh-generated configs) so attacker-supplied header values can't slip through.

## Motivation

### 1. This came up trying to roll out the management API
Discovered while configuring the first management-API key for the Server Manager rollout: setting an IP restriction of `69.164.209.253` (the control plane's real egress IP) caused every request to be rejected as "Unauthorized IP." Inspecting the container's access log showed every request arriving from `172.17.0.1`. Setting the restriction to `172.17.0.1` "works" but authorizes *any* request that reaches the public URL — it's a no-op.

### 2. Other features affected silently
- `RequestLogger::check_rate_limit()` rate-limits per `$_SERVER['REMOTE_ADDR']`. Today, on every docker-prod site, the whole internet shares one rate-limit bucket. A single noisy user trips the limit for everyone.
- Analytics, attribution, visitor events — anything that logs source IP today records `172.17.0.1`. Worthless.
- `apk_ip_restriction` on API keys — unusable, per above.

### 3. Fix is one Apache module
`mod_remoteip` is the purpose-built mechanism for this. It's installed on the base image, just not enabled. The logic is small and well-understood: trust proxies listed in `RemoteIPInternalProxy`, walk the `X-Forwarded-For` list, rewrite `REMOTE_ADDR` to the real client. Apache core and PHP see the corrected value.

## Non-Goals

- **No PHP code changes.** `mod_remoteip` rewrites `REMOTE_ADDR` before PHP runs. Application code (including the existing `apiv1.php` IP-restriction check) works unchanged.
- **Not reworking audit log columns.** Rows logged with `172.17.0.1` before the fix stay that way. The fix only affects requests from this point forward.
- **Not changing IP-restriction semantics.** A key IP-restricted to `A` was supposed to mean "from IP `A`" all along; we're finally making the app see the right value.
- **Not a one-off fix for empoweredhealthtn.** All docker-prod containers have the same issue; the spec fixes all of them and bakes the fix into the install scripts so future sites are correct from day one.

## Architecture

### Request flow today

```
Client (real_ip)
  →  Cloudflare (if enabled) sets X-Forwarded-For: real_ip
  →  Host Apache
     - Sees $_SERVER['REMOTE_ADDR'] = cloudflare_ip OR real_ip
     - Writes RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s   ← install.sh path
       (or leaves mod_proxy's auto-appended header as-is          ← manage_domain.sh path)
     - ProxyPass to http://127.0.0.1:{port}/
  →  Container Apache
     - $_SERVER['REMOTE_ADDR'] = 172.17.0.1  ← *** problem ***
     - X-Forwarded-For present but ignored
  →  PHP
     - Reads $_SERVER['REMOTE_ADDR'] = 172.17.0.1
```

### Request flow after this spec

```
Client (real_ip)
  →  Cloudflare (if enabled) sets X-Forwarded-For: real_ip
  →  Host Apache
     - Sees $_SERVER['REMOTE_ADDR'] = cloudflare_ip OR real_ip
     - SETS (not appends) X-Forwarded-For %{REMOTE_ADDR}s         ← deliberate override
     - ProxyPass to http://127.0.0.1:{port}/
  →  Container Apache + mod_remoteip
     - Incoming REMOTE_ADDR = 172.17.0.1, matches RemoteIPInternalProxy
     - Walk X-Forwarded-For backward through trusted proxies
     - Rewrite REMOTE_ADDR = real_ip                              ← the fix
  →  PHP
     - Reads $_SERVER['REMOTE_ADDR'] = real_ip ✓
```

### Why `RequestHeader set` (not append)

`mod_proxy_http` appends to `X-Forwarded-For` automatically. If the client sent `X-Forwarded-For: 1.2.3.4` and their real IP is `5.6.7.8`, the container receives `X-Forwarded-For: 1.2.3.4, 5.6.7.8`. Without `RemoteIPInternalProxy` trust chains configured exactly right, `mod_remoteip` could walk past `5.6.7.8` and pick `1.2.3.4` as the "real" client.

`RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s` at the host proxy **overwrites** whatever the client sent, producing a single-value header the container can trust unambiguously. `install.sh` already does this; `manage_domain.sh` does not — spec fixes that.

### Trust boundary

`RemoteIPInternalProxy 172.17.0.0/16` says: "trust a claim of X-Forwarded-For only when it comes from the docker bridge." The only process that can send requests from that range is the host's Apache (or another container on the same bridge, which is a non-issue in our topology). An external attacker cannot originate from `172.17.0.1`, so they cannot poison `X-Forwarded-For` on a request that reaches the container.

## Changes

### 1. Dockerfile template — enable mod_remoteip at build time

`maintenance_scripts/install_tools/Dockerfile.template`: add `a2enmod remoteip` to the RUN step that sets up Apache, and write a `/etc/apache2/mods-available/remoteip.conf` override file. This bakes the fix into every new container image from the day this ships.

```dockerfile
# Trust the docker bridge gateway for X-Forwarded-For; everything beyond it
# (including Cloudflare) is handled by the host proxy, not here.
RUN a2enmod remoteip && \
    printf '%s\n' \
      'RemoteIPHeader X-Forwarded-For' \
      'RemoteIPInternalProxy 172.17.0.0/16' \
      'RemoteIPInternalProxy 127.0.0.1' \
      > /etc/apache2/mods-available/remoteip.conf && \
    # Put real IP, not the bridge gateway, in access logs from day one.
    # Ubuntu's default LogFormat names are "combined" and "common".
    sed -i 's/%h /%a /' /etc/apache2/apache2.conf
```

The `%h → %a` log format swap is a quiet but important side-effect: access logs will show real client IPs going forward. (`%h` prints the raw REMOTE_ADDR; `%a` prints the mod_remoteip-resolved one.) Without this tweak, investigating "who did X" still shows `172.17.0.1` in the log file even though the application sees the right value.

### 2. manage_domain.sh — write consistent proxy config

`maintenance_scripts/sysadmin_tools/manage_domain.sh:317-328`: add the three `RequestHeader set` lines to the heredoc so its output matches `install.sh`'s output. Today, sites created via `install_node` (through the Server Manager) end up with a less-defensible proxy config than sites created via `install.sh site` directly; that inconsistency should not exist.

```apache
<VirtualHost *:80>
    ServerName ${domain}

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${port}/
    ProxyPassReverse / http://127.0.0.1:${port}/

    RequestHeader set X-Real-IP %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s
    RequestHeader set X-Forwarded-Proto "http"

    ErrorLog /var/www/html/${sitename}/logs/proxy_error.log
    CustomLog /var/www/html/${sitename}/logs/proxy_access.log combined
</VirtualHost>
```

### 3. One-shot fix for existing containers

Running containers don't rebuild from `Dockerfile.template` — they'd need `docker stop && docker rm && docker run --rm` with fresh builds, which is disruptive. Instead, ship a small script `maintenance_scripts/sysadmin_tools/enable_remoteip.sh` that can be run per-container:

```bash
#!/usr/bin/env bash
# Usage: enable_remoteip.sh CONTAINER_NAME
# Idempotent. Enables mod_remoteip inside a running container and reloads Apache.
set -euo pipefail
container="${1:?usage: enable_remoteip.sh CONTAINER_NAME}"

docker exec "$container" bash -c '
  a2enmod remoteip >/dev/null
  cat > /etc/apache2/mods-available/remoteip.conf <<EOF
RemoteIPHeader X-Forwarded-For
RemoteIPInternalProxy 172.17.0.0/16
RemoteIPInternalProxy 127.0.0.1
EOF
  # Swap %h → %a in access log format — only once.
  if ! grep -q "^LogFormat .*%a " /etc/apache2/apache2.conf; then
    sed -i "s/%h /%a /" /etc/apache2/apache2.conf
  fi
  apachectl configtest
  apachectl graceful
  echo "REMOTEIP_ENABLED"
'
```

Run across all docker-prod sites as a one-off rollout step:

```bash
ssh root@23.239.11.53 'for c in empoweredhealthtn scrolldaddy galactictribune getjoinery jeremytunnell joinerydemo mapsofwisdom phillyzouk; do
  bash /tmp/enable_remoteip.sh "$c"
done'
```

(The script does not live inside containers permanently; it's a maintenance tool on the host.)

### 4. Host-side proxy regeneration for existing sites

Regenerate each `/etc/apache2/sites-available/{sitename}-proxy.conf` with the new template. Use `manage_domain.sh set {sitename} {domain}` — it already exists and is idempotent. Do this *after* step 3, because the host reload is what makes the stricter `X-Forwarded-For: set` take effect.

## Cloudflare interaction

Cloudflare-fronted sites have an extra hop:

```
Client  →  Cloudflare  →  Host Apache  →  Container Apache
```

Two ways for Cloudflare to expose the real client IP:
1. `X-Forwarded-For` — Cloudflare prepends the real IP.
2. `CF-Connecting-IP` — Cloudflare sets this to exactly the real IP, no list semantics.

**Decision:** keep using `X-Forwarded-For`. The host proxy currently does `RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s`, where `%{REMOTE_ADDR}s` is *Cloudflare's* IP (not the real client) because the client connected to Cloudflare, not to us. **This is wrong but pre-existing**, and fixing it is a separate spec: the host would need to trust Cloudflare's IP ranges (`RemoteIPTrustedProxy 173.245.48.0/20` etc.) and read the real IP from CF-Connecting-IP before writing X-Forwarded-For onward. Explicitly out of scope for this spec — today's Cloudflare sites still won't see the real client IP, but they'll stop seeing `172.17.0.1`. That's a strict improvement.

## Verification

For each migrated site, after enabling the module:

```bash
# From an outside host with a known IP (laptop, control plane, etc.):
curl -sI https://empoweredhealthtn.com/ping  # any URL that gets logged

# On docker-prod, inspect the container's access log:
ssh root@23.239.11.53 'docker exec empoweredhealthtn tail -1 /var/www/html/empoweredhealthtn/logs/access.log'
```

Expected: the log line starts with the laptop/control-plane IP, not `172.17.0.1`.

Concrete verification for the management API:

```bash
# Control plane
curl -H "public_key: ..." -H "secret_key: ..." https://empoweredhealthtn.com/api/v1/management/health
```

With the key IP-restricted to the control plane's egress IP (`69.164.209.253`), this should now return `{"ok":true,...}` instead of `"Unauthorized IP"`.

## Rollout plan

Land in this order to keep things reversible at every step:

1. **Ship `enable_remoteip.sh`** to the control plane. Run against one canary site (`empoweredhealthtn` — that's the one already failing on management-API IP restriction, so success is visible immediately).
2. **Re-probe the management API credential** on that site's Server Manager node page. The "API healthy" indicator should turn green with the restriction still set to `69.164.209.253`.
3. If canary looks good, **run `enable_remoteip.sh` against the other 7 sites**.
4. **Run `manage_domain.sh set {sitename} {domain}`** for each site to regenerate the host proxy config. Verify `apachectl configtest` is clean after each, reload.
5. **Update `manage_domain.sh`** and `Dockerfile.template` in the repo (changes #1 and #2 above). Commit + publish upgrade. New sites built from this point have the fix baked in.
6. **Update `install.sh`** if anything there is incompatible with the module (it already sets the header correctly, so probably no change).

**Rollback:** per-container, `a2dismod remoteip && apachectl graceful` reverts the fix for that container. No data migration to undo.

## Open questions

1. **Should `CF-Connecting-IP` get its own spec right now, or wait until a Cloudflare-fronted site actually trips on the limitation?** Argument for now: gives full IP correctness across the fleet. Argument for later: no Cloudflare site is known to rely on real-client IP today; adding code to trust CF's IP ranges has its own attack surface (stale CF IP list = spoofed client IPs). Deferring until a concrete need lands feels right.
2. **IPv6 bridges.** Docker's default bridge is IPv4 only; the IPv6 egress address we saw earlier (`2600:3c03::...`) is for outbound connections from docker-prod, not a container listener. Not relevant for this spec. Leaving a note in case a future bridge config changes this.

## Docs to update

- **`docs/server_manager.md`** — in the Management API section, swap the "IP-restrict to the control plane's egress IP" tip for a note that IP restriction is meaningful on bare-metal nodes today and will become meaningful on docker-prod nodes once this spec ships.
- **`docs/deploy_and_upgrade.md`** (if there's a networking/proxy section — otherwise skip) — document the two-tier Apache chain and the `X-Forwarded-For → mod_remoteip` contract so future Joinery authors don't re-discover it.

## Success criteria

1. On every docker-prod site, access log lines start with the real client IP (not `172.17.0.1`).
2. `$_SERVER['REMOTE_ADDR']` inside PHP equals what the outside world sees as the client.
3. The management API key created for empoweredhealthtn, IP-restricted to `69.164.209.253`, authenticates successfully from the joinerytest.site control plane.
4. `RequestLogger` rate-limit counters are per-real-client, not per-docker-bridge.
5. New sites created via either `install.sh site` or the Server Manager's install_node flow end up with identical proxy configs — no post-install patchup required.
