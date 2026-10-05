package main

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"golang.org/x/crypto/nacl/box"
)

// TestSealRoundTripInProcess seals to a freshly generated keypair and confirms
// it opens with the NaCl OpenAnonymous — a fast in-process guard. The
// authoritative wire-compatibility check against PHP sodium_crypto_box_seal_open
// lives in roundtrip_test.sh.
func TestSealRoundTripInProcess(t *testing.T) {
	pub, priv, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("GenerateKey: %v", err)
	}
	pubB64 := base64.RawURLEncoding.EncodeToString(pub[:])

	msg := []byte("From: a@example.com\r\nSubject: hi\r\n\r\nbody\r\n")
	wire, err := sealToPublicKey(msg, pubB64)
	if err != nil {
		t.Fatalf("sealToPublicKey: %v", err)
	}
	if !strings.HasPrefix(wire, sealWirePrefix) {
		t.Fatalf("wire missing prefix: %q", wire)
	}
	sealed, err := base64.RawURLEncoding.DecodeString(strings.TrimPrefix(wire, sealWirePrefix))
	if err != nil {
		t.Fatalf("decode wire: %v", err)
	}
	opened, ok := box.OpenAnonymous(nil, sealed, pub, priv)
	if !ok {
		t.Fatal("OpenAnonymous failed")
	}
	if string(opened) != string(msg) {
		t.Fatalf("round trip mismatch: got %q", opened)
	}
}

func TestDecodePublicKeyRejectsWrongLength(t *testing.T) {
	if _, err := decodePublicKey(base64.RawURLEncoding.EncodeToString([]byte("short"))); err == nil {
		t.Fatal("expected error for short key")
	}
}

// TestSRSRewriteMatchesPHPShape checks the structural contract with
// SRSRewriter.php. The exact HMAC value is verified cross-language in
// roundtrip_test.sh; here we assert the field layout and the 6-char hash.
func TestSRSRewriteMatchesPHPShape(t *testing.T) {
	now := time.Date(2026, 7, 7, 0, 0, 0, 0, time.UTC)
	got := srsRewrite("alice@gmail.com", "fwd.example.com", "topsecret", now)
	if !strings.HasPrefix(got, "SRS0=") || !strings.HasSuffix(got, "@fwd.example.com") {
		t.Fatalf("SRS shape wrong: %q", got)
	}
	parts := strings.SplitN(strings.TrimPrefix(strings.Split(got, "@")[0], "SRS0="), "=", 4)
	if len(parts) != 4 {
		t.Fatalf("SRS local part should have 4 =-separated fields: %q", got)
	}
	if len(parts[0]) != 6 {
		t.Fatalf("SRS hash should be 6 chars, got %d (%q)", len(parts[0]), parts[0])
	}
	if parts[2] != "gmail.com" || parts[3] != "alice" {
		t.Fatalf("SRS domain/local wrong: %q", got)
	}
}

// TestExtractMetaCollectsAllAuthResultsInOrder guards Fix 2: a sender who embeds
// a forged Authentication-Results (same authserv-id) lower in the header block
// must not shadow the milter-stamped one. extractMeta must return EVERY A-R in
// document order, with the milter (top) entry first.
func TestExtractMetaCollectsAllAuthResultsInOrder(t *testing.T) {
	raw := []byte(
		"Authentication-Results: mx.example.com; spf=pass; dkim=pass; dmarc=pass\r\n" + // milter, top
			"Received: from somewhere\r\n" +
			"From: attacker@evil.test\r\n" +
			"Authentication-Results: mx.example.com; spf=fail; dkim=fail; dmarc=fail\r\n" + // forged, lower
			"Subject: hi\r\n" +
			"\r\nbody\r\n")
	_, authResults := extractMeta(raw)
	if len(authResults) != 2 {
		t.Fatalf("expected 2 Authentication-Results, got %d: %v", len(authResults), authResults)
	}
	if !strings.Contains(authResults[0], "spf=pass") {
		t.Fatalf("first (milter) A-R should be the pass line, got %q", authResults[0])
	}
	if !strings.Contains(authResults[1], "spf=fail") {
		t.Fatalf("second (forged) A-R should be the fail line, got %q", authResults[1])
	}
}

