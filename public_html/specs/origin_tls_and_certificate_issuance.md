# Origin TLS and certificate issuance

**Status: DEFERRED BY OWNER 2026-08-30 — written now, built later.**
Raised while auditing the local-queue retirement; deferred deliberately so the
agent migration keeps the floor. Nothing here is urgent in the
site-is-down sense: every affected site serves fine today. What is wrong is
the strength of one hop and the existence of a bypass.

## The problem in plain terms

A visitor's connection to one of our sites is encrypted twice: browser to
Cloudflare, then Cloudflare to the VPS. The first hop is fine. The second hop
is where the gap is — for five of eight sites the VPS answers with a
certificate belonging to a different site, so Cloudflare cannot be checking
who it is talking to. It encrypts the traffic and accepts whatever answers.
Separately, the VPS answers the public internet directly on ports 80 and 443,
so the edge can be skipped entirely by connecting to the IP.

Neither is visible to a visitor. Both mean the protection is thinner than the
padlock implies.

## Measured, 2026-08-30

All eight containerised nodes share Docker host `23.239.11.53`. Probing the
origin directly with each name in SNI:

| Name | Fronting | Certificate the ORIGIN presents |
|---|---|---|
| `developers.getjoinery.com` | direct to origin | its own (Let's Encrypt) |
| `demo.getjoinery.com` | Cloudflare | its own (Let's Encrypt) |
| `orgs.getjoinery.com` | Cloudflare | its own (Let's Encrypt) |
| `getjoinery.com` | Cloudflare | `developers.getjoinery.com` — mismatch |
| `scrolldaddy.app` | Cloudflare | `developers.getjoinery.com` — mismatch |
| `galactictribune.net` | Cloudflare | `developers.getjoinery.com` — mismatch |
| `mapsofwisdom.org` | Cloudflare | `developers.getjoinery.com` — mismatch |
| `phillyzouk.org` | Cloudflare | `developers.getjoinery.com` — mismatch |

The mismatch is Apache falling back to its first SSL vhost: those five have no
SSL vhost of their own. Consequences, in order of how much they matter:

1. **Cloudflare cannot be in Full (Strict)** for the five — strict rejects a
   name mismatch. So that hop is encrypted but unauthenticated: Cloudflare
   will accept any certificate presented, including an interposed one.
2. **Ports 80 and 443 are open to the internet** on the origin IP (verified).
   Anyone who learns the address reaches the sites without passing the edge,
   which is also how any WAF, rate limit or bot rule at the edge gets skipped.
3. `certbot.timer` on the host is enabled and active (12-hourly, plus
   `/etc/cron.d/certbot`), so the three certificates that DO exist renew
   without help. That part is healthy and needs no work.

**A correction this spec exists to preserve:** the demo and orgs certificates
were briefly read as vestigial leftovers for names now served at the edge, and
a `certbot delete` was proposed for them. That was wrong — they are the
origin-leg certificates for a live serving path, and deleting them would
downgrade the two sites currently doing this correctly. Anyone tempted to tidy
the host should read the table above first.

## What "end to end" requires

Three layers. The certificate alone is the first and least of them.

1. **A certificate per name on the origin.** Removes the mismatch.
2. **Cloudflare set to Full (Strict).** Without this the origin certificate is
   decoration: non-strict accepts anything, so the second hop stays
   unauthenticated no matter how correct the certificate is. This step is what
   converts encryption into authentication.
3. **Authenticated Origin Pulls, plus 80/443 firewalled to Cloudflare ranges.**
   Makes the origin refuse anything that did not come through the edge. Without
   it the bypass in finding 2 survives every certificate fix.

A deployment that is not behind a CDN at all (`developers.getjoinery.com`
today, and any customer node) needs only layer 1; layers 2 and 3 are
Cloudflare-specific and belong to whatever fronting a deployment chooses.

## How issuance should work: DNS-01

**Use DNS-01, not HTTP-01.** HTTP-01 needs the ACME challenge to survive a
round trip through the edge. It usually does, which is how demo and orgs got
their certificates — but it breaks under Under Attack mode, cannot issue
wildcards, and makes renewal depend on the very edge we are trying to stop
depending on. DNS-01 proves control by writing a TXT record, so it works
whether a site is proxied, firewalled or offline.

**The machinery already exists and is not certificate-specific.**
`includes/dns/` carries fifteen provider drivers including
`CloudflareDnsDriver`, TXT support, CAA handling (`docs/dns_management.md`
notes a wrong CAA record blocks issuance the same silent way a missing
challenge record does), ownership tracking in `dnr_dns_records`, and
`DnsPublishConsumer`, whose OAuth grant does not outlive the request. DNS-01
is a consumer of that subsystem, not a new one.

**Where issuance runs — the design decision.** The node generates the private
key and a CSR; the plane performs the DNS-01 challenge and returns the signed
certificate; the node installs it. This keeps all three doctrine lines intact:
the private key never leaves the machine that serves it, the node never holds
a DNS credential, and the plane holds nothing that opens a node. The obvious
alternative — issue centrally and ship key + certificate to the node — is
rejected: it puts a private key on the wire and in the plane's memory for no
gain.

## What this replaces

**R3 (Docker-host certificates)** in
`agent_machine_posture_and_relay_converge.md` should be reconsidered rather
than built as written. R3 teaches the agent to run certbot on the Docker host,
which keeps the inbound-HTTP dependency and keeps the container/bare-metal
split. DNS-01 issuance plane-side with an `install_certificate` primitive
removes both: the same path works for bare metal, containers and
Cloudflare-fronted nodes, and the `is_bare_metal` / `on_host` branching in
`build_provision_ssl` collapses.

This also improves **G2** in `agent_local_queue_retirement.md`. G2 is "new
certificate issuance for a directly-served container node has no transport
once the local queue is gone." DNS-01 answers it better than R3 did, because
issuance stops needing anything inbound at all.

## Work

**WP1 — Close the mismatch on the five sites.** Issue a per-name certificate
on the origin and give each an SSL vhost. Can be done with today's certbot,
by hand, independent of everything below. This is the whole of the live
defect.

**WP2 — Turn on Full (Strict)** per zone, after WP1 and not before: enabling
it while a mismatch stands takes those sites down.

**WP3 — Authenticated Origin Pulls and the origin firewall.** Closes the
bypass. Needs a decision about how a legitimate direct-served node
(`developers.getjoinery.com`) is exempted.

**WP4 — DNS-01 issuance through the platform's DNS drivers**, node-generated
CSR, plane-side challenge, `install_certificate` primitive. This is the piece
that makes the whole thing native and renewable without the edge, and the
piece that supersedes R3.

**WP5 — Retire `build_provision_ssl`** and its `local` + `ssh` steps, which
WP4 makes unnecessary. Removes one of the thirteen local-queue dependants.

## Acceptance

- Every site name presents its own valid certificate at the origin under SNI.
- Cloudflare is Full (Strict) for every proxied zone.
- The origin refuses a connection that did not come through the edge, except
  for nodes deliberately served direct.
- A certificate issues and renews with no inbound HTTP to the node.
- `grep` finds no `local` or `ssh` step in any certificate path.

## Related

- `agent_local_queue_retirement.md` — G2, which WP4 dissolves
- `agent_machine_posture_and_relay_converge.md` — R3, which WP4 supersedes
- `docs/dns_management.md` — the driver subsystem WP4 consumes
- `agent_management_first_principles.md` — the credential doctrine WP4 obeys
