# Sealed Settings Reconciliation — systemic handling of env-bound secrets

**Status: IMPLEMENTED 2026-08-29.** Built, fable-code-reviewed (all findings
resolved), and runtime-verified: `update_database` on dev created the registry,
seeded 23 categories, minted the canary, and reconciled; the end-to-end model
test passes 23/23. Written after a live finding on developers.getjoinery.com:
`update_database` reported *"File signing key provisioning failed: SecretBox:
decryption failed (tampered or wrong key)."* The one-row fix (delete
`file_signed_url_key` so it re-mints) is real, but the owner's call is correct —
this is a systemic gap, not a one-off.

A third, adversarial review pass against the actual sources found three places
the earlier "zero open questions" claim was false, each now resolved with the
owner:

- **Heal happens only on a cold read (B1).** The lazy heal-on-read the earlier
  draft mandated is refused by `SealedEgressGuard` in exactly the motivating
  request (one that has opened sealed content). A hot request now treats a dead
  free-to-heal secret as absent; the reconciler and the next cold read mint it.
  The egress guard is not touched. Legacy plaintext (B3) rides the same rule.
- **Scrub happens on import, not export (B2).** `clone_export` is a straight
  `pg_dump | gzip | openssl` passthru with no seam to scrub mid-stream, so the
  clean copy is produced by scrubbing sealed values on **import**, driven by the
  seeded registry table that travels inside the dump.
- **The declaration is a `sealed_secrets` block (N5).** `secret:true` in a
  manifest means *mask in the UI*, not *sealed at rest* (e.g. `hcaptcha_private`
  is masked but stored plaintext), so the registry gets its own declaration: one
  `sealed_secrets` list per manifest naming every category, singleton and
  row-scoped alike.

With those folded in it is ready to implement.

## The problem in plain terms

Some settings are secrets — OAuth tokens, storage-bucket credentials, signing
keys. The platform encrypts them at rest with **SecretBox**, whose key lives in
each install's `config/Globalvars_site.php` (`secret_box_key`, 32 random bytes,
minted per install). So **every encrypted value in the database only opens with
that one install's key**.

Copy or seed a database into an environment whose `secret_box_key` differs — or
regenerate the key — and *every* encrypted value becomes undecryptable at once.
Nothing detects this, nothing reconciles it, and the failures surface one at a
time, in whatever feature next reaches for a secret: a broken signed URL here, a
backup that can't read its bucket credential there, an OAuth connection that
silently stops working. That is how developers got here: its database carries
ciphertext sealed to a key it no longer has.

## Why it matters (the blast radius)

SecretBox protects, today, at least:

- **Operator-provided — cannot be regenerated, must be re-entered by a human:**
  - OAuth access/refresh tokens **and** provider client secrets
    (`OAuth2ProviderConfig`, the per-provider classes)
  - Backup-target bucket credentials (`backup_target_class`)
  - Registrar / domain API credentials (`registered_domains_class`)
  - IMAP account passwords (mailbox)
  - Inbound/provider webhook secrets where sealed
- **Regenerable, no side effects — the machine re-mints it and nothing notices:**
  - File-URL signing key (`file_signed_url_key`, `files_class`) — re-minting only
    invalidates outstanding short-lived signed URLs, which expire anyway
- **Regenerable, but re-minting breaks something — the machine *can* make a new
  one, but doing so silently damages live state, so it must never auto-heal:**
  - Joinery Direct identity (`joinery_direct/DirectIdentity`, `DirectSettings`)
    — every peer pinned to the old identity stops trusting this instance
  - Device-link secrets (`device_links_class`) — every paired device is
    silently unpaired

These three need **different** handling, which is the heart of this spec. The
important line is not regenerable-vs-not; it is **can this heal itself with no
consequences, or does healing cause the next incident.**

## What is wrong with today's handling

1. **No consistent failure contract.** `SecretBox::decrypt()` always throws a
   `RuntimeException`. Each consumer copes differently — `backup_target` (v2.2)
   deliberately fails loud and has a "configured but unreadable" surface;
   `OAuth2ProviderConfig` catches and degrades; `files_class` throws and took
   down a step of `update_database`. There is no shared rule for "this secret
   cannot be read here."
