# Hosted Trial Provisioning — pay once, log into a working site

**Status:** Draft, 2026-09-06. All owner decisions taken (§10, §13): SMTP2GO
for outbound mail; one hosted tier on a 1 GB Nanode, 60-day free trial then
$9.99/mo at the same allowances; relay only on a buyer-supplied VPS; B2
shelf; backups amber until the owner's key ceremony; 30 days grace then
shutdown, shelf kept 90 days. Engineering items E1–E7 settled (§13). Ready
to build (§11); owner account setup in §13 runs alongside.
**Companions:** `automatic_install_mail_topology.md` (the checkout question this
builds on), `managed_domain_registration.md` (the domain leg),
`keyless_provisioning.md` (no key of ours ever lands on a machine we create),
`implemented/fleet_scheduled_backups.md` (the write-only node credential
pattern), `subdomain_sandbox_tier.md` (the free try-it tier — separate, not a
funnel into this).

## 1. What this does for the buyer

Someone pays $39.99 on getjoinery.com, types the domain they want, and a few
minutes later logs into their own site at that domain with email that sends and
receives and backups that run. They do not open a Linode account, an email
provider account, or a storage account. They do not paste a DNS record. They do
not read a password off a server.

The hosting itself runs on our accounts — our Linode, our SMTP2GO, our storage
bucket — at one fixed set of allowances (§12). **There is no larger paid tier
(owner, 2026-09-06).** A customer who outgrows an allowance is pushed to their
own account for that service through our referral links, not upsold; the
owner may reconsider later. A card is on file from the first day; the free trial
converts to a $9.99/mo subscription at the same allowances when it expires.
When hosting ends the site is shut down, never deleted by the platform (§7).

Today's flow instead makes the buyer open a Linode account with a card, grant us
access on the Connect page, set an A record by hand, and then cannot log in at
all — the install job never passes the buyer's email or a password to the
installer, so the admin account is born with the installer default and a random
password in a file on a box nobody can read (B1 below). This spec replaces that
flow for the hosted path and keeps the bring-your-own-cloud path as the advanced
choice.

## 2. Doctrine

Four rules, all inherited, none new:

1. **A customer's box is the customer's trust domain.** They are permission 10
   there. Anything stored on it, sealed or not, is theirs to read. So a
   credential shared between customers never reaches a box. What reaches a box
   is a credential cut to that customer's own slice, revocable on its own.
2. **Enforcement lives where we control it** — at the provider (a key that
   cannot name another customer's domain or prefix, a cap the provider counts)
   or on the plane (metering and revocation). A setting on the box is
   advisory: the customer can edit it.
3. **Never a key of ours on a machine we create.** The plane's Linode token,
   SMTP2GO master key, storage master key and DNS credentials stay on the
   plane. The box gets one SMTP user inside its own SMTP2GO subaccount, and
   nothing else standing: the backup credential arrives per run and expires.
4. **The platform never deletes a cloud instance programmatically.** Shutdown
   is the strongest automatic action. Deletion is a person at the provider.

## 3. Buyer journey (end state)

1. Product page: domain field with live availability and price (the managed
   domain requirement, already built), or "I already own this domain".
   Email topology question per `automatic_install_mail_topology.md`.
2. Checkout: one payment. The hosting line is a subscription with a trial
   period, so Stripe collects the card now and charges nothing until the
   trial ends. The domain line is one-time, as today.
3. Provisioning runs unattended on the plane (§5). No Connect page, no grant.
4. Welcome email: the site URL and a link to the buyer's order page on
   getjoinery, which shows the admin password once. No password in email.
5. First login: forced password change, then the setup wizard with the Email
   and Backups steps already green. What remains is the personal encryption
   key, sign-in security, and the optional AI, calendar and mail-import steps.
6. Banners (permission 5+ only, pushed as managed settings) show each
   allowance's usage and, when one is near, the one action for that service:
   open your own account (§7).

For a buyer who owns their domain already: the welcome email carries the A
record instruction as today, and the DNS publish box on the site's mail Setup
tab does the mail records with a one-time credential handover. Email arrives
green only after that. That is the honest state; we do not hold their DNS.

## 4. Per-service design

### 4.1 Compute — Linode, our account

- `cvp_hosting_mode` = `operator` | `customer`. `customer` is today's path,
  unchanged. `operator` resolves the compute driver from a plane-held token
  (setting `server_manager_operator_linode_token`, sealed) instead of a buyer
  grant. `resolve_driver()` branches on the mode; everything after
  `handle_ready()` is the same pipeline.
- Instance type and region come from settings, never from the buyer.
- The root password is generated, sealed on the row, and retired after the
  agent joins — exactly as the keyless design already does.
- The buyer's getjoinery email becomes the site admin (`--admin-email`, an
  installer seam that exists and is unused by the job). The admin password is
  generated on the plane, passed as `JOINERY_ADMIN_PASSWORD`, sealed on the
  provision row, and revealed once on the buyer's page — erased in the same
  request (E7).

