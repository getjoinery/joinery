# Client-custody mail — Fortress, end-to-end encrypted mailboxes

**Status: ACTIVE 2026-09-24, prepared for an executor.** Supersedes
`specs/DEFERRED_client_custody_mail.md` (removed 2026-09-24; its design record is folded in at the
end). Builds on `specs/implemented/client_custody_declared_consumer.md`
(commit 243ff863: per-row custody, the browser-format sealer, the ceremony and
scope session in core, per-scope device handoff, client-scope rotation) and
`specs/implemented/protection_levels_fold.md` (mail's two cards and add-ons).
Level doctrine: `specs/protection_levels_platform.md` (R4: Fortress means
end-to-end, nothing less). The four decisions this spec needed were taken with
the owner on 2026-09-24 and are the last section.

## For the executor — read this first

This is the working brief. § Design gives the reasons; § Work packages is the
checklist, in order: each is testable on its own and the next depends on it.
Every design decision is made. When the tree contradicts a fact below, the
tree wins: stop, note the difference in your hand-back, proceed on the tree.

**House rules that bind this work** (CLAUDE.md and project memory):

- **Never commit and never `git add`.** The owner runs git; the index is
  shared with other sessions.
- **Never edit `CLAUDE.md`, anything under `specs/implemented/`, or Apache
  config.** Never chmod.
- **Schema changes go through `$field_specifications` only**, then
  `php utils/update_database.php`, which you name in an ask to the owner
  before running (stop point 1). Plugin tables sync in its final step. No
  migration for a column.
- **Never write to the database by hand.** Test data comes from fixtures.
  Never delete vault rows of user 4500.
- **Docs describe the current state only.** No "now", "previously",
  "replaces", "instead of".
- **Bump `@version`** on every file you touch with a one-line note.
  `plugins/mailbox/plugin.json` carries `version`; bump it.
- **After every PHP edit:** `php -l <file>`, then
  `php /var/www/html/joinerytest/maintenance_scripts/dev_tools/validate_php_file.php <file>`
  on class and function files only (`includes/`, `data/`, `logic/`); views
  get `php -l` only. The validator executes the file.
- **Tests use the shared harness** (`tests/lib/harness.php`, `@joinery-test`
  header; copy `tests/vault/seal_on_save_test.php`'s). Vault fixtures:
  `tests/lib/vault_fixtures.php` (`vault_fixture_client_vault($user_id,
  $public_key, $scope)` inserts a client-custody vault row). Mailbox fixture:
  `plugins/mailbox/tests/lib/mailbox_test_fixture.php`. `php tests/run.php
  --changed` is the loop, `php tests/run.php db --changed` before hand-back.
  Never as root. The test database has no content; make your own rows.
- **Vanilla JS and CSS.** Forms through FormWriter; a button that acts is a
  POST. **New server actions are API actions** (`logic/<name>_logic.php` with
  `_logic_descriptor()`, `requires_browser_session`), called through
  `joineryApi.post`. Never `/ajax/`.
- **Browser behavior is verified in the Playwright browser** on
  `https://dev.getjoinery.com` (login: memory `reference_credentials`; user
  4500 is vault-capable). Screenshot at each acceptance walk and say what it
  shows. Inbound test mail: `test@dev.getjoinery.com` (`reject_unmatched` is
  on; the local part must match an alias).
- **Secrets never appear in output.** No key bytes, recovery codes, wrapped
  blobs or message bodies in the transcript or a log.
- **The Go relay program** (`plugins/mailbox/provisioning/relay-sealer/`) is
  edited here (WP7, WP8); it is built with its `build.sh` and gated by
  `roundtrip_test.sh`. Deploying it to a relay box is the owner's action
  (stop point 3). The Rust sync client and the iOS/Android apps are not
  edited here.

**Stop points** (hand back; do not work around):

1. Before `php utils/update_database.php` in WP0 (three columns on
   `iem_inbound_email_messages`, one on `iea_inbound_email_aliases`).
2. After WP2, with the browser proof: a message that arrived at a Fortress
   alias on dev opens in the reader after the mail-vault unlock, and the
   same row read through psql shows only `v1.edge.` ciphertext.
3. Before WP7's relay binary goes anywhere but the dev tree.
4. When everything is green, with the list of files touched, for commit.

**Facts you will need, verified 2026-09-24 against the tree:**

- **Custody per row.** `SystemBase::sealScopeForWrite(array $row): string`
  (`includes/SystemBase.php:758`, default `'user'`) is the only hook;
  `resolveSealScope()` (:763) checks the answer against `VaultScopes`.
  `sealColumns()` (:926) switches to the browser format when
  `vaultIsClientCustody($vault)` (:1056, `uev_custody === 'client'`), and
  `sealWrappingAssignments()` (:1044) seals the DEK with
  `sealItemDekToBrowserKey($dek, $vault->sealingPublicKey(), $scope)`, the
  pending-aware key. No production model overrides the hook yet; the probes
  in `tests/vault/sealed_scope_model_test.php` and
  `client_reseal_batch_test.php` do.
- **The browser write path.** `acceptBrowserSealed(int $row_id, string
  $sealed_dek, array $fields, ?string $public_key = null)` (:1312) is the only
  way `v1.edge.` reaches a sealed column; it checks the blob's scope against
  `resolveSealScope($row)`, `shouldSeal`, every populated sealed field
  present, the owner holds the scope's vault, and derives the generation from
  `$public_key` (current or pending). `save()` refuses a `v1.edge.` value.
  Authorization is the caller's. `export_for_api()` (:1905) turns
  `VaultSealedForBrowserException` (thrown at :880 when the key parses as an
  edge scope, window open or not) into `export_for_api_sealed_for_browser()`
  (:1981): plain columns, sealed fields as stored, `key`, `sealed_scope`,
  `sealed_dek`, `sealed_ad_prefix` (`sealedAdPrefix()` :738). The credential
  floor (`CREDENTIAL_FIELD_PATTERN` :69) hides `{prefix}_sealed_key`; the DEK
  travels as `sealed_dek`.
- **Rotation plumbing.** `browserResealPage($user_id, $scope, $generation,
  $after_id, $limit)` (:1407, `LIKE 'v1.edgeseal.{scope}.%'` escaped),
  `acceptBrowserReseal()` (:1452, key column and generation only),
  `browserSealedRowCount()` (:1483). `VaultClientRotation`
  (`includes/VaultClientRotation.php`): `assertCanBegin`, `begin`,
  `resealPage`, `resealRows`, `commit` (no abandon; refuses while rows sit on
  the old generation; `forgetScopeOnDevices`). Registry:
  `VaultUnlock::clientReseal(string $scope, array $classes, array $scripts)`
  (`includes/VaultUnlock.php:712`), `clientResealsFor($scope)` (:722). The
  security page's rotation control is per vault row the user holds
  (`views/profile/security.php:1186`, `data-vault-rotate="{scope}"`, items
  from `RecoveryReadiness::vaultItems()`); a `mail` vault appears there
  without a code change. Rotation scripts load through
  `PublicPageBase::needs_vault_rotation()` (`includes/PublicPageBase.php:49`);
  `needs_vault_client()` (:36) loads `passkeys.js`, `vault-crypto.js`,
  `vault-keyring.js`, `joinery-sealed.js` (`render_vault_client_scripts()`
  :896). Only `views/drive.php`, `views/share.php`,
  `views/profile/devices_link.php`, `plugins/vault/views/profile/index.php`
  and `views/profile/security.php` call it; **no mailbox page does**.
- **Scopes.** `vault_scopes.json` (core: `user` server, `drive` client); a
  plugin declares under `vaultScopes` in `plugin.json` and must be client
  custody (`includes/VaultScopes.php:145-222`). Pattern:
  `plugins/vault/plugin.json:13-22` (`"bootstrap"`, `"vaultConsumer":
  {"client_reseals": ["passwords"]}`, `"vaultScopes": {"passwords":
  {"custody": "client", "label": "Password vault"}}`). PRF context is
  derived: `prfContext('mail')` = `vault-mail-kek`. The mailbox
  `plugin.json:25-30` declares `vaultConsumer` `{order:20, reseals:true,
  caches:true}` and no scopes. `VaultClientCustody::loadVault($user_id,
  $scope)`; `UserEncryptionVault::loadForUser($user_id, $scope = 'user')`.
- **Browser API.** `window.JoinerySealed` (`assets/js/joinery-sealed.js`,
  exports :424-438): `open(row, refetch, opts)` (:221; handles plain,
  `content_locked`, `sealed_scope`; decrypts each `v1.edge.` field with AD
  `sealed_ad_prefix + key + ':' + field`), `seal(scope, id, adPrefix,
  values)` (:273, pending-aware), `save(action, values, {scope,
  sealedFields})` (:285, two POSTs), `session(scope, opts)` (:143, one
  ceremony for concurrent callers), `isOpen`, `onLock(scope, fn)` (:169),
  `lock`, `lockAll`, `idleMinutes`, `onReseal(scope, fn(ctx))` (:315; ctx
  `{oldSession, newSession, newPublicKey, progress, skip}`),
  `resealScope`. `VaultKeyring.ensureUnlocked(scope, {reason, pending})`
  (`assets/js/vault-keyring.js:526`) runs setup (recovery codes proven
  saved) or unlock in one `JoineryModal`; a session is `{scope, publicKey,
  locked(), openSealed(blob), sealTo(bytes, pub?), sealSecretKeyTo(pub),
  lock(), label}` (`makeSession` :64). Primitives: `assets/js/vault-crypto.js`
  `sealToPublicKey` (:210), `openFromSecretKey` (:221), `encrypt`/`decrypt`
  (:250/:256, `base64(IV ‖ ct)`), `importDek`, `newDek`.
- **Server crypto.** `includes/VaultCrypto.php`: `sealItemDekToBrowserKey`,
  `sealFieldForBrowser`, `openItemDek` (any prefix), `openField` (opens
  `v1.edge.` under a DEK it holds), `parseEdgeScope`,
  `openHeldDeliveryBlob` (:263). `includes/SealedBox.php`: `sealEdge`,
  `openEdge`, `aeadEncryptGcm(plaintext, key, ad)` = `base64(IV[12] ‖ ct ‖
  tag[16])`, `aeadDecryptGcm`, plus the libsodium pair. Edge seal =
  `ephPub[32] ‖ IV[12] ‖ ct`, AES-256-GCM key = `HKDF-SHA256(X25519(eph,
  recipient), salt '', info 'sealed-vault:dek' ‖ ephPub ‖ recipientPub)`.
  Shared vector: `tests/vault/fixtures/edge_vector.json`.
  `tests/vault/sealed_read_paths_test.php` pins which files may call the
  opening primitives (`$allowed` :69, `$secret_allowed` :83) and where
  `VaultSealedForBrowserException` may be caught (only `SystemBase.php`).
- **Ingest.** `plugins/mailbox/includes/InboundEmailRouter.php` (IER):
  `processEmail()` :249 → `storeMessage()` :598 (also the relay transport
  pull, catch-all, archive import); `storeExtracted()` :1894 (IMAP);
  `storeDirectMessage()` :1082; `storeRelayPending()` :1005.
  `resolveSealTarget($alias, $domain)` :1462 returns `{sealing, vault,
  owner_id}` (`$alias->seals_content()` else `$domain->seals_content()`;
  owner via `InboundEmailMessage::sealOwnerUserId` :765 =
  `singleOwnerUserId` :736 or `domainOwnerUserId` :777; vault via
  `loadOwnerVault()` :1443 = `loadForUser` with the default scope;
  `MailboxSealTargetMissing` :201 → exit 75 / hold). Callers: IER:670,
  :1129, :1915 and `MailboxSender.php:1445`. Inside `storeMessage`: the row
  inserts with empty content (:690-714), `sealMessageContent()` :1495 →
  `InboundEmailMessage::sealAndPersistContent()` (IEM:989, `$seal_on_save =
  false` at IEM:220) → `sealColumns`; the DEK goes to
  `persistRawAndManifest()` :1554, which seals attachment bytes under it
  (`extractAttachmentsToFiles()` :1610, `VaultCrypto::sealField(bytes, dek,
  attachmentAd)`, AD `mail:{mid}:att:{mime_part}` from IEM:677) and, when
  extraction fails, `persistRawFallback()` :1814 (sealed raw to
  `RawMessageStore`, or inline `iem_raw_message`). Filters run after, on the
  in-memory plaintext (:832-842, `InboundEmailFilter::runForMessage` :534).
  `$sealed_fields` IEM:214 (12 columns), `$optional_sealed_fields` IEM:234
  (empty = nothing, not ciphertext). `sealAd` IEM:665 = `mail:{id}:{field}`.
  Alias/domain columns: `iem_iea_inbound_email_alias_id`,
  `iem_ied_inbound_email_domain_id` (IEM:271-272).
- **Plaintext written beside a sealed row today:** `ima_filename`,
  `ima_content_type`, `ima_content_id`, `ima_mime_part`, `ima_encoding`,
  `ima_size_bytes` (IER:1610-1688); `File` name and type; `iel_from_address`
  = the raw From header **with display name** on every `processEmail` path
  (`logTransaction()` IER:3410, `logSubjectFor()` :3431 already blanks the
  subject for a sealing alias); `mst_from_address`, `mst_message_id_header`;
  `MailRunRecord` writes exception text only; `MailboxMessageTimeline` is
  read-side (it opens `iem_raw_headers` in-window, `headerBlock` :175).
- **Relay path today.** `RelayMapExporter::build()`
  (`plugins/mailbox/includes/RelayMapExporter.php:61`): per recipient
  `public_key, key_kind ('user'|'transport'), key_generation, mode,
  destinations, forwarding_domain, forward_from` (:158-169);
  `sealTargetForAlias()` :229 returns the `user` vault's `uev_public_key`
  under the relay add-on; `keyGenerationFor()` :248. `RelayMapSync::push()`
  PUTs `/relay/fragment` through `RelayClient::putFragment()`
  (`RelayClient.php:168`), requests signed Ed25519 by
  `RelayClientIdentity::sign` with envelope `"joinery-relay:request:v1\n"` +
  canonical JSON (`RelayProtocol.php:62,100`), TLS pinned on the relay's
  SPKI fingerprint. Go: `main.go run()` :107 reads the raw (25 MiB cap),
  `resolve()` (`routing.go:232`), `sealAndSpool()` :228 → `sealToPublicKey`
  (`seal.go:45`, `box.SealAnonymous`, `"v1.seal." + base64url`), the whole
  RFC822; sidecar `.meta` (`meta.go:14-40`: `spool_id, recipient,
  envelope_sender, message_id, in_reply_to, references, date, size,
  authentication_results[], key_kind, public_key, map_version,
  received_utc`). `routingEntry` (`routing.go:35-54`) carries `key_kind`,
  `key_generation`. Routes (`relay_serve.go:302-341`): `/relay/ping`,
  `/relay/spool`, `/relay/spool/{id}.{seal|direct|meta}`, `/relay/spool/ack`,
  `/relay/fragment`, tenant routes; every `/relay/*` request passes
  `auth.verify` (`relay_auth.go:135`). The identity (`relay_identity.go`,
  Ed25519 + self-signed cert, `spkiFingerprint` :146) signs only the birth
  report (`identity.sign()` :159, `relay_birth.go:77`); **no response body is
  signed**. Pull: `RelaySpoolConsumer::pull()` :94 → `ingestOne()` :257: a
  transport blob opens with `SealedBox::openDek` (:353) and goes to
  `storeMessage`; a `user` blob goes to `storeRelayPending` (:347) with the
  owner from `singleOwnerUserId` or `ownerByPublicKey`. Pending rows:
  `iem_pending_parse`, `iem_relay_sealed_raw` (whole raw, `v1.seal.`),
  `iem_relay_spool_id` (IEM:333-344). `DeferredIngest::drainForUser($user_id,
  VaultKey $key, ...)` (`DeferredIngest.php:65`) parses in the window from
  `VaultDeferredWork::register('mailbox_parse', ...)`
  (`plugins/mailbox/includes/bootstrap.php:323-332`), from
  `MailboxService::drainRelayBacklog()` on views, and from the unseal batch;
  `parsePendingMessage()` IER:866 opens the blob, parses, seals content,
  splits attachments, reads spam from the stamped headers
  (`resolveContentSpam` / `readSpamHeader` :3305, `classifySpam` :950-958),
  runs filters. The relay stamps X-Spam headers with its own stateless
  rspamd (`provision_relay.sh` § 6b).
- **Reader.** `plugins/mailbox/assets/mailbox_reader.js` (4822 lines;
  config `window.MAILBOX_READER` from
  `plugins/mailbox/includes/mailbox_reader_mount.php:90-120`). List:
  `mailbox/thread_list` (`plugins/mailbox/logic/thread_list_logic.php:21`) →
  `MailboxService::listThreads()` (`MailboxService.php:991`); per thread
  `thread_key, subject, senders, sender, snippet, ai_summary, section,
  msg_count, unread_count, any_starred, any_archived, has_attachment,
  label_ids, danger_score, direct_verified, latest_time, latest_id,
  purge_time` (:1309-1345), content from `fetchAndDecryptContent()` :1408
  (newest message per thread) and the snippet built server-side (:1277);
  call site reader.js:1113. Thread: `mailbox/thread`
  (`thread_logic.php:22`) → `getThread()` :1564 → `decryptThreadRow()` :1765
  (`SEALED_PLACEHOLDER` + `locked` when the window is shut), per-message
  fields :1600-1679 including `body_plain, body_html, attachments[]` (each
  `{id, filename, content_type, size_bytes, preview_kind, url}` :1872-1905),
  signed transport URLs via `withSignedTransport()` :1962; call site
  reader.js:1557. Rendering: `messageBlock()` reader.js:2168; `body_html` →
  `<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc=…>`
  (:2211-2214, no scripts, no same-origin), `withBaseTarget()` :2128;
  `body_plain` → `<pre>`. Inline `cid:` images are rewritten server-side
  (`resolveInlineImages()` :2035). Attachment chip → `/profile/mailbox/
  attachment?ima_inbound_message_attachment_id=` (`profile_attachment_logic.php:25`
  → `mailbox_retrieve_attachment_bytes()` in `attachment_retrieval.php:35`
  → `InboundEmailMessage::openSealedAttachment()` IEM:903, server-side).
  Text preview: `attachment_text_logic.php`. **The browser never decrypts
  mail today; no mailbox file references `JoinerySealed`.**
- **Search.** No separate endpoint: `q` on `thread_list` (reader.js:1076,
  300 ms debounce). `MailboxService.php:1102-1154`: with sealed content and a
  server key, `MailboxIndex::fold()` then `search()`, OR'd with a Postgres
  `websearch_to_tsquery` over unsealed rows. `MailboxIndex::rowContent()`
  (`MailboxIndex.php:925-950`): sender, subject, plain body, readable text of
  the HTML body (`MailboxHtmlSanitizer::toReadableText`, input cap 200000
  bytes), every `ima_filename`; drafts excluded, pending rows skipped
  (`loadForIndex` :958). `sanitizeFtsQuery()` :1023 quotes each token
  (implicit AND of literals). The persisted index is one sealed file per
  user under the `user` vault (`persistOrThrow` :514).
- **Compose.** Multipart `fetch(CFG.sendUrl)` (reader.js:4350) →
  `plugins/mailbox/logic/send_logic.php:42` (`mode, source_id, alias_id, to,
  cc, bcc, subject, body, body_html, inline_manifest, draft_id,
  attachments[]`) → `MailboxSender::send()` (`MailboxSender.php:149`):
  sanitizes, `buildBody()` :499 quotes the source **server-side** from the
  decrypted columns, sends synchronously, `storeOutboundRow()` :1286 seals
  the Sent copy through `sealTargetFor()` :1441 (same resolver as ingest),
  `persistOutboundUploads()` :1014 under the message DEK,
  `appendSentCopy()` :1160 IMAP-APPENDs for connected accounts,
  `recordAttempt()` :1187. Drafts: `draft_save_logic.php:18` →
  `MailboxDrafts::saveDraft()` (`MailboxDrafts.php:59`), autosave 3 s after
  the last edit (reader.js:4113); `iem_draft_state` JSON `{mode, source_id,
  to, cc}` (:103); `draft_get_logic.php` decrypts server-side;
  `persistDraftUploads()` :438.
- **Attachments.** `InboundMessageAttachment`
  (`plugins/mailbox/data/inbound_message_attachments_class.php:46`): `ima_filename,
  ima_content_type, ima_size_bytes, ima_mime_part, ima_encoding,
  ima_content_id, ima_is_inline, ima_fil_file_id, ima_is_sealed`. Two sealed
  shapes: (a) `ima_is_sealed = true`, File bytes = `sealField(bytes,
  messageDEK, attachmentAd)`; (b) a `SealedFileContainer` with its own key
  wrapped to the `user` vault (`AttachmentByteCustody::adoptFromTemp` :260 →
  `DriveSealed::createSealedFile`). `openSealedAttachment()` IEM:903 opens
  both. `drive-crypto.js` `decryptContent(buf, fkKey, cid)` (:151) opens
  headerless GCM chunks; not needed here.
- **AI and other server readers of message content:**
  `plugins/joinery_ai/includes/EmailJobCandidates.php:155,220` (selection),
  `EmailPipelineJobBase::nextItem/digestFor` (:187-226) →
  `EmailSecurityDigest::build()` (`plugins/mailbox/includes/EmailSecurityDigest.php:44`,
  raw or columns), `EmailAttachmentDigest`; writers `EmailTriageJob.php:75`
  (`iem_ai_summary`), `EmailSecurityScanJob.php:139` (`iem_ai_scan`,
  danger score), `EmailScheduleJob`; `CreateCalendarEntryTool.php:151-185`;
  `ModelQueryExecutor::decryptSealedFields()` (`plugins/joinery_ai/includes/ModelQueryExecutor.php:207`,
  `$ai_readable = true` IEM:248, excludes locked rows already);
  `RecipeVaultScope::drain()` (:490) is the in-window AI drain.
  `InboundEmailFilter::matches()` (:289-356) reads subject and bodies; the
  backfill task `plugins/mailbox/tasks/ApplyInboundEmailFilters.php:88`.
  `MailboxMessageTimeline::headerBlock` :175. `EmailSecurityDigest`,
  `DocumentText` (text preview), `MailboxIndex::loadForIndex`.
- **Levels and UI.** `includes/ProtectionLevel.php` (`STANDARD, PRIVATE_,
  FORTRESS`, `ORDER`). `includes/ProtectionLevelPicker.php`: `render($fw,
  $field, $options)` :261 (`service, levels, value, disabled_values,
  helptext, visibility_rules, addons_note, addons`); `catalog()` :88 has no
  `SERVICE_MAIL` Fortress entry, so `copy()` :224 falls back to the generic
  Fortress copy; `addonCatalog()` :139; `levelTakesAddons()` :214 (Private
  and above). Mail domain editor:
  `plugins/mailbox/admin/admin_mailbox_domains.php:181-235` (picker with
  `InboundEmailDomain::SETTABLE_LEVELS`), step-up JS :450-580, raise receipt
  card on `mailbox/seal_batch` (:354, `assets/js/ceremony-batch.js`,
  `data-ceremony-batch` JSON `{action, payload, remaining, doneKey,
  remainingKey, doneTotal, labels}`), lowering loop on `mailbox/unseal_batch`
  (:368-436). Logic `plugins/mailbox/logic/admin_mailbox_domains_logic.php:32`:
  Fortress refused (:198-200, :236-238), feed/provider domains forced to
  Standard, lowering refused while the send lock enforces, step-up on
  change (:265-276), group mailboxes capped at Standard, ceremony gate rows
  (`mailbox_protection_rows()` in `protection_ceremony.php`), lowering needs
  `VaultUnlock::isOpen`, then `set_security_level()` and the flags
  (:316-320); redirects `?sealed_now=1` / `?unsealed_now=1`.
  `mailbox_protection_seal_batch($domain, 200, $alias_scope_id)`
  (`protection_ceremony.php:467`, public key only, skips pending rows),
  `mailbox_protection_unseal_batch()` :551, `mailbox_protection_backlog_count()`
  :397. Domain model `plugins/mailbox/data/inbound_email_domains_class.php`:
  `LEVEL_FORTRESS` reserved (:78-86, "set_security_level() refuses it"),
  `SETTABLE_LEVELS` :86, `security_level()` :340 (a stored `fortress` reads
  as Private: an unconverted row, `is_unconverted()` :380, converted on the
  next `set()` :201-209), `set_security_level()` :361, `seals_content()`
  :480 (`=== 'private'`), `relay_seals_to_owner()` :403,
  `is_protected_identity()` :628. Alias: `iea_security_level` (null =
  inherit, `inbound_email_aliases_class.php:62`), `security_level()` :194,
  `seals_content()` :210. Drive: `logic/drive_level_change_logic.php:60`
  refuses Fortress both ways; `drive_level_batch_logic.php` →
  `DriveSealed::runTransitionBatch` (byte budget).
- **Device handoff.** `logic/devices_link_logic.php:47-61` offers every
  client scope the user holds a vault for; `device-link.js:100-140` posts
  `sealed_vault_keys: {scope: blob}`; `drive_device_link_approve_logic.php`
  stores them and `sde_vault_scopes`. Nothing per consumer to declare.
- **Native apps.** iOS (`/var/www/html/joinerytest/ios/joinery-kit/Sources/JoineryMailKit`)
  and Android (`/var/www/html/joinerytest/android/joinery-android-mail`)
  read mail natively through the same `mailbox/*` actions and receive
  server-decrypted bodies (`docs/mobile_apps.md:234-262`); the Rust sync
  client has no mailbox code. `plugins/mailbox/plugin.json:49` declares
  `"nativeScreen": "mailbox"` with the web reader as the fallback URL.

## Intent

A member turns a mail domain to **Fortress** and from then on only their
devices can read what is stored: every message, attachment and draft is
encrypted to a key the server never holds, in the same browser-side vault
Drive folders and the password manager use. The server keeps routing,
threading, spam verdicts and the metadata it needs to serve the mailbox, and
nothing else it can open. The reader, search and compose keep working, in the
browser. A phone reads the same mail once it holds the key through the device
handoff. Lowering the level is possible and honest: the member's own browser
hands the mail back to the server.

What it costs, stated on the card: no server-side AI over this domain, no
server search (search runs on the device, over one field per message), mail
rules run only as mail arrives, the native apps hand off to the browser, and
mail that arrives while the server is compromised can be read at that
instant unless the relay add-on seals it at the edge.

## Design

### R1. One scope, custody chosen per row

The mailbox plugin declares scope **`mail`** (client custody, label "Mail
vault") under `vaultScopes` and takes the `client_reseals: ["mail"]`
obligation. PRF context derives as `vault-mail-kek`; nothing to declare.

`InboundEmailMessage::sealScopeForWrite(array $row)` answers **`mail`** when
the row's alias is at Fortress (its own `iea_security_level`, else the
domain's), and **`user`** otherwise. A row with no alias (catch-all,
domain-owned) follows the domain. The same rule, called on the row, decides
what `resolveSealTarget()` loads: `VaultClientCustody::loadVault($owner,
'mail')` at Fortress, `loadForUser($owner)` at Private. `sealColumns()`
already emits the browser format for a client-custody vault, so **ingest,
Sent copies and the raise batch seal to the browser key with the code that
seals today**; the difference is which vault row they are handed.

A Fortress alias whose owner holds no `mail` vault is a missing seal target:
mail is held (exit 75 / relay hold), exactly as a Private alias without a
vault. The level change refuses to create that state (R8).

`InboundEmailMessage::isBrowserSealed(array|self $row): bool` (the key column
starts with `v1.edgeseal.`) is the one predicate every server-side reader
gates on (R7).

### R2. The Fortress row

Same columns as a Private row; different formats, three more fields.

- Every `$sealed_fields` column holds `v1.edge.` under the row DEK with AD
  `mail:{id}:{field}`; the DEK is `v1.edgeseal.mail.…`.
- **Three new optional sealed fields**, written by whoever seals the row:
  - `iem_search_text` (text): what `MailboxIndex::rowContent()` folds today
    (sender, subject, plain body, readable text of the HTML body, attachment
    names), capped at 8192 characters after whitespace folding, gzip-compressed
    when that saves a third or more (value prefixed `gz:` + base64 before
    sealing; the browser uses `DecompressionStream('gzip')`, PHP `gzencode`).
  - `iem_snippet` (varchar 255): the first 240 characters of the readable
    body, the list preview.
  - `iem_attachment_manifest` (text, JSON): `[{id, filename, content_type,
    content_id, mime_part, inline, size}]` for the row's attachments.
    On a Fortress row `ima_filename`, `ima_content_type` and
    `ima_content_id` are `''`; `ima_mime_part`, `ima_encoding`,
    `ima_size_bytes`, `ima_is_inline`, `ima_fil_file_id` stay. The File row
    is named by the attachment id, typed `application/octet-stream`.
- **Attachment bytes** use shape (a) only: `SealedBox::aeadEncryptGcm(bytes,
  dek, 'mail:{mid}:att:{mime_part}')` stored as the File's content, the
  `v1.edge.` field format under the **message DEK**. No per-file key, no
  container; the browser opens them with the DEK it already holds for the
  row. The 25 MiB message cap bounds the one-shot AES-GCM.
- **No raw survives on a Fortress row.** `persistRawFallback` is not a path:
  when attachment extraction fails at ingest, the message is deferred (exit
  75) rather than stored with a raw. `iem_raw_message` and `RawMessageStore`
  are never written for a Fortress row (pinned by test).
- Server-readable columns are exactly today's: recipient (inbound),
  Message-ID, thread key, auth verdicts, spam verdict and score, size,
  times, direction, spool id, labels, flags. `iel_from_address` for a
  **sealing alias (Private or Fortress)** carries the envelope sender
  address only, never the From header's display name (WP0 fixes today's
  leak for both levels).

### R3. Arrival without the relay

The pipeline is unchanged: `processEmail` parses, `storeMessage` inserts the
row with empty content, `sealMessageContent` seals to the vault
`resolveSealTarget` handed it, `persistRawAndManifest` seals attachments
under the DEK, filters run on the in-memory plaintext, spam is scored by the
local milter as today. The server holds plaintext for that call and writes
none of it. Two additions at seal time: the search text, snippet and
manifest fields (R2), and the attachment bytes in the browser format.

`storeExtracted` (IMAP) never meets a Fortress alias (R8 refuses feeds).
`storeDirectMessage` (Joinery Direct) seals through the same resolver; its
attachments go through `storeDirectAttachments` with the same format rule.

### R4. The reader opens rows itself

The mailbox page calls `needs_vault_client()` and loads
`plugins/mailbox/assets/mailbox_fortress.js` (`window.MailboxFortress`),
which the reader calls at four seams. All Fortress content leaves the server
in the sealed-for-browser shape and is opened with `JoinerySealed.open`.

- **List.** `listThreads()` returns a Fortress thread's newest message as
  `sealed: {key, sealed_scope, sealed_dek, sealed_ad_prefix, iem_sender,
  iem_subject, iem_snippet}` and `subject`, `sender`, `snippet` empty;
  `senders` is the newest sender only; `ai_summary` is absent. The reader
  opens each `sealed` object after `thread_list` returns and fills the row.
  `fetchAndDecryptContent()` skips browser-sealed rows.
- **Thread.** `getThread()` returns a Fortress message with its sealed
  columns as stored plus `sealed_scope, sealed_dek, sealed_ad_prefix` (the
  `export_for_api_sealed_for_browser` shape merged into the message array),
  `attachments[]` with `id, mime_part, size_bytes, url, inline` and no
  name, `locked` untouched. The reader opens the row, then the manifest,
  and names the chips from it.
- **Attachments.** The attachment endpoints stream a Fortress File's bytes as
  stored (ciphertext), with `Content-Type: application/octet-stream` and no
  filename. The browser fetches, opens with the row DEK and the mime_part
  AD, and hands the user a blob URL named from the manifest. Image previews
  and `cid:` inline images are the same fetch: `resolveInlineImages` skips
  Fortress rows and the browser rewrites `cid:` to blob URLs before `srcdoc`.
  Text preview (`attachment_text`) is not offered on a Fortress row.
- **HTML.** Rendered exactly as today, in the sandboxed iframe with no scripts
  and no same-origin. Readable text for search and snippet is taken in the
  browser with `DOMParser` (`text/html`) `body.textContent`; nothing is
  executed or fetched.
- **Lock.** `JoinerySealed.onLock('mail', …)` blanks every rendered body,
  revokes every blob URL, drops opened rows and search entries, and returns
  the list to placeholders. The core idle lock is the timer. Unlock re-opens
  from the server copies.
- **Mixed mailboxes.** A domain converting up or down holds rows of both
  custodies; the reader handles each row by its shape (plain,
  `content_locked`, `sealed_scope`). A Private row in a Fortress domain still
  opens in the server window until the raise batch reaches it.
- **Pending rows** (relay path, R9) render as "Waiting to be opened on this
  device" until the parse runs.

### R5. Search runs in the browser

New action `mailbox/search_entries` (browser session): pages the caller's
Fortress rows for the requested scope (`alias_id` or all mail, same scoping
as `thread_list`) by id cursor, 200 per page: `[{id, thread_key, sealed_dek,
sealed_ad_prefix, iem_search_text, direction}]`. Drafts excluded. The
browser opens each entry into memory once per unlock and per scope (a
`Map` id → text; refreshed incrementally by `after_id` on later searches),
lower-cases, and matches every whitespace-separated token of the query as a
literal substring, the same rule `sanitizeFtsQuery` gives the server. The
matching thread keys go to `thread_list` as a new `thread_keys[]` parameter
(max 500), which replaces `q` for that request; the server orders and pages
them as it does any list. `MailboxService` skips `MailboxIndex::fold` and
`search` for a Fortress scope and returns `search_scope: 'device'`. Entries
are per message and idempotent: two devices never reconcile anything.

If opening one DEK per message proves slow on a very large mailbox, the
recorded follow-up is one per-mailbox search key wrapped to the `mail`
scope; the shape does not change.

### R6. Compose, Sent copies and drafts

- **Send is plaintext to the server**, as B10 settled: the server signs and
  hands SMTP plaintext to the next hop whatever the browser does. The
  reader builds the whole outgoing body itself (quoting the source it has
  already opened; `buildBody()` does not read a Fortress source) and posts
  as today. `MailboxSender` seals the **Sent copy to the mail key** through
  the same resolver (R1), with search text, snippet and manifest, and never
  writes a plaintext copy: `appendSentCopy` (IMAP APPEND) cannot apply
  (feeds are refused at Fortress); `logRefusedSend` writes the envelope
  addresses, not the subject, for a Fortress alias.
- **Drafts are sealed by the browser.** `draft_save` becomes a two-step
  `JoinerySealed.save` on a Fortress alias: step one the plain columns and
  the reply `{draft_id, sealed_ad_prefix}`, step two `{id, sealed_dek,
  fields, public_key}` into `acceptBrowserSealed` with `iem_body_plain,
  iem_body_html, iem_subject, iem_to, iem_cc, iem_bcc, iem_draft_state,
  iem_search_text, iem_snippet, iem_attachment_manifest`. Draft uploads are
  sealed in the browser under the draft DEK before upload
  (`v1.edge.`, AD `mail:{id}:att:{n}`) and stored as-is. `draft_get` returns
  the sealed shape. **The server never opens a draft**: on send, the browser
  re-uploads the attachments it holds and the Sent row gets a fresh DEK; the
  draft row is deleted as today.
- Both directions keep `iem_recipient` sealed on outbound rows, as today.

### R7. Server-side readers are gated on one predicate

Everything that opens message content on the server checks
`InboundEmailMessage::isBrowserSealed($row)` first and leaves the row out:

- AI: `EmailJobCandidates` excludes browser-sealed rows in SQL
  (`iem_sealed_key NOT LIKE 'v1.edgeseal.%'`); `EmailSecurityDigest::build`
  and `EmailAttachmentDigest::build` throw on one; `ModelQueryExecutor`
  already excludes rows it cannot open (the exception surfaces as
  "excluded", never as an error). Per `protection_levels_platform.md` AI
  rule 3, AI over Fortress mail is device-local or nothing; nothing
  device-local is built here.
- `MailboxIndex::loadForIndex` and `prime` skip them; the Postgres text
  search sees empty columns.
- `ApplyInboundEmailFilters` skips them; at arrival on the no-relay path
  filters run as today (R3). On the relay path they do not run (R9).
- `MailboxMessageTimeline` shows routing events only (no header block).
- Deliverability-report intercept (`parsePendingMessage` :896) does not
  apply to Fortress rows; a DMARC report to a Fortress alias is a message.
- `PromotedRowRepair`, `resealBackfillAttachments`, `unsealAndPersistContent`
  refuse a browser-sealed row (they are server-custody tools; R8's batches
  replace them for Fortress).
- Native apps: `thread_list` and `thread` answer `fortress: true` on a
  Fortress scope alongside the sealed shape. The apps are out of scope here;
  until they decrypt, they must show that state and open the web reader
  (owner follow-up A1 in the hand-back).

`tests/mailbox/fortress_server_readers_test.php` pins the gate: every
function in the list above, called on a browser-sealed fixture row, either
skips it or throws; none opens it.

### R8. Level changes, both directions (Q1)

**Core custody-change batch**, in `SystemBase` and two actions, mail is the
first caller; Drive keeps refusing Fortress transitions until a later spec
wires it (the batch is generic, the Drive UI is not built here).

- **Raise, server-side.** `SystemBase::convertRowToClientCustody(int
  $row_id, VaultKey $old_key, UserEncryptionVault $scope_vault): void` opens
  the row's DEK with `$old_key`, re-encrypts every populated sealed field
  from `v1.aead.` to `v1.edge.` under the **same DEK** (AD unchanged), seals
  the DEK to `$scope_vault->sealingPublicKey()` in the edge format and
  rewrites key column, generation and owner in one UPDATE. Mail wraps it:
  `InboundEmailMessage::convertToFortress($msg, $old_key, $vault)` also
  re-encrypts attachment bytes (shape (a) under the same DEK, shape (b)
  opened through `DriveSealed::fileKey` and re-stored as shape (a)), blanks
  the three `ima_` name columns into the manifest, and writes search text
  and snippet by reading the row it just opened. A shape (b) file becomes
  shape (a) so the browser needs one key per row.
- **Lower, browser-side.** `SystemBase::browserCustodyPage(int $user_id,
  string $scope, int $after_id, int $limit): array` pages the caller's rows
  under `v1.edgeseal.{scope}.` and keeps those for which
  `resolveSealScope($row)` **now** names a different scope (the hook is
  evaluated in PHP per row; alias and domain lookups are cached per
  request); `browserCustodyBacklog()` counts the same way. Returns
  `{rows: [{model, id, sealed_dek, target_scope, target_public_key}], next,
  remaining}`, the target public key being the target vault's
  `sealingPublicKey()`. `SystemBase::acceptBrowserCustodyChange(int
  $user_id, int $row_id, string $sealed_dek): void` accepts a DEK sealed in
  the edge format to the target scope's key (`v1.edgeseal.user.` for a
  server scope; `VaultKey::unsealEdge` opens it, `openField` opens the
  `v1.edge.` fields under it, so no field is rewritten), checks the blob's
  scope equals `resolveSealScope($row)` and that the row is the caller's,
  and rewrites key column, generation (the target vault's) and owner.
  Actions: `logic/vault_custody_rows_logic.php` (`{scope, model, after_id,
  limit}` over the classes registered by `clientReseal` for the scope) and
  `logic/vault_row_custody_logic.php` (`{rows: [{model, id, sealed_dek}]}`),
  both `requires_browser_session`. Browser:
  `JoinerySealed.changeCustody(scope, {progress})` walks pages, opens each
  DEK with the scope session, seals it with `session.sealTo(dek,
  target_public_key)`, posts, until `next` is null. Rows a page returns
  whose hook still answers the scope are not returned (the filter), so the
  walk terminates.

**Mail's transitions** (`admin_mailbox_domains_logic`, the receipt, the
mailbox page):

