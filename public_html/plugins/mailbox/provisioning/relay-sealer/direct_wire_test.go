package main

// The Direct wire, tested where it can be got wrong silently.
//
// The interop bytes are pinned against PHP by direct_wire_gate.sh; these are the
// unit-level properties that gate does not cover — the freshness window, the
// manifest bounds, the decoy's two load-bearing properties, and the session and
// replay state machine.

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/nacl/box"
)

// noKeyResolver makes every capability lookup come back empty, so an unsigned or
// unknown-sender preflight fails at the signature step without touching real DNS.
type noKeyResolver struct{}

func (noKeyResolver) LookupSRV(ctx context.Context, name string) (string, int, error) {
	return "", 0, context.Canceled
}

func (noKeyResolver) LookupTXT(ctx context.Context, name string) ([]string, error) {
	return nil, context.Canceled
}

// TestServedDomainDoesNotLeakBeforeAuth pins the fix for the pre-auth tenant
// enumeration: an unauthenticated peer must not be able to tell a domain this
// relay fronts from one it does not. Before the reorder, an unserved domain
// answered 404 at the tenant check while a served one fell through to the
// signature's 403 — two different answers a prober could map. Now the signature
// is verified FIRST, so both answer 403 and the tenant check is never reached
// unauthenticated.
func TestServedDomainDoesNotLeakBeforeAuth(t *testing.T) {
	dir := t.TempDir()
	m := &routingMap{
		Version: 1,
		Tenants: map[string]tenantConfig{"main": {DirectEnabled: true}},
		Domains: map[string]domainEntry{"served.example": {Tenant: "main"}},
	}
	data, err := json.Marshal(m)
	if err != nil {
		t.Fatalf("marshal map: %v", err)
	}
	mapPath := filepath.Join(dir, "routing.json")
	if err := os.WriteFile(mapPath, data, 0o600); err != nil {
		t.Fatalf("write map: %v", err)
	}

	h := newDirectHandler(mapPath, dir, dir)
	h.capabilities.resolver = noKeyResolver{}

	statusFor := func(recipient string) int {
		body, _ := json.Marshal(directPreflight{
			Envelope: directEnvelope{
				ProtocolVersion: 1, Kind: "mail", Sender: "someone@sender.example",
				Recipient: recipient, KeyID: "k1",
				Nonce:     "abcdef0123456789abcdef0123456789",
				Timestamp: time.Now().UTC().Format("2006-01-02 15:04:05"),
			},
			Manifest:  []directManifestEntry{{Role: "body_text", ContentType: "text/plain", Size: 1}},
			Signature: "not-a-valid-signature",
		})
		req := httptest.NewRequest(http.MethodPost, directEndpointPath+"?step=preflight", bytes.NewReader(body))
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		return rec.Code
	}

	served := statusFor("user@served.example")
	unserved := statusFor("user@unserved.example")

	if served != http.StatusForbidden {
		t.Fatalf("an unsigned preflight to a served domain should stop at the signature (403), got %d", served)
	}
	if unserved != served {
		t.Fatalf("unserved domain answered %d, served answered %d — a pre-auth prober can tell them apart",
			unserved, served)
	}
}

