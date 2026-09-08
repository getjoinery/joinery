# Testing day: agent, wizard, install (2026-09-07)

**Status:** IMPLEMENTED 2026-09-08. Written 2026-09-07 evening from the
live-verification queue, the programme table and the fleet as it stood. Tier A
ran on the dev box with nothing created; Tier B ran on throwaway Linode
instances (all four deleted, rows removed 2026-09-08). Tier C (owner UX runs)
was withdrawn: the owner runs manual testing from their own spec. Every
defect the day found is fixed (§ 6a); the records per gate are the history.

Sources folded in: `project_live_verification_queue` (memory),
`agent_management_first_principles.md` (item 4 and item 7 gates),
`keyless_provisioning.md` "Live gate, owed", `docker_host_agent.md`,
`relay_without_a_shell.md` WP5, `implemented/linode_stackscript.md` gate A1,
`implemented/setup_wizard.md` blank-slate paths, `installer_defects*.md`.

---

## 0. Where things stand (verified 2026-09-07 ~20:00 UTC)

| Thing | State |
|---|---|
| Fleet | 9 sites + dev on **0.8.376 / agent 1.21.0** (the local queue is gone); every node polled at 19:45 |
| Publishes today | 0.8.374, 0.8.375, 0.8.376 all built as jobs of dev's own agent (node 24776); getjoinery published as a node action from node 33 (jobs 12536, 12564) |
| 0.8.374 rollback | eight docker-prod nodes re-ran 0.8.373 → 0.8.375 → 0.8.376, all `completed` |
| Agent bundle | source 1.21.0 = bundle 1.21.0, `agent_bundle_drift` green |
| Go suites | `go test ./...` green (agent + primitives) |
| Provisions | only jeremytunnell-vps; keyless1–9 fixtures are gone |
| Linode grant (cca 7) | **expired 2026-09-02** |
| Operator accounts | `server_manager_operator_cloud_token` and the SMTP2GO key EMPTY — Managed cannot be bought or fulfilled; not on this day's list |
| Docker host 23.239.11.53 | **no host agent** (`mgh_mgn_host_node_id` NULL) |
| Relay rows on dev | none; node 1800 (relay1, 45.79.215.171) still a managed node |
| jeremytunnell.com MX | → relay1.getjoinery.com, the SSH-era 2.9 relay |
| Docker on dev | not installed — the container gate cannot run here |
| Uncommitted | VERSION + two plugin.json publish bumps, the copy spec, three new spec files |

---

## 1. Prerequisites (owner)

- **P1 — Commit the tree first.** A mid-day publish over uncommitted publish
  leftovers makes the release row and the tree disagree.
- **P2 — Reconnect Linode** at `/profile/server_manager/connect_cloud`
  immediately before Tier B starts. The grant lives two hours with no
  refresh; every provisioning session begins with this.
- **P3 — The auto-mode classifier.** It refuses instance creation, job
  dispatch and DB writes from my shell. Either switch it off for the day or
  I hand you one scratch script per step to run (the keyless5–9 scripts from
  2026-09-02 still exist and are the template). Decision D1.
- **P4 — Hazard check before anything else (A9).** jeremytunnell.com is on
  0.8.376, whose relay code speaks only the pinned API. Its MX still points
  at relay1, which has no API. If mail is being held on relay1 unpulled since
  ~15:10 today, the rotation (B5) moves up the list.
- **P5 — Keys for the wizard runs:** a B2 application key + bucket (the dev
  target-3 credential will do for throwaway sites), and an SMTP2GO API key
  (an account is needed; the quickstart makes it step 2).

---

## 2. Tier A — I run these on dev now; nothing is created

