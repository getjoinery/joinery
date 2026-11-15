package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"os"
	"strings"
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

	statement, signature, ok := buildSealTarget(m, "a", " Box@Fortress.test ", "", id, now)
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

	if _, _, ok := buildSealTarget(m, "a", "other@elsewhere.test", "", id, now); ok {
		t.Fatal("another tenant's recipient must not answer")
	}
	if _, _, ok := buildSealTarget(m, "a", "fwd@fortress.test", "", id, now); ok {
		t.Fatal("a forward-only recipient seals nothing and must not answer")
	}
	if _, _, ok := buildSealTarget(m, "a", "nobody@fortress.test", "", id, now); ok {
		t.Fatal("an unknown recipient must not answer")
	}
}

// A browser's challenge is signed into the statement as its last field, and an
// ask without one signs exactly what relays before 3.18 signed.
func TestSealTargetSignsTheChallenge(t *testing.T) {
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	id := &relayIdentity{public: pub, private: priv}
	m := &routingMap{Version: 9, Tenants: map[string]tenantConfig{"a": {}}, Recipients: map[string]routingEntry{
		"box@fortress.test": {Tenant: "a", PublicKey: "browser-pk", KeyKind: keyKindClient, KeyScope: "mail", Mode: modeStore},
	}}
	now := time.Date(2026, 11, 15, 12, 0, 0, 0, time.UTC)
	challenge := "q3Jx0mVb_k2n-Lp8ZtR4wA"

	statement, signature, ok := buildSealTarget(m, "a", "box@fortress.test", challenge, id, now)
	if !ok {
		t.Fatal("a storing recipient must answer")
	}
	want := `{"recipient":"box@fortress.test","public_key":"browser-pk","key_kind":"client","key_scope":"mail",` +
		`"key_generation":1,"map_version":9,"relay_identity_public_key":"` + base64.StdEncoding.EncodeToString(pub) +
		`","signed_at":"2026-11-15T12:00:00Z","challenge":"` + challenge + `"}`
	if statement != want {
		t.Fatalf("statement not canonical:\n got  %s\n want %s", statement, want)
	}
	sig, _ := base64.StdEncoding.DecodeString(signature)
	if !ed25519.Verify(pub, []byte(relaySealTargetSigningPrefix+statement), sig) {
		t.Fatal("the challenge is under the signature")
	}

	bare, _, _ := buildSealTarget(m, "a", "box@fortress.test", "", id, now)
	if strings.Contains(bare, "challenge") {
		t.Fatalf("an ask without a challenge must not carry the field: %s", bare)
	}

	for _, bad := range []string{"short", "has space in it 1234567890", "plus+slash/not-base64url==", strings.Repeat("a", 65)} {
		if sealTargetChallengePattern.MatchString(bad) {
			t.Fatalf("challenge %q must be refused", bad)
		}
	}
	if !sealTargetChallengePattern.MatchString(challenge) {
		t.Fatal("a 22-character base64url challenge must be accepted")
	}
}

// The shared vector (plugins/mailbox/tests/fixtures/relay_pin_vector.json,
// challenge_vector): from the same test seed the relay signs the same bytes the
// browser's self-check and the PHP test hold.
func TestSealTargetChallengeVector(t *testing.T) {
	raw, err := os.ReadFile("../../tests/fixtures/relay_pin_vector.json")
	if err != nil {
		t.Fatal(err)
	}
	var file struct {
		V struct {
			Seed      string `json:"relay_seed_hex"`
			Challenge string `json:"challenge"`
			Statement string `json:"statement"`
			Signature string `json:"signature"`
		} `json:"challenge_vector"`
	}
	if err := json.Unmarshal(raw, &file); err != nil {
		t.Fatal(err)
	}
	seed, err := hex.DecodeString(file.V.Seed)
	if err != nil || len(seed) != ed25519.SeedSize {
		t.Fatalf("bad seed: %v", err)
	}
	priv := ed25519.NewKeyFromSeed(seed)
	id := &relayIdentity{public: priv.Public().(ed25519.PublicKey), private: priv}
	m := &routingMap{Version: 8, Tenants: map[string]tenantConfig{"a": {}}, Recipients: map[string]routingEntry{
		"box@fortress.test": {Tenant: "a", PublicKey: "YO6P+WWilqSUxSipdAY4nfvIGw9u4mFN3hBKas4ZBVY=", KeyKind: keyKindClient, KeyScope: "mail", Mode: modeStore},
	}}
	statement, signature, ok := buildSealTarget(m, "a", "box@fortress.test", file.V.Challenge, id,
		time.Date(2026, 11, 15, 12, 0, 0, 0, time.UTC))
	if !ok || statement != file.V.Statement || signature != file.V.Signature {
		t.Fatalf("the relay does not rebuild the vector:\n got  %s\n      %s\n want %s\n      %s", statement, signature, file.V.Statement, file.V.Signature)
	}
}
