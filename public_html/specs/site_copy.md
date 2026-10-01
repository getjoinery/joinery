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
  - the fleet badge reads "Copy — dormant" until WP8 links the copy row to S.
  - **Review (public-html-a5, 2026-09-28):** R1, R4, R5, R6 and the low items fixed. Two limits
    carried to WP8, and true until it lands:
    - **Nothing on M sets `copy` yet.** A dormant install joined to M today is a row with no install
      state: M treats it as live, and its backups, uptime checks and notices fail against the quiet
      copy. Do not join a dormant install to M before WP8.
    - **`install.sh` has no release pin.** `--dormant` installs whatever release the upgrade server
      serves now, not S's. Until WP8 adds the pin, a copy is only correct when S is on the current
      release.
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
    `update_database`. Fix: the install seeds the registry and mints the canary.
  - **B30 — The install's last check follows a redirect to the bare IP.** OBSERVED on S: it reported
    `http://66.228.35.148/login` (302) before the certificate existed; the domain redirects
    correctly. Cosmetic, but the warning reads like a fault.
  - **B32 — A new site's admin cannot sign in.** OBSERVED on S (ERR_TOO_MANY_REDIRECTS at
    `/terms-accept`), TRACED: since every signed-in page runs the navigation gates (a5d22d7e,
    2026-09-24), the terms gate sent an admin who owes a password change off
    `/change-password-required` and the password gate sent them back; the wizard gate did the same
    to `/terms-accept`. Fixed in `SessionControl` 1.8: the first gate owed holds its own page and
    ends the check (`navigation_gate_target()`, tested in `setup_wizard_gates`). Every fresh install
    since 2026-09-24 is affected until it takes the release with the fix.
  - **B31 — Postfix on 26.04:** `postfix/postlog: not owned by root: /var/spool/postfix/etc/resolv.conf`,
    and `/etc/aliases` has no root alias. OBSERVED on S; not yet traced.
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
  - Fix: rewrite the driver against the Cloud API. WP7 needs it for Hetzner.
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
  possible for them. They come with the DNS switch (WP7).
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
   - S is not a management node.
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
   - **M creates it** through a provider driver: Linode today, Hetzner later (WP13). A Linode T is
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
- **Discard:** the copy row's ordinary node delete, which deletes the instance.

### Switch over (steps 7–10; a separate action, on the owner's word)

7. **Freeze S (downtime starts).**
   - S's row on M goes to `switching`, so `is_operational()` holds fleet backups, staged rollouts
     (`AdvanceStagedRollouts` could otherwise update S after the final copy), pruning and uptime
     checks for it. T's row is already `copy`.
   - `site_quiet on` sets `quiet switchover` on S (the quiet state): the maintenance page, no cron,
     Postfix deferring, the firewall rule, and the wait for command-line PHP to finish.
   - S's agent stays on throughout; the way back needs it.
