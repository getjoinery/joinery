package main

import (
	"io"
	"sort"
	"strings"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
)

// The flags Joinery keeps for a mail program (MailAppFolders::FLAG_NAMES).
// Anything else — a program's own tags — the program keeps locally, which is
// why there is no \* in PERMANENTFLAGS.
//
// $Junk and $NotJunk are deliberately not among them (owner decision J1: a
// program's automatic classifier must not set server verdicts). Thunderbird's
// junk filter stores them on messages the person never touched; left out of
// PERMANENTFLAGS, they stay in the program, a STORE of them is dropped like
// any other program keyword, and they are never reported in FLAGS. Only a
// move to Spam, or out of it, reaches the site as a verdict.
var keptFlags = []imap.Flag{imap.FlagSeen, imap.FlagFlagged, imap.FlagAnswered, imap.FlagDeleted, imap.FlagDraft,
	imap.FlagForwarded}

func isKeptFlag(f imap.Flag) (imap.Flag, bool) {
	for _, k := range keptFlags {
		if strings.EqualFold(string(f), string(k)) {
			return k, true
		}
	}
	return "", false
}

// imapSession is one IMAP connection.
type imapSession struct {
	g       *Gateway
	conn    *imapserver.Conn
	site    *Site
	ip      string
	started time.Time

	creds        Creds
	credKey      string
	authed       bool
	authFailures int
	folders      []apiFolder
	unlockURL    string

	sel      *selected
	bodies   *bodyCache
	refusals refusalMemory
	closed   bool

	untrack    func()
	credHeld   bool
	credBudget *Budget // what this credential's connections may hold, shared
}

var (
	_ imapserver.Session          = (*imapSession)(nil)
	_ imapserver.SessionNamespace = (*imapSession)(nil)
	_ imapserver.SessionMove      = (*imapSession)(nil)
)

func (g *Gateway) newIMAPSession(c *imapserver.Conn) (imapserver.Session, *imapserver.GreetingData, error) {
	ip := remoteIP(c.NetConn().RemoteAddr())
	tc, err := handshake(c.NetConn())
	if err != nil {
		logf("imap: %s: TLS handshake failed: %v", ip, err)
		return nil, nil, &imap.Error{Type: imap.StatusResponseTypeBye, Text: "TLS handshake failed"}
	}
	site := g.reg.Lookup(tc.ConnectionState().ServerName)
	if site == nil {
		logf("imap: %s: no site serves %q", ip, tc.ConnectionState().ServerName)
		return nil, nil, &imap.Error{Type: imap.StatusResponseTypeBye, Text: "This server does not serve that name"}
	}
	logf("imap: connect %s site=%s", ip, site.Site)
	s := &imapSession{g: g, conn: c, site: site, ip: ip, started: time.Now(), bodies: newBodyCache()}
	s.untrack = g.track(site.Site, func() {
		done := make(chan struct{})
		go func() { c.Bye("Mail apps were turned off for this site"); close(done) }()
		select {
		case <-done:
		case <-time.After(2 * time.Second):
			c.NetConn().Close()
		}
	})
	return s, nil, nil
}

func (s *imapSession) Close() error {
	if s.closed {
		return nil
	}
	s.closed = true
	s.untrack()
	if s.credHeld {
		s.g.credLimit.Release(s.credKey)
		s.credHeld = false
	}
	s.bodies.clear()
	s.sel.releaseList()
	s.sel = nil
	who := "-"
	if s.authed {
		who = s.creds.Username
	}
	logf("imap: disconnect %s site=%s user=%s after %s", s.ip, s.site.Site, who, time.Since(s.started).Round(time.Second))
	return nil
}

// ── sign-in ──────────────────────────────────────────────────────────────

