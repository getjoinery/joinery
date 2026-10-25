# Encrypted data root: data encrypted at rest, with the key held off the machine

**Status, 2026-10-17:** built, released and live-tested; every open item is
settled. The rehearsal (D7 step 2: T-M1/T-M1b, T-R2, T-E9, T-E12, T-E13, T-K2,
T-K5, T-P5) passed on throwaway management nodes on 2026-10-13, and the fixes
it found are released by 0.8.503 (B86, committed after it, is not). What is
left is D7 steps 3-8 on the real machines, timed by the owner: dev encrypts in
place (step 3), then `release_key_rotation`'s ceremony (step 4), getjoinery
(5), the mutual escrow (6), the other three by copy (7, in
`site_copy_production_moves.md`), and the launch release (8).
T-P1 passed on 2026-10-17: a Managed order paid on dev with a test card was
born on a 1 GB Nanode with the provider's layer reported disabled, its data
root encrypted and sealed to dev's recovery key, escrow enrolled with dev, and
a reboot opened by escrow in 3 seconds; it found B87 (every Managed order
stalled before create) and B88 (a subscription cancel answered 500). Not run
anywhere: T-V5 (no Lish access from here) and T-V8 (needs a filesystem that
refuses direct I/O). The 2026-10-12 notes below are the record of how it got
here.

**Build status, 2026-10-12:** every work package is BUILT (WP1-WP10, each
marked in its entry), shipped dormant behind `data_root_encryption_offered`;
committed 2026-10-12 (3fcf7b8f, agent d84bf16) and released in 0.8.484. The
Linode phase is below.
Proven on the Mac mini VM (Ubuntu 24.04 arm64, swtpm), each by the test named:
- **Born encrypted:** T-V1, and T-K1 (a marker written into `config/` is
  absent from the raw root disk; a control marker on it is found).
- **The volume and boot:**
  - T-V6: a locked boot with no failed unit and no ordering cycle.
  - T-V10: a slot not made our way fails `check`.
  - A clean shutdown order and clean XFS mounts, checked on the serial
    console.
- **What stays off the root disk:** T-A5. Markers in the logs (syslog and the
  journal), a PHP session, PHP's temp files, DKIM, `config/`, a PostgreSQL row
  and `/tmp` are all absent from the raw root disk.
- **Secrets under the data root:**
  - T-K3: the agent waits in site posture, then restarts and reads its config.
  - T-K6: `fix_permissions.sh` changes nothing while the data root is down.
  - T-K7: 382 reads of `config/` during its `move-in`, none failed.
- **Rotation and rekey:**
  - T-R1: a rotation re-seals, so the new key opens the copy and the old one
    does not.
  - T-R4: a power cut part-way through a rekey; opened by the recovery key, it
    resumed and finished.
  - T-R6: the TPM2 chip enrolled again and opening the next boot.
  - A rekey to the finish, with a changed volume key and the data intact.
  - T-R3 with dev as the escrow holder, file shape. The VM enrolled (approved
    on dev's dashboard). At the rekey's finish dev's `rekey` action minted a
    new secret and took the new report key, and the new escrow slot was
    confirmed. The next boot, its TPM2 slot taken away, opened by escrow with
    the new secret.
- **Encrypt and undo:** `decrypt` then `encrypt` again in place, on a machine
  whose `/var/log` is on the data root.
- **Tier 2:** the event names, probed in the test account.
- **Suites:**
  - the data root gate (221);
  - `data_root_escrow_test` (46), `escrow_tier2_test` (29),
    `provision_one_layer_test` (60);
  - the root request (103), vault health (81), installer contract (878) and
    host report (171) tests;
  - the agent's `go test`.

**Linode phase, 2026-10-12** (release 0.8.484, committed as 3fcf7b8f, with that
day's fixes copied over it; test account, us-east). Passed, by test:
- **Born encrypted:** T-P6 and T-V1 on a 1 GB Nanode with the provider's
  layer off; the volume key is absent from the raw root disk and Linode's swap
  disk. That swap disk is turned off at create and stays off.
- **Escrow and boot:** T-E1 (secret in no job row, journal or log), T-E2 (open
  7 s after the unlock starts), T-E3, T-E7 (IPv4 only), T-E8 (a second ask
  inside 15 minutes refused; a lost answer waited out), T-E10 (every request
  through Cloudflare), T-E11 (X25519MLKEM768 from a Go 1.27 client).
- **Unlock page and monitoring:** T-U1 without Cloudflare, T-U2, T-U5, T-U6 (a
  multi-tenant host serves each site's own certificate while locked), T-U7.
- **The claims:** T-A1 (a backup's copy holds only ciphertext), T-A2/T-E5 (the
  copy is refused), T-A3/T-A4 under Tier 1 (answered: the known gap) and under
  Tier 2 (refused, with the event), T-A6 (only "I did this myself" or a copy).
  T-E6: an IP swap leaves no provider event; Tier 2's instance check refuses a
  copy at a moved address.
- **Shapes:** T-V9 (the tick grew an encrypted file volume, the key never on
  disk), T-V11 (2,000,000 iterations took 1.5-2.5 s idle and 3.8 s under
  pgbench; at 1,000,000 an open takes 0.7-0.9 s idle and 1.6 s under
  pgbench), T-V12 and T-M2 (a multi-tenant host, born
  encrypted, then undone and encrypted again in place with both sites up).
- **T-F2, the reliability campaign:**
  - Cuts on 1 GB and 4 GB file-backed data roots and a 1 GB Block Storage
    device, with PostgreSQL under pgbench, 38 in all: in the start, mid
    run, during the finish, and during the undo.
  - Every run ends encrypted or decrypted, with checksums, `pg_amcheck` and
    `xfs_repair -n` clean.
- **T-S:** a 1 GB box, plain against encrypted in place: −80% sequential read,
  −37% random read, −20% random write, −11% pgbench. A 4 GB box: −64%, −22%,
  −9%, −1%. The sequential loss is the 512-byte sectors an in-place run
  keeps: a 1 GB box born encrypted reads 1246 MB/s against 527.

Found and fixed in that run (uncommitted), each with a gate case:
- **The installer** checked the wrong hash of its agent and unpacked it where
  nothing can run. The binary is now placed first, which also lets a reboot
  before the site install open the volume.
- **The first size** of the data root left the root disk under its reserve:
  no growth, no in-place encrypt, a disk case on every new Nanode.
- **A cut during encrypt's start** left a LUKS header under a record saying
  plain. The tick now undoes such a start, which had encrypted nothing.
- **A busy bind** (rsyslog's socket in Postfix's chroot, on every host) kept
  the plain filesystem live while the volume was written. The start now stops
  the services that hold one, or refuses.
- **The converger** could not start while a plain data root was unmounted, so
  nothing could repair it. It logs to the journal now.
- **A device's filesystem** grew into the room the header needs. It now keeps
  its last 32 MiB.
- **A new unit file** written just before a cut came back empty, so masked,
  and the decrypt never resumed. Every file a boot relies on is now fsynced,
  and the tick starts a recorded run that is not running.
- **A record change** of several keys could stop half way. It is now one
  rename.
- **An IP swap** did not move the escrow rows' addresses. This fix is set
  aside as a patch until commit: its data class blocked a publish.
- **Container sites** looped forever on a pid file a cut left behind.
- **An interrupted rekey** on network storage took over half an hour to
  open. Its rewrite area is capped at 8 MiB.
- **Speed:** data roots are formatted with 4096-byte sectors.
- **Agent 1.73.1** fixes its help text and what `enable` says on a site.
- **Key slots** are PBKDF2 at 1,000,000 iterations, about a second to open
  (T-V11; owner, 2026-10-12). `check` accepts a slot at exactly 1,000,000 or
  2,000,000, the counts a release has made (R27), and no other.
- **A Docker host that never joined** a management node had no host
  converger, so its data root never ticked: no growth, no re-seal, no reboot
  policy. `install.sh docker` now installs the release's own support bundle
  through the agent (`joinery-agent bundle install`, agent 1.75.0), whose
  converge installs the converger. Proven on mt-enc: a changed bundle and a
  missing statement are refused, the real one installs, and the timer runs
  the tick every minute.

Not run on Linode, and why:
- **T-V5 and Lish:** no Lish access from here. The console prompt is proven on
  the VM.
- **T-U3/T-U4:** proven on the VM; the code is the same.
- **T-P1/T-P2/T-P4:** they need `data_root_encryption_offered` on dev, the
  plane that provisions.
- **T-P5, T-K2, T-K5:** they need a verified backup, so a backup target.
- **T-R2, T-M1/T-M1b, T-E9, T-E12, T-E13:** they need throwaway management
  nodes. They are the D7 rehearsal.
- **T-V8:** needs a filesystem that refuses direct I/O.

**Status:** DRAFT 2026-10-07, rewritten the same day from the owner's framing
(below). Decided beyond the framing: one standard LUKS configuration for
every node (D-E) and the provider's disk encryption off where ours is on
(D-F), both 2026-10-07 after measurement; never two layers, enforced at
birth and at every move (D-G, D1a), 2026-10-11; the console prompt takes the
recovery key, so no install has a passphrase (D-H, D2a), and a rotated
recovery key re-seals the break-glass copy (D-I, D8), a locked machine
offers an unlock page in the browser before the console (D-J, D2b), and
getjoinery and dev hold each other's escrow (D-K, O4), 2026-10-11.
Reviewed by reviewer2 2026-10-11 (R1-R22, all applied: the unlock identity
and escrow table, enrolment after the join, `--disable-keyring`, the
re-encryption order, the holder-first unlock, dev's backup space for
getjoinery, C-4; the getjoinery parts later replaced by D-L). Reviewed again by reviewer1 2026-10-11 (P1-P16, all
applied: honest Tier 1 claims with an address check, the break-glass
recipient at birth, the boot wiring, the locked state the uptime check sees,
the getjoinery move re-ordered, PBKDF2 slots), and a second pass (P17-P26).
Owner 2026-10-11: PBKDF2 at about one second, not the minimum (D-E); the
post-quantum gaps recorded as O7. 2026-10-11: O2 settled (60 s), O8-O11
added, and a Testing section (T-L, T-V, T-E, T-U, T-P, T-R, T-A, T-M, T-F,
T-S) on test-account scratch boxes. 2026-10-11: T-L run (T-L6 failed: no
cross-setting clone); the five existing machines keep both layers and are
encrypted in place (D-L), which replaced the getjoinery copy plan in D7;
`config/` moves under the data root (D-M, D9, WP9); the release keys are
rotated in a spec of their own (O12); Tier 1 at launch and Tier 2 straight
after (D-C); the O1 inventory done, and what it found on the root disk moved
into D10; O9, O11 and O7 confirmed. Reviewed again by reviewer2 2026-10-11
(E1-E15, applied: no boot ordering cycle, `grow` without a passphrase, the
converger logging to the journal, the journal flushed after the unlock,
`fix_permissions.sh` waiting for the data root, both uptime paths reading
"locked", D7 re-ordered so no escrow exists before the key rotation, no plain
override after a rescue boot). O13 decided: existing data roots are encrypted
in place by LUKS2's online encryption, gated by the T-F2 reliability campaign
(D5). Built dormant and switched on at the end (D-O, "How it is built"),
2026-10-11. **WP1 built 2026-10-11, uncommitted** (`joinery_data_root.sh`
1.4, agent 1.73.0 `unlock` and `data-root`): proven on the Mac mini VM
(file and device backings, TPM2 hands-off, the recovery key at the serial
console, grow through the mapping, Postgres on the volume across reboots);
the Linode measurements (PBKDF2 count and slot memory on a 1 GB node, sector
size speed) wait for a release and come with WP3. Three assumptions failed
on the VM and are fixed above: the console agent (D2a), shutdown under
`blkdeactivate` (D1b), discards (D1). Stands on
`one_data_root`, which landed 2026-10-10 and has moved every node; every
install shape now looks the same to this spec. The research that led here
(Linode rescue mode, the precedents, the measured performance) is kept at
the end.

## The goal, in one sentence

**On any install — hosted or self-hosted, container host or bare metal — the
data root can be an encrypted volume whose key is never on the machine's own
disks, so a copy of the disk is unreadable; and nobody has to keep a new key
to make that true.**

## The owner's framing (2026-10-07)

- This is a **feature**, not warrant resistance. The sealed vault is per-user;
  business customers whose team features need shared, always-readable mail
  keep that mail outside the vault, so it sits plaintext on the disk today.
  "Encrypted at rest, key held separately from the hosting provider" is what
  those customers and their compliance checklists expect, and what the
  platform cannot claim yet.
- It is for **every install**: on by default where we host, optional on
  self-hosted installs, and organised so that multi-tenant, single container
  host and bare metal are not three designs.
- **No new human-held key.** The owner already holds one recovery key; this
  reuses it.
- **Root login stays off.** Nothing here needs a login. (During the beta,
  `beta_break_glass_root_access` keeps a root password login on hosted
  machines whose owner allows it; this line holds again when the beta ends.)
- What it does not promise is stated as plainly as what it does.

## What it buys, and what it does not

| Reader | Today | Encrypted data root |
|---|---|---|
| The provider's backups, snapshots and images, or a retired drive, read offline | plaintext | ciphertext — the key was never on the disk |
| A copy of the machine (a backup restored, a clone, an image) booted on another address | plaintext | ciphertext — the escrow holder refuses a boot request from an address not enrolled for that machine (D3) |
| A stolen cloud login that resets the root password, boots the machine in rescue mode, or boots a copy at the machine's own address | plaintext | **readable under Tier 1, and the code on the root disk can be altered**: the machine, or its stand-in, asks from the right address with the unlock identity on its plain root disk, and the holder answers; after a root-password reset, Lish then logs in. The site's code, the agent and everything else on the plain root disk are writable in rescue mode, and nothing re-checks the code a web request runs. Tier 2 (built straight after launch) refuses the boot after those provider events, for machines in an account we hold a lasting key for (D3); a machine in a customer's own account stays at Tier 1 |
| Us, with the agent, on the running machine | plaintext | plaintext — the volume is open while the machine runs, and we hold the escrow |
| A court order served on us | we can be made to comply | unchanged — that is the sealed vault's job, and the vault is unchanged by this spec |

Sealed-vault data is ciphertext before and after; this spec adds nothing to
it and takes nothing from it.

**The two escrow holders are the exception to the first row.** An offline
copy of getjoinery's disk, or of dev's, holds every escrow secret it keeps,
under a `SecretBox` key on that holder's plain root disk. Each holder's
secrets are protected only by its own encrypted data root, whose escrow the
other holder keeps. That is the circle D-K accepts (O4).

## What is protected: the inventory (O1, 2026-10-11)

Taken from the code and from dev's database and disk (counts are dev's,
2026-10-11). Three kinds of data, by what protects each.

**Sealed by the vault, per user, whatever this spec does.** Only where the
user or the setting chose Private or Fortress; every protection level
defaults to Standard, and an account with no vault has nothing sealed.

| Kind | Sealed when | On dev |
|---|---|---|
| Mail (sender, subject, bodies, recipients, headers, attachments, search index), mailbox contacts | the mailbox is Private or Fortress | 40 of 2,108 messages |
| Drive file bytes (names, sizes and types stay plain); names too at Fortress | the folder is Private or Fortress | 7 of 2,011 files |
| Messenger bodies and attachments (subjects and notification previews stay plain) | the conversation is Private | 5 of 25 messages |
| AI chat titles, turns, tool calls, attachment text (memories and notes never) | the conversation is Private | 14 of 274 turns |
| Calendar title, location, notes (times stay plain) | the calendar is Private | 0 of 892 entries |
| Password manager entries | always | |

**Plain, protected at rest only by this spec.** Everything else in the
database, uploads, storage and site logs, which is most of every site:
accounts and personal details (`usr_users`, addresses, phone numbers);
every Standard message, mail, file, calendar entry and AI turn above; the
TOTP secrets and second-factor keys (`usr_users`, plaintext); provider
secrets in `stg_settings` that are not sealed (Stripe, PayPal, mail
providers, AI providers, captcha); the provider's raw order and payment
responses (no card numbers are stored); event registrations, checkout and
survey answers; ScrollDaddy devices and rules; analytics with IP
addresses; webhook payloads, change tracking and the site's access and error
logs.

**Leaving the machine, protected otherwise.** Backups are encrypted per run
and sealed to the recovery key (`docs/backups.md`). Files and mail offloaded
to the cloud bucket are **not** encrypted before upload: Standard content
sits there as plaintext, Private and Fortress as vault ciphertext
(`CloudOffloadEngine`, `docs/cloud_storage.md`); the bucket's own encryption
is the customer's. Mail, payment and AI providers receive plaintext over TLS.

**On the plain root disk, outside the data root, before D9 and D10.** The
inventory found more than `config/`: PHP session files (live session ids,
carts with buyers' details, and, by a bug, a registration form's plaintext
password), `/tmp` (every upload in progress and PHP's spool of large
request bodies, Private files included before they are sealed), the system
journal, `mail.log` and syslog (addresses and IPs), rspamd's log (message
ids, senders, recipients, attachment names), Postgres's own log (failing SQL
text, 575 MB a day on dev), the DKIM private keys of every hosted domain,
and the blocks that `one_data_root`'s move freed (3 GiB of dev's database,
mail and uploads, until overwritten) plus every Linode backup taken before
the move. D10 deals with all of it.

