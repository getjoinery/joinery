package main

import (
	"bytes"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"net"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"
	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"
)

const testHost = "mail.example.test"

type harness struct {
	site     *fakeSite
	imapAddr string
	smtpAddr string
	gw       *Gateway
	dir      string
	upURL    string
	certPath string
	keyPath  string
}

func writeTestCert(t *testing.T, dir string) (string, string) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: testHost},
		DNSNames:     []string{testHost},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(time.Hour),
		KeyUsage:     x509.KeyUsageDigitalSignature,
		ExtKeyUsage:  []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	kb, _ := x509.MarshalECPrivateKey(key)
	certPath := filepath.Join(dir, "cert.pem")
	keyPath := filepath.Join(dir, "key.pem")
	os.WriteFile(certPath, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o600)
	os.WriteFile(keyPath, pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}), 0o600)
	return certPath, keyPath
}

func startHarness(t *testing.T) *harness {
	t.Helper()
	pollTTL = 50 * time.Millisecond
	idleInterval = 200 * time.Millisecond
	noopInterval = 0

	site := newFakeSite()
	up := httptest.NewServer(site)
	t.Cleanup(up.Close)

	dir := t.TempDir()
	certPath, keyPath := writeTestCert(t, dir)
	os.MkdirAll(filepath.Join(dir, "sites"), 0o755)
	cfg := fmt.Sprintf(`{"site":"example","hostname":%q,"upstream":%q,"cert_file":%q,"key_file":%q}`, testHost, up.URL, certPath, keyPath)
	os.WriteFile(filepath.Join(dir, "sites", "example.json"), []byte(cfg), 0o644)
	os.WriteFile(filepath.Join(dir, "sites", "example.enabled"), nil, 0o644)
	// A site that is configured but not turned on must not be served.
	os.WriteFile(filepath.Join(dir, "sites", "other.json"), []byte(`{"site":"other","hostname":"other.example.test","upstream":"http://127.0.0.1:1"}`), 0o644)

	reg := NewRegistry(dir)
	if err := reg.Reload(); err != nil {
		t.Fatal(err)
	}
	if reg.Count() != 1 {
		t.Fatalf("enabled sites = %d, want 1", reg.Count())
	}
	gw := NewGateway(reg, NewConnLimiter(50, 500), 20)

	imapLn, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	imapSrv := gw.NewIMAPServer()
	go imapSrv.Serve(gw.TLSListener(imapLn, "imap"))
	t.Cleanup(func() { imapSrv.Close() })

	smtpLn, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	smtpSrv := gw.NewSMTPServer(testHost)
	go smtpSrv.Serve(gw.TLSListener(smtpLn, "smtp"))
	t.Cleanup(func() { smtpSrv.Close() })

	return &harness{site: site, imapAddr: imapLn.Addr().String(), smtpAddr: smtpLn.Addr().String(),
		gw: gw, dir: dir, upURL: up.URL, certPath: certPath, keyPath: keyPath}
}

func clientTLS() *tls.Config {
	return &tls.Config{InsecureSkipVerify: true, ServerName: testHost}
}

type updates struct {
	mu      sync.Mutex
	exists  []uint32
	expunge []uint32
	fetch   int
}

func (u *updates) lastExists() uint32 {
	u.mu.Lock()
	defer u.mu.Unlock()
	if len(u.exists) == 0 {
		return 0
	}
	return u.exists[len(u.exists)-1]
}

func dialIMAP(t *testing.T, h *harness, u *updates) *imapclient.Client {
	t.Helper()
	opts := &imapclient.Options{TLSConfig: clientTLS()}
	if u != nil {
		opts.UnilateralDataHandler = &imapclient.UnilateralDataHandler{
			Mailbox: func(d *imapclient.UnilateralDataMailbox) {
				if d.NumMessages != nil {
					u.mu.Lock()
					u.exists = append(u.exists, *d.NumMessages)
					u.mu.Unlock()
				}
			},
			Expunge: func(seq uint32) {
				u.mu.Lock()
				u.expunge = append(u.expunge, seq)
				u.mu.Unlock()
			},
			Fetch: func(m *imapclient.FetchMessageData) {
				m.Collect() // imapclient runs this on its own goroutine
				u.mu.Lock()
				u.fetch++
				u.mu.Unlock()
			},
		}
	}
	c, err := imapclient.DialTLS(h.imapAddr, opts)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	return c
}

