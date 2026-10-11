package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// Upstream calls one site's mail_apps API over loopback.
type Upstream struct {
	base   *url.URL
	host   string
	client *http.Client
}

// Creds is what a mail program presented, relayed unchanged. The gateway
// never judges a credential; the site's ApiAuth does.
type Creds struct {
	Username string // the mailbox address the program signed in as
	Secret   string // the password exactly as typed
	ClientIP string // the program's address
}

// ErrKind classifies an upstream failure for the protocol faces.
type ErrKind int

const (
	KindRefusal     ErrKind = iota // 422 ActionError: the site said no, with imap_code / smtp_code
	KindAuth                       // the credential was not accepted (401/403, or an AuthenticationError)
	KindRateLimit                  // 429
	KindTooLarge                   // 413
	KindInvalid                    // 422 ValidationError or 400: the request was not acceptable
	KindServerBug                  // 500
	KindUnavailable                // network error, 404 (plugin off), 502-504, anything else
)

// UpstreamError is a failed call. Message is the site's own sentence where it
// gave one, suitable to show a person; it never contains a secret.
type UpstreamError struct {
	Kind       ErrKind
	Status     int
	Message    string
	IMAPCode   string
	SMTPCode   string
	RetryAfter time.Duration
	Action     string
}

func (e *UpstreamError) Error() string {
	return fmt.Sprintf("%s: HTTP %d: %s", e.Action, e.Status, e.Message)
}

// maxResponseBytes bounds what one call may answer: the site stops a fetch
// batch once it passes 12 MB, so the most is ~12 MB plus one whole message of
// up to ~27 MB, as base64 in JSON.
const maxResponseBytes = 64 << 20

func NewUpstream(cfg SiteConfig) (*Upstream, error) {
	u, err := url.Parse(cfg.Upstream)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return nil, fmt.Errorf("upstream %q is not an http(s) URL", cfg.Upstream)
	}
	tr := &http.Transport{
		Proxy: nil,
		DialContext: (&net.Dialer{
			Timeout:   5 * time.Second,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		TLSClientConfig: &tls.Config{
			ServerName:         cfg.Hostname,
			InsecureSkipVerify: cfg.UpstreamInsecureTLS,
			MinVersion:         tls.VersionTLS12,
		},
		MaxIdleConns:          64,
		MaxIdleConnsPerHost:   32,
		IdleConnTimeout:       90 * time.Second,
		TLSHandshakeTimeout:   10 * time.Second,
		ResponseHeaderTimeout: 0, // per-call context deadlines govern
		ForceAttemptHTTP2:     false,
	}
	return &Upstream{
		base:   u,
		host:   cfg.Hostname,
		client: &http.Client{Transport: tr, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }},
	}, nil
}

// timeoutFor sizes each action's deadline: a whole-message fetch may take the
// site ~20 s to rebuild and send; a full folder listing of 100,000 messages
// takes a while; a flag change does not.
func timeoutFor(action string) time.Duration {
	switch action {
	case "mail_fetch", "mail_sync", "mail_search":
		return 120 * time.Second
	case "mail_submit", "mail_append", "mail_move", "mail_copy", "mail_expunge", "mail_store":
		return 120 * time.Second
	default:
		return 30 * time.Second
	}
}

// envelope is the site's answer; Data decodes straight into the caller's
// value (one pass, no intermediate copy of a large answer).
type envelope struct {
	Data      any    `json:"data"`
	Error     string `json:"error"`
	ErrorType string `json:"errortype"`
}

type refusalData struct {
	IMAPCode string  `json:"imap_code"`
	SMTPCode *string `json:"smtp_code"`
}

// Call runs POST {upstream}/api/v1/action/mail_apps/{action} with body (to
// which "mailbox" is added) and decodes the answer's data into out.
func (u *Upstream) Call(ctx context.Context, cr Creds, action string, body map[string]any, out any) error {
	held, err := u.CallHold(ctx, cr, action, body, nil, false, out)
	held.Done()
	return err
}