### 4.2 Domain and DNS

Unchanged from `managed_domain_registration.md`: registered in our account with
the buyer as registrant, apex/www/mail records published by the plane, one
year, no renewal by us, six months of silence then the graduation notice. The
buyer holds no DNS credential. The plane writes only the zone that matches the
provision's domain.

### 4.3 Outbound mail — SMTP2GO, our account, one subaccount per customer

Every API call below is made with the plane's master key and carries
`subaccount_id`, which is how a master account acts for a subaccount.

- **Subaccount per customer** (`POST /v3/subaccount/add`, then `edit` with
  `limit`). The subaccount is the unit of isolation: its own SMTP users, its
  own sender domains, its own usage counter. Other customers' logs and
  recipients are invisible to it.
- **Sender domain** (`POST /v3/domain/add`, `subaccount_id`): the response
  carries the DKIM TXT, return-path TXT and tracking CNAME to publish. The DNS
  leg publishes them under the buyer's zone (with `mail.<domain>` as the
  sending identity per the sending-identity doctrine), then `domain/verify`.
- **SMTP user** (`POST /v3/users/smtp/add`, `subaccount_id`): one per
  customer, inside their subaccount. Its username and password are what
  reach the box, pushed over the channel as the site's SMTP settings
  (`email_service = smtp`, host `mail.smtp2go.com`, port 587). The platform's
  existing `SmtpProvider` sends; no new provider class.
- `defaultemail` = `hello@mail.<domain>`; Reply-To = `info@<domain>`.
- **Daily cap by rolling limit.** SMTP2GO enforces a subaccount's `limit`
  against month-to-date usage and has no daily field. So a daily plane job
  reads each subaccount's month-to-date sends (email-history summary per
  subaccount, or the per-credential webhook `auth` field) and sets
  `limit = sent_this_month + daily_allowance`; on the 1st it sets
  `limit = daily_allowance`. The provider then hard-stops at the day's
  allowance and the plane is never in the send path. A missed plane day caps
  the customer at yesterday's figure — fails safe. SMTP2GO permits a 10%
  overrun past `limit`; the allowance is chosen with that in mind.
- **Kill switch:** "Close a subaccount" (reopen exists). Removing the SMTP
  user is the softer step.
- **Webhooks** are scoped per sending credential and carry `auth` naming it,
  so one plane endpoint meters every customer; events `bounce`, `spam`,
  `reject` feed the complaint thresholds.

### 4.4 Inbound mail — the box's own Postfix

No shared resource. Inbound port 25 is not blocked at Linode. MX points at the
box (single topology) or at the buyer's relay (relay topology). The mailbox
plugin's provisioning already handles this once the domain leg publishes the
records the box asked for.

### 4.5 Backups — the fleet manager profile, sealed to the customer's key

Corrected 2026-09-06 (owner caught it): the platform already has exactly the
backup the hosted tier needs. Every node's backups — both the site profile
and the management node's **manager profile** — seal to the node's own
`backup_recovery_public_key`, and a manager run that arrives carrying key
material is refused (`docs/backups.md`, `BackupRunner::plan_manager`). So the
fleet backing up a hosted customer's box to our bucket is not a privacy
problem: the archive is unreadable by us, and no single key opens the fleet.

