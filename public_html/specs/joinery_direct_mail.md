# Joinery Direct Mail

**Status:** UNBUILT — experimental. Design settled, no open decisions; not yet built.

**Scope note:** v1 covers all tiers including Fortress, so it spans this repository
*and* a relay version bump with a fleet upgrade. See *Build plan*.

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

- **Direct channel** — a single authenticated HTTPS request from the sending
  instance to the receiving instance's advertised host, carrying one message.
- **Sealed part** — one piece of a message (body text, HTML, or a single attachment)
  encrypted by the *sending* instance to the recipient's vault public key, so nothing
  between the two endpoints — proxy, CDN, or relay — can read it.
- **Preflight** — the first of the two requests that make up a delivery: envelope and
  signature only, no content. The receiver answers `use-smtp` or `accept`, and an
  `accept` carries the recipient's current public key.
- **Manifest** — the declaration in the preflight of what is about to be sent: each
  part's size, type, and content hash. What the receiver accepts or refuses *before*
  any content crosses the wire.
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
- **An ingest endpoint of exactly this shape:**
  `plugins/mailbox/ajax/inbound_email_webhook.php` already takes a raw message over
  HTTP, verifies a sender-specific signature, and hands it to `InboundEmailRouter`.
  The Direct receiver is that shape with a different signature check — a route and a
  logic file, not a service.
- **Sealing primitives:** `VaultCrypto::sealItemDek()` seals to a recipient's
  `uev_public_key` (`data/user_encryption_vaults_class.php`). Sender-side sealing is
  a new *caller* of the primitive edge-sealing already uses, not new crypto.
- **Recipient key distribution:** `RelayMapExporter` already ships an
  owner→vault-public-key map to relays, so recipients' public keys are already
  exported off-box by design. Serving one to a signature-verified sending instance
  is not a new class of exposure.
- **DNS publishing:** the capability record is published through the DNS Record
  Management plan/driver system (see `specs/dns_record_management.md`) — it is one
  more record type in the plan, alongside MX/DKIM/SPF.

## The capability record

A domain advertises Joinery Direct in DNS so a sender can discover the channel
without asking anyone. Two records under the mail host:

- **`SRV`** `_joinery-mail._tcp.<domain>` → host + port of the receiving endpoint.
  Port is advertised, never hardcoded; the default is 443.
- **`TXT`** `_joinery-key.<domain>` → the instance's Ed25519 public signing key
  (base64), with a key id so keys can rotate without a flag day.

Both are published by the existing DNS plan/driver flow. Absence of the SRV record
means "this domain does not speak Direct" — the sender falls back silently.

**The transport is ordinary HTTPS**: a POST to `/.well-known/joinery-mail` at the
SRV-named host and port. The receiving box already terminates TLS for its own web
traffic, so Direct needs no second TLS stack, no additional certificate, no firewall
change, and no long-running service — it is a route, handled the way the existing
inbound webhook is handled. It therefore also works on deployments that cannot bind
a port at all, including shared hosting.

SRV still earns its place despite a fixed path, for a specific reason: a customer's
mail domain usually does not point its web traffic at the Joinery box, so
`https://<maildomain>/.well-known/joinery-mail` would land on their marketing site.
SRV names the machine that actually receives, independent of where the domain's
website lives.

**What the advertised port keeps open.** Running over shared 443 gives up what a
dedicated listener would buy: dropping a blocked or rate-limited peer at TCP accept
instead of after a TLS handshake and a php-fpm worker; rejecting before a large body
transfers (recovered here by the preflight step, below); failing independently
of the web vhost; and streaming headroom beyond `post_max_size` and request
timeouts. None of this is foreclosed. Because the port is advertised rather than
assumed, a deployment that *can* bind one may run a dedicated listener later and
publish it in SRV — senders follow the record with no coordination and no flag day.
HTTPS on 443 is the floor that works on every deployment, not the ceiling.

**Choosing the SRV target.** The target must be a host that reaches the receiving
instance *without an intermediary that would otherwise see the traffic*, and must not
expose an address the deployment is deliberately hiding:

- **Standard/Private:** a DNS-only hostname for the box (e.g. `direct.<domain>`), not
  a CDN- or proxy-fronted web host.
- **Fortress:** the relay, never the box — see *Security levels*. Publishing an SRV
  record pointing at a Fortress box would advertise in public DNS exactly the address
  the relay exists to conceal.

Sender-side sealing (see *Encryption*) means a proxy in the path would see ciphertext
rather than content, so this is defence in depth on metadata, not the only thing
standing between the message and a middlebox.

## The send decision (sending instance)

When `EmailSender::send()` resolves a transport for a message:

1. Look up `_joinery-mail._tcp.<recipient-domain>`. No record → existing SMTP/relay
   path, done.
2. Record present → **preflight**: POST the envelope, the manifest, and the instance
   signature to `/.well-known/joinery-mail` at the advertised host and port. No
   content.
3. The receiver either answers **`use-smtp`** (this recipient does not accept Direct
   from this sender) or **`accept`**, carrying the recipient's current public key and
   key generation.
4. On `accept` → seal each part to that key and transfer. On `use-smtp`, or on any
   connection failure at either step, fall back to the existing SMTP/relay path.

**The refusal is a plain status code, not a signed statement.** There is nothing for
the sender to verify: the response already comes over TLS from the SRV-named host, and
the worst a forged refusal achieves is a downgrade to SMTP — the path the message would
have taken before this feature existed. Signing it would buy nothing and add a
verification step that can itself fail. This has a useful side effect: a refusal from
the recipient, a WAF, a proxy, and a dead host are all indistinguishable to the sender
and all mean the same thing, so every failure mode converges on one behaviour instead of
a decision tree.

**Why two steps rather than one.** Splitting the envelope from the content is what
makes three otherwise-separate problems disappear at once: the key is current by
construction so there is nothing to cache and no rotation hazard (*Encryption*), a
refusal costs no content transfer, and the content stops having to be a single
monolithic body (*Message transfer*). The cost is one round trip on a connection the
sender was opening anyway.

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

A route serving `/.well-known/joinery-mail`, in the same shape as the existing
inbound webhook. On preflight:

1. **Verify the instance signature** against the sender domain's capability record
   (fresh DNS lookup, key id matched). Invalid or unsigned → refuse.
2. **Contact gate** — matched on the full sender address **and** a sending domain that
   matches the verified instance signature, never the bare address alone. A contact
   entry for `alice@example.com` is satisfied only by a message signed by
   `example.com`'s instance key, so a spoofed From cannot borrow someone else's place
   in your contacts. Three outcomes, but only **two answers on the wire**, so the
   protocol never becomes an oracle for the recipient's contact or block list:
   - **Contact** → accept; continue to step 3.
   - **Not a contact** (stranger, or a contact you removed) → answer `use-smtp` and
     drop (do not store, do not queue). The message reverts to ordinary email.
   - **Blocked** → answer `use-smtp` *as well*, indistinguishable on the wire from a
     plain downgrade. Because the sender is on the recipient's block list, the
     fallback SMTP message that follows is filed as spam (or rejected) by the
     existing inbound filter. The sender cannot tell a block from a downgrade.
3. **Accept**, answering with the recipient's current public key and generation, and
   admit the declared manifest. Then take the sealed parts on the second request and
   store into `iem_inbound_email_messages` — attachments as their own
   `imc_inbound_message_attachment` rows, as they are stored today — tagged with its
   transport so the UI can show "delivered directly."

Consent is a living list, not a one-time grant — every connection re-checks it.

## Abuse: removing vs blocking

The Direct channel elevates trusted senders; it was never a wall against known-bad
ones. Misbehavior is handled where all mail abuse is handled — plus a live gate at
the receiving endpoint:

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
- The endpoint rate-limits by sending instance and drops early once a signature
  identifies a blocked sender, so repeated attempts can't be used to hammer you. At
  Fortress this happens at the relay, before the box is touched at all.

## Encryption

Two layers, both in v1.

**Transport:** TLS on the advertised port, terminated by the receiving instance's
existing web stack. Instance signing gives authenticity and integrity on top.

