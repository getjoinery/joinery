# Site Copy — Switch by DNS, and a Hetzner Server Driver

**Status: DEFERRED (owner, 2026-10-03) — split out of the site copy spec; not scheduled.**
The site copy spec builds the copy, the switch-over by a proxied origin change (WP7a), the switch
by IP swap (WP12) and the copy from backups (WP10). This spec holds the two pieces that make the
copy work between any two providers, kept for when leaving Linode becomes the goal:
- **WP7 — the switch by DNS,** with the frozen source forwarding visitors until DNS settles;
- **WP13 — a Hetzner compute driver,** so the management node can create the new server itself.

Everything here builds on the site copy spec's pieces unchanged: the dormant copy, the quiet state,
the final copy and exact census, `take_node_id` and the row swap, and the way back. Only the step
that moves the address (step 9 there) and the creation of a Hetzner server are new.

**Related:**
- The site copy spec: the parties (S the source, T the copy, M the management node), the quiet
  state, steps 1–10, and D3 (why each switch exists).
- `includes/dns/drivers/HetznerDnsDriver.php` is already on Hetzner's Cloud API (B24 there), with
  mocked tests only: no Hetzner account existed when it was written.

## Why (D3, carried)

- **The exit capability.** Leaving Linode for Hetzner (or anyone) must stay a real option, so there
  is leverage with Linode and no lock-in. The IP swap works only inside one Linode account and
  region; the proxied origin change only for a site behind a proxy. DNS works between any two
  servers.
- **Pros:**
  - Works between any two servers.
  - No visitor sees an error while caches expire, because the old address forwards to the new.
- **Cons:**
  - S stays up, forwarding, until DNS settles.
  - The facts bound to S's address are a list to work through. M does its own; the owner does the
    rest.

## WP7 — The switch by DNS

**Design (the site copy spec's step 9, the DNS method):**
- M lowers the TTLs of the records naming S's address during the copy, so the wait at the switch
  is short.
- At step 9, S's quiet-state drop-in turns from the maintenance page into forwarding to T, and T
  trusts `X-Forwarded-For` from S's address through Apache's `mod_remoteip` in T's vhost, so its
  per-address throttles see real visitors with no change to the site's code.
- M, or the owner, changes the records, and M waits until public resolvers agree plus one old TTL.
- Postfix on S keeps deferring, so mail retries until MX reaches T.
- After step 10, S stops forwarding once DNS has settled, and is powered off still `quiet
  switchover`. If it is ever booted, it never runs the site.
- The way back after step 10 puts the records back by DNS the same way.

**Pieces:**
- TTL lowering, the record change and the resolver watch.
- Forwarding from S's drop-in, with `mod_remoteip` trusting S's address in T's vhost (no site code
  change).
- **The IP-bound list:** every fact naming S's address that the move does not carry, for the owner
  (reverse DNS, SPF `ip4:`/`ip6:`, the relay's and the domain registrar's allow-lists, provider
  firewalls).
- **Container sources onto bare metal.** The eight docker-prod sites share one host address, so no
  IP swap is possible for them; DNS is their switch.
  - Their certificate, DKIM keys and proxy vhost live on the host.
  - Their cron belongs to the container's start command.
  - The copy's preflight refuses a container source until this lands.

**Phase 2 (a dead source)** already switches by DNS with no forwarding, by the owner's own
change (the site copy spec's `manual` method): there is no S left to forward, so visitors whose
resolver still names S's old address fail until it settles, and the TTL matters. WP7's record
change and resolver watch would let M make that change and wait for it, as they are.

## WP13 — A Hetzner compute driver (convenience)

- `POST /servers` with the returned root password, never an SSH key; `change_dns_ptr`; locations.
- Its transfer counter is per server, which does not fit the provider interface's pooled
  `getTransfer()`.
- Until it exists, the owner creates the Hetzner server and runs the join command the Copy tab
  shows ("I'll bring a server"), which already works on any Ubuntu server.

## Test plan

- **Gate (db tier):** the DNS switch's sequence against fake DNS drivers (Linode, Cloudflare and
  Hetzner): TTLs lowered, records changed, the resolver watch's wait, the way back. The Hetzner
  compute driver against a fake Cloud API.
- **L1 — Linode to Hetzner: copy, look, switch over by DNS.**
  - S is a test site on Linode, managed by dev.
  - T is a Hetzner server (made by WP13, or by the owner with the join command).
  - The test domain is a direct (unproxied) record under `jeremytunnell.info`.
  - The source is seeded for coverage as in the site copy spec's L0 (sealed secrets, a passkey, a
    vault, raw mail, an offloaded file, a custom theme, a DKIM key).
  - Pass:
    - The census is equal and there are 0 dead secrets.
    - The same browser signs in with the passkey and unlocks the vault.
    - The offloaded file downloads, and mail pickup resumes.
    - T's backup runs under the same node, and one agent polls.
    - While DNS settles, a request to S's old address reaches T.
    - S is powered off and whole.
    - The certificate is unchanged.
    - The downtime is measured, against the site copy spec's D2 estimate.
