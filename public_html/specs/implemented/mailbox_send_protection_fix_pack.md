# Mailbox — Outbound Send Protection Fix Pack

**Status:** Ready for implementation
**Fixes:** the 9 confirmed correctness defects and 5 cleanups from the high-effort review of
`specs/inbound_email_outbound_send_protection_executor.md`'s implementation.
**Design source:** `specs/mailbox_outbound_send_protection.md` (the guarantee being repaired),
`docs/sealed_vault.md` (reseal contract), `specs/implemented/mailbox_sealing_fix_pack.md`
(the fail-loud rotation doctrine this feature must honor).

## The guarantee being repaired

A domain marked a protected sending identity sends only through the session-gated mailbox
compose path, inside an open vault window, DKIM-signed in-app with a key that exists only
sealed to the owner's vault. The review found the shipped state violates this in both
directions: the legitimate path is refused (a protected domain cannot send at all), and the
resting opendkim capability survives the ceremony (the removal instruction is never shown,
and the removal script aborts on the default single-domain box).

## Fix inventory

| # | Finding | Fix | Files |
|---|---------|-----|-------|
| 1 | Guard refuses the compose path (null transport) | Protected hosted alias resolves a real SmtpProvider transport | `includes/OutboundTransport.php` |
| 2 | Ceremony flash messages never display (invalid page_regex; plugin-wide rename artifact) | Normalize DisplayMessage routing across the whole mailbox plugin | plugin logic + admin pages |
| 3 | `--remove` aborts under `set -e` on single-domain boxes | Replace grep/tmp/mv with `sed -E -i` in-place deletes | `plugins/mailbox/provisioning/provision_dkim.sh` |
| 4 | Vault reseal skips grant-less owners → permanent DKIM key loss | Restructure onReseal so the DKIM block is unconditional | `plugins/mailbox/includes/bootstrap.php` |
| 5 | Rotate cuts over to an unpublished selector, destroys old key | Staged rotation via pending-key columns; cutover only after DNS verify | domain class, protect logic, protect page |
| 6 | Activation proceeds without a forwarding subdomain → forwarding hard-fails SPF | Subdomain becomes REQUIRED for protection; ceremony collects it | `InboundEmailSetupCheck.php`, protect logic/page |
| 7 | SRS subdomain has no mail routing (no MX, Postfix rejects it) → silent DSN loss | MX in the ceremony DNS list + REQUIRED check + Postfix acceptance via the pgsql map | protect logic, `InboundEmailSetupCheck.php`, `render_pgsql_map.php` |
| 8 | Server-wide forwarding-subdomain setting leaks across tenants | Per-domain column only; remove the global setting | domain class, `plugin.json`, migration |
| 9 | Bounce notices DMARC-fail for every domain (unconditional From override) | Never override From; platform default From is the deliverable identity | `InboundEmailRouter.php` |
| C1 | DKIM plaintext key lingers in worker heap | `sodium_memzero` after send | `SmtpProvider.php` |
| C2 | `mailbox_rotate_dkim_logic.php` is unreferenced dead code | Delete it | that file |
| C3 | Version numbers not bumped on modified files | Bump every touched file | all |
| C4 | Protected-check assembly duplicated verbatim | One private assembly method | `InboundEmailSetupCheck.php` |
| C5 | Per-send DB query for protectedness, no memoization | Per-request static cache | `MailboxDkimSigner.php` |

---

## Fix 1 — The protected compose path must carry its own transport

