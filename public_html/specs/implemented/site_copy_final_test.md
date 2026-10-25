# Site Copy — The Final Test

**Status:** Done, 2026-10-25; moved to implemented with `site_copy.md` and
`site_copy_anything_to_anything.md`. Written by one-data-root with multi-tenant-security
(the security checks, the record of what copies today); reviewed by
multi-tenant-security (R1–R6) and reviewer2 (F1–F22), all applied. E1, E2 and E4 are
built, gated (`data_root_layers_test.php`, `provision_one_layer_test.php`) and released.
Every run C1–C19 (with C4b, C11b and C15b) passed live on the test plane, 2026-10-19 to
2026-10-25, on record in the live verification queue. B114, found running C15b (E2's
refusal held on the management node only, not on a site's own Backups page), is built:
the management node records the provider's layer on each machine (agent 1.96.0's
`data_root_provider_layer`), and the data root script and the Backups page refuse by it;
its live proof waits for the agent and core releases, in the queue. The runs were driven
by hand on the rig; the owner chose one repeatable smoke test over a live test per run
(2026-10-25), `plugins/server_manager/tests/site_copy_live_test.php`, which passed on
dev 2026-10-25 (31 checks). Part P, E3 and the two-layer check of Done when moved to
`site_copy_production_moves.md`.

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
  complete. **Answered 2026-10-19:** the owner made a Hetzner test project; its
  token is on dev at `/home/user1/.config/joinery-test/hetzner_test_token` (user1 only, outside the site).
- **Cloudflare zone for the proxy runs (answered 2026-10-19).** The owner registered
  joinerytest2.com on Cloudflare for C2, C4b, C14, C15 and C19; a token scoped to
  that zone alone is on dev at `/home/user1/.config/joinery-test/cloudflare_test_token` (user1 only, outside the site).
- **Q2 — A second Linode account for C15**, granted to the test plane by OAuth as a
  customer's own account. C15 needs it. **Answered 2026-10-25:** no second account;
  the TEST Linode account itself, connected by OAuth as a customer user's on mgr-a
  (`/profile/server_manager/connect_cloud`), with mgr-a's own key for that account
  switched off for the run.

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
    No copy reaches this case today: a copy runs through the site's backups, and
    backups refuse to run until the recovery key is proven (`RecoveryKeyFleet`), so
    the copy is refused at preflight and the tab shows this line beside the refusal
    (found running C15b, 2026-10-25).

  The tab says which in one line before the copy is made. Requires
  `data_root_encryption_offered`, which the launch release removes; until then the
  test plane has it on.
- **E2 — "Keep the provider's layer as well" only on a management node.** Encrypting
  in place on a machine whose provider layer is on is refused everywhere else. The
  refusal offers a copy onto a new server instead, which is born with one layer by
  E1 (in an owner's account, once the site's recovery key is proven). dev and
  getjoinery are the only machines that ever see the tick.
- **E3 — The encryption spec's D7 follows the decision** (moved to
  `site_copy_production_moves.md`, which made the edit). Step 7 becomes: joinerydemo,
  jeremytunnell-vps and docker-prod's sites move by copy (that spec). Their old
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
  - **Probed on the box** by the `copy_probes` agent word (agent 1.84.2,
    `maintenance_scripts/sysadmin_tools/copy_probes.sh`, a job like any other).
    These are the rows that act as the site: the filter probe as www-data, walls
    by id, the write attempt as www-data, the old address's proxy trust, the
    database's listener. They are the rows marked ‡ below. Owner's decision
    2026-10-19: a word, not a login, since a plane-made copy has root login off
    and keys are never used; so these rows need no login and Part P may run them
    too.
  - **The B37 loosening alone** stays a login's (`tests/lib/check_loosen_restored.sh`,
    over SSH as root, **test plane only**, never on a production machine): no
    shipped script loosens a kernel setting. It runs on machines the test made by
    hand, whose root password the test holds: the sources and brought servers.
    The converger that puts the setting back is the same code on every machine.

  Each half prints one `PASS`/`FAIL` line per row with its evidence. Both run once
  against the source (the baseline) and once against the copy after the switch. A
  row that passed on the source and fails on the copy fails the run.
- **The layer line.** The check's first line is the layer count of the machine
  that holds the copy's data: the provider's answer for that machine, our data root's
  state from its host report, and the sum. For a container it is the host's data
  root, which holds the container storage (`/srv/joinery/containers/storage`).
- **Root login on the test plane.** Plane-made servers are born with root login off
  (`specs/implemented/root_ssh_login_and_keys.md`), and copies keep it off. The test
  plane's sources are made by hand in the test Linode account, with a root password
  the test holds (never a key), installed from the release, joined to the test
  management node and adopted there (`CloudServerAdoption`), so a swap can find their
  instance. Only the seed, the checker and the B37 loosening use that login; the
  probes are the word's.
