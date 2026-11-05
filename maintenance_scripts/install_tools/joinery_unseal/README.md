# joinery-unseal

The unseal daemon: it holds members' vault keys outside the PHP pool, so a
break-in during an open window can use a key while the window lives but never
carry it away (`specs/unseal_daemon.md`, `docs/sealed_vault.md`).

    joinery-unseal --socket PATH --allow-uid UID [--hold-file PATH] [--socket-mode 0660]
                   [--max-window-seconds S] [--max-windows N]
                   [--max-frame-bytes N] [--memory-budget N] [--max-conns N]
    joinery-unseal ping [--socket PATH] [--timeout S]
    joinery-unseal --selftest
    joinery-unseal --version

It answers on one unix socket, mode 0660, and serves only the peer whose
`SO_PEERCRED` uid is `--allow-uid` (the pool user). It has no network, reads no
database, holds no configuration beyond its argv, and opens no file after
startup but the hold marker. It refuses to serve as root, locks every page
(`mlockall`), is not dumpable, and has a zero core limit; under a bounded
memory-lock limit it refuses to start (`--unlocked-memory` lets the test
suites run it anyway, and every `status` then says `memory_locked: false`).

## The wire

One frame per request and per reply:

    u32be(len(header)) ‖ header (JSON) ‖ u32be(len(body)) ‖ body

The body is the frame's byte parts concatenated; `header.parts` lists their
lengths. Replies carry `ok: true`, or `ok: false` with an `error` code:
`bad_request`, `too_large`, `busy`, `unknown_op`, `unknown_handle`,
`unlock_failed`, `unseal_failed`, `held`, `at_capacity`, `internal`. A frame
past the size cap, or one that cannot be read in step, gets its reply (where
there is one) and ends that connection.

| Op | Header | Parts in | Reply | Parts out |
|---|---|---|---|---|
| `open` | `user`, `scope`, `idle`, `absolute` (0: the ceiling), `unlock`, `wrap`, `reveal` | with `unlock`: wrapped secret, KEK, AD; then `wrap` × (KEK, AD); then `reveal` × sealed chain KEK | `handle`, `version`, `revealed` (each reveal's scope: `previous-key`, `dek`, or empty when it did not open) | public key, the wrappings, the revealed KEKs |
| `unwrap` | `handle`, `activity` | sealed blobs (raw v2, the `dek` scope) | | the plaintexts, all or nothing |
| `open_previous` | `handle` | sealed chain KEK, the retired secret's wrapping, its AD | `handle` (a child), `version` | the retired public key |
| `close` | `handle`, or `user` | | `closed`, `unwraps` | |
| `status` | nothing, or `user` | | `daemon`, `windows`, `uptime`, `held`, `memory_locked`; or `open` | |

A wrapping is `SealedBox::wrapKey()`'s form, `v1.aead.` + XChaCha20-Poly1305;
the secret inside is `v2.` + base64url of the 32-byte X-Wing seed. The vault
secret leaves only as a wrapping, only from the `open` that presented an
unlocker for it or minted it. A `reveal` is refused without an unlocker, and
`unwrap` never opens the chain's `previous-key` scope, so a handle opens
content keys and nothing else. A child window (a retired key) lives on its
parent's clocks and closes with it.

Each window has an idle clock (refreshed only by an `unwrap` flagged as
activity) and an absolute cap, both on the monotonic clock, and no cap above
`--max-window-seconds` (seven days). A window past either is zeroed whether or
not anything asks again. A restart ends every window.

## Signals

`SIGUSR1` zeroes every window and holds: every `open` answers `held` until
`SIGUSR2` lifts it. A daemon that starts while `--hold-file` exists starts
held. `SIGTERM` zeroes every window and exits.

## Building and testing

Built by `UnsealDaemonPublisher` at publish time into
`bin/joinery-unseal-<uname -m>`. `go test ./...` runs the unit tests (the
shared X-Wing vectors among them); `go test -fuzz=FuzzServeStream` fuzzes the
framing and the request parser. `xwing.go` is the relay sealer's, byte for
byte. `tests/vault/unseal_daemon_test.php` drives the shipped binary from PHP.
