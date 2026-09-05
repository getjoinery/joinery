package main

// The relay's own identity: an Ed25519 key and a self-signed certificate for
// it, generated once at first start and never replaced on this machine — an
// update is a new machine and a new identity.
//
// The plane does not verify this certificate the way a browser would. It pins
// the SPKI fingerprint it learned from the signed birth report and connects by
// IP, so the identity is what WireGuard's public key was: proof that the thing
// answering is the thing that was born, with one fewer key. The same listener
// serves the mail hostname's ACME certificate to Direct callers by SNI; the
// identity certificate is what a caller sees when it names no host, or any host
// other than the mail hostname.

import (
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"math/big"
	"os"
	"path/filepath"
	"time"
)

const (
	identityKeyFile  = "identity.key"
	identityCertFile = "identity.crt"
)

// relayIdentity is the loaded identity: the TLS certificate the listener
// presents, the public key the birth report carries, and the fingerprint the
// plane pins.
type relayIdentity struct {
	cert        tls.Certificate
	public      ed25519.PublicKey
	private     ed25519.PrivateKey
	fingerprint string // base64 of SHA-256 over the SPKI DER — curl's pin, minus the sha256// prefix
}

// loadOrCreateIdentity reads the identity from dir, generating it when absent.
// A half-written identity (one file of two) is an error rather than a regen:
// regenerating would silently change the pin the plane holds, and the remedy
// for a relay whose identity is damaged is an update, not a guess.
func loadOrCreateIdentity(dir string) (*relayIdentity, error) {
	keyPath := filepath.Join(dir, identityKeyFile)
	certPath := filepath.Join(dir, identityCertFile)

	_, keyErr := os.Stat(keyPath)
	_, certErr := os.Stat(certPath)
	switch {
	case keyErr == nil && certErr == nil:
		return loadIdentity(keyPath, certPath)
	case errors.Is(keyErr, os.ErrNotExist) && errors.Is(certErr, os.ErrNotExist):
		return createIdentity(dir, keyPath, certPath)
	default:
		return nil, fmt.Errorf("relay identity in %s is incomplete (key: %v, cert: %v)", dir, keyErr, certErr)
	}
}

func createIdentity(dir, keyPath, certPath string) (*relayIdentity, error) {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, fmt.Errorf("create identity dir: %w", err)
	}
	public, private, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, fmt.Errorf("generate identity key: %w", err)
	}

	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 126))
	if err != nil {
		return nil, err
	}
	now := time.Now()
	template := &x509.Certificate{
		SerialNumber: serial,
		Subject:      pkix.Name{CommonName: "joinery-relay"},
		NotBefore:    now.Add(-time.Hour),
		// Long-lived on purpose. Nothing checks the dates — the plane pins the
		// key — and an expiry would be a scheduled outage with no remedy short
		// of an update.
		NotAfter:              now.AddDate(30, 0, 0),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
	}
	der, err := x509.CreateCertificate(rand.Reader, template, template, public, private)
	if err != nil {
		return nil, fmt.Errorf("self-sign identity certificate: %w", err)
	}
	keyDER, err := x509.MarshalPKCS8PrivateKey(private)
	if err != nil {
		return nil, err
	}

	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: keyDER})
	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	// Key first, then certificate, each atomically: loadOrCreateIdentity treats
	// one-of-two as damage, so a crash between the two writes is loud, never a
	// silent second identity.
	if err := writeFileAtomic(keyPath, keyPEM, 0o600); err != nil {
		return nil, fmt.Errorf("write identity key: %w", err)
	}
	if err := writeFileAtomic(certPath, certPEM, 0o644); err != nil {
		return nil, fmt.Errorf("write identity certificate: %w", err)
	}
	return loadIdentity(keyPath, certPath)
}

func loadIdentity(keyPath, certPath string) (*relayIdentity, error) {
	cert, err := tls.LoadX509KeyPair(certPath, keyPath)
	if err != nil {
		return nil, fmt.Errorf("load relay identity: %w", err)
	}
	private, ok := cert.PrivateKey.(ed25519.PrivateKey)
	if !ok {
		return nil, fmt.Errorf("relay identity key is not Ed25519")
	}
	leaf, err := x509.ParseCertificate(cert.Certificate[0])
	if err != nil {
		return nil, fmt.Errorf("parse relay identity certificate: %w", err)
	}
	public, ok := leaf.PublicKey.(ed25519.PublicKey)
	if !ok {
		return nil, fmt.Errorf("relay identity certificate is not Ed25519")
	}
	cert.Leaf = leaf
	return &relayIdentity{
		cert:        cert,
		public:      public,
		private:     private,
		fingerprint: spkiFingerprint(leaf),
	}, nil
}

// spkiFingerprint is base64(SHA-256(SubjectPublicKeyInfo DER)) — exactly the
// value curl's CURLOPT_PINNEDPUBLICKEY compares after its "sha256//" prefix, so
// the plane pins the reported string with no conversion in between.
func spkiFingerprint(cert *x509.Certificate) string {
	sum := sha256.Sum256(cert.RawSubjectPublicKeyInfo)
	return base64.StdEncoding.EncodeToString(sum[:])
}

// publicKeyB64 is the identity's raw Ed25519 public key, standard base64 — the
// form the birth report carries and the plane verifies the report's own
// signature against.
func (id *relayIdentity) publicKeyB64() string {
	return base64.StdEncoding.EncodeToString(id.public)
}

// sign produces a detached Ed25519 signature, standard base64.
func (id *relayIdentity) sign(message []byte) string {
	return base64.StdEncoding.EncodeToString(ed25519.Sign(id.private, message))
}

// runIdentityInit creates the identity when absent and prints its fingerprint.
// The build runs it as root before the listener's first start, then hands the
// directory to the relay user, so the birth report and the listener read one
// identity rather than racing to create two.
func runIdentityInit() int {
	fs := flag.NewFlagSet("identity-init", flag.ContinueOnError)
	home := fs.String("home", envOr("JOINERY_RELAY_HOME", "/opt/joinery-relay"), "relay home")
	if err := fs.Parse(os.Args[2:]); err != nil {
		return 2
	}
	id, err := loadOrCreateIdentity(relayPaths{home: *home}.identityDir())
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer identity-init: %v\n", err)
		return 1
	}
	fmt.Printf("IDENTITY_FINGERPRINT=%s\nIDENTITY_PUBLIC_KEY=%s\n", id.fingerprint, id.publicKeyB64())
	return 0
}