2. **No detection or inventory.** Nothing enumerates the sealed values and tells
   an operator which are dead. The knowledge is scattered across consumers and
   only appears when a feature breaks.
3. **No scrub when a database lands in a mismatched-key environment.**
   `clone_export.php` streams a full `pg_dump` (all settings, ciphertext
   included) and nothing clears env-bound secrets on the way in. A copied or
   seeded database carries foreign ciphertext that then fails unpredictably.

## Design

### 1. A registry of sealed values

One declared list of every *kind* of secret SecretBox protects. Each entry is a
**category**, not one secret — "IMAP account passwords" is one entry even though
there are many of them, one per account. So an entry has to answer two questions
the reconciler will ask: *how do I find every secret of this kind*, and *what do
I do when one is dead*.

Each entry carries:

- a human **label** and the **feature** it belongs to (e.g. "Google OAuth
  client secret" / "Sign-in with Google") — for the operator health panel
- **where they live — a plain, declarative locator: a table + column** (and for
  a singleton, the setting name is the location). This is the part that gets
  **persisted**, and it is deliberately code-free: `stg_settings.stg_value` for
  the file-signing key, `iem_account.iem_password` for IMAP passwords. It is
  enough to *count* and *scrub* every secret of the kind with no plugin code
  present — which is exactly what an orphan check after a plugin is deleted
  needs (see below).
- **optionally, a richer enumerator function** for a full reconcile while the
  owning code *is* present — e.g. one that returns each live `iem_account` so
  the reconciler can check and re-provision per row. This lives in code, is not
  persisted, and is only used when the plugin is installed. The declarative
  locator is the floor that always works; the enumerator is the ceiling that
  works when the code is loaded.
- **what kind it is:** `operator`, `regenerable`, or `regenerable-breaks-things`
  (the three categories above).
- for `regenerable` only, **how to make a fresh one** — the existing
  `File::provisionSigningKey()` call is the model. `operator` and
  `regenerable-breaks-things` entries have no auto-heal recipe on purpose.

**Where it is declared — a `sealed_secrets` block, distinct from `secret:true`.**
`secret:true` on a setting is a UI flag: it tells the settings form to mask the
field (`SettingsFieldRenderer`, `SettingsDeclarations::isSecret()`). It does *not*
mean the value is SecretBox-sealed at rest — `hcaptcha_private` is `secret:true`
yet stored and read as plaintext. Masked-in-form and sealed-on-disk are different
properties, so the registry gets its own declaration rather than overloading
`secret:true` (which would both mint phantom categories for masked-but-plaintext
values and still be unable to express secrets that are not settings at all).

Each manifest — `settings.json` for core, each `plugin.json` for a plugin —
carries **one `sealed_secrets` array** naming every category it owns. One uniform
shape covers both storage layouts, because the locator is the only thing that
differs: a **singleton** secret gives a setting name; a **row-scoped** secret
(there is no setting for these — an IMAP password is one row per account) gives
`table.column`. Everything the reconciler, the seeded table, and the import scrub
walk is this single list:

```
"sealed_secrets": [
  { "label": "File URL signing key",  "feature": "Signed file links",
    "kind": "regenerable", "locator": "file_signed_url_key",
    "reprovision": "File::provisionSigningKey" },
  { "label": "Google OAuth secret",   "feature": "Sign in with Google",
    "kind": "operator",    "locator": "oauth_google_client_secret" },
  { "label": "IMAP account password", "feature": "Mailbox IMAP",
    "kind": "operator",    "locator": "iem_account.iem_password",
    "enumerator": "MailboxAccount::eachSealedRow" }
]
```

A singleton operator secret is therefore named in two places — its ordinary
setting entry (which keeps `secret:true` for masking) and its `sealed_secrets`
entry (which declares kind and locator) — and that is correct: the two entries
answer two different questions. The `locator` is the code-free part that persists
into the seeded table (§1, durable memory); the optional `enumerator` is the
richer per-row function used only when the owning code is loaded.

The registry has **two jobs that read from two different places**, and keeping
them apart is what avoids a deploy-ordering trap:

- **Enforcement** — `seal()` refusing an unregistered name — reads the **on-disk
  manifests** (`settings.json` at the `public_html/` root, and **every on-disk
  `plugin.json`, active or not** — not the active set in `plg_plugins`), **never
  the database.** Reading every on-disk manifest keeps enforcement code-free and
  DB-free; it costs nothing, because an inactive plugin's classes do not resolve
  and so cannot reach `seal()` anyway, so the extra declarations are inert. The
  manifest is in sync with the code
  that calls `seal()` by construction: new code that adds a `seal()` call ships
  its declaration in the *same* deploy. So `seal()` works the instant the code
  lands — on a fresh install minting its own first secrets, during plugin
  activation, in the two-pass upgrade window — with no dependency on
  `update_database` having run yet.
- **Durable memory** — the reconciler's orphan detection and scrub — reads the
  **seeded table**, which the code-side manifests seed into on each
  `update_database` (the way plugin settings seed into `stg_settings`).

The seeded table earns its keep for one specific reason: **a plugin deleted from
disk takes its `plugin.json` with it.** Enforcement no longer cares (nothing is
sealing for a plugin that is gone), but the plugin's *existing* sealed rows are
still in the database. A table row — carrying the declarative locator — outlives
the plugin's files, so the reconciler can still count those orphans and the
scrub-on-copy step (§5) can still clear them. This is why the persisted locator
must be the code-free table+column form (previous bullet): an enumerator
function would vanish with the deleted plugin, degrading the row to a label that
can find nothing — reopening the very hole the persistence exists to close. A
row in the table with no matching manifest on disk *is* the orphan signal.

**The teeth go in `SecretBox` itself, not in a wrapper around it.** `seal()`
takes a registered category name and refuses one it does not recognize — checked
against the **on-disk manifests**, not the database (that separation, above, is
what lets it work before `update_database` has ever run) — exactly how
`Setting::put()` refuses an undeclared setting name. A wrapper a dev can route
around is only advice; a `seal()` that will not encrypt under an unknown name
makes the declaration load-bearing. You cannot seal a value the registry does
not know about, so the omission fails the moment someone adds a secret — not
months later when a database moves. A grep-based callsite test (the same
pattern `tests/unit/core_api_mechanical_test.php` already uses to enumerate
`server_initiated_write()` callers) fails CI on any direct raw-encrypt call that
tries to slip past the registered path.

### 2. A uniform decrypt-failure contract

`SecretBox` gains a decrypt path that **never throws into the caller** and
reports one of **four** distinct outcomes — the three-way live/dead/absent answer
is the part that matters, and collapsing it is a bug; the fourth exists because
legacy plaintext is real in production:

- **ok** — here is the secret.
- **absent** — no secret was ever set here. The feature is simply not configured.
- **dead** — a secret *is* stored, but it cannot be decrypted with this install's
  key (moved database, rotated key). Something is here and it is broken.
- **plaintext** — a value is stored but it is not a SecretBox blob at all
  (`SecretBox::looksEncrypted()` is false). Many consumers migrate values lazily
  without a flag column (`backup_target`, the OAuth providers,
  `registered_domains`), so an unencrypted-but-present value is neither ok, dead,
  nor absent. It reads as **ok** to the feature and is opportunistically resealed
  — but that reseal is a >64-char write, so it obeys the **B1 rule**: seal it on
  a **cold read** or in the reconciler, never inside a hot sealed-content
  request. The health panel counts a plaintext value as healthy (nothing is
  broken), not as a fault.

If **absent** and **dead** collapse into one "no value" answer (e.g. both return
`null`), the reconciler cannot tell "the operator needs to re-enter this" from
"this was never set up," and the health panel shows green over a dead secret —
it lies in the safe-looking direction, which is the worst direction. Keeping the
three apart is what lets the panel say *re-enter this* for a dead operator secret
and stay silent for one that was never configured.

The path distinguishes only what the cipher actually lets it distinguish:
*structural malformation* (wrong part count, bad base64, truncation, unknown
algorithm tag) is separable, but a **wrong key** and an **in-place bit flip**
both surface as the same authentication failure — secretbox/AES-GCM cannot tell
them apart. So the contract does not claim to. To recover the one distinction
that matters operationally — *this one secret is corrupt* vs *every secret is
dead because the key is wrong* — the design seals a **known canary constant** at
key-mint time: on a mass failure, if the canary still opens, the key is fine and
the individual value is corrupt; if the canary itself fails, it is a key
mismatch. The canary also gives the reconciler a cheap one-read verdict for the
batched "N secrets unreadable — environment key mismatch" alert (§4), instead of
inferring it from counting individual deaths. The outcome is recorded against the
registry entry for the health surface below. Consumers stop
calling raw `decrypt()` for registered values and read this instead — a feature
that gets **dead** treats it the same as **absent** (reports itself not
configured) and never crashes.

`backup_target`'s existing fail-loud behavior is preserved as its *surface*
(the operator sees "credentials cannot be decrypted"), but the mechanism moves
under the shared contract so it reads the same as every other sealed value.

### 3. Reconciliation

A reconciler (run on demand, and as a step of `update_database`, replacing the
current ad-hoc `provisionSigningKey` call) walks the registry, uses each entry's
"how to find them" to check every secret of that kind, and acts on the **dead**
ones by category:

- **`regenerable` + dead → auto-heal, on a cold read only.** Clear the dead value
  and call its re-provision recipe. This generalizes what `file_signed_url_key`
  already documents: deleting the row mints a fresh key, invalidating only what
  that key protected (short-lived URLs that expire anyway). No operator action, no
  crash. **The heal fires from the reconciler and from a cold read — never inside
  a hot sealed-content request.** The reason is a hard constraint, not a
  preference: `SealedEgressGuard` refuses any write of a long non-vault blob once
  a request has opened sealed content, and a fresh SecretBox key blob is exactly
  that — this is *why* `File::provisionSigningKey()` already pre-mints in
  `update_database` rather than on read. `server_initiated_write()` does not lift
  the egress guard (it only lifts the GET-mutation check), and adding SecretBox
  prefixes to the guard's allowlist is rejected: it would open a laundering
  channel a hot process could write sealed-derived plaintext through, against the
  guard's no-exemption doctrine. So the contract is: **a hot request that finds
  this secret dead treats it as absent** (the feature reports itself
  unconfigured for that one request) and the mint happens on the next cold read
  or the next reconciler pass. The signing key re-mint only invalidates
  already-expiring URLs, so the brief unconfigured window is harmless. The cold
  heal is still a write during a page view, so it goes through
  `SystemBase::server_initiated_write()` (whose own listed example is "a row
  reconciled") and adds an entry to that method's caller-enumeration test.
- **`regenerable-breaks-things` + dead → flag and wait for an explicit OK.**
  The machine *could* mint a new one, but doing so silently breaks live state —
  unpairs every device, drops every pinned Direct peer. So the reconciler never
  re-mints this on its own; it surfaces it on the health panel with the
  consequence spelled out and re-mints only when an operator acknowledges it.
  Auto-healing this category is how a reconciler *causes* the next incident.
- **`operator` + dead → flag, do not touch.** The value cannot be regenerated at
  all; overwriting it would destroy the only record that it was ever set. Mark
  it "needs re-entry" and let the feature report itself unconfigured.

### 4. Telling the operator — push, not a page to patrol

A health page nobody visits is worthless: when it is fine it says nothing, so
nobody opens it, so when it is *not* fine nobody sees it either. The design is
therefore **push a notification for the residue that a human must act on, and
show nothing for anything the machine already handled.**

The split by category:

- **`regenerable` (no side effects) → auto-heal silently, no surface at all.**
  The machine fixed it with zero consequence; announcing "we fixed this" is
  noise that reads like a confession. It goes to the log, nowhere else.
- **`operator` + dead → alert.** The machine *cannot* fix this — the value only
  exists in a human's hands (Google issued the client secret; the box never knew
  it). This is precisely the case that has to reach a person.
- **`regenerable-breaks-things` + dead → alert.** The machine could re-mint but
  the fix destroys live state, so it needs one human decision. Also has to reach
  a person.

**Where the alert goes: the existing `Notify` signal system.** The reconciler
raises a declared signal (`secret.unreadable`, say) whose payload carries the
feature label and a link to the fix. `Notify` already fans a signal out to an
**in-system notification** (persistent, sits in the operator's notification list
until dealt with) and, with `default_email` on for this signal, a **queued
email**. That is exactly "loud but not show-stopping": it waits for the operator
and mails them, and nothing blocks a page load or throws an interstitial. No
new alerting channel is invented — secrets health rides the rail the platform
already uses to tell an admin something needs attention.

**The in-system leg is the reliable one; email is the bonus.** The motivating
incident is a key mismatch that kills *every* sealed value at once — and the
outbound-mail path can itself depend on a sealed credential, so the email leg
may be dead in exactly the incident it exists for. The persistent in-system
notification does not depend on any secret, so it is the leg that must always
land. To make it findable without waiting for an email, reuse the **existing
setup-wizard pill** (`SetupSteps`, `specs/setup_wizard.md`) rather than inventing
a new indicator: register a **site-scoped step** that is `amber` while any secret is dead and
whose `active` callable is *false when every secret opens*, so the step is absent
entirely when there is nothing wrong. Its verdict must **not** be recomputed by a
full decrypt-walk on every admin request — that walk includes per-row kinds
(every `iem_account`), whose row fetches are not free on a mail-heavy site.
Instead `status()` reads a **cached verdict** that the reconciler writes and that
the same dead→alive/alive→dead transition tracking the alert dedup already
maintains (§4, "Dedup") keeps current. It is kept live by those transitions, not
stored-and-forgotten: real state still wins, at no per-request decrypt cost. It carries no `decision` (you cannot say
"not now" to a broken credential; real state always wins). This gives the "off
until there is something to see" badge for free, on a convention operators
already know, and the admin dashboard's setup band surfaces it with no extra
work. One copy nuance to settle: the pill reads "Finish setup — n of m", which
is slightly off for a fault that appears long after setup finished — the band's
wording for an amber-fault step may want to differ from an incomplete-onboarding
one.

**Dedup — one alert per problem, not per cron run.** A key mismatch makes a dozen
categories dead simultaneously, and the reconciler runs on a schedule, so naïve
per-value signals would mail the operator ~12+ times *every run, forever,* until
they act. Instead: raise `secret.unreadable` **once per (category, instance) on
the transition into dead**, clear it on heal or re-entry, and **batch a mass
event into a single "N secrets unreadable" alert.** A value that is dead and
stays dead never re-mails; only a *new* death or a *clear* is an event.

**The "page" demotes to the alert's destination, not a dashboard.** The
notification and the email both link to a small admin surface (in the settings
area, beside where you would re-enter the credential anyway) that lists the
currently-dead secrets and carries the actions: *re-enter this* (an `operator`
value, linking to its settings form) and *re-mint — this will unpair N devices,
confirm?* (a `regenerable-breaks-things` value, with the consequence named). It
exists to *act on* an alert you were already sent — not to be checked when
everything is fine. When everything is fine it is empty and silent.

**`update_database` also prints a one-line summary** of what the reconciler did
on that run ("healed 1, needs attention: 2") — transient, for whoever is
watching that run, not a substitute for the push.

A dead secret belonging to a **deactivated (not deleted) plugin** is raised at
**low severity** — in-system notification but no email, and no page badge:
nothing is breaking while the feature is off, but the dead secret is still there
and will bite on reactivation, so it is worth a quiet line, not a loud one.

`backup_target`'s existing "configured but unreadable" surface is folded into
this — it becomes an `operator`-kind alert like any other, rather than its own
one-off treatment.

**Rolling up to the management node — through the status blob it already polls.**
A node managed by a management node must surface a dead secret *up* to the fleet
view, so a fleet operator sees "node X has an unreadable credential" without
signing into the node. This does **not** need a new channel: the management node
already calls **`/api/v1/management/stats`** on each node and folds the returned
status blob into `mgn_last_status_data` (the same blob that already carries disk,
uptime, `backup_recovery_state`, and per-profile `backups`). So the roll-up is:

- **Node side:** the stats blob (`includes/management_api/stats_handler.php`)
  gains a `sealed_secrets` block — *counts and kinds only*, read from the same
  cached verdict that drives the local pill: e.g. `{ dead_operator: N,
  dead_needs_ack: N }`. **No secret value or ciphertext ever crosses** — only the
  health verdict. It follows the handler's existing nested-block convention (the
  per-profile `backups` map, `backup_recovery_state`): each is built inside its
  own try / `Throwable`-swallow so a node that has not built the registry yet
  answers normally with the key absent, and an old management node ignores a key
  it does not know. One computation, two consumers (the local `SetupSteps` pill
  and this blob).
