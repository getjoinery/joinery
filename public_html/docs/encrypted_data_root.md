# Encrypted data root

A host's data root (`/srv/joinery`, see [Installation § The data root](installation.md#the-data-root)) can sit on a LUKS2 volume, so an offline copy of the machine's disk (a provider backup, a snapshot, a disk taken out of a data centre) holds its data only as ciphertext. This page covers what is encrypted, how the volume is opened at boot, who holds its keys, and the commands and files involved. The design and its reasons are in `specs/hosted_data_promise_disk_encryption.md`.

Nothing offers or creates an encrypted data root unless the core setting `data_root_encryption_offered` is on (off by default). Everything below acts only on a machine whose data root is encrypted; a plain data root behaves as Installation describes.

## What is encrypted, and what is not

The volume holds the data root, and on an encrypted machine these move onto it, each as a bind mount recorded in `/etc/joinery/data_binds`:

| Path | Place under `/srv/joinery` | Moved |
|---|---|---|
| each bare-metal site's `config/` (`Globalvars_site.php` included) | `sites/<site>/config` | before `_site_init.sh` writes it, or when `encrypt` finishes |
| `/var/log` | `log` | at `create --encrypted`, or when `encrypt` finishes |
| `/var/lib/php/sessions`, `/var/lib/php/tmp` | `php/sessions`, `php/tmp` | by the tick, once they exist |
| `/etc/rspamd/dkim` | `mail/dkim` | by the tick, once it exists |

`joinery_data_root.sh move-in REL TARGET` does each move with no moment the path is missing: it copies the folder's files to the place, mounts the place over the path, copies again anything written during the copy, then deletes the root disk's own copy through a private bind of `/` and leaves the path the locked mountpoint every bind leaves. The tick trims the root disk (`fstrim /`) after a move.

Also on an encrypted machine:

- **PHP's temporary files** go to `/var/lib/php/tmp` (`upload_tmp_dir`, `sys_temp_dir` in each PHP-FPM version's `conf.d/99-joinery-data-root.ini`).
- **`/tmp` is a tmpfs** from the next boot (`/etc/systemd/system/tmp.mount`, `size=50%`, enabled and not started).
- **Core dumps are off**: `DefaultLimitCORE=0` (`system.conf.d`), `Storage=none` (`coredump.conf.d`), `kernel.core_pattern=|/bin/false` (`sysctl.d/60-joinery-data-root.conf`).
- **A swap device that is neither dm-crypt nor compressed memory** is turned off by the tick. The host's own swap is compressed memory (zram, `host_swap.sh`), which never reaches a disk.
- **The agent's kept job results** go to `/srv/joinery/agent-outbox` while the data root is mounted, and to `/etc/joinery-agent/outbox` while it is not.
- **A container's `/tmp`** is in its writable layer, under Docker's data root (`/srv/joinery/docker`), so it is covered already.

**What stays on the root disk**, each with its reason, is listed on the node page's encryption card: TLS private keys (the unlock page needs them), the SSH host keys, the agent's and the unlock identity (needed before the unlock), the recovery recipient (public), and Postfix's database lookup password and TLS session cache (they open nothing without the database).

### `/var/log` and early boot

Two early-boot units need `/var/log` before any unlock can happen: `systemd-update-utmp` (the boot record in `wtmp`) and `systemd-journal-flush`. On an encrypted machine each runs from a copy of its package unit file in `/etc/systemd/system/`, regenerated each tick, without its `/var/log` mount requirement, which would otherwise be an ordering cycle with an unlock that waits for the network. The boot's flush carries `ConditionPathIsMountPoint=/var/log`, so the journal stays in memory (`RuntimeMaxUse=32M`) until `joinery-journal-flush.service` moves it onto the data root once `/var/log` is mounted. The root disk's `/var/log` underneath the bind stays writable for what early boot writes there; once each boot, after the unlock, the tick appends those files to the data root's logs, deletes them, and trims. rsyslog, fail2ban and sysstat wait for the data root, as the database and web stack do.

The host converger's path trigger (`joinery-host-converger.path`) is not ordered before `paths.target`, since its folder (`cache/root_requests`) is on the data root.

## Making one

