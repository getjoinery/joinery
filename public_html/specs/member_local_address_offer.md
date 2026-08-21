# Spec: Offer a local address to a member who has none

**Status:** Draft (awaiting implementation)
**Version:** 1.0
**Area:** `plugins/messenger` (the S4 dead-end becomes an offer), `plugins/mailbox` (member-initiated alias mint), one plugin setting
**Related:** `specs/imap_source_domain_boundaries.md` (§ 9.1 raised the self-service question this partially answers), `specs/messenger_reachability_states.md` (S4), `docs/joinery_direct.md` (identity is domain-anchored)

---

## 1. What this does, in plain terms

A member whose only mail setup is a connected external account (Gmail over IMAP) can read and send their mail here, but they cannot chat across Joinery instances: cross-site chat is signed under a domain this deployment is authoritative for, and gmail.com can never be that. Today the messenger tells them exactly what is missing — "You need a Joinery email address on this site to chat across sites" — and stops.

This spec turns that dead end into an offer. The site already owns a domain it *is* authoritative for; the member is already a trusted, signed-in account holder. So: **let them mint themselves an address on the site's domain, right where the need appears.** One click and a name choice later, they have `pat@example.org`, and cross-site chat works with the full domain-anchored guarantee — no protocol change, no attestation of foreign mailboxes, nothing given up (the analysis of why proving Gmail-mailbox ownership to remote receivers is not worth building lives in the parent spec's history).

The minted thing is deliberately **a real mailbox, not a chat-only token**. It is an ordinary store-mode alias with a grant — it appears in their reader, can receive Direct mail, and behaves like any address an admin would have created for them. A second identity class ("chat address") would be a new concept to explain, gate, and migrate later; an ordinary mailbox is one concept the platform already has.

## 2. Preconditions — when the offer can exist

The offer appears only when all of these hold:

1. The deployment has a **mint domain** (§ 4): an enabled, authoritative mail domain designated for member addresses.
2. The viewer is a signed-in member with **no existing grant on any authoritative-domain alias** (someone who already has a local address needs nothing).
3. The operator has not turned the feature off (§ 5).

When 2–3 hold but 1 fails (an IMAP-only *deployment* — no authoritative domain at all), there is nothing to mint on: the S4 message stands as it is today, and nothing pretends otherwise.

## 3. The flow

- **Surface:** the messenger's S4 state. Instead of the bare refusal, the panel offers: an explanation line ("Chatting with other sites needs an address on this site"), a local-part input pre-filled with a suggestion derived from the member's name (lowercased, regex-safe, collision-suffixed), the fixed `@<mint domain>` label, and one button.
- **Action:** a new `/api/v1` action (per the API endpoint rules — browser-session credential, CSRF header). It validates and mints in one step: create the store-mode `InboundEmailAlias` on the mint domain plus the single `InboundEmailMailboxGrant` for the requesting member, exactly the rows an admin-created mailbox gets.
- **After:** the messenger re-resolves (`MessengerFederation::addressFor()` now finds a signable alias) and the member proceeds straight into the chat they were trying to start. The new mailbox appears in their reader rail like any other.
- The action is also linkable from the profile mailbox empty state ("No mailboxes have been shared with you") — same precondition checks, same endpoint.

## 4. The mint domain

Zero-config where possible, explicit where not:

- **Exactly one authoritative enabled domain** → it is the mint domain. Nothing to configure — the common single-domain site works out of the box.
- **Several** → the operator designates one with the plugin setting `mailbox_member_address_domain` (declared in `plugin.json`, default empty). While unset, the offer stays hidden and the mailbox Setup tab shows a one-line hint that member addresses need a designated domain.
- **None** → no offer (§ 2).

## 5. Guardrails

- **Operator switch:** `mailbox_member_address_minting` (plugin.json setting, default **on** — the feature only ever mints on a domain the operator already registered, for members the operator already admitted; pre-launch there is no legacy population to surprise). Off hides the offer everywhere.
- **One self-minted address per member.** A second one is an admin conversation. (Track by the grant's existence on an authoritative alias — the § 2.2 precondition already enforces this without a new column.)
- **Namespace protection:** the local part passes the alias regex, must not collide with an existing alias on the domain (live or soft-deleted), and must not be on the reserved list (`postmaster`, `abuse`, `admin`, `administrator`, `hostmaster`, `webmaster`, `root`, `security`, `noreply`, `no-reply`, `info`, `support`, `billing`, `sales`, `mail`, `help`). Reserved names are for the operator to create deliberately.
- **Standard level.** The alias carries no explicit security level (NULL = inherit the domain's), like any admin-created mailbox. Raising it is the existing protection ceremony, unchanged.
- **No contact writes.** Minting an address never touches anyone's contacts (contacts are permission — nothing is auto-added).
- **Deletion is the existing alias soft-delete**, admin-side; nothing new.

## 6. What this deliberately does not do

- No chat-only identity class — the mint is a full mailbox on purpose (§ 1).
- No Direct participation for the external address itself: `pat@gmail.com` still cannot send or receive joinery-to-joinery anything; the member's *local* address carries that. Their Gmail keeps flowing over Gmail's own SMTP with Gmail's own DKIM.
- No member self-service for the connected account (reconnect, re-auth) — that remains the parent spec's § 9.1 open question.
- No automatic minting. The member acts; nothing mints on their behalf at connect time.

## 7. Acceptance

On a deployment with one authoritative domain (`example.org`) plus a member whose only mailbox is a connected Gmail:

1. The messenger's cross-site picker shows the offer instead of the bare S4 refusal; minting `pat@example.org` succeeds and the same chat attempt then goes through capability lookup and sends (given the domain's Direct records are published — the existing unpublished state and its operator-facing copy are unchanged).
2. The minted mailbox appears in the member's reader; mail sent to `pat@example.org` from outside lands in it.
3. A second mint attempt by the same member is refused with a message naming the existing address.
4. `admin@example.org`, an existing alias name, and an illegal local part are each refused with a specific reason, before anything is created.
5. With `mailbox_member_address_minting` off, or with two authoritative domains and no designated mint domain, the offer is absent and S4 reads as today.
6. A member who already holds a grant on an authoritative-domain alias never sees the offer.
7. An IMAP-only deployment (no authoritative domain) never shows the offer.

Tests: extend `plugins/mailbox/tests/imap_source_boundaries_test.php`'s fixtures or add a sibling db-tier suite covering the mint action (success, each § 5 refusal, the one-per-member rule) and the messenger surface state.

## 8. Open questions

1. **Default of `mailbox_member_address_minting`** — spec says on (§ 5); flip to off if member-minted addresses on the site domain feel too generous for some deployments.
2. **Should the suggestion flow also appear in the setup wizard** for a member finishing an IMAP connect? Deferred — the messenger surface is where the need is felt; the wizard is operator-facing.
