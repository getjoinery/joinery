# Unseal daemon: the vault key never enters the pool

**Status:** Spec, written 2026-09-12 and reviewed the same day (eleven
operations folded to four; B1, a key-export door in the first draft, closed;
recovery redesigned as a self-healing ladder after the owner ruled out any
shell step). Closes S13 in `security_inventory.md`,
the second of the four separations ("keys leave the pool"). **WP1 built
2026-09-12** (uncommitted): the `VaultKey` seam is in, pool-held — every
production site and resealer takes the object, the four enrolments take a
fresh unlocker and wrap through `open()`, `MailboxContacts` keys its blind
index by a sealed per-user key (`MailboxContactIndexKey`, table
`mck_mailbox_contact_index_keys`, created on dev), and
`sealed_read_paths_test` pins the secret-taking `SealedBox` primitives to
`PoolVaultKey`; a reserved wrapping row (empty, between `reserve()` and
`storeWrapped()`) is excluded from every query and retired by the next
`reserve()` for the same unlocker. `php tests/run.php db --changed` green (413 suites). WP2–WP6 unbuilt.
The design is Mitigation A of `vault_key_memory_exposure.md`, worked through
against the code as it is today; that spec's open decision 1 is answered here.

## The goal, in one sentence

Opening a mailbox stops putting the owner's long-term key where every PHP
process on the box can copy it, so a break-in during an open window can read
what the owner is reading but cannot take the key home.

## What this closes

When a member unlocks their vault today, PHP unwraps their X25519 secret key
and stores it in APCu, the php-fpm master's shared memory, for the life of the
window. Anything that can run PHP under that master reads it with one
`apcu_fetch`, for every member with a window open, on every site that master
serves. With the key, an attacker decrypts everything ever sealed to that
member, off the box, against any copy of the database, forever. The bytes also
stay in the segment after the window closes until the allocator reuses them,
and reach swap and core dumps unless the host is configured not to let them.

After this spec, the secret key lives in one small process under its own user.
PHP holds a window handle, not a key, and asks that process to open one
32-byte per-item key at a time. What the pool ever holds is the keys to the
rows it is reading, which is the plaintext it was about to send to the browser
anyway. A break-in during the window is still a full read of that window (that
is accepted, `docs/sealed_vault.md` § One unlock opens everything); what stops
existing is the upgrade from "read this window" to "own this member's past and
future". The daemon's memory is locked and undumpable, which closes the residue
and disk-image route (route 3 of the ranked list) structurally instead of by
advisory host check.

Residual 2 and residual 7 of the inventory's threat model are written on the
assumption this is built.

## The shape today, verified 2026-09-12

**One accessor hands out the key.** `VaultUnlock::secretKey($user_id,
$scope)` (`includes/VaultUnlock.php:248`) returns the raw secret from APCu, key
`vault:{session_id}:{user_id}:{scope}`, re-storing it on every read to extend
the idle TTL. A process with no browser session (cron, the CLI) can never see a
window by construction, and that stays true.

**The crypto chokepoint is three methods.** Every asymmetric open goes through
`VaultCrypto::openItemDek()`, `openBulkDelivery()` and
`openHeldDeliveryBlob()` (`includes/VaultCrypto.php:69,89,161`), all of them
`crypto_box_seal_open` under the secret key (the public key is derived from
it). Content is never opened asymmetrically: every field, file, attachment and
index blob is AEAD under a per-item DEK that the secret key unwraps. So the
bytes that must cross to a daemon are small, and the megabytes never do.

**51 call sites reach `secretKey()`** (34 in production code, 17 in tests),
enumerated 2026-09-12. What they do with it:

| Use | Sites | Where |
|---|---|---|
| Unwrap a DEK and nothing else | 19 | `SystemBase` sealed fields, `DriveSealed`, `ChatSeal`, `ActionQueue`, `RecipeRun`, `ConversationKeyGrant`, `InboundEmailMessage`, `MailboxContacts` |
| Wrap the secret under a new unlocker | 4 | `vault_add_passkey_verify`, `vault_passphrase_enroll`, `vault_regenerate_codes`, `passkey_register_verify` — all through `UserEncryptionWrapping::createWrapped()` |
| Unwrap a signing key sealed like a DEK | 2 | `DirectIdentity::openSecretKey()` (Direct signing key), `MailboxDkimSigner::resolveFor()` (DKIM RSA key) |
| Derive a subkey by HMAC | 3 | `MailboxContacts::addressHash()`: `hash_hmac('sha256', 'joinery:contact-index:v1', $secret)` keys the address index, so every rotation orphans the hashes (tolerated as drift, `listForMailbox()`) |
| Hand the key down a drain | 4 | `VaultDeferredWork::drain()` gives it to every consumer's drain callback; `MailboxService` gives it to the search fold and `DeferredIngest`; the protection ceremony the same |
| Probe "is a window open" | 5 | the bytes are only compared with null |

