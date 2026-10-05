# Receive mail without Postfix

**Status: DEFERRED (owner, 2026-10-05) — interesting, not scheduled.** The owner
is not ready to take on the standing security watch a port-25 program of our
own needs (Q1). The listener is not built. What was done before deferring
stands on its own: B1 and B2 are fixed in both installers, and the library
evaluation and the laboratory comparison ran on dev (results below). The
live half of the shadow run was not done. B3 and B4 stay open as defects in
today's setup.

Depends on `relay_receive_only_forwarding` and `own_mail_server_sending`
landing first: they take sending away from Postfix, which is what makes the
rest small enough to consider.

## What this does for the user

A machine that receives mail runs one small program of ours on port 25
instead of Postfix and its helpers. For the operator that means:

- **Mail setup has fewer ways to drift.** There is no Postfix configuration
  to converge, so a class of "mail silently stopped" faults goes away.
- **Sealed mail is sealed before it is ever saved.** Today a message waits
  readable in Postfix's queue on the relay's disk before our sealer gets it
  (B3 below).
- **A machine of ours cannot send on port 25,** because the program that
  holds the port has no code for sending. Today that is a rule we enforce on
  Postfix; after this it is a property.

Sending mail from a machine's own mail server stays what
`own_mail_server_sending` makes it: a paid plugin. That plugin installs and
owns Postfix. Core carries none.

## What Postfix does for us today

Read from the installers and the code, 2026-10-05.

| # | Job | Site box (`install_email.sh`) | Relay (`provision_relay.sh`) |
|---|---|---|---|
| 1 | Answer port 25, speak SMTP and TLS | yes | yes |
| 2 | Refuse senders on Spamhaus lists (zen, dbl on HELO and sender) | yes | yes |
| 3 | Refuse mail for domains we don't host | `virtual_mailbox_domains` pgsql map | `relay_domains` hash map |
| 4 | Refuse unknown recipients during the conversation | pgsql lookup (`render_pgsql_map.php`) | hash + regexp maps the sealer derives (`merge.go`), then `postmap` and `postfix reload` |
| 5 | Stamp SPF/DKIM/DMARC verdicts | opendkim + opendmarc milters | same |
| 6 | Spam score | rspamd milter, when provisioned | rspamd milter, stateless |
| 7 | Bound one client's flood | Postfix defaults | 120 connections and 300 messages a minute per client |
| 8 | Save the message, then hand it on | queue → pipe → `inbound_email_handler.php` (argv: recipient, sender, queue id) | queue → pipe → `relay-sealer` (argv: recipient, sender) |
| 9 | Retry when the handler says "later" (exit 75) | up to 5 days | same |
| 10 | Bounce when the handler says "no such user" (exit 67) | sends a bounce on port 25 | same |
| 11 | Send mail out | local sendmail provider; forwards | forwards and SRS bounces (`forward.go`) |
| 12 | Sign outgoing mail | opendkim on locally submitted mail | no |

Jobs 10 to 12 are sending. The two specs this depends on remove 11 and 12
from core. Job 10 is the backscatter that `mail_reject_at_smtp_time`
narrowed and that B8 (relay expiry bounce) still has open.

**What we carry to keep it working:** about 2,560 lines of installer shell
across five scripts, 576 lines of Go that turn our routing data into Postfix
map files, and a 4,346-line setup checker, much of which asks Postfix what
its configuration currently is. Three recent bugs lived in this wiring
(milter list reset, backscatter, cron sendmail leak).

**What our code asks Postfix at run time:** `postconf` (setup checks, spam
policy, deferral expiry), `postmap` and `postfix reload` (relay map merge),
`postqueue -p` (relay status), `/usr/sbin/sendmail` (relay forwarding).

## Defects in today's setup found while researching this

These stand whether or not this spec is built.

- **B1. The smuggling guard was off. FIXED** (`install_email.sh` 2.23,
  `provision_relay.sh` 3.6, guard test `mailbox_postfix_acceptance_limits`).
  Proven on dev before the fix: Postfix 3.8.6 accepted a second message
  with a forged sender hidden inside the first, for three of five line-ending
  tricks. After the fix all five arrive as one message, and ordinary mail
  with bare line feeds is still accepted. Relays get it with the 3.6 update.
- **B2. Mail over 10 MB was refused although we promise 25. FIXED** in the
  same two installers. Dev now advertises and accepts 26,214,400 bytes and
  refuses a declared 27 MB at the first command.
