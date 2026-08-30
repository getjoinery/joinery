# Environment Build Surface Reduction — four removals from the image and the install

**Status: DESIGN SETTLED / NOT BUILT.** Written 2026-08-29 after a review of
`install.sh`, `Dockerfile.base`, `Dockerfile.template`, `fix_permissions.sh` and
the two vhost templates, asking one question: what can be *deleted* from a
Joinery environment build rather than added to it.

Everything below is independent of every other item, and none of it changes how
the platform behaves for a user. They are ordered by value, not by effort.

## What this is about, in plain terms

A running Joinery site includes a lot of software that has nothing to do with
serving the site: a compiler, a version-control client, a package manager, a
mail server on installs that never receive mail, and a web-server feature that
looks for configuration files that do not exist. Each of those is something an
attacker who gets a foothold can use, and something that has to be patched
forever. Separately, the account that runs the site's PHP can rewrite the site's
own program files, and each site container answers directly on the public
internet on a second port nobody meant to open.

None of these is a break-in on its own. Together they decide how much damage the
first real bug does.

## Explicitly out of scope

**Splitting the single container.** `Dockerfile.template`'s CMD starts
PostgreSQL, cron, php-fpm, Postfix, opendkim and Apache in one namespace as
root, and that remains the largest single surface item in the build. The owner
has decided not to pursue it. Every work package below is deliberately chosen to
stand alone, so none of them assumes or requires that split later.

**Replacing Apache with nginx or Caddy.** Considered and declined, recorded here
so it is not re-opened by default. The container loads 30 modules and none of
the ones carrying Apache's ugly history are among them — no `mod_cgi`,
`mod_lua`, `mod_proxy_ajp`, `mod_http2`, `mod_session`. What is loaded is core
plus `rewrite`, `proxy_fcgi`, `ssl`, `headers`. nginx's advisory record over the
same window is not meaningfully shorter, so the swap is a lateral move measured
in security.

The one real argument for Caddy is that automatic TLS would delete code *we
own*: certbot, `python3-certbot-apache`, `provision_origin_cert`, and the
`<IfFile /etc/letsencrypt/live/{{DOMAIN_NAME}}/fullchain.pem>` guard that
appears four times across `default_virtualhost.conf` and
`default_proxy_vhost.conf`. That is the right way to measure a swap — trust
boundaries and bespoke code removed, not daemon size — and by that measure it is
a modest win that does not pay for the migration today. If it is ever
reconsidered, it should be reconsidered for that reason and no other.

## The thread connecting WP1 and WP3

Both work packages are the same fact seen twice: **the web user can install and
rewrite software.**

- `fix_permissions.sh` (v3.0) sets *everything* under the site root to
  `www-data:user1` mode 770 — the whole code tree included. PHP runs as
  www-data, so a file-write bug is permanent webshell persistence, and the
  publish/upgrade integrity guards are protecting a tree the web process can
  edit underneath them.
- `PluginManager.php:746` calls `ComposerValidator::reconcilePluginPackages()`
  during plugin activation — a package manager resolving and fetching
  dependencies, from a web request, as www-data.
- `AbstractExtensionManager::installFromZip()` / `installFromTarGz()` unpack an
  archive to a temp dir and `rename()` it into `plugins/<name>` or
  `theme/<name>` — new executable PHP arriving in the served tree, from a web
  request, as www-data.

WP1 closes the first. WP3 removes the tooling behind the second. The third is
what makes both of them harder than they look, and is handled in WP1 Phase B.

---

## WP1 — The code tree stops being writable by the web user

**Value: highest here. Effort: moderate, and split into two phases so the large
part of the win lands without the design work.**

### What happens today

`fix_permissions.sh` v3.0 walks the whole site root and sets
`www-data:user1` / 770 on every file and directory that differs, with an
explicit `PINNED` list of five secret files pruned from the sweep and re-tightened
afterwards. `Dockerfile.template`'s CMD does the same thing on every container
start with `chown -R www-data:www-data` over `public_html`,
`maintenance_scripts` and `vendor`, then 755/644.

