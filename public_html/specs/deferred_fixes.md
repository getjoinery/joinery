# Deferred Fixes

**Status:** Open — a standing list, not a project
**Created:** 2026-07-18

Known defects and gaps that are understood, reproducible, and deliberately not
being fixed yet. Each entry says what is wrong, what it costs to leave alone,
and what closing it would take, so a later decision starts from the finding
rather than rediscovering it.

An entry leaves this file when it is fixed (move the detail into the spec that
covers the fix) or when it is decided against (say so and keep the entry, so it
is not re-raised).

---

## 1. Concurrent checkout can overbook an event by one

**Deferred:** 2026-07-18 (owner decision)

`FulfillmentProvider::checkAvailability()` counts registrants, the charge runs,
then `fulfill()` seats the buyer. Nothing holds a lock across those steps, so two
checkouts that read the same remaining-seat count both proceed and the event ends
up one over its limit. The window is small — it needs two buyers inside the same
few hundred milliseconds on the last seat — but it is real and it is silent.

**Cost of leaving it:** an event occasionally seats one more person than the room
holds. There is no error, no log line, and nothing reconciles it afterwards: the
overbooking is only discovered by counting attendees.

**Why it is not a constraint problem.** Capacity is a count, not a uniqueness
rule, so no `unique_with` or database constraint expresses it. `WaitingList`
carries a real unique constraint on (event, user) precisely because *that* is a
uniqueness rule.

**What closing it takes:** hold a lock spanning the count and the order write —
either `SELECT ... FOR UPDATE` on the event row, or a Postgres advisory lock
keyed on the event id — inside `cart_charge_logic`. That path is long and already
carries its own transaction structure (the pre-charge/post-charge phases), so
this is careful work rather than a small patch. Alternatively, accept the
overbook and add a reconciliation report that flags over-capacity events to an
admin.

Pinned today by `plugins/event_manager/tests/event_capacity_test.php`, which
asserts the single-threaded arithmetic only. A concurrency test belongs with the
fix.

---

## 2. Bundle purchases bypass the event capacity check entirely

**Deferred:** 2026-07-18 (owner decision)

`EventRegistrationFulfillment::checkAvailability()` returns "available" for any
bundle (`$ref <= 0`). A bundle seats every member of a group, and the membership
is not resolved until `fulfill()` runs, so the number of seats it will consume is
not known while the purchase can still be refused.

**Cost of leaving it:** a bundle can seat any number of people into a full event.
Unlike entry 1, this is not a narrow race — it is unbounded and repeatable.

**What closing it takes:** resolve the group size during checkout so the seat
count is known before the charge, then treat a bundle as consuming that many
seats. The complication is that group membership can change between purchase and
fulfillment, so "how many seats did this bundle buy" needs a defined answer
(membership at purchase time, most likely, recorded on the order item).
Alternatively, declare bundles exempt on purpose and say so in the event admin UI
so an organizer running a capped event knows not to sell bundles into it.

