package main

// `relay-sealer relay-serve` — the one listener a relay has besides Postfix.
//
// One port, 443, certificate chosen by SNI: the mail hostname gets the ACME
// certificate (Direct, public callers); anything else — and the plane names no
// host, it connects by IP with a pinned key — gets the relay's identity
// certificate. Direct's path is served exactly as direct-serve served it. Egress
// moves from the tunnel address to this listener and gains a tenant signature.
// Everything new lives under /relay/ and every /relay/ request is signed.
//
// The process is the unprivileged relay user and never gains root. What needs
// root — a merge, a tenant change — is filed as a request into a drop directory
// a root path unit watches (relay_apply.go).

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"golang.org/x/crypto/acme/autocert"
)

const (
	relayRoutePrefix = "/relay/"

	spoolListDefault = 200
	spoolListMax     = 500

	// A spool ack names ids; a tenant-change body names a key and some domains.
	// Neither is large. The fragment PUT has its own cap (maxFragmentBytes).
	maxSmallBody = 1 << 20
)

var spoolIDRe = regexp.MustCompile(`^[A-Za-z0-9._-]+$`)

type relayServer struct {
	paths        relayPaths
	hostname     string
	routingPath  string
	defaultSpool string
	certCache    string
	auth         *relayAuth
	identity     *relayIdentity
	direct       *directHandler
	acme         *autocert.Manager
	// verdictWait is a field so the tests can shorten it.
	verdictWait time.Duration

	mu                sync.Mutex
	conns443          []time.Time
	directDeliveries  []time.Time
	egressCalls       []time.Time
	lastDirectError   string
	lastDirectErrorAt time.Time
	lastACMEError     string
	lastACMEAttempt   time.Time
}

func runRelayServe() int {
	fs := flag.NewFlagSet("relay-serve", flag.ContinueOnError)
	hostname := fs.String("hostname", "", "mail hostname this relay answers Direct on (required)")
	home := fs.String("home", envOr("JOINERY_RELAY_HOME", "/opt/joinery-relay"), "relay home")
	spoolRoot := fs.String("spool", envOr("JOINERY_RELAY_SPOOL_ROOT", envOr("JOINERY_RELAY_SPOOL", "/var/spool/joinery-relay")), "spool root")
	routing := fs.String("routing", "", "routing map path (default <home>/routing.json)")
	certDir := fs.String("cert-cache", "", "ACME certificate cache (default <home>/acme)")
	stateDir := fs.String("state", "", "Direct state directory (default <home>/direct)")
	httpsAddr := fs.String("listen", ":443", "HTTPS listen address")
	if err := fs.Parse(os.Args[2:]); err != nil {
		return 2
	}
	if strings.TrimSpace(*hostname) == "" {
		fmt.Fprintln(os.Stderr, "relay-sealer relay-serve: --hostname is required")
		return 2
	}
	paths := relayPaths{home: *home, spoolRoot: *spoolRoot}
	if *routing == "" {
		*routing = filepath.Join(*home, "routing.json")
	}
	if *certDir == "" {
		*certDir = filepath.Join(*home, "acme")
	}
	if *stateDir == "" {
		*stateDir = filepath.Join(*home, "direct")
	}

	identity, err := loadOrCreateIdentity(paths.identityDir())
	if err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer relay-serve: %v\n", err)
		return 1
	}

	s := newRelayServer(paths, strings.ToLower(strings.TrimSpace(*hostname)), *routing, *spoolRoot, *certDir, *stateDir, identity)

	stop := make(chan struct{})
	go func() {
		t := time.NewTicker(time.Minute)
		defer t.Stop()
		for {
			select {
			case <-t.C:
				s.direct.state.sweep()
				s.auth.sweep()
				s.sweepCounters()
			case <-stop:
				return
			}
		}
	}()

	server := &http.Server{
		Addr:              *httpsAddr,
		Handler:           s,
		TLSConfig:         s.tlsConfig(),
		ReadHeaderTimeout: 15 * time.Second,
		IdleTimeout:       60 * time.Second,
		ConnState:         s.connState,
	}

	errs := make(chan error, 1)
	go func() {
		fmt.Fprintf(os.Stderr, "relay-sealer: relay API listening on %s for %s (identity %s)\n",
			*httpsAddr, s.hostname, s.identity.fingerprint)
		if err := server.ListenAndServeTLS("", ""); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errs <- fmt.Errorf("listener: %w", err)
		}
	}()

	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)
	select {
	case err := <-errs:
		fmt.Fprintf(os.Stderr, "relay-sealer: %v\n", err)
		close(stop)
		return 1
	case <-signals:
		close(stop)
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
		return 0
	}
}

