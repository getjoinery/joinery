package main

import (
	"bufio"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

// selfSigned is a certificate for name, for the fake server behind the relay.
func selfSigned(t *testing.T, name string) tls.Certificate {
	t.Helper()
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: name}, DNSNames: []string{name},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key}
}

// fakeGateway is the deployment's mail gateway: it reads the PROXY line, ends
// TLS with a certificate for the relay's name, and greets.
func fakeGateway(t *testing.T, name string) (port int, proxyLines chan string) {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	cert := selfSigned(t, name)
	proxyLines = make(chan string, 10)
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func(c net.Conn) {
				defer c.Close()
				br := bufio.NewReader(c)
				line, _ := br.ReadString('\n')
				proxyLines <- line
				tc := tls.Server(bufConn{Conn: c, r: br}, &tls.Config{Certificates: []tls.Certificate{cert}})
				if tc.Handshake() != nil {
					return
				}
				_, _ = tc.Write([]byte("* OK gateway\r\n"))
			}(c)
		}
	}()
	return ln.Addr().(*net.TCPAddr).Port, proxyLines
}

type bufConn struct {
	net.Conn
	r *bufio.Reader
}

func (c bufConn) Read(p []byte) (int, error) { return c.r.Read(p) }

// writeRouting registers these tenants (a directory each under the routing
// map's home, as tenant-add makes; existing ones are kept) and writes a routing
// map with their origins.
func writeRouting(t *testing.T, path string, tenants map[string][]string) {
	t.Helper()
	tenantsDir := filepath.Join(filepath.Dir(path), "tenants")
	m := routingMap{Format: 2, Tenants: map[string]tenantConfig{}}
	for slug, origins := range tenants {
		if err := os.MkdirAll(filepath.Join(tenantsDir, slug), 0o755); err != nil {
			t.Fatal(err)
		}
		m.Tenants[slug] = tenantConfig{MailAppsOrigins: origins}
	}
	data, _ := json.Marshal(m)
	if err := os.WriteFile(path, data, 0o644); err != nil {
		t.Fatal(err)
	}
}

// passFixture is a relay server with the pass-through listening on loopback.
func passFixture(t *testing.T, targetPort int) (*relayServer, string) {
	t.Helper()
	home := t.TempDir()
	s := newRelayServer(relayPaths{home: home}, "mx.relay.example", filepath.Join(home, "routing.json"),
		home, filepath.Join(home, "acme"), home, nil)
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	go s.mailApps.serve(ln, targetPort)
	return s, ln.Addr().String()
}

func dialThrough(addr, name string) (string, error) {
	c, err := tls.DialWithDialer(&net.Dialer{Timeout: 3 * time.Second}, "tcp", addr,
		&tls.Config{ServerName: name, InsecureSkipVerify: true})
	if err != nil {
		return "", err
	}
	defer c.Close()
	_ = c.SetReadDeadline(time.Now().Add(3 * time.Second))
	line, err := bufio.NewReader(c).ReadString('\n')
	return line, err
}

func TestPeekClientHelloKeepsEveryByte(t *testing.T) {
	a, b := net.Pipe()
	go func() {
		_ = tls.Client(a, &tls.Config{ServerName: "mx.relay.example", InsecureSkipVerify: true}).Handshake()
	}()
	_ = b.SetReadDeadline(time.Now().Add(3 * time.Second))
	name, raw, err := peekClientHello(b)
	if err != nil || name != "mx.relay.example" {
		t.Fatalf("peek: name %q err %v", name, err)
	}
	// The bytes read are a whole hello: peeking them again finds the same name.
	x, y := net.Pipe()
	go func() { _, _ = x.Write(raw); x.Close() }()
	again, _, err := peekClientHello(y)
	if err != nil || again != name {
		t.Fatalf("replayed hello: %q %v", again, err)
	}
	a.Close()
	b.Close()
}

