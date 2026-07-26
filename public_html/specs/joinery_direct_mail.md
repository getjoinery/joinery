# Joinery Direct Mail

**Status:** UNBUILT — experimental. Design only.

## What this is for

Two people whose accounts both live on Joinery instances shouldn't need a third
party in the middle to exchange mail. Right now every message — even Joinery to
Joinery — is handed to Postfix or a paid sender (Mailgun and friends), inheriting
all of email's cost, fragility, and spam apparatus.

Joinery Direct Mail lets one Joinery instance deliver a message straight to
another over its own signed channel, skipping SMTP entirely, **when both ends
have already affirmed the relationship.** Everything else — a stranger's first
message, mail to a Gmail address, anything not on a known channel — falls back
to the existing SMTP path unchanged. Nothing breaks; a fast lane is added for
correspondents who already know each other.

The experimental phase is deliberately narrow: prove the pipe between consenting
peers. Spam is a problem you earn the right to have later (see **Growth path**).

## The one idea that makes it safe

The direct channel is **whitelist-only, and the whitelist is the address book you
already have.** A message rides the direct path to someone's inbox only if the
sender is in that recipient's contacts. This is not a new idiom — "add sender to
your contacts" is an idiom every mail user already knows, and `imc_mailbox_contacts`
already stores it.

Consent lives at **per-person** granularity, not per-domain. "Alice is in Bob's
contacts" is the unit — trusting a whole domain would hand access to whichever
account on it gets compromised next week.

Because a stranger is by definition not yet in your contacts, **cold contact
routes itself to SMTP automatically.** The direct path only ever carries
relationships that were already affirmed, so it has no unsolicited surface to
filter. There is nothing to defend against because nothing unrequested can
arrive on it.

## Vocabulary

- **Direct channel** — a signed, authenticated TCP connection from the sending
  instance to the receiving instance, carrying one message.
- **Capability record** — a DNS record on the recipient's domain advertising that
  it speaks Joinery Direct: the host, port, and the instance's signing key.
- **Instance signature** — an Ed25519 signature over the message, made by the
  *sending instance*, verified by the receiver against the sender domain's
  capability record. This is DKIM's job, but mandatory and non-optional: no valid
  instance signature, no acceptance.
- **Contact gate** — the receiving instance's check that the envelope sender is in
  the recipient user's `imc_mailbox_contacts`. Passes → inbox. Fails → the
  receiver tells the sender to use SMTP.

## What already exists (reuse, don't reinvent)

- **The address list:** `plugins/mailbox/data/mailbox_contacts_class.php`
  (`imc_mailbox_contacts`) — per-user, sealed contacts with import
  (`contacts_import_logic.php`). This *is* the whitelist. No new store.
- **Pluggable transports:** `includes/EmailServiceProvider.php` is the transport
  interface; `EmailSender::send()` resolves a transport per message.
  `RawRelayComposeTransport` is a working non-SMTP transport that self-declares its
  delivery class. Direct Mail is a new `EmailServiceProvider` the resolver can
  pick — the send path does not change, only gains a branch.
- **Signing:** `MailboxDkimSigner` already signs outbound mail with a
  domain-scoped key. Instance signing reuses the same key custody, over the wire
  format rather than the DKIM header.
- **Store-and-forward:** the relay (`RelaySpoolConsumer`, `MailboxSender`,
  `RawRelayComposeTransport`) already buffers mail when a destination is
  unreachable. It becomes the resilience layer for direct mail — replacing a paid
  sender as the load-bearing fallback, not a new spool.
- **Inbound storage:** delivered messages land in `iem_inbound_email_messages`
  exactly as SMTP-delivered mail does, regardless of transport.
- **DNS publishing:** the capability record is published through the DNS Record
  Management plan/driver system (see `specs/dns_record_management.md`) — it is one
  more record type in the plan, alongside MX/DKIM/SPF.

## The capability record

A domain advertises Joinery Direct in DNS so a sender can discover the channel
without asking anyone. Two records under the mail host:

- **`SRV`** `_joinery-mail._tcp.<domain>` → host + port of the receiving instance.
  Port is advertised, never hardcoded.
- **`TXT`** `_joinery-key.<domain>` → the instance's Ed25519 public signing key
  (base64), with a key id so keys can rotate without a flag day.

Both are published by the existing DNS plan/driver flow. Absence of the SRV record
means "this domain does not speak Direct" — the sender falls back silently.

## The send decision (sending instance)

When `EmailSender::send()` resolves a transport for a message:

1. Look up `_joinery-mail._tcp.<recipient-domain>`. No record → existing SMTP/relay
   path, done.
