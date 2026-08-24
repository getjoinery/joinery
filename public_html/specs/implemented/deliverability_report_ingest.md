# Deliverability Report Ingest

**Status: UNBUILT — proposed 2026-07-25.** Design only.

## What this is

Mail providers send machine-generated reports about a domain's mail — who sent
as it, what passed authentication, what was rejected, where TLS failed. Those
reports arrive as ordinary email to an ordinary address. This detects them as
they arrive, extracts what they say, and turns it into the one view a domain
owner actually wants:

> **Everything that is sending as your domain, and whether it is authorised.**

Scope is the mailbox plugin's inbound path. Nothing here changes what a domain
publishes in DNS; it consumes what those published policies cause to be sent
back.

## Why

A DMARC policy without report handling is half a feature. The policy tells
receivers what to do with forgeries; the report stream tells *you* that
forgeries — or, far more often, your own forgotten systems — exist at all.

The concrete case this comes from: four separate deployments sent mail as
`jeremytunnell.com` while signing as a subdomain, failing that domain's strict
DMARC on every message. It went unnoticed indefinitely, because the only
mechanism that would have named those senders was aggregate reporting, and the
reports were going to an address nobody read.

That failure repeats for every domain the platform hosts, and it is silent by
construction: the sender never learns its mail is being quarantined, and the
domain owner never learns the sender exists.

## What already exists

- **Catch-all storage** — every hosted domain sets `ied_catch_all_mode` and
  `ied_reject_unmatched`, so report mail already arrives and is stored with no
  alias reserved for it. Nothing needs configuring to start receiving.
- **`InboundEmailRouter`** — the single chokepoint every inbound message passes
  through. One integration point, not many.
- **Attachment extraction and storage** (`EmailAttachmentDigest`,
  `RawMessageStore`) — reports are attachments; the machinery to reach them is
  built.
- **`MailboxSpamPolicy`** — precedent for a policy consulted during ingest that
  changes a message's disposition.
- **Security levels and sealing** — the constraint this design is shaped around;
  see D2.
- **`InboundEmailSetupCheck`** — already prescribes and verifies each domain's
  DMARC record, and is the natural place to report whether that record is
  actually producing reports.

Not to be confused with **`AuthenticationResults`**, which parses the
`Authentication-Results` header on *arriving* mail. That answers "did this
message authenticate?" This answers "what are other providers telling us about
mail claiming to be from us?"

## Decisions

### D1 — Detect by content, never by address

RFC 7489 fixes the shape of an aggregate report: an attachment named
`<receiver>!<policy-domain>!<begin>!<end>[!unique].xml` (gzip or zip), a subject
of the form `Report Domain: … Submitter: … Report-ID: …`, and an XML root of
`<feedback>` containing `<report_metadata>`. Requiring **two of those three**
makes a false positive essentially impossible while tolerating the providers
that get one of them wrong.

Detecting on content rather than on a reserved local part means the `rua`
address is free — `dmarc@<domain>` reads better than `postmaster@` — and a
report misaddressed by a provider is still recognised. Anything not matching is
delivered normally, untouched.

### D2 — Parse during ingest, before the message is sealed

**This is the decision the whole design turns on.** On a Private or Fortress
domain the stored body is encrypted to the holders' keys; the server cannot
read it afterwards by design. A parser that runs later — a scheduled task, an
admin action — would work on Standard domains and silently do nothing on
protected ones, which are exactly the domains whose sender inventory matters
most.

Extraction therefore happens in the ingest path, while the content is still in
hand. What persists afterwards is **derived data**, which is not sensitive: IP
addresses, counts, and pass/fail verdicts about mail *claiming* to be from the
domain. The original message then follows the domain's security level like any
other message.

### D3 — Reports are filed, not delivered

A recognised report does not appear in the human mailbox. It is recorded,
counted, and retrievable from the reports view. A domain publishing DMARC can
receive dozens of these a day; delivering them to an inbox trains people to
ignore that inbox.

### D4 — The derived rows are the product

Storing the files and calling it done reproduces the current situation with
extra steps. The value is per-source aggregation: for each sending IP the
platform records the reporter, the time window, the message count, the
disposition applied, and whether SPF and DKIM aligned. That is what turns into
*here is everything sending as you*.

### D5 — One pipeline for the whole family

Aggregate reports are one of several kinds of machine mail arriving at human
addresses. Building for DMARC alone guarantees a retrofit.

| Kind | Arrives because |
|---|---|
| DMARC aggregate (`rua`) | the domain publishes a DMARC policy |
| DMARC forensic (`ruf`) | the policy requests failure samples |
| TLS-RPT | the domain publishes `_smtp._tls` |
| ARF feedback loop | a recipient marks mail as spam at a large provider |

One detector interface, one parser per kind, one storage shape. A kind the
platform does not yet parse is **recorded as unrecognised and counted** — never
silently dropped, so its arrival is visible before support exists.

### D6 — A parsed report keeps its rows and loses its file

Source rows never expire. They are small, and they are the only historical
record of who has sent as a domain — the question this feature exists to
answer, asked across years rather than days.

The raw report is deleted the moment it parses successfully, because the rows
carry everything it said. A report that **fails** to parse is kept, since the
original is then the only way to learn why: a provider dialect that was not
anticipated, or a malformed submission. Parse failures are rare and worth the
storage; successes are daily per domain and worth none.

