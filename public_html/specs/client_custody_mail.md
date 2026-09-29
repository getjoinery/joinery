# Client-custody mail — Fortress, end-to-end encrypted mailboxes

**Status: ACTIVE 2026-09-24, prepared for an executor.** Supersedes
`specs/DEFERRED_client_custody_mail.md` (removed 2026-09-24; its design record is folded in at the
end). Builds on `specs/implemented/client_custody_declared_consumer.md`
(commit 243ff863: per-row custody, the browser-format sealer, the ceremony and
scope session in core, per-scope device handoff, client-scope rotation) and
`specs/implemented/protection_levels_fold.md` (mail's two cards and add-ons).
Level doctrine: `specs/protection_levels_platform.md` (R4: Fortress means
end-to-end, nothing less). The four decisions this spec needed were taken with
the owner on 2026-09-24 and are the last section. **Search (R5, WP3) was
redesigned with the owner on 2026-09-27** for 10 GB mailboxes: a sealed word
index kept in each browser, not a per-search download (§ Decisions
2026-09-27). WP0–WP2b are built (fb0a830c); WP3 is built and walked on dev
(8e008eeb); WP4 is built, reviewed and walked on dev (75b9501a). WP5 is
built, tested and walked on dev (2026-09-28, uncommitted; § WP5 As built).

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
   `iem_inbound_email_messages`, one on `iea_inbound_email_aliases`), and
   again in WP3 (one column and one index on `iem_inbound_email_messages`,
   the new `msk_mailbox_search_keys` table).
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
server search (search runs on the device, over a sealed word index each
browser builds once and keeps up to date), mail
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

`InboundEmailMessage::isBrowserSealed(array|self $row): bool` (the key is
sealed to a client-custody scope, `VaultCrypto::clientCustodyScope()`; a
lowered row's `v1.edgeseal.user.` key is the server's, B12) is the one
predicate every server-side reader gates on (R7).

### R2. The Fortress row

Same columns as a Private row; different formats, three more fields.

- Every `$sealed_fields` column holds `v1.edge.` under the row DEK with AD
  `mail:{id}:{field}`; the DEK is `v1.edgeseal.mail.…`.
- **Three new optional sealed fields**, written by whoever seals the row:
  - `iem_search_text` (text): what `MailboxIndex::rowContent()` folds today
    (sender, subject, plain body, readable text of the HTML body, attachment
    names), capped at 32768 characters after whitespace folding (owner,
    2026-09-27; `SEARCH_TEXT_MAX_CHARS`), gzip-compressed
    when that saves a third or more (value prefixed `gz:` + base64 before
    sealing; the browser uses `DecompressionStream('gzip')`, PHP `gzencode`).
    It is what a browser reads once to build its search index (R5), never
    per search.
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
- One more server-readable column, WP3: `iem_search_written_time`
  (timestamp(6), nullable), when `iem_search_text` was last written on the
  row. It is what a browser's index catches up by (R5), because a row's
  search text can arrive long after its id (relay parse, a raise, a draft
  turning into its Sent row in place).

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
- **Lock.** `JoinerySealed.onLock('mail', …)` (subscribed once the deferred
  vault modules have run; the file loads before them) blanks every rendered body,
  revokes every blob URL, drops opened rows, ends the search worker (R5:
  its key and every opened index record go with it; the saved index stays
  sealed on disk), and returns the list to placeholders. The core idle lock is the timer. Unlock re-opens
  from the server copies.
- **Mixed mailboxes.** A domain converting up or down holds rows of both
  custodies; the reader handles each row by its shape (plain,
  `content_locked`, `sealed_scope`). A Private row in a Fortress domain still
  opens in the server window until the raise batch reaches it.
- **Pending rows** (relay path, R9) render as "Waiting to be opened on this
  device" until the parse runs.

### R4a. An open vault survives a reload of the same tab (owner, 2026-09-24)

A browser-held vault's secret lives in the page's memory, so without this a
reload, or following a link to another page, asked for the passkey again.
Owner decision: that is not acceptable for mail; keep the key open across
reloads in the same tab without handing the server anything it can open.

- **The split.** When a client scope opens (`JoinerySealed.session()`), the
  browser makes two random 32-byte halves and wraps the vault secret under
  `AES-GCM` with a key from `HKDF-SHA256(server half ‖ tab half, info
  'joinery-vault-resume:v1:{scope}')`, AD `vault:{scope}:resume`
  (`session.wrapUnder`). The tab keeps `{wrapped, tab half, public_key,
  label}` in `sessionStorage` (`jy_vault_resume:{scope}`); the server keeps
  the other half in this sign-in's PHP session through
  `vault_client_resume` (`op` put / get / drop, keyed by scope and a random
  per-tab id so two tabs never overwrite each other; at most 8 per scope).
  Neither half opens anything alone, and the server never sees the tab half
  or the wrapped secret.
- **Reopening.** At load `JoinerySealed.ready` asks for the server half,
  checks the stored public key is still the vault's (current or pending; a
  rotation that retired it drops the halves), rebuilds the key, unwraps,
  and dispatches `joinery:vault-scope-unlocked` with `resumed: true`.
  `session()` waits for `ready`, so no ceremony runs for a scope the tab
  already opened. `MailboxFortress` waits for it before calling the vault
  shut.
- **Ending.** `pagehide` (reload, navigation, tab close) drops only the
  in-memory key. Every real lock — Lock now, the idle lock, a consumer's
  `lock()` — also drops both halves. Closing the tab loses the tab half;
  signing out or the session ending loses the server half. The idle clock
  survives reloads (`jy_vault_activity` in `sessionStorage`): a tab idle past
  its limit does not reopen. A brand-new tab asks once.
- **What it costs.** While the tab is open and the session is live, a
  machine holding both the browser's storage and the session cookie can
  reopen the vault; that is the same machine the unlocked page already
  runs on. The server alone, a stolen database, or a disk image of the
  browser without the live session, opens nothing. Shares are never logged.
- Core, not mail-only: Drive's client-custody folders and the password vault
  get the same behaviour.

### R5. Search runs on the device, over an index each browser keeps

**Why an index.** 10 GB of mail is about 105,000 messages (dev averages
95 KB a message). Opening every message's search text on each search would
download a few hundred MB, spend 30–60 s decrypting, and hold ~500 MB of
text in the tab, on every page load. So each browser builds a word index
**once**, keeps it sealed in its own storage, and afterwards adds only new
mail. A search then opens one small index record per word.

**Once per browser.** The saved index survives closing the tab or browser,
signing out and in, restarting, and lock/unlock. A full build happens only
in a new browser or device, after the site data is cleared, after a format
change (the `FORMAT` rule of `MailboxIndex`: rebuild, never an error), or
when the person asks ("Rebuild"). Key rotation does
**not** rebuild (the search key survives it, below).

**Shape: the Private index's rules** (`MailboxIndex` § SHAPE: contentless,
no positions; measured 799 MB → ~105 MB for 101k messages, identical
results), plus four of its own:

- **Word → message numbers, nothing else.** No text is stored and no word
  positions; a query is whole words that must all be present, the rule
  `sanitizeFtsQuery` gives the server.
- **Dense numbers.** Each browser numbers messages 0, 1, 2… in the order it
  indexes them; a word's list is ascending numbers stored as gaps in
  unsigned LEB128 varints (mostly one byte each). The `ids` record maps
  number → message id. Server ids are shared by every user's mail, so their
  gaps would be large.
- **List or bitmap, whichever is smaller,** per word: a bitmap of
  ⌈count/8⌉ bytes wins for a word in more than about one message in eight.
- **No word over 40 characters** is indexed (tracking codes, encoded blobs:
  most of the vocabulary of marketing mail, and never searched).
- **256 shards,** by the low 8 bits of FNV-1a-32 of the word's UTF-8 bytes.
  A shard is its words in byte order, each written as the bytes it shares
  with the word before plus the rest, then its list or bitmap. A word's count
  and last message are read from its list or bitmap, not stored: most words
  are in one message, and every stored byte is paid per word. Each saved
  record is gzip (`CompressionStream`) then AES-256-GCM.

Target, pinned by the WP3 benchmark: **at most 60 MB saved per 100,000
messages** at the 32 KB search-text cap (the Private index for the same mail
is ~105 MB, uncapped bodies). Measured 2026-09-27: 51.7 MB for 100,000
synthetic messages (234 MB of search text, pessimistic on rare words), the
slowest two-word query 20 ms in node; dev's 2,018 real messages cost 186
bytes each (short test mail).