- **B3. A relay does write readable mail to its disk.** The mailbox overview
  said "Plaintext is never written to the relay's disk." That is true of our
  sealer. Postfix saves every message to its queue directory before it
  starts the sealer, and no installer moves that queue off disk. The
  overview now says so. Graded *read, not traced*: I read Postfix's design
  and our installer, and did not inspect a relay. Putting the queue on a RAM
  disk is not a fix, because a reboot would then lose mail we had already
  accepted. No fix short of this spec.
- **B4. Editing a plugin's host installer does not make the converger run
  it.** The converger's change hash covers `install_tools/*.sh` and every
  `plugin.json`, not the installer a plugin declares. A fix to
  `install_email.sh` waits up to a day unless the plugin version moves too.
  Open.

## The costs

### C1. We become the thing the internet talks to

Postfix has had 25 years of strangers attacking it. A program of ours on
port 25 starts with none. What we would own:

- **Protocol parsing.** Not written by us. The candidate library,
  `emersion/go-smtp` (MIT, v0.25.0 from August 2026, imported by 438 other
  Go packages), parses the conversation. It fixed its own smuggling hole in
  v0.20.1.
- **Limits.** Ours to set and test: connections per client, messages per
  minute, errors before we hang up, how long a silent client may hold a
  connection, how many recipients, message size.
- **Memory.** The sealer holds each message in memory. Today Postfix runs at
  most 5 handlers at once, so the ceiling is 5 × 25 MiB. A listener of ours
  has to enforce that ceiling itself, or 200 slow senders of large messages
  exhaust the box.
- **Security watch, forever.** Postfix fixes arrive through the operating
  system's automatic updates with no work from us. Fixes to our program need
  us to notice, rebuild and release. This cost never ends, and it is the
  one I weight most.

### C2. We own "never lose a message"

Saying "accepted" is a promise. Postfix keeps it by saving to disk first and
retrying for 5 days. We would need:

- save (sealed, on a relay) and flush to disk before answering "accepted";
- our own retry schedule when the site's handler or database is down;
- a survivable restart: nothing accepted is lost when the program is killed
  mid-message;
- duplicate handling. A sender that times out waiting for our "accepted"
  sends the message again. Postfix has the same exposure today.

The relay sealer already does the first (temp file, flush, atomic rename).
The retry schedule and restart recovery are new.

### C3. The long tail of odd senders

See the next section. It is the cost hardest to see in advance.

### C4. Verifying SPF, DKIM and DMARC ourselves

opendkim and opendmarc go when Postfix goes, because they only speak
Postfix's plug-in protocol. The verdict they stamp is one protection levels
rely on. Replacing them means Go libraries doing the same checks in our
program. A wrong "pass" lets forged mail look genuine; a wrong "fail" marks
real mail as suspect. The candidate libraries are not yet evaluated (WP1).

rspamd can stay as it is and be asked over HTTP instead. It cannot be the
only verifier, because it is optional on a site box.

### C5. A second way to install mail

The enterprise sending plugin keeps Postfix. So for as long as that plugin
exists there are two receive paths to test: ours, and the plugin's Postfix.
The plugin must take port 25 over cleanly, or run its Postfix for sending
only and leave receiving to us. The second is simpler and is what this spec
assumes.

### C6. Small things Postfix gives for free

- `sendmail` for cron and system mail. Gone with Postfix; anything on the
  box that mails root needs another route or silence.
- Operators' familiarity. `mailq` and `mail.log` are known to every admin.
  Ours would be new and need the same visibility in the admin pages.
- A TLS certificate setup. No installer of ours sets one for Postfix, so it
  presents the operating system's default. Our program needs a certificate
  either way; the relay already holds one for its HTTPS listener.

## What "the long tail of odd senders" means

Most mail comes from a few large senders that follow the rules. The rest
comes from thousands of programs, many old and unmaintained, each wrong in
its own way. Postfix accepts nearly all of them because each tolerance was
added after someone's real mail bounced. A new listener meets them again.

The failure is quiet from our side. The sender gets the error. We hear about
it days later as "I emailed the club and it bounced."

