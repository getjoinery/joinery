package main

// The wire as Thunderbird 157 speaks it (Phase 0 rig, plugins/mail_apps/tests/
// thunderbird_rig): checked line by line on a raw connection, because these
// are the details a library client hides.

import (
	"bufio"
	"bytes"
	"crypto/tls"
	"encoding/base64"
	"fmt"
	"io"
	"regexp"
	"strconv"
	"strings"
	"testing"
	"time"
)

type rawIMAP struct {
	t   *testing.T
	c   *tls.Conn
	r   *bufio.Reader
	tag int
}

func dialRawIMAP(t *testing.T, h *harness) (*rawIMAP, string) {
	t.Helper()
	c, err := tls.Dial("tcp", h.imapAddr, clientTLS())
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	rc := &rawIMAP{t: t, c: c, r: bufio.NewReader(c)}
	return rc, rc.line()
}

var literalTail = regexp.MustCompile(`\{(\d+)\}$`)

// line reads one response line, with any literal it ends in folded in.
func (rc *rawIMAP) line() string {
	rc.t.Helper()
	rc.c.SetReadDeadline(time.Now().Add(10 * time.Second))
	var out strings.Builder
	for {
		l, err := rc.r.ReadString('\n')
		if err != nil {
			rc.t.Fatalf("read: %v (so far %q)", err, out.String()+l)
		}
		l = strings.TrimRight(l, "\r\n")
		out.WriteString(l)
		m := literalTail.FindStringSubmatch(l)
		if m == nil {
			return out.String()
		}
		n, _ := strconv.Atoi(m[1])
		buf := make([]byte, n)
		if _, err := io.ReadFull(rc.r, buf); err != nil {
			rc.t.Fatal(err)
		}
		out.Write(buf)
	}
}

func (rc *rawIMAP) send(s string) {
	rc.t.Helper()
	if _, err := io.WriteString(rc.c, s); err != nil {
		rc.t.Fatal(err)
	}
}

// cmd sends a tagged command and answers every line up to and including the
// tagged reply (last).
func (rc *rawIMAP) cmd(text string) []string {
	rc.t.Helper()
	rc.tag++
	tag := fmt.Sprintf("t%d", rc.tag)
	rc.send(tag + " " + text + "\r\n")
	return rc.until(tag)
}

func (rc *rawIMAP) until(tag string) []string {
	rc.t.Helper()
	var lines []string
	for {
		l := rc.line()
		lines = append(lines, l)
		if strings.HasPrefix(l, tag+" ") {
			return lines
		}
	}
}

func count(lines []string, re string) int {
	n := 0
	rx := regexp.MustCompile(re)
	for _, l := range lines {
		if rx.MatchString(l) {
			n++
		}
	}
	return n
}

func find(lines []string, re string) string {
	rx := regexp.MustCompile(re)
	for _, l := range lines {
		if rx.MatchString(l) {
			return l
		}
	}
	return ""
}

func isASCII(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] >= 0x80 {
			return false
		}
	}
	return true
}

