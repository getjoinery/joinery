# Per-Channel Release Signing (A14)

**Status: APPROVED, UNBUILT.** Owner decision 2026-08-28, recorded as A14 of
`specs/implemented/agent_on_node_architecture.md`; this spec carries the work.
Scheduled after the transport migration's cutover
(`specs/agent_machine_posture_and_relay_converge.md` R5) and **before any
external machine follows the stable channel**.

## The decision

Whoever holds the release-signing key can ship code every agent installs as
root — the one game-over row in the architecture spec's §3.7 trust table. Today
one key signs everything and lives on the publishing box, which is an
internet-facing website. The decision: **two keys, custody proportional to
blast radius.**

- **Nightly key** — stays on the publishing box, today's one-click flow
  unchanged. A compromise of that box signs only what the operator's own fleet
  installs.
- **Stable key** — the signature customer and beta machines require. Generated
  on and never leaving a non-internet-facing machine (the Mac mini), stored
  passphrase-protected, decrypted only at signing time: a stolen key file is
  ciphertext, and an attacker must be resident and capturing during an actual
  release ceremony on a machine with no inbound path. A VPS was considered for
  custody and rejected — internet-facing by definition, and the provider's
  hypervisor access makes a provider account the real custodian.

Agent binaries bake **both** public keys, channel-bound: a machine following
the stable channel refuses anything not stable-signed; nightly machines accept
the nightly key. This shrinks the §3.7 signing-key row rather than shuffling
trust between rows, which is that table's bar for new security machinery.

## The ceremony

Cutting a stable release: the publishing box builds exactly as it does today;
the custody machine signs the **same bytes** (no rebuild — the publish
integrity guards keep pinning the tree hash); the signed release goes to the
distribution plane. Friction lands only on stable releases, which are
infrequent by definition. Nightlies stay one click.

## Transition and what rides along

- **One nightly first ships binaries that bake the stable public key.** Agents
  trust only their baked-in keys, so the new key arrives via a release signed
  by the current one — which is also the first half of the key-rotation
  machinery the architecture spec records as unbuilt.
- **Signed release archives** belong to this work: `upgrade.php` today
  re-executes its own freshly-deployed copy unverified (the manifest gate is
  entry-point-only), and platform/plugin upgrade archives carry no signature.
  Closing that is a natural consumer of the same signing path.
- **Custody backup:** an encrypted copy of the stable key rides offsite under
  the same pattern as the current key; the passphrase lives only in the
  owner's password manager.