**What the customer wording says, from this.** "Your data is encrypted at
rest" is true of everything above except what leaves the machine, and only
once D9 and D10 have landed; before that it is not said. The node and site
pages (WP4) list what stays on the root disk and why.

## The design

### D1. The data root is a LUKS2 volume

`one_data_root` makes the data root a filesystem of its own, in a fully
allocated file or on a device, mounted at `/srv/joinery`, with every consumer
ordered after `joinery-data.target`. This spec puts LUKS2 underneath that
filesystem. Nothing above the mount point changes: Postgres, Docker, the site
directories, the mail stack and the quota work see the same paths.

**Standard configuration — one for every node, whatever its size.**
`cryptsetup luksFormat --type luks2 --cipher aes-xts-plain64 --key-size 512`
(a 512-bit volume key: two 256-bit AES keys for XTS), and every keyslot we
make `--pbkdf pbkdf2 --hash sha256 --pbkdf-force-iterations 1000000` (T-V11:
the 512-bit key takes two PBKDF2 blocks, so 2,000,000 took 1.5-2.5 s idle on
a 1 GB Linode; owner 2026-10-12: lower it to about one second). A slot an
earlier release made at 2,000,000 stays one of ours: D1's check accepts the
counts a release has made, `LUKS_ITERATIONS_MADE` = 1,000,000 and 2,000,000.
Nothing else is
tuned: no `--perf-no_read_workqueue` / `--perf-no_write_workqueue` (measured
2026-10-07: no gain on 1 GB or 4 GB nodes, and worse on some random-write
runs).

**Why PBKDF2, and why about one second** (amends D-E; owner, 2026-10-11). A
slow, memory-hard derivation (argon2id) exists to make guessing a human
passphrase expensive. No slot here ever holds one (D-H): the escrow slot,
the TPM2 token and the temporary slot of a re-encryption (D8) each hold a
random secret of 256 bits or more, which no derivation cost makes harder to
guess. Argon2id's memory cost, on the other hand, runs whenever a slot is
added or used: at enrolment, at the temporary slot of a re-encryption, and
at each slot a re-encryption mints, all while Postgres, Docker and the web
stack are up. On a 1 GB node that is an out-of-memory kill waiting to happen
(reproduced at unlock, appendix). PBKDF2 needs no memory at any cost, so
that risk is gone whatever the count.

The count is not the minimum (1,000), on the owner's word: a slot whose
secret turned out weaker than intended (a bug in how one was made, or a
passphrase someone added by hand) should still cost a guesser real time. Two
million iterations of PBKDF2-SHA256 is about one second on dev's CPU
(`cryptsetup benchmark`, 2026-10-11: 1.97 million iterations a second, AMD
EPYC 7542), against about two for argon2id at 256 MiB, and costs only CPU.
WP1 measures it on a 1 GB node with the stack running and settles the
number before anything ships; after that it is fixed, written into every
header the same, and never left to cryptsetup's per-machine benchmark, so a
header made on a big machine opens as fast on a small one. Each open names
its slot (`--key-slot`, or the TPM2 token), so the second is paid once, not
once per slot tried.

The TPM2 slot is the one exception: `systemd-cryptenroll` always gives its
slots the minimum, and the chip itself is what guards that secret. `status`
reports each slot's derivation, and `check` fails any slot that is neither
ours at the standard count nor systemd's TPM2 token slot. The recovery key is not a slot at all (D2), so nothing here
applies to it; the argon2id that once guarded a passphrase has nothing left
to guard.

`joinery_data_root.sh create` grows an `--encrypted` option that does this
before formatting XFS; `check`, `grow` and `status` look through the mapping
(D1b). `--sector-size 4096` stays (WP1, 2026-10-11: formats, opens, grows and
survives reboots on file and device backings; its speed is measured on
Linode with WP3). **`--allow-discards` is dropped** (WP1): a discard through
the mapping reaches a file-backed volume's loop device, which punches a hole
in the image and hands that space back to the root disk, so the data root
would promise space the disk may no longer have. The same was already
happening to every unencrypted data root: Ubuntu's weekly `fstrim.timer`
lists `/srv/joinery` from fstab and trims it through the loop device. Fixed
in `joinery_data_root.sh` 1.4 for every machine: the loop device passes no
discard (the direct-I/O unit at boot, the tick at once), and the tick
allocates again any space a discard already took back.

### D1a. One layer of encryption, never two

A machine has exactly one at-rest layer: **ours** when its data root is an
encrypted volume of this spec, **the provider's default** when it is not.
The provider's layer is held under keys the provider controls, is readable
in rescue mode, and does not cover backups, so under our volume it protects
nothing the volume does not. It was measured to cost nothing noticeable
(appendix), so this is not about speed: it is about not keeping a layer with
no purpose, and one fewer thing to explain to a customer.

On Linode the layer ("Local Disk Encryption") is on by default and is fixed
when the instance is created; only a rebuild, which wipes the disks, changes
it. So the choice is made before the machine exists, and checked after.

**Decided once, on the provision row.** `cvp_data_root_encrypted` is set
when the provision is created, by each place that creates one: a hosting
order, a buyer's pre-payment draft (`ManagedSiteDraft`, carried into the
order row at fulfilment), the admin Install New Node form's cloud target,
and a site copy onto a new machine. An adopted server (`CloudServerAdoption`)
never passes `handle_ready()`, so there the field means unknown and is read
from the machine. That one field drives both halves, so they cannot
disagree:

- `ProvisionCustomerCloud::handle_ready()` — the only place a node that
  gets a data root is created — passes `disk_encryption => 'disabled'` to
  `createInstance()` when the field is set, and passes nothing when it is
  not (the provider's default stays).
- The install job reads the same field to run `joinery_data_root.sh create
  --encrypted`.

**The driver.** `CloudComputeProvider::createInstance()` takes an optional
`disk_encryption` (`'enabled'`, `'disabled'`, absent = the provider's
default). `LinodeComputeDriver` sends it as Linode's `disk_encryption`, and
`normalize()` reports the instance's own `disk_encryption` back, so every
instance array says which layer the provider holds. A driver whose provider
cannot turn its layer off throws on `'disabled'` rather than stacking
silently, the rule the interface already states for `rebuildInstance()` on a
provider that cannot rebuild in place. Whether stacking is acceptable on such a
provider is decided when such a driver is written. `handle_ready()` saves
`cvp_instance_id` before any check that can fail after the create, because
the failure path and `release_credentials_if_no_instance()` decide by that
column.

**Checked at birth.** `handle_ready()` reads `disk_encryption` from the
create call's own answer, which is already normalized, and `booting` reads
it again from `getInstance()`. An encrypted provision whose instance reports
the provider's layer **on** fails before the install starts, with the reason
in `cvp_error`. Like every other failed provision, the instance is kept for
the operator to delete: the platform never deletes a customer or production
instance (`ProvisionCustomerCloud::alert_and_fail()`,
`SiteCopyRunner::discard()`). An answer with no `disk_encryption`, or a
null one, is **unknown**, not on: the provision proceeds and the node page
says the provider layer is unknown. The install never builds our volume on
top of a layer the provider reports.

**Regions.** Linode's layer is a region capability ("Disk Encryption"), on by
default only where the region offers it, and always on in distributed (edge)
regions, where it cannot be turned off. `regions()` carries each region's
`site_type` and capabilities, the way `regionSupportsMetadata()` already reads
them; today `regions()` returns id and label only, and no `site_type` is read
anywhere, so this is built in WP3. An encrypted provision offers and accepts
only `core` regions, and
`handle_ready()` refuses a distributed one before creating anything. It sends
`disk_encryption` only where the region lists the capability; in a core
region without it, it sends nothing, and the provider's layer is off.

**Moves on an existing machine.** `encrypt` in place (D5) adds
our layer; on a machine whose provider layer is on, that is two layers.
Before it sends the job, the management node asks the provider for the
machine's `disk_encryption`:

- **off** — the move runs in place.
- **on** — the move runs in place only when the operator ticks "keep the
  provider's layer as well", and the node page then says the machine has two
  layers until it is replaced. This exists for dev and getjoinery, the two
  management nodes that predate this spec (D-L, D7), and is offered on a
  management node only (`site_copy_final_test.md` E2); every machine born
  after WP3 has the provider's layer off and never meets it. Without the
  tick, the move is refused and points at a site copy onto a new machine.
- **unknown** (bare metal, a provider we have no driver for, a node with no
  instance on record) — the move runs; the node page says the provider
  layer is unknown.

Turning our encryption **off** in place is refused on a machine whose
provider layer is off, because it would leave the data with no at-rest
layer at all: that machine goes back by a site copy onto a new machine with
the provider's default.

**Relays** have no data root. Their births and rebuilds
(`RelayCloudProvisioner`) pass no `disk_encryption`, and keep the provider's
default.

### D1b. How the volume is wired at boot

The unencrypted data root of `one_data_root` is an fstab line naming the
image with the `loop` option, mounted at local-fs time, with
`joinery-data-dio.service` switching the loop device to direct I/O. Under
LUKS that shape would mount before anything has unlocked, so it changes:

- **No fstab line.** A native `srv-joinery.mount` unit mounts
  `/dev/mapper/joinery-data` with `prjquota`, `Requires=` and `After=`
  `joinery-data-unlock.service`, and is part of `joinery-data.target`, never
  of `local-fs.target`. Boot does not wait on it.
- **No ordering cycle.** A mount unit gets `Before=local-fs.target` by
  default (traced on dev's bind units; today's `srv-joinery.mount` escapes
  only through fstab's `nofail`), and a service with default dependencies is
  after `sysinit.target`, which is after `local-fs.target`. So the unlock
  service, waiting on the network, would sit behind mounts that wait on it,
  and systemd would break the cycle by dropping a job of its own choosing.
  Every mount unit the data root owns (`srv-joinery.mount`, every bind
  `joinery_data_root.sh` writes, D9's `config/` bind, D10's `/var/log` and
  session binds) therefore carries `DefaultDependencies=no` with
  `Conflicts=umount.target` and `Before=umount.target`, and none is ordered
  before `local-fs.target`. The unlock service has `Wants=` as well as
  `After=network-online.target`. WP1's tests require a boot whose journal has
  no "ordering cycle" line and a clean `systemd-analyze verify`.
- **`joinery-data-unlock.service`** is `Type=oneshot`, `RemainAfterExit=yes`,
  `TimeoutStartSec=infinity`, `After=` and `Wants=network-online.target`. On a file it
  sets up the loop device itself (`losetup --direct-io=on --find --show`,
  which absorbs `joinery-data-dio.service`'s job; where the backing
  filesystem refuses direct I/O it falls back to a loop without it and logs
  one line, as that unit's `|| true` does today), then opens the mapping
  (D3) and exits. Its console prompt passes `systemd-ask-password
  --timeout=0`, since the default gives up after 90 seconds, long before
  anyone reaches Lish. `ExecStop=` closes the mapping and detaches the loop,
  so a stop (D5's start or undo, `migrate`'s put-back) leaves nothing open. A unit that waited for a person is still waiting, not
  failed, so when the volume opens the mount and every consumer start on
  their own, with no reboot.
- **`grow`** runs unattended from the converger's tick (`tick_upkeep`, through
  `plan_growth`), so it cannot ask for a key. `cryptsetup resize` on a mapping
  opened with `--disable-keyring` does ask for a keyslot's passphrase and takes
  no volume key (`cryptsetup-resize(8)`), and on an escrow machine the slot's
  secret is not on the machine. So `grow` does what `cryptsetup resize` does
  inside, itself: extend the image (`losetup -c` on a file), read the live
  table with `dmsetup table --showkeys` (the read D8 relies on), and
  `dmsetup suspend`, `reload` with the new sector count, `resume`; then
  `xfs_growfs`. The key goes from the table to the reload through a pipe,
  never to disk.
- **The config** records the backing as `luks-file` or `luks-device`, beside
  today's `file` and `device`, so `check` and `status` know to look through the
  mapping.
- **The converger runs while the machine is locked, and logs to the
  journal.** Its unit appends to a log file in the site's `logs/` (or
  `/var/log/joinery/` in machine mode), both on the data root; the root-disk
  mountpoint under a bind is mode 000 and immutable, so while locked systemd
  would fail the unit before it starts, every minute, and the host report
  would raise a failed-unit incident beside the locked one. So the
  converger's output goes to the journal (`StandardOutput=journal`), which
  reaches `/var/log/journal` on the data root once it is flushed there (D10).
  While locked, the tick does only what needs no data root and reports the
  machine as locked; its installers already wait on the data root
  (`_plugin_installers_start.sh`, "data-root-not-ready"), and the trust-file
  writer that would warn each tick about an unwritable `config/` is silenced
  the same way.

- **The mapping's device unit waits as long as the unlock.** The mount binds
  to `/dev/mapper/joinery-data`'s device unit, which systemd gives 90 seconds
  to appear before failing the mount and everything behind it; a drop-in sets
  its `JobRunningTimeoutSec=infinity`.
- **Shutdown: after `blk-availability.service`.** lvm2's
  `blk-availability.service` runs `blkdeactivate -u` early in shutdown, which
  unmounts every filesystem on a device-mapper device while the services
  using it are still stopping (seen on the WP1 VM: the data root unmounted
  under Apache). The mount and the unlock unit are ordered after it, so at
  shutdown the consumers stop, then the binds, the mount and the mapping, and
  only then `blkdeactivate` runs. Proven with Postgres on the volume: a clean
  shutdown and a start with no recovery.
- **While locked, `multi-user.target` is not reached** (its consumers are
  waiting), so `systemctl is-system-running` says `starting`, and what is
  ordered after it (`cloud-final`, utmp's runlevel record) waits too. The
  agent daemon does not (WP2).

WP1 proves the wiring: a boot with the holder down leaves the machine up and
its consumers waiting, with no failed unit and no ordering cycle, and the
unlock then brings the whole stack up.

### D2. One volume, several keys: which slots are cut for which install

The volume's own key is random, 512 bits, generated at `create`, and is never
written to the machine's disks. What is enrolled are the ways to reach it:

| Install | Slot: normal boot | Break-glass |
|---|---|---|
| Hosted (our Linode, any shape) | **escrow** — held by the management node | the volume key sealed to the operator's recovery key (ours) |
| Self-hosted, managed by us | **escrow** — held by the management node | the volume key sealed to the site owner's recovery key |
| Self-hosted, unmanaged | **TPM2** where the hardware has one, else the site owner's recovery key on the unlock page or at the console on every boot (D2a, D2b) | the volume key sealed to the site owner's recovery key |
| Off | — | — |

Escrow and TPM2 are LUKS2 key slots. No install has a passphrase slot (D2a).
**The break-glass copy is not a slot.** It is the volume key sealed to a
recovery public key, with the same sealed-box arithmetic a backup's envelope
key uses (`docs/backups.md`, `backup_envelope.php`), written as a sidecar
beside the volume (`/srv/joinery.img.recovery`, or beside the device's
mapping). Opening it by hand is `backup_envelope.php open --private
recovery.key`, then `cryptsetup open --disable-keyring --volume-key-file
--key-size 512` (the size must be given when no slot is used). The owner
holds the one recovery key they already hold, and the volume never contains
anything derived from it.

**The recipient is a file of its own,**
`/etc/joinery/data-root/recovery_recipient` on the root disk: the public key
and its fingerprint, which the node page shows. It is never written into
`backup_recovery_public_key`, which only the site itself sets
(`docs/backups.md`, "Only this site ever sets this site's key").

- **Hosted machines and multi-tenant hosts:** the recipient is the operator's
  key, the management node's own proven recovery public key. The plane passes
  it to the install job as a parameter (it is public), D8's push replaces it,
  and `create --encrypted` refuses to run without one.
- **Self-hosted machines, managed or not:** the recipient is the site's own
  proven `backup_recovery_public_key`, which the converger copies into the
  file. A new self-hosted site has no recovery key until its owner sets one
  up on the Backups page, after the site exists. So a self-hosted machine is
  **born unencrypted** and is encrypted once its key is proven: the Backups
  page then offers **Encrypt the data root**, which runs `encrypt` (D5),
  usually while the data root is still small. An owner who already
  holds a recovery key passes `--recovery-public-key` to the installer and is
  born encrypted.

So no volume is ever born without a sidecar. For a hosted machine "the
owner" is the operator. The sealed copies are per machine; only the
recipient is shared.

**What building it found (2026-10-11): the TPM2 chip is asked again, and
its slot sealed again after a boot it did not open.** On the test VM the chip
refused its slot at two boots of about ten with `TPM_RC_PCR_CHANGED` (0x128):
another process at boot extended a PCR between the policy check and the
unseal, a race, not a changed measurement. So the unlock asks the chip up to
five times, two seconds apart, before escrow or the recovery key. And when the
chip still does not open a boot and something else does (escrow, the recovery
key), the unlock records it (`/run/joinery/data-root.tpm2-failed`) and the
data root's tick seals the chip's slot again to this boot's measurements,
through a slot made for the moment from the open volume's key and removed
after: harmless after a race, and what brings the chip back after a real
change (a firmware or shim update moves PCR 7). Whoever opened this boot
vouched for it, so the chip's slot trusts what they trusted. Every enrolment
prunes the tokens whose slots are gone (a re-encryption under a new volume
key, D8, leaves them), so the chip is never asked for a slot that is not
there. Proven on the VM: three boots in a row opened by the chip after the
re-seal and a rekey.

**What a TPM2 slot does not protect (B43, 2026-10-15).** A chip's slot opens
the volume for whoever boots the machine as it is: there is no PIN and, by
D2a, no passphrase slot. It keeps a disk taken out of the machine locked, not
the machine taken whole. So the chip is the owner's choice where there is one:
`create --encrypted --no-tpm2` and `encrypt --no-tpm2` never enrol it, and
`joinery_data_root.sh tpm2 off` takes its slot away later. The choice is
recorded (`tpm2=off`) and every enrolment follows it (create, an encryption's
and a rekey's finish, the re-seal above). A machine that chooses so asks for
its key at every boot, from its escrow holder where it has one, else the
recovery key at the console or on its unlock page. The Backups page says what
the chip protects and offers only the stricter way (a root request carries
`tpm2: off` or nothing, `data_root_tpm2_off` takes the slot away); letting
the chip back in is root's own command (`tpm2 on`), since the request queue is
the web user's to write. The default stays: a chip that is there is enrolled.

### D2a. A person opens a machine with the recovery key

Wherever a machine has to be opened by a person — an unmanaged machine
without TPM2, a management node whose escrow holder is also down (O4), any
machine whose management node does not answer — what is asked for is the
**recovery key**, not a passphrase. It is asked for in two places: the
machine's unlock page in the browser (D2b), which is the normal one, and its
console, which is the fallback for a machine whose network is broken. Both
take the same key and do the same thing with it. The recovery key is one line of 44 base64 characters
(`docs/backups.md`, Recovery key setup) and lives in the owner's password
manager already, so it is pasted into the console (Lish, a cloud serial
console, a local keyboard) as easily as a passphrase would be.

The unlock helper prompts with `systemd-ask-password` ("Recovery key for
<site>"), opens the break-glass sidecar with what was pasted, and opens the
volume with `cryptsetup open --disable-keyring --volume-key-file
--key-size 512`, the key held only in memory (D3). A key that does not open
the sidecar is asked for again. The helper is the agent binary in a new
one-shot unlock mode (O5), on the root disk, so it needs nothing from the
data root or the site tree; it is installed on every encrypted machine,
including an unmanaged one with no management node configured.

**What exists and what is new.** Only the arithmetic carries over: the
agent's `openRecoverySealed` (`copy_take_key.go`) and the browser's
`assets/js/copy-key.js`. Everything else is new: the one-shot mode (the agent
CLI has only version, join, status, leave, enable and disable), an https
listener (the agent has none), reading certificates from `/etc/letsencrypt`,
a copy of Cloudflare's address ranges on the root disk (today's list is a
PHP-side file in the site tree), rate limiting, and the console prompt. The
`/copy-key` handoff itself (two settings rows in the site's Postgres and a
PHP view) cannot be used, because none of it runs while the volume is locked.

**The console prompt** runs after networking, well past early boot. systemd's
console password agent does not survive that on its own (found on the WP1
VM, 2026-10-11): `systemd-ask-password-wall.service` stops it first thing
(`ExecStartPre=systemctl stop systemd-ask-password-console.*`), and the wall
agent starts at the first password request once user sessions are allowed,
which for a disk opened in early boot is never and for this one is at once.
So on an encrypted machine the wall agent and the console logins
(`getty@`, `serial-getty@`) are ordered after the unlock, as they are behind a
disk opened in early boot; ssh is not. The prompt then shows on every active
console, the kernel's serial console included (`console=ttyS0`, on Linode's
default kernel line) for Lish. Proven on the VM: a wrong key refused and
asked again with the reason, the right key opening the volume and the stack
starting with no reboot.

So the break-glass path, the unlock page and the console are one path, and
nothing is added to what the owner keeps. A passphrase slot is never
enrolled.

**What it costs.** The recovery key is entered on that machine, so a machine
already in an attacker's hands at that moment could capture it.

- On a management node this adds little: the plane already holds every
  machine's escrow secret. It also happens only when its escrow holder is
  down at the same time (O4).
- On an unmanaged machine without TPM2 the key is pasted at every boot, not
  only in an emergency. The install says so when it offers encryption on such
  a machine.
- On a hosted machine the break-glass recipient is the operator's key (D2),
  which opens every hosted machine's sidecar. It is typed into a hosted
  machine only when that machine reboots while its management node is down,
  or when the management node refuses it (Tier 2).
  That machine sees the one key that opens them all. Accepted: the
  alternative is a per-machine human-held secret, which D-A rules out.
  After such an unlock, the operator rotates the recovery key (D8).
  **The procedure that keeps this rare (D2b):** while the management node
  answers, the operator never types the key into a hosted machine's own page
  or console; they unlock it from the management node's page, so the key
  never reaches the machine. A machine that shows its own unlock page while
  its management node is up is treated as a machine that may be lying.

### D2b. The unlock page

A locked machine is not dark. Its operating system is up and the unlock
unit is running, and so is the agent daemon (D4); the services that wait for
the data root are not. So until the volume
opens, the unlock helper (D2a) answers on the machine's own addresses, ports
80 and 443, with one page: *"This server is waiting to be unlocked"*, and a
box for the recovery key. It uses the site's own certificate, which is on the
root disk, so the page is ordinary https at the site's own name and works
through the Cloudflare proxy. The listener is part of
`joinery-data-unlock.service` itself, a oneshot that exits once the volume
opens; the web server is ordered after `joinery-data.target`, so the ports
are free before it starts, with no separate stop step.

The page works like `/copy-key`: the browser takes the X25519 of the pasted
key with the ephemeral key of the sidecar's sealed box and sends only that
value, which opens that one box. The recovery key itself is not sent.
The arithmetic is `copy-key.js` and the agent's `openRecoverySealed`
(`copy_take_key.go`) as they stand. Attempts are rate-limited by the
caller's address: `CF-Connecting-IP` only when the connection comes from a
Cloudflare range, otherwise the connection's own address. The page shows
nothing about the machine beyond its name and that it is locked.

**Its limit, stated plainly.** The page and its script are served by the
locked machine. A machine already in an attacker's hands could serve a
script that sends the whole key. So the page carries the same risk as the
console (D2a), and no more; it is easier, not safer.

**Whenever the escrow holder is up, the unlock is the holder's, not the
machine's.** A machine in an attacker's hands (rescued, say) can skip asking
its holder and show its own page as if the holder were down, and serve a
script that sends the whole recovery key; on a hosted machine that key opens
every hosted sidecar. So the management node's node page offers the unlock
for any of its locked machines, and the browser does the arithmetic on the
management node's page, from the machine's sealed box. **What this protects
is the recovery key, and only that.** It does not protect the machine's
volume key: unlocking hands a machine its own key, which is what unlocking
is, so a rescued machine unlocked this way is a rescued machine with its data
open.

So the unlock is a judgment, and the page gives the evidence for it: why the
machine is locked (the holder's refusal and its reason, or no request at
all), the last provider event once Tier 2 reads them, the address the
machine is asking from and whether it is enrolled, and the time since
enrolment. Under Tier 1 a genuine machine whose holder is up never needs this
page; it exists for a machine rebooted before enrolment and one whose slot is
gone. Under Tier 2 it is also where a refusal is answered, in the two ways
D3 allows after a rescue boot or a root-password reset, never by a plain
override. The machine reports each
sidecar's box to its management node and escrow holder, at enrolment and
after every re-seal (D8); a box that differs from the record is shown as a
warning that something changed. The machine's own page is for when the holder
itself is down.

**Being told.** A machine waiting at its unlock page must read as **locked**,
not as up and not as an ordinary outage. Today the two uptime paths disagree:
`NodeHealthProbe::http` reads any status outside 200-399 as down (so a 503
would open a "site down" incident with the wrong link), while
`RunNodeUptimeChecks`'s API check reads any answer as up. So the helper
answers every request with status 503, `Retry-After`, and a header
`X-Joinery-Locked: 1`, and **both** paths learn that header as a state of its
own, **locked**, which opens a locked incident. The incident links to the
management node's unlock while the management node is up. It links to the
machine's own page only when the holder is the thing that is down: the
management node for its nodes, and dev's uptime row for getjoinery.

**A multi-tenant host** has no site name of its own, and its web server
waits on the data root, so nothing else answers its containers' names while
it is locked. The helper serves the page at every name the host carries,
with the host's own certificates (`/etc/letsencrypt`, on the root disk), so
no one is taught to click through a certificate warning. It never answers
with the host's self-signed placeholder certificate.

### D3. The escrow slot

The one key source this platform adds. The escrow holder, normally the
machine's management node, keeps one random escrow secret per machine,
sealed under its `SecretBox` like every other secret in its database.

**The unlock identity.** The machine's agent key cannot be what the holder
checks: the agent mints a new keypair at every join (`join.go`), a leave
clears the key on the plane's row (`forgetAgent()`), and the agent's
identity file names one plane. So `create` mints a separate **unlock
identity** on the root disk: an Ed25519 keypair used for nothing else,
untouched by any leave or join. The holder records its public key beside the
escrow secret, in an escrow table of its own, keyed by that identity and
not by any `mgn_managed_nodes` row. The boot request is signed with it and
checked against that table alone.

**The address check (Tier 1).** The unlock identity is on the plain root
disk, so every copy of the disk carries it. The holder therefore also keeps
the addresses each machine may ask from, and refuses a boot request from
any other, logging the address it came from. So a backup restored onto a new
machine, a clone, or an image booted on another address is refused. A copy
given the machine's own address (a clone handed its IPv4 by the provider's IP
transfer, as `IpSwapMove` does), the machine itself in rescue mode, and the
machine after a root-password reset all ask from an enrolled address; those
are Tier 2's.

- **The machine reports its addresses to the holder itself,** in a request
  signed with the unlock identity to the holder's escrow endpoint, at
  enrolment and whenever its addresses change (an IP swap, a new address
  behind NAT). It is never inferred from agent traffic, which goes to the
  agent's plane: for the two management nodes the holder is the other one,
  and would otherwise never hear of a change.
- **A report proves the volume is open** (found while building WP2,
  2026-10-11). The identity is on the plain root disk, so a copy of the disk
  could sign a report of its own new address and then ask from it. So at
  enrolment, while its volume is open, the machine also hands the holder a
  **report key**, HMAC-SHA256 of the volume key under a fixed label, and
  every report (an address, unlocked, shut down, a new box) and the
  enrolment's confirmation carry an HMAC of the request under it. A running
  machine can make one; a copy of its disk, whose volume is ciphertext,
  cannot. An IP swap still works (the machine reports from its new address,
  T-E6); a copy elsewhere can neither move the address check nor reset the
  one-answer rule. A re-encryption changes the volume key and so the report
  key; the re-enrolment D8 already makes before it is done carries the new
  one.
- **It states both families.** The report names the machine's own IPv4 and
  IPv6; the holder records the stated set plus the address the report came
  from. A dual-stack machine whose earlier requests came over IPv4 may boot
  and ask over IPv6, and is answered. Addresses are compared as addresses
  (`inet_pton`), not text.
- **Behind Cloudflare** the holder reads the caller's address the way the
  platform does: `CF-Connecting-IP` only when the connection comes from a
  Cloudflare range (`includes/cloudflare_ip_ranges.txt`), otherwise the
  connection's own address. Every request to getjoinery.com arrives through
  the proxy.

**One answer per boot** (settles O3). The holder records when it answered an
identity, and refuses another request for that identity for 15 minutes
unless the machine has since reported itself unlocked or shut down. A second
boot that arrives while the first is still answered (a copy started beside
the original) is refused and shown on the holder's page. Replay needs no
rule of its own: the request travels over TLS.

**An answer that never lands** (the response is lost, or `cryptsetup open`
fails after it) leaves the machine refused for those 15 minutes while its
holder is up, and its unlock page shows. Accepted. The holder's page says
"answered at 14:02, no unlock reported since" so nobody reads it as a refusal,
the machine's own retries say the same in its journal, and after 15 minutes
the next request is answered.

**Enrolment comes after the join.** On a new machine `create --encrypted`
runs inside the install, before any agent has joined, so there is no channel
to the holder yet. The volume is born with its break-glass sidecar only.
Once the agent has joined, an agent job enrols the escrow slot: the holder
mints the secret, sends it once, and records the unlock identity's public
key, the sidecar's box and the address it sees. The machine adds the slot
with `luksAddKey --volume-key-file`, reading the volume key from the open
mapping the way D8's re-seal does. The secret never rides the install executor's
command line or job log. A reboot between `create` and enrolment, minutes
on a hosted birth, is opened by the recovery key.

At boot, `joinery-data-unlock.service`, ordered after networking and before
the mapping, runs the agent binary's one-shot unlock mode. It asks the
holder for the escrow secret and opens the volume itself with `cryptsetup
open --disable-keyring --key-slot <escrow slot> --key-file`, the secret held
only in memory. The
volume opens; `joinery-data.target` is reached; the consumers start. Every
open, by escrow, TPM2 or recovery key, uses `--disable-keyring`, for the
re-seal (D8). A TPM2 slot, enrolled with `systemd-cryptenroll`, is opened the
same way: `cryptsetup open --disable-keyring --token-only` with systemd's
TPM2 token plugin (`libcryptsetup-token-systemd-tpm2.so`). It is never opened
through a `crypttab` line, because `crypttab` has no keyring-off option and
would put the key where the re-seal cannot read it.

A machine whose management node does not answer within the timeout (O2)
offers its unlock page (D2b) and the console prompt for the recovery key
(D2a), both at once — the break-glass path, which needs no login. It keeps
asking the management node meanwhile, so a plane that comes back unlocks it
with no person involved.

**The escrow holder can be a management node other than the machine's own.**
The boot request goes to the holder's URL recorded beside the unlock
identity on the root disk (a name such as `https://getjoinery.com`, never an
IP address, so a holder that moves machines is still found), not
to the agent's plane. Normally that holder is
its management node. For the two management nodes it is the other one (O4).
Because the holder checks only its escrow table, it answers a machine that
is not otherwise its node, and a re-pair changes nothing.

**Tier 1** is D1–D3 as written: the holder answers every authenticated
request from an enrolled address.

**Tier 2** adds the refusal (owner, 2026-10-11: built straight after launch,
D-C). Before it answers a boot request, the holder reads the provider's
activity log for the machine's instance and refuses if, since the machine
last reported itself unlocked, any of these happened to it: a rescue boot, a
root-password reset, a clone, a backup restored onto it, a rebuild, or its
address moved to another instance; or if the instance asking is not the one
enrolled. It then waits a few seconds and reads the log again before
answering, so an event the log records a moment late is still seen; every
boot is that much slower. A refused machine shows on the holder's page with
the event that refused it. **After a rescue boot or a root-password reset,
nothing on that machine's root disk can be trusted** (its code, its agent,
the unlock helper itself), and nothing running on it can vouch for it,
because the thing that would check is on the same disk. So the page does not
offer a plain override for those events. It offers two ways on: the owner
states that they caused the event themselves (a rescue boot they ran), which
the page records with their name; or the site is recovered by a copy from
its backups onto a new machine, and the old one is retired. For the other
events (a clone, a restore onto the machine, an address moved) the same two
ways apply. The rotation spec rests on the same fact: a thief with rescue
access can write to a management node's plain root disk
(`release_key_rotation`, K3 there). This closes the third row of
the table: a stolen hosting login that resets the root password, rescue-boots
the machine, or boots a copy at its address. It is a policy on the escrow
endpoint and nothing else: no change on the machine. Nothing in the platform
reads a provider's activity log today, so the provider-events capability is
built whole (WP5).

