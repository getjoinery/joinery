# Fleet Move to Ubuntu 26.04 / PostgreSQL 18

**Status:** Stage 1 is released in 0.8.424 (published 2026-09-24; commit 71277d44): WP1–WP5,
WP3b/B7, B1, B9, B11, B12, Postgres local-only, and WP7 (the move script). B8's remainder,
B10, B13–B17 and WP6 (base 2.0) are released in 0.8.426 (commits e98df32c, 4de2fdc3);
every node runs 0.8.426 (checked 2026-09-25).
R1 passed on the owner's test box, all gates (last two 2026-09-25). R2 waits on the owner.
B19 and B20 (found finishing R1) are fixed in 0.8.430 (`specs/fleet_move_bug_fixes_2026_09_25.md`).
**Stage 3 is done (2026-09-25/26, from 0.8.430): all eight Docker sites are on
PostgreSQL 18** and pass every gate (§ Progress). scrolldaddy moved before
`specs/dns_resolvers_read_over_https.md` WP7, with its database publish declared.
`finish` (dropping the rollback copies) runs from 2026-10-03. Stage 4 waits on Ubuntu
(D3, D4). B23–B25, found during the moves, are fixed; B23 was also healed
live on docker-prod.
Two owner decisions open (D3, D4; D1 and D2 are in `specs/backup_database_incrementals.md`).
**Date:** 2026-09-24 (rewritten from the 2026-08-01 draft after a fleet investigation;
the code-side cutover items of `php_85_pg18_stack_cutover.md` are folded in here).
**Related:** `specs/backup_database_incrementals.md` — the main payoff. It needs
PostgreSQL 17+ on a node before that node's nightly database backup can shrink.
`specs/implemented/php_85_pg18_code_prep.md` — the code already runs on PHP 8.5 /
PostgreSQL 18 (proven on a real 26.04 box, 2026-08-06).

## What this does for the owner

Moves every Joinery site onto PostgreSQL 18 and PHP 8.5. The reason now is backups:
PostgreSQL 18 can back up only what changed in the database, so jeremytunnell's nightly
1.65 GB database upload can drop to a fraction of that. It also puts the fleet on the
current Ubuntu LTS.

**No existing site runs PostgreSQL 18 yet.** Every node reports Ubuntu 24.04 /
PostgreSQL 16. New standalone installs land on 26.04 / PostgreSQL 18, and new Docker
sites are born on it from 0.8.426 (WP6). Before that release a Docker site was built from
`joinery-base:1.2` (Ubuntu 24.04): test380s — a 26.04 server — built its site on it (job
23394, output line 303), so it runs PostgreSQL 16.

**Ubuntu has not opened the 24.04 → 26.04 upgrade.** `meta-release-lts` lists resolute
26.04.1 with `Supported: 0` (checked 2026-09-24). Press reports put the hold on
regressions in 26.04's Rust coreutils. That affects only the two standalone boxes. The
eight Docker sites get PostgreSQL from their image, not from the server, so they can move
without it.

## Where each box stands

| Box | Runs | What moves it | Blocked on |
|---|---|---|---|
| 8 sites on docker-prod (galactictribune, getjoinery, getjoinery-developers, getjoinery-orgs, joinerydemo, mapsofwisdom, phillyzouk, scrolldaddy) | a container per site, each with its own PostgreSQL | rebuild the container on a 26.04 image, carrying its database across (Stage 3) | nothing: 0.8.426 carries the image and the move script |
| new Docker sites (customer servers) | same | born on the 26.04 image (Stage 2, released in 0.8.426) | — |
| jeremytunnell-vps | standalone | in-place OS upgrade, by hand (Stage 4) | Ubuntu opening the upgrade (D3) |
| dev (management node) | standalone | in-place OS upgrade, by hand (Stage 4) | same |
| docker-prod server, joinery-relay-1, both scrolldaddy-dns | no site database | **stay on 24.04** (supported to 2029) | — |

docker-prod's own OS does not matter to its sites: a 26.04 container runs on a 24.04
server. Upgrading it would take all eight sites down at once for nothing this spec needs.
The relay and DNS boxes run no Joinery database and are rebuilt, never upgraded.

## For the executor — read this first

- **Never commit, never `git add`.** The owner runs git.
- **Never touch a live box from the dev tree.** Stages 3 and 4 run on the boxes
  themselves, by the owner or with the owner present. The dev-tree work is Stages 1, 2 and 5.
- **No schema change, no settings row.**
- **Docs describe the current state only.**
- **Bump the version header** of every file touched.
- `php -l` every PHP file; `validate_php_file.php` on class/function files only; `bash -n`
  every shell script.
- **Tests use the shared harness**, with the `@joinery-test` header. Shell gates are
  `*_gate.sh`. Run `php tests/run.php --changed`, then `db --changed` before handing back.
- **Installer files and the converger.** The dev box's host converger runs installer
  scripts as root. Stop its timer before editing anything in `install_tools/` on dev,
  and restart it after.
- **Bugs found on the way** are numbered from B7 (B1–B6 are below and in the backups spec).

## Stage 1 — Fixes that stand alone (dev tree, now)

Each ships in an ordinary release and is useful even if the move never happens.

