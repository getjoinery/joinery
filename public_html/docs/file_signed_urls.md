# Signed URLs for Private Files

Short-lived links to private files that work without a session — for native
app clients and embedded HTML (inline email images) that cannot send cookies
or custom headers.

## The contract: minting is the authorization

`File::mintSignedUrl()` is the authorization statement. Only code that has
already verified the viewer may access the file calls it — e.g. a mailbox
thread fetch that has verified viewer scope mints links for the thread's
attachments as it builds the response. There is no hook registry and no
plugin callback in the serving path; authorization stays in the feature code
that knows the rules.

## Minting

```php
$url = $file->mintSignedUrl($size_key, $ttl_seconds, $format);
// e.g. /uploads/thumb/photo.jpg?expires=1751500000&sig=ab12...
```

- `$size_key` — `'original'` (default) or an ImageSizeRegistry variant key.
- `$ttl_seconds` — keep short; default 300 (5 minutes). Consumers that
  re-fetch their parent resource for fresh links use the default; a consumer
  that mints once per page open and sits idle (the web mail readers'
  inline images) uses a longer TTL — 3600 — and accepts that a link
  outliving it renders broken until the page is reopened.
- `$format` — `'short'` (relative, default) or `'full'` (absolute).

The signature is HMAC-SHA256 over (file id, size key, expiry), so a
thumbnail URL cannot fetch the original and the expiry cannot be extended.
The minted URL is always the local `/uploads` pattern — it routes through
serve.php's gate, never a bucket URL.

## Validation and serving

The `/uploads/*` route in `serve.php` checks `expires`/`sig` query
parameters before the ownership gate:

- Valid and unexpired (constant-time compare) → the file is served with no
  session consulted.
- Invalid, expired, or absent → falls through to the normal `is_viewable()`
  gate. A signed miss is never its own error state: an expired link in a
  logged-in owner's browser still works via their session; a stranger gets
  the same 404 they would get with no link at all.

Signed responses stream with `Cache-Control: private, no-store` — a cached
copy must not outlive the grant. Private cloud-stored files keep their
never-302 rule: a valid signature streams the bytes through PHP from the
private bucket, exactly like the sessioned path.

## The signing key

A dedicated 32-byte key, generated on first mint and stored
SecretBox-encrypted in `stg_settings` under `file_signed_url_key`. It is
deliberately separate from `secret_box_key` (key separation): deleting the
row rotates the key, which invalidates every outstanding signed URL and
nothing else — a non-event given short TTLs.

## Tests

`tests/functional/files/signed_urls_test.php` (CLI) — covers no-session
serving, expiry, tamper, size-key binding, and the ownership-gate fallback.
