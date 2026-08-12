# Joinery Direct Mail

**Status:** BUILT and verified 2026-08-12 — the channel, the mail kind, the Fortress
relay, and SRV on every DNS driver. A post-build multi-agent review (A1–A5
architecture + B1–B19 bugs) is resolved with tests; that review, and its four
deferred residuals, live in `specs/joinery_direct_mail_remediation.md`.
`joinery_direct_enabled` defaults 0 (pre-launch).

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

**The pipe is general; mail is its founding payload.** Joinery Direct is a
kind-dispatched channel: every payload crosses the same discovery, instance
signature, preflight/manifest, sealing, relay, and rate-limit layer, and an
envelope **kind** routes it to its consumer on the receiving side. Mail
(`kind: mail`, the default when absent) is the first kind and the subject of this
spec; the messenger is the second (`kind: chat`, `specs/joinery_messenger.md`).
The shared layer is kind-independent, but **authorization is per-kind** — the
contact gate defined here is the mail and chat rule, not a property of the
channel, and a future kind (calendar invites, contact-card exchange, drive share
offers) defines its own gate rather than inheriting this one. A receiver refuses
a preflight whose kind it does not serve, so a partially-upgraded federation
reads as not-capable for that kind and never breaks.

**The pipe addresses people, not machines.** Every kind's recipient is a user
address (`user@domain`) on a domain whose instance publishes the capability
record; consent, key discovery, decoys, and deferred authorization are all
per-user constructs, and sealing is always to the recipient user's vault key
returned in the `accept`. A payload with no user on the receiving end — fleet
coordination, server-manager traffic, machine-to-machine sync — is out of scope
for this channel and stays on the purpose-built machine channels (`FleetClient`);
putting it here would leave every per-user layer of the pipe with nothing to
attach to.

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
filter. There is nothing to defend against because nothing unrequested can reach
your inbox on it. (The one nuance — a locked Private or Fortress box spools an
accepted message before it can judge it — is bounded and covered in §Why there is no spam
problem.)

## Vocabulary

- **Direct channel** — a single authenticated HTTPS request from the sending
  instance to the receiving instance's advertised host, carrying one message.
- **Direct client** — the send-side service: one call,
  `JoineryDirect::send($recipient_address, $kind, $parts, $options)`, that runs
  discovery, preflight, sealing, and transfer identically for every kind and returns
  a **typed result** — `delivered`, `declined`, `no_capability`, or `failed`, plus
  whether content was sealed and to which key generation — never a behavior. What a
  non-`delivered` result *means* belongs to the calling kind: mail falls back to
  SMTP, chat renders the peer not-reachable.
- **Kind** — the envelope field naming the payload type: `mail` (the default when
  absent) or `chat`, with more later. The receiver dispatches on it and refuses
  preflights for kinds it does not serve. What `declined` means is defined per
  kind: mail falls back to SMTP; chat renders the peer as not-reachable
  (`specs/joinery_messenger.md`).
- **Protocol version** — an integer on the signed envelope naming the version of
  the shared layer itself (envelope shape, manifest fields, hash and signature
  construction) — distinct from *kind*, which names the payload. A receiver
  refuses an envelope whose version it does not implement, in the same
  request-level bucket as an unknown kind; the sender treats that like any other
  failure, so version skew across a federation converges on the caller's fallback
  and never breaks anything.
- **Sealed part** — one piece of a message (body text, HTML, or a single attachment)
  encrypted by the *sending* instance to the recipient's vault public key, so nothing
  between the two endpoints — proxy, CDN, or relay — can read it.
- **Preflight** — the first of the two requests that make up a delivery: envelope and
  signature only, no content. The receiver answers `declined` or `accept`, and an
  `accept` carries the recipient's current public key. (For the mail kind,
  `declined` means "deliver over SMTP instead.")
- **Manifest** — the declaration in the preflight of what is about to be sent: each
  part's size, type, and role. What the receiver accepts or refuses *before* any content
  crosses the wire. (Per-part integrity hashes are not here — they cover the *sealed*
  bytes, which do not exist until the recipient key arrives in the `accept`, so they
  travel signed *with the content transfer*; see *Message transfer*.)
- **Delivery session** — the short-lived, single-use state an `accept` opens on
  the receiving side, keyed by the envelope nonce: the admitted manifest, the
  verified sending-instance identity, and the key generation answered. The content
  transfer redeems it — once. Completion or terminal failure consumes it, expiry
  (a declared TTL, default **15 minutes**) discards it along with any partial
  parts, and a transfer against a consumed, expired, or unknown session is a
  request-level refusal. It holds nothing sealed and nothing per-user, so it works
  while a vault is locked.
- **Capability record** — a DNS record on the recipient's domain advertising that
  it speaks Joinery Direct: the host, port, and the instance's signing key.
- **Instance signature** — an Ed25519 signature by the *sending instance*, verified by
  the receiver against the sender domain's capability record. The sender signs two
  things: the **preflight** (envelope + manifest of sizes/types) and, after sealing, the
  **content transfer** (the per-part hashes of the sealed bytes, bound to the preflight
  nonce). The first authenticates and dates the exchange; the second binds the exact
  delivered bytes. This is DKIM's job, but mandatory and non-optional: no valid instance
  signature, no acceptance.
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

- **`SRV`** `_joinery._tcp.<domain>` → host + port of the receiving endpoint.
  Port is advertised, never hardcoded; the default is 443. The sender accepts only
  **443 or a port ≥ 1024** (see *The send decision* → SSRF guard).
- **`TXT`** `_joinery-key.<domain>` → the instance's Ed25519 public signing key
  (base64), with a key id so keys can rotate without a flag day.

Both are published by the existing DNS plan/driver flow. Absence of the SRV record
means "this domain does not speak Direct" — the sender falls back silently.

**The transport is ordinary HTTPS**: a POST to `/.well-known/joinery-direct` at the
SRV-named host and port. The receiving box already terminates TLS for its own web
traffic, so Direct needs no second TLS stack, no additional certificate, no firewall
change, and no long-running service — it is a route, handled the way the existing
inbound webhook is handled. It therefore also works on deployments that cannot bind
a port at all, including shared hosting.

SRV still earns its place despite a fixed path, for a specific reason: a customer's
mail domain usually does not point its web traffic at the Joinery box, so
`https://<maildomain>/.well-known/joinery-direct` would land on their marketing site.
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

The send side is the **Direct client** (see *Vocabulary*) — one kind-independent
call that every consumer uses, mail included. The client owns the shared layer end
to end: the caller supplies a recipient address, a kind, and parts, and gets back a
typed result. The client never decides what a result means; that is the caller's
kind policy.

**What the caller hands the client.** `$parts` is a list of part descriptors —
`role` (`body_text`, `body_html`, `attachment`), `content_type`, `filename` for
attachments, and the content as either `bytes` or a `path`/stream — so a large
attachment never has to sit in memory as a string just to be sent. Sealing is
`crypto_box_seal`, a one-shot primitive, so peak memory scales with the *largest
single part*, never the whole message — the ceiling the per-part size cap already
enforces. `$options` stays minimal (a timeout override); anything a kind needs to
say travels in its parts, not in new envelope fields.

