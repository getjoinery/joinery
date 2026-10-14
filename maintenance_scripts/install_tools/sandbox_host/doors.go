package main

// The doors a module may use, as Extism host functions in the
// "extism:host/user" namespace. Each takes one JSON request and returns one
// JSON answer, honours the call's deadline, and is recorded in the call's log
// (what was asked, and whether it was done, refused or failed).
//
//	joinery_api   {method, path, body}            -> {status, body}            door 1
//	joinery_http  {method, url, headers, body}    -> {status, headers, body}   door 5
//	joinery_log   {level, message}                -> {}
//
// A refused or failed request answers {"error": "…"} rather than trapping, so
// a module can say what went wrong.

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	extism "github.com/extism/go-sdk"
)

const (
	maxDoorRequestBytes  = 1 << 20
	maxDoorResponseBytes = 1 << 20
	maxLogMessage        = 500
)

type callStateKey struct{}

// What one call may use, and what it has done.
type callState struct {
	env envelope

	mu      sync.Mutex
	calls   []doorCall
	dropped int
}

func (s *callState) record(door, target, status, detail string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.calls) >= maxCallsLogged {
		s.dropped++
		return
	}
	s.calls = append(s.calls, doorCall{Door: door, Target: oneLine(target, 300), Status: status, Detail: oneLine(detail, 500)})
}

func (s *callState) recorded() []doorCall {
	s.mu.Lock()
	defer s.mu.Unlock()
	out := append([]doorCall{}, s.calls...)
	if s.dropped > 0 {
		out = append(out, doorCall{Door: "log", Target: "host", Status: "error",
			Detail: fmt.Sprintf("%d more door calls were not recorded", s.dropped)})
	}
	return out
}

func doorFunctions() []extism.HostFunction {
	return []extism.HostFunction{
		door("joinery_api", apiDoor),
		door("joinery_http", httpDoor),
		door("joinery_log", logDoor),
	}
}

// door wraps one JSON-in, JSON-out door as a host function.
func door(name string, handle func(ctx context.Context, state *callState, request []byte) any) extism.HostFunction {
	return extism.NewHostFunctionWithStack(name, func(ctx context.Context, p *extism.CurrentPlugin, stack []uint64) {
		state, _ := ctx.Value(callStateKey{}).(*callState)
		var result any
		request, err := p.ReadBytes(stack[0])
		switch {
		case state == nil:
			result = map[string]string{"error": "no call is running"}
		case err != nil:
			result = map[string]string{"error": "the request could not be read"}
		case len(request) > maxDoorRequestBytes:
			result = map[string]string{"error": "the request is too large"}
		default:
			result = handle(ctx, state, request)
		}
		out, _ := json.Marshal(result)
		offset, err := p.WriteBytes(out)
		if err != nil {
			offset = 0
		}
		stack[0] = offset
	}, []extism.ValueType{extism.ValueTypePTR}, []extism.ValueType{extism.ValueTypePTR})
}