| # | Gate | Pass looks like |
|---|---|---|
| A1 | `php tests/run.php safe` | green, or every red named and fixed |
| A2 | `php tests/run.php db` (full, ~6½ min) | green; this is the pre-publish gate and today has publishes in it |
| A3 | `go test ./...` in the agent repo | **DONE, green** |
| A4 | `php tests/run.php deploy` on dev | green (the tier a node runs after a swap) |
| A5 | Migration 179 guard | each of the eight apply_update outputs (jobs 12528–12535) carries 179's skip line; jeremytunnell/dev carry its run line |
| A6 | Publish log for 0.8.376 | `logs/publish/publish-0.8.376-*.log` shows the deploy tier ran, the agent stage said `carried` or `built`, nothing root-owned left in the tree |
| A7 | Fleet status read | `mgn_last_status_data` on all ten nodes shows agent 1.21.0, no `fetch_failed`/`verify_failed`, no "last backup failed"; backups of the last two nights all `completed` (18 rows in 48 h say yes; check outcomes) |
| A8 | Certificate renewal after the queue died | expiry date of every fleet site's cert (`openssl s_client`) and WHERE renewal lives for the eight containers on 23.239.11.53 — the host has no host agent, so it must be the host's own certbot cron, not a plane job |
| A9 | Relay hazard (P4) | relay1 answers on 25 and holds no growing spool; jeremytunnell's Setup tab reads green — owner reads the tab, I read the wire |
| A10 | Wizard, account-scope steps, throwaway account on dev via Playwright | fresh account → `/setup` redirect on first login; passkey step with the virtual authenticator; **the PRF-failure route** (queue item 5: derivation fails → hint names the device → "Show my options" → phrase branch → vault holds `TYPE_PASSPHRASE` only; a second account with one capable credential lands on the normal step); recovery codes and 2FA backup codes shown once; dismissal releases; next login no redirect |
| A11 | Wizard site-scope render with the installer's DNS credential | seal a dummy token with `utils/install_dns_credential.php`; the email step preselects Linode and says the blank field is fine; a publish attempt consumes the row (deleted before the call) and fails cleanly on the bad token |
| A12 | Installer static checks | `bash -n` on install.sh 2.63, `_site_init.sh`, `linode_stackscript.sh` 1.3; `installer_contract` green (in A1) |

Expected reds worth knowing before they show: none known. `sync_sim` fails
only while a drive-sync session holds the simulator.

### 2a. Tier A results (run 2026-09-07 22:10–23:20 UTC, dev on 0.8.377)

| # | Result | Notes |
|---|---|---|
| A1+A2 | 382/383 suites, 12699 checks; ONE red, unreproduced | `fleet_auto_enrollment`: FleetProvisionSeeding's service URL failed the https guard once. Passed alone, in the 170-suite plugin rerun, 96 loop runs beside two test-db lane reruns, and across a forced test-db refresh. JobCommandBuilder 1.57 now prints the rejected address (uncommitted). Watch. |
| A3 | green | (before the day) |
| A4 | green | the deploy tier ran inside both publishes (0.8.376, 0.8.377): 3/3 suites, 30 checks |
| A5 | pass | all eight apply outputs (12528–12535) carry 179's own guard line ("no recipes table here" / "no declared key … joinery_ai inactive") and "Successfully applied"; jeremytunnell's 0.8.377 apply (12566) says "already applied" |
| A6 | pass | both publish logs: deploy tier ran first, agent stage "already bundled - unchanged", support bundle built, tree manifests signed; nothing root-owned in the tree |
| A7 | pass with a finding | all ten agented nodes 1.21.0, polled 22:30, no fetch/verify failures; 18 backup runs in 48 h all `completed`. Finding B6: `joinery_version` and `ssl_*` in status data are stale folds (see memory project_wizard_defects_2026_09_07) |
| A8 | pass | origin certs behind Cloudflare (23.239.11.53, SNI): demo Oct 20, galactictribune/mapsofwisdom/getjoinery/phillyzouk/developers/scrolldaddy Nov 17, orgs Nov 30; dns.scrolldaddy Sep 30; relay1 Dec 6. Renewal lives in each container's own certbot — demo's renewal (~Sep 20) is the first proof it works without the local queue |
| A9 | done earlier | relay rotated by hand (§ B5 header); jeremytunnell ping + pull green |
| A10 | pass, 4 defects | fresh member: no interrupt on public pages (by design — the interrupt lives in check_permission), /profile → /setup; passkey via virtual authenticator; PRF-failure route restated as a hardware limit → Show my options → phrase route → step-up → vault = 1 passphrase + 10 recovery wrappings, no passkey; codes shown once; dismissal releases, next login lands on the dashboard. A fresh account on a PRF-capable authenticator takes the normal branch (vault = passkey + 10 recovery). Fresh permission-10 admin: interrupted straight from login, all 9 steps render. Defects B2–B5 in the memory. |
| A11 | NOT RUNNABLE on dev | the Email step is already green on dev, so the site-scope form never renders; the sealed dummy credential was consumed again by hand. Belongs to B1's fresh site. |
| A12 | pass | `bash -n` clean on install.sh 2.63, _site_init.sh, linode_stackscript.sh; installer_contract green in the gate |

