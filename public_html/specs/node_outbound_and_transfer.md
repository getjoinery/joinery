# Outbound limits and transfer on every node

**Status:** Draft, 2026-10-05. WP1 built 2026-10-06 (tests
`machine_transfer`, `outbound_transfer_meter`; running on dev). WP2 built
2026-10-06 (gates `site_run_spec`, `host_housekeeping`; proven live on a
scratch Linode). WP3 built 2026-10-06 (gates `outbound_limits`, `host_report`,
`job_result_processor`; proven live on two scratch Linodes). WP4–WP5 not built. Every mechanism they rely on was tried by hand on two scratch Nanodes
on 2026-10-06; the results are in § Measured on scratch boxes and the work
packages below. Split out of
`site_outbound_limits`, which keeps what only a multi-tenant host needs (a
lane and a monthly share per site among many). This spec covers what every
install needs, whoever hosts it: managed by us, or self-hosted on the owner's
own provider account. WP1 depends on nothing and goes first.

## What this does

Two things cost us when a machine sends too much:

- **The bill.** Linode charges for traffic leaving a machine past its
  allowance. Downloads by a site's visitors count, and so do uploads the site
  starts itself.
- **Abuse reports.** A site's permission-10 admin can upload a plugin and run
  their own code (`hosted_abuse_response` § What abuse looks like). From a
  web host, abuse means scanning other servers, guessing passwords on them,
  or flooding them. Reports name the machine's address and land on our Linode
  account.

Both apply to a managed customer's own VPS as much as to a multi-tenant box,
and to a self-hosted install on the owner's own provider account. There the
bill and the reports are the owner's, not ours, and the limits protect them.
Most owners never think about bandwidth, which is who a surprise bill or a
suspended account lands on. Other providers charge far more than Linode past
an allowance: AWS charges about $0.09 per GB, so the month that costs $320
past a Nanode's allowance (below) costs about $5,800 there.

This spec gives every install:

| Piece | Which installs | What it does |
|---|---|---|
| Transfer watch (WP1) | every install | an alert when a machine runs ahead of its allowance: to an operator where we hold the provider account, to the site's admin otherwise |
| IPv6 for containers (WP2) | every Docker host | same-region uploads and traffic to machines in the same data center stop counting against the allowance |
| Connection rate and UDP drop (WP3) | every install | a scanner crawls; floods and reflection attacks are gone |
| Speed ceiling (WP4) | every install | a runaway site costs a bounded amount while someone looks |

Our own nodes are the one exception: left unlimited, with a switch on the
node page to turn the limits on (WP5).

No feature is switched off. Every limit leaves a site's real use — its
owner's mail, calendar and Drive, its pages, its backups — untouched.

## The numbers

Linode adds every machine's allowance to one account pool and bills overage
against the pool at **$0.005 per GB**. Inbound traffic is free.

| Machine | Transfer a month | Outbound link |
|---|---|---|
| Managed customer, Nanode 1 GB | 1 TB | 1 Gbps |
| Multi-tenant box, shared 4 GB | 4 TB | 4 Gbps |

| Figure | Arithmetic | Value |
|---|---|---|
| A site's speed ceiling, on a machine of its own | 10× a multi-tenant site's 20 Mbit/s; a site on its own server should barely meet it | **200 Mbit/s** |
| Time for it to spend its machine's 1 TB at the ceiling | 1 TB at 200 Mbit/s | about 11 hours |
| A month flat out at the ceiling | about 65 TB | about **$320** past its 1 TB |
| The same month with no ceiling | 1 Gbps, about 320 TB | about $1,600 |
| Each day an operator takes to act, at the ceiling | 200 Mbit/s for 24 hours, about 2.2 TB | about $11 |

A multi-tenant box's sites get their own, lower figures from
`site_outbound_limits`.

## What it builds on

- **The run spec** (`multi_tenant_docker_hosts` WP0, built): every container
  site is run from one saved record, so a rebuild, rebase or move keeps what
  it records.
- **The account pool alert** (`HostedTrialWatch::watch_transfer_pool()`):
  once a billing period, an operator alert when the account's pool is 80%
  spent.
- **The provider interface** (`CloudComputeProvider`, `LinodeComputeDriver`),
  which already reads the account's pool (`getTransfer()`).

## Work packages

### WP1 — Each machine's transfer

Two sources, by who holds the provider account.

**Where we hold it,** from the provider. An optional provider capability,
`CloudMachineTransfer` (beside `CloudInstanceTransfers` and
`CloudAddressSwap`, so a driver that cannot answers by not implementing it),
lists an account's machines (`listInstances()`) and reads one machine's month
(`getInstanceTransfer($instance_id)`). `LinodeComputeDriver` implements it
with `GET linode/instances` and `GET linode/instances/{id}/transfer`.

