# Signed-URL Private File Serving (Core File Capability)

Give any feature a way to hand out short-lived links to private files that
work without a session — for native app clients and embedded HTML that
can't send cookies or custom headers.

**Problem.** Private files are served owner-or-admin via `is_viewable()`
(`serve.php` `/uploads` route, `data/files_class.php`). Native clients and
embedded HTML (inline email images) can't authenticate those fetches.
`specs/mobile_native_email.md` needs expiring self-authorizing links — and
so will every future native surface, so the capability belongs on the File
model, not in a plugin.

## The change

A mint/validate pair on the core File layer:

- **Mint:** `File::mintSignedUrl($size_key, $ttl_seconds)` returns the
  file's normal `/uploads/...` URL with `expires` and `sig` query
  parameters. Signature = HMAC-SHA256 over (file identifier, size key,
  expiry) with a server-side signing key stored via SecretBox. Default TTL
  short (minutes, caller-set).
- **Validate:** the `/uploads` route in `serve.php` checks for the
  signature parameters before the ownership gate: a valid, unexpired
  signature (constant-time compare) serves the file; invalid or expired
  falls through to the existing `is_viewable()` gate — never an error page
  for a signed miss, just the normal rules.
- **The contract (this is the design):** minting **is** the authorization
  statement. Whoever calls `mintSignedUrl()` has already verified the
  viewer may access the file (e.g. the mailbox thread fetch verifying
  viewer scope). There is no hook registry and no plugin callback in the
  serving path — authorization stays in the feature code that knows the
  rules, exactly where it already runs.
- **Serving details:** signed responses stream with
  `Cache-Control: private, no-store` (they expire; caches must not outlive
  the grant). Private cloud-stored files keep their never-302 rule — a
  valid signature streams through PHP from the private bucket, same as the
  sessioned path. Signing-key rotation invalidates outstanding URLs, which
  short TTLs make a non-event.

## Sequencing

Lands before `specs/mobile_native_email.md` Phase 1, which consumes it for
attachments and inline `cid:` images.

## Tests

- Valid signature serves without any session.
- Expired or tampered signature falls back to the ownership gate (owner
  still succeeds, stranger denied).
- Size-key is part of the signed material (a thumbnail URL can't fetch the
  original).
- Cloud-private files stream and never redirect.

## Acceptance checklist

1. A signed URL fetches a private file with no session from a clean
   client; the same URL after expiry does not; the file's owner still
   loads it normally either way.
2. Signed responses carry `Cache-Control: private, no-store`.
3. No behavior change for unsigned requests to `/uploads`.

## Out of scope

- Signed-URL adoption inside inbound email — `specs/mobile_native_email.md`.
- Public files and cloud-public 302 behavior (unchanged).

## Versioning

- Bump `@version` on each modified core file (`serve.php`,
  `data/files_class.php`).

## Documentation deliverables (on implementation)

- `docs/cloud_storage.md` or a new short `docs/file_signed_urls.md` — the
  mint/validate contract and the "minting is authorization" rule.
