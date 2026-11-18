# Hosted tier — a site this operator runs, pays for, and can switch off

A hosting product's server is born on one of two cloud accounts, and the
difference between them is **whose**:

- **Your own cloud.** The buyer connects their own provider account, the
  provider bills them directly, and none of this operator's keys are involved.
- **Hosted.** The server is created on this operator's account with this
  operator's token, its outbound mail goes through this operator's mail
  provider, and its backups land in this fleet's backup storage. The buyer connects
  nothing, pastes no DNS record, and reads no password off a server.

A hosted site lands in one of two places, and this is the buyer's choice of
**plan**, made on the configure page:

- **Managed** — an instance of its own on this operator's account
  (fulfillment reference 1). The site is installed straight onto the server
  (bare metal, one agent), with no mail server of its own
  (`cvp_no_mail_server`, `install.sh --no-mail-server`): it receives through
  the relay fleet, which grants a place to a sold site on our account that has
  no mail server (`FleetService::site_plan_grants_slot`).
- **Starter** — a container on one of this operator's shared starter hosts
  (fulfillment reference 2; `cvp_landing` `container`). Its row never holds
  an instance id, and it lands only on a host whose tier is `starter`.

The configure page offers a third plan beside them that is not hosted:

- **On your own Linode** — free (fulfillment reference 0, `cvp_plan`
  `own_linode`). The buyer pays first, then connects their Linode account:
  a paid site with no grant that can carry the build
  (`CustomerCloudAccount::live_for_build`, an hour left) waits at
  `pending_connect`, and Your Sites offers Connect. The server (1 GB, us-east,
  the customer-cloud defaults) is created in their account with their grant and
  built bare metal as Managed is, but with its own mail server: it is not
  ours to give a relay place. The grant is asked for `linodes:read_write`
  only, whenever the buyer has an own-Linode site to build, and is let go once
  per site after the build, when none of the buyer's sites is still being
  built or retried, when the server's agents have been admitted (admitting
  them asks Linode, with this grant, whether the server runs at the address
  that asked; `ProvisionOwnLinode::joins_owed`), and only when it is that
  build-only grant: the account
  then reads `released` and the pages ask to connect again
  (`ProvisionOwnLinode`, `cvp_grant_released_time`). The server's size is
  pinned on the draft (`g6-nanode-1`), so it is what the buyer was told. An account may hold
  `server_manager_own_linode_sites_per_buyer` of them (default 3).
  The email and backups bundle (the `joinery_services` product) can ride in the
  same checkout: the draft's install line brings a bundle line naming the draft
  (`cvp:<id>`), refused without its install line in the cart, recorded on the
  provision at payment (`cvp_services_order_item_id`). Such a site's mail is
  built by the hosted mail leg, then the site is carried into the end state a
  Managed site has after moving to its owner's account: node-linked mail and
  backup Services rows following the bundle's subscription (paid to its period
  end while it pays), and the node's space on this fleet's backup storage. The
  mail row starts unverified and the services reconcile verifies it; a mail
  leg that failed still gets its backups row, and is reported on every pass.
  The install line is refused at checkout while the bundle it was set up with
  is not in the cart. Without the bundle, nothing of ours is
  set up: no mail and no backup space.

Which plan a draft is for is recorded on it (`cvp_plan`, read by
`ManagedSiteDraft::plan_of()`; a row from before it is read from its landing),
and the plan, not only the landing, must match the paid product before the
charge and at activation.

Whose account a server is born on is the product's decision, not the buyer's:
the `customer_cloud` fulfillment provider offers three references (the buyer's
own account, 0, besides the two above), and the one a product stores becomes
`cvp_hosting_mode` (and, for Starter, the landing) on every provision it
creates. Which a deployment sells is a product decision.

A hosted site stays connected to its management node: its email settings,
backups and updates run through that connection, so the site's Admin →
Management Node page offers no Disconnect and the action is refused while its
`hosted_plan_state` is a hosted billing state (`HostedPlanNotice::hostedHere`).
A self-hosted site, including one renting our email and backups (`services`),
may disconnect.