For any send:

1. Look up `_joinery._tcp.<recipient-domain>`. No record → return
   **`no_capability`**.
2. Record present → **preflight**: POST the envelope — carrying a fresh timestamp and a
   random per-delivery nonce — the manifest, and the instance signature to
   `/.well-known/joinery-direct` at the advertised host and port. No content. A retry
   after any failure is a newly signed envelope with its own timestamp and nonce.
3. The receiver either answers **`declined`** (this recipient does not accept Direct
   from this sender — returned to the caller as-is) or **`accept`**, carrying the
   recipient's current public key and key generation.
4. On `accept` → seal each part to the returned key, then transfer the parts together
   with a signature over their sealed-byte hashes (bound to the preflight nonce) →
   **`delivered`**, recording whether the parts were sealed and to which key
   generation. The transfer redeems the accept's single-use delivery session and
   must complete within its window (see *The receive decision*); a late attempt is
   refused, and a retry is a fresh preflight. Any connection or verification
   failure at either step → **`failed`**.

**Mail is the client's first caller, not its owner.** The mail transport — a new
`EmailServiceProvider` wired into the `EmailSender` resolver — calls the client with
`kind: mail` and maps every result short of `delivered` to the existing SMTP/relay
path (see *The SMTP fallback boundary*). That mapping lives only in the mail
adapter: the SMTP fallback is mail-kind policy, not pipe behavior. Chat maps the
same results to "not reachable" (`specs/joinery_messenger.md`), and a future kind
defines its own mapping.

**The SRV target is validated before the sender connects (SSRF guard).** The recipient
domain — and therefore the SRV host and port — is chosen by whoever controls that domain,
so a hostile domain could aim Direct at `127.0.0.1`, a cloud-metadata address, an
internal host, or a sensitive port. The sender resolves and connects through the
SSRF-safe outbound client (`specs/safe_http_client.md`): every resolved A/AAAA address is
checked against the private/reserved/loopback/link-local blocklist and the connection is
pinned to a validated public IP; the port must be **443 or ≥ 1024** — privileged ports
below 1024 (the SSH/SMTP/DNS range) are refused, since a dedicated Direct listener runs
on 443 or a high port, never a privileged one; TLS is verified against the SRV hostname;
and redirects are never followed. Any failure is `failed` to the caller, treated like
any other Direct failure — for mail, a silent fall back to SMTP. Mandatory TLS
verification is the load-bearing control at any
port: a target that cannot present a valid certificate for the recipient's host (a raw
Redis, Memcached, or SSH port) never completes the handshake, so the port rule is
defence-in-depth over it, not the only barrier.

**The refusal is a plain status code, not a signed statement.** There is nothing for
the sender to verify: the response already comes over TLS from the SRV-named host, and
the worst a forged refusal achieves is a downgrade to the caller's fallback — for mail,
SMTP: the path the message would have taken before this feature existed. Signing it would buy nothing and add a
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
endpoint — the only thing crossing the wire is *accepted* vs *declined*.

The consequence for abuse: **permission is never cached on the sender.** Their
instance must query yours on every single send, and yours can refuse on every one.
(A sender may cache a recent `declined` for a recipient under a short TTL to skip a
redundant attempt — but that is its own observation, never a query of your state,
and it expires quickly so re-adding a contact heals on its own.)

## The receive decision (receiving instance)

A route serving `/.well-known/joinery-direct`, in the same shape as the existing
inbound webhook. The route first dispatches on the envelope kind (see *Serving a
kind*, below, for how kinds register) — the steps below define the mail kind, and a
preflight for a kind the receiver does not serve is refused before any of them run.
Steps 1–3 run identically at every tier; steps 4–5 are the **Standard** behaviour —
at Private and Fortress the receiver instead accepts unconditionally and defers the
gate (see *Security levels*). On preflight:

1. **Verify the instance signature** against the sender domain's capability record,
   resolved through a **cached, rate-limited** capability lookup (see *Resolving the
   sender's capability record*, below), with the key id matched. Invalid or unsigned →
   refuse.
2. **Check freshness and replay.** The signed envelope carries a timestamp and a
   random per-delivery nonce (≥128 bits), both inside what the instance signature
   covers. Refuse if the timestamp is more than **5 minutes** old or more than **1
   minute** in the future (a clock-skew margin), and refuse if the nonce is already in
   the replay cache. On acceptance, record the nonce with a **10-minute** expiry —
   deliberately longer than the acceptance window, so a replay old enough to have aged
   out of the cache is already too stale to pass the freshness check and the two checks
   compose with no gap. The cache holds only opaque nonces and expiries — no contact or
   content data — so it works while the vault is locked and a Fortress box can dedup
   without unlocking. A benign retry from a real sender never collides: each attempt is
   a freshly signed envelope with its own timestamp and nonce. This runs before the
   contact gate, and a failure here is a **request-level refusal** in the same bucket as
   an invalid signature — not one of the two contact-gate answers — so it discloses
   nothing about the recipient: only a replayer, who already holds the captured message,
   ever triggers it.
3. **Manifest bounds.** The declared manifest is checked against the instance's
   size caps — maximum parts per message, maximum bytes per part, and maximum
   total bytes (declared settings; defaults **64 parts / 100 MB per part / 250 MB
   total**) — applied identically to every recipient and every kind, at every
   tier. Exceeding any is a request-level refusal in the same bucket as an
   invalid signature: the caps are instance configuration, not per-user data, so
   the answer discloses nothing. The admitted manifest is also the transfer-time
   contract — a delivered part that exceeds its declared size aborts the
   delivery.
4. **Contact gate** — matched on the full sender address **and** a sending domain that
   matches the verified instance signature, never the bare address alone. A contact
   entry for `alice@example.com` is satisfied only by a message signed by
   `example.com`'s instance key, so a spoofed From cannot borrow someone else's place
   in your contacts. Three outcomes, but only **two answers on the wire**, so the
   protocol never becomes an oracle for the recipient's contact or block list:
   - **Contact** → accept; continue to step 5.
   - **Not a contact** (stranger, or a contact you removed) → answer `declined` and
     drop (do not store, do not queue). The message reverts to ordinary email.
   - **Blocked** → identical on the wire. A block removes the contact, so a blocked
     sender already fails the contact check and gets the same `declined` as any
     stranger — there is no separate block branch and no gate-time block lookup. The
     block adds only that the fallback SMTP message is filed as spam by the recipient's
     `mark_spam` filter rule (see *Abuse*). The sender cannot tell a block from a plain
     downgrade.
5. **Accept**, answering with the recipient's current public key and generation, and
   admit the declared manifest. Then take the sealed parts on the second request and
   store into `iem_inbound_email_messages` — attachments as their own
   `imc_inbound_message_attachment` rows, as they are stored today — tagged with its
   transport so the UI can show "delivered directly."

Consent is a living list, not a one-time grant — every connection re-checks it.

**An `accept` opens a single-use delivery session — the state between the two
requests.** The receiver records, keyed by the envelope nonce: the admitted
manifest, the verified sender identity (domain and key id), and the key generation
it answered with. The content transfer redeems the session: each arriving part is
verified against the session's sender key (the transfer signature is bound to the
nonce) and checked against the admitted manifest — part count, declared sizes,
roles — so a sender cannot stream more than it declared (step 3). Completion
consumes the session, and so does a terminal failure such as a hash mismatch; a
second transfer for the same nonce is a request-level refusal, closing
content-transfer replay the way step 2 closes preflight replay. An unredeemed
session expires after a declared TTL (default **15 minutes**), discarding any
partially delivered parts — the sender sees a refusal, mail falls back to SMTP,
and any retry is a fresh preflight with its own nonce. The session holds only
envelope and manifest data — nothing sealed, nothing per-user — so it works while
a vault is locked. At Fortress it lives at the relay, where the wire terminates;
the origin box needs no session, because it independently re-verifies both
signatures and every sealed-byte hash on the forwarded delivery (*Message
transfer*) — the session disciplines the wire, while the signatures protect
against the relay itself.

