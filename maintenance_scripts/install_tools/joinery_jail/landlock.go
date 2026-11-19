package main

// Landlock: the allowlist of files and connections (specs/sandboxed_php_plugins.md
// design 2). One ruleset handles every file right, both TCP rights and both
// scopes; the only rules added are the grants this file builds. Everything a
// rule does not name is refused, including what a later change to the machine
// adds.
//
// The three system calls share their numbers on every architecture (they
// postdate the unified syscall table).

import (
	"debug/elf"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"unsafe"
)

const (
	sysLandlockCreateRuleset = 444
	sysLandlockAddRule       = 445
	sysLandlockRestrictSelf  = 446

	llCreateRulesetVersion = 1
	llRulePathBeneath      = 1

	fsExecute    = 1 << 0
	fsWriteFile  = 1 << 1
	fsReadFile   = 1 << 2
	fsReadDir    = 1 << 3
	fsRemoveDir  = 1 << 4
	fsRemoveFile = 1 << 5
	fsMakeChar   = 1 << 6
	fsMakeDir    = 1 << 7
	fsMakeReg    = 1 << 8
	fsMakeSock   = 1 << 9
	fsMakeFifo   = 1 << 10
	fsMakeBlock  = 1 << 11
	fsMakeSym    = 1 << 12
	fsRefer      = 1 << 13
	fsTruncate   = 1 << 14
	fsIoctlDev   = 1 << 15

	fsHandledAll = (1 << 16) - 1
	// The only rights a rule on a file (not a directory) may carry.
	fsFileRights = fsExecute | fsWriteFile | fsReadFile | fsTruncate | fsIoctlDev

	netBindTCP    = 1 << 0
	netConnectTCP = 1 << 1

	scopeAbstractUnix = 1 << 0
	scopeSignal       = 1 << 1

	// ABI 6 is the first that scopes signals and abstract Unix sockets.
	minLandlockABI = 6

	grantRead = fsReadFile | fsReadDir
	grantExec = fsExecute | fsReadFile
	// No remove-file: a file unlinked while still open would keep growing
	// where the supervisor's sum of the folder cannot see it.
	grantWrite = fsReadFile | fsReadDir | fsWriteFile | fsMakeReg | fsTruncate

	// A grant list longer than this is a caller bug, not a tree.
	maxRules = 40000
)

// System files every fenced PHP run reads: the shared libraries, the loader's
// cache and the time-zone files. Root-owned; resolved through their links,
// which on a merged-/usr box point /lib and /lib64 into /usr.
var systemReadPaths = []string{
	"/usr/lib",
	"/lib",
	"/lib64",
	"/etc/ld.so.cache",
	"/usr/share/zoneinfo",
}

type rulesetAttr struct {
	handledFS  uint64
	handledNet uint64
	scoped     uint64
}

func landlockABI() int {
	v, _, e := syscall.RawSyscall(sysLandlockCreateRuleset, 0, 0, llCreateRulesetVersion)
	if e != 0 {
		return 0
	}
	return int(v)
}

type ruleset struct {
	fd    int
	rules int
}

func newRuleset() (*ruleset, error) {
	if abi := landlockABI(); abi < minLandlockABI {
		return nil, fmt.Errorf("Landlock ABI %d on this kernel; the fence needs %d or later", abi, minLandlockABI)
	}
	attr := rulesetAttr{handledFS: fsHandledAll, handledNet: netBindTCP | netConnectTCP, scoped: scopeAbstractUnix | scopeSignal}
	fd, _, e := syscall.RawSyscall(sysLandlockCreateRuleset, uintptr(unsafe.Pointer(&attr)), unsafe.Sizeof(attr), 0)
	if e != 0 {
		return nil, fmt.Errorf("landlock_create_ruleset: %v", e)
	}
	syscall.CloseOnExec(int(fd))
	return &ruleset{fd: int(fd)}, nil
}