func imapCode(err error) imap.ResponseCode {
	var ie *imap.Error
	if errors.As(err, &ie) {
		return ie.Code
	}
	return ""
}

func TestIMAPSession(t *testing.T) {
	h := startHarness(t)
	u1 := h.site.Add("inbox", testMessage("Hello there", "bob@example.org", "first body"), nil, false)
	u2 := h.site.Add("inbox", testMessage("Second note", "carol@example.org", "second body"), []string{`\Seen`}, false)
	u3 := h.site.Add("inbox", testMessage("Sealed thing", "dave@example.org", "secret body"), nil, true)

	c := dialIMAP(t, h, nil)

	// Sign-in: a wrong password is refused with AUTHENTICATIONFAILED.
	if err := c.Login(h.site.mailbox, "jm-wrong").Wait(); imapCode(err) != imap.ResponseCodeAuthenticationFailed {
		t.Fatalf("bad login: err=%v code=%q", err, imapCode(err))
	}
	if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
		t.Fatalf("login: %v", err)
	}
	if h.site.lastIP != "127.0.0.1" {
		t.Errorf("client IP passed upstream = %q", h.site.lastIP)
	}
	caps := c.Caps()
	for _, want := range []imap.Cap{imap.CapIMAP4rev1, imap.CapUIDPlus, imap.CapMove, imap.CapIdle, imap.CapSpecialUse, imap.CapLiteralPlus, imap.CapNamespace, imap.CapEnable} {
		if !caps.Has(want) {
			t.Errorf("capability %s missing", want)
		}
	}

	// LIST: every folder, special uses, and the implied "Labels" parent.
	list, err := c.List("", "*", nil).Collect()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	attrs := map[string][]imap.MailboxAttr{}
	for _, l := range list {
		attrs[l.Mailbox] = l.Attrs
		if l.Delim != '/' {
			t.Errorf("%s delimiter %q", l.Mailbox, l.Delim)
		}
	}
	for _, name := range []string{"INBOX", "Sent", "Drafts", "Archive", "Spam", "Trash", "All Mail", "Labels", "Labels/Inbox", "Projects"} {
		if _, ok := attrs[name]; !ok {
			t.Errorf("LIST lacks %q (got %v)", name, attrs)
		}
	}
	if !hasAttr(attrs["Sent"], imap.MailboxAttrSent) || !hasAttr(attrs["Spam"], imap.MailboxAttrJunk) {
		t.Errorf("special-use attributes missing: %v", attrs)
	}
	if !hasAttr(attrs["Labels"], imap.MailboxAttrNoSelect) || !hasAttr(attrs["Labels"], imap.MailboxAttrHasChildren) {
		t.Errorf("Labels parent attrs = %v", attrs["Labels"])
	}

	// LIST (SPECIAL-USE) and LSUB, as Thunderbird asks them.
	su, err := c.List("", "*", &imap.ListOptions{SelectSpecialUse: true}).Collect()
	if err != nil || len(su) != 6 {
		t.Errorf("LIST (SPECIAL-USE): %d entries, %v", len(su), err)
	}
	sub, err := c.List("", "*", &imap.ListOptions{SelectSubscribed: true}).Collect()
	if err != nil || len(sub) != 9 {
		t.Errorf("LSUB-style list: %d entries, %v", len(sub), err)
	}

	// STATUS.
	st, err := c.Status("INBOX", &imap.StatusOptions{NumMessages: true, UIDNext: true, NumUnseen: true}).Wait()
	if err != nil || st.NumMessages == nil || *st.NumMessages != 3 || *st.NumUnseen != 2 {
		t.Fatalf("status: %+v %v", st, err)
	}

	// SELECT (case-insensitive INBOX).
	sel, err := c.Select("inbox", nil).Wait()
	if err != nil {
		t.Fatalf("select: %v", err)
	}
	if sel.NumMessages != 3 || sel.UIDValidity != 1700000000 || sel.UIDNext != imap.UID(u3+1) {
		t.Fatalf("select data: %+v", sel)
	}
	for _, f := range sel.PermanentFlags {
		if f == imap.FlagWildcard {
			t.Errorf("PERMANENTFLAGS must not offer \\*")
		}
	}

	// The list a program downloads first: header fields, sizes, flags.
	hdrSection := &imap.FetchItemBodySection{Specifier: imap.PartSpecifierHeader, HeaderFields: []string{"Subject", "From"}, Peek: true}
	msgs, err := c.Fetch(imap.UIDSetNum(imap.UID(u1), imap.UID(u2)), &imap.FetchOptions{
		UID: true, Flags: true, RFC822Size: true, InternalDate: true, Envelope: true, BodySection: []*imap.FetchItemBodySection{hdrSection},
	}).Collect()
	if err != nil {
		t.Fatalf("fetch headers: %v", err)
	}
	if len(msgs) != 2 {
		t.Fatalf("fetch headers: %d messages", len(msgs))
	}
	if msgs[0].Envelope == nil || msgs[0].Envelope.Subject != "Hello there" {
		t.Errorf("envelope: %+v", msgs[0].Envelope)
	}
	hb := msgs[0].FindBodySection(hdrSection)
	if !strings.Contains(string(hb), "Subject: Hello there") || strings.Contains(string(hb), "Message-ID") {
		t.Errorf("header fields: %q", hb)
	}
	if msgs[0].RFC822Size != int64(len(testMessage("Hello there", "bob@example.org", "first body"))) {
		t.Errorf("size %d", msgs[0].RFC822Size)
	}
	if h.site.Calls("mail_fetch") != 1 {
		t.Errorf("header list took %d fetch calls, want 1", h.site.Calls("mail_fetch"))
	}

	// A whole message, its structure, and a piece of it.
	whole := &imap.FetchItemBodySection{Peek: true}
	piece := &imap.FetchItemBodySection{Peek: true, Partial: &imap.SectionPartial{Offset: 6, Size: 3}}
	part := &imap.FetchItemBodySection{Peek: true, Part: []int{1}}
	msgs, err = c.Fetch(imap.UIDSetNum(imap.UID(u1)), &imap.FetchOptions{
		UID: true, BodyStructure: &imap.FetchItemBodyStructure{Extended: true}, BodySection: []*imap.FetchItemBodySection{whole, piece, part},
	}).Collect()
	if err != nil || len(msgs) != 1 {
		t.Fatalf("fetch full: %v (%d)", err, len(msgs))
	}
	raw := testMessage("Hello there", "bob@example.org", "first body")
	if got := msgs[0].FindBodySection(whole); !bytes.Equal(got, raw) {
		t.Errorf("BODY[] differs:\n%q\n%q", got, raw)
	}
	if got := msgs[0].FindBodySection(piece); string(got) != "bob" {
		t.Errorf("partial = %q", got)
	}
	if got := msgs[0].FindBodySection(part); !strings.Contains(string(got), "first body") {
		t.Errorf("BODY[1] = %q", got)
	}
	mp, ok := msgs[0].BodyStructure.(*imap.BodyStructureMultiPart)
	if !ok || mp.Subtype != "alternative" || len(mp.Children) != 2 {
		t.Errorf("bodystructure: %#v", msgs[0].BodyStructure)
	}
	calls := h.site.Calls("mail_fetch")
	// Reading the same message again within a minute is served from memory.
	if _, err := c.Fetch(imap.UIDSetNum(imap.UID(u1)), &imap.FetchOptions{BodySection: []*imap.FetchItemBodySection{piece}}).Collect(); err != nil {
		t.Fatal(err)
	}
	if h.site.Calls("mail_fetch") != calls {
		t.Errorf("a second piece of the same message went back to the site")
	}

	// A locked message is never served: NO [UNAVAILABLE] with the unlock link.
	_, err = c.Fetch(imap.UIDSetNum(imap.UID(u3)), &imap.FetchOptions{UID: true, BodySection: []*imap.FetchItemBodySection{whole}}).Collect()
	if imapCode(err) != imap.ResponseCodeUnavailable || !strings.Contains(err.Error(), "Mailbox locked - unlock at https://mail.example.test/") {
		t.Fatalf("locked fetch: %v", err)
	}

	// Thunderbird retries a refused download several times in a row; the
	// retries are answered without asking the site again.
	calls = h.site.Calls("mail_fetch")
	for i := 0; i < 4; i++ {
		_, err = c.Fetch(imap.UIDSetNum(imap.UID(u3)), &imap.FetchOptions{UID: true, RFC822Size: true, BodySection: []*imap.FetchItemBodySection{whole}}).Collect()
		if imapCode(err) != imap.ResponseCodeUnavailable {
			t.Fatalf("retry %d: %v", i, err)
		}
	}
	if h.site.Calls("mail_fetch") != calls {
		t.Errorf("refused retries reached the site %d times", h.site.Calls("mail_fetch")-calls)
	}

	// BODY[] without PEEK sets \Seen.
	msgs, err = c.Fetch(imap.UIDSetNum(imap.UID(u1)), &imap.FetchOptions{UID: true, BodySection: []*imap.FetchItemBodySection{{}}}).Collect()
	if err != nil || len(msgs) != 1 || !hasFlag(msgs[0].Flags, imap.FlagSeen) {
		t.Fatalf("implicit seen: %v %+v", err, msgs)
	}

	// STORE.
	msgs, err = c.Store(imap.UIDSetNum(imap.UID(u2)), &imap.StoreFlags{Op: imap.StoreFlagsAdd, Flags: []imap.Flag{imap.FlagFlagged, "$label1"}}, nil).Collect()
	if err != nil || len(msgs) != 1 || !hasFlag(msgs[0].Flags, imap.FlagFlagged) || !hasFlag(msgs[0].Flags, imap.FlagSeen) {
		t.Fatalf("store: %v %+v", err, msgs)
	}

	// SEARCH: flags here, words at the site.
	sr, err := c.UIDSearch(&imap.SearchCriteria{NotFlag: []imap.Flag{imap.FlagFlagged}}, nil).Wait()
	if err != nil {
		t.Fatal(err)
	}
	if got := sr.AllUIDs(); len(got) != 2 || got[0] != imap.UID(u1) || got[1] != imap.UID(u3) {
		t.Errorf("UNFLAGGED = %v", got)
	}
	sr, err = c.UIDSearch(&imap.SearchCriteria{Header: []imap.SearchCriteriaHeaderField{{Key: "Subject", Value: "second"}}}, nil).Wait()
	if err != nil || len(sr.AllUIDs()) != 1 || sr.AllUIDs()[0] != imap.UID(u2) {
		t.Errorf("SUBJECT second = %v %v", sr, err)
	}
	sr, err = c.UIDSearch(&imap.SearchCriteria{Or: [][2]imap.SearchCriteria{{
		{Body: []string{"first"}}, {Header: []imap.SearchCriteriaHeaderField{{Key: "X-Unknown", Value: "x"}}},
	}}}, nil).Wait()
	if err != nil || len(sr.AllUIDs()) != 1 || sr.AllUIDs()[0] != imap.UID(u1) {
		t.Errorf("OR BODY first / unknown header = %v %v", sr, err)
	}

	// MOVE: COPYUID, then the source message is expunged.
	mv, err := c.Move(imap.UIDSetNum(imap.UID(u2)), "Archive").Wait()
	if err != nil {
		t.Fatalf("move: %v", err)
	}
	if mv.UIDValidity != 1700000000 || mv.DestUIDs == nil || mv.DestUIDs.String() != "1" || mv.SourceUIDs.String() != fmt.Sprint(u2) {
		t.Errorf("move data: %+v", mv)
	}
	if n := c.Mailbox().NumMessages; n != 2 {
		t.Errorf("after move INBOX has %d", n)
	}

	// \Deleted + EXPUNGE.
	if _, err := c.Store(imap.UIDSetNum(imap.UID(u1)), &imap.StoreFlags{Op: imap.StoreFlagsAdd, Silent: true, Flags: []imap.Flag{imap.FlagDeleted}}, nil).Collect(); err != nil {
		t.Fatal(err)
	}
	exp, err := c.Expunge().Collect()
	if err != nil || len(exp) != 1 || exp[0] != 1 {
		t.Fatalf("expunge: %v %v", exp, err)
	}

	// APPEND to Drafts answers APPENDUID.
	draft := []byte("From: ann@example.test\r\nSubject: draft\r\n\r\nhello\r\n")
	ac := c.Append("Drafts", int64(len(draft)), &imap.AppendOptions{Flags: []imap.Flag{imap.FlagDraft, imap.FlagSeen}})
	ac.Write(draft)
	ac.Close()
	ad, err := ac.Wait()
	if err != nil || ad.UID != 1 || ad.UIDValidity != 1700000000 {
		t.Fatalf("append: %+v %v", ad, err)
	}
	// APPEND to a folder that does not exist: TRYCREATE.
	ac = c.Append("Nope", int64(len(draft)), nil)
	ac.Write(draft)
	ac.Close()
	if _, err := ac.Wait(); imapCode(err) != imap.ResponseCodeTryCreate {
		t.Errorf("append to missing folder: %v", err)
	}

	// CREATE, RENAME (refused by the site), DELETE.
	if err := c.Create("Receipts", nil).Wait(); err != nil {
		t.Fatalf("create: %v", err)
	}
	if err := c.Rename("Receipts", "Bills", nil).Wait(); imapCode(err) != imap.ResponseCodeCannot {
		t.Errorf("rename: %v", err)
	}
	if err := c.Delete("Receipts").Wait(); err != nil {
		t.Errorf("delete: %v", err)
	}

	if err := c.Logout().Wait(); err != nil {
		t.Errorf("logout: %v", err)
	}
	if !waitFor(func() bool { return inflight.Used() == 0 }) {
		t.Errorf("%d in-flight bytes still reserved after the session", inflight.Used())
	}
}

