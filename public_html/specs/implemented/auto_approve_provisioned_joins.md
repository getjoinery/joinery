# Auto-approve joins from machines this plane provisioned

**Status: IMPLEMENTED 2026-10-08 (WP1-WP4 built). Verified: `join_auto_approval_test` 37/37 (and fails with the key check removed); customer_cloud_provisioning, agent_channel, job_result_processor and install_job_executor suites still pass; dashboard rendered against dev data. Not yet run live: a real provision end to end (SITE_AGENT_KEY readback, both joins unattended) - in the live verification queue.**

## What this is for

When this plane builds a machine, it already knows the machine's address, the names its two agents will join under,
and (because it runs the install itself) the keys those agents print. Today it throws that knowledge away: every
provisioned agent lands in **Agents asking to join** and the operator compares a key by eye. At 50-100 machines a day
that does not scale, and it is the wrong job for a person: the plane can do the comparison exactly, and a person
should only see the joins the plane did *not* expect.

After this, a join from a provisioned machine is approved automatically when the plane can prove it is the machine it
built. **Agents asking to join** becomes a list of manual cases only.

## What exists today (verified 2026-10-08)

- A join request carries the claimed name, the agent's public key, its fingerprint, the source address (IPv4 or
  IPv6) and the reported addresses (`AgentChannelEndpoint::handle_join`).
- `CustomerCloudProvision::for_machine_address()` names the provision a request's address belongs to, by either of the
  instance's addresses.
- `ProvisionCustomerCloud::join_approval_check()` asks the cloud provider whether the instance is running at the
  request's address.
- Two approval paths already exist and are used by the dashboard's Approve button:
  `AgentChannelEndpoint::approveProvisionSiteJoin()` (a provision's **site** agent, bound to the provision's site
  node) and `AgentChannelEndpoint::adoptJoin()` (a provision's **host** agent, claim `<slug>-host`).
- Site copies already approve their own agent (`SiteCopyRunner`). Not touched.
- The install job's output (`mjb_output`) already contains the **host agent's** key, in this shape:

      Asked https://<plane> to adopt this machine as "<slug>-host".

          Key fingerprint:  f2716c8b2d4c52b5

  Checked against install jobs 86835 and 86836: the printed key equals the request's `ajr_fingerprint`.
- It does **not** contain the **site agent's** key. The site agent is switched on and its join lodged by
  `agent_control.php --on --join=URL`, and the key only exists once the running agent has staged it a moment later, so
  the install ends with `join requested: <url>` and no key.

## The decision rule

A pending join request is approved automatically **only when every one of these holds**. Anything else stays in the
manual list, unchanged.

1. **A provision expected it.** The request's source address belongs to a provision (`for_machine_address`), and that
   provision is not failed or dismissed.
2. **The name is the expected one.** The claimed name is exactly the provision's site name (site agent) or
   `host_agent_name()` (host agent). Nothing else is auto-approved.
3. **The key is the one the install saw.** The request's fingerprint equals the key the plane recorded from the install
   for that agent (`cvp_expected_site_key` / `cvp_expected_host_key`). No recorded key means no auto-approval.
4. **The provider agrees.** `join_approval_check()` reports the instance running at exactly the request's address.
5. **Nothing is already there.** For a site agent, the provision's site node has no agent yet (first join wins, as
   today). For a host agent, no host agent is already linked to the host record.

The order matters because the join endpoint is public: checks 1-3 are database reads and run first; the provider call
(4) runs only for a request that already matched a recorded key. A stranger cannot make the plane call the provider.

**Fail closed.** Any exception, missing field, provider error or mismatch leaves the request pending and untouched. It
is never rejected automatically: a mismatch is information for the operator, not a verdict.

A recorded key is **one-use**: approving consumes it, so a second request with the same key cannot be auto-approved
again.

## Design

### WP1 - Record the keys the install saw

- New fields on `cvp_customer_cloud_provisions`: `cvp_expected_site_key` and `cvp_expected_host_key` (`varchar(16)`,
  the 16-hex fingerprint), plus `cvp_keys_captured_time`. Schema comes from the data class; no migration.