## What the buyer sees

They describe the site on one page — **`/profile/server_manager/configure`**:
the plan when more than one is on sale (Starter is offered only while its
product is active and a starter host has room), the domain (their own, or one
this plane registers for them at cost, quoted live), the region when more
than one is offered, and the admin email — see the price as a sentence, and
continue to payment. The payment button posts at the plan's product; a cart
line whose draft is of the other plan is refused before the charge, and so is
a Starter line while no starter host has room. The cart
carries one line for hosting and, when a name is registered, one for the
domain year; nothing about the site is asked there. Until they pay, the setup
is a draft on their sites page (Continue, Edit, Delete) that holds nothing and
expires after `server_manager_draft_days`. Payment activates it (see
[Server Manager § The buyer configures first](overview.md#the-buyer-configures-first-then-pays-payment-activates)).

**One buyer, a few sites.** One account may hold `server_manager_hosted_sites_per_buyer` hosted sites at once (default 3), and so may one card across every account. A site counts from payment until it is deleted, held and failed ones included; a server on the buyer's own cloud account is not ours and does not count, and nor is a machine an admin made in ours (a starter host, a relay shard, an admin's own install): only a site that was bought counts.

- **At checkout**, before any money moves, an account already at the limit is refused with *This account already has 3 hosted sites, the most one account can have. Contact us if you need more.*
- **At payment** the card is known: `fulfill()` reads its Stripe fingerprint (`StripeHelper::card_fingerprint_for_order`) and counts again, by account and by card. Two lines in one cart each pass checkout alone, which is why the account is counted again here. A PayPal payment carries no card and is judged by the account alone.
- **An order past either limit is held**, at status `held`: nothing is built and no domain is bought. The operator is alerted, and the Server Manager board shows the row with **Approve**, **Refuse** and a link to its order.
  - **Approve** goes on as an activation under the limit would.
  - **Refuse** takes the row off the board. It moves no money: refund the payment and cancel the subscription on the order page first.
- A customer who needs more sites goes on `server_manager_hosted_sites_limit_exempt` (user ids).
- **A suspended site or a disputed payment holds the next order too.** While any site of the account, or any site paid with the same card, is suspended or has a disputed payment, a paid hosted order is held the same way, with the reason on its row (`HostedSuspensions::buyer_flag`). A site removed after a dispute still counts.

A few minutes after paying their site is live at that domain, with email that
sends and backups that run. Their sites page says **Ready**, and offers the
password, only once the site has been told what it comes with
(`SmHosting::site_told`): a hosted site's banner has landed, or a bundle
site's node has taken its services notice, which is sent as soon as the node
works and names email and backups as being set up. Until then, for a minute or
two, the page reads *Finishing setup*; a site not told within fifteen minutes
of its install is shown Ready anyway. The site's own setup pages then say what
is included and still on its way, and never ask for a mail provider or backup
storage the buyer already paid for (`HostedPlanNotice::includedMailPending`). The welcome email carries the address — and, for a
domain they brought themselves, word that their DNS records are listed on the
sites page — and a link to
**their sites page** (`/profile/server_manager`). For a domain they brought
themselves, that page lists every record still to add in one step: the address
record, the email records, the site's own records (its Joinery Direct endpoint
and signing key among them), DMARC while there is none, and the optional IPv6
record. The list is held until it is whole, so the buyer makes one trip to
their registrar: **Buyer DNS** (`ProvisionBuyerDns`, a phase of the umbrella)
waits for the sending and inbound records, asks the built site what its domain
needs (the `managed_domain_prepare` primitive), keeps the answer in
`cvp_site_dns_records` and stamps `cvp_site_dns_time`; until the stamp the
page says the records are being gathered and lists none. A site that has not
answered fifteen minutes after its install (`SmHosting::DNS_WAIT_SECONDS`) has
its list shown with what is known, the gap is reported on every pass, and the
site is asked until it answers. Where the plane set up sending, the site's own
signing key, SPF and DMARC give way to the provider's. Where the domain's DNS
host has a driver the page offers to add every record at once
(the DNS publish box, docs/dns_management.md). There
the admin password their account was born with is shown **once** — reading it
erases it, and the site asks them to choose their own at first sign-in. The
password is never in an email: email is a copy that persists in somebody else's
system.

