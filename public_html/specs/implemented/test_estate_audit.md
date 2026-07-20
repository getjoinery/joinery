# Test Estate Audit

**Status:** IMPLEMENTED (2026-07-20). Every numbered work-plan item (T1–T32,
P0 through P3, plus the housekeeping bundle) is done and gate-verified — see
the dated progress log entries throughout. Final items closed 2026-07-20:
T20's accept criterion was proven by demonstration (a TypeError in one
teardown closure still runs the remaining teardowns LIFO and emits the full
result contract, exit honest), and the two `test_*.php` stragglers were
renamed to the `*_test.php` suffix (`tests/schema/index_management_test.php`,
`tests/models/model_tester_selftest_test.php`; discovery re-verified: 150
declared, 0 undeclared, both suites green).

Two items remain deferred by decision, not omission: the cloud 6→4 file merge
(rationale in the progress log — merging characterization into the engine
suite risks the real-BlobStorageProfile db-tier coverage) and the optional
feature-based directory taxonomy reorg (headers carry all metadata; the runner
ignores location, so the reorg is cosmetic).

**Original status:** AUDIT COMPLETE (2026-07-16). All 13 areas surveyed; findings,
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

- **T32 db-tier flakiness root cause (vault suites)** — DONE 2026-07-18. The
  rotating vault-suite failures (`specs/implemented/test_gate_flakiness.md` has
  the full investigation and fix) were primary-key reuse: `update_database`'s
  sequence-sync step rewound serial sequences to `MAX(pkey)` after test
  teardown deleted the newest rows, while the database contained **zero**
  foreign-key constraints (the `'foreign_key'` field-spec key was materialized
  by nothing), so deleted test vaults left 1,894 orphaned rows that re-attached
  to freshly created vaults sharing a recycled ID. Fix: forward-only sequence
  sync everywhere (shared `DatabaseUpdater::syncSequenceForward`), the
  `'foreign_key'` spec key is now materialized as real constraints by
  update_database + plugin sync (orphans block loudly), migration 150 removed
  the orphan estate, `make_user` emails carry a per-process token, and the new
  `tests/schema/referential_integrity_test.php` (tier `safe`) fails the gate on
  any future orphan/behind-sequence/stray-fixture leak. Hypotheses ruled out:
  APCu (per-process in CLI), connection exhaustion (sequential subprocesses),
  concurrency-suite residue (same orphan class as every suite), fixture-name
  collision (secondary abort mode only, also fixed).

**T21 (unify the two SSRF guards) — DONE 2026-07-17.** The two near-identical
SSRF validators — joinery_ai's `UrlSafetyValidator` (fetch_url tool) and
dns_filtering's `scan_url_validate_target()` (scan_url action) — are now one core
class, `includes/UrlSafetyValidator.php`, with a single authoritative IPv4 range
table. That single table is the whole point: the split table is exactly how the
0.0.0.0/8 SSRF hole (T13) diverged — one guard blocked it, the other did not.
- The one genuine policy difference between the two callers is the port allowlist:
  the LLM fetch tool restricts to 80/443, while the page scanner legitimately hits
  dev sites on non-standard ports. This is now a per-call option — `checkAndResolve($url,
  ['allowed_ports' => null])` permits any port; the default keeps 80/443. Every
  other defense (scheme allowlist, blocked-hostname literals, resolve-all-IPs
  range rejection, fail-closed on resolver error, IP pinning via the returned
  `ips`) is shared, so it cannot drift again.
- `FetchUrlTool` now requires the core path; the plugin copy
  `plugins/joinery_ai/includes/UrlSafetyValidator.php` is deleted. `scan_url_logic`
  calls the core guard (any-port) and catches `UnsafeUrlException`; its
  `scan_url_validate_target()` function and `ScanUrlValidationException` are gone,
  and its user-facing error is kept generic so it does not disclose a target's
  resolved internal address.
- One comprehensive test, `tests/unit/url_safety_validator_test.php` (48 checks),
  replaces both former unit tests (the scan_url one is deleted). It covers both
  port modes, the full IPv4 range table + boundary, IPv6 loopback/link-local/
  unique-local/v4-mapped, DNS rebinding, fail-closed, redirect-hop revalidation,
  and the T13-remaining obfuscation cases (v4-mapped metadata, decimal/octal/hex-
  encoded IP hosts, userinfo tricks). Safe tier green (36/36 — the two SSRF tests
  are now one). Docs updated (joinery_ai + dns_filtering overviews).
- Known pre-existing gap left in place (out of T21's unify scope, and true of both
  former guards): the NAT64 well-known prefix `64:ff9b::/96` embedding an IPv4
  address is not blocked — a future range-table addition, not a regression.

**T19 (extract per-area fixture libs) — DONE 2026-07-18.** All five
duplicate-fixture families are now single shared libs, every touched test verified green:
- `tests/lib/dns_fixtures.php` — `FakeDnsBackend` (was 3 identical copies:
  dns_resolver, dns_auth_checker, url_safety_validator).
- `tests/lib/llm_fixtures.php` — `FakeLlmProvider` base (all 8 LlmProviderInterface
  boilerplate methods once) + `ScriptedLlmProvider` (accepts full canonical
  responses OR the `['text'=>…]` shorthand, streams text deltas). Replaced the two
  identical `FakeVerdictProvider` copies (email_security_scan_job,
  joinery_ai_pipeline_runner) + `FakeActivityProvider` (turn_activity); the bespoke
  `ChatCancelStubProvider` now extends the base, keeping only its cancel logic.
- `tests/lib/cloud_fixtures.php` — `RecordingMockDriver` (ops log + failure
  injection + on_put; was CharMockDriver/EngMockDriver/PrivMockDriver),
  `InMemoryBlobDriver` (content round-trip; was RawIngestMockDriver/
  RawStoreMockDriver), and `ScratchTableProfile` (option-driven eligibility/
  visibility/ownership; was EngMockProfile + PartProfile). `reverseEligibilityWhere()`
  defaults to `''` so the Eng case — which had no such method — is behavior-identical
  (the engine trims the result; '' == absent).
- `tests/lib/vault_fixtures.php` gained `vault_fixture_client_vault()` — the raw-SQL
  client-custody vault write (was inline in drive/encryption_test).
- `plugins/mailbox/tests/lib/mailbox_test_fixture.php` — `mailbox_make_user()`
  (raw insert bypassing MX validation; was 5 near-identical private `makeUser`
  copies across inbound_attachment, inbound_email_mailbox_grant,
  inbound_email_attachment_storage, mailbox_reader, profile_mailbox) plus
  `mailbox_purge_domains($domain_like, $user_email_like, $purge_orphan_grants)` —
  the FK-safe preClean cascade (attachments→grants→messages→aliases→domains, +
  optional user sweep by email LIKE and orphan-grant sweep). Replaced 7 per-file
  `preClean()` bodies (inbound_attachment, inbound_email_attachment_storage,
  profile_mailbox, mailbox_reader, inbound_email_mailbox_grant, raw_message_store,
  inbound_raw_storage). The cascade + the escaped-underscore user LIKE were
  proven correct against a seeded fixture (all cascade rows removed; matching user
  deleted, non-matching kept). The three IMAP suites (imap_poller/imap_syncer/
  inbound_imap_account) keep their own preClean — they cascade extra IMAP tables
  (iia/iif/ilb/ilm), a genuinely different shape, not core boilerplate.