- **Transport:** it rides the existing fold (`JobResultProcessor::fold_status_data`)
  with per-key provenance, so the fleet view also gets an "as of" time for free.
  A node that is not managed simply is never polled — nothing special-cased; the
  node always fills the field, only a management node ever reads it.
- **Management side:** `status_color_for_node` (on `JobCommandBuilder`, called
  from `plugins/server_manager/views/admin/index.php`) factors a dead `operator` /
  `needs-ack` secret into the node's badge (amber), and the overview tab /
  `NodeMonitorHealth` render "N secrets unreadable" with a link **to the node** —
  because the fix (re-enter the operator secret, ack the destructive re-mint)
  happens *on the node*. The management node deliberately never holds the node's
  keys (agent-migration doctrine: the plane never supplies keys), so the fleet
  surface is *notify-and-link*, never remote-fix.

The core per-install surface stays the source of truth; this is a read-only feed
*from* it *into* `server_manager`, which is why the core surface does not belong
in the plugin.

### 5. Prevention — scrub on database copy

The paths that land a database into an environment that will not hold the
matching key must scrub sealed values so the target starts clean. The scrub
happens **on import, not export.** `handle_database_export` in
`utils/clone_export.php` is a straight `pg_dump | gzip | openssl` passthru of the
live database — there is no seam in that pipeline to drop a row, and the export
must not `UPDATE` the source. The importer, by contrast, has the whole database
in hand and can clear rows before anything reads them:

- **`clone_export` import**: after the dump is restored, the importer walks the
  **seeded registry table — which travelled inside the dump itself** — and nulls
  every sealed value at each declared `locator`. This works with no plugin code
  present because the locator is the code-free `table.column` / setting-name form
  (§1), which is the entire reason it is persisted. The copy lands **clean**
  (every sealed value now `absent` = "not configured") rather than dead. The dump
  is already encrypted in transit under the clone key, so the source ciphertext
  it briefly carries is never exposed in the clear — which is why scrubbing at the
  destination, not the source, is safe. (A genuine *move* is restore-from-backup,
  which carries `config/` and the matching key, so nothing is scrubbed there —
  see Decided.)
- **Minimal test-DB seed** and any "seed from another site" path: run the same
  import-side scrub, so a test/dev database never inherits foreign secrets. (This
  is the most likely origin of the developers state.)

### 6. Migration to the teeth

The teeth in `seal()` change an existing platform, so the cutover has to be
handled, not just switched on:

- **Seed the registry from a grep of real callsites, not this spec's list.** The
  "Why it matters" inventory is illustrative and known to be incomplete — the
  cutover sweep must be built from an actual grep of every `SecretBox` seal/open
  callsite, then a `sealed_secrets` declaration written for each. Callsites the
  review surfaced beyond the inventory include `mailbox_relay_class`,
  `relay_cloud_provision_class`, `ImapConnectStash` (mailbox) and
  `customer_cloud_account_class`, `ProvisioningSetup` (server_manager). Some of
  these are **ephemeral per-run values** (an `ImapConnectStash` hand-off, a relay
  provisioning key) that fit none of the three long-lived kinds: a fourth
  disposition, **`ephemeral` — a dead one is simply discarded, never healed,
  flagged, or alerted.** Anything genuinely transient is declared `ephemeral` so
  it neither triggers an alert nor lingers as a false fault.
