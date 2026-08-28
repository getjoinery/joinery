# Machine posture, relay convergence, and Docker hosts (WP4)

**Status: MIGRATION AT A RESTING MILESTONE (2026-08-28). R1 BUILT AND LIVE
(agent 1.10.0, fleet-wide). Vocabulary-on-claim BUILT AND LIVE (agent 1.11.0).
R2 support bundle + artifact channel BUILT AND GREEN but SHELVED DORMANT — no
siteless consumer on the chosen rollout path; its publish wiring is inert; it
has never run against a real plane. R3–R5 DEFERRED.**

> **Read this before the body — three owner decisions of 2026-08-28 supersede
> sections written earlier, which are NOT yet rewritten below:**
> - **The relay is not managed** (health check + full reprovision only, no
>   agent). §5's `relay_converge` is **never built**; the five relay SSH
>   builders die as dead code at the cutover with no replacement; the `bin/`
>   bundle convention for relay content is **dead** (the sealer ships prebuilt
>   in the mailbox plugin, not the bundle). §5 stands only as a record.
> - **The Docker host is a plain `ManagedNode` in machine posture** (§7.2's
>   host-pairing schema and exactly-one-of enforcement are not built and not
>   needed). R3 (Docker-host certificates) is the bundle's only consumer, and
>   is **deferred with getjoinery**.
> - **Both hardening targets deferred.** getjoinery moves to its own box (a
>   full-site node, no bundle) before being hardened; jeremytunnell is deferred
>   for bug-fixing, on a launch-ready to-do (needs a restore-over-agent path
>   first). The Step 3 cutover / key work is per-node launch-readiness, not
>   near-term; c7's `specs/r5_cutover_inventory.md` is its map and found there
>   are **two** keys, of which the fleet key (`id_ed25519_claude`) is the one
>   that matters and is a Claude-agent key, not the owner's personal key.

The architecture spec (`specs/implemented/agent_on_node_architecture.md`, whose
A13 records the siteless decision) is implemented and frozen. This spec owns
the remainder of the transport migration; the sections below predate the
decisions boxed above; §§4, 5, 7.2 and 8 have been corrected to match them.

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
rollback, and job-lock interlock are all built and proven. So (**BUILT in R2**):

- Add `/api/v1/agent/artifact` to `AgentChannelEndpoint`, authenticated by
  the same Ed25519 signature as `claim` and `result`, served from the plane's
  own `public_html/agent_dist`. **POST, not GET**: `dispatchPreAuth` is
  POST-only and the signature covers the method, so a GET would have been a
  second auth shape for one endpoint. The request names a `kind` from a closed
  set (`agent_manifest`, `agent_binary`, `bundle_manifest`, `bundle_body`) and,
  for a binary, an architecture matched against a pattern — never a file name.
  Body responses are streamed in chunks and are the one response on the channel
  that is not a JSON envelope; the agent reads them with a separate capped
  reader, leaving the 64 KiB envelope cap exactly as it was. Body fetches carry
  their own rate bucket and each is recorded (§3.5.6).
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

**BUILT AND GREEN, SHELVED DORMANT (2026-08-28).** Everything below is
implemented and tested; none of it runs. Two of the three needs that motivated
it have since gone away — the relay is not a managed machine (§5), so it never
runs a primitive at all, and the Docker-host work is deferred (§7.2). The
argument stands and the mechanism is banked; read this section as a design that
is ready rather than one that is in service.

**The problem in one sentence:** a machine that has no site tree has nothing to
verify a shipped script against, and so can run no script primitive at all.

| Need | Script today | Status |
|---|---|---|
| Docker-host certificates (§7) | `setup_ssl.sh` → `provision_origin_cert` | deferred with R3 — the bundle's only consumer |
| Docker-host vhosts (§7) | `manage_domain.sh` | deferred with R3 |
| ~~Relay convergence (§5)~~ | ~~`provision_relay.sh`~~ | not a bundle consumer — the relay runs no agent |

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
half-do the work. The bundle therefore carries a version and the agent reports
it on claim beside `mgn_agent_version`, so a primitive whose parameters were
built for a newer script can refuse rather than guess. That reporting is built
and live (`mgn_agent_bundle_version`); every node in the fleet reports it
empty, because every node has a site.

**BUILT in R2**, with three details settled in the building:

- **The version is a content hash** — the sha256 of the bundle's own manifest
  body, truncated — rather than the release number. A publish that changes no
  bundled script therefore leaves the bundle byte-identical and no machine
  fetches anything, the same property `AgentDistPublisher` already has for the
  agent artifact. It also makes "has the content changed" answerable with
  nothing to keep in step.
- **The trust root is inside the bundle.** The plane advertises the tarball's
  sha256 alongside it, used for exactly one thing: skipping a transfer a
  machine already has. What makes the tree runnable is the signature inside it,
  verified against the compiled-in key, plus a hash of every listed file **and**
  a walk proving the tree holds nothing the manifest does not list — a tarball
  can add a file as easily as change one.