**Housekeeping — DONE 2026-07-18 (safe 36/36, db green).** The bounded, coverage-safe
housekeeping items:
- **Discovery marker anchored.** `harness_parse_metadata` now matches `@joinery-test`
  only at a header-style line start (`/^[ \t\/*#]*@joinery-test\b/m`), so a prose
  MENTION of the marker (docs, a test describing the header format) is no longer
  discovered as a phantom test. Verified: the prose-only files (vault_fixtures,
  api_test_harness, discovery, run.php) dropped out of the declared list; real
  tests unchanged.
- **docs/testing.md synced** with `--only=`, `--timeout=`, the zero-match non-zero
  exit, the fail-closed tier direction (unknown tier → live), and `needs` enforcement.
- **Cloud D20 + D21.** Deleted the self-referential cap check in
  cloud_storage_characterization (it re-ran its own copy of the eligibility SQL —
  a real regression in the engine's query still passed; the cap is covered through
  the REAL query in cloud_offload_engine). Added the partial-push failure path to
  cloud_offload_engine (a multi-object row via the shared profile's new `variants`
  option; a later object's PUT fails; asserts the already-pushed object is deleted
  — no bucket orphan — the row stays local, counter bumped). engine 16→22, char 22→20.
- **db-tier teardown hygiene.** joinery_ai_pipeline_runner and email_security_scan_job
  now register each throwaway row's cleanup via harness_defer AT creation (LIFO,
  crash-safe) instead of an end-of-file block; native_entry and schedule_model
  register their rows via harness_register_row. error_handling and
  email_validation_toggle were already crash-safe (defers present, no undeferred
  persistent rows). The two store testers' teardown is folded into their
  gen-2→gen-4 conversion (below), not half-fixed.
- **Hardcoded env facts.** The `-42` per-row advisory-lock namespace is now
  `CloudOffloadEngine::ADVISORY_LOCK_NAMESPACE`, single-sourced on the engine and
  referenced by cloud_storage_live_b2 (was hardcoded in both). joinery_ai_turn_activity
  derives its conversation owner instead of assuming user 1 exists. Confirmed the
  rest were already derived: http.php base URL from `webDir` (the dev URL survives
  only in doc examples), live_b2 DB name from `get_setting('dbname')`, origin IP
  from the outbound interface (T18).
- **Legacy email framework — RETIRED 2026-07-18.** The ~2000-line
  generation-2 framework (`EmailTestRunner` + `suites/{Service,Template,Delivery,
  Authentication}Tests` + the `email_suite_test` wrapper + `fixtures/` + the dead
  `test_runner.php`) is deleted. Its valuable coverage is re-expressed as four
  native harness tests, each verified green:
  - `email_template_render` (db, 18 checks) — template→EmailMessage rendering:
    body *var* substitution, subject extraction, subject-override priority, the
    htmlToText alternate, subjectless→empty-subject, fail-loud on a missing
    template. Self-created throwaway templates. (The legacy TemplateTests was
    partly stale — it called a removed `CreateLegacyTemplate` and asserted a
    "missing subject exception" the model never raised; those were NOT ported.)
  - `email_provider_config` (safe, 13 checks + 3 skips) — the provider registry
    (env-independent) + per-provider config well-formedness (unconfigured → SKIP).
  - `email_send_delivery` (live/dev-only, needs mailgun, 6 checks) — CLOSED-LOOP:
    sends through the active provider (Mailgun) AND the SMTP fallback (transport
    override) to a throwaway store alias on dev.getjoinery.com, then polls
    iem_inbound_email_messages to prove arrival (both delivered in ~5–10s). A
    stronger bar than the legacy "send accepted" checks.
  - `email_auth_dns` (live/prod-verify, needs mailgun, 7 checks) — SPF/DKIM/DMARC
    against the REAL published DNS for mailgun_domain (mg.dev.getjoinery.com: SPF
    pass, DKIM selector `mx`, DMARC present). Complements the offline, mocked
    `dns_auth_checker_test`.
  DeliveryTests was deleted with nothing ported — confirmed no unique coverage
  (its "test-mode redirect" never tested redirect, just template-sendability now
  in email_template_render; debug-logging is a no-op unless debug mode is on;
  service-sending is subsumed by email_send_delivery). docs/email_system.md and
  the dns_auth_checker_test pointer were updated to the new files.

**Vault recovery-unlock robustness — FIXED 2026-07-18.** Running the full db gate
after the email work surfaced a flaky failure in the T17 `vault_recovery_concurrency`
test: under heavy concurrent load, `unlockWithRecoveryCode` occasionally threw a raw
`SodiumException` ("unsupported key length"). Root cause was a real defense-in-depth
gap, not a data bug (every stored salt is a valid 16 bytes): the per-recovery-code
KEK derivation (`kekFromRecoveryCode`) sat OUTSIDE the per-wrapping try/catch, so a
single malformed or transiently-unreadable salt on ANY recovery wrapping aborted the
whole unlock — denying every other recovery code. Moved the derivation inside the
try/catch so one bad row is skipped, not fatal (`SodiumException extends Exception`,
already caught). Verified: 20/20 standalone + 12/12 under 3× concurrent load (was
~1/8 flaky); vault_ceremonies 27/27 still green. The T17 test was also hardened:
the concurrent round retries when NO worker wins (the atomic consume never fired, so
the code is still unused and re-racing the same fixture is safe), asserts the hard
security invariant (`oks <= 1`) every round, and tolerates ≤2 infrastructure
casualties under maximum load while keeping "exactly one consumed" strict — 15/15
standalone + 9/9 under heavy load after hardening.

**T23 account security lifecycle — DONE 2026-07-18.** The top-ranked zero now has
four db-tier suites under `tests/account_security/` — 137 checks, all inside the
pre-deploy gate rather than behind a live HTTP round trip, because the logic
functions are callable in-process through `harness_call_logic`:

- `registration_test.php` (35) — the feature gate, three bot defenses, required
  fields in both bare and `lbx_reg_` forms, duplicate and platform-hosted address
  refusals, Argon2id hashing, and the successful path down to the activation code
  actually minted.
- `login_test.php` (31) — credential refusals proven indistinguishable (no
  enumeration oracle), the per-IP failure throttle at both edges plus window
  expiry and success exclusion, the IP allowlist including CIDR, the activation
  gate, and the second-factor divert asserting the diverted session is NOT signed
  in under either cadence.
- `password_reset_test.php` (40) — code entropy/shape/distinctness, expiry,
  single use across all three resolvers, purpose and ownership isolation, the
  completion flow, and reset-request throttling.
- `stepup_test.php` (30) — factor detection, marker TTL asserted on both sides of
  the boundary, session binding, the gate's four decisions, and the open-redirect
  guard across seven hostile return URLs.

Four product defects surfaced and were fixed, which is the return on testing an
untested subsystem:

1. **Consumed activation codes stayed live (security).** Validity was expressed in
   three lookups and only `checkTempCode` filtered `act_deleted`;
   `getIdFromTempCode` and `getTempCodeInfo` did not. `Activation::ActivateUser`
   resolves through the former and `login_logic.php:36` calls it with no
   `checkTempCode` in front, so a spent activation link stayed a working
   credential for its full lifetime — and for an account with no password set
   that branch calls `store_session_variables()`, signing the visitor in. Old
   activation mail was account takeover. `profile_logic.php:37` and two admin
   pages shared the exposure; `recovery_verify_logic.php:32` carries a comment
   showing the asymmetry was known and locally worked around. Fixed at the source
   — both resolvers now filter `act_deleted` — which covers every caller and
   costs no UX, since `ActivateUser` never deletes codes and re-clicking a valid
   link still works. Reverting the fix turns exactly five checks red.
2. **`User::CreateCompleteNew` leaked an open transaction.** It caught only
   `TTClassException`, so the `DisplayableUserException` a failing password raises
   escaped with the transaction open. A web request hid it by dying; any
   long-lived process kept a poisoned connection and failed every later write.
3. **`email_dry_run` did nothing.** A declared setting with an admin control
   reading "prevent all sending, just log" that `EmailSender::send()` never
   consulted. Registration and reset both send, and dev points `email_service` at
   mailgun, so the gate would have emitted real mail on every run.
4. **The password no-spaces rule was unreachable and is deleted.**
   `strstr(' ', $password)` had haystack and needle reversed, so it never fired.
   Removed rather than repaired: a passphrase with spaces is a good password,
   nothing in the UI claimed otherwise, and `GeneratePassword`/`check_password`
   already trim symmetrically. No replacement check — asserting that something is
   accepted asserts the absence of a rule, of which there are infinitely many.

Also documented: `harness_set_setting_mem` cannot blank a setting, because
`Globalvars::get_setting()` treats an empty in-memory value as a cache miss and
falls through to the database. It silently routes a test down the wrong branch;
`docs/testing.md` now says so.

Gate after: safe 39/39 (765 checks), db 114/114 (2790 checks).

**Generation-2 store testers RETIRED — DONE 2026-07-18.** The two bespoke tester
classes plus their runner shims (~3,200 lines) are deleted and re-expressed as
native harness tests. Both are verified green against real Stripe test mode.

- `plugins/store/tests/subscription_tiers_test.php` (live, dev-only,
  needs stripe-test-keys, **39 checks**, was 12) replaces
  `subscription_tiers/SubscriptionTierTester.php` + `run.php`. The entire
  silent-green apparatus is gone — the positive `$test_passes` counter, the
  `$tests_executed` guard, and the runner's reflection into private props were
  all scaffolding to stop a coarse tester reporting green for tests that never
  ran; native `check()` calls make that failure mode structurally impossible.
  Sections: preconditions, model-layer entitlement, price-id sync, then the
  Stripe lifecycle (upgrade+proration, downgrade immediate AND end-of-period,
  cancellation immediate AND end-of-period, reactivation).
- `plugins/store/tests/products_test.php` (live, dev-only, needs
  stripe-test-keys, **44 checks**) replaces `products/ProductTester.php` +
  `run.php` + both `products_to_test*.json` spec files. Fixtures are created
  in-test rather than driven from JSON, matching the email conversion.
  Sections: admin-logic product creation (incl. an explicit
  written-to-test-DB-not-production assertion), cart add/remove/total, coupons,
  and a real tokenized Stripe charge verified against the Stripe API.

Defects found and fixed in the conversion (all were dead or unasserted paths):
- **`subscription_downgrade_timing` was never honored** (product bug). The
  setting drove only the button label; the downgrade branch always applied
  immediately. Default is `end_of_period`, so the DEFAULT config was the broken
  one — the UI promised end-of-period while the backend stripped paid-for access
  at once. The two end-of-period test methods that would have caught it existed
  but were never called (`testLogicFileDowngrade(false)` /
  `testLogicFileCancellation(false)` — dead code). Fixed with a new
  `StripeHelper::schedule_subscription_change()` (Stripe subscription schedule:
  current price to period end, new price after) plus timing branching in
  `change_tier_logic`.
- **Stripe reactivation only half-reversed a cancellation** (product bug). It
  cleared `cancel_at_period_end` but left `odi_subscription_cancelled_time` set
  and the status stale, so `is_active_subscription` never matched again — the
  PayPal branch in the same switch cleared all three correctly.
- **The webhook never re-derived the tier from the billed price**, so a
  scheduled downgrade would have scheduled at Stripe and never landed locally.
  `customer.subscription.updated` now re-points the order item and tier from the
  current price; covered by 5 new checks in `stripe_webhook_test` (23→28),
  proven as real detectors by disabling the remap (3 fail).
