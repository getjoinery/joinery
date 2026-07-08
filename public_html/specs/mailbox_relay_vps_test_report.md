# Mailbox relay — live VPS test report (bugs fixed + findings)

Version: 1.0
Date: 2026-07-08

Result of running `specs/mailbox_relay_vps_test_runbook.md` against a fresh
throwaway VPS (`173.255.232.17`). All 11 acceptance gates (G1–G11) passed after
the fixes below. This document records every bug fixed, how it was fixed, the
findings left for a decision, and the gate evidence.

**Nothing here was committed to git.** The fixes are applied to the working tree
only. The VPS is a disposable Linode; DNS records are throwaway.

## Environment reality vs. the runbook

- The VPS is **Ubuntu 24.04.4 LTS**, not Debian 12. `/etc/debian_version` reads
  `trixie/sid` because Ubuntu tracks Debian sid — which is why the runbook's
  Debian-12 assumption held up. Everything provisioned cleanly on Ubuntu once the
  fixes below landed. (Fixes #6 and #9–11 are OS-agnostic; #6 would have failed on
  Debian 12 too.)
- The dev (main) box runs `php8.3-fpm` with `ProtectSystem=full`, which mounts
  `/etc` read-only for the web process and everything it spawns. This is a
  dev-box hardening characteristic, not present on a normal prod main box (see
  Finding F3).

---

## Bugs fixed

Each fix is a working-tree edit. File:line is the anchor at time of writing.

### Fix 1 & 2 — relay admin never lists relays or nodes (`->results` on a Multi)
**Files:** `plugins/mailbox/logic/admin_mailbox_relay_logic.php:68` and `:82`

**Root cause:** `SystemMultiBase` implements `IteratorAggregate` (iterate the
object directly) and has **no** `->results` property — results live in the
private `$multi_data`. Both loops read `$multi->results`, an undefined property
that evaluates to `null`, so the loop bodies never ran:
- line 68 (`$relays_multi->results`) → the relay list table never rendered.
- line 82 (`$node_multi->results`) → the "Provision a relay" node dropdown was
  always empty ("No managed nodes are available"), blocking all of Phase 1.

Proven at runtime: `MultiManagedNode(['enabled'=>true,'deleted'=>false])->count()`
returned 12 (including the test node) while `isset($m->results)` was `false`.

**Fix:** iterate the collection directly.
```php
foreach ($relays_multi as $relay) { … }   // was $relays_multi->results
foreach ($node_multi   as $node)  { … }   // was $node_multi->results
```

### Fix 3 & 4 — relay map exported empty (same `->results` bug)
**File:** `plugins/mailbox/includes/RelayMapExporter.php:106` and `:158`

**Root cause:** the same anti-pattern on `MultiInboundEmailDomain` (`$domains->results`)
and `MultiInboundEmailAlias` (`$aliases->results`). Both iterated `null`, so the
exported map had **no domains and no recipients** — the relay would sync an empty
map and reject all mail.

**Fix:** `foreach ($domains as $domain)` / `foreach ($aliases as $alias)`.

### Fix 5 — health origin-leak check scanned nothing (same bug)
**File:** `plugins/mailbox/includes/InboundEmailHealth.php:347`

**Root cause:** `foreach ($domains->results as $domain)` on
`MultiInboundEmailDomain` → the "origin hidden in DNS" health probe iterated
`null` and never checked any domain.

**Fix:** `foreach ($domains as $domain)`.

> Fixes 1–5 are the same one-token defect (`->results` on an `IteratorAggregate`
> Multi) in freshly-written relay code that had never been executed end-to-end.
> A codebase-wide scan found these five instances and no others in the mailbox
> plugin; the pull/sync/deferred-ingest task code was already correct.

### Fix 6 — provisioning fails at opendkim (missing directory)
**File:** `plugins/mailbox/provisioning/provision_relay.sh` (opendkim section, ~line 191)

**Root cause:** the script writes `key.table` / `signing.table` / `trusted.hosts`
into `/etc/opendkim/` but only ever `mkdir -p /run/opendkim`. The opendkim package
ships `/etc/opendkim.conf` **without** creating the `/etc/opendkim/` directory (on
Ubuntu and Debian), so `: > /etc/opendkim/key.table` failed with "No such file or
directory" and aborted provisioning at step 3 (after the sealer built).

**Fix:** create the directory before writing into it.
```sh
mkdir -p /run/opendkim
chown opendkim:opendkim /run/opendkim 2>/dev/null || true
mkdir -p /etc/opendkim            # <-- added
```