// CallHold is Call with the memory accounting exposed. It reserves the
// answer's size from the in-flight budget before reading it (twice over:
// the bytes read, and what they decode to). When hold is set — whole messages
// the caller will go on writing out — a reservation the size of the answer
// is kept until the caller runs release; otherwise everything is released
// once decoded. message, when not nil, is sent as the body's "message" field,
// base64-encoded as it streams rather than built in memory.
func (u *Upstream) CallHold(ctx context.Context, cr Creds, action string, body map[string]any, message []byte, hold bool, out any) (*Held, error) {
	if body == nil {
		body = map[string]any{}
	}
	body["mailbox"] = cr.Username
	payload, err := json.Marshal(body)
	if err != nil {
		return nil, &UpstreamError{Kind: KindUnavailable, Action: action, Message: "encoding request: " + err.Error()}
	}
	ctx, cancel := context.WithTimeout(ctx, timeoutFor(action))
	defer cancel()

	var reqBody io.ReadCloser
	length := int64(len(payload))
	if message == nil {
		reqBody = io.NopCloser(bytes.NewReader(payload))
	} else {
		prefix := append(payload[:len(payload)-1:len(payload)-1], []byte(`,"message":"`)...)
		suffix := []byte(`"}`)
		pr, pw := io.Pipe()
		go func() {
			enc := base64.NewEncoder(base64.StdEncoding, pw)
			_, werr := enc.Write(message)
			if werr == nil {
				werr = enc.Close()
			}
			pw.CloseWithError(werr)
		}()
		length = int64(len(prefix)) + int64(base64.StdEncoding.EncodedLen(len(message))) + int64(len(suffix))
		reqBody = readCloser{io.MultiReader(bytes.NewReader(prefix), pr, bytes.NewReader(suffix)), pr.Close}
	}

	endpoint := *u.base
	endpoint.Path = strings.TrimSuffix(endpoint.Path, "/") + "/api/v1/action/mail_apps/" + action
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint.String(), reqBody)
	if err != nil {
		reqBody.Close()
		return nil, &UpstreamError{Kind: KindUnavailable, Action: action, Message: err.Error()}
	}
	req.ContentLength = length
	req.Host = u.host
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Joinery-App-Username", headerSafe(cr.Username))
	req.Header.Set("X-Joinery-App-Secret", headerSafe(cr.Secret))
	if cr.ClientIP != "" {
		req.Header.Set("X-Joinery-Client-Ip", cr.ClientIP)
		req.Header.Set("X-Forwarded-For", cr.ClientIP)
	}
	req.Header.Set("X-Forwarded-Proto", "https")
	req.Header.Set("Client-App", "joinery-mail-gateway")
	req.Header.Set("Client-Version", Version)

	resp, err := u.client.Do(req)
	if err != nil {
		return nil, &UpstreamError{Kind: KindUnavailable, Action: action, Message: describeNetErr(err)}
	}
	defer resp.Body.Close()
	if resp.ContentLength > maxResponseBytes {
		return nil, &UpstreamError{Kind: KindServerBug, Status: resp.StatusCode, Action: action, Message: "answer too large"}
	}

	// Reserve before reading.
	// An answer of unknown length (chunked, as large PHP answers are) starts
	// small and reserves more as it arrives.
	expect := resp.ContentLength
	if expect < 0 {
		expect = 1 << 20
	}
	reserved := int64(0)
	grow := func(n int64) error {
		ctx, cancel := context.WithTimeout(context.Background(), inflightWait)
		defer cancel()
		if err := inflight.Acquire(ctx, n); err != nil {
			return err
		}
		reserved += n
		return nil
	}
	releaseAll := func() { inflight.Release(reserved); reserved = 0 }
	// The read buffer, plus what it decodes to (base64 decodes to 3/4).
	if expect > maxResponseBytes {
		expect = maxResponseBytes
	}
	if err := grow((expect + 1) + (expect+1)*3/4); err != nil {
		return nil, busyError(action)
	}
	raw, err := readReserved(resp.Body, expect, func(oldCap, newCap int64) error {
		// The new buffer and the extra it decodes to; the old buffer is
		// given back once copied.
		return grow(newCap + (newCap-oldCap)*3/4)
	}, func(oldCap int64) {
		inflight.Release(oldCap)
		reserved -= oldCap
	})
	if err != nil {
		releaseAll()
		if errors.Is(err, errBusy) {
			return nil, busyError(action)
		}
		if errors.Is(err, errTooLargeAnswer) {
			return nil, &UpstreamError{Kind: KindServerBug, Status: resp.StatusCode, Action: action, Message: "answer too large"}
		}
		return nil, &UpstreamError{Kind: KindUnavailable, Status: resp.StatusCode, Action: action, Message: "reading answer: " + describeNetErr(err)}
	}

	if resp.StatusCode == http.StatusOK {
		env := envelope{Data: out}
		if out == nil {
			env.Data = &json.RawMessage{}
		}
		jsonErr := json.Unmarshal(raw, &env)
		size := int64(len(raw))
		raw = nil
		if jsonErr != nil {
			releaseAll()
			return nil, &UpstreamError{Kind: KindServerBug, Status: 200, Action: action, Message: "answer not understood: " + jsonErr.Error()}
		}
		if !hold {
			releaseAll()
			return nil, nil
		}
		// Keep the size of the answer: the decoded messages, and the copies
		// a section or structure extraction makes while they are written.
		// The caller trims it to what it really keeps (Held.TrimTo) and gives
		// it back message by message as each is written (Held.Release).
		keep := size
		if keep > reserved {
			keep = reserved
		}
		inflight.Release(reserved - keep)
		reserved = 0
		return &Held{global: keep}, nil
	}
	defer releaseAll()

	var rd refusalData
	env := envelope{Data: &rd}
	if json.Unmarshal(raw, &env) != nil {
		rd = refusalData{}
		var loose struct {
			Error     string `json:"error"`
			ErrorType string `json:"errortype"`
		}
		json.Unmarshal(raw, &loose)
		env.Error, env.ErrorType = loose.Error, loose.ErrorType
	}
	ue := &UpstreamError{Status: resp.StatusCode, Action: action, Message: ascii(strings.TrimSpace(env.Error))}
	if ue.Message == "" {
		ue.Message = http.StatusText(resp.StatusCode)
	}
	switch {
	case env.ErrorType == "AuthenticationError" || resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		ue.Kind = KindAuth
	case resp.StatusCode == http.StatusTooManyRequests:
		ue.Kind = KindRateLimit
		ue.RetryAfter = parseRetryAfter(resp.Header.Get("Retry-After"))
	case resp.StatusCode == http.StatusRequestEntityTooLarge:
		ue.Kind = KindTooLarge
	case resp.StatusCode == http.StatusUnprocessableEntity && env.ErrorType == "ActionError":
		ue.Kind = KindRefusal
		ue.IMAPCode = strings.ToUpper(strings.TrimSpace(rd.IMAPCode))
		if rd.SMTPCode != nil {
			ue.SMTPCode = strings.TrimSpace(*rd.SMTPCode)
		}
	case resp.StatusCode == http.StatusUnprocessableEntity || resp.StatusCode == http.StatusBadRequest:
		ue.Kind = KindInvalid
	case resp.StatusCode == http.StatusInternalServerError:
		ue.Kind = KindServerBug
	default:
		ue.Kind = KindUnavailable
	}
	return nil, ue
}

