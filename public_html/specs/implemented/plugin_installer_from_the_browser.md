# Plugin Installers From the Browser

**Status:** Built 2026-10-29 (D1–D5, docs, tests). Verified on dev (localdev): `plugin_installer_request` gate 34/34, `provisioning_refused` 33/33, `root_request` 120/120, `vault_health` 108/108, `host_converger` 135/135, the safe `--changed` run 50/50. In the browser, persona_browser's Run setup again queued a request, the converger carried it out as root, the panel showed Done with the transcript, and the record moved to the new run. The Mailbox checks (Run setup again restarting Postfix once, and the unmet-check button with Postfix stopped) need a box whose mailbox package verifies, and are in the live verification queue under this spec's name.

Where the build departs from the design below:
- **office_editor** also declared `script`; it now declares `fixed_by_installer` (0.1.5). Without it its check would report `error`.
- **Mailbox's spam scanner messages** (`InboundEmailHealth`) named `sudo bash …provision_spam_scanner.sh install`; they point at Run setup again, and `MailboxSpamPolicy::installCommand()` is gone.
- **The kind list** held thirteen kinds, not nine; it holds fourteen.
- **The page renders the status line server-side** (a file read per plugin), and the button reloads the page at `?installer_request=ID`, where the existing root-request panel shows it. `plugin_provisioning_check` carries `installer_status` and `actor_state` as D4 says.
- **D5 names only plugins whose outcome fails a run** (`failed`, `refused-untrusted`). An unsigned plugin is skipped on every run without the runner recording a failure, so naming it blamed it for a core installer's failure (seen on localdev).
- **An outcome `inactive`** (switched off since the pass began) maps to exit 2 and writes no record.

Reviewed by reviewer2 2026-10-29; every finding taken:
- **B1** A failed request overwrote the run's `installer-failed` with `request-failed`, so the D5 notice vanished exactly when Run setup again hit a failing installer. `request-failed` now only replaces a clean `converged`.
- **B2** `fix_permissions.sh`'s data-set sweep handed `cache/plugin_installers`, `logs/plugin_installers` and `logs/root_requests` back to www-data 770 between converges, so the web user could plant a link at a name root writes through. The three directories are pinned out of the sweep (fix_permissions 4.11); the runner takes them back to root:www-data 750 and removes each file before writing it. `logs/root_requests` had the same hole before this work. The gate checks that a planted link is not followed.
- **B3** `HostConvergerNotice::facts()` read every plugin's record on every admin page; it reads them only when the run reported a failed or refused installer. The Plugins page reads the root actor once.

## The problem in plain terms

Some plugins need software on the server itself. The Mailbox plugin, for example, needs Postfix and rspamd. On most boxes this already happens with no shell: the host converger (a root timer every install leaves behind) notices when a plugin is turned on and runs that plugin's installer within a minute.

There are two problems with what the admin sees around that:

1. **When setup is missing, the admin is told to SSH in.** An unmet setup check on the Plugins page prints `sudo bash /var/www/.../install_email.sh` (`PluginProvisioning.php`, `script_command`; `utils/check_provisioning.php` prints the same). When an installer fails, the header notice says to reinstall the converger, and the vault health check says to read a log on the host. We are retiring SSH, so on most boxes none of these can be followed. Nothing on any page says whether the converger already tried, or what happened when it did.
2. **A failed install waits a day before it tries again.** The converger records "done" before it runs, so a run that dies halfway isn't retried every minute. If the Mailbox installer fails on a temporary problem (an apt mirror is down, the disk is briefly full), the next automatic attempt is up to 24 hours away. A managed node can force a run from Server Manager. A self-hosted box has no way to do that from the browser.

There is also a bug:

- **B1.** Mailbox's "Hardened relay reachable" check names `provisioning/provision_relay.sh` as its fix. That script "builds the minimal, hardened, disposable VPS that fronts the public MX", so the Plugins page tells a site admin to turn their own box into a relay. An unreachable relay is fixed from the Relay tab and Server Manager, never by a script on the site box.