Pinned today by `event_capacity_test.php` as current behavior ("a bundle
reference is not capacity checked"), so a change here is a deliberate test change.

---

## 3. API sign-in does not enforce the activation gate that web sign-in does

**Deferred:** 2026-07-18 — pending owner decision on which side is correct

`login_logic` refuses an unactivated account when `activation_required_login` is
on. `POST /api/v1/auth/login` (`ApiAuthEndpoint` → `ApiAuth::attemptLogin`) has no
equivalent check, so the same account that is refused a web session is issued API
session keys.

**Cost of leaving it:** the activation requirement is bypassable by anyone who
calls the API instead of the login form, which includes the native apps. Whether
that is a hole or a deliberate carve-out for first-launch mobile flows has never
been written down.

**History:** searched at deferral time and no spec decides this.
`specs/implemented/security_audit.md` §361 raised the adjacent question (accounts
usable before verification) and recommended enabling the setting, which is done.
`specs/implemented/api_auth_gate_unification.md` is explicitly authorization-only
and behavior-preserving. `specs/implemented/user_session_api_keys.md`, which
created the endpoint, does not mention activation. The only acknowledgement
anywhere is a comment in `tests/functional/api/browser_session_test.php:47`,
written 2026-07-02 during the browser-session credential work, explaining why
that test activates its fixture user. It is undocumented drift, not a recorded
decision.

**What closing it takes:** either apply the gate in `ApiAuth::attemptLogin` so
both doors agree, or record the exemption deliberately — in
`docs/account_security.md`, with the reason — and pin it with a test so it stops
looking like an oversight.

---

## 4. models_crud fails on SubscriptionTier — RESOLVED 2026-07-19 (torn test-DB copy)

**Resolved:** 2026-07-19. The untruncated message was `Column
'sbt_subscription_tier_id' exists but is not set as primary key`: the test
database itself was missing the primary keys on `sbt_subscription_tiers` AND
`usr_users`. Cause: `copyLiveToTest()` (adm/admin_test_database.php) ran
`pg_dump | psql` straight into the live test-DB name while a concurrent test
run held connections — constraint DDL failed mid-restore and the function
could not see it (a pipe's exit code is psql's, and psql without ON_ERROR_STOP
exits 0 past errors). Fixed in admin_test_database.php v1.1: restore into a
staging database with `pipefail` + `ON_ERROR_STOP=1`, then terminate/drop/
rename swap; any failure is now loud and leaves the previous copy untouched.
After a clean resync, models_crud passed 151/151 twice. The ModelTester
HTML-truncation gripe below stands — it cost most of the diagnosis time.

**Original entry (for context):**

`tests/models/models_test.php` reports one sub-check failure on
`SubscriptionTier`, truncated to `key in table 'sbt_subscription_tiers'` (the
full text is swallowed because ModelTester emits it wrapped in HTML). The suite
was 151/151 earlier the same day and regressed after that point.

**What was ruled out:** it is not the `vary_scalar_value()` change made that day
— the failure reproduces with `tests/models/ModelTester.php` stashed. It is not
a database constraint: nothing references `sbt_subscription_tiers` with a
foreign key, so a delete cannot be blocked at that layer. The rows in the table
are seeded plans, not leftover fixtures.

**What points elsewhere:** the warning printed alongside the failure names
`pro_products.pro_sbt_subscription_tier_id`, and
`plugins/store/data/products_class.php` is one of many files being actively
changed in the working tree by concurrent store work (optional donation
pricing). `CustomerCloudProvision` failed and then self-resolved the same
afternoon when that author's `$test_fixture` declaration landed, so the tree was
demonstrably shifting under the gate.

**Cost of leaving it:** the db gate is red, which is the state that makes every
other red result unreadable. Attribution has to happen before any db run is
believed.

**What closing it takes:** re-run once the concurrent store/server_manager work
is committed and settled. If it survives that, get the untruncated message first
— ModelTester should emit sub-check failures as plain text rather than HTML, so
the harness reports the whole sentence instead of its tail.

---

## 5. Two tests were green or red depending on operator configuration, and the sweep for others is not done

**Deferred:** 2026-07-19 — the two known cases are fixed; the sweep is not

`email_security_digest` (safe) and `joinery_ai_chat_encryption` (db) each read a
setting that ships empty and is operator-configured, then asserted behaviour
that depends on it being set. Both are now hermetic — they pin the setting in
memory with `harness_set_setting_mem` — but they were found by accident, because
an unrelated change turned the gate red and the failures had to be attributed.

**Cost of leaving it:** this class of test does not fail loudly, it fails
*confusingly* — the assertion goes red while the code under test is working
correctly, on one box and not another. It also fails in the other direction:
the same test passes for a reason unrelated to what it claims to prove. A gate
that behaves differently per box is a gate nobody trusts, which is the exact
problem the P0 tier of `specs/test_estate_audit.md` set out to fix.

**What closing it takes:** a sweep of every safe- and db-tier test for
`get_setting(` calls whose result feeds an assertion, rather than a fixture the
test fully controls. Each hit is then either pinned in memory or moved to a tier
where the configuration is a declared `needs:`. Mechanical to find, and worth
doing in one pass rather than one accident at a time.

---

## 6. ModelTester cannot generate valid values for code-enforced enum columns

**Deferred:** 2026-07-19

`vary_scalar_value()` and `generate_different_value()` now respect a column's
declared *type* — dates shift, booleans negate, text stays inside its width.
Neither can respect a constraint that exists only in PHP: a `varchar(10)` column
that `prepare()` restricts to `'order'` or `'admin'` will still be handed
`'order_upd'`, and the model's own validation rejects it. The failure reads as
the model being broken.

**Cost of leaving it:** every model with a code-enforced enum needs a manual
`$test_fixture` declaration naming a safe `update_field`, and nothing prompts
the author to add one. The test simply fails after the fact, on someone else's
gate run — which is how `CustomerCloudProvision` surfaced.

**What closing it takes:** either declare the allowed set in the field spec
(`'allowed_values' => ['order','admin']`) so the tester and the model read one
source — which also lets `update_database` consider a real CHECK constraint —
or have ModelTester skip fields whose model rejects a generated value, and
report them as needing a `$test_fixture` rather than as failures. The first is
better: it removes the guesswork instead of tolerating it.

---

## 7. A stray harness fixture user appeared mid-gate and its source was not identified

**Deferred:** 2026-07-19 — transient, currently clean

One db gate run failed `referential_integrity`'s "no leftover harnesstest_%
users" check with a single row. The row was gone by the next query, and the
check has passed on every run since.

**Most likely cause:** a falsification run deliberately crashed mid-test that
day (several were run to prove new checks were load-bearing), and a crash
between `make_user()` and teardown leaves exactly this. That is a testing
artifact rather than a product defect, and it was not reproduced.

**Cost of leaving it:** small but not zero. The guard exists because leaked
fixtures are how the vault-suite flakiness started, so a leak whose source is
unknown is worth one look. If it recurs without a deliberately crashed run
nearby, the leak is real and teardown has a hole.

**What closing it takes:** if it reappears, capture the user row's id and
adjacent rows before anything else runs — the id ordering identifies which
suite created it.

---

## 8. Two operator settings on dev sit at factory defaults and may not be intentional

**Deferred:** 2026-07-19 — needs an owner decision, not a code change

Surfaced while attributing test failures, not by looking for it:

- `mailbox_mail_hostname` is empty. It was created 2026-07-06 and has never been
  updated, so it appears never to have been configured on this box.
  `tests/tools/fetch_spamassassin_ham.php` and `fetch_phishing_pot.php` both
  document the intended value as `devmail.getjoinery.com`, and both note it MUST
  equal this setting.
- `joinery_ai_local_model` is empty and `joinery_ai_local_base_url` is
  `http://localhost:11434/v1`. Both rows were created 2026-06-20 and last
  written 2026-07-18 21:05. The recorded dev configuration for the local LLM
  host is the Mac Studio at `100.69.133.69:11434/v1` with a Qwen model, so these
  look reset rather than deliberately cleared.

**Cost of leaving it:** low and bounded, now that the two tests that depended on
these have been made hermetic — which is exactly why it needs recording rather
than fixing quietly. Nothing in the gate will complain about them again, so if
the values matter for real use (inbound mail DKIM attribution, local-model
chat), the blanks will be discovered by a person hitting the feature.

**Why this is not being changed unilaterally:** writing operator configuration
is not a test-estate change, and the correct values are inferred from tool
comments and prior notes rather than known. `seed_declared()` uses `ON CONFLICT
DO NOTHING`, so no deploy will restore them — a human has to set them.

**What closing it takes:** confirm whether each blank is intentional. If not,
set them through the admin settings page. If the local-LLM reset has a cause
worth finding, the 2026-07-18 21:05 write timestamp is the starting point.

**Addendum 2026-07-19 (entry 8):** restoring the recorded values may no longer
be enough — the Studio's Ollama now lists only re-tagged models
(`qwen3.5-9b-pintest:latest`, `qwen3.6-35b-a3b-cap24k:latest`), so
`joinery_ai_local_model` needs whichever tag is current, not the old
`qwen3.5:9b-nvfp4`. The endpoint itself is up and reachable at
`100.69.133.69:11434/v1`. Until settings and tags agree, the
`joinery_ai_chat_encryption` check "a Fortress chat on a local model resolves
to the local provider" stays red on dev.

---

## 9. vse_visitor_events has no index on vse_usr_user_id (1.5M rows and growing)

**Deferred:** 2026-07-19

`vse_visitor_events` holds ~1.57M rows and its only index is the primary key.
Every per-user query — including the `SELECT COUNT(*) ... WHERE
vse_usr_user_id = ?` that `permanent_delete()`/dry-run issues when a user is
deleted — is a full table scan. During the 2026-07-19 model-suite runs, three
of these COUNTs sat `active` for minutes and held connections on the test
database (which is also what blocked `dropdb` during the copy investigation).

**Cost of leaving it:** user deletion (and any admin/analytics view that
filters events by user) gets slower every day the table grows; long scans hold
connections and locks that interfere with unrelated operations, as they
demonstrably did here.

**What closing it takes:** add an index declaration for `vse_usr_user_id` in
`data/visitor_events_class.php` field specs so `update_database` materializes
it. One-line class change plus a schema sync; verify the analytics write path
doesn't mind the extra index maintenance (this table is insert-heavy).

---

## 10. Deleting a SubscriptionTier default-deletes referencing products

**Deferred:** 2026-07-19

ModelTester warns on every run: `SubscriptionTier` has an empty
`$permanent_delete_actions` while `pro_products.pro_sbt_subscription_tier_id`
references it, so a tier's permanent delete falls back to the default action
for referencing rows. A tier is a billing plan; products pointing at it should
survive its removal (nullify or block), not ride along on the default.

**Cost of leaving it:** deleting a subscription tier from the admin can take
its products with it, silently. Rare operation, expensive surprise.

**What closing it takes:** declare the relationship in
`SubscriptionTier::$permanent_delete_actions` (likely `set_null` on
`pro_sbt_subscription_tier_id`, or refuse deletion while products reference
the tier), then the ModelTester warning disappears on its own.

---

## 11. Multi collections silently ignore filter options they do not implement

**Deferred:** 2026-07-19

`Question::output_question()` asks for a question's answer choices with
`new MultiQuestionOption(array('deleted' => false, 'question_id' => $this->key))`
in four live call sites (and four more in a commented-out block).
`MultiQuestionOption::getMultiResults()` implements no `deleted` option, and
`qop_question_options` has no delete column at all — verified against the live
schema. The filter does nothing and no one is told.

Today the result is still correct: question options are hard-deleted, so every
row is live and "exclude deleted" and "exclude nothing" agree. The hazard is
the mechanism, not this instance. `SystemMultiBase` reads only the option keys
a subclass names and drops the rest, so any filter key that is misspelled,
renamed, or never implemented degrades to *no filter* — the collection returns
more rows than the caller asked for, silently. For an ownership filter
(`user_id`, `owner_id`) that failure mode is a data-exposure bug that reads as
correct code, and the reviewer's eye slides right over it because the call
site plainly says what it wanted.

**Cost of leaving it:** every Multi call site is trusted-by-appearance. A
future refactor that renames a filter key in `getMultiResults()` without
updating callers widens those queries instead of breaking them, and nothing —
not a test, not a log line — reports it.

**What closing it takes:** a decision on strictness, because the blast radius
is platform-wide. Options, roughly in order of appeal:

1. **Throw on unknown option keys** in `SystemMultiBase`. Correct and loud, but
   it will surface every existing dead filter across the codebase at once,
   some of them in paths with no test coverage. Needs a survey of current
   offenders before it can be turned on.
2. **Log unknown option keys** (dev/test only), leaving behaviour unchanged.
   Cheap, reversible, and it produces exactly the survey option 1 needs. This
   is the natural first step whichever end state is chosen.
3. **Leave it and fix the call sites found by hand.** Closes this instance,
   not the class.

The four live `'deleted' => false` arguments in `data/questions_class.php`
should be dropped either way — they promise a filter that does not exist.
They are deliberately left in place for now so this entry has a reproducer.

---

## 12. A question stores its required-ness in two places and reads only one

**Deferred:** 2026-07-19

`Question` declares a `qst_is_required` boolean column, and nothing in the
codebase ever reads it. Whether an answer is actually required is decided by a
`required` key inside `qst_validate`, a PHP-serialized blob in a text column —
that is what `validate_answers()` checks, what `output_js_validation()`
advertises to the browser, and what the new
`tests/functional/surveys/survey_answer_test.php` pins.

So the admin-facing column and the enforced rule can disagree, and the column
is the one that looks authoritative.

**Cost of leaving it:** an admin who sets required-ness through whichever
surface writes `qst_is_required` gets a question that is not required, with no
error and no way to tell from the question record which value is in force.
Worth confirming against the question admin page before deciding — it may be
that nothing writes the column either, in which case this is dead schema
rather than a live contradiction.

**What closing it takes:** decide which one is the source of truth.
`qst_validate` is the working implementation and carries all the other rules
(lengths, bounds, numeric type), so the cheap resolution is to drop
`qst_is_required` from the field specs as dead schema. The more principled
resolution is the reverse — promote the rules out of a serialized blob into
real columns, where they can be queried, indexed, and validated — but that is
a schema migration for the whole rule set, not a one-column change.

---

## 13. One db-tier test failed once, was never identified, and has not recurred

**Deferred:** 2026-07-19

A full `php tests/run.php db` reported `Tests: 126 passed, 1 failed of 127`.
The failing test was never named: the run had been invoked through
`| tail -30`, which discarded everything above the summary, and the summary
reports counts rather than names. An immediate re-run of the same tier, same
box, same working tree came back `127 passed, 0 failed of 127` — 3384 checks,
`RESULT: PASS`. The failure has not reappeared since.

So there is no reproducer, no name, and no evidence of what broke. What is
known: it was one test out of 127, it was not one of the four suites added that
day (`survey_answer`, `upload_safety`, `plugin_sync`, `event_recurrence` all
pass standalone and in-tier), and another agent was committing to the same
working tree throughout both runs.

**Why this is written down rather than dismissed:** an unattributed red that
turns green on its own is the exact signature of the two worst gate problems
this project has had. The db-tier vault flakiness looked like noise for days
before it turned out to be sequences being set backwards, which re-attached
orphaned child rows to reused primary keys. `CustomerCloudProvision` looked
like a real regression until it self-resolved when another agent's
`$test_fixture` landed. Both were dismissed as flakes first. A green re-run is
not evidence that nothing is wrong; it is evidence that whatever is wrong is
intermittent, which is worse.

**Cost of leaving it:** if it is real and ordering- or timing-dependent, it
will surface again at the least convenient moment — most likely on the
pre-deploy gate, where a red of unknown provenance either blocks a deploy or,
worse, gets waved through as a known flake. The habit of waving reds through is
the actual risk here, more than the defect itself.

**What closing it takes:** one full db run at a quiet moment, with no
concurrent work in the tree, captured to a file rather than piped through
`tail`. If it recurs it names itself and becomes an ordinary bug. If several
clean runs pass, close this entry as a transient — but close it deliberately,
with the runs recorded, rather than by forgetting.

Two process notes worth keeping regardless of the outcome:
- Never invoke the runner through `tail`. Capture the full output to a file;
  the summary line alone cannot tell you what failed.
- **Fixed 2026-07-19.** `tests/run.php` reported failure counts but not
  failure names in its summary, which is what made this entry necessary at
  all. It now prints a `Failed:` block naming each failed test and its path
  immediately above `RESULT: FAIL`, matching how skipped and undeclared tests
  were already listed. Verified by forcing a check red and confirming the
  block appears within the last few lines of output — so even a run read
  through a pager or a `tail` names its failures.
