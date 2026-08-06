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

## Ranged downloads

Every served response carries `Accept-Ranges: bytes`, and a `Range` request
gets the span it asked for. This is what lets a client resume an interrupted
download from where it stopped instead of starting over — the difference
between a 4 GB transfer that survives a dropped connection and one that does
not.

- A valid single range → `206` with `Content-Range: bytes start-end/total`
  and exactly those bytes. Supported forms: `bytes=start-end`,
  `bytes=start-` (to the end), `bytes=-suffix` (the last N bytes). A range
  whose end runs past the object is clamped rather than refused.
- A syntactically valid range that starts past the end, or any range on a
  zero-length object → `416` with `Content-Range: bytes */total`.
- A multi-range request, an unknown range unit, or a malformed header → the
  whole object, `200`. RFC 7233 permits ignoring a `Range` the server will
  not honor, and serving everything is always a correct answer.

The signature is unaffected: it covers `{file_id}:{size_key}:{expires}`, and
asking for part of a file is not asking for a different file.

Cloud-offloaded blobs pass the range to the storage driver
(`CloudStorageDriver::get_range()` → S3 `GetObject` with a `Range` header),
so a resume moves the requested bytes and no more. This applies to the
`original` variant, whose size is known from the blob without a round trip;
image variants are small enough that ranging them is pointless and they are
served whole.

## Sealed content

A signature authorizes the **fetch**. It does not authorize the **unsealing**:
server-custody content still needs its owner's unlock window, and that window is
bound to the browser session that opened it. A signed URL followed by a client
carrying no session cookie therefore answers `423` for a sealed file however
recently its owner unlocked — the signature says who may ask, the window says
whether the server may answer. Native-app transport reaches sealed content
through the web-session bridge (`docs/mobile_apps.md`), not through a bare signed
URL.

Sealed content answers ranges according to which decrypt hook its source
registered (see [Sealed Vault](sealed_vault.md#the-generic-consumer-hooks)):

- A **streaming** hook — Drive's Private files — reads and decrypts only the
  chunks covering the requested span, so `Accept-Ranges: bytes` is advertised
  and a `206` is answered against **plaintext** offsets. Seeking in a sealed
  video and resuming a sealed download both work. A range on a sealed file whose
  owner has no open unlock window is a `423`, decided before any header is
  written.
- A **whole-bytes** hook — small sealed attachments — produces its plaintext in
  memory from the entire ciphertext, so there is nothing to seek into. Those
  advertise no `Accept-Ranges` and ignore a `Range` header rather than
  half-honoring it.

A sealed cloud-offloaded blob is fetched whole rather than by bucket range: the
stored bytes are a container, so a range over them would be a span of ciphertext
at the wrong offsets. The container answers the range once the object is local.

## The signing key

A dedicated 32-byte key, stored SecretBox-encrypted in `stg_settings` under
`file_signed_url_key`. It is deliberately separate from `secret_box_key` (key
separation): deleting the row rotates the key, which invalidates every
outstanding signed URL and nothing else — a non-event given short TTLs.

`update_database` provisions the key on every install and upgrade
(`File::provisionSigningKey()`), so a deployment has one before anything needs
to mint. That timing is the point. The setting is declared in `settings.json`,
so the row is seeded empty and first-mint fills it; but minting writes a long
encrypted blob to `stg_settings`, which cannot seal to a user, so
[SealedEgressGuard](sealed_vault.md) refuses the write in any request that has
already opened sealed content. Opening a protected mail thread with an
attachment is exactly that: it decrypts the bodies, then mints signed URLs for
the attachments. Provisioning at deploy time, cold, keeps first-mint out of
that request.

A request may still mint the key itself if none exists — filling the seeded row
when it is empty, and never overwriting a key already in use.

## Composition: Drive share links

A Drive public share link (`/s/{token}`, see [Drive](drive.md)) is the **durable,
revocable grant**; the signed URL stays the **short-lived transport**. The share
page authorizes the visitor against the link (live, not revoked, password
satisfied), then mints a fresh signed URL per download. Revoking or expiring the
link stops new signed URLs from being issued; any already-minted URL simply lapses
at its short TTL. The two layers are independent — the link controls *whether* a
visitor may fetch, the signed URL controls *this one fetch*.

## Tests

`tests/functional/files/signed_urls_test.php` (see [Testing](testing.md)) — covers no-session
serving, expiry, tamper, size-key binding, and the ownership-gate fallback.

`tests/functional/files/signing_key_provision_test.php` — covers first-mint:
filling the seeded-empty row, leaving a key already in use alone, and minting
end to end.
