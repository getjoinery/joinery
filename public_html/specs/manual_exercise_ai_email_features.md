# Manual exercise pass — the AI email & calendar features

**Status:** Active checklist — pass not yet run as of 2026-07-16 (confirmed
with owner: still intended, deferred by build work). This is a hands-on pass
for a human (Jeremy), not an implementation spec. Work through it as a user
of the product; write findings inline under each item (rough notes are fine
— they become the next round of specs/fixes). Move to `implemented/` when
the pass is done and the findings are triaged.

**The one-sentence recap of what got built:** the platform now reads your
incoming mail for you — scores every message for danger, files it under
your labels with a one-line summary you see in the inbox, and puts any
real dated event (including calendar invites) on your calendar as a
tentative entry — plus the chat assistant can add calendar entries when
you ask. Everything below exercises one piece of that.

Test site: `https://dev.getjoinery.com`. The AI mailbox with existing
triage output is **joineryemailtests@gmail.com** (visible in the Mailbox
reader). Two recipes already exist under Admin → Joinery AI → Recipes:
"Email triage — joineryemailtests" (#126) and "Email schedule —
joineryemailtests" (#127). Both are manual-run right now.

---

## 1. Set up the world the features assume

- [ ] **Create a real label vocabulary.** The triage AI can only file mail
  into labels *you* have created — today there are just two test labels
  ("deals", "test label"), so it answers "none" for almost everything.
  Create the set you'd actually want (e.g. Receipts, Work, Personal,
  Newsletters, Travel, Money) in the mailbox admin's label management.
  *Judge:* was creating labels findable and painless?
- [ ] **Put the recipes on a schedule.** Open each recipe and change
  **Runs** from "Manually only" to "As mail arrives". *Judge:* does the one
  control, and the sentence under it about how this recipe's mailbox is
  handled, answer "when will this run?" without further reading? Anything
  confusing about the Job/Mailbox fields, the batch size, or "Allow tainted
  writes"?
- [ ] **Optional: seed a security-scan recipe** (same form, job "Inbound
  email security scan") on the same mailbox, so danger scoring runs
  continuously too.

## 2. Feed it real mail

- [ ] From a personal account, send a few real-shaped emails to the test
  mailbox: a receipt-looking one, a newsletter-ish one, one proposing a
  meeting in prose ("can we talk Thursday at 2pm?"), and one with a
  **calendar invite attached** (create an event in Google Calendar and
  invite the test address — that sends a real .ics).
- [ ] Wait for the arrival-triggered runs (or open each recipe and Run Now).
  *Judge:* is "did it run? did it work?" easy to answer from the Run
  History page and the dashboard tally, or did you have to dig?

## 3. The inbox experience (the payoff)

- [ ] Open the Mailbox reader. Each triaged thread should show an
  *italic one-line AI summary* where the message preview normally is.
  *Judge:* are the summaries actually useful — would you triage your
  inbox from them? Are they accurate? Is italic enough of a cue that
  it's AI-written, or does it need more/less?
- [ ] Check the labels the AI applied against what you'd have chosen.
  *Judge:* with your real label set in place, how good is the filing?
  Note any labels it should have but keeps missing.
- [ ] Find the danger badge on any message scored 3+ (send yourself
  something phishing-shaped if you want to provoke one). *Judge:* badge
  visible enough? Score believable?

## 4. The calendar experience

- [ ] After the schedule recipe processes the meeting-in-prose email and
  the .ics invite, open your calendar (`/profile/calendar`). Both should
  appear as entries — the invite with its exact title and times.
  *Judge:* are the times right in your timezone? Titles sensible?
- [ ] **Look hard at "tentative".** These AI-created entries are stored
  as tentative, but check what *you can actually see and do*: can you
  tell an AI-added entry apart from one you created? Is there any way to
  "confirm" it, or is editing/deleting the only interaction? **This is
  the area most likely to produce findings** — the storage and safety
  model landed, but the calendar UI may not surface firmness at all yet.
- [ ] Delete a junk entry the AI made (if any). *Judge:* easy enough?

## 5. The chat assistant's calendar door

- [ ] In the AI chat, ask it to add something to your calendar ("put
  lunch with Sam on my calendar Friday at noon"). It should ask you to
  confirm before writing, then the entry should appear on your calendar
  (also tentative). *Judge:* did the confirm step feel right? Did it get
  the time/timezone right from natural phrasing?

## 6. Small incidental checks (from fixes made along the way)

- [ ] Make any admin form fail on purpose (e.g. edit a recipe, untick
  "Allow tainted writes", save). You should see a red error explaining
  why — never a silently re-rendered form. *Judge:* is the wording of
  the taint-gate message understandable to a human?
- [ ] Skim the recipe list/dashboard after a few scheduled runs. *Judge:*
  token spend visible enough? Would you notice a runaway recipe?

---

## Findings

(Write anything here — bugs, confusions, "this should be one click",
missing affordances, wrong copy. Each becomes a spec or a fix.)

-
