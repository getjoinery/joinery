# DMARC Checked by the Current Standard

**Status:** DEFERRED 2026-10-09 by the owner. No code. Revisit when rspamd adopts RFC 9989.
Why deferred: the 0.8.473 `p=none` exemption already keeps Ghost-shaped mail out of Spam, and
the remaining gap (a listed hosting domain with a split setup AND an enforcing policy) would
fail at most receivers today, so such senders fix it themselves. D1 recommendation, if revived: (a).

## What this does for the owner

Some legitimate senders use a common setup: the From address is on a hosting company's domain,
but the mail is signed by and bounces through a subdomain of it. For example, Ghost newsletters
come From `nerdreich@ghost.io` and are signed by `m.ghost.io`. Our mail server says these
messages **fail** DMARC, the check that the From address really belongs to the sender. Gmail and
other receivers that follow the current standard say they **pass**.

The two disagree because they use different rules to decide which names belong to the same
organization:

- **Old rule, RFC 7489 (2015), which we use.** It consults a public list of hosting domains
  (the Public Suffix List). `ghost.io` is on that list, so `m.ghost.io` counts as a separate
  organization. Result: **fail**.
- **Current rule, RFC 9989 (published 2026-05-20).** It replaces RFC 7489. It walks up the DNS,
  label by label, and looks for DMARC records. A domain can mark itself as a hosting domain
  (`psd=y`) or as an organization boundary (`psd=n`). `_dmarc.ghost.io` has a record with no
  such mark, and `_dmarc.io` has none, so `ghost.io` is the organization for both names. Result:
  **pass**.

Today this costs nothing for Ghost, because the 0.8.473 fix (commit f53adfca) never files a
DMARC fail under `p=none` as spam, and `ghost.io` publishes `p=none`. It does cost us for any
hosting domain with the same setup and a `p=quarantine` or `p=reject` policy. Their mail lands
in Spam with us and in the inbox everywhere else. This spec makes our verdict match the current
standard, so the Authentication-Results line, the message timeline and the spam filing all say
"pass" for this mail.

## Today (checked 2026-10-09)

- **The receiving relay and every node's own box run rspamd 3.8.1** (Ubuntu package). Its DMARC
  module (`/usr/share/rspamd/plugins/dmarc.lua`, 685 lines) finds the organization with
  `rspamd_util.get_tld()`, which reads `/usr/share/rspamd/effective_tld_names.dat` (the Public
  Suffix List). `ghost.io` is on line 12417 of that file.
- **Upstream rspamd master does the same**, so an rspamd upgrade does not fix this. The source
  fetched 2026-10-09 still calls `rspamd_util.get_tld(hfromdom)` and has no `psd` handling.
- **The DNS records involved:**
  - `_dmarc.ghost.io` = `v=DMARC1; p=none; rua=mailto:…` (no `psd` tag);
  - no record at `_dmarc.m.ghost.io` or `_dmarc.io`.
- **The real header** (Gil Duran → jeremy@jeremytunnell.com, 2026-10-09 16:59 UTC, through
  relay1):

  ```
  dkim=pass header.d=m.ghost.io; spf=pass smtp.mailfrom=…@m.ghost.io;
  dmarc=fail reason="SPF not aligned (relaxed), DKIM not aligned (relaxed)" header.from=ghost.io (policy=none)
  ```

  `AuthenticationResults` parses it to `dmarc=fail, dmarc_policy=none`. With 0.8.473,
  `InboundEmailMessage::authRuleSaysSpam()` returns false for it.
- **rspamd only adds headers.** It never refuses or greylists (`actions.conf`:
  `reject = null`). So no mail was ever lost to this; the cost is only where mail is filed.
- **The app does not decide DMARC itself.** `AuthenticationResults` reads the verdict rspamd
  stamps, and trusts only lines under our own authserv-id. The Mailgun inbound webhook path
  reports Mailgun's own verdict; this spec does not reach it (see "Out of scope").

## Design

The checker decides, the app reads. rspamd keeps producing the one Authentication-Results line.
We switch off rspamd's stock DMARC module and load our own copy of it. The copy is the same
module with one change: it finds the organization and the policy record by the RFC 9989 tree
walk instead of the hosting-domain list.

What stays exactly as it is:

- **Symbol names:** `DMARC_POLICY_ALLOW`, `DMARC_POLICY_SOFTFAIL`, `DMARC_POLICY_QUARANTINE`,
  `DMARC_POLICY_REJECT`, `DMARC_NA`, `DMARC_BAD_POLICY`, `DMARC_DNSFAIL`. rspamd's
  `lua_auth_results.lua` builds the `dmarc=` part of the header from these, so the header format
  does not change.
- **The option strings** each symbol carries (`<domain> : <reason>`, plus the policy). The
  `(policy=…)` in the header comes from them, and `AuthenticationResults::policyOf()` reads it.