Throwaway rows (three members, one admin, their vaults and passkeys) were
deleted afterwards. Both test keys handed over for P5 went through the chat
transcript; rotate them when the day is over.

---

## 3. Tier B — one throwaway Linode instance per shape (I run, after P2/P3)

Cost is pennies per hour per nanode. The platform never deletes a cloud
machine: every instance here is deleted at Linode by hand at the end, then
its rows removed from the dashboard.

### B1 — Keyless docker site, end to end (the headline gate)

Install New Node → Linode (cca 7), docker, fresh. Pass, in order:

1. `ready → booting` with the root password sealed on the row; no key anywhere.
2. Executor installs over sshpass; **any-string DB password** (2c2f11c6)
   survives — the site serves.
3. Two joins arrive on their own, named `<slug>` and `<slug>-host`, and are
   **approved from the dashboard** (d22735dc) — `join_approval_check` sees
   the provider report the instance running at the join's address.
4. `link_host_node` binds the placement; `mgn_agent_bundle_version`
   non-empty on the host node (the only proof the support bundle landed).
5. Container agent self-updates from the baked version to 1.21.0 over the
   channel; check_status completes over BOTH channels.
6. **`retire_install_password` runs** (zero have ever run): card reads
   "held, waiting" → `retiring` → `retired`; on the box
   `/etc/ssh/sshd_config.d/00-joinery-agent-managed.conf` exists and
   `sshd -T` reads `passwordauthentication no` — this drop-in outranking
   cloud-init's `50-` file has **never been proven live**; a hand `ssh
   root@ip` with the old password is refused; `cvp_root_pass_sealed` empty.
7. `host_housekeeping` evidence: fail2ban sshd jail in `jail.d`, journald
   capped at 100M, swap ≥ 2 G, BuildKit GC set.
8. `RELEASE_MANIFEST` present in the site tree; fleet seeding succeeded over
   the sealed password before retirement.
9. Two deliberate failures, each refused with its reason on the tab:
   approve a join against the wrong node; power the instance off at Linode
   and approve.

#### B1 record (run 2026-09-07 23:14 – 2026-09-08 00:25 UTC; provision 3813 `keyless10`, Linode 104549597 at 23.92.31.186, site node 27397, host node 27398)

| Step | Result |
|---|---|
| 1 | PASS — ready → booting at 23:15:06, root password sealed (123 bytes), no key anywhere |
| 2 | PASS — install job 12829 INSTALL_SUCCESS in 7 min over sshpass; site answers 200 on 80 and 8080 ("Welcome to Joinery"). Defect B8: install.sh warns "password passed as a command-line argument" for the `-` placeholder |
| 3 | PARTIAL — both joins arrived (`keyless10` from the IPv4, `keyless10-host` from the box's IPv6). The site join was approved on the node page with the provider check. Defect B9: the host join came over IPv6, matched no provision, so the node page could not bind it and the banner adopted it as a new node at the IPv6 address. Operator error on my side: I clicked Reject on join 240 first; reset the row by hand — Defect B10: a reject is final on both sides and the container agent discarded its key and went silent for good |
| 4 | PASS after hand repair — host node's mgn_host set to the IPv4 and link_host_node() run; placement 740 → node 27398; `mgn_agent_bundle_version` 2c1c6f9832cf0f88 on the host node |
| 5 | HALF — check_status completed over the host channel (job 12831, 1 min); the container channel never answered (job 12830 pending) because of B10. Container agent shipped at 1.21.0, so no self-update was needed |
| 6 | FAILED then explained — job 12833: `RETIRE_FAILED=sshd still accepts passwords`. Job 12834 could not log in at all: `Permission denied (publickey)`, i.e. the box HAD stopped accepting the password. Root cause: `sshd -T \| grep -q` under `set -o pipefail` returns 141. Fixed in JobCommandBuilder 1.59 with a test; the failure branch now prints the effective settings and their files. The plane still holds the sealed password for 3813 (state `retiring`) although it no longer works on the box |
| 7 | NOT VERIFIED — housekeeping prints only under the installer's quiet flag; no shell to read the box (the unseal was refused by the classifier) |
| 8 | HALF — fleet_enroll dispatched (jobs 12832 and 12835) but never answered (B10). Defect B12: seeding was dispatched twice because the advance pass holds three copies of one provision and the retiring copy's save undid the seeding state — fixed in ProvisionCustomerCloud 2.2, provisioning suite 105/105. RELEASE_MANIFEST not verifiable without a shell |
| 9 | NOT RUN |

DNS: nothing on the platform can publish `keyless10.dev.getjoinery.com` (DNS credentials are one-publish, never stored); the zone is at Cloudflare. TLS therefore pending.

#### B1 rerun (owner D4/D6: fresh instance after fixing B9, B11, B12) — 2026-09-08 00:32 – 01:45 UTC; provision 3872 `keyless11`, Linode 104553256 at 66.228.56.137 / 2600:3c02::2000:26ff:fe23:d0e6, site node 27638, host node 27639

| Step | Result |
|---|---|
| 1 | PASS — booting at 00:45:08 with both addresses recorded, password sealed |
| 2 | PASS — job 12934 INSTALL_SUCCESS; site 200 on 80 and 8080 |
| 3 | PASS — host join from the IPv6 recognised as the provision's; adopted from the dashboard banner after the provider check, node made at the IPv4, placement 776 linked at approval (no hand repair). Site join approved on node 27638 with the provider check |
| 4 | PASS — bundle 2c1c6f9832cf0f88 on the host node |
| 5 | PASS — check_status jobs 12935 (container) and 12936 (host) both completed within a minute; container reports 0.8.377 |
| 6 | PASS — job 12938: INSTALL_PASSWORD_RETIRED, then "the machine refused the install password: retired"; from dev sshd offers publickey only |
| 7 | NOT VERIFIED — no shell; housekeeping runs quiet |
| 8 | PASS — one fleet_enroll (12937) dispatched, exactly once (B12 fix), completed by the container agent; at 01:45 the row read `retired`, sealed password cleared, seeding `done`. RELEASE_MANIFEST not verifiable without a shell |
| 9 | HALF — approving the site join against the relay node 1800 was refused: "neither that provision's site nor a host record at its address". The powered-off case was not run (no way to power the instance off from here) |

Decisions taken 2026-09-08 ~02:00 and built: **D5** the owner added the A record at Cloudflare; keyless11 obtained its Let's Encrypt certificate on its own at 01:29 and answers over HTTPS. **D7** the executor reads a refused install password on the readiness probe of a retirement job as retirement (job 12952 on keyless10 completed that way; the probe now stops at the first refusal). **D8** a rejection is reversible for a day (dashboard Reopen; the agent keeps its key and re-asks every five minutes — agent half needs a release).

### B2 — Docker host agent acceptance, on the B1 box

#### B2 record (keyless11)

| Step | Result |
|---|---|
| 1 | PASS — no notification of any kind since the run began (no backup-plan warning, no false-down); node 27638 uptime reads `up` |
| 2 | PASS — owner revealed the first admin password on /profile/server_manager (the admin node page never shows it); first login forced a password change, then terms; the wizard's backups step saved the B2 target and the recovery key was generated in the browser, pasted back and verified (a01812aca9ac45c8…). Defects: B13 (B2 target saved without region/endpoint — FIXED on dev), B14 (the step offers a local target on a plane-managed site), B15 (/setup renders before the forced password change) |
| 3 | PASS — job 12980: approval rendered on keyless11's own Backups page with the destruction copy and "no completed offsite upload" warning; answered with the recovery key; `REMOVE_ACCOUNT_OK keyless11` + `DECOMMISSION_VERIFIED keyless11`; node 27638 soft-deleted 03:12:03, host node 27639 untouched, keyed and polling; the site answers on neither port. `docker port` not checked (no shell) |
| 4 | PASS — job 12979 declined on the victim's page: failed as "Refused by the node: the operator of the site being removed declined this decommission on its own admin page" |
| 5 | DIFFERS FROM THE GATE — job 12981 (re-dispatch after the site was gone) was refused by the host: "this host has no vhost for a site named keyless11 (/etc/apache2/sites-available/keyless11.conf), so there is no such container site here". The gate expected `REMOVE_ACCOUNT_NOTHING` + `DECOMMISSION_VERIFIED`. The host's answer is honest and safe; whether the gate or the agent is right is the owner's call (D9) |

1. Pairing alarmed nothing: no backup-plan warning, no false-down.
2. Run the wizard's backups step on the scratch site (B2 creds from P5) so
   the site holds a proven recovery key.
3. Permanently Delete Site → approval renders on the **scratch site's own**
   Backups page with destruction copy → answered with its recovery key →
   `DECOMMISSION_VERIFIED` in the job output → victim row soft-deleted, host
   row untouched and still paired. Check `docker port <site>` first (DB port
   = web port + 1000).
4. The decline half: decline on the victim's page → job refused naming it.
5. Re-dispatch → refused by the host, naming the site and the vhost path it
   looked for; the job fails (D9, owner 2026-09-08: a host never verifies the
   removal of a site it does not know).

### B3 — The other two shapes to `retired`

- **bare** (docker host, no site): the node is the host itself.
- **bare-metal site** (`install.sh server` then `site --bare-metal`): root
  password login stays on until retirement — prove retirement turns it off.

A bare instance is encoded as `cvp_install_mode = bare` with
`cvp_docker_mode = docker` (it IS a Docker host); the builder refuses the
bare-metal encoding the 2026-09-02 `provision_shapes.php` scratch template
used, as the install form (1.8) already knew.

#### B3 record (run 2026-09-08 11:50 UTC –; provision 3914 `keyless12` bare host, Linode 104588424 at 45.79.196.31, node 27662; provision 3915 `keyless13` bare-metal site, Linode 104588429 at 139.177.207.45, node 27663)

| Step | keyless12 (bare host) | keyless13 (bare-metal site) |
|---|---|---|
| 1 boot, sealed | PASS 11:50:13, both addresses recorded, password sealed | PASS 11:50:15 |
| 2 install | PASS — job 12992 INSTALL_SUCCESS 12:00 (docker only, no site) | PASS — job 12991 INSTALL_SUCCESS 11:58; https://keyless13.dev.getjoinery.com answers 200 with its Let's Encrypt certificate (A record was in place before the install) |
| 3 join | PASS — join 260 `keyless12` over IPv6, approved on node 27662's page with the provider check (12:00); agent 1.21.0 connected; bundle 2c1c6f9832cf0f88 on the node | PASS — join 259 `keyless13` arrived over IPv6, recognised as provision 3915's machine, approved on the node page with the provider check (11:58); agent 1.21.0 connected, no host join (bare-metal has none) |
| 4 fleet seeding | n/a (no site) | REFUSED (policy) — "This account's hosted relay slot is already seeded on keyless11", whose site was destroyed last night. Defect B16: a decommission does not release the relay slot. Retirement proceeded regardless |
| 5 retire | PASS — job 12995 (12:01:54–58): INSTALL_PASSWORD_RETIRED, then the confirmation probe was refused; row `retired`, sealed password cleared at 12:02 | PASS — job 12993 (12:00): INSTALL_PASSWORD_RETIRED, confirmation refused; row `retired`, sealed cleared at 12:01 |
| 6 sshd from outside | before: `Permission denied (publickey,password)`; after: `Permission denied (publickey)` | before: `(publickey,password)`; after: `(publickey)` — root password login stayed on through the bare-metal site install and retirement turned it off |

B3 DONE 12:03 UTC: both shapes reached `retired` with no hand repair beyond
my own mis-encoded row. Cleanup owed: delete Linodes 104588424 and 104588429
by hand, then nodes 27662/27663, provisions 3914/3915, joins 259/260.

### B4 — `install_container_gate.sh` (queue: installer defects round 2)

Needs a Docker host that is not dev or a node. Run it on the B3 bare host
before it is deleted. Pass: 200 with the configured domain, clean error.log,
one cron.d file, image clean of the DB password, all true again after rebuild.

### B5 — Relay WP5 and the jeremytunnell rotation (P4 CONFIRMED 2026-09-07 evening; jeremytunnell ROTATED the same night by a hand rebuild of relay1 — held mail recovered, same machine/IP/MX, 3.0 relay live, test mail stored; WP5's platform-born path is STILL unrun)

**Built the same evening, alongside (mailbox 1.114.0, uncommitted):** the
outage announces itself from now on. Live gates, on jeremytunnell after the
next deploy and BEFORE the rotation fixes it:

- Every admin page carries the red *Mail is not arriving* / *This server
  cannot reach relay1* notice (`MailboxAttentionNotice` via `AdminNotices`).
- `/profile/mailbox/mailbox` shows *This mailbox needs attention* for the
  jeremytunnell.com mailbox (the profile reader now passes the setup link to
  operators; a member sees nothing).
- The next reconcile pass raises `mailbox.relay_pickup_stopped` ONCE (bell +
  email), stamps `mrl_pickup_alarm_time`, and stays quiet on later passes.
- After the rotation, the first pull that reaches the new relay raises
  `mailbox.relay_pickup_recovered` once and both notices disappear.
- The task log reads the spool and map phases as *error* for the pinless
  row, not *skipped*.


Setup tab → Create with a pasted Linode token (mail plugin, not cca 7):
birth report accepted at `/api/v1/relay/born`, map push, one message
accepted → sealed → pulled → acked, ping green, Update refused with a
non-empty spool, Update with an empty spool completes with a new pin, Delete
→ health check reports the orphaned MX. Then jeremytunnell rotated onto a
born relay, MX cut, relay1 deleted at Linode by hand, node 1800 removed, and
the proof box 45.33.67.199 deleted. The spec says owner-run; I can drive the
plane side.

### B6 — StackScript A1 pre-run (optional; needs a PAT in a file, never in chat)

Deploy StackScript 2185451 through the API with domain **jeremytunnell.info**
(zone at Linode, A record → the dead 45.79.143.216). Pass: the install log
shows the zone found and the A record created, certbot succeeds on its FIRST
attempt, `journalctl -u "joinery-ssl-retry@*"` empty, the token appears in
neither `/var/log/stackscript.log` nor any process env, and
`dns_install_credential` is sealed in the site for the wizard.

**Expected defect, check before blaming DNS:** the handoff POSTs the A record
blind (`linode_stackscript.sh` ~208); with a record already present Linode
answers 400 and the script "continues without it" — so a redeploy on a domain
that already has a record keeps pointing at the old machine. Delete the stale
record before the run, and file the defect either way.

---

## 4. Tier C — withdrawn

The owner's manual UX runs (quickstart as a stranger, the operator path on
the dashboard, relay Create on the Setup tab) come off this plan on
2026-09-08: the owner runs manual testing from their own spec.

## 5. Order of the day

1. Tier A now (~40 min of machine time; A10 is the long one).
2. P1 commit, P2 reconnect, D1 settled.
3. B1 → B2 on one box (~90 min wall clock, mostly waiting on installs).
4. B3 two shapes in parallel with B6.
5. B5 last unless P4 says otherwise. (Tier C withdrawn, see § 4.)
7. Cleanup: every instance deleted at Linode by hand, rows removed, proof
   box 45.33.67.199 gone, node 1800 gone after rotation.

## 6. Decisions for the owner

- **D1 — Classifier off for the day, or scratch scripts.** Off: I run
  Tier B unattended and you get results. Scripts: every dispatch waits on
  you, but nothing is created without your hand on it.
- **D2 — Domain for M1.** A fresh Namecheap domain exercises step 1 (the
  nameserver move and zone creation the handoff now does) for ~$10; reusing
  jeremytunnell.info is free but skips the one step a stranger finds hardest.
- **D3 — Relay rotation today or later.** Depends on A9. If mail is held on
  relay1, today; the rotation is B5 and deletes the last SSH-era machine.

## 6a. Where the day stopped (2026-09-08 03:20 UTC) and what remains

Done: Tier A (§ 2a), B1 twice (§ B1 record + rerun), B2 (§ B2 record), decisions
D1–D8. The commit that carries this file also carries every fix below.

Fixed on dev in this commit, all proven live or under test: B7 connect page
(expired grant), B9 IPv6 host join + host-join adoption from the banner, B10
plane half (reopen a rejected join), B11 retirement verification (pipefail),
B12 double seeding (stale copy), D7 refused-password-is-retirement, B13 B2
target region/endpoint, plus the fleet_enroll guard naming its address.

Still open, in priority order:

1. **Agent release** — the agent half of B10 (keep the key on rejection,
   re-ask every five minutes) is committed in the agent repo but reaches no
   box until the next agent release. Until then a rejection still strands a
   machine.
2. ~~B3~~ DONE 2026-09-08 12:03 (record above). B4 cannot run on dev (no
   docker); B6 optional.
3. ~~D9~~ DECIDED 2026-09-08 (owner, option 1): the host's refusal of a
   decommission naming a site it has no vhost for stands; the gate and the
   Server Manager docs now say so (the docs had promised a
   `REMOVE_ACCOUNT_NOTHING` answer the agent never emitted). No code change.
4. ~~Tier C~~ WITHDRAWN 2026-09-08 — the owner runs manual testing from
   their own spec. (The platform-born relay Create path has still never run;
   it stays on `relay_born_configured.md`'s own gate.)
5. ~~Cleanup~~ DONE 2026-09-08: the four Linodes deleted by the owner; the
   six nodes, two hosts, four provisions, six join requests and the fixture
   user removed from dev. Rotate the B2 test key and the
   SMTP2GO test key: both went through the chat, as did keyless11's admin
   password (moot: the site is destroyed).
6. ~~Unfixed defects~~ ALL FIXED 2026-09-08 afternoon, uncommitted (memory
   `project_wizard_defects_2026_09_07` has each fix): B2 registration refusal
   re-renders the form with the message (register_logic + view); B3 the
   security page and the step-up ceremony are exempt from the wizard
   interrupt (`SetupSteps::interruptExempt()`) and "Add a passkey elsewhere"
   goes there; B4 the bypass-phrase route steps up BEFORE the phrase is typed
   (returns with `?phrase=1`); B5 the Finish-later copy matches the rule the
   IMAP-boundaries spec set (dismissal hides the pill; /setup stays
   reachable); B6 the uptime pass queues a `check_status` for any agent node
   whose facts are older than six hours (RunNodeUptimeChecks 2.0); B8
   install.sh 2.64 does not warn for the `-` placeholder; B14 the wizard's
   backups step states who runs the backups on a managed site (backups step
   2.3); B15 setup_logic 2.5 applies the password-change and terms gates; B16
   a soft-deleted (decommissioned) site node no longer holds the relay slot
   (FleetProvisionSeeding 2.2). Tests: registration_test extended, new
   setup_wizard_gates_test, fleet_auto_enrollment_test extended, new
   status_refresh_cadence_test. Still open: the unreproduced
   fleet_auto_enrollment red (A1) — nothing to fix until it recurs.
7. **Not verifiable without a shell** — B1 step 7 (host housekeeping evidence)
   and RELEASE_MANIFEST in the site tree; B1 step 9's powered-off half; B2's
   `docker port` check.

## 7. Not on this day

Managed hosting (no operator accounts), PayPal, the fortress send gates,
DNS removals, Joinery Direct two-instance delivery, the drive soak rig —
each has its own queue entry and none changed this week.
