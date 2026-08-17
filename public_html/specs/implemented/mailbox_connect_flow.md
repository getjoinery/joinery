# Connecting a Mailbox: Ask in the Order the Answers Exist

**Status:** Built.

## The problem

Adding a pulled-in mailbox (Gmail, Microsoft, Yahoo/AOL, iCloud, Fastmail,
generic IMAP)
currently walks the operator through the storage layout rather than the task:

1. Create a **domain** — `gmail.com`, flagged as an IMAP source, with a
   protection level chosen before any mail exists.
2. Create a **mailbox** under it. The editor no longer asks the unanswerable
   folder and sync questions — those controls stay hidden until the server has
   been heard from — but hiding them means the answers require a later trip
   back to this same editor.
3. Save, return to the Accounts tree. If the deployment has no Google app
   registered there is no Connect to press — the row offers **Set up Google
   access**, which leaves for the OAuth providers page.
4. Register the app there, come back, connect — and land back on the Accounts
   tree, where nothing acknowledges the connection (`?connected=N` is carried
   in the redirect and never read) and the folder and sync settings that just
   became knowable are another editor visit away.

The row has to be saved before consent can attach a token to it. That
implementation ordering has been allowed to become the operator's ordering;
hiding the premature questions and signposting the missing app registration
treated the symptoms, and the round trips remain.

## The order the flow should follow

1. **Where does this mail live?** — a provider choice. Nothing else.
2. **Is this site registered with that provider?** — shown only when it is not,
   for the chosen provider only, with the fields in place.
3. **Sign in.** Consent runs here, before anything unanswerable is asked.
4. **Now configure it** — with the address confirmed by the provider, the real
   folder list, sync offered only where the server does it, how much history to
   import, who reads it, and what protection it gets.

The wizard owns **creation only**. Editing an existing feed stays where it is,
in the per-object editors, which continue to work unchanged.

## A. The connect wizard

New page `plugins/mailbox/admin/admin_mailbox_connect.php` plus
`plugins/mailbox/logic/admin_mailbox_connect_logic.php`, permission 10 (it
handles full-mailbox credentials, like the existing IMAP surfaces).

One page, four states, chosen by what is known rather than by a step counter in
the URL:

| State | Shown when | Contains |
|---|---|---|
| `provider` | no provider chosen | provider cards from `InboundImapAccount::PRESETS`, plus a card for a domain you host that hands off to the existing domain editor |
| `register` | chosen provider is OAuth and `isConfigured()` is false | that provider's `configFields()` inline, saved with `OAuth2ProviderConfig::save($class, $input, $prefix, $session)`, plus the callback URL to paste |
| `signin` | provider ready, no token yet | one button that begins consent; for password providers, the host/password fields instead; and **"someone else will sign in"**, which takes the address, calls the provisioner with no token, and leaves the feed disabled on the Accounts tree with its normal **Connect** button — completed later through the existing `account_id` path, by a permission-10 admin on their own device. No new consent mechanism; a shareable consent link for non-admin owners would be its own spec if member-owned pulled-in mail becomes a real flow |
| `configure` | a feed exists and is connected | the full settings form, everything knowable |

The `register` state is not a new pattern: `OAuth2ProviderConfig` already takes
a field prefix precisely so a page can collect an app registration in place, as
the DNS publish box does. The standalone OAuth providers page remains the place
to manage every provider at once.

The Accounts tree keeps **+ Domain** for domains you host, where MX and identity
are real decisions. Its **+ Mailbox** / **+ IMAP feed** entry points for pulled-in
mail **route into this wizard and nowhere else** — the combined editor becomes
edit-only for IMAP feeds. The one capability the old entry point had that a
consent-first wizard would otherwise lose is creating a mailbox for someone who
must authorize on their own device; that survives as the explicit "someone else
will sign in" choice above, rather than as the default path everybody walks.

## B. Consent creates the feed

