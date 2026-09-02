# DEFERRED — Member self-service reconnect of a connected IMAP account

**Status: DEFERRED (owner, 2026-09-02) — design record, not scheduled.**
Split out of `specs/imap_source_domain_boundaries.md` § 9 Q1. Connect,
reconnect and re-auth of a connected account are operator-only (permission 10)
until this is picked up.

## What this is, in plain terms

A member whose Gmail (or other IMAP) account is connected to their mailbox can
watch it stop syncing when the provider's token expires or the password
changes, but cannot fix it. Only a permission-10 admin can re-run the connect
wizard (`plugins/mailbox/logic/admin_mailbox_connect_logic.php:67`) or edit the
feed (`plugins/mailbox/logic/admin_mailbox_imap_edit_logic.php:45`). Every
token expiry is therefore an admin ticket.

The credential is the member's own. They should be able to re-authorize the
account they connected without an operator in the loop.

## Scope when built

1. **A profile-side reconnect action** for a feed bound to an alias the member
   is granted on: re-enter the password or re-run the OAuth consent, then the
   existing `ImapFeedProvisioner` path re-tests and re-enables the feed. Same
   validation and sealed-credential handling as the admin wizard; no new
   credential store.
2. **Ownership rule**: a member may reconnect only feeds whose alias grants
   them the mailbox. They may not change the address, the host, the delivery
   mode, or the alias binding — those stay operator-only. Reconnect changes
   the credential and the enabled flag, nothing else.
3. **Surfacing**: the reader's existing sync-failure notice (the one a member
   sees today) gains the reconnect link. The admin accounts page is unchanged.
4. **Audit**: a reconnect writes the same feed-history entry the admin path
   writes, attributed to the member.

## Not in scope

- Connecting a *new* account from the profile — stays operator-only.
- Any change to the domain authority boundary (built by the parent spec).

## Open questions at build time

- Whether the OAuth consent callback (`docs/oauth2.md` consumer contract) can
  return to a profile route as well as the admin route, or whether the
  callback stays admin-only and the member path is password-only.
- Whether a paused feed that the *operator* paused deliberately should be
  reconnectable by the member (recommendation: no — a member reconnect only
  clears an auth failure, never an operator pause).
