# Machine posture, relay convergence, and Docker hosts (WP4)

**Status: R1 BUILT AND LIVE (agent 1.10.0, fleet-wide); R2–R5 approved to
build.** The support bundle (§4) was approved by the owner on 2026-08-28,
unblocking R2. This spec **owns the remainder of the transport migration**:
the architecture spec (`specs/implemented/agent_on_node_architecture.md`,
whose A13 records the siteless decision) is implemented and frozen; Step 2b,
the Step 3 cutover, its deploy-tier gate and the destruction of the shared
provisioning key are all carried here as R2–R5.

**Design bar for production installs (owner, 2026-08-28):** the operator's own
managed boxes keep sshd for troubleshooting (A11 stands; jeremytunnell may
join service posture later, undecided) — but a production box rolled out
generally must be designed to need **no SSH for any maintenance reason**. A
maintenance task that requires a shell on a production install is a vocabulary
gap to close, never a runbook step. This is the standing acceptance test for
every primitive and every rung the sentinel spec later adds.

---

## 1. What the owner asked for, and the one thing that does not fit

Three decisions define this package:

1. The agent gains a **siteless machine posture** — it runs on machines the
   plane manages that host no Joinery site.
2. The relay is managed by **one idempotent primitive**, `relay_converge`,
   taking the full desired tenant/domain state as parameters, **embedded Go**.
3. **Docker hosts** run the same siteless agent, and certificate work for
   container fleets becomes host-agent jobs. The cutover waits for both, so the
   shared key dies everywhere at once.

Decisions 1 and 3 are sound and the code supports them. **Decision 2's
"embedded Go" cannot be met**, and §5 argues it should not be. The short
version: the relay's provisioning surface is `apt-get`, `systemctl`,
`postconf`, `postmap`, `ufw`, `useradd` and `wg`. None of those has a Go
equivalent that is not a reimplementation of the tool, and a primitive package
that may not start a process cannot call them at all. What the relay needs is
not Go instead of a script — it is a script the node can **verify**, which is
the thing a siteless machine cannot do today.

That gap turns out to be the same gap as decision 1's self-update problem. One
mechanism closes both, and Docker-host certificates as well. That mechanism is
§4, and it is the spine of this package.

---

## 2. Why a siteless machine cannot run the agent today

Four blockers, in the order a starting process hits them. All verified in code.

**B1 — The agent never finishes starting.** `LoadConfig` (`config.go:147-158`)
returns an error unless it can find a database name *and* password, and
`loadConfigWaiting` (`main.go:56-73`) loops on that error for ever by design:
"it never gives up and it never exits". A machine with no
`Globalvars_site.php` therefore parks in that loop and reaches nothing else.
This is correct behaviour for a node mid-upgrade and fatal for a relay.

**B2 — Everything downstream is derived from a site tree.**
`config.go:124-131` sets `SiteRoot` to the config file's grandparent and
derives `WebRoot` and `AgentDistDir` from it. `startRemoteSource`
(`main.go:110-124`) builds `ExecEnv` from those and calls
`releaseVerifier(cfg.SiteRoot)`.

**B3 — No manifest means no script primitive, ever.** `runScriptPrimitive`
(`script.go:80-90`) refuses when `SiteRoot` is empty, and `ArtifactManifests`
resolves every path against a `RELEASE_MANIFEST` at the site root. A siteless
machine has neither, so **its whole vocabulary is embedded-`Run` primitives**.
That is the constraint decision 2 was reacting to; §4 removes it rather than
working around it.

**B4 — Enrollment runs through the site database.** `JoinWatcher` reads and
writes the `agent_join_request` / `agent_join_state` settings rows
(`join.go:388-420`), and `SwitchWatcher` reads `agent_enabled` the same way.
The node's own `/admin/admin_management_node` page is the operator surface. A
siteless machine has no database, no settings table and no admin page.

Two more that bite before any of the above:

- **`install_agent.sh` refuses the machine outright**: `[ -f "$SITE_CONFIG" ]
  || { say "site not initialised yet - skipping"; exit 0; }`. A8 called that
  "already the right condition"; under this package it is exactly wrong.
