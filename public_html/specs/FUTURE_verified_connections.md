# FUTURE — Verified Connections (Identity & the Relationship Graph)

**Status: future — design record, not scheduled.** Captured so the reasoning doesn't
have to be re-derived when this is taken up. Nothing here is committed; the decisions
below are the starting position for the real spec.

## What this is

The network layer of the suite: a per-user graph of **verified connections** — people
you actually know, each pinned to their real cryptographic identity (their Sealed
Vault keypair) rather than to a string like an email address. It is the foot in the
door that grows from "contact list" to "verified friends" to cross-instance features:
E2E mail between Joinery nodes, shared Drive, joining a chat on someone else's
instance, and the guardian's impersonation defense.

## Why (strategic frame)

The identity thesis is Urbit's, with its four execution mistakes inverted:

1. Identity is minted **free and invisibly** as a side effect of using the product —
   every user already has a Sealed Vault keypair. Nothing is bought first.
2. The product is **complete solo** — mail, drive, calendar, passwords all work with
   zero connections, so there is no empty-room cold start. The network only adds.
3. Identities are free and infinite, never scarce or speculative.
4. Identity is invisible in the UI. The user sees "Mom ✓", never a key fingerprint
   unless they go looking.

The graph is the **real relationship graph**: edges are created by out-of-band
verification between people who already know each other (Signal safety-number model),
not by open discovery. Open discovery is a non-goal permanently — it is how PGP's
web of trust died and how spam networks bootstrap. Win the intimate network (the
~30 people who matter), not planetary scale.

**Convergence with the guardian:** the graph is the anti-scam immune system. A
scammer can spoof "Mom" the display string but not Mom the key. "Green check = math"
turns impersonation detection from heuristics into verification, and the guardian
knows exactly who is really family.

## The bright line: keys authenticate, users participate

This is the load-bearing architectural rule, settled up front because every FK in the
platform is a user id and that must stay true.

- **Anything that participates on an instance is a `usr_users` row — always.** Chat
  membership, drive grants, conversations, orders: all keyed on `usr_user_id`,
  unchanged. The connections table **never appears as a participant foreign key
  anywhere.**
- **The connections table is a credential/introduction mechanism**, exactly one
  level up from passkeys: a passkey credential row doesn't replace the user row, it
  authenticates you *into* one. Same shape here. When a remote person's node first
  touches your instance (accepting a chat invite, opening a share), their keypair
  authenticates and your instance **mints them a real local user row** — a federated
  guest, following the provisioning precedent of
  `specs/implemented/anonymous_browser_credential.md`. From that moment they are a
  user id like anyone else; every permission check and Multi-class filter works
  unchanged. The connection row records which keypair may claim that row, so
  returning visits land in the same identity.
- The confusion scenario (some features keyed on users, some on connections) can
  only arise if a connection can *act* without a user row. The rule forbids
  precisely that.

## Data model (starting position)

New **core** table — core, not plugin, because mail, Drive, chat, and the guardian
all consume the same graph (same reasoning that puts conversations/messages in core):

`data/verified_connections_class.php` → `VerifiedConnection` / table
`vcn_verified_connections`, roughly:

- `vcn_usr_user_id` — the local owner of the edge (FK, every edge belongs to a
  local user).
- `vcn_public_key` — the counterparty's identity key (the primary identity; the
  thing that travels between nodes).
- `vcn_label` — what the owner calls them ("Mom"). Display never shows key material.
- `vcn_endpoint` — where their node lives (domain), for outbound federation.
- `vcn_verified_state` + `vcn_verified_time` — `unverified` (imported/observed) vs
  `verified` (out-of-band ceremony completed). Two trust tiers: opportunistic
  (key discovered, protects against passive) vs verified (pinned out-of-band,
  protects against active MITM).
