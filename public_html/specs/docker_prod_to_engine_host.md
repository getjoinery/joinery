# Moving docker-prod's Sites to an Engine Host

**Status:** Draft, 2026-10-21. Written at the owner's request ("lets create a side spec for that
specific move", `fleet_security_patching.md` Q1). This is the detailed plan for
`site_copy_final_test.md` Part P step 3. Nothing is built or run yet. Q1 is decided (2026-10-22):
the move waits for the encryption steps.

## The goal in plain terms

The six sites on docker-prod move one at a time to a new standard container host. Each site keeps
its address, data, mail and members, and each has a way back until the owner says Keep. When the
last one is kept, docker-prod is deleted.

Why move them:

- docker-prod runs plain Docker. On Docker, every site on the host shares one set of user ids, so
  a breakout from one site owns all of them. The standard host (the site container engine, our
  own Podman build) gives each site its own ids (`site_container_engine.md`).
- docker-prod never patched its containers (B10 in `fleet_security_patching.md`). An engine host
  replaces them nightly when Ubuntu ships a fix.
- No site on docker-prod has a memory cap. An engine host caps each site.

## What lives on docker-prod (read-only inventory, 2026-10-21)

The host:

- Ubuntu 24.04.3, Docker 29.1.5.
- Linode, 2 CPU, 4 GB, 78 GB disk (44% used). Address 23.239.11.53.
- Host agent 1.87.0, which has `site_quiet_host` and `hold_container`.
- Nothing runs on it but the six sites, Apache in front of them, certbot, and the agent.

| Site (container) | Domain | Cloudflare | Cloudflare account (NS pair) | Inbound mail | Release | Data | Memory now |
|---|---|---|---|---|---|---|---|
| getjoinery_developers | developers.getjoinery.com | **not proxied** (A → 23.239.11.53, no www) | aryanna/colin | none | 0.8.516 | 630 MB | 175 MB |
| getjoinery_orgs | orgs.getjoinery.com | proxied (no www) | aryanna/colin | none | 0.8.517 | 471 MB | 160 MB |
| mapsofwisdom | mapsofwisdom.org | proxied | chuck/cortney | relay1.getjoinery.com | 0.8.517 | 558 MB | 187 MB |
| galactictribune | galactictribune.net | proxied | chuck/cortney | relay1.getjoinery.com | 0.8.517 | 594 MB | 184 MB |
| phillyzouk | phillyzouk.org | proxied | aryanna/colin | relay1.getjoinery.com | 0.8.517 | 591 MB | 164 MB |
| scrolldaddy | scrolldaddy.app | proxied | aryanna/colin | relay1.getjoinery.com | 0.8.516 | 770 MB | 160 MB |