**What building Tier 2 found (2026-10-11, probed in the test account).**
- **A rescue boot has no event of its own.** Linode logs it as a
  `linode_reboot` (or `linode_boot`) that names no configuration profile.
  Every other boot or reboot names the profile it booted, whether or not one
  was asked for. That missing profile is how `EscrowTier2` recognises a rescue
  boot. `password_reset` is logged under its own name.
- **Linode refuses an `entity.id` filter without an `entity.type`.** So the
  driver reads the instance's own events (`linode`), its disks' (`disks`) and
  its backups' (`backups`) as three queries and merges them by time.
- **What refuses:** `password_reset`, `linode_rebuild`, `linode_clone`,
  `disk_imagize`, `disk_duplicate`, `backups_restore`, `linode_deleteip`, and a
  boot naming no profile. A request from an address that belongs to another
  instance, or to none in the account, also refuses: that is the clone or
  moved address that is asking. A failed event does not count.
- **What is answered:** a boot or reboot with its profile, `host_reboot`,
  `lassie_reboot`, migrations and resizes.
- **The window** runs from the latest of: the last unlock reported, the
  enrolment, and the owner's excuse.
- **A log that cannot be read refuses.** The machine asks again every ten
  seconds, so an outage at the provider delays an unlock and never skips the
  check.
- **A key that stops working refuses too (B77, 2026-10-15).** Found on the
  0.8.499 round: the rig's 48-hour key expired, the account stayed active,
  and node-n's reboot waited 12 minutes with nothing saying why ahead of time.
  Worse, the code then treated an account that "cannot be used now" as Tier 1:
  marked refused (the daily transfer watch marks a 401), it answered boots
  unchecked, so a stolen hosting login could revoke our key, wait a day,
  rescue-boot the machine and plant code the next boot would be handed the key
  by. Now: a machine in one of our accounts held by a lasting key with the
  events scope stays Tier 2 whatever that key's state; refused, expired or
  unreadable, its boots are refused with what to do. A 401 marks the account
  refused. Each API key's own expiry is read at every check (Linode
  `profile/tokens`, readable with any scope) and checked daily; the Hosting
  tab shows it, and `plane:cloud_account_key` is critical on each covered
  machine when the key does not work, a warning within a week of its expiry.
  Tier 1 by design: the owner's own account, a two-hour sign-in, a key that
  never had the events scope, an account removed from the management node.
- **The owner's word ("I did this myself")** is superadmin-only. It is
  recorded with the user's name and excuses every event up to then. The
  preflight before a planned reboot runs the same check, so a reboot that
  would be refused is held.
- **Proven:** the test-account probe's real log classified as above, through
  the driver (a plain reboot answered, the rescue boot and the password reset
  refused); `escrow_tier2_test.php` 29/29 through the endpoint with a fake
  provider. The live tests (T-A3, T-A4) are for the Linode phase.

**Tier 2 needs a lasting read credential for the account each machine lives
in.** A Linode sign-in (Linode Connect) lasts two hours, so it cannot serve:

| Machines | Tier 2 |
|---|---|
| In Joinery's live account: getjoinery's fleet and every machine we host | Yes: getjoinery's API key for that account, with events read access |
| getjoinery itself, held by dev | Yes: dev is given an **events-only, read-only** token for the live account. It can read the activity log and nothing else; it is the one live credential dev holds, and it changes no server |
| jeremytunnell-vps, in the owner's personal account | Yes, once the owner adds an API key for that account (events read access) |
| In a customer's own account, reached through Linode Connect | **No.** These stay at Tier 1, and their node page and the customer's page say so |

### D4. Boot, with root login off

1. The OS boots from the plain disk.
2. `joinery-data-unlock.service` starts once the network is online (D1b).
   It is the agent binary in its one-shot unlock mode. The agent daemon is a
   separate unit, and starts beside it: `joinery-agent.service` drops its
   `After=`/`Wants=postgresql.service` (it already tolerates a database that
   is not there), so a locked machine is still reachable by its plane and
   reports itself locked.
3. The unlock unit obtains the key: escrow from the holder, or the TPM2
   slot, or the recovery key given on the unlock page (D2b) or at the console
   (D2a).
4. The mapping opens; the XFS mounts; `joinery-data.target` is reached.
5. Postgres, Docker, the web stack, the mail stack start.

Until step 4 the machine serves nothing. On a multi-tenant host that is the
"host starting" state the suspended page already has a slot for; on a bare
metal site the web server is not up yet, and the unlock page answers in its
place. No step is a login: the unlock unit is a root service, the console
prompt is systemd's, before any login exists,
and creating or migrating a volume is root work done the way root work is
done now — a job the agent runs, or `sudo` from the operator's own account.

**Reboots.** Unattended reboots are off on sites (`install.sh` sets
`Unattended-Upgrade::Automatic-Reboot "false"`) but on for multi-tenant hosts
(`multi_tenant_host.sh`: `"true"`, at `"now"` after the 05:30 UTC run), so
every host would reboot in the same minutes on the night a kernel lands, all
asking the holder at once, and all sit locked if the holder is down then. On
an encrypted host, unattended-upgrades' own reboot is turned off. The host
converger reboots instead, when `/var/run/reboot-required` exists, only after
the holder answers a preflight request, and at a minute derived from the
host's id across a window, so hosts never reboot together and never while
their holder is known to be down. `multi_tenant_host.sh` refuses to install,
and reports a misconfiguration, unless apt's own reboot is on (its install
check and `updates_reboot()`); both learn the converger-owned reboot as the
encrypted host's correct setting.

The preflight proves the holder was up a minute before, not at the reboot:
a holder that fails in that minute leaves the host at its unlock page until
the holder returns, the same state as any reboot. A holder down for days
means `reboot-required` waits and the old kernel keeps running; the node page
says so, so a long holder outage is seen, not silent.

### D5. Existing machines: encrypted where they stand

An existing data root is encrypted **in place**, by LUKS2's own online
encryption (`joinery_data_root.sh encrypt`), not by copying it into a second
image (owner, 2026-10-11, O13). Nothing is copied, the root disk never has to
hold the data twice, the site is down for seconds, and it is the same
machinery D8 uses to change a volume key. The owner's condition: it is the
method only if it is made reliable. The test campaign T-F2 decides; if it
finds a failure that cannot be fixed, the fallback is a second image (copy,
switch, keep the old until a reboot proves the new).

It runs on our five existing machines once each (D7), and on every
self-hosted site whose owner turns encryption on (D2), unattended. So it is
built to the standard of something that runs on a customer's machine with
nobody watching.

1. **Before.** A verified offsite backup newer than a day; 32 MiB free past
   the end of the backing (a file is grown by 32 MiB, which XFS never sees,
   since XFS cannot shrink; a Block Storage volume is resized); the recovery
   recipient in place (D2). The node page shows each precondition.
2. **Keys, in memory.** A new 512-bit volume key, sealed to the recipient as
   the sidecar **before anything is written**, so the recovery key opens the
   result from its first second. And a driving slot's random secret, sealed
   to the recipient as a **pending** sidecar and otherwise held only in `/run`.
   On a machine with TPM2 the TPM2 slot is enrolled at this step as well.
3. **Start, offline, seconds.** Stop the consumers, unmount the binds and the
   data root, run `cryptsetup reencrypt --encrypt --init-only` with D1's
   settings, `--reduce-device-size 32M`, the volume key and the driving slot,
   on the loop device or volume; open the mapping (`--disable-keyring`), mount
   it, restore the binds, record the backing as `luks-file` or `luks-device`,
   start the consumers. This step is the whole of the downtime.
4. **Encrypt, online.** `joinery-data-encrypt.service` runs `cryptsetup
   reencrypt --resume-only` at idle I/O priority (`ionice -c3`, a low
   `IOWeight`), so the site keeps its share of the disk; the node page shows
   progress. cryptsetup keeps the boundary between the encrypted and the plain
   part crash-safe (its data-shift resilience mode, for this case). No planned
   reboot runs while it does (D4's converger reboot waits).
5. **A crash or reboot part-way.** A device part-way opens only through a
   keyslot. On an escrow machine there is none yet (D7), so the unlock unit
   asks for the recovery key on the unlock page or the console (D2a, D2b),
   opens the pending sidecar with it, and uses the driving secret; a TPM2 slot
   opens it hands-off. If cryptsetup reports that the header needs recovery,
   the unit runs `cryptsetup repair` first. Then the encrypt service resumes
   where it stopped.
6. **Finish.** Remove the driving slot and the pending sidecar; take a header
   backup beside the sidecar (`luksHeaderBackup`). Then move the folders D9
   and D10 add (`config/`, `/var/log`, sessions, temp directories, DKIM keys)
   into the now-encrypted data root, with the existing `migrate` mechanism,
   so no secret ever sits in a partly plain data root. Then D10's clean-up:
   `fstrim`, and the provider-backup purge.
7. **Stopping or undoing it.** `cryptsetup reencrypt --decrypt`, also online,
   takes the volume back to plain from wherever it stands. The owner can stop
   an encryption from the node page; the campaign uses it.

**What building it found (WP3, 2026-10-11, on the VM):**

- **Sectors.** Every existing data root is an XFS made with 512-byte sectors
  (`mkfs.xfs` on the image itself), and dm-crypt refuses to encrypt one in
  4096-byte sectors. `encrypt` keeps the filesystem's own sector size: 512 for
  every machine encrypted in place, 4096 for one born encrypted. T-S measures
  the difference.
- **Crashes.** A power cut part-way (and a guest frozen and then cut, which is
  harsher) recovers: the next open repairs the hotzone and the run resumes and
  finishes, data intact (pgbench rows, file checksums, `pg_amcheck`,
  `xfs_repair -n`). A SIGTERM finishes the hotzone and stops. A **SIGKILL of a
  running encryption cannot be survived in place**: the hotzone is left
  suspended, writes to the data root wait, and only a reboot (the crash path)
  recovers it. So `joinery-data-encrypt.service` is never sent SIGKILL
  (`SendSIGKILL=no`, `TimeoutStopSec=infinity`) and is never the OOM killer's
  (`OOMScoreAdjust=-1000`).
- **The run's unit is ordered after the data root, never requiring it**: the
  undo's finish stops the mount to move it off the mapping, and a `Requires=`
  stopped the unit, and the finish, half-way (found and fixed).
- **The undo's finish is crash-safe in its order**: the mapping is closed by
  the unlock unit's own stop, and from then the record and units say plain; a
  run or a boot that finds a decryption recorded over a device that is already
  plain XFS finishes the switch instead of asking for a key.
- **TPM2 is enrolled at the finish**, through the driving slot before it goes,
  not at step 2: there is no header before the start, and no slot can be
  added part-way.
- Undo uses `cryptsetup reencrypt --decrypt` with the header exported to
  `/etc/joinery/data-root/decrypt.header` on the root disk, which a device
  part-way opens with.

Turning encryption **off** is step 7 run to the end, under D1a's rule. Either
direction runs in place only where it leaves exactly one layer (D1a), the
five machines of D-L excepted.

### D6. What is claimed

Customer-facing: *"Your data is encrypted at rest. The key is held off the
machine, not by the hosting provider, so a copy of the disk — a backup, a
snapshot, a clone, a retired drive — is unreadable, whether it is read
directly or started up on another address."* With Tier 2: *"…and so is the
machine itself, started under someone else's control with the hosting
account."* That second sentence is said only of a machine Tier 2 covers (D3),
never of one in a customer's own Linode account. Without Tier 2 it is not said, because a
stolen hosting login can reset the root password or rescue-boot the machine
at its own address (the table above). Not claimed: anything
about us, or about a court order served on us; that sentence belongs to the
vault and to `release_transparency`.

### D7. Encrypting the existing machines

The five machines that exist today (getjoinery, dev, docker-prod,
joinerydemo, jeremytunnell-vps) were all born with Linode's layer on, and
Linode refuses to clone between machines with different encryption settings
(T-L6). So dev and getjoinery, the two management nodes, which never copy
themselves live, are encrypted **in place** and keep Linode's layer as well
(D-L, O8); Linode's layer drops away when each is next replaced for another
reason. The other three move by copy onto new servers born with one layer, ours
(step 7, `site_copy_production_moves.md`). All five are our own machines; every
machine born after WP3 has one layer. The two are encrypted where they stand by D5's `encrypt`, not by the move
`one_data_root` ran, which copies folders into a data root and has nothing to
do once they are all there.

**Two rules set the order.** O4: no management node holds an escrow secret
while its own disk is unencrypted. And `release_key_rotation`: until dev's
release keys are rotated, whoever holds the old ones can ship a release that
runs on getjoinery and reads every escrow secret out of it, and those secrets
would open every hosted machine's disk copies for ever after. So **no machine
enrols an escrow slot anywhere until the rotation is complete.** Until then
each encrypted machine opens only by its recovery key, so none is rebooted
without the owner at hand.

1. **Before:** WP1–WP3, WP9 and WP10 released, with every machine on that
   release (dormant until each is encrypted). getjoinery's and dev's newest backups are verified, and the owner
   has every machine's recovery key at hand. Each machine meets D5's
   preconditions (the node page shows them).
2. **Rehearse in the test account,** end to end: two throwaway management
   nodes, each encrypted in place and then holding the other's escrow, and one
   node under one of them. Nothing below runs until the rehearsal passes
   (T-M1).
3. **dev** encrypts its data root in place. The same move carries out WP9 on
   dev (`config/` under the data root, D9) and WP10 (D10). Once its post-reboot
   check has removed the old plain copy: `fstrim`, Linode Backups cancelled and
   re-enabled, any manual snapshot replaced, and a new Cloudflare token with the
   old one revoked.
4. **The release keys are rotated** (`release_key_rotation`), the new keys
   made on dev inside its encrypted `config/`.
5. **getjoinery** turns on `data_root_encryption_offered` and encrypts its
   data root in place, from its own node page, its
   sidecar sealed to getjoinery's own recovery key, which is also the
   operator's key (D2). Then the same clean-up as step 3.
6. **getjoinery and dev enrol with each other as escrow holders.**
   getjoinery's unlock identity asks dev; the request waits on dev's dashboard
   with the identity's fingerprint, the owner compares it with the one
   getjoinery's node page shows, and approves (C-3). Then the same the other
   way. A planned reboot of each, one at a time, must open hands-off from the
   other.
7. **The other three move by copy** (`site_copy_production_moves.md`; owner,
   2026-10-18: one layer by copy wherever a copy can do it): joinerydemo,
   jeremytunnell-vps and docker-prod's sites each move onto a new server born
   with our layer and Linode's off, lowest stakes first, and enrol with
   getjoinery, which mints and holds their escrow. Their old servers are
   retired at Keep and deleted at the provider. Only dev and getjoinery, which
   never copy themselves live, keep two layers until each is replaced.
8. **The launch release** removes `data_root_encryption_offered` and its
   checks and adds the installer's offer ("How it is built").

**Downtime** is D5's step 3: seconds to stop the consumers, start the
encryption and bring them back. The encryption itself then runs for hours in
the background at idle priority (dev's 19 GB data root, docker-prod's larger
one), during which no planned reboot runs. No address changes.

**Each node page says "two layers"** on these five, so the exception is
never mistaken for the rule (D1a), and the page says it ends when the
machine is replaced.

**What must be built for this, beyond WP1–WP3:**

- **C-2.** The copy tab's new-server form offers "data root encrypted", which
  sets `cvp_data_root_encrypted`. It is on by default for a copy into one of
  our accounts. Not needed for these five; it is how every later copy is born
  with one layer.
- **C-3.** An escrow holder that is not the machine's management node (D3):
  the unlock identity and escrow table, plus an **enrolment request from a
  machine that is not the holder's node**, signed by its unlock identity and
  approved by the owner on the holder's dashboard after comparing the
  identity's fingerprint. This is how getjoinery and dev hold each other's
  escrow.

### D8. Rotating the recovery key

The volume key never changes on its own. What a rotation changes is who can
reach it in an emergency, and that is the break-glass sidecar.

**Every rotation re-seals.** When the break-glass recipient changes and the
new key is **proven** (the Backups page's challenge; nothing is sealed to an
unproven key, as with backups), the machine seals a fresh sidecar to the new
key and replaces the old one atomically. The machine can do this because its
volume is open while it runs: the converger, as root, reads the volume key
from the open mapping (`dmsetup table --showkeys`) and pipes it straight into
the seal, never to disk. That works only because every open uses
`--disable-keyring` (D3): opened the default way, cryptsetup keeps the key in
the kernel keyring as a logon key, which userspace cannot read, and the
table shows only a reference. With the key in the table, any root process
can read it; the converger is root already, so this widens nothing. It runs
on the converger's next tick, and the machine then reports the new sidecar's
box to its management node and escrow holder (D2b).

- **Self-hosted:** the recipient is the site's own
  `backup_recovery_public_key`, so rotating on the site's Backups page is all
  the owner does.
- **Hosted:** the recipient is the operator's key, the management node's own.
  When it rotates, the management node sends the new public key to every
  hosted machine over the agent channel, and each one re-seals. The
  management node's Backups page shows how many have re-sealed and names the
  ones that have not.

That covers the usual reason to rotate, a lost key: from the moment of the
re-seal, the new key opens the machine and the old one is not needed.

**A leaked key also changes the volume key.** Re-sealing does not take away
what the old key can already reach. Old copies of the sidecar survive in the
provider's backups and snapshots of the root disk, beside the encrypted data
image, and the volume key inside them is the one encrypting today's disk.
So the Rotate key screen asks one question: *could someone else have seen
the old key?* On yes, after the re-seal, the machine re-encrypts its data
root under a new volume key: `cryptsetup reencrypt`, LUKS2's online
re-encryption, as an agent job. The site stays up. It is heavy disk work for
hours. It needs no header space beyond LUKS2's default 16 MiB header, since
neither the sector size nor the data offset changes. The order matters,
because re-encryption removes every keyslot it was not unlocked with, and a
device part-way through opens only through a keyslot, not a volume key:

1. The machine generates the new volume key in memory and seals it to the
   new recovery key as a **pending** sidecar. It passes the same key to
   `reencrypt --volume-key-file`; left alone, reencrypt would pick one the
   machine never sees.
2. The keyslot that drives the re-encryption is the one that opens the
   machine until it finishes. On an escrow machine that is the escrow slot,
   which survives. Anywhere else it is a temporary slot with a random secret,
   sealed to the new recovery key as a second pending sidecar, so the
   recovery key opens a machine caught part-way. The temporary slot is added
   before the re-encryption starts, because no slot can be added while one is
   pending. `reencrypt` is always given `--key-slot` naming the driving slot
   (without it, a key file is refused when more than one slot is active), and
   also `--disable-keyring` and the slot settings of D1, so the slots it mints
   carry the standard PBKDF2 count and the reloaded mapping keeps the key
   readable. On a file it runs against the loop device; a path to the image
   is refused.
3. A reboot part-way opens through that slot; a volume key alone does not
   open a device mid-re-encryption. The unlock service keeps the driving
   slot's secret in memory (the escrow secret, or the temporary slot's,
   opened from its pending sidecar with the recovery key) and runs
   `cryptsetup reencrypt --resume-only` with it. Nothing resumes on its own.
