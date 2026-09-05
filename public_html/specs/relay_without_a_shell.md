# Relay Without a Shell

**Status: DRAFT 2026-09-05, revised the same day after a review against the
running system, then reviewed by a second session (fifteen findings, all
folded in below). Q1, Q2 and Q3 decided.**

**Build status.** WP1 built 2026-09-05: `relay-serve` and the root-side verbs in
the sealer, `RelayProtocol.php`, the wire gate extended to both relay envelopes,
`provision_relay.sh` 3.0, the first-boot template `relay_first_boot.sh`, and the
support bundle carrying the relay build. Proven on dev against the Go listener
with PHP-signed requests and a pinned curl (ping, fragment merge, seal pull, ack,
refusals). The hand-run proof ran 2026-09-05 on 45.33.67.199 (not 45.33.72.32,
which is the live Drive soak VPS): build from the bundle with `--keep-sshd`,
then from dev over the pinned API a ping with the collector's facts, a fragment
merged by the root path unit, and a spool listing. The birth report reached dev
and was refused by API-key authentication, because `relay/bundle` and
`relay/born` were WP2 at the time.

WP2 built 2026-09-05: `RelayClientIdentity` (client and operator kinds),
`RelayClient` with classed failures, the consumers behind the per-row switch on
`mrl_identity_fingerprint` (spool pull, map push, health, Direct egress),
`checkRelayReachable`, `RelayBirthEndpoint` (`relay/bundle`, `relay/born`,
dispatched before key auth), `RelayCloudProvisioner::completeBirth` and
`registerBornRelay`, the run token and bundle copy on the run row, the Linode
driver's `user_data`, `regionSupportsMetadata` and StackScript fallback,
`RelayFirstBoot` rendering both forms, and fleet enrollment by public key with
the tenant routes behind `FleetService::applyTenant`. Tests drive every consumer
and the birth channel against the real relay binary.

