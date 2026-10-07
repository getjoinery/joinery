# Encrypted data root: data encrypted at rest, with the key held off the machine

**Status:** DRAFT 2026-10-07, rewritten the same day from the owner's framing
(below). Decided beyond the framing: one standard LUKS configuration for
every node (D-E) and the provider's disk encryption off where ours is on
(D-F), both 2026-10-07 after measurement. Nothing built. **Stands on
`one_data_root`**, which must land first; every install shape then looks the
same to this spec. The research that led here (Linode rescue mode, the
precedents, the measured performance) is kept at the end.

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
- **Root login stays off.** Nothing here needs a login.
- What it does not promise is stated as plainly as what it does.

## What it buys, and what it does not

| Reader | Today | Encrypted data root |
|---|---|---|
| The hosting provider's backups, clones, images; a retired physical drive | plaintext | ciphertext — the key was never on the disk |
| A stolen cloud login that snapshots or clones the machine | plaintext | ciphertext |
| Rescue mode (the provider boots the machine's disks under another OS) | plaintext | ciphertext only with Tier 2 (below); Tier 1 is still readable this way, because the agent's credential is on the plain OS disk and the rescue VM has the machine's address |
| Us, with the agent, on the running machine | plaintext | plaintext — the volume is open while the machine runs, and we hold the escrow |
| A court order served on us | we can be made to comply | unchanged — that is the sealed vault's job, and the vault is unchanged by this spec |

Sealed-vault data is ciphertext before and after; this spec adds nothing to
it and takes nothing from it.

## The design

### D1. The data root is a LUKS2 volume

`one_data_root` makes the data root a filesystem of its own, in a fully
allocated file or on a device, mounted at `/srv/joinery`, with every consumer
ordered after `joinery-data.target`. This spec puts LUKS2 underneath that
filesystem. Nothing above the mount point changes: Postgres, Docker, the site
directories, the mail stack and the quota work see the same paths.

**Standard configuration — one for every node, whatever its size.**
`cryptsetup luksFormat --type luks2 --cipher aes-xts-plain64 --key-size 512
--pbkdf argon2id --pbkdf-memory 262144` on every slot, and nothing else
tuned: no `--perf-no_read_workqueue` / `--perf-no_write_workqueue` (measured
2026-10-07: no gain on 1 GB or 4 GB nodes, and worse on some random-write
runs). The memory cost is stated, never left to cryptsetup's own choice:
left alone it sizes the cost to the machine it runs on (109 MB on a 1 GB
node, up to 1 GiB on a big one) and writes that number into the header, and
a header made on a big node can be killed by the out-of-memory killer
when it is unlocked on a small one (reproduced on a 1 GB node, appendix).
256 MiB unlocks in about 2 s on the smallest plan we sell and costs nothing
a random 256-bit volume key needs. The same value applies on every plan, so
a machine can be resized, restored or cloned onto any plan and still open.
`joinery_data_root.sh create` grows an `--encrypted` option that does this
before formatting XFS; `check`, `grow` and `status` learn to look through
the mapping. `status` reports the header's memory cost and `check` fails a
volume whose cost exceeds 256 MiB. `--sector-size 4096` and `--allow-discards`
are carried over from the first draft and were not part of the 2026-10-07
measurements; WP1 proves them before they stay in the standard.

The unlock runs at step 3 of D4, before Postgres, Docker and the web stack
start, so it never competes with them for memory.

### D1a. Provider disk encryption is off where this is on

A Linode instance is created with the provider's "Local Disk Encryption"
**disabled** whenever its data root is an encrypted volume of this spec
(`disk_encryption: disabled` on the create call; it is enabled by default and
cannot be changed afterward without a rebuild, so it is decided at birth).
The provider's layer is held under keys the provider controls, is readable
in rescue mode, and does not cover backups, so under our volume it protects
nothing the volume does not. A machine with encryption **off** (D2, last row)
keeps the provider's default. We provision only in core regions, never the provider's distributed (edge) regions, where it cannot be disabled. The
provider's layer was measured to cost nothing noticeable (appendix), so
turning it off is not about speed: it is about not keeping a layer with no
purpose, and about one fewer thing to explain to a customer.

### D2. One volume, several keys: which slots are cut for which install

The volume's own key is random, 256 bits, generated at `create`, and is never
written to the machine's disks. What is enrolled are the ways to reach it:

| Install | Slot: normal boot | Break-glass |
|---|---|---|
| Hosted (our Linode, any shape) | **escrow** — held by the management node | the volume key sealed to the operator's recovery key (ours) |
| Self-hosted, managed by us | **escrow** — held by the management node | the volume key sealed to the site owner's recovery key |
| Self-hosted, unmanaged | **TPM2** where the hardware has one, else a passphrase at the console | the volume key sealed to the site owner's recovery key |
| Off | — | — |

Escrow, TPM2 and passphrase are LUKS2 key slots. **The break-glass copy is
not a slot.** It is the volume key sealed to the owner's existing
`backup_recovery_public_key`, exactly as a backup's envelope key is
(`docs/backups.md`, `backup_envelope.php`), written as a sidecar beside the
volume (`/srv/joinery.img.recovery`, or next to the device's fstab entry).
Opening it is `backup_envelope.php open --private recovery.key` and then
`cryptsetup open --key-file`. The owner therefore holds the one recovery key
they already hold, and the volume never contains anything derived from it.

For a hosted machine "the owner" is the operator: the management node's own
site recovery key, which we keep. The backup rule that no single key opens a
fleet still holds — the sealed copies are per machine; only the recipient is
shared.

### D3. The escrow slot

The one key source this platform adds. The management node keeps one random
escrow secret per machine, sealed under its `SecretBox` like every other
secret in its database, minted at `create` and sent once to enrol the slot.
At boot, a small unit, `joinery-data-unlock.service`, ordered after the agent
and before the mapping, asks the management node for the machine's escrow
secret over the agent's existing channel and hands it to
`systemd-cryptsetup` as a key file on `/run` (memory-backed, root-only, gone
at reboot). The volume opens; `joinery-data.target` is reached; the consumers
start.

`crypttab` names the key file with a timeout and a console-password fallback,
so a machine whose management node does not answer waits, then offers the
console prompt — the break-glass path, which needs no login.

**Tier 1** is D1–D3 as written. The management node answers every
authenticated request.

**Tier 2** adds the refusal: the management node does not answer a machine
whose most recent provider event is a rescue boot, a root-password reset or a
clone (`linode_rescue`, `linode_reboot` from rescue, `linode_clone`), or whose
instance id is not the one enrolled. This is the piece that closes rescue
mode, and it is a policy on the escrow endpoint and nothing else: no change on
the machine. Its own cost is the event-feed latency window; its own work is
reading the provider's event feed, which `test_cloud_account_and_prod_management`
touches. Tier 2 is a separate work package and may land never.

### D4. Boot, with root login off

1. The OS boots from the plain disk.
2. The agent starts (plain disk; needs nothing from the data root).
3. `joinery-data-unlock.service` obtains the key: escrow via the agent, or
   the TPM2 slot, or the console passphrase.
4. The mapping opens; the XFS mounts; `joinery-data.target` is reached.
5. Postgres, Docker, the web stack, the mail stack start.

Until step 4 the machine serves nothing. On a multi-tenant host that is the
"host starting" state the suspended page already has a slot for; on a bare
metal site the web server is simply not up yet. No step is a login: the agent
is a root service, the console prompt is systemd's, before any login exists,
and creating or migrating a volume is root work done the way root work is
done now — a job the agent runs, or `sudo` from the operator's own account.

### D5. Existing machines

`one_data_root`'s `migrate` already copies data into a new data root and
switches consumers over. With `--encrypted` the new root is created under
LUKS first; the copy and the switch are the same procedure, with the same
downtime. Turning encryption *off* is the same procedure in reverse. Nothing
is re-encrypted in place.

### D6. What is claimed

Customer-facing: *"Your data is encrypted at rest. The key is held off the
machine, not by the hosting provider, so a copy of the disk — a backup, a
snapshot, a clone, a retired drive — is unreadable."* With Tier 2: *"…and so
is the machine booted under someone else's control."* Not claimed: anything
about us, or about a court order served on us; that sentence belongs to the
vault and to `release_transparency`.

## Work packages

- **WP1 — The volume.** `joinery_data_root.sh create --encrypted`, LUKS2
  with the flags of D1, the random volume key, the TPM2 and passphrase slots,
  the sealed break-glass sidecar. `check`/`grow`/`status` through the
  mapping. Proven on a scratch box: create, reboot, open by TPM2 / passphrase,
  open by the recovery key with the other slots removed.
- **WP2 — The escrow.** Management node: a per-machine escrow secret, sealed,
  minted at `create`, an endpoint that answers the enrolled machine over the
  agent channel. Machine: `joinery-data-unlock.service` and the `crypttab`
  line with the console fallback. Proven: reboot with the management node up
  (hands-off), reboot with it down (console prompt, break-glass opens it).
- **WP3 — Install shapes.** `install.sh`: on by default on a hosted birth
  (host provisioning), offered on a self-hosted install; `migrate --encrypted`
  for existing machines. Hosted birth passes `disk_encryption: disabled` to
  the provider (D1a) when the volume is encrypted: a `disk_encryption` option
  on `CloudComputeProvider::createInstance()`, honoured by
  `LinodeComputeDriver` and ignored by drivers whose provider has no such
  setting. Proven on one of each shape, including a 1 GB node booted and
  unlocked under the standard configuration.
- **WP4 — The customer's view.** The site's and the host's pages say whether
  the data root is encrypted, which slot opened it at the last boot, and that
  the recovery key on file can open it; `VaultHealth`-style advice when the
  break-glass sidecar is missing or sealed to a retired recovery key.
- **WP5 — Tier 2.** The provider-event refusal on the escrow endpoint.
  Separate; decided separately.

## Decisions

- **D-A. Reuse the owner's recovery key for break-glass; never a new
  human-held key** (owner, 2026-10-07).
- **D-B. One volume key, one escrow secret per machine, held by the
  management node.** Splitting the key across peer machines' memory so it
  exists nowhere at rest was **rejected by the owner 2026-10-07** as too large
  a risk. Do not re-propose.
- **D-C. Tier 2 is separate.** Tier 1 is the feature; Tier 2 is a policy with
  its own dependency (the provider event feed) and its own window.
- **D-E. One standard LUKS configuration for every node** (owner, 2026-10-07):
  LUKS2, aes-xts-plain64, argon2id at 256 MiB, no workqueue flags (D1). Not
  chosen per plan size.
- **D-F. The provider's disk encryption is turned off on nodes that use our
  volume** (owner, 2026-10-07; D1a), and left at the provider default on
  nodes that do not.
- **D-D. Warrant resistance is out of scope.** The vault is the answer to
  that question; this spec does not pretend otherwise.

## Open items

- **O1.** Which business data is outside the vault on a hosted site — the
  inventory that says what this feature actually protects.
- **O2.** The console fallback's timeout: how long a hosted machine waits for
  the management node before it offers the prompt.
- **O3.** Whether the escrow request carries a boot nonce the management node
  records, so Tier 2 can also refuse a second boot that arrives while a first
  is still answered.
- **O4 — DECIDED 2026-10-07: A now, B′ when the second account lands.**
  The management node cannot escrow its key to itself, and it must not stay
  unencrypted: its database holds every machine's sealed escrow secret and
  its `SecretBox` key is on its plain root disk, so an unencrypted plane
  makes a copy of one disk the key to the fleet. Its own volume is opened by:
  **A — a console passphrase at boot**, systemd's standard prompt on the
  serial console (`console=ttyS0`), the same on every provider and on bare
  metal; only how a person reaches the console is the provider's (Lish, the
  EC2 or GCP serial console, VNC, IPMI), and the platform builds none of
  that. The management node is excluded from automatic reboots; the
  passphrase lives in the owner's password manager. A is also every
  machine's break-glass path, so it costs nothing extra.
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
- **O5.** Everything the agent needs before the volume opens — its credential,
  its local queue, the host state — must stay on the root disk, never under
  `/srv/joinery`. This settles `one_data_root` O2: host state stays on the
  root disk. The boot-time fetch is a one-shot run of the agent binary, not a
  call into the long-running daemon, so a dead daemon is not a dead boot; a
  machine leaving the plane enrols a TPM2 or passphrase slot before it leaves,
  because after that only the recovery key opens it.

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
brute-forcing a passphrase expensive; a random 256-bit volume key gains
nothing from a large cost, hence the fixed 256 MiB in D1.

**Precedent**, for why warrant resistance is the vault's job and not this
spec's: Apple v FBI 2016 (withdrawn; no US precedent either way); Tutanota,
Cologne 2020 (upheld: ordered to add capture of future inbound plaintext mail
for two accounts — the shape of a hosted box); Hushmail 2007 and Lavabit 2013
(an operator-held key is a compellable key); UK IPA 2016 TCN on Apple ADP
(2025–26, unresolved) and Australia TOLA 2018 (both compel capability
building); CALEA 47 USC §1002(b)(3) — a carrier is not responsible for
decrypting what it lacks the means to decrypt. A key we escrow is a key we can
be ordered to use; a key only the user's unlock produces is not.