- **Contents are a deliberate list, not a sweep** (`SupportBundlePublisher`).
  R2 ships `setup_ssl.sh` and the `install.sh` it sources. Binaries the scripts
  invoke ship under `bin/` with one file per architecture
  (`bin/relay-sealer-linux-amd64`, `bin/relay-sealer-linux-arm64`) and the
  script selecting on `uname -m`: **one bundle carrying both architectures**,
  not one bundle per architecture. Per-arch bundles would double the publish
  surface and create a way for a machine to hold one built for the wrong
  architecture, to save a few megabytes of dead weight on a mail relay.

**Rejected alternative — a narrow exec capability in `script.go`.** Allowing
compiled-in, parameterless commands (`systemctl reload postfix`) would keep the
gate technically intact and would not scale: the relay needs dozens of such
commands and the Docker host needs `apt-get`. It converts a clean boundary into
a growing allowlist.

---

## 5. `relay_converge` (question c) — RECORD ONLY, NOT BUILT

**The relay is not a managed machine (owner, 2026-08-28).** Its entire
management surface is a plane-side port probe plus complete reprovisioning: it
runs no agent, holds no pairing, and invokes no primitive. So `relay_converge`
is **never built**, and the five SSH builders below are neither retired nor
replaced — they remain the relay's only management path, and they die as dead
code at the cutover with nothing taking their place.

What survives this section is not its design. It is three findings: §5.1's
inventory of what the builders actually do, §5.4's six idempotency defects
(closed in `provision_relay.sh` 2.9 regardless of whether anything ever
converges), and §5.6's traced finding that `RelayCloudProvisioner` never held
the shared provisioning key. Read the rest as the reasoning that produced
those, not as work owed.

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

Found in `provision_relay.sh` 2.8. **All six are closed in 2.9** (gate-pinned,
56 checks), and they were worth closing on their own account: a rebuild re-runs
provisioning end to end, so every non-idempotent step below is a defect in the
path the relay actually has, not only in a converge it will never get.

