//go:build amd64

package main

// x86_64 syscall numbers (asm/unistd_64.h). Kept as literals rather than the
// syscall package's constants because several (clone3, io_uring_setup, the
// mount API) postdate that package's frozen table.
const auditArch = 0xc000003e // AUDIT_ARCH_X86_64
const x32Bit = 0x40000000    // the x32 ABI flag: a different table, killed outright

var denied = []uint32{
	41, 53, 42, 49, 50, 43, 288, // socket, socketpair, connect, bind, listen, accept, accept4
	57, 58, 56, 435, // fork, vfork, clone, clone3
	101, 310, 311, // ptrace, process_vm_readv, process_vm_writev
	165, 166, 155, 161, // mount, umount2, pivot_root, chroot
	428, 429, 430, 432, 442, // open_tree, move_mount, fsopen, fsmount, mount_setattr
	105, 106, 113, 114, 117, 119, 122, 123, 116, // setuid … setgroups
	250, 248, 249, // keyctl, add_key, request_key
	321, 425, // bpf, io_uring_setup
	272, 308, // unshare, setns
	175, 313, 176, 246, 169, // init_module, finit_module, delete_module, kexec_load, reboot
	167, 168, 170, // swapon, swapoff, sethostname
	133, 259, // mknod, mknodat
	323, 298, 304, 312, // userfaultfd, perf_event_open, open_by_handle_at, kcmp
	434, 438, // pidfd_open, pidfd_getfd
}
