# Managed to Self-Hosted — Hand the Server to the Customer's Linode Account

**Status:** Built 2026-10-02 on the mocked driver (`instance_transfer` suite, 80 checks); schema
and email templates applied on dev. All owner decisions taken 2026-10-02 (§10). Design review by
`public-html-91` 2026-10-02: R1–R22 folded in (§12). The live dev run (§8) waits on A1 (the new
operator token). Build notes in §13.

**Companions:** `hosted_trial_provisioning.md` (the Managed tier this ends — its open item A1 and
the §7 "move to your own Linode" off-ramp), `services_phase2_platform.md` (self-hosted sites using
our mail and backup storage), `site_copy.md` (the path for a site this one cannot move),
`keyless_provisioning.md` (no credential of ours left working on a customer's machine).

## 1. What this does

A Managed customer's site runs on its own Linode instance in **our** Linode account. This feature
moves that instance — running, same disks, same IP addresses — into the **customer's** Linode
account. Linode bills them from then on, and we stop billing them for hosting. Their mail and
backups keep working as they did, now as a Services subscription (D1), and we keep managing the
site until they say otherwise (D2).

Nothing is copied or rebuilt. DNS does not change (the IPs move with the server), and the
customer's recovery key is never involved. This replaces the off-ramp
`hosted_trial_provisioning.md` §7 described (a new bring-your-own install fed by a live clone),
which assumed an instance could not cross Linode accounts. It can: Linode's Service Transfer.

**Who it is for:** a site a customer bought as Managed, on a dedicated instance in our account
(§3 has the exact test). A site in a container on one of our shared Docker hosts has no instance
of its own to hand over; it moves with `site_copy.md` instead (§9).

## 2. Linode Service Transfer, in brief

Base `https://api.linode.com/v4`. Our side uses the operator's Linode token.

| Step | Call | Who |
|---|---|---|
| Create | `POST /account/service-transfers` `{"entities":{"linodes":[ID]}}` → `token`, `status`, `expiry` | us |
| Accept | `POST /account/service-transfers/{token}/accept` — or Cloud Manager → Account → Service Transfers | customer |
| Status | `GET /account/service-transfers/{token}` | us (poll) |
| Cancel | `DELETE /account/service-transfers/{token}` — only while `pending` | us |

- The token is valid **24 hours**. Once accepted, the move finishes within **3 hours**, and the
  transfer **cannot be undone**. Status values: `pending`, `accepted`, `completed`, `failed`,
  `canceled`, `stale` (expired).
- **Moves:** the instance, its disks and configuration, its IPs, and its Linode Backups (if that
  paid add-on is on). **Does not move:** Linode DNS Manager records, Longview keys (we use none).
- **Linode's promise is narrow:** services "will not experience interruptions due to the
  transfer." Our emails say exactly that and no more.
- **The receiving account** must have a payment method, no past-due balance and no ToS
  violations, and must not already have a Linode with the same **label** (ours are
  `{slug}-{provision id}`, so a clash is rare, but it fails the transfer after accept). Both
  accounts get a confirmation email from Linode. Linode's docs do not say whether the sender
  learns which account accepted; we assume not.
- **Linode enforces every restriction at create (400 with a reason) and again at accept.** Our
  pre-check (§3) is for a clear page; the create call's reason is still shown verbatim, because
  something attached between check and create cannot be pre-checked.
- **The token is a bearer secret.** Whoever accepts it gets the server and every byte on it. It is
  handled like a password: shown only on the customer's signed-in page, never put in email, a job
  step or a log line (§6).

**Token scopes.** The operator token today is `linodes:read_write`. Creating a transfer needs
`account:read_write`; the eligibility checks also read firewalls and volumes. A1: the owner mints a
replacement token with those scopes and saves it on the Provisioning Setup page. The helptext there
and in `plugin.json` names the new scopes.

**Why the customer accepts by hand rather than us accepting for them:** accepting through the API
would need an `account:read_write` grant on the customer's Linode account. We ask for no more than
`linodes:read_write` anywhere today, and a customer becoming self-hosted should end up with less of
our reach, not more. Cloud Manager's Accept is one screen.

## 3. The eligibility check

Runs when the operator opens the panel, again before every code is issued, and on demand. Each
item is a pass, a **blocker** (prevents a code being issued) or a **warning** (shown, does not
block). Every blocker says what to do about it. The check never fixes anything itself.

**Linode side** (operator token):

| Check | Call | Result if found |
|---|---|---|
| Instance exists on our account | `GET /linode/instances/{id}` | blocker if 404 |
| Resource lock | same object, `locks` (`cannot_delete`, `cannot_delete_with_subresources`) | blocker: remove the lock |
| Cloud Firewall attached | `GET /linode/instances/{id}/firewalls` | blocker: detach it |
| Block Storage volume attached | `GET /linode/instances/{id}/volumes` | blocker: detach it (data on it does not move) |
| Shared IPv4 | `GET /linode/instances/{id}/ips` → `ipv4.shared` | blocker: unshare |
| Reserved IPv4 | same call, `reserved` on an `ipv4.public` or `ipv4.shared` address | blocker: convert to ephemeral (address is kept) |
| IPv6 range (/32, /56, /64, /116) | same call, `ipv6.global` | blocker: remove the range |
| NodeBalancer | `GET /linode/instances/{id}/nodebalancers` | blocker |
| VLAN or VPC interface | by the instance's `interface_generation`: `legacy_config` → `/configs` interfaces `purpose`; `linode` → `GET /linode/instances/{id}/interfaces`, non-null `vpc` / `vlan` | blocker |
| Linode Managed on our account | `GET /account/settings` → `managed` | blocker |
| Already in a pending transfer | `GET /account/service-transfers?status=pending&is_sender=true` | blocker |
| Linode Backups add-on enabled | instance `backups.enabled`; also `GET /account/settings` → `backups_enabled` (auto-enrols every new instance) | warning: it moves and the customer starts paying for it |

None of these is something this plane attaches today (no firewall, volume, reserved IP, VPC or
NodeBalancer code exists in server_manager), so a Managed instance normally passes. A blocker
means someone changed the instance by hand in Cloud Manager. If the account-level
`backups_enabled` is on, the warning fires on every site: turn it off on the operator account.

**Platform side:**

| Check | Result if found |
|---|---|
| Not a sold Managed site: `cvp_hosting_mode` not `operator`; `cvp_origin` not `order`/`buyer`; no `cvp_external_order_item_id`; `cvp_install_mode = bare`; no `cvp_instance_id`; provision not `done`; node is a management node or `mgn_is_relay` | blocker (relay shards and operator-account site copies are also `operator` mode) |
| Node not operational (`ManagedNode::is_operational()`: a dormant copy, `switching`, `retired`, `installing`) | blocker |
| An open site copy with this node as source or target | blocker: wait |
| Another node's `mgn_copy_of_node_id` points here | warning: that dormant copy will name a node we no longer host |
| A management job running or queued on the node | blocker: wait |
| Install password not `retired` (`cvp_install_password`; `retire_failed` keeps it working) | blocker: a working credential of ours would ride along (`keyless_provisioning.md`) |
| `mgn_ssh_key_path` or `mgn_ssh_user` set on the node | blocker: an operator-added key is a working credential of ours; remove it from the box and the node |
| Mail leg unfinished (`cvp_mail_state` not `done`, `failed` or empty) | blocker: wait — the mail pipeline only advances operator-hosted rows, so a move now would strand it |
| Hosting in `grace` (a payment failed) | warning: operator's call |
| Hosting in `shutdown` (powered off, deletion task raised) | handled at Start, below |
| Open incidents on the node (`inc_triage` new/looking/snoozed) | warning, listed |

**A site in `shutdown`.** Shutdown raised `hosted.deletion_required` to a person. A person acting
on it while a code is out deletes the instance and the transfer fails. So Start on a shut-down
site **withdraws the deletion**: a node note and a second email to the same recipients ("do not
delete — this server is being handed to its customer"). The site moves powered off; email 4 tells
the customer to boot it.

## 4. The flow

```
operator: node detail → "Move to customer's Linode" → check passes → Start
   │                                                           (row: invited)
   ▼
email 1: "Your site can move to your own Linode account" — why, the
         monthly cost, open a Linode account (referral link) + add a card,
         then press "I'm ready" on Your sites
   │
customer: Your sites → "Get my transfer code"
   │      (plane re-runs the check; a blocker stops here, the customer is
   │       told we are on it, and the operator is alerted)
   ▼
plane creates the transfer                                (row: code_issued)
customer page shows the code, a copy button, the 3 Cloud Manager steps,
and "valid until 14:05 tomorrow"; email 2 says the code is ready (link only)
   │
   ├── 24 h pass, not accepted → Linode: stale → row back to invited,
   │   email 3 "your code expired — get a new one"
   ├── operator or customer cancels → DELETE transfer → row: canceled
   │
customer accepts in Cloud Manager                         (row: accepted)
   │   Cancel disappears (accepted cannot be undone); Linode moves it (≤ 3 h)
   ▼
plane sees completed → finishing (§5)  →  row: done, email 4 to customer,
                                          note to operator
   └── Linode reports failed → row: failed, operator alert, customer told
       we are looking into it (a label clash on their account is the
       likeliest cause; the operator relabels and issues a new code)
```

The customer can also start it (D3): a "Move to my own Linode account" button on Your sites puts a
row in the queue as `requested`, and the operator's Start moves it to `invited`. The operator also
has "Issue code now" on a row, for a customer walked through it on a call.

## 5. Finishing (on `completed`)

One scheduled pass, one step at a time, each recorded on the row so a crash resumes where it
stopped:

1. **Confirm it left us:** `GET /linode/instances/{id}` with the operator token returns 404.
2. **The provision says what happened:** `cvp_hosting_mode = transferred` (a third value), transfer
   time noted. Not `customer`: that would make the bring-your-own-cloud branch live for a row with
   no account grant, and a password-retirement re-run would park it in `pending_connect` and mail
   the buyer to reconnect. `is_operator_hosted()` is false for it, so HostedTrialWatch,
   ProvisionHostedMail and every other operator-mode reader drops it.
3. **Hosting billing ends, state first:** the hosting row moves to `transferred`. Only then is the
   subscription cancelled — immediately, no refund, no store emails (D4). The cancel fires
   `subscription.cancelled`, and HostedTrialSignals must ignore a `transferred` row in both
   `start_grace` and `clear_grace`, or it would put the row into grace minutes later. The store's
   `cancel_subscription_order_item` asserts a signed-in user and cannot run from a task: the build
   adds a system variant (no session, `$send_email = false`). When the store is remote (the
   HostedTrialWatch case), finishing records an operator to-do "cancel the subscription in the
   store" on the row and carries on; the queue keeps the row flagged until it is ticked.
4. **Mail and backups carry on (D1)** — §5a.
5. **A shut-down site comes back on our books as live:** if Start withdrew a deletion, reverse what
   shutdown wrote — backup policy on, `mgn_uptime_enabled` on, the "awaiting deletion" note
   replaced.
6. **The banner switches to Services:** the finishing pass composes and dispatches
   `hosted_plan_notice` itself (HostedTrialWatch no longer reaches the row), now showing the
   Services standing (§5a) in the `services` state the node script already renders
   (`utils/hosted_plan_notice.php` 1.2). An agent without the primitive, or an older release that
   answers `HOSTED_PLAN_NOTICE=error` for that state, is a note on the row, not a failure.
7. **Our management stays (D2):** the node remains paired, now a bring-your-own-cloud node.
8. **Domain:** the IPs did not change, so the A records are still right. A domain we registered
   for them stays on its existing graduation path (`managed_domain_registration.md`). If their
   zone lives in our Linode DNS Manager, it stays ours and keeps answering; email 4 lists the
   records we hold for them.
9. **Email 4 to the customer:** what is theirs now —
   - the Linode instance and its monthly cost, and Linode Backups if it moved with them;
   - **root access:** the server takes no password over SSH and holds no key of ours; reset root
     in Cloud Manager and use the **Lish console**, then add your own SSH key;
   - reverse DNS is now set in their Cloud Manager;
   - mail and backups carry on as a Services subscription, paid up to the date their hosting was
     paid to, and what happens after.

### 5a. Mail and backups as a Services tenant

The Managed site never ran the Services Connect flow, so it has no connected API key and the site
cannot call enrol, status or release itself. Its two tenant rows are **node-linked** instead
(`svt_mgn_managed_node_id`), reached only by the plane's reconcile and the operator's grant and
release. Nothing on the box changes.

**Mail** — a row copy, then the existing Services machinery runs it:
- `svt_provider_subaccount_id` ← `cvp_smtp2go_subaccount_id`; `svt_provider_user_id` ←
  `cvp_smtp2go_user_id`; `svt_provider_domain` ← the sender domain name `mail.<domain>` (not
  `cvp_smtp2go_domain_id`, which is a provider id); `svt_mail_state` ← `domain_verified`;
  `svt_mail_records` ← `cvp_mail_records`; `svt_host` ← `cvp_domain`; `svt_slug` `t<id>`;
  `svt_state` active; `svt_allowance`; `svt_paid_until` = end of the paid hosting period.
- Then clear `cvp_smtp2go_user_id` and `cvp_smtp2go_subaccount_id` on the provision: the SMTP2GO
  webhook matches provisions first and would keep counting sends against the old Managed row.
- From here the mail meter, suspend and subaccount close work as for any tenant.

**Backups** — the fleet path stays; the tenant row's ladder acts on the node. A Managed site's
backups never went through the broker: the fleet job writes `joinery-backups/{mgn_slug}/` with a
per-run key. A shelf tenant row on its own would govern nothing, so for a node-linked row:
- **figure** = the node's measured backup bytes;
- **allowance** = the act HostedTrialWatch does today (pause the node's policy with
  `paused_for_shelf` over the cap, resume under it), lifted into a helper both callers use;
- **suspend** = node backup policy off with the reason; **reactivate** = back on;
- **prune** = empty `joinery-backups/{mgn_slug}/`, the act `prune_shelf_if_due` does today, lifted
  the same way.
- At finishing, a node paused with `paused_for_shelf` and under the cap is resumed.

**Telling the customer.** A Managed box has no Services key, so the Services status poll never
writes its banner. For node-linked rows, ServiceTenantWatch composes `hosted_plan_notice` from the
tenant rows (paid-until, usage, grace) and pushes it when it changes — the primitive is already on
the box. One email at grace start and one at suspension, to the provision's user.

**Connected sites page.** A node-linked row shows as "Moved from Managed — mail and backups through
us" with no Disconnect button (Disconnect releases every row for a host; one click would close
their mail). The customer leaves our mail through the existing off-ramp (the wizard's Email step to
their own SMTP2GO), after which the operator releases the row.

## 6. Pages, emails, task

**Customer — Your sites (`/profile/server_manager`).** A "Move to your own Linode account" card on a
Managed site's entry, showing the row's state in plain words. Once the site is no longer hosted the
page hides its plan and mail lines, so the card carries the whole story. In `code_issued` it shows
the code (revealed by a button, like the admin password reveal), the expiry, and the accept steps.
Buttons: I'm ready / Get my transfer code / Cancel (gone once accepted). The buttons call API
actions (`_logic_descriptor()`), per the platform's API rule.

After `done`, the entry offers **"Stop managing this site" (D2)**. Unpairing the agent alone would
leave a node that fails its backups nightly and raises silent-agent incidents, so the action:
1. forgets the agent (`AgentChannelEndpoint::forgetAgent`);
2. turns off the backup policy and uptime checks, and sets `mgn_enabled` false;
3. closes the node's open incidents with the reason. For the close to stay closed, the incident
   reconciler skips `mgn_enabled = false` nodes (`IncidentReconciler`, beside its
   `is_operational()` skip). Without that, sources that read columns frozen at unpair
   (Unmanageable's `mgn_script_trust`, FailedUnits and MonitoringBroken's last host report)
   reopen the incident every tick. The skip applies to every disabled node, which is what
   disabling a node means;
4. releases the backup tenant row, which starts the 90-day retention clock on
   `joinery-backups/{mgn_slug}/` — the only point those backups ever get a prune date.
The mail tenant row stays (mail does not depend on the agent). The confirm screen says all of
this: backups stop, stored backups are deleted after 90 days, mail continues until the paid-until
date.

**Operator — node detail, Overview tab.** A "Move to customer's Linode" panel on an
operator-hosted node: the check results (§3) with Re-check, Start, and a link to the node's queue
row once one exists.

**Operator — queue page (`/admin/server_manager/transfers`).** One row per transfer: site, customer,
state, code expiry, last Linode status and when it was read, last email sent, the check summary,
and any operator to-do (§5 step 3). Actions: Re-check, Resend email, Issue code now, Cancel (while
`pending`). Filter: open (default) / done / all. Linked from the server_manager admin menu.

**Emails** (EmailSender templates, editable): 1 invitation, 2 code ready (link, never the code), 3
code expired, 4 done; deletion withdrawn (§3, to the operator's recipients); Services grace start
and suspension (§5a). Operator alerts: blocked at code issue, failed, and a code accepted while the
row was not `code_issued` (should never happen; means a code leaked or was reused).

**Scheduled task `PollInstanceTransfers`** (same pattern as the provisioning tasks): every 5
minutes, reads the status of every `code_issued` / `accepted` row, moves state, and runs §5.

## 7. Data model

New `itx_instance_transfers` (`InstanceTransfer` / `MultiInstanceTransfer`):
`itx_cvp_customer_cloud_provision_id`, `itx_mgn_managed_node_id`, `itx_usr_user_id`,
`itx_instance_id`, `itx_state` (`requested|invited|code_issued|accepted|finishing|done|failed|canceled`),
`itx_token_sealed` (SecretBox; held only in `code_issued` and `accepted`), `itx_token_expiry`,
`itx_linode_status`, `itx_linode_checked_time`, `itx_check_result` (jsonb),
`itx_finish_step`, `itx_operator_todo`, `itx_deletion_withdrawn` (bool), `itx_error`,
`itx_requested_by` (`operator|customer`), the email timestamps, create/update/delete times. At most
one open row per provision.

Changes to existing models:
- `cvp_hosting_mode` gains `transferred` (allowed values and `validate_row`).
- `htr_state` gains `transferred` (allowed values and the save-time list); HostedTrialSignals
  skips it in `start_grace` and `clear_grace`.
- `svt_service_tenants` gains `svt_mgn_managed_node_id` (nullable): a node-linked tenant (§5a).
  ServiceTenantWatch and `JoineryServices::stop` branch on it for the backup acts and the banner.
- `CloudComputeProvider` / `LinodeComputeDriver` gain `transferEligibility()`, `createTransfer()`,
  `getTransferStatus()`, `cancelTransfer()`. The transfer calls are Linode-specific but sit behind
  the provider contract like the rest, so a provider without them answers "not supported" and the
  operator is pointed to `site_copy.md`.
- The store gains a system variant of the subscription cancel (§5 step 3).
- `IncidentReconciler` skips `mgn_enabled = false` nodes (§6).
- `NodeReverseDns` answers a `transferred` provision with "set reverse DNS in your own Cloud
  Manager" (no reconnect: there is no grant to reconnect), and the node page's Reverse DNS panel
  says the same.

## 8. Tests

Mocked-driver unit tests for:
- every §3 check (pass, blocker, warning), both interface generations, and the create call's
  verbatim reason;
- every state move in §4, including stale, cancel, failed, and Cancel refused after accept;
- §5 resumed from each step after a simulated crash;
- `subscription.cancelled` arriving after finishing leaves the row `transferred`;
- the mail row copy and the webhook counting against the tenant, not the provision;
- the node-linked backup acts (allowance pause/resume, suspend, prune) and the banner push;
- Stop managing leaves no enabled backup policy, uptime check or open incident, and starts the
  retention clock;
- the token never appearing in an email body, a job step, or a log line.

One live run on the dev plane against a throwaway Nanode and the owner's second Linode account
(A2), once the new token (A1) is in place.

## 9. Out of scope

- Container sites on our Docker hosts, and a customer whose own account is on another provider:
  `site_copy.md` to a new server, then the DNS switch.
- Moving Block Storage volumes, NodeBalancers or anything other than the instance.
- Accepting the transfer on the customer's behalf (§2).
- Backups through the broker for Managed sites (`services_phase2_platform.md` §3's per-run broker
  token, unbuilt). §5a keeps the fleet path; moving to the broker is its own spec.

## 10. Decisions (owner, 2026-10-02)

- **D1. Mail and backup storage carry on unchanged.** Moving the instance between Linode accounts
  must not interrupt the site's mail or backup setup. At §5 step 4 the site becomes a Services
  tenant for mail and backup storage (§5a), with `svt_paid_until` set to the end of the hosting
  period already paid for; after that the normal Services ladder applies.
- **D2. Our agent stays on the box.** The node carries on as a bring-your-own-cloud node: upgrades,
  backup orchestration and incidents continue. Your sites gains a "Stop managing this site" action
  the customer can use afterwards (§6).
- **D3. Both the operator and the customer can start a move.** Both end at the same queue row; the
  operator still presses Start (§4).
- **D4. The hosting subscription is cancelled immediately at completion, no refund.** D1's
  paid-until date covers mail and backups for the rest of the period.

## 11. Action items

- **A1.** Owner: a new operator Linode token with `linodes:read_write`, `account:read_write`,
  `firewall:read_only`, `volumes:read_only` (confirm at build whether the interfaces read needs
  `vpc:read_only`). Deferred by the owner (2026-10-02); the build proceeds on the mocked driver and
  the live run (§8) waits for it.
- **A2.** Done: the owner already has a second Linode account to receive the dev test transfer.

## 12. Review (public-html-91, 2026-10-02)

All 22 findings accepted. Load-bearing ones re-derived against the code before folding in (R1,
R3, R7, R8, R9, R10, R11, R16); the Linode API findings (R6) are from Linode's docs and are
confirmed by the live run.

| # | Finding | Where it landed |
|---|---|---|
| R1 | Managed backups never touch the broker; a shelf tenant row would govern nothing; the 10 GB cap and a `paused_for_shelf` pause would be orphaned | §5a backups, §7 |
| R2 | Mail conversion is a field-by-field row copy; the site cannot call Services itself | §5a mail |
| R3 | SMTP2GO webhook matches provisions first | §5a mail |
| R4 | No banner or email reaches a key-less tenant on lapse | §5a telling the customer |
| R5 | Disconnect on Connected sites would close their mail | §5a connected sites |
| R6 | Locks, Managed, VPC/VLAN detection corrected; label uniqueness; narrow promise; create-time reason | §2, §3 |
| R7 | `subscription.cancelled` would put a transferred row into grace | §5 step 3, §7 |
| R8 | Store cancel needs a session; remote store | §5 step 3, §7 |
| R9 | Unfinished mail leg stranded by the flip | §3 blocker |
| R10 | Flipping to `customer` makes the reconnect path live | §5 step 2, §7 |
| R11 | Reverse DNS was never settable for Managed sites | §5 step 9, §7; the pre-existing bug was fixed separately 2026-10-02 (`NodeReverseDns` 1.1) |
| R12 | A shut-down site has a deletion task out and node fields to reverse | §3, §5 step 5 |
| R13 | Relay shards and operator site copies are also `operator` mode | §3 blocker |
| R14 | Node operational state; copies as source or target | §3 |
| R15 | Open incidents | §3 warning |
| R16 | Stop managing must tear down more than the pairing key | §6 |
| R17 | Operator SSH key on the node | §3 blocker |
| R18 | Root password alone does not open SSH after keyless | §5 step 9 |
| R19 | Our Linode DNS zone keeps answering for them | §5 step 8 |
| R20 | Account-level `backups_enabled` auto-enrols | §3 |
| R21 | Two Managed sites: nothing collides | no change |
| R22 | Banner push is ours to send, non-fatal | §5 step 6 |

Re-read by `public-html-91` the same day: dispositions hold. **P1** (traced, re-derived): closed
incidents on a stopped node reopen every tick for sources reading frozen columns → the reconciler
skips disabled nodes (§6, §7). **P2** (traced, re-derived): the node script already renders the
`services` banner state (§5 step 6).

## 13. Build notes (2026-10-02)

Where the build differs from the text above, and why:

- **Prefix `itx`, not `itr`.** `itr` is ItemRelation's (items plugin); a shared prefix makes
  column-name filters ambiguous. The table is `itx_instance_transfers`.
- **The code is held through `accepted`, not cleared at `code_issued`.** The provider's status is
  asked by the code, so an accepted transfer could not otherwise be followed to `completed`. It is
  held in `code_issued` and `accepted` only, and erased in every other state.
- **The provider calls are a capability interface,** `CloudInstanceTransfers`, beside
  `CloudComputeProvider`: a driver without the capability does not implement it, and the caller
  says "not supported" (five test fakes implement `CloudComputeProvider` and are unaffected).
- **`PollInstanceTransfers` is a phase** of Advance customer provisioning, before the hosted
  phases, rather than a task of its own.
- **The operator never sees the code.** The queue shows its expiry; only the customer's own
  signed-in page shows the code (§2).
- **A canceled move of a shut-down site raises the deletion again** (`hosted.deletion_required`),
  since Start had withdrawn it.
- **Paid-until** for the Services rows: the local store's period end; else the trial end; for a
  site in grace or shut down, the failed-payment time (its services lapse into their own grace);
  else 30 days out with an operator to-do to confirm it.
- **Operator alerts** are one signal, `hosted.transfer_attention` (asked, blocked at code issue,
  refused by Linode, failed, finished, accepted unexpectedly); the deletion withdrawal is
  `hosted.deletion_withdrawn`.