func TestThunderbirdPaths(t *testing.T) {
	h := startHarness(t)
	u1 := h.site.Add("inbox", testMessage("Hello there", "bob@example.org", "first body"), nil, false)
	h.site.Add("inbox", testMessage("Second note", "carol@example.org", "second body"), nil, false)
	u3 := h.site.Add("inbox", testMessage("Sealed thing", "dave@example.org", "secret body"), nil, true)

	// Thunderbird never sends CAPABILITY: the greeting and the sign-in reply
	// must carry it.
	rc, greeting := dialRawIMAP(t, h)
	if !strings.Contains(greeting, "[CAPABILITY ") || !strings.Contains(greeting, "AUTH=PLAIN") {
		t.Fatalf("greeting: %q", greeting)
	}
	// Two-step AUTHENTICATE PLAIN (no initial response).
	rc.tag++
	rc.send(fmt.Sprintf("t%d AUTHENTICATE PLAIN\r\n", rc.tag))
	if l := rc.line(); !strings.HasPrefix(l, "+") {
		t.Fatalf("continuation: %q", l)
	}
	rc.send(base64.StdEncoding.EncodeToString([]byte("\x00"+h.site.mailbox+"\x00"+h.site.password)) + "\r\n")
	lines := rc.until(fmt.Sprintf("t%d", rc.tag))
	last := lines[len(lines)-1]
	if !strings.Contains(last, "OK [CAPABILITY ") || !strings.Contains(last, " IDLE") || !strings.Contains(last, " MOVE") || !strings.Contains(last, " UIDPLUS") {
		t.Fatalf("AUTHENTICATE reply: %q", last)
	}

	// LOGIN's reply carries it too.
	rl, _ := dialRawIMAP(t, h)
	lines = rl.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	if last := lines[len(lines)-1]; !strings.Contains(last, "OK [CAPABILITY ") || !strings.Contains(last, " IDLE") {
		t.Fatalf("LOGIN reply: %q", last)
	}

	// A plain LIST carries special-use; fixed folders take no subfolders.
	lines = rc.cmd(`LIST "" "*"`)
	for name, want := range map[string][]string{
		`"INBOX"|INBOX$`:  {`\Noinferiors`},
		`"Sent"$`:         {`\Noinferiors`, `\Sent`},
		`"Archive"$`:      {`\Noinferiors`, `\Archive`},
		`"Spam"$`:         {`\Noinferiors`, `\Junk`},
		`"Trash"$`:        {`\Noinferiors`, `\Trash`},
		`"Drafts"$`:       {`\Noinferiors`, `\Drafts`},
		`"All Mail"$`:     {`\Noinferiors`, `\All`},
		`"Projects"$`:     {`\HasNoChildren`},
		`"Labels/Inbox"$`: {`\HasNoChildren`},
		`"Labels"$`:       {`\Noselect`, `\HasChildren`},
	} {
		l := find(lines, `^\* LIST .*`+name)
		if l == "" {
			t.Errorf("LIST lacks %s: %v", name, lines)
			continue
		}
		for _, attr := range want {
			if !strings.Contains(l, attr) {
				t.Errorf("%q lacks %s", l, attr)
			}
		}
		if strings.Contains(name, "Projects") && strings.Contains(l, `\Noinferiors`) {
			t.Errorf("a label must nest: %q", l)
		}
	}

	// LSUB answers every folder.
	lines = rc.cmd(`LSUB "" "*"`)
	if n := count(lines, `^\* LSUB `); n != 9 {
		t.Errorf("LSUB answered %d folders: %v", n, lines)
	}

	// FLAGS and PERMANENTFLAGS include $Junk and $NotJunk, never \*.
	rc.cmd("ENABLE UTF8=ACCEPT")
	lines = rc.cmd(`SELECT "INBOX"`)
	fl := find(lines, `^\* FLAGS `)
	pf := find(lines, `PERMANENTFLAGS`)
	for _, f := range []string{`\Seen`, `\Flagged`, `\Answered`, `\Deleted`, `\Draft`, `$Forwarded`, `$Junk`, `$NotJunk`} {
		if !strings.Contains(fl, f) || !strings.Contains(pf, f) {
			t.Errorf("%s missing: %q / %q", f, fl, pf)
		}
	}
	if strings.Contains(pf, `\*`) {
		t.Errorf("PERMANENTFLAGS offers \\*: %q", pf)
	}

	// A locked message: NO [UNAVAILABLE] in plain ASCII, and the +FLAGS
	// (\Seen) Thunderbird sends after it does not mark it read; other flags
	// still apply.
	lines = rc.cmd(fmt.Sprintf("UID FETCH %d (UID RFC822.SIZE BODY[])", u3))
	last = lines[len(lines)-1]
	if !strings.Contains(last, "NO [UNAVAILABLE] Mailbox locked - unlock at https://mail.example.test/") || !isASCII(last) {
		t.Fatalf("locked fetch: %q", last)
	}
	rc.cmd(fmt.Sprintf(`UID STORE %d +FLAGS (\Seen \Flagged)`, u3))
	h.site.mu.Lock()
	var m3 *fakeMsg
	for _, m := range h.site.folder("inbox").msgs {
		if m.uid == u3 {
			m3 = m
		}
	}
	seen, flagged := m3.flags[`\Seen`], m3.flags[`\Flagged`]
	h.site.mu.Unlock()
	if seen || !flagged {
		t.Errorf("after refused fetch: seen=%v flagged=%v (want false, true)", seen, flagged)
	}

	// The Junk button: $Junk reaches the site.
	calls := h.site.Calls("mail_store")
	rc.cmd(fmt.Sprintf(`UID STORE %d +FLAGS ($Junk)`, u1))
	if h.site.Calls("mail_store") != calls+1 {
		t.Errorf("$Junk was not passed to the site")
	}

	// APPEND in the form Thunderbird uses once UTF8=ACCEPT is on.
	draft := "From: ann@example.test\r\nSubject: draft\r\n\r\nhello\r\n"
	rc.tag++
	tag := fmt.Sprintf("t%d", rc.tag)
	rc.send(fmt.Sprintf("%s APPEND \"Drafts\" (\\Draft) UTF8 (~{%d+}\r\n%s)\r\n", tag, len(draft), draft))
	lines = rc.until(tag)
	if last := lines[len(lines)-1]; !strings.Contains(last, "OK [APPENDUID 1700000000 1]") {
		t.Fatalf("APPEND UTF8: %v", lines)
	}

	// MOVE: COPYUID, then exactly one EXPUNGE for the moved message — and
	// none again on the next poll.
	lines = rc.cmd(fmt.Sprintf(`UID MOVE %d "Archive"`, u1))
	if n := count(lines, `^\* \d+ EXPUNGE`); n != 1 || find(lines, `COPYUID`) == "" {
		t.Fatalf("MOVE answered %d EXPUNGEs: %v", n, lines)
	}
	time.Sleep(60 * time.Millisecond)
	lines = rc.cmd("NOOP")
	if n := count(lines, `EXPUNGE`); n != 0 {
		t.Errorf("a second EXPUNGE after MOVE: %v", lines)
	}
}