WP3 built 2026-09-05: `RelayCloudProvisioner` 2.1 without the SSH leg - `ready`
creates with user-data (Metadata, or the plane's StackScript), `booting` polls,
`provisioning` waits for the birth report with a birth timeout that destroys the
instance, update drains over the API then re-images the same instance with
fresh user-data; `completeBirth` attaches a `ManagedNode` in the disposable
posture when Server Manager is active, whose node card points at Update and
Delete on the Setup tab; the Delete confirm names the machine and the MX; with
no relay after a recorded cutover, `checkInboundMailServer` and the Relay
section say the MX points at a relay this deployment no longer has and offer
both ways out; the Setup tab no longer requires the main box's tunnel identity.
Node 1800 on dev is replaced in WP5. WP4 (deletions) and WP5 (live gate) not
started.
The Q1 consequence for `OutboundTransport` (the smarthost branch giving way to
the origin-leak probe verdict) lands with WP4's deletions. Two details
differ from the text below and the text is not yet updated: the bundle names
the binaries `bin/relay-sealer-{x86_64,aarch64}` (the `uname -m` spelling
`provision_relay.sh` selects on), and the ping has no `held` count in its spool
group (the relay cannot know what the plane is holding) and reports the units
that exist (`joinery-relay-serve`, `joinery-relay-apply.path`,
`joinery-relay-collect.timer`) rather than a `joinery-sealer` service, which is
a pipe.

**Owner direction (2026-09-05):** relay provisioning goes through Server
Manager; the relay stays disposable (no editing, no maintenance); every
operation is a named one with fixed parameters, in the spirit of the agent
primitives; SSH is eliminated to the greatest extent possible without
compromising security; no provider-specific process is added. The standing
rule that the relay never runs the Go agent is unchanged.

This relay has been rebuilt several times and each attempt left confusion
behind. This spec therefore settles the contract down to the wire before any
code is written, and names the order the pieces land in.

## What this spec ends with

A relay is a machine with two listeners, Postfix on 25 and one Go binary on
443, and no other way in. It has no sshd, no WireGuard, no Unix accounts for
tenants, no sudoers rules, and no platform door: the platform holds no
credential for it and records no root password. (The account holder can still
reset the root password at the provider and use its console; that is their
act on their machine, not a door the platform has.) It is born configured by its provider's first-boot mechanism,
tells its plane it exists over HTTPS, and is then reached only through an
HTTPS API that it serves itself. Its whole vocabulary is two operations,
both the provider's act, never the relay's:

| Operation | What it is |
|---|---|
| create | provider creates the instance with first-boot user-data; the relay builds itself and reports in |
| update | drain the spool through the API until it is empty, then the provider re-images the same instance and the relay is born again from the current bundle; the address is kept. This is how new code reaches a relay, and it is also the wipe |

Destroying the machine is the owner's act at the provider, by hand, never the
platform's (Q3, which keeps the July decision). The platform's **Delete**
removes the relay's row and says the machine keeps running and billing until
it is deleted at the provider. What the platform must guarantee is that a
deployment with no relay stops depending on one: see "When there is no relay".

There is no third. New code reaches a relay only through update, as today's
rebuild does. An update is a wipe: the provider replaces the disk, which is the
only wipe that means anything.
There is no in-place update, because nothing on the relay can be asked to do
one. The one change a relay makes to itself is the OS's own security updates,
which it already installs unattended; the build also turns on
`Unattended-Upgrade::Automatic-Reboot` at a fixed hour, so a kernel update
does not wait for a reboot nobody can request. Senders retry across the
minute. The ping reports `reboot_required` in between. There is no self-wipe, because a wipe a machine performs on itself
proves nothing about that machine. The only wipe that means anything is one
performed by something the machine cannot influence, and on a VPS that is the
hypervisor.

A relay cannot run a primitive because a primitive runs on an agent, and the
relay has none. What the primitives give the fleet, a closed vocabulary and no
shell, the relay gets by having nothing on it that could take an instruction
except the three above.

## What the relay is today

**Two build paths.** The Server Manager job path (`provision_relay`,
`rebuild_relay`, three tenant jobs) runs `local`/`scp`/`ssh` steps on the
plane's local queue with the node's admin key; it dies with
`agent_local_queue_retirement.md` item 7 WP3 and WP4 and nothing live uses it.
The cloud path (`RelayCloudProvisioner`) creates the instance through the
provider API, injects a per-run root key, and runs the same tarball, scp and
`provision_relay.sh` sequence over SSH from the PHP worker, erasing the key at
the end. The live relay was built this way. It registers no managed node.

**One tunnel, four riders.** WireGuard between the main box and the relay
carries: the spool pull (rsync over ssh as a restricted forced-command tenant
account, ack by the tenant shell's `joinery-ack` verb), the map push (rsync of
a fragment plus `joinery-merge`, which sudo-runs the merge and returns the
verdict), the health ping (`joinery-ping`, JSON over ssh), and, when opted in,
smarthost submission and the Direct egress proxy, both bound to the tunnel
address. The tunnel was the cheap way to keep four plain protocols off the
public internet without writing an API on the relay. The mail itself is
protected by the seal at acceptance, not by the tunnel.

**What the tunnel costs.** sshd and a tenant shell on the relay; a WireGuard
interface on the main box, which makes a compromised relay a routed peer of the
origin; a sudo peering helper and a pull keypair on the main box; per-tenant
Unix accounts, tunnel addresses and peer stanzas on a fleet shard; and every
root-only act that tenant changes need.

**What already points the other way.** The relay already serves HTTPS: the
sealer binary runs Joinery Direct on 443 with its own ACME certificate and
verifies Ed25519 box signatures (`direct_crypto.go`), with a wire gate
(`direct_wire_gate.sh`) proving PHP and Go sign the same bytes. The signed
support bundle channel (`agent_machine_posture_and_relay_converge.md` §4) is
built, tested and idle, and ships one tarball for both architectures. The
sealer binaries are prebuilt into the mailbox plugin. The provider driver
already creates, polls, rebuilds and deletes instances and sets reverse DNS.
PHP's curl exposes `CURLOPT_PINNEDPUBLICKEY`.

## Every root or shell act, and its disposition

| Act today | Transport | Disposition |
|---|---|---|
| Build the relay | root ssh, per-run key | first-boot user-data; the relay builds itself |
| Add tenant `main` | root ssh, same run | done by the build, from the tenant public key in user-data |
| Report WireGuard key and IP | ssh stdout markers | `POST /api/v1/relay/born` from the relay, signed by its identity key |
| Rebuild / upgrade | drain over tunnel, root ssh rebuild | **update**: drain over the API, provider re-image with fresh user-data |
| Spool pull | rsync over ssh, tenant account | `GET /relay/spool`, `GET /relay/spool/{id}.{kind}` |
| Spool ack | `joinery-ack` over ssh | `POST /relay/spool/ack` |
| Map push and merge verdict | rsync + `joinery-merge` over ssh | `PUT /relay/fragment`, verdict in the response |
| Health | `joinery-ping` over ssh | `GET /relay/ping` |
| Direct egress | tunnel-bound listener on 8442 | `POST /egress` on 443, signed |
| Smarthost submission | SMTP on the tunnel address | gone (Q1): the relay is inbound only; an operator who wants their own outbound path uses SMTP sending, which the platform supports |
| Fleet tenant add / set domains / remove | root ssh jobs | `POST`/`PUT`/`DELETE /relay/tenants/{slug}`, operator-signed |
| Main-box WireGuard peering | local sudo helper | gone; nothing on the main box listens for the relay |
| Human troubleshooting | none (the per-run key is erased) | no platform door (Q2). The ping carries everything a person could learn from a shell that is not another tenant's data; the remedy is update |

Nothing in the right-hand column is a shell.

## Security argument

**What protects the mail.** Unchanged. Every accepted message is sealed to the
recipient's public key at acceptance; the relay spools ciphertext; the plane
pulls ciphertext. The relay sees plaintext only in the moment between Postfix
accepting a message and the sealer sealing it, which is why a persistent
compromise of the relay matters and why rotation on a cadence exists.

**Relay identity.** At first boot the relay generates an Ed25519 key and a
self-signed certificate for it. The SPKI fingerprint goes to the plane in the
signed birth report and is pinned on the relay row. Every plane-to-relay
connection verifies that pin, connecting by IP with no dependence on DNS or
the ACME certificate. This is the job the WireGuard public key did, with one
fewer key. The same listener serves the mail hostname's ACME certificate to
Direct callers by SNI; the pinned identity is what the plane sees when it
sends no server name.

**Caller identity: signed requests, no shared secret.** Every request to a
`/relay/` route is signed with an Ed25519 key, the same envelope shape and
canonical-JSON rule Direct already uses, verified by the same code on the
relay. A self-hosted deployment holds one **relay client identity**, minted
with `sodium_crypto_sign_keypair()` and sealed with SecretBox exactly as
`DirectIdentity::mint()` seals its secret, and its public key rides in the
user-data at birth. A fleet tenant sends its public key at enrollment in place
of the pull public key it sends today, and the operator's plane holds an
**operator identity** whose public key rides in a shard's user-data. No token
is ever minted, transmitted or stored: the user-data carries only public keys,
the birth report carries no secret, and an operator never holds anything that
could read a tenant's spool. The relay keeps a nonce cache and a freshness
window, as Direct does, so it needs a synchronized clock; `systemd-timesyncd`
is enabled by the build.

**A compromised relay reaches nothing.** Today it is a WireGuard peer with a
route to the origin box's tunnel address. In the end state the origin has no
tunnel interface, no listener for the relay, and dials out only. The relay
holds public keys, sealed blobs and a map fragment, none of which opens
anything elsewhere.

**Origin concealment.** Unchanged. The origin connects out to the relay, so the
relay sees the origin's address, as it does through the tunnel handshake
today; senders and Direct peers see only the relay. `checkOriginHidden` and
`checkOutboundOriginLeak` keep their meaning. The relay also learns the plane's
URL at birth, to fetch its bundle and report in; it is the same host it already
peers with.

**Bootstrap trust.** The provider API call that creates the instance is
authenticated by the customer's grant-per-act credential (`D1` of
`implemented/mailbox_relay_cloud_provisioning.md`, unchanged: a short-lived
token or one OAuth approval, sealed on the run row and erased at every terminal
state). The user-data it carries names the plane, the bundle's sha256, the
public keys, and a one-time run token. The first-boot script fetches the bundle
from the plane over the plane's own TLS, refuses a hash mismatch, and runs it.
The run token is single-use, bound to the run row, expires with the boot
timeout, and is erased with the run's other credentials. The user-data is
readable by root on the box and by the account holder through the provider
API; it holds no secret that outlives the boot.

