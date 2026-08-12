# SafeHttpClient — the safe outbound HTTP path

Any server-side fetch whose destination is chosen by someone below the trusted
operator — a request parameter, a remote party's DNS/SRV record, or a row a
low-privilege user can write — goes through `includes/SafeHttpClient.php`. It is
the default so that safety is not a per-callsite discipline nobody keeps.

## What it guarantees, on every request

- **Validation before a socket opens.** The URL, and every redirect hop, passes
  through `UrlSafetyValidator` (scheme allowlist, port policy, hostname-literal
  rejection, DNS resolution of every A/AAAA record against private / loopback /
  link-local / reserved ranges).
- **Connection pinned to the validated IPs** via `CURLOPT_RESOLVE` — the real
  hostname is still used for SNI, Host and certificate verification, but the
  socket cannot be re-resolved to a rebinding target between the check and the
  connect.
- **Redirects are never handed to curl.** Following is a manual loop that
  re-validates and re-pins every hop; `CURLOPT_FOLLOWLOCATION` is always off.
- **TLS verification is always on** — there is no per-call insecure escape hatch.
- **Response body and headers are both capped** so attacker-controlled content
  cannot be buffered without bound.

## Using it

```php
require_once(PathHelper::getIncludePath('includes/SafeHttpClient.php'));

$client = new SafeHttpClient([
    'allowed_ports'   => [80, 443],   // default; a list, or ['allow' => [443], 'min' => 1024]
    'allow_redirects' => false,       // default; set true only when a redirect is expected
    'timeout'         => 15,
]);
$response = $client->get($url, ['Header-Name' => 'value']);
// $response->status, ->body, ->headers (lowercased map), ->header('Content-Type'), ->json()
```

An unsafe URL (or redirect hop) throws `UnsafeUrlException`; a transport failure
throws `SafeHttpException`. Catch both at the callsite and treat a refusal as a
failed fetch, never as a reason to fall back to a bare request.

`SafeHttpClient::directPortPolicy()` returns the port policy Joinery Direct uses
(443, or any port ≥ 1024), because its destination is a remote party's SRV record.

## When NOT to use it

A fetch whose host is a compile-time constant no user or admin can move — a
vendor API base (`Stripe`, `PayPal`, `Mailgun`, the DNS driver `API_BASE`
constants) — is not an SSRF surface and does not need the client. A callsite that
legitimately needs a policy the client forbids (following redirects uncapped, or
skipping TLS verification for a self-signed node cert) keeps its own transport and
documents why at the callsite.
