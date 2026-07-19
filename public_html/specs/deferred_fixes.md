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

**Update 2026-07-19 (T30).** Half of this is now covered, and it is worth being
precise about which half. `tests/models/multi_models_test.php` asserts that
every row a filter returns satisfies that filter, so a collection which
*declares* an option and then fails to apply it is caught — verified by
mutating `MultiBookingType` to accept `user_id` and discard its filters, which
the suite now fails and previously passed.

What remains uncovered is exactly the case this entry opens with: a **caller**
passing an option the class never declared. No per-class suite can see it,
because from the collection's side nothing happened — the key was read by no
one. That still needs the strictness decision above, and option 2 (log unknown
option keys in dev) is still the natural first step, now with a green Multi
suite underneath it to catch any fallout from tightening.

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

## 14. Postgres on the jeremytunnell VPS accepts connections from the entire internet

**Found:** 2026-07-19, during the read-only step-8 mail-readiness probe of node
176 (jeremytunnell-vps, 45.79.204.178). Its ufw shows `5432 ALLOW IN Anywhere`
(v4 and v6), and Postgres is listening on the external interface — verified
reachable from the dev box (connection accepted, password prompt reached).
Password auth is the only gate on a box that now serves jeremytunnell.com and
is about to hold real mail.

**Scope:** specific to node 176's install path. VPS A (45.56.119.74) refuses
5432, so the exposure came from something in the bare-metal and/or from-backup
branch of the install — possibly a rule added so the control plane could push
the node-32 restore, never removed. The other bare-metal-branch findings from
the same probe (no ufw 25/tcp rule, opendmarc absent, `hostname -f` =
`localhost`) suggest that branch's host-setup steps diverge from the docker
path in more ways than this one.

**Cost of leaving it:** the box's database is one credential guess or one
pg_hba misconfiguration away from the internet, and it is the box that step 8
turns into a mail server holding personal mail. Exposure also invites constant
credential-stuffing noise in the logs.

**What closing it takes:** identify what legitimately needs remote 5432 (if
the control plane needs it for backup/restore pushes, restrict the rule to the
control plane's IP; if nothing does, delete the rule and set
`listen_addresses` back to localhost). Then audit the bare-metal install
branch so the next born node does not reproduce it, and add the check to the
node health/status surface so an exposed 5432 shows up as a finding rather
than a probe surprise. Firewall change on a live box — do it attended.

## 15. A booker who loses the race for a slot still leaves a user account behind

**Found:** 2026-07-19, while writing the T29 bookings suite. Confirmed by
probe: two submissions for the same hour, the second refused with "that time
was just taken", and the losing email still has a `usr_users` row afterwards.

`book_logic()` matches-or-creates the invitee from their email *before* it
opens the transaction and re-checks the slot. So the order is: create the
person, take the lock, discover the slot is gone, roll back the booking — and
the person stays. Every lost race, every mistyped-then-corrected attempt at a
contested time, mints a permanent user record for an appointment that never
happened.

**Why it is not obviously a one-line fix.** Moving the match-or-create inside
the transaction would make the rollback clean it up, but it also moves it
under the generic `catch` that currently returns "Could not complete the
booking" — losing the specific "We couldn't use that email address" message a
failed `User::save()` produces today. The other direction (create it lazily,
only after `$open` is confirmed) keeps the message but means the invitee is
created while holding the advisory lock, lengthening the critical section for
every booking to save litter on the rare one. Which trade is right depends on
whether these ghost accounts are actually a problem — they are inactive,
permission 0, and a returning booker with the same email is matched to the
existing row rather than duplicated, so they may be harmless clutter.

**Cost of leaving it:** the user table accumulates records for people who
never booked, which distorts any member count, any "users who have booked"
segment, and any email list built by querying users. It also means a contested
booking page is a way for an outsider to create rows in `usr_users` at will —
not an account they control, but rows nonetheless.

