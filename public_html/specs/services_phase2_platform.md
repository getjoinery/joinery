# Joinery-run services, phase 2 — mail and the backup shelf for self-hosted sites, against a date

**Status:** Draft, 2026-09-19. Phase 2 of `managed_hosting_and_services.md`
(the umbrella; read its phase map and contract C2 first). Absorbs the
shelved self-hoster backup design that was `managed_backups.md`.

**What this phase delivers.** A self-hosted site can use our outbound mail
and our backup shelf, on credentials cut to its own slice, for as long as a
paid-through date on the plane says so, and loses them on a ladder when the
date passes. **No store involvement at all:** in this phase the date is set
by hand on an admin page. That is what lets the services run for beta
testers before pricing exists, and what keeps this phase small.

**Owner decisions, 2026-09-17:** Joinery's outbound mail and backup
accounts are offered to self-hosters; the keys stay ours (§1). No free
trial (§8). A warm-up ramp on new subaccounts is rejected (§8).

**Depends on:** nothing unbuilt; independent of phase 1. **Feeds:** phase 3,
through contract C2.

**Companions:** `implemented/hosted_trial_provisioning.md` (the Managed
legs this reuses), `implemented/mailbox_relay_shared_fleet.md` (the
tenant-side enrolment shape), `managed_backup_recovery.md` (recovery-key
custody, deliberately separate), `hosted_bounce_handling.md` (the webhook),
`smtp2go_affiliate_signup.md` (the referral link the first door needs).

---

The Managed site uses these services because that is what Managed is, and
its legs are built. A self-hosted site may use them to get through the
wizard without opening two more accounts. One design serves both; the
difference is only how the credential reaches the box.

## 1. Keeping control of the keys

The rules, all inherited from the hosted spec's doctrine and already
enforced on the Managed path:

- **Master keys never leave the management node.** The SMTP2GO master key,
  the B2 master key and the registrar credential are sealed settings on the
  plane. No install, Managed or self-hosted, ever holds one.
- **What reaches a box is cut to that customer's own slice.** A credential
  that can name only the customer's own domain or the customer's own
  prefix, and nothing else. A leaked credential exposes one customer's own
  service, and is revoked with one call from the plane.
- **The box owner can read anything on the box.** Design for it: every
  credential on a box is one the owner is allowed to have.
- **Enforcement lives at the provider or on the plane**, never in a setting
  on the box, which the owner can edit. The provider counts sends; the plane
  meters bytes, revokes keys and prunes.
- **The bytes we store we cannot read.** Backups seal to the customer's
  recovery key before upload; the shelf is ciphertext to us.

## 2. Outbound mail — one SMTP2GO subaccount per customer

Built for Managed (`ProvisionHostedMail`); the same leg serves a self-hosted
tenant with one branch. The plane creates a subaccount with the master key,
sets the monthly `limit` from the allowance setting, adds the sender domain
under it, and creates **one SMTP user inside that subaccount**. That
username and password is the whole credential the box gets. It can send as
the customer's domain and no other; the subaccount counts its sends and the
provider refuses past the limit. Kill switch: close the subaccount.

The one branch: a Managed box receives the SMTP settings over the agent
channel (`hosted_mail_settings`); a self-hosted box has no channel, so the
enrol call **returns** them and the site writes its own `email_service`,
host, port, user and password (§4). The DKIM, return-path and tracking
records come back the same way and the wizard's existing DNS publish box
publishes them; a Managed site has them published by the domain leg.

