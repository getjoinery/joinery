# Email Forwarding — Install Path Unification

**Status:** Implemented 2026-05-17. (Was: Proposal — Option C selected; scope fixed to one site per host. §9 decisions all resolved.)
**Author:** Analysis prepared 2026-05-17
**Plugin:** `plugins/email_forwarding/` (v1.4.0)

---

## 1. Background

Email Forwarding needs host-level setup that the database layer knows nothing
about: Postfix must be installed, a pipe transport wired into `master.cf`, and
each forwarding domain registered in Postfix. Today **two independent
mechanisms** try to do this, and they have already diverged:

- **Location A — the Plugin Provisioning system** (added 2026-05-16). Detects
  whether runtime dependencies work and points at `provisioning/install_email.sh`
  as the fix. This is the intended "new mechanism."
- **Location B — the Domains-page generated script** (pre-dates A). The admin
  Domains page silently writes a `setup_email_forwarding.sh` on every page load
  and tells the admin to run it.

The two overlap heavily, configure Postfix differently, and B carries Docker
bugs that A has since had fixed. This spec documents both in full and proposes
collapsing them into one.

> **Already fixed in the current session** (not yet covered by either side of
> the divergence): `install_email.sh` systemd fallback, dynamic `php` binary
> resolution, stale-transport guard, Docker header note; and the
> `inbound_mail_server` probe host changed from `host-gateway` to `127.0.0.1`
> (`plugin.json` 1.3.0 → 1.3.1). Location B still has the unfixed versions of
> the same bugs — see §5.

---

## 2. How email forwarding works end-to-end

Understanding the unification requires the runtime path, because the install
exists only to make this path work.

```
inbound mail ──▶ Postfix :25 ──▶ joinery pipe transport ──▶ email_forwarder.php
                    │                                            │
            virtual_mailbox_domains                      EmailForwarder::processEmail()
            must list the domain                                 │
                                                          alias lookup, DKIM verify,
                                                          rate limit, rewrite headers
                                                                  │
                                                          SmtpMailer ──▶ outbound relay ──▶ destination
```

Postfix configuration the path depends on:

| Setting | Required value | Why |
|---|---|---|
| `virtual_transport` | `joinery` | Routes virtual-domain mail to the pipe. |
| `master.cf` `joinery` service | `pipe` running `php …/email_forwarder.php ${recipient}` | The bridge from Postfix to the plugin. |
| `virtual_mailbox_domains` | every enabled forwarding domain | Postfix only accepts mail for listed domains. **Changes as domains are added.** |
| `mydestination` | must **not** contain a forwarding domain | `mydestination` outranks `virtual_mailbox_domains`; a conflict yields "User unknown in local recipient table". |
| `smtpd_recipient_restrictions` | RBL clients (optional) | Spam filtering. |
| opendkim milter | `smtpd_milters = inet:localhost:8891` (optional) | Outbound DKIM signing. |

`email_forwarder.php` bootstraps Joinery via a relative `__DIR__` path, reads
the master switch, and hands the raw message to `EmailForwarder`. This runtime
code is environment-neutral — it behaves identically in a container or on bare
metal — so it is **out of scope** for unification. Only the *install* differs.

---

## 3. Location A — the Plugin Provisioning system

### 3.1 Core classes (`includes/`)

- **`PluginProvisioning.php`** — reads a `provisioners` array from every active
  plugin's `plugin.json` and runs each declared check live. Nothing is
  persisted. Public surface: `getProvisioners()`, `runChecks()`, `resolveHost()`.
  Two check types:
  - `code` — invokes `Class::method`; convention is a `*Health` class in the
    plugin's `includes/`. Returns normally → `verified`; throws
    `ProvisioningCheckFailed` → `unmet`; any other throwable → `error`.
  - `probe` — opens a TCP connection within a 5 s enforced timeout. Connects →
    `reachable`; refused/timeout → `unmet`.
