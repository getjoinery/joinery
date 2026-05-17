# Email Forwarding — Postfix Database Credential Handling

**Status:** Implemented 2026-05-17. (Was: Proposal — Solution C recommended: a
dedicated least-privilege PostgreSQL role for this plugin.)
**Author:** Analysis prepared 2026-05-17.
**Plugin:** `plugins/email_forwarding/` (v1.4.0)
**Relates to:** `specs/implemented/email_forwarding_install_unification.md` — that
spec's Option C (now implemented) introduced the issue this spec addresses.
Solution A below *is* the current implementation.

---

## 1. Problem

`email_forwarding_install_unification` (Option C) made Postfix resolve the
active forwarding-domain list live from the database through a `postfix-pgsql`
map, `/etc/postfix/joinery-domains.cf`. To authenticate that connection the map
file carries the database connection values, **including the password** —
written by `provisioning/render_pgsql_map.php` and locked to `640 root:postfix`.

This is a second on-disk copy of a database credential (the app already holds
it in `config/Globalvars_site.php`). Three concrete costs:

1. **Rotation coupling.** Rotating the database password now also requires
   re-running `install_email.sh` to regenerate the map, or Postfix's domain
   lookups break. Two files must change together and nothing enforces it.
2. **Blast radius.** Verified on this deployment: the platform connects to
   PostgreSQL as the **`postgres` superuser** (`DbConnector` builds its DSN from
   `dbusername` in `Globalvars_site.php`, which is `postgres`). So the map file
   does not hold *a* database password — it holds the **cluster superuser
   password**. Anything that can read a `640 root:postfix` file (root, the
   `postfix` user, or a compromised Postfix) thereby gains full database
   control.
3. **Surface.** One more secret file to protect, on a longer-lived rotation
   cadence than the app config. The implemented spec accepts this as its §7.2;
   this spec asks whether it can be eliminated.

What is **not** the problem. The *domain list* is already fully dynamic — the
pgsql map queries the database on every recipient lookup. And the renderer is
not web-reachable: the Apache vhost rewrites every request to `serve.php`, and
`RouteHelper::serveStaticFile()` explicitly rejects `.php` files. The issue is
narrowly the **authentication credential**, not the data flow or web exposure.

## 1a. Verified environment facts

Gathered 2026-05-17 on the test/dev host so the options below do not rest on
assumptions. **Production images should be re-checked** — see §5.

| Fact | Value |
|---|---|
| PostgreSQL | 16.13 (Ubuntu 24.04) |
| Unix socket dir | `/var/run/postgresql` (listening: `.s.PGSQL.5432`) |
| `pg_hba.conf` | `/etc/postgresql/16/main/pg_hba.conf` |
| hba: local superuser | `local all postgres trust` — root can `psql -U postgres` with no password |
| hba: other local | `local all all md5` |
| hba: TCP | `host all all 127.0.0.1` / `::1` `md5` — the app uses this (password auth) |
| Postfix | 3.8.6-1ubuntu0.1 |
| `mail_owner` | `postfix` — Postfix services, including `proxymap`, run as the `postfix` OS user |
| Lookup types built in | includes `proxy`, `socketmap`, `tcp`, `memcache`; **`pgsql` appears only once `postfix-pgsql` is installed** |
| Chroot (`postconf -M`) | `smtp/inet` = **y**, `rewrite/unix` (trivial-rewrite) = **y**, `proxymap/unix` = **n** |
| Site OS isolation | All sites' PHP runs as one shared `www-data` (single php-fpm pool); there are no per-site OS users |
| pg_hba includes | PostgreSQL 16 supports `include` / `include_dir` in `pg_hba.conf` — confirmed via the `file_name` column of `pg_hba_file_rules` |

The chroot finding **contradicts** `email_forwarding_install_unification` §7.8,
which assumed modern Debian/Ubuntu ship `smtpd`/`trivial-rewrite` un-chrooted.
On this box they are chrooted, so the `proxy:pgsql:` routing (proxymap is the
un-chrooted service that performs the lookup) is the *normal* path here, not a
rare fallback. The implemented `install_email.sh` detects chroot and already
selects `proxy:pgsql:` — so it is functionally correct — but the framing in the
older spec is wrong, and any option below that relies on a filesystem path (a
config file or a Unix socket) inherits this constraint.