So the account serving the site owns the program the site runs.

### What we get for free

The writable data already lives *outside* `public_html`. The `docker run`
volume list (`install.sh:3714-3728`) mounts `uploads`, `storage`, `config`,
`backups`, `static_files`, `logs` and `cache` as siblings of `public_html`, not
children of it. The boundary this work package needs is therefore already a
directory boundary, not a scattering of exceptions.

### The change

`fix_permissions.sh` becomes the single owner of a two-zone model, using the
`PINNED`/`PRUNE` mechanism it already has:

- **Code zone** — `public_html/`, `maintenance_scripts/`, `vendor/`: owned
  `user1:www-data`, dirs 750, files 640. The web user reads through the group
  and cannot write. `user1` keeps full access, so nothing about developer
  workflow or the CLI upgrade path changes.
- **Data zone** — `uploads/`, `storage/`, `cache/`, `logs/`, `config/`,
  `backups/`, `static_files/`: unchanged, `www-data:user1` 770.
- **Pinned files** — unchanged. The five existing pins keep their exact
  ownership and modes; this work package must not quietly retighten
  `provisioning_key` or `backup_site_key` as a side effect, for the reason
  already written into the script's own comments.

`Dockerfile.template`'s start-time `chown -R` is replaced by a call to
`fix_permissions.sh`, so the model has one definition instead of two that can
drift. Bump the template to VERSION 4.9 and `fix_permissions.sh` to 3.1.

### Phase A — core code read-only, `plugins/` and `theme/` still writable

Everything in the code zone above, except `public_html/plugins/` and
`public_html/theme/`, which stay in the data zone for now.

This alone removes the ability to overwrite `serve.php`, `includes/`, `data/`,
`logic/`, `views/`, `api/`, `adm/` and `utils/` from a web-triggered write. It
does *not* stop an attacker with arbitrary file write from dropping executable
PHP into `plugins/` — a webshell there runs just as well. What it does buy is
that the platform's own code becomes tamper-evident and the publish/upgrade
integrity guards start guarding something the web process cannot reach.

Phase A is a permissions change and nothing else. No PHP changes.

### Phase B — extension installation moves to a privileged path

The end state is the whole code zone read-only, which requires
`installFromZip()` / `installFromTarGz()` to stop being the thing that writes
into the served tree. The mechanism should mirror what `upgrade.php` already
does: a root-run helper script invoked over a narrowly scoped sudoers rule.

Constraints that shape it:

- Only `user1` currently has NOPASSWD sudo (`install.sh:513`). www-data has
  none, and **that must remain true in general** — the new rule grants exactly
  one command, not `ALL`.
- The helper must live in a directory www-data cannot write, or the rule grants
  arbitrary root. With `maintenance_scripts/` in the code zone, that holds.
- The helper takes a *validated, already-unpacked* staging directory and a
  destination name, re-validates the manifest itself rather than trusting the
  caller, and refuses any destination name that is not a plain slug.
- In Docker the CLI process is already root, so the helper is called directly
  there; on bare metal it is `sudo -n`. `upgrade.php:1153` already contains this
  exact `posix_geteuid()` check and `$root_prefix` pattern — reuse it, do not
  write a second one.

### Why a PHP-driven upgrade still works under this

The obvious objection — the thing that performs an upgrade is itself a PHP file
in the tree being made read-only — does not bite, for two independent reasons.

**Executing a PHP file does not require write permission on it.** Mode 640 owned
`user1:www-data` is readable by php-fpm through the group. Read-only code is
still running code.

**The upgrade never runs as the web user.** Both transports run as root:

- SSH — `JobCommandBuilder::build_apply_update()` composes
  `cd {web_root} && php utils/upgrade.php --verbose`
  (`JobCommandBuilder.php:1874`), which its own comment describes as sent to be
  run as root.
- Agent primitive — the agent is a root systemd service. `install_agent.sh:190`
  refuses to install unless `id -u` is 0, and `:351-352` register `@reboot root`
  plus a per-minute `root` cron supervisor.

