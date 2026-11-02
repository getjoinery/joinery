package main

// The browser format, as the relay writes it for a Fortress mailbox
// (specs/client_custody_mail.md § R9, WP7).
//
// The field bytes are pinned against the shared vector every other
// implementation reproduces (tests/vault/fixtures/edge_vector.json: WebCrypto
// made it, PHP and vault-crypto.js selfCheck() reproduce it); the DEK is sealed
// with the v2 seal, pinned by xwing_test.go. roundtrip_test.sh adds the
// PHP-opens-what-Go-sealed case with a fresh keypair.

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type edgeVector struct {
	DEKHex         string `json:"dek_hex"`
	FieldIVHex     string `json:"field_iv_hex"`
	FieldAD        string `json:"field_ad"`
	FieldPlaintext string `json:"field_plaintext"`
	FieldBlobB64   string `json:"field_blob_b64"`
}

func loadEdgeVector(t *testing.T) edgeVector {
	t.Helper()
	data, err := os.ReadFile(filepath.Join("..", "..", "..", "..", "tests", "vault", "fixtures", "edge_vector.json"))
	if err != nil {
		t.Fatalf("read the shared vector: %v", err)
	}
	var v edgeVector
	if err := json.Unmarshal(data, &v); err != nil {
		t.Fatalf("parse the shared vector: %v", err)
	}
	return v
}

func mustHex(t *testing.T, s string) []byte {
	t.Helper()
	b, err := hex.DecodeString(s)
	if err != nil {
		t.Fatalf("hex: %v", err)
	}
	return b
}

// edgeRecipient is a fresh X-Wing key for a client-custody recipient: the
// seed (the browser's secret) and the public key as the routing map carries it.
func edgeRecipient(t *testing.T) ([]byte, string) {
	t.Helper()
	seed := make([]byte, 32)
	if _, err := rand.Read(seed); err != nil {
		t.Fatalf("seed: %v", err)
	}
	pub, err := xwingPublicKey(seed)
	if err != nil {
		t.Fatalf("xwingPublicKey: %v", err)
	}
	return seed, base64.StdEncoding.EncodeToString(pub)
}

// openEdgeForTest is the browser's side: open a sealed DEK with the recipient
// seed, then a field under that DEK.
func openEdgeForTest(t *testing.T, sealedB64 string, seed []byte) []byte {
	t.Helper()
	raw, err := base64.StdEncoding.DecodeString(sealedB64)
	if err != nil {
		t.Fatalf("sealed DEK is not standard base64: %v", err)
	}
	dek, err := openV2(raw, seed, v2ScopeDEK)
	if err != nil {
		t.Fatalf("the sealed DEK does not open: %v", err)
	}
	return dek
}

func openFieldForTest(t *testing.T, blobB64 string, dek []byte, ad string) []byte {
	t.Helper()
	raw, err := base64.StdEncoding.DecodeString(blobB64)
	if err != nil {
		t.Fatalf("field is not standard base64: %v", err)
	}
	gcm, _ := newGCM(dek)
	plain, err := gcm.Open(nil, raw[:edgeIVBytes], raw[edgeIVBytes:], []byte(ad))
	if err != nil {
		t.Fatalf("the field does not open: %v", err)
	}
	return plain
}

// TestEdgeFieldMatchesTheSharedVector: with the vector's IV, Go writes the
// vector's exact field bytes — so the browser and PHP read what the relay writes.
func TestEdgeFieldMatchesTheSharedVector(t *testing.T) {
	v := loadEdgeVector(t)
	field, err := edgeFieldWith([]byte(v.FieldPlaintext), mustHex(t, v.DEKHex), v.FieldAD, mustHex(t, v.FieldIVHex))
	if err != nil {
		t.Fatalf("edgeFieldWith: %v", err)
	}
	if field != v.FieldBlobB64 {
		t.Fatalf("field differs from the vector:\n got  %s\n want %s", field, v.FieldBlobB64)
	}
}

