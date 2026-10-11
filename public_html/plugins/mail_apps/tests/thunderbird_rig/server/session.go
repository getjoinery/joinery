package main

import (
	"sort"
	"strings"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
	"github.com/emersion/go-imap/v2/imapserver/imapmemserver"
)

// rigSession wraps go-imap's in-memory session with the rig's hooks:
// PERMANENTFLAGS, a LIST that reports SPECIAL-USE, the body-fetch lock, and
// a modseq bump on every change (for CONDSTORE emulation).
type rigSession struct {
	*imapmemserver.UserSession
	password string
	selected string
}

var _ imapserver.SessionIMAP4rev2 = (*rigSession)(nil)

func (s *rigSession) Close() error {
	if s.UserSession != nil {
		return s.UserSession.Close()
	}
	return nil
}

func (s *rigSession) Login(username, password string) error {
	if username != *flagUser || password != s.password {
		return imapserver.ErrAuthFailed
	}
	s.UserSession = imapmemserver.NewUserSession(memUser)
	return nil
}

func (s *rigSession) Select(name string, options *imap.SelectOptions) (*imap.SelectData, error) {
	data, err := s.UserSession.Select(name, options)
	if err != nil {
		return nil, err
	}
	s.selected = canonFolder(name)
	if *flagSelectFlags != "" {
		var fl []imap.Flag
		for _, f := range strings.Fields(*flagSelectFlags) {
			fl = append(fl, imap.Flag(f))
		}
		data.Flags = fl
	}
	if *flagPermFlags != "*" {
		var pf []imap.Flag
		for _, f := range strings.Fields(*flagPermFlags) {
			pf = append(pf, imap.Flag(f))
		}
		data.PermanentFlags = pf
	}
	return data, nil
}

func canonFolder(name string) string {
	if strings.EqualFold(name, "INBOX") {
		return "INBOX"
	}
	return name
}

func (s *rigSession) List(w *imapserver.ListWriter, ref string, patterns []string, options *imap.ListOptions) error {
	if len(patterns) == 0 {
		return w.WriteList(&imap.ListData{Attrs: []imap.MailboxAttr{imap.MailboxAttrNoSelect}, Delim: '/'})
	}
	foldersMu.Lock()
	names := make([]string, 0, len(folders))
	for n := range folders {
		names = append(names, n)
	}
	sort.Strings(names)
	use := map[string]imap.MailboxAttr{}
	for k, v := range folders {
		use[k] = v
	}
	foldersMu.Unlock()
	for _, name := range names {
		match := false
		for _, p := range patterns {
			if imapserver.MatchList(name, '/', ref, p) {
				match = true
				break
			}
		}
		if !match || (options.SelectSpecialUse && use[name] == "") {
			continue
		}
		data := imap.ListData{Mailbox: name, Delim: '/', Attrs: []imap.MailboxAttr{imap.MailboxAttrHasNoChildren}}
		for _, n := range strings.Split(*flagNoInferiors, ",") {
			if n != "" && n == name {
				data.Attrs = []imap.MailboxAttr{imap.MailboxAttrNoInferiors}
			}
		}
		if options.SelectSubscribed || options.ReturnSubscribed {
			data.Attrs = append(data.Attrs, imap.MailboxAttrSubscribed)
		}
		if use[name] != "" {
			data.Attrs = append(data.Attrs, use[name])
		}
		if options.ReturnStatus != nil {
			st, err := memUser.Status(name, options.ReturnStatus)
			if err == nil {
				data.Status = st
			}
		}
		if err := w.WriteList(&data); err != nil {
			return err
		}
	}
	return nil
}

func (s *rigSession) Create(name string, options *imap.CreateOptions) error {
	if err := s.UserSession.Create(name, options); err != nil {
		return err
	}
	foldersMu.Lock()
	folders[name] = ""
	foldersMu.Unlock()
	_ = memUser.Subscribe(name)
	return nil
}

func (s *rigSession) Delete(name string) error {
	if err := s.UserSession.Delete(name); err != nil {
		return err
	}
	foldersMu.Lock()
	delete(folders, name)
	foldersMu.Unlock()
	return nil
}

