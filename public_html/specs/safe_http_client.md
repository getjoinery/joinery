# SafeHttpClient — one SSRF-safe outbound HTTP path

**Status:** OPEN — proposed. Grew out of the Joinery Direct Mail review (finding F1),
but stands on its own: the platform's outbound-request surface is broad and its one
SSRF guard is used in two places. This spec makes "the safe fetch" the easy default
instead of a per-callsite discipline nobody keeps.

**Relationship to other specs:** `specs/joinery_direct_mail_review.md` F1 references this
document. Direct Mail's outbound preflight becomes one consumer of the helper defined
here rather than carrying its own bespoke guard.

---

## The problem in one paragraph

Reaching out to a URL is dangerous when any part of the destination is chosen by
someone other than a trusted operator: a request parameter, a database row a low-priv
user can write, or a remote party's DNS record. If the fetch is unguarded, that party
can point the server at `127.0.0.1`, `169.254.169.254` (cloud metadata), an internal
admin panel, or an arbitrary port, and — where the response is echoed back — read the
result. This is server-side request forgery (SSRF). The platform already has a correct
guard, `includes/UrlSafetyValidator.php`, but it is wired into only two callers. Every
other outbound callsite hand-rolls curl / `file_get_contents` / `fsockopen` with no
host or IP validation. There is no shared, safe-by-default HTTP client, so safety is
opt-in and mostly opted out.

## What already exists (reuse, do not rebuild)

`UrlSafetyValidator::checkAndResolve($url, $opts)` already does the hard part correctly:

- scheme allowlist (http/https), port policy (default `[80, 443]`, `allowed_ports => null`
  to permit any), hostname-literal rejection;
- DNS resolution to **all** A/AAAA records, each checked against a single authoritative
  private/loopback/link-local/reserved CIDR table (`0.0.0.0/8`, RFC1918, `169.254/16`,
  CGNAT, multicast, reserved) plus PHP's `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` for
  IPv6;
- returns `{host, port, ips[]}` so the caller can pin the connection to the exact
  validated IPs via `CURLOPT_RESOLVE`, closing the resolve→connect DNS-rebinding window;
- `checkIp()` is public so redirect targets can be re-validated per hop.

The canonical safe consumer is `FetchUrlTool::fetchWithRedirects()`
(`plugins/joinery_ai/recipe_tools/FetchUrlTool.php:109`): redirects disabled at the
client, walked manually, `checkAndResolve()` re-run on **every** hop, connection pinned
to the validated IPs each time. That loop is exactly what this spec turns into a shared,
reusable component so it is not re-derived (or mis-derived) at each callsite.

**Nothing about the validator changes.** This spec adds a client wrapper around it.

## Goals

1. One class every outbound HTTP caller can use where safety is the default, not a
   checklist item. Pinning, per-hop re-validation, redirect policy, port policy, scheme
   policy, and timeouts are built in.
2. Migrate the callsites whose destination is influenced by anyone below a trusted
   operator onto it. Leave hardcoded-vendor-endpoint callers alone.
3. Give Direct Mail (and any future feature that fetches an externally-named URL) a
   ready consumer instead of a new guard.

## Non-goals

- Not a general HTTP abstraction, retry framework, or connection pool. It is a safety
  wrapper; feature-rich clients (Guzzle, the vendor SDKs) stay where they legitimately
  talk to fixed vendor hosts.
- Does not change `UrlSafetyValidator`'s policy tables or behavior.
- Does not touch signature-gated or hardcoded-endpoint callers (see the LEAVE list).

## The component

`includes/SafeHttpClient.php` — `class SafeHttpClient`. Backed by curl (present in this
build; the AI fetch path already relies on it). One instance is configured with a
policy; each request re-applies it.

### Construction / policy

```php
$client = new SafeHttpClient([
    'allowed_ports'    => [443],   // default [80, 443]; null = any (rarely correct)
    'allow_redirects'  => false,   // default false; when true, capped and re-validated per hop
    'max_redirects'    => 3,       // only consulted when allow_redirects = true
    'connect_timeout'  => 5,
    'timeout'          => 15,
    'max_response_bytes' => 5_000_000, // hard cap; abort the transfer past it
    'user_agent'       => 'Joinery/SafeHttpClient',
]);
```

### Methods

