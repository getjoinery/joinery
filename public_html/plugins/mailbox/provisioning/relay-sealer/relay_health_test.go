package main

import (
	"bufio"
	"encoding/json"
	"net"
	"net/http"
	"strings"
	"sync/atomic"
	"testing"
)

// fakePostfix listens on the loopback and greets every connection with
// greeting, counting the connections. It returns the address to dial.
func fakePostfix(t *testing.T, greeting string, count *int32) string {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { ln.Close() })
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			atomic.AddInt32(count, 1)
			go func(c net.Conn) {
				defer c.Close()
				if greeting != "" {
					_, _ = c.Write([]byte(greeting))
				}
				_, _ = bufio.NewReader(c).ReadString('\n')
			}(conn)
		}
	}()
	return ln.Addr().String()
}

func healthOf(t *testing.T, f *relayFixture) (int, map[string]any) {
	t.Helper()
	resp, body := f.do(mustReq(http.MethodGet, f.ts.URL+healthPath, ""))
	var out map[string]any
	if err := json.Unmarshal(body, &out); err != nil {
		t.Fatalf("health body is not JSON: %s", body)
	}
	return resp.StatusCode, out
}

func TestHealthIsOkWhenPostfixGreets(t *testing.T) {
	for name, greeting := range map[string]string{
		"one line":   "220 mx.example.test ESMTP\r\n",
		"multi-line": "220-mx.example.test ESMTP\r\n220 ready\r\n",
	} {
		t.Run(name, func(t *testing.T) {
			f := newRelayFixture(t)
			var n int32
			f.server.smtpProbeAddr = fakePostfix(t, greeting, &n)
			status, out := healthOf(t, f)
			if status != http.StatusOK || out["status"] != "ok" || out["smtp"] != "answering" {
				t.Fatalf("want 200 ok, got %d %v", status, out)
			}
		})
	}
}

// The health address needs no signature: the management node asks it as a
// stranger would, and it says nothing a stranger cannot learn on port 25.
func TestHealthNeedsNoSignatureAndOnlyAnswersGet(t *testing.T) {
	f := newRelayFixture(t)
	var n int32
	f.server.smtpProbeAddr = fakePostfix(t, "220 mx ESMTP\r\n", &n)
	if status, _ := healthOf(t, f); status != http.StatusOK {
		t.Fatalf("unsigned GET: %d", status)
	}
	resp, _ := f.do(mustReq(http.MethodPost, f.ts.URL+healthPath, "{}"))
	if resp.StatusCode != http.StatusNotFound {
		t.Fatalf("POST /health: %d, want 404", resp.StatusCode)
	}
}

func TestHealthIsDownWhenPostfixIsNot(t *testing.T) {
	// A port with nothing on it.
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	closed := ln.Addr().String()
	ln.Close()

	var n int32
	cases := map[string]struct {
		addr string
		want string
	}{
		"nothing listening": {closed, "does not accept connections"},
		"refusing greeting": {fakePostfix(t, "554 5.3.2 go away\r\n", &n), "instead of 220"},
		"shutting down":     {fakePostfix(t, "421 4.3.2 Service shutting down\r\n", &n), "instead of 220"},
		"silent":            {fakePostfix(t, "", &n), "did not greet"},
	}
	for name, c := range cases {
		t.Run(name, func(t *testing.T) {
			f := newRelayFixture(t)
			f.server.smtpProbeAddr = c.addr
			status, out := healthOf(t, f)
			if status != http.StatusServiceUnavailable || out["status"] != "degraded" {
				t.Fatalf("want 503 degraded, got %d %v", status, out)
			}
			if s, _ := out["smtp"].(string); !strings.Contains(s, c.want) {
				t.Fatalf("smtp reason %q does not say %q", s, c.want)
			}
		})
	}
}

// Asking often costs Postfix one connection per cache window, not one per ask.
func TestHealthCachesTheSelfTest(t *testing.T) {
	f := newRelayFixture(t)
	var n int32
	f.server.smtpProbeAddr = fakePostfix(t, "220 mx ESMTP\r\n", &n)
	for i := 0; i < 5; i++ {
		healthOf(t, f)
	}
	if got := atomic.LoadInt32(&n); got != 1 {
		t.Fatalf("five asks dialled Postfix %d times, want 1", got)
	}
}

// The plane folds these names (NodeHealthProbe::MACHINE_KEYS). A drifted name
// is a figure that silently stops arriving, so they are pinned here.
func TestHealthCarriesTheMachineKeysThePlaneFolds(t *testing.T) {
	f := newRelayFixture(t)
	var n int32
	f.server.smtpProbeAddr = fakePostfix(t, "220 mx ESMTP\r\n", &n)
	_, out := healthOf(t, f)
	for _, k := range []string{
		"disk_usage_percent", "disk_total", "disk_used", "disk_available",
		"memory_total_mb", "memory_free_mb", "memory_used_mb",
		"swap_total_mb", "swap_used_mb",
	} {
		if _, ok := out[k]; !ok {
			t.Errorf("health carries no %s", k)
		}
	}
	if s, _ := out["disk_total"].(string); s == "" || !strings.ContainsAny(s[len(s)-1:], "BKMGTP") {
		t.Errorf("disk_total %v is not in df -h form", out["disk_total"])
	}
}

func TestLoopbackConnectsAreNotCountedAsMail(t *testing.T) {
	for line, want := range map[string]bool{
		"2026-10-05T04:00:00+0000 relay postfix/smtpd[1]: connect from localhost[127.0.0.1]":          true,
		"2026-10-05T04:00:00+0000 relay postfix/smtpd[1]: connect from localhost[::1]":                true,
		"2026-10-05T04:00:00+0000 relay postfix/smtpd[1]: connect from mail-x.google.com[209.85.1.2]": false,
		"2026-10-05T04:00:00+0000 relay postfix/smtpd[1]: connect from unknown[2001:db8::1]":          false,
	} {
		if got := isLoopbackConnect(line); got != want {
			t.Errorf("isLoopbackConnect(%q) = %v, want %v", line, got, want)
		}
	}
}
