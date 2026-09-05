package main

// The relay API, tested where it can be got wrong silently: the signed
// envelope (bound to method, URI and body; fresh; unreplayable), tenant
// scoping of every spool path, the root applier's validation of what the
// listener files, and the SNI split between the ACME name and the identity.
//
// The interop bytes are pinned against PHP by direct_wire_gate.sh; these are
// the properties that gate does not cover.

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

type relayFixture struct {
	t      *testing.T
	paths  relayPaths
	server *relayServer
	ts     *httptest.Server
	keys   map[string]ed25519.PrivateKey // tenant → signing key
	stop   chan struct{}
}

// newRelayFixture builds a relay home with tenant "main" (allowlist *) and an
// operator key, starts the listener over plain HTTP (TLS is tested separately),
// and runs the root applier in the background as the path unit would.
func newRelayFixture(t *testing.T) *relayFixture {
	t.Helper()
	root := t.TempDir()
	f := &relayFixture{
		t:     t,
		paths: relayPaths{home: filepath.Join(root, "opt"), spoolRoot: filepath.Join(root, "spool")},
		keys:  map[string]ed25519.PrivateKey{},
		stop:  make(chan struct{}),
	}
	postfix := filepath.Join(root, "postfix")
	for _, d := range []string{f.paths.home, f.paths.spoolRoot, postfix, f.paths.tenantsDir(),
		f.paths.requestsDir(), f.paths.verdictsDir(), f.paths.statusDir()} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("JOINERY_RELAY_HOME", f.paths.home)
	t.Setenv("JOINERY_RELAY_POSTFIX_DIR", postfix)
	t.Setenv("JOINERY_RELAY_SPOOL_ROOT", f.paths.spoolRoot)
	t.Setenv("JOINERY_RELAY_MERGE_NO_RELOAD", "1")
	t.Setenv("JOINERY_RELAY_REQUEST_UID", itoa(os.Getuid()))
	if err := os.WriteFile(filepath.Join(f.paths.home, "version"), []byte("3.0"), 0o644); err != nil {
		t.Fatal(err)
	}

	f.addKey("operator")
	if err := os.WriteFile(filepath.Join(f.paths.home, "operator_public_key"),
		[]byte(f.publicB64("operator")+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	f.addKey("main")
	if why := registerTenant(f.paths, "main", f.publicB64("main"), []string{"*"}, nil); why != "" {
		t.Fatalf("register main: %s", why)
	}

	identity, err := loadOrCreateIdentity(f.paths.identityDir())
	if err != nil {
		t.Fatal(err)
	}
	f.server = newRelayServer(f.paths, "mx.example.test", filepath.Join(f.paths.home, "routing.json"),
		f.paths.spoolRoot, filepath.Join(f.paths.home, "acme"), filepath.Join(f.paths.home, "direct"), identity)
	f.server.verdictWait = 5 * time.Second
	f.ts = httptest.NewServer(f.server)

	// The path unit, in miniature: react to the drop directory.
	go func() {
		for {
			select {
			case <-f.stop:
				return
			case <-time.After(50 * time.Millisecond):
				runApplyRequests()
			}
		}
	}()
	t.Cleanup(func() {
		close(f.stop)
		f.ts.Close()
	})
	return f
}

func itoa(n int) string { return strconv.Itoa(n) }

func (f *relayFixture) addKey(tenant string) {
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		f.t.Fatal(err)
	}
	f.keys[tenant] = priv
}

func (f *relayFixture) publicB64(tenant string) string {
	return base64.StdEncoding.EncodeToString(f.keys[tenant].Public().(ed25519.PublicKey))
}

type signOpts struct {
	tenant    string
	method    string
	uri       string // signed request_uri; defaults to the real one
	body      []byte
	bodyHash  string // override
	timestamp string // override
	nonce     string // override
	key       ed25519.PrivateKey
}

// signedRequest builds a request whose envelope describes it, unless an
// override says otherwise.
func (f *relayFixture) signedRequest(method, uri string, body []byte, tenant string, opts ...func(*signOpts)) *http.Request {
	o := &signOpts{tenant: tenant, method: method, uri: uri, body: body, key: f.keys[tenant]}
	for _, fn := range opts {
		fn(o)
	}
	nonce := o.nonce
	if nonce == "" {
		var raw [16]byte
		_, _ = rand.Read(raw[:])
		nonce = base64.StdEncoding.EncodeToString(raw[:])
	}
	ts := o.timestamp
	if ts == "" {
		ts = time.Now().UTC().Format("2006-01-02 15:04:05")
	}
	hash := o.bodyHash
	if hash == "" {
		hash = relayBodyHash(body)
	}
	env := relayEnvelope{
		ProtocolVersion: relayProtocolVersion,
		Tenant:          o.tenant,
		Method:          o.method,
		RequestURI:      o.uri,
		BodySHA256:      hash,
		Nonce:           nonce,
		Timestamp:       ts,
	}
	msg, err := relayRequestSigningBytes(env)
	if err != nil {
		f.t.Fatal(err)
	}
	header, err := encodeRelayAuthHeader(env, ed25519.Sign(o.key, msg))
	if err != nil {
		f.t.Fatal(err)
	}
	req, err := http.NewRequest(method, f.ts.URL+uri, bytes.NewReader(body))
	if err != nil {
		f.t.Fatal(err)
	}
	req.Header.Set(relayAuthHeader, header)
	return req
}

func (f *relayFixture) do(req *http.Request) (*http.Response, []byte) {
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		f.t.Fatal(err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	return resp, body
}

func (f *relayFixture) call(method, uri string, body []byte, tenant string, opts ...func(*signOpts)) (int, []byte) {
	resp, out := f.do(f.signedRequest(method, uri, body, tenant, opts...))
	return resp.StatusCode, out
}

func (f *relayFixture) spoolWrite(tenant, id, kind, content string) {
	dir := f.paths.spoolDir(tenant)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		f.t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, id+"."+kind), []byte(content), 0o640); err != nil {
		f.t.Fatal(err)
	}
}