- **Silent-green Multi filter keys**: SubscriptionTierTester queried
  `MultiGroupMember` with `grm_grp_group_id`/`grm_foreign_key_id` and
  `MultiProduct` with `pro_is_active`/`pro_delete_time` — none are recognized
  option keys, so both queries ran unfiltered. The membership assertion counted
  every group member on the site and could never fail. (The documented
  `->results` foot-gun's sibling; see the Multi option-key rule in CLAUDE.md.)
- **Dead code referencing a schema that does not exist**: ProductTester's
  `$this->test_billing_user` was read but never assigned anywhere, and
  `createMockSubscription()` (uncalled) set `ord_amount` and
  `odi_subscription_item_id` — neither column exists.
- **Teardown**: ProductTester tracked `$created_products` only to print them —
  products, versions and requirement instances were never deleted. The
  replacement deletes via `permanent_delete()` (so the requirement-instance FK
  children go too) and adds a narrow preclean keyed on its own generated
  `pro_link` pattern, so a crashed run self-heals on the next one.
- Order identification no longer uses "created in the last 60 seconds"; the
  charge section takes a max-id watermark first, so a concurrent run cannot make
  the suite verify somebody else's order.
- **D29 closed (echo-only coupon results).** The coupon section now asserts the
  arithmetic against a known single-item cart: a 15.00 coupon takes exactly
  15.00 off, a 10 percent coupon takes exactly a tenth off, removal restores the
  original total, and an unknown code is refused without moving the total.
- **D28's scaffolding retired.** The `$test_passes` / `$tests_executed` /
  executed-guard machinery added under T16 was the right fix for a coarse
  tester; with native per-assertion checks it is unnecessary and is gone.

Not fixed, logged here: `admin_product_edit_logic.php:57` sets `pro_created_by`,
which is not a defined field on Product — every product creation logs a
non-fatal "Attempting to set the non-defined field" exception and silently drops
the intent. Fix is either adding the column to `$field_specifications` or
removing the line, depending on whether the audit field is wanted.

**`tests/models/` (newly inventoried).** It holds `ModelTester.php` (83KB) and
`MultiModelTester.php` (37KB) behind gen-4 shims (`models_test.php`,
`test_model_tester.php`) plus four undeclared web/CLI runners (`run_all.php`,
`run_automated.php`, `run_multi.php`, `index.php`). These are **not** the same
shape as the store testers that were retired: they are reusable engines that
introspect `$field_specifications` and generate values per type across all 151
models, so there is nothing to convert — the shims already drive them correctly.
The suite was red (66/151) and is now green; see the T32 entry below and
`specs/implemented/model_crud_suite_repair.md`.

`models_test.php` hard-sets `SINGLE_TESTS_ONLY=true` / `TEST_MULTI=false`, so
`MultiModelTester` never runs from the gate — which is exactly why **T30**
records the Multi surface as untested. A measured probe with `MULTI_TESTS_ONLY`
showed the Multi engine itself works (9 pass / 0 fail / 3 skip on a 12-model
sample), so T30 is wiring plus whatever the full sweep then surfaces. It is
unblocked now that the single-model half is green.

**T24 event_manager — IN PROGRESS 2026-07-18.** Five db-tier suites (201 checks)
under `plugins/event_manager/tests/`, all inside the pre-deploy gate:

- `event_capacity_test.php` (22) — the availability contract, capacity
  arithmetic, seat release on expiry, and the enforcement boundary (pins that
  `add_registrant` is not the gate).
- `event_deletion_test.php` (20) — declared rules, the executed cascade, and the
  blast radius: what must survive a delete.
- `checkout_survey_test.php` (22) — the registration requirement, the
  survey-belongs-to-event binding, the replay guard, and the answers written.
- `event_recurrence_test.php` (89) — pattern matching for all four types
  (intervals, named weekdays, week-of-month including last, month-length and
  leap-day clamping), occurrence computation, virtual instance construction
  across a DST boundary, range expansion with materialized/virtual dedup,
  materialization idempotence and refusals, and ending a series.

- `event_ics_test.php` (48) — date resolution, both ICS route handlers driven end
  to end, calendar feed membership and UID distinctness, and ICS content.

Recurrence carried three defects, all in the same shape: date arithmetic that
looks plausible in the output, so nothing downstream flags it.

1. **Annual events listed occurrences 11 years apart.** After the first match,
   `compute_occurrence_dates` advanced by 11 months and kept doing so, drifting a
   month per step and only realigning with the start month after twelve steps —
   132 months. A yearly series asked for four occurrences returned 2026, 2037,
   2048, 2059. The two `count = 1` callers (event page, ICS feed) escaped it
   because the loop exits on the first match; the admin occurrence list asks for
   20 and got nonsense. Fixed by stepping to the next anniversary directly,
   clamped to month length so a Feb 29 series lands on Feb 28 in common years and
   returns to Feb 29 in leap years.
2. **Wide strides silently returned short lists.** The iteration budget was
   `count * 50` while the loop walked one day at a time, so a quarterly series
   asked for 20 occurrences returned 11 and stopped. A short list is
   indistinguishable from a series that ended. The budget now accounts for the
   stride the pattern can actually ask for.
3. **Virtual instances carried the parent's row id.** `create_virtual_instance`
   set `evt_event_id = null` and then the field-copy loop two lines below put the
   parent's id straight back. Latent today — every consumer gates on `is_virtual`
   and builds URLs from `evt_link` plus the instance date — but the code stated
   an intent the next statement destroyed, and any consumer reading the id would
   have linked to, or registered against, the series template instead of the
   occurrence. Fixed by clearing after the copy.

All three were confirmed load-bearing by reverting the fix and watching the
specific checks go red (4 of 89).

`event_ics_test.php` (48) closes the ICS half: the date-resolution contract, both
route handlers driven end to end, the calendar feed's membership and UID
distinctness, and ICS content (UTC stamps, RFC 5545 escaping, envelope). The
handlers finish through `exit()`, so they run in a subprocess via
`tests/support/ics_route_runner.php` rather than in-process — which means the
real handler is what gets tested, route params and all.

Two more defects, both about a date arriving from a URL:

4. **The ICS route served occurrences that do not exist.** The event page checked
   a requested date against the recurrence pattern and 404'd otherwise; the ICS
   route handed any date straight to `create_virtual_instance`. So a URL that
   404'd as a page still returned a calendar entry — published into software that
   would then remind people to attend. The same gap covered a date on a
   non-recurring event, a finished series (which emitted the series template as
   though it were an event), and malformed input. Fixed by giving both routes one
   resolver, `Event::resolve_instance_for_date()`, which also rejects a
   well-formed but impossible date (`2026-02-31`) that `strtotime` would
   otherwise roll forward to March 3 — serving one date's occurrence under
   another date's URL. A materialized row still wins over the pattern, because
   people may already be registered against it.
5. **`get_by_link(NULL)` returned an arbitrary record (`SystemBase`).** Multi
   option keys are read with `isset()`, which is false for NULL, so a null slug
   dropped the filter entirely and left an unfiltered query whose first row was
   returned. Platform-wide: every model with a slug. The live consequence found
   was `Product::prepare()`, which calls `get_by_link($this->get('pro_link'))` as
   a duplicate check — a product with no link yet matched some unrelated product
   and threw "This product link already exists." Fixed in `SystemBase` by
   returning false for an empty or null link.

**Still remaining in T24:** the check-then-insert in `materialize_instance` and
the missing database-level parent link on instance rows, both recorded in
`specs/deferred_fixes.md` (entries 4 and 5) because each is a schema change.

**T25 server_manager — DONE 2026-07-18.** The production deploy pipeline had zero
coverage of the two classes that carry it. Two db-tier suites, 121 checks:

- `job_command_builder_test.php` (71) — transport gating and the disabled-action
  reasons, input refusals, shell safety, path construction, step structure.
- `job_result_processor_test.php` (50) — dispatch, the API envelope, SSH status
  parsing, markers and SSL tokens, size formatting, and the end-to-end node
  update.

Shell safety is tested by pushing metacharacters (`; touch CANARY`, `$(…)`,
backticks, embedded quotes) through every builder that takes input, then
**running the emitted fragment through a real shell** in a scratch directory and
asserting the payload never fires. Asserting on the shape of the string would
pass just as well against a builder that silently dropped its input, so each case
also asserts the payload survives — quoted — before proving it inert. All
builders held: slugs are shape-checked, values are `escapeshellarg`'d, numeric
options are `intval`'d.

The parsers are tested against output that is not the happy case — truncated,
doubled, empty, error text — pinning that unrecognised output yields *no*
reading rather than a wrong one. A missing reading is visibly missing on the
dashboard; a misparsed one is indistinguishable from a real measurement.

One defect, and it is the quiet kind:

6. **The X-Forwarded-Proto patch never ran.** `escapeshellarg` returns its value
   *with* quotes, and `build_provision_ssl` interpolated the result inside an
   already double-quoted shell string. The emitted path was
   `/etc/apache2/sites-enabled/'mysite'-proxy.conf` — quotes and all — so
   `[ -f "$CONF" ]` never matched, the `sed` never ran, and the step still
   reported success (the Cloudflare branch ends in an `echo`, the certbot branch
   in `|| true`). Both branches were affected. Fixed by assigning the value to a
   shell variable and expanding `"${SITE}"`, which keeps it both quoted and
   correct; a site name containing a space is covered so the fix cannot regress
   into unquoted interpolation. Confirmed load-bearing: reverting turns 3 checks
   red, one of which shows the config file still reading
   `X-Forwarded-Proto "http"` after the patch ran.

   **Fleet impact: none, verified.** The deployed fleet was checked directly
   rather than inferred from the code path. No config anywhere on the shared
   host or the DNS nodes contains `X-Forwarded-Proto "http"`, and the
   `-proxy.conf` / `-proxy-le-ssl.conf` files this step targets do not exist on
   any of them. The live vhosts are `{sitename}.conf`, written by a mechanism
   that bakes `"https"` into both the `:80` and `:443` vhosts from the start, so
   the patch was never load-bearing for these sites. The bug would bite a node
   provisioned through `manage_domain.sh`'s docker-proxy path, which writes the
   header as `"http"` (correct pre-cutover) and relies on this step to flip it
   once TLS terminates in front. No current node took that route.

   **The reason it went unnoticed is the part worth fixing.** The step could not
   distinguish "rewrote the header", "already correct" and "never found the
   file" — all three exited zero and printed the same thing. A step that cannot
   fail visibly cannot be trusted when it reports success. The patch now emits
   one of `PROTO_PATCHED`, `PROTO_ALREADY_HTTPS`, `PROTO_CONF_MISSING` or
   `PROTO_HEADER_ABSENT`, each covered by a check that runs the emitted fragment
   against a real config file and asserts both the marker and the resulting file
   contents, including that a repeat run is a no-op rather than a duplicate.

Also surfaced, not fixed: the error log drops any error longer than 255
characters (`specs/deferred_fixes.md` entry 6), found when a fixture violated a
NOT NULL constraint and the logging of that failure itself failed.

**T26 UploadHandler / photos — DONE 2026-07-19.** The upload path is the one
place where a stranger chooses both the bytes on our disk and the name they land
under, and it had no coverage. One db-tier suite, `upload_safety_test.php` (116
checks), pinning the four things that have to hold independently: the name
cannot escape the upload directory or become a hidden/control-character name;
the extension allowlist is applied to the *sanitized* name rather than the one
the client sent; the stored type is read from the bytes, never from the claimed
Content-Type or the extension; and only genuine raster images render inline.

The type-detection layer came through clean and is worth saying so plainly: PHP
source named `photo.png` stores as `text/x-php`, HTML named `photo.gif` as
`text/html`, an SVG named `photo.png` as `image/svg+xml`, and a real PNG named
`notes.txt` is still detected as a PNG — so detection is actual detection, not
merely distrust of the client. Unrecognized bytes fail closed to
`application/octet-stream`. None of these are `is_image()`, and none render
inline.

Two defects, both in `UploadHandler`'s name pipeline:

7. **A NUL byte in an uploaded filename crashed the upload.** `trim_file_name`
   strips control characters only from the *ends* of the name, so a NUL in the
   middle survives — and it is invisible to everything downstream: `is_file()`
   reports false for a NUL path, so the name looks unused, and the allowlist
   regex still matches the trailing `.png`. It then reaches
   `move_uploaded_file()`, which rejects NUL paths with a `ValueError`. A value
   the client fully controls produced an unhandled fatal instead of a refusal,
   leaving the temporary file orphaned. Fixed by stripping `\x00-\x1f\x7f`
   throughout the name, which is what the existing comment already claimed the
   trim was doing. Reverting turns 7 checks red.

8. **`basename($path, null)` emitted a deprecation on every call.** The private
   `basename()` helper defaults `$suffix` to null and passes it straight to PHP's
   `basename()`, where a null suffix has been deprecated since 8.1 (this host
   runs 8.3). Every default-argument call — which is all of them — logged a
   notice. Defaulted to `''`.

Also pinned, because it is the assumption the rest of the surface rests on:
neither directory that receives uploaded bytes (`upload_dir`, the fast-serve
`static_files/uploads`) is inside the document root. This matters because
`File::_mint_unique_name` preserves whatever extension it is handed — including
`.php` — and the non-upload ingestion paths (`createFromBytes`, used by inbound
email attachments) never pass through `UploadHandler`'s allowlist at all. Today
a `.php` name is inert wherever it lands *only* because Apache cannot reach
either directory. The test asserts that placement directly, so a future move
under `public_html` fails the gate rather than becoming an execution hole.
`RouteHelper::serveStaticFile`'s hard `.php` rejection is covered as the second
layer.

**Schema fixes applied — 2026-07-19.** The three items held in
`specs/deferred_fixes.md` as entries 4–6 were all schema changes waiting on one
`update_database` run. That run happened; all three are live and the entries are
closed.

- **One materialized instance per parent per date.** `unique_with` on
  (`evt_materialized_instance_date`, `evt_parent_event_id`). Both columns are
  NULL on standalone events and recurring parents, so only instance rows are
  constrained — covered by a check that two NULL-pair events still save, since
  if Postgres collided repeated NULLs every non-recurring event after the first
  would fail.
- **A real parent link on instance rows.** `foreign_key` on
  `evt_parent_event_id` → `evt_events.evt_event_id` `ON DELETE CASCADE`, so an
  instance cannot outlive its parent even when deletion bypasses the models.
- **The error log stopped dropping its most detailed errors.**
  `err_error`, `err_message`, `err_description`, `err_file` and `err_path`
  widened from `varchar(255)` to `text`.

Preconditions were checked before the run rather than assumed: zero duplicate
(parent, date) pairs, zero orphaned instances, zero self-references. A second
`update_database` run reported no further table changes and only the one
pre-existing unrelated warning (`usa_users_addrs.usa_usr_user_id` nullability).

`materialize_instance()` gained the race recovery entry 4 called for, and **the
first version of it was wrong** — worth recording, because the mistake is the
kind that reviews pass. It caught `PDOException` and classified the failure by
SQLSTATE 23505, walking the previous-chain to find it. Two things defeated that.
`save()` rewraps driver errors and `handle_query_error()` throws a
`DatabaseException`, so no `PDOException` escapes at all; and more decisively,
`unique_with` generates an *application-level* pre-check that fires before the
database is ever reached, throwing a `DisplayableUserException` carrying no
SQLSTATE anywhere in its chain. So the recovery now does not classify the
exception. It asks the only question that matters — is the row there now? — and
returns it if so, rethrowing otherwise. That is robust to both refusal paths
and to any future third one.

Pinning that recovery needed a deliberate construction, and the first attempt at
*that* was also wrong: the guard at the top of `materialize_instance()` returns
the existing row long before the write, so a single-threaded test never reaches
the catch at all. The check passed with the recovery code deleted — it was
measuring nothing. A `RaceLoserEvent` subclass whose first lookup reports
nothing (the state every racing caller is genuinely in: it read before the
winner committed) reaches it deterministically, and now deleting the recovery
turns the suite red. `event_recurrence_test.php` 89 → 102 checks.

The new constraint also surfaced a defect in `ModelTester` itself, which is
worth more than the constraint that found it. Its composite-unique check proves
that a *different* combination is still accepted, and it built that different
value by appending `_different` to the original — valid for text, invalid for
every other column type. On the new `date` column it produced
`2023-01-01_different`, Postgres rejected the row, and the tester read that
rejection as "the constraint incorrectly rejected a different combination". The
check therefore fails whether the model is correct or not, and it would have
done so for any composite unique constraint involving a date, timestamp or
boolean column — this was the first one that existed. Fixed with a type-aware
`vary_scalar_value()` that shifts dates and timestamps by a year, negates
booleans, and keeps text inside the column's declared width (appending to a
value already at maximum length would fail on width, producing the same
misreading). `models_crud` 150 → 151.