**WP1 — B5: a container rebuild must never start PostgreSQL on the wrong data.**
A site's `_postgres` volume holds `/var/lib/postgresql/16/main`. Once the image carries
PostgreSQL 18, a routine recreate (`install.sh site X -y`) mounts that volume under an
image whose config points at `18/main`. PostgreSQL then does not start and the site is
down. The data is intact, but nothing says why. (Inferred from the start command; not
reproduced.)
- The start command (`Dockerfile.template`, before `service postgresql start` at `:257`)
  compares the major version on the volume (`/var/lib/postgresql/*/main/PG_VERSION`) with
  the image's. If they differ it refuses by name: `The database on this volume is
  PostgreSQL 16 and this image carries 18. Move it with rebase_site_container.sh; the
  data is untouched.`
- `install.sh`'s site-container path makes the same check before `docker run`
  (`install.sh:4399`) and refuses, pointing to the same script.
- Shell gate: fixture volume trees (16 under 18, 18 under 18, empty) → refuse / start /
  start.

**WP2 — B6: a recreated container loses its agent identity.** The agent keeps its
credential in `/etc/joinery-agent` (`identity.go`, `agentConfigDir`). No volume covers
that path (`install.sh:4408`–`:4421`), so any `docker rm` + `docker run` brings the agent
back unpaired, and the site has to be re-paired by hand.
`migrate_site_to_code_volumes.sh:143`–`:148` learned this the hard way and carries the
directory across only while the image is unchanged.
- Add `-v "${SITENAME}_agent":/etc/joinery-agent` to both `docker run` forms.
- `rebase_site_container.sh` (WP7) seeds that volume from the old container. A site not
  being rebased gains the volume at its next recreate, seeded the same way.
- `installer_contract_test`: both forms mount it.

**WP3 — B2: a site copy reports success when its database only partly loaded.**
`_site_init.sh:317`–`:320` pipes the clone into `psql -q 2>/dev/null` with no
`ON_ERROR_STOP`. psql exits 0 after SQL errors, so a partial load logs "Database cloned
successfully".
- `psql -v ON_ERROR_STOP=1`, stderr kept and tailed into the failure message.
- Refuse a dump newer than the target server, the same way `restore_database.sh:334`–`:342`
  does. Once one Docker site is on 18, copying it into a 16 container would otherwise
  half-load. The check reads the dump header before any statement reaches psql.
- Gate: `tests/integration/site_init_clone_load_gate.sh` (12 checks).
- The clone key on command lines is B7 (WP3b), not this package.

**WP3b — B7: the clone key and the source's database password travel on command
lines.** Any process on either machine can read them while a clone runs:
- **Source:** `utils/clone_export.php:262`–`:268` builds one `sh -c` string holding
  `PGPASSWORD=…` and `openssl … -pass pass:<key>`.
- **Destination:** the key goes to `_site_init.sh` as `--clone-key=` (`:141`); inside
  it, to `curl -H "Authorization: Bearer …"` (`:317`, `:331`, `:339`, `:358`, `:366`)
  and `openssl -pass pass:` (`:318`).
- **In between:** `install.sh --clone-key` (`:3450`), `docker run -e CLONE_KEY=` (`:4353`),
  and the management node's job command (`JobCommandBuilder.php:3998`).
- **Fix (built 2026-09-24):** every hop carries the key in the environment or a 0600 file.
  - **Management node:** `JobCommandBuilder` 1.77 names `clone_key` in the bootstrap
    step's stdin list, after `admin_password`; the session reads it into
    `JOINERY_CLONE_KEY` and fails without it. The command carries only `--clone-from`.
    `InstallJobExecutor` 1.8 writes one stdin line per name (`step_stdin()`); a single
    name as a string still works for jobs stored before the list. The scrub of finished
    jobs and the display redaction stay for commands stored in the old form.
  - **Source:** `clone_export.php` 1.6 runs `pg_dump | gzip | openssl -pass
    env:JOINERY_CLONE_KEY` with the database password and key in the pipeline's
    environment (proc_open), and logs pg_dump's error instead of discarding it. Proven on
    dev: the output decrypts to a valid dump, and neither secret was on any command line
    during the run.
  - **Destination:** `install.sh` 2.80 reads `JOINERY_CLONE_KEY` (a typed `--clone-key` is
    exported there at once), sends it to the container in the 0600 env file and to
    `_site_init.sh` by inheritance, and reads the manifest's bearer header from a 0600
    file. `_site_init.sh` 3.6 hands curl a header file and openssl a key file, both 0600
    in a directory removed on exit. `Dockerfile.template` 5.3 passes no key argument.
  - **Docs:** `installation.md` and `INSTALL_README.md` show the environment form.
  - **Tests:** `job_command_builder` (the command never holds the key; stdin order; the
    executor's rules), `customer_cloud_provisioning`, `site_init_clone_load_gate`
    (17 checks), and six contract checks, one per hop.
- **Committed 2026-09-24** (71277d44). The installer files went in while the dev
  converger was stopped. `installer_contract` 708/708, and green on five straight runner
  runs. Its first two runs after landing each failed 3–4 checks and could not be
  reproduced; the timing-bound agent-keepalive section is the likely cause under load.

**WP4 — B3: a new PHP version never gets the platform's settings.**
`host_housekeeping.sh` tunes `php.ini` only when the file is **missing** (`[[ -e … ]] &&
continue`). After an in-place PHP switch, 8.5's packaged `php.ini` exists, so it keeps a
2 MB upload limit and no UTC timezone.
- The converger deliberately never touches a `php.ini` that exists, so an owner's edit
  survives (its gate pins that). Keep that, and add one case: a `php.ini` **byte-identical
  to its version's `php.ini-production`** is the untouched copy a PHP package installs,
  so it is tuned. Anything that differs from the template is still left alone.
- FPM restarts only when the tuning changed the file. A template the tuning finds nothing
  to change in would otherwise restart FPM on every one-minute converge.
- `host_housekeeping.sh` 1.5; gate extended (78 checks): the stock file gets tuned, an
  owner's edit beside a template survives, a second converge changes nothing, and an
  untunable template is not announced.

**WP5 — B4: the docs say backups never carry the site's config; they do.**
`docs/backups.md:896` and `docs/deploy_and_upgrade.md:837` say config and
`secret_box_key` are never in a backup. `backup_project.sh:321` says the archive carries
`config/`, and the chain files engine excludes only `agent_signing_key`. What is true:
the archive carries `config/`, encrypted, and a restore keeps the **target's** config
and keys on purpose. Restate both docs to say that.

B1 — backup messages under-report size — is WP1 of `backup_database_incrementals.md`.
Built 2026-09-24: `BackupRunner` 1.22 states `Full backup 4.7 GB (files 3.0 GB, database
1.7 GB)`, and `BACKUP_BYTES` is the whole run. Every backup size is shown in decimal units
by `BackupRunner::human()`, which the dashboard's formatters call. The objects notice's
2 GB threshold is a true 2 GB, so the number it shows is the number it means.

**B8 — scrolldaddy's DNS resolvers depend on database access that lives only in its
container, and a rebuild or a restore drops it.** Confirmed on docker-prod 2026-09-24
(read-only, over SSH):
- **The port binding is hand-made.** scrolldaddy publishes its database on
  `192.168.206.198:9087`, docker-prod's private address. The other seven containers use
  `install.sh`'s `127.0.0.1:908x`.
- **No access rule was added by hand** (`docker diff` shows nothing under
  `/etc/postgresql`). The resolvers get in through the old base image's own
  `host all all 0.0.0.0/0 md5` rule. Current `install.sh` writes a container rule that
  admits only the Docker bridge (`install.sh:2880`–`:2889`). So a rebuild on any current
  image locks the resolvers out even if the port binding were kept.
- **The resolver role holds 143 grants.** `scrolldaddy_reader` is a login role, granted
  on all 143 tables. Connected at the time of the check: the primary resolver from
  `192.168.206.21`, the secondary from its public address `97.107.131.227`. The ops
  guide's `192.168.206.198` for the primary is wrong; that is docker-prod.
- **The database is exposed beyond the resolvers.** Every role, the `postgres` superuser
  included, accepts a password login from any address that can reach
  `192.168.206.198:9087`. The secondary reaches it from a public address, so it is not
  confined to a private link. `ufw status` reported no rules on docker-prod.

Consequences:
- **Any routine `install.sh site scrolldaddy` rebuild cuts the resolvers off.** The port
  returns to loopback and the new image's rule refuses them.
- **Restoring scrolldaddy's backup onto a fresh server fails outright.** The dump carries
  143 `GRANT … TO scrolldaddy_reader`, that server has no such role, and
  `restore_database.sh` loads under `ON_ERROR_STOP` (`:384`, `:400`).
- **A level-3 verification cannot catch that.** It rehearses the restore into a throwaway
  database on the same server, where the role exists.
- **WP7 would have cut the resolvers off.** Fixed: `rebase_site_container.sh` 1.1 refuses
  a container with any port binding `install.sh` does not recreate, and names each one.

**Fixed 2026-09-24, one declared home for each piece** (released in 0.8.426):
- **Roles:** `restore_database.sh` 3.8 reads every role the dump names (owners, grantees,
  default privileges; COPY data skipped) before it drops anything, and creates any the
  server lacks, unable to log in. A role it may not create is refused as
  `RESTORE_ROLE_MISSING`, database untouched. Nothing new goes into backups, so every
  backup already stored restores too. The owner decision on password hashes falls away: a
  dump holds none, so a login is set again on the target, where the resolvers need
  repointing anyway. Wider than scrolldaddy: dev's own dump names `iemap_joinerytest`,
  the mailbox plugin's role, so every mailbox site had the same failure. That installer
  re-creates its role with login and password on every run.
  Gate: `restore_roundtrip` 37 checks (11 new; 7 fail on the old engine, which dropped the
  schema and then failed the load).
- **Port:** a `publish <address>` line in `config/postgres_access.conf` names the host
  address for the database port. `install.sh` 2.82 reads it at every rebuild and refuses
  an address the host does not hold, or `0.0.0.0`, before touching the old container. It
  also exempts exactly that address and port from `install.sh docker`'s `DOCKER-USER`
  block of 9080–9099. On a Linode the private address is on the public interface, so
  without the exemption the block would cut the resolvers off. `install.sh docker` now
  adds that block once, below any exemption. docker-prod carries a hand-made version
  (checked 2026-09-24): ACCEPT 9080–9099 from 97.107.131.227 and from all of
  192.168.128.0/17 (every Linode on that private network), then DROP 9080–9087. A re-run of
  `install.sh docker` there would insert its DROP above those ACCEPTs and cut the
  resolvers off, unless the tagged exemption is already in place.
  `host_housekeeping.sh` 1.8 passes over the `publish` line.
- **The move script:** `rebase_site_container.sh` 1.2 accepts the declared binding and the
  loopback web port. It no longer carries `pg_hba` lines itself, because housekeeping
  rebuilds `pg_hba` from the declaration at every container start. `prepare` refuses a
  network line the file does not declare, naming it.
- **Stopgap:** done live on 2026-09-24 (next section).
- **Superseded 2026-09-25:** no `publish` line. The owner wants no exception at all, so
  the resolvers stop reading the database and the exception mechanism is removed:
  `specs/dns_resolvers_read_over_https.md`.

**PostgreSQL answers only locally — every box, and every new install** (owner, 2026-09-24:
"Postgres should be completely shut off to any remote access and only work locally").
- **Checked 2026-09-24 over SSH** (owner-approved for existing boxes) and from outside.
  Nothing answers from the internet on any box. dev and jeremytunnell were already
  local-only (listen `localhost`, loopback rules only; the July 5432 exposure on
  jeremytunnell is gone). The docker-prod host, both DNS servers and the relay run no
  PostgreSQL server.
- **Fixed live on docker-prod, all eight containers.** Each had a network-wide rule:
  `0.0.0.0/0 md5` from the old base image, or `172.16.0.0/12` on getjoinery-developers.
  Every other container on the host could log in to each site's database.
  - Each is now loopback plus the Docker host (`172.17.0.1/32`). The original is kept
    as `pg_hba.conf.pre-local-only`.
  - Verified per site: the host still reaches the password check, another container
    gets "no pg_hba.conf entry", and the site answers.
  - scrolldaddy also admits `scrolldaddy_reader` from its two resolvers only. From
    dns-primary the reader reaches the password check and `postgres` is refused. Both
    resolvers are active and reconnecting, with no database errors.
  - Its exception is declared in `config/postgres_access.conf` on its config volume.
- **Built in, committed 2026-09-24** (71277d44; landed with the converger stopped):
  - `host_housekeeping.sh` 1.7 section 5 enforces it on every converge and at every
    container start. It removes any rule admitting a network address, or an include
    directive, and keeps the original once.
  - In a container it adds only the gateway and the checked declared lines. A declared
    line must name one database and one non-`postgres` role, from no wider than a /24
    (IPv6 /64), with md5 or scram.
  - On a standalone server it pins `listen_addresses` with
    `conf.d/99-joinery-local-only.conf`. It reads `postgresql.auto.conf` last and names
    an `ALTER SYSTEM` value that would outrank it: dev's own `'*'` in `postgresql.conf`
    is overridden there by `localhost`, so dev gets the drop-in and no restart.
  - `install.sh` 2.81: a container image's rules are loopback only.
  - Gate: 104 checks. Contract: 12 checks, which fail on the old tree.
  - Docs: `installation.md` § PostgreSQL access.
- **B8 after this:** the access rules are declared and survive a rebuild. The port binding
  and the role were fixed next (above).

**B11 — a new server got a `user1` account** (owner, 2026-09-24: "for new installs, I don't
want user1 with logins turned on either"). `install.sh server` always created `user1`
and added it to `www-data`. When root held SSH keys it also copied them to `user1` with
`NOPASSWD: ALL` sudo. Nothing a production site runs depends on it; only dev-box tooling
names it. Fixed (committed 71277d44):
- `install.sh` 2.81 creates no account, copies no key and grants no sudo.
- Root login becomes keys-only when root holds keys, off under `sudo` from an ordinary
  account, and is left as it is when root has only a password (the management node
  retires that password).
- `linode_stackscript.sh` 2.0 honours no `JOINERY_SSH_KEY`.
- Existing boxes keep their `user1`; the owner exempted them.

**B12 — the root-login hardening never took effect on Ubuntu.** It replaced
`#PermitRootLogin yes`, but Ubuntu ships `#PermitRootLogin prohibit-password`, and sshd
takes the first value it reads — `sshd_config.d/*.conf` is included first. Fixed (committed 71277d44):
the setting goes in `sshd_config.d/00-joinery-root-login.conf`, and `sshd -t`
checks it before SSH restarts.