Therefore the hosted tier's backup **is** the fleet manager profile. Nothing
is seeded on the box — no BackupTarget row, no stored bucket credential, no
scheduled task. `FleetBackupRun` already schedules every managed node with a
web root, retention already runs on the plane, and the wizard's Backups step
already reads green from manager-profile history rows
(`BackupHistory::manager_coverage()`).

What the hosted tier changes:

- **A per-node, per-run credential instead of the target's one shared
  write-only key.** Today a target holds a single `bkt_node_credentials`
  handed to every node; on a customer's box that key could write anywhere in
  the fleet bucket. The plane instead mints, at dispatch, a B2 key pinned to
  `{prefix}/{slug}/` with `writeFiles` only and a lifetime of a few hours
  (`b2_create_key`, `validDurationInSeconds`), and passes it as the run's
  credential. The box holds nothing between runs and nothing it could reuse.
  The existing per-run placeholder mechanism carries it.
- **Backups stay amber until the recovery-key ceremony** (Q3): a machine
  with no verified key of its own refuses to back up at all, by design. The
  banner says so; the wizard step is the one ceremony.
- **Size allowance** = keep-N chains on the plane's retention pass (already
  built) plus the prefix measurement from §6.
- **Restore** is the existing restore-over-agent path; the read credential
  it needs is minted the same way, read-only, short-lived, prefix-pinned.

### 4.6 Trial state and banners

Trial start, end, plan, and enforcement verdicts live on the plane in a new
`hosted_trial` row per provision. The box learns only what it needs to render:
`managed:true` settings pushed over the channel (the `ManagedDomainNotice`
pattern) carrying the hosting end date, usage against each allowance, the
own-account links, and any active suspension notice. Rendered to permission 5+ only. The customer cannot edit a
managed setting.

## 5. Provisioning orchestration

Additional phases of `ServerManagerAdvanceProvisioning`, each guarded by
status, not timestamps (a stamp written after a provider call is one crash away
from a second charge or a second key):

1. Site row `ready` → instance born on the operator token.
2. Install job carries admin email + sealed admin password.
3. Domain leg publishes apex/www (existing).
4. **Mail leg:** create subaccount → set limit → add sender domain → publish
   its records → verify → add SMTP user → push settings to the box → register
   webhook. Each step stamped by state.
5. **Backup leg:** nothing to seed. The node joins the fleet schedule as
   any managed node does; the first manager run happens after the buyer's
   key ceremony. The plane's dispatch mints the per-run prefix key (§4.5).
6. `done` → welcome email → banners pushed.

Failure in the mail or backup leg leaves a working site and an operator alert;
the buyer's wizard shows the honest amber state for that step.

## 6. Enforcement — the limits that need a meter

Scope limits (which domain a key can send as, which prefix it can write, which
zone the plane touches, who holds a Linode credential) are structural: the
credential cannot name anything but its own slice, so there is nothing to
enforce and nothing listed here. What follows is the set of limits a customer
*can* exceed.

| Limit | Signal | Warn | Act | Lever |
|---|---|---|---|---|
| Sending volume, daily | SMTP2GO counts month-to-date against the subaccount `limit`, which the plane rolls forward daily (§4.3) | Banner at 80% of the day's allowance, from the webhook count, with the own-account link | Provider refuses at the allowance (+10%) until the plane rolls the limit forward next day | The `limit` itself; the plane only moves it |
| Complaints and bounces | Webhook events `spam`, `bounce`, `reject` per `auth` | — | Sending stops at the threshold; operator alert; repeat → shutdown | Remove the SMTP user, then close the subaccount; instance shutdown |
| Storage size | Prefix listing on the plane's retention pass | Banner at 80% with the own-bucket link | Runs stop at 100%; the node's fleet policy is set off and history records why | Stop minting the per-run key |
| Site data on disk | Node's own disk usage, reported by the agent's check_status | Banner at 80% with the move offer | Nothing automatic — a full disk stops the site on its own | None; the off-ramp is the lever |
| Outbound bandwidth | Instance transfer figure from the Linode API (the pool is account-wide, so this is the only per-customer view) | Banner at 80% with the move-to-your-own-Linode offer | Shutdown at 100% until the month rolls | Instance shutdown, reversible |
| Trial end | Plane clock plus the Stripe subscription state | Banner from two weeks out | Shutdown at end plus grace | Instance shutdown; deletion is a person at the provider |
| Failed charge | Stripe webhook | Banner for the 30-day grace | Shutdown at day 30; shelf kept 90 more days | Same |