- **`agent_enabled` cannot be read**, so the switch that decides whether the
  agent runs has no value on a machine with nowhere to store it.

---

## 3. Self-update for a siteless machine (question a)

**What exists.** `Updater.CheckAndApply` (`update.go`) reads
`{AgentDistDir}/manifest.json`, picks the entry for its platform, and calls
`loadAndVerify`, which decompresses, checks the SHA-256, and verifies an
Ed25519 signature against `updatePubKeyB64` — the key baked into the binary at
build time. Then `install()` swaps atomically, keeping `.bak`, and the watchdog
rolls back a version that never reaches a healthy start.

**What that means for the field evidence.** The dev plane agent's
0.5.0 → 1.2.0 self-update is sometimes cited as proof the agent can update
itself anywhere. It is not: the dev plane **is a Joinery site**, so
`AgentDistDir` resolved to its own `public_html/agent_dist` and a publish
delivered the artifact there through the ordinary upgrade. Nothing in that
story involves fetching anything. **There is no code path by which an agent
pulls an artifact from its management node.** `api.go` is the plane-side client
calling *a node's* management API — the opposite direction — and is not
reusable here.

**What is missing is only the fetch.** The verification, installation,
rollback, and job-lock interlock are all built and proven. So:

- Add `GET /api/v1/agent/artifact` to `AgentChannelEndpoint`, authenticated by
  the same Ed25519 signature as `claim` and `result` (`remote.go:368`), served
  from the plane's own `public_html/agent_dist`.
- Introduce an **artifact source** interface behind `Updater` with two
  implementations: the existing local directory, and the channel. A machine
  with a site tree keeps the local one — upgrades still deliver it, and a
  machine that can update without talking to anyone should.
- `loadAndVerify` changes only by taking an `io.Reader` instead of opening a
  file. **The signature check does not move, weaken, or gain a network
  dependency**: the key is still compiled in, so a hostile plane serving a
  hostile binary is still refused. This is a new delivery route for bytes that
  were always verified on arrival, not a new trust relationship.

The plane is already forbidden from being a trust root here, and that is what
makes serving the artifact safe. It is worth saying plainly in the spec,
because "the agent downloads its own binary from the control plane" reads
alarming until you notice the plane cannot sign one.

---

## 4. The unifying proposal: a signed bundle for machines with no site

**The problem in one sentence:** three separate pieces of this package need to
run a *shipped, verified script* on a machine that has no site tree to verify
it against.

| Need | Script today |
|---|---|
| Relay convergence (§5) | `provision_relay.sh` (1025 lines, v2.8) |
| Docker-host certificates (§7) | `setup_ssl.sh` → `provision_origin_cert` |
| Docker-host vhosts (§7) | `manage_domain.sh` |

**The proposal:** the same channel that serves the agent binary serves an
**agent support bundle** — a small signed tree, published alongside the agent
artifact, carrying exactly the scripts a siteless machine's primitives invoke,
plus its own `RELEASE_MANIFEST` and `.sig` signed by the existing release key.
The agent unpacks it to a root-owned directory (`/opt/joinery-agent/tree`), and
`ExecEnv` gains a `ToolRoot` that script primitives resolve against when
`SiteRoot` is empty.

Why this is the right shape rather than a convenience:

- **It keeps §3.2's promise honest on machines that currently cannot keep it.**
  A relay converged by an unverified script would be the manifest gate's
  largest hole; this closes it instead of routing around it.
- **It avoids a fourth certbot.** There are already three implementations
  (`provision_origin_cert`, `manage_domain.sh`'s `setup_ssl()`, and the inline
  `certbot --apache` steps in `build_provision_ssl`). A Go reimplementation on
  the host would be the only one outside the release manifest — the exact
  argument `operate_provision_certificate.go` already makes against composing a
  certbot argv in Go.
- **It reuses the publish pipeline.** `TreeManifestPublisher` already emits
  per-artifact signed manifests; the bundle is one more artifact.
- **It is one mechanism, not three.** The channel artifact endpoint of §3
  serves the bundle too.

**The honest cost:** the bundle is a second thing to publish and version, and a
machine running a bundle older than its plane expects must say so rather than
half-converge. The bundle therefore carries a version, the agent reports it on
claim beside `mgn_agent_version`, and `relay_converge` refuses when the bundle
does not carry the script version its parameters were built for.

