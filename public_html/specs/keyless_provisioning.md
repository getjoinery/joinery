# Keyless provisioning — we never put a key on a machine we create

**Status: DIRECTION SETTLED BY OWNER 2026-08-30. Ready to build.**
Pulled out of `fleet_ssh_credential_custody.md`, which had this wrong.

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
4. **Then burn the bridge, and not before.** The burn is **plane-driven, at
   approval** — not at the end of the install. Once a human approves the join,
   the executor uses the still-sealed password one last time to disable root
   password login, and only then is the sealed password erased.

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
`from_backup` keeps its `scp` transfers. No StackScript changes, no Akamai
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
| Customer cloud, `from_backup` | us | **Yes** — `scp` still works over the password |
| Customer cloud, `bare` (infrastructure) | us | **Yes**, but see WP4 |
| Install New Node → new cloud instance | us | **Yes** — creates an admin-origin provision, so it is the three rows above |
| Paid hosting order onto a shared host | already exists, ours | N/A — a container on a host we already reach; nothing new is placed |
| Install New Node → existing host | already exists, ours | N/A — same |
| `discover_nodes` (adopt a box) | its owner | N/A — someone else's machine; its owner supplies the credential |
| `enable_agent` on an existing node | already exists | N/A — same |
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

**WP2 — Close the machine when the agent is up.** At the end of the site
install, once the agent is confirmed running: disable root password login, and
give `host-harden`'s empty-`authorized_keys` refusal the same condition. This is
the ~10 lines measured above. The safety check exists to prevent orphaning a
machine; the agent is a truthful answer that it is not orphaned.

**WP3 — Erase the password.** On install completion, erase the sealed password
from the provision row. After WP2 and WP3 nothing anywhere can log into that
machine but its owner and its agent.

**WP4 — every machine we create runs an agent ON THE MACHINE.**

*The unit of this rule is the machine, not the site.* Found in review, and it is
the finding that changes the shape of the work. On a Docker-mode provision — the
default for a customer VPS — the sited agent lives **inside the container**; its
join path reads `stg_settings`, which exists only in the site. After the burn,
the **host** has no key (never placed), no password login (disabled), and no
agent. "The agent is the access path" is then untrue of the machine itself, and
every future `on_host` operation for that node — reverse-proxy changes, container
rebuild, Docker repair, and certificate issuance for a direct-served customer
domain — has no transport, forever. That is G2's shape all over again, created
fresh.

So Docker-mode provisions install a **siteless host agent alongside the
container's sited one**. `install_agent.sh` v2.8's own header names "Docker hosts
that the plane manages but that host no deployment" as its purpose, so the
artifact exists. Two consequences to design for rather than discover: the host
needs an agent identity distinct from the container node's (the known
"Docker host has no agent identity" gap), and a VPS then involves **two join
approvals**.

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