Beyond these, the rotation ceremony hands `$old_secret_key` to every
`onReseal` callback (`VaultCeremonies::drainAndRetire()`, ten callbacks across
core, mailbox, joinery_ai, Drive and the Direct spool), and `setup()` /
`rotate()` generate a keypair in PHP and wrap its secret under each unlocker's
KEK before opening the window with it.

**Where the key enters.** The browser sends the WebAuthn PRF output (32 bytes)
to `vault_unlock_passkey_logic`, which unwraps `uew_wrapped_secret_key` under
it. The recovery-code and passphrase paths derive their KEK server-side. So the
unlock request carries key material through the pool once; that stays, and is
stated as residual 2.

**Nothing else stores the key.** The only `apcu_store` of key bytes is inside
`VaultUnlock`; no session, file or subprocess holds it. Two DEK caches exist
(`VaultCrypto::$dek_memo`, `ConversationKeyGrant::$open_key_cache`) and both
hold per-item keys, which this spec leaves in the pool on purpose.

**Precedents to reuse.** The parser jail shipped a Go binary through
`GoBinaryPublisher` (prebuilt per arch in the tree, inside the signed archive)
with a core host installer that `_plugin_installers_start.sh` runs at every
root moment and the host converger repeats. `install_agent.sh` and
`install_host_converger.sh` supervise a process two ways with one rule: a
systemd unit where PID 1 is systemd, a `cron.d` keepalive otherwise (the eight
containers). `provision_relay.sh` swaps a running binary by rename so a live
process never sees `ETXTBSY`. PHP has no unix-socket client anywhere today;
its helper patterns are `proc_open` with pumped pipes and file-drop plus a
unit. This is the first socket.

## The design

### One daemon, one socket

`joinery-unseal` runs as system user `joinery-unseal` (no shell, no home, no
groups), installed root-owned at `/usr/local/sbin/joinery-unseal` outside the
tree, listening on `/run/joinery-unseal/unseal.sock` owned
`joinery-unseal:www-data`, mode `0660`. It accepts a connection only from a
peer whose `SO_PEERCRED` uid is the pool user given at install (`--allow-uid`),
so the socket mode is belt and the credential check is braces. It has no
network at all, opens no file after startup, reads no database, and holds no
configuration beyond its argv. Written in Go like the agent, the jail launcher
and the relay sealer, built by a `UnsealDaemonPublisher extends
GoBinaryPublisher`, refused at publish time if the build fails.

It holds **windows**. A window is a secret key plus the numbers that bound it,
tagged with the user id and scope PHP gave at open, addressed by a random
32-byte handle the daemon minted. PHP stores the handle where it stores the key
today (`vault:{session_id}:{user_id}:{scope}` in APCu), so every piece of
window policy PHP has, the session binding, the idle TTL, the Fortress caps,
the heartbeat staleness, the audit rows, keeps working unchanged. What changes
is what the APCu slot is worth to a thief: a handle opens per-item keys only
while the daemon still holds that window, and is worthless the moment the
window ends or the daemon restarts. A key was worth everything, forever.

### The operations

One frame per request and per reply: a header length, a small JSON header,
then a raw body. The body carries whatever is bytes (sealed blobs in, plaintext
out), so nothing is base64-encoded anywhere, and a multi-megabyte Direct part
costs one copy per side rather than a third more bytes and a JSON parser
walking one giant string. Replies carry `ok` or an `error` code. Four
operations.

