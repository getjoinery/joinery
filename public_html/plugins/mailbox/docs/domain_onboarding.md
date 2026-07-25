# Bringing a Domain onto Hosted Mail

Reference for putting a domain's incoming mail on a Joinery deployment: the
identity layout that lets the domain publish strict DMARC, the record set, the
order that avoids dropping mail, and the provider and DNS behaviours that
silently produce a broken setup.

This is operator guidance. The mechanics it relies on — the Setup tab's DNS
rows, provider DKIM, the relay map — are documented in
[overview.md](overview.md).

## The identity rule

> **The bare domain belongs to humans. Automated senders live on a subdomain.**

A hosted mailbox sends as the bare domain (`info@example.com`, signing
`d=example.com`). Everything a site sends by itself — receipts, password
resets, notifications — sends as `mail.example.com` and signs
`d=mail.example.com`. Each sender signs exactly as the domain it appears to
come from, which is what `aspf=s; adkim=s` requires, so the domain can publish
strict DMARC with every legitimate sender still passing.

Two consequences worth stating plainly:

- **A site must never send as an address that is also a hosted mailbox.** That
  single address would be both a human inbox and a robot sender, and no DMARC
  policy can describe both.
- **Replies to automated mail should still reach a person.** Set the core
  `defaultreplyto` setting to the domain's hosted mailbox, so a reply to a
  no-reply-shaped From lands in `info@` instead of nowhere.

`mail.` is the conventional machine subdomain. Avoid naming it after the
sending vendor: the visible sender identity outlives any particular provider.
Reserve a second subdomain (`news.`) if bulk or marketing mail ever starts, so
its reputation stays separate from transactional mail.

## The record set

For a relay-fronted deployment with provider outbound, a fully configured
domain publishes:

| Record | Value | Why |
|---|---|---|
| `MX example.com` | the relay's MX hostname | inbound arrives at the relay, which spools to the deployment |
| `TXT example.com` | `v=spf1 include:<provider> -all` | authorizes the outbound provider, and only it |
| `TXT <sel>._domainkey.example.com` | provider DKIM | mailbox mail signs as the bare domain |
| `TXT mail.example.com` | `v=spf1 include:<provider> -all` | same, for the machine identity |
| `TXT <sel>._domainkey.mail.example.com` | provider DKIM | site mail signs as its own subdomain |
| `TXT _dmarc.example.com` | `v=DMARC1; p=quarantine; aspf=s; adkim=s; rua=mailto:postmaster@example.com` | strict alignment, reports somewhere real |

Prefer `-all` over the provider's suggested `~all`. A softfail asks receivers
to accept forged mail and merely mark it; a hardfail is the point of publishing
SPF at all. Only use `~all` while an unknown sender may still exist on the
domain.

The Setup tab prescribes and live-verifies these per domain, including the
exact provider DKIM record, so it is the authority on what a given domain still
needs. Publish `p=reject` in place of `p=quarantine` once aggregate reports show
nothing legitimate failing.

**`rua` must be an address that receives.** A report address on a domain you do
not control needs that domain's authorization record, so the practical choice is
one on the domain itself. A domain with catch-all storage or a `postmaster@`
alias satisfies this; without one, the reports are discarded and the policy is
unmonitored.

## Order of operations

Each step is safe to sit in indefinitely. Only step 4 changes where mail goes.

1. **Register both identities with the outbound provider** — the bare domain and
   `mail.<domain>` — and publish their SPF and DKIM records. Purely additive:
   nothing routes here yet, and the domain's existing mail is untouched.
2. **Create the domain and its first alias on the deployment.** `info@` is the
   conventional first mailbox. The relay learns the domain from the map push
   that follows the change; no relay-side action is needed.
3. **Find out what currently receives for the domain, and where it forwards.**
   A domain with no MX is greenfield. Otherwise something is being taken away in
   the next step, and it is worth knowing what.
4. **Point MX at the relay**, and remove the previous inbound path in the same
   pass. This is the cutover: mail follows the new MX as caches expire.
5. **Publish strict DMARC** once both senders are verified aligned.
6. **Switch the site's own sending** to `mail.<domain>` — its From address, its
   provider sending domain, and its Reply-To.

Steps 4 and 6 are independent; do whichever is ready. A site still sending as
the bare domain will fail a strict DMARC policy published in step 5, so if the
site cannot move yet, publish `p=none` and tighten after.

## Provider behaviour that silently breaks setups

**A sending key is not an admin key.** Providers commonly issue keys scoped to
message submission alone. Domain creation, DKIM authority changes and
verification need an administrative credential; a sending key returns a
permissions error, or worse, serves stale domain state that disagrees with what
another key reports. Do domain administration through one known-admin
credential, and treat a surprising read from a second key as stale rather than
as news.

**Subdomains inherit the parent's DKIM authority.** When the bare domain is
already registered, adding `mail.<domain>` typically makes the subdomain sign as
the *parent* — exactly the merge the identity split exists to prevent, and it
looks correct until you read the selector. Force self-authority at creation.
Setting it afterwards works but regenerates the key at the provider's default
size, discarding a requested stronger key, so recreating the domain is cleaner.
Deletion may be asynchronous — expect the immediate recreate to fail and retry.

**Verification is a separate call.** A domain stays unverified, and may refuse
to send, until the provider re-reads DNS. Records being correct is not the same
as the provider having noticed.

## DNS behaviour that silently breaks setups

**A TXT record over 255 characters must be pre-quoted and split.** DNS carries
TXT as a sequence of strings, each at most 255 bytes. A 2048-bit DKIM value
exceeds that. An API that accepts a long unquoted string may store it and then
serve nothing — no error at publish time, no error on lookup, just an empty
answer and a DKIM check that never passes. Publish it as adjacent quoted
strings split at 255 characters.

**Managed email routing owns its MX records.** Where a DNS provider also offers
mail forwarding, that feature publishes and reclaims the MX records itself.
Deleting them in the DNS editor does not disable the feature. Turn the feature
off, then publish the relay's MX.

**Confirm which zone you are editing.** Sibling domains under different TLDs
are easy to mistake for each other, and a parked domain can carry records that
look like real mail configuration.

## Verifying the result

- The Setup tab for the domain reports every required row green. Two warnings
  are expected until real mail flows: SRS, and inbound authentication results,
  which have nothing to report before the first message arrives.
- Send from the hosted mailbox to an external account and read the received
  headers: DKIM `d=` equal to the bare domain, and `dmarc=pass`.
- Trigger one of the site's own messages and read the same headers: DKIM `d=`
  equal to `mail.<domain>`, `dmarc=pass`, and a reply landing in the hosted
  mailbox.
- Aggregate reports begin arriving at the `rua` address within a day or two,
  and are the only source that shows senders you forgot about.
