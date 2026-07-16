package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// The merge unit is the domain-claim enforcement point
// (specs/mailbox_relay_shared_fleet.md § Map sync): these tests build a fake
// shard tree, run runMerge() with env overrides (no postmap/reload), and
// assert validation, isolation, last-accepted fallback, and the derived
// Postfix maps.

type mergeFixture struct {
	home    string
	postfix string
	spool   string
}

func newMergeFixture(t *testing.T) *mergeFixture {
	t.Helper()
	root := t.TempDir()
	f := &mergeFixture{
		home:    filepath.Join(root, "opt"),
		postfix: filepath.Join(root, "postfix"),
		spool:   filepath.Join(root, "spool"),
	}
	for _, d := range []string{f.home, f.postfix, f.spool, filepath.Join(f.home, "tenants")} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("JOINERY_RELAY_HOME", f.home)
	t.Setenv("JOINERY_RELAY_POSTFIX_DIR", f.postfix)
	t.Setenv("JOINERY_RELAY_SPOOL_ROOT", f.spool)
	t.Setenv("JOINERY_RELAY_MERGE_NO_RELOAD", "1")
	return f
}

func (f *mergeFixture) addTenant(t *testing.T, slug string, allowed []string) {
	t.Helper()
	tdir := filepath.Join(f.home, "tenants", slug)
	if err := os.MkdirAll(tdir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(tdir, "allowed_domains"),
		[]byte(strings.Join(allowed, "\n")+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(f.home, "home", slug, "fragments"), 0o755); err != nil {
		t.Fatal(err)
	}
}

func (f *mergeFixture) pushFragment(t *testing.T, slug string, frag mapFragment) {
	t.Helper()
	data, err := json.Marshal(frag)
	if err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(f.home, "home", slug, "fragments", "fragment.json")
	if err := os.WriteFile(path, data, 0o644); err != nil {
		t.Fatal(err)
	}
}

func (f *mergeFixture) verdict(t *testing.T, slug string) mergeVerdict {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(f.home, "tenants", slug, "merge_result.json"))
	if err != nil {
		t.Fatalf("verdict for %s: %v", slug, err)
	}
	var v mergeVerdict
	if err := json.Unmarshal(data, &v); err != nil {
		t.Fatalf("verdict for %s unparseable: %v", slug, err)
	}
	return v
}

func (f *mergeFixture) mergedMap(t *testing.T) *routingMap {
	t.Helper()
	m, err := loadRoutingMap(filepath.Join(f.home, "routing.json"))
	if err != nil {
		t.Fatalf("merged routing.json: %v", err)
	}
	return m
}

func (f *mergeFixture) postfixMap(t *testing.T, name string) string {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(f.postfix, name))
	if err != nil {
		t.Fatalf("postfix map %s: %v", name, err)
	}
	return string(data)
}

func simpleFragment(slug, domain string) mapFragment {
	return mapFragment{
		FragmentFormat:  1,
		Tenant:          slug,
		Version:         1,
		SRSSecret:       "sekrit-" + slug,
		ForwardFromName: "Site " + slug,
		ForwardShowVia:  true,
		Recipients: map[string]routingEntry{
			"info@" + domain: {Mode: modeStore, PublicKey: testPubKeyB64, KeyKind: keyKindTransport},
		},
		Domains: map[string]domainEntry{
			domain: {CatchAllMode: "none", RejectUnmatched: true},
		},
		ForwardingDomains: []string{"fwd." + domain},
	}
}

// A fixed valid X25519 public key (32 bytes of 0x01, base64url).
var testPubKeyB64 = "AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE"

