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
