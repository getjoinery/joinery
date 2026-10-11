package main

import (
	"bufio"
	"bytes"
	"strings"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
	"github.com/emersion/go-message/textproto"
)

// What a FETCH needs from the site.
func fetchNeedsFull(o *imap.FetchOptions) bool {
	if o.BodyStructure != nil || len(o.BinarySection) > 0 || len(o.BinarySectionSize) > 0 {
		return true
	}
	for _, bs := range o.BodySection {
		if len(bs.Part) > 0 || bs.Specifier != imap.PartSpecifierHeader {
			return true
		}
	}
	return false
}

func fetchNeedsHeader(o *imap.FetchOptions) bool {
	return o.Envelope || len(o.BodySection) > 0
}

// fetchMarksSeen: reading a body without .PEEK sets \Seen (RFC 3501 § 6.4.5).
func fetchMarksSeen(o *imap.FetchOptions) bool {
	for _, bs := range o.BodySection {
		if !bs.Peek {
			return true
		}
	}
	for _, bs := range o.BinarySection {
		if !bs.Peek {
			return true
		}
	}
	return false
}

// served is what the site gave for one message in this FETCH.
type served struct {
	full   []byte
	header []byte
	size   int64
	err    string // locked | unavailable | gone
	held   bool   // its bytes are counted in this round's Held (not the cache's)
}

// Fetch serves FETCH and UID FETCH. Numbers, flags, dates and sizes come from
// the folder list the connection already holds; header blocks and whole
// messages come from mail_fetch in batches. A message the site cannot serve
// (locked, unavailable, gone) is left out and the command answers
// NO [UNAVAILABLE] — never a stand-in (spec § Locked behaviour, rule 3).
func (s *imapSession) Fetch(w *imapserver.FetchWriter, numSet imap.NumSet, options *imap.FetchOptions) error {
	sel := s.sel
	if sel == nil {
		return &imap.Error{Type: imap.StatusResponseTypeBad, Code: imap.ResponseCodeClientBug, Text: "No folder selected"}
	}
	idx := sel.resolve(numSet)
	if len(idx) == 0 {
		return nil
	}
	needFull := fetchNeedsFull(options)
	needHeader := !needFull && fetchNeedsHeader(options)
	markSeen := !sel.readOnly && fetchMarksSeen(options)

	what := ""
	switch {
	case needFull:
		what = "full"
	case needHeader || options.RFC822Size:
		what = "header"
	}

	// The numbers still to answer, ascending. Each round asks the site once
	// for (up to fetchWindow of) the ones it must supply; the site answers as
	// many as its time and size budget allows and names the rest in `more`.
	// What it answered is written at once, and the rest go round again — so
	// "UID FETCH 1:5 (BODY.PEEK[])" is one call when five messages fit in the
	// budget, and memory holds one answer at a time.
	const fetchWindow = 1000
	worst := ""
	unlock := s.unlockURL
	pending := idx
	for len(pending) > 0 {
		// One round: ask, write what came back, then let go of it.
		round := func() error {
			var held *Held
			defer func() { held.Done() }()
			got := map[uint32]*served{}
			var want []uint32
			end := 0
			for end < len(pending) && len(want) < fetchWindow {
				m := &sel.msgs[pending[end]]
				end++
				switch {
				case what == "":
					// metadata only: the list has it all
				case needFull || needHeader:
					if b := s.bodies.get(m.uid); b != nil {
						got[m.uid] = &served{full: b, size: int64(len(b))}
					} else if reason := s.refusedWithin(m.uid, refusalTTL); reason != "" {
						got[m.uid] = &served{err: reason}
					} else {
						want = append(want, m.uid)
					}
				default: // RFC822.SIZE the list does not have yet
					if !m.sizeKnown() {
						want = append(want, m.uid)
					}
				}
			}
			window := pending[:end]
			deferred := map[uint32]bool{}
			if len(want) > 0 {
				res, more, url, h, err := s.fetchOnce(want, what)
				held = h
				if err != nil {
					return s.imapError(err)
				}
				if url != "" {
					unlock = url
				}
				for _, u := range more {
					deferred[u] = true
				}
				for uid, v := range res {
					got[uid] = v
					if v.full != nil {
						s.bodies.put(uid, v.full)
					}
					if v.err != "" {
						s.noteRefusal(uid, v.err)
					}
				}
				if len(res) == 0 {
					return s.imapError(&UpstreamError{Kind: KindServerBug, Action: "mail_fetch", Message: "no progress fetching messages"})
				}
			}
			var now, later []int
			for _, i := range window {
				if deferred[sel.msgs[i].uid] {
					later = append(later, i)
				} else {
					now = append(now, i)
				}
			}
			pending = append(later, pending[end:]...)

			// Sizes learned along the way.
			for _, i := range now {
				m := &sel.msgs[i]
				if v := got[m.uid]; v != nil && v.err == "" && v.size >= 0 {
					if v.full != nil && m.sizeKnown() && m.size != v.size {
						logf("imap: size told %d but served %d site=%s folder=%s uid=%d", m.size, v.size, s.site.Site, sel.folder.Key, m.uid)
					}
					m.setSize(v.size)
				}
			}

			// Reading a body sets \Seen — only for a message actually served.
			seenSet := map[uint32]bool{}
			if markSeen {
				var unseen []uint32
				for _, i := range now {
					m := &sel.msgs[i]
					if v := got[m.uid]; v != nil && v.err == "" && !m.has(imap.FlagSeen) && !m.gone() {
						unseen = append(unseen, m.uid)
					}
				}
				if len(unseen) > 0 {
					if err := s.storeFlags(unseen, "add", []imap.Flag{imap.FlagSeen}); err != nil {
						return err
					}
					for _, uid := range unseen {
						seenSet[uid] = true
					}
				}
			}

			for _, i := range now {
				m := &sel.msgs[i]
				v := got[m.uid]
				if (needFull || needHeader || (options.RFC822Size && !m.sizeKnown())) && (v == nil || v.err != "") {
					reason := "gone"
					if v != nil {
						reason = v.err
					}
					worst = worseReason(worst, reason)
					continue
				}
				if err := s.writeFetch(w, uint32(i+1), m, v, options, seenSet[m.uid]); err != nil {
					return err
				}
				// Written: its bytes are let go now, not at the end of the
				// round, so a program that reads slowly holds only what it
				// has not read yet. (The message cache may keep its own.)
				if v != nil && v.held {
					held.Release(int64(len(v.full) + len(v.header)))
					v.full, v.header, v.held = nil, nil, false
				}
			}
			return nil
		}
		if err := round(); err != nil {
			return err
		}
	}

	if worst != "" {
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeUnavailable, Text: unavailableText(worst, unlock)}
	}
	return nil
}

