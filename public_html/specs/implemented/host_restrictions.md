# Host Restrictions

## The problem in plain terms

On a multi-tenant host, each site belongs to a different customer, and that customer is the site's superadmin. A superadmin can install an unsigned plugin or page theme today: they read the warning, confirm with a second factor, and press **Install anyway**. Their code then runs with everything their site has.

We don't want that on a box we host for other people. More rules like this will come for particular kinds of box, so this spec builds one place to hold them and uses it for the first one.

The person being restricted is the site's own superadmin. So the rule can't be a site setting, which they can change, or a row in the site's database, which arrives with a restored backup they made. It has to belong to the host.

A customer has two ways to get code onto the host: upload it, or bring their site in as a copy or restore. The second way carries their whole code tree, core included, plus their `config/` folder, which holds the files that decide what this machine trusts. Both ways have to be closed (D3, D5).

## What changes for people

- **A tenant on a multi-tenant host** uploads an unsigned plugin or page theme. The page doesn't offer **Install anyway**. It says: "This host installs only signed plugins and themes." Signed packages install as before.
- **Unsigned style themes still install.** A style theme holds only stylesheets, fonts and images (`ThemeHelper::styleThemeRefusal()` decides this), so nothing in it runs. That's already why the warning is skipped for them (decided, owner Q1).
- **The shell path refuses too.** `php utils/install_extension.php ... --acknowledged` on a restricted host exits with the same sentence.
- **Moving a site onto a multi-tenant host** (site copy, or a restore) is refused if any of its code isn't exactly what we shipped: an unsigned plugin, a page theme we didn't build, or a core file edited on the old box. The refusal names each one, so the customer can remove it on their old box and copy again.
- **Single-tenant boxes, bare-metal installs and dev** have no restrictions file and behave as today. The one change everywhere (D5.2) is that a restore keeps this machine's own trust files rather than taking the backup's.

## Inventory: every way plugin, theme or core code enters a site tree

The restrictions only hold if every way in is covered. The inventory was done on 2026-10-13 by reading the code.

| Way in | Who can start it | Carries unsigned code? | Covered by |
|---|---|---|---|
| Upload on the Plugins or Themes page (`install_package` root request → `install_extension.php --staged`), including **Replace** | site superadmin | yes, with the acknowledgement | D3.1, D3.3 |
| `install_extension.php` from disk: an extension already in the tree with a row saying `unsigned`/`unlogged` | root request or shell | yes, no fresh warning | D3.2 |
| `install_extension.php` from disk on the **publishing box** (`PackageSignature::publisherBox()`: `config/agent_signing_key` exists) | root request or shell | yes, nothing is verified | D4 |
| Marketplace install by name (`install_plugin` / `install_theme`) | site superadmin | no: refused unless signed | as it is |
| Core upgrade (`upgrade.php`) | operator / management node | no: refused unless signed | as it is |
| New site (`install.sh site`) | host operator, from the host's own release archive | no: our archive | as it is |
| Restore (`restore_project.sh`) | host operator, from an archive the tenant may have made | yes: the whole `public_html`, `maintenance_scripts` and `config/` | D5 |
| Site copy and chain restore (`restore_chain.sh`): "the code part of a chain carries `public_html` whole" (spec `site_copy`) | management node, from the tenant's source site | yes, the same | D5 |
| Moving a site onto code volumes (`migrate_site_to_code_volumes.sh`) | host operator | no: same host, same tree | as it is |
| Code generator (`utils/scaffold.php`) | shell only; no admin page calls it | operator's own code | as it is |
| Content packs (`apply_content_pack.php`) | shell | no: database content only; plugin and theme state are outside a pack | as it is |
| The web user writing into the tree | — | not on a node: the tree is root's (`fix_permissions.sh --production`), and `config/` is `root:www-data` 750 | D6 keeps it so |
| `PluginManager::sync()` | — | registers and migrates what is already on disk; it brings no files | — |