This is also why the code zone is owned `user1:www-data` and not `root:www-data`.
On bare metal `upgrade.php` runs as the deploy account and prefixes `sudo -n`
only on specific steps (`upgrade.php:1153`); the main staged-to-live `mv` runs as
the process user. Keeping `user1` as owner leaves that path working unchanged.
`fix_permissions.sh` already hardcodes `user1` as the group on every node, so
this model uses the same two principals the fleet has always used — it only
moves which one holds the write bit.

**And `fix_permissions.sh` is why the change survives.** `upgrade.php:1227` ends
every successful deploy by calling `fix_permissions.sh --production`. That makes
the script the single point where the two-zone model is re-established after
every upgrade, and is the reason WP1 belongs there rather than in
`Dockerfile.template`. Implemented anywhere else, the next upgrade silently
reverts it.

The www-data writers listed at the top of this spec remain the entire problem.
Nothing in the upgrade machinery is among them.

### Constraint discovered: the web-triggered upgrade path

`utils/upgrade.php:282` aborts if `$live_directory` is not writable by the
current process, and the non-CLI branch gates on `check_permission(8)` — i.e.
there is a web-triggered upgrade that runs as www-data. Under WP1 that path
fails at the writability check.

No route to it was found in `serve.php` or `adm/`, and the documented procedures
are the Server Manager dashboard and `php utils/upgrade.php --verbose` at the
CLI, both of which are unaffected. **Confirm this before Phase A ships**, and if
the web path is genuinely dead, delete the branch rather than leaving a
permission-8 entry point that can now only fail.

### Acceptance

- After a fresh install and after an upgrade, `find public_html -user www-data`
  returns nothing.
- A PHP script running as www-data cannot write to `public_html/serve.php`,
  `includes/`, or `utils/`.
- Uploads, page caching (`cache/static_pages`), logging, backups and the
  scheduled-task runner all still work — the last one matters because the cron
  entry runs as www-data.
- `php tests/run.php db` is green.
- Phase B only: installing a plugin from a ZIP through the admin UI still works,
  and the sudoers rule names exactly one script.

---

### Correction: what WP1 is worth on the management node

Measured 2026-08-30. On the **management node specifically**, WP1's security
benefit is smaller than the section above implies. The root `joinery-agent`
executes unsigned `bash -c` from `mjb_management_jobs` (`db.go:189` ->
`runner.go:291`), a table the web application writes as its normal dispatch
path. So www-data already reaches root there without touching a file.

WP1 is still worth doing on that box — it removes a persistence and
tamper-evidence problem, and defence in depth is the point — but it is not what
stops a web compromise becoming root. That is
`agent_local_queue_retirement.md`.

On every other node (no server_manager, no job queue) WP1 carries its full
stated value.

## WP2 — Container HTTP ports stop listening on the public internet

**Value: high relative to effort. Effort: one line, plus verification.**

### What happens today

`install.sh:3712` and `install.sh:3735` (the quiet and non-quiet `docker run`
branches) publish the container's web port as:

```
-p "$PORT":80
```

which binds `0.0.0.0`. The line directly below it gets this right for the
database:

```
-p 127.0.0.1:"$DB_PORT":5432
```

and `do_install_docker` adds a `DOCKER-USER` DROP for the Postgres range
9080-9099 on the public interface (`install.sh:1566-1573`), specifically because
UFW cannot see through Docker's DNAT. **Nothing covers the web range**, which
`find_available_port` allocates from 8080-8180 (`install.sh:3395,3420`).

### Why it matters

The host proxy only ever talks to `127.0.0.1:{{PORT}}` — both vhost templates
say so. So the public bind serves no purpose, and it has two consequences:

1. The container's Apache answers directly on the public IP, bypassing the host
   vhost entirely: no TLS, no canonical-host redirect, no host-level protection.
