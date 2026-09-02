# Joinery Direct

Two people whose accounts live on Joinery instances can exchange messages without a
third party in the middle. Joinery Direct is the channel that makes that work: a
signed, sealed, consent-gated HTTPS exchange between two Joinery instances. Mail is
its founding payload; any plugin can put its own payload on it. This document is the
platform-level guide to the channel and the developer surface for putting a new
payload on it.

The channel is off until an operator turns it on (`joinery_direct_enabled`). While
it is off the endpoint answers a plain not-found and nothing is published, so a
deployment that has not enabled it is indistinguishable from one that never heard
of the channel.

## The channel in one pass

A delivery is a short sequence of HTTPS requests from the sending instance to the
receiving instance's advertised endpoint (`/.well-known/joinery-direct`):

1. **Preflight** (`?step=preflight`) — the envelope (sender, recipient, kind,
   protocol version, signed timestamp + nonce) and a manifest declaring each
   part's size, type, and role. No content. The receiver answers `declined` or
   `accept`; an `accept` may carry a vault public key and key generation to seal
   to, and opens a single-use delivery session.
2. **Parts** (`?step=part&nonce=…&index=N`) — each part (body, HTML, each
   attachment) as its own request, raw bytes, sealed to the returned key when one
   was offered. One request per part is what keeps a large message from ever being
   bounded by `post_max_size`, a request timeout, or the memory needed to hold a
   whole message: the ceiling is the largest single part.
3. **Commit** (`?step=commit`) — the ordered per-part hashes of the *sealed* bytes
   and a signature over them, bound to the preflight nonce. The commit redeems the
   session once; parts are enforced against the admitted manifest, every hash is
   verified, and a replayed, late, or repeated commit is refused.

An unredeemed session expires after a declared TTL (`joinery_direct_session_ttl`,
default 15 minutes), discarding any partial parts. A retry is a fresh preflight
with its own nonce.

Both signed steps carry an Ed25519 **instance signature** verified against the
sender domain's DNS capability record — no valid signature, no acceptance.

**The pipe addresses people, not machines.** Every delivery's recipient is a user
address (`user@domain`); consent, key discovery, and deferred authorization are all
per-user constructs, and sealing is always to the recipient user's vault key.
Machine-to-machine traffic (fleet coordination, server-manager operations) belongs
on the machine channels (`FleetClient`), not here.

## Discovery: the capability record

A domain advertises Direct in DNS, published through the DNS record management
driver flow:

- **`SRV`** `_joinery._tcp.<domain>` → host and port of the receiving endpoint
  (published as `0 5 443 <host>`). The target is the same host the domain's MX
  names: the mail host on a colocated deployment — a DNS-only name for the box,
  never a CDN- or proxy-fronted web host — and the relay on a relay-fronted one,
  whose address the relay exists to conceal.
- **`TXT`** `_joinery-key.<domain>` → `v=joinery1; k=<key id>; p=<base64 Ed25519>`,
  one record per publishable key so a rotation can stage a second key while senders
  may still be quoting the first.

No SRV record means the domain does not speak Direct, and senders fall back
silently. Publishing the records is the *last* step of enabling Direct for a
domain; they only enter the plan once the channel is on and the domain holds a
signing identity, which is minted the first time its plan is built.

A signing identity exists only for a domain the deployment is **authoritative**
for. The mint itself refuses anything else — in particular an IMAP-source
domain (the anchor row a connected Gmail/Outlook account creates), whose DNS
nobody here could ever publish records under. The authority answer comes from a
resolver the mailbox plugin registers (`DirectSigningIdentity::
registerAuthorityResolver`, backed by `InboundEmailDomain::is_authoritative()`),
so core never names a plugin symbol.

Every DNS driver publishes SRV. Vendors model it three different ways and each
driver translates: some take the whole RDATA verbatim, some split the priority
out and carry `weight port target` as content (the shape they already use for
MX), and some decompose all four fields — with GoDaddy and Namecheap additionally
moving the `_joinery` and `_tcp` labels out of the record NAME and into fields of
their own. `tests/dns/dns_srv_drivers_test.php` pins every one of those mappings
in both directions, because a wrong field mapping does not throw: it writes a
record that looks published and resolves to nothing.