From then on the site's own admin pages carry a banner (`HostedPlanNotice`)
saying what the hosting arrangement is, when the next date falls, and where an
allowance is running out. While the site holds no proven backup recovery key,
the banner is urgent and says the backups are off until the key exists, with a
link to the Backups page (`HostedPlanNotice::backupKeyMissing()`, for the trial,
subscribed and grace states).

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

`ProvisionCustomerCloud` resolves its driver from the credential of the cloud
account of ours the provision names — the default account
(**Server Manager → Provisioning → Hosting**), given to it when first saved —
instead of a buyer grant, and skips the Connect wait: a hosted provision is
created at `ready`. Everything
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
   label (`test-` there: a subaccount name is letters and digits joined by
   hyphens, the one shape known to pass the provider's check) and its SMTP username
   (`CustomerCloudProvision::external_name_prefix()`)
   — so a rehearsal's leftovers are told from a customer's in every provider's
   console and are never mistaken for something to keep. The registered
   domain keeps its own name; a sandbox registration is not real.
1. **A subaccount per customer.** The unit of isolation: its own SMTP users,
   its own sender domains, its own usage counter and its own monthly cap. Other
   customers' logs and recipients are invisible to it.
2. **A sending domain**: the site's own domain. Hosted mail is for the
   owner's everyday email from their own address (`hello@<domain>`, which the
   setup wizard suggests), on a small allowance; bulk mail, or automated mail on
   a separate `mail.<domain>` identity, is for the customer's own provider
   account. The site enforces this: on hosted mail the email_lists plugin
   refuses to queue a campaign, and the admin banner's email row prompts the
   owner to connect their own provider from 95% of the allowance (see the
   plugin's overview). The site's domain is also routable, which the provider requires of
   an envelope sender: it always has the address record the site answers on.
   The provider's records are two uniquely named CNAMEs (`s<id>._domainkey`,
   `em<id>`) that sit beside the domain's own SPF and DKIM.
3. **Its DNS records**, published where this operator holds the zone. Where it
   does not, the records are kept on the provision and the leg says who has to
   publish them; it does not stall waiting for something nobody here can do.
