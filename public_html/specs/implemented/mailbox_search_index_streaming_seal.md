# Spec: The sealed search index must persist in bounded memory

**Status:** Implemented 2026-08-21 (jeremytunnell search recovers on the first unlock after the next publish + upgrade — § 6)
**Version:** 1.0
**Area:** `plugins/mailbox/includes/MailboxIndex.php`, `includes/SealedBox.php`, `includes/VaultCrypto.php`
**Related:** `docs/sealed_vault.md`, `docs/secret_box.md`, `specs/implemented/inbound_email_encryption_at_rest.md` (§ 6, the sealed FTS index), `tests/vault/sealed_read_paths_test.php` (the pinned decrypt-callsite enumeration)

---

## 1. What this fixes, in plain terms

On jeremytunnell.com, every mailbox search returns "Could not load this mailbox (500)". The search itself works — the failure is the bookkeeping around it. A vault-holding owner's mail is searched through a SQLite FTS5 index that lives decrypted in `/dev/shm` and is persisted as one sealed blob so the next unlock restores instead of rebuilding. That owner's index reached 31.5 MB (a normal size for ~3,400 messages / ~137 MB of bodies), and sealing it now needs more memory than PHP-FPM allows:

```
PHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 42025016 bytes)
in includes/SealedBox.php on line 276    (SealedBox::b64url)
request_uri: /api/v1/action/mailbox/thread_list
```

`MailboxIndex::persist()` holds the whole index as a string, encrypts it into a second string, base64url-encodes the ciphertext into a third (the 4/3-size 42 MB allocation that died), and concatenates a fourth for the `v1.aead.` text format — roughly 150 MB of simultaneous copies against a 128 MB limit. `persist()` is written to be best-effort ("never throws"), but an out-of-memory fatal is uncatchable, so its try/catch cannot save the request: the whole `thread_list` dies and the reader shows the 500.

Two compounding faults:

1. **The seal is whole-blob, in-string, and base64-inflated.** Peak memory is ~4× the index size, so the ceiling is hit around a 25–30 MB index — a size any real mailbox reaches. `restoreFromBlob()` mirrors the same shape (blob string → `explode` copies → base64-decode → plaintext) and is only marginally under the limit today; it is the next thing to break.
2. **`persist()` runs on every search**, even when `foldSince()` folded nothing — every keystroke-search re-reads, re-encrypts, and rewrites the full blob.

## 2. The rule

**Sealing or restoring the search index must use memory proportional to a chunk, never to the mailbox.** A member's search must keep working at any mailbox size the platform can store. And a search that changed nothing must write nothing.

## 3. Design

### 3.1 A streaming sealed-file format

`SealedBox` gains a chunked file format alongside the existing string AEAD, built on libsodium's secretstream (`sodium_crypto_secretstream_xchacha20poly1305_*`):

- **Layout:** ASCII magic `v1.stream.` + the 24-byte secretstream header + repeated frames of `[4-byte big-endian ciphertext length][ciphertext]`. The final frame carries the secretstream `FINAL` tag, so truncation is detectable and rejected, exactly as the AEAD tag rejects tamper today.
- **Key:** the same 32-byte per-item DEK the AEAD path uses — `VaultCrypto::newItemDek()` / `sealItemDek()` / `openItemDek()` are unchanged; only what the DEK encrypts changes shape.
- **AD:** the caller's AD string (for the index, `mail:ftsindex:{user_id}`) is passed as the AD of **every** frame, preserving the splice defense: a frame, or a whole file, sealed for one owner can never decrypt in another's context.
- **Chunk size:** 1 MiB plaintext per frame. Peak memory is one chunk in, one chunk out, regardless of file size.
- **API (path-to-path, never string):**
  - `SealedBox::sealStreamFile(string $src_path, string $dst_path, string $key, string $ad): void`
  - `SealedBox::openStreamFile(string $src_path, string $dst_path, string $key, string $ad): void` — throws on tamper, truncation, or AD mismatch; the destination is written to a temp name and renamed in only on success, so a failed open never leaves a partial plaintext file.
  - `SealedBox::isStreamFile(string $path): bool` — magic-prefix sniff, for format detection.

### 3.2 VaultCrypto wrappers, and the hot-turn contract

