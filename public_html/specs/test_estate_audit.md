# Test Estate Audit

**Status:** AUDIT COMPLETE (2026-07-16). All 13 areas surveyed; findings,
duplication map, gap map, and executor-ready work plan below. This spec is the
work plan — implement from the "Recommendations / work plan" section (P0 first).

## TL;DR

The estate is broad (102 tests, ~24k lines) and much of it is genuinely good —
the vault rotation-crash, drive blob-layer, mobile-billing, and API browser-session
suites are exemplary. But the audit found three systemic problems that matter more
than any single test:

1. **The pre-deploy gate cannot be fully trusted today.** Unknown/mistyped tiers
   fall into the `safe` batch (fail-open), a zero-match run reports PASS, five
   suites convert a mid-run crash into a green result, and one unit test
   (parse_utc_range) asserts literally nothing (all 12 checks disabled by an
   arg-order bug). Green ≠ covered. **P0 tasks T1-T7 fix this.**
2. **Whole subsystems have zero business-logic coverage** — account security,
   Stripe webhooks, server_manager, event_manager, UploadHandler, PluginManager,
   surveys, bookings — while six subsystems hold ~60% of all tests. The two most
   attack-relevant vault suites silently skip in every gate run (APCu off in CLI).
3. **The same ~11 helpers and ~6 defect patterns are copy-pasted across the
   estate** because there's no shared HTTP client, logic-call helper, or per-area
   fixture libs, and because `needs` is declared but never enforced.

Two security fixes are also product bugs, not just test bugs: a live SSRF hole
(`0.0.0.0` reaches loopback, unblocked by one of two divergent guards) and a
secret leak (a Gmail app password written to the error log and page source by a
web page living in the tests tree).

## Purpose

The test estate (102 declared tests, ~24,000 lines) grew feature-by-feature with no
overarching design. This audit surveys every test for:

1. **Gaps** — important behavior with no covering test
2. **Duplication** — tests re-covering what another test already asserts
3. **Quality** — vacuous checks, wrong tier/env declarations, hygiene problems,
   legacy patterns that predate the shared harness
4. **Efficiency** — slow patterns, oversized fixtures, redundant boots

The output is an executor-ready work plan (bottom of this spec): each item is a
discrete task with acceptance criteria.

## Method

Parallel deep-read agents, one per area, each reading every file in full and
comparing against the subsystem's source and docs. Areas:

| Area | Files | Status |
|---|---|---|
| Mailbox: inbound/storage/reader | 13 | **done** |
| Mailbox: relay/fleet/reseal/profile | 7 | **done** |
| Vault (core + plugin + fixtures lib) | 9 | **done** |
| Drive + files | 10 | **done** |
| API functional | 10 | **done** |
| Cloud storage integration | 6 | **done** |
| Integration misc (routing, errors, deletion, email-adjacent) | 9 | **done** |
| Joinery AI | 8 | **done** |
| Calendar + email estate (incl. legacy runner) | 15 | **done** |
| Store (products, tiers, mobile billing) | 8 | **done** |
| Unit / scaffold / models / schema / ab_testing | 14 | **done** |
| Infrastructure (harness, discovery, run.php, dashboard, .sh gates) | 8 | **done** |
| Estate-wide coverage-gap map | — | **done** |

## Inventory snapshot (2026-07-16)

- 102 declared tests, 0 undeclared. Tiers: safe 36, db 54, test-db 2, live 10.
- Verified baseline: safe 36/36, db 90/90 (2,094 checks), live-on-dev green.
- Largest files: ProductTester.php 1,755; SubscriptionTierTester.php 1,364;
  ServiceTests.php 1,009 (legacy email suite); routing_test.php 927;
  imap_syncer_test.php 677; mailbox_api_test.php 591.
- Non-harness infrastructure still present: `tests/email/EmailTestRunner.php` +
  `tests/email/suites/` + `tests/email/test_runner.php` + `tests/email/index.php`,
  `tests/functional/api/api_test_harness.php`, store tester classes.

## Estate-level design findings

Observable from the inventory alone (agent findings appended below as they land):

**E1. Four generations of test infrastructure coexist.**
1. Legacy email framework: `tests/email/EmailTestRunner.php` + `tests/email/suites/`
   (4 classes, ~1,750 lines) + `test_runner.php` + `index.php`, wrapped for the
   modern runner by thin `email_suite_test.php` / `email_pattern_test.php`.
2. Tester-class suites: `plugins/store/tests/products/ProductTester.php` (1,755)
   and `subscription_tiers/SubscriptionTierTester.php` (1,364) — pre-harness
   classes driven by `run.php` wrappers.
3. A second harness layer: `tests/functional/api/api_test_harness.php` on top of
   `tests/lib/harness.php` (API tests only).
4. The modern shared harness (`tests/lib/harness.php`) — the declared standard.
Consolidation direction: everything converges on generation 4; generations 1–3
either get ported or their unique helpers get promoted into `tests/lib/`.

**E2. Two competing directory taxonomies.** Type-based (`tests/unit/`,
`tests/integration/`, `tests/functional/`) and feature-based (`tests/calendar/`,
`tests/email/`, `tests/vault/`, `tests/models/`, `tests/scaffold/`,
`tests/schema/`, plus `plugins/*/tests/`). The same kind of test lands in
different places depending on when it was written (drive is under `functional/`,
calendar is top-level, cloud storage is under `integration/`). Tier/env headers
made the directories semantically meaningless — the runner ignores location.
Decide one taxonomy (feature-based matches the plugin convention) and note that
moves are mechanical (headers carry the metadata).

**E3. Naming inconsistency.** Two files use the `test_*.php` prefix
(`tests/models/test_model_tester.php`, `tests/schema/test_index_management.php`)
against the `*_test.php` suffix used by the other 100. Discovery accepts both,
but grep/filter ergonomics suffer.

**E4. Tier distribution is bottom-light.** safe 36 / db 54 / test-db 2 / live 10.
The db tier is the center of gravity; the safe (pre-deploy, prod-runnable) gate
covers only about a third of the estate. Where a db test's DB use is incidental
(fixture user only), converting to safe strengthens the pre-deploy gate.

**E5. Hand-rolled cross-cutting helpers.** At least three suites independently
implement: an HTTP client with cookie jar + CSRF handling, a
"call a logic function as if POSTed" helper, and positional-arg parsing that
must skip runner flags (the `--json`-as-base_url bug found 2026-07-16 in
profile_mailbox). These belong in `tests/lib/`. The audit found this is far
broader than three suites — see the Duplication map for the full ~11-helper list.

**E6. The safe/db gate does not fail closed, and can pass while running nothing.**
Confirmed by the infrastructure audit: an unknown/mistyped `tier` falls into the
`safe` batch (fail-open for blast radius), and a run matching zero tests exits
`RESULT: PASS`. Together these mean the pre-deploy gate — the thing the whole
estate exists to be — can be green while executing a live-effect test in the wrong
batch, or while executing nothing at all. These are the two highest-leverage fixes
in the report because they undermine trust in every other green result.

