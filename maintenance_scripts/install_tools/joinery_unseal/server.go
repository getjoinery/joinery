package main

// The socket: one goroutine per connection, a connection cap, a deadline on
// every frame, a budget on the bytes in flight, and the peer's uid checked
// against the one pool user the daemon serves. Any panic while serving a
// connection is caught, logged with the peer's pid, and ends that connection
// only: nothing that arrives on the socket ends the process.

import (
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"sync/atomic"
	"syscall"
	"time"
)

type serverConfig struct {
	socket        string
	allowUID      int
	maxConns      int
	maxFrameBytes int64
	memoryBudget  int64
	idleTimeout   time.Duration // between frames on a reused connection
	frameTimeout  time.Duration // to read one frame once it has begun, and to write its reply
}

type server struct {
	cfg      serverConfig
	d        *daemon
	slots    chan struct{}
	inflight atomic.Int64
	lastPeer atomic.Int64
}

func newServer(cfg serverConfig, d *daemon) *server {
	return &server{cfg: cfg, d: d, slots: make(chan struct{}, cfg.maxConns)}
}

func (s *server) reserve(n int64) bool {
	if s.inflight.Add(n) > s.cfg.memoryBudget {
		s.inflight.Add(-n)
		return false
	}
	return true
}

func (s *server) release(n int64) {
	s.inflight.Add(-n)
}

// ownedSocket is the socket path this process made, and the lock that says
// so. Two daemons on one path would both run and the first to exit would
// unlink the other's socket, leaving a healthy daemon nobody can reach (and
// every unlock on the pool path). So a daemon takes an exclusive lock on
// <socket>.lock for its whole life and refuses to start without it, refuses
// a socket that answers, and at exit removes the socket only if it is still
// the one it made.
type ownedSocket struct {
	path string
	ino  uint64
	lock *os.File
}

func inodeOf(path string) (uint64, bool) {
	fi, err := os.Lstat(path)
	if err != nil {
		return 0, false
	}
	st, ok := fi.Sys().(*syscall.Stat_t)
	if !ok {
		return 0, false
	}
	return st.Ino, true
}

// release removes the socket if it is still this process's, and drops the lock.
func (o *ownedSocket) release() {
	if ino, ok := inodeOf(o.path); ok && ino == o.ino {
		os.Remove(o.path)
	}
	o.lock.Close()
}

// listen makes the socket at path, mode 0660 from birth (umask), after
// removing a stale socket left by a crash. Anything at that path that is not
// a socket is refused rather than removed, and so is a socket another daemon
// still answers on.
func listen(path string) (*net.UnixListener, *ownedSocket, error) {
	lock, err := os.OpenFile(path+".lock", os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return nil, nil, err
	}
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		lock.Close()
		return nil, nil, fmt.Errorf("another joinery-unseal holds %s.lock", path)
	}
	fail := func(err error) (*net.UnixListener, *ownedSocket, error) {
		lock.Close()
		return nil, nil, err
	}
	if fi, err := os.Lstat(path); err == nil {
		if fi.Mode()&os.ModeSocket == 0 {
			return fail(fmt.Errorf("%s exists and is not a socket", path))
		}
		if c, err := net.DialTimeout("unix", path, 500*time.Millisecond); err == nil {
			c.Close()
			return fail(fmt.Errorf("%s is answering: another daemon serves it", path))
		}
		if err := os.Remove(path); err != nil {
			return fail(err)
		}
	}
	old := syscall.Umask(0o117)
	l, err := net.ListenUnix("unix", &net.UnixAddr{Name: path, Net: "unix"})
	syscall.Umask(old)
	if err != nil {
		return fail(err)
	}
	l.SetUnlinkOnClose(false) // release() unlinks, and only its own socket
	ino, ok := inodeOf(path)
	if !ok {
		l.Close()
		return fail(fmt.Errorf("cannot stat %s", path))
	}
	own := &ownedSocket{path: path, ino: ino, lock: lock}
	if err := os.Chmod(path, 0o660); err != nil {
		l.Close()
		own.release()
		return nil, nil, err
	}
	return l, own, nil
}

