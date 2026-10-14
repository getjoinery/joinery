package main

import (
	"bufio"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/rsa"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
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
	st := s.mailApps.status("main")
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
		{"mail apps off", "mx.relay.example", map[string][]string{"main": nil}, "mail apps are off for this deployment"},
		{"a shared shard", "mx.relay.example", map[string][]string{"a": {"127.0.0.1"}, "b": {"127.0.0.1"}}, "a relay with more than one tenant passes nothing"},
	}
	for _, c := range cases {
		s, addr := passFixture(t, port)
		writeRouting(t, s.routingPath, c.tenants)
		if g, err := dialThrough(addr, c.sni); err == nil {
			t.Errorf("%s: got through (%q)", c.name, g)
		}
		time.Sleep(50 * time.Millisecond)
		st := s.mailApps.status("a")
		if c.tenants["main"] != nil || c.name == "mail apps off" {
			st = s.mailApps.status("main")
		}
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
	if why := s.mailApps.status("main")["last_refusal"]; why != "a relay with more than one tenant passes nothing" {
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
	if why := s.mailApps.status("main")["last_refusal"]; why != "the server did not answer on "+strconv.Itoa(dead) {
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
	if csr, why := s.checkMailAppsCSR(csrPEM(t, ec, []string{"mx.relay.example"}, nil), "mx.relay.example"); csr == nil {
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
		if csr, _ := s.checkMailAppsCSR(text, "mx.relay.example"); csr != nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestCertificateRouteGuards(t *testing.T) {
	// The full fixture has an operator key: it is a shard, so a tenant is
	// certified only under the slot name the operator assigned it.
	f := newRelayFixture(t)
	writeRouting(t, f.server.routingPath, map[string][]string{"main": {"203.0.113.9"}})
	ec, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	csrFor := func(name string) []byte {
		b, _ := json.Marshal(map[string]string{"csr": csrPEM(t, ec, []string{name}, nil)})
		return b
	}
	const route = "/relay/mail-apps/certificate"

	// The operator has no gateway.
	if resp, _ := f.do(f.signedRequest("POST", route, csrFor("t1.fleet.example"), "operator")); resp.StatusCode != 403 {
		t.Fatalf("operator: %d", resp.StatusCode)
	}
	// A tenant the operator has assigned no name: refused, whatever it asks for.
	if resp, out := f.do(f.signedRequest("POST", route, csrFor("t1.fleet.example"), "main")); resp.StatusCode != 403 ||
		!strings.Contains(string(out), "no mail name") {
		t.Fatalf("no name: %d %s", resp.StatusCode, out)
	}
	if why := writeTenantMailName(f.paths, "main", "T1.Fleet.Example."); why != "" {
		t.Fatal(why)
	}
	// The shard's own name is never a tenant's.
	if resp, _ := f.do(f.signedRequest("POST", route, csrFor(f.server.hostname), "main")); resp.StatusCode != 422 {
		t.Fatalf("shard's own name: %d", resp.StatusCode)
	}
	// Its assigned name: the order starts (and, with no ACME account yet, says so).
	resp, out := f.do(f.signedRequest("POST", route, csrFor("t1.fleet.example"), "main"))
	if resp.StatusCode != 502 || !strings.Contains(string(out), "no ACME account yet") {
		t.Fatalf("assigned name: %d %s", resp.StatusCode, out)
	}
	if e := f.server.mailAppsCertStatus("main")["certificate_error"]; e == nil {
		t.Fatal("the failure was not recorded for the ping")
	}
	// Another tenant's slot name, asked for by this one: refused.
	f.addKey("other")
	if why := registerTenant(f.paths, "other", f.publicB64("other"), []string{"other.test"}, nil); why != "" {
		t.Fatal(why)
	}
	if why := writeTenantMailName(f.paths, "other", "t2.fleet.example"); why != "" {
		t.Fatal(why)
	}
	if resp, _ := f.do(f.signedRequest("POST", route, csrFor("t2.fleet.example"), "main")); resp.StatusCode != 422 {
		t.Fatalf("another tenant's name: %d", resp.StatusCode)
	}
	// Inside twelve hours of an issue: refused, with when; the record is the tenant's own.
	f.server.writeMailAppsCertRecord("main", mailAppsCertRecord{IssuedUTC: time.Now().UTC().Format(time.RFC3339)})
	if resp, _ := f.do(f.signedRequest("POST", route, csrFor("t1.fleet.example"), "main")); resp.StatusCode != 429 {
		t.Fatalf("rate: %d", resp.StatusCode)
	}
	if resp, out := f.do(f.signedRequest("POST", route, csrFor("t2.fleet.example"), "other")); resp.StatusCode != 502 {
		t.Fatalf("another tenant inside main's twelve hours: %d %s", resp.StatusCode, out)
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

// The minute's check dials the server on each onward port, so a blocked path
// (a provider's outbound block on 465, a firewall not opened to the relay)
// shows in the ping before any program meets it.
func TestCheckServerReportsEachOnwardPort(t *testing.T) {
	open, _ := fakeGateway(t, "mx.relay.example")
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	dead := ln.Addr().(*net.TCPAddr).Port
	ln.Close()
	s, _ := passFixture(t, open)
	s.mailApps.targets = map[string]int{"993": open, "465": dead}

	s.mailApps.checkServer()
	if _, ok := s.mailApps.status("main")["reaches_server"]; ok {
		t.Fatal("reported a check with mail apps off")
	}

	writeRouting(t, s.routingPath, map[string][]string{"main": {"127.0.0.1"}})
	s.mailApps.checkServer()
	st := s.mailApps.status("main")
	reaches, ok := st["reaches_server"].(map[string]interface{})
	if !ok {
		t.Fatalf("no reaches_server in %v", st)
	}
	imap := reaches["993"].(map[string]interface{})
	sub := reaches["465"].(map[string]interface{})
	if imap["answers"] != true || imap["port"] != open {
		t.Fatalf("993: %v", imap)
	}
	if sub["answers"] != false || sub["port"] != dead {
		t.Fatalf("465: %v", sub)
	}
	if _, ok := st["reaches_server_utc"].(string); !ok {
		t.Fatalf("no check time in %v", st)
	}
	if mailAppsSubmissionTarget == 465 || mailAppsSubmissionTarget == 587 || mailAppsSubmissionTarget == 25 {
		t.Fatalf("submission must be dialed on a port cloud providers leave open, not %d", mailAppsSubmissionTarget)
	}
}

// shardFixture is passFixture with an operator key (a shard) and tenants with
// assigned slot names and origins.
func shardFixture(t *testing.T, targetPort int, names map[string]string, origins map[string][]string) (*relayServer, string) {
	t.Helper()
	s, addr := passFixture(t, targetPort)
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	if err := os.WriteFile(filepath.Join(s.paths.home, "operator_public_key"), []byte(base64.StdEncoding.EncodeToString(pub)+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	writeRouting(t, s.routingPath, origins)
	for slug, name := range names {
		if why := writeTenantMailName(s.paths, slug, name); why != "" {
			t.Fatal(why)
		}
	}
	if !s.mailApps.shard() {
		t.Fatal("the fixture is not a shard")
	}
	return s, addr
}

// On a shard each tenant is reached under its slot name only; an unknown name
// and the shard's own name are closed, and a tenant's status is its own.
func TestShardRoutesBySlotName(t *testing.T) {
	gw1, lines1 := fakeGateway(t, "t1.fleet.example")
	s, addr := shardFixture(t, gw1, map[string]string{"t1": "t1.fleet.example", "t2": "t2.fleet.example"},
		map[string][]string{"t1": {"127.0.0.1"}, "t2": nil})
	if got, err := dialThrough(addr, "t1.fleet.example"); err != nil || !strings.HasPrefix(got, "* OK gateway") {
		t.Fatalf("slot name: %q %v", got, err)
	}
	if line := <-lines1; !strings.HasPrefix(line, "PROXY TCP4 127.0.0.1 ") {
		t.Fatalf("proxy line %q", line)
	}
	for _, name := range []string{"t3.fleet.example", "mx.relay.example", ""} {
		if g, err := dialThrough(addr, name); err == nil {
			t.Errorf("%q got through (%q)", name, g)
		}
	}
	if g, err := dialThrough(addr, "t2.fleet.example"); err == nil {
		t.Errorf("a tenant with no origins got through (%q)", g)
	}
	time.Sleep(50 * time.Millisecond)
	st1, st2 := s.mailApps.status("t1"), s.mailApps.status("t2")
	if st1["spliced_1h"] != 1 || st1["refused_1h"] != 0 || st1["mail_name"] != "t1.fleet.example" {
		t.Fatalf("t1: %v", st1)
	}
	if st2["refused_1h"] != 1 || st2["off_because"] != "mail apps are off for this deployment" {
		t.Fatalf("t2: %v", st2)
	}
	st3 := s.mailApps.status("t3")
	if st3["off_because"] != "the operator has assigned this deployment no mail name on this shard" || st3["refused_1h"] != 0 {
		t.Fatalf("a tenant with no name, and no one else's refusals: %v", st3)
	}
}

// The operator's ping on a shard sees the whole pass-through: every tenant by
// slug, and the refusals that reach no tenant (a hello with no name, a name no
// tenant has), not a tenant-shaped group about a tenant called "operator".
func TestShardOperatorSeesTheWholePassThrough(t *testing.T) {
	gw, _ := fakeGateway(t, "t1.fleet.example")
	s, addr := shardFixture(t, gw, map[string]string{"t1": "t1.fleet.example", "t2": "t2.fleet.example"},
		map[string][]string{"t1": {"127.0.0.1"}, "t2": nil})
	if _, err := dialThrough(addr, "t1.fleet.example"); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"", "t3.fleet.example", "t2.fleet.example"} {
		if g, err := dialThrough(addr, name); err == nil {
			t.Fatalf("%q got through (%q)", name, g)
		}
	}
	time.Sleep(50 * time.Millisecond)
	st := s.mailApps.status(relayOperatorTenant)
	if _, ok := st["off_because"]; ok {
		t.Fatalf("the operator was told it is a tenant with no name: %v", st)
	}
	if st["tenants_named"] != 2 || st["tenants_on"] != 1 || st["spliced_1h"] != 1 || st["refused_1h"] != 3 || st["refused_unknown_1h"] != 2 {
		t.Fatalf("operator: %v", st)
	}
	tenants, _ := st["tenants"].(map[string]interface{})
	t1, _ := tenants["t1"].(map[string]interface{})
	t2, _ := tenants["t2"].(map[string]interface{})
	if t1["mail_name"] != "t1.fleet.example" || t1["spliced_1h"] != 1 || t2["refused_1h"] != 1 || t2["origins"] != 0 {
		t.Fatalf("by tenant: %v", tenants)
	}
	if st["last_refusal"] != "a name this relay does not pass" {
		t.Fatalf("last refusal %v", st["last_refusal"])
	}
	// A tenant still sees only its own.
	if st1 := s.mailApps.status("t1"); st1["refused_1h"] != 0 || st1["spliced_1h"] != 1 {
		t.Fatalf("t1: %v", st1)
	}
}

func TestShardCapsOneTenant(t *testing.T) {
	gw, _ := fakeGateway(t, "t1.fleet.example")
	s, addr := shardFixture(t, gw, map[string]string{"t1": "t1.fleet.example"}, map[string][]string{"t1": {"127.0.0.1"}})
	old := mailAppsPerTenant
	mailAppsPerTenant = 0
	defer func() { mailAppsPerTenant = old }()
	if g, err := dialThrough(addr, "t1.fleet.example"); err == nil {
		t.Fatalf("over the tenant's cap got through (%q)", g)
	}
	time.Sleep(50 * time.Millisecond)
	if why := s.mailApps.status("t1")["last_refusal"]; why != "this deployment's connection cap reached" {
		t.Fatalf("refusal %v", why)
	}
}

// The operator's tenant-add carries the slot name into the tenant's registry
// entry; a malformed one is refused; an empty one keeps what was assigned.
func TestTenantMailNameIsTheOperators(t *testing.T) {
	p := relayPaths{home: t.TempDir()}
	if err := os.MkdirAll(p.tenantDir("t1"), 0o755); err != nil {
		t.Fatal(err)
	}
	if why := writeTenantMailName(p, "t1", "T1.Fleet.Example."); why != "" || tenantMailName(p, "t1") != "t1.fleet.example" {
		t.Fatalf("assigned: %q %q", why, tenantMailName(p, "t1"))
	}
	if why := writeTenantMailName(p, "t1", "not a name"); why == "" {
		t.Fatal("a malformed name was accepted")
	}
	if why := writeTenantMailName(p, "t1", ""); why != "" || tenantMailName(p, "t1") != "t1.fleet.example" {
		t.Fatal("an empty name took the assignment away")
	}
	if tenantMailName(p, "../t1") != "" {
		t.Fatal("a slug that is a path was read")
	}
}

// Review B1: a hello with no name never reaches a tenant the operator has not
// named (tenantMailName "" must not match an empty server name).
func TestShardNoNameReachesNoUnnamedTenant(t *testing.T) {
	gw, _ := fakeGateway(t, "t1.fleet.example")
	s, addr := shardFixture(t, gw, map[string]string{"t1": "t1.fleet.example"},
		map[string][]string{"t1": {"127.0.0.1"}, "t2": {"127.0.0.1"}})
	if g, err := dialThrough(addr, ""); err == nil {
		t.Fatalf("a hello with no name got through (%q)", g)
	}
	time.Sleep(50 * time.Millisecond)
	if st := s.mailApps.status("t2"); st["refused_1h"] != 0 || st["spliced_1h"] != 0 {
		t.Fatalf("the unnamed tenant was reached: %v", st)
	}
	if slug, _ := s.mailApps.route(""); slug != "" {
		t.Fatalf("route(\"\") = %q", slug)
	}
}

// Review B2: a self-hosted relay's one tenant is not held to a shard's per-tenant cap.
func TestSelfHostedHasNoPerTenantCap(t *testing.T) {
	gw, _ := fakeGateway(t, "mx.relay.example")
	s, addr := passFixture(t, gw)
	writeRouting(t, s.routingPath, map[string][]string{"main": {"127.0.0.1"}})
	old := mailAppsPerTenant
	mailAppsPerTenant = 0
	defer func() { mailAppsPerTenant = old }()
	if got, err := dialThrough(addr, "mx.relay.example"); err != nil || !strings.HasPrefix(got, "* OK gateway") {
		t.Fatalf("a self-hosted relay applied the per-tenant cap: %q %v", got, err)
	}
}

// Review B4: the shard's own name is no tenant's, and one name has one tenant.
func TestShardNameRules(t *testing.T) {
	gw, _ := fakeGateway(t, "t1.fleet.example")
	s, _ := shardFixture(t, gw, map[string]string{"t1": "t1.fleet.example"}, map[string][]string{"t1": nil, "t2": nil})
	if why := writeTenantMailName(s.paths, "t2", "t1.fleet.example"); why == "" {
		t.Fatal("a name another tenant holds was given to a second tenant")
	}
	if why := writeTenantMailName(s.paths, "t2", s.hostname); why != "" {
		t.Fatal(why)
	}
	if name, why := s.mailApps.mailName("t2"); name != "" || why == "" {
		t.Fatalf("the shard's own name was a tenant's mail name: %q", name)
	}
}

// Review B5: on a shard the total grows with its tenants, so one tenant's
// programs cannot fill the pool the others share.
func TestShardTotalScalesWithTenants(t *testing.T) {
	gw, _ := fakeGateway(t, "t1.fleet.example")
	names := map[string]string{}
	routes := map[string][]string{}
	for i := 0; i < 5; i++ {
		slug := "t" + strconv.Itoa(i)
		names[slug] = slug + ".fleet.example"
		routes[slug] = nil
	}
	s, _ := shardFixture(t, gw, names, routes)
	s.mailApps.checkServer()
	s.mailApps.mu.Lock()
	got := s.mailApps.totalCap
	s.mailApps.mu.Unlock()
	if want := 5 * mailAppsPerTenant; got != want {
		t.Fatalf("total %d, want %d", got, want)
	}
}

// Review R1/R2: a self-hosted relay reports every open connection as its one
// tenant's; a tenant-add whose name is refused registers nothing.
func TestActiveCountAndRefusedAddChangesNothing(t *testing.T) {
	gw, _ := fakeGateway(t, "mx.relay.example")
	s, _ := passFixture(t, gw)
	writeRouting(t, s.routingPath, map[string][]string{"main": {"127.0.0.1"}})
	s.mailApps.mu.Lock()
	s.mailApps.active = 3
	s.mailApps.mu.Unlock()
	if a := s.mailApps.status("main")["active"]; a != 3 {
		t.Fatalf("self-hosted active %v, want 3", a)
	}
	p := relayPaths{home: t.TempDir()}
	if err := os.MkdirAll(p.tenantDir("t1"), 0o755); err != nil {
		t.Fatal(err)
	}
	if why := writeTenantMailName(p, "t1", "t1.fleet.example"); why != "" {
		t.Fatal(why)
	}
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	v := applyRequest(p, relayRequest{Type: requestTypeTenantAdd, Tenant: "t2", By: relayOperatorTenant,
		PublicKey: base64.StdEncoding.EncodeToString(pub), MxHostname: "t1.fleet.example"})
	if v.Status != "rejected" || tenantExists(p, "t2") {
		t.Fatalf("a refused add registered the tenant: %+v exists=%v", v, tenantExists(p, "t2"))
	}
}