2. Record present → attempt the direct transport: open the channel, present the
   instance-signed message.
3. The receiver either **accepts** (delivered) or returns **`use-smtp`** (this
   recipient does not accept Direct from this sender). On `use-smtp`, or on any
   connection failure, fall back to the existing SMTP/relay path.

**The sender does not know — and cannot know — the per-recipient path.** There are
two separate questions living in two separate places:

- *Can this domain speak Direct at all?* — public, per-**domain**, read from DNS.
  The sender reads this.
- *Will this recipient accept Direct from this sender right now?* — private,
  per-**person**, held only in the recipient's contact list on the receiving
  instance. The sender cannot read this.

So there is no path lookup. The sender attempts the good path whenever the domain
supports it and lets the receiver answer in real time. This is deliberate: if the
sender could look up "am I allowed to send Direct to bob@you," that would be an
oracle leaking the recipient's contact and block lists to anyone who asks. Per-user
consent therefore never lives in DNS (which is per-domain) or any queryable
endpoint — the only thing crossing the wire is *accepted* vs *use-smtp*.

The consequence for abuse: **permission is never cached on the sender.** Their
instance must query yours on every single send, and yours can refuse on every one.
(A sender may cache a recent `use-smtp` for a recipient under a short TTL to skip a
redundant attempt — but that is its own observation, never a query of your state,
and it expires quickly so re-adding a contact heals on its own.)

## The receive decision (receiving instance)

A new inbound listener on the advertised port. Per connection:

1. **Verify the instance signature** against the sender domain's capability record
   (fresh DNS lookup, key id matched). Invalid or unsigned → refuse.
2. **Contact gate** — three outcomes, but only **two answers on the wire**, so the
   protocol never becomes an oracle for the recipient's contact or block list:
   - **Contact** → accept; continue to step 3.
   - **Not a contact** (stranger, or a contact you removed) → answer `use-smtp` and
     drop (do not store, do not queue). The message reverts to ordinary email.
   - **Blocked** → answer `use-smtp` *as well*, indistinguishable on the wire from a
     plain downgrade. Because the sender is on the recipient's block list, the
     fallback SMTP message that follows is filed as spam (or rejected) by the
     existing inbound filter. The sender cannot tell a block from a downgrade.
3. Store into `iem_inbound_email_messages` as a normal delivered message, tagged
   with its transport so the UI can show "delivered directly."

Consent is a living list, not a one-time grant — every connection re-checks it.

## Abuse: removing vs blocking

The Direct channel elevates trusted senders; it was never a wall against known-bad
ones. Misbehavior is handled where all mail abuse is handled — plus a live gate at
the listener:

- **Remove from contacts** = a neutral downgrade. Their next message reverts to
  SMTP. No punishment, just "no longer elevated."
- **Mark as spam / block** = remove the contact *and* add the sender to the block
  list. Future Direct attempts get the indistinguishable `use-smtp` answer, and the
  ensuing SMTP delivery is auto-filed as spam.
- **Direct-path blocking is not total blocking.** Anyone can still reach you over
  SMTP, exactly as in email today — removing a contact can no more stop that than
  deleting someone from Gmail contacts stops their email. What Direct adds is that
  abuse *loses its verified mark and inbox placement the instant you act*, and every
  future attempt must re-ask your instance live.
- The listener rate-limits by sending instance and drops early once a signature
  identifies a blocked sender, so repeated attempts can't be used to hammer you.

## Encryption