Sending identity is the customer's own domain in both cases. A sender domain
that never verifies (records not published within the provider's window)
leaves the tenant's mail row at `domain_added` and the wizard step amber,
exactly as the Managed leg reports it; nothing sends until it verifies,
which is also the abuse gate: you send as a domain whose DNS you control.

## 3. The backup shelf — a prefix-pinned key, and two ways to hand it over

The shelf is one B2 bucket of ours, one prefix per customer,
`{slug}/`. Every backup is sealed to the site's own recovery key before it
leaves the box (`BackupRunner` hard-codes it), so we store ciphertext, and
the recovery ceremony stays mandatory: a site with no proven key refuses to
back up at all, and the wizard step says the backups start when the key is
created.

**Managed: no standing key, built.** Backups are the fleet manager profile,
dispatched from the plane. At job pickup the plane mints a B2 application
key pinned to the prefix, `writeFiles` only, with a lifetime derived from the
job's timeout, and the agent holds it for that run alone. Restores use
presigned links from the plane's master credential. Ships off until the B2
master key is replaced by one that can create keys (`writeKeys`).

**Self-hosted: a standing scoped key.** The box runs its own backups on its
own schedule, so there is no plane-run job to mint into. Enrolment mints a
key pinned to the prefix, `writeFiles` only — no `listFiles`, no
`readFiles`, no `deleteFiles` — and returns it; the site writes an ordinary
target row with provider `managed` (behaves as `b2` for the engine; the
Backups page renders it locked, as a service, with the figure). What the
key can do if it leaks: write junk into one prefix. What it cannot: read a
backup, list one, delete one, touch another customer. Revocation is
`b2_delete_key` from the plane. The alternative — the box calls the plane
each run for a short-lived key — was considered and dropped: the box would
need a standing credential to make that call, so it trades one standing
key for another and adds a dependency on the plane at every run.

The site cannot prune its own shelf (write-only), so **the plane prunes
every tenant** from the delete-capable side, chains whole, to a fixed
retention that is a property of the service, not a tenant setting. The
prune pass sums the prefix; that figure is the meter.

## 4. Enrolment — how a self-hosted site gets a credential

The shape is the mailbox fleet's, already shipped: the tenant holds a
getjoinery account and one of its API keys; the tenant calls enrol, status
and release actions on getjoinery; the operator side keeps one row per
tenant per service. The shelved backup spec's first build item — lift that
tenant-to-operator pattern out of the mailbox plugin into core so a second
and third service sit on it — stands, and is item 1 of §10: a client class
(API-key auth against a service URL, the `FleetClient::call` shape), an
operator-side service base (qualification check, enrol / status / release,
a slot row with `provisioning | active | suspended | released`), and the
grace-lapse reconcile. The mailbox fleet migrates onto it; mail and backups
are its second and third consumers. Backups are core, so the tenant side of
that one lives in core, not a plugin.

**The account link.** A self-hosted site needs a getjoinery account, and
the site has to be bound to it once. Nobody types a key. Two ways in:

- **Born through getjoinery** (Managed, or the buyer's own cloud from the
  configure page): the plane mints the buyer's key and seeds it over the
  agent channel with the `fleet_enroll` primitive — the existing
  customer-cloud pattern (`FleetProvisionSeeding`), no new work.
- **Born anywhere else** (the StackScript, a plain install): the wizard
  step shows one **Connect your getjoinery account** button. The browser
  goes to an authorise page on getjoinery carrying the site's identity and
  its return address; the owner signs in or signs up there (the existing
  side-by-side start page shape), approves *link this site*, and
  getjoinery mints a key against their account and returns it to the site
  over the redirect. The site stores the key pair sealed, and the step is
  now the same one-click Enrol a seeded site shows. Someone with no
  getjoinery account yet is covered, which a paste never was.

The authorise flow, owner decision 2026-09-20:

- **The site starts it.** The step's button is a POST that mints a
  single-use state (`OAuth2State`, the platform's own carrier) binding the
  return address to this browser session, then redirects to
  `{services_url}/services/authorize?site={host}&return={url}&state={s}`.
  The return address must be the site's own origin (`webDir`), so a
  crafted link cannot send a key elsewhere.
- **getjoinery asks once.** Signed out, the authorise page routes through
  the start page and comes back. Signed in, it names the site and the
  account and takes one click. It mints an `ApiKey` named for the site
  (`Services: {host}`, read + write, no delete — the seeding's own
  permission), one active per site per account: connecting again
  deactivates the earlier key for that site rather than leaving two.
- **The key returns in the redirect, once.** The redirect carries the
  public key, the secret and the state; the site verifies the state,
  seals the pair into `server_manager_services_api_public_key` /
  `_secret_key`, and the secret is never shown on either side. A redirect
  that lands twice, or with a spent state, is refused and the owner told
  to connect again. The secret is in one URL for one hop over TLS, the same
  exposure as every OAuth code exchange the platform already performs.
- **Re-connect is always allowed** and is how a lost or rotated key is
  replaced: the old key goes inactive when the new one is minted.
- **A connected site names its account.** The wizard step and the Backups
  page show which getjoinery account the site is linked to, with
  *Disconnect* (deactivates the key on getjoinery through release, then
  clears the pair). Disconnecting a site with an active service is
  refused with the sentence saying to release the service first.

**Enrol, per service:**

| Service | Operator side creates | Returns to the site | Site writes |
|---|---|---|---|
| Mail | subaccount, limit, sender domain, SMTP user | host, port, user, password, the DNS records | the send settings; the records go to the publish box |
| Backups | slug, the standing prefix-pinned write-only key | endpoint, bucket, prefix, key id + secret, retention | a `managed` target row, `backup_target_id` |

Idempotent on the tenant row: a second enrol returns the same coordinates.

**Status** returns, per service: the figure (sent this month of the
allowance; bytes on the shelf of the allowance), the paid-through date, the
state, and the notice sentence if any — the response is contract C2 in the
umbrella. The site polls it daily (a scheduled task, like the fleet status
check) and on the wizard step's load, and writes the figures into the five
banner settings (§9).

**The tenant row is created on first contact.** A site's first enrol call,
for either service, creates its tenant rows at `unpaid` with no date and
returns *not entitled*; nothing is minted. What sets the date in this phase
is the **grant action** on the plane's Service Tenants admin page (§10 item
4): an operator picks a tenant, a service and a date, and the next status
poll or enrol call sees it. Phase 3 replaces the hand with a payment that
writes the same column; nothing else changes.

**Release** is the customer's action (§9, the switch-over) or the
reconcile's (§5): close the subaccount or revoke the key, and start the
retention clock.

## 5. Enforcement and the lapse ladder

| Limit | Meter | At 80% | At 100% | Lever |
|---|---|---|---|---|
| Sends, 1,000/mo | the provider, against the subaccount limit; the webhook feeds the figure | banner + one email | the provider refuses (+10%) until the month rolls; the site's send log shows the refusals | the `limit` |
| Shelf, 10 GB | the prune pass sums the prefix | banner + one email | no new runs are accepted: Managed stops minting; self-hosted has its key revoked after `server_manager_services_grace_days` of sustained overage, with the banner saying exactly why uploads stopped | mint / revoke |

A stopped upload path is never quiet: the site keeps taking local backups,
the run history names the cause, and the wizard step is red with the
sentence.

**Lapse** (the paid-through date passes — the reconcile compares the date
every pass, so no signal is needed): grace of
`server_manager_services_grace_days`, then the
credential is closed or revoked and the tenant told, then the shelf is
retained 90 days from revocation and pruned. Re-qualifying at any point
before the prune reactivates in place. The 90 days is a customer-facing
promise and appears in the copy.

## 6. Data, operator side

- `svt_service_tenants`: user, site identity (the host, as
  `MarketplaceClient::site_identity()` reports it), service (`mail` |
  `shelf`), slug, state, provider ids (subaccount / domain / SMTP user, or
  key id), `svt_allowance` (the plan's allowance for this service,
  snapshotted each reconcile), `svt_paid_until` (the date the tenant is paid
  through; null on a Managed tenant, whose hosting is the entitlement),
  figure + measured time, grace-ends time, revoked time, prune-after time.
  In this phase the date is written by the grant action; in phase 3 by a
  payment. Managed provisions get no tenant row in this phase; their legs
  are the built ones.
- Nothing new on the site beyond the sealed settings in §9 and the
  `managed` target provider.

## 7. Tests (from the absorbed backup spec, plus mail)

- Enrol → row written, key scoped to the tenant prefix; a second tenant's
  key cannot read or write the first's objects.
- Write-only proof: the tenant key cannot delete or list outside — or
  inside — its prefix; refusal asserted, not assumed.
- Metering: bytes aggregate per tenant from a listing; overage flags at the
  allowance.
- Pruning: chains deleted whole, never a full out from under its
  incrementals; families aged independently.
- The ladder: grace → revocation → retained → pruned, and reactivation at
  every stage before the prune.
- Mail: enrol returns one SMTP user inside the tenant's subaccount; release
  closes the subaccount; a second enrol is idempotent.
- Site side: the `managed` target renders locked; the status figure
  populates; a revoked key surfaces as a failed-run cause, not silence.

## 8. Abuse

The only service with abuse value is outbound mail: the shelf is write-only
ciphertext with no share or download path, and a spammer gains nothing from
it. The defense for mail is three things and no more:

1. **A real charge before any sending.** There is no free start; the first
   thing a self-hosted tenant does is pay, which is a card that cleared
   Stripe's checks and a chargeback risk a throwaway card cannot carry at
   scale. The amount matters less than the charge.
2. **A verified sending domain.** Nothing sends until the DKIM and
   return-path records are published under DNS the customer controls
   (§2), and their own domain signs every message.
3. **The provider's own controls** on the subaccount, plus the plan's
   monthly limit, which the provider enforces.

A warm-up ramp on new subaccounts was considered and rejected by the owner
(2026-09-17): 1,000 sends a month is not a spam volume, and the ramp is
complexity that protects nothing at that size. There is no platform-side
bounce or complaint enforcement, per the 2026-09-06 decision.


## 9. The site side

- **The wizard's Email and Backups steps** each offer *use getjoinery's
  account* beside the existing providers. An unlinked site shows the
  Connect button (§4) in that slot; a linked one calls enrol, and on
  *entitled* writes what §4's table says. On *not entitled*
  it says so and links to the site's page on getjoinery (in this phase that
  page shows the date and nothing to buy; phase 3 adds the card).
