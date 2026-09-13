# Post-release fleet defects: what the 0.8.390 check found

**Status: IMPLEMENTED 2026-09-13. WP1, WP2, WP5, WP6 built, reviewed and
proven on dev; they ship in the next release, and docker-prod's fail2ban is
fixed once by hand with `host_housekeeping.sh` run from a checkout. WP3 is
`agent_tier1_recipes.md`, sequenced after. Per-package status lines under
each B-item.** Written
2026-09-13 from a read-only check of
all ten Joinery nodes, the relay and both DNS resolvers after 0.8.390 rolled out.
The release itself is healthy everywhere. Everything below predates it and
was only visible because someone finally looked at every node at once.
Four defects, worked as separate packages: **B1** a plugin uninstall the
read-only tree half-refuses, **B2** fail2ban dead on both hosts we run,
**B3** the node's own backup failing every morning on two nodes, **B4** the
warnings every error log carries. B2 opened the question of what the agent
needs so that a problem like it is fixed by the node itself; the answer is
`agent_recipes_and_vocabulary.md`, the unified design (owner, 2026-09-13).

## B1: uninstalling a plugin on a read-only tree leaves it half gone

**Status (WP1): BUILT 2026-09-13.** `uninstalled` state on the row
(`plg_uninstalled_time`, request id under `plg_metadata._remove_request_id`),
`remove_plugin` kind (RootRequest, the runner, `utils/root_request.php`,
checks in `includes/PluginRemoval.php`), `is_system` refusal, sync/stale/
dependents/version-list/marketplace/activation all leave the row out, the
page's three readings and the confirm text. Proven live on dev: uninstall from
the page, row `uninstalled`, request queued, the host timer removed the
directory on its tick (transcript: `removed plugins/zz_uninstall_proof (4 files
and directories)`), the row then read "Uninstalled 2026-09-13 - Data and files
removed - Not published by the upgrade source". Tests: `plugin_uninstall`
(test-db), `root_request`, `host_converger` gate. Review 2026-09-13: migration
105 (`cleanup_uninstalled_plugins.php`, which deleted every `uninstalled` row
and was hash-tracked, so any edit would have re-run it) is removed from the
list and deleted - every node has its hash row and a fresh install has no rows
for it; "still published" comes from `MarketplaceClient::published_names()`, a
copy kept for a day in `cache/`, not a catalog fetch on every page load; the
`plg_status` reader inventory: the readers left test for `active` only.

### What happened

On 2026-09-12 at 19:17 UTC the owner uninstalled the store plugin on
jeremytunnell from `/admin/admin_plugins`. `PluginManager::uninstall()` ran
its nine steps: settings, menus, deletion rules, scheduled tasks, version and
migration records, the uninstall hook, **drop every store table**, delete the
`plg_plugins` row, delete the files. Step nine failed on every file
(`unlink(): Permission denied`, 144 files, then `rmdir()`), because the tree
belongs to root and the pool cannot write it (`specs/implemented/read_only_tree.md`).
The page reported success.

Three hours later 0.8.390 landed. The upgrade's `PluginManager::sync()` walks
`plugins/*`, finds a directory with no row, and registers it: store came
back as **inactive**, `plg_installed_time` 22:08:55, with none of its tables
(zero `prd_*`, `ord_*`, `crt_*`, `cpn_*` tables on the node today). The
admin page now shows store as an installed, inactive plugin that can be
activated. Activating it would run install-time table creation, so it would
not crash, but the row lies: it says "installed 22:08:55" for a plugin
nobody installed.

A side effect worth knowing: step one deletes the plugin's declared
settings, and store declares `site_currency`. Core migration 130 tests for
that setting's row, found none, and re-applied itself at 22:08:55. The
statement is an idempotent UPDATE, so nothing changed, but it shows that
tearing down a plugin's settings reaches into core migrations.

### What the upgrade actually does with plugin directories

`utils/upgrade.php` downloads a plugin archive only when the plugin's
directory is already on the node (`plugins_to_download` is the intersection
of `plugins/*` on disk with what the source publishes), plus every plugin
whose `plugin.json` says `is_system: true`
(`get_system_required_extensions()`). So a directory that is really gone
stays gone across releases, and **`is_system` is already the mark that
means "this node cannot be without it."** No plugin carries it today.

That makes the defect exactly one thing: the pool cannot delete the
directory, and nothing else was asked to.

