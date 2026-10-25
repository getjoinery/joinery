# Site Copy — Anything to Anything, and Done

**Status:** Built, and passed live on the test plane (2026-10-25; one-data-root); moved to implemented with
`site_copy.md` and `site_copy_final_test.md`. WP0–WP5 built and gated, released in 0.8.505 and 0.8.506. WP6 is
the final test's: every run C1–C19 passed, which covers the proofs below but L4, and
`plugins/server_manager/tests/site_copy_live_test.php` is the repeatable one. L4 (jeremytunnell) is the second
move of `site_copy_production_moves.md`.
WP6's rig: `seed_copy_source.php` and `check_copy_target.php` (plugins/server_manager/tests/lib), proven on node-n
and src1 on 2026-10-19; `CopySecurityCheck` with `utils/copy_security_check.php`; and the probes, which became the
`copy_probes` agent word (agent 1.84.2, `sysadmin_tools/copy_probes.sh`; owner's decision 2026-10-19, since a
plane-made copy has root login off), with the B37 loosening alone left to `tests/lib/check_loosen_restored.sh`
over the test plane's own login. The seed leaves
out the vault (each run's browser made it, with the passkey) and the custom theme (not seeded). The runs were
driven by hand on the rig, each on record; the one declared live test is the smoke test (owner, 2026-10-25).
Code review by reviewer2 (2026-10-18, B1-B14) all fixed: the resolvers asked in RFC 8484 wire format on 443
(Quad9's JSON port 5053 is closed from dev); the watch ends at MAX_WAIT whatever they say; the copy proven at
its own address whether or not anything forwards; a halted settle can be retried or kept; TTLs lowered last
and put back by any way back; housekeeping expires a forwarder past its end; a brought old server is quieted
at settle; Hetzner's swap waits on each action with both servers off; the forward sends http to https;
shared and reserved IPv4 refused as a forward address.

- **WP0:** the agentless host rows are named once, together, on the Copy tab; the quiet-state
  paragraph carries the forward; `site_copy.md`'s IP swap and from-backups packages say they passed
  live. `copy_database.sh` exists (`sysadmin_tools/`): its row stays. B31–B33 were committed.
- **WP1:** `ComputeDriverRegistry` (includes/cloud_compute); `CloudInstanceTypes` and
  `CloudTokenScopes` join the interface; every B5 site asks the registry. The relay keeps its own
  Linode driver (born on Linode by design). Gate: `compute_driver_registry_test.php`.
- **WP2:** alive or dead is `scp_from` for every target (`runs_from_backups()` is how a run gets
  its data); `SiteCopyMethods` (D6); a running site's agent must have polled within two hours;
  `machine_wide_refusals()` (D8, with a `sites` list added to `host_report` 1.32); a distributed
  region refused before the copy's server is made. Gate: `site_copy_matrix_test.php`.
- **WP3:** `DnsRecordMove`, `PublicResolvers` (DNS-over-HTTPS), the watch and the settle phase
  (`settling`); the forward (`site_quiet forward`, `site_quiet_host forward`) and `trust_forwarder`
  (agent 1.84.0). The IP-bound list (`SiteCopyAddressFacts`, found missing in the final test's C3
  and built then) and the copy's reverse DNS set once a move settles. Gates:
  `site_copy_dns_switch_test.php`, `site_quiet_gate.sh`, `site_quiet_host_gate.sh`,
  `trust_forwarder_gate.sh`.
- **WP4:** a running container copies anywhere, frozen through its host; the way back after
  take-over on a host (B7). Gates: the site copy suites, updated.
- **WP5:** `HetznerComputeDriver`; a Hetzner account on the Hosting tab. Gate:
  `hetzner_compute_driver_test.php`. Needs Q1 for its live proof.
- **WP6** (the rig and live proofs) is `site_copy_final_test.md`'s, done; **WP7** is this move to
  implemented.

**Where the build differs from the design, decided while building (2026-10-18):**

- **The forward is a reverse proxy, not a redirect** (D1). A redirect cannot name the copy under
  the site's name; the frozen source proxies every request to the copy's address over https, the
  copy's certificate checked against the site's own name, so a visitor's request and cookies reach
  only a server holding the site's certificate (the ACME challenge path goes over http, so a copy
  without one can be issued one). The agent takes only a public unicast address.
- **TTLs are lowered at the switch's start, not the copy's** (D1): a DNS token is never kept, and
  the switch's first press is the first to have one. The watch waits one old TTL anyway, and the
  forward covers every cache meanwhile. Keep asks the token once more to put the TTLs back.
- **A machine whose agent cannot forward** (older than 1.84.0) does not stop the switch: it goes
  ahead without the forward, and says so.
- **The watch** runs only where something is let go once it settles (a forward, or the old
  server's power); Keep waits for it.
- **The Hetzner swap moves IPv4 only** (D3, D9): Hetzner writes a server's IPv6 into its network
  configuration at its first boot, so a moved IPv6 would not be taken up without a change on the
  machine. The AAAA note covers it as at Linode.
- **No project name, no cleanup at Hetzner** (D3): Hetzner's API names no project, so an account
  is added under the name typed (and a replaced key must reach the same servers), and with no
  company there is no test-account flag, so `CloudAccountCleanup` is not implemented.
- **An old container is held stopped** at the end of the start, or once a DNS move settles while it
  forwards (D4); a dead container's host is asked for `hold_container` at the switch, not at
  preflight (its host may be gone with it).
- **Going back from a copy onto a host after it took the site** holds the copy's container
  stopped on its host for its owner to remove, with what was written on it (D7).

**Owner's goal (2026-10-17):** finish copy for good. Any site copies to any
server, the whole thing is tested thoroughly, and then it is checked off and
never worried about again. This spec holds **all** remaining copy work. It
folds in the deferred *Site Copy — Switch by DNS, and a Hetzner Server
Driver* spec, whose file is removed with this one's arrival. `site_copy.md`
stays as the record of what is built (its parties, steps 1–10, the quiet
state, the census, the row swap); this spec names its pieces and changes
nothing in them that it does not say. Both move to implemented together.

## The owner's goal in plain terms

A site lives on one of five kinds of place: a server of its own, a
container on an old Docker host, a container on a site engine host, a
management node, or nowhere (dead, with backups). It should copy to any of
four kinds of place: a server the management node makes at Linode, a server
the management node makes at Hetzner, a server the owner brings from
anywhere, or a container on an engine host. And the address should follow
it by whichever way works there, with a way back until the owner says keep.

Today most of those cells refuse, for reasons that are each small, and the
only place to learn that a copy can never switch is after it is made.

## What copies today, and what refuses (read from the code, 2026-10-17)

Three ways to move the address exist (`SiteCopyRunner` 267–269):
`proxied_origin` (Cloudflare only: the one driver implementing
`DnsProxiedOrigin`), `ip_swap` (two servers this management node made at
Linode, one account, one region) and `manual` (offered only for a copy from
backups, `SiteCopyRunner` 2104). There is no switch by DNS record.

| Source ↓ / Target → | Linode, made here | Brought server (any provider) | Container on an engine host | Hetzner, made here |
|---|---|---|---|---|
| Own server, alive | proxy; IP swap; **no manual**. Freeze and final copy; way back until Keep | proxy only. A site not behind Cloudflare: "No way to move this site's address", said after the copy exists | proxy or manual; frozen through its host; way back until take-over | refused: no driver |
| Own server, from backups | proxy; IP swap; manual. No way back after take-over | proxy; manual; the owner powers the old server off | as above | refused |
| Container, alive | **refused: "copied from its backups"** (`SiteCopyRunner` 385) | refused | refused | refused |
| Container, from backups | proxy (domain and `www.` only); manual. **Not frozen: writes after the backup are lost** | same | the one built path (`site_container_engine.md` D9): frozen by its host, proxy or manual | refused |
| Management node | this one: refused always. Another: from backups only | same | preflight allows a live one, then freezes it (wrong) | refused |
| Dead site | from backups: proxy; manual; IP swap if both are Linode here | proxy; manual | preflight passes, then `site_quiet` on a dead source halts the switch | refused |

What stops the rest from working is a short list:

- **B1 — No switch by DNS record.** The exit from Linode, and the only switch for a
  live site that is neither behind Cloudflare nor swappable.
- **B2 — A live container site cannot be copied** (`SiteCopyRunner` 385), though its
  host can freeze it from outside (`site_quiet_host`, built for D9) and the on-host
  path already does.
- **B3 — "From backups" is a property of the target, not of the source.** A container
  source copied to bare metal is never frozen, so its last writes are lost; a dead
  source copied onto a host is "frozen" and halts.
- **B4 — `manual` only after death.** A live copy whose zone the management node holds
  no credential for has no way to switch, and nothing says so before the copy is made.
- **B5 — Every cloud driver is Linode's by name.** `NodeReverseDns` 174/222,
  `operator_cloud_accounts_class` 229/348/469, `HeldCloudAccounts` 139/154,
  `ProvisionCustomerCloud` 794/840, `IpSwapMove` 471–477, the Copy tab 273,
  `SiteCopyRunner` 459/654, plus `'linode'` string checks at `SiteCopyRunner` 2286 and
  `IpSwapMove` 130. Plans and regions are read from Linode whatever account is chosen,
  with `us-east` and `g6-nanode-1` as defaults.
- **B6 — No Hetzner compute driver.** `CloudComputeProvider` and its nine optional
  interfaces have one implementation.
- **B7 — The way back closes at take-over for every backups copy and every copy on a
  host**, though a frozen source could take the site back exactly as a bare-metal
  one does.
- **B8 — Small gaps:** a bare-metal source whose machine hosts other sites is not
  refused, though the quiet state and the IP swap act on the whole machine; the
  backups preflight does not require the source's agent key, so a copy can fail after
  its server is created; `go_back()` relies on the page to hide itself during
  `starting` and `undoing`; the on-host preflight never asks whether the source is alive.

## The end state

| Source ↓ / Target → | Linode, made here | Hetzner, made here | Brought server | Container on an engine host |
|---|---|---|---|---|
| Own server, alive | proxy; IP swap (same account and region); DNS; manual | proxy; Hetzner IP swap (same project and location, D3); DNS; manual | proxy; DNS; manual | proxy; DNS; manual |
| Container, alive (Docker or Podman host) | proxy; DNS; manual | proxy; DNS; manual | proxy; DNS; manual | proxy; DNS; manual |
| Another management node, alive | refused: from its backups only (`site_copy.md` out of scope), said at preflight | same | same | same |
| Dead site (any kind), from backups | proxy; IP swap if both are Linode here; DNS; manual | proxy; Hetzner IP swap if both are Hetzner here; DNS; manual | proxy; DNS; manual | proxy; DNS; manual |

Every alive source is frozen and copied one last time at the switch, whatever
the target; every switch has the way back until Keep; every cell that refuses
says why at preflight, before anything is made. One test pins this table.

**Firm lines, kept on purpose:**

- The management node that runs the copy never copies itself.
- The domain never changes (`site_copy.md` I4).
- One bare-metal site per machine, on both sides.
- A dead source's writes since its last backup are gone; the ceremony shows the age.
- A customer's own cloud account stays Linode OAuth. A customer's Hetzner is "bring a
  server".
- The Linode IP swap does not cross accounts or regions, and no swap carries IPv6.

## What changes for people

- **The owner** can move any site anywhere from the Copy tab, sees every way the
  address can move before the copy is made, and goes back from any of them until Keep.
- **The owner** adds a Hetzner account on Provisioning Setup → Hosting with an API
  token, and "Create it for me" offers it like any Linode account, with Hetzner's own
  locations and sizes.
- **A visitor** during a DNS switch reaches the site at either address: the old server
  forwards to the new until resolvers agree.
- **An operator** reads one table in the docs that says what copies where and how.

## Design

### D1. The switch by DNS record

The site copy spec's step 9, the DNS method, carried from the deferred spec and
built on what the DNS subsystem already has.

- **Which records.** Every A and AAAA record in the zone whose value is the source's
  IPv4 or IPv6 (`DnsProvider::listRecords`, `DnsRecord::$value`), proxied ones left to
  the proxy method. Both families move; a source with an IPv6 the copy lacks is a
  refusal, as the IP swap's AAAA check is today.
- **Before the switch.** At the copy's start, the records' TTLs go to 60 seconds
  (`updateRecord` with `$ttl`), so the wait at the switch is one minute, not hours.
- **At the switch.** The records' values change to the copy's addresses. The
  management node then watches public resolvers (`DnsReconciler::diffAgainstPublicDns`
  and `settled()`, which exist for the publish rail) and declares the move done when
  they agree plus one old TTL. Then the TTLs go back.
- **Forwarding.** From the moment the records change until the watch is done, the
  frozen source's quiet drop-in answers every request with a forward to the copy
  (an HTTP redirect for GET, a 307 for the rest), and the copy's vhost trusts
  `X-Forwarded-For` from the source's address through `mod_remoteip`, so the site's
  own per-address checks see visitors. Postfix on the source keeps deferring; mail
  retries until MX reaches the copy. No site code changes.
- **After.** The source is powered off still `quiet switchover`, as after an IP swap.
- **Whose zone.** The write needs a credential for the zone on the management node,
  through the existing DNS credential flow (Cloudflare, Linode, Hetzner, Azure
  drivers). With none, the method is `manual`: the same forwarding and the same
  resolver watch, with the owner making the record change at their DNS host; the tab
  shows the records to change and says when resolvers agree.
- **The IP-bound list.** The tab lists every fact that names the source's address and
  does not move: reverse DNS, SPF `ip4:` and `ip6:`, the relay's and the registrar's
  allow-lists, provider firewalls. The management node fixes its own (reverse DNS on a
  server it made, the relay map); the owner does the rest.
- **The way back** puts the records back the same way, with the watch.

### D2. The driver is chosen by the account's provider

One registry maps a provider name to its compute driver, as `DnsDriverRegistry` does
for DNS. Every place in B5 asks it; none names `LinodeComputeDriver`. `instanceTypes`
and `regions` join the interface (`CloudRegionFacts`, or a `CloudInstanceTypes`
capability), so the Copy tab's size and region lists come from the chosen account's
driver with that provider's defaults, and a provider that lacks a capability is a
plain "not offered here" on the page. Linode's `regionSupportsMetadata` and
`ensureStackScript` stay inside the Linode driver: provisioning hands every driver
the same first-boot user data, and the driver decides how its provider delivers it.

Operator cloud accounts take a provider at creation: `linode` (OAuth, as today) or
`hetzner` (an API token for one Hetzner Cloud project, sealed with `SecretBox`;
Hetzner tokens carry no scopes and no expiry, so `CloudKeyExpiry` answers "none"
and the scope check answers "all"). Customer accounts stay Linode (firm line).

### D3. The Hetzner compute driver

Hetzner Cloud API v1, one driver, pure API wrapper, no models:

- `CloudComputeProvider`: `createInstance` (`POST /servers` with `user_data` for
  cloud-init and no SSH key, so the response carries the root password the
  provisioning path already expects; `location`, `server_type`, `image`
  `ubuntu-26.04`), `getInstance`, `rebuildInstance`, `deleteInstance`,
  `shutdownInstance`, `bootInstance`, `setReverseDns` (`change_dns_ptr` on the
  server's primary IPs, IPv4 and IPv6), `getTransfer` (the sum of each server's
  outgoing traffic this month: Hetzner counts per server, the interface per pool).
- `CloudRegionFacts`: locations and server types, with the cheapest type at the
  location as the default size.
- `CloudAddressSwap`: Hetzner's primary IPs can be unassigned and assigned to another
  server in the same location and project while both are off, so a Hetzner-to-Hetzner
  copy keeps its address exactly as the Linode swap does, and its IPv6 too, since a
  primary IPv6 is a movable object there.
- `CloudProviderEvents` (the server's actions list: rescue, password reset, console,
  which `EscrowTier2` asks before opening an encrypted data root),
  `CloudMachineTransfer` (`listInstances`), `CloudAccountIdentity` (the project's
  name), `CloudAccountCleanup` (the project label and volumes), `CloudKeyExpiry`
  (none).
- Not implemented: `CloudInstanceTransfers` (Hetzner has no service transfer; a site
  leaves for the customer's own server by copy) and `CloudVolumeFacts`.

Tested against a fake Cloud API in the gate; proven on a real project in L1.
Until the driver exists, "I'll bring a server" already joins any Ubuntu server,
Hetzner's included.

### D4. A live container site copies like any other

The refusal at `SiteCopyRunner` 385 goes. A container source is frozen by its host
exactly as the on-host path freezes it today (`site_quiet on` inside the container,
then the host loads the table into its network namespace, then `backup_run`), and that
freeze serves every target. The preflight requires the source's host agent to ship
`site_quiet_host` and `hold_container`, and names them when it does not.

**The window on a Docker host, and why it is accepted.** A restart keeps the
container's own layer, so at its next start the site asserts its own quiet measures
itself (`_site_start.sh`: the 503 drop-in, the cron file held, the start gate running
no installer, the supervisor starting Apache alone). What a restart loses is the
host-loaded table in the container's network namespace. A Podman host reloads it
before the site runs (the engine's start hook runs `site_quiet_host hook`); a Docker
host has no hook, so the table is back only at the host converger's next run, within
a minute (`site_quiet_host assert`). In that minute the wall on outbound traffic is
down, but nothing inside acts as the site: no scheduled task runs without cron,
Apache answers 503 to everyone without the look cookie, a site container has no
Postfix, and the supervisor starts nothing else. The only thing that could reach out
is a page the owner opens with the look cookie. A restart during the freeze is itself
unlikely: the host's health check leaves a quiet container alone (`host_report` 1.27
answers `quiet`), the supervisor restarts services inside the container and never
the container, so only a crash of the container's own init (an out-of-memory kill
during the final backup is the realistic case, and that halts the switch anyway) or
a host reboot triggers one. After the census, `hold_container stop` sets
`restart=no` and stops the source, so from then until Keep no restart can happen at
all. Podman hosts need the hold only at Keep.

**The certificate travels as a bare-metal site's does** (owner, 2026-10-22, from the
final test's C7). A container site's lineage is on its host, which ends its TLS, so an
export made inside the container carried none and its copy started on a self-signed
certificate. Around each export the source's host stages the site's own lineage inside
the container (`site_certificate_stage stage`, agent 1.91.0) and clears it after; the
export carries it sealed to the copy and approved by the site's owner, as it carries a
bare-metal site's. Only a lineage naming the site's domain or its `www.` and nothing
else is staged, only for the domain the host serves that container at, and never the
host's ACME account, which every site there shares: the copy adopts the lineage under
its own (`provision_origin_cert`'s `adopt_carried_lineage`). The preflight requires
the word of the source's host. A copy onto a host still has its certificate issued by
that host (`provision_certificate`).

This is not a question for the owner. The alternative, leaving Docker-host sources
with only their engine-host path, carries the same window once per site (that path
freezes through the host today) and gives those sites no other target.

### D5. Alive or dead is the source's property, not the target's

A copy is "live" when its source is alive and its agent answers; it is "from backups"
when the source is dead or the owner ticks that it will not come back. The target
kind changes nothing about that. So:

- an alive source of any kind is frozen and copied one last time at the switch,
  whatever the target (B3 closes);
- a dead source is never asked to freeze, whatever the target (the on-host halt
  closes);
- another management node is refused alive at preflight on every path, with the
  reason, and copies from its backups on every path;
- the backups preflight requires the source's agent key on the node row before a
  server is made, and says so when it is missing.

### D6. Every way to move the address is known before the copy is made

Preflight computes the methods the copy will be able to use, from the source's
addresses, the zone's records and credentials, the account and region of both sides,
and the target kind, and shows them on the tab beside the target choice. A target with
no method is a refusal there, not a surprise after an hour's copy. `manual` is one of
them for every copy (D1). The list is the same table the matrix test pins.

### D7. The way back is open until Keep, for every switch

With D4 and D5, a frozen source exists for every live copy, so the way back after
take-over is the bare-metal one everywhere: quiet the copy while it is still the node,
move the address back (undo the swap, change the records back with the watch, or
ask the owner), swap the rows back, start the source again (`site_quiet off`, or
`hold_container start` then `site_quiet off`). A dead source has nowhere to go back
to, and the page says so before the switch, as today. `go_back()` itself refuses
`starting` and `undoing`.

### D8. The small refusals

- A bare-metal source whose host report lists another site or a container is refused
  at preflight: the quiet state and the power-off are machine-wide.
- The on-host preflight applies D5.
- The copy tab's "at Linode" wording becomes the provider's name from the account.

### D9. IPv6

The DNS switch moves AAAA with A (D1). The Linode swap stays IPv4 and keeps its
AAAA note. The Hetzner swap moves both. Reverse DNS set by the management node on a
server it made covers both families where the provider offers it. Everything else
about addresses is `ipv6_address_handling.md`'s.

### D10. The proof rig

A seeded source and a checker, so every live proof below is a run of the same two
scripts and a pass means the same thing each time:

- `seed_copy_source.php` (a sysadmin tool run on a fresh test site): a backup target
  with a sealed credential, an OAuth client secret, an IMAP account, a vault, a raw
  message in `storage/`, an offloaded file, a custom theme, a DKIM key, and a marker
  row with the seed's id. A passkey is registered through the browser (Playwright's
  virtual authenticator) in the live test, since no script can.
- `check_copy_target.php`: on the copy after the switch, opens every sealed value,
  downloads the offloaded file, finds the message, renders the theme, reads the DKIM
  key, and reports each as pass or fail with the seed's id, so a census-equal copy is
  also shown to work.
- Each live proof is a declared `live` test (`tier: live`, `env: dev-only`, `needs:`
  naming the rig: a cloud account, a zone token, a browser), written on the harness,
  that drives the Copy tab through the API, waits on the run, runs the checker on the
  copy, measures the downtime, and cleans up its servers. One per cell below.

## Work packages

- **WP0 — Loose ends from the built spec.** Commit B31–B33 (`RunNodeUptimeChecks`
  2.16, `host_housekeeping` 1.21, `SupportBundlePublisher` 1.24, Server Manager
  1.30.80); the agentless host rows that show on every Copy tab; the quiet-state
  paragraph in the deploy doc; the stale "not yet live" wording in `site_copy.md`'s
  status (its IP swap and from-backups packages passed live 2026-10-14 and 10-15);
  delete the wd5 test host, its node rows and DNS, keep se1. Useful alone.
- **WP1 — The driver registry and the interface (D2).** Every B5 site. Useful alone,
  and the first step of the bare-metal strategy note too. Gate: a fake second driver
  is chosen by its account; the Copy tab lists its sizes.
- **WP2 — Alive or dead is the source's (D5), the known methods (D6), the small
  refusals (D8), the matrix test.** Gate: `site_copy_matrix_test.php` builds a fixture
  node of each source kind and target kind and asserts the methods offered and the
  refusal text for every cell of the end-state table.
- **WP3 — The switch by DNS record, and `manual` everywhere (D1, D9).** Gate: the
  sequence against fake drivers (Linode, Cloudflare, Hetzner): TTLs lowered at start,
  records changed, the resolver watch's wait, TTLs restored, the way back; the
  forwarding drop-in against a real Apache in the quiet gate; `mod_remoteip` trust.
- **WP4 — A live container source (D4) and the way back everywhere (D7).** Gate: the
  freeze steps for a container source to a bare-metal target; `hold_container stop`
  ordered after the census; go back from each state.
- **WP5 — The Hetzner compute driver (D3) and the Hetzner operator account.** Gate:
  the driver against a fake Cloud API, each interface; the account form; the Hosting
  tab. Needs a Hetzner Cloud project and token (Q1) for its live proof.
- **WP6 — The proof rig (D10)** and the live tests of the matrix.
- **WP7 — Docs**, and the move of `site_copy.md` and this spec to implemented.

Order: WP0 and WP1 first (both stand alone). WP2 before WP3 and WP4. WP5 any time
after WP1. WP6 grows with each; the matrix is proven cell by cell as its packages
land.

## Questions for the owner

- **Q1 — A Hetzner Cloud account.** Not a decision: WP5's live proof and the L1 run
  need a Hetzner Cloud project and an API token. There is none today (owner,
  2026-09-28). The gate runs without it; the cell stays "built, unproven" until it
  exists.

## Testing

**In the gate (db tier), one suite per package, named above.** The matrix test is the
one that says the end-state table is the code's table.

**Live, on the rig (D10), each recorded in the live verification queue when it
passes:** the final test's runs C1–C19 cover every proof but L4, and the one declared
`live` test is `site_copy_live_test.php` (L0, with the way back and Keep). Every run: census equal,
0 dead secrets, the checker all green, the same browser signs in with the passkey and
unlocks the vault, the copy's backup runs under the same node with one agent polling,
the source is left stopped and whole, the certificate unchanged, the downtime
measured against `site_copy.md` D2. Then the way back, checked the same way, then
Keep.

| Proof | Source | Target | Switch |
|---|---|---|---|
| L0 | own server, alive, behind Cloudflare | Linode made here | proxy |
| L0b | own server, alive | Linode made here, same account and region | IP swap |
| L1 | own server, alive, Linode | Hetzner made here (or brought until WP5) | DNS; while resolvers settle, a request to the old address reaches the copy |
| L1b | own server, alive, Hetzner | Hetzner made here, same location | Hetzner IP swap, IPv6 kept |
| L1c | own server, alive, zone not held here | brought server | manual with forwarding and the watch |
| L2 | container on docker-prod, alive | Linode made here | DNS or proxy; the old container held stopped; go back starts it |
| L2b | container on a Podman host, alive | container on another engine host | proxy or DNS |
| L2c | container on a Podman host, alive | own server | DNS |
| L3 | dead site of each kind | each target kind | proxy, DNS, manual; the recovery-key ceremony on the copy's page; the key appears nowhere the management node stores |
| L4 | jeremytunnell | Ubuntu 26.04 server | proxy; the first real use (`standalone_boxes_ubuntu_2604.md`); `site_copy_production_moves.md` |
| L5 | another management node, alive | any | refused at preflight with the reason; from backups passes as L3 |

A cell the table refuses is proven by the matrix test, not live.

## Done when

- Every cell of the end-state table is either passed live, on record in the queue
  under this spec's name, or refused with the reason the matrix test pins.
- The DNS switch, the Hetzner driver and the live container copy are released.
- The docs below say what copies where, and nothing else about copy anywhere says
  less.
- `site_copy.md` and this spec are in implemented; the deferred spec's file is gone.

## Docs to update when this lands

- `plugins/server_manager/docs/overview.md` § Site copy: the end-state table; the
  four switch methods with their preflights; a live container source; the forwarding;
  the way back for every switch; the provider registry and the Hetzner account.
- `docs/dns_management.md`: the switch's TTL lowering, record change and resolver
  watch, as one more user of the DNS drivers.
- `docs/deploy_and_upgrade.md`: the quiet-state paragraph owed since 2026-10-14.
- `docs/testing.md`: the live proofs' rig under `needs:`.
- The Provisioning Setup → Hosting page's own help: adding a Hetzner account.