| Op | In | Out | For |
|---|---|---|---|
| `open` | user id, scope, idle seconds, absolute seconds; either a wrapped secret with its KEK and AD, or nothing; optionally a list of (KEK, AD) pairs to wrap under | handle, public key, the requested wrappings | with a wrapped secret: every unlock path (passkey, recovery, passphrase); the daemon unwraps (`v1.aead.` under the KEK, the format `SealedBox::unwrapKey()` reads) and keeps the result. With nothing: `setup()` and `rotate()` mint a fresh keypair whose secret never exists in PHP. The wrap list is the only way the secret ever leaves the daemon, and only in the request that presented an unlocker for it (see B1) |
| `unwrap` | handle, a list of raw sealed blobs, activity flag | the list of plaintexts | every asymmetric open: a DEK (PHP strips the `v1.seal.` framing first), a signing key sealed the same way, the contacts index key, a held Direct part. Always a list, usually of one; a page of rows or a fold batch of 200 is one round trip. One size cap on the frame at the Direct part ceiling |
| `close` | handle, or user id | closed, counters | `lock()` by handle; `lockAll()` by user id, so a credential event ends every window even one the APCu sweep cannot see |
| `status` | nothing, or a user id | version, window count, uptime; or whether that user has any window | the health check, and `hasAnyOpenWindow()`; never a list of who is open |

**B1, found in review 2026-09-12: a standalone wrap operation is a key-export
door.** The first draft had `wrap(handle, KEK, AD)`, which hands back the
long-term secret wrapped under any KEK the caller names. An attacker resident
in the pool during a window would call it with a KEK of their own, take the
result home, and unwrap it offline: exactly the permanent loss the daemon
exists to prevent, through the daemon's own front door. So wrapping is not an
operation. It is a list on `open`, answered only in the request that
presented a real unlocker (a KEK that opened an existing wrapping, or the
first-time setup that mints the key). An attacker present at that instant
already holds the KEK and can unwrap the wrapping from the database without
the daemon, so nothing is lost there; an attacker who arrives later in the
window has a handle that unwraps DEKs and cannot export the key under any
circumstances. The user-visible consequence: adding a passkey, enrolling a
bypass phrase, or regenerating recovery codes asks for one fresh tap of an
enrolled passkey (or the phrase, or a code) in that same request, the way
every end-to-end system asks for the old credential before adding a device.
Rotation already asks. `vault_add_passkey_verify`, `vault_passphrase_enroll`,
`vault_regenerate_codes` and `passkey_register_verify` stop reading the window
and start taking an unlocker.

Things that looked like operations and are not:

- **Re-sealing for rotation** is `unwrap` then a seal to the new public key in
  PHP, which needs no secret. The DEK crosses into the pool for a moment, as
  it does on every read of that row, and an attacker resident during the
  window can already unwrap any sealed DEK it reads from the database, so a
  daemon-side re-seal would protect nothing the window oracle does not
  already give away. The ten resealers keep their open-then-seal shape.