### The fix

**Owner's decision 2026-09-13: every plugin can be uninstalled, files
included, except one marked `is_system`.**

1. `uninstall()` steps one to eight stay. Step eight changes from
   `permanent_delete()` to a status: `plg_status = 'uninstalled'`,
   `plg_uninstalled_time = now()`, active flag cleared. Step nine changes
   from `LibraryFunctions::delete_directory()` to
   `RootRequest::queue('remove_plugin', ['name' => $name])`. Uninstall
   refuses up front when `plg_is_system` is set, with the same wording as
   the active-plugin refusal.
2. **`remove_plugin` root-request kind.** Added to `RootRequest::KINDS`
   and to the runner in `_plugin_installers_start.sh` (the mechanical test
   holds the two lists to each other). Root, before deleting anything,
   checks that the name is a valid plugin name, the directory is directly
   under `plugins/`, the plugin's `plugin.json` does not say `is_system`,
   and the database row (read through `php` from the root-owned tree, as
   `install_plugin` does) is `uninstalled`, not active. Then it removes
   the directory and records the transcript. Any check failing leaves the
   directory and says why. The request is carried out on the
   host timer's next tick, minutes not releases.
3. **The `uninstalled` state on the page.** Between the request and root's
   action the row reads "Uninstalled {date}, data removed, files being
   removed". After the directory is gone the row stays as the record
   ("Uninstalled {date}") with one action, **Install**, which is the
   existing `install_plugin` root request: fetch from the upgrade source,
   verify, install. For a plugin the source no longer publishes, Install
   is absent and the row says so.
4. `AbstractExtensionManager::sync()` treats an `uninstalled` row like any
   other existing row: it may refresh metadata while the directory exists,
   it must not change status, and it must not create a second row. Once
   the directory is gone it has nothing to do.
5. Every list that means "installed plugins" excludes `uninstalled`:
   activation lists, `getDependents()`, the API collection,
   `PluginHelper::getInstance()` where it implies a live plugin. Grep every
   `plg_status` read before calling this done.
6. The confirm text on the page is true again and gains one line: files
   are removed by the host within a few minutes.

### Tests

- Plugin manager suite: uninstall leaves a row in `uninstalled` with the
  stamp set, the directory untouched, and one queued `remove_plugin`
  request; uninstall of an `is_system` plugin throws and queues nothing;
  `sync()` after uninstall adds nothing and changes nothing; install after
  uninstall clears the stamp.
- `tests/unit/root_request_test.php`: `remove_plugin` is a known kind; a
  name with a path separator is refused before anything is written.
- `tests/integration/host_converger_gate.sh`: the runner refuses
  `remove_plugin` for an `is_system` plugin and for a row that is not
  `uninstalled`, and removes the directory otherwise.

### Cleanup on jeremytunnell

None by hand. When the fix ships, the owner uninstalls store once more from
the page; the row goes `uninstalled`, root removes the directory, and the
upgrade never brings it back.

## B2: fail2ban has been dead on both hosts since they were built

**Status (WP2): BUILT 2026-09-13.** `host_housekeeping.sh` in
`CORE_INSTALLERS`, `install.sh` 2.76 calls it and carries no inline recipe,
`default_proxy_vhost.conf` 1.04 appends X-Forwarded-For (shipped proxy
templates 1.00-1.03 added to `vhost_history/` so a Docker host's vhosts
adopt), `includes/cloudflare_ip_ranges.txt` is the one range list
(`SessionControl::cloudflare_edge_ranges()` and the installer read it, pinned
equal). Run in override mode on dev (gate transcript in the report); the dev
host timer also ran the real thing as root on its 15:52 tick, fail2ban and
Apache active afterwards. Not run on any node. Found while building: Ubuntu's
`jail.d/defaults-debian.conf` sets `backend = systemd` for every jail, so an
Apache jail on the default backend watches the journal and never the log
files - the drop-in sets `backend = auto` per Apache jail. Review 2026-09-13
(script 1.1): docker-prod's `jail.local` tail is a hardening block in other
words, not install.sh's text, so "ours" is recognised by shape (jail.conf
followed only by section headers, `enabled` and a ban policy, naming only
jails the drop-ins carry); the Apache jails are written only when the remoteip
configuration is enabled and accepted, otherwise removed with the reason (a
jail on a log naming the peer would ban the edge); the jails watch a Docker
host's `proxy_access.log` / `proxy_error.log`; `manage_domain.sh` 1.1 appends
X-Forwarded-For like the shipped proxy template.