- `get(string $url, array $headers = []): SafeHttpResponse`
- `post(string $url, string $body, array $headers = []): SafeHttpResponse`
- `request(string $method, string $url, ?string $body, array $headers): SafeHttpResponse`

`SafeHttpResponse` exposes `status`, `headers`, `body`, and `final_url`. On a blocked
URL every method throws `UnsafeUrlException` (the validator's existing exception type);
network/HTTP transport failures throw a `SafeHttpException`.

### What each request does, always

1. `UrlSafetyValidator::checkAndResolve($url, ['allowed_ports' => $policy])` before any
   socket is opened.
2. Pin the connection to the returned IPs via `CURLOPT_RESOLVE` (real hostname still used
   for SNI, Host header, and cert verification).
3. **Redirects off by default.** `CURLOPT_FOLLOWLOCATION` is never set on the curl
   handle. When `allow_redirects` is true, redirects are walked manually — each hop's
   `Location` re-run through step 1 and re-pinned — reusing the `FetchUrlTool` loop,
   never delegated to curl (curl re-resolves and would reopen the rebinding window).
4. TLS verification always on (`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`). No per-call
   "insecure" flag; a caller that truly needs to skip verification (self-signed node
   cert) documents why at its own callsite and does not use this client.
5. Response size capped; the transfer aborts past `max_response_bytes` rather than
   buffering unbounded attacker-controlled content.

### Redirect policy rationale

Following redirects is how a host-pinned or allowlisted fetch gets escaped: the first
hop passes the guard, the `Location` header sends the client somewhere internal. Default
off. Callers that must follow (the two admin diagnostic tools, which mimic a real
browser fetch) opt in, and get per-hop re-validation for it.

## Migration — who moves onto it

Grouped by who controls the destination. Only the first two groups move; the reasoning
is that the guard's cost is only justified where the destination is not already
operator-fixed.

### MIGRATE NOW — destination influenced below the trusted-operator line

| Callsite | Destination | Policy on the new client |
|---|---|---|
| `utils/cache_benchmark.php:25` | `$_GET['url']` (live request param) | `allowed_ports=[80,443]`, `allow_redirects=true` (it benchmarks a real fetch), read-back stays |
| `adm/admin_static_cache.php:170` → `includes/StaticPageCache.php:943` | `diagnose_url` request param | same; strong read-back (headers+body) is the whole point, so pinning matters most here |
| **Direct Mail outbound preflight** (per `joinery_direct_mail.md`) | SRV target host+port for a recipient domain, chosen by a remote party | port = 443 or ≥ 1024 (D1, resolved), `allow_redirects=false` |

The two admin tools are permission-8/9 gated, so this is authenticated-admin SSRF, not
open — but the destination is a **live parameter**, so a CSRF or reflected-XSS against an
admin session turns either into an internal-network probe with read-back. They are the
two existing callers worth fixing as SSRF. Direct Mail is the one genuinely new,
externally-triggerable vector and the reason the whole surface got a second look.

### MIGRATE OPPORTUNISTICALLY — superadmin-configured destination

These take a URL/host from a setting or DB row only a permission-10 superadmin can write
(node health/site URLs, relay hosts, rspamd controller, cloud endpoints, `upgrade_source`).
Someone who can set these already controls the box, so practical SSRF risk is low; move
them as each is touched, not in a rush. Representative set (not exhaustive — the full list
is in the review audit):

- `plugins/mailbox/includes/FleetClient.php:221,224`
- `plugins/mailbox/includes/MailboxSpamPolicy.php:207-221` (+ consumers in
  `InboundEmailRouter`, `tasks/LearnSpamFeedback.php`)
- `plugins/server_manager/logic/refresh_node_status_logic.php:65`,
  `tasks/RunNodeUptimeChecks.php:186,251,504`,
  `includes/JobCommandBuilder.php:168,259,337`, `includes/JobResultProcessor.php:478`
- `plugins/dns_filtering/includes/ScrollDaddyApiClient.php:74` and the querylog/test/scan
  logic that consumes the same setting
- `includes/cloud_storage/*` connection-test probes, `includes/S3Signer.php` endpoint

Two of these legitimately need policy the default forbids and must configure the client
explicitly rather than bypass it: node checks that follow redirects (opt in, capped) and
per-node checks that skip TLS verification for self-signed certs (these keep their own
curl and document why — they are out of scope for the safe client).

### LEAVE — hardcoded vendor endpoint, no user/admin influence

Stripe, PayPal, Mailgun, SES (host-pinned to `sns.<region>.amazonaws.com` **and**
signature-gated before any fetch), Backblaze, Cloudflare, the DNS driver `API_BASE`
constants, the OAuth2 provider registry (static classes — there is no dynamic
issuer/discovery fetch), LLM provider base URLs, hCaptcha/reCAPTCHA verify. These
concatenate a path onto a compile-time constant; the host cannot be moved. No migration.

## Separate line item — `utils/upgrade.php` catalog trust

Not SSRF, flagged here so it is not lost: `utils/upgrade.php` fetches a catalog JSON from
`upgrade_source` and then downloads and extracts archives from URLs **that JSON supplies**
(`:576`, `:1985`), over the live tree. That is second-order, remote-controlled code
delivery — a supply-chain / integrity concern, not a request-forgery one. It belongs to
the publish-integrity work, not this helper. Tracked, out of scope here.

## Hardening also worth doing (adjacent, small)

`includes/email_providers/SesProvider.php:539,623` fetches SNS URLs with
`file_get_contents`, which follows redirects by default. The path is signature-gated and
host-pinned, so it is not a live SSRF, but an open redirect on a real AWS SNS host would
escape the pin. One-line fix: a stream context with `follow_location => 0`. Does not need
the full client; noted so it rides along.

## Test plan

New suite `tests/security/safe_http_client_test.php` (db tier — needs DNS resolution):

- blocks each private/reserved range via a hostname that resolves to it (rebinding case:
  one public + one private A record → refuse);
- blocks non-80/443 ports under the default policy; honors an explicit `allowed_ports`;
- refuses `file://`, `gopher://`, `dict://`, and other non-http(s) schemes;
- with `allow_redirects=false`, a 302 to an internal host is returned as a redirect
  status, never followed;
- with `allow_redirects=true`, a redirect chain to an internal host is refused at the hop
  that resolves inward, and the hop cap is enforced;
- pins to the validated IP (assert `CURLOPT_RESOLVE` is set for the resolved host);
- aborts past `max_response_bytes`.

Plus a regression assertion for the two migrated admin tools: a `diagnose_url` /
`?url=` pointing at `169.254.169.254` or `127.0.0.1:22` is refused.

## Build steps

1. `includes/SafeHttpClient.php` + `SafeHttpResponse` / `SafeHttpException`, wrapping the
   validator and reusing the `FetchUrlTool` redirect-walk loop.
2. Migrate the three MIGRATE-NOW callsites; keep their read-back behavior, add the guard.
3. SES `follow_location => 0` one-liner.
4. Tests above; run `safe` after edits, `db` before checkin.
5. Add a short **Security** doc entry describing the safe outbound path **only once the
   class exists** (docs describe current state — no doc for unbuilt behavior). Update the
   `UrlSafetyValidator` header note to point callers at `SafeHttpClient` as the default
   consumer.
6. Migrate the OPPORTUNISTIC tier as those files are next touched; do not big-bang it.

## Open decisions

- **D1 — Port policy for Direct Mail. RESOLVED:** the Direct transport permits **443 or
  any port ≥ 1024**; privileged ports below 1024 (other than 443) are refused. Rationale:
  the Direct design deliberately allows a deployment to run a dedicated listener on a
  non-443 port later (`joinery_direct_mail.md` → *The capability record*, "What the
  advertised port keeps open"), so 443-only would foreclose a stated goal; blocking
  privileged ports removes the SSH/SMTP/DNS-class SSRF targets at no cost to legitimate
  listeners; and mandatory TLS verification neutralizes cross-protocol abuse at any
  allowed port. Redirects off, IP-pinned, private/reserved blocked, as for every caller.
  The client expresses this as `allowed_ports` allowing 443 plus a ≥ 1024 floor (a list
  cannot enumerate an open range, so the port policy accepts a `{allow: [443], min:
  1024}`-style form as well as a plain list).
- **D2 — One shared instance or per-caller construction?** Leaning per-caller
  construction (policy varies: redirects on for the admin tools, off for Direct Mail), no
  singleton.
- **D3 — Do the two node-check callers that need redirect-following or TLS-skip move onto
  the client (with opt-in flags) or stay bespoke?** Leaning: redirect-following moves
  (the client supports it); TLS-skip stays bespoke and documented, since baking an
  insecure switch into the safe client invites misuse.