## 2. Constraints any solution must respect

- **One site per host, co-located** (inherited from the implemented spec §6.0):
  Postfix and PostgreSQL run on the same machine.
- **The domain list stays live** — no return to a static map regenerated on
  domain change (rejected in the implemented spec).
- **Chrooted lookups.** `smtpd` / `trivial-rewrite` are chrooted here; a map
  that needs a filesystem path (config file or Unix socket) must be reached
  through `proxymap` (un-chrooted, user `postfix`). A plain `tcp:` connection is
  unaffected by chroot.
- **Install runs as root**, and `local all postgres trust` (§1a) means it can
  perform PostgreSQL DDL (`CREATE ROLE`, `GRANT`) locally with no password —
  this enables any option needing a one-time database setup step.

## 3. Cross-cutting improvement (independent of the transport choice)

Whatever transport is chosen, Postfix should **not** authenticate as the
`postgres` superuser. A dedicated, login-capable role with `SELECT` on only
`efd_email_forwarding_domains` (or a view over it) shrinks the blast radius of
§1.2 from "cluster takeover" to "can read the list of forwarding domains."
`install_email.sh` can create it credential-free via the `trust` line (§1a).
This is worth doing on its own and is assumed by Solutions B–E below.

(The app itself continuing to connect as `postgres` — because `update_database`
performs DDL — is a separate, pre-existing concern and is out of scope here.)

## 4. Proposed solutions

### Solution A — Status quo: app credential in the map file

*(the current implementation)*

**Mechanism.** `render_pgsql_map.php` writes `hosts` / `user` / `password` /
`dbname` / `query` into `/etc/postfix/joinery-domains.cf` (`640 root:postfix`).

**Pros**
- Already implemented and working.
- No PostgreSQL role or `pg_hba.conf` change.
- The install step does not need the database to be reachable.

**Cons**
- The credential is duplicated on disk; rotation coupling (§1.1).
- As shipped, that credential is the **superuser** password (§1.2). §3 fixes
  the privilege level, but the *duplication* remains.
- A second secret file to protect and keep in sync.

### Solution B — Peer authentication over the Unix socket

**Mechanism.** Create a PostgreSQL role named `postfix` (matching the OS user
`proxymap` runs as); add `local <dbname> postfix peer` to `pg_hba.conf`. The
pgsql map sets `hosts = /var/run/postgresql`, `user = postfix`, and **no
`password`**, and is wired as `proxy:pgsql:` — required, because proxymap is the
only un-chrooted service that can reach the socket (§2).

**Pros**
- **No credential in any file.** The map file becomes non-secret — nothing to
  rotate, nothing to leak. Eliminates the problem outright.
- The standard PostgreSQL pattern for a co-located service.
- Pairs cleanly with the `proxy:pgsql:` routing `install_email.sh` already
  selects on chrooted hosts.

**Cons**
- `install_email.sh` must edit `pg_hba.conf` and reload PostgreSQL — a new
  system file in its remit, and a reload of a service it does not own.
- Requires the database reachable at install/upgrade time. Acceptable (the DB
  is local and already exists by then) but it reverses the implemented spec
  §6.2 "no DB dependency at install" choice.
- Couples to the OS user name `proxymap` runs as — correct here (`postfix`) but
  must be confirmed per platform, and pinned via `pg_ident.conf` if it differs.
- Exact `postfix-pgsql` syntax for a socket `hosts` value and an omitted
  `password` must be confirmed against `pgsql_table(5)` — see §5.

### Solution C — Dedicated role with its own password — *recommended (§6)*

**Mechanism.** As §3: a dedicated PostgreSQL role with `SELECT` on
`efd_email_forwarding_domains` only. `install_email.sh` generates the role's
password and writes it *solely* into the map file; re-running the installer
issues a fresh `ALTER ROLE ... PASSWORD` and rewrites the map.

**Pros**
- No `pg_hba.conf` change — uses the existing `host ... md5` rule.
- A map-file leak exposes only "can read the forwarding-domain list" —
  practically nothing, versus the cluster superuser today (§1.2).
- With the password living *only* in the map file, there is no on-disk
  duplication and no rotation coupling: the map file is the single copy, and
  re-running the installer is the rotation.

