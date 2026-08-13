# Vault Consumer Platform — make the Sealed Vault a thing third parties can build on

**Status: IMPLEMENTED 2026-08-13. All six work items built; safe and db tiers
green (268 tests / 8824 checks), including `plugins/mailbox/tests/mailbox_reseal_test.php`
untouched — the stated behavior-preservation proof. Companion docs:
`docs/sealed_vault.md` (the architecture as built), `docs/joinery_direct.md`
§ *Serving a kind: the plugin surface* (the registration idiom this spec
copies).**

**As built, deviating from the text below in two places worth recording.**
`VaultConsumers::unmetObligations()` takes an `$include_inactive` flag rather
than reading inactive plugins in a separate pass — one code path, with the
rotation guard as its only caller passing true. And `VaultScopes::labelFor()`
returns the declared label with `' recovery codes'` appended by
`RecoveryReadiness`, so a declaration reads `"label": "Password vault"` rather
than carrying the card's whole title.

## Intent

A developer who wants their own private assets — sealed to a member's key,
opened only when that member proves presence with a passkey — should be able to
build it from their own plugin, with no core edits and one doc section to read.
Today the crypto supports that completely and the plumbing does not.

The gap is not in the encryption. It is that **who may consume the vault is a
hardcoded list inside core**, and there is no developer-facing guide. Three
constants and one cross-plugin reach are the whole obstacle:

| What | Where | Effect on a third-party consumer |
|---|---|---|
| `CONSUMER_PLUGINS = array('mailbox', 'joinery_ai')` | `includes/VaultUnlock.php:95` | Their bootstrap never loads, so their reseal / wipe / deferred-work hooks never register. Key rotation silently strands their content. |
| `CONSUMER_CORE_FILES` | `includes/VaultUnlock.php:103` | Same, for a core (non-plugin) consumer. |
| `SCOPE_CONTEXTS`, `SCOPE_*`, `ALLOWED_PRF_CONTEXTS` | `includes/VaultClientCustody.php:30`, `data/user_encryption_vaults_class.php:45`, `includes/PasskeyService.php:93` | They cannot have their own isolated unlock without editing three core files. |
| `capsForUser()` reaches into `plugins/mailbox/` | `includes/VaultUnlock.php` | The mail plugin's security levels decide the unlock-window length for **every** server-custody consumer, including theirs. |
| One mention of the vault in the plugin guide | `docs/plugin_developer_guide.md:818` (`vault_gated`) | Nothing tells them the feature exists or how to use it. |

**What is already right, and is not touched by this spec.** The crypto layers
are genuinely general and their reuse is already proven:

- Sealing at rest is a model declaration and nothing else — `public static
  $sealed_fields = [...]` plus four columns, no crypto code in the consumer.
- Encrypted-to-the-edge is **core, not the password plugin's**:
  `assets/js/vault-crypto.js`, `assets/js/vault-keyring.js`, and
  `includes/VaultClientCustody.php` are scope-parameterized and consumer-blind.
  Drive's Fortress folders — a completely different content type — reused the
  whole layer and added no keyring or identity server surface at all. The
  password manager's own server footprint on top of it is four logic files.

So this is a plumbing and documentation spec. No cipher, wire format, blob
format, container format, or rotation semantic changes.

## The idiom being copied

`includes/joinery_direct/DirectKinds.php` already solved this exact problem for
Joinery Direct: core entries in a root JSON file, plugin entries under a key in
`plugin.json`, merged into one registry that is readable as plain instance
configuration without loading any handler code, with a deactivated plugin's
entries simply absent from the merged set. Every registry below follows that
file's structure, error handling (a malformed declaration is logged and skipped,
never fatal), and `resetForTests()` convention.

---

## Work item 1 — the vault consumer registry

Replace both hardcoded constants with a declarative registry,
`includes/VaultConsumers.php`.

A plugin declares itself in `plugin.json`:

```json
"vaultConsumer": {
  "bootstrap": "includes/bootstrap.php",
  "order": 20,
  "reseals": true,
  "caches": true
}
```