## What the admin gets

On `/admin/admin_plugins`, every active plugin that has a host installer shows one line about its last install run:

- `Host setup: installed 2026-10-11 14:02 UTC (release 0.8.484)`, or
- `Host setup: failed 2026-10-11 14:02 UTC` with the last lines of its output, or
- `Host setup: not run yet`, followed by what will happen next, which depends on the machine's root actor (`RootRequest::actorState()`):
  - **present:** "the server runs it shortly". Under systemd that is seconds; under cron (containers) it is up to a minute.
  - **absent or stale:** `RootRequest::actorWarning()`.

Beside the line is a **Run setup again** button. It queues a root request. The existing root-request panel (`AdminPage::root_request_panel`) shows the request until it is done or failed, then shows the installer's full output. The output appears when the run finishes, not as it goes (see "One request, one run" below for why). The button replaces the `sudo bash ...` line on unmet setup checks.

When the machine has no working root actor, the button still queues the request, and the page shows `actorWarning()` above it, the same as every other root-request button.

## Design

### D1. A new root request kind: `run_plugin_installer {plugin}`

Add `run_plugin_installer` to `RootRequest::KINDS` and to the runner's kind list in `_plugin_installers_start.sh`.

**Why it is safe.** A request file only proves that something running as the web user wrote it. This kind adds no new power. `run_one_plugin_installer` takes one input, the plugin name, and works out everything else from the root-owned tree: the manifest, its `host_installer`, the check that the path stays inside the plugin directory, `installer_is_trusted` (root-owned), and `plugin_package_verified` (signed by us). The quiet-site, data-root and upgrade-lock gates all run before the request loop, so a request cannot run an installer anywhere the daily pass would not. A forged request can make something happen sooner, but it cannot change what happens. Two rules keep that true:

- **(a) The handler reads exactly one field from the request, `plugin`, and ignores the rest.** The D2 record's `installer` and `release` come from the manifest and `VERSION`, never from the request.
- **(b) The name must match the runner's own pattern, `^[a-z][a-z0-9_]{1,49}$` (the one `--only-plugin` uses), before it is used in a path or as a shell word.**

**An invariant changes, and the spec names it.** "Nothing a web request wrote ever becomes a shell word" is stated in `RootRequest.php`'s header, in the runner's comment above the request loop, and in `tests/unit/root_request_test.php`. Because this kind runs inside the runner, the plugin name becomes one quoted shell word that has passed the regex check. That is safe under (a) and (b). All three statements are reworded to say so, and to say that this is the only kind that does it.

**Validation.** A name that fails the regex, a plugin that is not active, or a plugin with no `host_installer` sends the request to `failed/` with exit 2 and a one-line reason in its transcript. That is stricter than `--only-plugin`, which exits 0 in those cases. Exit 2 is right for a request, because the page has to show that it did nothing.

**When the active set can't be read, the request stays queued.** If `read_active_plugins` fails (the database is down), the handler doesn't treat the plugin as inactive. It leaves the request where it is. The converge already drops its stamp in this case so the next tick retries, and that tick carries the request out.

**Dispatch happens inside the runner, not through `utils/root_request.php`.** The runner takes its lock with `exec 9>>LOCK` then `flock -w 600 9`. A second copy of the runner, started from a request, would open the lock file again as a new open file description, wait the full 600 seconds on its own parent, print "another run holds the lock", and exit 0. The request would then land in `done/` although nothing ran. So the request loop handles this kind itself, calling `run_one_plugin_installer`.

`utils/root_request.php` still gets a `case 'run_plugin_installer'`. It refuses with exit 2 and the message "this kind is carried out by the host converger itself; this runner is older than the tree". That keeps the dispatcher test's rule that every kind has a case. It also means an old runner paired with a new tree fails loudly instead of claiming success.

