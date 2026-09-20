# Joinery-run services, phase 2 — mail and the backup shelf for self-hosted sites, against a date

**Status:** Ready for an executor, 2026-09-20 — code-checked, every open
item decided (§13–§15).
Phase 2 of `managed_hosting_and_services.md` (the umbrella; read its phase
map and contract C2 first). Absorbs the shelved self-hoster backup design
that was `managed_backups.md`. The shelf is **brokered** (§3): no box ever
holds a storage credential, so the shelf provider is any S3-compatible
store.

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
- **What reaches a box is cut to that customer's own slice.** For mail, a
  credential that can send as the customer's own domain and no other. For
  the shelf, no storage credential at all: one presigned URL per object,
  each naming one key inside the customer's own prefix, signed by the plane
  for minutes. A leaked connected key exposes one customer's own slice and
  dies the moment the account holder disconnects the site.
- **The box owner can read anything on the box.** Design for it: every
  credential on a box is one the owner is allowed to have.
- **Enforcement lives at the provider or on the plane**, never in a setting
  on the box, which the owner can edit. The provider counts sends; the plane
  signs every upload, keeps the ledger of what it signed, and prunes.
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

## 3. The backup shelf — brokered: no storage credential on any box

The shelf is one bucket of ours, one prefix per customer, `{slug}/`, on
whichever S3-compatible store the plane's shelf target row names — B2
today, Linode or S3 tomorrow, by editing that one row. Every backup is
sealed to the site's own recovery key before it leaves the box
(`BackupRunner` hard-codes it), so we store ciphertext, and the recovery
ceremony stays mandatory: a site with no proven key refuses to back up at
all, and the wizard step says the backups start when the key is created.

**The one idea (owner decision D3, 2026-09-20).** The box never holds a
storage credential. For every object it writes or reads it asks the plane
for a **presigned URL**: one HTTP request, to one object key, for one
operation, valid for minutes, signed with the plane's master credential.
Presigned URLs are the S3 standard, honoured identically by Backblaze, AWS
and Linode, so the design is cross-provider by construction and nothing is
minted, counted or reaped. The plane signs only what the tenant may do and
**never signs a delete**.

**The broker** is five actions on the plane, called by a self-hosted site
over its connected key (§4) and by a Managed node's backup run over a
per-run token the job carries (below):

| Action | The site sends | The plane checks, then answers |
|---|---|---|
| `shelf_begin_run` | profile, chain id, the artifacts with names and sizes | tenant active, date not passed, ledger bytes + declared sizes within the allowance; answers a run id and the run's base key, or **refuses with the sentence** the run records as its cause |
| `shelf_sign` | run id, object name, operation (`put`, `get`, `multipart_create`, `multipart_parts` with a range, `multipart_complete` with the part list) | the key is inside the run's base key; answers the URL(s), each good for one hour; parts in batches of ten |
| `shelf_list` | a prefix under the tenant's own | lists with the master credential, answers from inside the tenant's prefix only |
| `shelf_finish_run` | run id, the objects it completed | closes the run; the ledger marks them complete |
| `shelf_status` | — | the figure, the allowance, the date, the state (the same C2 fields the status action carries) |

**The ledger.** The plane records every object it signs — tenant, key,
bytes, chain, signed and completed times — so the meter is exact and
immediate: no listing pass is needed to say a tenant is at 6.4 GB, and a
run that would cross the allowance is refused before a byte moves. The
prune pass reconciles the ledger against a listing (an object signed but
never completed is aborted on the plane's side and dropped from the
ledger; an unfinished multipart is aborted the same way — the plane's own
call, not a delete the box could make).

**Two ways a box reaches the broker.**

- *Self-hosted*: the connected key, over the services client (§4). The
  earlier objection to "the box calls the plane each run" — that the box
  would need a standing credential to do it — is answered by Connect: it
  already has one, and that one credential now covers mail, the shelf and
  the status poll.
