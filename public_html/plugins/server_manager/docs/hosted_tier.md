# Hosted tier — a site this operator runs, pays for, and can switch off

A hosting product's server is born on one of two cloud accounts, and the
difference between them is **whose**:

- **Your own cloud.** The buyer connects their own provider account, the
  provider bills them directly, and none of this operator's keys are involved.
- **Hosted.** The server is created on this operator's account with this
  operator's token, its outbound mail goes through this operator's mail
  provider, and its backups land in this fleet's backup storage. The buyer connects
  nothing, pastes no DNS record, and reads no password off a server.

Nobody chooses between them at checkout. The choice is the product's: the
`customer_cloud` fulfillment provider offers two references, and the one a
product stores becomes `cvp_hosting_mode` on every provision it creates. Which
of the two a deployment actually sells is a product decision; both modes are
always available to it.

## What the buyer sees

They describe the site on one page — **`/profile/server_manager/configure`**:
the domain (their own, or one this plane registers for them at cost, quoted
live), the site's name, the region when more than one is offered, and the
admin email — see the price as a sentence, and continue to payment. The cart
carries one line for hosting and, when a name is registered, one for the
domain year; nothing about the site is asked there. Until they pay, the setup
is a draft on their sites page (Continue, Edit, Delete) that holds nothing and
expires after `server_manager_draft_days`. Payment activates it (see
[Server Manager § The buyer configures first](overview.md#the-buyer-configures-first-then-pays-payment-activates)).

A few minutes after paying their site is live at that domain, with email that
sends and backups that run. The welcome email carries the address — and, for a
domain they brought themselves, the one A record to add — and a link to
**their sites page** (`/profile/server_manager`), where
the admin password their account was born with is shown **once** — reading it
erases it, and the site asks them to choose their own at first sign-in. The
password is never in an email: email is a copy that persists in somebody else's
system.

From then on the site's own admin pages carry a banner (`HostedPlanNotice`)
saying what the hosting arrangement is, when the next date falls, and where an
allowance is running out.

## Doctrine

Four rules, none of them new here:

1. **A customer's box is the customer's trust domain.** They are permission 10
   there, so a credential shared between customers never reaches a box. What
   reaches one is a credential cut to that customer's own slice, revocable on
   its own.
2. **Enforcement lives where this operator controls it** — at the provider, or
   on the management node. A setting written on the box is advisory: the
   customer can edit it.
3. **Never a key of this operator's on a machine this operator creates.** The
   cloud token, the mail provider's master key and the storage master key stay
   here. The box gets one SMTP user inside its own subaccount, and nothing else
   standing — the backup credential arrives per run and expires.
   *The converse is worth stating too:* the `hosted_mail_settings` primitive
   that carries that SMTP user can reach **any** node this management node
   manages, not only hosted ones, so this operator can point any managed site's
   outbound mail — and therefore its password-reset email — at a server of its
   choosing. Accepted deliberately: enrolling a node already grants the power to
   replace its code. See [Settings](../../../docs/settings.md#settings-a-management-node-writes).
4. **The platform never deletes a cloud instance.** Shutting one down is the
   strongest automatic action there is. Deletion is a person at the provider.

## The legs

Provisioning runs as phases of **Advance customer provisioning**, in order. The
two hosted phases run last, so a slow provider there never sits in front of a
customer waiting for their machine.

### Compute

`ProvisionCustomerCloud` resolves its driver from the sealed operator token
(**Server Manager → Provisioning → Hosted tier**) instead of a buyer grant, and
skips the Connect wait: a hosted provision is created at `ready`. Everything
after that is the same pipeline every other cloud install takes. The instance is
born with a root password sealed on its row, used for the one bootstrap session
and retired once the agent joins.

The bootstrap also carries the buyer's address (`--admin-email`) and their first
admin password. The password travels on the session's **stdin** — never in the
job's stored steps, which are readable here, and never in its output, which is
logged.

### Domain and DNS

Unchanged: registered in this operator's account with the buyer as registrant,
apex and www published by the management node. A buyer who already owns their
domain publishes their own records; the mail leg says so on the provision rather
than pretending otherwise.

### Outbound mail

`ProvisionHostedMail` builds it, one step per tick, each stamped on
`cvp_mail_state` so a crash resumes rather than repeats:

0. **A test purchase names what it creates.** A site bought with a test-mode
   payment (`ord_test_mode` on the order) names everything the pipeline
   creates for it outside the platform with `test_` in front — the cloud
   instance's label, the node's name on the fleet board, the mail subaccount's
   label and its SMTP username (`CustomerCloudProvision::external_name_prefix()`)
   — so a rehearsal's leftovers are told from a customer's in every provider's
   console and are never mistaken for something to keep. The registered
   domain keeps its own name; a sandbox registration is not real.
1. **A subaccount per customer.** The unit of isolation: its own SMTP users,
   its own sender domains, its own usage counter and its own monthly cap. Other
   customers' logs and recipients are invisible to it.
2. **A sending domain**, `mail.<domain>` rather than the apex — so a customer
   who later moves to their own provider is not fighting a record this operator
   published on their apex.
3. **Its DNS records**, published where this operator holds the zone. Where it
   does not, the records are kept on the provision and the leg says who has to
   publish them; it does not stall waiting for something nobody here can do.
4. **One SMTP user**, inside that subaccount. On a rehearsal plane
   (`server_manager_smtp2go_sandbox_users` on, from the Provisioning Setup
   hosted card) it is minted in the provider's sandbox status: mail through it
   is accepted and counted but never delivered, so a site built to prove the
   pipeline can email nobody. Its username and password are the
   only credential that reaches the box, handed over by the
   `hosted_mail_settings` primitive — which carries nine *values* whose setting
   names live in a script on the node, so this management node cannot name a
   setting on any site it manages. The password is not stored here: a
   replacement is one call away, which is a better property than a copy in this
   database. If the site cannot take it (an agent too old for the primitive),
   nothing is minted at all, so a stale credential is never left behind.

The send cap is the provider's own: it counts month-to-date sends against the
subaccount's limit and refuses past it. Nothing on this platform is in the send
path, so nothing in it can be bypassed by editing a setting on the customer's
own box.

**Delivery events arrive at `/ajax/smtp2go_webhook`**, and that webhook is
created **by hand**, once, in the SMTP2GO console against the master account —
it is account-wide rather than per-subaccount, so it is operator setup rather
than a step of every customer's provisioning. Set the deployment's webhook
secret as the basic-auth password on the URL. The endpoint requires both that
secret and a source address the provider publishes; it refuses anything else,
and it refuses everything if no secret is configured, because an open counter
looks like evidence and is not. What those events can do is bounded on purpose:
they move the banner's *sent this month* figure and nothing else. Bounces and
complaints are the provider's to act on, inside the customer's own subaccount,
and it does — nothing on this platform keeps a second count or draws a second
threshold from unsigned events. And nothing arriving here moves the send cap,
which is the provider's own count.

If a site's mail setup cannot be finished — its agent predates the
`hosted_mail_settings` primitive, or the provider's answer carried no DNS records
this platform could read — the leg **stops** and raises an operator alert rather
than retrying for ever. It is deliberately terminal: each retry would mint
another SMTP user in the customer's subaccount, and the causes are configuration
rather than weather. Clearing `cvp_mail_state` on the provision row restarts it.

### Backups

**Nothing is seeded on the box.** The hosted tier's backup *is* the fleet
manager profile, which already backs up every managed node. Both profiles seal
to the node's own recovery key and a manager run arriving with key material is
refused, so the fleet backing up a customer's box to this operator's bucket is
unreadable by this operator — and no single key opens the fleet.

Two consequences follow, and both are fleet-wide rather than hosted-only:

- A node's run is handed a credential **minted for that run** and pinned to its
  own prefix where the storage provider allows it. See
  [Backups § The credential a run is handed](../../../docs/backups.md).
- **Backups stay amber until the customer creates their recovery key.** A
  machine with no verified key of its own refuses to back up at all, by design.
  The fleet scheduler reports such a node as *awaiting its recovery key* and
  does not dispatch at it; the wizard's Backups step is the one ceremony.

## The billing clock, and what happens when a payment fails

`HostedTrialWatch` runs the commercial half. One `htr_hosted_trials` row per
provision holds the state — `trial`, `subscribed`, `grace`, `shutdown` — and
nothing else: there are no meter columns, because every figure already lives
with the party that measures it (the mail provider counts sends, the retention
pass sizes backup storage, the node reports its own disk).

A new site opens **subscribed**: hosting is billed from checkout. The `trial`
state exists for a deployment that configures a free period (**Hosted tier →
Free trial length**, matching the trial on the product's subscription version);
with the length at zero, no row ever passes through it.

**The clock is set by the store's signals, not by the watch.**
`HostedTrialSignals` listens for `subscription.payment_failed`,
`subscription.payment_recovered` and `subscription.cancelled`, and moves dates
on the row. It never acts: powering off a customer's machine from inside a
webhook is how a retry or a duplicate delivery becomes an outage.

The watch then acts on those dates, on its own schedule:

| When | What happens |
|---|---|
| A charge fails | The grace period starts and the site's banner says so. It does not restart on a second failed retry — a card that never works would otherwise buy unlimited hosting. |
| The grace ends | The instance is **shut down** by API and `hosted.deletion_required` is raised, asking a person to delete it at the provider. It keeps billing until they do; that is the price of rule 4. The node's fleet backups and uptime checks are switched off in the same step, so a machine somebody turned off on purpose does not spend the next month failing runs and tripping down-alerts over the one line that is actually actionable. |
| Backup storage date passes | The customer's whole prefix is pruned. Between the shutdown and this, a returning customer is recoverable — a fresh install plus restore-over-agent, with **their** recovery key. |
| A payment arrives | Everything pending is cancelled. After a shutdown, bringing the site back is a deliberate manual step: the machine may have been deleted by then, and a signal cannot know that. |

Both dates are counted from the **failed payment**, not from the shutdown, so
"your backups are kept ninety days" is something a customer can check against
the day they stopped paying.

## Allowances, and why exceeding one is an off-ramp

There is one tier. A customer who outgrows an allowance is pointed at their own
account for that service, never sold a bigger plan — each banner line names the
one action for its service, and only once that allowance is actually near.

| Allowance | Measured by | At 100% |
|---|---|---|
| Sends per month | the mail provider's own count | the provider refuses until the month rolls |
| Backup storage | the retention pass's listing (`mgn_backup_shelf_bytes`) | this node's fleet backups are paused, the site's banner says so, and nothing is deleted. They resume on their own once backup storage comes back under the allowance. |
| Disk | the node's own status check | nothing automatic — a full disk stops the site on its own |
| Outbound transfer | the account-wide pool | one operator alert per billing period. There is no per-customer figure, because there is no per-customer bill. |

Sending health is the provider's. It enforces the monthly limit on the
customer's subaccount and applies its own bounce and complaint controls there;
this platform keeps no threshold of its own and removes nothing on the strength
of a webhook.

## Moving a site to its customer's own Linode account

A hosted site runs on its own instance in this operator's Linode account, and it
can move — running, same disks, same addresses — into the customer's own Linode
account through Linode's **Service Transfer**. Nothing is copied or rebuilt, DNS
does not change, and the recovery key is never involved. Linode bills the
customer from then on; their hosting subscription is cancelled, and their mail
and backups carry on through us as a Services subscription.

**Who can move.** A hosted site a customer bought, on its own instance, finished
setting up. A site in a container on a shared Docker host has no instance of its
own; it moves with a [site copy](overview.md#site-copy) instead.

**The check** (`InstanceTransfers::check`) runs when the node page's *Move to
customer's Linode* panel opens, before every code is issued, and on Re-check.
Each item passes, warns, or blocks, and every blocker says what to do:

- *On Linode* (the operator token, through `CloudInstanceTransfers`): the
  instance is on our account; no resource lock, Cloud Firewall, Block Storage
  volume, NodeBalancer, shared or reserved IPv4, IPv6 range, or VLAN/VPC
  interface; Linode Managed off; no pending transfer of it already. Linode
  Backups on the instance, or the account auto-enrolling every instance, is a
  warning: they move and the customer pays for them. A check the token cannot
  make is a blocker that says so.
- *Here*: a sold hosted site (not a relay shard, a bare machine or an operator
  copy); the node working, with no site copy in progress and no job running;
  the install password retired and no SSH key of ours recorded on the node — a
  working credential of ours must not ride along; the mail leg finished. A
  grace period, open incidents, or a dormant copy naming this node are warnings.
  A site shut down for non-payment is a warning: Start withdraws its deletion.

Linode enforces every restriction again when the code is created and when it is
accepted; its own refusal is shown verbatim.

**The flow.** The operator presses *Start* on the node page (or the customer
asks from their sites page with *Move to my own Linode account*, which puts a
row on the queue for the operator to start). The customer is emailed how to get
ready — a Linode account with a card on it — and presses *I'm ready — get my
transfer code* on their sites page. The plane creates the transfer and the page
shows the code behind a button, its expiry, and the three Cloud Manager steps.
The customer accepts in **Cloud Manager → Account → Service Transfers**; we
never accept on their behalf, because that would need a grant on their account
we do not otherwise hold.

| Linode says | The row |
|---|---|
| pending | waits; Cancel withdraws the code at Linode |
| accepted | can no longer be canceled; Linode moves the instance within about three hours |
| completed | the finish runs |
| stale (24 hours passed) | back to waiting, and the customer is emailed to get a new code |
| failed | the operator is alerted and the customer told we are looking into it; the likeliest cause is a Linode on their account with the same label, `{slug}-{provision id}` |

**The code is a bearer secret** for the whole machine. It is sealed on the row
(`itx_token_sealed`) only while Linode's status is asked by it, shown only on
the customer's own signed-in page, and never put in an email, a job, a log line
or an error message.

**A shut-down site.** Its deletion was asked of a person; deleting it now would
fail the transfer. *Start* withdraws the deletion (`hosted.deletion_withdrawn`,
and a note on the node). A move canceled afterwards raises the deletion again.
The site moves powered off, and the last email tells the customer to boot it.

**The finish** (`InstanceTransferFinish`) runs one recorded step at a time, so a
crash resumes where it stopped:

1. our token gets a 404 for the instance;
2. the provision's hosting mode becomes `transferred` — neither ours to host nor
   a bring-your-own-cloud row with a grant to reconnect;
3. the hosting row becomes `transferred`, then the subscription is cancelled
   immediately, with no refund and no store email (`OrderItem::cancel_subscription_by_system`).
   The store's own cancel signal then finds a row it leaves alone. A
   subscription sold by a store on another site is a to-do on the queue row;
4. mail and backups become **node-linked Services rows**, paid to the day the
   hosting was paid to: the mail row takes over the subaccount, SMTP user, sender
   domain and records, and the provision lets go of them; backup storage stays
   the node's fleet backups (see [Joinery-run services](overview.md#joinery-run-services-for-self-hosted-sites));
5. a site that had been shut down gets its backups and uptime checks back;
6. the site's banner switches to the services standing;
7. the customer is emailed what is theirs now — root access (reset it in Cloud
   Manager, use the Lish console: the server takes no SSH password and holds no
   key of ours), reverse DNS in their own Cloud Manager, the Services date, and
   the DNS records we still hold for them.

Our agent stays. The node carries on as a managed node until the customer
presses **Stop managing this site** on their sites page: the agent is
forgotten, fleet backups and uptime checks stop, the node is disabled (the
incident reconciler does not watch a disabled node), its open incidents close,
and its backup storage row is released, which starts the 90-day retention
clock. Mail carries on until its paid-through date.

**The queue** — **Server Manager → Server Transfers** — lists every transfer:
state, code expiry, what Linode last said and when, the last email, the last
check, and any to-do, with Re-check, Start, Issue code now, Resend email,
Cancel and To-do done.

**The operator token** needs `linodes:read_write`, `account:read_write` (to
create and cancel a transfer), `firewall:read_only` and `volumes:read_only` (the
check).

## Setting it up

**Server Manager → Provisioning → Hosted tier** takes everything: the cloud
token, the mail provider's master key and webhook secret, the allowances, the
grace and backup storage periods, the sites-page address pushed to each site's banner,
and the two referral links the off-ramps use. Until the token and the mail key
are both present, a hosted order cannot be fulfilled — a hosted site without
mail is a site whose owner cannot reset their own password.

Then, on the product: pick *Customer cloud server* under Purchase grants and
choose **Create the server on the operator's account (hosted)**. Give it one
monthly subscription version; nothing is attached under *Info to collect
before purchase* — the provider contributes the one requirement the line
carries, and the buyer configures the site before paying. A trial is optional:
a version that carries one needs the same length set under Hosted tier, so the
banner counts down to the right day; with none, a new site is subscribed from
checkout. `server_manager_hosted_regions` lists the regions the configure page
offers; empty means the one customer-cloud region and no choice shown.
