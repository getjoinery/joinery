package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// These mirror the per-line regexes InboundEmailRouter::buildForwardMessage uses
// (`/^From:.*$/mi`, `/^Reply-To:.*$/mi`): case-insensitive, multiline, and `.`
// does not cross newlines — so a folded header's continuation lines are left
// untouched, exactly as the PHP does.
var (
	fromLineRe    = regexp.MustCompile(`(?im)^From:.*$`)
	replyToLineRe = regexp.MustCompile(`(?im)^Reply-To:.*$`)
	fromNameRe    = regexp.MustCompile(`^"?([^"<]+)"?\s*<`)
	angleAddrRe   = regexp.MustCompile(`<([^>]+)>`)
)

// sendmailPath is the local MTA submission binary. Forwarding re-injects through
// the relay's own Postfix (a local pipe, not a new network listener), so the
// relay's network surface stays exactly Postfix + WireGuard + key-only SSH.
const sendmailPath = "/usr/sbin/sendmail"

// srsRewrite reproduces SRSRewriter::rewrite() byte-for-byte so a bounce to the
// rewritten address still decodes and validates on the main box (which holds
// the same mailbox_srs_secret). Format:
//
//	SRS0=HASH=TIMESTAMP=originaldomain=localpart@forwardingdomain
func srsRewrite(sender, forwardingDomain, secret string, now time.Time) string {
	at := strings.Index(sender, "@")
	if at < 0 {
		return sender // not an address; forward the envelope as-is
	}
	local := sender[:at]
	domain := sender[at+1:]
	timestamp := srsTimestamp(now)
	hash := srsHash(timestamp, domain, local, secret)
	return "SRS0=" + hash + "=" + timestamp + "=" + domain + "=" + local + "@" + forwardingDomain
}

// srsHash mirrors SRSRewriter::generate_hash: HMAC-SHA256 over
// "timestamp=domain=local", take the first 8 hex chars (4 bytes), base64 them,
// keep the first 6 characters.
func srsHash(timestamp, domain, local, secret string) string {
	data := timestamp + "=" + domain + "=" + local
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(data))
	full := hex.EncodeToString(mac.Sum(nil))
	firstFour, err := hex.DecodeString(full[:8])
	if err != nil {
		return ""
	}
	return base64.StdEncoding.EncodeToString(firstFour)[:6]
}

// srsTimestamp mirrors SRSRewriter::encode_timestamp: days since epoch in base36.
func srsTimestamp(now time.Time) string {
	days := now.Unix() / 86400
	return strconv.FormatInt(days, 36)
}

// forwardMessage re-injects the raw message to its destinations. It applies the
// identical header treatment InboundEmailRouter::buildForwardMessage applies —
// rewrite From to the site's verified address (so the original sender domain's
// DMARC never judges us), preserve the original sender as Reply-To, stamp the
// X-Forwarded-* provenance headers — and SRS-rewrites the envelope sender so the
// forwarding subdomain's SPF passes at the destination. The SRS secret and the
// From display identity come from the OWNING TENANT's block, so each tenant's
// forwards carry that tenant's identity.
func forwardMessage(raw []byte, recipient string, entry routingEntry, tc tenantConfig) error {
	outgoing, envelopeSender := buildForwardMessage(raw, recipient, entry, tc, time.Now().UTC())

	args := []string{"-i", "-f", envelopeSender, "--"}
	args = append(args, entry.Destinations...)
	cmd := exec.Command(sendmailPath, args...)
	cmd.Stdin = bytes.NewReader([]byte(outgoing))
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("sendmail forward failed: %v: %s", err, strings.TrimSpace(string(out)))
	}
	return nil
}

