# The reader's timestamp ladder

**Status: implemented** — verified against the code 2026-09-02: `fmtTime` / `sixMonthsBefore` in `plugins/mailbox/assets/mailbox_reader.js`, gated by `timestamp_ladder.mjs`.

A timestamp answers a different question as mail ages. Minutes old, the only
thing being asked is *how long ago*. Earlier today, the clock time places it in
your day. Within the year, roughly when in the day and on what date. Older than
that, the date alone — the hour stopped meaning anything months ago.

So the format coarsens in four rungs:

| age | format | example |
|---|---|---|
| under 1 minute, or dated ahead of now | `just now` | `just now` |
| under 1 hour | `N minutes ago` | `7 minutes ago` |
| under 12 hours | `h:mm am/pm` | `3:45 pm` |
| under 6 months | `ham/pm Mon D` | `3pm Jan 3` |
| 6 months or older | `Mon D, YYYY` | `Jan 3, 2020` |

Applies to both places the reader prints a message time: the conversation list
row and the open message header.

## Decisions worth keeping

**The clock is composed, not delegated.** `toLocaleTimeString` would render
`15:45` under a locale that prefers 24-hour clocks, one row above a `3pm Jan 3`
that names its meridiem — the ladder would disagree with itself. The hour and
meridiem are therefore built directly. The **month name** still comes from the
viewer's locale (`toLocaleDateString`), which is where the translation value
actually is.

**Under a minute is `just now`, not `0 minutes ago`.** This also absorbs a
message dated slightly in the future: clock skew between the browser and the
server routinely puts a just-arrived message a few seconds ahead, and
`-1 minutes ago` reads as a bug rather than as fresh mail.

**The minute is dropped on the third rung.** `3pm Jan 3` is what was asked for,
and it is right: on a message from January, the minute is noise.

**The six-month boundary is calendar-based** (`setMonth(-6)`), not 180 days.
JavaScript rolls a short month over — 31 August minus six months lands on 2 or
3 March — moving the boundary by a day. Immaterial for choosing a display
format, and not worth the clamping code to avoid.

**An unparseable value is echoed unchanged**, never rendered as 1970 or
`Invalid Date`. A wrong date stated confidently is worse than an obviously raw
one.

## Implementation

`mailbox_reader.js` 2.53: `fmtTime()` plus the `ampm` / `hour12` / `pad2` /
`clockTime` / `hourOfDay` / `monthDay` / `sixMonthsBefore` helpers. `fmtDate()`
is untouched — it serves the Trash purge date, where the day is the whole point
and the hour is noise.

Tests: `plugins/mailbox/tests/timestamp_ladder_gate.sh` +
`timestamp_ladder.mjs` (safe, needs node, 24 checks) — every rung and both
boundaries, noon and midnight, the singular minute, the future-dated message,
and the degenerate inputs. Cases are built as local-time offsets from a fixed
`now`, since the boundaries and the am/pm rendering are both local-time facts;
a UTC literal would pass or fail with the machine's zone.