**Cons**
- A credential still exists in a file — eliminated only by peer auth (B). Here
  it is a near-worthless one, but it is still a secret.
- One more PostgreSQL role to exist and to drop on uninstall.

### Solution D — Resolver daemon via `socketmap:` or `tcp:`

**Mechanism.** A small long-running service answers Postfix `tcp:`
(`tcp_table(5)`) or `socketmap:` lookups. It holds the database connection the
normal application way (credential in memory, loaded from `Globalvars`);
Postfix only ever asks it "is this domain active?". A `tcp:127.0.0.1:PORT` map
is unaffected by the Postfix chroot.

**Pros**
- The credential lives only where the app already keeps it — no Postfix-side
  secret at all.
- `tcp:` sidesteps the chroot / socket-path problem entirely (§2).
- The resolver can reuse the real `EmailForwardingDomain` model, so "active
  domain" has exactly one definition — this also removes the duplicated-query
  risk noted in the implemented spec §7.3.

**Cons**
- Introduces a **daemon** the plugin must ship, supervise, restart on boot, and
  monitor. The plugin has no long-running process today (its scheduled task
  runs under the cron runner) — this is the largest operational change of all
  the options.
- A new failure mode: if the resolver is down, inbound mail defers.
- More code to write and secure (a network-listening service that answers
  recipient-domain queries).

### Solution E — `~postfix/.pgpass`

**Mechanism.** Omit `password` from the map file; place it in `~postfix/.pgpass`
(`600 postfix`), which libpq consults for the connecting user.

**Pros**
- The map file itself is no longer a secret.
- Minimal change — no PostgreSQL role or `pg_hba.conf` work.

**Cons**
- The credential is still copied on disk — *moved*, not eliminated; the
  rotation coupling of §1.1 remains.
- Depends on `postfix-pgsql` / libpq honoring `.pgpass` for the lookup process
  and on that process's effective home directory — must be confirmed (§5).
- Marginal: trades one secret file for another.

## 5. Open questions for implementation

1. **Grant ordering.** The role's `GRANT SELECT` needs
   `efd_email_forwarding_domains` to exist; `update_database` creates that
   table after the plugin is activated. `install_email.sh` must run after
   that, or tolerate the table being absent and apply the grant on a re-run.
2. **Re-run idempotency.** `install_email.sh`'s re-run path must (re)create the
   role, (re)apply the grant, and rotate the password cleanly whether or not
   the role already exists.
3. **Production chroot.** §1a found `smtpd` / `trivial-rewrite` chrooted on the
   dev box; confirm on production images, since it decides `pgsql:` vs
   `proxy:pgsql:` (the installer already auto-detects this).
4. **Existing deployments.** Decide how the role is created on already-deployed
   email-forwarding sites — re-running `install_email.sh` is the natural path.

## 6. Recommendation

Adopt **Solution C**, scoped to this plugin. `install_email.sh` creates one
dedicated PostgreSQL role for the Postfix map, granted `SELECT` on
`efd_email_forwarding_domains` only, and the map authenticates as that role
instead of the `postgres` superuser. The verified `local all postgres trust`
rule (§1a) lets the installer run the `CREATE ROLE` / `GRANT` as root with no
password of its own.

The role's password is generated by `install_email.sh` and written **only**
into the map file — never into `Globalvars_site.php` or anywhere else. A re-run
issues a fresh password and rewrites the map, so a re-run *is* the rotation.
The §1.1 duplication and rotation coupling therefore disappear along with the
§1.2 superuser exposure: a leak of the map file becomes "can read the list of
forwarding domains," which is practically nothing.

`render_pgsql_map.php` is adjusted accordingly — it no longer needs the
credentials from `Globalvars_site.php`; the dedicated role and its generated
password come from `install_email.sh` (the exact division of work between the
two is an implementation detail).

**Solutions B and D were considered and rejected as disproportionate.** Peer
auth (B) removes the on-disk secret entirely but requires the platform to take
write access to PostgreSQL's `pg_hba.conf` — a new failure class — and its
machinery only pays off across several DB-backed plugin integrations. A
resolver daemon (D) adds a supervised long-running process the plugin does not
otherwise need. With a single consumer, neither cost is justified. If DB-backed
plugin host integrations multiply later, revisit B together with a managed
`pg_hba` `include_dir` mechanism — out of scope here.