**Tokenizer.** Mirrors SQLite FTS5 `unicode61` with its default
`remove_diacritics`: NFKD, drop combining marks, lower-case, split on
anything not a letter or number (`/[^\p{L}\p{N}]+/u`), drop empties. The
query goes through the same function; a query word that splits
("e-mail") requires every piece, since there are no positions.

**The search key.** One random 32-byte key per user, sealed to the `mail`
scope and held by the server, which cannot open it. Model
`MailboxSearchKey` (`plugins/mailbox/data/mailbox_search_keys_class.php`,
table `msk_mailbox_search_keys`): `msk_usr_user_id` (unique, cascade),
`msk_sealed_key` (`v1.edgeseal.mail.…`), `msk_key_generation`,
`msk_sealed_owner_user_id`, create time. The pattern is the password vault's
store key (`plugins/vault/data/vault_keyring_class.php`,
`keyring_save_logic.php`): **create-only**; the first browser that needs one
makes it (`session.sealTo`) and posts it; a second browser racing it gets
"already set up" and fetches. Those three `msk_` columns are what
`SystemBase::browserResealPage` walks, so the bootstrap registers
`VaultUnlock::clientReseal('mail', [InboundEmailMessage::class,
MailboxSearchKey::class])` and a rotation re-seals it with no script of its
own; commit refuses while it sits on the old generation, as for messages.
The key itself does not change, so no browser rebuilds. That leaks nothing:
the key opens only a browser's saved index, whose words that browser
already held. Each record's AES key is `HKDF-SHA256(search key, salt '',
info 'mailbox-search:v1')` (non-extractable); AD
`mailsearch:{user_id}:{record}:{FORMAT}`. A `head` record in the clear
carries the format and a fingerprint of the key (`HKDF` info
`'mailbox-search:fp'`, first 16 bytes); a different key or format, or records
that will not open, clear the database and rebuild.

**Where it is kept.** IndexedDB database `jy_mailsearch_{user_id}` on the
site's origin (two people on one browser never share one). Records: `head`,
`meta` (`{seq, doc_count, catchup, backfill, backfill_done, build_started,
total}`), `ids`, `shard:00` … `shard:ff`, `tail`. One save writes every
record it changed in one transaction. Two tabs share the database: each
operation runs under a Web Lock (exclusive to write, shared to search) and
reloads first when another tab saved since (`seq`, a random stamp per save);
cursors only move forward; one tab at a time runs the first build (a second
Web Lock). The
first build calls `navigator.storage.persist()`, so the browser does not
clear it to free space. No path of its own for private windows: current
browsers give them IndexedDB and delete it when the window closes, so the
index builds as anywhere else and is gone with the window. If IndexedDB
will not open or a write fails, the search box says "Search couldn't save
its index in this browser" and search stops there. The reader has no
settings menu, so the controls sit on the line a Fortress search puts above
its results: "Rebuild" and "Remove from this browser" (clears the database;
the next search here builds again). Signing out does not delete it; it is
sealed, and the next unlock reuses it.

**Server side.**

- `iem_search_written_time` (R2) is set with `clock_timestamp()` in the
  **same UPDATE** that writes `iem_search_text`: `sealFortressDerived`
  (ingest, Joinery Direct, the Sent copy), `fortress_parse_store` (WP7),
  the raise (`MailboxFortressLevel::convertRow`, WP5). A draft that turns into its Sent row in place
  gets a fresh value at send. Each of these commits within minutes of its
  stamp (the bound below). The hook is
  `SystemBase::sealedWriteMarks()` (honoured by `sealColumns()` and
  `acceptBrowserSealed()`), which `InboundEmailMessage` answers for
  `iem_search_text`. Index `iem_search_written_idx`
  `(iem_sealed_owner_user_id, iem_search_written_time,
  iem_inbound_email_message_id) WHERE iem_search_written_time IS NOT NULL`
  (migration `iem_017`: a data class cannot declare a composite index).
- `mailbox/search_entries` (browser session): the caller's rows with
  `iem_sealed_key LIKE 'v1.edgeseal.mail.%'` (escaped as
  `browserResealPage` does), `iem_sealed_owner_user_id` = caller,
  `iem_search_written_time IS NOT NULL`, direction not `draft`, in every
  alias, folder and delete state (the Private COVERAGE rule: the read scope
  decides what a search returns, so restore works). Two modes, 200 rows a
  page: `order: 'new'` after a `since` cursor `(time, id)` ascending
  (catch-up); `order: 'old'` before a `before` cursor descending (the first
  build, newest first). Each row `{id, sealed}`, `sealed` being
  `{key, sealed_scope, sealed_dek, sealed_ad_prefix, iem_search_text}`; the
  page adds `next` (the cursor, null on the last page), `last` (where a
  caught-up walk got to), `total` with `with_total` (rows that match without
  the cursor) and `server_time`. `overlap` on a catch-up's first page reaches
  ten minutes behind the cursor. Rules in `MailboxDeviceSearch`.
- `mailbox/search_key`: `op: 'get'` returns `{set_up, sealed_key, user_id}`;
  `op: 'create'` takes `sealed_key` and `public_key`, refuses a blob that is
  not `v1.edgeseal.mail.`, takes the generation from `public_key` (current
  or pending, as `acceptBrowserSealed` does), and refuses when a row exists.
- `thread_list` takes `device_hits`: base64 of the ascending hit message
  ids as delta varints (the index's own encoder; a PHP decoder beside it),
  up to 500,000 ids (more is a 400). It is unioned with the server's search
  exactly as `MailboxIndex` ids are today, `(iem_inbound_email_message_id
  IN (…) OR <server search>)`, so a mailbox part way through a raise finds
  its Private rows in the server window and its Fortress rows on the
  device. The scope WHERE is the authorization; nothing is cut at 500.
  `MailboxIndex` already skips Fortress rows (its 1.12). The response adds
  nothing: the reader knows it searched on the device.
- **No term when every mailbox in view is Fortress** (review 2026-09-27,
  B4). `q` with `device_hits` would tell the server which Fortress messages
  hold which word, search after search. So when every mailbox in view is
  Fortress the reader sends `device_only` and no term (an all-access
  viewer's All mailboxes is never "every mailbox Fortress": it also holds
  unmatched mail the server searches; review B9); `thread_list` drops
  any `q` that comes with it, and `MailboxService` runs the search as the ids
  alone (`device_search`), inside the same scope. With the vault shut, or a
  browser that cannot search on the device, the list is empty under the
  unlock banner (or says the browser cannot search): the term still stays in
  the browser. **Residual, stated in `plugins/mailbox/docs/overview.md`:** in
  a view mixing Fortress and server-searchable mailboxes the term goes with
  the ids, since the server searches its half; the server then sees which of
  the Fortress messages in view hold the word. The server still never sees a
  Fortress message's content.
- **The writers' transactions are bounded by the overlap** (review
  2026-09-27, B5). A catch-up reaches `OVERLAP_SECONDS` (ten minutes) behind
  its cursor. A writer that stamps `iem_search_written_time` and commits more
  than ten minutes later can land behind a cursor that has already passed,
  and no browser indexes that row. Every writer commits per row or per short
  batch: ingest and the Sent copy do today; WP5's raise batch and WP7's
  parse store must too.

**Browser.** `plugins/mailbox/assets/mailbox_search.js`
(`window.MailboxSearch`, page side), `mailbox_search_worker.js` (a Web
Worker; `worker-src 'self'` is in the CSP), and
`mailbox_search_core.js` (pure functions: tokenizer, varint and bitmap
codecs, shard build, merge, query; loaded by the worker with
`importScripts` and by node in the WP3 gate).

- **Split.** The page holds the `mail` session: it fetches pages, opens
  each row's DEK and its `iem_search_text` field (the session and
  `VaultCrypto` directly: `JoinerySealed.open` keeps every plaintext it opens
  for the tab's life, which would hold the whole mailbox), inflates `gz:`, and
  posts `{id, text}` batches to the worker. A row that will not open is left
  out; a page where none opens stops the walk, so a vault that changed under
  it is retried rather than indexed as empty. It
  opens the search key once, derives the record key and posts that
  non-extractable `CryptoKey` to the worker (structured clone). The worker
  tokenizes, builds, merges, seals, reads and writes IndexedDB, and answers
  queries, so the page never stalls.
- **First build.** `meta.build_started = server_time`; the catch-up cursor
  starts at `build_started − 10 minutes`; the backfill walks `order: 'old'`
  from `build_started`, newest first. It saves after the first page (the
  newest mail is searchable in seconds) and then every 5,000 messages, with
  the cursors in the same save, so a reload, a lock or a closed tab
  resumes it. While it runs, a search answers over what is indexed and the
  list says "Indexing mail on this device: 42,000 of 105,000. Results so
  far." (`total` from the first page), and the results refresh at each
  save.
- **Notices.** The reader refreshes its results when the index reports
  progress, and a refresh searches again; so a notice comes only from a save
  or from a build that ends or fails, never from a build that did not start
  because another tab holds it (review 2026-09-27, B1). A failed build is not
  retried until a reload, an unlock or Rebuild. A search asks the worker
  first, so the tab knows whether another tab finished the build before it
  decides to build (B3). An add that changes nothing saves nothing (B2).
- **Keeping up.** On unlock and before each search, the page fetches
  `order: 'new'` from the catch-up cursor minus 10 minutes (the overlap
  covers a row whose write committed after a later one); the worker skips an
  id already in `ids` (ten minutes' worth is opened again, a handful). An
  indexed row is never indexed
  again (search text is written once per row; drafts are never indexed).
  New messages go into `tail` (kept in the worker and saved sealed). Past
  2,000 messages the worker merges the tail into the shards it touches; the
  shards, the emptied tail, `ids` and the advanced cursor are one save.
- **Query.** Tokenize; per word, open its shard (opened shards kept in the
  worker, at most 32, least recently used dropped) and take its list plus
  the tail's; intersect; numbers → ids; ascending; encode; `thread_list`
  with `device_hits` and `q`. A word the index has never seen answers
  nothing without opening a shard beyond its own.
- **Deleted mail** stays in the index; the server's scope drops it, as in
  Private. A purge never touches the index; a rebuild sheds dead entries.
- **Lock** ends the worker (R4); the next unlock starts a new one from the
  saved records.

**What it costs**, stated in `plugins/mailbox/docs/overview.md` and on the
"Remove from this browser" control: every browser that searches Fortress mail keeps a sealed
word index of it on its disk, unreadable without the mail vault. The first
search in a new browser downloads each message's search text once (a few
hundred MB for 10 GB of mail) and indexes in the background for a few
minutes.

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
  save on a Fortress alias, in `JoinerySealed.save`'s shape but with one DEK
  for the draft's life (WP4 § As built): step one the plain columns and
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
  Approve button that re-pins only after a step-up (for an account with a second factor, B50). The `mail` rotation
  hook (`plugins/mailbox/assets/mailbox-reseal.js`, `onReseal('mail')`)
  re-MACs every pin the user holds with the new session.

### R11. Rotation, devices, recovery

`plugins/mailbox/includes/bootstrap.php` registers
`VaultUnlock::clientReseal('mail', ['InboundEmailMessage',
'MailboxSearchKey'], ['plugins/mailbox/assets/mailbox-reseal.js'])`. The
rotation walk covers every row under the scope, pending rows included (they
carry a `v1.edgeseal.mail.` key), and the search key (R5). A mail vault opens
through the root, so its rotation needs only the root open: the new key takes
one `root` wrapping (WP6 Q1). The hook re-MACs relay pins (R10). The device
handoff offers `mail` automatically; the recovery-readiness ledger and the
security page card appear per vault row. A rotation re-pushes the relay map
at begin and commit.

### R12. The cards

`ProtectionLevelPicker::catalog()` gains a `SERVICE_MAIL` Fortress entry:

- Title: **Fortress**
- Protects: "Only your devices can read stored mail. A stolen database or a
  hacked server gets nothing it can open."
- Costs: "No server-side AI or server search on this domain; search runs on
  your device, and the first search in each browser takes a few minutes to
  prepare. Mail rules run only as mail arrives. Phone apps open this
  mailbox in the browser."
- Note (relay add-on off): "New mail is encrypted the moment it arrives; a
  server hacked while mail is arriving could read what arrives then."
- Note (relay add-on on): "This server never sees your mail, not even as it
  arrives. Your browser checks which key the relay seals to, pinned on first
  use. Mail rules do not run on relay-sealed mail."

- Note (the owner unlocks by passphrase, `specs/one_vault_experience.md`
  § R7): "You unlock with a passphrase, so this mail is as safe from a
  hacked server as your passphrase is hard to guess. A passkey that can
  hold a key makes it only-your-devices."

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

### WP2b. Reopen after a reload (R4a) — built 2026-09-24

- Core: `includes/VaultClientResume.php`, `logic/vault_client_resume_logic.php`
  (`requires_browser_session`, `session_write`), `assets/js/joinery-sealed.js`
  (the halves, `ready`, `pagehide` keeps them, real locks drop them, the idle
  clock across reloads). `mailbox_fortress.js` and the reader wait for
  `ready`.
- **Tests:** `tests/vault/client_resume_test.php` (`test-db`): put / get /
  drop per scope and per tab, the public keys in the answer, the refusals,
  another user in the same session gets nothing, the per-scope cap, the
  action's auth and that nothing logs a share.
- **Acceptance:** unlock the mail vault on dev, reload: the mailbox opens
  without a ceremony. Lock now, reload: it asks. Close the tab, open a new
  one: it asks.

**Ordering (owner, 2026-09-24).** `specs/one_vault_experience.md` is built
before WP3: one passkey touch opens the account key and the mail key, one
padlock state, one setup, one name ("Vault"). Until it lands, a Fortress
mailbox needs its own unlock and setup, and the strings "mail vault" / "Set
up mail vault" in WP0–WP2b change to "your vault" there.

### WP3. Search on the device (R5, revised 2026-09-27) — built and walked on dev 2026-09-27

Built and green: `fortress_search` (39), `search_index_gate.sh` (21, the
benchmark), `MailboxSearch.selfCheck()` in the browser (12: the real worker,
IndexedDB and crypto against a stand-in server — build, search, reopen
without rebuilding, catch up, two tabs, a changed key, rebuild, remove).
Migration `iem_018` stamps rows sealed before the column existed.
Walked on dev 2026-09-27 as a fresh test user (143105, mailbox
`claude-search@dev.getjoinery.com`, vault set up in the browser with a
virtual passkey): first search builds and fills in; a body word, an
attachment name and a later-arriving message each hit; a reload and a
lock/unlock search again with no rebuild; no search word appears in any
request (`device_only`). The walk found **B11** in WP2's lock teardown
(R4): `mailbox_fortress.js` loads before the deferred vault modules, so its
load-time check subscribed to nothing and a lock wiped no key, URL or row.
It now subscribes once they have run (1.8), and its `selfCheck()` pins it.

- **Cap.** `InboundEmailMessage::SEARCH_TEXT_MAX_CHARS` 8192 → 32768.
  Fortress rows already on dev keep their 8 KB text (no production users;
  nothing to backfill).
- **Schema.** `iem_search_written_time` (timestamp(6), nullable) and the
  index `iem_search_written_idx` (migration `iem_017`);
  `MailboxSearchKey` model and table. **Stop point 1** before
  `update_database`. Set the time in `sealFortressDerived`'s UPDATE (and
  leave a line in WP5 and WP7 for their writers).
- **Server.** `logic/search_entries_logic.php`,
  `logic/search_key_logic.php` (both `requires_browser_session`), the
  `device_hits` decoder and union in `MailboxService::listThreads`,
  the `thread_list` descriptor documenting
  `device_hits`. Bootstrap registers `MailboxSearchKey` for rotation.
- **Browser.** `mailbox_search_core.js`, `mailbox_search_worker.js`,
  `mailbox_search.js` per R5; the reader's search box routed through
  `MailboxSearch` when `MAILBOX_READER.fortress`; the indexing line, the
  storage-failure line, "Rebuild" and "Remove from this browser" on the
  device-search line above the results; the lock teardown.
- **Tests:**
  - `plugins/mailbox/tests/fortress_search_test.php` (`test-db`):
    `search_entries` pages both ways by `(time, id)` with no row twice and
    none skipped across a page edge; drafts excluded; another user's rows
    and Private rows never returned; a row whose search text is written
    after the cursor passed its id is returned by the next `order: 'new'`
    page; `search_key` create-only (second create refused, wrong scope
    refused, generation from `public_key`); `thread_list` with
    `device_hits` returns exactly those messages' threads within the scope,
    ignores ids outside it, unions with `q` over a Private row, 400 over
    500,000 ids; the PHP decoder reads what the JS encoder wrote (a shared
    vector file in `plugins/mailbox/tests/fixtures/`). A `mail` rotation
    re-seals the search key (extend `fortress_rotation` when WP6 lands; until
    then drive `browserResealPage` on `MailboxSearchKey` directly).
    `mailbox_search_scope` stays green.
  - `plugins/mailbox/tests/search_index_gate.sh` running
    `search_index.mjs` (node, the `timestamp_ladder_gate.sh` pattern) over
    `mailbox_search_core.js`: tokenizer cases (diacritics, case, punctuation,
    the 40-character rule, a split query word); varint and bitmap round
    trips and the smaller-wins choice; merge of a tail into shards equals a
    build from scratch; query results equal a brute-force scan over the same
    corpus. **Benchmark:** a synthetic corpus of 100,000 messages (Zipf word
    frequencies, lengths drawn to the 32 KB cap, long junk tokens mixed
    in); gzip-compressed shards + `ids` + `meta` at most **60 MB**; a
    two-word query under 50 ms in node. Print the measured numbers.
- **Self-check:** `MailboxSearch.selfCheck()` on the mailbox page.
- **Acceptance:** on dev, as user 4500: first search on a Fortress mailbox
  builds (indexing line with a count), search a word from a message body and
  from an attachment name, both hit; reload and search again without a
  rebuild (network log: one catch-up page, no backfill); send a new message
  to the alias, search a word from it, it hits; lock and unlock, no rebuild;
  report the saved size from `navigator.storage.estimate()`. Screenshots.
  If the benchmark or the dev size misses the 60 MB target, stop and report
  before tuning.

### WP4. Compose, Sent copy, drafts — built, reviewed and walked on dev 2026-09-28

**As built.** The Sent copy was already sealed to the mail key (WP1); WP4
adds the browser's half.

- **Drafts.** `mailbox/draft_save` with `fortress=1` is two calls: the first
  (no `sealed_dek`) makes the row with its clear columns only and answers
  `{draft_id, id, sealed_ad_prefix}`; the second posts `sealed_dek`,
  `public_key`, `fields` (the eleven columns in
  `MailboxDrafts::FORTRESS_DRAFT_FIELDS`, no search text: drafts are outside
  device search), `parts` and `keep`, and goes through `acceptBrowserSealed`
  in one transaction with the clear columns. Later saves are the second call
  alone. `JoinerySealed.save` is not used: it mints a DEK per call, and a
  draft keeps **one DEK for its life** (re-sealed to the current mail key each
  save), or its saved parts would stop opening. `MailboxFortress.saveDraft`
  does the sealing.
- **Draft parts.** The browser picks the part name (`draft:` or `draftinl:`
  + 24 hex), seals the bytes under the draft DEK (AD `mail:{id}:att:{part}`)
  and posts them named by part; the server checks the name, the `v1.edge.`
  prefix and that the stored length matches the declared plaintext size (so
  the size caps hold), and stores a nameless File and `ima_` row. The sealed
  manifest names parts by MIME part. `keep` is authoritative: a saved part
  the post does not list is deleted after the save commits.
- **Reopen.** `draft_get` answers `{fortress, sealed_ad_prefix, thread_key,
  sealed, parts}`; `MailboxFortress.openDraft` opens the fields and the inline
  images (object URLs) and keeps the DEK in the compose.
- **Send.** Plaintext to the server, as B10 settled. For an end-to-end source
  the browser posts `source_open` ({sender, subject, recipient, body_html,
  body_plain} as it opened them) and `MailboxSender::buildBody` quotes it with
  the one template; the source's received time is the row's own. A forward
  posts the original's parts, opened here, as uploads (inline ones keyed by
  Content-ID, rewritten to fresh ones in the quote); the file cap grows by the
  source's real part count. A Fortress draft's saved parts are opened and
  posted with the send; the Sent row is a fresh one and the draft is deleted
  after it is stored (never morphed). A draft sent from a mailbox of the
  other custody is refused; the reader starts a new draft when the From
  changes custody, leaving the old one in Drafts.
- The plaintext `saveDraft` refuses a Fortress mailbox and a Fortress draft.
  The reader offers Reply / Forward on an end-to-end message it opened, none
  on a placeholder.
- **Walked on dev** (user 143105, alias 45242): a reply with an attachment to
  the WP3 walk message autosaved as ciphertext only (request fields checked
  in the page: no body, recipient or file text), reopened from Drafts after a
  reload with its attachment, sent (addressed to the mailbox itself: dev
  sends through Mailgun), and opened in its conversation with the quote and
  the attachment; the draft and its part were gone. A forward carried the
  original's CSV. A pasted inline image saved, reopened, sent, and rendered
  in the Sent copy. Discard removed the draft and its Files. No walk word
  appears in any table or the error log. Screenshots
  `/tmp/playwright-mcp/wp4-0*.png`.
- Found and fixed on the way: `composerHtml()` cloned the editor into the
  live document, where an `<img>` with a `cid:` src starts a load the CSP
  refuses (a console error on every save with a pasted image, Private too);
  it clones into an inert document.
- **Self-review 2026-09-27** (a read-only review agent on the WP4 diff, not
  the reviewer session), all fixed:
  B1 opening a draft from Drafts kept the previous compose's key and id, so a
  save could seal one draft's words into another and drop its parts
  (`openDraft` now saves a dirty compose to its own draft, then resets);
  B2 a Standard draft sent or continued from a Fortress mailbox was taken for a
  Fortress one and its parts lost (only a browser-sealed or truly hollow draft,
  `MailboxDrafts::isHollowDraft`, is Fortress; the rest are refused);
  B3 a custody change during an in-flight first save kept the other custody's
  draft id (any custody change bumps the generation and starts a new draft);
  B4 a part removed during a save came back (`d.parts` is what the server
  answered); B5 an inline image that would not open was dropped at the next
  save (the open fails instead); B6 PHP drops files past `max_file_uploads`
  (20) with only a warning, so a big forward could leave without some
  (`upload_count` on every send, a short count refused); B7 a Fortress save
  was all-or-nothing and silent (text first, then one request per new part;
  the part limits count only the parts kept; failures are shown); B8 the
  no-module fallback did not mark a placeholder, so reply chips showed; B9 an
  unsealed draft on a mailbox now Fortress opened empty and would be
  overwritten (hollow means empty); B10 a failed send was not recorded on a
  Fortress draft. Re-walked on dev: two Fortress drafts switched back and forth
  in Drafts keep their own words and parts; one sent, the other intact.
- **Review by public-html-91, 2026-09-28** (not yet valid; server half and the
  guard change traced sound): B13 closing a compose (or opening another draft)
  bumped the compose generation, which stopped a Fortress save chain after its
  text step, so files and pasted images added since the last save were lost
  silently (the chain now finishes on its own snapshot and draft state, touches
  the screen only while its compose is open, and reports a failure even after
  close); B14 a lock left a Fortress compose, a draft opened from Drafts
  above all, on screen with its key and image bytes (a lock now saves and
  closes it, empties the fields and returns a Drafts view to the list); B15 a
  new part could repeat a part name (refused). `draftSaving` belongs to the
  open compose (a reset clears it; a stale save no longer clears a newer one).
  Re-trace 2026-09-28: B13–B15 VALID. B16 (read): a chain outlives its compose,
  so reopening the same draft while it still posted a part let two composes
  each drop the other's parts; `openDraft` now waits for that draft's chains
  (`fortressChains`). B16 traced VALID; review closed. Re-walked on dev 2026-09-28 as user 145198 (alias 45924): attach + type + close inside the autosave delay kept the attachment (B13); a lock with a Drafts-view draft open saved it (words, pasted image, attachment) and cleared the screen (B14); reopening a draft while an 8 MB part was still posting waited for it (draft_get after the part save) and a later edit kept all three parts (B16); the draft then sent with all three (upload_count).
- Not covered by an automated test: the upload path itself
  (`is_uploaded_file` cannot pass in the CLI) and a whole send; the walk
  covers both.

**As specified:**

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

**As built 2026-09-28.** Tests: `custody_change` (34, db), `fortress_level_change` (43, test-db),
`fortress_scope` 1.3 (+ the relay refusal), `protection_addons` 1.4, `sealed_serve_grant` 1.2
(see B17); the `db --changed` gate green. **Not yet walked on dev** (the acceptance below): it
needs a test admin who owns a domain of their own with Private mail, a passkey and both vaults,
which is database writes the owner approves first.

- **B12, resolved as recommended (owner's go-ahead 2026-09-28, "continue working on the next
  part").** Custody is the key's scope, not its frame: `VaultCrypto::clientCustodyScope()`
  (1.7) names the scope when it is client custody (or declared by nothing active), null
  otherwise. Every "only the browser opens this" check asks it: `SystemBase`'s field read,
  save guard, `convertRowToClientCustody` and the sealed-for-browser export;
  `VaultClientRotation::resealRows` (1.3); `InboundEmailMessage::isBrowserSealed()` and
  `unwrapDekInWindow()`; `ModelQueryExecutor` (1.2, by the row's key). Mailbox SQL names the mail
  scope (`LIKE 'v1.edgeseal.mail.%'`): `PromotedRowRepair` (1.2), the unseal pass, the server
  rotation (`bootstrap` 1.20), the raw-headers read, `EmailJobCandidates`. Found while fixing it:
  the mailbox server rotation skipped every `v1.edgeseal.` row, so a lowered row would have been
  stranded on a retired key; the generic `resealRows()` tried client-custody rows it cannot
  open; `PromotedRowRepair` would have sealed a lowered row's `v1.edge.` recipient as though it
  were plaintext; the unseal pass skipped `v1.edge.` fields; the outbound-recipient read handed
  a `v1.edge.` recipient back as plaintext. All fixed.
- **Core.** `SystemBase::convertRowToClientCustody()` (the raise, returns the DEK),
  `browserCustodyPage()` / `browserCustodyBacklog()` / `acceptBrowserCustodyChange()` (the
  lowering; with the server window open the key must open a field first; the UPDATE is
  conditional on the key read), `assertBrowserCustodyColumns()`. `VaultCustodyChange` (new):
  pages across the models `clientReseal()` registered, each row with its target vault's public
  key in standard base64; `remaining` on the first page only, and only when every model counts
  in SQL (the generic `browserCustodyBacklog()` answers null rather than read every row).
  `clientCustodyKeyLike()`: the one escaped LIKE for a scope's keys. Actions
  `vault_custody_rows`, `vault_row_custody`. `JoinerySealed.changeCustody(scope, {progress,
  reason})` (joinery-sealed.js 1.9). `docs/sealed_vault.md` § Moving rows between custodies.
- **Mail.** `MailboxFortressLevel` (new) in place of `InboundEmailMessage::convertToFortress`:
  `convertRow()` takes a Private or a Standard row (reads parts through
  `mailbox_retrieve_attachment_bytes`, so both sealed shapes and the stored raw; a raw with no
  manifest rows gets rows; a part nowhere to be had stays listed without a file), converts
  (`convertRowToClientCustody` or `sealExistingRow` to the mail vault), re-stores every part as
  shape (a) nameless, seals search text, snippet and manifest (`sealFortressDerived`, which
  stamps `iem_search_written_time`), drops the raw, one transaction per row under a row lock
  that re-checks the key; old Files and the stored raw go after the commit; the server index
  refolds the row. Deferred work `mailbox_fortress_raise` (100 rows or 64 MiB a pass; a row that
  fails is stamped `iem_fortress_move_attempt_time` and passed by for an hour; a row sealed to
  another owner's key is not taken) and `mailbox_fortress_lowered` (`settleLowered()`: the
  manifest's names back onto the ima_ rows, the Fortress-only columns cleared, a refold, all
  conditional on the key it read); the unseal pass settles first and leaves no Fortress-only
  column. Both run only as deferred work, driven by the vault client. `backlogCount()` (all, or
  `$ready`), `loweringBacklogCount()`. `InboundEmailAlias::effectiveLevelSql()` reads a legacy
  unconverted Fortress domain as Private, so it is the one level rule in SQL (the Private seal
  batch and backlog are `= 'private'`). `InboundEmailMessage::MAIL_KEY_PREFIX` / `mailKeySql()`
  (the one spelling of "on the mail key"; joinery_ai's `EmailJobCandidates` keeps its literal,
  so it does not depend on a mailbox class) and `FORTRESS_DERIVED`.
  `InboundEmailMessage::browserCustodyPage()`/`Backlog()` answer the walk in SQL.
  Action `mailbox/fortress_backlog` (counts only: all, ready, lowering, window open). Domain editor (4.3) and logic (1.2): the Fortress card (R12 copy in
  `ProtectionLevelPicker` 1.4.0, notes: arrival without the relay, passphrase accounts), the
  mail vault opened before a save that chooses Fortress (`JoinerySealed.session('mail')`, then
  `requestSubmit`), the relay refusal (below), AI switches hidden and off at Fortress, the
  window rule only for a lowering to Standard, `mailbox_fortress_receipt_render()` for both
  directions with `mailbox_fortress_level.js` (the raise card follows the count, and keeps
  following it while locked so an unlock carries on;
  the lowering is one button), the unseal card waiting for the browser's step. The mailbox page
  banner (`mailbox_reader_emit_fortress_banner`), and the vault client loaded there while
  messages remain to move back. Mailbox plugin 1.125.0.
- **The acceptance walk (done 2026-09-28).** As test user 146345 (permission 5), owning
  `claude-levels.example` (domain 37640) with one store mailbox `box@` (alias 46267); passkey and
  vaults made in the browser; two messages delivered at Standard, two at Private (each with a
  PDF and an inline image). Standard → Private sealed the two. Private → Fortress from the
  editor: the mail key was made in the save, the receipt moved all four (fields in the browser's
  format, parts nameless, raw emptied, search written). An old Standard-era message opened on
  /profile/mailbox/mailbox, its PDF downloading under its name with the exact bytes. The AI
  setting was hidden at Fortress. Fortress → Private with the receipt's button: "Moved 4 of 4",
  every key on the server's user key, names back from the manifest, the message read through
  the server window, and server search found a word again. Private → Standard unsealed all four
  with their names. Then Standard → Fortress straight (raising Standard rows), and a lowering
  from the mailbox page's banner, both clean. No console errors.

**Found while building (2026-09-28).**

- **B17 (fixed).** `sealed_serve_grant` failed on dev: its 1×1 PNG deduplicated onto a blob a
  bug-report intake had made at 00:28, since moved to cloud storage, and the test read the
  local path directly. It reads through `File::read_bytes()` now (test 1.2).
- **B18 (fixed).** The receipt's raise pass and the heartbeat's deferred work could move the
  same message at once: the second could read parts after the first deleted the old Files and
  record them missing. The card no longer moves anything (the review's S4: the deferred work is
  the one driver), and `convertRow` re-checks the key under a row lock before writing.
- **B19 (fixed, found by the walk).** The receipt's progress went into its first status line
  (the new-mail row), so a finished raise read "Every earlier message is on your device key"
  above a stale "4 messages to go". `mailbox_fortress_level.js` 1.1 writes only to the
  `#fortress-move-row` line (or a banner's `[data-fortress-text]`).