Anything this table missed, such as a hand edit by someone with root or a future feature that forgets its gate, is caught after the fact by the daily code check (D8).

## Design

### D1. One file, owned by the host: `/etc/joinery/host_restrictions/restrictions`

One restriction per line, `name=value`, with `#` comments:

```
# joinery-managed: written by install.sh, read by every site on this host
unsigned_packages=refuse
```

- **On a Docker host**, the directory `/etc/joinery/host_restrictions/` is bind-mounted **read-only** into every site container at the same path. Mounting the directory rather than the file means a rewrite on the host (write to a temp file, then rename) reaches running containers without a rebuild. Containers run with only the six capabilities in `RUN_SPEC_CAPS`, and none of them can unmount anything, so nothing inside a container can remove or edit the mount.
- **On bare metal**, the same path is a plain root-owned directory (755) and file (644).
- Code reads the same path in both shapes.

**Why not in the site's `config/` folder**, which is how the disk allowance reaches a site (`DiskAllowance::ALLOWANCE_FILE`)? That folder belongs to the container and arrives with a restored backup (D5). A backup without the line would switch the restriction off. The disk allowance can live there because the XFS limit does the real enforcing; its file only makes the refusal friendlier. Here the file is the rule itself.

**Why named restrictions and not one "multi-tenant mode" switch:** a mode hides what is actually on, and different boxes will want different mixes. A name says what it does.

### D2. The reader: `HostRestrictions` (new core class, `includes/HostRestrictions.php`)

- `HostRestrictions::PATH` is the file path; `HostRestrictions::KNOWN` is the fixed list of restriction names and their allowed values. Today that's `unsigned_packages => ['refuse']`.
- `HostRestrictions::has(string $name): bool`, plus `all(): array` for display.
- **Fail closed:**
  - No directory and no file: no restrictions.
  - File present and parses cleanly: exactly the restrictions it names.
  - File present but unreadable, a line that doesn't parse, or not trusted by owner and mode: **every known restriction is on**, and `refusal()` gives the sentence that names why.
  - The owner check accepts uid 0 or the kernel's overflow uid (`/proc/sys/kernel/overflowuid`, normally 65534). That's because on a host with userns-remap, a file owned by the host's root shows inside the container as the overflow uid. Group- or other-writable is refused either way. Box-prove the overflow-uid case (WP5).
  - A name not in `KNOWN` is ignored by the reader. `install.sh` refuses to write one (D7), so it can only appear through a hand edit.
- It reads nothing but the file and has no database access, so root's PHP (`install_extension.php`), root's shell (through a one-line CLI, `utils/host_restrictions.php has NAME`, exit 0/1) and the web pages all ask the same question the same way.

### D3. Uploads: where `unsigned_packages=refuse` bites in `install_extension.php`

Root does the actual blocking. The pages only stop people from walking into a refusal.

1. **Upload branch.** Where `!$verdict->signed()` and the kind isn't `style`: when `HostRestrictions::has('unsigned_packages')`, refuse even with `--acknowledged`. Exit `EXIT_UNVERIFIED` with "This host installs only signed plugins and themes (`/etc/joinery/host_restrictions/restrictions`)." Check this before the acknowledgement is consulted. `unlogged` is refused the same way: it's already treated as unsigned everywhere else (D6 of spec `release_transparency`).
2. **From-disk branch.** The "previously acknowledged; installing under the unsigned restrictions" path (a row that already says `unsigned`/`unlogged`) is refused the same way. A row is not evidence on a restricted host: it may have arrived in a restored database.
3. **The Plugins and Themes pages** (`admin_plugins_logic.php`, `admin_themes_logic.php`, through `PackageInstallPage`). When the restriction is on, a refused unsigned upload shows the sentence in place of the warning and **Install anyway**. `PackageAcknowledgement::mint()` refuses too, so no step-up is asked for just to be turned down by root.

