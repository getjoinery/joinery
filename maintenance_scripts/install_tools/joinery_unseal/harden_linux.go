package main

// The process-level protections (specs/unseal_daemon.md § Memory): every page
// locked, now and future, so no copy the runtime or a library makes of a key
// reaches swap; not dumpable, so no core and no ptrace attach by the same uid;
// a zero core limit beside that.
//
// Locking a whole Go process needs the memory-lock limit unbounded: the heap
// grows by arenas, and a bounded limit fails an allocation at the worst
// moment, which in Go is a fatal error. So the daemon locks only under an
// unbounded limit (or as root, which the limit does not bind, though the
// daemon refuses to serve as root), and otherwise refuses to start unless it
// was told it may run unlocked (--unlocked-memory, the test suites' flag),
// which it then reports on every status as memory_locked: false.

import (
	"errors"
	"syscall"
)

const (
	prSetDumpable = 4
	rlimitMemlock = 8
	mclCurrent    = 1
	mclFuture     = 2
	mclOnfault    = 4 // Linux 4.4+: lock pages as they are touched, not every mapping up front
	rlimInfinity  = ^uint64(0)
)

func setNotDumpable() error {
	if _, _, e := syscall.RawSyscall(syscall.SYS_PRCTL, prSetDumpable, 0, 0); e != 0 {
		return e
	}
	return syscall.Setrlimit(syscall.RLIMIT_CORE, &syscall.Rlimit{Cur: 0, Max: 0})
}

// lockMemory locks every page, or reports why it cannot.
func lockMemory() error {
	var lim syscall.Rlimit
	if err := syscall.Getrlimit(rlimitMemlock, &lim); err != nil {
		return err
	}
	if lim.Cur != rlimInfinity && lim.Max == rlimInfinity {
		lim.Cur = rlimInfinity
		syscall.Setrlimit(rlimitMemlock, &lim)
		syscall.Getrlimit(rlimitMemlock, &lim)
	}
	if lim.Cur != rlimInfinity {
		return errors.New("the memory-lock limit is bounded (the unit sets LimitMEMLOCK=infinity; a container's keepalive raises it with prlimit)")
	}
	if err := syscall.Mlockall(mclCurrent | mclFuture | mclOnfault); err == nil {
		return nil
	}
	return syscall.Mlockall(mclCurrent | mclFuture) // a kernel without MCL_ONFAULT
}
