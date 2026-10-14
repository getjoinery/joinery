package main

// POST /relay/mail-apps/certificate — the certificate the deployment's mail
// gateway serves for this relay's mail hostname (specs/mail_apps_plugin.md §
// Where programs connect). Mail programs connect to the relay's name, the relay
// passes their TLS through untouched, and the gateway on the server ends it, so
// the server needs a certificate for the relay's name. The server makes the key
// and a CSR; the key never leaves it. The relay, which the name points at,
// completes the ACME order with its own Let's Encrypt account (TLS-ALPN-01 on
// its 443) and answers the chain.
//
// Refused unless: the CSR names exactly the caller's mail name and nothing
// else (mailAppsPass.mailName: on a shard, the slot name the operator assigned
// to the tenant whose signature is on the request, never anything the tenant
// sent; on a self-hosted relay, the relay's own name, for its one tenant), its
// signature verifies, and no certificate was issued for that tenant in the last
// mailAppsCertInterval (a self-hosted relay's own certificate shares Let's
// Encrypt's duplicate-certificate limit for the name: five a week). Each
// tenant's record is its own.

import (
	"context"
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"golang.org/x/crypto/acme"
)

const (
	mailAppsCertInterval = 12 * time.Hour
	mailAppsCertTimeout  = 3 * time.Minute
	// One record per tenant: mail_apps_certificate-<slug>.json.
	mailAppsCertStatePrefix = "mail_apps_certificate-"
	// autocert's name for its account key in the cache (x/crypto acme/autocert).
	acmeAccountKeyName = "acme_account+key"
)

type mailAppsCertRecord struct {
	IssuedUTC string `json:"issued_utc,omitempty"`
	NotAfter  string `json:"not_after,omitempty"`
	ErrorUTC  string `json:"error_utc,omitempty"`
	Error     string `json:"error,omitempty"`
}

func (s *relayServer) mailAppsCertificate(w http.ResponseWriter, tenant string, body []byte) {
	if tenant == relayOperatorTenant {
		refuse(w, http.StatusForbidden, "the operator has no mail gateway")
		return
	}
	name, why := s.mailApps.mailName(tenant)
	if name == "" {
		refuse(w, http.StatusForbidden, why)
		return
	}
	var req struct {
		CSR string `json:"csr"`
	}
	if json.Unmarshal(body, &req) != nil {
		refuse(w, http.StatusBadRequest, "the body is not {\"csr\": PEM}")
		return
	}
	csr, why := s.checkMailAppsCSR(req.CSR, name)
	if csr == nil {
		refuse(w, http.StatusUnprocessableEntity, why)
		return
	}

	// One order at a time, and none inside the interval.
	if !s.mailAppsCertMu.TryLock() {
		refuse(w, http.StatusConflict, "another certificate is being issued on this relay; try again in a few minutes")
		return
	}
	defer s.mailAppsCertMu.Unlock()
	rec := s.readMailAppsCertRecord(tenant)
	if t, err := time.Parse(time.RFC3339, rec.IssuedUTC); err == nil && time.Since(t) < mailAppsCertInterval {
		w.Header().Set("Retry-After", time.Until(t.Add(mailAppsCertInterval)).Round(time.Second).String())
		refuse(w, http.StatusTooManyRequests, "a certificate was issued at "+rec.IssuedUTC+"; the next may be issued 12 hours after")
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), mailAppsCertTimeout)
	defer cancel()
	chain, notAfter, err := s.issueMailAppsCert(ctx, csr, name)
	if err != nil {
		rec.Error, rec.ErrorUTC = err.Error(), time.Now().UTC().Format(time.RFC3339)
		s.writeMailAppsCertRecord(tenant, rec)
		refuse(w, http.StatusBadGateway, "the certificate was not issued: "+err.Error())
		return
	}
	rec = mailAppsCertRecord{IssuedUTC: time.Now().UTC().Format(time.RFC3339), NotAfter: notAfter.UTC().Format(time.RFC3339)}
	s.writeMailAppsCertRecord(tenant, rec)
	answerJSON(w, http.StatusOK, map[string]any{"chain": chain, "not_after": rec.NotAfter})
}

// checkMailAppsCSR returns the parsed CSR for name, or nil and why it is refused.
func (s *relayServer) checkMailAppsCSR(text, name string) (*x509.CertificateRequest, string) {
	block, _ := pem.Decode([]byte(text))
	if block == nil || block.Type != "CERTIFICATE REQUEST" {
		return nil, "csr is not a PEM CERTIFICATE REQUEST"
	}
	csr, err := x509.ParseCertificateRequest(block.Bytes)
	if err != nil {
		return nil, "csr does not parse"
	}
	if csr.CheckSignature() != nil {
		return nil, "csr signature does not verify"
	}
	if len(csr.DNSNames) != 1 || strings.ToLower(csr.DNSNames[0]) != name {
		return nil, "csr must name exactly this deployment's mail name, " + name
	}
	if cn := strings.ToLower(csr.Subject.CommonName); cn != "" && cn != name {
		return nil, "csr common name is not this deployment's mail name"
	}
	if len(csr.IPAddresses)+len(csr.EmailAddresses)+len(csr.URIs) > 0 {
		return nil, "csr names something other than the hostname"
	}
	switch k := csr.PublicKey.(type) {
	case *ecdsa.PublicKey:
		if k.Curve != elliptic.P256() && k.Curve != elliptic.P384() {
			return nil, "csr key is not P-256 or P-384"
		}
	case *rsa.PublicKey:
		if k.N.BitLen() < 2048 {
			return nil, "csr RSA key is shorter than 2048 bits"
		}
	default:
		return nil, "csr key is neither ECDSA nor RSA"
	}
	return csr, ""
}

