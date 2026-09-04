# Mailbox

## Overview

The Mailbox plugin (`/plugins/mailbox/`) is the platform's
self-hosted email subsystem — the receiving counterpart to outbound sending
(`SystemMailer`). Its first feature is **forwarding**: admins create aliases
(e.g., `info@example.com`) that forward incoming email to real addresses.

Postfix receives inbound mail, pipes it to a PHP handler, which looks up the
alias and relays it through the selected outbound provider (see
[Forwarding relay](#forwarding-relay)).

**Self-hosting here means inbound.** The plugin owns receiving — MX, Postfix,
the pipe handler, verification milters. Everything it sends (forwards, replies,
composed mail) leaves through the platform's configured outbound provider, the
assumed path for all outbound mail (see the outbound doctrine in
[Email System](/docs/email_system.md)). Delivering directly from this box's own
port 25 to recipient mail servers is an advanced setup a deployment must
deliberately pursue (cloud egress unblock, PTR, IP reputation) — never a
required step of mailbox setup.

**Features:** multiple domains, multiple destinations per alias, catch-all addresses, SRS for SPF compatibility, inbound authentication results (SPF/DKIM/DMARC) read from the verifying MTA / provider, outbound DKIM signing (opendkim), per-alias and per-domain rate limiting, RBL spam filtering, inbound email logs with admin viewer, live DNS validation.

> **Putting a domain on hosted mail:** the sender-identity layout, the record
> set, the cutover order, and the provider and DNS behaviours that produce a
> setup which looks correct and is not — see
> [Bringing a Domain onto Hosted Mail](domain_onboarding.md).

## Installation

### Prerequisites

Postfix (with the `postfix-pgsql` map driver), opendkim and opendmarc are
installed and configured by `provisioning/install_email.sh` — run it once per
deployment (see Server Setup below). It assumes one Joinery site per host; that
host may be a Docker container or bare metal.

> **Setup status on the Plugins page.** Once activated, this plugin declares three provisioners, so the admin Plugins page (`/admin/admin_plugins`) reports whether its runtime dependencies are working: a missing inbound mail server shows **Needs setup** with the `provisioning/install_email.sh` fix command; a down or misconfigured outbound relay shows **Needs setup** with the reason; and missing MX/SPF DNS records on any enabled inbound domain show **Needs setup** listing the affected domains. See the "Declaring Host Provisioners" section of `docs/plugin_developer_guide.md`.

### Enabling

1. Activate the plugin in **Admin > System > Plugins**
2. Run **update_database** from admin utilities to create tables and run migrations
3. **Mailbox** appears under **Emails** in the admin sidebar — it opens on the
   **Setup** tab

### The receive-mode choice (relay or direct)

One deployment-wide fact shapes every domain's DNS prescription: does mail come
straight to this server, or does a relay front it so the server's address stays
hidden?

**It is a setting, not a gate.** An undecided deployment receives directly and
works; the choice lives in the Setup tab's Advanced section and can be changed at
any time. A relay is only load-bearing at the Fortress security level, so the
answer is asked for where it becomes true — raising a domain to Fortress — rather
than in front of every mailbox page before any domain has a level.

The control is a brief pros/cons comparison (setup effort, whether the server's
address is public or hidden, and that a relay is **required for the Fortress
email security level**) with one choose button per column. The choice belongs to
the admin: a relay provisioned as part of setup does not decide it.

`mailbox_receive_mode()` (`includes/receive_mode.php`) resolves the mode:

1. The stored choice (`mailbox_receive_mode` setting) → its value. Choosing
   **relay** redirects to the Setup tab's Relay section; choosing **direct** redirects to
   Accounts to add the first domain (with a pointer to remove any provisioned
   relay).
2. Live domains with no stored choice → the mode reports what the deployment is
   actually doing (live relay row → `relay`, else `direct`).
3. Otherwise `''` — undecided, which every consumer treats as direct.

The choice is deployment-wide and reversible.

### Setup & verification (mailbox-first)

The **Setup** tab (`Emails > Mailbox > Setup`) verifies one mailbox at a time.
Pick a registered mailbox (from the Accounts tab) in the dropdown; the checks
scope to that mailbox and split into two groups:

- **Receiving** (always): the mailbox's domain DNS verified for *correctness*,
  not just presence (MX target actually resolves to this server, SPF authorizes
  the IP, DMARC published), that the domain is registered, that inbound mail is
  being authentication-verified (opendkim-verify + opendmarc), that the alias
  resolves, and an **end-to-end** proof — send a real message and watch it land
  in the logs. For an **IMAP-source** mailbox there is no MX/host stack, so this
  group instead reports the feed's connection state and last fetch.
- **Forwarding** (only when the mailbox forwards): the outbound relay, SRS, and
  DKIM signing — the checks that matter when mail is forwarded back out.

Copy-ready DNS records and exact fix commands appear inline on any failing
check, along with one-click actions to enable the plugin or register a domain.
The tab cannot create DNS records or set reverse DNS for you (those live with
your registrar / VPS provider) — it detects, instructs, and verifies.

#### The automated mail identity (machine sender)

The site's automated mail — reminders, receipts, notifications, everything
sent with nobody signed in — can send from its own subdomain identity
(`mail.<domain>`) instead of the bare domain, keeping the bare domain reserved
for people. The **Automated mail identity** card
(`domain.machine_sender*`, in the Sending group and the domain-focused view)
owns this:

- **On/off is derived, never a toggle**: the machine sender is on when the
  `defaultemail` setting's domain is a proper subdomain of the focused domain.
  The setting that controls where system mail sends from IS the switch, so the
  card can never disagree with what happens at send time.
- **Off, and eligible**: a grey OPTIONAL card on the domain system mail sends
  as, offering the setup. Other domains show nothing.
- **Off, and blocked**: when `EmailSender::transactionalSendBlocker()` reports
  the system sender can never send (a protected identity, typically), the card
  is a REQUIRED FAIL — mail is being dropped and nobody chose that. An empty
  or invalid `defaultemail` matches no domain view, so that blocker surfaces
  as a `plugin.system_mail` row under Advanced instead.
- **On**: the sub-checks are REQUIRED — provider registration in a *usable*
  state (registered-but-unverified fails), the provider's DKIM records live in
  DNS, SPF on the machine domain with the provider mechanism and a strict
  `-all` terminal (extra mechanisms pass; a softer terminal fails), the
  machine domain not itself protected, and an informational Reply-To row
  (`defaultreplyto` unset is a hint, never red). A **Send a test email**
  action proves the whole ambient path with one real send to the operator;
  with `email_dry_run` on it says so instead of reporting a suppressed send
  as proof.
- v1 verifies providers that implement `DkimRecordSource` (Mailgun, SES);
  other transports render guidance rather than fabricated verdicts.

The card's **setup ceremony** (`?machine_setup=1`, opened from the card)
walks: register `mail.<domain>` at the provider (one button when the provider
implements `SendingDomainRegistrar` — DKIM authority stays on the subdomain so
its keys align strictly), publish its DNS records (they join the domain's
publish-box plan while the ceremony is open), then switch system mail — a
local-part field (prefill `notifications`) plus `defaultreplyto` prefilled
with the domain's primary mailbox and saved unless cleared. The switch is
offered only once the earlier steps verify, and the action re-verifies
server-side. The subdomain is fixed to `mail.<domain>`, which is what lets
the ceremony hold no state: after any reload, progress is re-derived by
probing the provider and DNS. A custom subdomain takes the manual path
(register at the provider dashboard, point `defaultemail` at it).

The send-protection ceremony's readiness rows include **System mail has
somewhere to go**: a warning when `defaultemail` still sends as the domain
being protected, since protection will refuse every automated send. It warns
rather than blocks — proceeding past it is an explicit act.

#### Topology-aware prescriptions

Every prescription derives from the deployment's **receive topology**,
resolved from the `MailboxRelay` row: **colocated** (no relay row — the box is
the MX), **self-hosted relay** (`mrl_is_hosted = false`), or **hosted fleet
slot** (`mrl_is_hosted = true`). A relay row's *existence* — enabled or not —
flips every prescription to relay targets: the checklist walks the user to the
relay end state, so mid-cutover guidance already names the relay. Topology is
deployment-level; the security level is per-domain.

Under a fronted topology:

- **MX** must string-equal the relay's MX hostname (`mrl_mx_hostname`) and
  resolve to the relay's public IP. The box's address is never prescribed.
- **SPF** prescribes the outbound provider's mechanism alone
  (`v=spf1 <mechanism> -all`, from `EmailServiceProvider::getSpfMechanism()`)
  — a record naming the box FAILS, because it publishes the address the relay
  hides. Smarthost outbound prescribes the relay's IP instead. Local-sendmail
  outbound gets no record prescription: that row prescribes switching to an
  API provider.
- **Relay identity rows** (the MX hostname's A record, the relay IP's PTR)
  replace the box's own A/PTR rows. On a fleet slot they are operator-published
  and render as neutral INFO when missing; on a self-hosted relay the tenant
  owns the zone, so they are REQUIRED with the fix.
- **Domain ownership** (fleet only, `domain.ownership`): the fleet accepts no
  mail for a domain until a TXT proof is published. The row behaves like every
  other DNS row — the challenge is filed automatically (at enrollment for
  every registered domain, and at domain registration while a slot exists),
  re-verified on every check pass, and shown with the copy-ready TXT record
  until it goes green. There are no buttons and no claim/verify vocabulary.
- **Cutover progress** (`plugin.relay_enable`): relays are born enabled, so
  this row reports how far the DNS move has come — INFO with the first
  incomplete reason while MX records move, PASS once every hosted domain's MX
  targets the relay (and every ownership proof is published). The one bad
  state — cutover complete while the relay sits emergency-disabled (mail
  arriving with no consumer) — is a REQUIRED FAIL. Every evaluation records
  its verdict in the `mailbox_relay_cutover_complete` setting, which is what
  the outbound doctrine enforcement and the origin-hidden health check read —
  a fronted deployment keeps sending the legacy way until the cutover verdict
  flips, so nothing breaks mid-move and nothing leaks after it.

Fleet state (slot + ownership proofs) is read live from the fleet service once
per check run — never cached; if the service is unreachable, the ownership row
renders one UNKNOWN naming the error and the rest of the page is unaffected.

#### Advanced server setup

Settings and diagnostics that are server-wide rather than per-mailbox live
behind the **Advanced server setup** disclosure: the inbound **provider** picker
(`mailbox_provider`), this server's **mail identity** — the FQDN
(`mailbox_mail_hostname`, used as the MX target, HELO name, and PTR name)
and public IP — the provider's DNS records to publish, and the **full inbound
health run** (every layer: Postfix/pipe transport/domain map/opendkim/port 25,
mail identity, domain DNS, plugin config, and end-to-end). Set the mail hostname
here once; everything else is autodetected.

### The Accounts tree

**Emails > Mailbox > Accounts** is the single place to see and manage routing:
every domain, the mailboxes (aliases) nested under it, how each mailbox routes
(stored / forwarded / both), and any IMAP feed pulling mail into it. A domain is
either MX-hosted (mail pushed in) or an **IMAP source** (mail pulled in per
mailbox — e.g. `gmail.com`, no MX needed); both nest identically. The tree is the
overview and entry point; **+ Domain** and every **Edit** open the per-object editor
with context pre-filled. Under an **IMAP-source** domain the mailbox *is* its feed:
**Edit** opens one combined editor that manages the mailbox name, its reader, its
protection level and the IMAP feed together — there is no separate feed object.
Hosted (MX) domains keep a distinct **+ IMAP feed** per mailbox, which opens the
connect wizard — the feed is created there, attached to the hosted mailbox.

**+ Connect a mailbox** is how a pulled-in mailbox is created, and the only way
(`admin_mailbox_connect`); **+ Mailbox** on an IMAP-source domain leads there too.
See *Connecting a mailbox* below.

### Adding a Domain

The Setup tab can register a domain for you (a one-click action on the
"Domain registered" check). To manage domains directly: on the **Accounts** tab
click **+ Add Domain**, enter the name
and save — Postfix picks it up immediately (the inbound domain list is read live
from the database; no host command, no per-domain Postfix config). Then use the
Setup tab to verify and publish the domain's DNS records. **+ Add Domain**
creates hosted domains only: an IMAP-source domain (`gmail.com`) comes into
existence through the connect wizard, together with its first mailbox.

### Adding an Alias (mailbox)

1. On the **Accounts** tab, click **+ Mailbox** on the hosted domain you want
2. Enter the alias name, delivery mode, and destinations (for forwarding)
3. Save

### Connecting a mailbox

A mailbox whose mail lives somewhere else — Gmail, Microsoft 365, Yahoo/AOL, iCloud,
Fastmail, or any IMAP host — is created by the connect wizard
(`admin_mailbox_connect`, permission 10), reached from **+ Connect a mailbox** on the
Accounts tab. It is one page in four states, chosen by what is known rather than by a
step in the URL:

| State | Shown when | Asks |
|---|---|---|
| `provider` | nothing chosen yet | where does this mail live — nothing else |
| `register` | the chosen sign-in is OAuth and this site is not registered with that provider | that provider's app credentials, inline, plus the callback URL to paste |
| `signin` | the provider is ready | sign in; who reads the mailbox; what protection it gets |
| `configure` | a connected feed exists | the real folder list, how much history to bring in, what to call it |

**The easiest sign-in comes first.** The signin step offers the preset's default
method — an app password wherever the host honors one, with a guided **How do I
get this?** modal linking to the host's own app-password pages. A password
sign-in proves itself against the mail server before anything is created: a
refused credential fails on the signin form with the server's reason, and no
mailbox exists until the login has succeeded (the OAuth path is verified by its
consent round trip). A host that also
supports OAuth (Gmail) keeps it behind **Other options** (`method=oauth2`),
because OAuth costs a one-time app registration in the provider's developer
console; the register step, when it appears, offers the app-password way back.
Microsoft is OAuth-only — outlook.com retired basic auth.

The order is the point: each question is asked at the first moment it can be answered.
The folder list is a fact about a connected account, so it comes after signing in; the
provider registration is asked for only when it is missing, and only for the provider
being used.

**Consent creates the mailbox.** The flow payload carries the operator's *intent* —
provider, reader, protection level — and no ids, because none of those rows exist yet.
`InboundImapOAuthConsumer` creates the mailbox on success through
`ImapFeedProvisioner::provision()`, which is the one path a pulled-in domain, alias,
grant and feed come into being by; the combined editor edits what it made. A grant that
carries `account_id` instead is a **reconnect**, and stores the token on the feed that
already exists.

**The address comes from the provider.** Google and Microsoft report which account
consented (`OAuth2Client::fetchIdentity()`), and that answer is authoritative — it is
the address the IMAP session will authenticate as, so a typed one that disagrees is
simply wrong. Where a provider cannot say, the wizard asks, with the grant already held
in the session; losing the convenience never loses the connection.

**Someone else can sign in.** Choosing that creates the mailbox with no token: it
appears on the Accounts tree switched off with its normal **Connect** button, finished
later by a permission-10 admin on the owner's device.

**Protection is asked before the import, deliberately.** Sealing happens per message at
store time, so a mailbox set to Private *before* its archive is imported seals every
message as it lands, at no extra cost. Set afterwards, the same end state means the
backlog pass rewriting every stored row, 200 at a time, from the browser.

The feed is created **disabled** and starts fetching when the configure step is
finished, so an abandoned flow leaves a mailbox that is visibly not enabled rather than
one quietly collecting mail nobody finished asking for.

**A connected account is not a hosted domain.** Connecting `name@gmail.com` creates a
domain row for `gmail.com` marked as an IMAP source — bookkeeping for the mailbox, never
an identity this deployment owns (`InboundEmailDomain::is_authoritative()` answers no).
Nothing treats it as hosted here: anyone can still register or set a recovery address
`@gmail.com`, no DNS is checked or prescribed for it, no Joinery Direct signing identity
is minted for it, and the messenger does not treat its addresses as local. Mail from the
mailbox leaves **only** through the connected account's own SMTP: when the feed is
disabled, unauthorized, or a generic IMAP host with no SMTP, the reader's compose shows
the reason before anything is written, the Setup tab's Sending row says the same, and a
send refuses with the same words — it never falls through to the site's provider or the
relay (`MailboxSender::sendCapabilityFor()` is the one answer all three read). The setup
wizard's Email step counts a syncing connected account as a complete receiving
arrangement ("connected account", green), and "connection paused" when the feed is off.

**Plus-tagged addresses cannot be connected.** A mailbox local part admits no `+`, so
`name+tag@gmail.com` is refused with the base address named; connect `name@gmail.com` —
the provider delivers the tagged form to the same inbox, so nothing is lost.

**The configure step asks about sync too.** The discovery connection that lists the
server's folders also detects its sync capabilities, so when the server can keep two
copies in step the wizard offers the same **Keep in step with the original** choice
(Off / Read-only / Two-way, with the deletion and compose toggles) that the mailbox
editor carries — see [Sync](#sync-read-only-and-two-way). When the server cannot, the
step says plainly that the feed is a one-way import that never changes the original.

## Server Setup

On apt-based systems, run `provisioning/install_email.sh` as root, once per
deployment. It installs Postfix, `postfix-pgsql`, opendkim and opendmarc and
applies the **fixed** base configuration, idempotently:

- the `joinery` pipe transport in `master.cf`;
- `virtual_transport = joinery`, `inet_interfaces = all`, a safe
  `mydestination`, and RBL `smtpd_recipient_restrictions`;
- `virtual_mailbox_domains` wired to a PostgreSQL map (see below) so Postfix
  reads the live inbound-domain list straight from the database;
- opendkim config — inet socket on `localhost:8891`, `Mode sv` (sign **and
  verify**), empty key/signing tables, and an `AuthservID` matching the
  configured mail hostname;
- opendmarc config — inet socket on `localhost:8893`, `SPFSelfValidate true`,
  `RejectFailures false` (stamp-only, never reject);
- both Postfix milters, in order: `smtpd_milters = inet:localhost:8891,
  inet:localhost:8893` (opendkim first so opendmarc can consume its DKIM
  result), with `milter_default_action = accept` so a down/keyless milter never
  blocks mail. Received mail is thereby stamped with an `Authentication-Results`
  header the app reads for SPF/DKIM/DMARC (see **Inbound authentication** below).

The only genuinely per-deployment work left is DNS, and per-domain DKIM keys.
Adding or removing an inbound domain needs **no host action** — see below.

### Inbound authentication (SPF / DKIM / DMARC)

**The app never computes these verdicts itself.** SPF and DMARC are *structurally
impossible* to compute at the PHP layer: SPF is a function of the connecting
client IP evaluated against the sender domain's record, and the inbound Postfix
pipe (`utils/inbound_email_handler.php`) only ever receives the raw MIME on
stdin plus the envelope recipient — the connecting IP is known only to `smtpd`,
before the pipe. So the verdicts come from whoever ran the inbound MTA, in one
of two trusted forms, resolved by `InboundEmailRouter::readAuthResults()` in
precedence order:

1. **Webhook provider verdicts.** A provider that received and verified the
   message upstream (Mailgun, SendGrid, SES) returns its SPF/DKIM/DMARC results
   from `handleInbound()` as an `auth` array. The webhook dispatcher threads
   that into `processEmail()`, and the router records it with
   `iem_auth_source =` the provider key (`mailgun` / `sendgrid` / `ses`).
2. **Authentication-Results header.** For the self-hosted Postfix path, the
   verifying milters — `opendkim` in verify mode and `opendmarc`
   (`SPFSelfValidate`) — evaluate SPF/DKIM/DMARC on receipt and stamp an
   `Authentication-Results` header with our `AuthservID`. `AuthenticationResults`
   (in `includes/`) parses that header and the router records
   `iem_auth_source = 'milter'`.
3. **Relay stamps.** Under a fronted topology the relay is the verifying MTA: its
   own milters evaluate the message on receipt, and the sealer carries every
   `Authentication-Results` line into the `.meta` sidecar.
   `InboundEmailRouter::authFromRelayMeta()` reads them when the message is
   pulled and records `iem_auth_source = 'relay'`.

Either way the verdicts land in `iem_spf_result` / `iem_dkim_result` /
`iem_dmarc_result`. Each provider normalizes its native field values to the same
token set the header parser produces: `pass | fail | softfail | neutral | none |
temperror | permerror`. A method a source does not assert reads `none`. Only SES
reports a real DMARC verdict; Mailgun and SendGrid report SPF and DKIM only, so
their `dmarc` reads `none`.

**Trust model.** Provider verdicts are trusted only because they ride that
provider's authenticated delivery path: Mailgun's HMAC-signed POST (verified in
`MailgunProvider::handleInbound()`), SES's SNS message signature (verified
against AWS's signing certificate, pinned to an `sns.<region>.amazonaws.com`
host), and a shared secret on the Destination URL for SendGrid Inbound Parse
(which does not sign its requests — a blank `sendgrid_inbound_secret` rejects
everything). A forged `X-Mailgun-Spf`, `SPF`, or `receipt` blob on mail that did
**not** arrive through the matching provider is never honored: the `auth` key
only exists when that provider object handled the request. For the header path,
a message can carry attacker-supplied `Authentication-Results` lines from
upstream hops, so the parser honors **only** a line whose authserv-id is the one
belonging to the MTA that actually verified this message. Lines stamped by
anyone else are discarded, and the trusted name differs by path:

- **Colocated:** the local milters' `AuthservID`, which `install_email.sh`
  converges on `mailbox_mail_hostname`. They must match or verdicts are ignored.
- **Relay (self-hosted or fleet slot):** the relay's own mail hostname, resolved
  by `MailboxRelay::authservId()` and passed in by `RelaySpoolConsumer`. That is
  `mrl_authserv_id` when recorded, falling back to `mrl_mx_hostname`. The two
  differ on a hosted fleet slot, where the MX hostname is a per-tenant record
  (`<slug>.<zone>`) and the shard stamps under its own hostname — so the slot
  carries the shard's name in `authserv_id` from the fleet coordinates. On a
  self-hosted relay they are the same host. This pairs with the relay's
  `RemoveARFrom <relay hostname>`, which strips
  sender-supplied lines bearing that name before its milters stamp — so the one
  authserv-id accepted here is the one name a sender cannot smuggle in. The
  deployment's own `mailbox_mail_hostname` is **not** trusted on a pulled
  message; nothing on the relay strips lines carrying it.

**The `unverified` state is normal, not a failure.** When neither a provider
verdict nor a trusted `Authentication-Results` header is present — no verifying
milter installed, or mail that arrived some other way — the verdicts read
**`unverified`** and `iem_auth_source = 'none'`. A hand-rolled `fail` is
**never** emitted; an honest `unverified` is safer than a confident-but-wrong
verdict. Misreading a provider field is fail-safe the same way: an unrecognized
value falls through to `none` (or, when no verdict field is present at all,
`unverified`) — never a synthesized `pass`. The valid `iem_auth_source` values
are `milter`, `relay`, `mailgun`, `sendgrid`, `ses`, and `none`.

**One place turns a source into a readout.** `InboundEmailMessage::authIsVerified()`
answers whether a row's verdicts mean anything (derived from the source→name map,
so it can never lag the router's list), and `authReadout()` turns them into a
plain-language state every display surface shares:

| `state` | Headline | When |
|---|---|---|
| `verified` | Sender verified | DMARC `pass`, or no DMARC verdict with SPF and DKIM both `pass` |
| `failed` | Sender could NOT be verified | DMARC `fail`, or no DMARC verdict with SPF and DKIM both `fail` |
| `partial` | Sender partly verified | a trusted source, mixed results |
| `unchecked` | Sender not checked | no trusted source — with the reason: imported from an archive, collected over IMAP, or simply never received here |

It is a **readout, not a disposition** — what a verdict does to a message is
`InboundEmailRouter::classifySpam()`'s call alone, and the states above are
deliberately coarser than the filing rule. The Mailbox reader renders the
headline plus who checked it (`Sender verified · checked by your mail relay`)
with the acronyms on hover; the admin message detail page shows the headline
*and* the three raw verdicts, because an operator chasing a delivery problem
needs to see which one failed. Neither ever renders a bare red `fail`. The
reader payload carries the whole readout under `auth`, so native and API
consumers say the same thing without reimplementing any of it.

#### Verification-capability warning (Setup tab)

The Setup tab runs an **Inbound authentication verified** check
(`host.inbound_verification` in `InboundEmailSetupCheck`) so a missing or broken
verifier surfaces as an explained warning rather than a silent `unverified`:

The check reads the topology first, because which verifier to interrogate
follows from it. Under a fronted topology inbound mail never reaches this box's
milters, so their state is not evidence of anything and is not probed — the
question is whether the relay's stamps are arriving.

- **WARN** — the selected provider has no inbound verification path at all,
  **or** the provider is Postfix but verification is broken (milter unreachable,
  opendmarc missing, config drift). Fix: run `install_email.sh`, then send a test
  message to confirm an `Authentication-Results` header appears.
- **WARN (fronted)** — mail is arriving from the relay but none of it carries a
  verdict. The relay is delivering while its stamps are being refused, which is
  what an authserv-id that is not the relay's mail hostname looks like, or a
  relay whose own milters are stopped. Fix: re-run the relay provisioner on the
  relay host, then send a test message. This case is called out separately
  because the alternative — reporting it as *nothing has arrived yet* — hides a
  live defect behind a to-do.
- **INFO** (neutral) — the verifier is in place, but no verdict-carrying mail has
  arrived **yet** to confirm it. For Postfix this also covers a host whose config
  isn't readable by the web user; for a webhook provider (Mailgun/SendGrid/SES)
  or a relay it simply means no message stamped with that `iem_auth_source` has
  been received yet. We legitimately can't tell yet — not an alarm.
- **PASS** — recent mail carries verdicts (`iem_auth_source` = `milter` for
  Postfix, `relay` under a fronted topology, or `mailgun` / `sendgrid` / `ses`
  for a webhook provider). The behavioral signal (verdict-carrying mail actually
  seen) is authoritative, because a milter can be wired-but-unreachable; for
  Postfix the config probe only enriches the reason.

#### End-to-end delivery check (Setup tab)

The **End-to-end delivery** check (`e2e.test_message`) is the only proof that
the outside world can actually reach an address: that inbound port 25 answers on
a colocated deployment, or that the relay is reachable and its spool is being
pulled on a fronted one.

It asks both places an arrival is recorded, because the two ingest paths record
in different ones. The colocated path writes a transaction row per message
(`iel_inbound_email_logs`) and the check reports its status and time. The relay
path stores the message and writes no transaction row — the relay already made
the forwarding decisions, so there is no local transaction to log — and the
check falls back to the stored message (`iem_inbound_email_messages`). Asking
only the log would leave a relay deployment permanently warning that nothing has
ever arrived while its mailbox fills up.

### The inbound-domain list is live, never "installed"

`install_email.sh` writes `/etc/postfix/joinery-domains.cf`, a `postfix-pgsql`
map (`640 root:postfix`), and sets:

```
virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
```

For every inbound recipient, Postfix asks the database whether that domain is
an active inbound domain. Adding, removing, enabling, or disabling a domain
in the admin UI is therefore effective immediately — no SSH, no root, no
re-run, and no drift. `install_email.sh` creates a dedicated least-privilege
PostgreSQL role for the map — it can `SELECT` the inbound-domain list and
nothing else, never the application's superuser — and writes the map. The
role's password lives only in the map file; re-running `install_email.sh`
rotates it.

If Postfix's `smtpd` / `trivial-rewrite` services run chrooted, `install_email.sh`
wires the map as `proxy:pgsql:...` instead (proxymap runs un-chrooted). Modern
Debian/Ubuntu ship these services un-chrooted, so the bare `pgsql:` map is used.

### DNS (per domain)

```
@                 MX   10  mail.yourserver.com.
@                 TXT  "v=spf1 ip4:YOUR_SERVER_IP -all"
mail._domainkey   TXT  "v=DKIM1; k=rsa; p=YOUR_PUBLIC_KEY"
```

### Postfix reference (non-apt systems)

`install_email.sh` is the supported installer. On a non-apt system, apply the
equivalent fixed config by hand. In `/etc/postfix/main.cf`:

```
virtual_transport = joinery
virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
inet_interfaces = all
mydestination = localhost, localhost.localdomain

# opendkim (verify) then opendmarc — order matters; accept on milter failure.
milter_default_action = accept
smtpd_milters = inet:localhost:8891, inet:localhost:8893
non_smtpd_milters = inet:localhost:8891

smtpd_recipient_restrictions =
    permit_mynetworks, reject_unauth_destination,
    reject_rbl_client zen.spamhaus.org,
    reject_rhsbl_helo dbl.spamhaus.org,
    reject_rhsbl_sender dbl.spamhaus.org, permit
```

Spamhaus is the only list rejected on. Zen and DBL are built for it — low
false positive, and Zen deliberately excludes the shared outbound ranges ESPs
send from. Lists that do cover those ranges (SpamCop, Barracuda) list an IP on
a brief automated trigger and de-list hours later, so rejecting on them bounces
ordinary mail from Mailgun, SendGrid or Google at random, and permanently: a
5xx tells the sender never to retry. A weaker signal belongs in content
scoring, not at RCPT time.

opendkim must run `Mode sv` with an `AuthservID` equal to your mail hostname
(== `mailbox_mail_hostname`), and opendmarc with `SPFSelfValidate true`
and `RejectFailures false`. See `provisioning/install_email.sh` for the exact
managed config both daemons use.

`/etc/postfix/joinery-domains.cf` (the pgsql map). `install_email.sh` creates
the dedicated role and writes this file automatically; on a non-apt system,
create the role by hand — `CREATE ROLE "iemap_<dbname>" LOGIN PASSWORD '...';`
then `GRANT SELECT ON ied_inbound_email_domains` to it — and write the map as
that role:

```
hosts    = localhost
user     = iemap_<dbname>
password = <the role's password — lives only in this file>
dbname   = <db name>
query    = SELECT ied_domain FROM ied_inbound_email_domains
           WHERE lower(ied_domain) = '%s'
             AND ied_is_enabled = true
             AND ied_delete_time IS NULL
```

Add to `/etc/postfix/master.cf`:

```
joinery   unix  -  n  n  -  5  pipe
  flags=DRhu user=www-data
  argv=/usr/bin/php /var/www/html/SITENAME/public_html/plugins/mailbox/utils/inbound_email_handler.php ${recipient}
```

(Use the PHP CLI path for your system — `install_email.sh` resolves it
automatically; the official `php` Docker images ship it at `/usr/local/bin/php`.)

### opendkim (DKIM signing + inbound verify)

`install_email.sh` installs opendkim's **static** config (the inet socket,
`Mode sv`, `AuthservID`, empty `key.table` / `signing.table` / `trusted.hosts`,
and the Postfix milter). `Mode sv` means it **signs** outbound *and* **verifies**
inbound (stamping the DKIM result into `Authentication-Results` — see **Inbound
authentication** above, where opendmarc adds SPF/DMARC). opendkim runs from
first install — keyless for *signing* until a per-domain key is added, but
*verifying* inbound DKIM immediately — and `milter_default_action = accept`
guarantees a keyless or down opendkim never blocks or defers mail.

> The opendkim.conf the installer writes is keyed on a managed marker. Re-running
> `install_email.sh` re-asserts the managed config — the `inet:8891` socket,
> `Mode sv`, and the `AuthservID` — and realigns Postfix's milter wiring to match,
> so a host whose opendkim config has drifted is brought back into line on the
> next run.

Generating a key is a **per-domain** step (a key file on disk plus a DNS record
cannot be a database lookup). `provisioning/provision_dkim.sh` does the whole
host side in one idempotent command:

```bash
sudo bash plugins/mailbox/provisioning/provision_dkim.sh example.com
```

It runs `opendkim-genkey`, appends the `key.table` / `signing.table` lines
(only if absent), restarts opendkim, and prints the DNS TXT record to publish
at `mail._domainkey.example.com`. Re-running for a domain that already has a
key is a no-op that just reprints the record. The Setup tab's "DKIM signing
key" check offers this exact command as its fix, and the following "DKIM record
published" check then hands you the TXT record as a copy-paste DNS fix.

Forwarding works without a DKIM key; only outbound DKIM signing is affected.

**The Setup tab's DKIM rows follow the signing path** (specs/mailbox_provider_dkim.md):
the local opendkim key above is prescribed only when the domain's mail actually
leaves through local Postfix (colocated deployments). When composed mail rides
an API provider — always the case on a relay-fronted deployment with provider
outbound, and additionally on colocated deployments whose active provider is
API-class — the correct DKIM record is the one the **provider** issues for the
domain, and the row verifies exactly that: providers implementing
`DkimRecordSource` (Mailgun, SES — see
[email_system.md](../../../docs/email_system.md#provider-dkim-records-optional-capability))
report their required records from their own API, and the Setup tab renders one
row per record, each checked against live DNS with a copy-paste fix. A domain
not registered at the provider gets a row saying so (mail from it fails DMARC
alignment until it is added at the provider dashboard); a provider without the
capability gets generic guidance naming it. When sent mail leaves through the
relay, the row states plainly that sends carry no DKIM signature.

### Firewall

`install_email.sh` runs `ufw allow 25/tcp` when ufw is active. Bare metal or a
container, the site's Postfix owns port 25 on its host.

### Container persistence

On a **systemd host**, `install_email.sh` runs `systemctl enable`, so Postfix
and opendkim restart on boot automatically — nothing else is needed.

A **Docker container** has no systemd; its `CMD` is the init. The Joinery site
image handles the mail stack the same way it handles PostgreSQL and cron — by
(re)starting it on every container start. The plugin declares
`install_email.sh` as its `host_installer` in `plugin.json`, and the `CMD`
runs `_plugin_installers_start.sh`, which executes every active plugin's
declared host installer — for Mailbox that re-applies the Postfix / opendkim
configuration and starts both daemons (via the idempotent
`install_email.sh`). The mail packages themselves are baked into the base
image. So in a container the mail stack survives a `docker stop`/`start` and
an image rebuild with no manual step.

This applies to images built from base image version 1.1 or later. An older
container keeps relying on a manual `install_email.sh` run until it is rebuilt
and redeployed — base-image changes do not travel through the code-upgrade
pipeline. See the `mail_stack_container_persistence` spec.

### Advanced: multi-site host relay (manual, not installed)

A more complex topology — several sites behind one IP, with a host front-relay
demultiplexing inbound mail to per-container Postfix instances by domain — is
possible but is **manual, operator-level configuration**. `install_email.sh`
assumes one site per host and does not set this up. If you run it, the host
relay (`relay_domains`, `transport_maps`) and per-container port mapping are
yours to maintain; RBL checks would happen on the host relay only.

## Settings

Delivery-policy settings — spam filtering, forwarding limits, the forwarded-From
display, and retention/storage caps — are edited on the **Settings** tab. Server
identity and provisioning (provider, mail hostname/IP, SRS, the relay) live on the
**Setup** tab.

| Setting | Default | Description |
|---|---|---|
| `mailbox_enabled` | `0` | Master switch |
| `mailbox_mail_hostname` | (empty) | FQDN of this mail server — MX target, HELO, PTR (set on the Setup tab) |
| `mailbox_public_ip` | (empty) | Optional public-IP override; empty = autodetect |
| `mailbox_srs_enabled` | `0` | SRS envelope rewriting (recommended) |
| `mailbox_srs_secret` | (empty) | Required before SRS can be enabled |
| `mailbox_forwarding_max_destinations` | `10` | Max destinations per alias |
| `mailbox_forwarding_rate_limit_per_alias` | `50` | Per-alias limit per window |
| `mailbox_forwarding_rate_limit_per_domain` | `200` | Per-domain limit per window |
| `mailbox_forwarding_rate_limit_window` | `3600` | Rate limit window (seconds) |
| `mailbox_log_retention_days` | `30` | Days the inbound delivery log is kept. `0` keeps it indefinitely. |
| `mailbox_trash_retention_days` | `30` | Days mail stays in Trash before it is permanently deleted. `0` keeps it indefinitely. See [Trash and retention](#trash-and-retention). |
| `mailbox_unmatched_retention_days` | `90` | Days stored mail for an address no mailbox claims is kept. `0` keeps it indefinitely. See [Trash and retention](#trash-and-retention). |
| `mailbox_forwarding_smtp_host` | (empty) | Dedicated SMTP relay for forwarding. **When set, it forces the SMTP relay path** (overriding provider relay); falls back to base `smtp_*` for any field left blank. See [Forwarding relay](#forwarding-relay). |
| `mailbox_forwarding_smtp_port` | (empty) | Falls back to `smtp_port` |
| `mailbox_forwarding_smtp_username` | (empty) | Falls back to `smtp_username` |
| `mailbox_forwarding_smtp_password` | (empty) | Falls back to `smtp_password` |
| `mailbox_spam_filtering_enabled` | `1` | Move suspected spam to the Spam view. The one spam question; on by default. See [Spam filtering](#spam-filtering). |
| `mailbox_spam_learning_enabled` | `0` | Learn from what users mark as spam. Relay/webhook mail is re-scored locally wherever a scanner runs; this setting makes that local verdict the one that counts (replacing the upstream's) instead of merely adding to it. Clamped off whenever filing is off; offered only where a scanner is running (it ships with the mail stack). See [Content scanner](#content-scanner-rspamd). |
| `mailbox_rspamd_controller_url` | `http://127.0.0.1:11334` | Loopback rspamd controller endpoint the ingest scan and the spam/ham feedback loop POST to. No password (loopback-trusted). |
| `mailbox_relay_outbound_mode` | `provider` | On a relay-fronted deployment, where compose sends leave: `provider` (default — the configured provider's raw-MIME API, hiding the origin) or `smarthost` (through the relay over the tunnel; the deployment owns the relay IP's sending reputation). The stored value keeps the Postfix term; the reader is shown *Through the relay*. See [Outbound sending](#outbound-sending). |

## Plugin Structure

```
/plugins/mailbox/
├── plugin.json
├── data/          — Domain, Alias, Log models (auto-create tables)
├── includes/      — InboundEmailRouter (processing), InboundEmailHealth,
│                    InboundEmailSetupCheck (guided-setup verification engine),
│                    MailboxSpamPolicy (derived spam posture), SRSRewriter
├── utils/         — Postfix pipe script (inbound_email_handler.php),
│                    spam_policy.php (spam posture readout for shell sessions),
│                    managed_domain_prepare.php (make a domain mail-ready and
│                    print its DNS plan, for a management node over SSH)
├── provisioning/  — Host setup: install_email.sh, provision_spam_scanner.sh,
│                    render_pgsql_map.php
├── admin/         — Admin pages (setup, aliases, alias edit, domains, logs)
├── logic/         — Logic files for admin pages
├── tasks/         — Scheduled tasks (relay reconcile, IMAP poll, imports, filters)
└── migrations/    — Settings and menu entry
```

**Tables:** `ied_inbound_email_domains`, `iea_inbound_email_aliases`, `iel_inbound_email_logs`

**How forwarded emails appear to recipients:**
- **From:** `"Original Sender via Site Name" <info@your-verified-domain.com>` — uses the site's verified sending address for deliverability
- **Reply-To:** `original-sender@their-domain.com` — hitting Reply goes to the right person
- **Subject:** Preserved from the original email

This approach is required because SMTP services (Mailgun, SendGrid, etc.) require the From address to be on a verified domain. Sending with an arbitrary external From would be silently dropped.

## Forwarding relay

Forwarding relays the message through the **selected outbound provider**
(`email_service`, the same provider ordinary outbound mail uses) when that
provider can relay raw MIME with a chosen envelope sender — reusing the one
credential the operator already maintains. There is no separate forwarding
SMTP password to configure or let go stale.

The router resolves one of two paths once, in `resolveRelayProvider()`:

1. **Provider relay** — the active provider implements the optional
   `RawMessageRelay` capability **and** no `mailbox_forwarding_smtp_host`
   override is set. The original message bytes are relayed faithfully through
   the provider's API (Mailgun `messages.mime`, SES `sendEmail` with
   `Content.Raw`) or native SMTP, reusing the provider credential. Providers
   that implement it: **Mailgun, SMTP, SES**.
2. **SMTP fallback** — every other provider (Postmark, SendGrid, Brevo,
   Mailjet, Resend), or whenever `mailbox_forwarding_smtp_host` is set.
   Relays over raw SMTP using the forwarding-specific
   `mailbox_forwarding_smtp_*` settings, falling back to base `smtp_*`.
   This is also the path for operators who deliberately point forwarding at a
   dedicated relay.

When the provider relay is the primary path, any destination it fails is
**retried over the SMTP relay** — the same primary→fallback the outbound
`EmailSender` uses. Only the failed destinations are retried, so a partial
provider success never double-sends. The retry is skipped when it could not
help: when no base `smtp_host` is configured, or when the active provider *is*
the SMTP relay (same transport). A failure that survives both paths is logged
`STATUS_ERROR` as before.

All forward paths — alias forward, `forward_and_store`, and the domain
catch-all forward — go through this resolver, so the catch-all forward
preserves attachments and MIME structure exactly like the alias forward.

### Forwarding off a protected domain

A domain at Private or Fortress promises its mail cannot be read without the
owner's key. A forwarding filter breaks that promise by design: the copy leaves
over SMTP in clear text, permanently out of the vault's reach. That is allowed,
but only as an informed choice.

Saving a filter with a forwarding address on a protected domain requires ticking
an acknowledgment that names the destination. The acknowledgment is stored with
the address it was given for (`fil_forward_ack_time`, `fil_forward_ack_destination`,
`fil_forward_ack_usr_user_id`), so repointing the filter somewhere else needs
fresh consent rather than inheriting the old one.

**Raising the domain's security level revokes every acknowledgment on it.**
Agreeing to send a Standard domain's mail out in clear text is not agreement for
what Fortress promises. Affected filters keep matching, labelling, starring and
filing — only the forward stops, and the address stays in the box so
re-acknowledging is one tick. Each suppressed forward is logged, naming the
filter and the address.

A filter with no forwarding address needs no acknowledgment, and forwarding off
a Standard domain is unaffected.

The **SRS bounce notification** (`handleSRSBounce`) is not a relay — it is a
freshly generated delivery-failure message, sent through the normal provider
send path (`EmailSender`), which also reuses the provider credential.

**SRS, per path.** On the **SMTP fallback** path the SRS-rewritten envelope
sender is honored as `MAIL FROM` (we are the MTA and own the return-path), so
SPF aligns at the destination and bounces route back through us for SRS
decoding. On the **provider relay** path, providers that own bounce handling
(Mailgun, SES) align their own SPF/DKIM with their sending domain and manage
bounces, so the SRS envelope is best-effort there and SRS bounce-decoding does
not apply — the From-header rewrite to the verified address is what carries
deliverability either way.

The **Sending route** check on the Setup tab verifies the *resolved* outbound
path: when provider relay is active it confirms the provider's own credential is
configured (so a healthy API key reads PASS even with empty `smtp_*`); on the
SMTP fallback path it connects to the SMTP host and closes. It is named for what
it is — the route outgoing mail takes — and has nothing to do with the ingest
relay described under [The relay](#the-relay); the two are unrelated, and
sharing the word *relay* between them left no way to tell which a row was
about.

## Knowing a mailbox is unfinished

The Setup tab tells you whether a mailbox is configured correctly, but only if
you go and ask it. The Accounts listing badges the ones worth asking about, so a
half-finished mailbox does not sit broken until somebody happens to open the
page that would have said so.

The reader answers the same question where an operator is already reading, and
it answers it exactly: opening a mailbox asks `mailbox/setup_status`, which runs
the Setup tab's own checks for that mailbox and returns its verdict. Anything the
tab paints amber or red banners the reader in place of the first conversation,
naming the offending check and linking to the tab. A mailbox that is all green
shows nothing at all — silence is the normal state, so the banner means something
when it appears.

Both surfaces run the same grouping code (`mailbox_setup_scope.php`), so the
banner and the tab cannot disagree: `mailbox_setup_scoped_rows()` builds the
Receiving/Forwarding groups and `mailbox_setup_verdict()` grades them. A check
that could not run (unknown), one that is legitimately undecidable yet (info),
and a capability nobody turned on (optional) are all silent — a verdict that
flaps with a DNS hiccup gets ignored.

The checks cost DNS lookups and host probes, so the verdict is remembered per
operator rather than re-resolved on every mailbox click. Freshness comes from
writing it wherever the checks have genuinely just run: **rendering the Setup tab
stamps the verdict for the mailbox it just checked**, so fixing a record there and
going back to the mailbox clears the banner immediately — no waiting out a cache.
The five-minute expiry is only the backstop for a mailbox nobody has looked at,
the reader's Refresh control forces a re-run, and a reader left open in another
tab re-asks when it regains focus. An `unknown` result never overwrites a real
answer: one failed lookup should not make the banner flap.

It is admin-only (permission 5+) and scoped to mailboxes the caller can already
see: members reading their own mail never receive a verdict, and the member mount
has no Setup page to link to.

A badge is a **navigation hint, not a verdict**. It says go and look; the Setup
tab re-runs everything live and is the only thing that claims a domain is
correct or broken. That is why the copy reads *needs attention* rather than
*broken*, and why nothing else in the platform reads these signals — they never
gate sending, provisioning or cutover.

Three signals feed it, tiered by cost, assembled in
`plugins/mailbox/includes/mailbox_setup_hints.php`:

| Tier | Signal | Cost |
|---|---|---|
| Free | Fortress domain whose protect ceremony never ran; protected domain with no sealed signing key; domain switched off | Already on the loaded row |
| One query | No mail has ever arrived at this address | Two lookups bounded by the mailboxes on screen |
| Persisted | A required DNS record was missing when last checked | A column read |

The arrival lookup asks both `iel_inbound_email_logs` and
`iem_inbound_email_messages`, because the colocated path writes a transaction row
per message while the relay path stores the message and writes none — asking
only the log would report a relay deployment's whole estate as having never
received anything. It filters `iem_direction = 'inbound'`, since `iem_recipient`
is a plain routing address only on an inbound row; on a composed row it is sealed
content. Both tables carry a `LOWER()` expression index for these queries:
stored addresses are genuinely mixed-case, so a plain index on the raw column
would not be used.

The persisted tier comes from the **Check inbound domain DNS setup** scheduled
task (`CheckDomainSetup`, daily), which runs the DNS-only check entry point
against each enabled non-IMAP domain and stores `ied_setup_status` plus
`ied_setup_checked_time`. Two rules keep it worth reading:

- **Only a required failure flags a domain.** A missing DMARC record is graded
  *recommended* — real advice, but a domain receiving mail perfectly well should
  not wear a badge saying otherwise.
- **A check that could not run is not a failure.** An unanswered resolver would
  otherwise make every badge flap with the first DNS hiccup, and flapping badges
  get ignored. When nothing could be evaluated at all the previous verdict is
  left alone rather than overwritten with an absence of information.

A stored verdict older than seven days is not displayed: pointing at a domain
that was fixed last week wastes exactly the attention the badge is buying.

See `specs/mailbox_setup_verdicts.md`.

## Testing

Test without Postfix by piping raw email to the handler:

```bash
echo "From: alice@gmail.com
To: info@example.com
Subject: Test

Hello" | php plugins/mailbox/utils/inbound_email_handler.php info@example.com
echo $?   # 0 = success, 67 = unknown alias, 75 = temp failure
```

## Troubleshooting

**Email not arriving:** Check inbound email logs (Mailbox > Logs tab), verify alias and domain are enabled, check SMTP settings, check `error.log`.

**Email not reaching Postfix:** Verify MX records (`dig MX domain`), port 25 open, Postfix running. Confirm `virtual_mailbox_domains` is wired to the pgsql map (`postconf -h virtual_mailbox_domains` should show `pgsql:/etc/postfix/joinery-domains.cf`); the Domains page Server Status panel reports this. If a pgsql lookup fails because the database is down, Postfix returns a temporary error and the sender retries — mail is deferred, not lost.

**"User unknown in local recipient table":** The domain is in Postfix's `mydestination` setting, which takes priority over `virtual_mailbox_domains`. The admin domain edit page detects this conflict and shows a red "Conflict" badge. Run `install_email.sh` to fix — it sets `mydestination = localhost, localhost.localdomain`.

**Landing in spam:** Enable SRS, verify opendkim running and a DKIM key generated and its DNS record published, check SPF includes server IP, verify rDNS/PTR record, check IP at mxtoolbox.com.

## Delivery Modes

Each alias has a **delivery mode** (`iea_delivery_mode`):

- **`forward`** (default) — relay to one or more destination addresses; no copy is kept locally.
- **`store`** — persist the message to `iem_inbound_email_messages` for inspection in the admin Mailbox tab. Nothing is relayed. Destinations are not required.
- **`forward_and_store`** — relay AND keep a faithful copy of the original message.

Each domain also has a **catch-all mode** (`ied_catch_all_mode`):

- **`forward`** — send unmatched recipients to `ied_catch_all_address` (or reject/discard, per `ied_reject_unmatched`).
- **`store`** — persist every unmatched recipient on the domain to the local mailbox. This is the equivalent of a Mailgun wildcard `forward()` route. `ied_reject_unmatched` is ignored when catch-all mode is `store`.

## Local Mailbox

The **Mailboxes** tab (`Emails > Mailbox > Mailboxes`) is the default landing
tab — a Gmail-style reader over locally-stored inbound messages. The
conversation list updates in place after mutations
(`specs/implemented/mailbox_reader_list_persistence.md`): an action that takes rows out of
the current view (delete, restore, spam, archive-from-Inbox) removes exactly
those rows, and anything else (read/unread, star, folder moves) re-reads the
same view without blanking it first — scroll position and still-applicable
checkbox selections survive, and only mailbox/view switches and searches
rebuild the list from scratch. Each message
shows the parsed plain-text body, a sandboxed iframe rendering of the HTML body
(no scripts, no top-nav), and a per-attachment download. Each attachment is a
private `File`, streamed through a single gated endpoint for every transport (see
**Attachment & message storage** below); a `.eml` is never reassembled from those
parts — the reader's Download .eml serves the stored original or nothing.

Stored bodies are fully attacker-controlled — admins should never paste a
captured token into a non-admin page or feed an untrusted body to an AI
agent without the platform's untrusted-input markers (see
`specs/implemented/joinery_ai_untrusted_input_markers.md`).

**Settings:**
- `mailbox_max_per_window` (default `0`, which disables the cap) — max non-deleted stored messages per domain inside the forwarding rate-limit window. A store above the cap is deferred, not dropped: the delivery is temp-failed (Postfix retry / webhook 503) so the sender redelivers once the window rolls, and it is logged once as `store_capped`.
- `mailbox_relay_orphan_grace_days` (default `30`) — how long the relay pull *holds* recoverable-but-not-yet-storable mail on the relay before aging it out. A blob whose domain is disabled/unconfigured, or whose Fortress owner is not yet resolvable, is held (not deleted) so re-enabling the domain or restoring the grant lets the next pull store it; past the grace window it is dropped with a loud log. The held count surfaces on the relay health as "No mail held on relay".

A `store`-only deployment does not need the outbound forwarding relay
provisioner — the `outbound_forwarding_relay` check may legitimately
report "Needs setup" without actually preventing inbound mail from being
captured.

**Test workflow:**

1. Add an inbound domain (e.g. `inbox.dev.getjoinery.com`) with catch-all mode **store**.
2. Publish its MX record pointing at this host and an SPF record; let the Setup tab confirm them green.
3. The test sends application mail to `whoever@inbox.dev.getjoinery.com`.
4. The test queries the store:
   ```sql
   SELECT * FROM iem_inbound_email_messages
   WHERE iem_recipient LIKE '%whoever%'
   ORDER BY iem_received_time DESC LIMIT 1;
   ```
5. Tests extract links / verify content from `iem_body_plain` / `iem_body_html`.
6. The retention task handles cleanup — no manual DELETE needed.

Dedup is enforced at the DB layer by a UNIQUE constraint on
`(iem_message_id_header, iem_recipient, iem_direction)`. A retry with the
same Message-ID header succeeds silently (no duplicate row). Direction is
part of the key because mail between two hosted mailboxes legitimately
produces two rows for the same Message-ID and address — the sender's
outbound (Sent) copy and the recipient's inbound copy. Messages with no
Message-ID header are always inserted (NULLs are distinct in Postgres
unique constraints).

The recipient half of that key is meaningful only where `iem_recipient` holds
the plain routing address — inbound rows. On a sealed mailbox a composed row's
recipient is ciphertext (see [Sealed Vault](../../../docs/sealed_vault.md)),
so the constraint cannot recognize a provider-filed copy of a locally-composed
send. Every IMAP store path therefore dedups by Message-ID alone against the
alias's outbound/draft rows before storing (`ImapIngestor::storeMessage`): on
a hit the composer's copy adopts the IMAP locator and the folder membership,
and no new row is stored. That includes a **self-addressed** send: its
appearance outside the Sent-role folder is the delivered copy of a message the
composer's row already holds, so it reconciles there and the row is marked
`iem_self_delivered` (see *Mail addressed to yourself* below). Where a
Sent-folder sighting promotes an
already-stored inbound row to outbound on a sealed mailbox, the row's
plaintext recipient becomes a sealing debt (`iem_reseal_pending`), paid
in-window by the `mailbox_promoted_reseal` deferred-work consumer
(`PromotedRowRepair`), which also retires an outbound duplicate of a
composer's copy when one exists: the composer's copy adopts the locator, the
duplicate is stripped of its remote binding (so the trash push can never
relocate the provider's copy) and soft-deleted.

### Mail addressed to yourself

A message sent from a hosted mailbox to that same mailbox is **one row**, not a
Sent copy plus a delivered copy. `MailboxSender` writes the row at send time;
when the message completes the trip out through MX and back in through Postfix,
`InboundEmailRouter::storeMessage()` finds that row — by Message-ID scoped to
the mailbox, since the `(Message-ID, recipient, direction)` key differs in
direction by construction and is blind to a sealed row's ciphertext recipient —
stamps `iem_self_delivered` on it, adopts the delivery's DKIM/SPF/DMARC verdicts
where the row still holds placeholders, and stores nothing. Repeating the
delivery reconciles again rather than forking a row. `storeDirectMessage()`
applies the same rule, since Direct discovery can resolve a domain this
deployment hosts.

`iem_self_delivered` is what the Inbox reads: the view is otherwise "not
outbound", and the flag is the one exception, so the message lists once in the
Inbox and once in Sent, as it does in any mail client. Opening the conversation
shows one message.

A delivery stores its own row as usual when there is nothing to reconcile onto:
mail from outside, a self-send composed in another client, a self-send whose Sent
copy the member discarded (the delivery is then their only copy), and a matching
row that belongs to a different mailbox.

## Attachment & message storage

A stored push message is a **lean record**: the database holds the small, searchable
parts — headers, the decoded text bodies (`iem_body_plain` / `iem_body_html`), and the
attachment manifest — while every non-text MIME part (real attachments *and* inline
`cid:` images) is extracted at ingest into its own **private `File`**. The bytes live
in exactly one place — the `File` — so nothing is stored twice, and each attachment
inherits the `File` layer's bucket offload, small-VPS drain, and gated serving for free.
On the happy path **no raw RFC822 is retained** — except its **header block**,
kept byte-for-byte in `iem_raw_headers` (capped at 64 KB, sealed like the body on
a sealing mailbox): the wire truth (Received chain, `Content-Type`/charset, DKIM
as sent) that no parsed column preserves, and what the reader's Show original
renders for a lean record (`specs/mailbox_show_original_coverage.md`).

**The manifest is the glue.** Each `ima_` row keeps the email-specific MIME metadata
(filename, content-type, size, MIME section, encoding, content-id, inline flag) and, for
file-backed rows, `ima_fil_file_id` pointing at its `File`. Dispatch everywhere keys on
**presence of `ima_fil_file_id`**, not the transport:

| Manifest row | Where the bytes live | Serve / forward |
|--------------|----------------------|-----------------|
| `ima_fil_file_id` set | a private `File` (push mail, lean record) | read the `File` |
| no `ima_fil_file_id`, driver `remote` | the IMAP source | fetch the part on demand (`ImapIngestor::fetchPart`) |
| no `ima_fil_file_id`, stored raw | inside the raw (legacy / fallback row) | `getRawMimePart($section)` |

**Inline images are body content and are always file-backed.** The reader's
cid: rewrite (`MailboxService::resolveInlineImages()`) serves only file-backed
rows, so the IMAP ingest fetches each inline image part's bytes (5 MB cap)
beside the body fetches and adopts them at store time
(`AttachmentByteCustody::adoptBytes()`, sealed iff the message is). Inline
image rows that are still reference-backed are turned file-backed by the
`mailbox_inline_backfill` deferred-work consumer (`InlineImageBackfill`) — from
the IMAP source for `remote` rows, from the stored raw (in-window when sealed)
otherwise, retried at most daily per part (`ima_adopt_attempt_time`).

**Local bytes win.** A reference is what the platform has when it does not have
the bytes, so any path that turns up the message's raw bytes for a
reference-backed row takes them and the row becomes file-backed
(`AttachmentByteCustody::adopt()`). Concretely: a message ingested over IMAP
keeps only a manifest; importing an archive that holds the same message
deduplicates the message *and* adopts its attachment bytes; a live SMTP
delivery (Postfix or webhook) that dedupes against an IMAP-fed row — a combined
alias — hands its bytes over the same way from `storeMessage`'s dedup return;
and a Joinery Direct delivery does too (`adoptParts()` — Direct delivers
decoded parts rather than a raw document, so its parts are matched by
Content-ID or filename+type). The reverse order already keeps them — the
ingester only adds a server locator and leaves the manifest alone — so
connecting a mailbox, importing an archive, or receiving the same mail live
reach the same end state in any order, and the user never has to know which to
do first. Adoption streams each part to disk (never a whole-attachment string)
and is a bonus on top of dedup, never a condition of it.

The upgrade only touches rows whose bytes genuinely live elsewhere: a row with no
`File` on a message whose raw is stored locally is a **section pointer into that
raw**, so its bytes are already local and copying them out would duplicate
custody. A soft-deleted message is never upgraded, and a part that cannot be
matched to a manifest row **uniquely on both sides** — by Content-ID, then MIME
section plus type, then filename plus type — is skipped and logged rather than
guessed at, because attaching the wrong bytes to a row is worse than leaving a
working reference alone. Identity columns are never rewritten; `ima_size_bytes`
becomes the decoded size, which is what it means on every file-backed row.

**Attachment access has two doors, one rule each.** The member download endpoint
(`/profile/mailbox/attachment`) authorizes by **mailbox grant** for both
backings: the viewer may access the alias of the attachment's message
(`MailboxViewer`; a NULL-alias catch-all message is superadmin-only) — an
attachment is exactly as private as its message, so every grantee of a mailbox,
including permission-0 members and shared-mailbox teammates, downloads its
attachments. The admin endpoint keeps the File-level posture for file-backed
rows: each attachment `File` carries `fil_private`, so `File::is_viewable()`
admits the file's owner (the single grantee of an individual mailbox; a shared
or catch-all alias is owned by `User::USER_SYSTEM`) or any admin (≥ 5) — the
same algorithm serve.php's `/uploads/*` path uses. No image variants are
generated — attachments are served as their original.

**Ingest is all-or-nothing per message.** Every non-text part is minted as a `File` via
`File::createFromBytes()` and linked in the manifest; the text bodies are extracted as
today. If any `File` write fails (disk full — the pressure this design relieves), the
message's Files are rolled back and it **falls back** to persisting the whole raw with a
section-pointer manifest — the raw-storage shape below. The degradation chain is **lean
record → raw-to-disk → inline-in-DB**; ingest never aborts, and the fallback logs a
distinct `INBOUND_ATTACHMENT_EXTRACTION_FAILED` marker so an operator sees disk pressure.

**Download** streams the bytes by where they live (see the table): a file-backed row
reads its `File`; `remote` fetches the one part from IMAP; a legacy/fallback row
extracts it from the stored raw. Retrieval and streaming (original `ima_filename`,
attachment disposition, `nosniff`) are the shared helpers in
`includes/attachment_retrieval.php`, used by both download endpoints — each endpoint
gates first with its own authorization posture, then retrieves. A `.eml` is never
reassembled from these parts; the export serves the stored original or nothing.

**Preview reads an attachment without opening it.** Beside every attachment the platform
can turn into text, the reader draws an eye button; it opens a modal containing the
document's **words as plain text** and nothing else — no page is rendered, no markup
becomes a document, no font is loaded, no URL is fetched. The endpoint is
`mailbox/attachment_text` (POST `attachment_id`), gated on the **same mailbox-grant rule
as the download** (a preview is exactly as private as the file), throttled at 30 per five
minutes per IP because each one costs a subprocess — refusals count against the throttle
too — and ceilinged by `mailbox_preview_max_bytes` (15 MB) from the attachment's recorded
size before any byte is fetched or decrypted. Parsing itself is
[`DocumentText`](../../../docs/document_text.md) — PDF, Word, Excel, PowerPoint,
OpenDocument, EPUB, RTF, XML/SVG, forwarded `.eml`, calendar invites, the whole text
family, and a name-and-size manifest for a `.zip`. Whether the button appears is decided
from the declared type **and** the filename, because most real PDFs arrive declared
`application/octet-stream`; that is a UI hint only, and the extractor re-sniffs the bytes
regardless. A scanned document, an encrypted one, and one too large to read each get
their own sentence rather than a shared failure line.

**A picture gets the other kind of preview.** There is no text inside a photo to pull out, so an image attachment opens as the picture itself — the one preview that really is decoded, by the browser's image decoder. It is offered because the alternative it replaces is worse: downloading the file and opening it on your own computer. The modal says so plainly rather than repeating the text preview's promise. The bytes come through the same gated download endpoint and are given an image type in the browser, so a sender's declared type never decides how the response is treated, and a file that is not really a picture simply fails to decode. SVG is deliberately not in this path: it is markup wearing an image's name, and it previews as text. Which kind of preview an attachment gets — `text`, `image`, or none — is `MailboxService::previewKind()`, and the reader carries it as `preview_kind` on each attachment. On a protected mailbox the bytes
are sealed, so a locked vault answers `{locked:true}` and the modal offers the one-tap
unlock. Nothing is cached: extracted text from a sealed message is exactly as sensitive
as the sealed body, and re-reading costs milliseconds.

**Forward** re-attaches the original's parts in one manifest-driven loop dispatching per
row: a file-backed row reads its `File`, `remote` fetches from IMAP, a legacy raw row
extracts the section. An inline (`cid:`) part is **re-embedded** with its original
Content-ID via `EmailMessage::attachInlineData()` so the forwarded HTML body's `cid:`
references still resolve in the recipient's client; every other part attaches normally.
The message is rebuilt fresh (forwarding re-signs DKIM/SRS), so byte-exact replay was
never on the wire.

**Inline images in the readers.** `MailboxService::resolveInlineImages()` — the
single `cid:` rewrite implementation, shared by the Mailbox Reader thread
endpoint (`ajax/mailbox_thread.php`), the single-message detail page
(`admin_mailbox_message.php`), and the native transport
(`withSignedTransport()`) — resolves each `cid:<id>` reference in an HTML body
to a short-lived **signed URL** (`docs/file_signed_urls.md`, 1-hour TTL for the
web readers) for the manifest row whose `ima_content_id` matches `<id>` **in
that message only** — a Content-ID can never reach another message's parts.
Signed URLs are required, not optional: the body renders inside a sandboxed
`srcdoc` iframe whose opaque origin attaches no cookies to subresource
requests, and mailbox visibility is a grant decision (`MailboxViewer`) that
`File::is_viewable()`'s owner-or-admin rule cannot express — so a session-gated
`/uploads` URL can never authorize inline images for any reader. Minting is the
authorization statement: the resolver runs only on messages the caller has
already scope-checked (the viewer's grant scope, or the admin permission gate).
A link that outlives its TTL renders broken until the message is reopened,
which mints fresh ones. Unmatched `cid:` references are left as-is (broken).
This applies to file-backed inline parts; a purely on-demand IMAP (`remote`)
inline part is not resolved here.

### Raw storage (fallback, legacy, and IMAP)

When a message is stored as a raw (the extraction fallback, or a legacy row), the heavy
raw RFC822 lives in the cheapest durable store for its transport, **not** in the
`iem_raw_message` column. A per-row **storage descriptor** (`iem_raw_storage_driver`) says
where, and one accessor resolves it so callers are tier-blind:

| Driver | Where the raw lives | Set by |
|--------|---------------------|--------|
| `inline` | `iem_raw_message` (the column) | lean records (empty), legacy rows, and the local-write-failure fallback |
| `local` | a file under `{site_root}/storage/`, keyed by `iem_raw_storage_key` | the extraction fallback (raw-to-disk) |
| `cloud` | an object in the verified-private store, the **same** key | the shared cloud-offload engine; reversible |
| `remote` | no platform copy — parts fetched on demand from the IMAP source | the IMAP poller (`storeExtracted`) |

`iem_raw_storage_key` is a single **tier-invariant** relative key,
`mailbox/{yyyy}/{mm}/{message_id}.eml` (received-month shard). The local tier
prepends `{site_root}/storage/`; the cloud tier prepends the shared bucket's
`{site_template}/` prefix automatically — so offload is a flag flip + byte copy with no
key rewrite. `RawMessageStore` (the mail `StorageProfile`) owns the key scheme and the
request-time `write` / `read` / `delete`. `InboundEmailMessage::getRawMessage()` returns
the whole raw and `getRawMimePart($section)` one decoded part, dispatching on the driver
(`inline` reads the column, `local` the file, `cloud` pulls the private object to a unique
temp and unlinks it, `remote` yields null so the caller fetches from IMAP). A transient
cloud outage surfaces a clean "temporarily unavailable", never a fatal.

**The cloud tier is the platform's verified-private store**, reached only through the
shared offload layer's server-side `get()` behind a permission gate — never a public URL
or presigned link. Both attachment `File`s and any fallback raw declare
`visibility = 'private'` and inherit the private store, the privacy gate, the offload
engine, and the admin lifecycle from the cloud-storage layer (see
[Cloud Storage](../../../docs/cloud_storage.md)). Until a private store is configured,
bytes stay `local` on disk forever — the feature degrades cleanly to local-only. Offload
runs through the platform's single `CloudOffloadRun` tick; `RawMessageStore` is declared
under `storage_profiles` in `plugin.json`. Because the plugin owns a `private` profile,
**uninstalling** it requires the mail store drained back to local first (the offload
layer's drain-before-uninstall rule); deactivation alone is safe.

**Durability.** `{site_root}/storage/` is durable runtime data on par with `uploads/`
and `backups/` — it **must** be backed by a persistent Docker volume (`{site}_storage`),
or a container rebuild destroys stored mail. The install layer provisions and persists
it; `upgrade.php` never touches runtime data dirs, so `storage/` survives upgrades. The
directory and the private bucket are **never** web-served. Permanent delete / purge of a
message reclaims its attachment `File`s **and** any stored raw through the message's
hard-delete hook (the single reclaim path); soft delete leaves everything in place (the
row is recoverable).

## Security levels

**Protection attaches to the identity that owns the mail.**

For **hosted mail** that identity is the domain: MX, SPF, DMARC and DKIM are
domain-level facts, so the level lives on the domain (`ied_security_level`) and every
mailbox under it inherits. It is chosen on the domain editor as a required three-card
picker (outcome language only, default **Standard**).

For **pulled-in mail** — a mailbox collected over IMAP — that identity is the mailbox.
`gmail.com` is not an identity this deployment holds; it is somebody else's domain that
we hold one account on, and two people pulling their own Gmail into one site have
nothing in common to share a setting with. So a pulled-in mailbox carries its own level
(`iea_security_level`), chosen in the mailbox editor, and its provider domain carries
none at all: it is forced Standard, its picker is hidden, and the Accounts tree shows no
badge on it. **Fortress is domain-only** — it is an identity guarantee (relay-side
sealing, inverted DNS, in-app signing), and none of it exists for mail on somebody
else's server; a pulled-in mailbox offers Standard and Private.

This is deliberately *not* a general per-mailbox override. A hosted domain keeps exactly
one answer, because its DNS-shaped guarantees cannot vary per mailbox.

`InboundEmailAlias::security_level()` / `seals_content()` are **the** resolver — own
value when set, the domain's otherwise. Every content-sealing decision asks the alias;
nothing else reads `iea_security_level`, and nothing else reimplements the inherit rule.
Domain identity — DKIM, the protected sending identity, the DNS shape, the relay map —
keeps asking the domain.

The level switch itself is the same either way, and so is the ceremony: raising or
lowering a pulled-in mailbox runs the checklist, the server-side re-verification, the
receipt card and the backlog sealing that a domain does, scoped to that one mailbox.
There is no second ceremony.

| | **Standard** | **Private** | **Fortress** |
|---|---|---|---|
| Meaning | The server manages this mailbox for you | Only you can read stored mail | Even a fully hacked server can't read new mail or send as you |
| Stored bodies/subjects/attachments/search index | plaintext | sealed at rest | sealed at rest |
| Fresh inbound sealed before reaching Joinery (relay) | — | — | ✓ (pending-parse until unlock) |
| Outbound signing | ambient (opendkim) | ambient | in-app, session-gated |
| Automated sends (no login) | ✓ | ✓ | ✗ — sending is session-gated |
| Search | SQL | in-window FTS | in-window FTS |
| Best for | club signups, newsletters | mail worth keeping private, automation still runs | the address that *is* you |

**Where the level switches behavior:**

- **Ingest** — every store path resolves one answer through
  `InboundEmailRouter::resolveSealTarget()`: the MAILBOX's posture decides, and mail
  with no mailbox asks the domain. A Standard mailbox stores plaintext even when its
  owner has a vault. A sealing mailbox that cannot produce a key **declines the
  message** rather than writing it in the clear — see *A sealing mailbox always has
  someone to seal to* below. This covers pushed mail (`storeMessage`), Direct
  (`storeDirectMessage`) and IMAP-polled mail (`storeExtracted`) alike. Composing
  is an ingress path too and asks the same resolver, through
  `MailboxSender::sealTargetFor()` — used by the send and by the draft autosave, so
  a draft and the message it becomes can never disagree about being sealed.
- **Relay seal target** — `RelayMapExporter::sealTargetForAlias()` seals to the owner's
  vault key (`key_kind=user`, producing Fortress pending-parse rows) **only** for a
  Fortress domain; every other posture seals to the ambient transport key, which
  Joinery opens at pull and re-seals per the domain's own level.
- **Setup/health DNS shape** — `InboundEmailSetupCheck` expects the inverted protected
  shape (SPF without the box, `p=reject; aspf=s; adkim=s`, DKIM matching the sealed
  key) for a domain whose `ied_is_protected_identity` flag is set, and for that
  domain only. The **enforcement flag is the branching key, not the security
  level**: the shape instructs the world to reject anything the sealed key did not
  sign, and `MailboxDkimSigner` signs with that key only once the flag is on, so
  prescribing it at the level would hand a Fortress domain without send protection
  a record set that rejects its own outgoing mail. The ceremony asks for the shape
  explicitly while it runs (`dnsPlan($domain, true)`), which is the one exception.

**Raising a level runs the protection ceremony**
(specs/mailbox_protection_ceremony.md, `includes/protection_ceremony.php`).
Choosing a card above the current level reveals a prerequisite checklist on the
domain editor — every row a verdict with an in-place fix — and the save is
refused server-side until every required row passes (the button state is a
convenience, `mailbox_protection_rows()` re-verification at save is the
enforcement):

- **One reader per mailbox** — protected mail seals to one person's key; a
  shared mailbox renders its holders with inline remove-access buttons, a
  holderless one an add-owner link.
- **Every reader holds a vault** — evaluated per HOLDER (the sealing target),
  never the admin running the save. The session user's own missing vault links
  to `/profile/security?return=…`, which bounces straight back after setup;
  another holder's names them (an admin cannot create someone's zero-knowledge
  vault).
- **Unlock by touch** (recommended) — a PRF-capable passkey per holder.
- With the `passkeys_enabled` kill switch off, one required blocker row says
  so — vault setup itself runs through a PRF passkey.
- **Fortress adds** a relay-fronted required row and an info row announcing
  the DNS/protect stage; activation saves the level (relay-side sealing and
  the inverted-DNS prescriptions start immediately) and routes into the
  verify-gated protect ceremony exactly as before.

**A raise lands on the receipt card** (specs/mailbox_raise_receipt.md,
`mailbox_protection_receipt_render()`), the same surface that guided the raise
in. Its title states the event ("This domain is now Private"), its green-dot
rows state the completed facts (earlier messages sealed with the real count,
new mail seals on arrival, reading takes the holder's unlock), and one button
opens the mailbox. Sealing is per-row, so earlier mail was stored plaintext —
the card converges history in place: page JS loops the `mailbox/seal_batch`
API action (bounded batches via `mailbox_protection_seal_batch`, 200 rows per
pass — sealing needs only the holder's vault PUBLIC key, so any admin session
drives it), counting the progress row down until the backlog is empty, then
resolves it into the sealed-count fact. A batch that seals nothing while rows
remain (a holder's vault deleted after the raise) stops the loop with a red
row pointing at the Setup tab; without JS a noscript form runs the same
batches one page load at a time. A Fortress raise before outbound protection
is activated renders the card as a handoff — the title stays honest
("Earlier messages sealed — one step left") and the button continues into the
protect ceremony. The Setup tab carries a per-domain **Mail sealed at rest**
row, which separates the two states that wear the same not-sealed-yet flag.
A message the pass *can* seal — the mailbox has one holder and that holder
has a vault — is a RECOMMENDED WARN counting how many are waiting: every
message is briefly unsealed between arriving and being sealed, so a failure
there would fire on ordinary delivery. A message no pass can ever seal — no
holder, several holders, or a vault deleted after the raise — is the REQUIRED
FAIL, naming the mailbox, because that is protection silently degrading.
Relay-sealed rows awaiting parse (`iem_pending_parse`) count as neither: the
relay already sealed them to the owner's vault public key, and they open at
the owner's next unlock. PASS says so when any are in flight. The editor
resumes the sealing pass on its next visit whenever a backlog exists.

**A lowering converges history back out**
(specs/mailbox_lowering_unseal.md). Leaving a sealing level lands on the
lowering receipt card — "This domain is now Standard" — which unseals earlier
messages in place. Unsealing is the asymmetric twin of sealing: it needs each
row's DEK, which unwraps only inside the sealed owner's browser-session
unlock window, so convergence is always caller-scoped
(`mailbox_protection_unseal_batch`, driven by the `mailbox/unseal_batch`
action; the lowering save's vault-open gate guarantees the acting user's own
rows can converge immediately). Rows sealed to other holders wait for those
holders: the reader mount quietly runs the same batches for any signed-in
user with sealed rows on non-sealing domains, so each holder's next unlocked
visit finishes their share. Pending-parse rows (a lowered Fortress domain's
relay blobs) drain through `DeferredIngest` first and unseal on a later pass.
`unsealAndPersistContent()` is recovery-safe: plaintext writes back
per-file/per-flag and the key wrapping clears last, so an interrupted pass
always leaves a still-sealed row for the next pass, never a stranded
ciphertext. Search follows the scope's actual sealed content
(`scopeSealedContentActive()`): the sealed FTS index serves a scope only
while a mailbox in it seals or sealed rows remain — a fully-converged lowered
mailbox searches plain Postgres FTS with no unlock. The Setup tab carries an
INFO **Sealed leftovers** row naming any not-yet-converged count.

**Protected-domain invariants enforce at the mutation points**: on a domain
that seals content, the alias editor refuses a second member on a mailbox and
refuses a memberless mailbox (`mailbox_protected_grant_error()`), so the
raised state cannot be corrupted afterward.

**Protection badges.** Every domain row on the Accounts tree shows its level
as a badge linking to the domain editor (the badge IS the path to raising
protection); mailboxes on protected domains carry the badge on their Accounts
rows, and in the reader (both the staff reader and `/profile/mailbox/mailbox`) as
a chip beside the name of the open mailbox in the conversation-list header. The
chip states the level of the one mailbox being read, so the all-mail view — which
spans mailboxes that may differ — shows none. Each unmatched box states its own
domain's level, which it can do honestly because it holds one domain's mail.

**Rows are sealed per-row** (`iem_content_sealed`): mail sealed under one posture stays
readable after the domain's level changes — the read hooks key off the row, not the
domain. Lowering a level changes future ingest only, and never unseals: sealed
rows stay sealed and readable in-window.

**Group-collaboration mailboxes are Standard-only** (the one-operator/one-key model every
protected level rests on doesn't cover multi-reader sealing); the domain editor refuses
to raise a domain whose alias has more than one live grant.

### A sealing mailbox always has someone to seal to

A mailbox that seals encrypts to **exactly one holder**, and that holder must have a
vault. With neither, sealing has no key — and a message stored in plaintext on a
protected mailbox is the worst outcome available, because the read path dispatches on
the row's own `iem_content_sealed` column, so a row that lands plaintext renders
plaintext forever.

Two things hold the rule, and between them the bad state is unreachable rather than
repairable:

- **`InboundEmailMailboxGrant::sync_for_alias()`** refuses any change to a sealing
  mailbox that would leave other than one holder, or a holder without a vault. Every
  grant-writing surface goes through it — the alias editor, the combined mailbox editor,
  headless provisioning, the ceremony's inline fixes. The one write that does not is the
  user-delete cascade, so deleting the sole holder of a sealing mailbox is refused too,
  at the moment the deletion is attempted.
- **Store time declines rather than downgrades.** If a sealing mailbox somehow cannot
  produce a key, the message is not stored — and declining always means *try again
  later*, never a bounce: the Postfix pipe exits tempfail, the webhook answers 503, the
  IMAP feed leaves the message on the source and reports why it stopped, a Direct
  delivery is held with its parts, and the relay blob stays on the relay un-acked. Once
  the mailbox is repaired, the held mail flows in on its own.

The Setup tab's **Protected mail can be sealed** row
(`InboundEmailHealth::checkSealingMailboxHolders()`) names any mailbox in that state,
so it is seen well inside the shortest sender retry window rather than after it.

There is no sweep and no janitor: with the invariant enforced there is nothing to sweep.

**Automated mail on a Fortress domain** uses the subdomain pattern: put the automated
senders on `mail.<domain>` at Standard. Under Fortress's strict DMARC alignment the
Standard subdomain's keys cannot sign as the bare domain, so the split is safe by
construction.

### The locked-state surface contract

Logged in but locked is the state a Private/Fortress user sees most often, so it is
defined once for every surface: **every surface shows cleartext metadata; every content
action becomes a one-tap unlock prompt, and the original action resumes after unlock
without re-navigation.** A sealed or Fortress-pending row renders the neutral
placeholder `Sealed message` (`MailboxService::SEALED_PLACEHOLDER`) — never a visible
third state — while threading, unread, labels, folders, times, and sizes render
normally.

- **Web reader** (`mailbox_reader.js`) — `listThreads()`/`getThread()` carry a top-level
  `locked` flag; opening a sealed thread or searching sealed mail shows an inline
  *Unlock to read* button that runs the shared platform ceremony
  (`JoineryVaultLock`, [Sealed Vault § The lock chip](../../../docs/sealed_vault.md))
  and re-runs the original request. Explicit lock lives on the platform lock chip; the
  reader listens for the platform's `joinery:vault-locked` / `joinery:vault-unlocked`
  events, so a lock or unlock from the chip re-seals or reveals content in place.
- **Native `/api/v1`** — `mailbox/thread_list`, `mailbox/thread`, and `mailbox/mailboxes`
  return metadata plus `locked` (per-mailbox on the switcher, with each mailbox's
  `security_level`); `mailbox/send` returns `locked: true` instead of sending when a
  Fortress compose has no open window (`MailboxLockedException`); `mailbox/thread_action`
  (mark/star/delete) is cleartext metadata and keeps working while locked.

### AI processing

Recipes read mail through the `query_model` tool (`InboundEmailMessage` is
`$ai_readable`, with sender/subject/body wrapped as untrusted input). On a protected
domain a locked row is **excluded** from AI results (never a placeholder) and stays
pending for post-unlock catch-up; `query_model` reports the excluded count so the model
knows the result set is partial. The LLM provider is a disclosure, not a level gate.

### Notifications & offline cache (native contract)

Push content is set by when plaintext legally exists: Standard = full (sender/subject/
snippet); Private = sender + subject (generated at the ingest moment, pre-seal), with an
optional per-mailbox generic-notifications toggle; Fortress = generic by construction
("New mail to `user@domain`"). Native offline cache defaults on for Standard/Private and
off for Fortress. (These ride the native app + push packages.)

## Encryption at rest

A mailbox seals when its **posture** says so — Private or Fortress, resolved per
mailbox — *and* its **single owner** (the alias's one grantee — a shared or
catch-all mailbox is never sealed) holds a Sealed Vault (`docs/sealed_vault.md`, the
platform's per-user X25519 key hierarchy and unlock window). Mail is the vault's first
consumer: it supplies its own AD row-binding convention and content, and reuses every
generic vault mechanism — key hierarchy, unlock window, the File decrypt hook, the
sealed-field model hook, rotation, revocation. See the [Passkeys](../../../docs/passkeys.md)
and [Account Security](../../../docs/account_security.md) docs for the sign-in and
unlock ceremonies themselves; this section covers only mail's own participation.

**What's sealed.** `InboundEmailRouter::storeMessage()` resolves the owner's vault
*before* the insert: a sealing row is written with **empty** content columns from the
start (`iem_sender` / `iem_subject` / `iem_body_plain` / `iem_body_html`), then a fresh
per-message DEK seals each field and the row is UPDATEd — no plaintext is ever written,
even transiently, and the insert + seal UPDATE run as **one DB transaction**, so no
empty-content row can ever survive a seal failure (a failed delivery tempfails and the
MTA's retry re-inserts from a clean slate instead of hitting the dedup constraint).
`iem_sealed_key` (the DEK, sealed to the owner's vault public key),
`iem_key_generation` (0 = never sealed), `iem_sealed_owner_user_id` (whose vault, recorded
at seal time — decryption resolves the owner from the row itself, so later grant or alias
changes can never strand sealed mail), and `iem_content_sealed` (true once the UPDATE
lands) mark the row. Attachments seal under the **same** DEK
(`InboundEmailRouter::extractAttachmentsToFiles()`), AD-bound to their MIME part id — no
chicken-and-egg with the manifest row's serial id, which doesn't exist yet at seal time —
and each manifest row records its own `ima_is_sealed`: sealed state is a per-file fact
about the stored bytes, never an inference from the message's flags. If attachment
extraction fails for a sealed mailbox, the whole raw is preserved **sealed** (one AEAD
blob under the same DEK, `iem_raw_sealed = true`, opened in-window by
`getRawMessage()`) with a section-pointer manifest — the same durability a plaintext
mailbox gets from the raw fallback, with nothing written in the clear.
A composed outbound row (`MailboxSender::storeOutboundRow()`) seals identically (same
one-transaction insert + seal), and also seals `iem_recipient` — an outbound row's
recipient list is real content (who you emailed; stored untruncated — the column is
`text`), unlike an inbound row's `iem_recipient`, which is the receiving alias address
(routing metadata, never sealed regardless of the row's sealed state). Standard-tier
mail (no vault) is unaffected: `iem_content_sealed` stays false and every column holds
plaintext, exactly as before this package.

**Reading.** `InboundEmailMessage::$sealed_fields` + `decryptSealedField()` /
`decryptSealedFieldStatic()` are the Sealed Vault's generic model read hook: any
`$msg->get('iem_body_plain')` on a loaded model decrypts automatically when the owner's
window is open, and throws `VaultLockedException` when it isn't. `MailboxService`'s raw
SQL reads (`listThreads()`, `getThread()`) batch-decrypt through
`decryptSealedFieldStatic()` directly (mirroring
`plugins/joinery_ai/includes/ModelQueryExecutor.php`'s raw-row hook), catching a locked
vault into a `[locked - unlock your vault to view]` placeholder rather than an error.
Attachments decrypt through `InboundEmailMessage::openSealedAttachment()` — the one
opener, which dispatches over **two sealed shapes** and plaintext, in that order:

| Shape | Flag | Key | Opened by |
|---|---|---|---|
| Self-sealed `File` | `fil_content_sealed` on the `File` | the File's own DEK (`fil_sealed_key`), wrapped to the owner's vault | `DriveSealed::fileKey()` + `SealedFileContainer` |
| Message-DEK blob | `ima_is_sealed` on the manifest row | the owning message's DEK (`iem_sealed_key`) | `VaultCrypto::openField()` |

The two are never both set on one attachment. The self-sealed shape is the same
one Drive and every other consumer uses, and it is the only one that can be
**written without an open window** — sealing needs a public key, so a background
import can seal bytes onto protected mail with nobody signed in. That is what
makes byte adoption possible on a sealed mailbox at all. An attachment is sealed
only when its message is, so a plaintext message keeps plaintext bytes even on a
mailbox whose current policy would seal new mail.

Because a self-sealed attachment answers ranges, it also registers a **streaming**
decryptor (`File::registerStreamingDecryptHook(File::SOURCE_EMAIL_ATTACHMENT, …)`)
which hands back a `DriveSealedStream`; it returns `null` for the other two shapes,
so they fall through to the whole-bytes hook. Resolving the key in `prepare()` is
what turns a closed vault into a clean `423` before any header is written, rather
than a `200` full of container bytes.

The opener is reached five ways, and every one of them goes through it rather than
inspecting a flag itself — which is what stops any of them handing back a sealed
container as a successful read: the generic `File` decrypt hook
(`File::registerDecryptHook(File::SOURCE_EMAIL_ATTACHMENT, …)`, registered by
`plugins/mailbox/includes/bootstrap.php`, called by `File::serve_from_path()` between
reading the on-disk ciphertext and streaming the response), the per-attachment download
endpoints (`includes/attachment_retrieval.php` opens explicitly after
`File::read_bytes()`, which bypasses the serve hook), a forward's re-attach path
(`MailboxSender::readOriginalPartBytes()`), the forward message synthesis
(`InboundEmailRouter`), and the unattended attachment digest
(`EmailAttachmentDigest`, a defensive catch that should never meet sealed bytes).
Each passes the `File` it already holds, purely to save a re-load — a caller that
does not still gets plaintext, because the opener resolves the `File` from the row.
A locked vault becomes a generic
`423 Locked` on the serve path and a clean "Unlock your vault" message on the download
endpoints — never a raw error or leaked ciphertext. The admin single-message
viewer (`admin_mailbox_message.php`) gates on the same key-possession rule as anyone
else — a permission-10 admin (including via login-as) with no open window for the
message's *owner* sees a `[locked]` placeholder, never real content; permission is not a
bypass.

**Bootstrap.** `plugins/mailbox/includes/bootstrap.php` is mail's one-time-per-request
wiring point, loaded lazily by `VaultUnlock::loadConsumerBootstraps()` from every code
path that needs a consumer's hooks live (the File decrypt-hook resolution, the rotation
ceremony, and window-close) — it registers the File decrypt hook and mail's
`VaultUnlock::onReseal()` / `VaultUnlock::onWipe()` callbacks in one place.

**Search.** A sealed mailbox's content columns are ciphertext, unsearchable in SQL.
`plugins/mailbox/includes/MailboxIndex.php` is a disposable, per-owner SQLite FTS5
index — sender, subject, both bodies, and attachment *filenames* (never attachment
contents) — held **only** in `/dev/shm` (RAM-backed, never touches disk in the clear)
for the lifetime of the unlock window. The table is contentless (`content=''`) and
positionless (`detail=none`), keyed by the message id as its rowid: a search returns
ids and nothing else, so the text is never read back, and every query is single
tokens ANDed, so positions are never consulted — the working copy is about an eighth
of what storing both would cost (~105 MB for a 101k-message mailbox). `PRAGMA
user_version` carries `MailboxIndex::FORMAT`; a working copy or restored blob of any
other format fails to open and is rebuilt from the sealed rows, so a shape change is
one rebuild per owner, never a migration. `contentless_delete` needs SQLite 3.43+,
which the provisioning check `checkSearchIndexEngine()` verifies with the real DDL.

Folding is batched, checkpointed, and bounded
(`specs/mailbox_search_incremental_fold.md`): every id is delete-then-insert (so
re-folding is harmless), the high-water mark advances per completed batch, and
`fold()` takes an optional deadline plus a non-blocking per-user `flock`
(`/dev/shm/mailfts_{uid}.lock`) so exactly one fold runs at a time — a second caller
searches what is already indexed and reports the backlog instead of contending. The
search request folds at most `MailboxService::SEARCH_FOLD_BUDGET_SECONDS`; a backlog
beyond that (a bulk import, a long-offline owner) drains in-window through the
`mailbox_fts_fold` deferred-work consumer, and until it catches up the response
carries `search_indexing: {remaining, total}` and the reader shows a non-blocking
"still indexing" banner. A failed SQLite write stops the pass with the mark at the
last contiguous success — the mark never advances past a message that is not actually
in the index.

A fold that completed or processed refolds re-seals and persists the working copy as
a private File (seal-after-fold; the sealed blob and its bookkeeping — high-water
mark, blob coverage, sealed DEK — live in `imi_inbound_mailbox_search_index`); a fold
mid-backlog persists every `PERSIST_MIN_ADVANCE` messages, so a window close costs at
most one chunk of re-folding rather than a per-slice rewrite of a multi-hundred-
megabyte blob. The blob records the mark it covers (`imi_blob_high_water`) and a
restore resets the live mark to it, so a blob that lags the mark can never open a
silent coverage gap. A fold that changed nothing persists nothing, so repeated
searches over unchanged mail never rewrite the blob. Sealing and
restoring stream path-to-path in the chunked `v1.stream.` secretstream format
(`VaultCrypto::sealFieldFile`/`openFieldFile` over `SealedBox::sealStreamFile`/
`openStreamFile`), so memory stays proportional to a chunk — never to the mailbox — at
any index size. Missing, stale, corrupt, or in any other sealed shape → `rebuild()`
from the sealed message rows; the cache is never the source of truth. `InboundMailboxSearchIndex::sweepWorkingCopies()` is the
passive-close safety net for a working copy the wipe callback missed (an idle APCu
expiry, a worker recycle); it is declared as that class's `$retention_policy` and runs
in the daily retention sweep, so worst case a copy lingers until the next sweep.
`MailboxService::listThreads()`'s `q` path consults the **viewer's** index for
whatever part of the scope the viewer holds a grant for — one mailbox or all of
them (`sealedIndexScope()`) — and unions those hits with the plain Postgres
`tsvector` search, which answers for the rest of the scope (another member's
unsealed mailbox under an all-access view, unmatched mail) and simply never
matches a sealed row's ciphertext — so its GIN index (`iem_fulltext_unsealed_idx`)
is partial on unsealed rows, and every full-text query carries the same predicate
(`MailboxService::FULLTEXT_INDEX_PREDICATE`) so the planner uses it. Locked surfaces as `search_locked` in the
response, not a silent empty result, and the Postgres half still runs so unsealed
hits show under the unlock prompt; a scope with no sealed content left needs no
unlock and no index.

The index covers **every stored message** in the owner's mailboxes — trashed ones
included, drafts excepted — and the **read scope decides what a search returns**: hits
are intersected with the caller's scope, so a mailbox search never surfaces trashed
mail and a Trash search finds it. One rule, in one place. The Inbox tab is not a
search scope: a query typed there covers All Mail — archived and sent included — and
the response carries `search_scope: 'all_mail'` so the reader labels the widening;
explicit scopes (Trash, Spam, Drafts, a label) bound their own searches. Coverage cannot be narrowed by
filtering the fold, because the high-water mark advances past every row a pass *saw*: a
row the fold skipped is skipped permanently, and a rebuild runs the same query. Pruning
follows the row's existence rather than a flag — `MailboxIndex::enqueueRefold()` queues
an id whose row is about to go, and the refold pass re-inserts only if the message is
still there. The queue also carries content that appears *behind* the mark: a
pending-parse row folds as a no-op (its content fields do not exist yet) and
`parsePendingMessage()` enqueues its refold when the fields land. Processed refolds
leave the queue only after a persist has carried them (or when no blob exists), so a
restore of an older blob can never revive a stale entry.

**Key rotation and window close.** Mail's `VaultUnlock::onReseal()` callback re-seals
every message on the generation being drained (`iem_key_generation =
old_key_generation` — the only generation the ceremony's old secret can open; idempotent,
so a retry skips already-flipped rows), purges the FTS blob (sealed under the
now-superseded key; the next unlock rebuilds it), and **throws** if any row failed —
per the vault's re-seal contract, so the ceremony never retires a generation whose mail
is still sealed to it. Its `VaultUnlock::onWipe()` callback clears the `/dev/shm`
working copy on an explicit lock, a credential event, or `lockAll()` — the persisted
sealed blob is untouched, so the next unlock restores it without a rebuild.

**No sideways copies.** The inbound log viewer (`iel_inbound_email_logs`) never carries
subject or body — every write passes an empty subject, sender/recipient addresses are
logged as routing metadata only. Content-derived AI processing
(`plugins/joinery_ai/pipeline_jobs/EmailSecurityScanJob.php`) excludes sealed rows from
its candidate pool outright: it runs unattended with no unlock window, so a sealed
message is simply never a scan candidate, not a retried failure. `LearnSpamFeedback`
already only trains from a message's raw RFC822, which a sealed message never
retains — nothing further was needed there.

**Pre-launch backfill.** `logic/backfill_seal_logic.php` (an in-window,
session-authenticated API action, `mailbox/backfill_seal`) converges a user's
already-stored, not-yet-sealed mail to the sealed form once they set up a vault — one
bounded batch per call, called repeatedly until `done: true`. It seals what the read
path expects per direction: an outbound row's `iem_recipient` seals as content, an
inbound row's stays plaintext routing metadata. A message still carrying
its raw (a legacy fallback-stored row) re-splits its attachments into sealed Files and
destroys the raw; an already-lean row (Files already extracted before the vault
existed) has its content columns sealed while its existing attachment Files stay
plaintext — safe, because every byte reader keys on the per-file `ima_is_sealed` flag,
so those Files keep streaming as-is (the accepted pre-launch residual; there are no
production users yet).

**Per-page cost.** The reader reads `iem_inbound_email_messages` by mailbox on every
page load — the Inbox list, the rail's unread badge, the per-mailbox totals — and the
message class declares two partial indexes for exactly those reads (live rows by
mailbox with the archived/read/spam/direction columns the queries filter on, and trashed
rows by mailbox). The queries are shaped to them: the Inbox filter is `iem_is_archived =
false` (the column is NOT NULL; `IS NOT TRUE` is not a btree operator), and totals and
unread are two queries so the totals run index-only. A page of threads opens only the
content it shows: the sender of every message, the subject/body/summary of the newest
per thread, and the HTML body only when the plain part yields no preview.

**Provisioning.** `ext-sqlite3` (with FTS5 compiled in) backs `MailboxIndex` and has no
fallback — without it, search on a sealed mailbox is simply unavailable (the reader
surfaces this, not a 500). `InboundEmailHealth::checkSearchIndexEngine()` verifies both
the extension and FTS5 support. The unlock window's own host-hardening facts (APCu
`apc.mmap_file_mask`, swap, coredumps) are the vault's own `VaultHealth` check
(`includes/VaultHealth.php`), not repeated here.

### Mail that belongs to no mailbox

The catch-all accepts mail for addresses nobody created — `postmaster@`, a typo,
an address a spammer guessed. That mail is stored with no alias, so it has no
mailbox and therefore no mailbox owner.

On a sealing domain it seals to the **domain's owner**
(`ied_owner_usr_user_id`, the same person whose vault seals the domain's DKIM
key). It arrived for the domain, so the domain's owner is whose it is. One key
covers every such address, and no mailbox has to be created per address.

`InboundEmailMessage::sealOwnerUserId()` is the single answer to "whose key does
this message seal to", used by both delivery and the backlog pass so the two can
never disagree. The fallback applies **only** to mail with no mailbox: a mailbox
with no owner, or with several, still has no single key and stays unsealed until
an operator fixes the mailbox. Sealing one of those to the domain owner would
hand someone else's mail to a third party.

Two consequences worth knowing. Only the domain owner can read unmatched mail —
other all-access admins still see the rows but cannot decrypt them. And a domain
cannot sit at Private or Fortress without an owner who holds a vault: the
protection ceremony makes it a required prerequisite, with an inline control for
the acting admin to claim ownership.

### Letting AI read a sealed domain's mail

The AI email features (triage, security scan, calendar extraction) cannot read
mail that is encrypted at rest unless the owner is signed in with their vault
open — and even then, only if the domain has been set to allow it.

`ied_ai_processing_enabled` is that switch. It is off by default, appears on the
domain form only for Private and Fortress domains (at Standard the server
already reads the mail, so there is nothing to consent to), and turning it on
requires a recent identity confirmation. Turning it off never does — withdrawing
consent must not be harder than giving it.

With it off, saving a recipe pointed at a mailbox on that domain is refused, and
the message names the domain and the setting. The refusal happens at save time
rather than at run time, so the failure mode is an explanation rather than a
recipe that silently does nothing.

What it buys, stated plainly: with it on, the server reads that domain's mail
during an unlock window and sends it to the configured model host. Overnight
processing remains impossible on a sealed domain — summaries appear shortly
after the owner opens their mail, never before they arrive.

### Parsing the backlog

Fortress mail that arrived while the owner was logged out is stored unparsed.
`DeferredIngest` turns it into readable fields, and is registered as a
[deferred-work consumer](../../../docs/sealed_vault.md#deferred-work-in-the-window),
so the backlog drains wherever the owner is on the site with their vault open —
not only when they open the mailbox. It parses newest first, so the most recent
mail becomes readable first, and because the AI jobs skip unparsed mail and also
take the newest first, the two never work against each other.

## Outbound send protection

Encryption at rest protects *reading* stored mail while locked; outbound send
protection protects the *sending identity*. A domain flagged
`ied_is_protected_identity` is a **protected sending identity**: while no unlock
window is open, no credential on the box can produce a DMARC-passing message with
a `From:` header at that domain. The enforcement point is other people's mail
servers applying the domain's published DMARC policy — infrastructure a
compromised (even root) box does not control.

**The invariant holds by closing every ambient send path:**

- **Sealed DKIM key, signed in-app.** The domain's DKIM private key is generated
  in-session, sealed to the owner's vault public key (`ied_dkim_sealed_key`, a
  `crypto_box_seal` envelope — the same one message DEKs use), and stored in the
  database. The plaintext never touches disk and is never given to opendkim. At
  compose time, inside an unlock window, `MailboxDkimSigner::resolveFor()` unwraps
  it and PHPMailer signs with it as an in-memory string (`DKIM_private_string`),
  zeroized (`sodium_memzero`) as soon as the send returns. Core send code names
  no mailbox symbol: `SmtpProvider` and `EmailSender` read two callables the
  plugin registers on `MailIdentityGuard` at bootstrap (a protected-domain
  predicate and the DKIM signer resolver, memoized per request). A locked window
  makes the resolver throw, and the compose path prompts a one-tap unlock rather
  than sending unsigned.
- **Protected compose submits through the box's own SMTP transport.** A hosted
  alias on a protected domain resolves an `SmtpProvider` on the forwarding SMTP
  coordinates (`OutboundTransport::forHostedAlias()`), never the ambient
  platform provider — that transport is where the in-app signer runs, and the
  injected transport is what marks the send as the session-gated compose path.
  DMARC acceptance rides the strict-aligned DKIM signature alone; the domain's
  SPF excludes the box by design.
- **Box out of SPF; strict alignment.** The protected domain's SPF (`v=spf1
  -all`) does not authorize the box, and its DMARC is `p=reject; aspf=s; adkim=s`.
  Strict alignment is load-bearing: it stops the box-authorizing forwarding
  subdomain from aligning the bare domain.
- **SRS envelope on the forwarding subdomain.** Alias forwarding runs while
  logged out, so its SRS envelope leaves from `ied_forwarding_subdomain` —
  strictly per-domain, always a subdomain of the protected domain (e.g.
  `fwd.<domain>`), set on the Protect page. Its SPF authorizes the box and its
  MX points back at the box (Postfix accepts it via the pgsql domain map), so
  forwarded mail passes SPF and delivery-failure notices (DSNs to the SRS
  envelope) route back to the router. The forwarded message's `From:` is the
  original sender's own domain, so forwarding never needs the user's identity.
  The SRS bounce notification sends from the platform's default identity — the
  one the ambient provider is verified for — so the notice itself is
  deliverable.
- **Ambient senders refused.** `EmailSender::send()` refuses any transactional
  (no injected transport) send from a protected From-domain; only the
  session-gated mailbox compose path (which injects a transport) may send as the
  identity.

**opendkim keeps verify duty, not signing.** `provision_dkim.sh --remove
<domain>` strips the domain's `signing.table` / `key.table` lines and destroys
its on-disk key (a resting key is a resting send capability), leaving the in-app
per-send signer as the sole signer. `Mode sv` is untouched, so inbound
verification is unaffected.

**Setup verification inverts for a protected domain.** The Setup tab checks that
SPF *excludes* the box, DMARC is strict, the published DKIM record matches the
sealed key's public half (`ied_dkim_public_dns`, cleartext so it verifies while
locked), the forwarding subdomain's SPF authorizes the box and its MX resolves to
the box, and the domain is not relay-provider-verified. These are REQUIRED, so
`InboundEmailHealth` gates on them and activation blocks until the whole shape —
forwarding subdomain included — is published. One assembly
(`InboundEmailSetupCheck::protectedShapeResults()`) feeds both the Setup tab and
the ceremony's pre-activation verify, so they can never disagree.

**Fortress is a two-sided promise, and send protection is the second side.**
Nobody can read your mail (*arrival sealing*), and nobody can send as you (*send
protection*). Raising a domain to Fortress delivers the first half immediately
and seals a DKIM key (`mailbox_protect_seal_new_key()`), defaulting
`ied_forwarding_subdomain` to `fwd.<domain>`. **A Fortress domain without send
protection is not finished** — it is one anyone can still impersonate — and both
the raise receipt (*one step left*) and the `domain.send_protection` check row
say so. That row is REQUIRED, so an unfinished domain reads `attention`.

Unfinished is a **transit** state, never a resting one. It cannot be made
simultaneous with the raise — the switch needs published DNS and a vault unlock —
so the interface declares the domain in progress rather than pretending either
that it is done or that the remaining step is optional.

The step is not in the general setup path. It is the completion of the Fortress
raise, and the raise is already the advanced, gated ceremony. The guided box
carries a single *Finish Fortress* entry, gated on the relay being live and the
domain's MX cut over — offering the sending half before mail arrives through the
relay would ask an operator to finish what has not started — and it disappears
the moment protection is on.

The cost is real and is stated where the offer is made: every interactive send
needs an unlock, and automated mail must move to a Standard subdomain. Those are
reasons not to choose Fortress for a domain, not reasons to run Fortress
half-on.

**Send protection has no page of its own — the Setup tab's Advanced section is
its whole surface.** `includes/protect_identity.php` owns the state transitions
and nothing else; `mailbox_protect_handle_action()` runs any `protect_*` action
posted to Setup and redirects back to the focused domain with a flash. The
*Sending identity* box under Advanced holds the entire arc: what send protection
buys and what it costs, the owner question when the raise could not guess, the
publish step, the pre-flight verification, the switch, and afterwards the
lifecycle (replace the key, switch over, cancel, turn off, and the return address
behind a disclosure).

The ceremony opens on an explicit gesture (`?protect_setup=1`). Only inside it
does `dnsPlan($domain, true)` prescribe the protected shape; the DNS records and
the verification are not re-rendered anywhere else, and `protectedShapeResults()`
remains the single assembly.

**Ordering is load-bearing: no step may cause silent rejection.** Publishing the
strict records first tells the world to reject anything the sealed key did not
sign, while the sealed key is signing nothing — so mail leaves, the provider
accepts it, and the recipient discards it with no bounce anyone sees. The order
is therefore:

1. **Publish the DKIM record.** Changes nothing; asks nobody to reject anything.
2. **Start signing** — `protect_activate`, gated by `signingReadinessChecks()`
   (the sealed key's record plus the forwarding subdomain) and a vault unlock.
   DNS is still ambient, so mail passes on either signature. The cost lands here
   as a *visible* refusal: a locked vault stops the send with a message.
3. **Publish the strict SPF and DMARC.** The signature they demand is already on
   every message.

This is why `protectedShapeApplies()` branches on the enforcement flag: the flag
means *signing*, and the strict shape is prescribed exactly once signing is live.

**`domain.send_protection` reports the whole state in one row** — finished
(PASS); not signing (FAIL, Fortress unfinished); signing without the strict
records (WARN, forgeries not rejected yet); and strict records without signing
(FAIL), which is not a gap but an outage: the domain is rejecting its own mail.

**Lifting protection does not strand the DNS.** `protect_disable` clears the
flag and then computes the ambient shape, because leaving the strict records up
drops the domain into that fourth state. DNS credentials are ephemeral, so
nothing writes them in the background: the operator — who has just pressed the
button — is told exactly which records must change and is landed on the publish
diff, and the check row holds at FAIL until they do. The confirm states every
consequence first, including that this server, and anyone who breaks into it,
can send as the domain again.

**The old on-disk signing key is a checked state, not a remembered command.**
Send protection means only the domain's sealed key should be able to sign as it —
but an ordinary opendkim key at `/etc/opendkim/keys/<domain>/mail.txt` can still
sign for that domain with no vault and no unlock involved. Nothing destroys it on
its own. So for a domain with send protection on, `domain.local_signing_key`
(RECOMMENDED/WARN) reports that the key is still there and says what it means, and
keeps reporting it until it is gone.

Beside the row is a **Destroy the old signing key** action, gated on send
protection being on **and** every required row of the protected shape passing. It
runs a fixed-verb root helper — `sudo -n /usr/local/sbin/joinery-dkim-remove
<domain>`, installed by `provision_relay_main.sh` with its own sudoers line, the
domain validated against the registered set on both sides, and a `DKIM_REMOVED`
marker the caller demands — then confirms the file is actually gone before
reporting success. Where the helper is not installed the row falls back to the
manual `provision_dkim.sh --remove` command. **It never runs automatically**:
deleting key material is irreversible from a browser, so it happens because a
person pressed it.

**Proof of presence sits on enforcement, not on key creation.** Sealing needs
only the owner's vault public key, and a key that exists publishes nothing and
changes no mail, so `generate` and `rotate` run without an unlock window.
`activate` and `activate_rotation` require one: those decide what the rest of
the world will accept as this domain.

**A Fortress raise requires the acting user's own second factor.** Sealing the
key makes them `ied_owner_usr_user_id`, and
`SessionControl::must_enroll_2fa_for_fortress()` holds any owner of a Fortress
domain on `/profile/security` until they have a factor independent of any single
passkey. The ceremony carries that as a required row (`second_factor_self`,
Fortress-only) fed by `mailbox_protection_facts($domain, $acting_user_id)`, so
the raise is refused with an enrollment link rather than completing and
stranding the operator. Callers that omit `$acting_user_id` omit the fact, and
the row is skipped rather than failed.

The **Setup tab is that ceremony's parent surface**. Its *Still to set up* box
lists what a Private or Fortress domain cannot be complete without — the vault,
at Fortress the relay, and once those are in place the *Finish Fortress* step —
and the ceremony page's breadcrumb and footer link return there. The box carries
outstanding work only and does not render at all when there is none. Saving a domain at Fortress lands on
Setup focused on that domain, not on the ceremony. The ceremony keeps its own
page rather than becoming a Setup card because it holds destructive actions
(rotate, disable, re-generate) that do not belong on a diagnostics surface.

Setup focuses either a mailbox (`?alias_id=`) or, for a domain that has no
mailbox yet, the domain itself (`?domain_id=`) — a domain is registered before
its first address, and all of this setup is domain-level, so the domain state
renders the guided steps and the DNS publish box and skips the per-address
checks. The picker lists a domain only while it has no enabled alias; once one
exists the mailbox entry reaches the same guidance.

**Rotation is staged.** On an enforced domain, *Rotate key* seals a fresh key
under the next selector (`mailk{n}`) into the pending columns
(`ied_dkim_pending_*`) while the live key keeps signing; *Verify & cut over*
swaps pending → live only after the pending selector's published DNS record
matches, and *Cancel rotation* abandons the staged key. The live key is never
overwritten or destroyed until its replacement is proven in DNS. A vault key
rotation re-seals the DKIM keys — live and pending — alongside the message DEKs
(the plugin's `onReseal` callback), for every protected-domain owner regardless
of mailbox grants, on the same fail-loud contract.

**Automated mail** (lists, receipts, notifications) that must run around the clock
lives on a dedicated **non-protected** sending subdomain (e.g. `mail.<domain>`),
signed ambiently by `provision_dkim.sh` as usual. Under the bare domain's
`adkim=s` that subdomain's key can never sign as the bare domain, so a locked box
can send as `list@mail.<domain>` but never as `you@<domain>`.

## Hardened ingest relay

A deployment runs one of three receive topologies:

- **Colocated** (the default, the cost floor): the MTA stack runs on the Joinery
  box itself, exactly as `install_email.sh` builds it. Zero extra infrastructure.
- **Relay-fronted, self-hosted**: a minimal, hardened, disposable VPS at
  the public MX fronts every hosted domain. It buys a hidden origin, edge-sealed
  ingest, and a shrunken main box.
- **Relay-fronted, hosted fleet slot**: the same relay stack, run by the
  platform operator as a shared fleet. The deployment enrolls for a slot,
  points its domains' MX at a per-tenant hostname, and gets the same
  edge-sealed ingest and hidden origin with zero extra infrastructure. See
  [Hosted relay fleet](#hosted-relay-fleet).

The relay runs Postfix + verify milters + a small Go sealing binary + WireGuard,
and nothing else — no PHP, no database, no web, no application. It accepts mail,
verifies it, **seals it to the recipient's public key at the moment of
acceptance**, and spools ciphertext. Each tenant's Joinery box dials out over
WireGuard and pulls its own sealed blobs. Its own IP appears in no mail DNS.

**It also serves Joinery Direct** for its tenants, from the same binary in a
third mode. At Fortress the relay has to: an SRV record pointing at the origin
box would advertise the address the relay exists to conceal. It terminates the
public endpoint on 443 (with an ACME certificate obtained in-process — still no
web server), writes verified deliveries into the same spool as `.direct`
entries, and offers a tunnel-only egress listener so a tenant's box-signed
request leaves from the relay's address. The relay authenticates and never
signs; the box authorizes at unlock. See
[Joinery Direct](../../../docs/joinery_direct.md).

**The relay stack is tenancy-native, and a self-hosted relay is a fleet of
one.** Every tenant on a relay has its own spool subdirectory (setgid,
tenant-group readable — the cross-tenant isolation boundary), its own
restricted SSH pull account locked to a forced-command shell, its own WireGuard
peer at an allocated tunnel address, and its own root-owned domain allowlist.
A self-hosted relay is simply a relay on which the add-tenant operation has run
once (slug `main`, allowlist `*`); a fleet shard is one on which it has run per
enrolled tenant. One codebase, one code path — N=1 is the degenerate case.

Once a relay fronts a deployment it is the MX for **all** that deployment's
hosted domains (a mixed MX would leak the origin). The security level controls
where mail is *sealed*, never where it is *routed*.

### The sealing binary

`provisioning/relay-sealer/` is a single static Go binary built and installed by
`provision_relay.sh` to `/opt/joinery-relay/relay-sealer`. It replaces the PHP
pipe on the MX path as the Postfix `joinery` transport (raw on stdin,
`${recipient} ${sender}` as argv). The same binary is the relay's **map merge
unit** (`relay-sealer merge-maps` — see [Map sync](#map-sync-fragment-push--shard-side-merge)).
For each accepted message it:

- Looks up the recipient's public key + routing in the merged `routing.json` (no
  database). Every entry names its owning tenant; the tenant's block carries the
  spool directory, SRS secret, forward From identity, transport key, and the
  shard-policy limits (per-tenant forward rate limit and spool quota — over
  quota temp-fails, so senders queue instead of one tenant filling the disk).
- Seals the **entire raw message** with `crypto_box_seal` (libsodium wire format,
  `SealedBox::openDek`-compatible) to that public key — Fortress recipients to the
  owner's vault key, Standard/Private to the ambient transport key Joinery holds.
- Writes `<spoolid>.seal` (ciphertext) + `<spoolid>.meta` (cleartext operational
  metadata only — recipient, Message-ID, thread inputs, size, the milter-stamped
  Authentication-Results; never subject or body) via write-tempfile → fsync →
  atomic rename, returning the Postfix exit code only after the fsync. Plaintext
  is never written to the relay's disk.
- Executes forward-mode aliases relay-side, applying the identical header
  treatment `InboundEmailRouter::buildForwardMessage` applies (a byte-for-byte Go
  port with a parity test): rewrite From to the site's verified address so the
  original sender domain's DMARC never judges us, preserve the original sender as
  Reply-To, stamp the `X-Forwarded-*` headers, and SRS-rewrite the envelope sender
  (byte-compatible with `SRSRewriter`, so bounces decode on the main box).
- Stores SRS bounces: a delivery-failure notice returning to `SRS0=…@forwardingdomain`
  is accepted (a Postfix regexp map), transport-sealed, and spooled; the pull
  consumer routes it through the same `handleSRSBounce` path colocated ingest uses,
  so the original sender gets the NDR (never a stray stored message).

### Map sync: fragment push + shard-side merge

The relay holds no database, so `RelayMapExporter` compiles this tenant's
routing — its domains, recipients, forwarding domains, and per-tenant identity
(SRS secret, forward From identity, transport key) — into **one JSON fragment**,
and `RelayMapSync` rsyncs it into the tenant's own drop area over the restricted
tenant account (never root, never `/etc/postfix`), then triggers the relay's
merge with the tenant shell's `joinery-merge` verb and reads the validation
verdict in-band. IMAP-source domains are excluded — their mail arrives by IMAP
poll, not MX, and listing them would make the relay wrongly authoritative for
e.g. `gmail.com`, looping forwards to addresses there back into the sealer
instead of out over SMTP.

The relay-side merge (`relay-sealer merge-maps`, root, triggered — never a
resident daemon) is **where the domain-claim boundary is mechanically
enforced**: every domain a fragment names must sit inside that tenant's
root-owned allowlist (`/opt/joinery-relay/tenants/<slug>/allowed_domains` — `*`
on a self-hosted fleet of one, the explicit TXT-verified list on a fleet
shard), and must not be claimed by another tenant on the relay. A fragment
violating either is rejected whole — nothing from it is installed, and the
tenant's **last accepted** fragment keeps serving so a bad push never erases
working routing. From the validated fragments the merge derives all the Postfix
artifacts — `relay_domains`, `check_recipient_access` (preserving
`reject_unmatched`: listed aliases match before a domain REJECT, so no
backscatter), `transport_maps`, the SRS-bounce accept `regexp` map — plus the
merged `routing.json`, installs atomically, and runs `postmap` + `postfix
reload` only when the output changed. Shard-policy limits
(`tenants/<slug>/limits.json`) are stamped into the merged tenant block here,
so a fragment can never raise its own caps.

The tenant-side push is content-hashed and skipped when unchanged, and the
verdict echoes the pushed fragment's version so the sync knows the merge saw
this push. Every routing change (alias/domain/grant write, via a data-layer
hook) triggers an immediate best-effort push, and the `SyncRelayMap` scheduled
task reconciles every cron pass as the backstop — so a newly created alias
reaches the relay before it can bounce.

The Setup tab's relay card grades map freshness accordingly
(`InboundEmailHealth::checkRelayMapFresh`): a map that differs from the last
push while the reconcile task is alive and succeeding (a run within the last
15 minutes) is **pending** — an amber wait ("address changes are queued"),
because every domain/alias/system-sender change passes through that window
for up to one tick. It is a red failure only when the task will not converge
it: never ran, errored on its last run, or missed the window. Pending rides
`ProvisioningCheckPending`, a subclass of `ProvisioningCheckFailed`, so every
surface that cannot render the middle grade still treats it as unmet.

### Spool pull + deferred ingest

The pull (`RelaySpoolConsumer`, phase 2 of the `MailboxRelayReconcile`
scheduled task) dials out over WireGuard as the deployment's restricted tenant
account, `rsync`s new entries from **its own spool subdirectory** copy-only,
stores each durably keyed on the spool id (an idempotent re-pull is a no-op),
and acks the entries it stored with the tenant shell's `joinery-ack` verb (ids
only — the shell resolves them inside the tenant's spool and rejects anything
with a path separator) — the delete-after-store is the ack. Standard/Private
blobs are opened at pull with the ambient transport key and run through today's
ingest. Fortress blobs cannot be opened while the owner is logged out, so they
land as **pending-parse** rows: operational metadata + the sealed blob, so
threading and unread counts work while subject/sender/body/attachments do not
exist yet. At the next unlock, `DeferredIngest` unseals each blob, runs the
full pipeline (parse, filters, attachment split, seal fields under a fresh
per-message DEK), and clears the pending state. For a single reader this is
invisible — the rules have always run by the time any mailbox view renders.

The reader's Refresh button runs the same pull on demand (`mailbox/check_mail`
API action) before re-reading the list, so a user waiting on a message waits on
one click, not on the cron interval. A per-relay advisory try-lock inside
`RelaySpoolConsumer::pull()` keeps a click and the scheduled pass from racing
(the loser reports `skipped`), and a short cooldown on the relay's last-pull
time absorbs rapid clicking. On a direct-MX deployment the relay lane is a fast
no-op — mail is pushed at SMTP time and there is nothing to pull.

`check_mail` covers the other pull lane too: it fetches the enabled IMAP feeds
bound to the viewer's accessible mailboxes (`ImapFetch::run` — the same full
cycle the scheduled poller runs), bypassing each account's poll interval.
Most-starved accounts go first, a few per click, and an atomic 15-second claim
on each account's last-poll time collapses rapid clicks to one fetch; the
per-account advisory fetch lock inside `ImapIngestor::poll()` keeps a click
and the scheduled poller off the same account at once.

**A click is answered in bounded time.** A browser, and the proxy in front of
it, waits only so long for a request, so the IMAP lane hands every account one
absolute deadline, `ImapFetch::INTERACTIVE_BUDGET_SECONDS` after the click.
A cycle stops between folders and between messages once it passes — nothing
already started is cut — with the folder cursor held below the first message
not walked, so nothing is skipped, only left. An account the deadline stopped,
or one the lane never reached, is left **due** (`ImapFetch::leaveDue` clears its
last-poll stamp) so the scheduled poller finishes it at its next tick rather
than a full interval later. The response reports each lane's `took_ms` and the
IMAP lane's `deferred` count. The admin **Fetch now** action runs under the
same budget and says when it stopped early.

Degradation is safe: relay down → senders' MTAs retry for days; tunnel down → the
relay keeps spooling sealed blobs until the next pull. Neither loses mail.

### Outbound sending

The relay is **inbound-only by default**: it accepts, verifies, seals, spools,
and forwards inbound mail, and carries no compose sends. Compose sends leave
through the deployment's configured outbound provider over an HTTP-API raw-message
path. SMTP submission would stamp the main box IP into the sent message's first
`Received:` header; an API submission's `Received:` chain begins inside the
provider, so the origin stays hidden. `OutboundTransport` builds a fully formed,
in-app-signed message (`RawRelayComposeTransport`) and hands it to the active
provider's `relayRawMessage()` — the `ApiSubmissionRelay` capability (Mailgun's
`messages.mime`, SES's `Content.Raw`). The provider must be API-class; an
SMTP-only provider is refused with a message pointing to an API provider or the
smarthost. DKIM signing stays in-app: a protected domain signs with its
vault-sealed key, a standard domain with the filesystem key opendkim would have
used, and the envelope (MAIL FROM) routes through the forwarding subdomain so the
protected domain's own `v=spf1 -all` never touches the envelope. Generated
headers (`Message-ID`, etc.) derive from the mail hostname — which points at the
relay — never `gethostname()` or the box IP.

Outbound confidentiality is bounded by the recipient's provider anyway: every
message to an external address is delivered in plaintext to the recipient's
mailbox provider, so a provider carrying it in transit adds a second reader to a
set that already has one. Mail whose transit privacy genuinely matters is the
encrypted-interop path, which is ciphertext before it leaves the box and stays
ciphertext through any transport. The asymmetry lives on **inbound**, which lands
in the operator's own archive under the user's keys — and inbound keeps the relay.

**Sending through the relay** is the opt-in alternative (`mailbox_relay_outbound_mode
= smarthost`, offered on the Settings tab's "Sent mail leaves through" select as
*Through the relay*). Compose sends then leave through the relay over the tunnel, so
no third party carries outbound plaintext — in exchange the deployment owns the relay
IP's sending reputation (warmup, blocklist monitoring, PTR hygiene). The stored value
and the internal identifiers keep Postfix's word *smarthost*; no reader is shown it,
because it names the plumbing rather than what happens to their mail.
`OutboundTransport` routes hosted-alias sends through
`SmtpConfig::fromRelaySmarthost()`; DKIM signing stays in-app, the relay only
transports. The hop is deliberately plaintext
SMTP — the WireGuard tunnel already encrypts it — so `fromRelaySmarthost()` sets
`encryption = 'none'` and `SmtpMailer` disables PHPMailer's opportunistic
auto-STARTTLS for an explicit `'none'` (otherwise it would upgrade into the
relay's self-signed cert and fail the handshake). `provision_relay.sh` opens the
tunnel submission listener (`permit_mynetworks` on the WireGuard subnet) only in
this mode — pass `smarthost` as its second argument. The listener state is baked
at provision time, so changing the outbound mode takes effect on the relay itself
at its next Rebuild: switching to the relay leaves compose sends refused (and the
tunnel check failing) until the Rebuild opens the listener, and switching back to a
provider leaves the listener open until the next Rebuild closes it. The mode
select's save message says so.

The relay's outbound health checks match the chosen path, never showing an N/A row.
Provider mode verifies the active provider is API-class and offers an out-and-back
origin-leak probe: `sendOriginProbe()` sends a marked message from the first
enabled store-mode alias on a Standard or Private domain to itself — a listed
alias because the relay's SMTP-time recipient validation rejects anything else,
store-mode so the delivered copy lands in `iem_inbound_email_messages`, and
non-Fortress so that copy is server-readable — out via the provider, back via the
relay MX, and `checkOutboundOriginLeak` scans the delivered headers for the box
IP or hostname on token boundaries. Smarthost mode verifies compose submission
with a live SMTP handshake over the tunnel (EHLO, `MAIL FROM:<>`, RCPT to a
reserved `.invalid` recipient, QUIT — nothing is ever sent): port 25 answers in
both modes, so only the relay *accepting* an external recipient proves the
submission listener is open.

### Provisioning

`provisioning/provision_relay.sh` is the self-contained installer, in two
layers:

- **Shard skeleton** (`provision_relay.sh <mail-hostname> [smarthost]`):
  idempotent, zero prompts, runnable as root on a fresh minimal Debian VPS. It
  builds the sealer/merge binary, installs the tenant shell
  (`/opt/joinery-relay/bin/joinery-tenant-shell`) and the sudoers rule letting
  tenant accounts trigger the map merge, wires Postfix + opendkim(verify,
  `RemoveARFrom` stripping forged Authentication-Results) + opendmarc(stamp) +
  rspamd + WireGuard + a default-deny firewall, and prints the relay public IP,
  WireGuard public key, and tunnel endpoint. rspamd is **stateless**: static
  rules only, Bayes classifier and autolearn off, no redis — learned state on a
  shared relay would be one model trained on every tenant's mail (a
  cross-tenant privacy leak and a poisoning vector), and the relay's header was
  never the verdict anyway — each tenant's own rspamd re-scores at ingest. The
  script self-installs to `/opt/joinery-relay/provision_relay.sh` so tenant
  lifecycle operations run without re-shipping the bundle.
- **Tenant lifecycle** (`add-tenant <slug> --pull-pubkey … [--wg-pubkey …]
  [--tunnel-ip …] [--domains a.com,b.com | '*' | '-'] [--forward-limit N]
  [--spool-max-mib N] [--spool-max-entries N]`, plus `remove-tenant` and
  `set-domains`): each run creates one tenant — spool subdirectory
  (`/var/spool/joinery-relay/<slug>`, mode 2770, owner the sealer, group the
  tenant), SSH account `jt-<slug>` whose authorized key is locked to the tenant
  shell (rsync pull of its own spool, rsync push into its own fragment drop,
  `joinery-ack`, `joinery-merge`, `joinery-ping` — nothing else; `joinery-ping`
  answers the shard's health as JSON, see **Is the relay still scanning?**),
  WireGuard
  peer pinned to its allocated tunnel address, and the root-owned registry
  entry (allowlist + limits). `remove-tenant` refuses while the tenant's spool
  holds undrained sealed mail unless forced. The smarthost is single-tenant
  only — `add-tenant` refuses a second tenant on a smarthost relay, because
  `mynetworks` trusts the whole tunnel subnet in that mode.

The main box's half of the tunnel is `provisioning/provision_relay_main.sh`
(root, once per deployment): it generates the box's WireGuard keypair (private
key root-only in `/etc/wireguard`), writes the `jyrelay0` dial-out interface,
installs the `joinery-relay-peer` root helper plus the sudoers rule that lets
the provision job peer a freshly built relay automatically, generates the
**relay pull key** (`{site root}/config/relay_pull_key`, `RelaySsh::pullKeyPath()`),
and registers the public key in settings (`mailbox_relay_wg_public_key`). The
Relay section's provision form stays gated — showing the exact command to run —
until that key exists.

The pull key is a dedicated SSH identity owned by the web user, because every
steady-state relay connection — the spool pull and map-push cron tasks and the
Relay section's health battery — runs as the web user, and ssh only accepts a key
file its caller owns with mode 600. The provision job installs the pull key's
public half as the tenant account's authorized key (forced command: the tenant
shell) and points the relay row's `mrl_ssh_key_path` at it, so the managed
node's admin key (which drives provisioning through the Go agent) never has to
be readable by the web user, and the steady-state credential grants exactly the
tenant surface — this tenant's spool and fragment drop, nothing else.
`provision_relay_main.sh` also installs a second narrow root helper
(`joinery-relay-addr`) that applies a fleet-allocated tunnel address to the
`jyrelay0` interface (a hosted slot's allocation is not always the `10.99.0.2`
self-hosted default).

The **Setup tab's Relay section** (rendered whenever the receive mode is relay
or a relay row exists) is the dashboard: it lists each relay with the four
provisioning checks (tunnel, spool draining, map fresh, origin hidden) plus the
relay's last spam-scanning answer, and its guided controls provision, rebuild,
enable/disable, delete, and **Check spam scanning now**.

#### Is the relay still scanning?

Everything else the relay does leaves evidence in the tenant's database.
opendkim and opendmarc stamp a verdict onto every message, so a broken verifier
shows up as unverified mail. rspamd does not: it stamps a header only when it
**flags** something, and `milter_default_action = accept` means a dead scanner
lets mail through rather than deferring it. A relay that scanned and found
nothing and a relay whose scanner is dead therefore send identical evidence —
none — and no amount of reading stored mail can separate them. Warning on "no
message carried a content verdict in N days" reports every quiet mailbox behind
a healthy relay as broken.

So the relay is asked. `joinery-ping` answers one JSON object:

```json
{"status":"ok",
 "services":{"rspamd":"active","opendkim":"active","opendmarc":"active"},
 "milters":{"opendkim":true,"opendmarc":true,"rspamd":true},
 "contract":true,"provisioned":"2.2","slug":"example"}
```

`services` is `systemctl is-active`; `milters` is read from `postconf -h
smtpd_milters`; `contract` is the header contract — provisioning writes
`local.d/milter_headers.conf` and `local.d/actions.conf` itself and records
their digest in `/opt/joinery-relay/contract.sha256`, so ping re-hashes and
returns a boolean rather than PHP modelling rspamd's config format. A relay
whose contract drifted scans perfectly and stamps nothing
`InboundEmailRouter::readSpamHeader()` can parse, which is why service liveness
alone is not the question. A relay built before this answers the plain text
`PONG <slug>`, and that is the capability probe.

**Shard-level service liveness only.** A shared fleet shard serves several
deployments, so the answer never carries queue depth, message counts, spool
sizes, or anything per-tenant — that would leak one tenant's mail volume to
another. Service state is not tenant data.

`MailboxRelay::readHealth()` turns one answer into a state (`ok`,
`not_delivering`, `legacy`, `unreadable`, `unreachable`) plus a reason (`dead`,
`unwired`, `drift`). `pollHealth()` runs the ping and caches the answer on the
relay row (`mrl_last_health_json` / `mrl_last_health_time`); an unreachable
result is deliberately not cached, because overwriting the last real answer
destroys the only information available during an outage.

`MailboxRelayReconcile` polls once per pass — the SSH session is already open —
and `InboundEmailSetupCheck::checkRelayScannerHealth()` reads the cached answer,
so no page render pays for a round trip. **Check spam scanning now** in the
Relay section forces a fresh one for an operator mid-incident. Severity depends
on whether this server is covering:

| Relay | Local scan (`scanAtIngest` + `scannerAvailable`) | Result |
|---|---|---|
| delivering usable verdicts | either | PASS |
| not delivering — dead, unwired, or drifted | active | WARN — the relay is not delivering verdicts; this server is covering |
| not delivering — dead, unwired, or drifted | not available | FAIL — nothing is scanning content anywhere |
| answers `PONG` | either | INFO — the relay predates the check |

A dead scanner and a drifted contract share a severity on purpose: different
faults, one finding (the verdict is not reaching the tenant) and one remedy
(rebuild the relay). Which it was survives in the detail text. A relay answering
`PONG` also reports its version as unknown; both are the one finding — this relay
predates the current provisioner — so the scanner row names the same **Upgrade
relay** control rather than offering a parallel fix.

**Relay findings reach only mailboxes whose domain needs a relay.** A WARN or FAIL
scanner is promoted from Advanced to a Receiving card, and the relay's two state
cards render, only for a mailbox on a **Fortress** domain — the level the relay is
load-bearing for. A deployment may run a relay at any level, and the Relay section
stays available to set one up, but on a Standard or Private domain the relay does
nothing for that mailbox, so its health is not that mailbox's verdict. Promoting it
unconditionally turned one deployment-wide fault into an `attention` banner on every
mailbox on the deployment.

The reconcile pass also raises the change on the signal bus —
`mailbox.relay_scanner_down` and `mailbox.relay_scanner_recovered`, both
topic-subscribable — **on transition only**, comparing against the cached state,
so a relay that stays broken is announced once rather than every pass. A finding
that lives only on the Setup tab is found by opening the Setup tab, which nobody
does until mail already looks wrong.

**Provisioning paths**, primary first:

- **The customer's own cloud account** (specs — mailbox_relay_cloud_provisioning):
  the section's form takes a mail hostname, region, and instance type;
  submitting shows the **just-in-time credential step**, which has two
  branches: with a `linode` OAuth client configured (Admin > OAuth
  Providers), a single **Approve at Linode** button (consent lands via
  `RelayCloudConsumer`, purpose `relay_cloud`); otherwise — the universal
  floor — a short-lived Linode API token the customer mints for this one act
  (scope Linodes read/write only, numbered walkthrough with a direct link to
  the provider's token page), verified live with a cheap read call. Either
  way the credential is sealed onto the run and nothing is configured
  beforehand. The platform never deletes a customer's running server —
  removing a cloud relay's instance happens at the provider, by the customer;
  a relay's Delete here removes only the deployment's row (and says so). The `AdvanceRelayCloudProvisions` scheduled task drives the
  `RelayCloudProvision` state machine: create the instance on the customer's
  account (`includes/cloud_compute/` — `LinodeComputeDriver`, per-run SSH key
  injected), wait for boot (the cheap transitions — create, boot poll — also advance on
  every Setup page load, so a watching admin is never waiting on cron), run
  the same tarball → `provision_relay.sh` →
  add-tenant `main` → markers sequence over root SSH
  (`RelayCloudProvisioner`), register the `MailboxRelay` row **born enabled**
  (pulling and address-list pushes start immediately, so the relay is ready
  before any MX points at it; Disable is an emergency stop, and doctrine
  effects key off the recorded cutover verdict, not this flag — carrying
  `mrl_cloud_provider`/`mrl_cloud_instance_id`), peer the main
  box's WireGuard, and attempt reverse DNS through the provider API (refused
  until the hostname's A record resolves; the PTR check carries it from
  there). **Grant-per-act custody**: the token and per-run SSH key live
  SecretBox-sealed on the run row and are erased at every terminal state; a
  failed run destroys the instance it created within the same grant.
  Requires only the main box's relay identity (`provision_relay_main.sh`).
- **A Server Manager node** (operator deployments): picks a managed node and
  fires a `provision_relay` job (`JobCommandBuilder::build_provision_relay`);
  the job result processor registers the `MailboxRelay` row, born enabled the
  same way. "Rebuild" re-runs provisioning on the same node.
- **By hand** — run `provision_relay.sh` as root on any fresh VPS: the
  standalone floor.

#### Keeping a relay's code current

A relay runs code that ships with the platform — the tenant shell, the sealing
binary, the rspamd configuration — so it goes out of date when the platform moves
on. The Relay section reads the version out of the relay's own health answer
(`joinery-ping` reports `provisioned`), compares it against `RELAY_VERSION` in
`provision_relay.sh` with `version_compare()`, and offers an upgrade when the
relay is **behind or silent**. A relay that answers the legacy plain-text `PONG`
predates the version marker and reads as unknown, which offers the upgrade. A
relay **newer** than the deployment offers nothing: the deployment is the thing
to update.

**Nobody holds a shell credential to a cloud relay, by design.** Provisioning
sets a random root password that is never stored, injects a per-run key that
`eraseCredentials()` wipes at the run's terminal state, and
`provision_relay.sh` leaves sshd key-only (`PasswordAuthentication no`,
`PermitRootLogin prohibit-password`). What survives is the tenant pull key, locked
to the `joinery-tenant-shell` forced command. So a relay cannot be logged in to
and patched — its contents are replaced instead.

The route depends on what the platform can reach:

| Relay origin | Upgrade route |
|---|---|
| Cloud (the customer's own account) | An **upgrade run** — the same grant-per-act ceremony as provisioning, with two states in front: **draining**, then **rebuilding** |
| Server Manager managed node | **Rebuild** — a `rebuild_relay` job over root SSH. Rendered only when `mrl_mgn_managed_node_id` resolves to a live node |
| Run by hand | The Relay section states the version and says to re-run `provision_relay.sh`. That customer built the box and is the one who can act on it |
| Hosted fleet slot | Operator-managed. The slot says so and offers no control |

**An upgrade run drains before it wipes.** The rebuild destroys every byte on the
machine, so `handleDraining()` pulls the spool until it is empty, and refuses to
advance if anything is **held** (a blob whose owner is not yet resolvable,
deliberately left un-acked) or if successive passes stop making progress. An
upgrade is elective, and losing mail to it is not a trade the platform makes on
the customer's behalf.

**A relay somebody else lives on is never wiped.** A deployment can see only its
own tenancy, so `joinery-ping` answers `sole` — is the asking tenant the only one
here? A `false` renders no control, refuses a hand-posted upgrade, and refuses
again at drain time (re-asked live, because a tenant can have been added since the
relay last spoke). Anything short of a confirmed count of one answers `false`,
including an unreadable tenant registry. A relay too old to answer reports
`null`, which is not consent: the upgrade proceeds only with an explicit
acknowledgement that the relay serves this site alone. Without the guard, one
tenant clicking Upgrade would destroy every other tenant's mail, accounts,
allowlists and WireGuard peers — the drain empties only the asking tenant's spool.

**The wipe is a rebuild in place, not a recreate.** `rebuildInstance()` replaces
every disk while keeping the instance and its public IPv4 — the address an MX
record points at. A destroy-and-create would turn a few minutes of downtime into a
DNS change plus propagation. Port 25 is gone for the whole run; SMTP senders queue
and retry for days, so the visible effect is mail arriving late.

A failed upgrade **destroys nothing**. `destroyInstanceQuietly()` refuses on an
upgrade run: the instance is the customer's working relay, not the run's to throw
away. That refusal lives at the single choke point every cleanup path funnels
through.

**Postfix's own queue is lost with the machine.** It holds mail Postfix accepted
but has not handed to the sealer — normally empty, and unreachable by the drain
because the tenant credential cannot read the queue. So `joinery-ping` reports its
depth and the upgrade control states it, blocking nothing. That count is emitted
**only on a relay with exactly one tenant**: on a shared shard the queue is shared,
and its depth would read out every other tenant's mail volume. Absent is not zero
— a relay that could not measure its queue reports nothing rather than a reassuring
`0`.

**The operator's half** is the fleet console. A shard is a managed node, so the
operator holds root SSH and rebuilds through the ordinary job path. There is no
`joinery-ping` for them — the operator is not a tenant of their own shards, which
are provisioned `skeleton_only` with no tenant account — so the version arrives
from the job's `RELAY_VERSION=` marker into `mfs_provisioned_version`. A shard
whose job emitted no marker reads as unknown, never as up to date.

### The shrunken main box

With every domain fronted, the relay is the sole mail listener: the main box's
Postfix/opendkim/opendmarc are decommissioned and port 25 closed — the box holding
the data no longer exposes a mail listener. **rspamd stays where it was
running**: the scanner ships with the mail stack, so it is on every box that
ever ran the mail installer, and the decommission leaves it alone — a learning
deployment simply switches it from milter mode to scoring pulled mail over
HTTP at ingest, carrying its Bayes corpus across the move with no reinstall.
The relay scores regardless
(`provision_relay.sh` installs rspamd unconditionally, stateless) and stamps
its X-Spam header inside the sealed raw. The setup/health checks retarget to the relay
(`checkRelayTunnel`, `checkRelaySpoolDraining`, `checkRelayMapFresh`) and add a
deployment-wide origin-hidden check (`checkOriginHidden`) that fails if the main
box IP appears in any hosted domain's mail DNS.

**Decommission is a guarded platform action, never manual host surgery**
(`includes/listener_admin.php`). The Setup tab's Relay section shows the offer
only when it is actually possible: once every guardrail passes, an amber
**Uninstall local mail** block appears (one sentence — the relay makes the
local mail software unnecessary and a security risk — plus the button); while
any guardrail fails, nothing renders at all (the Setup rows already walk the
missing pieces), and the server-side re-check on POST remains the
enforcement. The button runs `/usr/local/sbin/joinery-mail-listener off` — a
narrow root helper installed by `provision_relay_main.sh` alongside the
peer/addr helpers — which stops and disables Postfix/opendkim/opendmarc and
closes 25/tcp at the firewall. After an uninstall the block goes quiet while a
relay is still receiving mail — reinstalling would reopen attack surface no
mail would use — and returns only once no enabled relay remains, as an amber
warning that this server has no way left to receive mail plus **Reinstall
local mail** (`on`, the always-safe inverse; it has no guardrails of its own).
The one exception is a setting/reality mismatch: if port 25 answers while the
record says uninstalled, the red block appears regardless of the relay and
re-offers the uninstall. The guardrails: an enabled relay exists, DNS has fully cut over
(`InboundEmailSetupCheck::relayCutoverState()`, the same evaluation behind the
cutover-completion row), the spool pull is healthy
(`checkRelaySpoolDraining`), and outbound does not lean on the local Postfix
(no provider, or SMTP aimed at localhost). The outcome is **recorded, not
inferred**: the `mailbox_local_listener` setting (`active` |
`decommissioned`) is written only on a successful helper run, and the
`host.port25` / `host.postfix` / `host.opendkim` setup rows and
`InboundEmailHealth::checkInboundMailServer()` compare it with reality — under
`decommissioned`, an answering port 25 is the failure, and silence is the
healthy state. The helper runs on the deployment's own box via a sudoers rule,
so standalone tenants get the same button with no server_manager dependency.

### Hosted relay fleet

The platform operator runs a shared fleet of hardened relays as a service
(`specs/mailbox_relay_shared_fleet.md`). A **shard** is exactly the self-hosted
relay stack fronting many tenants; a **slot** is one tenant deployment's place
on a shard. The trust statement is published plainly: the fleet operator stands
at the plaintext-arrival moment for inbound transit mail and could read it
while actively compromised — the same position any hosted MX occupies. It can
never reach the tenant's archive, keys, drive, passwords, or sending identity
(DKIM keys never leave the tenant's app). The exit ramp: point your MX at your
own relay whenever you want — same stack, nothing else changes
(`fleet_release`). Release revokes the slot's domain claims immediately, so
the domains' next home (a new slot here or another fleet) can claim them
before the old slot finishes evicting.

**Tenant side** (any deployment): every tenant-facing hosted-relay surface —
the Setup Relay section's Hosted relay block, the Settings connection box, and
the live fleet-status fetch — is gated behind
`mailbox_hosted_relay_offered()` (`includes/receive_mode.php`), which is off:
the hosted offering is not customer-facing yet, and the choice card/Relay
section describe only the run-your-own path. The fleet API actions and the
operator console are unaffected. When offered, the Settings tab's *Hosted
relay connection* box takes the operator's service URL + the customer
account's API key
(`mailbox_fleet_service_url` / `mailbox_fleet_api_public_key` /
`mailbox_fleet_api_secret_key`); enrollment itself is a button in the Setup
tab's Relay section. `FleetClient` calls the operator's
`/api/v1/action/mailbox/fleet_*` actions: `fleet_enroll` sends this box's
WireGuard + pull public keys and returns the slot coordinates (per-tenant MX
hostname, shard WireGuard endpoint + key, allocated tunnel address, pull
account, spool subdirectory), which fold into the deployment's `MailboxRelay`
row (`mrl_is_hosted`) — after which every relay consumer runs exactly as
against a self-hosted relay. Hosted vs self-hosted differs only in where the
coordinates came from. Each domain must pass a **DNS TXT ownership proof**
(`_joinery-fleet-challenge.<domain>`) before the fleet accepts a single
message for it, with fleet-wide uniqueness; verification writes the domain
into the tenant's shard-side allowlist, which the map merge enforces on every
subsequent sync. The proof is fully automated on the tenant side: challenges
are filed at enrollment and at domain registration
(`FleetClient::fileDomainClaims()`), the Setup tab's `domain.ownership` row
shows the copy-ready TXT record and re-verifies on every check pass, and the
Relay section shows a read-only **Ownership proofs** state table. The
`fleet_claim_domain` / `fleet_verify_domain` API actions are what that
automation calls — they are not user-facing steps.

**Operator side** (the deployment with `mailbox_fleet_service_enabled` +
`mailbox_fleet_mx_zone` set): the fleet service is the brain — `FleetService`
assigns shards (least-loaded active shard with capacity), allocates tunnel
addresses, issues and verifies domain claims, and checks entitlement (the
`mailbox_fleet_slot` tier feature, re-checked periodically with a
`mailbox_fleet_grace_days` grace window before suspension empties the tenant's
shard allowlist). Every decision is effected by dispatching a `server_manager`
job (`relay_add_tenant` / `relay_set_domains` / `relay_remove_tenant`) from the
`FleetReconcile` scheduled task — server_manager is the hands and never knows
what a tenant or a domain claim is. The operator's control panel is the
**relay fleet console** (`/plugins/mailbox/admin/admin_mailbox_fleet`, reached
from the Server Manager dashboard — operator infrastructure, so it never
appears in the tenant mailbox tabs): the service switch + MX zone, shard
registration (skeleton-only provisioning: the operator's box is not a tenant
of its own shards), and the DNS-to-publish table. Each tenant's MX hostname
(`<slug>.<mailbox_fleet_mx_zone>`, slug format `t<id>` — deliberately
anonymous so DNS names no tenant) is an operator-controlled A record, so
re-sharding a tenant or replacing a burned shard is an A-record change —
tenants never touch DNS after setup. The operator's half of that guidance is
the fleet console's **DNS to publish** table: every record the fleet zone needs —
each shard's A record and PTR expectation, and one A record per live slot MX
hostname — with a live resolution verdict and copy fields. (PTR records are
set where the shard's IP is hosted, not in the DNS zone.)

**Selling slots — order-time auto-enrollment.** The fleet console's *Fortress
hosting product* box creates the sellable product in one click (store +
server_manager required): a subscription tier whose features grant
`mailbox_fleet_slot` (an existing slot-granting tier is reused) and an
inactive `customer_cloud`-fulfilled product on it — pricing and activating it
are the operator's explicit acts on the product edit page. When a paid order
then provisions the buyer's server, `ProvisionCustomerCloud` hands the
finished site to `FleetProvisionSeeding` (mailbox side) once its agent has
paired: if the fleet service is on, the store is this deployment, and the
buyer's tier carries the slot feature, it mints a machine API key for the
buyer's account (`Fleet enrollment`, read+write; re-minting deactivates the
previous one) and dispatches ONE `fleet_enroll` job on the site's own agent
carrying the three values — the setting names are compiled into
`utils/fleet_enroll.php` on the site, the secret rides the job row redacted
and is blanked once the node answers, and nothing opens a shell. The
owner's Setup tab then lands on one-click Enroll; the DNS TXT ownership
proofs and the MX edit stay manual by nature (the customer proving domain
control at their own DNS provider). Seeding is best-effort: a failure alerts
the ops address and leaves the provision done — the owner can always enter
the credentials manually on the Settings tab.

**Rebuild carries the spool across the wipe.** The scheduled shard rebuild
closes port 25, flushes the Postfix queue for a bounded window, copies the
per-tenant spools and any still-deferred queue files aside, re-runs the full
provisioning, and restores with a validating pass (strict `<id>.seal` /
`<id>.meta` name pattern, owning tenant's directory, correct ownership, no
exec bits) before reopening 25 — so **no accepted message is ever lost in a
rebuild**; mail not yet accepted waits at senders' MTAs. Self-hosted rebuilds
use the same sequence; N=1 is the same job.

## Mailbox Reader

The Mailbox Reader is a two-pane Gmail-style reader over the stored messages:
a left rail (the mailbox switcher and its folders) and a single main pane that
shows either the conversation list or an opened conversation full-width. It is
a vanilla-JS client (`assets/mailbox_reader.js` + `.css`, cache-busted by file
mtime) talking to the scoped AJAX/API actions listed under **API Surface**.

The reader has **two mounts** of one shared UI
(`includes/mailbox_reader_mount.php`):

- **Admin** — the **Mailboxes** tab (`admin_mailbox_reader.php`), staff
  chrome, with attachment downloads at the admin endpoint.
- **Member** — `/profile/mailbox/mailbox`
  (`views/profile/mailbox.php` + `logic/profile_mailbox_logic.php`), theme
  chrome, for any signed-in member; what they see is their granted mailboxes.
  A member with no grants gets a short "no mailboxes are assigned to your
  account" state. Attachment chips point at the member endpoint. The
  plugin's `profileMenu` declares the "Email" entry that puts the page in the
  member menu on every theme and in the apps' navigation.

The mounts differ only in chrome and endpoint URLs (handed to the JS via
`window.MAILBOX_READER`); the endpoints themselves scope every read and write
via `MailboxViewer`.

The **member mount only** also carries an **AI** button beside Actions when
the joinery_ai plugin is active: it mounts that plugin's area AI panel
(`JoineryAiPanel.mount`), a drawer where the signed-in user switches their AI
recipes on or off for the mailbox open in the rail. The reader exposes the
host surface the panel reads — `window.MailboxReader.currentAddress()` plus a
`joineryareacontextchange` event on every rail switch. The admin mount
deliberately does not carry the button: its all-access view spans mailboxes
the viewer holds no grant on, and admins manage recipes on the dashboard. See
`plugins/joinery_ai/docs/overview.md` § The area AI panel.

### Phone layout

Below 768px (the kit's header breakpoint) the reader shows one pane at a
time. The conversation list is the default screen; an opened conversation
replaces it, with its own Back arrow; the rail is a drawer that slides over
the list on request. A **scope bar** above the list header names the mailbox
and folder the list is showing with the mailbox's unread count — it is fed
from the same state as the rail highlight — and tapping it opens the drawer.
Picking a mailbox or folder in the drawer closes it; so does its close button,
a tap on the scrim, or Back.

Back (the browser button, a phone's hardware key, an edge-swipe) undoes the
last in-reader step: it closes the drawer if one is open, otherwise it returns
an open conversation to the list, and only then leaves the page. Each step
pushes a marked history entry (`mbxRail`, `mbxReading`) with no URL change; a
reload lands on the list.

The page title, the page padding and the site footer are absent on a phone:
the reader takes the whole screen. Search, the AI button and the Actions menu
sit as icons at the right of the scope bar (the reader moves the app bar's
action nodes there, and back, as the viewport crosses the breakpoint); the
search icon reveals the search line under the row, and closing it with a term
in the box cancels the search. The scope bar reads as a dropdown (a caret after
the folder name) and carries the protection chip; the member section nav is
behind the header's hamburger. The list toolbar appears only for a selection
(its bulk actions) or an open search — there is no select-all or Refresh on a
phone — and New message floats as a pill over the foot of the list. Rows are two lines on a phone: sender and time, then subject and
snippet. The row markup is the desktop row's, placed by CSS grid. The contacts panel is not
shown below 1100px. The native apps load this page in their webview in app
display mode (no site header), where the scope bar is the only mailbox
switcher.

### Mailbox-per-address model

**A mailbox IS an address (alias).** `beth@` and `legal@` are two mailboxes
because they are two aliases — there is no separate container entity. Because the
router stores **one `iem` row per (message, recipient)**, every stored row
belongs to exactly one mailbox via `iem_iea_inbound_email_alias_id`.

### Grants and the switcher

Access is an explicit **grant** of a user to an alias, stored in
`ieg_inbound_email_mailbox_grants` (`InboundEmailMailboxGrant`). One alias can be
granted to several users (a shared team `legal@`); one user can hold several
mailboxes. Grants are managed on the **alias editor** ("Users with access"); on
save the editor calls `InboundEmailMailboxGrant::sync_for_alias($alias_id, $user_ids)`,
which diffs the set (insert added, delete removed). Grants cascade-delete with
either the alias or the user.

The reader's **left rail** is a switcher over the addresses the viewer has been
granted, each independently badged with its unread count, each with its folders
beneath it. Selecting one scopes the whole reader to that mailbox.

The rail lists **where mail lives, and nothing else**. Everything that belongs to a
mailbox sits inside it rather than beside it: the selected mailbox's folders (Inbox,
its own Drafts, All Mail, any tracked IMAP folders, Spam, Trash) are indented under it,
and its contacts are the right-hand panel. An all-access viewer additionally sees one
**Unmatched** box per domain that holds unrouted mail — per domain because catch-all mail
seals to the domain's owner, so a single lumped box could hold mail sealed to several
different people and could state no honest protection level. A box is offered whenever it
holds anything live **or discarded**, since hiding an emptied box would also hide the only
route to its own Trash.

Selecting rows in the list and acting on them sends the whole selection to
`thread_action` as `thread_keys[]`, which expands each key **under the caller's
own scope** and unions the resulting message ids. A key the caller cannot see
contributes nothing, so naming a conversation can never reach it — the same
guarantee the single-`thread_key` path carries, and the mutations re-check scope
in SQL besides.

A debounced search box sits in the conversation-list header. It runs a single PostgreSQL
full-text query (`websearch_to_tsquery`) over the sender, subject, and both
plain and HTML body fields at once, backed by the `iem_fulltext_idx` GIN index
on the matching `to_tsvector` expression. Searching the HTML body directly is
safe: PostgreSQL's text-search parser classifies markup as `tag` and skips it, so
an embedded stylesheet contributes no lexemes and an `<a href>` indexes only its
link text. The expression lives once in `MailboxService::FULLTEXT_SQL` and the
migration builds the index **from that constant** — the index serves the query
only while the two match byte for byte, and a silently-unused index is
indistinguishable from a slow one. Each column in the expression is
`left()`-capped (250 KB per body) because a `tsvector` has a hard 1 MiB limit
and the index is evaluated on INSERT — uncapped, a message with more text than
that is not merely unsearchable but **unstorable**, failing the insert itself. A mailbox whose IMAP feed has discovered
folders also lists them **indented under the selected mailbox** (an "All Mail"
root for the folder-unfiltered view, then each tracked folder); see the Sync
subsection for how membership drives folder contents.

### Threading and shared state

Threading is by `iem_thread_key`, computed at store time by
`InboundEmailRouter::computeThreadKey()` (References first token → In-Reply-To →
own Message-ID → null; a null key is a singleton, keyed client-side as `m:<id>`).
Subject-based grouping for header-less mail is a deliberate non-goal.

The row's **preview** is the plain body when there is one, and otherwise the
reading text of the HTML via `MailboxHtmlSanitizer::toPreviewText()`. That is a
DOM walk, not a `strip_tags()`: received bulk mail carries its stylesheet inside
the document, and stripping tags keeps the CSS between them, so the preview
would read `a.cta_button{-moz-box-sizing…`. The walk drops `<style>`, `<script>`,
`<head>`, `<title>` and comments with their contents, treats block edges as word
boundaries (table-built mail otherwise reads as `benefitTerms apply`), keeps link
text without the URL, and removes the invisible characters — zero-width joiners,
soft hyphens, combining grapheme joiners, non-breaking spaces — that senders use
to pad a preheader. An image-only message previews as empty, which is honest.
`toPlainText()` remains the separate, faithful plaintext copy of mail *we*
composed, and does render links as `text <url>`.

The **sealed search index** reduces an HTML body the same way, so a sender's
stylesheet is never searchable — otherwise a search for `container` or
`sans-serif` matches every newsletter in the mailbox. Changing what
`MailboxIndex::rowContent()` indexes changes what a stored index holds:
`purgePersisted()` the affected owners so the next unlock rebuilds, or the old
text keeps matching.

A list row also carries a **paperclip** between the subject line and the time
when any message in the thread has a real attachment — `has_attachment` on the
thread payload, from one id-only query over the page's messages
(`MailboxService::messageIdsWithAttachments()`). Inline `cid:` parts do not
count: they are body content, not something the reader would go looking for, so
a message whose only "attachment" is an embedded signature image shows no clip.

Read/star state lives **on the message row** (`iem_is_read`, `iem_is_starred`,
`iem_read_time`) — not in a per-viewer table. On a shared mailbox this means read
state is **shared** among everyone with access (team-inbox semantics: you see
what a colleague already handled). Opening a thread marks it read for everyone on
that mailbox. Read/star state is a property of the mailbox row, shared by everyone
granted access to it.

### Sender names

`iem_sender` holds the `From` display name beside the address, in the form
`"Name" <addr>`. `InboundEmailRouter::senderDisplayString()` builds it for the
Postfix and webhook paths (both the immediate store and the deferred parse a
sealed mailbox uses); the IMAP and archive paths get the same shape from Horde's
envelope. Encoded words are decoded, a name identical to the address is dropped,
and the name — attacker-chosen text arriving over SMTP — is stripped of quotes,
angle brackets and CR/LF before being quoted. When the column limit bites, the
**name** is what gets cut: an address short of bytes is an unreplyable sender.

The `From` addr-spec is the **last** angle-addr in the header, not the first. A
display name may legally be a quoted string containing angle brackets, so
`From: "Support <billing@paypal.com>" <thief@evil.example>` is a valid header
whose real address is `thief@evil.example`. Reading the first one would let a
sender choose the address used for `iem_sender`, the reply address, the contact
lookup, filter matching and the SRS envelope, while the authentication results
still described the domain that actually sent the message.

The reader's list column shows the name and keeps the address on the row's hover
title. With no display name at all, the **sending organization** is the label —
`hello@fireworks.ai` reads as `Fireworks`, since the local part of automated mail
(`no-reply`, `meet`, `product`) identifies nothing. The organization label is the
last host label below the public suffix, which drops infrastructure subdomains for
free (`accounts.google.com` → `Google`). The exception is a consumer mail
provider, where the person is the only identity available, so the local part is
used instead (`jeremy.tunnell@gmail.com` → `Jeremy Tunnell`, never `Gmail`).

That exception covers only what could actually be somebody's mailbox. A **role
address** — `no-reply@`, `support@`, or anything carrying a no-reply marker
(`AmericanExpress-no-reply`) — is infrastructure, and an address **below** a
provider's own domain is the provider writing rather than one of its users, since
a personal mailbox never lives at a subdomain. Both fall back to the organization:
`no-reply@notify.proton.me` reads as `Proton`, not `No-Reply`.

All four lists live at the top of `mailbox_reader.js`. The same rules are mirrored
in the native mail kits (`MailDisplay` in `ios/joinery-kit/.../MailModels.swift`
and `android/joinery-android-mail/.../MailModels.kt`) so one message reads the same
in the app and the browser — change them together. Each of the three has its own
guard: `plugins/mailbox/tests/sender_name.mjs`, `MailParsingTests.swift`, and
`MailParsingTest.kt`.

An open message shows the name **and** the address (`senderFull()`). A display
name is only ever as trustworthy as the domain behind it, and the domain is the
part that survived DKIM.

### The viewer seam

`MailboxViewer` (`includes/MailboxViewer.php`) answers *who is looking and what
may they touch*:

- `accessibleAliasIds()` — for a permission-10 superadmin, **every** alias
  (all-access oversight: every mailbox plus a merged "All mail" view that also
  surfaces unmatched NULL-alias mail); otherwise the aliases the viewer holds a
  grant for.
- `scopeAliasIds(?int $aliasId)` — the **single** place audience becomes a query
  filter: an accessible alias → `[id]`; a null selection → the full accessible
  set; a non-accessible alias → `[]` (matches nothing). The superadmin "All mail"
  unconstrained case is handled in `MailboxService`, gated by `isAllAccess()`.

`MailboxService` funnels **every** read and mutation through the viewer's scope,
so a crafted id/thread/alias for an un-granted mailbox returns nothing and mutates
nothing. `MailboxViewer::forUser($user_id, $permission)` builds a viewer
independent of the session.

### Permissions

The endpoints (`ajax/mailbox_*.php`) require a **signed-in session** — any
member — and `MailboxViewer` is the sole authority on which mailboxes a viewer
touches: grants partition mailboxes per user, and permission-10 superadmins are
all-access (every mailbox plus "All mail" and the per-domain "Unmatched" boxes). The admin page itself
stays permission-5, and grant management (the alias editor) is admin-only.
Reply/Reply-All/Forward are gated by `MailboxViewer::canCompose()` — a grant
means full access to the mailbox, reading it and sending as it, so any viewer
with at least one accessible mailbox may compose; per-alias send scope is
enforced inside `MailboxSender`.

### Endpoints

All endpoints are `/api/v1` actions (POST, browser-session credential: session
cookie + `X-Joinery-Csrf`). The reader consumes the response envelope's `data`.

| Action | Purpose |
|--------|---------|
| `mailbox/mailboxes` | switcher: accessible mailboxes + unread |
| `mailbox/thread_list` | thread list (`alias_id`, filters, `page`) |
| `mailbox/thread` | messages in a `thread_key` (with bodies) |
| `mailbox/thread_action` | mark read/unread, star/unstar, delete — accepts `ids[]`, a `thread_key`, or a whole selection as `thread_keys[]` — each expanded server-side |
| `mailbox/send` | multipart: send a reply / reply-all / forward / new message AS the mailbox; stores the sent copy |
| `mailbox/message_source` | the original RFC822 source of one message, for **Show original** |

HTML bodies stay sandboxed — stored mail is fully attacker-controlled. The
reader's frame carries `sandbox="allow-popups allow-popups-to-escape-sandbox"`
and nothing else: no `allow-scripts` and no `allow-same-origin`, so no script in
a message runs and the frame reaches neither the session nor the page around it.
The two popup grants are what make a link in an email clickable — `allow-popups`
lets the frame open a tab at all, and `allow-popups-to-escape-sandbox` keeps the
opened site from inheriting the frame's restrictions and loading broken. The
reader splices `<base target="_blank">` into the message document (after the
sender's `<head>`/`<html>`/doctype, so the frame stays out of quirks mode), which
sends every link to a new tab rather than navigating the message frame itself.
Browsers imply `rel=noopener` on `target=_blank`, so the opened tab gets no
handle back on the reader. The admin detail page
(`admin_mailbox_message.php`) is a forensic view and grants nothing: its
`sandbox=""` frame leaves links dead on purpose.

### The message kebab: Show original, Download .eml, Print

Every message card carries a kebab (⋮) in its top-right corner, on both mounts:

| Item | What it does |
|------|--------------|
| **Show original** | the message exactly as it arrived, headers and all, in a modal with a Copy button (`mailbox/message_source`) |
| **Download .eml** | the same bytes as a `message/rfc822` file, named from the subject |
| **Print** | a print sheet — addressed header block, body, attachment names — opened in a new tab and printed on load |

Every one of them scopes the read to the caller's own grants exactly as the
reader does (a NULL-alias catch-all message stays superadmin-only), so the member
mount and the admin mount give the same answer and the admin mount needs no staff
route of its own. The two exports live at `/profile/mailbox/original`
(`logic/profile_original_logic.php` gates, `includes/message_export.php`
renders); `format=print` picks the sheet, and the `.eml` download is the default.

**Where the original comes from.** The thread payload's `original_source` says
what each message can answer with, and the menu follows it rather than offering
dead ends (`specs/mailbox_show_original_coverage.md`):

- **`stored`** — a whole RFC822 original is stored here (a raw-storage
  fallback shape, or a legacy inline row). Both items show.
- **`imap`** — a reference-backed message (`iem_raw_storage_driver` =
  `remote`) whose source account is still connected: the true original is
  fetched live from the source mailbox by its locator (Message-ID fallback if
  UIDVALIDITY changed), passed through, and never persisted. Both items show;
  a message since expunged at the source gets an honest message instead.
- **`headers`** — a lean record: push ingest splits attachments into Files and
  discards the raw, but retains the wire header block in `iem_raw_headers`
  (sealed like the body on a sealing mailbox). Show original renders the
  headers plus the decoded text body and labels it a reconstruction; there is
  no `.eml`, because a downloaded file claiming to be the original must be
  one. Rows stored before header retention have no header block and answer
  `none`.
- **`none`** — neither item; Print always appears — it works from the parsed
  body.

Reaching either export by URL anyway gets an honest page saying so, never a
broken file.

Two more limits worth knowing: Show original cuts off past **1 MB** and names the
full size (a message with attachments is mostly base64, and the whole of one
belongs in the download); and a sealed stored original or header block on a
protected mailbox needs an open unlock window, where a locked one answers
`{locked:true}` and the modal offers the one-tap ceremony before asking again —
the same contract as reading a sealed body. The live IMAP answer needs no
window: the seal protects the local copy at rest, and those bytes come straight
from the source mailbox to a viewer already scope-checked for the message.

**The print sheet is the one place received HTML is not sandboxed.** It cannot
be: a browser prints only the visible slice of a scrollable frame, and the
frame's opaque origin is exactly what stops us measuring its content to size it.
So the sheet inlines the body through `MailboxHtmlSanitizer::sanitizeForPrint()`
— an allowlist that keeps what an email's layout is made of (tables, alignment
attributes, inline styles) and drops everything that executes, fetches, or
escapes the attribute, including `<style>` blocks and any style value carrying
`url()` / `expression()` / `@import`. Images survive only with an `http(s)` src;
`cid:` parts are already signed URLs by then. The response then carries a
`Content-Security-Policy` allowing no script beyond the sheet's own nonce'd
print call and no network fetch beyond images, so a miss in the sanitizer is
still not an execution. `plugins/mailbox/tests/print_sanitizer_test.php` holds
that contract.

### Reply / Forward / New Message

From an open conversation, **Reply**, **Reply All**, and **Forward** compose a
message sent **as that mailbox**, threaded into the conversation, with the sent
copy stored so the thread reads as a back-and-forth dialog (outbound messages are
labelled "Sent"). A **New message** button (the reader's list header, and the
mailbox screen's toolbar on iOS) starts a conversation from scratch instead —
see "New message" below for what differs.

- **Compose UI.** A single `FormWriter` form is rendered once in the reader
  (hidden) and the reader's JS shows it, populates To/Cc/Subject and the quoted
  context, and submits it by `fetch` so the page never reloads. The form is
  rendered with `csrf => false` (FormWriter's single-use, 2-hour token would
  break a second compose in a long-lived reader); the endpoint validates the
  reader's persistent `mailbox_reader_csrf` token instead, as the other reader
  actions do.
- **Identity & transport.** `mailbox_send.php` resolves the mailbox to a
  transport with `resolveOutboundTransport()` and sends through the one
  `EmailSender` pipeline. An **IMAP-source** mailbox (a connected account) sends
  through the feed's own SMTP as the feed address; a **hosted alias**
  (`alias@our-domain`) sends through the platform's active provider as the alias,
  with the domain's DKIM/SRS. Sending is gated by the same grant that governs
  reading the mailbox.
- **Threading.** Replies set `In-Reply-To`/`References` on the wire from the
  replied-to message; the stored outbound row reuses the conversation's
  `iem_thread_key` (a singleton original is given a real thread key on first
  reply so the two group). A forward starts a fresh external thread (no reply
  headers) but still files into the conversation locally.
- **Forward attachments.** The original's attachments are re-attached: an
  IMAP-source original loads its `ima_` manifest and fetches each part on demand
  (`ImapIngestor::fetchPart`); a hosted original parses `iem_raw_message` with
  `Horde_Mime`. If a reference-backed original is no longer in the source mailbox,
  the forward fails with a clear message rather than sending an empty body.
  User-uploaded attachments ride along in every mode.
- **Uploading new attachments.** `POST
  /api/v1/action/mailbox/send` accepts the identical multipart
  `attachments[]` field (a multipart POST leaves `php://input` empty, so the
  dispatcher falls back to `$_POST` and PHP fills `$_FILES` natively — no
  transport change needed), and the iOS reply/forward sheet attaches from
  Photo Library or Files the same way. Every surface enforces the same caps
  (`MailboxSender::MAX_UPLOAD_FILES` / `MAX_UPLOAD_BYTES` / `MAX_TOTAL_BYTES` —
  10 files, 10 MB per file, 25 MB total including any re-attached forward
  originals); a cap breach fails the whole send so a partially-attached email
  never goes out. On success each upload persists as a private `File`
  (`fil_source = email_attachment`, owned by the sending user) with an `ima_`
  manifest row on the new outbound message, so the sent copy shows what was
  attached in every reader (web, admin, iOS) with no separate rendering path.
  Downloads authorize the same way as any other mail attachment: via the
  message's mailbox grant, not `File` ownership.
- **The stored copy.** Each successful send is persisted as an
  `iem_direction = 'outbound'` row (sender = mailbox address, recipient = the
  To/Cc list, `iem_is_read = true`) so the conversation renders from the local
  row immediately — no poll needed. A failed send stores **no** row and surfaces
  the error inline; the draft stays in the panel to fix and resend.

### New message

A fourth compose mode, `mode=new` (`MailboxSender::MODE_NEW`), starts a
conversation with no source message to reply to or quote:

- **Identity.** `alias_id` (not `source_id`) picks the sending mailbox,
  gated by the same `MailboxViewer::canAccess()` grant every other mode
  uses — a grant means full access: read the mailbox and send as it. The
  web reader's From selector and the iOS From picker are both populated
  from the viewer's already-loaded mailbox list (no extra fetch) and
  always show, even with a single grant, as a plain statement of the
  sending address rather than a control to hunt for.
- **Subject and body.** Sent exactly as entered — no Re:/Fwd: prefix, no
  fallback subject, and no quote block (there's nothing to quote).
- **Threading.** No `In-Reply-To`/`References` headers go out. The stored
  row's `iem_thread_key` is the new message's own Message-ID — the same
  "singleton thread" rule inbound ingest uses for a first-contact message
  — so when the recipient replies, their `In-Reply-To` resolves back to
  that key and the reply files into this same conversation, not a new one.
- **Uploads.** `attachUploads()` runs in every mode, so a new message can
  carry attachments with no extra work.

### Rich text, Bcc, and inline images

The composer body is a vanilla `contenteditable` editor with a small toolbar
(bold / italic / underline, bulleted & numbered lists, link, clear formatting).
On send the client posts `body_html`; the server sanitizes it against a strict
allowlist (`MailboxHtmlSanitizer` — `p br div b strong i em u a[http/https/mailto]
ul ol li blockquote img[cid:]`; everything else unwrapped, every other attribute
stripped) and derives `iem_body_plain` from the sanitized HTML. The plaintext
`body` param still works unchanged for degraded clients, so the `send` contract
stays backward-compatible.

**Bcc** hides behind a toggle next to Cc. It is delivered as true envelope Bcc and
stored on the outbound row in its **own sealed column `iem_bcc`** — never merged
into `iem_recipient`, so a reply-all on your Sent copy can never re-leak a bcc'd
address. The Sent view shows a separate "Bcc:" line.

**Inline images** paste or drag into the editor. Each rides in `attachments[]` with
an `inline_manifest` (local-id → filename); the server embeds it with a minted
`Content-ID`, rewrites `cid:{local-id}` in the stored/sent HTML, and persists it as
an `ima_is_inline` manifest row — so the Sent copy's inline art renders through the
same `resolveInlineImages()` path as received inline images.

### Drafts

Drafts are `iem_inbound_email_messages` rows with `iem_direction='draft'` — no new
table (`MailboxDrafts`). A draft carries the From alias, subject/body, `iem_recipient`
(To + Cc), `iem_bcc`, and a sealed JSON `iem_draft_state` (`{mode, source_id, to, cc}`)
that restores the exact fields on reopen. The composer autosaves (debounced, on close,
and on `beforeunload`); the first save creates the row, later saves update it.

**A draft is personal compose state, owned by its author** (`iem_draft_author_user_id`):
every read/write is scoped to that user, so a co-grantee of a shared mailbox and an
all-access superadmin can neither see the draft in the Drafts rail/count nor open, edit,
send, or delete it. The author column is cleared when the draft morphs to an outbound row.

The compose panel closes two ways: the **× is save-and-close** (the panel is always safe
to close — it persists and keeps the draft), and a separate **🗑 discards** after a
confirm (hard-deletes the row, its attachment manifest, and the backing Files). On send
with `draft_id`, the draft **morphs into the Sent row** in place — direction flips, the
final From alias/domain are written (a mid-draft From change files the Sent copy in the
right mailbox), and the already-uploaded attachments are reused — or the draft is deleted
in the Gmail pending-Sent-ingest case.

Attachments persist onto the draft on save; the save response returns the authoritative
attachment list so the client never re-uploads bytes it already sent, and a saved chip's
× removes one part (`draft_attachment_delete`). Pasted **inline images** persist as inline
manifest rows carrying their local id as Content-ID; reopening a draft resolves each
`cid:{id}` to a signed URL so the image renders in the editor, and sending re-embeds the
stored bytes under the same Content-ID. Removing an image from the editor prunes its
stored part on the next save.

A sealed draft re-seals its content under the SAME per-draft DEK on every save (reused
in-window) so its attachments stay readable; autosave never blocks on the unlock window
(a fresh public-key seal needs no window). Sending a sealed draft unwraps that DEK once,
up front — a closed window fails loudly before anything reaches the wire (`locked:true`,
prompting a one-tap unlock), never delivering a message shorn of its sealed attachments.
A From change from a sealed to a standard mailbox clears `iem_content_sealed` but retains
`iem_sealed_key`, so the draft's already-sealed attachments stay decryptable.

Each mailbox has its own **Drafts folder** in the folder rail, beneath its Inbox, carrying
that mailbox's draft count. Every draft is bound to a From mailbox at save time
(`MailboxDrafts::save()` rejects one with no alias), so a draft always has exactly one place
to live and none can be stranded. The folder lists via `thread_list` `drafts=1` with the
mailbox's `alias_id`; passing no alias keeps the cross-mailbox form. An unmatched box has no
From identity and so gets no Drafts folder. Every other view/query, the FTS index, IMAP
dirtiness, and AI triage/scan/schedule exclude `direction='draft'`.
Because a morphed draft keeps its message id (now below the FTS high-water mark), each
mailbox owner's search bookkeeping carries a **refold queue** (`imi_refold_ids`) — the
sent message is explicitly re-indexed on the next fold so it becomes searchable.

### Signatures

Each grantee sets a per-mailbox compose signature (`ieg_signature` on the grant —
sanitized HTML, **not sealed**: a signature is a cleartext template on every outgoing
message). A gear on each of the viewer's own mailboxes opens a small editor
(`mailbox/signature_save`, own grant only). The `mailboxes` payload carries each
mailbox's signature; on compose open the client inserts it into the editor above the
quote, where the user sees and can edit it before sending — the server does no injection.

### Contacts + recipient autocomplete

A contact store (`imc_mailbox_contacts`, `MailboxContact` / `MailboxContacts`) holds the
addresses a user chose to keep. **Mail traffic never writes to it.** The only two ways in
are a hand-add (`imc_source` `manual`) and a vCard / Google CSV import (`import`); sending
and reading file nobody. That is deliberate: anyone who can send you mail could otherwise
put themselves in your address book, and a list that fills itself with spam senders is no
use for what it is for — offering the people you meant to write to. `MailboxContacts`
enforces this in its shape, exposing only `manualAdd()` and `import()` as writers, and the
`contacts` test asserts that public surface so a traffic-driven writer cannot creep back.

Contacts belong to **one mailbox** (`imc_iea_inbound_email_alias_id`), not to the account:
composing from a work address never suggests what is kept in a personal one. The same
person added on two mailboxes is two rows, which the store treats as normal — it is a
cache, not a person record. An add lands in the mailbox it was made from, and one naming
no mailbox is refused rather than stored where no mailbox-scoped read would surface it.
Scope is a property of the row, not of the sealing: a row seals to the **adding user's**
vault, so two grantees sharing one mailbox each keep their own contacts, readable only by
them.

Rows are sealed when that user holds a vault (`imc_address` / `imc_display_name` under a
per-row DEK); dedup is `imc_address_hash` — a keyed blind index for vault holders (never
leaks the sealed address), plain SHA-256 otherwise. The hash covers the **mailbox and the
address together**, which is what makes the existing `(hash, user)` unique constraint mean
one row per (user, mailbox, address) without a composite key over an encrypted column.

The composer fetches the whole (small) decrypted list for one mailbox and filters it
client-side for To/Cc/Bcc autocomplete (no server prefix-search over ciphertext); a locked
vault makes autocomplete silently absent. Changing the **From** selector re-fetches the
list for the newly chosen mailbox, so suggestions always follow the address being written
from. Addresses already typed are left alone — only the suggestion list changes.

Because nothing files itself, a row's mere presence means the user put it there — there is
no seen-vs-saved distinction to report. `MailboxContacts::lookup()` returns how the row got
there (`manual` or `import`) and when. Adding an address already held bumps the existing row
rather than inserting a second: a hand-add re-stamps an imported row `manual` and fills a
display name the import never carried. An add that cannot be written — a sealed store whose
vault window has closed has nowhere to put the address — returns false, so the reader reports
a failed add instead of appearing to have saved it.

### Contact panel

The right-hand aside is where contacts live — the left rail lists **where mail lives**, and
a contact store belongs to a mailbox rather than sitting beside one. The panel has two
states over the same element:

- **On the list view** — the selected mailbox's contact manager (add, delete, and import a
  vCard / Google CSV via `mailbox/contacts_import`, all landing in that mailbox).
  **Collapsed to a labelled spine by default**, since it is reference material rather than
  the task at hand; the open/closed choice is remembered across visits. A view with no one
  mailbox behind it (All mail, or an unmatched box) has no single store to show, so the
  panel steps aside entirely.
- **On an open conversation** — the correspondent's card, expanded
  (`mailbox/sender_context`). The client sends the **message id, never an address**, so the
  endpoint can't be a membership oracle: the server re-derives the counterparty from a
  message already in the caller's scope, and that scope binds admin and non-admin alike.

The card names the correspondent, shows their address, and states whether they are **In
Contacts** or **Not in Contacts** — the latter with a one-click **+ Add** that posts the
address (with the display name from the message) to `mailbox/contacts_import` and re-renders
from the server. Because contacts are per-mailbox, the answer is about **this mailbox
alone**; the same address may be kept in another. Mail belonging to no mailbox has no store
to add to, so the Add control is absent rather than offering a save that cannot land. For a
known contact the card also shows when it was added or imported, and a link that searches
the mailbox for all mail with that address — the store itself knows nothing about how much
mail was exchanged, having never watched the traffic. A sealed contact store with no open
window can answer neither way, so the card says **Contacts locked** and offers Unlock rather
than asserting "not a contact".

Below the card, a **Site account** section is **admin-only** (permission 5+), because member
records, orders and registrations are operator data: it resolves the address with
`User::GetByEmail` and shows the joined date and a link to the admin edit page, or "No
account on this site", followed by recent orders / event registrations / conversation count,
each present only when its plugin/feature is active. For a non-admin the server never looks,
returns `account_visible:false`, and the client omits the whole section — so an absent
section reads as "not disclosed to you", never as "no account". The panel is lazy,
session-cached, collapsible, and hidden below a width breakpoint.

## API Surface

The mailbox is exposed to API clients (the native mobile mail screens,
`docs/mobile_apps.md`) as actions under the plugin namespace,
`POST /api/v1/action/mailbox/{action}`, session-key authenticated:

| Action | Purpose |
|---|---|
| `mailboxes` | The viewer's granted mailboxes with unread/total counts, folder rails, per-mailbox `signature`, `own` flag and `drafts` count, plus `can_compose`; for an all-access viewer also `all_mail` and `unmatched` — an array of one entry per domain holding unrouted mail (`domain_id`, `domain`, `security_level`, `unread`, `total`, `trashed`) |
| `thread_list` | Paged threads for a mailbox view — params `alias_id`, `q`, `unread_only`, `starred_only`, `spam`, `inbox`, `folder_id`, `drafts`, `page`; same row shapes as the web reader's list endpoint. `alias_id` takes a mailbox id, `unmatched:{domain_id}` for a domain's catch-all box, or nothing for all accessible mail |
| `thread` | One full thread: messages with plain/HTML bodies, attachment manifest, and the thread's folder ids |
| `thread_action` | The reader's full mutation set: `mark_read`/`mark_unread`, `star`/`unstar`, `archive`/`unarchive`, `delete`, `mark_spam`/`mark_not_spam`, `set_membership`, `create_folder` — targets `ids[]`, a `thread_key`, or `thread_keys[]` (the list's multi-select) |
| `send` | Reply / reply-all / forward / new message as the mailbox — `source_id` or `alias_id`, plus optional `bcc`, `body_html`, `inline_manifest`, `draft_id` (morph a draft); plain JSON or multipart `attachments[]`; forwards re-attach the original's parts server-side |
| `draft_save` / `draft_get` / `draft_delete` | Create/update, reopen, and discard a compose draft (multipart attachments + `inline_manifest` on save; save returns the persisted `attachments`/`inline` lists) |
| `draft_attachment_delete` | Remove one saved attachment from a draft — `draft_id`, `attachment_id` (author-scoped, non-inline) |
| `signature_save` | Save the caller's compose signature for one of their mailboxes |
| `contacts` / `contact_delete` / `contacts_import` | List (decrypted, ranked) / delete / import-or-add the caller's contacts for ONE mailbox — `contacts` and `contacts_import` both require `alias_id`, since a contact belongs to a mailbox |
| `sender_context` | Resolve a thread counterparty (by message id) to the caller's contact-store entry, plus (admins only) their member record, orders and registrations |

Each action is a `logic/{action}_logic.php` with a `_logic_descriptor()` opt-in that
builds a `MailboxViewer` for the key's user and goes through
`MailboxService` / `MailboxSender` — the same shared brain the web AJAX
endpoints wrap, so scoping, threading, view semantics, and send side effects
live in exactly one place. There is no authorization logic in the actions
themselves: viewer scope is the single authority, and out-of-scope ids
silently affect nothing (same guarantee as the AJAX layer).

**Signed URL transport.** Sessionless clients can't fetch attachments with
web cookies, so the `thread` action enriches its payload via
`MailboxService::withSignedTransport()`: every file-backed attachment carries
a short-lived signed download URL (`docs/file_signed_urls.md`), and each HTML
body has its inline `cid:` references rewritten to signed URLs for that
message's inline file-backed parts. Minting happens only after the
viewer-scope check that gated the thread fetch; the serving path validates
signature + expiry with no session at all. Attachments whose bytes are not a
private File (IMAP on-demand / raw-section parts) carry `url: null` and
stream only through the sessioned member endpoint.

**The app route flip.** The plugin's `profileMenu` entry declares
`"nativeScreen": "mailbox"`, so the app navigation endpoint serves the Email
entry as `{type: "native", screen: "mailbox", fallback_url:
"/profile/mailbox/mailbox"}` — clients with the native mail module
render these actions' screens; older builds keep loading the web reader.

## Spam filtering

Spam is a **first-class verdict on the message**, `iem_spam_verdict` (`ham` /
`spam`; NULL = not evaluated). It is what the reader filters on, so one Spam view
works identically for locally-received mail and IMAP-polled mailboxes. There is no
folder membership — the verdict is the disposition. The app runs **no scorer of its
own**: it acts on the auth verdicts and on a binary spam result a content scanner (or
the webhook provider) decides, recording the scanner's numeric score only for display.
The one exception is SendGrid, which exposes a score but no binary, so its result is
derived from a configurable threshold (see [Content scanner](#content-scanner-rspamd)).

Three protection layers stack: the MTA's RBLs at RCPT time, the auth rule below
(DMARC/SPF/DKIM), and a content scanner — the only layer that catches **authenticated
bulk spam** (junk that passes its own DMARC/SPF/DKIM: lookalike domains, bulk mail
from real ESPs, a compromised aligned account). All three feed the same
`iem_spam_verdict`.

Gated by `mailbox_spam_filtering_enabled` (default **on**), toggled on the
**Settings** tab as *Move suspected spam to the Spam view*. When off, the verdict
stays NULL and nothing changes. Default-on is safe because the disposition is
reviewable — spam is moved, never rejected, bounced, deleted, or forwarded — and
because the auth verdicts it acts on are recorded for every message regardless.

**Classification rule.** The router acts on the SPF/DKIM/DMARC verdicts it already
records (it never computes them — see [Inbound authentication](#inbound-authentication-spf--dkim--dmarc)).
`InboundEmailRouter::classifySpam()`:

- **DMARC `fail` → `spam`.** The primary rule. DMARC is alignment-based and already
  subsumes SPF and DKIM, so it is the one signal worth acting on directly. Applies
  wherever a DMARC verdict exists (Postfix milters, SES).
- **No DMARC verdict, and SPF *and* DKIM both `fail` → `spam`.** The fallback for
  providers that supply SPF/DKIM but no DMARC field (Mailgun, SendGrid). Both must
  fail: raw SPF/DKIM lack DMARC's alignment check, so a single failure has too many
  legitimate causes (forwarding breaks SPF; some legit mail breaks DKIM), whereas
  both failing is a clean "even basic auth broke" signal.
- otherwise **`ham`**.

The rule is intentionally strict because the disposition is reviewable, never
rejection: a false positive costs a click in the Spam view, not a lost message.

**Forward suppression.** A judged-`spam` message is **never relayed** — forwarding
spam burns the platform's sending reputation and can relay abuse. The forward is
suppressed and logged with status `spam_held`. A `forward_and_store` alias still
stores the message (with its `spam` verdict) so it stays reviewable; only the
outbound forward is dropped. Pure-store and catch-all-store aliases store as usual,
verdict and all.

**IMAP-polled mail.** No auth rule runs — the remote server already classified it.
A message ingested into a folder whose `iif_role` is `junk` is marked
`iem_spam_verdict = 'spam'`, giving the Spam view the same meaning for polled mail.

**Reader.** The default inbox (and the mailbox unread badges) exclude `spam`-verdict
rows; a **Spam** entry in the per-mailbox folder rail shows only them. Per
conversation, **Report spam** (inbox) / **Not spam** (Spam view) set the verdict
directly.

### Content scanner (rspamd)

The content layer is a second verdict source **OR'd** into `classifySpam()`: a message
is `spam` if the content scanner flagged it **or** the auth rule fires. It changes no
downstream behavior — same `iem_spam_verdict`, same Spam view, same forward
suppression. The signal is resolved per ingest path:

- **Postfix path.** rspamd runs as a Postfix milter *after* opendkim + opendmarc (so it
  scores on the auth results), in header-stamping mode only (**never** rejects —
  consistent with the reviewable-verdict model). It stamps `X-Spam: Yes` on a spam
  verdict (plus `X-Spam-Status` carrying the score); `InboundEmailRouter::readSpamHeader()`
  reads that header, trusting it on the same basis as the `Authentication-Results` line
  (the milter is ours, and rspamd strips any inbound-forged `X-Spam` before re-stamping).
- **Webhook providers.** Mailgun, SendGrid and SES supply their own content/reputation
  spam signal in the authenticated payload; each provider's `handleInbound()` surfaces it
  as a `spam` key, carried into the router as a sibling of the auth verdicts. SES's
  `spamVerdict` and Mailgun's `X-Mailgun-Sflag` are binary verdicts. SendGrid posts only a
  numeric `spam_score` (its SpamAssassin score, no yes/no), so the binary is derived by
  comparing it to `sendgrid_inbound_spam_threshold` (default `5.0`, SpamAssassin's own
  `required_score`; tunable on the Setup tab). The raw score is recorded either way.
- **IMAP-polled mail.** Unchanged — the remote already classified it (junk-folder mapping).

**Reading a verdict and computing one are separate concerns.** Whatever scanner
verdict arrives with a message is always read: the `X-Spam` header a relay or a
local milter stamped, or a webhook provider's own flag. That costs a header parse
and needs no scanner here, so it works on every box whatever it runs. Whether any
verdict changes a message's disposition is `mailbox_spam_filtering_enabled`'s
call — with it off, the stored verdict stays NULL no matter what any scanner
said.

**Learning.** `mailbox_spam_learning_enabled` (default off, shown on the
Settings tab as *Learn from what users mark as spam*, and only while filing is
on) is the one advanced choice. It is the single capability no upstream scanner
can provide: a Bayes corpus of **this deployment's own mail**, taught by its own
users' corrections. A shared relay is deliberately stateless — one model trained
across every tenant's mail would be both a privacy leak and a poisoning vector —
so learning cannot be delegated upstream; it runs on the scanner that ships with
the mail stack, whatever the topology.

**Which mail is re-scored here.** Relay- and webhook-sourced messages are scanned
again locally at ingest through the controller's `/checkv2`, on any box where
filing is on and a scanner is running. Learning is **not** a condition. An
upstream scanner is stateless and its header is the only content signal a fronted
deployment would otherwise ever get — and a header that was never stamped is
indistinguishable from a clean verdict, so scanning here is what makes the
difference observable. Colocated mail is not re-scored: its own milter already ran
exactly that scan.

**How much the local verdict counts** is what learning changes:

| Learning | Local verdict | Why |
|---|---|---|
| off | **OR'd** into the upstream signal — can add spam, never subtract it | Without a corpus the local scan is the same static ruleset the upstream ran, minus the live SMTP client context a milter sees. It is not better informed, so it must not overturn an upstream `spam`. |
| on | **Replaces** the upstream signal, in both directions | The corpus is knowledge that exists nowhere else. Replacement is also the only arrangement in which a user's *Not spam* correction can subtract — an OR could only ever add. |

A scanner that is absent, down or slow costs nothing: the upstream verdict
stands, the message stores normally, and nothing is ever held, bounced or retried
on the scanner's account. Presence is observed once per request
(`MailboxSpamPolicy::scannerAvailable()`) rather than per message, and a box with
no scanner is never called at all — so a webhook-only deployment spends no failed
request per message.

**The scanner ships with the mail stack.** `install_email.sh` installs rspamd +
redis unconditionally on every box that hosts its own mail, and the platform
never removes them, so enabling learning later is a pure settings toggle —
nothing to install, no command to paste. There is no "scanner installed"
setting: presence is observed (the controller answering on its port), and the
Settings page offers the learning checkbox only where a scanner is running. A
box with no local mail stack (webhook-only, or relay-fronted from birth) never
ran a root script of ours and has none; learning is unavailable there — the
checkbox is disabled with the reason — unless an operator hand-runs
`provision_spam_scanner.sh install`.

How the scanner is *used* is decided in software by `MailboxSpamPolicy`, and
every consumer (the health probe, the learning task, the ingest scan, the admin
pages) asks it rather than re-reading settings or re-deriving topology. The
provider is read resolved (`InboundProviderRegistry::active()`), never as the
raw `mailbox_provider` row, so an empty or misspelled setting cannot flip an
answer. `learningEnabled()` is clamped by `filingEnabled()`, so "learning with
nothing filing" is unreachable rather than merely discouraged — the stored row
survives as a remembered preference and takes effect again when filing returns.

| topology | provider | filing | learning | scoring path | re-scored at ingest |
|---|---|---|---|---|---|
| colocated | postfix | on | off | the box's own milter | no |
| colocated | postfix | on | on | the milter, corpus included | no — the milter already scored it |
| relay / fleet | postfix | on | off | the relay's stateless rspamd | no |
| relay / fleet | postfix | on | on | relay, then re-scored here | yes |
| any | webhook | on | off | the provider's own signal | no |
| any | webhook | on | on | provider, then re-scored here | yes |
| any | any | off | either | verdicts read but not filed | no |

**Recorded score.** `iem_spam_score` (nullable) holds the scanner's/provider's numeric
score as reported, for display and tuning only — **nothing in PHP ever branches on it**.
The reader shows it on the message detail when present.

**Provisioning.** `provisioning/provision_spam_scanner.sh install|remove|status` owns
the scanner as a standalone, idempotent, verb-driven step. `install_email.sh` calls
`install` unconditionally — the scanner is part of the mail stack — and re-running
`install` is also the repair for config or milter-wiring drift. `install` installs
`rspamd` and `redis-server`, pins the `X-Spam` header contract, sets
header-stamping-only actions, puts the Bayes classifier on redis with autolearn, and
exposes the rspamd **controller** on loopback `127.0.0.1:11334` (trusted via
`secure_ip` — **no password**, since a privileged learn command is authorized by
originating inside the container). It wires the milter on `inet:localhost:11332`
after opendkim/opendmarc **only when Postfix is present**; on a box without local
Postfix the scanner is HTTP-only and the milter worker idles. `remove` is an
operator escape hatch the platform never runs or surfaces: it unwires the milter,
deletes the joinery-managed `local.d` files and purges both packages — the corpus
goes with redis deliberately, because it is the tenant's private model and Postgres
holds the durable verdicts it rebuilds from. `status` prints machine-readable
markers. `utils/spam_policy.php show` prints the resolved posture for a shell
session. rspamd queries DNS RBLs while scanning, so the host needs outbound DNS
egress.

**Day 2: turning the scanner on and off.** Installed-ness is observed, never
declared, so the `content_spam_scanner` provisioner
(`InboundEmailHealth::checkContentSpamScanner`) compares two facts — *expected*
(`localScannerExpected()`) and *present* (the controller answers):

| expected | present | outcome |
|---|---|---|
| yes | yes | passes; on a colocated deployment the Postfix milter wiring is verified too, since a scanner installed while Postfix was absent never got wired |
| yes | no | fails, naming `provision_spam_scanner.sh install` |
| no | yes | passes — dead weight, not a fault; the Settings tab offers the removal command |
| no | no | passes silently |

So turning learning on shows a red row until the one install command is run; mail
is unaffected in the meantime. Turning it off on a colocated box changes nothing
on the host (the milter keeps scoring, it just stops being taught); on a
relay/webhook box the scanner becomes unexpected and removal is offered. The
listener-decommission and listener-restore helpers are untouched by any of this:
decommissioning deliberately leaves rspamd alone so a learning deployment carries
its corpus across the move, and restoring the listener surfaces any missing milter
wiring through the drift check above.

**redis is disposable.** The Bayes corpus lives in redis (the container's writable layer)
and a recreate/rebuild wipes it. That is acceptable: the **durable** signal is
`iem_spam_verdict` in Postgres, and the corpus self-heals from ongoing corrections after a
wipe — the failure degrades to "the filter is temporarily less sharp," never "training
data lost." A redis volume mount is an optional deploy-layer optimization, never a
correctness requirement.

**Spam/ham feedback (Bayes training).** A reader correction (**Report spam** / **Not
spam**) is the whole trigger — there is no separate "report" control. Flipping
`iem_spam_verdict` leaves the row *diverged* from `iem_learned_verdict` (the marker of
what was last taught). The **`LearnSpamFeedback`** scheduled task (every cron pass, gated
on `MailboxSpamPolicy::learningEnabled()`) reconciles the divergence out-of-band: for each
diverged row it POSTs the raw RFC822 to the controller's `/learnspam` | `/learnham` over
loopback and, on success, stamps `iem_learned_verdict = iem_spam_verdict` so the row stops
re-selecting. Flip-backs and idempotency fall out for free. Every correction that still
has a raw message teaches the corpus, whatever path the message arrived by —
webhook- and IMAP-sourced rows included, since the corpus is a deployment-wide asset
and the local scanner is what scores that mail. Rows whose raw is gone (pruned, IMAP
reference-backed, or sealed out of reach of this keyless cron pass) are marked handled as
permanent no-ops. A controller that is unreachable — not yet installed, or down — returns
`skipped` and leaves rows diverged to retry on the next pass, so the loop self-heals
through an outage and rebuilds the corpus after a wipe rather than stranding corrections.
(rspamd's classifier needs roughly 200 messages of each class before it contributes, so
early corrections have little visible effect.)

## Deliverability reports

Mail providers send machine-generated reports about a domain's mail — DMARC
aggregate XML (who sent as the domain and whether it aligned), TLS-RPT JSON
(where TLS to the domain failed), and ARF feedback-loop complaints (a recipient
marked the domain's mail as spam). They arrive as ordinary email because the
domain's published policy asked for them. `DeliverabilityReportIngest`
(specs/deliverability_report_ingest.md) detects them during ingest and files
them instead of delivering them.

**Detection is by content, never by address.** Two of three signals must match
— the RFC 7489 attachment filename shape, the `Report Domain: … Submitter: …`
subject shape, and the payload structure itself — so a misaddressed report is
still caught and an ordinary message carrying a zip is never touched. ARF is
recognised by its `multipart/report; report-type=feedback-report` content
type. Detection runs at every moment the pipeline holds plaintext: receive
time (`InboundEmailRouter::processEmail`, before the alias branch, and the
relay pull path in `RelaySpoolConsumer`), and deferred parse at unlock
(`parsePendingMessage`) for Fortress relay mail — sealed domains get the same
inventory as everyone else because extraction happens while content is in
hand, never against stored sealed rows.

**A recognised report is filed, not delivered.** No mailbox message is
created (or, on the deferred path, the pending row is removed), so message
counts, unread badges and quotas keep describing human mail. What persists is
derived data: one `dvr_deliverability_reports` row per report and one
`dvs_deliverability_report_sources` row per source line — IP, count,
disposition, SPF/DKIM alignment verdicts, identity domains. Source rows never
expire; they are the domain's long-term answer to "who has sent as us?". A
parsed report's raw is discarded (the rows carry everything it said); a report
that fails to parse keeps its raw in the report row for diagnosis, and a kind
with no parser is recorded and counted rather than dropped. Every filing is a
`report_filed` row in the transaction log.

**Report content is untrusted input.** Payloads are size-capped before and
during decompression (zip and gzip both stream against a ceiling), XML with a
DOCTYPE is refused outright and external entities are never resolved, and a
report naming a domain this platform does not host is discarded without
granting it anything. A failure anywhere files or falls back to ordinary
delivery — it never aborts ingest of the carrying message.

**Surfaces.** The reports view (`admin_mailbox_reports`) is the sender
inventory — sources over a chosen window, unaligned senders first, plus the
report list with parse status. It has no tab of its own (the same reasoning as
the relay: a diagnostic, not a daily workspace) — it is reached from the Setup
tab's "Deliverability reports" row, which sits beside the DMARC row and shows
reports actually arriving (the only proof the published `rua` address is
right), from the new-sender notification email, and via the `report_filed`
lines on the Logs tab. The first time a report names an unaligned source never
seen for the domain, the domain owner gets one email — the source is then
known and updates the inventory silently, with one escalation notice if its
volume later jumps sharply.

## Trash and retention

Deleting mail from the reader is a **soft delete**: `MailboxService::softDelete()`
stamps `iem_delete_time` and nothing else. Trash is a **view over that column**, not a
label or a folder — the same shape as the Spam view — so nothing has to move and a
restore has nothing to reassemble.

**Exactly one view sees a trashed row.** Every read scope pins `iem_delete_time IS
NULL`; `MailboxService::trashScopeSql()` inverts that pin, branch for branch (a single
mailbox, an all-access "All mail", a superadmin per-domain "Unmatched" box). The Trash
view is also the one view that ignores the spam verdict, so mail a filter trashed on
arrival is not invisible in both places. `listThreads()` takes a `trash` filter,
`getThread()` / `messageIdsInThread()` a `$trashed` flag; the reader passes them from
its Trash rail entry.

**Exactly two mutations reach a trashed row.** `restoreFromTrash()` clears the column;
`purgeFromTrash()` deletes for good. Both resolve targets through
`trashMutationScopeSql()` and are its only callers — the `IS NULL` pin that every other
mutation carries is what keeps discarded mail out of the read/star/archive/spam/label
paths, so it is not a parameter those methods can be handed. Restore needs no
bookkeeping: trashing never touched read, star, archive, spam verdict or label
membership, so the message returns exactly as it left.

**A purge reclaims everything or it is not a purge.** Both the reader's *Delete forever*
and the scheduled task go row by row through `InboundEmailMessage::permanent_delete()`,
which frees the file-backed attachment `fil_` Files and the stored raw object (local file
or cloud object). A bulk `DELETE` would drop the row in one statement and leak both. Each
id is queued for refold first, so the owner's sealed search index drops the entry at their
next fold. Sealed mailboxes purge **locked**: `permanent_delete()` works on columns and
storage keys, never on plaintext, so a Fortress mailbox needs no unlock window.

**The window.** `InboundEmailMessage::purgeExpiredTrash()` is declared in that class's
`$retention_policy` and runs in the platform's daily retention sweep, purging what was
trashed longer than `mailbox_trash_retention_days` (default 30) ago. `0` means nothing
purges. A per-run cap (500) keeps a large backlog draining over several runs rather than
one enormous transaction, and says so in its result message. Each Trash row shows **when
it purges**, computed for display from the same setting and the row's delete time — never
stored, because an operator can change the window.

**Unmatched mail ages out on its own window.** Mail stored for an address no alias claims
sits in no member's mailbox, so nobody ever trashes it and the window above never sees it.
`InboundEmailMessage::purgeExpiredUnmatched()` is the second rule in the same
`$retention_policy` list, deleting stored unmatched mail received longer than
`mailbox_unmatched_retention_days` (default 90) ago; `0` keeps it indefinitely. It reads
its own setting because the two questions are different: a member decided about their
Trash, and nobody ever decided about mail addressed to nobody. Read state is deliberately
not consulted — unmatched mail is unread by definition, so exempting unread rows would
exempt the category. Three kinds of NULL-alias row are excluded: already-trashed ones
(the Trash window owns those, on its own clock), outbound rows (a compose belongs to a
member, not a mailbox), and pending-parse rows (still on their way to an alias at the
owner's next unlock). Reclamation and the per-run cap are shared with the Trash purge.

**IMAP-backed mailboxes are one-way.** `ImapSyncer::pushTrash()` moves the source copy
into the account's Trash folder and repoints the locator (which doubles as the
already-trashed marker). Restore and purge act **locally**: a restored message returns
here while the source copy stays in the provider's Trash, and a purge deletes this row and
these bytes without expunging anything remote. Providers run their own 30-day Trash purge,
so the remote copy goes on its own schedule; the reader's Trash view says so on a mailbox
that has a feed.

## AI security scan

A **danger score** (0-10) plus specific **red flags** and a one-line **summary**,
generated by the `email_security_scan` pipeline job (see
`plugins/joinery_ai/docs/overview.md` § Registered jobs) for mail that passes
the spam/auth filters above but is malicious in *content* — attacker-triggered
notifications sent through a legitimate provider's own infrastructure
(`dmarc=pass`) whose payload is an open-redirect sign-in link, which
authentication-based filtering structurally cannot catch. The scan runs after
delivery and only annotates; nothing is deleted, moved, or forwarded by it.

**`EmailSecurityDigest`** (`includes/EmailSecurityDigest.php`) is the
deterministic, LLM-free reduction of one stored message to the bounded
evidence the job's checklist prompt needs — a fixed-section plain-text digest
(`=== EMAIL DIGEST ===` header block, decoded `FROM`/`REPLY-TO`/`RETURN-PATH`/
`TO`/`DATE`, the stored `AUTHENTICATION` verdicts plus the DKIM signing
domain, every extracted `URLS FOUND` — each with its visible anchor text when
it differs from the href, so the classic link-text/destination mismatch
survives tag-stripping, preceded by a `DOMAINS:` per-host count summary (top
15, most frequent first) so a small model never has to aggregate the raw URL
list itself — and the decoded `BODY`). Headers not
already stored as columns (Reply-To, Return-Path, Date) are read from the raw
message when available; From/To/Subject fall back to the already-decoded
`iem_sender`/`iem_recipient`/`iem_subject` columns when raw is unavailable
(a `remote`-driver IMAP row). Whitespace and invisible-character runs of 4+
collapse to one space, with the removed count annotated once it exceeds 200 —
turning obfuscation itself into citable evidence instead of context filler
that can blow a small model's attention. Subject and body are each size-capped
(1024 / 4096 characters) with a `[truncated, N characters total]` marker;
URLs are capped at 20 with a `(+N more)` marker. `EmailSecurityDigest::build()`
is a pure function of an `InboundEmailMessage` — no LLM concepts in the class.
Its format is corpus-validated for this job (any change requires a full
re-score against the labelled corpus), so `email_security_scan` reads it
alone, unaugmented, until that re-score happens.

**`EmailAttachmentDigest`** (`includes/EmailAttachmentDigest.php`) is a
sibling builder the `email_triage` and `email_schedule` jobs append after
`EmailSecurityDigest::build()` — an `ATTACHMENTS (N):` section listing every
non-inline attachment (up to 10, then a `(+N more attachments)` marker):
a metadata line always (`filename — content-type, size bytes`, filename
whitespace-collapsed and capped at 120 characters), plus, for file-backed
parts only, readable text — a `text/plain` body (collapsed, capped at 2000
characters per part) or a `text/calendar`/`.ics` invite parsed with
`IcsImporter::parse()` and rendered as a deterministic `ICS EVENT:` block
(title, start with its timezone, end, location, organizer). All attachment
text combined is capped at 4000 characters, with the same
`[truncated, N characters total]` marker style `EmailSecurityDigest` uses. A
section-pointer or IMAP (`remote`) part gets its metadata line only — no
on-demand IMAP fetch from an unattended job. Any read failure degrades to
`[content unreadable]` and a malformed `.ics` to
`[calendar attachment could not be parsed]`, each after the metadata line,
never failing the item. `EmailSecurityDigest` stays untouched and
corpus-frozen; opting the scan job into attachment evidence is possible but
only alongside a corpus re-score.

**Verdict fields**, written only by the job's `recordVerdict()` (not
`$ai_writable_fields` — there is no other write door):

- `iem_ai_danger_score` (`int2`, 0-10)
- `iem_ai_scan` (`jsonb`: `{verdict, red_flags, summary, model, recipe_id}`)
- `iem_ai_scan_time` (`timestamp`)

All three are `NULL` until a pipeline recipe scans the message. Re-scoring
after a mis-score is an admin deleting the recipe's `aip_recipe_item_log` row
for that message — the run picks it up again on the next pass.

**Reader surface.** The thread list shows a compact badge — amber "Caution"
for a danger score of 5-6, red "Danger" for 7-10 — silent below 5 since an
unremarkable inbox is the common case (`danger_score` is the max across the
thread's messages). The message view shows a banner with the score, the
summary, and the red-flags findings, in a green `safe`, amber `caution`, or
red `dangerous` tier taken from the score itself rather than the stored
verdict word, so a message scanned under an earlier band mapping still reads
under the current one. It renders as a sibling of the message body (not
inside it) so it stays visible even when the message is collapsed.

## Email triage

A one-line **summary** plus an **existing label** applied automatically,
generated by the `email_triage` pipeline job (see
`plugins/joinery_ai/docs/overview.md` § Registered jobs) — the inbox sorts
itself into the labels the mailbox owner already uses, with no new
vocabulary invented by the job. It shares its mailbox-selection config,
access check, and source digest (`EmailSecurityDigest::build()`) with the
`email_security_scan` job above; the two run as independent recipes with
their own `aip_recipe_item_log` rows, so either can run on a mailbox without
the other, or both together.

**`MailboxAliasConfig`** (`includes/MailboxAliasConfig.php`) is the shared
mailbox-alias config helper the AI pipeline jobs bind through: the option map
of enabled, store-capable mailbox addresses (`aliasOptions()`), address
resolution to an alias id (`resolveAliasId()` / `resolveActiveAliasId()`), the
`mailbox_aliases` checkbox-list descriptor field (`descriptorListField()`),
the normalized stored list (`listedAddresses()`), the live resolution of what
a recipe covers right now (`resolveBoundAliases()` — grant held, alias
enabled, domain enabled, re-checked on every read), and the owner-grant check
a recipe's `validateConfig()` runs per listed address at save time
(`validateOwnerGrant()`). It lives in this plugin, not `joinery_ai`, because
it is mailbox-domain knowledge — the dependency points this plugin →
`joinery_ai`, never the reverse.

**Verdict fields**, written only by the job's `recordVerdict()`:

- `iem_ai_summary` (`varchar(280)`) — the one AI-authored message field this
  job writes. Content in miniature, so it is a sealed field alongside the
  message body on a protected domain (see Encryption at rest, above); labels
  stay cleartext. The reader's inbox list shows it as the thread's preview
  line (italic, replacing the body snippet) once a message has been triaged;
  an untriaged thread still shows its body snippet.
- A label application via `InboundLabelMember::apply()` — an *existing*
  label only (`InboundEmailLabel::getByName()`); this job never creates one.
  A message with no fitting label gets a summary only.

`NULL` until a pipeline recipe triages the message; re-triaging after a
mis-label is an admin deleting the recipe's `aip_recipe_item_log` row for
that message, same as the security scan job.

Its sibling `email_schedule` job (same mailbox-selection config and
digest, its own `aip_recipe_item_log` row) reads for a real, dated event
instead of a label, and puts it on the recipe owner's calendar — see
`plugins/joinery_ai/docs/overview.md` § Calendar access. When the digest's
ATTACHMENTS section carries an ICS EVENT block (an invite attached to the
email), that job takes the invite's own title/start/end/timezone as
authoritative instead of inferring them from prose.

## Filters

Operator-defined rules that match incoming mail and apply actions to it
automatically — the inbound-email equivalent of Gmail's *Filters and Blocked
Addresses*. Managed under the **Filters** admin tab (between Accounts and Logs),
**one mailbox at a time**: a mailbox picker scopes the list, and *Create filter*
is pre-scoped to the picked mailbox. The picker also offers each domain's *All
mailboxes in `<domain>`* bucket for managing domain-wide rules. It lists **only
mailboxes where filters can actually fire** — those that store locally-received
mail (delivery mode store / forward-and-store, and not IMAP-backed); IMAP-polled
and pure-forward mailboxes are omitted because the filter hook never runs for them.

**Scope.** Filters run on **freshly-received** mail only — the Postfix milter path
and the provider-webhook path, both of which funnel through
`InboundEmailRouter::storeMessage()`. Because `storeMessage` is the single such
path, the ingest hook there covers Postfix and webhook identically with no per-path
branch.

Two ingest paths are exempt, for the same underlying reason: the mail did not just
arrive, so acting on it would fire forwards and notifications for messages nobody
received today.

- **IMAP-polled feeds** mirror an upstream account that already applies its own
  filters, and the reader's two-way sync treats the remote as the source of truth
  for flag/label state. They use `storeExtracted()`, which has no filter hook at
  all, so the exemption is structural.
- **Archive imports** pass `run_filters => false` to `storeMessage()`. An archive
  already reflects whatever filtering its source applied, and a decade of mail run
  through live rules would act on all of it at once.

**A filter has two parts** (Gmail's split):

- **Criteria** — From, To, Subject, *Has the words*, *Doesn't have*, Size
  (greater/less than a value + unit), and *Has attachment*. From/To accept
  comma-separated terms (any one matches); *Has the words* requires every word, and
  *Doesn't have* excludes any. A filter matches when **all** non-empty criteria
  match. At least one criterion is required.
- **Actions** — apply a label, star, mark read, *Skip the Inbox* (archive), mark as
  spam, never send to spam, forward to an address, delete.

**Scope of a rule.** A filter belongs to a mailbox, or to *all mailboxes in a
domain* (a domain-wide rule). A label is a custom label (an `ilb_inbound_email_labels`
row) with a single global namespace rather than belonging to one mailbox — every scope,
domain-wide rules included, can apply a label. The *Apply the label* dropdown lists
the existing labels and offers **Create new label…** to mint one inline.

**Engine.** The match and action logic lives on the `InboundEmailFilter` model.
`runForMessage()` loads every in-scope enabled filter (the mailbox's own plus the
domain-wide ones), runs `matches()`, accumulates the actions of all that match, and
applies them once in a fixed order so multi-filter interactions are well-defined:
*never-spam → mark-spam → label/star/read/archive → forward → delete*. An explicit
*never send to spam* always beats *mark as spam*. It runs at ingest **after** the
spam verdict is set, so a filter is the last word on disposition; it writes the state
columns and label memberships directly (system authority), reusing the same primitives
the reader uses. A forward action relays a copy through the same path alias-forwarding
uses; a delete soft-deletes the stored copy last, so a forwarded copy still went out.

**Archive ("Skip the Inbox").** The reader's default mailbox view is the **Inbox**
(non-archived, non-spam, non-deleted); an **All Mail** rail entry shows everything,
archived included. The open-thread toolbar offers **Archive** in the Inbox and **Move
to Inbox** in All Mail — the manual counterpart to the filter's archive action.

**Sent.** A **Sent** rail entry lists every conversation carrying an outbound row —
like Spam and Trash it reads a column (`iem_direction`), not folder membership, so it
works for local and IMAP mailboxes alike. Row-level filter, thread-level effect: the
thread's list row is its latest sent message and opening it shows the full history.
Trash still wins (a discarded sent message is in Trash and nowhere else), a search in
Sent is bounded to sent mail (an explicit scope), and spam reporting is not offered
there — a verdict on the member's own outbound mail means nothing.

**Timestamps coarsen with age.** Both places the reader prints a message time —
the list row and the open message header — run the same ladder: `just now` and
`N minutes ago` under an hour, `3:45 pm` under twelve hours, `3pm Jan 3` under
six months, `Jan 3, 2020` beyond. The hour and meridiem are composed rather than
delegated to `toLocaleTimeString`, which would render 24-hour under some locales
and disagree with the rung below it; the month name stays locale-aware
(specs/mailbox_timestamp_ladder.md).

**Sent and Drafts are ordered by time alone.** Every other list is sectioned —
unread first, then starred, then the rest — which answers *what still needs me?*
On mail the member sent or wrote there is no such question: an outbound row's
unread flag is whatever the source's `\Seen` said when it was pulled, or the
ingest default of false, and never something the member decided. So those two
views drop the sectioning and read strictly newest-first, the way every mail
client shows sent mail. The **mailbox unread badge** excludes outbound rows for
the same reason, and because the Inbox it lands on has never listed them
(specs/bugfix_sent_view_ordering.md).

**Apply to existing.** A filter saved with *Also apply to matching existing mail*
sets a pending flag drained by the `ApplyInboundEmailFilters` scheduled task, which
pages through that mailbox's locally-received, non-deleted history in bounded
batches and applies the same matcher and actions (forwarding is never re-applied to
historical mail), resuming across runs via a per-filter cursor.

**Logging.** Each ingest that matches at least one filter writes a `filtered` line to
the inbound transaction log (the **Logs** tab) recording the matched filter ids and
the actions taken.

**Importing from Gmail.** The Filters list has an **Import filters** button that
ingests Gmail's `mailFilters.xml` export (*Gmail → Settings → Filters and Blocked
Addresses → Export*) into the picked mailbox. The operator uploads the file and sees a
**preview** — one row per Gmail filter with its synthesized name, mapped criteria,
mapped actions, and a *Skipped* column for anything that has no platform equivalent —
then confirms to create the checked rows. An imported filter is an ordinary
`InboundEmailFilter`; import adds no new behavior.

- **Criteria** map directly: `from`, `to`, `subject`, `hasTheWord` → *Has the words*,
  `doesNotHaveTheWord` → *Doesn't have*, `hasAttachment`, and `size`.
- **Actions** map directly too: archive, mark-read, star, trash → delete, never-spam,
  and forward. Gmail's `label` action **find-or-creates a custom label** by name (a
  nested `Parent/Child` name is kept verbatim); new labels are created on confirm and
  their count is shown in the summary.
- **Skipped:** importance (`shouldAlwaysMarkAsImportant` / `shouldNeverMarkAsImportant`),
  categories (`smartLabelToApply`), and chat exclusion have no platform concept and are
  dropped visibly, listed per row in the preview.
- **The size default caveat:** Gmail emits a default `sizeOperator`/`sizeUnit` on every
  exported filter even when no size is set, so a size criterion is imported **only when
  a `size` value is actually present** — otherwise every filter would gain a bogus
  "size < 0 MB" rule.

An entry is importable only when it has at least one criterion **and** at least one
action (a label counts). Re-importing the same file is safe: a candidate whose criteria,
actions, and resolved label already exist in the scope is skipped and reported as
*already present*.

## Inbound Providers

Inbound mail is **provider-based** and composes with the platform's
outbound `EmailServiceProvider` model. A single provider class may
implement both `EmailServiceProvider` and `InboundEmailProvider`
interfaces — Mailgun is the canonical example.

```
includes/email_providers/MailgunProvider.php
    implements EmailServiceProvider, InboundEmailProvider

includes/email_providers/SmtpProvider.php
    implements EmailServiceProvider       (outbound only)

includes/email_providers/PostfixProvider.php
    implements InboundEmailProvider       (inbound only)
```

One inbound provider is active at a time, selected by the
`mailbox_provider` setting. All providers feed the same
`InboundEmailRouter::processEmail()`, so delivery modes, dedup, rate
limits, and the Mailbox tab all work identically regardless of which
front door let the message in.

### Shipping providers

- **PostfixProvider** (default, `postfix`) — local Postfix accepts mail
  via MX and pipes it to `utils/inbound_email_handler.php`. The Setup
  tab's Host / Mail-host / per-domain DNS checks come from this provider.
- **MailgunProvider** (`mailgun`) — Mailgun accepts mail via MX and
  POSTs to `ajax/inbound_email_webhook.php?provider=mailgun`. Reuses
  the outbound Mailgun settings (`mailgun_api_key`, `mailgun_domain`,
  `mailgun_eu_api_link`) and adds an inbound-only
  `mailgun_webhook_signing_key`. Configure a Mailgun route with
  `match_recipient(".*@your-domain")` → `forward("https://.../ajax/inbound_email_webhook?provider=mailgun")`,
  set to deliver `body-mime` (raw MIME).

### Adding a provider

Adding inbound support to an existing outbound provider is one diff to
one class — append `, InboundEmailProvider` to its `implements` clause
and add the interface methods (`getInboundSettingsFields()`,
`getSetupChecks()`, `getDnsRecords()`, `isWebhook()`, `handleInbound()`).

Adding a new HTTP-based provider is one new file in
`includes/email_providers/` implementing `InboundEmailProvider`.
`isWebhook()` returns true; `handleInbound()` verifies the request and
returns `['raw_mime' => ..., 'recipient' => ...]`. No router changes, no
Setup-tab changes, no new endpoints — the generic dispatcher handles
routing via `?provider=<key>`.

## Receiving by IMAP poll

Besides the push transports above (Postfix MX→pipe, Mailgun webhook), the platform
can receive mail by **polling an existing mailbox** over IMAP — Gmail, Microsoft
365, Yahoo, iCloud, Fastmail, or any IMAP host. Paired with the generic **SMTP**
outbound provider, this gives a complete **bring-your-own-mailbox** path (SMTP out
+ IMAP in, same account) with no self-hosted MX and no webhook service. It targets
the low-volume user who already has a mailbox and wants the platform to read it.

> **One account, both directions.** The same connected account also powers
> **outbound**: selecting the **Connected Email Account** provider sends all site
> mail through this account's SMTP (Gmail/M365 via XOAUTH2, app-password hosts via
> SMTP AUTH), reusing the same stored grant and `iia_needs_reauth` health flag — one
> Reconnect fixes both inbound and outbound. The connect flow requests both the IMAP
> read scope and the SMTP send scope, so connecting once enables both directions. See
> [Email System → Two send modes](../../../docs/email_system.md#two-send-modes--smtpconfig).

IMAP feeds are managed from the **Accounts** tree, attached to the mailbox they
fill. They are **additive**: any number run alongside whatever the system's single
push transport is, and adding one never changes that transport. Each feed binds to
an inbound **alias** (the mailbox it populates), so fetched mail lands in
`iem_inbound_email_messages` and appears in the **Mailbox Reader** like any other
stored mail, honoring the same grant model. **No MX/DNS is needed** for an
IMAP-sourced mailbox — the mail is already in the remote mailbox.

A polled mailbox is modeled as a normal `alias@domain`: the address you poll
*is* the mailbox. So a Gmail you read becomes the domain **`gmail.com`** with the
`ied_is_imap_source` flag set (Setup skips MX/DNS for it) and `me@gmail.com` as a
mailbox under it, fed by an IMAP feed. Multiple polled Gmail accounts sit as
sibling mailboxes under the one `gmail.com` domain.

### Per-host matrix — who needs OAuth vs. an app password

| Provider | IMAP host | Auth |
|----------|-----------|------|
| Gmail / Google Workspace | `imap.gmail.com:993` | app password (default) or OAuth2 |
| Microsoft 365 / Outlook.com | `outlook.office365.com:993` | **OAuth2** (basic auth retired) |
| Yahoo / AOL | `imap.mail.yahoo.com:993` | app password |
| iCloud | `imap.mail.me.com:993` | app-specific password |
| Fastmail | `imap.fastmail.com:993` | app password |
| Generic IMAP | user-supplied | password |

Connection details are **data, not code**: the `InboundImapAccount::PRESETS`
catalog is the single inventory of every supported host (host/port/encryption/auth
and, for OAuth hosts, the OAuth provider key). A row's `auth` is the **default**
sign-in — the easiest one that works for that host — and a row carrying an OAuth
provider key supports OAuth besides (`authMethodsFor()`); each account records
which method *it* signed in with (`iia_auth_method`, stamped by the credential
setters). Adding a host is a one-line edit there. Authentication is a single
branch in `ImapIngestor`: `password` LOGIN vs. `XOAUTH2` with a bearer token. The
IMAP library (`horde/imap_client`) is wrapped entirely behind `ImapIngestor`.

### OAuth accounts (Gmail / Microsoft)

OAuth accounts use the platform's [OAuth2 Core](/docs/oauth2.md) — the IMAP
transport is its first consumer (purpose `inbound_imap`). Register the Google/Azure
app **once** and paste its client id/secret on `/admin/admin_oauth_providers`; that
is documented in the [OAuth2 Core guide](/docs/oauth2.md) and not repeated here.
Then: on an IMAP-source domain, **+ Mailbox** → enter the address as the username →
save → click **Connect** on the mailbox row in the Accounts tree (on a hosted
domain it is **+ IMAP feed** on the mailbox instead). That begins a consent flow through the shared
`/oauth_callback`; on return, `InboundImapOAuthConsumer` stores the granted tokens
(encrypted) on the account. The poller keeps the access token fresh via
`OAuth2Client::ensureFresh`. IMAP-specific scopes requested at consent:

- **Google:** `https://mail.google.com/`
- **Microsoft:** `https://outlook.office365.com/IMAP.AccessAsUser.All offline_access`
  (`offline_access` is required for a refresh token).

The token grants full mailbox **read** access; secrets (IMAP passwords and OAuth
refresh tokens) are stored encrypted at rest with [`SecretBox`](/docs/secret_box.md)
and never logged or echoed.

**A grant is checked before it is stored.** Signing in and being allowed into the
mailbox are separate permissions on the consent screen, and a provider will grant
the first without the second — leaving a valid token that no IMAP session can log
in with. `InboundImapOAuthConsumer` therefore grades what came back
(`InboundImapAccount::missingMailScopes()`) and refuses a grant missing the mail
scope: nothing is stored, the feed keeps whatever state it had, and the operator
is told to allow email access on the way through. A provider that reports no scope
at all is not treated as having refused — only what the provider actually says is
acted on. A feed already holding such a grant reports it as
`mailAccessRefused()`, which the Setup tab names in its own right rather than as
an expired authorization.

### The poll cadence

The **PollImapAccounts** scheduled task is the heartbeat. It runs every cron pass
(`every_run`) as a **floor**; each account's own `iia_poll_interval_seconds`
(default 300) is the **actual cadence** — the task self-throttles per account, and
claims each account with an atomic stamp so two runs can't race the same cursor.
Two on-demand paths sit beside it, both running the same `ImapFetch::run` cycle:
the reader's Refresh button (`mailbox/check_mail`, the viewer's own feeds) and
the admin **Fetch now** row action. Every path funnels through the per-account
advisory fetch lock inside `ImapIngestor::poll()`, so concurrent fetches on one
account fail fast rather than racing the cursors. The on-demand paths run under
`ImapFetch::INTERACTIVE_BUDGET_SECONDS` (see the reader's Refresh, above); the
poller runs unbounded.
**Every cycle is timed.** The ingestor keeps a ledger of where the wall clock
went — connect, prepare, pull, each folder's seek / fetch / store, push — and
`ImapFetch::run` writes it into `iia_last_status` after the counts
(`… · took 4.2s: connect 0.5s, pull 1.1s, INBOX 2.1s, All Mail 1.8s, push 0.1s`),
so the last fetch of any feed says which seconds it spent where. The run record
carries the same ledger with per-folder detail, and a fetch that failed reports
how long it ran before it did.
**Feed health is announced, once.** Every fetch path reports its outcome to the
account (`InboundImapAccount::observeFetchOutcome()`), which keeps the last
announced state on the row (`iia_health_state`, `iia_consecutive_failures`,
`iia_broken_since`) and raises `mailbox.imap_feed_broken` /
`mailbox.imap_feed_recovered` on the signal bus **on transition only** — the
relay scanner's pattern. A refused credential (`iia_needs_reauth`: the token
refresh was refused, or the server rejected the login) breaks the feed at once,
since it never self-heals; any other failure breaks it after
`HEALTH_FAILURE_THRESHOLD` (3) polls in a row; the first successful fetch
recovers it. Both signals carry a `notify` block (in-app + email to admins),
saying what stopped arriving, why, since when, whether sending through the same
connected account is affected too, and what to press. A disabled feed takes
part in no transitions. The same state feeds the provisioning check
`InboundEmailHealth::checkImapFeeds()` ("Connected mailboxes are fetching"), so
a broken feed shows anywhere provisioning status is shown, and the reader's
"needs attention" banner and the Setup tab read the row as before.

**Reconnect** (the OAuth consumer) stores the fresh token, then runs the same
connection test the Accounts tab's Test button runs before it says Connected —
a token that still cannot open the mailbox is reported on the spot, with the
error, and the feed stays flagged. A denied or errored consent lands back on the
Accounts tab with the cause translated: for Google's `access_denied`, that the
account is not a test user on the OAuth app or the app sits in Testing mode with
an expired consent, and what to change in the Google console. A Google OAuth app
left in Testing mode expires its authorization every 7 days; publishing it to
production is the operational answer to weekly reconnects.

**Existing mail** is a per-mailbox choice (`iia_import_scope`) on the feed
editor — how far back into the source the feed reaches:
- **Future only** (default) — the cursor seeds to the folder's current high UID,
  so a 50 GB archive and an empty mailbox behave identically; only mail arriving
  after hookup is ingested.
- **Last N days** (`iia_import_days`, default 30, capped at `IMPORT_DAYS_MAX`) —
  the cursor seeds to the boundary between mail inside the window and mail older
  than it, and the window backfills oldest-first. The window is anchored when the
  feed starts reading, not rolling.
- **Full history** — the cursor starts at 0 and the whole mailbox backfills
  oldest-first.

**Finding the day boundary.** `ImapIngestor::seekCursorForCutoff()` first asks
the server outright — one `UID SEARCH SINCE` returns exactly the in-window UIDs,
so the cursor is one below their minimum. Not every server cooperates (Gmail
advertises ESEARCH yet rejects the `UID SEARCH RETURN (...)` form Horde emits),
so any refusal or unusable answer falls through to bisection: IMAP assigns UIDs
in strictly ascending arrival order (RFC 3501 §2.3.1.1), so the UID space is
sorted by INTERNALDATE and can be bisected. The bisection probes narrow UID
bands (`SEEK_BAND`) asking only for INTERNALDATE, on the same numeric `UID FETCH`
path the ingest window uses — bands rather than single UIDs because deletions
leave gaps a lone probe would land in. `SEEK_MAX_PROBES` bounds the seek; an
inconclusive one falls back to the best lower bound reached, importing somewhat
more than asked rather than silently importing nothing.

**The cursor decides where to look; the scope decides what to keep.** The seek
is fail-open, so on a sparse UID space (a decades-old Gmail folder is mostly
deleted UIDs) the cursor can land far below the true boundary. What keeps that
from filling the mailbox with out-of-window mail is a storage-time guard: during
a day-scoped backfill, a walked message whose INTERNALDATE predates the window
is skipped and counted (`out of scope` in the run record), with the cursor
advancing past it — a conservative cursor costs walk time, never scope. The
guard governs only the backfill: `iif_seed_high_uid` records the folder's high
UID at seed time, and above it no date filter applies, so a message the member
later moves into a tracked folder (a fresh, higher UID) is ingested whatever its
age. An unreadable INTERNALDATE counts as in-window — the same fail-toward-
keeping direction the seek uses (specs/imap_seed_scope_guard.md).

**A draft on the source is not mail.** A message carrying the `\Draft` flag is
skipped and counted (`source drafts` in the run record), in every folder. Not
tracking the Drafts folder is not enough on its own: Gmail files every draft in
`[Gmail]/All Mail` as well, and replaces it on each autosave — old UID expunged,
a fresh higher one appended — so a coverage pass meets each half-written revision
as new mail above its cursor. Left unfiltered, writing one email over half an
hour lands one incoming message from yourself per poll, each with its own
Message-ID, which no dedup can collapse. The flag travels with the message, so
asking it covers the Drafts folder, the coverage view and a label alike. An
unreadable flag list counts as ordinary mail — the same fail-toward-keeping
direction as the scope guard (specs/bugfix_imap_draft_ingest.md).

The choice is editable at any time. **Any** change to it — a different scope, or a
different day count — rewinds every folder cursor of that feed
(`InboundImapFolder::rewindCursors()`) so the next fetch re-seeds: widening
reaches further back, narrowing skips forward to the new boundary. The per-folder
`iif_` cursor is what the ingester reads, so rewinding the account-level one alone
would leave the feed positioned and skip the re-seed entirely. Dedup keeps a
re-walk from storing a second copy of mail already in the mailbox, and
already-imported mail is never removed by narrowing.

Whatever the scope, each fetch walks **one bounded UID window** (`(cursor+1):(cursor+max_per_account)`,
a numeric `UID FETCH` range — the walk never depends on `SEARCH`, since Gmail
rejects the form Horde emits; the boundary seek's `SEARCH` attempt is exactly
that, an attempt with a fetch-based fallback), so a backfill of a large mailbox
imports in batches across successive fetches rather than one enormous fetch. A UIDVALIDITY change re-seeds per the same
choice. Failures are per-account and non-fatal: one unreachable mailbox or expired
token never stops the rest, and the reason is recorded in the account's last status
(`iia_needs_reauth` is set when a token refresh/auth fails, surfacing a Reconnect).

### The run record

`iia_last_status` and the scheduled task's last-run message are both overwritten
every pass, and a full-history backfill is hundreds of passes — so neither can
answer *what did the import lose two hours ago*. Every poll that did something
therefore leaves a durable row in `evl_event_logs` under the event
**`mailbox_imap_ingest`**, holding the counts (`seen`, `stored`, `duplicates`,
`out of scope` — the day-window guard's bucket — `source drafts` — the bucket for
a message the source still holds as a draft — and `failed`) and each distinct
failure reason with the number of messages it hit. Fifty messages failing the
same way read as one line, not fifty.

Two things make an otherwise-silent loss visible:

- **`unaccounted`** — `seen` counts every UID the window walked. If
  `stored + duplicates + out of scope + source drafts + failed` does not
  reconcile against it,
  the shortfall is named in the note and the row is marked unsuccessful. This is
  the only signal for a message that disappeared without anything reporting a
  reason. A scope-guard skip and a source-draft skip are first-class buckets for
  exactly this reason: provable-on-purpose, never unaccounted.
- **A UID the server returned no data for** is a counted failure rather than a
  skip, so the reconciliation above stays honest.

The note's last line is the cycle's timing ledger (`took 4.2s: connect 0.5s,
pull 1.1s, INBOX 2.1s (seek 0.3s, fetch 1.2s, store 0.6s), …`): seek is the
STATUS and window walk, fetch the bodies and inline images, store the
transaction. A run that stored one message in two minutes says which two
minutes. Messages a deadline left unwalked are `deferred`, never `seen`, so the
reconciliation still balances.

An **idle poll writes nothing** — a mailbox polled every five minutes forever would
otherwise bury the runs that matter under thousands of no-op rows. A backfill leaves
one row per batch, which is the progress trail. The same summary goes to the error
log prefixed `mailbox_imap_ingest:`, followed by one line per failed message (UID,
folder, reason) capped at `MailRunRecord::MAX_LOGGED_FAILURES` so a wholesale folder
failure cannot flood the log. Writing the row is best-effort: if it fails, the poll
still succeeds and the mail is still stored.

A failed message leaves the folder cursor below it, so the next poll retries it —
a permanently-broken message therefore records a failure every pass until it is
dealt with.

**A message and its attachment manifest are one write.** Both go in a single
transaction, so a poll interrupted part-way through leaves nothing behind rather
than a message that lists no attachments. This matters beyond tidiness: the
cursor has not advanced past an uncommitted message, so the retry stores it
whole, and every other path can treat "the message is here" as "its attachments
are here too". A collision the *database* raises — as opposed to one caught by
the pre-validate check before any insert — surfaces as
`InboundStoreCollisionException` rather than a reported duplicate, because
Postgres has aborted the transaction and the caller cannot carry on inside it.
The message rolls back, the UID retries, and the retry resolves the same
collision cleanly.

### Proving where a day-windowed feed started reading

A feed set to **Last N days** seeks the oldest message still inside the window
and starts just below it. That decision is made once per folder and it decides
what the user will and will not receive, so each seek writes a row to
`isp_inbound_imap_seed_proofs`: the cutoff and high UID that went in, the cursor
that came out, which method answered (`isp_method` — `search` for a server-side
`UID SEARCH SINCE`, `bisect` for the band-probe bisection), how many probes it
took, whether it converged or ran out of budget, and two boundary probes.

- **below** — the newest message at or under the cursor. Its date should be
  *older* than the cutoff. If it is not, the seek started too high and skipped
  mail, and the row is written to the error log as well.
- **above** — the oldest message over the cursor, which should be *inside* the
  window. This measures tightness, not safety: over-importing is the fail-soft
  direction and costs only time.

The boundary check is exactly that — a check of the boundary the bisection chose,
not a statement about the whole region beneath it. INTERNALDATE does not
reliably rise with UID, because a message copied or imported into an account
gets a fresh high UID carrying whatever date it already had. Proving the region
needs every date below the cursor, which is what
`maintenance_scripts/dev_tools/imap_window_audit.php` does on demand:

```bash
php maintenance_scripts/dev_tools/imap_window_audit.php --account=12
```

It reports, per folder, every message the feed has read past that has no stored
row, and every message below the cursor whose date falls inside the window.
Soft-deleted rows count as present — a Trash arrival is stored as a deleted row.
Deliberately expensive; it is the instrument for verifying a feed, not something
a poll does.

### Reference-backed storage + the attachment list

IMAP-sourced messages are **reference-backed**, not copied whole. Unlike a pushed
delivery (which is gone after one delivery, so the full raw must be kept), an IMAP
mailbox is a durable remote store. So the poller stores only what the reader shows
— headers + the `text/plain`/`text/html` bodies + an attachment **manifest** — plus
a locator (account + UID + UIDVALIDITY + folder) back to the message, and leaves
`iem_raw_message` empty. A 50 GB Gmail costs the platform kilobytes per message.

Every message view shows a clickable **attachment list** (filename, size,
type), built from the `ima_inbound_message_attachments` manifest. For **IMAP
(`remote`) mail the bytes stay on the server** — clicking one fetches exactly that
MIME part on demand (`FETCH BODY[<section>]`, Message-ID fallback if UIDVALIDITY
changed), decodes it, and streams it pass-through with `Content-Disposition:
attachment` + `X-Content-Type-Options: nosniff`. For **push (Postfix/Mailgun) mail
the part is a private `File`** streamed the same way. Inline (`cid:`) parts belong to
the HTML body and are excluded from the list. If a part can't be retrieved (message
deleted/moved/account disabled), the endpoint says so honestly. The manifest +
endpoint + reader list are **transport-agnostic**: the download dispatches on where
the bytes live — a `File` for push mail, an IMAP fetch for `remote` mail, a raw
section for a legacy/fallback row (see **Attachment & message storage**) — through the
same endpoint, same table, same UI. The whole-message *.eml* is a separate
export, offered only where a stored original exists — see **The message kebab**.

**Deleting a feed with mirrored mail.** Because a `remote` message's attachments
live on the source account, removing the IMAP feed — or permanently deleting the
mailbox that owns it — presents a **keep/remove** choice rather than silently
stranding those rows (`admin_mailbox_imap_delete`). *Keep* materializes each
mirrored message into a self-contained local copy (fetch the full RFC822 while the
account is still connected, split attachments into private `File`s, drop the IMAP
locator) via `InboundEmailRouter::materializeRemoteMessage`, then removes the feed;
it requires the account be connectable, and refuses the delete if any message can't
be copied so nothing is lost. *Remove* permanent-deletes the mirrored rows (the mail
stays on the source server) and removes the feed. A feed with no reference-backed
mail deletes directly, no prompt.

### Setting up a Gmail account (end to end)

The live connect/fetch path is wrapped behind Horde; unit tests cover the platform
side (model + encryption, reference-backed store + dedup, manifest + grant parity,
poller summary). To connect a real Gmail account:

1. **Gmail prep.** In Gmail: Settings → Forwarding and POP/IMAP → **Enable IMAP** →
   Save.
2. **App password.** With 2-Step Verification on the Google account, create an app
   password at `myaccount.google.com/apppasswords` — the wizard's **How do I get
   this?** modal links every step.
3. **Connect.** **+ Connect a mailbox** on the Accounts tab → **Gmail / Google
   Workspace** → enter the address and the app password, choose the reader and the
   protection level → **Connect** → finish the configure step (folder, history,
   name).
4. **Verify.** Click **Test**, then **Fetch now**. The first fetch seeds the cursor to
   "now" and ingests nothing — send a **new** email to the Gmail afterward, **Fetch now**
   again, and confirm it appears under the mailbox in the **Mailboxes** reader; open it
   and download an attachment. For hands-off fetching, activate the **Fetch inbound IMAP
   mail** scheduled task.

**OAuth instead of an app password.** The signin step's **Other options** switches
to signing in at Google, which stores a token here rather than a password. That
path needs a one-time app registration, collected in place by the wizard's
register step: in Google Cloud's Auth Platform, create an OAuth client (**Web
application**), paste the callback URL the wizard shows under Authorized redirect
URIs, and copy the client ID + secret back. Two Google-side rules matter: while
the app's publishing status is **Testing**, only listed test users can consent
*and Google expires refresh tokens after 7 days* — publish the app to Production
for a durable connection. (No need to "enable the Gmail API" — IMAP uses
`imap.gmail.com` with XOAUTH2; the scope authorizes it.)

### Sync (read-only and two-way)

Each IMAP feed has a **Sync** mode, set per feed on the mailbox editor and **off by
default**:

- **Off** — one-time import. The source is never written to and local read/star/
  delete state stays in Joinery.
- **Read-only** — Joinery *follows* the source: a read/star/move/delete made in the
  native client is reflected in Joinery; Joinery never writes back.
- **Two-way** — full reconciliation: acting in either place is reflected in the other.

Read-only and Two-way require the server to advertise **CONDSTORE** (incremental
flag/membership pull via `CHANGEDSINCE`). Detecting messages that *left* a folder
uses **QRESYNC**'s `VANISHED` when the server also has it; on a CONDSTORE-only server
(notably **Gmail**, which has CONDSTORE but not QRESYNC) it falls back to diffing the
folder's current UID set against the stored membership UIDs — same result, a little
more bandwidth. A server without CONDSTORE offers only Off. Capabilities are detected
on connect/**Test** and cached on the feed. No OAuth re-consent is needed — the
granted IMAP scope already permits the `STORE`/`COPY`/`MOVE`/`APPEND`/`EXPUNGE`
writes. **Gmail is reconciled by the same folder model as every other host** — no
Gmail IMAP extensions are used.

**State mapped to IMAP.** Read ↔ `\Seen`, star ↔ `\Flagged`, custom label ↔ the
remote folder that mirrors it, deletion ↔ move to Trash. Standard state (read, star,
spam, archive, deletion) is a column on the message; only **custom** labels are
folder memberships.

**Custom labels are rows; folders are bindings.** A custom label is an
`ilb_inbound_email_labels` row, and a message *has* it iff it carries an
`ilm_inbound_label_members` row with `ilm_present_local` — the same truth for
locally-received and IMAP mail. An IMAP folder (`iif_inbound_imap_folders`) is a
**binding** that mirrors one label to a remote folder on one feed
(`iif_ilb_inbound_email_label_id`). Special-use folders (Inbox, Sent, Trash, Junk) and
the `\All` coverage view bind no label — their state is a message column, not a label.
The membership row is also the IMAP **shadow**: `ilm_present_base` records whether the
message was in the bound folder at the last sync, alongside the folder UID, so truth
and shadow share one row. Adding a label is a `COPY` (a Gmail label add) on a
multi-folder host or a `MOVE` on a classic one-folder host; removing is `STORE
\Deleted` + `EXPUNGE`; deleting is a `MOVE`/`COPY` to Trash. Operators pick which
folders are tracked on the mailbox editor; special-use folders are pre-selected.

**Changing labels from the reader.** The open-thread toolbar has a **Move to** (folder
icon, exclusive feeds) / **Labels** (tag icon, non-exclusive feeds, e.g. Gmail) control:
pick a folder to relocate the thread, or toggle label checkboxes. Each change applies or
removes the custom-label membership (`MailboxService::setMembership`, via the
`set_membership` action); when the label is bound to a Two-way feed the next sync
pushes the change to the source, and an unbound (local) label is pure membership that
never touches a remote.

**Creating a label/folder.** The same control has a **New label… / New folder…**
field. Creating one makes an `ilb_` label; on a mailbox with an IMAP feed it also makes
a tracked binding flagged `iif_pending_remote_create` (the remote folder does not
exist yet) and files the thread into it. The folder is materialized on the source
**during the sync push** — `ImapSyncer` issues the IMAP `CREATE`, clears the pending
flag, then `COPY`s the message in; pull/ingest skip a pending folder until it exists.
Creation is idempotent (a folder that already exists is adopted). Conversely, a label
created on the *source* is discovered each sync as an untracked folder — tick it on
the mailbox editor to start syncing it.

**The `\All` coverage view (Gmail All Mail).** An all-mail folder is tracked as a
**coverage source**, not a navigable label: it ingests every message — including
mail archived with no label — so nothing is missed, but it carries no label. It is
also where the source's own drafts surface, which is why the `\Draft` skip above is
folder-agnostic rather than a matter of leaving the Drafts folder untracked. In the
reader, the **mailbox root is the label-unfiltered “All Mail” view**, so messages with
no label are reachable there; the labels listed beneath it narrow to one label.

**Reconciliation.** Each cycle runs **Pull → Ingest → Push** on one connection.
Flags are a three-way merge keyed on a per-row dirty signal (a local change since the
last push wins over an incoming remote change). Custom-label membership is reconciled
through the single `ilm_` row: an element is dirty when `ilm_present_local` differs
from `ilm_present_base` — a column predicate a partial index covers, so the push scans
only the dirty rows. Each (message, label) bit is a conflict-free boolean merge; on a
one-folder host a divergent move converges to the local destination within two cycles
with no explicit tiebreak. A pushed change is re-read next cycle as a value-equal
no-op, so nothing loops.

**Deletion** (a separate **“Also sync deletions”** toggle): driven by the
`iem_delete_time` column, not a label. A local delete moves/copies the source message
to Trash (the locator follows, so it is never re-pushed); a message arriving in Trash
on the source soft-deletes the local row at ingest. Archiving (the `iem_is_archived`
column) is distinct from deletion and stays local.

**Compose / Sent** (the **“Enable compose / Sent sync”** toggle, on by default in the
connect wizard whenever sync is on, with the reader’s reply/forward feature): the
source Sent folder is ingested like any tracked folder,
so mail sent from the native client appears in Joinery. When a feed’s SMTP does not
auto-file sent mail (self-hosted / generic), Joinery `APPEND`s the sent copy to the
source Sent folder itself. Sent dedup is **by Message-ID only**: a compose send always
stores its own outbound row, carrying the Message-ID the message was sent with, and
the filed copy reconciles to that row on the next Sent ingest — adopting its IMAP
locator rather than inserting a second row.

A message filed in the source Sent folder reads as **sent mail** (`iem_direction =
'outbound'`) whichever folder stored it first: when the `\All` coverage pass wins the
race and stores it as an ordinary row, the Sent pass's dedup promotes that same row.
A **self-addressed** message is the exception — it stays inbound and shows in the
Inbox, exactly as the source mailbox files it. (A self-send this deployment
composed has no such row to promote: it reconciled onto the composer's copy at
ingest.) The promotion only ever lifts an
inbound row (outbound and draft rows are never touched) and stands down when a live
outbound row already holds the same `(Message-ID, recipient)` dedup key.

## Provisioning a mailbox in one call

`plugins/mailbox/includes/provisioning.php` exposes the headless provisioning
functions — register a domain, create a store-mode mailbox on it, and grant it
to a user, with no page or session plumbing:

- `mailbox_provision_domain($domain_name)` — ensures the domain row exists
  (files fleet domain claims on creation, exactly like the Setup tab's
  add-domain action).
- `mailbox_provision_mailbox($domain_name, $local_part, $user_id)` — domain +
  store alias + access grant in one call.

Both are **idempotent**: what already exists is reused, grants are added by
union (never overwritten), and re-running after a partial failure finishes the
rest. The protected-domain grant invariant is enforced at the mutation point;
the vault-unlock gate on routing changes does not apply because these only
create — they never edit an existing alias's destinations or mode. Callers own
authorization (every current caller sits behind `check_permission(5)` or
higher). The setup wizard's one-go apply (`wizard_provision` in
`admin_mailbox_setup_logic`) is the primary consumer.

### Preparing a domain for a management node

`plugins/mailbox/utils/managed_domain_prepare.php <domain>` is the CLI form of
the same idea, run **on this box** by a management node that just registered a
domain on the owner's behalf
([Server Manager § Managed Domain Registration](../../server_manager/docs/overview.md)).
It calls `mailbox_provision_domain()`, makes sure a DKIM signing key exists
(`provision_dkim.sh`, which never regenerates an existing key), and prints one
JSON line:

```json
{"ok":true,"dkim_ready":true,"records":[{"type","name","value","priority","note"}]}
```

The records come from `InboundEmailSetupCheck::dnsPlan()` — the same desired
state the Setup tab prescribes. That is the point of running it here rather
than computing the records remotely: this box is what knows its receive
topology, its SPF shape, its signing key and whether it speaks Joinery Direct,
and a record set computed anywhere else would be plausible and wrong. The
management node publishes what it gets; `dkim_ready: false` means the set is
usable but unfinished, so it publishes and comes back for the signing key.

Removals are omitted — a freshly registered domain has nothing to take away,
and an instruction about somebody else's record is not a new domain's business.

## Importing an existing archive

An IMAP feed pulls from a **live** account. An archive import reads a **dead** one:
a file the user already has — a Proton export, a Gmail Takeout, an mbox from
Thunderbird, a folder of saved messages. Between the two there is a way in from any
provider, including the ones that no longer exist.

The unit of work is an **import run**: pick a source file, say which mailbox it goes
into and which addresses were yours, choose what to bring, and let it grind. Runs are
resumable, reportable and reversible.

### Formats

| Format | Covers |
|---|---|
| mbox | Gmail Takeout, Thunderbird, Apple Mail |
| `.eml` / `.emlx` folder | Proton export, maildir, Apple Mail `Messages/`, any folder of saved mail |
| single `.eml` | one message |
| `.zip` | any of the above, zipped — which is how a folder actually arrives |
| `.tar`, `.tar.gz` | any of the above, from a Unix-side export |

`.pst` and `.olm` are **refused**, by magic bytes as well as by extension, so a
renamed one is still caught. Reading them needs an external binary, which would break
the zero-config install. The refusal names the way that does work: connect the account
as an IMAP feed, which also keeps working as new mail arrives.

**One archive is one run.** An export delivered as several parts is imported a
part at a time, which works as long as each part is self-contained. A part whose
mbox member was cut mid-message is not. The cut costs one message, silently, in
two halves: the earlier part still holds the message's separator and headers, so
it imports **truncated** — stored under its real Message-ID with however much
body the cut left it — and the later part's leading orphan bytes are **dropped**,
because `MboxSplitter` only starts a message at a separator. Nothing reports
either half, and the truncated copy is the worse one: it looks imported. Before
importing a multi-part export, check which shape it is:

```bash
php maintenance_scripts/dev_tools/takeout_parts_probe.php /path/to/export/
```

It lists every member with its uncompressed size, names any member appearing in
more than one part (those overwrite each other on extraction), and says for each
mbox whether it begins at a message boundary or part-way through one. Zips are
read from the central directory plus a 4 KB prefix; a `.tgz` gets one streaming
pass over the tar headers; a bare `.mbox` beside the parts is read directly.
Nothing is extracted, so it is safe to point at an export wherever it sits. It
exits `2` if it finds a cut. Reassembling a cut mbox is not built.

Saved messages inside a zip are read **in place** through `zip://` — a 50GB archive of
small messages is never expanded, which would otherwise double the disk this feature
needs. An mbox member is the exception: splitting one means seeking inside it and zip
streams cannot seek, so an mbox member is expanded into the run's working area once. A
tar is sequential-access only and is expanded whole. Working areas are removed when the
run finishes.

A container inside a container is **reported, not followed** — that way lies a zip
bomb, and no real export tool produces one.

Provider conventions are read where present and cost nothing where absent: Proton's
`<id>.metadata.json` sidecars, Gmail's `X-Gmail-Labels` header (where read state is
the *presence* of `Unread`), and maildir's `:2,` filename flags. A bare folder of
`.eml` files with none of these still imports correctly, just with less state.

#### Where a message lives, and what is merely laid over it

A message has one **location** and any number of **labels**, and an export gives both
in the same list. Telling them apart is what decides the whole import: a view like
Proton's *All Mail* sits on every message in the account, so reading one as a folder
files an entire archive under a single heading and loses the real one.

`MailArchiveReader::PROTON_LOCATIONS` is therefore an allow-list of the ids that name
a place — Inbox, Trash, Spam, Archive, Sent, Drafts, Outbox. Everything else numeric
is a view (*All Mail*, *Almost All Mail*, *All Sent*, *All Drafts*, *All Scheduled*,
*Snoozed*, the inbox category tabs) and is dropped. Dropping is deliberate on both
counts: a view is not a place, and an unrecognised number used as a name is how an
import ends up tagging thousands of messages with a label called `15`. Where two
locations somehow appear together, `PROTON_LOCATION_PRIORITY` decides — thrown away
beats filed away, so Trash and Spam win and the message stays out of an import that
did not ask for them.

The export's `labels.json` names every custom folder and label, and its `Type` field
says which is which — `3` for a folder, anything else for a label. A custom folder is
a location in its own right, so a message in one lands there rather than nowhere. A
custom label is applied as a label and leaves the location alone. Without the
manifest, custom entries are treated as labels: the name survives, the placement does
not, and a bare folder of `.eml` files still imports.

Formats that carry no folder information of their own — a lone mbox, a single `.eml`
— name the folder after the archive itself. That name is the one the **person typed**
(`mir_source_name`), never the path on disk: the file store appends a uniquifier to
keep names from colliding, and importing `Receipts.mbox` must not produce a folder
called `Receipts a7f3k2q1`.

### The two sources

Upload an archive, or pick a file already in your files. There is no server-path
option — pointing at a folder on the machine is not something a member can do, and an
uploaded archive becomes the user's own file the moment it lands, so an interrupted
run resumes against it rather than needing a re-upload.

**Uploading has no size limit.** The archive goes up through the platform's
resumable chunk transport under the `mail_import_archive` upload purpose
(docs/api.md § Uploading something that is not a Drive file), so the bytes never
ride in a single request and `upload_max_filesize` never applies. A dropped
connection resumes from the server's byte count rather than starting the archive
again, which on a large export is the difference between a hiccup and an afternoon.

A file in an **encrypted** folder is listed but refused, with the reason. Drive
encryption is per-folder and inherited, and an encrypted file's plaintext exists only
in the browser — the server genuinely cannot read it. It is shown rather than hidden
because a user who cannot find their archive is worse off than one told why.

### One at a time

A person may have **one import going at a time**. While they do, the start form is not
on the page: in its place is a line naming the archive that holds the slot and what it
is doing. The form returns by itself when that run finishes — the page is already
polling, so no reload is needed.

A run counts as going in every state except `done`, `failed` and `undone`. That
includes `scanned`, where nothing is moving because the run has stopped to ask which
folders to bring: it resumes on the answer, so it holds the slot until it gets one.

The rule is scoped to **runs the caller started**, not to the mailbox. An operator
setting up somebody else's mailbox is still the person doing the importing, and two
grantees of one shared mailbox are two people.

`MailImportService::activeRun()` is the single answer to *is this person busy*. The
page render reads it, `mailbox/mail_import_status` returns it as `busy_run` so the
poller can flip the form back without re-deriving anything, and
`mailbox/mail_import_start` refuses on it — so a second browser tab cannot queue what
the first is already carrying.

### Where the form picks up from

Importing is rarely one archive. A Takeout arrives split across several files and a
provider migration means the same mailbox and the same address list over and over, so
the form opens on the **last run's answers**: the mailbox that run targeted, and the
addresses declared for it. An explicit `?alias_id` still wins, a mailbox the caller no
longer holds is not re-offered, and asking about a different mailbox falls back to the
suggestion for that one rather than carrying across a list written for another.

### Declaring your addresses

An archive carries no envelope. Without knowing which addresses were the user's, there
is no way to tell sent mail from received, and no way to know which of several
addresses a message actually reached. So the run asks — pre-filled from the last import
when there was one, otherwise from the account — and derives two things from the answer:

- **Direction** — mail from one of those addresses is mail you sent. The source's own
  filing outranks the headers: a message sitting in Sent was sent, even if its From is
  an address the user forgot to declare.
- **Delivery address** — the first of `Delivered-To`, `X-Original-To`, `Envelope-To`,
  then `To`/`Cc`, that names a declared address. Nothing matching falls back to the
  target mailbox's own address, which is the honest answer for a Bcc.

Sent mail records its first `To`, matching how mail sent from the reader is stored.

### Scan, then choose

The scan walks the source **once** and writes one `mie_mail_import_entries` row per
message. It stores no mail. That index is what makes any size work: nothing ever
re-parses the archive to find out what is in it, the preview counts are exact rather
than estimated, and resume is a `WHERE mie_state = 'pending'` query. A 500,000-message
archive means 500,000 narrow rows, which is unremarkable for Postgres and bought
cheaply — the scan writes them in bulk rather than one model save at a time.

On completion the run holds at `scanned` and the user picks folders, with **Spam and
Trash unticked**: an archive's spam folder is usually the largest thing in it and
almost never what anyone meant to keep. Anything left out is marked `skipped` rather
than deleted, so the final reconciliation can still account for every message found.

### Storing

Batches of entries, oldest first. Almost none of this is new code — live delivery
already parses bodies, splits attachments into private Files, computes thread keys,
seals content to the owner's vault, and treats a unique violation as a successful
dedup — so the importer points the existing store path at a different source of bytes.

Two properties of the schema carry the design:

**Dedup is free and correct.** Re-running an import over the same archive stores
nothing new, so resume-after-a-crash costs at most one batch, retry is safe, and "did I
already do this" needs no bookkeeping. The importer asks whether *this mailbox already
holds this message id in this direction*, which is stronger than the unique constraint
alone: on a protected mailbox a sent message's recipient is sealed content and cannot
be matched on a second pass.

**Filing and delivery address are independent.** `iem_iea_inbound_email_alias_id`
decides which mailbox the message appears in; `iem_recipient` records where it was
delivered. Mail can be gathered into one mailbox while each message still says
honestly which address received it.

Messages with no `Message-ID` get a stable synthetic one, `<sha256(raw)@import.invalid>`,
written into the stored copy so the row and its raw agree. It is derived from the bytes,
so the same message scanned twice produces the same id and still dedups; `.invalid`
(RFC 2606) can never collide with a real domain.

Imported mail carries its own `Date` header as its received time, so a decade of mail
sorts where it belongs instead of landing all at once at the import's clock. A folder
that is not one of the platform's own buckets becomes a label of the same name; the
standard buckets (Inbox, Sent, Spam, Trash, Starred, Archived) are columns on the
message and are handled by the store. Trash arrives soft-deleted, which is how the
platform models a bin.

Imported mail carries **no authentication verdict** — `unverified` across the board.
The stamps in an archived message were written by whichever server received it years
ago, and this deployment cannot vouch for them. The reader says so in as many words
("Sender not checked — imported from a mail archive, so it never arrived here to be
checked"), because on a deployment that has imported an archive this is the majority
of stored mail and reads as alarming otherwise.

### Attachments and where the bytes go

Every non-text part is split out into its own private `File` and linked from the
message's attachment manifest, exactly as live delivery does — the importer inherits
this by going through the same store path. Unlike an IMAP feed, which is
reference-backed and fetches parts from the source on demand, an imported message is
**self-contained**: the archive is the only copy of those bytes, so they are stored.
A large Gmail archive is therefore a real disk commitment.

A message the mailbox **already holds as references** — ingested over IMAP — gets its
attachment bytes too. The import still counts as a deduplication (no second copy of
the message, no run tag, so undo still cannot remove mail the run did not create),
but the bytes it was holding are kept rather than dropped. On a protected mailbox
they land sealed, which works even though an import runs in the background with
nobody signed in: sealing needs only the vault's public key. See **Attachment &
message storage** for the matching rules and what is deliberately never upgraded.

Those Files are tagged `email_attachment` (`fil_source`), which is what keeps them
out of the member's Drive listing and away from their Drive quota — an attachment is
not something the user filed in their Drive, and a thirty-thousand-message import
must not silently fill it.

The uploaded archive itself is tagged `mail_import_archive`: also not a Drive item,
because it is working material for one run rather than a file the member is keeping.
The file picker therefore offers Drive items **and** previously-uploaded archives,
which is what lets an interrupted run restart against the same file instead of
re-uploading gigabytes.

Undo reclaims all of it — attachment Files, manifest rows and any stored raw object
go with the message, through the model's own permanent delete.

### Any size

Both phases run in the **`RunMailImports`** scheduled task, because a 50GB scan cannot
happen inside a web request. Each pass claims one run with an atomic conditional
`UPDATE` — the same overlap guard `PollImapAccounts` uses — does **one bounded batch**,
and returns. A claim goes stale after 30 minutes, which is how a run whose pass was
killed gets picked up again rather than sitting claimed forever.

Scanning gets a time budget rather than a message count (it reads sequentially and
writes narrow rows), and hands back an opaque cursor the reader understands: a byte
offset for an mbox, a member index for a container. Storing gets
`mailbox_import_batch_size` entries. `mailbox_import_max_concurrent` caps how many runs
are underway deployment-wide so one enthusiastic user cannot starve the mail stack.

Progress is `mir_processed` against `mir_total_entries`, advanced by the importer with
one atomic `UPDATE` per batch. Every write to a live run is a targeted column update
rather than a model save, because the counters move underneath any model instance held
for more than an instant.

**Every entry is time-boxed.** `importBatch()` arms a SIGALRM watchdog
(`MailArchiveImporter::ENTRY_TIMEOUT_SECONDS`, 300s) around each entry, so a message
that sends a parser into a loop becomes one failed entry with a reason rather than a
cron worker pinned at 100% CPU holding the task's "already running" lock. The guard is
CLI-only by nature (pcntl does not exist under FPM), which matches where batches run.
Raw MIME parsing itself goes through core `MimeParse::parseMessage()`, which refuses
the one input shape known to hang the Horde parser — a declared boundary quoted
mid-line in the body — with `MimeParseHazardException`; the body walk then falls back
to the legacy splitter and the message still imports.

**The page shows when the next batch lands.** The import panel's poll carries
`next_batch` from `MailImportService::nextBatch()`: a countdown to the runner's next
pass (from the cron runner's own measured tick spacing,
`scheduled_tasks_cron_observed_interval`, plus its heartbeat) and how many messages
that pass takes (`mailbox_import_batch_size`, capped by what remains). When the
import task has been silent far longer than its cadence while a run is active, the
page reports the worker as stalled instead of counting down to a batch that will not
come.

### The run record

Per-entry failures are recorded on the entry with a reason and never abort the run —
one unreadable message must not cost the other thirty-five thousand.

Each batch that did something writes an `evl_event_logs` row under event
**`mail_archive_import`**, with failures rolled up by reason so four hundred messages
failing identically read as one line, plus bounded per-entry detail in the error log.
This is the same `MailRunRecord` machinery the IMAP run record uses, with one extra
bucket for what the user chose to leave out.

The reconciliation tripwire applies here too: `stored + duplicates + skipped + failed`
must equal what was seen. A shortfall is reported as `unaccounted` and marks the batch
unsuccessful, because a message that vanishes without a reason is exactly the failure a
set of counters alone hides.

**Every duplicate names what it duplicated.** A duplicate is not one outcome, and
the differences decide whether the message is actually in the mailbox:

| Reason | What it means |
| --- | --- |
| `Already in this mailbox` | The ordinary case. The entry carries the message id, and `— attachment bytes taken.` when the archive copy handed its bytes over. |
| `Stored by another process during this run` | The copy arrived between this entry's own check and its insert — a poll running alongside the import. Still here. |
| `Already stored on this site, in another mailbox` | The site-wide unique key collided with a row belonging to a **different** alias. This is the one that can mean the mailbox does not hold it. |
| `Already stored on this site — the colliding copy could not be identified` | The collision is real but the row cannot be resolved by lookup, which is what a sealed recipient looks like. |
| `The stored copy lists no attachments` | The stored copy has an empty manifest while the archive copy carries parts. The two disagree about what the message is. |

The last three are the report's **suspicious buckets**, defined once in
`MailImportEntry::SUSPICIOUS_REASONS` and matched by prefix so a reason can carry
the colliding id in its tail. A finished run holding any of them says so on its
row in the run list; the detail belongs to the reconciliation below.

### Proving nothing was lost

The counters above are self-consistent, but their denominator is the importer's
own scan — a message its reader never emitted is missing from all of them. The
denominator therefore comes from outside, and comparison is a two-step:

```bash
python3 maintenance_scripts/dev_tools/mail_archive_inventory.py archive.mbox -o inventory.jsonl
php maintenance_scripts/dev_tools/reconcile_mail_import.php --run=42 --inventory=inventory.jsonl
```

The inventory is Python because it must **not** be this codebase: it finds
message boundaries with the stdlib `mailbox` module and parses MIME with
`email`, so a bug in `MboxSplitter` or in Horde cannot hide from the report meant
to catch it. A disagreement between the two is itself a finding.

The reconciliation prints identifiers, never bare counts — a count comparison
passes whenever two errors cancel. It checks four things:

- **By Message-ID** — source ids with no row in the target mailbox. Soft-deleted
  rows count as present, since mail the source had in its bin is correctly
  stored as a deleted row.
- **By byte offset** — a message with no Message-ID is stored under a synthesized
  id derived from its bytes, which the inventory cannot reproduce, so those are
  matched by position instead: the inventory records each message's body offset
  and mbox locators are `offset:length` against the same file. This also
  localises exactly where the two splitters diverged. Offsets are unique only
  *within* a file, so an archive holding several mboxes needs `--member=NAME` to
  say which one the inventory covers; without it the comparison is skipped and
  reported as skipped rather than guessed at.
- **By attachment count** — per message present on both sides, the source's part
  count against the stored manifest's rows. Catches a dropped attachment on a
  message that is otherwise fine. Count, not filename: the two sides name and
  de-duplicate parts differently.
- **By ledger reason** — the suspicious buckets above.

It exits `0` when nothing is outstanding and `2` when there are findings, and
writes each full list to a file beside the inventory.

### Room to put it

An import writes considerably more than it reads, so free disk space is checked
against the size of the archive before a run is accepted, and again before every
batch while it works.

The estimate is **twice the archive**, and the reason it is not "the same size" is
that an archive is unpacked into more places than it came from: the RFC822 raw of
every message lands under `{site_root}/storage/`, every attachment is extracted
into its own File, and the message rows carry the headers and both body columns.
Attachments are therefore held twice over — once inside the raw, once on their
own — which alone takes a Gmail Takeout to roughly one and a half times its size
before the body columns are counted. `MailArchiveImporter::estimatedStorageBytes()`
owns that ratio; `DiskSpace` only ever answers whether a given number of bytes
fits.

Raw messages and attachments can sit on different filesystems, so both are
measured and the tighter one decides. A **1 GiB reserve** is held back on top of
whatever the job needs, because a disk with nothing spare stops Postgres
journalling and stops the error log recording why anything failed.

`MailImportService::startRun()` refuses an archive that will not fit, naming what
is needed, what is free, and how much to clear — a person is present at that
moment and can act on it. `RunMailImports` asks again each pass, because a run
lasting hours shares its machine with everything else: when a batch will not fit,
the run **holds** rather than fails. Its state, its pending entries and its stored
mail are untouched, the reason is written to the run, and the next pass continues
by itself once there is room. The task reports the hold as an error even though
the run is healthy — the machine is not, and a stalled import nobody was told
about is the outcome worth avoiding.

Where free space cannot be measured at all, the job is allowed. The check is a
safety net over the disks it can see, not an entitlement gate that a host hiding
`disk_free_space` could turn into a permanent refusal.

### What happens to the archive afterwards

The uploaded archive is **kept for a grace period after the run finishes**, not
deleted on completion. That is deliberate: undoing an import and running it again
is a normal thing to do — a reader improves, a folder turns out to have been
missed — and it needs the same bytes. Deleting them the moment a run completes
would be tidier and would quietly remove that possibility.

`PurgeMailImportArchives` (daily) collects archives whose run finished more than
`mailbox_import_archive_retention_days` ago, and a *Discard archive* button on any
finished run reclaims it immediately when the user knows they are done.

**An archive picked from the user's own Drive is released, never deleted.** It is
their file, it counts against their Drive quota, and they may well want it
afterwards — the importer only reclaims what the importer created, which is what
the `mail_import_archive` origin tag is for.

The run itself always survives losing its archive: the record of what was imported
outlives the file it came from.

Working directories (used only by formats that cannot be read in place — a zip
holding an mbox, a tar) are removed when a run finishes. The same task sweeps any
left by a run that ended some other way, which nothing else would collect.

### Undo

Available on a finished run. It permanently deletes every message carrying that run's
id — through the message model's own permanent delete, so attachment Files, manifest
rows, label memberships and stored raw objects all go with it — and removes labels the
import created that are now empty.

Mail that **deduped** against something already present was never tagged, so undo
cannot remove mail the import did not create; neither can it touch anything that
arrived afterwards. Labels that existed beforehand, or that still hold mail from
elsewhere, are left alone: undo reverses the import, not the user's filing.

The run itself moves to `undone` and keeps its entries, so the report of what happened
outlives the reversal.

### Surfaces

**Member** — *Import old mail* at `/profile/mailbox/import`.
**Admin** — the same tool in the Accounts tree beside IMAP feeds, at
`/plugins/mailbox/admin/admin_mailbox_import`, with a mailbox picker covering every
mailbox.

Both render the same panel and call the same logic; the only difference is which
mailboxes are offered, decided by `MailImportService`. A member must hold a live grant
on the target mailbox; permission 5+ may target any. That check lives in the service,
not in a view, so calling the API directly cannot bypass it.

Actions are `mailbox/mail_import_start`, `mail_import_status`, `mail_import_select` and
`mail_import_undo` on `/api/v1`, with the browser-session credential. The start action
accepts `multipart/form-data` so an archive uploads in the same request that starts the
run.

### Settings

| Setting | Default | Purpose |
|---|---|---|
| `mailbox_import_enabled` | on | Master switch. Turning it off also stops runs already underway from advancing. |
| `mailbox_import_batch_size` | 1000 | Entries stored per task pass. Measured cost is roughly 150ms per message, so this is also how long a pass holds the cron runner. Below a few hundred, a large import spends most of its elapsed time waiting for the next cron tick rather than working. |
| `mailbox_import_max_concurrent` | 2 | Runs importing at once, deployment-wide. |
| `mailbox_import_archive_retention_days` | 7 | How long a finished run keeps its uploaded archive, so it can be undone and re-run. |

**Both tasks must be active** for imports to work: `RunMailImports` performs them
and `PurgeMailImportArchives` reclaims the archives afterwards. A task is
discovered from its manifest but activated in Scheduled Tasks — until then a run
sits at *Waiting to start*, which the import surface says plainly rather than
leaving it looking broken.

### Adding a format

One class extending `MailArchiveReader` (or `MailArchiveTreeReader`, if the format is a
tree of members) and one line in `MailArchiveReaderRegistry::READERS`. Order in that
list is priority: readers are asked in turn and the first to claim a file wins, so the
list runs from the most specific sniff to the loosest. A reader answers three
questions and no others — is this file mine, what messages are in it, and give me the
bytes at this position. The locator it hands out is private to it; nothing else ever
interprets one.
