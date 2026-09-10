// joinery-jail — the parser jail's launcher (specs/parser_jail.md).
//
// Installed setuid root at /usr/local/sbin/joinery-jail. Takes one command
// line and runs it as the joinery-jail user inside a fence the command cannot
// leave: no network namespace (best effort), kernel resource limits, no new
// privileges, a seccomp deny-list, and the uid drop. It carries no policy about
// what runs — DocumentText decides the command, the memory ceiling and the
// wall-clock; this binary decides only what the child cannot reach.
//
//	joinery-jail [--timeout=SECS] [--rlimit-as=BYTES] [--rlimit-fsize=BYTES]
//	             [--rlimit-cpu=SECS] -- <command> [args...]
//
// Two stages, one binary. The first invocation is the SUPERVISOR: it re-runs
// itself as the fenced child, drops its own privileges to the jail user, and
// waits with a deadline. The child (STAGE 2, marked by an environment variable
// the supervisor sets) applies the fence and execs the command. The split
// exists because a wall-clock has to be enforced by something that can still
// signal the child after the uid change — the web user cannot signal a
// joinery-jail process, and `timeout(1)` outside the launcher would be that
// web user. The supervisor holds no root while it waits: it drops to the jail
// uid too, which is exactly the uid that may kill the child.
//
// Exit codes follow timeout(1) so the PHP side reads them unchanged: the
// child's own code on a normal exit, 124 on the deadline, 128+signal when the
// child died of one (137 for a kernel kill).
//
// Reads no file, parses nothing but its own argv, makes no network call. The
// environment is discarded and replaced: a setuid binary that honours the
// caller's environment is a privilege bug waiting for a library that reads one.
//
// Version 1.0.0
package main

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"os/user"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unsafe"
)

const (
	jailUser  = "joinery-jail"
	stageEnv  = "JOINERY_JAIL_STAGE"
	stageMark = "2"

	exitUsage   = 125 // the launcher itself refused (mirrors timeout(1))
	exitTimeout = 124

	defaultTimeout = 20
)

// Every scrubbed child sees exactly this environment, plus the stage marker
// on the way through the supervisor (removed again before the command runs).
var childEnv = []string{
	"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
	"LANG=C.UTF-8",
	"TMPDIR=/dev/shm",
}

type options struct {
	timeout     int
	rlimitAS    uint64
	rlimitFsize uint64
	rlimitCPU   int
	argv        []string
}

func main() {
	if os.Getenv(stageEnv) == stageMark {
		stage2()
		return
	}
	supervise()
}

func fail(code int, format string, args ...interface{}) {
	fmt.Fprintf(os.Stderr, "joinery-jail: "+format+"\n", args...)
	os.Exit(code)
}

// ---- argv ------------------------------------------------------------------

func parse(args []string) (options, error) {
	o := options{
		timeout:     defaultTimeout,
		rlimitAS:    0,
		rlimitFsize: 64 * 1024 * 1024,
		rlimitCPU:   0,
	}
	i := 0
	sawDashes := false
	for ; i < len(args); i++ {
		a := args[i]
		if a == "--" {
			sawDashes = true
			i++
			break
		}
		if !strings.HasPrefix(a, "--") {
			return o, fmt.Errorf("unexpected argument %q before --", a)
		}
		eq := strings.IndexByte(a, '=')
		if eq < 0 {
			return o, fmt.Errorf("option %s needs a value", a)
		}
		name, value := a[2:eq], a[eq+1:]
		n, err := strconv.ParseUint(value, 10, 63)
		if err != nil {
			return o, fmt.Errorf("option --%s: %q is not a number", name, value)
		}
		switch name {
		case "timeout":
			o.timeout = int(n)
		case "rlimit-as":
			o.rlimitAS = n
		case "rlimit-fsize":
			o.rlimitFsize = n
		case "rlimit-cpu":
			o.rlimitCPU = int(n)
		default:
			return o, fmt.Errorf("unknown option --%s", name)
		}
	}
	o.argv = args[i:]
	if !sawDashes || len(o.argv) == 0 {
		return o, errors.New("usage: joinery-jail [--timeout=S] [--rlimit-as=B] [--rlimit-fsize=B] [--rlimit-cpu=S] -- command [args]")
	}
	if o.timeout <= 0 {
		o.timeout = defaultTimeout
	}
	if o.rlimitCPU <= 0 {
		// CPU seconds are a backstop behind the wall-clock: a child that spins
		// is killed by the kernel even if the supervisor were gone.
		o.rlimitCPU = o.timeout + 5
	}
	return o, nil
}

// ---- the jail user ----------------------------------------------------------

func jailIDs() (uid, gid int) {
	u, err := user.Lookup(jailUser)
	if err != nil {
		fail(exitUsage, "user %s does not exist (run install_parser_jail.sh as root)", jailUser)
	}
	uid, err1 := strconv.Atoi(u.Uid)
	gid, err2 := strconv.Atoi(u.Gid)
	if err1 != nil || err2 != nil || uid == 0 || gid == 0 {
		fail(exitUsage, "user %s resolves to uid %s gid %s, which is not a jail", jailUser, u.Uid, u.Gid)
	}
	return uid, gid
}