4. **One SMTP user**, inside that subaccount. On a rehearsal plane
   (`server_manager_smtp2go_sandbox_users` on, from the Provisioning page's
   Hosted tier tab) it is minted in the provider's sandbox status: mail through it
   is accepted and counted but never delivered, so a site built to prove the
   pipeline can email nobody. Its username and password are the
   only credential that reaches the box, handed over by the
   `hosted_mail_settings` primitive — which carries *values* whose setting
   names live in a script on the node, so this management node cannot name a
   setting on any site it manages. The same push carries the DKIM key name the
   provider signs with (SMTP2GO's `s<id>`, from the records it returned) to an
   agent of 1.120.0 or later; the site keeps it as `smtp_dkim_key_name`, and its
   mail check looks for the signing key under it. The password is not stored here: a
   replacement is one call away, which is a better property than a copy in this
   database. If the site cannot take it (an agent too old for the primitive),
   nothing is minted at all, so a stale credential is never left behind.
   A site that has no From address yet is given `hello@<domain>`, replying to
   its owner account (`HostedMailSettingsMap::defaultFrom`), so password resets
   work before the owner reaches the setup wizard's mail step. An address the
   owner already chose is never overwritten.

   **A test site mails only us.** Where the Hosted tier tab names an address
   for test sites' mail (`server_manager_test_site_mail_trap`), a site whose
   server is in a test cloud account of ours is sent it with its credential, as
   the primitive's `test_recipient`. The site writes it first: email test mode
   on, every message to that address with the people it was meant for in the
   subject (`HostedMailSettingsMap::testTrap`). The mail travels the real path,
   subaccount and send count included, and lands in a mailbox we read, such as
   `joineryemailtests@dev.getjoinery.com` on dev. It is sent only to an agent
   that takes it (`JobCommandBuilder::HOSTED_MAIL_TRAP_AGENT`); an older one is
   refused rather than given a credential that would mail the people a
   rehearsal names, and a site that took the credential without printing
   `test_trap=on` fails the leg. Every other site is sent no trap, and an empty
   one writes nothing, so an owner's own test mode is left alone.

The send cap is the provider's own: it counts month-to-date sends against the
subaccount's limit and refuses past it. Nothing on this platform is in the send
path, so nothing in it can be bypassed by editing a setting on the customer's
own box.

**Delivery events arrive at `/ajax/smtp2go_webhook`**, from a webhook on each
customer's own subaccount: a webhook on the master account does not fire for a
subaccount's mail. Provisioning adds it right after creating the subaccount, for
a Managed site and a services tenant alike (`Smtp2GoLeg::ensureWebhook`), and
leaves a subaccount already pointing here alone. It sends JSON, for the *Email
processed* event only (a message counts once, when the provider accepts it, as
its own monthly count does), with Basic authorization whose password is the
deployment's webhook secret; the username is not checked. The endpoint requires both that
secret and a known sending address: SMTP2GO's own, resolved from `webhooks.smtp2go.com`, or
one of Svix's, the delivery service SMTP2GO sends its webhooks through
(`Smtp2GoWebhookSources`, from Svix's published US and EU list). It refuses anything else,
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
  does not dispatch at it; the wizard's Backups step is the one ceremony. On a
  managed site that step comes right after the secure connection rather than
  near the end, the admin banner above says backups are off until it is done, and
  `plane:backups_stopped` opens on the management node once the node is two days
  old without a key.

## The billing clock, and what happens when a payment fails

`HostedTrialWatch` runs the commercial half, for sold sites only
(`CustomerCloudProvision::is_sold()`: an order or a buyer's checkout made it,
and it is a whole site). A relay shard or a site copy on the operator's account
is never billed, banner-pushed or shut down. One `htr_hosted_trials` row per
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
| The grace ends, for a Starter site | Its host's agent stops the site's container and keeps it stopped (`hold_container`); the host and its other sites are not touched, and nothing is deleted. The site is recorded as shut down only when the host's answer says the container is stopped and held (the job's `done`, not its status): until then it stays in grace, its backups and uptime checks stay on, and its backup storage is not pruned. A hold that failed or did not take is asked for again once an hour, and said in the task's result each run. Once held, backups and uptime checks are switched off and `hosted.container_held` is raised: the container still takes a place on its host until a person removes the site. |
| Backup storage date passes | The customer's whole prefix is pruned. Between the shutdown and this, a returning customer is recoverable — a fresh install plus restore-over-agent, with **their** recovery key. A Starter site is then marked ready to remove and `hosted.removal_ready` is raised; a person removes it with Permanently Delete Site on its node page, and its host does so only for a container it has held stopped for 55 days. Nothing removes it on its own. A late payment clears the mark. |
| A payment arrives | Everything pending is cancelled. After a shutdown, bringing the site back is a deliberate manual step: the machine may have been deleted by then, and a signal cannot know that. For a Starter site that step is **Bring back** on its node page (see O3 below). |

Both dates are counted from the **failed payment**, not from the shutdown, so
"your backups are kept ninety days" is something a customer can check against
the day they stopped paying.

## Allowances, and why exceeding one is an off-ramp

There is one tier. A customer who outgrows an allowance is pointed at their own
account for that service, never sold a bigger plan — each banner line names the
one action for its service, and only once that allowance is actually near.

| Allowance | Measured by | At 100% |
|---|---|---|
| Sends per month | the mail provider's own count | the banner says so. The provider refuses at its cap, the smallest subaccount size it offers that covers the allowance (the default allowance, 2,000, is itself the smallest), until its billing cycle rolls |
| Backup storage | the retention pass's listing (`mgn_backup_shelf_bytes`) | this node's fleet backups are paused, the site's banner says so, and nothing is deleted. They resume on their own once backup storage comes back under the allowance. |
| Disk | the node's own status check; on a starter host, the site's own disk allowance (`server_manager_starter_disk`, 5 GB), which the site reads itself (`DiskAllowance`) and its agent reports against | nothing automatic — a full disk stops the site on its own. A Starter site stops taking uploads and stored mail at its allowance, and pulled mail waits on the relay. The way out is a site of its own: a Starter site that outgrows its allowance is offered Managed (O1 below) |
| Outbound transfer | the account-wide pool | one operator alert per billing period. There is no per-customer figure, because there is no per-customer bill. |

Sending health is the provider's. It enforces the monthly limit on the
customer's subaccount and applies its own bounce and complaint controls there;
this platform keeps no threshold of its own and removes nothing on the strength
of a webhook.

**A Starter host** holds seven sites (`ManagedHost::STARTER_PLACES`), each at 448 MB of memory (`server_manager_starter_memory`), one CPU and a 5 GB disk allowance. Every plan includes 500 GB of backup storage (`server_manager_hosted_shelf_allowance_gb`).

## Abuse: terms, reports, Suspend

**The terms.** getjoinery's Terms of Service § 8 (*Acceptable use*) say what a
hosted site may not be used for, that an abusive site may be suspended at once
without notice, the three-site limit, what a disputed payment does, and the
abuse address, abuse@getjoinery.com. Reports sent there reach the operator.

**Suspend** is on a hosted site's node page, in the **Suspension** box (level
10). It asks why, and the customer is emailed that reason. Then:

| Site | What Suspend does |
|---|---|
| Starter | Its host's agent stops the container and keeps it stopped (`hold_container`). The host and its other sites are not touched. |
| Managed | The instance is powered off through its cloud account. |

On either kind it also closes the site's SMTP2GO subaccount, and switches off the
node's backups and uptime checks so a site stopped on purpose raises no alerts.
Visitors reach nothing; there is no suspended page. Nothing is deleted: the
data, the backups and the record stay. The stop comes first. A Managed
power-off the provider refuses records nothing, and the page says why. A
Starter stop is a request to the host: the record is kept from the moment it is
asked, and the box says **Stop asked, not confirmed yet** until the host's
answer says the container is stopped and held (its `done`, not the job's
status). A stop that failed or did not take reads **The host has not stopped
it**, and the billing watch asks again once an hour. The record is saved
straight after the stop, so a later failure never leaves a stopped site with
nothing on record. Each later step's outcome is listed on it. One suspend or
unsuspend runs at a time per site.

Only Unsuspend lifts a suspension. The host page offers no **Start** for a
suspended site's container, and refuses Start and **Replace** for one, and a
suspended site cannot be copied.

The record (`hsu_hosted_suspensions`, one row per suspension) names who
suspended the site, when and why. The customer's sites page shows the reason.
While it is open, the billing watch sends the site no banner and does not pause
its backups for storage, but its grace still runs out as any other site's.

**Unsuspend** reverses each step: the container is started or the instance
powered on, the subaccount reopened, and the node's own backup policy and uptime
check put back as they were. The customer is emailed. A site whose hosting
ended unpaid is already stopped: Suspend does not stop it again, and Unsuspend
closes the record and reopens the mail but does not start it, and leaves its
backups and uptime checks off. Bringing it back is the manual step above.

**A disputed payment.** Stripe's `charge.dispute.created` raises `order.disputed`.
It names the order, the amount and the reason, and alerts the operator. A first
charge is matched to its order; a renewal is matched through its invoice to
the subscription line. Each hosted site the order paid for gets
`cvp_disputed_time`, which holds the buyer's next order (above). The site
itself keeps running: answering the dispute, and suspending the site if it is
abuse, are a person's decisions. `charge.dispute.closed` raises
`order.dispute_closed` with Stripe's status. Each site keeps its disputes by id
(`cvp_disputes`), and the mark stays while any of them is not won: a lost one
keeps it, and winning one of two clears nothing. Both events have to be switched on for the webhook endpoint
in each Stripe account (test and live); nothing registers them on its own.

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