func TestIMAPIdleSeesNewMail(t *testing.T) {
	h := startHarness(t)
	h.site.Add("inbox", testMessage("One", "bob@example.org", "x"), nil, false)
	u := &updates{}
	c := dialIMAP(t, h, u)
	if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Select("INBOX", nil).Wait(); err != nil {
		t.Fatal(err)
	}

	// A second connection of the same program, idling too: the two share
	// one poll of the site.
	c2 := dialIMAP(t, h, &updates{})
	if err := c2.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := c2.Select("INBOX", nil).Wait(); err != nil {
		t.Fatal(err)
	}

	idle, err := c.Idle()
	if err != nil {
		t.Fatal(err)
	}
	idle2, err := c2.Idle()
	if err != nil {
		t.Fatal(err)
	}
	time.Sleep(300 * time.Millisecond)
	h.site.Add("inbox", testMessage("Two", "carol@example.org", "y"), nil, false)
	deadline := time.Now().Add(5 * time.Second)
	for u.lastExists() != 2 && time.Now().Before(deadline) {
		time.Sleep(50 * time.Millisecond)
	}
	if u.lastExists() != 2 {
		t.Fatalf("IDLE did not report the new message (EXISTS seen: %v)", u.exists)
	}
	idle.Close()
	idle.Wait()
	idle2.Close()
	idle2.Wait()

	// A flag change made elsewhere (the web reader) reaches the program.
	h.site.mu.Lock()
	in := h.site.folder("inbox")
	in.modseq++
	in.msgs[0].flags[`\Flagged`] = true
	in.msgs[0].modseq = in.modseq
	h.site.mu.Unlock()
	time.Sleep(100 * time.Millisecond)
	if err := c.Noop().Wait(); err != nil {
		t.Fatal(err)
	}
	if !waitFor(func() bool { u.mu.Lock(); defer u.mu.Unlock(); return u.fetch > 0 }) {
		t.Errorf("flag change made elsewhere was not reported")
	}

	// Removed elsewhere: not announced during a FETCH (sequence numbers must
	// not shift under it), then EXPUNGE on the next poll that may.
	h.site.mu.Lock()
	in.modseq++
	in.msgs[0].expunged = true
	in.msgs[0].modseq = in.modseq
	h.site.mu.Unlock()
	time.Sleep(100 * time.Millisecond)
	fl, err := c.Fetch(imap.SeqSetNum(1, 2), &imap.FetchOptions{UID: true, Flags: true}).Collect()
	if err != nil || len(fl) != 2 {
		t.Fatalf("fetch during pending expunge: %v %d", err, len(fl))
	}
	u.mu.Lock()
	early := len(u.expunge)
	u.mu.Unlock()
	if early != 0 {
		t.Errorf("EXPUNGE sent during FETCH")
	}
	if err := c.Noop().Wait(); err != nil {
		t.Fatal(err)
	}
	waitFor(func() bool { u.mu.Lock(); defer u.mu.Unlock(); return len(u.expunge) > 0 })
	u.mu.Lock()
	expunged := append([]uint32(nil), u.expunge...)
	u.mu.Unlock()
	if len(expunged) != 1 || expunged[0] != 1 {
		t.Errorf("expunge made elsewhere: %v", expunged)
	}
}