// ---------------------------------------------------------------------------

func TestRelayPingNeedsASignature(t *testing.T) {
	f := newRelayFixture(t)

	req, _ := http.NewRequest(http.MethodGet, f.ts.URL+"/relay/ping", nil)
	resp, _ := f.do(req)
	if resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("unsigned ping: got %d, want 401", resp.StatusCode)
	}

	status, body := f.call(http.MethodGet, "/relay/ping", nil, "main")
	if status != http.StatusOK {
		t.Fatalf("signed ping: got %d: %s", status, body)
	}
	var ping map[string]any
	if err := json.Unmarshal(body, &ping); err != nil {
		t.Fatal(err)
	}
	// The keys the plane reads today.
	for _, k := range []string{"status", "services", "milters", "contract", "provisioned", "slug", "sole"} {
		if _, ok := ping[k]; !ok {
			t.Errorf("ping lacks compat key %q", k)
		}
	}
	if ping["slug"] != "main" || ping["sole"] != true || ping["provisioned"] != "3.0" {
		t.Errorf("ping compat values wrong: slug=%v sole=%v provisioned=%v", ping["slug"], ping["sole"], ping["provisioned"])
	}
	// The new groups.
	for _, k := range []string{"build", "identity", "listeners", "tls", "clock", "machine", "firewall", "postfix", "spool", "direct", "auth"} {
		if _, ok := ping[k]; !ok {
			t.Errorf("ping lacks group %q", k)
		}
	}
	identity := ping["identity"].(map[string]any)
	if identity["identity_fingerprint"] != f.server.identity.fingerprint {
		t.Errorf("ping reports a different fingerprint than the identity")
	}
	// No collector has run: services must read unknown, never blank-as-healthy.
	services := ping["services"].(map[string]any)
	if services["rspamd"] != "unknown" {
		t.Errorf("without a collector rspamd should be unknown, got %v", services["rspamd"])
	}
}