**Egress stays fenced.** `/egress` keeps its target rules (no private,
loopback, link-local or reserved address; port 443 only) and now also requires
a valid tenant signature, so moving it from the tunnel to the public listener
opens no proxy.

## Birth

**User-data.** A short shell script the provider runs at first boot, rendered
by the plane from a template in the mailbox plugin:

```
plane              https://<deployment>          where to fetch and report
run_id, run_token  <rcp id>, <one-time token>    single use, expires with the boot timeout
bundle_sha256      <hex>                         the bundle this run was created against
mail_hostname      mx.example.com
authserv_id        <as today>
client_public_key  <base64 Ed25519>              tenant main's signing key (absent for a shard)
operator_public_key <base64 Ed25519>             shard only
skeleton_only      0|1                           a fleet shard has no tenant main
```

It installs `curl`, fetches `GET /api/v1/relay/bundle?run=<id>&sha256=<hex>`
with the run token, checks the hash, unpacks to a root-owned directory, runs
`provision_relay.sh` with those arguments, disables and removes sshd, and
posts the birth report. It logs to the provider console and nowhere else.

**Bundle.** `SupportBundlePublisher` (in `server_manager`, whose docblock
today says no relay content belongs there; that sentence changes with this
spec) adds `provision_relay.sh` and `bin/relay-sealer-linux-{amd64,arm64}` to
the support bundle it already builds at publish. Every deployment receives the
built bundle in `agent_dist/` with its release; none builds one. That is why
the relay flow works without the `server_manager` plugin active.

