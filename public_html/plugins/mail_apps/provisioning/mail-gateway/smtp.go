package main

import (
	"errors"
	"io"
	"strconv"
	"strings"
	"time"

	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"
)

// The submission face: AUTH PLAIN (checked by mail_login), the envelope, the
// message, then mail_submit. The gateway never relays anything itself — the
// site sends through the mailbox's own path, or refuses.

type smtpBackend struct{ g *Gateway }

func (b smtpBackend) NewSession(c *smtp.Conn) (smtp.Session, error) {
	ip := remoteIP(c.Conn().RemoteAddr())
	state, ok := c.TLSConnectionState()
	if !ok {
		return nil, smtpReply(530, smtp.EnhancedCode{5, 7, 0}, "TLS required")
	}
	site := b.g.reg.Lookup(state.ServerName)
	if site == nil {
		logf("smtp: %s: no site serves %q", ip, state.ServerName)
		return nil, smtpReply(554, smtp.EnhancedCode{5, 7, 0}, "This server does not serve that name")
	}
	logf("smtp: connect %s site=%s", ip, site.Site)
	s := &smtpSession{g: b.g, site: site, ip: ip, started: time.Now()}
	s.untrack = b.g.track(site.Site, func() { c.Close() })
	return s, nil
}

type smtpSession struct {
	g       *Gateway
	site    *Site
	ip      string
	started time.Time

	creds        Creds
	authed       bool
	authFailures int
	credKey      string
	untrack      func()

	from string
	rcpt []string
}

var _ smtp.AuthSession = (*smtpSession)(nil)

func (s *smtpSession) AuthMechanisms() []string { return []string{sasl.Plain} }

func (s *smtpSession) Auth(mech string) (sasl.Server, error) {
	if !strings.EqualFold(mech, sasl.Plain) {
		return nil, smtp.ErrAuthUnknownMechanism
	}
	return sasl.NewPlainServer(func(identity, username, password string) error {
		username = strings.TrimSpace(username)
		if identity != "" && identity != username {
			return smtpReply(535, smtp.EnhancedCode{5, 7, 8}, "Signing in as someone else is not supported")
		}
		if s.authFailures >= 3 || s.g.throttle.Blocked(s.ip) {
			return smtpReply(454, smtp.EnhancedCode{4, 7, 0}, "Too many failed sign-ins. Try again later.")
		}
		cr := Creds{Username: username, Secret: password, ClientIP: s.ip}
		var res loginResult
		err := s.site.upstream.Call(callCtx(), cr, "mail_login", nil, &res)
		if err != nil {
			ue, _ := asUpstream(err)
			if ue != nil && (ue.Kind == KindAuth || ue.Kind == KindRefusal || ue.Kind == KindInvalid) {
				wait := s.g.throttle.Failed(s.ip)
				s.authFailures++
				logf("smtp: sign-in fail %s site=%s user=%s: %s", s.ip, s.site.Site, username, ue.Message)
				time.Sleep(wait)
				msg := "Incorrect username or app password"
				if ue.Kind == KindRefusal && ue.Message != "" {
					msg = ue.Message
				}
				return smtpReply(535, smtp.EnhancedCode{5, 7, 8}, msg)
			}
			logf("smtp: sign-in error %s site=%s user=%s: %v", s.ip, s.site.Site, username, err)
			return s.smtpError(err, true)
		}
		key := credKey(s.site.Site, cr)
		if s.g.credLimit.Acquire(key) == nil {
			logf("smtp: sign-in refused %s site=%s user=%s: too many connections for this credential", s.ip, s.site.Site, username)
			return smtpReply(454, smtp.EnhancedCode{4, 7, 0}, "Too many connections with this app password. Try again shortly.")
		}
		s.credKey = key
		s.creds = cr
		s.authed = true
		logf("smtp: sign-in ok %s site=%s user=%s", s.ip, s.site.Site, username)
		return nil
	}), nil
}

var errTooLarge = smtpReply(552, smtp.EnhancedCode{5, 3, 4}, tooLargeText)

var errAuthFirst = smtpReply(530, smtp.EnhancedCode{5, 7, 0}, "Sign in first (AUTH PLAIN)")

func (s *smtpSession) Mail(from string, opts *smtp.MailOptions) error {
	if !s.authed {
		return errAuthFirst
	}
	s.from = from
	s.rcpt = nil
	return nil
}

func (s *smtpSession) Rcpt(to string, opts *smtp.RcptOptions) error {
	if !s.authed {
		return errAuthFirst
	}
	if len(s.rcpt) >= 500 {
		return smtpReply(452, smtp.EnhancedCode{4, 5, 3}, "Too many recipients")
	}
	s.rcpt = append(s.rcpt, to)
	return nil
}

func (s *smtpSession) Data(r io.Reader) error {
	if !s.authed {
		io.Copy(io.Discard, r)
		return errAuthFirst
	}
	raw, release, err := readMessage(r, maxMessageBytes)
	defer release()
	if errors.Is(err, smtp.ErrDataTooLarge) || errors.Is(err, errMessageTooLarge) {
		return errTooLarge
	}
	if errors.Is(err, errBusy) {
		return smtpReply(451, smtp.EnhancedCode{4, 7, 0}, "The mail gateway is busy; try again shortly.")
	}
	if err != nil {
		var se *smtp.SMTPError
		if errors.As(err, &se) {
			return smtpReply(se.Code, se.EnhancedCode, se.Message)
		}
		return smtpReply(451, smtp.EnhancedCode{4, 3, 0}, "The message did not arrive whole")
	}
	if len(raw) > maxMessageBytes {
		return errTooLarge
	}
	rcpt := s.rcpt
	if rcpt == nil {
		rcpt = []string{}
	}
	var res submitResult
	held, err := s.site.upstream.CallHold(callCtx(), s.creds, "mail_submit", map[string]any{
		"mail_from": s.from,
		"rcpt_to":   rcpt,
	}, raw, false, &res)
	held.Done()
	size := len(raw)
	raw = nil
	if err != nil {
		logf("smtp: submit refused site=%s user=%s rcpts=%d bytes=%d: %v", s.site.Site, s.creds.Username, len(rcpt), size, err)
		return s.smtpError(err, false)
	}
	logf("smtp: submitted site=%s user=%s rcpts=%d bytes=%d stored=%v", s.site.Site, s.creds.Username, len(rcpt), size, res.Stored)
	if res.Warning != "" {
		logf("smtp: submit warning site=%s user=%s: %s", s.site.Site, s.creds.Username, res.Warning)
	}
	return nil
}

