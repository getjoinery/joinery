# Protection Levels

Every service that stores member content offers the same ladder of protection,
and changing a level follows one sequence wherever it happens.

## The ladder

`ProtectionLevel` (`includes/ProtectionLevel.php`) owns the order and the spelling:

| Level | Meaning |
|---|---|
| `standard` | The server manages this for you (plaintext). |
| `private` | Encrypted at rest, server custody, opened only inside the owner's unlock window. |
| `fortress` | End to end, client custody: plaintext never exists on the server. |

`ProtectionLevel::ORDER` is the ladder, weakest first; position is rank. A
service shows only the rungs it implements: mail offers all three, Drive offers
all three but changes only Standard ↔ Private on the server, chat offers
Standard and Private. Hardening one of a service's doors is an **add-on**, a
flag stored beside the level, never a rung (see the class docblock).

## Changing a level

`ProtectionLevelChange` (`includes/ProtectionLevelChange.php`) owns the
sequence and its security rules, and nothing else:

1. **The target is a rung the scope offers**, from a rung it can move between
   here. A Fortress rung a scope does not list belongs to its browser.
2. **A recent second factor.** A change to existing content needs a step-up
   confirmation. It is enforced for an account that has a second factor
   (`SessionControl::step_up_outstanding()`); an account with none has
   nothing to step up with and passes, as it does everywhere on the platform.
   The first choice on a new scope (`$existing` false) asks none.
3. **The scope's prerequisites**, asked at call time and never earlier.
4. **Ending sealing needs the actor's window.** A change to Standard needs the
   acting user's unlock window when the acting user holds a vault. Rows sealed
   to other holders converge in those holders' own windows.
5. **The promise flips first.** Everything written from that moment lands at
   the new level, and the backlog still to converge is reported.
6. **Stored content converges afterwards**, in bounded passes
   (`convergeBatch()`): at most the consumer's row bound and
   `ProtectionLevelChange::BYTE_BUDGET` (64 MB), optionally a deadline. One
   item that fails is logged and left in the backlog; a closed window stops the
   pass cleanly (`locked`); an item sealed for the browser is never converted
   server-side.

Product rules stay with the consumer, checked before it calls `change()`:
Drive's sharing revoke, mail's sending lock / add-ons / group mailbox / AI
consent, chat's running-turn check. The consumer also decides *when* to call:
a mail mailbox lowers before its grants sync and raises after, and asks
`gate()` (rules 1 and 2) before any of those writes so a confirmation is never
requested after them.

### API

```php
ProtectionLevelChange::gate($scope, $target, $existing = true);            // rules 1-2
ProtectionLevelChange::change($scope, $target, $actor_id, $existing = true); // rules 1-5
ProtectionLevelChange::convergeBatch($consumer, $budget = null, $deadline = null); // rule 6
```

`gate()` and `change()` return `status` — `ok`, `unchanged`, `refused` or
`stepup` — with `error`, `from` and `level`; a successful `change()` adds
`remaining`. `convergeBatch()` returns `converted`, `failed`, `bytes`,
`remaining` and `locked`.

A form flow answers `stepup` with `SessionControl::require_recent_second_factor($return_url)`.
An API action answers it as an error carrying `requires_stepup`, and the page
runs `JoineryPasskeys.withStepUp(call, returnUrl)` (`assets/js/passkeys.js`):
a passkey confirms in place and the call runs again; without one the person goes
through `/verify-stepup`, which also takes an authenticator code, and comes back.

### Writing a consumer

A scope implements `ProtectionLevelScope`:

| Method | Supplies |
|---|---|
| `label()` | What the member calls it, for refusals ("this folder"). |
| `levels()`, `currentLevel()` | The rungs it moves between here, and where it is. |
| `blockers($target, $actor_id)` | Why the change cannot happen now, or null. |
| `flip($target)` | Make the scope promise `$target`, durably. |
| `pending()`, `convertOne()`, `browserSealed()`, `remaining()`, `budget()` | The converge half (`ProtectionLevelConvergence`). |

`convertOne()` returns the bytes it moved, or null when there was nothing to
do, and throws `VaultLockedException` when the window it needs is closed.

A change the page's batch loop does not finish (the tab closes) must still
finish, so every consumer has a second driver: the vault's deferred work
(`VaultDeferredWork::register()`), which runs `convergeBatch()` in the owner's
window for each scope with a backlog. Picking the level a scope already has
also resumes it. A
converge that is not a level change — the vault's deferred work moving a mail
mailbox to its device key — implements `ProtectionLevelConvergence` alone.

### Consumers

| Scope | Class | Converge driver |
|---|---|---|
| Drive folder tree | `DriveFolderLevel` (`includes/DriveFolderLevel.php`) | `drive_level_batch`; deferred work `drive_level` |
| Mail domain | `MailboxDomainLevel` (`plugins/mailbox/includes/MailboxProtectionLevel.php`) | `mailbox/seal_batch`, `mailbox/unseal_batch` |
| Mail mailbox (IMAP) | `MailboxAliasLevel` (same file) | same |
| Mail Fortress moves | `MailboxFortressRaise`, `MailboxFortressSettle` (`MailboxFortressLevel.php`) | vault deferred work |
| AI chat | `ChatConversationLevel` (`plugins/joinery_ai/includes/ChatConversationLevel.php`) | `joinery_ai/chat_level_batch`; deferred work `ai_chat_level` |

Tests: `tests/vault/protection_level_change_test.php` pins each rule against a
fake scope; `tests/functional/drive/level_change_test.php`,
`plugins/mailbox/tests/domain_level_change_test.php` and
`plugins/joinery_ai/tests/chat_level_change_test.php` drive each consumer end
to end.