2.9 consumes a **prebuilt** `relay-sealer` rather than compiling one on the
box. That binary ships in the mailbox plugin's own tree
(`provisioning/bin/relay-sealer-<arch>`, built at publish time by
`RelaySealerPublisher` and covered by the plugin's signed manifest) and reaches
the relay inside the provisioning tarball the builders already send. **The
`bin/` support-bundle convention for relay content is dead** — the bundle
carries no relay content, because a machine that runs no agent cannot be handed
one. There is no delivery window to close and nothing here waits on R4.

1. **`ufw --force reset` wipes all firewall state on every run** (line 976) and
   would clobber the rebuild flow's own `ufw deny 25/tcp`. Must become a
   converge of the intended rule set.
2. **Unconditional `systemctl restart`** of postfix, opendkim, opendmarc,
   rspamd, `wg-quick@wg0` and joinery-direct. Every converge would drop mail
   service. Must become reload-if-changed, on the model `merge-maps` already
   follows.
3. **`go build` on the box** (line 425) — installs `golang-go`, fetches
   `golang.org/x/crypto` over the network, burns minutes of CPU, and changes
   the binary's mtime every run. **Ship `relay-sealer` prebuilt**, in the
   mailbox plugin's provisioning tree. This is the single biggest
   simplification available and it removes a compiler and a network fetch from
   a mail relay.
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

### 5.6 A path that must not be forgotten — corrected

`RelayCloudProvisioner.php:378-420` performs the same tarball → `scp root@ip` →
`bash provision_relay.sh` sequence **over raw SSH as root, outside Server
Manager entirely**.

**It does not hold the shared provisioning key** (traced 2026-08-28,
superseding this section's first reading). `handleReady` generates a per-run
Ed25519 keypair, injects the public half at instance creation, seals the private
half on the run row and erases it at every terminal state. That is the
architecture's own bootstrap-at-birth pattern — one SSH act, key destroyed
afterwards — so it **does not block the shared-key destruction** and does not
have to be retired for the cutover to complete.

A customer-cloud relay comes out of this path with **no `ManagedNode` row, no
pairing and no agent** — which, now that the relay is not a managed machine, is
the correct end state rather than a gap. The path neither grows nor dies: it
stays a bootstrap-at-birth SSH act with a per-run key destroyed afterwards.
Three things here have no primitive equivalent and are not candidates for one
in any case: instance lifecycle on the customer's cloud account, the
drain-gated upgrade path, and main-box-side WireGuard peering, which runs on
the main box rather than on the relay.

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

### 7.2 The Docker host is a node, not a new kind of thing — DEFERRED

`mgh_managed_hosts` carries `mgh_slug`, `mgh_name`, `mgh_host`,
`mgh_ssh_user`, `mgh_ssh_key_path`, `mgh_ssh_port`, `mgh_max_sites`,
`mgh_provisioning_enabled`, `mgh_notes` and timestamps — **SSH connection
details and nothing else**. No public key, no fingerprint, no pairing, no
version. And `mjb_management_jobs` carries `mjb_mgn_node_id` only, so **no job
row can name a host**. On-host steps are dispatched at a *node* and merely skip
the `docker exec` wrapper (`runner.go:238-245`).

An earlier draft answered that by teaching the whole dispatch path a second
kind of subject: pairing columns on `mgh_managed_hosts`, a nullable
`mjb_mgh_host_id` with exactly-one-of enforcement, and both
`AgentChannelEndpoint` and `JobResultProcessor` taught that a job's subject may
be a host. **That is not the design (owner, 2026-08-28).** A Docker host is a
machine the plane manages, and this migration already has a word for that: it
becomes **a plain `ManagedNode` in machine posture**, paired like any other,
addressed like any other, running host-scoped vocabulary only. It needs no
schema of its own, and `mgh_managed_hosts` keeps being what it is — the
placement record for containers, not an identity.

**R3 is deferred** along with the hardening it served (see the header box):
getjoinery moves to its own full-site box rather than being hardened in place,
so nothing needs host-scoped certificates in the near term. The support bundle
is R3's only consumer, and is shelved with it.

One finding here outlives the design and is worth keeping: siblings on a host
are found **two different ways that disagree** — by the `mgn_host` string and
by the `mgn_mgh_host_id` FK, with `next_container_port()` hedging across both.
Whenever the host does become a node, that must be settled first, or the host
is paired under one identity and addressed under the other.

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

**R1 — agent 1.10.0. Siteless startup, no new vocabulary. SHIPPED
2026-08-28, fleet-wide.**
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

**R2 — agent 1.11.0 + plane. Vocabulary on claim SHIPPING; the artifact
channel (§3, §4) BUILT AND GREEN but SHELVED DORMANT.**
The signed artifact endpoint, the update-source abstraction, the support bundle
and `ToolRoot` are all built and covered, and none of them has a consumer: the
relay runs no agent and R3's Docker host is deferred, so there is no siteless
machine for them to serve. They are banked rather than discarded — the publish
wiring is inert behind `SupportBundlePublisher::hasConsumer()`, the artifact
endpoint answers a fetch no agent makes, and `ToolRoot` stays empty on every
node in the fleet. Reactivating is flipping that one method, and the tests
exercise the builder directly so the mechanism cannot rot unnoticed.

One piece of R2 stands on its own and stays live regardless: `update.go` now
reads its artifact through an `io.Reader` with a ceiling on the decompressed
size, which closes a gzip-bomb path in the ordinary node update flow.

R2 also carries **vocabulary on claim**, which is not a siteless concern at all:
the agent reports `primitives.Names()` on every poll, the plane stores it
(`mgn_agent_primitives`) and `has_primitive()` consults it, falling back to
`PRIMITIVE_MIN_AGENT_VERSION` for agents that predate the report. The map is
kept — it is the contract for every agent at 1.10.0 or earlier, and there are
nine of them. It lands here because the plane must never guess a node's
vocabulary, and the first `apply_update` rollout did exactly that: nine agents,
nine refusals.

**One compatibility hazard, closed in the agent.** The plane refuses an
undeclared field on a claim rather than ignoring it, which is the right rule and
makes a newer agent's extra fields fatal against an older plane. In this fleet
the plane upgrades first — the agent artifact ships inside the core release —
but "first in practice" is not an ordering guarantee, and a node whose site
upgraded ahead of its management node would stop claiming altogether. The agent
therefore latches the extras off on that specific refusal and keeps claiming in
the older shape. Losing the capability report costs the plane a fact; losing the
claim costs it the node.

**R3 — the Docker host as a node. DEFERRED (§7.2).** No schema: the host
enrolls as a `ManagedNode` in machine posture and gets host-scoped vocabulary —
the proto-patch primitive with its slug parameter, and host-scoped
`provision_certificate` resolved from the support bundle. This is the only
consumer the bundle has, and it is deferred with the hardening it served, so it
is also what would un-shelve R2. Until then `provision_ssl` on a Docker host
remains without an executor.

**R4 — nothing.** `relay_converge` is not built (§5). The five relay builders
and `RelayCloudProvisioner`'s bootstrap SSH path are not retired and get no
replacement; they end when the relay stops being provisioned this way.

**R5 — cutover.** Unchanged in shape from `agent_on_node_architecture.md` §6
Step 3, but see §9 — and it is now per-node launch-readiness work rather than a
single near-term event, since both hardening targets are deferred.

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
- **R2 is proven by build and unit coverage only.** The fetch path has never run
  against a real plane: there is no siteless machine in the fleet, so the
  artifact endpoint and the bundle fire on **zero** machines today. The live
  proof — install a siteless agent on the scratch box, enroll it, watch it fetch
  and verify a bundle — is an owner-gated step and is owed before R4 depends on
  the mechanism.
- **The general agent-channel rate bucket is vacuous.** `api_agent` is checked
  in `apiv1.php` but nothing writes an `api_agent` row, so the counter is always
  zero and the limit never fires. R2's artifact bucket writes its own rows and
  is therefore real; the general one is a pre-existing gap, noticed here and not
  fixed here.
- **`update_database` on the live plane** for the two new `mgn_` columns. Run
  on dev 2026-08-28 (it added exactly those two); the control plane gets them
  at its own upgrade, and until it does, a node's reported vocabulary has
  nowhere to land and `has_primitive()` falls back to the version floor — which
  is the designed behaviour, not a fault.
