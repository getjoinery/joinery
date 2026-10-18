# Site Copy — The Final Test

**Status:** Draft, 2026-10-18. Written by one-data-root with multi-tenant-security
(the security checks, the record of what copies today); reviewed by
multi-tenant-security (R1–R6) and reviewer2 (F1–F22), all applied. Nothing in it has
run. When every run below has passed, copy is declared complete, and this spec,
`site_copy.md` and `site_copy_anything_to_anything.md` move to implemented together.

## The owner's goal in plain terms

Copy a site from anywhere to anywhere, test every combination once, thoroughly,
and then stop worrying about copy. Three things are checked on every copy, not
only that the site arrived:

1. **The site works** on the copy, every secret opens, and the address follows it,
   with a way back until the owner says Keep.
2. **The security the platform promises holds** on the copy: the host's walls,
   the site's own limits, the code check, the read-only tree. A copy never
   lands somewhere weaker than where it came from.
3. **Exactly one layer of encryption.** A site leaving a server that Linode
   encrypts lands on a server encrypted by us, with Linode's layer off, and the
   management node works this out itself: nobody ticks a box, and no server ends
   up with two layers.

## Owner's decisions (2026-10-18)

- **Build first, then test.** The remaining copy work in
  `site_copy_anything_to_anything.md` (WP0–WP5: switch by DNS record, a live
  container copies, the way back for every switch, Hetzner, and the small
  refusals) is built before this test runs. This spec adds only the encryption
  work below (E1–E4) and the test itself.
- **One layer by copy wherever a copy can do it.** joinerydemo, jeremytunnell-vps
  and docker-prod's sites move by copy onto servers with our layer only, as the
  last part of this test. dev and getjoinery are management nodes, which never
  copy themselves live, so they are encrypted in place as
  `hosted_data_promise_disk_encryption.md` D7 says, keeping Linode's layer as well
  until each is replaced. That spec's D7 step 7 changes accordingly (E3).

## Open questions

- **Q1 — The Hetzner Cloud project** (`site_copy_anything_to_anything.md` Q1). C5
  and C6 need it, and Done when needs C5 and C6. Without it, copy is not declared
  complete.
- **Q2 — A second Linode account for C15**, granted to the test plane by OAuth as a
  customer's own account. C15 needs it.

## What is built for this test (beyond `site_copy_anything_to_anything.md`)

- **E1 — The layer is decided, not ticked.** The Copy tab's "Data root encrypted"
  tick goes. `SiteCopyRunner::copy_born_encrypted` decides from where the server is
  born, whatever the form says:
  - **One of our accounts:** always born with our layer, sealed to the operator's
    key, and the provider's off.
  - **Its owner's account, the site's recovery key proven:** born with our layer,
    sealed to the site's own key (the installer's `--recovery-public-key`, encryption
    spec D2), and the provider's off. This is new: today it returns false outside
    our accounts.
  - **Its owner's account, no proven key:** born with the provider's default and
    none of ours. The tab says to prove a recovery key first to get ours instead.

  The tab says which in one line before the copy is made. Requires
  `data_root_encryption_offered`, which the launch release removes; until then the
  test plane has it on.
- **E2 — "Keep the provider's layer as well" only on a management node.** Encrypting
  in place on a machine whose provider layer is on is refused everywhere else. The
  refusal offers a copy onto a new server instead, which is born with one layer by
  E1 (in an owner's account, once the site's recovery key is proven). dev and
  getjoinery are the only machines that ever see the tick.
- **E3 — The encryption spec's D7 follows the decision.** Step 7 becomes: joinerydemo,
  jeremytunnell-vps and docker-prod's sites move by copy (this spec, Part P). Their old
  servers are retired at Keep and deleted at the provider (Done when). The node
  pages of dev and getjoinery keep "two layers until replaced".
- **E4 — One layer is checked after every birth, and on every host report.** The
  host report already carries the data root's state. The node page shows the layer
  count from both sides:
  - the provider's: `getInstance()['disk_encryption']`, which is `enabled`,
    `disabled` or unknown;
  - ours: the data root's backing.

  Two is a warning incident on any machine but dev and getjoinery. Unknown plus none
  of ours is shown as such and raises nothing.