**Resolving the sender's capability record (bounded, not per-request).** The sender
domain in a preflight is attacker-chosen, and verifying the signature needs that
domain's signing key from DNS — so a naive "fresh lookup every preflight" turns the
receiver into an outbound-DNS engine driven by attacker input (an amplification and
forced-resolution lever, and it runs *before* the request is authenticated, so the
per-instance limit in *Abuse* cannot yet apply). Three bounds close it:

- **Cache the capability record**, keyed by domain and key id, honoring the record's DNS
  TTL within a sane floor and ceiling. A busy legitimate sender is then one lookup per
  TTL, not one per message. A signature presenting a key id not in cache triggers at most
  one refresh — so a rotation is still picked up promptly — and those refreshes are
  themselves rate-limited, so an attacker cannot force unbounded lookups by naming random
  key ids.
- **Negative-cache failures.** A domain with no capability record, NXDOMAIN, or SERVFAIL
  is remembered as "no Direct" for a TTL and short-circuited to refuse, so repeatedly
  naming non-resolving domains costs one lookup, not one per request.
- **Rate-limit by connecting peer.** Because verification has not happened yet, this
  earlier limit keys on the transport peer (connecting IP) to cap how much resolver work
  any one source can drive. This is `RequestLogger::check_rate_limit` — the same
  IP-keyed sliding-window limiter the API and login paths already use — under a
  Direct feature key, not a new mechanism.

This is DNS resolution through the system resolver, not an HTTP fetch, so it is not the
SSRF surface `specs/safe_http_client.md` addresses; the concern here is the *volume* of
attacker-driven lookups, and caching plus the two limits are the answer.

## Serving a kind: registration and the handler contract

This is the developer surface — what it takes for any part of Joinery, core or
plugin, to put a payload on the pipe.

**Registration is declarative.** A plugin serves a kind by declaring it in its
`plugin.json` under `directKinds` — kind name → handler class — the same idiom the
platform already uses for settings, menus, and signals:

```json
"directKinds": {
  "chat": { "handler": "includes/ChatDirectHandler.php", "gate": "contacts" }
}
```

The string shorthand `"chat": "includes/ChatDirectHandler.php"` is equivalent and
means the handler supplies its own `gate`. Core kinds are declared the same way in
the core manifest, with mail as the first entry. The registry is instance configuration, readable without loading plugin
code, so "does this instance serve kind X" is answerable the moment a preflight
arrives; a kind whose plugin is deactivated is simply absent from the served set,
so its preflights refuse exactly like an unknown kind's — the
partially-upgraded-federation behavior falls out with no special case. The refusal
is request-level (the same bucket as an invalid signature), issued before any
handler code runs.

At Fortress the wire terminates at the relay, so the refusal must too: the
served-kind set is exported to the relay **as data** in the relay map, alongside
the keys and secrets `RelayMapExporter` already ships, and the relay matches the
envelope's kind string against it. Relay *code* stays kind-blind — a kind is an
opaque string it compares, never logic it implements — so a new kind, core or
plugin, reaches the fleet as a map update, never a `RELAY_VERSION` bump (see
*Security levels*).

**The framework owns the wire; a handler owns only its decision and its storage.**
The hard-won properties of this design — exactly two gate answers, request-level
refusals distinct from them, unconditional accept with a decoy key at the sealed tiers, no
lock-state oracle, never a bounce — must hold for every kind, because a receiving
endpoint is only as oracle-free as its leakiest kind. So they are not conventions a
handler is trusted to follow; they are structure a handler cannot break:

| Layer | Responsibility |
|---|---|
| Framework, identical for every kind | Signature verification; freshness + replay; manifest size bounds; per-instance and per-peer rate limits; spool byte caps; kind dispatch; every wire answer, including the key and sealed-tier decoy; sealed-byte hash verification; spool-while-locked and unlock scheduling |
| Kind handler | `gate(envelope): accept \| decline` and `ingest(envelope, parts, gate_outcome)` |

A handler's entire surface is those two pure functions:

- **`gate(envelope)`** answers "does this recipient accept this kind from this
  sender" — nothing else. It never sees lock state, never composes a wire
  response, and at Private and Fortress is **not called at receive**: the framework
  accepts unconditionally and defers the gate (see *Security levels*). A `decline`
  becomes the wire's `declined` at Standard and a silent local filing decision at
  Private and Fortress; the handler cannot tell which, and that is the point.
- **`ingest(envelope, parts, gate_outcome)`** stores the delivered payload in the
  kind's own model. It runs only after the framework has verified every
  sealed-byte hash. On the live path it is called only on `accept`, so
  `gate_outcome` is always accept there. On the deferred path (*Security levels*)
  it is called at unlock for **every** spooled delivery, carrying the deferred
  gate's outcome — because the sender was already answered `accept`, a deferred
  decline is a local disposition, not a drop: mail files a declined message
  exactly where SMTP would have put it (ordinary/spam, no verified mark), never
  losing it and never signalling the sender. The verified-sender fact and
  transport tag arrive with the envelope, so a kind can drive its own UI the way
  mail's verified-direct mark does.

With that shape a kind cannot produce a third wire answer, a lock-state
distinguisher, or a bounce, and reviewing a new kind means reviewing two pure
functions.