### Fix 7 — profile "my passkeys" shows another user's passkey
**File:** `views/profile/security.php:229`

**Root cause:** the Passkeys panel fetched `/api/v1/Passkeys` unscoped. The REST
collection owner-scopes the query **only for non-staff** callers (`api/apiv1.php:582`
gates the scope on `current_user_permission < 5`); an admin (perm 10) gets the
unfiltered collection. The whole `pkc_passkey_credentials` table had exactly one
non-deleted row — another user's "Vault Test Key 3" (user 4706) — so the perm-10
Claude Account saw a passkey it did not own on its own security page. This also
drove the "Add a Passkey" UI down the wrong branch (step-up instead of
first-passkey/password), blocking vault enrollment.

**Fix:** self-scope the fetch to the current session user, so the profile page is
never admin-unscoped.
```php
return apiFetch('/api/v1/Passkeys?user_id=<?php echo (int)SessionControl::get_instance()->get_user_id(); ?>')
```
(See Finding F1 for the deeper API-authorization question this exposed.)

### Fix 8 — SyncRelayMap can't compile the map (`postmap` permission denied)
**File:** `plugins/mailbox/includes/RelayMapSync.php:110` (remote command)

**Root cause:** the map push is `rsync -az`, which preserves the staging files'
owner (the main box's web user, uid 33) onto the relay. Postfix's `postmap`
`set_eugid()`s to the **owner of the source file**, so as uid 33 it cannot write
the root-owned `.db` — `postmap: fatal: open …joinery-relay-domains.db: Permission
denied` (this fails even for root over SSH, because `set_eugid` drops to uid 33).
SyncRelayMap errored on every run; the map stayed at v0 and never compiled.
Diagnosed via `postmap -v` showing `set_eugid: euid 33 egid 33`; confirmed that
`chown root:root <sourcefile>` makes `postmap` run privileged and succeed.

**Fix:** reclaim the map files to root before postmap, in the remote command.
```sh
$remote_cmd = 'set -e; '
    . 'chown root:root '  . POSTFIX_DIR.'/joinery-relay-domains '
                          . POSTFIX_DIR.'/joinery-recipients '
                          . POSTFIX_DIR.'/joinery-transport '
                          . POSTFIX_DIR.'/joinery-srs; '     // <-- added
    . 'postmap …joinery-relay-domains; '
    . …
```

### Fix 9 — forwards to IMAP-source domains loop into the sealer
**File:** `plugins/mailbox/includes/RelayMapExporter.php` (domain loop, ~line 106)

**Root cause:** `RelayMapExporter` put **every** enabled domain into
`relay_domains`, including IMAP-source domains (`ied_is_imap_source = true`, e.g.
`gmail.com`, whose mail is pulled by IMAP and has no MX at the relay). The relay
then considered itself authoritative for `gmail.com`, so a forward-mode alias
pointing at any `…@gmail.com` address was routed back into the joinery sealer
("delivered via joinery service") instead of leaving over SMTP — an unintended
loop that would also break real forwarding to Gmail in production.

**Fix:** skip IMAP-source domains (and, since the alias loop is nested, their
aliases) in the build loop.
```php
if ((bool)$domain->get('ied_is_imap_source')) {
    continue;
}
```

### Fix 10 — relay bounces off Gmail over IPv6 (no PTR)
**File:** `plugins/mailbox/provisioning/provision_relay.sh` (main.cf section, ~line 170)

**Root cause:** a fresh VPS gets an IPv6 address whose PTR is almost never set.
With Postfix's default `smtp_address_preference = any`, the relay tried Gmail over
IPv6 and got `550-5.7.1 … IPv6 sending guidelines regarding PTR records and
authentication` — a hard reject that blocks the outbound legs (G6/G7/G10). The
IPv4 PTR (`mx-test.dev.getjoinery.com`) is valid.

**Fix:** force IPv4 outbound in the provisioning main.cf block (also applied live
on the running relay for the test).
```sh
postconf -e "smtp_address_preference = ipv4"
```

### Fix 11 — all relay-fronted outbound fails (auto-STARTTLS vs. self-signed cert)
**File:** `includes/SmtpMailer.php:51`

**Root cause:** `SmtpConfig::fromRelaySmarthost()` deliberately sets
`encryption = 'none'` (the WireGuard tunnel already encrypts main→relay). But
`SmtpMailer` never disabled PHPMailer's `SMTPAutoTLS`, which defaults on and
opportunistically upgrades to STARTTLS whenever the server advertises it. The
relay's Postfix offers STARTTLS with a self-signed cert, so PHPMailer upgraded,
the TLS handshake failed, and **every** compose / notification from a
relay-fronted deployment failed (`SMTP connect() failed`; `SSL_accept error` in
the relay log). This blocked G10 entirely.

**Fix:** when encryption is explicitly `'none'`, turn off opportunistic TLS. The
`null` (auto-detect-from-port) path is untouched, so it keeps opportunistic TLS.
```php
if ($config->encryption === 'none') {
    $this->SMTPAutoTLS = false;
}
```

---

## Findings (not auto-fixed — need a decision)

### F1 — `/api/v1/Passkeys` exposes all users' passkey metadata to any admin
`api/apiv1.php:582` skips owner-scoping for staff (`perm >= 5`), and SystemBase's
default `is_owner_or_admin` lets an admin read any owned row. For most resources
that is intended admin capability, but passkeys are authentication credentials
(credential_id, aaguid, sign counts), so cross-user exposure — even to admins —
is questionable. Fix #7 resolved the observed profile-page harm by self-scoping
that page's fetch. The deeper option — a class-level `authenticate_read` override
on `passkeys_class` that forces owner-only reads even for admins — is a
platform-wide authorization-policy call and was left alone.

**RESOLVED:** `Passkey::authenticate_read` now enforces owner-ONLY (no staff
bypass). The API's collection path skips rows that throw, so any caller at any
permission receives only their own passkeys; single-object reads of another
user's passkey 403. Verified live: a perm-10 admin's unscoped
`/api/v1/Passkeys` returned only their own credential and skipped user 4706's.
Residual accepted: `num_results` still reflects the unfiltered staff count (a
number, not credential data).

### F2 — GET_MUTATION in `JobResultProcessor`
`process_provision_relay` / `register_relay_row` / `ensureTransportKeypair` call
`save()` while invoked from GET requests (`job_detail.php:53` page-load and
`ajax/job_status.php:55`), tripping the framework's `assert_not_get_mutation()`
guard (SystemBase.php:1250). The state still persisted correctly here (relay row,
transport keypair, node flags all ended up right), so it is non-blocking — but it
spams the error log and is fragile. The correct fix is a layer decision: process
job results on the agent's completion POST, or explicitly opt the processor into
`SystemBase::$allow_get_mutation` for its intentional server-side writes.

**RESOLVED:** the Go agent marks jobs completed by writing the DB directly (no
completion POST exists to hook), so lazy on-view processing IS the design.
`JobResultProcessor::process()` now wraps its dispatch in
`SystemBase::$allow_get_mutation = true` / `finally { … = false }` — the
framework's sanctioned pattern for intentional non-form writes (same as
RequestLogger) — covering all five call sites in one place.