This inventory was taken over SSH, read-only, because this session cannot reach getjoinery's plane.
**Suggested agent addition:** the host report carries, for each site, its domain names and every
published port with its address. A move's inventory then comes from the node page, and a stale
published port (like scrolldaddy's below) shows up there.

Other facts found:

- **The zones are in two Cloudflare accounts.** Their nameserver pairs differ: aryanna/colin and
  chuck/cortney. A proxy switch needs a token for the site's zone, typed in at the switch, so the
  owner needs a token from each account.
- **scrolldaddy's database is still published on the private address**
  (192.168.206.198:9087). This is left over from when the ScrollDaddy DNS servers read the
  database directly. They now read the site's `resolver_snapshot` API action over HTTPS (the
  servers' OPS_GUIDE says nothing reaches its database). docker-prod's wall drops the port on
  eth0, and nothing connects to it. The engine refuses a site that publishes its database
  anywhere but loopback, so the copy drops it.
- **demo.getjoinery.com is not served here.** It has no virtual host on docker-prod. Any name
  docker-prod does not know gets galactictribune.net, the first virtual host.
- Inbound mail for four domains goes to the relay, and each site collects its mail from the
  relay. The MX records do not name docker-prod, so they do not change.

## Before the first site

1. **Our encryption can be born on getjoinery's plane.** `hosted_data_promise_disk_encryption.md`
   D7 steps 3–6 are done:
   - dev is encrypted;
   - the release keys are rotated;
   - getjoinery is encrypted and has `data_root_encryption_offered` on;
   - dev and getjoinery hold each other's escrow.

   This is Part P's gate (see Q1).
2. **Run C8 has passed on the test plane** (`site_copy_final_test.md`). It is this same move: a
   live container on a Docker host into a container on an engine host born with our layer.
3. **Every site has a good backup from the last day** in its backup storage. A copy is built from
   the site's backups, then gets a final copy at the freeze. Each site's Backups tab shows it.
4. **getjoinery and all six sites are on the newest release**, and so is docker-prod's host
   agent.
5. **Until the move, docker-prod patches itself.** `fleet_security_patching.md` WP4 turns on the
   nightly container refresh for Docker hosts. This is not a gate. It is what makes waiting safe.

## The new host

- Made from getjoinery's Install New Node as an engine host: Ubuntu 26.04, a Linode in Joinery's
  account, `install.sh docker --site-engine --multi-tenant`.
- Born with our encryption layer and Linode's off, its container storage on the data root.
- The same size as docker-prod (2 CPU, 4 GB) is enough:
  - The six sites use 160–190 MB each and 3.6 GB of data together.
  - A fresh base build took 123 s on that size (test box dt1).
  - The host's load average on 2026-10-21 was about 0.6.
- Any region. Nothing reaches docker-prod over Linode's private network any more.
- Its nightly container refresh comes from host housekeeping on its first run. Its node page
  shows one encryption layer.

## Moving one site

Each site moves from its own node's Copy tab on getjoinery, the same way every time:

1. **Copy** onto the new host, as a container there. The copy starts quiet: visitors see 503, and
   it sends no mail.
2. **Check the copy**, as every final-test run is checked (`site_copy_final_test.md`, "What every
   run checks"):
   - the site works and every secret opens;
   - `CopySecurityCheck` passes;
   - the node page says one layer.
3. **Switch by proxy.** getjoinery moves the Cloudflare records for the domain and its `www.` to
   the new host, using a token for that zone typed in at the switch. The old container is held
   stopped on docker-prod as the way back.
   - developers.getjoinery.com is not proxied, and a proxy switch refuses a record that is not.
     Turn Cloudflare's proxy on for it first. Its origin is then hidden like the others.
4. **Check after the switch:**
   - the site answers through Cloudflare from the new host;
   - it sees visitors' real addresses (the trusted-proxy setting);
   - its scheduled tasks run;
   - it sends mail;
   - for the four relay domains, a test message reaches the site through the relay.
5. **Keep** after a day. Permanently Delete Site then removes the held container on docker-prod.

**scrolldaddy.app, in addition.** Both ScrollDaddy DNS servers read its snapshot. Their read keys
are tied to each server's IPv4 address, which the site sees only through Cloudflare's real-address
header. After the switch, both servers' `/health` must read fresh, not `stale`, within their
reload interval. If it reads stale, go back before the servers' cache ages.

## Order

The lowest stakes go first, and each site is kept for a day before the next starts:

1. developers.getjoinery.com (ours; it also proves the step that turns the proxy on)
2. orgs.getjoinery.com (ours)
3. mapsofwisdom.org (first one with relay mail)
4. galactictribune.net (first one from the second Cloudflare account)
5. phillyzouk.org
6. scrolldaddy.app (last: its DNS servers depend on it)

## Retiring docker-prod

After the last Keep:

- No container is left on docker-prod.
- Nothing answers on its address but the default page.
- No DNS record names 23.239.11.53.

Then, within a day, delete it at Linode together with its Linode backups and snapshots. It holds
production secrets under Linode's layer only: each site's `secret_box_key`, DKIM keys, ACME
account and `relay_pull_key`. Its node is removed from getjoinery's plane.

## Owner decision

- **Q1 — Wait for the encryption steps (decided 2026-10-22, owner: "wait on docker prod").** Waiting means the sites land once, on a
  host with our encryption and only our encryption. The catch: docker-prod stays on Docker until
  the owner does D7 steps 3–6. Moving sooner means the new host is born with Linode's layer only.
  It is encrypted in place later, with D7 step 7, and keeps both layers until it is replaced. That
  goes against the owner's 2026-10-18 rule: one layer, by copy, wherever a copy can do it.
  Once WP4 has docker-prod patching itself nightly, waiting costs only the per-site ids.

## Done when

- All six sites serve from the new host, each kept, each checked as above.
- scrolldaddy's DNS servers read fresh.
- The new host's node page says one layer, and its nightly refresh has run.
- docker-prod and its Linode backups are deleted, and its node is gone from getjoinery's plane.
- `site_copy_final_test.md` Part P step 3 is done.
