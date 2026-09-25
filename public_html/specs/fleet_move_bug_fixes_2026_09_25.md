# Bug Fixes Before the Site Moves Continue

**Status:** WP1–WP7 built and reviewed 2026-09-25 (executor public-html-9a, reviewer
public-html-bb). WP5 and the Caddy diversion were done live. Owner, 2026-09-25: "we
should pause and fix all of these bugs now before moving forward".
- **Final `db --changed`:** 508/510 suites. One failure is `agent_bundle_drift`, which
  the publish clears. The other was a stale `dns_filtering_resolver_user_id` row on
  dev, since deleted; scrolldaddy has the same row.
- **Left:**
  - the release;
  - WP2's live check on getjoinery (upgrade, then publish, then compare with dev);
  - updating jeremytunnell.com.
- The PostgreSQL 18 site moves (`specs/fleet_ubuntu_2604_postgres_upgrade.md` Stage 3)
  resume after the release. joinerydemo is prepared.
**Related:** `specs/fleet_ubuntu_2604_postgres_upgrade.md` (B18–B20),
`specs/dns_resolvers_read_over_https.md` (B4, B8).

## What this does for the owner

Every bug found while moving the fleet gets fixed, so none rides along into the site
moves:
- a site that joins by itself gets backed up;
- the release customers download from getjoinery.com is the release dev signed;
- rebuilding a new site does not change its agent's key;
- the provisioning account cannot be taken over by signing up with its address;
- the two DNS servers share query logs again;
- the DNS server installer can rebuild a server as production runs it;
- the permission sweep stops reporting a false alarm.

## For the executor — read this first

- **Never commit, never `git add`.** The owner runs git.
- **Three repositories:** the Joinery tree, `/home/user1/joinery-agent` (Go, WP1) and
  `/home/user1/scrolldaddy-dns` (Go, WP6).
- **No live box** except where a WP says the owner is present.
- **Installer files and the converger.** `install_tools/` edits on dev (WP7) need the
  host converger stopped by the owner first.
- **Docs describe the current state only. Bump the version header of every file
  touched.**
- **Checks:** `php -l` every PHP file, `validate_php_file.php` on class and function files
  only, `bash -n` shell, and `go test ./...` in Go.
- **Tests:** harness tests with the `@joinery-test` header. Run
  `php tests/run.php --changed`, then `db --changed`; tell the reviewer before a db run.
- **Checkpoints:** one per WP, reviewed against `git diff` before the next starts.

## WP1 — A site that joins by itself gets backed up (B20)

**Bug:** `AgentChannelEndpoint::adoptJoin()` makes the node with no web root.
`ManagedNode::hosts_site_from()` reads an empty web root as "no site", so the node gets:
- no recovery-key report;
- no nightly backup;
- Run backup refuses "does not host a Joinery site".

Nothing says so. The agent knows the path (`ExecEnv.WebRoot`, used by `check_status`).
No live node is affected today: 61130 (the test box) had its path typed in by hand.

**Fix:**
- **Agent (1.44.0):**
  - the join body carries `web_root` when the agent has a site;
  - `check_status` reports `web_root`.
  - A siteless agent sends neither.
- **Management node:**
  - The join request stores it (a new `ajr_web_root` column, via the data class).
  - `adoptJoin()` sets `mgn_web_root` from it.
  - `process_check_status` fills an empty `mgn_web_root` from a reported one.
  - It never overwrites a set one. A mismatch between the two is logged, not applied.
  - Accept only an absolute path ending in `/public_html`.
- **Tests:**
  - agent: both reports;
  - management node:
    - a join with a web root makes a node that hosts a site;
    - a status check fills an empty web root and leaves a set one alone;
    - a malformed path is refused.
- **Ships in a release that carries agent 1.44.0.** Older agents send nothing, and the
  node stays as today.

## WP2 — getjoinery.com serves the release dev signed (B18)

**Bug:** getjoinery republishes by upgrading itself and running `publish_upgrade.php` on
its own tree. Its 0.8.426 archive differed from dev's:
- `joinery-install.sql.gz` is regenerated from getjoinery's own database (157 tables,
  not 204). The carried signed `RELEASE_MANIFEST` names dev's hash, so
  `sha256sum -c RELEASE_MANIFEST` fails.