### What happened

`fail2ban.service` is failed on **docker-prod** (since 2026-04-23, the day
after the host was built) and on **jeremytunnell** (since 2026-07-19, its
install day). Both fail the same way at startup:

```
Failed during configuration: While reading from '/etc/fail2ban/jail.local'
[line 983]: section 'sshd' already exists
```

Meanwhile docker-prod's journal shows 978 SSH brute-force attempts in the
last 24 hours, none banned. docker-prod is key-only, so there the attempts
are noise and CPU. **jeremytunnell has SSH password authentication on**
(`sshd -T`: `passwordauthentication yes`, root login off), so a named user
there can be brute-forced, and the tool meant to stop that has never run.

### How it happened

`install.sh`'s `do_server_setup()` (line 3173) configures fail2ban with:

```
cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
tee -a /etc/fail2ban/jail.local <<'EOF'
[sshd]
enabled = true
[apache-auth]
enabled = true
...
```

`jail.conf` already contains a `[sshd]` section (line 274 of the copy) and
the `[apache-*]` sections. The append creates a second `[sshd]` at line 983.
Older fail2ban parsers tolerated a repeated section; **fail2ban 1.0.2 on
Ubuntu 24.04 refuses it** and the service exits 255 on every start. The
recipe was copied forward from `maintenance_scripts/archive/server_setup.sh`
(same lines) and was never run on a 24.04 host until these two.

A later fix exists and does not help. `host_housekeeping()` (install.sh
line 2200) writes the SSH jail as a drop-in, `jail.d/joinery-sshd.local`,
with the comment "re-running never appends a second [sshd]". Neither host
has that drop-in: it was added after both were installed, and nothing
re-runs housekeeping on an existing host. Even if it did, fail2ban reads
`jail.local` first and would still die on the duplicate.

The two DNS resolvers are fine (fail2ban active, no `jail.local`): they
were built by the ScrollDaddy installer, not `do_server_setup`.

### Why nothing noticed for five months

Nothing on the plane looks at a node's systemd. `check_status` reports the
site (databases, certificates, versions), the uptime monitor reports HTTP,
and the host timer reports its own work. A failed unit on the host is
invisible unless a person runs `systemctl` there. That gap is the real
finding, and it is what `agent_recipes_and_vocabulary.md` and
`agent_tier1_recipes.md` exist for.

### The fix

Settled 2026-09-13 with the owner, and the reason
`agent_recipes_and_vocabulary.md` exists. Sites are not assumed to sit behind
Cloudflare, SSH password authentication may be on (it is on jeremytunnell),
and the fix must be a self-repair, not a release that happens to land.

1. **One implementation of "fail2ban is configured", three callers.**
   `host_housekeeping.sh` in `install_tools/`, added to `CORE_INSTALLERS` in
   `_plugin_installers_start.sh`. It replaces both inline copies in
   `install.sh` (`do_server_setup()` line 3173 and `host_housekeeping()` line
   2200); `install.sh` calls the file. Idempotent: writes
   `jail.d/joinery-sshd.local` and `jail.d/joinery-apache.local` with `tee`
   (overwrite, never append); if `/etc/fail2ban/jail.local` is byte-for-byte
   `jail.conf` plus our appended block it deletes it, otherwise it leaves it
   and logs that it was hand-edited; `systemctl enable --now fail2ban`; then
   **proves** the service is active and prints the sshd jail's ban count, or
   exits non-zero so the transcript shows the failure.
2. **Apache jails stay, and must not ban a proxy.** A jail bans whatever
   address the access log names, so the log must name the real client, and
   must name it **only when every hop between the client and Apache is a
   proxy we know**: Cloudflare's published edge ranges (the list
   `SessionControl::ip_is_cloudflare_edge()` already holds) and, inside a
   container, the host's reverse proxy (`172.17.0.0/16`, `127.0.0.1`, which
   `Dockerfile.template` 3.5 already trusts through `mod_remoteip`). A
   forged forwarding header on a direct connection must never be believed,
   because fail2ban would then ban whoever it names. Two things to build:
   the container host proxy currently **overwrites** `X-Forwarded-For` with
   its own peer address (`default_proxy_vhost.conf` lines 36 and 86), which
   discards the edge chain, so it must append instead and let `mod_remoteip`
   in the container walk the chain right to left; and bare-metal nodes get
   the same `mod_remoteip` configuration through `host_housekeeping.sh`.
   The Cloudflare range list becomes one file with two readers (PHP and the
   installer) and a test that pins them equal. Result: bans hit real
   attackers on a direct site and are inert behind an edge, never harmful.