**Rejected alternative — a narrow exec capability in `script.go`.** Allowing
compiled-in, parameterless commands (`systemctl reload postfix`) would keep the
gate technically intact and would not scale: the relay needs dozens of such
commands and the Docker host needs `apt-get`. It converts a clean boundary into
a growing allowlist.

---

## 5. `relay_converge` (question c)

### 5.1 What the five builders actually do

All five are in `JobCommandBuilder.php` and none has a `_primitive` sibling, so
relay work is SSH-only today.

| Builder | Line | Shape |
|---|---|---|
| `build_provision_relay` | 3487 | tar the provisioning dir on the plane → `scp` → untar → `sudo bash provision_relay.sh <fqdn> [smarthost]` → `add-tenant main` → read back `RELAY_WG_PUBKEY=` / `RELAY_PUBLIC_IP=` / `RELAY_VERSION=` |
| `build_rebuild_relay` | 3607 | close port 25, flush + stop Postfix, carry spool and queues aside, re-run all of provision, restore, `postsuper -r ALL`, reopen 25 |
| `build_relay_add_tenant` | 3683 | `provision_relay.sh add-tenant <slug> --pull-pubkey … --tunnel-ip … --domains …` |
| `build_relay_set_domains` | 3723 | `provision_relay.sh set-domains <slug> <csv\|*\|->` |
| `build_relay_remove_tenant` | 3744 | `provision_relay.sh remove-tenant <slug> [--force]` |

### 5.2 The desired state, which is smaller than the script

Per box: hostname, outbound mode (`smarthost` or not), and the main box's
WireGuard public key. Per tenant, keyed by slug:

```
{ slug, pull_pubkey, wg_pubkey, tunnel_ip,
  allowed_domains: [...],            # or ["*"], or [] meaning suspended
  limits: { forward_hourly_limit, spool_max_mib, spool_max_entries } }
```

Plane-side that is `mfs_mailbox_fleet_shards` (box) and
`mft_mailbox_fleet_slots` + `mfd_mailbox_fleet_domain_claims` (tenants);
`MailboxFleetSlot::verifiedDomains()` is already the exact projection that
becomes `allowed_domains`. **The relay holds no database and no plane
credential**, so it must be handed a fully rendered document — it cannot fetch
one. That is a property to keep, not a limitation to fix.

### 5.3 The design

`relay_converge` is a **script primitive** (§4 bundle), class `operate`, taking
the desired-state document **on stdin** via `StdinFrom` — the same treatment
`backup_run` gives its config, and for the same reason: the document carries
`pull_pubkey` and, if the fragment path is ever folded in, real secrets, and
argv is visible to every process on the box.

It invokes `provision_relay.sh converge`, a **new subcommand** that takes the
whole document and reconciles, replacing the imperative
`add-tenant`/`set-domains`/`remove-tenant` verbs with one reconciliation:

- tenants in the document but not on the box → create
- tenants on the box but not in the document → remove (**`--force` never
  implied**; a tenant with unsent mail in its spool fails the converge loudly
  rather than discarding mail)
- tenants in both → converge each field

The primitive declares `slug` nowhere as a parameter. The document is one
parameter, validated for shape on the node, and every slug in it is checked
against `^[a-z0-9][a-z0-9-]{0,27}$` **on the node** before it becomes a
username, a group, a path, or a line in `authorized_keys`.

### 5.4 Six non-idempotent things that must be fixed first

Found in `provision_relay.sh` 2.8. `relay_converge` is not safe to run on a
schedule until these are closed, and most are latent defects today.

1. **`ufw --force reset` wipes all firewall state on every run** (line 976) and
   would clobber the rebuild flow's own `ufw deny 25/tcp`. Must become a
   converge of the intended rule set.
2. **Unconditional `systemctl restart`** of postfix, opendkim, opendmarc,
   rspamd, `wg-quick@wg0` and joinery-direct. Every converge would drop mail
   service. Must become reload-if-changed, on the model `merge-maps` already
   follows.
