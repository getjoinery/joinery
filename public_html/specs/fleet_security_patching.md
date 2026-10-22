# Fleet Security Patching

**Status:** Draft, 2026-10-21. Written after the PHP2Shell patch day by oldmigration at the
owner's request ("write all of these up in a spec and we'll start working through it").
Q1–Q6 are decided (2026-10-21). Q1 and Q4 went to their own specs. WP1–WP6 and WP7's warning
were released in 0.8.519 (2026-10-22). WP8–WP10 and WP3's lost-.deb fix are built (not released).
Open: Q7, and Q8 before WP7's automated live test can be built. See "Progress" below.

## The owner's goal in plain terms

When Ubuntu ships a fix for something our sites run, every site gets it the same day, without
anyone typing commands into a terminal, and one page shows that it happened. Today it took most
of an afternoon and hand-run commands, because every automatic path was broken or missing.

Four things have to be true:

1. **We can see it.** For every machine and every site container: which PHP it runs, whether
   the code actually serving requests is the patched one, what security fixes are waiting, and
   whether a reboot is owed.
2. **We hear about it.** A security fix waiting for something we run becomes an incident within
   the hour, naming the sites it affects.
3. **One action fixes it**, for one site or the whole fleet, containers included, and says what
   changed.
4. **Nothing is outside the system.** Every machine we pay for is either managed (and so patched
   by all of the above) or on a list to be converted or retired.

## What happened on 2026-10-21

PHP released 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35 for CVE-2026-109618 and -109619 (critical,
unauthenticated remote code execution). Ubuntu published USN-8948-1 at 13:41 UTC with fixed
packages for 22.04, 24.04 and 26.04. What we hit, in order:

- **No view.** Nothing reported PHP versions. Finding out meant opening each node page, SSH, and
  `docker exec` into containers one by one.
- **The button did not work (B9).** *Install security updates now* waited ten minutes and said
  busy on every Ubuntu machine: it took `unattended-upgrade-shutdown`, which always runs, for a
  package run. It had never run on a real machine; its gate stubbed `pgrep` as "nothing running".
  **Fixed and live in 0.8.517** (`install_security_updates.sh` 1.1, gate 42/42).
- **Containers had no path at all (B10).** The nightly container refresh (`site_refresh.sh`)
  installs only on site-engine hosts; docker-prod is plain Docker on 24.04, so its six sites never
  patch. Container nodes have no button. *Replace* rebuilds on the current base, not a fresh one.
- **An in-place container patch did not take effect.** Nothing restarts php-fpm in a container
  after an upgrade; all six kept serving the old binary (`/proc/PID/exe` "(deleted)") until a
  `kill -USR2`. Two of six runs also lost a downloaded `.deb` mid-unpack (both straddled a minute
  boundary; cause not found) and left 14 packages half-installed until `apt-get -f install`.
- **Unknown machines.** Three old sites surfaced only because the owner remembered them; one was
  not even live. Two ran PHP that no one would ever patch.
- **The release was slow.** The publish was held by another session's files, and `work --held`
  named the wrong session.
- **Side findings from the xandyliberato.com move the same day:** the IP swap failed on Linode's
  newer interfaces (B8); the converted site had no mail service set, so nothing would have sent;
  the theme's compiled CSS lacked classes its views used (the home page video collapsed).

Everything was patched by 15:50 UTC, by the owner pasting commands. Tracked in memory:
`project_todo_security_updates_b9_b10.md`, `project_todo_ip_swap_linode_interfaces.md`,
`project_patch_day_retro_2026_10_21.md`.

## Owner decisions (2026-10-21)

- **Q1 — docker-prod.** Its sites move to an engine host by site copy, planned in its own spec,
  `docker_prod_to_engine_host.md` (owner: "lets create a side spec for that specific move"). The
  move waits for the encryption steps (2026-10-22). Until then, docker-prod patches itself nightly
  (WP4).
