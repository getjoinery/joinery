package main

// A fake site: enough of the mail_apps/mail_* actions, in memory, to drive a
// real IMAP and SMTP session through the gateway. It answers in the site's
// envelope (api/apiv1.php): 200 {"data":...}, 422 ActionError with
// {imap_code, smtp_code}, 401 AuthenticationError.

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

type fakeMsg struct {
	uid      uint32
	flags    map[string]bool
	modseq   uint64
	date     time.Time
	raw      []byte
	locked   bool
	expunged bool
}

type fakeFolder struct {
	key, name   string
	special     *string
	uidvalidity uint32
	uidnext     uint32
	modseq      uint64
	msgs        []*fakeMsg
}

type fakeSite struct {
	mu        sync.Mutex
	mailbox   string
	password  string
	folders   []*fakeFolder
	submitted [][]byte
	refuseAll string        // when set, mail_submit refuses with this smtp_code
	budget    int           // mail_fetch what=full: stop after this many bytes (0: no limit), like the site's 12 MB
	password2 string        // a second app password of the same person (another device)
	slowCalls time.Duration // mail_changes and mail_sync take this long
	calls     map[string]int
	nextLabel int
	lastIP    string
}

func strp(s string) *string { return &s }

func newFakeSite() *fakeSite {
	f := &fakeSite{mailbox: "ann@example.test", password: "jm-abcd-efgh", calls: map[string]int{}, nextLabel: 100}
	mk := func(key, name string, special *string) *fakeFolder {
		return &fakeFolder{key: key, name: name, special: special, uidvalidity: 1700000000, uidnext: 1, modseq: 1}
	}
	f.folders = []*fakeFolder{
		mk("inbox", "INBOX", nil),
		mk("sent", "Sent", strp(`\Sent`)),
		mk("drafts:7", "Drafts", strp(`\Drafts`)),
		mk("archive", "Archive", strp(`\Archive`)),
		mk("spam", "Spam", strp(`\Junk`)),
		mk("trash", "Trash", strp(`\Trash`)),
		mk("all", "All Mail", strp(`\All`)),
		mk("label:5", "Labels/Inbox", nil),
		mk("label:6", "Projects", nil),
	}
	return f
}

func testMessage(subject, from, body string) []byte {
	return []byte("From: " + from + "\r\nTo: ann@example.test\r\nSubject: " + subject +
		"\r\nDate: Mon, 05 Oct 2026 10:00:00 +0000\r\nMessage-ID: <" + strings.ReplaceAll(strings.ToLower(subject), " ", ".") + "@example.test>" +
		"\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"b1\"\r\n\r\n" +
		"--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n" + body + "\r\n" +
		"--b1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>" + body + "</p>\r\n--b1--\r\n")
}

// add puts a message in a folder, numbered (it must be called with mu held or before serving).
func (f *fakeFolder) add(raw []byte, flags []string, locked bool) *fakeMsg {
	f.modseq++
	m := &fakeMsg{uid: f.uidnext, flags: map[string]bool{}, modseq: f.modseq, date: time.Date(2026, 10, 5, 10, 0, 0, 0, time.UTC), raw: raw, locked: locked}
	for _, fl := range flags {
		m.flags[fl] = true
	}
	f.uidnext++
	f.msgs = append(f.msgs, m)
	return m
}

func (f *fakeSite) folder(key string) *fakeFolder {
	for _, fo := range f.folders {
		if fo.key == key {
			return fo
		}
	}
	return nil
}

func (f *fakeSite) Add(folder string, raw []byte, flags []string, locked bool) uint32 {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.folder(folder).add(raw, flags, locked).uid
}

func (f *fakeSite) Calls(action string) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.calls[action]
}

func (f *fakeFolder) live() []*fakeMsg {
	var out []*fakeMsg
	for _, m := range f.msgs {
		if !m.expunged {
			out = append(out, m)
		}
	}
	return out
}

func (f *fakeFolder) maxUID() uint32 {
	var max uint32
	for _, m := range f.live() {
		if m.uid > max {
			max = m.uid
		}
	}
	return max
}