3. **`go build` on the box** (line 425) — installs `golang-go`, fetches
   `golang.org/x/crypto` over the network, burns minutes of CPU, and changes
   the binary's mtime every run. **Ship `relay-sealer` prebuilt in the §4
   bundle.** This is the single biggest simplification available and it removes
   a compiler and a network fetch from a mail relay.
4. **`wg0.conf` accumulates dead `[Peer]` stanzas** when a tenant's key
   rotates: `add-tenant` appends and never removes the old block. This is the
   *same defect class* already fixed once for the main box's peer (the
   `AllowedIPs = 10.99.0.1/32` collision). Converge must replace the peer set.
5. **`/etc/default/opendkim` and `/etc/default/opendmarc` are appended to**
   when they carry no `SOCKET=` line.
6. **`dpkg-reconfigure unattended-upgrades` every run.**

### 5.5 What `relay_converge` deliberately does not absorb

- **The rebuild's carry-and-restore.** Copying `/var/spool/joinery-relay` and
  Postfix's `deferred`/`active`/`incoming` aside and back is stateful data
  movement that **cannot be re-derived from a desired-state document**. It stays
  its own operation. Converge is for state that can be declared; mail in flight
  cannot be.
- **The map fragment path.** `RelayMapSync` pushes the fragment tenant→shard
  over the restricted `jt-<slug>` forced-command account, and root-owned
  `merge-maps` validates it against the root-owned allowlist. That split **is
  the security model** — the allowlist is the claim boundary and the tenant
  cannot widen it. Converge writes the allowlist; the fragment keeps arriving
  the way it does.

### 5.6 A path that must not be forgotten

`RelayCloudProvisioner.php:378-420` performs the same tarball → `scp root@ip` →
`bash provision_relay.sh` sequence **over raw SSH as root, outside Server
Manager entirely**. Replacing only the five builders leaves this running. It
must be converted or retired in the same release, or the shared-key cutover
will believe the relay is done while a second root-SSH path is still live.

---

## 6. Enrollment without an admin panel (question b)

The join endpoint needs no changes. `callJoin` (`join.go:335`) already performs
the HTTP join; what is missing is a way to reach it without the settings table.

Add subcommands to a binary whose only flag today is `--version`
(`main.go:183`):

```
joinery-agent join --management-node=https://plane.example.com
joinery-agent status
joinery-agent leave
```

`join` generates the keypair if absent, calls the existing endpoint, and
**prints the fingerprint** — first 16 hex of SHA-256 over the raw public key,
the contract already pinned in `join_test.go` and `agent_channel_test.php`. The
human compares it against the pending request on the plane, exactly as A6
requires. Nothing is shared, and the CLI is a second front door to the same
ceremony, not a weaker one.

The **run switch** needs the same treatment: `agent_enabled` lives in a
settings table a siteless machine does not have. The marker file
`/etc/joinery-agent/enabled` already exists as the setting's projection and is
already what the cron keepalive reads. On a siteless machine **the marker
becomes the source of truth rather than a projection**, written by
`joinery-agent enable|disable`. One-way projection is preserved where a site
exists; nothing changes for the nine existing nodes.

---

## 7. Machine posture and Docker hosts (questions d, e)

### 7.1 Degradation — better than expected

`check_status` already degrades correctly: `runCheckStatus`
(`observe_check_status.go:44-63`) guards each collector on the field it needs
and errors only when *nothing* could be collected. On a siteless machine it
still returns memory, load, uptime and **certificates** — `collectCertificates`
reads `/etc/letsencrypt/live` directly and needs no site at all, which is
precisely what a Docker host has to report. No change needed.

`ssl_probe_place` / `ssl_probe_clear` already refuse legibly without a webroot
(`operate_ssl_probe.go:107-112`), and should: the probe belongs in the
container, and the fetch on the plane. Neither moves to the host.

So the `ExecEnv` change is small: `SiteRoot`/`WebRoot`/`DB` become legitimately
empty, and `ToolRoot` (§4) is added. The rule to write down is **collect what
exists, refuse what needs what is missing, and never guess a path** — which is
what the code already does; machine posture makes it a supported state rather
than an accident.

### 7.2 The Docker host needs an identity, and does not have one