- **Deriving the contacts index key** goes away with a change to
  `MailboxContacts`: a random per-user index key sealed to the vault like any
  DEK, opened with `unwrap`, replacing the HMAC of the vault secret. Today's
  derivation changes on every rotation and orphans every address hash, which
  the class already tolerates as drift ("a rotation can leave two rows for
  one address"); a sealed key rides the ordinary reseal path and survives
  rotation. No migration: rows hashed under the old derivation drift exactly
  as they do after a rotation today.
- **Touching the window** is any `unwrap` flagged as activity. That is the
  rule `docs/sealed_vault.md` already states, that only a content decrypt
  extends the window, and PHP stops re-storing the APCu slot on a bare fetch
  so the two clocks agree.

The unwrap count per window comes back on `close` and goes into the existing
`vault_window_closed` audit row.

The daemon serves one goroutine per connection with a per-request timeout and
a connection cap, so a wedged pool worker holds one slot and nothing else.
PHP zeroes the KEK and the wrapped blob with `sodium_memzero` the moment
`open` returns; they were the only key material that ever sat in the pool.

### The daemon enforces its own end

PHP's policy is read-time: a window past its cap is noticed by the next read.
The daemon holds the two hard numbers PHP passes at open, the idle limit and
the absolute cap, refreshes the idle clock on every `unwrap` flagged as
activity (the same flag `VaultUnlock::$activity_suppressed` clears for
deferred work, so background drains extend nothing there either), and zeroes
the key when either clock runs out, whether or not PHP ever asks again. So a
window that PHP forgot, or whose APCu meta an attacker deleted, still dies on
schedule in the only place the key exists.

There is one idle clock, not two. Today `vault_unlock_idle_minutes` (the APCu
TTL) and the Fortress idle cap both mean "no content decrypt for this long";
PHP folds them and passes the smaller. The APCu metadata shrinks to the arming
time and the heartbeat stamp, the two things the daemon does not know. The
daemon also carries its own ceiling on the absolute cap
(`--max-window-seconds`, default seven days, the Private cap), so a window
where no consumer registered a cap is still not immortal; today anything that
keeps reading keeps such a window open forever. PHP-side policy stays
authoritative for the *earlier* ends (heartbeat staleness, IP change,
explicit lock) and calls `close`.

### The PHP seam: a key you can use but not read

`VaultUnlock::secretKey()` stops returning a string. It returns a `VaultKey`,
an object with two methods, or null when locked:

```php
$key = VaultUnlock::secretKey($user_id);         // ?VaultKey
$dek = $crypto->openItemDek($sealed, $key);       // VaultCrypto takes the object; unwrap
$pub = $key->publicKey();
```

There is no wrap method on the object, by B1. Wrapping happens only inside
`VaultUnlock::open()`, which takes the unlocker's KEK and an optional list of
KEKs to wrap under and returns the wrappings with the window; the enrolment
logic files call that and store what comes back through `createWrapped()`.
Rotation re-seals with the public key it already has:
`$crypto->sealItemDek($crypto->openItemDek($sealed, $old_key), $new_public)`,
which is what every resealer writes today.

Two implementations. `DaemonVaultKey` holds a handle and talks to the socket.
`PoolVaultKey` holds the bytes in PHP and does the same operations locally with
`SealedBox`; it is the path the safe-tier tests run on, and the path on a box
where the daemon was never installed (below). Nothing outside `PoolVaultKey` can read the
bytes: there is no getter, and `tests/vault/sealed_read_paths_test.php`, which
already pins the three open methods, gains a check that `SealedBox::openDek`,
`openBinary`, `unwrapKey`, `wrapKey` and `generateKeypair` are called from
`PoolVaultKey` (and `SealedBox` itself) only, with two named exceptions that
use `SealedBox` with a key that is not a vault key: `RelaySpoolConsumer`
opens relay spool envelopes with the server's own transport secret, and
`mailbox_relay_class` mints that transport keypair. `VaultCrypto` opens
through `VaultKey::unseal()` and touches none of them. `VaultCrypto`'s DEK
memo keys on the handle's id instead of the secret bytes, so a request still
unwraps each row once, and gains `openItemDeks(array $sealed, VaultKey $key)`,
which fills the memo from one round trip. The callers that already hold a set
of rows use it: the thread list, the fold batch, the contacts batch upsert,
the resealers and the deferred-work drains. A collection of sealed models
prefetches its rows' DEKs the same way as it loads, so the per-row hook that
follows never touches the socket. That is what keeps the hottest pages at one
trip per page rather than one per row.

The type change is the migration plan: every one of the 34 production sites
fails loudly the first time it treats the return as a string, and the five
probe-only sites become `isOpen()`. The two contracts that hand the key
downward change their signature to the object: `onReseal` callbacks receive
`(int $user_id, VaultKey $old_key, int $old_generation, string $new_public_key,
int $new_generation)`, and `VaultDeferredWork` drain callbacks receive
`(int $user_id, VaultKey $key, float $deadline)`. The resealers change only
the type of what they are handed. `MailboxContacts` gains the sealed index
key. `MailboxIndex::persistOrThrow()` drops the secret parameter it never
used.

### The ceremonies

`VaultCeremonies::setup()` and `rotate()` call `open` with no wrapped secret
and the full wrap list (the passkey's PRF output, the ten recovery-code KEKs,
the bypass phrase's KEK) in that one request, and receive a handle, a public
key and every wrapping, which `createWrapped()` stores. The rotation's drain
calls every resealer with the *old* generation's key object (an `open` under
the fresh PRF output, as today, but the result stays in the daemon), retires
the old wrappings, then closes the old handle and makes the new handle the
window (`VaultUnlock::open()` returns a `VaultKey`). A crash between minting
and flipping leaves a daemon window nobody references; it ages out on its own
clock, and the orphan-wrapping cleanup `rotate()` already does covers the
database side.

Every later enrolment is an `open` under a presented unlocker with one wrap
entry: the new passkey's PRF output, the new phrase's KEK, or the fresh
recovery codes' KEKs. The window that results replaces the session's current
one. The existing re-enrol ceremony (`VIA_REENROLL`) already has this shape.

The KEK still passes through PHP at unlock and at enrolment. That is the one
transit residual 2 names. Moving it out (the browser seals the KEK to the
daemon's own public key, and does the recovery and passphrase KDFs itself,
which `assets/js/vault-crypto.js` already can) is a real gain against a passive
reader and none against an attacker who can rewrite the unlock page to serve
their own public key, which anyone resident in the pool can. It is left out of
the bar and noted under what stays out.

### Memory

The daemon locks every page (`mlockall(MCL_CURRENT | MCL_FUTURE)`, so stacks
and future allocations included), marks itself non-dumpable
(`prctl(PR_SET_DUMPABLE, 0)`), sets `RLIMIT_CORE` to zero, and zeroes a
window's key on close or expiry. Go's collector does not move heap objects,
and with every page locked no copy the runtime or a library makes reaches swap
or a core. Under systemd the unit adds `MemoryDenyWriteExecute`,
`ProtectSystem=strict`, `PrivateNetwork=yes`, `NoNewPrivileges`,
`LimitCORE=0`, `LimitMEMLOCK=infinity`, `RuntimeDirectory=` for the socket.
Locking a whole Go process is what HashiCorp Vault does, and it needs the
limit unbounded rather than merely generous: Go's heap arenas lock as the
runtime touches them, and a limit set from the window count fails an
allocation at the worst moment. In a container the cron keepalive raises `RLIMIT_MEMLOCK` with
`prlimit` before exec, the same last-hop trick the jail launcher uses, and the
process-level settings do the rest. What none of this stops is root reading
the process, which is residual 7 and is now forensic work rather than a file
read.

One consequence to state plainly: **a restart ends every window.** An upgrade
that changes the binary, or a crash, means every member re-unlocks with one
tap. PHP learns of it on the next operation (`unknown_handle`), treats it as
locked, and audits the close with a new reason, `daemon_restart`. The
installer restarts only when the bytes changed, by rename.

### Installing and supervising

`install_unseal_daemon.sh` joins `CORE_INSTALLERS` in
`_plugin_installers_start.sh` after the jail's, with the same contract:
idempotent, root, non-interactive, exit 0 when not applicable. It creates the
user, installs the prebuilt binary for `uname -m` (ELF check, rename swap),
writes the unit or the `cron.d` keepalive by the `install_agent.sh` rule,
passes `--allow-uid` for the pool user and `--socket` for the path, writes
the install marker, and smoke-tests with `status` as the pool user. The host converger repeats it at
every root moment, so a self-hosted box that upgrades from the browser
converges without a shell; a container gets it at container start.

A shell gate, `tests/security/unseal_daemon_gate.sh` (`needs:
[unseal-daemon]`), checks the socket's owner and mode, that a connection from
another uid is refused, that `status` answers, that `/proc/<pid>/status` shows
locked memory and no dumpable flag, and that the process has no network
namespace with routes. A PHP suite, `tests/vault/unseal_daemon_test.php`,
spawns the tree's own prebuilt binary on a temporary socket as the test user
and runs the whole protocol against it: open with and without a wrapped
secret, open with a wrap list, unwrap, the idle and absolute clocks and the
seven-day ceiling, close by user id, status for a user, a wrong-KEK open, an
oversized `unwrap`, a handle after close, and that no request shape returns
the secret in any form other than a wrapping from `open`. It needs no install, only the binary for the
box's arch in the tree, and skips otherwise.

### When the daemon is missing or down: the ladder

Nobody is ever asked to open a shell. Whether the daemon is used is decided
by an install-time fact plus a bounded, automatic ladder of recoveries, and
the pool path (the key in APCu, exactly as today) is the last rung, never
the first.

**Never installed.** The installer leaves a marker
(`/etc/joinery-unseal/installed`). Where it is absent, a self-hosted box on
which no root moment has run the installer yet, `VaultUnlock::open()` takes
the pool path, logs once per process, and `VaultHealth`'s sixth check is
unmet, naming the installer; `AdminNotices` carries the finding for
superadmins as `ParserJailNotice` does. The parser jail's decision applied
again: a box without root keeps a working vault, never a gate, and the
converger installs the daemon at the next root moment.

**Installed and not answering.** Three rungs, each automatic, each short:

1. **Seconds: restart.** `Restart=always`, `RestartSec=5` under systemd;
   the `cron.d` keepalive in a container. A hang is caught by
   `WatchdogSec=30`, fed by `sd_notify` from the daemon, and in a container
   by the keepalive running `joinery-unseal ping` with a timeout every
   minute. During those seconds an unlock fails with its own code,
   `vault_service_unavailable`, and the unlock page waits three seconds and
   retries once on its own, so a member who taps during a restart sees
   nothing. Only after the retry does the prompt say that the vault service
   is restarting and to try again shortly; it never reads as a wrong
   passkey, or a member burns a recovery code they did not need.
2. **A bad binary: the previous build.** The installer keeps the last good
   binary beside the current one. Three crashes inside one minute and the
   supervisor launches the previous binary and marks the node degraded. A
   release whose daemon will not start heals itself to the last release's
   daemon with nobody involved, which is a better outcome than the pool
   path for the most likely long outage, and the finding tells the plane
   which release to look at.
3. **Minutes: the pool path.** If nothing has answered for five continuous
   minutes through both rungs above, `VaultUnlock::open()` takes the pool
   path for new windows, with the finding on every admin page and in the
   node status. The moment the daemon answers again, the next unlock uses it.
   A window opened on the pool path does not migrate; it ends on its own
   terms.

Why the five-minute floor, stated plainly: the fallback is the one thing an
attacker in the pool could want. Falling back costs nothing against every
ordinary cause of a dead daemon (a bad build, the OOM killer, a reboot),
because the pool path is what every node runs today. It costs exactly one
thing: an attacker already in the pool who also holds a way to crash the
daemon on demand (a bug in its request parsing) could crash it, wait for the
fallback, and harvest the long-term key of everyone who unlocks afterward.
The floor makes that a five-minute campaign through two automatic
recoveries, every restart logged with the peer pid of the last request, on
a surface that is a length prefix and one small standard-library JSON
object, fuzzed in WP2. Locking instead would trade that for a vault nobody
can open until a human acts, which the owner has ruled out.

### Every other way it dies

The fact that makes every failure survivable: **the daemon holds nothing
durable.** Every secret in it is an in-window copy of a wrapping that sits in
`uew_user_encryption_wrappings`, openable by the member's passkey, recovery
code or phrase. Writes never touch the daemon at all, because a new row's DEK
is sealed to the vault's *public* key in PHP. So the worst outcome of any
failure is that reads stop until the ladder brings it back and every member
taps once more. No data is lost, nothing needs restoring, and there is
nothing to back up. A database restored onto a new box works with the same
unlockers and a fresh daemon. A window that ends because the daemon went away
is reported as locked, never as an error, with the audit reason
`daemon_restart`.

| Failure | Effect | Which rung |
|---|---|---|
| Crash (a bug, a signal, the OOM killer) | every window ends | 1 |
| Hang | as a crash, once the watchdog fires | 1 |
| Crash loop from a bad build | every window ends; would be a long outage | 2, then 3 if the previous build is bad too |
| Memory-lock limit reached | new `open` refused, existing windows fine | not expected: a window is a few hundred bytes, the daemon caps windows at 10 000, the unit sets `LimitMEMLOCK=infinity`; the refusal is logged with the count |
| Socket directory or mode drifts | PHP cannot connect | 3 until the converger's next root moment restores it |
| Reboot | windows gone; locked until the daemon is up | the unit is enabled; the keepalive has `@reboot`; seconds |
| Upgrade with a changed binary | windows end once | by design: rename swap, restart only on changed bytes, one tap per member |
| Crash mid-request | that request reads as locked | nothing to repair: reads are idempotent, writes did not need the daemon |
| Crash mid-rotation | the new generation's wrappings were committed before any row was re-sealed (`rotate()` commits the wrappings, then drains) | the existing fail-loud drain: "run the rotation again", which re-opens the old generation and continues |
| Crash mid-setup | the minted key is gone before the vault row flipped; nothing was sealed to it | the member runs setup again |
| The daemon itself exploited | the attacker holds the in-window secrets, as root would | its own uid, no network, no files after start, seccomp; residual 7 |

The daemon uses the monotonic clock for both caps, so a wall-clock jump
neither ends nor extends a window. Its unit carries `OOMScoreAdjust=-900`,
since a locked few megabytes are the last thing worth reclaiming under
pressure.

**Managed nodes.** The ladder runs on the node; what we add is sight. Today
`VaultHealth` is surfaced only to that node's superadmin and nothing reports
it to the plane, so WP3 puts the daemon's state (answering, on the previous
build, on the pool path) into the node status the plane already polls. A
degraded node shows on the fleet dashboard, and the fix is ours from there: a
reinstall request the converger carries out, or a release rollback. The
customer is never asked to do anything.

