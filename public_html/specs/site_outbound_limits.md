# Outbound limits per site on a multi-tenant host

**Status:** Draft, 2026-10-05. Nothing here is built. Split out of
`multi_tenant_docker_hosts`, whose CPU, memory, disk and process limits leave
outbound traffic as the one resource a site can still use against the outside
world and against our bill. To be worked on after that spec's WP5 and after
`node_outbound_and_transfer`.

## What this does

`node_outbound_and_transfer` gives every install a transfer watch per
machine, IPv6 for its containers, a connection rate and UDP drop per site,
and a speed ceiling per site. On a managed VPS the machine is the site, so
that is enough. On a multi-tenant box twelve sites share one machine, one
address and one allowance, and two things more are needed:

| Piece | What the site gets | What happens at the edge |
|---|---|---|
| A lower speed ceiling | about 20 Mbit/s for everything the site sends | the site's transfers slow down; neighbours keep theirs |
| A share of the box's month | about 300 GB, counted per site | an operator alert names the site; nothing is cut off |

A multi-tenant site exists to host one person's or one small group's mail,
calendar and Drive. **No feature is switched off.** The ceiling leaves that
use untouched and allows little else.

The ceiling does not keep a site inside its share. It buys time: a site
sending flat out reaches its share in about a day and a half, not in hours.
The count of what each site sends (WP2) and an alert catch it inside that
window, and an operator decides what to do.

## The box, in numbers

The starter tier's box is a Linode shared 4 GB: **4 TB of outbound transfer
a month, 4 Gbps outbound, $0.005 per GB past the allowance**, 12 sites
(`starter_tier` § The box).

| Figure | Arithmetic | Starting value |
|---|---|---|
| A site's monthly share | 4 TB ÷ 12, less 10% for the box's own traffic | **300 GB** |
| A site's speed ceiling | what personal use needs: a new device's first Drive sync of a full 3.5 GB disk allowance takes about 25 minutes | **20 Mbit/s** |
| A site's most in a day | 20 Mbit/s for 24 hours | 216 GB |
| Time to spend its share at the ceiling | 300 GB at 20 Mbit/s | about 33 hours |
| Every site flat out at once | 12 × 20 Mbit/s = 240 Mbit/s, 6% of the link | the box's 4 TB lasts about 1.5 days |

## Work packages

### WP1 — The box's figures

New columns on `mgh_managed_hosts`, set on the host's edit page beside the
other caps: each site's speed ceiling and each site's monthly share. Empty
means the plane-wide figures of `node_outbound_and_transfer`. Every site on a
box gets the same ones.

The edit page offers the worked figures from the box's plan and `max_sites`
(its allowance ÷ sites, less 10%), so a change of plan or of `max_sites`
changes them with it. The plane passes them as the
`--outbound-*` flags when it builds the box and places each site, and runs
`joinery-limits` through the host agent when they change
(`node_outbound_and_transfer` WP5).
A site's admin can set a lower ceiling for their own site, never a higher
one.

### WP2 — Counting what each site sends

The count already exists. The host report sends each container's
`net_tx_bytes`, every byte it has sent on any interface but loopback since it
started (`host_report.sh`, `container_figures`). That covers its replies to
the proxy, which is what visitors' downloads leave the box as. It differs
from what Linode bills in five ways:

- the interface counter includes packet headers and Linode's figure does not
  (measured 2026-10-06: 1 GB of payload moved Linode's figure by 1.002 GB
  and the interface counter by 1.077 GB), so a site's count runs about 7%
  high on bulk transfers;
- the proxy adds TLS and headers on the way out (a few percent more), and
  compresses what the site did not (less);
- name lookups leave from the host, not the site
  (`node_outbound_and_transfer` WP3), so they are in the box's own traffic;
- traffic Linode does not charge for, over IPv6 to a service in the same
  data center (a same-region bucket, a relay there), still counts. Linode's
  figure leaves it out in both directions (measured: 8.5 GB of it left a
  scratch box without moving the figure). A site's count runs higher than
  its bill, never lower;
- the counter starts again when the container restarts. The plane already
  turns two reports into a rate using `started_at`, and does the same here.

The plane:

- sums each site's sent bytes per Linode billing month;
- alerts an operator, naming the site, when a site sends more than 30 GB in
  24 hours (about three times its daily share), and when its month passes
  its share;
- shows each site's month so far against its share on the node page.

**A cross-check, one way.** The node page shows the sites' counts plus the
box's own traffic beside Linode's figure for the machine
(`node_outbound_and_transfer` WP1). The counts are expected to run above
Linode's figure (headers, and free same-data-center IPv6 such as backups to
a same-region bucket), by any amount. Only the other direction is flagged:
Linode's figure more than 20% above the counts means traffic is leaving by a
path the count does not see.

`hosted_abuse_response` gains the alert as an outlier signal. What an
operator does about a site past its share is that spec's business.

## Testing

On a scratch multi-tenant host with three sites:

| Check | Pass |
|---|---|
| a site uploads to an outside server as fast as it can | it stays at the box's ceiling; a neighbour's downloads and page times stay within 2× idle |
| a visitor downloads a large Drive file from a site | it arrives at about the box's ceiling |
| a site opens 1,000 connections a second | a neighbour's outbound connections are unaffected |
| a site sends a Joinery Direct delivery to a neighbour on the same box | it arrives |
| a site sends 30 GB in a day | the operator alert names it |
| the sites' counts plus the box's own, against Linode's figure for the machine | Linode's figure is not more than 20% above the counts || the box's ceiling is changed on its edit page | every site's ceiling changes live, with no restart |
| the host agent and the proxy under one site's flood | the box's own traffic and the other sites' pages are unaffected |

## Docs to update when this lands

- `docs/installation.md` § Docker Deployment: the multi-tenant host's lower
  ceiling and per-site share.
- `plugins/server_manager/docs/overview.md`: per-site month against share,
  and the box's figures on its edit page.
