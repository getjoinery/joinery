package main

import (
	"bufio"
	"crypto/tls"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// Two sites on one gateway: each program is greeted with the name it
// connected to, not the machine's name (B7: '220 localhost' on a host with
// two or more sites).
func TestSMTPGreetingNamesTheSiteAskedFor(t *testing.T) {
	dir := t.TempDir()
	certPath, keyPath := writeTestCert(t, dir)
	os.MkdirAll(filepath.Join(dir, "sites"), 0o755)
	for _, s := range []struct{ site, host string }{{"a", "mail.a.example.test"}, {"b", "mail.b.example.test"}} {
		cfg := fmt.Sprintf(`{"site":%q,"hostname":%q,"upstream":"http://127.0.0.1:1","cert_file":%q,"key_file":%q}`, s.site, s.host, certPath, keyPath)
		os.WriteFile(filepath.Join(dir, "sites", s.site+".json"), []byte(cfg), 0o644)
		os.WriteFile(filepath.Join(dir, "sites", s.site+".enabled"), nil, 0o644)
	}
	reg := NewRegistry(dir)
	if err := reg.Reload(); err != nil {
		t.Fatal(err)
	}
	if reg.Count() != 2 {
		t.Fatalf("enabled sites = %d, want 2", reg.Count())
	}
	gw := NewGateway(reg, NewConnLimiter(50, 500), 20)

	serve := func(fixed string) string {
		ln, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		front := gw.NewSMTPFront(fixed)
		go front.Serve(gw.TLSListener(ln, "smtp"))
		t.Cleanup(front.Close)
		return ln.Addr().String()
	}
	greeting := func(addr, sni string) (string, error) {
		d := &net.Dialer{Timeout: 5 * time.Second}
		c, err := tls.DialWithDialer(d, "tcp", addr, &tls.Config{InsecureSkipVerify: true, ServerName: sni})
		if err != nil {
			return "", err
		}
		defer c.Close()
		c.SetDeadline(time.Now().Add(5 * time.Second))
		line, err := bufio.NewReader(c).ReadString('\n')
		return strings.TrimSpace(line), err
	}

	addr := serve("")
	for _, name := range []string{"mail.a.example.test", "mail.b.example.test", "mail.a.example.test"} {
		got, err := greeting(addr, name)
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
		if !strings.HasPrefix(got, "220 "+name+" ") {
			t.Errorf("greeting for %s = %q, want 220 %s ...", name, got, name)
		}
	}
	if got, err := greeting(addr, "MAIL.B.example.test"); err != nil || !strings.HasPrefix(got, "220 mail.b.example.test ") {
		t.Errorf("a name asked in capitals is greeted in lower case: %q, %v", got, err)
	}
	if got, err := greeting(addr, "unknown.example.test"); err == nil {
		t.Errorf("a name no site serves got a greeting: %q", got)
	}

	fixed := serve("gateway.example.test")
	if got, err := greeting(fixed, "mail.b.example.test"); err != nil || !strings.HasPrefix(got, "220 gateway.example.test ") {
		t.Errorf("-smtp-domain names every greeting: %q, %v", got, err)
	}
}