**Self-hosted boxes.** The same ladder, the same finding on every admin page,
and the converger reinstalling at the next root moment. There is no switch
to turn the daemon off: a self-hoster who wants the old path has no reason
to, and a switch that removes a protection with no use case is surface for
nothing. `check_vault_health.php` reports the same findings from the shell
for anyone scripting.

### Overhead

A unix-socket round trip on the same box is on the order of 100 µs, and the
design spends one per batch, not one per row. A thread list prefetches its
page's DEKs in one trip; a fold batch of 200 (`MailboxIndex::FOLD_BATCH`) is
one trip, so a full 100 000-message rebuild gains a few hundred trips spread
over a drain that is already checkpointed and budgeted by
`vault_deferred_work_slice_seconds`. An `unwrap` of a held Direct part copies
the part across the socket twice as raw bytes; parts arrive rarely and are
bounded by the spool caps. The crypto itself is the same X25519 open PHP runs
today, and a passphrase unlock's Argon2id stays slow on purpose. WP4 measures
the thread list and one fold batch before and after and records the numbers
in the doc.

## What stays out, and why

- **A rate limit on unwraps.** Every unwrap is counted and the count is
  audited, but nothing refuses. A limit high enough to let the fold run is
  high enough for an attacker to drain a mailbox at the fold's pace, and a
  limit lower than the fold breaks search for every member. A number that
  stops nobody and breaks something is not a control; the honest control is
  the count in the audit row, where an absurd number is visible.
