# relay-sealer

The Postfix pipe transport on the hardened ingest relay. It replaces
`plugins/mailbox/utils/inbound_email_handler.php` on the MX path: instead of
parsing and storing mail on the box that holds the archive, it **seals each
accepted message to the recipient's public key at acceptance** and spools
ciphertext into the owning tenant's spool directory. Each tenant's plane pulls
its own sealed blobs over the relay's own signed HTTPS API.

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

## What it writes for a Direct delivery

- `<spoolid>.direct` — a JSON container: the envelope, the VERIFIED sending
  domain (not what the envelope claimed), and every part exactly as it arrived —
  sealed by the SENDER to the recipient's vault key, so the relay forwards a blob
  it cannot read.
- `<spoolid>.meta` — the same cleartext sidecar shape mail uses, with
  `artifact: "direct"` and the payload kind, so one listing serves both and the
  pull consumer knows which framework to hand it to.

## The relay API (`relay-serve`)

```
relay-sealer relay-serve --hostname <mail hostname>
```

The relay's one listener besides Postfix, and its only long-running mode
(`specs/relay_without_a_shell.md`). One port, 443, with the certificate chosen by
SNI:

- the **mail hostname** gets an ACME certificate obtained in-process over
  TLS-ALPN-01 on that same port (there is no web server and no certbot on this
  machine) and serves **Joinery Direct** — `POST /.well-known/joinery-direct`,
  the three-step exchange (preflight → one request per part → commit,
  `direct_handler.go`);
- **any other name, or none**, gets the relay's **identity certificate**: an
  Ed25519 key and a self-signed certificate generated once at first start
  (`identity-init`). The plane pins the SPKI fingerprint it learned from the
  signed birth report and connects by IP: the pin is the whole trust in the
  machine, and no other key exists for it.

Every `/relay/` request, and `/egress`, carries a signed envelope in
`X-Joinery-Relay-Auth`: base64 of `{"envelope": {...}, "signature": "..."}`,
where the envelope is `protocol_version, tenant, method, request_uri (path AND
query), body_sha256, nonce (16 bytes, base64), timestamp (UTC, YYYY-MM-DD
HH:MM:SS)` and the signature is Ed25519 over
`"joinery-relay:request:v1\n" + canonical JSON`. The relay resolves the tenant's
public key from its registry (`tenants/<slug>/public_key`, or
`operator_public_key` for the reserved tenant `operator`), verifies, refuses a
stale timestamp or a replayed nonce, and scopes every path to that tenant's own
spool. No token is ever minted or stored; the relay holds public keys only. The
envelope's bytes are pinned against `plugins/mailbox/includes/RelayProtocol.php`
by `direct_wire_gate.sh` (`relay_protocol.go` is the contract).

| Route | What it does |
|---|---|
| `GET /relay/ping` | health, the only window into a relay (below) |
| `GET /relay/spool?after=<id>&limit=N` | complete entries only (artifact + `.meta`): id, kind (`seal`, `direct`), size; oldest first, bounded page |
| `GET /relay/spool/{id}.{seal,direct,meta}` | the bytes; `Range` honoured |
| `POST /relay/spool/ack` `{"ids":[...]}` | removes every artifact kind for each id |
| `PUT /relay/fragment` | body is the fragment; the response is this tenant's merge verdict |
| `POST /egress` | the Direct egress proxy, same target rules, now signed |
| `POST /relay/tenants/{slug}` | operator only: `{public_key, domains, limits}`; creates the registry entry and spool |
| `PUT /relay/tenants/{slug}/domains` | operator only |
| `DELETE /relay/tenants/{slug}` | operator only; the spool must be empty |

**Privilege split.** `relay-serve` runs as the unprivileged relay user under
`NoNewPrivileges`, `ProtectSystem=strict` and `CapabilityBoundingSet=CAP_NET_BIND_SERVICE`,
and never gains root. A merge writes `/etc/postfix/joinery-*` and reloads
Postfix; a tenant change writes the root-owned registry. So the listener **files
a request** into `requests/` (a directory it can write and nothing but root can
read) and a root `systemd` path unit fires:

```
relay-sealer apply-requests
```