- **B20 (fixed, found by the walk, not WP5's).** Every load of the domain editor logged
  "Undefined array key readable_title" (`AdminPage.php`): `admin_header()` read the option
  unguarded, and about 77 admin pages omit it. It passes `BeginPage`'s own default now
  (AdminPage 1.3).

**Review by public-html-91 (2026-09-28).** NOT YET VALID on first read; all taken:

- **B22 (fixed).** The settle and the unseal's settle-first keyed on the `v1.edgeseal.user.`
  frame, which a server rotation rewrites to `v1.seal.`: such a row never settled, stayed
  nameless, and an unseal would write its manifest out as plaintext. The marker is now "a
  manifest on a row not on the mail key".
- **B23 (fixed).** A settle racing a re-raise could null the new Fortress row's manifest and put
  its names on the parts. The settle locks the row and writes only if the key is the one it read.
- **B24 (fixed).** A row that fails every pass was retried every beat and, 100 of them, starved
  the rest. Failures are stamped and passed by for an hour; rows sealed to a previous owner's
  key are left out of the work (they count as not moved, and the card says so).
- **B25 (fixed).** Unsealing to Standard wrote search text, snippet and manifest out in
  plaintext. The unseal and the settle clear `FORTRESS_DERIVED`.
- **B26 (fixed by S4).** The raise card said "the server log says why" when the window closed
  mid-pass, and never woke after an unlock. It now follows the count, locked or not.
- **Simplifications.** S1 `mailKeySql()` (nine literals), S2 `FORTRESS_DERIVED`, S3 the legacy
  rule inside `effectiveLevelSql()` (`fortressSql()` gone), S4 the card counts and the deferred
  work moves (`runNow`, `busy` and the retry loop gone), S5 the generic count is optional. S6's
  redirect-flag merge was not taken: it would rework the older Private receipts
  (`sealed_now` / `unsealed_now`) and their tests for four lines.
- **Found while testing the fixes.** S5 left the mail walk's total blank: `MailboxSearchKey` (also
  registered for the `mail` scope) had no count. It never leaves the mail vault, so it now lists
  nothing and counts 0 (`mailbox_search_keys_class` 1.2). Schema: `iem_fortress_move_attempt_time`
  (update_database, owner-approved). Gate 486/486; fortress_level_change 51, custody_change 34.
