# DNS Functionality Consolidation

**Status:** Implemented 2026-05-17. All five phases shipped. The §8 decisions
were resolved as recommended: system-resolver timeout behaviour accepted (§8.2);
`checkDomainDns()` kept presence-only (§8.4); the `scan_url.php` multi-IP fix
shipped inside this work (§8.5a). The DNS-rebinding fix (§8.5b) is now
**complete** for both kind-4 callers — see the §8.5b update below. Only email
*format*-validation unification (§8.6) remains deliberately open as a follow-up.
**Author:** Analysis prepared 2026-05-17
**Origin:** Surfaced during the Email Forwarding install unification work
(`specs/email_forwarding_install_unification.md`).

> **Implementation notes (2026-05-17).**
> - `DnsResolver` shipped as 2 files — `includes/DnsResolver.php` and
>   `includes/DnsLookupException.php` — plus `tests/unit/dns_resolver_test.php`.
> - One intentional behaviour refinement in `checkDomainDns()`: the old code
>   conflated a resolver failure with "no record" and so flagged every domain
>   when the local resolver hiccupped. It now distinguishes the two and
>   fails **open** on a `DnsLookupException` (kind 2, per §3) — a transient DNS
>   error no longer produces a false "DNS not configured" report.
> - `scan_url.php` gained IPv6 classification (private/reserved ranges) so the
>   multi-IP fix genuinely covers AAAA records, not just multiple A records.
>
> **DNS-rebinding follow-up — §8.5b update (2026-05-17).** Closed for both
> kind-4 callers.
> - **`FetchUrlTool` (joinery_ai) — done.** `UrlSafetyValidator` gained
>   `checkAndResolve()`, which validates the URL and returns the exact
>   resolved IPs; `check()` is now a thin wrapper over it. `FetchUrlTool`
>   pins the Guzzle connection to those IPs via `CURLOPT_RESOLVE`, so the
>   fetch cannot be rebound between the safety check and the connect. Because
>   the tool already re-validates every redirect hop, every hop is now pinned
>   too. Covered by `tests/unit/url_safety_validator_test.php`.
> - **`scan_url.php` (dns_filtering) — done.** It does not reuse
>   `UrlSafetyValidator` (that would couple the plugin to `joinery_ai`); it
>   keeps its own inline guard, `scan_url_validate_target()`, built on the core
>   `DnsResolver`. Curl's `CURLOPT_FOLLOWLOCATION` is now off and redirects are
>   walked manually — every hop, initial and redirect, is validated and the
>   connection pinned via `CURLOPT_RESOLVE`. This closes both initial-host
>   rebinding and the previously-unguarded redirect-to-internal SSRF path.
>   (An earlier note here scoped this out as "not small"; that was a
>   mis-analysis — the rebinding pin is small, and the redirect rewrite, while
>   moderate, was the right call and was done.)

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
| `includes/LibraryFunctions.php` | `IsValidEmail()` | MX, A (fallback) — email-domain validity |
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

`includes/DnsResolver.php`. A small **static** class that is the *only* place
raw DNS functions are called.

- **Methods** return normalized shapes: `getMx()` → `[['host'=>…,'pri'=>…], …]`;
  `getA()` / `getAaaa()` → `['1.2.3.4', …]`; `getTxt()` → `['v=spf1 …', …]`;
  `getCname()` → `?string`; `resolveHostIps()` → all A **and** AAAA combined
  (for SSRF). `domainAcceptsMail()` (the validation helper, §4.3) lives here
  too — it is just a lookup, not its own file.
- **Error vs empty is preserved.** A genuine "no such record" returns `[]`. A
  *resolver failure* throws `DnsLookupException`. This is the mechanism that
  lets one primitive serve both failure modes: kinds 1–3 catch the exception
  and treat it as fail-open; kind 4 catches it and treats it as fail-closed.
  The primitive itself takes no policy stance.
- **One test seam.** A static `DnsResolver::setBackend($double)` swaps the raw
  DNS layer for tests; the default path does real DNS, so production code never
  touches it. `setBackend()` accepts any duck-typed double — there is **no
  `DnsBackend` interface and no named `SystemDnsBackend`/`FixtureDnsBackend`
  classes**; the "real" path is simply `DnsResolver`'s default code. Tests reset
  the backend in teardown. Because the seam sits at the bottom of the stack,
  one `setBackend()` call makes `DnsResolver`, `DnsAuthChecker`,
  `domainAcceptsMail()`, and the SSRF path all testable — there is no per-class
  seam above it.