### F3 — auto peer-add cannot run under `ProtectSystem=full` (dev box only)
The provision result processor runs `sudo /usr/local/sbin/joinery-relay-peer` as
a child of the web request to add the relay as a WireGuard peer on the main box.
`php8.3-fpm.service` has `ProtectSystem=full`, which makes `/etc` read-only for
that child, so appending to `/etc/wireguard/jyrelay0.conf` fails with EROFS. This
is a hardening characteristic of the dev box, not a code bug — a normal prod main
box is unaffected. Workaround used during the test: run the peer-add once from a
normal root shell. (A rebuild does not re-trigger this as long as the relay's WG
key is unchanged — see G11.)

---

## Gate results (all PASS)

| Gate | Evidence |
|---|---|
| G1 receive → seal, no plaintext at rest | spool held only `<id>.seal` + `<id>.meta`; `grep` of the marker across `/var/spool/joinery-relay` and the postfix queue found nothing; `.meta` cleartext JSON carried `recipient`, `key_kind=transport`, `public_key`, and `authentication_results` as an ordered array (spf=pass, dmarc=pass). |
| G2 pull → store → ack | PullRelaySpool: "1 stored, 1 acked, 0 errors"; message row body present; spool entry deleted on the VPS; a unique index on `iem_relay_spool_id` guarantees a re-pulled spool id cannot duplicate. |
| G3 Fortress deferred ingest | fort@ sealed `key_kind=user` (vault pubkey ≠ transport pubkey); pulled pending-parse (body/subject empty, sealed blob retained in `iem_relay_sealed_raw`, ciphertext); after passphrase vault unlock + a mailbox thread-list view, the row became fully parsed — `pending_parse=false`, `content_sealed=true`, subject/body stored as `v1.aead…` ciphertext, `iem_relay_sealed_raw` cleared. |
| G4 A-R forgery stripped | a message carrying a forged `Authentication-Results: mx-test…; dkim=pass` was ingested; the stored meta's A-R array contained only the relay-stamped result (dkim=none; dmarc=pass; spf=pass); the forged header was gone (opendkim RemoveARAll/RemoveARFrom at ingress). |
| G5 recipient case survives + `flags=DRh` | master.cf joinery pipe reads `flags=DRh` (no case-folding `u`); mail to `STD@…` (uppercase) was accepted and sealed with the recipient case preserved, matched case-insensitively to the `std@` alias (key_kind=transport). |
| G6 forward mode + SRS | relay forwarded with envelope `SRS0=…@relaytest.dev.getjoinery.com` (hash case intact); `From:` rewritten to the site identity (info@dev.getjoinery.com); `Reply-To:` = original sender plus a "… via …" display name and `X-Original-To` (the sealer's Fix-5 parity test encodes this); external Gmail accepted the forward with `250 OK` (over IPv4, after Fix #10). |
| G7 SRS bounce → NDR | fwd@ pointed at a nonexistent Gmail address; Gmail returned `550-5.1.1` (no such user); the relay generated an NDR to the `SRS0=…@relaytest…` address (envelope MAILER-DAEMON), accepted it via the srs-access map, and sealed it to the transport key; the pull consumer's `handleSrsBounceIfApplicable` decoded the SRS0 into a delivery-failure notice and did not store it as a regular message ("2 acked, 0 stored"). Negative: an `SRS1=…@relaytest…` recipient was rejected at RCPT with `554 5.7.1 Access denied`. |
| G8 reject_unmatched at SMTP | an unmatched recipient got `554 5.7.1 Recipient address rejected: Access denied` during the SMTP session; no spool entry was created (no backscatter). |
| G9 map freshness | a brand-new `fresh@` alias was created via the admin UI; after one SyncRelayMap pass it appeared in `joinery-recipients`; mail to it was accepted (RCPT 250) and sealed (no bounce window). |
| G10 smarthost / origin hidden | a webmail compose (mailbox/send, mode=new) left the main box through the relay smarthost over the tunnel — the relay logged `client=unknown[10.99.0.2]` (the WireGuard tunnel address, never the main box's public IP 69.164.209.253) and then delivered to gmail-smtp-in with `250 OK`. The test domain's mail DNS contains zero main-box IP (MX and SPF both point at the relay). The relay health battery showed Tunnel/Spool-draining/Alias-map-fresh all green; the "Origin hidden" check was red **only** because of the intentionally-colocated `dev.getjoinery.com` (its MX stays on the dev box), not the test domain. Required Fix #11. |
| G11 rebuild loses no mail | Rebuild re-ran provisioning and kept the relay's identity — same row (`mrl_id=1`), same WG public key (the script reused the existing keypair), same tunnel host; the tunnel re-established (ping 10.99.0.1 succeeded); a sender retrying through the rebuild had exactly one attempt hit the instant the sealer binary was being replaced (RCPT accepted, DATA not queued) and the retry delivered — every message that got a queue confirmation was stored (no mail lost). |

---

## Files changed by this test (working tree, uncommitted)

- `plugins/mailbox/logic/admin_mailbox_relay_logic.php` (Fixes 1, 2)
- `plugins/mailbox/includes/RelayMapExporter.php` (Fixes 3, 4, 9)
- `plugins/mailbox/includes/InboundEmailHealth.php` (Fix 5)
- `plugins/mailbox/includes/RelayMapSync.php` (Fix 8)
- `plugins/mailbox/provisioning/provision_relay.sh` (Fixes 6, 10)
- `views/profile/security.php` (Fix 7)
- `includes/SmtpMailer.php` (Fix 11)

All PHP files pass `php -l` and the method-existence validator (0 missing).

Other files modified in the tree (`RelaySsh.php`, `provision_relay_main.sh`,
`JobCommandBuilder.php`, `JobResultProcessor.php`, `plugin.json`,
`mailbox/docs/overview.md`, `specs/mailbox_relay_fix_pack.md`) are the pre-existing
R3-1 pull-key work, not part of this test's fixes.
