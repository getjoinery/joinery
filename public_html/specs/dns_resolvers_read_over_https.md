# DNS Resolvers Read Their Site Over HTTPS

**Status:** WP1–WP5 built and reviewed 2026-09-25, committed (61a23075; resolver repo
2c8252b, installer 2.0.0 rebuilt from it) and released in 0.8.427. Executor
public-html-9a, reviewer public-html-bb.
- Final `db --changed`: 461/463 suites. The two failures were one suite killed at 180 s
  under load (it passed alone, 29/29) and the test user it stranded, which the harness
  reclaims after an hour.
- B6 fixed in core.
- D1 decided: the DNS servers download the blocklists.
- D2 decided: cryptominers moves to the NoCoin list (dns_filtering 1.3.1, 94628e67; on
  scrolldaddy in 0.8.428).
- **WP6, secondary done 2026-09-25 20:00 UTC** (after B7's fix shipped in 0.8.429):
  - key 2, owned by user 1, restricted to 97.107.131.227;
  - `.pre-https` backup kept, and `SCD_DB_*` left in the env;
  - 2.0.0 primed in 12 s (1 device, 18 lists, 3.8 M domains) and swapped in.
  - Gates passed:
    - health 200 ok with `source_ok`;
    - the same verdicts as the 1.8.0 baseline (`pornhub.com` and `doubleclick.net`
      blocked, `example.com` forwarded);
    - the key answers over IPv4 and is refused over IPv6 ("Unauthorized IP");
    - public HTTPS through Caddy is 200;
    - with scrolldaddy.app unreachable, a restart filters from the cache (200 `stale`);
    - RSS 226 MB.
  - Live rule change on the owner's device: a block rule for `example.org` took effect in
    61 s, and its removal in 58 s. The rule is gone.
  - B4 (peer URL) is left as it was: the secondary has no private address, and query logs
    should not cross the internet in clear.
- **WP6, primary done 2026-09-25 20:10 UTC.** The owner went ahead the same day: the two
  servers serve one device, the owner's.
  - key 3, owned by user 1, restricted to 45.56.103.84; `.pre-https` backup kept, and
    `SCD_DB_*` left in the env;
  - primed in 15 s and swapped in;
  - the same gates passed (health ok, baseline verdicts, IPv4 answers and IPv6 refused,
    public HTTPS 200, cache restart 200 `stale`, RSS 226 MB).
  - The check scripts are removed from both boxes.
- **WP7, live, done 2026-09-26** (the owner brought it forward from 2026-10-02):
  - **Evidence:** over one minute, no packets on any database-port rule, no connection to
    scrolldaddy's database port, and no reader session. The DNS servers had read over
    HTTPS since 2026-09-25.
  - **scrolldaddy:** `config/postgres_access.conf` was set aside (kept in
    `/root/rebase/scrolldaddy/` on docker-prod). Housekeeping rebuilt pg_hba as loopback
    plus the Docker host. `DROP OWNED BY` and `DROP ROLE scrolldaddy_reader` ran with the
    owner's yes; 0 such roles are left.
  - **docker-prod's firewall:** install.sh's standard `DOCKER-USER` drop of 9080–9099 on
    eth0 is in place. The tagged exemption, both hand-made ACCEPTs (97.107.131.227 and
    192.168.128.0/17) and the narrower 9080–9087 drop are gone. Saved; the originals are
    `rules.v4.pre-wp7` / `rules.v6.pre-wp7`.
  - **Checked from outside:** 9087 is closed from the primary on docker-prod's private
    and public addresses, and 9082 is closed from dev.
  - **Both DNS servers:** the `SCD_DB_*` lines are removed (copy kept as
    `scrolldaddy.env.pre-wp7`), and the `.pre-https` and `.pre-peer` copies are deleted.
    Both restarted to health 200 ok, `source_ok`.
  - scrolldaddy's container still has its database port bound on 192.168.206.198:9087
    until its next rebuild. The firewall drops it and pg_hba admits no one from the
    network.
- **WP7, code, built 2026-09-26, uncommitted:**
  - `install.sh` 2.86, `host_housekeeping.sh` 1.10, `rebase_site_container.sh` 1.7 and
    `restore_database.sh` 3.9 (comments);
  - dns_filtering 1.4.0 (the task, the data class and the setting are gone);
  - migration 202 (`blocklist_domains_dropped.php`);
  - server_manager 1.26.5 (`NodeHealthProbe` 1.3, overview 1.26);
  - docs and the three other specs.
  - Tests: installer_contract 774/774, host_housekeeping 109/109, node_health_probe
    41/41. `db --changed` shows 508/510; the two failures are dev's own
    `bld_blocklist_domains` table and setting row, which migration 202 removes.
- **Next:** commit, release, and dev's `update_database` (drops dev's 549 MB table; needs
  the owner's yes). Every site then takes the release: scrolldaddy's migration drops its
  593 MB table.
- **WP6** is live and needs the owner present.
- **WP7's code, and WP4's site-side removals, stay out of the tree until WP6 is done on
  both DNS servers.** A release carrying them early would strip the resolvers' database
  access at scrolldaddy's next converge.
**Date:** 2026-09-25.
**Related:** `specs/fleet_ubuntu_2604_postgres_upgrade.md`. Its B8 found the resolvers'
database access; the owner asked for "Postgres completely shut off to any remote access"
(2026-09-24) and, on 2026-09-25, for no one-off exception. This spec replaces that
spec's `publish` line for scrolldaddy (its A2). scrolldaddy moves last there, after
WP7 here.
**Reviewed:** 2026-09-25 by public-html-9a (§ Review).

## What this does for the owner

**Today:** the two ScrollDaddy DNS servers sign in to scrolldaddy's database across the
network every minute. It is the only Joinery database anything outside its own machine
can reach. Five hand-made pieces on three machines hold it together, and a routine
rebuild of scrolldaddy's container would quietly undo one of them.

**After this:**
- The DNS servers fetch what they need from `https://scrolldaddy.app`, like any other
  API client. Their key can call one read-only action and nothing else.
- Every Joinery database answers only its own machine, with no exceptions. The
  exception mechanism is deleted, so there is nothing to remember.

**Also:**
- scrolldaddy's database drops from 634 MB to about 40 MB, and its nightly backup shrinks
  with it: 593 MB of it is the blocklist, which only the DNS servers read.
- A DNS server restarted while the site is down keeps filtering. Today it answers
  unfiltered (B3).
- Two blocklist bugs go away by design (B1, B2).

## Today (checked 2026-09-25)

The resolvers' database access, piece by piece:

| # | Piece | Where | Made by |
|---|---|---|---|
| 1 | Login role `scrolldaddy_reader`, SELECT on 143 tables | scrolldaddy's database | hand |
| 2 | pg_hba lines for `192.168.206.21` (primary) and `97.107.131.227` (secondary) | declared in scrolldaddy's `config/postgres_access.conf`, applied by `host_housekeeping.sh` | hand-written file |
| 3 | Database port on `192.168.206.198:9087` | the container's `docker run` arguments | hand (no `publish` line yet) |
| 4 | Firewall: ACCEPT 9080–9099 from `97.107.131.227` and from all of `192.168.128.0/17`, DROP 9080–9087, plus a tagged exemption for `192.168.206.198:9087` | docker-prod `DOCKER-USER` | hand, and 2026-09-24 |
| 5 | `SCD_DB_HOST=192.168.206.198`, `SCD_DB_PORT=9087`, `SCD_DB_NAME=scrolldaddy`, `SCD_DB_USER=scrolldaddy_reader`, password | `/etc/scrolldaddy/scrolldaddy.env` on both DNS servers | hand |

Both DNS servers run `scrolldaddy-dns 1.8.0` with `"fail_mode": "open"`.

**What the resolver reads** (`/home/user1/scrolldaddy-dns/internal/db/db.go`):
- **Every 60 s** (`LightReload`, `cache.go:134`):
  - active devices: `sdd_device_id`, `sdd_resolver_uid`, `sdd_timezone`,
    `sdd_log_queries`, where `sdd_delete_time IS NULL AND sdd_is_active AND` the UID is
    non-empty;
  - active scheduled blocks: `sdb_*`, where `sdb_is_active AND sdb_delete_time IS NULL`;
  - their filter, service and domain rules, from `sbf_`, `sbs_` and `sbr_`
    (`sbr_is_active = 1`).
- **Every hour** (`FullReload`, `cache.go:305`): `dns_filtering_blocklist_version` from
  `stg_settings`. When it has changed, all of `bld_blocklist_domains`.
- **On demand:** the site's `POST /reload` after its blocklist download
  (`DownloadBlocklists.php:308`–`:313`).

**What stays as it is:** the site's calls *to* the resolvers, with the existing
`SCD_API_KEY`:
- `ScrollDaddyApiClient`: `/device/{uid}/seen` (`devices_logic.php`), and `/reload`
  (called only by `DownloadBlocklists`, so it has no site caller after WP7);
- direct curl calls: the query log and the domain test.
- `/cache/flush` has no caller on the site. `/reload` and `/cache/flush` remain as
  operator tools. Edits reach the resolvers through the 60 s poll, as they do today.

**Scale:** scrolldaddy has 1 active device and 1 block. The blocklist is 4,301,108 rows
in 16 categories, 593 MB of a 634 MB database. dev carries 3,959,305 rows (549 MB).

**Found while checking (fixed by this spec):**
- **B1 — scrolldaddy's blocklist has not been refreshed since 2026-04-22.**
  `DownloadBlocklists` is inactive there (`sct_is_active = f`). Its last run succeeded,
  and nothing in its row says who turned it off.
- **B2 — a failed source empties its category, silently.** The task truncates the whole
  table before downloading (`DownloadBlocklists.php` `run()`). On 04-22 the cryptominers
  source failed, so `cryptominers` has 0 rows. A device set to block cryptominers blocks
  nothing, and nothing says so.
- **B3 — a restarted resolver answers unfiltered until it can reach the database.** In
  `fail_mode` open it starts in passthrough and stays there until the first load
  (`cmd/dns/main.go:83`–`:90`). Its data lives only in memory.
- **B4 — query-log merging between the two resolvers does not work.**
  - The primary's `SCD_PEER_URL=http://192.168.151.4:8053`: nothing answers from the
    primary.
  - The secondary's `SCD_PEER_URL=http://192.168.206.198:8053`: that is docker-prod,
    and nothing answers there either.
  - The secondary has no private address; it reaches `192.168.206.198` through its
    public gateway.
- **B5 — six of the 19 blocklist URLs are dead** (found by public-html-9a, 2026-09-25).
  - All five hagezi URLs (`main/domains/{light,multi,pro,tif,doh}.txt`) return 404:
    hagezi moved them to `main/wildcard/{name}-onlydomains.txt`. These are the same
    lists, and each entry covers the domain and its subdomains, which the resolver's
    `IsDomainInSet` already does.
  - cryptominers' CoinBlockerLists now redirects to a GitLab sign-in (403).
  - Re-enabling `DownloadBlocklists` on today's code would truncate the table and then
    fail these six, emptying six categories. The source list in
    `blocklist_sources.json` points at the moved paths, and at a new cryptominers source
    (D2).

- **B6 — deleting a user who owns DNS devices leaves orphan device backups** (found by
  public-html-9a's WP2 fixtures, 2026-09-25; core, not dns-specific).
  - `SystemBase::permanent_delete()` walks a table's deletion rules in rule-id order.
    For `usr_users`, the flat `cascade` on `sbk_device_backups` (590373) runs before the
    model-level `permanent_delete` of `sdd_devices` (590374).
  - `SdDevice::permanent_delete()` then writes a new backup row for the same user
    (`devices_class.php:146`–`:151`), and the user row goes, orphaning it.
  - **Fix:** rules run in action order: `prevent` first, then `permanent_delete`, then
    the flat actions. A model's own delete may write under the same parent, and the flat
    pass then removes it. The dry run uses the same order.
  - **Audit** (dev's rule table, 2026-09-25): 10 source tables mix the two kinds. The
    only case where a model deleted first has a `prevent` on a table the parent also
    touches flatly is `usr_users` → `bty_booking_types` with `prevent` on
    `bkn_bookings.bkn_bty_booking_type_id`. The parent's flat actions there
    (`set_value` on the user columns) do not remove those rows, and that model delete
    already runs first today, so nothing changes.

- **B7 — issuing a DNS server's key failed on every production site** (found at the WP6
  start, 2026-09-25; fixed in dns_filtering 1.3.2).
  - The service account WP2 created had its address on the reserved `.invalid` domain.
  - The email field's accepts-mail check (`email_validation_mx_check`, on by default)
    refuses that. It is off on dev, so WP2's suite passed there.
  - On scrolldaddy, `issueKey` threw and its transaction rolled back, so nothing was
    written.
  - **Fix (owner, 2026-09-25):** the service account is gone, and a key belongs to the
    admin who issues it. The account existed so a key would survive its owner's
    deletion, and the core `User` guard already refuses that.
- **B8 — the DNS server installer cannot rebuild a production server** (found
  2026-09-25; not fixed).
  - Both servers answer as `dns.scrolldaddy.app`, so each gets its certificate by a DNS
    challenge through Cloudflare. That takes a custom Caddy build with the Cloudflare
    module, plus a `CF_API_TOKEN`, both set up by hand.
  - `install_caddy()` installs stock Caddy, and `configure_caddy()` writes no DNS
    challenge. On a fresh box, issuance would depend on which of the two servers the
    ACME check reached.
  - A rebuilt box also has no way in for us (no SSH keys on new installs), and would
    need its `SCD_API_KEY`, token and firewall rules carried across.
  - So the servers are upgraded in place. A hands-off rebuild needs:
    - the installer to build Caddy with the module and take the token;
    - a keyless first-boot path, as the relay has.

## Design

- **The site publishes one read-only action**, `dns_filtering/resolver_snapshot`,
  returning exactly what the resolver reads today.
- **Only a key scoped to that action can call it.** The scoped machine key is new and
  general (WP1). An admin's ordinary key copied onto a DNS box would carry the admin's
  whole reach, so the action refuses unscoped keys.
- **Each resolver polls every 60 s**, sending the version it holds. An unchanged
  snapshot costs one small response.
- **The path runs through Cloudflare.** scrolldaddy.app has Cloudflare A and AAAA
  records. The key and every user's custom rules pass through Cloudflare's TLS
  termination. Today the secondary reads the database across the public internet with
  `sslmode=disable` (`db.go:64`), so this is still an improvement. A bot or WAF rule
  could answer with a challenge page, so the client treats any answer that is not the
  API's JSON envelope as a failure.
- **The resolver dials IPv4 only** for the snapshot. Both DNS servers have global IPv6,
  and both reach scrolldaddy.app over it by default (L2). The key's address restriction
  is an exact match against one address per server (`ApiAuth.php:163`), so one family
  keeps it to one address, the one already in the plugin's settings.
- **Each site's last good snapshot is kept on disk and loaded at startup** (B3). One
  failing site no longer stalls the others: today a failed connection to any database
  retries all of them.
- **The DNS servers download the blocklists themselves**, from the source list the site
  gives them (D1). The blocklist leaves the database.

## For the executor — read this first

- **Never commit, never `git add`.** The owner runs git.
- **Two repositories.** The Joinery tree, and `/home/user1/scrolldaddy-dns` (Go). The
  latter has uncommitted changes from another session in `internal/machine/facts.go`
  and `facts_test.go`; leave them alone.
- **Live boxes only in WP6 and WP7, with the owner present.** A write to scrolldaddy's
  database needs the owner's explicit yes. The DNS servers are disposable and never
  agented. They are deployed over SSH with their installer (`make release`, then the
  installer on each box).
- **Secrets never reach the transcript.** Key secrets go from the settings page into the
  env file without being echoed.
- **Installer files and the converger.** The dev box's host converger runs
  `install_tools/` scripts as root. The owner stops it before any edit there (WP7).
- **Docs describe the current state only. Bump the version header of every file touched.**
- **Checks:** `php -l` every PHP file, `validate_php_file.php` on class and function files,
  `bash -n` every shell script, and `go test ./...` in the resolver.
- **Tests:** tests use the shared harness with the `@joinery-test` header. Run
  `php tests/run.php --changed`, then `db --changed`.

## WP1 — Scoped machine keys (core)

A machine key that can call only the actions it names.
- **The column:** `apk_api_keys` gains `apk_scope` (text; empty means unscoped, which is
  every key today). It holds action names, e.g. `dns_filtering/resolver_snapshot`.
  - Schema comes from `data/api_keys_class.php`, via `update_database`.
  - Only machine keys may be scoped.
- **The check:** `ApiAuth::authorize()` is the one decision point, so the check lives
  there. It gains the endpoint's name.
  - A scoped key is refused 403 on every surface its scope does not name: CRUD, forms,
    management, and every other action. The message names the scope.
  - Check every route family in `api/apiv1.php`. These do not reach `authorize()`
    after authentication, so each refuses a scoped key itself:
    - `auth/*` (`ApiAuthEndpoint`);
    - `app/*` (`ApiAppEndpoint`);
    - `drive_upload` (`DriveUploadTransport::dispatch`, `apiv1.php:563`);
    - the `GET /api/v1/actions` discovery listing (`apiv1.php:831`).
  - Sessionless actions (`requires_session: false`) are dispatched before
    authentication (`ApiLogicEndpoint::dispatchActionPreAuth`) and never see a key.
    Anyone may call them, so there is nothing to refuse; build nothing there.
- **The descriptor flag:** `requires_scoped_key` in an action's `auth` block admits only
  a machine key scoped to that action. It refuses unscoped keys and browser sessions.
- **Admin > API Keys** shows a key's scope (read-only). Scoped keys are minted by the
  feature that owns the action (WP2), not typed.
- **Docs:** `docs/api.md` § Key Properties and § Declaring endpoint authorization.
- **Tests:** `tests/unit/api_scoped_key_test.php` (offline) and
  `tests/functional/api/scoped_keys_test.php` (over HTTP, beside the other API suites):
  - a scoped key calls its action;
  - it is refused on another action, on a CRUD read and on a management path;
  - an unscoped key and a browser session are refused on a `requires_scoped_key` action.

## WP2 — The snapshot action (dns_filtering plugin)

- **The file:** `plugins/dns_filtering/logic/resolver_snapshot_logic.php`, with a
  descriptor:
  - `auth`: `capability: read`, `requires_machine_key`, `requires_scoped_key`;
  - `mutates: false`;
  - input: `if_version` (string, optional).
- **Output:**
  - `version`: the sha256 of the canonical JSON of `devices`, `blocks` and
    `blocklist_sources` only. `generated_at` is outside the hash, or no answer would
    ever match `if_version`;
  - `generated_at`;
  - `devices`: `id`, `uid`, `timezone`, `log_queries`;
  - `blocks`: `id`, `device_id`, `name`, `always_on`, `start`, `end`, `days`,
    `timezone`, and the `filters`, `services` and `domains` lists (`key`, `action`);
  - `blocklist_sources` (WP4).
  - Row selection matches `db.go` exactly (§ Today).
  - Device IDs stay local to the site; the resolver merges by UID, as it does now.
  - When `if_version` equals `version`, the answer is `{version, unchanged: true}`.
- **Queries:** one per table, through `MultiSdDevice`, `MultiSdScheduledBlock` and the
  three rule collections where their filters fit. Never one query per row.
- **Keys, on the plugin's settings page:** a "DNS server access" panel, one row per
  configured server (primary; secondary when set).
  - **Issue key** mints a machine key: read-only, scoped to
    `dns_filtering/resolver_snapshot`, and IP-restricted to that server's
    `dns_filtering_dns_server_ip` or `dns_filtering_dns_secondary_server_ip` (IPv4). It
    shows the secret once.
  - **The key belongs to the admin who issues it.** Every key carries a user
    (`apk_usr_user_id`); `authenticate()` refuses a key whose user is deleted
    (`ApiAuth.php:158`), and the action runs as that user.
    - Its scope confines it to an action that needs no permission level, so demoting
      the owner or changing their password does not affect it.
    - Deleting the owner would cut the servers off, so the core guard refuses it:
      `User::soft_delete()` and `permanent_delete()` refuse while the user owns any live
      scoped machine key. The key is revoked, or re-issued by another admin, first.
  - **Address drift:** the restriction is a copy of the setting, made when the key is
    minted. That setting is also the address the setup instructions hand out. When the
    two differ, the panel says so and asks for a re-issue.
  - **Revoke** ends it.
  - The key IDs are kept in two managed settings declared in `plugin.json`.
  - A POST action, not a link.
- **The IP restriction** depends on the resolver's address reaching the site through
  docker-prod's front proxy (`mod_remoteip`, `SessionControl::get_client_ip(true)`).
  WP6 proves it.
- **Tests** (`plugins/dns_filtering/tests/`):
  - the shape;
  - deleted and inactive devices, blocks and rules are left out;
  - the unchanged path;
  - refusal of an unscoped key;
  - a key whose owner is deleted is refused; the issuing admin cannot be deleted while
    the key is live, and can be once another admin has re-issued it;
  - two answers with the same data carry the same `version`.

## WP3 — The resolver reads HTTPS (scrolldaddy-dns 2.0.0)

- **Config:** `SCD_JOINERY_SITES`, comma-separated entries of
  `https://base|public_key|secret_key`, the line the key panel prints. The secret never
  appears in a log or in `/health`.
  The env file stays `root:scrolldaddy` 0640, as `build_installer.sh:291`–`:292` sets
  it.
  - `SCD_DB_*` and `SCD_JOINERY_DB_URLS` are gone.
  - `internal/db` goes (`lib/pq` drops out of `go.mod`), and `ValidateSchema` with it.
- **The client:** `POST {base}/api/v1/action/dns_filtering/resolver_snapshot`, with the
  `public_key`/`secret_key` headers and `{if_version}`.
  - TLS is verified, with a 10 s timeout.
  - It dials `tcp4` only (§ Design).
  - An answer that is not the API's JSON envelope counts as a failure, whatever its
    status.
- **Per site, last good wins:** a failing site keeps its last data and logs; the others
  update.
- **Disk cache:** `/var/lib/scrolldaddy/cache/`, written atomically after every changed
  fetch.
  - **The unit must allow the write.** `dist/scrolldaddy-dns.service` has
    `ProtectSystem=strict`, and its `ReadWritePaths` names only the log and config
    directories. So the unit gains `StateDirectory=scrolldaddy`, which systemd creates
    and hands to the service user. Without it every cache write fails and B3 stays
    broken.
  - Startup loads the cache before the first fetch, so filtering is on from the first
    query. `fail_mode` applies only when no usable cache exists.
  - The cache is loaded before the server listens: about 3 s on dev for 3.8 million
    domains, likely longer on a 1 GB server. During that time it answers nothing
    rather than answering unfiltered, and clients use the other server.
  - A cache file that does not decode, or a list whose count does not match its
    recorded count, is logged and treated as absent.
- **Health:**
  - `/health` answers **200 `ok`** when every site answered within three intervals.
  - It answers **200 `stale`** while it filters from cached data because a site has
    not answered.
  - It answers **503 `degraded`** only in passthrough, when nothing is loaded.
  - The management node marks a node down on any status of 400 or above
    (`NodeHealthProbe.php:205`). So a site outage must not read as the DNS server being
    down while it filters fine from cache.
  - It also reports `source_ok`, `last_reload`, and a per-site `sources` list (`host`,
    `ok`, `last_ok`). `db_connected` is gone.
- **`POST /reload`** triggers an immediate fetch. It is an operator tool: after WP7
  nothing on the site calls it.
- **Tests:**
  - snapshot decode;
  - the unchanged path;
  - one failing site leaves the other's data;
  - the disk-cache round trip;
  - startup with every site unreachable filters from the cache;
  - a corrupt cache file is treated as absent;
  - a non-JSON answer (a challenge page) is a failure.
- **Docs and installer:**
  - `README.md`, and `docs/OPS_GUIDE.md` (`:73`–`:84`, `:244`–`:246`, and the copy
    each installer lays down);
  - `dist/scrolldaddy.env.example` (its `SCD_DB_*` block);
  - `build_installer.sh`: `SCD_JOINERY_SITES` replaces the `SCD_DB_*` block. The
    database prompts (`:416`–`:420`) and the `nc` database check (`:460`) become a
    snapshot fetch check.

## WP4 — The DNS servers download the blocklists (D1)

- **The source list becomes data:** it moves from `DownloadBlocklists::SOURCES` to
  `plugins/dns_filtering/blocklist_sources.json` (category → URLs, and the skip list).
  The snapshot carries it as `blocklist_sources`.
- **Resolver side:**
  - It refreshes every `SCD_BLOCKLIST_REFRESH_HOURS` (24).
  - Per category, it downloads each URL (60 s timeout, 5 redirects, TLS verified). It
    parses exactly as `parse_line()` does, ported with its cases as Go tests, and
    dedupes.
  - **Sets are kept per URL, not per category.** A category is the list of its URLs'
    sets, and a lookup checks each one. tif (2.3 million domains) feeds two categories
    and is held once, and the disk cache holds one file per URL.
  - A failed URL, or one under 10 domains, keeps that URL's previous set (B2). A
    category lacks only what a never-successful URL would add.
  - **At startup,** it leaves passthrough once every site's snapshot is loaded and
    every URL is either loaded (from cache or download) or has been tried once in this
    start.
    - A URL that has never succeeded stays empty. It is named in `/health`
      `blocklists_missing`, and the status is `stale` (200).
    - Waiting for every list would keep a box unfiltered for good behind one dead
      source.
  - **The installer primes the cache** before it touches the running service.
    - The new binary goes in beside the old one, and `--prime` runs as the service user
      with the env file. The installer creates `/var/lib/scrolldaddy` for it, since
      systemd makes the `StateDirectory` only when the unit starts.
    - `--prime` fetches every snapshot and list, then exits. It exits 1 when a site
      fails, or a URL fails with no previous set on disk, and names it.
    - `--prime --allow-missing` proceeds knowingly.
    - The binary is swapped in only on success, so a failed prime leaves the old version
      serving.
  - **One URL at a time:** each URL is downloaded, parsed and swapped in alone, so the
    whole set is never built twice at once. Today's `FullReload` holds two copies
    (instinct: the memory peak on a 1 GB server).
- **Site side, in the WP7 release** (1.8.0 resolvers read the table until the cutover):
  - removed: the `DownloadBlocklists` task and its JSON, `blocklist_domains_class.php`,
    the `dns_filtering_blocklist_version` setting and `trigger_reload()`;
  - a migration drops `bld_blocklist_domains`. It follows `migrations/retired_tables_dropped.php`
    and guards the table as a plugin table: an inactive plugin keeps a stale one.
  - B1 ends with the task. The task's row retires itself once its code file is gone
    (`docs/scheduled_tasks.md` § Retired tasks).

## WP5 — The management node reads the new health (server_manager)

- `NodeHealthProbe::SERVICE_KEYS` (`NodeHealthProbe.php:45`) gains `source_ok`.
- The node overview (`node_detail_tabs/overview.php:644`) shows "site reachable" or "site
  unreachable" from `source_ok`. It shows `db_connected` only while a 1.8.0 resolver
  still reports it; WP7 removes that.

## WP6 — Cutover (owner present)

1. **Release:** a release carrying WP1, WP2 and WP5. scrolldaddy, dev and getjoinery
   take it.
2. **Keys:** issue both keys on scrolldaddy's settings page.
3. **Secondary first:**
   - Keep `scrolldaddy.env` as `scrolldaddy.env.pre-https`.
   - **Before:** the release artifact is rebuilt after the owner commits the resolver
     repo, since `make release` bundles the working tree. Check `free -m`: `--prime`
     peaked at 374 MB on dev, and it runs while 1.8.0 still serves.
   - Add `SCD_JOINERY_SITES` to the env and **leave the `SCD_DB_*` lines until WP7**:
     2.0.0 ignores them, and the installer's automatic rollback starts 1.8.0 with the
     same file. Set `SCD_PEER_URL` to an
     address the peer answers on (B4), which may mean opening 8053 between the two
     servers.
   - Install 2.0.0.
   - Gates:
     - `/health` `source_ok`;
     - `/stats` devices equal scrolldaddy's active devices;
     - for the test device, a domain in a blocked category answers NXDOMAIN;
     - a custom rule added on the site takes effect within a minute;
     - the key refuses a request from dev (IP restriction);
     - every answer is the API's JSON, not a Cloudflare page;
     - `/var/lib/scrolldaddy/cache/` holds the snapshot and every list after the first
       fetch;
     - with `192.0.2.1 scrolldaddy.app` added to `/etc/hosts` and the service
       restarted, it still filters from its cache: a blocked domain answers NXDOMAIN,
       and `/health` says `stale` with a 200. Then remove the line and restart.
       - The site's address must stay the same: the cache is keyed by it, so pointing
         `SCD_JOINERY_SITES` elsewhere reads as a different site with no cache.
       - An OUTPUT rule would have to block every Cloudflare range, v4 and v6.
4. **Primary:** the same, once the secondary has served 24 hours.
5. **Rollback, per server:** reinstall 1.8.0 with the **current** env, which still
   carries the `SCD_DB_*` lines. This works until WP7, because the database access is
   still in place. Do not restore `.pre-https`: since the B4 fix the servers share one
   API key, and that backup holds the secondary's old one.
6. **Blocklist:** one daily refresh is watched end to end on each server: every category
   non-empty, cryptominers included (B2).

## WP7 — Remove the exception (release after WP6 holds for a week)

**This release must not reach scrolldaddy before WP6 is done on both servers.** Its
housekeeping strips the reader lines at the next converge. Housekeeping runs inside
each container from that site's own tree, so the other sites may take it earlier.
**scrolldaddy moved to PostgreSQL 18 before this release** (2026-09-26,
`specs/fleet_ubuntu_2604_postgres_upgrade.md` Stage 3). Its `postgres_access.conf` gained
`publish 192.168.206.198`, which declares the binding the direct read uses, so the rebuild
kept it; `install.sh` now carries the tagged exemption for it. Moving the file aside here
removes that line too. The container keeps the binding until it is next rebuilt; until
then pg_hba admits no network login, and the rebuild drops the binding.

**Live (owner present; database writes confirmed):**
- **scrolldaddy's database:** `DROP OWNED BY scrolldaddy_reader; DROP ROLE
  scrolldaddy_reader;`.
- **scrolldaddy's config:** move its `config/postgres_access.conf` aside. The next
  container start or converge drops the lines.
- **docker-prod's firewall:** remove the hand-made rules and the tagged exemption from
  `DOCKER-USER`. Confirm `install.sh docker`'s standard DROP of 9080–9099 is in place,
  then save; keep the originals.
  - **First, a day of evidence.** The ACCEPT from `192.168.128.0/17` opened every
    site's database port to the whole private network, not just scrolldaddy's. Add a
    LOG rule, or watch the ACCEPT's counters and conntrack, to confirm nothing else
    uses 9080–9099 privately.

**Code:**
- **`install.sh`:** `resolve_database_publish_address`,
  `allow_declared_database_publish` and `DB_PUBLISH` go (the port is always
  `127.0.0.1`), and so does the exemption counting in the `DOCKER-USER` block.
- **`host_housekeeping.sh`** section 5: the declared lines go (`pg_access_line_ok`, the
  `PG_ACCESS_FILE` loop). A container admits loopback and its gateway only. A leftover
  `postgres_access.conf` gets one warning saying it is not read.
- **`rebase_site_container.sh`:**
  - Any network pg_hba line refuses, as none can be declared.
  - A database port published on a non-loopback address is reported as dropped, not
    refused: with no network pg_hba line, nothing can use it.
  - Any other hand-made binding still refuses.
- **Stays:** `restore_database.sh` 3.8's role handling. It is general: the mailbox
  plugin's role needs it.
- **Tests:** the B8 section of `installer_contract_test` (the publish mechanism is
  absent), `host_housekeeping_gate.sh`'s declared-line checks (a leftover file is
  ignored), `install_container_gate.sh`'s publish checks.
- **Docs:**
  - `docs/installation.md` § PostgreSQL access;
  - `INSTALL_README.md`;
  - the dns_filtering overview § Resolver Configuration (`:143`–`:157`) and § DNS
    Resolver Flow;
  - the fleet spec's Stage 3 step 9;
  - `docs/backups.md:902`, which uses `scrolldaddy_reader` as its example role;
  - the comments naming it in `restore_database.sh` (`:5`, `:358`, `:364`); the code
    stays.
- **Other specs:**
  - `specs/sister_brand_deployment.md:117`–`:119` becomes "issue a key on
    NetworkSentry's settings page, add an `SCD_JOINERY_SITES` entry";
  - `specs/scrolldaddy_combined_server_install.md` (roadmap) is built on
    `scrolldaddy_reader` and `SCD_DB_*` (`:158`–`:172`, `:256`, `:429`);
  - `specs/node_reverse_dependency_check.md:59` cites `SCD_DB_HOST`.
- **Plugin and plane:** WP4's site-side removals and the table migration. WP5's
  `db_connected` reading goes.

## Decisions

**D1 — Who downloads the blocklists? Decided 2026-09-25 (owner): the DNS servers.**
- **Why:** they are the lists' only readers. The site stops its daily download and its
  4-million-row rewrite, and nothing large passes between the site and the DNS servers.
- **Accepted:** the parsing moves to Go, and the two servers refresh independently, so
  they can differ for a few hours.

**D2 — cryptominers: its source is gone. Decided 2026-09-25 (owner): replace it.**
- The source is the NoCoin list
  (`raw.githubusercontent.com/hoshsadiq/adblock-nocoin-list/master/hosts.txt`): hosts
  format, 312 domains, maintained (last pushed 2026-09-12).
- dns_filtering 1.3.1. The DNS servers pick it up from the snapshot once scrolldaddy
  runs that release.

## What goes away

- **Across machines:** the five hand-made pieces in § Today: a role, access lines, a
  published port, firewall rules, and database credentials on two servers. None is
  replaced; each DNS server holds one scoped key instead.
- **Coupling:** the resolver stops depending on the site's table and column names
  (`ValidateSchema` and its optional-column probing). Its contract is one JSON action
  the plugin owns and tests.
- **Code:**
  - the resolver's database layer (`internal/db`, 443 lines) and `lib/pq`;
  - the blocklist task and its data class (354 lines);
  - the remote-database exception in `install.sh`, `host_housekeeping.sh` and
    `rebase_site_container.sh`, with its tests and docs.
- **Data:** 593 MB from scrolldaddy's database and every nightly backup of it; 549 MB
  from dev's.
- **Rules:** "a Joinery database answers only its own machine" has no exception left to
  document, check, or carry through a restore or a move.
- **Added in their place:**
  - scoped machine keys (one column, one check, usable by any future machine client);
  - the snapshot action and its key panel;
  - in the resolver: an HTTPS client, a disk cache and the blocklist downloader.

## Review (2026-09-25, public-html-9a)

Thirteen findings, all taken; each is answered where it applies above. Grades are the
reviewer's: traced (ran it), read (code), instinct (judgement).
- **R1** [read]: the version hash included `generated_at`, so it could never match →
  WP2.
- **R2** [traced]: Cloudflare and IPv6 → § Design, WP3, WP6.
  - IPv6 is the default path from both DNS servers (L2), so the client dials IPv4.
  - Blocking the site for the restart gate means pointing the resolver at an unroutable
    address.
  - A challenge page is a failure, and Cloudflare's TLS termination is stated.
- **R3** [read]: the unit's sandbox would block the disk cache → `StateDirectory`, and a
  WP6 gate.
- **R4** [read]: `/health` status for a site outage → 200 `stale`; 503 only in
  passthrough.
- **R5** [read]: empty categories on the first start → the cache is primed before
  service; one category at a time.
- **R6** [read]: route families that skip `authorize()` → WP1 lists all four;
  sessionless actions need nothing.
- **R7** [read]: the key's owner → first a service account; since B7, the issuing
  admin, with the core delete guard covering the risk R7 named.
- **R8** [read]: nothing calls `/reload` after WP7 → operator tool; § Today corrected.
- **R9** [read]: the env file is 0640, not 0600 → WP3.
- **R10** [read]: dependents WP7 missed → WP7 § Docs and § Other specs, and WP3's
  resolver files.
- **R11** [read]: the address restriction goes stale if a server is replaced → the panel
  shows the mismatch.
- **R12** [read]: a corrupt cache → treated as absent.
- **R13** [instinct]: the `/17` ACCEPT opened every site's port → a day of evidence
  before removal.
- **Q (a simpler existing mechanism)** [read]: a read-only key of a dedicated user needs
  no core change, but 24 actions declare `capability: read`, `user_search` among them.
  Reusing the shared `SCD_API_KEY` cannot be revoked per server. WP1 stands.

**Live checks, run 2026-09-25:**
- **L1:** from the primary, one unauthenticated call to `https://scrolldaddy.app/api/v1/actions`
  over each family. scrolldaddy logged `45.56.103.84` and
  `2600:3c03::2000:51ff:fe2e:3606`: the real client, not the proxy or the bridge. The
  restriction works through Cloudflare and the front proxy. Both requests were 400
  failed-auth rows.
- **L2:** both DNS servers have global IPv6, and curl reached scrolldaddy.app over
  `2606:4700:20::681a:53a`.
- **L3:** scrolldaddy holds no address-restricted key today, so there was nothing to
  test with; L1 covers the question.

## Evidence (2026-09-25)

- Resolver env keys (values not printed) and `scrolldaddy-dns --version` → 1.8.0 on both
  servers; `dns.json` `"fail_mode": "open"` on both.
- scrolldaddy's settings (read inside its container):
  - internal URLs `http://45.56.103.84:8053` and `http://97.107.131.227:8053`;
  - `dns_filtering_blocklist_version` `2026-04-22 08:00:59`;
  - `DownloadBlocklists`: `sct_is_active = f`, last run 2026-04-22 success, with
    "Download failed for cryptominers".
- `bld_blocklist_domains` per category on scrolldaddy: 16 categories, no
  `cryptominers`. `pg_total_relation_size` 593 MB; `pg_database_size` 634 MB.
- From the primary: no answer from `192.168.151.4:8053` or `97.107.131.227:8053`.
  From the secondary: no answer from `192.168.206.198:8053`, and
  `ip route get 192.168.206.198` → via `97.107.131.1`, src `97.107.131.227`.
- `grep -rn bld_blocklist_domains` in the plugin: only the task and its data class. No
  site page reads the table.