**Content: the sender seals the body before it leaves the box**, encrypting to the
recipient's vault public key with `VaultCrypto` — the same primitive the edge relay
already uses to seal inbound mail. This is *opportunistic*: seal when the recipient's
key is discoverable, send plaintext-over-TLS when it isn't, exactly as the path
itself degrades to SMTP.

Sealing is in v1 rather than deferred because it is what makes Direct **strictly
better** than the alternative rather than merely faster, and the difference is
sharpest exactly where it matters most. Under Fortress today, mail arrives readable
at the edge relay and the relay seals it — so an off-box machine holds plaintext for
a moment. A message sealed by the sender has no such moment, on the relay or on any
proxy, CDN, or middlebox in the path. Deferring content encryption to a later phase
would ship the version that keeps the weaker property.

It is also cheap. There is no new cryptography here and no OpenPGP: Joinery-to-Joinery
uses the native vault primitives already in core. OpenPGP belongs to
`specs/mailbox_encrypted_interop.md`, which is about corresponding with the *outside*
world and carries its own much larger implementation risk. Two channels, two crypto
stacks, no overlap.

**Key discovery: the key rides the connection, and is never cached.** Sealing needs the
recipient's *per-user* encryption key, while the capability TXT carries only the
*instance* signing key. Per-user keys do not belong in DNS — size, and the enumeration
surface of a public list of every address on a domain. Nor are they fetched from a
standalone key endpoint. They are returned in the preflight `accept`, in the same
exchange that is about to carry the message.

This is a correctness decision, not an optimization. Vault rotation is a re-wrap
migration (`VaultCeremonies` walks consumers from the old secret key to the new public
key, advancing `uev_key_generation`), so a message sealed to generation N and arriving
after the vault moved to N+1 has nobody left who can open it. **A cached key is a bet
that the recipient will not rotate inside the TTL, and losing that bet delivers a
message that is permanently unreadable** — strictly worse than never sealing it. A key
handed over in the connection that carries the message cannot be stale. The sealed
parts are tagged with the generation they were sealed to, so a receiver can always tell
an unopenable message from a corrupt one.

A recipient's public key being readable by a verified peer is not a new exposure:
`RelayMapExporter` already ships owner→vault-public-key maps to relays as a matter of
course. Sealing to a public key is what public keys are for.

**Fortress answers with a key for every address, real or not.** At Fortress the
receiver accepts unconditionally so that acceptance discloses nothing — but a
key-bearing `accept` would reopen exactly what that closed. Verifying an instance
signature proves a sender is who it claims, never that it is welcome, so any instance
could preflight a guessed address and learn from a real key coming back that the
address exists. So the receiver returns a key unconditionally too, deriving a
deterministic decoy from a domain secret for addresses that do not exist. It is
indistinguishable from a real key; the sender seals to it, the message arrives, and
nobody can ever open it — which costs nothing, because mail to a nonexistent address
was going nowhere in any case. Existence, contact membership, and block status all stay
unknowable from the wire.

A decoy is derived as `HMAC(domain secret, lowercased address)` seeded into an X25519
public key, with no private half ever computed. Two properties make it hold up:
it must be a valid curve point, or malformed-key errors would identify it, and it must
be **deterministic**, since a key that changed between probes of the same address would
itself be the tell.

Decoys are a Fortress mechanism only, not a uniform behaviour across tiers. At
Standard and Private the contact gate refuses a stranger with `use-smtp` before any
key is offered, so the only sender who ever receives a key is one already in the
recipient's contacts — who knows the address exists. There is no oracle to close, and
a decoy path there would be dead code.

## Message transfer: parts, not one blob

Because the preflight declares a manifest before anything is sent, the content that
follows does not have to be a single MIME document. Each part — body text, HTML,
each attachment — transfers as its own sealed object. This is not an optimization
bolted on; it falls out of having split the envelope from the content, and it retires
several of email's oldest attachment costs at once:

- **No base64.** MIME must armour binary attachments into text, inflating them by a
  third. Parts transfer as bytes.