func (s *smtpSession) Reset() {
	s.from = ""
	s.rcpt = nil
}

func (s *smtpSession) Logout() error {
	s.untrack()
	if s.credKey != "" {
		s.g.credLimit.Release(s.credKey)
		s.credKey = ""
	}
	who := "-"
	if s.authed {
		who = s.creds.Username
	}
	logf("smtp: disconnect %s site=%s user=%s after %s", s.ip, s.site.Site, who, time.Since(s.started).Round(time.Second))
	return nil
}

// smtpError turns an upstream failure into an SMTP reply. A refusal carries
// the site's own reply ("454 4.7.0") and sentence — without its closing full
// stop, which Thunderbird adds itself when it shows the reply ("mailbox..").
func (s *smtpSession) smtpError(err error, atAuth bool) error {
	out := s.smtpErrorReply(err, atAuth)
	if se, ok := out.(*smtp.SMTPError); ok && se != smtp.ErrAuthFailed {
		trimmed := *se
		trimmed.Message = strings.TrimRight(strings.TrimSpace(trimmed.Message), ".")
		return &trimmed
	}
	return out
}

func (s *smtpSession) smtpErrorReply(err error, atAuth bool) error {
	ue, ok := asUpstream(err)
	if !ok {
		return smtpReply(451, smtp.EnhancedCode{4, 3, 0}, "Internal error")
	}
	switch ue.Kind {
	case KindRefusal:
		if code, enh, ok := parseSMTPCode(ue.SMTPCode); ok {
			return smtpReply(code, enh, ue.Message)
		}
		switch ue.IMAPCode {
		case "UNAVAILABLE":
			return smtpReply(451, smtp.EnhancedCode{4, 3, 0}, ue.Message)
		case "LIMIT":
			return smtpReply(451, smtp.EnhancedCode{4, 7, 0}, ue.Message)
		case "TOOBIG":
			return smtpReply(552, smtp.EnhancedCode{5, 3, 4}, ue.Message)
		case "CLIENTBUG":
			return smtpReply(554, smtp.EnhancedCode{5, 6, 0}, ue.Message)
		case "AUTHORIZATIONFAILED":
			return smtpReply(550, smtp.EnhancedCode{5, 7, 1}, ue.Message)
		}
		return smtpReply(554, smtp.EnhancedCode{5, 7, 0}, ue.Message)
	case KindAuth:
		if atAuth {
			return smtp.ErrAuthFailed
		}
		logf("smtp: credential no longer accepted %s site=%s user=%s: %s", s.ip, s.site.Site, s.creds.Username, ue.Message)
		return smtpReply(535, smtp.EnhancedCode{5, 7, 8}, "This app password is no longer accepted")
	case KindRateLimit:
		if atAuth {
			return smtpReply(454, smtp.EnhancedCode{4, 7, 0}, retryText(ue.RetryAfter))
		}
		return smtpReply(451, smtp.EnhancedCode{4, 7, 0}, retryText(ue.RetryAfter))
	case KindTooLarge:
		return errTooLarge
	case KindInvalid:
		return smtpReply(554, smtp.EnhancedCode{5, 6, 0}, ue.Message)
	default:
		logf("smtp: upstream unavailable site=%s %s: %v", s.site.Site, ue.Action, ue)
		if atAuth {
			return smtpReply(454, smtp.EnhancedCode{4, 7, 0}, "The mail service is not reachable right now. Try again shortly.")
		}
		return smtpReply(451, smtp.EnhancedCode{4, 3, 0}, "The mail service is not reachable right now. Try again shortly.")
	}
}

// parseSMTPCode reads "454 4.7.0" (the enhanced code optional).
func parseSMTPCode(s string) (int, smtp.EnhancedCode, bool) {
	fields := strings.Fields(s)
	if len(fields) == 0 {
		return 0, smtp.EnhancedCode{}, false
	}
	code, err := strconv.Atoi(fields[0])
	if err != nil || code < 400 || code > 599 {
		return 0, smtp.EnhancedCode{}, false
	}
	enh := smtp.EnhancedCodeNotSet
	if len(fields) > 1 {
		parts := strings.Split(fields[1], ".")
		if len(parts) == 3 {
			var e smtp.EnhancedCode
			okAll := true
			for i, p := range parts {
				n, err := strconv.Atoi(p)
				if err != nil {
					okAll = false
					break
				}
				e[i] = n
			}
			if okAll && e[0] == code/100 {
				enh = e
			}
		}
	}
	return code, enh, true
}

// smtpReply is every SMTP refusal the session gives. The sentence goes out
// without a trailing full stop (programs append their own punctuation), and
// in ASCII.
func smtpReply(code int, enh smtp.EnhancedCode, msg string) *smtp.SMTPError {
	return &smtp.SMTPError{Code: code, EnhancedCode: enh, Message: strings.TrimRight(strings.TrimSpace(ascii(msg)), ".")}
}