**One request, one run (no double install).** A queued request triggers a full converge (`REQUESTS_WAITING=1`), and a full converge runs every active plugin's installer before it reaches the request loop. Without care, one click would run every installer once and Mailbox's twice, restarting Postfix twice. So `run_plugin_installers` records the names it ran in this pass. When the request handler finds its plugin among them, it runs nothing. It copies that run's D2 log into its transcript and maps the recorded outcome to its exit code. The full pass still runs every installer. That is what the converger does whenever it runs, and a request is one of the things that make it run.

**The request's installer run happens in the pass's plugin loop, not in the handler.** Every path that reaches the request loop has already run `run_plugin_installers` in the same pass: a converge started by the request, a container start, an upgrade, or a managed-node job. So for a valid request, the handler only ever copies. That is why the panel shows the output when the run finishes rather than as it goes; streaming would mean claiming requests before the plugin loop and coupling the two loops. If the plugin is somehow not in this pass's set, the handler still calls `run_one_plugin_installer` itself, so a request is never dropped.

**Duplicate clicks.** `RootRequest::submit` returns the id of an existing `run_plugin_installer` request for the same plugin that is queued **or running**, so a second click doesn't start a second install. `pending()` doesn't carry the request's arguments, so the check reads each request's body with `request()`.

**Exit codes.** `run_one_plugin_installer` keeps returning 0 on every path, so a full run still never exits non-zero. It also sets `INSTALLER_OUTCOME`, which the handler maps to the request's exit code:

| `INSTALLER_OUTCOME` | Request exit |
|---|---|
| `ok` | 0 |
| `failed` | 1 |
| `refused-untrusted`, `refused-unsigned`, `missing` | 2 |

### D2. A per-plugin record of the last install run

The page needs to know what happened last time, for each plugin. Today the converger only records its overall outcome (`cache/host_converger.last`).

`run_one_plugin_installer` writes a record for every run, however it was started: a full run, `--only-plugin`, a D1 request, or a managed node's `run_plugin_installers` or `run_installer plugin:NAME` job.

- **`cache/plugin_installers/<plugin>.json`** holds `{plugin, installer, started_at, finished_at, outcome, exit_code, release}`, where `outcome` is `INSTALLER_OUTCOME`.
- **`logs/plugin_installers/<plugin>.log`** holds the full output of that run. Write it as `<plugin>.log.tmp`, then `mv` it into place. Truncating the old file instead would let a daemon from the previous run, still holding its descriptor, write into the new log at its old offset.

Root creates both directories as `750 root:www-data`. Root writes the files as `640 root:www-data`, the same arrangement as root-request transcripts: root writes, the page reads.

**Output goes to a file, never through a pipe.** `run_one_plugin_installer` runs `bash "$INSTALLER" > "$TMP" 2>&1`, where `$TMP` is the D2 `.tmp` file. Afterwards the file is moved into place and copied to the runner's stdout. A D1 request's transcript is a copy of that log.

**Refusals are written into the log too.** The refused and missing outcomes stop before the installer runs: `installer_is_trusted`, `plugin_package_verified`, and the missing-file check. Today their reason goes only to the runner's stderr. `run_one_plugin_installer` also writes that one-line reason into the D2 log. Otherwise the log, and the request transcript copied from it, would be empty in exactly the cases where the admin most needs to know why.

Nothing is piped through `tee` and nothing writes to the journal. The installer contract only asks installers to close descriptors *above* stdio, so even a correct installer can leave a daemon holding stdout. A pipe would then never close and the runner would hang.

**The runner's stdout lines don't change.** The management node reads the runner's output: `JobResultProcessor.php` matches `plugin installers: X: ok`, `WARNING - `, `- skipping`, `- refused` and `- host installer skipped` to decide each node's green or red. Those lines stay on stdout, word for word, in the same order relative to the installer's own output.