- **The `managed` backup target provider:** behaves as `b2` for the engine;
  the Backups page renders it locked, as a service, with the figure and the
  date, no editable credential fields.
- **The daily status poll:** a scheduled task calls status and writes the
  figures, the date and the notice into the five banner settings
  (`hosted_plan_state = services`, `hosted_plan_until_time`,
  `hosted_plan_notice`, `hosted_plan_allowances`, `hosted_plan_manage_url`),
  so `HostedPlanNotice` renders them exactly as it renders a Managed site's
  pushed values. The `services` state is added to the renderer: the date,
  the allowance rows and the manage link; no billing sentence. Each
  allowance row at 80% carries the **first door** — the referral link from
  the existing setting — and nothing else in this phase.
- **The switch-over forms**, which lose nothing:
  - *Mail.* The Email step's form takes the customer's own provider
    credentials, as it does today for any provider. The site sends the test
    message through the new provider; on the human *It arrived* click the
    site calls release, the plane closes the subaccount, and the
    `mail.<domain>` records the plane published are listed for the customer
    to replace. The new provider is proven before the old one is closed.
  - *Backups.* The Backups step's own-target form writes a second target;
    both run side by side. Once the customer's own target has a verified
    chain, the step offers *stop using getjoinery's shelf*, which calls
    release; the shelf is kept 90 days from that day, and the step says so.
    Nothing is deleted while the customer has only one copy.