// TestSealEdgeOpensWithTheRecipientSecret: the random path frames both halves
// and binds the body to its spool id.
func TestSealEdgeOpensWithTheRecipientSecret(t *testing.T) {
	secret, recipientPub := edgeRecipient(t)
	msg := []byte("From: a@example.com\r\nSubject: hi\r\n\r\nbody\r\n")

	body, sealedDEK, err := sealEdge(msg, recipientPub, "mail", "20260928T000000-abc")
	if err != nil {
		t.Fatalf("sealEdge: %v", err)
	}
	if !strings.HasPrefix(body, "v1.edge.") || !strings.HasPrefix(sealedDEK, "v2.edgeseal.mail.") {
		t.Fatalf("frames wrong: %q / %q", body[:12], sealedDEK[:20])
	}
	dek := openEdgeForTest(t, strings.TrimPrefix(sealedDEK, "v2.edgeseal.mail."), secret)
	if got := openFieldForTest(t, strings.TrimPrefix(body, "v1.edge."), dek, "mail:relay:20260928T000000-abc"); !bytes.Equal(got, msg) {
		t.Fatalf("round trip mismatch: %q", got)
	}

	raw, _ := base64.StdEncoding.DecodeString(strings.TrimPrefix(body, "v1.edge."))
	gcm, _ := newGCM(dek)
	if _, err := gcm.Open(nil, raw[:edgeIVBytes], raw[edgeIVBytes:], []byte("mail:relay:another-id")); err == nil {
		t.Fatal("a body must not open under another spool id's AD")
	}
	if _, _, err := sealEdge(msg, recipientPub, "Mail.x", "id"); err == nil {
		t.Fatal("a scope the browser would not read back must be refused")
	}
}

// TestSealAndSpoolClientEntry: a client entry spools the browser format, with
// the DEK, scope and generation in the sidecar.
func TestSealAndSpoolClientEntry(t *testing.T) {
	secret, recipientPub := edgeRecipient(t)
	entry := routingEntry{PublicKey: recipientPub, KeyKind: keyKindClient, KeyScope: "mail", KeyGeneration: 3, Mode: modeStore}
	spoolDir := t.TempDir()
	msg := []byte("From: a@example.com\r\nMessage-ID: <x@example.com>\r\n\r\nbody\r\n")
	if code := sealAndSpool(msg, "box@fortress.test", "a@example.com", entry, &routingMap{Version: 9}, spoolDir); code != exitOK {
		t.Fatalf("sealAndSpool exit %d", code)
	}
	metas, _ := filepath.Glob(filepath.Join(spoolDir, "*.meta"))
	seals, _ := filepath.Glob(filepath.Join(spoolDir, "*.seal"))
	if len(metas) != 1 || len(seals) != 1 {
		t.Fatalf("expected one pair, got %v %v", metas, seals)
	}
	var meta spoolMeta
	data, _ := os.ReadFile(metas[0])
	if err := json.Unmarshal(data, &meta); err != nil {
		t.Fatalf("parse meta: %v", err)
	}
	if meta.KeyKind != keyKindClient || meta.KeyScope != "mail" || meta.KeyGeneration != 3 || meta.MessageID != "<x@example.com>" {
		t.Fatalf("meta fields wrong: %+v", meta)
	}
	body, _ := os.ReadFile(seals[0])
	dek := openEdgeForTest(t, strings.TrimPrefix(meta.SealedDEK, "v2.edgeseal.mail."), secret)
	if got := openFieldForTest(t, strings.TrimPrefix(strings.TrimSpace(string(body)), "v1.edge."), dek, "mail:relay:"+meta.SpoolID); !bytes.Equal(got, msg) {
		t.Fatal("the spooled body does not open to the message")
	}

	// A transport entry's sidecar carries none of the client fields.
	other := t.TempDir()
	tentry := routingEntry{PublicKey: testXWingKey, KeyKind: keyKindTransport, Mode: modeStore}
	if code := sealAndSpool(msg, "box@plain.test", "a@example.com", tentry, &routingMap{Version: 9}, other); code != exitOK {
		t.Fatalf("transport sealAndSpool exit %d", code)
	}
	tm, _ := filepath.Glob(filepath.Join(other, "*.meta"))
	data, _ = os.ReadFile(tm[0])
	if bytes.Contains(data, []byte("sealed_dek")) || bytes.Contains(data, []byte("key_scope")) {
		t.Fatalf("a transport sidecar must not carry client fields: %s", data)
	}
}