func newRelayServer(paths relayPaths, hostname, routing, defaultSpool, certDir, stateDir string, identity *relayIdentity) *relayServer {
	return &relayServer{
		paths:        paths,
		hostname:     hostname,
		routingPath:  routing,
		defaultSpool: defaultSpool,
		certCache:    certDir,
		auth:         newRelayAuth(paths.home),
		identity:     identity,
		direct:       newDirectHandler(routing, defaultSpool, filepath.Join(stateDir, "nonces")),
		acme: &autocert.Manager{
			Prompt:     autocert.AcceptTOS,
			HostPolicy: autocert.HostWhitelist(hostname),
			Cache:      autocert.DirCache(certDir),
		},
		verdictWait: verdictWait,
	}
}

// tlsConfig selects the certificate by SNI. The mail hostname is the ACME
// certificate's; every other name, and no name, is the relay identity.
func (s *relayServer) tlsConfig() *tls.Config {
	return &tls.Config{
		GetCertificate: func(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
			name := strings.ToLower(strings.TrimSuffix(hello.ServerName, "."))
			if name == s.hostname {
				cert, err := s.acme.GetCertificate(hello)
				s.mu.Lock()
				s.lastACMEAttempt = time.Now()
				if err != nil {
					s.lastACMEError = err.Error()
				} else {
					s.lastACMEError = ""
				}
				s.mu.Unlock()
				return cert, err
			}
			return &s.identity.cert, nil
		},
		NextProtos: []string{"h2", "http/1.1", "acme-tls/1"},
		MinVersion: tls.VersionTLS12,
	}
}

func (s *relayServer) connState(_ net.Conn, state http.ConnState) {
	if state != http.StateNew {
		return
	}
	s.mu.Lock()
	s.conns443 = append(s.conns443, time.Now())
	s.mu.Unlock()
}

func (s *relayServer) countSince(series []time.Time, window time.Duration) int {
	cutoff := time.Now().Add(-window)
	s.mu.Lock()
	defer s.mu.Unlock()
	n := 0
	for _, t := range series {
		if t.After(cutoff) {
			n++
		}
	}
	return n
}

func (s *relayServer) sweepCounters() {
	cutoff := time.Now().Add(-time.Hour)
	trim := func(in []time.Time) []time.Time {
		out := in[:0]
		for _, t := range in {
			if t.After(cutoff) {
				out = append(out, t)
			}
		}
		return out
	}
	s.mu.Lock()
	s.conns443 = trim(s.conns443)
	s.directDeliveries = trim(s.directDeliveries)
	s.egressCalls = trim(s.egressCalls)
	s.mu.Unlock()
}

// ServeHTTP is the whole listener: Direct's path untouched, egress signed, and
// the /relay/ routes.
func (s *relayServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.URL.Path == directEndpointPath:
		rec := &statusRecorder{ResponseWriter: w, status: http.StatusOK}
		s.direct.ServeHTTP(rec, r)
		s.noteDirect(r, rec.status)
	case r.URL.Path == egressPath:
		s.serveEgress(w, r)
	case strings.HasPrefix(r.URL.Path, relayRoutePrefix):
		s.serveRelay(w, r)
	default:
		http.NotFound(w, r)
	}
}

