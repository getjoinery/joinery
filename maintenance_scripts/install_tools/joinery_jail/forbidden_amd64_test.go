//go:build amd64

package main

// Calls the fence leaves out on purpose (specs/sandboxed_php_plugins.md design 2).
var forbiddenForTest = map[string]uint32{
	"socket": 41, "socketpair": 53, "connect": 42, "bind": 49, "sendto": 44,
	"fork": 57, "vfork": 58, "clone": 56, "clone3": 435, "setsid": 112, "setpgid": 109,
	"inotify_init": 253, "inotify_init1": 294, "inotify_add_watch": 254, "fanotify_init": 300,
	"shmget": 29, "shmat": 30, "shmctl": 31, "msgget": 68, "semget": 64,
	"memfd_create": 319, "execveat": 322,
	"chmod": 90, "fchmod": 91, "fchmodat": 268, "chown": 92, "fchown": 93, "lchown": 94, "fchownat": 260,
	"utime": 132, "utimes": 235, "utimensat": 280, "futimesat": 261,
	"setxattr": 188, "lsetxattr": 189, "fsetxattr": 190, "removexattr": 197,
	"setpriority": 141, "sched_setaffinity": 203, "sched_setscheduler": 144, "ioprio_set": 251, "kcmp": 312,
	"ptrace": 101, "process_vm_readv": 310, "process_vm_writev": 311, "pidfd_open": 434, "pidfd_getfd": 438,
	"io_uring_setup": 425, "setuid": 105, "setresuid": 117, "unshare": 272, "setns": 308,
	"mount": 165, "keyctl": 250, "bpf": 321, "perf_event_open": 298, "userfaultfd": 323,
	"rename": 82, "mkdir": 83, "link": 86, "symlink": 88, "mknod": 133,
	"unlink": 87, "unlinkat": 263, "openat2": 437,
	"landlock_create_ruleset": 444, "seccomp": 317, "personality": 135,
}
