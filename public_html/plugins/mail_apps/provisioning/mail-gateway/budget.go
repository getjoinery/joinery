package main

import (
	"container/list"
	"context"
	"errors"
	"io"
	"sync"
	"time"
	"unsafe"
)

// Memory is bounded gateway-wide, not per connection: one member with a valid
// app password can open many connections, and the gateway serves every site
// on the host. Two budgets, both flags:
//
//   - inflight bounds the bytes of upstream answers being read, decoded and
//     written out (and of messages a program is submitting or appending). A
//     call reserves before it reads; when the budget is spent it waits, and
//     past inflightWait answers "busy, try again" (IMAP NO [LIMIT], SMTP 451).
//   - bodyStore bounds the bytes of whole messages kept for 60 seconds so a
//     program reading one message in pieces does not make the site rebuild it
//     per piece. Oldest goes first, across all connections; a message larger
//     than one connection's share is simply not kept.

var (
	inflight     = NewBudget(144 << 20)
	bodies       = newBodyStore(48 << 20)
	inflightWait = 20 * time.Second
	bodyTTL      = 60 * time.Second
)

// errBusy is a reservation the budget could not make in time.
var errBusy = errors.New("the gateway is busy")

// Budget is a byte semaphore.
type Budget struct {
	mu   sync.Mutex
	cap  int64
	used int64
	wake chan struct{} // closed and replaced whenever bytes are released
}

func NewBudget(capacity int64) *Budget {
	return &Budget{cap: capacity, wake: make(chan struct{})}
}

// Acquire reserves n bytes, waiting until they are free or ctx ends. A request
// larger than the whole budget can never be met and fails at once.
func (b *Budget) Acquire(ctx context.Context, n int64) error {
	if n <= 0 {
		return nil
	}
	for {
		b.mu.Lock()
		if n > b.cap {
			b.mu.Unlock()
			return errBusy
		}
		if b.used+n <= b.cap {
			b.used += n
			b.mu.Unlock()
			return nil
		}
		wake := b.wake
		b.mu.Unlock()
		select {
		case <-wake:
		case <-ctx.Done():
			return errBusy
		}
	}
}

func (b *Budget) Release(n int64) {
	if n <= 0 {
		return
	}
	b.mu.Lock()
	b.used -= n
	if b.used < 0 {
		b.used = 0
	}
	close(b.wake)
	b.wake = make(chan struct{})
	b.mu.Unlock()
}

// Cap is the budget's size.
func (b *Budget) Cap() int64 {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.cap
}

// Used is the number of bytes reserved now.
func (b *Budget) Used() int64 {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.used
}

// reserve is Acquire with the standard wait.
func reserve(n int64) (release func(), err error) {
	ctx, cancel := context.WithTimeout(context.Background(), inflightWait)
	defer cancel()
	if err := inflight.Acquire(ctx, n); err != nil {
		return nil, err
	}
	var once sync.Once
	return func() { once.Do(func() { inflight.Release(n) }) }, nil
}

// ── whole messages held briefly ─────────────────────────────────────────

type bodyItem struct {
	owner *bodyCache
	uid   uint32
	bytes []byte
	at    time.Time
}

// bodyStore is the one store every connection's bodyCache draws on.
type bodyStore struct {
	mu    sync.Mutex
	cap   int64
	total int64
	lru   *list.List // of *bodyItem, oldest first
}

func newBodyStore(capacity int64) *bodyStore {
	s := &bodyStore{cap: capacity, lru: list.New()}
	go s.janitor()
	return s
}

// share is the most one connection may keep: a quarter of the store, but at
// least 32 MiB (when the store has it), so the largest message — the one most
// worth keeping while a program reads it in pieces — still fits.
func (s *bodyStore) share() int64 {
	share := s.cap / 4
	if share < 32<<20 {
		share = 32 << 20
	}
	if share > s.cap {
		share = s.cap
	}
	return share
}

func (s *bodyStore) setCap(capacity int64) {
	s.mu.Lock()
	s.cap = capacity
	s.evictLocked()
	s.mu.Unlock()
}

func (s *bodyStore) janitor() {
	for {
		time.Sleep(5 * time.Second)
		s.mu.Lock()
		for e := s.lru.Front(); e != nil; {
			next := e.Next()
			if it := e.Value.(*bodyItem); time.Since(it.at) > bodyTTL {
				s.removeLocked(e)
			}
			e = next
		}
		s.mu.Unlock()
	}
}

func (s *bodyStore) removeLocked(e *list.Element) {
	it := e.Value.(*bodyItem)
	s.lru.Remove(e)
	s.total -= int64(len(it.bytes))
	it.owner.total -= int64(len(it.bytes))
	delete(it.owner.items, it.uid)
}