- **Settings** on the site: `server_manager_services_url` (default
  `https://getjoinery.com`), `server_manager_services_api_public_key` /
  `_secret_key` (sealed), `server_manager_services_account` (the account's
  display, written by the connect return, shown on the step). On the plane: `server_manager_services_grace_days`
  (14). The allowances and the referral links are the existing settings.

## 10. Build order

1. The enrolment skeleton lifted from the mailbox plugin into core: a
   client class (API-key auth against a service URL, the `FleetClient::call`
   shape), an operator-side service base (entitlement check, enrol / status
   / release, a slot row with `provisioning | active | suspended |
   released`), and the grace-lapse reconcile shape. The mailbox fleet
   migrates onto it (E1).
2. Operator side: the tenant row (§6), the enrol / status / release actions
   on the skeleton, the B2 standing-key mint and revoke through the client
   that already exists for per-run keys, the SMTP2GO subaccount leg reused
   from `ProvisionHostedMail` with the node-less branch.
3. The reconcile and metering as phases of `ServerManagerAdvanceProvisioning`
   (§5): prefix sums, limit re-set, the ladder, the retention prune.
4. The authorise page on the plane (`/services/authorize`, §4): the
   start-page routing for a signed-out visitor, the one-click approval,
   the key mint (one active per site per account), the redirect back.
   The site side of the link — the state, the return check, the sealed
   write, the account display, Disconnect — is part of item 6.