// noteDirect keeps the ping's Direct counters without touching the Direct
// handler: a 200 on the commit step is a delivery; a 5xx is an error worth
// showing.
func (s *relayServer) noteDirect(r *http.Request, status int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if status == http.StatusOK && r.URL.Query().Get("step") == "commit" {
		s.directDeliveries = append(s.directDeliveries, time.Now())
	}
	if status >= 500 {
		s.lastDirectError = "HTTP " + strconv.Itoa(status) + " on step " + r.URL.Query().Get("step")
		s.lastDirectErrorAt = time.Now()
	}
}

// serveEgress: the same proxy direct-serve bound to the tunnel, now on the
// public listener and therefore signed. The target rules are unchanged.
func (s *relayServer) serveEgress(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.NotFound(w, r)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, egressMaxBody+1))
	if err != nil || len(body) > egressMaxBody {
		refuse(w, http.StatusRequestEntityTooLarge, "body too large")
		return
	}
	if _, status, why := s.auth.verify(r, body); status != 0 {
		refuse(w, status, why)
		return
	}
	s.mu.Lock()
	s.egressCalls = append(s.egressCalls, time.Now())
	s.mu.Unlock()
	r.Body = io.NopCloser(bytes.NewReader(body))
	handleEgress(w, r)
}

// serveRelay authenticates, then dispatches on method and path.
func (s *relayServer) serveRelay(w http.ResponseWriter, r *http.Request) {
	limit := int64(maxSmallBody)
	if r.URL.Path == relayRoutePrefix+"fragment" {
		limit = maxFragmentBytes
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, limit+1))
	if err != nil || int64(len(body)) > limit {
		refuse(w, http.StatusRequestEntityTooLarge, "body too large")
		return
	}
	tenant, status, why := s.auth.verify(r, body)
	if status != 0 {
		refuse(w, status, why)
		return
	}

	rest := strings.TrimPrefix(r.URL.Path, relayRoutePrefix)
	switch {
	case rest == "ping" && r.Method == http.MethodGet:
		answerJSON(w, http.StatusOK, s.buildPing(tenant))

	case rest == "spool" && r.Method == http.MethodGet:
		s.listSpool(w, r, tenant)

	case strings.HasPrefix(rest, "spool/") && rest != "spool/ack" && r.Method == http.MethodGet:
		s.fetchSpool(w, r, tenant, strings.TrimPrefix(rest, "spool/"))

	case rest == "spool/ack" && r.Method == http.MethodPost:
		s.ackSpool(w, tenant, body)

	case rest == "fragment" && r.Method == http.MethodPut:
		s.putFragment(w, tenant, body)

	case strings.HasPrefix(rest, "tenants/"):
		s.tenantRoute(w, r, tenant, strings.TrimPrefix(rest, "tenants/"), body)

	default:
		refuse(w, http.StatusNotFound, "no such route")
	}
}

// spoolFor is the authenticated tenant's spool directory. The operator has no
// spool and cannot read anyone's.
func (s *relayServer) spoolFor(w http.ResponseWriter, tenant string) (string, bool) {
	if tenant == relayOperatorTenant {
		refuse(w, http.StatusForbidden, "the operator has no spool")
		return "", false
	}
	return s.paths.spoolDir(tenant), true
}

type spoolEntry struct {
	ID   string `json:"id"`
	Kind string `json:"kind"`
	Size int64  `json:"size"`
}

