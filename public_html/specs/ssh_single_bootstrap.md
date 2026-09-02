# SSH is one bootstrap, run once — everything else is the agent

**Status: BUILT 2026-09-02, awaiting live verification on the keyless boxes.**
WP1–WP5 and B1 are implemented (agent 1.17.0 adds `clone_export_arm` and
`fleet_enroll`; `build_install_node` is one session; `ProvisionPendingSsl`
observes then asks, with a container's certificate issued on its host;
`FleetProvisionSeeding` dispatches a primitive and waits for pairing;
`enable_agent`, `discover_nodes`, `provision_ssl` and the SSH helpers are
deleted). Two schema columns were added (`cvp_clone_key_sealed`,
`cvp_fleet_seed_state`) and need the plugin sync on each deployment. Found and
fixed while building: **B2** — in quiet mode (every plane-driven install)
install.sh never armed the deferred-SSL retry timer at all, because arming
lived inside the verbose summary; it is armed before the summary now, on a
colon-separated candidate list the timer resolves when it fires. Nothing below
has run against a live box yet; the Acceptance list is the live gate.

**Review 2026-09-02 (public-html-01), all findings resolved in code:** the
install form creates cloud instances only (its existing-server target could
never run since keyless and would have armed a source with nothing to disarm
it); one clone per source at a time, armed once, disarmed only by the
provision that armed it, released after seven days on a failed provision, and
the bootstrap job's copy of the key blanked at completion; a bare-metal
bootstrap is **one-shot** (install.sh server disables root password login and
the password was the only credential — Retry Install refuses it by name);
chain jobs key on `for_node_id` so a node that issues for others never sees
their jobs as its own; the release lands under `/opt/joinery-install`, not
`/tmp`; the SSL button observes before it asks. Deliberate and recorded:
`clone_export_key` is declared plain, not `secret` — it is a single-provision
bearer token the redactor masks on display, and `Setting::put` writes
plaintext either way. **Ordering the owner will meet:** cloning FROM an
existing node needs that node on a core release that ships
`utils/clone_export_arm.php` and on agent 1.17.0; fleet seeding needs the new
site's agent at 1.17.0, which a fresh install gets from the release it fetched.

Originally written as DIRECTION on 2026-09-02, consolidating the SSH
disposition that was spread across `plane_side_executor.md`,
`agent_local_queue_retirement.md`, `agent_management_first_principles.md` and
`keyless_provisioning.md`, two of which pointed the other way (they moved
`provision_ssl`, `enable_agent` and `discover_nodes` onto the plane-side
executor, which keeps them on SSH). This spec is the single place the
remaining SSH surface is enumerated and dispositioned. Those four now point
here for it.

> **Status and ordering live in `agent_management_first_principles.md`.**
> This spec is an annex: it carries the design and the disposition table. If
> it and the programme disagree, the programme wins.

## The rule, in plain terms

The management node reaches a machine over SSH exactly once in that machine's
life: to run the installer. The installer puts an agent on the machine and the
agent asks to join. From the moment the join is approved, the plane talks to
the machine only through the agent, and the credential the bootstrap used is
erased. Nothing else the plane does — certificates, restores, enrollment,
adopting a box, turning an agent on — may open an SSH session.

Stated as a test, the same way the programme states its bar: **if an operation
other than the first install opens an SSH session from the plane, it is a
defect in that operation, never a reason to keep a key.**

## What "one bootstrap file" means

The file is `maintenance_scripts/install_tools/install.sh`. It already does
everything a fresh machine needs and already knows about the plane:

- `install.sh docker --management-node=URL --node-name=NAME` installs Docker,
  installs the siteless host agent from the release's `agent_dist`, and lodges
  the host's join request (`--no-wait`, so nobody has to be at a terminal).
- `install.sh site NAME DOMAIN … --enable-agent --management-node=URL` creates
  the site, turns its agent on, and lodges the site's join request.
- Without `--no-ssl`, the docker site path writes the universal proxy vhost on
  the host (`default_proxy_vhost.conf`: both ports send
  `X-Forwarded-Proto https`, the :443 block is guarded by `<IfFile>` on the
  certificate path), tries for a certificate, and if DNS is not pointed here
  yet arms the host's own retry timer (`arm_ssl_retry.sh`) which issues the
  certificate on its own once it is.

So the bootstrap is one SSH session running one shell line: fetch the release,
run install.sh twice (docker, then site). The Linode StackScript path is the
same shape already, from the machine's own first boot, and is keyless today.

