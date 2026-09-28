package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"io"
	"regexp"

	"golang.org/x/crypto/curve25519"
	"golang.org/x/crypto/hkdf"
	"golang.org/x/crypto/nacl/box"
)

// sealWireVersion is the self-describing prefix SealedBox.php (openDek) expects.
// A sealed blob is exactly: "v1.seal." + rawurlbase64(crypto_box_seal(msg, pk)).
// box.SealAnonymous produces the libsodium-wire-compatible sealed box
// (ephemeral_pubkey || box), so the PHP side opens it with
// sodium_crypto_box_seal_open without any format translation.
const sealWirePrefix = "v1.seal."

// x25519PublicKeyBytes is the fixed length of a Curve25519 public key.
const x25519PublicKeyBytes = 32

// decodePublicKey reads an X25519 public key in either base64 alphabet: a
// server vault's key is base64url with the padding trimmed
// (SealedBox::b64url_encode), a browser-held key is standard base64
// (vault-crypto.js), and the routing map carries whichever the vault stores.
func decodePublicKey(encoded string) (*[32]byte, error) {
	raw, err := decodeAnyBase64(encoded)
	if err != nil {
		return nil, fmt.Errorf("public key is not valid base64: %w", err)
	}
	if len(raw) != x25519PublicKeyBytes {
		return nil, fmt.Errorf("public key must decode to %d bytes, got %d", x25519PublicKeyBytes, len(raw))
	}
	var pk [32]byte
	copy(pk[:], raw)
	return &pk, nil
}

// sealToPublicKey seals the entire raw message to the recipient public key and
// returns the SealedBox wire string ("v1.seal.<rawurlbase64>"). Anonymous seal:
// anyone with the public key can seal, only the secret key opens. There is NO
// DEK and NO AEAD at this layer — the blob is opened exactly once at deferred
// ingest and re-sealed there with the real per-message DEK.
func sealToPublicKey(message []byte, publicKeyB64 string) (string, error) {
	pk, err := decodePublicKey(publicKeyB64)
	if err != nil {
		return "", err
	}
	sealed, err := box.SealAnonymous(nil, message, pk, rand.Reader)
	if err != nil {
		return "", fmt.Errorf("crypto_box_seal (SealAnonymous) failed: %w", err)
	}
	return sealWirePrefix + base64.RawURLEncoding.EncodeToString(sealed), nil
}

// decodeAnyBase64 accepts standard or URL-safe base64, padded or not.
func decodeAnyBase64(encoded string) ([]byte, error) {
	for _, enc := range []*base64.Encoding{base64.RawURLEncoding, base64.URLEncoding, base64.StdEncoding, base64.RawStdEncoding} {
		if raw, err := enc.DecodeString(encoded); err == nil {
			return raw, nil
		}
	}
	return nil, fmt.Errorf("not base64")
}

// --- The browser format (a client-custody key) --------------------------------
//
// A Fortress mailbox's key lives only in its owner's browsers, which open
// WebCrypto's format rather than libsodium's (specs/client_custody_mail.md
// § R9). So a message for one is sealed the way the browser seals a row: a
// fresh 32-byte DEK encrypts the whole message under AES-256-GCM, and the DEK
// is sealed to the recipient's key with X25519 + HKDF-SHA256 + AES-256-GCM —
// byte-for-byte SealedBox::sealEdge / aeadEncryptGcm and vault-crypto.js,
// pinned by tests/vault/fixtures/edge_vector.json.
//
//	body : "v1.edge." + base64(IV[12] ‖ ct ‖ tag[16]), AD "mail:relay:{spool_id}"
//	DEK  : "v1.edgeseal.{scope}." + base64(ephPub[32] ‖ IV[12] ‖ ct ‖ tag[16])
//
// The relay forgets the DEK when the function returns; nothing here can open
// either blob again.

const (
	edgeFieldPrefix = "v1.edge."
	edgeSealPrefix  = "v1.edgeseal."
	edgeKDFLabel    = "sealed-vault:dek"
	edgeIVBytes     = 12
)

// The scope names a vault; the browser reads it back out of the frame, so it
// is held to the same shape VaultCrypto::parseEdgeScope accepts.
var edgeScopePattern = regexp.MustCompile(`^[a-z0-9_]{1,32}$`)

