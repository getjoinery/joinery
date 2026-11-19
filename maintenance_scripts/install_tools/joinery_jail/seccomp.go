package main

// Seccomp: the allowlist of system calls (specs/sandboxed_php_plugins.md
// design 2), the only layer that refuses what nobody thought of. Built from a
// trace of PHP running the runner, the sample plugin and every document the
// parser reads, then judged as native code: the fenced process is assumed to
// make any call PHP could.
//
// An argument rule is a list of exact allowed values matched on all 64 bits,
// never an exclusion. The kernel reads prctl's option and fcntl's command as
// 32-bit ints while seccomp compares the full register, so "not F_SETOWN"
// would let 2^32|F_SETOWN through and the kernel would run it as F_SETOWN.

import (
	"fmt"
	"syscall"
	"unsafe"
)

const (
	prSetDumpable     = 4
	prSetNoNewPrivs   = 38
	prSetSeccomp      = 22
	prGetSeccomp      = 21
	prGetNoNewPrivs   = 39
	prSetPdeathsig    = 1
	prSetVMA          = 0x53564d41
	seccompModeFilter = 2

	bpfLdWAbs  = 0x20 // BPF_LD  | BPF_W   | BPF_ABS
	bpfJmpJeqK = 0x15 // BPF_JMP | BPF_JEQ | BPF_K
	bpfJmpJa   = 0x05 // BPF_JMP | BPF_JA
	bpfJmpJset = 0x45 // BPF_JMP | BPF_JSET | BPF_K
	bpfRetK    = 0x06 // BPF_RET | BPF_K

	seccompRetKillProcess = 0x80000000
	seccompRetErrno       = 0x00050000
	seccompRetAllow       = 0x7fff0000

	rlimitNofile = 7
	rlimitAS     = 9
	eperm        = 1

	// struct seccomp_data: int nr; u32 arch; u64 ip; u64 args[6].
	offNr   = 0
	offArch = 4
	offArg0 = 16
)

// argRule allows a call only when one argument is exactly one of the values.
type argRule struct {
	nr     uint32
	arg    int
	values []uint64
}

// flagRule allows a call unless one argument has any of the bits set. Used
// only on an int argument the kernel truncates to 32 bits, so the low word
// is the whole of what the kernel reads.
type flagRule struct {
	nr     uint32
	arg    int
	denied uint32
}

// __O_TMPFILE, the same bit on amd64 and arm64: an unnamed file in the
// scratch folder grows where the supervisor's sum cannot see it.
const oTmpfileBit = 0x400000

// Values every architecture shares.
const (
	fDupfd        = 0
	fGetfd        = 1
	fSetfd        = 2
	fGetfl        = 3
	fSetfl        = 4
	fGetlk        = 5
	fSetlk        = 6
	fSetlkw       = 7
	fOfdGetlk     = 36
	fOfdSetlk     = 37
	fOfdSetlkw    = 38
	fDupfdCloexec = 1030

	ioTCGETS   = 0x5401
	ioFIONREAD = 0x541B
)

// fcntl: the descriptor-flag, status and lock commands. Left out with no list
// of their own: F_SETOWN, F_SETOWN_EX, F_SETSIG, F_NOTIFY, F_SETLEASE and
// everything added later.
var fcntlAllowed = []uint64{fDupfd, fGetfd, fSetfd, fGetfl, fSetfl, fGetlk, fSetlk, fSetlkw, fOfdGetlk, fOfdSetlk, fOfdSetlkw, fDupfdCloexec}

// prctl: naming PHP's own memory (traced), and the two reads the launcher's
// own after-filter check makes. PR_SET_PDEATHSIG and PR_SET_SECCOMP are not
// on it: the launcher sets the death signal before the filter, and nothing
// inside may clear it.
var prctlAllowed = []uint64{prSetVMA, prGetNoNewPrivs, prGetSeccomp}

var ioctlAllowed = []uint64{ioTCGETS, ioFIONREAD}

// asm is a small BPF assembler with forward labels, so every jump is
// computed rather than counted by hand.
type asm struct {
	prog   []syscall.SockFilter
	fixups map[int]string // instruction index -> label for its Jt (or K for ja)
	labels map[string]int
}

func newAsm() *asm {
	return &asm{fixups: map[int]string{}, labels: map[string]int{}}
}

func (a *asm) ld(off uint32) { a.prog = append(a.prog, syscall.SockFilter{Code: bpfLdWAbs, K: off}) }
func (a *asm) ret(k uint32)  { a.prog = append(a.prog, syscall.SockFilter{Code: bpfRetK, K: k}) }
func (a *asm) label(name string) {
	a.labels[name] = len(a.prog)
}

// jeqTo jumps to label when A == k, else falls through.
func (a *asm) jeqTo(k uint32, label string) {
	a.fixups[len(a.prog)] = label
	a.prog = append(a.prog, syscall.SockFilter{Code: bpfJmpJeqK, K: k})
}

