package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"testing"
	"time"
)

// The seal-target statement (specs/client_custody_mail.md § R10): it verifies
// under the relay's identity key over the prefixed bytes, a changed byte fails,
// and it answers only for the asking tenant's own storing recipients.
func TestSealTargetStatementVerifiesAndBinds(t *testing.T) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	id := &relayIdentity{public: pub, private: priv}
	m := &routingMap{Version: 42, Tenants: map[string]tenantConfig{"a": {}, "b": {}}, Recipients: map[string]routingEntry{
		"box@fortress.test":    {Tenant: "a", PublicKey: "browser-pk", KeyKind: keyKindClient, KeyScope: "mail", KeyGeneration: 3, Mode: modeStore},
		"fwd@fortress.test":    {Tenant: "a", Mode: modeForward, Destinations: []string{"x@y.test"}},
		"other@elsewhere.test": {Tenant: "b", PublicKey: "pk-b", KeyKind: keyKindTransport, Mode: modeStore},
	}}
	now := time.Date(2026, 9, 28, 12, 0, 0, 0, time.UTC)

	statement, signature, ok := buildSealTarget(m, "a", " Box@Fortress.test ", id, now)
	if !ok {
		t.Fatal("a storing recipient of the asking tenant must answer")
	}
	sig, err := base64.StdEncoding.DecodeString(signature)
	if err != nil {
		t.Fatalf("signature is not standard base64: %v", err)
	}
	if !ed25519.Verify(pub, []byte(relaySealTargetSigningPrefix+statement), sig) {
		t.Fatal("the statement must verify under the identity key over the prefixed bytes")
	}
	if ed25519.Verify(pub, []byte(statement), sig) {
		t.Fatal("the signature must not verify without the domain-separating prefix")
	}
	tampered := []byte(statement)
	tampered[len(tampered)/2] ^= 0x01
	if ed25519.Verify(pub, append([]byte(relaySealTargetSigningPrefix), tampered...), sig) {
		t.Fatal("a changed byte must fail")
	}

	want := `{"recipient":"box@fortress.test","public_key":"browser-pk","key_kind":"client","key_scope":"mail",` +
		`"key_generation":3,"map_version":42,"relay_identity_public_key":"` + base64.StdEncoding.EncodeToString(pub) +
		`","signed_at":"2026-09-28T12:00:00Z"}`
	if statement != want {
		t.Fatalf("statement not canonical:\n got  %s\n want %s", statement, want)
	}
	var parsed sealTargetStatement
	if err := json.Unmarshal([]byte(statement), &parsed); err != nil || parsed.PublicKey != "browser-pk" {
		t.Fatalf("statement does not parse back: %v", err)
	}

	if _, _, ok := buildSealTarget(m, "a", "other@elsewhere.test", id, now); ok {
		t.Fatal("another tenant's recipient must not answer")
	}
	if _, _, ok := buildSealTarget(m, "a", "fwd@fortress.test", id, now); ok {
		t.Fatal("a forward-only recipient seals nothing and must not answer")
	}
	if _, _, ok := buildSealTarget(m, "a", "nobody@fortress.test", id, now); ok {
		t.Fatal("an unknown recipient must not answer")
	}
}
