package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// A site with a mail host name is reached by that name (the TLS SNI programs
// send), while its upstream keeps the web name; the web name alone no longer
// selects it. A site without one is reached by its web name.
func TestMailHostnameSelectsSite(t *testing.T) {
	dir := t.TempDir()
	sites := filepath.Join(dir, "sites")
	if err := os.MkdirAll(sites, 0o755); err != nil {
		t.Fatal(err)
	}
	cert, key := writeTestCert(t, dir)
	write := func(name string, cfg map[string]any) {
		b, _ := json.Marshal(cfg)
		os.WriteFile(filepath.Join(sites, name+".json"), b, 0o644)
		os.WriteFile(filepath.Join(sites, name+".enabled"), nil, 0o644)
	}
	write("withmail", map[string]any{"site": "withmail", "hostname": "web.example.test",
		"mail_hostname": "Mail.Example.Test", "upstream": "http://127.0.0.1:9", "cert_file": cert, "key_file": key})
	write("plain", map[string]any{"site": "plain", "hostname": "other.example.test",
		"upstream": "http://127.0.0.1:9", "cert_file": cert, "key_file": key})

	r := NewRegistry(dir)
	if err := r.Reload(); err != nil {
		t.Fatal(err)
	}
	if s := r.Lookup("mail.example.test"); s == nil || s.Site != "withmail" {
		t.Fatalf("mail host name did not select the site: %+v", s)
	}
	if s := r.Lookup("web.example.test"); s != nil {
		t.Fatalf("the web name selected a site that serves programs by its mail host name: %s", s.Site)
	}
	if s := r.Lookup("other.example.test"); s == nil || s.Site != "plain" {
		t.Fatalf("a site with no mail host name is not reached by its web name: %+v", s)
	}
	if s := r.Lookup("mail.example.test"); s.Hostname != "web.example.test" {
		t.Fatalf("upstream name changed: %s", s.Hostname)
	}
}
