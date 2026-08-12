package main

// The two pieces of crypto the Direct endpoint needs, and nothing else.
//
// Verifying an instance signature is stateless — no vault, no key material of
// the tenant's own — which is exactly why the relay can do it at the edge and
// drop forged senders before the origin box is touched at all. The relay never
// SIGNS: outbound Direct is signed on the box and the relay only transports it,
// the same division OutboundTransport already enforces for mail. Putting a
// signing identity on the relay would be a new custody model this design
// deliberately avoids.

import (
	"crypto/ed25519"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"strings"

	"golang.org/x/crypto/curve25519"
)

// decodeSigningKey accepts either base64 alphabet, padded or not — the box
// publishes standard base64 in the TXT record, and a hand-edited zone may have
// lost the padding.
func decodeSigningKey(encoded string) ([]byte, bool) {
	encoded = strings.TrimSpace(encoded)
	for _, enc := range []*base64.Encoding{
		base64.StdEncoding, base64.RawStdEncoding,
		base64.URLEncoding, base64.RawURLEncoding,
	} {
		if raw, err := enc.DecodeString(encoded); err == nil && len(raw) == ed25519.PublicKeySize {
			return raw, true
		}
	}
	return nil, false
}

func validEd25519PublicKey(encoded string) bool {
	_, ok := decodeSigningKey(encoded)
	return ok
}

// verifyInstanceSignature checks a detached Ed25519 signature over message.
// Returns false rather than an error for every failure mode: a malformed key,
// a malformed signature and a wrong signature are one answer on the wire.
func verifyInstanceSignature(message []byte, signatureB64, publicKeyB64 string) bool {
	public, ok := decodeSigningKey(publicKeyB64)
	if !ok {
		return false
	}
	var sig []byte
	for _, enc := range []*base64.Encoding{
		base64.StdEncoding, base64.RawStdEncoding,
		base64.URLEncoding, base64.RawURLEncoding,
	} {
		if raw, err := enc.DecodeString(strings.TrimSpace(signatureB64)); err == nil && len(raw) == ed25519.SignatureSize {
			sig = raw
			break
		}
	}
	if sig == nil {
		return false
	}
	return ed25519.Verify(ed25519.PublicKey(public), message, sig)
}

// decoyGeneration is the key generation a decoy always reports: the value a
// freshly created, never-rotated vault carries. Most real vaults never rotate,
// so it is the overwhelmingly common real answer and a decoy blends into that
// cohort.
const decoyGeneration = 1

// decoyPublicKey is the key handed back for an address that does not exist.
//
// The relay accepts unconditionally at Fortress so that acceptance discloses
// nothing — but a key-bearing accept would reopen exactly what that closed,
// because a real key coming back would tell a prober the address exists. So a
// key comes back either way. Two properties make it hold up, and both are load
// bearing:
//
//   - it must be a VALID curve point, or a malformed-key error at the sender
//     would identify it. The derived value is used as an X25519 scalar and the
//     published decoy is its base point multiple — a genuine public key. The
//     scalar is discarded the instant the point is computed and is stored
//     nowhere, so nothing can ever open a message sealed to a decoy.
//   - it must be DETERMINISTIC, since a key that changed between probes of one
//     address would itself be the tell.
//
// The encoding matches SealedBox::b64url so the sender seals to it exactly as
// it would to a real vault key.
func decoyPublicKey(domainSecret, address string) string {
	mac := hmac.New(sha256.New, []byte(domainSecret))
	mac.Write([]byte(asciiLower(strings.TrimSpace(address))))
	scalar := mac.Sum(nil)

	// Clamp the way X25519 clamps, so the point is on the curve and in the
	// right subgroup — a real key in every observable respect.
	scalar[0] &= 248
	scalar[31] &= 127
	scalar[31] |= 64

	public, err := curve25519.X25519(scalar, curve25519.Basepoint)
	for i := range scalar {
		scalar[i] = 0
	}
	if err != nil {
		return ""
	}
	return base64.RawURLEncoding.EncodeToString(public)
}