- **Who.** In this build a domain goes to Fortress only when the acting
  admin is the single owner of every mailbox on it (owner-operated domains;
  today's relay rule tightened to the actor), and the owner holds a `mail`
  vault: the domain editor page loads the vault client and, on choosing
  Fortress, runs `VaultKeyring.ensureUnlocked('mail')` (setup with recovery
  codes on first use) **before** the step-up POST. Refusals, each with its
  reason on the page: an IMAP feed on any alias (Q3: "the server holds a
  password that can read the whole source mailbox"), a group mailbox, a
  mailbox with no or several owners, an owner other than you. `LEVEL_FORTRESS`
  joins `SETTABLE_LEVELS`; `security_level()` returns `fortress` for a row
  that was set through `set_security_level`, and keeps reading the
  unconverted legacy value as Private (`is_unconverted()` distinguishes:
  a converted row carries `ied_level_set_time`, a new timestamp column set
  by `set_security_level`).
- **Private → Fortress.** The level flips at once (new mail seals to the
  mail key), then the backlog converts **in the owner's unlock window** as
  deferred work `mailbox_fortress_raise` (`VaultDeferredWork::register`, the
  `mailbox_parse` shape, 100 rows or 64 MiB of attachment bytes per pass),
  so it runs on the security heartbeat and on mailbox views; the receipt
  card and a mailbox banner show "N messages still being moved to your
  device key" from `mailbox_fortress_backlog_count()`. Rows not yet moved
  read through the server window as before. Standard → Fortress runs the
  existing raise (Standard → Private, `mailbox_protection_seal_batch`) with
  the resolver now handing it the mail vault, so it seals straight to the
  browser key: one batch, no intermediate state.
- **Fortress → Private.** The level flips first (new mail seals to the
  server key), then the receipt runs `JoinerySealed.changeCustody('mail')`
  in the admin's browser (the admin is the owner). A closed tab resumes
  from the mailbox banner ("Finish moving this mailbox off end-to-end: N
  messages", one button). The lowering-needs-open-window rule does not apply
  to this direction (the browser does the work); the lowering-refused-while-
  the-send-lock-enforces rule does.
- **Fortress → Standard** is Fortress → Private then the existing unseal
  batch, one ceremony with two phases on the receipt.
- The add-ons behave as at Private: relay sealing (R9) and the sending lock
  (server-side in-window DKIM signing over the outgoing plaintext, B10).
  `onWindowCaps` short caps apply when the sending lock is on; there is no
  server window over Fortress mail to cap.

### R9. Arrival with the relay (Seal at the relay add-on)

- **Fragment.** `sealTargetForAlias()` answers `[mail vault sealingPublicKey,
  'client']` for a Fortress alias under the add-on, with `key_scope: 'mail'`
  and `key_generation` from `sealingKeyGeneration()`; `user` stays for
  Private. `RelayMapSync` pushes on rotation begin and commit (the sealing
  key changes both times) and on the level change.
- **Sealing, Go.** `seal.go` gains `sealEdge(raw, recipientPub, scope,
  spoolID)`: a fresh 32-byte DEK, `AES-256-GCM(raw)` with a 12-byte IV and AD
  `mail:relay:{spool_id}`, output `"v1.edge." + base64(IV ‖ ct ‖ tag)`; the
  DEK sealed with X25519 + HKDF-SHA256 (`golang.org/x/crypto/curve25519`,
  `hkdf`; info `'sealed-vault:dek' ‖ ephPub ‖ recipientPub`, empty salt) +
  AES-GCM, output `"v1.edgeseal." + scope + "." + base64(ephPub ‖ IV ‖ ct)`.
  Written as two spool artifacts: `{id}.seal` (the body) and the DEK in the
  `.meta` sidecar as `sealed_dek`, with `key_kind: 'client'`, `key_scope`.
  `sealer_test.go` proves both against `tests/vault/fixtures/edge_vector.json`
  and `roundtrip_test.sh` gains the PHP-opens-Go-edge case
  (`SealedBox::openEdge` + `aeadDecryptGcm` with a test keypair).
- **Pull.** `RelaySpoolConsumer::ingestOne` stores a `client` blob as a
  pending row: `iem_pending_parse = true`, `iem_relay_sealed_raw` = the
  `v1.edge.` body, `iem_sealed_key` = the `v1.edgeseal.mail.` DEK,
  `iem_key_generation` from meta, `iem_content_sealed = true`, owner from
  `singleOwnerUserId` / `ownerByPublicKey` (which now also looks up client
  vaults by public key). `DeferredIngest` skips browser-sealed pending rows.
- **Parse, browser.** `plugins/mailbox/assets/mailbox_mime.js`
  (`window.MailboxMime.parse(bytes) → {headers, from, to, cc, subject, date,
  messageId, inReplyTo, references, textPlain, textHtml, attachments:
  [{filename, contentType, contentId, mimePart, inline, bytes}]}`): RFC 5322
  headers with folding and RFC 2047 encoded words, MIME multipart (nested),
  base64 and quoted-printable, charsets through `TextDecoder`, RFC 2231
  parameters, `text/plain` and `text/html` alternatives. Pinned by
  `plugins/mailbox/tests/mime_parser_gate.sh` running
  `mime_parser.mjs` (node) over fixture `.eml` files in
  `plugins/mailbox/tests/fixtures/mime/` (the pattern of
  `timestamp_ladder_gate.sh`). `MailboxFortress` opens the pending row's DEK
  and body, parses, seals the fields under the **same DEK** (sender, subject,
  bodies, raw headers, to, cc, search text, snippet, manifest) and the
  attachment bytes (AD by mime part), and posts one multipart request to
  `mailbox/fortress_parse_store`: `{id, public_key, fields{…},
  attachments[] (files), manifest, spam_headers{x_spam, x_spam_flag,
  x_spam_score, x_spam_status}}`. The server: `acceptBrowserSealed` for the
  fields (the DEK is unchanged, so `sealed_dek` is the stored one), File rows
  for the attachments, `classifySpam` from the posted header values and the
  row's stored auth verdicts (the same code path as `parsePendingMessage`
  :950-958), `iem_pending_parse = false`, `iem_relay_sealed_raw = null`,
  thread key from `references` if the meta had none. **The first device to
  open the row parses it**; a second device fetching the same id after that
  gets the parsed row (B11: parse results are per row, idempotent). The
  reader drains pending rows for the visible scope on unlock, newest first,
  with a count in the banner.
- **What the relay path gives up at Fortress**, said on the card when both
  are on: mail rules do not run on relay-sealed mail (no server plaintext,
  and rules are not evaluated in the browser in this build); spam learning
  is off (the relay's stateless verdict is the verdict; "not spam" moves the
  message and teaches nothing).

### R10. The browser pins the relay's seal target (Q4)

- **Relay.** New route `GET /relay/seal-target?recipient=<addr>` (tenant-
  signed like every `/relay/*` request) answers `{statement, signature}`
  where `statement` is canonical JSON `{recipient, public_key, key_kind,
  key_scope, key_generation, map_version, relay_identity_public_key,
  signed_at}` from the live routing entry and `signature` is the identity
  key's Ed25519 signature over `"joinery-relay:seal-target:v1\n" +
  statement` (`identity.sign`, the birth-report code path).
- **Server.** `mailbox/relay_seal_target` (browser session; the alias must be
  the caller's) proxies the relay's answer **byte for byte** and adds the
  relay's pinned `mrl_identity_public_key`.
- **Browser.** After the `mail` session opens on the mailbox page, for each
  Fortress alias under the add-on: fetch, verify the signature against the
  **pinned** relay identity, then check `public_key` equals the session's
  public key (or the pending key during a rotation) and `key_scope ===
  'mail'`. The pin is `{relay_identity_public_key, mac}` in the new column
  `iea_relay_identity_pin` (text), written through `mailbox/relay_pin_set`,
  where `mac = HMAC-SHA256(pinKey, alias_id ‖ relay_identity_public_key)`
  and `pinKey = HKDF-SHA256(vault secret, info 'sealed-vault:pin')`, exposed
  by the keyring session as `session.mac(bytes)`; the server cannot make or
  alter a pin the browser will accept. First use with no pin: pin what the
  server reports (trust on first use), and the card says so. A statement
  that fails the signature, names another key, or a relay identity that
  differs from the pin, stops with a modal: "Mail arriving at your relay is
  not being sealed to this device's key" with the two fingerprints, and an
  Approve button that re-pins only after a step-up. The `mail` rotation
  hook (`plugins/mailbox/assets/mailbox-reseal.js`, `onReseal('mail')`)
  re-MACs every pin the user holds with the new session.

### R11. Rotation, devices, recovery

`plugins/mailbox/includes/bootstrap.php` registers
`VaultUnlock::clientReseal('mail', ['InboundEmailMessage'],
['plugins/mailbox/assets/mailbox-reseal.js'])`. The rotation walk covers
every row under the scope, pending rows included (they carry a
`v1.edgeseal.mail.` key). The hook re-MACs relay pins (R10). The device
handoff offers `mail` automatically; the recovery-readiness ledger and the
security page card appear per vault row. A rotation re-pushes the relay map
at begin and commit.

### R12. The cards

`ProtectionLevelPicker::catalog()` gains a `SERVICE_MAIL` Fortress entry:

- Title: **Fortress**
- Protects: "Only your devices can read stored mail. A stolen database or a
  hacked server gets nothing it can open."
- Costs: "No server-side AI or server search on this domain; search runs on
  your device. Mail rules run only as mail arrives. Phone apps open this
  mailbox in the browser."
- Note (relay add-on off): "New mail is encrypted the moment it arrives; a
  server hacked while mail is arriving could read what arrives then."
- Note (relay add-on on): "This server never sees your mail, not even as it
  arrives. Your browser checks which key the relay seals to, pinned on first
  use. Mail rules do not run on relay-sealed mail."

Relay add-on copy at Fortress (`addonCatalog`, keyed by level):
protects "This server never sees your mail, not even as it arrives", costs
"New mail is opened on your device; mail rules do not run on it."

## What does not change

Standard and Private mail, the relay's transport-key path, the sending lock,
the server-custody rotation, the `user` vault, the reader for Private rows,
`MailboxIndex` for Private mailboxes, forwarding aliases (a forward is a
routing decision at arrival), Joinery Direct's wire format (its stored copy
follows R1), backups (ciphertext either way), the sync client, the native
apps' code.

## Work packages

Each WP leaves the tree working and is reviewable alone. Write the named
test before the code it tests.

### WP0. Declarations, the per-row scope, refusals, columns

- `plugins/mailbox/plugin.json`: `vaultScopes.mail` (client, "Mail vault");
  `vaultConsumer.client_reseals: ["mail"]`; bump `version`.
- `InboundEmailMessage`: `sealScopeForWrite()` per R1 (alias level, else
  domain level; cache alias/domain rows per request in a static map);
  `isBrowserSealed()`; columns `iem_search_text` (text), `iem_snippet`
  (varchar 255), `iem_attachment_manifest` (text), all in `$sealed_fields`
  and `$optional_sealed_fields`; `sealAd` unchanged.
- `InboundEmailDomain`: `LEVEL_FORTRESS` in `SETTABLE_LEVELS`;
  `ied_level_set_time` (timestamptz, nullable) set by `set_security_level`;
  `security_level()` and `is_unconverted()` per R8; `seals_content()` is
  Private **or** Fortress; `is_fortress()`. Alias: `iea_relay_identity_pin`
  (text, nullable). Remove the "Not built" comments.
- `InboundEmailRouter::resolveSealTarget()` and
  `MailboxSender::sealTargetFor()`: load the vault for the scope
  `sealScopeForWrite` names (build the row array the hook needs from alias
  and domain ids). `MailboxSealTargetMissing` message names the mail vault
  when the scope is `mail`.
- `admin_mailbox_domains_logic`: the Fortress refusals of R8 (feed, group,
  owner count, actor is the owner, actor holds a `mail` vault via
  `VaultClientCustody::loadVault`), each a page error naming the reason.
  Keep every existing check.
- `logTransaction()`: `iel_from_address` = envelope sender address for a
  sealing alias, both levels.
- **Stop point 1**, then `php utils/update_database.php`.
- **Tests:** `plugins/mailbox/tests/fortress_scope_test.php` (tier
  `test-db`): the hook answers `mail` for a Fortress alias, an inheriting
  alias on a Fortress domain, a domain-owned row on a Fortress domain, and
  `user` for a Private alias on a Fortress domain and for a Standard one;
  `resolveSealTarget` loads the client vault (fixture
  `vault_fixture_client_vault(owner, pub, 'mail')`) and throws
  `MailboxSealTargetMissing` without one; `set_security_level('fortress')`
  is accepted and `security_level()` reads it back while a legacy
  unconverted row still reads Private; the refusal list. `mailbox_level_scope`
  and `protection_addons` suites stay green. Extend
  `plugins/mailbox/tests/inbound_raw_storage_test.php`: `iel_from_address`
  carries no display name for a sealing alias.

### WP1. Ingest to the browser key, attachment format, no raw

- `sealMessageContent` / `sealAndPersistContent`: when the vault is client
  custody, also write `iem_search_text` (the `MailboxIndex::rowContent`
  recipe extracted into `InboundEmailMessage::searchTextFor(array $parts)`,
  cap and gzip per R2), `iem_snippet`, `iem_attachment_manifest`.
- `extractAttachmentsToFiles` / `persistRawAndManifest` /
  `storeDirectAttachments` / `persistOutboundUploads` /
  `persistInlineUploads`: on a client-custody DEK, bytes through
  `SealedBox::aeadEncryptGcm` with the attachment AD, the three `ima_` name
  columns blank, File named by attachment id. `persistRawFallback` refuses a
  client-custody row: the caller defers (exit 75) instead.
- `MailboxSender::storeOutboundRow`: same writers for the Sent copy;
  `appendSentCopy` and `logRefusedSend` per R6.
- **Tests:** `plugins/mailbox/tests/fortress_ingest_test.php` (`test-db`):
  drive `storeMessage` with a fixture raw (two attachments, one inline HTML
  image) to a Fortress alias whose owner has a client vault made from a test
  keypair; assert every sealed column is `v1.edge.` and the key
  `v1.edgeseal.mail.`, open the DEK with `SealedBox::openEdge` and each
  field with `aeadDecryptGcm` and the row's AD, open one attachment with
  the mime-part AD, decode the manifest and the search text (gzip case),
  assert `ima_filename = ''`, `iem_raw_message IS NULL`, no `RawMessageStore`
  file, no plaintext in `iel_`; a raw that fails extraction is deferred and
  leaves no row. Same for `storeOutboundRow`. `tests/vault/sealed_read_paths_test.php`
  gains `plugins/mailbox/includes/InboundEmailRouter.php` and
  `MailboxSender.php` in `$allowed` only if they call a primitive directly
  (prefer `VaultCrypto` wrappers so they need not).

### WP2. The reader opens Fortress rows

- `plugins/mailbox/views/profile/mailbox.php`: `needs_vault_client()`;
  mount `mailbox_fortress.js` and `mailbox-reseal.js` through
  `mailbox_reader_mount.php` with `MAILBOX_READER.fortress = true` when any
  visible alias is Fortress.
- `MailboxService::listThreads` / `getThread` / `withSignedTransport` /
  `resolveInlineImages` and the attachment endpoints per R4; `thread_list`
  and `thread` descriptors document `sealed`, `sealed_scope`, `fortress`.
- `mailbox_fortress.js`: `openList(threads)`, `openThread(messages)`,
  `attachmentBlob(message, att)`, `inlineRewrite(message, html)`, the lock
  teardown, and `selfCheck()`. The reader calls them at the four seams
  (list fill :1113, thread render :1557, chip click :2254, preview :2461).
- R7 gates, every reader in the list, plus the pinning test.
- **Tests:** `plugins/mailbox/tests/fortress_reader_api_test.php`
  (`test-db`): `thread_list` and `thread` on a Fortress fixture return the
  sealed shape with no plaintext field and `fortress: true`; the attachment
  endpoint streams ciphertext with no filename; `resolveInlineImages` leaves
  `cid:` alone. `fortress_server_readers_test.php` per R7.
- **Acceptance (stop point 2):** on dev, set a test domain to Fortress as
  user 4500 (WP5 is not built yet: set it through the model in a one-off
  PHP snippet the owner approves, or wait for WP5 and run this walk then;
  say which), send a message with an attachment to its alias, unlock the
  mail vault in the reader, read it, download the attachment, lock, see the
  body blank. Screenshot each. psql on the row shows ciphertext only.

### WP3. Search on the device

- `mailbox/search_entries` action, `thread_keys[]` on `thread_list`,
  `MailboxService` skipping the server index for a Fortress scope,
  `search_scope: 'device'`.
- `mailbox_fortress.js`: the entry cache, incremental refresh, token match,
  and the reader's search box routed through it when
  `MAILBOX_READER.fortress`.
- **Tests:** `plugins/mailbox/tests/fortress_search_test.php` (`test-db`):
  entries page by cursor, drafts excluded, another user's rows never
  returned; `thread_list` with `thread_keys[]` returns exactly those threads
  and ignores `q`. `mailbox_search_scope` suite stays green.
- **Acceptance:** search a word from a Fortress message body and from an
  attachment name; both hit. Screenshot.

### WP4. Compose, Sent copy, drafts

- Reader: quoting in the browser for a Fortress source; drafts through
  `JoinerySealed.save('mailbox/draft_save', …)` with browser-sealed uploads;
  send re-uploads attachments; `draft_get` opened in the browser.
- `draft_save_logic` two-step, `MailboxDrafts::saveDraft` accepting the
  sealed step through `acceptBrowserSealed` (its authorization: the draft is
  the caller's), `persistDraftUploads` storing sealed bytes as-is.
- **Tests:** `plugins/mailbox/tests/fortress_compose_test.php`
  (`test-db`): the Sent copy of a send from a Fortress alias is
  browser-sealed with search text and manifest and no plaintext copy
  anywhere (`mst_`, `evl_`, `iem_raw_message`); a draft saved in two steps
  holds `v1.edge.` fields and refuses a plaintext body on a Fortress alias;
  `drafts` and `compose_stores_its_row` suites stay green.
- **Acceptance:** reply to the WP2 message with an attachment; the reply
  lands in Sent, opens, and the draft autosave never sends plaintext (check
  the request bodies in the browser's network log). Screenshot.

### WP5. Level changes (R8)

- Core: `convertRowToClientCustody`, `browserCustodyPage`,
  `browserCustodyBacklog`, `acceptBrowserCustodyChange` in `SystemBase`;
  `logic/vault_custody_rows_logic.php`, `logic/vault_row_custody_logic.php`;
  `JoinerySealed.changeCustody`; `VaultKey::unsealEdge` is already there.
- Mail: `InboundEmailMessage::convertToFortress`, deferred work
  `mailbox_fortress_raise`, `mailbox_fortress_backlog_count`, the domain
  editor (vault client loaded, `ensureUnlocked('mail')` before the step-up
  POST on choosing Fortress, the receipt running the raise count or the
  browser lowering, the two-phase Fortress → Standard), the mailbox banner
  with resume button, `admin_mailbox_domains_logic` rules per R8,
  `ProtectionLevelPicker` copy (R12).
- **Tests:** `tests/vault/custody_change_test.php` (`test-db`): fixture
  probe model (the `client_reseal_batch_test` pattern) with rows under
  `drive`; flip the probe's hook answer; the page returns only the rows the
  hook moved, `acceptBrowserCustodyChange` accepts a `v1.edgeseal.user.`
  blob the test makes with `sealItemDekToBrowserKey`, opens the fields with
  the server key, refuses another user's row and a blob for the wrong scope;
  `convertRowToClientCustody` turns a `user` row into a browser-openable
  one with the same DEK. `plugins/mailbox/tests/fortress_level_change_test.php`
  (`test-db`): Private → Fortress converts a row with a shape (b)
  attachment into shape (a) and writes the manifest and search text;
  Standard → Fortress seals straight to the browser key; Fortress → Private
  via the accept path leaves the row server-readable; the refusals.
  `raise_receipt`, `lowering_unseal`, `protection_ceremony` stay green.
- **Acceptance:** on dev, raise the test domain Private → Fortress from the
  editor (vault setup ceremony, step-up, receipt, backlog drains on the
  mailbox page), read an old message; lower it back; read it in the
  server window. Screenshots at each state.

### WP6. Rotation and devices

- `bootstrap.php` registration, `mailbox-reseal.js` (pins only, R10
  lands in WP8; until then the hook is a no-op that reports), the relay-map
  push on begin and commit (`VaultClientRotation` calls a
  `VaultUnlock::onClientRotation($scope, callable)` registry, new here, that
  the mailbox bootstrap uses; keep it tiny).
- **Tests:** extend `tests/vault/client_reseal_batch_test.php` or add
  `plugins/mailbox/tests/fortress_rotation_test.php`: a rotation of `mail`
  walks message rows and pending rows, commit refuses with one left, the
  registry callback fires at begin and commit.
- **Acceptance:** rotate the mail vault on dev from the security page with
  three Fortress messages; they open after. Link a device
  (`/profile/devices_link`) and see "Mail vault" in its held scopes.
  Screenshot.

### WP7. The relay path

- Go: `sealEdge`, `key_kind: 'client'` + `key_scope` in `routingEntry`, the
  `.meta` fields, `sealer_test.go` on the shared vector, `roundtrip_test.sh`.
  `build.sh`; **stop point 3** before the binary leaves the tree.
- PHP: `RelayMapExporter` per R9, `RelaySpoolConsumer::ingestOne` storing the
  client pending row, `ownerByPublicKey` over client vaults,
  `DeferredIngest` skipping browser-sealed rows, `mailbox/fortress_parse_store`
  (multipart; `classifySpam` from posted headers), the pending banner and
  drain in `mailbox_fortress.js`, `mailbox_mime.js` with its gate.
- **Tests:** `plugins/mailbox/tests/fortress_relay_pull_test.php`
  (`test-db`): a spool entry with `key_kind: client` (made in PHP with the
  test keypair, the same bytes the Go test emits) stores the pending row
  with the DEK in the key column; `fortress_parse_store` accepts fields under
  that DEK, refuses a different DEK, classifies spam from the posted
  headers, clears pending; a second post on a parsed row is a no-op.
  `mime_parser_gate.sh` over at least: plain, multipart/alternative,
  nested multipart with an inline image and a base64 attachment,
  quoted-printable ISO-8859-1, RFC 2047 subject, RFC 2231 filename.
  `relay_map_pending`, `relay_client`, `relay_sealer_publish` stay green.
- **Acceptance:** owner deploys the binary to the dev relay; a message to a
  relay-fronted Fortress alias appears as pending, opens on unlock, and the
  parsed row reads on a second browser without re-parsing. Screenshots.

### WP8. The relay pin (R10)

- Go route and signing; `mailbox/relay_seal_target`, `mailbox/relay_pin_set`;
  `session.mac()` in `vault-keyring.js` (HKDF of the secret, info
  `'sealed-vault:pin'`); the check and the modal in `mailbox_fortress.js`;
  `mailbox-reseal.js` re-MACs pins.
- **Tests:** Go: the statement verifies under the identity public key and
  fails when a byte changes. PHP `plugins/mailbox/tests/fortress_relay_pin_test.php`:
  `relay_pin_set` refuses an alias not the caller's; the proxy returns the
  relay body unchanged. JS `selfCheck`: verify passes on a fixture
  statement, fails on a changed key, the MAC matches the PHP vector.
- **Acceptance:** first open pins (card says so); change the pinned key in
  the fixture and see the modal. Screenshot.

### WP9. Docs

- `docs/sealed_vault.md` § Client-custody scopes: the custody-change batch,
  the `onClientRotation` registry. `plugins/mailbox/docs/overview.md`: the
  Fortress row, arrival on both paths, the reader, search, compose, level
  changes, the relay pin, what the apps do. `docs/account_security.md` if it
  lists vault scopes. `specs/protection_levels_platform.md` matrix row
  "Fortress" → built. Current state only. Update the `DEFERRED` pointers in
  `inbound_email_domains_class.php` comments. **Stop point 4.**

## Tests

New: `fortress_scope`, `fortress_ingest`, `fortress_reader_api`,
`fortress_server_readers`, `fortress_search`, `fortress_compose`,
`fortress_level_change`, `fortress_rotation`, `fortress_relay_pull`,
`fortress_relay_pin` under `plugins/mailbox/tests/`; `tests/vault/custody_change_test.php`;
`mime_parser_gate.sh` + `.mjs`; Go `sealer_test.go` cases. Changed:
`sealed_read_paths_test.php`, `inbound_raw_storage_test.php`,
`roundtrip_test.sh`. Browser walks at each WP's acceptance line with
screenshots. Before hand-back: `php tests/run.php db --changed` green and
the four mailbox suites the memory names for sync verdicts untouched.

## Design record (from the deferred spec, condensed)

**Why mail is server-custody by default.** Custody follows whether the
server needs to read the content. Drive and passwords never had server-side
processing, so client custody cost them nothing. Mail's server reads bodies
for full-text search over years-deep archives and for automation (AI
triage, spam learning, labeling) while the user is away. Fortress mail gives
that up for the mailboxes that opt in; the card says so.

**What survives:** reading and rendering (the sandboxed iframe already
exists), attachments and previews (in the browser), search (one sealed
field per message, matched on the device), compose and send (plaintext to
the server for transport; the server signs in-window under the sending lock
and never stores a plaintext copy), threading, sorting and listing
(cleartext operational metadata).

**What collapses:** server-side automation over content (AI triage while
away; AI over Fortress mail is device-local or nothing); content in
notifications (none exist; none may be built); spam learning (the stateless
verdict survives); mail rules on relay-sealed mail.

**Residuals, stated honestly:** decrypted content lives in browser memory
while unlocked and on a linked phone behind its biometric gate; cleartext
metadata (Message-ID, references, envelope recipient and sender, times,
sizes); mail arriving during a server compromise on the no-relay path;
served JavaScript (native or extension clients would close it; the device
handoff already keeps the key off the wire).

**Review 2026-09-23 corrections carried:** B1 client custody shipped; B1a
native clients take the key by device handoff; B2 the browser format is the
one format (PHP sealer built, Go sealer in WP7); B3 deferred ingest moves to
the browser only on the relay path; B4 the PRF context derives; B5 no
content notifications exist; B6 both level directions (R8); B7 no server AI
over Fortress mail; B8 spam verdicts survive, learning does not; B9 feeds
refused; B10 sending lock stays server-side in-window; B11 parse results
are per row; B12 rotation shipped. S1 iframe reused; S2 attachments as one
key per row (shape (a), simpler than the container for mail); S3 search as
one field; S4 interop overlap recorded, not merged; S5 seal-target
selection is one branch. V1 pinned (R10); V2 metadata residual stated; V3
plaintext writes enumerated and the From-header leak fixed; V4 lock tears
down.

## Decisions 2026-09-24 (owner)

- **Q1 → both level directions, first build, core batch, mail first; Drive
  inherits later.**
- **Q2 → not a decision:** the relay stamps a spam verdict inside the sealed
  raw; the browser reads it; learning is what Fortress loses.
- **Q3 → IMAP feeds refused at Fortress**, with the reason in the refusal.
- **Q4 → relay seal target pinned in the first build**, relay-signed,
  browser-verified, trust on first use said on the card.
- Executor-preparation assumptions to confirm (hand-back items): **A1** the
  native apps show the Fortress state and open the web reader until a later
  spec gives them the key and the crypto; **A2** in this build a domain goes
  to Fortress only when the acting admin is the single owner of every
  mailbox on it (lifting it needs a "requested, waiting for owners' vaults"
  state, recorded, not built).