**What closing it takes:** decide whether ghost invitees matter. If they do,
move the creation after the conflict check and decide what the invalid-email
path says. If they do not, say so here and leave the code alone.

## 16. Pending paid holds block their slot but do not count toward booking caps

**Found:** 2026-07-19, T29. Confirmed by probe: a `BOOKING_STATUS_CREATED`
booking with an unexpired `bkn_hold_expires_time` on a type with
`bkt_max_per_day = 1` removes its own hour from availability, but the rest of
the day stays open — the cap of one is not reached.

Two pieces of code disagree about what a hold is.
`BookingItemSource::getItems()` treats CREATED-with-live-expiry as occupying
the slot, which is why the hour disappears. `NativeSchedulingProvider::
applyCaps()` counts only `BOOKING_STATUS_BOOKED`, so the same row is invisible
to the cap. A host who set "two a day" can end up with two confirmed bookings
plus any number of live holds on the same day.

**Why it needs a decision rather than a fix.** Whether a hold should consume a
cap slot is a policy question, not an oversight to correct blindly. Counting
them means an abandoned checkout eats the host's daily allowance until the
hold expires. Not counting them means the cap is a cap on confirmations, not
on the day — which is defensible, but then availability and caps are answering
two different questions and that should be deliberate and documented rather
than incidental.

**Cost of leaving it:** the cap silently means something different from what
the admin field implies, and only on days where a checkout was started and not
finished — so it will present as an occasional inexplicable overbooking rather
than a reproducible rule.