- **Direct `SecretBox` callers break loudly — that is the point.** Any code
  (including a third-party plugin) that still calls raw encrypt after the teeth
  land will be refused for using an unregistered name. This is the intended
  signal, not a regression, but it needs a **release note** so an operator
  upgrading a plugin that seals its own secret knows to add the declaration.
- **No table-seeding ordering trap.** Because `seal()` enforces against the
  on-disk manifest, not the seeded table (§1), there is no "seed the table first"
  step and no window where `seal()` refuses a name the code legitimately uses:
  the declaration ships with the code that calls `seal()`. The table seeds
  whenever `update_database` next runs; nothing waits on it. On a fresh install,
  the box mints its own first secrets (signing key, Direct identity) against the
  manifest that shipped in the same build — no dependency on a migration order.
- **The reconciler runs only from `update_database`'s post-deploy step chain —
  never earlier.** `update_database.php` is one of the four self-updating
  deployment files that run against the *old* core during `upgrade.php`'s
  pre-deploy pass, so anything it calls in that window must already exist on the
  old code. Its SecretBox steps (`SecretBox::ensureConfigKey`,
  `File::provisionSigningKey`) already run *after* file deploy, and the
  reconciler must join them there — in the post-deploy step chain the existing
  provisioning call lives in, never wired into `upgrade.php`'s pre-deploy pass.
  Stated so nobody moves it earlier and wedges an upgrading node.

