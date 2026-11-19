//go:build amd64

package main

// x86_64 syscall numbers (asm/unistd_64.h), as literals: several postdate the
// syscall package's frozen table.
const auditArch = 0xc000003e // AUDIT_ARCH_X86_64
const x32Bit = 0x40000000    // the x32 ABI flag: a different table, killed outright

// The open(2) call number, or -1 where the ABI has none.
var sysOpen = 2

const (
	sysOpenat    = 257
	sysIoctl     = 16
	sysFcntl     = 72
	sysPrctl     = 157
	sysFstatat   = 262 // newfstatat
	sysPrlimit64 = 302
)

// Every call the fenced process may make with any arguments. Anything not
// here (and not an argument rule in seccomp.go) answers EPERM. Left out on
// purpose, each judged as native code: every socket call; inotify and
// fanotify; System V IPC; memfd_create and execveat; the chmod, chown, utime
// and xattr families; setpriority, sched_setaffinity, sched_setscheduler,
// ioprio_set, kcmp; ptrace, process_vm_readv/writev, pidfd_*; io_uring; the
// whole fork family, setsid and setpgid; unlink and unlinkat (a file
// unlinked while open grows out of the scratch sum's sight); every setuid and
// namespace call; mount, keyctl, bpf, perf and module calls.
var allowedCalls = []uint32{
	0, 1, 3, // read, write, close (open: an argument rule)
	4, 5, 6, // stat, fstat, lstat
	7, 8, // poll, lseek
	9, 10, 11, 12, // mmap, mprotect, munmap, brk
	13, 14, 15, // rt_sigaction, rt_sigprocmask, rt_sigreturn
	17, 18, 19, 20, // pread64, pwrite64, readv, writev
	21, 23, 24, 25, // access, select, sched_yield, mremap
	26, 27, 28, // msync, mincore, madvise
	32, 33, 35, // dup, dup2, nanosleep
	36, 38, // getitimer, setitimer (max_execution_time)
	39, 59, 60, // getpid, execve, exit
	// kill, tkill, tgkill: Landlock's signal scope refuses every process outside
	// the run, and the run is one process, so only signals to itself pass. Without
	// them abort() cannot raise SIGABRT and ends in a trap the kernel logs as a
	// general protection fault, which monitoring reads as an attack on the kernel.
	62, 200, 234,
	63, 73, 74, 75, // uname, flock, fsync, fdatasync
	78, 79, 80, 81, // getdents, getcwd, chdir, fchdir
	89, 95, // readlink, umask
	96, 97, 98, 99, 100, // gettimeofday, getrlimit, getrusage, sysinfo, times
	102, 104, 107, 108, // getuid, getgid, geteuid, getegid
	110, 111, 115, // getppid, getpgrp, getgroups
	131, 137, 138, // sigaltstack, statfs, fstatfs
	158, 186, 201, 202, // arch_prctl, gettid, time, futex
	204, 217, 218, // sched_getaffinity, getdents64, set_tid_address
	228, 229, 230, 231, // clock_gettime, clock_getres, clock_nanosleep, exit_group
	262,                // newfstatat (openat: an argument rule)
	267, 269, 270, 271, // readlinkat, faccessat, pselect6, ppoll
	273, 292, // set_robust_list, dup3
	318, 332, 334, // getrandom, statx, rseq
	436, 439, // close_range, faccessat2
}