**Defect.** Only hosted (non-IMAP) domains can be protected, and
`OutboundTransport::forHostedAlias()` resolves `transport = null` ("use the platform's
active provider"). `EmailSender::send()`'s ambient guard keys on exactly that null, so every
compose from a protected domain is refused — and even without the guard, a null transport
routes through Mailgun-class providers whose payload APIs never run the in-app DKIM signer.
The feature's happy path does not exist.

**Fix.** A protected domain's mail is sent by the box itself, DKIM-aligned, through the
SMTP pipeline that runs the in-app signer. In `OutboundTransport::forHostedAlias()`:

```php
require_once(PathHelper::getIncludePath('includes/MailIdentityGuard.php'));
if (MailIdentityGuard::isProtectedDomain(MailIdentityGuard::domainOf($aliasAddress))) {
    // A protected identity never rides the ambient provider: the box submits it
    // itself through SmtpProvider, whose send() runs the in-app DKIM signer
    // (sealed key, in-window). DMARC passes on the strict-aligned DKIM signature
    // alone — the domain's SPF is v=spf1 -all by design.
    $t->transport = new SmtpProvider(SmtpConfig::fromForwardingSettings());
    return $t;
}
$t->transport = null; // non-protected hosted alias: platform active provider, as today
```

`SmtpConfig::fromForwardingSettings()` is the box's existing outbound submission
coordinates (global SMTP settings with the `mailbox_forwarding_smtp_*` overrides) — the
same relay the forwarding path already trusts. All core symbols; no plugin names in core.

**What this restores, end to end:** compose from a protected alias → injected SmtpProvider →
`resolveDkimSigner()` unwraps the sealed key in-window and stamps `DKIM_*` → signed send;
locked window → `VaultLockedException` from the resolver propagates through
`EmailSender::send()`'s existing rethrow to `MailboxSender`'s unlock prompt. The
null-transport guard in `EmailSender::send()` is now correct as written — only genuinely
ambient calls can reach it — and needs no change.

## Fix 2 — DisplayMessage routing across the mailbox plugin

**Defect.** Two halves, both rename artifacts. Messages are saved with
`page_regex '/plugins/mailbox/admin/'` — an invalid PCRE (`mailbox/admin/` parses as
modifiers), so `preg_match()` errors and the message is dropped. And every mailbox admin
page still calls `get_messages('/plugins\/inbound_email\/admin\//')` — the pre-rename
pseudo-URL, which a *correct* mailbox regex would not match either. Net effect: no mailbox
admin flash message has displayed since the rename; the ceremony's mandatory
opendkim-removal command is the highest-stakes casualty.

**Fix.** One convention, applied plugin-wide:

- Every `save_message()` in mailbox logic uses the valid, tilde-delimited regex
  `'~/plugins/mailbox/admin/~'`.
- Every admin page calls `get_messages('/plugins/mailbox/admin/')` (the plain pseudo-URL
  the regex matches).

Sweep both sides: `grep -rn "page_regex\|'/plugins" plugins/mailbox/logic/` and
`grep -rn "get_messages(" plugins/mailbox/admin/` — fix every occurrence, not only the
protect ceremony (alias, filters, message, domains, imap, accounts, setup pages carry the
same dead pair).

## Fix 3 — `provision_dkim.sh --remove` must survive the single-domain box

**Defect.** Under `set -euo pipefail`, `grep -vE ... > tmp` exits 1 when it selects zero
lines — exactly the shipped default where the protected domain is the only signing.table
entry. The script dies before the `mv`, the key deletion, and the restart; opendkim keeps
signing "protected" mail and the operator sees an unexplained non-zero exit.

**Fix.** Replace both grep/tmp/mv blocks with in-place deletes that exit 0 regardless of
match count, keeping the same anchored patterns:

```bash
if [[ -f "${SIGNING_TABLE}" ]] && grep -qE "@${DOMAIN_RE}[[:space:]]" "${SIGNING_TABLE}"; then
    sed -E -i "/@${DOMAIN_RE}[[:space:]]/d" "${SIGNING_TABLE}"
    echo "signing.table: removed *@${DOMAIN}"
    removed_any=1
...
    sed -E -i "/\._domainkey\.${DOMAIN_RE}[[:space:]]/d" "${KEY_TABLE}"
```

No `.tmp` files to strand. Verify with `bash -n` plus a live exercise against a scratch
copy: single-entry table, multi-entry table, already-absent domain (idempotent re-run).

## Fix 4 — Vault reseal must never skip the DKIM key

**Defect.** `bootstrap.php`'s `onReseal` returns early when the user has zero mailbox alias
grants — before the protected-domain DKIM reseal block below it. A grant-less domain owner
who rotates their vault loses the DKIM key permanently *without any error*: no exception,
so the ceremony retires the old generation, exactly the silent-loss class the sealing fix
pack eliminated.

**Fix.** Restructure the callback so message resealing is conditional but DKIM resealing and
the throw are unconditional:

```php
$alias_ids = InboundEmailMailboxGrant::alias_ids_for_user($user_id);
$ids = array(); $failed = 0;
if (count($alias_ids)) {
    // ... existing message-DEK reseal loop and FTS purge, unchanged ...
}
// DKIM reseal block runs for every user — ownership of a protected domain is
// independent of mailbox grants. (Also re-seal ied_dkim_pending_sealed_key when
// non-empty — Fix 5 introduces it and it is sealed to the same vault key.)
...
if ($failed > 0 || $dkim_failed > 0) { throw ...; }
```

The FTS purge stays inside the grants branch (no grants → no index).

## Fix 5 — Rotation is staged: publish → verify → cut over

**Defect.** `rotate` shares the `generate` branch verbatim: on an enforced domain it
overwrites the live selector and sealed key immediately, before the new DNS record exists,
and destroys the old key. Every send DMARC-fails until the operator publishes and DNS
propagates; abandoning mid-rotation strands the domain with no rollback.

**Fix.** Pending-key staging, mirroring the vault ceremony's "nothing is retired until the
new state is proven" doctrine.

**Schema** (`inbound_email_domain_class.php` `$field_specifications`; applied by plugin
sync, no migration):

- `ied_dkim_pending_selector` — varchar(63), nullable
- `ied_dkim_pending_sealed_key` — text, nullable (matches the `_key$` credential pattern,
  auto-floored from API export like the live column)
- `ied_dkim_pending_public_dns` — text, nullable

**Ceremony** (`mailbox_protect_domain_logic.php`):

- `generate` — allowed only when the domain is **not enforced** (first setup, or repairing
  an unenforced half-ceremony). Writes the live columns directly, as today: nothing sends
  under this identity yet, so overwrite is harmless. When the domain is enforced, `generate`
  is refused with a pointer to `rotate`.
- `rotate` — allowed only when **enforced**. Generates and seals a new key under selector
  `mailk{generation+1}` into the **pending** columns. The live key keeps signing. The page
  shows both DKIM records: the live one (leave published) and the pending one (publish now).
- `activate_rotation` — verifies the pending selector's DNS TXT matches the pending public
  half (same matching used by `protectedDkimResult`, pointed at the pending pair). On pass:
  live columns ← pending, `ied_dkim_key_generation`++, pending cleared, flash tells the
  operator the old `mailk{n}._domainkey` TXT may be removed after propagation. On fail:
  nothing changes; the live key keeps signing.
- `cancel_rotation` — clears the pending columns. Explicit abandon path; the live key was
  never touched.

`MailboxDkimSigner::resolveFor()` reads only the live columns — a mid-rotation domain signs
with the proven key throughout. The reseal callback re-seals the pending key too (Fix 4).

## Fix 6 — A protected domain requires a forwarding subdomain

**Defect.** The forwarding-subdomain check grades a missing subdomain RECOMMENDED/WARN, and
activation blocks only on REQUIRED failures. Activate without one and the SRS envelope
falls back to the bare domain — whose prescribed SPF is `v=spf1 -all` — so every forwarded
message hard-fails SPF at the destination. Forwarding while locked is the one flow the
design says must keep working.

**Fix.** Two halves:

- `InboundEmailSetupCheck::forwardingSubdomainSpfResult()`: the missing/equal-to-bare-domain
  branch returns `REQUIRED` + `FAIL` (not RECOMMENDED/WARN). The check only runs in the
  protected-domain shape, where the subdomain is structural, not advisory. Fix text:
  "Set the forwarding subdomain on this page." (the global-setting suggestion dies with
  Fix 8).
- The protect ceremony collects it: `admin_mailbox_protect.php` gains a FormWriter form
  with one field, `ied_forwarding_subdomain` (default suggestion `fwd.{domain}` when
  empty), posting action `set_forwarding_subdomain` to the logic, which validates it is a
  proper subdomain of nothing-in-particular but **not** equal to the bare domain and not an
  apex, saves, and redirects back. The DNS records list already renders the subdomain's
  SPF record once the field is set; with Fix 7 it also renders the MX.

## Fix 7 — The forwarding subdomain must actually receive mail

**Defect.** The SRS envelope now uses `SRS0=…@fwd.example.com`, but the ceremony prescribes
only an SPF TXT for it — no MX — and Postfix's accepted-domain map
(`render_pgsql_map.php`: `SELECT ied_domain FROM ied_inbound_email_domains …`) does not
include forwarding subdomains. Remote DSNs addressed to the SRS envelope are undeliverable;
`handleSRSBounce` never fires; senders get silent mail loss.

**Fix.** Three layers, one invariant — the subdomain routes to the same box:

- **Ceremony DNS list** (`_protect_view_vars()`): alongside the subdomain's SPF TXT, an MX
  record for the subdomain pointing at the same mail host the bare domain's MX uses (the
  expected MX target `InboundEmailSetupCheck` already computes for the inbound-MX check —
  reuse that accessor rather than re-deriving).