Today `InboundImapOAuthConsumer::onTokenGranted()` reads only
`payload['account_id']`; without one it silently redirects to the Accounts tree
and the granted token is discarded. A row must therefore exist before the
operator signs in. That requirement inverts: the flow payload carries the
**intent**, and the consumer creates the feed on success.

`OAuth2State` keeps the payload server-side in the session, single-use and
expiring, so intent may be carried there safely.

**Payload:** `{provider_key, security_level, reader_user_id}` — no ids for rows
that do not exist yet. `account_id` remains supported for the reconnect path,
where the feed genuinely does already exist.

**New `plugins/mailbox/includes/ImapFeedProvisioner.php`** — the one place a
pulled-in mailbox is brought into being, given a connected identity:

```php
ImapFeedProvisioner::provision(string $provider_key, string $address,
        array $intent, ?OAuth2Token $token): InboundImapAccount
```

- Finds or creates the domain for the address's domain part, flagged as an IMAP
  source. An address on a domain this deployment already hosts reuses that
  domain row rather than adding a second one.
- Finds or creates the store-mode alias for the local part.
- Creates the feed, stores the token, records the connected status.
- Applies the intent (protection level, reader grant), disabled until the
  operator finishes the configure step.

The provisioner is built **on** the existing headless helpers
`mailbox_provision_domain()` / `mailbox_provision_mailbox()` in
`includes/provisioning.php`, which already find-or-create a domain, alias and
grant idempotently — not beside them, or rule 1 below dies on day one. What the
combined editor does inline today — alias resolution
(`_imap_edit_resolve_mailbox()`, which handles only the alias), feed creation,
grant sync, folder-tracking sync — moves behind the provisioner, and both paths
call it, so there is exactly one way a pulled-in mailbox comes into existence.

**Abandonment:** consent that is never granted leaves nothing behind, because
nothing was created. Consent granted and then abandoned mid-configure leaves a
connected but disabled feed, which the Accounts tree already renders honestly as
not enabled.

## C. Learning the address from the provider

Asking the operator to type the address they are about to sign in as invites a
mismatch that surfaces later as an opaque IMAP authentication failure — the
address is used verbatim as the SASL username.

The OAuth layer has no identity concept today (no `id_token` handling, no
profile call). Two additions, both provider-declared, keeping mechanics in the
client:

**`OAuth2Provider` gains:**

```php
public static function identityScopes(): array;              // extra scopes, may be empty
public static function getIdentityEndpoint(): ?string;       // NULL when unsupported
public static function identityFromProfile(array $p): ?string; // the email address
```

The "no identity" defaults (`[]` / `NULL` / `NULL`) come from a trait, the same
escape hatch `DeclaresOAuthConfigFields` provides for `configFields()`, so the
DNS/cloud providers gain one `use` line and nothing else. Google and Microsoft
override. This knowingly retires the `docs/oauth2.md` "establishing identity is
the consumer's responsibility" boundary — that line predates any consumer
needing identity, and the next one (social login) would otherwise build the
same lookup a second time.

**`OAuth2Client` gains** `fetchIdentity(string $providerClass, OAuth2Token $t): ?string`,
a bearer GET against the declared endpoint whose result the provider interprets.

| Provider | Scopes added | Endpoint | Field |
|---|---|---|---|
| Google | `openid`, `email` | `https://www.googleapis.com/oauth2/v3/userinfo` | `email` |
| Microsoft | `User.Read` | `https://graph.microsoft.com/v1.0/me` | `mail`, else `userPrincipalName` |
| Others | — | NULL | — |

**When identity is unavailable** (provider does not support it, the call fails,
or the operator is on a password provider) the configure step asks for the
address as it does today, with the token already held. Losing the convenience
must never lose the connection.

**A learned address is authoritative.** Where an operator has typed one and the
provider reports another, the provider's wins and the difference is stated.

## D. Protection level per mailbox, for pulled-in mail only

