# Page views never write

**Status:** Open. The guard is built and has logged since 0.8.521; what is
left is reading what it logged, and switching it to refuse. Split out of
`declared_permissions` (its WP1 flip and bug B1) on 2026-10-26, when every
other part of that spec was built and released.

## What this does

A page view (a web GET, HEAD or OPTIONS) never changes anything, unless the
server itself makes the write and says so. Another site can make a visitor's
browser open any link with the visitor's session cookie (SameSite=Lax sends it
on a top-level navigation), so a link that writes is a link anyone can fire on
an admin's behalf. A POST from another site carries no cookie.

## What is there

- `GuardedPdo` (includes/GuardedPdo.php) checks every statement on a page
  view. A write not wrapped in `SystemBase::server_initiated_write()` is logged
  as `[GET_WRITE]` with the request, verb, table and file:line.
  `PAGE_VIEW_WRITES = 'log'`; `'refuse'` throws.
- The server's own page-view writes are marked (visitor events, logout's
  cleanup, subscription reconciliation, mailed one-time links, the share-link
  counter and the rest listed in `declared_permissions` WP1).
- Admin actions that ran on a GET link are POST buttons; their callers are
  enumerated in tests/unit/core_api_mechanical_test.php.
- `SystemBase::assert_not_get_mutation()` is the older model-level check of
  the same rule; it sees model saves only, not raw SQL or plugins.

## Work packages

**WP1: an agent word that counts a marker in a node's error log.** Takes a
marker (`[GET_WRITE]`, `[WRITE_REFUSED]`) and a time window, and returns the
count and the distinct file:line places, never the whole log. Read only.

**WP2: read the nodes.** Run WP1's word on getjoinery and every other managed
node for the window since 0.8.521. The app, Drive sync clients, mailbox flows
and webhooks reach paths dev never runs. Each place found is either the
server's own write (mark it) or an action on a GET (make it a POST).

**WP3: sweep dev.** Open every view route and call every API read action with
PageProbe's view-only session, and fix each `[GET_WRITE]` as in WP2.

**WP4: the flip.** When WP2 and WP3 find nothing new for a release, set
`PAGE_VIEW_WRITES = 'refuse'`, remove `assert_not_get_mutation()` and
`$allow_get_mutation` from SystemBase, and update docs/permissions.md.
Sign-out stays a link, accepted (`declared_permissions` WP1).