**B10 — every site container's web server is reachable from the internet over plain
HTTP, bypassing docker-prod's front proxy** (found 2026-09-24 during B8, confirmed on the
host). From dev, 23.239.11.53 ports 8080–8082 and 8084–8088 all answer HTTP (200, or 302
to `/login`). `docker ps` shows every container's web port on `0.0.0.0` and `[::]`, and
`ufw status` reported no rules.
- **Cause:** `install.sh` publishes each container's web port on every interface (`-p
  "$PORT":80`), and Docker's published ports bypass UFW.
- **What it skips:** the host Apache proxy is the intended way in. A request to IP:port
  skips HTTPS, skips Cloudflare, and is logged only inside the container, where the
  host's fail2ban does not read — so a login form can be brute-forced over plain HTTP.
- **Nothing reaches a container's web port from off the host** (searched 2026-09-24): every
  management-node probe, the SSL and certificate checks, Cloudflare and the agent use the
  domain through the proxy, which targets `127.0.0.1:<port>`. The exception is a site with
  no domain (or `--no-ssl`), which gets no proxy: its port is its only way in.
- **Fixed in the tree 2026-09-24** (released in 0.8.426):
  - `install.sh` 2.82 publishes a proxied site's web port on `127.0.0.1`. A site with no
    domain keeps every interface, and says so. The post-start probe asks `127.0.0.1`. The
    port checks and the container list read a binding on any address.
  - `manage_domain.sh` 1.2 warns when a domain is set on a container whose port still
    answers on every interface. On clearing a domain from a loopback-bound one, it says
    nothing off the host reaches the site now.
  - Docs: `installation.md` (Docker trust bullet), `INSTALL_README.md`, Server Manager
    overview.
  - Contract: 18 checks (15 fail on the current installers), including the publish-address
    resolver and the firewall exemption run against stubs. The live container gate asserts
    both bindings.
- **IPv6 too** (confirmed 2026-09-24 from dev: 200 at `[2600:3c03::2000:d1ff:fef0:1ec5]:8087`).
  Docker publishes `-p PORT:80` on `[::]` through docker-proxy on the host, so IPv6 ends in
  INPUT, where a `DOCKER-USER` rule never sees it. `-p 127.0.0.1:PORT:80` publishes on
  IPv4 loopback only, which closes both.
- **Existing containers keep `0.0.0.0` and `[::]` until rebuilt.** Stage 3 rebuilds all
  eight through `install.sh`. The owner approved closing it now on docker-prod (Q1,
  2026-09-24): `DOCKER-USER` DROP 8080–8180 on eth0 (IPv4), `ip6tables INPUT` DROP
  8080–8180 on eth0 (IPv6), and the tagged `joinery-declared-db-publish` RETURN for
  192.168.206.198:9087, saved with `netfilter-persistent` (originals kept as
  `/etc/iptables/rules.v{4,6}.pre-web-ports`). **Applied and verified 2026-09-24:** from
  dev every port 8080–8088 is dropped over IPv4 and IPv6. All eight sites answer by
  domain over HTTPS and on the host's loopback. Both resolvers reconnected to the
  database through the new exemption and report `db_connected`.

**B13 — a new Docker host cannot build its base image** (found 2026-09-24 in rehearsal R1).
`install.sh server` loads `_host_files.sh` from its own directory (since `install.sh` 2.78,
release 0.8.422), and the base image build runs `install.sh server`. But `do_build_base`
put only `install.sh` in the build context, and `Dockerfile.base` copied only `install.sh`.
The build stops at "Configuring Apache" with `/tmp/_host_files.sh: No such file or
directory`. So a Docker host without `joinery-base` cannot install its first site: a new
customer Docker host, or any host's first install. docker-prod and test380s built their
base before 0.8.422.
- **Fix** (in the tree 2026-09-24, landed with the converger stopped): `install.sh` 2.83 copies `_host_files.sh` into the build context, and
  `Dockerfile.base` 1.2 copies it beside `install.sh` and removes both after. No base
  version bump: the image's contents do not change.
- **Contract:** for every file `install.sh` loads from its own directory, `do_build_base`
  and `Dockerfile.base` both carry it (3 checks; 2 fail on the current tree).
- **Recorded, not changed:** the base image's drift label hashes only `do_server_setup`,
  so an edit to `_host_files.sh` does not warn that the base is stale. WP6's version bump
  rebuilds every base anyway.

**B14 — one bad answer from an Ubuntu mirror fails an install** (found 2026-09-24 in R1).
The site image's dependency step ran `apt-get update` once. It failed with `File has
unexpected size … Mirror sync in progress?` on noble-security, and the whole site build
stopped. The same single attempt sat in four places in `install.sh`.
- **Fix** (landed with B13): `install.sh` 2.83 `apt_update` tries up
  to four times, 30 seconds apart, and every index update in `install.sh` goes through it.
  `Dockerfile.template` 5.4 runs the same loop in `/bin/sh`. Both were exercised against a
  failing `apt-get` stub: success on the third call proceeds, four failures stop.
- **Contract:** 3 checks. The grub-order check now anchors on `apt_update && apt upgrade -y`.
  The full contract passes 738/738.
- **Side effect:** `do_server_setup` changed, so a host whose base predates this release
  prints the advisory "do_server_setup has changed since joinery-base was built" until
  WP6's base 2.0 replaces it. Warning only.

**B15 — PostgreSQL would not start on a kept volume from an older image** (found 2026-09-24
in R1). Images give `postgres` different ids: `joinery-base:1.2` has 101/104, and 2.0 has
100/103. A rebuilt site keeps its `_pg_logs` volume, which the old `postgres` group owned,
so PostgreSQL 18 could not create `postgresql-18-main.log` and never started. The same
drift would stop a kept data volume between two images of one major.
- **Fix** (in the tree): `Dockerfile.template` 5.5's start command hands
  `/var/lib/postgresql` and `/var/log/postgresql` to the image's `postgres` user when
  another owns them. It runs after the foreign-major refusal and before PostgreSQL starts.
  Contract: 3 checks.

**B16 — the move script could lose its way back** (found 2026-09-24 in R1; all fixed in
`rebase_site_container.sh`, in the tree):
- **Stopping writes stopped the container (1.3).** `service apache2 stop` ended it, because
  Apache is its main process; the restart policy started it again, and swap died silently.
  `stop_site_writes` now stops PHP-FPM, cron, the agent and Postfix, and checks the
  container is still up with no PHP-FPM left.
- **Silent failures (1.3):** an error trap names the failing line. swap and rollback wait
  for the site to finish starting before reporting its front page; it read 000 and 502
  while the installers ran.
- **The rollback image was kept by name (1.4).** `install.sh` rebuilds under
  `joinery-<site>`, so after one swap the name meant the new image. A second swap on stale
  prepare data tagged that as the rollback image, and rollback then started PostgreSQL 16
  data on an 18 image. The old image is now recorded by id.
  - rollback ends at `rolled_back`, so another swap needs a fresh prepare.
  - prepare refuses while a move is in flight.
  - swap refuses a container whose image changed since prepare, or a rollback tag that
    already names another image.
- **Rollback could not start the old server (1.4).** The new image's start command (B15)
  had handed the log directory to its own `postgres`, so rollback hands it back to the old
  image's ids first.
- **Cosmetic:** the trial dump shows to a tenth of a MB (a 0.7 MB dump read "0 MB").
- Contract: 6 checks, which fail on the staged 1.2.

**B17 — every fresh install came up without its default plugins** (found 2026-09-24 in the
WP6 gate). `_site_init.sh` installs the `personal` bundle (mailbox, joinery_ai) on a fresh
site. Every bundle package is verified against `config/release_verify_keys`, and nothing
had written that file yet:
- **Docker:** the container's host installers write it after first boot.
- **Standalone:** `install.sh` called its writer after `_site_init.sh`, and that writer
  defers to the host installers whenever the tree ships an agent bundle.

Both packages were refused with `no_keys`, and the site came up bare with one warning line.
This has been so since package signing (S9, 2026-09-12), for Docker and standalone alike;
the quick-deploy customer install is standalone. Proven on the test box: with the key in
place, the same bundle installs cleanly.
- **Fix** (in the tree 2026-09-24, landed with WP6): the converger's key
  writer moves into `_host_files.sh` 1.2 as `host_files_write_release_verify_keys`. The
  runner (2.19) calls it every tick, as before, and `_site_init.sh` 3.7 calls it just
  before the bundle. `install.sh` writes its upgrade-server fallback before `_site_init.sh`.
- **Proven:** a fresh site built from the fixed installers logged "release key … carries
  the agent bundle's signing key", then "mailbox: installed and activated, joinery_ai:
  installed and activated, Plugin bundle installed" on first boot.
- **Tests:** the converger gate's key cases run against the shared writer (112/112). The
  contract's package-signing checks read `_host_files.sh`, and two ordering checks pin
  key-before-bundle on both paths (7 fail on today's tree; the full contract passes
  752/752 against the fix).
- **Sites already installed** without the bundle get their plugins from the admin Plugins
  page; nothing installs them after the fact.

**B21 — a moved site's signed manifest came from another release** (found 2026-09-25
moving joinerydemo). The manifest sits at the site root in the container's own layer; the
code lives on a volume. `install.sh` lays the newest core from its upgrade server into the
image and takes the manifest from that archive. getjoinery.com still served 0.8.426, so
the rebuilt joinerydemo had 0.8.430 code under a 0.8.426 manifest (28 files failed). Its
agent would have refused every backup, as in R1. Hand-fixed by copying the 0.8.430
manifest (the same bytes the unmoved sites carry) into the container: 0 files failed.
- **Fix** (move script 1.5): `prepare` and `swap` refuse a site whose files
  do not match its manifest. `swap` keeps the old container's manifest and puts it into
  the new one, stopping unless every file matches. It checks again after the restart.
  `rollback` puts it into the old image's container too: that layer holds whatever release
  the old image was built from.
- **Still open, outside the move:** any container rebuilt from its image gets the image's
  manifest back, whatever the code volume holds. Recorded in the running to-do list.

**B22 — the move renders the host vhost for a stale name** (found 2026-09-25 moving
joinerydemo). `prepare` read the domain from the container's `DOMAIN_NAME`, and `install.sh`
rewrote `/etc/apache2/sites-available/<site>.conf` for that name.
- joinerydemo's said `joinerydemo.site`, a name that is now unregistered. The host served
  it as `demo.getjoinery.com`, the name its `webDir` and its Let's Encrypt certificate
  carry.
- After the swap the host had no vhost for `demo.getjoinery.com`, and Cloudflare answered
  526. The deploy tier's read-only gate caught it.
- `install.sh` also minted a placeholder certificate and started a five-minute retry timer
  (`joinery-ssl-retry@joinerydemo.site`) for the dead name.
- The same survey found getjoinery_orgs's `DOMAIN_NAME` is `getjoinery.com`, another
  site's name; it is served as `orgs.getjoinery.com`. getjoinery_developers is served from
  `getjoinery_developers-proxy.conf` and `-proxy-le-ssl.conf`, so a rebuild would put a
  second `developers.getjoinery.com` vhost beside them.

- **Fix** (move script 1.5):
  - `prepare` takes the name from the enabled host vhosts that proxy to the site's web
    port, with `www.` folded in, and prints any disagreement with `DOMAIN_NAME`. It refuses
    a site served under several names, and lists other vhost files on the port.
  - `swap` keeps the site's host vhost files, and disables the other files once
    `install.sh` has written `<site>.conf`.
  - `rollback` puts all of them back.
- **Tests:** `installer_contract` checks the name choice on fixture vhosts (www folding,
  files under any name, two names, none) and pins the carry-overs statically: 761/761.
- **joinerydemo, fixed 2026-09-25 23:23 UTC (owner approved):**
  - `joinerydemo.conf` was rendered from the 0.8.430 template for `demo.getjoinery.com`.
    The file on disk had been exactly `install.sh`'s render for the dead name; it is kept as
    `joinerydemo.conf.swap-wrong-domain.20260925232310`.
  - The retry timer is stopped and disabled, and the placeholder is removed.
  - The site answers again: `/` 302, `/login` 200, 0.8.430, on its Let's Encrypt
    certificate.

**B23 — three certificate lineages still let certbot edit the vhost** (found 2026-09-25
moving getjoinery_developers). The renewal configs for `demo.getjoinery.com`,
`developers.getjoinery.com` and `orgs.getjoinery.com` still say `installer = apache`, with
no `renew_hook`.
- The five others on docker-prod were healed on 2026-09-11 (`installer = None`, reload
  hook). The three missed are the sites whose name differed from the container's
  `DOMAIN_NAME` or vhost file name.
- Only `render_vhost.sh` heals a renewal config, and on a Docker host nothing runs it
  against the host's proxy vhosts. `install.sh` does not heal an existing lineage.
- At the next renewal (developers expires 2026-11-17), certbot would edit the rendered
  vhost again, which is the drift `specs/implemented/tls_and_origin_trust.md` WP1a ended.
- **Healed live 2026-09-26 00:29 UTC** (owner approved): the three configs now say
  `installer = None` with the reload hook. The originals are kept as
  `.conf.before-render.20260926002949`. `certbot certificates` reads all eight lineages
  as valid.
- **Fix at root:**
  - The rule moves from `render_vhost.sh` (1.9, which drops its copy) into `_host_files.sh`
    1.3.
  - `host_files_heal_renewal_confs` heals every lineage on the machine that renews through
    Apache, whatever its name. Lineages issued by webroot or DNS are left alone, and an
    owner's `renew_hook` is kept.
  - `host_housekeeping.sh` 1.9 runs it at every converge: site mode on a standalone box,
    machine mode on a Docker host (which is how docker-prod's own converger runs it), and
    a no-op in a container.
- **Tests:** a new section of the housekeeping gate covers a lineage under an unrelated
  name, a DNS lineage, an owner's hook, and a second run (113/113). The contract's heal
  checks run against `_host_files.sh` (772/772).

**B24 — `install.sh` dies when its upgrade server does not answer** (found 2026-09-26
moving getjoinery).
- `download_core_archive` is meant to warn and build with the archive copy. But
  `upgrade_info=$(curl -sf …)` is a plain assignment under `set -e`, so a failed curl ends
  the script with no message. The theme download beside it survives only because
  `local x=$(…)` hides the exit code.
- getjoinery is `install.sh`'s default upgrade server and was the site being moved, with
  its writes stopped, so its swap failed. It was rolled back after about 65 seconds of
  downtime, then moved with `swap -- --upgrade-server=https://dev.getjoinery.com` (dev
  serves the same 0.8.430).