// issueMailAppsCert runs one ACME order for the CSR's name with the relay's own
// account, answering the TLS-ALPN-01 challenge on 443 (tlsConfig consults
// alpnCerts before autocert). Returns the chain as PEM and its leaf's expiry.
func (s *relayServer) issueMailAppsCert(ctx context.Context, csr *x509.CertificateRequest, name string) (string, time.Time, error) {
	key, err := s.acmeAccountKey()
	if err != nil {
		return "", time.Time{}, err
	}
	client := &acme.Client{Key: key, DirectoryURL: s.acmeDirectory}
	if _, err := client.Register(ctx, &acme.Account{}, acme.AcceptTOS); err != nil && !errors.Is(err, acme.ErrAccountAlreadyExists) {
		return "", time.Time{}, errors.New("the relay's ACME account: " + err.Error())
	}
	order, err := client.AuthorizeOrder(ctx, acme.DomainIDs(name))
	if err != nil {
		return "", time.Time{}, errors.New("order: " + err.Error())
	}
	for _, u := range order.AuthzURLs {
		authz, err := client.GetAuthorization(ctx, u)
		if err != nil {
			return "", time.Time{}, errors.New("authorization: " + err.Error())
		}
		if authz.Status == acme.StatusValid {
			continue
		}
		var chal *acme.Challenge
		for _, c := range authz.Challenges {
			if c.Type == "tls-alpn-01" {
				chal = c
			}
		}
		if chal == nil {
			return "", time.Time{}, errors.New("the CA offered no tls-alpn-01 challenge")
		}
		cert, err := client.TLSALPN01ChallengeCert(chal.Token, name)
		if err != nil {
			return "", time.Time{}, err
		}
		s.alpnCerts.Store(name, &cert)
		_, err = client.Accept(ctx, chal)
		if err == nil {
			_, err = client.WaitAuthorization(ctx, authz.URI)
		}
		s.alpnCerts.Delete(name)
		if err != nil {
			return "", time.Time{}, errors.New("challenge: " + err.Error())
		}
	}
	order, err = client.WaitOrder(ctx, order.URI)
	if err != nil {
		return "", time.Time{}, errors.New("order: " + err.Error())
	}
	der, _, err := client.CreateOrderCert(ctx, order.FinalizeURL, csr.Raw, true)
	if err != nil {
		return "", time.Time{}, errors.New("finalize: " + err.Error())
	}
	var out strings.Builder
	for _, b := range der {
		_ = pem.Encode(&out, &pem.Block{Type: "CERTIFICATE", Bytes: b})
	}
	leaf, err := x509.ParseCertificate(der[0])
	if err != nil {
		return "", time.Time{}, errors.New("the CA answered a certificate that does not parse")
	}
	return out.String(), leaf.NotAfter, nil
}

// acmeAccountKey is the account key autocert keeps in the cache for the
// relay's own certificate, so both orders come from one account.
func (s *relayServer) acmeAccountKey() (crypto.Signer, error) {
	data, err := os.ReadFile(filepath.Join(s.certCache, acmeAccountKeyName))
	if err != nil {
		return nil, errors.New("the relay has no ACME account yet: it registers with its own certificate on 443")
	}
	block, _ := pem.Decode(data)
	if block == nil {
		return nil, errors.New("the relay's ACME account key does not parse")
	}
	if k, err := x509.ParseECPrivateKey(block.Bytes); err == nil {
		return k, nil
	}
	if k, err := x509.ParsePKCS8PrivateKey(block.Bytes); err == nil {
		if signer, ok := k.(crypto.Signer); ok {
			return signer, nil
		}
	}
	if k, err := x509.ParsePKCS1PrivateKey(block.Bytes); err == nil {
		return k, nil
	}
	return nil, errors.New("the relay's ACME account key does not parse")
}

// alpnChallengeCert answers an acme-tls/1 hello for a pending order of ours.
func (s *relayServer) alpnChallengeCert(hello *tls.ClientHelloInfo, name string) *tls.Certificate {
	for _, p := range hello.SupportedProtos {
		if p == acme.ALPNProto {
			if c, ok := s.alpnCerts.Load(name); ok {
				return c.(*tls.Certificate)
			}
		}
	}
	return nil
}

func (s *relayServer) mailAppsCertPath(tenant string) string {
	return filepath.Join(s.stateDir, mailAppsCertStatePrefix+tenant+".json")
}

func (s *relayServer) readMailAppsCertRecord(tenant string) mailAppsCertRecord {
	var rec mailAppsCertRecord
	if !slugRe.MatchString(tenant) {
		return rec
	}
	if data, err := os.ReadFile(s.mailAppsCertPath(tenant)); err == nil {
		_ = json.Unmarshal(data, &rec)
	}
	return rec
}

func (s *relayServer) writeMailAppsCertRecord(tenant string, rec mailAppsCertRecord) {
	if !slugRe.MatchString(tenant) {
		return
	}
	data, _ := json.Marshal(rec)
	path := s.mailAppsCertPath(tenant)
	_ = os.MkdirAll(s.stateDir, 0o700)
	tmp := path + ".tmp"
	if os.WriteFile(tmp, data, 0o600) == nil {
		_ = os.Rename(tmp, path)
	}
}

// mailAppsCertStatus is the tenant's certificate in the ping's mail_apps group.
func (s *relayServer) mailAppsCertStatus(tenant string) map[string]interface{} {
	rec := s.readMailAppsCertRecord(tenant)
	out := map[string]interface{}{}
	if rec.IssuedUTC != "" {
		out["certificate_issued_utc"] = rec.IssuedUTC
		out["certificate_not_after"] = rec.NotAfter
	}
	if rec.Error != "" {
		out["certificate_error"] = rec.Error
		out["certificate_error_utc"] = rec.ErrorUTC
	}
	return out
}