func TestOfflineSyncBatching(t *testing.T) {
	h := startHarness(t)
	var uids []uint32
	for i := 0; i < 5; i++ {
		uids = append(uids, h.site.Add("inbox", testMessage(fmt.Sprintf("Note %d", i), "bob@example.org", strings.Repeat("x", 2000)), nil, false))
	}
	rc, _ := dialRawIMAP(t, h)
	rc.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	rc.cmd(`SELECT "INBOX"`)

	// Five whole messages in one command: one call when they fit the budget.
	calls := h.site.Calls("mail_fetch")
	lines := rc.cmd(fmt.Sprintf("UID FETCH %d:%d (UID RFC822.SIZE BODY.PEEK[])", uids[0], uids[4]))
	if n := count(lines, `^\* \d+ FETCH`); n != 5 {
		t.Fatalf("%d FETCH responses: %v", n, lines[len(lines)-1])
	}
	if got := h.site.Calls("mail_fetch") - calls; got != 1 {
		t.Errorf("five messages took %d mail_fetch calls, want 1", got)
	}

	// Over the site's budget: it answers some and names the rest in `more`;
	// the gateway follows until all five are served.
	rc2, _ := dialRawIMAP(t, h)
	rc2.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	rc2.cmd(`SELECT "INBOX"`)
	h.site.mu.Lock()
	h.site.budget = 1
	h.site.mu.Unlock()
	calls = h.site.Calls("mail_fetch")
	lines = rc2.cmd(fmt.Sprintf("UID FETCH %d:%d (UID RFC822.SIZE BODY.PEEK[])", uids[0], uids[4]))
	if n := count(lines, `^\* \d+ FETCH`); n != 5 || !strings.Contains(lines[len(lines)-1], "OK") {
		t.Fatalf("with more: %d FETCH responses, %q", n, lines[len(lines)-1])
	}
	if got := h.site.Calls("mail_fetch") - calls; got != 5 {
		t.Errorf("budgeted fetch took %d calls, want 5 (the fake site serves one per call over budget)", got)
	}

	// A number the site no longer has, named on its own: answered gone (on a
	// connection that has not just read it, so nothing is held in memory).
	rc3, _ := dialRawIMAP(t, h)
	rc3.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	rc3.cmd(`SELECT "INBOX"`)
	h.site.mu.Lock()
	in := h.site.folder("inbox")
	in.modseq++
	in.msgs[2].expunged = true
	in.msgs[2].modseq = in.modseq
	h.site.mu.Unlock()
	lines = rc3.cmd(fmt.Sprintf("UID FETCH %d (UID BODY.PEEK[])", uids[2]))
	if last := lines[len(lines)-1]; !strings.Contains(last, "NO [UNAVAILABLE]") || !strings.Contains(last, "no longer exist") {
		t.Errorf("gone: %q", last)
	}
}

