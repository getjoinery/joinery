package main

import (
	"bytes"
	"crypto/tls"
	"errors"
	"io"
	"log"
	"strings"
	"time"

	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"
)

type submission struct {
	Time    string   `json:"time"`
	From    string   `json:"mail_from"`
	To      []string `json:"rcpt_to"`
	Size    int      `json:"size"`
	Headers string   `json:"headers"`
	Result  string   `json:"result"`
}

type smtpBackend struct {
	password string
	lg       *logFile
}

func (b *smtpBackend) NewSession(c *smtp.Conn) (smtp.Session, error) {
	return &smtpSession{b: b}, nil
}

type smtpSession struct {
	b    *smtpBackend
	auth bool
	from string
	to   []string
}

func (s *smtpSession) AuthMechanisms() []string { return []string{sasl.Plain} }

func (s *smtpSession) Auth(mech string) (sasl.Server, error) {
	s.b.lg.Printf("AUTH mechanism chosen by client: %s", mech)
	return sasl.NewPlainServer(func(identity, username, password string) error {
		if username != *flagUser || password != s.b.password {
			return errors.New("invalid credentials")
		}
		s.auth = true
		return nil
	}), nil
}

// failure returns the configured SMTP error if the failure mode applies at this stage.
func failure(stage string) error {
	rig.mu.Lock()
	mode, st := rig.smtpMode, rig.smtpStage
	rig.mu.Unlock()
	if st != stage {
		return nil
	}
	switch mode {
	case "454":
		return &smtp.SMTPError{Code: 454, EnhancedCode: smtp.EnhancedCode{4, 7, 0}, Message: "Mailbox locked — unlock at https://example.test/unlock"}
	case "550":
		return &smtp.SMTPError{Code: 550, EnhancedCode: smtp.EnhancedCode{5, 7, 1}, Message: "Sender address not allowed for this mailbox"}
	}
	return nil
}

func (s *smtpSession) Mail(from string, opts *smtp.MailOptions) error {
	if !s.auth {
		return smtp.ErrAuthRequired
	}
	s.from = from
	return failure("mail")
}

func (s *smtpSession) Rcpt(to string, opts *smtp.RcptOptions) error {
	s.to = append(s.to, to)
	return failure("rcpt")
}

func (s *smtpSession) Data(r io.Reader) error {
	raw, err := io.ReadAll(r)
	if err != nil {
		return err
	}
	hdr := string(raw)
	if i := strings.Index(hdr, "\r\n\r\n"); i >= 0 {
		hdr = hdr[:i]
	}
	sub := submission{Time: time.Now().Format(time.RFC3339), From: s.from, To: s.to, Size: len(raw), Headers: hdr, Result: "accepted"}
	ferr := failure("data")
	if ferr != nil {
		sub.Result = ferr.Error()
	}
	rig.mu.Lock()
	rig.submissions = append(rig.submissions, sub)
	rig.mu.Unlock()
	s.b.lg.Printf("DATA received: from=%s to=%v size=%d result=%s\n--- headers ---\n%s\n--- end headers ---", s.from, s.to, len(raw), sub.Result, hdr)
	if ferr != nil {
		return ferr
	}
	for _, rcpt := range s.to {
		if strings.EqualFold(rcpt, *flagUser) { // a send-to-self lands in INBOX
			appendMsg("INBOX", bytes.Clone(raw), nil)
		}
	}
	return nil
}

func (s *smtpSession) Reset()        { s.from, s.to = "", nil }
func (s *smtpSession) Logout() error { return nil }

func serveSMTP(addr, password string, lg *logFile, tlsConfig *tls.Config) {
	srv := smtp.NewServer(&smtpBackend{password: password, lg: lg})
	srv.Addr = addr
	srv.Domain = "rig.example.test"
	srv.AllowInsecureAuth = true // loopback plaintext rig only
	srv.MaxMessageBytes = 50 << 20
	srv.Debug = &tsWriter{lg: lg}
	if tlsConfig != nil {
		srv.TLSConfig = tlsConfig
		srv.AllowInsecureAuth = false // AUTH only because the connection is TLS
		log.Fatal(srv.ListenAndServeTLS())
	}
	log.Fatal(srv.ListenAndServe())
}

// tsWriter prefixes go-smtp's raw debug stream with timestamps and masks AUTH.
type tsWriter struct {
	lg      *logFile
	maskNxt bool
}

func (w *tsWriter) Write(p []byte) (int, error) {
	for _, l := range strings.Split(strings.TrimRight(string(p), "\r\n"), "\r\n") {
		up := strings.ToUpper(l)
		switch {
		case strings.Contains(up, "AUTH PLAIN "):
			w.lg.Printf("smtp: %s", l[:strings.Index(up, "AUTH PLAIN ")+10]+" ********")
		case w.maskNxt && !strings.HasPrefix(l, "334") && !strings.HasPrefix(l, "235") && !strings.HasPrefix(l, "535"):
			w.lg.Printf("smtp: ******** (SASL response)")
			w.maskNxt = false
		default:
			if strings.TrimSpace(up) == "AUTH PLAIN" { // client chose the two-step form; next line is the response
				w.maskNxt = true
			}
			w.lg.Printf("smtp: %s", l)
		}
	}
	return len(p), nil
}