- *Managed*: the plane's own backup job on the node carries a per-run
  token instead of a per-run storage key; the node's run presents it to
  the broker. `AgentChannelEndpoint::mint_run_credentials()` and the
  per-run B2 key mint are retired; `bkt_mint_run_keys` and the node
  credential on the fleet target go with them, since no node is handed a
  storage credential any more.

**What the site keeps.** Everything it does with a target today, through
the broker: the nightly upload (small artifacts by one `put`; the streamed
files archive as a multipart upload, part URLs in batches, bytes straight
from the box to storage, never through the plane), the Sunday verify
(`shelf_list` then a `get` URL per object), a rehearsal restore (a `get`
URL — restores already take a presigned link on stdin and refuse a bucket
credential), and the Test button (`shelf_status`). What it loses is
pruning, which it never should have had: the plane prunes every tenant,
chains whole, to a retention that is a property of the service.

**When the plane cannot be reached** the run fails at `shelf_begin_run`
with that as its recorded cause, the local backup is still taken, and the
next night tries again. A plane outage costs one night's off-site copy,
never a backup.

**What each provider has to support:** SigV4 presigned URLs for PUT, GET,
and the three multipart calls, plus list and delete from the plane's own
credential. All three do. The one per-provider item is the master
credential the plane's shelf target row holds, which must be able to
list, read, write and delete in its bucket — a plain full-access key on
any of them, no key-management capability needed.

**Rejected, 2026-09-20.** A standing storage key scoped to the tenant's
prefix (add, list, read, never delete): B2 can express it and Linode
cannot (its limited keys are per bucket, read-only or read-write, and
read-write includes delete), and a design that only one provider can
honour is not one to build into an open storage decision. Write-only was
rejected before that, because the site verifies and rehearses with its
target credential.

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
| Backups | slug, prefix, the tenant row | slug, prefix, retention | a `managed` target row with **no credentials** (the broker is the services url), `backup_target_id` |

Idempotent on the tenant row: a second enrol returns the same coordinates,
and since nothing is minted there is nothing to replace after a lapse — a
re-granted tenant's next `shelf_begin_run` simply succeeds.

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
reconcile's (§5): close the subaccount, or mark the shelf tenant released
so the broker refuses it, and start the retention clock.

## 5. Enforcement and the lapse ladder

| Limit | Meter | At 80% | At 100% | Lever |
|---|---|---|---|---|
| Sends, 1,000/mo | the provider, against the subaccount limit; the webhook feeds the figure | banner + one email | the provider refuses (+10%) until the month rolls; the site's send log shows the refusals | the `limit` |
| Shelf, 10 GB | the ledger (§3), reconciled against a listing by the prune pass | banner + one email | `shelf_begin_run` refuses any run whose declared size would cross the allowance, with the sentence the run records and the banner shows; the site's local backups continue | the broker refuses |

A stopped upload path is never quiet: the site keeps taking local backups,
the run history names the cause, and the wizard step is red with the
sentence.

**Lapse** (the paid-through date passes — the reconcile compares the date
every pass, so no signal is needed): grace of
`server_manager_services_grace_days`, then mail's subaccount is closed and
the broker refuses the shelf tenant, and the tenant is told; then the
shelf is retained 90 days from that day and pruned. Re-qualifying at any
point before the prune reactivates in place — for the shelf that is a
state change on the row and nothing else. The 90 days is a customer-facing
promise and appears in the copy.

## 6. Data, operator side