// dropTo becomes uid:gid with no supplementary groups and verifies it took.
// Go applies these to every thread (syscall.Setuid since 1.16).
func dropTo(uid, gid int) {
	if err := syscall.Setgroups([]int{}); err != nil {
		fail(exitUsage, "setgroups: %v", err)
	}
	if err := syscall.Setgid(gid); err != nil {
		fail(exitUsage, "setgid: %v", err)
	}
	if err := syscall.Setuid(uid); err != nil {
		fail(exitUsage, "setuid: %v", err)
	}
	if syscall.Getuid() != uid || syscall.Geteuid() != uid ||
		syscall.Getgid() != gid || syscall.Getegid() != gid {
		fail(exitUsage, "privilege drop did not take")
	}
	// A setuid root process that becomes unprivileged is not dumpable, and a
	// dropped process should not be either: nothing it holds belongs in a core.
	_, _, _ = syscall.RawSyscall(syscall.SYS_PRCTL, prSetDumpable, 0, 0)
}

// ---- stage 1: the supervisor -------------------------------------------------

func supervise() {
	if syscall.Geteuid() != 0 {
		fail(exitUsage, "not running as root: the binary must be installed 4755 root:root (install_parser_jail.sh)")
	}
	o, err := parse(os.Args[1:])
	if err != nil {
		fail(exitUsage, "%v", err)
	}
	uid, gid := jailIDs()

	self, err := os.Readlink("/proc/self/exe")
	if err != nil || self == "" {
		fail(exitUsage, "cannot find my own binary: %v", err)
	}

	env := append(append([]string{}, childEnv...), stageEnv+"="+stageMark)
	pid, err := syscall.ForkExec(self, os.Args, &syscall.ProcAttr{
		Env:   env,
		Files: []uintptr{0, 1, 2},
		Sys:   &syscall.SysProcAttr{Setpgid: true},
	})
	if err != nil {
		fail(exitUsage, "cannot start the fenced child: %v", err)
	}

	// Root is no longer needed. The child is already running as root through
	// its own fence; the only thing left to do here is wait and, if it comes
	// to that, kill — and the jail uid may kill the jail uid.
	dropTo(uid, gid)

	// The pipes belong to the child now. Holding our copies would keep the
	// caller's read of stdout open until this process exits rather than the
	// child's, and nothing here writes to them.
	if devnull, err := os.OpenFile("/dev/null", os.O_RDWR, 0); err == nil {
		for _, fd := range []int{0, 1, 2} {
			_ = syscall.Dup3(int(devnull.Fd()), fd, 0)
		}
		devnull.Close()
	}

	// A caller that gives up (SIGTERM, SIGINT) takes the child with it.
	sigs := make(chan os.Signal, 2)
	signal.Notify(sigs, syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)

	done := make(chan syscall.WaitStatus, 1)
	go func() {
		var ws syscall.WaitStatus
		for {
			_, err := syscall.Wait4(pid, &ws, 0, nil)
			if err == syscall.EINTR {
				continue
			}
			done <- ws
			return
		}
	}()

	deadline := time.After(time.Duration(o.timeout) * time.Second)
	for {
		select {
		case ws := <-done:
			os.Exit(exitFor(ws))
		case <-deadline:
			killGroup(pid)
			ws := <-done
			_ = ws
			os.Exit(exitTimeout)
		case <-sigs:
			killGroup(pid)
			ws := <-done
			os.Exit(exitFor(ws))
		}
	}
}

func killGroup(pid int) {
	_ = syscall.Kill(-pid, syscall.SIGKILL)
	_ = syscall.Kill(pid, syscall.SIGKILL)
}

func exitFor(ws syscall.WaitStatus) int {
	if ws.Exited() {
		return ws.ExitStatus()
	}
	if ws.Signaled() {
		return 128 + int(ws.Signal())
	}
	return 1
}

// ---- stage 2: the fence ------------------------------------------------------

