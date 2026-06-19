# Spec — CLAUDE.md addition: explain decisions plainly and directly

## Status

Proposed. A small documentation/process change, not a code change.

## Problem

When Claude surfaces a design fork for the operator to decide, the first framing
often leads with internal jargon — table names, column names, class names — before
the operator knows what is actually at stake. That forces the reader to reverse-
engineer the real-world consequence from implementation detail. A concrete instance:
the Trash/role-folder question in the inbound-email labels work was first asked in
dense schema terms and rejected; a second, plainer framing of the *same* decision
("every label becomes a Group; the only open question is the three special folders;
today the code knows a message is in Trash by a membership row…") was accepted as
"much better."

## Change

Add a short **Communication** rule to the internal CLAUDE.md (the "Internal
CLAUDE.md" record at `/admin/admin_agent_files` — **not** the on-disk file, which is
regenerated from `agf_agent_files`). Proposed text:

> ### Explaining Decisions
>
> When you present a decision or design fork for the user to make, lead with the
> plain-language stakes, then the options, then a recommendation — in that order:
>
> 1. **One sentence on what is actually at stake** in real-world terms (what breaks,
>    what gets slower, what the user will see), not in schema/class terms.
> 2. **Each option in plain language**, each with a one-line "catch" (its cost or
>    risk). Name the concrete tradeoff, not just the label.
> 3. **A recommendation** with the reason it wins.
>
> Put table names, column names, and class names *after* the plain explanation, as
> supporting detail — never as the opening. If a sentence only makes sense to someone
> who already has the schema in their head, rewrite it. The test: a competent operator
> who has not read the code should be able to choose from your framing alone.

## Why this layer

This is guidance about how the agent communicates, so it belongs in the agent
instructions (CLAUDE.md), not in `/docs/` (developer-facing) or a code comment. It
generalizes beyond any one feature, matching the platform principle of building
reusable rules rather than one-off fixes.

## How to apply

Edit the "Internal CLAUDE.md" record through `/admin/admin_agent_files` and add the
section above (placement: near the existing Secret Handling / Documentation rules, as
a peer "Communication" rule). Do not edit `CLAUDE.md` or `GEMINI.md` on disk — they
are regenerated from `agf_agent_files`.

## Testing

Not automatable. The next time a design fork is surfaced, confirm the framing leads
with stakes-then-options-then-recommendation and defers jargon.