**Handlers see typed objects, never wire bytes.** `gate` and `ingest` receive an
envelope object with named accessors — kind, protocol version, verified sender
(address and signing domain), recipient (address and resolved mailbox/user), and
the manifest (each part's role, type, and declared size) — and `ingest`'s parts
are part objects exposing role, content type, sealed-or-not, and a handle to the
content. A handler never parses the wire format, so the wire format can move (a
new protocol version) without touching any handler.

**Every kind is testable without a second instance.** The framework provides a
loopback send for the test estate: `JoineryDirect::send` to an address served by
the same instance runs the full receive framework locally — registry lookup,
session, gate, hash verification, ingest — with no DNS record and no network, so
a plugin's handler is exercisable from `plugins/{plugin}/tests/` like any other
code. Loopback is a test-tier tool, not a delivery path: real same-instance mail
never needs Direct.

**The contact gate is a canned gate, not mail's private one.** Mail's
authorization — full sender address plus a sending domain bound to the verified
instance signature, matched against `imc_mailbox_contacts` — is exported by the
framework as a reusable policy, declared as `"gate": "contacts"` in the kind's
`directKinds` entry. The framework then runs the canned gate itself and never
calls a handler `gate` at all — such a handler implements only `ingest`. Mail and
chat both declare it; a future kind declares it in that one line or supplies its
own `gate`. Authorization stays per-kind, but the best-reviewed gate is the
easiest one to reach for.

## Abuse: removing vs blocking

The Direct channel elevates trusted senders; it was never a wall against known-bad
ones. Misbehavior is handled where all mail abuse is handled — plus a live gate at
the receiving endpoint:

- **Remove from contacts** = a neutral downgrade. Their next message reverts to
  SMTP. No punishment, just "no longer elevated."
- **Mark as spam / block** = remove the contact *and* add a sender-matched `mark_spam`
  rule to the existing inbound filter engine (`inbound_email_filter`: `fil_match_from`
  = the sender, `fil_action_mark_spam`). There is no separate block store. Future
  Direct attempts, having lost their contact entry, get the indistinguishable
  `declined` answer like any non-contact; the ensuing SMTP delivery is auto-filed as
  spam by that rule. The block is a disposition applied once the message is on the box,
  never an endpoint lookup — a gate-time block index would have to be readable while
  locked, which §Security levels rejects.
- **Direct-path blocking is not total blocking.** Anyone can still reach you over
  SMTP, exactly as in email today — removing a contact can no more stop that than
  deleting someone from Gmail contacts stops their email. What Direct adds is that
  abuse *loses its verified mark and inbox placement the instant you act*, and every
  future attempt must re-ask your instance live.
- The endpoint rate-limits preflights **by verified sending instance** — the identity
  the instance signature establishes — so one instance cannot flood you no matter which
  of your addresses it aims at. This is a per-instance throttle, **not** a
  blocked-sender lookup: an individual sender is never dropped early (that would need a
  gate-time block index readable while locked, which §Security levels rejects). A
  blocked sender is simply a non-contact on the wire whose SMTP fallback is spam-filed.
  At Fortress the throttle runs at the relay, before the box is touched at all, with
  the limit values carried to it as relay-map data. The limiter is the platform's
  existing sliding-window idiom, not a new engine: the per-instance check counts
  recent preflights per sender domain in Direct's own request log, exactly as the
  mailbox forwarding limiters count the inbound email log, and the pre-verification
  per-peer check is `RequestLogger::check_rate_limit` (see *The receive decision*).
  The limit is a **declared setting** (default **120 preflights per instance per
  rolling 2 minutes** — the same average and burst as 60/min with burst 120, in
  window form — tunable) and is the count cap referenced by §Why there is no spam
  problem; the byte cap beside it is the spool cap (§Security levels).
- Counts alone do not bound storage, so two byte-denominated caps ride with the
  limiter: the framework's **manifest size bounds** at preflight (*The receive
  decision*, step 3) and the **Direct spool caps** at the sealed tiers (*Security
  levels*). A refusal from any of them is request-level and downgrades mail to
  SMTP — nothing is lost.
- **Nothing is silent to the operator.** Every request-level refusal (signature,
  freshness, version, bounds, caps) and every send-side downgrade is counted in
  Direct's request log and surfaced on the admin dashboard — so a box whose clock
  has drifted (every inbound preflight failing freshness, every send quietly
  falling back to SMTP with no user-visible symptom) is diagnosable at a glance.
  Silence is a wire posture toward senders, never toward the operator.

## Encryption

Two layers, both in v1.

**Transport:** TLS on the advertised port, terminated by the receiving instance's
existing web stack. Instance signing gives authenticity and integrity on top.

**Content: the sender seals the body before it leaves the box**, encrypting to the
recipient's vault public key with `VaultCrypto` — the same primitive the edge relay
already uses to seal inbound mail. This is *opportunistic*: seal when the recipient's
key is discoverable, send plaintext-over-TLS when it isn't, exactly as the path
itself degrades to SMTP.

Sealing is in v1 rather than deferred because it is what makes Direct **better against
an in-path reader** than the alternative rather than merely faster, and the difference
is sharpest exactly where it matters most. Under Fortress today, mail arrives readable
at the edge relay and the relay seals it — so an off-box machine holds plaintext for
a moment. A message sealed by the sender has no such moment, on the relay or on any
proxy, CDN, or middlebox in the path. Deferring content encryption to a later phase
would ship the version that keeps the weaker property.

**What sealing does not defend against is a compromise of the recipient's name
resolution.** The sender seals to the key returned in the preflight `accept`, trusting
it because it arrived over TLS from the SRV-advertised host. An attacker who hijacks the
recipient domain's DNS can point SRV at their own host, terminate TLS with a valid cert
for *that* host, and return *their own* vault key; the sender seals to it and the
attacker reads the message. The instance signature does not help — it authenticates the
sender to the receiver, not the receiver to the sender — and neither does binding the
key to a DNS record, since a domain hijacker rewrites that too. Against a DNS hijack
Direct is therefore **no worse than SMTP**, which the same hijack redirects to a
plaintext-collecting host, but it is **not the unconditional upgrade** a bare "always
better" would claim: sealing raises the bar from *sitting anywhere in the path* to
*controlling the recipient's DNS*, and no higher. A recipient domain that deploys DNSSEC
closes the redirect for free — a validating resolver rejects the forged SRV — and Direct
honors that ambient protection but neither requires nor re-implements it, because
mandating DNSSEC would make Direct unavailable to the majority of domains that do not
run it. A trust-on-first-use check on the recipient's *box identity* is a possible later
hardening; TOFU on the per-user *key* is deliberately not taken, as caching a key for
continuity collides with the never-cache-the-key correctness rule below (a key cached
across a rotation seals messages nobody can open).

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

**The sealed tiers answer with a key for every address, real or not.** At Private
and Fortress the receiver accepts unconditionally so that acceptance discloses nothing — but a
key-bearing `accept` would reopen exactly what that closed. Verifying an instance
signature proves a sender is who it claims, never that it is welcome, so any instance
could preflight a guessed address and learn from a real key coming back that the
address exists. So the receiver returns a key unconditionally too, deriving a
deterministic decoy from a domain secret for addresses that do not exist (the
domain secret lives on the box at Private, and travels in the relay map at
Fortress, where the relay answers preflights). It is
indistinguishable from a real key; the sender seals to it, the message arrives, and
nobody can ever open it — which costs nothing, because mail to a nonexistent address
was going nowhere in any case. Existence, contact membership, and block status all stay
unknowable from the wire.

A decoy is derived as `HMAC(domain secret, lowercased address)` used as an X25519
scalar, and the published decoy is its base-point multiple. The scalar — the private
half — is therefore computed, but only in passing: it is zeroed the instant the point
is derived and is never stored anywhere, so nothing on the box or off it can open a
message sealed to a decoy. Two properties make it hold up:
it must be a valid curve point, or malformed-key errors would identify it, and it must
be **deterministic**, since a key that changed between probes of the same address would
itself be the tell.