func stage2() {
	if syscall.Geteuid() != 0 {
		fail(exitUsage, "stage 2 reached without root")
	}
	os.Unsetenv(stageEnv)
	o, err := parse(os.Args[1:])
	if err != nil {
		fail(exitUsage, "%v", err)
	}
	uid, gid := jailIDs()

	// Everything that needs a lookup happens before the fence closes: the
	// command's path against the scrubbed PATH, and the argv/env pointers the
	// final exec will hand the kernel.
	os.Setenv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin")
	path := o.argv[0]
	if !strings.Contains(path, "/") {
		found, err := exec.LookPath(path)
		if err != nil {
			fail(exitUsage, "command not found: %s", path)
		}
		path = found
	}
	if _, err := os.Stat(path); err != nil {
		fail(exitUsage, "command not found: %s", path)
	}

	// 1. A fresh, empty network namespace. Needs CAP_SYS_ADMIN, which a setuid
	//    root process has on bare metal and lacks inside a default container;
	//    the seccomp filter below closes the same door either way.
	_ = syscall.Unshare(syscall.CLONE_NEWNET)

	// 2. Kernel-enforced limits, inherited by the command.
	setLimit(syscall.RLIMIT_CORE, 0, 0)
	setLimit(rlimitNofile, 256, 256)
	setLimit(syscall.RLIMIT_FSIZE, o.rlimitFsize, o.rlimitFsize)
	setLimit(syscall.RLIMIT_CPU, uint64(o.rlimitCPU), uint64(o.rlimitCPU+5))
	if o.rlimitAS > 0 {
		setLimit(syscall.RLIMIT_AS, o.rlimitAS, o.rlimitAS)
	}

	// 3. Become the jail user. This must precede the seccomp filter, whose
	//    deny-list includes every setuid family.
	dropTo(uid, gid)

	// 4. No way back up, then the deny-list. NO_NEW_PRIVS is what lets an
	//    unprivileged process install a filter, and it also means a setuid
	//    binary the command could reach gains it nothing.
	if _, _, e := syscall.RawSyscall(syscall.SYS_PRCTL, prSetNoNewPrivs, 1, 0); e != 0 {
		fail(exitUsage, "PR_SET_NO_NEW_PRIVS: %v", e)
	}
	installSeccomp()

	// 5. Files the command creates are its own.
	syscall.Umask(0077)

	if err := syscall.Exec(path, o.argv, childEnv); err != nil {
		fail(exitUsage, "exec %s: %v", path, err)
	}
}

func setLimit(resource int, soft, hard uint64) {
	lim := syscall.Rlimit{Cur: soft, Max: hard}
	if err := syscall.Setrlimit(resource, &lim); err != nil {
		fail(exitUsage, "setrlimit(%d): %v", resource, err)
	}
}

// ---- seccomp ------------------------------------------------------------------

const (
	prSetDumpable     = 4
	prSetNoNewPrivs   = 38
	prSetSeccomp      = 22
	seccompModeFilter = 2

	bpfLdWAbs   = 0x20 // BPF_LD  | BPF_W   | BPF_ABS
	bpfJmpJeqK  = 0x15 // BPF_JMP | BPF_JEQ | BPF_K
	bpfJmpJsetK = 0x45 // BPF_JMP | BPF_JSET | BPF_K
	bpfRetK     = 0x06 // BPF_RET | BPF_K

	seccompRetKillProcess = 0x80000000
	seccompRetErrno       = 0x00050000
	seccompRetAllow       = 0x7fff0000

	rlimitNofile = 7
	eperm        = 1
)

// The filter, in order: the architecture must be the one this binary was
// built for (anything else, including the x32 ABI on amd64, is killed), then
// every number on the deny-list answers EPERM, then everything else is
// allowed. A deny-list rather than an allow-list on purpose: PHP's syscall set
// is wide and changes between releases, and an allow-list that breaks on a PHP
// upgrade is a jail nobody keeps.
func installSeccomp() {
	n := len(denied)
	prog := make([]syscall.SockFilter, 0, n+8)
	// A = arch
	prog = append(prog, syscall.SockFilter{Code: bpfLdWAbs, K: 4})
	prog = append(prog, syscall.SockFilter{Code: bpfJmpJeqK, Jt: 1, Jf: 0, K: auditArch})
	prog = append(prog, syscall.SockFilter{Code: bpfRetK, K: seccompRetKillProcess})
	// A = nr
	prog = append(prog, syscall.SockFilter{Code: bpfLdWAbs, K: 0})
	if x32Bit != 0 {
		prog = append(prog, syscall.SockFilter{Code: bpfJmpJsetK, Jt: 0, Jf: 1, K: x32Bit})
		prog = append(prog, syscall.SockFilter{Code: bpfRetK, K: seccompRetKillProcess})
	}
	for i, nr := range denied {
		// Jump over the remaining comparisons and the ALLOW to the ERRNO.
		prog = append(prog, syscall.SockFilter{Code: bpfJmpJeqK, Jt: uint8(n - i), Jf: 0, K: nr})
	}
	prog = append(prog, syscall.SockFilter{Code: bpfRetK, K: seccompRetAllow})
	prog = append(prog, syscall.SockFilter{Code: bpfRetK, K: seccompRetErrno | eperm})

	fprog := syscall.SockFprog{Len: uint16(len(prog)), Filter: &prog[0]}
	if _, _, e := syscall.RawSyscall(syscall.SYS_PRCTL, prSetSeccomp, seccompModeFilter, uintptr(unsafe.Pointer(&fprog))); e != 0 {
		fail(exitUsage, "seccomp: %v", e)
	}
}