// addFD grants access beneath one open O_PATH descriptor. A rule on a file
// may carry file rights only; anything else is dropped from it.
func (r *ruleset) addFD(fd int, access uint64, isDir bool) error {
	if !isDir {
		access &= fsFileRights
	}
	if access == 0 {
		return nil
	}
	if r.rules >= maxRules {
		return fmt.Errorf("more than %d grants", maxRules)
	}
	// struct landlock_path_beneath_attr is packed: u64 allowed_access; s32 parent_fd.
	var attr [12]byte
	*(*uint64)(unsafe.Pointer(&attr[0])) = access
	*(*int32)(unsafe.Pointer(&attr[8])) = int32(fd)
	_, _, e := syscall.RawSyscall6(sysLandlockAddRule, uintptr(r.fd), llRulePathBeneath, uintptr(unsafe.Pointer(&attr[0])), 0, 0, 0)
	if e != 0 {
		return fmt.Errorf("landlock_add_rule: %v", e)
	}
	r.rules++
	return nil
}

func (r *ruleset) restrictSelf() error {
	_, _, e := syscall.RawSyscall(sysLandlockRestrictSelf, uintptr(r.fd), 0, 0)
	if e != 0 {
		return fmt.Errorf("landlock_restrict_self: %v", e)
	}
	syscall.Close(r.fd)
	return nil
}

// addSystemPath grants a root-owned system path, following its links: these
// are the machine's own files, not the tree's.
func (r *ruleset) addSystemPath(p string, access uint64) error {
	real, err := filepath.EvalSymlinks(p)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil
		}
		return err
	}
	fd, err := syscall.Open(real, oPath|syscall.O_CLOEXEC|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return fmt.Errorf("open %s: %v", real, err)
	}
	defer syscall.Close(fd)
	var st syscall.Stat_t
	if err := syscall.Fstat(fd, &st); err != nil {
		return err
	}
	return r.addFD(fd, access, st.Mode&syscall.S_IFMT == syscall.S_IFDIR)
}

// openExact opens a caller-named path without following a link anywhere in
// it: the launcher is setuid root and the tree it is pointed at is writable by
// the web user. The path must be absolute and clean, and the kernel's own
// name for what was opened must be that same path; a link in any component
// changes the name and is refused.
func openExact(p string) (int, syscall.Stat_t, error) {
	var st syscall.Stat_t
	if !filepath.IsAbs(p) || filepath.Clean(p) != p || p == "/" {
		return -1, st, fmt.Errorf("grant %q: must be an absolute, clean path other than /", p)
	}
	fd, err := syscall.Open(p, oPath|syscall.O_CLOEXEC|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return -1, st, fmt.Errorf("grant %s: %v", p, err)
	}
	if err := syscall.Fstat(fd, &st); err != nil {
		syscall.Close(fd)
		return -1, st, err
	}
	if st.Mode&syscall.S_IFMT == syscall.S_IFLNK {
		syscall.Close(fd)
		return -1, st, fmt.Errorf("grant %s: is a link", p)
	}
	got, err := os.Readlink("/proc/self/fd/" + strconv.Itoa(fd))
	if err != nil || got != p {
		syscall.Close(fd)
		return -1, st, fmt.Errorf("grant %s: passes through a link", p)
	}
	return fd, st, nil
}

// addExact grants one caller-named file or directory, whole.
func (r *ruleset) addExact(p string, access uint64) error {
	fd, st, err := openExact(p)
	if err != nil {
		return err
	}
	defer syscall.Close(fd)
	mode := st.Mode & syscall.S_IFMT
	if mode != syscall.S_IFDIR && mode != syscall.S_IFREG {
		return fmt.Errorf("grant %s: neither a file nor a directory", p)
	}
	return r.addFD(fd, access, mode == syscall.S_IFDIR)
}

// addPHPFiles grants listing of a directory tree and read on every regular
// .php file beneath it, one rule per file, and no other file in it: core's folders hold other files (an
// IP list, a feature table, a certificate) and may gain a key file beside the
// code one day. Listed at each call, by descriptor, with no link followed.
func (r *ruleset) addPHPFiles(dir string) error {
	fd, st, err := openExact(dir)
	if err != nil {
		return err
	}
	defer syscall.Close(fd)
	if st.Mode&syscall.S_IFMT != syscall.S_IFDIR {
		return fmt.Errorf("grant %s: not a directory", dir)
	}
	// The folders themselves may be listed (PHP's class loader walks them to
	// build its map); listing shows names, which design 2 accepts as known,
	// and reads no file. Each .php file is then granted read on its own.
	if err := r.addFD(fd, fsReadDir, true); err != nil {
		return err
	}
	// Listed from the descriptor that was checked, never by name again.
	dfd, err := openat(fd, ".", syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_CLOEXEC)
	if err != nil {
		return fmt.Errorf("grant %s: %v", dir, err)
	}
	return r.walkPHP(dfd, 0)
}

