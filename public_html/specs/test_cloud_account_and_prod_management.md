# Test cloud account, and production management on getjoinery

**Status:** Draft, 2026-10-06; checks C1–C4 answered 2026-10-07, adding
WP9. Built: WP1, WP3 and WP9 (committed). WP2 done 2026-10-07: dev holds
the test account's token, Delete Old Test Servers is on and its first run
passed the safety catch. WP4 step 1 done (getjoinery's live token, all
permissions present). WP4 step 3 done 2026-10-08 except Fleet Backups.
WP5 rehearsal started 2026-10-09 (below); the host move word docker-prod
needs (`move_to_plane`, agent 1.67.0) is built, uncommitted. Open: WP4
steps 2, 4, 5 (step 2 waits on the owner's Backblaze key), the rest of WP5
(waits on the next release), WP6, WP7.

## What this does

Today one Linode account holds everything: live customer servers, the fleet,
and the scratch boxes we make and throw away while testing. Dev's Server Manager
holds a token for that account, and dev is also the management node for the
production fleet. A bug or a bad test on dev can therefore reach a live server.
Test servers are also deleted by hand, because nothing can safely tell them
apart from real ones.

The owner has made a second Linode account for test servers only. This spec
splits the two worlds:

| | dev (dev.getjoinery.com) | getjoinery (getjoinery.com) |
|---|---|---|
| Linode token it holds | the **test account** | the **live account** |
| Nodes it manages | itself and test nodes only | every production node, itself included |
| Releases | publishes them (stays the origin) | republishes them; every production node upgrades from here |
| Test-server cleanup | automatic (WP3) | never runs here |

Each token lives in the Server Manager that uses it: sealed at rest and set on
that site's Provisioning Setup page. Every feature that creates or changes a
server already reads it from there. The Linode account logins (password and
2FA) stay in the owner's password manager. No Joinery site needs them.

This replaces the arrangement in `new_site_deployment_fortress_verification`
§ Two management nodes, where dev keeps managing the deployment fleet and
"nothing migrates between them". Amend that section when WP6 lands (A1).

## What dev manages today

Taken from dev's database on 2026-10-06.

- **Production nodes with an agent:** getjoinery-orgs,
  getjoinery-developers, galactictribune, mapsofwisdom, phillyzouk, scrolldaddy
  (all containers on docker-prod), getjoinery and Joinerydemo (each its own
  Linode, made by a site copy on dev with the operator token, so dev holds a
  provision record for each), jeremytunnell-vps (on the owner's own Linode account,
  through a connected-cloud grant), and the docker-prod host agent.
- **Production rows with no agent (uptime checks only):** both scrolldaddy DNS
  servers and joinery-relay-1.
- **Dev itself**, paired to itself so publishing runs as a job of its own agent.
  It stays.
- **What dev runs for them:** nightly fleet backups into the Backblaze bucket
  `joinery-backups-354`, plus backup retention, uptime checks, incidents
  (alerts to info@getjoinery.com), Apply Update, site copies, staged rollouts,
  install jobs and hosted provisioning.
- **Leftovers to clear:** hosted trials 61 and 74 are still open, end
  2026-10-19, and point at provisions already deleted. There is also one
  pending join request, "probe-test".

## Work packages

### WP1 — The setup page checks the token's permissions (B1)

**Being built in another session; not repeated here.** When the hosted card is
saved, the setup page asks Linode which permissions the token holds. It then
names each one missing and what will be refused without it
(`ProvisioningSetup::OPERATOR_TOKEN_SCOPES`). A token without
`linodes:read_write` cannot fulfil an order. A token missing any other permission
is kept, and only the work that needs that permission is refused.

**What this spec needs from it:** the test token and the live token both
show no missing permissions once saved. Today dev's token is refused for
account transfers, so the transfer-allowance alert in HostedTrialWatch would
fail on the first sold site.

### WP2 — Put the test account's token on dev

1. The owner creates a personal access token in the test account with the
   permissions WP1 lists, and sets the account's **company name** to
   `Joinery Test disposable` under Linode Account Settings (WP3 reads it;
   Linode refuses parentheses in the field).
2. Close hosted trials 61 and 74 and remove the "probe-test" join request.
   Otherwise HostedTrialWatch acts on them on 2026-10-19 with a token that
   cannot see their (already deleted) servers.
3. Save the token on dev's Provisioning Setup page. Expect the card to
   show the test account's name and no missing permissions.

**What stops working on dev, on purpose:** reverse DNS, IP swap, site copy to
a new server, and instance transfer for any server in the live account. Today
getjoinery and Joinerydemo are affected (their provision rows name
live-account Linodes); both move to getjoinery (WP4, WP6, WP9). jeremytunnell-vps is unaffected
because it uses the owner's own account. Scratch boxes that sessions create
from dev's token land in the test account with no change on their side.

**Copies of production sites wait for WP6.** From the swap until getjoinery
manages the fleet, dev does not copy a production site to a new server: the
new server would be made in the test account, and a switch-over by IP swap
cannot reach the live one. A copy onto a server the operator set up, switched
over by DNS, uses no token and still works. Copies of test sites work and land
in the test account.

WP2 does not depend on WP4–WP6 and can go first.

### WP3 — Automatic cleanup of the test account

A scheduled task on dev deletes test servers that are past their age. Nobody
deletes test boxes by hand any more, and a forgotten one costs a day of billing
at most.

**What it deletes:** servers (instances) older than the age limit (setting,
default 24 hours), and storage volumes not attached to anything that are
older than the same limit.

**What it never deletes:**
- anything tagged `keep` in Linode;
- a server whose IPv4 or IPv6 matches a live managed node on dev. It
  skips the server and lists it in the run's report, so the operator either
  tags it `keep` or deletes the node first.

**The safety catch is a property of the account, not of the site.** The
task runs only when the token's Linode account has the company name
`Joinery Test disposable`, which it reads on every run. The live account never
carries that name. So a live token pasted onto dev by mistake, or the task
turned on at getjoinery, deletes nothing: the run stops and opens an
incident naming the account it found. There is no "this is a test site"
setting that someone could flip in the wrong place.

**What the operator sees:** a card on Provisioning Setup showing the account
name, whether the safety catch passes, and what the next run would delete
(label, age, IPs). After each run, the task status lists what it deleted. The
card also lets the operator see the next run's effect before enabling the
task.

**Built (2026-10-07):**
- A new optional capability, `CloudAccountCleanup`, in
  `CloudComputeProvider.php`, implemented by `LinodeComputeDriver` 1.14:
  - `accountCompany()`: the company field alone, throwing when unreadable;
  - `listVolumes()` and `deleteVolume()`;
  - `listInstances()` now carries tags.
- `TestCloudCleanup`: the plan, the run, the stored outcome and the
  incident condition. The task is `DeleteOldTestServers`: hourly, off at install,
  turned on by hand under Scheduled Tasks.
- Incident source `plane:test_cloud_cleanup`, on the management node's own
  node.
- Settings: `server_manager_test_cloud_max_age_hours` (24) and the managed
  `server_manager_test_cloud_cleanup`.
- Provisioning Setup has card 11, with a preview on request.
- Managed-node addresses come from `MachineTransferWatch::node_addresses()`:
  the host and the newest join's addresses.
- Test `test_cloud_cleanup` (21 checks).

### WP4 — Get getjoinery ready to manage production

Things to check or set on getjoinery's own Server Manager before any node
moves:

1. **Live token** saved on Provisioning Setup, WP1 showing no missing
   permissions. (Probably already set for hosted orders. Not verified;
   getjoinery's settings are not readable from dev.)
2. **A backup destination of its own, locked.** A new Backblaze bucket,
   `joinery-prod-backups`, created with object lock on and no default
   retention, and a key scoped to it. getjoinery's target for it locks every
   backup for 35 days (retention 28 plus the 7-day full interval) and is the
   default for new backups. **Done 2026-10-09.** Only a bucket created with
   lock can lock, so `joinery-backups-354` could not be converted. A node must
   run 0.8.474 or later before its backups can go to a locking target. See C1
   for the old backups.
3. **Alerts** to info@getjoinery.com. Activate the fleet tasks: backups and
   retention, uptime, incidents, rollouts, install jobs.
4. **getjoinery manages itself.** Its agent leaves dev and joins getjoinery,
   the same way dev is paired to itself. From then on, getjoinery updates
   itself from dev's releases (`upgrade_source` = dev) and republishes each
   one, as it does today. The difference is that its own dashboard drives
   it instead of dev's. **This path has never run:** a managing site that is
   not the origin applying an update to itself. WP5 rehearses it first.
5. **Someone has to notice if getjoinery itself goes down.** It cannot watch
   itself. Dev keeps one uptime-only row for getjoinery, with no agent, the
   same as the DNS servers have today. This is the only production
   connection dev keeps, and it is read-only.

### WP5 — Rehearse in the test account

The test account makes this cheap. Create a throwaway management node and
one node in it, and run every step of WP6 on them, including the untested
self-update from WP4 item 4. The rehearsal must prove:

- leave, join and approve work, and the node's next backup lands and passes
  verification;
- a self-paired management node that is not the origin applies an update
  to itself and republishes it;
- a node whose upgrade source is set by hand to the new management node
  upgrades from it;
- WP9 adopts the throwaway management node's own server, and reverse DNS
  then works on it.

Anything in this list that needs a shell to do is a missing agent word.
Record it and add the word rather than
SSHing.

WP3 deletes the rehearsal boxes afterwards.

**Rehearsal so far (2026-10-09, test account, wp5-mgr / wp5-node.joinerytest.com):**
- Proven: a management node that is not the origin leaves dev and pairs to
  itself (WP4 item 4); a container site leaves dev and joins it, with its slug,
  site address and upgrade source set by hand (WP6 steps 1-3, 5); WP9 adopts
  the new management node's own server, and reverse DNS then works on it.
- Join names, read from the code: seven production nodes keep their slugs.
  jeremytunnell.com joins as `jeremytunnell` and getjoinery joins itself as
  `getjoinery-com`, both fixed by hand at step 3; docker-prod moves with
  `move_to_plane`, which carries its slug.
- Waiting on the next release: the new management node updates itself from
  dev and republishes, the node upgrades from it, a node backup through the
  broker, and docker-prod's move word (agent 1.67.0) on the practice host.

### WP6 — Move the production nodes

One node at a time, lowest stakes first: getjoinery-developers, phillyzouk,
mapsofwisdom, galactictribune, getjoinery-orgs, scrolldaddy, Joinerydemo,
jeremytunnell-vps, the docker-prod host agent, then the uptime-only rows
(scrolldaddy DNS ×2, joinery-relay-1). getjoinery itself moves in WP4 item 4,
before the others.

Per node:

1. On the node's Management Node page, **Disconnect** (a signed goodbye to dev).
   This also clears the node's marker for management-node backups.
2. **Join** getjoinery from the same page, and approve it on getjoinery.
3. **Match its slug and site address to dev's** (Overview tab, edit). A join
   leaves the site address empty until a status check fills it; set it here. The slug is the node's
   folder in the backup bucket, and a join names it from the hostname, so it
   can differ from dev's (jeremytunnell-vps will). Keep dev's slug anyway:
   the node's name stays the same in both places, and the old chains in
   `joinery-backups-354` stay findable under it (C1).
4. Set its backup policy on getjoinery and run one backup; it must pass
   verification.
5. **Point its upgrade source at getjoinery** by hand: on the node's own
   `/admin/admin_settings`, unlock the vault and set "Upgrade source" to
   `https://getjoinery.com`. Every site getjoinery creates already points at
   it, so this setting changes only when an existing site moves management
   nodes, and that is this migration alone. No remote action is built for it.
6. Check that uptime is green on getjoinery, then soft-delete its row on dev.
   Dev keeps its job and incident history as read-only.

Node-specific steps:
- **Joinerydemo and getjoinery:** each joins as a plain node, then WP9
  adopts its Linode on getjoinery, so reverse DNS and IP swap work there (C3).
  getjoinery adopts its own server.
- **jeremytunnell-vps:** the owner connects their own Linode on getjoinery
  (the connected-cloud OAuth flow) so getjoinery holds its own grant.
  Dev's grant is then disconnected.
- **docker-prod host agent:** moves after every container on it has moved
  (getjoinery is not one of them). It has no site page, so it moves with
  **Move to another management node** on its API Keys tab on dev (agent
  1.67.0, `move_to_plane`): the agent files the join with getjoinery under
  its slug `docker-prod`, stays with dev until getjoinery approves, then says
  goodbye to dev itself. Needs the release carrying agent 1.67.0 first.

**Done when:** dev's live node list holds only dev itself, test nodes, and
the uptime row for getjoinery. Every production node shows green on
getjoinery, backed up and verified. Also, every node's last Apply Update
downloads from getjoinery, not dev.

### WP9 — Adopt an existing cloud server

Reverse DNS, IP swap and instance transfer find a node's server only
through a provision record (`cvp_customer_cloud_provisions`). The node row
holds no instance id. Only creating a server makes one, so a node that
joins getjoinery has none, and those features refuse there. getjoinery and
Joinerydemo both need them.

An **Adopt cloud server** action on a node's Overview tab, shown when the
node has no provision record and is not a container:

1. It lists the instances visible to the operator token and to each
   connected cloud account. It matches the node's address against each
   instance's public IPv4 and IPv6, compared as addresses. This reuses the
   match `MachineTransferWatch` already makes.
2. It shows the one match (label, id, region, account) and asks the operator
   to confirm. No match, or more than one, refuses and names what it found.
3. On confirm (a POST) it writes a provision record:
   - origin `admin`, status `done`;
   - provider, instance id, IPv4, IPv6 and region;
   - hosting mode `operator`, or the connected account;
   - the link to the node.

Dev's provision records for both go when their nodes are removed on dev
(WP6 step 6): removing a node removes its provision.

**Built (2026-10-07):**
- `CloudServerAdoption`: `find()` and `adopt()`. The provision model has a
  new install mode, `adopted` (admin origin, bare metal).
- The node action `adopt_cloud_server`, and a Cloud server panel on the
  Overview tab where Reverse DNS would be. The panel names the daily
  transfer watch's match. The POST refuses a different answer.
- A host match beats a join match. Two servers at the same match are
  refused, as is a server another node's record holds.
- Test `cloud_server_adoption` (18 checks).

## Checks (answered 2026-10-07)

- **C1 — Old backups carry over, provided the slug matches.** Retention
  works from the bucket listing, not from history rows
  (`FleetBackupRetention::prune` lists `{prefix}/{slug}/manager/`). Two
  things follow:
  - getjoinery backs up to its own locked bucket, so a moved node's first
    backup there is a full one, and its old chains stay in
    `joinery-backups-354` under the same slug. getjoinery's old target
    (`Getjoinery-backup-read-write`) is kept, not removed, so those chains can
    still be listed, pruned and restored from getjoinery.
  - Dev stops pruning a node as soon as its agent stops polling dev, and
    never prunes a soft-deleted row. Retention works through each node's own
    storage space, and a moved node's space is on the new target, so nothing
    prunes its old chains: they are deleted by hand from the old target's
    Stored Backups panel on getjoinery, or left until that bucket is retired.

  So dev's Backblaze key for `joinery-backups-354` can be revoked once the
  last node has moved and its old chains have been deleted or are no longer
  wanted.
- **C2 — No remote path exists, and none is built.** Every production node
  downloads its releases from dev today. The setting is vault-gated on the
  node, and nothing on the management node writes it. It is set by hand in
  WP6 step 5, because only this migration needs to change it.
- **C3 — The provision record is needed, and nothing can recreate one for
  a running server.**
  - Reverse DNS, IP swap and instance transfer all refuse without one.
  - Site copy to a new server works without one, except the IP-swap
    switch-over.

  The fix is WP9.
- **C4 — The live account holds** docker-prod, getjoinery, Joinerydemo, dev
  and the drive soak VPS. The scrolldaddy DNS servers and joinery-relay-1
  are in another account. That is fine, since they are uptime-only rows and
  getjoinery needs no token for them. jeremytunnell-vps is in the owner's own
  account, as expected.

## Decided

- **Q1 (owner, 2026-10-06):** nodes move with the existing Disconnect/Join
  pages (WP6). No "move to another management node" action is built. Each
  node goes a few minutes with no management node, and its job and incident
  history stays on dev.
- **Q2 (owner, 2026-10-06):** the drive soak VPS (45.33.72.32) moves into the
  test account and is tagged `keep`. No hurry; do it at a soak pause. Use the
  Linode transfer the code already uses, after checking it qualifies (no
  attached volumes or firewalls). This is WP7.

### WP7 — Move the soak VPS into the test account

Lowest priority, at the next soak pause. Before starting the transfer, tag the
VPS `keep`, so WP3 never sees it untagged. After the reboot, restart the soak
driver by hand: a reboot does not bring it back on its own.

## Action items

- **A1** — When WP6 lands, amend `new_site_deployment_fortress_verification`
  § Two management nodes to match this spec.
- **A2** — Update `docs/deploy_and_upgrade.md` § publishing to getjoinery: its
  own dashboard applies the update and republishes, and production nodes
  upgrade from getjoinery.
- **A3** — Update memory: dev holds the test-account token, getjoinery
  manages production, and test servers are reaped by WP3 instead of being
  deleted by hand.
