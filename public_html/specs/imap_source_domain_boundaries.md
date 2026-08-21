# Spec: An IMAP-source domain is not a hosted domain

**Status:** Draft (awaiting implementation)
**Version:** 1.0
**Area:** `plugins/mailbox` (domain model, transport, setup), core account-security guards (`logic/register_logic.php`, `logic/security_logic.php`, `logic/account_edit_logic.php`), `plugins/messenger`, `includes/joinery_direct`, core setup wizard (`includes/SetupSteps.php`)
**Related:** `docs/joinery_direct.md`, `plugins/mailbox/docs/overview.md`, `specs/messenger_reachability_states.md`

---

## 1. What this fixes, in plain terms

A tester connected their Gmail over IMAP and configured nothing else — no domain of their own, no local mailbox. That is a legitimate, complete way to use the mailbox: read your Gmail here, send through Gmail's own SMTP, run the AI panel over it. The core loop works today.

But connecting an IMAP account manufactures a real domain row for `gmail.com` (`ied_is_imap_source = true`, `plugins/mailbox/includes/provisioning.php:56-77`), and much of the platform then treats that row as proof that **this deployment hosts gmail.com**. The fallout ranges from absurd to serious:

- **Nobody can register on the site with a `@gmail.com` address anymore** — refused as "a mailbox hosted here", with error text that suggests using Gmail instead.
- A `@gmail.com` **recovery address** is refused for the same reason.
- The setup wizard's Receiving step is **permanently amber**, labels the working Gmail mailbox "waiting for DNS", and cannot be declined.
- If the mailbox's feed is disabled, a compose **silently falls through to platform egress** trying to send `From: user@gmail.com` through Mailgun/Postfix — spoofing, or a nonsense error.
- Messenger treats every `@gmail.com` address as local and answers "That address cannot be reached by chat"; the Setup tab can even mint a Joinery Direct signing identity for gmail.com and then tell the user to publish DNS records under gmail.com, which is impossible.

The root defect is one thing: the codebase asks two different questions with one check. **"Is there a domain row for X?"** and **"is this deployment authoritative for X?"** are not the same question, and IMAP-source rows answer yes to the first and must answer no to the second. Some subsystems already know this — the relay map (`RelayMapExporter.php:108-114`), fleet claims (`FleetClient.php:88-97`), identity protection (`protect_identity.php:208-210`), and the DNS-check task (`tasks/CheckDomainSetup.php:48-54`) all deliberately exclude IMAP sources. This spec extends that same boundary to every remaining consumer, and fixes the handful of adjacent defects the IMAP-only walkthrough surfaced.

---

## 2. The rule

**An IMAP-source domain row is a bookkeeping anchor for a connected account's mailbox — never an identity this deployment owns.** Concretely:

1. It is never "hosted here" for account-security purposes (registration, recovery address, login-email change).
2. It never acquires a Joinery Direct signing identity, and is never "local" to the messenger.
3. Mail with a From on it leaves **only** through that account's own authenticated SMTP — never through platform egress, the relay, or a DKIM signer.
4. Its DNS is never checked, prescribed, planned, or graded — the deployment has nothing to say about gmail.com's MX.
5. The setup wizard treats a connected account as a **complete** receiving arrangement, not a domain waiting for DNS.

Where a consumer needs the distinction, it asks the model — not a scattered `ied_is_imap_source` test at each callsite. `InboundEmailDomain` gains one instance helper, `is_authoritative()` (a real row that is enabled and not an IMAP source), and the existing static helpers change meaning to match §2.1–2.2 below. Callsites that genuinely want "any registered row" (alias FK integrity, the reader, ingest) keep using `GetByDomain` untouched.

---

## 3. Inventory — every consumer, and which question it is really asking

| Callsite | Question it means to ask | Today | Correct |
|---|---|---|---|
| `data/inbound_email_domain_class.php:214-225` `isHostedEmailAddress()` ← `logic/register_logic.php:128-130`, `logic/security_logic.php:134-153` | authoritative? | any row | exclude IMAP source |
| `data/inbound_email_domain_class.php:332-350` `userHostedDomainNames()` ← `logic/account_edit_logic.php:85-118` | authoritative? | any granted row | exclude IMAP source |
| `plugins/messenger/includes/MessengerFederation.php:73-80` `localDomain()` | authoritative? | any enabled row | exclude IMAP source |
| `includes/InboundEmailSetupCheck.php:1710-1716, 599-632` `directResult()` / `:718-745` `directRecords()` → `DirectSigningIdentity::ensureFor()` | authoritative? | mints for any domain | refuse IMAP source **at `ensureFor`** |
| `includes/InboundEmailSetupCheck.php:284-292, 325-336` `runDomainChecks(null)` (Setup tab advanced run, `PostfixProvider::getSetupChecks`) | authoritative? | probes gmail.com MX/SPF | skip IMAP source |
| `plugins/mailbox/includes/InboundEmailHealth.php:241-266` `checkDomainDns()` | authoritative? | REQUIRED checks against gmail.com, can throw `ProvisioningCheckFailed` | skip IMAP source |
| `includes/InboundEmailSetupCheck.php:2041-2093` `machineSenderRows()` | authoritative? | offers `mail.gmail.com` machine sender when `defaultemail` is the Gmail address | skip IMAP source |
| `plugins/mailbox/includes/MailboxDirectConsumer.php:59-77` `resolveAddress()` `hosts_domain` | authoritative? | claims authority for gmail.com preflights | exclude IMAP source |
| `plugins/mailbox/includes/receive_mode.php:66-72` `mailbox_receive_mode()` | has the operator decided a receiving topology? | counts gmail.com → reports `direct` | exclude IMAP source |
| `plugins/mailbox/includes/MailboxDkimSigner.php:66-79, 129-142` `standardFilesystemSigner()` | do we sign for this domain? | error_log "gmail.com leaving UNSIGNED" every relay-fronted send | return null for IMAP source, no log |
| `includes/OutboundTransport.php:79-137` `forHostedAlias()` | may platform egress carry this From? | falls through when feed disabled | refuse IMAP source (§5) |
| `plugins/mailbox/includes/bootstrap.php:383-411` `mail_receive` status | is receiving set up? | permanently amber | connected-account arm (§6) |