**E7. Green is not the same as covered.** The single most repeated finding across
all 13 areas is that a passing suite frequently proves less than it appears to:
crash-to-pass catches (5 files), whole security surfaces that skip in the gate
(vault APCu, drive node-gate), tautological checks (parse_utc_range asserts
literally nothing — all 12 checks disabled by an arg-order bug), and tests that
assert a value they set themselves or fabricate the artifact they then verify
(ProductTester's paid-order). The estate's real coverage is materially lower than
its pass count implies.

## Per-area findings

### Mailbox: relay / fleet / reseal / profile

| File | Tier/env | Grade | Verdict |
|---|---|---|---|
| relay_fix_pack_test.php | db/dev | A- | Real regression pins; contentHash section now redundant with relay_fleet |
| relay_fleet_test.php | db/dev | B | Fleet-allocation checks real; exporter section is shape-only (empty fragment — none of RelayMapExporter's routing logic runs) |
| relay_outbound_inbound_only_test.php | db/dev | A | Pure, injectable fakes; **tier over-declared** — belongs in `safe` |
| mailbox_reseal_test.php | db/dev | A | Strongest file in area; every check a security/data-loss property |
| profile_mailbox_test.php | live/dev | B- | Hardcoded URL/IP; asserts a *copied* attachment rule not the production logic; raw-SQL fixtures; only 5/26 checks actually live |
| inbound_forwarding_relay_test.php | safe/dev | B+ | Honest; no section()s; reflection-pokes Globalvars instead of harness_set_setting_mem (not restored) |
| mailbox_api_test.php | db/dev | B | Best e2e coverage in area, but two real defects (below) |

**Defects found:**
- **D1 (crash-to-pass).** `mailbox_api_test.php:554-556` catches `Exception` into an
  undefined `$failed++` — an exception mid-suite prints a trace, cleanup runs,
  `harness_finish()` reports failed=0, and the runner records the crashed suite
  GREEN. Fix: `check(false, ...)` and catch `\Throwable` (the pattern
  relay_fix_pack_test.php:49-51 already uses).
- **D2 (mis-declared tier).** mailbox_api_test sends a real email through the
  configured email service on every run (its own header admits it, lines 44-49)
  but is declared tier `db` — so the pre-deploy `run.php db` gate sends real
  mail. Belongs in `live` (or split the send round-trip out).

**Duplication:**
- profile_mailbox vs mailbox_api: same scope semantics asserted twice (in-process
  and HTTP) AND near-verbatim copies of `preClean`/`makeUser`/`insertMsg`/`makeAlias`
  (profile_mailbox_test.php:128-194 ≡ mailbox_api_test.php:80-198); a third copy
  lives in mailbox_reader_test. profile_mailbox's unique value is only
  canCompose + attachment rule + anonymous-403.
- Provider capability inventory (`X instanceof RawMessageRelay`) pinned in both
  relay_outbound_inbound_only_test.php:93-94 and inbound_forwarding_relay_test.php:87-89.
- contentHash determinism pinned in both relay_fix_pack:55-65 and relay_fleet:113-115.
- Local re-implementations of harness facilities: relay_fleet's private `$cleanup`
  array ≡ `harness_register_row`; inbound_forwarding_relay's Globalvars reflection
  ≡ `harness_set_setting_mem`.

**Top gaps** (Go merge_test.go/sealer_test.go own the shard-side claim/fragment
validation — not gaps):
1. SECURITY — `FleetService::claimDomain`/`verifyClaim` (uniqueness throw, maxDomains
   quota, wrong-slot claim, `hash_equals` TXT match) untested; `dns_get_record`
   is un-injectable, blocking the TXT path. FleetService.php:189-264.
2. SECURITY — `FleetService::enroll` (entitlement refusal, WG/SSH key-format
   validation, idempotent re-enroll) and the whole `fleet_*_logic.php`
   authorization layer untested.
3. SECURITY — `limits.go` **enforcement** has no test at all (`spoolQuotaExceeded`,
   `forwardAllowed` hour-rollover); merge_test.go only proves stamping.
4. SECURITY — `RelaySsh` command construction (never-root login, escaping, no
   `--remove-source-files`) untested; static `exec` blocks seams.
5. DATA LOSS — `RelayMapSync::push()` on-error must-not-touch-hash invariant
   (RelayMapSync.php:113-145) and `RelaySpoolConsumer` copy-before-ack ordering
   untestable without an SSH seam.
6. CORRECTNESS — exporter routing (Fortress→user-key seal target, catch-all,
   IMAP exclusion, SRS gating) and `FleetReconcile` grace/suspend/reactivate
   untested.

**Area recommendations (prioritized):**
1. Fix D1 now (one line). 2. Re-tier mailbox_api_test to `live` (D2).
3. New FleetService security suite + injectable DNS seam.
4. New `limits_test.go` (pure t.TempDir() style — cheapest high-value add).
5. Extend relay_fleet exporter section with real routing fixtures.
6. Consolidate mailbox fixture factory (3 copies → one shared helper); shrink
   profile_mailbox to its unique checks, driving `profile_attachment_logic`
   itself instead of a copied closure.
7. RelaySsh string-construction test (safe tier) + injectable seam for push/consumer invariants.
8. Housekeeping: relay_outbound → tier safe; sections + mem-setting helper in
   inbound_forwarding_relay; drop redundant contentHash section; single home for
   dev URL/IP defaults.

### Drive + files

| File | Tier/env | Grade | Verdict |
|---|---|---|---|
| changes_test.php | db/dev | B | Real feed coverage; **destructively deletes real dev change-log rows** |
| drive_crypto_gate.sh (+mjs) | safe/any | A- | Strongest crypto artifact (AAD transplant + chunk-reorder defenses); **skip-as-pass when node absent** |
| encryption_test.php | db/dev | A- | Best server-side security coverage; 4 vacuous `check(true,…)` — one gates ~35 security checks |
| fix_pack_test.php | db/dev | A- | Tests underlying D1–D6 behavior, not just pins; concurrency claims tested only sequentially |
| folders_test.php | db/dev | B+ | Clean, but zero negative-authorization checks |
| sharing_test.php | db/dev | B | Grant matrix solid; links minted via model — logic layer dark; brittle copy-string asserts |
| upload_api_test.php | db/dev | A- | Real transport + boundaries; no cross-user token negatives |
| versions_test.php | db/dev | B- | Thin, model-only; near-tautological usage check |
| blob_layer_test.php | db/dev | A | Strongest file: disk-level, adversarial, falsifiable |
| signed_urls_test.php | db/dev | B+ | Good tamper/expiry; size-swap check unfalsifiable on a text fixture |

**Defects found:**
- **D3 (destructive test).** `changes_test.php:127` permanently DELETEs up to 3 of
  the oldest real `fch_file_changes` rows on dev — no transaction, no restore.
  fix_pack_test.php:270-277 does the identical simulation correctly inside a
  rolled-back transaction; port that technique.
- **D4 (skip-as-pass).** `drive_crypto_gate.sh:17-20` exits 0 when `node` is
  missing — the whole client-crypto surface silently reads green on a node-less
  box (gates are exit-code-only).
- **D5 (vacuous checks).** `encryption_test.php:287,342,358,511` use
  `check(true, …)` where `harness_skip()` exists; :287 gates the entire
  ~35-check upload-pipeline security block.
- **D6 (unfalsifiable check).** `signed_urls_test.php:110-112` size-key-swap
  check can't detect the bug it targets (text fixture has no thumb variant —
  404s either way). Needs an image fixture.

**Duplication:** tier-enrollment raw INSERT ×4 (upload_api:64, versions:53,
encryption:289, fix_pack:66 → `harness_enroll_tier()`); chunk-PUT helper ×2;
drive-fixture teardown blocks ×5 with *inconsistent* fga cleanup; session-stub
classes ×2; anonymous-curl helpers ×2; change-feed reset asserted twice (keep
fix_pack's transactional one).

**Top gaps:**
1. SECURITY — share-link mint/revoke authorization completely dark:
   `drive_link_create_logic.php`/`drive_link_revoke_logic.php` have zero tests
   and `FileShareLink::mint` has no auth check (file_share_links_class.php:49) —
   the logic layer is the only gate and it's untested. Most dangerous drive escalation.
2. SECURITY — version list/restore authorization dark (`drive_versions_logic`,
   `drive_version_restore_logic` untested).
3. SECURITY — no outsider/viewer negatives for rename/trash/restore/delete-forever;
   no cross-user chunk-token binding test; no cross-file signed-URL replay test.
4. DATA LOSS — concurrency never tested: D4 quota advisory lock and D6 chunk-append
   lock exercised only sequentially; concurrent save_new_content untested.
5. DATA LOSS — version prune and trash purge asserted at row level only; blob
   refcount release/reclaim (leak) and dedup-shared blob survival (loss) unverified.
6. CORRECTNESS — drive_list trash/starred/shared-with-me views; depth-cap on move;
   file name collisions; stale-upload purge.

**Area recommendations (prioritized):** 1. New `drive_link_auth_test.php` (top
security gap). 2. Negative-authz section (outsider+viewer across all mutating
actions + foreign upload token). 3. Fix D4/D5/D6 unfalsifiable/skip defects.
4. Fix D3 destructive delete. 5. Concurrency tests for the two advisory locks.
6. Extend versions through logic layer down to blob refcounts. 7. Consolidate
helpers (enrollment, chunk PUT, fixture registration, session stub) into tests/lib.

### API functional (+ ab_testing)

| File | Tier/env | Grade | Verdict |
|---|---|---|---|
| api_test_harness.php | (lib) | — | Clean split from tests/lib; no curl-error handling; hardcoded dev URL/IP; boot wipes ALL api_auth rows |
| ajax_migration_actions_test.php | db/dev | B+ | Strong intent pins; catch(Throwable)+harness_finish() = crash-to-pass; one check(true,…) |
| app_platform_test.php | db/dev | A- | Absence checks properly anchored by positive twins (2026-07-16 fix held); bare exit(1) at :98 skips finally |
| browser_session_test.php | db/dev | A | Every positive has DB/behavior proof; exemplary |
| crud_authorization_test.php | db/dev | A- | Best coverage-per-request; tautological self-defined-probe checks :198-206; crash-to-pass catch :401-403 |
| guest_credential_test.php | db/dev | A | Cache-HIT section most operationally aware test in area; one leaked tempfile :213 |
| idempotency_test.php | db/dev | A- | Out-of-band mutation proof = strongest replay form; crash-to-pass catch :142-144 |
| member_screens_test.php | db/dev | A- | Owner-scoping both-ways; denial checks don't pin status (a 500 passes as "denied") |
| session_keys_test.php | db/dev | A- | Most complete lifecycle suite; non-monotonic apk_permission pins excellent; crash-to-pass catch :342-344 |
| ab_testing_test.php | db/dev | B+ | Clever in-process engineering; leaks vse_visitor_events rows every run; tests 11/15 near-vacuous |

**Defects found:**
- **D7 (crash-to-pass, ×4).** Same class as mailbox D1: `catch (Exception) { $failed++; }`
  with `$failed` an undefined local in crud_authorization:401, idempotency:142,
  session_keys:342; ajax_migration:233 catches Throwable then calls
  harness_finish() → green contract on a crashed suite. Fix all with
  `check(false, 'unhandled', $e->getMessage())` catching `\Throwable`, or no
  catch at all (harness shutdown reporter handles it correctly).
- **D8 (silent network failure).** `api_request()` ignores `curl_exec() === false`
  (api_test_harness.php:96-99) — connection failure reads as "status 0" with no
  curl_error(); defect replicated in every per-file curl helper.
- **D9 (fixture leaks).** guest_credential:213 orphan tempfile; ab_testing
  leaks visitor-event rows (finally deletes only variants/test/page);
  app_platform exit(1) path skips settings restore + jar cleanup.
- **D10 (tautologies).** crud_authorization:198-206 asserts static props of a
  probe class defined in the test file; ab_testing test 15 asserts its own raw
  SQL UPDATEs worked (tests PostgreSQL, not platform code).

**Duplication:** cookie-jar curl helper ×4 (+2 raw curls in mailbox_api); jar
CSRF scraper ×2; auth-login+register-key helper ×3; web-form login ×2; CSRF
header builder ×2; failed-auth log cleanup SQL ×3. ~150 lines deletable into
api_test_harness.php. Anonymous-keyless-400 shape pinned by four suites (keep
one canonical home). Management-boundary split across two suites is deliberate
and cross-referenced — keep.

**Top gaps** (grounded in docs/api.md + api/apiv1.php):
1. SECURITY — CRUD collection **filter/sort injection**: leftover query params
   pass as Multi filter options and sort columns (apiv1.php:597-607); nothing
   tests whether a non-staff caller can widen owner scope or inject a sort
   column. Highest-value missing test in the area.
2. SECURITY — `management/backups/fetch` path traversal (`path=../…`) never probed.
3. SECURITY — management owner-floor negatives: perm-5 machine key → 403;
   superadmin apk_permission=1 orthogonality; "auth block may tighten but not
   loosen" descriptor rule.
4. SECURITY — machine-key edge states (inactive, future start_time, expired,
   IP-restriction mismatch) never exercised over HTTP.
5. SECURITY — general 1,000/hr request rate limiter untested (only api_auth
   bucket covered).
6. CORRECTNESS — **DELETE verb never issued anywhere in the estate** (grep: zero
   hits); apk_permission 3-vs-4 delete capability untested.
7. CORRECTNESS — idempotency residue: in-progress 409, stored-error replay,
   guest header-ignore, same-user cross-scope isolation.
8. CORRECTNESS — DescriptorValidator 422 shape/coercion/enum systematic coverage;
   form endpoint faces; login-as via browser credential; CORS preflight.

**Area recommendations (prioritized):** 1. Kill crash-to-pass catches (4 files).
2. curl-error handling in api_request(). 3. Consolidate HTTP helpers into
api_test_harness. 4. New security sections: filter/sort injection,
backups traversal, management floors, machine-key edges, DELETE verb.
5. Derive BASE_URL/ORIGIN_IP from settings. 6. Hygiene: leaked jar, exit(1),
vse cleanup, drop tautologies, time-bound the boot limiter wipe.

### Joinery AI

| File | Tier/env | Grade | Verdict |
|---|---|---|---|
| ai_memory_test.php | db/dev | A | Model-driven, anti-oracle byte-identical neutral messages, cascade verified via raw SELECT |
| chat_cancel_test.php | db/dev | B | Deterministic cancel via stub flipping flag mid-stream (no sleeps — right pattern); two hollow sections |
| chat_encryption_test.php | db/dev | A- | Raw-SQL at-rest checks + AD-splice defense + rotation old-key-fails; env fragility |
| chat_search_conversations_test.php | db/dev | A | Oracle-resistance section exemplary; gating tested through real resolveAllowedTools |
| llm_provider_resilience_test.php | safe/any | A- | Transport-level mocking done right; only OpenAI-compatible family covered |
| joinery_ai_owner_scope_test.php | safe/dev | B- | Only 6 checks on the most security-critical read fence; products check passes vacuously on empty table |
| joinery_ai_pipeline_runner_test.php | db/dev | B+ | Model behavioral test (exact call counts); cleanup not crash-safe |
| joinery_ai_turn_activity_test.php | db/dev | B | No sleeps (throttle by construction); hardcoded owner user_id=1 |

**Defects found:**
- **D11 (hollow sections).** chat_cancel "Finalize" section performs the terminal
  write ITSELF instead of calling `ChatTurn::runAndFinalize` (:167-176 — proves
  nothing about ChatTurn); "Endpoint guards" (:190-198) asserts fixture
  properties that cannot fail and never invokes `chat_turn_action_logic`.
- **D12 (crash-unsafe cleanup).** pipeline_runner deletes rows in a trailing loop
  with no harness_register_row/defer — a fatal leaks recipes/runs/logs.
- **D13 (fixture fragility).** turn_activity hardcodes owner user 1;
  owner_scope depends on pre-existing first-two usr_users rows; chat_encryption
  :136-139 hard-fails (not skips) when `joinery_ai_local_model` is unset; two
  APCu-gated sections silently vanish without `-d apc.enable_cli=1`.

**Duplication:** LlmProviderInterface stub ×3 (8-method boilerplate — a shared
scripted FakeLlmProvider would collapse all); conversation-fixture closure ×5;
vault-row builder ×2 (belongs in existing tests/lib/vault_fixtures.php);
LOCAL/REMOTE model constants ×2. owner_scope vs chat_search = different layers,
both needed; pipeline_runner vs turn_activity = no meaningful overlap.

**Top gaps:**
1. SECURITY — **TaintGate completely untested** (documented as "the primary
   write-side defense against prompt injection"; zero grep hits in tests/).
   Worst gap in the area.
2. SECURITY — untrusted-envelope contract tested only for memory; nothing covers
   fetch_url/web_search/query_model/view_attachment wrapping, per-turn nonce
   freshness, or an embedded fake envelope-closer in attacker content.
3. SECURITY — memory-tool authorization from recipe contexts (whose memories can
   a recipe read?) and tool-list gating for remember/recall/forget untested.
4. DATA LOSS — reseal interrupted mid-way (mixed generations) untested;
   deleted-conversation purge scoping untested.
5. CORRECTNESS — RecipeRunner agent mode has ZERO direct coverage — no
   regression net for the planned recipe/chat unification; ChatRunner full-turn
   (memory injection wiring, runAndFinalize) untested; provider routing table
   unpinned; chat-side token-budget/max-iteration stops untested;
   AnthropicProvider resilience untested.

**Area recommendations (prioritized):** 1. TaintGate test (highest security
value per line in the area). 2. Untrusted-envelope conformance test across all
untrusted-content tools. 3. RecipeRunner agent-mode test = regression net for
unify work. 4. Shared FakeLlmProvider + conversation/vault fixtures in lib.
5. Fix chat_cancel hollow sections (call the real code). 6. Crash-safe cleanup
in pipeline_runner; make_user everywhere. 7. Chat-side terminal-state coverage.
8. Guard-or-skip the local-model check; runner passes apc.enable_cli=1.

### Mailbox: inbound / storage / reader (13 files)

Strong area overall — imap_syncer (A-), mailbox_reader (A), inbound_raw_storage
(A-), inbound_imap_account (A-), provider_auth (A-), authentication_results (A-)
carry real security assertions (spoof/SSRF-pin/fail-safe/plaintext-leak checks).
Weak spots concentrate in tier honesty and shared-environment disruption.

**Defects found:**
- **D14 (tier violation + shared-state mutation).** `imap_poller_test.php`
  testPollerSummary runs the REAL `PollImapAccounts` task against every enabled
  account in the dev DB — opens live IMAP connections, stamps real poll cursors,
  can ingest real mail, races the cron — while declared tier `db`. Near-vacuous
  assertion ("run does not throw"). Grade C+.
- **D15 (env-any test writes DB).** `filter_import_test.php` `--db` path writes
  `ilb_inbound_email_labels` but the file is declared tier `safe`/env `any` — no
  runtime gate; `php filter_import_test.php --db` on prod would write rows.
- **D16 (shared-directory chmod).** `inbound_raw_storage_test.php:218` chmods the
  live shared `upload_dir` to 0555 mid-test — every other process's uploads fail
  in that window; restore hardcodes 0777 regardless of prior mode.
- **D17 (registration-only proxy).** `inbound_email_mailbox_grant_test`
  testCascadeUser asserts a `del_deletion_rules` row EXISTS instead of executing
  the cascade — papers over an environment bug and can't catch a broken executor.
- "Grant parity" in `inbound_attachment_test` asserts the viewer seam, not the
  download endpoint it names (endpoint gating lives in profile_mailbox, live tier).

**Duplication:** fixture+purge boilerplate ×8 (~60 lines each → one
`mailbox_test_fixture.php`); raw-SQL makeUser ×4; mock cloud driver + factory
injection byte-identical ×2 (inbound_raw_storage ≡ raw_message_store);
MailboxViewer scope triple-covered (reader test is the superset); lean-record
ingest asserted in both inbound_raw_storage and inbound_email_attachment_storage.

**Top gaps:**
1. SECURITY — `InboundEmailRouter::processEmail()` routing decisions entirely
   untested: reject_unmatched/catch-all/store/forward selection, disabled
   alias/domain refusal, and the **alias/domain rate limits** (the abuse-control
   gate on public ingest).
2. SECURITY — SNS signature *verification* untested (only string-to-sign builder
   + URL pin); attachment admin-endpoint gating untested in the db batch.
3. DATA LOSS — `ImapIngestor::poll()` (977 lines) has no direct test — cursor
   advance, UIDVALIDITY change, Message-ID fallback; retention purge tasks untested.
4. CORRECTNESS — filter *engine* (match/apply) untested (only the Gmail parser);
   MailboxSender::send() MIME assembly only reflection-poked; forward/SRS delivery;
   charset/QP body extraction.

**Area recommendations:** 1. Fix imap_poller tier violation (scope the run to
fixture accounts or split to live tier) — highest urgency. 2. Split/drop
filter_import --db block. 3. Extract mailbox_test_fixture.php (kills 8 copies).
4. processEmail routing + rate-limit test. 5. ImapIngestor::poll() unit test
reusing FakeImapClient (move it to shared lib). 6. Restore upload_dir's original
mode, not 0777.

### Vault (core + plugin + fixtures)

One of the strongest areas — vault_rotation_crash (A, genuine two-phase crash
injection with data-readability as oracle), vault_wrappings_floor (A, every
unlocker-floor edge), vault_ceremonies (A, replay/atomicity), sealedbox (A,
full tamper/splice/truncation battery). BUT its two most attack-relevant runtime
suites silently skip in every gate run.

**Defects found:**
- **D18 (green-by-skip, security-critical).** The runner spawns tests with plain
  `php` and no `-d apc.enable_cli=1` (run.php:150), and the host CLI has
  `apc.enable_cli=0`. So `vault_unlock_window_test` (the unlock-window policy
  caps, heartbeat asymmetry, lockAll, stolen-session eviction) and
  vault_ceremonies' kill-switch section **skip in every `safe`/`db` batch** — the
  single most attack-relevant runtime component is green-by-skip. Fix: append
  `-d apc.enable_cli=1` in run.php (one line) or set it in the dev CLI php.ini.
- **D19 (vault_health is a shape test).** Grade C — asserts the return literal
  has 3 entries; none of the three detectors (/proc/swaps parse, ulimit, mmap
  mask) is exercised against controlled input. False-comfort for a
  "keys never touch disk" control.

**Duplication:** sealedbox ↔ vault_crypto re-assert the same primitives (only the
DEK-swap splice is unique — keep it); kill-switch/lockAll APCu-fabrication
technique duplicated in ceremonies + unlock_window (→ one fixture helper);
client-custody vault row built via model in one test and via **raw SQL** in
drive/encryption_test.php:31-38 (the estate's one model-bypassing vault write —
same missing fixture, two implementations). secret_box_test misfiled under
tests/integration/oauth/ (it's a general core helper).

**Top gaps:**
1. SECURITY — unlock-window + kill-switch never run in the gate (D18).
2. SECURITY — recovery-code consumption is read-then-mark with no row lock
   (VaultCeremonies.php:384-416); concurrent replay of one code can double-unlock;
   the "consumed code never unlocks again" guarantee is tested only serially.
3. SECURITY — `unlockWithPassphrase`/`unlockWithRecoveryCode` never verify
   `uev_usr_user_id === $user->key` — no cross-user assertion at either layer.
4. SECURITY — no downgrade/version-substitution probes; floor-bypass logic shells
   (remove-wrapping, passphrase-remove, two-generation refusals) untested.
5. DATA LOSS — rotation's key file never reconstructed-from (only setup's);
   regenerate-codes atomicity untested; client-custody replace_recovery untested
   (unrecoverable if botched).

**Area recommendations:** 1. Make APCu suites run in the gate (one-line run.php
change) — activates ~25 existing high-value checks. 2. Recovery-code
replay-under-concurrency test + atomic `UPDATE ... WHERE is_used=false` fix.
3. Assert vault ownership inside ceremony cores + cross-user negative.
4. `vault_fixture_client_vault()` helper (kills the raw-SQL vault write, gives
drive scope real coverage). 5. Extend rotation-crash with a second passkey
(dropped_passkeys) + key-file reconstruction. 6. New vault_enrollment_guards
test. 7. Round out secret_box (wrong-key, aesgcm branch, unknown-algo) + move it.

### Cloud storage (6 files)

Better-than-average — honest tiers, real assertions, no fabricated passes —
but grown by accretion: ~40% behavioral overlap between engine_test and
characterization_test at different seams, and the three biggest genuine risks
sit exactly where the tests aren't.

**Defects found:**
- **D20 (decorative failure knobs).** Both `cloud_offload_engine_test` (`$fail_keys`)
  and `cloud_storage_characterization_test` (`$put_should_fail`) declare a
  partial-PUT-failure injection knob and **never set it** — the engine's
  partial-push cleanup path (delete already-pushed keys on failure) is untested
  despite the mocks being built for it.
- **D21 (self-referential check).** characterization_test check 2b re-implements
  the engine's eligibility SQL *inline in the test* and asserts against its own
  copy — a regression dropping the cap in the real query still passes. Weak AND
  redundant (engine_test covers the cap through the real query).
- **D22 (pins policy without judging).** cloud_private_store:37 blesses
  "connection refused (0) ⇒ privacy gate PASSES" — a probe that never ran latches
  the store as proven-private. Arguably fail-open; canonized as correct.

**Duplication:** 4 near-identical mock drivers; 3 near-identical scratch-table
profiles; identical `rrmdir` ×3; scratch-DDL helpers ×3;
`set_enabled_mem`/`set_enabled_mem` reflection ≡ harness_set_setting_mem.
engine vs characterization overlap on 4 of 6 behavior groups (characterization's
seam is worse — private-method reflection vs public entry points).

**Top gaps:**
1. DATA LOSS — partial upload failure completely untested (D20).
2. DATA LOSS — cloud-resident visibility-flip pull-back (`FileBlob::flipVisibility`
   three-phase + re-PUT rollback) has zero coverage; blob_layer covers only the
   local dir-to-dir flip. Most intricate data-loss path in the subsystem.
3. DATA LOSS — reverse failure phases (pull/commit/delete) untestable because no
   mock can fail `get()`/`delete()`; private-blob reverse *placement* never
   asserted (a private blob pulled into the fast-serve dir = exposure-on-restore).
4. SECURITY — the serving gate is untested: private cloud row through serve.php
   (is_viewable fail ⇒ 404 not 403, stream via PHP never 302 to bucket). The suite
   tests the URL the model emits, never what the server serves.

**Area recommendations:** 1. Exercise the partial-PUT path now (knobs exist —
~4 checks). 2. Add get/delete failure injection to a shared mock; cover reverse
phases. 3. Write the cloud flipVisibility test. 4. Extract
`cloud_storage_fixtures.php` (RecordingMockDriver + ScratchTableProfile — kills
all copies). 5. **Consolidate 6 files → 4 by seam** (engine / blob-profile /
lifecycle / live-b2); delete characterization's 4 duplicated sections + the
self-referential cap check. 6. Add the serve-path security test. 7. Fix live_b2:
DB host from settings not hardcoded, harness_defer the temp plugin dir + startup
sweep, export the `-42` lock namespace as a constant.

### Integration misc (routing, errors, deletion, oauth, email-adjacent)

Mixed. Strong: deletion_rule tests (A-/A), oauth2_client (A), oauth2_state (A-),
email_security_digest (A), email_security_scan_job (A-). Weak: routing_test (C+,
half self-mirroring), error_handling (C, mostly getter tautologies),
components_manifest (C, stale migration scaffolding).

**Defects found:**
- **D23 (self-mirroring routing test).** routing_test.php's 927 lines derive
  expectations from the same filesystem the router reads (`file_exists()` decides
  200-vs-404), so a route-resolution regression that tracks the filesystem
  symmetrically can't be caught; `testPluginFiles()` iterates an empty array
  (zero checks); over-broad accept-lists (existing admin `[301,302,401,403]` vs
  nonexistent `[302,404,401,403]` share 3 codes) can't distinguish outcomes;
  silent catches turn broken models green.
- **D24 (db-tier contract violation).** error_handling_test inserts an
  `err_general_errors` row and never deletes it; email_security_scan_job cleanup
  is bottom-of-file sequential (leaks on any thrown check);
  email_validation_toggle persists the MX-check flag and restores non-deferred
  (a mid-run fatal strands the dev site with MX validation off).
- **D25 (getter tautologies).** ~30 of error_handling's 40 checks verify a
  constructor stored what it was given — testing PHP's Exception base, not
  platform behavior. components_manifest flags `PathHelper::getThemeFilePath(` as
  "legacy" though CLAUDE.md prescribes exactly that — skip-noise for correct code.

**Duplication:** routing_test re-covers admin-redirect + plugin-gating that the
functional API suites own with real credentials; oauth2_client/state duplicate the
private ok() wrapper + Guzzle mock factory + @session_start guard (→
tests/integration/oauth/fixtures/).

**Top gaps:**
1. SECURITY — `min_permission` gates NEVER tested with an authenticated session
   (no test asserts perm-0 denied `/admin/*` or perm-5 denied `/tests/*`).
2. SECURITY — path-traversal rejection is implemented (RouteHelper:253-298) but
   zero tests probe `/theme/../config/...`, `%2e%2e%2f`, dotfiles, or direct
   `/includes/*.php`. Highest-value missing routing test.
3. SECURITY — 404-vs-403 leakage on check_setting-gated routes never tested with
   the setting toggled off.
4. DATA LOSS — deletion *cascade execution* is untested — both deletion tests
   cover rule bookkeeping only; nothing asserts permanent_delete actually deletes
   children per del_action, recurses, or that `prevent` blocks. A perfectly
   registered, never-consulted rules table passes every existing check.
5. CORRECTNESS — OAuth state TTL tested only on a hand-fabricated entry (the real
   `issue()` TTL is unasserted); valid-state + failed-code-exchange branch untested.

**Area recommendations:** 1. Routing security test (traversal + sensitive-file +
404-not-403) — worth more than all 927 existing routing lines; can call
RouteHelper directly. 2. Authenticated permission-gate test. 3. Deletion-cascade
*execution* test. 4. Fix teardown hygiene in 3 tests (harness_defer). 5. Slim
routing_test to the ~450 lines that earn their keep. 6. Rewrite
components_manifest as per-component checks, drop the echo scaffolding + doctrine-
contradicting legacy scan. 7. Route-table lint test (in-process, ms).

### Store (products, tiers, mobile billing)

Split verdict. `mobile_billing_test` (A) is the house style everything should
converge on — self-provisioned fixtures, LIFO teardown, negative security cases,
49 real checks. The two big tester classes are the opposite.

**Defects found:**
- **D26 (a test that manufactures its own pass).** ProductTester's stripe_checkout
  path, when session validation fails (payment never completed), FABRICATES a paid
  Order + OrderItems itself (sets `ord_status = PAID`) and returns it — which the
  caller then "verifies" as paid. The test creates the exact artifact it asserts.
- **D27 (catastrophe → pass).** ProductTester::verifyProduct — if the product
  turns up in the **production** DB, it prints a warning and `return true`. A test
  writing the live DB should be the loudest possible failure.
- **D28 (silent green on unmet preconditions).** SubscriptionTierTester returns
  early on <3 tiers WITHOUT recording a failure → run.php emits its single passing
  check → "0 tests ran" is indistinguishable from "11 passed". Same in 3 more
  methods. testFeatureAccess has zero assertions.
- **D29 (echo-only coupon results).** ProductTester's coupon tests never reach
  `$test_results` and never compare discount to an expected total — all coupon
  behavior is invisible to the harness contract.
- **Dead code:** ~131 lines hard-dead + ~118 conditionally-unreachable in
  ProductTester (~14%); ~50 dead + broken (references nonexistent columns) in
  SubscriptionTierTester. `products_to_test_subscription.json` is orphaned — no
  subscription product is EVER carted/charged.
- **No live-key guard in SubscriptionTierTester** (ProductTester has one) — it
  creates real Stripe subscriptions with no abort. **Incomplete cleanup**: created
  products, Stripe test products, and orders are never deleted.

**Duplication:** test-mode bootstrap, DB banner, Stripe scaffolding, order
verification, session faking all copy-pasted ProductTester ↔ SubscriptionTierTester;
the two run.php wrappers are near-identical twins.

**Top gaps (money-loss first):**
1. MONEY — Stripe webhook processing completely untested (signature verify,
   duplicate-event suppression, payment_failed dunning). SubscriptionTierTester
   itself admits this. The path that keeps entitlements honest.
2. MONEY — Stripe refunds; renewal/payment-failure lifecycle (Apple/Play covered,
   Stripe not); coupon enforcement math (discount amount never asserted anywhere);
   PayPal wholly untested; checkout_type variants (only the current site mode runs).
3. SECURITY — webhook signature rejection; guest/account-creation checkout path;
   permission gating (both testers fake permission 10).

**Area recommendations:** 1. Kill the two failure→pass conversions in ProductTester
(D26/D27). 2. Fix silent-green in SubscriptionTierTester (record failures on
precondition miss) + add the live-key guard. 3. Excise ~180 dead lines; wire up
the orphaned subscription JSON. 4. **Restructure both tester classes into standard
harness tests** — the dual-mode run.php + reflection bridge exists only to preserve
an HTML report the dashboard now renders; converts ~5 contract checks into ~30-60
granular ones. 5. Shared `store_fixtures.php` (guard, make_product w/ Stripe
cleanup, make_coupon, make_tier_ladder, charge_cart returning order by ID).
6. Assert coupon money math. 7. Stripe webhook test (mobile_billing's seam
approach) — highest-value net-new store test. 8. Replace PII email in fixtures.

### Unit / scaffold / models / schema / ab_testing (14 files)

Best-in-class: formwriter_json (A), dns_resolver (A), recurrence/ics (A). But two
serious defects and thin SSRF coverage on the guard whose whole job is bypasses.

**Defects found:**
- **D30 (CRITICAL — a test asserting nothing).** `parse_utc_range_test.php` calls
  `check($label, $condition)` — arg-swapped — at all 12 sites. The harness is
  `check($condition, $label)`, so the non-empty label string is always truthy and
  every real condition is discarded into the label. **The suite is 100% green
  while asserting nothing** — worse than no test, it occupies the coverage slot.
  Fix: swap to `ok($label, $cond)` (12 sites) + add a harness guard that warns
  when $condition is a string, so this can never recur estate-wide.
- **D31 (real SSRF hole, missed by the test).** `scan_url_validate_target()`
  (dns_filtering) does NOT block `0.0.0.0/8`, `100.64/10` (CGNAT), `224/4`,
  `240/4` — the sibling `UrlSafetyValidator` blocks all of these. On Linux
  `0.0.0.0` reaches loopback: a live SSRF hole. Neither SSRF test covers it.
- **D32 (silent model drop).** models_test discovery swallows any data-class file
  that fatals on require (LibraryFunctions:1322) — a broken model just vanishes
  from the suite, no skip, no failure, the count silently shrinks. Plus ModelTester
  converts several real failure classes (varchar length, unique-constraint) to
  warns/passes, and the entire Multi-collection surface is untested (SINGLE_TESTS_ONLY).

**Duplication:** `FakeDnsBackend` copy-pasted ×4 (→ tests/lib, with
`harness_defer(clearBackend)` built in — scan_url_validate_target never clears its
backend); two SSRF guards are duplicated *product* code with divergent range
tables (that's how the 0.0.0.0 gap crept in — fix at the product layer per
build-generally principle).

**Top gaps — platform-core units with NO unit test, by load-bearing weight:**
1. `LogicResult`/`process_logic()` — every page routes through it; zero tests.
2. `SystemBase` save/validation lifecycle — only exercised generically through
   ~100 models on the test DB; a lifecycle regression shows as 100 confusing
   failures, not one pointed one.
3. `LibraryFunctions::convert_time()`/`time_shift()` — the mandated display path
   for every timestamp; no dedicated test. DST/timezone = silent-corruption land.
4. FormWriter server-side validation (rejection on submit); PathHelper/RouteHelper
   unit-level; DescriptorValidator base coercion; Multi collections.

**Area recommendations:** 1. Fix parse_utc_range immediately (D30) + harness guard.
2. Close the scan_url SSRF range gap and pin it (D31 — product fix). 3. Unify the
two SSRF guards, extend url_safety_validator with IPv6 + 169.254.169.254 + decimal/
octal + userinfo cases. 4. De-fang ModelTester's fail-to-pass paths; make model
discovery loud (one failing check per unloadable file). 5. Declare a Multi-
collection suite. 6. Add the missing core unit tests in gap-rank order. 7. Shared
FakeDnsBackend fixture.

### Calendar + email estate (15 files)

Calendar is strong (ics_import A, recurrence_nth_occurrence A, calendar_core A-).
The email directory is a pre-harness framework in decay: ~1,750 lines across
EmailTestRunner + suites/, three-quarters dead or vacuous, plus a 763-line admin
web page masquerading as a test file.

**Defects found:**
- **D33 (SECURITY — secret leak).** `tests/email/auth_analysis.php` (a 763-line
  interactive admin page with no test header, invisible to the runner)
  `error_log()`s the submitted `$_POST` — **including a Gmail app password** — and
  echoes full `$_POST` into page-source HTML comments. Direct violation of the
  secret-handling rules. Fix before any structural decision.
- **D34 (guaranteed fatal).** DeliveryTests `testDebugLogging` calls
  `$this->runner->createTestEmail()` — a method that doesn't exist (it's
  `createTestMessage`). When `email_debug_mode` is on it throws `Error` and kills
  the whole `email_suites` run; when off it returns pass early — the test passes
  exactly when its feature is disabled and crashes when enabled.
- **D35 (leaves prod broken on fatal).** ServiceTests snapshots/restores
  `stg_settings` via raw UPDATE inside `try/catch (Exception)` — a fatal `Error`
  or the 600s timeout kill skips restoration, leaving prod with
  `email_service=mailgun` + `mailgun_api_key='key-bogus...'`. testQueueOnTotalFailure
  deliberately breaks both providers for a window. Worst defect in the area for a
  `prod-verify` test.
- **D36 (live send to hardcoded external address).** email_pattern_test falls back
  to `test@example.com` when `email_test_recipient` is unset (should be
  harness_skip). Dead pattern tests commented-out prod code. ServiceTests reports
  6 unconfigured providers as `passed=true` (should be harness_skip).
- Dead files: `test_runner.php` (runs all live sends, no harness — delete),
  three-quarters of EmailTestRunner (uncalled/no-op), DeliveryTests (2 vacuous +
  1 crashing).

**Duplication:** the "email looks ready" assertion (fromTemplate → non-empty)
exists 3× (two are vacuous name-mismatches); live provider sends triplicated
across ServiceTests + email_pattern + integration/mailgun_test; calendar_core vs
native_entry both assert busy-title stripping (only native_entry's is non-vacuous).

**Top gaps:**
1. CORRECTNESS — recurring-entry *materialization* untested:
   `get_instances_for_range()` (the virtual expansion the calendar actually
   renders) incl. EXDATE/exception application has zero coverage. End-date math is
   tested; the instance stream is not.
2. CORRECTNESS — recurring instances across a DST boundary; fall-back slot
   semantics (slot_generator only asserts count>=3 for the repeated hour).
3. CORRECTNESS/SECURITY — email template escaping/XSS: nothing asserts
   user-controlled values landing in HTML bodies are escaped.
4. CORRECTNESS — bookings plugin has no tests dir at all (double-booking untested);
   CalendarEntryImporter (DB-writing import, UID dedup) untested; bounce handling;
   queue drain.

**Area recommendations:** 1. Fix the auth_analysis.php secret leak immediately
(D33). 2. **Legacy framework verdict — port two, delete the rest:** delete
test_runner.php, the uncalled EmailTestRunner methods, and DeliveryTests entirely;
port ServiceTests' read-only provider-abstraction group → safe test, its real-send/
fallback/queue → live test with harness_defer restoration (closes D35) +
harness_skip for unconfigured; port TemplateTests' subject logic → db test that
creates+registers its own fixtures; port AuthenticationTests → live DNS test; then
delete EmailTestRunner + suites/ + the wrapper. Until then, fix DeliveryTests:96
so the run stops being a guaranteed fatal. 3. auth_analysis → move to /adm/ as a
proper tool (after the secret fix) or delete. 4. Fix calendar db-test hygiene
(make_user + harness_register_row in native_entry + schedule_model). 5. Add
recurring-materialization + DST-instance + template-XSS + importer tests.

### Test infrastructure (harness / discovery / runner / dashboard / gates)

The harness is small and coherent, and its crash-detection is fundamentally sound
(exit(0) mid-test can't false-pass; a fatal after harness_finish() can't
false-fail; sentinel-forgery is resisted by last-occurrence + always-emit-last;
the SIGTERM→teardown conversion is a genuinely good design). But several
estate-wide defects live here.

**Defects found (these affect every test):**
- **D37 (tier fails OPEN).** `harness_parse_metadata` maps an unknown/mistyped
  `tier` to `safe` (harness.php:117) and does not lowercase the value. A live-tier
  test with a typo (`tier: Live`, `tier: lve`) lands in the `safe` batch and runs
  on every pre-deploy `php tests/run.php`. env doesn't save you: a mistyped
  live+dev-only mail/Stripe suite runs in the "no side effects" gate. Fix: lowercase +
  unknown tier → `live` (never batched) or refuse.
- **D38 (zero-match run passes).** A run matching zero tests exits 0 / "RESULT:
  PASS" (run.php:282). `php tests/run.php db --filter=typo` or `--only=badpath` is
  green — a real CI hazard for "the pre-deploy gate and CI entry point".
- **D39 (teardown catches Exception not Throwable).** Every teardown catch
  (harness.php:298,310,326,344) misses `Error`/`TypeError`. A TypeError in one
  teardown closure aborts remaining teardowns AND — because `finished=true` is set
  before teardown — the shutdown reporter returns early and NO contract is emitted;
  the runner then fails it as "no result contract", losing every check result and
  leaking all remaining fixtures. (Fail-safe direction, but diagnostics + cleanup lost.)
- **D40 (shell gates leave probes in production source).** The three device gates
  clean up via `trap cleanup EXIT` only. The runner's `timeout -k 5s` SIGTERM kills
  untrapped bash WITHOUT running the EXIT trap — so a 900s timeout leaves SMTP
  settings flipped to localhost, `api_min_client_versions` at 99.0.0, and the
  PHASE2_PROBE field **inside production `logic/account_edit_logic.php`**, the link
  probe in `views/notifications.php`, all on the live dev site. Plus the remote
  xcodebuild continues orphaned. (The PHP harness solves exactly this with a TERM
  trap; the gates were never given the equivalent.)
- **D41 (live tests run with no confirm).** The live-run confirmation is
  client-side JS only. A direct `POST /api/v1/action/tests_run {"tier":"live"}`
  from any superadmin session runs the whole live tier server-side with no confirm;
  a direct GET `/tests/functional/...?json=1` runs any single test — including a
  `prod-verify` test on production — with no confirm. The env gate blocks
  `dev-only` but not `prod-verify` (mutative by definition).
- **D42 (drive_crypto_gate false-green, already logged as D4).** Confirmed at the
  infra level: `exit 0` on missing node scores as PASS in the safe pre-deploy tier.
- Secondary: header parsing reads only the first 4KB; discovery fails OPEN on false
  positives (any file mentioning `@joinery-test` in its first 4KB becomes a runnable
  phantom test unless on the basename blocklist); iOS gates pass the fixture
  password in the ssh argv (the Android gate fixed this via stdin — iOS never
  retrofitted); `--filter` matches the absolute path so install-path substrings
  (`html`) match everything.

**Missing infrastructure the estate keeps hand-rolling:**
1. Shared HTTP client with cookie jar + session-login/CSRF — exists only in
   api_test_harness.php with a hardcoded base URL + origin IP.
2. Shared logic-invocation helper (`harness_call_logic()`) — hand-rolled as
   ProductTester::runAdminLogic and echoed in other suites.
3. `needs` enforcement — declared and badged but NEVER checked; an unmet need
   hard-fails (macmini) or silently passes (node). A tiny probe registry fixes both.
4. Per-area fixture libs (only vault has one); parallel safe-tier execution;
   timing report (duration_ms captured, never aggregated); run-history/trend
   storage; real CI hooks (run.php calls itself the CI entry point but nothing
   invokes it); bounded child output.

**Doc drift (docs/testing.md):** omits `--only=`/`--timeout=`, the zero-match
exit-0 behavior, the tier fail-open direction, that `needs` is unenforced, and the
runner-added contract fields.

**Area recommendations (prioritized):** 1. tier fails closed (D37). 2. exit
non-zero on zero-match (D38). 3. `trap cleanup TERM INT` in the 3 device gates
(D40). 4. Fix drive_crypto false-green (D42). 5. catch Throwable in teardown +
emit contract even when teardown crashes (D39). 6. Enforce `needs` via a probe
map. 7. Promote harness_call_logic() + shared HTTP client into tests/lib
(base URL from Globalvars). 8. Server-side confirm for live/prod-verify runs (D41).
9. Anchor the discovery marker to the header start. 10. Sync docs/testing.md.

## Duplication map

Consolidated across all areas. Same defect or helper recurring in ≥3 places is an
estate-level problem, not a per-file nit.

**Cross-cutting defect patterns (the headline finding):**

| Pattern | Where | Fix |
|---|---|---|
| **Crash-to-pass** — `catch (Exception) { $failed++; }` with undefined `$failed`, or `catch (Throwable)` then `harness_finish()` → green contract on a crashed suite | mailbox_api_test:554, crud_authorization:401, idempotency:142, session_keys:342, ajax_migration:233 (5 files) | Remove the catch (harness shutdown net handles it) or `check(false,…)` catching `\Throwable` |
| **Green-by-skip / green-by-absence** — a whole security surface silently skips or passes by absence in the gate | vault unlock-window + kill-switch (APCu off in CLI, D18); drive_crypto_gate node-absent (D4); 6 store providers `passed=true`; encryption_test :287 gates ~35 checks | Run APCu in the gate; `harness_skip` not pass; needs-probe |
| **Tautology / vacuous check** — asserts a value the test itself set, or `check(true,…)`, or `count>=0` | parse_utc_range (all 12, D30); error_handling ~30; crud_authorization:198; ab_testing 11/15; encryption_test 4×; mailgun:63; email_inline:121; calendar_core:81 | Assert real behavior or delete |
| **Non-crash-safe teardown** — cleanup in trailing code / finally, not harness_register_row/defer; leaks or strands state on a mid-run fatal | pipeline_runner, email_security_scan_job, email_validation_toggle, error_handling, native_entry, schedule_model, ServiceTests, the 3 device gates, both store testers | Register at creation; harness_defer; TERM trap for gates |
| **Tier/env dishonesty** — declared blast radius < actual | mailbox_api (db, sends real mail — D2); imap_poller (db, live IMAP — D14); filter_import (safe/any, writes DB — D15); relay_outbound (db, actually pure) | Re-declare to match real effects |
| **Hardcoded environment facts** — dev URL, origin IP 69.164.209.253, user_id 1, theme markers, DB name, `-42` lock namespace | api_test_harness, signed_urls, profile_mailbox, turn_activity, mailgun, routing, live_b2, all shell gates | Derive from Globalvars; one shared home |

**Duplicated helpers that belong in `tests/lib/` (or a per-area lib):**

| Helper | Copies | Home |
|---|---|---|
| HTTP client w/ cookie jar + CSRF | 4 API + 2 mailbox raw curls | `tests/lib/http.php` |
| Logic-call simulation (`runAdminLogic`) | ProductTester + echoed patterns | `harness_call_logic()` |
| `FakeDnsBackend` | 4 (dns_resolver, dns_auth, 2 SSRF) | `tests/lib` |
| Mailbox fixture+purge boilerplate | 8 (~60 lines each) | `plugins/mailbox/tests/lib/` |
| Cloud mock driver + scratch profile | 4 drivers + 3 profiles | `cloud_storage_fixtures.php` |
| LlmProviderInterface stub | 3 | `FakeLlmProvider` base |
| Conversation/vault fixtures | 5 conv + 2 vault-row | extend `vault_fixtures.php` |
| Drive tier-enrollment + chunk PUT + fixture teardown | 4 + 2 + 5 | `tests/lib` |
| Foreign-session APCu window fabrication | 2 (ceremonies, unlock_window) | `vault_fixtures.php` |
| Auth-login+register-key, web-form login, CSRF header, log-cleanup SQL | 3 + 2 + 2 + 3 | `tests/lib/http.php` |
| oauth `ok()` + Guzzle mock + session guard | 2 | `tests/integration/oauth/fixtures/` |

**Genuine test-vs-test duplication (candidates to merge/trim):**
- Cloud: engine_test vs characterization_test — 4 of 6 behavior groups overlap at a
  worse seam → consolidate 6 files → 4 by seam.
- Mailbox: MailboxViewer scope triple-covered (reader is the superset); profile_mailbox
  re-proves mailbox_api scope semantics in-process.
- Email: "email looks ready" assertion ×3 (2 vacuous); live provider sends ×3.
- Routing: routing_test re-covers admin-redirect + plugin-gating the functional
  API suites own with real credentials.
- Deletion: convention-resolution asserted in both the unit and integration test
  (one redundant data point — keep both tests).
- Vault: sealedbox ↔ vault_crypto re-assert primitives (keep only the DEK splice).

## Gap map

### Estate-wide coverage map (2026-07-16)

Coverage: NONE / TOKEN / PARTIAL / SOLID. Every subsystem gets thin structural
CRUD via `tests/models/models_test.php` (ModelTester over every discovered data
class including plugins) — counted as background, not business-logic coverage.

| Subsystem | Coverage | Evidence | Risk |
|---|---|---|---|
| Account security (registration, lockout, pwd reset, email verify, step-up, passkey sign-in, security levels) | TOKEN | Only a happy-path login fixture in browser_session_test.php; zero tests for login/passkeys/ceremonies/activation-codes flows | **Security — highest** |
| Stripe webhook (`/ajax/stripe_webhook`) | TOKEN | routing_test.php:645 asserts only "responds with some status"; payload processing only in live-only Stripe suites | **Money** |
| Subscription tier gating (core) | TOKEN | `grep tier_features\|has_feature` over tests/ → zero; no db-tier test that limits actually gate features | **Money** |
| server_manager (JobCommandBuilder, JobResultProcessor, publish_upgrade) | NONE | No tests dir; zero grep hits. This is the production deploy pipeline | **Data loss / availability** |
| event_manager (registrations, waiting lists, sessions, ICS) | NONE | `plugins/event_manager/tests/` exists and is EMPTY; only incidental fixtures elsewhere | Money / correctness |
| bookings (public /book flow) | TOKEN | Only sessionless fail-soft check + parse_utc_range unit | Correctness |
| dns_filtering beyond resolver units | TOKEN | Only 2 unit tests; block model, tier gating, editor, resolver e2e untested | Money / safety promise |
| Questions & surveys | NONE | 5 data classes untested; event checkout depends on checkout_submit_survey_logic | Correctness / data |
| Photos / UploadHandler | NONE | includes/UploadHandler.php + entity_photos untested (drive tests cover only the new file layer) | **Security** (upload surface) |
| Social features (conversations, messages, blocks, reports) | TOKEN | Reactions round-trip only; messaging/blocks/reports nothing | Correctness / privacy |
| Analytics / visitor events | TOKEN | ab_testing uses visitor_events; no attribution/reporting tests | Cosmetic |
| SEO metadata | NONE | zero grep hits | Cosmetic |
| Themes / theme chain | TOKEN | routing fallback status codes + components_manifest only | Correctness |
| Plugin lifecycle (PluginManager sync) | NONE | Sync mutates plugin schema on every deploy; zero tests | Data loss |
| Scheduled tasks runner | NONE (runner) | Tasks invoked directly by cloud tests; runner itself untested | Correctness |
| Admin pages framework | NONE dedicated | Routing smoke status codes only | Correctness |
| Deletion system | PARTIAL | Rule registration tested; no end-to-end cascade execution test | Data loss |
| Password vault plugin | PARTIAL | Server-side zero-knowledge contract covered; browser crypto browser-verified only | Security (mitigated) |
| Users / groups core | TOKEN | Structural CRUD only; no membership-logic tests | Correctness |
| Notifications | TOKEN | Count + invalidation only | Cosmetic |
| items plugin | NONE | No tests, no docs, no logic dir | Cosmetic |
| i18n | N/A | No i18n subsystem exists | — |
| Mailbox, Vault, Drive, Files/cloud, API v1, joinery_ai, Calendar | SOLID | Depth audited in per-area sections | — |
| Email sending, Store, Routing, FormWriter, scaffold/models/oauth2 | PARTIAL | Depth audited in per-area sections | — |

### Top 10 gaps (ranked)

1. **Account security lifecycle** — the most load-bearing security surface has
   zero dedicated tests. A regression is silent account takeover or total lockout.
2. **Stripe webhook processing** — a payload-handling regression (missed
   cancellation, double-crediting) ships past the safe gate undetected; all real
   coverage needs stripe-test-keys at live tier.
3. **Subscription tier gating (core)** — no automated check that tier limits gate
   features; a bug gives paid features away or blocks paying customers.
4. **server_manager** — the tool that builds/applies production upgrades has zero
   tests; a bad job command reaches every managed node.
5. **event_manager** — whole plugin untested; its tests/ dir is empty (intent
   without follow-through); checkout path touches money and surveys.
6. **UploadHandler / photos** — classic web attack surface, completely untested.
7. **Plugin lifecycle** — PluginManager::sync() runs on every deploy and mutates
   schema; nothing stands in the way of fleet-wide schema corruption.
8. **Deletion end-to-end** — no test executes a real cascade and asserts the right
   rows (and only those) are deleted — the unrecoverable-data-loss failure mode.
9. **dns_filtering** — paid product whose promise is "blocked stays blocked";
   only two narrow units.
10. **Questions & surveys** — zero tests, and event checkout depends on survey
    submission, so a break also breaks event registration.

Runners-up: bookings flow (double-booking/timezone), scheduled task runner,
core messaging (privacy-adjacent), themes chain, admin framework, SEO.

### Structural notes

- **Money paths are absent from the pre-deploy gate**: everything Stripe lives
  only in live-tier suites gated on stripe-test-keys; the safe tier contains no
  payment coverage at all.
- **Distribution skew**: ~60 of 102 tests concentrate in six subsystems
  (mailbox 20, api 10, drive 8, vault 8, cloud/files 8, joinery_ai 8) while five
  whole plugins plus surveys, photos, SEO, messaging, and account security share
  roughly three incidental checks between them.
- models_test's discovery-driven CRUD is the only reason "NONE" subsystems get
  any sanity at all — field/constraint level only.

## Implementation progress

Fixes are being applied 2026-07-16. Log (survives compaction):

**P0 + T8/T9 — DONE and verified (safe+db gate green: 89/89, 2086 checks).**
- T1 (tier fails closed): harness.php lowercases tier/env and maps an unknown
  tier to `live` (never batched) instead of `safe`.
- T2 (zero-match fails): run.php exits 2 with a clear message when a
  filter/only/tier selection matches no test (keyed on the pre-env-gate match
  count, so a prod-locked batch still exits 0). Also fixed `--filter` to match
  the repo-relative path, not the absolute install path.
- T3 (crash-to-pass ×5): crud_authorization, idempotency, session_keys,
  mailbox_api now `catch (\Throwable)` + `check(false,…)`; ajax_migration
  restructured to a single post-finally harness_finish() with a recorded crash.
- T4 (parse_utc_range): 12 `check($label,$cond)` → `ok(...)`; the suite now
  genuinely asserts. Plus a permanent harness guard in `check()` that turns any
  future arg-swap (string in the condition slot + non-string label) into a hard
  failure — verified it fires.
- T20 (teardown): harness teardown loop + all register helpers now catch
  `\Throwable`, so an Error in one teardown can't erase the result contract.
- T5 (gate TERM traps): the 3 device gates add `trap 'exit 143' TERM` /
  `'exit 130' INT`, so a runner timeout runs cleanup (removes the source-file
  probes, restores settings) exactly once via the EXIT trap.
- T6 (drive_crypto false-green): exits 1 (not 0) on missing node; declares
  `needs: [node]`.
- T7 (dishonest tiers): mailbox_api → `tier: live` (it sends real mail);
  imap_poller's poller-summary is now scoped to its fixture alias via a new
  optional `alias_id` on PollImapAccounts::run() — no more global live polling.
- T8 (vault APCu in gate): run.php spawns php with `-d apc.enable_cli=1`.
  Verified: vault_unlock_window went from skipped:1 to passed:23,skipped:0.
- T9 (needs probe): run.php `harness_unmet_needs()` probes node/macmini/
  stripe-test-keys/mailgun/b2 and reports unmet needs as SKIP; unrecognized
  needs default to met (never a blind skip).

**P1 / P2 partial — DONE and verified (full safe+db gate green: 90/90, 2113 checks).**

Product security fixes (these were real vulnerabilities, not just test gaps):
- T13 (SSRF hole): `scan_url_validate_target` now blocks `0.0.0.0/8`, CGNAT
  `100.64/10`, multicast `224/4`, reserved `240/4` — it previously let
  `0.0.0.0` (→ loopback on Linux) through while the sibling guard blocked it.
  Pinned with new cases in scan_url_validate_target_test (29→34 checks).
- Double-encoded path traversal: `RouteHelper::validatePath` now decodes
  iteratively, so `%252e%252e%252f` (which a second decode would revive into
  `../`) is rejected. New `routing_security_test.php` (20 checks) pins the whole
  traversal family in-process.
- Secret leak: `tests/email/auth_analysis.php` no longer logs `$_POST` or echoes
  it into page-source comments (it carried a live Gmail app password).
- T17 (vault recovery-code race): `unlockWithRecoveryCode` now consumes the code
  with an atomic `UPDATE … WHERE is_used=false` (rowCount must be 1) — a
  load-then-save race let two concurrent requests double-unlock from one code.
  Plus a cross-user ownership guard on both unlock methods. New test coverage in
  vault_ceremonies (25→27 checks).

Existing-test repairs:
- T16: ProductTester no longer fabricates a paid order (D26) or passes when a
  product lands in the production DB (D27); SubscriptionTierTester records a
  failure on every precondition miss (D28) and testFeatureAccess now asserts the
  tier display instead of returning true unconditionally.
- D34: DeliveryTests `createTestEmail`→`createTestMessage` (was a guaranteed
  fatal that killed the email_suites run).
- Teardown/quality: error_handling cleans its err_general_errors row + dropped a
  dead `sleep(1)`; email_validation_toggle defers its MX-check restore
  crash-safely; ajax_migration's `check(true,'fixtures created')` tautology is
  now a real assertion.

Infrastructure (P2):
- T22 (live-run confirm): tests_run_logic requires `confirm=true` server-side
  for the live tier and prod-verify tests; the dashboard threads it through.
- T18 (shared HTTP + logic helpers): DONE and gate-verified (safe 37/37,
  db 90/90; the live suites products/api_mailbox/profile_mailbox re-run green).
  New `tests/lib/http.php` — one `harness_request()` covering the whole estate's
  needs (json/form/raw/multipart bodies, cookie jar + extra cookies, redirect
  control, response-header capture, absolute URLs), plus jar/CSRF/login helpers
  (`harness_jar_new`, `harness_jar_csrf`, `harness_meta_csrf`, `harness_csrf_header`,
  `harness_web_login`, `harness_put_chunk`). Base URL derives from `webDir` and
  the origin IP is probed from the box's own outbound address — the hardcoded
  `dev.getjoinery.com` / `69.164.209.253` defaults are gone everywhere, and
  pinning engages only for the site under test so a prod base URL can't be served
  by this box. New `tests/lib/logic.php` — `harness_call_logic()` /
  `harness_call_logic_ok()`, generalized from ProductTester::runAdminLogic.
  `api_test_harness.php` is now a thin shim over the shared client (its
  `api_test_boot`/`api_request`/`key_headers` signatures unchanged, so all 12
  consumers keep working). Migrated off local curl/logic-sim: the 4 cookie-jar
  suites (guest_credential, browser_session, ajax_migration, app_platform),
  drive upload/encryption/sharing, files/signed_urls, mailbox_api (multipart +
  absolute-URL signed fetch), profile_mailbox, routing (HttpTester), and
  ProductTester (dead `makeAdminRequest`/`extractProductIdFromResponse` deleted).
  Acceptance met: the only `curl_init` left under tests/ is the shared client
  itself; no test simulates REQUEST_METHOD by hand; no hardcoded origin IP
  remains. Also folded in an ajax_migration teardown-hygiene fix (it never called
  harness_teardown_data(), leaving fixture users behind). Docs updated
  (docs/testing.md).
- T11 (routing security, HTTP half): DONE and gate-verified (db 91/91, 2125
  checks). New `tests/integration/routing_authz_test.php` (db, dev-only, 14
  checks) built on the T18 helpers: sensitive files by path (/includes/*.php,
  /data/*.php, direct /adm/*.php) all 404; anonymous access to a gated route
  redirects to /login; the permission gate refuses an authenticated-but-
  underprivileged user (perm-0 → /admin/* → 401, perm-5 → /tests/* → 401) WITH
  positive controls (perm-5 allowed /admin/*, perm-10 allowed /tests/*, so it
  can't pass by refusing everything); a private file is 404-not-403 to a
  stranger. The in-process traversal half (routing_security_test.php, safe) is
  untouched.
- **T10 drive_link_auth** — DONE 2026-07-17. New
  `tests/functional/drive/link_auth_test.php` (db, dev-only, 15 checks) drives
  drive_link_create_logic / drive_link_revoke_logic through an impersonated
  session ($_SESSION, which SessionControl reads live). Minting: owner mints a
  file link and a folder link; a viewer-grantee and a stranger are both refused
  with the ownership-gate error; anonymous is refused; a nonexistent entity is
  refused. Every non-owner actor holds the drive_share_links tier feature (a
  lightweight tier fixture) so a refusal can only be the ownership gate, not the
  tier gate — the model-layer sharing_test always passed the owner id straight
  to mint() and never touched this gate. Revoking: a stranger and a viewer-
  grantee are refused (link left live), the creator succeeds, and an admin
  (perm >= 5) may revoke a link they did not create (deliberate staff override,
  a positive control). Plus a render-side non-exposure check: a folder link
  lists that folder's own file but not a private file elsewhere in the owner's
  Drive. Full db 92/92, 2140 checks; teardown leaves no fixtures behind.
- **T12 deletion-cascade execution** — DONE 2026-07-17. New
  `tests/integration/deletion_cascade_test.php` (db, dev-only, 18 checks) proves
  permanent_delete() actually READS del_deletion_rules and applies each action —
  the companion deletion_rule_registration_test only proved rules are written.
  Section A, against self-contained scratch tables (zzdel_*, created + dropped
  by the test) with rule rows inserted directly: cascade deletes children, null
  nulls the FK, set_value rewrites to a sentinel, prevent throws and rolls the
  whole delete back (atomic — cascade/set_value children verified untouched
  after the block), and removing the blocker lets it through. Section B:
  permanent_delete_dry_run() lists all four dependencies with the right action
  each and reports can_delete false→true as the prevent child comes and goes.
  Section C: the permanent_delete ACTION recurses multi-level through the real
  registered chain usr_users → aic_conversations → aim_conversation_messages
  (only 3-level chain registered on dev; guarded with harness_skip if joinery_ai
  is inactive) — deleting a user removes its conversation (level 2) and the
  conversation's messages (level 3, the level flat-cascade would orphan) while a
  bystander's conversation+message survive. getModelClassForTable discovers
  models from the FILESYSTEM, so a fake in-file middle model can't drive the
  recursion (falls back to flat cascade) — the recursion test needs real
  on-disk models, hence the real chain. Full db 93/93, 2158 checks; teardown
  clean (0 scratch tables / rule rows / fixture users).

- **T14 TaintGate + untrusted-envelope** — DONE 2026-07-17. New
  `plugins/joinery_ai/tests/taint_gate_test.php` (db, dev-only, 25 checks) covers
  joinery_ai's prompt-injection defense, which had zero tests. (1) evaluate()
  predicate matrix: write-tool × untrusted-model → tainted-capable; workspace
  path; read-only tool or out-of-scope untrusted model → not tainted;
  whitespace-only workspace is empty; pipeline untrusted-digest flag. (2)
  explain()/describeDrift() name the trigger. (3) Save-time gate wired through
  admin_edit_logic in-process (perm-10 session): tainted-capable without opt-in
  rejected, saves once rcp_allow_tainted_writes set, write-but-not-tainted saves
  without opt-in (no over-block). (4) Run-start drift via reflection on
  RecipeRunner::checkTaintDrift: drift blocked without opt-in, cleared by opt-in.
  (5) Untrusted-input envelope: per-run nonce is fresh (distinct across
  contexts) and unpredictable 8-hex; a fake closer bearing the wrong nonce stays
  ENCLOSED inside the real <<UNTRUSTED_nonce>>…<</UNTRUSTED_nonce>> block, across
  query_model (ModelQueryExecutor::wrapUntrustedFields), view_attachment
  (AiAttachment::framedText), and get_workspace — the crafted-payload injection
  the accept criterion names. Fixtures: Comment (untrusted), Group (clean),
  update_model (write). 25/25 in isolation + via runner; teardown clean.
  NOTE: the full db tier currently shows 6 PRE-EXISTING failures unrelated to
  T14 — schema drift where the uncommitted mailbox compose-maturity work added
  iem_bcc to InboundEmailMessage field specs but update_database was never run,
  so every test inserting an inbound message fails (imap_poller, imap_syncer,
  inbound_email_attachment_storage, inbound_raw_storage, mailbox_vault_reseal,
  email_security_scan_job). They fail with T14 removed too; fix is update_database.
- **T15 Stripe webhook** — DONE 2026-07-17. New
  `plugins/store/tests/stripe_webhook_test.php` (db, dev-only, 23 checks) covers
  the store's top money path, previously untested. Two seams mirroring
  mobile_billing's split of crypto from engine. (1) Signature primitive: the
  endpoint delegates verification to `\Stripe\Webhook::constructEvent`, exercised
  in-process with a self-generated whsec_ secret (no configured secret, no HTTP)
  — a correctly signed payload verifies; a tampered body under the original
  signature, a wrong-secret signature, a stale timestamp (outside the SDK's 300s
  DEFAULT_TOLERANCE window the endpoint uses), and a malformed header are each
  rejected. (2) Endpoint over real HTTP against `/ajax/stripe_webhook`, events
  signed with the site's configured stripe_endpoint_secret (read server-side,
  never printed): a valid checkout.session.completed marks an Order STATUS_PAID
  linked to the client_reference_id user at amount_total/100; an invalid-signature
  post is refused with 400 and writes no order and no webhook-log row; a missing
  signature header is 400; a byte-identical replayed event id returns 200 and
  creates no second order (WebhookLog::isDuplicate suppresses it); and
  customer.subscription.deleted for a subscriber holding the tier cancels the
  order item and strips the tier (TierBilling::handleSubscriptionExpired). KEY
  SEAM NOTE: the endpoint script exits() and defines a function, so it is built
  to run once per request — the dispatch half MUST be driven over HTTP (a real
  request populates php://input), never by including the file (function-redeclare
  + exit() would break a second in-process event). The HTTP section skips
  cleanly when stripe_endpoint_secret is unconfigured; the signature seam has no
  config dependency. 23/23 in isolation + via runner; teardown leaves no orders/
  order_items/products/tiers/groups/webhook_logs behind. Full db 100/100, 2317
  checks.
- **T16 tester failure→pass conversions** — DONE 2026-07-17. Two halves.
  ProductTester (D26/D27): both conversions were already closed in the P0 pass and
  re-verified here — the fabricated-paid-order fallback is gone (stripe_checkout
  mode now throws an honest limitation instead of manufacturing a paid Order), and
  the create-verify path throws when a product surfaces in the PRODUCTION db (test
  isolation broken) rather than passing with a warning. SubscriptionTierTester
  silent-green (D28) — the real remaining work: the runner inferred a single GREEN
  check from the mere ABSENCE of recorded failures, so a run where zero tier tests
  executed (preconditions unmet, setup aborted, Stripe keys missing) was
  indistinguishable from a full pass. Fixed at the design level: the tester now
  records passes POSITIVELY (`$test_passes`) and counts executions
  (`$tests_executed`) through a `runTest()` dispatcher wrapping all 11 tests; a new
  `stripeTestKeysPresent()` guard turns a missing-test-key run into a loud recorded
  failure instead of a deep SDK throw or silent skip; and run.php now emits one
  check per pass, one per failure, plus a load-bearing `check($executed > 0, ...)`
  guard — so 0 executed is RED, never a lone green check. Verified: the suite's
  contract went from 1 check to 12 (executed-guard + 11 per-test), 12/12 green via
  runner at live tier (real Stripe test-mode calls); a zero-executed run flips the
  guard check false → red + legacy exit 1. Accept criterion met by construction.
- **T17 vault concurrency + ownership** — DONE 2026-07-17. The product fixes
  (atomic conditional consume `UPDATE ... WHERE uew_is_used=false` with rowCount==1,
  and `assertVaultOwnership` on both unlock cores) plus the cross-user ownership
  negatives landed in the P0 pass and are re-verified — vault_ceremonies_test
  already covers ownership refusal and *sequential* replay ("a consumed code never
  unlocks again"). The remaining accept criterion — "two concurrent uses of one
  recovery code cannot both unlock" — is the new
  `tests/vault/vault_recovery_concurrency_test.php` (db, dev-only, 7 checks). It
  spawns 8 real PHP worker processes (each its own DB connection), releases them on
  a shared wall-clock barrier so their conditional consumes genuinely contend, and
  asserts exactly one unlocks, the other seven are refused specifically as
  already-used, and exactly one recovery wrapping ends up consumed; the raced code
  is then spent to a later sequential use. The recovery code is passed to workers
  via the environment, never argv (no process-list exposure). Proven to be a real
  regression detector, not a serialized no-op: racing the OLD load-then-save
  consume pattern under the same barrier double-unlocked all 8 workers (OK=8),
  while the shipped atomic consume yields OK=1. Stable across repeated runner runs;
  teardown removes its own vault + wrappings (0 orphans added — the standing
  vault→uew cascade gap is separate and pre-existing). Full db 101/101.

**Still remaining (documented in the work plan below):**
T19 per-area
fixture libs, T21 unify the two SSRF guards, the url_safety_validator test
extension, the legacy email framework port/delete, cloud 6→4 consolidation,
remaining teardown-hygiene fixes, and the P3 greenfield suites (account
security, event_manager, server_manager, UploadHandler, PluginManager, surveys,
bookings, Multi collections, core-unit tests). These are larger and were left
for a follow-up pass rather than risk half-built suites.

## Recommendations / work plan

Executor-ready, ordered by leverage. Each task is self-contained with an
acceptance criterion. Tiers below are independent — an executor can take any P0
task without waiting on others. "Est." is relative size (S/M/L), not human time.

### P0 — trust the gate again (do these first; they invalidate false greens)

**T1. Make tier fail closed.** In `harness_parse_metadata` (tests/lib/harness.php:110-117),
lowercase the tier value and map any unrecognized tier to `live` (never batched)
rather than `safe`. *Accept:* a test declaring `tier: Live` or `tier: xyz` does not
run under `php tests/run.php` or `php tests/run.php db`. Est. S. [E6/D37]

**T2. Exit non-zero on a zero-match run.** In tests/run.php (the CLI exit at ~:282
and the `--json` exit at ~:267), return a non-zero code and print an explicit
"0 tests matched" error; error when `--only=` matches nothing. *Accept:*
`php tests/run.php db --filter=nonexistent` exits non-zero. Est. S. [E6/D38]

**T3. Fix the five crash-to-pass catches.** Replace `catch (Exception) { $failed++; }`
in crud_authorization_test:401, idempotency_test:142, session_keys_test:342, and
the `catch (Throwable)` + harness_finish() in ajax_migration_actions_test:233, and
the `catch (Exception)` in mailbox_api_test:554 — with either no catch (the harness
shutdown net records a failing check and runs teardown) or
`catch (\Throwable $e) { check(false, 'unhandled', $e->getMessage()); }` before the
finally. *Accept:* an exception thrown mid-suite in each file produces a failing
contract, not a green one. Est. S. [E7/D1/D7]

**T4. Fix parse_utc_range_test (asserts nothing today).** Swap all 12
`check($label, $cond)` calls to `ok($label, $cond)`. Then add a guard in
`check()` (tests/lib/harness.php:210) that emits a warning when `$condition` is a
non-empty string, so the arg-swap can never silently recur estate-wide. *Accept:*
introducing a real regression in a parse_utc_range case turns the suite red. Est. S. [E7/D30]

**T5. Give the three device gates a TERM trap.** Add `trap 'cleanup; exit 1' TERM INT`
alongside the existing EXIT trap in member_gate.sh:154, phase2_gate.sh:122,
phase3_gate.sh:162. *Accept:* sending SIGTERM to a running gate removes the
PHASE2_PROBE field from logic/account_edit_logic.php and restores SMTP/min-version
settings. Est. S. [D40]

**T6. Fix the drive_crypto_gate false-green.** In drive_crypto_gate.sh:17-20,
replace `exit 0` on missing node with `exit 1` and a clear message (interim), and
declare `needs: [node]` so T9's probe can turn it into a real SKIP. *Accept:* on a
box without node, the safe tier does not report drive_crypto as passed. Est. S. [D4/D42]

**T7. Re-tier the two dishonest live tests.** Move mailbox_api_test to `tier: live`
(or split its real-send round-trip into a separate live test); fix imap_poller_test
so testPollerSummary cannot run the real PollImapAccounts against non-fixture
accounts (scope the run to fixture account ids, or split to live tier). *Accept:*
`php tests/run.php db` sends no real mail and opens no live IMAP connection. Est. M. [D2/D14]

### P1 — close the security gaps that ship silently

**T8. Make the vault APCu suites run in the gate.** Append `-d apc.enable_cli=1`
where run.php spawns php test subprocesses (~:150), or set it in the dev CLI
php.ini. *Accept:* vault_unlock_window and the ceremonies kill-switch section
execute (not skip) under `php tests/run.php db`. Est. S — activates ~25 existing
high-value security checks. [D18]

**T9. Enforce `needs` via a probe registry.** Add a small map in discovery/run.php:
`macmini` → `ssh -o ConnectTimeout=5 macmini true`, `node` → `command -v node`,
`stripe-test-keys`/`mailgun`/`b2` → the relevant setting present. An unmet need
becomes a reported SKIP in both CLI and dashboard. *Accept:* with the mini off,
the device gates report SKIP, not FAIL; drive_crypto reports SKIP without node. Est. M.

**T10. New `drive_link_auth_test.php` (top drive escalation gap).** ✅ DONE (see
progress log). Covers
drive_link_create_logic / drive_link_revoke_logic: owner can mint/revoke; a
viewer-grantee and a stranger cannot; revoke by non-creator refused; folder-link
non-exposure of nested/private siblings. *Accept:* a stranger minting a public link
to another user's file fails. Est. M. [drive gap 1]

**T11. Routing security test.** ✅ DONE (see progress log): traversal half in
routing_security_test.php (safe), HTTP/authz half in routing_authz_test.php (db).
In-process against RouteHelper plus a few HTTP
probes: path traversal (`/theme/../config/...`, `%2e%2e%2f`, dotfiles), direct
`/includes/*.php` / `/data/*.php` → 404, private cloud file → 404-not-403, and an
authenticated permission-gate check (perm-0 denied `/admin/*`, perm-5 denied
`/tests/*`). *Accept:* a traversal payload and a low-perm admin request are both
refused. Est. M. [misc gaps 1-3]

**T12. Deletion-cascade execution test.** ✅ DONE (see progress log). Fixture
parent+child models; call
permanent_delete() on the parent and assert children are deleted per del_action,
that permanent_delete recurses multi-level, and that `prevent` blocks. *Accept:* a
rules table that is registered but never consulted fails this test. Est. M. [misc gap 4 — the data-loss gap]

**T13. Close the scan_url SSRF range hole (product + test).** Add `0.0.0.0/8`,
`100.64.0.0/10`, `224.0.0.0/4`, `240.0.0.0/4` to scan_url_validate_target's range
table (or delegate to the unified guard, T21), and add test cases for
`http://0.0.0.0/` and `http://100.64.0.1/`; extend url_safety_validator_test with
IPv6 literals, `169.254.169.254`, decimal/octal IPs, and userinfo tricks. *Accept:*
`http://0.0.0.0/` is rejected by both guards. Est. M. [unit D31]

**T14. TaintGate test (joinery_ai's worst gap).** ✅ DONE (see progress log). Cover the tainted-capable
predicate across write-tools × untrusted-model-reads, the save-time rejection
without allow_tainted_writes, and the run-start drift re-check. Plus an
untrusted-envelope conformance test across fetch_url/web_search/query_model/
view_attachment (per-turn nonce freshness; an embedded fake closer doesn't
terminate the block). *Accept:* a crafted untrusted payload with a fake envelope
closer cannot inject. Est. M. [ai gaps 1-2]

**T15. Stripe webhook test (store's top money gap).** ✅ DONE (see progress log). Model it on mobile_billing's
seam approach: constructed events through stripe_webhook.php with a signature-
failure case, a duplicate-event case, and an entitlement-revoking refund. *Accept:*
an invalid-signature webhook is rejected; a duplicate event is suppressed. Est. M. [store gap 1]

**T16. Fix ProductTester's two failure→pass conversions.** ✅ DONE (see progress log). Delete the fabricated-
paid-order fallback (ProductTester:1650-1702) → record a failure or skip; make the
"found in production DB" branch (:674) a hard failure. Fix SubscriptionTierTester's
silent-green: every precondition-miss `return` must recordFailure (:1162 etc.), and
add the live-key guard it lacks. *Accept:* a run where 0 tier tests execute reports
red, not one green check. Est. M. [store D26/D27/D28]

**T17. Vault concurrency + ownership.** ✅ DONE (see progress log). Add a recovery-code replay-under-concurrency
test and fix the underlying read-then-mark race with an atomic
`UPDATE ... SET is_used=true WHERE ... AND is_used=false` in unlockWithRecoveryCode;
assert vault ownership (`uev_usr_user_id === user->key`) inside the ceremony cores
with a cross-user negative. *Accept:* two concurrent uses of one recovery code
cannot both unlock. Est. M. [vault gaps 2-3]

### P2 — consolidate infrastructure (unblocks cheaper future tests)

**T18. Promote shared helpers into `tests/lib/`.** ✅ DONE (see progress log).
Create `harness_call_logic($path,
$fn, $data, $method)` (from ProductTester::runAdminLogic) and an HTTP client with
cookie jar + session-login/CSRF (generalizing api_test_harness, base URL + origin
from Globalvars, not hardcoded). Migrate the 4 API + 2 mailbox curl copies. *Accept:*
no test file defines its own cookie-jar curl or logic-call simulation. Est. L. [E5]

**T19. Extract per-area fixture libs.** `plugins/mailbox/tests/lib/mailbox_test_fixture.php`
(kills 8 purge-boilerplate copies + 4 raw makeUser); `cloud_storage_fixtures.php`
(RecordingMockDriver with get/put/delete failure injection + ScratchTableProfile,
kills 4 drivers + 3 profiles); a scripted `FakeLlmProvider` base (kills 3 stubs);
`FakeDnsBackend` into tests/lib (kills 4); `vault_fixture_client_vault()` (kills the
raw-SQL vault write in drive/encryption_test). *Accept:* each named duplicate class
exists once. Est. L. [Duplication map]

**T20. Catch Throwable in teardown + always emit the contract.** In the harness
teardown paths (harness.php:298,310,326,344) catch `\Throwable`; restructure
harness_finish so the contract is emitted even when a teardown throws (emit inside
a finally, or move `finished=true` after emit). *Accept:* a TypeError in one
teardown closure still produces a full contract and runs remaining teardowns. Est. M. [D39]

**T21. Unify the two SSRF guards.** Merge UrlSafetyValidator and
scan_url_validate_target into one core validator with a single range table (per the
build-generally principle — this is how the 0.0.0.0 gap diverged), then one
comprehensive test replaces both. *Accept:* both call sites use the same guard;
the bypass-case table lives in one test. Est. M. [unit Duplication 1]

**T22. Server-side confirm for live/prod-verify runs.** Require `confirm: true` in
tests_run_logic.php for `tier=live` batches and prod-verify single tests; gate
direct `/tests/*` GET execution of live-tier files. *Accept:* a bare
`POST tests_run {"tier":"live"}` without confirm does not run live tests. Est. M. [D41]

### P3 — fill the zero-coverage subsystems (largest, do after P0-P2)

Ranked by the estate-wide gap map. Each is a new suite for a subsystem with no
business-logic coverage today:

- **T23. Account security lifecycle** (registration, login lockout, password reset,
  email verification, step-up) — the highest-risk zero. Est. L.
- **T24. event_manager** (registration, waiting lists, ICS, checkout+survey path) —
  its tests/ dir exists and is empty. Est. L.
- **T25. server_manager** (JobCommandBuilder, JobResultProcessor) — the production
  deploy pipeline, zero tests. Est. M.
- **T26. UploadHandler / photos** — the legacy upload attack surface. Est. M.
- **T27. PluginManager::sync()** — runs on every deploy, mutates schema. Est. M.
- **T28. Questions & surveys** — event checkout depends on it. Est. M.
- **T29. bookings /book flow** — double-booking/timezone; no tests dir. Est. M.
- **T30. Multi-collection CRUD suite** — the entire Multi surface is untested
  (SINGLE_TESTS_ONLY); high value given the documented `->results` foot-gun. Est. M.
- **T31. Core unit tests** — process_logic/LogicResult, convert_time/time_shift,
  SystemBase lifecycle, DescriptorValidator base coercion. Est. M.

### Housekeeping (bundle opportunistically when touching a file)

Legacy email framework verdict: **delete** test_runner.php, the uncalled
EmailTestRunner methods, and DeliveryTests entirely; **port** ServiceTests'
read-only provider group → safe, its send/fallback/queue → live with harness_defer
restoration, TemplateTests' subject logic → db with self-created fixtures,
AuthenticationTests → live DNS; then delete EmailTestRunner + suites/ + the wrapper.
**Immediately** fix the auth_analysis.php secret leak (delete the `$_POST`
error_log and the debug HTML comments) regardless of its eventual disposition, and
fix DeliveryTests:96 (`createTestEmail`→`createTestMessage`) so the email_suites run
stops being a guaranteed fatal.

Consolidate cloud 6 files → 4 by seam (delete characterization's 4 duplicated
sections + its self-referential cap check). Fix db-tier teardown hygiene
(harness_defer / harness_register_row at creation) in pipeline_runner,
email_security_scan_job, email_validation_toggle, error_handling, native_entry,
schedule_model, and both store testers. Replace hardcoded env facts (dev URL,
origin IP, user_id 1, DB name, `-42` lock namespace) with values derived from
Globalvars or a single shared constant. Anchor the discovery marker to the header
start (reduce phantom-test risk). Retrofit stdin credential streaming into the iOS
gates. Sync docs/testing.md with `--only=`/`--timeout=`, the zero-match behavior,
the tier direction, and that `needs` is enforced (after T9).

### Directory / naming cleanup (mechanical, low priority)

Pick one taxonomy — feature-based matches the plugin convention. Moves are
mechanical because tier/env headers carry the metadata (the runner ignores
location). Rename the two `test_*.php` files to the `*_test.php` suffix. Convert the
two big store tester classes and the legacy email framework to standard harness
tests as part of T16/housekeeping (generation-1/2/3 infrastructure → generation 4).