What Linode's figure holds (measured 2026-10-06): `used` is in bytes,
`quota` in whole GB (prorated for a machine created mid-month), and
`GET linode/instances/{id}/transfer/{year}/{month}` splits it into
`bytes_in` and `bytes_out`. **Traffic to another machine in the same data
center over IPv6 is in neither direction**: 5 GB from the host and 3.5 GB
from a container (through Docker's IPv6 translation) left the machine
without moving its figure, while the same traffic over IPv4 was counted. The
figure caught up with traffic within five to ten minutes. The endpoint needs
only `linodes:read_only`; the account pool needs account access (see B1 in
§ Measured on scratch boxes).

Once a day (**Watch Machine Transfer**, `MachineTransferWatch`) the plane
reads every account it holds a credential for: the operator token and each
connected customer account. It matches each account's machines to its nodes
by address: the node's host (resolved when it is a name), its server's host
for a site on a shared one, and the addresses the machine reported when its
agent last joined (a name behind Cloudflare resolves to Cloudflare; on dev
that is how `dev.getjoinery.com` finds its machine; joins before about
2026-10-01 carry no reported addresses). A node is on one machine: a match by
host beats a match by a join, and of equals the newest machine wins, so a site
that moved is not also matched to the machine it left. A connected account
whose token cannot be refreshed is a problem in the run's report; this read
never marks it for re-granting. On a month's first two days a figure no lower
than last month's is taken as not yet started again and held, so last
month's total never raises "allowance passed" on the 1st. Each machine a node runs on gets
one `MachineTransfer` row (`mtr_machine_transfers`) with the month's figure,
the allowance and the read before; every node on it points at the row. A
machine no node runs on is not read.

Its conditions are an incident, `plane:machine_transfer`, so they reach an
operator the way every other condition does (Reconcile Incidents opens it,
tells the superadmins, clears it when the condition is gone):

- **the allowance passed**: critical;
- **a day far above its share**: over a window of at least 20 hours, more
  than three times the machine's daily share (the allowance over the month's
  days, or the days since it was created; 32 GB a day for a Nanode, so about
  100 GB) and at least 1 GB;
- **on pace**: at least three days in, the month projected from the figure
  so far is past one and a half times the allowance (half the allowance by
  day 10 is about that).

One node speaks for each machine, the server's own node where it has one,
else its lowest-numbered site, and the incident names the others: a server of
twelve sites raises one. A reading more than three days old raises nothing.

This catches the one runaway machine that the account pool alert hides: the
pool is the sum of every machine's allowance, so one machine can run far past
its own share before the pool reaches 80%.

The node page's Health shows the machine's month against its allowance, with
when it was read and any read error.

Linode's own transfer alert (a percentage per machine, set through the API)
emails the account holder and uses a format Linode is retiring. Reading the
figure directly gives the plane the same fact without parsing email.

**Where we do not,** the site counts what it sends itself
(`OutboundTransferMeter`, the **Outbound Transfer Count** task on every run).
It reads the bytes sent on the interfaces that carry the machine's default
routes, IPv4 and IPv6 (`/proc/net/dev`): inside a container its own
interface, which carries its replies to the host's proxy; on bare metal the
public interface, not loopback, a Docker bridge or a tunnel. It adds the
difference since its last reading to the month's total. A counter that went
down, or a reading under a different boot or network namespace (a reboot, a
new container), counts what is on the counter as new. An interface first
seen under the same boot is a baseline, never a delta: it only just took a
default route (a DHCP renew, a tunnel briefly taking `::/0`), and its counter
holds everything since boot. The first reading ever only sets the baseline. On a single-site machine the site is nearly all of
what the machine sends, so this is close to the provider's figure; it runs a
few percent above it, because packet headers are in it.

Past a monthly figure the site's superadmin sets (`outbound_monthly_notice_gb`,
Settings → Outbound transfer), the admin header says so to superadmins and one
email goes to them that month (`site.outbound_transfer_high`), in plain words:
how much the server has sent, that most providers charge past an allowance,
and to check their own provider's. The figure defaults to **1 TB**, the
smallest allowance among common providers' small plans; 0 turns it off. The
settings page shows the month so far beside it. A site the operator hosts on
its own account (`hosted_plan_state` trial, subscribed, grace or shutdown)
stays silent: the plane watches that machine.

### WP2 — IPv6 for containers, on every Docker host

**Built 2026-10-06** (gates `site_run_spec` 210, `host_housekeeping` 125;
proven live on a scratch Linode, below).

Linode charges nothing for traffic from a machine to any service in the same
data center over IPv6, including uploads to a Linode Object Storage bucket in
the same region. Over IPv4 the same traffic counts against the pool, and a
bucket's own outbound traffic always counts, even to a machine beside it. A
container site on Docker's default network has no IPv6, so all of it would be
charged.

**Every container site runs on a network of its own,** `SITENAME_net`, with an
IPv4 subnet and a private IPv6 /64. The host numbers its site networks: slot
N is `10.250.N.0/24`, `fd00:250:N::/64` and the bridge `jsnetN`. The bridge
name is fixed so that WP3, WP4 and the multi-tenant walls
(`multi_tenant_docker_hosts` WP5) can name a site's interface without asking
Docker. `install.sh site` takes the lowest slot that nothing on the host
holds: no run spec, Docker network, route (a VPN, a private network) or
interface. Docker would refuse an overlap with its own networks, but not one
with a route. The slot is taken by creating the network, under a host lock,
before anything is stopped. The run spec (format 2) records it in four lines:
`network=`, `bridge=`, `subnet=` and `subnet6=`. `run_spec_args` makes the
network exist exactly as the spec says before it gives `--network`, so a
rebuild, rebase or move cannot run a site onto a missing or different
network. A different network with nothing attached is recreated. One a
container still uses is refused by name. Adopt accepts a container already on
its own network of that shape. `remove_account.sh` removes the network with
the site.

Outbound traffic leaves through the host from the machine's one IPv4 address
or its one IPv6 address. Docker translates both (`MASQUERADE` in `iptables`
and `ip6tables`), with no setting in `daemon.json` from Docker 27 on. A
network is IPv4 only, and the installer says why, when any of these holds:
- the machine has no IPv6 route out;
- Docker is older than 27;
- `daemon.json` sets `ip6tables` to `false`.

The next rebuild after that changes gives the network its slot's IPv6.