**The executor stays exactly what `keyless_provisioning.md` made it:**
`InstallJobExecutor` running `install_node` over the provision's sealed root
password, poked by the `RunInstallJobs` task. What changes is the job it runs.

### The install job today, and what leaves it

`build_install_node` emits 32 `ssh`/`scp` steps. Five are bootstrap. The rest
are work on a machine that, by the time they run, has an agent:

| Steps | What they do | Disposition |
|---|---|---|
| Ensure curl; download and extract the release; install Docker and host agent; create the site | The bootstrap | **Stay**, collapsed into one command |
| Pre-stage user1; switch the SSH user to user1 | Keep a login working for the plane's *next* SSH session | **Delete.** There is no next session |
| Set up HTTP reverse proxy (`manage_domain.sh set … --no-ssl`) | Writes the OLD proxy vhost: :80 only, `X-Forwarded-Proto http`, no :443 block. Confirmed on the keyless1 host | **Delete.** Drop `--no-ssl` from the site command and install.sh writes the universal vhost itself. This is also what makes the proto-patch step of `provision_ssl` unnecessary |
| Report published container port; record container name | Ledger facts | The install output already carries the port; the executor parses it. The container name is the sitename the plane chose — set it in PHP when the job is created, no step |
| Verify install; clean up installer | A second SSH round trip to look at what install.sh just did | **Delete.** Two join requests arriving IS the verification, and the provision already completes at approval. The extracted release tree stays (see B1) |
| From-backup: 21 steps that SSH into the paired SOURCE node to dump and fetch, scp to the target, and restore inside it | Cloning | **Leave the bootstrap.** See below |

After this the job's remote side is: one `ssh` step. `scp` has no emitter left
in it.

### From-backup is not a restore over SSH — it is a clone over HTTPS

install.sh already has the answer: `--clone-from=URL --clone-key=KEY` pulls the
source site's database, uploads, themes and plugins over HTTPS from the source
site's own `utils/clone_export` endpoint, inside the site install. The source
is reached by its web address, not its shell. Nothing SSHes into the source.

What the plane has to do is arm the source: `clone_export_key` is a setting on
the source site, off by default. That is a settings write on a paired node, so
it is a primitive with the name compiled in — the exact shape of
`managed_domain_notice` (four values in, the names live in the node-side
script, the plane cannot express which setting it is writing). One value: the
key. The node-side script writes it through `Setting::put`, and the plane
disarms it the same way when the provision completes. A key that lives for one
provision and is scoped to a read-only export is the right size for a job row;
it is redacted on display like everything else under `SmSecretRedactor`.

Two things this settles that the SSH version got wrong:

- The 16-hour give-up and the mid-transfer failure modes go away with the
  transfers. A clone that fails is install.sh exiting non-zero, which the
  executor already reports.
- `clone_export` streams the source's sealed settings as ciphertext the target
  cannot open (its SecretBox key differs). That is a pre-existing gap owned by
  `sealed_settings_reconciliation.md`, not made worse here, and the reason a
  cloned site's sealed secrets are reconciled on first boot rather than assumed.

The bare-metal shape needs nothing new: `install.sh server` then `install.sh
site … --enable-agent --management-node` is the same one session. The `bare`
shape (a machine with no site) is `install.sh docker --management-node` alone.
The executor's refusal of all three was a scope choice for the first live pass
and lifts with this work.

## Everything else that still opens SSH, and where it goes

Measured 2026-09-02 against `JobCommandBuilder.php` (builders still emitting
`ssh`/`scp` steps), `plugins/mailbox/includes`, and the live node table.

### Goes through the agent

**`provision_ssl`** — 7 `ssh` + 1 `local`, taken by every container node and
every unpaired node. It is three operations in one builder, and each already
has an agent-side home:

| Part | Agent home | State |
|---|---|---|
| Run certbot | `provision_certificate` primitive → bundled `setup_ssl.sh` → install.sh's `provision_origin_cert`. Addressed to the **host** node for a container (via `mgh_mgn_host_node_id`, the same link `decommission_site` routes on), to the node itself on bare metal | All four keyless host agents report it; the bundle with both scripts is on the keyless1 host at `/opt/joinery-agent/tree` |
| Prove a Cloudflare domain routes here | `ssl_probe_place` / `ssl_probe_clear` on the **site** node; the plane fetches the token | Built, site agents report both. A container serves the token through the host proxy, so nothing changes for it |
| Patch `X-Forwarded-Proto` into the certbot-generated vhost | Nothing — it exists only because the install wrote the old vhost | Dies with the universal vhost |

