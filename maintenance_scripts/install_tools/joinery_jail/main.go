// joinery-jail — the fence's launcher (specs/parser_jail.md,
// specs/sandboxed_php_plugins.md design 2).
//
// Installed setuid root at /usr/local/sbin/joinery-jail. Takes one command
// line and runs it inside a fence the command cannot leave: its own Unix
// user, kernel resource limits, no new privileges, a Landlock allowlist of
// files and connections, and a seccomp allowlist of system calls. It carries
// no policy about what runs: the caller decides the command, the grants, the
// memory ceiling and the wall-clock; this binary decides only what the
// command cannot reach.
//
//	joinery-jail [--user=jpx-NAME] [--timeout=SECS] [--rlimit-as=BYTES]
//	             [--rlimit-fsize=BYTES] [--rlimit-cpu=SECS] [--scratch=BYTES]
//	             [--read=PATH]... [--read-php=DIR]... -- <command> [args...]
//
// What the command may touch is a list and nothing else: execute and read on
// the command's own binary and its program loader, read on the shared
// libraries and time-zone files, read on each --read path (whole), read on
// each .php file beneath each --read-php directory (file by file, listed at
// this call), and with --scratch a per-run folder of its own under /dev/shm,
// capped at that many bytes. Without --user the command runs as the
// joinery-jail user (the document parser); --user names a plugin's own user,
// which must begin jpx- and gets no scratch folder.
//
// Two stages, one binary. The first invocation is the SUPERVISOR: it re-runs
// itself as the fenced child, drops its own privileges to the run's user, and
// waits with a deadline. The child (STAGE 2, marked by an environment variable
// the supervisor sets)
// applies the fence and execs the command. The supervisor stays outside the
// Landlock domain, so the command cannot signal it; the command carries a
// parent-death signal, so it never outlives the supervisor.
//
// Exit codes follow timeout(1) so the PHP side reads them unchanged: the
// child's own code on a normal exit, 124 on the deadline, 128+signal when the
// child died of one (137 for a kernel kill), 125 when the launcher refused.
//
// Parses nothing but its own argv, makes no network call. The environment is
// discarded and replaced: a setuid binary that honours the caller's
// environment is a privilege bug waiting for a library that reads one.
//
// Version 2.0.1 - reviewer2's code review (D1-D7): no unlink and no O_TMPFILE
// inside the fence, so every scratch byte is in a file the sum sees; the
// supervisor makes its real uid root and stage 2 requires it, so no caller
// enters stage 2 itself; the command is opened O_NONBLOCK|O_NOFOLLOW, must be
// a regular executable file, and every failure gives one message; the
// supervisor's pid is passed and checked around the death signal; the cap
// message reaches the caller; the run dies when its caller goes away; the
// PHP-file walk and the stale sweep act on the descriptor they checked.
//
// Version 2.0.0 - the fence is an allowlist (specs/sandboxed_php_plugins.md
// design 2): Landlock grants named files only, seccomp allows named calls
// only with every argument rule an exact 64-bit list, the fork family is
// gone, --user runs a plugin as its own jpx- user, a parent-death signal ties
// the command to the supervisor, the launcher refuses below Landlock ABI 6
// and checks the ruleset took on the exec thread, the address-space cap is
// set by the launcher itself just before exec (no util-linux prlimit), and
// the parser's staging lives in a capped per-run folder the supervisor sums
// and removes.
//
// Version 1.0.3 - inherited descriptors above 2 are marked close-on-exec, never closed: closing
// them by number closed the Go runtime's own poller descriptor and every run died (netpoll failed).
//
// Version 1.0.2 - the whole fence is built on ONE OS thread. Unshare, NO_NEW_PRIVS
// and the seccomp filter are all per-thread, and the Go scheduler moves a
// goroutine between threads between any two calls. The thread is locked in
// init(), and the exec refuses unless the thread doing it reports the filter
// and NO_NEW_PRIVS.
package main

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"os/user"
	"path/filepath"
	"regexp"
	"runtime"
	"runtime/debug"
	"strconv"
	"strings"
	"syscall"
	"time"
	"unsafe"
)