func worseReason(a, b string) string {
	rank := map[string]int{"": 0, "gone": 1, "unavailable": 2, "locked": 3}
	if rank[b] > rank[a] {
		return b
	}
	if _, known := rank[b]; !known && a == "" {
		return "unavailable"
	}
	return a
}

func unavailableText(reason, unlock string) string {
	switch reason {
	case "locked":
		if unlock != "" {
			return ascii("Mailbox locked - unlock at " + unlock)
		}
		return "Mailbox locked - unlock it on the website"
	case "gone":
		return "Some of these messages no longer exist"
	default:
		return "Some of these messages cannot be opened here"
	}
}

// fetchOnce asks mail_fetch once for header blocks or whole messages.
// Numbers the site did not reach come back in more; a number it answered
// neither way (no longer in the folder) counts as gone.
//
// The messages (or header blocks) answered stay reserved — in the in-flight
// budget and in the credential's own budget — in the Held it answers, which
// the caller gives back message by message as it writes them, and finishes
// with Done. Before the call the credential reserves a likely round
// (fetchCredentialEstimate); afterwards that is set to what really came.
func (s *imapSession) fetchOnce(uids []uint32, what string) (map[uint32]*served, []uint32, string, *Held, error) {
	sets := uidSetStrings(uids)
	credB := s.credBudget
	if credB == nil {
		credB = NewBudget(credentialHeld)
	}
	est := min64(fetchCredentialEstimate(what), credB.Cap())
	if err := acquireWithin(credB, est); err != nil {
		return nil, nil, "", nil, busyError("mail_fetch")
	}
	var res fetchResult
	held, err := s.site.upstream.CallHold(callCtx(), s.creds, "mail_fetch",
		map[string]any{"folder": s.sel.folder.Key, "uids": sets[0], "what": what}, nil, true, &res)
	if err != nil {
		credB.Release(est)
		held.Done()
		return nil, nil, "", nil, err
	}
	var payload int64
	for i := range res.Messages {
		payload += int64(len(res.Messages[i].Bytes) + len(res.Messages[i].Header))
	}
	// The credential holds what the round has not written, up to its whole
	// budget: one message can be bigger and must still go out.
	want := min64(payload, credB.Cap())
	switch {
	case want > est:
		if err := acquireWithin(credB, want-est); err != nil {
			credB.Release(est)
			held.Done()
			return nil, nil, "", nil, busyError("mail_fetch")
		}
	case want < est:
		credB.Release(est - want)
	}
	held.TrimTo(payload)
	held.holdCredential(credB, want, payload)

	out := map[uint32]*served{}
	for _, fm := range res.Messages {
		v := &served{size: -1, err: fm.Error, held: fm.Error == ""}
		if fm.Error == "" {
			if what == "full" {
				v.full = fm.Bytes
				v.size = int64(len(v.full))
			} else {
				v.header = fm.Header
				if fm.Size != nil {
					v.size = *fm.Size
				}
			}
		}
		out[fm.UID] = v
	}
	// A set string covers a prefix of the numbers (uidSetStrings splits in
	// order); numbers past it were not asked and go round again.
	askedEnd := asked(uids, sets[0])
	more := map[uint32]bool{}
	for _, u := range res.More {
		more[u] = true
	}
	var again []uint32
	for _, u := range uids[:askedEnd] {
		if _, answered := out[u]; more[u] && !answered {
			again = append(again, u)
		} else if !answered {
			out[u] = &served{err: "gone"}
		}
	}
	again = append(again, uids[askedEnd:]...)
	return out, again, res.UnlockURL, held, nil
}