Beyond that, most issuance needs no dispatch at all: install.sh armed the
host's retry timer, and the host's siteless `check_status` already reports
every certificate under `/etc/letsencrypt/live`. So `ProvisionPendingSsl` for a
container node becomes **observe first, dispatch second**: `mgn_ssl_state`
flips to `active` when the host reports a certificate for the domain; the
`provision_certificate` dispatch to the host is the "issue now" button and the
slow-lane retry, not the primary path. For a Cloudflare domain the chain is the
probe pair on the site node and `active` on verified routing, with no
certificate and no patch.

`uses_primitive_route()` therefore stops asking "bare metal?" and asks "which
node issues for this one?": the node itself when it is bare metal, its host
node when it is a container and that host is paired. A container on a host with
no paired host agent has no issuance path — that is the manual-enrollment case
`keyless_provisioning.md` already states for existing docker hosts, and today
it is exactly one host (23.239.11.53, eight containers, all already `active`;
renewals there are certbot's own timer and unaffected).

Deleted with it: `build_provision_ssl`, `proto_patch_cmd`, `process_provision_ssl`,
the SSH branch of `ProvisionPendingSsl::run()`, the `provision_ssl` lookups in
`node_detail_tabs/overview.php`, and `has_ssh()`'s last caller.

**Fleet enrollment seeding** (`FleetProvisionSeeding`) — a second SSH session
over the same sealed password at install completion, writing three mailbox
settings (`mailbox_fleet_service_url`, `mailbox_fleet_api_public_key`,
`mailbox_fleet_api_secret_key`) into the node's database by scraping its DB
password out of `Globalvars_site.php`. It becomes a primitive after pairing,
compiled-names shape again: three values in, the node-side script writes them
through `Setting::put`. It runs from the provision's completion handler at
approval, where the node is paired by definition.

The secret question, answered: the plane already mints this key, holds its
hash, and is the API it authenticates *to*; a plane compromise owns that API
with or without the job row. So the row is not a new holder of anything. It is
redacted on display, and the completion handler blanks `mjb_parameters` once
the node reports done, so the plaintext does not outlive the job. What seeding
was written to avoid — a secret in a `bash -c` string on a web-writable table
that root executes — is the local queue, and that is what is being retired.

### Deleted — the replacement already exists

**`enable_agent`** — 2 `ssh` steps; one button on the node's API keys tab,
shown only for a node with an SSH key path. It installs and joins an agent on a
node the plane can already SSH into. Every node that still has a key is
already paired, every install path installs and joins the agent itself, and
the same tab already prints the replacement: the node's own *Admin → System →
Management Node* page, where the node asks to join. That is the
manual-enrollment rule for existing machines. Delete the builder, the action
case, and the button.

**`discover_nodes`** — `local` steps that SSH into a box to look for Joinery
sites, driven from `node_add.php`. Adopting someone else's machine is the
owner enrolling it from their node's own Management Node page; the plane never
needs a shell on it. Delete the builder, the logic action, and the discovery
form on `node_add`; the page keeps "add a node by its join request".

**The five relay builders** (`provision_relay`, `rebuild_relay`,
`relay_add_tenant`, `relay_set_domains`, `relay_remove_tenant`) — dead since
agent 1.13.1 refused `ssh` steps; disposition unchanged from
`agent_local_queue_retirement.md`: delete at the relay cutover.

### Stays on SSH by decision, and is not a node

The relay (`RelaySsh`, six caller files in the mailbox plugin) and the two
ScrollDaddy DNS boxes. They run no agent, hold no customer data, and their
management surface is a port probe plus full reprovisioning
(`feedback_dns_relay_disposable_never_agented`, owner). They are not in the
job system and this spec does not touch them.

### Already crossed

`check_status`, `list_backups`, the three restores, `decommission_site`,
`ProvisionManagedDomains` and `ManagedDomainWatch`. Recorded so nobody
re-inventories them.

## The whole surface after this spec

| Reach | Transport | Runs |
|---|---|---|
| `install_node` | `InstallJobExecutor`, `sshpass -e` over the sealed root password | Once per machine we create; the password is burned at approval (`keyless_provisioning.md` WP2/WP3) |
| Relay, DNS boxes | `RelaySsh` / personal key | Disposable machines, not nodes |

Nothing else. `node_health_probe_test.php`'s `$expected_ssh_only` list shrinks
to `install_node` plus the five relay names until the relay cutover deletes
those, and the test fails on any newcomer.

## Work

Ordered so each step is live-testable on the keyless boxes before the next.

**WP1 — One session.** Collapse `build_install_node` to the bootstrap steps:
fetch, `docker --management-node`, `site … --enable-agent --management-node`
without `--no-ssl`. Delete the user1 pre-stage/switch, the `manage_domain.sh`
proxy step, the verify and cleanup steps, the port and container-name steps
(port parsed from output; container name set in PHP). Lift the executor's
bare / bare-metal refusal — same command shape, different install.sh verbs.
`manage_domain.sh` loses its last plane-side caller.

**WP2 — Clone, not restore.** `from_backup` becomes `--clone-from` /
`--clone-key` on the site command. New primitive, compiled-names shape:
`operate_clone_export_arm` (one value, the key; blank disarms). The provision
arms the source at `handle_ready`, passes the key in the bootstrap command,
disarms at completion. Lift the executor's `from_backup` refusal. Delete the 21
transfer/restore steps and their teardown.

**WP3 — Certificates.** `ProvisionPendingSsl`: observe the host's reported
certificates first; `provision_certificate` to the host node (container) or the
node (bare metal) as the on-demand and slow-lane path; probe chain for
Cloudflare. `build_provision_certificate` accepts a container node and resolves
the host. `process_provision_certificate` stamps the node the job was *for*,
carried in its params, not the host it ran on. Delete `build_provision_ssl` and
everything listed above.

**WP4 — Seeding over the channel.** `operate_fleet_enroll` primitive, three
values, compiled names. `FleetProvisionSeeding::seedNode` builds and dispatches
it from the approval-time completion path instead of running `ssh`; its
`runSsh`, `buildRemoteCommand` and the `sshpass` branch go. Completion handler
blanks the job's parameters.

**WP5 — Delete `enable_agent` and `discover_nodes`.** Builders, logic cases,
the API keys tab button, the discovery half of `node_add.php`, their tests.
Shrink `$expected_ssh_only`.

**B1 — the SSL retry timer points at a tree the install then deletes.** In
docker site mode install.sh arms the timer with
`$ARCHIVE_ROOT/…/setup_ssl.sh`, prints that the archive "is no longer needed",
and the install job's teardown removes it — so the timer would fire into a
missing script. On a docker host the script that is maintained is the host
agent's bundle (`/opt/joinery-agent/tree/maintenance_scripts/sysadmin_tools/setup_ssl.sh`,
present and versioned on keyless1). install.sh arms the timer at the bundle
path when the host agent is installed, and the bootstrap no longer deletes the
tree (WP1). Fix with WP1; it is what makes "the host issues its own
certificate" true.