func TestIMAPWrongSNI(t *testing.T) {
	h := startHarness(t)
	_, err := imapclient.DialTLS(h.imapAddr, &imapclient.Options{TLSConfig: &tls.Config{InsecureSkipVerify: true, ServerName: "other.example.test"}})
	if err == nil {
		t.Fatalf("a name no enabled site serves must fail the handshake")
	}
}

func TestSMTPSubmission(t *testing.T) {
	h := startHarness(t)
	dial := func() *smtp.Client {
		c, err := smtp.DialTLS(h.smtpAddr, clientTLS())
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() { c.Close() })
		if err := c.Hello("laptop"); err != nil {
			t.Fatal(err)
		}
		return c
	}
	msg := "From: ann@example.test\r\nTo: bob@example.org\r\nSubject: hi\r\n\r\nhello bob\r\n"

	// Nothing before sign-in.
	c := dial()
	if err := c.Mail("ann@example.test", nil); err == nil {
		t.Fatalf("MAIL before AUTH accepted")
	}
	// A wrong password fails at AUTH with 535 5.7.8.
	err := c.Auth(sasl.NewPlainClient("", h.site.mailbox, "jm-wrong"))
	var se *smtp.SMTPError
	if !errors.As(err, &se) || se.Code != 535 || se.EnhancedCode != (smtp.EnhancedCode{5, 7, 8}) {
		t.Fatalf("bad auth: %v", err)
	}

	c = dial()
	if err := c.Auth(sasl.NewPlainClient("", h.site.mailbox, h.site.password)); err != nil {
		t.Fatalf("auth: %v", err)
	}
	if err := c.SendMail("ann@example.test", []string{"bob@example.org", "eve@example.org"}, strings.NewReader(msg)); err != nil {
		t.Fatalf("send: %v", err)
	}
	h.site.mu.Lock()
	n := len(h.site.submitted)
	got := ""
	if n > 0 {
		got = string(h.site.submitted[0])
	}
	h.site.mu.Unlock()
	if n != 1 || !strings.Contains(got, "hello bob") {
		t.Fatalf("submitted = %d %q", n, got)
	}

	// The site's refusal comes back as its own reply: 454 4.7.0 while locked.
	h.site.mu.Lock()
	h.site.refuseAll = "454 4.7.0"
	h.site.mu.Unlock()
	err = c.SendMail("ann@example.test", []string{"bob@example.org"}, strings.NewReader(msg))
	if !errors.As(err, &se) || se.Code != 454 || se.EnhancedCode != (smtp.EnhancedCode{4, 7, 0}) || !strings.Contains(se.Message, "Mailbox locked - unlock at") || !isASCII(se.Message) {
		t.Fatalf("locked send: %v", err)
	}
	h.site.mu.Lock()
	h.site.refuseAll = ""
	h.site.mu.Unlock()

	// A From that is not the mailbox: 550 5.7.1 from the site.
	err = c.SendMail("ann@example.test", []string{"bob@example.org"}, strings.NewReader("From: mallory@example.org\r\n\r\nx\r\n"))
	if !errors.As(err, &se) || se.Code != 550 {
		t.Fatalf("foreign From: %v", err)
	}
	c.Quit()
}