- **No monolithic body.** A 40MB message is not one request straining `post_max_size`,
  memory, and an fpm timeout. Each part is its own transfer, so the ceiling is the
  largest part rather than the whole message.
- **Refusal before transfer.** The manifest gives size and type up front, so a
  receiver can decline an oversized or unwanted message having received none of it —
  and a whitelist miss costs no attachment bandwidth at all.
- **Structure survives encryption.** This is the part worth dwelling on. PGP/MIME
  encrypts the whole MIME tree into one opaque object, which is precisely why
  `specs/mailbox_encrypted_interop.md` records that an encrypted inbound message
  cannot have its attachments extracted or its text indexed at ingest. Sealing each
  part *separately* to the same recipient key gives the same secrecy with none of that
  loss: at unlock the receiver can list attachments, store, index, and preview them
  individually. Nothing on the wire or on a relay was ever readable, and yet nothing
  about the message's shape was flattened.

The receiving model for this already exists. Attachments are stored as their own
`imc_inbound_message_attachment` rows pointing at a file (`ima_fil_file_id`), each
carrying its own `ima_is_sealed` flag — per-part storage with per-part sealing is what
the platform already does. Direct receives parts in the shape the database already
wants them, instead of reassembling a MIME blob and taking it apart again.

**The manifest declares sizes and types, not content hashes.** A per-part hash looks
free — it would let a receiver skip a part it already holds, and make resuming an
interrupted transfer tractable — but skip-if-held is an oracle pointed at the
recipient: a sender that watches which parts get skipped learns exactly which files
that recipient already possesses, and can probe for a specific one by offering it.
That is a worse disclosure than the bandwidth is worth. Resume is the defensible half
and needs only a per-part byte offset, not a hash of the content. So the manifest
carries size, type, and part role; if skip-if-held is ever built, the decision must
be invisible to the sender — the receiver takes the transfer and discards it — which
removes most of the saving and is a good reason not to build it.

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

**While locked, Direct accepts and seals, exactly like SMTP deferred ingest.**
The connection is accepted, the signature verified, the message placed in the same
pending-parse spool Fortress already uses, and the authorization decision deferred.
A message the sender already sealed needs no edge-sealing step at all — it arrives in
the state the spool wants it in, and no machine on the path ever held it readable. An
unsealed one is edge-sealed on arrival as SMTP mail is. At the next unlock, the existing `unseal → parse` pipeline gains one step:
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
briefly occupies storage until the next unlock. A locked-readable index of blocked
identities would let the receiver drop such connections early. **Rejected**, for four
reasons that compound:

- **A blocklist is more sensitive than a contact list, not less.** Contacts disclose
  who you correspond with; a blocklist discloses who you are trying to get away from —
  harassers, stalkers, an estranged family member, an ex. The people who block someone
  are disproportionately the people endangered by that fact becoming readable, and a
  locked-readable set is readable to whoever steals the locked box.
- **The gain has mostly evaporated.** The manifest declares size at preflight, so an
  oversized message is refused before content transfers, and the endpoint already
  rate-limits by sending instance. What an early drop still saves is storing a bounded
  amount of spam until the next unlock — which is exactly what already happens to every
  other piece of unsolicited mail arriving at a locked Fortress box.
- **One rule beats two.** "Nothing membership-testable while locked" is a property that
  survives arguments. "No locked-readable contact set, but yes a locked-readable block
  set" is a seam that gets relitigated every time someone finds a new set worth
  indexing.
- **At Fortress it would have to live on the relay to work at all**, since the relay is
  what terminates the connection — putting the list of everyone a user has blocked on
  the most exposed machine in the topology, which is worse than the on-box version it
  was meant to improve on.

Blocked Direct mail is therefore sealed and filed to spam at unlock, exactly as blocked
SMTP mail is.

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

**At Fortress the relay is the Direct endpoint, in both directions.** A Fortress
deployment hides its box's address; publishing an SRV record pointing at that box
would advertise in public DNS precisely what the relay exists to conceal. So the SRV
target is the relay, exactly as the MX record is today — Direct changes which door
mail arrives at, not which machine faces the world.