// fetchCredentialEstimate is what a credential reserves for a round before
// asking: the site stops a batch of whole messages past 12 MB (but always
// sends one), and of header blocks past 4 MB.
func fetchCredentialEstimate(what string) int64 {
	if what == "full" {
		return 16 << 20
	}
	return 4 << 20
}

// fetchUpstream follows more until every number is answered.
func (s *imapSession) fetchUpstream(uids []uint32, what string) (map[uint32]*served, string, error) {
	out := map[uint32]*served{}
	unlock := ""
	pending := uids
	for len(pending) > 0 {
		res, more, url, held, err := s.fetchOnce(pending, what)
		held.Done() // only sizes are kept from here
		if err != nil {
			return nil, "", err
		}
		if url != "" {
			unlock = url
		}
		if len(res) == 0 {
			return nil, "", &UpstreamError{Kind: KindServerBug, Action: "mail_fetch", Message: "no progress fetching messages"}
		}
		for u, v := range res {
			out[u] = v
		}
		pending = more
	}
	return out, unlock, nil
}

// asked is how many of the ascending UIDs the set string covers (uidSetStrings
// splits in order, so it is a prefix).
func asked(sorted []uint32, set string) int {
	var max uint32
	for _, piece := range strings.Split(set, ",") {
		if k := strings.LastIndexByte(piece, ':'); k >= 0 {
			piece = piece[k+1:]
		}
		var n uint32
		for _, c := range piece {
			n = n*10 + uint32(c-'0')
		}
		if n > max {
			max = n
		}
	}
	i := 0
	for i < len(sorted) && sorted[i] <= max {
		i++
	}
	return i
}