Today the level lives on the domain, and `overview.md` gives the reason: MX,
SPF, DMARC and DKIM are domain-level facts. That reasoning is sound — and it
does not reach a pulled-in mailbox, which has none of those facts. `gmail.com`
is not an identity this deployment holds; it is somebody else's domain that we
hold one account on. Two people pulling their own Gmail into one site would
today share a single protection setting, and sealed mail encrypts to one person.

**The rule, stated once:** *protection attaches to the identity that owns the
mail.* For hosted mail that identity is the domain, and every mailbox under it
inherits — unchanged. For pulled-in mail the identity is the mailbox.

This is deliberately **not** a general per-mailbox override. A hosted domain
keeps exactly one answer, because its DNS-shaped guarantees cannot vary per
mailbox.

**Storage:** `iea_security_level` on the alias, `varchar(16)`, nullable. NULL
means inherit from the domain, which is what every existing row means on day
one, so no data migration is needed.

**The resolver — one function, not a new subsystem.** `InboundEmailAlias` gains
`security_level()` and `seals_content()` with the same signatures the domain
has: own value when set, otherwise the domain's. Callers that hold an alias
switch to asking the alias. The domain's own methods stay, and their meaning
narrows to what they always meant for identity.

**Call-site inventory** (~40 sites, all inside the mailbox plugin). Two groups,
and the split is the whole point:

*Content sealing — moves to the alias (it already has one in hand):*
`InboundEmailRouter::storeMessage()` and its attachment path,
`inbound_email_message_class.php` (`sealOwnerUserId` neighbourhood),
`MailboxDirectConsumer`, `MailboxContacts`, `inbound_email_filter_class.php`,
`admin_mailbox_filters_logic`, `seal_batch_logic`, `unseal_batch_logic`,
`admin_mailbox_imap_edit_logic` (the single-reader rule),
`admin_mailbox_imap_edit.php`, `mailbox_setup_hints`, `mailbox_setup_scope`,
`admin_mailbox_accounts.php` (the badge).

*Domain identity — unchanged:* `MailboxDkimSigner`, `protect_identity.php`,
`InboundEmailSetupCheck` (the DNS shape branches on
`ied_is_protected_identity`, not the level), `RelayMapExporter`,
`InboundEmailHealth`, `admin_mailbox_setup_logic`, `admin_mailbox_domains*`.

**`maxSecurityLevelForUser()` must union alias levels.** It drives the
per-level unlock-window caps and the Fortress second-factor enrollment gate, so
leaving it domain-only would silently under-report a user whose only protected
mail is a pulled-in Private mailbox. This is the one place where missing the
change is quiet rather than loud, and it gets its own test.

**One ceremony, scoped.** Raising a pulled-in mailbox to Private runs the
existing `protection_ceremony.php` — the same checklist rows, the same
server-side re-verification at save, the same receipt card and backlog sealing.
`mailbox_protection_facts()` gains an optional alias scope so it gathers for one
mailbox instead of every alias on the domain; the seal and unseal batch paths
gain the same scope. No second ceremony, no second checklist, no second receipt.

**Fortress stays domain-only.** It is an identity guarantee — relay-side
sealing, inverted DNS, in-app signing — and none of it exists for mail we pull
from somebody else's server. The domain editor already coerces Fortress to
Standard for an IMAP source; the mailbox picker offers Standard and Private.

**The domain row under a pulled-in mailbox is forced Standard** and its level is
not shown, because it is not ours to make claims about.

## Anti-fragmentation rules

These are the constraints that keep this from becoming a second mail system:

1. **One creation path.** `ImapFeedProvisioner` is the only code that brings a
   pulled-in domain, alias and feed into existence. The wizard and the combined
   editor both call it.
2. **One resolver.** A content-sealing decision asks the alias. Nothing
   reimplements the inherit rule, and nothing reads `iea_security_level`
   directly outside the accessor.
3. **One ceremony.** Raising or lowering a level runs the existing ceremony,
   scoped. A per-mailbox raise that needed its own checklist would mean the rule
   was wrong.
