# relay-sealer

The Postfix pipe transport on the hardened ingest relay. It replaces
`plugins/mailbox/utils/inbound_email_handler.php` on the MX path: instead of
parsing and storing mail on the box that holds the archive, it **seals each
accepted message to the recipient's public key at acceptance** and spools
ciphertext into the owning tenant's spool directory. Each tenant's Joinery box
pulls its own sealed blobs over WireGuard.

It is deliberately tiny — the relay's smallness *is* the security property (see
`specs/mailbox_hardened_ingest_relay.md`). The relay stack is tenancy-native: a
self-hosted relay is a fleet of one, and a shard fronting many tenants runs the
same binary and the same map format.

## Invocation

Postfix `master.cf` pipe (`flags=DRh`):

```
relay-sealer ${recipient} ${sender}
```

- Raw RFC822 on **stdin**.
- `argv[1]` = envelope recipient, `argv[2]` = envelope sender.

Environment:

- `JOINERY_RELAY_ROUTING` — routing map path (default `/opt/joinery-relay/routing.json`)
- `JOINERY_RELAY_SPOOL` — fallback spool directory for a legacy (pre-tenancy)
  map (default `/var/spool/joinery-relay`); the per-tenant spool directory
  comes from the tenant's block in the routing map

## Merge unit

The same binary is the shard's map merge unit:

```
relay-sealer merge-maps
```

Run as root (tenants trigger it through the `joinery-tenant-shell` forced
command via a narrow sudoers rule). It validates each tenant's pushed fragment
against the tenant's root-owned domain allowlist
(`/opt/joinery-relay/tenants/<slug>/allowed_domains` — `*` on a self-hosted
fleet of one), keeps the last accepted fragment when a push is rejected,
derives all Postfix map lines from the validated routing data, installs
atomically, and runs `postmap` + `postfix reload` only when the merged output
changed. Per-tenant verdicts land in
`/opt/joinery-relay/tenants/<slug>/merge_result.json` and are returned in-band
by the tenant shell's `joinery-merge` verb. Shard-policy limits
(`tenants/<slug>/limits.json`: forward rate, spool quota) are stamped into the
merged tenant block here — a tenant's fragment cannot set its own caps.

## What it writes

For each sealed message, two files committed atomically into the spool:

- `<spoolid>.seal` — the whole raw message sealed with `crypto_box_seal`
  (libsodium wire format), encoded as SealedBox's `v1.seal.<rawurlbase64>` so
  the main box opens it with `SealedBox::openDek` (`sodium_crypto_box_seal_open`).
- `<spoolid>.meta` — cleartext operational metadata only (recipient, envelope
  sender, Message-ID, In-Reply-To, References, Date, size, the milter-stamped
  Authentication-Results, `key_kind`). **Never** the subject or body — those do
  not exist as fields until deferred ingest unseals the blob in-session.

`key_kind` tells the pull consumer whether the blob was sealed to a single
user's vault (`user` → Fortress, store pending-parse) or the ambient transport
key (`transport` → Standard/Private, open at pull and run today's ingest).

## Routing map

The sealer's only source of recipient public keys and routing — the relay holds
no database. Produced by `merge-maps` from the tenants' pushed fragments.
Tenancy-native shape (`format: 2`): every recipient/domain entry names its
tenant, and per-tenant context (spool dir, SRS secret, forward identity,
transport key, shard-policy limits) lives in `tenants`:

```json
{
  "format": 2,
  "version": 12,
  "tenants": {
    "main": {
      "srs_secret": "…",
      "forward_from_name": "Example",
      "forward_show_via": true,
      "transport_public_key": "<base64url X25519>",
      "spool_dir": "/var/spool/joinery-relay/main",
      "forwarding_domains": ["fwd.example.com"],
      "forward_hourly_limit": 0,
      "spool_max_mib": 0,
      "spool_max_entries": 0
    }
  },
  "recipients": {
    "alice@fortress.example.com": {
      "public_key": "<base64url X25519>",
      "key_kind": "user",
      "mode": "store",
      "destinations": [],
      "forwarding_domain": "fortress.example.com",
      "tenant": "main"
    }
  },
  "domains": {
    "example.com": {
      "catch_all_mode": "store",
      "catch_all_address": "",
      "reject_unmatched": true,
      "public_key": "<base64url transport key>",
      "key_kind": "transport",
      "forwarding_domain": "example.com",
      "tenant": "main"
    }
  }
}
```

A legacy map (top-level `srs_secret` etc., no `tenants`) is normalized on load
into a single synthesized tenant, so routing survives an in-flight upgrade.

Forward-mode aliases are executed relay-side: the envelope sender is
SRS-rewritten (byte-compatible with `SRSRewriter.php`, so bounces decode on the
main box) and re-injected through the relay's own Postfix via `sendmail`.

## Build & test

```bash
bash build.sh                 # produce ./relay-sealer (static, CGO off)
bash roundtrip_test.sh        # go vet + go test + PHP openDek wire round-trip
```

`roundtrip_test.sh` is the CI gate: it seals a message with the Go binary and
opens it with PHP `SealedBox::openDek`, proving wire compatibility end to end.