- **Host agent:** `JobResultProcessor::process_install_node` parses the install output for
  `Asked <url> to adopt this machine as "<name>".` followed by `Key fingerprint:  <16 hex>`, and records the key when
  `<name>` equals the provision's `host_agent_name()`. Only a completed install job whose node belongs to a provision
  records anything.
- **Site agent:** `install.sh site` (docker and bare-metal paths), after switching the agent on and lodging the join,
  waits up to 90 seconds for the agent to stage its key and prints one machine-readable line
  `SITE_AGENT_KEY=<16 hex>` (the same readback convention as `CONTAINER_PORT=`). `process_install_node` records it as
  the site key. If the key does not appear in time the line is simply absent: the join stays manual, which is the
  safe outcome. `agent_control.php --status` already prints the key once staged; the wait polls it.
- A second install job for the same provision replaces the recorded keys (a retry mints new agents).

### WP2 - Decide and approve

- New class `JoinAutoApproval` in the plugin's `includes/`:
  - `verdict($request)` is pure and fast (checks 1-3 and 5, no provider call). It returns the matched provision, which
    agent it is, and either `eligible` or the first reason it is not. The dashboard also uses it.
  - `run()` loops the pending requests (at most `AgentJoinRequest::MAX_PENDING`), and for each `eligible` one runs the
    provider check and then the existing approval path (`approveProvisionSiteJoin` or `adoptJoin`), then consumes the
    recorded key and stamps the request `ajr_decided_by = 'auto'` (new `varchar(12)` field; a human approval leaves it
    empty). Every automatic approval is written to the error log with the provision, the agent and the key.
- New scheduled task `AutoApproveJoins` (`every_run`, `activate_on_install`) calls `JoinAutoApproval::run()`. One path
  covers the race between the join arriving and the install log being processed: whichever finishes second, the next
  tick approves it. It is cheap when nothing is pending (one indexed query).
- A **kill switch** setting `server_manager_auto_approve_joins` (default on, declared in `plugin.json`) so the operator
  can turn the whole thing off without a deploy.

### WP3 - Dashboard

- **Agents asking to join** shows only what is still pending. A request that matches a provision but was *held*
  carries its reason in plain words ("key does not match what the install saw", "no key was recorded for this
  install", "provider does not show the instance running"), computed from `verdict()`. A request that matches a
  recorded provision with a **different key** is the one to look at, so it gets the danger tag.
- A provision's entry in **Cloud provisions** says when its agents were approved automatically.
- The unexpected cases (unknown machine, mismatched key, second agent on a machine that has one) remain manual and
  are unchanged.

### WP4 - Tests (`tests/`, harness, `db` tier)

Each case builds a provision, a recorded key and a join request, runs `JoinAutoApproval::run()` with the fake
provider (`AgentChannelEndpoint::$provisioner`):

- site agent with matching key, address and name: approved, bound to the provision's site node, key consumed;
- host agent with matching key: host node made and linked;
- wrong key; wrong name; address of no provision; address of a *different* provision; no recorded key; provider
  says not running; provider throws; a second agent after the site node already has one; a replayed request with a
  consumed key: **all stay pending, nothing created**;
- IPv6 source address matched against the provision's IPv6;
- kill switch off: nothing approved;
- the provider is never called for a request that fails checks 1-3;
- `process_install_node` parsing: host key recorded for the right name only; `SITE_AGENT_KEY=` recorded; garbage and
  a failed install record nothing.

### Documentation

`plugins/server_manager/docs/` gets a short "Join approval" section: the rule above, what stays manual, and the
kill switch. Written as the current state only.

## Not in this spec

- Machines this plane did not provision (a hand-installed node, a relay, a DNS box): still approved by a person.
- Site copies: already automatic.
- Auto-rejecting anything. A mismatch is surfaced, never decided.
- Changing the agent's key, the join protocol or the join endpoint itself.

## Risks

- This removes the human key comparison for provisioned machines. What stands in for it: the key the plane itself
  watched the install produce, the provider's word that the instance is running at the request's address, and the
  exact name. That is the trust model the original agent design chose for provisioned installs.
- The 90-second wait for the site agent's key lengthens an install by up to that much when the key is slow; a missing
  key costs only the automation, never the install.