4. When it finishes, before the job reports done: the pending sidecar
   replaces the old one; a TPM2 slot is enrolled again; the temporary slot is
   removed; the new sidecar's box is reported (D2b). A TPM2 machine rebooted
   between the finish and its re-enrolment opens by the recovery key.

On a hosted fleet the management node runs these one machine at a time.

**What building it found (2026-10-11).**
- **The driving slot is always a temporary one.** The plan had the escrow
  slot drive the run on an escrow machine. But the machine does not hold the
  escrow secret once it has booted, so it cannot drive with it. So a
  temporary slot drives every rekey, sealed to the recovery key, as D5's
  encryption does. The escrow slot goes with every other slot.
- **The escrow comes back at the finish** through the holder's `rekey`
  action. It is signed by the unlock identity and MAC'd with the OLD volume
  key's report key. That key is kept on the data root during the run
  (`/srv/joinery/.joinery-rekey`). The holder stores the new report key and
  mints a NEW secret for the new slot, which is confirmed as an enrolment's
  is.
  - It is never the old secret. Whoever holds the leaked recovery key and an
    old copy of the disk can derive the old report key. If they raced the
    machine and won, they would get a secret that no slot of the machine
    holds, and the machine's own ask would fail and say so.
  - A holder that cannot be reached at the finish does not hold the run up:
    the finish completes, and the tick asks again each minute.
- **cryptsetup 2.7 refuses `--key-slot` during a change of volume key,** for
  resuming the run and for opening it part-way. The driving secret sits
  behind two slots, the old key's and the new key's, and cryptsetup must be
  free to try both. The resume and the unlock name no slot for it.
- **Proven on the VM** (file-backed, TPM2, 8 GiB). A rekey to the finish: the
  volume key changed (its table prefix), a 300 MB file's checksum held, and
  the chip opened the next boot. Then a rekey with a power cut part-way: the
  boot asked for the recovery key, opened through the driving slot, resumed,
  finished, and enrolled the chip again, with the file intact and the machine
  `running` with no failed unit.

**What a leaked key still opens, even after all of this:** backups made
before the rotation (the backup rule; `docs/backups.md`, Rotating the key),
and the provider's own disk backups taken before the re-encryption, until
they age out. Both are stated on the Rotate key screen.

### D9. Secrets under the data root

Only the data root is encrypted, and a site's `config/` sits on the plain
root disk, so a copy of that disk carries its secrets: on dev the release
signing keys (`agent_signing_key`, `release_statement_key`) and the
Cloudflare DNS token, and on every bare-metal site `backup_site_key`, the
backup ledger and the relay keys. A site inside a container keeps its tree
in the data root, so its `config/` and its `/tmp` are already covered (D10,
"What building it found"). So on an encrypted
machine `config/` moves under the data root too (owner, 2026-10-11, D-M).

**`config/` becomes a bind of the data root,** the same mechanism as a
site's uploads and logs (`joinery_data_root.sh`, recorded in
`/etc/joinery/data_binds`, required by `joinery-data.target`):
`/srv/joinery/sites/<site>/config` is bind-mounted over `{site}/config`. Every
file anything writes into `config/` afterwards, including a key minted later
(`AgentDistPublisher` mints `agent_signing_key` on first use,
`ReleaseStatementPublisher` mints `release_statement_key` on the first logged
publish), lands encrypted with no list to keep up to date. Default-deny: a
new secret is protected because of where it is written, not because someone
remembered to name it.

**Nothing in `config/` stays readable before the unlock,
`Globalvars_site.php` included.** The one reader that runs while the volume
is locked is the agent daemon (D4 starts it beside the unlock), and all it
reads from the file is the database login and the web directory, for a
database that is on the data root too and so is not running. Finding its
config absent, the agent asks whether the config's folder is a bind of the
data root (`/etc/joinery/data_binds`) that is not mounted. If it is, the
agent keeps site posture and waits: no database login, none of the powers
only a siteless machine has, and it restarts to read the file within 15
seconds of it becoming readable. Absent anywhere else still means a machine
with no site (machine posture). So the list of what stays readable before the
unlock is empty; anything that must join it is added only with the reason
written beside it.

