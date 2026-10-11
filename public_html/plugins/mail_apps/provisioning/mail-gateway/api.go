package main

import (
	"context"
	"sort"
	"strconv"
	"strings"
	"time"
)

// The shapes of the site's mail_apps/mail_* answers (the LogicResult::render
// arrays in plugins/mail_apps/logic/).

type apiFolder struct {
	Key        string  `json:"key"`
	Name       string  `json:"name"`
	SpecialUse *string `json:"special_use"`
}

type loginResult struct {
	Mailbox   string      `json:"mailbox"`
	Level     string      `json:"level"`
	Locked    bool        `json:"locked"`
	UnlockURL string      `json:"unlock_url"`
	Folders   []apiFolder `json:"folders"`
}

type foldersResult struct {
	Folders []apiFolder `json:"folders"`
}

type apiMessage struct {
	UID    uint32   `json:"uid"`
	Flags  []string `json:"flags"`
	ModSeq uint64   `json:"modseq"`
	Date   string   `json:"date"`
	Size   *int64   `json:"size"`
}

type syncResult struct {
	Folder        string       `json:"folder"`
	UIDValidity   uint32       `json:"uidvalidity"`
	UIDNext       uint32       `json:"uidnext"`
	HighestModSeq uint64       `json:"highest_modseq"`
	Exists        uint32       `json:"exists"`
	Unseen        uint32       `json:"unseen"`
	Locked        bool         `json:"locked"`
	Waiting       bool         `json:"waiting"`
	Messages      []apiMessage `json:"messages"`
	Vanished      []uint32     `json:"vanished"`
}

type changesResult struct {
	Folder        string `json:"folder"`
	UIDValidity   uint32 `json:"uidvalidity"`
	UIDNext       uint32 `json:"uidnext"`
	HighestModSeq uint64 `json:"highest_modseq"`
	Exists        uint32 `json:"exists"`
	Changed       bool   `json:"changed"`
	Locked        bool   `json:"locked"`
}

// fetchedMessage: Bytes and Header arrive base64 in the JSON and decode
// straight to bytes (encoding/json decodes base64 into []byte), with no
// intermediate string copy of a large message.
type fetchedMessage struct {
	UID    uint32 `json:"uid"`
	Size   *int64 `json:"size"`
	Bytes  []byte `json:"bytes"`
	Header []byte `json:"header"`
	Error  string `json:"error"`
}

type fetchResult struct {
	Folder    string           `json:"folder"`
	Messages  []fetchedMessage `json:"messages"`
	More      []uint32         `json:"more"`
	UnlockURL string           `json:"unlock_url"`
}

type storeResult struct {
	Folder        string `json:"folder"`
	HighestModSeq uint64 `json:"highest_modseq"`
	Messages      []struct {
		UID    uint32   `json:"uid"`
		Flags  []string `json:"flags"`
		ModSeq uint64   `json:"modseq"`
	} `json:"messages"`
	Expunged []uint32 `json:"expunged"`
}

type placeResult struct {
	Folder          string      `json:"folder"`
	To              string      `json:"to"`
	UIDMap          [][2]uint32 `json:"uid_map"`
	DestUIDValidity uint32      `json:"dest_uidvalidity"`
}

type expungeResult struct {
	Folder        string   `json:"folder"`
	Expunged      []uint32 `json:"expunged"`
	HighestModSeq uint64   `json:"highest_modseq"`
}

type appendResult struct {
	Folder      string  `json:"folder"`
	UID         *uint32 `json:"uid"`
	UIDValidity uint32  `json:"uidvalidity"`
}

type folderCreateResult struct {
	Key  string `json:"key"`
	Name string `json:"name"`
}

type searchResult struct {
	Folder string   `json:"folder"`
	UIDs   []uint32 `json:"uids"`
}

type submitResult struct {
	MessageID string `json:"message_id"`
	Stored    bool   `json:"stored"`
	Warning   string `json:"warning"`
}

// uidSetStrings writes ascending, de-duplicated UIDs as IMAP sequence-set
// strings ("1:5,7,9:12"), split so no string exceeds the site's 65,536
// character input limit.
func uidSetStrings(uids []uint32) []string {
	if len(uids) == 0 {
		return nil
	}
	s := append([]uint32(nil), uids...)
	sort.Slice(s, func(i, j int) bool { return s[i] < s[j] })
	const limit = 60000
	var out []string
	var b strings.Builder
	flush := func() {
		if b.Len() > 0 {
			out = append(out, b.String())
			b.Reset()
		}
	}
	i := 0
	for i < len(s) {
		j := i
		for j+1 < len(s) && (s[j+1] == s[j] || s[j+1] == s[j]+1) {
			j++
		}
		piece := strconv.FormatUint(uint64(s[i]), 10)
		if s[j] != s[i] {
			piece += ":" + strconv.FormatUint(uint64(s[j]), 10)
		}
		if b.Len()+len(piece)+1 > limit {
			flush()
		}
		if b.Len() > 0 {
			b.WriteByte(',')
		}
		b.WriteString(piece)
		i = j + 1
	}
	flush()
	return out
}

// chunkUIDs splits ascending UIDs into batches of at most n.
func chunkUIDs(uids []uint32, n int) [][]uint32 {
	var out [][]uint32
	for len(uids) > n {
		out = append(out, uids[:n])
		uids = uids[n:]
	}
	if len(uids) > 0 {
		out = append(out, uids)
	}
	return out
}

func parseAPITime(s string) time.Time {
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t
	}
	return time.Unix(0, 0).UTC()
}

// callCtx is the context for upstream calls made on behalf of a connection.
func callCtx() context.Context { return context.Background() }
