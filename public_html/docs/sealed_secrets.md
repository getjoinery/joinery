# Sealed Secrets — registry, reconciliation, and health

Every value [SecretBox](secret_box.md) seals is bound to one install's
`secret_box_key`. Copy or seed a database into an environment whose key differs,
or rotate the key, and *every* sealed value becomes undecryptable at once. This
subsystem makes that a diagnosed, reconciled condition instead of a string of
unrelated feature failures.

Three parts: a **registry** of every kind of sealed value, a **reconciler** that
checks their health and acts by category, and the **surfaces** that tell an
operator what needs a human — plus a **scrub** that keeps a copied database from
inheriting foreign ciphertext.

## The registry

Each kind of sealed value is declared once, in a `sealed_secrets` array in
`settings.json` (core) or a plugin's `plugin.json`. A declaration is a
**category**, not one secret — "IMAP account passwords" is one entry for all
accounts. Fields:

| field | meaning |
|---|---|
| `locator` | **Required, and the entry's id.** Where the secret lives, code-free: a setting name for a singleton (`file_signed_url_key`), or `table.column` for a row-scoped kind (`iia_inbound_imap_accounts.iia_password_enc`). Enough to count and scrub with no plugin code present. |
| `label`, `feature` | Human names for the health surface. |
| `kind` | `operator` \| `regenerable` \| `regenerable-breaks-things` \| `ephemeral` (below). |
| `reprovision` | For `regenerable`: `Class::method` that mints a fresh one. Optional on `regenerable-breaks-things` (used only by the operator-acknowledged re-mint). |
| `enumerator` | Optional, for a row-scoped kind whose column wraps the blob (a jsonb `{"enc":...}` envelope): `Class::method` returning each `['ref'=>…, 'blob'=>…]`. Used only when the owning code is loaded; the locator is the floor that always works. |

`sealed_secrets` is **not** a setting's `secret:true` flag. `secret:true` only
masks a form field; it says nothing about encryption at rest (`hcaptcha_private`
is masked yet stored plaintext). The two answer different questions, so sealing
gets its own declaration.

### The four kinds

- **`operator`** — a human entered it (an OAuth client secret, an IMAP password);
  the machine cannot regenerate it. A dead one is flagged for re-entry, never touched.
- **`regenerable`** — the machine re-mints it with no consequence (the file-URL
  signing key: re-minting only invalidates already-expiring signed URLs). A dead
  one is auto-healed, silently.
- **`regenerable-breaks-things`** — the machine *can* re-mint, but doing so
  silently damages live state (unpairs devices, drops peers pinned to a Joinery
  Direct identity). Never auto-healed; flagged, and re-minted only on an explicit
  operator acknowledgement.
- **`ephemeral`** — a per-run value (a relay provisioning token, a wizard stash).
  A dead one is just discarded, never healed, flagged, or alerted.

### Enforcement and durable memory — two readers, two sources

- **Enforcement** — `SecretBox::seal()` refusing an unregistered locator — reads
  the **on-disk manifests** (every `plugin.json`, active or not), never the
  database. So it works the instant new code lands, with no dependency on a seed
  having run. `SealedSecretsDeclarations::isDeclared()` is the check.
- **Durable memory** — the reconciler's orphan detection and the scrub — reads the
  **seeded table** `ssr_sealed_secret_registry`, mirrored from the manifests on
  each `update_database` (`SealedSecretRegistry::seed_from_manifests()`). A table
  row outlives a deleted plugin's `plugin.json`, so an orphaned sealed value can
  still be counted and scrubbed. **A table row whose locator matches no on-disk
  manifest is the orphan signal.**

## The reconciler

`SecretReconciler::reconcile()` walks the registry, checks each secret's health
through `open()`, and acts by kind: heals `regenerable`, flags `operator` and
`regenerable-breaks-things`, discards dead `ephemeral`. It runs as the last step
of `update_database` (post-deploy — never `upgrade.php`'s pre-deploy pass, which
runs against the old core) and on demand from the health page.

**Heal is cold-only.** Re-minting writes a fresh SecretBox blob — a long
non-vault string — and `SealedEgressGuard` refuses exactly that write once a
request has opened sealed content. The reconciler runs cold, so its heals are
safe; a hot request that finds a `regenerable` secret dead treats it as absent
and lets the next cold pass mint it.

**The key canary.** A known constant sealed at key-mint time
(`secret_box_canary`). A wrong key and a bit-flip both surface as the same
authentication failure, so on a mass failure the canary is what separates "one
secret is corrupt" (canary still opens) from "the key is wrong, everything is
dead" (canary itself fails). It gives the reconciler a one-read mass-mismatch
verdict.

**The cached verdict.** The reconciler writes each category's aggregate state
back to its registry row (`ssr_last_state`, `ssr_dead_count`).
`SecretReconciler::attention_verdict()` reads that — no live decrypt walk — so the
setup pill and the management-node stats blob share one cheap computation.

## Telling the operator

- **Push, not a page to patrol.** A dead secret a human must act on raises the
  `secret.unreadable` signal ([Notify](email_system.md) / signals), which lands a
  persistent in-system notification (the reliable leg — the email path can itself
  depend on a dead credential) and, opt-in, an email. Raised **once** on the
  transition into dead, batched into a single alert on a mass event — never once
  per reconcile run.
- **The pill.** A site-scoped setup step (`sealed_secrets`) is amber while a
  secret needs attention and **absent entirely** when everything opens — off until
  there is something to see. It reads the cached verdict.
- **The health page** `/admin/admin_sealed_secrets` is the alert's destination:
  it lists the dead secrets and carries the actions — re-enter an `operator`
  credential, or acknowledge a destructive re-mint (typed confirmation, with the
  consequence named) — plus "reconcile now". Empty and silent when all is well.
- **Fleet roll-up.** A managed node reports **counts only** (`dead_operator`,
  `dead_needs_ack`) in the `/api/v1/management/stats` blob it already serves — no
  value or ciphertext ever crosses. The management node folds it with per-key
  provenance, ambers the node badge, and links **to the node** to fix it (the
  management node never holds the node's keys).

## Prevention — scrub on copy

`utils/scrub_sealed_secrets.php` nulls every sealed value at its declared locator,
driven by the registry table that travels **inside the dump**, so it needs no
plugin code and no key. It runs on **import** — `_site_init.sh` calls it after a
`clone_export` restore — because the export is a passthru `pg_dump` pipeline with
no seam to scrub, and must never `UPDATE` the source. The copy lands **clean**
(every sealed value `absent` = "not configured") rather than dead. A genuine
*move* is restore-from-backup, which carries `config/` and the matching key, so
nothing is scrubbed there.

## Adding a sealed secret

1. Seal and read through `seal($locator, …)` / `open($stored)` — never raw
   `encrypt()`/`decrypt()` (a grep test enforces this).
2. Add a `sealed_secrets` entry naming the `locator`, `kind`, `label`, `feature`,
   and — for a `regenerable` — its `reprovision` recipe. Without it, `seal()`
   refuses the value.
3. If the column wraps the blob (a jsonb envelope), add an `enumerator` so the
   reconciler can reach the blob; a bare-blob column needs none.

That is the whole contract: the omission fails the moment the secret is added, not
months later when a database moves.
