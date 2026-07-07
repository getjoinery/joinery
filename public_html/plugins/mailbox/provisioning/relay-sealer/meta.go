package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"strings"
)

// spoolMeta is the cleartext sidecar written next to each sealed blob. It holds
// ONLY header-level operational metadata — never the subject, sender display,
// body, or attachments. Those do not exist as fields until deferred ingest
// unseals the blob in-session. This is exactly the set the pull consumer needs
// to make threading and unread counts work before parse.
type spoolMeta struct {
	SpoolID        string `json:"spool_id"`
	Recipient      string `json:"recipient"`
	EnvelopeSender string `json:"envelope_sender"`
	MessageID      string `json:"message_id"`
	InReplyTo      string `json:"in_reply_to"`
	References     string `json:"references"`
	Date           string `json:"date"`
	Size           int    `json:"size"`
	// EVERY Authentication-Results header, in document order. Milters PREPEND
	// their verdict, so the milter-stamped (trusted) lines are the earliest
	// entries; the main box takes the first authserv-matching verdict per method.
	// Keeping only the last line let a sender forge a lower A-R that won.
	AuthenticationResults []string `json:"authentication_results"`
	KeyKind               string   `json:"key_kind"`
	// The exact public key the blob was sealed to (public material, already in
	// the relay's routing map). For key_kind=user it lets the pull consumer
	// resolve the owning vault even after the alias's grants changed — an
	// ownerless pending row could never be drained.
	PublicKey   string `json:"public_key"`
	MapVersion  int64  `json:"map_version"`
	ReceivedUTC string `json:"received_utc"`
}

// extractMeta pulls the operational header set out of the raw RFC822 message. It
// reads only the header block (up to the first blank line) and unfolds continued
// header values. It returns the single-value headers (first occurrence wins) and,
// separately, ALL Authentication-Results headers in document order — the caller
// must not collapse those to one, or a forged lower A-R can beat the milter one.
func extractMeta(raw []byte) (map[string]string, []string) {
	headers := map[string]string{}
	var authResults []string
	scanner := bufio.NewScanner(bytes.NewReader(raw))
	// Headers can be long (References grows per reply); lift the token cap.
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	var curName string
	var curVal strings.Builder
	flush := func() {
		if curName == "" {
			return
		}
		key := strings.ToLower(curName)
		value := strings.TrimSpace(curVal.String())
		if key == "authentication-results" {
			authResults = append(authResults, value) // keep every line, in order
		} else if _, exists := headers[key]; !exists {
			headers[key] = value // first occurrence wins for single-value headers
		}
		curName = ""
		curVal.Reset()
	}

	for scanner.Scan() {
		line := scanner.Text()
		if line == "" {
			break // end of header block
		}
		if line[0] == ' ' || line[0] == '\t' {
			// Folded continuation of the previous header.
			curVal.WriteByte(' ')
			curVal.WriteString(strings.TrimSpace(line))
			continue
		}
		colon := strings.IndexByte(line, ':')
		if colon < 0 {
			continue
		}
		flush()
		curName = line[:colon]
		curVal.WriteString(strings.TrimSpace(line[colon+1:]))
	}
	flush()
	return headers, authResults
}

func (m *spoolMeta) marshal() ([]byte, error) {
	return json.MarshalIndent(m, "", "  ")
}
