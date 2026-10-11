package main

import (
	"sort"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
)

func readOnlyErr() error {
	return &imap.Error{Type: imap.StatusResponseTypeNo, Code: "READ-ONLY", Text: "This folder was opened read-only (EXAMINE)"}
}

func noSelection() error {
	return &imap.Error{Type: imap.StatusResponseTypeBad, Code: imap.ResponseCodeClientBug, Text: "No folder selected"}
}

// storeFlags runs mail_store over UIDs and takes the flags the site answers.
func (s *imapSession) storeFlags(uids []uint32, op string, flags []imap.Flag) error {
	sel := s.sel
	names := []string{}
	for _, f := range flags {
		names = append(names, string(f))
	}
	for _, set := range uidSetStrings(uids) {
		var res storeResult
		if err := s.call("mail_store", map[string]any{"folder": sel.folder.Key, "uids": set, "op": op, "flags": names}, &res); err != nil {
			return s.imapError(err)
		}
		for _, m := range res.Messages {
			if i := sel.indexOfUID(m.UID); i >= 0 {
				sel.msgs[i].setFlags(m.Flags)
				sel.msgs[i].modseq = m.ModSeq
			}
		}
	}
	s.g.cache.Invalidate(folderCacheKey(s.credKey, s.creds.Username, sel.folder.Key))
	return nil
}

// Store serves STORE / UID STORE. Only the flags Joinery keeps are sent; a
// program's own tags stay with the program (not in PERMANENTFLAGS).
func (s *imapSession) Store(w *imapserver.FetchWriter, numSet imap.NumSet, flags *imap.StoreFlags, options *imap.StoreOptions) error {
	sel := s.sel
	if sel == nil {
		return noSelection()
	}
	if sel.readOnly {
		return readOnlyErr()
	}
	idx := sel.resolve(numSet)
	if len(idx) == 0 {
		return nil
	}
	var kept []imap.Flag
	for _, f := range flags.Flags {
		if k, ok := isKeptFlag(f); ok {
			kept = append(kept, k)
		}
	}
	op := "set"
	switch flags.Op {
	case imap.StoreFlagsAdd:
		op = "add"
	case imap.StoreFlagsDel:
		op = "remove"
	}
	uids := sel.liveUIDs(idx)
	if len(uids) > 0 && (op == "set" || len(kept) > 0) {
		for _, g := range s.storeGroups(uids, op, kept) {
			if op != "set" && len(g.flags) == 0 {
				continue
			}
			if err := s.storeFlags(g.uids, op, g.flags); err != nil {
				return err
			}
		}
	}
	if flags.Silent {
		return nil
	}
	_, byUID := numSet.(imap.UIDSet)
	for _, i := range idx {
		m := &sel.msgs[i]
		rw := w.CreateMessage(uint32(i + 1))
		if byUID {
			rw.WriteUID(imap.UID(m.uid))
		}
		rw.WriteFlags(m.flagList())
		if err := rw.Close(); err != nil {
			return err
		}
	}
	return nil
}

func (s *imapSession) destination(dest string) (*apiFolder, error) {
	f, err := s.folderByName(dest)
	if err != nil {
		return nil, err
	}
	if f == nil {
		return nil, &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeTryCreate, Text: ascii("No folder named " + dest)}
	}
	return f, nil
}

// place runs mail_copy or mail_move and builds the COPYUID data.
func (s *imapSession) place(action string, numSet imap.NumSet, dest string) (*imap.CopyData, []uint32, error) {
	sel := s.sel
	to, err := s.destination(dest)
	if err != nil {
		return nil, nil, err
	}
	uids := sel.liveUIDs(sel.resolve(numSet))
	if len(uids) == 0 {
		return nil, nil, nil
	}
	data := &imap.CopyData{}
	var pairs [][2]uint32
	for _, set := range uidSetStrings(uids) {
		var res placeResult
		if err := s.call(action, map[string]any{"folder": sel.folder.Key, "uids": set, "to": to.Key}, &res); err != nil {
			return nil, nil, s.imapError(err)
		}
		data.UIDValidity = res.DestUIDValidity
		pairs = append(pairs, res.UIDMap...)
	}
	var moved []uint32
	data.SourceUIDs, data.DestUIDs = copyUIDSets(pairs)
	for _, p := range pairs {
		moved = append(moved, p[0])
	}
	s.g.cache.Invalidate(folderCacheKey(s.credKey, s.creds.Username, sel.folder.Key))
	s.g.cache.Invalidate(folderCacheKey(s.credKey, s.creds.Username, to.Key))
	if len(data.SourceUIDs) == 0 {
		data = nil
	}
	return data, moved, nil
}

func (s *imapSession) Copy(numSet imap.NumSet, dest string) (*imap.CopyData, error) {
	if s.sel == nil {
		return nil, noSelection()
	}
	data, _, err := s.place("mail_copy", numSet, dest)
	if err != nil {
		return nil, err
	}
	// A copy can change the source folder too (out of Trash or Spam): the
	// next poll looks without waiting.
	s.sel.forcePoll = true
	return data, nil
}