3. **sshd posture is reported, never changed.** `host_report` (the unified
   spec) carries password-auth and root-login as read from `sshd -T`. A
   customer's authentication settings are theirs; the guard in front of them
   is ours to keep running.
4. **The self-repair is the agent's `fail2ban` recipe**, first recipe under
   `agent_tier1_recipes.md` (sequenced after the other packages): check the unit every few minutes,
   repair with `host_converge` (which runs this installer), verify, three
   attempts in an hour, then open a case. The host timer's daily converge is
   the floor when the agent is not running. No health pass is added to the
   timer; the watcher lives in the agent.
5. **Who is fixed how.** jeremytunnell: the next platform release runs the
   installer through the timer, and the agent release with the recipe keeps
   it running. docker-prod: the host has neither actor today; it is fixed
   once by hand over SSH with the same script, and `docker_host_agent.md`
   carries the acceptance that both actors land on the host so this is the
   last time.

### Tests

- `tests/unit/installer_contract_test.php`: `host_housekeeping.sh` is in
  `CORE_INSTALLERS`, `install.sh` no longer contains `cp /etc/fail2ban/jail.conf`
  or a `tee -a` into `jail.local`, and the Cloudflare range list in PHP and
  the one the installer reads are equal.
- `tests/integration/host_housekeeping_gate.sh`: the script takes a
  directory override for `/etc/fail2ban` and a no-systemctl mode; against a
  temp copy it writes both drop-ins, removes a `jail.local` that is
  `jail.conf` plus our block, leaves one with any other content, and a
  second run changes no file (compare a checksum of the tree).
- A shell gate under `tests/deploy/` is wrong here: the deploy tier must
  skip on an unreachable dependency, and fail2ban's absence is not a
  release fault.

## The vocabulary, moved

The primitive vocabulary this section first proposed grew into the unified
design and lives in `agent_recipes_and_vocabulary.md`: every word compiled,
two composers (recipes on the node, the driver on the plane), and the case
between them. B2 supplies its first installer and its first recipe.

## B3: the node's own backup has failed every morning on two nodes

**Status (WP5): BUILT 2026-09-13.** `BackupTarget` 2.7 completes a Backblaze
credential on read and writes it back once (a permitted server-initiated
write); `SiteBackupNotice` is a core `AdminNotices` renderer from the first
failed site-profile run, cleared by the next success; the relay run's bundle
copy lives under `cache/relay_runs/` (the only `relay_runs` writer was
`RelayCloudProvision::bundlePath()`, not a script). Proven live on dev: the
notice showed for the 06:00 failure, a "Run a backup now" succeeded (428 MB,
uploaded) and the header went quiet. Tests: `b2_target_heal_on_read`,
`site_backup_notice` (both test-db).

### Background: two backup profiles

Each node has two independent backup profiles
(`specs/implemented/fleet_scheduled_backups.md`): **manager**, dispatched by
the plane as an agent `backup_run` job at the node's policy time and
uploaded to the fleet target, and **site**, the node's own scheduled task
(`tasks/BackupRun.php`, "Backup" in the scheduled tasks list) writing to the
target the node's owner configured under Backups. Both run
`BackupRunner`. The manager profile is what we rely on for the fleet; the
site profile is what a self-hosted owner relies on, and on our own nodes it
is the one that has been failing.

### jeremytunnell

- Manager profile: **succeeds** daily (04:00, five minutes, last
  2026-09-13 04:00:19).
- Site profile: **fails** daily. It runs the files engine for eight
  minutes, then the upload throws `Missing required credential field:
  region` (`S3Signer::validate_creds`). The cron log shows this every day
  from 2026-09-01; between 09-06 and 09-11 the message was different
  ("No passwordless sudo, reading as www-data; an unreadable file will
  fail this backup") because a root-only file in the tree broke the
  archive step first, and 0.8.389's permission pass fixed that, which
  returned the run to the upload error underneath.
- Root cause: the node's only target (`bkt_backup_targets` id 1,
  "Backblaze B2 Joinery", saved 2026-08-25) carries `access_key`,
  `secret_key`, `bucket` and nothing else. The wizard and the admin form
  hide region and endpoint for B2, and on 2026-09-08 `BackupTarget` 2.5
  learned to ask Backblaze for them at save time
  (`b2_s3_location()`, found on keyless11 with this exact message). That
  fix runs on **save**. A target saved before 09-08 keeps its empty region
  forever, and `BackupRunner` signs with what the row has.