Only the storage and bandwidth caps are plane-enforced without a provider
backstop (no storage provider caps a prefix; Linode has no per-instance cap).
Both are metered with margin.

## 7. Lifecycle and the off-ramps

- **Start:** provisioning `done`. Card on file via the subscription's trial
  period.
- **Outgrowing an allowance is an off-ramp, not an upsell.** Each banner and
  the matching wizard step name the one action for that service:
  - *Email* → open your own SMTP2GO account through our referral link (20%
    recurring for the customer's first 12 months via PartnerStack, 90-day
    cookie). The wizard's Email step takes their credentials; the box's SMTP
    settings switch to them; the plane closes their subaccount. Sending is
    then theirs, uncapped by us.
  - *Backups* → point the Backups step at your own bucket (the existing
    site-profile target form; both profiles may run side by side). The
    plane sets the node's fleet policy off and, after the retention window,
    prunes the prefix.
  - *Compute or bandwidth* → move to your own Linode. Images cannot cross
    Linode accounts, so the move is a new bring-your-own provision with
    `install_mode = from_backup` reading this node's shelf (the existing
    path), through the Linode referral link already held as a setting. When
    the new box is live and DNS moved, the hosted instance is shut down and
    queued for manual deletion.
- **Convert:** Stripe charges at trial end; webhook
  flips `hosted_trial` to `subscribed`; the countdown banner clears. The
  allowances do not change.
- **Lapse:** charge fails → 30 days of grace with the banner up (Stripe's
  retries run inside it; a successful retry cancels everything) → instance
  shutdown via API (reversible) → the backup shelf is kept for **90 days**
  after shutdown, then an operator task to delete the instance at the
  provider and prune the prefix. A customer who pays inside the 90 days is
  restored from the shelf, not lost. The grace month is paid for by the
  $39.99 setup fee.
- **Abuse:** complaint threshold → SMTP user removed immediately, operator
  alert; repeated → subaccount closed and instance shutdown. Never deletion.

## 8. Data model

- `cvp_customer_cloud_provisions`: `cvp_hosting_mode`, `cvp_admin_pass_sealed`
  (erased on reveal), `cvp_smtp2go_subaccount_id`,
  `cvp_smtp2go_domain_id`, `cvp_smtp2go_user_id`, per-leg state columns.
- New `htr_hosted_trials`: provision id, start, end, state
  (`trial|subscribed|grace|suspended|shutdown`), allowances, meters (sends
  today/month, complaints, storage bytes, transfer bytes), last-measured
  stamps, last `limit` written, Stripe subscription id.
- Plugin settings: operator Linode token (sealed), SMTP2GO master key
  (sealed), SMTP2GO referral URL, storage master credential (sealed, the
  existing target), trial length, the allowances in §12, instance type and
  region.

## 9. Out of scope

- The free subdomain sandbox (its own spec).
- Fortress at checkout (owner-interactive by design).
- An AI provider key on our account. The AI step stays bring-your-own.
- Migrating existing bring-your-own-cloud customers.

## 10. Open decisions

- ~~Q1. Storage provider.~~ **Decided 2026-09-06: B2.** Prefix- and
  capability-pinned keys with a lifetime are native; one call per run.