// TestBuildForwardMessageParity is the Fix 5 parity gate: the Go rewrite must
// reproduce InboundEmailRouter::buildForwardMessage's header treatment exactly.
// SRS is disabled (srs_secret empty) so the envelope sender is the From address
// and there is no timestamp dependency; the expected strings are hand-derived by
// applying the PHP algorithm step by step.
func TestBuildForwardMessageParity(t *testing.T) {
	now := time.Date(2026, 7, 7, 0, 0, 0, 0, time.UTC)
	tc := tenantConfig{
		SRSSecret:       "", // SRS off → envelope sender = From address, deterministic
		ForwardFromName: "Example",
		ForwardShowVia:  true,
	}
	entry := routingEntry{
		Mode:             modeForward,
		ForwardingDomain: "example.com",
		ForwardFrom:      "fwd@example.com",
	}

	t.Run("named sender, no reply-to", func(t *testing.T) {
		raw := []byte("From: \"Alice Smith\" <alice@gmail.com>\r\n" +
			"To: team@example.com\r\n" +
			"Subject: Hi\r\n" +
			"\r\n" +
			"Hello body\r\n")
		want := "From: Alice Smith via Example <fwd@example.com>\r\n" +
			"To: team@example.com\r\n" +
			"Subject: Hi\r\n" +
			"Reply-To: alice@gmail.com\r\n" +
			"X-Original-To: team@example.com\r\n" +
			"X-Forwarded-For: team@example.com\r\n" +
			"X-Forwarded-By: Joinery Inbound Email" +
			"\r\n\r\n" +
			"Hello body\r\n"
		got, env := buildForwardMessage(raw, "team@example.com", entry, tc, now)
		if got != want {
			t.Fatalf("rewrite mismatch:\n--- got ---\n%q\n--- want ---\n%q", got, want)
		}
		if env != "alice@gmail.com" {
			t.Fatalf("envelope sender = %q, want alice@gmail.com", env)
		}
	})

	t.Run("existing reply-to stripped, no display name", func(t *testing.T) {
		raw := []byte("From: bob@x.com\r\n" +
			"Reply-To: someone@y.com\r\n" +
			"Subject: Re\r\n" +
			"\r\n" +
			"body\r\n")
		// PHP removes the Reply-To line content but leaves its blank line in place.
		want := "From: Forwarded via Example <fwd@example.com>\r\n" +
			"\r\n" +
			"Subject: Re\r\n" +
			"Reply-To: bob@x.com\r\n" +
			"X-Original-To: c@example.com\r\n" +
			"X-Forwarded-For: c@example.com\r\n" +
			"X-Forwarded-By: Joinery Inbound Email" +
			"\r\n\r\n" +
			"body\r\n"
		got, _ := buildForwardMessage(raw, "c@example.com", entry, tc, now)
		if got != want {
			t.Fatalf("rewrite mismatch:\n--- got ---\n%q\n--- want ---\n%q", got, want)
		}
	})
}

func TestResolveCatchAllStore(t *testing.T) {
	m := &routingMap{
		Version:    1,
		Recipients: map[string]routingEntry{},
		Domains: map[string]domainEntry{
			"example.com": {
				CatchAllMode:    modeStore,
				RejectUnmatched: true,
				PublicKey:       "pk",
				KeyKind:         keyKindTransport,
			},
		},
	}
	entry, ok := m.resolve("anyone@example.com")
	if !ok || entry.Mode != modeStore || entry.PublicKey != "pk" {
		t.Fatalf("catch-all store resolve wrong: %+v ok=%v", entry, ok)
	}
	if _, ok := m.resolve("anyone@nosuchdomain.com"); ok {
		t.Fatal("unknown domain should not resolve")
	}
	if !m.rejectUnmatched("x@example.com") {
		t.Fatal("expected rejectUnmatched true")
	}
}

// A refusing domain's access map accepts postmaster (RFC 5321 §4.5.1, and the
// DMARC rua points at it), so with no alias and no catch-all SMTP delivery
// must carry it to the site, never refuse it after acceptance (a bounce). It
// seals to the tenant's transport key, which the site always holds. resolve()
// itself still answers no such recipient (Direct, seal target). An alias for
// postmaster, or a catch-all, still decides as before.
func TestResolvePostmasterOnARefusingDomain(t *testing.T) {
	m := &routingMap{
		Version:    1,
		Recipients: map[string]routingEntry{},
		Tenants:    map[string]tenantConfig{"main": {TransportPublicKey: "tpk"}},
		Domains: map[string]domainEntry{
			"refuse.example": {CatchAllMode: "none", RejectUnmatched: true, PublicKey: "dpk", KeyKind: "client", Tenant: "main"},
			"fwd.example":    {CatchAllMode: modeForward, CatchAllAddress: "boss@elsewhere.example", RejectUnmatched: true, Tenant: "main"},
		},
	}
	if _, ok := m.resolve("postmaster@refuse.example"); ok {
		t.Fatal("resolve() must still answer no such recipient: Direct and the seal target read it")
	}
	entry, ok := m.postmasterFallback("PostMaster@refuse.example")
	if !ok || entry.Mode != modeStore || entry.KeyKind != keyKindTransport || entry.PublicKey != "tpk" || entry.Tenant != "main" {
		t.Fatalf("postmaster delivery should store under the transport key, got %+v ok=%v", entry, ok)
	}
	if _, ok := m.postmasterFallback("nobody@refuse.example"); ok {
		t.Fatal("the fallback is for postmaster only")
	}
	if _, ok := m.postmasterFallback("postmaster@nosuch.example"); ok {
		t.Fatal("the fallback is for our domains only")
	}
	if entry, ok := m.resolve("postmaster@fwd.example"); !ok || entry.Mode != modeForward {
		t.Fatalf("a catch-all forward still takes postmaster, got %+v ok=%v", entry, ok)
	}
	m.Recipients["postmaster@refuse.example"] = routingEntry{Mode: modeStore, PublicKey: "alias-pk", KeyKind: "user", Tenant: "main"}
	if entry, ok := m.resolve("postmaster@refuse.example"); !ok || entry.PublicKey != "alias-pk" {
		t.Fatalf("a postmaster alias still wins, got %+v ok=%v", entry, ok)
	}
}