- **Sources born as the old machines were.** C1–C3's sources are made by hand
  (above) with Linode's disk encryption on and none of ours, as joinerydemo's,
  jeremytunnell-vps's and docker-prod's are; Install New Node cannot make one, since
  a server it makes in our account is always born with ours (E1). The adoption
  records Linode's answer, and the rig confirms it reads `enabled` before the run
  starts. That is the point of
  those runs: Linode-encrypted in, ours out.
- **A neighbour on every engine host.** Every engine host a copy lands on also
  serves one other seeded site, not the copy. "Walls by id", "a block no other site
  has" and the host restrictions on a host that serves others are checked against
  it.
- **A mail source.** C1's and C3's sources run the mailbox plugin with one local
  mailbox, a DKIM key and a relay pickup, so mail is proven on the test plane before
  jeremytunnell-vps is the first mail site ever switched.

Each run was driven by hand on the test plane (mgr-a and its nodes in the TEST Linode
account) with the rig above, and recorded in the live verification queue. One path is a
declared `live` test, re-run after copy code changes and before the release that carries
them: `plugins/server_manager/tests/site_copy_live_test.php` (`SiteCopyLiveRun`), on the
management node's own code. Its source is a test server at the oldest release that node
serves and a copy accepts, behind the Cloudflare test zone; the copy goes onto a Linode
made there; it goes back at ready, then switches over by the proxy and is kept. The
production moves run on getjoinery's plane (`site_copy_production_moves.md`).

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
   - The census is equal; 0 dead secrets. `check_copy_target.php` is all green on the
     source. On a plane-made copy (root login off) it cannot run, and what it adds to
     the census is checked from outside: the seed's public marker file, fetched from
     the copy through its look path, is the seed's bytes; the DKIM key is proven by the
     copy's signed mail (C1, C3). The sealed values need nothing more: each is sealed
     to its column under the key the copy took, so one that opens is the source's.
   - The same browser signs in with its passkey and unlocks its vault on the copy.
   - No hosted-trial row was opened by the copy (B39).
4. **The switch.**
   - Downtime is measured against `site_copy.md` D2.
   - During a DNS switch, a request to the old address reaches the copy
     (forwarding), and the copy's log shows the visitor's own address, not the
     source's.
   - After a DNS or IP-swap switch, the copy's address has reverse DNS naming the
     site. Not through the proxy: the site's name resolves to the proxy, and a
     provider sets reverse DNS only to a name that resolves to the address.
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
     It leaves the copy dormant; switch again from there. Through the proxy the copy
     takes the site's node id seconds after the move; going back once it has (while
     it is still starting) is the way back after the swap and discards it, so the
     run checks both: back before the take (dormant), back after it (discarded).
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
| C6 | own server, alive, Hetzner | Hetzner made here, same location | Hetzner IP swap (IPv4 moves; the AAAA note for IPv6) | ours | after take-over, then a second copy | Keep |
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
| C15b | as C15, no recovery key | its owner's account | — | the copy refused at preflight (no backups run without a proven key), the tab naming the fix and the provider's-only layer a copy would get; encrypting in place refused while Linode's is on, pointing at a copy once a key is proven (E2) | — | — |
| C16 | own server, alive | container on an engine host | proxy or DNS | the host's | mid-switch | Keep |
| C17 | own server, dead (backups only) | container on an engine host | manual | the host's | none | Keep |
| C18 | container, dead (backups only) | Linode made here | DNS | ours, Linode's off | none | Keep |
| C19 | another management node, from backups | container on an engine host | proxy | the host's | none | Keep |

**Done once, in the run named:**

- **C1 and C3, mail:**
  - Mail sent to the source during the freeze is deferred (4xx) and arrives on the
    copy after the switch.
  - Relay pickup resumes on the copy and has stopped on the source.
  - Outbound mail from the copy leaves through our sending key (owner, 2026-10-20: no
    machine on the test plane sends on port 25), is DKIM-signed by the provider and
    verifies, and the copy's own address appears nowhere in the delivered headers. A
    relay-fronted site's mail can leave only through a provider's API, so the DKIM key
    the copy carries for its own Postfix is not on this path.
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

Moved to `site_copy_production_moves.md` (2026-10-25): joinerydemo, jeremytunnell-vps
and docker-prod's sites by copy onto servers with our layer only, on getjoinery's plane,
behind the encryption spec's D7 steps 3–6, with E3 and the two-layer check of Done when.

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

## Done when

- Every run C1–C19 (with C4b, C11b and C15b) has passed, on record in the live
  verification queue under this spec's name. Every refused cell and every D8 refusal
  is pinned by `site_copy_matrix_test.php`.
- Part P, and the check that no machine but dev and getjoinery reports two layers:
  moved to `site_copy_production_moves.md`.
- `site_copy.md`, `site_copy_anything_to_anything.md` and this spec are in
  implemented. The docs list of `site_copy_anything_to_anything.md` is done,
  including the Copy tab's one-line encryption statement and the layer line in
  `plugins/server_manager/docs/overview.md`.
