# Sealed Vault

A per-user encryption identity shared by every feature that seals content the
server should only read while the user has proven presence. One lock (a
passkey, a recovery code, or an optional bypass phrase), one bounded unlock
window, and any number of consumers behind it — mail, chat, and
[Drive's Private files](drive_encryption.md#private-files--server-custody) seal
server-custody content; the [password manager](../plugins/vault/docs/overview.md)
and [Drive's Fortress folders](drive_encryption.md) are client-custody consumers
(their keys are unwrapped only in the browser). The vault owns the identity and
the lock; each consumer owns what it seals and how it presents locked state.

Consumers register their hooks from a bootstrap file the vault loads lazily:
plugins through `VaultUnlock::CONSUMER_PLUGINS`, and core consumers — Drive is
one, since it has no plugin — through `VaultUnlock::CONSUMER_CORE_FILES`.

## The shape of it

Each user gets an X25519 keypair per **scope** (`uev_scope`; this package
builds the server-custody `user` scope, shared by mail and chat). The
**public** key is cleartext at rest — anything can seal to it, even while the
user is offline. The **secret** key never touches disk unwrapped: it exists
only as **wrappings**, one per enrolled unlocker (a passkey's WebAuthn PRF
output, a recovery code, an optional bypass phrase), and is unwrapped only
transiently into server RAM for the duration of an **unlock window**.

**Naming:** the memorized unlocker is a **bypass phrase** everywhere a user
sees it (internally `passphrase` in identifiers and API action names). The
name carries its own warning: it bypasses the passkey requirement, it is not
the login password, and enrolling one lowers the vault's strength to the
strength of the phrase. It is never offered during setup — the ceremony is
passkey + recovery codes only — and is added deliberately from the unlocker
management panel by users who need to unlock where their passkey is not
available (another device, CLI tooling).

**One unlock opens everything in that scope.** A single passkey tap puts the
secret key in the window; every server-custody consumer's
`VaultUnlock::secretKey()` call sees it open at once. That is the UX win and
the accepted cost: an attacker resident during an active window reads every
consumer's in-window content, not just one — bounded by the idle timeout,
seal-after-use, and key rotation. A consumer with genuinely higher sensitivity
can enroll a second `uev` scope for isolation instead of sharing `user`.

## Crypto core

`includes/SealedBox.php` — the asymmetric sibling of
[SecretBox](secret_box.md), hard-requiring `ext-sodium` (no OpenSSL fallback;
`crypto_box_seal` has none). Versioned, self-describing base64url blobs, same
philosophy as SecretBox: fail closed, never return half-verified plaintext.

```php
$box = new SealedBox();
$keypair = $box->generateKeypair();               // ['public'=>b64, 'secret'=>b64] X25519
$sealed  = $box->sealDek($bytes, $public_key);     // crypto_box_seal - anyone can seal
$bytes   = $box->openDek($sealed, $secret_key);       // public key derived from the secret
$blob    = $box->aeadEncrypt($plaintext, $key, $ad);   // xchacha20poly1305_ietf
$plain   = $box->aeadDecrypt($blob, $key, $ad);        // throws on tamper or AD mismatch
$wrapped = $box->wrapKey($secret_key, $kek, $ad);      // same AEAD primitive, wrapping a key
$secret  = $box->unwrapKey($wrapped, $kek, $ad);
$kek     = $box->kekFromRecoveryCode($code, $salt);    // crypto_generichash - fast; entropy is the defense
$kek     = $box->kekFromPassphrase($passphrase, $salt);// crypto_pwhash Argon2id - slow, low-entropy input
$salt    = $box->generateSalt();                       // one uev_salt serves both KDFs above
$code    = $box->generateRecoveryCode();               // 26 Crockford-base32 chars, >=128 bits, grouped
```

`includes/VaultCrypto.php` names the per-item envelope-encryption dance every
consumer repeats, thin over `SealedBox`:

```php
$crypto = new VaultCrypto();
$dek    = $crypto->newItemDek();                        // random 32B, one per content item
$sealed = $crypto->sealItemDek($dek, $public_key);       // store on the consumer's own row
$dek    = $crypto->openItemDek($sealed, $secret_key);
$blob   = $crypto->sealField($plaintext, $dek, $ad);     // $ad is the CONSUMER's row-binding string
$plain  = $crypto->openField($blob, $dek, $ad);          // e.g. 'mail:{message_id}:body_plain'
```

The AD (additional data) is entirely the consumer's convention — a stable
per-item identity string. Binding it means a ciphertext can never be spliced
onto a different row and still decrypt.

## Key hierarchy

- **`uev_user_encryption_vaults`** (`UserEncryptionVault`) — one row per
  (user, scope): `uev_public_key` (cleartext), `uev_salt` (the current
  generation's KDF salt for the recovery/passphrase unlockers), `uev_custody`
  (`server` for mail/chat; `client` for the browser-only password/Drive scopes),
  `uev_key_generation`.
- **`uew_user_encryption_wrappings`** (`UserEncryptionWrapping`) — one row per
  enrolled unlocker: `uew_unlocker_type` (`passkey`/`recovery`/`passphrase`),
  `uew_wrapped_secret_key` (AEAD-wrapped, AD = `vault:{vault_id}:{wrapping_id}`
  via `UserEncryptionWrapping::adFor()`), `uew_salt` (the KDF salt this
  wrapping's KEK was derived under — recovery/passphrase only, null for
  passkeys — so a rotation replacing `uev_salt` never strands a live
  wrapping), `uew_key_generation` (which generation's secret it wraps),
  `uew_is_used` (recovery codes are one-time), `uew_delete_time` (soft delete
  retires a wrapping).

`UserEncryptionWrapping::createWrapped($vault_id, $type, $secret_key, $kek,
$credential_id = null, $label = null, $key_generation = null, $salt = null)`
is the one place a wrapping gets created — it two-phase-inserts (the AD needs
the row's own id) so every wrapping is sealed the same way. `$key_generation`
null resolves to the vault's current generation (correct for every enrollment
ceremony — the in-window secret being wrapped is the current generation's);
rotation passes its computed `new_key_generation` explicitly. Unlock paths
derive each wrapping's KEK from the wrapping's own `uew_salt` (falling back
to `uev_salt` for a null), so codes and passphrases from a not-yet-drained
generation keep working in a two-generation state.

Neither table is an API resource; consumers never touch them directly.

## Enrollment

All in `logic/vault_*_logic.php`, gated on `passkeys_enabled` and a signed-in
session. Every enrollment ceremony (add passkey, enroll passphrase,
regenerate codes) refuses while the vault has live wrappings in more than one
generation — an unfinished rotation, whose only exit is re-running the
rotation — because a wrapping it created could not be tagged with a single
truthful generation. Every vault endpoint declares `requires_browser_session` (see
[API § Authentication](api.md#authentication)): the unlock window is keyed to
the browser session id, so these actions are reachable only through the
browser-session credential, never an API key — the boundary is stated in the
contract rather than left to fail incidentally.

| Action pair | Purpose |
|---|---|
| `vault_setup_options` / `vault_setup_verify` | First-time setup: generate the keypair, wrap it under the enrolling passkey + N fresh recovery codes, open the window. The verify action also accepts an optional `passphrase` (a bypass-phrase wrapping) for non-web clients; the web ceremony never offers it. Requires an account password first (see *The vault-activation flip*) and an explicit permanent-loss acknowledgment. |
| `vault_add_passkey_options` / `vault_add_passkey_verify` | Wrap the (already-unlocked) secret key under another PRF-capable passkey — "activating" that passkey for the vault. The security page chains this automatically after enrolling a new passkey while the vault is unlocked, so passkeys end up vault-active by default; each passkey row carries a vault badge with activate/deactivate in its Actions menu. |
| `vault_passkey_deactivate` | Remove one passkey's vault wrapping (it still signs in; it can no longer unlock). Requires a recent step-up; refused if it would break the unlocker floor. |
| `vault_regenerate_codes` | Invalidate all recovery codes and mint a fresh set. Requires a recent step-up and an unlocked vault. |
| `vault_passphrase_enroll` / `vault_passphrase_remove` | Add or remove the optional bypass phrase. Requires a recent step-up; enroll also requires an unlocked vault. |
| `vault_status` | Read-only: set-up/unlock state and the wrapping list (no secret material) for the keyring UI. |

**Which passkeys a vault prompt offers** is one rule, shared by every ceremony
above and by unlock and rotation:
`VaultUnlock::offerableCredentialIds($user_id, $scope)` returns the credentials
holding a wrapping for that scope's vault; if none do, this is an enrollment, so
it returns everything except the credentials
[known to be incapable](passkeys.md#capability-detection) of ever deriving a
secret. The two halves answer different questions and the first is much the
stronger: which credentials hold a wrapping is a stored fact about this vault, so
unlock and rotation — the paths where a wrong answer means someone cannot reach
their own sealed content — never consult capability at all. A partially-rotated
vault holds wrappings across generations and the offer is their union. Client-
custody scopes need no special case: the server cannot read those KEKs, but it
does store each scope's wrapping rows tagged with the credential id, so it knows
*which* credentials unlock a scope without knowing *what* they unlock. An empty
result means "no opinion" and offers every live credential — never nothing, since
an empty `allowCredentials` on the unlock path is a lockout.

Adding an unlocker is the exception: `vault_add_passkey_options` takes a
`credential_id` and scopes the ceremony to that one passkey, because the browser
otherwise decides which credential answers — pick the security key's row, tap
Touch ID at the prompt, and Touch ID would get the wrapping while the row that
was clicked still read *Not activated*. `vault_add_passkey_verify` echoes the
credential id and label it actually activated, so a caller that forgets to scope
cannot activate one silently.

## The unlock window

`includes/VaultUnlock.php` — the secret key lives in APCu, keyed
`vault:{session_id}:{user_id}:{scope}`, TTL = `vault_unlock_idle_minutes`
(default 30), re-stored on every read (activity extension):

```php
VaultUnlock::open($user_id, $secret_key, $scope = 'user');
VaultUnlock::isOpen($user_id, $scope = 'user'): bool;
VaultUnlock::secretKey($user_id, $scope = 'user'): ?string;  // null = locked
VaultUnlock::close($user_id, $scope = 'user'): void;         // current session
VaultUnlock::lock($user_id, $session_id, $scope = 'user'): void;  // a specific session
VaultUnlock::lockAll($user_id): void;                        // every scope, every session
VaultUnlock::hasAnyOpenWindow($user_id, $scope = 'user'): bool;  // ANY session, any SAPI
```

Every content read calls `secretKey()` and treats `null` as **locked** — a
one-tap unlock prompt, never an error. `lock()`/`lockAll()` are the generic
wipe surface; *when* to call them (explicit lock, a credential event, a
heartbeat/IP-change policy, a permission cap) is entirely consumer-defined.

`hasAnyOpenWindow()` answers "does any session hold a window for this user"
for a consumer's passive-close sweep (e.g. reclaiming `/dev/shm` working
copies from cron). Its signal is a secret-free marker file
(`/dev/shm/vault_window_{user_id}_{scope}`, mtime = the window's current
expiry, stamped by `open()`/`secretKey()`), NOT APCu — a CLI cron process has
its own APCu segment and can never see the web workers' entries, but every
process on the host sees `/dev/shm`. A single-session `lock()` leaves the
marker (another session may still hold a window); it expires with the idle
TTL, so a sweep is at worst delayed one interval, never wrong about an open
window. `lockAll()` removes the user's markers outright.

Unlock endpoints (`logic/vault_unlock_options_logic.php` and its
`vault_unlock_passkey` / `vault_unlock_recovery` / `vault_unlock_passphrase`
siblings, plus `vault_lock`) mint the WebAuthn PRF assertion options with
`userVerification: required` (`PasskeyService::getDerivationOptions()`) —
every vault unlock demands device user verification, not merely preferred.
The two knowledge-factor unlocks (recovery code, bypass phrase) additionally
demand the account's second factor regardless of the 2FA cadence setting: a
remote attacker must hold a possession factor, never just stolen strings.

### Host hardening

`includes/VaultHealth.php` checks the three facts that keep an unwrapped
secret key off disk even during a live window: APCu backed by anonymous
shared memory (`apc.mmap_file_mask` unset), the PHP worker's core dumps
disabled (`rlimit_core = 0`), and swap off or encrypted. Best-effort and
advisory (a check that can't be verified reports `unknown`, never a false
pass) — surfaced informationally from `vault_setup_verify` and via
`php maintenance_scripts/dev_tools/check_vault_health.php` (exits non-zero on
any `unmet` check, mirroring `check_provisioning.php`'s convention).

## The lock chip

The platform-wide "you're locked" idiom: every signed-in page for a user with
a set-up server-custody vault carries a padlock in a fixed place — closed
while the vault is locked, open (success-colored) while an unlock window is
live. Clicking the closed padlock runs the one-tap passkey unlock ceremony in
place; clicking the open padlock opens a small popover with the idle-timeout
note and a **Lock now** button — the walk-away affordance. Users without a
vault never see the chip or load its assets.

`PublicPageBase` drives it: for a signed-in user whose vault exists it emits
`<meta name="joinery-vault" content="locked|open" data-idle-minutes="N">` and
includes `assets/js/vault-lock.js` + `assets/css/vault-lock.css` (plus
`passkeys.js` for the ceremony). The chip mounts into the page's
`[data-vault-lock-slot]` element — the core page classes emit one from their
header icon cluster via `PublicPageBase::render_vault_lock_slot()` (which
emits nothing for chip-less users, so headers never carry an empty gap) — and
falls back to a fixed bottom-right chip on any theme without a slot, so the
idiom holds everywhere with zero theme work.

**The ceremony surface.** `window.JoineryVaultLock` is the one client-side
unlock/lock ceremony: `unlock()` (resolves `true` on success), `lock()`, and
`state()`. Consumer surfaces (the mail reader's unlock banners, a Fortress
compose) delegate to it when present rather than calling the vault actions
directly, so every ceremony updates the chip and announces itself.

**The event contract.** Two document-level events keep every surface on the
page — chip, presence beacon, consumer UIs — in one state:

- `joinery:vault-unlocked` — dispatched after any successful unlock. The chip
  flips open, `vault-presence.js` starts beating, and consumer surfaces may
  refresh sealed placeholders in place.
- `joinery:vault-locked` — dispatched after any explicit lock, and by
  `vault-presence.js` when a heartbeat answers `alive:false` (the window
  ended elsewhere — another session's lock, a credential event, a cap). The
  chip flips closed, the beacon stops, and consumer surfaces re-seal their
  content to placeholders.

## The unlocker floor + revocation veto

A wrapping delete is refused when it would leave fewer than 1 **live** passkey
wrapping **and** fewer than 3 unused recovery codes — the refusal names what
to enroll first. `VaultUnlock::assertWrappingDeleteSafe($vault_id,
$exclude_credential_id = null)` is the shared counting logic behind every such
refusal: passkey revocation (excluding the credential being revoked from the
count) and bypass-phrase removal (nothing to exclude — a bypass phrase never
counts toward the floor itself, so removing one only matters when the
passkey/recovery counts are already at the floor). A passkey wrapping counts only if its
credential row is still live (`pkc_delete_time IS NULL`) — belt-and-suspenders
against old data predating the cleanup below. A credential the platform knows to
be [PRF-incapable](passkeys.md#capability-detection) can never count toward the
floor, and needs no special case to be excluded: it cannot have completed a
derivation, so it holds no wrapping to count.

`VaultUnlock::registerRevocationHooks()` (called once, from
`logic/passkey_revoke_logic.php`) subscribes to both of
`PasskeyService`'s revocation registries:

- **`onPreRevoke`** → `VaultUnlock::assertRevocationSafe()` calls the shared
  floor and throws `PasskeyRevocationVetoException` when it would strand the
  vault; `PasskeyService::revoke()` propagates it without deleting the
  credential.
- **`onPostRevoke`** → `VaultUnlock::cleanupRevokedCredential()` soft-deletes
  every `uew` wrapping tied to the now-revoked credential — a wrapping for a
  dead credential can never be re-derived (its PRF output is gone with it),
  and left alive it would otherwise miscount as a usable passkey in the floor.

Consuming a recovery code to unlock is exempt from the floor, but drops the
vault into `regenerate_recommended` (surfaced by `vault_status` and the unlock
response) once fewer than 3 remain unused.

## The generic consumer hooks

A server-custody consumer never builds its own decrypt plumbing — it declares
into one of the generic hooks and the vault (or the reader that already
exists) does the rest.

**Sealed-`File` decrypt hook** — a consumer with sealed attachments registers
a decryptor for its `fil_source` tag once, at bootstrap:

```php
File::registerDecryptHook(File::SOURCE_EMAIL_ATTACHMENT, function (string $ciphertext, File $file): string {
    $secret = VaultUnlock::secretKey($file->get('fil_usr_user_id'));
    if ($secret === null) throw new VaultLockedException();
    // ... open the per-item DEK, then the AEAD blob, return plaintext bytes
});
```

`File::serve_from_path()` calls the registered decryptor between reading the
stored bytes and writing the response; a `VaultLockedException` becomes a
generic `423 Locked` response, never a raw error or ciphertext.

**Streaming `File` decrypt hook** — the shape for sealed content too large to
hold in memory, and the one that can answer a Range request honestly. A consumer
registers an opener that returns a `FileStreamingDecryptor`:

```php
File::registerStreamingDecryptHook('drive', function (File $file, $size_key = null) {
    return $file->is_sealed() ? new DriveSealedStream($file, $size_key) : null;  // null = stream unchanged
});
```

The opener is handed the size key being served — `'original'` or an image
variant — because a consumer's integrity checks differ between the two: a file's
row records the plaintext size of its original and knows nothing about a
variant's. A caller that cannot say passes `null`, which means *unknown*, never
*original*.

The decryptor answers three questions: `prepare($path)` acquires the in-window
key (and throws `VaultLockedException` if there is none), `plainSize($path)`
reports the plaintext length, and `stream($path, $sink, $offset, $length)`
decrypts a span. `serve_from_path()` resolves the key **before** writing any
header — so a locked vault is a clean 423 — then advertises `Accept-Ranges:
bytes` and serves 206 against plaintext offsets. Whole-file content should use
this shape; the whole-bytes hook above suits small sealed attachments.

**Blob-only sealing** — a consumer whose ciphertext lives entirely outside the
database declares no `$sealed_fields` at all. It still needs the four sealing
columns, and records its key with `SystemBase::recordSealedKey($row_id, $vault,
$dek)`, which wraps a key the consumer already minted (a file's bytes have to be
sealed before the row that will point at them exists) rather than minting its
own the way `sealColumns()` must. Such a row is still sealed: `save()` protects
its key wrapping exactly as it does for a column-sealing model.

**Sealed-field model hook** — a model declares which columns hold protected
content and adds four columns. That is the whole integration: no crypto code,
no key handling, no AD string of its own.

```php
class MailboxContact extends SystemBase {
    public static $sealed_fields = ['imc_address', 'imc_display_name'];

    public static $field_specifications = [
        // ... the content columns above, declared 'text' (base64 + AEAD
        // overhead outgrows any varchar cap), plus:
        'imc_content_sealed'       => ['type'=>'bool', 'is_nullable'=>false, 'default'=>false],
        'imc_sealed_key'           => ['type'=>'text', 'is_nullable'=>true],
        'imc_sealed_owner_user_id' => ['type'=>'int8', 'is_nullable'=>true],
        'imc_key_generation'       => ['type'=>'int4', 'is_nullable'=>false, 'default'=>0],
    ];
}
```

### Sealing is per row, not per model

The flag lives on the row because sensitivity does. The same table holds sealed
and plaintext rows side by side — a Fortress domain's mail and a Standard
domain's mail are the same model — and only the row knows which it is. A row
with `{prefix}_content_sealed` false reads and writes as ordinary plaintext and
costs nothing.

### Reading

`SystemBase::get()` decrypts automatically whenever the requested key is in
`$sealed_fields`, which covers ordinary field access and everything built on it
(`export_as_array()`, `export_for_api()`). `ModelQueryExecutor` (the AI
`query_model` tool's raw-row reader) calls `decryptSealedFieldStatic()` on a raw
associative row instead, since it never instantiates the model. Both paths run
the same implementation, so they cannot drift apart.

A locked vault raises `VaultLockedException` — never a return of ciphertext,
which would look like data. At the edges that becomes a `423 Locked` response
(File hook) or a `[locked - unlock your vault to view]` placeholder (the raw-row
path, so an LLM sees a legible state rather than a stack trace).

### Writing

`sealColumns()` is the only supported writer for a `$sealed_fields` column:

```php
MailboxContact::sealColumns($contact_id, $owner_vault, [
    'imc_address'      => $address,
    'imc_display_name' => $name,
]);
```

It mints the row's DEK, wraps it to the owner's vault public key, seals each
value, sets the flag and writes one UPDATE — returning the raw DEK so the caller
can seal related blobs (attachments, raw messages) under the same key. Pass a
DEK as the fourth argument to re-seal under an existing one, which leaves the
key wrapping untouched and keeps anything already sealed beside it readable.

The row must exist first: the AD binds every value to the primary key. Insert
with the sealed columns empty, then seal.

**Sealing needs only the owner's public key.** Any process can seal content to a
user at any time, with no unlock window — only reading needs the in-window
secret. So there is never a reason to store protected content in the clear
because "the window might close".

**`save()` is sealed-safe.** On a row whose flag is set it skips the
`$sealed_fields` columns entirely, so an ordinary metadata edit cannot write
decrypted content back into them, and it works with the vault locked. Those
columns belong to `sealColumns()`.

### The override surface

Two hooks, for the cases the defaults cannot answer:

- `sealedOwnerUserIdFor($row)` — whose vault this row opens against. The default
  is the owner recorded at seal time, which is immune to later membership
  changes. Override for an indirect owner (chat resolves through the
  conversation) or a fallback for rows sealed before the column existed.
- `sealedFieldIsActive($field, $row)` — whether a column holds content on this
  particular row. Override where a column is content on some rows and metadata
  on others: an inbound message's recipient is the routing alias, written in the
  clear, while an outbound message's recipient is a real address list.

`sealAd($row_id, $field)` builds the AD binding a value to its row and column —
the splice defense, so a ciphertext moved elsewhere fails to open rather than
decrypting into the wrong place. The default is `{prefix}:{id}:{field}`; models
that predate it override it and keep their own literal (`mail:`, `contact:`),
because changing an AD strands every row already sealed under it.

A model that declares `$sealed_fields` without the flag and key columns, and
without overriding the hooks, throws on first read. Failing loudly beats
returning ciphertext that looks like a value.

### Derived content

A record derived from protected material is itself protected material. An AI
summary of a sealed body, a run log quoting a sealed subject, a note written
from a sealed thread — all of it seals, on the same per-row terms, to the same
owner. Where a pointer will do, store the pointer: an id resolved through the
sealed reader at display time cannot leak and cannot go stale. See
`specs/implemented/sealed_content_egress.md`.

## The hot-turn rule

Reading protected content correctly still breaks the promise the moment the
reader writes what it read somewhere else. The rule that stops that is one rule
at one place, in `includes/SealedEgressGuard.php`:

> Once a process has actually opened sealed content, any long string it writes
> to the database must land somewhere that protects it.

A process is **cold** until `VaultCrypto::openField()` hands out a plaintext,
and **hot** from then on. Cold is virtually every request, and costs one boolean
check per statement. Hot, an INSERT or UPDATE carrying a string longer than
`SealedEgressGuard::THRESHOLD` (64 characters) must satisfy one of:

- every long value is already a sealed blob (`v1.aead.` or `v1.seal.`) — this is
  how `sealColumns()` writes through the rule it sits behind;
- the statement updates a single row already sealed to the owner whose scope
  this process opened.

Anything else throws `SealedContentEgressException` naming the destination table
and what was read. The exception is the fix instruction, in preference order:
store a reference instead of a copy, give the destination the Layer 0 sealing
columns and seal the value, or do not write the content. There is deliberately
no way to declare a table exempt.

The rule anchors at the PDO statement layer (`includes/GuardedPdo.php`), under
models, Multi collections, hand-written SQL and plugins alike, because there is
no single write path above it. Owner attribution comes from
`VaultUnlock::secretKey()`, which every read must pass through first; a process
that opened two people's content can name neither, so only ciphertext writes
pass.

**Mail is refused outright.** `EmailSender::send()` will not send from a hot
process unless the call site passes one of the `EmailSender::EGRESS_*`
assertions — `CONTENT_FREE` (built from counts, ids, links and fixed prose),
`USER_COMPOSE` (the user is sending their own message from their own mailbox),
or `ACKNOWLEDGED_FORWARD` (a filter whose owner acknowledged the egress in
writing). Refusing the send is also what keeps protected content out of
`equ_queued_emails`: a message that is never sent is never queued for retry.
An asserted send is attempted **once** — the retry queue stores bodies in the
clear, so a hot process never queues one, whatever the message contains. A
transport failure on a hot send is logged and final.

**AI web egress defers to the owner.** The AI web tools' arguments (a URL, a
search query) leave the box verbatim, so when sealed content is in play they
stop executing inline: in chat the call queues as a pending action whose card
shows the complete outbound argument, and on an autonomous recipe run it is
refused — see the hot-turn egress passage in
`plugins/joinery_ai/docs/overview.md#proposed-actions`.

**Sealed content is opened only in a protected chat.** Protection is a
conversation-level property, so a standard chat that opened sealed content would
be hot but plaintext — unable to persist its next reply or protect what it read.
Rather than patch that, the AI simply does not open sealed content in a standard
chat: `ToolContext::sealedReadsAllowed()` is true for a protected chat and for a
recipe (its whole run is the protected unit), false for a standard chat, and the
read executor excludes an actually-sealed row when it is false — the same
exclusion a locked vault triggers. A standard turn therefore never goes hot, and
an approved fetch's result only ever rides back into a protected conversation,
where the transcript seals. A backstop fails a standard turn cleanly (pointing to
a private chat) if some other path decrypts anyway.

Egress reads a wider predicate than the write-guard, `SealedEgressGuard::
egressGated()`: the process is hot, **or** the conversation is durably
egress-restricted. The hot flag alone is a per-process signal, but a chat
conversation carries sealed-derived context across turns in its transcript, and
each turn is a fresh process. So the first time any turn in a conversation opens
sealed content — a tool reading protected mail or drive, or (on a protected
conversation) decrypting its own sealed history — the conversation is marked
`aic_egress_restricted`, and every later turn arms `restrictEgress()` from that
mark before dispatch. The mark never clears: once the transcript holds
sealed-derived context, a later cold turn could otherwise smuggle it out inside
an outbound URL, so web tools gate behind the owner's approval for the life of
the conversation. A protected conversation gates from its first turn; a standard
conversation gates only after it actually touches sealed content, and never
before. Arming restriction does not arm the write-guard, so an ordinary standard
conversation keeps writing its plaintext transcript normally.

**Units of work.** `SealedEgressGuard::isolate()` runs one independent unit with
its own hot state and restores the caller's afterwards, so a process that does
several unrelated things in a row — a drain slice working through one user's
pending AI runs — does not let the first protected run poison every later one.
The caller is asserting that nothing the unit decrypted is still in play when it
returns; an outer hot state survives, so nesting cannot launder a process cold.
It is a boundary between units, never a wrapper around a write site.

**One sanctioned non-arming open.** Mail held in transit for a protected
domain — relay-fronted Fortress mail waiting, sealed to the owner's key, for
the owner to appear — is opened with `VaultCrypto::openHeldDeliveryBlob()`,
which does not arm the rule. Opening it is first-time delivery arriving late:
the plaintext is exactly what receive-time ingest holds, cold, for the same
message on any server, so it is not a read of stored sealed content. It is
the only such exception, and `tests/vault/sealed_read_paths_test.php` pins
the entire caller set of the low-level decrypt primitives — a new direct
caller fails the suite and has to argue its case against that criterion in
review. Everything stored sealed is read through `openField()`, which arms.

**The accepted gap.** Any copy shorter than the threshold passes. That is a
deliberate trade: the surfaces that actually carry short protected content —
subjects in run rows, summaries on message rows — are sealed structurally by the
record-level rule above, and every new write site prefers a reference anyway.

## Key rotation

`logic/vault_rotate_options_logic.php` / `vault_rotate_verify_logic.php`: a
fresh PRF assertion from an already-enrolled passkey both proves possession
(unwrapping the current secret) and supplies a KEK the ceremony can act on
immediately. (The ceremony bodies for setup, rotation, and the
recovery/passphrase unlocks live in `includes/VaultCeremonies.php` — the
logic files are shells owning gates and WebAuthn; the cores are driven by
tests with synthetic KEKs.) The authorizing wrapping is the presented
credential's **lowest-generation** live wrapping — after a partial failure
both generations' wrappings are live, and a retry must unwrap the oldest
secret, the one still holding un-resealed content. From there, in
crash-safety order:

1. Generate a new keypair and salt; compute `new_key_generation` (`uev_key_generation + 1`);
   note `old_key_generation` (the authorizing wrapping's generation).
2. **Persist the new generation first**, while the old wrappings are still
   live: the authorizing passkey's wrapping, 10 fresh recovery-code
   wrappings, and a resupplied passphrase's wrapping — each tagged
   `uew_key_generation = new_key_generation` — then flip the `uev` row
   (public key, salt, generation, updated time).
3. **Only then** walk every registered consumer's re-seal callback
   (`VaultUnlock::onReseal($callback)`, registration order; signature
   `function(int $user_id, string $old_secret_key, int $old_key_generation,
   string $new_public_key, int $new_key_generation): void`) — the old secret
   is still in hand to open with, the new public key to seal to. A callback
   re-seals **exactly** the items whose per-item generation equals
   `$old_key_generation` (the only generation `$old_secret_key` can open),
   attempts every item, and **throws** if any failed. Any callback throw
   aborts the ceremony here with an error: nothing is retired, every
   unlocker still works, and re-running the rotation converges.
4. **Only after every callback confirms the drain**, soft-delete the drained
   generation's wrappings (`uew_key_generation = old_key_generation`) —
   never the whole pre-rotation list, so wrappings of any other live
   generation survive until a later rotation drains them.

A crash or callback failure at any point up through step 3 leaves both
generations' wrappings live and both secrets recoverable — old wrappings
still unwrap the old secret, and each wrapping's own `uew_key_generation`
says which secret it belongs to (recovery/passphrase wrappings also carry
their own `uew_salt`, so they stay derivable after the vault row's salt has
moved on).

**Re-running the rotation completes it rather than repeating it.** When the
authorizing wrapping's generation is BELOW the vault row's — the signature of
an interrupted rotation — the ceremony runs in completion mode: no new
keypair, no new wrappings, no salt change. It drains the old generation to
the vault's existing current key and retires it, converging to a single live
generation. (Minting a fresh generation on every retry would instead leave
the vault permanently split across two generations — each pass retiring one
and creating another — with every unlock able to read only half the
content.) The completion response carries `completed_pending = true`, no
recovery codes (the current generation's were minted by the interrupted
attempt and never shown), and `regenerate_recommended = true`. Enrollment
ceremonies refuse while two generations are live, so completion is the one
road out of the interrupted state.

**Every wrapping not re-derivable during this same request is invalidated**,
not left dangling — a KEK for another enrolled passkey can only come from
that passkey's own live WebAuthn assertion, which the ceremony doesn't have.
Leaving such a wrapping in place would let it silently unwrap to the
now-superseded secret. The response lists which passkeys (and whether the
passphrase) need re-adding via the ordinary enrollment endpoints afterward.

## Backups

`uev`/`uew` are never excluded from backup sets — losing them is the one
unrecoverable thing (every consumer's content is otherwise-unreadable
ciphertext). The setup and rotation ceremonies both return a `key_file`
payload (the wrapped-key rows, public key, and salt) for the client to offer
as a download — useless without a live unlocker, but the thing that makes a
restored backup's wrappings reconstructible if a `uew` row is ever lost
independently of the vault row itself.

## The consumer contract (server-custody)

1. Seal content with `VaultCrypto`, storing a per-item `*_sealed_key` on your
   own rows and using your own AD row-binding convention. Decide deliberately
   whose key each item seals to, including items that have no obvious owner —
   mail resolves the mailbox's single owner, falling back to the domain's owner
   for mail that belongs to no mailbox, because an item with no resolvable owner
   is stored in the clear.
2. Read via `VaultUnlock::secretKey($user_id)`; treat `null` as locked — a
   one-tap prompt, never an error.
3. Reuse the File decrypt hook for sealed attachments (`File::registerDecryptHook`)
   and the sealed-field model hook for generic reads (`$sealed_fields` +
   `decryptSealedField()`/`decryptSealedFieldStatic()`).
4. Register a re-seal callback for rotation (`VaultUnlock::onReseal()`):
   re-seal exactly the items on `$old_key_generation`, attempt every item,
   and throw if any failed — a swallowed failure would let the ceremony
   retire the only path to that content. The callback must cover **every**
   sealed asset the user can own, unconditionally — the mailbox callback
   re-seals protected-domain DKIM keys (live and rotation-pending) for a
   domain owner even when that user holds no mailbox grants at all.
5. Register a wipe callback if you keep any disposable in-window cache
   (`VaultUnlock::onWipe()`), e.g. a plaintext search index.
6. Own your own levels, scope, and locked-state surfaces (list placeholders,
   a content-action unlock prompt, a native `locked` flag) — the vault
   provides everything below the content. Run web unlock/lock ceremonies
   through `JoineryVaultLock` and listen for the two lock-chip events (see
   *The lock chip*) so your surface and the chip stay in one state.

**One unlock opens every server-custody consumer** — the accepted tradeoff. A
consumer with a genuinely higher sensitivity bar may enroll a second `uev`
scope instead of sharing `user`.

## Deferred work in the window

Some work over sealed content cannot happen when the user asks for it — mail
arrives while they are logged out, and AI features want to run continuously.
That work cannot run from cron either: the secret key lives in APCu keyed to
the **browser session**, so a CLI process has a different APCu segment and
`VaultUnlock::secretKey()` returns null there by construction. It has to run
inside a web request carrying a live window.

`includes/VaultDeferredWork.php` schedules it. A consumer registers from its
`includes/bootstrap.php` — already loaded by `loadConsumerBootstraps()`:

```php
VaultDeferredWork::register(
    'mailbox_parse',
    fn(int $user_id) => bool,                                   // cheap, indexed, no decrypt
    fn(int $user_id, string $key, float $deadline) => int        // work until the deadline
);
```

**What starts it.** `assets/js/vault-presence.js` already beats
`vault_heartbeat` every 25s from every signed-in page with an open vault. The
beat now also reports `work_pending`, and the client fires the separate
`vault_deferred_work` action when it is true, chaining while work remains.
`VaultUnlock::open()` runs one batch immediately so work starts on the tap.

The work never runs inside the beat. A batch can involve a language model whose
timeout is measured in minutes; a beat blocked that long would stack up behind
itself while the window it exists to protect lapsed.

**Order and budget.** Consumers run in registration order — which is
`CONSUMER_PLUGINS` order, and is meaningful: mail parsing precedes AI judging,
because an unparsed message has no fields to read. Each batch is bounded by
`vault_deferred_work_slice_seconds` (default 10), shared round-robin so one slow
consumer cannot starve another. The deadline is checked **between** items, never
inside one — an in-flight model call cannot be cut off cleanly, so a batch may
overrun by a single item. Each consumer's turn holds a Postgres advisory lock on
`(user, consumer)`, so two open tabs never double-process; a held lock is
skipped, not waited on. A consumer that throws is logged and skipped for that
batch, and retried on the next.

**Background work is not user activity.** `secretKey()` normally stamps the
content-decrypt time the Fortress idle cap measures from. If a drain's reads
counted, a tab left open at an empty desk would hold the window open forever and
the idle cap would stop existing. Every batch therefore runs inside
`VaultDeferredWork::withBackgroundWork()`, which sets
`VaultUnlock::setActivitySuppressed(true)` for the duration: the key is still
returned and every policy check still applies, but the TTL is not re-stored, the
`/dev/shm` marker is not touched, and the content stamp is not refreshed. It is a
request-scoped flag rather than a separate accessor because consumer code below
the drain reaches `secretKey()` on its own.

A test asserts the property directly: a window whose only reads come from
background work still expires on schedule.

## The vault-activation flip

A passkey never opens both session sign-in and the vault on the same account
— the platform-wide rule is stated in [Account
Security](account_security.md); this section is the vault's half of the
mechanics. `vault_setup_options`/`vault_setup_verify` refuse to start until the
account has a working password (prompting the user to set one via the
existing password-change flow first) — a vault holder always keeps password
sign-in as the second factor alongside their passkey.

The other half of the flip: once an account has a vault, its passkey stops
signing it in. `logic/passkey_login_verify_logic.php` checks
`UserEncryptionVault::loadForUser($user_id)` right after the WebAuthn
assertion verifies and, if a vault exists, undoes the session
`PasskeyService::verifyAuthentication()` just established and rejects with a
message pointing the user at their password. `logic/passkey_login_options_logic.php`
makes the same check for an email-scoped request (the discoverable/usernameless
flow can't know the account in advance, so the verify-side check is the actual
enforcement — the options-side check is only an earlier, friendlier rejection
for the common case). Passkey-as-step-up and passkey-as-vault-unlock remain
available on every account regardless of vault status — only passwordless
sign-in is withdrawn.

## Tests

The vault test estate lives in `tests/vault/` (crypto refusals, the unlock
window, ceremony state machines, rotation crash-injection) plus
`plugins/mailbox/tests/mailbox_reseal_test.php` (the consumer contract
against real rows); shared fixtures in `tests/lib/vault_fixtures.php`. The
window suite exercises APCu and skips under plain CLI — run it directly with
`php -d apc.enable_cli=1 tests/vault/vault_unlock_window_test.php`.

## Settings

- `vault_unlock_idle_minutes` (default `30`) — the unlock window's idle
  timeout.

No RP-ID, origin, or PRF-context setting here — see [Passkeys](passkeys.md)
for those (the vault uses the `vault-kek` PRF context).

## Client-custody scopes

A client-custody scope (`uev_custody = 'client'`) is unwrapped **only in the
browser** — the server never holds the secret key and never sees plaintext. The
shared client-custody layer lives in **core** so every consumer reuses it:

- **`assets/js/vault-crypto.js`** — the browser crypto module: WebCrypto
  AES-GCM/X25519, the vendored hash-pinned Argon2id WASM for the
  passphrase-fallback KDF, KEK derivation (passkey PRF / recovery / passphrase),
  wrap/unwrap of the vault secret key, ECIES seal/open of a data key, and the
  `encrypt()→blob` / `blob→decrypt()` content contract.
- **`assets/js/vault-keyring.js`** — the scope-parameterized enrollment, unlock,
  and recovery ceremony, driving the crypto module against the server actions.
- **`includes/VaultClientCustody.php`** + the core `logic/vault_client_*`
  actions — custody-agnostic **opaque-blob storage**: create the keypair record,
  return the keyring view (public key, KDF salt/params, wrapped-secret blobs),
  add/remove/replace unlocker wrappings, consume a one-time recovery key (which
  emails the account — the server can't verify code knowledge, so visibility is
  the defense against a session-rider burning codes). The secret key is never
  unwrapped server-side.

Each client scope has its **own** keypair and its **own** PRF context
(`vault-passwords-kek`, `vault-drive-kek`), so unlocking one never opens another.
The built consumers are the [password manager](../plugins/vault/docs/overview.md)
(scope `passwords`) and [Drive encryption](drive_encryption.md) (scope `drive`,
reusing this same layer and adding per-file content encryption and multi-user key
sharing on top).