Gate for E1–E4 in `site_copy_matrix_test.php` (the encryption column) and
`provision_disk_encryption_test.php`.

## The rig

`site_copy_anything_to_anything.md` D10's seed and checker
(`seed_copy_source.php`, `check_copy_target.php`), plus the following.

- **The security check, in two halves.** The agent runs words, never a script it
  is handed, so the check is split by what it needs.
  - **Read on the management node** (`CopySecurityCheck`, a plane class the live
    test calls, with a CLI for Part P). It covers every row the host report already
    carries or is taught to carry:
    - already in `host_report.sh`: `hardening`, `kernel_events_24h`,
      `site_engine`, `site_refresh`, the data root and escrow;
    - added for this test: each site container's `joinery.seccomp` label against
      the host filter's hash, its setuid count and its capabilities.

    It needs no login anywhere, so Part P runs it on production.
  - **Probed on the box** (`check_copy_probes.sh`, over SSH as root, **test plane
    only**). These are the rows that change something or act as the site: the B37
    loosening, the filter probe as www-data, walls by id, the write attempt as
    www-data, the old address's proxy trust. They are the rows marked ‡ below.
    Never run on a production machine.

  Each half prints one `PASS`/`FAIL` line per row with its evidence. Both run once
  against the source (the baseline) and once against the copy after the switch. A
  row that passed on the source and fails on the copy fails the run.
- **The layer line.** The check's first line is the layer count of the machine
  that holds the copy's data: the provider's answer for that machine, our data root's
  state from its host report, and the sum. For a container it is the host's data
  root, which holds the container storage (`/srv/joinery/containers/storage`).
- **Root login on the test plane.** Plane-made servers are born with root login off
  (`specs/implemented/root_ssh_login_and_keys.md`). The test plane's sources are
  installed with root login opted in, by password, never a key. Its copies carry it
  by the Copy tab's own option. Without it there are no probes.
- **Sources born as the old machines were.** C1–C3's sources are made from Install
  New Node on the test plane with our layer unticked, so Linode's layer is on and
  ours off, as joinerydemo's, jeremytunnell-vps's and docker-prod's are. The rig
  confirms Linode reports `enabled` before the run starts. That is the point of
  those runs: Linode-encrypted in, ours out.
- **A neighbour on every engine host.** Every engine host a copy lands on also
  serves one other seeded site, not the copy. "Walls by id", "a block no other site
  has" and the host restrictions on a host that serves others are checked against
  it.
- **A mail source.** C1's and C3's sources run the mailbox plugin with one local
  mailbox, a DKIM key and a relay pickup, so mail is proven on the test plane before
  jeremytunnell-vps is the first mail site ever switched.

Every run is a declared `live` test (`tier: live`, `env: dev-only`) on the test
plane (mgr-a and its nodes in the TEST Linode account). Part P is the exception: it
runs on getjoinery's plane.

## What every run checks

In order, on every run of the matrix below:

1. **Before.** Seed the source; take the security baseline on its host; record its
   layers.
2. **The copy.** Start it from the Copy tab through the API, with the run's target
   and switch method.
   - The preflight's methods must equal the end-state table's cell for that source
     and target, and the run's method must be one of them.
   - Then a **Refresh** of the dormant copy, after more seeded writes on the source,
     as a real move does: copy Monday, switch Friday.
3. **The data.**
   - The census is equal; 0 dead secrets; `check_copy_target.php` is all green.
   - The same browser signs in with its passkey and unlocks its vault on the copy.
   - No hosted-trial row was opened by the copy (B39).
4. **The switch.**
   - Downtime is measured against `site_copy.md` D2.
   - During a DNS switch, a request to the old address reaches the copy
     (forwarding), and the copy's log shows the visitor's own address, not the
     source's.
   - After a DNS, proxy or IP-swap switch, the copy's address has reverse DNS
     naming the site.