The channel is TLS on the advertised port (the receiving instance already
terminates TLS for its web/mail services). Instance signing gives
authenticity and integrity on top. End-to-end content encryption (sealing the
body to the recipient's vault, reusing the Sealed Vault consumer pattern) is a
natural later layer but is **out of scope for the experimental phase** — v1 proves
transport and consent, not content custody.

## Security levels: locked vaults and Fortress

Contacts (`imc_mailbox_contacts`) are sealed to the owner's vault, so the contact
gate can only read them while the vault is unlocked. That raises the obvious
question at **Private** (encrypted at rest, decrypts in bounded unlock windows) and
**Fortress** (Private + edge-sealing ingest + off-box send): is Direct receiving
only available while unlocked? **No** — Fortress already solved "receive while
locked" for ordinary mail, and Direct rides the same machinery.

**Authentication runs locked; authorization defers to unlock.** The gate is two
halves and only one needs the vault:

- *Is this really sender X's instance?* — verifying the instance signature against
  the sender domain's DNS key is stateless crypto. No vault needed, so it runs at
  receive time even while locked. The verified-sender fact is recorded next to the
  message.
- *Is X in my contacts?* — needs the sealed contact list, so it runs only in an
  unlock window.

**While locked, Direct accepts and edge-seals, exactly like SMTP deferred ingest.**
The connection is accepted, the signature verified, the message edge-sealed into the
same pending-parse spool Fortress already uses, and the authorization decision
deferred. At the next unlock, the existing `unseal → parse` pipeline gains one step:
run the contact gate against the now-readable list, and either apply the
verified-direct mark (sender is a contact) or file the message exactly where SMTP
would have (not a contact → ordinary/spam). The mark is deferred, never lost.

**A deferred rejection is local, never returned.** This is the one place Fortress
genuinely differs from Standard. At Standard the gate runs live, so a non-contact
gets `use-smtp` on the wire and the *sender* re-sends over SMTP. Under Fortress the
message was accepted and sealed before the gate could run, and the sender is long
gone by unlock — so a rejection at unlock is a **local filing decision**: the message
is placed exactly where SMTP would have put it (ordinary/spam) and denied the
verified mark. It is not bounced, not returned to the sending instance, and the
sender is never told. From the sender's side the message was "delivered" — and it
was, into your spam. No round trip, no duplicate, no notification. The end state
matches regular email; only the path differs. To keep this oracle-free, Fortress uses
accept-then-decide-locally whether locked or unlocked — it never signals `use-smtp`.

**Not handing it back is a feature, not a compromise.** Even given a free choice you
would want this: a bounce or a rejection reply is a recon tool (attackers enumerate
valid addresses and probe filters through delivery feedback), and returning mail to
forged senders is backscatter. Silent accept-and-file gives an attacker zero feedback
and closes the lock-state, contact-membership, and block-status oracles in one move —
the sender always sees "accepted" and can never learn which of those they are. The
only cost is that a legitimate sender who lands in spam gets no notice, which is true
of every spam folder already, and genuine contacts never land there.

**Why not a locked-readable contact index.** A blind index (keyed hashes of contact
addresses) would let the gate run at receive even while locked — tempting, because it
saves the deferred step. It is fine at Standard/Private, whose threat model does not
include an attacker reading sealed data while locked. It is a **net loss at Fortress**,
whose threat model is exactly that. For the gate to run while locked, the hashing key
must be usable while locked, so an attacker who steals the locked box holds it too;
email addresses are low-entropy, so a single `HMAC(candidate)` confirms or denies a
specific relationship, and the whole graph falls to a dictionary run. That turns
Fortress's guarantee — contacts sealed and invisible while locked — into contacts
guessable while locked. The rule underneath: anything the box can compute while locked,
an attacker who owns the locked box can compute too, so "test membership while locked"
and "list stays secret if the box is stolen" cannot both hold for guessable inputs.
Deferred ingest is secure *because* the box genuinely cannot answer "is X a contact"
while locked. (Locked membership checks without a guessable oracle would require
contacts to present a recipient-issued token — the growth-path model, not a stored set
the receiver tests against.)

Because a message is sealed before it can be judged, a **blocked** sender's mail
briefly occupies storage until the next unlock. A locked-readable blocklist could drop
such connections early — it shares the same theoretical weakness (a stolen locked box
can brute-force it) but the trade is defensible: the data is less sensitive than the
contact graph, the set is small, and online it only ever checks the one
cleartext-verified identity already connecting, never iterating. So it is a reasonable
**opt-in** optimization where a locked-readable contact list is not; without it, blocked
Direct mail is sealed and then filed to spam at unlock.

**No lock-state oracle.** The receiver accepts identically whether locked or
unlocked — it never answers `use-smtp` based on lock state — so a sender cannot probe
unlock windows by watching whether Direct succeeds. Fortress treats locked-state
metadata leaks as in scope; Direct must not become one. (The live two-answer gate in
the send-decision section applies at **Standard**, where contacts are cleartext and
the gate runs at receive.)

**Worst case is still the old email system — and in Fortress that is the *most*
sealed path.** Whenever Direct can't apply (no capability record, unreachable box, or
a deployment that hasn't enabled it), the message rides SMTP, which under Fortress is
the edge-sealing ingest relay. Falling back never drops to a less-protected path; at
worst it drops to a more-sealed one.

**Send side (open).** Outbound Direct is signed with the same key custody as the
deployment's DKIM at its level — a local key at Standard, sealed/off-box at Fortress.
Fortress send already runs through the edge relay in a bounded unlock window, so
originating Direct connections from that relay (and giving it the instance signing
key) is a Fortress-specific build item. Experimental v1 targets Standard/Private
send; Fortress receive works via deferred ingest as above.

## The social signal (make the good path visible)

The point isn't a feature badge — it's the iMessage move: make the good path
*visibly* better so people want it and nudge their contacts onto it, without a word
of marketing. Apple didn't sell iMessage; they made the absence of it (the green
bubble) a quiet social signal, and — the part that did the work — they showed it
**at compose time**, before you sent.

