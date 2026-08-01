# Key Management: Inventory, Simplification, and Backup Recoverability

**Status:** Draft — awaiting owner decisions (see Open Decisions)
**Date:** 2026-08-01

## Problem

The platform has accumulated keys faster than custody discipline: four private key files in `config/` with four different generation paths and no unified rotation or inventory surface, a per-node backup key with its own escrow machinery, ~25 credential settings stored plaintext in the database, plaintext TOTP secrets, and no single place an operator can see what keys exist and what happens if each is lost.

Two findings raise this from tidiness to urgency:

1. **The project backup tarball is unencrypted.** `backup_project.sh` encrypts only the nested database dump; the outer `.tar.gz` — uploaded to offsite buckets — is plain gzip and contains the entire `config/` directory: `secret_box_key`, the database password, the agent signing key, the provisioning SSH key, and the relay pull key. Anyone with bucket read access holds the server's entire secret root in cleartext. The documented threat model ("a stolen bucket yields only sealed blobs") holds for `.sql.gz.enc` and `escrow/*.sealed` — not for `.tar.gz`.
2. **The backup retrieval chain has an undocumented escape hatch and a circular dependency.** Bucket endpoint and credentials are SecretBox-sealed in the database by a key that lives in a config file whose only offsite copy is inside the backup itself. Recovery today works only because (a) the tarball is unencrypted (the hole above) and (b) the operator can mint fresh bucket credentials from the provider console — a fact recorded nowhere.

## Goals

1. **Two root secrets, everything else recoverable.** After this spec: the operator's recovery private key (password manager) and the per-site `secret_box_key` are the only irreplaceable secrets, and the recovery key alone is sufficient to retrieve and decrypt any backup.
2. Every secret at rest in the database or bucket is encrypted — no plaintext credentials in a DB dump or bucket object.
3. One admin surface inventories the platform's keys, their custody state, and their rotation actions.
4. A written, tested disaster-recovery card exists outside the platform.

## Relationship to Other Specs

`specs/backups_core_and_incremental.md` Phase 1 (envelope encryption: per-backup data key sealed to the operator recovery public key + a disposable site key) is the mechanism this spec relies on for backup artifacts. That spec owns the backup pipeline changes; this spec owns everything else about keys and defines the end-state custody model both must satisfy. Build order: Phase A here (DR card) → backups Phase 1 (envelope, which also encrypts the tarball) → remaining phases here in any order.

---

## Key Inventory

Grouped by custody root. "In backup scope" = captured by `backup_project.sh` (config/, public_html/, uploads/, static_files/, maintenance_scripts/ + the DB dump).

### A. Operator-held roots (password manager / offline)

| Key | Type | Used for | Loss impact |
|---|---|---|---|
| Escrow recovery keypair (private half) | X25519; public half in setting `server_manager_escrow_public_key`, honored only after the prove-possession ceremony (`server_manager_escrow_public_key_proven_fpr`) | Unsealing escrowed node backup keys (`bke_backup_key_escrow` rows + `escrow/{slug}/{fpr}.sealed` bucket replicas) via `escrow_keypair.php unseal`; becomes the universal backup recipient under the envelope model | **Irreplaceable.** Today: every escrowed node backup key → every `.sql.gz.enc` unrecoverable. End state: every backup unrecoverable without the site copy |
| Provider console logins (B2/S3/Linode, registrars, Stripe…) | Account credentials | Minting fresh bucket credentials — the only non-circular path back into a bucket after total server loss | Recovery blocked at the "fetch" step |

### B. Server root (per site)

| Key | Where | Used for | Rotation | Loss impact |
|---|---|---|---|---|
| `secret_box_key` | `config/Globalvars_site.php` (plaintext PHP config; minted by `_site_init.sh`, self-healed by `SecretBox::ensureConfigKey()`) | Root for all SecretBox blobs: backup target credentials (`bkt_credentials`), OAuth client secrets, customer-cloud OAuth tokens, IMAP passwords, relay transport secret key, signed-URL key, device-link secrets, GetJoinery API secret | **None** — no re-encryption tooling; docs forbid casual change | Every SecretBox blob permanently undecryptable — including bucket credentials |

