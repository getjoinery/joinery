# Waitlist Promotion and Attendance Helpers

**Status:** Deferred (owner, 2026-10-12). Not scheduled. Split out of `core_improvements_for_plugins.md` (item C9) so that spec could close. It is built when a plugin that needs it is built (Volunteers is the first candidate), or when Event Manager's missing promotion is judged worth fixing on its own.

**What it does for the user:** when someone cancels a full event or shift, the first person waiting gets the seat automatically, and organizers can mark who showed up.

## Today

- **Event Manager:** the waiting list (`ewl_waiting_lists`) is only a list. An admin views it and emails it by hand. Nothing promotes anyone into a freed seat, and `evr_event_registrants` has no attended mark. A registration runs through a store product, so a promoted person would need a payment link, not a silent confirmation.
- **Volunteers (specified, not built):** designed with automatic promotion and an attendance status on its own sign-up table. Until this exists, Volunteers carries its own waitlist and attendance code.

## Design if built

A small class `SeatLedger` that takes callbacks so each plugin keeps its own tables:

- `takeSeat`: lock the parent row, count confirmed, confirm or waitlist.
- `freeSeat`: promote the oldest waiting person in the same transaction and return who was promoted, so the caller sends notifications after commit.
- `markAttendance`: a status plus an optional hook.

Each plugin supplies "count confirmed", "list waiting, oldest first" and "confirm this person".

## Who could use it

Only Event Manager and Volunteers have capacity today. Bookings sends group classes to Event Manager, and Boards and Donations have no capacity. A Courses plugin with cohorts, or Bookings resource pools, would be future users, but neither is specified. The case that stands on its own is Event Manager's missing promotion, and it has to design for a payment link on promotion.

## The decision (owner, 2026-10-12)

Leave it out until a second real user exists. Volunteers carries its own short version in the meantime, and the code is lifted into core when a third user appears.

## When to revisit

- Volunteers is scheduled: build it with Event Manager as the first user and Volunteers as the second, so the shared code is shaped by both.
- Event Manager admins ask for automatic promotion: build it against Event Manager's waiting list alone.