- **B28 (fixed, 91's re-trace).** The unseal called `settleLowered()` unguarded, so one lowered
  row whose key or manifest would not open threw out of the unseal batch, and headed every later
  pass: the domain never finished going to Standard. The settle is caught, logged and the row
  left, like a failed decrypt. Re-trace: B22–B26 and S1–S5 VALID; VALID with B28 guarded.
- **Re-walked on dev after the fixes** (a `walk_fixture.php` user, specs/dev_walk_fixtures.md):
  Standard → Private sealed two; two more at Private; Private → Fortress, the card counting while
  the vault client's background pass moved them (4 to go at 0 s, done at 16 s); lowered with the
  button, and the background settle put every name back and left no Fortress-only column.


**Ordering constraint (review of WP0–WP2, B11), built as a refusal:** `admin_mailbox_domains_fortress_refusal()`
refuses Fortress with Seal at the relay on; WP7 lifts it. Until WP7 lands,
`RelayMapExporter::sealTargetForAlias()` gives a Fortress mailbox the relay's
transport key (never the owner's server key, which would store relay mail as a
Private row). Mail is then sealed to the mail key at pull, but the relay add-on's
promise that the server never sees arriving mail does not hold. So WP5 must not
let a domain be Fortress with Seal at the relay on until WP7 is built: refuse
that combination in `admin_mailbox_domains_logic` (the relay add-on off, or WP7
first), and lift the refusal in WP7.

- Core: `convertRowToClientCustody`, `browserCustodyPage`,
  `browserCustodyBacklog`, `acceptBrowserCustodyChange` in `SystemBase`;
  `logic/vault_custody_rows_logic.php`, `logic/vault_row_custody_logic.php`;
  `JoinerySealed.changeCustody`; `VaultKey::unsealEdge` is already there.
- Mail: `InboundEmailMessage::convertToFortress` (sets
  `iem_search_written_time` in the UPDATE that writes the search text, and
  commits per row or per short batch, never one long transaction: R5's
  overlap bound), deferred work
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
- **Built 2026-09-28 (server half):** `VaultUnlock::onClientRotation()` /
  `clientRotationChanged()` (listeners run after begin and commit are stored;
  one that throws is logged and changes nothing); `VaultClientRotation` 1.4
  calls it; the mailbox bootstrap 1.21 pushes the relay map from it.
  `plugins/mailbox/tests/fortress_rotation_test.php` (20 checks): the walk
  lists the three messages (a pending-parse and a deleted one included) and
  the search key, not a mid-rotation arrival or another owner's row; commit
  refuses with one left; every DEK is unchanged and opens under the new key.
  `mailbox-reseal.js` is left to WP8: with no pins it has nothing to do, so
  WP8 adds the script and its registration together.
- **Q1 (owner, 2026-09-28: build it in WP6).** No mail vault could be
  rotated: every one made since the one-vault change opens through the root
  (`specs/implemented/one_vault_experience.md` § As built left "rotating a
  content vault that opens through the root" unbuilt and its card hidden),
  `begin()` demanded a passkey or passphrase wrapping plus recovery codes, and
  `persistWrappings()` refuses both for such a vault. Built:
  - `VaultClientCustody` 1.3: `opensThroughRoot($vault)` (a live `root`
    wrapping on the key in use) and `throughRootVaults($user_id)`.
  - `VaultClientRotation::begin()`: a vault that opens through the root takes
    exactly one `root` wrapping and nothing else; any other set is refused.
  - `vault-keyring.js` 1.6: `rotationPlan(scope, st, secret, rootSession)`
    shows a short cost screen for such a vault (no codes, no taps; linked
    computers re-link) and wraps the new secret under `rootSession.scopeKek(scope)`;
    `pendingStatus()` exported.
  - `joinery-sealed.js` 1.10: `resealScope()` opens the root first for such a
    vault, shows no recovery codes, and finishes a stopped rotation by opening
    the pending key through the root (`openThroughRoot(root, scope, pendingStatus(st))`).
  - Security page: a **Vault Keys** panel lists each vault that opens through
    the root with "Rotate this key" / "Finish rotating this key", using the
    existing `data-vault-rotate` handler (`security_logic` supplies
    `through_root_vaults`).
  - `fortress_rotation_test.php` (34 checks): the root-vault case refuses its
    own unlockers, a root wrapping beside a recovery code, two root wrappings
    and an empty set, stores nothing on refusal, lists the pending root
    wrapping apart for the resume, and after commit opens by the new root
    wrapping alone.
  - `docs/sealed_vault.md` § Rotating a client-custody key describes both
    kinds and the `onClientRotation` listener.
- **B30 (found on the walk, fixed):** the admin menu's Mailbox page
  (`admin_mailbox_reader.php`) never passed `fortress` to the reader mount, so
  a Fortress owner reading there saw every message as a sealed placeholder with
  no way to unlock. It now mounts the Fortress module like the member page
  (1.6).
- **Review (public-html-a5, 2026-09-28):** the five questions traced sound
  (the two through-root predicates cannot disagree; the current/pending
  wrapping lists are strictly apart, the only guard since both generations'
  root wrappings share KEK and AD; retiring a legacy vault's own unlockers is
  the consistent end state; no leak on the admin reader). Findings, all
  closed:
  - **B31 (fixed):** the old key was retired with no proof the one stored
    `root` wrapping opens the new key. `rotationPlan` now unwraps its blob and
    compares it with the new secret before posting, and `resealScope` runs
    `proveThroughRoot()` after begin and before the walk: it re-reads the
    status, opens the pending wrapping through the root as the next page would,
    and round-trips a random value sealed to the new public key. The walk and
    the commit run only after it passes. That second check can fail only if
    the stored copy differs from the posted one. It would then leave the
    rotation pending with nothing moved, and the page says to report it.
  - **B32 (fixed):** on the admin reader, another person's Fortress rows said
    "Unlock your vault" or "could not be opened on this device". The service
    marks them `sealed.foreign` (`MailboxService` 1.48); `mailbox_fortress.js`
    1.9.2 shows "Only the mailbox owner's devices can open it" and raises no
    unlock banner for them. `fortress_reader_api_test` 1.3 checks the flag.
  - **B33 (WP7):** see WP7.
  - **S1 (done):** the Vault Keys panel and the rotate handler no longer
    depend on a recovery card being on the page. **S2 (done):** the cost
    screen of a vault that still has passkeys or codes of its own says those
    stop opening it. **S3 (done):** `persistWrappings` asks `opensThroughRoot()`.
    **S4 (not done):** putting the generation into a root wrapping's AD. The
    strict list split already guards this, and a second AD scheme would be
    one more thing to keep in step.
- **B34 (found on the re-walk, fixed):** after a rotation the mailbox showed
  the mail vault locked (one click reopened it through the root, no tap):
  `resealScope` locked both keys at the end. A committed rotation now adopts
  the new key's session in the old one's place, resume halves and all.
- **Re-walk after the fixes (fixture 147789, domain 38098):** two rotations
  (generations 2 and 3). The recorded calls show `vault_client_status` (the
  proof) between begin and the walk, and the mailbox read all three messages
  on the next page with no click. The foreign-row notes were checked in the
  browser with `MailboxFortress.openList`/`openThread`; `selfCheck` passes.
- **Walk, 2026-09-28 (dev, fixture user 147432, domain 37983):** raised the
  domain to Fortress from the editor; the mail vault was made through the root
  (one `root` wrapping). Delivered three messages. Security page → Vault Keys →
  Mail vault → Rotate this key: the dialog asked for nothing, and the status
  read "Done. The vault is on its new key (generation 2)". In the database: the
  mail vault on generation 2 with one live `root:2` wrapping, all three messages
  on generation 2. The mailbox listed and opened all three (body and PDF). A
  device linked at `/profile/devices_link` with "Let this device open my mail
  vault" showed **Mail vault** in its held vaults on the Security page (then
  unlinked). Screenshots: `/tmp/playwright-mcp/wp6-rotate-dialog.png`,
  `wp6-list-after-rotation.png`, `wp6-message-after-rotation.png`,
  `wp6-device-row.png`. Not walked: finishing a rotation that stopped part way
  (the server half is in the test; the browser half is the three-line branch
  above). `db --changed` 490/490.

### WP7. The relay path

- Go: `sealEdge`, `key_kind: 'client'` + `key_scope` in `routingEntry`, the
  `.meta` fields, `sealer_test.go` on the shared vector, `roundtrip_test.sh`.
  `build.sh`; **stop point 3** before the binary leaves the tree.
- PHP: `RelayMapExporter` per R9, `RelaySpoolConsumer::ingestOne` storing the
  client pending row, `ownerByPublicKey` over client vaults,
  `DeferredIngest` skipping browser-sealed rows, `mailbox/fortress_parse_store`
  (multipart; `classifySpam` from posted headers; sets
  `iem_search_written_time` with the search text and commits per message:
  R5's overlap bound), the pending banner and
  drain in `mailbox_fortress.js`, `mailbox_mime.js` with its gate.
- **B33 (from the WP6 review):** once the map names the `mail` key, a commit
  must not retire the old key while the relay still seals to it; mail sealed
  to the retired key would be unreadable for ever. The rotation's commit
  refuses while a relay is active and the last map it accepted is not the
  current one: `RelayMapSync` records the pushed hash, and commit compares it
  and does not push. The begin listener stays logged-only.
- **B35 (found at WP7 start):** a relay running an older program reads a
  `client` entry as any other key: it seals the whole message in the old
  `v1.seal.` form and stamps `key_kind: client`, which nobody can open. So
  the exporter names the mail key only to a relay whose reported
  `relay_version` is at least the version that ships `sealEdge`
  (`RELAY_VERSION` bumps with this package); any other relay keeps the
  transport key, as today. The pull holds (never stores, never acks) a
  `client` entry that is not `v1.edge.` + a `v1.edgeseal.mail.` DEK.
- **B36 (found at WP7 start):** the relay's Joinery Direct preflight hands a
  sender the entry's public key, and a Direct sender seals with
  `crypto_box_seal`, which the browser cannot open. A `client` entry answers
  the tenant's transport key instead: Direct at Fortress stays on the path
  it takes today (the box opens and seals to the mail key at pull).
- **Q1 (owner, 2026-09-28): the acceptance runs on dev with the relay
  program run locally** (the `relay-serve` listener and the sealer fed a
  message by pipe), not a relay box. The first real relay check is the
  jeremytunnell relay after release.
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
- **Acceptance (Q1):** the relay program run locally on dev; a message to a
  relay-fronted Fortress alias appears as pending, opens on unlock, and the
  parsed row reads on a second browser without re-parsing. Screenshots.
- **Built 2026-09-28.**
  - **Go:**
    - `seal.go` has `sealEdge` (plus `edgeSealWith` and `edgeFieldWith`,
      which take their randomness from the caller so the vector can fix it),
      and a public key is read in either base64 alphabet.
    - `routing.go` adds `keyKindClient` and `key_scope`; a domain entry also
      carries `key_scope` and `key_generation`.
    - `meta.go` adds `sealed_dek`, `key_scope` and `key_generation` (client
      entries only). `main.go` seals client entries with `sealEdge`.
    - B36 is in `direct_handler.go`.
    - New `edge_seal_test.go`: the shared vector byte for byte, the round
      trip, the spool-id AD, the sidecar, a client catch-all, and B36.
    - `roundtrip_test.sh` gains the PHP `openEdge` case.
    - `provision_relay.sh` `RELAY_VERSION` 3.1.
  - **PHP:**
    - `RelayVersion::sealsForBrowsers()` (B35).
    - `RelayMapExporter` 2.4: `clientSealTarget()` gives the mail vault's
      sealing key and generation. A catch-all stays on the transport key,
      since it has no single owner.
    - `RelaySpoolConsumer` 1.14 `ingestClient()`:
      - holds (never acks) an entry that is not in the browser's format;
      - holds one sealed to a key no mail vault has, current or pending;
      - finds the owner from the key when the grants changed.
    - `InboundEmailRouter` 1.45:
      - `storeRelayPending(..., $client)` inserts the row and gives it the
        relay's key in one transaction;
      - `spamFromBrowserHeaders()`.
    - `InboundEmailMessage::adoptRelayClientKey()`.
    - `DeferredIngest` 1.3 skips rows under a mail key.
    - New `MailboxFortressParse`:
      - `next()`: newest first, with `skip`;
      - `pendingCount()`;
      - `store()` / `storeParts()`: locks the row, refuses any DEK but the
        row's own, owner only, parts bounded by the message's size, the
        fields stored under the row's key (`acceptRelayParse`, B45), a second
        post is a no-op.
    - New actions `mailbox/fortress_pending` and
      `mailbox/fortress_parse_store`.
  - **B33:**
    - `VaultUnlock::onClientRotationCommit()` and
      `clientRotationCommitRefusal()`; `VaultClientRotation` 1.5 asks them
      before commit.
    - `RelayMapSync::rotationRefusal()` refuses until the relay's recorded
      hash matches the map as it stands now **and** a pull has finished since
      that push (so what it sealed to the old key is stored and on the walk).
    - Registered in the mailbox bootstrap 1.22.
  - **Browser:**
    - New `mailbox_mime.js` with `mime_parser_gate.sh` (81 checks over 8
      fixtures plus never-throws cases).
    - `mailbox_fortress.js` 1.10 `drainPending()` runs whenever the mail vault
      opens. A manifest can name its parts by number, and the reader matches
      on it.
    - A pending banner on the reader mount.
  - **Tests:** `fortress_relay_pull_test.php` (39 checks).
  - **Mail rules and contact elevation do not run on this path**: the server
    has no sender to look up. This is the R9 card sentence, plus contact
    elevation.
  - **Review (a5, 2026-09-28): NOT YET VALID, 3 must-fix and 5 should-fix,
    all taken.**
    - **B37:** PHP drops uploads past `max_file_uploads` (20). All the parts
      now go as ONE `bundle` upload, with each part at an offset. Walked with
      a 27-part message: 26 parts stored and the last one opened.
    - **B38:** the add-on is the switch, as R9 says:
      - `clientSealTarget()` checks `relay_seals_to_owner()`;
      - the domain refusal is lifted, except on a relay older than 3.1;
      - the Fortress card note follows the add-on. The pinning sentence
        waits for WP8.
    - **B39:** the parts' Files are made and committed before the row's
      transaction, so a refused store can delete them.
    - **B40:** an entry sealed to a key no vault holding the mailbox has is
      stored and acked, not held:
      - it is stored under the owner at generation 0
        (`RELAY_UNOPENABLE_GENERATION`);
      - it is never handed to a device and is on no rotation walk;
      - the reader says "arrived sealed to a key your vault does not hold …
        You can delete it".
      - Only a non-browser-format entry (B35, unreachable) and a mailbox with
        no vault holder still hold.
    - **B41:** the pull stamps `mrl_last_pull_drained_time` (new column;
      `update_database` run on dev with the owner's OK) only when the listing
      was read to its end with nothing torn, failed or left over.
      `rotationRefusal` needs drained > pushed.
    - **B42:** the key's own vault is used only if its owner still holds a
      grant on the mailbox; otherwise the entry is stored unopenable (B40).
    - **B43:** a device that skipped every remaining message makes the banner
      say they could not be opened here. Every refusal is logged with its row.
    - **B44:** a DEK that no longer matches the row answers `stale`
      (nothing written), and the drain fetches it once more.
    - **B45 (found while fixing B40):** the lowering walk and its count skip
      rows still waiting to be parsed. The parse is stored under the row's
      own key through `InboundEmailMessage::acceptRelayParse()`, not
      `acceptBrowserSealed()`, which would re-derive the scope from the
      mailbox's current level and refuse the parse forever after a lowering.
    - `fortress_relay_pull_test` 1.1 has 51 checks.
  - **Re-trace (a5, 2026-09-28): B37–B45 VALID; B46 from B45, fixed.**
    - **B46:** with the waiting rows left out of the lowering count, a
      lowered mailbox whose only mail-key rows were still waiting loaded no
      Fortress client. So nothing parsed them, and the receipt read done.
      The fix:
      - `mailbox_reader_fortress_visible()` also counts
        `MailboxFortressParse::pendingCount()`;
      - the lowering receipt has its own "waiting to be opened on your
        device" row, and does not read "every message is back" while any
        wait;
      - the server unseal waits for them too.
      The walk's page still leaves them out. Test 1.2 has 53 checks.
  - **a5 verdict 2026-09-28: VALID.** Two notes for the release:
    - `mrl_last_pull_drained_time` is a new column, so every node needs
      `update_database` before a mail rotation commit is tried there.
    - A Fortress domain takes the add-on only once its relay reports 3.2
      (3.1 until WP8 moved it);
      until then the transport path is the fallback (B35). The first real
      relay check (jeremytunnell after the release, Q1) should include one
      mail rotation commit with the relay live.
  - **Walked on dev 2026-09-28** (fixture 148539, domain 38333, via the new
    `walk_fixture.php deliver-relay`, which runs the built Go sealer on the
    message and stores the result with `ingestOne`):
    - two messages stored pending, showing only `v1.edge.` and a
      `v1.edgeseal.mail.` key in psql;
    - the mailbox page drained them on load (pending, store, pending, store,
      pending), each with its inline image (part 1.2) and PDF (part 2)
      sealed;
    - the list and thread read, and the PDF download is the original bytes;
    - device search found one by a word in its body;
    - a reload parsed nothing again;
    - a third message with the vault locked showed the banner and "Waiting
      to be opened on this device"; a passkey unlock drained it, and the
      banner went.

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
- **Built 2026-09-28.**
  - **Go:**
    - New `relay_seal_target.go`: `GET /relay/seal-target`; it answers only
      a storing recipient of the asking tenant, and anything else gets the
      same 404.
    - New `relay_seal_target_test.go`: canonical bytes, verify, a changed
      byte fails, the prefix binds, and it refuses another tenant, a
      forward-only address and an unknown one.
    - `RELAY_VERSION` 3.2, and `RelayVersion::SEALS_FOR_BROWSERS` moves to
      3.2 with it. No relay took 3.1, so Fortress sealing at the relay
      never runs on a relay that cannot answer the statement.
  - **PHP:**
    - `RelayClient` 1.1 `sealTarget()` returns the raw body.
    - New `MailboxRelayPin`: `mailboxesToCheck`, `sealTarget` (the body
      byte for byte, plus the TLS-pinned relay identity and the stored pin),
      `setPin`, `changesIdentity`, `pins`.
    - New actions `mailbox/relay_seal_target`, `mailbox/relay_pin_set` (a
      recent step-up only when the identity changes; a first pin and a
      rotation's new MAC need none) and `mailbox/relay_pins`.
    - The reader config carries `relayPinMailboxes`.
    - The Fortress card's add-on note gains "Your browser checks which key
      the relay seals to, pinned on first use."
    - The pin column `iea_relay_identity_pin` was already there (WP0), so
      there was no schema change.
  - **The pin MAC input:** `joinery-relay-pin:v1\n{alias_id}\n{relay
    identity key}`, under HKDF-SHA256(the vault secret as the browser holds
    it, empty salt, info `sealed-vault:pin`).
  - **Browser:**
    - `vault-crypto.js` 1.3: `macFromSecret`, `verifyEd25519`,
      `ed25519Supported`.
    - `vault-keyring.js` 1.7: `session.mac()`.
    - `mailbox_fortress.js` 1.11:
      - `checkRelayPins()` runs when the mail vault opens;
      - `judgeSealTarget()` gives one of pin, identity (approvable only when
        the new relay's own statement is signed and names this key),
        signature, key or unreadable;
      - the alarm dialog shows both fingerprints;
      - Trust the new relay does the step-up, then re-pins;
      - the selfCheck runs the shared vector.
    - New `mailbox-reseal.js` re-MACs every pin on a mail rotation. It is
      registered through `clientReseal('mail', …, scripts)`.
  - **Shared vector:** new `plugins/mailbox/tests/fixtures/relay_pin_vector.json`,
    made by PHP. The browser's MAC and Ed25519 checks reproduce it: the six
    selfCheck checks pass under Node in `device_ai_drain`.
  - **Tests:** new `fortress_relay_pin_test.php` (21 checks). The step-up
    gate in `relay_pin_set_logic` is the same two lines as
    `vault_client_rotate_begin` and has no test of its own.
  - **Walked on dev 2026-09-28** (fixture 149192, domain 38556, alias 47173).
    Dev has no relay, so the browser intercepted `relay_seal_target` and
    answered with statements signed by two throwaway relay keys, A and B.
    Everything else was real.
    - The first check pinned A (`relay_pin_set` 200, the pin stored).
    - A reload checked A against the stored pin silently.
    - Relay B raised the alarm (identity, both fingerprints, Trust
      offered). Trust re-pinned to B: the passkey check at unlock had just
      happened, so no step-up was asked.
    - A's statement with another key, and a pin carrying a MAC this vault
      did not make, both raised the alarm with no Trust button.
  - **Review (public-html-91, 2026-09-28): NOT YET VALID.**
    - **B47 (traced, a bypass):** the accepted keys came from
      `VaultKeyring.status()`, which is the server's word. A hacked server
      could report a pending key of its own; the exporter would push it to
      the relay, and the honest relay would sign for it without any alarm.
      Fix:
      - `VaultCrypto.publicKeyFromSecret()` and `session.derivedPublicKey()`
        work the key out from the held secret;
      - `acceptedKeys()` takes the current key from the open mail session;
      - a pending key counts only once opened through the root from its
        `root` wrapping. With the root shut, the check waits for a later page.
    - **B48:** a pin re-made under the rotation's new key before the commit
      raised the "pin" alarm on the user's other devices. The pin's MAC is
      now accepted under the current or the proven pending session.
    - **B49:** a deleted server pin let a fake relay answer be taken as a
      first use. Each device now keeps a local record of the relay it
      pinned: a missing server pin is judged against it, is restored
      silently for the same relay, and raises the alarm for any other.
      - Taken: the deleted-pin half. A different relay then raises the
        alarm; Trust needs the click, and the step-up only where B51 says.
      - Not taken: alarming when the server drops a mailbox from the list.
        A mailbox leaves the list legitimately too (add-on off, level
        lowered), so this residual stays: a hacked server can withhold the
        check.
      - A new device still pins on first use.
    - **B50 (owner, 2026-09-29: accepted):** the step-up on Trust exists
      only for accounts with a second factor, as with every vault step-up
      (rotation begin, adding an unlocker). On an account without one, Trust
      re-pins after the click alone; adding a passkey is what protects that
      account, here and everywhere else. The dialog does not say so.
    - **B51 (91, re-trace; wording):** Trust asks for the step-up only when
      the server still holds the old pin (`changesIdentity`). On the
      deleted-pin path it re-pins after the click alone, which is not
      silent. Against a hacked server the step-up is presence, not
      authority: the same server checks the assertion. What protects the
      pin is the MAC and this device's own record, and the step-up guards
      against a slip, not an attacker.
    - **Re-trace (91): B47, B48, B49 (the deleted-pin half) VALID by trace**
      (cases A–L: a server-reported key, pending or current, never counts;
      an unopenable pending key holds the check; a pin under either the
      current or the proven pending key passes). **WP8 VALID for commit.**
    - 91 confirmed:
      - the judge's ordering, and the first-use identity (the born relay's
        report and the signing key are the same key; check this once on a
        real relay);
      - `ctx.newSession` is always present;
      - no false alarm right after the add-on goes on (a synchronous push;
        a failed push is a true alarm);
      - the tenant check and the signed bytes.
    - selfCheck 9 pin checks pass under Node.
    - The re-walk passed three cases: the stored pin checked silently
      against the derived key; a deleted pin with relay A raised the alarm;
      a deleted pin with relay B was restored silently.

### WP9. Docs

- `docs/sealed_vault.md` § Client-custody scopes: reopening after a reload
  (R4a: the two halves, `ready`, what ends it), the custody-change batch,
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
`mime_parser_gate.sh` + `.mjs`; `search_index_gate.sh` + `.mjs`; Go `sealer_test.go` cases. Changed:
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
field per message, indexed once per browser into a sealed word index; R5),
compose and send (plaintext to
the server for transport; the server signs in-window under the sending lock
and never stores a plaintext copy), threading, sorting and listing
(cleartext operational metadata).

**What collapses:** server-side automation over content (AI triage while
away; AI over Fortress mail is device-local or nothing); content in
notifications (none exist; none may be built); spam learning (the stateless
verdict survives); mail rules on relay-sealed mail.

**Residuals, stated honestly:** decrypted content lives in browser memory
while unlocked and on a linked phone behind its biometric gate; a sealed
word index on the disk of each browser that searched (opens only with the
mail vault); cleartext
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

## Decisions 2026-09-27 (owner)

- **Search keeps a sealed index in each browser (R5).** The design it
  replaces opened every message's search text into tab memory on each page
  load: at 10 GB (~105,000 messages) a few hundred MB downloaded, 30–60 s of
  decrypting and ~500 MB of memory per search session. Rejected
  alternatives: an encrypted index kept on the server (it sees which parts
  each search opens, and two devices must merge into one index); searching
  only recent mail by default (hides the cost, full search still slow).
  The index reuses the Private index's size rules (no text, no positions)
  and adds dense numbering, list-or-bitmap, a 40-character word limit and
  256 sealed shards.
- **Search text cap 8 KB → 32 KB.** Coverage close to Private's, which
  indexes whole bodies; the cost is paid once per browser at the first
  build.
- **The 500-thread cap is gone.** `thread_keys[]` (max 500) cut common
  words' results silently; `device_hits` carries every hit, as the Private
  index's ids do.
