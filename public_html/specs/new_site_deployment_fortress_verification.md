# New-Site Deployment + Fortress Live Verification — Prep Plan

**Status:** Active — ordered work plan. Item 1 is DONE (backlog committed,
release published 2026-07-18). Item 5 (the Fortress live-verification runbook)
is written: `specs/fortress_live_verification_runbook.md`. The decided
execution sequence for the remaining items is in **Execution sequence** below.
**Why this exists:** Two goals converge on one missing thing — a second, real
deployment. The shared-relay-fleet spec's own status line is the pivot:
*"Built — code complete and validated; live verification needs a real shard VPS
plus a second tenant deployment (dev is colocated, single deployment)."* Fortress
is the one feature that cannot be fully proven on dev, because dev's relay and app
share a box and there is only one tenant. So "deploy to a new site" is not merely
prep for testing Fortress — it **is** the test fixture.

## The convergence

Everything the fleet and Fortress features still need to prove requires a
topology dev cannot provide:

- **Two tenants** — the fleet's per-tenant isolation (spool namespaces, pull
  accounts, WireGuard peers, map-fragment merge with domain-allowlist
  enforcement) is untestable at N=1 on a colocated box. The cross-tenant claim
  boundary — the security property the merge unit exists to enforce — has never
  run against a real second tenant.
- **A real off-box relay** — dev is colocated (app and relay on one box), so
  the origin-hidden guarantee, the WireGuard tunnel, edge-seal-before-storage,
  and rebuild-carries-spool have only ever run in the degenerate co-located
  case, not across a real network boundary to a real shard VPS.
- **A fresh install** — months of features (Sealed Vault, drive, password vault,
  mailbox relay, AI memory, mobile billing) have landed since anyone did a
  from-scratch install. The zero-config principle is a claim that has drifted
  out of test.

## Site roles (decided 2026-07-18)

The three standing deployments and what each is for:

- **jeremytunnell.com** — the **feature test site**. First real daily-use
  deployment of the email / calendar / drive / password features (with and
  without encryption). It moves to its own VPS via the copy-site path and
  becomes the second fleet tenant for the Fortress verification below.
- **getjoinery.com** — the **production control plane**: Server Manager,
  automated provisioning fulfillment, and — once this testing program is
  green — the publish source for all production upgrades.
- **dev.getjoinery.com** — the **development site**: where features are
  built, the publish source for the beta-tester upgrade channel, and other
  dev actions.

There are **two control planes**, with different fleets — nothing migrates
between them:

- **Dev control plane** (dev.getjoinery.com, the existing node registry):
  manages the *deployment* fleet. It is how code reaches getjoinery.com and
  every other node — a prod control plane can only ever be deployed *from*
  dev — and it publishes the beta upgrade channel.
- **Prod control plane** (getjoinery.com): manages the *customer/production*
  fleet — provisioning hosts, customer-cloud nodes, and the production
  upgrade channel once this testing program is green. It registers its own
  hosts from scratch and never needs dev's registry.

## Execution sequence (decided 2026-07-18)

The concrete run order for the remaining items, folding in the decisions
above. Each step gates the next.

1. ~~Get the tree deployable~~ — **DONE 2026-07-18**: backlog committed,
   release published from dev.
2. **Build customer-cloud provisioning** on dev
   (`specs/customer_cloud_provisioning.md`): Linode OAuth provider +
   `customer_cloud` consumer, compute driver, fulfillment branch, Connect
   page. Built first deliberately — it removes the manual VPS purchase from
   the critical path (the feature creates VPS A) and makes test order #1 a
   test of the true final state.
   - *Parallel (operator, in progress):* register the OAuth client in Linode
     Cloud Manager (callback `https://getjoinery.com/oauth_callback`) and
     collect the referral URL from the Cloud Manager profile.
3. **Publish + upgrade getjoinery.com from dev** (Updates tab). First live
   publish→apply exercise of the new release cycle; getjoinery.com must be
   current before it can serve as the prod control plane.
4. **Activate provisioning on getjoinery.com** — the
   `specs/automated_hosting_provisioning_setup.md` checklist (domain
   Question, service user + API key, `server_manager_*` settings, scheduled
   tasks) executed **on getjoinery.com**, not dev, per the two-control-plane
   decision; plus the customer-cloud settings (OAuth client credentials,
   referral URL).
5. **Test order #1 — BYO-Linode** (satisfies item 2, fresh-install gate):
   the owner acts as the test customer. Order on getjoinery.com → Connect
   the owner's Linode account via OAuth → pipeline creates **VPS A** on that
   account, installs, registers the node, SSL. This is the from-scratch
   install proof *and* the final-state provisioning proof in one run.