- **No per-request cache in v1.** Memoisation is deliberately deferred (§8.3):
  its real hit rate is low (callers query different names/record types) and it
  is the sole source of mid-request staleness. It is internal to `DnsResolver`,
  so it can be added later with zero API change *if* a real latency problem
  appears.

### 4.2 Email-auth — consolidate onto `DnsAuthChecker`

- `DnsAuthChecker` is refactored to call `DnsResolver` statically for its
  lookups. Its **public static API stays byte-for-byte stable** —
  `EmailAuthChecker` extends it and `admin_settings_email` calls it, so no
  signature may move. It needs **no seam of its own**: because `DnsResolver`
  carries the single `setBackend()` test seam (§4.1), a test that swaps the
  backend automatically makes `DnsAuthChecker` testable too. The signatures are
  untouched and no new public method is added.
- `EmailForwardingHealth::checkDomainDns()` and the
  `admin_email_forwarding_domains` badges drop their hand-rolled
  `dns_get_record()` SPF/MX parsing and call `DnsAuthChecker` / `DnsResolver`.
  This is the **correctness win**: the plugin inherits weak-policy detection it
  does not have today (see §8.4 for the semantics decision).
- `EmailForwarder`'s inbound-DKIM TXT lookup moves to `DnsResolver::getTxt()`.

### 4.3 Validation lookups — share the DNS tail only

`SystemBase`'s email rule and `LibraryFunctions::IsValidEmail()` are *not* two
copies of one function — they are two different email validators that happen to
share a DNS tail. Their **format** checks differ: `SystemBase` uses
`filter_var(…, FILTER_VALIDATE_EMAIL)`; `IsValidEmail()` uses a hand-rolled
regex that, among other things, hard-caps the TLD at 2–10 letters and rejects
IP-literal domains. The two accept different sets of addresses.

Only the **DNS portion** is genuinely identical — fail-open, MX with an
A-record fallback per RFC 5321 — and only that portion is shared here. Each
caller keeps its own format gate and, on passing it, calls the shared
`DnsResolver::domainAcceptsMail()` method (§4.1). Behaviour is unchanged
because no caller's format gate moves.

Unifying the *format* validation as well is a separate, behaviour-changing
decision — see §8.6 — deliberately not folded into this phase.

### 4.4 SSRF — keep the policy, share the lookup

`UrlSafetyValidator` and `dns_filtering/ajax/scan_url.php` adopt
`DnsResolver::resolveHostIps()`. The **private/reserved-range classification
stays in the security code** — only the lookup is shared. A `DnsLookupException`
is treated as fail-closed (block).

Note this also fixes a latent gap: `scan_url.php` currently uses
`gethostbyname()`, which returns **one IPv4 address only**. A host with
multiple A records, or any AAAA record, can today resolve past the private-IP
block. `resolveHostIps()` (all A + all AAAA) closes that.

**This does not, on its own, close DNS rebinding.** The SSRF pattern is
*resolve → classify → (later) fetch*, and the fetch re-resolves through the
system resolver — an attacker who flips the record between the two steps still
lands the connection on a private IP. Sharing the lookup does not fix that
TOCTOU; it has to be closed deliberately, and with no per-request cache (§4.1)
nothing here even attempts to. The only real fix is to make the validated
result and the actual connection the **same** resolution: the HTTP client must
connect to the validated IP (connect-by-IP / pinned host) rather than
re-resolving. That connect-by-IP change lives in the caller's HTTP client, not
in any file this spec touches — see §8.5. Until it is done, the kind-4 callers
remain DNS-rebinding-vulnerable, and that is stated here plainly rather than
letting the lookup change imply the hole is closed.

### 4.5 `server_manager` A-record checks

`ProvisionPendingSsl`, `JobCommandBuilder`, and `node_detail.php` switch their
`gethostbyname()` calls to `DnsResolver::getA()`. Behaviour is unchanged; they
gain the test seam (and the cache, if §8.3 ever adds it).

### 4.6 Explicitly NOT in scope — `dns_filtering` as a service

The `dns_filtering` plugin runs a filtering DNS **resolver service** — it
*answers* DNS queries for managed devices. Operating a resolver has nothing in
common with looking records up from PHP. The plugin is out of scope here; only
its `scan_url.php` SSRF check (§4.4) is touched.

---

## 5. Migration / phasing