func parseRetryAfter(v string) time.Duration {
	v = strings.TrimSpace(v)
	if v == "" {
		return 0
	}
	if n, err := strconv.Atoi(v); err == nil && n >= 0 {
		return time.Duration(n) * time.Second
	}
	if t, err := http.ParseTime(v); err == nil {
		if d := time.Until(t); d > 0 {
			return d
		}
	}
	return 0
}

// headerSafe keeps a header value from carrying a line break; a password with
// one can never be right, and must not split the request.
func headerSafe(s string) string {
	if strings.ContainsAny(s, "\r\n\x00") {
		return strings.NewReplacer("\r", "", "\n", "", "\x00", "").Replace(s)
	}
	return s
}

func describeNetErr(err error) string {
	var ne net.Error
	if errors.Is(err, context.DeadlineExceeded) || (errors.As(err, &ne) && ne.Timeout()) {
		return "the site did not answer in time"
	}
	var ue *url.Error
	if errors.As(err, &ue) {
		return ue.Err.Error()
	}
	return err.Error()
}

// asUpstream unwraps an *UpstreamError.
func asUpstream(err error) (*UpstreamError, bool) {
	var ue *UpstreamError
	if errors.As(err, &ue) {
		return ue, true
	}
	return nil, false
}

// retryText words a rate-limit wait.
func retryText(d time.Duration) string {
	if d <= 0 {
		return "Too many requests; try again shortly."
	}
	s := int(d.Round(time.Second) / time.Second)
	if s < 1 {
		s = 1
	}
	if s < 120 {
		return fmt.Sprintf("Too many requests; try again in %d seconds.", s)
	}
	return fmt.Sprintf("Too many requests; try again in %d minutes.", (s+59)/60)
}