| # | What the sender does | Who does it | What Postfix does | What we would have to decide |
|---|---|---|---|---|
| L1 | Ends lines with a bare line feed instead of the required pair | old scripts, appliances, home-made mailers | accepts and normalises (3.9); this is also the smuggling vector | accept and normalise inside the message, but demand the exact end-of-message marker. mox rejects bare carriage returns outright since its v0.0.9 |
| L2 | Sends the message in chunks (BDAT) instead of the classic way | Microsoft 365 and Outlook.com, whenever a server says it supports chunks | supports it | simplest safe answer: don't offer chunks, and every sender falls back to the classic way |
| L3 | Sends lines far longer than the 998-character rule | web apps, newsletters, machine-generated HTML | accepts; folds lines past 2,048 | accept and pass through untouched, since changing the bytes breaks DKIM |
| L4 | Sends 8-bit or raw UTF-8 without declaring it, or stray zero bytes | many | passes bytes through | same |
| L5 | Sloppy envelope addresses: a space after the colon, no angle brackets, odd quoting, a trailing dot, extra parameters we never offered | old mailers, some bulk senders | accepts (`strict_rfc821_envelopes = no`) | how forgiving the library's address parser is, is not yet known (WP1) |
| L6 | The empty sender `<>` | every bounce and many auto-replies | accepts | must accept; refusing it breaks bounce delivery for everyone |
| L7 | A greeting that is missing, an IP address, or nonsense | appliances, misconfigured servers | accepts | accept; never judge on it alone |
| L8 | Unusual command order: a second greeting, RSET or NOOP between messages, hanging up without saying goodbye | common | handles all | a message is delivered once we said "accepted", even if the sender then vanishes. The library has two open issues in this area (#236, #281) |
| L9 | Only speaks old encryption (TLS 1.0 or 1.1, old ciphers) | old Exchange servers, scanners, embedded devices | accepts TLS 1.0 and up | Go refuses below TLS 1.2 by default. A sender whose handshake fails may fall back to no encryption, may retry for days, or may bounce. One survey puts over 97% of mail on TLS 1.2 or newer |
| L10 | Pauses for minutes mid-message | slow links, overloaded senders | waits up to 5 minutes per step | long patience for real senders without letting an attacker hold connections open cheaply |
| L11 | Opens many connections at once | Gmail and Microsoft during a burst | allows 50 per client by default | when full, answer "try later" rather than dropping the connection |
| L12 | Gives up early on "try later" | some bulk and transactional senders retry only a few times | n/a | every temporary refusal costs some real mail, so "the database is down" must be rare and short |
| L13 | Sends a message bigger than our limit | anyone | refuses at the first command when the sender declares the size | read the declared size and refuse before the body, or we receive 25 MB just to say no |
| L14 | Uses non-ASCII addresses | rare, growing | supports | offer it only if every later step handles such addresses |
| L15 | Is not a mail sender at all | scanners, password guessers, web browsers, monitoring probes that connect and send nothing | error limits, junk-command limits | hang up fast and cheaply; never log an error for a probe |

L2, L9 and L12 are where real mail from real organisations is most at risk.
L1 is where a wrong choice is a security hole.

**How to take the guessing out of it.** Run the new program beside Postfix
on live mail before it carries any (WP2). The lists above then become
counts: how many senders used old TLS this month, how many sent bare line
feeds, how many envelopes the parser would have refused.

## What the evaluation found (2026-10-05)

Tools and raw results are kept outside the tree in `/home/user1/smtp-shadow`
(`shadowd` the trial listener, `diff_run.py` the 54 odd sessions,
`authcheck` the DKIM comparison, `run1.txt` and `results_*.json`).

### The SMTP library against dev's Postfix: 54 odd sessions

Each session was sent to dev's Postfix and to a trial listener built on
`emersion/go-smtp` v0.25.0. 32 got the same answer from both. The
differences:

| Session | Postfix | Library | Verdict |
|---|---|---|---|
| Five smuggling tricks | accepted a forged second message in 3 (before B1) | one message, all 5 | library is safe as shipped |
| Sender or recipient before any greeting | accepts | refuses (502) | **gap**: needs a patch to the library, or we lose such senders |
| Body line over 2,000 characters | accepts 100,000 | refuses and hangs up by default; accepts with the limit raised | a setting; must be set |
| TLS 1.0 or 1.1 only | accepts | refuses by default; accepts when told to | a setting; Q2 |
| Chunked sending (BDAT) | accepts | accepts | same. The library cannot switch chunks off without a patch |
| Mail parameters we don't offer (SMTPUTF8, RET, NOTIFY) | accepts | refuses (504) | not a gap: a sender only uses what a server offers |
| 150 recipients | accepts all | accepts 100, "try later" for the rest | our setting |
| 5 bad commands in a row | tolerates 20 | hangs up after 4, fixed in the library | acceptable |
| A web browser's request | hangs up at once | answers each line with an error | cosmetic |

Same answer from both, and worth knowing: sloppy addresses (space after
the colon, no angle brackets, quoted names, source routes, trailing dot),
the empty sender, junk greetings, commands and bodies ending in bare line
feeds, 8-bit and zero bytes, everything sent in one burst, speaking before
the greeting, hanging up without goodbye.

Also read in the library's source, not exercised: it has no limit on
connections per client, no total time limit per connection, and no ceiling
on memory. Those are ours to add around it. It builds with the Go version
the relay sealer already uses (1.22).

So L5, L6, L7 (mostly), L8 and L1 are answered by the library as it is.
L2, L9 and L3 are settings. One real patch is needed (mail before greeting).

### DKIM library against opendkim: 2,057 real messages

Corpus: every message posted to seven public Apache mailing lists in
September and October 2026, 2,047 of them signed, 47 signing domains.
Each was verified by `emersion/go-msgauth` v0.7.0 and by opendkim's own
test tool.

| Result | Messages |
|---|---|
| both pass | 1,328 |
| both fail (expired signature, body changed by the list, bad signature) | 719 |
| disagree | **0** |

Limits of this result: passes came from only 9 domains (mostly apache.org
and gmail.com), and none of it is mail addressed to us. It shows no
disagreement, not that none exists.

### SPF and DMARC libraries: a sanity check only

`blitiri.com.ar/go/spf` v1.6.0 gave the expected answer for 15 hand-picked
cases against live DNS, IPv4 and IPv6. Three things to carry forward:

- It needs Go 1.25; the relay sealer builds with 1.22.
- It enforces the 10-lookup limit strictly. uber.com's record exceeds it
  for a non-matching address and is reported as a permanent error. Whether
  opendmarc is as strict has not been compared.
- `go-msgauth`'s DMARC part looks up and parses a policy. Deciding
  alignment, and falling back from a subdomain to the organisation's
  domain, would be our code.

There was no reference to compare SPF and DMARC against. Dev stores no
readable raw mail, so the "compare with what our milters stamped" test in
WP1 could not be run here. It needs a machine that receives real mail.

### The live half of the shadow run: not done

Copying live sessions for replay is not possible from outside: they are
encrypted, and the log that would show them needs root. Two ways forward,
both needing the owner:

1. **Counts from the log we already have.** `logcount.sh` in the tools
   directory prints numbers only (no addresses, no content): hang-ups by
   stage, pipelining abuse, bare line feeds, handshake failures, commands
   used. Run as root on dev and on a relay. TLS versions appear only once
   `smtpd_tls_loglevel = 1` is set.
2. **A verdict comparison in the arrival path.** A small program run on
   each arriving message recomputes SPF, DKIM and DMARC with the Go
   libraries and logs whether it agrees with the header the milters
   stamped. No mail is affected. This is the test C4 needs.

## The shape, if built

One listener, the same on both kinds of machine, living in the Go program
relays already run.

- **It fits behind the handoff that exists.** Today Postfix starts a handler
  with the recipient, the sender and a queue id, gives it the message, and
  reads back 0, 67 or 75. On a site box the listener does exactly that with
  the same PHP handler, so nothing in the PHP mail path changes. On a relay
  it calls the sealing code directly.
- **It asks our own data who exists.** A site box asks the database with the
  query the pgsql map uses today. A relay reads the routing data it already
  holds in memory. No map files, no `postmap`, no reload.
- **It never bounces.** A message refused after acceptance is logged and
  dropped. The program has no code that connects out on port 25, so B8
  cannot recur.
- **It saves before it accepts,** sealed where the domain is sealed, and
  retries the handoff on its own schedule.
- **It is self-contained.** Nothing else in the platform needs to know which
  program holds port 25, beyond the installer and the setup checker.

## Alternatives

| Option | In plain terms | Catch |
|---|---|---|
| A. Keep Postfix, fix B1 and B2 | cheapest; today's drift bugs are already fixed | B3 stays, config drift stays, B8 needs its own fix |
| B. Our listener on relays only | relays are disposable, already run our Go program, and are where B3 and the map files live | a site box keeps Postfix, so two receive paths exist until C follows |
| C. Our listener everywhere | one receive path; installer and checker shrink the most | carries every cost above on self-hosted boxes too |
| D. Swap Postfix for another whole mail server (mox, maddy, Stalwart) | someone else's listener | trades a proven server with config files for a less proven one with config files; gains nothing here |

**Recommendation:** A now, since B1 and B2 are defects regardless. Then WP1
and WP2, which cost little and turn C3 and C4 into numbers. Then decide
between stopping at A and going to B. C follows B only after a relay has
carried real mail on the new listener without a lost or wrongly refused
message.

**What would make the answer "stop at A":** the shadow run shows refusals we
cannot explain; the DKIM library disagrees with opendkim on real mail; or we
are not willing to own C1's security watch.

## Work packages

1. **WP1 — Evaluate the parts.** Read `emersion/go-smtp`'s server code for
   L1, L5, L8, L13 and its limits. Pick and test Go libraries for SPF, DKIM
   and DMARC against a corpus of our own stored raw messages, comparing
   each verdict with the one opendkim and opendmarc stamped at the time.
   Output: a list of gaps, each with a fix or a "this stops the project".
2. **WP2 — Shadow run.** The listener runs on a spare port. A copy of every
   live SMTP session on a relay is replayed to it (Postfix keeps carrying
   the mail). It records what it would have answered. Compare with what
   Postfix answered, message by message. Output: counts for L1 to L15 and
   a list of every disagreement.
3. **Decision point.** Stop at A, or continue.
4. **WP3 — Relay.** Listener, limits, save-then-accept, retry, restart
   recovery, status for the plane's relay page. `provision_relay.sh` stops
   installing Postfix, opendkim and opendmarc. `merge.go` loses its map
   derivation.
5. **WP4 — Site box.** Same listener driving the PHP handler.
   `install_email.sh` and the setup checker lose their Postfix sections.
   An install that has the sending plugin keeps Postfix for sending only.
6. **WP5 — Upgrade path.** An existing install switches without a gap on
   port 25 and without losing what sits in Postfix's queue.

## Tests

- A corpus of deliberately odd sessions, one per row L1 to L15, each with
  the expected answer.
- Smuggling: every published variant of the end-of-message trick is refused
  or delivered as one message.
- Kill the program at each step of an acceptance; nothing we said
  "accepted" to is lost, and nothing is delivered twice.
- Handler down for an hour: mail is retried and arrives.
- Memory stays bounded with 500 slow connections each sending 25 MiB.
- IPv4 and IPv6 for every address check (client limits, Spamhaus lookups).
- Existing gates that assume Postfix: `relay_provision_idempotence_gate`,
  `recipient_lookup`, `setup_topology`, `setup_verdict`.

## Documents this would change

Mailbox overview (installation, the relay section, B3's sentence), Email
System, Deploy and Upgrade, `mailbox_security_model_public`, the relay
sealer README.

## Open questions

- **Q1.** Who notices when our port-25 program needs a security fix, and
  how fast does the fix reach every machine? Postfix answers both for free:
  Ubuntu publishes the fix and each machine installs it overnight by
  itself. Our own program gets neither. A yes means accepting three things:
  a scheduled check of the library's releases and of new mail-attack
  research, with someone acting on it; a release of ours whenever one
  lands; and a way to push that release to relays, which today are updated
  by hand and are deliberately not managed by the agent. A no ends the idea
  at alternative A.
- **Q2.** Old encryption (L9): accept TLS 1.0 as Postfix does today, or
  require 1.2 and let those senders fall back to none? Wait for WP2's count.
- **Q3.** Can a relay's live sessions be copied for the shadow run without
  storing readable mail anywhere new? If not, WP2 runs on a site box that
  holds only Standard mail.

## Sources

- Postfix on SMTP smuggling: https://www.postfix.org/smtp-smuggling.html
- go-smtp releases: https://github.com/emersion/go-smtp/releases
- go-smtp package: https://pkg.go.dev/github.com/emersion/go-smtp
- mox on bare carriage returns and its testing: https://github.com/mjl-/mox
- TLS 1.0/1.1 retirement and its effect on delivery:
  https://www.suped.com/learn/mta-sts/what-are-the-implications-of-disabling-tls-1011-for-outgoing-email-and-what-alternatives-are-ava
- Microsoft 365 and BDAT:
  https://learn.microsoft.com/da-dk/exchange/troubleshoot/email-delivery/ndr/fix-error-code-550-5-6-11-in-exchange-online