### C. Per-node / fleet keys

| Key | Where | Used for | Rotation | Loss impact |
|---|---|---|---|---|
| Node backup key | `~/.joinery_backup_key` on each node (0600, no-clobber); escrowed to the recovery key before first use | Passphrase for `openssl enc` of DB dumps | Escrow model supports it (`bke_source='rotated'`); no action in code | Escrowed: none (unseal blob). Unescrowed: that node's `.sql.gz.enc` dead. **Retired by the envelope model** |
| Agent signing key | `config/agent_signing_key` (Ed25519, 0600); escrowed as `bke_kind='agent_signing'` | Signs agent release binaries — fleet trust root; deployed agents pin the public key | **None** | Cannot ship agent updates until every node re-keyed. Leak = fleet-wide RCE |
| Node admin SSH key | Outside site tree (operator `$HOME`); path in `mgn_ssh_key_path` | All management jobs; encrypted B2 copy exists (see memory/runbooks) | Manual | Fleet unmanageable until key restored/replaced |
| Provisioning SSH key | `config/provisioning_key` (0640) | Sole root access to provisioned customer-cloud instances | None | Provisioned instances unreachable (no stored root passwords) |
| Relay pull key | `config/relay_pull_key` (0600) | Mail relay spool pull | None | Relay pull down until re-keyed |
| Agent API credential pair | `mgn_api_public_key` / `mgn_api_secret_key` — **plaintext in DB** | Agent HTTP auth | Re-mint | Re-mintable |
| `config/cloudflare_dns_token` | Plaintext file, **zero code references** | Nothing (orphaned) | — | None — delete it |

### D. Per-user keys (already well-designed; no structural change)

| Key | Custody | Notes |
|---|---|---|
| Sealed Vault keypairs (`user`, `drive`, `passwords` scopes) | Secret half never stored unwrapped; wrappings per unlocker (passkey PRF / recovery codes / passphrase); full rotation ceremony with generation counters | Losing all unlockers = permanent loss, stated at opt-in. Correct by design |
| Passkey credentials | Private keys in authenticator hardware only | Revocation-veto prevents stranding a vault |
| DKIM private keys | Sealed to the owner's `user`-scope vault; staged rotation | Correct by design |
| 2FA backup codes | Argon2id-hashed | Correct |
| `usr_second_factor_hmac_key` | Plaintext per-user DB column; rotation IS trusted-device revocation | Acceptable (signing key, not a confidentiality key) |
| **`usr_totp_secret`** | **Plaintext DB column** | **Defect — see Phase C**: the highest-value plaintext in every DB dump (2FA bypass for all users) |

### E. Plaintext credential settings (defect class)

`SettingsDeclarations::isSecret()` controls UI masking only — it does **not** encrypt. Stored plaintext in `stg_settings` and therefore in every DB dump: Stripe secret keys + webhook secret, PayPal secrets, Apple App Store private key (PEM), cloud storage S3 keys, all ESP credentials (SMTP, SendGrid, Mailgun, Mailjet, Brevo, Postmark, Resend, SES, Mailchimp), `mailbox_srs_secret`, mailbox fleet API secret, DNS filtering API keys, all joinery_ai provider keys. Only OAuth2 client secrets, `file_signed_url_key`, and the GetJoinery API secret are actually SecretBox'd today.

### F. Ephemeral / correctly scoped (no action)

