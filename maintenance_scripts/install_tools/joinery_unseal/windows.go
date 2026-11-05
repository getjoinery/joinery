package main

// The windows the daemon holds. A window is a vault secret (an X-Wing seed)
// plus the two numbers that bound it, tagged with the user id and scope PHP
// gave at open, addressed by a random 32-byte handle minted here. A child
// window holds a retired key opened through its parent (open_previous): it
// has no clocks of its own, it lives exactly as long as its parent, and an
// unwrap through it counts as activity on the parent.
//
// Both clocks run on the monotonic clock (time.Time carries a monotonic
// reading, and Sub uses it), so a wall-clock jump neither ends nor extends a
// window. A window past either clock is zeroed the moment anything looks at
// it and by the sweeper every second, whether or not PHP ever asks again.

import (
	"crypto/rand"
	"encoding/hex"
	"io"
	"sync"
	"time"
)

type window struct {
	handle   string
	user     int64
	scope    string
	seed     []byte
	public   []byte
	opened   time.Time
	last     time.Time
	idle     time.Duration
	absolute time.Duration
	unwraps  uint64
	parent   *window
	children map[string]*window // by handle
}

type store struct {
	mu          sync.Mutex
	windows     map[string]*window // every live window, children included, by handle
	maxWindows  int
	maxAbsolute time.Duration
	held        bool
	now         func() time.Time
}

func newStore(maxWindows int, maxAbsolute time.Duration) *store {
	return &store{
		windows:     make(map[string]*window),
		maxWindows:  maxWindows,
		maxAbsolute: maxAbsolute,
		now:         time.Now,
	}
}

func newHandle() (string, error) {
	b := make([]byte, 32)
	if _, err := io.ReadFull(rand.Reader, b); err != nil {
		return "", err
	}
	return hex.EncodeToString(b), nil
}

func (w *window) root() *window {
	if w.parent != nil {
		return w.parent
	}
	return w
}

func (s *store) expiredLocked(w *window, now time.Time) bool {
	r := w.root()
	return now.Sub(r.opened) >= r.absolute || now.Sub(r.last) >= r.idle
}

// dropLocked zeroes a window and every child of it and forgets them all.
// Returns how many windows ended and the unwraps they had counted.
func (s *store) dropLocked(w *window) (int, uint64) {
	n, count := 0, uint64(0)
	for _, c := range w.children {
		cn, cc := s.dropLocked(c)
		n += cn
		count += cc
	}
	w.children = nil
	if w.parent != nil {
		delete(w.parent.children, w.handle)
	}
	zero(w.seed)
	w.seed = nil
	delete(s.windows, w.handle)
	return n + 1, count + w.unwraps
}

// add holds a new top-level window. The seed is the store's from here on,
// whatever the answer: a refusal zeroes it.
func (s *store) add(user int64, scope string, seed, public []byte, idle, absolute time.Duration) (h string, oerr *opError) {
	s.mu.Lock()
	defer s.mu.Unlock()
	defer func() {
		if oerr != nil {
			zero(seed)
		}
	}()
	if s.held {
		return "", errHeld
	}
	if len(s.windows) >= s.maxWindows {
		return "", errAtCapacity
	}
	h, err := newHandle()
	if err != nil {
		return "", errInternal
	}
	now := s.now()
	s.windows[h] = &window{handle: h, user: user, scope: scope, seed: seed, public: public,
		opened: now, last: now, idle: idle, absolute: absolute}
	return h, nil
}