func (s *bodyStore) evictLocked() {
	for s.total > s.cap && s.lru.Len() > 0 {
		s.removeLocked(s.lru.Front())
	}
}

// Total is the number of bytes kept now.
func (s *bodyStore) Total() int64 {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.total
}

// bodyCache is one connection's view of the store.
type bodyCache struct {
	store *bodyStore
	items map[uint32]*list.Element
	total int64 // guarded by store.mu
}

func newBodyCache() *bodyCache {
	return &bodyCache{store: bodies, items: map[uint32]*list.Element{}}
}

func (c *bodyCache) get(uid uint32) []byte {
	s := c.store
	s.mu.Lock()
	defer s.mu.Unlock()
	e, ok := c.items[uid]
	if !ok {
		return nil
	}
	it := e.Value.(*bodyItem)
	if time.Since(it.at) > bodyTTL {
		s.removeLocked(e)
		return nil
	}
	return it.bytes
}

func (c *bodyCache) put(uid uint32, b []byte) {
	s := c.store
	s.mu.Lock()
	defer s.mu.Unlock()
	if e, ok := c.items[uid]; ok {
		s.removeLocked(e)
	}
	n := int64(len(b))
	if n > s.share() {
		return // too big to keep; it is served from the answer in hand
	}
	// This connection's own oldest go first when it is over its share.
	for c.total+n > s.share() {
		var oldest *list.Element
		for _, e := range c.items {
			if oldest == nil || e.Value.(*bodyItem).at.Before(oldest.Value.(*bodyItem).at) {
				oldest = e
			}
		}
		if oldest == nil {
			break
		}
		s.removeLocked(oldest)
	}
	it := &bodyItem{owner: c, uid: uid, bytes: b, at: time.Now()}
	c.items[uid] = s.lru.PushBack(it)
	c.total += n
	s.total += n
	s.evictLocked()
}

func (c *bodyCache) drop(uid uint32) {
	s := c.store
	s.mu.Lock()
	defer s.mu.Unlock()
	if e, ok := c.items[uid]; ok {
		s.removeLocked(e)
	}
}

func (c *bodyCache) clear() {
	s := c.store
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, e := range c.items {
		s.removeLocked(e)
	}
}

// ── connections per credential ──────────────────────────────────────────

// KeyedLimiter counts holders per key (a credential's hash — never the
// secret itself), and gives each key its own held-bytes budget, shared by
// its holders and dropped with the last of them.
type KeyedLimiter struct {
	mu    sync.Mutex
	max   int
	count map[string]*keyEntry
}

type keyEntry struct {
	n    int
	held *Budget
}

func NewKeyedLimiter(max int) *KeyedLimiter {
	return &KeyedLimiter{max: max, count: map[string]*keyEntry{}}
}

// Acquire admits one more holder of key, answering the key's budget; nil
// when the key is at its limit.
func (l *KeyedLimiter) Acquire(key string) *Budget {
	l.mu.Lock()
	defer l.mu.Unlock()
	e := l.count[key]
	if e == nil {
		e = &keyEntry{held: NewBudget(credentialHeld)}
		l.count[key] = e
	}
	if l.max > 0 && e.n >= l.max {
		if e.n == 0 {
			delete(l.count, key)
		}
		return nil
	}
	e.n++
	return e.held
}

func (l *KeyedLimiter) Release(key string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	if e := l.count[key]; e != nil {
		if e.n--; e.n <= 0 {
			delete(l.count, key)
		}
	}
}

// ── open folders' message lists ─────────────────────────────────────────

// folderLists bounds the message lists every connection holds for the folder
// it has open (32 bytes a message). A list is reserved at SELECT/EXAMINE for
// its capacity, grown with new mail, and given back on UNSELECT, a new
// SELECT, or close.
var (
	folderLists = NewBudget(96 << 20)
	folderWait  = 5 * time.Second
)

const messageBytes = int64(unsafe.Sizeof(message{}))

// reserveFor makes room for n messages: when the list's capacity is short,
// it reserves the larger capacity first, then reallocates. errBusy when the
// budget cannot be had in time.
func (sel *selected) reserveFor(n int) error {
	if n <= cap(sel.msgs) {
		return nil
	}
	newCap := n
	if len(sel.msgs) > 0 {
		newCap = n + n/8 + 4 // room for some new mail without regrowing
	}
	need := int64(newCap)*messageBytes - sel.listReserved
	if sel.listBudget == nil {
		sel.listBudget = folderLists
	}
	ctx, cancel := context.WithTimeout(context.Background(), folderWait)
	defer cancel()
	if err := sel.listBudget.Acquire(ctx, need); err != nil {
		return err
	}
	sel.listReserved += need
	grown := make([]message, len(sel.msgs), newCap)
	copy(grown, sel.msgs)
	sel.msgs = grown
	return nil
}