func (s *imapSession) Login(username, password string) error {
	username = strings.TrimSpace(username)
	if s.authFailures >= 3 {
		// Nothing may be written here: AUTHENTICATE holds the connection's
		// writer while it asks. Closing ends the attempt.
		s.conn.NetConn().Close()
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeAuthenticationFailed, Text: "Too many failed sign-ins"}
	}
	if s.g.throttle.Blocked(s.ip) {
		logf("imap: sign-in refused %s site=%s user=%s: too many failures from this address", s.ip, s.site.Site, username)
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeUnavailable,
			Text: "Too many failed sign-ins from your address. Try again later."}
	}
	cr := Creds{Username: username, Secret: password, ClientIP: s.ip}
	var res loginResult
	err := s.site.upstream.Call(callCtx(), cr, "mail_login", nil, &res)
	if err != nil {
		ue, _ := asUpstream(err)
		if ue != nil && (ue.Kind == KindAuth || ue.Kind == KindRefusal || ue.Kind == KindInvalid) {
			wait := s.g.throttle.Failed(s.ip)
			s.authFailures++
			logf("imap: sign-in fail %s site=%s user=%s: %s", s.ip, s.site.Site, username, ue.Message)
			time.Sleep(wait)
			text := "Incorrect username or app password."
			if ue.Kind == KindRefusal && ue.Message != "" {
				text = ue.Message
			}
			return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeAuthenticationFailed, Text: text}
		}
		logf("imap: sign-in error %s site=%s user=%s: %v", s.ip, s.site.Site, username, err)
		return s.imapError(err)
	}
	key := credKey(s.site.Site, cr)
	credB := s.g.credLimit.Acquire(key)
	if credB == nil {
		logf("imap: sign-in refused %s site=%s user=%s: too many connections for this credential", s.ip, s.site.Site, username)
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeLimit,
			Text: "Too many connections with this app password. Close some and try again."}
	}
	s.credHeld = true
	s.credBudget = credB
	s.creds = cr
	s.credKey = key
	s.authed = true
	s.folders = res.Folders
	s.unlockURL = res.UnlockURL
	logf("imap: sign-in ok %s site=%s user=%s level=%s locked=%v", s.ip, s.site.Site, username, res.Level, res.Locked)
	return nil
}

// call runs an upstream action as the signed-in program.
func (s *imapSession) call(action string, body map[string]any, out any) error {
	return s.site.upstream.Call(callCtx(), s.creds, action, body, out)
}

// imapError turns an upstream failure into the IMAP answer. A credential the
// site stops accepting mid-session (revoked, password changed, plugin off)
// ends the connection: nothing further can succeed on it.
func (s *imapSession) imapError(err error) error {
	ue, ok := asUpstream(err)
	if !ok {
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeServerBug, Text: "Internal error"}
	}
	switch ue.Kind {
	case KindRefusal:
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: refusalCode(ue.IMAPCode), Text: ue.Message}
	case KindAuth:
		if s.authed {
			logf("imap: credential no longer accepted %s site=%s user=%s: %s", s.ip, s.site.Site, s.creds.Username, ue.Message)
			s.conn.Bye("This app password is no longer accepted")
		}
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeAuthorizationFailed, Text: "This app password is no longer accepted."}
	case KindRateLimit:
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeLimit, Text: retryText(ue.RetryAfter)}
	case KindTooLarge:
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeTooBig, Text: tooLargeText}
	case KindInvalid:
		logf("imap: request refused as invalid site=%s user=%s %s: %s", s.site.Site, s.creds.Username, ue.Action, ue.Message)
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeCannot, Text: ue.Message}
	case KindServerBug:
		logf("imap: upstream error site=%s %s: %s", s.site.Site, ue.Action, ue.Message)
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeServerBug, Text: "The mail service had a problem. Try again shortly."}
	default:
		logf("imap: upstream unavailable site=%s %s: %v", s.site.Site, ue.Action, ue)
		return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeUnavailable, Text: "The mail service is not reachable right now. Try again shortly."}
	}
}

func refusalCode(code string) imap.ResponseCode {
	switch code {
	case "AUTHORIZATIONFAILED", "NONEXISTENT", "CANNOT", "UNAVAILABLE", "ALREADYEXISTS", "TOOBIG", "CLIENTBUG",
		"LIMIT", "NOPERM", "OVERQUOTA", "SERVERBUG", "TRYCREATE", "INUSE", "EXPIRED", "CONTACTADMIN":
		return imap.ResponseCode(code)
	case "":
		return imap.ResponseCodeCannot
	default:
		return imap.ResponseCodeCannot
	}
}

// ── folders ──────────────────────────────────────────────────────────────