8. **Final copy.**
   - S runs one last chain run marked *never roll*, so it can never turn into a full.
   - `copy_export` runs again. T downloads that increment and runs the full apply at it, which also
     discards anything the owner's look changed.
   - The price of M2: the file part is unpacked again from local disk inside the downtime, about 1–3
     minutes for jeremytunnell's 3 GB, growing with the site. L1 measures it. Applying only the
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
       and on T (about a second on dev; more with jeremytunnell's 16.7 GiB of uploads). L1 measures
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
     - Preflight also lists anything on S that allows only the proxy's addresses in (a firewall), so
       T gets the same.
     1. M changes the proxied record's address from S to T. Visitors follow within seconds, because
        they only ever see the proxy; there is no TTL wait.
     2. Prove T answers through the proxy: HTTPS to the site's name gets the dormant health answer
        from T. The certificate travelled, so a strict-TLS proxy accepts T.
     3. Update `mgn_host` on M.
     - Failure here is cheap too: M changes the record back and unfreezes S. T stays dormant.
     - Needs a DNS token for the zone on M, through the existing DNS credential flow.
   - **DNS (WP7; built later).**
     - M lowers the TTLs during the copy.
     - At this step S's drop-in turns from the maintenance page into forwarding to T, and T trusts
       `X-Forwarded-For` from S's address through Apache's `mod_remoteip` in T's vhost, so its
       per-address throttles see real visitors with no change to the site's code.
     - M, or the owner, changes the records, and M waits until public resolvers agree plus one old
       TTL.
     - Postfix on S keeps deferring, so mail retries until MX reaches T.
10. **Start T.**
    1. T's agent is told to take S's node id, keeping its own key (D4).
    2. **M swaps the two rows (no new record type).**
       - S's row keeps its id, slug and history, takes T's key and host, and leaves `switching`: it is
         the node, now on T.
       - T's copy row takes S's old key and host and becomes `retired` ("Retired source of S" in the
         fleet list). S's provision row is re-pointed to it, and T's to the node.
       - `is_operational()` keeps the retired row out of every automation. Its delete is the ordinary
         node delete, which deletes S's instance. The way back reads S's key from it.
    3. `site_quiet off` on T. The agent allows it now, because T holds S's node id. The next
       converger tick writes the cron, runs the installers (the mailbox's arms Postfix and sets the
       `iemap_` role's password) and certbot's timer comes on. An installer that fails is retried
       every minute, as on any site.
    4. On the DNS path, S stops forwarding once DNS has settled, and is powered off still `quiet
       switchover`. If it is ever booted, it never runs the site.

    T is now the node. Its backups continue under the same node, and its first run is a new full
    backup, sealed to T's own `backup_site_key`, because its tree identity changed. Chains from
    before the switch stay sealed to S's site key and the recovery key: they open by the recovery
    ceremony, as after any key change.

S ends powered off, whole and quiet. Deleting it is the owner's separate action: the retired row's
ordinary delete.

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

Steps 2, 4, 5, 9 and 10, without `copy_export` and the freeze.
- **Keys.** With no S to seal anything, the owner opens the newest chain's data key once, with the
  recovery key, in a ceremony on T's site. The browser opens the recovery-sealed data key and
  re-seals it to T, which writes it as the chain's `chain.key`: what `copy_import` leaves in Phase 1,
  so everything after it is shared. Older chains open by the recovery ceremony, as any rotated key does.
- **Trust.** The ceremony's statement names the chain, its date and its manifest hash, and stands in
  for the ledger check. The owner is the authority, because no machine that made the archive is
  left to vouch for it.
- **Age.** The backup's age is the data lost. The ceremony shows it as an age, above the key box.
- **Address.** DNS. There is no S left to forward; visitors whose DNS still names S's old
  address fail until it settles, so the TTL matters. An IP swap is possible only if S's instance
  still exists at the same provider.

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

L1 measures the real downtime. If it is too long, the middle path above is the fix, and it can be
added without changing anything else.

**D3 — Decided 2026-09-27.**
- **Design target:** Linode to Hetzner. Every step is written so that it works between any two
  servers.
- **Build order (revised 2026-09-28):** the proxied origin change (WP7a) first, because it is the
  smallest switch and jeremytunnell's; then the IP swap (WP12); then the DNS switch with forwarding
  (WP7), which completes the any-provider ability.
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

**Order of building.** Phase 2 is built after Phase 1 is proven live. It reuses WP2, WP3, WP5 and
WP7 unchanged; only the ceremony (WP10) is its own, and it produces the same input as
`copy_export` (a data key sealed to T).

## Work packages

In build order. Each is built and tested on its own (design rule).

**New agent words (seven):** `copy_export` (S), `copy_import` (T, Q6), `copy_stage` (T, G7),
`copy_restore` (T), `site_census` (any, read-only), `site_quiet on|off` (S; T's `off` at step 10), and the node-id word (T).

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
- **WP7a — Proxied origin change (the first switch built; L0).**
  - The preflight: every record naming S's address is proxied; list any proxy-only firewall on S.
  - The record change through M's DNS driver (Cloudflare first), the proof through the proxy, the
    change back on failure, and `mgn_host`.
  - No forwarding, no TTL lowering, no resolver watch: those are WP7.
  - Its live test is L0, on a proxied record under `jeremytunnell.info` (Q3).
- **WP8 — Copy and Switch Over on the management node.**
  - Carried from WP5's review:
    - M sets the copy row's install state to `copy` when it creates the row or approves its join;
      `JobResultProcessor`'s install-success path (which writes NULL) must not clear it.
    - `install.sh site --release=X.Y.Z`, with the upgrade server serving that release's archives,
      so T installs S's exact release (vendor/ is never in the chain).
    - The copy preflight refuses an S older than the WP5 release: the converger's quiet gate and
      its helper live in the tree `copy_restore` lands on T.
    - T installs under S's site name and S's domain: `restore_chain.sh` refuses a target whose
      directory name differs from the one the archive carries, the project name is also the
      database name, and `copy_restore` passes no `--domain`, so T keeps its config's own.
  - **Copy to a new server:** preflight, then "create it for me" or "I'll bring a server", then
    steps 2–6, progress, Refresh and Discard.
  - **Switch over:** steps 7–10 and the way back.
  - The node-id word, and the row swap (both directions).
- **WP9 — Retire Clone.**
  - Delete `clone_export.php`, `clone_export_arm.php`, the `from_backup` install mode,
    `cvp_clone_key_sealed`, the `clone_export_key` setting, `scrub_sealed_secrets.php` and their
    tests.
  - Retire the agent's `clone_export_arm` under the vocabulary rules.
  - Update the documents.
- **WP11 — Live proofs.** See the test plan.
- **WP12 — IP swap (after the first live proofs; L0b).** The switch most of the fleet will use:
  every node is on Linode, and almost none sits behind a proxy.
  - `swapIpv4`, with `regions` and interface-model reads on the Linode driver.
  - The preflight: Network Helper, the scope, the firewall, the IPv6 list.
  - The sequence and its failure path from step 9.
  - The record updates.
  - The Linode token request widened to `ips:read_write`, with a re-grant for existing tokens
    (`plugin.json:403`, `profile_connect_cloud_logic.php:48`, `relay_admin.php:229`).
- **WP7 — The switch by DNS (after WP12; the exit capability: any provider, Hetzner first).**
  - TTL lowering, the record change and the resolver watch.
  - Forwarding from S's drop-in, with `mod_remoteip` trusting S's address in T's vhost (no site code
    change).
  - The IP-bound list.
  - Container sources onto bare metal.
- **WP13 — A Hetzner compute driver (convenience).**
  - `POST /servers` with the returned root password, never an SSH key; `change_dns_ptr`; locations.
  - Its transfer counter is per server, which does not fit the interface's pooled `getTransfer()`.
- **WP10 — Phase 2.** The recovery-key ceremony on T's site, re-sealing the data key to T, and the
  owner's statement standing in for `copy_export`'s signed list.

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
    creation path, installs the seeded test site from L1 on it under a proxied record in
    `jeremytunnell.info`, copies it to a T that M creates, switches, checks, and deletes both
    servers. It never depends on a box's history.
  - Needs `jeremytunnell.info` on Cloudflare with a zone token on M (Q3).
  - Pass: as L1, except visitors follow within seconds and there is no forwarding window.
- **L0b — the same, switched by IP swap (once WP12 exists).** One account and region; needs the
  operator Linode token re-granted with `ips:read_write`. Pass: the address and reverse DNS stay
  the same.
- **L1 — Linode to Hetzner: copy, look, switch over by DNS (once WP7 exists).**
  - S is a test site on Linode, managed by dev.
  - T is a Hetzner server the owner creates and joins with the owner-run command.
  - The test domain is a direct (unproxied) record under `jeremytunnell.info` (Q3).
  - The source is a small site seeded for coverage:
    - sealed secrets set (a backup target, an OAuth secret, an IMAP account);
    - a passkey registered through a Playwright virtual authenticator;
    - a vault;
    - raw mail in `storage/`;
    - an offloaded file;
    - a custom theme;
    - a DKIM key.
  - Pass:
    - The census is equal and there are 0 dead secrets.
    - The same browser signs in with the passkey and unlocks the vault.
    - The offloaded file downloads, and mail pickup resumes.
    - T's backup runs under the same node, and one agent polls.
    - While DNS settles, a request to S's old address reaches T.
    - S is powered off and whole.
    - The certificate is unchanged.
    - The downtime is measured, against the D2 estimate.
- **L2 — The way back and the dormant copy.**
  - Back out after step 8: S serves exactly as before.
  - Refresh a dormant copy, then discard it.
  - Go back after step 9: S serves, and the dashboard names what T wrote since it started.
- **L3 — Phase 2.** Power S off for good and copy from its backups onto a new T with the recovery-key
  ceremony, once WP10 exists.
- **L4 — jeremytunnell onto Ubuntu 26.04 by Cloudflare origin change (Q4).** Only after L0 and L2
  pass, and only on the owner's word. T can be in any
  region or account, since no address moves.

## Out of scope

- Copying the management node itself: it carries the agent signing key and the fleet's control.
- Changing the domain: a rename, with passkeys lost by design (I4).
- Carrying hand-installed host services: they are listed, not copied.
- A container site as the target, and any machine hosting more than one site (v1).

## Open questions for the owner

- **Q1 — Answered 2026-09-27:** copies must work across Linode accounts and regions, and to Hetzner.
- **Q2 — Answered 2026-09-27:** there is no Hetzner account yet; the owner will get one later. L0
  (Linode to Linode) comes first.
- **Q3 — Answered 2026-09-28: `jeremytunnell.info`**, unused. It holds a proxied record for the WP7a
  test and a direct one for L1.
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