// TestResolveSRSBounce guards Fix 6: an SRS bounce returning to a forwarding
// domain must resolve to a transport-sealed store even when it matches no alias
// and its domain is a reject_unmatched forwarding subdomain. The map here is a
// LEGACY (pre-tenancy) shape — normalize() must lift it into a synthesized
// tenant so the bounce still seals to the right transport key.
func TestResolveSRSBounce(t *testing.T) {
	m := &routingMap{
		TransportPublicKey: "tpk",
		ForwardingDomains:  []string{"fwd.example.com"},
		Recipients:         map[string]routingEntry{},
		Domains: map[string]domainEntry{
			"fwd.example.com": {CatchAllMode: modeStore, RejectUnmatched: true},
		},
	}
	m.normalize()
	entry, ok := m.resolve("SRS0=abc=de=gmail.com=alice@fwd.example.com")
	if !ok {
		t.Fatal("SRS bounce should resolve")
	}
	if entry.Mode != modeStore || entry.KeyKind != keyKindTransport || entry.PublicKey != "tpk" {
		t.Fatalf("SRS bounce should be transport-sealed store, got %+v", entry)
	}
	if entry.Tenant != legacyTenantSlug {
		t.Fatalf("legacy map SRS bounce should land in the synthesized tenant, got %q", entry.Tenant)
	}
	if tc, ok := m.tenantFor(entry); !ok || tc.TransportPublicKey != "tpk" {
		t.Fatalf("tenantFor should resolve the synthesized tenant, got %+v ok=%v", tc, ok)
	}
	// A non-SRS unknown recipient at a forwarding domain still follows normal rules.
	if _, ok := m.resolve("nobody@notforwarding.test"); ok {
		t.Fatal("unknown non-SRS recipient at unknown domain must not resolve")
	}
	if !isSRSLocalPart("srs0=x@d.com") {
		t.Fatal("isSRSLocalPart should be case-insensitive")
	}
}

// TestSealAndSpoolPreservesRecipientCaseAndSealKey guards R2-3/R2-7: the .meta
// sidecar must carry the recipient's original local-part case (SRS bounce
// hashes are case-sensitive — lowercasing breaks validation on the main box)
// and the exact public key the blob was sealed to (the pull consumer's owner
// fallback when alias grants changed between seal and pull).
func TestSealAndSpoolPreservesRecipientCaseAndSealKey(t *testing.T) {
	pub, _, err := box.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("GenerateKey: %v", err)
	}
	pubB64 := base64.RawURLEncoding.EncodeToString(pub[:])

	recipient := "SRS0=AbC9+z=5x=Gmail.com=Alice.Smith@fwd.example.com"
	entry := routingEntry{PublicKey: pubB64, KeyKind: keyKindTransport, Mode: modeStore}
	m := &routingMap{Version: 7}
	spoolDir := t.TempDir()

	raw := []byte("From: mailer-daemon@dest.test\r\nSubject: failure\r\n\r\nbounce\r\n")
	if code := sealAndSpool(raw, recipient, "<>", entry, m, spoolDir); code != exitOK {
		t.Fatalf("sealAndSpool exit %d", code)
	}

	metas, err := filepath.Glob(filepath.Join(spoolDir, "*.meta"))
	if err != nil || len(metas) != 1 {
		t.Fatalf("expected one .meta, got %v (%v)", metas, err)
	}
	data, err := os.ReadFile(metas[0])
	if err != nil {
		t.Fatalf("read meta: %v", err)
	}
	var meta spoolMeta
	if err := json.Unmarshal(data, &meta); err != nil {
		t.Fatalf("parse meta: %v", err)
	}
	if meta.Recipient != recipient {
		t.Fatalf("recipient case not preserved: %q", meta.Recipient)
	}
	if meta.PublicKey != pubB64 {
		t.Fatalf("seal public key missing from meta: %q", meta.PublicKey)
	}
}