- **The KEK sealed to the daemon in the browser.** See the ceremonies above:
  the gain is against a passive reader only, and it drags the recovery and
  passphrase KDFs into the browser. Post-1.0, if ever.
- **Per-site separation on a shared php-fpm.** The daemon tells peers apart
  by uid, and every site on one master is `www-data`. Sites sharing a master
  share reach, exactly as they share APCu today; managed nodes run one site
  per container and dev is the known exception. A per-site pool user is an
  S10-shaped question, not a daemon question.
- **Client-custody scopes.** The password vault and Drive scopes never hand
  the server a key; nothing here touches them.
- **The per-item DEKs the pool holds during a request.** They are the keys to
  the plaintext the request is producing; hiding them would hide nothing.
- **The daemon as the agent, or reachable by the plane.** It has no channel
  to anything but the local socket, on purpose: an unseal RPC the management
  node could call is the skeleton key this platform refuses to have.
- **Moving the MIME parse, the fold or any consumer logic into the daemon.**
  It does asymmetric opens and wraps and nothing else; the moment it
  understands a message it is a second application.

## Interfaces to the other items

- **S10, the read-only tree.** The binary lives outside the tree, root-owned,
  installed by a host installer the converger runs; the pool never writes it.
- **S5, the parser jail.** Same publisher base class, same installer shape,
  same advisory-fallback rule, same `prlimit` last hop in the container.
