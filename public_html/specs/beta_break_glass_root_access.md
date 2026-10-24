# Beta break-glass root access

**Status:** Draft 2026-10-24, redesigned the same day. The first design (an
agent operation, five new states and a new install job, so that removal ran
unattended) drew 61 findings over four reviews; the owner chose this simpler
design instead: removal waits for the holder's click, and the existing,
proven cleanup does the work. Section 2 (self-hosted) is unchanged from the
reviewed text. Sections 3 to 6 were reviewed by reviewer1 (N1–N8, C1–C2) and reviewer2 (H1–H8,
H2b), all taken. The owner then chose no special handling for a lost holder
vault (3.4), and asked for the agent operation in 5.3, reviewed by reviewer2
(K1–K9), all taken. WP1 built 2026-10-24 (gate 22/22); its live check is in the live verification queue. WP2–WP4 not started.

## 1. What this does

During the beta we may break a hosted machine in a way its agent cannot fix. This
spec keeps one way back in: on each fresh install the management node creates
(not bare hosts, copies, or machines whose operator supplied root keys), root
keeps its password login, and the management node keeps that password sealed
to one named operator's passkey vault (the holder). Only the holder, with their
vault unlocked, can read it.

The site's owner sees this in the setup wizard, in plain words, beside the
existing log-access permission, and can turn either off. Turning root access
off is permanent. It takes effect the next time the holder clicks **Retire**,
which runs the existing install-password cleanup.

Self-hosted installs go the other way: root SSH login is off by default, with a
flag to keep it. Self-hosted sites still get the wizard step, with log access
only.

When the beta ends, the holder retires every kept password with one click, and a
release turns the setting off and removes the wizard's root checkbox.

This is a temporary relaxation of policy, chosen deliberately:

- **What the agent can do is public; what a root login can do is not.** The agent
  runs only signed operations recorded in the public release log. A root shell
  can do anything and leaves no trace in our records. That is the cost, accepted
  for the beta.
- **The data-root encryption is unaffected.** It protects a machine that is off
  or taken whole. A running machine's data is readable by root either way (the
  agent included), a password login does not unlock the disk after a reboot,
  and the volume key a root shell could copy out is one the management node
  already holds in escrow. Encrypted machines are offered root access like any
  other. `hosted_data_promise_disk_encryption` says "root login stays off"; it
  carries a pointer to this spec, and that line holds again once the beta ends.
- **A leak of the management node's database and config does not reveal the
  passwords.** They are sealed to the holder's vault, not under SecretBox, whose
  key sits in the management node's config. An attacker running code on the
  management node is a different case: they could capture a password at its
  next reveal. That residual risk is accepted for the beta.

## 2. Self-hosted: root SSH login off by default

Today only `install.sh server` touches sshd (`do_server_setup`, through
`derive_ssh_access` and the `00-joinery-root-login.conf` drop-in);
`install.sh docker` never does. The sshd block moves into one function that
both host commands call, so a self-hosted Docker host is hardened the same way.
(`site` never touches sshd.)

The default is `PermitRootLogin no`, written in the drop-in and checked with
`sshd -t` as today. That applies in every case, including root holding SSH keys
(Q3).

`--keep-root-login` keeps the rules `derive_ssh_access` applies today: key-only
when root holds keys, off when run under sudo from an ordinary account, left as
it is when root's password is the only way in.

What the installer says:

- Root was the only SSH way in (root has keys, or only a password, and the run
  is not under sudo from another account): "Root SSH login is now off. Your
  provider's web console still logs in as root (it is not SSH). To keep root
  SSH login, re-run with --keep-root-login."
- Run under sudo: the account named keeps its access, as today.

An interactive run asks once before turning it off, in both root-only cases
(keys or password), defaulting to off. A run with `-y` or no terminal (the
existing guard: `[ "$ASSUME_YES" -eq 1 ] || [ ! -t 0 ]`) does not ask.

`install.sh` gets a version bump and the help text names the flag;
`docs/installation.md` describes the default and the flag: its table of the
three `derive_ssh_access` cases is rewritten for the new default. The comment in
`JobCommandBuilder::build_install_node` saying `install.sh server` leaves root
password login on is rewritten to name `--keep-root-login` as the reason.