### dev

- Site profile fails daily at 06:00: `tar` cannot read
  `relay_runs/` and `legacy_message_export_2026-09-02.json` in the site
  root (root-owned leftovers from relay proofs), and the engine correctly
  refuses to ship an archive with holes. Both files are gone or readable
  today, so the 2026-09-14 run should pass; the warning line about
  `config/agent_signing_key` is expected on dev and is not the failure.
- No manager profile on dev (it is the plane).

### Why the owner never saw it

The site profile's failure lands in `sct_last_run_status` and the cron log.
Nothing surfaces it: no `AdminNotice`, no email, and the plane's node page
shows the manager profile only. A self-hosted owner whose only backup is
the site profile would be in this state without knowing.

### The fix

1. **Heal B2 targets on read, not only on save.** `BackupTarget` resolves
   region and endpoint through `b2_s3_location()` whenever a B2 target is
   loaded with either empty, and saves the result back once. `BackupRunner`
   then never sees an unsigned-capable B2 target. One test: a B2 row with
   empty region loads with both filled.
2. **A failing site backup is an `AdminNotice`** after the first failure
   (not the third; a backup is the one thing that must not be quietly
   wrong), cleared by the next success, with the engine's last line as the
   text, on the node's own admin. The plane-side view (the site profile's
   last outcome beside the manager's on the node page) needs the agent to
   carry it and is **not in this package**: it rides `host_report` under
   `agent_tier1_recipes.md`.
3. **Root-owned leftovers in the site root** (dev): the engine's refusal
   is right. The relay proof scripts that wrote under the site root as root
   write under `logs/` or `/var/tmp` instead. One grep in
   `sysadmin_tools` and `install_tools` for `relay_runs`.

### Settled

Owner, 2026-09-13: **both profiles stay on our own nodes.** The site profile
is the backup every self-hosted customer runs, and it was noticed only
because it runs on our nodes too. The plane's policy editor gains no
"site profile off" position.

## B4: the warnings every node carries

**Status (WP6): BUILT 2026-09-13.** All nine fixed at the root named below;
tests `static_page_cache` (extended), `pager`, `product_validation_rules`,
`admin_user_groups`. Dev's error log carried no new warning across a browse of
the touched paths (no-UA request, `?a[]=1`, `/_config`, a scheme-relative
request line, `/events`). `page_marketing` and the scrolldaddy login are not
reachable under dev's theme; their fixes are read-through only. Found while
building 4.5: `createCache()` on an unreadable index wrote the "off for this
request" placeholder back over the pool's index and turned the cache off for
good - nothing is written while the index cannot be read.

Every item below was seen on at least one production node in the two days
around the release; none is new. Four are only noise, five change behaviour.
Fix order: the real ones first (4.2, 4.3, 4.5, 4.8, 4.9), then the noise in
one sweep. All decisions taken 2026-09-13. Line numbers are as of 0.8.390.

### 4.1 `includes/SessionControl.php:449`, undefined `HTTP_USER_AGENT` (every node)

A request with no User-Agent header has no such key; `save_visitor_event()`
reads it raw. `crawlerDetect()` already treats an empty agent as a crawler,
so the behaviour is right. The same unguarded read is in `getOS()` (316) and
`getBrowser()` (355). **Fix:** `$_SERVER['HTTP_USER_AGENT'] ?? ''` at all
three. **Noise only.**

### 4.2 `adm/admin_user.php:196`, `key` on false (getjoinery, user 592)

The view lists the user's groups from `Group::get_groups_for_member()` and
then re-queries membership per row with `is_member_in_group()`; the second
query returned `false` for a group the first had just listed, and the page
emitted a Remove button with an empty member id. The two queries use the
same predicate, so this is an N+1 re-lookup that can disagree with itself.
**Fix:** `admin_user_logic` fetches the `MultiGroupMember` rows once and
passes `grm_group_member_id` with each group; the view renders the Remove
button only when a member row exists. **Real:** the page warns and emits a
button that removes nothing.

