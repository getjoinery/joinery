# Inbound Email — Outbound Send Protection (Session-Gated Sending Identity)

**Status:** Draft / awaiting implementation
**Version:** 1.0
**Builds on:** `specs/inbound_email_encryption_at_rest.md` (the user key hierarchy —
passphrase-wrapped X25519 keypair, sealing helper, session-scoped key unwrapping —
is defined there and reused here, not duplicated).

## Goal

Make it impossible for a compromised box to send mail **as the user** while the
user is not logged in. The encryption-at-rest spec protects the *reading* of stored
mail; this spec protects the *sending identity*. The two together give the same
shape of guarantee: while no session is active, the box holds nothing that can
read your mail and nothing that can speak as you.

Sending as the user is arguably the worse compromise: an attacker who can emit
DMARC-passing mail from your address can defraud your contacts, reset accounts,
and destroy trust in the address itself — damage that outlives the incident.

## Threat Model

**Defends against:**

- **Logged-out box compromise (including root).** An attacker with full control of
  the box while no session is active cannot produce a message that receiving mail
  servers will accept as coming from the user's address. The enforcement point is
  **other people's mail servers applying the domain's published DMARC policy** —
  infrastructure the attacker does not control. This is what makes the guarantee
  durable against root: on-box controls (rate limits, audit logs, egress rules)
  can be switched off by root; a signature the box cannot produce cannot be.

**Explicitly accepted residuals:**

- **Active-session exposure.** While the user is logged in, the unwrapped DKIM
  signing key is in session RAM and an attacker present in that window can send
  signed mail. This deliberately matches the read-side boundary in the
  encryption-at-rest spec: logged out, the box can neither read nor speak;
  logged in, it can do both.
- **Receivers that ignore DMARC.** A minority of mail servers do not enforce
  `p=reject`. An attacker can still deliver spoofed mail to those; the major
  providers (Gmail, Outlook, Yahoo, Apple) all enforce.
- **DNS/registrar compromise.** An attacker who controls the domain's DNS can
  publish their own DKIM key and defeat this entirely. DNS credentials are
  off-box and out of scope.
- **Look-alike identities.** The forwarding subdomain (below) remains ambiently
  usable; an attacker could send as `anything@fwd.<domain>`. That is not the
  user's correspondence address and is accepted.

**Open tradeoff — automated sends (may make the strict invariant unrealistic):**

The invariant blocks **all** logged-out sends from the protected domain,
including legitimate automated mail: mailing-list signup confirmations,
notifications, any transactional send using an address at the identity domain.
If the platform must send those around the clock, the strict form cannot hold
for the whole domain. The recorded middle ground: protect only the **personal
correspondence identity** (the bare domain the user writes from) and move
automated senders to a dedicated subdomain (e.g. `mail.<domain>`) with its own
ambient DKIM key. Under strict alignment (`adkim=s`/`aspf=s`) that subdomain's
key cannot sign as the bare domain, so a compromised box can send as
`list@mail.<domain>` but still not as `user@<domain>`. The human-trust boundary
then sits between the bare domain and its subdomains — recipients must learn
that only the bare domain is "really you." Whether that split is acceptable is
an open product decision; this spec stays parked until it is made.

## The Ambient Send Inventory (what must be removed or gated)

DMARC accepts a message if **either** an aligned DKIM signature verifies **or**
the sending IP passes SPF for an aligned domain. Every ambient path to either
must be closed; a design that seals the DKIM key but leaves the box IP in SPF
protects nothing. The complete inventory of resting send capability today:

1. **The DKIM private key on disk** — `/etc/opendkim/keys/<domain>/mail.private`,
   readable by opendkim (and root) at all times; opendkim signs anything Postfix
   relays, no session required.
2. **The box IP in the domain's SPF record** — lets the box send SPF-aligned mail
   with no key at all.
3. **Relay-provider credentials at rest** (Mailgun/SES API keys, `smtp_*`
   settings) — if the provider is authorized to send for the domain (verified
   sending domain at the provider, or provider include in SPF), a resting API
   key is a resting send capability, exactly like a resting DKIM key.
4. **Ambient senders using protected-domain From addresses** — any platform code
   (transactional mail, notifications, SRS bounce messages) that sends
   `From: <anything>@<protected domain>` outside a session.

## Design

### Protected identity domains

A per-domain flag marks a domain as a **protected sending identity**. For a
protected domain, the invariant is:

> While no session is active, no credential exists on the box that can produce
> a DMARC-passing message with a `From:` header at this domain.

Non-protected domains (the platform's transactional domain, the forwarding
subdomain) keep today's ambient behavior.

### The four closures, matching the inventory

**1. Seal the DKIM key; sign in-app at compose time.**

- The protected domain's DKIM private key is generated at setup **in-session**,
  sealed to the user's public key (the same `crypto_box_seal` envelope the
  encryption-at-rest spec uses for message DEKs), and stored in the database.
  The plaintext key never touches disk and is never given to opendkim.
- The protected domain is **removed from opendkim's signing table** (opendkim
  keeps verify duty for inbound). `provision_dkim.sh` remains the tool for
  non-protected domains only.
- Signing moves to the app, at compose time, inside the authenticated session:
  `MailboxSender` → `EmailSender` → `SmtpMailer` (a PHPMailer subclass —
  PHPMailer signs natively via `DKIM_domain` / `DKIM_selector` /
  `DKIM_private_string`, so the unwrapped key is passed as an in-memory string,
  never a key file). Provider-API transports (Mailgun/SES payload sends) must
  either carry the app-produced signature in the raw message or are simply not
  used for protected-domain mail (see closure 3).

