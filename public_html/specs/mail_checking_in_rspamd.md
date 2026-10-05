# Mail checking in rspamd alone

**Status: DRAFT (2026-10-05) — not built. No open questions.**

## The idea in one paragraph

Every box that receives mail runs Postfix plus three helper programs that
inspect each message: opendkim, opendmarc and rspamd. rspamd already does
everything the other two do. This spec removes opendkim and opendmarc, so a
mail box runs Postfix and one checker. Nothing changes in what the app
decides or what a user sees.

## What runs today

| Job | Done by | Site box | Relay |
|---|---|---|---|
| Refuse senders on the Spamhaus lists, at the door | Postfix | yes | yes |
| Check DKIM signatures on arriving mail | opendkim (port 8891) | yes | yes |
| Check SPF and DMARC on arriving mail | opendmarc (port 8893) | yes | yes |
| Score content, stamp `X-Spam` headers | rspamd (port 11332) | yes | yes |
| Sign outgoing mail for ordinary domains | opendkim | yes | no |
| Sign outgoing mail for protected domains | the app (sealed key) | yes | no |
| Learn from what users mark; the final spam verdict | the app | yes | no |

Two things in that table are done twice:

- rspamd also checks SPF, DKIM, DMARC and ARC on every message, to score
  it, and writes its own verdict line. Two codebases reach a verdict on the
  same message.
- rspamd also asks Spamhaus about every sender Postfix has just let through.

## Findings

**F1. rspamd's verdict is already the only one the app reads.** Confirmed
2026-10-05, three ways:

- *Real messages on dev.* Stored messages 77056, 123672 and 125402 each carry
  exactly one verdict line, in rspamd's wording (`dmarc=fail reason="No valid
  SPF, No valid DKIM"`, `(policy=none)`). opendmarc's only trace is its
  `DMARC-Filter:` stamp; its verdict line and opendkim's are gone.
- *Asked the running rspamd.* Given a message already carrying three verdict
  lines in our name, it answers Postfix with `remove_headers:
  {"Authentication-Results": 0}` and one line to add.
- *rspamd 3.8.1 source.* In `milter.c` a count of 0 means "clear all headers"
  of that name.

So for arriving mail opendkim and opendmarc are already dead weight: they
check every message and rspamd throws their answer away. Removing them
changes no verdict. The removal is rspamd's default, not something our
configuration asks for; WP2 makes it explicit.

**F2. A forged "passed" line does not win on dev.** Tested 2026-10-05: two
messages from `example.com` (which forbids all senders), one carrying a
forged line in our name claiming SPF and DMARC passed. Both were recorded
`spf=fail, dmarc=fail`. The new setup must keep this, and a test pins it.

**F3. opendmarc judges "same organisation" crudely.** DMARC allows
`mail.example.co.uk` to count as `example.co.uk`. That needs a list of
public suffixes. Our opendmarc is configured without one; rspamd ships one.

## Defect in today's setup

**B1. A Spamhaus error answer would refuse all mail.** Postfix is told
`reject_rbl_client zen.spamhaus.org`, which refuses on *any* answer. When
Spamhaus decides a resolver is public or over quota it answers every query
with an error code (`127.255.255.x`), and Postfix would then refuse every
sender. Spamhaus documents this and says to accept only real listing codes:
`zen.spamhaus.org=127.0.0.[2..11]`, `dbl.spamhaus.org=127.0.1.[2..99]`.
Dev's resolver gets real answers today (checked), so nothing is broken now.
Both installers carry the rule. *Read from Postfix and Spamhaus
documentation, not reproduced.* Fixed by WP0.

## End state

- A mail box runs **Postfix and rspamd**. opendkim, opendmarc, their two
  config files, two `/etc/default` files, two services, two ports and the
  `dbconfig-no-thanks` workaround are gone.
- Postfix's checker list is one entry: `smtpd_milters = inet:localhost:11332`.
  The "opendkim first, then opendmarc, then rspamd" ordering rule disappears.
- rspamd writes the one verdict line the app reads, in our mail host's name,
  and strips any verdict line that arrived with the message. It takes the
  name from Postfix (`myhostname`, already held equal to
  `mailbox_mail_hostname`), so the "keep the name in step in two config
  files" step disappears.
- rspamd signs outgoing mail for ordinary domains on a site box
  (`non_smtpd_milters = inet:localhost:11332`). Keys keep the selector
  `mail`, so **no DNS record changes**. They move from `/etc/opendkim/keys/`
  to rspamd's own key directory. Protected domains are untouched: the app
  still signs those.
- Still true: rspamd never refuses or delays mail, has no memory, and the
  app makes the final decision. A stopped rspamd lets mail through unchecked
  (`milter_default_action = accept`) and the health check reports it.

Configuration sketch, to be checked against rspamd 3.8.1 while building:

```
# milter_headers.conf — unchanged, plus the removal made explicit
routines { authentication-results { remove = 0; } }   # 0 = strip every arriving line

# dkim_signing.conf — new
selector = "mail";
path = "/var/lib/rspamd/dkim/$domain.$selector.key";
sign_local = true;
allow_username_mismatch = true;
try_fallback = false;        # no key for the domain -> no signature
use_redis = false;
```

## Decided

**Q1. Where are Spamhaus-listed senders refused? In Postfix (option a),
owner 2026-10-05.** It is the one spam rule that stays outside rspamd,
because it is a decision about the connection and Postfix is the only
program present when the connection opens.

