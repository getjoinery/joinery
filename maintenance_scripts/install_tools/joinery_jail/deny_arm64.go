//go:build arm64

package main

// aarch64 syscall numbers (asm-generic/unistd.h). fork, vfork and mknod do not
// exist on this ABI; clone/clone3 and mknodat cover them.
const auditArch = 0xc00000b7 // AUDIT_ARCH_AARCH64
const x32Bit = 0

var denied = []uint32{
	198, 199, 203, 200, 201, 202, 242, // socket, socketpair, connect, bind, listen, accept, accept4
	220, 435, // clone, clone3
	117, 270, 271, // ptrace, process_vm_readv, process_vm_writev
	40, 39, 41, 51, // mount, umount2, pivot_root, chroot
	428, 429, 430, 432, 442, // open_tree, move_mount, fsopen, fsmount, mount_setattr
	146, 144, 145, 143, 147, 149, 151, 152, 159, // setuid … setgroups
	219, 217, 218, // keyctl, add_key, request_key
	280, 425, // bpf, io_uring_setup
	97, 268, // unshare, setns
	105, 273, 106, 104, 142, // init_module, finit_module, delete_module, kexec_load, reboot
	224, 225, 161, // swapon, swapoff, sethostname
	33,                 // mknodat
	282, 241, 265, 272, // userfaultfd, perf_event_open, open_by_handle_at, kcmp
	434, 438, // pidfd_open, pidfd_getfd
}
