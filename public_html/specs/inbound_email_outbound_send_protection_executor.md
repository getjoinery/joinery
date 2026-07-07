# Inbound Email — Outbound Send Protection — Executor Package

**Status:** Ready for implementation
**Version:** 1.0
**Design authority:** `specs/inbound_email_outbound_send_protection.md` (v1.2) — the *why*,
the four closures, and the resolved "offer both postures from one build" decision. This is
the *how*.
**Depends on (build first):** `specs/implemented/passkeys_core_executor.md` and
`specs/inbound_email_encryption_at_rest_executor.md`. This package reuses that package's
`SealedBox` (the `crypto_box_seal` envelope), the per-user key hierarchy (`uev`/`uew`), and
the `VaultUnlock` window — the DKIM private key seals to the **same** user public key and
unwraps **in-window**. Do not start until those exist.

### Naming baseline (rename interaction)

Same convention as the encryption package: **run the rename first.** Paths below use
today's on-disk `plugins/mailbox/…`; after the rename apply exactly two
substitutions — dir `plugins/mailbox/`→`plugins/mailbox/`, setting-key
`inbound_email_`→`mailbox_`. Class names (`InboundEmailDomain`, `SRSRewriter`,
`InboundEmailSetupCheck`, `SmtpProvider`), table prefixes (`ied_`), and line numbers are
rename-invariant. Core files under `includes/` (`EmailSender`, `SmtpProvider`,
`SmtpMailer`) are **not** in the plugin and are untouched by the rename.

## What this changes (map, verified)

| Area | File |
|---|---|
| Domain schema (protected flag, sealed DKIM key, DNS value, selector) | `plugins/mailbox/data/inbound_email_domain_class.php` (`ied`, no DKIM cols today) |
| In-app DKIM signing hook (core, crypto-agnostic) | `includes/email_providers/SmtpProvider.php` (`send()` 180–184) |
| Protected-domain DKIM resolver (plugin, unwraps in-window) | new `plugins/mailbox/includes/MailboxDkimSigner.php` |
| Ambient-send guard | `includes/EmailSender.php` (`send()` 122, From default 124–126) |
| Remove protected domain from opendkim | `plugins/mailbox/provisioning/provision_dkim.sh` (append-only today; `signing.table` line `*@<domain> …`) |
| SRS → forwarding subdomain | `SRSRewriter::rewrite()` (42) called by `InboundEmailRouter::buildForwardMessage()` (~985, `$forwarding_domain = ied_domain`); bounce `handleSRSBounce()` (1169) |
| DNS check inversion | `plugins/mailbox/includes/InboundEmailSetupCheck.php` (`checkDomain()` 495; SPF 531–549 / `spfAuthorizes()` 977; DKIM 551–564; DMARC 566–582) |
| Health gating | `plugins/mailbox/includes/InboundEmailHealth.php` (`checkDomainDns()` 123) |

## Phase 0 — Preflight

Branch `outbound-send-protection`. Confirm the encryption package's `SealedBox`,
`VaultUnlock`, and `uev`/`uew` exist. No new dependencies (PHPMailer already present; it
signs natively via `DKIM_private_string`).

## Phase 1 — Domain schema (protected identity + sealed DKIM)

Add to `InboundEmailDomain::$field_specifications` (`ied` prefix — all net-new, no DKIM
columns exist today):
```php
'ied_is_protected_identity' => array('type'=>'bool','is_nullable'=>false,'default'=>false),
'ied_dkim_selector'         => array('type'=>'varchar(63)','is_nullable'=>true),   // e.g. 'mailk1'
'ied_dkim_sealed_key'       => array('type'=>'text','is_nullable'=>true),          // DKIM private key, crypto_box_seal'd to the owner public key
'ied_dkim_public_dns'       => array('type'=>'text','is_nullable'=>true),          // cleartext DKIM DNS record value (needed by the Setup tab while locked)
'ied_dkim_key_generation'   => array('type'=>'int4','is_nullable'=>false,'default'=>0),
'ied_forwarding_subdomain'  => array('type'=>'varchar(255)','is_nullable'=>true),  // e.g. 'fwd.example.com' (per-domain; server-wide default in settings)
```
`ied_dkim_sealed_key` is ciphertext; `ied_dkim_public_dns` is cleartext so the Setup tab
can show/verify the DNS record when no window is open. Keep `$api_readable`/`$api_writable`
default; `ied_dkim_sealed_key` matches the `_key$` credential pattern so it is auto-floored
from any API export.

## Phase 2 — In-app DKIM signing (core hook + plugin resolver)

`SmtpProvider` (core) sets no `DKIM_*` today. Keep core crypto-agnostic — mirror the File
decrypt-hook pattern from the encryption package.

### 2.1 Core hook — `includes/email_providers/SmtpProvider.php`