// addChild holds a retired key under a live top-level window. A parent that
// already holds a child for the same key hands that child back, so a page
// view that opens the chain does not grow the parent by one per view. The
// seed is the store's from here on: a refusal or a reuse zeroes it.
func (s *store) addChild(parent string, seed, public []byte) (h string, oerr *opError) {
	s.mu.Lock()
	defer s.mu.Unlock()
	kept := false
	defer func() {
		if !kept {
			zero(seed)
		}
	}()
	if s.held {
		return "", errHeld
	}
	p, err := s.liveLocked(parent)
	if err != nil {
		return "", err
	}
	if p.parent != nil {
		return "", errBadRequest
	}
	for h, c := range p.children {
		if string(c.public) == string(public) {
			return h, nil
		}
	}
	if len(s.windows) >= s.maxWindows {
		return "", errAtCapacity
	}
	h, herr := newHandle()
	if herr != nil {
		return "", errInternal
	}
	kept = true
	c := &window{handle: h, user: p.user, scope: p.scope, seed: seed, public: public, parent: p}
	if p.children == nil {
		p.children = make(map[string]*window)
	}
	p.children[h] = c
	s.windows[h] = c
	return h, nil
}

// liveLocked finds a window by handle, ending it (and answering unknown) if a
// clock has run out.
func (s *store) liveLocked(handle string) (*window, *opError) {
	w, ok := s.windows[handle]
	if !ok {
		return nil, errUnknownHandle
	}
	if s.expiredLocked(w, s.now()) {
		s.dropLocked(w.root())
		return nil, errUnknownHandle
	}
	return w, nil
}

// use hands out a copy of a live window's seed for one request (the caller
// zeroes it). It counts nothing and extends nothing: touch() does that once
// the request has opened what it came for.
func (s *store) use(handle string) (seed []byte, isChild bool, oerr *opError) {
	s.mu.Lock()
	defer s.mu.Unlock()
	w, err := s.liveLocked(handle)
	if err != nil {
		return nil, false, err
	}
	out := make([]byte, len(w.seed))
	copy(out, w.seed)
	return out, w.parent != nil, nil
}

// touch records unwraps that succeeded, and refreshes the idle clock when they
// were a content decrypt. Only an open that worked extends a window (the
// spec's one idle clock); a child's activity is its parent's.
func (s *store) touch(handle string, unwraps int, activity bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	w, ok := s.windows[handle]
	if !ok {
		return
	}
	w.unwraps += uint64(unwraps)
	if activity {
		w.root().last = s.now()
	}
}

func (s *store) closeHandle(handle string) (int, uint64) {
	s.mu.Lock()
	defer s.mu.Unlock()
	w, ok := s.windows[handle]
	if !ok {
		return 0, 0
	}
	return s.dropLocked(w)
}

func (s *store) closeUser(user int64) (int, uint64) {
	s.mu.Lock()
	defer s.mu.Unlock()
	n, count := 0, uint64(0)
	for _, w := range s.windows {
		if w.parent == nil && w.user == user {
			wn, wc := s.dropLocked(w)
			n += wn
			count += wc
		}
	}
	return n, count
}

// hasUser answers whether a user holds any live window, never which.
func (s *store) hasUser(user int64) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := s.now()
	for _, w := range s.windows {
		if w.parent == nil && w.user == user && !s.expiredLocked(w, now) {
			return true
		}
	}
	return false
}

// count is the number of live top-level windows.
func (s *store) count() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	n := 0
	for _, w := range s.windows {
		if w.parent == nil {
			n++
		}
	}
	return n
}

// sweep zeroes every window past a clock.
func (s *store) sweep() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := s.now()
	n := 0
	for _, w := range s.windows {
		if w.parent == nil && s.expiredLocked(w, now) {
			wn, _ := s.dropLocked(w)
			n += wn
		}
	}
	return n
}

// dropAndHold is the sentinel's SIGUSR1: every key zeroed, every handle
// ended, and every open refused until the hold is lifted.
func (s *store) dropAndHold() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.held = true
	n := 0
	for _, w := range s.windows {
		if w.parent == nil {
			wn, _ := s.dropLocked(w)
			n += wn
		}
	}
	return n
}

func (s *store) setHeld(held bool) {
	s.mu.Lock()
	s.held = held
	s.mu.Unlock()
}

func (s *store) isHeld() bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.held
}
