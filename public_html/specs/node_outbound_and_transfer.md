# Outbound limits and transfer on every node

**Status:** Draft, 2026-10-05. Nothing here is built. Split out of
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

**Where we hold it,** from Linode. Linode reports each machine's transfer used this month, the allowance it
adds to the pool, and its overage (`GET linode/instances/{id}/transfer`).
`CloudComputeProvider` gains `getInstanceTransfer($instance_id)` beside
`getTransfer()`, and `LinodeComputeDriver` implements it.

Once a day the plane reads it for every machine it runs on a provider
account: managed customers' machines, multi-tenant boxes, relays, our own
nodes. It alerts an operator, naming the node:

- when a machine has used more than three times its daily allowance since
  the last read (100 GB for a Nanode);
- when it has used more than its allowance in proportion to the days gone
  (more than half by day 10, for instance);
- again past its full allowance.

This catches the one runaway machine that the account pool alert hides: the
pool is the sum of every machine's allowance, so one machine can run far past
its own share before the pool reaches 80%.

The node page shows the machine's month so far against its allowance.

Linode's own transfer alert (a percentage per machine, set through the API)
emails the account holder and uses a format Linode is retiring. Reading the
figure directly gives the plane the same fact without parsing email.

**Where we do not,** the site counts what it sends itself. A scheduled task
reads the site's own byte counter: in a container, its own network
interfaces (`/proc/net/dev`, which inside a container is the container's);
on bare metal, the machine's public interfaces. It adds the difference since
its last run to the month's total, treating a counter that went down as one
that started again (a restart or a reboot). On a single-site machine the
site is nearly all of what the machine sends, so this is close to the
provider's figure.

Past a monthly figure the site's admin sets, the admin dashboard shows a
notice and the site emails its admins once a month, in plain words: how much
the site has sent, that most providers charge past an allowance, and where
to check their own provider's. The figure defaults to **1 TB**, the smallest
allowance among common providers' small plans. The settings page shows the
month so far beside it.

### WP2 — IPv6 for containers, on every Docker host

Linode charges nothing for traffic from a machine to any service in the same
data center over IPv6, including uploads to a Linode Object Storage bucket in
the same region. Over IPv4 the same traffic counts against the pool, and a
bucket's own outbound traffic always counts, even to a machine beside it.
Today a container site has no IPv6: it sits on Docker's default network,
which is IPv4 only, so all of it would be charged.

**Every container site runs on a network of its own, with an IPv4 subnet and
a private IPv6 /64** (`fd00::/8`), both assigned by the host and recorded in
the run spec. `docker network create --ipv6 SITENAME_net`. Its outbound
traffic leaves through the host from the machine's one IPv4 address or its
one IPv6 address. Docker translates both (`MASQUERADE` in `iptables` and
`ip6tables`), with no setting in `daemon.json`. Visitors still arrive through
the host's proxy, on both.

Measured on the drive-test VPS (Docker 29.7.2, Ubuntu 24.04), 2026-10-05: a
container on a network with subnets `10.250.251.0/24` and
`fd00:250:251::/64` was seen outside as the machine's IPv4 and IPv6
addresses, and reached `us-east-1.linodeobjects.com` over IPv6 at an address
in the machine's own data center.

One network per site is also what WP3 and WP4 hang off: each site's network
is its own Linux bridge on the host, which tells one site's traffic from
everything else. On a multi-tenant host it is also what walls sites off from
each other (`multi_tenant_docker_hosts` WP5 item 1).

**Three things assume the default network (`172.17.0.0/16`) and read the
site's subnet from its run spec instead:**

- `RemoteIPInternalProxy` in the image (`Dockerfile.template`). Left as it
  is, `X-Forwarded-For` from the proxy is not trusted, and every visitor
  shows up as the gateway address. Rate limits, login IP rules and analytics
  would then all see one address.
- PostgreSQL's "Docker host" line in `pg_hba`.
- The gateway lookup in `rebase_site_container.sh`.

**Existing sites** move at their next rebuild from the run spec. A site not
rebuilt by the time this ships moves in a one-time pass that attaches it to
its new network and detaches it from the default one, proven on a scratch
box first to keep the site answering throughout.

**What follows from IPv6:**

- Containers prefer IPv6 wherever a destination has it (getjoinery.com and
  B2 do; Stripe, SMTP2GO and Namecheap do not today). Anything that checks
  where a node connects from accepts its IPv6 as well as its IPv4, under the
  IPv4-and-IPv6 rule. The agent channel keys by identity
  (`multi_tenant_docker_hosts` S23).
- A machine's IPv6 address sits in a /64 that Linode shares among many
  customers' machines, and IPv6 blocklists usually list whole /64s. With
  port 25 closed to every machine we create (`own_mail_server_sending` § 4),
  this matters little. A routed /64 per machine, with an address per site,
  was considered and not taken: blocklists acting on the /64 would still
  treat the machine's sites as one.
- Every firewall rule a node carries for its sites is written for both
  families. No firewall script in `maintenance_scripts` calls `ip6tables`
  today.

Bare-metal sites need nothing: the site runs on the machine's own network
stack, which already has IPv6.

### WP3 — New connections and UDP

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
  second, bursts to 100. Over the limit, drop.
- a cap on connections open at once from the site (`ct count`).
- a counter of drops, read by the host report.

Traffic between a site and the host's own proxy (the reply path) is not
counted. A site reaching another site's public pages, or sending a Joinery
Direct delivery, goes out through the public address and is counted like any
other outbound connection.

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
the mark on the public interface.

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

**A site can tighten its limits, never loosen them.** Anything the site's
settings page can write, the site's own code can write too: a plugin running
as `www-data` has the same database access. A ceiling the site could raise
would be lifted by the very hacked plugin it exists to slow. So every
loosening comes from outside the site, and the site's own setting only
counts when it is lower.

**The highest figures live where only root writes,** at two levels:

- **The machine's defaults,** in a host file (`/etc/joinery/outbound_limits.json`).
  With no file, the unit's built-in defaults apply: 200 Mbit/s, 20 new
  connections a second with bursts to 100, and the open-connection cap.
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
| `--outbound-open-conns=N` | connections open at once | from WP3's measurements |
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
| a machine uses three times its daily allowance in a day (driver mocked) | the operator alert names the node |
| a machine passes its proportional allowance, then its full one (driver mocked) | one alert at each |
| a self-hosted site sends past its monthly notice figure, across a container restart | the month's total survives the restart; the dashboard notice shows and one email goes to its admins |
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
| a container site connects out over IPv6 | it leaves from the machine's IPv6 address |
| a container site uploads to its region's Object Storage bucket | it goes over IPv6; Linode's figure for the machine does not grow by the upload |
| an existing site on the default network is moved by the one-time pass | it answers throughout, and visitors' addresses still reach it through the proxy |
| a site opens 1,000 connections a second to outside addresses, container and bare-metal | it gets about the rate limit; the rest are dropped and counted |
| the same site opens connections over IPv4 and IPv6 at once | together they get the one rate limit, not twice it |
| a site holds connections open past the cap | new ones are refused; its pages still answer visitors |
| a site sends UDP to an outside address, and DNS straight to an outside resolver, over each family | all dropped and counted; its own name lookups still work |
| the host's `/etc/resolv.conf` is pointed at an outside resolver | the unit refuses to write the rules and says why in the host report |
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