// writeFetch writes one message's FETCH response.
func (s *imapSession) writeFetch(w *imapserver.FetchWriter, seq uint32, m *message, v *served, o *imap.FetchOptions, seenNow bool) error {
	var full, header []byte
	if v != nil {
		full, header = v.full, v.header
		if header == nil && full != nil {
			header = full[:headerEnd(full)]
		}
	}
	rw := w.CreateMessage(seq)
	if o.UID {
		rw.WriteUID(imap.UID(m.uid))
	}
	if o.Flags || seenNow {
		rw.WriteFlags(m.flagList())
	}
	if o.InternalDate {
		rw.WriteInternalDate(m.when())
	}
	if o.RFC822Size {
		rw.WriteRFC822Size(m.size)
	}
	if o.Envelope {
		h, _ := textproto.ReadHeader(bufio.NewReader(bytes.NewReader(header)))
		rw.WriteEnvelope(imapserver.ExtractEnvelope(h))
	}
	if o.BodyStructure != nil {
		rw.WriteBodyStructure(fixBodyStructure(imapserver.ExtractBodyStructure(bytes.NewReader(full))))
	}
	for _, sec := range o.BodySection {
		data := extractSection(full, header, sec)
		wc := rw.WriteBodySection(sec, int64(len(data)))
		_, werr := wc.Write(data)
		cerr := wc.Close()
		if werr != nil {
			return werr
		}
		if cerr != nil {
			return cerr
		}
	}
	for _, sec := range o.BinarySection {
		data := imapserver.ExtractBinarySection(bytes.NewReader(full), sec)
		wc := rw.WriteBinarySection(sec, int64(len(data)))
		_, werr := wc.Write(data)
		cerr := wc.Close()
		if werr != nil {
			return werr
		}
		if cerr != nil {
			return cerr
		}
	}
	for _, sec := range o.BinarySectionSize {
		rw.WriteBinarySectionSize(sec, imapserver.ExtractBinarySectionSize(bytes.NewReader(full), sec))
	}
	return rw.Close()
}

// headerEnd is the length of the header block including the blank line.
func headerEnd(b []byte) int {
	if i := bytes.Index(b, []byte("\r\n\r\n")); i >= 0 {
		return i + 4
	}
	if i := bytes.Index(b, []byte("\n\n")); i >= 0 {
		return i + 2
	}
	return len(b)
}

// extractSection serves a body section. The whole message, its header and
// its text are served as the exact bytes the site sent (so sizes and offsets
// agree with RFC822.SIZE); parts and header-field subsets go through
// go-imap's extraction.
func extractSection(full, header []byte, sec *imap.FetchItemBodySection) []byte {
	var data []byte
	switch {
	case len(sec.Part) == 0 && sec.Specifier == imap.PartSpecifierNone:
		data = full
	case len(sec.Part) == 0 && sec.Specifier == imap.PartSpecifierText && full != nil:
		data = full[headerEnd(full):]
	case len(sec.Part) == 0 && sec.Specifier == imap.PartSpecifierHeader &&
		len(sec.HeaderFields) == 0 && len(sec.HeaderFieldsNot) == 0:
		data = header
	case len(sec.Part) == 0 && sec.Specifier == imap.PartSpecifierHeader:
		plain := *sec
		plain.Partial = nil
		data = imapserver.ExtractBodySection(bytes.NewReader(header), &plain)
	default:
		plain := *sec
		plain.Partial = nil
		data = imapserver.ExtractBodySection(bytes.NewReader(full), &plain)
	}
	if p := sec.Partial; p != nil {
		if p.Offset >= int64(len(data)) {
			return []byte{}
		}
		end := p.Offset + p.Size
		if end > int64(len(data)) || p.Size <= 0 {
			end = int64(len(data))
		}
		return data[p.Offset:end]
	}
	if data == nil {
		return []byte{}
	}
	return data
}

// fixBodyStructure gives a part with no Content-Type its default
// (text/plain; charset=us-ascii, RFC 2045 § 5.2): go-imap leaves the type
// empty, which programs cannot parse.
func fixBodyStructure(bs imap.BodyStructure) imap.BodyStructure {
	switch b := bs.(type) {
	case *imap.BodyStructureSinglePart:
		if b.Type == "" {
			b.Type, b.Subtype = "text", "plain"
			if b.Params == nil {
				b.Params = map[string]string{}
			}
			if _, ok := b.Params["charset"]; !ok {
				b.Params["charset"] = "us-ascii"
			}
			if b.Text == nil {
				b.Text = &imap.BodyStructureText{}
			}
		}
		if b.MessageRFC822 != nil {
			b.MessageRFC822.BodyStructure = fixBodyStructure(b.MessageRFC822.BodyStructure)
		}
	case *imap.BodyStructureMultiPart:
		for i, c := range b.Children {
			b.Children[i] = fixBodyStructure(c)
		}
	}
	return bs
}