**The `accept` carries a key generation, and a decoy reports generation 1** — the value
a freshly created, never-rotated vault carries (`uev_key_generation` defaults to 1).
Most real vaults never rotate, so generation 1 is the overwhelmingly common real answer;
a decoy reporting it blends into that cohort, and a probe that sees generation 1 cannot
tell a decoy from a real never-rotated address. A fixed value also keeps the decoy
deterministic, as required above.

**One residual distinguisher, accepted on the record.** A real vault that *has* rotated
reports a higher generation, so an attacker who has already correctly guessed such an
address can tell it from a decoy — and, equivalently, probing one address across a
rotation would see a real key advance while a decoy stands still. Both are the same weak,
one-sided leak: they can only *confirm* existence for an address the attacker already
guessed *and* whose owner has rotated (a minority — rotation is a rare recovery or
key-compromise event), and they never *deny* existence for anything. Closing it fully
would require a decoy to forge a plausible per-address rotation history — deterministically
faking not just a key but a believable generation trajectory over time — which is complex,
fragile, and buys little against an attack that must first guess a low-entropy address and
then catch or await a rotation. The decoy therefore holds at generation 1 and this residual
is accepted.

Decoys are a sealed-tier (Private and Fortress) mechanism, not a uniform behaviour
across tiers. At Standard the contact gate refuses a stranger with `declined` before
any key is offered, so the only sender who ever receives a key is one already in the
recipient's contacts — who knows the address exists. There is no oracle to close at
Standard, and a decoy path there would be dead code.

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
  and a whitelist miss costs no attachment bandwidth at all. "Oversized" is
  enforceable because the framework checks the manifest against declared size caps
  at preflight (*The receive decision*, step 3), at every tier.
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

**Each sealed part is bound to a signature — over its ciphertext, and carried with the
content, not the preflight.** Sealing needs the recipient's key, which only arrives in
the `accept`, so the sealed bytes do not exist when the preflight is sent and their
hashes cannot ride the preflight manifest. Instead the content transfer carries, for
each part, a BLAKE2b-256 hash of its *sealed bytes*, and the sending instance signs that
set — bound to the preflight's envelope nonce, so content cannot be spliced onto a
different preflight. On receipt the box hashes each delivered part and rejects the
*entire* message on any mismatch, before applying the verified-direct mark. This is what
binds the delivered bytes. Without it there is nothing to bind: the parts arrive under
an anonymous seal (`crypto_box_seal`), which anyone holding the recipient's public key
could construct, so an in-path element — including a Fortress relay that cannot read the
content — could substitute wholesale and the receiver would decrypt attacker-chosen
bytes cleanly and then stamp them verified-direct. Hashing the *ciphertext* rather than
the plaintext lets the receiving box verify without unsealing, so even a locked Fortress
box rejects a substituted part at receive rather than discovering it at unlock — which
matters precisely because the relay, the untrusted machine, is the one forwarding those
bytes; the Ed25519 signature over the hash authenticates the plaintext transitively once
it is unsealed. The transfer also redeems the preflight's single-use delivery session
(*The receive decision*), so it can happen exactly once: a captured transfer replayed
against a consumed or expired session is refused at request level.

What the hash must **never** do is let the receiver skip a part it already holds.
Skip-if-held is an oracle pointed at the recipient: a sender that watches which parts
get skipped learns exactly which files that recipient possesses, and can probe for a
specific one by offering it. The rule that keeps integrity without the oracle is
absolute — **the receiver always takes the full transfer**, verifies every part, and
only then acts; it never signals, per part, that it already had the content, and it
never handles one part differently from another based on the hash. Resume across an
interrupted transfer is the one defensible saving and needs only a per-part byte
offset, not content-defined skipping; if it is ever built it must be invisible to the
sender — the receiver takes the bytes and discards them — which removes most of the
saving and is a good reason not to build it.

## Security levels: locked vaults and the sealed tiers

Contacts (`imc_mailbox_contacts`) seal under the **same rule as the mail beside
them**: sealed at rest only when the owner holds a vault **and** the mailbox's domain
seals content — Private or Fortress, i.e. `$domain->seals_content()`, the identical
condition the message path uses (`InboundEmailRouter`). At **Standard** contacts are
plaintext whether or not the owner happens to hold a vault for other purposes, so the
live contact gate always runs at receive. Sealing an address book while the mail it
describes sits in plaintext would protect nothing — every correspondent is already
visible in that plaintext mail — so both hang off one posture switch: a Standard
mailbox is server-readable end to end, a Private/Fortress mailbox seals end to end.
There is no "plaintext mail, sealed contacts" mixed state, and therefore no Standard
mailbox where the gate can be blocked by a locked vault.

Sealed contacts (Private/Fortress) can be read only while the vault is unlocked. That
raises the obvious question at **Private** (encrypted at rest, decrypts in bounded
unlock windows) and **Fortress** (Private + edge-sealing ingest + off-box send): is
Direct receiving only available while unlocked? **No** — Fortress already solved
"receive while locked" for ordinary mail, and Direct rides the same machinery at
both sealed tiers. **Private and Fortress share one receive posture on the wire —
accept unconditionally, never a live `declined`, defer authorization — and differ
only in topology: Fortress adds the relay and edge concealment.** A Private box
that answered live while unlocked and deferred while locked would turn its own
answer into the lock-state oracle this section closes, so the sealed tiers behave
identically whether locked or unlocked.

**Authentication runs locked; authorization defers to unlock.** The gate is two
halves and only one needs the vault:

- *Is this really sender X's instance?* — verifying the instance signature against
  the sender domain's DNS key is stateless crypto. No vault needed, so it runs at
  receive time even while locked. The verified-sender fact is recorded next to the
  message.
- *Is X in my contacts?* — needs the sealed contact list, so it runs only in an
  unlock window.

**While locked, Direct accepts and seals, exactly like SMTP deferred ingest — and
deferral is a shared-layer service, for every kind.** The connection is accepted,
the signature verified and the sealed-byte hashes checked (both possible without the
vault), and the delivery placed in the **Direct spool**: a framework-owned holding
store keyed by kind, carrying the envelope, the verified-sender fact, and the sealed
parts — nothing in it needs the vault. A message the sender already sealed needs no
edge-sealing step at all — it arrives in the state the spool wants it in, and no
machine on the path ever held it readable. An unsealed one is sealed on arrival
(at the edge under Fortress, on the box at Private) as SMTP mail is. At the next
unlock — at Fortress the same trigger that drains the existing SMTP pending-parse
spool — the framework drains the Direct spool: for each
delivery it runs the kind's deferred `gate`, then hands the outcome to the kind's
`ingest` (see *Serving a kind*). For mail that means: run the contact gate against
the now-readable list, and either apply the verified-direct mark (sender is a
contact) or file the message exactly where SMTP would have (not a contact →
ordinary/spam). The mark is deferred, never lost.