// folderByName resolves a name the program uses; INBOX is case-insensitive.
// A name not in the list from sign-in is looked up once more (a label made in
// the web reader since).
func (s *imapSession) folderByName(name string) (*apiFolder, error) {
	if f := findFolder(s.folders, name); f != nil {
		return f, nil
	}
	if err := s.refreshFolders(); err != nil {
		return nil, err
	}
	return findFolder(s.folders, name), nil
}

func findFolder(list []apiFolder, name string) *apiFolder {
	name = strings.TrimSuffix(name, "/")
	for i := range list {
		f := &list[i]
		if f.Name == name || (f.Key == "inbox" && strings.EqualFold(name, "INBOX")) {
			return f
		}
	}
	return nil
}

func (s *imapSession) refreshFolders() error {
	var res foldersResult
	if err := s.call("mail_folders", nil, &res); err != nil {
		return s.imapError(err)
	}
	s.folders = res.Folders
	return nil
}

func nonexistent(name string) error {
	return &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeNonExistent, Text: ascii("No folder named " + name)}
}

func specialUseAttr(su *string) imap.MailboxAttr {
	if su == nil {
		return ""
	}
	switch strings.ToLower(*su) {
	case `\sent`:
		return imap.MailboxAttrSent
	case `\drafts`:
		return imap.MailboxAttrDrafts
	case `\archive`:
		return imap.MailboxAttrArchive
	case `\junk`:
		return imap.MailboxAttrJunk
	case `\trash`:
		return imap.MailboxAttrTrash
	case `\all`:
		return imap.MailboxAttrAll
	case `\flagged`:
		return imap.MailboxAttrFlagged
	}
	return ""
}

type listEntry struct {
	name    string
	folder  *apiFolder // nil for a parent that exists only to hold children
	childOf bool
}

func (s *imapSession) List(w *imapserver.ListWriter, ref string, patterns []string, options *imap.ListOptions) error {
	if err := s.refreshFolders(); err != nil && len(s.folders) == 0 {
		return err
	}
	if len(patterns) == 0 || (len(patterns) == 1 && patterns[0] == "") {
		if options.SelectSubscribed {
			return nil
		}
		return w.WriteList(&imap.ListData{Attrs: []imap.MailboxAttr{imap.MailboxAttrNoSelect}, Delim: '/', Mailbox: ""})
	}

	// Every folder, plus a selectable-less parent for each level a name with
	// "/" implies but no folder provides (e.g. "Labels" for "Labels/Inbox").
	var entries []listEntry
	have := map[string]bool{}
	for i := range s.folders {
		have[s.folders[i].Name] = true
	}
	added := map[string]bool{}
	for i := range s.folders {
		f := &s.folders[i]
		parts := strings.Split(f.Name, "/")
		for j := 1; j < len(parts); j++ {
			parent := strings.Join(parts[:j], "/")
			if !have[parent] && !added[parent] {
				added[parent] = true
				entries = append(entries, listEntry{name: parent})
			}
		}
		entries = append(entries, listEntry{name: f.Name, folder: f})
	}
	hasChildren := func(name string) bool {
		prefix := name + "/"
		for _, e := range entries {
			if strings.HasPrefix(e.name, prefix) {
				return true
			}
		}
		return false
	}

	for _, e := range entries {
		if !matchAny(e.name, ref, patterns) {
			continue
		}
		if e.folder == nil {
			if options.SelectSubscribed || options.SelectSpecialUse {
				continue
			}
			if err := w.WriteList(&imap.ListData{
				Attrs: []imap.MailboxAttr{imap.MailboxAttrNoSelect, imap.MailboxAttrHasChildren},
				Delim: '/', Mailbox: e.name,
			}); err != nil {
				return err
			}
			continue
		}
		su := specialUseAttr(e.folder.SpecialUse)
		if options.SelectSpecialUse && su == "" {
			continue
		}
		var attrs []imap.MailboxAttr
		switch {
		case isFixedFolder(e.folder.Key) && !hasChildren(e.name):
			// The fixed folders take no subfolders. Told so, Thunderbird
			// archives straight into Archive instead of creating a yearly
			// "Archive/2026" (which would become a label). Labels nest. A
			// fixed folder that already has a label listed under it (one
			// made before this) says so instead, rather than contradict it.
			attrs = append(attrs, imap.MailboxAttrNoInferiors)
		case hasChildren(e.name):
			attrs = append(attrs, imap.MailboxAttrHasChildren)
		default:
			attrs = append(attrs, imap.MailboxAttrHasNoChildren)
		}
		if options.ReturnSubscribed {
			attrs = append(attrs, imap.MailboxAttrSubscribed)
		}
		if su != "" {
			attrs = append(attrs, su)
		}
		if err := w.WriteList(&imap.ListData{Attrs: attrs, Delim: '/', Mailbox: e.name}); err != nil {
			return err
		}
	}
	return nil
}

