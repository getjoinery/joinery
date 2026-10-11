# Mail Apps — Thunderbird and Other IMAP Clients

**Purpose:** Let a person read and send their Joinery mail from a desktop mail program — Thunderbird first, and any client that speaks standard IMAP and SMTP submission (Apple Mail, K-9/Thunderbird for Android, Outlook's IMAP mode). Joinery mail today is reachable only through the web reader and the native app's JSON API.

**Last Updated:** 2026-10-11

**Status:** Active — not yet implemented. Phase 0 (measuring real Thunderbird) comes before any build.

**Plugin:** `mailbox`, plus a vault change in core and a new Go program (the **mail gateway**).

---

## Decisions already made (owner, 2026-10-11)

1. **Private mailboxes are included.** A mail app does not get its own way to unlock the vault. It **borrows the website unlock**: when the person unlocks on the website with their passkey, their mail apps can read too; when that unlock ends, the apps stop getting mail.
2. **A mail app's checks count as activity.** Thunderbird checking for mail keeps the unlock alive, up to the existing hard limit — **7 days** for Private, **24 hours** on a hardened domain (one with *Seal at the relay* or *Only send while I'm signed in*). After that the person unlocks on the website again.

This is a deliberate, bounded exception to the rule in `VaultUnlock` that machine-driven presence never keeps a key in memory (`HEARTBEAT_MAX_STALE_SECONDS` comment: "presence is cheap to fake for a resident attacker and must never be what keeps a key in RAM"). The absolute cap is what still bounds it. The person sees the cost in plain words before they turn a mail app on (§ What the person is told).

---

## What the person gets

- On their profile, a **Mail apps** page: "Use Thunderbird or another mail program." They name the device ("Laptop"), get an **app password** shown once, and copy the settings into Thunderbird — or Thunderbird finds the settings itself from their address (§ Finding the server).
- Thunderbird shows every mailbox they hold as an account, with Inbox, Sent, Drafts, Archive, Spam, Trash and one folder per label. Reading, starring, archiving, labelling, deleting and sending from Thunderbird do the same thing as doing it in the web reader, and both stay in step.
- On a **Private** mailbox: Thunderbird works while the vault is unlocked. When it is locked, Thunderbird keeps showing the mail it already downloaded, new mail waits, and Thunderbird's Inbox shows one notice: "Your mailbox is locked. New mail is waiting. Unlock it at {link}." Unlocking on the website removes the notice and the waiting mail arrives.
- On a **Fortress** mailbox: not offered. The Mail apps page says why in one line ("Fortress mail can only be opened on your own devices; mail programs are not supported yet").

### What the person is told

Shown on the Mail apps page before the first app password is made, when any of their mailboxes is Private:

> **While a mail app is checking your mail, your mail stays unlocked on the server** — for up to 7 days after you last unlocked on the website (24 hours on {domain}). That is the same as staying signed in to the website the whole time. A stolen copy of the database or a backup still can't be read.
>
> **Your mail app also keeps its own copy of your mail on this computer.** Anyone who can open this computer's files can read it. Turn on disk encryption if you haven't.

The lock chip, while a mail app holds the unlock, adds one line: "Also unlocked for: Laptop (Thunderbird), until Oct 18."

---

## Scope

### In scope (v1)

- IMAP4rev1 with the extensions Thunderbird uses for fast sync: `UIDPLUS`, `MOVE`, `CONDSTORE`, `IDLE`, `SPECIAL-USE`, `LITERAL+`, `ENABLE`, `UTF8=ACCEPT`, `NAMESPACE`, `ID`
- SMTP submission with `AUTH PLAIN`
- Implicit TLS only: IMAP on **993**, submission on **465** (RFC 8314). No plaintext ports, no STARTTLS.
- App passwords: per device, mail-only, revocable, listed with last use
- Standard and Private mailboxes; shared (team) mailboxes at Standard
- Thunderbird autoconfig where the mail domain allows it; manual settings always shown

### Out of scope (v1)

- **Fortress mailboxes.** The server cannot open them. The route is a local bridge — this same gateway run on the person's own computer, holding the mail key the way the phone does (`docs/sealed_vault.md` § Handing a vault to a device). Separate spec.
- POP3, JMAP, Exchange (EWS), CalDAV/CardDAV
- OAuth sign-in for mail apps (Thunderbird only offers it to providers built into Thunderbird)
- The catch-all "Unmatched" box and the all-access "All mail" view
- Server-side Sieve

