# Messenger: Reachability States and the Honest Picker

**Status:** BUILT 2026-08-15 (code + automated tests; the keep-it-broken ladder's
rungs 2–6 are live verification still to run — the spec stays active until the ladder
completes). Design decisions settled with owner 2026-08-15:
R1 resolves to the member; one unified search box (remote panel deleted); opening a chat
never creates a contact (a contact is an inbound-permission grant — deliberate adds only);
the 1:1-only rule blocks the impossible click immediately, both directions; a failed
check offers Check again with a fresh, cache-bypassing lookup.
**Date:** 2026-08-15
**Origin:** Live testing on jeremytunnell. The people picker's contact search shipped
(0.8.276) but the whole cross-site surface was silently invisible there, which read as a
caching bug and cost a full publish/test round-trip. This spec works out every state a
sender and a recipient can be in, what the member sees in each, and how we verify the
error surfaces by deliberately testing against broken states.

## Ground truth (current behavior, verified)

Two lanes share the messenger:

- **Local lane.** Conversations between members of the same site are fully internal —
  storage, protection levels, reactions, attachments. Joinery Direct is not involved and
  `joinery_direct_enabled` has no effect on it. This lane works today on every install.
- **Cross-site lane.** A conversation with an address on another Joinery instance rides
  Joinery Direct. It engages only when a conversation has a remote peer
  (`Conversation::is_federated()`).

The cross-site lane requires a chain, evaluated by `MessengerFederation`:

1. mailbox plugin active — the address book and the member's own address come from it
2. `joinery_direct_enabled = 1` (`DirectSettings::enabled()`)
3. the member holds a mailbox on a domain this deployment can sign as
   (`MessengerFederation::addressFor()` — needs a `jdi_direct_identities` row)
4. the recipient's domain publishes a Joinery key in DNS and answers a capability
   lookup advertising the `chat` kind

Today, a break anywhere in 1–3 makes the surface **silently disappear** (no remote
panel, no contact results). That silence is the bug this spec exists to fix: to the
person testing, "hidden by design" and "broken" are indistinguishable.

## The state model

### Sender-side states (why *you* can't send)

| # | State | Today | Specified |
|---|-------|-------|-----------|
| S1 | Mailbox plugin inactive | surface hidden, silent | hidden; picker footer explains to superadmins (see § Admin notice) |
| S2 | `joinery_direct_enabled = 0` | surface hidden, silent | same as S1 |
| S3 | Direct on, but no signing identity for any mail domain | surface hidden, silent | same as S1 |
| S4 | Site fully set up, but this member holds no mailbox on a signable domain | surface hidden, silent | picker shows contacts but a pick explains: "You need a Joinery email address on this site to chat across sites" with the mailbox link |
| S5 | Member's contacts are sealed and the vault window is closed | contacts absent, silent | unchanged — absence is the designed behavior for a closed window everywhere; no lock-state oracle |
| S6 | Identity minted but the domain's own DNS records not published | a send is signed with a key no recipient can verify — refused on the far end, after the wire | checked on OUR side before anything: a pick answers "This site's Joinery Direct DNS records are not published yet" (email offered; Check again re-resolves this half too), and `JoineryDirect::send()` refuses with NO_CAPABILITY before the wire, so mail falls back to email cleanly. Both halves of the handshake are verified before sending, not entrusted to operator ordering |

### Recipient-side states (why *they* can't receive)

| # | State | Today | Specified |
|---|-------|-------|-----------|
| R1 | Address belongs to a member of **this** site | contact treated as remote; member search never matches an email | resolve internally: open the plain local conversation (see § Local addresses) |
| R2 | Joinery site, key in DNS, `chat` capability advertised | reachable → chat | unchanged; result row labeled **chat** |
| R3 | Joinery site, key in DNS, no `chat` capability (older version, messenger off) | "cannot be reached by chat" + email offer | unchanged; row labeled **email only** |
| R4 | No Joinery key in DNS (not a Joinery site, or one that never published) | same as R3 | same as R3 — deliberately indistinguishable from R3 on the wire |
| R5 | Transient failure (DNS timeout, instance down) | reads as R3/R4 | same answer, plus a **Check again** link that forces a fresh lookup past the capability cache (lightly rate-limited) — the one honest concession to transience. Without the bypass a blip's "no" would be served from cache on every retry, making retries silently fake |
| R6 | Reachable for chat, but the conversation would be Private/Guarded | cross-site conversations are Standard-only; nothing says so | the thread and the picker say it: "Cross-site conversations are Standard" where the level picker would be |
| R7 | Local member who can't hold a Private/Guarded key yet (no protection set up) | refusal at create time | unchanged mechanically; the error must name the person and the reason, not a generic failure |

Design invariant kept throughout: every cross-site "no" reads the same on the wire
(refusal ≡ no capability ≡ too-old instance). Reachability reports **whether**, never
**why** — the recipient's choices don't leak. R5's "Check again" retries the lookup; it
does not distinguish the states.

## The unified picker

One search box resolves everything. No separate "Someone on another Joinery site"
panel — it exists today only because members and contacts were separate worlds.

- Results are members (name match) and the member's own contacts (name or address
  match), visually distinct: members show avatar + name; contacts show name + address.
