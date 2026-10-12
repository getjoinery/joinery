package main

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/emersion/go-imap/v2"
	"github.com/emersion/go-imap/v2/imapclient"
	"github.com/emersion/go-sasl"
	"github.com/emersion/go-smtp"
)

// S19: a program that reads slowly holds only what it has not read, and one
// credential's slow connections cannot take what other credentials need.
func TestSlowReaderHoldsOnlyItsCredential(t *testing.T) {
	h := startHarness(t)
	h.site.password2 = "jm-second-device"
	savedHeld, savedWait := credentialHeld, inflightWait
	credentialHeld, inflightWait = 4<<20, 300*time.Millisecond
	defer func() { credentialHeld, inflightWait = savedHeld, savedWait }()

	var uids []uint32
	for i := 0; i < 8; i++ {
		uids = append(uids, h.site.Add("inbox", testMessage(fmt.Sprintf("Big %d", i), "bob@example.org", strings.Repeat("q", 2<<20)), nil, false))
	}
	small := h.site.Add("inbox", testMessage("Small", "bob@example.org", "tiny"), nil, false)

	// A: asks for 16 MB and does not read.
	slow, _ := dialRawIMAP(t, h)
	slow.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	slow.cmd(`SELECT "INBOX"`)
	slow.send(fmt.Sprintf("t99 UID FETCH %d:%d (UID BODY.PEEK[])\r\n", uids[0], uids[7]))
	time.Sleep(1500 * time.Millisecond) // the gateway fills the socket and blocks

	// B, same credential: its round cannot be held while A pins the credential.
	b := dialIMAP(t, h, nil)
	if err := b.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := b.Select("INBOX", nil).Wait(); err != nil {
		t.Fatal(err)
	}
	_, err := b.Fetch(imap.UIDSetNum(imap.UID(small)), &imap.FetchOptions{BodySection: []*imap.FetchItemBodySection{{Peek: true}}}).Collect()
	if imapCode(err) != imap.ResponseCodeLimit {
		t.Errorf("same credential while A pins it: %v", err)
	}

	// C, another device's credential: unaffected.
	c := dialIMAP(t, h, nil)
	if err := c.Login(h.site.mailbox, h.site.password2).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Select("INBOX", nil).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Fetch(imap.UIDSetNum(imap.UID(small)), &imap.FetchOptions{BodySection: []*imap.FetchItemBodySection{{Peek: true}}}).Collect(); err != nil {
		t.Errorf("another credential was starved: %v", err)
	}

	// A reads at last: everything arrives, and everything is given back.
	lines := slow.until("t99")
	if n := count(lines, `^\* \d+ FETCH`); n != 8 {
		t.Errorf("A got %d of 8 messages", n)
	}
	if _, err := b.Fetch(imap.UIDSetNum(imap.UID(small)), &imap.FetchOptions{BodySection: []*imap.FetchItemBodySection{{Peek: true}}}).Collect(); err != nil {
		t.Errorf("same credential once A has read: %v", err)
	}
	if !waitFor(func() bool { return inflight.Used() == 0 }) {
		t.Errorf("%d in-flight bytes still reserved", inflight.Used())
	}
}

func TestHeldReleasesPerMessage(t *testing.T) {
	saved := inflight
	inflight = NewBudget(100)
	defer func() { inflight = saved }()
	cred := NewBudget(30)
	if err := inflight.Acquire(context.Background(), 60); err != nil {
		t.Fatal(err)
	}
	cred.Acquire(context.Background(), 30)
	h := &Held{global: 60}
	h.holdCredential(cred, 30, 60) // a 60-byte round; the credential holds 30 of it
	h.Release(20)                  // first message written: 40 left, credential still 30
	if inflight.Used() != 40 || cred.Used() != 30 {
		t.Errorf("after 20: inflight %d cred %d", inflight.Used(), cred.Used())
	}
	h.Release(25) // 15 left: the credential holds only 15
	if inflight.Used() != 15 || cred.Used() != 15 {
		t.Errorf("after 45: inflight %d cred %d", inflight.Used(), cred.Used())
	}
	h.Done()
	if inflight.Used() != 0 || cred.Used() != 0 {
		t.Errorf("after Done: inflight %d cred %d", inflight.Used(), cred.Used())
	}
	var none *Held
	none.Release(5)
	none.Done()
}

