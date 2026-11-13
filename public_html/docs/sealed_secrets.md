# Sealed Secrets — registry, reconciliation, and health

Every value [SecretBox](secret_box.md) seals is bound to one install's
`secret_box_key`. Copy or seed a database into an environment whose key differs,
or rotate the key, and *every* sealed value becomes undecryptable at once. This
subsystem makes that a diagnosed, reconciled condition instead of a string of
unrelated feature failures.

Three parts: a **registry** of every kind of sealed value, a **reconciler** that
checks their health and acts by category, and the **surfaces** that tell an
operator what needs a human.

## The registry

Each kind of sealed value is declared once, in a `sealed_secrets` array in
`settings.json` (core) or a plugin's `plugin.json`. A declaration is a
**category**, not one secret — "IMAP account passwords" is one entry for all
accounts. Fields:

| field | meaning |
|---|---|
| `locator` | **Required, and the entry's id.** Where the secret lives, code-free: a setting name for a singleton (`file_signed_url_key`), or `table.column` for a row-scoped kind (`iia_inbound_imap_accounts.iia_password_enc`). Enough to count with no plugin code present. |
| `label`, `feature` | Human names for the health surface. |
| `kind` | `operator` \| `regenerable` \| `regenerable-breaks-things` \| `ephemeral` (below). |
| `reprovision` | For `regenerable`: `Class::method` that mints a fresh one. Optional on `regenerable-breaks-things` (used only by the operator-acknowledged re-mint). |
| `enumerator` | Optional, for a row-scoped kind whose column wraps the blob (a jsonb `{"enc":...}` envelope): `Class::method` returning each `['ref'=>…, 'blob'=>…]`. Used only when the owning code is loaded; the locator is the floor that always works. |

**Secret settings are sealed secrets without an entry.** Every setting
declared `secret: true` (in `settings.json`, a `plugin.json` or a sandboxed
plugin's manifest) is stored sealed under its own name
([Settings § Credentials](settings.md#credentials)), and is declared here
implicitly: an `operator` secret labelled from its setting
(`SealedSecretsDeclarations::all()` marks it `_implicit`). An explicit entry
for the same name (an OAuth client secret, `services_api_secret_key`) wins
and keeps its own kind. A sealed value that its consumer opens itself, and
that is no form field, is declared explicitly and is not `secret: true`
(`joinery_direct_decoy_secret`).

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
- **Durable memory** — the reconciler's orphan detection — reads the
  **seeded table** `ssr_sealed_secret_registry`, mirrored from the manifests on
  each `update_database` (`SealedSecretRegistry::seed_from_manifests()`). A table
  row outlives a deleted plugin's `plugin.json`, so an orphaned sealed value can
  still be counted. **A table row whose locator matches no on-disk
  manifest is the orphan signal.**

## The reconciler

`SecretReconciler::reconcile()` walks the registry, checks each secret's health
through `open()`, and acts by kind: heals `regenerable`, flags `operator` and
`regenerable-breaks-things`, discards dead `ephemeral`. It runs as the last step
of `update_database` (post-deploy — never `upgrade.php`'s pre-deploy pass, which
runs against the old core) and on demand from the health page.

**Plaintext is sealed.** A cold pass also seals any value of a setting or a bare
column that is still plaintext (a value written before its locator was
declared), and counts it: the summary in `update_database`'s output reads
`healed 0, needs attention: 0, sealed 6 plaintext`. A wrapped column (one with an
enumerator) is left to its consumer, which seals it on its next save.

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

**The read-only count.** `SecretReconciler::census()` walks the same registry and
returns the canary's state with the number of stored and dead secrets, by
locator, and writes nothing: no heal, no cached verdict, no signal. The site
census (`SiteCensus`, see [Backups](backups.md)) uses it to check a restored copy
of a site against its source.

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

## Replacing them after a break-in

PHP holds `secret_box_key` so it can use what it seals, so code that breaks in
as the web user can open every stored secret. After a break-in each one has to
be treated as read and replaced. The **Replace after a break-in** box on
`/admin/admin_sealed_secrets` (`SecretReplacementList`) turns the registry into
that list:

- **Make new ones here:** `regenerable` and `regenerable-breaks-things` kinds,
  the second marked with what replacing it cuts off. A kind held in one setting
  with a `reprovision` recipe gets a *Make a new one* button
  (`SecretReconciler::acknowledge_remint()`), which succeeds only if a new value
  is stored. A recipe provisions what is missing, so a kind held in rows is
  replaced on its own feature's page.
- **Get new ones from the provider:** `operator` kinds, implicit `secret: true`
  settings among them. Make a new one in the provider's console, revoke the old
  one there, and enter the new one on its page.
- **Nothing to do:** `ephemeral` kinds, listed so the list is complete.

Only kinds with a value stored here appear. Opening the list records a short
hash of every stored value (`SecretReconciler::present_hashes()`, which opens
nothing) in its own table, `srs_secret_replacement_snapshots`, so a
one-per-member kind does not swell the settings every request reads. A re-seal
always changes the stored blob, so each row shows how many values are not yet
replaced, and a kind is marked done once none of its values from the opening is
left. A kind this admin cannot replace (registrant contact details, a customer
server's password, a disk key in escrow) is closed without replacing, with a
reason typed in; the reason and the count left unchanged stay on the row. The
list closes once every kind to act on is done, and its snapshot goes with it;
while it is open, an admin notice keeps it in view. Its state is the managed
setting `secret_replacement_list`, changed under a lock on its row. A
one-per-member kind says that replacing it means each member sets theirs up
again, and a kind whose plugin is switched off says so. The Security sentinel's alarm on the
management node links to the list on the node.

The box also names the rest of a recovery, which is not stored secrets: change
the database password; redeploy the code; sign everyone out and have admins
change passwords; review what could have been added (admins, permissions, API
keys, passkeys, app passwords, second factors, forwarding, webhooks, scheduled
tasks); and members whose vault was open rotate their vault key.
`secret_box_key` and `config/backup_site_key` cannot be replaced from the site,
so a value replaced on the list is sealed under the same key an intruder may
have copied.

## A database on another machine

A restore onto **another machine** by default keeps nothing
sealed either. The backup carries the source's `config/`, but the restore keeps the
target machine's own `config/Globalvars_site.php`, and with it the target's own
`secret_box_key`. Every value sealed on the source is therefore **dead** on the
target: the canary fails, the reconciler raises one batched key-mismatch alert,
`regenerable` values are minted again (a new Joinery Direct signing key no longer
matches its published DNS record, and outstanding signed file links stop
working), and every `operator` value is entered again. A restore on the same
machine is unaffected: the key is the same.

A chain restore with `restore_chain.sh --adopt-secret-key` keeps nothing dead: the
target keeps its own config and takes the source's `secret_box_key` into it, so
every value sealed on the source opens on the target, the canary included. This is
how a copy of a site onto new hardware keeps its secrets (the `copy_restore` agent
word always passes it).

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
