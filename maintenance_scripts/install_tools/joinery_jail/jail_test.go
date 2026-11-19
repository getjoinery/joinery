package main

import (
	"encoding/binary"
	"os"
	"syscall"
	"testing"
	"time"
)

func TestParse(t *testing.T) {
	o, err := parse([]string{"--timeout=7", "--rlimit-as=1024", "--", "/usr/bin/id", "-u"})
	if err != nil {
		t.Fatal(err)
	}
	if o.timeout != 7 || o.rlimitAS != 1024 || len(o.argv) != 2 || o.argv[1] != "-u" {
		t.Fatalf("parsed wrong: %+v", o)
	}
	if o.rlimitCPU != 12 {
		t.Fatalf("cpu backstop should be timeout+5, got %d", o.rlimitCPU)
	}
	if _, err := parse([]string{"/usr/bin/id"}); err == nil {
		t.Fatal("a command without -- must be refused")
	}
	if _, err := parse([]string{"--"}); err == nil {
		t.Fatal("no command must be refused")
	}
	if _, err := parse([]string{"--bogus=1", "--", "id"}); err == nil {
		t.Fatal("an unknown option must be refused")
	}
	if _, err := parse([]string{"--timeout=x", "--", "id"}); err == nil {
		t.Fatal("a non-numeric value must be refused")
	}
}

// Every call number is listed once, and none of the calls the fence leaves
// out on purpose has crept onto the list.
func TestAllowlistShape(t *testing.T) {
	seen := map[uint32]bool{}
	for _, nr := range allowedCalls {
		if seen[nr] {
			t.Fatalf("syscall %d listed twice", nr)
		}
		seen[nr] = true
	}
	for _, r := range argRules() {
		if seen[r.nr] {
			t.Fatalf("syscall %d is both allowed outright and under an argument rule", r.nr)
		}
	}
	for _, r := range flagRules() {
		if seen[r.nr] {
			t.Fatalf("syscall %d is both allowed outright and under a flag rule", r.nr)
		}
	}
	for name, nr := range forbiddenForTest {
		if seen[nr] {
			t.Fatalf("%s (%d) must not be on the allowlist", name, nr)
		}
	}
	if _, err := buildFilter(allowedCalls, argRules(), flagRules()); err != nil {
		t.Fatal(err)
	}
}

// runBPF evaluates a classic BPF seccomp program over one call, the way the
// kernel does: loads of 32-bit words at fixed offsets, equality and bit-test
// jumps, returns.
func runBPF(t *testing.T, prog []syscall.SockFilter, arch, nr uint32, args [6]uint64) uint32 {
	var data [64]byte
	binary.LittleEndian.PutUint32(data[0:], nr)
	binary.LittleEndian.PutUint32(data[4:], arch)
	for i, a := range args {
		binary.LittleEndian.PutUint64(data[16+8*i:], a)
	}
	var A uint32
	for pc := 0; pc < len(prog); pc++ {
		ins := prog[pc]
		switch ins.Code {
		case bpfLdWAbs:
			A = binary.LittleEndian.Uint32(data[ins.K:])
		case bpfJmpJeqK:
			if A == ins.K {
				pc += int(ins.Jt)
			} else {
				pc += int(ins.Jf)
			}
		case bpfJmpJset:
			if A&ins.K != 0 {
				pc += int(ins.Jt)
			} else {
				pc += int(ins.Jf)
			}
		case bpfRetK:
			return ins.K
		default:
			t.Fatalf("unknown BPF op %#x at %d", ins.Code, pc)
		}
	}
	t.Fatal("program fell off its end")
	return 0
}