// S20 / S22: kept answers are counted and bounded; a panicking call does not
// wedge its key.
func TestSharedCacheBudgetAndPanic(t *testing.T) {
	saved := cacheBudget
	cacheBudget = NewBudget(1000)
	defer func() { cacheBudget = saved }()
	c := &SharedCache{entries: map[string]*cacheEntry{}, ttl: func() time.Duration { return time.Minute }}
	ctx := context.Background()
	calls := 0
	get := func(key string, cost int64) {
		c.Get(ctx, key, true, func() (any, int64, error) { calls++; return "v", cost, nil })
	}
	get("a", 400)
	get("a", 400)
	if calls != 1 || cacheBudget.Used() != 400 {
		t.Errorf("kept: calls %d used %d", calls, cacheBudget.Used())
	}
	get("b", 700) // does not fit: answered, not kept
	get("b", 700)
	if calls != 3 || cacheBudget.Used() != 400 {
		t.Errorf("over budget: calls %d used %d", calls, cacheBudget.Used())
	}
	get("c", -1) // too large to keep at all
	get("c", -1)
	if calls != 5 {
		t.Errorf("uncacheable: calls %d", calls)
	}
	c.Invalidate("a")
	if cacheBudget.Used() != 0 {
		t.Errorf("after invalidate: used %d", cacheBudget.Used())
	}

	_, err := c.Get(ctx, "p", true, func() (any, int64, error) { panic("boom") })
	if err == nil {
		t.Errorf("a panic was not reported")
	}
	done := make(chan struct{})
	go func() {
		c.Get(ctx, "p", true, func() (any, int64, error) { return "ok", 0, nil })
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatalf("the key stayed blocked after a panic")
	}

	// A waiter whose context ends returns at once; the call completes for others.
	slow := make(chan struct{})
	cctx, cancel := context.WithCancel(ctx)
	go func() { time.Sleep(50 * time.Millisecond); cancel() }()
	start := time.Now()
	_, err = c.Get(cctx, "s", true, func() (any, int64, error) { <-slow; return "late", 0, nil })
	if !errors.Is(err, context.Canceled) || time.Since(start) > time.Second {
		t.Errorf("cancelled wait: %v after %s", err, time.Since(start))
	}
	close(slow)
}

// S20: the change listing a poll shares is the compact form.
func TestChangeSetIsCompact(t *testing.T) {
	size := int64(10)
	res := syncResult{UIDValidity: 1, HighestModSeq: 9, Messages: []apiMessage{
		{UID: 3, Flags: []string{`\Seen`, `$Junk`}, ModSeq: 9, Date: "2026-10-05T10:00:00Z", Size: &size},
	}, Vanished: []uint32{1}}
	cs := compactChanges(res)
	if len(cs.msgs) != 1 || !cs.msgs[0].has(imap.FlagSeen) || cs.msgs[0].size != 10 {
		t.Errorf("compact: %+v", cs.msgs)
	}
	// A flag the gateway does not keep ($Junk, owner decision J1) is not
	// reported, even when the site's list carries it.
	if cs.msgs[0].has(imap.FlagJunk) || len(cs.msgs[0].flagList()) != 1 {
		t.Errorf("unkept flag reported: %v", cs.msgs[0].flagList())
	}
	if cs.cost() != messageBytes+4+64 {
		t.Errorf("cost %d", cs.cost())
	}
	big := syncResult{Messages: make([]apiMessage, maxCachedChanges+1)}
	if compactChanges(big).cost() != -1 {
		t.Errorf("a listing over %d changes must not be kept", maxCachedChanges)
	}
}