- It ships `maintenance_scripts/install_tools/deploy.sh` and `_reconcile_stock_assets.sh`,
  both removed from git long ago (253330e4, 4314d992). `upgrade.php` rsyncs
  `maintenance_scripts` without deleting, so they persist on every node.

New customer installs fetch `getjoinery.com/utils/latest_release`.

**Fix:**
- **In republish mode** (`DeploymentHelper::mayMintReleaseVersion()` false), the core
  archive is built from exactly the files the carried manifest lists, each checked
  against its hash as it is copied. A mismatch refuses the publish and names the file.
  - The install SQL is not regenerated: the received one is in the tree, and the
    manifest covers it.
  - The on-disk copy is not overwritten either.
  - Plugin and theme archives follow the same rule against their own manifests.
- **Stale files:** `upgrade.php` removes each `maintenance_scripts` file that the
  previous release's manifest listed and the new one does not. It removes only files a
  release shipped, never a local one.
  - The two files B18 found left git before signed manifests existed (they started
    2026-08-27), so no manifest ever listed them. A one-time named list in `upgrade.php`
    removes `install_tools/deploy.sh` and `install_tools/_reconcile_stock_assets.sh`
    (commits 253330e4, 4314d992). It never grows, since the manifest rule covers
    everything after.
  - Removal runs only after the new tree has deployed, never on a rolled-back upgrade.
  - **Remove the list later** (owner, 2026-09-25): once every managed node has taken the
    release that carries it and the two files are confirmed gone, delete it in the next
    release.
- **Tests:**
  - a republish of a tree with a stray file and a regenerated install SQL yields
    exactly the manifest's files, byte-identical;
  - a tampered file refuses;
  - the upgrade removes a file dropped between two manifests and keeps a local file.
- **Live, owner present:** getjoinery **upgrades first, then republishes.** The upgrade
  puts back dev's install SQL, which its last republish overwrote on disk; publishing
  before upgrading would refuse on that file. The republished archive is then compared
  file by file with dev's.
  - Checked 2026-09-25: every theme and plugin getjoinery republishes carries a
    `RELEASE_MANIFEST`.

## WP3 — Rebuilding a site before approval keeps its agent's key (B19)

**Bug:** `install.sh site` re-passes `--join`. `utils/agent_control.php` writes a new
`requested_time`, and the agent treats a newer ask as a new one: it withdraws, drops its
staged key and asks with a new key. The old request is orphaned (`fresh`: 1788 then
1789).

**Fix:** `agent_control.php --join` keeps the existing request when it names the same
URL and has not been answered. The admin page's own join (`admin_management_node_logic`)
stays a fresh ask on purpose: an operator asking again means it.

**Tests:**
- the same URL twice keeps `requested_time`;
- a different URL, or a cleared request (after a rejection), writes a new one.

## WP4 — The provisioning account is found by its id (running to-dos)

**Bug:** `ProvisioningSetup::setupApiCredentials()` finds its "Provisioning Service"
user (`SERVICE_USER_PERMISSION`) by email, `<local>@<site host>`, through
`User::GetByEmail`.
Whoever registers that address before the account exists becomes the pipeline key's
owner.

**Fix:**
- The account's id goes into a managed setting, and it is found by id.
- A new account gets a random address on the site's host.
- **Existing management nodes** (dev, getjoinery): when the setting is empty, the
  account found by the old address is adopted only if it owns the machine key whose
  public half is in `server_manager_getjoinery_api_public_key`. Otherwise a new
  account is made and a fresh key is minted for it, as a rotation does.

**Live state (read-only, 2026-09-25):**
- dev has no provisioning pipeline configured.
- getjoinery's account at `provisioning@<host>` (user 593) owns the active configured key
  (key 2). Its first setup or rotate click adopts it and mints nothing; until a click,
  nothing changes.

**Tests:**
- a squatted address is not adopted;
- an existing account that owns the key is adopted once;
- the id is used thereafter.

## WP5 — The DNS servers share query logs again (B4) — DONE 2026-09-25