- **Picking a contact resolves its state inline** on the result/chip: a reachability
  check runs and the chip resolves to **chat** (proceed), **email only** (offer the
  compose link, don't add the chip), or the S4 message. No navigation away, no second
  panel, no separate Check button.
- **Enter never closes the dialog.** Enter in the search box selects the first result;
  with no results it does nothing. (Today the dialog is a `<form method="dialog">`, so
  Enter submits and closes — this is a bug in every state.)
- A typed full address that matches nothing in contacts is still usable: the search box
  accepts it as a candidate row ("Use address …") that resolves the same way. Opening a
  chat this way creates **no contact row**: a contact is a permission — it lets that
  address send to you directly — and initiating outbound must never grant the reverse
  direction. Contacts are created only by a deliberate add.
- Mixed picks: a selection containing a remote contact can only be a 1:1 (remote group
  chat is not a thing), and the impossible click is blocked **immediately**, never at
  Start. With a remote chip picked, the search box disables with the reason
  ("Cross-site chats are one-to-one"). With any chip already picked, a remote result
  greys out with the same line, naming what to remove. Removing chips re-enables.

### Local addresses (R1)

An address whose domain is one of this site's own mail domains resolves internally: if
it maps to a mailbox grant held by a member, the pick opens a plain local conversation
with that member (their display name shown — the same reveal the mailbox itself makes
when mail arrives from them). If it maps to no member, it behaves as R3 (email only).

Note the bounded oracle: confirming that an exact typed address belongs to a member
reveals membership to someone who already knows the address. This matches what sending
an email to that address would reveal anyway; partial matches never search member
emails, so no enumeration is possible.

## Admin notice (S1–S3, S6)

Silence was the real failure in testing. A superadmin (permission 10 — who can reach
the switch in General Settings' superadmin section and the Setup tab) sees the next
unfinished step in the picker footer:

- **S1–S2** (no mailbox, or the switch off): "Cross-site chat isn't set up on this
  site. [Set up Joinery Direct]" → General Settings.
- **S3 and S6** (switch on, DNS half not done): "Joinery Direct is on, but this
  site's DNS records aren't published yet. [Publish them on the Setup tab]" → the
  Mailbox admin Setup tab, whose per-domain record plan already carries the
  `_joinery` rows and mints the signing identity while planning — S3's missing
  identity is an implementation detail with the same fix as S6, so the notice
  does not distinguish them. The publication check reuses the send path's
  capability cache: a DNS query only when the cache has expired, superadmins only.

The same seam is stitched at the switch itself: a save that turns
`joinery_direct_enabled` on confirms with "one step left — publish the records from
the Mailbox Setup tab" (or "activate the mailbox plugin first" when it is inactive).

Regular members see nothing — for them the local lane is simply all there is,
which is honest.

A chat-only deployment is supported by construction, not by a separate posture:
the mailbox plugin is the identity layer (address, contacts, endpoint), and mail
*delivery* is the optional half — the Setup tab's per-record confirmation lets an
operator publish only the `_joinery` rows and skip MX/SPF/DKIM.

## Keep-it-broken test plan

jeremytunnell (and dev) stay broken on purpose. Each rung of the chain gets verified
in its broken state **before** the next rung is repaired, so every error surface is
seen for real at least once:

1. **S2 now** (Direct off, no identity): picker shows local members; admin sees the
   notice; no contact rows; Enter selects instead of closing.
2. **Enable `joinery_direct_enabled`, still no identity (S3):** identical member-facing
   behavior; admin notice remains.
3. **Mint a signing identity, no DNS key published yet (S6):** contacts appear; picking
   one answers with OUR unpublished state — "This site's Joinery Direct DNS records are
   not published yet" — before any recipient lookup, with the email offer. Sender S4
   checked here with a member holding no signable mailbox.
4. **Publish DNS for jeremytunnell, dev still without capability (R3/R4):** picking
   `test@dev.getjoinery.com` resolves **email only** with the compose link working.
5. **Set dev up fully (R2):** the first real two-instance chat. Send, receive, react,
   delete; then take dev's messenger down again and verify R5's Check-again path and
   the outbox drain (`DrainChatOutbox`) retrying a mid-conversation outage.
6. **R6/R7:** open a remote thread and confirm the Standard-only line; locally, start a
   Private conversation with a member who has no protection set up and confirm the
   refusal names them.

Automated coverage mirrors the ladder: `messenger_federation_test` gains a section per
sender-side state (S2–S4 are all constructible in one run by toggling settings and
identities within the test, with restore), and the picker's Enter/inline-resolve
behavior gets a browser check on dev.

## Out of scope

- Remote group conversations.
- Private/Guarded across instances (Standard-only stands; R6 only makes it visible).
- Any relaxation of the indistinguishable-"no" invariant.
- Auto-provisioning Direct identities/DNS (covered by the managed-domain work).

## Documentation

`plugins/messenger/docs/overview.md` already states the local/cross-site split and the
availability chain correctly for today's behavior. When the unified picker lands, its
section on the compose surface is rewritten to describe result labeling, inline
resolution, and the admin notice — as current state, per docs rules.