Moving the sshd block out of `do_server_setup` changes the hash
`compute_install_sh_hash` takes of that function's body, which labels the
`joinery-base` image. So `BASE_IMAGE_VERSION` is bumped in the same change and the
base image rebuilt on each Docker host, or every `install.sh site --docker`
warns that `do_server_setup` changed since the base was built.

**Everything that runs `install.sh server` or `install.sh docker` over the root
password passes `--keep-root-login`.** Commands later in the same SSH session
survive the sshd restart; what fails without the flag is every later login
(the keep or retire job, probes, Retry Install):

- the install executor (`JobCommandBuilder::build_install_node`), on both its
  `install.sh -y -q server` line and its `install.sh -y -q docker` line. Docker is
  the default hosted shape, and `docker` hardens sshd for the first time here;
- the live proof run (`AgentLiveProofRun`, its `install.sh -y -q server` line).

Not the site copy onto the owner's own server (`SiteCopyRunner::owner_command`):
the owner pastes it on a server of their own, and nothing logs in to that
server over SSH afterwards (the copy joins through its agent). It gets the
self-hosted default, and the Copy tab says so and names the flag. A copy onto
a server this plane creates goes through the install executor above. The
Linode StackScript (`linode_stackscript.sh`, self-hosted) gets the default
too; root keeps its password for Linode's web console.

Built with it: when the main file holds the setting (no Include) and `sshd -t`
refuses it, the main file's line is put back as it was; the older code removed
only the drop-in, which in that case did not exist. `install.sh docker
--site-engine` is a host command too and runs the same function. The behavior
is pinned by `tests/integration/root_ssh_login_gate.sh` (fixture `/etc/ssh`,
stand-in `sshd`, the question answered through a terminal).

## 3. Hosted: the password is kept

### 3.1 How this reuses what exists

Today a hosted machine's root password (`cvp_root_pass_sealed`, under SecretBox)
is held for the install, then `handle_install_password`
(`ProvisionCustomerCloud.php`) queues `retire_install_password` once
`machine_agents()` says every agent on the machine is admitted (`held` →
`retiring`). The executor runs it over the password, the job is complete only
once a fresh login is refused, and the pipeline erases the password (`retired`).
That path already handles root keys the operator supplied
(`cvp_root_ssh_keys`), Docker and bare-metal machines, a deleted node
(`fail_retire` → `retire_failed`, password kept), and re-runs.

This spec adds one state, `kept`, between `held` and `retiring`:

- `held` → `kept`: when the agents are admitted and the box is kept (3.2), the
  password moves from SecretBox into the holder's vault, with nobody present.
- `kept` → `held`: when the holder clicks **Retire** with their vault unlocked
  (3.5), the password moves back under SecretBox. From `held`, the existing
  branch queues `retire_install_password` on the next pass. Nothing about the
  retire changes.

`kept` (4 characters) fits `cvp_install_password`'s `varchar(16)` and joins
its `allowed_values`. It joins `PASSWORD_HELD_STATES`
(`customer_cloud_provisions_class.php`), which already gives the right
behavior everywhere it is read:

- the sweep's `install_password_states` list hands kept rows to
  `handle_install_password`, which returns at once for `kept` (3.2);
- the dashboard's `open` list (`views/admin/index.php`) keeps kept boxes on
  the board;
- the delete blocker (`dismiss_blockers`) refuses to dismiss one;
- `InstanceTransfers` refuses a transfer while it is kept. Its fix text for
  `kept` reads "Retire it first: the holder clicks Retire on the dashboard",
  not the re-run of `retire_install_password` it gives for the other states.

Retry Install is refused for a kept row already, because `holds_install_password`
reads `cvp_root_pass_sealed`, which is empty while kept.

### 3.2 Keeping it

In `handle_install_password`, two changes:

1. **First line:** `if ($state === 'kept') return 0;` before the node-gone
   check, so a kept row never reaches `fail_retire` (which would mark it
   `retire_failed` while the SecretBox copy it expects is gone). A kept row
   whose node is gone stays `kept`; the dashboard summary says so (3.6), and the
   holder's Retire takes it to `held`, where today's node-gone branch applies
   unchanged.
2. **In the `held` branch, once the agents are ready:** keep instead of retiring
   when all of these hold, else retire as today:
   - `server_manager_beta_root_access` is on;
   - the install offered it (`cvp_root_access_offered`, 3.3), which only a fresh
     install does (4.1);
   - the holder is fit (3.4);
   - `cvp_root_ssh_keys` is empty. A box whose operator supplied root keys
     already has its own way in, and only the retire job installs those keys;
   - the site's node has not reported `root_access` = `off` (5.1): an owner who
     answered the wizard before the agents were admitted is honored.

Keeping, in one save:

- set `cvp_sealed_owner_user_id` to the holder;
- set `cvp_root_pass_vault_sealed` to the password read from
  `cvp_root_pass_sealed` (SecretBox `open()`);
- leave `cvp_root_pass_sealed` alone in this save;
- set `cvp_install_password` = `kept`.

The save is wrapped in a try/catch, so any throw goes to the fallback below
rather than aborting the sweep's pass. The fallback's direct UPDATE leaves the
row in a known state whatever the save did or did not write.

Then reload the row (`new CustomerCloudProvision($id, TRUE)`) and verify it from
the database: `rowIsSealed()` is true, `cvp_sealed_key` is non-empty, and
`cvp_sealed_owner_user_id` is the holder. Only then null `cvp_root_pass_sealed`
in a second save.

**Fallback**, on a throw or a failed check, in one direct UPDATE, not through
`save()`: `cvp_root_pass_vault_sealed` = NULL and the four convention columns
cleared (a save with no vault for the owner writes a sealed field as plaintext,
so the copy the check rejected may be sitting there in the clear),
`cvp_install_password` = `held` with the password still under SecretBox,
`cvp_root_access_offered` = false so the next pass does not try again, and
`cvp_error` saying why. It is a direct UPDATE because, if the seal did land and
a later step failed, `save()` would try to open the row's key to rewrite the
field, which needs the holder's vault, and the sweep has none. Then read the raw
column back and confirm it is NULL.
Today's branch retires the box on the next pass. A failed keep therefore falls
back to today's behavior, never to a password left in the clear.

### 3.3 The sealed field

The provision model gets the standard annotation: `$sealed_fields =
array('cvp_root_pass_vault_sealed')` and the four convention columns
(`cvp_content_sealed`, `cvp_sealed_key`, `cvp_sealed_owner_user_id`,
`cvp_key_generation`), declared as `CalendarEntry` declares them. The field is
`text` with no `default`, `required`, `allowed_values` or `validation`: `save()`
reads only fields that declare those, so the sweep's ordinary saves of a kept
row (with nobody's vault open) never try to decrypt it. Code that reads every
field (`export_as_array()`) would throw `VaultLockedException` on a kept row
with no vault open. Nothing reads provisions that way today, and the model is
not API-exposed; it must not become so without handling that exception.

Two model hooks, both existing:

- **`sealedOwnerUserIdFor()`** returns `cvp_sealed_owner_user_id`, and throws
  when it is empty. The default write path falls back to `cvp_usr_user_id`,
  which on a provision is the buyer: the password must never seal to the
  customer.
- **`shouldSeal()`** stays true. The default save stores a sealed field in the
  clear when its owner has no vault; the keep decision checks the vault first,
  and the read-back check in 3.2 catches the rest.

`cvp_root_access_offered` (bool, default false) records that the install told
the site to offer the checkbox (4.1).

server_manager's `plugin.json` gains `"vaultConsumer": {"reseals": true}`, and
its existing `includes/bootstrap.php` registers
`VaultUnlock::onReseal(VaultUnlock::modelReseal([CustomerCloudProvision::class]))`,
so a rotation of the holder's key re-wraps kept rows. Declaring `reseals` makes
a key rotation refuse on any management node where the callback does not load;
the plugin's docs say so.

### 3.4 The holder

`server_manager_beta_root_access_holder` (a user id, no default: a seeded
admin must never become the holder by accident) names whose vault new kept
passwords seal to. It is an ordinary plugin setting. The holder is **fit** when
they have a vault (`UserEncryptionVault::loadForUser`, scope `user`) and a
second factor (`$session->user_has_second_factor($holder)`, which takes the
`User` and reads only that user's factors, so the sweep can call it); without the second
factor they could not use Reveal or Retire (3.5), and a kept password nobody
can retire is worse than none. Fitness is checked where the holder is used: the
keep decision (3.2) retires instead of keeping, Reveal and Retire refuse, and
the incident (3.7) reports an unfit holder.

Changing the holder affects new boxes only. A row stays sealed to the holder
it was kept under; the old holder can still Reveal or Retire it, and the
incident (3.7) lists rows sealed to someone other than the current holder, so
they are retired rather than forgotten.

**If a holder's vault is lost** (wiped, every passkey and the recovery code
lost, or the account removed), nothing handles it specially. The passwords
sealed to it become unreadable, so those boxes can no longer be retired through
Retire, and in practice root login on them is closed: they accept only a long
random password that nobody holds. If one needs root login actually turned
off, an administrator uses **Turn off root login** (5.3), which needs no
password and no vault.

### 3.5 Reveal and Retire

On the Server Manager dashboard, a kept box's row (the provision list in
`views/admin/index.php`) shows **Reveal root password** and **Retire** to the
holder. Both are POSTs handled in the dashboard's POST block beside
`dismiss_provision`, and both refuse unless:

- the viewer is the row's holder (`cvp_sealed_owner_user_id`);
- the holder's vault is unlocked (`VaultUnlock::secretKey` is keyed by session,
  user and scope, so another admin's open vault gives nothing);
- the holder has a second factor (`$session->user_has_second_factor($holder)`)
  and has used it recently (`$session->has_recent_second_factor()`). `node_detail_actions_logic::step_up_required()` lets an
  account without a second factor through, so this checks the factor itself.

**Reveal** shows the password once. Reveal and Retire each write a row to the
platform's general event log (`evl_event_logs`), as `VaultAudit` does, with the
events `break_glass_reveal` and `break_glass_retire` and a note naming the
provision and node: who, when, which box, never the password. There is no
provision detail page and no general event-log page, so they are listed on the
kept box's dashboard row (which exists for every kept row, including one whose
node is gone), and also on the node detail page's Security box while the node
exists, whatever the row's state (beside **Emergency root access: kept** while
it is kept), so a retired row's history (5.3) stays visible there.

**Retire**, in one request with the vault open:

1. read `cvp_root_pass_vault_sealed` (logged as a reveal);
2. seal it back into `cvp_root_pass_sealed` under SecretBox, with the locator the
   pipeline already uses (`cvp_customer_cloud_provisions.cvp_root_pass_sealed`):
   the install executor opens the column by that name, and a password sealed under
   any other locator would be one the retire job cannot read;
3. set `cvp_root_pass_vault_sealed` to NULL, `cvp_install_password` = `held`, and
   `cvp_root_access_offered` = false. The last is what makes Retire one-way: the
   keep decision (3.2) requires it, so the next pass retires instead of keeping
   the box again;
4. save. The vault is open, so saving the sealed field as NULL is an ordinary
   save (it reuses the row's key).

The next sweep pass queues `retire_install_password` from `held`, and today's
pipeline does the rest. **Retire all requested** does the same for every kept
box whose owner asked (5.1), in one request.

### 3.6 Dashboard summary

`install_password_summary()` gets a `kept` line: "Kept for the beta (holder:
name)", plus "owner asked to remove it" when the site's node reports
`root_access` = `off`, plus "the node record is gone" when it is. Without a
line it would show "—".

### 3.7 Incident

The reconciler keeps one open incident per node per source
(`IncidentRecord::open_for($node, $source)`), so this is two sources, listed in
`includes/bootstrap.php` beside the others. Both evaluate only on the
management node's own node (`ReleaseLogWatch::isSelf($node)`, as
`IncidentSourceTestCloudCleanup` does), because the reconciler asks only
operational nodes and a box's own node may be gone or still installing.