## Decided (previously open)

- **Registry mechanism:** a declared list, persisted into a seeded table, with
  the teeth in `SecretBox::seal()` itself. The reconciler and health panel must
  be exhaustive, and self-registration can only ever see code that loaded this
  request — so an inactive plugin's dead secret would read as all-clear.
- **Row-scoped vs singleton values:** one `sealed_secrets` list holds both (N5,
  §1). The entry is category-level and carries its own locator — a setting name
  for a singleton, a `table.column` (plus an optional per-row `enumerator`) for a
  many-rows kind. No separate declaration site or shape for the two layouts.
- **`update_database` coupling: auto.** Reconciliation runs as a step of every
  `update_database` (formalizing the ad-hoc `provisionSigningKey` call it already
  replaces), so a dead secret can never sit undetected the way developers' did.
  The old fear — unattended surprise re-mints — is already answered by the
  three-way split: only the no-side-effect kind auto-heals; `operator` and
  `regenerable-breaks-things` are flag-only and touch nothing without a human. An
  explicit "reconcile now" action is *also* kept, not as the trigger but as a
  convenience so an operator who just re-entered a secret can clear its alert
  without waiting for the next run.

- **Clone policy: the clone import scrubs; a move restores from backup.** The
  clone/move distinction already maps onto two existing tools, so no mode flag is
  needed. A full **backup carries `config/`** (including `secret_box_key`), so
  restore-from-backup is the genuine *move* path — it brings the key and sealed
  values keep working, already correct. **`clone_export`** streams the database
  only (no `config/`), so it is inherently a *copy into a different environment*
  and lands dead ciphertext unless cleaned. Because the export is a passthru
  `pg_dump` pipeline with no seam to edit, **the scrub happens on import** (§5):
  the importer nulls every sealed `locator` using the seeded registry table
  carried inside the dump, landing the copy clean (absent = "not configured")
  rather than dead. Scrubbing at the destination is also the safer place: the
  source key never has to travel, so ciphertext and the key that opens it never
  share one exportable artifact — the double-protection the clone has today is
  preserved. Same rule covers the minimal test-DB seed (§5).