- **Setup check**: a new REQUIRED protected-shape check, `domain.fwd_mx` — the forwarding
  subdomain's MX resolves to the expected mail host. Added to the protected-check assembly
  (single method after C4), so activation gates on it.
- **Postfix acceptance** (`render_pgsql_map.php`): the accepted-domain query becomes a
  UNION adding non-empty, distinct-from-bare `ied_forwarding_subdomain` values from
  non-deleted domains. The pgsql map is a live query — existing deployments pick the change
  up on the next `install_email.sh`-rendered map deploy; note this in the script's header
  comment. Inbound SRS bounces then flow to the router's existing catch-all → SRS-recipient
  branch → `handleSRSBounce`, unchanged.

## Fix 8 — Forwarding subdomain is per-domain, full stop

**Defect.** `forwarding_subdomain()` falls back to the server-wide
`mailbox_forwarding_subdomain` setting for **every** domain, so protecting one domain
rewrites an unrelated tenant's SRS envelope onto a foreign subdomain.

**Fix.** Delete the fallback: per-domain column, else bare domain (today's behavior).

- `inbound_email_domain_class.php::forwarding_subdomain()` drops the Globalvars branch and
  the docblock's mention of it.
- Remove the `mailbox_forwarding_subdomain` declaration from `plugin.json`.
- Data migration (data-only, allowed): delete the `mailbox_forwarding_subdomain` row from
  `stg_settings`.
- Sweep for other readers of the setting (`grep -rn mailbox_forwarding_subdomain`) — the
  setup-check fix text (Fix 6) is the known one.

## Fix 9 — Bounce notices send from the platform identity

**Defect.** `handleSRSBounce` now unconditionally overrides From to
`mailer-daemon@<SRS envelope domain>`. For non-protected domains that is an inbound domain
the ambient provider is not verified for; for protected domains the subdomain inherits the
org's `p=reject` DMARC and the ambient provider aligns neither SPF nor DKIM. Either way the
delivery-failure notice itself is rejected or junked, where the pre-change platform-domain
From was deliverable.

**Fix.** Delete the From override block entirely. `EmailSender::send()` stamps its default
From (the platform domain the ambient provider is verified for) — the pre-change,
deliverable behavior, and never a protected identity, so the ambient guard is satisfied by
construction. Keep the failed recipient context in the notice body (already present via the
parsed original).

## Cleanups

**C1 — Zeroize the unwrapped DKIM key.** In `SmtpProvider::send()`, after
`$mailer->send()` returns (both branches), when `$sig !== null`:
`sodium_memzero($sig['private_string']); sodium_memzero($mailer->DKIM_private_string);`
then set `$mailer->DKIM_private_string = ''`. Same pattern as `SealedBox`. (The resolver
cannot zeroize what it returns; the consumer owns the copy.)

**C2 — Delete `plugins/mailbox/logic/mailbox_rotate_dkim_logic.php`.** Nothing routes to
it (no view, no serve.php entry, no plugin.json reference), and Fix 5 gives rotation a real
home inside the protect ceremony. Dead file; remove.

**C3 — Version bumps.** Increment the `@version` docblock on every file this pack touches,
including the ones the feature modified without bumping: `InboundEmailSetupCheck.php`,
`InboundEmailRouter.php`, `MailboxSender.php`, `admin_mailbox_domains.php`,
`inbound_email_domain_class.php`.

**C4 — One protected-check assembly.** `InboundEmailSetupCheck`: extract the five-call
protected-shape list plus the SPF-extraction loop (duplicated verbatim between
`checkDomain()` and `protectedDomainChecks()`) into one private method both call. Fix 7's
new `domain.fwd_mx` check is added there, once.

**C5 — Memoize protectedness.** `MailboxDkimSigner::isProtected()` loads a
`MultiInboundEmailDomain` per call, and `sendBatch()` calls it per message. Add a
per-request static cache (`private static $protected_cache = []`, keyed by lowercased
domain) covering both `isProtected()` and the domain load in `resolveFor()`'s predicate
path. Request-scoped only — no invalidation surface needed.

## Docs (current-state voice)

- `plugins/mailbox/docs/overview.md` — Outbound send protection section: protected compose
  submits via the box's own SMTP transport with the in-app signer; the forwarding subdomain
  is a per-domain, required part of the protected shape with SPF + MX and Postfix
  acceptance; rotation is staged (pending selector published and verified before cutover);
  bounce notices send from the platform identity.
- `docs/email_system.md` — the protected-domain paragraph: sends ride the box's SMTP
  submission path, never the ambient provider.
- `docs/sealed_vault.md` — consumer list: the mailbox reseal callback re-seals protected
  DKIM keys (live and pending) for owners regardless of mailbox grants.

## Verification (acceptance gate)

1. `php -l` + `validate_php_file.php` on every touched PHP file; `bash -n` on
   `provision_dkim.sh`.
2. `--remove` exercised against scratch table copies: single-entry, multi-entry,
   already-absent (idempotent), similar-suffix domain untouched (`example.com` vs
   `mail.example.com`).
3. End-to-end on dev, protected test domain:
   - In-window compose from the protected alias **sends** (Fix 1), and the outbound MIME
     carries `DKIM-Signature: d=<bare domain>; s=<live selector>`.
   - Locked compose prompts the one-tap unlock; nothing sends.
   - Transactional `EmailSender::send()` with the protected From is still refused;
     a non-protected From still sends.
   - Ceremony flash messages display on the protect page and on the accounts page
     (Fix 2), including the `--remove` command after activation.
   - Activation blocks until the forwarding subdomain is set and its SPF **and MX** checks
     pass (Fixes 6–7); Postfix accepts a message addressed to `SRS0=test@<fwd subdomain>`
     (Fix 7).
   - Rotate stages a pending selector; compose still signs with the live selector;
     `activate_rotation` refuses until the pending TXT is published, then cuts over;
     `cancel_rotation` leaves the live key intact (Fix 5).
   - Vault rotation for a grant-less owner of a protected domain re-seals the DKIM key
     (compose signs afterward); a forced re-seal failure throws and retires nothing
     (Fix 4).
   - A second domain's SRS envelope still uses its own bare domain (Fix 8).
   - A simulated DSN to the SRS address produces a bounce notice From the platform
     default, and it passes the ambient provider's alignment (Fix 9).
4. Grep gates: no `mailbox_forwarding_subdomain` reader remains; no
   `'/plugins\/inbound_email\/admin\//'` remains under `plugins/mailbox/`; no invalid
   `page_regex` (every saved pattern compiles under `preg_match`).