**"Where SMTP would have" is literal — the gate decides elevation, never
placement.** A non-contact's Direct message is handed to the same full inbound
pipeline deferred SMTP mail already runs at unlock (`DeferredIngest` → parse,
attachment split, filter rules), including the spam verdict: sender-sealed
content has no relay-stamped score — no machine in the path could read it — so
the box's own content scan (`scanContentSpam`, the same
no-upstream-verdict path `MailboxSpamPolicy` already handles) runs at unlock, the
first moment the content is readable. Direct changes the transport, not the
classification: a non-contact is spam-scored and filter-sorted exactly as if the
message had arrived over SMTP, so the direct path bypasses the spam apparatus for
no one. A contact's mail skips it only in the sense that consent elevates it past
scoring — the address-book feature, applied identically at every tier. The one
honest delta from SMTP is timing: time-sensitive signals (URL reputation, RBLs)
are evaluated at unlock rather than at receive, which is inherent to content
nobody in the path can read.

**A spooled kind whose plugin is deactivated before unlock is held, not errored.**
The spool keeps the delivery sealed until the plugin is reactivated, and expires it
quietly under the spool's ordinary retention if it never is. Nothing is returned to
the sender in either case — the sender was answered `accept` at receive, and the
no-bounce rule below applies to every kind, not just mail.

**The defer decision is per-mailbox, not per-domain — an unencrypted mailbox on a
sealing domain never waits for an unlock.** Deferral exists for one reason only: an
encrypted mailbox's contact list is sealed, so the gate cannot read it until that
mailbox's owner unlocks. A mailbox that does not encrypt — a **shared/group alias**
(several grantees, no single vault) or an individual who holds no vault — has a
*plaintext* address book even when its domain seals content, so its gate has nothing
to wait for. Such a mailbox keeps the sealed tier's uniform wire posture — it still
accepts unconditionally, so the answer signals neither existence nor encryption
status — but runs its gate at **commit**, the moment the parts have arrived and their
sealed-byte hashes verified: the contact check reads the plaintext book right then
and files the message with its outcome — verified-direct for a contact,
ordinary/spam for a stranger — exactly as the deferred path files it at unlock, only
without the wait. Nothing is ever held for an unlock that will not come, so no
unencrypted mailbox loses mail; only encrypted single-owner mailboxes defer. A shared
alias's address book is the alias's own contacts — an entry any grantee added counts
— so "in the mailbox's book" is answered against the mailbox, not against one person.

**The spool is capped in bytes, and the cap is neither Sybil-able nor an oracle.**
Accept-before-judgment means a flood's only real spend is disk held sealed until
unlock, so that is what gets bounded — in two layers, both declared settings: a
**per-domain cap** on the whole Direct spool (default **10 GB**), a request-level
refusal once full, safe to signal because it is instance-level state and the same
answer for every address; and a **per-address cap** beneath it (default **1 GB**),
so one flooded recipient cannot consume the domain's budget. The per-address
accounting applies to every address, real or decoy: a decoy delivery is discarded,
but its declared bytes are still counted, so an address past its cap refuses
identically whether it exists or not — sustained flooding cannot convert the cap
into an existence probe. Counters drain as the spool drains at unlock, and by
retention expiry for addresses holding nothing. Because the caps are absolute
recipient-side bounds, no number of cheap sending domains raises the ceiling the
way Sybil multiplies the per-instance rate limit. A cap refusal costs a legitimate
sender only the downgrade — the mail adapter falls back to SMTP and the message
arrives anyway; chat renders not-reachable. A silent local drop was considered and
rejected: it could lose a legitimate contact's sealed mail, where a request-level
refusal loses nothing. At Fortress the checks run where the wire terminates — the
relay — with the cap values carried in the relay map and enforced as windowed byte
counters at the edge, exactly as the rate limiter is.

**A deferred rejection is local, never returned.** This is the one place the sealed
tiers genuinely differ from Standard. At Standard the gate runs live, so a non-contact
gets `declined` on the wire and the *sender* re-sends over SMTP. Under Private or
Fortress the message was accepted and sealed before the gate could run, and the sender is long
gone by unlock — so a rejection at unlock is a **local filing decision**: the message
is placed exactly where SMTP would have put it (ordinary/spam) and denied the
verified mark. It is not bounced, not returned to the sending instance, and the
sender is never told. From the sender's side the message was "delivered" — and it
was, into your spam. No round trip, no duplicate, no notification. The end state
matches regular email; only the path differs. To keep this oracle-free, the sealed
tiers use accept-then-decide-locally whether locked or unlocked — they never signal
`declined`.

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
saves the deferred step. It would be moot at Standard (contacts are plaintext there)
and merely tolerable at Private, whose threat model does not include an attacker
reading sealed data while locked. It is a **net loss at Fortress**, whose threat
model is exactly that — and Private deliberately shares Fortress's receive path
rather than gaining a mechanism whose only job is saving the middle tier a deferral
(one rule beats two, below). For the gate to run while locked, the hashing key
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
unlocked — it never answers `declined` based on lock state — so a sender cannot probe
unlock windows by watching whether Direct succeeds. Fortress treats locked-state
metadata leaks as in scope, and Private shares the same wire posture so no seam
opens between the sealed tiers; Direct must not become one. (The live two-answer gate in
the send-decision section applies at **Standard**, where contacts are cleartext *by
posture* — a Standard mailbox never seals them — so the gate always runs at receive
and no locked vault can block it.)

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

**The relay is kind-agnostic by construction.** It authenticates, rate-limits, and
forwards deliveries of any kind; the only kind-aware thing it does is match the
envelope's kind — an opaque string — against the tenant's served-kind list carried
in the relay map, refusing unserved kinds at the edge exactly as the box itself
would. That refusal is request-level and leaks nothing: the served set is instance
configuration, not per-user data. Kinds are data to the relay, never code — a
tenant activating the messenger plugin, or any future kind, reaches the fleet as a
map update with no relay release and no fleet upgrade. `RELAY_VERSION` moves only
when the shared layer itself changes. (A delivery accepted in the window between a
plugin's deactivation and the map update propagating simply lands in the Direct
spool and is held or expires there — see the deferred-path rules above.)

**Send-side key custody mirrors DKIM: the box signs, the relay only transports.**
Outbound Direct is signed with the same custody as the deployment's DKIM at its level.
At Standard the signing key is a local filesystem key; at Fortress it is sealed to the
domain owner's vault (`ied_dkim_sealed_key`) and unwrapped **on the box, in-window, per
send**, then zeroed — exactly as `MailboxDkimSigner` already does for DKIM. The box
produces the fully-formed, instance-signed request; the relay originates the outbound
connection (so the recipient sees the relay's address, never the box's) but **never
holds the instance signing key and never signs** — the same division `OutboundTransport`
already enforces, where the relay transports an app-signed message it cannot alter.
Moving the signing key onto the relay would be a *new* custody model this design
deliberately avoids: the relay stays a pure address-hiding forwarder, a weaker position
than holding a signing identity.

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

