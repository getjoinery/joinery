package main

import "testing"

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
