package main

import (
	"crypto/rand"
	"encoding/base64"
	"fmt"

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

// b64url decodes a base64url public key exactly as SealedBox::b64url_decode
// produced it: the PHP side rtrim()s the '=' padding, so accept the unpadded
// (Raw) alphabet.
func decodePublicKey(encoded string) (*[32]byte, error) {
	raw, err := base64.RawURLEncoding.DecodeString(encoded)
	if err != nil {
		// Tolerate a value that still carries '=' padding.
		raw, err = base64.URLEncoding.DecodeString(encoded)
		if err != nil {
			return nil, fmt.Errorf("public key is not valid base64url: %w", err)
		}
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
