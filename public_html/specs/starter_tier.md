# Starter tier — a $3 site on a shared box

**Status:** Draft, 2026-10-04. Nothing here is built. Reviewed the same day by
public-html-d7; its findings (S-numbers) are folded in. The owner's decisions
from that review are at the end.

## What this is for

The goal is the lowest monthly cost for a casual, kick-the-tires site. Many
sites share one Docker box, which is the cheapest arrangement we have.

**This spec is the product.** The pieces it stands on are their own specs,
and each can be built and shipped alone:

| Spec | What it gives the starter tier |
|---|---|
| `multi_tenant_docker_hosts` | the box: memory, CPU and disk caps per site, sites walled off from each other, stop/start that stays stopped, a catch-all default site |
| `hosted_abuse_response` | per-buyer limits, detection, and one-click Suspend |
| `relay_receive_only_forwarding` | forwarding that never sends from our machines |
| `own_mail_server_sending` | no machine we create sends mail on port 25 |
| `mail_reject_at_smtp_time` | no backscatter (for colocated boxes; starter sites are relay-fronted) |

The starter tier cannot be sold to strangers before `multi_tenant_docker_hosts`
WP5 (walling sites off) and `hosted_abuse_response` are in place.

## Where this sits: three ways to run a site

Owner direction, 2026-10-04: **price for growth, not profit.** The money is
expected to come from business licenses, each worth about 30 users' profit.
Hosting is priced to bring people in.

| Tier | What the customer gets | Price (working figure) | Built? |
|---|---|---|---|
| **Starter** | a site on a shared box, with mail and Drive, inside a storage allowance | ~$3/mo, paid up front | this spec |
| **Own server** | a private VPS (~20 GB), with our backups and 1,000 emails a month | $8/mo for now (owner, 2026-10-04) | yes: the hosted tier |
| **Bring your own** | the customer's own VPS, plus our mail and backups if they want them | ~$3/mo for mail and backups | the services phase specs |

**The starter tier is complete, not crippled.** It includes mail and Drive.
What limits it is the storage allowance. Running out of space is the natural
point to move up, and the site's banner says so: **Move to your own server**
(WP2.6).

**There is no free tier.** Owner decision, 2026-10-04: free hosting that
includes a domain, mail and file storage attracts phishing, malware and
spam, and the reports land on our Linode account, where every node we run
lives. The price must make abuse not worth it. What deters an abuser is
mostly that a real payment is taken before the site exists, not the size of the amount
(`hosted_abuse_response`). This decision also retires the free trial in
`subdomain_sandbox_tier`, which is marked superseded. The starter tier is
how people try the product.

**The Linode credit offsets cost; it does not make the tier free.** Linode
has given $5,000 of credit, usable through 2026-12-31. That pays for about
69 starter-tier boxes for that period, or about 830 sites. The
credit covers the servers only. Email (SMTP2GO) and backups (B2) are still
billed to us.

## The box, and the numbers behind it

**Plan: Linode shared 4 GB** ($24/mo, 2 CPUs, 80 GB disk), **12 sites per
box** (owner decision, 2026-10-04), about **$2.00 per site per month**.

The 8 GB plan costs the same per site. The 4 GB plan wins because a disaster
on one box (a full disk, a kernel panic, an abuser) reaches 12 customers
rather than 24. Larger plans are worse: their CPUs grow more slowly than their
memory, so CPU runs out before memory does.

**Why 12 and not 14 (S10).** The memory caps are hard limits with no swap
behind them (`--memory-swap` is pinned to the same figure). 14 × 256 MB is
3.5 GB on a box with about 3.9 GB usable, which leaves about 300 MB for the
kernel, Docker, the host's Apache proxy, the host agent and the page cache.
12 × 256 MB is 3 GB and leaves about 900 MB. The caps are then a real promise,
not an oversubscription that holds only while most sites are idle.

### What one site costs us each month

Prices checked 2026-10-04.

| Item | How it is worked out | Per site |
|---|---|---|
| Server | Linode shared 4 GB, $24 ÷ 12 sites | $2.00 |
| Email, 1,000 a month | SMTP2GO Professional, $75 for 100,000 = $0.00075 each ($0.000625 billed yearly) | $0.75 |
| Backups, 28 days | B2 at $0.00695/GB. Our small Docker sites keep 1.5–3 GB for 28 days | $0.01–0.02 |
| **Total** | | **≈ $2.77** |