// jneTo jumps to label when A != k, else falls through.
func (a *asm) jneTo(k uint32, label string) {
	a.fixups[len(a.prog)] = "!" + label
	a.prog = append(a.prog, syscall.SockFilter{Code: bpfJmpJeqK, K: k})
}

func (a *asm) jsetTo(k uint32, label string) {
	a.fixups[len(a.prog)] = label
	a.prog = append(a.prog, syscall.SockFilter{Code: bpfJmpJset, K: k})
}

func (a *asm) link() ([]syscall.SockFilter, error) {
	for i, lab := range a.fixups {
		neg := false
		if lab[0] == '!' {
			neg, lab = true, lab[1:]
		}
		t, ok := a.labels[lab]
		if !ok {
			return nil, fmt.Errorf("bpf: no label %s", lab)
		}
		d := t - i - 1
		if d < 0 || d > 255 {
			return nil, fmt.Errorf("bpf: jump from %d to %s is %d", i, lab, d)
		}
		if neg {
			a.prog[i].Jf = uint8(d)
		} else {
			a.prog[i].Jt = uint8(d)
		}
	}
	return a.prog, nil
}

// buildFilter: the architecture must be this binary's (anything else, x32
// included, is killed); each allowed call returns ALLOW; a call with an
// argument rule jumps to its own block; everything else answers EPERM.
// Every block ends in its own ALLOW or ERRNO, so no jump is ever long.
func buildFilter(allowed []uint32, rules []argRule, flags []flagRule) ([]syscall.SockFilter, error) {
	a := newAsm()
	a.ld(offArch)
	a.jeqTo(auditArch, "arch_ok")
	a.ret(seccompRetKillProcess)
	a.label("arch_ok")
	a.ld(offNr)
	if x32Bit != 0 {
		a.jsetTo(x32Bit, "kill")
	}
	for i, nr := range allowed {
		next := fmt.Sprintf("n%d", i)
		a.jneTo(nr, next)
		a.ret(seccompRetAllow)
		a.label(next)
	}
	for i, r := range rules {
		next := fmt.Sprintf("r%d", i)
		deny := fmt.Sprintf("r%d_deny", i)
		a.jneTo(r.nr, next)
		lo := uint32(offArg0 + 8*r.arg)
		// High word first: every allowed value fits in 32 bits, so a set high
		// word is refused outright. This is what stops 2^32|value.
		a.ld(lo + 4)
		a.jneTo(0, deny)
		a.ld(lo)
		ok := fmt.Sprintf("r%d_ok", i)
		for _, v := range r.values {
			if v>>32 != 0 {
				return nil, fmt.Errorf("argument rule for %d: value %#x needs 64 bits", r.nr, v)
			}
			a.jeqTo(uint32(v), ok)
		}
		a.label(deny)
		a.ret(seccompRetErrno | eperm)
		a.label(ok)
		a.ret(seccompRetAllow)
		a.label(next)
		a.ld(offNr)
	}
	for i, r := range flags {
		next := fmt.Sprintf("f%d", i)
		deny := fmt.Sprintf("f%d_deny", i)
		a.jneTo(r.nr, next)
		a.ld(uint32(offArg0 + 8*r.arg))
		a.jsetTo(r.denied, deny)
		a.ret(seccompRetAllow)
		a.label(deny)
		a.ret(seccompRetErrno | eperm)
		a.label(next)
		a.ld(offNr)
	}
	a.ret(seccompRetErrno | eperm)
	a.label("kill")
	a.ret(seccompRetKillProcess)
	return a.link()
}

func installAllowlist() {
	prog, err := buildFilter(allowedCalls, argRules(), flagRules())
	if err != nil {
		fail(exitUsage, "seccomp: %v", err)
	}
	fprog := syscall.SockFprog{Len: uint16(len(prog)), Filter: &prog[0]}
	if _, _, e := syscall.RawSyscall(syscall.SYS_PRCTL, prSetSeccomp, seccompModeFilter, uintptr(unsafe.Pointer(&fprog))); e != 0 {
		fail(exitUsage, "seccomp: %v", e)
	}
}

func argRules() []argRule {
	return []argRule{
		{nr: sysFcntl, arg: 1, values: fcntlAllowed},
		{nr: sysPrctl, arg: 0, values: prctlAllowed},
		{nr: sysIoctl, arg: 1, values: ioctlAllowed},
		// On another process prlimit lowers its CPU limit and kills it, and
		// Landlock has no hook for it: own process (pid 0) only.
		{nr: sysPrlimit64, arg: 0, values: []uint64{0}},
	}
}

func flagRules() []flagRule {
	r := []flagRule{{nr: sysOpenat, arg: 2, denied: oTmpfileBit}}
	if sysOpen >= 0 {
		r = append(r, flagRule{nr: uint32(sysOpen), arg: 1, denied: oTmpfileBit})
	}
	return r
}