- **S9, package signing.** The prebuilt binaries ride the signed core archive
  and are covered by `RELEASE_MANIFEST`; the converger verifies the package
  before root runs the installer.
- **The sealed egress guard.** `secretKey()` keeps calling
  `SealedEgressGuard::noteScopeOpened()`; `openField()` keeps arming the hot
  turn. The guard never saw key bytes and does not now.
- **VaultAudit.** One new close reason, `daemon_restart`; the counters on the
  close row. The session handle digest is unchanged.
- **VaultHealth and AdminNotices.** The sixth check and its notice.
- **`hasAnyOpenWindow()`.** Becomes `status` for one user. Cron may ask the
  daemon because it holds no handle and so still cannot unwrap anything; the
  `/dev/shm` marker file, its touch on every read, the glob unlink in
  `lockAll()` and the "at worst delayed one interval" caveat all go. On the
  pool path the marker stays, since APCu is per process there.

## Decisions this spec takes

- **Nobody is ever sent to a shell.** A never-installed daemon is advisory,
  mirroring the owner's 2026-09-10 decision on the jail; an installed daemon
  that dies climbs the ladder (restart in seconds, the previous build on a
  crash loop, the pool path after five silent minutes) with no human involved.
  The five-minute floor before the pool path is the one concession to the
  crash-bug downgrade, and the owner chose availability over locking (2026-09-12).
- **The secret leaves the daemon only wrapped, only in the request that
  presented an unlocker.** B1 above. Enrolments ask for a fresh tap.
- **Count, do not limit.** Unwrap counts land in the audit row; no refusal.
- **The KEK transits the pool at unlock.** Sealing it to the daemon from the
  browser is out of the bar.
- **A restart ends every window.** No handoff of live keys across an upgrade;
  the cost is one tap per member, the alternative is a key on disk.
