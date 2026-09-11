# The read-only tree: the web user can read the code and never change it

**Security inventory row:** S10 (closes S6 with it; the floor under S9).
**Status:** BUILT 2026-09-11, proven on dev (read_only_tree gate 23/23, a web-user request carried out by root). Moves to implemented/ once the fleet has been upgraded and watched.
**Related:** `specs/host_converger.md` (the root actor this spec relies on),
`specs/implemented/parser_jail.md` (S5), `specs/vault_key_memory_exposure.md`
§ Mitigation C (the direction this spec turns into a task; B4, B12).

## The problem, with the example that matters

On every node today the code tree belongs to the web server user. `www-data`
owns `serve.php`, every plugin, every theme, `vendor/`, `maintenance_scripts/`
and even the site directory that holds them, with write permission
(`fix_permissions.sh` sets `www-data:user1` 770; the container start command
sets `www-data:www-data` 755/644 on every boot). That was convenient: the
browser upgrade, plugin installs and theme installs all write the tree from
inside a web request. The cost is that any bug that lets an attacker make the
web server write one file lets them write PHP into the tree.

A plugin's image resizer takes a filename from the request and does not check
it hard enough. An attacker sends a request that makes it save a file at
`plugins/mailbox/includes/Helper2.php` holding ten lines of PHP. That is all
the bug gives them, once. But the file is now in the tree, and the tree is what
the web server runs: on the next request their file loads like any other class.
They are permanent. They see the next vault window that opens, the next sealed
mailbox the owner reads, every setting, every password the pool decrypts. The
parser jail does not help (nothing is being parsed); the settings guards do not
help (no setting was touched); the upgrade does not evict them (it overwrites
the files it knows about and leaves a stranger's file in place). A one-request
bug became a resident.

After this spec, the same attacker with the same bug gets a permission error.
The bug is still a bug; it cannot persist. Their access ends with the request.

## What the sweep found (2026-09-10)

The inventory of everything the web user writes, and everything root runs from
a place the web user can write. Each item is either moved out of the tree,
handed to the root actor, or closed outright.

### The executable set — what the pool runs or includes

| Path | Owner today | Written by www-data today | Under this spec |
|---|---|---|---|
| `{site}/public_html/` | www-data | upgrade swap, plugin/theme installs, `refreshFromUpstream`, agent-files editor (`*.md` at the root), docs editor (`docs/*.md`), test fixtures | root-owned, 755/644; only the root actor writes |
| `{site}/maintenance_scripts/` | www-data | upgrade (`rsync` as root already) | root-owned; **root runs these** |
| `{site}/vendor/` | www-data | `ComposerValidator::reconcilePluginPackages()` on plugin activation (`composer require`/`install` as www-data) | root-owned; reconcile becomes a root request |
| `{site}/config/Globalvars_site.php` | www-data | `SecretBox` mints `secret_box_key` on first use if absent (install already mints it, `_site_init.sh` 2.2) | `root:www-data` 0640; the fallback mint moves to the root actor |
| `{site}/RELEASE_MANIFEST`, `.sig` | copied by root into a www-data-owned directory | replaceable by www-data (directory write) | root-owned in a root-owned site directory |
| `{site}` (the site directory itself) | www-data (`install.sh` line 2905 `chown -R www-data /var/www/`) | can rename `public_html` or replace the manifest | root-owned 755 |
| `{site}/cache/class_map.php` | www-data | `ClassAutoloader` writes `<?php return [...]` and **`include`s it** on every request (B14) | becomes `class_map.json`, parsed, never included |

### The data set — what www-data writes and must keep writing

`uploads/`, `static_files/`, `cache/`, `logs/`, `storage/`, `backups/`,
`/var/lib/php/sessions`, and the non-PHP files in `config/` that are already
pinned to www-data (`backup_site_key`, `relay_pull_key`, `backup-ledger/`). All
of these are outside `public_html` already (a container mounts each as its own
volume). None of them may ever be executed:

- `static_files/` is an Apache `Alias` with `AllowOverride FileInfo` and the
  global php-fpm handler in force (B16). A `.php` file dropped there runs;
  a `.htaccess` there can `SetHandler` anything. The one `.htaccess` in use
  (`static_files/uploads/.htaccess`, a 302 to `/uploads/` for missing files)
  moves into the vhost; the directory gets `AllowOverride None` and the PHP
  handler removed.
- `uploads/` is served through `serve.php` with `readfile()`; nothing executes
  there. It stays that way, and `allowed_upload_extensions` stays bounded (S2).
- `cache/` holds the static page cache (HTML served with `readfile()`) and the
  class map (B14 above). Nothing in `cache/` is included after this spec.

### Root runs scripts the web user can write (B13)

`_plugin_installers_start.sh` runs `install_agent.sh`, `install_parser_jail.sh`,
`install_host_converger.sh` and every active plugin's `host_installer` **as
root** and verifies none of them. That was always true at container start,
install and the agent-run upgrade; the host converger (2026-09-10) now runs it
from a root timer every five minutes on every node. The scripts live in
`maintenance_scripts/` and `plugins/`, both www-data-writable. So today a
process running as the web user writes ten lines into
`plugins/mailbox/provisioning/install_email.sh` and is root within five minutes.
The agent's manifest check covers only the entry point it execs
(`reference_manifest_gate_is_entry_point_only`), not what that script runs.

This is the reason S10 is next and not later. The fix is ownership: root-owned
scripts cannot be edited by the web user. The runner additionally refuses any
script that is not owned by root or is group/other-writable, so a box whose
ownership has drifted fails loudly instead of running whatever it finds.

### Findings recorded here

- **B13** — the root moments run www-data-writable scripts, unverified. Closed by ownership + the runner's refusal.
- **B14** — `cache/class_map.php` is a www-data-writable PHP file the pool includes on every request. Closed by JSON.
- **B15** — `config/Globalvars_site.php` is `require`d PHP and www-data-writable. Closed by `root:www-data` 0640.
- **B16** — `static_files/` executes PHP and honours `.htaccess`. Closed in the vhost.
- **B12** (from the vault spec) — `uploads/` readable by any local uid. Closed by `www-data:www-data` 0770 on the data set.

## The principle

**Every file the PHP pool executes or includes is owned by root and not
writable by the pool. Every file the pool writes is data, and nothing ever
executes it.** One rule, both directions. A break-in through the web server
can read what the web server reads and write what the web server writes, and
neither of those is code.

The pool keeps its write access to exactly the data set, and loses it to
exactly the executable set. What the pool used to write into the executable set
becomes a **request** that the root actor carries out.

## The root actor

Every box already has one, as of 0.8.385: the host converger
(`joinery-host-converger.timer`, or `/etc/cron.d/joinery-host-converger`)
running `_plugin_installers_start.sh --when-changed` as root, and on managed
nodes the agent as well. This spec gives the converger a second job: **carry out
root requests**.

A root request is a small JSON file the web user writes into
`{site}/cache/root_requests/`:

```json
{"kind": "upgrade", "args": {}, "requested_by": 4500, "requested_at": 1789062000}
```

`kind` is one of a closed set; `args` are validated by the CLI that carries the
kind out, never by the request. The runner reads each file, runs the matching
command from the root-owned tree, writes the transcript to
`{site}/logs/root_requests/<id>.log`, and moves the request to `done/` or
`failed/` with the exit code. The web side (`RootRequest` class) submits,
lists, and reads status and transcript. This is the same shape as the plane's
primitives: a name crosses, never a command.

| kind | carried out by | replaces |
|---|---|---|
| `upgrade` | `php utils/upgrade.php --verbose` as root (the exact command the agent runs) | the browser upgrade running as www-data |
| `install_plugin` `{name}` | `php utils/install_extension.php plugin <name>` (fetch from the upgrade source + DB install) | `PluginManager::install()` writing `plugins/` |
| `reconcile_composer` `{plugin}` | `ComposerValidator::reconcilePluginPackages()` as root | activation writing `vendor/` |
| `write_agent_files` `{agent_file_id?, force?}` | `AgentFile::write_to_disk()` | the agent-files editor writing `CLAUDE.md` |
| `save_doc` `{key, content, expected_hash}` | `DocsScanner::save_doc()`; root derives the docs directory from the key (a `plugin/…` key is that plugin's `docs/`, anything else is core `docs/`), never from the request | the docs editor writing `docs/*.md` |
| `set_receives_upgrades` `{name, value}` | `ThemeManager::writeManifestReceivesUpgrades()` | the Themes page writing `theme.json` |

**There is no kind that installs an uploaded package.** The queue and
`uploads/staging/` are both www-data-writable, so a request proves only that
something running as the web user wrote it, never that an operator asked. And
installing a plugin is root on the box: its migrations run under the root
request, and its `host_installer` runs as root on the converger's next tick.
A kind that moved a staged directory into the tree would turn the spec's own
premise, one bug that lets an attacker write one file, into root. The kinds
above cannot be abused that way: `upgrade` and `install_plugin` fetch from the
configured upgrade source, and the rest write markdown, a doc, or one boolean
in a manifest. The web side still unpacks and checks an uploaded package under
`uploads/staging/`; installing it is a shell command
(`php utils/install_extension.php plugin|theme --staged=<dir>`) that the page
shows. The kind comes back with S9, when root can verify our signature on the
package before it moves anything.

Three rules about carrying a request out:

1. **Root never unpacks a stranger's archive.** The web side (www-data) unpacks
   a package into `uploads/staging/<id>/` and validates it (manifest present,
   no path escapes, no symlinks, entries summing under a size cap). Root, from
   a shell, first copies it with `cp -a --no-dereference` into a root-owned
   temporary directory, checks it there, and installs from there: nothing the
   web user can still write is read at the moment of the move.
2. **Ownership after every write is the tree's owner.** On a node that is root.
   On the developer box the tree belongs to the developer account (see § Dev),
   and root leaves files owned by that account. The owner is recorded in
   `{site}/config/tree_owner` by `fix_permissions.sh` (root:root 0644 inside a
   0750 directory the pool cannot write), because the one moment the record
   matters, `public_html` owned by www-data, is the moment the tree itself
   cannot answer. The record is authoritative once written; `--dev` and
   `--production` decide only what is recorded the first time. The runner
   trusts the record only if root could have written it (root-owned, no group
   or other write bit) and it names a real account that is not www-data;
   anything else means root.
3. **Modes are 755/644 in the executable set.** No group write, no other write.
   `config/Globalvars_site.php` alone is `root:www-data` 0640 (the pool must
   read it; nobody else needs to).

### The entry point lives outside the tree

The timer and the cron line run `/usr/local/sbin/joinery-host-converger`, a
root-owned copy of the runner, never the file inside the tree: whoever owns
the tree can rewrite anything in it, and on the developer box that is not
root. The converger installer places the copy and refreshes it whenever it
differs from a trusted source (owned by the tree owner, no group or other
write bit); the runner refreshes it too, after the ownership assertion. Both,
because the copy is what runs, and a copy too old to find the site's tools
would otherwise never replace itself.

### Latency

The converger timer moves from five minutes to **one** (`--when-changed` is a
hash of a handful of files plus a directory listing; a minute is nothing). On
systemd boxes a `joinery-host-converger.path` unit watching
`cache/root_requests/` fires the service on the first write, so a request there
is picked up in seconds; cron boxes (the containers) wait up to a minute. The
admin page that submitted the request polls its transcript.

### A box with no root actor

If the converger is missing or stale (`cache/host_converger.last` older than
`HostConvergerNotice::STALE_AFTER`), every page that submits a request says so
before the button: *"This machine has no root actor; the request will wait until
one runs."* The request is still written, and is carried out when the converger
returns. Nothing is silently refused and nothing is silently skipped. This is
the one real cost of the spec: a self-hosted box that removed its converger
cannot upgrade or install anything until it is put back, because the web server
can no longer write the new code in. `install.sh` installs the converger on
every new box; 0.8.385 installed it on every existing one; the notice reports
one that is missing.

## What changes, by area

### WP1 — ownership and the runner's refusal

- `fix_permissions.sh` 4.0: two sets. Executable set (`{site}` itself,
  `public_html`, `maintenance_scripts`, `vendor`, `RELEASE_MANIFEST*`,
  `config/*.php`): owner = the tree owner (root on a node, the developer account
  on dev), 755/644, `config/*.php` `root:www-data` 0640. Data set: `www-data:www-data`
  0770 (dev too; the 777 mode goes). The pinned files keep their pins. The
  `--production` / dev distinction shrinks to *who owns the tree*.
- `Dockerfile.template`: the start command stops chowning the tree to
  www-data (3.6's self-heal); it sets root ownership instead. Until a container
  is recreated with the new image it keeps the old CMD, so the runner's first
  act on every run is to assert the executable set's ownership itself (inline
  `find`/`chown`, not a script), then proceed.
- `_plugin_installers_start.sh` 2.0: before running any installer, refuse one
  that is not owned by the tree owner or is group/other-writable, with the
  reason on stderr and in `host_converger.last`. `install.sh` stops the
  `chown -R www-data /var/www/`.
- `upgrade.php`: drop the `www-data` ownership expectation (the pre-flight at
  line 41 and the "Apache cannot read a single file" rationale at 1276); the
  deploy step chowns to the tree owner; `tests/run.php deploy` already runs as
  root.

### WP2 — the executable set stops being written by the pool

- `ClassAutoloader` 1.2.0: `cache/class_map.json`, `json_decode`, never
  `include`. Old `class_map.php` removed on first run.
- Every first-use mint in `config/` becomes a root step: `secret_box_key`,
  `backup_site_key` and `backup-ledger/`, in one sourced script
  (`_config_secrets.sh`) that `_site_init.sh` uses at install and the runner
  uses at every root moment. `SecretBox` and `BackupEnvelope` throw with the
  converger's next-run wording instead of writing. Composer runs as root under
  `COMPOSER_ALLOW_SUPERUSER=1` with `COMPOSER_HOME` in the site's cache.
- `install.sh` / `_site_init.sh`: write `Globalvars_site.php` as `root:www-data` 0640.

### WP3 — root requests

- `includes/RootRequest.php`: `submit(kind, args)`, `status(id)`, `transcript(id)`,
  `pending()`, and `actorState()` (converger present / stale / absent, for the
  page notice). Kinds are constants; unknown kinds throw.
- `_plugin_installers_start.sh` 2.0 carries requests out after the installers,
  one at a time, oldest first, each in its own `php` process, transcript to
  `logs/root_requests/`. A request older than a day that never ran is reported,
  not dropped.
- `utils/install_extension.php` (new, CLI, root): `plugin|theme <name>` from
  the upgrade source, or `--staged=<dir>` from `uploads/staging/`. Validates the
  name (`validateName()`), refuses a staged path outside `uploads/staging/`,
  moves the directory into place, sets ownership, runs the DB half
  (`PluginManager::install()` minus the file refresh).
- `install_host_converger.sh` 1.1: one-minute interval; the `.path` unit on
  systemd boxes.

### WP4 — every web write into the tree becomes a request

- `/utils/upgrade` (browser): the page shows the current version, the source's
  version, the actor state, and an *Upgrade now* button that submits `upgrade`
  and then shows the transcript as it grows. The agent's `apply_update` path is
  unchanged (it already runs `upgrade.php` as root).
- `admin_plugins_logic.php`: ZIP upload → unpack + validate into staging, then
  the page shows the shell command that installs it (no request kind, see
  § The root actor); marketplace install → `install_plugin`; activation of a
  plugin declaring composer packages → `reconcile_composer` first, then
  activation when it completes.
- `admin_themes_logic.php`: ZIP upload → staged, shell command shown; the
  Mark Preserved / Mark Upgradable buttons → `set_receives_upgrades`.
- `admin_agent_files` / `admin_agent_file_edit` / `update_database`: the write
  becomes `write_agent_files`. `update_database` reports "queued for the root
  actor" instead of writing.
- `admin_help_edit`: Save submits `save_doc` with the key, the content and the
  starting hash; root derives the directory from the key.
- `PluginManager::refreshFromUpstream()` is called only from the CLI.

### WP5 — the vhost

`default_virtualhost.conf`: `AllowOverride None` everywhere (the rewrite already
lives in the `<Directory>` block, and no `.htaccess` exists in `public_html`);
in the `static_files` Directory, `<FilesMatch "\.ph(?:ar|p|ps|tml)$"> Require all
denied</FilesMatch>` and the `/uploads/` fallback rewrite moved in from the
`.htaccess`. `install.sh` re-renders the vhost on upgrade the way it renders it
on install (it is a host installer step, so the converger applies it).

### WP6 — tests and the notice

- `tests/security/read_only_tree_gate.sh` (tier `deploy`, `needs: [host-converger]`):
  www-data cannot create a file in any executable-set directory; `config/*.php`
  is 0640 root:www-data; `cache/class_map.php` does not exist and the map is
  JSON; a `probe.php` written into `static_files/` by www-data is served as
  403, never executed; a `.htaccess` there changes nothing; the runner refuses a
  group-writable installer (proved with a fixture under `--site-root`); a
  submitted `write_agent_files` request completes within the timer interval.
- `tests/security/parser_jail_gate.sh` and `tests/unit/document_text_test.php`:
  the tree-write checks stop WARNing on a world-writable tree and FAIL, because
  there is no longer a world-writable tree anywhere, dev included.
- `tests/unit/installer_contract_test.php`: the runner's refusal, the request
  loop, the ownership assertion.
- `tests/unit/root_request_test.php` (safe): submit/status/transcript, unknown
  kind refused, staged path outside `uploads/staging/` refused.
- `HostConvergerNotice` gains the pending-requests count and the oldest
  request's age; `VaultHealth::checkHostConverger` fails on a request older
  than a day.
- Test scratch that lands in the tree today (`tests/fixtures/documents/`,
  written by suites run from `/tests/` as www-data) moves to `cache/tests/`.

### WP7 — docs, inventory, rollout

- `docs/deploy_and_upgrade.md`: the ownership model, root requests, the
  browser upgrade as a request. `docs/plugin_developer_guide.md`: a plugin
  never writes the tree; `host_installer` scripts are root-owned and refused
  otherwise. `docs/security` pointer in `docs/document_text.md`'s S10 note.
- `specs/security_inventory.md` S10 row → this spec; S6 row closes with it
  (the Postfix pipe runs `www-data` and can no longer write code; a separate
  uid buys nothing further).

## Dev

The developer box is under the same rule, with the tree owner being the
developer account (`user1`) instead of root. `www-data` reads the tree and
writes the data set. Consequences, each handled above:

- `git`, editors and the CLI test runner work as `user1` unchanged.
- Publish runs as root (a job of dev's own agent) and leaves what it writes
  (`bin/`, `agent_dist/`, `RELEASE_MANIFEST`) owned by `user1` (rule 2).
- The agent-files editor and the docs editor go through root requests and the
  `.path` unit, so an edit at `/admin/admin_agent_files` reaches `CLAUDE.md` in
  seconds, owned by `user1`.
- 1,643 files in dev's tree are `www-data`-owned today; `fix_permissions.sh`
  re-owns them once.
- The parser jail gate's two WARNs (tree writable, config readable by the jail
  user) become passes.

## Rollout

The change arrives as an ordinary release. On a managed node the agent runs
`upgrade.php` as root: the new `fix_permissions.sh` re-owns the tree during the
deploy step, the new runner installs the one-minute timer. On a self-hosted box
the browser upgrade (still www-data, for the last time) deploys the new code
into a still-writable tree; the converger's next `--when-changed` run sees the
new VERSION and runs the new `fix_permissions.sh` as root. From then on the
tree is root-owned and the next browser upgrade is a request.

Containers keep the old start command (which chowns the tree to www-data on
every start) until recreated with the new image; the runner's inline ownership
assertion covers the gap, and the image rebuild closes it. Dev converges the
way it did for 0.8.385: the publish queues the installers job on its own node.

## What this does not do

- It does not verify content. A file root wrote is trusted because root wrote
  it; a signed tree manifest checked at each root moment is S9's job and gets
  its floor here.
- It does not stop a break-in from reading. The pool still reads every file it
  needs to run, including `config/Globalvars_site.php`. Residual #2 stands.
- It does not stop root. A kernel or agent compromise is residual #7.
- It does not make the parser jail required; `DocumentText::JAIL_REQUIRED`
  stays advisory (owner, 2026-09-10).
- It does not let a browser install an uploaded package. That needs a shell
  until S9 gives root a signature to check.

## Decisions (owner, 2026-09-10)

**D1 — Dev is under the same rule.** The tree owner on the developer box is
the developer account; `www-data` reads the tree and writes the data set.
Browser-side tree writes on dev go through root requests and the `.path` unit.
The one-time re-own of the web-user-owned files happens with the first
`fix_permissions.sh` run.

**D2 — The docs editor stays, as a request.** `save_doc` carries the document
key, the content and the starting hash in the request file itself; there is no
draft table. Root checks the hash before writing, so an edit that landed in the
checkout meanwhile is not clobbered.

Everything else follows from the principle and has no second reasonable answer:
root owns the tree; the converger carries requests; the browser upgrade becomes
a request with a transcript; `static_files` never executes; the class map is
data.

## Execution notes for the builder

This section is the build order. Follow it in sequence; each step ends with a
command whose output decides whether the next step starts. Two **stop points**
hand the work back to the owner for review before anything irreversible.

### Standing rules

- Never `git commit`, never `git add`. The owner commits from their own
  session; a shared index means staged work gets swept into someone else's
  commit.
- Schema changes go through data classes and `update_database`, never
  migrations. This spec has none.
- Root on the developer box is the owner's. When a step needs root on dev,
  print the exact `sudo` command with absolute paths and stop; do not look for
  another way to get root.
- After every PHP edit: `php -l` and
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`.
  The validator executes the file's top level, so never point it at a script
  that acts on include.
- Working loop: `php tests/run.php --changed`. Pre-handoff gate:
  `php tests/run.php db --changed`. Never `live`; never any tier as root except
  `deploy`.
- Docs describe the end state only: no "now", "previously", "replaces".
- Bump the version header of every file touched.
- If a code path writes the tree and is not in § What the sweep found, do not
  improvise: add it to the table with what it does, make it a request kind
  only if it is an operator action, and flag it in the handoff.

### Step 1 — WP1, ownership and refusal (STOP POINT 1 at the end)

1. `fix_permissions.sh` → 4.0. Interface unchanged (`SITE --production|--dev`).
   Executable set = `{site}` itself, `public_html`, `maintenance_scripts`,
   `vendor`, `RELEASE_MANIFEST`, `RELEASE_MANIFEST.sig`, `config/*.php`.
   Tree owner: `config/tree_owner` when it exists; otherwise `--production` =
   `root`, `--dev` = the current owner of `public_html` unless that is
   `www-data`, in which case `${SUDO_USER:-root}`, and the answer is recorded
   in `config/tree_owner` (root:root 0644).
   Modes 755/644; `config/*.php` = `root:www-data` 0640. Data set =
   `uploads static_files cache logs storage backups` + non-PHP `config/`
   contents: `www-data:www-data` 0770 in both modes (dev's 777 goes). Keep the
   existing pins and the find-only-what-differs rule (ctime matters to
   incremental backups). Keep `-not -path` pruning of `.git` so a dev checkout's
   object store is not re-owned.
2. `_plugin_installers_start.sh` → 2.0, in this order at the top of every run:
   a. **Ownership assertion**, inline (no script called): if `public_html` is
      owned by `www-data`, own the executable set to `root:root` 755/644 and
      `config/*.php` to `root:www-data` 0640, `find ... -not -user root`
      style so an already-correct tree is untouched. Log one line when it
      changed anything.
   b. **Refusal**: before running any installer (core or plugin), check
      `stat -c '%U %a'`: owner must equal the tree owner (owner of
      `public_html`) and mode must have no group/other write bit. Refuse with
      `installer refused: <path> owned by X mode NNN` on stderr and outcome
      `installer-refused` in `host_converger.last`.
   c. Then the existing flow: core installers, PHP extensions, plugin
      installers.
3. `Dockerfile.template` → 4.6: the CMD owns the tree to `root:root` (not
   `www-data`) and leaves `cache/` and the data volumes to `www-data`. Keep
   `_plugin_installers_start.sh` as the last thing before `apache2ctl`.
4. `install.sh`: remove the `chown -R www-data:www-data /var/www/` (line ~2905);
   call `fix_permissions.sh` in the mode the install is in. `_site_init.sh`:
   write `Globalvars_site.php` as `root:www-data` 0640.
5. `utils/upgrade.php`: remove the www-data ownership pre-flight (~line 41) and
   the "Apache cannot read a single file" fallback `chmod -R 770` paths (~1276
   to 1290): after the deploy swap, `fix_permissions.sh --production` is the
   only permission step. The deploy-tier test run and the runner stay where
   they are.
6. `tests/unit/installer_contract_test.php`: the assertion (a www-data-owned
   fixture tree under `--site-root` gets re-owned — run under the harness as a
   simulation: assert the runner *emits* the chown plan when not root, since
   the test cannot be root), the refusal (a group-writable fixture installer is
   refused with the exact message), and the order (assertion before refusal
   before installers).
7. Run `php tests/run.php db --changed`. Then **STOP POINT 1**: hand the owner
   (a) the diff list, (b) the test output, (c) this command for dev:
   `sudo bash /var/www/html/joinerytest/maintenance_scripts/install_tools/fix_permissions.sh joinerytest --dev`
   and (d) `sudo bash /var/www/html/joinerytest/maintenance_scripts/install_tools/_plugin_installers_start.sh --when-changed`
   and ask them to run both and paste `cache/host_converger.last` and
   `ls -ld /var/www/html/joinerytest/public_html`. Do not continue to Step 2
   until the owner says the tree is re-owned and the site still serves.

### Step 2 — WP2, the two included files

1. `includes/ClassAutoloader.php` → 1.2.0: `cache/class_map.json`,
   `json_decode`, atomic write via temp + rename as today; delete a stale
   `class_map.php` if present; the APCu path unchanged.
2. `includes/SecretBox.php`: remove the config write. When the key is absent,
   throw naming the converger: "secret_box_key is not set; the host converger
   mints it on its next run". `_plugin_installers_start.sh` 2.0 gains the mint
   (as root, `root:www-data` 0640, same generator `_site_init.sh` uses) when
   the key is absent — reuse the generator by sourcing one function, do not
   copy it.
3. Tests: `tests/unit/class_autoloader_test.php` (or the existing autoloader
   suite) proves the map is JSON and that a `class_map.php` left behind is
   removed and never included.

### Step 3 — WP3, root requests

1. `includes/RootRequest.php` 1.0: `const KINDS`, `submit(string $kind, array
   $args, int $user_id): string` (returns id, writes
   `cache/root_requests/<id>.json` 0640 www-data), `status(string $id): array`
   (`queued|running|done|failed`, exit code, started/finished), `transcript(
   $id): string` (from `logs/root_requests/<id>.log`), `pending(): array`,
   `actorState(): string` (`present|stale|absent` from
   `HostConvergerNotice::facts()`). Unknown kind throws. `$args` are stored
   verbatim; validation belongs to the CLI. Id = `<unix>-<random 8 hex>`.
2. `_plugin_installers_start.sh` 2.0, after the installers: for each
   `cache/root_requests/*.json` oldest first, move it to `running/`, run the
   kind's command with `php` from the root-owned tree (kind → command table in
   the script, args passed as `--key=value` after `escapeshellarg`), transcript
   to `logs/root_requests/<id>.log`, then move to `done/` or `failed/` with
   `.exit` written beside it. One request at a time. A request in `running/`
   older than an hour at startup is moved to `failed/` with reason `abandoned`.
3. `utils/install_extension.php` 1.0 (CLI, root): `plugin|theme <name>` from
   the upgrade source (what `refreshFromUpstream` does today, moved here;
   reachable as a request) or `--staged=<dir>` where `<dir>` must resolve
   under `{site}/uploads/staging/` (shell only, never a request kind).
   Validates the name with the manager's `validateName()`, copies the staged
   directory with `cp -a --no-dereference` into a root-owned temporary
   directory, refuses symlinks and `..` there, copies it into place, owns it
   to the tree owner 755/644, then runs the DB half (`PluginManager::install()`
   with the file refresh removed, or the theme equivalent). `reconcile_composer`
   calls `ComposerValidator::reconcilePluginPackages([$plugin])` and re-owns
   `vendor/`. `write_agent_files` and `save_doc` are `utils/root_request_*.php`
   one-purpose scripts or subcommands of one `utils/root_request.php`; pick
   one file, `utils/root_request.php <kind> --args`.
4. `install_host_converger.sh` → 1.1: `OnUnitActiveSec=1min`; a
   `joinery-host-converger.path` unit with `PathChanged={site}/cache/root_requests`
   on systemd boxes; cron line `* * * * *`.
5. `tests/unit/root_request_test.php` (safe): submit/status/transcript against
   a scratch site root, unknown kind refused, a staged path outside
   `uploads/staging/` refused by the CLI, the request loop under `--site-root`
   carries a `write_agent_files` request to `done/` when run as the current
   user (the harness is not root; the loop must not require root for kinds
   that only need the tree owner).

### Step 4 — WP4, the pages

Build one shared panel first: `AdminPage::root_request_panel($id)` renders
status + transcript and polls `/api/v1` (`root_request_status` logic
descriptor) every two seconds until done/failed; when `RootRequest::actorState()`
is not `present`, every page that submits shows the "no root actor" line above
its button. Then, one page at a time:

1. `utils/upgrade.php` browser branch: version, source version, actor state,
   *Upgrade now* → `submit('upgrade')` → panel. The CLI/agent branch unchanged.
2. `adm/logic/admin_plugins_logic.php`: ZIP → `installPlugin()` becomes
   "unpack + validate into `uploads/staging/<id>/`" (move the unpack out of
   `AbstractExtensionManager` into a `stage()` method that never touches the
   tree) → the page shows the shell command that installs it. Marketplace
   → `submit('install_plugin', ['name' => $n])`. Activation of a plugin whose
   manifest declares composer packages → `submit('reconcile_composer')` and
   activation proceeds when the request is `done` (panel, then the activate
   button re-enabled).
3. `adm/logic/admin_themes_logic.php`: ZIP staged as above; the
   receives-upgrades buttons submit `set_receives_upgrades`.
4. `data/agent_files_class.php` + `adm/logic/admin_agent_files_logic.php` +
   `admin_agent_file_edit_logic.php` + `utils/update_database.php`:
   `write_to_disk()` is only called by the root request script; the pages and
   update_database call `RootRequest::submit('write_agent_files')`. The drift
   guard stays inside `write_to_disk()`.
5. `adm/logic/admin_help_edit_logic.php`: Save → `submit('save_doc', ['key',
   'content', 'expected_hash'])`; the root script derives the docs directory
   from the key and calls `DocsScanner::save_doc()` unchanged.
6. `PluginManager::refreshFromUpstream()` and `installPlugin()`: throw if
   called from a web SAPI.

### Step 5 — WP5, the vhost

`default_virtualhost.conf`: `AllowOverride None` in every Directory block; in
both `static_files` blocks add `<FilesMatch "\.ph(?:ar|p|ps|tml)$">Require all
denied</FilesMatch>` and the `/uploads/` fallback rewrite from
`static_files/uploads/.htaccess`; delete that `.htaccess` in
`install_host_converger.sh`'s run (it is a data-dir file, root may remove it).
Re-render the vhost on upgrade: `install.sh` already has the render; expose it
as `install_tools/render_vhost.sh` and add it to `CORE_INSTALLERS` so the
converger applies it (idempotent: write only if changed, `apachectl -t` on the
candidate before installing it, reload only on change, previous file kept). It
records what it last wrote and applies only over its own previous output. On a
box with no record it adopts when every line of the vhost on disk, ignoring
`AllowOverride` lines, also appears in the new render: that is an unedited
older render, which is every box we installed. Anything else is an operator's
work and gets a `.new` candidate and a message.

### Step 6 — WP6, the gate and the notice

1. `tests/security/read_only_tree_gate.sh` (`tier: deploy`, `needs:
   [host-converger]` — add the need to `tests/run.php` next to `parser-jail`,
   probing `HostConvergerNotice::facts()`): the checks listed in § WP6, each a
   `chk` line, exit-code only.
2. `tests/security/parser_jail_gate.sh` and `tests/unit/document_text_test.php`:
   the world-writable WARN/skip branches go; the checks are hard.
3. `HostConvergerNotice`: pending count + oldest age; `VaultHealth` 1.4:
   `checkHostConverger` fails on a request older than a day;
   `tests/vault/vault_health_test.php` updated.
4. Move test scratch: grep `tests/` for writes under `PathHelper::getRootDir()`
   and point them at `{site}/cache/tests/` via one harness helper
   `harness_scratch_dir()`.
5. `php tests/run.php db --changed` green.

### Step 7 — WP7, docs and the handoff (STOP POINT 2)

1. `docs/deploy_and_upgrade.md` (ownership model; root requests; the browser
   upgrade), `docs/plugin_developer_guide.md` (a plugin never writes the tree;
   `host_installer` scripts must be root-owned; `stage()`), `docs/document_text.md`
   (the S10 note becomes a sentence in the present tense),
   `plugins/server_manager/docs/overview.md` (the converger carries requests).
2. `specs/security_inventory.md`: S10 and S6 rows → DONE wording, pointer to
   `implemented/read_only_tree.md`. Do not move the spec yet; the owner moves it
   at commit.
3. **STOP POINT 2**: hand the owner the full diff list grouped by WP, the gate
   outputs, the list of any tree-writing paths found outside the sweep, and the
   rollout order: commit → publish from the button → the publish queues the
   installers job on dev → upgrade one container and paste its transcript →
   the rest of the fleet → rebuild the container image. Do not publish.