func TestRelayEnvelopeIsBoundToTheRequest(t *testing.T) {
	f := newRelayFixture(t)

	cases := []struct {
		name string
		opt  func(*signOpts)
	}{
		{"wrong method", func(o *signOpts) { o.method = "POST" }},
		{"wrong uri", func(o *signOpts) { o.uri = "/relay/spool" }},
		{"wrong body hash", func(o *signOpts) { o.bodyHash = relayBodyHash([]byte("x")) }},
		{"stale", func(o *signOpts) { o.timestamp = time.Now().UTC().Add(-10 * time.Minute).Format("2006-01-02 15:04:05") }},
		{"future", func(o *signOpts) { o.timestamp = time.Now().UTC().Add(10 * time.Minute).Format("2006-01-02 15:04:05") }},
		{"bad nonce", func(o *signOpts) { o.nonce = "short" }},
		{"unknown tenant", func(o *signOpts) { o.tenant = "ghost" }},
		{"wrong key", func(o *signOpts) { o.key = f.keys["operator"] }},
		{"path-shaped tenant", func(o *signOpts) { o.tenant = "../operator" }},
	}
	for _, c := range cases {
		status, _ := f.call(http.MethodGet, "/relay/ping", nil, "main", c.opt)
		if status != http.StatusUnauthorized {
			t.Errorf("%s: got %d, want 401", c.name, status)
		}
	}

	// A replay: the same signed request twice.
	req := f.signedRequest(http.MethodGet, "/relay/ping", nil, "main")
	header := req.Header.Get(relayAuthHeader)
	if resp, _ := f.do(req); resp.StatusCode != http.StatusOK {
		t.Fatalf("first use: %d", resp.StatusCode)
	}
	again, _ := http.NewRequest(http.MethodGet, f.ts.URL+"/relay/ping", nil)
	again.Header.Set(relayAuthHeader, header)
	if resp, _ := f.do(again); resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("replay: got %d, want 401", resp.StatusCode)
	}

	// Failures are counted for the ping's auth group, under the claimed slug.
	counts := f.server.auth.failureCounts()
	if counts["main"]["replay"] != 1 || counts["main"]["stale"] < 1 || counts["ghost"]["unknown_tenant"] != 1 {
		t.Errorf("auth counters: %v", counts)
	}
}

func TestRelaySpoolListFetchAck(t *testing.T) {
	f := newRelayFixture(t)

	// Two complete entries, one torn (artifact without .meta), one of the other
	// kind, and a stray tmp file.
	f.spoolWrite("main", "1000-aaaa", "meta", `{"spool_id":"1000-aaaa"}`)
	f.spoolWrite("main", "1000-aaaa", "seal", "v1.seal.AAAA")
	f.spoolWrite("main", "1001-bbbb", "meta", `{}`)
	f.spoolWrite("main", "1001-bbbb", "direct", `{"recipient":"x@y"}`)
	f.spoolWrite("main", "1002-torn", "seal", "v1.seal.TORN")
	if err := os.MkdirAll(filepath.Join(f.paths.spoolDir("main"), "tmp"), 0o700); err != nil {
		t.Fatal(err)
	}

	status, body := f.call(http.MethodGet, "/relay/spool?limit=1", nil, "main")
	if status != http.StatusOK {
		t.Fatalf("list: %d %s", status, body)
	}
	var page struct {
		Entries []spoolEntry `json:"entries"`
		More    bool         `json:"more"`
	}
	if err := json.Unmarshal(body, &page); err != nil {
		t.Fatal(err)
	}
	if len(page.Entries) != 1 || page.Entries[0].ID != "1000-aaaa" || page.Entries[0].Kind != "seal" || !page.More {
		t.Fatalf("first page: %+v more=%v", page.Entries, page.More)
	}
	status, body = f.call(http.MethodGet, "/relay/spool?after=1000-aaaa&limit=10", nil, "main")
	if err := json.Unmarshal(body, &page); err != nil || status != http.StatusOK {
		t.Fatalf("second page: %d %s", status, body)
	}
	if len(page.Entries) != 1 || page.Entries[0].ID != "1001-bbbb" || page.Entries[0].Kind != "direct" || page.More {
		t.Fatalf("second page should hold only the complete direct entry: %+v", page.Entries)
	}

	// Fetch, with a Range.
	req := f.signedRequest(http.MethodGet, "/relay/spool/1000-aaaa.seal", nil, "main")
	req.Header.Set("Range", "bytes=8-")
	resp, got := f.do(req)
	if resp.StatusCode != http.StatusPartialContent || string(got) != "AAAA" {
		t.Fatalf("range fetch: %d %q", resp.StatusCode, got)
	}
	status, got = f.call(http.MethodGet, "/relay/spool/1000-aaaa.meta", nil, "main")
	if status != http.StatusOK || !strings.Contains(string(got), "1000-aaaa") {
		t.Fatalf("meta fetch: %d %q", status, got)
	}

	// Names that try to leave the spool, or name another kind.
	for _, bad := range []string{"..%2F..%2Fetc%2Fpasswd.seal", "1000-aaaa.json", "tmp.seal", "..meta", "a/b.seal"} {
		status, _ := f.call(http.MethodGet, "/relay/spool/"+bad, nil, "main")
		if status != http.StatusBadRequest && status != http.StatusNotFound {
			t.Errorf("fetch %q: got %d, want a refusal", bad, status)
		}
	}

	// Another tenant sees an empty spool, not main's.
	f.addKey("other")
	if why := registerTenant(f.paths, "other", f.publicB64("other"), []string{"other.test"}, nil); why != "" {
		t.Fatal(why)
	}
	status, body = f.call(http.MethodGet, "/relay/spool", nil, "other")
	if err := json.Unmarshal(body, &page); err != nil || status != http.StatusOK || len(page.Entries) != 0 {
		t.Fatalf("other tenant's listing: %d %s", status, body)
	}
	status, _ = f.call(http.MethodGet, "/relay/spool/1000-aaaa.seal", nil, "other")
	if status != http.StatusNotFound {
		t.Fatalf("other tenant fetching main's entry: got %d, want 404", status)
	}
	// The operator has no spool at all.
	status, _ = f.call(http.MethodGet, "/relay/spool", nil, "operator")
	if status != http.StatusForbidden {
		t.Fatalf("operator listing a spool: got %d, want 403", status)
	}

	// Ack removes every kind for each id, including the torn entry.
	ack, _ := json.Marshal(map[string]any{"ids": []string{"1000-aaaa", "1001-bbbb", "1002-torn", "never-there"}})
	status, body = f.call(http.MethodPost, "/relay/spool/ack", ack, "main")
	if status != http.StatusOK || !strings.Contains(string(body), `"acked":3`) {
		t.Fatalf("ack: %d %s", status, body)
	}
	left, _ := os.ReadDir(f.paths.spoolDir("main"))
	for _, e := range left {
		if !e.IsDir() {
			t.Errorf("left behind after ack: %s", e.Name())
		}
	}
	badAck, _ := json.Marshal(map[string]any{"ids": []string{"../x"}})
	if status, _ = f.call(http.MethodPost, "/relay/spool/ack", badAck, "main"); status != http.StatusBadRequest {
		t.Fatalf("ack with a path: got %d, want 400", status)
	}
}

