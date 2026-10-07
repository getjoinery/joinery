# Site Copy — The Same Site on a New Server

**Status:** Building (started 2026-09-28).
- **WP1 built:** the five documents, `reconcile_site.sh`, the scrub header and the Clone form label
  say what is true today (no faithful move exists; a restore onto another machine kills every
  sealed value).
- **WP0 built:** B26 built (`restore_database.sh` 3.10 stages beside the archive); B27 built
  (`host_runner_lock.sh`, held by `restore_chain.sh` 1.6.0 and `restore_project.sh` 1.6.0); the
  approval fold built (`ApprovalChallenge` + `ApprovalChallengePanel`, one scope table). B24 built
  (`HetznerDnsDriver` 2.0 on the Cloud API's record sets; mocked tests only, no Hetzner account).
  B25 built (owner 2026-09-28: one bare-metal site per machine, Docker for several):
  `default_virtualhost.conf` 2.08 answers on `*`, `render_vhost.sh` 1.10 moves a pinned 2.07 render
  on its next converge; the installers and `virtualhost_update_script.sh` 2.3.0 substitute no
  address. B28 built (`_plugin_installers_start.sh` 2.21: the root lock file is 0600).
- **WP5 built (2026-09-28); the firewall rule proven as root on dev (web user and Postfix refused, root and loopback through); the reboot and clear-exactly checks wait for L0:** `_site_state.sh` 1.1 (the measures, set and cleared),
  `site_quiet.sh` 1.0, `install.sh` 2.89 (`--dormant --copy-of`), agent 1.46.0 (`site_quiet`, the
  quiet refusal in `Execute`, `backup_run` allowed under `switchover`), and on M
  `ManagedNode::is_operational()` with the five states, and the converger gate
  (`_plugin_installers_start.sh` 2.23: no stamp while quiet, so clearing converges in full; fails closed without its helper). Four
  choices made while building, each recorded where it applies below:
  - the dormant install runs its one installer pass as any site does, then sets `quiet copy`;
  - Postfix defers at every smtpd service, not at the `joinery` transport;
  - the firewall rejects rather than drops, and covers Postfix's own client too;
  - the fleet badge reads "Copy — dormant" on a row in state `copy`.
  - **Review (public-html-a5, 2026-09-28):** R1, R4, R5, R6 and the low items fixed. Two limits
    were carried to WP8, which closed both: M sets `copy` on the copy's row, and a copy is
    installed from S's exact release (WP8 below).
- **WP2 built (2026-09-28); gate-tested on fixture chains, not yet on a real copy:** `restore_chain.sh`
  1.7.0 (`--adopt-secret-key`, `--skip-ssl` passed to the reconcile), agent 1.47.0 (`copy_restore`),
  and on M `build_copy_restore` with an 8700 s claim budget. Choices made while building:
  - `copy_restore` is an **operate** word: a destructive word always asks for approval, and the
    decision is that a dormant copy asks none.
  - **The vouch is a record, not a check of the signature at restore time.** `copy_restore` applies
    a staged manifest only when its hash is listed with its chain id in root's
    `/etc/joinery/sites/{site}/vouched` (`<sha256> <chain id>` per line). It refuses when the record
    is absent or writable by other accounts. This machine's own upload ledger does not count. WP4
    writes the record (see WP4).
  - The key is read out of the chain's config by pattern, never by running the file, and must be
    32 base64-encoded bytes. The splice is written with `var_export` and linted before it replaces
    the config, which keeps its owner and mode.
  - It refuses before any write when T has no config of its own. After the files are in, it refuses
    when the chain's config carries no usable key: T's own key stays, and the database is not loaded.
  - `restore_statement.go` is not shared: with no approval, there is no statement to show.
  - `_site_state.sh` 1.2: clearing removes `vouched` with `copy_of`.
  - The end-to-end proof ran with WP3 (`tests/backups/copy_restore_gate.sh`, below).
- **WP3 built (2026-09-28); gate-tested on dev and on throwaway projects, not yet on a real copy:**
  `SiteCensus` (core, 1.0), `maintenance_scripts/sysadmin_tools/site_census.php` 1.0,
  `SecretReconciler` 1.2 (`census()`, the read-only pass), `SecretBox` 1.3 (`canaryState()` takes
  the box to open with), agent 1.48.0 (`site_census`, observe), and on M `build_site_census`,
  `JobResultProcessor::process_site_census` and `census_of()`. Choices made while building:
  - **One class on both sides.** The node counts with `SiteCensus::take()`, M compares with
    `SiteCensus::compare($source, $copy, $exact)`; the script prints one `CENSUS=` line of JSON.
  - **The exclusion list is the files engine's.** Beyond the list in step 5: the site's own
    `backup_exclude` names (tar patterns, matched as tar matches them), `public_html_*`,
    `uploads/upgrades`, and the local copies of offloaded files. `site_census_test` holds the census's
    names equal to `backup_files.sh`'s `NAMED_EXCLUDES`.
  - **Files carry a digest.** Per top-level directory: files, directories, bytes, and a sha256 over
    every path with its size, so a rename or two size changes that cancel still show. Nothing is read
    but directory entries.
  - **Tables are counted in one read-only snapshot**, so a live source's counts are of one moment.
  - **The offloaded-files sample is part of the census:** the count, and 20 drawn at random asked
    for by a metadata request each (never a download). Run as root, as the agent runs it, so a quiet
    site's firewall does not stop it.
  - **The census's egress.** Its offloaded-files sample is a request from root to the bucket, which
    the quiet state's firewall allows by design: on a quiet copy it is the one outgoing request the
    census makes (`docs/deploy_and_upgrade.md` says so).
  - **Review (public-html-a5, 2026-09-28):** B1 fixed (a `backup_exclude` pattern matches any tail
    of the path that starts at a name, with `*` crossing `/`, as tar's unanchored exclude does; the
    test checks the census against tar itself). A1, N1 (the census is never redacted: a masked
    digest compares equal to any other) and the spec and doc notes applied. L1-L5 answered with
    nothing to fix: a restore's extraction deletes everything T has that S's archive lacks, at any
    depth; nothing on a quiet T rewrites a counted file.
  - **What blocks while S is live:** only what S changing cannot explain: T's canary not opening
    while S's does, more dead secrets on T, or an offloaded file T cannot reach. Row, file, byte and
    stored-secret counts block only in the exact comparison (step 8).
  - The WP2 end-to-end proof, `tests/backups/copy_restore_gate.sh` (db tier): S is a throwaway
    project whose database is the test database's schema and settings, every sealed setting sealed
    again under S's own fixture key with a canary added (dev's key is never read). Restored with
    `--adopt-secret-key` onto T with another key and password: the canary and every sealed value
    open with T's config, T keeps its password, and the census is exactly equal. A second apply after
    an edit, a new file and a new row on T removes all three.
- **WP4 built (2026-10-01); unit- and gate-tested, not yet on a real copy:** agent 1.49.0
  (`copy_export` destructive, `copy_import` and `copy_stage` operate), `ApprovalChallenge` 1.2 (the
  `export` scope, with its two settings rows), `CopyStaging` 1.0 with `utils/copy_stage.php`, and on M
  `build_copy_export`, `build_copy_import`, `build_copy_stage`, `process_copy_export` with
  `bundle_of()`, `ManagedNode::backup_node_of()` with the column `mgn_copy_of_node_id`, and claim
  budgets (export 4500 s, stage 8700 s), with `install.sh` 2.90 (`--copy-of-key`) and
  `_site_state.sh` 1.3 (records `copy_of_key`; clearing removes it and `copy_import_issued`).
  Released in 0.8.452. Choices made while building:
  - **The seal** (`copy_seal.go`): ephemeral X25519, HKDF-SHA256 bound to both public keys,
    AES-256-GCM, to T's Ed25519 key converted to X25519 (the public half by u = (1+y)/(1−y), the
    private half by the scalar Ed25519 derives from its seed). `curve25519.X25519` refuses a
    low-order key. No new dependency.
  - **The signature** covers the body's exact bytes, which travel base64-encoded, under
    `joinery-copy-export-v1`. The agent lends its key to words only as `SignDomain` (a message must
    begin with its own domain; the request domain is refused) and `OpenSealed`; no word holds it.
  - **The bundle** (gzipped inside the seal): each chain with its data key and the vouched manifest
    hash, and the host bundle. A bundle is valid for six hours, may be issued at most fifteen minutes
    ahead of T's clock, and must be newer than the last one T took (`copy_import_issued`, raised
    last). At most 200 KiB on the wire, so `copy_import` carries the chain ceiling.
  - **One manifest version is vouched:** the one on S's disk, required to match S's ledger. A backup
    on S between an export and a stage makes `copy_stage` refuse by name; export again. At the final
    copy S is frozen, so it cannot happen there.
  - **The host bundle takes each certbot lineage's current version only:** the four files `live/`
    links to, the links themselves, the renewal file, the account directory, `/etc/letsencrypt/*.ini`
    and `/etc/opendkim/keys/`. Entries travel as a root by name and a path inside it; a link must point
    into its own lineage's archive. Owners travel by name; one T has no account for is written as
    root's and named in the result.
  - **The statement** names T by the 16-character fingerprint both admin pages show and by the full
    SHA-256, with the chain, its newest upload time, the certificate names and the DKIM key count.
  - **HTTPS on T:** the vhost template picks `/etc/letsencrypt/live/<domain>/` over the placeholder
    when Apache parses its config, and clearing the quiet state reloads Apache. Nothing re-renders.
