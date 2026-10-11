// rigserver — a throwaway IMAP + SMTP-submission server for measuring how a
// real Thunderbird behaves (mail_apps spec, Phase 0). In-memory only; nothing
// is written to disk except the protocol logs.
//
//	IMAP        go-imap v2 imapmemserver, behind a line shim (shim.go) that
//	            logs every protocol line, answers ID, optionally emulates
//	            CONDSTORE and COMPRESS=DEFLATE.
//	SMTP        go-smtp submission server (smtp.go).
//	control     HTTP on localhost (control.go): inject mail, lock a UID,
//	            rotate the lock notice, change flags, set SMTP failure mode.
package main

import (
	"bytes"
	"crypto/tls"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapserver"
	"github.com/emersion/go-imap/v2/imapserver/imapmemserver"
)

var (
	flagIMAP      = flag.String("imap", "127.0.0.1:1143", "IMAP listen address (plaintext unless -tlscert)")
	flagSMTP      = flag.String("smtp", "127.0.0.1:1587", "SMTP submission listen address (plaintext unless -tlscert)")
	flagControl   = flag.String("control", "127.0.0.1:1880", "control HTTP listen address")
	flagUser      = flag.String("user", "rig@example.test", "the one mailbox user")
	flagPassFile  = flag.String("passfile", "", "file holding the user's throwaway password (required)")
	flagLogDir    = flag.String("logdir", ".", "directory for imap.log / smtp.log / control.log")
	flagCondstore = flag.Bool("condstore", true, "advertise CONDSTORE and emulate it (global HIGHESTMODSEQ, CHANGEDSINCE answered as a superset)")
	flagCompress  = flag.Bool("compress", false, "advertise and implement COMPRESS=DEFLATE")
	flagID        = flag.Bool("id", true, "advertise and answer ID (go-imap v2 has no ID support; the shim answers it)")
	flagPermFlags = flag.String("permflags", `\Seen \Flagged \Answered \Deleted \Draft $Forwarded`,
		`PERMANENTFLAGS to announce on SELECT; "*" means go-imap's default (all flags + \*)`)
	flagSelectFlags = flag.String("selectflags", "", `override the untagged FLAGS list on SELECT (e.g. add "$Junk $NotJunk", which makes Thunderbird STORE $Junk/$NotJunk without \* in PERMANENTFLAGS)`)
	flagNoInferiors = flag.String("noinferiors", "", `comma-separated folders LIST marks \Noinferiors (e.g. "Archive" makes Thunderbird archive flat instead of into Archive/<year>)`)
	flagTLSCert     = flag.String("tlscert", "", "PEM certificate: serve IMAP and SMTP with implicit TLS (as 993/465 would) instead of plaintext")
	flagTLSKey      = flag.String("tlskey", "", "PEM key for -tlscert")
	flagBigKB       = flag.Int("bigkb", 180, "size in KB of the seeded large message (to observe chunked fetches)")
)

// Folder registry: name -> SPECIAL-USE attribute. go-imap's memserver
// ignores CREATE's special-use option and only reports special-use on
// RETURN (SPECIAL-USE), so the rig keeps its own list for LIST.
var (
	foldersMu sync.Mutex
	folders   = map[string]imap.MailboxAttr{
		"INBOX":    "",
		"Sent":     imap.MailboxAttrSent,
		"Drafts":   imap.MailboxAttrDrafts,
		"Archive":  imap.MailboxAttrArchive,
		"Spam":     imap.MailboxAttrJunk,
		"Trash":    imap.MailboxAttrTrash,
		"Projects": "", // stands in for a Joinery label folder
	}
)

var (
	memUser *imapmemserver.User
	rig     = &rigState{}
)

// rigState holds the knobs the control endpoint turns.
type rigState struct {
	mu          sync.Mutex
	lockedUIDs  map[string]map[imap.UID]bool // folder -> uid -> locked
	noticeUID   imap.UID                     // current lock-notice UID in INBOX (0 = none)
	smtpMode    string                       // ok | 454 | 550
	smtpStage   string                       // mail | rcpt | data
	modseq      uint64                       // global HIGHESTMODSEQ for CONDSTORE emulation
	submissions []submission
}