4. **One edit surface.** The wizard creates; the per-object editors edit. No
   setting exists in the wizard that the editor cannot also change later.
5. **The grant engine stays provider-agnostic.** Identity lookup is a declared
   endpoint plus a provider-supplied reader, exactly like the token endpoints.

## Data model

| Field | Table | Type | Meaning |
|---|---|---|---|
| `iea_security_level` | `iea_inbound_email_aliases` | `varchar(16)`, nullable | mailbox's own level; NULL inherits the domain's |

No migration: NULL is the existing behaviour, and there are no production users.

## Failure and edge cases

- **Consent granted, provisioning fails** (address unparseable, domain row
  refuses to save): the token is not silently dropped — it is held in the
  operator's session, SecretBox-encrypted, single-use and expiring, the same
  shape `OAuth2State` gives the flow payload. The wizard returns to `configure`
  with the failure stated and the operator asked for the address, and retries
  provisioning from the stash — no re-consent. A session that ends first means
  signing in again, which costs a click and loses nothing.
- **The address belongs to a domain this deployment hosts by MX.** The existing
  domain is reused and its level applies; the mailbox does not get its own,
  because that domain is an identity we hold.
- **Two mailboxes, same provider domain, different levels.** Supported, and the
  reason the change exists.
- **Reconnect** keeps carrying `account_id` and does not touch the provisioner.
- **A mailbox already sealed, lowered later** goes through the existing
  unseal path, alias-scoped.

## Documentation

To land with the build, not before (developer docs describe the current state):

- `plugins/mailbox/docs/overview.md` § **Security levels** — restate the opening
  rule as *protection attaches to the identity that owns the mail*, with the
  domain and mailbox cases beneath it, and note that Fortress is domain-only.
- `plugins/mailbox/docs/overview.md` § **The Accounts tree** / **Adding an
  Alias (mailbox)** — the wizard as the creation path for pulled-in mail.
- `docs/oauth2.md` — the identity lookup: the three provider methods, the client
  call, and that a provider without an endpoint is normal.

## Tests

- `plugins/mailbox/tests/` — alias level resolution (own value, inherit, invalid
  value falls back to Standard), `maxSecurityLevelForUser()` seeing an
  alias-only Private, provisioner find-or-create for both a new provider domain
  and an existing hosted one, single-reader enforcement reading the alias.
- `tests/unit/` — provider identity parsing from fixture profile payloads for
  Google and Microsoft, and a provider without an endpoint returning NULL.
- The invariant: `sync_for_alias()` refuses a second holder, the removal of the
  last holder, and a holder without a vault, on a sealing mailbox — whichever
  surface calls it. Deleting the sole holder of a sealing mailbox is refused
  (the cascade path that bypasses `sync_for_alias()`).
- Store time: a sealing mailbox that cannot resolve a seal target declines the
  message rather than storing it in plaintext — on `storeMessage()` and
  `storeDirectMessage()` both, and the decline is retryable, not a bounce.
- The existing protection-ceremony and mailbox suites must pass unchanged; a row
  that changes verdict means the scoping is wrong.

## Build order

0. The grant invariant and the store-time refusal (§E). Independent of
   everything else here, and the only part that closes a silent plaintext hole.
1. `ImapFeedProvisioner` extracted from the combined editor, both paths on it.
2. Alias level field, resolver, call-site switch, `maxSecurityLevelForUser`.
3. Ceremony alias scoping (facts, seal, unseal).
4. Provider identity lookup.
5. The wizard, and the consumer's intent payload.

Each step is shippable on its own; the wizard is last because it is the one that
depends on all of them.

## E. A sealing mailbox must always have exactly one holder with a vault