func (r *ruleset) walkPHP(dfd int, depth int) error {
	defer syscall.Close(dfd)
	if depth > 32 {
		return errors.New("php grant: directories nested too deep")
	}
	names, err := readDirNames(dfd)
	if err != nil {
		return err
	}
	for _, name := range names {
		var st syscall.Stat_t
		if err := fstatat(dfd, name, &st); err != nil {
			continue
		}
		switch st.Mode & syscall.S_IFMT {
		case syscall.S_IFDIR:
			sub, err := openat(dfd, name, syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC)
			if err != nil {
				continue // unreadable to us is unreadable to the fenced run too
			}
			if err := r.walkPHP(sub, depth+1); err != nil {
				return err
			}
		case syscall.S_IFREG:
			if !strings.HasSuffix(name, ".php") {
				continue
			}
			ffd, err := openat(dfd, name, oPath|syscall.O_NOFOLLOW|syscall.O_CLOEXEC)
			if err != nil {
				continue
			}
			var fst syscall.Stat_t
			if syscall.Fstat(ffd, &fst) == nil && fst.Mode&syscall.S_IFMT == syscall.S_IFREG {
				err = r.addFD(ffd, fsReadFile, false)
			}
			syscall.Close(ffd)
			if err != nil {
				return err
			}
		}
	}
	return nil
}

func readDirNames(dfd int) ([]string, error) {
	var names []string
	buf := make([]byte, 32768)
	for {
		n, err := syscall.Getdents(dfd, buf)
		if err != nil {
			return nil, err
		}
		if n <= 0 {
			return names, nil
		}
		_, _, names = syscall.ParseDirent(buf[:n], -1, names)
	}
}

func openat(dfd int, name string, flags int) (int, error) {
	p, err := syscall.BytePtrFromString(name)
	if err != nil {
		return -1, err
	}
	fd, _, e := syscall.Syscall6(syscall.SYS_OPENAT, uintptr(dfd), uintptr(unsafe.Pointer(p)), uintptr(flags), 0, 0, 0)
	if e != 0 {
		return -1, e
	}
	return int(fd), nil
}

const atSymlinkNoFollow = 0x100

func fstatat(dfd int, name string, st *syscall.Stat_t) error {
	p, err := syscall.BytePtrFromString(name)
	if err != nil {
		return err
	}
	_, _, e := syscall.Syscall6(sysFstatat, uintptr(dfd), uintptr(unsafe.Pointer(p)), uintptr(unsafe.Pointer(st)), atSymlinkNoFollow, 0, 0)
	if e != 0 {
		return e
	}
	return nil
}

// elfInterpreterOf is the program loader a dynamically linked binary names,
// read from an open file. The kernel checks execute on it too, so it is
// granted beside the binary.
func elfInterpreterOf(r io.ReaderAt) (string, error) {
	f, err := elf.NewFile(r)
	if err != nil {
		return "", err
	}
	for _, p := range f.Progs {
		if p.Type != elf.PT_INTERP {
			continue
		}
		if p.Filesz == 0 || p.Filesz > 4096 {
			return "", errors.New("bad interpreter")
		}
		b := make([]byte, p.Filesz)
		if _, err := p.ReadAt(b, 0); err != nil {
			return "", err
		}
		return strings.TrimRight(string(b), "\x00"), nil
	}
	return "", nil
}

// assertLandlocked opens a path no grant covers and refuses unless the kernel
// answers EACCES: Landlock is per thread, like the filter, and a ruleset that
// landed on another thread must fail closed.
func assertLandlocked() {
	fd, err := syscall.Open("/", syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_CLOEXEC, 0)
	if err == nil {
		syscall.Close(fd)
		fail(exitUsage, "Landlock did not take on the exec thread (/ is readable); refusing to run")
	}
	if err != syscall.EACCES {
		fail(exitUsage, "Landlock check: opening / gave %v, not EACCES; refusing to run", err)
	}
}

// O_PATH: the syscall package does not name it. Same value on amd64 and arm64.
const oPath = 0x200000
