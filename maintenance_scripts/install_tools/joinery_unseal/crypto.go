package main

// The symmetric halves the daemon needs beside X-Wing (xwing.go):
//
//   - the AEAD wrapping an unlocker's KEK puts around a vault secret,
//     byte-compatible with includes/SealedBox.php aeadEncrypt()/aeadDecrypt():
//     'v1.aead.' + b64url(nonce[24]) + '.' + b64url(XChaCha20-Poly1305(pt, ad))
//   - the secret's own text form inside that wrapping, PoolVaultKey's:
//     'v2.' + b64url(seed[32])
//
// base64url is unpadded on the way out, as SealedBox::b64url() writes it, and
// read with or without padding, as SealedBox::b64url_decode() reads it.

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"strings"

	"golang.org/x/crypto/chacha20poly1305"
)

const (
	aeadPrefix  = "v1.aead."
	secretTag   = "v2."
	kekBytes    = 32
	scopeDEK    = v2ScopeDEK
	scopeChain  = "previous-key"
	keyVersion  = 2
	secretChars = 3 + 43 // 'v2.' + unpadded base64url of 32 bytes
)

func zero(b []byte) {
	for i := range b {
		b[i] = 0
	}
}

func zeroAll(bs [][]byte) {
	for _, b := range bs {
		zero(b)
	}
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

func b64url(b []byte) string {
	return base64.RawURLEncoding.EncodeToString(b)
}

func b64urlDecode(s string) ([]byte, error) {
	return base64.RawURLEncoding.DecodeString(strings.TrimRight(s, "="))
}

// aeadWrap is SealedBox::wrapKey(): the plaintext under kek, bound to ad.
func aeadWrap(plaintext, kek, ad []byte) ([]byte, error) {
	aead, err := chacha20poly1305.NewX(kek)
	if err != nil {
		return nil, err
	}
	nonce := make([]byte, chacha20poly1305.NonceSizeX)
	if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
		return nil, err
	}
	sealed := aead.Seal(nil, nonce, plaintext, ad)
	return []byte(aeadPrefix + b64url(nonce) + "." + b64url(sealed)), nil
}

// aeadUnwrap is SealedBox::unwrapKey(). Every failure reads the same.
func aeadUnwrap(blob, kek, ad []byte) ([]byte, error) {
	bad := errors.New("the wrapping does not open")
	if len(kek) != chacha20poly1305.KeySize {
		return nil, bad
	}
	parts := strings.Split(string(blob), ".")
	if len(parts) != 4 || parts[0] != "v1" || parts[1] != "aead" {
		return nil, bad
	}
	nonce, err := b64urlDecode(parts[2])
	if err != nil || len(nonce) != chacha20poly1305.NonceSizeX {
		return nil, bad
	}
	sealed, err := b64urlDecode(parts[3])
	if err != nil {
		return nil, bad
	}
	aead, err := chacha20poly1305.NewX(kek)
	if err != nil {
		return nil, bad
	}
	plain, err := aead.Open(nil, nonce, sealed, ad)
	if err != nil {
		return nil, bad
	}
	return plain, nil
}

// secretForm is the text a wrapping holds for a seed. The caller zeroes it.
func secretForm(seed []byte) []byte {
	out := make([]byte, 0, secretChars)
	out = append(out, secretTag...)
	enc := make([]byte, base64.RawURLEncoding.EncodedLen(len(seed)))
	base64.RawURLEncoding.Encode(enc, seed)
	out = append(out, enc...)
	zero(enc)
	return out
}

// seedFromSecretForm reads a wrapping's plaintext back into a seed. Anything
// but a tagged 32-byte X-Wing seed is refused, as PoolVaultKey refuses it.
func seedFromSecretForm(form []byte) ([]byte, error) {
	bad := errors.New("not a v2 secret key")
	if len(form) < len(secretTag) || string(form[:len(secretTag)]) != secretTag {
		return nil, bad
	}
	body := form[len(secretTag):]
	for len(body) > 0 && body[len(body)-1] == '=' {
		body = body[:len(body)-1]
	}
	seed := make([]byte, base64.RawURLEncoding.DecodedLen(len(body)))
	n, err := base64.RawURLEncoding.Decode(seed, body)
	if err != nil || n != xwingSeedBytes {
		zero(seed)
		return nil, bad
	}
	return seed[:n], nil
}

// randomSeed mints a fresh X-Wing seed: setup() and rotate()'s key, born here.
func randomSeed() ([]byte, error) {
	seed := make([]byte, xwingSeedBytes)
	if _, err := io.ReadFull(rand.Reader, seed); err != nil {
		return nil, err
	}
	return seed, nil
}