func TestPeekRefusesWhatIsNotTLS(t *testing.T) {
	a, b := net.Pipe()
	go func() { _, _ = a.Write([]byte("a1 LOGIN me secret\r\n")); a.Close() }()
	if _, _, err := peekClientHello(b); err == nil {
		t.Fatal("a plain-text command was taken for a hello")
	}
}

func TestPassThroughSplicesToTheServerWithTheProgramsAddress(t *testing.T) {
	port, lines := fakeGateway(t, "mx.relay.example")
	s, addr := passFixture(t, port)
	writeRouting(t, s.routingPath, map[string][]string{"main": {"127.0.0.1"}})

	greeting, err := dialThrough(addr, "mx.relay.example")
	if err != nil || greeting != "* OK gateway\r\n" {
		t.Fatalf("through the relay: %q %v", greeting, err)
	}
	line := <-lines
	if !strings.HasPrefix(line, "PROXY TCP4 127.0.0.1 127.0.0.1 ") || !strings.HasSuffix(line, "\r\n") {
		t.Fatalf("PROXY line %q", line)
	}
	st := s.mailApps.status()
	if st["spliced_1h"] != 1 || st["origins"] != 1 {
		t.Fatalf("status %v", st)
	}
}

func TestPassThroughRefuses(t *testing.T) {
	port, _ := fakeGateway(t, "mx.relay.example")
	cases := []struct {
		name    string
		sni     string
		tenants map[string][]string
		why     string
	}{
		{"another name", "mail.other.example", map[string][]string{"main": {"127.0.0.1"}}, "a name this relay does not pass"},
		{"no name", "", map[string][]string{"main": {"127.0.0.1"}}, "a name this relay does not pass"},
		{"mail apps off", "mx.relay.example", map[string][]string{"main": nil}, "mail apps are off for this relay's deployment"},
		{"a shared shard", "mx.relay.example", map[string][]string{"a": {"127.0.0.1"}, "b": {"127.0.0.1"}}, "a relay with more than one tenant passes nothing"},
	}
	for _, c := range cases {
		s, addr := passFixture(t, port)
		writeRouting(t, s.routingPath, c.tenants)
		if g, err := dialThrough(addr, c.sni); err == nil {
			t.Errorf("%s: got through (%q)", c.name, g)
		}
		time.Sleep(50 * time.Millisecond)
		st := s.mailApps.status()
		if st["refused_1h"] != 1 || st["last_refusal"] != c.why {
			t.Errorf("%s: status %v", c.name, st)
		}
	}
}

func TestASecondTenantThatHasNotPushedStillMakesAShard(t *testing.T) {
	port, _ := fakeGateway(t, "mx.relay.example")
	s, addr := passFixture(t, port)
	writeRouting(t, s.routingPath, map[string][]string{"main": {"127.0.0.1"}})
	// Registered, no fragment yet: it is in tenants/ and not in the map.
	if err := os.MkdirAll(filepath.Join(filepath.Dir(s.routingPath), "tenants", "other"), 0o755); err != nil {
		t.Fatal(err)
	}
	if g, err := dialThrough(addr, "mx.relay.example"); err == nil {
		t.Fatalf("a shard passed a program through (%q)", g)
	}
	time.Sleep(50 * time.Millisecond)
	if why := s.mailApps.status()["last_refusal"]; why != "a relay with more than one tenant passes nothing" {
		t.Fatalf("refusal %v", why)
	}
}

func TestPassThroughSaysWhenTheServerDoesNotAnswer(t *testing.T) {
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	dead := ln.Addr().(*net.TCPAddr).Port
	ln.Close()
	s, addr := passFixture(t, dead)
	writeRouting(t, s.routingPath, map[string][]string{"main": {"127.0.0.1"}})
	if _, err := dialThrough(addr, "mx.relay.example"); err == nil {
		t.Fatal("got through to nothing")
	}
	time.Sleep(50 * time.Millisecond)
	if why := s.mailApps.status()["last_refusal"]; why != "the server did not answer on "+strconv.Itoa(dead) {
		t.Fatalf("refusal %v", why)
	}
}