- **Audit: yes, via `EventLog`, for material changes only.** Reuse the existing
  `evl_event_logs` audit trail (`EventLog` — `evl_event`, `evl_usr_user_id`,
  `evl_was_success`, `evl_note`); no new table. The rule is *log when a secret's
  material actually changes, never for a read, a flag, or a steady state*:
  - a `regenerable` auto-heal (a signing-key re-mint) → an audit row — a key
    rotated and outstanding signed URLs were invalidated, the exact silent change
    someone will ask about later.
  - an operator-acknowledged re-mint of a `regenerable-breaks-things` value → an
    audit row **with `evl_usr_user_id`** — it unpaired every device / dropped
    every peer and a human authorized it; the most important one to record.
  - an `operator` value being *flagged* → no row (a detected condition, not a
    change); the row is written when the operator **re-enters** the secret, like
    any credential change.
  - Audit the re-mint, **not** the read: the cold heal (B1) re-mints only once,
    and the row rides the same dead→alive *transition* the alert dedup tracks, so
    a mass-dead incident writes one row per real event, not one per page view.

## Open questions for the owner

None outstanding. The third review pass raised three real forks, each now
resolved with the owner and folded above: heal-when-cold-only (B1, + plaintext
B3), scrub-on-import (B2), and a dedicated `sealed_secrets` block distinct from
`secret:true` (N5). Two smaller mechanism choices were folded in on the
implementer's recommendation and are cheap to revert if the owner disagrees: the
**key canary** for the mass-dead-vs-corruption verdict (§2) and the **cached pill
verdict** instead of a per-request decrypt walk (§4). Remaining work is
implementation.