## Acceptance

- Provisioning a customer cloud instance of each shape (`fresh`, `from_backup`,
  `bare`; docker and bare metal) opens exactly one SSH session to the new
  machine, and none to any other machine. A bare-metal bootstrap is one-shot:
  a failure after `install.sh server` is finished from the provider console or
  by provisioning again.
- `grep -c "'type' => 'ssh'\|'type' => 'scp'"` over `JobCommandBuilder.php`
  counts only `build_install_node` and the relay builders; `$expected_ssh_only`
  agrees and the health-probe test passes.
- A container node's `mgn_ssl_state` reaches `active` with no `provision_ssl`
  job ever created, on the four keyless boxes (each is `pending` today, stuck
  on the dead SSH path).
- A from-backup provision completes with zero `scp` steps and no job of any
  kind against the source node other than the arm/disarm primitive.
- Fleet seeding on a keyless node produces one `fleet_enroll` job and no SSH
  process; the job row holds no secret after completion.
- The API keys tab has no "over SSH" button; `node_add` has no discovery form;
  `class_exists` and route checks for their handlers are false.
- `FleetProvisionSeeding.php` and `JobCommandBuilder.php` contain no `sshpass`
  or `ssh -i`; `InstallJobExecutor.php` is the only file under
  `plugins/server_manager` that does.

## Related

- `agent_management_first_principles.md` — the programme; this is item 6
- `keyless_provisioning.md` — the bootstrap executor and the burn this spec
  runs behind
- `plane_side_executor.md` — the executor's design; its "what moves" table
  defers to this spec for the operations that go to the agent instead
- `agent_local_queue_retirement.md` — the audit that found the thirteen; its
  WP2 table defers here for the same rows
- `docker_host_agent.md` — the host node identity and the placement link the
  certificate routing uses
- `origin_tls_and_certificate_issuance.md` — the deferred certificate work on
  the legacy shared host; unblocked by a host agent there, not by this spec
- `sealed_settings_reconciliation.md` — owns what a clone does with ciphertext
  it cannot open