A publish keeps only one bundle on a deployment (`agent_dist.old` is swap
leavings and is excluded from the release archive), and for a self-hosted
relay the deployment **is** the plane. So the run does not point at "the
current bundle": at run creation the plane **copies the bundle bytes onto the
run**, a run-scoped file under the run's own directory, erased with the run's
credentials at every terminal state. The user-data carries that copy's sha256,
and `GET /api/v1/relay/bundle` serves that copy. A publish landing between
create and first boot changes nothing the run sees.

**The plane is the trust root for relay code.** The support bundle's signature
is verified by the Go agent against its compiled-in key; the relay has neither,
so it verifies only the sha256 the plane put in the user-data, over the plane's
own TLS. That is the same trust the scp path has today and is not a regression,
but the property `agent_machine_posture_and_relay_converge.md` §4 gives a
siteless agent, that the plane is not the trust root, does **not** carry over
to the relay. Anyone who can change what the plane serves can change what a
relay runs; that is already true of the plane's own code.

`AgentChannelEndpoint::handle_artifact` stays node-authenticated; the relay
route is a separate endpoint in the mailbox plugin that serves the run's copy
only to a run in `booting` or `provisioning` presenting its token, and is
metered like the agent channel.

**Birth report.** `POST /api/v1/relay/born` with the run token, signed by the
relay identity key over the canonical body:

```
run_id, public_ip, identity_public_key, identity_fingerprint,
relay_version, postfix (ok), listener_443 (ok)
```

**The run token is not enough to be believed.** It is readable by the
account holder through the provider API and by anything on the box through
the metadata service for the whole boot window, and a birth report sets the
identity pin the plane will trust from then on. So the plane does not learn the
relay's address from the report, and does not trust the pin until the address
the provider gave has answered to it:

1. The address is `rcp_instance_ip`, which `handleBooting` already records
   from the provider. The report's `public_ip` must equal it, and the report
   must arrive **from** it, or the report is refused and logged.
2. The signature must verify against the identity key the report carries, and
   the run token must be live and unspent.
3. The plane then performs a pinned `GET /relay/ping` to `rcp_instance_ip`
   with the reported fingerprint. Only when that succeeds is the pin written to
   `mrl_identity_fingerprint`, the row updated (`mrl_public_ip`,
   `mrl_authserv_id`), the map hash cleared and the fragment pushed. The push
   is the gate, not best effort as it is today: a run whose fragment did not
   land is not `done`.
4. Reverse DNS is requested from the provider, the run is marked `done`, and
   its credentials are erased.

A run that has not reported by the boot timeout is failed and its instance
destroyed, as a boot timeout is today.

**Provider support.** The Linode driver gains `user_data` (the Metadata
service, base64 cloud-init). Regions without Metadata fall back to a
StackScript carrying the same fields. `keyless_provisioning.md` rejected
teaching the driver user-data for `install_node` because a root-password path
already existed and was the smaller change; here no password path exists and
the user-data is the whole mechanism, so that argument does not carry over.
Neither is a new provider process: both are fields of the existing
`createInstance` and `rebuildInstance` calls on the existing driver.

## The relay API

Served by the sealer binary in a `relay-serve` mode that absorbs
`direct-serve`. One listener on 443, certificate chosen by SNI: the mail
hostname gets the ACME certificate (Direct, public callers), anything else gets
the relay identity certificate (the plane, pinned). Direct's paths are
untouched; everything new lives under `/relay/`.

**Request signing.** Every `/relay/` request carries an `X-Joinery-Relay-Auth`
header holding a signed envelope:

```
{ "protocol_version": 1, "tenant": "<slug>", "method": "GET",
  "request_uri": "/relay/spool?after=<id>&limit=200",
  "body_sha256": "<hex>", "nonce": "<16 bytes b64>", "timestamp": "2026-09-05 14:03:11" }
```

plus `signature` over the canonical JSON of that object, field order as
declared. `request_uri` is the path **and query string** exactly as sent, so
paging parameters are covered. `timestamp` is `YYYY-MM-DD HH:MM:SS` in UTC,
the format Direct's fixture already uses, so one clock rule serves both. The
relay resolves the tenant's public key from its registry, verifies the
signature, rejects a timestamp outside the freshness window or a replayed
nonce, and then scopes every path to that tenant's own directory. The nonce
cache is bounded (per tenant, sized to the freshness window at the rate limit)
because this is a public listener. Operator routes are the same envelope with
`"tenant": "operator"`. This is Direct's envelope discipline, not Direct's
envelope: its canonical bytes are pinned by their own fixture in the wire
gate, PHP against Go, exactly as `direct_wire_gate.sh` pins Direct's; a drift
here would fail every pull silently, which is the failure this relay has had
before.