// uidSet mirrors MailAppAction::uidSet: the live numbers a set names.
func (f *fakeFolder) uidSet(set string) ([]*fakeMsg, bool) {
	if set == "" {
		return nil, false
	}
	max := f.maxUID()
	var out []*fakeMsg
	for _, m := range f.live() {
		for _, piece := range strings.Split(set, ",") {
			ends := strings.Split(piece, ":")
			conv := func(s string) uint32 {
				if s == "*" {
					return max
				}
				n, _ := strconv.Atoi(s)
				return uint32(n)
			}
			a := conv(ends[0])
			b := a
			if len(ends) > 1 {
				b = conv(ends[1])
			}
			if a > b {
				a, b = b, a
			}
			if m.uid >= a && m.uid <= b {
				out = append(out, m)
				break
			}
		}
	}
	return out, true
}

func flagList(m *fakeMsg) []string {
	out := []string{}
	for fl, on := range m.flags {
		if on {
			out = append(out, fl)
		}
	}
	sort.Strings(out)
	return out
}

func apiMsg(m *fakeMsg) map[string]any {
	return map[string]any{"uid": m.uid, "flags": flagList(m), "modseq": m.modseq,
		"date": m.date.Format(time.RFC3339), "size": len(m.raw)}
}

func (f *fakeSite) foldersJSON() []map[string]any {
	var out []map[string]any
	for _, fo := range f.folders {
		out = append(out, map[string]any{"key": fo.key, "name": fo.name, "special_use": fo.special})
	}
	return out
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(v)
}

func refuse(w http.ResponseWriter, msg, imapCode string, smtpCode *string) {
	writeJSON(w, 422, map[string]any{"api_version": "1.0", "errortype": "ActionError", "error": msg,
		"data": map[string]any{"imap_code": imapCode, "smtp_code": smtpCode}})
}