func TestRelayFragmentIsFiledMergedAndAnswered(t *testing.T) {
	f := newRelayFixture(t)

	frag := simpleFragment("main", "example.test")
	frag.Version = 7
	body, _ := json.Marshal(frag)
	status, out := f.call(http.MethodPut, "/relay/fragment", body, "main")
	if status != http.StatusOK {
		t.Fatalf("fragment: %d %s", status, out)
	}
	var v relayVerdict
	if err := json.Unmarshal(out, &v); err != nil {
		t.Fatal(err)
	}
	if v.Status != "ok" || v.Merge == nil || v.Merge.FragmentVersion != 7 || !v.Merge.Installed {
		t.Fatalf("verdict: %+v merge=%+v", v, v.Merge)
	}
	// The merge really ran: the routing map now carries the recipient.
	m, err := loadRoutingMap(filepath.Join(f.paths.home, "routing.json"))
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := m.Recipients["info@example.test"]; !ok {
		t.Fatalf("merged map lacks the pushed recipient: %v", m.Recipients)
	}
	// The request file is gone and the verdict was left for pruning.
	if entries, _ := os.ReadDir(f.paths.requestsDir()); len(entries) != 0 {
		t.Errorf("request not consumed: %d files left", len(entries))
	}

	// A fragment outside the allowlist is rejected whole, and says so.
	f.addKey("fenced")
	if why := registerTenant(f.paths, "fenced", f.publicB64("fenced"), []string{"fenced.test"}, nil); why != "" {
		t.Fatal(why)
	}
	bad := simpleFragment("fenced", "stolen.test")
	body, _ = json.Marshal(bad)
	status, out = f.call(http.MethodPut, "/relay/fragment", body, "fenced")
	if status != http.StatusUnprocessableEntity {
		t.Fatalf("out-of-allowlist fragment: %d %s", status, out)
	}
	if err := json.Unmarshal(out, &v); err != nil || v.Status != "rejected" || !strings.Contains(v.Reason, "allowlist") {
		t.Fatalf("rejection verdict: %+v", v)
	}

	// Not JSON at all is refused before anything is filed.
	if status, _ = f.call(http.MethodPut, "/relay/fragment", []byte("{nope"), "main"); status != http.StatusBadRequest {
		t.Fatalf("non-JSON fragment: %d", status)
	}
	// The operator cannot push a fragment.
	if status, _ = f.call(http.MethodPut, "/relay/fragment", body, "operator"); status != http.StatusForbidden {
		t.Fatalf("operator fragment: %d", status)
	}
}