func TestTooLarge(t *testing.T) {
	h := startHarness(t)
	big := bytes.Repeat([]byte(strings.Repeat("y", 76)+"\r\n"), maxMessageBytes/78+100)

	// APPEND over the limit: NO [TOOBIG], refused from the announced size.
	rc, _ := dialRawIMAP(t, h)
	rc.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	rc.tag++
	tag := fmt.Sprintf("t%d", rc.tag)
	go func() {
		rc.c.Write([]byte(fmt.Sprintf("%s APPEND \"INBOX\" {%d+}\r\n", tag, len(big))))
		rc.c.Write(big)
		rc.c.Write([]byte("\r\n"))
	}()
	lines := rc.until(tag)
	if last := lines[len(lines)-1]; !strings.Contains(last, "NO [TOOBIG] Message too large for this mailbox (limit 23 MB)") {
		t.Fatalf("big APPEND: %q", last)
	}
	if h.site.Calls("mail_append") != 0 {
		t.Errorf("a too-large message reached the site")
	}
	// The connection is still in step afterwards.
	if lines := rc.cmd("NOOP"); !strings.Contains(lines[len(lines)-1], "OK") {
		t.Errorf("after big APPEND: %v", lines)
	}

	// SMTP: SIZE advertised, and DATA over it refused 552 5.3.4.
	c, err := tls.Dial("tcp", h.smtpAddr, clientTLS())
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	r := bufio.NewReader(c)
	readReply := func() string {
		c.SetReadDeadline(time.Now().Add(20 * time.Second))
		var all []string
		for {
			l, err := r.ReadString('\n')
			if err != nil {
				t.Fatalf("smtp read: %v %v", err, all)
			}
			all = append(all, strings.TrimRight(l, "\r\n"))
			if len(l) >= 4 && l[3] == ' ' {
				return strings.Join(all, "\n")
			}
		}
	}
	readReply()
	io.WriteString(c, "EHLO laptop\r\n")
	if ehlo := readReply(); !strings.Contains(ehlo, "SIZE 23000000") {
		t.Errorf("EHLO: %q", ehlo)
	}
	io.WriteString(c, "AUTH PLAIN "+base64.StdEncoding.EncodeToString([]byte("\x00"+h.site.mailbox+"\x00"+h.site.password))+"\r\n")
	if rep := readReply(); !strings.HasPrefix(rep, "235") {
		t.Fatalf("auth: %q", rep)
	}
	io.WriteString(c, "MAIL FROM:<ann@example.test>\r\n")
	readReply()
	io.WriteString(c, "RCPT TO:<bob@example.org>\r\n")
	readReply()
	io.WriteString(c, "DATA\r\n")
	if rep := readReply(); !strings.HasPrefix(rep, "354") {
		t.Fatalf("DATA: %q", rep)
	}
	go func() {
		c.Write([]byte("From: ann@example.test\r\n\r\n"))
		c.Write(big)
		c.Write([]byte(".\r\n"))
	}()
	if rep := readReply(); !strings.HasPrefix(rep, "552 5.3.4 Message too large for this mailbox (limit 23 MB)") {
		t.Errorf("big DATA: %q", rep)
	}
	if h.site.Calls("mail_submit") != 0 {
		t.Errorf("a too-large message reached the site")
	}
	c.Close()
	if !waitFor(func() bool { return inflight.Used() == 0 }) {
		t.Errorf("%d in-flight bytes still reserved", inflight.Used())
	}
}
