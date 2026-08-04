# Plain-language authentication readout

## Problem

Two problems, one of them a live defect.

**1. Three display surfaces each kept their own list of trusted sources.**

```php
$auth_verified = ($auth_source === 'milter' || $auth_source === 'mailgun');   // admin page
var verified   = (m.auth_source === 'milter' || m.auth_source === 'mailgun'); // reader JS
var verified   = (a.source === 'milter' || a.source === 'mailgun');           // send-test tool
```

The router writes **`milter`, `relay`, `mailgun`, `sendgrid`, `ses`, `none`**. All
three lists had fallen behind it, so every relay-fronted or SES deployment
displayed *"Authentication: unverified (no verifying milter)"* on mail its verifier
had **fully checked and passed**.

On jeremytunnell.com that is 333 of 341 messages received in the last 30 days —
`iem_auth_source = 'relay'`, `spf/dkim/dmarc = pass/pass/pass` in the database,
rendered as unverified on screen. The failure is silent in exactly the wrong
direction: it understates trust, so nothing looks broken, it just looks like
authentication never works.

**2. The wording was jargon.** *"no verifying milter"* names an internal MTA
plumbing concept. It also implies a fault, when the commonest cause by far is
benign — imported and IMAP-collected mail was never received here, so there was
nothing to verify. On a deployment that has imported an archive that is the
**majority** of stored mail (1,932 of 2,273 on jeremytunnell.com).

## Change

One helper pair on the model that owns the column, and every surface asks it.

```php
InboundEmailMessage::authIsVerified(?string $source): bool
InboundEmailMessage::authReadout($source, $spf, $dkim, $dmarc, $origin = null): array
```

`authIsVerified()` is derived from the source→name map rather than a hand-kept
list, so it **cannot** lag the router again. `authReadout()` returns a state, a
plain-language headline, supporting detail, and who did the checking:

| `state` | Headline | When |
|---|---|---|
| `verified` | Sender verified | DMARC `pass`, or no DMARC verdict with SPF and DKIM both `pass` |
| `failed` | Sender could NOT be verified | DMARC `fail`, or no DMARC verdict with SPF and DKIM both `fail` |
| `partial` | Sender partly verified | trusted source, mixed results — including a source that asserted nothing |
| `unchecked` | Sender not checked | no trusted source, **plus the reason** |

The `unchecked` reason follows the ingest path, because "why wasn't this checked"
is the question the headline raises:

- import → *imported from a mail archive, so it never arrived here to be checked*
- IMAP → *collected from another mailbox, which did its own checks*
- neither → *this message did not arrive through your mail server*

**It is a readout, not a disposition.** What a verdict does to a message stays
`InboundEmailRouter::classifySpam()`'s call; these states are deliberately coarser
than the filing rule and nothing reads them back.

### Per surface

- **Reader** — `Sender verified · checked by your mail relay`, acronyms on hover
  (`title`). Colour only on a real failure; `unchecked` stays neutral grey, or the
  warning stops meaning anything on an archive-heavy mailbox.
- **Admin message detail** — headline **and** the three raw verdicts. This is the
  diagnostic view; an operator chasing a delivery problem needs to see which of
  the three failed.
- **Send-test tool** — `verified` now resolved server-side from
  `authIsVerified()`; the explainer keeps the actionable instruction
  (`opendkim` + `opendmarc`, Inbound Email → Setup) but leads with what happened.
- **API/native** — the whole readout ships in the thread payload under `auth`, so
  no client reimplements any of it.

## Files

| File | Change |
|---|---|
| `plugins/mailbox/data/inbound_email_message_class.php` | `$AUTH_SOURCE_NAMES`, `authIsVerified()`, `authReadout()`; v1.18 |
| `plugins/mailbox/includes/MailboxService.php` | Thread payload carries `auth`; selects the two ingest-path columns; v1.19 |
| `plugins/mailbox/assets/mailbox_reader.js` | `authText()` renders the readout; new `authTitle()`; state class on the line |
| `plugins/mailbox/assets/mailbox_reader.css` | `.mbx-auth` states — colour only on failure |
| `plugins/mailbox/admin/admin_mailbox_message.php` | Headline + raw verdicts via the shared helper; v1.6 |
| `utils/email_send_test.php` | `verified` resolved server-side; explainer rewritten; v2.2 |
| `plugins/mailbox/docs/overview.md` | Readout table; import section |
| `plugins/mailbox/tests/auth_readout_test.php` | **New** — 48 checks |

## Verification

- `php tests/run.php safe` → 83 tests, 2040 checks, PASS
- `php tests/run.php db --filter=mailbox` → 52 tests, 1514 checks, PASS
- Live on dev, all three states through the admin message view:
  - `Sender verified · SPF: pass DKIM: pass DMARC: pass (checked by this mail server)`
  - `Sender could NOT be verified · SPF: pass DKIM: none DMARC: fail (checked by this mail server)`
  - `Sender not checked — imported from a mail archive, so it never arrived here to be checked`

The regression guard asserts every source the router can write reads as verified,
against the source map rather than a copy of the list — the drift that caused this
cannot recur silently.

## Open

- **Not yet deployed.** jeremytunnell.com keeps showing "unverified" on its
  relay-verified mail until the next upgrade.
- The reader's own rendering was verified by serving the asset and by unit test,
  not by eye — the dev admin account's mailbox is empty and its vault locked, so
  no thread was reachable to open. Worth a look after deploy.