**What building it found (2026-10-11).** The design first kept
`Globalvars_site.php` on the root disk at `/etc/joinery/sites/<site>/`, with a
symlink from both sides of the bind. The inventory of everything that reads
or writes `config/` found three things wrong with that:
- The backups would carry only the link. A copy onto new hardware would then
  lose the source's `secret_box_key`, which `restore_chain.sh
  --adopt-secret-key` takes from the chain's copy of the file.
- Four writers replace the file rather than write through it:
  `_config_secrets.sh`, `restore_chain.sh`, `reconcile_site.sh`'s `sed -i`,
  and the restore's tar extract.
- `/etc/joinery/sites/<site>/` is kept at mode 700, out of the web server's
  reach.

The agent's one read was the only reason for the root-disk copy. So the
agent waits instead, and none of those three needs to change.

**What this asks of the code that touches `config/`:**

- **The move.** `joinery_data_root.sh move-in REL TARGET` moves a folder that
  holds data onto the data root where it stands, and its path is never
  missing at any moment:
  1. its files are copied to the place;
  2. the place is mounted over the path;
  3. a file written there during the copy is copied again;
  4. only then are the root disk's own files deleted, seen through a private
     bind of `/` that has no mounts over it.

  The path is left the locked mountpoint every bind leaves.
- **Born encrypted:** `_site_init.sh` moves `config/` in before it writes
  anything there. What the release put there first (the release
  verification keys, which are public) moves in with it.
- **An existing machine:** `config/` moves in when `encrypt` finishes (D5
  step 6), before its `fstrim`, so it never sits in a partly plain volume. The
  tick moves any site still left (one installed while the encryption ran, or
  a move that failed), then trims.
- `fix_permissions.sh` **exits 0, with a line saying why, while the data root
  is not mounted** for a site whose folders are on it. Its `chmod 750` on the
  locked `config/` mountpoint would fail under `set -e`, and nothing needs
  permissions fixed on a machine that serves nothing. Once mounted, it sets
  `config/`'s modes as it always has, on the data root's side.
- The converger runner's "site not initialised" check reads a `config/` that
  is a bind of an unmounted data root as locked. It says so once a boot, as
  its gate does.
- Writers, restores and backups are unchanged: they read and write `config/`
  through the mounted bind. The first backup after the move starts a new
  chain, because the folder's inode is part of the data archive's identity.
- A site's removal takes the bind away with its other folders: `remove-site`
  covers every bind under `sites/<site>/`.

**What moving does not undo.** Copies made before the move still hold the
secrets in plain text: the provider's backups and snapshots of the root
disk, and the freed blocks on the root disk itself until they are
overwritten. Dev has Linode Backups on (owner, 2026-10-11), so Linode holds
copies of dev's root disk with the release signing keys in plain text. So on
dev, right after its move:

- **Linode Backups is cancelled and re-enabled.** Cancelling deletes every
  existing Linode backup of dev at once; the backups taken afterwards hold the
  encrypted data root and no `config/` secrets. Any manual snapshot is replaced
  the same way. Linode may make the owner wait before re-enabling; dev's own
  nightly backups run throughout.
- **The Cloudflare token is replaced** and the old one revoked.
- **The release signing keys are rotated** (O12), which is its own spec.

### D10. The rest of the root disk

D9 moves `config/`. The inventory (O1) found the rest. Each item is fixed by
moving it into the data root, keeping it in memory, or stating why it stays,
so that "an offline copy of the disk is ciphertext" is true of the machine,
not only of the database. Every item applies to an encrypted machine only; a
machine with a plain data root keeps its `/var/log`, `/tmp`, swap and core
dump settings as they are ("How it is built").

- **`/var/log` becomes a bind of the data root.** One bind covers syslog,
  `mail.log`, `auth.log`, rspamd's log, Postgres's own log on bare metal,
  Apache's default-vhost logs, fail2ban and anything a package adds later:
  default-deny, like `config/`. rsyslog and the other writers become
  consumers of `joinery-data.target`. journald keeps `Storage=persistent`.
  `systemd-journal-flush.service` runs once, early in boot, long before any
  unlock, finds no persistent directory and leaves journald in memory
  (`/run/log/journal`) for the whole boot. So a unit ordered after the
  `/var/log` bind runs `journalctl --flush` once it is mounted, and the log of
  a boot that waited at the unlock page lands on the data root.
  `RuntimeMaxUse` is raised so a long wait at the page does not rotate the
  early entries away on a 1 GB node.
  **Moving `/var/log` in place** needs journald to let go of it first: the
  move runs `journalctl --relinquish-var` before and `journalctl --flush`
  after, stops rsyslog with the other consumers, and writes its own log
  somewhere other than `/var/log` (today's
  `/var/log/joinery-data-root-migrate.log` would be held open by the move
  itself, and the move refuses to run while anything holds a folder it moves).
- **PHP sessions** (`/var/lib/php/sessions`) become a bind of the data root
  on bare metal, as containers already have them.
- **Temporary files.** PHP's `upload_tmp_dir` and `sys_temp_dir` point at a
  directory on the data root for each site, on bare metal and in every
  container, so uploads in progress and spooled request bodies never touch
  the root disk; it is swept like any temp directory. The system `/tmp` is a
  size-capped tmpfs (memory) on the machine, inside its containers too
  (`--tmpfs /tmp`), for what other tools leave there. The cap fits what the platform's
  own tools put there: `install.sh` unpacks the core archive under `/tmp`
  (29 MB compressed today) and the StackScript writes its DNS file there;
  WP10 sizes the cap from a full install on a 1 GB node, or points those at
  the data root.
- **DKIM private keys** (`/etc/rspamd/dkim/`) move under the data root;
  rspamd is a consumer already.
- **Swap** stays the encrypted `/swapfile` with a fresh random key each boot,
  as `install.sh` makes it. Linode's own swap disk is plain once the
  provider's layer is off. `createInstance()` sends no `swap_size` today, so
  Linode makes its default 512 MB swap disk; `install.sh` already drops every
  fstab swap line, so it is attached and unused. A new machine born encrypted is
  created with `swap_size: 0`, and the five existing machines detach theirs.
- **Core dumps** are off: `LimitCORE=0` for the platform's units and
  `rlimit_core = 0` in the PHP pool, not left to systemd's default.
- **Freed blocks and provider backups, on every machine encrypted in place**
  (D7), not only dev: once the post-reboot tick has removed the old plain copy
  (not merely after the move), `fstrim`, and where Linode Backups is on,
  cancel and re-enable it and replace any manual snapshot, because those
  copies hold the data in plaintext from before the move.

**What building it found (2026-10-11).**
- **Containers.** A container's `/tmp` is in its writable layer, which is
  under Docker's data root, `/srv/joinery/docker`, on the encrypted volume. So
  it needs no `--tmpfs`; on an encrypted host only the host's own `/tmp` is a
  tmpfs.
- **PHP's temporary files.** Bare metal runs one PHP-FPM pool for all its
  sites, so these go to one directory, `/var/lib/php/tmp`, which is a bind of
  the data root, rather than one per site. `/tmp` becomes a tmpfs at the
  next boot (`tmp.mount`, `size=50%`, enabled and not started): mounting it
  over a running install would hide what is in `/tmp` now.
- **Folders a package makes.** PHP's sessions folder, its temp folder and the
  DKIM keys are moved in by the tick once they exist, within a minute. They are
  not made ahead of the package, because the package would then find its
  directory with the wrong owner and mode. `/var/log` exists before the
  install, so `create --encrypted` moves it in at once, and the rest of the
  install logs onto the volume.
- **Logs still being written.** After the move, a service still writing to a
  deleted file under `/var/log` is reloaded, and restarted if the reload does
  not reopen the file (PostgreSQL's log is its stderr). journald lets go of
  `/var/log` before the move (`--relinquish-var`) and flushes back after.
  `joinery-journal-flush.service` flushes at each boot once `/var/log` is
  mounted. `RuntimeMaxUse=32M` gives the journal room in memory while the
  boot waits for the key.
- **Early boot writes `/var/log` before any unlock** (found on the test VM
  with a real boot). Two early-boot units require `/var/log`:
  `systemd-update-utmp` (the boot record in `wtmp`) and
  `systemd-journal-flush`. A `/var/log` that waits for the unlock made an
  ordering cycle with them, and systemd broke it by dropping early jobs, which
  failed PHP-FPM (`/run/php` never made). A drop-in cannot take a
  `RequiresMountsFor=` away (systemd 255), so on an encrypted machine each
  runs from a copy of its package unit file without it, remade each tick so it
  follows an update. The boot's own flush gains
  `ConditionPathIsMountPoint=/var/log`, so the journal stays in memory until
  `/var/log` is the data root. The root disk's `/var/log` underneath the bind
  stays writable for what early boot writes there: the boot record,
  cloud-init's first lines, empty files tmpfiles makes. Once a boot, after the
  unlock, the tick appends those to the data root's logs, deletes them, and
  trims. Proven on the VM: no cycle and no failed unit locked or open; the
  persistent journal on the data root starts at the kernel's first line;
  cloud-init's early lines land in the data root's `cloud-init.log`.
- **sysstat** writes `/var/log/sysstat` at boot; it waits for the data root as
  rsyslog and fail2ban do.
- **The converger's path trigger** watches `cache/root_requests`, which an
  encrypted data root mounts only after the unlock; a path unit is ordered
  before `paths.target` by default, which was a second cycle. It is not
  (`DefaultDependencies=no`), on every machine, and starts once its folder is
  there.
- **Core dumps** are off machine-wide rather than per unit:
  `DefaultLimitCORE=0`, `systemd-coredump` set to `Storage=none`, and
  `kernel.core_pattern=|/bin/false`.
- **Swap.** The tick turns off any swap device that is not dm-crypt. The
  provider's swap disk on the five existing machines is detached at Linode as
  a step of D7.
- **The agent's outbox** can hold a job's full result, and a result can carry
  what the data root holds. So on an encrypted machine a result is kept under
  `/srv/joinery/agent-outbox` while the data root is mounted. While it is
  locked, results are kept where they always were, because nothing that runs
  then reads the data root. Both places are delivered from.

**Stays on the root disk, each with its reason:** TLS private keys (the unlock page needs them); the SSH host
keys (no login uses them; a copy lets someone impersonate the host to an SSH
client, nothing more); the agent's and the unlock identity (needed before the
unlock; the address check bounds the second); the recovery recipient
(public); Postfix's Postgres lookup password (opens nothing without the
database); Postfix's TLS session cache. The agent's outbox
(`/etc/joinery-agent/outbox`) holds queued job results: WP10 checks what they
carry, and moves the outbox under the data root if any carries member
content.

## How it is built: shipped dormant, switched on at the end

The whole spec is built and tested before any production machine is
encrypted (owner, 2026-10-11; D-O). Test servers take code only through a
release, since publishing refuses uncommitted work, and every release from
dev is the one getjoinery passes on. So the code reaches production machines
while it is being built, and it is written to do nothing there:

- **One setting decides whether encryption is offered:**
  `data_root_encryption_offered`, a core setting, off by default. While it is
  off, nothing makes an encrypted data root: no creating path sets
  `cvp_data_root_encrypted` (order, admin, site copy, Remote Install), the
  copy form shows no tick, the node page shows no Encrypt action, and a
  self-hosted site's Backups page shows no **Encrypt the data root**. It is
  turned on by hand at dev, at each test management node, and on the
  self-hosted test site that tests WP4.
- **Everything else acts only on an encrypted data root:** the unlock
  service and page, escrow enrolment, D1b's boot wiring, the converger's
  re-seal, the D9 and D10 moves, a multi-tenant host's reboot handling (D4),
  `swap_size: 0` at create. A machine whose data root is plain is unchanged by
  every release this spec ships. The escrow endpoint and the holder's table
  exist on every management node, empty.
- **`install.sh` takes encryption only by an explicit flag** until launch
  (sent by a management node whose setting is on, or typed on a test box); it
  does not offer it.
- **What ships to every machine on purpose,** under the ordinary test gate:
  the converger's output to the journal (D1b), the agent unit's start order
  without Postgres (WP2), the uptime check's **locked** state, and
  `release_key_rotation`'s agent changes, which must reach every agent before
  its ceremony and are proven first by a rotation of dev's test keys on test
  servers.
- **Switching on** follows D7. getjoinery's setting is turned on at D7 step
  5, after the release keys are rotated, and no hosted site is ordered while
  getjoinery's own encryption runs. When D7 is done, a **launch release**
  removes the setting and its checks and adds the installer's offer (WP3):
  from then every hosted birth is encrypted by default, and every self-hosted
  site can turn it on.

## Work packages

- **WP1 — The volume.** `joinery_data_root.sh create --encrypted`, LUKS2
  with the flags of D1, the random volume key, the TPM2 slot, the sealed
  break-glass sidecar, and the console prompt that takes the recovery key
  (D2a). Every open with `--disable-keyring` (D3), TPM2 through the token
  plugin with `--token-only`; the standard PBKDF2 count on every slot we
  make, measured on a 1 GB node and settled; the
  recipient file and `create --encrypted` refusing without one (D2); the
  boot wiring of D1b; `check`/`grow`/`status` through the mapping, including
  `grow` through `losetup -c` and `cryptsetup resize`. Proven on a scratch
  box, on a file-backed root and on a device: create, reboot, open by TPM2,
  open by the recovery key at the console with the other slots removed (which
  needs `--key-size 512`), a wrong key asked for again, a boot with the
  holder down that leaves consumers waiting and starts them on unlock with no
  reboot, and the measured memory of each slot operation on a 1 GB node with
  the stack running.
- **WP2 — The escrow.** Machine: the unlock identity minted at `create` on
  the root disk (D3); the one-shot unlock mode of the agent binary. Holder:
  the escrow table (secret, unlock public key, sidecar box, enrolled
  addresses), the enrolment job after the agent joins, and an endpoint that
  answers a boot request signed by a recorded unlock identity from an
  enrolled address, once per boot, whether or not the machine is the
  holder's node; the machine's own signed address report to the holder.
  Multi-tenant hosts: unattended-upgrades' reboot off, the converger's
  staggered reboot after a holder preflight, `multi_tenant_host.sh` accepting
  that setting, and the node page naming a reboot held back by a down holder
  (D4). The agent daemon's unit without the Postgres ordering, and the
  converger's quiet tick while locked (D1b). The agent built with Go 1.24 or
  later, for post-quantum key exchange on the escrow fetch (O7). Machine:
  `joinery-data-unlock.service`, with the console
  prompt (D2a) after the timeout. Proven: reboot with the management node up
  (hands-off), reboot with it down (the console prompt takes the recovery
  key), a reboot before enrolment (recovery key), and a holder still
  answering after the machine leaves it and re-pairs elsewhere, a copy of the
  root disk booted at another address refused, a dual-stack machine
  answered over the family it did not enrol from, and a second boot while the
  first is answered refused.
- **WP3 — Install shapes.** `install.sh`: on by default on a hosted birth
  (host provisioning), offered on a self-hosted install, both only where
  `data_root_encryption_offered` is on until the launch release adds the
  installer's offer ("How it is built"); `encrypt` (D5) for
  existing machines: the offline start, the online run at idle priority with
  progress on the node page, recovery and resume after a crash, the finish,
  and `decrypt` to undo. One layer, never two (D1a): `cvp_data_root_encrypted`
  on the provision row, set by the order, admin and site-copy paths; the
  `disk_encryption` option on `CloudComputeProvider::createInstance()`,
  honoured by `LinodeComputeDriver` and reported back by `normalize()`;
  `regions()` carrying `site_type` and the disk-encryption capability (built
  new: today it returns id and label only); the check at create and at
  `booting`, with an absent answer as unknown; `cvp_instance_id` saved before
  any check that can fail; the field set by all four creating paths, and
  unknown on an adopted server; the operator's recovery key passed to the
  install as the recipient; the in-place move
  refusal and its site-copy route. Proven by tests: an encrypted provision
  creates with `disk_encryption` disabled and an unencrypted one sends none;
  an instance reporting the layer on fails the provision before install; a
  distributed region is refused; an in-place `encrypt` on a
  machine with the layer on is refused; relay births send none. Proven live
  on one of each shape, including a 1 GB node booted and unlocked under the
  standard configuration, with the provider reporting its layer off. Every
  Linode fact this spec rests on is proven live, since none is in the code
  yet: the create field, the instance field, a region capability decides the
  default, distributed regions refuse `disabled`, a rebuild without the field
  keeps the setting, and a Linode backup of a disk with the provider's layer
  on restores as plaintext.
- **WP4 — The customer's view.** The site's and the host's pages say whether
  the data root is encrypted, which slot opened it at the last boot, and
  the recovery recipient's fingerprint; on a self-hosted site, the Backups
  page's **Encrypt the data root** once its recovery key is proven (D2), and
  the installer's `--recovery-public-key`; `VaultHealth`-style advice when the
  break-glass sidecar is missing or sealed to a retired recovery key.
- **WP5 — Tier 2 (D3), the next piece after launch.** BUILT 2026-10-11 (D3,
  "What building Tier 2 found"), but for the credentials, which are the
  owner's to issue: the events-only token on dev and the personal-account
  key. The machines that predate provisioning need adopting first (their
  provision row names their instance). The provider-events
  capability on `CloudComputeProvider` (`LinodeComputeDriver` reads
  `account/events` for an instance); the refusal on the escrow endpoint, with
  the wait-and-read-again; the refused machine on the holder's page with its
  event and the two ways on after a rescue boot or a root-password reset (the
  owner's recorded "I did this myself", or a copy from backups); an events-only read token for the
  live account on dev; an API key for the owner's personal account; the
  events scope added to the scopes the setup page asks for; customer-account
  machines shown as Tier 1. Proven on scratch boxes in the test account: T-A3
  and T-A4 turn from answered to refused (rescue boot, root-password reset), a
  clone given the machine's address is refused, a backup restored onto the
  machine is refused, a plain reboot and a provider maintenance reboot are
  answered, and an override unlocks.
- **WP6 — The existing machines (D7).** C-2 and C-3, the "keep the
  provider's layer as well" tick and the node page's "two layers" line, the
  test-account rehearsal (T-M1), then getjoinery, dev, their escrow with each
  other, and joinerydemo, jeremytunnell-vps and docker-prod, in D7's order.
- **WP7 — Rotation (D8).** BUILT 2026-10-11 (D8, "What building it
  found"): the tick's re-seal, the operator key pushed to hosted machines and
  counted on the Backups page, `rekey` with the holder's `rekey` action, the
  Rotate key question, and the node page's and the site Backups page's
  Re-encrypt under a new key. The converger's re-seal when the recipient changes
  to a proven key; the management node pushing a rotated operator key to every
  hosted machine, and the re-seal count on its Backups page; the new box
  reported after each re-seal; the Rotate key
  screen's "could someone else have seen the old key?" question and what the
  old key still opens; the online re-encryption job in D8's order (pending
  sidecars, the driving slot, `--resume-only` from the unlock service, the
  re-enrolment before done), one hosted machine at a time. Proven on a
  file-backed root as well as a device: a re-seal after a re-encryption
  still reads the key from the mapping, and a resume after a reboot
  part-way. Proven on a scratch box: rotate,
  old key refused at the console and new one accepted; rotate as leaked,
  re-encryption interrupted by a reboot and resumed, then the machine opens by
  escrow, by the new key, and not by an old sidecar copy.

- **WP8 — The unlock page (D2b).** The unlock helper's https listener on
  the site's certificate until the volume opens, inside the oneshot unlock
  service; every name a multi-tenant host carries, with its own
  certificates; the browser arithmetic shared with `/copy-key`; rate limits
  by Cloudflare-aware address; status 503 with `X-Joinery-Locked`, and the
  uptime check's **locked** state; the management node's unlock for any of
  its locked machines, with the evidence of D2b and the box warning; the
  uptime incident linking to the management node's unlock while it is up,
  and to the machine's own page only when it is not. Proven on a scratch box: reboot with the
  management node down, open by the page through the Cloudflare proxy, the
  console still works, a wrong key refused, the uptime check reporting
  locked, and the web server takes port 443 after unlock.
- **WP9 — Secrets under the data root (D9).** `config/` as a bind of
  `/srv/joinery/sites/<site>/config` on every encrypted machine, born that way
  on a new one and moved in (`move-in`) once `encrypt` finishes on an existing
  one, `Globalvars_site.php` with it; the agent waiting in site posture while
  its config is on an unmounted data root; `fix_permissions.sh` and the
  converger waiting for the data root; the node page listing what stays on
  the root disk. BUILT 2026-10-11 but for the node page list (agent
  `site_waiting.go`, data root gate 186, installer contract 878).
  On dev, right after its move: Linode Backups cancelled and re-enabled (and
  any manual snapshot replaced), and a new Cloudflare token with the old one
  revoked.
  Ships with WP1–WP3; carried out on dev by D7 step 3.

- **WP10 — The rest of the root disk (D10).** `/var/log` as a bind of the
  data root with journald flushed onto it after the unlock and its writers
  ordered after `joinery-data.target`; PHP sessions bound; PHP's temp
  directories on the data root and `/tmp` a capped tmpfs, on bare metal and in
  containers; DKIM keys under the data root; Linode's swap disk left out of a
  new machine's profile and detached on the five; core dumps off; `fstrim` and
  the provider-backup purge after every in-place move; the agent outbox
  checked and moved if needed. Ships with WP1–WP3. BUILT 2026-10-11 (D10,
  "What building it found"), but for detaching the five machines' swap disks
  at Linode, which is a step of D7, and the provider-backup purge, which is the
  owner's.

## Testing

Nothing in this spec acts on a production machine until every test below
that touches its work package has passed on scratch boxes. The code reaches
production machines earlier, dormant ("How it is built"). Each test names what
it proves and what a pass looks like; results go in this spec's status line
and, for anything run live, the live verification queue.

### Where tests run

- **Scratch boxes in the test Linode account**, created from dev, which
  holds that account (`test_cloud_account_and_prod_management`). They are
  deleted after 24 hours by `DeleteOldTestServers`; a box needed longer is
  tagged `keep` and deleted by hand when its test is done.
- **A throwaway management node in the test account** (the `wp5-mgr`
  pattern) for every test where the holder is not dev, and for the D7
  rehearsal, which needs two of them. Dev's own plane holds escrow for scratch boxes only.
- **Shapes**, each used where the test says: a bare-metal site on a
  file-backed data root (the common case); a bare-metal site on a device
  (a Linode Block Storage volume as the backing); a multi-tenant host with
  two container sites; a 1 GB plan wherever memory or speed is measured.
- **A Linux VM on the Mac mini** (UTM, Ubuntu 24.04, with a software TPM)
  is the fast loop for the shell and boot pieces (WP1, `encrypt` and
  `decrypt`, T-F2's power cuts): files are copied straight in, so no release
  is needed. A scratch box in the test account then confirms each release on
  Linode's own CPU and kernel; speed is measured only there. **TPM2** cannot
  be tested on Linode, which has none (O4 C), so those tests run on the VM
  only.
- **Automated suites** run in the ordinary gate (`php tests/run.php`) and the
  agent's `go test ./...`. Each work package lists its own; they cover logic
  that needs no real disk or provider.

### Automated suites

- **Shell gate for `joinery_data_root.sh`** (WP1): `create --encrypted`
  refuses without a recipient; the header it writes (cipher, 512-bit key,
  PBKDF2 at the standard count on every slot we make, no argon2id); `check`
  failing a slot not made our way; `status` through the mapping; the config's
  `luks-file` / `luks-device` backing; `grow`'s command order. On loop images,
  as root, on a scratch box (it cannot run on dev's shared tree).
- **Agent** (WP2, WP8): opening a sealed box against fixed vectors, shared
  with `copy_take_key`'s; the unlock request's signature and its refusal when
  altered; the Cloudflare range check; the console prompt's retry on a wrong
  key; the one-shot mode's exit codes.
- **Plane, db tier** (WP2, WP3, WP8): the escrow endpoint — a known identity
  from an enrolled address answered, an unknown identity, an unenrolled
  address (IPv4 and IPv6, compared as addresses), a second request inside 15
  minutes, a request after an unlocked report, all refused or answered as D3
  says; the address report; enrolment and removal writing the table; the
  provision path setting `cvp_data_root_encrypted` from all four creating
  places and sending `disk_encryption` only when set and only where the region
  has the capability; the check at create and at `booting` (on, off, absent);
  `cvp_instance_id` saved before a failing check; a distributed region
  refused; the in-place move refused on a machine with the provider's layer
  on, and turning ours off refused on one with it off; relay births sending
  nothing; the uptime check reading 503 plus `X-Joinery-Locked` as
  **locked**; the holder's unlock page showing the D2b evidence.

### T-L — Linode's facts (first, before any code)

Each is a few minutes on a Nanode and settles a fact the design rests on.

**Run 2026-10-11 on the test account (us-east, Nanodes, all deleted
after):** T-L1 pass (created `disabled`, instance and disks report
`disabled`); T-L2 pass (no field: `enabled`); T-L3 not runnable (the account
is offered no distributed region; all 33 regions are `core` with the "Disk
Encryption" capability); T-L4 pass (run 2026-10-15: a rebuild with no `disk_encryption` field keeps the setting, `disabled` stays `disabled` and the default `enabled` stays `enabled`, on the instance and every disk); T-L5 pass (run 2026-10-15: a Nanode with the provider's layer `enabled` and Backups on; a marker written, a snapshot taken, restored onto a new Nanode, whose disks also report `enabled`; booted, the marker reads back as written: the provider's layer is no protection from whoever holds the account or its backups); **T-L6 fails**:
cloning into an existing Linode with the other setting is refused ("cannot
clone to a linode with different encryption status"), and a plain clone to a
new Linode inherits `enabled` (a 25 GB disk cloned in 132 s); T-L7 pass
(`site_type` and the capability present); T-L8 pass (`disk_encryption` on
the instance and on each disk). Also found: a **rebuild with `disabled`**
turns the layer off and keeps the Linode's IPv4 and IPv6; a **Block Storage
volume** defaults to the provider's own encryption on; a **captured Image**
can start a new Linode with `disabled`, but Images are capped at 6 GB of used
space by default (Linode's documentation), far below any of our machines
(dev's root disk holds 107 GB).

- **T-L1** Create in a core region with `disk_encryption: disabled`: the
  instance and its disks report `disabled`.
- **T-L2** Create in a core region with the capability and no field: it
  reports `enabled` (the default the spec assumes).
- **T-L3** Create in a distributed region with `disabled`: refused, and the
  error says why.
- **T-L4** Rebuild without the field: the setting is unchanged.
- **T-L5** Take a Linode backup of a disk with the provider's layer on,
  restore it to a new Linode, read a marker file from it in rescue mode:
  plaintext (the spec's claim that the provider's layer does not cover
  backups).
- **T-L6** Clone a powered-off Linode's disks into an existing empty Linode
  created with the layer off (`linode_id`): the target's disks report
  `disabled`. Also a plain clone to a new Linode: record what it inherits.
  Decides O8.
- **T-L7** `GET /regions`: `site_type` and the "Disk Encryption" capability
  are present, as `regions()` will read them.
- **T-L8** `GET /linode/instances/{id}`: `disk_encryption` is on the instance
  object, as `normalize()` will read it.

### T-V — The volume and its boot wiring (WP1)

Run on the file-backed and the device-backed shape unless marked.

- **T-V1** `create --encrypted` with the operator's recipient: `luksDump`
  shows aes-xts-plain64, a 512-bit key and no keyslot until enrolment; the
  sidecar exists and opens with the recovery key; the volume key never
  appears on disk (search the root disk for it after create).
- **T-V2** `create --encrypted` with no recipient: refused before anything is
  formatted.
- **T-V3** Open by the sidecar alone with every slot removed: needs
  `--key-size 512`, and works with it.
- **T-V4** A wrong recovery key at the console and on the page: refused and
  asked again; the right one then opens.
- **T-V5** Console prompt in Lish, left for 10 minutes (`--timeout=0`), then
  answered: opens, and the stack comes up with no reboot.
- **T-V6** Boot with the holder down, checked without a login (the agent's
  status, the unlock page). The unlock unit is activating, not failed;
  Postgres, Apache and Docker are waiting, not failed; the agent daemon is
  up and reports locked; the converger runs each minute with no failed unit
  and logs to the journal; the journal has no "ordering cycle" line and
  `systemd-analyze verify` is clean. Then unlock: every consumer starts on its
  own.
- **T-V7** `ExecStop`: stop the unlock unit; the mapping is closed and the
  loop detached, nothing left open.
- **T-V8** Direct I/O fallback (file shape): with the image on a filesystem
  that refuses direct I/O, the loop comes up without it and one line is
  logged; the volume still opens.
- **T-V9** `grow` on both shapes with a 2 GB marker file in place, run by the
  converger's own tick (not by hand), on an escrow machine and on one opened
  by the recovery key: the filesystem grows with no prompt, quotas hold, the
  marker's checksum is unchanged, and the key never touches the disk.
- **T-V10** `check` with a slot added by hand with argon2id: `check` fails and
  names it.
- **T-V11** PBKDF2 count (1 GB node, Postgres under `pgbench`, Docker and
  Apache running): time an unlock and each slot operation (enrolment, a
  temporary slot). Settles the count at about one second there; no
  out-of-memory kill in the kernel log.
- **T-V12** Multi-tenant shape: the host's data root under LUKS, both
  container sites up after unlock, quotas per site intact.

### T-E — Escrow, identity and the address check (WP2)

- **T-E1** Enrolment after the join: the escrow slot appears, the holder's
  table has the identity, the box and the address; the secret is in no job
  log or command line (search the holder's job rows and the machine's
  journal for it).
- **T-E2** Reboot, holder up: opens hands-off within a minute.
- **T-E3** Reboot, holder down: the page and the prompt appear after 60
  seconds; bring the holder back and the machine unlocks itself.
- **T-E4** Reboot between create and enrolment: opens only by the recovery
  key, as D3 says.
- **T-E5** Restore a Linode backup of the machine onto a new Linode: its boot
  request is refused and logged with the new address.
- **T-E6** IP swap the machine (the copy flow's swap, or a provider transfer):
  it reports the new address; the next reboot is answered.
- **T-E7** Dual-stack: block the machine's IPv4 route to the holder for a
  boot, so it asks over IPv6; answered from the stated set.
- **T-E8** One answer per boot: a second signed request inside 15 minutes is
  refused and shown on the holder's page; after the machine reports
  unlocked, the next boot is answered. And the lost answer: drop the holder's
  response once; the machine waits at its page, the holder's page says it
  answered with no unlock reported, and after 15 minutes the machine unlocks
  on its own.
- **T-E9** Holder not the plane: the machine leaves its plane and joins
  another; the original holder still answers its next boot.
- **T-E10** Holder behind Cloudflare: the holder records the
  `CF-Connecting-IP` address, not Cloudflare's; a request forging that header
  from outside Cloudflare's ranges is judged by its real address.
- **T-E11** Post-quantum key exchange: the agent built with Go 1.24 or later
  fetching through Cloudflare negotiates X25519MLKEM768 (`/cdn-cgi/trace`
  shows the key exchange).
- **T-E12** Multi-tenant reboot: two hosts with `reboot-required` reboot at
  different minutes after a preflight; with the holder down neither reboots
  and the node page says the reboot is held back; `multi_tenant_host.sh`
  installs and reports healthy with apt's reboot off.
- **T-E13** Holder restored from a backup older than an enrolment (O11): that
  machine falls to break-glass at its next boot, and its node page had said
  it would.

### T-U — The unlock page (WP8)

- **T-U1** Locked bare-metal site through the Cloudflare proxy: the page
  loads over https with the site's own certificate; status 503 with
  `X-Joinery-Locked: 1`.
- **T-U2** The uptime check reports **locked** and opens an incident; while
  the holder is up the incident links to the holder's unlock, and only when
  the holder is down to the machine's own page.
- **T-U3** The holder's unlock page shows the evidence D2b lists, and a
  changed sealed box shows as a warning.
- **T-U4** Unlock from the holder's page: the recovery key never reaches the
  machine (capture the machine's inbound request body and find only the
  32-byte shared value).
- **T-U5** Rate limiting: repeated wrong answers from one address are slowed;
  through Cloudflare, by `CF-Connecting-IP`.
- **T-U6** Multi-tenant host: every container's name answers with its own
  valid certificate; no self-signed placeholder is ever served.
- **T-U7** After unlock the helper exits and Apache binds 80 and 443 with no
  manual step; the console prompt, still open, closes itself.

### T-P — Provisioning and install shapes (WP3, WP4)

- **T-P1** A hosted birth on a 1 GB plan from the order path: provider layer
  reported `disabled`, data root encrypted, recipient is the operator's key,
  escrow enrolled, a reboot opens hands-off.
- **T-P2** The same from the admin Install New Node form and from a site
  copy (C-2), and a buyer's draft carried into an order.
- **T-P3** An adopted server: the field reads unknown and the node page says
  so.
- **T-P4** `encrypt` asked of a machine with the provider's layer
  on: refused without the "keep the provider's layer as well" tick, pointing at
  a site copy; with it, runs, and the node page says "two layers".
- **T-P5** Self-hosted install: born unencrypted; set up the recovery key;
  **Encrypt the data root** runs and the site comes back encrypted; a later
  reboot asks for the recovery key (no TPM2 on Linode).
- **T-P6** Self-hosted install with `--recovery-public-key`: born encrypted.
- **T-P8** `install.sh server` and a full site install on a 1 GB node with
  `/tmp` as the capped tmpfs: completes, nothing spills to the root disk.
- **T-P7** The site's and host's pages: encrypted, the slot that opened it,
  the recipient's fingerprint, and the advice when the sidecar is missing.

### T-R — Rotation and re-encryption (WP7)

- **T-R1** Self-hosted rotation: the sidecar re-seals on the next tick; the
  old key is refused at the page and the console, the new one opens.
- **T-R2** Hosted rotation of the operator's key across three scratch
  machines: all re-seal, the holder's Backups page counts them, one kept
  offline is named until it returns.
- **T-R3** Rotation as leaked, escrow machine: re-encryption runs with the
  site up; reboot it at about a third of the way; it opens by escrow and
  resumes (`--resume-only`); when done, a copy of the old sidecar taken
  beforehand no longer opens the volume.
- **T-R4** The same on a machine without escrow (temporary slot): a reboot
  part-way opens by the recovery key through the pending sidecar, and
  resumes.
- **T-R5** After a re-encryption, a further rotation's re-seal still reads the
  key from the mapping.
- **T-R6** TPM2 (swtpm VM): re-enrolled before the job reports done; a reboot
  between finish and re-enrolment opens by the recovery key.
- **T-R7** Both shapes, file and device, for T-R3.

### T-K — Secrets under the data root (WP9)

- **T-K1** A machine born encrypted: `config/` is a bind of the data root
  before `_site_init.sh` writes it; a search of the root disk's raw blocks for
  a marker written into `config/`, `Globalvars_site.php` included, finds
  nothing.
- **T-K2** `encrypt` on an existing machine with an
  `agent_signing_key` and `backup_site_key`: both are on the data root
  afterwards, `config/` works for the site, publishing still signs, and a
  backup still runs and verifies.
- **T-K3** Locked: the agent daemon starts in site posture, waiting (never
  machine posture), and the management node sees it; the root disk's
  `config/` is an empty locked mountpoint. Unlocked: the agent restarts and
  reads its config within 15 seconds, and connects to its database.
- **T-K4** A key minted after the move (delete a scratch
  `release_statement_key` and publish): it is created on the data root.
- **T-K5** `restore_chain.sh --adopt-secret-key` on an encrypted machine:
  the chain carried the source's `Globalvars_site.php`, the `SecretBox` key
  is adopted into this machine's own config on the data root, and the agent
  still starts after a reboot.
- **T-K6** `fix_permissions.sh` on a locked machine: exits 0 saying it waits
  for the data root, changes nothing. On an unlocked machine: `config/`'s
  modes right on the data root's side, `Globalvars_site.php` 640
  root:www-data.
- **T-K7** `move-in` on a live site: no request fails during it, a file
  written to `config/` during the copy is on the data root afterwards, and
  the root disk's copy is gone (searched in its raw blocks after `fstrim`).

### T-A — The claims in the table

Each row of "What it buys, and what it does not" is tested in both
directions, so the table stays honest.

- **T-A1** Offline: a Linode backup and a snapshot of an encrypted machine,
  restored and read in rescue mode: the data image is ciphertext.
- **T-A2** A copy booted on another address: refused (T-E5).
- **T-A3** Rescue mode on the machine itself, then a normal boot from rescue
  at its own address: answered under Tier 1. Recorded as the known gap.
- **T-A4** Root-password reset and reboot: answered under Tier 1, and Lish
  logs in. Recorded as the known gap.
- **T-A6** After a rescue boot (Tier 2): alter a PHP file on the root disk in
  rescue, boot. The holder refuses; its page offers only "I did this myself"
  and a copy from backups, never a plain override.
- **T-A5** What stays plaintext on the root disk (D9, D10): on a scratch box
  that has served real traffic (logins, a registration, uploads including a
  Private file, mail in and out, a failing SQL statement), take a copy of the
  encrypted machine's root disk and search it, files and raw blocks, for
  marker strings planted in each. Pass: only what D10's "stays" list names;
  no session, upload, mail address, log line, DKIM key or `/tmp` leftover;
  WP4's page says exactly that. And the log was not simply lost: the journal of
  the locked window is in `/var/log/journal` after the unlock and after the
  next reboot.

### T-M — The moves (WP6)

- **T-M1** The D7 rehearsal in the test account: two throwaway management
  nodes, each encrypted in place with the tick, each enrolled as the other's
  escrow holder through the fingerprint approval, and a node encrypted under
  one of them. Pass: a planned reboot of each management node opens hands-off
  from the other; with both down, each opens by its own recovery key; the
  node opens from its holder; every node page says "two layers".
- **T-M1b** The same rehearsal with one management node on a device-backed
  data root (a Block Storage volume), since D5's start differs by backing.
- **T-M2** A multi-tenant host with two container sites encrypted in place:
  both sites back after the move, their quotas intact, and a reboot opens
  hands-off.
- **T-M3** A machine leaving our management (O9): an instance transfer
  refused while the sidecar names the operator's key, allowed after the
  re-seal to the site's own key, and the holder's record gone afterwards.

### T-F — Every bad moment

A reboot or a power cut at each of these, on a scratch box, and the machine
must come back openable by the path the spec names, with no data lost:
during `create`; between `create` and enrolment; during enrolment; during a
re-seal; during each third of a re-encryption; during each step of D5's
`encrypt`;
during each step of the D7 rehearsal; with both management nodes down at
once (two throwaway planes holding each other's escrow). And each way to
lose a key: the recovery key lost before and after a rotation, the holder's
database lost and restored (T-E13), the escrow slot removed by hand.

### T-F2 — `encrypt` made reliable (decides D5)

The owner chose in-place encryption on condition it is reliable, so this
campaign is the gate. On a 1 GB node and a 4 GB node, each on a file-backed
and a device-backed data root, with Postgres under `pgbench`, uploads and mail
running throughout:

- **Cut the power** (an instant reboot through `sysrq`, not a clean shutdown)
  during the offline start, early in the online run, in the middle, and near
  the end: at least twenty cuts across the runs, at random points as well as
  the chosen ones. Every time the machine comes back openable by the path D5
  names, repairs the header if asked, resumes, and finishes.
- **Fill the disk** of the backing's host part-way, and **kill** the encrypt
  service with `SIGKILL`: it resumes cleanly once the cause is gone.
- **Undo** an encryption part-way with `decrypt`, and run one to the end.
- **After each run:** `pg_amcheck` finds nothing; a tree of files written
  before the start matches its checksums; a read-only `xfs_repair -n` on a
  snapshot of the volume is clean; the site works.
- **Load:** the site's response times while the online run is going, against
  the plain baseline, so "idle priority" is measured, not assumed.

Pass: every run ends encrypted (or decrypted, for the undo runs) with the data
intact. A failure that cannot be fixed means D5 falls back to the second
image, and this spec is amended before any machine is encrypted.

### T-S — Speed, on the final configuration

`fio` and `pgbench` on 1 GB and 4 GB plans, plain against encrypted, the
appendix's method (three runs, medians, two instances per size). Pass: within
the appendix's ranges; anything worse is reported before WP3 ships.

## Decisions

- **D-A. Reuse the owner's recovery key for break-glass; never a new
  human-held key** (owner, 2026-10-07).
- **D-B. One volume key, one escrow secret per machine, held by the
  management node.** Splitting the key across peer machines' memory so it
  exists nowhere at rest was **rejected by the owner 2026-10-07** as too large
  a risk. Do not re-propose.
- **D-C. Tier 1 at launch; Tier 2 straight after** (owner, 2026-10-11;
  2026-10-07 had it "separate, may land never"). Tier 1 delivers what
  customers and their compliance checklists ask for: the provider's backups,
  snapshots, clones and retired drives are unreadable, and so is a copy started
  on another address. Tier 2 closes the attack on us, a stolen hosting login,
  for every machine in an account we hold a lasting key for (D3), including
  getjoinery through an events-only token on dev and jeremytunnell-vps
  through an API key for the owner's personal account. Machines in a
  customer's own account stay at Tier 1.
- **D-E. One standard LUKS configuration for every node** (owner, 2026-10-07):
  LUKS2, aes-xts-plain64 with a 512-bit key, no workqueue flags (D1). Not
  chosen per plan size. **Amended by the owner 2026-10-11:** every slot we
  make is PBKDF2-SHA256 at a fixed count of about one second (1,000,000,
  settled on a 1 GB Linode by T-V11, 2026-10-12), not argon2id at 256 MiB, because D-H left no
  slot holding a passphrase and argon2id's memory runs while the stack is up
  (D1). Not the minimum, so a slot weaker than intended still costs a guesser
  time. Slots a re-encryption mints inherit the driving slot's settings; D8
  passes them explicitly anyway.
- **D-F. The provider's disk encryption is turned off on nodes that use our
  volume** (owner, 2026-10-07; D1a), and left at the provider default on
  nodes that do not.
- **D-G. Never two layers** (owner, 2026-10-11; the five existing machines
  excepted by D-L): the choice is made on the
  provision row before the machine exists, checked against what the provider
  reports after, and an in-place move that would leave two layers (or none)
  becomes a site copy onto a new machine (D1a).
- **D-H. No passphrase anywhere** (owner, 2026-10-11): the console prompt
  takes the recovery key (D2a), so the management node and an unmanaged
  machine without TPM2 add nothing for the owner to keep.
- **D-I. A rotation always re-seals; a leaked key also changes the volume
  key** (owner, 2026-10-11; D8). The owner says which, on the Rotate key
  screen.
- **D-J. A locked machine offers an unlock page** (owner, 2026-10-11; D2b):
  the browser is the normal way a person unlocks, and the console is the
  fallback.
- **D-K. getjoinery and dev hold each other's escrow** (owner, 2026-10-11;
  O4). Dev holding a production secret is a small addition, because dev
  already holds the release signing key (`config/agent_signing_key`), and
  every production node installs what that key signs. Whoever controls dev
  can already reach getjoinery through its next update.
- **D-L. The five machines that predate this spec keep Linode's layer as
  well as ours** (owner, 2026-10-11; O8, D7). They are encrypted in place;
  Linode's layer goes when each is next replaced. Every machine born after
  WP3 has one layer (D-G stands for them).
- **D-M. `config/` moves under the data root on every encrypted machine**
  (owner, 2026-10-11; D9, WP9), `Globalvars_site.php` with it. It was decided
  with that one file left on the root disk; building it found the copy
  unneeded and harmful to backups, so the agent waits for it instead (D9,
  "What building it found"). On dev it is carried out by dev's own in-place encryption (D7
  step 3), so dev's signing keys and Cloudflare token are inside the
  encryption from its first encrypted boot.
- **D-N. An existing data root is encrypted in place** (owner, 2026-10-11;
  O13, D5), by LUKS2's online encryption, on condition the T-F2 campaign shows
  it reliable; otherwise a second image. It serves our five machines once and
  every self-hosted owner who turns encryption on.
- **D-O. Built dormant, switched on at the end** (owner, 2026-10-11; "How
  it is built"). The code ships in ordinary releases while it is built, does
  nothing on a machine whose data root is plain, and is offered only where
  `data_root_encryption_offered` is on, until the launch release after D7
  removes the setting.
- **D-D. Warrant resistance is out of scope.** The vault is the answer to
  that question; this spec does not pretend otherwise.

## Open items

- **O1 — DONE 2026-10-11:** the inventory is "What is protected", above; what
  it found on the root disk is D10.
- **O2 — SETTLED 2026-10-11:** the unlock page and the console prompt appear
  60 seconds after the first unanswered request; the unlock unit keeps asking
  the holder every 15 seconds after that, so a holder that comes back still
  unlocks the machine with no person involved (D3).
- **O3 — SETTLED 2026-10-11:** one answer per boot (D3), beside the address
  check.
- **O4 — DECIDED 2026-10-11: getjoinery and dev hold each other's escrow (D-K),
  with A as the fallback; B′ is dropped.** (2026-10-07: A now, B′ later.)
  The management node cannot escrow its key to itself, and it must not stay
  unencrypted: its database holds every machine's sealed escrow secret and
  its `SecretBox` key is on its plain root disk, so an unencrypted plane
  makes a copy of one disk the key to the fleet. Its own volume is opened by:
  **A — the recovery key at the console at boot** (D2a; a passphrase until
  2026-10-11), systemd's standard prompt on the
  serial console (`console=ttyS0`), the same on every provider and on bare
  metal; only how a person reaches the console is the provider's (Lish, the
  EC2 or GCP serial console, VNC, IPMI), and the platform builds none of
  that. Its recovery key is already in the owner's password manager. A is
  also every machine's break-glass path, so it costs nothing extra; since
  2026-10-11 the unlock page (D2b) offers it in the browser as well.
  **B″ — the other management node holds the escrow (chosen 2026-10-11).**
  Dev holds getjoinery's escrow secret and getjoinery holds dev's (D3, D7,
  C-3). Each reboots hands-off while the other is up; A is needed only when
  both are down at once. The cost: dev holds one production secret (D-K says
  why that is small), and both are in the live Linode account, so a stolen
  login could rescue-boot dev, have getjoinery unlock it, and read
  getjoinery's escrow from it. Tier 2 (WP5) closes both halves: getjoinery
  reads dev's events with its own key, and dev reads getjoinery's with an
  events-only, read-only token for the live account (owner, 2026-10-11),
  the one live credential dev holds. Until WP5 lands, both halves are open. B′ had the same opening until Tier 2, and needed a machine of its
  own. Both sit in Linode's Newark data center today (getjoinery's server is
  in `us-east`; dev's address is in the same range), so one provider
  maintenance could reboot both; each then waits at its unlock page for its
  own recovery key, with no deadlock. Accepted until one of them is next
  replaced, when it goes to another region.
  **B′ — a key holder with nothing to unlock**, when hands-off plane reboots
  are wanted: a small disposable machine (never agented, one job, like the
  DNS and relay machines) that holds the plane's escrow secret and answers
  the plane's boot request after checking the instance identity through the
  provider's metadata service. No data root of its own, so no deadlock; on
  the second cloud account (`test_cloud_account_and_prod_management`), so
  one stolen login does not reach both halves. A stays as its fallback.
  **C — a TPM2 slot — verified NOT available on Linode 2026-10-07:** no TPM
  device on a Linode (nothing at `/dev/tpm*`, BIOS boot, no UEFI), and the
  configuration-profile API has no UEFI, Secure Boot or TPM option. It
  exists on AWS (NitroTPM), GCP (Shielded VM), Azure (Trusted Launch),
  Exoscale and on real hardware, so the TPM2 slot stays in D2 for those. A
  cloud vTPM is the provider's software: it stops a clone, image or backup
  from opening the volume, not the provider, and stops rescue mode only
  where the key is bound to measured boot.
- **O5.** Everything the unlock needs before the volume opens — the unlock
  identity, the holder's address, the recovery recipient, the host state —
  must stay on the root disk, never under
  `/srv/joinery`. This settles `one_data_root` O2: host state stays on the
  root disk. The boot-time fetch is a one-shot run of the agent binary, not a
  call into the long-running daemon, so a dead daemon is not a dead boot; a
  machine leaving the plane enrols a TPM2 slot where it has one before it
  leaves; without one, it opens by the recovery key at the console (D2a) from
  then on.
- **O6 — SETTLED 2026-10-11 (D7):** the five existing machines are encrypted
  in place, getjoinery and dev first, then joinerydemo, jeremytunnell-vps and
  docker-prod.

- **O7 — Post-quantum (owner, 2026-10-11: record both; confirmed).** The symmetric
  parts are already safe against a quantum computer: AES-256 and the hash
  inside PBKDF2 keep about 128 bits of strength against Grover's search. The
  public-key parts are not, and both can be copied today and opened later:
  - **The escrow fetch at boot** travels over TLS whose key exchange is
    X25519. Recorded now, it could be decrypted later and give up the escrow
    secret. The fix is hybrid post-quantum key exchange (X25519MLKEM768) on
    every hop: the agent's Go is 1.22 and gains it by default from 1.24; the
    holder side needs OpenSSL 3.5 or later (dev runs 3.0.13), or Cloudflare's
    edge, which offers it, on the hop it terminates. Upgrade the agent's Go
    as part of WP2.
  - **Every sealed box to the recovery key**, which is X25519: the break-glass
    sidecar here, which sits in the provider's backups beside the encrypted
    image, and every backup envelope the platform already makes. A hybrid
    sealing (X25519 plus ML-KEM, opened only when both are broken) changes
    the recovery key's format for backups too, so it is a spec of its own,
    not part of this one. PHP's libsodium (1.0.18) has no ML-KEM; Go has
    `crypto/mlkem` from 1.24.

- **O8 — DECIDED 2026-10-11: the existing machines keep both layers (D-L).**
  A site copy cannot move docker-prod (a multi-tenant host) or dev (far more
  than its site), and the machine-clone path is closed: Linode refuses to
  clone between machines with different encryption settings, and Images are
  capped at 6 GB (T-L6). Rejected: rebuilding each machine in place by
  staging its disk on a volume from Linode's rescue console (hands-on, twice
  per machine, on production), and automating that (a build for a one-time
  job on five machines). All five are our own machines; customers' machines
  are always born with one layer.
- **O9 — CONFIRMED by the owner 2026-10-11: a machine leaving our management.** A hosted machine's break-glass is
  sealed to the operator's key, and its escrow is ours. When it leaves (an
  instance transfer to its customer, `managed_to_self_hosted_transfer`, or a
  customer who disconnects), the recipient switches to the site's own proven
  recovery key and the sidecar is re-sealed (D8's mechanism) before the
  transfer completes; the escrow slot is removed and the holder's record
  deleted once it has left. A Linode has no TPM2, so from then on it opens by
  the owner's recovery key at every boot (D2a), and the transfer page says
  so. The transfer refuses to finish while the sidecar is still sealed to the
  operator's key.
- **O10 — DECIDED 2026-10-11 (D-M, D9):** `config/` moves under the data
  root on every encrypted machine, by bind mount. WP9. Built with
  `Globalvars_site.php` under the data root as well (D9, "What building it
  found").
- **O11 — CONFIRMED by the owner 2026-10-11: the holder's own restore.** A holder rebuilt from its backups has
  the escrow table as of its last backup. A machine enrolled after that
  backup falls to break-glass at its next boot. So the holder runs a database
  backup within the hour after any escrow change (an enrolment, a removal),
  and the node page of a machine enrolled since the holder's last backup says
  so.

- **O12 — DECIDED 2026-10-11: rotate the release signing keys, as a spec of
  its own.** Dev has Linode Backups on, so anyone who read one of those
  backups in the past holds `agent_signing_key` and `release_statement_key`
  today; purging Linode's copies (D9) stops new readers, not past ones. The
  release system can already bring a new key in through its key chain
  (`release_transparency`), but every node's trust list only grows ("Nothing
  is ever removed by the converger"), so after a rotation every node would
  still accept the old key. The rotation spec therefore adds a signed
  retirement of a key that nodes obey, and moves the agent's built-in update
  key (`updatePubKeyB64`) through an agent release signed with the old key.
  It belongs to the release system, not to this spec: `release_key_rotation`
  (drafted 2026-10-11).

- **O13 — DECIDED 2026-10-11: in place, by LUKS2's online encryption, if it
  is made reliable (D5).** The owner weighed a second image (copy, switch,
  keep the old until proven; needs room for the data twice and downtime the
  length of the copy) against encrypting in place (no copy, seconds of
  downtime, hours of background disk work, a header repair after a badly
  timed crash), and chose in place as the better choice provided it is
  reliable. T-F2 decides; the second image is the fallback. The rule this
  replaces, "never by re-encrypting in place" (D5 as first written), went
  with it.

## Appendix — the research this stands on (reviewer1, 2026-10-07)

**Linode rescue mode (Finnix)** reboots the instance with its disks attached
raw; it needs only the `rescue_linode` grant and cannot be disabled per
instance. Same-account doors: Reset Root Password, Lish, Backups, Clone,
Images. Linode's own "Local Disk Encryption" is platform-managed: readable in
rescue, clones usable, and Linode states backups are not encrypted even when
taken from an encrypted disk; it covers a decommissioned physical drive and
nothing else. A custom kernel, Direct Disk or an unusual filesystem change
nothing: any plaintext block device is readable in Finnix.

**Performance — first draft's estimate, superseded by the measurements
below.** The draft expected the cost to be small, near zero with the
workqueue flags. The measurements show a real cost on large sequential reads
and no benefit from the flags.

**Performance, measured 2026-10-07** on Linode (us-east, AMD EPYC 7642,
Ubuntu 24.04, kernel 6.8, AES-NI), `fio` on raw devices plus `pgbench`,
3 runs per cell, medians, two instances per size with the variant order
swapped so host noise does not decide the result. The provider's disk
encryption was on for the LUKS runs (the stacked case); LUKS2
aes-xts-plain64, defaults unless stated.

- *The provider's own encryption* (enabled against disabled, 4 GB node): no
  measurable cost. The encrypted instance measured as fast or faster on
  every test; the difference is host noise, not a speedup. The provider's
  documentation publishes no figures.
- *LUKS on top (4 GB / 2 vCPU, 1 GB / 1 vCPU)*: large sequential reads fall
  about half or more (a node reading 3.5–5 GB/s plain reads about
  1.2 GB/s through LUKS; `cryptsetup benchmark` gives 1.3–1.7 GB/s for AES-XTS
  per instance, which is the ceiling). Postgres (`pgbench`, 8 clients) loses
  roughly 0–15%. Random 4K reads lose 3–40% (worse on the 1 GB node), random
  4K writes at queue depth 1 lose 13–31%. Rows where the two instances
  disagree in sign are noise and are not conclusions.
- *`--perf-no_read_workqueue --perf-no_write_workqueue`*: no consistent
  gain on either size; not part of the standard (D1).
- Backups read the mounted plaintext, unchanged. Grow works; shrink does
  not. Streaming work (backup, restore, large downloads) is where the cost
  shows; a database-shaped load pays the least.

**Unlock memory, measured on a 1 GB / 1 vCPU node with no swap**
(cryptsetup 2.7.0). Default `luksFormat` on that node chose 109 MB. A
header forced to 700 MB unlocked in 3.3 s with the warning "keyslot operation
could fail as it requires more than available memory", and with a 300 MB
process resident **cryptsetup was killed by the out-of-memory killer and the
volume did not open**. A forced 1 GiB header was refused at format time
(exit 3). 256 MiB opened in about 2 s idle and with load. The warning is
cryptsetup's own notice that the header's stored cost exceeds free physical
memory now; it is only a warning when memory is free, a failure when it is
not (cryptsetup issue 896; Debian bug 924560). argon2id exists to make
brute-forcing a passphrase expensive; a slot holding a random secret gains
nothing from a large cost. That led first to a fixed 256 MiB, and, once no
slot held a passphrase (D-H), to PBKDF2 at about one second (D1).

**Precedent**, for why warrant resistance is the vault's job and not this
spec's: Apple v FBI 2016 (withdrawn; no US precedent either way); Tutanota,
Cologne 2020 (upheld: ordered to add capture of future inbound plaintext mail
for two accounts — the shape of a hosted box); Hushmail 2007 and Lavabit 2013
(an operator-held key is a compellable key); UK IPA 2016 TCN on Apple ADP
(2025–26, unresolved) and Australia TOLA 2018 (both compel capability
building); CALEA 47 USC §1002(b)(3) — a carrier is not responsible for
decrypting what it lacks the means to decrypt. A key we escrow is a key we can
be ordered to use; a key only the user's unlock produces is not.