// TestResolveCatchAllCarriesTheClientScope: a Fortress domain's catch-all
// seals like an alias.
func TestResolveCatchAllCarriesTheClientScope(t *testing.T) {
	m := &routingMap{Tenants: map[string]tenantConfig{"t": {}}, Domains: map[string]domainEntry{
		"fortress.test": {CatchAllMode: modeStore, PublicKey: "pk", KeyKind: keyKindClient, KeyScope: "mail", KeyGeneration: 2, Tenant: "t"},
	}}
	m.normalize()
	e, ok := m.resolve("anyone@fortress.test")
	if !ok || e.KeyKind != keyKindClient || e.KeyScope != "mail" || e.KeyGeneration != 2 {
		t.Fatalf("catch-all lost the client fields: %+v", e)
	}
}

// TestDirectPreflightNamesTheTransportKeyForAClientEntry (B36): a Direct sender
// seals each part raw (SealedBox::sealBinary), which a browser does not open, so
// a Fortress recipient's preflight answers the tenant's transport key.
func TestDirectPreflightNamesTheTransportKeyForAClientEntry(t *testing.T) {
	dir := t.TempDir()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tc := tenantConfig{
		DirectEnabled: true, DirectDecoySecret: "a-decoy-seed", TransportPublicKey: "transport-pk",
		DirectMaxParts: 8, DirectMaxPartBytes: 1000, DirectMaxTotalBytes: 5000,
		DirectPreflightLimit: 100, DirectPreflightWindow: 120,
		DirectSessionTTLSeconds: 900, DirectKinds: []string{"mail"}, SpoolDir: dir,
	}
	m := &routingMap{
		Version:    1,
		Tenants:    map[string]tenantConfig{"main": tc},
		Domains:    map[string]domainEntry{"served.example": {Tenant: "main"}},
		Recipients: map[string]routingEntry{"box@served.example": {Tenant: "main", PublicKey: "browser-pk", KeyKind: keyKindClient, KeyScope: "mail", KeyGeneration: 4}},
	}
	data, _ := json.Marshal(m)
	mapPath := filepath.Join(dir, "routing.json")
	if err := os.WriteFile(mapPath, data, 0o600); err != nil {
		t.Fatal(err)
	}
	h := newDirectHandler(mapPath, dir, dir)
	h.capabilities.resolver = noKeyResolver{}
	h.capabilities.entries["sender.example"] = &capabilityRecord{
		Keys: map[string]string{"k1": base64.StdEncoding.EncodeToString(pub)}, present: true, expiresAt: time.Now().Add(time.Hour),
	}
	env := directEnvelope{
		ProtocolVersion: 1, Kind: "mail", Sender: "me@sender.example",
		Recipient: "box@served.example", KeyID: "k1", Nonce: "cccccccccccccccccccccccccccccccc",
		Timestamp: time.Now().UTC().Format("2006-01-02 15:04:05"),
	}
	manifest := []directManifestEntry{{Role: "body_text", ContentType: "text/plain", Size: 1}}
	signed, err := preflightSigningBytes(env, manifest)
	if err != nil {
		t.Fatalf("sign bytes: %v", err)
	}
	body, _ := json.Marshal(directPreflight{Envelope: env, Manifest: manifest, Signature: base64.StdEncoding.EncodeToString(ed25519.Sign(priv, signed))})
	rec := httptest.NewRecorder()
	h.ServeHTTP(rec, httptest.NewRequest(http.MethodPost, directEndpointPath+"?step=preflight", bytes.NewReader(body)))
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rec.Code, rec.Body.String())
	}
	var out map[string]any
	_ = json.Unmarshal(rec.Body.Bytes(), &out)
	if out["key"] != "transport-pk" {
		t.Fatalf("a client entry must answer the transport key, got %v", out)
	}
}
