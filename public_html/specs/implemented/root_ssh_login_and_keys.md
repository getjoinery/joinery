# Root SSH Login and Keys — keyless by default, carried on request

Owner policy, 2026-10-07 (found asking whether the moved getjoinery was a no-root-access box). A
machine we create is keyless with root login off. That is a default, not an absolute: an existing
node keeps the keys it has, a copy can carry them, and an install can opt in to root login.

Status: built 2026-10-07; db --changed gate 247/247 green, host_report gate and the four suites above pass. The live copy of a keyed node is in the live verification queue.

## Behaviour

- **Default.** A new server has no root SSH keys and `PermitRootLogin no`. It is reached through its agent.
- **Install opt-in.** The install form has "Keep root SSH login (not recommended)" and a box for public
  keys, one per line. Ticked, the keys go on root and root keeps key login. A tick with no key, or a line
  that is not a plain public key, is refused.
- **Copy.** The Create-the-new-server form lists the source's root SSH keys by fingerprint and offers
  "Carry these SSH keys to the copy (not recommended)". The copy is refused if the source's keys changed
  since the form was drawn. A source with no keys, or one that has not reported them, offers nothing.
- **Restricted keys.** A key line with options (`command=`, `from=`, `restrict`) is listed with its
  fingerprint and never carried: carrying it without its options would widen what it allows.
- **The one-time root login.** The install and the retire step both run over the install password. Nothing
  changes SSH settings before the retire step. The retire step installs the keys, writes
  `/etc/ssh/sshd_config.d/00-joinery-agent-managed.conf` (password and keyboard-interactive off;
  `PermitRootLogin no`, or `prohibit-password` when keys exist), runs `sshd -t`, restarts sshd and checks
  the effective setting (`sshd -T` prints `prohibit-password` as `without-password`).
- **Not covered.** A copy onto a server the owner brings (its keys are the owner's); existing nodes are
  untouched until their next retire.

## Built (2026-10-07)

- `host_report.sh` 1.16: `root_ssh` (fingerprint, carry, type, key, comment per key; `HOST_REPORT_ROOT_SSH_DIR`
  test hook). `unknown` when unreadable by a non-root run, `[]` when root has none.
- `JobResultProcessor` 1.67: keeps `root_ssh`, re-checking every field on intake.
- `CustomerCloudProvision` 1.15: `cvp_root_ssh_keys`.
- `JobCommandBuilder` 1.104: `build_retire_install_password($node, $root_keys)`, `root_key_lines()`.
- `ProvisionCustomerCloud` 2.9 passes the provision's keys to the retire job.
- `SiteCopyRunner` 1.11: `source_root_keys()`, the fingerprint check at start.
- Copy tab 1.11, actions logic 1.48, install form 1.15. Server Manager 1.30.25.

## Tests

`host_report_gate.sh` (root_ssh), `job_command_builder` (keyless and keyed scripts, hostile line dropped),
`job_result_processor` (intake), `site_copy_runner` (tick, stale fingerprints).

## Open

- Live proof: a copy of a node that holds keys, once its host-report script is 1.16.