- **Fix** (`install.sh` 2.85, landed with the converger stopped, 2026-09-26):
  `upgrade_info=$(curl …) || upgrade_info=""`. It was the only fallible plain assignment
  in the download path; the theme fetches are `local` and the release-key fetch ends in
  `|| true`.
- **Test:** the contract runs `download_core_archive` under `set -e` against a closed
  port; the install carries on with the fallback warning.

**B25 — a rollback does not bring back the old container** (found 2026-09-26, the
getjoinery rollback).
- `rollback` recreates the container from the old image with its old arguments. Much of
  what the old container was had been added at runtime and lived in its own layer:
  - PHP `apcu` and `sqlite3`, installed by upgrades (Composer validation failed at start);
  - the agent and the host converger cron entries;
  - the local-only `pg_hba` (the image's `host all all 0.0.0.0/0` came back);
  - the remoteip and `php.ini` tuning.
- The old image's start command also calls the retired `_reconcile_stock_assets.sh`.
- The site served pages, but its agent was dead: a status check waited unanswered. And
  `prepare` refused its `pg_hba`.
- Running `host_housekeeping.sh` through the installer runner restored `pg_hba` and the
  tuning.
- **Fix** (move script 1.6): once the old start command has brought Apache up,
  `rollback` runs `restore_runtime`:
  - installs the PHP extensions the site's code declares (the `list_dependencies.php --apt`
    loop the image build and `upgrade.php` use), plus every PHP package `prepare`
    recorded that is missing. A site prepared after a rollback records a list that is
    already short, which is why the declared list comes first;
  - restarts PHP-FPM when it installed anything;
  - runs every core installer the way a current image's start command does;
  - waits for the agent, and checks the manifest again.