### 4.3 `includes/Pager.php:44`, array offset on false (jeremytunnell)

`Pager` parses the raw request URI with `parse_url()`, which returns
`false` for scheme-relative or absolute-form request lines (`GET //x:abc`,
`GET http:///x`), the shape scanners send. **Fix:** never `parse_url` a
request URI: split on the first `?`, `parse_str` the remainder, and use the
left part as the base. **Noise only** (pager links degrade to relative
`?offset=` links for that one request).

### 4.4 `includes/FormWriterV2Base.php:5187`, undefined `antispam_question`

`antispam_question_check()` reads the posted key without `isset`. Every
form that calls the check also emits the input, so the key is missing only
when a bot posts directly or a form was rendered before `anti_spam_answer`
was set. The result is already a rejection. **Fix:**
`strtolower($postvars['antispam_question'] ?? '')`. **Noise only.**

### 4.5 `includes/StaticPageCache.php`, three defects (developers, phillyzouk)

**Permission denied on `cache/static_pages/index.json`.** The index is
written by www-data from page requests, and also by root: `upgrade.php` and
plugin sync run under the agent, and
`AbstractExtensionManager::clearStaticPageCache()` calls `saveIndex()`,
which writes `index.json.tmp` and renames it as root. `fix_permissions.sh`
resets `cache/` to www-data only when it runs, so a root write after it
leaves a file the pool cannot open, and **the static page cache is silently
off on that node** until the next permissions pass. **Fix:** `saveIndex()`
chmods 0660 after the rename and chowns to www-data when running as root;
a CLI `clearAll()` deletes files rather than rewriting the index;
`loadIndex()` checks `is_readable()` and logs once instead of warning on
every request. Drop the dev-only `chgrp user1` at 37 and 50. **Real.**

**Lines 139 and 140, array to string.** The cache key is built from `$_GET`;
`?a[]=1` makes a value an array, `preg_replace` returns null, and the key
collapses so `?a[]=1` and `?a[]=2` share one cached file. **Fix (owner,
2026-09-13): a request with an array query parameter is never served from
or written to the static cache.** A page asked for that way is a filter or
a search, and a cache miss is the safe failure; the key format does not
change. **Real:** a cache-key collision serves one page for another.

**Lines 237 and 240, undefined `status`.** The only index entry without a
`status` is `_config`, and a request for `/_config` produces the cache key
`_config`, so it is read as a page entry; a later `markAsNostatic('/_config')`
overwrites the config entry and `$index['_config']['enabled']` goes
undefined, which **turns the cache off site-wide**. **Fix:** store the
config under a key no URL can produce (one containing a `.`), migrate a
legacy `_config` in `loadIndex()`. **Real.**

### 4.6 `theme/scrolldaddy/views/login.php:48`, undefined `email` (scrolldaddy, 89 a day)

`login_logic()` sets `email` only when `?e=` carries a valid address; the
core `views/login.php` reads it with `?? null`, the theme copy reads it raw
and passes null to `htmlspecialchars()`. **Fix:** `$page_vars['email'] ?? ''`.
**Noise only.**

### 4.7 `plugins/event_manager/views/events.php:131`, undefined `$tz` (phillyzouk)

`$tz` is never assigned in the file (also used at 134); it was lost when the
view was rewritten for the plugin extraction. Null makes
`get_event_start_time()` fall back to the event's own timezone, which is the
right date for a listing. **Fix:** `$tz = 'event';` near the top, so the
intent is written down. **Noise only.**

### 4.8 `plugins/store/data/products_class.php:611`, undefined `$field_container` (dev)

In `output_javascript()` the associative `['value' => ...]` branch for
Question requirements sets its rule and then falls through to lines 609 to
611, which overwrite it with `$value`, `$message` and `$field_container`:
undefined when a Question requirement comes first, stale from the previous
positional entry otherwise. **Fix:** `continue;` after the associative
branch and reset `$field_container` per iteration. **Real:** Question
requirements get null or another field's client-side validation rule.

### 4.9 `theme/getjoinery/views/page_marketing.php:21`, method on null (developers)