func TestMergeTwoTenantsIsolatedAndDerived(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	f.addTenant(t, "beta", []string{"beta.test"})
	f.pushFragment(t, "alpha", simpleFragment("alpha", "alpha.test"))
	f.pushFragment(t, "beta", simpleFragment("beta", "beta.test"))

	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}

	if v := f.verdict(t, "alpha"); v.Status != "ok" || !v.Installed {
		t.Fatalf("alpha verdict: %+v", v)
	}
	m := f.mergedMap(t)
	if len(m.Tenants) != 2 {
		t.Fatalf("expected 2 tenant blocks, got %d", len(m.Tenants))
	}
	if m.Tenants["alpha"].SRSSecret != "sekrit-alpha" || m.Tenants["beta"].SRSSecret != "sekrit-beta" {
		t.Fatal("per-tenant SRS secrets not preserved")
	}
	if got := m.Tenants["alpha"].SpoolDir; got != filepath.Join(f.spool, "alpha") {
		t.Fatalf("alpha spool dir = %q", got)
	}
	entry, ok := m.resolve("info@beta.test")
	if !ok || entry.Tenant != "beta" {
		t.Fatalf("beta recipient should resolve to tenant beta, got %+v ok=%v", entry, ok)
	}

	rd := f.postfixMap(t, "joinery-relay-domains")
	for _, want := range []string{"alpha.test\tOK", "beta.test\tOK", "fwd.alpha.test\tOK"} {
		if !strings.Contains(rd, want) {
			t.Fatalf("relay-domains missing %q:\n%s", want, rd)
		}
	}
	ra := f.postfixMap(t, "joinery-recipients")
	if !strings.Contains(ra, "info@alpha.test\tOK") || !strings.Contains(ra, "alpha.test\tREJECT") {
		t.Fatalf("recipient access wrong:\n%s", ra)
	}
	srs := f.postfixMap(t, "joinery-srs")
	if !strings.Contains(srs, `/^SRS0=[^@]*@fwd\.beta\.test$/ OK`) {
		t.Fatalf("srs accept map wrong:\n%s", srs)
	}
}

// The cross-claim attack: tenant beta pushes a fragment naming alpha's domain.
// The fragment must be rejected WHOLE — beta contributes nothing, alpha's
// routing is untouched.
func TestMergeRejectsOutOfAllowlistDomainWhole(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	f.addTenant(t, "beta", []string{"beta.test"})
	f.pushFragment(t, "alpha", simpleFragment("alpha", "alpha.test"))

	evil := simpleFragment("beta", "beta.test")
	evil.Domains["alpha.test"] = domainEntry{CatchAllMode: "store", PublicKey: testPubKeyB64, KeyKind: keyKindTransport}
	f.pushFragment(t, "beta", evil)

	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	v := f.verdict(t, "beta")
	if v.Status != "rejected" || !strings.Contains(v.Reason, "allowlist") {
		t.Fatalf("beta should be rejected on allowlist, got %+v", v)
	}
	m := f.mergedMap(t)
	if _, ok := m.Tenants["beta"]; ok {
		t.Fatal("rejected fragment must contribute NOTHING (no tenant block)")
	}
	if e, ok := m.Domains["alpha.test"]; !ok || e.Tenant != "alpha" {
		t.Fatalf("alpha.test must stay alpha's, got %+v ok=%v", e, ok)
	}
	if _, ok := m.Domains["beta.test"]; ok {
		t.Fatal("beta.test rides the rejected fragment and must be absent")
	}
}

// A rejected push must not erase working routing: the last ACCEPTED fragment
// keeps serving, and the verdict says so.
func TestMergeKeepsLastAcceptedOnRejectedPush(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	f.pushFragment(t, "alpha", simpleFragment("alpha", "alpha.test"))
	if rc := runMerge(); rc != 0 {
		t.Fatal("first merge failed")
	}

	bad := simpleFragment("alpha", "alpha.test")
	bad.Version = 2
	bad.Domains["stolen.test"] = domainEntry{CatchAllMode: "store"}
	f.pushFragment(t, "alpha", bad)
	if rc := runMerge(); rc != 0 {
		t.Fatal("second merge failed")
	}

	v := f.verdict(t, "alpha")
	if v.Status != "rejected" || !strings.Contains(v.Reason, "keeping last accepted") {
		t.Fatalf("expected rejected-with-fallback verdict, got %+v", v)
	}
	if v.FragmentVersion != 1 {
		t.Fatalf("serving fragment should be v1, got %d", v.FragmentVersion)
	}
	m := f.mergedMap(t)
	if _, ok := m.Domains["alpha.test"]; !ok {
		t.Fatal("last accepted routing must keep serving")
	}
	if _, ok := m.Domains["stolen.test"]; ok {
		t.Fatal("rejected domain must not be installed")
	}
}