5. The Service Tenants admin page (`/admin/server_manager/service_tenants`):
   the list with state, figure and date; the grant action (tenant, service,
   date); release; and the ladder's timestamps. The page is also where the
   Managed-included case is invisible, since no tenant row exists for it.
6. Site side (§9): the Connect button and the return handler, the wizard
   choice, the `managed` target, the status poll, the `services` banner
   state, the switch-over forms.
7. Docs: `plugins/server_manager/docs/overview.md` (the services),
   `docs/backups.md` (the `managed` provider), `docs/email_system.md` (the
   getjoinery send choice), `plugins/mailbox/docs/overview.md` (the fleet on
   the skeleton).
8. Tests: §7, plus the skeleton's own suite and the fleet's suite green on
   the skeleton, and the link: a spent state refused, a return address off
   the site's origin refused, a second connect deactivating the first key.

## 11. Verification

1. A fresh StackScript box against dev as the operator: Connect from the
   Email step with no dev account yet, sign up on the way, land back
   linked; try Email → *not entitled*; grant a date on the admin page; enrol mail, send
   the test message through the subaccount, publish the records, see the
   figure on the banner.
2. Enrol backups, take a chain; prove from the operator side that the
   tenant key cannot list, read or delete, inside or outside its prefix.
3. Move the date back on the admin page and watch the ladder: grace, the
   banner urgent, revocation, the site's run history naming the cause,
   local backups continuing, the shelf retained, then pruned. Grant again
   before the prune and see it reactivate in place.
4. The switch-over for each service: nothing lost, the old closed only
   after the new is proven.
5. The fleet's own live check still passes on the skeleton.

## 12. Out of scope

- Selling the services and the second door of the nudge (phase 3).
- Tenant rows for Managed sites. Their legs are built; unifying them onto
  tenant rows is a later cleanup, not a requirement.
- Recovery-key custody for the shelf (`managed_backup_recovery.md`).
  Enrolment says in one sentence that we cannot open a backup and cannot
  help if the recovery key is lost.

## 13. Open

- **Q4. Resolved 2026-09-20:** the Connect button (§4). No key field on
  the public StackScript deploy form, and no paste anywhere.
- **E1. The enrolment skeleton's exact seam** — what moves from
  `FleetClient` / `FleetService` into core and what the mailbox fleet keeps.
  Read both before item 1 of §10.
- **E2. The `managed` target provider** on `BackupTarget`: confirm the
  engine's `b2` path needs nothing beyond the endpoint and that the Backups
  page can render a locked target.
- **E3. B2 verification items** (carried): a `namePrefix` key is honoured
  over the S3-compatible endpoint the engine uses; the account's application
  key ceiling; whether expired keys are reaped. Standing keys make the
  ceiling matter more than per-run keys did.
- **E4. The webhook figure for a self-hosted tenant:** SMTP2GO webhooks are
  per sending credential and carry `auth`; the one endpoint on the plane
  already meters Managed subaccounts, so a tenant's SMTP user needs its
  `auth` registered the same way.
- **E5. Site identity.** A tenant is keyed by the site's host. A site that
  changes its domain must re-enrol or carry the row; decide at build.