2. The proxy's `RequestHeader set X-Forwarded-For %{REMOTE_ADDR}s` never runs on
   that path, while the container trusts `172.17.0.0/16` via
   `RemoteIPInternalProxy` (`Dockerfile.template`, mod_remoteip block). A direct
   connection can therefore assert any client IP it likes — poisoned access
   logs, wrong `%a` in every log line, spoofed analytics, and anything
   downstream that reasons about client IP.

### The change

```
-p 127.0.0.1:"$PORT":80
```

in both branches. Optionally extend the existing `DOCKER-USER` rule to
8080-8180 as belt-and-braces; the bind change is the actual fix and the iptables
rule is only insurance against a future `-p` regression.

### Before shipping

Confirm no deployment reaches a container's web port from off-box. The expected
topology is Cloudflare → host Apache → `127.0.0.1:PORT`, which is unaffected.
A node where the TLS terminator or proxy runs on a *different* host would break,
and the relay and beta-node topologies should each be checked against
`reference_relay_topology_lives_on_the_deployment` before this lands fleet-wide.

### Acceptance

- `docker port <site>` shows the web port bound to 127.0.0.1.
- `curl http://<public-ip>:8080/` from off-box is refused.
- The site still serves normally through its domain.
- A request carrying a forged `X-Forwarded-For` reaches the access log with the
  real client IP.

---

## WP3 — The build toolchain leaves the runtime image

**Value: solid. Effort: low for the toolchain, blocked for composer.**

### What happens today

`install.sh:2025` installs `build-essential` and `git` into the base image, and
`Dockerfile.template` runs `composer install` at build and leaves Composer in
place. All three are present in every running container. A compiler and a
package manager on a production box are the classic post-exploitation
convenience.

### What can go now

**`build-essential` and `git`.** Neither is used at runtime: no PHP in
`includes/`, `utils/`, `plugins/` or `adm/` shells out to `git`, and
`composer install --no-dev` resolves from packagist dist archives with no VCS
repositories declared. Remove both from the essential-packages line and confirm
the base image still builds — some PECL builds want a compiler, so if a future
extension needs one, it belongs in a builder stage, not the runtime.

The clean form is a multi-stage build: resolve dependencies in a builder stage
that has the toolchain, `COPY --from=builder` the `vendor/` tree into a runtime
stage that has none.

### What cannot go yet: Composer

Plugin activation calls `ComposerValidator::reconcilePluginPackages()`
(`PluginManager.php:746-749`) and *refuses activation* when the reconcile fails.
So Composer is a runtime dependency of the web application, not just a build
tool, and removing it silently breaks every plugin activation that declares
`requires.composer`.

Removing it properly means plugin dependencies are resolved when a plugin is
*published*, not when it is *activated* — the plugin archive carries its
resolved packages, and activation verifies rather than fetches. That is a real
design change with a marketplace consequence, and it belongs in its own spec.
Recorded here as the reason Composer stays for now, so nobody deletes it and
discovers this from a support ticket.

Note that `_install_declared_dependencies.sh` running `apt-get` at container
start is *not* in this category and stays: it runs as root at start time, never
from a web request.

### Acceptance

- `which gcc git` in a running container returns nothing.
- The base image builds and a fresh site installs.
- Plugin activation still succeeds for a plugin declaring composer packages.

---

## WP4 — The mail stack leaves the base image

**Value: real on installs that never receive mail. Effort: low, using machinery
that already exists.**

### What happens today

`Dockerfile.base` VERSION 1.1 bakes `postfix`, `postfix-pgsql`, `opendkim` and
`opendkim-tools` into the shared base (`install.sh:2248`), so every site gets a
mail transfer agent and a key-holding signing daemon whether or not the mailbox
plugin is ever activated. The header comment says this was deliberate — the
stack has to survive container rebuilds because there is no systemd in the
container.

### Why it can change now

The generic plugin host-installer mechanism landed after that decision.
`_plugin_installers_start.sh` runs *every active plugin's* declared
`host_installer` on every container start — which is exactly the "survives a
rebuild" property the base-image bake was buying. The mailbox plugin already
declares one.