// Door 1: the site's API, as the plugin, over this machine's loopback.
func apiDoor(ctx context.Context, state *callState, raw []byte) any {
	var req struct {
		Method string          `json:"method"`
		Path   string          `json:"path"`
		Body   json.RawMessage `json:"body"`
	}
	if err := json.Unmarshal(raw, &req); err != nil {
		state.record("api", "", "refused", "the request is not JSON")
		return map[string]string{"error": "the request is not JSON"}
	}
	method := strings.ToUpper(strings.TrimSpace(req.Method))
	if method == "" {
		method = http.MethodGet
	}
	target := method + " " + req.Path
	if state.env.API == nil {
		state.record("api", target, "refused", "the plugin has no API credential")
		return map[string]string{"error": "this plugin has no API credential"}
	}
	if method != http.MethodGet && method != http.MethodPost && method != http.MethodPut && method != http.MethodDelete {
		state.record("api", target, "refused", "method not allowed")
		return map[string]string{"error": "method not allowed"}
	}
	// A path on the API and nothing else: no way out of /api/v1 by dots,
	// doubled slashes or an encoded escape.
	if !apiPathAllowed(req.Path) {
		state.record("api", target, "refused", "not an API path")
		return map[string]string{"error": "the path must be an /api/v1/ path"}
	}
	u, _ := url.Parse(req.Path)

	var body io.Reader
	if len(req.Body) > 0 && string(req.Body) != "null" {
		body = bytes.NewReader(req.Body)
	}
	httpReq, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(state.env.API.BaseURL, "/")+u.RequestURI(), body)
	if err != nil {
		state.record("api", target, "error", err.Error())
		return map[string]string{"error": "the request could not be made"}
	}
	httpReq.Host = state.env.API.Hostname
	httpReq.Header.Set("public-key", state.env.API.PublicKey)
	httpReq.Header.Set("secret-key", state.env.API.SecretKey)
	httpReq.Header.Set("Accept", "application/json")
	httpReq.Header.Set("User-Agent", "Joinery-SandboxHost/"+version+" ("+state.env.Plugin+")")
	if body != nil {
		httpReq.Header.Set("Content-Type", "application/json")
	}

	client := &http.Client{
		// Loopback to this machine's own web server, named as the site so it
		// routes there: the certificate is the site's, for a name this
		// address is not, and nothing between here and there can intercept.
		Transport: &http.Transport{
			TLSClientConfig: &tls.Config{ServerName: serverName(state.env.API.Hostname), InsecureSkipVerify: true, MinVersion: tls.VersionTLS12},
			DialContext:     (&net.Dialer{Timeout: 3 * time.Second}).DialContext,
		},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	resp, err := client.Do(httpReq)
	if err != nil {
		status := "error"
		if ctx.Err() != nil {
			status = "timeout"
		}
		state.record("api", target, status, err.Error())
		return map[string]string{"error": "the site did not answer"}
	}
	defer resp.Body.Close()
	data, tooLarge := readCapped(resp.Body, maxDoorResponseBytes)
	if tooLarge {
		state.record("api", target, "error", "answer over 1 MB")
		return map[string]string{"error": "the site's answer was larger than 1 MB"}
	}
	state.record("api", target, "ok", fmt.Sprintf("HTTP %d", resp.StatusCode))
	return map[string]any{"status": resp.StatusCode, "body": asJSON(data)}
}

// Door 5: HTTPS to a host an admin approved, through the guard.
func httpDoor(ctx context.Context, state *callState, raw []byte) any {
	var req struct {
		Method  string            `json:"method"`
		URL     string            `json:"url"`
		Headers map[string]string `json:"headers"`
		Body    string            `json:"body"`
	}
	if err := json.Unmarshal(raw, &req); err != nil {
		state.record("http", "", "refused", "the request is not JSON")
		return map[string]string{"error": "the request is not JSON"}
	}
	method := strings.ToUpper(strings.TrimSpace(req.Method))
	if method == "" {
		method = http.MethodGet
	}
	u, err := url.Parse(req.URL)
	// The log names the host and never the rest: a webhook's address is
	// often its secret (Slack's token is its path), kept out of the call log
	// an admin reads, as it is out of the settings page.
	target := method + " " + redactURL(u, req.URL)
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil {
		state.record("http", target, "refused", "only https URLs")
		return map[string]string{"error": "only https URLs are allowed"}
	}
	if u.Port() != "" && u.Port() != "443" {
		state.record("http", target, "refused", "only port 443")
		return map[string]string{"error": "only port 443 is allowed"}
	}
	hostname := strings.ToLower(u.Hostname())
	if !hostAllowed(hostname, state.env.OutboundHosts) {
		state.record("http", target, "refused", "host not approved")
		return map[string]string{"error": "the host " + hostname + " is not one this plugin may reach"}
	}
	switch method {
	case http.MethodGet, http.MethodPost, http.MethodPut, http.MethodPatch, http.MethodDelete, http.MethodHead:
	default:
		state.record("http", target, "refused", "method not allowed")
		return map[string]string{"error": "method not allowed"}
	}

	var body io.Reader
	if req.Body != "" {
		body = strings.NewReader(req.Body)
	}
	httpReq, err := http.NewRequestWithContext(ctx, method, u.String(), body)
	if err != nil {
		state.record("http", target, "error", err.Error())
		return map[string]string{"error": "the request could not be made"}
	}
	for name, value := range req.Headers {
		if headerAllowed(name) {
			httpReq.Header.Set(name, value)
		}
	}
	httpReq.Header.Set("User-Agent", "Joinery-SandboxHost/"+version+" ("+state.env.Plugin+")")

	resp, err := guardedClient().Do(httpReq)
	if err != nil {
		status := "error"
		if ctx.Err() != nil {
			status = "timeout"
		} else if strings.Contains(err.Error(), errNotPublic.Error()) {
			status = "refused"
		}
		detail := strings.ReplaceAll(strings.ReplaceAll(err.Error(), req.URL, redactURL(u, req.URL)), u.String(), redactURL(u, req.URL))
		state.record("http", target, status, detail)
		return map[string]string{"error": "the request failed: " + oneLine(detail, 200)}
	}
	defer resp.Body.Close()
	data, tooLarge := readCapped(resp.Body, maxDoorResponseBytes)
	if tooLarge {
		state.record("http", target, "error", "answer over 1 MB")
		return map[string]string{"error": "the answer was larger than 1 MB"}
	}
	headers := map[string]string{}
	for name := range resp.Header {
		headers[strings.ToLower(name)] = resp.Header.Get(name)
	}
	state.record("http", target, "ok", fmt.Sprintf("HTTP %d", resp.StatusCode))
	return map[string]any{"status": resp.StatusCode, "headers": headers, "body": string(data)}
}