**The container must be told to use its IPv6** (found while building).
glibc's default address-selection table gives a private IPv6 source
(`fc00::/7`) a different label from a public destination. So from an `fd00:`
address, every name with both families resolves IPv4 first, and the
container's IPv6 goes unused. Measured on the drive-test VPS:
- getjoinery.com and `us-east-1.linodeobjects.com` resolved IPv4 first, and
  curl went over IPv4.
- With a table that drops the private-address label, both went over IPv6, out
  from the machine's own IPv6 address.

`host_housekeeping.sh` writes that table to `/etc/gai.conf` in every
container at every start. It is glibc's default without the `fc00::/7` entry.
A container with no IPv6 is unaffected: with no IPv6 source, IPv4 comes first
anyway.

**The proxy's address.** On any network but the default, the host proxy's
requests arrive from that network's gateway. Both copies of
`RemoteIPInternalProxy` named the default bridge (`172.17.0.0/16`): one in
the image (`Dockerfile.template`), one in `host_housekeeping.sh`'s
`joinery-remoteip.conf`. Behind them, every visitor was logged as the gateway.
fail2ban would then ban every visitor at once, and rate limits, login IP rules
and analytics would see one address.
- `host_housekeeping.sh` 1.13 names the container's own gateways, IPv4 and
  IPv6, read from its routes.
- The image's own baseline (Dockerfile 6.1) trusts the host's site-network
  ranges, `10.250.0.0/16` and `fd00:250::/32`, beside the default bridge.
  The image is versioned apart from the site's code. A rebuild keeps the
  site's code volume, whose `host_housekeeping.sh` may predate site networks
  (review R1). These ranges are host-internal, and Docker keeps one site's
  network from another's.
- `PluginProvisioning`'s `host-gateway` no longer falls back to
  `172.17.0.1`. A container with no default route reports the host as
  unreachable.

PostgreSQL's "Docker host" line in `pg_hba` already followed the container's
default route. `rebase_site_container.sh` reads the gateway from `docker
inspect`, which is right for a container on one network.

**Existing sites** move at their next rebuild, or without stopping by
`move_site_to_own_network.sh SITE` (or `--all`):
1. It records the run spec if there is none, takes a slot and writes it into
   the spec.
2. It connects the container to the new network, then disconnects it from
   Docker's default.
3. It runs `host_housekeeping.sh` inside the container.
4. If Apache has no IPv6 listener, it starts Apache again once, under the
   supervisor's hold. Apache bound port 80 when the container had no IPv6, and
   a reload keeps that socket. Docker then sends a visitor reaching a
   published port over IPv6 to the container's IPv6 address, where nothing
   listens. Found on the scratch box: a site moved without this restart lost
   every IPv6 request to its published port, and IPv4 was unaffected. Sites
   behind the host's proxy are reached over IPv4 and never saw it.
5. It checks the site on its port.

A site whose code predates `host_housekeeping.sh` 1.13 is refused before
anything changes, because on the new network it would log every visitor as
the gateway. A re-run finishes a move that was interrupted.

**What follows from IPv6:**

- A container's PHP, curl and every other glibc program use IPv6 wherever a
  destination has it (getjoinery.com, B2 and Linode Object Storage do;
  Stripe, SMTP2GO and Namecheap do not today). So backups, which PHP sends,
  go over IPv6. The host agent is built without cgo, so Go's own resolver
  ignores `/etc/gai.conf` and keeps IPv4 first from a private source. Its
  traffic (host reports, release downloads) stays on IPv4, which is small. Each
  process reads `gai.conf` once, so after a move it takes effect as processes
  start again.
- The management node compares a node's source address in one place: a
  customer cloud join's approval checks it against the provider's IPv4 and
  IPv6 for the machine (`ProvisionCustomerCloud::join_approval_check`,
  `CustomerCloudProvision::for_machine_address`). It accepts either family.
  The agent channel keys by identity (`multi_tenant_docker_hosts` S23).
- A machine's IPv6 address sits in a /64 that Linode shares among many
  customers' machines, and IPv6 blocklists usually list whole /64s. With
  port 25 closed to every machine we create (`own_mail_server_sending` § 4),
  this matters little. A routed /64 per machine, with an address per site,
  was considered and not taken: blocklists acting on the /64 would still
  treat the machine's sites as one.
- Every firewall rule a node carries for its sites is written for both
  families. `install.sh docker`'s `DOCKER-USER` rule for the database ports
  is added to `ip6tables` too. The multi-tenant walls are one `inet` table
  (`multi_tenant_docker_hosts` WP5).
- Docker 27 and later set `net.ipv6.conf.all.forwarding=1`. On Linode's
  Ubuntu images `systemd-networkd` handles router advertisements itself, so
  the host keeps its IPv6 route. Seen on the drive-test VPS: 6 weeks up, the
  RA route refreshed.

Bare-metal sites need nothing: the site runs on the machine's own network
stack, which already has IPv6.

**Proven live, 2026-10-06** (scratch Linode 2 GB, Ubuntu 24.04, Docker
29.8.2, release 0.8.460 with this work laid over it; deleted afterwards):