### The change

Move the four packages out of `install.sh do_server_setup` and into the mailbox
plugin's declared host installer, so they are installed on activation and
re-asserted at start. Bump the base image to 1.3 and update the
`BASE_IMAGE_VERSION` default in `Dockerfile.template` in the same change — the
two disagreeing is how a site ends up on a base that no longer matches
`install.sh` (the `joinery.install_sh_hash` label exists to catch precisely that
drift, and should be watched here).

### Constraint

The install can no longer assume apt reachability at *activation* time the way
it can at image-build time. `_install_declared_dependencies.sh` already handles
this shape — a site that cannot reach apt must still start — and the plugin
activation gate is the runtime backstop. Follow that precedent rather than
making activation hard-fail on a network error.

### Acceptance

- A fresh site with the mailbox plugin inactive has no `postfix` or `opendkim`
  binary and no process listening on 25.
- Activating the mailbox plugin installs and starts them.
- `docker restart` on that site brings them back.
- The existing inbound-mail path on `dev.getjoinery.com` is unchanged.

---

## WP5 — Small removals

Each of these is small enough that it does not deserve its own work package, and
none of them should be bundled into an unrelated change either.

### `AllowOverride All` → `AllowOverride None`

Both `<Directory>` blocks in `default_virtualhost.conf` (v2.03) set
`AllowOverride All`, and **there is not a single `.htaccess` file anywhere in
the tree** — the count is zero including `vendor/`. So Apache performs a
per-request directory walk looking for files that do not exist, and write access
to the webroot is also Apache-configuration access. Set `None` in both blocks;
bump the file to 2.04.

Worth doing *after* WP1 Phase A, so the two changes to the webroot's trust model
land in a sensible order and a rewrite regression is attributable to one of them.

### Remove `php-soap`

`soap` is in the `PHP_EXTENSIONS` list at `install.sh:2085`. No `SoapClient` or
`SoapServer` reference exists anywhere in the application or in `vendor/`. Drop
it from the list and from `REQUIRED_MODULES` in the same edit — leaving it in
the verification list turns a removal into a failed install.

`sqlite3` is *not* a candidate: the mailbox search index uses it
(`MailboxIndex.php`, `inbound_mailbox_search_index_class.php`). `gd` and `intl`
are in use. Do not extend this beyond `soap` without the same check.

### Base image: `ubuntu:24.04` → a slim base

Optional, and honestly labelled. Moving to `debian:*-slim` will noticeably
reduce the CVE count a scanner reports, and will reduce actual reachable risk
very little, because the packages it drops are ones nothing in the container
ever executes. Worth doing to quiet audit noise; not worth counting as security
work, and not worth doing at the same time as WP4 (both change base image
contents, and a failure should be attributable to one).

---

## WP6 — The terminal rung: a read-only code mount

**Not scheduled. Recorded because it is where WP1 leads, and because the four
writers below are worth knowing about before any of them is touched.**

### There is no tighter permission than WP1's

640 is the floor. Dropping to 440 buys nothing, because root ignores mode bits;
dropping to 600 breaks php-fpm's read through the group. The dial has no notch
left.

Worse, discretionary permissions have a hole no mode bit closes: the serving
container's process tree runs as root, and Docker's default capability set
includes `DAC_OVERRIDE` — in-container root bypasses file permissions by design.
WP1 stops **www-data** from writing code, which is the realistic attack path and
worth having. It does nothing about anything that reaches root inside the
container.

### What is above it

Mount the code volume `:ro` in the serving container. Kernel-enforced rather
than discretionary, and it holds against in-container root: remounting
read-write requires `CAP_SYS_ADMIN`, which Docker drops by default. The change
itself is one flag in `install.sh`.

### The four writers that must leave first

| Writer | Runs as | When |
|---|---|---|
| `AbstractExtensionManager::installFromZip()` / `installFromTarGz()` | www-data | web request |
| Composer reconcile (`PluginManager.php:746`) | www-data | plugin activation |
| `_reconcile_upgradable_assets.sh` | root | every container start |
| `utils/upgrade.php` | root | every upgrade |