- **`ProvisioningCheckFailed.php`** — marker exception. A check method catches
  the real acquisition error and rethrows it as this, with a clean message.
- `resolveHost()` — maps the `host-gateway` token to the Docker bridge gateway
  inside a container (via `/proc/net/route`) or `127.0.0.1` on bare metal. It
  decides container-vs-bare-metal by reading the `deployment_environment` flag
  in `Globalvars_site.php` (single source of truth, recorded at install) — not
  a runtime `/.dockerenv` check. See
  `specs/implemented/deployment_environment_flag.md`.

Result states roll up to a per-plugin badge on the admin Plugins page:
`verified` → green "Setup complete", `reachable` → teal, `unmet` → amber
"Needs setup", `error` → red "Check failed".

### 3.2 Entry points

- **`ajax/check_provisioning.php`** — permission 5; runs `runChecks()` and
  returns JSON. The Plugins page fetches this asynchronously after render.
- **`utils/check_provisioning.php`** — CLI equivalent; exits non-zero when
  anything is `unmet` or `error`, so a deploy script can gate on it.
- **`adm/admin_plugins.php`** — renders the rolled-up badge and an expandable
  per-provisioner panel; `admin_plugins_logic.php` only discovers *which*
  plugins declare provisioners (no checks run server-side).

### 3.3 Email Forwarding's declaration

`plugin.json` → `provisioners` (3 entries):

| key | type | check | fix script |
|---|---|---|---|
| `inbound_mail_server` | probe | TCP `127.0.0.1:25` | `provisioning/install_email.sh` |
| `outbound_forwarding_relay` | code | `EmailForwardingHealth::checkForwardingRelay` | — |
| `domain_dns_records` | code | `EmailForwardingHealth::checkDomainDns` | — |

`EmailForwardingHealth.php` (`includes/`): `checkForwardingRelay()` calls the
shared `EmailForwarder::createMailer()`, connects with a 5 s timeout, and closes
— it verifies relay *acquisition*, sends nothing. `checkDomainDns()` confirms
MX + SPF for every enabled, non-deleted domain.

### 3.4 `provisioning/install_email.sh`

The fix script for `inbound_mail_server`. Idempotent, apt-based, must run as
root. Applies the **fixed, deployment-independent** base config only:

- Installs `postfix opendkim opendkim-tools`.
- `master.cf`: appends the `joinery` pipe transport once.
- `main.cf`: `virtual_transport = joinery`; `mydestination = localhost,
  localhost.localdomain`.
- Opens port 25 if `ufw` is active.
- `postfix check`, then reload/start.

It deliberately **does not** touch `virtual_mailbox_domains`, DNS records,
per-domain DKIM keys, RBL restrictions, or opendkim milter wiring — its header
documents these as "genuinely per-deployment, handled elsewhere."

---

## 4. Location B — the Domains-page generated script

### 4.1 Where it lives

`admin/admin_email_forwarding_domains.php`, lines ~416–524. On **every page
load** the page builds a shell script as a string and writes it to
`plugins/email_forwarding/setup_email_forwarding.sh` (`file_put_contents` +
`chmod 0755`). The script content varies with detected server state and the
current domain list.

### 4.2 What the generated script does

A superset of `install_email.sh`:

- Installs Postfix via `debconf-set-selections` + `apt install`.
- `postconf -e` for `virtual_transport`, **`virtual_mailbox_domains`** (built
  from the live DB domain list), **`smtpd_recipient_restrictions`** (RBL), and
  `mydestination`.
- Appends the `joinery` pipe transport to `master.cf`.
- Installs opendkim and wires the **milter** (`smtpd_milters` etc.).
- **Generates per-domain DKIM keys** (`opendkim-genkey`) for any domain without
  one.
- `postfix reload`, `systemctl restart opendkim`, `ufw allow 25`.

### 4.3 How it is surfaced