- **Tests:** the contract drives `restore_runtime` against stubs. It installs exactly the
  missing declared extension and the missing recorded package, names one it cannot
  install, reports a missing agent, and installs nothing on a second run.
- Not yet run live. A rollback of a real site is downtime; test380s could rehearse it.
  The script on docker-prod is 1.5 until 1.6 is committed and copied there.

**B9 — the platform's PHP tuning loaded the PostgreSQL extensions twice** (fixed
2026-09-24). `host_files_tune_php_ini()` enabled `extension=pdo_pgsql` and `extension=pgsql`
in `php.ini`, but Ubuntu's php-pgsql package already loads both from `conf.d`. Every PHP
start then logged `Unable to load dynamic library 'pdo_pgsql' … undefined symbol:
pdo_parse_params` (it loaded before PDO) and `Module "pgsql" is already loaded`. Every box
`install.sh` set up carries it. Reproduced on dev against a tuned copy of its own `php.ini`.
- `_host_files.sh` 1.1 no longer writes the lines.
- `host_housekeeping.sh` 1.6 comments them back out wherever `conf.d` loads the same
  module. Only those two lines; the rest of an existing `php.ini` is still never touched.
  The fleet gets the repair at its first converge after a release.

## Stage 2 — The 26.04 container image (dev tree)

**WP6 — Land `joinery-base:2.0`.** This was proven and deliberately held back in
August. **In the tree 2026-09-24** (landed with the converger stopped; released in 0.8.426): `install.sh` 2.84 (`BASE_IMAGE_VERSION` 2.0), `Dockerfile.base` 1.3
(`FROM ubuntu:26.04`), `Dockerfile.template` 5.6 (default 2.0). Contract: 3 checks, one
pinning the template's default to `install.sh`'s version. Docs: `deploy_and_upgrade.md`
§ Docker Shared Base Image. **Gates run on the owner's test box:**
- Base 2.0 built: Ubuntu 26.04.1, PostgreSQL 18, PHP 8.5.4.
- A fresh site on it passes the deploy tier (4/4).
- A routine `install.sh site` rebuild of that site keeps the agent's identity: same
  fingerprint and identity file, new container. The site was not yet approved, and a
  minute later its agent asked again with a new key (B19).
- Not run, because it buys a Linode: the quick-deploy live gates with a customer provision.
- `Dockerfile.base:17` becomes `FROM ubuntu:26.04`; `BASE_IMAGE_VERSION` goes `1.2` → `2.0`
  (`install.sh:477`).
- The first site install on each Docker host builds the new image (`install.sh:4103`–`:4108`).
  Running containers keep their image until recreated, and WP1 guards that recreate.
- From this release on, every new Docker site is born on PostgreSQL 18 / PHP 8.5. A
  customer server provisioned before it is the only kind of box that ever needs Stage 3
  done without a shell (see *Not in this spec*).
- **Ships only in a release that already carries WP1 and WP2.**
- Gates: build 2.0 on a scratch Docker host, install a fresh site, run the deploy tier in
  it; recreate that site's container and confirm the agent stays paired (WP2). Then re-run
  the quick-deploy live gates (`specs/linode_quick_deploy_app.md`) with a customer
  provision.