The third is easy to miss. On a clone or a restore it reads
`plg_receives_upgrades` / `thm_receives_upgrades` out of the site database and
downloads the named plugins and themes into `public_html/plugins/` and
`public_html/theme/` before Apache starts. It exists because `_site_init.sh`
streams the source's database but not the source's extension directories.

All four are the same fact the top of this spec names, now with a fourth strand:
**the platform treats its own code as mutable data, written by the process that
serves it.**

### The shape of the rewrite

The writer moves out of the serving container into an **ephemeral** one. The same
named volume mounts read-write in a `docker run --rm` upgrader and `:ro` in the
server; the upgrader stages, swaps, reconciles assets and fixes permissions, then
exits.

This deliberately does **not** require splitting the serving container — the item
this spec puts out of scope — because the new container lives for seconds and
serves nothing.

### What it costs

`Dockerfile.template` VERSION 4.3 put code on named volumes specifically so an
in-place upgrade would survive container recreation, and the rollback path
depends on the container being able to move the backup directory back into
place. Both move out with the writer. That is a genuine design change and the
real price of this rung.

### Where to stop

A read-only mount does nothing against an attacker who is already root on the
**host**, who writes the volume directly. Closing that means code baked into the
image with no code volume at all — which collides with the rebuild-downgrades
problem (`reference_container_code_not_on_volume`) and with the zero-config
install principle. Stop at the read-only mount.

### Sequencing

The flag is trivial; the writers are the work, and WP1 Phase B already removes
two of them for its own reasons. So this is less a separate project than the
natural end of WP1: finish Phase B, move the start-time asset reconcile and the
upgrade out of the serving container, then flip the flag.

---

## Suggested order

1. **WP2** — one line, immediate, removes an internet-facing entry point.
2. **WP1 Phase A** — the largest real win, permissions only, no PHP changes.
3. **WP5 `AllowOverride`** and **WP5 `php-soap`** — trivial, and Phase A has
   already exercised the webroot.
4. **WP3 toolchain** (not Composer) — build-file change, verified by a fresh
   install.
5. **WP4** — touches the base image and the plugin installer path; wants its own
   deploy and its own watch window.
6. **WP1 Phase B** — the design work, once Phase A has been running.

WP3 Composer removal, WP5 base-image swap and WP6 are deliberately
unscheduled. WP6 in particular should not start until Phase B has run for a
while — it inherits Phase B's work and adds a rollback redesign on top.

## Files this touches

| File | Current version | Work package |
|---|---|---|
| `maintenance_scripts/install_tools/install.sh` | — | WP2, WP3, WP4, WP5 |
| `maintenance_scripts/install_tools/fix_permissions.sh` | 3.0 → 3.1 | WP1 |
| `maintenance_scripts/install_tools/Dockerfile.template` | 4.8 → 4.9 | WP1, WP3, WP4 |
| `maintenance_scripts/install_tools/Dockerfile.base` | 1.1 → 1.3 | WP3, WP4 |
| `maintenance_scripts/install_tools/default_virtualhost.conf` | 2.03 → 2.04 | WP5 |
| `includes/AbstractExtensionManager.php` | — | WP1 Phase B |
| `utils/upgrade.php` | — | WP1 (dead web branch) |

## Open questions

1. **Is the non-CLI branch of `utils/upgrade.php` reachable?** No route was
   found. If it is dead, delete it; if it is live, WP1 Phase A must not ship
   until it moves to the privileged path from Phase B.
2. **Does any deployment reach a container web port from off-box?** Gates WP2
   fleet-wide. The dev box and the standard Cloudflare → host Apache topology
   are fine.
3. **Is anything inside `public_html` written at runtime besides `plugins/` and
   `theme/`?** `content_staging/` is mode 777 on the dev box and should be
   checked; if it is written at runtime it either joins the data zone or moves
   out of `public_html` entirely, and moving it out is the better answer.