- ~~Q2. Price of the hosted tier.~~ **Decided 2026-09-06: $9.99/mo** after
  the free trial, at the same allowances; no larger tier. Competitor anchors
  that set it (verified 2026-09-06, monthly billing): Zoho Mail Lite
  $1.25/user (mail only); Hetzner Storage Share NX11 €4.29 (managed
  Nextcloud, no email); Fastmail Standard $6/user (mail only); Proton Mail
  Plus $4.99, Proton Unlimited $12.99; Google Workspace Business Starter
  $8.40/user; Microsoft 365 Business Basic $7/user; PikaPods ≈ $2–4 per app;
  Cloudron ≈ $15 licence + $5 VPS, self-managed. Against §12's cost ($5.46
  typical, $7.32 at the caps, plus the SMTP2GO plan share) the typical
  customer clears about $4; the at-cap customer is roughly break-even and is
  who the off-ramps are for. Annual billing, if offered, is a separate
  decision.
- ~~Q3. Interim recovery key.~~ **Decided 2026-09-06: none — amber until
  the ceremony, by design.** `BackupRecoveryKey` (1.2) accepts the public key
  from this site alone and refuses to seal until the owner has opened a
  challenge with the private half, so neither a plane-held interim key nor a
  plane-generated key shown on the order page can exist. The plane pre-seeds
  everything else (target, write-only key, scheduled task); the first backup
  runs the moment the wizard's one-ceremony Backups step passes. The banner
  says: your backups start when you create your recovery key.
- ~~Q5. Grace period.~~ **Decided 2026-09-06: 30 days of grace after a
  failed charge, then shutdown; the backup shelf is kept for 90 days after
  shutdown.** The owner treats the grace month as paid for by the $39.99
  setup fee (about $5.50 of hosting cost). A shelf held 90 days costs at most
  10 GB × 3 months ≈ $0.21. Stripe's own retry schedule (about two weeks)
  runs inside the grace window; a recovered card cancels the shutdown.
- ~~Q6. Instance plan.~~ **Decided 2026-09-06: 1 GB Nanode.** Memory is not
  the constraint (a full site with Postgres runs at 105–290 MB on the shared
  host; the July proof ran on a Nanode). Disk is: 25 GB less ~5 GB system
  leaves ~18 GB for mail and Drive, so site data gets its own 15 GB
  allowance with the same 80% banner and the move-to-your-own-Linode
  off-ramp (§12). The backup shelf stays at 10 GB; chains compress well
  below live size.

## 11. Build order

1. B1 login fix (admin email + sealed password through the install job) —
   independent of everything else and owed on today's path too.
2. Operator hosting mode in the provisioner.
3. Mail leg (subaccount, sender domain, SMTP user, settings push, webhook
   meter, rolling-limit job).
4. Backup leg: per-run prefix-pinned key minting in the fleet dispatch,
   plane-side size meter. No node-side work.
5. Trial row, Stripe subscription linkage, banners, shutdown action.
6. Live gate on a fresh install, then the purchase-path verification campaign.

## 12. Limits and cost per user

Prices verified 2026-09-06 unless marked: SMTP2GO Professional $75/mo for
100k emails ($0.00075 each; overage $0.85 per 1,000 up to 3× the plan);
Starter $10/mo for 10k ($1 per 1,000 overage). B2 $6.95/TB/mo, first 10 GB
free, egress free up to 3× stored. Linode outbound transfer pooled across the
account, overage $0.005/GB. Linode Nanode 1 GB $5/mo with 1 TB transfer —
published shared-plan price, not re-fetched (the pricing page did not render).

### Allowances — one tier, no larger one

| Resource | Allowance | When exceeded |
|---|---|---|
| Instance | 1 GB Nanode, 25 GB disk | Move to your own Linode (§7) |
| Site data on disk | 15 GB (mail + Drive + database) | Banner at 80%; move to your own Linode (§7) |
| Sends per day | 100 (≈3,000/mo) | Own SMTP2GO account (§7) |
| Backup shelf | 10 GB | Own bucket (§7) |
| Outbound transfer | 250 GB/mo | Shutdown until the month rolls; move offer |
| Complaint rate | 0.1% over 7 days, min 100 sends | Sending removed; abuse path |
| Bounce rate | 5% over 7 days, min 100 sends | Same |