Already correct, no change: `RelayMapExporter.php:108-114`, `FleetClient.php:88-97`, `protect_identity.php:208-210`, `tasks/CheckDomainSetup.php:48-54`, `InboundEmailSetupCheck.php:2400-2412` (relay cutover), `mailbox_setup_scope.php` / `mailbox_setup_hints.php` (IMAP branches), `admin_mailbox_setup_logic.php:462-465` (domain picker).

---

## 4. Work item A — the authority boundary

1. **`InboundEmailDomain::is_authoritative()`** (instance): enabled, not deleted, `!ied_is_imap_source`. One place the meaning lives; docblock states the §2 rule.
2. **`isHostedEmailAddress()` excludes IMAP sources.** Its callers all mean "would this address be unreachable/circular as an outside contact point" — for a Gmail feed the answer is no: Gmail delivers it, we merely mirror it. Registration, recovery address, and the login-email-change step-up all come right with this one change.
3. **`userHostedDomainNames()` excludes IMAP sources** — same reasoning for the login-email-change 2FA precondition.
4. **`DirectSigningIdentity::ensureFor()` refuses IMAP-source domains** (throws or returns null; callers already handle a domain without identity). Guarding at the mint kills the whole downstream class — `siteReady()` flipping true, the messenger's un-followable "publish DNS records" advice, per-send `downgrade:no_capability` log spam. `directResult()`/`directRecords()` skip IMAP sources so the Setup tab never renders the un-publishable plan.
5. **`MessengerFederation::localDomain()` excludes IMAP sources.** Chat to `bob@example.org` then performs a real capability lookup even when someone connected `example.org` over IMAP — restoring Direct chat to genuinely-published domains that today get local-trapped.
6. **`MailboxDirectConsumer::resolveAddress()`** returns not-hosted for IMAP-source domains, so this instance stops answering preflights for gmail.com.
7. **DNS/health**: `runDomainChecks(null)`, `InboundEmailHealth::checkDomainDns()`, `machineSenderRows()`, `mailbox_receive_mode()` skip IMAP sources per the inventory.
8. **DKIM**: `MailboxDkimSigner` returns no signer and logs nothing for IMAP-source domains.
9. **Cleanup**: delete any existing `jdi_direct_identities` rows whose domain is IMAP-source (one small migration; also repairs the tester's site if the advanced run already minted one). Sites are pre-launch; no other data motion needed.

## 5. Work item B — transport safety: a connected mailbox never leaks to platform egress

`MailboxSender::send()` picks the transport by "is there an **enabled** feed" (`MailboxSender.php:149-150, 337-345`). A disabled or paused feed — the state every feed is **born in** during the connect wizard (`ImapFeedProvisioner.php:151`) — silently drops the send into `OutboundTransport::forHostedAlias()`, which happily tries platform egress with a `@gmail.com` From.

1. `forHostedAlias()` (or the caller, whichever reads cleaner) checks the From domain: if the alias's domain is IMAP-source, **refuse with an actionable error** — "This mailbox sends through its connected account, which is currently disabled — re-enable it under Mailbox → Accounts." Never fall through.
2. The compose surface gets a send-capability preflight: Reply/Reply-All/Forward/New for an alias whose feed is disabled, unauthorized (`isSendAuthorized()` false), or generic-IMAP-without-SMTP surface the problem **before** the user writes the message (a banner on the compose panel is enough; hiding the buttons hides the diagnosis).
3. The mailbox Setup verdict stops reading green for a store-only IMAP mailbox that cannot send: `mailbox_setup_scoped_rows()` (`mailbox_setup_scope.php:88-95`) adds a Sending row for IMAP mailboxes (feed enabled + send-authorized), not only when the alias forwards.

## 6. Work item C — the setup wizard accepts a connected account as done

`mail_receive` (`plugins/mailbox/includes/bootstrap.php:383-411`) waits for `ied_setup_status = 'ok'`, which `CheckDomainSetup` deliberately never writes for IMAP sources — permanent amber, "waiting for DNS" against a working Gmail mailbox, and no `decision` key so it cannot be declined.

1. **Status**: green when every enabled store alias is either on a domain with `ied_setup_status = 'ok'` **or** on an IMAP-source domain whose bound feed is enabled. (A connected account that is syncing *is* the receiving arrangement.)
2. **Rendering** (`includes/setup_steps/mail_receive.php:50-70`): an IMAP-source mailbox row reads "connected account" with a green dot when its feed is enabled, "connection paused" when not — never "waiting for DNS". The DNS-plan block already skips IMAP sources; the label catches up.
3. **Copy**: the step description and the dismissal `dismiss_line` ("This site has no mailbox of its own yet") acknowledge the connected-account arrangement instead of denying it exists.
4. **The setup pill honors dismissal** (`SetupSteps::pillCounts()` / `PublicPageBase.php:58-70` ignore `usr_setup_dismissed_time` today). A dismissed wizard stops counting against the header pill. This is a core wizard defect independent of IMAP, fixed here because this walkthrough exposed it.

## 7. Work item D — compose coherence on a connected account

1. **Wizard-provisioned feeds default `iia_show_compose = true`** when sync is on (`inbound_imap_account_class.php:170` default stays false for hand-built rows; the connect wizard sets it). Rationale: compose is already offered for any granted alias (`mailbox_reader.js:1449-1457`), and `iia_show_compose` is what arms the Sent-copy handling (`MailboxSender.php:270-289`) that avoids the Gmail Message-ID rewrite producing duplicate Sent rows and broken threading. With the flag off, the flag's absence is a data-loss-shaped bug, not a preference.
2. **Reply/compose chips respect the same preflight as §5.2** — one capability answer serves both.
3. **The compose "can go direct" indicator checks the sender too**: `direct_status_logic.php:76-82` currently answers from the recipient's domain alone; it also requires the composing alias's domain to hold a signing identity, else reports plain SMTP. No more lying indicator for connected-account senders (or any unpublished sender).

## 8. Acceptance

On a deployment whose only mail configuration is one connected Gmail account:

1. A new visitor registers with a `@gmail.com` address; a member sets a `@gmail.com` recovery address.
2. The setup wizard's Receiving step is green with the feed enabled, and says "connected account"; pausing the feed turns it amber with "connection paused". A dismissed wizard shows no header pill.
3. With the feed disabled, pressing Send returns the §5.1 error; nothing reaches platform egress, the relay, or the send queue (assert via `eml_` / provider logs).
4. Sending with the feed enabled goes out via smtp.gmail.com, threads correctly on reply, and produces exactly one Sent row after ingest.
5. The Setup tab's advanced run and `InboundEmailHealth` produce no gmail.com DNS checks, no gmail.com signing identity, no machine-sender offer for `mail.gmail.com`, and `jdi_direct_identities` stays empty.
6. Messenger: picking a `@gmail.com` correspondent performs a capability lookup (and correctly reports unreachable); the user attempting cross-site chat still gets the honest S4 "You need a Joinery email address on this site" — never "publish this site's DNS records".
7. Relay-fronted sends log no "leaving UNSIGNED" line for the Gmail From.
8. Existing hosted-domain behavior is unchanged: a protected-identity domain still refuses, `mail_receive` still keys off `ied_setup_status` for real domains, Direct still works between two published instances.

Tests: a `db`-tier suite provisioning an IMAP-source domain + alias + disabled feed and asserting the guard answers (registration allowed, `forHostedAlias` refusal, `ensureFor` refusal, `localDomain` null, wizard status), plus a unit test pinning `is_authoritative()`.

## 9. Open questions (decide before or during build)

1. **Member self-service**: connect/reconnect/re-auth of an IMAP account requires permission 10 (`admin_mailbox_connect_logic.php:70`, `admin_mailbox_imap_edit_logic.php:45`). A member whose Gmail token expires can watch failures but not fix them. Product decision — self-service reconnect for a granted member, or keep operator-only? Not blocking the boundary work.
2. **Vault window coupling**: a connected-account alias raised to Private feeds the platform-wide unlock-window cap via `maxSecurityLevelForUser()` (`plugins/mailbox/includes/bootstrap.php:70-88`). Arguably correct (the user chose Private); confirm it is intended for IMAP aliases or exclude them from the cap walk.
3. **`+`-tagged addresses** cannot be provisioned as mailboxes (`inbound_email_alias_class.php:73-76` local-part regex). Widen the regex for IMAP-source aliases, or document the limit.

## 10. Out of scope

- Making gmail.com participate in Joinery Direct in any form — structurally impossible and correctly refused; this spec only makes the refusals honest.
- The feed-with-null-alias-binding editor hole (`admin_mailbox_imap_edit_logic.php:327`) — real but orthogonal; note filed here so it is not lost.
- Any change to hosted-domain (non-IMAP) mail flow, DKIM, SRS, relay, or fleet behavior.