// edgeRelayAD binds a relay-sealed body to the spool entry it arrived as, so a
// body cannot be swapped onto another message's row.
func edgeRelayAD(spoolID string) string {
	return "mail:relay:" + spoolID
}

// sealEdge seals a raw message for a client-custody key: the body under a
// fresh DEK, and the DEK to the recipient. Returns (body, sealedDEK).
func sealEdge(raw []byte, recipientPubB64, scope, spoolID string) (string, string, error) {
	if !edgeScopePattern.MatchString(scope) {
		return "", "", fmt.Errorf("key scope %q is not a vault scope name", scope)
	}
	dek := make([]byte, 32)
	if _, err := io.ReadFull(rand.Reader, dek); err != nil {
		return "", "", fmt.Errorf("DEK: %w", err)
	}
	defer zero(dek)
	fieldIV := make([]byte, edgeIVBytes)
	if _, err := io.ReadFull(rand.Reader, fieldIV); err != nil {
		return "", "", fmt.Errorf("IV: %w", err)
	}
	body, err := edgeFieldWith(raw, dek, edgeRelayAD(spoolID), fieldIV)
	if err != nil {
		return "", "", err
	}
	ephSecret := make([]byte, curve25519.ScalarSize)
	if _, err := io.ReadFull(rand.Reader, ephSecret); err != nil {
		return "", "", fmt.Errorf("ephemeral key: %w", err)
	}
	defer zero(ephSecret)
	sealIV := make([]byte, edgeIVBytes)
	if _, err := io.ReadFull(rand.Reader, sealIV); err != nil {
		return "", "", fmt.Errorf("IV: %w", err)
	}
	sealed, err := edgeSealWith(dek, recipientPubB64, ephSecret, sealIV)
	if err != nil {
		return "", "", err
	}
	return edgeFieldPrefix + body, edgeSealPrefix + scope + "." + sealed, nil
}

// edgeFieldWith is aeadEncryptGcm with its IV supplied (the vector fixes it):
// base64(IV ‖ ct ‖ tag).
func edgeFieldWith(plaintext, key []byte, ad string, iv []byte) (string, error) {
	gcm, err := newGCM(key)
	if err != nil {
		return "", err
	}
	out := append([]byte{}, iv...)
	out = gcm.Seal(out, iv, plaintext, []byte(ad))
	return base64.StdEncoding.EncodeToString(out), nil
}

// edgeSealWith is sealEdge's DEK half with its randomness supplied:
// base64(ephPub ‖ IV ‖ ct ‖ tag), the AES key HKDF-SHA256 over
// X25519(eph, recipient) with info 'sealed-vault:dek' ‖ ephPub ‖ recipientPub
// and an empty salt.
func edgeSealWith(dek []byte, recipientPubB64 string, ephSecret, iv []byte) (string, error) {
	recipient, err := decodePublicKey(recipientPubB64)
	if err != nil {
		return "", err
	}
	ephPub, err := curve25519.X25519(ephSecret, curve25519.Basepoint)
	if err != nil {
		return "", fmt.Errorf("ephemeral public key: %w", err)
	}
	shared, err := curve25519.X25519(ephSecret, recipient[:])
	if err != nil {
		return "", fmt.Errorf("X25519 refused the key: %w", err)
	}
	defer zero(shared)
	info := append(append([]byte(edgeKDFLabel), ephPub...), recipient[:]...)
	aesKey := make([]byte, 32)
	if _, err := io.ReadFull(hkdf.New(sha256.New, shared, nil, info), aesKey); err != nil {
		return "", fmt.Errorf("HKDF: %w", err)
	}
	defer zero(aesKey)
	gcm, err := newGCM(aesKey)
	if err != nil {
		return "", err
	}
	out := append(append([]byte{}, ephPub...), iv...)
	out = gcm.Seal(out, iv, dek, nil)
	return base64.StdEncoding.EncodeToString(out), nil
}

func newGCM(key []byte) (cipher.AEAD, error) {
	if len(key) != 32 {
		return nil, fmt.Errorf("AES-256-GCM key must be 32 bytes, got %d", len(key))
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	return cipher.NewGCM(block)
}

func zero(b []byte) {
	for i := range b {
		b[i] = 0
	}
}
