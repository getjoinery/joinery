# Keyless provisioning — we never put a key on a machine we create

**Status: BUILT 2026-09-03, live gate open.** WP1, WP4 and the host link
landed 2026-09-01; WP2/WP3 (retiring the install password) and WP5 (approval
checked with the provider, the join's address verified) landed 2026-09-03 —
see "Built" below. What remains is one live run per shape on a real instance.
Direction settled by owner 2026-08-30.
Pulled out of `fleet_ssh_credential_custody.md`, which had this wrong.

**The bootstrap executor (owner 2026-09-01: "simplest functional, then iterate").**
`install_node` runs plane-side over the provision's sealed root password —
`InstallJobExecutor` (a `local`/`ssh`-only runner, `ssh` via `sshpass -e`),
poked by the `RunInstallJobs` task spawning `utils/run_install_executor.php`.
It is deliberately install_node-only and does NOT replace the local queue.
Routing takes zero agent change: install_node jobs are created `queued`, a
status the node agent's `pending`-only claim never matches. Scope: fresh docker;
`scp`/cross-node (from_backup) and bare-metal are refused for now. This is the
"executor WP1" the sections below refer to; it is built (minimally).

**Landed 2026-09-01 (build the unblocked pieces first, owner call):**
- **WP1 (provisioning seal).** `ProvisionCustomerCloud` creates the instance
  with a root password and no key of ours, sealing it onto the new
  `cvp_root_pass_sealed` (SecretBox, `ephemeral` kind, declared in plugin.json
  and seeded to the registry). `handle_booting` leaves `mgn_ssh_key_path`
  empty. The old "key path unset ⇒ pipeline blocked" gate is gone. Class 1.5.
- **WP4 (agent on the machine), install.sh 2.56.** Docker mode installs the
  siteless host agent from the release's `agent_dist` and, given
  `--management-node=URL`, issues the CLI join (never fails the Docker install;
  the early Docker-present `exit 0` no longer skips the agent step). The Docker
  *site* path now honours `--enable-agent` inside the container — it was
  silently dropped before, so a container came up unmanageable. `host-harden`
  takes `--agent-managed`: a keyless machine whose access path is its joined
  agent can be hardened (retiring the install password), which its empty
  `authorized_keys` otherwise refuses.
- **WP5 (host link at approval).** `ManagedHost::link_host_node()` (class 1.2),
  called from `AgentChannelEndpoint::approveJoin`, names a machine-posture node
  as its host's own agent (`mgh_mgn_host_node_id`) when a placement record for
  its address exists with no host node yet — conservative (a container node
  with a web root links nothing; an already-linked host is never re-pointed).
- Tests: `agent_channel_test` (+ host-link section), `customer_cloud_provisioning_test`
  (+ keyless section) — both green on db tier.

- **Join wiring (2026-09-01, after the once-over).** `build_install_node` names
  this plane on both steps — `install.sh docker --management-node=URL` (the
  host agent asks to join) and `install.sh site … --enable-agent
  --management-node=URL` (install.sh 2.57: the container's agent runs
  `agent_control.php --on --join=URL`). Two join requests arrive without a
  human at a terminal. Shapes keyless cannot finish yet — bare, bare-metal,
  from_backup — are refused at `handle_ready` before an instance is created,
  and by the executor on the job's parameters before a step runs, instead of
  stalling in `installing` or dying mid-run. The job pages treat `queued` as
  live (polling, cancel, a queued-too-long notice naming the executor).
- **Live technical pass (2026-09-01, provision 2787 / keyless1) and its fixes.**
  The path ran end to end: instance born with a sealed password, install over
  it from the plane, both joins arrived unattended, both channels carried a
  status check. Found and fixed the same day: (1) a siteless agent never
  noticed its approval once the CLI's five-minute wait gave up — agent 1.16.0
  adds `StagedJoinWatcher`, so the running agent finishes the join itself
  hours later, and `join --no-wait` lets the install lodge the ask and move
  on; (2) joins claimed the machine hostname (`localhost`, a container id) —
  `join --name`, `install.sh docker --node-name`, and `--hostname SITENAME` on
  the site container; (3) fleet enrollment seeding at completion shelled out
  to keyed SSH — it now reaches a keyless node over the sealed password
  (interim: it leaves SSH for a primitive under `ssh_single_bootstrap.md`);
  (4) the Install New Node form says up front when a Linode grant (two hours,
  no refresh token) has expired. Not bugs: the container's site and agent come
  from getjoinery's release channel, not this plane's; a parked provision
  already emails the buyer a re-connect link.

**Built 2026-09-03 — retiring the install password (WP2/WP3) and WP5.**
`cvp_install_password` (provision class 1.5) is `held` from the moment the
password is sealed. `ProvisionCustomerCloud` 2.0 works `done` provisions in a
held state each pass: `machine_agents()` requires every agent the install put
on the machine to be admitted — the site node's, and on a docker box the
host's own agent named through the placement record — then queues a
`retire_install_password` job (`JobCommandBuilder::build_retire_install_password`
1.54: one ssh session, `install.sh -y -q host-harden --agent-managed` from the
release the bootstrap left under `/opt/joinery-install`, then `sshd -T` must
read `passwordauthentication no`). The job is a bootstrap type
(`ManagementJob::BOOTSTRAP_JOB_TYPES`, 1.14: created `queued`, claimed only by
`InstallJobExecutor` 1.5), and the executor completes it only after a fresh
login with the password was answered "Permission denied" — a login that still
works, or no answer inside the budget, fails the job. The next pass erases
`cvp_root_pass_sealed` on a completed job (`retired`); on a failed job it
keeps the password, writes the reason on the row, emails ops
(`retire_failed`), and a re-run of the job from its detail page is watched
for. A provision that fails with a live instance keeps its password until the
install is complete (owner, 2026-09-03). The dashboard's provisions table
keeps a finished provision listed until its password is retired and says
where it stands (`install_password_summary`).

WP5: the join endpoint records `SessionControl::get_client_ip(true)` (the
Cloudflare header only from a verified edge — the dev plane sits behind
Cloudflare, so bare `REMOTE_ADDR` was an edge address and could never match).
`CustomerCloudProvision::for_machine_address()` names the provision whose
instance the join came from; the join card shows provision, instance, age and
the password's state; `approve_join` runs
`ProvisionCustomerCloud::join_approval_check()`, which refuses unless the node
is the provision's site or a host record at its address, and the provider
reports the instance `running` at exactly the join's address. A second join
from that address for a node whose agent is already admitted is refused and
logged as an alarm. The design section step 4 (retirement is plane-driven
once the agent is admitted, not on "running") is the authority.

**Live gate, owed:** one real instance per shape (docker site, bare, bare
metal) run through join approval to `retired`, with a hand login attempt over
the old password refused afterwards; and one deliberate failure (approve
against a wrong node, and a provider that reports the instance stopped)
refused with its reason on the tab.

> **Status and ordering live in `agent_management_first_principles.md`.**
> This spec is an annex: it carries the design and nothing else. If this
> document and the programme disagree about what is done, the programme is
> right.

## The rule

**A machine this platform creates never receives an SSH key from us. Not a
shared one, not a per-node one, not a throwaway one.**

Any design that installs a credential and then removes it later is rejected,
however short the interval and however careful the removal.

**Nodes that already hold a key are dealt with manually.** No migration, no
sweep, no automated retirement path — deliberately, because building one is how
the removal machinery gets built, and the removal machinery is what we are
refusing.

## The design

**We already have the root password.** `createInstance` requires `root_pass`
and we generate it ourselves (`ProvisionCustomerCloud:124`), then throw it away
one line later. Keep it for the length of the install instead:

1. **Create** with `root_pass` and **no `authorized_keys`**. Seal the password
   onto the provision row. Nothing is placed on the machine.
2. **Install** over SSH with that password — the existing `install_node` job,
   unchanged, all sixteen steps.
3. **Pair.** The install enables the agent and records the management node's
   URL as a join request; the agent mints its own keypair on the machine and
   asks to enrol. A human approves at the plane.
4. **Then retire the install password, and not before.** Retirement is
   **plane-driven, once the agent is admitted** — not at the end of the install.
   Once a human approves the join, the executor uses the still-sealed password
   one last time to disable root password login on the machine, and only then
   is the sealed password erased. The word is deliberate: the password is not
   revoked or destroyed by some ceremony, it is retired — no longer accepted by
   the machine, no longer held by the plane.

Step 4 comes after step 3 for a reason, and the trigger must be **paired**, not
**running**. Approval is asynchronous and can be hours away; a box whose join is
never approved, or whose join request is lost, would otherwise end with password
login disabled, password erased, and an unproven channel — orphaned, which is
exactly what this design exists to prevent. An agent process that is up is not
an agent that has been admitted.

The password is the fallback for exactly as long as the channel is unproven, and
is destroyed the moment it is proven. Nothing was ever placed on the machine, so
there is nothing to go back and remove — the credential dies when we forget it.

**Why not a key, once more, since three drafts got this wrong:** a key is
installed *on the machine*; a password is not. That is not a difference in the
kind of secret, it is the whole difference — it is whether anything is left
behind that has to be taken back. The throwaway-key design was built, produced
roughly two hundred lines of *retirement* machinery (strip-on-pairing sweep,
abandonment window, best-effort-versus-mandatory destruction asymmetry,
orphaned-key logging, a pruned directory in the permissions sweep), and was
reverted. The patch is kept at
`scratchpad/rejected_throwaway_keypair_wp2.patch` as evidence of the cost, not
as work to resume. **That machinery's existence was the proof the design was
wrong.**

## Why this is a minimal change

The test the owner set: if this needs substantial install-script changes, the
approach is wrong. Measured:

**Install scripts: about ten lines, one file, no structural change.**
`derive_ssh_access` (`install.sh:498`) already asks the right question — *will
anyone still be able to reach this box if I disable root login?* It has three
answers: root has keys (mirror to user1, safe), running under sudo (that account
keeps access, safe), or neither (leave root password login enabled and tell a
human to add a key, `:528-538`). A keyless managed box is a fourth answer: **the
agent is the access path**, so disabling root login orphans nobody.

That fourth case cannot go in `derive_ssh_access` itself. It runs during
`install.sh server` (called at `:2490`), configuring sshd *before* the site or
the agent exist — `ENABLE_AGENT` is a local in the `site` function and is not
even in scope there. Asserting it early would disable root login before the
thing replacing it is installed, and orphan the machine if the install then
failed. So it belongs at the **end of the site install**, once the agent is
confirmed running, plus a matching condition in `host-harden`'s empty-
`authorized_keys` refusal (`:1598-1607`) for the case where someone runs that
later.

**One addendum to "ten lines, one file":** the **bare-metal** branch of
`build_install_node` also changes. Its "Pre-stage user1 for managed access" step
hard-aborts on precisely this input —
`sudo test -s /root/.ssh/authorized_keys || { echo 'FATAL: ... Aborting before
install.sh server locks out root SSH.'; exit 1; }`
(`JobCommandBuilder.php:3492`). Keyless bare-metal dies there.

It cannot simply be skipped. The whole pre-stage-and-switch-to-user1 dance exists
to survive `install.sh server` disabling root login — which keyless never
triggers, because `derive_ssh_access`'s third branch leaves root password login
enabled when root has no keys and there is no sudo user. So the keyless
bare-metal path is: **drop the pre-stage and the user switch, stay root-over-
password for the whole job.** Deletion-shaped, but it must be owned here rather
than discovered during build. Docker mode is genuinely unchanged — that branch's
own comment notes the Docker subcommand does not harden SSH, so root access stays
intact.

**Provisioning: mostly deletion.** Stop passing `authorized_keys`; seal the
`root_pass` we already generate instead of discarding it; use it for the SSH
steps; erase it on completion.

**Nothing else changes.** The `install_node` job still exists, so the provision
state machine keeps its ending — `handle_installing` watches the same job, the
welcome email and `ProvisionPendingSsl` keep the same order-item linkage.
`from_backup` keeps its `scp` transfers for now — it becomes install.sh's
`--clone-from` over HTTPS under `ssh_single_bootstrap.md`. No StackScript changes, no Akamai
round trip, no join-approval redesign, no new completion path.

**An earlier draft of this spec proposed exactly those four things** — routing
provisioning through StackScript 2185451, teaching the driver `user-data`,
rewriting the published script to carry a plane URL, and inventing a completion
path to replace the vanished install job. All of it existed only to avoid using
the root password. The owner's minimal-change test is what caught it.

## What this depends on

**One real dependency: the executor must be able to authenticate with a
password.** Today `install_node`'s SSH steps are executed by the Go agent,
whose client is `sshConfig.Auth = []ssh.AuthMethod{ssh.PublicKeys(signer)}`
(`ssh.go:85`) — public key only, no password method in the binary.

**As of 2026-08-30 the agent cannot speak SSH at all.** Its SSH and SCP
transport was removed deliberately (397 lines across `ssh.go`, `scp.go`,
`runner.go`, `db.go`, `server.go`); `ssh`/`scp` steps now return
"SSH and SCP capability is deprecated". Running on the dev management node from
14:47 UTC. This is a forcing function, not a completed migration — the plane
still composes those steps, and every one of them now fails loudly until the
executor exists.

**Do not fix this by adding password auth to the agent.** That is new plumbing
on the component we are shrinking, in service of a delivery path that is moving
off it anyway. The plane-side executor already required by
`agent_local_queue_retirement.md` (G1) gets password auth for free — `sshpass`
is present on the management node and PHP can shell out. This is shared work,
not new work, and it is the reason this spec sequences behind that executor.

## Coverage: every install path

| Path | Who creates the machine | Keyless |
|---|---|---|
| Customer cloud, `fresh` | us | **Yes** |
| Customer cloud, `from_backup` | us | **Yes** — clone over HTTPS inside the one session (`ssh_single_bootstrap.md`) |
| Customer cloud, `bare` (infrastructure) | us | **Yes**, but see WP4 |
| Install New Node → new cloud instance | us | **Yes** — creates an admin-origin provision, so it is the three rows above |
| Paid hosting order onto a shared host | already exists, ours | N/A — a container on a host we already reach; nothing new is placed |
| Install New Node → existing host | already exists, ours | N/A — same |
| `discover_nodes` (adopt a box) | its owner | Deleted — the owner enrolls from the node's own page (`ssh_single_bootstrap.md`) |
| `enable_agent` on an existing node | already exists | Deleted — same |
| Relay provisioning | us | **Exempt** — see below |
| Linode StackScript self-serve | the customer | Already keyless, and unchanged by this spec |
| Manual `install.sh` | a human | Already keyless |

**The self-serve StackScript is not affected.** Its `SSH_KEY` field is optional
and belongs to whoever deploys it — *"Without one it leaves root login alone, so
omitting the field cannot lock anybody out"* (`linode_stackscript.sh:120-135`).
No credential of ours goes near it. **Our provisioning path must never populate
that field.**

**The relay is exempt, by A8 rather than by this spec.** We do create it, but A8
says it never runs an agent, so there is no channel and SSH is the only reach.
`RelayCloudProvisioner` already mints per-run and destroys with the instance
(`generateSshKeypair():671`, rebuild mints fresh at `:286-296`).

**A machine that does not run an agent *yet* is not this case.** Its reach is
the root password, for the length of the install. The exemption applies only to
a machine that will **never** run one. Without that distinction the exemption's
own words — "no agent, therefore no channel, therefore a key" — are exactly the
throwaway design's argument, since a fresh instance has no agent either at the
moment we would be installing a key.

## Separate question: the relay-shard pull key

Raised in review (public-html-cb) and verified. `build_provision_relay` runs
`provision_relay.sh add-tenant --pull-pubkey <key>`
(`JobCommandBuilder:3906-3963`), installing the management node's pull public
key on the target; relay shards are a bare-node workload, so a machine we create
does receive a key.

**This is not a provisioning credential and is not settled by this spec.** It is
a *standing* credential for a recurring mail fetch that runs every few minutes
for years — a root password used once at build time cannot do that job. What it
installs is narrow: `command="joinery-tenant-shell <slug>",restrict` in an
unprivileged per-tenant account at 0600 (`provision_relay.sh:448-452`).
`restrict` denies pty, port forwarding, agent forwarding, X11 and user-rc; the
forced command permits only rsync of that one tenant's spool and refuses the
`--daemon`/`--config` escape (`:758`). It cannot get a shell, cannot reach
another tenant, and does not touch root.

The open question is whether mail pull should use SSH at all, or a token over
HTTPS. **Tracked separately; do not fold it into this spec, and do not treat it
as licence for any other key.**

## Work

**WP1 — Provision with the password, not a key.** `handle_ready` stops passing
`authorized_keys` and seals `root_pass` onto the provision row instead of
discarding it. `handle_booting` leaves `mgn_ssh_key_path` empty. Sequenced
behind the plane-side executor (see dependency above). Decide at the same time
whether `rebuildInstance` — which also accepts `authorized_keys`
(`LinodeComputeDriver:60-78`), today only from `RelayCloudProvisioner:293`
— stays relay-only.

**WP2 — Retire the install password when the agent is ADMITTED: the machine
stops accepting it.** Retirement is plane-driven once the agent is admitted,
not at the end of the install — a running agent is not an admitted one (see
the design section, step 4). Once a human approves the
join, the executor uses the still-sealed password one last time to disable root
password login. The install-script half is done: `host-harden --agent-managed`
gives the empty-`authorized_keys` refusal the fourth answer (a keyless machine
whose access path is its joined agent is not orphaned). The safety check exists
to prevent orphaning a machine; an admitted agent is a truthful answer that it
is not orphaned.

**WP3 — Retire the install password: the plane stops holding it.** Once root
password login is disabled, erase the sealed password from the provision row. After WP2
and WP3 nothing anywhere can log into that machine but its owner and its agent.
A provision that ends with *no instance created* erases it immediately (there
is no machine it opens) — built. A provision that fails with a *live* instance
keeps it for manual recovery: an owner call on WP3's list, not settled here.

**WP4 — every machine we create runs an agent ON THE MACHINE.**

*The unit of this rule is the machine, not the site.* Found in review, and it is
the finding that changes the shape of the work. On a Docker-mode provision — the
default for a customer VPS — the sited agent lives **inside the container**; its
join path reads `stg_settings`, which exists only in the site. Once the
install password is retired, the **host** has no key (never placed), no password login (disabled), and no
agent. "The agent is the access path" is then untrue of the machine itself, and
every future `on_host` operation for that node — reverse-proxy changes, container
rebuild, Docker repair, and certificate issuance for a direct-served customer
domain — has no transport, forever. That is G2's shape all over again, created
fresh.

So Docker-mode provisions install a **siteless host agent alongside the
container's sited one**. `install_agent.sh` v2.8's own header names "Docker hosts
that the plane manages but that host no deployment" as its purpose, so the
artifact exists. The host's identity shape is settled and built
(`docker_host_agent.md`, 2026-09-01): the host is a **plain ManagedNode in
machine posture**, and the placement record's `mgh_mgn_host_node_id` names it —
that link is how host-scope work (decommission_site today; certificates and
container install through the same door) is routed. One consequence remains to
design for rather than discover: a VPS involves **two join approvals**.

**The default is the machine, every path (owner, 2026-09-01):** any FRESH
machine that runs our Docker gets the host agent as part of the install itself
— `install.sh`'s docker mode installs it siteless and issues the CLI join —
not only the customer-cloud provisioning path. A docker host without an agent
identity has no path for certificate renewal or site removal once SSH is gone,
whoever created it. Existing docker hosts are the manual-enrollment case, per
the rule.

**Approving a HOST join must also set the link.** A host agent that pairs but
is never named in `mgh_mgn_host_node_id` is routed to by nothing — the
approval flow (WP5's card, or the host edit page it points at) sets the
placement record's link when the joining machine is a provision's host, so
host-scope routing works without a separate manual step to forget.

The rejected alternatives: keeping the sealed password forever for Docker hosts
is a standing plane-held credential — the shared-key defect in new clothes; and
accepting unmanaged hosts contradicts both container-rebuild and certificate
reality.

**Bare mode, within that:** A bare instance installs nothing today and
is verified by a `check_status` SSH probe, so "bare node" currently *means* "a
machine with a key we can SSH to." Keyless, it must run the siteless agent or it
is unmanageable. Three pieces already exist: `install_agent.sh` v2.8 supports
`--siteless --dist-dir=DIR [--enable]` (`:6`, `:70-71`, `:110-152`); the agent
joins without a site via `joinery-agent join --management-node=URL`
(`main.go:312`, `:325`); and `AgentChannelEndpoint` v1.8 serves the binary to a
machine with no site tree. Note the trigger differs: a sited box can have the
install write the `agent_join_request` setting, which `JoinWatcher` reads with
`SELECT stg_value FROM stg_settings` (`join.go:388-411`); a bare box has no
`stg_settings` and must use the CLI join.

**WP5 — Approve against the provider, not the stored row.** Because the plane
created the instance it can offer one-click approval — but `cvp_instance_id`
and `cvp_instance_ip` go stale (the customer can delete the instance, a
boot-timeout failure deliberately leaves the provision in place, providers
recycle IPv4). At approval time call `getInstance` and require `status ==
running` **and** the provider's current IP to equal the join's source IP;
first-join-wins with an alarm on a second join for the same provision; show
instance ID, IP and age on the card. Keep displaying the key fingerprint — it
costs nothing and allows a cross-check over the provider's serial console.

A6 stays intact: no secret is shared, the private half is born on the machine,
and a human still approves. **The rejected alternative, recorded so it is not
re-proposed:** automatic approval via a per-instance nonce delivered through the
provider API. That *is* a reversal of A6 and must be raised and decided as one,
never slipped in as an implementation detail.

## Acceptance

See the programme's single acceptance list — the criterion this spec is
responsible for is marked *(item 4)* there.

## Related

- `agent_local_queue_retirement.md` — G1's plane-side executor, which WP1 needs
- `fleet_ssh_credential_custody.md` — WP2 there is superseded by this spec; its
  WP1, WP3, WP4 and WP5 are unaffected
- `agent_management_first_principles.md` — the credential doctrine this obeys
- `agent_machine_posture_and_relay_converge.md` — A8, which exempts the relay