// The rows reviewer2 ran against an exclusion rule: a value with bit 32 set
// that the kernel truncates to a forbidden 32-bit command. With exact lists
// each is refused, and the allowed value itself still passes.
func TestArgumentRulesMatchAll64Bits(t *testing.T) {
	prog, err := buildFilter(allowedCalls, argRules(), flagRules())
	if err != nil {
		t.Fatal(err)
	}
	const hi = uint64(1) << 32
	allow := uint32(seccompRetAllow)
	deny := uint32(seccompRetErrno | eperm)
	cases := []struct {
		name string
		nr   uint32
		args [6]uint64
		want uint32
	}{
		{"fcntl F_GETFL", sysFcntl, [6]uint64{3, fGetfl}, allow},
		{"fcntl F_SETOWN", sysFcntl, [6]uint64{3, 8}, deny},
		{"fcntl 2^32|F_SETOWN", sysFcntl, [6]uint64{3, hi | 8}, deny},
		{"fcntl 2^32|F_GETFL", sysFcntl, [6]uint64{3, hi | fGetfl}, deny},
		{"fcntl F_SETSIG", sysFcntl, [6]uint64{3, 10}, deny},
		{"fcntl F_NOTIFY", sysFcntl, [6]uint64{3, 1026}, deny},
		{"fcntl F_SETLEASE", sysFcntl, [6]uint64{3, 1024}, deny},
		{"fcntl F_SETOWN_EX", sysFcntl, [6]uint64{3, 15}, deny},
		{"prctl PR_SET_VMA", sysPrctl, [6]uint64{prSetVMA}, allow},
		{"prctl PR_SET_PDEATHSIG", sysPrctl, [6]uint64{prSetPdeathsig}, deny},
		{"prctl 2^32|PR_SET_PDEATHSIG", sysPrctl, [6]uint64{hi | prSetPdeathsig}, deny},
		{"prctl PR_SET_SECCOMP", sysPrctl, [6]uint64{prSetSeccomp}, deny},
		{"prctl 2^32|PR_SET_SECCOMP", sysPrctl, [6]uint64{hi | prSetSeccomp}, deny},
		{"prctl PR_SET_DUMPABLE", sysPrctl, [6]uint64{prSetDumpable, 1}, deny},
		{"ioctl TCGETS", sysIoctl, [6]uint64{0, ioTCGETS}, allow},
		{"ioctl 2^32|TCGETS", sysIoctl, [6]uint64{0, hi | ioTCGETS}, deny},
		{"ioctl TIOCSTI", sysIoctl, [6]uint64{0, 0x5412}, deny},
		{"prlimit64 own process", sysPrlimit64, [6]uint64{0, 7}, allow},
		{"prlimit64 another process", sysPrlimit64, [6]uint64{1234, 0}, deny},
		{"prlimit64 2^32|0 (truncates to own)", sysPrlimit64, [6]uint64{hi, 0}, deny},
		{"read", allowedCalls[0], [6]uint64{}, allow},
		{"openat O_RDONLY", sysOpenat, [6]uint64{0, 0, 0}, allow},
		{"openat O_WRONLY|O_CREAT|O_TRUNC", sysOpenat, [6]uint64{0, 0, 01 | 0100 | 01000}, allow},
		{"openat O_TMPFILE", sysOpenat, [6]uint64{0, 0, oTmpfileBit | 0200000 | 02}, deny},
		{"openat O_TMPFILE with high bits", sysOpenat, [6]uint64{0, 0, hi | oTmpfileBit}, deny},
	}
	for name, nr := range forbiddenForTest {
		cases = append(cases, struct {
			name string
			nr   uint32
			args [6]uint64
			want uint32
		}{name, nr, [6]uint64{}, deny})
	}
	for _, c := range cases {
		if got := runBPF(t, prog, auditArch, c.nr, c.args); got != c.want {
			t.Errorf("%s: got %#x, want %#x", c.name, got, c.want)
		}
	}
	if got := runBPF(t, prog, 0x40000003, 0, [6]uint64{}); got != seccompRetKillProcess {
		t.Errorf("another architecture must be killed, got %#x", got)
	}
	if x32Bit != 0 {
		if got := runBPF(t, prog, auditArch, x32Bit|0, [6]uint64{}); got != seccompRetKillProcess {
			t.Errorf("an x32 call must be killed, got %#x", got)
		}
	}
}

func TestParseUserAndGrants(t *testing.T) {
	o, err := parse([]string{"--user=jpx-hello_world2", "--read=/a/src", "--read-php=/a/includes", "--", "php"})
	if err != nil {
		t.Fatal(err)
	}
	if o.runUser() != "jpx-hello_world2" || len(o.reads) != 1 || len(o.readPHP) != 1 {
		t.Fatalf("parsed wrong: %+v", o)
	}
	for _, bad := range []string{"--user=root", "--user=joinery-jail", "--user=jpx-", "--user=jpx-A", "--user=jpx-abcdefghijklmnopqrstu", "--user=jpx-../x", "--user=jpx-a", "--user=jpx-a__b", "--user=jpx-_ab", "--user=jpx-ab_", "--user=jpx-9ab"} {
		if _, err := parse([]string{bad, "--", "php"}); err == nil {
			t.Fatalf("%s must be refused", bad)
		}
	}
	if _, err := parse([]string{"--user=jpx-ab", "--scratch=1024", "--", "php"}); err == nil {
		t.Fatal("a plugin run must get no scratch folder")
	}
	o, err = parse([]string{"--scratch=1000", "--", "php"})
	if err != nil || o.rlimitFsize != 1000 || o.runUser() != jailUser {
		t.Fatalf("scratch lowers the file-size limit to its cap: %+v %v", o, err)
	}
}

// Keeping the caller's descriptors from the fenced child must leave this
// process working: the Go runtime's own poller descriptors are among the ones
// /proc/self/fd lists, and closing them by number killed every run (netpoll
// failed). A descriptor opened without close-on-exec comes out marked, and a
// pipe read through the poller still works afterwards.
func TestInheritedDescriptorsKeptFromChildNotClosed(t *testing.T) {
	f, err := os.Open("/proc/self/stat")
	if err != nil {
		t.Fatal(err)
	}
	defer f.Close()
	inherited, err := syscall.Dup(int(f.Fd())) // dup(2) never sets close-on-exec
	if err != nil {
		t.Fatal(err)
	}
	defer syscall.Close(inherited)
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	defer w.Close()

	closeInheritedDescriptors()

	flags, _, errno := syscall.RawSyscall(syscall.SYS_FCNTL, uintptr(inherited), syscall.F_GETFD, 0)
	if errno != 0 {
		t.Fatalf("the inherited descriptor was closed, not kept from the child: %v", errno)
	}
	if flags&syscall.FD_CLOEXEC == 0 {
		t.Fatal("the inherited descriptor would reach the child: not close-on-exec")
	}
	go func() { _, _ = w.Write([]byte("x")) }()
	if err := r.SetReadDeadline(time.Now().Add(5 * time.Second)); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 1)
	if n, err := r.Read(buf); err != nil || n != 1 {
		t.Fatalf("the runtime's poller no longer works: %d %v", n, err)
	}
}