func TestPassThroughCapsOneSource(t *testing.T) {
	p := newMailAppsPass(&relayServer{})
	for i := 0; i < mailAppsPerSource; i++ {
		if !p.admit("203.0.113.9") {
			t.Fatalf("connection %d refused", i)
		}
	}
	if p.admit("203.0.113.9") {
		t.Fatal("one source went past its cap")
	}
	if !p.admit("198.51.100.1") {
		t.Fatal("another source was refused")
	}
	p.release("203.0.113.9")
	if !p.admit("203.0.113.9") {
		t.Fatal("a released slot was not reusable")
	}
}

func TestSourceKeyCountsAnIPv6NetworkAsOne(t *testing.T) {
	a := sourceKey(&net.TCPAddr{IP: net.ParseIP("2001:db8:1:2::5"), Port: 1})
	b := sourceKey(&net.TCPAddr{IP: net.ParseIP("2001:db8:1:2:ffff::9"), Port: 2})
	if a != b || a != "2001:db8:1:2::/64" {
		t.Fatalf("%q %q", a, b)
	}
	if k := sourceKey(&net.TCPAddr{IP: net.ParseIP("203.0.113.9"), Port: 3}); k != "203.0.113.9" {
		t.Fatalf("%q", k)
	}
}

func TestProxyLine(t *testing.T) {
	v4 := proxyLine(&net.TCPAddr{IP: net.ParseIP("203.0.113.9"), Port: 51000}, &net.TCPAddr{IP: net.ParseIP("198.51.100.1"), Port: 993})
	if v4 != "PROXY TCP4 203.0.113.9 198.51.100.1 51000 993\r\n" {
		t.Fatalf("%q", v4)
	}
	v6 := proxyLine(&net.TCPAddr{IP: net.ParseIP("2001:db8::9"), Port: 51000}, &net.TCPAddr{IP: net.ParseIP("2001:db8::1"), Port: 465})
	if v6 != "PROXY TCP6 2001:db8::9 2001:db8::1 51000 465\r\n" {
		t.Fatalf("%q", v6)
	}
	mixed := proxyLine(&net.TCPAddr{IP: net.ParseIP("2001:db8::9"), Port: 1}, &net.TCPAddr{IP: net.ParseIP("198.51.100.1"), Port: 993})
	if mixed != "PROXY UNKNOWN\r\n" {
		t.Fatalf("%q", mixed)
	}
}

func TestFragmentOriginsMustBePublic(t *testing.T) {
	frag := func(origins ...string) *mapFragment {
		return &mapFragment{FragmentFormat: 1, Tenant: "main", MailAppsOrigins: origins}
	}
	if err := validateFragment(frag("203.0.113.9", "2001:db8::9"), "main", []string{"*"}, map[string]string{}); err != nil {
		t.Fatalf("public addresses refused: %v", err)
	}
	for _, bad := range []string{"127.0.0.1", "10.0.0.5", "192.168.1.2", "169.254.1.1", "::1", "fe80::1", "0.0.0.0", "relay.example", ""} {
		if err := validateFragment(frag(bad), "main", []string{"*"}, map[string]string{}); err == nil {
			t.Errorf("%q accepted as a server address", bad)
		}
	}
	if err := validateFragment(frag("203.0.113.1", "203.0.113.2", "203.0.113.3", "203.0.113.4", "203.0.113.5"),
		"main", []string{"*"}, map[string]string{}); err == nil {
		t.Error("five addresses accepted")
	}
	if got := normalizeOrigins([]string{" 2001:DB8:0::9 "}); len(got) != 1 || got[0] != "2001:db8::9" {
		t.Errorf("normalized %v", got)
	}
}

func csrPEM(t *testing.T, key interface{}, names []string, ips []net.IP) string {
	t.Helper()
	der, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{DNSNames: names, IPAddresses: ips}, key)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: der}))
}