A small reader, `PluginInstallerStatus::forPlugin($name)`, returns the record plus the last 20 lines of the log. It returns null when there is no record.

### D3. Setup checks name the installer, not a script path

Each provisioner declares whether the plugin's own installer fixes it. The declared field changes from a script path to a flag:

```json
"fixed_by_installer": true
```

Root only ever runs the plugin's declared `host_installer`, so a second, separate script path in each check would be a second list of files that could run as root. With the flag, an unmet check that has `fixed_by_installer` shows the D2 status line and the **Run setup again** button. A check without it shows only its reason. Those failures are configuration the admin fixes on a page, such as DNS or a relay.

`script` and `script_command` are removed from `PluginProvisioning`, the page's JavaScript and `utils/check_provisioning.php`. That CLI prints the D2 status line in their place.

**Where the declaration is checked.** No plugin manifest validator knows about provisioners. `PluginProvisioning::runOne` does the check: it returns state `error` with a message naming the problem in two cases:

- the declaration still carries `script` (the message names `fixed_by_installer`);
- it declares `fixed_by_installer` on a plugin with no `host_installer`, so the button would have nothing to run.

Mailbox's `plugin.json`:

| Check | Today | After |
|---|---|---|
| `inbound_mail_server` | `script: install_email.sh` | `fixed_by_installer: true` |
| `search_index_engine` | `script: install_email.sh` | `fixed_by_installer: true` |
| `content_spam_scanner` | `script: provision_spam_scanner.sh` | `fixed_by_installer: true` (`install_email.sh` always runs the scanner's `install`). Its `details` drop "provision_spam_scanner.sh install repairs drift" and say the mail setup repairs it. |
| `relay_reachable` | `script: provision_relay.sh` | no flag. Its `details` name the Relay tab. **Fixes B1.** |

### D4. API action

`plugin_installer_run` (superadmin, POST) goes through `/api/v1` with the browser-session credential, following `docs/api.md`. It takes `{plugin}`, checks that the plugin is active and declares a `host_installer`, calls `RootRequest::submit('run_plugin_installer', ['plugin' => $name])`, and returns `{request_id, actor_warning}`. The Plugins page posts to it and mounts the root-request panel on the id it gets back.

`plugin_provisioning_check` adds `installer_status` (from D2) and `actor_state` for each plugin, so the page renders the status line in the same request that renders the checks.

### D5. Failure notices point at the Plugins page

Once D2 exists, a failed plugin installer has a page that names it, with a button.

- **`HostConvergerNotice::forState`:** a fresh run whose outcome is `installer-failed` (or `installer-refused`) says which plugin failed, read from the D2 records, and links to `/admin/admin_plugins`. Today it tells the admin to reinstall the converger (`sudo bash install_host_converger.sh`), which fixes nothing when one plugin's installer failed. The reinstall command stays for the cases it does fix: no converger installed, or a stale one.
- **A core installer can fail too.** Core installers set the same `installer-failed` outcome and write no D2 record. When no D2 record shows a failed or refused plugin, the notice says a core installer failed and names `logs/host_converger.log`, since a core installer has no page or button.
- **`VaultHealth`**'s "see logs/host_converger.log on the host" becomes the Plugins page link when a plugin failed, and stays as it is when a core installer failed.

## What stays as it is

- **Automatic runs.** The converger still runs installers when the release or the active plugin set changes, and once a day. A failed installer is retried automatically the next day. The button is for "now".
- **Server Manager's Run Plugin Installers job** on managed nodes runs every active plugin's installer. It keeps doing that. Its runs write D2 records too, so the site's Plugins page shows them.
- **Containers** have a root actor. The converger's own core installer writes `/etc/cron.d/joinery-host-converger` when PID 1 is not systemd, and the container start runs the runner, so `actorState()` reads present.
- **A box with no root actor at all** still needs one shell step to install the converger, or Server Manager's job on a managed node. Nothing in the browser can create root's timer. `HostConvergerNotice` already tells the superadmin this.

## Files

- `includes/RootRequest.php`: the kind, the duplicate check in `submit`, the reworded invariant, and a version bump.
- `utils/root_request.php`: the refusing `case`.
- `maintenance_scripts/install_tools/_plugin_installers_start.sh`: the kind in the list, and the "same nine" comment becoming ten; dispatch inside the runner (regex, one field, leave queued on an unreadable active set, reuse this pass's run); `INSTALLER_OUTCOME`; the D2 record, log and directories, with refusal reasons written into the log; stdout lines unchanged; and a version bump.
- `includes/PluginInstallerStatus.php` (new): the D2 reader.
- `includes/PluginProvisioning.php`: `fixed_by_installer` replaces `script` / `script_command`, plus the two declaration errors.
- `utils/check_provisioning.php`: the status line replaces `script_command`.
- `logic/plugin_installer_run_logic.php` (new): the D4 action.
- `logic/plugin_provisioning_check_logic.php`: `installer_status` and `actor_state`.
- `adm/admin_plugins.php`: the status line, the button and the panel; the `<pre>` command goes away.
- `includes/HostConvergerNotice.php`, `includes/VaultHealth.php`: D5.
- `plugins/mailbox/plugin.json`: the D3 table, and a version bump.
- `docs/plugin_developer_guide.md`: the provisioner field table and the two other `script` mentions; the `host_installer` section (the button, D2 records); and the paragraph that sends a bare-metal admin to Server Manager.
- `docs/deploy_and_upgrade.md`: one sentence on the button beside the converger and `--only-plugin`.

## Tests

- **`tests/unit/root_request_test.php`:**
  - The kind-list checks still pass: the runner and the dispatcher both name the kind.
  - The count of kinds pinned at 9 becomes 10.
  - The "nothing becomes a shell word" assertion is reworded per D1.
  - `submit` returns the existing id for a second request on the same plugin, both while the first is queued and while it is running.
- **`plugins/mailbox/tests/spam_filtering_test.php`:** the assertion that `content_spam_scanner.script` is `provision_spam_scanner.sh` becomes an assertion that `fixed_by_installer` is true.
- **Unit, `PluginProvisioning`:**
  - It emits no `script_command`.
  - A declaration carrying `script` returns `error`.
  - `fixed_by_installer` without a `host_installer` returns `error`.
- **Unit, `HostConvergerNotice::forState`:** `installer-failed` with a failed D2 record names the plugin and links to the Plugins page, not the converger reinstall. With no failed D2 record, it says a core installer failed and names the log.
- **Gate** (`tests/integration/`, using a fixture tree with `JOINERY_ACTIVE_PLUGINS`). Like `host_converger_gate.sh`, it runs a copy of the runner with the root-only check in `run_root_requests` removed, so an unprivileged run reaches the request loop.
  - A queued request for an active plugin runs that plugin's installer exactly once, including when the same pass's full run already ran it. It writes the record and the log.
  - Requests that should do nothing fail with exit 2 and run nothing: an inactive plugin, a plugin with no installer, a name that fails the regex, and a request carrying extra fields that would point elsewhere.
  - With the active set unreadable, a request stays queued.
  - An installer that exits 1 records `failed`, and its request exits 1 with the output in its transcript.
  - An installer refused as untrusted or unsigned leaves its one-line reason in both the D2 log and the request transcript.
  - An installer that leaves a background process holding stdout doesn't hang the runner.
  - The runner's `plugin installers: X: ok` and `WARNING - ` lines appear on stdout unchanged.
- **Browser, on dev:**
  - Turn on Mailbox. The status line moves from "not run yet" to "installed".
  - Press **Run setup again** and watch the panel finish with the installer's output. Check that Postfix restarted once.
  - Stop Postfix on purpose. The unmet check shows the button and no `sudo` line.