- **WP8 built (2026-10-01), copy side; unit- and db-tested, not yet live:** on M the node's **Copy**
  tab (`node_detail_tabs/copy.php`), `SiteCopy` (`scp_site_copies`), `SiteCopyRunner` and the
  **Advance Site Copies** task; install mode `copy` in `build_install_node` and the provisioning
  pipeline (`cvp_release`); `process_install_node` keeps a copy row in `copy`; agent 1.50.0
  (`take_node_id`, and `copy_import` reports the look path); `SiteCopySwap` and
  `JobResultProcessor::complete_take_node_id`, answered from `AgentChannelEndpoint::handle_result`.
  Schema applied on dev. Owner decisions while building (2026-10-01):
  - **Q1: switching over waits for WP7a.** The Switch Over button (steps 7–10 and the way back)
    arrives with the first way to move the address. WP8 builds the two pieces that are not about
    the address: the node-id word and the row swap, in both directions.
  - **Q2: the platform never deletes a cloud instance, copies included.** Discard removes the
    copy's row and names the server (provider, instance, address) for the owner to delete at the
    provider; the retired source likewise. The spec's earlier "the ordinary node delete deletes
    the instance" was never true: no Server Manager delete deletes a server.
  Choices made while building:
  - **The release pin is in the fetch, not an `install.sh` flag.** T fetches
    `latest_release?version=<S's release>` (which existed, unused by installs), and that tree's own
    `install.sh` runs. An `--release` flag would exist only in releases after this one, and T runs
    S's. `UpgradeRetention` already keeps the archive of any release a managed node runs.
  - **The floor is 0.8.453** (`JobCommandBuilder::COPY_SOURCE_MIN_VERSION`), not WP5's release: T
    runs S's tree, so S's release must carry `install.sh` 2.90 (`--copy-of-key`), `restore_chain`'s
    `--adopt-secret-key`, the converger gate and `_site_state.sh` 1.4 (B36). 0.8.453 is the first.
  - **Preflight** refuses also: a web root outside `/var/www/html/<site>/public_html`, no https
    site address, and a copy row of S that exists already (the L0 run's hand-linked node 75177
    counts: remove it before copying copytest again).
  - **A run is seven jobs, one at a time:** `host_report` on T first, then a fit check from both
    host reports (memory at least S's; free disk at least the chain staged, twice the files, six
    times the compressed database, and 2 GiB, an estimate stated as one), then export, import,
    stage, restore, a census on each side and the informational compare. The first job that does
    not do its part stops the run by name. A run takes the newest manager-profile chain and
    requires it under 24 hours old, the preflight's rule, at every run.
  - **The first run starts by itself** once T's agent has joined and reported its words.
  - **Joining a brought server:** the tab lists pending requests from an agent that claims S's
    site name; the owner compares the fingerprint with `joinery-agent status` on T. Approving makes
    the row (`<S> (copy)`, state `copy`, `mgn_copy_of_node_id`). A server M created is approved
    onto its existing row after the provider check, as any provisioned join.
  - **The look link** comes from T: `copy_import`'s result carries `/.joinery-look/<secret>`
    (agent 1.50.0); the tab shows it with the hosts-file line and the stale-socket note.
  - **Discard waits for an install in progress** (a discard under a running pipeline would leave
    it making a copy nobody tracks) and withdraws a provision not yet started.
  - **`take_node_id` is two-phase.** T stages its identity under S's id beside the live one and
    posts its result; M swaps the rows while answering and says `node_id_taken`; only then does T
    make the staged identity live and restart (it refuses unless a supervisor is proven, as
    `restart_agent` does). No answer, no change. The processor that runs from page views and sweeps
    records what was staged and never swaps, so a swap T does not hear about cannot happen.
  - **The swap moves machine columns only** (`SiteCopySwap::MACHINE_COLUMNS`: agent key and what
    its agent reports, host, SSH fields) and provision links; name, slug, site URL, web root,
    backup policy and status data stay on the node. `go_back` leaves the node `switching` and the
    other row `copy`.
  - Tests: `site_copy_runner` 63/63 (install command, provision rules, a finished install, the
    word's builder, the swap answered and not, preflight, a brought server end to end with fake
    job results, a declined export, a short disk, a moved release, Discard); agent `go test ./...`
    with `take_node_id`'s refusals, the confirmed and unconfirmed take, and the look path.
  - **B37 — Every dormant copy opens a case for a PHP-FPM that is fine.** OBSERVED on T (case 1814,
    "php-fpm is active and does not answer", three refused restarts), TRACED: `host_report`'s
    `answers` probe asks for the site's own name, and on a quiet site every page is the quiet
    state's 503 without serve.php's header, which the script read as PHP-FPM failing. Fixed in
    `host_report.sh` 1.6: on a quiet site PHP-FPM reads `quiet`, which the `service_health` recipe
    already takes as unknown (it passes, and the open case closes at its next check). The probe does
    not send the look cookie to get past the 503: that would write request rows into a copy whose
    rows must match its source's at the exact census. The Host card says "quiet". Tests:
    `host_report` gate and the recipe's table.
  - **B38 — A node that joins on its own never records its site address.** OBSERVED on dev: S and T
    (75176, 75177) have no `mgn_site_url`, so the Copy tab refuses S ("no https site address").
    `adoptJoin` sets a site URL only for this machine's own join, and nothing a node reports carries
    its domain. The refusal says where to set it (the Site URL field in the Overview's connection
    settings). Fixed (2026-10-02): agent 1.51.0's `check_status` reports `site_domain` (webDir from
    the site's config; the management API's stats endpoint 1.3 reports it too), and
    `ManagedNode::adopt_reported_site_domain` fills an empty `mgn_site_url` as `https://<domain>`
    from it, as the web root is filled. It never replaces a set address, and refuses an address
    another live node holds (a copy and its source excepted) and the management node's own.
    `job_result_processor` 239/239; agent `go test ./...`.
  - **B39 — A copy on the operator's account is billed as a sold Managed site.** OBSERVED on the
    first Copy-tab run (2026-10-02, copy 14, provision 10076, node 77547): `HostedTrialWatch` opened
    a 60-day trial row (846) for the copy and pushed a `hosted_plan_notice`, which the quiet copy
    refused (job 52528), and it re-files the push every tick. TRACED: the watch takes every
    `cvp_hosting_mode = operator` provision that is done as a sold site; a copy made with the
    operator token is `operator` with origin `admin`. At the trial's end plus grace the watch shuts
    the instance down with the operator token, which after a switch-over would be the live site.
    Fixed: one rule for a sold site, `CustomerCloudProvision::is_sold()` (1.13: origin `order` or
    `buyer`, not `bare`), which `HostedTrialWatch` 1.3 applies through `sold_sites()`; the node
    page's move panel and Your sites ask it too, in place of their own copies. Trial rows 846 and
    873 removed. `hosted_tier` 111/111; `db --changed` 199/199.
  - **B40 — A copy run moved one step per two task ticks.** OBSERVED on the first Copy-tab run
    (copy 14): the host report finished at 18:31, passed at the 18:45 tick, and the export waited
    for the 19:00 tick; seven steps at fifteen minutes a tick is about three and a half hours.
    TRACED: `advance_run` either judged a finished step or queued the next, never both, and only
    the Advance Site Copies task called it. Fixed in `SiteCopyRunner` 1.2: a passed step queues
    the next in the same call (a run that begins queues its first step too), and
    `AgentChannelEndpoint` 1.30 calls `SiteCopyRunner::job_finished()` when a node posts a copy
    step's result, so a run moves as fast as its jobs finish. `site_copy_runner` 70/70.
  - **B41 — Incidents outlive their nodes.** OBSERVED 2026-10-02 by this work's `db --changed`
    gate: `referential_integrity` found 13 incidents on deleted fixture nodes and 10 events whose
    incident was gone. TRACED, two causes: the live incident pass lists nodes and then opens
    incidents on them, so a node deleted in between got an incident after its own were deleted
    (and test fixtures deleted by raw SQL skip the deletion rules altogether); and a node's
    incidents were a one-level `cascade`, so each incident's timeline stayed behind. Fixed:
    `IncidentRecord` 1.2 deletes with the node by `permanent_delete` (timeline included);
    `ManagedNode` 1.34's `permanent_delete()` takes `IncidentReconciler::LOCK_KEY`, so it waits
    for a running pass; `IncidentReconciler` 1.2's full pass deletes any incident whose node row is
    gone (`remove_nodeless`), and the task reports it. Dev cleaned; deletion rules regenerated.
    `incident_reconciler` 27/27; `db --changed` 514/515 (the one is the agent bundle awaiting
    publish); no orphans after the run.
  - **B42 — Discard leaves the source's export request waiting.** OBSERVED 2026-10-02 (copy 14,
    job 52708): discarding while `copy_export` waited for the owner on S marked the copy discarded,
    but S's approval request stayed open until the agent's wait ran out; approving it would still
    export a bundle for a copy nobody tracks (sealed to that copy's key, so readable only there).
    TRACED: the source's agent holds one job at a time and nothing on M could stop a running one,
    so no job sent to S could reach the request. Fixed: `SiteCopyRunner` 1.3's Discard cancels
    every step job not yet finished; the new signed channel route `job_status`
    (`AgentChannelEndpoint` 1.31) answers whether a node's job was withdrawn, and agent 1.52.0
    asks it every 30 seconds while any approval waits, ending the wait as a refusal (which clears
    the request) on an explicit withdrawn. Any other answer keeps waiting, and nothing on the route
    can approve. `site_copy_runner` 73/73, `agent_channel_metering` 26/26, agent `go test ./...`.
    Live check (S on 1.52.0) is in the verification queue.
  - **First Copy-tab run (copy 14, 2026-10-02): abandoned by the owner at the export approval.**
    New server installed quiet in 5 minutes (us-east, operator token), join approved (Linode
    address check, IPv6 source), host report passed; found B39, B40, B42. Discarded; provision
    10076 retired and its trial rows (846, 873) deleted. What a full run still has to show is in
    the live verification queue.
  - **Carried to WP7a:** the switch-over itself. One finding for it: after step 10, T is live and
    answers as S's node id; going back swaps T's key off the node, so T can no longer reach M. The
    way back must quiet T (`site_quiet on`, while T is still the node) before `go_back`, then
    discard it.
- **WP7a built (2026-10-02): the switch-over and the way back, by a proxied origin change;
  unit- and db-tested, not yet live.** On M, the Copy tab's Switch over, Move the address, Go
  back and Keep the switch-over (`SiteCopyRunner` 1.4, `SiteCopy` 1.1 with `scp_switch`,
  `ProxiedOriginMove`, node detail actions 1.37, Copy tab 1.2); the Cloudflare driver reports
  `proxied` and moves a proxied record's address (`DnsProxiedOrigin`, `CloudflareDnsDriver` 1.4,
  `DnsRecord` 1.3); agent 1.53.0 (`copy_vouch`, `copy_take_vouch`); `JobCommandBuilder` 1.88,
  `JobResultProcessor` 1.51, `AgentChannelEndpoint` 1.32. Schema applied on dev.
  - **B44 — The owner cannot approve the final export.** FOUND 2026-10-02 while building, before
    any code: step 8 ran `copy_export` again inside the freeze, and an export waits for the
    owner's approval on S's own Backups page (Q9), but a frozen S answers every request, the
    owner's included, with the maintenance page. Letting the owner past it does not work either:
    whatever the visit writes after the final backup is lost at the switch, and breaks the exact
    census. **Decided by the owner (2026-10-02): the final copy carries no secret, so it needs no
    approval.** T already holds the chain key, the certificate and the DKIM keys from the last
    approved export; the final run extends that chain, so the one new fact T needs is which
    manifest S stands behind. `copy_vouch` (S, only under `quiet switchover`) signs that hash,
    from S's upload ledger, for T's key under its own domain `joinery-copy-vouch-v1`, valid an
    hour; `copy_take_vouch` (T, only under `quiet copy`) checks it against the recorded S key,
    shares `copy_import`'s high-water mark, refuses a chain it holds no key for, and replaces the
    vouch. Catches, accepted: a certificate renewed on S since the last Copy again does not
    travel (T keeps the older, valid one and renews it itself after the switch); a final backup
    that starts a new chain stops the switch-over (go back, copy again).
  - **Choices made while building:**
    - **The DNS token is typed twice:** once to check the records before anything freezes (read
      only), once to move them. Nothing DNS-write-capable is stored on M (`docs/dns_management.md`),
      and the move comes minutes after the check, in another request. The way back asks for it
      again when the address had moved.
    - **"Never roll" is the longest chain interval** (`full_interval_days` 365, the agent's
      maximum) on the final backup run: no agent change. A chain still ends for another reason (a
      lost snapshot, a rotated recovery key, 30 runs); the run's judge then sees a new chain id and
      stops before the vouch, naming it.
    - **The proof is the look path.** T has no health answer of its own while dormant; its
      `/.joinery-look/<secret>` answers a 303 setting its look cookie, and only T knows the secret.
      M asks it through the proxy at the site's name, with a fresh query string each time, for up
      to a minute. Unproven, the records move back and S stays frozen and ready.
    - **Which records move:** every A and AAAA naming one of S's addresses (its host, its
      provision's addresses, and what its agent reported at join), compared as addresses. Each must
      be proxied, the site's own name must point only at S, and T must have an address of each
      family (T's IPv6 comes from its provision or its join). Refused, nothing is frozen.
    - **Firewalls are not listed:** M has no view of S's firewall (the host report carries none).
      The tab says that a rule letting only the proxy in must be given to T by the owner before
      the move. A word that reports the firewall is a later addition if it is wanted.
    - **`mgn_host` needs no separate update:** the row swap moves it with the machine.
    - **The swap comes before the copy moves on:** `AgentChannelEndpoint` 1.32 answers a
      `take_node_id` result with the swap first, then advances the copy, so the step is judged by
      what the swap did.
    - **After the way back from a switched site, the copy is discarded** (its agent answers as
      S's node id against a row holding its own key); the tab names its server for deleting for a
      week, as after any discard or a kept switch-over.
  - Tests: `site_copy_switch` 44/44 (new: the records, the driver against a fake Cloudflare API,
    the freeze, an exact census that differs, a new chain at the final backup, an unproven move,
    the start, the way back after the switch, and keeping it), `job_command_builder` 383/383,
    agent `go test ./...` (`copy_vouch_test.go`: the vouch moves the copy to the final run with no
    secret, and each refusal); `db --changed` 357/365, the eight failures all in the
    mailbox spam-learning work another session has in progress, none in this work's files.
- **WP12 built (2026-10-03): the switch-over by IP swap; unit- and db-tested, not yet live.**
  `IpSwapMove` 1.0 (the plan and the local steps), `CloudAddressSwap` on the provider interface
  (`CloudComputeProvider.php` 1.4) with `LinodeComputeDriver` 1.9 (`addressReport`, `ipAddress`,
  `assignIpv4` on `POST networking/ips/assign`, `instanceFirewalls`, `rebootInstance`),
  `SiteCopyRunner` 1.5 (`switch_methods()`, the method on `begin_switch`, local steps, the
  `undoing` status), `SiteCopy` 1.2, `ProxiedOriginMove` 1.1 (`prove()` takes a time budget), node
  detail actions 1.38, Copy tab 1.3, `utils/advance_site_copy.php` 1.0. The customer Connect grant
  asks for `ips:read_write` and `firewall:read_only` too (`profile_connect_cloud_logic.php` 1.3);
  the operator token's help names `ips:read_write` (`plugin.json` 1.30.3, Provisioning Setup 1.8).
  Choices made while building:
  - **The move is local steps, not one request.** Powering a server off, the swap, the copy's
    restart and the probe each take seconds to minutes, so each is a step this management node
    does itself (`power_off`, `ip_swap`, `power_cycle`, `probe`, beside `go_back`), safe to call
    again: it asks the provider first and does only what is not done. The press that lays them
    out starts a short worker that advances the copy every five seconds while its step is local;
    without it, the next task tick carries on.
  - **The provider credential is the one that made the servers** (the operator token, or the
    connected account's stored grant), as reverse DNS already uses it. No token is typed.
  - **Machines are named by instance id, never by node row:** the rows swap at step 10 and the
    machines do not, so the way back after the swap acts on the same instances.
  - **The records follow the machines:** after each swap, each provision's address is the one its
    instance holds, and each row whose host was one of the two addresses names its machine's.
  - **Reverse DNS** belongs to the address; after the swap each is read back and set again if the
    provider dropped it.
  - **The preflight refuses** a second public IPv4 on either server (a swap moves one), Network
    Helper off or unreadable (read from the configuration profiles, or the interface settings on
    the newer interface generation), a provider firewall on S that T lacks, and an AAAA record of
    the site naming S's IPv6 (IPv6 never moves: remove it or point it at T's first). A connected
    account's grant must carry `ips:read_write` and `firewall:read_only`; the operator token's
    scopes cannot be read, so a missing one shows at the provider's first refusal.
  - **Unproven, the swap is undone** (`undoing`): the addresses back, T restarted on its own, S
    booted, still frozen; the copy is `ready` again with the reason. Step 9 said to shut T down;
    restarting it instead leaves it a reachable dormant copy, so trying again or Copy again work.
  - **The relay's Linode grant is unchanged** (`relay_admin.php`): a relay never switches over.
  - Tests: `site_copy_ip_swap` 34/34 (the driver's calls against a fake Linode API, each preflight
    refusal with nothing frozen, the switch end to end with the records and reverse DNS, the way
    back after the swap, an unproven copy undone and then the way back).
- **WP10 built (2026-10-03, ahead of the Phase 1 live proofs, owner's order): a copy from
  backups when the source is dead; unit- and db-tested, not yet live.** On M: `SiteCopy` `scp_from`
  (`backups`), the Copy tab's second kind of copy, `SiteCopyRunner::key_request()`, the run
  `host_report`, `copy_look`, `copy_take_key`, `copy_stage`, `copy_restore`, `site_census` on the
  copy alone, `SiteCensus::check()` (1.1), `JobCommandBuilder` 1.90, `JobResultProcessor` 1.52,
  `ManagementJob` 1.30 (`copy_take_key`'s claim budget). Agent 1.54.0 (`copy_look`, observe;
  `copy_take_key`, operate, only under `quiet copy`). On T's site: `CopyKeyHandoff` 1.0, the
  `/copy-key` page (`views/copy-key.php`, `logic/copy_key_logic.php`, `assets/js/copy-key.js`) and
  the settings rows `copy_key_request` and `copy_key_answer`. Schema applied on dev. Owner
  decision while building (2026-10-03):
  - **The recovery key is typed only on T's own page, never on M (Q10, below).** Typing it on M's
    Copy tab would hand M's web tier every backup the key opens.
  Choices made while building:
  - **What the browser does, and what T does.** The chain's data key is sealed to the recovery key
    in a libsodium sealed box, which WebCrypto cannot open. It can do the one step that needs the
    recovery key: X25519 of it with the box's ephemeral public key. That value opens that one box
    and nothing else; T finishes (`crypto_box`'s HSalsa20 key, the BLAKE2b-192 nonce of both public
    keys, XSalsa20-Poly1305). So no JavaScript crypto library and no re-seal: the browser posts
    that value and the recovery public key to T's own page over HTTPS, and M carries only the
    sealed key in the job. Checked against PHP's libsodium both ways (a Go test vector, and
    WebCrypto in Node against `sodium_crypto_scalarmult`).
  - **The page needs no sign-in.** T is a blank site whose admin the owner does not know; the
    quiet state lets only the look cookie's holder through, and the page does nothing unless T's
    agent staged a request. A forged answer can only fail to open the box.
  - **A wrong key is not the end:** T checks the key's fingerprint against the envelope's and the
    box, writes the reason back to the page, and keeps waiting (an hour, as an approval does).
  - **The look path comes first** (`copy_look`), so the tab can send the owner to T's page while
    `copy_take_key` waits.
  - **Any age:** the newest chain is taken whatever its age (`newest_chain($source, null)`); the tab
    and T's page show it, since it is the data lost. A chain sealed to another recovery key than
    the site last reported is named on M and not asked for.
  - **The census is judged alone** (`SiteCensus::check()`): canary open, no dead secret, sampled
    offloaded files answer. Counts are reported, not judged.
  - **The switch-over from backups** freezes nothing and has no final copy. When M created S's
    server, a local `power_off` step turns it off first (I1); otherwise the owner confirms it is
    off. The address moves by IP swap or through the proxy as in Phase 1, or by the owner's own
    DNS change (`manual`, Phase 2 only), proven by the look path at the site's name. There is no
    way back once T has taken the node.
  - **The certificate and DKIM keys do not travel** (D5). The proof at the site's name checks the
    copy's look answer without checking the certificate (the look secret is the proof), and the
    start ends with `provision_certificate` on the node, once its name points at T. Until then
    browsers warn, and a proxy set to check the origin's certificate strictly refuses T: Phase 2's
    accepted cost, said on the tab.
  - Tests: `site_copy_from_backups` 30/30 (preflight with a dead source, the six steps on the copy,
    the key request's sealed key opening only with the recovery key, a dead secret stopping the
    run, the switch-over with the old server powered off and the owner's DNS change, no way back,
    the page handoff's shapes); agent `copy_take_key_test.go` (the browser's value opens the box,
    a libsodium vector, the word writing `chain.key` and the vouch, its refusals, `copy_look`) and
    `copy_key_handoff_test.go` (a wrong answer shown and the wait going on, a decline, a withdrawn
    job, the window).
- **getjoinery onto bare metal (2026-10-03): a container site and a management node, copied from
  their backups; unit- and db-tested, not yet live.** Owner's goal: move getjoinery.com (node 33, a
  container on docker-prod, behind the Cloudflare proxy, itself a management node) to a bare-metal
  server on the new Linode account, using the copy from backups with the old site stopped by hand at
  the cutover. A rehearsal copy (look, then Discard) comes first and changes nothing on the source.
  - **A container site is copied from its backups.** Nothing is asked of its machine, and the
    copy's own config is kept (`--adopt-secret-key` takes only the key), so a backup made in a
    container restores onto bare metal as any site's does. A copy of the running site still refuses
    a container: the freeze is a machine's firewall and boot unit, which a container cannot set on
    itself.
  - **A management node is copied from its backups,** never this management node itself (its own
    row would be swapped from under it). Its secrets reach the copy only through the owner's
    recovery key on the copy's page, and a dormant copy runs none of its fleet's work. A copy of a
    running management node still refuses.
  - **B45 — A copy from backups of a source on 0.8.453–0.8.455 has no key page.** TRACED: the copy
    installs the source's release, and `/copy-key` (`views/copy-key.php`, `CopyKeyHandoff`) first
    ships in 0.8.456; 0.8.455's archive carries neither, and the preflight's floor was the live
    copy's 0.8.453. Fixed: `JobCommandBuilder::COPY_FROM_BACKUPS_MIN_VERSION` (0.8.456), applied by
    `SiteCopyRunner::source_refusals()` (1.6) to a copy from backups.
  - **B46 — The proxied move takes the other sites on a shared server.** TRACED: `ProxiedOriginMove::plan()`
    took every A/AAAA in the zone naming the source's address. For getjoinery that is docker-prod's
    address, so demo.getjoinery.com (proxied) would have moved to the copy, and
    developers.getjoinery.com (unproxied, same address) refused the whole move. Fixed in
    `ProxiedOriginMove` 1.2: a container site moves only its own name and `www.`; other names on the
    shared address are left alone.
  - **A container's server is never powered off or swapped:** `IpSwapMove` 1.1's `provision_of()`
    answers null for a container site and `record_refusals()` names why, so the switch-over from
    backups never powers off a shared server; the owner stops the container.
  - Tests: `site_copy_from_backups` 37/37 (a container and a management node allowed from backups and
    refused from the running site, this management node refused, the release floor, no swap or
    power-off of a shared server), `site_copy_switch` 46/46 (sibling records on a shared address),
    `site_copy_runner` 73/73, `site_copy_ip_swap` 34/34, `job_command_builder` 391/391.
- **WP9 built (2026-10-02): Clone is retired; B1–B20 and B23 close with the code.** Deleted:
  `utils/clone_export.php`, `utils/clone_export_arm.php`, `utils/scrub_sealed_secrets.php`, the
  `clone_export_key` setting, the `from_backup` install mode (model, Install New Node form,
  `build_install_node`, the provision pipeline's source arming), `cvp_clone_key_sealed` and the
  unused `cvp_backup_source`, the `clone_key` stdin source, `JobResultProcessor`'s two clone
  handlers, the installers' `--clone-from` / `--clone-key` / `JOINERY_CLONE_KEY` and the
  `_site_init.sh` clone block (`install.sh` 2.92, `_site_init.sh` 3.10, `Dockerfile.template` 5.8),
  and the clone load gate. The agent's `clone_export_arm` word is gone from agent 1.52.0; the
  plane stopped sending it in the same release, and an older agent that still reports it is
  simply never asked. Data: core migrations 206 and 207 remove the setting row and its sealed
  key's registry row on every site; `sm_010_clone_retired` fails a from_backup provision still
  working, records every from_backup row as `fresh`, and drops the two columns. Retry Install on
  an old clone refuses, pointing at the Copy tab. Kept on purpose: the log redactors still mask
  `export_key` / `clone_key` / `--clone-key=` (old job rows on a management node carry them), and
  `rebase_site_container.sh` still knows a clone container's `CLONE_FROM` / `CLONE_KEY` variables
  so it can rebuild one. Tests: `installer_contract` 787/787, `job_command_builder` 375/375,
  `customer_cloud_provisioning` 115/115, `db --changed` 514/515 (the agent bundle awaits publish).
- **First live run (L0-copy, started 2026-10-01): the copy steps by hand, before WP7a and WP8.** The
  owner chose to prove WP2–WP5 on real machines before building on them. No switch-over: S, then
  a dormant T, then export, import, stage, restore and the census, then a private look at T.
  - S: node 75176 `copytest`, `copytest.jeremytunnell.info` (Linode DNS, account getjoinery),
    66.228.35.148, Ubuntu 26.04.1, 1 GB, release 0.8.452, agent 1.49.0, backups to B2
    `joinery-test-bucket`. Seeded: three stored messages, a sealed OAuth secret, an IMAP account
    with a sealed password, a custom theme `copytest-custom`, a DKIM key. No offloaded file
    (owner: skip cloud storage this run).
  - Jobs are queued from dev by a scratch helper through `JobCommandBuilder` and
    `ManagementJob::createFromBuild`, and the copy row is linked by hand, since WP8 does both.
  - **B29 — A fresh install has no sealed-secret canary and an empty registry.** OBSERVED on S.
    `_site_init.sh` never runs `update_database`, the one place that seeds the registry and mints
    the canary, so until the first upgrade a site cannot tell a wrong key from a corrupt value, and
    the census's canary check reads `absent` on both sides. Worked around on S by running
    `update_database`. Fixed in `_site_init.sh` 3.9: a bare-metal install runs `update_database`
    once, after the plugin bundle, as a container does at every start (`installer_contract`).
  - **B30 — The install's last check follows a redirect to the bare IP.** OBSERVED on S: it reported
    `http://66.228.35.148/login` (302) before the certificate existed; the domain redirects
    correctly. TRACED: the front page sends a new visitor to `/login`, and curl's `redirect_url`
    resolves that relative path against the probe address. Fixed in `install.sh` 2.91: both closing
    checks read the `Location` header as sent, and a redirect that stays on the site is a responding
    site (`installer_contract`).
  - **B32 — A new site's admin cannot sign in.** OBSERVED on S (ERR_TOO_MANY_REDIRECTS at
    `/terms-accept`), TRACED: since every signed-in page runs the navigation gates (a5d22d7e,
    2026-09-24), the terms gate sent an admin who owes a password change off
    `/change-password-required` and the password gate sent them back; the wizard gate did the same
    to `/terms-accept`. Fixed in `SessionControl` 1.8: the first gate owed holds its own page and
    ends the check (`navigation_gate_target()`, tested in `setup_wizard_gates`). Every fresh install
    since 2026-09-24 is affected until it takes the release with the fix.
  - **G8 — A site that backs up only to its own bucket cannot be copied.** The copy fetches the chain
    through links M signs on M's target under S's slug (`backup_node_of`); a site-owned target's
    credential is on S, never on M. Sites whose backups M runs are covered. The run backs S up from
    dev, as production does; the site-owned target set on S at first was removed.
  - **B33 — M learns a newly proven recovery key up to six hours late.** OBSERVED: the join's first
    `recovery_key_report` ran before the owner set the key, read `unconfigured`, and the six-hour
    throttle on that report kept every backup of S refused ("no backup recovery key") until the
    report was asked by hand. Fixed in `JobResultProcessor` 1.46: the window is six hours once a
    node's key is proven and two minutes otherwise, so the next status check (hourly, or the one the
    node detail page tells the operator to run) asks again (`job_result_processor` 222/222).
  - S's first backup from dev: full, 70.8 MB (code 68.8 MB, data 1.9 MB, database 140 KB),
    `chain-20261001_161743` in dev's bucket, 11 s.
  - **B34 — Any unrecognised argument starts a second agent.** OBSERVED on T: `joinery-agent
    version` printed "starting joinery-agent v1.49.0" and ran the service in the foreground beside
    systemd's, under the node's identity (I1: two agents claim one node's jobs). `runCLI` returns
    "not handled" for anything it does not know, and `main` then starts the service. Every real
    launch (systemd, the cron keepalive, `build_installer.sh`) passes no argument. Fixed in agent
    1.49.1: an unrecognised argument prints the usage and exits 2; only no argument starts the
    service.
  - T: Linode 107213311 `copytest-t`, 173.255.229.58, us-east, created through dev's operator
    token with a generated root password; dormant install 6 min, ending in `quiet copy` (firewall,
    web drop-in, cron and certbot held, Postfix deferring; visitors get 503). Join request 2437,
    fingerprint `d895a76d1b225869`.
  - **B35 — An approval had to be given twice.** OBSERVED on S's export: the answer posted at
    16:43:27, the page reloaded at once and still showed the request with its form, the agent took
    the answer at 16:43:28, and the owner approved again at 16:43:35 to a request already gone.
    Fixed for every scope: `ApprovalChallenge` 1.3 (`pending()` reports `answered`; a second
    answer or a decline after an answer is refused) and `ApprovalChallengePanel` 1.1 (an answered
    request says so and reloads until the agent has taken it). `approval_challenge` 125/125.
  - Export (job 49570): owner approved on S in about three minutes; 22 KB bundle, chain
    `chain-20261001_161743`, 1 certificate lineage, 1 DKIM key, 27 host files, sealed to
    `d895a76d1b225869`. Import on T (job 49571): signature and seal checked, chain key, vouch and
    27 host files written.
  - Stage on T (job 49577): 5 artifacts, 70.8 MB, each checked against the vouched manifest.
    Restore on T (job 49578): T kept its own config and site key, adopted S's secret key, loaded the
    database, re-rendered its vhost, no certificate attempt.
  - **Census, S against T: 3 sealed secrets present on both, 0 dead, canary `ok` on both; every
    file digest equal; 207 tables, two differing** (`rql_request_logs` 75 vs 50,
    `vse_visitor_events` 57 vs 50: S logged the owner's visits after its backup). Informational
    compare: no blocking difference. T serves S's certificate (same SHA-256), holds S's DKIM key
    (same hash, same owner and mode) and the custom theme.
  - **B36 — A quiet site answers no one off the machine.** OBSERVED on T, TRACED with nft counters:
    the quiet rule rejects every packet the web user (33) or Postfix (106) sends off the machine,
    replies to inbound connections included. Apache's response left as uid 33 and was rejected, so
    a visitor got no 503, the owner's look could not work, and a sending server got no deferral,
    only a timeout. WP5 proved the rule from the machine itself, over loopback. Fix,
    `_site_state.sh` 1.4: `ct direction reply accept` before the rejects. Trialled live on T: no
    cookie 503, look link 303 with the cookie, login 200 with it, and the web user's own outbound
    connection still refused. `site_quiet` gate 57/57. Applied on dev; T takes it from S's tree at
    the next refresh.
  - **The look (2026-10-01 17:40): passed.** The owner pointed `copytest` at T in Linode DNS, T's
    converger was paused and the B36 rule put in by hand for the look, and in the owner's own
    browser: the look link set the cookie, the passkey made on S signed in on T, and the vault made
    on S opened. T's converger restarted afterwards and the hand rule is gone. A browser that
    already held a connection to S got S's 404 for the look link until Chrome's sockets were
    flushed: worth one line wherever the look link is shown.
  - **B31 — Postfix on 26.04:** `postfix/postlog: not owned by root: /var/spool/postfix/etc/resolv.conf`,
    and `/etc/aliases` has no root alias. OBSERVED on S, TRACED, both cosmetic. (1) The Ubuntu
    package's `configure-instance.sh` copies `/etc/resolv.conf` into the chroot at every Postfix
    start through `syncfiles.pl`, which keeps the source's owner, and on 26.04 resolved's file is
    owned by `systemd-resolve`; no Postfix service on our hosts runs chrooted, so the copy is never
    read. (2) Debian's postinst creates `/etc/aliases` with only `postmaster: root` when nobody
    answered `postfix/root_address`, and warns once, in the install log, on every fresh install
    unless a root alias already exists (answering `none` does not quiet it). The alias would be
    inert: `mydestination` is `localhost` only (`install_email.sh`), so root's mail is addressed to
    the box's mail name and never delivered locally. DECIDED (owner, 2026-10-01): no change to
    either. The first is Ubuntu's packaging bug and touches nothing we use; the second is a
    low-level channel the platform does not use, since a failed system job is a failed systemd
    unit (logrotate, certbot, apt and e2scrub are timers on 26.04), which the node's host report
    carries to M's fleet attention notice.
- Decided:
  - D1: a faithful copy, with switching over and deleting kept separate; the old Clone is retired.
  - D2: the backup chain carries the copy.
  - D3: designed for Linode to Hetzner (the exit capability); built in the order proxied origin
    change, IP swap, DNS switch (revised 2026-09-28).
  - D4: the new server keeps its own agent key and takes over the old node's identity.
  - D5: the certificate and the DKIM keys travel.
- **Goal set by the owner (Q1):** copy between any two servers — across Linode accounts and regions,
  and to another provider. Linode to Hetzner is the design target.
- Q6–Q9 answered (Q6, Q7 2026-09-28; Q8, Q9 and G7 2026-10-01): WP4 seals to T's agent key and asks
  the owner on S at every export; T stages S's chain with its own word.
- **Q3 answered (2026-09-28):** the test domain is `jeremytunnell.info`.
- **Q5 answered (2026-09-28):** L0 creates its own source each run.
- **Q4 answered (2026-09-28):** jeremytunnell switches by changing its Cloudflare origin (WP7a).
- **Second round (2026-09-27, night):** three read-only investigations and an adversarial review. They
  found B24–B27 and changed the design. Site states, the export approval on S, S vouching for each run,
  the corrected D4 mechanism and the dormant-until-proven swap order all came from it.
- **Architecture review (public-html-91, 2026-09-28):** M1 and M3–M12 applied. One quiet state
  instead of two; one gate at the converger; chain data keys travel instead of S's backup key; one
  `copy_export` word; the two node rows swap instead of a retired-source record; no copy mode in
  `restore_chain.sh`; no load check; approvals folded first. M2 accepted by the owner: no
  `--continue`; every apply is a full apply.

**Related:**
- `specs/backups_remaining_gaps.md` §2: its handover of the backup key and the ledger is WP4 here.
- `specs/standalone_boxes_ubuntu_2604.md`: jeremytunnell onto 26.04 is this spec's first real use.
- `specs/backup_database_incrementals.md`: faster final copies later.
- `specs/edge_routing_tier.md` (`build_move_site`) and `specs/sentinel_managed_recovery.md`
  (rungs 6–7): both assume a copy or rebuild that does not exist yet. This spec is it.

**Design rule (owner, 2026-09-28): self-contained pieces.** Each piece is built, tested and changed on
its own. Code that has nothing to do with copying never learns that copies exist. Once this ships,
testing every combination of installed states on every change will not scale. A piece that needs
every other subsystem to cooperate, or that changes a format every site depends on, is redesigned.

## What this does for the owner

It makes a new server hold the same site, complete, and lets the owner switch over to it when ready.
Afterwards everything works on the new server:
- sign-in with passkeys, and the vault;
- stored passwords and API keys, mail accounts and backups;
- files kept in cloud storage, and custom themes.

The site keeps its name and, where the provider allows, its IP address. Two separate actions:
- **Copy** — build a faithful copy on a new server. The copy sits idle (dormant) and changes
  nothing on the old server. It can be refreshed, looked at privately, or thrown away.
- **Switch over** — freeze the old server, bring the copy up to the minute, point the address
  to the new server and start the site there. The old server is left stopped and whole.
  - The downtime is one database copy.

Deleting the old server is never part of either. The owner does that separately, whenever they
choose, with the ordinary delete.

Uses:
- moving a site to a newer operating system (jeremytunnell onto Ubuntu 26.04);
- moving it to a bigger or smaller server, or off a failing one.

Phase 2 does the same from backups when the old server is already dead.

**What exists today, and why neither does this:**
- **Clone** (Server Manager install type `from_backup`, which uses no backup at all) makes a
  partial copy of a running site over HTTPS.
  - It wipes every stored secret, and never carries themes, plugins, `storage/` or `config/`.
  - On a site of real size it fails outright, or reports success with the uploads missing (B1–B20).
- **Restore onto new hardware** carries everything, the secret key included, then throws that key
  away on purpose. Every stored secret is dead afterwards (B21).
- Five documents say one of these already moves a site (B22).

## What a copy promises (the invariants)

- **I1 — One site runs in one place.** At no moment do two machines both run the site's
  scheduled work, pull its mail, take its backups or answer its agent jobs. A duplicate is worse
  than an outage here:
  - Two relay pullers split the mail between them, silently.
  - Two backup writers share one chain prefix.
  - Two agents with one identity each claim whichever job they poll first.

  This is why the copy stays dormant until switch-over, and why switching over stops the old
  server in the same step.
- **I2 — Nothing is lost silently.** Before switch-over the copy is checked against the source's
  own count: every table's rows, every file under the site's directories, and every stored secret.
  A mismatch stops it.
- **I3 — The way back stays open until the owner closes it.**
  - Before switch-over, nothing on the old server has changed.
  - After switch-over, the old server stays whole and stopped until the owner deletes it.
- **I4 — Same domain.** Passkeys are bound to the domain: the relying-party ID is the host of
  `webDir` (`PasskeyService.php:148-159`), and no server secret is involved. So they keep working
  only if the domain stays.
  - A new domain is a rename and is out of scope: every passkey would stop working, and the vault
    would open only by recovery code.
- **I5 — Secrets leave S only with the owner's approval, and the management node never sees one.**
  - Keys and secrets that cross between the machines are sealed to T, so M carries ciphertext only.
  - The export needs the owner's recovery-key approval on S itself (step 3). So neither a compromised
    web tier on M nor M's root can start a copy to a key of its own choosing and collect the site's
    secrets.

## Part 1 — Bugs found (2026-09-27, read-only)

Grades:
- **TRACED:** the code path was followed.
- **READ:** read, and the logic says so.
- **INSTINCT:** suspicion, to confirm.

### The Clone path

Bugs B1–B20 live in `utils/clone_export.php`, `utils/clone_export_arm.php`, the `_site_init.sh`
clone block (`:314-512`) and the `from_backup` provisioning on the management node.

**Disposition (D1 decided: Clone is retired):** each bug is closed by deleting the code (WP9).
- The **Copy** line on a bug is the lesson, carried into the new copy as a requirement.
- The **Fix** line is kept as a record of what a repair would have been.

- **B1 — The rate limit breaks the transfer.** TRACED.
  - What happens: `clone_export.php:91-105` allows one request per minute per IP, HEAD requests
    included. `_site_init.sh` sends a HEAD and then a GET seconds apart, for uploads (`:382/:390`)
    and for static files (`:409/:417`).
    - **Small site:** the HEAD gets 429 and the uploads are skipped silently ("Source has no
      uploads to transfer"). The static files are skipped the same way, and the clone reports success.
    - **Large site:** the GET gets 429 and the install exits 1.
    - **Bare metal:** the manifest request (`install.sh:3828`) and the database request are
      seconds apart. The database request is then likely refused too, and a bare-metal install is
      one-shot. READ.
  - Fix: no rate limit on an armed, key-authenticated export; the arm is the gate.
  - Copy: each artifact is fetched by a signed link, with retry.
- **B2 — A refusal reads as "nothing to send".** TRACED.
  - What happens: `_site_init.sh` treats any JSON response as "empty" (`:384`, `:411`), so a 403 or
    429 is read as "the source has no uploads". This is why B1 and B4 lose data silently rather
    than fail.
  - Fix: an explicit status and count in every response; anything but a success with the expected
    count fails.
  - Copy: a count check (WP3).
- **B3 — Docker: the clone is declared done while it is still running.** TRACED.
  - What happens:
    - On Docker the pull runs in the container's start command. `install.sh:4621-4659` waits 60 s,
      warns, and carries on to `INSTALL_SUCCESS`.
    - The management node then completes the provision and disarms the source
      (`ProvisionCustomerCloud.php:657-705`).
    - The still-running pull gets 403 JSON and, by B2, skips the files. The result is a site with
      a database and no files, marked done.
  - Fix: the clone's outcome is part of the install's result.
  - Copy: every step reports its own outcome.
- **B4 — Docker: the site's agent is never enabled on a clone.** READ.
  - What happens: `docker exec … agent_control.php` (`install.sh:4668`) runs before
    `Globalvars_site.php` exists, because clone mode writes it last. So `Globalvars.php` fails, the
    agent never joins, and the install's root password is never retired.
  - Fix: enable the agent after the config exists.
- **B5 — A source with the mailbox plugin cannot be cloned.** TRACED.
  - What happens: the dump grants to `iemap_<db>` (`install_email.sh:464,495-497`), a role the
    target lacks. `ON_ERROR_STOP` aborts at the end of the load and the install exits 1.
    `restore_database.sh:355-440` creates missing roles; the clone does not.
  - Fix: the same role creation.
  - Copy: the restore already does this.
- **B6 — A failed clone can never be retried.** TRACED.
  - What happens:
    - There is no single transaction, `createdb … || true` hides errors, and the clone branch
      ignores `DB_EXISTS`. A rerun loads into a half-filled database and fails on "already exists".
    - On Docker the start command fails and `--restart unless-stopped` loops forever, and each loop
      runs `pg_dump` again on the source.
    - Retry Install keeps the old volumes (`install.sh:4293-4307`).
  - Fix: drop and recreate the database on a rerun; no restart loop on a failed clone.
  - Copy: each step is idempotent, and staging restarts from what is already fetched.
- **B7 — Themes and plugins never travel.** TRACED.
  - What happens:
    - The `themes` and `plugins` actions of `clone_export.php` are never called. The release ships
      empty `theme/` and `plugins/` directories (`publish_upgrade.php:764-772`, READ).
    - Bare metal points `UPGRADE_SERVER` at the source (`install.sh:3869`), which overrides the
      management node's `--upgrade-server`, and whose `publish_theme` answers 404 unless the source
      carries `server_manager`.
    - A custom theme (not in the release) is lost on both shapes. The form's promise of "database,
      uploads, themes and plugins" (`install_node_form.php:281`) is false.
  - Fix: fetch both, and stop repointing the upgrade source.
  - Copy: the code part of a chain carries `public_html` whole.
- **B8 — Bare metal never migrates after a clone.** TRACED.
  - What happens: only the Docker start command runs `update_database`; bare metal never runs it or
    the plugin sync, so a schema older than the code is left unmigrated.
  - Fix: run both.
  - Copy: the target runs the source's own code, so there is no gap to migrate.
- **B9 — `storage/` is not carried.** TRACED.
  - What happens: raw mail lives in `storage/mailbox/` (`RawMessageStore.php:22-26`). Its rows
    arrive and point at nothing.
  - Fix: export `storage/`.
  - Copy: the data part of a chain carries it.
- **B10 — `config/` is not carried, and every stored secret is wiped.** TRACED.
  - What happens:
    - The target mints a new `secret_box_key` (`_site_init.sh:256-283`) and
      `scrub_sealed_secrets.php` nulls all 35 registered sealed values. Right for a partial copy; for a faithful copy
      it is the whole problem.
    - The lost values include the backup-target credential, OAuth client secrets, IMAP passwords
      and tokens, the relay identity, the Joinery Direct signing key and the cloud accounts.
- **B11 — No space check, and uploads need twice their size.** TRACED.
  - What happens:
    - The manifest's sizes are printed and never compared (`install.sh:3854-3857`).
    - Uploads go to one temp file and are then unpacked (`:389-397`), so 16.7 GiB needs about
      33 GiB. The default 25 GB instance cannot hold jeremytunnell.
    - There is no resume and no checksum. A killed transfer leaves its temp file, and each Docker
      restart adds another.
  - Fix: a space check against the manifest, unpacking as a stream, and a checksum.
  - Copy: a preflight space check (WP8), and artifacts that are verified and resumable.
- **B12 — A HEAD request makes the source pack every upload for nothing.** READ.
  - What happens: PHP runs the whole handler for a HEAD (`clone_export.php:337-342`). On a 2 GB
    source that is two full gzip runs over already-compressed media.
- **B13 — The script can die without a word.** READ.
  - What happens: under `set -e` and `pipefail`, `CONTENT_TYPE=$(curl -sI … | grep … | head -1)`
    (`:382`, `:409`) ends the script on a network error or a missing header, with no message.
- **B14 — The source's tar hides what it skipped.** READ.
  - What happens: `2>/dev/null` and an ignored `passthru` status mean that files the web user cannot
    read (root-owned, say) are left out, and nothing compares counts afterwards.
  - Copy: a count check (WP3).
- **B15 — The instance is created before the source is armed.** TRACED.
  - What happens: `handle_ready` queues the arm job (`:305`) and calls `createInstance` in the same
    tick, although the comment says "a refusal leaves no box". A failed arm leaves a billed instance
    and a failed provision.
- **B16 — The export key outlives the clone.** TRACED/READ.
  - What happens:
    - Disarming is one fire-and-forget job, and nothing retries it.
    - Neither `clone_export_arm.php` nor the agent's `clone_export_arm` sets an expiry.
    - The key also stays in the new container's `--env-file` (`docker inspect` shows it). If the
      disarm fails, root on the new box holds a live full-export credential to the source.
- **B17 — The export key stays in plain text on the management node.** READ.
  - What happens: it is in `mjb_parameters` of every install and retry job, and
    `blank_install_clone_key` blanks only the latest one (`:666-671`).
- **B18 — The PostgreSQL check reads the wrong header line.** READ.
  - What happens: `refuse_newer_dump` reads "Dumped from" (the server's version), but the syntax
    follows "Dumped by pg_dump version". `restore_database.sh:339-341` reads the right line first.
- **B19 — The source's machine state travels unreset.** INSTINCT.
  - What happens:
    - `agent_join_state`, `agent_enabled`, `backup_path_slug` and the scheduled-task rows all come
      from the source.
    - If the copy's backup credential is entered again, its retention could delete objects under
      the source's prefix.
- **B20 — Nothing freezes the source.** TRACED.
  - What happens: the database is dumped first and the files are packed later, with nothing stopping
    writes between the two. Anything written after the dump is lost at cutover.
  - Copy: the freeze (the quiet state, WP5).

### The restore path and the documents

These are fixed whatever D1 decides.

- **B21 — A rebuild from backup kills every stored secret.** TRACED.
  - What happens:
    - Backups carry the source's `config/Globalvars_site.php`, and with it its `secret_box_key`
      (`backup_files.sh:241`; `backup_project.sh:321,569`).
    - `restore_chain.sh` holds the target's own copy aside and puts it back (`:376-392`, `:480-498`).
      `restore_project.sh` deletes the source's copy from staging (`:646-651`).
    - So on new hardware every sealed value is dead. Nothing warns except one batched
      `key_mismatch` alert on the next `update_database` (`SecretReconciler.php:134-150`).
    - Regenerable secrets are then quietly minted again. That changes the Joinery Direct signing
      key, so it no longer matches its DNS record, and it breaks outstanding signed file links.
  - Fix: WP2's `--adopt-secret-key` takes the source's key. The in-place restore is correct as it is: it runs
    on the same machine, so the key is the same.
- **B22 — The documents say a faithful copy already exists.** TRACED.
  - `docs/sealed_secrets.md:116-117` and the header of `scrub_sealed_secrets.php` say "a genuine
    move is restore-from-backup, which carries config/ and the matching key, so nothing is
    scrubbed". It is the opposite (B21).
  - `reconcile_site.sh:144` says a backup never carries them. False.
  - `docs/backups.md:1040-1042` says a dead node is "rebuilt and restored (`install_mode =
    from_backup`)". `from_backup` is a live HTTPS pull and needs the source up.
  - `docs/deploy_and_upgrade.md:855` and the Server Manager overview (`:506`, `:1692-1700`) say the
    same.
  - `specs/dev_workstation_migration.md:4-5` and `install_node_form.php:281` (B7) overstate it too.
  - Fix: WP1. The documents say what is true today, and say it again when this lands.
- **B23 — `from_backup` names the wrong thing.** TRACED. The mode uses no backup. It goes with WP9,
  or is renamed `clone` if D1 keeps Clone.

### Found in the second round (2026-09-27, night)

These are fixed whatever else happens.
- **B24 — The Hetzner DNS driver calls an API Hetzner has shut down.** TRACED/OBSERVED.
  - What happens: `includes/dns/drivers/HetznerDnsDriver.php:20` uses `https://dns.hetzner.com/api/v1/`.
    Hetzner retired that console and API on 2026-05-27, and it now answers 301 to the Cloud console.
  - DNS now lives in the Cloud API (`api.hetzner.cloud/v1/zones`), and old DNS-console tokens do not
    work there.
  - Fix: rewrite the driver against the Cloud API. The deferred DNS switch needs it for Hetzner.
- **B25 — A site's web server config is pinned to the machine's IP address.** TRACED.
  - What happens: the vhost is rendered as `<VirtualHost {{SERVER_IP}}:80/443>` with the address from
    `hostname -I` (`install.sh:1132-1137`, `default_virtualhost.conf:73,154,219`). `render_vhost.sh`
    and `virtualhost_update_script.sh` re-read the address from the existing file, so nothing ever
    renders it again.
  - Any change of address takes the site down: an IP swap, or a provider re-addressing the machine.
  - Fix: name-based vhosts on `*:80` / `*:443`. Otherwise WP12 must re-render on every change of
    address.
- **B26 — A large database cannot be restored on Ubuntu 26.04.** READ.
  - What happens: `restore_database.sh` decrypts and then gunzips the whole plain dump into
    `/tmp/jy_restore_*` (`:203`, `:254`, `:270`).
  - On 26.04, `/tmp` is a tmpfs sized from RAM. `restore_project.sh` 1.4.1 already moved its
    extraction off `/tmp` for this reason, but `restore_database.sh` did not. jeremytunnell's 2.8 GB
    database would not fit on a 2 GB box.
  - This is a disaster-recovery bug today, on every 26.04 box.
  - Fix: stage beside the archive, on disk.
- **B28 — Any account can hold the host converger off.** OBSERVED 2026-09-28.
  - What happens: the converger creates `/run/joinery/host-installers.{site}.lock` with the default
    umask (0644). `flock` needs only an open descriptor, so any local account, the web user
    included, can open it read-only and hold it; every converger run then waits ten minutes and
    exits 0 having done nothing. `user1` took dev's lock with `flock -n -s`.
  - Fix: create the file 0600 and tighten an existing one (`host_runner_lock.sh` already creates
    it 0600).
- **B27 — A restore races the host converger.** READ.
  - What happens: `restore_chain.sh` takes no lock. The converger runs every minute, and when the
    release changes it runs the plugin installers and `site_housekeeping.sh`
    (`_plugin_installers_start.sh:972-1010`) against a tree that is half restored.
  - Fix: one lock, which the converger and every restore both take.

### Gaps (not bugs: missing capability)

- **G1 — A dead machine cannot be rebuilt from its backups through the management node.** TRACED.
  A new node is refused at three separate checks:
  - The management node signs only object keys under the node's own slug
    (`JobCommandBuilder.php:2981-2986`).
  - The upload ledger refuses any artifact this machine did not upload
    (`BackupLedger.php:277-281`, used at `BackupStaging.php:389` and in `restore_objects`).
  - The envelope does not open with the new machine's site key (`BackupStaging.php:333-345`).
  - The only way today is a shell with the recovery key. This is Phase 2.
- **G2 — A fresh site can approve nothing.** A restore approval needs a proven recovery key on the
  target's site (`approval.go:424-446`), and a fresh install has none.
- **G3 — There is no freeze, no maintenance mode and no read-only mode, and nothing can hold a site's
  scheduled work off.**
  - The host converger writes the site cron again within a minute of its removal
    (`site_housekeeping.sh:108-117`, a core installer, `_plugin_installers_start.sh:223`).
  - `joinery-agent disable` is undone by the converger's projection of the `agent_enabled` setting
    (`cli.go:325-331`, `install_agent.sh:256`).
- **G4 — There is no way to apply only the newest increment.** `restore_chain.sh` always replays
  from level 0.
- **G5 — Some host state is not in any backup.**
  - Not in any backup:
    - `/etc/letsencrypt`;
    - the DKIM keys under `/etc/opendkim/keys/` (`provision_dkim.sh:82-87`);
    - the Tailscale identity;
    - rspamd's redis data;
    - anything installed by hand.
  - After a rebuild, outbound mail goes unsigned (or is signed with a key DNS does not match),
    silently. A certificate waits for DNS, so an HSTS site is unreachable in that window.
- **G6 — The management node has no idea of a copy.** A new machine is a separate node. Nothing
  stops the source, re-points the node record or carries the backup chain.

## Part 2 — Copy and switch over (Phase 1: the source is alive)

**Engine: the backup chain (D2).**
- The same archives the site already makes every night carry the copy, restored by the same scripts
  that restore it after a disaster.
- The chain carries everything Clone missed except `vendor/` (see step 4):
  - `config/`, `storage/`, `static_files/` and `uploads/`;
  - the whole `public_html/`, custom themes and plugins included;
  - the database.

**The parties:** the **source** S (the managed node being copied), the **target** T (a new server),
the **management node** M and the **owner** O.

**v1 scope: a bare-metal S onto a bare-metal T, one site per machine.** That covers jeremytunnell.
- **Container sources** (the eight docker-prod sites) share one host address, so no IP swap is
  possible for them. A copy of a running container site comes with the DNS switch, deferred to its
  own spec; a container site is copied from its backups today, switched by a proxy or the owner's
  DNS change (getjoinery, above).
  - Their certificate, DKIM keys and proxy vhost live on the host.
  - Their cron belongs to the container's start command.
- **Container targets** are out of scope.
- **Copy mode refuses on any machine that hosts another site.**

### The quiet state (the foundation; WP5)

A root-owned file, `/etc/joinery/sites/{site}/state`, holds `quiet copy` or `quiet switchover`, or is
absent (a live site). One state, one mechanism; the reason picks the web page and gates one word.

- **`quiet copy`** is set only by the installer, at a dormant install. "Dormant" in this spec means a
  copy in this state.
- **`quiet switchover`** is the only reason the agent's `site_quiet on` can set. It freezes S.
- `copy_restore` runs only under `quiet copy`. So M cannot quiet a live site and then restore over
  it without an approval.

**Enforced at the machine, never in the site's code (design rule).** v1 puts one site on a machine,
so a quiet site is a quiet machine. No PHP in the site asks whether it is a copy. A feature added
later is covered without anyone thinking about it.

| What | While quiet (either reason) |
|---|---|
| Leaving the machine (mail sends, relay and IMAP pulls, Joinery Direct, payments, webhooks, AI calls, OAuth refresh, anything added later) | **a firewall rule rejects all outgoing traffic from the web user and from Postfix's own client**, except to the machine itself. All site PHP runs as the web user, for web pages and for scheduled tasks; Postfix's client is covered because the site can hand mail to the local Postfix over loopback. Rejected rather than dropped, so a page the owner opens on a copy fails its outside calls at once instead of hanging on each. |
| Site cron and scheduled tasks | the cron file is removed, and nothing writes it back |
| The host converger's installers | none run, core or plugin; they run again when the state clears |
| Web | one Apache drop-in, placed ahead of the site's vhost. `copy`: a per-copy secret cookie lets the owner look; everyone else gets 503. `switchover`: a maintenance page (later, forwarding to T). |
| Inbound mail (Postfix) | every smtpd service answers 4xx (`smtpd_client_restrictions=defer` on its master.cf entry), so senders keep the mail and retry. Deferring at the `joinery` transport instead would accept the mail into S's queue, where it exists only on S. |
| certbot renewal | timer off |
| Agent | on; accepts only the copy and switch-over words and read-only words |

- **One gate, at the converger's entry (`_plugin_installers_start.sh`).** While the state file exists,
  the converger asserts the quiet measures (the firewall rule, the drop-in, no cron file, certbot's
  timer off) and runs no installer. That is the only place that reads the state; `site_housekeeping.sh`
  and every other installer never learn it exists.
- **The firewall rule** lives in its own nftables table (`inet joinery_site_state`), so it never
  touches ufw or a provider firewall.
  - It matches outgoing traffic by owner (`meta skuid`) for the web user; traffic to loopback is
    allowed, which covers the local database and the local DNS resolver.
  - It is applied at boot before the web server starts, and re-asserted by the converger gate every
    minute. A reboot of a quiet machine keeps it.
  - Root is not blocked, so the agent still reaches M and backup storage, the restore (run by the
    agent as root) still downloads, and S's final backup run still uploads.
  - On a frozen S it enforces what "nothing runs" only hoped: a worker still running after the final
    dump cannot send anything (B20).
- **Setting it** (`site_quiet on`, or the dormant install): drop-in, cron file removed, certbot's
  timer off, Postfix deferred if armed, the firewall rule. Then it waits until the web user runs no
  command-line PHP (detached workers and task runs run that way; the PHP-FPM pool is always there
  and is idle behind the drop-in). A generic wait, not a list of known workers to keep up to date.
- **Clearing it** (`site_quiet off`) removes the drop-in and the rule and turns certbot's timer on.
  The next converger tick runs the installers as on any site: the cron is written and the mailbox's
  installer arms Postfix. The agent refuses to clear `quiet copy` until T holds S's node id (step 10),
  so a copy can never go live beside its source.

**Why each row matters (the second round's findings):**
- **Without the cron rule,** the converger writes a dormant T's cron within a minute, and T runs S's
  work against S's live settings. It backs up into S's chain, sends S's queued mail again, splits
  relay mail and moves S's IMAP messages.
- **Without the firewall rule,** an owner clicking Refresh while looking at T pulls relay mail. The
  relay deletes it, and the final copy then overwrites it: the mail is lost for good.
- **Without the web rule,** T's vhost answers every visitor who reaches its IP.
- **Without the Postfix rule,** mail delivered to a frozen S after the final dump exists only on S.

**The catch:** this cannot quiet one site on a machine that hosts others. That only matters for a
container target, which is out of scope. A container source is never dormant.

### Copy (steps 1–6; changes nothing on S)

1. **Preflight (M, read-only; refuses with its reason).** It refuses unless all of these hold:
   - v1 scope: S is bare metal, and T hosts nothing else.
   - S's agent has the copy words.
   - S has a working backup target, a proven recovery key, and a successful backup under 24 hours old.
   - S is not a management node (a management node, and a container site, are copied from their backups).
   - T fits:
     - disk: the staged chain, plus the extracted tree, plus the dump staged on disk twice (gz and
       plain SQL), plus the live database, plus headroom;
     - memory: at least S's.
   - T's PHP and PostgreSQL are at least S's.
   - For an IP swap:
     - the same Linode account and region;
     - Network Helper on for both instances, read according to each instance's interface model;
     - the account's token carries the `ips:read_write` scope;
     - T's firewall admits at least what S's does.

   It also lists what will **not** travel, for the owner to carry by hand or accept:
   - non-platform services from S's host report (Tailscale, redis data, anything installed by hand);
   - every fact naming S's IPv6 address, which never moves: AAAA records, SPF `ip6:` and IPv6 reverse
     DNS.
2. **Birth of T, dormant.**
   - **Any server, anywhere.** The owner creates an Ubuntu server (Linode, Hetzner, anything) and runs
     the command M shows. It is the existing `install.sh site … --enable-agent --management-node=URL`,
     plus `--dormant --copy-of=<S>`.
   - **M creates it** through a provider driver: Linode today, Hetzner later (WP13, deferred). A Linode T is
     created with S's own cloud account when an IP swap is planned.
   - Either way:
     - T installs **S's exact release**, because `vendor/` is never in a backup
       (`backup_files.sh:190`), so T's installed dependencies must match S's code.
     - T's install runs its one installer pass as any fresh site's does (that installs its agent,
       its converger and its vhost), makes no certificate attempt, and ends by setting `quiet copy`.
       From then no installer runs. The pass ran against an empty site: S's data arrives only by
       `copy_restore`, which runs only under `quiet copy`.
     - T's agent asks to join. The owner approves it on S's node page by its fingerprint.
     - M records T as a copy row: a `mgn_managed_nodes` row linked to S, with `mgn_install_state`
       `copy`. It shows in the fleet list with a badge ("Copy of S — dormant"), the way an
       installing node does, so a forgotten copy is visible.
     - One model method, `ManagedNode::is_operational()`, owns the list of not-working states
       (`installing`, `install_failed`, `copy`, `switching`, `retired`). The places that check for
       `installing` today (`FleetBackupPolicy::eligible_nodes`, the fleet list, the status level, the
       overview tab), and every automation that does not consult the install state yet (staged
       rollouts, pruning, uptime checks), ask it instead. So scheduled backups, notices and upgrades
       skip the copy. No list is filtered.
     - A job someone starts on the copy anyway is refused by T's agent (the quiet state).
3. **The owner approves the export, on S (closes the second round's F7).**
   - S's agent will not hand over anything until the owner approves on S's own admin page, with the
     recovery-key ceremony that already guards restores (`approval.go`). Export is a third scope of
     the one approval mechanism (WP0 folds the PHP side into one pair first).
   - The statement names T and T's key fingerprint.
   - Why: without it, whoever controls M's web tier could start a copy to a key they hold and receive
     every secret the site has. M's web tier sees only ciphertext today.
4. **Export and restore.**
   - **`copy_export`, one word on S, run at the first copy, at each refresh and at the final copy.**
     It returns one bundle, sealed to T's agent key (the key whose fingerprint the owner approved,
     Q8) and signed with S's agent identity key under its own domain prefix
     (`joinery-copy-export-v1`, never a request signature). It holds:
     - **the runs T may apply:** chain id and manifest hash of each, read from S's own upload ledger.
       The manifest fixes every artifact's hash, so T refuses any byte S did not upload. S's backup
       runs are unchanged (design rule).
     - **the data key of each chain named,** which S opens with its own site key. S's
       `backup_site_key` itself never travels: T keeps its own, and `restore_chain.sh` takes the data
       key by `--key-file`, as it always has.
     - **the host bundle (D5):**
       - the domain's Let's Encrypt lineage and the ACME account it renews under;
       - any DNS-01 credentials (`/etc/letsencrypt/<provider>.ini`);
       - any DKIM keys under `/etc/opendkim/keys/`.

     It is re-sent whole every time, so a certificate renewed on S in between always reaches T.
   - **Fetching.** `ManagedNode::backup_node_of()` answers with S's row for a row in state `copy`, so
     the chain link builder signs links under S's prefix, on S's target, for T, and only while the row
     is a copy. T downloads with `copy_stage` (G7).
   - **Restoring.** `copy_restore`, allowed only under `quiet copy`, runs `restore_chain.sh
     --key-file … --adopt-secret-key`, with `--skip-ssl` passed through to the reconcile. The restore
     lock (B27) and on-disk staging (B26) are WP0 fixes to every restore, not a copy mode. On the
     agent, `copy_restore` shares `restore_paths.go` (the workspace, the chain files, the project
     check) with the ordinary restore; it differs only in the vouch check (instead of the ledger), the state gate, and no
     approval.
     - **`--adopt-secret-key`.** T keeps its own `Globalvars_site.php` and splices in S's
       `secret_box_key`, read from the chain's copy of the file before T's is put back
       (`restore_chain.sh:376-392` already holds T's aside), and written with PHP `var_export`.
       - Every other key in that file belongs to the machine: the database settings,
         `deployment_environment`, and the paths.
       - S's other `config/` files arrive with the data part, including `relay_pull_key`, the relay
         identity.
     - **Database.** It loads with any missing role created. Then `reconcile_site.sh --skip-ssl` runs
       for the same domain. No installer runs until the state clears (step 10), so nothing on T knows
       a "dormant mode".
5. **Check: the census.**
   - One script, run on both machines by a `site_census` agent word (read-only, allowed on any site).
     It reports rows per table, file count and bytes per top-level directory, whether the canary
     opens, and how many registered sealed secrets are dead.
   - The script owns one exclusion list: each machine's own `Globalvars_site.php`, `backup_site_key`
     and ledger, the names the files engine never archives (`logs/` among them), and the rows a
     backup run writes on S after its dump (`bkh_backup_history`).
   - It runs on T after every apply. At copy time S is live, so the comparison with S is approximate
     and informational. The exact comparison is at the final copy (step 8), with S frozen.
   - Nothing else re-proves the load: `restore_chain.sh` checks every artifact's size and hash
     against the manifest first, the database loads under `ON_ERROR_STOP`, and tar fails on a partial
     extract.
   - The census also asks the bucket, as root from the agent, for a sample of the offloaded files.
   - **Also useful beyond copies:** the census verifies any disaster restore the same way.
6. **Look (optional, owner).**
   - M shows the owner a link that sets the copy's secret cookie. The owner reaches T by its IP with a
     hosts-file entry, and HTTPS works because the certificate travelled.
   - The site is dormant, so nothing it does can leave the machine: the firewall drops it.
   - Changes made while looking disappear at the next apply: every apply replays the chain from its
     full, and the restore's incremental extraction rewrites every file and deletes any the archive
     does not list.

The copy can stay dormant as long as the owner likes.
- **Refresh:** steps 4–5 again. T downloads the artifacts it does not hold yet and runs the same full
  apply at the newest run, from its local archives. A new chain on S is just a new plan; there is no
  progress file and no fall-back logic (M2).
- **Discard:** the copy row leaves the dashboard; its server is the owner's to delete at the
  provider (the platform never deletes a cloud instance; owner 2026-10-01).

### Switch over (steps 7–10; a separate action, on the owner's word)

7. **Freeze S (downtime starts).**
   - S's row on M goes to `switching`, so `is_operational()` holds fleet backups, staged rollouts
     (`AdvanceStagedRollouts` could otherwise update S after the final copy), pruning and uptime
     checks for it. T's row is already `copy`.
   - `site_quiet on` sets `quiet switchover` on S (the quiet state): the maintenance page, no cron,
     Postfix deferring, the firewall rule, and the wait for command-line PHP to finish.
   - S's agent stays on throughout; the way back needs it.
8. **Final copy.**
   - S runs one last chain run on the longest chain interval, so age never turns it into a full
     (a chain that ends for another reason stops the switch-over: B44's catch).
   - `copy_vouch` on S signs the chain's new manifest for T, and `copy_take_vouch` makes it T's
     vouch (B44: no secret travels and no approval is asked, because T holds the chain key from
     the last approved export and a frozen S's owner cannot reach its Backups page). T downloads
     that increment and runs the full apply at it, which also discards anything the owner's look
     changed.
   - The price of M2: the file part is unpacked again from local disk inside the downtime, about 1–3
     minutes for jeremytunnell's 3 GB, growing with the site. L0 measures it. Applying only the
     newest increment is G4, a separate piece for later if the minutes matter.
   - M runs the census on S and on T and requires an exact match: S is frozen, so nothing drifts.
     On a mismatch, M stops and offers the way back (unfreeze S); nothing has moved yet.
     - The census follows the final apply directly, with no look in between: a look runs the site's
       PHP on T, and only the next full apply removes the rows it writes. The switch-over offers no
       look after the final apply.
     - No table is left out beyond `bkh_backup_history`. A row in `err_general_errors` on S between
       its dump and the census means something failed during the freeze, and is reported as the
       difference it is.
     - The census itself is frozen time: a row count of every table and a stat of every file on S
       and on T (about a second on dev; more with jeremytunnell's 16.7 GiB of uploads). L0 measures
       it with the rest of the downtime.
9. **Move the address, with T still dormant.**
   - **IP swap (WP12; the fleet's usual switch):**
     1. Power S off.
     2. Swap both IPv4 addresses in one call (`POST /networking/ips/assign`).
     3. Confirm the swap and each address's reverse DNS.
     4. Reboot T, so Network Helper configures the address. The vhost needs no re-render: B25's
        name-based vhosts (WP0) answer on any address.
     5. Update M's records: `cvp_instance_ip` on both provision rows, and `mgn_host`.
     6. Prove T answers on the address: HTTPS to the site's name gets the dormant health answer from T,
        and T's agent checks in from the address.
     - Nothing has acted as the site yet, so any failure here is cheap. Shut T down, reverse the swap,
       boot S (still frozen, agent on), and unfreeze it. T stays dormant.
   - **Proxied origin change (WP7a; for a site behind a proxy such as Cloudflare).**
     - Preflight (step 1) confirms through M's DNS driver that every record naming S's address is
       proxied. Any record that is not (a mail host, an unproxied subdomain) sends the copy to the IP
       swap or to the full DNS switch instead.
     - M has no view of S's firewall: the tab tells the owner that a rule letting only the proxy's
       addresses in must be given to T before the move.
     1. M changes the proxied record's address from S to T. Visitors follow within seconds, because
        they only ever see the proxy; there is no TTL wait.
     2. Prove T answers through the proxy: HTTPS to the site's name, at T's look path, gets the
        303 only T gives (the path's secret is T's). The certificate travelled, so a strict-TLS
        proxy accepts T.
     3. `mgn_host` on M moves with the machine in the row swap (step 10).
     - Failure here is cheap too: M changes the record back and unfreezes S. T stays dormant.
     - Needs a DNS token for the zone on M, through the existing DNS credential flow.
   - **DNS (deferred):** the switch between any two providers, with S forwarding visitors to T
     until DNS settles, is its own spec, *Site Copy — Switch by DNS, and a Hetzner Server Driver*.
10. **Start T.**
    1. T's agent is told to take S's node id, keeping its own key (D4).
    2. **M swaps the two rows (no new record type).**
       - S's row keeps its id, slug and history, takes T's key and host, and leaves `switching`: it is
         the node, now on T.
       - T's copy row takes S's old key and host and becomes `retired` ("Retired source of S" in the
         fleet list). S's provision row is re-pointed to it, and T's to the node.
       - `is_operational()` keeps the retired row out of every automation. Removing it is the
         ordinary node removal; S's server is the owner's to delete at the provider. The way back
         reads S's key from it.
    3. `site_quiet off` on T. The agent allows it now, because T holds S's node id. The next
       converger tick writes the cron, runs the installers (the mailbox's arms Postfix and sets the
       `iemap_` role's password) and certbot's timer comes on. An installer that fails is retried
       every minute, as on any site.

    T is now the node. Its backups continue under the same node, and its first run is a new full
    backup, sealed to T's own `backup_site_key`, because its tree identity changed. Chains from
    before the switch stay sealed to S's site key and the recovery key: they open by the recovery
    ceremony, as after any key change.

S ends powered off, whole and quiet. Deleting it is the owner's separate action: the retired row
removed from the dashboard, and the server deleted at its provider.

### The way back

- **Before step 10:** as in step 9's failure path. S is unfrozen (`site_quiet off`), and the copy
  stays dormant.
- **After step 10:**
  1. Put the address back (by swap or by DNS) and power S on.
  2. M swaps the rows back: the node takes S's key from the retired row.
  3. S is unfrozen.
  - Anything written on T since it started is lost, and the dashboard says so before the button
    works. Going back while keeping those writes is a copy in the other direction.

### What travels, and how it is checked

| What | How it travels | Checked by |
|---|---|---|
| Database | chain `db` part | manifest hashes, then census rows (S vs T) |
| `uploads/`, `storage/`, `static_files/`, `config/` | chain `data` part | manifest hashes, then census files and bytes |
| `public_html/` (custom themes and plugins included) | chain `code` part, onto T installed at S's exact release | census |
| `vendor/` | never; T installs S's release, so its own matches | the site runs |
| `secret_box_key` | from `config/`, spliced into T's own config (`--adopt-secret-key`) | canary opens, 0 dead secrets |
| Chain data keys | `copy_export`, sealed to T's agent key | opening the chain |
| `backup_site_key` | never; T keeps its own | T's first backup |
| Each run's integrity | `copy_export`'s signed list of manifest hashes | `restore_chain` checks every artifact against the manifest |
| Database password, paths | never; T keeps its own | the reconcile's database open |
| Agent identity | never; T keeps its key and takes S's node id | one agent polling |
| TLS lineage, ACME account, DNS-01 credentials, DKIM keys | `copy_export`'s host bundle (D5), re-sent every time | HTTPS on T; renewal works; DKIM matches DNS |
| Files kept in cloud storage | stay in the bucket; the credential is a database setting | sample answers |
| Passkeys, vault wrappings | in the database; bound to the domain, which stays | owner sign-in |
| Sessions, vault unlock windows | not carried | users sign in and unlock again |
| Tailscale, redis data, hand-installed services, IPv6-bound facts | not carried | preflight lists them |
| S's Postfix queue at the freeze (bounces, mail the site handed to a local Postfix) | not carried: the freeze rejects Postfix's outgoing traffic, so it stays in S's queue. Accepted: the platform sends through its provider's API, not the local Postfix | the owner, from S's queue, before deleting S |

## Part 3 — Copy from backups (Phase 2: the source is dead)

Steps 2, 4, 5, 9 and 10, without `copy_export` and the freeze. Built as WP10 (see the status above).
- **Keys.** With no S to seal anything, the owner opens the newest chain's data key once, with the
  recovery key, on T's own page (`/copy-key`, reached through the look link). The browser works out
  the X25519 of the recovery key with the sealed box's ephemeral key, which opens that one box; T
  finishes opening it and writes the chain's `chain.key` and the vouch: what `copy_import` leaves in
  Phase 1, so everything after it is shared. Older chains open by the recovery ceremony, as any
  rotated key does.
- **Trust.** The ceremony's statement names the chain, its date and its manifest hash, and stands in
  for the ledger check. The owner is the authority, because no machine that made the archive is
  left to vouch for it.
- **Age.** The backup's age is the data lost. The ceremony shows it as an age, above the key box.
- **Address.** By IP swap when S's instance still exists at the same provider and account; through
  the proxy for a proxied site; otherwise the owner changes DNS (`manual`): there is no S left to
  forward, so visitors whose resolver still names S's old address fail until it settles, and the
  TTL matters. M's own record change with a resolver watch is the deferred DNS switch's.
- **One machine runs the site (I1).** When M created S's server, the switch-over powers it off
  first; otherwise the owner confirms it is off.

## Decisions

**D1 — Decided 2026-09-27.**
- A faithful copy onto a new server, with switching over and deleting kept separate. The flow never
  deletes the source.
- The old Clone is retired (WP9).

**D2 — Decided 2026-09-27: the backup chain.**

What arrives on T is the same either way: the whole site as of the backup's moment, and at
switch-over that moment is after the freeze. The only difference is time, and only for the database
during switch-over:
- **Direct:** the dump streams straight into T's load, so the two overlap.
- **Chain:** the dump must finish uploading before T can download it, because storage exposes no
  object until its upload is complete.

The file increment is small. T cannot verify it until the run's manifest is uploaded, which happens
after the dump, so it arrives with the database and adds little. The record the decision was made on
follows.

*Measured on jeremytunnell* (the four nightly runs 09-23 to 09-26, jobs 29776–38314): a full chain
run is **4.7 GB** (files 3 GB, database 1.7 GB compressed) and takes **9½–11½ minutes**, streamed
to Backblaze B2. Its database is 2.8 GB on disk.

**What the choice changes:**

| | Backup chain | Direct pull (S → T over HTTPS) |
|---|---|---|
| **Downtime at switch-over** | dump, then upload, then download, then load, one after another. Estimate for jeremytunnell: dump plus upload about 3–5 min (part of the measured run), download under 1 min, load about 5–10 min (unmeasured). **About 10–15 min.** | dump and load run at the same time, so it takes as long as the slower of the two. **About 5–10 min.** This saves roughly the dump-and-upload time. |
| **Time to make the copy** | download the newest chain (4.7 GB) plus one fresh increment. | a full pull of everything from S. |
| **Code to write** | one restore flag, `copy_export`, census. Phase 2 (dead source) needs all of it anyway. | a new transport (resumable, counted, all parts), a delta for the final copy (files changed since), and the handover anyway. Phase 2 still needs the chain work, so there are **two engines to keep correct**. |
| **How often the path runs** | every night, on every node, so a fault shows up in the daily backup. | only when someone copies a site. Clone is the example of what a rarely-run path becomes (20 bugs). |
| **What it proves** | every copy is also a real restore of the site's backups. | nothing about the backups. |
| **Load on S** | one extra incremental run, then the final run. | a full read and send of the site on demand, on a 2 GB machine. |
| **Surface on S** | none new: S only uploads, as it does nightly. | an export endpoint (or agent listener) that hands out the whole site to whoever holds a token. That is what Clone's `clone_export` was, and what WP9 deletes. |
| **Depends on** | the backup storage being up. | only the two machines. |
| **Cost** | about 5 GB out of Backblaze per copy, inside its free egress allowance. | none. |

**A middle path**, for later if the minutes matter: the chain for everything, plus a direct
database stream for the final copy only. It cuts the downtime to the direct figure, at the price of
a narrow direct channel between the two machines.

**Why the chain:**
- The extra downtime is a few minutes at jeremytunnell's size.
- It is one engine instead of two.
- Every copy tests the backups.

L0 measures the real downtime. If it is too long, the middle path above is the fix, and it can be
added without changing anything else.

**D3 — Decided 2026-09-27.**
- **Design target:** Linode to Hetzner. Every step is written so that it works between any two
  servers.
- **Build order (revised 2026-09-28):** the proxied origin change (WP7a) first, because it is the
  smallest switch and jeremytunnell's; then the IP swap (WP12); then the DNS switch with forwarding
  (WP7), which completes the any-provider ability. **WP7 and WP13 deferred (owner, 2026-10-03)** to
  their own spec; this spec completes with WP12 and WP10.
- **Why each switch (owner, 2026-09-28):**
  - **IP swap:** the medium-term switch for the whole fleet. Every node is on Linode, and almost
    none sits behind a proxy. Within one account and region it keeps every IP-bound fact, with
    nothing to chase and no DNS wait (IPv6 aside).
  - **Proxied origin change:** a convenience for the few sites behind Cloudflare, jeremytunnell
    among them. Built first only because it is small and unblocks jeremytunnell.
  - **DNS switch:** the exit capability. Leaving Linode for Hetzner (or anyone) must stay a real
    option, so there is leverage with Linode and no lock-in. Every piece except WP12 is
    provider-neutral for this reason.
- **IP swap.**
  - Pros:
    - Every IP-bound fact is kept, with no list to chase: reverse DNS, SPF, and the relay's and
      Namecheap's allow-lists.
    - No wait for DNS caches.
  - Cons:
    - Same Linode account and region only.
    - IPv6 addresses do not move.
- **DNS, with the frozen source forwarding.**
  - Pros:
    - Works between any two servers.
    - No visitor sees an error while caches expire, because the old address forwards to the new.
  - Cons:
    - S stays up, forwarding, until DNS settles.
    - The IP-bound facts are a list to work through. M does its own; the owner does the rest.

**D4 — Decided 2026-09-27: T keeps its own key and becomes S's node.**

*Mechanism, corrected in the second round.* Putting T's key on S's record alone fails: T signs every
request with its own node id (`identity.go:142-151`), so its polls would be refused. And
`approve_join` refuses a node that already holds a key. So:
- a new agent word tells T to take S's node id, keeping its key;
- M swaps the two rows (step 10): the node takes T's key, and the copy row becomes the retired row
  holding S's key for the way back.

Neither is a re-pair: no person approves at switch-over, because the owner approved T's key when the
copy began. The record of the choice:
- **T's own key.**
  - Pros:
    - No agent private key ever leaves a machine.
  - Con: new code on both sides: the node-id word, and the key swap on M.
- **Carry S's key file.**
  - Pro: no re-point.
  - Cons:
    - A root 0600 private key in transit.
    - If S ever boots with its agent on, two agents answer as one node, which breaks I1.

**Why no restore approval is asked on a dormant target.**
- The restore approval guards a machine that holds someone's data against a management node
  choosing its bytes.
- A dormant T holds nothing yet, and `copy_export`'s signed list of manifest hashes vouches for its
  bytes.
- The owner's approval sits where the secrets leave, on S (step 3).
- `copy_restore` runs only under `quiet copy`, which only the installer sets. The agent can set only
  `quiet switchover`, so M cannot quiet a live site and restore over it.

**D5 — Decided 2026-09-27: the certificate and the DKIM keys travel** in the sealed handover.
- **Pros:**
  - HTTPS works from the first second, which matters for an HSTS site and for looking at the copy.
  - DKIM keeps matching the DNS record that is already published.
- **Con:** two more secrets in the sealed bundle.
- **Phase 2 cannot carry them:** a new certificate is issued once DNS points at T, and DKIM falls
  back to the database-held key for protected domains.

**Order of building.** Phase 2 was planned after Phase 1's live proofs; the owner moved it ahead
(2026-10-03). It reuses WP2, WP3, WP5, WP7a and WP12 unchanged; only the ceremony (WP10) is its
own, and it leaves the same input as `copy_import` (`chain.key` and the vouch).

## Work packages

In build order. Each is built and tested on its own (design rule).

**New agent words (eleven):** `copy_export` (S), `copy_import` (T, Q6), `copy_stage` (T, G7),
`copy_restore` (T), `site_census` (any, read-only), `site_quiet on|off` (S; T's `off` at step 10), the node-id word (T),
the final copy's `copy_vouch` (S) and `copy_take_vouch` (T) (B44), and Phase 2's `copy_look` and
`copy_take_key` (T, WP10).

- **WP1 — The documents tell the truth (B22).** Correct the five documents and the
  `reconcile_site.sh` message to what is true today, and again when the copy lands. Small; first.
- **WP0 — Standalone fixes that help today.**
  - B26: every database restore stages on disk.
  - B27: one restore lock, taken by every restore and by the converger.
  - B24: the Hetzner DNS driver on the Cloud API.
  - B25: name-based vhosts.
  - Fold the two PHP approval pairs (`RestoreApproval` + panel, `DecommissionApproval` + panel) into
    one pair parameterised by scope, as the agent's `approvalScope` already is (`approval.go:117-166`).
    Export (WP4) is then a scope entry on both sides, not a third copy.
- **WP5 — The quiet state.** The foundation. Nothing in the site's own code changes.
  - `/etc/joinery/sites/{site}/state` (`quiet copy` | `quiet switchover`), and one gate at the
    converger's entry that asserts the measures and runs no installer while it exists.
  - The measures: the nftables table dropping the web user's outgoing traffic (applied at boot), the
    Apache drop-in (secret-cookie look or 503 for a copy; the maintenance page for a switch-over),
    the cron file removed, certbot's timer off, Postfix deferred if armed.
  - `site_quiet on|off`: sets `quiet switchover` with the wait for command-line PHP; clears either
    reason, refusing `quiet copy` until T holds S's node id.
  - The agent accepts only the copy and switch-over words and read-only words while quiet.
  - Confirm on a bare-metal install that PHP-FPM, the site cron and detached workers all run as the
    web user, that nothing else runs site code as another user, and that the backup run (the agent,
    as root) reaches storage while quiet.
  - The dormant install (`--dormant --copy-of`, at S's exact release).
  - On M: install states `copy`, `switching` and `retired`, badged in the fleet list;
    `ManagedNode::is_operational()` replacing the literal `installing` checks, and consulted by every
    automation (fleet backups, staged rollouts, pruning, uptime checks).
- **WP2 — Restore onto a copy (B21).**
  - `restore_chain.sh --adopt-secret-key`, with `--skip-ssl` passed through to the reconcile. No other
    mode: staging and the lock are WP0's, for every restore.
  - The `copy_restore` agent word, allowed only under `quiet copy`, sharing `restore_paths.go` with
    the ordinary restore.
- **WP3 — Census.** No change to the backup format or to S's backup runs.
  - The census script with its one exclusion list, and the read-only `site_census` agent word.
  - M's compare: informational at copy time, exact at the final copy.
- **WP4 — Approval and export.**
  - The export approval on S: a scope of the folded approval (WP0), naming T's fingerprint, asked at
    every export (Q9).
  - `copy_export`: the vouched runs from S's ledger, each chain's data key and the host bundle sealed
    to T's agent key (Q8), signed by S; re-sent whole every time.
  - `ManagedNode::backup_node_of()`: S's row for a row in state `copy` (`mgn_copy_of_node_id`), so
    the chain link builder serves the copy.
  - The agent's key lent to the copy words as `SignDomain` (a domain prefix) and `OpenSealed`; the
    seal to T's agent key (`copy_seal.go`). S opens its chain envelope, a libsodium sealed box, with
    `golang.org/x/crypto/nacl/box`.
  - **`copy_import` on T (Q6)**, trusting only the S key the dormant install recorded (Q7):
    `install.sh --copy-of` takes S's agent public key with its node id. Pinned from the WP3 review:
    - it runs only under `quiet copy`, as `copy_restore` does: a live site never takes a bundle;
    - refusing a replayed bundle needs a high-water mark (the bundle's sequence or time) in root's
      state directory, raised only after a bundle checks out;
    - `_site_state.sh` clearing removes the recorded S key with `copy_of` and `vouched`, so a
      promoted T stops trusting S's key.
  - **G7 (found 2026-09-28 at the start of WP4; accepted 2026-10-01): T needs its own staging word,
    `copy_stage`.** The ordinary `stage_chain` accepts only artifacts in T's own upload
    ledger and opens the chain with T's own site key, so it refuses S's chain on both counts.
    `copy_stage` (operate, only under `quiet copy`) downloads the chain M links under S's prefix,
    accepts the manifest only when its hash is in `vouched`, and checks every artifact against the
    manifest's sizes and hashes. It writes no key: `copy_import` already wrote `chain.key`.
    `stage_chain` never learns that copies exist. One refresh is then: `copy_export` (S),
    `copy_import`, `copy_stage`, `copy_restore` (T), `site_census` (both). That makes seven words.
  - **T's side of the export (carried from WP2).** `copy_restore` reads what `copy_import` leaves:
    - each chain in the usual workspace, `restore_<chain id>` under the backup base, with
      `manifest.json` and the chain data key opened from the bundle as `chain.key`;
    - the vouch record `/etc/joinery/sites/{site}/vouched`, written whole each time with one
      `<manifest sha256> <chain id>` line per vouched run, root-owned 0600, and written only after
      the bundle's signature and seal check out.
- **WP7a — Switch over, and the proxied origin change (the first switch built; L0). Built
  2026-10-02; see the status above.**
  - The switch-over on M (steps 7–10 and the way back, moved here from WP8): freeze S, the final
    never-roll run and exact census, the move, `take_node_id` and `site_quiet off` on T, and the
    way back (quiet T while it is still the node, then `SiteCopySwap::go_back`).
  - The preflight: every record naming S's address is proxied; list any proxy-only firewall on S.
  - The record change through M's DNS driver (Cloudflare first), the proof through the proxy, the
    change back on failure, and `mgn_host`.
  - No forwarding, no TTL lowering, no resolver watch: those are the deferred DNS switch's.
  - Its live test is L0, on a proxied record under `jeremytunnell.info` (Q3).
- **WP8 — Copy on the management node (built 2026-10-01; see the status above).**
  - Carried from WP5's review (all four done):
    - M sets the copy row's install state to `copy` when it creates the row or approves its join;
      `JobResultProcessor`'s install-success path (which writes NULL) must not clear it.
    - T installs S's exact release (vendor/ is never in the chain): fetched as
      `latest_release?version=X.Y.Z`.
    - The copy preflight refuses an S older than 0.8.453: the tree T runs is S's.
    - T installs under S's site name and S's domain: `restore_chain.sh` refuses a target whose
      directory name differs from the one the archive carries, the project name is also the
      database name, and `copy_restore` passes no `--domain`, so T keeps its config's own.
  - **Copy to a new server:** preflight, then "create it for me" or "I'll bring a server", then
    steps 2–6, progress, Refresh and Discard.
  - The node-id word, and the row swap (both directions).
  - **Switch over** (steps 7–10 and the way back) moved to WP7a (owner, 2026-10-01).
- **WP9 — Retire Clone (built 2026-10-02; see the status above).**
  - Delete `clone_export.php`, `clone_export_arm.php`, the `from_backup` install mode,
    `cvp_clone_key_sealed`, the `clone_export_key` setting, `scrub_sealed_secrets.php` and their
    tests.
  - Retire the agent's `clone_export_arm` under the vocabulary rules.
  - Update the documents.
- **WP11 — Live proofs.** See the test plan.
- **WP12 — IP swap (L0b). Built 2026-10-03; see the status above.** The switch most of the fleet
  will use: every node is on Linode, and almost none sits behind a proxy.
  - `assignIpv4`, with the address and interface-model reads, on the Linode driver (`CloudAddressSwap`).
  - The preflight: Network Helper, the scope, the firewall, the IPv6 list.
  - The sequence and its failure path from step 9.
  - The record updates.
  - The Linode token request widened to `ips:read_write` (and `firewall:read_only` for the
    customer grant), with a re-grant for existing tokens; the relay's grant is unchanged.
- **WP7 (the switch by DNS) and WP13 (a Hetzner compute driver) are deferred** to their own
  spec, *Site Copy — Switch by DNS, and a Hetzner Server Driver* (owner, 2026-10-03), with L1.
- **WP10 — Phase 2. Built 2026-10-03; see the status above.** The recovery-key ceremony on T's own
  page, T opening the chain's data key from the browser's one-box value, and the owner's statement
  standing in for `copy_export`'s signed list.
- **WP14 — Remove a container source after a kept switch-over (owner, 2026-10-04).** Found moving
  Joinerydemo: once the address points at the copy, the old container's own Backups page, where an
  ordinary removal is approved, can only be reached with a hosts-file line. The proof that the
  domain has left stands in for that approval, and only for the old machine of a switch-over.
  - **Who proves it: the Docker host, never M.** The approval exists so that M alone cannot destroy
    a site. M saying the domain moved would give that back; the host checking it does not. (The SSL
    probe runs the other way, on M, because there the party it distrusts is the node.)
  - **The proof, `decommission_moved_site` (destructive, host posture, agent 1.55.0).** One
    parameter, the site's name, as `decommission_site`. The names come from the host's own vhost:
    the `ServerName` and any `ServerAlias` of the blocks that proxy to the container.
    1. The host writes a one-time token to the container's `public_html/sm-ssl-probe.txt` with
       `docker cp`, which runs nothing inside the container (older containers keep their code in
       the container's own layer, not a volume).
    2. **Control:** the container answers the token on its own port. If it does not, the test
       cannot tell anything, and the job refuses.
    3. **Each name over https, certificate checked, no redirect followed, a fresh query string so
       no cache answers.** The token coming back refuses: the domain still reaches this site. No
       answer, a certificate error or a 5xx refuses: unreachable is not moved. Any other answer
       means another server holds the name. An alias that does not resolve at all reaches nothing
       and passes; the `ServerName` must answer.
    4. The token is emptied (the view serves an empty file as a 404). Then `remove_account.sh`
       runs as for an ordinary removal and carries its own verdict.
  - **On M:** the overview's Permanently Delete Site on a row in state `retired` builds
    `decommission_moved_site` instead of `decommission_site`; every other row keeps the approval.
    The job type stays `decommission_node`, so the result is processed as before.
  - **Showing where the domain goes (owner, 2026-10-04).** Beside the old machine's site, the
    overview shows the host's last answer: reaches another server, still reaches this container,
    could not tell, or the container is gone. A DNS lookup cannot give it: a proxied domain
    resolves to Cloudflare for old and new alike. So the answer is the same proof, run as
    `moved_site_check` (operate, host posture, agent 1.56.0), which reports instead of refusing
    and removes nothing. The row keeps it (`mgn_moved_check_*`); opening the page asks again when
    it is older than ten minutes, and Check again asks at once. A check refuses while a removal is
    open on the host, since both write the same probe file. A verified removal records the
    container gone.
  - **The old container is stopped by the switch-over (owner, 2026-10-06).** Found moving
    getjoinery: the switch-over asked the owner to tick "The old server is off" for a container
    nobody had stopped, and that only root on its host could stop. It kept running: writes it took
    after the backup were lost, and its scheduled tasks, its own backups among them, ran until it was
    removed. A plain `docker stop` would not last, because `container_health` restarts a site
    container that is not running.
    - `hold_container` (operate, host posture, agent 1.60.0), `stop` or `start` and the site's
      name. Stop writes `/etc/joinery/sites/<site>/held` with the restart policy the container had,
      turns its restart off and stops it. While the mark exists, `container_health` leaves it alone,
      `restart_container` refuses it, and Docker does not start it at boot. Start puts the policy
      back and lifts the mark. `remove_account.sh` removes the mark with the container.
    - The switch-over records the container and its host's node when it begins (before the rows
      swap) and runs `hold_container stop` on the host as the start's **last** step. After the copy
      takes the node there is no way back for a copy from backups, so a stopped container never has
      to be started again by the switch-over, and a host that cannot stop it never holds up the
      site, which already runs on the copy. `switch_refusals()` asks the host's agent for the word.
    - The checkbox is gone: every press that cannot be taken back states what it does in the
      system modal, and an old server this management node cannot reach is still the owner's to
      turn off, said as "I have turned the old server off".
  - **The key step opens the key page (owner, 2026-10-06).** The look link lands on the copy's home
    page, which before the restore is an empty install whose login no password opens. The quiet
    state answers a second look path, `/.joinery-look/<secret>/key`: the same cookie, then
    `/copy-key`. The tab shows that one link to a copy whose release has it
    (`COPY_KEY_LOOK_MIN_VERSION`), and the two links otherwise.
  - **The cleanup stays on the site's Copy tab until it is done (owner, 2026-10-06).** A kept
    switch-over's old row leaves the dashboard, and the Copy tab linked to it for a week, saying
    "if you have not yet, remove the old container" even once it was removed. The certificate a
    removal left (Joinerydemo's) could be reached only through that link. The tab now names the
    old container while it is still on its host, then each certificate its host still holds for
    the domain, with Remove it from the host, for as long as either lasts.
  - **The old row is named for what it holds (owner, 2026-10-06).** The swap moved only machine
    columns, so the retired row kept "Getjoinery (copy)" while holding the old container. The swap
    now renames it: (old container) or (old server) once retired, (copy) again on the way back.

- **WP15 — Root SSH keys travel with a copy, when ticked (owner, 2026-10-07).** Its own spec: `root_ssh_login_and_keys`.

## Test plan

**In the gate (db tier unless marked).** Each group tests one piece alone.
- **Restore onto a copy (WP2):**
  - Setup: a chain of a throwaway project with dev's schema and settings (sealed under its own
    fixture key), restored with `--adopt-secret-key` into a scratch project whose config has a
    different `secret_box_key` and database password.
  - Pass:
    - The canary opens, and every registered sealed secret opens.
    - The scratch project keeps its own database password.
    - The census of the source equals the census of the scratch project, exactly.
  - A second full apply, after an edit and a new file on the scratch project, removes both.
- **`copy_export` (WP4):** seal, sign, verify and open.
  - Each of these refuses: a tampered byte, a wrong target key, a wrong source key, an expired
    bundle, a replayed bundle, a manifest not in the signed list, and a run missing from S's ledger.
- **Census (WP3):** refuses a missing row, a missing file, a file changed in size, and a dead sealed
  secret. The excluded items differ between the two sides without refusing.
- **The quiet state (WP5):**
  - One converge tick on a quiet fixture runs no installer, and leaves no cron file.
  - `site_quiet off` on `quiet copy` refuses until the node id is taken; `copy_restore` refuses under
    `quiet switchover` and on a live site.
  - A request without the copy's cookie gets 503; under `switchover`, the maintenance page.
  - **Live tier (needs root, on a test server):** while quiet, the web user cannot open a connection
    off the machine, root can, and the rule is back after a reboot and after it is deleted by hand
    (within a minute). Clearing restores the site exactly.
- **`is_operational()` (WP5, on M):** each not-working state is skipped by fleet backups, staged
  rollouts, pruning and uptime checks.
- **Agent (Go):** the vocabulary entries for the new words, and the refusals by state.

**Live, on test servers the owner provides.**
- **L0 — Linode to Linode: copy, look, switch over by proxied origin change (WP7a; the first).**
  - Repeatable and self-contained (Q5): each run creates its own small S through M's normal keyless
    creation path, installs a seeded test site on it under a proxied record in
    `jeremytunnell.info`, copies it to a T that M creates, switches, checks, and leaves both servers
    for the owner to delete at the provider. It never depends on a box's history.
  - The source is a small site seeded for coverage:
    - sealed secrets set (a backup target, an OAuth secret, an IMAP account);
    - a passkey registered through a Playwright virtual authenticator;
    - a vault;
    - raw mail in `storage/`;
    - an offloaded file;
    - a custom theme;
    - a DKIM key.
  - Needs `jeremytunnell.info` on Cloudflare with a zone token typed at each press (Q3).
  - Pass:
    - The census is equal and there are 0 dead secrets.
    - The same browser signs in with the passkey and unlocks the vault.
    - The offloaded file downloads, and mail pickup resumes.
    - T's backup runs under the same node, and one agent polls.
    - Visitors follow within seconds of the move.
    - S is frozen and whole.
    - The certificate is unchanged.
    - The downtime is measured, against the D2 estimate.
- **L0b — the same, switched by IP swap (once WP12 exists).** One account and region; needs the
  operator Linode token re-granted with `ips:read_write`. Pass: the address and reverse DNS stay
  the same, and S is powered off and whole.
- **L2 — The way back and the dormant copy.**
  - Back out after step 8: S serves exactly as before.
  - Refresh a dormant copy, then discard it.
  - Go back after step 9: S serves, and the dashboard names what T wrote since it started.
- **L3 — Phase 2.** Power S off for good and copy from its backups onto a new T with the recovery-key
  ceremony on T's `/copy-key` page. Pass: the census holds, 0 dead secrets, the owner signs in with
  the passkey, T's certificate is issued after the switch, and the recovery key never appears in
  anything M stores (job parameters and results).
- **L4 — jeremytunnell onto Ubuntu 26.04 by Cloudflare origin change (Q4).** Only after L0 and L2
  pass, and only on the owner's word. T can be in any
  region or account, since no address moves.

## Out of scope

- Copying the management node itself, or a running management node: it carries the agent signing key and
  the fleet's control. Another management node is copied from its backups only.
- Changing the domain: a rename, with passkeys lost by design (I4).
- Carrying hand-installed host services: they are listed, not copied.
- A container site as the target, and any machine hosting more than one site (v1).

## Open questions for the owner

- **Q1 — Answered 2026-09-27:** copies must work across Linode accounts and regions, and to Hetzner.
- **Q2 — Answered 2026-09-27:** there is no Hetzner account yet; the owner will get one later. L0
  (Linode to Linode) comes first.
- **Q3 — Answered 2026-09-28: `jeremytunnell.info`**, unused. It holds a proxied record for the WP7a
  test and a direct one for the deferred DNS switch's L1.
  - Its DNS is at Linode today, with one leftover A record (198.74.57.162, not a managed node). The
    WP7a test needs a proxy, so the owner adds it to Cloudflare's free plan and gives M a DNS token
    for that zone alone.
- **Q4 — Answered 2026-09-28: jeremytunnell switches by Cloudflare origin change (WP7a).**
  - Why: visitors reach it through Cloudflare's proxy; its MX is relay1 and SPF is `-all`, so no
    outside fact names the server's own address. The origin change needs no token re-grant for
    customer cloud account 7 (expired 2026-09-08), no same-region T, and no reboot.
  - The IP swap is still built, right after, and proven by L0b (D3's revised order).
- **Q5 — Answered 2026-09-28: L0 creates a fresh source each run** and deletes both servers after.
  `test1` (97.107.131.245) stays with the database-incrementals memory work.
- **Q6 — Answered 2026-09-28: a sixth word, `copy_import`, on T.** After each `copy_export` it checks
  S's signature, opens the seal, writes what `copy_restore` reads (each chain's `chain.key` and the
  `vouched` record), and installs the host bundle (certificate lineage, ACME account, DNS-01
  credentials, DKIM keys). Each piece stays on its own and is tested alone; a refresh can re-import
  without a restore.
- **Q7 — Answered 2026-09-28: the dormant install records S's signing key.** `--copy-of` takes S's
  node id and S's agent public key (M prints both in the command the owner runs), stored beside
  `copy_of` in root's state directory. `copy_import` trusts that key and no other. The owner already
  trusts M's printed command to install T, and M already dispatches every operate word to T, so
  this adds no new trust. (The census does not guard against a compromised M: M runs both census
  jobs and the compare.)
  - Hardening: S's own admin page already shows S's agent fingerprint, served by S and not by M.
    The printed command shows the fingerprint it carries and tells the owner to compare the two.
- **Q8 — Answered 2026-10-01: (a), T's agent key.** Which key the export is sealed to: The seal must go to a key S can
  prove is T's, or a compromised M substitutes its own key and reads every secret in the bundle.
  - **(a) T's agent key.** The key the owner approved T's join by, and the fingerprint the export
    approval names, so S seals to exactly the key the owner saw. No extra step. Catch: one key both
    signs and decrypts (Ed25519 converted to X25519; studied and accepted, as `age` does with ssh
    keys). The spec's "sealed to T's `backup_site_key`" changes.
  - **(b) T's `backup_site_key`, as written.** Keeps the signing key only signing. Catch: S cannot
    tell T's backup key from one M made up, so T must publish it signed by its agent key: one more
    word and one more job before every export.
  - Recommendation: (a).
- **Q9 — Answered 2026-10-01: (a), every export.** How often the owner approves on S: S exports at the first copy, at
  each refresh and at the final copy, inside the downtime.
  - **(a) Every export.** The restore approval's mechanism as it is. Catch: each refresh needs the
    recovery key, and the final copy waits on the owner inside the downtime (about a minute when
    ready; the job holds up to an hour).
  - **(b) Once per T.** The first approval records T's fingerprint in S's state directory; later
    exports to that T run unasked. Only T can open them either way. Catch: a record to keep and to
    withdraw, and a compromised M can push fresher data to T unasked (still readable only by T).
  - Recommendation: (a) for v1; L0 measures the cost, and (b) can be added later without changing
    the bundle.
  - **Revised by B44 (2026-10-02):** the final copy is not an export. It carries no secret, so it
    asks no approval; every export still does.
- **Q10 — Answered 2026-10-03: the recovery key is typed on T's own page.** Where the owner types
  the recovery key for a copy from backups (WP10). Typing it on M's Copy tab would put it in a page
  M's web tier serves, which is giving M every backup the key opens. On T's own `/copy-key` page,
  reached through the look link, the key stays where today's restore and export approvals keep it:
  on the site's own pages. Catch, accepted: a hosts-file line, and T's site code knows one page
  about being a copy.
