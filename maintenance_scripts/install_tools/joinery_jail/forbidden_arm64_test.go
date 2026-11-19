//go:build arm64

package main

// Calls the fence leaves out on purpose (specs/sandboxed_php_plugins.md design 2).
var forbiddenForTest = map[string]uint32{
	"socket": 198, "socketpair": 199, "connect": 203, "bind": 200, "sendto": 206,
	"clone": 220, "clone3": 435, "setsid": 157, "setpgid": 154,
	"kill": 129, "tgkill": 131, "tkill": 130,
	"inotify_init1": 26, "inotify_add_watch": 27, "fanotify_init": 262,
	"shmget": 194, "shmat": 196, "shmctl": 195, "msgget": 186, "semget": 190,
	"memfd_create": 279, "execveat": 281,
	"fchmod": 52, "fchmodat": 53, "fchown": 55, "fchownat": 54, "utimensat": 88,
	"setxattr": 5, "lsetxattr": 6, "fsetxattr": 7, "removexattr": 14,
	"setpriority": 140, "sched_setaffinity": 122, "sched_setscheduler": 119, "ioprio_set": 30, "kcmp": 272,
	"ptrace": 117, "process_vm_readv": 270, "process_vm_writev": 271, "pidfd_open": 434, "pidfd_getfd": 438,
	"io_uring_setup": 425, "setuid": 146, "setresuid": 147, "unshare": 97, "setns": 268,
	"mount": 40, "keyctl": 219, "bpf": 280, "perf_event_open": 241, "userfaultfd": 282,
	"renameat": 38, "mkdirat": 34, "linkat": 37, "symlinkat": 36, "mknodat": 33,
	"unlinkat": 35, "openat2": 437,
	"landlock_create_ruleset": 444, "seccomp": 277, "personality": 92,
}
