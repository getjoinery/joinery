# Refuse unknown recipients during the SMTP conversation

**Status:** Implemented 2026-10-05 (install_email.sh 2.22, render_pgsql_map.php 1.5, relay 3.4).
Verified live on dev: unknown address refused at RCPT with 550 5.1.1; alias, catch-all, postmaster
and SRS accepted and routed; queue empty (no bounce); a lookup that cannot read the database
answers 451 4.3.5; an IMAP feed's anchor domain is refused as a relay attempt. Reviewed by
public-html-0f (B1-B10 fixed, re-verified). The testing still to run (a forced deferral to queue
expiry, fleet nodes and relays after the release, the plugins page in a browser) is in the live
verification queue memory, under this spec's name. Split out of
`own_mail_server_sending` (review finding M3, public-html-d7) because it fixes
a live problem on its own and depends on nothing. It ships first.

## What this does for the user

When mail arrives for an address a site does not have, the sending server
should be told "no such address" while it is still connected. Then the real
sender's own mail system tells them, and our server sends nothing.

Today a colocated box (one that receives its own mail) accepts the message
first and decides afterwards. When it then refuses, Postfix writes a bounce
notice and mails it to the sender address on the message. Spam almost always
carries a forged sender, so those bounces land on innocent strangers. That
is called backscatter, and it is one of the classic ways a mail server gets
onto a blocklist. A blocklisted address hurts every site on it.

The relay fleet already refuses during the conversation. This brings a
colocated box to the same behaviour.

## Where it happens today

1. Postfix accepts mail for any address on a domain the site receives for
   (`virtual_mailbox_domains`, a pgsql lookup of enabled domains, in
   `install_email.sh`). It does not check the address itself.
2. The pipe hands the message to `inbound_email_handler.php`, which runs
   `InboundEmailRouter`.
3. The router refuses with exit code 67 in these cases:
   - a malformed recipient
   - a domain that is unknown or disabled
   - an address with no matching alias, on a domain with `reject_unmatched`
     on (`InboundEmailRouter.php`, "No match")
4. Postfix turns that 67 into a bounce to the sender.

The relay does step 3 during the conversation. Its `smtpd_recipient_restrictions`
end with `check_recipient_access` against a map of the tenant's listed
addresses and its SRS map (`provision_relay.sh`, "SMTP-time recipient
validation … no backscatter").

## The design

**One recipient lookup, during the conversation.** `install_email.sh` adds
`check_recipient_access` against a pgsql query, through Postfix's `proxy:`
the same way the domain map is read. Given a recipient address, the query
answers:

- **OK** when an enabled alias matches, or when the domain's catch-all would
  take it (catch-all mode `store`, or a catch-all forward address).
- **OK** for an SRS bounce address on a forwarding domain, which the router's
  SRS handling owns. This mirrors the relay's SRS regexp map.
- **REJECT 5.1.1** ("no such address") when the domain is one of ours, is
  enabled, has `reject_unmatched` on, and nothing above matched.
- **No answer** otherwise. The router decides, as now (for example,
  discard-unmatched domains).

It is one query against the same tables the router reads, so the two cannot
disagree. A database outage makes the lookup fail, and Postfix answers "try
again later" (4xx). The sender retries; nothing bounces.

**What stays after acceptance never bounces.** Some decisions can only be made
after the message is read: content, the per-window store cap, and filters.
Those already store, discard, or defer (exit 75), and none of them sends a
bounce.

**A deferral that never clears.** When the pipe defers (75), Postfix keeps
the message and retries it locally. If it is still deferred when Postfix's
queue lifetime ends (five days by default), Postfix bounces it, and that
bounce is backscatter too. The build checks what happens at expiry, and sets
the pipe transport so an expired deferral is logged and dropped rather than
bounced.

The router keeps its exit-67 checks as a backstop. With the lookup in front
they should never fire, so one firing is logged as a mismatch between the
lookup and the router.

## Testing

| Check | Pass |
|---|---|
| mail to an existing alias on a colocated box | accepted and stored |
| mail to an unknown address on a domain with `reject_unmatched` on | refused during the conversation with 5.1.1; no bounce in the queue or the mail log |
| mail to an unknown address on a catch-all store domain | accepted, stored by the catch-all |
| an SRS bounce to a forwarding domain | accepted and routed by the SRS handling |
| the database is stopped, then mail arrives | 4xx "try again later"; nothing bounced |
| a message the pipe defers until queue expiry (forced in the test) | dropped and logged, no bounce |

## Docs to update when this lands

- The mailbox overview: unknown recipients are refused during the SMTP
  conversation on a colocated box as on a relay (§ Server Setup, and the
  `reject_unmatched` setting's description).