## Stage 3 — Moving the eight Docker sites (owner, on docker-prod)

**WP7 — `maintenance_scripts/sysadmin_tools/rebase_site_container.sh <site>
prepare|swap|rollback|finish`** (built, 1.1), run as root on the Docker host from the
extracted release that carries the new base image. The container is rebuilt **by that
release's `install.sh`** (`-y site --docker`, the password in a 0600 file), so the result
is exactly a fresh install of the site, WP1 and WP2 included. The old container's
arguments are saved (as `migrate_site_to_code_volumes.sh` reads them from `docker
inspect`), and its image is tagged `joinery-<site>:pre-rebase-pg<N>`, so rollback can
recreate it exactly.

`prepare` — nothing destructive; the site keeps serving:
- Refuse if the site is already on the image's PostgreSQL major, or if the target image
  is missing and cannot be built.
- Read the site's database name and password from its `_config` volume.
- Inventory the container's writable layer: roles other than `postgres`
  (`pg_dumpall --roles-only`); refuse any `pg_hba.conf` network line the site's
  `config/postgres_access.conf` does not declare; the `/etc/joinery-agent` directory; `/etc/cron.d` and crontabs; installed PHP
  packages. Everything is written to `/root/rebase/<site>/` and printed, so an unexpected
  customization is seen before anything moves.
- Record the source database's encoding and locale, and refuse if the new image lacks
  that locale.
- Record every table's row count.
- Take a trial dump to measure its time and size, and check the host has room for the
  dump plus a copy of the old volume.

`swap` — the site is down from here until the gates pass:
- Stop PHP-FPM, cron, the agent and Postfix inside the container; PostgreSQL and Apache
  stay up, and Apache answers 503. Apache is the container's main process, so stopping it
  would end the container. Then take the real `pg_dump -Fc`. Nothing writes after it.
- Copy the `_postgres` volume to `<site>_postgres_pg16` (the rollback copy), stop and
  remove the container, and remove `_postgres`.
- Recreate the container on the new image with the old container's ports, environment,
  restart policy and volumes, plus the `_agent` volume (WP2) seeded from the inventory.
  A fresh `_postgres` volume starts with the image's empty PostgreSQL 18 cluster.
- Set the `postgres` role's password from the config — the same trust-swap as
  `Dockerfile.template:262`–`:273`, which runs only when there is no config.
- Recreate the inventoried roles, `createdb` with the recorded encoding and locale,
  then `pg_restore --exit-on-error`. The restore rebuilds every index under 26.04's
  collation, which is why this moves data by dump rather than by `pg_upgrade`.
- `pg_hba.conf` comes from the site's `config/postgres_access.conf` (B8), rebuilt by
  housekeeping at every start. Install the declared PHP extensions
  (`utils/list_dependencies.php --apt`, as `utils/upgrade.php:1644` does).
- Start Apache and cron.
- Gates (§ Per-site gates). A failure prints the rollback command and stops.

`rollback`: remove the new container and `_postgres`, restore `_postgres` from the
`_pg16` copy, and recreate from the saved arguments on the kept image. Once the old
database answers again, the copy is removed (the data is live again) and the stage
returns to `prepared`, so a retry needs a fresh `prepare`.

`finish` (after a week): remove `_pg16` and the dump.

**WP8 — Order.** One site at a time, each passing its gates before the next starts:
1. **Rehearse** on a scratch Docker host: 24.04 server, a site on base 1.2, rebase to 2.0.
   test380s is such a host if the owner wants to use it instead.
2. joinerydemo
3. galactictribune
4. phillyzouk
5. mapsofwisdom
6. getjoinery-orgs
7. getjoinery-developers
8. getjoinery — the production management node, so dispatch nothing from it while it moves.
9. **scrolldaddy last, after `specs/dns_resolvers_read_over_https.md` WP7.** By then its
   DNS resolvers read the site over HTTPS and its hand-made database port is reported
   as dropped rather than refused, so it moves like the others. Confirm both resolvers
   serve during and after the move.

## Stage 4 — The two standalone boxes (owner, by hand)

No agent job can do this: nothing in the agent runs apt or `do-release-upgrade`, and
the reboot would end the job. It is a shell session on the box.

**WP9 — Inventory both boxes** (read-only; paste the output into this spec):
`ls /etc/apt/sources.list.d/`, `apt-mark showhold`, `dpkg -l 'php*' | grep ^ii`,
`pg_lsclusters`, `df -h /`, `free -m`, and
`systemctl list-units --type=service --state=running`. Known already: dev's PHP 8.3 comes
from the ondrej PPA, and dev also has nodesource, chrome and tailscale sources.
jeremytunnell's package sources are unverified.

**WP10 — Rehearse on a clone of jeremytunnell.** Linode clone at the same plan (a
clone's disk cannot be smaller than the source's).
- **Before the clone's first boot, attach a Cloud Firewall that denies all outbound
  traffic and allows inbound SSH only from the owner's address.** The clone boots as
  jeremytunnell: the same agent identity, the same cron, the same relay pull and backup
  credentials. Unfenced, it would claim the node's jobs, pull the node's mail off the
  relay and write into its backup storage.
- Disable the agent, the host converger timer and path, and cron. Then open outbound
  80/443 for apt only.
- Run the § Standalone runbook with `do-release-upgrade -d`, then the gates, reaching
  the site through a hosts-file entry. Record timings and surprises here, then delete
  the clone.

**Standalone runbook** (rehearsed in WP10, then used for real in WP11/WP12):
1. A successful backup under 24 hours old with a level-2 verification. Take a provider
   snapshot if Linode Backups is enabled on the box (unverified).
2. Stop the agent, `joinery-host-converger.timer` and `.path`, so nothing converges
   packages mid-upgrade.
3. `do-release-upgrade` (non-interactive frontend), then reboot.
4. `pg_upgradecluster 16 main` using the **default dump method**, not `-m upgrade`: a
   dump rebuilds every index under 26.04's collation. The old cluster stays, stopped, on
   port 5433 as the rollback until the gates pass for a week, and is then dropped.
5. `a2disconf php8.3-fpm && a2enconf php8.5-fpm`. Purge PHP 8.3 first:
   `detect_php_version` prefers a leftover `php` binary. Install the declared extensions.
   WP4 then applies the platform's `php.ini` settings.
6. Re-enable the converger and the agent, run a host converge, then the gates.

**WP11 — jeremytunnell for real** (per D3 and D4). Postfix is off there, and inbound mail
waits on the relay while the box is down (`mailbox_listener_decommission`). The mail
concern the old draft named is gone. rspamd and redis are still there: check both after
the upgrade.

**WP12 — dev** (per D4). Also the management node: fleet backups are scheduled from here,
so pick a window away from 03:00–05:00 UTC. The PPA and third-party sources come back
for resolute (or are dropped) after the upgrade. Once dev is on PostgreSQL 18, the
database-incrementals integration tests run in the ordinary gate.

## Stage 5 — Cleanup (dev tree, after the last Joinery site moves)

**WP13 — Code-side cutover** (from `php_85_pg18_stack_cutover.md`):
- Drop 24.04 from the installer's OS gate (`install.sh:2492`) and move the
  `installer_contract_test` assertion (`:266`–`:279`), fixing its stale "PHP 8.3
  hardcoded" comment (`:268`). `--allow-unsupported-os` still covers a hand install.
- Restate the docs as current state: `docs/installation.md:5` and `:100`,
  `docs/deploy_and_upgrade.md` (supported OS), `INSTALL_README.md:297` and `:515` (the
  latter names `postgresql-16-main.log`), and the Server Manager overview's OS
  expectations.
- The PostGIS pin in the unbuilt `specs/geolocation_postgis_spec.md` (`:47`, `:578`) moves
  to 18 when that spec is built, not before.