// TestKeylessRealRecipientAcceptsWithoutADecoyKey pins the PHP/Go parity fix: a
// REAL recipient with no vault key (a group alias) must accept KEYLESS, exactly
// as the box's own resolver does in A2. The old relay handed it a decoy key, so
// the sender sealed real mail to a key nobody holds and it was destroyed. A
// nonexistent address still gets a decoy, so existence is not otherwise handed out.
func TestKeylessRealRecipientAcceptsWithoutADecoyKey(t *testing.T) {
	dir := t.TempDir()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	pubB64 := base64.StdEncoding.EncodeToString(pub)

	tc := tenantConfig{
		DirectEnabled: true, DirectDecoySecret: "a-decoy-seed",
		DirectMaxParts: 8, DirectMaxPartBytes: 1000, DirectMaxTotalBytes: 5000,
		DirectPreflightLimit: 100, DirectPreflightWindow: 120,
		DirectSessionTTLSeconds: 900, DirectKinds: []string{"mail"}, SpoolDir: dir,
	}
	m := &routingMap{
		Version:    1,
		Tenants:    map[string]tenantConfig{"main": tc},
		Domains:    map[string]domainEntry{"served.example": {Tenant: "main"}},
		Recipients: map[string]routingEntry{"group@served.example": {Tenant: "main"}}, // real, NO public key
	}
	data, _ := json.Marshal(m)
	mapPath := filepath.Join(dir, "routing.json")
	if err := os.WriteFile(mapPath, data, 0o600); err != nil {
		t.Fatal(err)
	}

	h := newDirectHandler(mapPath, dir, dir)
	h.capabilities.resolver = noKeyResolver{}
	h.capabilities.entries["sender.example"] = &capabilityRecord{
		Keys: map[string]string{"k1": pubB64}, present: true, expiresAt: time.Now().Add(time.Hour),
	}

	accept := func(recipient, nonce string) map[string]any {
		env := directEnvelope{
			ProtocolVersion: 1, Kind: "mail", Sender: "me@sender.example",
			Recipient: recipient, KeyID: "k1", Nonce: nonce,
			Timestamp: time.Now().UTC().Format("2006-01-02 15:04:05"),
		}
		manifest := []directManifestEntry{{Role: "body_text", ContentType: "text/plain", Size: 1}}
		signed, err := preflightSigningBytes(env, manifest)
		if err != nil {
			t.Fatalf("sign bytes: %v", err)
		}
		sig := base64.StdEncoding.EncodeToString(ed25519.Sign(priv, signed))
		body, _ := json.Marshal(directPreflight{Envelope: env, Manifest: manifest, Signature: sig})
		req := httptest.NewRequest(http.MethodPost, directEndpointPath+"?step=preflight", bytes.NewReader(body))
		rec := httptest.NewRecorder()
		h.ServeHTTP(rec, req)
		if rec.Code != http.StatusOK {
			t.Fatalf("expected 200 accept for %s, got %d: %s", recipient, rec.Code, rec.Body.String())
		}
		var out map[string]any
		if err := json.Unmarshal(rec.Body.Bytes(), &out); err != nil {
			t.Fatalf("parse accept: %v", err)
		}
		return out
	}

	real := accept("group@served.example", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
	if real["answer"] != directAnswerAccept {
		t.Fatalf("a keyless real recipient must accept, got %v", real)
	}
	if _, hasKey := real["key"]; hasKey {
		t.Fatalf("a keyless real recipient must accept WITHOUT a key — a decoy key here seals real mail to a "+
			"key nobody holds and destroys it; got %v", real)
	}

	absent := accept("nobody@served.example", "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	if _, hasKey := absent["key"]; !hasKey {
		t.Fatalf("a nonexistent address should still receive a decoy key, got %v", absent)
	}
}

func TestPreflightSigningBytesAreCanonical(t *testing.T) {
	env := directEnvelope{
		ProtocolVersion: 1,
		Kind:            "mail",
		Sender:          "Alice@Example.com",
		Recipient:       "BOB@receiver.test",
		KeyID:           "k1",
		Nonce:           "abcdef0123456789abcdef0123456789",
		Timestamp:       "2026-08-12 10:00:00",
	}
	manifest := []directManifestEntry{
		{Role: roleBodyText, ContentType: "text/plain", Size: 12},
	}

	got, err := preflightSigningBytes(env, manifest)
	if err != nil {
		t.Fatalf("preflightSigningBytes: %v", err)
	}
	s := string(got)

	if !strings.HasPrefix(s, "joinery-direct:preflight:v1\n") {
		t.Fatalf("missing version prefix: %q", s)
	}
	// Addresses are compared lowercased everywhere else, so they are signed
	// lowercased. A mixed-case From must not produce a different signature.
	if strings.Contains(s, "Alice@Example.com") || !strings.Contains(s, "alice@example.com") {
		t.Fatalf("addresses are not lowercased in the signed bytes: %q", s)
	}
	// Field order is the contract; a sorted-key encoder would break interop.
	if idx := strings.Index(s, `"v":1,"kind":"mail","sender":`); idx < 0 {
		t.Fatalf("canonical field order changed: %q", s)
	}
	// Go escapes < > & by default; PHP does not. The two must agree.
	if strings.Contains(s, `<`) || strings.Contains(s, `&`) {
		t.Fatalf("HTML escaping leaked into the signed bytes: %q", s)
	}
	// A manifest entry carries no hash: those cover the SEALED bytes, which do
	// not exist at preflight.
	if strings.Contains(s, `"hash"`) {
		t.Fatalf("a manifest entry must not carry a hash: %q", s)
	}
}

func TestEmptyManifestEncodesAsArrayNotNull(t *testing.T) {
	// A nil slice encodes as null in Go and [] in PHP. Left alone this would be
	// a silent signature mismatch on exactly the message shape nobody tests.
	got, err := preflightSigningBytes(directEnvelope{ProtocolVersion: 1}, nil)
	if err != nil {
		t.Fatalf("preflightSigningBytes: %v", err)
	}
	if !strings.Contains(string(got), `"manifest":[]`) {
		t.Fatalf("empty manifest must encode as []: %q", string(got))
	}
}

func TestTransferSignatureIsBoundToItsNonce(t *testing.T) {
	hashes := []string{hashBytes([]byte("one")), hashBytes([]byte("two"))}

	a, _ := transferSigningBytes("nonce-a", hashes)
	b, _ := transferSigningBytes("nonce-b", hashes)
	if string(a) == string(b) {
		t.Fatal("the same hashes under a different nonce must sign differently — " +
			"otherwise content can be spliced onto another preflight")
	}

	reversed := []string{hashes[1], hashes[0]}
	c, _ := transferSigningBytes("nonce-a", reversed)
	if string(a) == string(c) {
		t.Fatal("part order is part of what is signed")
	}
}

func TestSealedSizeCeilingCoversRealSealing(t *testing.T) {
	// The manifest declares plaintext sizes; the bytes that arrive are sealed
	// and larger. If the ceiling were ever below the real sealed size, every
	// honest sealed delivery would abort for arriving exactly as asked.
	//
	// A Direct part is sealed RAW (crypto_box_seal, no base64) — that is what the
	// ceiling measures. NOT sealToPublicKey, which base64-wraps for the SMTP relay
	// spool: a different format, opened by a different path, and never a Direct
	// part on the wire.
	pk, err := decodePublicKey(testPublicKey(t))
	if err != nil {
		t.Fatalf("decode key: %v", err)
	}
	for _, size := range []int{0, 1, 17, 1000, 1 << 20} {
		plaintext := strings.Repeat("x", size)
		sealed, err := box.SealAnonymous(nil, []byte(plaintext), pk, rand.Reader)
		if err != nil {
			t.Fatalf("seal: %v", err)
		}
		if int64(len(sealed)) != sealedSizeCeiling(int64(size)) {
			t.Fatalf("raw-sealed %d bytes to %d, ceiling says %d — the two must agree exactly",
				size, len(sealed), sealedSizeCeiling(int64(size)))
		}
	}
}

func TestFreshnessWindow(t *testing.T) {
	now := time.Now().UTC()
	cases := []struct {
		name    string
		stamp   time.Time
		refused bool
	}{
		{"now", now, false},
		{"a minute old", now.Add(-time.Minute), false},
		{"an hour old", now.Add(-time.Hour), true},
		{"slightly ahead (clock skew)", now.Add(30 * time.Second), false},
		{"well into the future", now.Add(time.Hour), true},
	}
	for _, c := range cases {
		why := freshnessError(c.stamp.Format("2006-01-02 15:04:05"))
		if (why != "") != c.refused {
			t.Fatalf("%s: refused=%v (%q)", c.name, why != "", why)
		}
	}
	if freshnessError("not a timestamp") == "" {
		t.Fatal("an unparseable timestamp must be refused")
	}
}

func TestManifestBounds(t *testing.T) {
	tc := tenantConfig{DirectMaxParts: 2, DirectMaxPartBytes: 100, DirectMaxTotalBytes: 150}

	if _, why := manifestBoundsError(nil, tc); why == "" {
		t.Fatal("an empty manifest must be refused")
	}
	if _, why := manifestBoundsError([]directManifestEntry{{Size: 10}, {Size: 10}, {Size: 10}}, tc); why == "" {
		t.Fatal("too many parts must be refused")
	}
	if _, why := manifestBoundsError([]directManifestEntry{{Size: 101}}, tc); why == "" {
		t.Fatal("a part over the per-part cap must be refused")
	}
	if _, why := manifestBoundsError([]directManifestEntry{{Size: 100}, {Size: 100}}, tc); why == "" {
		t.Fatal("a message over the total cap must be refused")
	}
	total, why := manifestBoundsError([]directManifestEntry{{Size: 50}, {Size: 60}}, tc)
	if why != "" || total != 110 {
		t.Fatalf("a conforming manifest must pass and report its total; got %d %q", total, why)
	}
}

func TestDecoyKeysAreValidDeterministicAndDistinct(t *testing.T) {
	secret := "a domain secret"

	a := decoyPublicKey(secret, "nobody@example.com")
	b := decoyPublicKey(secret, "NOBODY@Example.com")
	if a == "" || a != b {
		t.Fatalf("a decoy must be deterministic and case-insensitive: %q vs %q", a, b)
	}
	if decoyPublicKey(secret, "someone-else@example.com") == a {
		t.Fatal("different addresses must get different decoys")
	}
	if decoyPublicKey("another secret", "nobody@example.com") == a {
		t.Fatal("a different domain secret must produce a different decoy")
	}

	raw, err := base64.RawURLEncoding.DecodeString(a)
	if err != nil || len(raw) != x25519PublicKeyBytes {
		t.Fatalf("a decoy must be a full-length X25519 public key: %v (%d bytes)", err, len(raw))
	}
	// The load-bearing property: a sender must be able to SEAL to it, or a
	// malformed-key failure would identify the decoy immediately.
	if _, err := sealToPublicKey([]byte("probe"), a); err != nil {
		t.Fatalf("sealing to a decoy must succeed exactly as to a real key: %v", err)
	}
}

func TestReplayAndSessionStateMachine(t *testing.T) {
	dir := t.TempDir()
	state := newDirectState(dir+"/nonces", time.Minute)

	nonce := "abcdef0123456789abcdef0123456789"
	if !state.claimNonce(nonce) {
		t.Fatal("a fresh nonce must be claimable")
	}
	if state.claimNonce(nonce) {
		t.Fatal("the same nonce must not be claimable twice")
	}
	// A restart must not reopen the replay window.
	if newDirectState(dir+"/nonces", time.Minute).claimNonce(nonce) {
		t.Fatal("a nonce must survive a restart — otherwise a captured preflight replays")
	}

	sess := &directSession{Nonce: "n1", Manifest: []directManifestEntry{{Size: 4}}}
	state.openSession(sess, time.Minute)
	if state.liveSession("n1") == nil {
		t.Fatal("an opened session must be live")
	}
	if state.redeem("n1") == nil {
		t.Fatal("a live session must redeem once")
	}
	if state.redeem("n1") != nil {
		t.Fatal("a redeemed session must not redeem again — that is content-transfer replay")
	}
	if state.redeem("never-opened") != nil {
		t.Fatal("an unknown nonce must redeem nothing")
	}

	expired := &directSession{Nonce: "n2"}
	state.openSession(expired, time.Minute)
	expired.ExpiresAt = time.Now().Add(-time.Second)
	if state.liveSession("n2") != nil || state.redeem("n2") != nil {
		t.Fatal("an expired session is neither live nor redeemable")
	}
}

func TestRateLimitsAreSlidingWindows(t *testing.T) {
	state := newDirectState("", time.Minute)
	for i := 0; i < 3; i++ {
		if !state.withinInstanceRate("peer.example", 3, time.Minute) {
			t.Fatalf("request %d should be inside the limit", i)
		}
	}
	if state.withinInstanceRate("peer.example", 3, time.Minute) {
		t.Fatal("the fourth request must be over the limit")
	}
	if !state.withinInstanceRate("other.example", 3, time.Minute) {
		t.Fatal("the limit is per instance, so another domain is unaffected")
	}
	// Zero means no limit — a self-hosted fleet of one sets no cap.
	if !state.withinInstanceRate("peer.example", 0, time.Minute) {
		t.Fatal("a zero limit must mean unlimited")
	}
}

func TestServedKindsAreDataNotCode(t *testing.T) {
	tc := tenantConfig{DirectKinds: []string{"mail", "chat"}}
	if !tc.servesKind("mail") || !tc.servesKind("chat") {
		t.Fatal("a declared kind must be served")
	}
	if tc.servesKind("calendar") {
		t.Fatal("an undeclared kind must not be served")
	}
	if (tenantConfig{}).servesKind("mail") {
		t.Fatal("a tenant declaring no kinds serves none")
	}
}

func TestTenantForDomain(t *testing.T) {
	m := &routingMap{
		Tenants: map[string]tenantConfig{"main": {DirectEnabled: true, SpoolDir: "/spool/main"}},
		Domains: map[string]domainEntry{"example.com": {Tenant: "main"}},
		Recipients: map[string]routingEntry{
			"solo@other.example": {Tenant: "main"},
		},
	}
	m.normalize()

	if slug, _, ok := m.tenantForDomain("example.com"); !ok || slug != "main" {
		t.Fatalf("a fronted domain must resolve to its tenant; got %q %v", slug, ok)
	}
	if _, _, ok := m.tenantForDomain("other.example"); !ok {
		t.Fatal("a domain served only through a recipient row must still resolve")
	}
	if _, _, ok := m.tenantForDomain("not-ours.example"); ok {
		t.Fatal("a domain this relay does not front must not resolve")
	}
}

func TestEgressTargetGuard(t *testing.T) {
	// The relay must be no more permissive than the box's own guard, or it
	// becomes a way around it.
	for _, bad := range []string{
		"https://127.0.0.1/x",
		"https://10.1.2.3/x",
		"https://169.254.169.254/x",
		"https://example.com:22/x",
		"https://example.com:25/x",
		"http://example.com/x",
	} {
		u := mustParse(t, bad)
		if u.Scheme != "https" {
			continue // the scheme check happens before this guard
		}
		if err := checkEgressTarget(u); err == nil {
			t.Fatalf("%s must be refused", bad)
		}
	}
}

func TestIsPublicIPMatchesThePlatformTable(t *testing.T) {
	blocked := []string{"0.0.0.1", "10.0.0.1", "127.0.0.1", "169.254.169.254",
		"172.16.0.1", "192.168.1.1", "100.64.0.1", "224.0.0.1", "240.0.0.1", "::1"}
	for _, s := range blocked {
		if isPublicIP(parseIP(s)) {
			t.Fatalf("%s must not count as public", s)
		}
	}
	for _, s := range []string{"8.8.8.8", "1.1.1.1", "2606:4700::1111"} {
		if !isPublicIP(parseIP(s)) {
			t.Fatalf("%s must count as public", s)
		}
	}
}

func TestSpoolCapsCountHeldBytes(t *testing.T) {
	dir := t.TempDir()
	write := func(id, recipient string, size int) {
		meta, _ := json.Marshal(directSpoolMeta{
			SpoolID: id, Artifact: "direct", Recipient: recipient, Size: size,
		})
		if err := writeDurable(dir+"/tmp", dir+"/"+id+".meta", meta); err != nil {
			t.Fatalf("write meta: %v", err)
		}
	}
	if err := mkdirAll(dir + "/tmp"); err != nil {
		t.Fatalf("tmp: %v", err)
	}
	write("a", "held@example.com", 100)
	write("b", "other@example.com", 50)

	byDomain, byAddress := directSpoolBytes(dir, "held@example.com", "example.com")
	if byDomain != 150 || byAddress != 100 {
		t.Fatalf("held bytes: domain=%d address=%d", byDomain, byAddress)
	}

	tc := tenantConfig{DirectSpoolDomainCap: 200, DirectSpoolAddressCap: 120}
	if why := directSpoolCapRefusal(dir, "held@example.com", "example.com", 10, tc); why != "" {
		t.Fatalf("a delivery inside both caps must be accepted: %q", why)
	}
	if why := directSpoolCapRefusal(dir, "held@example.com", "example.com", 30, tc); why == "" {
		t.Fatal("a delivery over the ADDRESS cap must be refused")
	}
	if why := directSpoolCapRefusal(dir, "fresh@example.com", "example.com", 100, tc); why == "" {
		t.Fatal("a delivery over the DOMAIN cap must be refused")
	}
}

// --- small helpers, kept at the bottom so the tests above read as prose ---

func testPublicKey(t *testing.T) string {
	t.Helper()
	// A fixed, valid X25519 public key: the decoy derivation produces one from
	// any secret, which is exactly the property being relied on here.
	return decoyPublicKey("test-secret", "anyone@example.com")
}

func mustParse(t *testing.T, raw string) *url.URL {
	t.Helper()
	u, err := url.Parse(raw)
	if err != nil {
		t.Fatalf("parse %s: %v", raw, err)
	}
	return u
}

func parseIP(s string) net.IP { return net.ParseIP(s) }

func mkdirAll(dir string) error { return os.MkdirAll(dir, 0o700) }