The file is a page template that `views/page.php` requires with `$page`
bound. The route fallback resolves any bare path to a theme view, so
`GET /page_marketing` includes the template with no page and dies with a
500. **Fix:** guard the top of every page template the way
`logic/page_logic.php` already does: no `Page` in `$page`, `display_404_page()`.
**Real:** a 500 where a 404 belongs (and a canonical the marketing memory
already says must be 404).

### Tests for B4

- `tests/unit/static_page_cache_test.php`: a request with an array query
  parameter is neither cached nor served from cache; `/_config` cannot reach
  the config entry; `saveIndex()`
  leaves a www-data-readable file when run as another user (skip when not
  root).
- `tests/unit/pager_test.php`: a scheme-relative request URI yields a base
  URL and no warning.
- The existing store requirement tests gain a case where a Question
  requirement comes first and keeps its rule.
- `admin_user` page: a user in two groups renders two Remove buttons with
  ids; a user in none renders no button and no warning.

## Executor brief

Read this section first; it is the contract for building the packages
above.

**Order.** WP2 (B2) first, because jeremytunnell takes brute-force attempts
with password login on and no guard. Then WP1 (B1), WP5 (B3), WP6 (B4) in
any order; they touch different files. WP3 (tier 1) is **not** in this
brief and must not be started: it is `agent_tier1_recipes.md`, sequenced
after everything here by the owner. Nothing in this brief changes the Go
agent.

**Done means, per package:**

- WP2: the installer exists, is in `CORE_INSTALLERS`, `install.sh` calls it
  and carries no inline fail2ban recipe; the proxy vhost appends the
  forwarding header; the range list has one source; both tests above are
  green; the script has been run once on dev in override mode with its
  transcript in the report. It is **not** run on any node by the executor;
  the owner runs it on docker-prod by hand and the release runs it on
  jeremytunnell.
- WP1: uninstall on dev of a non-system test plugin (make one under
  `plugins/` for the test, never a shipped plugin) leaves the row
  `uninstalled`, queues `remove_plugin`, and the host timer on dev removes
  the directory within its tick; all three tests listed under B1 green;
  every `plg_status` reader inventoried in the report with its disposition.
- WP5: the heal-on-read test green; the notice appears on dev after a
  forced site-backup failure and clears after a success; `relay_runs` has
  no writer under the site root.
- WP6: each of the nine items fixed at the root named in its section, the
  four tests listed under B4 green, and no new warning in dev's error log
  across a browse of the pages touched.

**Standing rules.** Never commit; the owner commits. Never stage. Report
the by-path list of every file touched, grouped by package, so the owner
can commit each package on its own. Bump the `@version` of every class
touched. `php -l` and `validate_php_file.php` on every PHP file. Run
`php tests/run.php --changed` after each item and `php tests/run.php db
--changed` before reporting a package done. Docs under `docs/` describe the
end state only. Never edit `CLAUDE.md`, never edit anything under
`specs/implemented/`, never write to the database without asking, never run
a script against a node. Update this spec's status lines as packages land.

**Report shape.** Per package: green or not green with the runner's counts;
what was built, one line per file; the by-path list; anything found that
this spec did not predict, as B-items with the same numbering style, fixed
or left open with a reason. No narrative.

## Work packages

| WP | Scope | Ships as |
|----|-------|----------|
| WP1 | B1: `uninstalled` state, `remove_plugin` root request, `is_system` refusal, tests | release |
| WP2 | B2: `host_housekeeping.sh` in `CORE_INSTALLERS`, `install.sh` calls it, Apache jails behind `mod_remoteip` in proxy mode; run once by hand on docker-prod | release + one SSH |
| WP3 | moved: `agent_tier1_recipes.md` (recipe `fail2ban`, `host_report`, `host_converge`, the case, Host card, notices); sequenced after WP1, WP2, WP5, WP6 by the owner | agent + platform release |
| WP5 | B3: B2 region heal on read, site-backup failure notice, relay proof paths | release |
| WP6 | B4: the warning fixes, one commit per file group | release |

WP1, WP2, WP5 and WP6 are independent and can run in parallel and ship
first; WP3 is `agent_tier1_recipes.md` and follows them. Every WP ends with `php tests/run.php db --changed` green.

## Open questions

- Q1 (B1): settled 2026-09-13, all plugins uninstallable including files, `is_system` excepted.
- Q2 (B3): settled 2026-09-13, both profiles stay.
- Q3 (vocabulary): moved to `agent_recipes_and_vocabulary.md` Q2.