- The relay birth flow on 26.04 (`RelayCloudProvisioner.php:101`) has never run. Rebuild
  a relay on it the next time one is needed.

## Progress (2026-09-24)

- **Released in 0.8.424** (commit 71277d44):
  - B1: `BackupRunner.php`, `BackupFetch.php`, `BackupChainListHelper.php`,
    `BackupListHelper.php`, `targets.php`, `run_backup.php`, the `JobResultProcessor.php`
    comment, `BackupObjectsNotice.php`, and eight tests that pinned binary-unit sizes.
  - WP1 + WP2: `install.sh` 2.79, `Dockerfile.template` 5.2.
  - WP3: `_site_init.sh` 3.5, new `site_init_clone_load_gate.sh`.
  - WP4 + B9: `host_housekeeping.sh` 1.6, `_host_files.sh` 1.1, gate extended to 84 checks.
  - WP5: both docs.
  - WP7: `rebase_site_container.sh` 1.1 (refuses port bindings `install.sh` would drop, B8).
  - Six new `installer_contract_test` checks (702/702).
  - The dev converger was stopped for the installer edits.
- **Tests:** safe tier over the changed files green, except `sync_sim` (a Drive sync case
  in files another session is editing). The db tier over the changed files was green except
  two not touched here: `core_api_mechanical` (flags `includes/GuardedPdo.php`, committed
  eb06a599) and `sealed_reply_store` (an untracked test in mailbox files in progress
  elsewhere).
- **Dev's own PHP gets B3's fix at the converger's next run.** Its PHP 8.3 `php.ini` is
  the untouched packaged file (2 MB uploads, 30 s, no timezone). The next converge tunes
  it and restarts php8.3-fpm.
- **B8 remainder + B10, built 2026-09-24, released in 0.8.426:**
  - In the tree: `restore_database.sh` 3.8, `rebase_site_container.sh` 1.2,
    `manage_domain.sh` 1.2, `restore_roundtrip_gate.sh`, `install_container_gate.sh`, and
    the docs.
  - Landed with the converger stopped: `install.sh` 2.82, `host_housekeeping.sh` 1.8, the
    housekeeping gate (105/105), and the contract section (18; contract 732/732).
- **Publish of 0.8.425 refused 2026-09-24 18:34** by its own deploy tier: `read_only_tree`
  exited 1, and the log named no check. Every section passes by hand afterwards. The likely
  cause, and fixed: `plugin_uninstall_test` (1.1) wrote its fixture plugins into the live
  `plugins/` with group-writable modes, the exact state that gate refuses; any session
  running the test-db tier during a publish made it fail. The fixtures now take the tree's
  modes (watched through a run: present three times, never group-writable). `tests/run.php`
  now prints a failing shell gate's `FAIL:` lines under it and in the summary, so a refused
  publish names its check.
- **Rehearsal R1 (container move) — passed 2026-09-24** on the owner's test box
  45.79.180.75 (Ubuntu 24.04.4, 1 vCPU, 1 GB, our key on it). The order was the real one:
  - Site `rehearsal` built from 0.8.423: web port on every interface, the agent in the
    writable layer, domain `reh.example.test` behind the host proxy.
  - Upgraded to 0.8.424 from dev (deploy tier passed).
  - Moved with 0.8.424 plus the uncommitted B8/B10/B13–B16 scripts and WP6 (base 2.0 on
    Ubuntu 26.04.1: PostgreSQL 18, PHP 8.5.4).
  - A reader role with 203 grants and a declared `postgres_access.conf`
    (`publish 45.79.180.75`) stood in for scrolldaddy.
  - **Found and fixed on the way:** B13, B14, B15 and B16, plus the move script refusing a
    loopback database binding beside a publish line.
  - **Final clean cycle, all exit 0:** prepare → swap (203/203 tables match, 302) →
    rollback (PostgreSQL 16, 302) → prepare → swap (203/203, 302) → finish.
  - **Timings on 1 vCPU:** the 26.04 base build took about 9 minutes, a swap 2½ minutes
    (mostly the site image build; the dump and restore took seconds on 0.7 MB), a
    rollback 30 seconds.
  - **Proven on the moved site:**
    - the web port is on `127.0.0.1` only, and from dev it is closed on IPv4 and IPv6;
    - the database is published on the declared address, with the tagged firewall
      exemption above the block;
    - from dev, the reader reaches the password check and `postgres` gets
      "no pg_hba.conf entry";
    - the reader role kept its login and all 203 grants;
    - the agent's fingerprint `80fe16726c9cadce` matches its pending join request on dev;
    - the deploy tier passes (the read-only gate needed the fake domain in the container's
      `/etc/hosts`).
  - **Finished 2026-09-25**, after approving request 1787 on dev (node 61130):
    - two `check_status` round trips through the agent (PostgreSQL 18 accepting
      connections), and the recovery-key report it queues;
    - an upgrade 0.8.424 → 0.8.426 through the agent (`apply_update`, deploy tier passed,
      every file matches the signed manifest) — first needed because R1's rebuild had
      laid the uncommitted dev tree over the site, so the agent refused to back it up
      (`run_backup.php` does not match its signed hash) — correctly;
    - one full backup to B2 (69.8 MB, database 128 KB) and a level-2 verification of it
      (4 archives, 2,487 files, pass);
    - the recovery key was set and proven in PHP inside the container (the throwaway
      site had none; the private half was discarded). The node's scheduled backups are
      off. Requests 1788 and 1789 (the `fresh` site) were rejected.
  - **B19 — rebuilding a site that is not yet approved changes its agent's key.**
    `install.sh site` passes `--join` again, `agent_control.php` writes a new
    `requested_time`, and the agent treats a newer ask as a new one: it withdraws, drops
    the staged key and asks with a new key. `fresh` did this at 18:12 on 09-24 (request
    1788, key 2d45…, then 1789, key ec01…). The old request drops off the board
    unrenewed after an hour. So the WP6 gate line "no duplicate join request" held only
    for the first minute. An approved site is unaffected: its credential is promoted and a
    new ask is ignored. Fix: `agent_control.php --join` keeps the existing
    `requested_time` when the same URL is already being asked. Not fixed.
  - **B20 — a site that joins by its agent is never backed up.** `adoptJoin` makes the
    node with no web root, and `hosts_site_from()` reads an empty web root as "no
    site". The node gets no recovery-key report, no nightly backup, and Run backup
    refuses "does not host a Joinery site" — silently, until someone types the path. The
    agent knows the path (`cfg.WebRoot`). Fix: the agent reports its web root (poll or
    `check_status`) and the plane fills an empty `mgn_web_root` from it. Not fixed.
  - **For Stage 3:** a site takes the release carrying the new scripts first, then moves.
    Until it does, its container runs the scripts on its scripts volume, which is why
    housekeeping 1.7 there still refused the `publish` line.
  - The disk reached 11 of 25 GB with both base images; `prepare`'s disk check covers only
    the dump and the volume copy.
  a Linode is a purchase and needs the owner's explicit approval. Docker is not on dev.
- **Rehearsal R2 (jeremytunnell clone) — owner.** jeremytunnell is not in the account the
  dev Linode token reaches (that account holds only the test boxes). The clone,
  the firewall, and the console step to open SSH on the clone happen in jeremytunnell's
  own account.
- **Stage 3, joinerydemo — moved 2026-09-25** from 0.8.430 (`/root/rebase/release-0.8.430`
  on docker-prod, manifest verified). Move script 1.4.
  - `prepare` was re-run from 0.8.430. `swap` exited 0: the site was down from 22:46:54 to
    22:49:14, and 138/138 tables matched.
  - Now on Ubuntu 26.04.1, PostgreSQL 18 and PHP 8.5.4. Ports are on 127.0.0.1 only, and
    pg_hba has loopback plus the Docker gateway.
  - The agent kept its key and pairing. A `check_status` round trip (job 37695) reports
    0.8.430 with PostgreSQL accepting connections.
  - A full backup (job 37696, 81.8 MB, chain-20260925_225842) and a level-2 verification of
    it (job 37697: 4 archives, 3,167 files, pass).
  - Deploy tier: 4/4 after B22's vhost fix. Before it, `read_only_tree` failed one check
    (526).
  - Found: B21 and B22. The one extra step was copying the right manifest into the
    container.
  - Also found: `joinerydemo.site` is unregistered (available at the registry). Only the
    container's `DOMAIN_NAME` carries it; the site's name is `demo.getjoinery.com`, which
    `install.sh` sets at the next rebuild through move script 1.5.