- `vcn_guest_usr_user_id` — **nullable** FK to the local user row this keypair
  provisioned/claimed on this instance. Null until they first act here; also filled
  immediately in the degenerate same-instance case (both people on one box), where
  the counterparty already has a local row. An account is just what a keypair looks
  like when it's standing on your own server.

## How the graph bootstraps: the contact funnel

`specs/mailbox_compose_maturity.md` Phase 4 builds `imc_mailbox_contacts` — a dumb,
plugin-local, disposable autocomplete **cache** of observed addresses. That is the
funnel: every address you correspond with is a candidate edge. Upgrading a contact to
a verified connection is a user action ("verify this person"), not a migration — the
cache stays a cache forever, and eventually carries one nullable FK to `vcn_` when a
connection exists. Identity is never retrofitted into the autocomplete table.

## The escalation ladder (what each verified edge unlocks)

One ceremony, compounding utility — all keyed off the same vault keypair:

1. **E2E mail** — outbound mail to a verified connection seals to their public key
   automatically. MVP transport is existing SMTP rails (see the E2E-over-SMTP
   sequence in the vision thread; `specs/mailbox_encrypted_interop.md` handles the
   *legacy* PGP world — this graph is the Joinery-native tier above it, and the two
   must share compose-time key-selection precedence: verified connection key beats
   discovered PGP key).
   Note: mail is the one direction that needs **no user row on the counterparty's
   box** — sealing outbound only needs the key from the connection row.
2. **Shared Drive** — a share to a verified connection is a cryptographic grant
   (drive_encryption's sharing model gains a remote-recipient case).
3. **Chat / presence on your instance** — "join my chat" = their keypair
   authenticates, a federated-guest user row is minted, and chat proceeds keyed on
   user ids per the bright line. (This is the Urbit-beating feature: peer identity
   walking into another node and just working.)
4. **Guardian integration** — inbound claiming to be a verified connection but not
   signed by their key is flagged as probable impersonation; verified senders get
   trusted treatment.

## Relationship to existing specs

- `specs/implemented/sealed_vault_core.md` — supplies the identity keypair. This
  spec adds no key material, only the graph over it.
- `specs/mailbox_encrypted_interop.md` — the legacy-world interop layer (PGP/WKD).
  Complementary, not overlapping: interop inherits an existing federation for
  strangers; this graph is verified-by-ceremony for people who matter.
- `specs/implemented/anonymous_browser_credential.md` — the provisioning precedent
  for minting a constrained local user row for an external credential.
- `specs/mailbox_compose_maturity.md` (Phase 4) — the contact cache this funnels
  from.
- `specs/DEFERRED_client_custody_mail.md` — orthogonal (key custody, not identity).
- `specs/chat_plugin.md` — lists federation as a non-goal; if that plugin is built
  first, the federated-guest path lands later without changing its user-id keying
  (the bright line is what makes that true).

## Open problems (unresolved, listed honestly)

- **Verification ceremony UX** — the out-of-band step (QR scan in person? short
  authentication string over a phone call?) is the make-or-break usability moment;
  Signal's numbers are mostly ignored by users. Needs real design.
- **Key rotation/loss on the counterparty side** — Mom loses her vault and re-keys;
  the edge must re-verify without silently accepting a swapped key (that silent
  acceptance is exactly the attack).
- **Federated-guest permission ceiling** — what a minted guest row may do (join the
  chat it was invited to; not browse members). Likely a permission level below
  member plus explicit per-resource grants.
- **Endpoint discovery and transport** — DNS record on the counterparty's domain
  first (users already run mail DNS); anything richer (DHT, NAT traversal) belongs
  to the native-protocol endgame, not here.
- **Multi-device / multi-key identities** — one keypair per person is the v1
  simplification; real people have phones and laptops.

## Non-goals (permanent, inherited from the strategy)

- Open discovery / a public directory of identities.
- Scarce, purchasable, or speculative identity.
- Key fingerprints as primary UI.
- Planetary-scale social graph; this is the intimate network.