### D4. No publishing box on a restricted host

`PackageSignature::publisherBox()` answers yes when `config/agent_signing_key` exists. On that box, `install_extension.php` installs any plugin on disk without verifying it, and the host converger runs any plugin's host installer as root (`plugin_package_verified` in `_plugin_installers_start.sh` returns early). A site we host for a customer is never our publishing box. So when `unsigned_packages=refuse` is on, `publisherBox()` answers no, and the converger's shortcut is skipped (it asks `utils/host_restrictions.php`). D5.2 already stops the key file arriving by restore; this makes the exemption unreachable even if it somehow did.

### D5. Copies and restores onto a restricted host

Both `restore_project.sh` and `restore_chain.sh` (which site copy uses) put the tenant's archive into the tree.

1. **The code has to verify, all of it.** On a restricted host, after the code is laid down and **before the database is loaded or anything runs**, root checks the whole code tree with a new `PackageSignature::verifyTree($site_root)`:
   - **Core** (`public_html` and `maintenance_scripts`, minus `public_html/plugins/*` and `public_html/theme/*`) against the core manifest at the site root (`RELEASE_MANIFEST`, as `site_housekeeping.sh` already reads it). An edited, added or missing core file fails.
   - **Each plugin directory and each theme directory** against its own manifest, with `verify()`. A style theme that isn't signed passes and is reported, as at upload (Q1).
   - The answer is a list of everything that failed, with each verdict line, not just the first.

   If anything fails, the restore refuses and names each failure before anything is written. `restore_project.sh` checks the directory it has already unpacked, before the database step and before the dry run's exit. `restore_chain.sh` applies straight into the live site, so on a restricted host it first unpacks the chain into a scratch directory beside the site (leaving out `uploads`, `static_files` and `storage`, which hold nothing that runs), checks that, removes it, and only then applies. The verifier is the tree the restore script runs from, with that tree's keys, never anything inside the archive. The verdict always comes from the files, never from `plg_trust`/`thm_trust` in the restored database.

   **Only a site's own folders at the site root.** A hand-made archive could carry a `vendor/` (Composer's PHP, which no signature covers), a `deploy/` or a `public_html_last` (which an upgrade's rollback swaps in). On a restricted host the archive may hold only `public_html`, `maintenance_scripts`, `config`, `uploads`, `static_files`, `storage` and the release manifest at the site root; anything else is refused by name.

   `verifyTree()` is new, because `verify()` on the site root would read every plugin file as an "extra file". It walks only the two code roots, so `config/`, `uploads/`, `backups/`, `deploy/` and the other mutable folders at the site root are never in question. WP2 confirms against a real release whether the core manifest lists the shipped system plugins and themes or leaves them to their own manifests, and builds `verifyTree()` to match.

2. **This machine's trust files are this machine's (all hosts).** Today each restore keeps this machine's own copy of exactly two `config/` files (`Globalvars_site.php`, `backup_site_key`), and the two scripts carry that list separately. Everything else in `config/` comes from the archive, including the files that decide what the machine trusts: `release_verify_keys`, `release_statement_keys`, `transparency_log_keys`, `release_log_required`, `release_log_optional`, `tree_owner`, `agent_signing_key`, `release_statement_key`. A backup carrying its own `release_verify_keys` makes its own maker's signature count as ours. One carrying `tree_owner` naming `www-data` hands the tree to the web user at the next permissions run. One carrying `release_log_optional` opts the machine out of the public log.
   - **The fix:** one shared list, `MACHINE_CONFIG_FILES` in `install_tools/_restore_guard.sh` (sourced by both restore scripts), of the `config/` files that belong to the machine. That's the trust files above plus `disk_allowance` and `code_check`, which the host writes. A restore keeps this machine's copy of each one whenever this machine has one. That's right on every host: the converger rewrites the key files from the agent bundle anyway, and a same-machine restore loses nothing.
   - **When this machine has none,** the archive's copy is used, as today. That's what lets a management node rebuilt from its own backup get its `agent_signing_key` back. **On a restricted host, never:** the archive's copy is dropped and the converger writes what belongs there.

### D6. The tree stays root's on a restricted host

What actually keeps the web user from writing code is the tree's owner. `fix_permissions.sh` takes the owner from `config/tree_owner` when that record exists. With D5.2 the record can't arrive by restore, and on a restricted host `fix_permissions.sh` refuses any owner but root: it writes `root` and says why. It's one check that holds even if some later path lets the record arrive.

**Out of scope, on purpose:** root inside a container. Root in a container can already write PHP into its own tree, so a restriction on installing packages can't add anything there. Root inside a container is contained by the walls and userns-remap (spec `multi_tenant_docker_hosts`, WP5). The restriction is aimed at the superadmin, the web user and the archives a tenant hands us.

### D7. Who sets it: `install.sh` only, for now (decided, owner Q2)

- `install.sh docker --multi-tenant` (both the fresh-host path and the existing-Docker path) writes the multi-tenant set, `unsigned_packages=refuse`, through one script, `install_tools/host_restrictions.sh multi-tenant`. It refuses any pair not in the shell copy of the known list (`HOST_RESTRICTIONS_KNOWN` in `_host_restrictions.sh`), writes atomically, and sets the directory to 755 and the file to 644, owned by root. A gate compares the shell list with `HostRestrictions::KNOWN` so the two can't drift.
- `run_spec_args` adds `-v /etc/joinery/host_restrictions:/etc/joinery/host_restrictions:ro` for **every** site on a host whose directory exists. It's the platform's argument, like the capabilities, not a spec line, so a rebuild of an older container picks it up.
- **A site can't be born unrestricted on a restricted host.** On a host where Docker remaps user ids (`run_spec_docker_remaps_ids`, i.e. a multi-tenant host), `run_spec_args` refuses to build arguments when the directory is missing. The message names `install.sh docker --multi-tenant` as the fix.
- `run_spec_adopt` refuses bind mounts by name today. It accepts exactly this one: this source, this destination, read-only. Any other bind mount is still refused.
- **Changing a box's restrictions later** will be a management-node command (an agent word), so it needs no SSH. That's not built here; it gets built when the first restriction needs changing after install.

### D8. The daily code check: an incident when a site's code isn't what we shipped (decided, owner Q3)

Signing checks code when it's installed, not afterwards. Someone with root who edits a file in place, or copies a plugin directory in, goes through no install, so no signature is checked. For example: a customer's mail breaks, and someone hand-patches `includes/MailIdentityGuard.php` inside their container and copies in a fixed `mailbox` from dev.

Part of this is already noticed, but quietly. `site_housekeeping.sh` compares core with the release manifest in a container and writes a warning nobody reads; the agent then refuses that site's scripts. The converger skips a plugin's host installer whose signature fails and logs a line. Nothing checks themes after install, and none of it reaches a person.

**Extend the existing check rather than build a new one:**

- `site_housekeeping.sh` runs `verifyTree()` (D5.1, through `utils/verify_package.php --tree --tsv`) on a restricted host on every converge. The converger runs it daily, on a release change, when the active plugins change and after any root request, so no stamp of its own is needed. It writes the result to `config/code_check`, root's, 644: `ok <time>`, or `failed <time>` and one tab-separated line per failing package (kind, name, verdict, file). A verifier that answers nothing readable is `failed`, never `ok`. A host that stops refusing unsigned code drops the file.
- `host_report.sh` adds the per-site `code_check` field beside `health` and `answers`, read from the site's config volume. It also adds the host's own `host_restrictions`, so the management node knows which hosts should be checking. The management node keeps both, the same way it keeps the other per-site figures.
- A new incident source, `IncidentSourceSiteCodeUnverified` (`plane:site_code_unverified`), raises one incident per host (incidents are per node), naming each failing site and each package in plain words:

  > **Code on site `acme` isn't what we shipped** (host `mt-host-1`)
  > - core: `includes/MailIdentityGuard.php`: the bytes are not the bytes that were signed
  > - plugin `mailbox`: the bytes of `includes/relay_admin.php` are not the bytes that were signed
  >
  > This host only runs signed code. Find out how this got here and close that path, then put the shipped code back (an upgrade to the current release does that).

  It clears when a later check says `ok`. On a restricted host, a running site whose check is missing, unreadable or more than two days old is a warning: a check that stopped running is how a failure would go unseen.
- **It reports; it never turns anything off.** An edited core file can't be deactivated at all, and deactivating a plugin would break a customer's site for what may be our own fix. A miss means a gate is missing, and the incident names which code to look at.
- An unsigned style theme isn't a failure (Q1).

## Work packages

**Status (2026-10-13):** every WP built; WP5 box proof passed (below). Still to run live (in the live verification queue): the Plugins page in a browser on a real multi-tenant site, the incident raised through a joined host's agent once a release carries host_report.sh 1.21, and a real site copy onto a multi-tenant host.

- **WP1. Inventory.** Done 2026-10-13; the table above.
- **WP2. Reader + verification.** `HostRestrictions` and `utils/host_restrictions.php`; `PackageSignature::verifyTree()` after checking a real release's core manifest (D5.1); `publisherBox()` answering no when restricted (D4). Tests: unit test for the reader (absent, clean, unreadable, bad line, wrong owner, overflow-uid owner, group-writable, unknown name), using a fixture path the way `JOINERY_SITE_STATE_ROOT` is used. `tests/security/package_signature_test.php` for `verifyTree()`: a clean tree, an edited core file, an added core file, an unsigned plugin, an unsigned page theme, an unsigned style theme, every failure listed.
- **WP3. Upload gates.** D3.1–D3.3. Extend `tests/unit/extension_staging_test.php` with the refused-even-acknowledged case, the refused-from-disk case and the publishing-box case. Page sentence in the plugin page test.
- **WP4. Host side.** `host_restrictions.sh`, called from `install.sh`, the mount in `run_spec_args`, the refusal on a remapped host with no directory, and the adopt exception (D7). `_restore_guard.sh` and both restore scripts (D5.2), and the `verifyTree()` refusal in both (D5.1). The converger's publishing-box shortcut (D4). `fix_permissions.sh` root-only owner (D6). Gates: `site_run_spec` (mount present, refused without the directory on a remapped host, adopt accepts exactly this mount), `docker_multi_tenant` (the file is written on both paths), the shell-vs-PHP known-list check, a restore that keeps this machine's trust files, a restore on a restricted host refused for an unsigned plugin and for an edited core file, and the converger skipping the shortcut.
- **WP4b. The daily code check** (D8). `site_housekeeping.sh` (the daily stamp, the after-install/upgrade run, `config/code_check`), `host_report.sh` (the `code_check` field), `JobResultProcessor` (keeps it), `IncidentSourceSiteCodeUnverified` (registered in `IncidentSources`), and the server_manager docs. Tests: the housekeeping gate (once a day, rerun after an install, the file's contents), the host report gate (the field), and an incident source test (raised with each failure, cleared on `ok`, silent on an unrestricted host).
- **WP5. Box proof** on a multi-tenant scratch host: the file is written, the mount is read-only inside a container, the overflow-uid owner is accepted, an acknowledged unsigned upload is refused, a style theme installs, a site copy carrying an unsigned plugin is refused and the target is left as it was, a site copy of a clean site succeeds, a hand edit to a core file and to a plugin raises the incident on the management node and it clears after an upgrade, and a host-side rewrite reaches a running container without a rebuild.
- **WP6. Docs** (current state only): `docs/installation.md` (multi-tenant host: what it restricts), `docs/deploy_and_upgrade.md` (unsigned packages refused on a restricted host; a restore keeps the machine's trust files), `docs/backups.md` (the same restore rule), `docs/plugin_developer_guide.md` (the unsigned paragraph), and a short section on the restrictions file and `HostRestrictions::KNOWN` as the place to add the next restriction.

## Open questions

None. Q1 (style themes allowed), Q2 (`install.sh` only for now) and Q3 (the daily check reports and never turns anything off) are decided and written into the design above.

## Box proof (WP5), 2026-10-13

On a new test-account Linode (`hr-test`, Ubuntu 26.04, `install.sh docker --multi-tenant`, two sites at 512 MB with a 4 GB disk allowance). The sites run this build: core laid over the published 0.8.486, re-signed with a throwaway key that only those two sites trust, with their release-log requirement lifted; plugins and themes are the release's own, real-signed.

| Check | Result |
|---|---|
| T1 the host writes `/etc/joinery/host_restrictions/restrictions`, root's, 755/644; a second run writes the same file | pass |
| T2 every site mounts it read-only; inside, it is owned by 65534 and read cleanly; root in the container cannot write, delete, unmount or remount it | pass |
| T3 a rename on the host reaches a running container at once: a broken file reads as every restriction on, an empty one as none | pass |
| T4 no site container is made without the directory on a remapping host | pass after B-fix 1 |
| T5 a real installed site, its plugins and themes fetched from the release server, passes `verifyTree()` after running for minutes: nothing written at runtime lands in the code folders | pass |
| T6 the installer refuses an unsigned plugin and page theme with and without `--acknowledged`, and from disk with a row saying `unsigned` (control with the restriction off: it installs); a style theme installs; `publisherBox()` is false with a key file present; `mint()` refuses; the page shows the sentence and no button | pass |
| T8 `fix_permissions.sh` keeps the tree root's when the record names another account | pass |
| T9 a hand-edited core file, an unsigned plugin and an edited plugin file fail the converge's check; the box's own host report, through dev's sanitiser and `plane:site_code_unverified`, is a critical incident naming the site and each package; putting the code back clears it; a running site with no check is a warning; the site's own converger rewrites the check | pass |
| T10 `restore_project.sh`: the site's own backup goes through; an unsigned plugin, a site-root `vendor/` and an edited core file are refused before anything is written (a real run: no database step, no file); an older archive's `deploy/` and `public_html_last` are set aside; an archive's key file, owner record and check result are never taken, its signing key dropped | pass after B-fixes 2 and 3 |
| T11 `restore_chain.sh` (site copy): a clean chain applies, its trust files removed; an unsigned plugin, an edited plugin file and an unexpected top-level folder are refused before anything is written; no scratch directory is left | pass after B-fix 3 |
| T13 the converger never runs an unsigned plugin's host installer on a restricted host, even with a publishing-box key file present (control with the restriction off: it runs it as root) | pass |

Bugs the box found, each fixed and gated:

1. **A refused site left an image, a network and a run spec behind.** `run_spec_args` refused only after `install.sh site` had built them. `install.sh` asks `run_spec_restrictions_present` in its limits check, before anything is made.
2. **Every backup dropped signed code.** Both backup scripts skipped any directory named `vendor` at any depth, so `public_html/assets/vendor` (argon2, chart.js, uikit) and any plugin's own Composer tree never reached a backup or a site copy, and a restored tree was not what was shipped. `vendor/` is skipped at the site root only.
3. **Archives carried the upgrade's own trees.** `backup_project.sh` and a whole-tree chain archive carried `deploy/` (and would carry `public_html_last`, `upgrades/`), which a restore would put back. Both leave them out, and neither restore script takes them, on any host.

Not done on the box: the Plugins page in a browser (the sites have no public names); the page's output was rendered on the box from the live restriction instead. The host report's new fields travel through the agent unchanged, so they reach the plane once a release carries this `host_report.sh`; the box's report was evaluated on dev directly.