| What | Result |
|---|---|
| a new site | `s1_net`, slot 1, `10.250.1.0/24` and `fd00:250:1::/64`, created before the image build; the container on it alone |
| its outbound traffic | `api64.ipify.org` saw the machine's IPv6 address; `us-east-1.linodeobjects.com` reached at a same-data-center IPv6 address; `curl -4` saw the machine's IPv4 |
| a visitor through the proxy address, `X-Forwarded-For: 203.0.113.9` | logged as `203.0.113.9`; the proxy trusted is `10.250.1.1` and `fd00:250:1::1` |
| the host's login to the site's database | admitted from `10.250.1.1`; `psql` from the host answered |
| a rebuild | the same network (same Docker id), slot and addresses |
| a running site moved, polled every 0.1 s from dev over IPv4 and IPv6 | 160 of 160 answered on each family; the move took 2.6 s including Apache's restart |
| the same site afterwards | visitor logged from `X-Forwarded-For`; the database admits the new gateway; outbound over IPv6 |
| `remove_account.sh` | the network went with the site; `DECOMMISSION_VERIFIED` |
| `install.sh docker` | the database-port rule in `ip6tables` as well as `iptables` |

### WP3 — New connections and UDP

**Built 2026-10-06** (gates `outbound_limits` 71, `host_report` 147,
`job_result_processor` 287; proven live on two scratch Linodes, below).

On every install. These have no setting a site's admin can change: real use
never meets them, and they stop a hacked plugin turning the server into a
scanner.

**One limit per site across IPv4 and IPv6.** `iptables` and `ip6tables`
keep separate counters, so a rate written in both would let a site use the
full limit over each family, twice in total. The limits live in an nftables
table of their own in the `inet` family, which sees both. It sits beside
Docker's rules, not inside them: a packet either table drops is dropped.

- **A container site** is matched by its bridge (WP2), on the host's forward
  path.
