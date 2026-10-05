package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// A map from current sites carries no forward; one older site's forward
// recipient or forwarding catch-all keeps outbound open.
func TestCarriesForwarding(t *testing.T) {
	m := &routingMap{
		Tenants: map[string]tenantConfig{"a": {}},
		Recipients: map[string]routingEntry{
			"info@a.test": {Mode: modeStore, Tenant: "a"},
		},
		Domains: map[string]domainEntry{
			"a.test": {CatchAllMode: modeStore, Tenant: "a"},
		},
	}
	if m.carriesForwarding() {
		t.Fatal("a store-only map must close outbound")
	}
	m.Recipients["old@a.test"] = routingEntry{Mode: modeForwardAndStore, Destinations: []string{"x@gmail.test"}, Tenant: "a"}
	if !m.carriesForwarding() {
		t.Fatal("a forward_and_store recipient keeps outbound open")
	}
	delete(m.Recipients, "old@a.test")
	m.Domains["b.test"] = domainEntry{CatchAllMode: modeForward, CatchAllAddress: "x@gmail.test", Tenant: "a"}
	if !m.carriesForwarding() {
		t.Fatal("a forwarding catch-all keeps outbound open")
	}
	m.Domains["b.test"] = domainEntry{CatchAllMode: modeForward, Tenant: "a"}
	if m.carriesForwarding() {
		t.Fatal("a forward catch-all with no address forwards nothing")
	}
}

func TestUfwDeniesOutbound25(t *testing.T) {
	status := "Status: active\n\nTo                         Action      From\n--                         ------      ----\n" +
		"25/tcp                     ALLOW       Anywhere\n443/tcp                    ALLOW       Anywhere\n" +
		"25/tcp                     DENY OUT    Anywhere\n25/tcp (v6)                DENY OUT    Anywhere (v6)\n"
	if !ufwDeniesOutbound25(status) {
		t.Fatal("the DENY OUT rule was not seen")
	}
	if ufwDeniesOutbound25(strings.Replace(status, "DENY OUT", "ALLOW OUT", -1)) {
		t.Fatal("an allow rule read as a deny")
	}
}

// Only the domains of a tenant over its spool quota are deferred: its mail
// domains, the domains of its recipients, and its forwarding domains.
func TestDeferredMapLines(t *testing.T) {
	root := t.TempDir()
	full := filepath.Join(root, "full")
	empty := filepath.Join(root, "empty")
	for _, d := range []string{full, empty} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	for _, n := range []string{"1.seal", "2.seal"} {
		if err := os.WriteFile(filepath.Join(full, n), []byte("x"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	m := &routingMap{
		Tenants: map[string]tenantConfig{
			"full":  {SpoolDir: full, SpoolMaxEntries: 2, ForwardingDomains: []string{"fwd.full.test"}},
			"empty": {SpoolDir: empty, SpoolMaxEntries: 2},
		},
		Recipients: map[string]routingEntry{
			"a@solo.full.test": {Mode: modeStore, Tenant: "full"},
			"b@empty.test":     {Mode: modeStore, Tenant: "empty"},
		},
		Domains: map[string]domainEntry{
			"full.test":  {Tenant: "full"},
			"empty.test": {Tenant: "empty"},
		},
	}
	got := deferredMapLines(m, root)
	want := "full.test " + deferredReply + "\n" +
		"fwd.full.test " + deferredReply + "\n" +
		"solo.full.test " + deferredReply + "\n"
	if got != want {
		t.Fatalf("deferred map:\n%s\nwant:\n%s", got, want)
	}
	if err := os.Remove(filepath.Join(full, "1.seal")); err != nil {
		t.Fatal(err)
	}
	if got := deferredMapLines(m, root); got != "" {
		t.Fatalf("a drained tenant is still deferred:\n%s", got)
	}
}