5. **Security.** Both halves of the security check run on the copy (the probes on the
   test plane only). Every row passes, and none is weaker than the baseline. The quiet
   state is present before the switch and gone after it.
6. **Encryption.** The layer line says one, and ours where E1 says ours. Where the
   provider cannot answer (a brought server), it reads "ours: none; provider:
   unknown" with no incident. Where ours is present:
   - after a reboot of the copy's machine, it opens by escrow with no hand unlock;
   - the escrow row is enrolled with the copy's management node.
7. **The source.** It is left stopped and whole, its certificate unchanged and its
   agent quiet. For a DNS switch these hold once the watch ends (the source serves
   the forwarding until then), and the check waits for it.
8. **The way back**, as the matrix's column says, checked the same way as the
   switch (data, security, and the source's layers unchanged).
   - **Mid-switch:** the address has moved but the copy has not yet taken the site.
     It leaves the copy dormant; switch again from there.
   - **After take-over:** the copy is discarded (`finish_return`). Going forward
     again means a second copy: a second server, and for a from-backups copy a
     second export approval.
9. **Keep.** The old server is retired:
   - powered off, or held stopped on its host;
   - its node row retired and its escrow row let go;
   - nothing of it polling.

   On the copy, `/etc/joinery/sites/{site}/` holds no `copy_of`, `copy_of_key`,
   `vouched` or recorded source key, so the retired source's key can push nothing.
   After a DNS switch, no `RemoteIPTrustedProxy` names the old address ‡: that
   address returns to the provider's pool, and its next holder must not be trusted
   to name the visitor. Keep has never run before this test; every switch method
   runs it at least once.

## The matrix

Each row is one live run. Every source in our Linode account today was born with
Linode's layer on, so every run from one of them is also a "Linode-encrypted to our
layer" run. The encryption column says what the copy must end with.

| Run | Source | Target | Switch | Encryption on the copy | Way back | Ends with |
|---|---|---|---|---|---|---|
| C1 | own server, alive, Linode layer on, mail | Linode made here, same account and region | IP swap | ours, Linode's off | mid-switch; after take-over, then a second copy | Keep |
| C2 | own server, alive, behind Cloudflare | Linode made here | proxy | ours, Linode's off | mid-switch; after take-over, then a second copy | Keep |
| C3 | own server, alive, zone held here, mail | Linode made here, other region | DNS | ours, Linode's off | mid-switch; after take-over, then a second copy | Keep |
| C4 | own server, alive, zone not held here | brought server (Ubuntu 26.04) | manual, with forwarding and the watch | ours: none; provider: unknown; no incident | after take-over, then a second copy | Keep |
| C4b | own server, alive | brought server | proxy | as C4 | mid-switch | Keep |
| C5 | own server, alive | Hetzner made here | DNS | ours, and the provider's off or none, as the driver reports it | after take-over | back |
| C6 | own server, alive, Hetzner | Hetzner made here, same location | Hetzner IP swap, IPv6 kept | ours | after take-over, then a second copy | Keep |
| C7 | container on docker-prod, alive | Linode made here | DNS or proxy | ours, Linode's off | after take-over, then a second copy | Keep |
| C8 | container on docker-prod, alive | container on an engine host born with our layer, its container storage on the data root | proxy or DNS | the host's: ours, Linode's off | mid-switch | Keep |
| C9 | container on an engine host, alive | container on another engine host | proxy or DNS | the host's | after take-over | back |
| C10 | container on an engine host, alive | own server | DNS | ours | after take-over | back |
| C11 | own server, dead (backups only) | Linode made here | DNS; the recovery-key ceremony | ours | none: the source is dead | Keep |
| C11b | own server, dead (backups only), Linode here | Linode made here, same account and region | IP swap (its instance powered off first) | ours, Linode's off | none | Keep |
| C12 | container, dead (backups only) | container on an engine host | manual | the host's | none | Keep |
| C13 | another management node, alive | each target column, a container on an engine host among them | refused at preflight with the reason, the node never frozen | — | — | — |
| C14 | another management node, from backups | Linode made here | proxy | ours | none | Keep |
| C15 | own server, alive, in its owner's Linode account, its recovery key proven | its owner's account | proxy | ours sealed to the site's key, Linode's off (E1) | mid-switch | Keep |
| C15b | as C15, no recovery key | its owner's account | proxy | Linode's only; the tab says so; encrypting in place refused while Linode's is on, pointing at a copy once a key is proven (E2) | — | Keep |
| C16 | own server, alive | container on an engine host | proxy or DNS | the host's | mid-switch | Keep |
| C17 | own server, dead (backups only) | container on an engine host | manual | the host's | none | Keep |
| C18 | container, dead (backups only) | Linode made here | DNS | ours, Linode's off | none | Keep |
| C19 | another management node, from backups | container on an engine host | proxy | the host's | none | Keep |

**Done once, in the run named:**

- **C1 and C3, mail:**
  - Mail sent to the source during the freeze is deferred (4xx) and arrives on the
    copy after the switch.
  - Relay pickup resumes on the copy and has stopped on the source.
  - Outbound mail from the copy is signed with the carried DKIM key and verifies.
  - In C3, the preflight's list of what names the old address includes the SPF
    `ip4:` the seed zone carries.
- **C2, Discard:** a dormant copy is discarded before the run's real copy. Its server
  and escrow are gone and the source is untouched.
- **C11, Discard mid-export (B42):** a copy is discarded while its export waits for
  approval, before the run's real copy.
- **C3, the old backups:** after the switch, the copy's first backup is a new full
  under its own site key. One backup chain from before the switch is restored on the
  copy by the recovery ceremony, so the data from before the switch is still
  restorable.
- **C4, the brought server's address (B38):** after the server joins, its node row
  carries `mgn_site_url`.
- **C14 and C19, the copied management node manages:**
  - its nodes poll it at its name, and a job runs;
  - a machine whose escrow it holds boots by escrow from it.

  C19 is also the first management node inside a site filter and outbound limits.
  There it creates a server at Linode, pulls a backup and writes a DNS record.

Cells not run live, on purpose:

- Any container or dead source to Hetzner, and a dead source to a brought server: the
  same Hetzner driver and join that C5, C6 and C4 prove. The matrix test pins their
  preflights.
- Every cell the end-state table refuses: `site_copy_matrix_test.php` proves the
  refusal text appears at preflight, before anything is made. Its gate also names
  `site_copy_anything_to_anything.md` D8's refusals, which are not cells of the
  table:
  - a bare-metal source whose machine hosts another site;
  - a backups preflight without the source's agent key;
  - a distributed region.

## The security checks

From multi-tenant-security, 2026-10-18.

- **The host table** holds on every machine a copy lands on. On an own server, the
  engine, filter, nightly replacement, walls and Apache sandbox rows do not apply
  (the sandbox is written only where the host's Apache runs no PHP), and its kernel
  settings are read from the host report or the node page.
- **The site table** is a container's. On an own server these rows apply: the code
  check, read-only tree, database (`listen_addresses` localhost), backup, quiet and
  copy-state rows. The quiet row there is the bare-metal quiet state (`site_copy.md`:
  the firewall rule, the web drop-in, cron held, the certbot timer off, Postfix
  deferring).
- **Marks:** rows marked † need the release after 0.8.504; rows marked ‡ are probes,
  run on the test plane only.

### Host

| What (spec) | Check | Pass |
|---|---|---|
| Kernel settings pinned (multi_tenant_host_hardening D1) | `multi_tenant_host.sh check`; node page Kernel settings; host report `hardening` | `kernel settings: held (38 pinned)`; `hardening.state` ok |
| A loosened setting put back within a minute (B37) † ‡ | test box only: `sysctl -w kernel.yama.ptrace_scope=0`, wait 70 s | back to 1; converger journal `kernel.yama.ptrace_scope was 0; put back to 1` |
| Rare kernel modules refused | `modprobe sctp`; `/etc/modprobe.d/joinery-rare-modules.conf` | modprobe fails; the file present |
| Kernel warnings counted (D7) | host report `kernel_events_24h` | an object, `kernel_warning` 0 |
| Walls between sites and host | `multi_tenant_host.sh check` | `walls: loaded`, walls at boot enabled, reboot after updates on |
| Host Apache sandbox | `systemd-analyze security apache2`; `apache2.service.d/joinery-sandbox.conf` | about 2.5; drop-in present; every site answers on 80 and 443 |
| Site engine and its filter | host report `site_engine`; the filter's sha256 against `joinery_site_seccomp.source`'s `SHA256=` | `podman`; equal |
| Nightly OS replacement | `joinery-site-refresh.timer` enabled; `site_refresh.sh status` | enabled; last check ok; the copy listed, not held |
| Host restrictions | `host_restrictions.sh show` | with the neighbour present: refuses what multi-tenant refuses |
| Outbound limits | `outbound_limits.sh status` | installed; the copy has its figures |
| The data root and its layers | node page Encryption line; escrow row; the layer line | one layer; enrolled; opens by escrow after a reboot |
| No trust left for the old address ‡ | after a DNS switch's watch, and at Keep: `RemoteIPTrustedProxy` in the site's Apache config | the source's address absent |

### Site (a container on an engine host)

| What (spec) | Check | Pass |
|---|---|---|
| Its own id block (site_container_engine D4) | run spec `idblock=`; host `ps -o uid= -C apache2` | a block the neighbour does not have; processes in it |
| Walls by id ‡ | from the copy's root, read the neighbour's volume path | refused |
| The site filter and its label (WP3) † ‡ (the probe; the label is read on the plane) | `podman inspect` label against the host filter's hash; the WP3 probe inside as www-data | equal; AF_PACKET, NETLINK_AUDIT, ptrace, unshare, mount, bpf, io_uring refused; login, cron, runuser and the parser jail work |
| On the current base (WP8) | `site_refresh.sh check` | not due |
| No setuid but the jail's launcher | `find / -xdev -perm -4000 -type f`; `getcap -r /usr` | the launcher only; no capabilities |
| Capabilities and limits | `podman inspect` caps, memory, swap, pids | the six run-spec caps; memory = swap; pids as the run spec; disk allowance holds |
| Host restrictions reach the site | `/etc/joinery/host_restrictions` inside | present, read-only |
| Code check (D8) | `config/code_check` age; converger journal | within the hour; matches what was shipped |
| Read-only tree | `public_html/serve.php` owner and mode; a write as www-data ‡ | root 644; refused |
| Database local only | `pg_hba.conf`; the run spec's 5432 publish | loopback and the gateway; 127.0.0.1 only |
| The site's own backup | its scheduled backup | completes: as www-data in a container; as root through the agent on an own server |
| Quiet before, lifted after | a container: the host's `sites/SITE/quiet`; an own server: the quiet state's measures (`site_copy.md`); outbound from the site | present and no outbound before the switch; gone and outbound after |
| No copy state after the switch | `/etc/joinery/sites/{site}/` on the copy's machine | no `copy_of`, `copy_of_key`, `vouched` or recorded source key |

## Part P — the production moves

**Gate.** Part P starts only after the encryption spec's D7 steps 3–6 are done:

- dev is encrypted;
- the release keys are rotated;
- getjoinery is encrypted;
- dev and getjoinery hold each other's escrow.

Until then no management node may hold an escrow slot (D7, O4), and an IP swap onto
a copy born with our layer is refused while nobody holds its key. getjoinery must
also carry the release with WP0–WP5 and E1–E4, with `data_root_encryption_offered`
on.

**Where it runs.** All three machines are on getjoinery's plane, not dev's. Part P
is driven by hand from getjoinery's Copy tab. `CopySecurityCheck` runs on getjoinery
from its CLI, and each result is recorded in the live verification queue. No probe
(‡) runs.

The runs go in this order, each only after the one before has been kept for a day:

1. **joinerydemo** (own server, alive) → Linode made here, IP swap (as C1).
   - First confirm its server has a provision row on getjoinery's plane with an
     instance id.
   - Confirm no AAAA record names it; the swap's preflight refuses one.
2. **jeremytunnell-vps** (own server, alive, mail) → Linode made here in our
   account, **proxy** (as C2).
   - Not the IP swap. It sits in the owner's personal Linode account (customer cloud
     account 7) with no provision row, and a swap never crosses accounts.
   - A copy made in that account would be born with Linode's layer and none of ours.
     The proxy lets the copy be born in our account with ours, and `site_copy.md` Q4
     chose it for this site already.
   - The mail checks of C1 and C3 run here too.
3. **docker-prod's sites** (containers, alive) → containers on one new engine host
   born with our layer, its container storage on the data root (as C8), one at a
   time.
   - First, an inventory of what lives on docker-prod today: which site containers
     are left (getjoinery and joinerydemo have gone), and what else answers on its
     address (developers.getjoinery.com and demo.getjoinery.com, `site_copy.md`
     B46).
   - Its agent must run `site_quiet_host` and `hold_container`; record its version.
   - docker-prod is retired when the last site is kept and nothing else answers on
     it.

Each is checked as every run is, and afterwards the machine's layer line says one.

**After Keep,** each old server is deleted at its provider, with its provider
backups and snapshots, within a day. It holds live production secrets under Linode's
layer only: `secret_box_key`, the DKIM private key, the ACME account, the DNS-01 zone
credential, and `relay_pull_key`.

dev and getjoinery are not copied: they follow the encryption spec's D7 steps 3–6.

## Open copy bugs closed before the test

From multi-tenant-security's record and this session's. Each is fixed, or closed
as no longer true, before the first run:

- `site_copy_anything_to_anything.md` B1–B8 (built by its WP0–WP5).
- That spec's WP0 is stale: B31–B33 are committed; the remaining items stand.
- Agentless host rows (7598, 7666, 8356, fixture 10380) on every Copy tab.
- wd5's recovery key file is gone: a new key ceremony before any further copy from
  wd5. (wd5 itself, node 153811, serves live from se1; se1 is node 153812 and host
  11797. All three stay.)
- B25 (podman build hooks warning) and B30's early half: release status to confirm.
- Never passed live, each now a step in a run above:
  - B38 (a brought server's Site URL): C4;
  - B39 (a copy opens no hosted-trial row): every run, step 3;
  - B42 (discard mid-export): C11;
  - the look, Refresh and Discard: every run's step 2, and C2;
  - the proxy switch (L0, L2): C2;
  - a dead source with a switch: C11, C11b, C12, C17, C18.
- A connection made by site copy is invisible on the Management Node page (wp5
  rehearsal B42).
- B49 (a copy's new server advances one provisioning stage per 15-minute tick), B53
  (the copy suites fail while a real copy runs: one global lock), B51 (a test
  clean-up message).
- Older, status unknown: a5 review W5, H1, H2, R7 (Postfix queue); the check that the
  quiet network rule blocks www-data and survives a reboot.
- Rows 83209 and 92150 still named '(copy)'; row 92150's permanent delete.
- `docs/installation.md` names `copy_database.sh`, which does not exist.

## Done when

- Every run C1–C19 (with C4b, C11b and C15b) has passed, on record in the live
  verification queue under this spec's name. Every refused cell and every D8 refusal
  is pinned by `site_copy_matrix_test.php`.
- Part P is done: joinerydemo, jeremytunnell-vps and docker-prod's sites are each on
  one layer, and their old servers are deleted at the provider with their backups.
  dev and getjoinery are on the encryption spec's path.
- No machine but dev and getjoinery reports two layers.
- `site_copy.md`, `site_copy_anything_to_anything.md` and this spec are in
  implemented. The docs list of `site_copy_anything_to_anything.md` is done,
  including the Copy tab's one-line encryption statement and the layer line in
  `plugins/server_manager/docs/overview.md`.