func (f *fakeSite) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	const prefix = "/api/v1/action/mail_apps/"
	if r.Method != http.MethodPost || !strings.HasPrefix(r.URL.Path, prefix) {
		writeJSON(w, 404, map[string]any{"errortype": "ActionError", "error": "Unknown action"})
		return
	}
	action := strings.TrimPrefix(r.URL.Path, prefix)
	secret := r.Header.Get("X-Joinery-App-Secret")
	if r.Header.Get("X-Joinery-App-Username") != f.mailbox || (secret != f.password && (f.password2 == "" || secret != f.password2)) {
		writeJSON(w, 401, map[string]any{"errortype": "AuthenticationError", "error": "Incorrect username or app password"})
		return
	}
	var in map[string]any
	if err := json.NewDecoder(r.Body).Decode(&in); err != nil {
		writeJSON(w, 400, map[string]any{"errortype": "ValidationError", "error": "bad json"})
		return
	}
	str := func(k string) string { s, _ := in[k].(string); return s }
	if str("mailbox") != f.mailbox {
		refuse(w, "This app password cannot open "+str("mailbox")+".", "AUTHORIZATIONFAILED", nil)
		return
	}

	f.mu.Lock()
	slow := f.slowCalls
	f.mu.Unlock()
	if slow > 0 && (action == "mail_changes" || action == "mail_sync") {
		time.Sleep(slow)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls[action]++
	f.lastIP = r.Header.Get("X-Joinery-Client-Ip")
	ok := func(data any) {
		writeJSON(w, 200, map[string]any{"api_version": "1.0", "success_message": "ok", "data": data})
	}

	var fo *fakeFolder
	if k := str("folder"); k != "" {
		fo = f.folder(k)
		if fo == nil {
			refuse(w, "That folder no longer exists.", "NONEXISTENT", nil)
			return
		}
	}

	switch action {
	case "mail_login":
		ok(map[string]any{"mailbox": f.mailbox, "level": "standard", "locked": false,
			"unlock_url": "https://mail.example.test/profile/mailbox/mailbox", "folders": f.foldersJSON()})
	case "mail_folders":
		ok(map[string]any{"folders": f.foldersJSON()})
	case "mail_sync":
		live := fo.live()
		unseen := 0
		for _, m := range live {
			if !m.flags[`\Seen`] {
				unseen++
			}
		}
		out := map[string]any{"folder": fo.key, "uidvalidity": fo.uidvalidity, "uidnext": fo.uidnext,
			"highest_modseq": fo.modseq, "exists": len(live), "unseen": unseen, "locked": false, "waiting": false}
		if st, _ := in["status_only"].(bool); !st {
			var since uint64
			hasSince := false
			if v, okv := in["since_modseq"].(float64); okv {
				since, hasSince = uint64(v), true
			}
			msgs := []map[string]any{}
			vanished := []uint32{}
			for _, m := range fo.msgs {
				if hasSince && m.modseq <= since {
					continue
				}
				if m.expunged {
					if hasSince {
						vanished = append(vanished, m.uid)
					}
					continue
				}
				msgs = append(msgs, apiMsg(m))
			}
			out["messages"] = msgs
			out["vanished"] = vanished
		}
		ok(out)
	case "mail_changes":
		known, _ := in["known_modseq"].(float64)
		ok(map[string]any{"folder": fo.key, "uidvalidity": fo.uidvalidity, "uidnext": fo.uidnext,
			"highest_modseq": fo.modseq, "exists": len(fo.live()), "changed": fo.modseq != uint64(known), "locked": false})
	case "mail_fetch":
		msgs, valid := fo.uidSet(str("uids"))
		if !valid {
			refuse(w, "That message set is not one I can read.", "CLIENTBUG", nil)
			return
		}
		out := []map[string]any{}
		more := []uint32{}
		spent := 0
		// A number named on its own that is no longer live is answered gone.
		live := map[uint32]bool{}
		for _, m := range msgs {
			live[m.uid] = true
		}
		for _, piece := range strings.Split(str("uids"), ",") {
			if n, err := strconv.Atoi(piece); err == nil && !live[uint32(n)] {
				out = append(out, map[string]any{"uid": n, "error": "gone"})
			}
		}
		for _, m := range msgs {
			if f.budget > 0 && spent > f.budget && len(out) > 0 {
				more = append(more, m.uid)
				continue
			}
			if m.locked {
				out = append(out, map[string]any{"uid": m.uid, "error": "locked"})
				continue
			}
			if str("what") == "full" {
				spent += len(m.raw)
				out = append(out, map[string]any{"uid": m.uid, "size": len(m.raw), "bytes": base64.StdEncoding.EncodeToString(m.raw)})
			} else {
				h := m.raw[:headerEnd(m.raw)]
				out = append(out, map[string]any{"uid": m.uid, "size": len(m.raw), "header": base64.StdEncoding.EncodeToString(h)})
			}
		}
		ok(map[string]any{"folder": fo.key, "messages": out, "more": more, "unlock_url": "https://mail.example.test/profile/mailbox/mailbox"})
	case "mail_store":
		msgs, _ := fo.uidSet(str("uids"))
		var flags []string
		if arr, okv := in["flags"].([]any); okv {
			for _, x := range arr {
				flags = append(flags, x.(string))
			}
		}
		op := str("op")
		out := []map[string]any{}
		for _, m := range msgs {
			before := strings.Join(flagList(m), " ")
			if op == "set" {
				for k := range m.flags {
					delete(m.flags, k)
				}
			}
			for _, fl := range flags {
				m.flags[fl] = op != "remove"
				if op == "remove" {
					delete(m.flags, fl)
				}
			}
			if strings.Join(flagList(m), " ") != before {
				fo.modseq++
				m.modseq = fo.modseq
			}
			out = append(out, map[string]any{"uid": m.uid, "flags": flagList(m), "modseq": m.modseq})
		}
		ok(map[string]any{"folder": fo.key, "messages": out, "highest_modseq": fo.modseq, "expunged": []uint32{}})
	case "mail_copy", "mail_move":
		to := f.folder(str("to"))
		if to == nil {
			refuse(w, "That folder no longer exists.", "NONEXISTENT", nil)
			return
		}
		msgs, _ := fo.uidSet(str("uids"))
		pairs := [][2]uint32{}
		for _, m := range msgs {
			n := to.add(m.raw, flagList(m), m.locked)
			pairs = append(pairs, [2]uint32{m.uid, n.uid})
			if action == "mail_move" {
				fo.modseq++
				m.modseq = fo.modseq
				m.expunged = true
			}
		}
		ok(map[string]any{"folder": fo.key, "to": to.key, "uid_map": pairs, "dest_uidvalidity": to.uidvalidity})
	case "mail_expunge":
		var only map[uint32]bool
		if s := str("uids"); s != "" {
			msgs, _ := fo.uidSet(s)
			only = map[uint32]bool{}
			for _, m := range msgs {
				only[m.uid] = true
			}
		}
		gone := []uint32{}
		for _, m := range fo.live() {
			if m.flags[`\Deleted`] && (only == nil || only[m.uid]) {
				fo.modseq++
				m.modseq = fo.modseq
				m.expunged = true
				gone = append(gone, m.uid)
			}
		}
		ok(map[string]any{"folder": fo.key, "expunged": gone, "highest_modseq": fo.modseq})
	case "mail_append":
		raw, err := base64.StdEncoding.DecodeString(str("message"))
		if err != nil {
			refuse(w, "That message did not arrive whole.", "CLIENTBUG", nil)
			return
		}
		var flags []string
		if arr, okv := in["flags"].([]any); okv {
			for _, x := range arr {
				flags = append(flags, x.(string))
			}
		}
		m := fo.add(raw, flags, false)
		ok(map[string]any{"folder": fo.key, "uid": m.uid, "uidvalidity": fo.uidvalidity})
	case "mail_folder_create":
		name := str("name")
		for _, x := range f.folders {
			if strings.EqualFold(x.name, name) {
				refuse(w, "That folder already exists.", "ALREADYEXISTS", nil)
				return
			}
		}
		f.nextLabel++
		nf := &fakeFolder{key: fmt.Sprintf("label:%d", f.nextLabel), name: name, uidvalidity: 1700000001, uidnext: 1, modseq: 1}
		f.folders = append(f.folders, nf)
		ok(map[string]any{"key": nf.key, "name": nf.name})
	case "mail_folder_delete":
		for i, x := range f.folders {
			if x.name == str("name") {
				if !strings.HasPrefix(x.key, "label:") {
					refuse(w, x.name+" cannot be deleted.", "CANNOT", nil)
					return
				}
				f.folders = append(f.folders[:i], f.folders[i+1:]...)
				ok(map[string]any{"deleted": x.key})
				return
			}
		}
		refuse(w, "That folder does not exist.", "NONEXISTENT", nil)
	case "mail_folder_rename":
		refuse(w, "Folders cannot be renamed yet. Create a new one and move the mail into it.", "CANNOT", nil)
	case "mail_search":
		field, value := str("field"), strings.ToLower(str("value"))
		uids := []uint32{}
		for _, m := range fo.live() {
			hay := strings.ToLower(string(m.raw))
			if field != "text" && field != "body" {
				hay = ""
				for _, line := range strings.Split(string(m.raw[:headerEnd(m.raw)]), "\r\n") {
					if strings.HasPrefix(strings.ToLower(line), field+":") {
						hay = strings.ToLower(line)
					}
				}
			}
			if strings.Contains(hay, value) {
				uids = append(uids, m.uid)
			}
		}
		ok(map[string]any{"folder": fo.key, "uids": uids})
	case "mail_submit":
		raw, err := base64.StdEncoding.DecodeString(str("message"))
		if err != nil || len(raw) == 0 {
			refuse(w, "That message did not arrive whole.", "CLIENTBUG", strp("554 5.6.0"))
			return
		}
		if f.refuseAll != "" {
			refuse(w, "Mailbox locked — unlock at https://mail.example.test/profile/mailbox/mailbox", "UNAVAILABLE", strp(f.refuseAll))
			return
		}
		if !bytes.Contains(raw, []byte("From: "+f.mailbox)) {
			refuse(w, "This message is not from "+f.mailbox+", so it cannot be sent as this mailbox.", "CANNOT", strp("550 5.7.1"))
			return
		}
		f.submitted = append(f.submitted, raw)
		f.folder("sent").add(raw, []string{`\Seen`}, false)
		ok(map[string]any{"message_id": "<x@example.test>", "stored": true})
	default:
		writeJSON(w, 404, map[string]any{"errortype": "ActionError", "error": "Unknown action"})
	}
}
