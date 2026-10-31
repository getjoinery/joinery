package main

// X-Wing (draft-connolly-cfrg-xwing-kem-11: X25519 + ML-KEM-768) and the
// platform's v2 seal over it (specs/post_quantum_sealing.md R1, R2), standard
// library only. The same bytes as includes/XWing.php, includes/SealedBox.php
// sealV2 and assets/js/vault-crypto.js, held to
// tests/vault/fixtures/xwing_vector.json by xwing_test.go.
//
//	public key : pk_M[1184] ‖ pk_X[32]                       (1216 bytes)
//	secret     : a 32-byte seed; SHAKE256(seed, 96) = d ‖ z ‖ sk_X
//	ciphertext : ct_M[1088] ‖ ct_X[32]                       (1120 bytes)
//	shared     : SHA3-256(ss_M ‖ ss_X ‖ ct_X ‖ pk_X ‖ "\.//^\")
//	v2 seal    : ct[1120] ‖ IV[12] ‖ AES-256-GCM(plaintext) ‖ tag[16], no AAD,
//	             key = HKDF-SHA256(shared, salt empty, "joinery-seal:v2:" ‖ scope)

import (
	"crypto/ecdh"
	"crypto/hkdf"
	"crypto/mlkem"
	"crypto/rand"
	"crypto/sha256"
	"crypto/sha3"
	"errors"
	"fmt"
	"io"
)

const (
	xwingSeedBytes       = 32
	xwingPublicKeyBytes  = 1216
	xwingCiphertextBytes = 1120
	xwingMLKEMPublic     = 1184
	xwingMLKEMCipher     = 1088
	v2SealInfo           = "joinery-seal:v2:"
	v2ScopeDEK           = "dek"
	v2IVBytes            = 12
	v2Overhead           = xwingCiphertextBytes + v2IVBytes + 16
)

var xwingLabel = []byte{0x5c, 0x2e, 0x2f, 0x2f, 0x5e, 0x5c}

// mlkemEncapsulate is the ML-KEM half of an encapsulation. Production draws
// fresh randomness; a vector test supplies its own (crypto/mlkem/mlkemtest).
type mlkemEncapsulate func(ek *mlkem.EncapsulationKey768) (sharedKey, ciphertext []byte, err error)

func randomMLKEM(ek *mlkem.EncapsulationKey768) ([]byte, []byte, error) {
	ss, ct := ek.Encapsulate()
	return ss, ct, nil
}

// xwingExpand derives the ML-KEM decapsulation key and the X25519 key from a seed.
func xwingExpand(seed []byte) (*mlkem.DecapsulationKey768, *ecdh.PrivateKey, error) {
	if len(seed) != xwingSeedBytes {
		return nil, nil, fmt.Errorf("X-Wing seed must be %d bytes, got %d", xwingSeedBytes, len(seed))
	}
	expanded := sha3.SumSHAKE256(seed, 96)
	defer zero(expanded)
	dk, err := mlkem.NewDecapsulationKey768(expanded[:64])
	if err != nil {
		return nil, nil, err
	}
	skX, err := ecdh.X25519().NewPrivateKey(expanded[64:96])
	if err != nil {
		return nil, nil, err
	}
	return dk, skX, nil
}

// xwingPublicKey is the 1216-byte public key of a seed.
func xwingPublicKey(seed []byte) ([]byte, error) {
	dk, skX, err := xwingExpand(seed)
	if err != nil {
		return nil, err
	}
	return append(dk.EncapsulationKey().Bytes(), skX.PublicKey().Bytes()...), nil
}

func xwingCombine(ssM, ssX, ctX, pkX []byte) []byte {
	h := sha3.New256()
	h.Write(ssM)
	h.Write(ssX)
	h.Write(ctX)
	h.Write(pkX)
	h.Write(xwingLabel)
	return h.Sum(nil)
}

