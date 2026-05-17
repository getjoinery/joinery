# DNS Functionality Consolidation

**Status:** Proposal — undecided. Presents the inventory and a layered plan;
open decisions are in §8.
**Author:** Analysis prepared 2026-05-17
**Origin:** Surfaced during the Email Forwarding install unification work
(`specs/email_forwarding_install_unification.md`).

---

## 1. Background

DNS lookups happen in ~13 files spread across core and four plugins. A partial
core abstraction already exists — `includes/DnsAuthChecker.php`, for
SPF/DKIM/DMARC — and the core email settings page uses it correctly. But the
plugins largely bypass it, calling `dns_get_record()` / `gethostbyname()`
directly and re-implementing record parsing each time. The result:

- The same MX/A email-validation lookup is duplicated near-verbatim in
  `SystemBase` and `LibraryFunctions`.
- `email_forwarding` hand-rolls SPF parsing that `DnsAuthChecker::checkSPF()`
  already does — and does *better* (it detects weak `+all` / `?all` policies;
  the plugin's version only checks presence).
- No DNS call anywhere is mockable, so none of these checks are unit-testable.
- Failure handling is ad hoc: most callers fail-open, the SSRF callers must
  fail-closed, and nothing enforces or documents which is which.

This spec inventories what exists, then proposes a **layered** consolidation —
deliberately *not* a single "DNS library", because the uses have incompatible
failure semantics (§3).

---

## 2. Inventory

### 2.1 Core

| File | Symbol | DNS operation |
|---|---|---|
| `includes/DnsAuthChecker.php` | `quickCheck`, `checkSPF`, `checkDKIM`, `checkDMARC` | TXT/CNAME — SPF/DKIM/DMARC verification |
| `includes/LibraryFunctions.php` | `isValidEmail()` | MX, A (fallback) — email-domain validity |
| `includes/SystemBase.php` | email form-validation rule | MX, A (fallback) — duplicate of the above |
| `utils/email_setup_check.php` | `EmailAuthChecker` (extends `DnsAuthChecker`) | TXT/MX/AAAA — deep-dive email-auth tool |

### 2.2 Plugins

| File | Symbol | DNS operation |
|---|---|---|
| `email_forwarding/includes/EmailForwardingHealth.php` | `checkDomainDns()` | MX, TXT(SPF) — provisioning check, hand-rolled |
| `email_forwarding/includes/EmailForwarder.php` | DKIM verify (~line 486) | TXT — inbound DKIM public-key lookup |
| `email_forwarding/admin/admin_email_forwarding_domains.php` | DNS badges (~151–207) | MX, TXT(SPF/DKIM) — admin status badges, hand-rolled |
| `joinery_ai/includes/UrlSafetyValidator.php` | SSRF guard (~131–146) | A (`gethostbynamel`) + AAAA — resolve host to IPs |
| `dns_filtering/ajax/scan_url.php` | URL scan (~line 49) | A (`gethostbyname`) — resolve host, block private IPs |
| `server_manager/tasks/ProvisionPendingSsl.php` | SSL readiness (~line 94) | A — domain must resolve to node IP |
| `server_manager/includes/JobCommandBuilder.php` | Cloudflare detect (~line 1209) | A — is the domain on a Cloudflare IP |
| `server_manager/views/admin/node_detail.php` | SSL setup card (~749) | A — DNS-matches-node check |

### 2.3 The one existing abstraction

`DnsAuthChecker` (core, `includes/`) is already a reusable SPF/DKIM/DMARC
checker — single shared class, used correctly by `adm/admin_settings_email.php`
and extended by `EmailAuthChecker`. It is the seed of the consolidation, not a
thing to replace. Its weakness is only that it owns its own `dns_get_record()`
calls (untestable) and that the plugins do not use it.

---

## 3. Four kinds of DNS use — why "one library" is wrong

The 13 call sites are not one operation. They fall into four kinds, and the
**failure semantics differ**:

| Kind | Examples | On lookup failure |
|---|---|---|
| 1. Email-auth checking | SPF/DKIM/DMARC verification | fail-open (assume not-configured / unknown) |
| 2. Provisioning checks | "does this domain's DNS point here" — MX, SSL A-record | fail-open (don't flag a false failure) |
| 3. Validation lookups | MX/A check behind `isValidEmail()` | fail-open (RFC 5321 — accept on doubt) |
| 4. SSRF security resolution | host → all IPs, block private ranges | **fail-closed** (any doubt → block) |

Kinds 1–3 fail **open**; kind 4 must fail **closed**. A single "DNS library"
with one default invites a caller to inherit the wrong one — quietly opening an
SSRF hole, or quietly breaking email acceptance. The correct shape is therefore
a **shared lookup primitive** with **per-caller policy**, not one library that
bakes in a failure mode.

---

## 4. Proposal

### 4.1 New core primitive — `DnsResolver`

`includes/DnsResolver.php`. A small instance class that is the *only* place raw
DNS functions are called.

- **Methods** return normalized shapes: `getMx()` → `[['host'=>…,'pri'=>…], …]`;
  `getA()` / `getAaaa()` → `['1.2.3.4', …]`; `getTxt()` → `['v=spf1 …', …]`;
  `getCname()` → `?string`; `resolveHostIps()` → all A **and** AAAA combined
  (for SSRF).
- **Error vs empty is preserved.** A genuine "no such record" returns `[]`. A
  *resolver failure* throws `DnsLookupException`. This is the mechanism that
  lets one primitive serve both failure modes: kinds 1–3 catch the exception
  and treat it as fail-open; kind 4 catches it and treats it as fail-closed.
  The primitive itself takes no policy stance.
- **Injectable backend.** The constructor accepts an optional backend; the
  default does real DNS, tests pass a fixture backend. This is the single
  change that makes every DNS-dependent check unit-testable for the first time.
- **Per-request cache.** Lookups are memoised for the request — the admin
  domains page alone checks MX + SPF + DKIM for each domain, three calls that
  collapse to the records actually needed.

### 4.2 Email-auth — consolidate onto `DnsAuthChecker`

- `DnsAuthChecker` is refactored to perform its lookups through `DnsResolver`.
  Its **public static API stays byte-for-byte stable** — `EmailAuthChecker`
  extends it and `admin_settings_email` calls it, so the surface must not move.
- `EmailForwardingHealth::checkDomainDns()` and the
  `admin_email_forwarding_domains` badges drop their hand-rolled
  `dns_get_record()` SPF/MX parsing and call `DnsAuthChecker` / `DnsResolver`.
  This is the **correctness win**: the plugin inherits weak-policy detection it
  does not have today (see §8.4 for the semantics decision).
- `EmailForwarder`'s inbound-DKIM TXT lookup moves to `DnsResolver::getTxt()`.

### 4.3 Validation lookups — de-duplicate

The MX/A email-domain check is currently copied into both `SystemBase` and
`LibraryFunctions`. Both call one shared method (on `DnsResolver`, or a thin
validation helper that wraps it). Behaviour is unchanged — fail-open, MX with
an A-record fallback per RFC 5321.

### 4.4 SSRF — keep the policy, share the lookup

`UrlSafetyValidator` and `dns_filtering/ajax/scan_url.php` adopt
`DnsResolver::resolveHostIps()`. The **private/reserved-range classification
stays in the security code** — only the lookup is shared. A `DnsLookupException`
is treated as fail-closed (block).

Note this also fixes a latent gap: `scan_url.php` currently uses
`gethostbyname()`, which returns **one IPv4 address only**. A host with
multiple A records, or any AAAA record, can today resolve past the private-IP
block. `resolveHostIps()` (all A + all AAAA) closes that.

### 4.5 `server_manager` A-record checks

`ProvisionPendingSsl`, `JobCommandBuilder`, and `node_detail.php` switch their
`gethostbyname()` calls to `DnsResolver::getA()`. Behaviour is unchanged;
they gain the cache and the test seam.

### 4.6 Explicitly NOT in scope — `dns_filtering` as a service

The `dns_filtering` plugin runs a filtering DNS **resolver service** — it
*answers* DNS queries for managed devices. Operating a resolver has nothing in
common with looking records up from PHP. The plugin is out of scope here; only
its `scan_url.php` SSRF check (§4.4) is touched.

---

## 5. Migration / phasing

Each phase is independently shippable; nothing forces a big-bang change.

1. **Build `DnsResolver` + `DnsLookupException`** with a fixture backend and
   unit tests. No consumer changes — zero risk.
2. **Refactor `DnsAuthChecker`** to use `DnsResolver` internally; public API
   unchanged. Covered by existing email-auth callers + new tests.
3. **Migrate `email_forwarding`** — `checkDomainDns()`, the admin domain
   badges, and `EmailForwarder`'s DKIM lookup. The correctness win lands here.
4. **De-duplicate validation** — `SystemBase` + `LibraryFunctions` onto one
   shared method.
5. **Migrate SSRF + `server_manager`** — `resolveHostIps()` / `getA()`; ship
   the `scan_url.php` SSRF fix (§4.4) with it.

---

## 6. Edge cases & risks

- **No true per-lookup timeout.** PHP's `dns_get_record()` uses the system
  resolver and has no per-call timeout; a real timeout knob would require
  shelling to `dig +time=`. The primitive standardises *result shape, caching,
  fail-mode signalling, and testability* — not a literal timeout. See §8.2.
- **`DnsAuthChecker` public surface is load-bearing.** `EmailAuthChecker`
  extends it; the refactor must not move a single public signature.
- **Failure-mode regressions.** Every migrated caller must consciously pick
  fail-open or fail-closed (§3). This is a mandatory code-review checklist item,
  not something the primitive can enforce.
- **Per-request cache staleness.** A domain's DNS could change mid-request;
  acceptable — request lifetimes are seconds, and every current caller already
  tolerates a slightly stale answer.
- **Behaviour change risk in `checkDomainDns()`** if it adopts weak-policy
  detection rather than presence-only — see §8.4.

---

## 7. Documentation

Per project convention, developer docs go into existing `/docs/` files:

- `docs/validation.md` — add `DnsResolver` (the lookup primitive) and the
  unified email-domain MX/A validation method.
- `docs/email_system.md` — note that `DnsAuthChecker` now sits on `DnsResolver`
  and is the one place SPF/DKIM/DMARC checks should be made; cross-reference it
  from the `email_forwarding` overview.

---

## 8. Open questions / decisions

1. **`DnsResolver` API shape.** Instance class with an injectable backend
   (recommended — it is what makes tests possible) versus a static facade
   matching `DnsAuthChecker`'s existing style. The two can coexist: an instance
   primitive with `DnsAuthChecker` keeping its static methods on top.
2. **Timeouts.** Accept the system resolver's behaviour (recommended for now —
   simple, no new dependency) or shell to `dig` for a hard per-lookup timeout.
   Revisit only if a slow-DNS incident actually occurs.
3. **Per-request cache.** Include it in `DnsResolver` from the start
   (recommended) or add later.
4. **`checkDomainDns()` semantics.** Keep it presence-only (just "is there an
   SPF record") and only share the *lookup* — recommended for this round — or
   upgrade it to also flag weak `+all` / `?all` policies now that
   `DnsAuthChecker` makes that free. The latter is a user-visible behaviour
   change to a provisioning check and deserves its own call.
5. **The `scan_url.php` SSRF fix.** Ship it inside this work (recommended — it
   is a one-liner once `resolveHostIps()` exists and closes a real gap) or
   split it into a standalone security fix.

---

## 9. Relationship to the install unification spec

`specs/email_forwarding_install_unification.md` is independent of this work and
should ship first — it is scoped and implementation-ready. Its
`domain_dns_records` provisioner calls `EmailForwardingHealth::checkDomainDns()`;
this spec changes only that method's *internals* (Phase 3), never its
provisioner contract (throws `ProvisioningCheckFailed` / returns normally). The
two specs do not conflict.