`mgh_managed_hosts` carries `mgh_slug`, `mgh_name`, `mgh_host`,
`mgh_ssh_user`, `mgh_ssh_key_path`, `mgh_ssh_port`, `mgh_max_sites`,
`mgh_provisioning_enabled`, `mgh_notes` and timestamps — **SSH connection
details and nothing else**. No public key, no fingerprint, no pairing, no
version. And `mjb_management_jobs` carries `mjb_mgn_node_id` only, so **no job
row can name a host**. On-host steps are dispatched at a *node* and merely skip
the `docker exec` wrapper (`runner.go:238-245`).

This is the Step 3 blocker already recorded: the release that destroys the
shared key is the release after which Docker-node certificate provisioning has
**no executor at all**. Required:

- pairing columns on `mgh_managed_hosts`, mirroring the node set
  (`_agent_public_key`, `_paired_time`, `_last_poll`, `_agent_version`,
  `_channel_enabled`);
- a nullable `mjb_mgh_host_id` on the job table, with exactly one of node/host
  set, enforced;
- `AgentChannelEndpoint` and `JobResultProcessor` taught that a job's subject
  may be a host — both are node-keyed throughout today.

Note also that siblings on a host are found **two different ways that
disagree**: by the `mgn_host` string and by the `mgn_mgh_host_id` FK, with
`next_container_port()` hedging across both. A host identity that matters for
dispatch should settle that, or a host will be paired under one identity and
addressed under the other.

### 7.3 Host certificate work

The one genuinely new capability beyond running the existing scripts is the
**`X-Forwarded-Proto` patch**, which `sed`s
`/etc/apache2/sites-enabled/{SITE}-proxy-le-ssl.conf` and reloads Apache. The
site name is not derivable by the host — it is not the host's site — so this
primitive **must take a parameter**, unlike its node-side relatives.

The safe shape is the one `delete_backup` already uses: **a name, never a
path.** The plane sends the node's slug, validated on the host against
`^[A-Za-z0-9_-]+$`, and the host composes the vhost path itself from a
compiled-in pattern. The plane cannot express a path, cannot escape the
directory, and cannot name a file outside the pattern.

---

## 8. Sequencing (question f)

Every agent version must stay above the 1.1.0 legacy floor, and a publish
updates the whole fleet — so each release must be safe for the nine existing
site-having nodes, none of which needs any of this.

**R1 — agent 1.11.0. Siteless startup, no new vocabulary.**
Config becomes optional (B1/B2): no site config is a *posture*, not an error.
CLI `join`/`status`/`enable`/`disable` (§6). `install_agent.sh` gains a siteless
install path. Nothing about the nine nodes changes, which is what makes this
release cheap to verify: a site-having node must behave identically.

**A siteless machine has no PHP, and the installer currently needs some.**
`install_agent.sh` guards on `command -v php` and exits 0 without it, and
`converge_binary` parses `manifest.json` with `php -r`. A relay has neither:
`provision_relay.sh` installs postfix, opendkim, opendmarc, wireguard, ufw,
rspamd and golang-go, and PHP is not among them. Left alone, a siteless install
would exit 0 having done nothing, reporting success — a check that passes by
not running, which is the failure shape this migration keeps meeting.

The installer therefore needs a PHP-free path to three fields (version, file,
sha256) for one architecture. An `awk` reader over the machine-generated
manifest supplies them, verified against the real artifact for both
architectures and returning nothing — rather than the wrong block — for an
architecture the manifest does not carry. **The integrity check survives on a
machine with no PHP**, which matters more than the parser: dropping the sha256
because the convenient interpreter is absent would be trading a real guarantee
for an implementation detail.

Three installer decisions, approved 2026-08-28:

- `--dist-dir=DIR`. A siteless machine has no `public_html/agent_dist` for a
  release to have delivered, so the artifact location is an argument. One
  installer, not two. The trust model is unchanged and already stated in the
  script's own header: on a first install the operator is the delivery, and
  signature enforcement lives in the agent's baked-in key from its first
  self-update onward. `--dist-dir` remains useful for first installs after R2.