This also removes sealing from the retention question entirely — a sealed
domain behaves exactly like any other, rather than accumulating copies that
nothing can open.

### D7 — A new unaligned source is worth one email, once

The moment a report first names a source IP that has never sent as this domain
and did not align is the moment the information is worth something: it is
either a forgery or a system of yours nobody remembered. Both deserve a look,
and neither is visible today.

So the first sighting sends one notification. The source is then **known**, and
never notified about again — subsequent reports about it update the dashboard
silently. The single exception is escalation: a known source whose volume jumps
sharply is notified again, because a trickle becoming a flood is a different
event from the trickle.

This bounds the failure mode. A spam campaign forging a public domain produces
many distinct source IPs, but each costs exactly one email and then goes quiet,
rather than generating a stream for as long as the campaign runs. A dashboard
flag alone was rejected: it is the same shape as the failure this feature
exists to correct, where the information existed and nobody had a reason to go
looking for it.

### D8 — Counters describe human mail

Reports are excluded from message counts, unread badges and storage quotas. A
domain receiving forty reports a day still reads as a quiet mailbox, because
that is what it is — nobody sent those, and a counter that includes them stops
describing anything a person recognises.

D6 is what makes this safe rather than a blind spot: a parsed report leaves no
message behind, so the excluded traffic is not quietly consuming disk. What
does accumulate is source rows, and their volume is visible in the reports view
where it belongs.

### D9 — Report content is untrusted input

A report is XML from a stranger, and the address it arrives at is public.
Consequences that are requirements, not hardening:

- Size ceilings before decompression, and a decompressed-size ceiling — a
  compressed archive is an obvious amplification vector.
- XML parsed with external entity resolution explicitly disabled.
- The reporter's identity is data, not authority: a report naming a domain does
  not grant it anything, and reports for a domain the platform does not host
  are discarded.
- A malformed report is recorded as unparseable and never aborts ingest of the
  message carrying it.

## Data model

Two tables, following the platform's prefix convention:

- **`dvr_deliverability_reports`** — one row per report: kind, reporting
  organisation, the domain it concerns, the time window it covers, the report
  id, a reference to the stored raw message, and a parse status.
- **`dvs_deliverability_report_sources`** — one row per source line within a
  report: source IP, message count, disposition, SPF result and alignment, DKIM
  result and alignment, and the identity domains involved.

The second table is what every view reads. Its natural query — group by source
IP across a window, split by whether alignment passed — is the sender
inventory.

## Surfaces

- **Per-domain reports view** in the mailbox admin: sources over a chosen
  window, with unaligned senders surfaced first, since those are either a
  forgery or a system of yours you have forgotten about. Both are worth a look;
  neither is visible today.
- **Setup tab row** — *aggregate reports arriving*, showing the count and the
  most recent. A DMARC record whose `rua` address is wrong looks identical to a
  correct one in DNS; the only proof it works is reports showing up. This row
  is that proof, and it belongs beside the DMARC row that prescribes the record.
- **New-source notice** — one email the first time a source IP appears that has
  never been seen for a domain and did not align, per D7. Afterwards the source
  is known and updates the view silently.

## Build plan

1. **Detector and dispatch.** The two-of-three test in the ingest path, the
   parser interface, disposition of a recognised report (D3), and unrecognised
   kinds recorded rather than dropped.
2. **Aggregate report parser and storage.** Both tables, the RFC 7489 XML, the
   D6 retention and the D9 safety rules. Fixtures from real Google, Microsoft
   and Yahoo reports,
   whose dialects differ in practice.
3. **Views.** The per-domain reports page and the Setup tab row.
4. **The rest of the family.** TLS-RPT and ARF parsers behind the same
   interface.

## Documentation

- `plugins/mailbox/docs/overview.md` — report ingest in the inbound pipeline,
  the sealing constraint, and the admin surfaces.
- `plugins/mailbox/docs/domain_onboarding.md` — the `rua` address points at the
  domain itself and the platform reads the reports; drop the third-party
  collector guidance.
- `docs/email_system.md` — a sending deployment can be the subject of someone
  else's report; state where that data lands.

## Acceptance

1. A real aggregate report from each of Google, Microsoft and Yahoo parses into
   source rows with correct counts and alignment verdicts.
2. The same report arriving on a **Private** domain still produces those rows —
   the case a post-hoc parser cannot serve.
3. Ordinary mail carrying a gzip attachment is unaffected and lands in the
   inbox.
4. A report kind with no parser is recorded as unrecognised and visible, not
   discarded.
5. A malformed, oversized, or compression-bomb attachment is rejected without
   aborting delivery of the message that carried it.
6. A domain receiving reports shows them in the Setup tab row; a domain whose
   `rua` is wrong keeps showing none, which is the signal.
7. Recognised reports do not appear in any human mailbox but remain retrievable.
8. A successfully parsed report leaves its rows and no stored message; a report
   that fails to parse keeps its original for diagnosis.
9. A first-sighting unaligned source sends exactly one email; the same source in
   later reports sends none, and a sharp volume increase sends one more.
10. A domain receiving reports shows no change in its mailbox message count,
    unread badge or storage quota.

## Open decisions

None — all resolved 2026-07-25 (D6, D7, D8).