- **`plane:break_glass_attention`** (warning): one incident whose title counts
  the kept boxes needing the holder and whose detail lists each, as
  `IncidentSourceReleaseLogEntry` does for many entries. A box is listed when
  its owner asked for removal, its node is gone, or it is sealed to someone
  other than the current holder. Clears when the
  list is empty.
- **`plane:break_glass_no_holder`** (critical): the beta setting is on and the
  holder is unset or unfit (3.4), so new boxes are being retired instead of
  kept. Clears when the holder is fit or the setting is off.

## 4. The setup wizard: "Beta permissions"

### 4.1 How the site knows to offer root access

`JobCommandBuilder::build_install_node($node, $params)` is static and sees no
provision or setting, so the decision is made by its caller.
`ProvisionCustomerCloud` adds a `beta_root_access` job parameter, beside
`encrypted_data_root`, when `server_manager_beta_root_access` is on, the
provision is a fresh install (`cvp_install_mode` = `fresh`: not `bare`, which
has no site, nor `copy`, whose site database is its source's), and no root keys
were supplied. It sets `cvp_root_access_offered` in the same save that records
the job. The builder turns the parameter into `--beta-root-access` on the
`install.sh site` line. Retry Install reuses the stored parameters, so it carries
the flag. `PollHostingOrders`, which installs a container site on a shared host
and holds no install password, never passes it.

The flag travels through `install.sh` the way `--outbound-notice-gb` does (no
server_manager caller passes that one today, so the executor half is new):
`install.sh` turns it into
`JOINERY_BETA_ROOT_ACCESS=1`, adds that to `SITE_INIT_ENV_INPUTS` (beside
`JOINERY_OUTBOUND_NOTICE_GB`; without it the container's env file never gets it) and to `_site_init.sh` on bare
metal, and `_site_init.sh` writes the managed site setting
`beta_root_access_offered` = 1 with the same update-then-insert it uses for
`outbound_monthly_notice_gb`.

The keep decision requires `cvp_root_access_offered`, so a box is never kept
without its owner having been offered the choice. A box that was offered but
then retired (no fit holder at that moment, say) shows the owner a checkbox for
access that does not exist, which errs toward less access than the text claims.

### 4.2 The step

A new core step in the `SetupSteps` registry (registered beside the others in
`registerCoreDefaults()`), site scope (permission 10 only), after the welcome
step, on every install.

Plain-language copy (final wording at build):

> **While Joinery is in beta, we keep two ways to help if something goes wrong.**
>
> ☑ **Emergency root access.** If an update breaks your server badly enough that our
> normal tools can't reach it, we can log in as root to fix it. The password is
> locked so only our operator can read it, and every time it is read is
> recorded. Unchecking this has it removed within a day and **cannot be undone**:
> we cannot turn it back on for you. It is removed for everyone when the beta
> ends.
>
> ☑ **Log access.** Lets us read your server's error and activity logs when
> diagnosing a problem. Passwords, keys, member addresses and form contents are
> never sent. You can change this any time on the Management Node page.

- **Emergency root access** is the managed site setting `beta_root_access`
  (default on), shown only when `beta_root_access_offered` is 1. Once off, the
  step and the Management Node page show it as off with no checkbox, and any
  save that sets it back on is refused (Q1).
- **On a self-hosted site** the step has only the log-access checkbox, and says
  the permission matters once the site is connected to a management node (for
  example to get support), and that until then nothing reads the logs.
- **Log access** is the existing `agent_log_access`.

**Saving.** The settings are managed, and the generic `step_settings_save`
(`SettingsWriter`) refuses managed settings. So the step has its own action,
`beta_permissions_save`, in `logic/setup_logic.php`, written like the
Management Node page's `log_access_on` / `log_access_off` actions
(`adm/logic/admin_management_node_logic.php`). It writes with `Setting::put`,
refuses `beta_root_access` going from off to on, sets
`agent_log_access_notice_seen`, and calls `SetupSteps::recordDecision($key,
NULL)`.

The step does not declare `decision` (that adds a "Not now" button, and this
step is itself the question). Its `status` callable returns green when
`hasDecision($key, NULL)`. An owner who never opens the wizard keeps both on.

The Management Node page (`/admin/admin_management_node`) gets the same
Emergency root access row beside the log-access switch, with the same one-way
rule.

## 5. Turning it off

### 5.1 The owner turns it off

The site's agent reports the setting on every poll as `root_access`, the way it
reports `server_manager` (`remote.go`): `on` or `off` when the database answers
and the row exists, nothing otherwise. A reader in the `primitives` package
beside `ServerManagerActive` does the query. There is no marker and no
fail-closed default: an absent answer leaves the last one standing, so a
database outage never reads as the owner's off.

The poll validator (`AgentChannelEndpoint.php`) gets a `root_access` entry
(`/^(on|off)?$/`), and the endpoint stores it in a new `mgn_agent_root_access`
only when it is `on` or `off` and differs, as it does `server_manager`. The
column joins `SiteCopySwap::MACHINE_COLUMNS` beside `mgn_agent_log_access`.

Nothing acts on `off` automatically. The dashboard row shows that the owner
asked, the incident (3.7) is raised, and the holder clicks Retire (3.5).

### 5.2 The beta ends

The holder clicks **Retire all kept** on the dashboard, which does 3.5's
Retire for every kept box in one request. Then a release:

- turns `server_manager_beta_root_access` off with a migration. Changing the
  default in `plugin.json` is not enough: declared defaults are written only
  for a setting not yet stored;
- stops the install passing `--beta-root-access`;
- removes the wizard's root checkbox.

### 5.3 Turn off root login (agent)

The agent has no operation that changes sshd today: `restart_unit` refuses
sshd by design, and the only thing that turns root login off is
`retire_install_password`, which logs in with the password. This adds one,
`disable_root_login`, for any machine whose own agent is connected: a kept box
whose vault is lost, a box whose cleanup failed, or any machine an operator
wants closed to root.

**The agent operation.** A script word, built like `restart_unit`
(`primitives/operate_restart_unit.go:36-72`): class operate, `Machine: true`,
no parameters, running `maintenance_scripts/sysadmin_tools/disable_root_login.sh`
through a `ScriptSpec` (`/bin/bash`), verified against the signed release
manifest. It joins `pinnedMachineWords` (`machine_vocabulary_test.go:49`) and
`pinnedVocabulary` (`gate_test.go:162`), and the script joins
`SupportBundlePublisher::$contents` beside `restart_unit.sh` (line 228) so a
machine with no site tree has it. Agent version bump, signed release.

`Machine: true` only lets a siteless host agent report the word; it does not
keep a site agent from reporting it (`RunnableNames`,
`primitives/registry.go:251-262`, reports every word on a machine with a
site). A site agent inside a container therefore advertises
`disable_root_login` too, and a job run there would change the container's
files, not the host's sshd. **Routing is the builder's rule** (below), not the
flag's.

**The script**, which prints one JSON object shaped like `restart_unit.sh`'s
(before and after `permitrootlogin`, the reload result, `match_blocks`):

1. Creates the directory if missing (`install -d -m 755
   /etc/ssh/sshd_config.d`, as the retire job does,
   `JobCommandBuilder.php:6071`), then writes `PermitRootLogin no` in its own
   drop-in, `/etc/ssh/sshd_config.d/00-joinery-0-no-root-login.conf`. sshd
   takes the first value it reads; Ubuntu's `sshd_config` includes
   `sshd_config.d/*.conf` before every directive, and this name sorts first
   under both `C` and `en_US.UTF-8` collation, ahead of
   `00-joinery-agent-managed.conf`, `00-joinery-root-login.conf`,
   `50-cloud-init.conf` and `60-cloudimg-settings.conf` (checked on a
   24.04.4 box). Where `sshd_config` has no such Include it sets the line in
   the main file instead, as `install.sh`'s sshd block does (4161-4168).
2. Runs `sshd -t` itself, before any reload; on refusal it removes its file,
   changes nothing, and says so.
3. Reloads sshd only when `systemctl is-active --quiet ssh`. Ubuntu's sshd is
   socket-activated: on a box nobody has logged into since boot `ssh.service`
   can be inactive while `ssh.socket` listens, and a reload of an inactive
   unit fails. An inactive sshd reads the new file when the socket next starts
   it. The reload (`ExecReload`: `sshd -t`, then `kill -HUP $MAINPID`) restarts
   the listener only, so open sessions stay up.
4. Reports the effective value (`sshd -T`'s `permitrootlogin`). The job is
   complete only when that reads `no`.
5. Reports whether any `Match` block exists in `sshd_config` or
   `sshd_config.d/*.conf`. **Accepted residual:** a `Match` block can let root
   in for some address or user whatever the global value, and `sshd -T`
   without `-C` prints only the global section. None of our images ship one;
   the report makes one visible.

It changes nothing else: password login for other accounts, root's password,
and keys stay as they are. Root's keys stop working too, since root may not
log in at all.

Because nothing removes that file and it sorts first, no later step reopens
root login, including a later `retire_install_password` that writes
`prohibit-password` for operator keys. The operation is one-way, like Q1.

**Hostile-caller review** (`specs/agent_recipes_and_vocabulary.md:181`): the
worst a compromised management node can do with this is close root SSH login
on every machine it reaches. It grants nobody anything. On a hosted box the
provider's console still works. On a self-hosted box with no console the
owner's way back is a sudo account or physical access; the word exists only on
a plane the owner connected, and `install.sh`'s `derive_ssh_access` already
warns when root is the only way in. Accepted.

**On the management node.**

- `JobCommandBuilder::build_disable_root_login($node)` refuses a node whose
  agent lacks the word (`has_primitive` and `needs_newer_agent_text`, as
  `build_restart_unit` does, `JobCommandBuilder.php:2098-2102`), and refuses a
  container node (one with `mgn_mgh_managed_host_id`), naming the host's node
  (`ManagedHost::host_node()`) as the place to run it.
- The **Turn off root login** button sits in the node detail page's Security
  box behind the system modal, shown only where the restart buttons are: on a
  node with a host report (`node_detail_tabs/overview.php:1320`).
- `JobResultProcessor::process_disable_root_login` handles the result.

The builder and the result processor find the machine's provision with
`CustomerCloudProvision::for_machine_address($node->get('mgn_host'))`
(`customer_cloud_provisions_class.php:582`): one query, and on Docker it finds
the provision from the host's node, which is not the provision's
`cvp_mgn_managed_node_id`. A machine with no provision (self-hosted) has
nothing to refuse or retire.

The builder refuses while the provision is `held` or `retiring`: an install,
a Retry Install, or a cleanup still logs in as root with the password. That
includes a held row whose install failed (password kept for Retry); to close
root on that box, delete the instance. Races converge without the refusal's
help: a retire that runs after the new file lands expects `prohibit-password`
(`JobCommandBuilder.php:6084`), reads `no`, and fails into `retire_failed`,
which the completion below retires; a kept row Retired (3.5) after the disable
job is queued goes to `held`, and the retire job, refused at login, completes
through the executor's already-retired path (`InstallJobExecutor.php:210-217`).

When the job completes on a machine whose provision is `kept` or
`retire_failed`, the password we hold no longer opens anything over SSH, so
the row belongs in `retired`. The result processor writes one direct UPDATE
(as in 3.2's fallback, since nobody's vault is open): it nulls
`cvp_root_pass_vault_sealed`, clears the four convention columns, nulls
`cvp_root_pass_sealed` and `cvp_error` (fail_retire's "Install password NOT
retired" text, `ProvisionCustomerCloud.php:1121`, would otherwise stay on a
retired row and print first, `views/admin/index.php:943-946`), and sets
`cvp_install_password` = `retired`. It writes an event-log row
`break_glass_root_login_off` whose user is the job's creator
(`mjb_created_by`), since results are folded with no user session. A
retired row leaves the dashboard's provision list (its `open` filter,
`views/admin/index.php:395`), so the record of how it was closed is that
event, listed in the node's Security box (3.5) as "Root login turned off by
the agent", not a plain "Retired": the retire job's other sshd lines did not
run.

## 6. Work packages

- **WP1 — Self-hosted default.** Section 2, unchanged: install.sh's one sshd
  function for `server` and `docker`, `--keep-root-login`, the new default, the
  prompt, messages, help text, docs, `BASE_IMAGE_VERSION`. The install executor
  (its server and docker lines) and `AgentLiveProofRun` pass the flag;
  `SiteCopyRunner::owner_command` does not (section 2). **Built 2026-10-24.**
- **WP2 — Keep, reveal, retire.** The `kept` state and its first-line return,
  the keep decision and read-back check, the sealed field and its hooks,
  `cvp_root_access_offered`, the vault consumer, the holder setting, Reveal,
  Retire, Retire all requested, Retire all kept, the summary line, the transfer
  fix text, the incident source. Tests: kept when every condition holds and
  retired when any fails (each condition, including copy and bare modes); a holder with no vault leaves no plaintext and falls
  back to `held`; the buyer is never the seal owner; a retired-by-click box is
  never kept again; sweep saves of a kept row
  with no vault open succeed; a kept row with its node gone stays kept; Retire
  returns the row to `held` and the existing retire completes it; Reveal and
  Retire refused for a non-holder, a locked vault, or no second factor; rotation
  re-wraps a kept row; a keep whose seal landed but whose check then fails
  falls back to `held` with the column NULL; a seal that throws leaves `held`, NULL in the sealed
  column, and the SecretBox copy; the dashboard and node page render a kept row
  with no vault open; Retire's SecretBox copy opens with the executor's own
  reader.
- **WP3 — Site and agent.** `--beta-root-access` through `install.sh` and
  `_site_init.sh`; the wizard step and `beta_permissions_save`; the Management
  Node row; the agent's `root_access` report (agent version bump, signed
  release; no new agent operations); the endpoint entry, column and
  `MACHINE_COLUMNS`. Settings declared: `beta_root_access` (default on) and
  `beta_root_access_offered` (default empty), managed, in core `settings.json`;
  `server_manager_beta_root_access` (default on) and
  `server_manager_beta_root_access_holder` (default empty) in server_manager's
  `plugin.json`.

- **WP4 — Turn off root login.** Section 5.3: the agent word, its script and
  gate test (the script's one-way file, `sshd -t` refusal path, reload not
  restart, effective-value check), the pinned lists, the support bundle entry,
  the builder, button and result processor. Tests: the builder refuses a held
  or retiring provision and a container node; a completed job retires a kept
  and a retire_failed row with no vault open, clearing `cvp_error`; the
  effective-value check fails the job when another drop-in still wins; the
  script skips the reload when `ssh.service` is inactive; the gate test reads
  the unit name from `systemctl show -p Names` rather than assuming `ssh`.

WP1 ships alone. WP2 and WP3 ship together. WP4 ships on its own and is useful
without the others.

## 7. Decisions

- **Q1** Turning emergency root access off is permanent. — owner, 2026-10-24.
- **Q2** "Log access" in the wizard is the existing `agent_log_access` switch;
  no new split of logs. — owner, 2026-10-24.
- **Q3** Self-hosted root SSH login goes fully off by default, keys included;
  `--keep-root-login` keeps today's rules. — owner, 2026-10-24.
- Encrypted data roots are offered root access like any other machine. — owner,
  2026-10-24.
- Self-hosted sites get the wizard step with log access only. — owner,
  2026-10-24.
- The kept password is sealed to the holder's passkey vault. — owner, 2026-10-24.
- Removal waits for the holder's click (Retire), and runs the existing cleanup;
  nothing removes it unattended. — owner, 2026-10-24.
- The agent gets a one-way operation to turn off root login (5.3). — owner,
  2026-10-24. It runs only on a machine's own agent, never in a container, and
  a `Match` block that admits root is an accepted residual.
- Only fresh installs are kept: not boxes whose operator supplied root keys,
  bare instances or copies (adopted machines never reach the installer).
- No SSH keys of ours on any path (standing rule).