| Route | Replaces | Notes |
|---|---|---|
| `GET /relay/ping` | `joinery-ping` | the diagnostic surface; see "Health is the only window" below |
| `GET /relay/spool` | rsync listing | complete entries only (both artifacts present): id, kind (`seal`, `direct`), size; bounded page, oldest first |
| `GET /relay/spool/{id}.{seal,direct,meta}` | rsync copy | bytes; `Range` honoured for resume |
| `POST /relay/spool/ack` | `joinery-ack` | ids only; removes every artifact kind for each id |
| `PUT /relay/fragment` | rsync + `joinery-merge` | body is the fragment; the response is this tenant's merge verdict |
| `POST /egress` | tunnel-bound egress listener | same protocol, signed, target rules kept |
| `POST /relay/tenants/{slug}` | `relay_add_tenant` job | operator only; body carries the tenant's public key, domains, limits; creates the spool directory and registry entry |
| `PUT /relay/tenants/{slug}/domains` | `relay_set_domains` job | operator only |
| `DELETE /relay/tenants/{slug}` | `relay_remove_tenant` job | operator only; spool must be empty |

**Privilege split.** `relay-serve` runs as the unprivileged relay user under
the hardening the Direct unit already has (`NoNewPrivileges`,
`ProtectSystem=strict`, `CapabilityBoundingSet=CAP_NET_BIND_SERVICE`). It
never gains root. But a merge writes `/etc/postfix/joinery-*`, runs `postmap`
and `postfix reload`, and a tenant change writes the root-owned registry and
creates a spool directory. So the listener does not perform those acts; it
**files a request** and a root unit **reacts to the file**:

- `relay-serve` writes the fragment (or the tenant change) into a root-watched
  drop directory it can write and nothing else can read.
- A root-owned `systemd` path unit fires `relay-sealer merge-maps` (or the
  tenant operation), which validates, applies, and writes a per-tenant verdict
  file.
- `relay-serve` waits a bounded time for the verdict and returns it in the
  response, or a timeout verdict.

This is the same shape the tenant shell's `joinery-merge` has today (the
shell was unprivileged; sudo ran the merge), with the sudo rule replaced by a
file the root unit watches. It keeps the property this spec rests on: nothing
on the relay takes an instruction from the network as root; root reacts to a
file whose contents it validates. The same pattern gives the ping its
privileged facts (below). Without Unix accounts, a tenant change is still a
directory and a registry line, not a user, a peer stanza and a sudoers rule.
The per-tenant rate and size limits in `limits.go` apply unchanged.

The plane pulls with one curl handle per pass, keep-alive on, so a spool of
many small entries is one connection, not one per file.

## Health is the only window

There is no door (Q2), so the ping must carry everything a person would have
learned from a shell, subject to one rule that already governs `joinery-ping`:
**service state is not tenant data; anything per-tenant is reported only when
the relay has exactly one tenant.** On a shard those keys are absent, never
zero.

`GET /relay/ping` returns one JSON object:

| Group | Fields |
|---|---|
| build | `relay_version`, `bundle_sha256`, `built_at`, `image`, `arch`, `kernel` |
| identity | `identity_fingerprint`, `mail_hostname`, `authserv_id`, `tenant_count` |
| services | for each of `postfix`, `joinery-sealer`, `joinery-relay-serve`, `rspamd`, `opendkim`, `opendmarc`: `active`, `since`, `restarts_24h`, `last_exit` |
| listeners | `25` and `443`: bound, and the count of accepted connections in the last hour |
| milters | `wired` per milter, and the rspamd header-contract hash and whether it matches what the build wrote |
| tls | ACME certificate present, `not_after`, last issuance attempt and its error if any |
| clock | `now`, `timesync_active`, `skew_seconds` as timesyncd reports it |
| machine | `uptime_seconds`, `load_1m`, `mem_used_pct`, `disk_used_pct` for `/` and the spool volume, `reboot_required` |
| firewall | the rule set as a list, so a drift from "25 and 443" is visible |
| postfix | `queue_depth` (one tenant only), `last_accept_time`, counts for the last hour: accepted, rejected, deferred, bounced (one tenant only) |
| spool | (one tenant only) entries by kind, oldest entry age, bytes; `held` count |
| direct | `serving`, deliveries and egress calls in the last hour, last error |
| auth | signature failures, replayed nonces and stale timestamps in the last hour, by tenant slug only on a one-tenant relay, otherwise totals |
| log | (one tenant only) the last 50 lines of the relay's own service journals, `postfix` excluded because its log names correspondents |
| sole | as today |