// serverName is the site's name without a port, for TLS.
func serverName(hostname string) string {
	if h, _, err := net.SplitHostPort(hostname); err == nil {
		return h
	}
	return hostname
}

// A log line from the module, kept in the call's log.
func logDoor(ctx context.Context, state *callState, raw []byte) any {
	var req struct {
		Level   string `json:"level"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(raw, &req); err != nil {
		return map[string]string{"error": "the request is not JSON"}
	}
	level := strings.ToLower(req.Level)
	if level != "debug" && level != "info" && level != "warn" && level != "error" {
		level = "info"
	}
	message := req.Message
	if len(message) > maxLogMessage {
		message = message[:maxLogMessage]
	}
	state.record("log", level, "ok", message)
	return map[string]string{}
}

// apiPathAllowed: a path on the API and nothing else, judged on the path
// alone (a query value may hold anything): no scheme or host, no dot segment,
// no empty segment, no backslash, no encoded slash or dot.
func apiPathAllowed(raw string) bool {
	u, err := url.Parse(raw)
	if err != nil || u.Scheme != "" || u.Host != "" || u.User != nil || strings.HasPrefix(raw, "//") || !strings.HasPrefix(u.Path, "/api/v1/") {
		return false
	}
	escaped := strings.ToLower(u.EscapedPath())
	if strings.Contains(escaped, "%2f") || strings.Contains(escaped, "%2e") || strings.Contains(escaped, "%5c") || strings.Contains(u.Path, "\\") {
		return false
	}
	for i, segment := range strings.Split(u.Path, "/") {
		if i == 0 {
			continue
		}
		if segment == "." || segment == ".." || (segment == "" && i != len(strings.Split(u.Path, "/"))-1) {
			return false
		}
	}
	return true
}

// redactURL keeps a URL's scheme and host, and says whether there was more.
func redactURL(u *url.URL, raw string) string {
	if u == nil || u.Host == "" {
		return "(not a URL)"
	}
	if u.Path == "" && u.RawQuery == "" {
		return u.Scheme + "://" + u.Host
	}
	return u.Scheme + "://" + u.Host + "/…"
}

func hostAllowed(host string, allowed []string) bool {
	for _, a := range allowed {
		if a == host {
			return true
		}
	}
	return false
}

// Headers a module may set: anything but those that steer the connection.
func headerAllowed(name string) bool {
	switch strings.ToLower(name) {
	case "host", "connection", "content-length", "transfer-encoding", "te", "upgrade",
		"proxy-authorization", "proxy-connection", "keep-alive", "trailer", "user-agent":
		return false
	}
	return name != "" && !strings.ContainsAny(name, " \r\n:")
}

func readCapped(r io.Reader, max int64) ([]byte, bool) {
	data, _ := io.ReadAll(io.LimitReader(r, max+1))
	if int64(len(data)) > max {
		return nil, true
	}
	return data, false
}