func TestCSRIsOnlyForTheRelaysOwnName(t *testing.T) {
	s := &relayServer{hostname: "mx.relay.example"}
	ec, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if csr, why := s.checkMailAppsCSR(csrPEM(t, ec, []string{"mx.relay.example"}, nil)); csr == nil {
		t.Fatalf("a good CSR was refused: %s", why)
	}
	weak, _ := rsa.GenerateKey(rand.Reader, 1024)
	for name, text := range map[string]string{
		"another name":   csrPEM(t, ec, []string{"mail.victim.example"}, nil),
		"an extra name":  csrPEM(t, ec, []string{"mx.relay.example", "www.relay.example"}, nil),
		"an IP address":  csrPEM(t, ec, []string{"mx.relay.example"}, []net.IP{net.ParseIP("203.0.113.9")}),
		"a weak RSA key": csrPEM(t, weak, []string{"mx.relay.example"}, nil),
		"not a CSR":      "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n",
		"garbage":        "nothing",
	} {
		if csr, _ := s.checkMailAppsCSR(text); csr != nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestCertificateRouteGuards(t *testing.T) {
	f := newRelayFixture(t)
	writeRouting(t, f.server.routingPath, map[string][]string{"main": {"203.0.113.9"}})
	ec, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	body, _ := json.Marshal(map[string]string{"csr": csrPEM(t, ec, []string{"mx.example.test"}, nil)})
	const route = "/relay/mail-apps/certificate"

	// The operator has no gateway.
	if resp, _ := f.do(f.signedRequest("POST", route, body, "operator")); resp.StatusCode != 403 {
		t.Fatalf("operator: %d", resp.StatusCode)
	}
	// A relay with no ACME account of its own yet: the order cannot start, and says so.
	resp, out := f.do(f.signedRequest("POST", route, body, "main"))
	if resp.StatusCode != 502 || !strings.Contains(string(out), "no ACME account yet") {
		t.Fatalf("no account: %d %s", resp.StatusCode, out)
	}
	if e := f.server.mailAppsCertStatus()["certificate_error"]; e == nil {
		t.Fatal("the failure was not recorded for the ping")
	}
	// Inside twelve hours of an issue: refused, with when.
	f.server.writeMailAppsCertRecord(mailAppsCertRecord{IssuedUTC: time.Now().UTC().Format(time.RFC3339)})
	if resp, _ := f.do(f.signedRequest("POST", route, body, "main")); resp.StatusCode != 429 {
		t.Fatalf("rate: %d", resp.StatusCode)
	}
	// A CSR for another name.
	other, _ := json.Marshal(map[string]string{"csr": csrPEM(t, ec, []string{"mail.victim.example"}, nil)})
	if resp, _ := f.do(f.signedRequest("POST", route, other, "main")); resp.StatusCode != 422 {
		t.Fatalf("other name: %d", resp.StatusCode)
	}
	// A shared shard issues nothing.
	writeRouting(t, f.server.routingPath, map[string][]string{"main": nil, "other": nil})
	if resp, _ := f.do(f.signedRequest("POST", route, body, "main")); resp.StatusCode != 403 {
		t.Fatalf("shard: %d", resp.StatusCode)
	}
}

func TestChallengeCertificateAnswersOnlyAnACMEHello(t *testing.T) {
	s := &relayServer{hostname: "mx.relay.example"}
	cert := selfSigned(t, "mx.relay.example")
	s.alpnCerts.Store("mx.relay.example", &cert)
	if c := s.alpnChallengeCert(&tls.ClientHelloInfo{ServerName: "mx.relay.example", SupportedProtos: []string{"acme-tls/1"}}, "mx.relay.example"); c != &cert {
		t.Fatal("the ACME hello did not get the challenge certificate")
	}
	if c := s.alpnChallengeCert(&tls.ClientHelloInfo{ServerName: "mx.relay.example", SupportedProtos: []string{"h2"}}, "mx.relay.example"); c != nil {
		t.Fatal("an ordinary hello got the challenge certificate")
	}
}
