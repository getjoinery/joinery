package main

import (
	"context"
	"sort"
	"strings"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
)

// message is one numbered message as this connection's program knows it.
// Kept small — 32 bytes — because a connection holds one per message of the
// folder it has open, and a folder may hold 100,000 (§ Memory in README).
type message struct {
	modseq uint64
	date   int64 // INTERNALDATE, Unix seconds
	size   int64 // RFC822.SIZE; meaningful only with bitSizeKnown
	uid    uint32
	bits   uint16 // flag bits (flagBit), plus bitSizeKnown and bitGone
}

// The flags a message can carry here, one bit each — keptFlags, in order.
// A flag the site names that is not among them is not kept (none is).
const (
	bitSizeKnown uint16 = 1 << 14
	// bitGone: the site expunged it, but this connection may not say so yet
	// (EXPUNGE is not allowed during FETCH, STORE or SEARCH); it keeps its
	// sequence number until the next point where it may.
	bitGone  uint16 = 1 << 15
	flagMask uint16 = 1<<8 - 1
)

func flagBit(f imap.Flag) uint16 {
	for i, k := range keptFlags {
		if strings.EqualFold(string(f), string(k)) {
			return 1 << uint(i)
		}
	}
	return 0
}

func flagBits(names []string) uint16 {
	var b uint16
	for _, n := range names {
		b |= flagBit(imap.Flag(n))
	}
	return b
}

func newMessage(m apiMessage) message {
	msg := message{uid: m.UID, modseq: m.ModSeq, date: parseAPITime(m.Date).Unix(), bits: flagBits(m.Flags)}
	if m.Size != nil {
		msg.setSize(*m.Size)
	}
	return msg
}

func (m *message) flagList() []imap.Flag {
	out := make([]imap.Flag, 0, 4)
	for i, k := range keptFlags {
		if m.bits&(1<<uint(i)) != 0 {
			out = append(out, k)
		}
	}
	return out
}

func (m *message) has(f imap.Flag) bool { b := flagBit(f); return b != 0 && m.bits&b != 0 }

func (m *message) setFlags(names []string) (changed bool) {
	nb := flagBits(names)
	changed = m.bits&flagMask != nb
	m.bits = m.bits&^flagMask | nb
	return changed
}

func (m *message) sizeKnown() bool { return m.bits&bitSizeKnown != 0 }

func (m *message) setSize(n int64) {
	if n < 0 {
		return
	}
	m.size = n
	m.bits |= bitSizeKnown
}

func (m *message) gone() bool { return m.bits&bitGone != 0 }

func (m *message) setGone() { m.bits |= bitGone }

func (m *message) when() time.Time { return time.Unix(m.date, 0).UTC() }

// selected is the folder a connection has open: its message list in
// ascending UID order, which is also sequence-number order.
type selected struct {
	folder        apiFolder
	readOnly      bool
	uidValidity   uint32
	uidNext       uint32
	highestModSeq uint64
	msgs          []message
	listReserved  int64   // folder-list budget held for msgs' capacity
	listBudget    *Budget // the budget it is held in
	lastCheck     time.Time
	forcePoll     bool
}

// indexOfUID finds a message by UID, or -1.
func (sel *selected) indexOfUID(uid uint32) int {
	i := sort.Search(len(sel.msgs), func(i int) bool { return sel.msgs[i].uid >= uid })
	if i < len(sel.msgs) && sel.msgs[i].uid == uid {
		return i
	}
	return -1
}

func (sel *selected) maxUID() uint32 {
	if len(sel.msgs) == 0 {
		return 0
	}
	return sel.msgs[len(sel.msgs)-1].uid
}

// resolve turns a sequence or UID set into indices into msgs, ascending. "*"
// is the last message; a range may be written either way round.
func (sel *selected) resolve(numSet imap.NumSet) []int {
	var out []int
	seen := map[int]bool{}
	add := func(i int) {
		if !seen[i] {
			seen[i] = true
			out = append(out, i)
		}
	}
	switch set := numSet.(type) {
	case imap.SeqSet:
		n := uint32(len(sel.msgs))
		for _, r := range set {
			a, b := r.Start, r.Stop
			if a == 0 {
				a = n
			}
			if b == 0 {
				b = n
			}
			if a > b {
				a, b = b, a
			}
			if a < 1 {
				a = 1
			}
			for q := a; q <= b && q <= n; q++ {
				add(int(q - 1))
			}
		}
	case imap.UIDSet:
		max := sel.maxUID()
		for _, r := range set {
			a, b := uint32(r.Start), uint32(r.Stop)
			if a == 0 {
				a = max
			}
			if b == 0 {
				b = max
			}
			if a > b {
				a, b = b, a
			}
			i := sort.Search(len(sel.msgs), func(i int) bool { return sel.msgs[i].uid >= a })
			for ; i < len(sel.msgs) && sel.msgs[i].uid <= b; i++ {
				add(i)
			}
		}
	}
	sort.Ints(out)
	return out
}