---

## Architecture

```
Thunderbird ──TLS 993/465──▶ mail gateway (Go, on the host) ──loopback HTTP──▶ site /api/v1 mailbox/client_* actions
                                                                               │
                                                                               └─ MailboxService / MailboxSender / VaultUnlock (PHP)
```

**Why a separate program.** IMAP clients hold a connection open for hours and wait on it for new mail (`IDLE`). PHP serves one request and exits. The gateway holds the long connections and turns each IMAP or SMTP command into calls to the site's API.

**Why not Dovecot or Postfix submission.** Dovecot stores mail its own way. Using it would mean a second copy of every message outside our sealing model, or a custom storage plugin in C. The gateway keeps no mail of its own.

**The gateway knows nothing and holds nothing.** It has no database access, no vault access, and no credential of its own. Every call carries the person's app password, so the site's existing scoping (`MailboxViewer`), sealing and audit apply unchanged. A compromised gateway can do only what a person who typed their app password could. It never writes mail to disk; the plaintext it holds is one message in memory per connection while a fetch is in progress.

**Where it runs.** On the host, beside the Apache front door. It reads the same certificates (`/etc/ssl/joinery/<domain>` or the Let's Encrypt lineage, `default_proxy_vhost.conf` § certificate dir), picks the site by the TLS server name (SNI), and calls that site's published loopback port — the same path the front proxy uses. A single-site box is the same with one site. On a multi-tenant host one gateway serves every site. That matches the trust the front proxy already has: it terminates TLS for every site's web traffic, the web reader's mail included.

**Built like `relay-sealer`.** A single static Go binary, source at `plugins/mailbox/provisioning/mail-gateway/`, prebuilt at publish into `provisioning/bin/mail-gateway-<uname -m>`. It is built on `emersion/go-imap` v2 (server side) and `emersion/go-smtp`; Phase 0 confirms go-imap v2's server is mature enough, and versions are pinned.

**Tension with the shrunken main box.** With a relay in front, the main box deliberately runs no mail listener (§ The shrunken main box). The gateway is a mail listener on the box that holds the data. What keeps this acceptable:
- It accepts nothing anonymously. A connection can do nothing but sign in until it has, and it is not an MX — it never accepts mail from the internet.
- It is memory-safe Go and reaches data only through the API.
- It is off by default. Turning it on is a guarded platform action (§ Network and install).
- It listens on the site's web hostname, which is already public. It adds nothing to the mail domain's DNS, so `checkOriginHidden` is unaffected. Autoconfig records (§ Finding the server) are the exception and are opt-in per domain.

---

## Signing in: app passwords

**A new API key type, `mail_client`,** in `apk_api_keys`, beside `machine` and `session`:

| | `mail_client` |
|---|---|
| Minted by | The person, on the profile Mail apps page, after a step-up confirmation (`docs/account_security.md` § Step-up confirmation) |
| Shown | Once, as one string: `jm-{public}-{secret}`, grouped for typing. The gateway splits it into the `public_key` / `secret_key` headers |
| Secret hashing | SHA-256, as session keys (random 256-bit) |
| Identity | The person who minted it |
| Accepted by | Only actions whose descriptor declares `'allows_mail_client' => true` — the `mailbox/client_*` actions. Refused 403 everywhere else: other actions, CRUD, forms, `auth/*` (including the `auth/web_session` bridge), `app/*`, management |
| Not a person credential | `requires_person_credential` does not admit it. A mail app cannot unlock, set up, rotate, approve a device or change a protection level |
| Expiry | None. Revoked by the person, by password change (as session keys), and by an admin |
| Listing | The Mail apps page: device name (`apk_name`), created, last used, last address (`apk_last_used_ip`, new — whichever of IPv4 or IPv6 the request arrived on, stored as `inet`) |

**Username** is the mailbox address. The gateway resolves it to the alias and the API checks the key's person holds a grant on it. One app password works for every mailbox that person holds, because Thunderbird asks per account and people should not juggle several.

**Failed sign-ins** are limited per source address (IPv4 /32, IPv6 /64) and per username, through the existing API rate limiting with a `mail_client_auth` bucket. The gateway passes the client's address to the site (`X-Forwarded-For` from loopback, trusted as the front proxy's is), so limits and audit see the real address.

---