func TestUIDSetStrings(t *testing.T) {
	got := uidSetStrings([]uint32{9, 1, 2, 3, 5, 7, 8, 3})
	if len(got) != 1 || got[0] != "1:3,5,7:9" {
		t.Errorf("got %v", got)
	}
	var many []uint32
	for i := uint32(1); i < 40000; i += 2 {
		many = append(many, i)
	}
	sets := uidSetStrings(many)
	if len(sets) < 2 {
		t.Errorf("a long set was not split")
	}
	for _, s := range sets {
		if len(s) > 65536 {
			t.Errorf("set of %d chars", len(s))
		}
	}
}

func TestCopyUIDPairing(t *testing.T) {
	src, dst := copyUIDSets([][2]uint32{{7, 20}, {5, 21}, {6, 22}})
	if src.String() != "5:6,7" || dst.String() != "21:22,20" {
		t.Errorf("src %s dst %s", src, dst)
	}
}

func TestSMTPCodeParse(t *testing.T) {
	code, enh, ok := parseSMTPCode("454 4.7.0")
	if !ok || code != 454 || enh != (smtp.EnhancedCode{4, 7, 0}) {
		t.Errorf("%d %v %v", code, enh, ok)
	}
	if _, _, ok := parseSMTPCode("nonsense"); ok {
		t.Errorf("nonsense parsed")
	}
}

func TestSourceKey(t *testing.T) {
	if sourceKey("2001:db8:1:2:3:4:5:6") != "2001:db8:1:2::/64" {
		t.Errorf("v6 key %s", sourceKey("2001:db8:1:2:3:4:5:6"))
	}
	if sourceKey("::ffff:192.0.2.1") != "192.0.2.1" || sourceKey("192.0.2.1") != "192.0.2.1" {
		t.Errorf("v4 key")
	}
}

func hasAttr(list []imap.MailboxAttr, a imap.MailboxAttr) bool {
	for _, x := range list {
		if strings.EqualFold(string(x), string(a)) {
			return true
		}
	}
	return false
}

func hasFlag(list []imap.Flag, f imap.Flag) bool {
	for _, x := range list {
		if x == f {
			return true
		}
	}
	return false
}

// waitFor polls cond for up to two seconds (imapclient hands unsolicited
// responses to its handlers on their own goroutines).
func waitFor(cond func() bool) bool {
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return true
		}
		time.Sleep(20 * time.Millisecond)
	}
	return cond()
}