In `send()` (180), after `$mailer = new SmtpMailer(...)` and `applyMessage()` and **before**
`$mailer->send()` (184): consult a DKIM-signer resolver keyed on the message's From-domain
(`$message->getFrom()`). If it returns a signer descriptor, stamp:
```php
$mailer->DKIM_domain        = $sig['domain'];
$mailer->DKIM_selector      = $sig['selector'];
$mailer->DKIM_private_string = $sig['private_string'];  // in-memory only, never a file
$mailer->DKIM_identity      = $message->getFrom();
```
The resolver is a well-known, plugin-active-guarded entry (so `SmtpProvider` names no
email symbol directly — same discipline as `File::resolve_decrypt_hook()`). Returns null
for non-protected domains (opendkim signs those, or they're unsigned). The raw-relay path
`relayRawMessage()` (253) is unchanged — it carries the original sender's own signature and
is not a mailbox compose.

### 2.2 Plugin resolver — `plugins/mailbox/includes/MailboxDkimSigner.php`

`resolveFor(string $from_domain): ?array`:
1. `InboundEmailDomain::GetByDomain($from_domain)`; return null unless
   `ied_is_protected_identity`.
2. Require an **open unlock window** for the owner (`VaultUnlock::secretKey`); null →
   throw a locked-state signal so the compose path prompts one-tap unlock (levels spec's
   locked-state contract) rather than silently sending unsigned.
3. Unwrap: `SealedBox::openDek(ied_dkim_sealed_key, uev_public_key, secret_key)` → the DKIM
   private key string (openDek opens any `crypto_box_seal` blob, not only DEKs).
4. Return `['domain'=>$from_domain, 'selector'=>ied_dkim_selector, 'private_string'=>$key]`.
The key is used in-memory for the send and never written to disk / never handed to
opendkim.

## Phase 3 — Ambient-send guard (single choke point)

`EmailSender::send()` (122) is the one funnel for every non-relay send; From is on the
`EmailMessage` (`getFrom()`), default stamped at 124–126. Right after the default and
before validation (129): if the From-domain is a protected identity **and** no per-send
DKIM transport is in play (i.e. this is an ambient/transactional call, not the mailbox
compose path that reaches `send()` with an injected transport at 135), **refuse** — a
locked box's transactional code must not emit protected-domain mail. This single guard also
covers the SRS bounce generator (`handleSRSBounce`, 1169, which calls plain
`EmailSender::send()`), which Phase 5 already moves off the protected domain; the guard is
the backstop.

## Phase 4 — Remove protected domains from opendkim

- `provision_dkim.sh` is append-only today (no removal path). Add a removal mode:
  `provision_dkim.sh --remove <domain>` deletes that domain's `*@<domain> …` line from
  `/etc/opendkim/signing.table` (and its `key.table` line), then `systemctl restart
  opendkim`. opendkim keeps **verify** duty for inbound (leave `Mode sv`); only the
  domain's *signing* entry is removed.
- The enable-protection ceremony (Phase 7) calls this at cutover so opendkim stops signing
  the domain, leaving the in-app per-send signer (Phase 2) as the sole signer.
- Non-protected domains (incl. the optional automated-send subdomain, Phase 7) keep using
  `provision_dkim.sh` normally.

## Phase 5 — SRS to the forwarding subdomain

- `InboundEmailRouter::buildForwardMessage()` (~985) currently sets `$forwarding_domain =
  $domain->get('ied_domain')`. Change to the domain's `ied_forwarding_subdomain` (falling
  back to a server-wide `mailbox_forwarding_subdomain` setting). `SRSRewriter::rewrite()`
  (42) is unchanged — it already takes the domain as a parameter.
- `handleSRSBounce()` (1169): the freshly generated delivery-failure message sends from the
  forwarding subdomain, not the protected domain (it runs while locked).
- The forwarding subdomain's SPF authorizes the box; under the protected domain's `aspf=s`
  (Phase 6) that pass can never align the bare domain — so it adds no spoofing capability.

## Phase 6 — DNS check inversion (protected-domain branch)

In `InboundEmailSetupCheck::checkDomain()` (495), branch on `ied_is_protected_identity`:
- **SPF** (531–549 / `spfAuthorizes()` 977): today PASS when the box IP is authorized. For
  protected domains **invert** — PASS when `spfAuthorizes()` returns `'fail'` (box NOT in
  SPF), FAIL when `'pass'`. The suggested fix flips to an SPF that excludes the box
  (e.g. `v=spf1 -all`, or only the sending-subdomain include if used).