**T27 PluginManager::sync() — DONE 2026-07-19.** This runs on every upgrade,
against every active plugin, and writes tables, columns, unique constraints,
indexes, foreign keys, migrations, deletion rules, menus and settings. Nothing
else in the platform changes so much in one unattended step, and a deploy is
precisely when nobody is watching. One db-tier suite,
`plugin_sync_test.php` (52 checks).

The collision guard is tested by planting a real file whose basename matches a
core `utils/` file inside an active plugin, not by inspecting the regex: the
guard must refuse, the message must name the file, the plugin and the other
owner, and — the part that matters — `sync()` itself must abort with *that*
error rather than some later one, because the guard's whole purpose is to fire
before any schema is mutated. Removing the file clears it, and a shared basename
outside `ajax/`, `utils/` and `tests/` is still allowed, so the guard does not
overreach.

Settings seeding is pinned on the invariant that matters at deploy time: an
operator changes a setting, ships an upgrade, and the upgrade must not hand it
back to the factory default. `seed_declared()` relies on `ON CONFLICT DO
NOTHING`, which relies on a unique index on `stg_name` — both are asserted,
since without the index the statement would error rather than skip. The
manifest rules (prefix, `legacy_core` opt-out, core collision, string-only
defaults) are covered, and every shipped plugin's declared settings are run
through them, so a bad manifest fails here rather than mid-deploy.