Not "spam is filtered well" — **no unsolicited mail reaches the direct receiver's
inbox**, because the elevated path only carries mail from senders the recipient
already put in their contacts. Everything unsolicited either never attempts Direct
(no capability record) or hits the contact gate and is told to use SMTP. On the SMTP
path, the world's existing spam defenses apply exactly as they do today. We neither
weaken nor re-solve them.

**The one asterisk is storage, not the inbox, and only at the locked sealed tiers
(Private and Fortress).** There the
contact gate cannot run at receive — the sealed contact list is unreadable — so a
message with a valid instance signature is accepted and spooled before it can be
judged, then filed to spam or inbox at the next unlock (see §Security levels). Unsolicited
mail can therefore reach *storage* ahead of the gate at those tiers; it never reaches the
inbox, but it occupies space until unlock. At Fortress this is no worse than SMTP
arriving at the same locked box today — likewise spooled and filed at unlock — and it is bounded
twice: the endpoint rate-limits preflights per verified sending instance (see §Abuse),
and the Direct spool itself is capped in bytes per domain and per address (see
§Security levels), so what all senders together can park before a human sees it is
capped in bytes, not merely in messages — no number of cheap instances raises it. The
inbox guarantee is absolute; the storage cost is bounded and identical to email's.

Cheap instances and infinite domains — the obvious attack on any federation — buy
an attacker nothing here, because instance identity gates nothing valuable. The
inbox is gated by the recipient's contact list, which only the recipient's own
instance can write.

## The SMTP fallback boundary (the mail adapter's result mapping)

This is the mail kind's mapping of the Direct client's typed results — the contract
that keeps the feature invisible when it doesn't apply:

- `no_capability` (no SRV record; recipient not a Joinery instance at all) → SMTP.
- `declined` from the receiver (not a contact) → SMTP.
- `failed` (connection, timeout, or verification failure) → SMTP.

Direct Mail is strictly additive. A message that can't or shouldn't go direct goes
exactly where it goes today. The mapping lives only in the mail adapter — no other
kind's `declined` or `failed` ever produces an SMTP send.

## Build plan

1. **Capability record** — add the SRV + key TXT to the DNS plan; publish/verify
   through the existing driver flow, with the SRV target chosen per tier (DNS-only
   box host at Standard/Private, relay at Fortress). Read side: a resolver helper
   that answers "does this domain speak Direct, on what host/port/key?" — **caching
   positive and negative answers** by domain/key-id and **rate-limiting** lookups by
   connecting peer, so an attacker-named sender domain cannot drive unbounded outbound
   DNS (see *The receive decision*).
2. **Direct client (send)** — `JoineryDirect`, the kind-independent send service:
   capability resolution (via item 1's resolver helper), SSRF-guarded connect,
   preflight with timestamp + nonce, sealing to the returned key, signed sealed-byte
   hashes, part transfer, typed result. Accepts part descriptors as bytes or file
   paths/streams (*The send decision*). Plus the **mail adapter**: a new
   `EmailServiceProvider` wired into the `EmailSender` transport resolver that calls
   the client with `kind: mail` and maps every non-`delivered` result to the
   SMTP/relay path.
3. **Direct receiver** — a route serving `/.well-known/joinery-direct`: the shared
   framework (signature verification, freshness/replay, rate limits, manifest size
   bounds, kind dispatch from the `directKinds` registry — unknown or unserved
   kind → request-level refuse — key answer with decoy derivation at the sealed
   tiers, manifest admission, the single-use delivery-session store, sealed-byte
   hash verification, spool byte caps),
   then the kind handler's `gate`/`ingest`. Mail
   ships as the first registered handler: the canned contact gate plus per-part
   storage into `iem_inbound_email_messages` and `imc_inbound_message_attachment`.
   Built in the shape of `inbound_email_webhook.php`, not as a service. Relay
   store-and-forward for offline destinations. Rate limiting reuses the platform's
   existing limiters — `RequestLogger::check_rate_limit` (IP-keyed sliding window)
   for the per-peer check, and window counts over Direct's own request log (the
   mailbox forwarding limiter pattern) for the per-instance check; the spool caps
   are byte counters keyed by domain and address. Every limit and cap is a
   declared setting; no new rate-limiting engine. Ships with the typed envelope
   and part objects handlers receive, the canned contact gate
   (`"gate": "contacts"`), and the loopback send the test estate uses to exercise
   a kind without a second instance.
4. **Relay path (in v1, not deferred)** — relay-side termination and forwarding
   inbound, relay-originated connections outbound, instance signing key in relay
   custody. Three sub-items follow from how relays already work:
   - **Map additions.** The relay map already carries per-tenant secrets
     (`srs_secret`, the transport keypair) and already distinguishes `key_kind`
     **user** — a Fortress tenant, seal to that vault's public key — from
     **transport**, the relay's ambient key for everyone else. The preflight answer
     reuses that distinction verbatim rather than inventing a parallel one. What is
     new in the map is the domain secret backing decoy derivation, the tenant's
     served-kind list, and the tenant's rate-limit and spool-cap values — so
     relay-side kind refusal and edge throttling are data-driven, and a new kind
     never requires a relay code change.
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
   configure per-recipient. Plus the operator surface: request-level refusal and
   SMTP-downgrade counters from Direct's request log on the admin dashboard
   (§Abuse).

## Acceptance

1. A domain with no capability record receives mail via SMTP, unchanged.
2. Sender in the recipient's contacts, capability record present → message
   delivered over the direct channel, marked as such, never touches SMTP or a paid
   sender.
3. Sender **not** in the recipient's contacts (at Standard, where the gate runs
   live) → receiver returns `declined`, message delivered via SMTP, nothing stored
   on the direct path.
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
12. A blocked sender's Direct attempt receives the same `declined` answer as a
    stranger (no block oracle), and the SMTP fallback that follows is filed as spam.
13. No queryable endpoint or DNS record reveals whether a given sender is a contact
    of, or blocked by, a given recipient.
14. Under Private or Fortress with the vault locked, a Direct message is accepted
    and held sealed in the Direct spool (sender-sealed already, or sealed on
    arrival); the contact gate and verified-direct mark are applied at the next
    unlock, not at receive.
15. A sender cannot determine a recipient's lock state from Direct's behaviour —
    the receiver accepts identically whether locked or unlocked.