The capability provider hook (`DnsProvider::supportsType()`) remains for a future
vendor whose mapping cannot be verified. A driver that answers false there has
its record reported as **add by hand**, with the value ready to copy, while
everything else in the plan still publishes with the button — because a record
written into the wrong fields is worse than one the operator is told to add.

## Sending: the Direct client

All sending goes through one call, for every kind:

```php
$result = JoineryDirect::send($recipient_address, $kind, $parts, $options);
```

The client owns the whole shared layer — capability lookup (cached, positive and
negative), the SSRF-guarded connection, preflight, sealing, and transfer — and
returns a **typed result**, never a behavior:

| Result | Meaning |
|---|---|
| `delivered` | Accepted and transferred; the result records whether parts were sealed and to which key generation |
| `declined` | The receiver answered `declined` — this recipient does not accept this kind from this sender |
| `no_capability` | A missing precondition on either half of the handshake: the recipient domain publishes no capability record, this deployment holds no signing identity for the sender, or the sender domain's own DNS records are not published — checked before the wire, since the recipient verifies our signature against the key our domain publishes |
| `no_sealing` | The caller passed `require_sealed` and the preflight returned no recipient key — refused between preflight and transfer, so no content byte crossed the wire |
| `failed` | Connection, timeout, or verification failure at any step |

**`require_sealed`** (option): the client's default is opportunistic sealing —
parts cross plaintext-over-TLS when the far side publishes no key. A caller whose
policy forbids that trade (a Guarded conversation) passes
`'require_sealed' => true` and gets `no_sealing` instead of a transfer. The
refusal is final for as long as the far side has no vault: retrying asks the same
instance the same question.

**What a result means belongs to the calling kind, not the client.** Mail's
transport adapter (`DirectMailTransport`, registered into `EmailSender`) maps
everything short of `delivered` to the ordinary provider path. The SMTP fallback
exists only in that adapter — no other kind's failure ever produces an SMTP send.

Three client rules worth knowing:

- **The recipient key is never cached.** It arrives in each preflight `accept` and
  is used for that delivery only. A cached key can straddle a vault rotation and
  seal a message nobody can ever open — worse than not sealing.
- **The SRV target is hostile input.** The client resolves it through
  `SafeHttpClient`: private/reserved/loopback addresses blocked, connection pinned
  to a validated public IP, port restricted to 443 or ≥ 1024, TLS verified against
  the SRV hostname, redirects never followed. Any failure is simply `failed`.
- **Parts are descriptors, not strings.** Each part names its role, content type,
  and (for attachments) filename, with content as bytes or a file path — peak
  memory scales with the largest single part (sealing is one-shot), which the
  per-part size cap bounds. A payload past this instance's own caps is refused
  locally rather than costing the recipient a preflight.

## Receiving: the framework and the wire discipline

The endpoint is a route (`ajax/joinery_direct.php`, built in the shape of the
inbound email webhook, not a service). For every kind, identically, the framework
runs:

1. Protocol version, then instance signature verification against the sender
   domain's capability record — resolved through a cached, negative-cached,
   peer-rate-limited lookup.
2. A per-instance rate limit on the identity the signature established.
3. Freshness and replay: the signed timestamp must be within −5/+1 minutes, the
   nonce unseen (10-minute replay cache holding only opaque nonces, so it works
   while a vault is locked).
4. Manifest bounds: the declared parts and sizes are checked against declared caps
   (max parts, bytes per part, total bytes) — exceeding any is a request-level
   refusal, identical for every recipient and kind.
5. Kind dispatch from the registry; a kind this instance does not serve — or an
   unimplemented protocol version — is refused at request level.
6. Recipient resolution. A domain this deployment does not host is a request-level
   refusal, because it is a fact about the deployment rather than about a
   recipient. `exists` is an identity fact — is there an addressable recipient
   behind this local part — never one kind's routing preference.
7. The kind's declared recipient requirement, then its authorization gate — at
   Standard only (see **Security tiers**), both folded into one `declined`: a
   stranger, a nonexistent address, and a recipient this kind cannot land on
   (mail to a forwarding alias, chat to a shared mailbox) are indistinguishable.
   At the sealed tiers both defer to the same local disposition moment.