One defect, and it is the same shape as T25's:

9. **A plugin whose settings failed to sync produced no output at all.**
   `sync()` deliberately catches per-plugin failures so one bad plugin cannot
   abort an upgrade — an unreadable `plugin.json`, a manifest that violates the
   naming rules. The cost of that choice is that the failure becomes a string in
   `$result['settings_messages']` rather than an exception, and
   `utils/update_database.php` — the only caller on the deploy path — never read
   that array. It printed `table_messages` and `migration_messages` and
   discarded the rest, so a plugin's settings silently failed to seed while the
   deploy reported `✓ Plugins synced`. `deletion_rule_messages` was dropped the
   same way. Both are now printed, settings failures marked as warnings.
   Reverting turns 2 checks red.

Also pinned, because the failure mode is silent damage to an unrelated action:
`pruneOrphanedRules()` deletes any deletion rule whose source or target table
matches no declared model, and it runs on every sync. Model discovery scans the
filesystem rather than activation state, so a **deactivated** plugin's models
still count and its rules survive. If discovery ever became activation-aware,
deactivating a plugin would quietly delete the rules protecting its tables while
the tables and rows still existed — and the damage would show up later, on some
unrelated delete. The inactive `items` plugin on this box gives the check real
coverage rather than a vacuous pass.

**A silent-green test found along the way.** `email_security_digest` (safe tier)
read `mailbox_mail_hostname` to build its fixture's Authentication-Results
header, with a hardcoded fallback when the setting was empty. But the code under
test reads that same setting to decide whether to *trust* the line, and it has
no fallback — an empty authserv-id trusts nothing. So the fixture used the
fallback while the code used the empty value, and the DKIM-domain assertion
failed against working code. The setting ships with an empty default, so this
was a safe-tier check whose result depended on whether an operator had
configured this particular box. Fixed by pinning the setting in memory
(`harness_set_setting_mem`) so both sides of the comparison come from one value;
the test now passes with the setting still empty in the database, which is the
proof it is hermetic. Separately, `mailbox_mail_hostname` is currently blank on
dev and two corpus tools in `tests/tools/` document it as
`devmail.getjoinery.com` — restoring operator config is left to the owner.

**A second silent-green test, same shape.** `joinery_ai_chat_encryption` asserts
a Fortress chat routes to the local provider, but the local provider is built
from `joinery_ai_local_model`, which ships empty and is operator-configured. On
a box where it is unset the factory throws, and the check fails against routing
that is working correctly. Fixed the same way — pin the setting in memory, since
what the check is about is that Fortress routes locally, not that a particular
host is configured. Both of these were found only because an unrelated change
made the gate red; a safe- or db-tier check whose result depends on local
operator configuration is worth sweeping for deliberately, and that sweep is not
yet done.

The suite also asserts `sync()` is idempotent — a second run reports no further
table changes and no further migrations — because on a deploy the no-op path is
the common path, and a second run that still reports changes means something is
being rewritten every time.

**T28 Questions & surveys — DONE 2026-07-19.** One db-tier suite,
`tests/functional/surveys/survey_answer_test.php` (60 checks). Event checkout
already had `checkout_survey_test.php` covering *who* may answer; this covers
the other half — whether an answer is accepted at all, and what gets stored.

The subsystem turned out to be the worst-condition code the audit has touched
so far. Five defects, four of which made a documented feature simply not work:

10. **The integer rule made a question unanswerable.** `validate_answers()`
    tested `is_integer($answers)`, but answers arrive from a form post and are
    therefore always strings — `is_integer("5")` is false. Any question
    carrying the integer rule refused every possible answer, including a
    correct one. Now `ctype_digit`, which accepts exactly the set the
    client-side `digits` rule advertises for the same option, so the two sides
    cannot disagree. Reverting turns 2 checks red.

11. **The decimal rule was worse: it tested an undefined variable.**
    `preg_match('/^\d+\.\d+$/', $number)` — `$number` exists nowhere in the
    method. It evaluated to NULL, the match always failed, and every decimal
    question rejected every answer while emitting a PHP deprecation on each
    attempt. Now `is_numeric($scalar)`, mirroring the client-side `number`
    rule. Reverting turns 3 checks red.

12. **A length or bound rule on a multi-select question was a fatal error.** A
    checkbox-list answer arrives as an array; `strlen()` of an array is a
    TypeError in PHP 8, so an admin who put a `max_length` on a checkbox-list
    question took the page down for anyone answering it. The rules now measure
    the comma-joined form — which is what actually gets stored, so a length
    limit now bounds the stored value rather than an incidental representation.
    Reverting crashes the suite outright at check 25, which is the honest
    reproduction of the production symptom.

13. **A required question could be satisfied by silence.** This is the one that
    matters. `survey_logic()` validated only the questions whose field arrived
    in the post and was truthy — so omitting a required field entirely meant it
    was never validated, no message was produced, and the submitter was
    redirected to the finish page as though the survey were complete. Required
    was enforced by the browser and nowhere else, which is to say not enforced.
    Every question is now validated, present or absent; a question that passes
    validation but carries nothing to store still writes no row, so an optional
    blank answer behaves as before and does not overwrite an existing answer.
    Reverting turns 3 checks red.