// liveUIDs are the UIDs at these indices that the site still holds.
func (sel *selected) liveUIDs(idx []int) []uint32 {
	out := make([]uint32, 0, len(idx))
	for _, i := range idx {
		if !sel.msgs[i].gone() {
			out = append(out, sel.msgs[i].uid)
		}
	}
	return out
}

// removeAt drops the message at index i (after its EXPUNGE was written).
func (sel *selected) removeAt(i int) {
	copy(sel.msgs[i:], sel.msgs[i+1:])
	sel.msgs = sel.msgs[:len(sel.msgs)-1]
}

// expungeUIDs writes EXPUNGE for each of these UIDs this connection knows,
// highest sequence number first so the numbers stay right as it goes.
func (s *imapSession) expungeUIDs(uids []uint32, write func(seq uint32) error) error {
	sel := s.sel
	var idx []int
	for _, uid := range uids {
		if i := sel.indexOfUID(uid); i >= 0 {
			idx = append(idx, i)
		}
	}
	sort.Sort(sort.Reverse(sort.IntSlice(idx)))
	for _, i := range idx {
		s.bodies.drop(sel.msgs[i].uid)
		if err := write(uint32(i + 1)); err != nil {
			return err
		}
		sel.removeAt(i)
	}
	return nil
}

// ── finding out what changed ────────────────────────────────────────────

// Poll runs after most commands (go-imap calls it). It asks the site whether
// the selected folder changed — at most every few seconds per connection,
// and through the shared poll, so five connections of one program cost one
// call — and tells the program what did.
func (s *imapSession) Poll(w *imapserver.UpdateWriter, allowExpunge bool) error {
	sel := s.sel
	if sel == nil || !s.authed {
		return nil
	}
	force := sel.forcePoll
	// A poll already waiting on the site (one an IDLE just abandoned at DONE,
	// or another connection's) answers on its own time; this command's reply
	// does not wait for it — the next poll takes its result from the cache.
	pending := s.g.cache.Pending(changesKey(folderCacheKey(s.credKey, s.creds.Username, sel.folder.Key)))
	if pending || (!force && time.Since(sel.lastCheck) < noopInterval) {
		if allowExpunge {
			return s.flushGone(w)
		}
		return nil
	}
	return s.check(context.Background(), w, allowExpunge, !force)
}