8. On accept: the key answer and a single-use delivery session holding the admitted
   manifest; parts arrive one request each and are enforced against it; the commit
   redeems the session once, verifies every sealed-byte hash, and then either
   ingests (Standard) or leaves the delivery held for the recipient's unlock.

The wire discipline holds for every kind because handlers cannot touch the wire:
exactly two gate answers exist (`accept`/`declined`), request-level refusals are a
separate indistinguishable bucket carried as HTTP statuses, a Private or Fortress
receiver accepts unconditionally (with a decoy key for addresses that do not
exist), nothing is ever bounced, and rate limiting is per verified sending
instance (a declared, tunable setting).

## Serving a kind: the plugin surface

A plugin puts a payload on the pipe by declaring it in `plugin.json`:

```json
"directKinds": {
  "chat": { "handler": "includes/ChatDirectHandler.php", "gate": "contacts", "recipient": "owner" }
}
```

The string shorthand `"chat": "includes/ChatDirectHandler.php"` is equivalent and
means the handler supplies its own `gate`; `class` names the handler class when it
differs from the filename.

**`recipient` declares who the kind can land on** — a requirement over the facts
the address resolver reports, judged by the framework at every gate site so no
handler re-implements it:

- absent — any existing recipient.
- `"owner"` — a single consenting user must resolve. Chat declares this: a
  message needs a person whose conversation list it lands in, so a shared
  mailbox declines while a forwarding alias with one grantee chats fine —
  forwarding is an email routing choice, not an identity fact.
- `"email_store"` — email delivered here must land in a local store and only a
  local store. Mail declares this: a Direct payload never becomes a MIME
  document, so a forwarding leg cannot run; the decline sends the message back
  to SMTP, which runs both legs. An unknown requirement word makes the
  declaration unusable (the kind refuses as unserved) rather than silently
  meaning "anyone".

A failed requirement is never a third wire answer: at Standard it folds into the
same `declined` a stranger gets; at the sealed tiers it defers with the gate and
becomes a local disposition (`gate_accepted` false into ingest — mail files the
message through ordinary classification, chat discards it). Core kinds are declared the same way in
`direct_kinds.json` at the `public_html/` root. Mail is declared by the **mailbox
plugin**, which is what makes deactivating that plugin remove the kind from the
served set. The registry is plain instance configuration, readable without loading
handler code: a kind that is not served refuses exactly like an unknown one.

A handler is two pure functions — that is the entire surface:

- **`gate(envelope): bool`** — "does this recipient accept this kind from this
  sender," nothing else. It never sees vault lock state and never composes a wire
  response. Under Private and Fortress it is not called at receive at all; the
  framework accepts unconditionally and defers the gate to unlock.
- **`ingest(envelope, parts, gate_accepted)`** — store the delivered payload in the
  kind's own model. It runs only after hash verification. On the live path it runs
  only on accept. On the deferred path it runs at unlock for every spooled
  delivery, carrying the deferred gate's outcome: because the sender was already
  answered `accept`, a deferred decline is a local disposition, not a drop — mail
  files such a message where the ordinary path would have (ordinary/spam, no
  verified mark).

An ingest that cannot store **yet** — its destination is sealed and nobody
present can open it (chat arriving into a conversation the local member raised to
Private, say) — throws `DirectDeferIngest`. The framework holds the delivery
(state HELD, parts intact) and re-runs ingest at the recipient's next unlock; the
wire answer is unchanged, so lock state never leaks. This is for "not now", never
"not ever": a genuinely unstorable payload is the handler's to log and drop,
because a held delivery is retried at every unlock until the retention sweep
reclaims it.

The envelope hands ingest the verified-sender fact and transport tag, so a kind can
drive its own UI the way mail's verified-direct mark does — applied by the
receiver, never reproducible from message content. On the deferred path the
envelope also carries the recipient's in-window vault secret, which is the only
way a sealed part is ever opened.

**Authorization is per-kind.** The contact gate — full sender address plus a
sending domain bound to the verified instance signature, matched against
`imc_mailbox_contacts` — is exported as a canned gate, declared as
`"gate": "contacts"` in the `directKinds` entry; the framework then runs it and
never calls a handler `gate`, so such a handler implements only `ingest`. Mail uses
it; a new kind declares it or supplies its own `gate`. Handlers receive typed
envelope and part objects — named accessors for sender, recipient, manifest, roles,
and content — never raw wire payloads.

