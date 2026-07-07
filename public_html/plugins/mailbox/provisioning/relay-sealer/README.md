# relay-sealer

The Postfix pipe transport on the hardened ingest relay. It replaces
`plugins/mailbox/utils/inbound_email_handler.php` on the MX path: instead of
parsing and storing mail on the box that holds the archive, it **seals each
accepted message to the recipient's public key at acceptance** and spools
ciphertext. The main Joinery box pulls sealed blobs over WireGuard.

It is deliberately tiny — the relay's smallness *is* the security property (see
`specs/mailbox_hardened_ingest_relay.md`). It does exactly one job: stream
stdin → `crypto_box_seal` to the recipient public key → atomic-rename into the
spool → fsync → return the Postfix exit code.

## Invocation

Postfix `master.cf` pipe (`flags=DRh`):

```
relay-sealer ${recipient} ${sender}
```

- Raw RFC822 on **stdin**.
- `argv[1]` = envelope recipient, `argv[2]` = envelope sender.

Environment:

- `JOINERY_RELAY_ROUTING` — routing map path (default `/opt/joinery-relay/routing.json`)
- `JOINERY_RELAY_SPOOL` — spool directory (default `/var/spool/joinery-relay`)

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
no database. Synced from the main box (see the alias-map export, Phase 3). Shape:

```json
{
  "version": 12,
  "srs_secret": "…",
  "recipients": {
    "alice@fortress.example.com": {
      "public_key": "<base64url X25519>",
      "key_kind": "user",
      "mode": "store",
      "destinations": [],
      "forwarding_domain": "fortress.example.com"
    }
  },
  "domains": {
    "example.com": {
      "catch_all_mode": "store",
      "catch_all_address": "",
      "reject_unmatched": true,
      "public_key": "<base64url transport key>",
      "key_kind": "transport",
      "forwarding_domain": "example.com"
    }
  }
}
```

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
