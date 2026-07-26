# Mailbox Setup Verdicts

**Status: proposed 2026-07-25.**

## What this is

The Setup tab can tell you whether a mailbox is correctly configured, but only
if you go and ask it, one mailbox at a time. Nothing anywhere else in the admin
knows that a domain has no MX record, or that a Fortress domain never finished
its protect ceremony. A mailbox can sit broken indefinitely because the only
surface that would say so is a page nobody opened.

This puts that knowledge where people already are: a badge on the Accounts page,
linking to the Setup page for the mailbox in question.

The whole design problem is cost. The Setup checks are DNS lookups, host probes
and provider API calls — hundreds of milliseconds per domain. Running them to
render a list is not an option. So the signals are tiered by what they cost, and
only the expensive tier is persisted.

## The three tiers

**Free — already in the row.** The Accounts page loads each alias and its domain
to render the list at all, so these cost nothing beyond a boolean test:

| Signal | Condition |
|---|---|
| Protection never activated | `ied_security_level = 'fortress'` and `ied_is_protected_identity` false |
| Sealed key never generated | security level is not `standard` and `ied_dkim_selector` is empty |
| Domain switched off | `ied_is_enabled` false |

These are half-finished ceremonies. They are invisible today and they are the
cheapest thing on this page to fix.

**One query — arrival.** "Has any mail ever arrived for this address" is the
strongest single signal that a mailbox works, and it is what the Setup tab's
End-to-end check already asks. One grouped query answers it for every alias at
once.

It needs indexes to be affordable. Both source queries filter on
`lower(iel_to_address)` / `lower(iem_recipient)`, and the stored data really is
mixed-case (12 of 304 message rows, 2 of 97 log rows on the dev box), so the
`lower()` is load-bearing and a plain btree on the raw column would not be used.
Two expression indexes, declared through `$index_specifications`:

```php
public static $index_specifications = array(
    array('columns' => array('LOWER(iem_recipient)')),
);
```

**Persisted — DNS.** Everything else. A scheduled task walks the enabled domains,
runs the DNS-only check entry point that already exists
(`InboundEmailSetupCheck::runDomainChecks($domain)` — deliberately excludes the
host and relay layers), and stamps a verdict onto the domain row. The listing
reads that column for free.

## The rule that matters: the stored verdict never wins

There are now two answers to "is this mailbox set up" — the live checks and the
stored verdict — and they will disagree, because DNS changes between task runs.
That disagreement is fine as long as one of them is never treated as the answer.

**The stored verdict is a navigation hint, not a verdict about the world.** It
exists to tell an operator *which mailbox to go and look at*. The Setup page
re-runs everything live and is the only thing that ever claims a domain is
correct or broken. So:

- The badge links to the Setup page. It never explains what is wrong, only that
  something was, and when it was last looked at.
- The badge copy says *needs attention*, never *broken* or *failing*.
- A verdict older than **7 days** is not displayed at all. A stale hint that
  contradicts reality is worse than no hint, and pointing at a domain that was
  fixed six days ago wastes exactly the attention this feature is trying to buy.
- Nothing else reads the verdict. It never gates sending, provisioning,
  cutover, or any decision the platform makes on its own.

## What counts as a problem

Two rules, both about not crying wolf.

**Only REQUIRED failures set the verdict.** The check engine already grades every
row `required` or `recommended`. A missing DMARC record is `recommended` — real
advice, but a domain that receives mail perfectly well should not wear a badge
saying otherwise. Only `required` + `fail` marks a domain as needing attention.

**A check that could not run is not a failure.** `UNKNOWN` means the resolver did
not answer, and treating that as breakage would make every badge flap with the
first DNS hiccup — and flapping badges get ignored, which costs more than the
feature was worth. Unknown rows are skipped. If every row came back unknown, the
verdict is `unknown` and the previous verdict is left alone rather than
overwritten with an absence of information.

## Data

Two columns on `ied_inbound_email_domains`:

| Column | Meaning |
|---|---|
| `ied_setup_status` | `ok` \| `attention` \| `unknown`; empty means never checked |
| `ied_setup_checked_time` | when the task last reached a conclusion |

No failure detail is stored. It would be one more thing to keep in sync with
reality, and the Setup page regenerates it live and better.

## The task

`CheckDomainSetup`, daily. For each enabled, non-IMAP domain: run
`runDomainChecks()`, apply the two rules above, write the columns. IMAP-source
domains are skipped — they have no MX or SPF of their own to be wrong.

Daily is deliberate. DNS misconfiguration is not an outage that needs catching in
minutes; it is a state someone has to go and fix, and a badge that appears within
a day is soon enough for that. The cost is DNS lookups per domain per day.

## Acceptance

1. A Fortress domain whose protect ceremony never ran is badged on Accounts with
   no extra query.
2. A mailbox that has never received mail is badged, and the whole listing costs
   one additional query however many mailboxes it holds.
3. A domain missing only its DMARC record is **not** badged.
4. A domain whose checks all return unknown keeps whatever verdict it had.
5. A verdict older than seven days shows nothing.
6. Every badge links to the Setup page with that mailbox already selected.
7. Nothing but the Accounts listing reads the verdict columns.

## Open decisions

None. Recommended defaults: daily cadence, seven-day staleness, required-only
grading, domain-level granularity.