A personal user sends well under 20 a day; 100 is generous and still small
enough that a spammer cannot do damage before the first day's cap lands.

### Cost per user per month

| Line | At every cap | Typical |
|---|---|---|
| Compute | $5.00 | $5.00 |
| Email | 3,000 × $0.00075 = $2.25 | 600 × $0.00075 = $0.45 |
| Backup shelf | 10 GB × $0.00695 = $0.07 | 2 GB = $0.01 |
| Transfer, within the account pool | $0.00 | $0.00 |
| Transfer, worst case if the pool is exhausted | 250 GB × $0.005 = $1.25 | — |
| Domain (one-time on its own line, ≈$12/yr) | — | — |
| **Total** | **$7.32** (**$8.57** with the pool exhausted) | **$5.46** |

Typical assumes 20 sends a day, a 2 GB shelf and 20 GB of transfer.

Fixed overhead not in the per-user lines: the SMTP2GO plan itself. Starter
($10) covers three users at their cap or about 16 typical users; Professional
($75) covers 33 at their cap or about 160 typical. Divide the plan fee by the
live customer count for the true per-user figure.
No startup-credit programme exists at SMTP2GO (checked 2026-09-06). Two
discounts do: the **MSP partner programme** cuts our master-account bill by
5% from the start, 10% at 10 clients, 15% at 15 and 20% at 20 (subaccounts
count as clients), and a **reseller programme** with unpublished terms by
booked meeting. The affiliate programme is separate — it pays on customers
who leave for their own SMTP2GO account, i.e. the off-ramp — and the two
coexist.

## 13. Remaining items (2026-09-06)

### Decisions — all taken 2026-09-06

- **D1. Trial length: 60 days.** Cost ≈ $11 per signup, covered by the
  $39.99 setup fee.
- **D2. Relay topology: bring-your-own VPS only.** The hosted tier is single
  server. A buyer who wants the relay supplies their own Linode for it (the
  existing RelayCloudProvisioner path against their account); the plane never
  pays for a second instance.
- **D3. One trial per person: not needed.** The $39.99 setup fee is the
  dedupe — a repeat trial is a repeat sale.
- **D4. Email step green keeps the human "It arrived" click.** Provisioning
  sends the test message to the buyer's address so the click is all that is
  left.

### Engineering — all settled 2026-09-06

- **E1. Withdrawn (2026-09-06).** I had written that the fleet scheduler
  would archive customer boxes to our shelf under our key. Wrong: the
  manager profile seals to the node's own recovery key and refuses key
  material from the plane (`BackupRunner::plan_manager`, `docs/backups.md`).
  The fleet backing up hosted nodes is the design, and §4.5 now builds on
  it. What remains of E1 is the per-run prefix-pinned credential replacing
  the target's one shared write-only key.
- ~~E2. SMTP2GO webhooks are unsigned.~~ **Settled 2026-09-06.** Only URL
  basic-auth and an IP allowlist (`webhooks.smtp2go.com`) exist, so the
  plane's endpoint requires both; webhook counts drive banners and the
  complaint/bounce thresholds only; the daily rolling limit reads the
  email-history API figure, never the webhook tally. A spoofed or dropped
  webhook can move a banner, never a cap.
- ~~E3. Linode driver.~~ **Settled 2026-09-06.** Add `shutdownInstance`,
  `bootInstance` and `getTransfer` to `CloudComputeProvider` and
  `LinodeComputeDriver` (`POST …/shutdown`, `POST …/boot`,
  `GET …/transfer`). The plane's lever is shutdown; `deleteInstance` stays
  as is — its only caller is `RelayCloudProvisioner` cleaning up a relay
  run that never came up, and the hosted path never calls it.