- **Born encrypted.** `install.sh server` and `install.sh docker` take `--encrypted-data-root --recovery-public-key=KEY` (the base64 public key the Backups page shows). Where no agent is installed yet, the installer first places the release's own agent binary (`agent_dist/`, checked against its manifest) at `/usr/local/bin/joinery-agent`, with no unit and no identity: it seals the break-glass copy and opens the volume at every boot, including one before the site install. The agent's service comes with the site install, or at the end of `install.sh docker`. Provisioning in one of our cloud accounts passes both flags and creates the instance with the provider's disk encryption off (`cvp_data_root_encrypted`, see the server_manager overview, "One layer of encryption, never two").
- **In place.** `joinery_data_root.sh encrypt [--keep-provider-layer]` stops the data root's services for seconds, starts LUKS2's online encryption (`--reduce-device-size 32M`, the filesystem's 512-byte sectors), and brings them back; `joinery-data-encrypt.service` then encrypts at idle I/O priority for hours. The start goes ahead only once every mount of the data root's filesystem is gone: a service that keeps a file on one of its binds (rsyslog keeps its socket in Postfix's chroot) is stopped for those seconds, and anything else holding one refuses the start with nothing changed. Before the volume is touched, everything a recovery needs (the sealed copies, the record, the units) is synced to disk. A plain data root on a device keeps its last 32 MiB outside its filesystem for the header; one made before that needs its volume grown at the provider first. From the first stop to the last start, however the start ends (a refusal, a failure, a signal), it starts the data root and its services again on the volume as it then is, and leaves no keys or sealed copies behind; it never stops the service it runs in (a site's Backups page asks through the host converger, and the request lets go of its transcript, which is on the data root, for the start). A cut during the start, before any data is encrypted, is undone by the next tick (the data moved for the header goes back, and the data root mounts plain), and the tick starts a run that is recorded and not running. A tick that finds the data root not mounted with no run of the script holding its lock starts it again, and on a plain one removes keys and sealed copies a cut-off start left. A management node offers it on a node page (**Encrypt the data root**) for a machine in one of our accounts, once the node's recovery key is proven and a verified backup is under a day old; a self-hosted site offers it on its own Backups page, sealed to the site's recovery key.
- **Undo.** `joinery_data_root.sh decrypt` decrypts online from wherever it stands (**Decrypt (undo)** on the node page).

A reboot part-way through any of these opens with the recovery key, through the slot that drives the run, and the run resumes.

## Keys

- **The volume key** is random, 512 bits, and never written to a disk. Every open uses `--disable-keyring`, so it sits in the device-mapper table and root can read it from the open mapping (`dmsetup table --showkeys`).
- **Key slots** are PBKDF2-SHA256 at 1,000,000 iterations, about a second on a 1 GB machine (the 512-bit key takes two PBKDF2 blocks); `check` treats a PBKDF2-SHA256 slot as one the platform made only at a count some release has made its slots at, 1,000,000 or 2,000,000 (`LUKS_ITERATIONS_MADE`), so a slot added by hand at any other count is named as not ours. A machine with a TPM2 chip has a slot sealed to PCR 7; an escrow machine has a slot whose secret its holder keeps. Every enrolment prunes tokens whose slots are gone (`joinery-agent data-root prune-tokens`).
- **The break-glass copy** is the volume key sealed to a recovery public key (the backup envelope's format), beside the image (`/srv/joinery.img.recovery`). The key it is sealed to is in `/etc/joinery/data-root/recovery_recipient`, with who set it (`set_by=operator|site|install`). When the recipient changes, the tick seals the copy again from the open mapping and replaces it; a copy that has gone missing is made again the same way.
- **Whose key.** A machine in one of our cloud accounts is sealed to the management node's own recovery key, and the management node sends the current one to every such machine after a rotation (`DataRootEscrowSync`; a new key at once, the same key again at most hourly); its Backups page counts how many are sealed to it. A self-hosted machine is sealed to its site's own recovery key. A machine never takes back a key it was moved off: `recipient` records each key it replaces in `/etc/joinery/data-root/retired_recipients` and refuses those, so a management node rolled back to before a rotation cannot re-seal the fleet to the old key. A restore keeps the site's proven recovery key in any case (`restore_database.sh`).
- **A key that may have been seen.** `joinery_data_root.sh rekey` re-encrypts the volume under a new volume key, online, driven by a temporary slot sealed to the recovery key. When it finishes, the new break-glass copy replaces the old, the TPM2 chip is enrolled again, and the escrow slot is made again with a new secret: the machine proves the change to its holder with the old volume key's report key, kept meanwhile at `/srv/joinery/.joinery-rekey/`. A management node runs one at a time (**Re-encrypt under a new key…** on the node page); a self-hosted site starts it from its Backups page.

## Boot

`joinery-data-unlock.service` (the agent's `unlock` mode) opens the volume; `srv-joinery.mount` follows it, then `joinery-data.target` and every service that requires it. The agent daemon starts beside the unlock: a site whose `config/` is on an unmounted data root starts in site posture, waiting, and restarts to read its config within 15 seconds of the unlock. The host converger, `fix_permissions.sh` and the outbound limits wait for the data root too.

The unlock tries, in order:

1. **The TPM2 chip**, up to five times two seconds apart (a boot process extending a PCR during the unseal makes it refuse once, `TPM_RC_PCR_CHANGED`). A boot it did not open is recorded in `/run/joinery/data-root.tpm2-failed`, and once something else opens it the tick seals the chip's slot again to that boot's measurements.
2. **The escrow holder**, every ten seconds.
3. **The recovery key**: at the console, and on the machine's own unlock page on ports 80 and 443 (`503` with `X-Joinery-Locked`), at once where there is no holder and after a minute where there is. The holder's node page can also unlock it: the recovery key stays in the browser, which works out the one value that opens the break-glass copy.

What opened the last boot is in `/run/joinery/data-root.opened-by`. Planned reboots (unattended upgrades) are held until the holder's preflight says it would answer, at a minute of the day set by the machine's id (`/etc/apt/apt.conf.d/53joinery-encrypted-reboot`).

## The escrow holder

Usually the machine's management node; the two management nodes hold each other's. Requests go to `/api/v1/agent/escrow/{enrol,confirm,unlock,report,preflight,rekey}`, signed by the machine's unlock identity (Ed25519, `/etc/joinery/data-root/unlock_identity`, domain `joinery-unlock-v1`). Confirm, report, preflight and rekey also carry `X-Joinery-Unlock-Report-Mac`, an HMAC under a key derived from the volume key, so a copy of the root disk, which has the identity but not the volume, cannot change what the holder records.

- **Enrolment** is approved at once when the machine's node key vouches for it (its own management node), and by a person comparing fingerprints on the holder's dashboard otherwise; the dashboard names the machine by its site's domain and node (`joinery-agent data-root enrol --name` overrides). A holder takes new enrolments only where `data_root_encryption_offered` is on (`DataRootEscrow::takes_new_enrolments()`): none is made anywhere until the release signing keys are rotated. Enrolments made already keep answering.
- **The holder's own backup.** Rebuilt from a backup, a holder knows only the keys it held then. Within the hour of a change (an enrolment confirmed, a key changed or given up) it backs itself up (`EscrowHolderBackup`, the **Back Up After an Escrow Change** task, after ten minutes of quiet), and the node page of a machine enrolled since its newest offsite backup says so.
- **Unlock** is answered only from the machine's stated or seen addresses (IPv4 and IPv6, compared as addresses), once per boot.
- **Tier 2.** For a machine in one of our accounts whose key holds `events:read_only`, the holder reads the provider's activity log before it answers (`EscrowTier2`) and refuses a boot after a rescue boot, a root-password reset, a rebuild, a clone or image of its disks, a backup restored onto it, or an address taken away, or one asked from an address that is not its instance's. It reads the log twice, a few seconds apart, and refuses while the log cannot be read. A refused machine shows its event on the node page, with two ways on: **I did this myself** (recorded with the user's name, excusing every event up to then), or a copy from its backups onto a new server. A machine in its owner's own account stays at Tier 1, and its node page says why.

## On the site

The data root tick writes `/run/joinery/data-root.status` (world-readable, nothing secret) for the site to read (`DataRootStatus`). The Backups page shows whether the data root is encrypted, what opened it, and whose key its break-glass copy is sealed to, with advice when the copy is missing or sealed to a key the site has retired; the recovery key's card on Recovery Readiness says it opens the data root too; `VaultHealth` adds a row for the break-glass copy. The page asks root through `RootRequest` (`data_root_encrypt`, `data_root_reseal`, `data_root_rekey`), and root checks every condition again itself.

## Commands and files

`maintenance_scripts/install_tools/joinery_data_root.sh`, run from the site's tree by the host converger; an `encrypt`, `decrypt` or `rekey` run installs a copy at `/usr/local/sbin/joinery-data-root` for its unit:

| Command | What it does |
|---|---|
| `create --encrypted [SIZE] [DEVICE]` | Make the data root on a LUKS2 volume |
| `recipient [--set-by=site\|operator] KEY` | Set the recovery public key the break-glass copy is sealed to |
| `encrypt [--keep-provider-layer]`, `decrypt`, `rekey` | Encrypt in place, undo, re-encrypt under a new key; `encrypt-resume` is the run's own unit |
| `move-in REL TARGET` | Move a folder that holds data onto the data root where it stands |
| `status`, `check`, `tick` | As for any data root, through the mapping |

`/etc/joinery/data_root` records `backing=luks-file|luks-device`, `sidecar`, and while a run is part-way `reencrypt=encrypt|decrypt|reencrypt` with its `driving_slot` and `driving_sidecar`. `/etc/joinery/data-root/` holds the recipient, the unlock identity, the escrow holder (`holder`), the header backup and, during a decryption, the detached header.

The agent's words: `data_root_enrol`, `data_root_encrypt`, `data_root_decrypt`, `data_root_rekey` and `data_root_recipient` (operate; script words of `joinery_data_root.sh` except enrolment). `joinery-agent data-root` is the script's helper: `seal`, `unseal`, `reseal`, `slots`, `add-slot`, `prune-tokens`, `identity`, `enrol`, `report`, `preflight`, `report-key`, `escrow-rekey`.

Tests: `tests/integration/joinery_data_root_gate.sh`, `plugins/server_manager/tests/data_root_escrow_test.php`, `escrow_tier2_test.php`, `provision_one_layer_test.php`, `tests/vault/vault_health_test.php`, and the agent's `go test`.