**What closing it takes:** decide the policy, then make the two call sites
agree — ideally by giving `Booking` one predicate ("does this row occupy the
host's time") that both the item source and the cap counter call, so they
cannot drift again. Document the answer in the bookings overview.

## 17. A host cancelling someone else's booking is told it worked

**Found:** 2026-07-19, T29. Pinned in `booking_flow_test.php`: the booking
correctly is *not* canceled, which is the part that matters, so this is a
truthfulness bug rather than a security one.

`my_bookings_logic()` checks ownership before cancelling — `if ($booking->key
&& (int)$booking->get('bkn_usr_user_id_booked') === (int)$user_id)` — but the
redirect to `/profile/bookings/my_bookings?canceled=1` sits outside the `if`.
A host who posts another host's booking id gets the success banner and no
cancellation. The same is true for an id that does not exist at all.

**Why it is not a drive-by fix.** The page has no error channel: its
`page_vars` carry `session`, `bookings` and `canceled`, so surfacing a failure
means adding an errors key and rendering it in the view, or deciding that the
right answer is a 404 rather than a message. That is a small view change, but
it is a view change with a wording choice in it, and the booking-manage page
already has its own error convention that this one should probably match.

**Cost of leaving it:** low, and bounded by the fact that nothing actually
happens. The realistic case is not an attacker but a host acting on a stale
list — the booking was already canceled, or the page was open in another tab —
who is told the cancellation succeeded and stops thinking about it.

**What closing it takes:** decide between an inline error and a 404, then move
the redirect inside the ownership branch and add the corresponding check to
`booking_flow_test.php`, which currently asserts only that the booking is
untouched.

## 18. A stored sha256 reads back padded, so PHP-side hash comparisons fail

**Found:** 2026-07-19, T30. The Multi suite reported `MultiFileBlob` returning
a row whose `fbb_sha256` did not equal the value it had just filtered by. The
query was right; the values differ only in trailing whitespace.

`fbb_sha256` is declared `character(64)`. Postgres blank-pads CHAR columns to
their full width on write and ignores that padding when comparing CHAR values,
so `WHERE fbb_sha256 = :sha` behaves exactly as intended. PHP does not: a value
read back from the database is 64 characters wide whatever was written, so
`$blob->get('fbb_sha256') === hash('sha256', $bytes)` is false for a blob whose
hash is genuinely correct.

Nothing in production compares the field this way today — the dedup lookup at
`file_blobs_class.php:321` does it in SQL, which is why this has never bitten.
The one PHP comparison in the tree is `tests/functional/files/blob_layer_test.php:80`,
and it passes only because it reads the in-memory object it just saved rather
than a reloaded row. Change that line to reload first and it fails.

**Why it needs a decision.** The obvious fix is `varchar(64)`, which makes the
stored value what was written and removes the trap entirely. That is a column
type change on a table holding real blob rows; `update_database` would apply it,
and with no production users (see the deployment notes) the migration risk is
low, but it is still a schema change made for a latent hazard rather than a
live bug. The alternative is to leave the type and document that this field
must be compared with `rtrim()` or in SQL — cheaper, but it leaves a rule
someone has to know.

**Cost of leaving it:** the next person who writes an integrity check, a
dedup path, or a sync comparison in PHP gets a silent false negative — two
identical files judged different, or a corruption check that never matches.
That is a bug that looks like data loss rather than like a type quirk.

**What closing it takes:** decide between the column type change and the
documented rule. If the type changes, drop the `rtrim()` in
`MultiModelTester::same_field_value()` that currently absorbs it, and make
`blob_layer_test.php` reload before comparing so the property is actually
pinned. If the rule stays, write it into `docs/file_signed_urls.md` or the
blob section it belongs to, and add the reload-then-compare check with
`rtrim()` so the behaviour is at least asserted.

## 19. Logging in from a protected URL dumps the user on a 404 instead of the page they asked for

**Found:** 2026-07-19, during step-8 phase 1 on jeremytunnell.com. Visiting
`/admin` while signed out redirects to `/login` (correct); submitting the
login form then lands back on `/login` itself, which for an authenticated
user renders the 404 page. The session is fine — the user is signed in — but
the original destination is lost and the first thing a new admin sees after
logging in is "Page Not Found."

**Cost of leaving it:** every deep link into a protected page (bookmarks,
emailed admin links, docs links) greets the user with an error page after
login. It reads as a broken site, and it will hit every customer admin on
every new site.

**What closing it takes:** carry the originally-requested URL through the
login flow (return-to parameter or session slot) and redirect there on
success; fall back to the profile or home page, never to `/login` itself.
Verify the return target is a local path (no open redirect).

## 20. Applying a node update reports success when the upgrade only self-updated its tooling — FIXED in tree 2026-07-19

The Apply Update job on a node runs `utils/upgrade.php`, which after a
self-update of deployment tooling used to print "please re-run" and exit 0 —
so the job completed green while the site was still on the old version, and
the operator had to notice and click Apply Update again. Fixed in tree:
in CLI mode `upgrade.php` now re-execs its refreshed self automatically
(one-shot guard, web flow keeps the Continue button). Ships with the next
publish; until a node has received it, one upgrade may still need a second
Apply Update click (the two-pass gotcha).

## 21. Node detail Updates tab shows Current Version: Unknown for a live node

**Found:** 2026-07-19, node 176. The control-plane version and the update
availability render, but the node's own current version is "Unknown" — so
"Update available" is asserted without knowing what the node runs, and after
an upgrade there is no on-page confirmation the version advanced. Likely just
never populated for bare-metal/agent nodes (mgn version field written by a
status check that has not run, or not written at all).

**Cost of leaving it:** operators cannot see from the dashboard whether an
upgrade landed; today the only proof is reading job output.

**What closing it takes:** have the status check (or upgrade job completion)
stamp the node's system version, and render it on the Updates tab.

## 22. Plugins table Status column says "Inactive" for plugins that are not installed

**Found:** 2026-07-19, jeremytunnell.com admin Plugins page. The stats bar
distinguishes Inactive (installed, off) from Not Installed, but every row's
Status cell reads "Inactive" either way. The Actions menu differs (Install vs
Activate), which is the only visible cue.

**Cost of leaving it:** an admin cannot tell at a glance which plugins are a
one-click Activate versus a schema-creating Install; the states carry very
different weight.

**What closing it takes:** render the row status from the same three-state
logic the stats bar uses.

## 23. Soft-deleting a row does not free its unique values, and the two layers disagree about that

**Found:** 2026-07-19, T31. Reproduced directly and pinned as current behaviour
in `tests/unit/system_base_lifecycle_test.php`.

A `unique_with` declaration materialises two enforcement points. The
application pre-check (`check_for_duplicate()`, called from
`check_unique_constraints()` inside `save()`) excludes soft-deleted rows. The
database constraint `update_database` creates does not. So after soft-deleting
a record:

- `check_for_duplicate()` reports the values as free — verified, returns 0.
- `save()` on a new record with those values is refused by Postgres with
  `SQLSTATE[23505]`, surfaced as a `DatabaseException`.

The user-visible effect is that deleting a record and creating a replacement
with the same identifying values fails, and fails badly: the pre-check that
exists precisely to turn this into a readable `DisplayableUserException` a form
can render says there is no problem, so the raw database error escapes to an
error page instead.

**Why this is not obvious from the code.** Each layer is individually
defensible. Excluding deleted rows from the pre-check is what you want if
soft-delete means "gone". Including them in the constraint is what you get by
default, because a plain unique index cannot know about a delete column. The
bug is only in their disagreement, and nothing in either file mentions the
other.

**The platform already has the fix pattern.** `pkc_credential_id` in
`data/passkeys_class.php` declares a partial unique index with
`'where' => 'pkc_delete_time IS NULL'`, which is exactly the constraint that
agrees with the pre-check. It is applied by hand there; `unique_with` does not
do it.

**Cost of leaving it:** every model with a `unique_with` on a soft-deletable
table has a delete-then-recreate path that fails with a database error. How
often that is reached depends entirely on whether the values are user-chosen
(a slug, a name, a subject pairing) — where they are, it will be hit by an
ordinary user doing an ordinary thing.

**What closing it takes:** a decision on which layer moves, applied to
`unique_with` generally rather than per-model. Either make `update_database`
emit a partial unique index excluding soft-deleted rows whenever the table has
a delete column (matching the pre-check, and the passkeys precedent), or make
`check_for_duplicate()` include deleted rows for uniqueness purposes and
produce a message that explains the collision is with a deleted record. The
first is better behaviour and a schema migration across many tables; the second
is a smaller change that leaves users unable to reuse values. Either way,
`system_base_lifecycle_test.php` pins today's behaviour, so the fix has a test
to update rather than a blank page.

## 24. Every docker site's Postgres is published to the internet through the ufw bypass

**Found:** 2026-07-19, while making Postgres local-only the install default.
Site containers are started with `-p $DB_PORT:5432` (host ports 908X) bound to
all interfaces, and docker's iptables NAT rules run BEFORE ufw — a host
firewall does not protect published ports. So every docker-mode site's
Postgres is reachable from the internet on its 908X port, password-gated only.
This applies to the shared docker host (8 containers) and VPS A (test2jt). The
overnight probe that found node 176's exposure checked 5432 only, which is why
the docker estate's 908X ports were not flagged then.

**Fixed for future installs:** install.sh 2.24 binds the DB publish to
`127.0.0.1:` (host-local management access unchanged, container-internal
access unchanged) and makes bare-metal Postgres listen on localhost only.

**What closing it takes for the existing estate (owner timing):** port
bindings are immutable on a running container, so either (a) recreate each
container with the new loopback binding — seconds of downtime per site, next
maintenance window — or (b) one host-level `DOCKER-USER` iptables rule per
docker host dropping external traffic whose original destination port is in
the 908X range (no restarts, but another special rule to remember). (a) is the
honest end state; (b) is acceptable interim hardening if (a) waits.

**Cost of leaving it:** internet-facing Postgres on nine-plus databases,
protected by a single password each, on hosts that also hold real user data.