## The unlock for mail apps (core vault change)

Today a window belongs to one PHP session: `VaultUnlock::apcuKey()` is `vault:{session_id}:{user_id}:{scope}`. A mail app has no PHP session.

**Each `mail_client` key gets its own window slot,** `vault:apk{key_id}:{user_id}:user`, in the same APCu pool with the same metadata. A request authenticated by that key uses that slot as its session for every `VaultUnlock` read (`VaultUnlock::useCredentialWindow('apk' . $key_id)`, set by `ApiAuth` before dispatch). Everything else — `secretKey()`, `endedByPolicy()`, the egress guard, the audit — runs unchanged.

**How the slot opens.** When a person unlocks on the website (`VaultUnlock::arm()` from a browser session, scope `user`), the same key is also armed into the slot of each of their live `mail_client` keys. It uses the caps `capsForUser()` already folds, measured from that moment, and the audit records `via = mail_client_borrow` naming the key. Unlocking again on the website re-arms them, which restarts the 7-day / 24-hour limit.

**How the slot stays open (decision 2).** Every `client_*` call that reads sealed content is a content decrypt, so `secretKey()` refreshes the idle time as it does for the web. A `client_changes` poll with no new mail decrypts nothing, so it calls `VaultUnlock::touchActivity()` — new, refreshing the idle time and content stamp, and only from a `mail_client` request. No heartbeat applies to these slots, and neither does the network-change rule (session keys are not IP-bound either).

**How it closes.**
- The absolute or idle cap is reached, at read time, as now.
- The person presses **Lock** on the website. `vault_lock` now also locks every mail-app slot of that user.
- Signing out of one website session does not lock mail apps; signing out is about that browser.
- A credential event or password change. `lockAll()` already matches `vault:[^:]*:{user}:` and covers these slots with no change.
- The key is revoked, which locks its slot.

**Visible.** `vault_status` lists open mail-app slots and their end times, for the lock chip line in § What the person is told.

---

## Locked behaviour

A Private mailbox's sender, subject and bodies are sealed, so while locked the gateway cannot describe a new message at all — not even its list entry. The rules:

1. **A message becomes visible to IMAP only when it can be served** (§ Message numbers). While locked, new mail gets no number, so Thunderbird sees nothing new. On unlock it is numbered and Thunderbird picks it up as new mail.
2. **Mail already visible stays visible.** Thunderbird keeps showing what it downloaded. A fetch of a message Thunderbird never downloaded answers `NO [UNAVAILABLE] Mailbox locked — unlock at {link}`.
3. **Never a stand-in.** The gateway never answers a fetch with placeholder text in place of a real message. Thunderbird stores each message's bytes by number forever, so a placeholder would stay in its copy for good.
4. **The lock notice is its own message.** While any of the mailbox's mail is waiting on the lock, Inbox holds one generated message, numbered like any other, with the unlock link. Its content never changes. It is expunged when the lock lifts and a fresh one is numbered the next time.
5. **Changes still work.** Read, star, archive, label, delete and move are metadata (`thread_action` already works while locked) and apply at once.
6. **Sending works while locked**, except on a domain with *Only send while I'm signed in*, where the signing key lives in the window. There the submission is refused with `454 4.7.0 Mailbox locked — unlock at {link}` — a temporary failure, so Thunderbird keeps the message in its Outbox.

Phase 0 measures how Thunderbird presents rules 2, 4 and 6 before they are built (§ Build phases).

---

## Folders

Joinery uses labels, not folders: a message can carry several. Thunderbird sees them the way Gmail presents labels over IMAP.

| IMAP folder | `SPECIAL-USE` | Holds (always within the alias, `iem_delete_time IS NULL` unless Trash) |
|---|---|---|
| `INBOX` | — | inbound, not archived, not spam |
| `Sent` | `\Sent` | outbound, not a draft |
| `Drafts` | `\Drafts` | `iem_draft_state` set, author = the signed-in person |
| `Archive` | `\Archive` | archived, not spam |
| `Spam` | `\Junk` | the reader's Spam view |
| `Trash` | `\Trash` | `iem_delete_time` set (`MailboxService::trashScopeSql()`) |
| `All Mail` | `\All` | everything not trashed and not spam |
| one per label | — | label members (`ilm_`), not trashed |

Each folder's contents come from the same `MailboxService` scopes the reader uses, never a second query written for IMAP.