func TestRelayTenantRoutesAreTheOperatorsAct(t *testing.T) {
	f := newRelayFixture(t)
	f.addKey("acme")

	add, _ := json.Marshal(map[string]any{
		"public_key": f.publicB64("acme"),
		"domains":    []string{"acme.test"},
		"limits":     map[string]int{"forward_hourly_limit": 50, "spool_max_mib": 100, "spool_max_entries": 0},
	})
	// A tenant key cannot add tenants.
	if status, _ := f.call(http.MethodPost, "/relay/tenants/acme", add, "main"); status != http.StatusForbidden {
		t.Fatalf("tenant adding a tenant: %d", status)
	}
	status, out := f.call(http.MethodPost, "/relay/tenants/acme", add, "operator")
	if status != http.StatusOK {
		t.Fatalf("operator add: %d %s", status, out)
	}
	if !tenantExists(f.paths, "acme") {
		t.Fatal("tenant directory not created")
	}
	limits := readLimits(filepath.Join(f.paths.tenantDir("acme"), "limits.json"))
	if limits.ForwardHourlyLimit != 50 || limits.SpoolMaxMiB != 100 {
		t.Fatalf("limits not stamped: %+v", limits)
	}
	if got := readAllowlist(filepath.Join(f.paths.tenantDir("acme"), "allowed_domains")); len(got) != 1 || got[0] != "acme.test" {
		t.Fatalf("allowlist: %v", got)
	}
	if _, err := os.Stat(filepath.Join(f.paths.spoolDir("acme"), "tmp")); err != nil {
		t.Fatal("spool directory not created")
	}
	// The new tenant can now authenticate.
	if status, _ := f.call(http.MethodGet, "/relay/ping", nil, "acme"); status != http.StatusOK {
		t.Fatalf("new tenant ping: %d", status)
	}

	// Set domains.
	set, _ := json.Marshal(map[string]any{"domains": []string{"acme.test", "acme-two.test"}})
	if status, out := f.call(http.MethodPut, "/relay/tenants/acme/domains", set, "operator"); status != http.StatusOK {
		t.Fatalf("set domains: %d %s", status, out)
	}
	if got := readAllowlist(filepath.Join(f.paths.tenantDir("acme"), "allowed_domains")); len(got) != 2 {
		t.Fatalf("allowlist after set: %v", got)
	}
	badSet, _ := json.Marshal(map[string]any{"domains": []string{"not a domain"}})
	if status, _ := f.call(http.MethodPut, "/relay/tenants/acme/domains", badSet, "operator"); status != http.StatusUnprocessableEntity {
		t.Fatalf("bad domain: %d", status)
	}

	// Remove refuses an undrained spool, then removes an empty one.
	f.spoolWrite("acme", "1-x", "meta", "{}")
	f.spoolWrite("acme", "1-x", "seal", "v1")
	status, out = f.call(http.MethodDelete, "/relay/tenants/acme", nil, "operator")
	if status != http.StatusUnprocessableEntity || !strings.Contains(string(out), "undrained") {
		t.Fatalf("remove with spool: %d %s", status, out)
	}
	ack, _ := json.Marshal(map[string]any{"ids": []string{"1-x"}})
	if status, _ := f.call(http.MethodPost, "/relay/spool/ack", ack, "acme"); status != http.StatusOK {
		t.Fatal("drain")
	}
	if status, out = f.call(http.MethodDelete, "/relay/tenants/acme", nil, "operator"); status != http.StatusOK {
		t.Fatalf("remove: %d %s", status, out)
	}
	if tenantExists(f.paths, "acme") {
		t.Fatal("tenant directory survived removal")
	}
	if status, _ := f.call(http.MethodGet, "/relay/ping", nil, "acme"); status != http.StatusUnauthorized {
		t.Fatalf("removed tenant still authenticates: %d", status)
	}

	// Bad inputs at the door.
	badKey, _ := json.Marshal(map[string]any{"public_key": "not-a-key", "domains": []string{"*"}})
	if status, _ := f.call(http.MethodPost, "/relay/tenants/nope", badKey, "operator"); status != http.StatusUnprocessableEntity {
		t.Fatalf("bad key: %d", status)
	}
	if status, _ := f.call(http.MethodPost, "/relay/tenants/Bad_Slug", add, "operator"); status != http.StatusBadRequest {
		t.Fatalf("bad slug: %d", status)
	}
}