// listSpool answers complete entries only — both the artifact and its .meta
// present — oldest first, in a bounded page. `after` is the last id the caller
// saw; ids are lexically sortable by arrival.
func (s *relayServer) listSpool(w http.ResponseWriter, r *http.Request, tenant string) {
	dir, ok := s.spoolFor(w, tenant)
	if !ok {
		return
	}
	after := r.URL.Query().Get("after")
	limit := spoolListDefault
	if v := r.URL.Query().Get("limit"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n <= 0 {
			refuse(w, http.StatusBadRequest, "bad limit")
			return
		}
		if n > spoolListMax {
			n = spoolListMax
		}
		limit = n
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		answerJSON(w, http.StatusOK, map[string]any{"entries": []spoolEntry{}, "more": false})
		return
	}
	metas := map[string]bool{}
	artifacts := map[string]spoolEntry{}
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		name := e.Name()
		switch {
		case strings.HasSuffix(name, ".meta"):
			metas[strings.TrimSuffix(name, ".meta")] = true
		case strings.HasSuffix(name, ".seal"), strings.HasSuffix(name, ".direct"):
			info, err := e.Info()
			if err != nil {
				continue
			}
			id := strings.TrimSuffix(strings.TrimSuffix(name, ".seal"), ".direct")
			kind := "seal"
			if strings.HasSuffix(name, ".direct") {
				kind = "direct"
			}
			artifacts[id] = spoolEntry{ID: id, Kind: kind, Size: info.Size()}
		}
	}
	var complete []spoolEntry
	for id, entry := range artifacts {
		if !metas[id] || (after != "" && id <= after) {
			continue
		}
		complete = append(complete, entry)
	}
	sort.Slice(complete, func(i, j int) bool { return complete[i].ID < complete[j].ID })
	more := len(complete) > limit
	if more {
		complete = complete[:limit]
	}
	if complete == nil {
		complete = []spoolEntry{}
	}
	answerJSON(w, http.StatusOK, map[string]any{"entries": complete, "more": more})
}

