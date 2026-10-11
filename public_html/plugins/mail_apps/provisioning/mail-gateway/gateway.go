package main

import (
	"log"
	"net"
	"sync"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
	"github.com/emersion/go-smtp"
)

// Gateway is what the IMAP and SMTP faces share: the sites, the limits, and
// the shared poll cache.
type Gateway struct {
	reg       *Registry
	limiter   *ConnLimiter
	credLimit *KeyedLimiter
	throttle  *AuthThrottle
	cache     *SharedCache

	mu       sync.Mutex
	nextID   int64
	sessions map[string]map[int64]func() // site name -> open connections' closers
}

// NewGateway: perCredential bounds the connections signed in with one
// credential (counted at sign-in), so one app password cannot take a whole
// address's allowance.
func NewGateway(reg *Registry, limiter *ConnLimiter, perCredential int) *Gateway {
	return &Gateway{reg: reg, limiter: limiter, credLimit: NewKeyedLimiter(perCredential),
		throttle: NewAuthThrottle(), cache: NewSharedCache(), sessions: map[string]map[int64]func(){}}
}

// track registers an open connection of a site; the answer unregisters it.
func (g *Gateway) track(site string, closer func()) func() {
	g.mu.Lock()
	defer g.mu.Unlock()
	g.nextID++
	id := g.nextID
	if g.sessions[site] == nil {
		g.sessions[site] = map[int64]func(){}
	}
	g.sessions[site][id] = closer
	return func() {
		g.mu.Lock()
		defer g.mu.Unlock()
		delete(g.sessions[site], id)
		if len(g.sessions[site]) == 0 {
			delete(g.sessions, site)
		}
	}
}

// Reload re-reads the sites (SIGHUP). A site that is no longer enabled stops
// being served at once: its name fails the TLS handshake from now on, and its
// open connections are closed. Every other site's connections carry on.
func (g *Gateway) Reload() error {
	if err := g.reg.Reload(); err != nil {
		return err
	}
	g.mu.Lock()
	var closers []func()
	for site, conns := range g.sessions {
		if g.reg.ByName(site) != nil {
			continue
		}
		logf("config: %s is no longer enabled; closing its %d connection(s)", site, len(conns))
		for _, c := range conns {
			closers = append(closers, c)
		}
	}
	g.mu.Unlock()
	for _, c := range closers {
		go c()
	}
	return nil
}

// TLSListener wraps a raw listener: limits, then server-side TLS with the
// certificate chosen by SNI.
func (g *Gateway) TLSListener(ln net.Listener, proto string) net.Listener {
	return &tlsListener{Listener: ln, cfg: g.reg.TLSConfig(), limiter: g.limiter, proto: proto}
}

// imapCaps is what the gateway advertises. go-imap v2 adds SASL-IR,
// LITERAL-, UNSELECT, ENABLE, IDLE and UTF8=ACCEPT by itself; AUTH=PLAIN comes
// from the session. CONDSTORE and ID are not here because go-imap v2.0.0-beta.8's
// server cannot parse or answer them (README § go-imap gaps).
var imapCaps = imap.CapSet{
	imap.CapIMAP4rev1:   {},
	imap.CapNamespace:   {},
	imap.CapUIDPlus:     {},
	imap.CapMove:        {},
	imap.CapSpecialUse:  {},
	imap.CapLiteralPlus: {},
	imap.CapChildren:    {},
}

// NewIMAPServer builds the IMAP face.
func (g *Gateway) NewIMAPServer() *imapserver.Server {
	return imapserver.New(&imapserver.Options{
		NewSession: g.newIMAPSession,
		Caps:       imapCaps,
		Logger:     log.New(log.Writer(), "imap: ", log.LstdFlags|log.LUTC),
		// Every listener is implicit TLS; this only matters if a connection
		// ever arrived some other way, and none can.
		InsecureAuth: false,
	})
}

// NewSMTPServer builds the submission face.
func (g *Gateway) NewSMTPServer(domain string) *smtp.Server {
	s := smtp.NewServer(smtpBackend{g: g})
	s.Domain = domain
	s.MaxMessageBytes = maxMessageBytes
	s.MaxRecipients = 500
	s.MaxLineLength = 2000
	s.ReadTimeout = 5 * time.Minute
	s.WriteTimeout = 2 * time.Minute
	s.AllowInsecureAuth = false
	s.ErrorLog = log.New(log.Writer(), "smtp: ", log.LstdFlags|log.LUTC)
	return s
}

// maxMessageBytes bounds a submitted or appended message. It travels to the
// site as base64 inside JSON, and PHP's post_max_size (32M) caps that body:
// 23,000,000 bytes is about 30.7 MB as base64, under the 31 MB the site can
// take. Anything bigger is refused here, before it is read or sent.
const maxMessageBytes = 23000000

// tooLargeText is what a program is told for a message over the limit.
const tooLargeText = "Message too large for this mailbox (limit 23 MB)"