// releaseList gives the list's reservation back.
func (sel *selected) releaseList() {
	if sel == nil {
		return
	}
	if sel.listBudget != nil {
		sel.listBudget.Release(sel.listReserved)
	}
	sel.listReserved = 0
	sel.msgs = nil
}

// TryAcquire reserves n bytes only if they are free now.
func (b *Budget) TryAcquire(n int64) bool {
	if n <= 0 {
		return true
	}
	b.mu.Lock()
	defer b.mu.Unlock()
	if b.used+n > b.cap {
		return false
	}
	b.used += n
	return true
}

// ── bytes held while they are written out ───────────────────────────────

// Held is a reservation kept after a call returns: whole messages (or header
// blocks) waiting to be written to a program, counted in the in-flight
// budget and, for a FETCH, in the credential's own budget too. It is given
// back message by message as each is written, so a program that reads slowly
// keeps only what it has not read yet. All methods are safe on nil.
//
// In the credential's budget a round counts what it has not written yet,
// up to the whole of that budget (one round may be larger — a single message
// can be — and must still be able to go out).
type Held struct {
	mu        sync.Mutex
	global    int64   // bytes held in inflight
	remaining int64   // bytes of the round not yet written
	cred      int64   // bytes held in credB: min(remaining, its capacity)
	credB     *Budget // the credential's budget, when one is held
}

// holdCredential moves the round's credential reservation (already made,
// cred bytes) under h, for a round of payload bytes.
func (h *Held) holdCredential(b *Budget, cred, payload int64) {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.credB, h.cred, h.remaining = b, cred, payload
}

// Release gives back n bytes (as a message has been written).
func (h *Held) Release(n int64) {
	if h == nil || n <= 0 {
		return
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	g := min64(n, h.global)
	inflight.Release(g)
	h.global -= g
	if h.credB != nil {
		h.remaining -= n
		if h.remaining < 0 {
			h.remaining = 0
		}
		if keep := min64(h.remaining, h.cred); keep < h.cred {
			h.credB.Release(h.cred - keep)
			h.cred = keep
		}
	}
}

// TrimTo gives back the in-flight bytes above n.
func (h *Held) TrimTo(n int64) {
	if h == nil {
		return
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	if over := h.global - n; over > 0 {
		inflight.Release(over)
		h.global -= over
	}
}

// Done gives back whatever is left.
func (h *Held) Done() {
	if h == nil {
		return
	}
	h.mu.Lock()
	defer h.mu.Unlock()
	inflight.Release(h.global)
	h.global = 0
	if h.credB != nil {
		h.credB.Release(h.cred)
		h.cred, h.remaining = 0, 0
	}
}

func min64(a, b int64) int64 {
	if a < b {
		return a
	}
	return b
}

// credentialHeld is what one credential's connections may hold at once in
// messages waiting to be written (-credential-held-bytes): a few connections
// that read slowly, all signed in with one app password, cannot pin the
// in-flight budget every site shares.
var credentialHeld int64 = 48 << 20

// acquireWithin reserves n from b, waiting up to inflightWait.
func acquireWithin(b *Budget, n int64) error {
	ctx, cancel := context.WithTimeout(context.Background(), inflightWait)
	defer cancel()
	return b.Acquire(ctx, n)
}

// readMessage reads a message a program is submitting, of unknown length, up
// to max bytes, reserving in-flight budget for the buffer as it grows. The
// caller runs release once the message has been sent on. err is errBusy when
// the budget could not be had, errMessageTooLarge past max.
func readMessage(r io.Reader, max int64) (data []byte, release func(), err error) {
	var held int64
	release = func() { inflight.Release(held); held = 0 }
	take := func(n int64) error {
		if err := acquireWithin(inflight, n); err != nil {
			return err
		}
		held += n
		return nil
	}
	size := int64(1 << 20)
	if err := take(size); err != nil {
		return nil, release, err
	}
	buf := make([]byte, 0, size)
	for {
		if len(buf) == cap(buf) {
			if int64(len(buf)) > max {
				return nil, release, errMessageTooLarge
			}
			next := int64(cap(buf)) * 2
			if next > max+1 {
				next = max + 1
			}
			if err := take(next); err != nil {
				return nil, release, err
			}
			nb := make([]byte, len(buf), next)
			copy(nb, buf)
			old := int64(cap(buf))
			buf = nb
			inflight.Release(old)
			held -= old
		}
		n, rerr := r.Read(buf[len(buf):cap(buf)])
		buf = buf[:len(buf)+n]
		if rerr == io.EOF {
			if int64(len(buf)) > max {
				return nil, release, errMessageTooLarge
			}
			return buf, release, nil
		}
		if rerr != nil {
			return nil, release, rerr
		}
	}
}

var errMessageTooLarge = errors.New("message too large")
