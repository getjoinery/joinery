# R5 — the cutover release inventory

**Status: DEFERRED (owner, 2026-08-28).** Both hardening targets were deferred,
so the cutover and the key removal below are launch-readiness work rather than
near-term work. This document is the inventory the release will be written from
when hardening is scheduled; it records what the tree, the dev plane's database
and the agent source said on 2026-08-28. Re-verify §0.1's key configuration and
§5.3's pairing counts before acting on it — both are live state, and both will
have moved.

Investigation only; no code was changed and no machine was contacted. Everything
below is read out of the tree, the dev plane's database, or the agent source at
`/home/user1/joinery-agent`. Line numbers are `plugins/server_manager/includes/JobCommandBuilder.php`
unless another file is named.

---

## 0. Three findings that change the shape of the release

Read these before the inventory; two of them move the ceremony and one of them
contradicts a closed question.

### 0.1 There is no "the" shared provisioning key. There are two, and the
### ceremony as written destroys the one almost nothing uses.

Two distinct keypairs are configured on this plane:

| Fingerprint | Comment | Where the private half is | Used by |
|---|---|---|---|
| `SHA256:l1vBNGzTDcEoVhk1t+7ueOXVYlbYAnetdOJlt5agrGs` | `claude@joinerytest` | `/home/user1/.ssh/id_ed25519_claude` (0600 user1:user1) | **11 of 12 live nodes** and **all 3 managed hosts** |
| `SHA256:EK2tnR6R444KFX5zK9BCGuH340Jvt57X4dWK5CMSkB4` | `joinery-provisioning` | `{site}/config/provisioning_key` (0640 www-data:user1) | **1 node** — `jeremytunnell-vps` |

`config/provisioning_key` is the key the specs, `ProvisioningSetup.php:337` and
the key-management spec all call "the provisioning key". It reaches exactly one
node. The credential that actually reaches the fleet is a key in the operator's
home directory that no spec mentions.

Two consequences:

- **A destruction ceremony aimed at `config/provisioning_key` closes nothing.**
  It would leave a working plane-readable credential for 11 nodes and 3 hosts,
  and the completion test ("every machine that carries the shared key crosses or
  loses it") would pass while being false.
- **The mode matters and cuts the other way.** `id_ed25519_claude` is 0600
  `user1:user1`, so **www-data cannot read it**. The web plane cannot use it.
  The plane's own agent runs as root under systemd and can. So the skeleton key
  on this box is held by the local agent, not by PHP — which is worth stating
  plainly in R5, because it changes what "compromise the plane, own the fleet"
  means here.

**Owner question this raises, and it is not mine to answer:** the name and
location say `id_ed25519_claude` may also be a key a human logs in with. A11
says personal SSH access is untouched. If that key is both, then the R5 act is
not "destroy the key" but "stop the plane from being able to read it" —
different ceremony, different proof. Someone has to say which it is.

### 0.2 §9.2's "no exemptions left" was measured with the other key

The spec records that both ScrollDaddy resolvers were tested on 2026-08-28 with
a `BatchMode` SSH attempt **using the provisioning key**, got permission denied,
and were therefore concluded never to have held it.

The database says both resolvers are configured with `mgn_ssh_key_path =
/home/user1/.ssh/id_ed25519_claude` — the *other* key. So the test proved the
resolvers do not accept the key that reaches one node, and said nothing about
the key that reaches eleven. This is the failure shape the migration keeps
meeting: a check that passes by not testing the thing.

I did not re-run it — no live-node access this round. **R5 cannot close §9.2
until the same BatchMode probe is repeated with `id_ed25519_claude` against
`45.56.103.84` and `97.107.131.227`.** If it succeeds, the resolvers are not
outside the migration, and "no exemptions" is false today.

### 0.3 Deleting `'type' => 'ssh'` does not delete SSH dispatch

Three SSH surfaces would survive a sweep that removed every `ssh` and `scp`
step:

1. **`build_discover_nodes` (1817) composes SSH inside `local` steps.** All five
   of its steps are `'type' => 'local'` whose `cmd` begins with
   `self::ssh_prefix(...)` — a full `ssh -i <key> user@host '…'` command line
   executed on the plane. It is SSH dispatch wearing a local step's clothes, and
   it takes `ssh_key_path` straight from operator input.
2. **Three PHP classes shell out to `ssh` directly, outside the job system
   entirely**, each via `proc_open`:
   - `plugins/mailbox/includes/FleetProvisionSeeding.php:165` `runSsh()`
   - `plugins/server_manager/includes/provisioning/ManagedDomainWatch.php:480` `runSsh()`
   - `plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php:657` `run_on_node()`
   All three read `mgn_ssh_key_path` off the node row. No job, no builder, no
   step type — nothing in the builder inventory covers them.
3. **`build_install_node` (2989) survives by design** (§5 birth) with 28 `ssh`
   and 4 `scp` steps, which means the agent's SSH executors cannot be deleted.
   See §3.

---

## 1. `JobCommandBuilder` — what dies, what survives

### 1.1 Dies: SSH-only builders (no primitive sibling)

| Builder | Line | Steps | Note |
|---|---|---|---|
| `build_backup_database` | 751 | 3 ssh | A12 retired it already; the builder is what remains |
| `build_backup_project` | 806 | 3 ssh | A12, same |
| `build_restore_database` | 1006 | 4 ssh | |
| `build_restore_project` | 1091 | 5 ssh | |
| `build_restore_chain` | 1364 | 7 ssh | |
| `build_provision_ssl` | 2760 | 7 ssh + 1 local | spec line 139: bare metal uses the primitive chain; containers cross with the host agent |
| `build_decommission_node` | 2445 | 3 ssh + 1 scp | **no primitive sibling exists** — see §1.6 |
| `build_discover_nodes` | 1817 | 5 local-wrapping-ssh | see §0.3 |
| `build_check_status_ssh` | 668 | 11 ssh | fallback leg of the 621 dispatcher |
| `build_list_backups_ssh` | 2397 | 1 ssh | fallback leg of the 2361 dispatcher |
| `build_provision_relay` | 3541 | 3 ssh + 1 scp + 1 local | see §1.5 |
| `build_rebuild_relay` | 3661 | 3 ssh (+ everything provision emits) | see §1.5 |
| `build_relay_add_tenant` | 3737 | 1 ssh | see §1.5 |
| `build_relay_set_domains` | 3777 | 1 ssh | see §1.5 |
| `build_relay_remove_tenant` | 3798 | 1 ssh | see §1.5 |

### 1.2 Dies: the SSH fallback branch inside a primitive-routing builder

These builders survive; the `if (has_primitive) … else <ssh>` tail comes out and
the `else` becomes a refusal.

| Builder | Line | The branch |
|---|---|---|
| `build_check_status` | 621 | third leg: `if (self::has_ssh($node)) return build_check_status_ssh(...)` |
| `build_list_backups` | 2361 | third leg, same shape |
| `build_backup_run` | 895 | 1 ssh step after the primitive check |
| `build_apply_update` | 1524 | 1 ssh step |
| `build_run_plugin_installers` | 1573 | 1 ssh step, guarded by `has_ssh` at 1577 |
| `build_upload_backup` | 2174 | falls through to `self::upload_step(...)` |
| `build_delete_backup` | 2521 | 3 ssh steps after the primitive check |

Note the two dispatchers (`check_status`, `list_backups`) also have an **API**
leg. R5 removes the SSH leg; whether the API leg goes with it is a separate
decision the spec has never made. I would name it in R5 rather than leave it
implied — an API transport that outlives the cutover is a second plane-held
credential (`mgn_api_secret_key`), even if a much narrower one.

### 1.3 Dies: helpers with no surviving caller

- **`ssh_prefix` (1804)** — sole caller is `build_discover_nodes`. Dies with it.
- **`step_snapshot_before` (1897), `step_clean_before` (1905),
  `step_mint_envelope` (1957), `step_finalize_envelope` (2023),
  `append_upload_steps` (2140), `upload_step` (2253)** — called only by
  `build_backup_database` / `build_backup_project` / `build_upload_backup`'s SSH
  tail. The whole plane-side envelope-minting scratch pipeline goes.
- **`new_scratch_id` (1872), `envelope_key_path` (1876),
  `envelope_sidecar_path` (1880), `before_list_path` (1884),
  `resolve_new_archive` (1918), `resolve_new_sidecar` (1923),
  `new_file_pipeline` (1927)** — reachable only from the above.
- **`step_resolve_restore_key` (2064)** — only `build_restore_database`.
- **`decommission_verify_cmd` (2501)** — only `build_decommission_node`.
- **`steps_publish_container_domain` (1242), `step_verify_identity` (1284),
  `step_verify_served` (1319), `restore_domain` (1216)** — only the restore
  builders.
- **`build_node_uploader_script` (2306), `creds_token` (2341),
  `strip_php_tags` (2350)** — callers are `build_delete_backup`,
  `build_restore_*` and `upload_step`, all dying. **Verify before deleting**:
  `plugins/server_manager/includes/node_uploader.php` is a shipped file and may
  have a caller outside this class.
- **`backup_glob` (2045)** — internal callers all die, but it is `public
  static`; sweep for external callers first.

### 1.4 Survives, and why

- **`build_install_node` (2989)** — §5 birth, "stays SSH, provisioning-time
  only" (architecture spec line 145). 28 ssh + 4 scp + 4 local. It is the reason
  most of the shared helpers survive, and the reason the agent keeps its SSH
  executors (§3).
- **`get_config_path` (571), `get_scripts_path` (599), `sudo_prefix` (609),
  `get_db_credentials_script` (585)** — each is called by
  `build_install_node`, so all four survive. This contradicts the natural
  assumption that `get_db_credentials_script` is an SSH-era artifact: it is, but
  birth provisioning still needs it. Its ten *other* callers all die.
- **`get_target` (2084)** — also called by `backup_run_config` and
  `build_upload_backup_primitive`.
- **`assert_node_can_be_backed_up` (2001)** — also `backup_run_config`.
- **`_update_node_ssh_user_cmd` (2610)** — only `build_install_node`.
- **`build_publish_upgrade` (1783)** — plane-local, one `local` step, no SSH.
- **Every `*_primitive` builder**, `mint_ssl_probe_token`, the API probes
  (`probe_api_health`, `fetch_status_via_api`, `probe_https`), and the routing
  predicates other than `has_ssh`.

### 1.5 The five relay builders — the replacement named in the spec no longer exists

Architecture spec line 144 says these five "die at the Step 3 cutover", replaced
by `relay_converge`. The owner has since descoped the relay from management
entirely: no agent, `relay_converge` never built. So **they die with no
replacement on this plane**, and relay work lives entirely in
`RelayCloudProvisioner`'s ephemeral-key path, which is out of scope for deletion.

Worth recording that killing them costs nothing measurable here: on this plane
the only shard row (86) and the only `mrl_` row (1) are both soft-deleted, the
last relay job ran 2026-07-19, `mailbox_hosted_relay_offered()` returns `false`
unconditionally so `FleetService`'s dispatch path is dormant, and node 1800's
own `mgn_notes` say "Managed on the served deployment; this dev record is
monitoring-only". Every job dispatched at it since July has failed.

R5 should state the replacement explicitly rather than inherit line 144's
wording, which now points at something that will not be built.

### 1.6 Two operations that lose their only transport

Not blockers, but they must be a decision rather than a side effect:

- **`decommission_node`** has no primitive. After the cutover the plane cannot
  decommission a node at all.
- **`restore_database` / `restore_project` / `restore_chain`** have no primitive
  siblings. Restores are human-present by design (A3's note), but "human
  present" currently still means a plane-dispatched SSH job. After the cutover
  there is no plane-side restore path.

`enable_agent` is the third of these and is handled at §1.7.

### 1.7 `build_enable_agent` (1619)

2 ssh steps, guarded by `has_ssh` at 1620. O-5 records this as resolved-and-moot:
sshd stays on across the current fleet (A11), so the transport is not deleted out
from under the rollout, and **the surviving ordering rule is that every node
enables and pairs before the key is destroyed**. On this plane that rule is
already 9 of 12 satisfied — see §5.3. The builder dies with the rest; it has no
work left once every in-scope node is paired.

---

## 2. Every other reader of `mgn_ssh_key_path` / `mgh_ssh_key_path`

Classified `DIES` / `BIRTH` (survives for provisioning-time use) / `OUT OF SCOPE`.

| File | What it does with the key path | Class |
|---|---|---|
| `data/managed_node_class.php` | declares `mgn_ssh_key_path` | **BIRTH** — the column stays while `install_node` does; empty it per node as each is cut over |
| `data/managed_host_class.php` | declares `mgh_ssh_key_path` | **BIRTH** — same, plus §7.2's host pairing columns land beside it |
| `includes/JobCommandBuilder.php` | §1 above | mixed |
| `includes/JobResultProcessor.php:1153` | relay registration fallback: `mrl_ssh_key_path` = the relay pull key if present, **else the node's `mgn_ssh_key_path`** | **DIES.** The fallback writes the plane's fleet key into a relay row as that relay's credential. It is reached only when `RelaySsh::pullKeyPath()` is missing. Delete the fallback, not just the branch — a missing pull key should refuse, not substitute the fleet key. |
| `includes/JobResultProcessor.php:1473` | carries `ssh_key_path` out of `discover_nodes` job params into a node row | **DIES** with `build_discover_nodes` |
| `includes/provisioning/ProvisionManagedDomains.php:657` | direct `proc_open` ssh | **DIES** — needs a primitive or it loses its transport (see §2.1) |
| `includes/provisioning/ManagedDomainWatch.php:480` | direct `proc_open` ssh | **DIES** — same |
| `plugins/mailbox/includes/FleetProvisionSeeding.php:165` | direct `proc_open` ssh | **DIES** — dormant already (fleet offering unlaunched), so this is the cheapest of the three to kill |
| `includes/provisioning/ProvisionCustomerCloud.php:69,198` | reads setting `server_manager_customer_cloud_ssh_key_path`, injects its `.pub` at instance create, writes the path onto the new node row | **BIRTH** — this is §5 birth for customer cloud. It is also a **third key**, distinct from both in §0.1. R5 must say whether birth keys are one key or three. |
| `includes/provisioning/PollHostingOrders.php:189,202` | copies `mgh_ssh_key_path` onto a new node row | **BIRTH** — feeds `install_node` |
| `logic/add_discovered_nodes_logic.php:26,62` | takes `ssh_key_path` from operator input, writes it to node rows | **DIES** with discovery |
| `logic/node_detail_actions_logic.php:671` | `mgn_ssh_key_path` in the editable-field list | **BIRTH** — keep editable while birth needs it; consider making it write-once |
| `includes/node_detail_tabs/api_keys.php`, `overview.php:121,164` | display, and `has_ssh()` gates on two UI affordances | **DIES** — the tabs stop offering SSH-only actions |
| `views/admin/node_add.php`, `install_node_form.php`, `host_add.php` | operator enters a key path at node/host creation | **BIRTH** |
| `migrations/migrations.php` | created the columns | **OUT OF SCOPE** — never edit landed migrations |

### 2.1 The two managed-domain callers are the real work in §2

`ProvisionManagedDomains` and `ManagedDomainWatch` are live product flows that
reach nodes over SSH with no job, no builder and no primitive. They are not
mentioned in the architecture spec's transport table at all. R5 either gives
them a primitive or accepts that managed-domain provisioning stops at the
cutover. This is the largest unscoped item I found.

---

## 3. The agent side

`runner.go:198-215` `executeStep` dispatches four step types: `ssh`, `scp`,
`local`, `api`.

- `executeSSH` (runner.go:217) → `SSHPool.RunCommand` (`ssh.go:129`), pool at
  `ssh.go:17-128`, `sshAddr` at 197.
- `executeSCP` (runner.go:252) → `SCPTransfer` (`scp.go:11`), `buildSCPArgs` at 40.
- Both resolve the target through `db.GetNodeConnInfo` (`db.go:369-390`), which
  selects `mgn_ssh_key_path` and fills `NodeConnInfo.SSHKeyPath`.
- `golang.org/x/crypto/ssh` is imported only by `ssh.go`.

**What package main would lose:** `ssh.go` (199 lines) entire, `scp.go` (55)
entire, the two `executeStep` cases, `SSHPool` construction and
`sshPool.CloseAll()` in `Run`/`ReplayTeardown`, the `SSHKeyPath` field and its
scan in `GetNodeConnInfo`, and the `x/crypto/ssh` dependency.

**What must stay:** `executeLocal` and `executeAPI`, the whole plane-local job
source (`localqueue.go`, and the `local`-step path `build_publish_upgrade` and
`install_node`'s local steps use), credential placeholder substitution
(`resolveCmd`), and the container `docker exec` wrapping — which is a property
of the *step*, not of SSH, but currently sits inside `executeSSH` and would need
lifting if any surviving step type needs it.

**The contradiction R5 has to resolve.** Architecture spec §6 Step 3 says the
release deletes "the agent's SSH/SCP execution paths". Line 145 says
`install_node` "stays SSH, provisioning-time only". `install_node` emits 28
`ssh` and 4 `scp` steps executed by exactly those paths. Both cannot be true.
Three ways out, and the owner picks:

- **(a)** Keep `ssh.go`/`scp.go` and both step types, and make the deletion
  about *builders* rather than about the agent. The plane keeps the machinery
  and stops having a standing key to point it at. Weakest claim, smallest diff.
- **(b)** Move birth provisioning out of the job system entirely — `install_node`
  becomes an operator-run script with an operator-supplied key, never a job row.
  The agent genuinely loses SSH. Largest change, cleanest end state, and it fits
  "the operator is the delivery" that `install_agent.sh`'s own header already
  claims for first installs.
- **(c)** Keep the step types but gate them: the agent refuses an `ssh` step for
  any job type other than `install_node`. A compiled-in allowlist of one. Cheap,
  and it is the same shape as the primitive vocabulary — but it is a growing
  allowlist by construction, which §4 of the posture spec rejects for exactly
  this reason.

I would not guess. My read is that **(b)** is what the design bar in the posture
spec's preamble actually implies ("a production box rolled out generally must be
designed to need no SSH for any maintenance reason" — birth is not maintenance,
so moving it out of the plane's job system is consistent), but it is an owner
call with real cost.

---

## 4. The `authorized_keys` removal

### 4.1 Where the entry comes from

Nothing in the tree plants the fleet key's `authorized_keys` entry on the nine
existing nodes. Grepping the installers and provisioning scripts turns up only:

- **`JobCommandBuilder.php:3148-3159`** (inside `build_install_node`) — the
  "Pre-stage user1 for managed access" step, which **copies root's entire
  `authorized_keys` to `user1`** and grants `user1` NOPASSWD sudo, then switches
  the node's `mgn_ssh_user` to `user1`. This is the mechanism by which whatever
  key was used to reach root becomes a standing `user1` credential. It does not
  plant a specific key — it propagates every key root had.
- **`ProvisionCustomerCloud.php:125`** — `'authorized_keys' => array($pubkey)`
  at instance create, from `server_manager_customer_cloud_ssh_key_path`.
- **`RelayCloudProvisioner.php:149,296`** — per-run ephemeral key at create.
  Out of scope (owner).

So for the nine existing nodes the entry was placed **by hand or by an installer
run that predates the tree**, and it exists in at least two accounts per node
(`root` and `user1`, by way of the 3148 step). R5 must not assume one entry per
machine.

### 4.2 The machine list

From `mgn_managed_nodes` / `mgh_managed_hosts`, live rows only:

**Configured with `id_ed25519_claude` (the fleet key) — 11 nodes:**
`galactictribune`, `getjoinery`, `getjoinery-developers`, `getjoinery-orgs`,
`joinerydemo`, `joinery-relay-1`, `mapsofwisdom`, `phillyzouk`, `scrolldaddy`,
`scrolldaddy-dns-primary`, `scrolldaddy-dns-secondary`.

Eight of those eleven are containers on **three hosts**, and the hosts carry the
same key: `23.239.11.53`, `45.56.103.84`, `97.107.131.227`. The distinct
machines are therefore fewer than the node count — removal is per host plus the
standalone boxes.

**Configured with `config/provisioning_key` — 1 node:** `jeremytunnell-vps`
(`45.79.204.178`, ssh user `user1`, agent 1.10.0).

**Not paired (3):** `joinery-relay-1`, `scrolldaddy-dns-primary`,
`scrolldaddy-dns-secondary` — all three are the machines the spec places outside
the migration, and all three are configured with the fleet key. See §0.2.

### 4.3 What the removal step looks like per machine

Per machine, per account that has an entry (at minimum `root` and `user1` on any
node that went through the 3148 pre-stage):

1. Read the fleet key's public half locally
   (`/home/user1/.ssh/id_ed25519_claude.pub`, fingerprint in §0.1).
2. On the machine, remove matching lines:
   `ssh-keygen -R` is for known_hosts, not this — the operation is a filtered
   rewrite of `~/.ssh/authorized_keys` keeping every line whose key material is
   not this one, written to a temp file and renamed. **Match on the base64 key
   material, never on the comment** — the comment is attacker-controlled and
   also drifts.
3. Verify by count: the file must be exactly one line shorter, and must still be
   non-empty. An `authorized_keys` that ends up empty on a box where sshd is the
   only way in is a lockout, and `build_install_node:3151` already treats an
   empty root `authorized_keys` as fatal for exactly this reason.
4. Prove: a `BatchMode=yes` connection attempt with the removed key must be
   refused, and a connection with the operator's personal key must still succeed
   — **in that order, and the second before the first is irreversible.**

The uncomfortable part: this is 12+ machines' `authorized_keys` edited by hand,
after the release that removes the plane's ability to reach them. The window
where the plane can no longer dispatch but the key still works is exactly when
this must happen, and there is no tooling for it. R5 should say whether that is
a runbook (accepted, one-time, owner-present) or wants an
`authorized_key_remove` primitive shipped in the release *before* the cutover —
which is the only way it is not a shell-on-every-box errand, and which is
itself a destructive primitive and therefore A2-deferred to the sentinel spec.
That tension is worth naming rather than discovering.

---

## 5. The key destruction ceremony

### 5.1 Where the private halves live

| Copy | Path | Notes |
|---|---|---|
| Fleet key | `/home/user1/.ssh/id_ed25519_claude` | 0600 `user1:user1`, 411 bytes, dated 2026-01-26 |
| Fleet key, public | `/home/user1/.ssh/id_ed25519_claude.pub` | harmless, but delete with it so the fingerprint record is deliberate |
| Provisioning key | `{site}/config/provisioning_key` | 0640 `www-data:user1` |
| Provisioning key, public | `{site}/config/provisioning_key.pub` | 0644 |
| Customer-cloud key | wherever `server_manager_customer_cloud_ssh_key_path` points | **unset on this plane**; check every deployment |

Anything else is a copy, and the copies are the hard part.

### 5.2 Copies that would survive a `shred` of the originals

- **Every encrypted project backup.** `maintenance_scripts/sysadmin_tools/backup_project.sh:202`
  and `:431` are explicit: "The archive carries the whole site tree, config/
  included" and "Included: uploads/, static_files/, config/, public_html/,
  maintenance_scripts/". So `config/provisioning_key` is inside **every project
  backup chain this plane has ever made**, and inside every fleet node's own
  project backups of itself. `AgentDistPublisher`'s header states the same
  property as a feature for the signing key.
  → **`config/provisioning_key` cannot be destroyed by deleting the file.** It
  can only be *retired*: destroy the original, remove the `authorized_keys`
  entries, and accept that the ciphertext copies remain openable by anyone
  holding both a backup and its recovery key. Say that in R5 rather than claim a
  destruction that did not happen.
- **`/home/user1/.ssh/id_ed25519_claude` is outside the site tree**, so it is
  *not* in the project backups — which makes it the easier of the two to
  actually destroy, and is another reason the two keys need separate ceremonies.
  Whether the dev box itself is imaged or backed up elsewhere is a question I
  cannot answer from the tree.
- **Escrow / recovery records.** `RecoveryKeyFleet` and the recovery-record
  machinery escrow the *agent signing key* and recovery keys. I found no
  recovery record for either SSH key, and `AgentDistPublisher`'s comment
  explains why none was thought necessary (config/ rides the project backup).
  That reasoning is exactly what makes the key undestroyable. Worth a direct
  check against `tests/unit/installer_contract_test.php:858`, which lists
  `provisioning_key` alongside `cloudflare_dns_token` and `relay_pull_key.pub`
  in what looks like a config-inventory contract.
- **Offsite.** Whatever B2 holds of those chains. Not enumerable from here.

### 5.3 Proof of destruction

Composite, because no single check is sufficient:

1. **Absence** — neither private file exists; `stat` fails on both paths.
2. **Non-authentication** — for each machine in §4.2, `ssh -i <key>
   -o BatchMode=yes -o ConnectTimeout=10` refuses. This is the only test that
   proves the *entries* went, and it must run from a box that still has a copy,
   which means it runs **before** step 1 and its output is the artifact.
3. **No configured path** — `SELECT count(*) FROM mgn_managed_nodes WHERE
   mgn_delete_time IS NULL AND coalesce(mgn_ssh_key_path,'') <> ''` returns 0
   for every non-birth node, and the same for `mgh_managed_hosts`.
4. **No code path** — the gate test in §6.
5. **Named residue** — an explicit written list of the backup chains known to
   contain `config/provisioning_key`, with the statement that they are not being
   destroyed and why. A ceremony that quietly omits this is the one that gets
   contradicted later.

Current readiness: **9 of 12 live nodes paired.** The 3 unpaired are the relay
and the two resolvers — all three now outside the migration by owner decision,
and all three configured with the fleet key, which is §0.2's problem exactly.

---

## 6. Draft assertions for the deploy-tier gate

Model: `plugins/server_manager/tests/arbitrary_command_retirement_test.php` for
the "deleting code does not keep it deleted" framing, and
`tests/unit/core_api_mechanical_test.php:265-284` for the two-way caller pin
(unlisted callers fail; listed callers that no longer exist also fail, so the
allowlist cannot rot).

Header: `name: ssh_dispatch_retirement`, `tier: deploy`, `env: any`,
`needs: []`. **Deploy tier, never safe** — per the deploy-tier rule it runs on a
node, and the point is to assert against a real deployment's own tree.

```
section('The SSH builders are gone')
  - for each of the 15 names in §1.1: !method_exists(JobCommandBuilder, $name)
  - and the helpers in §1.3, ssh_prefix first

section('No builder emits an SSH or SCP step')
  - build every operation in the vocabulary against a fixture node that has
    BOTH ssh credentials and a paired agent, and assert no returned step has
    type ssh or scp. The fixture must have ssh configured — a node without it
    would pass by having nothing to fall back to, which is the check passing by
    not running.
  - the ONE permitted exception is install_node, listed by name, with a comment
    saying it is §5 birth. If §3 resolves as (b) there is no exception at all
    and this becomes an unconditional assertion.

section('No step composes an ssh command line')   // §0.3, the one a step-type
                                                  // sweep misses
  - for every builder's every step of any type, assert the cmd does not match
    /(^|[;&|(\s])ssh\s+-i\s/ and does not match /(^|[;&|(\s])scp\s+-i\s/
  - install_node exempt by name if it survives

section('Nothing outside the job system shells out to ssh')   // the §2.1 hole
  - a two-way caller pin over public_html/, exactly the shape of the
    server_initiated_write pin: every file containing proc_open/exec/shell_exec
    within N lines of an 'ssh ' literal must appear in a permitted[] list, AND
    every entry in permitted[] must still match — so removing the last caller
    forces the list entry out too.
  - permitted[] at cutover should be EMPTY, or contain only the birth path.
    Seed it with the three from §0.3 so the test is written before they are
    fixed and fails until they are.

section('The agent carries no SSH')                // skip if §3 resolves as (a)
  - agent source (server_manager_agent_source_path, default
    /home/user1/joinery-agent): ssh.go and scp.go do not exist; go.mod does not
    require golang.org/x/crypto; runner.go has no "case \"ssh\"" or
    "case \"scp\"".
  - guard on the source being present, the way AgentDistPublisher does — a
    management node without the agent repo must skip, not fail.

section('No node is configured with a plane-readable key')
  - every live mgn_ row and mgh_ row has an empty ssh_key_path, except rows in
    install_state 'installing' (birth in flight).
  - and: for any non-empty path, assert the file does NOT exist on this box —
    the strongest cheap check, because it catches the row and the key together.

section('The key files are gone')
  - !file_exists(config/provisioning_key)
  - the fleet key path from §0.1 is NOT hardcoded here; read it from the union
    of ssh_key_path values in a pre-cutover snapshot stored in the test, so the
    test names what it destroyed rather than what someone assumed it destroyed.
```

Two things the gate deliberately does not assert, and should say so in its
header: it cannot prove an `authorized_keys` entry is gone (that is §5.3.2, a
live probe, not a tree assertion), and it cannot prove a backup does not contain
the retired key (§5.2 — it never can, which is why the residue list is written
down instead).

---

## 7. Summary of what R5 must decide before it can be written

1. Which key is "the shared provisioning key" — and whether the fleet key is
   also a human's login key (§0.1). Everything else depends on this.
2. Re-run §9.2's resolver probe with the fleet key (§0.2). "No exemptions left"
   is currently unproven.
3. `install_node` versus deleting the agent's SSH — (a), (b) or (c) in §3.
4. Whether the API transport dies with the SSH transport (§1.2).
5. What replaces `decommission_node` and the three restore builders, or the
   explicit decision that nothing does (§1.6).
6. What happens to `ProvisionManagedDomains` and `ManagedDomainWatch` (§2.1) —
   the largest unscoped item.
7. Whether `authorized_keys` removal is a runbook or wants a primitive shipped
   one release earlier (§4.3).
8. That `config/provisioning_key` is *retired*, not destroyed, and the residue
   list that goes with that admission (§5.2).