**What Thunderbird's actions do:**

| In Thunderbird | In Joinery |
|---|---|
| Mark read / unread, flag | `mark_read` / `mark_unread`, `star` / `unstar` |
| Move Inbox → Archive | `archive` |
| Move anything → Trash | `delete` (soft) |
| Move or copy into a label folder | add the label. A move out of Inbox also archives, and a move out of a label folder also removes that label |
| Move → Spam, or the Junk button | `mark_spam` (teaches the filter, as the reader's button does) |
| Move out of Spam | `mark_not_spam` |
| Delete in Trash (`\Deleted` + `EXPUNGE`) | `purgeFromTrash()` — gone for good |
| `\Deleted` + `EXPUNGE` elsewhere | soft delete to Trash |
| Create / rename / delete a folder | `create_folder` / label rename / `delete_label` |
| Reply / forward sent | `\Answered` / `$Forwarded` (new columns, below) |

Flags Joinery does not store (Thunderbird's own tags `$label1`–`$label5`, `$MDNSent`) are left out of `PERMANENTFLAGS`, so Thunderbird keeps them locally.

---

## Message numbers (UIDs)

IMAP numbers each folder's messages, and a message added to a folder must get a number higher than every number that folder has used. Message ids cannot do this: a message archived and moved back to Inbox, or labelled a year after it arrived, would come back with an old number and Thunderbird would never fetch it.

**`imu_imap_uids`**, one row per (alias, folder, message) appearance:

| Column | |
|---|---|
| `imu_iea_inbound_email_alias_id` | the mailbox |
| `imu_folder` | `inbox`, `sent`, `drafts`, `archive`, `spam`, `trash`, `all`, `label:{ilb id}` |
| `imu_uid` | allocated from that folder's counter |
| `imu_iem_inbound_email_message_id` | the message |
| `imu_expunged_time` | set when the message leaves the folder; the number is never reused |

**`imf_imap_folder_states`**, one per (alias, folder): `imf_uidvalidity`, `imf_uidnext`, `imf_highest_modseq`.

These are separate from `ilm_imap_uid` / `ilm_imap_uidvalidity`, which record numbers on a source server Joinery *pulls from* (two-way IMAP sync). Different direction, different numbers.

**Numbers are allocated when the gateway reconciles a folder** (`client_sync`): messages now in the folder's scope without a live row are numbered in arrival order, and live rows no longer in scope are expunged. A sealed message is numbered only when it can be served — the window is open — which is what makes § Locked behaviour rule 1 hold. Reconciling is a set difference over ids, cheap per poll, and each folder's counter is taken under a row lock.

**Change numbers (`CONDSTORE`).** `iem_modseq` (int8, from one sequence) is bumped by every write that changes what a folder shows or a flag: read, star, archive, spam, trash, label membership, answered/forwarded. A label change bumps the message's number, so label folders see it too. Thunderbird then asks "what changed since N" instead of re-reading every flag in a large mailbox.

**`UIDVALIDITY` changes** only when a folder's numbering must start over: a label deleted and recreated under the same name, or a change to the message format (§ Whole messages). Thunderbird re-downloads that folder.

---

## Whole messages

On most stored mail the original is not kept: arrival keeps the header block (`iem_raw_headers`), the decoded text bodies and each attachment as a private File (§ Attachment & message storage). IMAP clients ask for the whole message, so the gateway serves a **rebuilt** one:

- The retained header block, minus `Content-Type` / `Content-Transfer-Encoding` / `MIME-Version`, then a rebuilt MIME tree: `multipart/mixed` over `multipart/related` (HTML + inline parts by `content_id`) over `multipart/alternative` (plain + HTML), with attachments from the manifest in manifest order.
- **The same bytes every time.** The boundary is derived from the message id and the format version. Bodies are always UTF-8 quoted-printable, attachments always base64 at 76 columns, CRLF throughout. Thunderbird caches by number and size, so a changed byte shows up as a broken message.
- **The format is versioned** (`MailRebuild::FORMAT = 1`). Changing the output means a new version and a `UIDVALIDITY` change for every folder, never a silent change to bytes already served.
- Its size is stored at first numbering (`iem_client_size`), because `RFC822.SIZE` is asked for in bulk and must not trigger rebuilds.
- A message stored whole (`stored`), or pulled in from a connected source (`remote`), is served as its original — the latter fetched live from the source as Show original does. A remote message whose source no longer has it answers `NO [UNAVAILABLE]`.
- The sender's DKIM signature will not verify against a rebuilt body. Thunderbird does not check DKIM by default.

**This does not change Download .eml.** The reader's export still serves only a stored original, because a downloaded file that claims to be the original must be one (§ The message kebab). A mail app's copy is a different contract: a faithful rendering of what Joinery holds. `docs/overview.md` states both.

Rebuilding a Private message needs the key. It happens in the API (`client_fetch`), in the window, and the rebuilt bytes go back to the gateway over loopback and are not stored. Large messages are fetched in pieces by Thunderbird (`BODY[]<offset.length>`). The gateway keeps the current message's bytes in memory for the connection so pieces don't trigger repeat rebuilds, dropped when the next message is fetched or after 60 seconds.

---

## Messages Thunderbird adds

- **Drafts** (`APPEND` to Drafts, and Thunderbird's delete of the previous version on each save) become draft rows through `MailboxDrafts`, parsed from the MIME Thunderbird sent. A draft started in Thunderbird opens in the web reader and the reverse.
- **Sent** (`APPEND` to Sent after sending). The submission already stored Joinery's Sent copy, so an `APPEND` whose Message-ID matches an outbound row of the same mailbox stores nothing and answers with that row's number (`APPENDUID`). Any other `APPEND` to Sent is stored as an outbound row.
- **Any other folder** (`APPEND` to Inbox or a label — Thunderbird's "copy local message to server"): stored as an inbound row through the store path archive import uses, sealed when the mailbox is, with the original kept whole.

Everything Thunderbird adds is sealed with the mailbox's public key, so adding works while locked.

---

## Sending

Thunderbird hands over a finished message and an envelope. **`mailbox/client_submit`** takes `{raw_mime, mail_from, rcpt_to[]}`:

1. **The From must be this mailbox.** The `From` header address must be the alias the session signed in as, or one it may send as (`MailboxSender::sendCapabilityFor()`). Otherwise `550 5.7.1`.
2. **Headers are made ours.** Drop any `Received`, `X-Originating-IP`, `Bcc` and Thunderbird's `User-Agent` leaks of the local hostname. Keep Thunderbird's `Message-ID` when its right side is the mailbox's domain, otherwise mint one from the mail hostname as composed sends do.
3. **Signed and sent the same way as the reader.** `OutboundTransport` → `RawRelayComposeTransport` → the provider's `relayRawMessage()`, with in-app DKIM. The hidden-origin rules apply unchanged (§ Outbound sending). The gateway never relays mail itself.
4. **Stored once**, as the outbound row a reader send stores (sealed where the mailbox is), with recipients from the envelope. The original Thunderbird sent is kept whole on that row, since there is no reason to discard it.
5. **Reply marks** — when `In-Reply-To` names a message in the mailbox, that message gets `\Answered` (`$Forwarded` for a forward, recognised by Thunderbird's `X-Forwarded-Message-Id`).

Outbound send protection (§ Outbound send protection) and the per-domain rate limits apply exactly as for a reader send.

---

## Finding the server

**Manual settings are always shown** on the Mail apps page:

| | |
|---|---|
| Incoming | IMAP, `{site hostname}`, port 993, SSL/TLS, normal password |
| Outgoing | SMTP, `{site hostname}`, port 465, SSL/TLS, normal password |
| Username | the mailbox address |
| Password | the app password |

**Autoconfig.** Thunderbird looks for `https://autoconfig.{maildomain}/mail/config-v1.1.xml` and `https://{maildomain}/.well-known/autoconfig/mail/config-v1.1.xml`. The site serves both (a public route, no sign-in, answering only for domains it hosts) with the manual settings above.
- Where the mail domain's website already points at this site, the second works with no setup.
- Otherwise the domain's DNS tab offers one optional row: a CNAME `autoconfig.{maildomain}` → the site hostname. The front door then needs a certificate covering that name, by the same certificate automation the encrypted-interop spec needs for `openpgpkey.` and `mta-sts.` subdomains (`mailbox_encrypted_interop.md`). Solve it once for all three.
- The row warns that it points at this server's address. On a relay-fronted deployment, `checkOriginHidden` treats an `autoconfig` CNAME as the person's choice, not a leak.

Phase 0 checks the lookup order of the Thunderbird release in use, including whether it consults SRV records (RFC 6186).

---

## Network and install

**Turning it on** is a guarded platform action on the mailbox Setup tab — **Allow mail apps** — never host surgery. It is the pattern of `listener_admin.php` and `joinery-mail-listener`: a narrow root helper (`joinery-mail-gateway on|off`) that installs the binary and systemd unit, opens 993/tcp and 465/tcp on IPv4 and IPv6, and closes them on `off`. On a multi-tenant host the helper is the host's, and the setting is per site. The gateway refuses SNI names for sites that have not turned it on, and the host keeps the ports open while any site has.

- The unit runs as an unprivileged `joinery-mailgw` user with read access to the certificate directories only, and reloads on certificate renewal.
- A site container gains nothing. The gateway lives on the host.
- Health on the Setup tab: listening, certificate valid for the hostname, and a round trip — sign in with a throwaway `mail_client` key, `SELECT INBOX`, sign out.

**Managed nodes.** The helper is installed with the node's provisioning, and turning it on goes through the agent like the mail-listener action. The agent gains `mail_gateway_status` (listening, version, connection count) as a read primitive.

---

## Data model

| Change | Where |
|---|---|
| `apk_type` value `mail_client`; `apk_name` holds the device name; new `apk_last_used_ip` (`inet`, stamped with `apk_last_used_time`, every key type) | core `apk_api_keys` |
| `imu_imap_uids` (above) | mailbox `data/imap_uids_class.php` |
| `imf_imap_folder_states` (above) | mailbox `data/imap_folder_states_class.php` |
| `iem_modseq` int8 | messages |
| `iem_is_answered`, `iem_is_forwarded` bool | messages. The reader's own reply and forward set them too, and the list can show them later |
| `iem_client_size` int8, `iem_client_format` int2 | messages |

Deletion: `imu_` rows cascade with the message and with the label. `imf_` rows cascade with the alias.

## API actions

All `POST /api/v1/action/mailbox/client_*`, `'allows_mail_client' => true`, each building a `MailboxViewer` for the key's person and scoping to the signed-in alias:

| Action | Purpose |
|---|---|
| `client_login` | check username/key, answer the alias, its level, lock state and folder list. Fortress refused with the Mail apps page message |
| `client_folders` | folders with `uidvalidity`, `uidnext`, counts, `highest_modseq` |
| `client_sync` | reconcile a folder (allocate/expunge numbers); answer numbers, flags, size, internal date, modseq — all, or changed since a modseq |
| `client_changes` | cheap poll for `IDLE`: highest modseq per folder, and whether the lock state changed. Counts as activity (decision 2) |
| `client_fetch` | one message's bytes by folder + number (rebuilt or original) |
| `client_store` | flag changes |
| `client_move` / `client_copy` | folder moves per the table above |
| `client_expunge` | expunge `\Deleted` numbers in a folder |
| `client_append` | add a message (Drafts / Sent / other) |
| `client_folder_create` / `client_folder_rename` / `client_folder_delete` | labels |
| `client_search` | IMAP `SEARCH` criteria mapped to the reader's search (`q`) plus flags and dates; Private search uses the in-window index |
| `client_submit` | send (above) |

They call into `MailboxService` / `MailboxSender` — no mail logic of their own.

---

## Integration points (up-front inventory)

- `includes/VaultUnlock.php`: credential windows, borrow-on-arm, `touchActivity()`, `vault_lock` reaching mail-app slots, `vault_status` listing them
- `includes/ApiAuth.php` + `apiv1.php`: `mail_client` type, `allows_mail_client`, refusals everywhere else, `useCredentialWindow()`
- `data/api_keys_class.php` (`ApiKey`): type, `apk_last_used_ip`, revocation on password change
- `VaultAudit`: `via = mail_client_borrow`, and the slot's opens and closes
- Lock chip (`JoineryVaultLock`): the "Also unlocked for" line
- `MailboxService`: folder scopes reused by `client_sync`; modseq bumps in every mutation; answered/forwarded
- `MailboxSender`: `client_submit` path; set answered/forwarded on reader replies too
- `MailboxDrafts`: drafts from MIME
- `InboundEmailRouter` / import store path: `client_append`
- `RawMessageStore` / `message_export.php`: rebuild lives beside, not inside, the export
- `InboundEmailSetupCheck`: autoconfig row, `checkOriginHidden` rule, gateway health rows
- `listener_admin.php` pattern: `joinery-mail-gateway` helper
- Front-door certificate automation: shared with `mailbox_encrypted_interop.md`
- Server manager agent: `mail_gateway_status`
- Profile menu (`plugin.json` `profileMenu`): Mail apps page

---

## Build phases

**Phase 0 — measure Thunderbird.** A throwaway go-imap v2 server with an in-memory store, driven by current Thunderbird on the Mac mini and the Windows VM. Record:
- what Thunderbird shows for `NO [UNAVAILABLE]` at `FETCH`, and for a `454` at submission;
- whether it downloads bodies for offline use by default;
- how it reacts to the lock notice being expunged and renumbered;
- which extensions it uses (`CONDSTORE`, `QRESYNC`, `MOVE`);
- its `APPEND`-to-Sent and draft-save sequences;
- the autoconfig lookup order, and SRV;
- go-imap v2 server-side completeness.

Any finding that changes a rule above is written back into this spec before Phase 1.

**Phase 1 — Standard mailboxes, read and sync.** App passwords, the gateway, `client_login` / `folders` / `sync` / `changes` / `fetch` / `store` / `move` / `copy` / `expunge`, numbering, modseq, rebuild, the install action and health rows.

**Phase 2 — sending and adding.** `client_submit`, `client_append`, drafts, answered/forwarded, Sent dedupe.

**Phase 3 — Private.** Credential windows, borrow-on-arm, locked behaviour, the lock notice, the warnings, the lock chip line.

**Phase 4 — finding the server.** Autoconfig routes, the DNS row, the shared certificate automation.

## Tests

- **Numbers only rise:** archive then unarchive, label late, trash then restore → each re-entry is numbered above the folder's `uidnext`. No number reused after expunge.
- **Same bytes:** rebuild a message twice, and across a process restart, byte-identical, with the size matching `iem_client_size`.
- **Scope:** a `mail_client` key is refused by every non-`client_*` action, CRUD, `auth/web_session`, `vault_*`. A key on alias A cannot read alias B without a grant. A Fortress alias is refused.
- **Borrowed unlock:** a browser unlock arms the key's slot; `client_fetch` opens sealed content; the absolute cap ends it; `vault_lock` ends it; revoking the key ends it; signing out of the browser session does not.
- **Locked:** new sealed mail is not numbered while locked and is numbered on unlock; no `client_fetch` ever returns placeholder text; metadata changes work locked; the lock notice appears and is expunged.
- **Send:** a From outside the mailbox is refused; `Received` and `Bcc` are stripped; the Sent `APPEND` dedupes; a send-lock domain refuses while locked.
- **Gateway:** a gate script runs a scripted IMAP session (Python `imaplib`) and SMTP session against the dev gateway, run in `live`.
- **Real client:** the Phase 0 rig re-run against the built gateway, in the live verification queue.

## Docs to update

- `plugins/mailbox/docs/overview.md`: new § Mail apps (IMAP). Download .eml vs a mail app's copy. Security levels table: a "Mail apps" row (Standard yes, Private yes while unlocked, Fortress no)
- `docs/sealed_vault.md`: credential windows and borrowed unlocks, under § The unlock window and § How long a window lasts
- `docs/api.md`: the `mail_client` credential column, `allows_mail_client`
- `docs/account_security.md`: app passwords as a mail-only sign-in path

## Complexity and risk

- **Rebuild determinism** is the most fragile piece. Every message ever served is pinned to the bytes of one format version. Mitigated by the frozen format version and a byte-identity test over a fixture corpus covering every manifest shape.
- **The relaxed idle rule** (decision 2) is a real weakening for Private users who turn on a mail app, bounded by the absolute caps and made visible in the lock chip.
- **A new listener on the data box.** Mitigated by authenticated-only, TLS-only, memory-safe, API-only, and off by default.
- **Load.** Each idle Thunderbird account polls `client_changes` through php-fpm. Thunderbird opens up to 5 connections per account. The gateway shares one poll per (key, alias) across that key's connections, every 30 seconds.

## Open decisions

None blocking. Phase 0 may surface some.

## Future

- **Fortress through a local bridge:** the gateway run on the person's computer, holding the mail key handed to it as the phone receives it.
- Accounts that hold several mailboxes under one login, with per-mailbox namespaces.
- CalDAV/CardDAV for the calendar and contacts, on the same gateway.
