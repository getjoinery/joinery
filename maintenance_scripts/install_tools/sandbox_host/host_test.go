package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// The fixture module (testdata/fixture), built once for every test.
var fixtureWasm []byte

func TestMain(m *testing.M) {
	out := filepath.Join(os.TempDir(), "sandbox-host-fixture.wasm")
	cmd := exec.Command("go", "build", "-buildmode=c-shared", "-o", out, ".")
	cmd.Dir = "testdata/fixture"
	cmd.Env = append(os.Environ(), "GOOS=wasip1", "GOARCH=wasm")
	if b, err := cmd.CombinedOutput(); err != nil {
		os.Stderr.Write(b)
		os.Exit(1)
	}
	var err error
	if fixtureWasm, err = os.ReadFile(out); err != nil {
		panic(err)
	}
	os.Exit(m.Run())
}

// A plugins directory holding the fixture as the plugin "hts_fix".
func fixtureHost(t *testing.T, maxCalls int) (*host, string) {
	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, "hts_fix"), 0o755); err != nil {
		t.Fatal(err)
	}
	module := filepath.Join(dir, "hts_fix", "hts_fix.wasm")
	if err := os.WriteFile(module, fixtureWasm, 0o644); err != nil {
		t.Fatal(err)
	}
	h := newHost(dir, maxCalls)
	t.Cleanup(h.close)
	return h, module
}

func uiCall(module, handler string, values map[string]any) envelope {
	input, _ := json.Marshal(map[string]any{"handler": handler, "values": values, "user": map[string]any{"id": 7, "permission": 8}})
	return envelope{
		CallID: "c_test", Plugin: "hts_fix", Module: module, Export: "on_ui",
		Input: input, Limits: limits{MemoryMB: 64, TimeoutMS: 3000},
		Settings: json.RawMessage(`{"channel":"general"}`),
	}
}

func output(t *testing.T, a answer) map[string]any {
	t.Helper()
	var out map[string]any
	if err := json.Unmarshal(a.Output, &out); err != nil {
		t.Fatalf("output is not an object: %s (%s)", a.Output, a.Error)
	}
	return out
}

func TestEchoGetsInputAndSettings(t *testing.T) {
	h, module := fixtureHost(t, 4)
	a := h.call(context.Background(), uiCall(module, "echo", map[string]any{"who": "Ann"}))
	if !a.OK {
		t.Fatalf("not ok: %s", a.Error)
	}
	out := output(t, a)
	if out["message"] != "echo" || out["settings"].(map[string]any)["channel"] != "general" ||
		out["got"].(map[string]any)["values"].(map[string]any)["who"] != "Ann" {
		t.Fatalf("unexpected output %s", a.Output)
	}

	env := uiCall(module, "", nil)
	env.Export = "on_signal"
	env.Input = json.RawMessage(`{"signal":"account.signup","data":{"user_id":3}}`)
	if a := h.call(context.Background(), env); !a.OK || output(t, a)["message"] != "echo" {
		t.Fatalf("on_signal: %s %s", a.Error, a.Output)
	}
}

func TestModulesGetRealRandomness(t *testing.T) {
	h, module := fixtureHost(t, 4)
	first := h.call(context.Background(), uiCall(module, "random", nil))
	second := h.call(context.Background(), uiCall(module, "random", nil))
	if !first.OK || !second.OK {
		t.Fatalf("%s %s", first.Error, second.Error)
	}
	a, b := output(t, first)["message"], output(t, second)["message"]
	if a == b || len(a.(string)) != 32 {
		t.Fatalf("two calls drew the same random bytes: %v %v", a, b)
	}
}

func TestModuleErrorIsReported(t *testing.T) {
	h, module := fixtureHost(t, 4)
	a := h.call(context.Background(), uiCall(module, "fail", nil))
	if a.OK || !strings.Contains(a.Error, "the module refused") {
		t.Fatalf("want the module's error, got ok=%v %q", a.OK, a.Error)
	}
}