- **DKIM** (551–564): today compares the published record to the on-disk opendkim
  `mail.txt`. For protected domains compare the published `mail._domainkey`/selector record
  to `ied_dkim_public_dns` (the sealed key's public half) instead of the opendkim file.
- **DMARC** (566–582): today PASS if any `v=DMARC1` exists (RECOMMENDED, never parses
  policy). For protected domains **parse** and require `p=reject; aspf=s; adkim=s`, at
  **REQUIRED** severity.
- Add checks: the **forwarding subdomain's** SPF authorizes the box; the protected domain
  is **not** provider-verified (no Mailgun/SES include in its SPF — closure 4).
- `InboundEmailHealth::checkDomainDns()` (123) already fails the provisioner only on
  domain-layer REQUIRED FAILs; making protected DMARC REQUIRED makes it gate, which is
  intended.

## Phase 7 — Enable / rotate / the optional automated subdomain

All in-window (they need the unwrapped secret key to seal the DKIM key).

- **Enable protection** `logic/mailbox_protect_domain_logic.php`: generate a DKIM keypair;
  `SealedBox::sealDek(dkim_private, uev_public_key)` → `ied_dkim_sealed_key`; store the
  public half in `ied_dkim_public_dns` + a fresh `ied_dkim_selector`; show the DNS to
  publish (new DKIM selector record, tightened SPF `-all`, strict DMARC
  `p=reject; aspf=s; adkim=s`, forwarding-subdomain SPF). **Verify via the Setup tab before
  flipping `ied_is_protected_identity`.** At cutover call `provision_dkim.sh --remove` to
  stop opendkim signing the domain. Sequence so compose is never broken: publish DNS →
  verify → in-app signer live → remove opendkim signing → set the flag.
- **The automated-subdomain question (the resolved product decision).** The setup flow asks
  once: *does this domain send automated mail (lists, receipts, notifications)?* If **no**,
  done — all-or-nothing. If **yes**, guide the operator to add `mail.<domain>` as an
  **ordinary non-protected domain** (`InboundEmailDomain` row, `ied_is_protected_identity =
  false`) signed ambiently via the existing `provision_dkim.sh` — no new mechanism. Under
  the bare domain's `adkim=s` the subdomain's key can't sign the bare domain. Surface the
  human-trust-boundary note ("only the bare domain is really you") only on this opt-in path.
- **Rotate** `logic/mailbox_rotate_dkim_logic.php`: in-window, generate + seal a new key
  under a new selector, publish the new DNS record, retire the old selector, destroy any
  old on-disk opendkim key for the domain at cutover. Bump `ied_dkim_key_generation`.

## Phase 8 — Settings & docs

- Settings (`plugins/mailbox/plugin.json`): `mailbox_forwarding_subdomain`
  (server-wide default; per-domain override in `ied_forwarding_subdomain`).
- Docs (current-state voice): `plugins/mailbox/docs/overview.md` gains an "Outbound
  send protection" section (the protected-domain invariant, the DNS shape, the in-app
  signing path, the forwarding-subdomain envelope, and the optional automated subdomain);
  `docs/email_system.md` notes protected-domain From addresses are usable only via the
  session-gated mailbox compose path.

## Phase 9 — Verification (acceptance gate)

9.1 `php -l` + `validate_php_file.php` on every PHP file; `bash -n` on the modified
`provision_dkim.sh`.

9.2 On a protected test domain at `dev.getjoinery.com`:
- **Enable ceremony:** DKIM key sealed (`ied_dkim_sealed_key` ciphertext in psql), DNS
  shown, Setup tab verifies the inverted shape (SPF excludes box, DMARC strict, DKIM
  matches the sealed key's public half).
- **In-window compose:** a reply from the protected domain is DKIM-signed by the in-app
  signer; confirm Gmail's `Authentication-Results` shows `dkim=pass` with
  `relaxed/relaxed` (design open item) and the d= is the bare domain.
- **Locked compose:** with no window open, composing from the protected domain prompts
  one-tap unlock (locked-state contract), and no unsigned/ambient send escapes.
- **Ambient guard:** a transactional `EmailSender::send()` with a protected From-domain is
  refused; the same call on a non-protected domain (incl. `mail.<domain>`) succeeds.
- **opendkim:** after cutover, the protected domain has no `signing.table` line; inbound
  verify still works.
- **Automated subdomain:** `list@mail.<domain>` sends ambiently while the bare domain is
  locked; it cannot produce a bare-domain-aligned message (strict alignment).
- **Rotation:** new selector published, old retired, on-disk key destroyed.

9.3 `batcat` commands for each created/edited file (do not run them).

## Open items the executor confirms against the running system (not decisions)

- PHPMailer `DKIM_private_string` produces `relaxed/relaxed` signatures that verify at
  Gmail/Outlook (test against real `Authentication-Results`).
- The plugin-active-guarded entry point `SmtpProvider` calls for the DKIM resolver (so
  core names no email symbol) — mirror `File::resolve_decrypt_hook()`.
- Whether Mailgun/SES payload transports can carry a pre-signed raw for protected-domain
  compose, or protected-domain mail is SMTP-direct only (design open item).
- The exact From-domain accessor on `EmailMessage` (`getFrom()`) and how the injected
  transport is distinguished from an ambient call inside `EmailSender::send()`.