func (s *server) serve(l *net.UnixListener) error {
	for {
		c, err := l.AcceptUnix()
		if err != nil {
			if errors.Is(err, net.ErrClosed) {
				return nil
			}
			var ne net.Error
			if errors.As(err, &ne) && ne.Timeout() {
				continue
			}
			log.Printf("accept: %v", err)
			time.Sleep(100 * time.Millisecond)
			continue
		}
		select {
		case s.slots <- struct{}{}:
			go func() {
				defer func() { <-s.slots }()
				s.serveConn(c)
			}()
		default:
			c.SetWriteDeadline(time.Now().Add(time.Second))
			writeFrame(c, errorReply(errBusy), nil)
			c.Close()
		}
	}
}

func peerCred(c *net.UnixConn) (*syscall.Ucred, error) {
	raw, err := c.SyscallConn()
	if err != nil {
		return nil, err
	}
	var cred *syscall.Ucred
	var cerr error
	if err := raw.Control(func(fd uintptr) {
		cred, cerr = syscall.GetsockoptUcred(int(fd), syscall.SOL_SOCKET, syscall.SO_PEERCRED)
	}); err != nil {
		return nil, err
	}
	return cred, cerr
}

func (s *server) serveConn(c *net.UnixConn) {
	defer c.Close()
	cred, err := peerCred(c)
	if err != nil || int(cred.Uid) != s.cfg.allowUID {
		uid := -1
		if cred != nil {
			uid = int(cred.Uid)
		}
		log.Printf("refused a connection from uid %d (only uid %d is served)", uid, s.cfg.allowUID)
		return
	}
	s.lastPeer.Store(int64(cred.Pid))
	defer func() {
		if r := recover(); r != nil {
			log.Printf("recovered while serving pid %d: %v", cred.Pid, r)
		}
	}()
	s.serveStream(&deadlineConn{c: c, s: s}, c)
}

// deadlineConn sets the idle deadline before a frame begins and the frame
// deadline once its first byte has arrived.
type deadlineConn struct {
	c       *net.UnixConn
	s       *server
	inFrame bool
}

func (d *deadlineConn) Read(p []byte) (int, error) {
	if !d.inFrame {
		d.c.SetReadDeadline(time.Now().Add(d.s.cfg.idleTimeout))
	}
	n, err := d.c.Read(p)
	if n > 0 && !d.inFrame {
		d.inFrame = true
		d.c.SetReadDeadline(time.Now().Add(d.s.cfg.frameTimeout))
	}
	return n, err
}

// serveStream answers frames until the stream ends or falls out of frame. It
// does not recover: the fuzz target runs it bare, so a panic is a failure
// there rather than a caught log line.
func (s *server) serveStream(r io.Reader, w io.Writer) {
	dc, _ := r.(*deadlineConn)
	for {
		if dc != nil {
			dc.inFrame = false
		}
		hdr, body, ferr, err := readFrame(r, s.cfg.maxFrameBytes, s.reserve, s.release)
		if err != nil {
			if ferr != nil {
				s.reply(w, errorReply(ferr), nil)
			}
			return
		}
		if !s.frame(w, hdr, body) {
			return
		}
	}
}

// frame answers one frame. The body's reservation is handed back however it
// ends, a panic included, so a fault never shrinks the budget for good.
func (s *server) frame(w io.Writer, hdr, body []byte) bool {
	defer func() {
		zero(body)
		s.release(int64(len(body)))
	}()
	fields, parts := s.answer(hdr, body)
	return s.reply(w, fields, parts)
}

func (s *server) answer(hdr, body []byte) (map[string]any, [][]byte) {
	req, parts, oerr := parseRequest(hdr, body)
	if oerr != nil {
		return errorReply(oerr), nil
	}
	fields, out, oerr := s.d.handle(req, parts)
	if oerr != nil {
		return errorReply(oerr), nil
	}
	return fields, out
}

func (s *server) reply(w io.Writer, fields map[string]any, parts [][]byte) bool {
	if c, ok := w.(*net.UnixConn); ok {
		c.SetWriteDeadline(time.Now().Add(s.cfg.frameTimeout))
	}
	err := writeFrame(w, fields, parts)
	for _, p := range parts {
		zero(p)
	}
	return err == nil
}