`relay-serve` is unprivileged and cannot read the journal, `ufw status` or
`mail.log`. So a root-owned collector, a `systemd` timer running every thirty
seconds, gathers the privileged groups (services, firewall, journal excerpt,
Postfix counts, `reboot_required`) into a status file the server can read, and
`relay-serve` merges that file with what it measures itself (spool, auth
counters, Direct, clock, listeners). The same privilege split as the merge
(above): root reacts to a timer, never to a request. Nothing shells out to
anything a tenant could influence.
The plane stores the whole object on `mrl_last_health_json`, as it does today,
and the Setup tab's Relay section renders every group, with the raw JSON
behind a disclosure, so a person diagnosing a relay reads the page and never
wishes for a shell. `RelayVersion` and the health battery keep reading the
fields they read today.

When the ping itself fails, the plane records the failure class the pin check
or curl returned (refused, timeout, pin mismatch, signature refused), which
distinguishes a dead machine from an updated one whose pin has not landed from a
clock problem. That, the provider console's boot log, and update are the
whole remedy set.

## The plane side

One class, `RelayClient`, replaces `RelaySsh`: curl with the pinned SPKI
(`CURLOPT_PINNEDPUBLICKEY`, with `VERIFYPEER` and `VERIFYHOST` off because the
pin is the verification and the plane connects by IP; verified on dev's curl
8.5.0 that the pin is still enforced with peer verification off, a wrong pin
failing with curl error 90) against `https://<mrl_public_ip>/`, the request signed with the
relay client identity, the same timeouts the ssh commands carry today. It does
not go through `SafeHttpClient`, for the reason the SafeHttpClient spec gives
for trusted infrastructure endpoints: the target is a pinned key at a known
address, not a URL a user supplied. Its consumers port one for one:

- `RelaySpoolConsumer`: list, fetch to the staging directory, ingest exactly as
  today, ack. Delete-after-store remains the ack; the idempotent store remains
  the crash safety; the per-relay try-lock stays.
- `RelayMapSync`: PUT the fragment, read the verdict from the response body.
- `MailboxRelay::pollHealth`, `RelayVersion`: `GET /relay/ping`.
- `DirectRelayEgress`: `POST /egress`, signed.
- `OutboundTransport`: the smarthost branch is removed; the cutover refusal
  keys on the origin-leak probe verdict instead of the provider class and its
  message names the probe, not smarthost;
  `mailbox_relay_outbound_mode`, its settings UI, and
  `checkOutboundOriginLeak`'s mode branch go with it.
