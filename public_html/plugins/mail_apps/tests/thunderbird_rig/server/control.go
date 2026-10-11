package main

// Control endpoint (localhost only). Every change is a POST with query
// parameters; the GETs show the server's view.
//
//	POST /inject?folder=INBOX&from=..&subject=..&body=..&kb=N   add a message server-side (kb pads the body)
//	POST /lock?folder=INBOX&uid=N        a body FETCH of that UID answers NO [UNAVAILABLE]
//	POST /unlock?folder=INBOX[&uid=N]    no uid = unlock every UID in the folder
//	POST /notice?action=add|remove|rotate  the lock-notice message in INBOX
//	POST /flags?folder=..&uid=N&add=\Seen&remove=\Flagged   server-side flag change
//	POST /expunge?folder=..&uid=N        server-side expunge
//	POST /smtp?mode=ok|454|550&stage=mail|rcpt|data
//	POST /mark?text=..                   write a marker line into imap.log and smtp.log
//	GET  /state                          folders, UIDs, flags, locks, smtp mode
//	GET  /submissions                    SMTP submissions received

import (
	"encoding/json"
	"fmt"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"sync"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
	"github.com/emersion/go-imap/v2/imapserver/imapmemserver"
)

var admMu sync.Mutex

// withFolder runs f on a private server-side session with folder selected.
// Changes made through it are announced to every connected client (IDLE /
// NOOP) by go-imap's mailbox tracker.
func withFolder(folder string, f func(s *imapmemserver.UserSession) error) error {
	admMu.Lock()
	defer admMu.Unlock()
	s := imapmemserver.NewUserSession(memUser)
	if _, err := s.Select(folder, &imap.SelectOptions{}); err != nil {
		return err
	}
	defer s.Unselect()
	return f(s)
}

func storeFlags(folder string, uid imap.UID, op imap.StoreFlagsOp, flags []imap.Flag) error {
	return withFolder(folder, func(s *imapmemserver.UserSession) error {
		rig.bump()
		return s.Store(nil, imap.UIDSetNum(uid), &imap.StoreFlags{Op: op, Silent: true, Flags: flags}, nil)
	})
}

func expungeUID(folder string, uid imap.UID) error {
	if err := storeFlags(folder, uid, imap.StoreFlagsAdd, []imap.Flag{imap.FlagDeleted}); err != nil {
		return err
	}
	return withFolder(folder, func(s *imapmemserver.UserSession) error {
		rig.bump()
		set := imap.UIDSetNum(uid)
		return s.Expunge(nil, &set)
	})
}

var stateFlags = []imap.Flag{imap.FlagSeen, imap.FlagFlagged, imap.FlagAnswered, imap.FlagDeleted, imap.FlagDraft,
	"$Forwarded", "$Junk", "$NotJunk", "Junk", "NonJunk", "$label1", "$label2", "$MDNSent"}

func folderState(folder string) (map[string]interface{}, error) {
	out := map[string]interface{}{}
	err := withFolder(folder, func(s *imapmemserver.UserSession) error {
		all, err := s.Search(imapserver.NumKindUID, &imap.SearchCriteria{}, &imap.SearchOptions{ReturnAll: true})
		if err != nil {
			return err
		}
		uids, _ := all.All.(imap.UIDSet).Nums()
		flags := map[imap.UID][]string{}
		for _, u := range uids {
			flags[u] = []string{}
		}
		for _, fl := range stateFlags {
			res, err := s.Search(imapserver.NumKindUID, &imap.SearchCriteria{Flag: []imap.Flag{fl}}, &imap.SearchOptions{ReturnAll: true})
			if err != nil {
				return err
			}
			if us, ok := res.All.(imap.UIDSet); ok {
				nums, _ := us.Nums()
				for _, u := range nums {
					flags[u] = append(flags[u], string(fl))
				}
			}
		}
		msgs := []string{}
		for _, u := range uids {
			lock := ""
			if rig.isLocked(folder, u) {
				lock = " LOCKED"
			}
			msgs = append(msgs, fmt.Sprintf("%d (%s)%s", u, strings.Join(flags[u], " "), lock))
		}
		out["messages"] = msgs
		return nil
	})
	if err != nil {
		return nil, err
	}
	st, _ := memUser.Status(folder, &imap.StatusOptions{UIDNext: true, NumMessages: true})
	if st != nil {
		out["uidnext"] = st.UIDNext
	}
	return out, nil
}