- **The email figure assumes a full plan.** The $75 is fixed, so it only
  works out to $0.75 a site once about 100 sites share it. Until then,
  SMTP2GO's own entry plan ($10 for 10,000) costs the same per email and
  fills sooner. A site that sends the services spec's typical 600 emails
  costs $0.45.
- **Backups keep the site default of 28 days.** At these sizes the window
  costs almost nothing, so the starter tier does not shorten it. A site at
  its full disk allowance still costs only a few cents.
- **Restore checks are free.** B2 charges nothing for downloads up to three
  times what is stored, which covers the monthly check that opens the newest
  backup.
- **Payment fees are not included.** Stripe takes 2.9% + $0.30 a charge,
  which is $0.39 on a $3 monthly charge.

What the estimate rests on, measured on docker-prod (a 4 GB Linode running 8
sites) on 2026-10-04:

- memory: 1.54 GB in use plus 0.32 GB of swap for 8 sites, which is about
  200 MB per site at rest
- disk: 20 GB used, which is about 2.5 GB per site before any customer
  content, including the OS
- load average: 0.7–1.1 on 2 CPUs. Roughly 0.1 CPU per resting site, but
  **not measured per site** yet (`multi_tenant_docker_hosts` WP1)

The per-site figures are a starting point. The multi-tenant host's own measurements (`multi_tenant_docker_hosts` WP1) set the
final figures.

### What the "no access to your personal data" promise is on a shared box

**Against us, the operator, nothing changes.** On a dedicated hosted machine
we already hold root: our agent runs there, and enrolling a node grants the
power to replace its code (hosted tier § Doctrine). A shared box gives us the
same power and no more. What protects a customer from us is the same in both
places: sealed content is encrypted to keys the server does not hold, and
backups are sealed to the customer's own recovery key.

**Against the other sites on the box, the wall is weaker.** On a dedicated
machine a neighbour has to break out of a virtual machine. On a shared box,
once its isolation is in place (`multi_tenant_docker_hosts` WP5), they need a container escape, which in practice means a
Linux kernel bug. What a neighbour who escapes can reach depends on the
protection level (the Protection Levels doc):

| Data | What an escaped neighbour gets |
|---|---|
| Site content with no level (members, events, orders, pages) and anything at **Standard** | all of it, at any time: it is plain text on disk |
| **Private**, at rest | nothing: it is ciphertext |
| **Private**, while its owner is unlocked | the secret key, which sits in that site's server memory for the unlock window (30 minutes idle by default) |
| **Fortress** | ciphertext only. The key never reaches the server. The remaining attack is active: altering the JavaScript the site serves so that it captures the key at its owner's next browser unlock (Drive encryption § served-JS). Native apps, which do not run served code, are not exposed to it. |
| Backups | nothing: they are sealed to the customer's recovery key |

The tier's own copy says this in one line: "Your site shares a machine with
other sites. For data you would not want a stranger to reach, choose your own
server."

### WP1 — Mail on a shared box

A box has one public IP address, and only one program can listen on its
port 25. Today a site's Postfix takes that port (mailbox overview §
Firewall). On a shared box, the second site to switch on mail would either
fail or take inbound mail away from the first.

**Inbound mail arrives through the hosted relay fleet.** The relay accepts
mail on its own port 25, seals it at the moment it accepts it, and each site
**pulls** its own sealed mail over HTTPS. Nothing on the box listens on port
25, so the box's sites need nothing between them. Enroll and pull
authenticate by signature; nothing compares an address or an instance ID.
**But the path is not reachable for a container site as built (S17):**

1. The hosted relay offering is switched off in code
   (`mailbox_hosted_relay_offered()` returns false in `receive_mode.php`).
   The starter tier needs it on, at least for starter sites.
2. A site is enrolled for its slot only from `ProvisionCustomerCloud`, which
   needs a provision row. The starter tier's container install (WP2.2) has to
   do the same seeding.
3. **A subscription carries one slot per buyer** (`FleetProvisionSeeding`).
   A buyer's second starter site would be refused, while `hosted_abuse_response` allows a few sites per buyer. Slots become per site.
4. Nothing sets `mailbox_receive_mode` at provisioning time; it is only ever
   chosen on the admin page. The container install sets it to the relay
   slot.