**Anything a kind needs to say travels in its parts**, never in new envelope
fields; that is what keeps the envelope kind-independent. Mail's own metadata
(subject, From display name, Message-ID, threading headers) rides as a part typed
`message/rfc822-headers`.

A loopback send lets the test estate exercise a handler's `gate`/`ingest` on one
instance with no DNS or network: `JoineryDirect::send` with `loopback` set runs the
full receive framework locally. It is a test-tier tool, not a delivery path — real
same-instance mail never needs Direct.

## Security tiers

Contacts seal under the same rule as the mail beside them (vault present **and**
the domain seals content), so the tiers behave as:

- **Standard** — contacts are plaintext; the gate runs live at receive; a
  non-contact gets `declined` on the wire. **No key is offered**, so parts cross
  under TLS unsealed and ingest runs live — which is what the mailbox stores
  anyway, and what lets ingest happen without an open unlock window. A stranger, a
  removed contact, a blocked sender and an address that does not exist all get one
  byte-identical `declined`.
- **Private / Fortress** — contacts are sealed, and both tiers share one wire
  posture, locked or unlocked: the receiver accepts unconditionally — never a live
  `declined`, no lock-state oracle — and returns a key for every address that
  exists or not, with a deterministic decoy (reporting key generation 1) standing
  in for addresses that do not, so existence, contact membership, and block status
  are unknowable from the wire. Authentication (signature verification, hash
  checks) runs at receive; authorization defers: the framework spools the accepted
  delivery in the **Direct spool** — keyed by kind, holding the envelope,
  verified-sender fact, and sealed parts, nothing needing the vault — and drains it
  at the next unlock, running each delivery's deferred `gate` then `ingest`. A
  spooled delivery for a deactivated plugin is held sealed until reactivation or
  expires quietly with the spool's retention; nothing is ever returned to the
  sender. Held parts are sealed straight to the recipient's vault keypair, so
  the spool is a `reseals: true` vault consumer: on a key rotation
  `DirectSpoolDrain::resealForUser()` re-seals every held delivery's sealed
  parts to the new keypair (each delivery atomically, alongside its
  `jdp_key_generation`), and a part that cannot be re-sealed refuses the
  ceremony. A delivery still **staging** rides out the rotation untouched — its
  sender is mid-transfer sealing to the key it discovered, and an undrainable
  staging row is an abandoned transfer the retention sweep reclaims. The gate decides only elevation, never placement: for mail, a deferred
  decline hands the message to the same classification ordinary mail gets — content
  spam scan and filter rules included. The decoy's domain secret is minted on first
  use and kept, because a key that changed between probes of one address would
  itself be the tell. At Fortress the relay answers preflights, so that same
  secret travels to it in the relay map — a decoy that differed between the box
  and its relay would be a distinguisher in itself.

Sealing is **opportunistic**, and one case follows from that: a real recipient at a
sealed tier who holds no vault is offered no key, so the delivery crosses unsealed
and is still spooled and gated. The absence of a key downgrades content protection,
never delivery — handing back a decoy there would conceal one more bit and
permanently destroy a real person's mail. A prober who reaches that case can
distinguish a vaultless real address from a nonexistent one; it is a degenerate
configuration (a domain that seals content stores its own mail in plaintext when
its owner holds no vault) and closing it would cost delivery.

## Key custody

Outbound Direct is signed by the **box**, with custody mirroring DKIM's:

- **Box custody** — the domain's Ed25519 secret key is held under `SecretBox` and
  unwrapped per send. This is what an ordinary deployment uses, so it signs without
  anyone being logged in.
- **Vault custody** — a domain that seals content and names an owner keeps its
  signing key sealed to that owner's vault public key and unwraps it in-window, per
  send. A locked box then cannot sign in anyone's name; the send falls back
  instead. A vault key rotation re-seals it alongside the message DEKs and the
  protected-domain DKIM keys.

Rotation is explicit (`DirectSigningIdentity::rotate`): a new key id is minted and
the old row stays publishable until it is retired, because a sender that cached the
capability record may still be quoting the old id.