const (
	jailUser   = "joinery-jail"
	pluginPfx  = "jpx-"
	stageEnv   = "JOINERY_JAIL_STAGE"
	stageMark  = "2"
	scratchEnv = "JOINERY_JAIL_SCRATCH"
	superEnv   = "JOINERY_JAIL_SUPERVISOR"

	scratchParent = "/dev/shm"
	scratchPrefix = "joinery-jail-run."
	// What DocumentText named its staging before the run folder existed.
	legacyStagedPrefix = "joinery_doctext_"
	// A run folder older than this belongs to a supervisor that was killed.
	scratchStale = 10 * time.Minute
	scratchPoll  = 50 * time.Millisecond

	exitUsage   = 125 // the launcher itself refused (mirrors timeout(1))
	exitTimeout = 124

	defaultTimeout = 20

	// interfaceMark names this launcher's options to the PHP side, which reads
	// it from the installed binary (DocumentText::JAIL_INTERFACE_MARK): a
	// deny-list launcher refuses --read and --scratch, so a caller must know
	// which one is installed before it builds a command line.
	interfaceMark = "JOINERY_JAIL_INTERFACE=2"
)

// A plugin user's name: jpx- and the manifest's name pattern
// (SandboxManifest::NAME_PATTERN: 2 to 20 characters, lower case and digits,
// single underscores between), so it fits Linux's 32.
var pluginUserRe = regexp.MustCompile(`^jpx-[a-z][a-z0-9]*(_[a-z0-9]+)*$`)

func isPluginUser(name string) bool {
	n := len(name) - len(pluginPfx)
	return n >= 2 && n <= 20 && pluginUserRe.MatchString(name)
}

// Every scrubbed child sees exactly this environment, plus the stage marker
// on the way through the supervisor (removed again before the command runs).
var childEnv = []string{
	"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
	"LANG=C.UTF-8",
}

type options struct {
	user        string
	timeout     int
	rlimitAS    uint64
	rlimitFsize uint64
	rlimitCPU   int
	scratch     uint64
	reads       []string
	readPHP     []string
	argv        []string
}

// Every step of the fence is per-thread, so this process runs on one: locked
// here, before main, the main goroutine never leaves the main thread.
func init() {
	runtime.LockOSThread()
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
		rlimitFsize: 64 * 1024 * 1024,
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
		switch name {
		case "user":
			if !isPluginUser(value) {
				return o, fmt.Errorf("option --user: %q is not a plugin user (jpx-name)", value)
			}
			o.user = value
			continue
		case "read":
			o.reads = append(o.reads, value)
			continue
		case "read-php":
			o.readPHP = append(o.readPHP, value)
			continue
		}
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
		case "scratch":
			o.scratch = n
		default:
			return o, fmt.Errorf("unknown option --%s", name)
		}
	}
	o.argv = args[i:]
	if !sawDashes || len(o.argv) == 0 {
		return o, errors.New(interfaceMark + " usage: joinery-jail [--user=jpx-NAME] [--timeout=S] [--rlimit-as=B] [--rlimit-fsize=B] [--rlimit-cpu=S] [--scratch=B] [--read=PATH]... [--read-php=DIR]... -- command [args]")
	}
	if o.user != "" && o.scratch > 0 {
		// A plugin writes nothing: a writable folder would need a total-size
		// cap a site container cannot mount, and is somewhere to plant a file.
		return o, errors.New("a plugin run (--user) gets no scratch folder")
	}
	if o.timeout <= 0 {
		o.timeout = defaultTimeout
	}
	if o.rlimitCPU <= 0 {
		// CPU seconds are a backstop behind the wall-clock: a child that spins
		// is killed by the kernel even if the supervisor were gone.
		o.rlimitCPU = o.timeout + 5
	}
	if o.scratch > 0 && (o.rlimitFsize == 0 || o.rlimitFsize > o.scratch) {
		// One write cannot pass the folder's cap by more than one file.
		o.rlimitFsize = o.scratch
	}
	return o, nil
}

func (o options) runUser() string {
	if o.user != "" {
		return o.user
	}
	return jailUser
}

// ---- the run's user ---------------------------------------------------------