The Domains page references the generated script in four places: the Server
Status panel (missing components), the per-domain `mydestination` conflict row,
the per-domain "Setup Required" block, and the stale-config warning ("Active
domain not in Postfix → re-run the setup script").

It also performs useful **detection** that should be preserved: Postfix /
transport / opendkim status, per-domain MX/SPF/DKIM badges, `virtual_mailbox_
domains` membership, and `mydestination` conflict.

---

## 5. Problems

### 5.1 Divergence — two sources of truth

A site set up via A and a site set up via B end up with **different Postfix
configurations** (B adds RBL rules, the milter, and the domain list; A does
not). The append-once `master.cf` guards differ, so running both can interact
unpredictably.

### 5.2 Docker bugs in Location B (unfixed)

The generated script repeats every bug already fixed in `install_email.sh`:

- `argv=/usr/bin/php` hard-coded — wrong on official `php:*` images
  (`/usr/local/bin/php`); the pipe transport then fails silently.
- `set -e` at the top, then **unguarded** `postfix reload` (×3),
  `systemctl restart opendkim`, and `ufw allow 25` — each aborts the whole
  script in a container with no systemd or no ufw.

### 5.3 Regenerated on every page load

`file_put_contents` runs on every Domains-page view — a side effect inside a
GET render, and the on-disk script silently changes under the admin's feet.

### 5.4 The `virtual_mailbox_domains` gap

`install_email.sh` does not register domains, by design. If Location B is
removed, **nothing** updates `virtual_mailbox_domains` when a domain is added.
This is the one capability that genuinely must survive unification — see §6.2.

### 5.5 Multi-site host topology — explicitly out of scope

`overview.md` also documents a Docker multi-container topology: a host
front-relay demultiplexing inbound mail to per-container Postfix instances. An
operator may still run that, but per §6.0 it is **manual configuration the
installer does not perform** — not a gap to be closed here.

---

## 6. Unification proposal — Option C

**Decision (2026-05-17): Option C — Postfix reads the database directly.**

Host configuration splits by its *nature*, not by which script is convenient:

- **Fixed, deployment-independent config** → `install_email.sh`, run once on
  install/upgrade. This includes writing a Postfix pgsql *map file* — the
  connection string is fixed even though the domains it returns are not.
- **The forwarding-domain list** → not "installed" at all. Postfix resolves
  `virtual_mailbox_domains` against the database live, so adding or removing a
  domain in the admin UI takes effect immediately with no host action.
- **Per-domain DKIM keys** → a genuinely separate per-domain concern (real key
  files on disk, a DNS record); see §6.3.

Unlike the rejected domain-aware-installer approach, Option C keeps
`install_email.sh` true to its original "fixed config only" charter — its
header does not need reversing. The source of truth for domains stays the
database; the host is made to *read* it rather than have a script re-push it.

### 6.0 Scope — one site per host (executive decision, 2026-05-17)

The installer assumes **one Joinery site per IP / host**. That host may be a
Docker container or bare metal — the installer neither cares nor needs to know.
Under this assumption the site's Postfix owns the host's port 25 outright, is
co-located with the app, and reads the app's own database. There is no front
relay, no per-domain host routing, and no cross-container coordination.

More complex topologies — several sites behind one IP, a host front-relay
demultiplexing to per-container Postfix instances — remain possible but are
**manual, operator-level configuration**. The installer does not attempt them
and this spec does not cover them. Every decision below follows from the
one-site-per-host assumption.

### 6.1 `install_email.sh` — fixed config, run once

| Step | Source | Notes |
|---|---|---|
| Install `postfix postfix-pgsql opendkim opendkim-tools` | A + new pkg | `postfix-pgsql` is the new dependency |
| `master.cf` `joinery` pipe transport | already in A | dynamic `php` path, stale guard |
| `virtual_transport = joinery`, safe `mydestination` | already in A | |
| `inet_interfaces = all` | new | fixed config — the site is the host's mail server |
| `ufw allow 25` (if active) | already in A | guarded |
| `smtpd_recipient_restrictions` (RBL clients) | from B | fixed config |
| opendkim static config — inet socket `localhost:8891`, empty key/signing/trusted tables | new | §6.3; opendkim runs keyless until a domain is added |
| Postfix milter wiring — `smtpd_milters`/`non_smtpd_milters = inet:localhost:8891`, `milter_default_action = accept` | from B | `accept` ensures a keyless or down opendkim never blocks mail |
| Write `/etc/postfix/joinery-domains.cf` (pgsql map) | new | §6.2; `chmod 640 root:postfix` |
| `virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf` | new | §6.2 |

Every line above is fixed config. The installer reads the database
*credentials* from `config/Globalvars_site.php` (via a small PHP helper) to
render the map file — it does **not** query the database, so it does not
depend on the database being up at install time.

### 6.2 The pgsql map — Postfix reads the domain list live

`install_email.sh` writes `/etc/postfix/joinery-domains.cf`:

```
hosts    = <db host from Globalvars_site.php>
user     = <db user>
password = <db password>
dbname   = <db name>
query    = SELECT efd_domain FROM efd_email_forwarding_domains
           WHERE lower(efd_domain) = '%s'
             AND efd_is_enabled = true
             AND efd_delete_time IS NULL
```

`main.cf` then carries `virtual_mailbox_domains =
pgsql:/etc/postfix/joinery-domains.cf`. For every inbound recipient, Postfix
asks the database whether that domain is an active forwarding domain. Adding,
removing, enabling, or disabling a domain in the admin UI is immediately
effective — no SSH, no root, no re-run, and no drift is possible.

A small helper, `provisioning/render_pgsql_map.php`, prints the map-file
content. It is a standalone CLI script (like `email_forwarder.php`): it reads
the four connection values directly from `config/Globalvars_site.php` and does
**not** bootstrap the full Joinery framework. The installer runs it as root, and
a full framework bootstrap as root risks creating root-owned cache or log files
that later break the `www-data` web process — reading the config file directly
avoids that entirely. `install_email.sh` redirects its output straight into the
map file and locks it down (`chmod 640`, owner `root`, group `postfix`) — the
credentials are never echoed. The `query` is the single definition of "active
forwarding domain" and must stay aligned with the `EmailForwardingDomain` model
(§7.3).

### 6.3 opendkim — static config installed, keys per-domain

opendkim setup splits the same way the rest of the install does — by nature,
not by convenience:

- **Static config → `install_email.sh`.** The installer configures opendkim's
  deployment-independent parts once: the inet socket on `localhost:8891` (so the
  Postfix milter wiring in §6.1 has something to talk to), empty `key.table` /
  `signing.table` / `trusted.hosts`, and `opendkim.conf`. opendkim then runs
  from first install — keyless, signing nothing — and `milter_default_action =
  accept` guarantees that a keyless or down opendkim never blocks or defers mail.
- **Per-domain keys → manual, per domain.** A key pair cannot be a database
  lookup — opendkim needs an RSA key file on disk and a published DNS record.
  With the static config already in place, adding DKIM for a domain shrinks to:
  `opendkim-genkey`, two lines into `key.table` / `signing.table`, and the DNS
  TXT record. The Domains-page edit view already shows these steps per domain.

§9 records the decision not to add a provisioning check for missing DKIM keys —
a domain forwards correctly with no key; only outbound signing is affected.

### 6.4 Domains page changes

- Delete the generator block (`admin_email_forwarding_domains.php`, lines
  ~416–524); stop writing `setup_email_forwarding.sh`; delete the file.
- **Keep** the detection: Postfix/opendkim status, per-domain MX/SPF/DKIM
  badges, `mydestination` conflict.
- The per-domain "in Postfix" badge is **removed**. Under Option C the database
  *is* Postfix's domain source, so an enabled, non-deleted domain shown on the
  page is by definition registered — a per-domain badge would be tautological.
  (`postmap -q` cannot stand in for it: the page runs as `www-data`, and the map
  file is `chmod 640 root:postfix`, unreadable to the web process — see §7.2.)
  What can still be wrong is the *plumbing*, so the Server Status panel gains one
  host-level check: `postconf -h virtual_mailbox_domains` (no credentials,
  world-readable) must report the `pgsql:/etc/postfix/joinery-domains.cf` map.
  The "stale config" banner is removed; per-domain staleness is no longer
  possible.
- Repoint the script references to `sudo bash …/provisioning/install_email.sh`
  for the base install.

### 6.5 Provisioning checks

The `checkDomainsRegistered` drift check considered earlier is **not needed** —
there is no drift to detect. Optionally a lightweight `code` check could
confirm `virtual_mailbox_domains` is wired to the pgsql map and a sample lookup
succeeds; this overlaps with the Domains-page live lookup, and §9 records the
decision to skip it. The three existing provisioners are unchanged.

### 6.6 Documentation changes (at implementation time)

- `plugins/email_forwarding/docs/overview.md` — single `install_email.sh`
  flow; the pgsql-map model (domains are live, never "installed"); per-domain
  DKIM keys (`opendkim-genkey` + table lines + DNS record) as the one manual
  per-domain step, with opendkim's static config now handled by
  `install_email.sh`; drop "run the setup script";
  relabel the multi-container topology section as advanced, manual, and outside
  the installer's scope (§6.0).
- `docs/plugin_developer_guide.md` — note `127.0.0.1` vs `host-gateway` for a
  co-located service.
- `install_email.sh` header — extend the "what it configures" list with the
  pgsql map; its "fixed config only" charter is unchanged.

---

## 7. Edge cases & issues under Option C

Option C dissolves most of the edge cases the domain-aware-installer approach
would have introduced (no-domains-yet, drift, clobbering manual edits, disabled
domains, concurrent runs) — because the domain list is no longer install
state. What genuinely remains:

### 7.1 No domains configured yet

Still worth stating, since it was the original concern: the first install has
zero forwarding domains. Under Option C this needs **no special handling** —
the pgsql query simply matches nothing, Postfix accepts mail for no virtual
domains yet, and the moment a domain is added in the UI it starts matching.
The installer has nothing domain-related to do or report.

### 7.2 DB credentials in a Postfix-readable file

`/etc/postfix/joinery-domains.cf` contains the database password. *Mitigation:*
`chmod 640`, owner `root`, group `postfix` — readable by Postfix, not
world-readable. These are the same credentials the application already holds
in `config/Globalvars_site.php`; the map file is a second copy, so a credential
rotation now also means re-running `install_email.sh` to regenerate it (a rare,
fixed-config re-run).

### 7.3 The query is a second definition of "active domain"

The pgsql `query` encodes "enabled and not deleted." If the
`EmailForwardingDomain` model later changes what makes a domain active, the
query must change with it. *Mitigation:* keep the query in exactly one place
(written by `render_pgsql_map.php`) and cross-reference this section from both
the helper and the model. Column names (`efd_domain`, `efd_is_enabled`,
`efd_delete_time`) were verified against the model on 2026-05-17. The query uses
`lower(efd_domain)` rather than a bare `=`: the model lowercases `efd_domain` on
save and Postfix folds the recipient domain to lowercase before lookup, but
`lower()` on the column side also covers any row written by a path that bypassed
`prepare()` (see the memory note that `prepare()` is not guaranteed before
`save()`).

### 7.4 Postfix RCPT decisions are coupled to database availability

With a live lookup, Postfix asks the database at RCPT time whether a domain is
accepted. If the database is down, the pgsql lookup fails and Postfix returns a
**temporary** error (4xx) — the sending server retries later. Mail is
**deferred, not lost**. This is the real cost of Option C: with a static
`virtual_mailbox_domains` a DB outage would not affect SMTP acceptance at all.
Acceptable, but must be documented.

### 7.5 `postfix-pgsql` must be available

The pgsql map type needs the `postfix-pgsql` package. It is in the apt
repositories; `install_email.sh` already restricts itself to apt-based systems
and will install it. Non-apt systems remain a documented manual path.

### 7.6 Reachability

With one site per host, Postfix is co-located with the app and reaches the same
database the app does; the pgsql map's `hosts` value is whatever
`Globalvars_site.php` already uses. No new networking — container or bare
metal — is introduced.

### 7.7 DKIM keys remain a separate manual step

§6.3 — not solved by this work, by design. Honest gap: a domain added through
the UI is fully functional for *forwarding* immediately, but outbound DKIM
signing for it still needs a manual `opendkim-genkey` + DNS record. The Domains
page surfaces this; §9 records the decision to keep it there rather than add a
provisioning check.

### 7.8 Chrooted Postfix services

Postfix resolves `virtual_mailbox_domains` in `smtpd` / `trivial-rewrite`. If
those services run chrooted (the `master.cf` chroot column is `y`), the map-file
path is interpreted relative to `/var/spool/postfix` and the lookup fails.
Modern Debian / Ubuntu ship these services un-chrooted (`-` / `n`) by default,
so the bare `pgsql:` map works as written. *Mitigation:* `install_email.sh`
inspects the chroot column for the relevant services; if any is chrooted it
wires the map as `proxy:pgsql:/etc/postfix/joinery-domains.cf` instead
(`proxymap` runs un-chrooted and reads the file on their behalf). No
user-visible difference; purely an installer detail.

---

## 8. Migration / rollout

- `install_email.sh` stays idempotent. Re-running it on an existing site
  installs `postfix-pgsql`, writes the map file, and switches
  `virtual_mailbox_domains` from a static list to the pgsql map — a one-time
  config change applied by the re-run. The previous static list is replaced.
- Sites set up via the old generated script keep working until the re-run;
  afterwards their domain list is served live from the DB.
- No database or schema changes — the pgsql query reads existing columns on
  `efd_email_forwarding_domains`.
- Once the §6.4 changes ship, `setup_email_forwarding.sh` is no longer
  regenerated, but a stale copy lingers in the plugin directory on
  already-deployed sites. `install_email.sh` deletes it if present, so no
  orphaned, bug-carrying script is left behind.

---

## 9. Resolved decisions

All earlier open questions are resolved; the spec is implementation-ready.

1. **No DKIM provisioning check.** A missing DKIM key is not a dependency
   failure — forwarding works without outbound signing — so flagging it would
   push the plugin to a false amber "Needs setup". DKIM stays surfaced
   per-domain on the Domains page, consistent with `checkDomainDns` already
   excluding it. (§6.3, §7.7)
2. **`inet_interfaces = all` is fixed config and goes in the installer;
   `mynetworks` is never touched.** With one site per host (§6.0) the site's
   Postfix *is* the host's mail server, so it listens on all interfaces.
   `mynetworks` only matters for the multi-site front-relay topology that §6.0
   places out of scope. (§6.1)
3. **No separate pgsql-map-wired check.** The `inbound_mail_server` probe
   already confirms Postfix is up, and the Domains page Server Status panel runs
   a `postconf -h virtual_mailbox_domains` plumbing check — a third provisioning
   check would be redundant. (§6.5)
4. **opendkim static config is fixed config and goes in the installer.**
   `install_email.sh` configures opendkim's inet socket and empty tables and
   wires the Postfix milter with `milter_default_action = accept`. opendkim runs
   keyless from first install; the per-domain DKIM step is reduced to a key plus
   two table lines plus a DNS record. The alternative — deferring all opendkim
   config to the per-domain step — was rejected because it pushes fiddly,
   identical plumbing into every domain setup, against the one-run-installer
   goal. (§6.1, §6.3)
