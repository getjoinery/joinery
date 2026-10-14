package main

// The submission face greets each program with the name it connected to.
// go-smtp writes its 220 line from Server.Domain, one name per server, and a
// machine serving several sites answers to several names; so the front
// finishes the TLS handshake itself, reads the name the program asked for
// (SNI), and hands the connection to a server for that name, made the first
// time the name is seen. Only names an enabled site serves get a server.

import (
	"net"
	"strings"
	"sync"

	"github.com/emersion/go-smtp"
)

type SMTPFront struct {
	g     *Gateway
	fixed string // -smtp-domain: this one name for every connection

	mu     sync.Mutex
	outer  net.Listener
	byName map[string]*namedSMTP
	closed bool
}

type namedSMTP struct {
	srv *smtp.Server
	ln  *chanListener
}

// NewSMTPFront builds the submission face. fixed, when not empty, is the
// greeting name for every connection.
func (g *Gateway) NewSMTPFront(fixed string) *SMTPFront {
	return &SMTPFront{g: g, fixed: fixed, byName: map[string]*namedSMTP{}}
}

// Serve accepts on ln (the gateway's TLS listener) until it is closed.
func (f *SMTPFront) Serve(ln net.Listener) error {
	f.mu.Lock()
	f.outer = ln
	f.mu.Unlock()
	for {
		c, err := ln.Accept()
		if err != nil {
			f.mu.Lock()
			closed := f.closed
			f.mu.Unlock()
			if closed {
				return nil
			}
			return err
		}
		go f.dispatch(c)
	}
}

func (f *SMTPFront) dispatch(c net.Conn) {
	tc, err := handshake(c)
	if err != nil {
		logf("smtp: %s: %v", remoteIP(c.RemoteAddr()), err)
		c.Close()
		return
	}
	n := f.serverFor(f.greetingName(tc.ConnectionState().ServerName))
	if n == nil || !n.ln.deliver(tc) {
		tc.Close()
	}
}

// greetingName is the name in the 220 line: the name the program asked for
// when an enabled site serves it, else what a single-name gateway would say
// (the session then refuses the connection).
func (f *SMTPFront) greetingName(sni string) string {
	if f.fixed != "" {
		return f.fixed
	}
	if site := f.g.reg.Lookup(sni); site != nil {
		if name := strings.ToLower(strings.TrimSuffix(sni, ".")); name != "" {
			return name
		}
		return site.ServedName()
	}
	return f.g.reg.DefaultDomain()
}

func (f *SMTPFront) serverFor(name string) *namedSMTP {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.closed {
		return nil
	}
	if n, ok := f.byName[name]; ok {
		return n
	}
	addr := net.Addr(&net.TCPAddr{})
	if f.outer != nil {
		addr = f.outer.Addr()
	}
	n := &namedSMTP{srv: f.g.NewSMTPServer(name), ln: newChanListener(addr)}
	f.byName[name] = n
	go n.srv.Serve(n.ln)
	return n
}

// Close stops accepting and closes every server and its connections.
func (f *SMTPFront) Close() {
	f.mu.Lock()
	f.closed = true
	outer := f.outer
	servers := make([]*smtp.Server, 0, len(f.byName))
	for _, n := range f.byName {
		servers = append(servers, n.srv)
	}
	f.mu.Unlock()
	if outer != nil {
		outer.Close()
	}
	for _, s := range servers {
		s.Close()
	}
}

// chanListener is a net.Listener fed by hand: the front delivers each
// connection to the server for its name.
type chanListener struct {
	addr  net.Addr
	conns chan net.Conn
	done  chan struct{}
	once  sync.Once
}

func newChanListener(addr net.Addr) *chanListener {
	return &chanListener{addr: addr, conns: make(chan net.Conn), done: make(chan struct{})}
}

func (l *chanListener) Accept() (net.Conn, error) {
	select {
	case c := <-l.conns:
		return c, nil
	case <-l.done:
		return nil, net.ErrClosed
	}
}

func (l *chanListener) deliver(c net.Conn) bool {
	select {
	case l.conns <- c:
		return true
	case <-l.done:
		return false
	}
}

func (l *chanListener) Close() error {
	l.once.Do(func() { close(l.done) })
	return nil
}

func (l *chanListener) Addr() net.Addr { return l.addr }