Core consumers declare identically in a new `vault_consumers.json` at the
`public_html/` root, with `bootstrap` relative to `public_html`. The two current
core entries (`includes/DriveSealed.php`,
`includes/joinery_direct/DirectSpoolDrain.php`) move there verbatim, and a
third is added: the API idempotency store. `ApiIdempotencyKey`
(`data/api_idempotency_keys_class.php:82`) declares `$sealed_fields`
(`aik_response_body`, sealed by `ApiLogicEndpoint.php:534`) but belongs to no
consumer today and is never resealed — rotation strands its cached responses,
unnoticed only because the rows expire. A one-line bootstrap
(`includes/ApiIdempotencySealed.php`) registers the work-item-4a model reseal,
declared `reseals: true`, which both closes that pre-existing hole and keeps
the work-item-6 declaration test honest with no exemption list.

- **`bootstrap`** (required) — the file `loadConsumerBootstraps()` requires. Its
  job is unchanged: register `File` decrypt hooks, `onReseal`, `onWipe`,
  `VaultDeferredWork::register`.
- **`order`** (optional, default `100`) — **load order is load-bearing and must
  stay explicit.** Mailbox parsing has to precede AI judging, because an
  unparsed message has no fields to read; today that ordering is an accident of
  array position in `CONSUMER_PLUGINS`. Plugin iteration order is not a contract,
  so the registry sorts on `order` ascending, ties broken by consumer name, and
  the resulting deterministic order is what `VaultDeferredWork` drains in.
  Mailbox declares `20`, joinery_ai `40`, leaving room between and below.
- **`reseals`** (optional, default `false`) — this consumer stores sealed content
  and must re-seal it on rotation. See work item 4.
- **`caches`** (optional, default `false`) — this consumer keeps disposable
  in-window plaintext *outside* the sealed columns (a search index, a streaming
  scratch) and must clear it on lock. See work item 4b.

`VaultUnlock::loadConsumerBootstraps()` keeps its signature, its once-per-request
guard, and every existing call site; only its source of truth changes. Plugin
consumers still load only when the plugin is active; core consumers still load
unconditionally.

**Callback registrations are attributed to their consumer.** `onReseal()` and
`onWipe()` append anonymously today (`VaultUnlock.php:427`, `:665`), so core ends
up holding an array of closures with no record of who registered them. Work item
4b cannot be built on that: it could neither name the consumer that registered
nothing, nor distinguish a consumer that registered two callbacks (mailbox will —
the generic model reseal plus its bespoke DKIM pass) from one that registered
none. The loader already knows which consumer it is requiring, so it publishes
that name for the duration of the require, and `onReseal()`/`onWipe()` stamp
each registration with the consumer currently loading:

```php
VaultConsumers::beginLoading($name);
require_once($path);
VaultConsumers::endLoading();
```

No signature change and no consumer edit — every consumer registers exactly as it
does today. `VaultConsumers::unmetObligations(): array` then reports, per
consumer, which declared obligations it registered nothing for.