// S21: the refusal memory stays small, dropping the oldest first.
func TestRefusalMemoryBounded(t *testing.T) {
	s := &imapSession{sel: &selected{folder: apiFolder{Key: "inbox"}, uidValidity: 1}}
	for u := uint32(1); u <= maxRefusals+50; u++ {
		s.noteRefusal(u, "locked")
	}
	if n := len(s.refusals.byUID); n > maxRefusals {
		t.Errorf("%d refusals kept", n)
	}
	if s.refusedWithin(1, time.Minute) != "" || s.refusedWithin(maxRefusals+50, time.Minute) != "locked" {
		t.Errorf("not oldest-first")
	}
	s.sel = &selected{folder: apiFolder{Key: "trash"}, uidValidity: 1}
	if s.refusedWithin(maxRefusals+50, time.Minute) != "" {
		t.Errorf("another folder's refusal answered here")
	}
}

// S24: IDLE's DONE is answered at once even while a poll waits on the site.
func TestIdleDoneDoesNotWaitForTheSite(t *testing.T) {
	h := startHarness(t)
	c := dialIMAP(t, h, nil)
	if err := c.Login(h.site.mailbox, h.site.password).Wait(); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Select("INBOX", nil).Wait(); err != nil {
		t.Fatal(err)
	}
	h.site.mu.Lock()
	h.site.slowCalls = 3 * time.Second
	h.site.mu.Unlock()
	idle, err := c.Idle()
	if err != nil {
		t.Fatal(err)
	}
	time.Sleep(400 * time.Millisecond) // a tick has started a slow poll
	start := time.Now()
	idle.Close()
	if err := idle.Wait(); err != nil {
		t.Fatal(err)
	}
	if d := time.Since(start); d > time.Second {
		t.Errorf("DONE took %s", d)
	}
	h.site.mu.Lock()
	h.site.slowCalls = 0
	h.site.mu.Unlock()
}

// S24: a fixed folder with a label listed under it does not claim to have no
// subfolders.
func TestFixedFolderWithChildIsNotNoinferiors(t *testing.T) {
	h := startHarness(t)
	h.site.mu.Lock()
	h.site.folders = append(h.site.folders, &fakeFolder{key: "label:9", name: "Archive/2026", uidvalidity: 1, uidnext: 1, modseq: 1})
	h.site.mu.Unlock()
	rc, _ := dialRawIMAP(t, h)
	rc.cmd(fmt.Sprintf("LOGIN %s %s", h.site.mailbox, h.site.password))
	lines := rc.cmd(`LIST "" "*"`)
	arch := find(lines, `^\* LIST .*"Archive"$`)
	if strings.Contains(arch, `\Noinferiors`) || !strings.Contains(arch, `\HasChildren`) {
		t.Errorf("Archive with a child: %q", arch)
	}
	if trash := find(lines, `^\* LIST .*"Trash"$`); !strings.Contains(trash, `\Noinferiors`) {
		t.Errorf("Trash: %q", trash)
	}
}

// S23: sign-in refusals end without a full stop, like every other reply.
func TestSMTPRepliesHaveNoTrailingFullStop(t *testing.T) {
	h := startHarness(t)
	h.gw.credLimit = NewKeyedLimiter(1)
	first, err := smtp.DialTLS(h.smtpAddr, &tls.Config{InsecureSkipVerify: true, ServerName: testHost})
	if err != nil {
		t.Fatal(err)
	}
	defer first.Close()
	first.Hello("laptop")
	if err := first.Auth(sasl.NewPlainClient("", h.site.mailbox, h.site.password)); err != nil {
		t.Fatal(err)
	}
	second, err := smtp.DialTLS(h.smtpAddr, &tls.Config{InsecureSkipVerify: true, ServerName: testHost})
	if err != nil {
		t.Fatal(err)
	}
	defer second.Close()
	second.Hello("laptop")
	err = second.Auth(sasl.NewPlainClient("", h.site.mailbox, h.site.password))
	var se *smtp.SMTPError
	if !errors.As(err, &se) || se.Code != 454 || strings.HasSuffix(se.Message, ".") || se.Message == "" {
		t.Errorf("credential cap reply: %v", err)
	}
	for _, r := range []*smtp.SMTPError{errTooLarge, errAuthFirst, smtpReply(451, smtp.EnhancedCode{4, 7, 0}, "Try again shortly.")} {
		if strings.HasSuffix(r.Message, ".") {
			t.Errorf("%q ends in a full stop", r.Message)
		}
	}
}

var _ = imapclient.Options{}