**2. Take the box out of the protected domain's SPF; strict alignment.**

- The protected domain's SPF record no longer authorizes the box IP (nor any
  ambient relay). Compose sends from the box will fail SPF and pass DMARC on
  the DKIM signature alone — which is the point: the *only* path to acceptance
  is the signature only a session can produce.
- The protected domain's DMARC record becomes `p=reject; aspf=s; adkim=s`.
  Strict alignment matters: with relaxed alignment (the default), SPF for *any
  subdomain* aligns with the organizational domain, so the forwarding
  subdomain's SPF (which must authorize the box — next item) would hand the
  ambient capability right back.

**3. Move the SRS envelope to a forwarding subdomain.**

Alias forwarding runs while the user is logged out and must keep working — but
forwarding never needs the user's identity: the forwarded message's `From:` is
the **original sender's** domain (its own DKIM signature, which survives
forwarding intact, is what carries DMARC at the destination). What forwarding
needs is an SPF-passing **envelope** sender for bounce routing. Today
`SRSRewriter` builds that envelope at the user's domain; it moves to a
dedicated forwarding subdomain (e.g. `fwd.<domain>`) whose SPF authorizes the
box. Under `aspf=s` that subdomain's SPF pass can never align a
protected-domain `From:`, so it adds no spoofing capability against the
identity.

The **SRS bounce notification** (`handleSRSBounce` — a freshly generated
delivery-failure message) is likewise sent from the forwarding subdomain, not
the protected domain, since it is generated while logged out.

**4. Keep resting relay credentials powerless over the identity.**

The protected domain must never be configured as a verified sending domain at
a relay provider whose API key rests on the box, and no provider include may
appear in its SPF. Protected-domain mail leaves only via the app-signed
compose path. (Providers stay fully usable for the platform's transactional
domain and the forwarding subdomain.) The Setup tab enforces this by check,
not by trust: see *Integration Points*.

### What still works while logged out

Receiving, filtering, threading, alias forwarding (with SRS at the forwarding
subdomain), catch-all forwarding, bounce notifications, and all platform
transactional mail on non-protected domains. The only thing a logged-out box
cannot do is emit mail as the protected identity — which is exactly the
capability being removed.

## Integration Points That Change

- **`SRSRewriter`** — envelope domain becomes the forwarding subdomain
  (setting), not the alias domain.
- **`MailboxSender` / `EmailSender` / `SmtpMailer`** — compose/reply/forward of
  protected-domain mail unwraps the sealed DKIM key from the session key
  material and signs via PHPMailer's in-memory DKIM support before transport.
- **`provision_dkim.sh` / opendkim tables** — protected domains are excluded
  from (or removed from) `signing.table`; opendkim remains inbound-verify.
- **`InboundEmailSetupCheck` (Setup tab)** — for a protected domain the
  *correct* DNS shape inverts: SPF must **not** authorize the box IP, DMARC
  must be `p=reject; aspf=s; adkim=s`, the DKIM DNS record must match the
  sealed in-app key's public half, the forwarding subdomain's SPF must
  authorize the box, and the domain must not be provider-verified. Checks that
  today demand box-IP SPF must branch on the protected flag rather than fail.
- **`InboundEmailHealth`** — same branching for the full health run.
- **Rate limiting** (per-alias / per-domain outbound) — unchanged; it is
  defense-in-depth for the active-session residual, not the primary control.

## Schema Changes (via data-class `$field_specifications`)

- Domain record: protected-identity flag; DKIM selector; **sealed** DKIM private
  key (ciphertext, sealed to the user's public key); cleartext DKIM public key /
  DNS record value (needed for the Setup tab while logged out).
- Settings: forwarding subdomain (per domain or server-wide — decide during
  implementation).

## Setup & Key Rotation

- Enabling protection on a domain is an in-session act: generate the keypair,
  seal the private key, show the DNS records to publish (new DKIM selector
  record, tightened SPF, strict DMARC, forwarding-subdomain SPF), and verify
  via the Setup tab before flipping enforcement.
- Rotation = generate + seal a new key under a new selector in-session, publish
  the new DNS record, retire the old selector. The old on-disk opendkim key for
  the domain is destroyed at cutover.

## Documentation to Update

- `plugins/inbound_email/docs/overview.md` — add an "Outbound send protection"
  section: the protected-domain invariant, the DNS shape, the in-app signing
  path, and the forwarding-subdomain envelope model (current-state only).
- `docs/email_system.md` — note that protected-domain From addresses are only
  usable via the session-gated mailbox compose path, never by transactional
  senders.

## Open Items to Confirm During Implementation

- Verify PHPMailer's `DKIM_private_string` path produces signatures that verify
  with `relaxed/relaxed` canonicalization at major receivers (test against
  Gmail's `Authentication-Results`).
- Decide whether Mailgun/SES payload transports can carry a pre-signed raw
  message for protected-domain compose sends, or whether protected-domain mail
  is SMTP-direct only.
- Confirm strict-alignment DMARC (`aspf=s`) does not disrupt any legitimate
  existing sender for the domain before publishing `p=reject`.
- Decide the forwarding subdomain naming convention and whether it needs its
  own restrictive DMARC record.
- Sequence the cutover: publish DNS, verify, remove opendkim signing, destroy
  the on-disk key — in an order that never leaves compose broken.
