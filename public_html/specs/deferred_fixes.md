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

## 4. Materializing a recurring instance twice can create a duplicate row

**Deferred:** 2026-07-18

`Event::materialize_instance()` checks whether the date is already materialized,
then inserts if not. Nothing holds a lock across those two steps, so two
concurrent calls for the same date can both pass the check and both insert. The
duplicate is then invisible: `_get_materialized_instance_for_date()` selects
`LIMIT 1`, so every later lookup returns one of the two and the other sits
unreferenced.

**Cost of leaving it:** narrower than entries 1 and 2 — materialization is
admin-initiated, so it needs two admins (or one double-click reaching two
handlers) on the same occurrence at the same moment. The result is a stray event
row that expansion may surface as a second copy of one occurrence, and that holds
its own registrants if anyone signs up before it is noticed.

**Why this one IS a constraint problem.** Unlike event capacity (entry 1), "one
materialized instance per parent per date" is a uniqueness rule, so it is
directly expressible: `unique_with` on `evt_materialized_instance_date` naming
`evt_parent_event_id`. Those keys materialize real database constraints through
`update_database` / plugin sync, and both columns are nullable, so standalone
events and recurring parents — which have NULL for both — stay outside the
constraint. Postgres permits repeated NULLs in a unique index.

**What closing it takes:** add the `unique_with` key, sync, and confirm no
duplicate pairs exist first (none do on dev). `materialize_instance` should then
catch the constraint violation and re-read, so the loser of a race returns the
winner's row rather than surfacing a database error — which is what the existing
idempotence check already promises callers.

Pinned today by `plugins/event_manager/tests/event_recurrence_test.php`, which
asserts single-threaded idempotence and that exactly one row exists for a
materialized date. The concurrent case belongs with the fix.

---

## 5. A materialized instance has no database-level link to its parent

**Deferred:** 2026-07-18

`evt_parent_event_id` is declared as a plain `integer` with no `foreign_key` key,
so nothing at the database level stops an instance row outliving the parent it
describes. The PHP deletion rules do cascade instances when a parent is
permanently deleted — `event_deletion_test.php` proves the executed cascade — but
that only holds when deletion goes through the models.

**Cost of leaving it:** an instance orphaned by raw SQL, a killed process, or an
interrupted test keeps a `evt_parent_event_id` pointing at a free id. This is the
exact hazard `docs/deletion_system.md` describes for hard ownership edges: if the
parent's primary key is later reallocated, the stale instance attaches itself to
an unrelated event and starts appearing in that series' expansion. Sequences are
forward-only platform-wide, which makes reallocation unlikely rather than
impossible.

**Why it qualifies.** An instance is meaningless without its parent — it holds no
recurrence pattern of its own and exists only to override one date of a series.
That is the stated test for declaring a real constraint.

**What closing it takes:** add `'foreign_key' => array('table' => 'evt_events',
'column' => 'evt_event_id', 'on_delete' => 'CASCADE')` to the
`evt_parent_event_id` spec and run `update_database` / plugin sync. It is a
self-referencing constraint on a nullable column, so standalone events and
parents (NULL) stay outside it. No orphans exist on dev today, so creation would
not be blocked. The `referential_integrity` safe-tier test would then guard it
every run.

Deferred only because it is a schema change, which needs an explicit go-ahead
before `update_database` runs.

---

## 6. The error log silently drops its most detailed errors

**Deferred:** 2026-07-18

`err_error` and `err_message` in `err_general_errors` are `varchar(255)`, and
both receive raw exception and database messages. A PostgreSQL error carrying a
`DETAIL:` clause routinely runs past 255 characters, so the insert that records
it fails on its own length limit. The platform then prints `Database error
logging failed: ... value too long for type character varying(255)` and the
original error is never stored.

**Cost of leaving it:** the errors that go missing are precisely the ones worth
having. A short error ("permission denied") fits and is logged; a constraint
violation naming the table, the column and the failing row does not, so the
incident that actually needed a record leaves none. Nothing about this is
visible unless someone is watching stderr at the moment it happens — which is
how it was found, incidentally, while a test fixture violated a NOT NULL
constraint.

**What closing it takes:** change both fields to `text` in
`data/general_errors_class.php` and run `update_database`. Postgres stores
`varchar(n)` and `text` identically, so widening costs nothing and rewrites no
rows. `err_description`, `err_file` and `err_path` are also `varchar(255)` and
worth reviewing in the same pass — a file path plus a long class name reaches
that limit too.

Grouped with entries 4 and 5: all three are schema changes and want a single
`update_database` run.