On a starter box, the box's firewall drops inbound port 25 outright, and the
site has no Postfix or rspamd at all: no Docker site container carries a mail
stack (`multi_tenant_docker_hosts` WP9, owner 2026-10-05). The relay's rspamd
checks the mail before the site pulls it. A per-container rspamd needed about
200 MB, which a 256 MB site cannot hold.

**Outbound mail goes through SMTP2GO**, using the hosted tier's mail leg: a
subaccount per customer and one SMTP user (hosted tier § Outbound mail). The
provider counts the monthly send allowance. Nothing on the box can raise it.

**As built, every starter site would show a red setup check (S18).** The mail
leg configures SMTP2GO as a generic SMTP service. On a relay-fronted site the
SPF plan fails as a required check for any service that is not an API relay
(`InboundEmailSetupCheck` `spfPlan`, "switch provider"). Compose is also
refused until the origin-leak probe passes (`OutboundTransport`). The hosted
mail leg switches to SMTP2GO's API (`Smtp2GoProvider`), so it relays raw
messages over HTTPS the way Mailgun and SES do. Check that the provider
implements the raw-relay capability. If it does not, that is part of this
work.

**The privacy promise gets stronger here, not weaker.** Mail sealed at the
relay is ciphertext before it ever reaches the shared box, so a neighbour who
escapes gets nothing from new mail. That holds for a Private mailbox with
Seal at the relay on, and for Fortress. A Standard mailbox's mail is
unsealed on the box, as in the promise table above.

Relay capacity comes from the same Linode credit while it lasts. Size the
fleet from WP1's figures for inbound mail per site. Relays send nothing:
a starter site's forwarding addresses are forwarded by the site at pull
time, through its SMTP2GO subaccount, and count toward its 1,000 a month
(`relay_receive_only_forwarding`).

### WP2 — Sell it, and the way up

The starter tier is sold through the **hosted-tier pipeline**: configure,
then pay, then the mail leg, fleet backups, the billing clock and the plan
banner. The difference is that the site lands in a container on a
starter box. **Almost every leg of that pipeline assumes a machine of
its own**, and this WP is the largest piece of the build.

1. **A third landing place (S2, S21).** The provision row records where its
   site landed in an explicit landing kind: its own instance, or a container
   on host N. **Every reader of `cvp_instance_id` branches on it, and a
   site's row never holds its host's instance ID.** As built, an empty ID
   makes `HostedTrialWatch` mark a container site shut down while it keeps
   serving. The host's ID would power off the whole box. The same column
   drives "Move to my own Linode account" (`profile_sites_logic.php`), which
   for a container would transfer the whole box. Build items:
   - The product's fulfillment reference is two-valued today
     (`PollHostingOrders`, `CustomerCloudFulfillment`). It gains the starter
     landing place.
   - `ManagedSiteDraft` expects exactly one Managed product. It learns the
     starter product.
   - `pick_for_provisioning` filters by the host's tier (`mgh_tier`, a new column on `mgh_managed_hosts`), so a
     starter site never lands on docker-prod and another tier's site never
     lands on a starter box.
   - Instance transfers to the customer's own Linode account are refused for
     a container site, with the way out (WP2.6) named.
2. **Installing a site on an existing box is a new host-agent word (S3).**
   `install_node` runs only through `InstallJobExecutor`, which needs a
   sealed root password. A keyless box has none, and no host-agent word
   installs a container. The new word, "install a site container", runs
   `install.sh site` with the box's caps and records the run spec (`multi_tenant_docker_hosts` WP0).
   **The older shared-host path in `PollHostingOrders` (an order with a
   domain answer, then `pick_for_provisioning`) creates jobs nothing can
   run.** It is retired in this WP, and the Server Manager overview, which
   still documents it as working, is corrected.
3. **The site's agent joins without a person (S4).** A join from an address
   that belongs to a provision is refused unless the claimed name is
   `<slug>-host` (`AgentChannelEndpoint`). Every container's site agent on a
   box would join from that one address. And every join is approved by a
   superadmin by hand today, so a $3 self-serve site would wait on a person
   before its mail, banner and backups work. Instead, the box's host agent,
   which is already trusted, installs the site and reports the new site
   agent's key fingerprint, and the plane approves the join when it matches.
4. **Stopping a container site at the end of grace (S1).** Stop and start
   that stay stopped come from `multi_tenant_docker_hosts` WP7. For a container
   site, `HostedTrialWatch`'s end-of-grace step stops the container and holds
   it, instead of shutting down an instance. The platform never deletes
   anything, as everywhere else.