## Implementation notes (as built)

Refinements settled during the build and its code review, recorded so the next
reader is not surprised by a delta from the design above:

- **Kinds, corrected against what the machine can actually do.** The device-link
  session secret (`dlk_secret_once`) is a one-time ceremony value scrubbed right
  after collection, so it is `ephemeral`, not `regenerable-breaks-things`. OAuth
  tokens (IMAP `iia_oauth_*`, customer-cloud `cca_*`) cannot be re-minted by the
  machine at all — recovery is the operator re-running the OAuth connect — so they
  are `operator` (re-auth = re-entry), not `regenerable-breaks-things`.
- **Alert dedup is per-category, not per-(category, instance).** The transition
  into dead is tracked on the category's cached state (`ssr_last_state`), so a
  second row of an already-dead category does not re-alert. A mass event (canary
  dead, or ≥3 categories newly dead in one pass) collapses to a single batched
  alert.
- **The destructive-re-mint acknowledgement is wired but dormant.** No
  `regenerable-breaks-things` entry declares a `reprovision` recipe yet, so the
  admin "Re-mint" action stays hidden and those secrets show "re-mint from the
  feature's page." Declaring a recipe (e.g. the relay transport or Direct identity
  mint) lights the in-page confirmed re-mint up later with no other change.
- **Scrub clears only sealed ciphertext.** The import scrub nulls a value only
  when it is an actual SecretBox blob (bare or inside a `{"enc":…}` envelope),
  never a readable plaintext one — a zero-config plaintext credential or an
  operator-entered registrant contact stored as raw JSON is kept, not lost.
- **Plaintext reseal runs in the reconciler, cold** (singleton and bare-blob
  columns; a wrapped column reseals through its consumer's next save, and an
  orphan row is skipped — its locator is undeclared, so `seal()` would refuse it).
  The wrapped-column exclusion is keyed on "declares an `enumerator`", which today
  matches exactly the three wrapped columns; a future BARE-blob category that
  declares an enumerator only for a richer reconcile would silently opt out of
  resealing, so re-check that coupling if that ever happens.

## The immediate developers fix (independent of this spec)

Deleting developers' `file_signed_url_key` row re-mints it and restores signed
URLs there today. It is safe (invalidates only outstanding signed URLs) and does
not depend on this spec. Worth a broader check on developers first: if its
database was seeded from elsewhere, other sealed values (OAuth, backup creds)
may be dead too — exactly the inventory this spec would make routine.