func (r *rigState) bump() {
	r.mu.Lock()
	r.modseq++
	r.mu.Unlock()
}

func (r *rigState) highestModSeq() uint64 {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.modseq
}

func (r *rigState) isLocked(folder string, uid imap.UID) bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.lockedUIDs[folder][uid]
}

func main() {
	flag.Parse()
	if *flagPassFile == "" {
		log.Fatal("-passfile is required")
	}
	pw, err := os.ReadFile(*flagPassFile)
	if err != nil {
		log.Fatal(err)
	}
	password := strings.TrimSpace(string(pw))
	rig.lockedUIDs = map[string]map[imap.UID]bool{}
	rig.smtpMode, rig.smtpStage, rig.modseq = "ok", "data", 1

	if err := os.MkdirAll(*flagLogDir, 0o755); err != nil {
		log.Fatal(err)
	}
	imapLog := openLog("imap.log")
	smtpLog := openLog("smtp.log")
	ctlLog := openLog("control.log")

	memUser = imapmemserver.NewUser(*flagUser, password)
	for name := range folders {
		must(memUser.Create(name, nil)) // memserver's NewUser has no INBOX either
		must(memUser.Subscribe(name))
	}
	seed()

	caps := imap.CapSet{
		imap.CapIMAP4rev1: {}, imap.CapNamespace: {}, imap.CapUIDPlus: {}, imap.CapMove: {},
		imap.CapSpecialUse: {}, imap.CapLiteralPlus: {}, imap.CapChildren: {},
	}
	srv := imapserver.New(&imapserver.Options{
		NewSession: func(*imapserver.Conn) (imapserver.Session, *imapserver.GreetingData, error) {
			return &rigSession{password: password}, nil, nil
		},
		Caps:         caps,
		InsecureAuth: true,
		Logger:       log.New(imapLog, "go-imap: ", log.LstdFlags),
	})
	var extra []string
	if *flagID {
		extra = append(extra, "ID")
	}
	if *flagCondstore {
		extra = append(extra, "CONDSTORE")
	}
	if *flagCompress {
		extra = append(extra, "COMPRESS=DEFLATE")
	}
	ln, err := net.Listen("tcp", *flagIMAP)
	if err != nil {
		log.Fatal(err)
	}
	var tlsConfig *tls.Config
	if *flagTLSCert != "" {
		cert, err := tls.LoadX509KeyPair(*flagTLSCert, *flagTLSKey)
		if err != nil {
			log.Fatal(err)
		}
		tlsConfig = &tls.Config{Certificates: []tls.Certificate{cert}}
		ln = tls.NewListener(ln, tlsConfig) // the shim then logs the decrypted lines
	}
	inner := newPipeListener()
	go func() { log.Fatal(srv.Serve(inner)) }()
	go serveShim(ln, inner, imapLog, extra)

	go serveSMTP(*flagSMTP, password, smtpLog, tlsConfig)

	mux := http.NewServeMux()
	registerControl(mux, ctlLog, imapLog, smtpLog)
	log.Printf("rigserver: IMAP %s  SMTP %s  control http://%s  extra caps %v  logs in %s",
		*flagIMAP, *flagSMTP, *flagControl, extra, *flagLogDir)
	log.Fatal(http.ListenAndServe(*flagControl, mux))
}

// logFile is a timestamped, goroutine-safe log sink.
type logFile struct {
	mu sync.Mutex
	f  *os.File
}

