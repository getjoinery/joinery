# A publish is a job of the machine's own agent, dispatched by its manager

**Status: IMPLEMENTED 2026-09-05.** Live gate met the same day: getjoinery
(dev's node 33, on 0.8.373 by Apply Update) was published from dev's node
detail page as job 11542 of its own agent, and its upgrade endpoint served
0.8.373 afterwards. Root was logged in nowhere. Written 2026-09-05. Follows
`agent_local_queue_retirement.md` G1, which made `publish_upgrade` a primitive
of the plane's own agent and had the plane pair to itself.

## The rule

A machine's release is built by that machine's own agent, as the
`publish_upgrade` primitive, dispatched by whichever management node manages
that machine. There is one code path. Self-pairing is not a mechanism of its
own: it is the case where a plane manages itself.

The cases this covers, none of which the code distinguishes:

- **A plane nobody manages** (dev; a customer running their own management
  node). It connects to itself from its Management Node page, approves the
  request on its own dashboard, and its Publish page dispatches to its own
  record. No shell.
- **A plane that is another plane's node** (getjoinery, a node of dev; a
  customer plane we manage; a relay serving releases to its own fleet). Its
  manager publishes it from the manager's node detail page. Its own Publish
  page says so, naming the manager from its own agent's credential. No shell.
- **A plain node.** Never publishes. The action is offered only to a node whose
  agent reports the primitive, and the publisher on the node refuses to mint a
  number on a site that did not author the code
  (`DeploymentHelper::mayMintReleaseVersion`).

## Why not a second identity for the agent

An agent holds one credential for one management node, and everything about
it — the job lock, the leave flow, the switch, the join — is built on that. A
plane that is also a node would need two polling loops over one job lock to
publish itself, for the sole benefit of a Publish button on a page its manager
already has. A machine's manager already runs upgrades on it as root through
this channel; a publish there is no new authority.

## What the topology owns and the code does not

getjoinery is both the release relay for customers and a node of dev. That is a
fact about our infrastructure. The code never checks for it: dev publishes
getjoinery through the same node action it would use for any node that carries
the publisher, and getjoinery's own Publish page reads the same "managed by"
fact any managed plane would show.

## Built

- **Node action** `publish_upgrade` on the node detail Updates tab
  (`includes/node_detail_tabs/updates.php` 1.1,
  `logic/node_detail_actions_logic.php` 1.24): a FormWriter form with the
  version (defaulting to the version the node runs, which is what a relay
  republishes) and release notes, shown only when
  `JobCommandBuilder::has_primitive($node, 'publish_upgrade')`. Dispatches
  `build_publish_upgrade($node, …)` through `createFromBuild`, the same
  builder the Publish page uses for the plane's own record.
- **`ManagedNode::managed_by()`** — the URL of the management node this site's
  own agent is connected to, read from the `agent_join_state` setting the
  agent writes; null when the site is managed by nobody. Pure over the state
  array (`managed_by_from`), so it is tested without touching settings.
- **Publish page** (`views/admin/publish_upgrade.php` 1.9): with a record of
  itself, the form. Without one and managed by another plane: a notice naming
  that plane, where the publish is a node action. Without one and managed by
  nobody: connect to itself from the Management Node page, no shell.
- **Tests:** `job_command_builder_test` (the builder, any node);
  `management_job_rerun_test` (`managed_by_from`);
  `node_detail_actions_csrf_test` derives the action list from the
  dispatcher, so the new action is covered without an edit.

## Promotion, for the record

Publish on dev. Apply Update on getjoinery (node detail, Updates). Publish on
getjoinery (node detail, Updates). Three clicks on one dashboard; root is
never logged in to anywhere.