Consumers never call SealedBox directly for stored content. `VaultCrypto` gains the two wrappers:

- `sealFieldFile($src_path, $dst_path, $dek, $ad)` → `SealedBox::sealStreamFile`.
- `openFieldFile($src_path, $dst_path, $dek, $ad)` → `SealedBox::openStreamFile`, **then `SealedEgressGuard::markHot($ad)`** — a streaming open of stored sealed content is a sealed read like any other and must arm the hot-turn rule exactly as `openField()` does.

`tests/vault/sealed_read_paths_test.php` pins every direct SealedBox decrypt callsite in the tree; `VaultCrypto::openFieldFile` joins that enumeration as the one sanctioned caller of `openStreamFile`.

### 3.3 MailboxIndex uses it

- **`persist()`** seals `/dev/shm/mailfts_{uid}.sqlite` to a temp file in the system temp dir via `sealFieldFile`, then ingests the temp file with the existing path-based factory (`File::createFromUpload` — `createFromBytes` minus the in-memory detour), keeping `fil_private` + `fil_source = SOURCE_MAILBOX_SEARCH_INDEX` and the current new-file-then-delete-old rotation. Never-throws contract unchanged — and now the contract actually holds, because the uncatchable failure mode is gone.
- **`restoreFromBlob()`** detects the format. A `v1.stream.` blob streams from the blob's local path (`FileBlob::filesystem_path('original')` — index blobs are private files and live on local disk) straight to the `/dev/shm` working path via `openFieldFile`. A legacy `v1.aead.` blob returns `false`, which the existing disposable-cache contract already handles: the caller `rebuild()`s from the sealed message rows and the next `persist()` writes stream-format. No migration, no legacy decode path to maintain — one slower unlock per owner, once.

### 3.4 Persist only when the fold changed something

`foldSince()` returns whether it wrote anything (folded a new row, or processed a refold entry). `fold()` persists only when that is true, or when no persisted blob exists yet (first index, and after `purgePersisted()`). `rebuild()` always persists. The seal-after-fold crash guarantee is preserved: when nothing was folded, the already-persisted blob is exactly current, so skipping the write loses nothing. Repeated searches over unchanged mail — the common case, since folds only happen when new mail arrived since the last one — stop rewriting a multi-megabyte file per query.

## 4. Alternatives considered

- **Raise `memory_limit`** — moves the cliff, keeps the O(mailbox) allocation; a 60 MB index dies at 256 MB, and every FPM worker inherits the ceiling. Rejected as the fix (acceptable as a stopgap until this ships).
- **Binary single-buffer seal (drop base64, keep whole-blob strings)** — halves peak memory but keeps it proportional to the mailbox; dies again around a 50–60 MB index. Rejected.
- **Dirty-flag alone** — removes most executions but the first search after new mail still seals the whole blob and dies. Kept as § 3.4, insufficient alone.
- **Stop persisting; rebuild every unlock** — correct under the disposable-cache contract, but rebuild decrypts every stored message, so unlock cost grows with the mailbox forever. Rejected.
- **SQLCipher / encrypted SQLite VFS** — page-level encryption with true O(1) memory, but a native dependency the platform's zero-config install rule forbids. Rejected.

## 5. Tests

`plugins/mailbox/tests/` (safe tier unless noted):

1. Stream roundtrip — seal a multi-megabyte generated file, open it, byte-identical; wrong key, wrong AD, truncated tail, and a flipped ciphertext byte each throw and leave no destination file.
2. Bounded memory — seal and open a file several times the chunk size; assert `memory_get_peak_usage` delta stays a small multiple of the chunk, not of the file.
3. Format detection — `isStreamFile` true/false; `restoreFromBlob` on a legacy `v1.aead.` blob returns false and the ensuing rebuild produces a searchable index that persists as stream-format (db tier).
4. Dirty-flag — a fold with nothing new performs no `File` write (blob id unchanged); a fold with one new row rotates the blob.
5. `sealed_read_paths_test` updated for the new sanctioned decrypt callsite.

## 6. Rollout

Ships in a normal publish; no migration. Each vault owner's first post-upgrade unlock rebuilds the index once (legacy blob refused → rebuild → stream-format persist). On jeremytunnell.com specifically, search recovers on the first unlock after upgrade with no operator action.