// buildForwardMessage is a byte-for-byte port of
// InboundEmailRouter::buildForwardMessage (InboundEmailRouter.php:1387-1430). It
// returns the rewritten raw MIME (CRLF) and the envelope sender. Kept as a pure
// function so the Go test suite can assert parity against the PHP output.
func buildForwardMessage(raw []byte, originalTo string, entry routingEntry, tc tenantConfig, now time.Time) (string, string) {
	fromFull := parseFromHeader(raw)         // parsed['from']  (unfolded, first occurrence)
	fromEmail := extractFromEmail(fromFull)  // parsed['from_email']

	// SRS envelope sender: rewrite the From-header address (matching the PHP,
	// which SRS-rewrites parsed['from_email'], not the SMTP MAIL FROM).
	envelopeSender := fromEmail
	if tc.SRSSecret != "" {
		envelopeSender = srsRewrite(fromEmail, entry.ForwardingDomain, tc.SRSSecret, now)
	}

	fromDisplay := forwardedFromDisplay(extractName(fromFull), tc.ForwardFromName, tc.ForwardShowVia)

	normalized := strings.ReplaceAll(string(raw), "\r\n", "\n")

	// Split into header block and body at the first blank line.
	var headerBlock, bodyBlock string
	if idx := strings.Index(normalized, "\n\n"); idx == -1 {
		headerBlock = normalized
		bodyBlock = ""
	} else {
		headerBlock = normalized[:idx]
		bodyBlock = normalized[idx+2:]
	}

	// Replace From with the verified sender; strip any existing Reply-To.
	headerBlock = fromLineRe.ReplaceAllString(headerBlock, "From: "+fromDisplay+" <"+entry.ForwardFrom+">")
	headerBlock = replyToLineRe.ReplaceAllString(headerBlock, "")

	extra := "Reply-To: " + fromEmail + "\n" +
		"X-Original-To: " + originalTo + "\n" +
		"X-Forwarded-For: " + originalTo + "\n" +
		"X-Forwarded-By: Joinery Inbound Email"

	headerBlock = phpTrim(headerBlock) + "\n" + extra

	modifiedHeader := strings.ReplaceAll(headerBlock, "\n", "\r\n")
	modifiedBody := strings.ReplaceAll(bodyBlock, "\n", "\r\n")
	return modifiedHeader + "\r\n\r\n" + modifiedBody, envelopeSender
}

// parseFromHeader returns the unfolded value of the first From header, mirroring
// InboundEmailRouter::parseEmail's header folding (continuation lines starting
// with whitespace are appended with a single space to the current header).
func parseFromHeader(raw []byte) string {
	normalized := strings.ReplaceAll(string(raw), "\r\n", "\n")
	headerBlock := normalized
	if idx := strings.Index(normalized, "\n\n"); idx != -1 {
		headerBlock = normalized[:idx]
	}
	var from string
	haveFrom := false
	curKey := ""
	for _, line := range strings.Split(headerBlock, "\n") {
		if len(line) > 0 && (line[0] == ' ' || line[0] == '\t') && curKey != "" {
			if curKey == "from" && haveFrom {
				from += " " + strings.TrimSpace(line)
			}
			continue
		}
		colon := strings.IndexByte(line, ':')
		if colon < 0 {
			continue
		}
		curKey = strings.ToLower(strings.TrimSpace(line[:colon]))
		val := strings.TrimSpace(line[colon+1:])
		if curKey == "from" && !haveFrom {
			from = val
			haveFrom = true
		}
	}
	return from
}

// extractFromEmail mirrors parseEmail: the address inside <...>, else the whole
// From value.
func extractFromEmail(from string) string {
	if m := angleAddrRe.FindStringSubmatch(from); m != nil {
		return m[1]
	}
	return from
}

// extractName mirrors InboundEmailRouter::extractName.
func extractName(fromHeader string) string {
	if m := fromNameRe.FindStringSubmatch(fromHeader); m != nil {
		return strings.TrimSpace(m[1])
	}
	return ""
}

// forwardedFromDisplay mirrors InboundEmailRouter::forwardedFromDisplay.
func forwardedFromDisplay(name, site string, showVia bool) string {
	if site == "" {
		site = "Inbound Email"
	}
	if !showVia {
		if name != "" {
			return name
		}
		return "Forwarded"
	}
	if name != "" {
		return name + " via " + site
	}
	return "Forwarded via " + site
}

// phpTrim strips the exact byte set PHP's trim() strips (" \t\n\r\0\x0B") from
// both ends — narrower than strings.TrimSpace, so header parity is byte-exact.
func phpTrim(s string) string {
	return strings.Trim(s, " \t\n\r\x00\x0B")
}