- `--siteless` is EXPLICIT, never inferred. A missing site config keeps meaning
  "not my machine, exit 0" — the two DNS resolvers depend on that, and so does
  a node whose config is briefly absent mid-upgrade. Inferring "this must be a
  relay" from a momentary absence would install a root service on a machine
  that never asked.
- The run marker is WRITTEN explicitly, off unless `--enable`. `markerSaysRun()`
  treats a missing marker as ON, which is correct for its one situation — an
  upgrade over an agent that predates the projection — and cannot arise on a
  fresh siteless machine. Inheriting it there would borrow a default chosen for
  a different problem and start a root service against A9.

**R2 — agent 1.12.0 + plane. The artifact channel (§3, §4).**
Signed artifact endpoint, the update source abstraction, the support bundle and
`ToolRoot`. Still no new primitives. At this point a siteless machine can be
installed, enrolled, kept current, and can verify a script — and script
primitives become available to it.

**R3 — plane schema + Docker host.** Host pairing columns, host-addressable
jobs, the proto-patch primitive with its slug parameter, host-scoped
`provision_certificate`. This is where `provision_ssl` stops being a Step 3
blocker.

**R4 — `relay_converge`.** Ordered last because §5.4's six idempotency fixes
land in `provision_relay.sh` first and want proving on a rebuilt relay before
anything converges on a schedule. Retire the five builders **and**
`RelayCloudProvisioner`'s raw-SSH path in the same release (§5.6).

**R5 — cutover.** Unchanged in shape from `agent_on_node_architecture.md` §6
Step 3, but see §9.

---

## 9. Closed questions, and what is still open

### 9.1 A8, superseded

A8 said a machine with no Joinery site "has nothing for it to do", that
`install_agent.sh` exiting without a site config was "already the right
condition", and that joinery-relay-1 was excluded as disposable. **A13
supersedes it**: the rule is now "machines the plane manages run the agent;
machines it doesn't, don't". The relay and the Docker hosts cross, because
their management surface turned out to be real — automated tenant
provisioning, and certificates for every container on a host.

### 9.2 The cutover gate opens fully — measured, not assumed

The first draft of this document flagged the two ScrollDaddy DNS resolvers as a
possible remaining exemption, on the grounds that nobody should assume whether
they hold the shared provisioning key.

**They do not.** Both resolvers were tested directly on 2026-08-28 with a
`BatchMode` SSH attempt using the provisioning key: permission denied on both.
They never held it. Nothing manages them from the plane, so there is no key on
them to destroy and no separate resolver answer is owed.

So with this package there are **no exemptions left**. Every machine carrying
the shared provisioning key either crosses to the agent channel or loses the
key, which is what makes destroying it the single event A8 always described.

### 9.3 The host agent is not a transport — decided and closed

A host agent doing `docker exec` on behalf of container nodes that cannot run
their own agent was considered and **rejected** (2026-08-28). Container nodes
keep their own agents and their own pairings; the host agent does host-scoped
work only — certificates, host status, host vhosts.

The reason is the one this migration exists for. A machine that can act on
other machines' behalf is exactly the trust shape being removed: it would
re-create, at host scope, the thing the shared provisioning key is being
destroyed for. A host that can `docker exec` into every container on it is a
smaller blast radius than a plane that can SSH to every node, but it is the
same shape, and the migration's own rule (§3.7) is that a mechanism must
eliminate a row or shrink a cell, never shuffle trust between rows.

### 9.4 Still open

- **Which plane converges the relay.** A relay's registration lives in the
  **served deployment's** `mrl_mailbox_relays`, not the control plane's, so the
  box holding the desired state and the box holding the Server Manager job
  queue are not necessarily the same machine. This goes to the owner, and it
  needs facts first: **R4 design must trace the live topology** — where the
  `mrl_` rows for the real relay actually live, which instance queues relay
  jobs today (`FleetService` versus `relay_admin`), and where the tenant
  desired-state document would be composed. Not to be settled by argument.
- **Timeouts.** Host install work runs 1800–3600s, well past `DefaultTimeout`,
  and a converge that installs packages is in the same territory. Each needs a
  declared `Timeout` and a matching `PRIMITIVE_CLAIM_BUDGETS` entry, which
  `primitive_transport_parity_test` will enforce.