- `svt_service_tenants`: user, the connected key (`svt_apk_api_key_id`,
  the site's identity — see §4 and E5), the host as the site last reported
  it (a label, refreshed on every status call, never a key), service
  (`mail` | `shelf`), slug, state, provider ids (subaccount / domain / SMTP user, or
  key id), `svt_allowance` (the plan's allowance for this service,
  snapshotted each reconcile), `svt_paid_until` (the date the tenant is paid
  through; null on a Managed tenant, whose hosting is the entitlement),
  figure + measured time, grace-ends time, revoked time, prune-after time.
  In this phase the date is written by the grant action; in phase 3 by a
  payment.
- `svo_shelf_objects`, the ledger (§3): tenant, run id, object key, bytes,
  chain, signed time, completed time. The figure on the shelf tenant row
  is the sum of its completed rows; the prune pass reconciles it against a
  listing and deletes rows with the objects they name. Managed provisions get no tenant row in this phase; their legs
  are the built ones.
- Nothing new on the site beyond the sealed settings in §9 and the
  `managed` target provider.

## 7. Tests (from the absorbed backup spec, plus mail)

- Enrol → row written; the `managed` target row on the site carries no
  credential and the runner's destination accepts that.
- The broker refuses, each asserted: a key outside the tenant's prefix, a
  key outside the run's base key, any delete, a run over the allowance, a
  lapsed or released tenant, a spent or unknown run id, a token or key
  from another tenant. And signs, each asserted: put, get, the three
  multipart calls, list inside the prefix.
- The brokered store passes the runner's own upload suite against the S3
  fixture (`tests/lib/s3_fixtures.php`), single-put and multipart, and the
  verify launcher lists and reads through it.
- Metering: the ledger is the figure; the reconcile's listing agrees with
  it, and an object signed but never completed is dropped, not counted.
- Pruning: chains deleted whole, never a full out from under its
  incrementals; families aged independently.
- The ladder: grace → revocation → retained → pruned, and reactivation at
  every stage before the prune.
- Mail: enrol returns one SMTP user inside the tenant's subaccount; release
  closes the subaccount; a second enrol is idempotent.
- Site side: the `managed` target renders locked; the status figure
  populates; a broker refusal surfaces as the run's recorded cause, not
  silence; an unreachable plane likewise.

## 8. Abuse

The only service with abuse value is outbound mail: the shelf holds
ciphertext sealed to the customer's own key, written only through URLs the
plane signed inside one prefix, with nothing on any box that can delete or
share, and a spammer gains nothing from it. The defense for mail is three things and no more:

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
- **The `managed` backup target provider:** a target row with no
  credentials, served by the brokered object store (§3, §14): every put,
  get and list the engine makes for it goes through the broker over the
  connected key. The Backups page renders it locked, as a service, with the
  figure and the date, no credential fields; its Test button asks
  `shelf_status`.
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
   on the skeleton, the SMTP2GO subaccount leg reused from
   `ProvisionHostedMail` with the node-less branch (`Smtp2GoLeg`, §14).
2a. The shelf broker (§3): the five actions, the ledger row, presigning
   for PUT, GET and the three multipart calls added to `S3Signer` beside
   `presign_get`, the per-run token for Managed jobs in place of
   `mint_run_credentials`, and the retirement of the per-run key mint, the
   node credential and `bkt_mint_run_keys`. The credential check (E9): the
   shelf target's Test exercises list, read, write and delete; the hosted
   card's `ready` reads one SMTP2GO probe.
2b. The object-store seam in the engine: one interface (`put_file`,
   `put_stream`, `get`, `get_to_file`, `list`) with a direct implementation
   (today's `S3Signer` calls with a credential) and a brokered one (asks
   the broker, performs the HTTP itself). `BackupRunner`,
   `BackupVerifyLauncher` and `TargetTester` call the interface; the
   `managed` provider selects the brokered one. **Lands after the
   streaming-upload work (`backup_streaming_upload.md`, built and at its stop
   point 2026-09-20, uncommitted) — never across it.** That build already
   routes every multipart call through the signer's one private
   `request()` → `attempt()` seam and reaches the signer only through
   `destination()` and `stream_engine()`, so 2b is a swap at that seam.
3. The reconcile and metering as phases of `ServerManagerAdvanceProvisioning`
   (§5): the ledger reconciled against a listing, limit re-set, the ladder,
   the retention prune.
4. The authorise page on the plane (`/services/authorize`, §4): the
   start-page routing for a signed-out visitor, the one-click approval,
   the key mint (one active per site per account), the redirect back.
   The site side of the link — the state, the return check, the sealed
   write, the account display, Disconnect — is part of item 6. Also the
   **Connected sites** list on the profile with Disconnect (E6).
5. The Service Tenants admin page (`/admin/server_manager/service_tenants`):
   the list with state, figure and date; the grant action (tenant, service,
   date); release; and the ladder's timestamps. The page is also where the
   Managed-included case is invisible, since no tenant row exists for it.
6. Site side (§9): the Connect button and the return handler, the wizard
   choice, the `managed` target on the brokered store, the status poll, the
   `services` banner state, the switch-over forms — which clear the dead
   SMTP settings after the new provider is proven and disable the `managed`
   target on shelf release (E8).
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
2. Enrol backups on a B2 shelf, take a chain (one artifact over the
   multipart threshold), run the site's own verify and a rehearsal
   restore; show the target row holds no credential; read the plane's
   ledger and see every signed key inside the prefix; ask the broker, as
   that tenant, for a delete and for a key outside the prefix and be
   refused. Then point the plane's shelf target row at a Linode bucket and
   repeat the chain: the cross-provider proof.
3. Move the date back on the admin page and watch the ladder: grace, the
   banner urgent, the broker refusing, the site's run history naming the cause,
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
- **E1. Resolved 2026-09-20** by the code check: §14 names what moves
  (`FleetClient::call` and the reconcile's grace ladder) and what stays.
  The tenant-side fleet surface is gated off today
  (`mailbox_hosted_relay_offered()` returns false, off for V1), so the
  migration touches no customer-facing path; §11.5 is the suite, not a
  live tenant.
- **E2. Resolved 2026-09-20** by the code check (§14) and D3 (§15): the
  engine is provider-blind and gains an object-store seam; a `managed` row
  carries no credential and the runner's destination accepts that; every
  site feature that read with the credential reads through the broker.
- **E3. Retired 2026-09-20** by D3: nothing is minted, so there is no key
  ceiling, no expiry to reap and no prefix-scoped key to prove. What
  remains is §11 item 2, run on B2 and then on Linode, which proves the
  presigned multipart path on each. Versioning on the bucket no longer
  carries a security property (nothing on a box can delete or overwrite
  outside a URL the plane signed for a new key); dev's bucket keeps all
  versions anyway (no lifecycle rules, read 2026-09-20).
- **E4. Resolved 2026-09-20** by the code check (§14): one more lookup by
  SMTP username in the webhook, and the reconcile reads the provider's own
  month-to-date figure so a missed delivery cannot make the banner wrong.
- **E5. Resolved 2026-09-20** by D2 (§15): a tenant is keyed by the
  connected key; the host is a label.
- **E6. Accepted 2026-09-20 — build as written.** A Connect approval
  binds a site to the signed-in account, and the approval page names the
  site. What the account holder has no way to do yet is see which sites
  hold a key for their account and cut one off. Add to item 4 of §10: a
  *Connected sites* list on the profile (host, connected date, the
  services it holds) with a **Disconnect** that deactivates the key and
  releases the services through the same path the reconcile uses. Without
  it, a site someone was tricked into approving keeps a key until the
  account holder finds the API keys page.
- **E7. Resolved 2026-09-20** by D3: with no credential on the box there
  is nothing to replace after a lapse; a re-granted tenant's next
  `shelf_begin_run` succeeds.
- **E8. Accepted 2026-09-20 — build as written.** Mail release closes the subaccount on
  the plane; the site must also clear the nine SMTP settings it wrote
  (they hold a dead username and password) once the new provider is
  proven, and shelf release must disable the `managed` target row rather
  than leave a target the broker now refuses to fail every night. Both
  belong to the switch-over forms in §9.
- **E9. Accepted 2026-09-20 — build as written** (running to-do B-key-capability-check):
  the plane never checks that its shelf target's credential can list,
  read, write and delete in its bucket (the Test button lists one object
  and stops), or that the SMTP2GO key is on a plan with subaccounts; both
  fail on first use instead of at save. Build with item 2a of §10: the
  Test button on the plane's shelf target exercises all four, and the
  hosted card's `ready` reads one SMTP2GO probe.

## 14. Executor notes — what the code check found (2026-09-20)

Read before item 1 of §10. Each note is a fact about the tree as it stands,
with the consequence for the build. File paths are under `public_html/`.

**E1, the skeleton seam.** The mailbox fleet is three pieces and only the
outer two move:

- *Tenant client* — `plugins/mailbox/includes/FleetClient.php`. The generic
  half is `configured()` and `call()`: three settings (service url, public
  key, secret key), `POST {url}/api/v1/action/{plugin}/{action}` as JSON with
  the `public-key` / `secret-key` headers dash-spelled (Apache→FPM drops
  underscore header names), 15 s timeout, non-200 → an exception carrying the
  remote `error` text, `data` returned. That becomes core
  `includes/ServiceClient.php`, parameterised by the three setting names and
  the plugin segment. `enroll()`, `status()`, `applyCoordinates()` and the
  domain-claim calls stay in the mailbox client as a subclass; nothing of
  them is generic.
- *Operator service* — `plugins/mailbox/includes/FleetService.php`. Nothing
  in it is generic: shard assignment, coordinates, claims and
  `applyTenant()` are relay work. What is generic lives **outside** it, in
  `plugins/mailbox/tasks/MailboxRelayReconcile.php` phase 5
  (`reconcileFleet()`, lines ~355–395): the entitlement re-check with the
  grace window (`mft_entitlement_lapse_time`, `mailbox_fleet_grace_days`,
  suspend after the window, reactivate in place when entitlement returns).
  That ladder, with `entitled()` and the suspend / reactivate acts as hooks,
  becomes core `includes/ServiceTenantLadder.php`; the mailbox reconcile
  calls it with the slot row and its two closures. The slot's four states
  (`provisioning | active | suspended | released`, plus the mailbox-only
  `evicted`) are the tenant row's state vocabulary.
- *The five actions* — `plugins/mailbox/logic/fleet_*_logic.php`. They are
  the shape every service action copies: `requires_session`, the user id
  from the session (the API key's owner), one `FleetService` call, a
  `LogicResult`. Nothing to lift; the services actions are new files under
  `plugins/server_manager/logic/services_*_logic.php`.
- Identity on the wire is the **API key pair alone** — `FleetClient` sends
  no site header, and the operator resolves the tenant from the key's user.
  The site's host travels in the enrol payload and is recorded on the tenant
  row as a label; the row is keyed by the connected key (D2).
- Two defects found on the way, both in the mailbox fleet: `fleet_status`
  called a `FleetService::reconcile()` that commit 863d0dde removed
  (fixed 2026-09-20, `relay_fleet` suite now exercises the action); and
  `mailbox_fleet_api_secret_key` is `secret: true` (masked on the form) but
  **not** in the mailbox `sealed_secrets`, so it sits in `stg_settings` in
  plain text. Seal it when the fleet migrates onto the skeleton (kind
  `regenerable-breaks-things`), the way `server_manager_getjoinery_api_secret_key`
  is declared in `plugins/server_manager/plugin.json`.

**The Connect flow (§4).**

- `includes/oauth/OAuth2State.php` is provider-agnostic: `issue(provider,
  purpose, scopes, payload, returnUrl)` mints a single-use session-bound
  nonce with a 600 s life, `validate(state)` consumes it. Use it as is with
  provider `getjoinery`, purpose `services_connect`. What is **not**
  reusable is `/oauth_callback`: its logic requires a `code`, a registered
  OAuth provider and a token exchange. The site's return lands on its own
  view, `views/services_connected.php`, which validates the state, checks the
  return is a top-level GET on the site's own origin, seals the pair and
  redirects to the wizard step. SameSite=Lax means the session cookie, and
  so the state, is only there on a top-level GET; a POST return would lose
  it.
- The plane's authorise page needs a signed-in visitor. Route a signed-out
  one through the start page with `SessionControl::set_return()` and
  `is_safe_return()` (SessionControl 1.2), the way `/server_manager/start`
  does for the Managed purchase; the authorise URL is the return.
- The key: copy `FleetProvisionSeeding::mintTenantKey()` (`apk_type`
  machine, `apk_permission` 3 = read + write, no delete; retire the earlier
  key of the same name for the same user before minting). `apk_name` is
  **varchar(32)**, so the key cannot be named for the host: name it
  `Joinery services` and record the host and the key id on the tenant
  row. "One active per site per account" is enforced on the tenant row's
  key id, not on `apk_name`.
- `MultiApiKey` carried a `published` filter naming a column the table does
  not have (removed 2026-09-20).
- The site-side settings belong in **core** `settings.json`, group
  `services`, not in the server_manager plugin: the wizard, the backup
  engine and the email step are core, `Setting::put()` refuses a name whose
  plugin is inactive, and nothing guarantees Server Manager is active on a
  self-hosted install. Names: `services_url` (default
  `https://getjoinery.com`), `services_api_public_key`,
  `services_api_secret_key` (`secret: true` **and** a `sealed_secrets` entry,
  kind `regenerable-breaks-things`), `services_account` (managed). Nothing else: the
  connected key is the site's identity, so no site key is stored. §9's `server_manager_services_*`
  names are superseded by these. The plane's `server_manager_services_grace_days`
  stays in the plugin.

**The mail leg (§2), node-less branch.** `ProvisionHostedMail` is welded to
a `CustomerCloudProvision` row: `advance()` runs only for `cvp_status =
done`, every step stamps `cvp_mail_state` and the last step pushes the
`hosted_mail_settings` job at a node. The provider calls inside it are the
reusable part — subaccount create + limit (`create_subaccount()`, saved
before the limit call so a crash never orphans a subaccount), sender domain
`mail.<domain>` (`add_domain()`, zero records from the provider fails the
leg), SMTP user mint (`Smtp2GoClient::mintUsername()` / `mintPassword()`,
the `sandbox_users()` switch and the `test_` prefix). Lift those three into
`Smtp2GoLeg` static functions that take a label, a domain, an allowance and
the prefix and return the provider ids and records; `ProvisionHostedMail`
calls them for its row, the services enrol action calls them for a tenant
row and returns host/port/user/password/records instead of pushing. The
nine values the site writes are the ones `utils/hosted_mail_settings.php`
lines 105–112 name (`email_service = smtp`, `smtp_host`, `smtp_port`,
`smtp_username`, `smtp_password`, `smtp_sender`, `smtp_helo`,
`smtp_hostname`, derived `smtp_auth`); reuse that mapping, do not write a
second list. Verification: the Managed leg waits `VERIFY_PATIENCE_HOURS`
(6) then moves on; the tenant leg has no node to wait for, so the status
action reports `domain_added` until the provider verifies and the wizard
step re-checks on load.

**E4, the webhook.** `plugins/server_manager/ajax/smtp2go_webhook.php`
maps an event to a customer by the SMTP username in `auth` (fallback
`subaccount_id`) against `cvp_smtp2go_user_id`, and increments
`htr_sent_count` on a `HostedTrial` row; anything it cannot map is dropped.
Add one lookup against the tenant row's SMTP username, incrementing the
tenant's figure, in the same function. Separately,
`Smtp2GoClient::monthToDateSends($subaccount_id)` exists and has **no
callers**: the reconcile phase reads it as the authoritative figure each
pass, so the banner is right even if a webhook delivery was missed. The
draft `hosted_bounce_handling.md` (store-then-process) is a separate change;
keep the tenant lookup a function it can call.

**E2, the `managed` target.** Confirmed and one surprise:

- The engine is provider-blind. `S3Signer` carries no provider string;
  `BackupRunner` never reads `bkt_provider`; `b2` differs only in
  `heal_b2_location()` and `complete_credentials()` (both gated on
  `=== 'b2'`) which discover region and endpoint. So a `managed` row must be
  **saved with region and endpoint** or the first upload throws `Missing
  required credential field: region`. `bkt_name` and `bkt_bucket` are
  required too. The plane's enrol response carries all of them.
- The provider list is a literal in six places plus four label maps:
  `data/backup_targets_class.php` (`allowed_values` line 53 **and**
  `$valid_providers` line 76), `adm/admin_backups.php:356`,
  `includes/setup_steps/backups.php:78`, `utils/install_backup_target.php:64`,
  and the label maps under `plugins/server_manager/` (`views/admin/targets.php`,
  `views/admin/target_info.php`, `includes/node_detail_tabs/backups.php`,
  `includes/RecoveryReadinessItems.php`). `managed` joins the data class and
  the label maps and is **absent** from the two dropdowns: it is set by
  enrol, never chosen.
- Layout: the engine writes `{bkt_path_prefix}/{slug}/{profile}/…` where the
  slug is the site setting `backup_path_slug`. The prefix the plane pins the
  key to is therefore `{shelf prefix}/{tenant slug}/`; the enrol response
  returns both and the site writes `bkt_path_prefix` and `backup_path_slug`.
  The reader-side helpers (`TargetBackups::base_prefix()`,
  `BackupPairing::cloud_state()`) assume no profile segment — the plane's
  prune must walk the real layout, which `HostedTrialWatch::prune_shelf_if_due()`
  already does for Managed; reuse it, chains whole.
- **The site uses its target credential for more than uploads**, which is
  what the object-store seam (§10 item 2b) has to cover: `BackupRunner`
  (`put_file` for small artifacts, `put_stream` for the streamed files
  archive, multipart above 1 GiB in 100 MiB parts), the nightly
  `BackupVerify` task (`BackupVerifyLauncher` lists the chain and presigns
  each object; switched on together with `BackupRun` by
  `BackupNightly::maybe_activate()`), the **Test** button on the Backups
  page and the wizard step (`TargetTester` lists one object;
  `utils/install_backup_target.php` deletes the target when that probe
  fails), and *Verify the newest backup* / *Rehearse a restore*. All of
  them take a credential today and call `S3Signer` statics; all of them
  call the interface after 2b, and `destination()` in the runner must
  accept a target with no credential when its provider is `managed`.
  Deletion is already gated: the manager profile sets
  `prunes_cloud => false` with the comment "the credential it was handed
  cannot delete", and `enforce_cloud_retention()`, `enforce_chain_retention()`
  and `discard_failed_run()` all honour it; the `managed` plan sets it the
  same way, and a failed run's partial multipart is aborted by asking the
  broker, which is the plane's own abort, not a delete.
- Presigning: `S3Signer::presign_get()` exists (SigV4 query signing); PUT
  and the multipart calls (`?uploads`, `?partNumber=&uploadId=`, the
  complete POST) are the same signer with a different verb and query, so
  the broker's signing is an extension of one function, not new crypto. A
  refused URL answers 403, which `S3Signer::is_retryable()` does not retry,
  and `BackupRunner::fail()` writes the cause into
  `bkh_backup_history.bkh_message` — a broker refusal at `shelf_begin_run`
  is written the same way before any HTTP happens.
- The Managed per-run leg today: `AgentChannelEndpoint::mint_run_credentials()`
  mints at job pickup and base64-encodes the credential into the job's
  params; the node's `BackupRunner` reads it from the manager config. The
  per-run token replaces that value in the same slot, so the agent
  primitive's parameter shape may not change at all — verify its spec in
  `joinery-agent/primitives` before assuming an agent release is needed.

**The banner (§9).** `HostedPlanNotice::STATES` is `trial | subscribed |
grace | shutdown` and `applies()` gates on membership, so `services` renders
nothing until it is added there. The same list is repeated in
`JobCommandBuilder::HOSTED_PLAN_STATES`, `utils/hosted_plan_notice.php` and
the agent primitive `operate_hosted_plan_notice.go`; add `services` to all
four so the lists stay one list, but only `HostedPlanNotice` matters in this
phase (a self-hosted site writes the five settings itself with
`Setting::put()`; they are declared `managed` in `settings.json`, which is
exactly what lets code write them and keeps them off the settings page).
`hosted_plan_allowances` is a one-line JSON list of `{label, used,
allowance, percent, action_label, action_url}` and the link renders only at
`percent >= 80` with an `https://` url — the first door is already built.
The referral links are **plane** settings (`server_manager_smtp2go_referral_url`,
`server_manager_storage_referral_url`), so the status response carries
`action_label` and `action_url` per service; add them to contract C2.

**The wizard (§9).** Steps register through `SetupSteps::register()`
(`plugins/joinery_ai/includes/bootstrap.php:170` is the cleanest plugin
example; core steps are in `SetupSteps::registerCoreDefaults()`). The Email
step is `includes/setup_steps/mail_send.php` with three stages, `form`
(provider select on `email_service`, credentials per provider) → `dns`
(`SendingDomainRegistrar`, the publish box) → `prove` (test send, *It
arrived* writes `email_test_send_last_success`). *Use getjoinery's account*
is an option in the `form` stage; enrol writes the nine settings, the
records go into the `dns` stage, and the `prove` stage is unchanged — which
is also the switch-over's proof. The Backups step
(`includes/setup_steps/backups.php`) already has a locked render: the
Managed badge branch when `ManagementNodeStatus::is_managed()`. The
`managed` target renders the same way with the figure and the date. The
Backups admin page (`adm/admin_backups.php`) has no per-target locked render
today; a `managed` row there hides Edit and Delete and shows the service
card.

**The reconcile (§5).** One entry in the `$phases` array of
`plugins/server_manager/tasks/ServerManagerAdvanceProvisioning.php`, after
`Hosted watch`. `HostedTrialWatch` is the model: per-row `watch()`, the
month roll on read, `enforce_storage_allowance()`, and the shelf prune. The
tenant ladder is the core `ServiceTenantLadder` from E1 with the date
compare in place of the tier check.

**The daily poll (§9).** A new core task `tasks/ServicesStatusPoll.php` +
`.json` (`daily`, `activate_on_install: true`, `run()` skips when the site
is not connected). Nearest templates: `plugins/dns_filtering/tasks/DownloadBlocklists`
and `plugins/store/tasks/ReconcileSubscriptions`. There is no daily fleet
status task today; the mailbox polls on page render.

**Suites that pin what this touches:** `plugins/mailbox/tests/relay_fleet_test.php`
and `fleet_auto_enrollment_test.php` (the fleet on the skeleton);
`tests/backups/backup_target_encryption_test.php`,
`b2_target_heal_on_read_test.php`, `target_backups_test.php`,
`backup_nightly_test.php` (the `managed` provider);
`plugins/server_manager/tests/hosted_tier_test.php` (banner levels, the
grace signals); `tests/account_security/login_test.php` (the start-page
return the authorise page rides).

## 15. Owner decisions from the code check

- **D1. What the tenant's shelf key may do — decided 2026-09-20, then
  superseded by D3 the same day.** Add, list and read on its own prefix,
  never delete, was the answer while a scoped key was the design; write-only
  was rejected because the site reads with its target credential for verify,
  Test and rehearsal.
- **D3. The shelf is brokered — decided 2026-09-20.** The owner is in
  talks to move storage to Linode, whose keys cannot express D1, and chose
  to build cross-provider now rather than a B2-only key minter: no box holds
  a storage credential; the plane signs one URL per object inside the
  tenant's prefix, never a delete, keeps the ledger, and prunes (§3). The
  Managed per-run key mint retires with it. Cost accepted: the object-store
  seam in the engine (§10 item 2b), the broker on the plane (2a), and the
  plane in the path of every run's start.
- **D2. What a tenant row is keyed by — decided 2026-09-20.** The connected
  key (`svt_apk_api_key_id`). Connect mints one key per site (§4), so the
  key is the site; the host is recorded from the enrol payload and
  refreshed on every status call, as a label. A renamed site carries its
  row and its shelf. Mail is the exception because the sender domain is the
  domain: a host change re-enrols mail — the enrol is idempotent on the row
  but a new domain adds a new sender domain and releases the old one. A
  re-connect (a new key for the same account and host) moves the row to the
  new key id and deactivates the old key; two live keys for one host under
  one account never coexist. A cloned site presenting the same key is the
  original as far as the plane can tell, the property the mailbox fleet has
  today with one slot per account.