// xwingEncapsulateWith encapsulates to pk with the X25519 ephemeral secret
// ekX and the ML-KEM half from encap. Returns (shared, ciphertext).
func xwingEncapsulateWith(pk, ekX []byte, encap mlkemEncapsulate) ([]byte, []byte, error) {
	if len(pk) != xwingPublicKeyBytes {
		return nil, nil, fmt.Errorf("X-Wing public key must be %d bytes, got %d", xwingPublicKeyBytes, len(pk))
	}
	ekM, err := mlkem.NewEncapsulationKey768(pk[:xwingMLKEMPublic])
	if err != nil {
		return nil, nil, fmt.Errorf("X-Wing public key: %w", err)
	}
	pkX, err := ecdh.X25519().NewPublicKey(pk[xwingMLKEMPublic:])
	if err != nil {
		return nil, nil, err
	}
	eph, err := ecdh.X25519().NewPrivateKey(ekX)
	if err != nil {
		return nil, nil, err
	}
	ctX := eph.PublicKey().Bytes()
	ssX, err := eph.ECDH(pkX)
	if err != nil {
		return nil, nil, fmt.Errorf("X25519 refused the key: %w", err)
	}
	defer zero(ssX)
	ssM, ctM, err := encap(ekM)
	if err != nil {
		return nil, nil, err
	}
	defer zero(ssM)
	return xwingCombine(ssM, ssX, ctX, pk[xwingMLKEMPublic:]), append(ctM, ctX...), nil
}

// xwingDecapsulate recovers the shared secret of ct with the seed.
func xwingDecapsulate(seed, ct []byte) ([]byte, error) {
	if len(ct) != xwingCiphertextBytes {
		return nil, fmt.Errorf("X-Wing ciphertext must be %d bytes, got %d", xwingCiphertextBytes, len(ct))
	}
	dk, skX, err := xwingExpand(seed)
	if err != nil {
		return nil, err
	}
	ssM, err := dk.Decapsulate(ct[:xwingMLKEMCipher])
	if err != nil {
		return nil, err
	}
	defer zero(ssM)
	ctX := ct[xwingMLKEMCipher:]
	peer, err := ecdh.X25519().NewPublicKey(ctX)
	if err != nil {
		return nil, err
	}
	ssX, err := skX.ECDH(peer)
	if err != nil {
		return nil, errors.New("X25519 refused the ciphertext")
	}
	defer zero(ssX)
	return xwingCombine(ssM, ssX, ctX, skX.PublicKey().Bytes()), nil
}

// v2Key is the AES-256 key of a v2 seal under scope.
func v2Key(shared []byte, scope string) ([]byte, error) {
	return hkdf.Key(sha256.New, shared, nil, v2SealInfo+scope, 32)
}

// sealV2 seals plaintext to a 1216-byte X-Wing public key under scope.
func sealV2(plaintext, pk []byte, scope string) ([]byte, error) {
	ekX := make([]byte, 32)
	if _, err := io.ReadFull(rand.Reader, ekX); err != nil {
		return nil, err
	}
	defer zero(ekX)
	iv := make([]byte, v2IVBytes)
	if _, err := io.ReadFull(rand.Reader, iv); err != nil {
		return nil, err
	}
	return sealV2With(plaintext, pk, scope, ekX, iv, randomMLKEM)
}

// sealV2With is sealV2 with its randomness supplied (the vector fixes it).
func sealV2With(plaintext, pk []byte, scope string, ekX, iv []byte, encap mlkemEncapsulate) ([]byte, error) {
	shared, ct, err := xwingEncapsulateWith(pk, ekX, encap)
	if err != nil {
		return nil, err
	}
	defer zero(shared)
	key, err := v2Key(shared, scope)
	if err != nil {
		return nil, err
	}
	defer zero(key)
	gcm, err := newGCM(key)
	if err != nil {
		return nil, err
	}
	out := append(ct, iv...)
	return gcm.Seal(out, iv, plaintext, nil), nil
}

// openV2 opens a v2 seal with the 32-byte seed under scope.
func openV2(raw, seed []byte, scope string) ([]byte, error) {
	if len(raw) < v2Overhead {
		return nil, errors.New("not a v2 sealed blob")
	}
	shared, err := xwingDecapsulate(seed, raw[:xwingCiphertextBytes])
	if err != nil {
		return nil, err
	}
	defer zero(shared)
	key, err := v2Key(shared, scope)
	if err != nil {
		return nil, err
	}
	defer zero(key)
	gcm, err := newGCM(key)
	if err != nil {
		return nil, err
	}
	iv := raw[xwingCiphertextBytes : xwingCiphertextBytes+v2IVBytes]
	return gcm.Open(nil, iv, raw[xwingCiphertextBytes+v2IVBytes:], nil)
}