Attribution is only correct if consumer bootstraps are loaded exclusively
through this loader — that is a stated invariant, not an accident. If any other
code path `require_once`s a bootstrap first, its registrations happen outside a
loading context (or, worse, the loader's later require is a no-op), the
consumer appears to have registered nothing, and work item 4b would refuse a
rotation for the wrong reason. The loader therefore checks
`get_included_files()` before each require and logs loudly when a bootstrap was
already included, so a false refusal names its actual cause. A registration made
outside any loading context (a test wiring a callback directly) attributes to no
consumer and satisfies no obligation.

`VaultConsumers::registered(): array` is the merged, ordered registry, and is
what `VaultDeferredWork` reads for order instead of the constant it cites today.

## Work item 2 — the vault scope registry

Replace the three scope allowlists with `includes/VaultScopes.php`, fed the same
way: `vaultScopes` in `plugin.json`, and core scopes in `vault_scopes.json` at
the `public_html/` root.

```json
"vaultScopes": {
  "passwords": { "custody": "client", "label": "Password vault" }
}
```

Core `vault_scopes.json` ships `user` (custody `server`) and `drive` (custody
`client`); `passwords` moves into the vault plugin's own `plugin.json`, which is
where it belongs.

**The PRF context is derived, never declared.** `VaultScopes::prfContext($scope)`
returns `vault-{scope}-kek`, with the single grandfathered exception `user` →
`vault-kek`. This is not a convenience: a declared context is a footgun, because
a developer who copies another plugin's declaration and forgets to change the
string silently destroys the isolation guarantee — two scopes deriving KEKs under
one context means unlocking one opens the other. Deriving it from the scope name
makes collision impossible, since scope names are unique keys in the merged
registry — a uniqueness the registry must enforce, not assume (next paragraph).
The derivation reproduces both existing client contexts exactly
(`vault-passwords-kek`, `vault-drive-kek`), so nothing re-wraps.

**Scope names are validated, and a name collision is refused, never merged.**
This is the one place VaultScopes deliberately deviates from the DirectKinds
idiom: DirectKinds lets a plugin override a core kind, but a scope collision
means two declarations deriving one PRF context — the exact isolation failure
R1 exists to make impossible — so override semantics are wrong here. Core
declarations always win; a plugin declaring a name core already ships is
refused and logged like any malformed declaration. Two plugins declaring the
same name are **both** refused, with a log naming both plugins — deterministic
regardless of plugin iteration order, and the safe direction, since honoring
either would let an unlock for one open the other. Names must match
`/^[a-z0-9_]{1,32}$/` (all three existing scopes do); the name flows into the
PRF context string, the APCu window key, and the `/dev/shm` window-marker
filename, whose sanitizer collapses characters outside that set — so two names
differing only in refused characters would collide post-sanitization. An
invalid name is refused and logged.

Consequent changes:

- `PasskeyService::ALLOWED_PRF_CONTEXTS` becomes
  `PasskeyService::allowedPrfContexts()`, computed from the registry. Both
  validation sites (`PasskeyService.php:388` and `:438`) call it. The constant is
  removed rather than kept as an alias — a stale second source of truth here is
  exactly the failure this spec exists to remove.
- `VaultClientCustody::SCOPE_CONTEXTS` is removed; `contextForScope()` and
  `assertClientScope()` delegate to the registry, and `assertClientScope()` now
  additionally refuses a scope whose declared custody is `server` (the check it
  performs today by absence from the map).
- `UserEncryptionVault::SCOPE_USER` stays as a named constant — core code refers
  to the server-custody scope constantly and a bare `'user'` string reads worse.
  `SCOPE_DRIVE` and `SCOPE_PASSWORDS` are removed; the one core caller
  (`logic/drive_public_keys_logic.php:65`) uses `'drive'` via the registry.
- `RecoveryReadiness::$scope_titles` is deleted; the card title comes from the
  registry's `label`. **A vault row whose scope is no longer registered is inert,
  not broken** — the same semantics as a Direct kind whose plugin is deactivated.
  `RecoveryReadiness` skips it rather than throwing or rendering an empty card,
  and the rows are never deleted, so reactivating the plugin restores access.

**A plugin-declared scope is a client-custody scope.** `VaultScopes` refuses a
plugin declaring `"custody": "server"` — logged and skipped, the same treatment
as any malformed declaration — because there is no server-custody scope but
`user` and no way to create one. Every `uev` row in the tree is written in one of
two places: `logic/vault_client_setup_logic.php:72`, which is scope-parameterized
but client-custody only, and `includes/VaultCeremonies.php:64`, which hardcodes
`SCOPE_USER`. Setup, rotation and every ceremony unlock are hardcoded the same
way (`VaultCeremonies.php:49, 98, 245, 329, 461`;
`logic/vault_rotate_verify_logic.php:37` loads the vault with no scope argument),
and `onReseal` has no scope parameter. A second server-custody scope would
therefore be enrollable by nobody, rotatable by nothing, and would receive
`user`'s reseal callbacks carrying keys that do not match its content.

Server custody remains fully available to third parties — as the `user` scope,
which a consumer reaches by declaring no scope at all. That is the point of work
items 1 and 4: seal into `user`, register the reseal, done. Making a *second*
server-custody scope real means threading a scope through the setup and rotation
ceremonies, the reseal signature, the lock chip and the recovery cards, which is
a larger piece of work than this spec and is not required by anything it enables.

## Work item 3 — window caps become a consumer declaration

`VaultUnlock::capsForUser()` currently `require_once`s
`plugins/mailbox/data/inbound_email_domain_class.php` and asks
`InboundEmailDomain::maxSecurityLevelForUser()` for the idle and absolute caps
applied to every server-custody window. Core should not know that the mailbox
plugin exists, and a third-party consumer's window length should not be decided
by mail domain configuration.

Add a provider registry:

```php
VaultUnlock::onWindowCaps(
    callable $provider,        // fn(int $user_id): array{idle:?int, absolute:?int}
    array $fail_closed_caps    // applied if $provider throws
);
```

`capsForUser()` calls `loadConsumerBootstraps()` first (it currently runs before
any bootstrap has loaded, which is only safe because it reaches for the class
file directly), then folds every registered provider by taking the **strictest**
value per field — the minimum non-null `idle`, the minimum non-null `absolute`.
Strictest-wins is the only defensible fold: a member who has configured a tight
window on any consumer has expressed a preference about their unlock window as a
whole, and one shared window cannot honor two different lengths.

Fail-closed behavior is preserved exactly, and generalized: a provider that
throws contributes its own declared `$fail_closed_caps` rather than being
skipped, and the throw is logged as it is today. Mailbox registers its Fortress
caps as its fail-closed pair from `plugins/mailbox/includes/bootstrap.php`, which
reproduces the current behavior for current users with no observable change.

With no providers registered, caps are `['idle' => null, 'absolute' => null]` —
the current no-mailbox result.

One observable change, accepted as correct: today `capsForUser()` gates on the
class *file* existing, so an installed-but-deactivated mailbox plugin still
caps windows; under the registry an inactive plugin registers no provider and
its caps lapse until reactivation. A deactivated consumer's policy should not
govern the shared window, and its unlocks are refused anyway (R5).

Out of scope, stated so nobody expects otherwise: `SessionControl.php:1466`
keeps its own direct reach into the mailbox plugin
(`maxSecurityLevelForUser()`, the Fortress mandatory-2FA gate). That is
sign-in posture, not window policy, and generalizing it belongs to
`specs/protection_levels_platform.md`, not here.

## Work item 4 — close the stranding trap, and take the plumbing into core

This is the one place where opening the vault to third parties creates a new
hazard rather than just removing an obstacle. The three pieces share one
observation: **the sealed-field model hook already knows the four column names,
so core can do the crypto work consumers currently hand-write** — on rotation
(4a), and on write (4c).

The rotation contract says a consumer must register an `onReseal` callback that
re-seals exactly the items on the draining generation, attempts every item, and
throws on any failure. Today that is a doc rule honored by two teams who read the
doc. A third-party consumer that declares sealed content and forgets the callback
gets no error and no warning — rotation completes, retires the old wrappings, and
the content is gone. Losing member data because a doc went unread is not an
acceptable failure mode for a feature we are advertising to developers.

Two mechanisms, both small:

**4a. A generic model reseal.** Nearly every consumer's callback is the same loop:
select this owner's rows at the old generation, unwrap the per-row key with the
old secret, re-wrap it to the new public key, write it back. The sealed-field
model hook already knows the four column names, so core can do it:

```php
VaultUnlock::onReseal(VaultUnlock::modelReseal([
    MailboxContact::class,
    MailboxMessage::class,
]));
```

`SystemBase::resealRows($user_id, $old_secret, $old_generation, $new_public_key,
$new_generation)` implements one model's pass, honoring `sealedOwnerUserIdFor()`
so an indirectly-owned row resolves the same way it does on read. Ownership
cannot live in the WHERE clause: the one override in the tree
(`inbound_email_message_class.php:617`) falls back to a live grantee lookup for
rows sealed before the owner column existed, which no SQL predicate expresses.
So the select is by generation plus owner-column-matches-or-empty, and each
candidate row's owner is confirmed through the hook before it is touched. It
attempts every row and throws if any failed, exactly as the hand-written
callbacks must. Consumers that also reseal material outside model columns —
mailbox's DKIM keys, Drive's `fil_sealed_key` on blobs — keep their bespoke
callbacks and may compose this helper alongside.

In passing, two stale `SystemBase` docblocks get corrected: `:423` counts "four
of the five sealed models" (there are eight), and the `sealedOwnerUserIdFor()`
docblock claims chat resolves ownership through its conversation — no such
override exists.

**4b. A declared-and-missing guard.** A consumer declaring `"reseals": true` that
registers no callback refuses the rotation with an error naming the consumer.
The check (`VaultConsumers::unmetObligations()`) runs at the **start** of
`VaultCeremonies::rotate()`, before the new generation is even minted — the
crash-safety ordering already makes any pre-retirement throw safe (both
generations live, every unlocker working, re-running converges), but refusing
before the mint costs nothing and avoids leaving even a benign pending
rotation behind.

The same guard closes a pre-existing hole the registry makes visible for the
first time: **rotation refuses when an installed-but-inactive plugin declares
`reseals: true`.** Deactivating a plugin removes its callbacks but not its
sealed rows — today a rotation past a deactivated mailbox would drain, retire,
and silently strand every sealed message. An inactive plugin's `plugin.json` is
still on disk, so the guard reads declarations from installed-but-inactive
plugins for this check alone (the runtime registry still excludes them
everywhere else, per the DirectKinds idiom). The error tells the owner to
reactivate the plugin — or remove it, accepting its content — before rotating.

**A `caches: true` consumer that registers no `onWipe` is logged, never refused.**
The two obligations read symmetrically in `plugin.json` and deliberately do not
behave symmetrically, because rotation is an operation the platform may refuse
and locking is not. The only moment a missing wipe callback becomes observable is
window close, and refusing to close the window would leave the vault **open** —
converting a stale plaintext file into a live unlocked vault, which is strictly
worse than the thing being guarded. So the check runs once per request after
bootstraps load and writes an error naming the consumer, and
`tests/vault/sealed_consumer_declaration_test.php` asserts the in-tree consumers
register what they declared.

The value of `caches` is therefore the declaration more than the warning:
`plugin.json` becomes the one place a reviewer can see which consumers hold
member plaintext outside the sealed columns without reading their code. Its limit
is worth stating plainly in the doc rather than discovering later — `reseals` is
checkable against the tree because `$sealed_fields` is a filesystem fact, while
nothing in the tree betrays a consumer that writes plaintext to `/dev/shm`. A
consumer that caches and declares `false` is undetectable, so this catches the
honest-but-forgetful only.

**4c. Sealing on save.** Writing sealed content is currently a ceremony —
`save()` the row, load the owner's vault, call `sealColumns()` with the row id —
and the ceremony exists for a reason that no longer needs to be the developer's
problem. `save()` rebuilds each column through `get()`, which decrypts, so on a
sealed row it would write plaintext back into sealed columns while the seal flag
stayed true (`SystemBase.php:1791`). That was closed by making `save()` skip
sealed columns entirely, which fixed the leak by amputating the write path.

Core has everything it needs to do this properly instead. `set()` records which
sealed columns the caller supplied — there is no dirty tracking today, and a
sealed-only dirty set is what separates supplied plaintext from ciphertext
sitting untouched in `$this->data` after a load. `save()` then seals exactly
those columns: on an insert it two-phases (INSERT, then seal, in one transaction)
because the AEAD is bound to the row id, and on an update it **reuses the row's
existing DEK**. Ownership resolves through `sealedOwnerUserIdFor()` — the hook
the read path already uses — which is what removes the vault lookup from consumer
code.

```php
$note = new AcmeNote(NULL);
$note->set('acn_usr_user_id', $user_id);
$note->set('acn_title', $title);
$note->set('acn_body',  $body);
$note->save();          // sealed. no vault lookup, no sealColumns, no two-phase.
```

This closes a live footgun as a side effect: a partial update that mints a fresh
DEK rewrites `{prefix}_sealed_key` and orphans every sealed column it did not
rewrite, which consumers avoid today only by threading the existing DEK through
by hand (`plugins/mailbox/includes/MailboxContacts.php:474`).

**Create works offline; update needs the window.** That asymmetry is the crypto,
not the API, and it survives: sealing needs only the public key, so ingest can
seal a brand-new row into a locked vault, but reusing an existing row's DEK means
unwrapping it, which needs the secret. So `save()` throws `VaultLockedException`
when a locked window blocks a sealed-column update — symmetric with `get()`,
which already does, and harmless in practice because editing content means
reading it first.

**Whether a row seals at all stays a policy decision, because it is per row.**
Mailbox seals a Fortress domain's message and stores a Standard domain's in the
clear from the same model; joinery_ai keys on `aic_security_level`. The default —
seal when this row's owner has an active vault — is exactly right for a plugin
whose premise is that its content is private, and needs no declaration. Consumers
with dynamic policy override one method, `shouldSeal(array $row): bool`.

Auto-seal is **on by default** for any model declaring `$sealed_fields`, and
every model that declares it today — two mailbox, five joinery_ai, and the API
idempotency store's one, eight across three consumers — opts out explicitly
(`$seal_on_save = false`) with a comment pointing at its own sealing path.
Putting the burden on the code that already works rather than on every future
plugin is what keeps
`plugins/mailbox/tests/mailbox_reseal_test.php` green untouched — the stated
behavior-preservation proof for this whole spec. Migrating those four onto
`shouldSeal()` is optional follow-on work, not a precondition. Blob-only
consumers (Drive, via `recordSealedKey()`) declare no `$sealed_fields` and are
untouched.

The remaining hole — a consumer that declares `"reseals": false` and then seals
content anyway — is caught at review time by
`tests/vault/sealed_consumer_declaration_test.php` (work item 6), which asserts
that every model in the tree declaring `$sealed_fields` belongs to a consumer
declaring `reseals: true`. That is a real gate for anything shipped in-tree, and
a documented obligation for anything out of tree.

## Work item 5 — the developer guide

The main deliverable, and the one that decides whether any of this is actually
usable. Per standing practice, the developer documentation lands in the existing
docs, not in this spec.

**`docs/plugin_developer_guide.md`** gains a *Building a vault consumer* section,
structured as the two paths a developer actually chooses between, plain-language
first:

- **Seal it at rest** (server custody) — *the server can open this while the
  member is present, so previews, search and AI keep working; a stolen database
  or backup yields ciphertext.* Declare `vaultConsumer`, declare
  `$sealed_fields` plus the four columns on your model, register the model
  reseal — and then write and read with ordinary `set()`/`save()`/`get()`. Show
  the complete minimal consumer: `plugin.json` block, model, one-line bootstrap.
  The section should be able to say honestly that the crypto surface a consumer
  touches is configuration plus one line, and that the only runtime behavior to
  learn is treating `VaultLockedException` as a one-tap prompt.
- **Encrypt it to the edge** (client custody) — *the server never holds a key and
  can never read it, and neither can any server-side feature, ever.* Declare
  `vaultScopes` with `custody: client`, store opaque blobs through the
  `vault_client_*` actions, drive `VaultKeyring` and `VaultCrypto` in the
  browser. Point at the password manager as the reference implementation and say
  how small it is.
- The choice between them, stated as the tradeoff it is, and the recovery honesty
  both paths must show at opt-in: lose every unlocker and the content is
  unrecoverable, with no support-desk recovery.
- The obligations: the reseal callback, `onWipe` if you cache plaintext, treating
  a `null` secret key as a one-tap unlock prompt and never an error, and running
  web ceremonies through `JoineryVaultLock` so the lock chip stays truthful.

**`docs/sealed_vault.md`** — the consumer-registration, scope, and caps passages
are rewritten to describe the registries as the current and only mechanism, per
the documentation rule (no "replaces", no "previously"). Its consumer-contract
section gains the model reseal and sealing on save, and cross-links the new
plugin-guide section.

It also carries a claim that is not true of the code and must be corrected in
both places it appears (§ *The shape of it* and § *The consumer contract*): *"A
consumer with a genuinely higher sensitivity bar may enroll a second `uev` scope
instead of sharing `user`."* No enrollment path for a second server-custody scope
exists (see work item 2), so per the documentation rule the passages state what
is true — one server-custody scope, and client custody as the isolation answer.

**`docs/passkeys.md`** — the PRF context passage describes derivation from the
scope name and the isolation property that derivation buys.

**`CLAUDE.md`** — the doc index entry for the plugin guide picks up vault
consumers. Edited through `/admin/admin_agent_files`, never on disk.

## Work item 6 — tests

- `tests/vault/vault_registry_test.php` — merge order and precedence for both
  registries; a malformed declaration is skipped and logged, not fatal; a
  deactivated plugin's consumer and scope leave the merged set; `order` sorts
  deterministically including the name tiebreak; PRF derivation reproduces
  `vault-kek`, `vault-passwords-kek`, `vault-drive-kek` exactly; a plugin
  declaring `custody: server` is refused and logged, leaving `user` the only
  server-custody scope in the merged set; a plugin redeclaring a core scope
  name is refused; two plugins declaring the same scope name are **both**
  refused with both named in the log; a scope name failing the pattern is
  refused.
- `tests/vault/vault_window_caps_test.php` — strictest-wins across two
  providers, per field; a throwing provider contributes its declared fail-closed
  caps; no providers yields uncapped.
- `tests/vault/sealed_consumer_declaration_test.php` — every in-tree model
  declaring `$sealed_fields` belongs to a consumer declaring `reseals: true`
  (including `ApiIdempotencyKey` via the new core entry);
  a `reseals: true` consumer with no registered callback refuses rotation
  before the new generation is minted, with every unlocker still working; an
  installed-but-inactive plugin declaring `reseals: true` refuses rotation the
  same way; a `caches: true` consumer with no registered
  callback logs and does **not** block a lock; callbacks attribute to the
  consumer whose bootstrap registered them, including a consumer registering two;
  a bootstrap already included outside the loader is detected and logged.
- `tests/vault/seal_on_save_test.php` — an insert seals and sets the flag; a
  partial update reuses the row's existing DEK and leaves untouched sealed
  columns readable; a sealed-column update against a locked window throws
  `VaultLockedException`; `shouldSeal()` returning false stores plaintext and
  leaves the flag false; a model with `$seal_on_save = false` behaves exactly as
  it does today.
- `tests/vault/model_reseal_test.php` — the generic reseal touches exactly the
  old generation, honors `sealedOwnerUserIdFor()`, and throws when one row fails.
- Existing suites that name the constants (`plugins/vault/tests/vault_client_custody_test.php:40-42`,
  `tests/account_security/passkey_capability_test.php`) move to the registry API.
- `plugins/mailbox/tests/mailbox_reseal_test.php` must stay green untouched — it
  is the real-rows proof that this refactor is behavior-preserving.

## Non-goals

- No change to any cipher, AD convention, wrapping format, blob format, or
  `SealedFileContainer` layout. Nothing already sealed is re-wrapped or re-read.
- No change to rotation semantics, the generation model, the unlocker floor, the
  revocation veto, the hot-turn egress rule, or the vault-activation flip.
- No new user-facing surface. The lock chip, keyring UI and recovery cards behave
  identically; they only source their scope labels differently.
- Not a protection-levels change. `specs/protection_levels_platform.md` owns the
  shared rung vocabulary; a consumer declaring a scope here is not declaring a
  rung.

## Resolved decisions

- **R1 — PRF context is derived, not declared.** A declared context lets a
  copy-paste mistake silently merge two scopes' unlocks. Derivation makes the
  isolation structural. `user` → `vault-kek` is grandfathered so nothing re-wraps.
- **R2 — Load order stays explicit.** An `order` integer, not plugin discovery
  order, because mailbox-before-AI is a correctness requirement, not a
  preference.
- **R3 — Server custody is available to third parties as the `user` scope; a
  plugin-declared scope is always client custody.** Not a policy choice: `user`
  is the only server-custody scope that can exist, since both vault-creating
  code paths either hardcode it or accept client scopes only, and the ceremonies
  and the reseal signature carry no scope. Permitting a `custody: server`
  declaration would accept a scope no member could ever enroll, so `VaultScopes`
  refuses it. Server custody is not withheld — it is reached by declaring no
  scope, which is what work items 1 and 4 make a one-line integration.
- **R4 — Strictest-wins for window caps.** One shared window cannot honor two
  lengths, and the member who set the tight one meant it.
- **R5 — Unregistered scopes are inert, not deleted.** Deactivating a plugin
  hides its cards and refuses its unlocks; reactivating restores everything.
  Matching Direct's behavior for an unserved kind.
- **R6 — `caches: true` is declared, and enforced by a log rather than a
  refusal.** Worth having for the declaration itself: it makes "this consumer
  holds member plaintext outside the sealed columns" visible in `plugin.json` to
  a reviewer who has not read the plugin. Not enforceable by refusal, because the
  only moment it is observable is window close and refusing to lock would leave
  the vault open. Its severity is also worse than *a leak inside the session*, the
  framing this decision started from: a `/dev/shm` working copy outlives the lock,
  the logout and the session, which makes the lock chip untruthful for that
  consumer's content — but it is still not data loss, which is why it is not
  treated like `reseals`.
- **R7 — Core seals on save; consumers declare, they do not encrypt.** The
  explicit `sealColumns()` ceremony exists because `save()` was made to skip
  sealed columns after it was found writing decrypted plaintext back into them.
  Core knows the four column names and the owner hook on the write path exactly as
  it does on the read path, so it can seal correctly instead — which also removes
  the DEK-reuse trap consumers currently avoid by hand. Default on, the four
  existing consumers opt out, so the mailbox reseal test stays green untouched.

- **R8 — Declaring a scope means wanting client custody; the guide presents two
  paths, not three.** Sharing the `user` keypair is not an alternative to
  declaring a scope, it is what server custody *is* — reached by declaring no
  scope and calling `VaultUnlock::secretKey($user_id)`. The guide states the
  inherited cost honestly: one tap opens every server-custody consumer at once,
  so this content opens whenever mail does, and a session-resident attacker
  during an open window sees all of it. A consumer needing genuine isolation
  declares a client-custody scope and accepts that no server-side feature can
  ever read it. Isolation *with* server readability is the one combination the
  platform does not offer, and the guide says so rather than leaving a developer
  to discover it.

- **R9 — A scope-name collision is refused, never resolved by override.** The
  one deliberate deviation from the DirectKinds idiom, because a collision here
  is not two implementations of one thing but two things sharing one PRF
  context — a broken isolation guarantee. Core wins over plugins; two plugins
  colliding are both refused; names are pattern-validated because they flow
  into context strings, cache keys, and marker filenames.
- **R10 — Rotation refuses while an installed-but-inactive plugin declares
  `reseals: true`.** Deactivation removes a consumer's callbacks but not its
  sealed rows, so rotating past it is the same data loss 4b exists to prevent —
  a hole that predates this spec and becomes checkable only because
  declarations live in `plugin.json`, readable without loading the plugin.

## Open decisions

None — D1 and D2 are resolved above (R6/R7 and R8), and the 2026-08-13 review's
four gaps are folded in (work items 1, 2, 4b; R9, R10). Ready to build.