func matchAny(name, ref string, patterns []string) bool {
	for _, p := range patterns {
		if imapserver.MatchList(name, '/', ref, p) {
			return true
		}
		if name == "INBOX" && imapserver.MatchList(name, '/', strings.ToUpper(ref), strings.ToUpper(p)) {
			return true
		}
	}
	return false
}

func (s *imapSession) Namespace() (*imap.NamespaceData, error) {
	return &imap.NamespaceData{Personal: []imap.NamespaceDescriptor{{Prefix: "", Delim: '/'}}}, nil
}

func (s *imapSession) Subscribe(mailbox string) error   { return nil }
func (s *imapSession) Unsubscribe(mailbox string) error { return nil }

func (s *imapSession) Create(mailbox string, options *imap.CreateOptions) error {
	name := strings.TrimSuffix(mailbox, "/")
	var res folderCreateResult
	if err := s.call("mail_folder_create", map[string]any{"name": name}, &res); err != nil {
		return s.imapError(err)
	}
	if findFolder(s.folders, res.Name) == nil {
		s.folders = append(s.folders, apiFolder{Key: res.Key, Name: res.Name})
	}
	logf("imap: folder created site=%s user=%s key=%s", s.site.Site, s.creds.Username, res.Key)
	return nil
}

func (s *imapSession) Delete(mailbox string) error {
	if err := s.call("mail_folder_delete", map[string]any{"name": strings.TrimSuffix(mailbox, "/")}, nil); err != nil {
		return s.imapError(err)
	}
	return s.refreshFolders()
}

func (s *imapSession) Rename(mailbox, newName string, options *imap.RenameOptions) error {
	if err := s.call("mail_folder_rename", map[string]any{"name": mailbox, "new_name": newName}, nil); err != nil {
		return s.imapError(err)
	}
	return s.refreshFolders()
}

func (s *imapSession) Status(mailbox string, options *imap.StatusOptions) (*imap.StatusData, error) {
	f, err := s.folderByName(mailbox)
	if err != nil {
		return nil, err
	}
	if f == nil {
		return nil, nonexistent(mailbox)
	}
	var res syncResult
	if err := s.call("mail_sync", map[string]any{"folder": f.Key, "status_only": true}, &res); err != nil {
		return nil, s.imapError(err)
	}
	data := &imap.StatusData{Mailbox: mailbox}
	if options.NumMessages {
		n := res.Exists
		data.NumMessages = &n
	}
	if options.UIDNext {
		data.UIDNext = imap.UID(res.UIDNext)
	}
	if options.UIDValidity {
		data.UIDValidity = res.UIDValidity
	}
	if options.NumUnseen {
		n := res.Unseen
		data.NumUnseen = &n
	}
	if options.NumRecent {
		zero := uint32(0)
		data.NumRecent = &zero
	}
	return data, nil
}

// ── adding a message ────────────────────────────────────────────────────

// No AppendLimit method on purpose: go-imap refuses a literal over that limit
// before reading it, and a non-synchronizing literal ({n+}, which Thunderbird
// uses) would then be read as commands. The size is checked here instead,
// from the literal's announced length; go-imap discards the bytes.