func TestApplierRefusesWhatTheListenerDidNotFile(t *testing.T) {
	f := newRelayFixture(t)
	close(f.stop) // drive the applier by hand
	f.stop = make(chan struct{})

	// A request owned by someone else.
	t.Setenv("JOINERY_RELAY_REQUEST_UID", "0")
	req := relayRequest{ID: "00112233445566778899aabbccddeeff", Type: requestTypeFragment, Tenant: "main", By: "main",
		Fragment: json.RawMessage(`{}`)}
	data, _ := json.Marshal(req)
	if err := os.WriteFile(f.paths.requestFile(req.ID), data, 0o640); err != nil {
		t.Fatal(err)
	}
	runApplyRequests()
	raw, err := os.ReadFile(f.paths.verdictFile(req.ID))
	if err != nil {
		t.Fatal("no verdict written")
	}
	if !strings.Contains(string(raw), "not filed by the relay listener") {
		t.Fatalf("foreign request accepted: %s", raw)
	}
	t.Setenv("JOINERY_RELAY_REQUEST_UID", itoa(os.Getuid()))

	// A name the listener would never write is removed without a verdict.
	stray := filepath.Join(f.paths.requestsDir(), "evil.json")
	if err := os.WriteFile(stray, []byte(`{}`), 0o640); err != nil {
		t.Fatal(err)
	}
	runApplyRequests()
	if _, err := os.Stat(stray); !os.IsNotExist(err) {
		t.Fatal("stray request left in place")
	}

	// A fragment filed in another tenant's name is rejected by root even if the
	// listener were fooled.
	req = relayRequest{ID: "ffeeddccbbaa99887766554433221100", Type: requestTypeFragment, Tenant: "main", By: "other",
		Fragment: json.RawMessage(`{}`)}
	data, _ = json.Marshal(req)
	if err := os.WriteFile(f.paths.requestFile(req.ID), data, 0o640); err != nil {
		t.Fatal(err)
	}
	runApplyRequests()
	raw, _ = os.ReadFile(f.paths.verdictFile(req.ID))
	if !strings.Contains(string(raw), "own tenant") {
		t.Fatalf("cross-tenant fragment accepted: %s", raw)
	}

	// A symlink is not a request.
	target := filepath.Join(t.TempDir(), "target.json")
	_ = os.WriteFile(target, data, 0o640)
	link := f.paths.requestFile("0123456789abcdef0123456789abcdef")
	if err := os.Symlink(target, link); err != nil {
		t.Fatal(err)
	}
	runApplyRequests()
	raw, _ = os.ReadFile(f.paths.verdictFile("0123456789abcdef0123456789abcdef"))
	if !strings.Contains(string(raw), "regular file") {
		t.Fatalf("symlink request accepted: %s", raw)
	}
}

func TestEgressOnThePublicListenerIsSigned(t *testing.T) {
	f := newRelayFixture(t)

	// Unsigned: refused before any target is looked at.
	req, _ := http.NewRequest(http.MethodPost, f.ts.URL+egressPath, strings.NewReader("{}"))
	req.Header.Set(egressTargetHeader, "https://127.0.0.1/x")
	if resp, _ := f.do(req); resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("unsigned egress: %d", resp.StatusCode)
	}
	// Signed, but aimed at a private address: the target rules still hold.
	signed := f.signedRequest(http.MethodPost, egressPath, []byte("{}"), "main")
	signed.Header.Set(egressTargetHeader, "https://127.0.0.1/x")
	if resp, _ := f.do(signed); resp.StatusCode != http.StatusForbidden {
		t.Fatalf("signed egress to loopback: %d, want 403", resp.StatusCode)
	}
	if n := f.server.countSince(f.server.egressCalls, time.Hour); n != 1 {
		t.Fatalf("egress calls counted: %d", n)
	}
}