which re-validates the file (regular, owned by the listener, under the cap,
well-formed, the request's tenant matching the caller), performs the merge or
the tenant change, and writes `verdicts/<id>.json`, which the listener returns in
the response (or a `timeout` verdict after thirty seconds). Root reacts to a
file whose contents it validates, never to the network. The same functions are
the CLI the build uses for tenant `main`:

```
relay-sealer tenant-add --slug main --public-key <base64> --domains '*'
relay-sealer tenant-set-domains --slug <slug> --domains a.com,b.com
relay-sealer tenant-remove --slug <slug>
```

A tenant is a directory: `tenants/<slug>/{public_key, allowed_domains,
limits.json}`, `home/<slug>/fragments/` (root-owned; the merge reads the
fragment there) and `/var/spool/joinery-relay/<slug>/` (the relay user's). No
Unix account, no peer, no sudoers rule.

**Health is the only window.** There is no shell on a relay, so the ping carries
everything a person would have learned from one, under one rule: service state
is not tenant data, and anything per-tenant is reported only when the relay has
exactly one tenant (absent, never zero, on a shard). The privileged half —
unit state, the firewall rule set, a journal excerpt of the relay's own units
(never Postfix, whose log names correspondents), Postfix counts,
`reboot_required` — is gathered by

```
relay-sealer collect-status
```

on a root timer every thirty seconds into `status/privileged.json`; the
listener merges it with what it measures itself (spool, auth failures,
listeners, Direct counters, clock, machine, ACME certificate). The keys the
plane read from the old `joinery-ping` (`services` as a flat map, `milters`,
`contract`, `provisioned`, `slug`, `sole`, `queue`) are kept alongside the
groups (`build`, `identity`, `service_detail`, `listeners`, `tls`, `clock`,
`machine`, `firewall`, `postfix`, `spool`, `direct`, `auth`, `log`).

**Birth.** `relay-sealer birth-report --run-id <id> [--out FILE] [--post <plane> --token-file F]`
signs `{run_id, public_ip, identity_public_key, identity_fingerprint,
relay_version, postfix, listener_443}` with the identity key over
`"joinery-relay:born:v1\n" + canonical JSON` and posts it to
`/api/v1/relay/born` with the one-time run token in `X-Joinery-Relay-Run-Token`.
The plane believes it only after dialling the provider's address with the
reported fingerprint pinned.

## Joinery Direct

The Direct exchange (`direct_handler.go`) is served by `relay-serve` on the mail
hostname. The split is **relay authenticates, box authorizes**: signature
verification is stateless crypto needing no vault, so forged senders are dropped
at the edge; the contact gate needs the sealed contact list, so it runs on the
box at unlock. A verified delivery is written to the tenant's spool as a
`.direct` container plus the usual `.meta` sidecar and travels the same pull
mail does.

The relay is **kind-blind**. Served kinds, rate limits, spool caps and the decoy
secret all arrive as relay-map data, so a new payload kind — core or plugin —
reaches the fleet as a map update and never as a relay release.

## Merge unit

The same binary is the shard's map merge unit:

```
relay-sealer merge-maps
```

Root only; the root applier runs it after writing a pushed fragment. It validates each tenant's pushed fragment
against the tenant's root-owned domain allowlist
(`/opt/joinery-relay/tenants/<slug>/allowed_domains` — `*` on a self-hosted
fleet of one), keeps the last accepted fragment when a push is rejected,
derives all Postfix map lines from the validated routing data, installs
atomically, and runs `postmap` + `postfix reload` only when the merged output
changed. Per-tenant verdicts land in
`/opt/joinery-relay/tenants/<slug>/merge_result.json`. Shard-policy limits
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
bash direct_wire_gate.sh      # PHP and Go sign the SAME BYTES
```

`roundtrip_test.sh` is the CI gate for sealing: it seals a message with the Go
binary and opens it with PHP `SealedBox::openDek`, proving wire compatibility end
to end.

`direct_wire_gate.sh` is the CI gate for Direct and for the relay API's
envelope, and it guards the one drift nothing else would catch. A signature is only worth anything if both ends agree
byte for byte on what was covered, and a divergence between
`DirectProtocol.php` and `direct_protocol.go` would not throw anywhere — every
delivery from a Joinery box would simply fail verification here, which a sender
reads as "peer unreachable" and downgrades to SMTP. Mail keeps flowing, nothing
is marked verified, nobody notices. The gate has PHP emit the signing bytes and
Go emit them for the same awkward fixture (mixed case, `&` and `/` in a
filename, non-ASCII, an empty manifest entry) and diffs them, then verifies a
PHP-made signature in Go over every byte-form. The relay envelope and the birth
report ride the same gate with their own awkward fixture (a query string with
`&`, `/` and `%2F`, a lowercase method, an uppercase body hash).