- **Inbound:** the relay terminates the Direct request, then forwards to the origin
  box over the channel it already uses for SMTP-received mail, routed by the domain
  map `RelayMapExporter` already maintains. No new topology, no new map.
- **Outbound:** the relay originates the connection, so the recipient sees the
  relay's address and never the box's. Over HTTPS this is a request the relay makes,
  not a bespoke protocol it must be taught.

This splits the gate across two machines along the line it was already split across
two moments: **the relay authenticates, the box authorizes.** Signature verification
is stateless crypto needing no vault, so the relay does it at the edge and drops
forged or blocked senders before they ever reach the box — which is also where the
cheap-early-rejection property a dedicated listener would have provided comes back,
at the tier that most wants it. The contact gate needs the sealed contact list, so it
runs on the origin box at unlock, exactly as described above.

Sender-side sealing completes the composition: **the relay forwards a blob it cannot
read.** It stops being a component that must be trusted with content and becomes a
pure address-hiding forwarder — a stronger position than it holds today, where
Fortress send hands it a readable message inside a bounded unlock window.

**Send-side key custody.** Outbound Direct is signed with the same custody as the
deployment's DKIM at its level — a local key at Standard, sealed and off-box at
Fortress, where the relay holds the instance signing key alongside the sending
identity it already holds.

The relay path ships **with v1** rather than a phase later, so Fortress deployments
can use Direct from the start. The practical consequence is that v1 is not confined to
this repository: it needs a new relay version and a fleet upgrade (see *Build plan*).
Rollout is safe in any order, because a domain's capability record is published only
once its relay can serve Direct — until then senders see no SRV record and take the
SMTP path they take today.

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
in their contacts. Everything unsolicited never reaches the direct receiver's
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
   through the existing driver flow, with the SRV target chosen per tier (DNS-only
   box host at Standard/Private, relay at Fortress). Read side: a resolver helper
   that answers "does this domain speak Direct, on what host/port/key?"
2. **Direct transport (send)** — a new `EmailServiceProvider` that preflights with an
   envelope and manifest, seals each part to the returned key, transfers the parts,
   and maps `use-smtp`/failure to fallback. Wire it into the `EmailSender` transport
   resolver as a branch.
3. **Direct receiver** — a route serving `/.well-known/joinery-mail`: signature
   verification, contact gate, key answer (with decoy derivation at Fortress),
   manifest admission, then per-part storage into `iem_inbound_email_messages` and
   `imc_inbound_message_attachment`. Built in the shape of
   `inbound_email_webhook.php`, not as a service. Relay store-and-forward for offline
   destinations.
4. **Relay path (in v1, not deferred)** — relay-side termination and forwarding
   inbound, relay-originated connections outbound, instance signing key in relay
   custody. Three sub-items follow from how relays already work:
   - **Map additions.** The relay map already carries per-tenant secrets
     (`srs_secret`, the transport keypair) and already distinguishes `key_kind`
     **user** — a Fortress tenant, seal to that vault's public key — from
     **transport**, the relay's ambient key for everyone else. The preflight answer
     reuses that distinction verbatim rather than inventing a parallel one. What is
     new in the map is the domain secret backing decoy derivation.
   - **A `RELAY_VERSION` bump.** A relay runs provisioned contents and nobody can log
     in to patch one, so teaching relays to speak Direct means shipping a new relay
     version and offering the fleet the upgrade through the existing
     behind/current/ahead flow. v1 therefore carries a fleet-upgrade dependency, not
     just a code change in this repository.
   - **Graceful asymmetry during rollout.** A tenant whose relay is behind simply has
     no capability record published yet, so senders fall back to SMTP and nothing
     breaks. Publishing the SRV record is the last step of enabling Direct for a
     domain, never the first.
5. **UI** — no new idiom. "Add to contacts" already exists. Three touch points
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
17. A message to a recipient whose vault public key is discoverable arrives sealed:
    the body is unreadable in transit, and unreadable on any relay that carried it.
18. A message to a recipient with no discoverable key still delivers over Direct,
    over TLS, unsealed — the absence of a key downgrades content protection, never
    delivery.