func TestSpinningModuleIsStoppedAtItsDeadline(t *testing.T) {
	h, module := fixtureHost(t, 4)
	env := uiCall(module, "spin", nil)
	env.Limits.TimeoutMS = 300
	started := time.Now()
	a := h.call(context.Background(), env)
	if a.OK || !a.TimedOut {
		t.Fatalf("want timed out, got ok=%v timed_out=%v %q", a.OK, a.TimedOut, a.Error)
	}
	if took := time.Since(started); took > 3*time.Second {
		t.Fatalf("a 300 ms call took %v", took)
	}
}

func TestMemoryCapHolds(t *testing.T) {
	h, module := fixtureHost(t, 4)
	env := uiCall(module, "alloc", nil)
	env.Limits.MemoryMB = 32
	if a := h.call(context.Background(), env); a.OK {
		t.Fatalf("a module allocating 1 GB under a 32 MB cap finished: %s", a.Output)
	}
}

func TestOversizedOutputIsRefused(t *testing.T) {
	h, module := fixtureHost(t, 4)
	if a := h.call(context.Background(), uiCall(module, "noisy", nil)); a.OK || !strings.Contains(a.Error, "more than") {
		t.Fatalf("want refused, got ok=%v %q", a.OK, a.Error)
	}
}

func TestLogDoorIsRecorded(t *testing.T) {
	h, module := fixtureHost(t, 4)
	a := h.call(context.Background(), uiCall(module, "log", nil))
	if !a.OK || len(a.Calls) != 1 || a.Calls[0].Door != "log" || a.Calls[0].Detail != "hello from the module" {
		t.Fatalf("log not recorded: %+v %s", a.Calls, a.Error)
	}
}