Sealing happens at store time and needs a target. `$sealing` is true only when
the mailbox resolves to **exactly one holder**
(`InboundEmailMessage::singleOwnerUserId()` returns NULL for none or several)
**and** that holder has a vault. When either is false the message is written in
**plaintext on a Private mailbox** — the row does appear in the domain editor's
unsealed-backlog count, but nothing names why, and nothing revisits it. (Mail
that matches no mailbox at all — the catch-all — seals to the domain owner by
a separate fallback; that path is out of scope here and must not be broken:
the invariant and the refusal below apply where an alias exists.)

The protection ceremony checks both at the moment of the raise, and two
grant-writing paths (`admin_mailbox_alias_logic`, `includes/provisioning.php`)
re-check holder count through `mailbox_protected_grant_error()`. Nothing else
holds the invariant afterwards, and four paths can break it:

- `admin_mailbox_imap_edit_logic` rejects a second holder but lets an empty
  selection sync a sealing mailbox to **zero** holders;
- `ceremony_remove_grant` in `admin_mailbox_domains_logic` removes a grant
  with no check at all — including the last one;
- no grant-writing path anywhere checks that the holder **has a vault** —
  only holder count is ever checked;
- deleting a user cascades away their grant rows (`ieg_usr_user_id` is a
  `cascade` rule) without passing through `sync_for_alias()` at all.

**The state is invalid, so make it unreachable rather than repairable:**

1. **The invariant lives in `InboundEmailMailboxGrant::sync_for_alias()`**,
   which every grant-writing path goes through. On a sealing mailbox it refuses
   any change that would leave other than exactly one holder, or that would
   leave a holder without a vault. The message names the fix: lower the mailbox
   to Standard first, which already has an unseal path. The one write that
   bypasses `sync_for_alias()` is the user-delete cascade; deleting the sole
   holder of a sealing mailbox is refused with the same message, at the same
   moment the deletion is attempted.
2. **Store time refuses instead of downgrading.** If a sealing mailbox somehow
   cannot produce a seal target, `storeMessage()` does not write plaintext — it
   declines the message, and declining always means **"try again later," never
   a bounce**, on every ingress path: the IMAP feed leaves the message on the
   source and reports why it stopped; the Postfix pipe exits tempfail so the
   queue defers and redelivers; the Mailgun webhook returns non-2xx so Mailgun
   retries; a Direct sender gets a retryable error and keeps the message.
   `storeDirectMessage()` carries the identical plaintext fallback and gets the
   identical fix. Once the mailbox is repaired, the held mail flows in on its
   own. A refusing mailbox is flagged in `InboundEmailHealth` so it is seen
   well inside the shortest retry window — the sender-side queues expire in
   hours to days, and the flag is what turns that deadline into a non-event.
   With the invariant above, this path is unreachable through every
   grant-writing door that exists today; it is the backstop that makes a
   future bypass — or a vault that fails to load at delivery time — loud and
   recoverable instead of a silent leak. The read side offers no
   symmetry to lean on: it dispatches on the row's own `iem_content_sealed`
   column, so a row that landed plaintext renders plaintext forever — which is
   why refusal at store time is the only gate. (One adjacent fail-open gets
   fixed in passing: `MailboxContacts` treats a resolution error as
   non-sealing. `security_level()` returning Standard for an unrecognized
   stored value stays — the value is only ever written through validated
   pickers.)

No scheduled sweep, and no janitor: with the invariant enforced there is nothing
to sweep. Rows that landed plaintext **before** this change are a one-time
repair — the existing ceremony backlog pass, run once — not a standing task.

With the invariant in place the receipt can appear immediately and its claim —
*new mail seals as it arrives* — is true by construction.

## A note on choosing Private before an import

Sealing happens per row at store time, and an import goes through the same
`InboundEmailRouter::storeMessage()` path as live delivery. So a mailbox set to
Private **before** its archive is imported seals every message as it lands, at no
extra cost. Set afterwards, the same end state is reached by
`mailbox_protection_seal_batch()` walking the backlog 200 rows at a time from
the browser, rewriting every row that already landed. The wizard should
therefore ask for the protection level **before** offering the import, and the
import panel should say so where a Standard mailbox is about to take a large
archive.