## The relay at Fortress

A Fortress deployment's SRV record targets its **relay**, in both directions. An
SRV record pointing at the origin box would advertise in public DNS exactly the
address the relay exists to conceal, so the target is the relay — the same
posture MX already takes.

The relay serves the channel from the same Go binary that seals its mail, in a
third mode (`relay-sealer direct-serve`), installed as the `joinery-direct`
service by `provision_relay.sh` from **relay version 2.5**:

- **Inbound, `:443`** — the public endpoint. TLS is terminated in-process with an
  ACME certificate obtained over TLS-ALPN-01 on that same port; there is no web
  server and no certbot on the machine, because the relay's smallness is the
  security property. A verified delivery is written to the tenant's spool as a
  `.direct` container beside the `.seal` blobs mail already uses, and travels the
  WireGuard pull that already exists — no new transport and no new credential.
- **Outbound, tunnel-only** — an egress listener on the WireGuard address. The
  box builds and signs a complete request and the relay makes it, so the
  recipient sees the relay's address and never the box's.

The split is **relay transports, box authenticates and authorizes**. Freshness,
replay, manifest bounds, rate limits and spool caps let the relay refuse obvious
junk at the edge, but its verdict is not load-bearing: because the relay is
untrusted with content, the sender's own preflight and transfer signatures travel
inside the `.direct` container, and the box **re-verifies them against the sender
domain's DNS-published key before it stores anything** — deriving the verified
sender from the signed envelope, never from the relay's assertion, and re-checking
each part's bytes against the signed hashes. So a forged sender never reaches the
contact gate even if the relay is compromised. The contact gate itself needs the
sealed contact list, so it runs on the box at the recipient's next unlock: a relayed
delivery lands in the *same* Direct spool a locally accepted one does, and the
ordinary unlock drain gates and ingests it. One deferred path, not two — which is
what keeps the no-bounce, held-plugin and decline-is-a-local-disposition rules in
one place.

**The relay never signs, and the box never trusts it to.** The instance signing key
stays on the box, exactly as DKIM's does; the relay transports an app-signed request
it cannot alter, and holds no key with which to forge a sender or reproduce a
tampered part's signature. With sender sealing it also forwards ciphertext it cannot
read, which makes it a pure address-hiding forwarder rather than a component that
must be trusted with content — the worst a compromised relay can do is drop or delay
a delivery.

The relay is **kind-agnostic in code**. The tenant's served-kind list, decoy
secret, rate limits and spool caps all travel in the relay map as data, and the
relay compares opaque kind strings — so a new kind, core or plugin, reaches the
fleet as a map update, never a relay release or fleet upgrade. `RELAY_VERSION`
moves only when the shared layer itself changes.

**The interop is pinned.** A signature is only worth anything if both ends agree
byte for byte on what was covered, and a drift between `DirectProtocol.php` and
the relay's `direct_protocol.go` would not throw anywhere — every delivery would
simply fail verification, which a sender reads as "unreachable" and downgrades to
the fallback. `plugins/mailbox/tests/direct_wire_gate.sh` has PHP emit the signing
bytes and Go emit them for the same deliberately awkward fixture and diffs the
two, on every `safe` run.

A tenant whose relay is behind simply has no capability record published yet, so
senders fall back and nothing breaks. Publishing the SRV record is the last step
of enabling Direct for a domain, never the first.

## Blocking and abuse

- **Remove from contacts** — a neutral downgrade; the sender's next attempt gets
  `declined` (for mail, that means the ordinary provider path).
- **Block** — remove the contact plus a sender-matched `mark_spam` inbound filter
  rule. There is no separate block store and no gate-time block lookup: a blocked
  sender is a non-contact on the wire, indistinguishable from any stranger, and the
  fallback that follows is filed as spam.
- The endpoint rate-limits preflights per verified sending instance (sliding
  window, `joinery_direct_preflight_limit` per
  `joinery_direct_preflight_window`, default 120 per rolling 2 minutes), and
  capability lookups are cached and rate-limited by connecting peer so
  attacker-named sender domains cannot drive unbounded outbound DNS. Both reuse the
  platform's existing limiters — `RequestLogger::check_rate_limit` for the per-peer
  check, window counts over Direct's own request log for the per-instance check —
  not a new engine.