16. A Direct message rejected at unlock under Private or Fortress is filed locally (ordinary or
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
23. Under Private or Fortress, preflighting a nonexistent address returns an `accept` and a key
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
28. A preflight carrying a kind the receiver does not serve is refused cleanly;
    mail delivery on the same endpoint is unaffected.
29. A flood of preflights naming distinct or non-resolving sender domains does not
    produce one outbound DNS lookup per request: capability records are cached
    (positive and negative) and resolution is rate-limited by connecting peer.
30. A sealed part altered in transit — or re-sealed by a relay to different content —
    is rejected on receipt against its signed sealed-byte hash and never receives the
    verified-direct mark; at locked Fortress the rejection happens without unsealing.
31. Outbound Direct is signed on the origin box; the relay originates the connection
    but never holds the instance signing key, and a relay cannot alter the signed
    message it transports.
32. An SRV record aiming Direct at a loopback/internal/reserved address, or at a
    privileged port below 1024, is refused by the sender's SSRF guard and the message
    falls back to SMTP; the connection is pinned to the validated public IP and no
    redirect is ever followed.
33. The send pipe is one client for every kind: mail sends through the same
    `JoineryDirect` call the messenger uses, the SMTP fallback exists only in the
    mail adapter, and a non-mail kind's `declined` or `failed` result never produces
    an SMTP send.
34. A plugin that declares a kind in `plugin.json` (`directKinds`) receives
    deliveries through its `gate`/`ingest` handler with no core code changes, and
    deactivating the plugin makes preflights for that kind refuse exactly as an
    unknown kind does.
35. The wire discipline is framework-enforced for every registered kind: a handler
    expresses only `accept`/`decline` and storage, and no kind can produce a third
    wire answer, an answer that varies with lock state, or a bounce.
36. A non-mail kind accepted at a locked Fortress box is spooled sealed in the
    shared Direct spool and its `gate` and `ingest` run at the next unlock; a
    spooled delivery for a plugin deactivated before unlock is held until
    reactivation or expires with the spool's retention, and in no case is anything
    returned to the sender.
37. A new kind, core or plugin-provided, requires no relay version change: a
    Fortress tenant's relay learns it as a relay-map data update, and a preflight
    for a kind the tenant does not serve is refused at the relay edge without
    touching the origin box.
38. A preflight carrying an unimplemented protocol version is refused cleanly at
    request level, and the sender's behavior on that refusal is identical to any
    other failure (for mail, the SMTP fallback).
39. A preflight whose manifest exceeds the declared size caps — parts, bytes per
    part, or total bytes — is refused at request level at every tier, and a
    delivered part exceeding its declared size aborts the delivery.
40. The Direct spool is capped in bytes per domain and per address: past a cap,
    further deliveries are refused at request level; a flooded address refuses
    identically whether it is real or a decoy, so filling a spool is not an
    existence probe; and a cap refusal downgrades mail to the SMTP path, losing
    nothing.
41. A content transfer without a live matching delivery session — unknown nonce,
    expired session, or one already redeemed — is refused at request level; a
    captured transfer replayed after delivery re-delivers nothing.
42. Delivered parts are enforced against the session's admitted manifest — part
    count, declared sizes, and roles — and an unredeemed session expires
    discarding its partial parts, with the sender's retry arriving as a fresh
    preflight under a new nonce.
43. A plugin kind's `gate` and `ingest` are exercisable in the test estate through
    the loopback send — no second instance, DNS record, or network — and the same
    handler code runs unmodified on the real wire.
44. Request-level refusals and send-side SMTP downgrades appear as counters on the
    admin surface; a clock-drifted deployment whose Direct silently degrades to
    SMTP is diagnosable from that surface alone.

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
- **Scraping capability records** (an internet-wide `_joinery._tcp` harvest to
  find Direct-speaking domains). Reveals only what MX records have revealed for
  decades — that a domain receives messages — and gates nothing: no inbox access
  (contact gate), no address harvesting (a stranger's answer is identical for
  real and nonexistent addresses at every tier), and no better channel than the
  SMTP the spammer already has — Direct requires a signed, attributable,
  per-instance-rate-limited identity where SMTP requires nothing. Spam economics
  therefore stay on SMTP, whose exposure is unchanged by this feature.
  → *Why there is no spam problem in v1*, *Encryption* (decoys), *Abuse.*
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
- **Locked vault / sealed-tier receive** ("receiving only when unlocked?"). No —
  authentication runs while locked (stateless signature check), authorization
  defers to unlock via the existing deferred-ingest pipeline. → *Security levels.*
- **Return-to-sender on rejection.** A Fortress rejection is filed locally, never
  bounced — which is a feature: no enumeration feedback, no backscatter, and it
  closes the lock/contact/block oracles at once. → *Security levels.*
- **Membership hashfile** (locked-readable contact index). Rejected: a key usable
  while locked is a key a thief of the locked box holds, and low-entropy addresses
  make the set dictionary-guessable. Moot at Standard (contacts are plaintext);
  Private shares Fortress's receive path rather than adding one. → *Security levels.*
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
- **Key-bearing `accept` as an existence oracle.** Answering sealed-tier preflights with
  a real key would reveal which addresses exist, undoing the unconditional accept.
  Closed by returning a deterministic decoy key for unknown addresses. → *Encryption.*
- **Manifest hash as a possession oracle.** Using per-part hashes to *skip* content a
  recipient already holds would let a sender probe which files they possess by watching
  what gets skipped. Resolved by separating the two uses: the content transfer carries a
  signed per-part hash for *integrity* (binding the delivered sealed bytes), but the
  receiver always takes the full transfer and never skips a part, so nothing is
  observable per part. Resume uses byte offsets, not content-defined skipping.
  → *Message transfer.*
- **Preflight as a DNS-amplification lever.** Verifying a preflight needs the
  attacker-named sender domain's key from DNS, so unbounded per-request lookups would let
  an attacker drive outbound resolution before authentication. Bounded by caching the
  capability record (positive and negative) and rate-limiting resolution by connecting
  peer. → *The receive decision.*
- **Attacker-controlled SRV target (SSRF).** A hostile recipient domain could aim the
  sender at an internal host or a sensitive port. The sender validates the SRV target
  through the SSRF-safe client — private/reserved IPs blocked, IP-pinned, port limited to
  443 or ≥ 1024, TLS verified against the SRV hostname, no redirects — and falls back to
  SMTP on any failure. → *The send decision*, `safe_http_client.md`.
- **Contact entry satisfied by a spoofed From.** The gate matches address *and* a
  sending domain bound to the verified instance signature, so being in someone's
  contacts is not a name anyone can claim. → *The receive decision.*
- **Locked-readable blocklist** (early-drop index of blocked senders). Rejected: a
  blocklist names who you are trying to escape, so it is more dangerous on a stolen
  locked box than the contact graph, not less — and with size declared at preflight
  the early drop saves little. → *Security levels.*
- **Storage exhaustion at the sealed tiers** (park sealed blobs until unlock;
  Sybil past the per-instance limiter with cheap domains). Bounded in bytes, not
  in trust: manifest size caps at preflight, then per-domain and per-address spool
  caps refusing at request level — with decoy addresses accruing phantom bytes so
  a full spool refuses identically for real and nonexistent addresses. A cap
  refusal downgrades to the caller's fallback (mail → SMTP), so nothing is lost.
  → *Security levels*, *Abuse*, *The receive decision*.
- **Replaying the content transfer.** A captured second request — sealed parts plus
  transfer signature — re-presented later to re-deliver the message (F6 closes only
  preflight replay). Closed by the single-use delivery session: the transfer
  redeems the preflight nonce once, completion or terminal failure consumes it,
  expiry discards it, so a replay is a request-level refusal. Splicing a transfer
  onto a different preflight was already closed by binding the transfer signature
  to the nonce. → *The receive decision*, *Message transfer*.

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
