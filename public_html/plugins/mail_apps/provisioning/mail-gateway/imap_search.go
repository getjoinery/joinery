package main

import (
	"strings"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
)

// textKey is one criterion that needs the mail's words: asked of the site one
// at a time (mail_search), the answers combined here.
type textKey struct{ field, value string }

// searchField maps an IMAP text criterion to mail_search's field. ok=false
// means the site cannot answer it, and it matches nothing.
func searchField(header string) (string, bool) {
	switch strings.ToLower(header) {
	case "from":
		return "from", true
	case "to":
		return "to", true
	case "cc":
		return "cc", true
	case "bcc":
		return "bcc", true
	case "subject":
		return "subject", true
	case "message-id":
		return "message-id", true
	}
	return "", false
}

// Search serves SEARCH / UID SEARCH. Flags, keywords, numbers, dates and sizes
// are evaluated here from the folder list; words go to the site.
func (s *imapSession) Search(kind imapserver.NumKind, criteria *imap.SearchCriteria, options *imap.SearchOptions) (*imap.SearchData, error) {
	sel := s.sel
	if sel == nil {
		return nil, noSelection()
	}

	// Gather and answer every text criterion first.
	keys := map[textKey]bool{}
	collectText(criteria, keys)
	matches := map[textKey]map[uint32]bool{}
	for k := range keys {
		if k.field == "" || k.field == "*all*" {
			matches[k] = map[uint32]bool{}
			continue
		}
		var res searchResult
		if err := s.call("mail_search", map[string]any{"folder": sel.folder.Key, "field": k.field, "value": k.value}, &res); err != nil {
			return nil, s.imapError(err)
		}
		set := map[uint32]bool{}
		for _, u := range res.UIDs {
			set[u] = true
		}
		matches[k] = set
	}

	// Sizes the folder list does not have yet, when a criterion needs them.
	if usesSize(criteria) {
		var unknown []uint32
		for i := range sel.msgs {
			if m := &sel.msgs[i]; !m.sizeKnown() && !m.gone() {
				unknown = append(unknown, m.uid)
			}
		}
		for _, chunk := range chunkUIDs(unknown, 500) {
			got, _, err := s.fetchUpstream(chunk, "header")
			if err != nil {
				return nil, s.imapError(err)
			}
			for uid, v := range got {
				if i := sel.indexOfUID(uid); i >= 0 && v.err == "" && v.size >= 0 {
					sel.msgs[i].setSize(v.size)
				}
			}
		}
	}

	data := &imap.SearchData{}
	var seqs imap.SeqSet
	var uids imap.UIDSet
	n := uint32(len(sel.msgs))
	for i := range sel.msgs {
		m := &sel.msgs[i]
		if m.gone() {
			continue
		}
		seq := uint32(i + 1)
		if !s.matches(m, seq, n, criteria, matches) {
			continue
		}
		num := seq
		if kind == imapserver.NumKindUID {
			num = m.uid
			uids.AddNum(imap.UID(m.uid))
		} else {
			seqs.AddNum(seq)
		}
		if data.Min == 0 || num < data.Min {
			data.Min = num
		}
		if num > data.Max {
			data.Max = num
		}
		data.Count++
	}
	if kind == imapserver.NumKindUID {
		data.All = uids
	} else {
		data.All = seqs
	}
	return data, nil
}

func collectText(c *imap.SearchCriteria, keys map[textKey]bool) {
	for _, h := range c.Header {
		keys[headerKey(h)] = true
	}
	for _, v := range c.Body {
		keys[textKey{"body", v}] = true
	}
	for _, v := range c.Text {
		keys[textKey{"text", v}] = true
	}
	for i := range c.Not {
		collectText(&c.Not[i], keys)
	}
	for i := range c.Or {
		collectText(&c.Or[i][0], keys)
		collectText(&c.Or[i][1], keys)
	}
}