func (s *rigSession) Rename(oldName, newName string, options *imap.RenameOptions) error {
	if err := s.UserSession.Rename(oldName, newName, options); err != nil {
		return err
	}
	foldersMu.Lock()
	folders[newName] = folders[oldName]
	delete(folders, oldName)
	foldersMu.Unlock()
	return nil
}

// wantsBody reports whether a FETCH asks for message content (anything but
// header-only sections), which is what a locked message cannot serve.
func wantsBody(o *imap.FetchOptions) bool {
	for _, bs := range o.BodySection {
		if bs.Specifier != imap.PartSpecifierHeader && bs.Specifier != imap.PartSpecifierMIME {
			return true
		}
	}
	return len(o.BinarySection) > 0
}

func (s *rigSession) Fetch(w *imapserver.FetchWriter, numSet imap.NumSet, options *imap.FetchOptions) error {
	if !wantsBody(options) {
		return s.UserSession.Fetch(w, numSet, options)
	}
	// Resolve the requested set to UIDs, drop the locked ones, serve the rest,
	// and answer NO [UNAVAILABLE] if any were dropped.
	crit := &imap.SearchCriteria{}
	switch ns := numSet.(type) {
	case imap.SeqSet:
		crit.SeqNum = []imap.SeqSet{ns}
	case imap.UIDSet:
		crit.UID = []imap.UIDSet{ns}
	}
	res, err := s.UserSession.Search(imapserver.NumKindUID, crit, &imap.SearchOptions{ReturnAll: true})
	if err != nil {
		return err
	}
	uids, _ := res.All.(imap.UIDSet).Nums()
	var keep imap.UIDSet
	locked := 0
	for _, u := range uids {
		if rig.isLocked(s.selected, u) {
			locked++
			continue
		}
		keep.AddNum(u)
	}
	if locked == 0 {
		return s.UserSession.Fetch(w, numSet, options)
	}
	if len(keep) > 0 {
		if err := s.UserSession.Fetch(w, keep, options); err != nil {
			return err
		}
	}
	return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeUnavailable,
		Text: "Mailbox locked — unlock at https://example.test/unlock"}
}

func (s *rigSession) Store(w *imapserver.FetchWriter, numSet imap.NumSet, flags *imap.StoreFlags, options *imap.StoreOptions) error {
	rig.bump()
	return s.UserSession.Store(w, numSet, flags, options)
}

func (s *rigSession) Append(mailbox string, r imap.LiteralReader, options *imap.AppendOptions) (*imap.AppendData, error) {
	rig.bump()
	return s.UserSession.Append(mailbox, r, options)
}

func (s *rigSession) Expunge(w *imapserver.ExpungeWriter, uids *imap.UIDSet) error {
	rig.bump()
	return s.UserSession.Expunge(w, uids)
}

func (s *rigSession) Copy(numSet imap.NumSet, dest string) (*imap.CopyData, error) {
	rig.bump()
	return s.UserSession.Copy(numSet, dest)
}

// Move is COPY + \Deleted + UID EXPUNGE of the moved UIDs. go-imap v2
// beta.8's imapmemserver Move both queues the EXPUNGEs in the mailbox
// tracker and writes them directly, so the moving client receives each
// EXPUNGE twice (the first one renumbered) and drops a second, unrelated
// message from its view. Here the EXPUNGEs reach the client once, from the
// tracker, before the tagged OK.
func (s *rigSession) Move(w *imapserver.MoveWriter, numSet imap.NumSet, dest string) error {
	rig.bump()
	data, err := s.UserSession.Copy(numSet, dest)
	if err != nil {
		return err
	}
	del := &imap.StoreFlags{Op: imap.StoreFlagsAdd, Silent: true, Flags: []imap.Flag{imap.FlagDeleted}}
	if err := s.UserSession.Store(nil, numSet, del, nil); err != nil {
		return err
	}
	uids := data.SourceUIDs
	if err := s.UserSession.Expunge(nil, &uids); err != nil {
		return err
	}
	return w.WriteCopyData(data)
}