Session/CSRF tokens (per-session, no persistent secret), API key secrets (hashed at rest, shown once), device-link and app-bridge tokens (TTL'd, hashed or sealed), DNS write credentials (deliberately ephemeral per `docs/dns_management.md`), WireGuard private keys (never leave the relay), SSL private keys (certbot-owned, re-issuable, not backed up by design).

---

## Backup Retrieval: Failure Modes

Scenario: the server is gone; the operator has the offsite bucket and their password manager.

| # | Failure mode | Severity | Fixed by |
|---|---|---|---|
| 1 | **Recovery private key lost** → every escrowed backup key (and, post-envelope, every backup) unrecoverable | Irreplaceable root — accepted by design | DR card names it + verify step; consider a second sealed copy (Open Decision 3) |
| 2 | **Bucket location/credentials circularity**: endpoint + creds are SecretBox-sealed in the DB; `secret_box_key` is in a config file whose only offsite copy is inside the backup | Hard blocker once the tarball is encrypted | DR card records provider, bucket names, endpoints, and the fresh-credentials-from-console procedure |
| 3 | **Tarball unencrypted** (inverse failure: retrieval possible for an attacker) — bucket read access yields `secret_box_key` + all `config/` keys in cleartext | Critical exposure | Envelope-encrypt the whole artifact set (backups spec Phase 1) |
| 4 | **Escrow blob missing**: `replicateBlob()` is best-effort (failure only `error_log`s); if the control plane dies and the replica never landed, the node key is gone with it | Real until envelope model retires blobs | Interim: make replication failure fail the backup job loudly (Phase A); envelope model removes the side-channel entirely (sealed keys travel inside the artifact) |
| 5 | **Possession never proven / escrow unset**: encrypted backups refuse to run (correct), but pre-existing node keys sit unescrowed | Guard works; gap is legacy keys | Registry page (Phase D) surfaces unescrowed keys |
| 6 | **`escrow_keypair.php` only ships inside the backup** — standalone (PHP + sodium) so any surviving copy works, but nothing guarantees one exists off-server | Soft circularity | DR card includes the unseal tool (or its ~150-line procedure) |
| 7 | **`vendor/` and SSL excluded from backups** | Minor — `composer.lock` is in the archive; certs re-issue | Documented restore steps |
| 8 | **`secret_box_key` lost** (config corrupted, key rotated by hand) | Today: recoverable from any unencrypted tarball (the hole). Post-envelope: every SecretBox blob dead, but backups still open via the recovery recipient | DR card stores `secret_box_key` per site (Open Decision 3); restore of `config/` from a backup also recovers it |

End-state chain (after envelope + DR card): `password manager → provider console → fresh bucket creds → fetch chain manifest + artifacts → unseal data key with recovery private key → decrypt everything → restore`. One root secret, no circularity, no side-channel escrow store.

---

## Phases

### Phase A — DR card + loud escrow (no code risk, do first)

1. **Standing re-verify action** — owned by `specs/recovery_readiness.md` (the Recovery Readiness page's verify tool for the recovery key).
2. **Recovery card generator** — candidate to fold into the Recovery Readiness page (its Open Decision 2); requirements stay as written here: a printable/copyable recovery sheet: provider + bucket + endpoint + path prefix per target, node slugs, the fresh-credentials-from-console procedure, the recovery public key fingerprint, the unseal procedure, and per-site `secret_box_key` **only if the operator opts in** (Open Decision 3). Stored nowhere server-side; generated on demand for the operator to file in the password manager.
3. **Make escrow replication failures fail the backup job** (interim until the envelope model lands): `replicateBlob()` failure becomes a job failure, not a log line.
4. Delete `config/cloudflare_dns_token` (orphaned, zero references) after owner confirms it's not used manually.
5. Pin `config/Globalvars_site.php` and `config/` to 0640/0750 in `fix_permissions.sh` (currently world-writable on dev; the file holds `secret_box_key` and the DB password).

### Phase B — Encrypt-at-rest sweep for declared secrets

Make `isSecret()` mean encrypted, not just masked:

1. `Setting` write path SecretBox-seals values whose declaration says `secret`; read path transparently unseals (blob prefix `v1.` distinguishes sealed from legacy plaintext, so mixed states read correctly during migration).
2. One-time migration (via `update_database`) re-writes existing plaintext secret settings sealed.
3. `usr_totp_secret` moves to SecretBox at rest (seal on write, unseal in the TOTP verify path; migration sweeps existing rows).
4. Explicitly out: values that must stay plaintext for external consumers (none identified — DKIM public DNS etc. are already public halves).

This collapses inventory section E into dependency on `secret_box_key` — acceptable because `config/` rides inside (soon-encrypted) backups and the DR card covers the key.

### Phase C — Backup envelope model

Owned by `specs/backups_core_and_incremental.md` Phase 1; requirements this spec adds to it:

- The **entire artifact set** (project tarball, DB dump, manifest sidecars) is envelope-encrypted — closing finding 3, not just restructuring keys.
- Sealed data keys travel inside the artifact set; `bke_backup_key_escrow`, blob replication, and `~/.joinery_backup_key` retire as load-bearing pieces (legacy unseal path kept as a documented manual procedure for pre-envelope archives).
- The recovery keypair + possession ceremony move to core and remain the gate: no proven recovery key → no encrypted backups.

### Phase D — Key registry surface

One superadmin page (core): every platform-level key from the inventory above — name, purpose in plain language, where it lives, custody state (escrowed / operator-held / disposable), last rotation, and the rotation action where one exists. Data driven by a small registry (core + plugin contributions), not hand-maintained prose. This is where "unescrowed legacy node key," "agent signing key not escrowed," or "recovery key possession unproven" become visible warnings instead of tribal knowledge. Guided controls, no explainer essays (docs carry the doctrine).

### Later / Out of Scope

- `secret_box_key` rotation tooling (re-encrypt-everything pass). With Phases A–C the key is recoverable and its blob population is enumerable (the registry), so rotation becomes tractable — but it's not needed for the two-roots goal.
- Agent signing key rotation (requires fleet re-key choreography; registry surfaces its custody state meanwhile).
- Per-user vault custody (already correct).
- Consolidating the four `config/` key files into one store — churn without a security gain; they're recoverable via encrypted backups after Phase C.

## Testing

- Phase A: recovery-card render includes every enabled target; escrow-replication failure fails the job (extend `backup_key_escrow_test.php`).
- Phase B: seal/unseal round-trip per secret setting; mixed plaintext/sealed read during migration; TOTP verify against sealed secrets; `password_field_no_value_test.php` inventory updated.
- Phase C: covered by the backups spec gates, plus: bucket-only DR drill — from a clean machine with only the recovery private key and provider console access, retrieve and fully restore a site (this drill is the acceptance test for the whole spec).
- Phase D: registry lists every key the inventory names; warning states render for unescrowed/unproven conditions.

## Documentation (update at build time, current-state only)

- New `docs/key_management.md`: custody model, the two roots, inventory table, DR procedure.
- `docs/secret_box.md`: secret-settings sealing semantics.
- `docs/settings.md`: `secret` declaration now implies encryption at rest.
- Backups docs per the backups spec; `docs/account_security.md` TOTP storage note.

## Open Decisions

1. **Phase B scope**: seal all `isSecret()` settings in one migration (recommended), or exclude any the owner wants greppable in the DB?
2. **`usr_totp_secret` sealing** — recommended yes; confirm (it invalidates any external tooling that reads the column directly).
3. **DR card contents**: include per-site `secret_box_key` on the card (single sheet recovers everything, but the sheet becomes more sensitive), or exclude it (config recovery then depends on restoring a backup first)? Recommended: include, since the card lives in the password manager next to the recovery key anyway.
4. **Second recovery-key copy**: accept the single password-manager copy, or add one offline copy (printed/HSM/safe)? The key is the one true SPOF by design.
5. Confirm deletion of `config/cloudflare_dns_token`.