6. **Test order #2 — shared-host mode**: flip VPS A to Provisioning Enabled
   on the prod control plane, place a second test order → container install
   on VPS A proves the original shared-host fulfillment mode. Both modes
   tested on one VPS.
7. **Clone jeremytunnell.com onto VPS A** (satisfies item 3, copy-site
   test): `backup_project.sh` on the current prod box →
   restore/clone flow onto VPS A. jeremytunnell.com then begins life as the
   feature test site (email / calendar / drive / passwords, with and without
   encryption).
8. **VPS B — relay shard** (item 4): buy the second VPS, deploy the relay
   stack in fleet configuration, point jeremytunnell.com's Fortress domain
   MX at it.
9. **Fortress live-verification runbook** (item 5), then the **pentest
   brief** (item 6).

## Ordered work

### 1. Get the tree deployable (task)

Publish Upgrade builds from the repo, so a clean commit is the literal first
gate. There is a large uncommitted backlog on dev — compose maturity (staged,
message ready), drive core + encryption, password vault, mobile billing, AI
memory, test-estate fixes, the URL-validator refactor. Land it in reviewable
commits, run the `safe` tier as the pre-deploy gate, then publish a version via
the Server Manager dashboard.

**Done when:** working tree is clean (or intentionally staged), `php tests/run.php
safe` is green, and a version is published.

### 2. Fresh-install gate (task; worth a test)

The zero-config principle says a new site needs nothing beyond
`Globalvars_site.php` + `install.sh` args. That claim is untested against the
current feature set. Do a from-scratch install (Docker-on-Ubuntu, the supported
path — `multi_distro_install_refactor.md` is explicitly deferred, do not block on
it): install, `update_database`, plugin sync, login, load the `/tests/` dashboard.
Anything that needs a manual step beyond the two inputs is a zero-config
regression to fix at the cause, not paper over in the runbook.

**Done when:** a scratch install reaches a working superadmin session and a green
`safe` tier with no undocumented manual step. Capture the smoke sequence as a
scripted gate if it isn't already.

### 3. Stand up the new site (task)

Two existing docs cover this depending on how the site is born:

- **Automated pipeline** — `specs/automated_hosting_provisioning_setup.md` is the
  not-yet-executed activation checklist. Using it here would also finally exercise
  that feature end-to-end (a second win for one effort).
- **Manual install** — the `install_tools/` path if you just want a box without
  activating the provisioning pipeline.

If the new site shares the getjoinery Stripe account,
`specs/sister_brand_deployment.md` lists platform code items (brand metadata on
customers/subscriptions + webhook brand-filtering) that must land before a second
deployment goes live.

**Done when:** a second Joinery deployment is reachable, has its own DB, and can
create a domain/user/vault independently of dev.

### 4. Stand up a real relay shard (task)

Buy an actual VPS, deploy the relay stack to it, point the new site's MX at it.
`specs/implemented/mailbox_relay_vps_test_runbook.md` is the proven procedure —
this repeats it on real hardware in the fleet (multi-tenant) configuration rather
than the colocated dev setup. This is where the new site becomes a fleet tenant.

**Done when:** the new site's Fortress domain receives edge-sealed mail through an
off-box shard, tunnel up, origin absent from mail DNS.

### 5. Fortress live-verification runbook (spec) — **WRITTEN**

`specs/fortress_live_verification_runbook.md`. Turns "second tenant + real shard"
into an executor-runnable acceptance checklist covering the guided Fortress setup
flow, inbound edge-seal proof, deferred-ingest-at-unlock, locked-send refusal,
in-window vault-sealed DKIM send, origin-hidden headers, the shard rebuild drill,
per-tenant isolation between the two tenants, and the exit ramp. It is the
functional layer; item 6 is the adversarial layer on top.

### 6. Run the pentest brief (task)

`specs/mailbox_security_model_pentest_brief.md` was written for exactly "fully
testing the Fortress features." First reconcile its `⟨VERIFY⟩` sections against
the shipped build, then run it against the new site (a realistic target it
presupposes). A multi-agent red-team workflow is the right tool for the adversarial
sweep once the functional runbook (item 5) is green.

## Cross-cutting: live-tier test coverage

The relay/fleet suites run at **db tier** today; only `profile_mailbox_test` is
live-tier. The test-estate audit (`specs/test_estate_audit.md`) still has its P3
greenfield suites open. The new-site exercise is the natural moment to add
**live relay/Fortress suites against real infrastructure** — fold new live-tier
tests in as items 4–6 execute rather than deferring them again.

## What is NOT in scope here

- Fleet features beyond N=2 (shard sizing under load, migration between shards) —
  provable later; the second tenant proves the isolation boundary, which is the
  security-critical part.
- The multi-distro install refactor — deferred; Docker-on-Ubuntu is the path.
- Client-custody mail (`DEFERRED_client_custody_mail.md`) — parked.