func (s *imapSession) Append(mailbox string, r imap.LiteralReader, options *imap.AppendOptions) (*imap.AppendData, error) {
	f, err := s.folderByName(mailbox)
	if err != nil {
		io.Copy(io.Discard, r)
		return nil, err
	}
	if f == nil {
		io.Copy(io.Discard, r)
		return nil, &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeTryCreate, Text: ascii("No folder named " + mailbox)}
	}
	if r.Size() > maxMessageBytes {
		return nil, &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeTooBig, Text: tooLargeText}
	}
	// The literal's size is known: reserve it, then read it in one buffer.
	release, err := reserve(r.Size())
	if err != nil {
		return nil, &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeLimit, Text: "The mail gateway is busy; try again shortly."}
	}
	defer release()
	raw := make([]byte, r.Size())
	if _, err := io.ReadFull(r, raw); err != nil {
		return nil, err
	}
	flags := []string{}
	for _, fl := range options.Flags {
		flags = append(flags, string(fl))
	}
	body := map[string]any{"folder": f.Key, "flags": flags}
	if !options.Time.IsZero() {
		body["date"] = options.Time.UTC().Format(time.RFC3339)
	}
	var res appendResult
	held, err := s.site.upstream.CallHold(callCtx(), s.creds, "mail_append", body, raw, false, &res)
	held.Done()
	raw = nil
	if err != nil {
		return nil, s.imapError(err)
	}
	s.g.cache.Invalidate(folderCacheKey(s.credKey, s.creds.Username, f.Key))
	if s.sel != nil && s.sel.folder.Key == f.Key {
		s.sel.forcePoll = true
	}
	if res.UID == nil {
		return nil, nil
	}
	return &imap.AppendData{UID: imap.UID(*res.UID), UIDValidity: res.UIDValidity}, nil
}

// ── selecting a folder ──────────────────────────────────────────────────

func (s *imapSession) Select(mailbox string, options *imap.SelectOptions) (*imap.SelectData, error) {
	s.sel.releaseList()
	s.sel = nil
	s.bodies.clear()
	f, err := s.folderByName(mailbox)
	if err != nil {
		return nil, err
	}
	if f == nil {
		return nil, nonexistent(mailbox)
	}
	// The listing stays reserved in the in-flight budget until it has been
	// turned into the compact list, which the folder-list budget then holds.
	var res syncResult
	held, err := s.site.upstream.CallHold(callCtx(), s.creds, "mail_sync", map[string]any{"folder": f.Key}, nil, true, &res)
	defer held.Done()
	if err != nil {
		return nil, s.imapError(err)
	}
	sel := &selected{
		folder:        *f,
		readOnly:      options.ReadOnly,
		uidValidity:   res.UIDValidity,
		uidNext:       res.UIDNext,
		highestModSeq: res.HighestModSeq,
		lastCheck:     time.Now(),
	}
	if err := sel.reserveFor(len(res.Messages)); err != nil {
		logf("imap: folder-list budget spent; SELECT refused site=%s user=%s folder=%s messages=%d",
			s.site.Site, s.creds.Username, f.Key, len(res.Messages))
		return nil, &imap.Error{Type: imap.StatusResponseTypeNo, Code: imap.ResponseCodeLimit, Text: "The mail gateway is busy; try again shortly."}
	}
	for _, m := range res.Messages {
		sel.msgs = append(sel.msgs, newMessage(m))
	}
	res.Messages = nil
	sort.Slice(sel.msgs, func(i, j int) bool { return sel.msgs[i].uid < sel.msgs[j].uid })
	s.sel = sel

	data := &imap.SelectData{
		Flags:          keptFlags,
		PermanentFlags: keptFlags,
		NumMessages:    uint32(len(sel.msgs)),
		UIDNext:        imap.UID(res.UIDNext),
		UIDValidity:    res.UIDValidity,
	}
	for i := range sel.msgs {
		if !sel.msgs[i].has(imap.FlagSeen) {
			data.FirstUnseenSeqNum = uint32(i + 1)
			break
		}
	}
	return data, nil
}

func (s *imapSession) Unselect() error {
	s.sel.releaseList()
	s.sel = nil
	s.bodies.clear()
	return nil
}

// isFixedFolder: INBOX, Sent, Drafts, Archive, Spam, Trash and All Mail (the
// MailAppFolders::FIXED keys), as opposed to a label.
func isFixedFolder(key string) bool {
	switch key {
	case "inbox", "sent", "archive", "spam", "trash", "all":
		return true
	}
	return strings.HasPrefix(key, "drafts:") || key == "drafts"
}