func registerControl(mux *http.ServeMux, lg *logFile, marks ...*logFile) {
	reply := func(w http.ResponseWriter, v interface{}, err error) {
		w.Header().Set("Content-Type", "application/json")
		if err != nil {
			w.WriteHeader(400)
			v = map[string]string{"error": err.Error()}
		}
		json.NewEncoder(w).Encode(v)
	}
	post := func(path string, h func(r *http.Request) (interface{}, error)) {
		mux.HandleFunc(path, func(w http.ResponseWriter, r *http.Request) {
			if r.Method != http.MethodPost {
				http.Error(w, "POST only", 405)
				return
			}
			v, err := h(r)
			lg.Printf("%s %s -> %v err=%v", r.URL.Path, r.URL.RawQuery, v, err)
			reply(w, v, err)
		})
	}
	q := func(r *http.Request, k, def string) string {
		if v := r.URL.Query().Get(k); v != "" {
			return v
		}
		return def
	}
	quid := func(r *http.Request) (imap.UID, error) {
		n, err := strconv.ParseUint(r.URL.Query().Get("uid"), 10, 32)
		return imap.UID(n), err
	}

	post("/mark", func(r *http.Request) (interface{}, error) {
		for _, m := range marks {
			m.Printf("=============== MARK: %s ===============", r.URL.Query().Get("text"))
		}
		return map[string]string{"ok": "marked"}, nil
	})
	post("/inject", func(r *http.Request) (interface{}, error) {
		body := q(r, "body", "Injected server-side.")
		if kb, _ := strconv.Atoi(r.URL.Query().Get("kb")); kb > 0 { // pad to ~kb KB of text
			line := strings.Repeat("0123456789abcdef", 4) + "\n"
			body += "\n" + strings.Repeat(line, kb*1024/len(line))
		}
		raw := plainMsg(q(r, "from", "Injected <injected@example.org>"), q(r, "subject", "Injected message"), body, "")
		return map[string]interface{}{"uid": appendMsg(q(r, "folder", "INBOX"), raw, nil)}, nil
	})
	post("/lock", func(r *http.Request) (interface{}, error) {
		uid, err := quid(r)
		if err != nil {
			return nil, err
		}
		folder := q(r, "folder", "INBOX")
		rig.mu.Lock()
		if rig.lockedUIDs[folder] == nil {
			rig.lockedUIDs[folder] = map[imap.UID]bool{}
		}
		rig.lockedUIDs[folder][uid] = true
		rig.mu.Unlock()
		return map[string]interface{}{"locked": uid}, nil
	})
	post("/unlock", func(r *http.Request) (interface{}, error) {
		folder := q(r, "folder", "INBOX")
		rig.mu.Lock()
		defer rig.mu.Unlock()
		if r.URL.Query().Get("uid") == "" {
			delete(rig.lockedUIDs, folder)
		} else if uid, err := quid(r); err == nil {
			delete(rig.lockedUIDs[folder], uid)
		}
		return map[string]string{"ok": "unlocked"}, nil
	})
	post("/notice", func(r *http.Request) (interface{}, error) {
		action := q(r, "action", "rotate")
		rig.mu.Lock()
		old := rig.noticeUID
		rig.mu.Unlock()
		res := map[string]interface{}{"old_uid": old}
		if old != 0 && (action == "remove" || action == "rotate") {
			if err := expungeUID("INBOX", old); err != nil {
				return nil, err
			}
			rig.mu.Lock()
			rig.noticeUID = 0
			rig.mu.Unlock()
		}
		if action == "add" || action == "rotate" {
			uid := appendMsg("INBOX", noticeMsg(), nil)
			rig.mu.Lock()
			rig.noticeUID = uid
			rig.mu.Unlock()
			res["new_uid"] = uid
		}
		return res, nil
	})
	post("/flags", func(r *http.Request) (interface{}, error) {
		uid, err := quid(r)
		if err != nil {
			return nil, err
		}
		folder := q(r, "folder", "INBOX")
		for _, pair := range []struct {
			key string
			op  imap.StoreFlagsOp
		}{{"add", imap.StoreFlagsAdd}, {"remove", imap.StoreFlagsDel}} {
			var fl []imap.Flag
			for _, f := range strings.Fields(r.URL.Query().Get(pair.key)) {
				fl = append(fl, imap.Flag(f))
			}
			if len(fl) > 0 {
				if err := storeFlags(folder, uid, pair.op, fl); err != nil {
					return nil, err
				}
			}
		}
		return folderState(folder)
	})
	post("/expunge", func(r *http.Request) (interface{}, error) {
		uid, err := quid(r)
		if err != nil {
			return nil, err
		}
		return map[string]interface{}{"expunged": uid}, expungeUID(q(r, "folder", "INBOX"), uid)
	})
	post("/smtp", func(r *http.Request) (interface{}, error) {
		rig.mu.Lock()
		defer rig.mu.Unlock()
		rig.smtpMode = q(r, "mode", "ok")
		rig.smtpStage = q(r, "stage", "data")
		return map[string]string{"mode": rig.smtpMode, "stage": rig.smtpStage}, nil
	})
	mux.HandleFunc("/state", func(w http.ResponseWriter, r *http.Request) {
		foldersMu.Lock()
		names := []string{}
		for n := range folders {
			names = append(names, n)
		}
		foldersMu.Unlock()
		sort.Strings(names)
		out := map[string]interface{}{}
		for _, n := range names {
			st, err := folderState(n)
			if err != nil {
				out[n] = err.Error()
				continue
			}
			out[n] = st
		}
		rig.mu.Lock()
		out["_smtp"] = rig.smtpMode + " at " + rig.smtpStage
		out["_notice_uid"] = rig.noticeUID
		out["_highestmodseq"] = rig.modseq
		rig.mu.Unlock()
		reply(w, out, nil)
	})
	mux.HandleFunc("/submissions", func(w http.ResponseWriter, r *http.Request) {
		rig.mu.Lock()
		defer rig.mu.Unlock()
		reply(w, rig.submissions, nil)
	})
}