We have one advantage email trust badges (BIMI, Gmail checkmarks) never had: a
direct message is **only ever rendered in a Joinery inbox**, so we own 100% of the
canvas. The mark can be tasteful, consistent, and impossible to forge from message
content — it is applied by the receiver from verified transport + contact
membership, never from anything in the message itself.

What the mark honestly asserts, exactly two things: the sending **instance** is
cryptographically verified, and the sender is in **your** contacts. Not "trusted
human." Three surfaces:

1. **Compose (the lever, not the reward).** As you address a message, show whether
   it will go direct or as ordinary email — before send. This is iMessage's
   blue/green compose field, and it's what actually drives behavior: you see the
   good path is available and you want it.
2. **Inbox list.** A small verified-direct mark next to the sender. Our own glyph —
   **not** a borrowed blue check (devalued) and **not** a colored header banner
   (reads as promo, or worse as a phishing tell). Restraint and consistency signal
   trust; saturation signals the opposite.
3. **Message view.** A subtle accent and a plain-language line ("Delivered directly
   from Alice — verified, no third party"), not a loud colored block.

Guardrail: the mark never appears on the SMTP-fallback path, and nothing in message
content can reproduce it. A message either passed signature verification and the
contact gate, or it did not.

## Why there is no spam problem in v1

Not "spam is filtered well" — **spam is structurally impossible on the direct
path**, because the path only carries mail from senders the recipient already put
in their contacts. Everything unsolicited never reaches the direct listener's
inbox step; it either never attempts Direct (no capability record) or hits the
contact gate and is told to use SMTP. On the SMTP path, the world's existing spam
defenses apply exactly as they do today. We neither weaken nor re-solve them.

Cheap instances and infinite domains — the obvious attack on any federation — buy
an attacker nothing here, because instance identity gates nothing valuable. The
inbox is gated by the recipient's contact list, which only the recipient's own
instance can write.

## The SMTP fallback boundary

This is the contract that keeps the feature invisible when it doesn't apply:

- No capability record → SMTP.
- `use-smtp` from the receiver (not a contact) → SMTP.
- Connection/timeout/verification failure → SMTP.
- Recipient is not a Joinery instance at all → SMTP.

Direct Mail is strictly additive. A message that can't or shouldn't go direct goes
exactly where it goes today.

## Build plan

1. **Capability record** — add the SRV + key TXT to the DNS plan; publish/verify
   through the existing driver flow. Read side: a resolver helper that answers
   "does this domain speak Direct, on what host/port/key?"
2. **Direct transport (send)** — a new `EmailServiceProvider` that opens the
   channel, presents the instance-signed message, and maps `use-smtp`/failure to
   fallback. Wire it into the `EmailSender` transport resolver as a branch.
3. **Direct listener (receive)** — the inbound port service: TLS, signature
   verification, contact gate, store into `iem_inbound_email_messages`. Relay
   store-and-forward for offline destinations.
4. **UI** — no new idiom. "Add to contacts" already exists. Three touch points
   (see **The social signal**): a compose-time direct-vs-email indicator, an
   inbox verified-direct mark, and a subtle in-message accent. Nothing to
   configure per-recipient.

## Acceptance

1. A domain with no capability record receives mail via SMTP, unchanged.
2. Sender in the recipient's contacts, capability record present → message
   delivered over the direct channel, marked as such, never touches SMTP or a paid
   sender.
3. Sender **not** in the recipient's contacts → receiver returns `use-smtp`,
   message delivered via SMTP, nothing stored on the direct path.
4. Message with an invalid/missing instance signature → refused, not stored.
5. Receiving instance offline → message spools on the relay and delivers when it
   returns; no bounce.
6. Removing a sender from contacts → their next message reverts to SMTP.
7. Rotating the instance signing key (new key id in the capability record) → mail
   keeps flowing with no manual intervention.
8. A message to a non-Joinery address (e.g. Gmail) is unaffected in every respect.
9. Composing to a direct-capable contact shows the direct indicator before send;
   composing to anyone else shows ordinary-email.
10. The verified-direct mark appears only on messages that passed signature
    verification and the contact gate, and cannot be reproduced by message content.
11. A removed contact's next message arrives via SMTP with no verified mark.
12. A blocked sender's Direct attempt receives the same `use-smtp` answer as a
    stranger (no block oracle), and the SMTP fallback that follows is filed as spam.
13. No queryable endpoint or DNS record reveals whether a given sender is a contact
    of, or blocked by, a given recipient.
14. Under Fortress with the vault locked, a Direct message is accepted and
    edge-sealed like any inbound mail; the contact gate and verified-direct mark are
    applied at the next unlock, not at receive.
15. A sender cannot determine a recipient's lock state from Direct's behaviour —
    the receiver accepts identically whether locked or unlocked.
16. A Direct message rejected at unlock under Fortress is filed locally (ordinary or
    spam), never returned to the sender: no bounce, no notification, and no duplicate
    delivered over SMTP.

## Growth path (not built, kept open by design)

The whitelist-only v1 is the token model with **manual issuance**. A contact entry
*is* a consent token. If hand-maintaining contacts ever becomes the friction:

- "Auto-add people you reply to" (behavior most mail UIs already have) becomes
  automatic token issuance.
- A signed, recipient-issued, revocable token would let established senders reach
  an inbox without a prior manual add — same gate, automated door.

Crucially none of that changes the transport, the signing, the DNS discovery, or
the SMTP boundary. You never rebuild the pipe; you only automate who gets a key.
POW and per-instance reputation were considered and rejected: POW taxes volume,
but volume is not what separates wanted mail from spam (opted-in bulk is the use
case we want to *enable*); reputation does no independent work once the inbox is
gated by recipient-issued consent.

## Attacks considered

A running ledger of the adversarial passes this design has survived, so later turns
don't relitigate settled ground. Each links to where it's handled.

- **Cheap-Sybil instances / infinite domains.** A fresh instance holds zero
  contacts, so it reaches no one's inbox no matter how many you spin up. Instance
  identity gates nothing valuable; the inbox is gated by recipient-held consent.
  → *Why there is no spam problem in v1.*
- **POW / volume tax.** Rejected: volume is not what separates wanted mail from
  spam (opted-in bulk is the case we want to *enable*), so a per-message cost hits
  the best citizen hardest. → *Growth path.*
- **Consent-queue ≈ spam-folder.** True only for genuine cold 1:1 contact, which
  self-routes to SMTP because a stranger isn't in your contacts yet. Everything
  pre-consented skips the pile entirely. → *The one idea that makes it safe.*
- **Send-path oracle** ("how does the sender know which path?"). The sender is
  stateless — it reads only per-domain DNS capability and lets the receiver decide
  live. Per-user consent is never queryable, or it would leak the contact/block
  list. → *The send decision.*
- **Locked vault / Fortress receive** ("receiving only when unlocked?"). No —
  authentication runs while locked (stateless signature check), authorization
  defers to unlock via the existing deferred-ingest pipeline. → *Security levels.*
- **Return-to-sender on rejection.** A Fortress rejection is filed locally, never
  bounced — which is a feature: no enumeration feedback, no backscatter, and it
  closes the lock/contact/block oracles at once. → *Security levels.*
- **Membership hashfile** (locked-readable contact index). Rejected for Fortress:
  a key usable while locked is a key a thief of the locked box holds, and
  low-entropy addresses make the set dictionary-guessable. Fine at Standard/Private.
  → *Security levels.*

Open seam, not yet walked: **multi-unlocker / multi-device** — whose unlock runs the
deferred gate, and how the verified mark and read-state reconcile across several
unlockers of one Fortress identity.

## Open decisions

- **Port and SRV service name** — pick a default port; confirm `_joinery-mail._tcp`
  as the SRV label. (Implementation detail, resolve at build.)
- **`use-smtp` response shape** — a minimal signed refusal vs. a plain protocol
  code. Leaning plain code; the sender's fallback doesn't need to trust it.
- **Envelope-sender identity for the contact gate** — match on the bare address vs.
  address + verified sending domain. Leaning address + domain-must-match-signature,
  so a contact entry can't be satisfied by a spoofed From.
- **Fortress send path** — originating Direct connections from the off-box edge
  relay (which holds the sealed sending identity) is a Fortress-specific build item.
  Experimental v1 targets Standard/Private send; Fortress receive works via deferred
  ingest.
- **Locked-readable blocklist** — whether to keep a locked-readable blind index of
  blocked sender identities so a blocked sender's Direct connection can be dropped
  early while locked, instead of sealed-then-spam-filed at unlock. Optimization, not
  correctness; a locked-readable *contact* index is deliberately rejected for Fortress
  (see Security levels — it would make the contact graph guessable on a stolen locked
  box).