// ascii makes text a mail program may display safe to show: IMAP and SMTP
// response text is ASCII, and Thunderbird mangles anything else (an em dash
// arrives as "â"). Dashes, quotes and ellipses become their ASCII forms, line
// breaks become spaces, and anything else outside ASCII becomes "?".
func ascii(s string) string {
	var b strings.Builder
	for _, r := range s {
		switch {
		case r == '\r' || r == '\n' || r == '\t':
			b.WriteByte(' ')
		case r < 0x20 || r == 0x7f:
			// dropped
		case r < 0x80:
			b.WriteRune(r)
		case r == '\u2014' || r == '\u2013' || r == '\u2012' || r == '\u2015' || r == '\u2212':
			b.WriteByte('-')
		case r == '\u2018' || r == '\u2019' || r == '\u201a':
			b.WriteByte('\'')
		case r == '\u201c' || r == '\u201d' || r == '\u201e':
			b.WriteByte('"')
		case r == '\u2026':
			b.WriteString("...")
		case r == '\u00a0' || r == '\u2009' || r == '\u202f':
			b.WriteByte(' ')
		default:
			b.WriteByte('?')
		}
	}
	return b.String()
}

type readCloser struct {
	io.Reader
	close func() error
}

func (r readCloser) Close() error { return r.close() }

var errTooLargeAnswer = errors.New("answer too large")

func busyError(action string) error {
	return &UpstreamError{Kind: KindRateLimit, Action: action, RetryAfter: 10 * time.Second,
		Message: "The mail gateway is busy; try again shortly."}
}

// readReserved reads an answer of about expect bytes into one buffer. When
// the answer runs past it, the buffer grows by a quarter (at least 1 MiB) —
// enough to keep copies few, small enough to keep the reservation close to
// the answer — calling grow before each larger buffer and shrunk once the
// old one is copied. It stops at maxResponseBytes.
//
// The first buffer is expect+1 bytes (so an answer of exactly the announced
// length ends without growing), and the caller has reserved for it.
func readReserved(r io.Reader, expect int64, grow func(oldCap, newCap int64) error, shrunk func(oldCap int64)) ([]byte, error) {
	buf := make([]byte, 0, expect+1)
	for {
		if len(buf) == cap(buf) {
			if int64(len(buf)) > maxResponseBytes {
				return nil, errTooLargeAnswer
			}
			oldCap := int64(cap(buf))
			step := oldCap / 4
			if step < 1<<20 {
				step = 1 << 20
			}
			if err := grow(oldCap, oldCap+step); err != nil {
				return nil, err
			}
			nb := make([]byte, len(buf), oldCap+step)
			copy(nb, buf)
			buf = nb
			shrunk(oldCap)
		}
		n, err := r.Read(buf[len(buf):cap(buf)])
		buf = buf[:len(buf)+n]
		if err == io.EOF {
			if int64(len(buf)) > maxResponseBytes {
				return nil, errTooLargeAnswer
			}
			return buf, nil
		}
		if err != nil {
			return nil, err
		}
	}
}