func TestEnvelopeIsHeldToWhatCoreMayAsk(t *testing.T) {
	h, module := fixtureHost(t, 4)
	cases := map[string]func(e *envelope){
		"plugin name":      func(e *envelope) { e.Plugin = "../etc" },
		"export":           func(e *envelope) { e.Export = "_start" },
		"module elsewhere": func(e *envelope) { e.Module = "/etc/passwd" },
		"another's module": func(e *envelope) { e.Plugin = "other" },
		"module not wasm":  func(e *envelope) { e.Module = strings.TrimSuffix(module, ".wasm") + ".txt" },
		"api off the box": func(e *envelope) {
			e.API = &apiCredential{BaseURL: "https://203.0.113.5", Hostname: "site.example", PublicKey: "p", SecretKey: "s"}
		},
		"api by name": func(e *envelope) {
			e.API = &apiCredential{BaseURL: "https://site.example", Hostname: "site.example", PublicKey: "p", SecretKey: "s"}
		},
		"host is an IP":    func(e *envelope) { e.OutboundHosts = []string{"10.0.0.1"} },
		"host with a port": func(e *envelope) { e.OutboundHosts = []string{"example.com:8443"} },
	}
	for name, change := range cases {
		env := uiCall(module, "echo", nil)
		change(&env)
		if a := h.call(context.Background(), env); a.OK {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestBusyWhenEverySlotIsTaken(t *testing.T) {
	h, module := fixtureHost(t, 1)
	slow := uiCall(module, "spin", nil)
	slow.Limits.TimeoutMS = 1500
	var wg sync.WaitGroup
	wg.Add(1)
	go func() { defer wg.Done(); h.call(context.Background(), slow) }()
	time.Sleep(300 * time.Millisecond)
	if a := h.call(context.Background(), uiCall(module, "echo", nil)); a.OK || !strings.Contains(a.Error, "busy") {
		t.Fatalf("want busy, got ok=%v %q", a.OK, a.Error)
	}
	wg.Wait()
}

func TestAPIDoorCallsTheSiteAsThePlugin(t *testing.T) {
	var got *http.Request
	site := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Clone(context.Background())
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"data":{"count":3}}`)
	}))
	defer site.Close()

	h, module := fixtureHost(t, 4)
	env := uiCall(module, "api", map[string]any{"path": "/api/v1/Users?usr_permission=0"})
	env.API = &apiCredential{BaseURL: site.URL, Hostname: "site.example", PublicKey: "public_x", SecretKey: "secret_y"}
	a := h.call(context.Background(), env)
	if !a.OK {
		t.Fatalf("not ok: %s", a.Error)
	}
	out := output(t, a)["answer"].(map[string]any)
	if out["status"].(float64) != 200 || out["body"].(map[string]any)["data"].(map[string]any)["count"].(float64) != 3 {
		t.Fatalf("unexpected answer %s", a.Output)
	}
	if got == nil || got.Host != "site.example" || got.Header.Get("public-key") != "public_x" || got.Header.Get("secret-key") != "secret_y" ||
		got.URL.Path != "/api/v1/Users" || got.URL.RawQuery != "usr_permission=0" {
		t.Fatalf("the site saw %+v", got)
	}
	if len(a.Calls) != 1 || a.Calls[0].Door != "api" || a.Calls[0].Status != "ok" || strings.Contains(a.Calls[0].Detail+a.Calls[0].Target, "secret_y") {
		t.Fatalf("door log %+v", a.Calls)
	}

	for _, path := range []string{"/admin/admin_users", "/api/v1/../admin", "/api/v1/%2e%2e/admin", "/api/v1/x%2fy", "/api/v1//Users",
		"/api/v1/./Users", "https://evil.example/api/v1/x", "//evil.example/api/v1/x"} {
		got = nil
		env := uiCall(module, "api", map[string]any{"path": path})
		env.API = &apiCredential{BaseURL: site.URL, Hostname: "site.example", PublicKey: "p", SecretKey: "s"}
		a := h.call(context.Background(), env)
		if got != nil || len(a.Calls) != 1 || a.Calls[0].Status != "refused" {
			t.Errorf("path %q was not refused: %+v", path, a.Calls)
		}
	}

	env = uiCall(module, "api", map[string]any{"path": "/api/v1/Users"})
	if a := h.call(context.Background(), env); len(a.Calls) != 1 || a.Calls[0].Status != "refused" {
		t.Fatalf("with no credential: %+v", a.Calls)
	}
}

func TestAPIPathsJudgedOnThePathAlone(t *testing.T) {
	for _, ok := range []string{"/api/v1/Users", "/api/v1/Users?q=a..b", "/api/v1/action/mailbox/send", "/api/v1/Sbx_n_Log/3"} {
		if !apiPathAllowed(ok) {
			t.Errorf("%s was refused", ok)
		}
	}
}

func TestHTTPDoorReachesOnlyApprovedPublicHosts(t *testing.T) {
	var hits int
	remote := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hits++
		body, _ := io.ReadAll(r.Body)
		io.WriteString(w, "got "+string(body))
	}))
	defer remote.Close()
	_, port, _ := net.SplitHostPort(strings.TrimPrefix(remote.URL, "https://"))
	pool := x509.NewCertPool()
	pool.AddCert(remote.Certificate())

	h, module := fixtureHost(t, 4)
	old := guardLookup
	defer func() { guardLookup = old; guardAllowsPrivate = false; guardDialPort = ""; guardRootCAs = nil }()
	guardLookup = func(ctx context.Context, host string) ([]net.IP, error) {
		return []net.IP{net.ParseIP("127.0.0.1")}, nil
	}
	guardDialPort = port
	guardRootCAs = pool

	call := func(url string, hosts []string) answer {
		env := uiCall(module, "http", map[string]any{"url": url})
		env.OutboundHosts = hosts
		return h.call(context.Background(), env)
	}

	// The stand-in resolves to loopback, which the guard refuses.
	a := call("https://example.com/hook", []string{"example.com"})
	if hits != 0 || len(a.Calls) != 1 || a.Calls[0].Status != "refused" {
		t.Fatalf("an internal address was reached: hits=%d %+v", hits, a.Calls)
	}

	guardAllowsPrivate = true
	a = call("https://example.com/hook", []string{"example.com"})
	if hits != 1 || !a.OK || output(t, a)["body"] != "got hi" {
		t.Fatalf("an approved host was not reached: hits=%d %s %+v", hits, a.Output, a.Calls)
	}
	if len(a.Calls) != 1 || a.Calls[0].Target != "POST https://example.com/…" || strings.Contains(a.Calls[0].Target+a.Calls[0].Detail, "/hook") {
		t.Fatalf("the call log holds the URL's path, which may be its secret: %+v", a.Calls)
	}

	for _, refused := range []struct{ url string }{
		{"https://other.example/hook"},    // not approved
		{"http://example.com/hook"},       // not https
		{"https://example.com:8443/hook"}, // not 443
		{"https://user@example.com/hook"}, // credentials in the URL
	} {
		a := call(refused.url, []string{"example.com"})
		if len(a.Calls) != 1 || a.Calls[0].Status != "refused" {
			t.Errorf("%s was not refused: %+v", refused.url, a.Calls)
		}
	}
	if hits != 1 {
		t.Fatalf("a refused request reached the host: %d", hits)
	}
}

// addressCases reads the one list every outbound guard is tested against
// (tests/fixtures/outbound_address_cases.json): this host's, the relay's and
// core's UrlSafetyValidator.
func addressCases(t *testing.T) (refused, allowed []string) {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", "public_html", "tests", "fixtures", "outbound_address_cases.json"))
	if err != nil {
		t.Fatalf("the shared address list: %v", err)
	}
	var cases struct {
		Refused []struct{ Address string } `json:"refused"`
		Allowed []struct{ Address string } `json:"allowed"`
	}
	if err := json.Unmarshal(raw, &cases); err != nil || len(cases.Refused) == 0 || len(cases.Allowed) == 0 {
		t.Fatalf("the shared address list does not read: %v", err)
	}
	for _, c := range cases.Refused {
		refused = append(refused, c.Address)
	}
	for _, c := range cases.Allowed {
		allowed = append(allowed, c.Address)
	}
	return refused, allowed
}

func TestPublicAddressTable(t *testing.T) {
	refused, allowed := addressCases(t)
	for _, s := range refused {
		if isPublicIP(net.ParseIP(s)) {
			t.Errorf("%s is treated as public", s)
		}
	}
	for _, s := range allowed {
		if !isPublicIP(net.ParseIP(s)) {
			t.Errorf("%s is treated as internal", s)
		}
	}
}

func TestServesOverItsSocket(t *testing.T) {
	h, module := fixtureHost(t, 4)
	socket := filepath.Join(t.TempDir(), "host.sock")
	listener, err := listen(socket)
	if err != nil {
		t.Fatal(err)
	}
	server := &http.Server{Handler: h.handler()}
	go server.Serve(listener)
	defer server.Close()
	if fi, err := os.Stat(socket); err != nil || fi.Mode().Perm() != 0o666 {
		t.Fatalf("socket mode %v %v", fi.Mode(), err)
	}

	client := &http.Client{Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "unix", socket)
	}}}
	body, _ := json.Marshal(uiCall(module, "echo", map[string]any{"who": "Bo"}))
	resp, err := client.Post("http://sandbox-host/call", "application/json", bytes.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	var a answer
	json.NewDecoder(resp.Body).Decode(&a)
	resp.Body.Close()
	if !a.OK || a.Calls == nil {
		t.Fatalf("over the socket: %+v", a)
	}
	resp, err = client.Get("http://sandbox-host/health")
	if err != nil {
		t.Fatal(err)
	}
	var health map[string]any
	json.NewDecoder(resp.Body).Decode(&health)
	resp.Body.Close()
	if health["ok"] != true || health["version"] != version {
		t.Fatalf("health %v", health)
	}
	resp, _ = client.Post("http://sandbox-host/call", "application/json", strings.NewReader("not json"))
	json.NewDecoder(resp.Body).Decode(&a)
	resp.Body.Close()
	if a.OK {
		t.Fatal("a body that is not a call was run")
	}
}

var _ = tls.VersionTLS12

func TestSmallModuleRunsUnderTheDefaultCap(t *testing.T) {
	h, module := fixtureHost(t, 4)
	env := uiCall(module, "echo", nil)
	env.Limits.MemoryMB = 16
	if a := h.call(context.Background(), env); !a.OK {
		t.Fatalf("a Go module did not run in 16 MB: %s", a.Error)
	}
	env = uiCall(module, "alloc", nil)
	env.Limits.MemoryMB = 32
	a := h.call(context.Background(), env)
	t.Logf("alloc under 32 MB: ok=%v error=%q", a.OK, a.Error)
}
