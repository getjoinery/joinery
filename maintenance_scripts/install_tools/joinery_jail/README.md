# joinery-jail

The fence's launcher: a setuid-root binary that runs one command inside an
allowlist fence (`specs/parser_jail.md`, `specs/sandboxed_php_plugins.md`
design 2, `docs/document_text.md`).

    joinery-jail [--user=jpx-NAME] [--timeout=S] [--rlimit-as=BYTES] [--rlimit-fsize=BYTES]
                 [--rlimit-cpu=S] [--scratch=BYTES] [--read=PATH]... [--read-php=DIR]...
                 -- command [args]

The command runs as `joinery-jail` (the document parser) or, with `--user`, as
a sandboxed plugin's own `jpx-NAME` user. It may touch only what is listed:

- **Files (Landlock):** execute and read on the command's own binary and its
  program loader; read on the shared libraries, the loader cache and the
  time-zone files; read on each `--read` path, whole; read on each `.php` file
  beneath each `--read-php` folder, file by file, listed at each call; and with
  `--scratch` a folder of its own under `/dev/shm`, capped at that many bytes,
  summed by the supervisor every 50 ms and removed when the run ends (the run cannot unlink or open `O_TMPFILE`, so every byte stays where the sum sees it). Nothing
  else, and no TCP connect or bind, no abstract Unix socket and no signal out
  of the run. A grant that is a link, or passes through one, is refused.
- **System calls (seccomp):** an allowlist written from a trace of PHP running
  the parser and every document type it reads. Every argument rule is a list
  of exact values matched on all 64 bits. No socket, fork, inotify, System V
  IPC, memfd, chmod/chown/utime/xattr, ptrace, io_uring, setuid or namespace
  call. Anything not listed answers EPERM.
- **Limits:** address space (set by the launcher just before the exec), file
  size, CPU, 256 descriptors, no core files, only descriptors 0, 1 and 2.
- **Lifetime:** a wall-clock deadline kept by a supervisor outside the fence,
  and a parent-death signal, so the command never outlives the supervisor.

It refuses to run below Landlock ABI 6 and checks on the exec thread that the
ruleset and the filter took.

Exit codes follow `timeout(1)`: the command's own, 124 on the deadline,
128+signal when it died of one, 125 when the launcher refused. Its usage line
carries `JOINERY_JAIL_INTERFACE=2`, which is how `DocumentText` tells it from
the deny-list launcher before it.

Built by `ParserJailPublisher` at publish time into `bin/joinery-jail-<uname -m>`;
installed by `install_parser_jail.sh` (a core host installer) at the platform's
root moments, which also keeps one `jpx-NAME` user per sandboxed PHP plugin.
`go test ./...` runs the parser and filter tests and applies the real fence,
unprivileged, to PHP making raw system calls through FFI
(`jail_fence_test.go`, `testdata/fence_probe.php`).