- ~~E4. Disk signal.~~ **Settled 2026-09-06: a health document on every
  Joinery site.** A small endpoint reporting disk, memory and version,
  guarded by a per-node token in its URL and stored as
  `mgn_health_check_url`; `RunNodeUptimeChecks` already reads such documents
  each tick and `NodeHealthProbe` already folds `disk_usage_percent` onto
  the node. No jobs; the disk banner is another folded figure under the 80%
  rule in §6.
- ~~E5. Subscription linkage.~~ **Settled 2026-09-06.** The store's Stripe
  webhook already marks the order item `past_due` / `canceled` and
  broadcasts `subscription.payment_failed`, `subscription.cancelled`,
  `subscription.expired` and `subscription.started` on `SignalBus` with the
  order item id. Server Manager declares `signalSubscribers` in its
  `plugin.json`: payment_failed starts the 30-day grace clock on the
  provision found by `cvp_external_order_item_id`; cancelled/expired end
  hosting; started clears a recovered grace. No Stripe code in the plane.
  Depends on E6: the provision's order item IS the subscription line.
- ~~E6. Cart shape.~~ **Decided 2026-09-06: one product.** The $9.99/mo
  subscription with a 60-day trial (`prv_trial_period_days`) carries the
  `customer_cloud` fulfilment, so the provision points at the subscription
  line; the $39.99 setup is a one-time line in the same cart (Stripe only —
  mixed carts already exclude PayPal). **Verify first in test mode on dev:**
  a subscription-mode Checkout session with a trial still charges the
  one-time setup line at signup. Product 9 on getjoinery is reshaped, not
  duplicated.
- ~~E7. Showing the admin password once.~~ **Settled 2026-09-06: reveal
  once, then erase.** The buyer's `/profile/server_manager` page becomes
  "your sites" (the Connect section renders only for bring-your-own
  provisions). A Reveal button unseals `cvp_admin_pass_sealed`, shows it,
  and erases it in the same request. No node report is needed: the site
  forces a password change on first login, and once the mail leg is done
  the site's own forgot-password covers a lost reveal.

### Owner account setup owed

- SMTP2GO: account (free tier to build against), master API key, MSP
  programme enrolment, affiliate enrolment via PartnerStack for the
  referral link.
- Linode: a personal access token scoped `linodes:read_write` on our
  account, sealed into the setting.
- B2: a bucket for customers and a master key able to create keys
  (`writeKeys`, `listKeys`, `deleteKeys`), sealed into the setting.
- Namecheap: API eligibility ($50 balance, 20 domains or $50 spent), the
  plane's IP allowlisted, sandbox rehearsal, then the managed-domain
  requirement attached to the product on getjoinery.
- `automatic_install_mail_topology.md` §8 defaults, if D2 keeps the relay.

## Appendix A — outbound provider comparison (verified 2026-09-06)

| Provider | Per-customer unit | Hard cap | Daily cap | Self-serve plan | Affiliate |
|---|---|---|---|---|---|
| **SMTP2GO** (chosen) | Subaccount, every paid plan | Monthly `limit`, +10% overrun; daily by rolling the limit | Via the plane's daily roll | Starter $10/10k, Professional $75/100k | 20% recurring, 12 months |
| Elastic Email | Subaccount | Monthly credits and a daily send limit | Yes, native | Self-serve | 20–25% recurring, ≤6 months |
| SendGrid | Subuser, Pro plan, **15 max** (more by support ticket only) | Credits with daily/weekly/monthly reset, by API | Yes, native | Pro | Twilio partner referral only |
| Mailgun | Domain sending key (identity only); subaccounts enterprise-contract only | Monthly, subaccounts only | No | Free/Basic $15/Foundation $35/Scale $90 | Via Sovrn, rate unpublished |
| Amazon SES | Tenant (Aug 2025) | None — reputation pause only | No | Any | None |

SMTP2GO won on: a per-customer cap on every paid plan, `subaccount_id` on the
domain, SMTP-user and edit endpoints, a daily cap achievable without the plane
in the send path, the platform's existing generic SMTP provider (no new
driver), and the best-paying off-ramp.