// Idle checks every idleInterval until the program says DONE. A check in
// progress when DONE arrives is abandoned for this connection (the shared
// call carries on for any other that waits on it), so DONE is answered at
// once instead of after the site.
func (s *imapSession) Idle(w *imapserver.UpdateWriter, stop <-chan struct{}) error {
	if s.sel == nil {
		<-stop
		return nil
	}
	if err := s.flushGone(w); err != nil {
		return err
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go func() {
		select {
		case <-stop:
			cancel()
		case <-ctx.Done():
		}
	}()
	t := time.NewTicker(idleInterval)
	defer t.Stop()
	for {
		select {
		case <-stop:
			return nil
		case <-t.C:
			if err := s.check(ctx, w, true, true); err != nil {
				// A failed check is not the end of the IDLE: the program keeps
				// waiting, and the next tick tries again. A refused credential
				// has already closed the connection.
				if _, closed := err.(connClosedError); closed {
					return err
				}
				logf("imap: idle check failed site=%s user=%s: %v", s.site.Site, s.creds.Username, err)
			}
		}
	}
}

type connClosedError struct{ reason string }

func (e connClosedError) Error() string { return e.reason }

// changeSet is a change listing in the compact form a connection keeps (and
// the shared poll caches): 32 bytes a changed message, 4 a vanished number.
type changeSet struct {
	uidValidity   uint32
	uidNext       uint32
	highestModSeq uint64
	msgs          []message
	vanished      []uint32
}

func compactChanges(res syncResult) changeSet {
	cs := changeSet{uidValidity: res.UIDValidity, uidNext: res.UIDNext, highestModSeq: res.HighestModSeq,
		msgs: make([]message, 0, len(res.Messages)), vanished: res.Vanished}
	for _, m := range res.Messages {
		cs.msgs = append(cs.msgs, newMessage(m))
	}
	return cs
}

// cost is what keeping cs in the shared poll costs; -1 when it is too large
// to keep at all.
func (cs changeSet) cost() int64 {
	if len(cs.msgs)+len(cs.vanished) > maxCachedChanges {
		return -1
	}
	return int64(len(cs.msgs))*messageBytes + int64(len(cs.vanished))*4 + 64
}

// check asks the site for changes and writes them.
func (s *imapSession) check(ctx context.Context, w *imapserver.UpdateWriter, allowExpunge, allowCached bool) error {
	sel := s.sel
	folder, known := sel.folder.Key, sel.highestModSeq
	prefix := folderCacheKey(s.credKey, s.creds.Username, folder)
	val, err := s.g.cache.Get(ctx, changesKey(prefix), allowCached, func() (any, int64, error) {
		var res changesResult
		err := s.call("mail_changes", map[string]any{"folder": folder, "known_modseq": known}, &res)
		return res, 64, err
	})
	if ctx.Err() != nil {
		return nil // DONE arrived; nothing to tell
	}
	if err != nil {
		if ue, ok := asUpstream(err); ok && ue.Kind == KindAuth {
			s.imapError(err)
			return connClosedError{"credential refused"}
		}
		// The program is told nothing new; it will ask again.
		logf("imap: poll failed site=%s user=%s folder=%s: %v", s.site.Site, s.creds.Username, folder, err)
		sel.lastCheck = time.Now()
		if allowExpunge {
			return s.flushGone(w)
		}
		return nil
	}
	ch := val.(changesResult)
	sel.lastCheck = time.Now()
	if ch.UIDValidity != sel.uidValidity {
		logf("imap: folder renumbered site=%s user=%s folder=%s; closing so the program starts it over", s.site.Site, s.creds.Username, folder)
		s.conn.Bye("This folder was renumbered; reconnect to see it")
		return connClosedError{"uidvalidity changed"}
	}
	if ch.HighestModSeq == sel.highestModSeq {
		sel.forcePoll = false
		if allowExpunge {
			return s.flushGone(w)
		}
		return nil
	}

	val, err = s.g.cache.Get(ctx, sinceKey(prefix, known), allowCached && !sel.forcePoll, func() (any, int64, error) {
		var res syncResult
		if err := s.call("mail_sync", map[string]any{"folder": folder, "since_modseq": known}, &res); err != nil {
			return nil, -1, err
		}
		cs := compactChanges(res)
		return cs, cs.cost(), nil
	})
	if ctx.Err() != nil {
		return nil
	}
	sel.forcePoll = false
	if err != nil {
		if ue, ok := asUpstream(err); ok && ue.Kind == KindAuth {
			s.imapError(err)
			return connClosedError{"credential refused"}
		}
		logf("imap: change listing failed site=%s user=%s folder=%s: %v", s.site.Site, s.creds.Username, folder, err)
		if allowExpunge {
			return s.flushGone(w)
		}
		return nil
	}
	cs := val.(changeSet)
	if cs.uidValidity != sel.uidValidity {
		s.conn.Bye("This folder was renumbered; reconnect to see it")
		return connClosedError{"uidvalidity changed"}
	}
	return s.apply(w, cs, allowExpunge)
}

// apply merges a change listing into the connection's view and writes it:
// expunges (when allowed), flag changes, then the new message count. cs may
// be shared with other connections: it is only read.
func (s *imapSession) apply(w *imapserver.UpdateWriter, cs changeSet, allowExpunge bool) error {
	sel := s.sel
	// New numbers extend the list: make room in the folder-list budget first,
	// and when there is none, take nothing from this listing — the next poll
	// asks again from the same change number, so nothing is missed.
	added := 0
	for i := range cs.msgs {
		if cs.msgs[i].uid > sel.maxUID() {
			added++
		}
	}
	if err := sel.reserveFor(len(sel.msgs) + added); err != nil {
		logf("imap: folder-list budget spent; new mail waits for the next poll site=%s user=%s folder=%s",
			s.site.Site, s.creds.Username, sel.folder.Key)
		return nil
	}
	for _, uid := range cs.vanished {
		if i := sel.indexOfUID(uid); i >= 0 {
			sel.msgs[i].setGone()
		}
	}
	var changed []uint32
	before := len(sel.msgs)
	for k := range cs.msgs {
		m := cs.msgs[k] // a copy: cs is shared
		if i := sel.indexOfUID(m.uid); i >= 0 {
			cur := &sel.msgs[i]
			cur.modseq = m.modseq
			if m.sizeKnown() {
				cur.setSize(m.size)
			}
			if !cur.gone() && cur.bits&flagMask != m.bits&flagMask {
				cur.bits = cur.bits&^flagMask | m.bits&flagMask
				changed = append(changed, m.uid)
			}
			continue
		}
		if m.uid > sel.maxUID() {
			m.bits &^= bitGone
			sel.msgs = append(sel.msgs, m)
			continue
		}
		// A number below the highest this connection knows that it has never
		// seen cannot happen (numbers only rise); ignore rather than renumber.
		logf("imap: ignoring out-of-order uid %d site=%s folder=%s", m.uid, s.site.Site, sel.folder.Key)
	}
	if cs.highestModSeq > sel.highestModSeq {
		sel.highestModSeq = cs.highestModSeq
	}
	if cs.uidNext > sel.uidNext {
		sel.uidNext = cs.uidNext
	}
	grew := len(sel.msgs) > before

	if allowExpunge {
		if err := s.flushGone(w); err != nil {
			return err
		}
	}
	for _, uid := range changed {
		i := sel.indexOfUID(uid)
		if i < 0 {
			continue
		}
		if err := w.WriteMessageFlags(uint32(i+1), imap.UID(uid), sel.msgs[i].flagList()); err != nil {
			return err
		}
	}
	if grew {
		if err := w.WriteNumMessages(uint32(len(sel.msgs))); err != nil {
			return err
		}
	}
	return nil
}

// flushGone writes the EXPUNGEs held back while they were not allowed.
func (s *imapSession) flushGone(w *imapserver.UpdateWriter) error {
	sel := s.sel
	if sel == nil {
		return nil
	}
	var uids []uint32
	for i := range sel.msgs {
		if sel.msgs[i].gone() {
			uids = append(uids, sel.msgs[i].uid)
		}
	}
	if len(uids) == 0 {
		return nil
	}
	return s.expungeUIDs(uids, w.WriteExpunge)
}

// ── messages the site refused ───────────────────────────────────────────

// A refusal (locked, unavailable, gone) is remembered per connection, by
// folder, UIDVALIDITY and number:
//   - for refusalTTL it answers a repeat fetch without asking the site again
//     (Thunderbird retries a refused download several times within a
//     second); short, so an unlock is seen almost at once;
//   - for refusalSeenGuard it keeps a STORE +FLAGS (\Seen) from marking read
//     a message the program never managed to open.
var (
	refusalTTL       = 5 * time.Second
	refusalSeenGuard = 60 * time.Second
)

// The memory holds the open folder's refusals only (a new folder or
// UIDVALIDITY starts it afresh), as number -> {when, why} in about 16 bytes, at most
// maxRefusals of them; past that, expired ones go, then the oldest.
const maxRefusals = 1000

var refusalReasons = []string{"", "locked", "unavailable", "gone"}

type refusal struct {
	at     int64 // Unix nanoseconds
	reason uint8 // index into refusalReasons
}

type refusalMemory struct {
	folder   string
	validity uint32
	byUID    map[uint32]refusal
}

func (s *imapSession) refusalsHere() *refusalMemory {
	if s.sel == nil {
		return nil
	}
	r := &s.refusals
	if r.byUID == nil || r.folder != s.sel.folder.Key || r.validity != s.sel.uidValidity {
		*r = refusalMemory{folder: s.sel.folder.Key, validity: s.sel.uidValidity, byUID: map[uint32]refusal{}}
	}
	return r
}

func (s *imapSession) noteRefusal(uid uint32, reason string) {
	r := s.refusalsHere()
	if r == nil {
		return
	}
	code := uint8(2) // unavailable, for anything unnamed
	for i, name := range refusalReasons {
		if name == reason && i > 0 {
			code = uint8(i)
		}
	}
	now := time.Now().UnixNano()
	if _, have := r.byUID[uid]; !have && len(r.byUID) >= maxRefusals {
		cutoff := now - int64(refusalSeenGuard)
		var oldestUID uint32
		oldest := int64(-1)
		for u, e := range r.byUID {
			if e.at < cutoff {
				delete(r.byUID, u)
				continue
			}
			if oldest < 0 || e.at < oldest {
				oldest, oldestUID = e.at, u
			}
		}
		if len(r.byUID) >= maxRefusals {
			delete(r.byUID, oldestUID)
		}
	}
	r.byUID[uid] = refusal{at: now, reason: code}
}

// refusedWithin answers the reason the site refused this number within d, or "".
func (s *imapSession) refusedWithin(uid uint32, d time.Duration) string {
	r := s.refusalsHere()
	if r == nil {
		return ""
	}
	e, ok := r.byUID[uid]
	if !ok || time.Now().UnixNano()-e.at > int64(d) {
		return ""
	}
	return refusalReasons[e.reason]
}