| Option | For | Against |
|---|---|---|
| **a. Stay in Postfix (chosen)**, with the B1 fix | Refused before the message is sent, so a listed sender costs almost nothing and never reaches the recipient lookup. Works when rspamd is stopped. One line of configuration. | One spam rule lives outside rspamd. Spamhaus is asked twice for senders that pass. |
| b. Move into rspamd | Truly one place. rspamd understands Spamhaus's error codes itself. | rspamd only sees a message after all of it has arrived, so a relay receives every listed sender's mail in full before refusing. Today a listed sender is refused before we say whether the address exists; in rspamd the address check answers first, so a listed sender can probe for valid addresses. Breaks the plain "rspamd never refuses" rule (`reject = null`) into "never, except these symbols". A stopped rspamd lets listed senders in. rspamd does not look up the envelope sender's domain by default, so matching today's three checks needs an added rule. |

## Work packages

- **WP0. Fix B1.** Independent of the rest; can ship first.
  - *The rule.* Postfix refuses only on Spamhaus's real listing answers.
    Any other answer, including an error code, lets the sender through to
    the remaining checks:

    ```
    reject_rbl_client   zen.spamhaus.org=127.0.0.[2..11],
    reject_rhsbl_helo   dbl.spamhaus.org=127.0.1.[2..99],
    reject_rhsbl_sender dbl.spamhaus.org=127.0.1.[2..99],
    ```

    Same position in `smtpd_recipient_restrictions` as today (before the
    recipient lookup), in `install_email.sh` and `provision_relay.sh`. Both
    already rewrite the whole setting on each run, so existing boxes pick it
    up when they converge; relays need the new relay version.
  - *Make a blocked resolver visible.* With the fix, a box Spamhaus has
    stopped answering accepts listed senders silently. Each box asks
    Spamhaus's permanent test entry (`2.0.0.127.zen.spamhaus.org`, which
    always answers `127.0.0.2`) and reports "Spamhaus is not answering this
    box" when it gets an error code or nothing: a row in the mailbox setup
    check on a site box, a field in the relay's status report. A warning,
    never a reason to refuse mail.
  - *Tests.* The guard test `mailbox_postfix_acceptance_limits` requires the
    three filtered forms in both installers and fails on a bare
    `zen.spamhaus.org` or `dbl.spamhaus.org`. Live on dev: `postconf` shows
    the filtered rule; an ordinary message still arrives; the setup check
    row is green.
  - *Versions.* Installer, relay and mailbox plugin versions move (the
    plugin version is what makes site boxes converge promptly).
- **WP1. Confirm F1.** Done, see F1.
- **WP2. rspamd writes the verdict on purpose.** Make the removal explicit
  in `rspamd_stateless.sh`, so it no longer rests on a default. The parser
  already reads rspamd's lines in production; add lines captured from real
  dev messages to its test (a quoted long address in `smtp.mailfrom`,
  `reason="..."`, a bracketed comment after a result).
- **WP3. rspamd signs.** `dkim_signing.conf` in `rspamd_stateless.sh`.
  `provision_dkim.sh` makes keys with `rspamadm dkim_keygen` and writes them
  to rspamd's directory. The installer moves existing keys once.
  `joinery-dkim-remove` and the three places that read
  `/etc/opendkim/keys/<domain>/mail.txt` (`InboundEmailSetupCheck`,
  `PostfixProvider`, `managed_domain_prepare`) use one shared path helper.
- **WP4. Remove the two programs.** Both installers: set the one-entry
  checker list, reload Postfix, *then* stop, disable and purge opendkim and
  opendmarc and delete the files we wrote for them. Order matters so no
  message arrives while Postfix still points at a stopped program.
- **WP5. Everything that names them.** Setup check rows (`host.opendkim`,
  the 8891/8893 wiring check), `listener_admin` wording, the decommission
  unit list in `provision_relay_main.sh`, the relay's status report
  (`relay_apply.go`, `relay_status.go`), the support bundle file list in
  `JobCommandBuilder`, tests, and the mailbox, email system and server
  manager docs.
- **WP6. Release.** New relay version; relays re-provision. Site boxes
  converge on their own.

**Sequencing:** both installers carry other sessions' uncommitted work
(relay 3.5 and 3.6). Build this after those are committed.

## Tests

- Guard: both installers install neither opendkim nor opendmarc, and set the
  one-entry checker list (`mail_stack_packages_test` extended).
- Parser: captured rspamd lines for pass, fail, none, two signatures, and a
  forged line in our name.
- Live on dev: the F2 forged-line probe still records `fail`; a message from
  a domain with good SPF and DKIM records `pass`; a message sent by dev for
  an ordinary domain carries a valid `mail` selector signature (checked by
  an outside receiver); a protected domain's mail is still signed by the app
  and signed once.
- Relay: `relay_provision_idempotence`, `relay_upgrade` and `relay_health`
  pass with the new status shape, including a relay upgraded from the old
  version.
- Stopped rspamd: mail still arrives, recorded `unverified`, and the health
  check goes red.

## Risks

- **rspamd becomes the single checker.** Today a stopped rspamd loses spam
  scoring; after this it also loses the SPF/DKIM/DMARC verdicts and outgoing
  signatures for ordinary domains. The health check already watches it; the
  signing loss is new and needs its own line there.
- **An rspamd upgrade changes its header wording.** The parser test pins
  3.8.1's lines, so a change shows up as a red test, not a silent
  `unverified`.
- **Outgoing mail now passes through rspamd.** It never refuses. Whether it
  also stamps spam headers on our own outgoing mail is unchecked; if it does,
  switch that off for local mail. Confirm on dev before release.

## Related

- `spam_learning_in_core` — made rspamd stateless and moved learning into
  the app. This is the next step in the same direction.
- `DEFERRED_receive_mail_without_postfix` — independent of this. One
  checker behind one port also makes any later change of mail server
  smaller.