// fetchSpool serves one artifact's bytes; Range is honoured for resume.
func (s *relayServer) fetchSpool(w http.ResponseWriter, r *http.Request, tenant, name string) {
	dir, ok := s.spoolFor(w, tenant)
	if !ok {
		return
	}
	id, kind, valid := splitSpoolName(name)
	if !valid {
		refuse(w, http.StatusBadRequest, "bad spool name")
		return
	}
	path := filepath.Join(dir, id+"."+kind)
	f, err := os.Open(path)
	if err != nil {
		refuse(w, http.StatusNotFound, "no such entry")
		return
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || !info.Mode().IsRegular() {
		refuse(w, http.StatusNotFound, "no such entry")
		return
	}
	w.Header().Set("Content-Type", "application/octet-stream")
	w.Header().Set("Cache-Control", "no-store")
	http.ServeContent(w, r, id+"."+kind, info.ModTime(), f)
}

// splitSpoolName validates "<id>.<seal|direct|meta>". The id is one path
// component with no separators, so a name can never leave the tenant's spool.
func splitSpoolName(name string) (id, kind string, ok bool) {
	dot := strings.LastIndex(name, ".")
	if dot <= 0 {
		return "", "", false
	}
	id, kind = name[:dot], name[dot+1:]
	if kind != "seal" && kind != "direct" && kind != "meta" {
		return "", "", false
	}
	if !validSpoolID(id) {
		return "", "", false
	}
	return id, kind, true
}

func validSpoolID(id string) bool {
	return id != "" && len(id) <= 128 && spoolIDRe.MatchString(id) &&
		id != "." && id != ".." && filepath.Base(id) == id
}

// ackSpool removes every artifact kind for each id — .seal, .direct and .meta —
// so an acked entry of either kind never lingers.
func (s *relayServer) ackSpool(w http.ResponseWriter, tenant string, body []byte) {
	dir, ok := s.spoolFor(w, tenant)
	if !ok {
		return
	}
	var req struct {
		IDs []string `json:"ids"`
	}
	if err := json.Unmarshal(body, &req); err != nil {
		refuse(w, http.StatusBadRequest, "malformed ack")
		return
	}
	if len(req.IDs) > spoolListMax {
		refuse(w, http.StatusBadRequest, "too many ids")
		return
	}
	acked := 0
	for _, id := range req.IDs {
		if !validSpoolID(id) {
			refuse(w, http.StatusBadRequest, "bad id")
			return
		}
		removed := false
		for _, kind := range []string{"seal", "direct", "meta"} {
			if os.Remove(filepath.Join(dir, id+"."+kind)) == nil {
				removed = true
			}
		}
		if removed {
			acked++
		}
	}
	answerJSON(w, http.StatusOK, map[string]any{"acked": acked})
}

// putFragment files the tenant's fragment for root to merge and returns the
// merge verdict.
func (s *relayServer) putFragment(w http.ResponseWriter, tenant string, body []byte) {
	if tenant == relayOperatorTenant {
		refuse(w, http.StatusForbidden, "a fragment is a tenant's own act")
		return
	}
	if !json.Valid(body) {
		refuse(w, http.StatusBadRequest, "fragment is not valid JSON")
		return
	}
	s.fileAndAnswer(w, relayRequest{
		Type:     requestTypeFragment,
		Tenant:   tenant,
		By:       tenant,
		Fragment: json.RawMessage(body),
	})
}

// tenantRoute: POST /relay/tenants/{slug}, PUT /relay/tenants/{slug}/domains,
// DELETE /relay/tenants/{slug}. Operator only.
func (s *relayServer) tenantRoute(w http.ResponseWriter, r *http.Request, caller, rest string, body []byte) {
	if caller != relayOperatorTenant {
		refuse(w, http.StatusForbidden, "tenant changes are the operator's act")
		return
	}
	slug, tail, _ := strings.Cut(rest, "/")
	if !slugRe.MatchString(slug) {
		refuse(w, http.StatusBadRequest, "bad tenant slug")
		return
	}
	var change struct {
		PublicKey string        `json:"public_key"`
		Domains   []string      `json:"domains"`
		Limits    *tenantLimits `json:"limits"`
	}
	if len(body) > 0 {
		if err := json.Unmarshal(body, &change); err != nil {
			refuse(w, http.StatusBadRequest, "malformed body")
			return
		}
	}
	req := relayRequest{Tenant: slug, By: relayOperatorTenant}
	switch {
	case tail == "" && r.Method == http.MethodPost:
		req.Type = requestTypeTenantAdd
		req.PublicKey = change.PublicKey
		req.Domains = change.Domains
		req.Limits = change.Limits
	case tail == "domains" && r.Method == http.MethodPut:
		req.Type = requestTypeTenantSetDomains
		req.Domains = change.Domains
	case tail == "" && r.Method == http.MethodDelete:
		req.Type = requestTypeTenantRemove
	default:
		refuse(w, http.StatusNotFound, "no such route")
		return
	}
	s.fileAndAnswer(w, req)
}

// fileAndAnswer writes the request into the drop directory and waits, for a
// bounded time, for root's verdict. The verdict is the response body.
func (s *relayServer) fileAndAnswer(w http.ResponseWriter, req relayRequest) {
	req.ID = newRequestID()
	req.FiledUTC = time.Now().UTC().Format(time.RFC3339)
	data, err := json.Marshal(req)
	if err != nil {
		refuse(w, http.StatusInternalServerError, "cannot encode request")
		return
	}
	if err := writeFileAtomic(s.paths.requestFile(req.ID), data, 0o640); err != nil {
		fmt.Fprintf(os.Stderr, "relay-sealer: cannot file request: %v\n", err)
		refuse(w, http.StatusServiceUnavailable, "cannot file the request")
		return
	}

	deadline := time.Now().Add(s.verdictWait)
	for time.Now().Before(deadline) {
		raw, err := os.ReadFile(s.paths.verdictFile(req.ID))
		if err == nil {
			var v relayVerdict
			if json.Unmarshal(raw, &v) == nil && v.ID == req.ID {
				status := http.StatusOK
				switch v.Status {
				case "rejected":
					status = http.StatusUnprocessableEntity
				case "error":
					status = http.StatusInternalServerError
				}
				answerVerdict(w, status, v)
				return
			}
		}
		time.Sleep(100 * time.Millisecond)
	}
	answerVerdict(w, http.StatusGatewayTimeout, relayVerdict{
		ID: req.ID, Status: "timeout", Reason: "the relay did not apply the request in time",
	})
}

func answerVerdict(w http.ResponseWriter, status int, v relayVerdict) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// statusRecorder captures the status a wrapped handler wrote.
type statusRecorder struct {
	http.ResponseWriter
	status int
}

func (r *statusRecorder) WriteHeader(code int) {
	r.status = code
	r.ResponseWriter.WriteHeader(code)
}