- Storage is bounded in bytes, not just counts: manifest size caps at preflight
  (`joinery_direct_max_parts`, `joinery_direct_max_part_bytes`,
  `joinery_direct_max_total_bytes`), and per-domain plus per-address byte caps on
  the Direct spool at the sealed tiers
  (`joinery_direct_spool_domain_cap_bytes`,
  `joinery_direct_spool_address_cap_bytes`), refused at request level. Decoy
  addresses accrue phantom bytes, so a full spool refuses identically for real and
  nonexistent addresses; a cap refusal downgrades mail to the provider path, losing
  nothing.
- Nothing is silent to the operator: request-level refusals and send-side
  downgrades are counted in Direct's request log and surfaced on the mailbox admin
  **Logs** tab, so a clock-drifted box that quietly loses Direct is diagnosable.
  That panel names the diagnosis, not the symptom — "every outbound attempt fell
  back" is what an unpublished record or a drifted clock looks like from there.

## Mail: what the channel adds

Mail's own delivery, storage and classification are unchanged; Direct adds a lane
and a mark.

- The transport adapter attempts Direct per recipient. Recipients it delivered are
  dropped from the message, so the ordinary send that follows is never a duplicate
  for them. A message carrying Cc or Bcc stays whole on the ordinary path — Direct
  addresses one person at a time, and splitting a message across two paths with
  different header sets is not something the channel can express.
- A delivered message is stored from its parts directly into
  `iem_inbound_email_messages` and `imc_inbound_message_attachment` — no MIME
  document is assembled and taken apart again, so attachments land as individual
  rows, listable and previewable, without ever having been readable in transit.
  Attachments transfer as bytes, with none of MIME's base64 inflation.
- `iem_transport` records how the message arrived and `iem_direct_verified` whether
  it earned the mark. The mark asserts exactly two things — the sending instance
  was cryptographically verified, and the sender is in *this* recipient's contacts.
  It never appears on the fallback path and nothing in message content can
  reproduce it.
- Three UI surfaces: a compose-time indicator under the To field (which states only
  that a recipient's domain *can* take a direct delivery — whether that person
  accepts one is theirs to answer live, and is deliberately not queryable — and
  only when the From mailbox's own domain holds a signing identity: a connected
  account's address, or a hosted domain not yet published, sends as ordinary
  email whatever the recipient supports, and the indicator stays silent), a small
  mark beside the sender in the conversation list, and a hairline accent plus one
  plain-language line on the message itself.

## Where the pieces live

- Sending: `includes/joinery_direct/JoineryDirect.php`, the mail adapter
  `plugins/mailbox/includes/DirectMailTransport.php` registered into `EmailSender`.
- Receiving: the `/.well-known/joinery-direct` route (`ajax/joinery_direct.php`),
  `includes/joinery_direct/DirectReceiver.php`, and the kind registry from
  `direct_kinds.json` plus `directKinds` declarations.
- Consent: `imc_mailbox_contacts` (see the [Mailbox plugin
  overview](../plugins/mailbox/docs/overview.md)).
- Storage for the mail kind: `iem_inbound_email_messages` and
  `imc_inbound_message_attachment`, tagged with the delivering transport.
- The spool, sessions, replay cache, capability cache and signing identities:
  `jdp_direct_spool` / `jda_direct_spool_parts`, `jds_direct_sessions`,
  `jdn_direct_nonces`, `jdc_direct_capability_cache`, `jdi_direct_identities`.
- Capability records: the DNS record management plan/driver flow (see [DNS
  Management](dns_management.md)).
- The safe outbound path every SRV target is reached through:
  `includes/SafeHttpClient.php`.
- The relay's side: `plugins/mailbox/provisioning/relay-sealer/direct_*.go`
  (endpoint, capability lookup, state, crypto, spool artifact, egress), installed
  as the `joinery-direct` service by `provision_relay.sh`.
- The box's side of the relay path: `DirectRelayEgress` (outbound, registered
  into `JoineryDirect`), `DirectRelayIngest` (inbound, called by
  `RelaySpoolConsumer` for `.direct` entries), and the Direct block of the
  fragment `RelayMapExporter` builds.