- **Scores:** the stock scores for those symbols are kept.
- **The app:** `AuthenticationResults`, the auth rule and the `p=none` exemption are unchanged.
  The exemption still matters: it covers mail that really is unaligned from a domain that has
  asked for no action.

What the module does differently:

1. **Finding the policy (RFC 9989 §4.10.1).**
   - Look up `_dmarc.<From domain>`. If it has a valid record, that record applies, and so does
     its `p`.
   - Otherwise tree walk up from the parent. Prefer the organization's record, then the hosting
     domain's (`psd=y`) record.
   - For a subdomain, use `sp` if the From domain exists and `np` if it does not (NXDOMAIN). If
     the tag is missing, fall back to `p`.
   - `pct` is gone in RFC 9989. Honor `t=y` (testing): report the policy one step lower,
     `reject`→`quarantine` and `quarantine`→`none`.
   - Invalid `p` with a valid `rua`: act as `p=none`.
   - No record at all: `DMARC_NA`, as today.
2. **Finding the organization (RFC 9989 §4.10 and §4.10.2).** Run this for the From domain and
   for each domain to compare against it: every DKIM signature that passed (`DKIM_TRACE`
   entries ending `:+`, and `:?` for the temporary-failure case) and the SPF envelope domain.
   1. Query `_dmarc.<name>`. Keep only records that start with `v=DMARC1`. If more than one
      remains at a name, discard them all.
   2. If the domain has 8 or more labels, shorten it to 7 first. This caps a walk at 8 queries.
   3. Walk from longest to shortest name. Stop at the first record that carries a `psd` tag.
   4. Pick the organization:
      - the name whose record says `psd=n`;
      - else, one label below a name whose record says `psd=y` (when that name is not where the
        walk started);
      - else, the shortest name that has a valid record;
      - else, the starting name itself.
3. **Alignment.**
   - Relaxed: the two names have the same organization by step 2.
   - Strict (`adkim=s` / `aspf=s`): exact match, as today.
   - Reason strings stay as they are: "SPF not aligned (relaxed)" and so on.
4. **DNS trouble.** If a lookup fails with anything other than "no such record", report
   `DMARC_DNSFAIL` (temperror), the way the stock module does. Never guess an organization from
   a partial walk.
5. **Cost.** Each walk is at most 8 TXT queries. Run them in parallel through rspamd's own async
   resolver. Remember results for the life of one message, so the From domain's walk is shared
   by the comparisons. The box's resolver caches across messages. Typical mail (From
   `example.com`, signed `example.com`) costs one query, the same as today.
6. **Left out of the copy:**
   - DMARC reporting (it needs redis; our boxes run none, and reporting is off);
   - munging (also unused).

   The copy's header comment records these as deliberately absent, so nobody restores them by
   diffing against upstream.

## D1 — Where the fix lives

The question is whether the checker or the app decides the standard-correct verdict.