**Two causes, both fixed live with the owner present:**
- **The secondary's private address was assigned in Linode but not active.**
  192.168.151.4 was listed, but eth0 carried only the public address until the owner's
  reboot, when Linode's network helper applied it (`/etc/systemd/network/05-eth0.network`).
  The primary's `SCD_PEER_URL` already pointed there.
- **The two servers held different API keys.** A peer request carries the asking
  server's own `SCD_API_KEY`, so the other server refused it. Each key matched the site's
  setting for that server (fingerprints compared, no key printed).

**Done:**
- The secondary took the primary's key, piped over without printing. Its env was kept as
  `scrolldaddy.env.pre-peer`.
- The site's `dns_filtering_dns_secondary_api_key` was cleared, so `ScrollDaddyApiClient`
  calls the secondary with the primary's key (its built-in fallback).
- The secondary's `SCD_PEER_URL` is now `http://192.168.206.21:8053`.
- The secondary's `ufw` 8053 rule for `192.168.206.198` (docker-prod) was replaced with
  one for `192.168.206.21`. The primary's rule for `192.168.151.4` was already right.

**Verified:**
- The site's keyed call (`/device/{uid}/seen`) works on both servers.
- Each server reads the other's `/device/{uid}/log?peer=0` over the private network
  (HTTP 200 both ways).

**Not run:** a query through one server appearing in the other's log. The owner's
device has query logging off, so there are no logs to share.

## WP6 — The DNS server installer reproduces production (B8)

**Bug:** both servers answer as `dns.scrolldaddy.app`, so each gets its certificate by a
DNS challenge through Cloudflare. Production runs a hand-built Caddy with the Cloudflare
module and a `CF_API_TOKEN`. The installer installs stock Caddy with no DNS challenge,
so on a fresh box issuance depends on which server the ACME check reaches.

**Fix (scrolldaddy-dns 2.0.1):**
- The installer installs Caddy with the `caddy-dns/cloudflare` module and checks for it
  (`caddy list-modules`).
- It takes the token (an env file for `caddy.service`, 0600).
- It writes the Caddyfile with `tls { dns cloudflare {env.CF_API_TOKEN} }`.
- On an upgrade it leaves a working Caddy alone.

**Found in review and fixed live 2026-09-25:** both production servers ran the custom Caddy
in place of the apt package's binary, with no diversion. `caddy` 2.11.4 was pending in
apt, so a manual `apt upgrade` would have replaced it with stock Caddy. Caddy would then
not start with the `dns cloudflare` block, and DoH would go down on that server. The fix
was `dpkg-divert --local --divert /usr/bin/caddy.default --add /usr/bin/caddy` on both.
- **Verified on each:**
  - the binary is unchanged, with the module present;
  - the package's path is `/usr/bin/caddy.default`;
  - Caddy is active, and public DoH answers 200.
- The installer's fresh install diverts (with `--rename`) before installing its Caddy.

**Not in this WP:** a keyless first-boot path, which a hands-off rebuild would also need.
That is a feature, for when a DNS server is next replaced.

## WP7 — The permission sweep ignores a directory that vanishes mid-walk

**Bug:** `fix_permissions.sh --dev` fails when a directory is removed while `find` walks
it (a test fixture's plugin directory). The converger logs "the tree may still be
writable by the web user" when nothing was wrong.

**Fix:** `find -ignore_readdir_race` alone does not do it on dev's GNU findutils 4.9
(traced by public-html-9a, 2026-09-25).
- The flag covers a file that vanishes before its stat, not a directory removed before
  `find` opens it.
- A file removed between the listing and `-exec chown … +` also fails.

So the walking finds run through a small wrapper:
- `LC_ALL=C`, with the flag kept;
- a non-zero exit is tolerated only when every error line is "No such file or directory"
  for a path strictly beneath a sweep root;
- a missing root, any other error, or a failure with no message still fails the sweep.
- A test proves a real error still fails it.

It is in `install_tools/`, so it lands with the converger stopped.

## Owner actions (not code)

- **Test box 45.79.180.75: done 2026-09-25.** The owner deleted the box; dev's node 61130
  was removed and purged, and its 5 offsite objects (66.6 MB) deleted from B2.
- **Update jeremytunnell.com** (on 0.8.428).
- **components_manifest flake:** it crashed once with no captured error. Watch for a
  recurrence; there is nothing to fix without one.
