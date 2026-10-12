package main

import (
	"crypto/tls"
	"encoding/json"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type addrConn struct {
	net.Conn
	remote net.Addr
}

func (c addrConn) RemoteAddr() net.Addr { return c.remote }

func header(t *testing.T, line string) (*proxiedConn, error) {
	t.Helper()
	a, b := net.Pipe()
	go func() { _, _ = io.WriteString(a, line+"after"); a.Close() }()
	return readProxyHeader(b)
}

func TestProxyHeader(t *testing.T) {
	pc, err := header(t, "PROXY TCP4 203.0.113.9 198.51.100.1 51000 993\r\n")
	if err != nil || pc.RemoteAddr().String() != "203.0.113.9:51000" {
		t.Fatalf("TCP4: %v %v", pc, err)
	}
	rest, _ := io.ReadAll(pc)
	if string(rest) != "after" {
		t.Fatalf("the bytes after the line were lost: %q", rest)
	}
	pc, err = header(t, "PROXY TCP6 2001:db8::9 2001:db8::1 51000 465\r\n")
	if err != nil || pc.RemoteAddr().String() != "[2001:db8::9]:51000" {
		t.Fatalf("TCP6: %v %v", pc, err)
	}
	if _, err := header(t, "PROXY UNKNOWN\r\n"); err != nil {
		t.Fatalf("UNKNOWN: %v", err)
	}
	for _, bad := range []string{
		"PROXY TCP4 2001:db8::9 198.51.100.1 51000 993\r\n", // family mismatch
		"PROXY TCP4 203.0.113.9 198.51.100.1 99999 993\r\n", // port
		"PROXY TCP4 203.0.113.9\r\n",
		"\x16\x03\x01 a TLS hello, no line",
		"PROXY TCP4 203.0.113.9 198.51.100.1 51000 993\n", // no CR
		strings.Repeat("P", 200),
	} {
		if _, err := header(t, bad); err == nil {
			t.Errorf("accepted %q", bad)
		}
	}
}

func TestProxyFromIsTheOnlyTrust(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "sites"), 0o755)
	os.WriteFile(filepath.Join(dir, "proxy_from"), []byte("# the relay\n198.51.100.7\n2001:db8::7\nnot-an-ip\n"), 0o644)
	r := NewRegistry(dir)
	if err := r.Reload(); err != nil {
		t.Fatal(err)
	}
	if !r.TrustsProxy("198.51.100.7") || !r.TrustsProxy("::ffff:198.51.100.7") || !r.TrustsProxy("2001:db8::7") {
		t.Fatal("a listed relay is not trusted")
	}
	if r.TrustsProxy("198.51.100.8") || r.TrustsProxy("127.0.0.1") {
		t.Fatal("an unlisted address is trusted")
	}
}

func TestARelayedSiteIsServedOnlyThroughItsRelay(t *testing.T) {
	dir := t.TempDir()
	sites := filepath.Join(dir, "sites")
	os.MkdirAll(sites, 0o755)
	cert, key := writeTestCert(t, dir)
	b, _ := json.Marshal(map[string]any{"site": "hidden", "hostname": "web.example.test", "mail_hostname": testHost,
		"upstream": "http://127.0.0.1:9", "cert_file": cert, "key_file": key, "via_relay": true})
	os.WriteFile(filepath.Join(sites, "hidden.json"), b, 0o644)
	os.WriteFile(filepath.Join(sites, "hidden.enabled"), nil, 0o644)
	r := NewRegistry(dir)
	if err := r.Reload(); err != nil {
		t.Fatal(err)
	}
	get := r.TLSConfig().GetCertificate
	stranger := addrConn{remote: &net.TCPAddr{IP: net.ParseIP("203.0.113.9"), Port: 5}}
	if _, err := get(&tls.ClientHelloInfo{ServerName: testHost, Conn: stranger}); err == nil {
		t.Fatal("a direct connection to the server got the relay's certificate")
	}
	relayed := &limitedConn{Conn: stranger, release: func() {}, relayed: true}
	if c, err := get(&tls.ClientHelloInfo{ServerName: testHost, Conn: relayed}); err != nil || c == nil {
		t.Fatalf("a relayed connection was refused: %v", err)
	}
	local := addrConn{remote: &net.TCPAddr{IP: net.ParseIP("127.0.0.1"), Port: 5}}
	if c, err := get(&tls.ClientHelloInfo{ServerName: testHost, Conn: local}); err != nil || c == nil {
		t.Fatalf("this machine (host report, round trip) was refused: %v", err)
	}
}

// Through a real listener: the relay's PROXY line names the program, the
// limits count the program, and an untrusted sender's line is never read.
func TestListenerReadsTheRelaysLine(t *testing.T) {
	dir := t.TempDir()
	cert, key := writeTestCert(t, dir)
	pair, err := tls.LoadX509KeyPair(cert, key)
	if err != nil {
		t.Fatal(err)
	}
	run := func(trusted bool) (net.Addr, bool, error) {
		raw, _ := net.Listen("tcp", "127.0.0.1:0")
		l := &tlsListener{Listener: raw, cfg: &tls.Config{Certificates: []tls.Certificate{pair}},
			limiter: NewConnLimiter(1, 10), proto: "imap", trusts: func(string) bool { return trusted }}
		defer l.Close()
		go func() {
			c, err := net.Dial("tcp", raw.Addr().String())
			if err != nil {
				return
			}
			io.WriteString(c, "PROXY TCP4 203.0.113.9 198.51.100.1 51000 993\r\n")
			tc := tls.Client(c, clientTLS())
			tc.SetDeadline(time.Now().Add(3 * time.Second))
			tc.Handshake()
			time.Sleep(200 * time.Millisecond)
			tc.Close()
		}()
		c, err := l.Accept()
		if err != nil {
			return nil, false, err
		}
		tc := c.(*tls.Conn)
		tc.SetDeadline(time.Now().Add(3 * time.Second))
		herr := tc.Handshake()
		lc := tc.NetConn().(*limitedConn)
		return lc.RemoteAddr(), lc.relayed, herr
	}
	addr, relayed, err := run(true)
	if err != nil || !relayed || addr.String() != "203.0.113.9:51000" {
		t.Fatalf("trusted relay: %v relayed=%v err=%v", addr, relayed, err)
	}
	if _, relayed, err := run(false); err == nil || relayed {
		t.Fatalf("an untrusted sender's line was believed (relayed=%v err=%v)", relayed, err)
	}
}