- **(a) Our own rspamd DMARC module (recommended).**
  - Pros:
    - The header, the scanner score, the stored verdict and the timeline all agree.
    - The relay and every node get it from the same file.
    - It keeps the rule that the app never computes verdicts (`AuthenticationResults`
      docblock).
  - Cons:
    - We maintain a Lua copy of an upstream module, about 450 lines once reporting and munging
      are removed.
    - Changes to the Ubuntu rspamd package must be checked against it (see WP3's version pin).
    - Testing Lua takes the private-rspamd harness rather than PHPUnit-style tests.
- **(b) The app re-checks alignment in PHP when rspamd says "fail".**
  - Pros:
    - Plain PHP and easy to test.
    - It could also re-check what the Mailgun webhook reports.
  - Cons:
    - The stored verdict and the Authentication-Results header the user can read disagree.
    - It breaks the documented rule that the app only reads verdicts.
    - rspamd's score still carries the fail.
    - The parser keeps only one DKIM domain, so it needs widening.
    - The check runs after delivery rather than at SMTP time.

Recommendation: (a). The disagreement in (b) is exactly what the message timeline exists to
explain, and it would explain it wrongly.

## For the executor — read this first

- Memory `project_mail_checking_in_rspamd` has the hard-won rspamd facts:
  - A `local.d` list is appended to the stock list; use `override.d` to replace.
  - How to run a private rspamd without root against a copy of `/etc/rspamd`, and POST to
    `/checkv2`.
  - `spam_scanner_gate.sh` accepts `PROVISIONING_DIR=<dir>` to gate a scratch copy.
- **The converger runs the tree's provisioning scripts as root within minutes.** Build and gate
  the new files in a scratch copy first. Land them in the tree only when the gate passes.
- rspamd loads `/etc/rspamd/local.d/rspamd.lua` on its own (`/usr/share/rspamd/rules/rspamd.lua`,
  near the end). Turn off the stock module with `local.d/dmarc.conf` → `enabled = false;`.
- rspamd's stock record parser (`lualib/plugins/dmarc.lua`, `dmarc_check_record`) reads every
  tag but keeps only `p`, `sp`, `adkim`, `aspf`, `pct` and `rua`. The copy needs `psd`, `np` and
  `t` too. Parse the record in the copy, and do not edit the stock lualib file.

## WP1 — The module

`plugins/mailbox/provisioning/rspamd/joinery_dmarc.lua` (new).

- Start from rspamd 3.8.1's `plugins/dmarc.lua`. Header comment:
  - what it is, and the upstream version it was copied from;
  - the one behavioral change (tree walk);
  - what was removed (reporting, munging) and why.
- Implement the Design section, steps 1–6.
- Register the same symbols with the same groups and scores, and the same `DMARC_CHECK`
  dependencies on the SPF and DKIM allow symbols.
- Keep `auth_and_local_conf` skipping as the stock module does.

## WP2 — Shipping it

`rspamd_stateless.sh`:

- Add `rspamd.lua` and `dmarc.conf` to `RSPAMD_STATELESS_FILES`.
- `rspamd.lua` renders the module. Read it from the WP1 file, so the module has one copy in the
  tree.
- `dmarc.conf` renders `enabled = false;` with a comment naming the replacement.
- Bump the version. Both callers already reload rspamd when a file changes.
- Relay: bump `RELAY_VERSION` (`RelayVersion.php` and `provision_relay.sh`) so relays converge.

## WP3 — Tests and the gate

- **The private rspamd harness** (spam_scanner_gate.sh) runs these cases through `/checkv2`. Use
  rspamd's fake DNS (`options { dns { fake_records … } }`). If 3.8.1 lacks it, publish the
  records under the test domain `joinerytest.com`. Each case asserts the `dmarc=` part of the
  header the reply would add.

  | Case | Records | Mail | Expect |
  |---|---|---|---|
  | Ghost shape (a name ON the Public Suffix List, so the old rule fails it) | `_dmarc.ghost.io` p=quarantine, nothing above | From ghost.io, DKIM d=m.ghost.io | pass |
  | Hosting marks itself | `_dmarc.host.example` psd=y p=none | From a.host.example, DKIM d=b.host.example | fail (not aligned) |
  | Boundary below | `_dmarc.sub.example.com` psd=n; `_dmarc.example.com` p=reject | From example.com, DKIM d=sub.example.com | fail, policy=reject |
  | Plain domain | `_dmarc.example.com` p=quarantine | From example.com, DKIM d=example.com | pass |
  | Subdomain policy | `_dmarc.example.com` p=reject sp=none | From news.example.com, unsigned, SPF other domain | fail, policy=none |
  | np | `_dmarc.example.com` p=none np=reject | From ghost-sub.example.com (NXDOMAIN), unsigned | fail, policy=reject |
  | Testing mode | `_dmarc.example.com` p=reject t=y | unaligned | fail, policy=quarantine |
  | Two records at one name | two `v=DMARC1` at `_dmarc.example.com` | any | none (no policy) |
  | Long name | From a 10-label name | — | at most 8 queries |
  | DNS failure | SERVFAIL on the walk | — | temperror, not fail |

- **Version pin.** The gate fails when the installed rspamd version is not the one the copy names
  in its header. An Ubuntu package update then shows up as a red gate rather than a silent
  mismatch.
- **App side.** Add one case to `authentication_results_test.php`: the Ghost header with
  `dmarc=pass` parses to pass. The format must not have shifted.

## WP4 — Rollout and live check

1. Dev (converger), then relay1 (relay update), then the nodes through the release.
2. **Live check:** the next Gil Duran newsletter at jeremy@jeremytunnell.com reads
   `dmarc=pass header.from=ghost.io` in its stored headers and lands in the Inbox. Until then,
   send a test message with the Ghost shape to `test@dev.getjoinery.com` from a joinerytest.com
   sender set up that way.
3. Watch the relay's rspamd log for a day for module errors and scan-time growth.

## Out of scope

- **The Mailgun inbound webhook path.** It reports Mailgun's own DMARC verdict. If Mailgun still
  uses the old rule, Ghost-shaped mail through that path reads "fail". The `p=none` exemption
  covers Ghost there.
- **Sending DMARC reports** (we send none).
- **Our own outgoing records.** Whether to add `psd=n` to the DMARC records our customers'
  domains publish is a separate question about how others read our mail.

## Versions to bump

- `rspamd_stateless.sh`
- `RELAY_VERSION` (both places)
- mailbox `plugin.json`
- the new module's own header version
- `docs` — mailbox `overview.md`: the DMARC section describes the tree walk, and drops the
  hosting-domain list.