func userIDs(name string) (uid, gid int) {
	u, err := user.Lookup(name)
	if err != nil {
		if strings.HasPrefix(name, pluginPfx) {
			fail(exitUsage, "user %s does not exist yet (the plugin is being set up)", name)
		}
		fail(exitUsage, "user %s does not exist (run install_parser_jail.sh as root)", name)
	}
	uid, err1 := strconv.Atoi(u.Uid)
	gid, err2 := strconv.Atoi(u.Gid)
	if err1 != nil || err2 != nil || uid == 0 || gid == 0 {
		fail(exitUsage, "user %s resolves to uid %s gid %s, which is not a jail", name, u.Uid, u.Gid)
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

// closeInheritedDescriptors marks every descriptor above 2 close-on-exec, so
// none of them reaches the fenced child: the kernel closes them at the exec in
// ForkExec. It never closes one here. The Go runtime holds descriptors of its
// own in this process (its poller's epoll and wake-up descriptors), and
// closing those by number killed the runtime (epollwait on a closed fd,
// fatal error: netpoll failed) before the child ever ran. The runtime opens
// its own close-on-exec already, so marking them changes nothing for it.
func closeInheritedDescriptors() {
	d, err := os.Open("/proc/self/fd")
	if err != nil {
		fail(exitUsage, "cannot list open descriptors: %v", err)
	}
	names, err := d.Readdirnames(-1)
	if err != nil {
		d.Close()
		fail(exitUsage, "cannot list open descriptors: %v", err)
	}
	self := int(d.Fd())
	for _, n := range names {
		fd, convErr := strconv.Atoi(n)
		if convErr != nil || fd <= 2 || fd == self {
			continue
		}
		if _, _, errno := syscall.RawSyscall(syscall.SYS_FCNTL, uintptr(fd), syscall.F_SETFD, syscall.FD_CLOEXEC); errno != 0 && errno != syscall.EBADF {
			d.Close()
			fail(exitUsage, "cannot keep descriptor %d from the fenced child: %v", fd, errno)
		}
	}
	d.Close()
}

func supervise() {
	if syscall.Geteuid() != 0 {
		fail(exitUsage, "not running as root: the binary must be installed 4755 root:root (install_parser_jail.sh)")
	}
	o, err := parse(os.Args[1:])
	if err != nil {
		fail(exitUsage, "%v", err)
	}
	uid, gid := userIDs(o.runUser())
	caller := os.Getppid()

	// Real, effective and saved uid all become root (euid is root, so this is
	// allowed). Stage 2 refuses unless its REAL uid is root, which no caller
	// that is not root can arrange by setting the stage marker itself: that
	// door would skip this supervisor, its deadline and its scratch cap, and
	// hand any local account another live run's folder. It also keeps the
	// caller from signalling this process while it holds root.
	if err := syscall.Setuid(0); err != nil || syscall.Getuid() != 0 {
		fail(exitUsage, "cannot take the real uid: %v", err)
	}

	self, err := os.Readlink("/proc/self/exe")
	if err != nil || self == "" {
		fail(exitUsage, "cannot find my own binary: %v", err)
	}

	// Only 0, 1 and 2 go to the child. Go hands ForkExec's Files list to the
	// child but does not close what the caller left open without CLOEXEC, so a
	// file, session or socket the web worker held would otherwise sit inside
	// the fence beside the parser (specs/shell_command_audit.md D2).
	closeInheritedDescriptors()

	env := append(append([]string{}, childEnv...), stageEnv+"="+stageMark, superEnv+"="+strconv.Itoa(os.Getpid()))
	var scratch *scratchDir
	if o.scratch > 0 {
		sweepStaleScratch(uid)
		scratch = makeScratch(uid, gid)
		env = append(env, scratchEnv+"="+scratch.path)
	}

	pid, err := syscall.ForkExec(self, os.Args, &syscall.ProcAttr{
		Env:   env,
		Files: []uintptr{0, 1, 2},
		Sys:   &syscall.SysProcAttr{Setpgid: true},
	})
	if err != nil {
		if scratch != nil {
			scratch.remove()
		}
		fail(exitUsage, "cannot start the fenced child: %v", err)
	}

	// Root is no longer needed. The child is already running as root through
	// its own fence; the only thing left to do here is wait and, if it comes
	// to that, kill — and the run's uid may kill the run's uid. The scratch
	// folder is that uid's, so it is summed and removed as that uid too.
	dropTo(uid, gid)

	// The pipes belong to the child now. Holding our copies of 0 and 1 would
	// keep the caller's read of stdout open until this process exits rather
	// than the child's. Standard error is kept, close-on-exec, for the one
	// thing this process says itself: that a run was killed over its cap.
	errOut := os.Stderr
	if fd, err := syscall.Dup(2); err == nil {
		syscall.CloseOnExec(fd)
		errOut = os.NewFile(uintptr(fd), "stderr")
	}
	if devnull, err := os.OpenFile("/dev/null", os.O_RDWR, 0); err == nil {
		for _, fd := range []int{0, 1, 2} {
			_ = syscall.Dup3(int(devnull.Fd()), fd, 0)
		}
		devnull.Close()
	}

	// A signal reaches this process only from root (it is the run's uid now,
	// not the caller's). A caller that gives up is noticed instead by its
	// going away: this process is reparented, and the run is killed.
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

	t := time.NewTicker(scratchPoll)
	defer t.Stop()
	finish := func(code int) {
		if scratch != nil {
			scratch.remove()
		}
		os.Exit(code)
	}

	deadline := time.After(time.Duration(o.timeout) * time.Second)
	for {
		select {
		case ws := <-done:
			finish(exitFor(ws))
		case <-deadline:
			killGroup(pid)
			<-done
			finish(exitTimeout)
		case <-sigs:
			killGroup(pid)
			ws := <-done
			finish(exitFor(ws))
		case <-t.C:
			if os.Getppid() != caller {
				killGroup(pid)
				ws := <-done
				finish(exitFor(ws))
			}
			if scratch != nil && scratch.size() > o.scratch {
				killGroup(pid)
				<-done
				fmt.Fprintf(errOut, "joinery-jail: the run's scratch folder passed its cap of %d bytes\n", o.scratch)
				finish(128 + int(syscall.SIGKILL))
			}
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

// ---- the scratch folder -------------------------------------------------------

// scratchDir is one run's folder under /dev/shm, owned by the run's uid, mode
// 0700, held by descriptor. Nothing in it is ever reached by name from /.
type scratchDir struct {
	path   string
	name   string
	parent int
	fd     int
}

func makeScratch(uid, gid int) *scratchDir {
	parent, err := syscall.Open(scratchParent, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil {
		fail(exitUsage, "scratch: %s: %v", scratchParent, err)
	}
	b := make([]byte, 12)
	if _, err := rand.Read(b); err != nil {
		fail(exitUsage, "scratch: %v", err)
	}
	name := scratchPrefix + hex.EncodeToString(b)
	if err := syscall.Mkdirat(parent, name, 0700); err != nil {
		fail(exitUsage, "scratch: mkdir: %v", err)
	}
	fd, err := openat(parent, name, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC)
	if err != nil {
		fail(exitUsage, "scratch: open: %v", err)
	}
	if err := syscall.Fchown(fd, uid, gid); err != nil {
		fail(exitUsage, "scratch: chown: %v", err)
	}
	if err := syscall.Fchmod(fd, 0700); err != nil {
		fail(exitUsage, "scratch: chmod: %v", err)
	}
	return &scratchDir{path: scratchParent + "/" + name, name: name, parent: parent, fd: fd}
}

// size is what the folder's files hold, summed through the folder's own
// descriptor. The fence lets the run make regular files only.
func (s *scratchDir) size() uint64 {
	if _, err := syscall.Seek(s.fd, 0, 0); err != nil {
		return 0
	}
	names, err := readDirNames(s.fd)
	if err != nil {
		return 0
	}
	var total uint64
	for _, n := range names {
		var st syscall.Stat_t
		if fstatat(s.fd, n, &st) != nil {
			continue
		}
		sz := uint64(st.Size)
		if b := uint64(st.Blocks) * 512; b > sz {
			sz = b
		}
		total += sz
	}
	return total
}

// remove unlinks what is in the folder and the folder, by descriptor and
// name, following nothing.
func (s *scratchDir) remove() {
	emptyDir(s.fd)
	syscall.Close(s.fd)
	_ = unlinkat(s.parent, s.name, atRemoveDir)
	syscall.Close(s.parent)
}

const atRemoveDir = 0x200

func unlinkat(dfd int, name string, flags int) error {
	p, err := syscall.BytePtrFromString(name)
	if err != nil {
		return err
	}
	_, _, e := syscall.Syscall(syscall.SYS_UNLINKAT, uintptr(dfd), uintptr(unsafe.Pointer(p)), uintptr(flags))
	if e != 0 {
		return e
	}
	return nil
}

func emptyDir(fd int) {
	if _, err := syscall.Seek(fd, 0, 0); err != nil {
		return
	}
	names, err := readDirNames(fd)
	if err != nil {
		return
	}
	for _, n := range names {
		if unlinkat(fd, n, 0) != nil {
			_ = unlinkat(fd, n, atRemoveDir)
		}
	}
}

// sweepStaleScratch removes run folders a killed supervisor left behind:
// this user's, past scratchStale. Runs as root, so it reaches each folder by
// descriptor with no link followed and checks its owner before touching it.
func sweepStaleScratch(uid int) {
	parent, err := syscall.Open(scratchParent, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC, 0)
	if err != nil {
		return
	}
	defer syscall.Close(parent)
	names, err := readDirNames(parent)
	if err != nil {
		return
	}
	cutoff := time.Now().Add(-scratchStale).Unix()
	for _, n := range names {
		if strings.HasPrefix(n, legacyStagedPrefix) {
			// Staging a deny-list run left in /dev/shm itself when it was
			// killed: that launcher's runs swept it, and the fence's cannot.
			var st syscall.Stat_t
			if fstatat(parent, n, &st) == nil && st.Mode&syscall.S_IFMT == syscall.S_IFREG &&
				int(st.Uid) == uid && st.Mtim.Sec <= cutoff {
				_ = unlinkat(parent, n, 0)
			}
			continue
		}
		if !strings.HasPrefix(n, scratchPrefix) {
			continue
		}
		fd, err := openat(parent, n, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC)
		if err != nil {
			continue
		}
		// Owner, type and age from the opened descriptor, not the name.
		var st syscall.Stat_t
		if syscall.Fstat(fd, &st) != nil || st.Mode&syscall.S_IFMT != syscall.S_IFDIR ||
			int(st.Uid) != uid || st.Mtim.Sec > cutoff {
			syscall.Close(fd)
			continue
		}
		emptyDir(fd)
		syscall.Close(fd)
		_ = unlinkat(parent, n, atRemoveDir)
	}
}

// ---- stage 2: the fence ------------------------------------------------------

func stage2() {
	// Nothing below may need the collector: the address-space cap goes on
	// just before the exec, and a collection then could need memory it no
	// longer may map.
	debug.SetGCPercent(-1)
	if syscall.Geteuid() != 0 || syscall.Getuid() != 0 {
		// Only the supervisor, with its real uid made root, starts stage 2.
		fail(exitUsage, "stage 2 must be started by the launcher itself")
	}
	supervisor, err := strconv.Atoi(os.Getenv(superEnv))
	if err != nil || supervisor <= 1 || os.Getppid() != supervisor {
		fail(exitUsage, "the supervisor is gone")
	}
	os.Unsetenv(superEnv)
	os.Unsetenv(stageEnv)
	scratchPath := os.Getenv(scratchEnv)
	os.Unsetenv(scratchEnv)
	o, err := parse(os.Args[1:])
	if err != nil {
		fail(exitUsage, "%v", err)
	}
	uid, gid := userIDs(o.runUser())

	// Everything that needs a lookup happens before the fence closes: the
	// command's path against the scrubbed PATH, its real file and its loader.
	os.Setenv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin")
	// Root resolves and opens a path the caller named, so every failure
	// gives the same answer: nothing about a root-only file leaks out.
	realCmd, interp, ok := resolveCommand(o.argv[0])
	if !ok {
		fail(exitUsage, "not a runnable program: %s", o.argv[0])
	}

	rs, env, err := buildRuleset(o, realCmd, interp, scratchPath, uid)
	if err != nil {
		fail(exitUsage, "%v", err)
	}
	grant := func(err error) {
		if err != nil {
			fail(exitUsage, "%v", err)
		}
	}

	// The argv and environment the exec will hand the kernel, built now: once
	// the address-space cap is on, nothing may allocate.
	argv0, err := syscall.BytePtrFromString(realCmd)
	grant(err)
	argvp, err := syscall.SlicePtrFromStrings(o.argv)
	grant(err)
	envp, err := syscall.SlicePtrFromStrings(env)
	grant(err)

	// 1. A fresh, empty network namespace where the kernel allows one (bare
	//    metal). A site container cannot make one; Landlock and the filter
	//    close the same door either way.
	_ = syscall.Unshare(syscall.CLONE_NEWNET)

	// 2. Kernel-enforced limits, inherited by the command.
	setLimit(syscall.RLIMIT_CORE, 0, 0)
	setLimit(rlimitNofile, 256, 256)
	setLimit(syscall.RLIMIT_FSIZE, o.rlimitFsize, o.rlimitFsize)
	setLimit(syscall.RLIMIT_CPU, uint64(o.rlimitCPU), uint64(o.rlimitCPU+5))

	// 3. Become the run's user. This must precede the filter, which allows no
	//    setuid call.
	dropTo(uid, gid)

	// 4. Die with the supervisor. Set after the drop (a credential change
	//    clears it), then checked: the supervisor may have died before it was
	//    set, and then nothing would ever deliver it.
	tieToSupervisor(supervisor)

	// 5. No way back up, then the file allowlist, then the call allowlist.
	closeFence(rs)

	// 6. Files the command creates are its own.
	syscall.Umask(0077)

	// 7. The address-space cap, on this process, last: the exec replaces the
	//    Go runtime that could not live under it.
	capAndExec(o.rlimitAS, realCmd, argv0, argvp, envp)
}

// tieToSupervisor: die with the supervisor. Set after the drop (a credential
// change clears it), then checked: the supervisor may have died before it was
// set, and then nothing would ever deliver it.
func tieToSupervisor(supervisor int) {
	if os.Getppid() != supervisor {
		fail(exitUsage, "the supervisor is gone")
	}
	if _, _, e := syscall.RawSyscall(syscall.SYS_PRCTL, prSetPdeathsig, uintptr(syscall.SIGKILL), 0); e != 0 {
		fail(exitUsage, "PR_SET_PDEATHSIG: %v", e)
	}
	if os.Getppid() != supervisor {
		fail(exitUsage, "the supervisor is gone")
	}
}

// capAndExec puts the address-space cap on this process and execs, with
// nothing allocated in between.
func capAndExec(as uint64, path string, argv0 *byte, argvp, envp []*byte) {
	if as > 0 {
		lim := syscall.Rlimit{Cur: as, Max: as}
		if _, _, e := syscall.RawSyscall6(syscall.SYS_PRLIMIT64, 0, rlimitAS, uintptr(unsafe.Pointer(&lim)), 0, 0, 0); e != 0 {
			fail(exitUsage, "address-space cap: %v", e)
		}
	}
	_, _, e := syscall.RawSyscall(syscall.SYS_EXECVE, uintptr(unsafe.Pointer(argv0)), uintptr(unsafe.Pointer(&argvp[0])), uintptr(unsafe.Pointer(&envp[0])))
	fail(exitUsage, "exec %s: %v", path, e)
}

// buildRuleset is the whole list of what the command may touch: its binary
// and loader, the system's libraries and time zones, the caller's grants and
// the run's own scratch folder. Nothing is applied yet.
func buildRuleset(o options, realCmd, interp, scratchPath string, uid int) (*ruleset, []string, error) {
	rs, err := newRuleset()
	if err != nil {
		return nil, nil, err
	}
	if err := rs.addSystemPath(realCmd, grantExec); err != nil {
		return nil, nil, err
	}
	if interp != "" {
		if err := rs.addSystemPath(interp, grantExec); err != nil {
			return nil, nil, err
		}
	}
	for _, p := range systemReadPaths {
		if err := rs.addSystemPath(p, grantRead); err != nil {
			return nil, nil, err
		}
	}
	for _, p := range o.reads {
		if err := rs.addExact(p, grantRead); err != nil {
			return nil, nil, err
		}
	}
	for _, d := range o.readPHP {
		if err := rs.addPHPFiles(d); err != nil {
			return nil, nil, err
		}
	}
	env := append([]string{}, childEnv...)
	if o.scratch == 0 {
		return rs, append(env, "TMPDIR=/nonexistent"), nil
	}
	if !strings.HasPrefix(scratchPath, scratchParent+"/"+scratchPrefix) {
		return nil, nil, errors.New("scratch folder missing")
	}
	fd, st, err := openExact(scratchPath)
	if err != nil || st.Mode&syscall.S_IFMT != syscall.S_IFDIR || int(st.Uid) != uid || st.Mode&0777 != 0700 {
		return nil, nil, fmt.Errorf("scratch folder %s is not this run's", scratchPath)
	}
	defer syscall.Close(fd)
	if err := rs.addFD(fd, grantWrite, true); err != nil {
		return nil, nil, err
	}
	return rs, append(env, "TMPDIR="+scratchPath), nil
}

// closeFence: no way back up, then the file allowlist, then the call
// allowlist, each checked on this thread.
func closeFence(rs *ruleset) {
	if _, _, e := syscall.RawSyscall(syscall.SYS_PRCTL, prSetNoNewPrivs, 1, 0); e != 0 {
		fail(exitUsage, "PR_SET_NO_NEW_PRIVS: %v", e)
	}
	if err := rs.restrictSelf(); err != nil {
		fail(exitUsage, "%v", err)
	}
	assertLandlocked()
	installAllowlist()
	assertFenced()
}

// The thread about to exec is the one whose fence the command inherits.
// Refuse unless it carries the filter, NO_NEW_PRIVS and the Landlock domain:
// a fence that landed on another thread must fail closed, never run the
// command bare.
func assertFenced() {
	nnp, _, e1 := syscall.RawSyscall(syscall.SYS_PRCTL, prGetNoNewPrivs, 0, 0)
	mode, _, e2 := syscall.RawSyscall(syscall.SYS_PRCTL, prGetSeccomp, 0, 0)
	if e1 != 0 || e2 != 0 || nnp != 1 || mode != seccompModeFilter {
		fail(exitUsage, "fence not on the exec thread (no_new_privs=%d seccomp=%d); refusing to run", nnp, mode)
	}
	assertLandlocked()
}

func setLimit(resource int, soft, hard uint64) {
	lim := syscall.Rlimit{Cur: soft, Max: hard}
	if err := syscall.Setrlimit(resource, &lim); err != nil {
		fail(exitUsage, "setrlimit(%d): %v", resource, err)
	}
}

// resolveCommand finds the program to run, as root, without letting the
// caller learn anything about a file it could not read or block on one that
// is not a program: the real path, opened O_NONBLOCK and without following a
// link, must be a regular file anyone may execute, and its ELF header is read
// from that descriptor. Any failure is just "not runnable".
func resolveCommand(name string) (realCmd, interp string, ok bool) {
	path := name
	if !strings.Contains(path, "/") {
		found, err := exec.LookPath(path)
		if err != nil {
			return "", "", false
		}
		path = found
	}
	real, err := filepath.EvalSymlinks(path)
	if err != nil || !filepath.IsAbs(real) {
		return "", "", false
	}
	fd, err := syscall.Open(real, syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK|syscall.O_CLOEXEC, 0)
	if err != nil {
		return "", "", false
	}
	f := os.NewFile(uintptr(fd), real)
	defer f.Close()
	var st syscall.Stat_t
	if syscall.Fstat(fd, &st) != nil || st.Mode&syscall.S_IFMT != syscall.S_IFREG || st.Mode&0001 == 0 {
		return "", "", false
	}
	interp, err = elfInterpreterOf(f)
	if err != nil {
		return "", "", false
	}
	return real, interp, true
}
