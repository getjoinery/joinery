package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestSecurityFixesReadsTheHourlyCheckAndTheEarlyFixState(t *testing.T) {
	root := t.TempDir()
	if securityFixes(root) != nil {
		t.Fatal("a relay with neither state reports nothing")
	}
	write := func(rel, text string) {
		p := filepath.Join(root, rel)
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(text), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write("var/lib/joinery/security-check/state", "installed_at=2026-11-28T09:00:00Z\nchecked_at=2026-11-28T10:00:00Z\nchecked_ok_at=2026-11-28T10:00:00Z\npending=2\ncritical=1\n")
	write("var/lib/joinery/package-fixes/state", "updated=2026-11-28T10:00:00Z\nchannel=4\nchannel_refused=7\n"+
		"fix=openssl|3.5.5-1ubuntu3.7+joinery1|1|-|-|-|0|0|-\nfix=postfix|3.10.6+joinery1|0|-|-|-|2|0|-\n")
	st := securityFixes(root)
	if st == nil {
		t.Fatal("nil")
	}
	if st.CheckedAt != "2026-11-28T10:00:00Z" || st.Pending != 2 || st.Critical != 1 {
		t.Fatalf("check: %+v", st)
	}
	if st.Channel != 4 || st.ChannelRefused != 7 || st.FixesInstalled != 1 || st.OtherRelease != "" {
		t.Fatalf("fixes: %+v", st)
	}
	write("var/lib/joinery/package-fixes/state", "other_release=noble\n")
	if st := securityFixes(root); st.OtherRelease != "noble" || st.ChannelRefused != 0 {
		t.Fatalf("another release: %+v", st)
	}
	write("var/lib/joinery/package-fixes/state", "other_release=noble<script>\n")
	if st := securityFixes(root); st.OtherRelease != "" {
		t.Fatalf("a value that is not a codename is dropped: %+v", st)
	}
}