- `FleetService`: tenant lifecycle through the tenant routes with the operator
  identity instead of dispatching jobs; a slot records the tenant's public key
  in place of its WireGuard and pull keys. `coordinates()` returns the shard's
  `identity_fingerprint` and public IP in place of the tunnel endpoint and
  WireGuard key, and `FleetClient::applyCoordinates` writes them to the tenant's
  own `mrl_` row. A shard update changes the pin, so the tenant's
  `RelayClient` treats a **pin mismatch** as its own failure class and
  re-fetches coordinates once before reporting the relay down; a slot whose
  shard was updated therefore heals on its next pull without a push. Nothing
  exercises this today (dev's three slots are all evicted); the fake-relay
  tests cover it.
- `MailboxRelayReconcile`, `check_mail_logic`, `DeliverabilityReportIngest`:
  unchanged callers of the above.

`InboundEmailHealth::checkRelayTunnel` becomes `checkRelayReachable`: a pinned
`GET /relay/ping`. The rest of the battery keeps its meaning.

## Server Manager

**The mailbox plugin owns the relay; Server Manager shows it.** The relay flow
must work on a deployment without the `server_manager` plugin, as the cloud
path does today (`relay_admin.php` gates every Server Manager touch on
`isPluginActive('server_manager')`). The `mrl_` row and the `rcp_` run are the
source of truth; the provider driver is core (`includes/cloud_compute/`).

When Server Manager is active, the relay is a `ManagedNode` in the
**disposable** posture, marked by the existing `mgn_is_relay`: no agent, no key
path, no SSH fields, `has_ssh()` false by construction, provider coordinates
read through `mrl_mgn_managed_node_id` rather than duplicated. The dashboard
shows it with the fleet, monitors it with the existing plane-side uptime probe
plus the ping, and offers exactly Update and Delete, the same acts the
mailbox Setup tab offers. Create lives on the Setup tab,
where the relay's inputs are known.

**Which plane.** The relay's registration lives in the served deployment's
`mrl_mailbox_relays`. For a self-hosted relay the served deployment is its own
management node and everything above is on one box. For a fleet shard the
operator's plane creates it (`skeleton_only`), holds the operator identity, and
tenants reach it through the fleet service. This is the answer to the open
question in `agent_machine_posture_and_relay_converge.md` §9.4, with the
traced facts it asked for: dev's only `mrl_` row is soft-deleted and carries
no managed node id, and the live relay's row is on jeremytunnell, the
deployment it serves, which is also that deployment's own management node.
The fleet case has never had live data.

**Node 1800 on dev** ("Relay joinery-relay-1", a hand-made row with an SSH key
path and uptime monitoring, `mgn_is_relay` false) is replaced by the row the
rotation in WP5 creates, then deleted.

## Update and rotation

Update is the drain-gated upgrade of
`implemented/mailbox_relay_upgrade_without_server_manager.md` with the SSH leg
removed: drain through the API until the spool is empty (held entries still
refuse, no-progress still refuses, because a wipe destroys accepted mail nobody
else has a copy of), the provider's re-image of the same instance with fresh user-data
and a fresh run token, birth report with a new identity pin, DNS untouched
because the address is kept. Inbound mail between drain and birth report is
retried by senders.

A relay under suspicion whose spool cannot be drained is not updated. The
owner deletes the machine at the provider by hand, Deletes its row, and
creates a new relay, accepting the loss on the spool. The destructive act is a
human's at the provider, which is the right place for it.

## When there is no relay

A row is Deleted, or the machine is gone, or the ping fails for long enough.
In every case the deployment must stop depending on the relay without anyone
touching a setting:

- **Outbound never depended on it.** With smarthost gone (Q1), compose sends
  leave through the provider. `DirectRelayEgress` already returns no client
  when there is no enabled relay, and a Direct send then goes out directly or
  downgrades to SMTP as it does for any unreachable peer.
- **Inbound does depend on it, and the health check must say so.** The MX still
  points at the relay's hostname, and the origin's own listener may be off
  (`joinery-mail-listener off`). `checkInboundMailServer` and
  `checkForwardingRelay` report, in one sentence, that mail is addressed to a
  relay this deployment no longer has, and the Setup tab offers the two ways
  out: create a relay, or repoint the MX at the origin and turn its listener
  on. Neither is done automatically; both change where the world sends mail.
- **Delete's confirm** names the machine, says it keeps running and billing at
  the provider until deleted there, and says the MX still points at it.

**Provider credential: grant-per-act, and only that.** Every act here asks for
a fresh short-lived token or one OAuth approval. `relay_rotation_automation.md`
D1 chose standing sealed tokens so a scheduled rotation could run with nobody
present; that spec is unbuilt, this spec does not build it, and the owner
declined any standing provider credential on 2026-09-05. Manual rotation
(create new, cut DNS, drain old, delete the old machine at the provider) keeps its shape under this spec and
loses nothing. Unattended rotation stays that spec's question, to be answered
there, if ever, on its own custody terms.

## Cutover order

The plane that drains an old-style relay must still speak ssh to it. So:

1. Ship WP1 to WP3. The switch is one column: a row with
   `mrl_identity_fingerprint` set uses `RelayClient`; a row without it and with
   `mrl_wg_public_key` set uses `RelaySsh` and the tunnel consumers, unchanged.
2. On each deployment with a live relay (today: jeremytunnell, and dev's
   soft-deleted row), rotate: create a born relay, cut the MX, drain the old
   relay over ssh, delete the old machine at the provider by hand.
3. Remove the main box's tunnel by hand as root on each such box: the
   `jyrelay0` interface, `/etc/wireguard`, `/usr/local/sbin/joinery-relay-peer`
   and its sudoers line, `config/relay_pull_key` and its `.pub`. The owner's
   own boxes keep sshd for exactly this kind of step. The other helpers
   `provision_relay_main.sh` installs (`joinery-mail-listener`,
   `joinery-dkim-remove`) are unrelated to the tunnel and stay; the script
   keeps installing them and stops doing the WireGuard and pull-key parts.
4. WP4 deletes the ssh code in the next release. There are no production users
   (`project_no_production_users`), so no longer compatibility window is owed.

## What is deleted (WP4)

- The five relay job builders in `JobCommandBuilder` (also on item 7 WP4's
  list; whichever lands first deletes them), their `relay_admin.php` and
  `FleetService` dispatchers, and `JobResultProcessor`'s relay handlers.
- The SSH leg of `RelayCloudProvisioner` (`handleProvision`'s scp and ssh,
  `writeKeyFile`, `generateSshKeypair`, `sshOptions`), the columns
  `rcp_sealed_ssh_key` and `rcp_ssh_public_key`, and `authorized_keys` at
  create.
- `RelaySsh.php`; the WireGuard and pull-key sections of
  `provision_relay_main.sh`.
- In `provision_relay.sh`: WireGuard, the sshd drop-in, the tenant shell,
  tenant Unix accounts, the sudoers rules, tunnel addresses, the 8442 egress
  binding. In the sealer: `direct-serve`'s `--tunnel` flag and the 8442
  listener, absorbed by `relay-serve`.
- Columns that named the old identity: `mrl_host` (the tunnel address),
  `mrl_ssh_user`, `mrl_ssh_port`, `mrl_ssh_key_path`, `mrl_spool_path`,
  `mrl_wg_public_key`, `mrl_wg_endpoint`,
  `mrl_wg_ip`, `mgn_wg_public_key`, `mgn_wg_endpoint`, `mgn_wg_ip`,
  `mfs_wg_endpoint`, `mfs_wg_public_key`, and the slot's WireGuard and
  pull-key columns, retired through the models and `update_database`.
- The `mailbox_relay_wg_public_key` and `mailbox_relay_outbound_mode` settings
  (from `plugin.json`), the Setup tab's tunnel rows, the smarthost opt-in, the
  tunnel submission listener in `provision_relay.sh`, and
  `InboundEmailHealth`'s smarthost checks.

## Work packages

**WP1 — The relay serves itself.** `relay-serve` in the sealer: identity key
and certificate at first start, SNI certificate selection, the request-signing
envelope and nonce cache, the routes above, tenant registry without accounts.
`provision_relay.sh` 3.0: no WireGuard, no sshd, no accounts, timesyncd on,
firewall 25 and 443 only; takes the user-data arguments; writes the birth
report. The support bundle carries both and keeps its predecessor. Go tests
for every route; the wire gate extended to the relay envelope.

**Proof without a provider:** the first-boot script run by hand as root on the
scratch box (45.33.72.32) against dev as the plane, through fetch, build and
birth report, before any provider user-data is involved. The script takes a
`--keep-sshd` flag for exactly this run, so the box is not locked; the flag is
absent from every user-data template and refused by a run that is not
hand-started. Every earlier relay
failure surfaced half an hour into a provider run; this one surfaces on a box
you can watch.

**WP2 — The plane speaks HTTPS.** The relay client identity; `RelayClient`;
the consumers ported behind a per-row switch on the identity pin;
`checkRelayReachable`; the `relay/bundle` and `relay/born` endpoints; the
Linode driver's `user_data` and the StackScript fallback. Tests against a fake
relay for each consumer, and the wire gate.

**WP3 — Born configured.** `RelayCloudProvisioner` without the SSH leg: `ready`
creates with user-data, `booting` polls, `provisioning` waits for the birth
report, drain and update over the API. The disposable posture on `ManagedNode`
and the dashboard card with Update and Delete, present only when Server
Manager is active.

**WP4 — Delete**, after step 2 of the cutover has run everywhere a relay lives.

**WP5 — Live gate.** One relay born on dev's provider account end to end: birth
report, map push, a message accepted, sealed, pulled and acked, ping green,
update with a non-empty spool refused, update with an empty spool completing
with a new pin, Delete of the row with the health check then reporting the
orphaned MX, the machine deleted at the provider by hand. Then jeremytunnell's relay rotated onto a born relay
and the old one deleted at the provider by hand. Owner-run.

WP1 and WP2 are independent. WP3 needs both. `agent_local_queue_retirement.md`
item 7 WP3 (flip the local queue off) does not wait for any of this; the job
path this spec deletes is already dead.

## Decisions for the owner

**Q1 — DECIDED 2026-09-05 (owner): smarthost is dropped.** The relay is
inbound only. A tenant key can read its own spool and write its own routing
fragment, nothing more. The signed Direct egress route still gives a Direct
send the relay's address.

**What that means for outbound mail, stated exactly.** `OutboundTransport`
today refuses any provider that does not submit over an API once
`mailbox_relay_cutover_complete` is set, because SMTP from the box stamps the
origin's address into the first `Received` header, which is the address the
relay exists to conceal. Its refusal offers smarthost as the alternative. With
smarthost gone, that refusal is replaced by a **measurement** (owner,
2026-09-05): on a hidden-origin deployment, SMTP sending is allowed when the
most recent origin-leak probe (`checkOutboundOriginLeak`, which sends a message
through the configured provider and scans the delivered headers for the
origin's address and hostname) **passed within its freshness window**, and
refused, with that reason and the probe button, when it failed or has not run.
An operator who runs their own Postfix strips the submission `Received` line
with a header check, runs the probe, and sends; if they get it wrong the probe
catches it before any real mail leaks. API-submitting providers (Mailgun,
Amazon SES) pass by construction and need no probe. Nothing new is built: one
condition (provider class) becomes another (probe verdict) that already
exists. A deployment that has not completed the cutover keeps every provider
it has today, unprobed, as now.

**Q2 — DECIDED 2026-09-05 (owner): no human door.** The health check carries
as much information as possible, and that is as far as it goes. See "Health is
the only window".

**Q3 — DECIDED 2026-09-05 (owner): the platform never destroys the machine.**
Destroying the VPS is the owner's manual act at the provider, exactly as
`implemented/mailbox_relay_cloud_provisioning.md` item 5 records. The relay's
vocabulary is create and update. What the platform owes is that a deployment
with no relay stops depending on one; see "When there is no relay".