5. **Allowances.** Disk is the multi-tenant host's allowance (`multi_tenant_docker_hosts` WP4). **Backup storage needs a
   per-tier allowance (S20).** Today it is one global setting
   (`server_manager_hosted_shelf_allowance_gb`, default 10 GB), and so is the
   send allowance. The starter tier's backup allowance is 20 GB: 28 days of
   weekly full backups of a site at its full disk allowance fits within that,
   and costs us $0.14 a month at most. A starter site that connects its own
   bucket (Cloud Storage) moves Drive and mail files off its disk, but backups
   still copy them into our backup storage, and this allowance is what bounds
   that.
6. **The way up is a new feature, not an existing one (S19).** At 80% of the
   disk allowance, the banner's Disk line offers one action: **Move to your
   own server.** Site copy exists, but not in this shape:
   - It refuses a running container (`SiteCopyRunner`).
   - Copying from backups is operator-run, loses everything since the last
     backup, and needs the owner to paste their recovery key.
   - It leaves the old container running.
   - It lands on an operator-origin provision that is not sold: no billing,
     no banner.
   - The sold provision, its order item, SMTP2GO subaccount and billing row
     stay on the retired row (`SiteCopySwap`). Nothing touches the Stripe
     subscription.
   - Today's banner action is a Linode referral link (`HostedTrialWatch`).

   The move:
   1. The customer buys the Own server tier from the banner.
   2. The box's **host agent** stops the container and holds it (item 4).
      Something no container can do to itself gives a move that loses
      nothing.
   3. The host agent takes a final backup.
   4. The new machine restores it.
   5. The provision, its SMTP2GO subaccount and its relay slot move to the
      new machine, and the Stripe subscription changes from starter to Own
      server.
   6. DNS switches to the new machine.
   7. The old container stays held, not deleted, until the move is
      confirmed.
7. **A catch-all default site** on every starter box, so an unknown name
   never shows another customer's site, comes from `multi_tenant_docker_hosts`
   WP8.
8. **The domain.** A hosted site always lives on a domain the buyer owns or
   registers through us at cost (`ManagedSiteDraft`). So "$3 a month to try
   it" also means a domain. **Owner decision, 2026-10-04: every site has a
   domain,** starter included. No subdomains of a name we own.

## Testing

On a scratch starter box with limits and isolation in place:

| Check | Pass |
|---|---|
| one buyer's second starter site | gets its own relay slot and receives mail |
| a starter site's mail setup page | no red checks |
| a new starter site's agent joins | approved without a person |
| Move to your own server | the site arrives on its new machine with nothing lost; billing, mail and relay slot follow; the old container is held |
| a starter order is paid | the site lands in a container on a starter box, never on docker-prod |
| a container site reaches the end of grace | its container is stopped and held; the box and its other sites are untouched |

## Docs and specs to update when this lands

- `plugins/server_manager/docs/hosted_tier.md` § Allowances: Disk is enforced
  per site on a starter box. The rule "there is one tier … never sold a
  bigger plan" changes. A starter site that outgrows an allowance is offered
  the Own server tier. A customer on their own server who outgrows an
  allowance is still pointed at their own account for that service. The same
  rule lives in code comments (`HostedPlanNotice`, `HostedTrialWatch`), which
  change with it (S25).
- `managed_hosting_and_services` spec: doctrine 6 (the same rule), and "the
  Managed topology is single-server by decision", which the starter tier's
  relay-fronted, shared-box shape departs from (S25).
- `DEFERRED_services_pricing` spec: Managed $12.99 becomes Own server $8, and
  its email $1.99 plus backups $1.99 sit against the $3 services figure (S25).
- The mailbox overview § Firewall: port 25 is closed on a starter box, and
  its sites receive through the relay fleet.
- `plugins/server_manager/docs/overview.md`: the host's tier field, and the
  retired shared-host order path (S3).

## Owner decisions from the review (2026-10-04)

- **Sites per box: 12.** Every memory cap stays a real promise, with about
  900 MB left for the host.
- **Every site has its own domain**, starter included: one the buyer owns,
  or one registered through us at cost. No subdomains of a name we own.
- **Own server: $8 a month for now.** The `DEFERRED_services_pricing` spec's
  $12.99 Managed figure changes to match when this lands.