- **Q2 — Install a found fix at once (b).** When the hourly check finds a security fix for what
  faces the internet (PHP, OpenSSL, Apache, PostgreSQL: WP6's critical list without the
  kernel, which needs a reboot), the machine installs it then, and replaces its site containers if it is a container host. A reboot is always a person's choice.
  - **As built:** the at-once install is the nightly run, early. Unattended-upgrade has no
    "this package only", so every allowed fix goes in with the critical one, and their services
    restart then rather than at night (reviewer2). When no critical fix is waiting, other fixes
    wait for the nightly run.
- **Q3 — Check hourly.**
- **Q4 — A website theme's home page** moved to its own spec, `homepage_follows_theme.md` (owner:
  "this needs to be thought through and it probably needs its own spec"), with B12.
- **Q5 — The publish page warns (b).** It lists each changed agent action with no recorded
  real-server run and asks before going ahead.
- **Q6 — No keys for other Linode accounts** (owner: "not going to bother with keys for those
  right now. We'll have to work around it"). WP8 lists those machines by hand.

## Work packages

Order is the build order. WP1 and WP3 close today's gaps; the rest make it routine.

### WP1 — Every report says what PHP is running

- The agent's `check_status` (`joinery-agent/primitives/observe_check_status.go`), which runs on
  bare metal and inside each site container, adds `php`: the installed package version of each
  `phpX.Y-fpm` / `-cli`, the php-fpm master's PID and whether its `/proc/PID/exe` is the installed
  binary or "(deleted)" (`running_current: true|false`), and the age of the oldest worker.
- `host_report.sh` adds `security_pending`: the packages with an upgrade waiting in the release's
  `-security` pocket (and the platform's outside PHP/PostgreSQL repositories where
  `51joinery-platform-repos` names them), from `apt-get -s` after a list refresh at most once per
  Q3's interval. Each entry: package, installed, candidate. Inside a container the same comes from
  `check_status` (containers run no `host_report`).
- `services_needing_restart` (already in `install_security_updates`' result) is reported by both,
  from `needrestart -b -r l` where it is installed and from `/proc/*/exe` "(deleted)" otherwise.
- JobResultProcessor keeps each in the shape it keeps the rest; tests pin the shapes.

### WP2 — The fleet security page

One page under Server Manager, one row per machine and per site container: OS release and its
support end, PHP package versions, **running current / old code still serving**, security fixes
pending (count, the packages on hover), when security updates last ran, reboot owed and since
when. Red when old code is serving or a fix has waited past Q3's interval; amber for a reboot
owed more than a day. Each row links to its node and carries the WP3 action.

### WP3 — Patch containers in place, and reload what was upgraded

- `install_security_updates.sh` (1.2): after `unattended-upgrade`, every php-fpm master whose binary
  is "(deleted)" is reloaded with `USR2` (no downtime), on any machine; it reports
  `reloaded: [...]` and `still_on_old_code: [...]` (empty on success). A systemd machine's packages
  restart their own services; the check still runs and still reports.
- A half-finished package run (`dpkg --audit` non-empty) is finished first with
  `apt-get -f install` and reported (`repaired: true`), instead of `unattended-upgrade` refusing on
  a broken cache.
- **The button on container nodes.** The node page offers *Install security updates now* for a site
  container (the primitive already runs there; only the Machine card hid it).
- **The lost `.deb`.** Reproduce on a test host: run the script in a container across a minute
  boundary with the host converger's cron active, and find what removes
  `/tmp/apt-dpkg-install-*`. Fix the cause, not the symptom.
- What a container installs lasts until it is made again on a fresh base; WP4 is the lasting fix.

### WP4 — Containers patch themselves (B10)

- `host_housekeeping.sh` 10c installs `joinery-site-refresh.timer` on a Docker host as well as on an
  engine host. `site_refresh.sh` and `recreate_site.sh` already handle both. It is proven first on
  a test Docker host: a pending fix is found, built and swapped, and a night with no fix does
  nothing. Then docker-prod gets it.
- docker-prod's sites move by `docker_prod_to_engine_host.md` (Q1).
- **Refresh now:** a host action that runs `site_refresh.sh run` on demand. It builds a fresh base
  when a fix is pending, then replaces each due site one at a time. It is shown beside the refresh
  status. Per Q2 it also runs when the hourly check finds a fix on WP6's critical list.
- *Replace* says which base it uses and offers "on a fresh base" (build first, then replace).
- A host with site containers and no refresh check in two days raises an incident ("this host's
  site containers are not being refreshed").

### WP5 — Patch the whole fleet with one action

A Server Manager action, *Install security updates everywhere*: one machine or container at a
time (a site's container after its host), each job's result in a table as it lands (outcome,
packages changed, reloaded, still on old code, reboot owed), stopping on a `failed` and
continuing past `nothing_to_install`. The same runner serves WP6.

### WP6 — A pending fix becomes an incident (and, per Q2, an action)

- `security.fix_pending` opens on a machine or container whose WP1 report lists a pending
  security fix; critical when the package is PHP, OpenSSL, PostgreSQL, Apache or the kernel; it
  names the packages and offers WP5's action for the machines it covers.
- Per Q2, a machine whose own hourly check finds a fix on the critical list (not the kernel) installs it at once:
  `install_security_updates` on a machine, or the WP4 refresh on a container host. The incident
  records what happened instead of waiting. Other fixes wait for the nightly run, and the
  incident says when that is.
- `security.old_code_serving` opens while any `running_current` is false for more than ten
  minutes.
- Optional: Ubuntu's notices feed (`ubuntu.com/security/notices`) sets the incident's priority
  from Ubuntu's own rating. The node's apt check stays the trigger; the feed only labels it.

### WP7 — A new agent word runs once on a real machine before release

- docs/testing.md: a gate that stubs a system tool models what a stock Ubuntu machine runs
  (`unattended-upgrade-shutdown`, `systemd`, `cron`, the agent itself), never "nothing".
- A record of live proofs (word, release, test server, job id, date) beside the agent vocabulary;
  the publish page warns about any word without one (Q5).
- The proof is a live-tier test, not a hand run. It makes a throwaway server in the test account
  (`test_server.php`), installs the release being prepared, runs each changed word through the
  agent, records the result, and deletes the server and its DNS.

### WP8 — Every machine we pay for is on a list

- Server Manager lists every instance on each connected cloud account (it already reads them for
  the transfer watch), marks the ones that are not managed nodes, and shows their image and age.
- Manual entries for machines outside connected accounts (address, purpose, owner, retire-by).
- An Ubuntu release past standard support (20.04 since mid-2025) is red; a machine nobody
  manages and nothing serves from is flagged to retire.
- Per Q6, accounts the platform holds no key for (the owner's personal Linode account) are not
  read. Their machines are hand entries. Managed nodes appear whatever account holds them, since
  they report through their agents. A hand entry carries the date it was last confirmed, and goes
  amber after 90 days so the list is checked again.

### WP9 — The emergency publish

`work --held` names the session whose prepared commit holds a file before falling back to its
shell guess (2026-10-21 it named oldmigration for drive-b3's `api_keys_class.php`). This is the
owner's local `~/work-tool` repository.

### WP10 — Moving and converting sites

- **B8:** `LinodeComputeDriver::createInstance` asks for `interface_generation` = `legacy_config`
  for machines a swap may target; `IpSwapMove` preflight refuses, with the remedy, when either
  machine is on Linode interfaces.
- **A site that cannot send mail is an incident:** users exist and `email_service` is blank, or
  the provider's `validateConfiguration()` fails.
- **Q4 and B12** are `homepage_follows_theme.md`.
- **A theme CSS check:** a safe-tier test that every class a theme's views use is defined in the
  theme's own compiled CSS (where it ships one).
- **Test DNS:** the test-cloud reaper (`TestCloudCleanup`) removes the `joinerytest.com` records
  pointing at what it deletes. `maintenance_scripts/dev_tools/test_server.php` 1.1 already does
  this for manual deletes and adds `dns-list` / `dns-delete --stale` (uncommitted).

## Open questions

- **Q7 — On a container host, should "Install security updates everywhere" replace the
  containers instead of patching each one in place?**
  - In place (as built): each container installs its fixes and reloads PHP. Nothing goes down,
    but the fix lasts only until the container is next replaced, and that night's refresh
    replaces it anyway.
  - Replace: the host's Refresh now, once per host. A fresh base, then each site down about
    12 s. The fix lasts, and it is what Q2 already does for a critical fix.
  - **Recommend replace,** as Q2 does. Its catch is the 12 s per site in the daytime.

- **Q8 — How does WP7's live test put the release being prepared on its test server?**
  The test can make the server, join it to dev's plane, run each changed word as a plane job,
  check the result and clean up, all from existing code. The hard part is the install. Dev is
  the publishing box: its `agent_dist` is what the last release shipped, and the changed-word
  list is measured against it, so the test must not rebuild it. A script word's script reaches
  a machine only in a support bundle, and the agent installs a bundle only with a release
  statement signed by a key it trusts.
  - Sign the candidate with the release key: the test builds the agent and bundle into a side
    directory and signs them as a release would. The bytes tested are the bytes shipped. Catch:
    the fleet's own release key signs builds that are not releases, and any agent would accept
    one.
  - A throwaway key per run: the test builds the agent with a key it just made baked in, in
    place of the release keys, and signs its bundle with that key. Nothing the fleet trusts
    signs anything, and the key is gone after the run. Catch: the tested binary differs from
    the shipped one in its baked keys. Same commit, same code.
  - Prove after publishing, before the rollout: the test installs dev's published release, and
    the staged rollout, not the publish page, waits on the proofs. Catch: the warning comes
    after the release exists, and a release is fixed by publishing another.
  - **Recommend the throwaway key.** It tests the code being shipped without the release key
    signing anything new.

## Progress (2026-10-22)

Built, uncommitted, not released. Gates: site_refresh 153+, host_report 236, security_check 20,
install_security_updates 48, fleet_security 34, job_result_processor 411, incident_sources 130,
job_command_builder 566, agent_machine_scripts_bundled 88, agent `go test ./...` green. reviewer2
reviewed WP4 (A–E, F1–F4, all taken).

- **WP1.** Agent 1.88.0 `check_status` reports `php`: packages from dpkg's status file, and each
  php-fpm master's binary with `running_current` from `/proc/PID/exe`. No command runs. New
  `sysadmin_tools/security_check.sh`: an hourly timer that housekeeping installs on every
  machine. It refreshes the lists when they are over 45 minutes old, simulates an upgrade, and
  records each `-security` or platform-origin package, marking critical ones. Host report 1.37
  adds `security_pending` and `services_needing_restart`. The node page shows both, and a PHP card.
  - **Change from the text above:** a container's pending fixes are its host's base check
    (`site_refresh.sh check`, which the hourly check also runs on a container host), not
    `check_status`. `check_status` runs no command, and a container's OS is its base's.
- **WP2.** `/admin/server_manager/fleet_security` (`FleetSecurityView`).
  - **Verdict, refined:** red for old code serving or a *critical* fix waiting. Amber for other
    fixes (Q2: they wait for the night), a reboot owed over a day, or a check stale over two
    hours. Grey for nothing reported.
- **WP3.**
  - `install_security_updates.sh` 1.2: finishes a half-done package run first (`dpkg --audit`,
    then `apt-get -f install` naming no package). Afterwards it USR2-reloads any php-fpm master
    on a replaced binary and reports `reloaded` and `still_on_old_code`.
  - Box-proven: a container's replaced php-fpm was reloaded, and the site answered 200.
  - The container-node button is in the PHP card.
  - **The lost `.deb`, found and fixed (built 2026-10-22, not released).**
    - Cause: the host converger's every-minute tick checks the site's declared PHP extensions
      (`_plugin_installers_start.sh`) and counted one as missing unless dpkg said "install ok
      installed". Mid-upgrade, an extension apt has already unpacked reads "unpacked" or
      "half-configured", so the tick ran `apt-get update`. Ubuntu's Docker image carries
      `docker-clean`, which deletes every downloaded `.deb` after any update, with no lock. dpkg
      then lost the packages it had not reached; on 10-21 that was package 14, php8.5-common.
    - Reproduced on wp4dock (container wp4b, Ubuntu 26.04): one tick, run while php8.5-apcu read
      "half-configured", took the cache from 16 `.deb`s to 0, and the reinstall failed with the
      10-21 error (`failed to read archive '/tmp/apt-dpkg-install-…/03-php8.5-common…'`).
    - Fix 1: a package dpkg wants installed (or held) is present unless absent (not-installed,
      config-files), one pattern in all three places that check (`_plugin_installers_start.sh`
      2.43, `_install_declared_dependencies.sh` 1.4, `utils/upgrade.php` 1.26), pinned by
      installer_contract. The same tick at the same moment then left all 16, and the install
      finished.
    - Fix 2: `Dockerfile.template` 6.4 drops docker-clean from the finished image (the build steps
      keep it), so an update beside an install is harmless, as on any Ubuntu server. Proven alone:
      with the old tick and docker-clean removed, an `apt-get update` mid-install left all 16.
      It reaches each site at its next replacement. `install_security_updates.sh` 1.3 runs
      `apt-get clean` after an install that changed something, so the downloads do not stay.
    - The image also turns apt's daily job off by our own setting (`99joinery-no-periodic`).
      Ubuntu's image ships `20auto-upgrades` switched on, and only its own
      `docker-disable-periodic-update` keeps cron's `apt-compat` from refreshing lists and
      upgrading in every container at 06:25. Checked in wp4b: the job has never run there.
    - **B17, not built: a package run left half done is invisible.** dpkg leaves packages
      "unpacked" or "half-installed" when a run dies, as on 10-21. Nothing reports it until the
      next `install_security_updates` run repairs it, and inside a container no hourly check
      runs at all. Plan: the agent's `check_status` already reads dpkg's status file for `php`.
      It reports the packages a run left half done (wanted, in any state but installed,
      not-installed and config-files), and the fleet page shows the node amber with
      "Install security updates finishes it". It is an agent change, so it waits for the agent
      work in progress (1.89.0, the hung-agent fix) to land.
- **WP4.**
  - Housekeeping 1.26 installs the timer on Docker hosts.
  - `recreate_site.sh` 1.5 records a run spec for a site Docker made before run specs
    (`run_spec_adopt`, after the site is known to be its own and its base exists). It judges an
    image that predates the health check by its answer, and refuses a new image with no health
    check.
  - `site_refresh.sh` 1.3 counts such sites, skips the base build on a Docker host with no sites,
    and adds `start`. Agent word `refresh_site_containers` gives Refresh now.
  - The incident covers a host running site containers with no timer.
  - **Dropped:** "Replace on a fresh base". Refresh now does that for every due site, and one
    site alone gains nothing.
  - **Box-proven** on a Docker 24.04 Linode set up as docker-prod (no remap, old start command,
    no run spec, no health check, old base only). Proven paths:
    - the first night, start to finish (about 12 s down per site, PostgreSQL stopped cleanly);
    - a fix found, so the base is rebuilt and sites are replaced;
    - a failed new image, rolled back onto the old image under the six capabilities (pages,
      PostgreSQL, cron and scheduled tasks all work);
    - "already under way", and host report `running`.
- **WP5.** Install security updates everywhere: `FleetSecurityRun` plus `FleetSecurityRunner`
  plus task `AdvanceFleetSecurityRuns`, on the fleet page. Machines go first, then containers by
  host. It stops on a failed updater, passes busy and unavailable as skipped, and can be stopped
  between nodes.
  - A container whose host is replacing its containers is left out, or skipped while its
    install is open. The install would die with the old container and halt the run an hour
    later as a lost job (reviewer2 c).
  - **Open, Q7:** see "Open question" below.

- **WP6.**
  - Review fixes (reviewer2):
    - G1: the hourly service's time limit is 2 h, past the run's own bounds, so systemd never
      kills a package run part way.
    - G2: the base check runs only where the refresh timer is on.
    - G3: the kernel reads one way everywhere. The page shows it amber; the incident counts a
      kernel package still uninstalled after a day.
    - (b): `site_refresh.sh start` tries systemctl three times, because D-Bus restarts under an
      update.
    - (d): a machine without needrestart reports binaries, and those match too.
    - G4, accepted: an at-once install that fails is tried again each hour, and the critical
      incident carries it.
  - `security_check.sh` records when each pending fix was first seen.
  - Q2: a critical fix (not the kernel) is installed at once by `install_security_updates.sh`,
    then checked again. On a container host, a critical fix for the base starts
    `site_refresh.sh start`.
  - New incident sources:
    - `plane:security_fix_pending`: critical when a critical fix has waited over an hour;
      warning when any fix has waited over a day, or no check has passed in three hours.
    - `plane:old_code_serving`: a php-fpm still on a replaced binary ten minutes after its
      package was installed, or needrestart naming PHP-FPM, Apache or PostgreSQL.
  - Box-proven: an Ubuntu 24.04 machine with 128 fixes waiting (6 critical) installed them at
    once and then found none waiting. Its sites answered 200 throughout, and it reported a
    reboot owed without rebooting.
  - **Not built:** the optional Ubuntu notices feed.
- **WP7.**
  - `docs/testing.md` has the rule: a stand-in models a real machine, and a changed agent word
    runs once on a real server.
  - `AgentLiveProofs` works out the changed words. A word has changed when its Go file (or a
    helper file of its own) changed since the bundled agent's commit, or its script differs
    from the signed `RELEASE_MANIFEST`.
  - Proofs live in `plugins/server_manager/agent_live_proofs.json`, added with
    `maintenance_scripts/dev_tools/record_live_proof.php`. The publish page lists unproven
    words and refuses until "Publish these without a live run" is ticked (Q5).
  - **Not built:** the automated live-tier test that makes the server and runs each word
    through an agent. Proofs are recorded by hand until it exists.

- **WP8.** `/admin/server_manager/machines` (`MachineInventory`) lists three kinds of machine:
  - **Managed machines,** in any install state (a dormant copy and a retired source are paid
    for), with their release from their host reports.
  - **Every server in our own cloud accounts** (not the test account, not a customer's Connect
    grant), matched to nodes by address as the transfer watch matches them. A server no node
    runs on is listed as not managed, with what a note on it says it runs, or else the release
    of the image it was made from. Both drivers' listings now carry the image.
  - **Machines written down by hand** (`FleetHandMachine`, `/admin/server_manager/machine_edit`):
    for accounts no key here reads (Q6), or a note on a listed server.

  Red is a release past standard support, or a retire-by date passed. Amber is a server nothing
  explains, a written-down row not confirmed for 90 days, one serving nothing written with no
  retire-by date, a retired source, or a node whose install failed. Support ends are the first
  day of the earliest month Canonical's pages give. Test machine_inventory 21/21.
  - **Change from the text above:** "nothing serves from it" cannot be seen from here. A
    written-down row says what it serves, and one that says nothing and has no retire-by date
    is asked about.
- **WP9.** `work` 1.16 (`~/work-tool`, uncommitted, the owner's local repo): `work --held` puts a
  file under the session whose prepared commit holds it as it is in the tree, before falling
  back to the edit log's guess. A stale prepared copy, changed since, does not count.
- **WP10.**
  - **B8.** `LinodeComputeDriver::createInstance` asks for `interface_generation` =
    `legacy_config`. Box-proven on the test account: Linode accepted it, and the server came up on
    configuration-profile interfaces with Network Helper on. `addressReport` reports the
    generation. `IpSwapMove` 1.9's plan refuses a server on Linode Interfaces, with the remedy.
    Test site_copy_ip_swap 52/52.
  - **A site that cannot send mail.** Core's own entry in the fleet report,
    `core/outbound_mail` (`PluginProvisioning::outboundMailCheck`), checks that a mail service is
    set and its provider's `validateConfiguration()` passes. A failure fails the node's health
    like a failing plugin check, so no new incident type is needed. It fails with no mail service
    set whether or not other users exist: every site has its admin, whose password reset is mail.
    The cost: a fresh site's node shows danger until its setup's mail step sets `email_service`.
    The check is first in the report, so the 50-check cap never drops it. Test
    outbound_mail_check 6/6.
  - **Theme CSS.** New safe-tier test `theme_compiled_classes`: a theme whose CSS carries
    Tailwind's variables must define every utility class its views use.
  - **B16.** It found galactictribune.net's post and blog views using 19 utilities its compiled
    CSS lacked (gap-2, flex-wrap, bg-indigo-50, text-2xl, leading-relaxed, border-l-4, the hover colours and others).
    They are now appended with Tailwind v3 values (theme 1.0.11), as xandy's were.
  - **Test DNS.** `TestCloudCleanup` 1.3 removes a deleted server's A and AAAA records in the test
    zone (joinerytest.com) with it, whichever test account the server was in. The zone's Linode
    test account removes them with its own token (`zone_holder()`, `domains:read_write`, added to
    the scopes a run needs). With no such account held, the run says the records were left. Test
    test_cloud_cleanup 31/31. The test token already
    holds the scope. On 2026-10-22 the zone held six leftover records (gva, gvb, gvc) from the
    gVisor trial's server, deleted by hand on 10-13. Linode had since given its IPv4 to se1, so
    those names reached se1 by accident. They were removed with `test_server.php dns-delete`.
- **docker-prod, 2026-10-22 02:56 UTC.** Refresh now (job 7049) built base 2.2 and replaced all
  six sites, 11.8 to 15.8 s down each. The public probes agree: one 502 burst per site. There
  were no forced stops and no PostgreSQL recovery.

Bugs the box proof found and fixed:

- **B13.** `site_refresh.sh`'s check passed `--cap-add=CHOWN,DAC_OVERRIDE,FOWNER`. Docker refuses
  a comma list (exit 125); Podman takes it. On a Docker host every check would have failed, so no
  fix would ever have been found. Each capability is now its own flag.
- **B14.** An old container's first process is a shell that ignores TERM, so `docker stop` waited
  60 s and SIGKILLed it, PostgreSQL included. PostgreSQL then logged "was interrupted ...
  automatic recovery". On docker-prod that is six production databases.
  - Fix: for a container not on the joinery start, take the restart policy off, stop
    PostgreSQL inside cleanly, then Apache.
  - The restart policy matters: without it, Docker restarted the container and the stop killed
    that one too.
  - Also true of `restart_container` and `hold_container` on docker-prod today, until each site
    is replaced once.
- **B15.** After a fresh build the state still named the old base's pending fixes. se1 showed 15
  PHP fixes "waiting" after its 01:43 run had built them in, for a real Ubuntu PHP update on
  2026-10-22. The new base is now checked in turn.

## Done when

- The fleet security page shows every machine and container, and on a day like 2026-10-21 it turns
  red within the hour of Ubuntu's notice and green again after one action, with no terminal.
- A container patched in place serves the new code before the job reports success, and docker-prod's
  sites (wherever they live) refresh nightly.
- No machine we pay for is missing from the inventory.
- Each WP's gate is green and its live check (a real fix, on a test server and then the fleet) has
  passed; the live checks go in the live verification queue as each WP lands.