- **Stage 3, the next six — moved 2026-09-25/26** from 0.8.430, move script 1.5 (commit
  63c3a829, copied into the release directory on docker-prod). Every site passed every
  gate:
  - pages over its public name;
  - 0.8.430 on Ubuntu 26.04.1, PostgreSQL 18 and PHP 8.5.4;
  - 0 manifest failures;
  - ports on 127.0.0.1;
  - a `check_status` round trip;
  - the deploy tier 4/4;
  - a full backup and a level-2 verification.

  | Site | Down | Tables | Backup (verified) |
  |---|---|---|---|
  | galactictribune | 23:34:37–23:36:17 | 134/134 | 62 MB, 2,369 files |
  | phillyzouk | 23:38:04–23:40:27 | 133/133 | 202.5 MB, 2,609 files |
  | mapsofwisdom | 23:42:05–23:44:27 | 137/137 | 82.4 MB, 3,167 files |
  | getjoinery_orgs | 23:46:40–23:49:09 | 137/137 | 60.1 MB, 2,655 files |
  | getjoinery_developers | 23:51:26–23:53:57 | 153/153 | 214.9 MB, 2,582 files |
  | getjoinery | 23:58:39–23:59:44, then 00:12:18–00:14:46 | 157/157 | 649.2 MB, 3,074 files |

  - Each host vhost moved from template 1.03 to 1.04 (X-Forwarded-For appended). Nothing
    else changed in it; the pre-move copies are in `/root/rebase/<site>/host_vhosts`.
  - getjoinery_orgs: `prepare` named the stale `DOMAIN_NAME` (`getjoinery.com`), and the
    rebuild used `orgs.getjoinery.com`; the container now says so too. Its layer carried
    a `www-data` crontab duplicating `/etc/cron.d/scheduled-tasks` (so tasks ran twice
    every 15 minutes since 2026-05-03); the rebuild dropped it.
  - getjoinery_developers: its `-proxy.conf` and `-proxy-le-ssl.conf` (forwarding
    `X-Forwarded-Proto "http"`) were disabled for the rendered `getjoinery_developers.conf`,
    and are kept for rollback.
  - getjoinery: the first swap failed (B24) and was rolled back (B25). Then
    `host_housekeeping.sh`, a fresh `prepare`, and a swap with
    `--upgrade-server=https://dev.getjoinery.com`. Nothing was in flight on it (0 jobs,
    0 nodes of its own, no publish). The status check left waiting through the rollback
    completed once the new agent came up.
  - docker-prod afterwards: 36 of 78 GB used, 2.5 GB memory available, and seven
    `pre-rebase-pg16` images and `_postgres_pg16` volumes kept until `finish`.
- **Stage 3, scrolldaddy — moved 2026-09-26** with move script 1.6 (commit 8078cd46),
  before the DNS spec's WP7.
  - `prepare` refused its database port on docker-prod's private address, which its
    `postgres_access.conf` did not declare. `publish 192.168.206.198` was added to that
    file (the original is kept in `/root/rebase/scrolldaddy/`), and `prepare` passed:
    143 tables, one extra role (`scrolldaddy_reader`), and 2 declared pg_hba lines.
  - The swap took the site down from 00:54:43 to 00:56:52, and 143/143 tables matched.
  - Afterwards the database is published on 192.168.206.198:9087 behind the tagged
    `joinery-declared-db-publish` exemption. The web port moved from every interface to
    127.0.0.1 (B10's last site), and the reader's two pg_hba lines came across.
  - **Both resolvers served throughout** (polled every 20 s): HTTP 200, status ok. Their
    last read held at 00:54:37–39 while the site was down, and was advancing again from
    00:57:37.
  - Gates:
    - pages;
    - 0.8.430 on Ubuntu 26.04.1, PostgreSQL 18 and PHP 8.5.4;
    - 0 manifest failures;
    - `check_status` (job 37754);
    - deploy tier 4/4;
    - a full backup (192.5 MB, database 40.7 MB) and a level-2 verification (3,614
      files, pass).

## Per-site gates (every site, both stages)

- The front page, login and admin dashboard over HTTPS.
- Every table's row count matches `prepare`'s record (Docker sites), or the old
  cluster's (standalone boxes).
- The agent is green on the management node, and a `check_status` job round-trips
  without re-pairing.
- `php tests/run.php deploy` on the node (never the safe tier on a node).
- One fleet backup and one level-2 verification succeed on the new stack.
- For each box's own role: mail flow and rspamd on jeremytunnell, both DNS resolvers on
  scrolldaddy.

## Not in this spec

- **Moving a site to a new server from its backup.** Not ready: a site copy skips
  `storage/` and `config/` and wipes encrypted secrets (`_site_init.sh:413`). A restore
  keeps the target's `secret_box_key`, so secrets sealed under the old key stop opening,
  even though the backup carries it (B4). A cross-node restore over the agent is refused
  by the upload ledger (`backups_remaining_gaps.md` item 2). This move does not need it.
- **Running the rebase through the host agent** instead of a shell. `decommission_site`
  is the model: a host primitive with the approval asked on the site's own page. Only a
  Docker server we cannot reach by shell, born before WP6, would need it. Build it if one
  exists when this stage runs.
- **The docker-prod server's own OS.** It stays on 24.04.

## Open decisions

**D3 — When do the two standalone boxes move?**
- **Wait for Ubuntu to open the upgrade** (`Supported: 1`). Canonical has held it for
  regressions, so the first run of the upgrade path is not ours. Catch: no date, and
  jeremytunnell — the box whose backups shrink most — waits with it.
- **Force it (`-d`) once the clone rehearsal (WP10) passes.** Catch: we run a path Ubuntu
  itself says is not ready, on a box our installers drive with coreutils-heavy bash.
- **Recommendation:** rehearse now, and wait for `Supported: 1` for the real upgrade.

**D4 — Which standalone box goes first?**
- **jeremytunnell first:** the simpler box, and the one whose backups benefit most; the
  relay holds its mail while it is down. Catch: it is your live site.
- **dev first:** we use it all day, so problems surface fast, and the database-incremental
  tests start running. Catch: it is the management node, and it carries four third-party
  package sources.
- **Recommendation:** jeremytunnell first, after the clone rehearsal.

## Evidence (2026-09-24)

- `curl -s https://changelogs.ubuntu.com/meta-release-lts` → `Dist: resolute`,
  `Version: 26.04.1 LTS`, `Supported: 0`. Nodes show "no upgrade offered" because
  `host_report.sh` reads `/var/lib/ubuntu-release-upgrader/release-upgrade-available`,
  which follows that file.
- `mgn_last_host_report` → every reporting node on 24.04.3 or 24.04.4.
- `Dockerfile.base:17` → `FROM ubuntu:24.04`. `install.sh:477` → `BASE_IMAGE_VERSION="1.2"`.
  Job 23394 (test380s install) line 303 → built `joinery-base:1.2`.
- Linode StackScript → `--bare-metal` (`linode_stackscript.sh:434`), and 26.04 only.
- No live code hardcodes PHP 8.3 or PostgreSQL 16. `restart_unit.sh:62`,
  `tune_postgres_memory.sh:160` and the start command (`Dockerfile.template:260`, `:307`)
  find versions by glob.
- The agent survives a reboot: static binary, systemd `Restart=always`,
  `After=postgresql`.
