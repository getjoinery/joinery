package main

import (
	"strings"
	"testing"
)

// A handler alias takes `base+tag@domain` as `base@domain`; nothing else does.
func subaddrMap() *routingMap {
	return &routingMap{
		Version: 1,
		Recipients: map[string]routingEntry{
			"boards@example.com": {Mode: modeStore, PublicKey: "pk", KeyKind: keyKindTransport, Tenant: "t", AcceptsTag: true},
			"info@example.com":   {Mode: modeStore, PublicKey: "pk", KeyKind: keyKindTransport, Tenant: "t"},
		},
		Domains: map[string]domainEntry{
			"example.com": {CatchAllMode: "none", RejectUnmatched: true},
		},
	}
}

func TestResolveSubaddressHandlerAliasOnly(t *testing.T) {
	m := subaddrMap()

	entry, ok := m.resolve("Boards+R7K2ABC@Example.com")
	if !ok || entry.Tenant != "t" || entry.PublicKey != "pk" || entry.ForwardingDomain != "example.com" {
		t.Fatalf("a handler alias should take its sub-address, got %+v ok=%v", entry, ok)
	}
	if _, ok := m.resolve("boards@example.com"); !ok {
		t.Fatal("the plain handler alias still resolves")
	}
	for _, rcpt := range []string{
		"info+tag@example.com",    // an alias without a handler is never sub-addressed
		"unknown+tag@example.com", // an unknown base is refused as before
		"boards+@example.com",     // an empty tag
		"+tag@example.com",        // no base
		"boards+tag@nosuch.org",   // another domain
	} {
		if _, ok := m.resolve(rcpt); ok {
			t.Fatalf("%s must not resolve", rcpt)
		}
	}
}

// The base is looked up as typed, so a catch-all's behaviour is unchanged: an
// unknown+tag lands wherever unknown@ would.
func TestResolveSubaddressLeavesCatchAllAlone(t *testing.T) {
	m := subaddrMap()
	m.Domains["example.com"] = domainEntry{CatchAllMode: modeStore, PublicKey: "ck", KeyKind: keyKindTransport, Tenant: "t"}
	if e, ok := m.resolve("unknown+tag@example.com"); !ok || e.PublicKey != "ck" {
		t.Fatalf("catch-all should take it, got %+v ok=%v", e, ok)
	}
	if e, ok := m.resolve("info+tag@example.com"); !ok || e.PublicKey != "ck" {
		t.Fatalf("a plain alias's sub-address falls to the catch-all as any unknown address does, got %+v ok=%v", e, ok)
	}
}

// The access map accepts the sub-address of a handler alias at SMTP time, and
// the fragment is rejected whole if a base could not be written safely.
func TestMergeAcceptsHandlerSubaddresses(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	frag := simpleFragment("alpha", "alpha.test")
	frag.Recipients["boards@alpha.test"] = routingEntry{Mode: modeStore, PublicKey: testPubKeyB64, KeyKind: keyKindTransport, AcceptsTag: true}
	frag.Recipients["my.desk@alpha.test"] = routingEntry{Mode: modeStore, PublicKey: testPubKeyB64, KeyKind: keyKindTransport, AcceptsTag: true}
	f.pushFragment(t, "alpha", frag)
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	if v := f.verdict(t, "alpha"); v.Status != "ok" {
		t.Fatalf("verdict: %+v", v)
	}
	srs := f.postfixMap(t, "joinery-srs")
	for _, want := range []string{`/^boards\+[^@]+@alpha\.test$/ OK`, `/^my\.desk\+[^@]+@alpha\.test$/ OK`} {
		if !strings.Contains(srs, want) {
			t.Fatalf("regexp map missing %q:\n%s", want, srs)
		}
	}
	if strings.Contains(srs, "info") {
		t.Fatalf("an alias without a handler must not be sub-addressable:\n%s", srs)
	}
	ra := f.postfixMap(t, "joinery-recipients")
	if !strings.Contains(ra, "boards@alpha.test\tOK") {
		t.Fatalf("the exact address stays in the hash map:\n%s", ra)
	}
}

func TestMergeRejectsUnsafeHandlerBase(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	frag := simpleFragment("alpha", "alpha.test")
	frag.Recipients[".*@alpha.test"] = routingEntry{Mode: modeStore, PublicKey: testPubKeyB64, KeyKind: keyKindTransport, AcceptsTag: true}
	f.pushFragment(t, "alpha", frag)
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	if v := f.verdict(t, "alpha"); v.Status != "rejected" {
		t.Fatalf("a base that cannot be written into a regexp must reject the fragment, got %+v", v)
	}
}