func (s *imapSession) Move(w *imapserver.MoveWriter, numSet imap.NumSet, dest string) error {
	if s.sel == nil {
		return noSelection()
	}
	if s.sel.readOnly {
		return readOnlyErr()
	}
	data, moved, err := s.place("mail_move", numSet, dest)
	if err != nil {
		return err
	}
	if err := w.WriteCopyData(data); err != nil {
		return err
	}
	// The messages that arrived are gone from here. Any the destination did
	// not number (a draft deleted, the lock notice dismissed) leave with the
	// poll that follows this command.
	s.sel.forcePoll = true
	return s.expungeUIDs(moved, w.WriteExpunge)
}

// Expunge serves EXPUNGE, UID EXPUNGE and CLOSE.
func (s *imapSession) Expunge(w *imapserver.ExpungeWriter, uids *imap.UIDSet) error {
	sel := s.sel
	if sel == nil {
		return noSelection()
	}
	if sel.readOnly {
		if uids == nil {
			return nil // CLOSE on a read-only folder expunges nothing
		}
		return readOnlyErr()
	}
	var sets []string
	if uids != nil {
		live := sel.liveUIDs(sel.resolve(*uids))
		if len(live) == 0 {
			return s.expungeUIDs(nil, w.WriteExpunge)
		}
		sets = uidSetStrings(live)
	} else {
		sets = []string{""}
	}
	var gone []uint32
	for _, set := range sets {
		body := map[string]any{"folder": sel.folder.Key}
		if set != "" {
			body["uids"] = set
		}
		var res expungeResult
		if err := s.call("mail_expunge", body, &res); err != nil {
			return s.imapError(err)
		}
		gone = append(gone, res.Expunged...)
	}
	s.g.cache.Invalidate(folderCacheKey(s.credKey, s.creds.Username, sel.folder.Key))
	for i := range sel.msgs {
		if m := &sel.msgs[i]; m.gone() {
			gone = append(gone, m.uid)
		}
	}
	return s.expungeUIDs(gone, w.WriteExpunge)
}

// copyUIDSets writes COPYUID's two sets so they pair up item by item (RFC 4315
// § 3): sources ascending, each with its own destination. The destination
// numbers follow arrival order in the new folder, not source order, so a
// merged and re-sorted set would pair them wrongly; runs are joined only
// where both sides are consecutive.
func copyUIDSets(pairs [][2]uint32) (imap.UIDSet, imap.UIDSet) {
	sorted := append([][2]uint32(nil), pairs...)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i][0] < sorted[j][0] })
	var src, dst imap.UIDSet
	for i := 0; i < len(sorted); {
		j := i
		for j+1 < len(sorted) && sorted[j+1][0] == sorted[j][0]+1 && sorted[j+1][1] == sorted[j][1]+1 {
			j++
		}
		src = append(src, imap.UIDRange{Start: imap.UID(sorted[i][0]), Stop: imap.UID(sorted[j][0])})
		dst = append(dst, imap.UIDRange{Start: imap.UID(sorted[i][1]), Stop: imap.UID(sorted[j][1])})
		i = j + 1
	}
	return src, dst
}

type storeGroup struct {
	flags []imap.Flag
	uids  []uint32
}

// storeGroups splits a STORE so a message this connection could not serve
// does not become read: Thunderbird sometimes sends +FLAGS (\Seen) right after
// a fetch answered NO [UNAVAILABLE]. For a number refused in the last
// minute, \Seen is left as it is (dropped from +FLAGS, kept at its present
// state for a plain FLAGS); every other flag passes through.
func (s *imapSession) storeGroups(uids []uint32, op string, flags []imap.Flag) []storeGroup {
	hasSeen := false
	var others []imap.Flag
	for _, f := range flags {
		if f == imap.FlagSeen {
			hasSeen = true
		} else {
			others = append(others, f)
		}
	}
	if !hasSeen && op != "set" {
		return []storeGroup{{flags: flags, uids: uids}}
	}
	normal := storeGroup{flags: flags}
	keepSeen := storeGroup{flags: append(append([]imap.Flag(nil), others...), imap.FlagSeen)}
	keepUnseen := storeGroup{flags: others}
	if keepUnseen.flags == nil {
		keepUnseen.flags = []imap.Flag{}
	}
	for _, uid := range uids {
		if s.refusedWithin(uid, refusalSeenGuard) == "" || op == "remove" {
			normal.uids = append(normal.uids, uid)
			continue
		}
		if op == "add" {
			keepUnseen.uids = append(keepUnseen.uids, uid)
			continue
		}
		// set: \Seen stays as it is now.
		if i := s.sel.indexOfUID(uid); i >= 0 && s.sel.msgs[i].has(imap.FlagSeen) {
			keepSeen.uids = append(keepSeen.uids, uid)
		} else {
			keepUnseen.uids = append(keepUnseen.uids, uid)
		}
	}
	if len(normal.uids)+len(keepSeen.uids)+len(keepUnseen.uids) > len(normal.uids) {
		logf("imap: kept \\Seen off %d message(s) the program could not open site=%s user=%s",
			len(keepSeen.uids)+len(keepUnseen.uids), s.site.Site, s.creds.Username)
	}
	var out []storeGroup
	for _, g := range []storeGroup{normal, keepSeen, keepUnseen} {
		if len(g.uids) > 0 {
			out = append(out, g)
		}
	}
	return out
}
