# Disowned Mail Is Never Allowed

**Status:** Implemented 2026-10-09 (mailbox 1.138.0). Built after the owner's ruling "we can't
override the DMARC check". Verified on dev by the test suites listed under Tests. The live
checks (Gil's next newsletter after release, the Spam-view banners in a browser, the filter help
text) are in the live verification queue.

## What this does for the owner

Some mail fails its sender's own authentication policy. When that policy is strict, the owner of
the sender's domain has said "mail that fails our checks is not from us". Before this change, an
"Always allow" rule from the Spam view could pull such mail into the Inbox. The rule was keyed on
the From address, so anyone forging that address got through too. Allow `alerts@yourbank.com`,
and every forged "bank" message reached the Inbox.

The change: when the sender's domain has disowned a message, nothing lifts it out of Spam. That
covers "Always allow", any "Never send it to Spam" filter, the backfill that applies rules to
older mail, and the per-message "Not spam" button. The message stays readable in Spam, and the
Spam view explains why instead of offering buttons that can't work.

## How this came up: the Gil Duran newsletter

Gil Duran's newsletter (`nerdreich@ghost.io`, sent through Ghost) kept landing in Spam at
jeremy@jeremytunnell.com. The header from 2026-10-09 16:59 UTC, through relay1:

```
dkim=pass header.d=m.ghost.io; spf=pass smtp.mailfrom=bounce+…@m.ghost.io;
dmarc=fail reason="SPF not aligned (relaxed), DKIM not aligned (relaxed)" header.from=ghost.io (policy=none)
X-Spamd-Result: [2.19 / 6.00]
```

### Why DMARC says "fail"

DMARC checks whether the From domain (`ghost.io`) belongs to the same organization as the domain
that signed the mail or sent it (`m.ghost.io`). Two standards decide "same organization"
differently:

- **RFC 7489 (2015), which rspamd uses.** It consults the Public Suffix List, a public list of
  hosting domains. Ghost added `ghost.io` to it (`effective_tld_names.dat` line 12417, "Ghost
  Foundation"), so `m.ghost.io` counts as a separate organization. Result: **fail**.
- **RFC 9989 (published 2026-05-20).** It replaces RFC 7489. It walks up the DNS, label by
  label, and looks for DMARC records:
  - `_dmarc.m.ghost.io`: none;
  - `_dmarc.ghost.io`: `v=DMARC1; p=none; rua=…`, with no `psd` tag;
  - `_dmarc.io`: none.

  So `ghost.io` is the organization for both names. Result: **pass**.

So Ghost's setup is valid under the current standard. It fails only under the older one, which
the open-source filters still use:

- rspamd 3.8.1 on our boxes, and upstream rspamd master (checked 2026-10-09), both call
  `rspamd_util.get_tld()`;
- SpamAssassin and OpenDMARC use the list as well.

### Why everyone else delivers it anyway

Standard filters treat a DMARC fail as a small penalty and honor the sender's policy. rspamd
added +0.1 for this one. Under `p=none` ("watch, take no action"), Gmail, Outlook and rspamd
take no action. Ghost publishes `p=none`, presumably because it knows its setup fails under the
old rule.

Our app was the outlier. `InboundEmailMessage::authRuleSaysSpam()` filed every DMARC fail as
spam and ignored the policy. Commit f53adfca (released in 0.8.473, the same day) fixed that: a
fail under `p=none` is no longer spam by itself. It feeds the learning filter as the
`dmarc_monitored_fail` token instead. Run against the real header, the parser reads
`dmarc=fail, dmarc_policy=none`, and the auth rule returns false.

### Whether Gil's next newsletter reaches the Inbox

Very likely. After the auth rule come these checks:

- the scanner score (2.19, far below the threshold);
- the sender history (filed as Spam only if the user marked Gil spam twice or more and never ham);
- the learning filter (files as Spam only at p ≥ 0.99, and it is never taught by automatic
  filings).

One limit: the "you rescued this sender before" shortcut needs DMARC to pass, so marking Gil
"not spam" teaches the learning filter but does not trigger that shortcut. "Always allow" is the
way to guarantee it, and it still works for Gil, because `p=none` is not a disowning.

### Adopting RFC 9989 was deferred

We considered making our checker follow RFC 9989, by shipping our own copy of rspamd's DMARC
module with the DNS tree walk. The owner deferred it, and it is recorded in
`DEFERRED_dmarc_current_standard`. With the `p=none` fix in, the only remaining case is a listed
hosting domain with a split setup **and** a strict policy. Such a domain fails at nearly every
receiver today, so its owner would fix it. Revisit when rspamd adopts RFC 9989.

## The fix

### One definition

`InboundEmailMessage::senderDomainDisowns(array $auth)` is true when DMARC failed and the policy
is anything other than `none`: `quarantine`, `reject`, or a policy that could not be read.
`rowSenderDomainDisowns(array $row)` asks the same question of a stored row's
`iem_dmarc_result` / `iem_dmarc_policy`. `authRuleSaysSpam()` uses it for its DMARC branch, so
the auth rule and the allow rules can never disagree about which failures count.

Not disowned, and so still allowable by the user:

- a fail under `p=none` (Ghost);
- the no-DMARC fallback, where SPF and DKIM both fail and the domain publishes no DMARC record.
  The domain has made no claim, so it is the user's call.

### Where it is enforced

| Place | Behavior |
|---|---|
| `InboundEmailFilter::applyActionSet()` (filters 1.8) | `never_spam` is skipped for a disowned message. The action list records `never_spam_refused`, which the filter log keeps. This one path serves live ingest, the device-run rules, and the `ApplyInboundEmailFilters` backfill. |
| `MailboxService::allowSender()` (1.58) | A disowned message is neither cleared nor given a rule. The result carries `disowned` (count). |
| `MailboxService::setSpamVerdict()` (1.58) | "Not spam" (ham) skips disowned messages. "Mark as spam" is unaffected. The mobile app and bulk actions go through the same call. |
| `thread_action_logic` `allow_sender` (1.3.2) | Passes `disowned` through in the response. |
| Reader rows (`MailboxService`, both builders) | New `spam_disowned` flag. |
| `mailbox_reader.js` (2.93) | The Spam-view banner for a disowned message explains that the domain says it did not send it and offers no button. A conversation made only of disowned messages has no "Not spam" button. After a "Not spam", bulk or on a mixed conversation, the list is re-read rather than the rows removed, since disowned parts stay behind. The banner for the allowable auth case no longer claims a "misconfigured DMARC record". |
| `mailbox_filters_panel.php` (1.1) | The "Never send it to Spam" checkbox has help text naming the exception. |
| `InboundEmailRouter` (1.53) | Comments on the verdict order and the filter step state the exception. |
| `docs/overview.md` (mailbox) | Describes the disowned-message rule. |

### Tests

- `spam_filtering_test` 2.1: `senderDomainDisowns()` across reject, quarantine, unknown,
  `p=none`, the no-DMARC fallback, and pass; and `rowSenderDomainDisowns()` on row columns.
- `mailbox_reader_test` 1.8:
  - `allowSender` refuses a disowned message, reports `disowned = 1`, leaves it in Spam and
    writes no rule;
  - a `never_spam` action set on it returns `never_spam_refused` and leaves it in Spam;
  - the same action on a `p=none` fail lifts it to ham;
  - "Not spam" leaves a disowned message in Spam, "Mark as spam" still works on it, and a
    mixed selection lifts only the message that was not disowned.
- Results on dev:
  - `spam_filtering` 111/111, `authentication_results` 38/38, `mailbox_reader` 88/88 and
    `relay_auth_trust` 20/20; after the "Not spam" change, `mailbox_reader` 91/91,
    `spam_learning` 69/69, `mailbox_trash` 72/72 and `imap_syncer` 79/79;
  - `run.php --changed`: 77/77 suites, 3148 checks.

## The "Not spam" button

At first the per-message "Not spam" button was left able to move a disowned message, on the
grounds that it is a one-time choice about a message the user can see. The owner's rule had
already settled it: nothing on our side overrides the DMARC check, and "Not spam" is an
override. So it is blocked too. A disowned message can never leave Spam. That matches what
other receivers do: Gmail refuses p=reject failures outright, so its users never see them.

## Sources

- RFC 9989: https://www.rfc-editor.org/rfc/rfc9989.html
- rspamd DMARC module (upstream master):
  https://raw.githubusercontent.com/rspamd/rspamd/master/src/plugins/lua/dmarc.lua
- RFC 9989 publication summaries:
  - https://www.dmarctrust.com/blog/dmarcbis-rfc-9989-9990-9991
  - https://www.suped.com/blog/dmarcbis-is-now-published-as-rfc-9989-9990-9991