// The self-hosted fleet-of-one default: a "*" allowlist accepts any domain —
// there is no other tenant to claim against.
func TestMergeWildcardAllowlist(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "main", []string{"*"})
	f.pushFragment(t, "main", simpleFragment("main", "anything.example"))
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	if v := f.verdict(t, "main"); v.Status != "ok" {
		t.Fatalf("wildcard allowlist should accept, got %+v", v)
	}
}

// Duplicate claim with overlapping (wildcard) allowlists: deterministic —
// first slug in sorted order wins, the later fragment is rejected whole.
func TestMergeCrossTenantDuplicateClaim(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "aaa", []string{"*"})
	f.addTenant(t, "bbb", []string{"*"})
	f.pushFragment(t, "aaa", simpleFragment("aaa", "same.test"))
	f.pushFragment(t, "bbb", simpleFragment("bbb", "same.test"))
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	if v := f.verdict(t, "aaa"); v.Status != "ok" {
		t.Fatalf("aaa should win the claim, got %+v", v)
	}
	v := f.verdict(t, "bbb")
	if v.Status != "rejected" || !strings.Contains(v.Reason, "claimed by another tenant") {
		t.Fatalf("bbb should be rejected as duplicate claim, got %+v", v)
	}
}

// A fragment whose tenant field does not match its account slug is rejected —
// the pushing account cannot impersonate another tenant.
func TestMergeRejectsTenantMismatchAndSymlink(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	frag := simpleFragment("other", "alpha.test")
	f.pushFragment(t, "alpha", frag)
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	if v := f.verdict(t, "alpha"); v.Status != "rejected" || !strings.Contains(v.Reason, "does not match") {
		t.Fatalf("tenant mismatch should reject, got %+v", v)
	}

	// Symlinked fragment (drop area is tenant-writable; merge runs as root):
	// must be refused without reading the target.
	fragPath := filepath.Join(f.home, "home", "alpha", "fragments", "fragment.json")
	os.Remove(fragPath)
	secret := filepath.Join(f.home, "secret.txt")
	if err := os.WriteFile(secret, []byte("root-only"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(secret, fragPath); err != nil {
		t.Fatal(err)
	}
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	if v := f.verdict(t, "alpha"); v.Status != "rejected" || !strings.Contains(v.Reason, "regular file") {
		t.Fatalf("symlink fragment should reject, got %+v", v)
	}
}

// Limits are shard policy: stamped from the root-owned limits.json, and a
// fragment attempting to set its own limits is ignored (the fragment schema
// has no limit fields — this asserts the merged block carries the root file).
func TestMergeStampsRootOwnedLimits(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	limits := tenantLimits{ForwardHourlyLimit: 42, SpoolMaxMiB: 100, SpoolMaxEntries: 500}
	data, _ := json.Marshal(limits)
	if err := os.WriteFile(filepath.Join(f.home, "tenants", "alpha", "limits.json"), data, 0o644); err != nil {
		t.Fatal(err)
	}
	f.pushFragment(t, "alpha", simpleFragment("alpha", "alpha.test"))
	if rc := runMerge(); rc != 0 {
		t.Fatalf("runMerge rc=%d", rc)
	}
	tc := f.mergedMap(t).Tenants["alpha"]
	if tc.ForwardHourlyLimit != 42 || tc.SpoolMaxMiB != 100 || tc.SpoolMaxEntries != 500 {
		t.Fatalf("limits not stamped from root-owned file: %+v", tc)
	}
}

// Unchanged input → unchanged output → no reinstall (the reload-skip contract).
func TestMergeIdempotent(t *testing.T) {
	f := newMergeFixture(t)
	f.addTenant(t, "alpha", []string{"alpha.test"})
	f.pushFragment(t, "alpha", simpleFragment("alpha", "alpha.test"))
	if rc := runMerge(); rc != 0 {
		t.Fatal("first merge failed")
	}
	if v := f.verdict(t, "alpha"); !v.Changed {
		t.Fatalf("first merge should report changed, got %+v", v)
	}
	if rc := runMerge(); rc != 0 {
		t.Fatal("second merge failed")
	}
	if v := f.verdict(t, "alpha"); v.Changed {
		t.Fatalf("second merge should report unchanged, got %+v", v)
	}
}
