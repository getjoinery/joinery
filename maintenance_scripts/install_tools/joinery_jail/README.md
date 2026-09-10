# joinery-jail

The parser jail's launcher: a setuid-root binary that runs one command as the
`joinery-jail` user inside a fence (`specs/parser_jail.md`, `docs/document_text.md`).

    joinery-jail [--timeout=S] [--rlimit-as=BYTES] [--rlimit-fsize=BYTES] [--rlimit-cpu=S] -- command [args]

What the command cannot do: reach the network (a fresh network namespace where
the kernel allows one, and `socket`/`connect`/`bind` refused by seccomp either
way), start another process (`fork`/`clone` refused), change user, trace, mount,
load kernel code, or exceed the address-space, file-size and CPU limits it was
given. It runs as `joinery-jail`, a user that owns nothing and can read the code
tree and vendor directory, nothing else that matters.

Exit codes follow `timeout(1)`: the command's own, 124 on the deadline,
128+signal when it died of one, 125 when the launcher refused.

Built by `ParserJailPublisher` at publish time into `bin/joinery-jail-<uname -m>`;
installed by `install_parser_jail.sh` (a core host installer) at the platform's
root moments. `go test ./...` runs the parser and filter-shape tests.
