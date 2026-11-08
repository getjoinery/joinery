package main

import (
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

func TestDenyListJumpsFit(t *testing.T) {
	// Every jump offset in the filter is an 8-bit forward distance.
	if len(denied) > 200 {
		t.Fatalf("deny-list too long for single-byte BPF jumps: %d", len(denied))
	}
	seen := map[uint32]bool{}
	for _, nr := range denied {
		if seen[nr] {
			t.Fatalf("syscall %d listed twice", nr)
		}
		seen[nr] = true
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