// headerKey: an unsupported header, or an empty value (which asks "has this
// header at all"), is the empty field — matching nothing, except an empty
// Message-ID, which every message has.
func headerKey(h imap.SearchCriteriaHeaderField) textKey {
	field, ok := searchField(h.Key)
	if !ok || h.Value == "" {
		if strings.EqualFold(h.Key, "message-id") {
			return textKey{"*all*", ""}
		}
		return textKey{"", h.Key + "\x00" + h.Value}
	}
	return textKey{field, h.Value}
}

func usesSize(c *imap.SearchCriteria) bool {
	if c.Larger > 0 || c.Smaller > 0 {
		return true
	}
	for i := range c.Not {
		if usesSize(&c.Not[i]) {
			return true
		}
	}
	for i := range c.Or {
		if usesSize(&c.Or[i][0]) || usesSize(&c.Or[i][1]) {
			return true
		}
	}
	return false
}

func dayOf(t time.Time) time.Time {
	y, mo, d := t.UTC().Date()
	return time.Date(y, mo, d, 0, 0, 0, 0, time.UTC)
}

func criteriaDay(t time.Time) time.Time {
	y, mo, d := t.Date()
	return time.Date(y, mo, d, 0, 0, 0, 0, time.UTC)
}

func (s *imapSession) matches(m *message, seq, n uint32, c *imap.SearchCriteria, text map[textKey]map[uint32]bool) bool {
	for _, set := range c.SeqNum {
		if !inSeqSet(set, seq, n) {
			return false
		}
	}
	maxUID := s.sel.maxUID()
	for _, set := range c.UID {
		if !inUIDSet(set, m.uid, maxUID) {
			return false
		}
	}
	day := dayOf(m.when())
	// SENT* are approximated by the internal date: the site's list carries no
	// Date header, and a program's own search window is all they serve.
	if !c.Since.IsZero() && day.Before(criteriaDay(c.Since)) {
		return false
	}
	if !c.Before.IsZero() && !day.Before(criteriaDay(c.Before)) {
		return false
	}
	if !c.SentSince.IsZero() && day.Before(criteriaDay(c.SentSince)) {
		return false
	}
	if !c.SentBefore.IsZero() && !day.Before(criteriaDay(c.SentBefore)) {
		return false
	}
	for _, f := range c.Flag {
		if !hasFlagFold(m, f) {
			return false
		}
	}
	for _, f := range c.NotFlag {
		if hasFlagFold(m, f) {
			return false
		}
	}
	if c.Larger > 0 && !(m.sizeKnown() && m.size > c.Larger) {
		return false
	}
	if c.Smaller > 0 && !(m.sizeKnown() && m.size < c.Smaller) {
		return false
	}
	for _, h := range c.Header {
		if !textHit(text, headerKey(h), m.uid) {
			return false
		}
	}
	for _, v := range c.Body {
		if !textHit(text, textKey{"body", v}, m.uid) {
			return false
		}
	}
	for _, v := range c.Text {
		if !textHit(text, textKey{"text", v}, m.uid) {
			return false
		}
	}
	for i := range c.Not {
		if s.matches(m, seq, n, &c.Not[i], text) {
			return false
		}
	}
	for i := range c.Or {
		if !s.matches(m, seq, n, &c.Or[i][0], text) && !s.matches(m, seq, n, &c.Or[i][1], text) {
			return false
		}
	}
	return true
}

func textHit(text map[textKey]map[uint32]bool, k textKey, uid uint32) bool {
	if k.field == "*all*" {
		return true
	}
	return text[k][uid]
}

func hasFlagFold(m *message, f imap.Flag) bool {
	return m.has(f) // \Recent is never set: no message is new to one session alone
}

func inSeqSet(set imap.SeqSet, seq, n uint32) bool {
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
		if seq >= a && seq <= b {
			return true
		}
	}
	return false
}

func inUIDSet(set imap.UIDSet, uid, max uint32) bool {
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
		if uid >= a && uid <= b {
			return true
		}
	}
	return false
}