func openLog(name string) *logFile {
	f, err := os.OpenFile(*flagLogDir+"/"+name, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	if err != nil {
		log.Fatal(err)
	}
	return &logFile{f: f}
}

func (l *logFile) Write(p []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.f.Write(p)
}

func (l *logFile) Printf(format string, args ...interface{}) {
	l.mu.Lock()
	defer l.mu.Unlock()
	fmt.Fprintf(l.f, "%s %s\n", time.Now().UTC().Format("15:04:05.000Z"), fmt.Sprintf(format, args...))
}

func must(err error) {
	if err != nil {
		log.Fatal(err)
	}
}

// ---- seed mail ----

func appendMsg(folder string, raw []byte, flags []imap.Flag) imap.UID {
	data, err := memUser.Append(folder, bytes.NewReader(raw), &imap.AppendOptions{Flags: flags, Time: time.Now()})
	must(err)
	rig.bump()
	return data.UID
}

func crlf(s string) []byte {
	return []byte(strings.ReplaceAll(strings.ReplaceAll(s, "\r\n", "\n"), "\n", "\r\n"))
}

func plainMsg(from, subject, body string, extraHeaders string) []byte {
	id := fmt.Sprintf("<%d.%s@example.test>", time.Now().UnixNano(), strings.ReplaceAll(strings.ToLower(subject), " ", "-"))
	return crlf(fmt.Sprintf("From: %s\nTo: %s\nSubject: %s\nDate: %s\nMessage-ID: %s\nMIME-Version: 1.0\n%sContent-Type: text/plain; charset=utf-8\nContent-Transfer-Encoding: 7bit\n\n%s\n",
		from, *flagUser, subject, time.Now().Format(time.RFC1123Z), id, extraHeaders, body))
}

func seed() {
	appendMsg("INBOX", plainMsg("Alice <alice@example.org>", "Welcome to the rig", "Plain text message one.", ""), nil)
	appendMsg("INBOX", plainMsg("Bob <bob@example.org>", "Lunch on Friday", "Plain text message two.", ""), []imap.Flag{imap.FlagSeen})
	// multipart/alternative
	appendMsg("INBOX", crlf(fmt.Sprintf(`From: Carol <carol@example.org>
To: %s
Subject: HTML newsletter
Date: %s
Message-ID: <html-1@example.org>
MIME-Version: 1.0
Content-Type: multipart/alternative; boundary="b1"

--b1
Content-Type: text/plain; charset=utf-8

Plain alternative.
--b1
Content-Type: text/html; charset=utf-8

<p>HTML <b>alternative</b>.</p>
--b1--
`, *flagUser, time.Now().Format(time.RFC1123Z))), nil)
	// large message with an attachment, to observe chunked BODY[]<offset.length>
	var att strings.Builder
	line := strings.Repeat("QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVo", 3)[:76]
	for att.Len() < *flagBigKB*1024 {
		att.WriteString(line + "\n")
	}
	appendMsg("INBOX", crlf(fmt.Sprintf(`From: Dave <dave@example.org>
To: %s
Subject: Big attachment
Date: %s
Message-ID: <big-1@example.org>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="b2"

--b2
Content-Type: text/plain; charset=utf-8

See attached.
--b2
Content-Type: application/octet-stream; name="blob.bin"
Content-Disposition: attachment; filename="blob.bin"
Content-Transfer-Encoding: base64

%s--b2--
`, *flagUser, time.Now().Format(time.RFC1123Z), att.String())), nil)
	appendMsg("INBOX", plainMsg("Erin <erin@example.org>", "Will be locked", "If you can read this, the lock was lifted and the real body arrived.", ""), nil)
	appendMsg("Archive", plainMsg("Frank <frank@example.org>", "Old archived thread", "Archived body.", ""), []imap.Flag{imap.FlagSeen})
	appendMsg("Projects", plainMsg("Grace <grace@example.org>", "Labelled message", "Label folder body.", ""), nil)
	appendMsg("Sent", plainMsg(*flagUser, "Something I sent earlier", "Sent body.", ""), []imap.Flag{imap.FlagSeen})
	appendMsg("Spam", plainMsg("Spammer <spam@example.net>", "You won", "Spam body.", ""), nil)
}

func noticeMsg() []byte {
	// Content never changes (spec § Locked behaviour rule 4); only the Message-ID differs per numbering.
	return plainMsg("Joinery <no-reply@example.test>", "Your mailbox is locked",
		"Your mailbox is locked. New mail is waiting. Unlock it at https://example.test/unlock", "")
}