19. A whitelist miss on a large message is refused at preflight, with no content
    transferred.
20. A recipient who rotates their vault key between two messages can open both; no
    sender holds a cached key long enough to seal to a retired generation.
21. A sealed message arrives with its structure intact: attachments land as
    individual `imc_inbound_message_attachment` rows, listable and previewable at
    unlock, without ever having been readable in transit.
22. Attachments transfer without base64 inflation, and a message larger than
    `post_max_size` delivers, because no single request carries the whole message.
23. Under Fortress, preflighting a nonexistent address returns an `accept` and a key
    indistinguishable from a real one; nothing in the exchange reveals whether the
    address exists.
24. Under Fortress the published SRV record resolves to the relay; no DNS record
    published by the deployment discloses the origin box's address.
25. A Fortress deployment's relay forwards a sealed Direct message it cannot read,
    and rejects a forged instance signature without contacting the origin box.
26. A deployment that later publishes a different host or port in SRV keeps
    receiving mail from senders that cached the old record, with no coordinated
    change on the sending side.
27. A tenant whose relay has not yet been upgraded to a Direct-capable version
    publishes no capability record and receives mail over SMTP exactly as before —
    a partially upgraded fleet degrades by tenant, never breaks.

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
- **Middlebox on the transport** ("running over 443 puts a CDN or proxy in the
  path"). Answered by sender-side sealing: an intermediary sees ciphertext and
  metadata, not content — and SMTP leaks the same metadata to every hop today. The
  SRV target additionally defaults to an unproxied host, so this is defence in depth
  rather than the only barrier. → *Encryption*, *The capability record*.
- **SRV as an address-disclosure oracle** ("a Fortress box that publishes a
  capability record has just published its address"). The SRV target is the relay at
  Fortress, never the box — the same posture MX already takes. → *Security levels.*
- **Untrusted relay in the path** ("Fortress routes Direct through a machine that
  isn't the box"). The relay carries sealed parts and verifies signatures; it
  authenticates but cannot read, and cannot authorize. Weaker access than it has
  today. → *Security levels.*
- **Stale cached recipient key.** Rejected the cache entirely rather than tuning a
  TTL: a key that expires late produces a delivered, permanently unreadable message,
  which is worse than sending unsealed. The key rides the preflight response instead.
  → *Encryption.*
- **Key-bearing `accept` as an existence oracle.** Answering Fortress preflights with
  a real key would reveal which addresses exist, undoing the unconditional accept.
  Closed by returning a deterministic decoy key for unknown addresses. → *Encryption.*
- **Manifest hash as a possession oracle.** Per-part content hashes would let a sender
  probe which files a recipient already holds by watching what gets skipped. The
  manifest therefore carries size and type only; resume uses byte offsets, not
  hashes. → *Message transfer.*
- **Contact entry satisfied by a spoofed From.** The gate matches address *and* a
  sending domain bound to the verified instance signature, so being in someone's
  contacts is not a name anyone can claim. → *The receive decision.*
- **Locked-readable blocklist** (early-drop index of blocked senders). Rejected: a
  blocklist names who you are trying to escape, so it is more dangerous on a stolen
  locked box than the contact graph, not less — and with size declared at preflight
  the early drop saves little. → *Security levels.*

Open seam, not yet walked: **multi-unlocker / multi-device** — whose unlock runs the
deferred gate, and how the verified mark and read-state reconcile across several
unlockers of one Fortress identity.

## Open decisions

None. Every design question this spec opened is settled in the sections above, and the
reasoning for each rejected alternative is recorded in *Attacks considered* so it does
not get relitigated.

Two seams are deliberately left for later and are not decisions blocking a build:
multi-unlocker reconciliation — whose unlock runs the deferred gate, and how the
verified mark and read state reconcile across several unlockers of one Fortress
identity (*Attacks considered*) — and the growth-path shift from manually maintained
contacts to issued, revocable consent tokens (*Growth path*).

What remains before implementation is ordinary build sequencing, including the fleet
upgrade the relay path depends on.
