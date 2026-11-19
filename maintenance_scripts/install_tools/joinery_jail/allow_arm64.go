//go:build arm64

package main

// aarch64 syscall numbers (asm-generic/unistd.h). This ABI has no open,
// stat, poll, select, dup2, getdents, readlink, unlink or time; the *at and
// p* forms cover them.
const auditArch = 0xc00000b7 // AUDIT_ARCH_AARCH64
const x32Bit = 0

// The open(2) call number, or -1 where the ABI has none.
var sysOpen = -1 // no open(2) on this ABI

const (
	sysOpenat    = 56
	sysIoctl     = 29
	sysFcntl     = 25
	sysPrctl     = 167
	sysFstatat   = 79 // newfstatat
	sysPrlimit64 = 261
)

// The same allowlist as allow_amd64.go, call for call where the call exists.
var allowedCalls = []uint32{
	17, 23, 24, // getcwd, dup, dup3
	32,         // flock
	43, 44, 48, // statfs, fstatfs, faccessat
	49, 50, 57, // chdir, fchdir, close (openat: an argument rule)
	61, 62, 63, 64, // getdents64, lseek, read, write
	65, 66, 67, 68, // readv, writev, pread64, pwrite64
	72, 73, 78, 79, 80, // pselect6, ppoll, readlinkat, newfstatat, fstat
	82, 83, // fsync, fdatasync
	93, 94, 96, 98, 99, // exit, exit_group, set_tid_address, futex, set_robust_list
	101, 102, 103, // nanosleep, getitimer, setitimer
	113, 114, 115, // clock_gettime, clock_getres, clock_nanosleep
	123, 124, 132, // sched_getaffinity, sched_yield, sigaltstack
	129, 130, 131, // kill, tkill, tgkill (scoped to the run by Landlock; see allow_amd64.go)
	134, 135, 139, // rt_sigaction, rt_sigprocmask, rt_sigreturn
	153, 158, 160, // times, getgroups, uname
	163, 165, 166, // getrlimit, getrusage, umask
	169, 172, 173, // gettimeofday, getpid, getppid
	174, 175, 176, 177, 178, 179, // getuid, geteuid, getgid, getegid, gettid, sysinfo
	214, 215, 216, // brk, munmap, mremap
	221, 222, 226, 227, // execve, mmap, mprotect, msync
	232, 233, // mincore, madvise
	278, 291, 293, // getrandom, statx, rseq
	436, 439, // close_range, faccessat2
}