- **Nothing durable lives in the daemon.** Recovery from any failure is
  automatic; there is no state to back up, restore or migrate.
- **The seam is a type.** `secretKey()` returns an object with no getter, and
  the raw bytes are unreachable outside `PoolVaultKey` by test, not by
  convention.
- **Four operations.** Re-seal, derive, touch, generate, wrap and a per-user
  close were all folded (2026-09-12): each was two existing operations, a
  promise the window oracle already breaks, or (wrap) a hole.
- **One idle clock, and a ceiling on every window.** The daemon takes the
  smaller of the idle setting and the Fortress idle cap, and refuses an
  absolute cap above seven days.
- **The contacts index gets its own sealed key.** It fixes the hash drift
  every rotation causes today and removes the only HMAC the daemon would have
  had to know about.
- **Held Direct parts cross the socket whole.** The sender-side envelope that
  would make them 32-byte opens is a Direct protocol change and waits for one.

## Open questions

None for the design. One for the rollout: whether dev, which serves three
sites through one master, runs the daemon from the first release (the spec
assumes yes; the installer treats it like any node).

## Work packages

1. **WP1 — the seam, pool-held only.** `includes/VaultKey.php` (interface),
   `PoolVaultKey`, `VaultCrypto` 1.5 taking the object, `secretKey()` and
   `open()` returning the object and taking the wrap list, `createWrapped()`
   storing what `open()` returns, `VaultCeremonies` minting through `open()`,
   the four enrolment logic files taking a fresh unlocker (B1), the
   `onReseal` and drain callback contracts,
   all 34 production sites and the ten resealers (type only), the five probes
   on `isOpen()`, the `MailboxContacts` sealed index key with its reseal
   entry in the mailbox bootstrap, the dead parameter removed.
   `sealed_read_paths_test` extended to pin where `SealedBox` is called. Every
   vault, mailbox, joinery_ai and Drive suite green with no daemon anywhere.
   This package changes no behaviour and ships on its own.
2. **WP2 — the daemon.** Go source under
   `maintenance_scripts/install_tools/joinery_unseal/`, the protocol above,
   `UnsealDaemonPublisher`, prebuilt binaries in `bin/`, the Go unit tests,
   a fuzz target over the request framing and JSON (the daemon must never
   exit on any input from the socket; a bad request is a reply, not a crash),
   the `ping` and `--selftest` subcommands, and
   `tests/vault/unseal_daemon_test.php` driving the real binary on a
   temporary socket.
3. **WP3 — install and supervise.** `install_unseal_daemon.sh` in
   `CORE_INSTALLERS`, the previous-binary keep and the
   three-crashes rule, the unit (restart, watchdog, `OOMScoreAdjust`) and
   the keepalive (`@reboot`, the `ping` probe), the `ping` subcommand, the
   gate, `VaultHealth`'s sixth check, the notice, the daemon's state in the
   node status the plane polls, the unlock page's automatic retry,
   `publish_upgrade.php` refusing on a failed build.
4. **WP4 — `DaemonVaultKey` and the switchover.** The socket client (the
   platform's first; one class, `UnsealClient`, one connection per PHP
   request reused for every trip in it, a connect timeout and one retry; a
   persistent connection per worker only if the measurements say so),
   `openItemDeks()` and the collection prefetch, `open()` choosing by the
   install marker, the pool path's one
   log line, the five-minute pool-path rung and its return to the daemon,
   `lock()`/`lockAll()` closing handles, `unknown_handle` as locked
   with the `daemon_restart` reason, `vault_service_unavailable` on the
   unlock endpoints and its wording in the prompt, the folded idle clock and the shrunken
   APCu metadata, `hasAnyOpenWindow()` on `status`, the count into the audit
   row, the two timing measurements recorded.
5. **WP5 — the record.** `docs/sealed_vault.md` § The unlock window and
   § Host hardening rewritten as the current state (the key lives in the
   daemon; the host checks guard the pool's per-item keys and the fallback);
   the S13 row points here; `vault_key_memory_exposure.md` open decision 1
   answered.
6. **WP6 — release and live proof.** A release, the fleet upgrade, then on
   jeremytunnell with the owner watching: unlock by passkey, read a thread,
   search (a fold), add a passkey, rotate, lock, an upgrade that restarts the
   daemon and the one-tap re-unlock after it, and the audit rows for each.
   The spec moves to `implemented/` after that walk, not before.