Each phase is independently shippable; nothing forces a big-bang change.

1. **Build `DnsResolver` + `DnsLookupException`** — two files — with a
   `setBackend()` test seam and unit tests. No consumer changes — zero risk.
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
  shelling to `dig +time=`. The primitive standardises *result shape, fail-mode
  signalling, and testability* — not a literal timeout. See §8.2.
- **`DnsAuthChecker` public surface is load-bearing.** `EmailAuthChecker`
  extends it; the refactor must not move a single public signature.
- **Failure-mode regressions.** Every migrated caller must consciously pick
  fail-open or fail-closed (§3). This is a mandatory code-review checklist item,
  not something the primitive can enforce.
- **DNS rebinding stays open.** Consolidating the lookup does not close the
  *resolve → classify → fetch* TOCTOU for kind-4 callers (§4.4). The real fix —
  connect-by-IP in the caller's HTTP client — is outside every file this spec
  touches and is tracked as §8.5. This work must not be read as "SSRF solved";
  it is "SSRF lookup made multi-IP and testable."
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

1. **`DnsResolver` API shape — decided.** `DnsResolver` is a **static** class
   with a single `setBackend()` test seam at the bottom of the stack (§4.1).
   `DnsAuthChecker` stays static and calls it statically, with no seam of its
   own. Rejected alternative: an *instance* `DnsResolver` with constructor
   injection. That is the textbook-DI choice, but `DnsAuthChecker` must stay
   static (load-bearing public API), so its own seam would be static mutable
   state regardless — the instance form pays for instance plumbing and *still*
   ends up with a static seam. The all-static form is simpler, matches the
   platform's existing utility style, and lets one seam serve every layer.
2. **Timeouts.** Accept the system resolver's behaviour (recommended for now —
   simple, no new dependency) or shell to `dig` for a hard per-lookup timeout.
   Revisit only if a slow-DNS incident actually occurs.
3. **Per-request cache — decided: deferred.** Not built in v1 (§4.1). Low hit
   rate (callers query different names/record types), and it is the only
   source of mid-request staleness. It is internal to `DnsResolver`, so it can
   be added with zero API change if a measured DNS-latency problem actually
   appears. Until then, every lookup is fresh.
4. **`checkDomainDns()` semantics.** Keep it presence-only (just "is there an
   SPF record") and only share the *lookup* — recommended for this round — or
   upgrade it to also flag weak `+all` / `?all` policies now that
   `DnsAuthChecker` makes that free. The latter is a user-visible behaviour
   change to a provisioning check and deserves its own call.
5. **SSRF: two separate fixes, two separate decisions.**
   (a) *The multi-IP fix* — `scan_url.php`'s single `gethostbyname()` →
   `resolveHostIps()` — ships inside this work (recommended; a one-liner once
   `resolveHostIps()` exists, and it closes a real gap).
   (b) *The DNS-rebinding fix* — connect-by-IP / host-pinning in the caller's
   HTTP client (§4.4) — is **not** in any phase of this spec; it lives in the
   HTTP-fetch code of `UrlSafetyValidator`'s and `scan_url.php`'s callers, none
   of which this spec touches. Decide explicitly: scope it as a follow-up
   security spec (recommended) or pull it in here as a sixth phase. Until (b)
   is done, both kind-4 callers stay DNS-rebinding-vulnerable, and §6 says so.
   **Update (2026-05-17): (b) is now done for both `FetchUrlTool` and
   `scan_url.php` — see the §8.5b follow-up note near the top of this
   document.**

6. **Email *format*-validation unification.** §4.3 shares only the DNS tail;
   `SystemBase` and `IsValidEmail()` keep their differing format gates
   (`filter_var` vs hand-rolled regex). Unifying those onto one format check is
   a separate, user-visible behaviour change — deferred, and not recommended
   for this round. `IsValidEmail()` is confirmed still in live use (callers in
   `adm/`, `logic/`, and `data/users_class.php`), so §4.3's dedup stays
   relevant — it does not collapse to the `SystemBase` path alone.

---

## 9. Relationship to the install unification spec

`specs/email_forwarding_install_unification.md` is independent of this work and
should ship first — it is scoped and implementation-ready. Its
`domain_dns_records` provisioner calls `EmailForwardingHealth::checkDomainDns()`;
this spec changes only that method's *internals* (Phase 3), never its
provisioner contract (throws `ProvisioningCheckFailed` / returns normally). The
two specs do not conflict.