- **A bare-metal site** is matched by the web server's user (`meta skuid
  www-data`), on the host's output path.

Per site, for traffic leaving the machine:

- a rate on new connections (`ct state new`, TCP and UDP), about 20 a
  second, bursts to 100. Over the limit, drop. A dropped opening is sent
  again by the site's own TCP a second later, so a short burst over the rate
  is slowed, not refused; only a sustained one fails.
- a cap of **256** connections open at once from the site (`ct count`). A
  connection the site has closed and the far end has not counts for up to
  a minute more (the connection tracker's close-wait timeout), so the cap
  needs room above real use. Real use is small: on the dev server, sampled
  every 2 seconds for half an hour (939 samples), the web user never held
  more than 2 outbound connections. The platform makes its outbound calls one at a time
  per PHP worker (the only fan-outs are a file's variants pushed to the
  bucket together and the DNS-filter scan in batches of 5), so the most a
  site can hold is about its PHP worker count (at most 80) plus its
  scheduled tasks. 256 is three times that.
- a counter of drops, read by the host report.

Matching, as measured: a container site by `iifname` its bridge on a
`forward` hook at priority -10 in the table's own chain, which runs beside
Docker's chains; a bare-metal site by `meta skuid www-data` on the `output`
hook with `oifname "lo"` accepted first, so its lookups to the local resolver
and its own database are never touched.

Traffic between a site and the host's own proxy (the reply path) is not
counted. A container site reaching the host itself (another site on the same
host, through the host's proxy) arrives on the host's input path and is not
counted. A site reaching another machine, or sending a Joinery Direct
delivery to one, is counted like any other outbound connection.

**All outbound UDP from a site is dropped, over IPv4 and IPv6.** A site's
only use of UDP is name lookups, and those never leave its network as
packets. Measured on the drive-test VPS, 2026-10-05: a container on its own
network asks Docker's resolver at `127.0.0.11` inside the container;
`dockerd` forwards from the host's side to the host's own resolver at
`127.0.0.53` (systemd-resolved); the host asks Linode's resolver from its
public address. Nothing crossed the site's bridge. Docker forwards from the
host's side because the host's resolver is a loopback address. On a
bare-metal site, PHP asks the same local resolver, and the resolver's own
queries run as its own user, not the web server's.

- A container keeps time from the host's clock and runs no NTP of its own,
  and nothing in the platform speaks HTTP/3, WebRTC, syslog or statsd.
- The three places that find their own address with a UDP "connect"
  (`IpAddress::detectPublicIpv6()`, `NamecheapDnsDriver::clientIp()`,
  `InboundEmailSetupCheck::detectPublicIp()`) send no packet, so the rule
  does not touch them.
- Dropped UDP counts toward the site's drop counter.

The rule removes UDP floods and reflection attacks outright, and DNS sent
straight to a server the site's code chose. It does not stop data leaving as
lookups of names under a domain the site's owner controls: the host's
resolver resolves them like any other name, which delivers the query to that
domain's own server. That path is slow and narrow, and is outside this spec.

**The rule depends on the host's resolver being a loopback address.** Were
the host's `/etc/resolv.conf` to name an outside resolver, Docker would
forward lookups from inside the container's network, and the rule would
break every site's DNS. The WP5 unit checks it before writing the rules and
refuses, in plain words in the host report, when it does not hold.

The host report adds each site's drops since the last report. On intake the
plane flags a site with drops, and `hosted_abuse_response`'s outlier alert
reads it. Many dropped connections is a stronger signal of scanning than
bytes sent.

**As built:**

- `install_tools/outbound_limits.sh` 1.0, installed as
  `/usr/local/sbin/joinery-limits`. `joinery-limits.service` (oneshot, after
  Docker and the resolver, at boot) and `joinery-limits.timer` (every five
  minutes) run `apply`. WP5 adds the figures and the site setting to the
  same unit.
- The table, `inet joinery_limits`:
  - a `forward` chain at priority -10 jumps, per site, from `iifname
    "jsnetN"` to that site's chain;
  - with `--web-user`, an `output` chain at priority -10 accepts `oifname
    "lo"`, then jumps from `meta skuid "www-data"`;
  - each sender's chain drops, in order: UDP; a new connection over 256 open
    (`ct count`); a new connection over 20 a second, bursts to 100;
  - every drop counts in a named counter, `drops_site_SITE` or
    `drops_web_user`.
- **A site still on Docker's default network is not limited.** It shares
  `docker0` with every other such site, so nothing per site can name it. Its
  lookups also leave as its own UDP there: on the default network Docker
  copies the host's upstream resolvers into the container. `status` and
  `apply` name each such site and the move script that fixes it;
  `install.sh site` already gives every new or rebuilt site its own network
  (WP2).
- Which sites, and which machine kind:
  - the sites are read from the run specs: a `bridge=` line is limited, a
    spec without one is not covered;
  - a bare-metal machine is recorded in the unit (`apply --web-user`,
    written by `install.sh server`);
  - `install` keeps `--web-user` once given.
- **Kept across runs.**
  - A run whose table would be unchanged replaces nothing, so the rate's
    tokens and `ct count`'s connection list are not reset every five minutes.
  - A run that changes it (a site added, moved or removed) replaces it in
    one transaction and writes each drop counter's value into the new
    declaration.
  - `/run/joinery/outbound_limits.status` holds `since`, when the counters
    began. It is reset at boot with the table.
  - A change nft refuses leaves the table before it in force. The status
    keeps that table's sites and `since`, with `reason=nft_refused`, so its
    drops are neither hidden nor counted twice. The unit fails, and the next
    run tries again. `status`, the host report (`reason` beside `on`) and the
    node page (an amber line under the badge) each say the last change was
    refused.
  - `bridge=docker0` in a hand-edited spec is not one site's, and is passed
    over.
- **The off switch** exists from WP3, since no limit should arrive on a
  machine with no way to turn it off:
  - `--no-outbound-limits` on `install.sh docker` and `install.sh server`;
  - `joinery-limits on|off`, writing `"enabled"` in the host file
    (`/etc/joinery/outbound_limits.json`) and keeping its other keys for
    WP5.
  - A host file that does not parse leaves the limits on.
  - `install.sh server`'s part runs in a new `do_server` around
    `do_server_setup`: the base image build runs and hashes that function, and
    an image has no host to limit.
- **The resolver check.** Every `nameserver` in `/etc/resolv.conf` must be in
  `127.0.0.0/8` or `::1`. Otherwise the run:
  - removes the table;
  - writes `state=refused` and `reason=resolver_not_loopback`;
  - says why and how to fix it;
  - exits 1, so the unit shows as failed in the host report as well.
- **Callers.** Each of these starts the unit, never runs the script: one run
  at a time, and the unit's own flags.
  - `install.sh site`, once the container starts (`run_spec_limits_refresh`,
    `_site_run_spec.sh` 1.4);
  - `move_site_to_own_network.sh` 1.1, after the moves;
  - `remove_account.sh` 2.8, after the run spec goes.

  A rebase keeps the site's network, so it changes nothing here.
- **Host report 1.9:**
  - `outbound_limits` carries `state` (`on`, `off`, `refused` with a
    `reason` code, `absent`, `none` in a container, `unknown`), `since`, and
    `web_user_dropped`;
  - each container carries `outbound_dropped` (packets, or `none` when not
    limited);
  - the counters are read with `nft list counters` alone.
- **Plane** (JobResultProcessor 1.61):
  - keeps both;
  - `host_report_container_rates()` stores `since_last.outbound_dropped`
    (and `outbound_limits.dropped_since_last`): the difference while `since`
    holds, the count itself when the counters began between the two reports,
    nothing otherwise. A container restart does not reset them.
- **Node page** (overview 1.45):
  - a site with drops since the last report gets an amber count;
  - a running site the limits do not cover says so;
  - the Machine tile says whether the limits are on, off, absent, or not in
    force and why.
- The alert that reads the drops belongs to `hosted_abuse_response`, not yet
  built.

**Live, 2026-10-06.** Two Linodes in `us-east` ran 0.8.462 with this work laid
over it:
- A, 2 GB: `install.sh docker`, sites s1 and s2;
- B, a Nanode: `install.sh server`.

Each box was the other's outside world. The connections went to a listener
that closes at once, as a real server does. A listener that holds every
connection open fills the 256 cap by itself, because a connection the site
has closed stays half-open at that far end, and counts.

| What | Result |
|---|---|
| Install | `install.sh docker` and `install.sh server` put the limits in force; on A the table named s1 and s2 by `jsnet1` and `jsnet2`; on B, `www-data` (`meta skuid 33`) |
| Rate, container, one family for 15 s | 389 connections, over IPv4 and over IPv6 alike: the burst of 100 plus 20 a second |
| Rate, both families at once | 195 + 194 = 389: one limit, not two |
| A short burst of 150 | all 150 made, in 3.1 s (dropped openings retried) |
| Rate, bare metal (`www-data`) | 389 and 388 per family; 190 + 198 at once |
| The host's own connections, and root on bare metal | 500 in 0.2–0.3 s, untouched |
| `www-data` over loopback (its database, Apache over `::1`) | 500 and 500, no drop |
| Cap | s1 held exactly 256 open over IPv6; the rest dropped and counted; its pages answered over both families throughout; s2 meanwhile made its own connections, untouched |
| UDP | DNS to 8.8.8.8 and to Google's IPv6 resolver unanswered from s1 and from `www-data` (refused at once on bare metal: `Operation not permitted`), counted; the sites' own lookups answered; the host's own UDP answered |
| Visitors to the bare-metal site | 200 of 200 page loads answered; its drop count unchanged |
| Outside resolver in `/etc/resolv.conf` | the unit failed, the table was removed, the host report said `refused` / `resolver_not_loopback` and named the failed unit, sites read `none`; s1's lookups kept working; the resolver put back, the limits came back with counters from zero |
| `install.sh docker --no-outbound-limits` on the existing host | the host file says `enabled: false`, the table gone, s1's UDP answered; `joinery-limits on` dropped it again |
| A site still on Docker's default network | named by `status` with the move command |
| `remove_account.sh s2` | `DECOMMISSION_VERIFIED`; s2's chain and counter gone |
| Host report | `outbound_limits` on with its since; each site's `outbound_dropped` (s1 241, s2 12 at that point) |
| Reboot, both boxes | the limits back at boot, the timer active, counters from zero with a new since; UDP dropped again on both |

Found and fixed on the way: `install.sh` run again without the flag, on a
machine whose limits were off, kept them off but printed "in force". It now
reports what the unit did (the `state=` it wrote).


### WP4 — A speed ceiling per site

On every install. Docker has no bandwidth flag, so this is traffic shaping
(`tc`).

**The ceiling holds everything the site sends.** Visitors reach a container
site through the host's proxy, so a visitor's Drive download leaves the
machine from the proxy, not from the site's subnet. Shaping the machine's
public interface by source subnet would miss it. Instead, for a container
site the ceiling sits where the site's traffic enters the host: on the site's
bridge, with the bridge's incoming traffic redirected to an `ifb` device that
carries an HTB class for the site. Replies to the proxy and connections the
site opens itself both pass through it, over both families.

For a bare-metal site, the web server sends visitors' downloads itself. The
host marks packets from the web server's user on the output path and shapes
the mark on the public interface (an HTB root on it, the mark to the site's
class by an `fw` filter, everything else to an unshaped default class).
Apache's replies to visitors carry the web server's user too: a connection
Apache accepts belongs to the worker that took it, and only the opening
handshake goes out as root (measured: 9,190 packets of a download as
`www-data`, 2 as root).

The host's own reads of a container site's database through its
loopback-published port also cross the bridge and are shaped. They are an
operator's tunnel and nothing else (backups run inside the container), so
they are left in. A filter that let them through by source port 5432 was
tried and works, but a hacked site could send anything from port 5432.

- The ceiling is **200 Mbit/s**. A multi-tenant box's sites get the box's
  lower figure (`site_outbound_limits`). A site's admin can lower it from the
  settings page; raising it or turning it off takes a command on the server
  (WP5).
- The machine's own traffic (the host agent, its updates, the proxy itself)
  is never shaped.
- Traffic to the site (uploads by visitors) is inbound, free, and not shaped.

The ceiling does not keep a machine inside its allowance; WP1 does that by
telling someone. The ceiling bounds what a runaway costs while they look.

### WP5 — Where the figures live, and surviving reboots

Already built with WP3:
- the unit, its timer and the boot run;
- the host file with its `enabled` key;
- `--no-outbound-limits` and `joinery-limits on|off|status`;
- the run from `install.sh site`, the move script and `remove_account.sh`.

WP5 adds the figures, the per-site lines in the run spec, the rest of the
flags and commands, and the site setting.

**A site can tighten its limits, never loosen them.** Anything the site's
settings page can write, the site's own code can write too: a plugin running
as `www-data` has the same database access. A ceiling the site could raise
would be lifted by the very hacked plugin it exists to slow. So every
loosening comes from outside the site, and the site's own setting only
counts when it is lower.

**The highest figures live where only root writes,** at two levels:

- **The machine's defaults,** in a host file (`/etc/joinery/outbound_limits.json`).
  With no file, the unit's built-in defaults apply: 200 Mbit/s, 20 new
  connections a second with bursts to 100, and 256 open at once.
- **A site's own figures,** where its other caps are kept: a container
  site's run spec, beside `--memory`, `--cpus` and `--pids-limit`, so a
  rebuild, rebase or move keeps them; a bare-metal site's entry in the host
  file. A site with none uses the machine's defaults.

**Every figure is settable at install time.** The flags are the same on
`install.sh server`, `install.sh docker` (the machine's defaults, written to
the host file) and `install.sh site` (that site's own figures):

| Flag | Sets | Default |
|---|---|---|
| `--outbound-ceiling=MBIT` or `=off` | the speed ceiling (WP4) | 200 |
| `--outbound-conn-rate=N` | new connections a second (WP3) | 20 |
| `--outbound-conn-burst=N` | the burst above it | 100 |
| `--outbound-open-conns=N` | connections open at once | 256 |
| `--no-outbound-limits` | every limit off, UDP drop included | — |

`install.sh site` also takes `--outbound-notice-gb=N`, which seeds the site's
monthly notice setting (WP1); it is a site setting, not a limit, so the
machine-level commands do not take it. The flags are validated like
`--memory`: a whole number, or `off` where offered, refused in plain words
otherwise. An install with no flags gets every limit at its default, which is
the point: an owner who never thinks about bandwidth is protected anyway.

**After install,** `joinery-limits` changes the same figures without a
reinstall: `sudo joinery-limits ceiling 500`, `sudo joinery-limits ceiling
off --site=SITENAME`, `sudo joinery-limits show`. It writes the host file, or
the site's run spec through the same path `install.sh site` uses, and runs
the unit.

**Who sets them follows who pays the bill:**

| Install | Who sets the figures |
|---|---|
| Self-hosted | the owner: install flags, then `joinery-limits` |
| Managed by the plane, on our provider account | the plane, from plane settings in `plugins/server_manager/plugin.json`: it passes the flags when it provisions the node, and runs `joinery-limits` through the host agent when they change |
| Multi-tenant box | the plane, from the box's columns (`site_outbound_limits`), by the same two paths |
| Our own nodes | provisioned with `--no-outbound-limits`; the node page's switch turns the limits on |

**The site's own setting**, declared in `settings.json`:
`outbound_speed_ceiling_mbit` (default empty, meaning the host's figure).
The unit applies whichever is lower, the host's figure or the site's, so a
value written by the site's code can only slow the site down. The settings
page shows the host's figure, lets the admin set a lower one, and to go
higher shows the exact command to run on the server, with plain words on what
it risks: a site that is hacked, or suddenly popular, can then run up the
provider's bill as fast as the machine's link allows. On a managed or
multi-tenant node it says the figure is set by the host instead.

The connection rate, burst and open-connection cap are not offered on the
settings page: real use never meets them. An owner who knows why changes them
with the install flags or `joinery-limits`.

`outbound_monthly_notice_gb` (default 1000) stays a plain site setting
(WP1). A hacked site could silence its own notice, as it could fake the count
behind it; the notice informs the owner, and the guards are the ceiling and
the connection limits, which the site cannot loosen.

**The unit.** One host-side unit writes the nftables table and the `tc`
classes, from the host file, and from each site's run spec: its network and
its own figures. It
reads each site's own ceiling from the site (`docker exec` on a Docker host,
the site directly on bare metal) and treats what comes back as untrusted: a
whole number, or nothing, and applied only when lower than the host's figure.
It runs:

- at boot after Docker;
- whenever a site is created, rebuilt or removed (`install.sh site`, the
  rebase and move scripts, `remove_account.sh`);
- when the plane's figures change;
- when `joinery-limits` changes the host file;
- every five minutes on a timer, so an admin's lower ceiling takes effect
  within minutes. A site cannot ask the host to run anything; the host
  reads.

Before writing anything it checks that the host's resolver is a loopback
address (WP3). It replaces its own table whole each time, so a run leaves no
rule from a removed site behind. None of the table, the `tc` classes or the
`ifb` devices survives a reboot, so the unit is what puts them back.
`install.sh` installs it on every install, server or Docker.

## Measured on scratch boxes, 2026-10-06

Two Nanodes in `us-east` (Ubuntu 24.04, Docker 29.8.2): A ran
`install.sh docker` and one container site from release 0.8.460 with a host
proxy in front of it; B stood in for a bare-metal site (Apache and PHP-FPM as
`www-data`) and for the outside world. Rules and `tc` were written by hand,
as the unit will write them.

| What | Result |
|---|---|
| Linode's figure, same-data-center IPv6 | not counted, in or out: 5 GB host-to-host and 3.5 GB from a container left the figure unchanged |
| Linode's figure, same-data-center IPv4 | counted |
| Linode's figure against the interface counter | 1 GB of payload sent over IPv4 moved the figure by 1.002 GB and `/proc/net/dev` by 1.077 GB: Linode counts about the payload, the interface counter adds the packet headers (about 7% on a bulk transfer). Two such sends moved the receiving machine's `bytes_in` by 2.004 GB |
| Moving a running site to its own IPv4+IPv6 network | 120 of 120 requests answered over both families during the move; published ports followed; the database login from the host came back when housekeeping ran (0.3 s). Those IPv6 requests reached the site through the host's proxy, over IPv4: a published port reached over IPv6 needs Apache started again after the move (WP2) |
| The proxy's requests after the move, before the remote-IP fix | logged as the gateway, not the visitor (WP2) |
| Speed ceiling, container, set to 20 Mbit/s on the bridge's `ifb` | the site's own uploads 19.4 (IPv4) and 19.2 (IPv6); visitor downloads through the host proxy 19.0 and 19.0; straight to the published port 19.0 and 18.7 |
| The same, the machine's own upload | 1,209 Mbit/s, untouched |
| Pages from the site during its own saturating download | 24–34 ms, as at idle (`fq_codel` under the HTB class) |
| Speed ceiling, bare metal, 20 Mbit/s on the `www-data` mark | visitor downloads 19.1 over each family |
| Connection rate, both kinds | 150 connections in 3 s all made (the burst plus retried openings); a sustained 300 lost 36 |
| Open-connection cap, both kinds, set to 200 | 200 of 260 held open over IPv6; the rest timed out |
| UDP drop, both kinds | a DNS query to 8.8.8.8 and to Google's IPv6 resolver answered before the rule and timed out after; the site's own name lookups worked throughout |
| Docker's chains and ours together | no interference: Docker's rules still published and forwarded, ours still dropped |

**B1, found on the way (fixed 2026-10-06, as below: saving the hosted card
reads the token's scopes from Linode's `X-OAuth-Scopes` header and the card
names each one missing).** The dev plane's operator token is refused on
`account/transfer` ("Your OAuth token is not authorized to use this
endpoint"), so `HostedTrialWatch::watch_transfer_pool()` would fail on dev
the first time it ran (it has not: dev has no sold sites, and the watch
returns before reaching it). `hosted_tier.md` says the operator token needs
`account:read_write`, which this one lacks. Saving the token on the setup
page reads its account's name and quietly accepts a token with no account
access (`LinodeComputeDriver::accountName()` falls back to the user's name),
so nothing says a scope is missing. The fix belongs with that page: when a
token is entered, try a read of each endpoint the plane uses it for
(`account/transfer` among them) and name, in the page's message, any the
token is refused. Whether the production plane's token has the scope is not
known from here.

## Why not a Linode product

- **Cloud Firewall** filters per machine by address, port and protocol. It
  cannot tell the sites on a machine apart, and it has no rate or bandwidth
  limits.
- **NodeBalancer** handles traffic coming in from visitors. A site's own
  outbound traffic never passes through it.
- Either would tie the limits to Linode (the Linode exit principle). Rules on
  the host move with us to any provider. WP1 reads Linode's figure through
  the provider interface, so another provider's driver supplies its own.

## Why not Cloudflare in front

Owner decision, 2026-10-05: no. Its free plan would hide a machine's address
and cache public pages, but it does not reduce the traffic this spec is
about: mail, calendar and Drive are signed in and never cached, so every byte
still leaves the machine. It does nothing for outbound abuse. And Cloudflare
decrypts every request, so it would see mail, sign-ins and anything at
Private as it is served, and could alter served JavaScript, which is the
attack Fortress names (`starter_tier` § What the "no access to your personal
data" promise is).

## Testing

| Check | Pass |
|---|---|
| a machine uses three times its daily allowance in a day (driver mocked) | the incident opens on the node that speaks for the machine and names the others (`machine_transfer`) |
| a machine passes its proportional allowance, then its full one (driver mocked) | on pace, then the allowance passed, critical (`machine_transfer`) |
| a self-hosted site sends past its monthly notice figure, across a container restart | the month's total survives the restart; the dashboard notice shows and one email goes to its admins (`outbound_transfer_meter`, fake counters; a real restart is in the live verification queue) |
| a self-hosted admin sets the ceiling to 50 | within five minutes the ceiling is 50 Mbit/s |
| the site's code writes 0, then 10000, then text, into its ceiling setting | the ceiling stays at the host's 200 Mbit/s each time |
| the owner runs `sudo joinery-limits ceiling 500`, then `off` | the ceiling is 500 Mbit/s at once, then gone; the settings page shows each |
| a managed site's admin opens the ceiling setting | it shows the plane's figure as set by the host; a lower figure applies, a higher one changes nothing |
| a fresh self-hosted install, never configured | the connection rate, UDP drop and 200 Mbit/s ceiling are all in force |
| `install.sh docker --outbound-ceiling=300`, then `install.sh site a` and `install.sh site b --outbound-ceiling=50` | site a at 300, site b at 50; b's figure is in its run spec and survives a rebuild and a rebase |
| `install.sh server --no-outbound-limits` | no limit and no UDP drop on the machine |
| `--outbound-ceiling=fast` or `--outbound-conn-rate=-1` | refused in plain words; nothing installed |
| `install.sh site c --outbound-notice-gb=500` | the site's notice setting is 500 |
| `sudo joinery-limits show` on each of the above | prints each site's figures and where each came from |
| a container site connects out over IPv6 | it leaves from the machine's IPv6 address (seen live, WP2) |
| a container site uploads to its region's Object Storage bucket | it goes over IPv6 (the bucket was reached at a same-data-center IPv6 address, WP2); Linode's figure for the machine does not grow by the upload (same-data-center IPv6 to another machine was seen not to count; the bucket's own figure is left to see) |
| an existing site on the default network is moved by `move_site_to_own_network.sh` | it answers throughout over both families, and visitors' addresses still reach it through the proxy (seen live, WP2; `site_run_spec`) |
| a site's network is missing, different, or its slot is taken by a route | made again as the spec says, refused while a container uses it, passed over (`site_run_spec`) |
| the table for a machine's sites (WP3) | each site on its own network by its bridge, none on `docker0`; the web server's user only on bare metal, loopback first; UDP, then the cap, then the rate; an unchanged run replaces nothing; a replace keeps the counts; an outside resolver refuses and removes the table; the host file turns it off and on (`outbound_limits`) |
| the host report and the plane read the drops (WP3) | each site's count, none where not limited, unknown without root; the drops between two reports, through a container restart, from zero after a reboot (`host_report`, `job_result_processor`) |
| a site opens 1,000 connections a second to outside addresses, container and bare-metal | it gets about the rate limit; the rest are dropped and counted (seen live, WP3) |
| the same site opens connections over IPv4 and IPv6 at once | together they get the one rate limit, not twice it (seen live, WP3) |
| a site holds connections open past the cap | new ones are refused; its pages still answer visitors (seen live, WP3) |
| a site sends UDP to an outside address, and DNS straight to an outside resolver, over each family | all dropped and counted; its own name lookups still work (seen live, WP3) |
| the host's `/etc/resolv.conf` is pointed at an outside resolver | the unit refuses to write the rules and says why in the host report (seen live, WP3) |
| a visitor downloads a large file from a managed site | it arrives at about 200 Mbit/s |
| a site's visitor download and its own upload at once | together they stay at the ceiling |
| a site takes a full backup to B2 | it finishes with no drops |
| the host reboots, and a site is rebuilt and rebased | its limits and ceiling are back |
| the host agent under a site's flood | the machine's own traffic is unaffected |

## Docs to update when this lands

- `docs/installation.md`: the connection limits and speed ceiling every
  install carries, and § Docker Deployment: each site's own network with
  IPv6.
- `docs/settings.md`: `outbound_speed_ceiling_mbit` (lower only) and
  `outbound_monthly_notice_gb`; `docs/installation.md`: the `--outbound-*`
  install flags, `joinery-limits`, and the host file.
- `plugins/server_manager/docs/overview.md`: the per-machine transfer watch
  and its alerts, the plane's figures, the drop counter, and the switch for
  our own nodes.