func TestDirectPathIsServedUntouched(t *testing.T) {
	f := newRelayFixture(t)
	// The Direct endpoint answers exactly as direct-serve did: no signature
	// needed, unknown step refused at request level.
	resp, body := f.do(mustReq(http.MethodPost, f.ts.URL+directEndpointPath+"?step=bogus", "{}"))
	if resp.StatusCode != http.StatusBadRequest || !strings.Contains(string(body), "Unknown step") {
		t.Fatalf("direct path: %d %s", resp.StatusCode, body)
	}
	resp, _ = f.do(mustReq(http.MethodGet, f.ts.URL+"/anything-else", ""))
	if resp.StatusCode != http.StatusNotFound {
		t.Fatalf("unknown path: %d", resp.StatusCode)
	}
}

func mustReq(method, url, body string) *http.Request {
	req, _ := http.NewRequest(method, url, strings.NewReader(body))
	return req
}

func TestIdentityIsStableAndSelectedBySNI(t *testing.T) {
	dir := t.TempDir()
	first, err := loadOrCreateIdentity(dir)
	if err != nil {
		t.Fatal(err)
	}
	second, err := loadOrCreateIdentity(dir)
	if err != nil {
		t.Fatal(err)
	}
	if first.fingerprint != second.fingerprint || first.publicKeyB64() != second.publicKeyB64() {
		t.Fatal("identity changed between loads")
	}
	if len(first.fingerprint) != 44 {
		t.Fatalf("fingerprint is not base64 of a SHA-256: %q", first.fingerprint)
	}
	// Half an identity is damage, not a reason to mint another.
	if err := os.Remove(filepath.Join(dir, identityCertFile)); err != nil {
		t.Fatal(err)
	}
	if _, err := loadOrCreateIdentity(dir); err == nil {
		t.Fatal("a half-present identity was silently regenerated")
	}

	// SNI: the mail hostname routes to ACME; anything else, or nothing, is the
	// identity certificate.
	s := &relayServer{hostname: "mx.example.test", identity: second}
	cfg := s.tlsConfig()
	for _, name := range []string{"", "203.0.113.5", "other.example.test"} {
		cert, err := cfg.GetCertificate(&tls.ClientHelloInfo{ServerName: name})
		if err != nil || cert != &second.cert {
			t.Errorf("SNI %q: want the identity certificate (err %v)", name, err)
		}
	}
	// The birth report signs with the same key the certificate carries.
	report := relayBirthReport{RunID: "42", PublicIP: "203.0.113.5", IdentityPublicKey: second.publicKeyB64(),
		IdentityFingerprint: second.fingerprint, RelayVersion: "3.0", Postfix: "ok", Listener443: "ok"}
	msg, err := relayBirthSigningBytes(report)
	if err != nil {
		t.Fatal(err)
	}
	if !verifyInstanceSignature(msg, second.sign(msg), second.publicKeyB64()) {
		t.Fatal("birth report signature does not verify against the identity public key")
	}
	if !strings.HasPrefix(string(msg), relayBornSigningPrefix) {
		t.Fatal("birth signing bytes lack their prefix")
	}
}

func TestRelaySigningBytesAreCanonical(t *testing.T) {
	env := relayEnvelope{ProtocolVersion: 1, Tenant: "main", Method: "get",
		RequestURI: "/relay/spool?after=1&limit=200", BodySHA256: strings.ToUpper(relayBodyHash(nil)),
		Nonce: "AAAAAAAAAAAAAAAAAAAAAA==", Timestamp: "2026-09-05 14:03:11"}
	got, err := relayRequestSigningBytes(env)
	if err != nil {
		t.Fatal(err)
	}
	want := relayRequestSigningPrefix + `{"protocol_version":1,"tenant":"main","method":"GET",` +
		`"request_uri":"/relay/spool?after=1&limit=200","body_sha256":"` + relayBodyHash(nil) +
		`","nonce":"AAAAAAAAAAAAAAAAAAAAAA==","timestamp":"2026-09-05 14:03:11"}`
	if string(got) != want {
		t.Fatalf("signing bytes:\n got %s\nwant %s", got, want)
	}
	// The header round-trips.
	header, err := encodeRelayAuthHeader(env, []byte("sig"))
	if err != nil {
		t.Fatal(err)
	}
	decoded, err := decodeRelayAuthHeader(header)
	if err != nil || decoded.Envelope != env || decoded.Signature != base64.StdEncoding.EncodeToString([]byte("sig")) {
		t.Fatalf("header round trip: %+v %v", decoded, err)
	}
}