14. **The input's typing cap ignored the admin's rule.** `output_question()`
    read `$this->get('max_length')` — not a column, so always NULL, so every
    field was capped at 255 characters no matter what the question's
    `max_length` rule said. An admin who allowed 500 got a browser that refused
    at 255 while the server would have accepted the full answer. Now read from
    the validation options, which is where the value has always lived.
    Reverting turns 1 check red.

The rule-engine section deliberately pins the server rule and the advertised
client rule together (`digits`/`ctype_digit`, `number`/`is_numeric`). Defects
10 and 11 were both *drift* between what the browser was told to enforce and
what the server enforced, and drift is the failure mode that re-emerges;
asserting the pair keeps them from separating again.

Two findings needed an owner decision rather than a fix, and are recorded as
entries 11 and 12 in `specs/deferred_fixes.md`: Multi collections silently
ignore filter options they do not implement (this subsystem asks
`MultiQuestionOption` for `'deleted' => false` in four live call sites, against
a class with no such filter and a table with no such column — harmless here,
but the same mechanism turns a misspelled ownership filter into a silent data
exposure), and `Question` carries a `qst_is_required` column that nothing
reads, while required-ness actually lives in the serialized `qst_validate`
blob.

**T29 bookings — DONE 2026-07-19.** One db-tier suite,
`plugins/bookings/tests/booking_flow_test.php` (123 checks), covering the
public flow through `book_logic`, the sessionless slot endpoint, the invitee
manage link, the host cancel path, and the availability rules underneath all of
them.

No defects. This is the first P3 subsystem to come through the audit clean, and
it is worth saying why rather than just recording the count. Bookings was built
after the calendar seam existed, so the properties that usually rot in this
codebase — a check that lives in one place and is skipped in another — are
here structurally rather than by convention. A booked slot disappears because
`BookingItemSource` projects it into the same busy blocks the generator already
subtracts, not because the booking code remembers to look at other bookings.
The posted slot is re-checked against live availability inside a per-host
advisory lock, so the browser's slot list is treated as a claim rather than a
fact. And the same window/cap/notice gates run whether a caller is drawing the
picker or submitting a form, so hiding a slot and refusing it are the same
code path — which is why every "refused, not just hidden" check in the suite
passes.

Ten separate reverts were used to prove the checks are load-bearing rather than
decorative, since a suite that finds nothing is exactly the suite most likely to
be asserting nothing: dropping the booking conflict re-check turns 11 checks
red; un-projecting bookings from the busy blocks, 14; skipping the per-period
caps, 3; not storing the invitee's local times, 2; not enforcing the
cancellation notice window, 4; not re-checking on reschedule, 2; skipping the
host-ownership check on cancel, 2; ignoring the already-resolved state, 1;
letting inactive types take bookings, 1; not recomputing local time on
reschedule, 1. Removing the bookable-window clamp turns 4 red and ignoring
minimum notice turns 2 red.

The DST section deliberately locates its own test dates by asking
`DateTimeZone` for the actual New York offset on each candidate day, then
asserts the UTC hour outright (13:00 in summer, 14:00 in winter). Deriving the
expected value with `convert_time` — the same function under test — would have
produced a check that passes no matter what the conversion does.

Three findings needed an owner decision rather than a fix and are recorded as
entries 15, 16 and 17 in `specs/deferred_fixes.md`: a booker who loses the race
for a slot still leaves a real user record behind, pending paid holds block
their slot but do not count toward per-day/per-week caps, and a host cancelling
a booking that is not theirs is told it worked.

**T30 Multi-collection CRUD — DONE 2026-07-19.** One test-db-tier suite,
`tests/models/multi_models_test.php` (143 checks), driving `MultiModelTester`
over every model that has a collection class — the half of the model estate
`models_test.php` has always excluded by hard-setting `SINGLE_TESTS_ONLY`.

The wiring was the easy part. The engine behind it asserted almost nothing,
and most of this entry is about what it took to make its green mean something.

**The assertion that mattered.** `test_multi_filtering()` checked that *at
least one* returned row matched the filter. That is satisfied by a collection
which ignores the option entirely and hands back the whole table, because the
row being looked for is somewhere in it. Proven, not assumed: a `MultiBookingType`
mutated to accept `user_id` and then discard every filter passed the suite
before the change and fails it after. It now asserts that **every** returned
row matches. This is the exact failure shape deferred entry 11 describes — an
option silently dropped returns a plausible superset, and where the option is
an owner id, the superset is somebody else's data.

**Why that assertion could not simply be turned on.** Applying it naively
produced 25 failures and zero bugs, because it assumed every option is an
equality match. Many are predicates: `created_before` is a bound,
`has_parent_menu_id` is IS NOT NULL, `active` maps to a status literal. The
tester now reads each option's own block in `getMultiResults()` and classifies
it — an array right-hand side binds the caller's value, anything else
interprets it — and only equality options earn the row-by-row assertion.
Classification is scoped to the option's block rather than searched file-wide,
because several classes expose two options over one column (`MultiAdminMenu`
has both `parent_menu_id` and `has_parent_menu_id` on `amu_parent_menu_id`) and
a file-wide search calls the predicate an equality.

**Four further engine defects, each of which was manufacturing false failures:**

18. **Strict comparison against a system that does not coerce.** `set()` keeps
    whatever PHP type it is handed while a loaded row comes back through PDO's
    typing, so `9774 === '9774'` failed for essentially every integer column.
    70 of the original 80 failures. Values are now compared by string form,
    with NULL kept distinct from the empty string.
19. **Fixtures were born soft-deleted.** The generator filled every field
    including `*_delete_time`, and most collections read that column
    unconditionally — so the fixture was invisible to the very query under
    test, which then reported the model broken for filtering correctly.
20. **Guessed column names asserted as fact.** When an option does not map to
    a plain column the detector guesses `prefix_option`, which is wrong for
    `MultiConversation`'s lateral join and for `MultiUserEncryptionWrapping`'s
    `vault_id` → `uew_uev_user_encryption_vault_id`. Those options are now
    skipped rather than failed.
21. **Options that take a raw SQL fragment were fed a scalar.**
    `MultiProductVersion`'s `prv_display_priority` is passed straight through
    as the condition — its only caller sends the string `'> 0'`, a documented
    filter format — so a generated `210` landed in the query as
    `prv_display_priority 210` and failed to parse. Such options cannot be
    driven from synthesised data and are dropped.

**One product-side find, recorded as deferred entry 18:** `fbb_sha256` is
`character(64)`, so a hash reads back blank-padded and any PHP-side equality
against a computed digest fails, even though the SQL comparison succeeds.

**The leak that made the gate red.** Two separate fixture leaks were fixed, and
they matter more than any single assertion because both convert one failure
into a permanent one. `MultiModelTester` overrides `test()` and never called
`teardown_created_parents()`, so every foreign-key parent it built survived the
run — and those parents carry deterministic values, so the leftover Schedule
row `(sch_subject_type='a', sch_subject_id=4957)` was precisely the row
`models_crud`'s own composite-unique test inserts next time. One run of the new
suite was enough to make the test-db tier red until that row was deleted by
hand. Separately, `ModelTester`'s unique-constraint cleanup sat after a
`test_fail()` that throws, so a failing constraint test abandoned its fixture
and then collided with it forever after; cleanup now runs in a `finally`. The
Multi fixtures are also removed outright rather than soft-deleted, since a
soft-deleted row still occupies any unique constraint that does not exclude
deleted rows.