- *On Linode* (the credential of the account the site is in, through `CloudInstanceTransfers`): the
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

**The account's credential** needs `linodes:read_write`, `account:read_write`
(the transfer-allowance alert reads the default account's pool; a handover
creates and cancels a transfer), `firewall:read_only` and `volumes:read_only`
(the check). A site copy's switch-over by IP swap also needs `ips:read_write`.
Adding or checking an account on the Hosting tab asks Linode which scopes the
credential holds (the `X-OAuth-Scopes` header on a read of the token's own
profile) and records them on the entry (`oca_scopes`). The tab, and the Hosted
tier tab for the default account, then name each missing scope and what it is
needed for. The list is `OperatorCloudAccount::SCOPES`. A default account
missing `linodes:read_write` cannot fulfil a hosted order; one missing another
scope is kept, and only what needs that scope is refused.

## Operator steps

These are done by a person, from the dashboard. Each names what the platform
does on its own and what it leaves to you.

**One rule they share: a site copy is not a sold site.** A copy's own
provision is an admin's (install mode `copy`), never billed, seeded or held.
At a switch-over the sold provision stays with the site and only its machine
columns move (`SiteCopySwap`): its order line, hosting row, relay slot, fleet
key and mail subaccount go on describing the site on its new machine, and
removing the old machine afterwards removes none of them.

**O1. Starter to Managed by hand** (the answer to a Starter site that has
outgrown its disk).

1. Agree it with the buyer: Managed is billed at its own price.
2. On the site's node page, **Copies**, copy it to a new server in our account
   (Server Manager → Provisioning → Hosting names the account), the plan the
   Managed product sells.
3. When the copy is ready, **Switch over**. The site's row now holds the new
   server; its sold provision names it.
4. At Stripe, change the subscription item's price to the Managed price. The
   `customer.subscription.updated` webhook moves the order line to the
   Managed product.
5. Remove the old container: **Permanently Delete Site** on the old
   container's row, once the domain reaches the new server. Its place on the
   starter host comes back.
6. Mail: a copy onto a new server is installed bare metal, and a bare-metal
   site receives its own mail rather than through a relay slot
   (`FleetService::site_plan_grants_slot`). Until that is settled, check the
   site's Mail setup after the switch and publish what it asks for.

**O2. Moving one Starter site to another starter host.**

1. On the site's node page, **Copies**, **Copy onto a host**, choosing a
   starter host with a free place.
2. When the copy is ready, **Switch over**. The sold provision stays on the
   site's row and names the new host and port; the old container's row holds
   nothing billed.
3. Point the site's name at the new host if its DNS names the old host's
   address.
4. Remove the old container with **Permanently Delete Site** on its row. Its
   place comes back.
5. Check, a day later, that the site still receives mail: its relay slot and
   fleet key are the same ones.

**O3. Bringing back a held Starter site after a late payment.** A payment
that arrives after the shutdown clears the ready-to-remove mark and writes a
note on the hosting row; nothing else happens on its own. On the site's node
page, **Bring back** (level 10, `HostedLateReturn`) is offered once a
subscription pays for it again: its own line active again, or, when the
processor had cancelled that one and the buyer bought again, the one active
subscription to the same product that no other site is on, which the site is
moved to. It asks the host to start the container, and the page says
**Starting**. Once the host answers that the container runs, the hosting watch
sets the hosting row to subscribed with every date cleared, puts the node's
backups back on the fleet's defaults and turns its uptime checks on
(`HostedLateReturn::settle`). A start that did not take changes nothing: the
page names the job and offers Bring back again. The relay slot comes back on
its own on the next relay pass, and the banner on the next watch. A suspended
site is unsuspended first, and Bring back waits while a removal is under way.
A buyer who bought again usually has a new site on the new subscription
already, so the old one has no subscription free to take: either the buyer
keeps the new site (copy what they want across, then remove the old one), or
cancel and remove the new site's order first, and Bring back then takes its
subscription. A site already removed is not brought back: it is a new order,
restored from the buyer's own backups if they kept any.