// TestPingGatesTenantDataOnAFleetOfOne pins the rule joinery-ping had: the
// queue depth, the spool group and the journal excerpt are wholly the asker's
// only when the relay has exactly one tenant. On a shard they are ABSENT, never
// zero, and `sole` says so.
func TestPingGatesTenantDataOnAFleetOfOne(t *testing.T) {
	f := newRelayFixture(t)

	// A collector file claiming a queue depth, as root's timer would write it.
	depth := 3
	priv := privilegedStatus{
		CollectedUTC: time.Now().UTC().Format(time.RFC3339),
		Services:     map[string]serviceStatus{"rspamd": {Active: "active"}},
		Milters:      map[string]bool{"rspamd": true},
		Postfix:      postfixStatus{QueueDepth: &depth},
		Log:          []string{"a line"},
		TenantCount:  1,
	}
	raw, _ := json.Marshal(priv)
	if err := os.WriteFile(f.paths.privilegedStatus(), raw, 0o640); err != nil {
		t.Fatal(err)
	}

	ping := f.server.buildPing("main")
	if ping["sole"] != true || ping["queue"] != 3 {
		t.Fatalf("one tenant: sole=%v queue=%v", ping["sole"], ping["queue"])
	}
	for _, k := range []string{"spool", "log"} {
		if _, ok := ping[k]; !ok {
			t.Errorf("one tenant: %q should be present", k)
		}
	}
	if pf := ping["postfix"].(map[string]any); pf["queue_depth"] != 3 {
		t.Errorf("one tenant: postfix.queue_depth=%v", pf["queue_depth"])
	}

	f.addKey("other")
	if why := registerTenant(f.paths, "other", f.publicB64("other"), []string{"other.test"}, nil); why != "" {
		t.Fatal(why)
	}
	ping = f.server.buildPing("main")
	if ping["sole"] != false {
		t.Fatalf("two tenants: sole=%v", ping["sole"])
	}
	for _, k := range []string{"queue", "spool", "log"} {
		if _, ok := ping[k]; ok {
			t.Errorf("two tenants: %q must be absent, not zero", k)
		}
	}
	pf := ping["postfix"].(map[string]any)
	for _, k := range []string{"queue_depth", "accepted_1h"} {
		if _, ok := pf[k]; ok {
			t.Errorf("two tenants: postfix.%s must be absent", k)
		}
	}
	// Service liveness survives the gate.
	if _, ok := ping["services"]; !ok {
		t.Error("withholding tenant data must not cost the service liveness")
	}
	// The auth group falls back to totals, never per-slug, on a shard.
	auth := ping["auth"].(map[string]any)
	if _, ok := auth["failures_1h_by_tenant"]; ok {
		t.Error("two tenants: auth failures must not be broken out by tenant")
	}

	// No registry at all answers sole=false — "cannot tell" never authorises a wipe.
	if err := os.RemoveAll(f.paths.tenantsDir()); err != nil {
		t.Fatal(err)
	}
	if ping = f.server.buildPing("main"); ping["sole"] != false {
		t.Fatalf("no registry: sole=%v", ping["sole"])
	}
}

// TestProcNetPortParse pins the zero-padded hex the kernel writes: a text
// comparison against "19" never matched "0019", so a bound 25 read as unbound
// in the ping and the birth report.
func TestProcNetPortParse(t *testing.T) {
	table := `  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode
   0: 00000000:0019 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1 0000000000000000 100 0 0 10 0
   1: 00000000:01BB 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1 0000000000000000 100 0 0 10 0
   2: 0100007F:0016 0100007F:C8A2 01 00000000:00000000 00:00000000 00000000     0        0 1 0000000000000000 100 0 0 10 0
`
	if !procNetListsPort(table, 25) || !procNetListsPort(table, 443) {
		t.Fatal("bound ports 25 and 443 read as unbound")
	}
	if procNetListsPort(table, 22) {
		t.Fatal("an ESTABLISHED socket on 22 read as a listener")
	}
	if procNetListsPort(table, 8443) {
		t.Fatal("an unbound port read as bound")
	}
}