Verified by running the whole tier three times in succession: 297/297 each
time, with the schedule table left at exactly the two rows it started with.
Repeatability was the point — before this, the second run of any pair failed.

**What this suite cannot catch, stated plainly:** it reads each class's own
source as the specification, so a filter wired to the wrong column reads as
correct — the tester learns the intended column from the same line that
implements it. It also cannot see deferred entry 11's other half, where a
*caller* passes an option the class never implemented; that is a call-site
problem no per-class suite reaches.

**T31 Core unit tests — DONE 2026-07-19.** Four suites, 156 checks, covering
the contracts every page and every model inherits:

- `tests/unit/time_conversion_test.php` (28, safe) — `convert_time`,
  `time_shift`, `time_ago_or_time`.
- `tests/unit/logic_result_test.php` (35, safe) — `LogicResult` and
  `process_logic`.
- `tests/unit/descriptor_validator_base_test.php` (54, safe) — base type
  coercion, required/default handling, the whitelist property. The existing
  `descriptor_validator_pipeline_test.php` covers only the pipeline additions
  (enum, min/max, max_length, nested arrays).
- `tests/unit/system_base_lifecycle_test.php` (38, db) — the Active Record
  rules `models_crud` cannot express, because it drives models generically
  rather than asserting the contract a developer has to hold in their head.

**One defect found and fixed: an empty timestamp rendered as the current time.**
`convert_time()` guarded against NULL but not `''`, and DateTime reads the empty
string as "now". A blank optional timestamp therefore rendered as this moment —
not a visibly wrong value a reader would question, but a plausible one saying
the thing just happened. `time_shift('')` had the same hole, reporting a
deadline a week out for a record with no start date. Both now refuse an empty
input the way they already refused NULL. Reverting either guard turns a check
red.

**One defect found and deferred (entry 23): soft-delete and `unique_with`
disagree.** The application pre-check inside `save()` excludes soft-deleted rows
and reports a freed unique pair as available; the database constraint does not
exclude them and refuses the insert. Deleting a record and creating a
replacement with the same identifying values fails with a raw
`SQLSTATE[23505]` reaching the user as an error page, precisely because the
pre-check whose job is to turn that into a readable message says there is no
problem. The platform already has the fix pattern — `pkc_credential_id`
declares a partial unique index with `'where' => 'pkc_delete_time IS NULL'` —
it is simply not what `unique_with` emits. Pinned as current behaviour so the
fix is a deliberate test change.

The rest is characterization of behaviour that is correct but surprising, which
is the category most likely to be "fixed" into something wrong: a timestamp
string carrying its own offset overrides the `$fromtz` argument; a DateTime
argument ignores it entirely; `time_shift('1 month')` on Jan 31 overflows to
March 3 rather than clamping to February, and lands a day earlier in a leap
year; a primary key comes back from PDO as a string, so `$model->key === 5` is
false for row 5; setting an undeclared field logs an exception no caller sees,
keeps the value readable in memory, and drops it silently at save; and declared
defaults do not exist on an object until it has been saved and reloaded.

**Two intermittent gate failures were found and fixed on the way**, both
unrelated to T31's own suites and both surfaced only because the runner now
names its failures — without that, each would have been another unattributable
red like deferred entry 13.

- `tests/integration/oauth/secret_box_test.php` tampered with a ciphertext by
  flipping its **last** base64 character. When the encoded length is not a
  multiple of three that character carries fewer than six significant bits, so
  some flips decode to byte-for-byte identical ciphertext — nothing was
  tampered, decryption correctly succeeded, and the check failed. Measured at
  roughly one run in eight. It now flips a character mid-ciphertext and, more
  importantly, **asserts the tamper actually changed the decoded bytes before
  asserting decryption fails**: a check that a *non*-tampered blob fails to
  decrypt is worse than no check, because it reads as proof the guard works.
  Twenty consecutive runs green after the fix.
- `MultiMigration` in the Multi suite failed whenever the generator produced a
  version with one decimal place: `mig_version` is `numeric(6,2)`, so 1092.9 is
  read back as `'1092.90'`. Same number, same row, different string. The
  comparison now normalises numeric strings, by trimming trailing fractional
  zeros textually rather than casting to float so bigint keys beyond float's
  exact-integer range still compare correctly. This is the same shape as the
  `character(64)` padding found in T30 — the database's canonical
  representation of a value is not PHP's, in more than one type.

Two checks were rewritten after falsification showed they proved less than they
claimed. "A duplicate pair is refused by save()" passed with the entire
application pre-check deleted, because the database constraint refuses it too —
it now asserts the exception *type*, which is what distinguishes a renderable
form error from an error page. And the soft-delete exclusion is now asserted as
a pair with `$search_deleted = true`, since either half alone is satisfiable by
a broken filter: a check that always returns 0 passes the first, one that never
filters passes the second.

**Still remaining (documented in the work plan below):**
the full cloud 6→4 FILE merge
(the D20/D21 DEFECTS are fixed; merging characterization into engine risks the
real-BlobStorageProfile db-tier coverage and is deferred), the directory/naming
taxonomy reorg, and the P3 greenfield suites
(account security, event_manager, server_manager, UploadHandler, PluginManager,
surveys, bookings, Multi collections, core-unit tests). These are larger and were
left for a follow-up pass rather than risk half-built suites.

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

**T19. Extract per-area fixture libs.** ✅ DONE (see progress log): DNS, LLM, cloud, vault-client, mailbox raw-user, and the mailbox purge-boilerplate fixtures are all extracted and migrated. `plugins/mailbox/tests/lib/mailbox_test_fixture.php`
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

**T21. Unify the two SSRF guards.** ✅ DONE (see progress log). Merge UrlSafetyValidator and
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
  email verification, step-up) — the highest-risk zero. ✅ DONE (see progress log).
- **T24. event_manager** (registration, waiting lists, ICS, checkout+survey path) —
  its tests/ dir exists and is empty. Est. L.
- **T25. server_manager** (JobCommandBuilder, JobResultProcessor) — the production
  deploy pipeline, zero tests. Est. M.
- **T26. UploadHandler / photos** — the legacy upload attack surface. ✅ DONE (see progress log).
- **T27. PluginManager::sync()** — runs on every deploy, mutates schema. ✅ DONE (see progress log).
- **T28. Questions & surveys** — event checkout depends on it. ✅ DONE (see progress log).
- **T29. bookings /book flow** — double-booking/timezone; no tests dir. ✅ DONE (see progress log).
- **T30. Multi-collection CRUD suite** — the entire Multi surface is untested
  (SINGLE_TESTS_ONLY); high value given the documented `->results` foot-gun.
  ✅ DONE (see progress log).
- **T31. Core unit tests** — process_logic/LogicResult, convert_time/time_shift,
  SystemBase lifecycle, DescriptorValidator base coercion. ✅ DONE (see progress log).

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