**O4. A domain registered through us.** The platform never renews it. Before
its first expiry, push it to the buyer's own registrar account
(`ManagedDomainWatch`); the buyer's copy says so. On a refund, push it then:
the records we published in it go with it and stay working until the buyer
changes them. On an abuse suspension, do nothing: it lapses.

**O5. Losing a Starter host.** Its seven sites are restored one by one, each
from its own backups with its owner's recovery key: make a new starter host,
then for each site a new Starter install restored over the agent from that
site's backups. A site whose owner never made a recovery key has no backups
and is not rebuilt; its owner is told so.

**O6. Restoring a hosted site from its backups** (the buyer asks, or after a
loss). Follow [Backups](../../../docs/backups.md) § *Restoring a managed node from
its management node*: on the site's node page, Backups tab, **Bring back to
node** (or **Prepare** for a chain), then ask for the restore. The site's own
Backups page then shows a pending approval that only its recovery key opens,
so the buyer approves it in their browser (or the operator, when the buyer has
handed the key over). A site whose buyer never made a recovery key has no
backups to restore; tell them so. Tell the buyer when it is done.

**O7. A Managed site paid for after its shutdown.** A shut-down Managed site's
server is powered off and waiting to be deleted (O8); a late payment writes a
note on the hosting row and brings nothing back by itself. There is no Bring
back for a server-based site, and no step that carries its content onto a new
site: a copy from its backups makes a new server, but its hosting row stays
shut down and would still prune those backups on their date. So a shut-down
Managed site is not brought back. Refund the late payment and tell the buyer;
they may order a new site, which starts empty. Then delete the old server as
in O8.

**O8. Deleting a shut-down Managed server.** When a hosted site's grace runs
out, the platform powers its server off and sends **Hosted instance needs
deleting by a person** (`hosted.deletion_required`, to the operator's
notifications). It never deletes a server itself.

1. Check the site has no payment pending and no O7 under way.
2. Delete the server at the provider (for Linode, Cloud Manager, the instance
   named in the notice). Its backups are not on it: they stay in backup
   storage until the date the notice names.
3. On the site's node page, **Remove Permanently**, confirming the server is
   gone. The removal refuses while the site's hosting subscription still bills,
   which it should not after a shutdown; if it does, the subscription has to be
   cancelled first.

## Setting it up

**Server Manager → Provisioning → Hosted tier** takes the mail provider's
master key and webhook secret, the allowances, the grace and backup storage
periods, the sites-page address pushed to each site's banner, and the two
referral links the off-ramps use, and names the default cloud account hosted
servers go to (chosen on the **Hosting** tab; see *Cloud accounts* in the
[overview](overview.md#cloud-accounts)). Until a usable default account and the
mail key are both present, a hosted order cannot be fulfilled — a hosted site
without mail is a site whose owner cannot reset their own password.

At SMTP2GO the master key's status is **Allowed**: Blocked turns off every API
call, not only sending. This site never sends with the key, so it is narrowed
with permissions instead: Subaccounts, SMTP Users, Sender Domains and Webhooks
on, Emails (the API's sending) off. The tab checks
the key with SMTP2GO each time it opens and shows each permission beside the
key's field, Webhooks among them: every customer subaccount gets its own
webhook to `/ajax/smtp2go_webhook` on this site, made with this key and carrying
the webhook secret. Nothing is set up by hand in SMTP2GO's webhook screen.

Then, on the product: pick *Customer cloud server* under Purchase grants and
choose **Create the server on the operator's account (hosted)**. Give it one
monthly subscription version; nothing is attached under *Info to collect
before purchase* — the provider contributes the one requirement the line
carries, and the buyer configures the site before paying. A trial is optional:
a version that carries one needs the same length set under Hosted tier, so the
banner counts down to the right day; with none, a new site is subscribed from
checkout. `server_manager_hosted_regions` lists the regions the configure page
offers; empty means the one customer-cloud region and no choice shown.
