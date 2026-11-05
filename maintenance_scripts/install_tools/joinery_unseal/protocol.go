package main

// The wire: one frame per request and per reply.
//
//	frame  = u32be(len(header)) ‖ header ‖ u32be(len(body)) ‖ body
//	header = one JSON object
//	body   = the parts, concatenated; header.parts lists their lengths
//
// Whatever is bytes (a KEK, a sealed blob, a plaintext, a public key) is a
// part, so nothing is base64-encoded on the wire and a multi-megabyte held
// Direct part costs one copy per side. The header is small and parsed by the
// standard library into a fixed struct that refuses unknown fields.
//
// The daemon never exits on anything that arrives here: a frame it cannot
// read is a reply (or, past the length prefixes, a closed connection), never
// a crash. FuzzServeStream holds it to that.

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
)

const (
	maxHeaderBytes = 64 << 10
	maxParts       = 4096
)

// opError is a reply's error code. The codes are the contract with PHP
// (UnsealClient, WP4); the text beside each is for a log, never a secret.
type opError struct {
	code    string
	message string
}

func (e *opError) Error() string { return e.code + ": " + e.message }

var (
	errBadRequest    = &opError{"bad_request", "the request is malformed"}
	errTooLarge      = &opError{"too_large", "the frame is over the size cap"}
	errBusy          = &opError{"busy", "the daemon is at its in-flight limit; retry"}
	errUnknownOp     = &opError{"unknown_op", "no such operation"}
	errUnknownHandle = &opError{"unknown_handle", "no such window (closed, expired, or the daemon restarted)"}
	errUnlockFailed  = &opError{"unlock_failed", "the unlocker does not open the wrapping"}
	errUnsealFailed  = &opError{"unseal_failed", "a sealed blob does not open with this window's key"}
	errHeld          = &opError{"held", "the vault service is held by a security case"}
	errAtCapacity    = &opError{"at_capacity", "the daemon holds its maximum number of windows"}
	errInternal      = &opError{"internal", "the daemon could not complete the request"}
)

// request is every field any operation reads. An operation checks that the
// fields it does not read are empty, so a request means one thing.
type request struct {
	Op       string  `json:"op"`
	Handle   string  `json:"handle,omitempty"`
	User     int64   `json:"user,omitempty"`
	Scope    string  `json:"scope,omitempty"`
	Idle     int64   `json:"idle,omitempty"`
	Absolute int64   `json:"absolute,omitempty"`
	Unlock   bool    `json:"unlock,omitempty"`
	Wrap     int     `json:"wrap,omitempty"`
	Reveal   int     `json:"reveal,omitempty"`
	Activity bool    `json:"activity,omitempty"`
	Parts    []int64 `json:"parts,omitempty"`
}

// errFrame is a stream that can no longer be read in step: the connection
// ends, because the next byte is not known to start a frame.
var errFrame = errors.New("the stream is out of frame")

func readU32(r io.Reader) (uint32, error) {
	var b [4]byte
	if _, err := io.ReadFull(r, b[:]); err != nil {
		return 0, err
	}
	return binary.BigEndian.Uint32(b[:]), nil
}

// readFrame reads one frame's header and body. reserve is asked for the body
// before it is allocated, so a burst of large requests waits for nothing and
// allocates nothing past the in-flight budget; release hands it back.
// io.EOF before the first byte is a clean end of the connection.
func readFrame(r io.Reader, maxBody int64, reserve func(int64) bool, release func(int64)) (hdr []byte, body []byte, oerr *opError, err error) {
	hlen, err := readU32(r)
	if err != nil {
		return nil, nil, nil, err
	}
	if hlen == 0 || hlen > maxHeaderBytes {
		return nil, nil, nil, errFrame
	}
	hdr = make([]byte, hlen)
	if _, err := io.ReadFull(r, hdr); err != nil {
		return nil, nil, nil, errFrame
	}
	blen, err := readU32(r)
	if err != nil {
		return nil, nil, nil, errFrame
	}
	if int64(blen) > maxBody {
		return nil, nil, errTooLarge, errFrame
	}
	if blen > 0 && !reserve(int64(blen)) {
		return nil, nil, errBusy, errFrame
	}
	body = make([]byte, blen)
	if _, err := io.ReadFull(r, body); err != nil {
		zero(body)
		release(int64(blen))
		return nil, nil, nil, errFrame
	}
	return hdr, body, nil, nil
}

// parseRequest decodes the header strictly and splits the body into parts.
func parseRequest(hdr, body []byte) (*request, [][]byte, *opError) {
	dec := json.NewDecoder(bytes.NewReader(hdr))
	dec.DisallowUnknownFields()
	var req request
	if err := dec.Decode(&req); err != nil {
		return nil, nil, errBadRequest
	}
	if _, err := dec.Token(); err != io.EOF {
		return nil, nil, errBadRequest // trailing data after the object
	}
	if len(req.Parts) > maxParts {
		return nil, nil, errBadRequest
	}
	parts := make([][]byte, 0, len(req.Parts))
	var at int64
	for _, n := range req.Parts {
		if n < 0 || n > int64(len(body))-at {
			return nil, nil, errBadRequest
		}
		parts = append(parts, body[at:at+n])
		at += n
	}
	if at != int64(len(body)) {
		return nil, nil, errBadRequest
	}
	return &req, parts, nil
}

// writeFrame writes one reply. fields is the header; parts the body.
func writeFrame(w io.Writer, fields map[string]any, parts [][]byte) error {
	lens := make([]int64, len(parts))
	total := 0
	for i, p := range parts {
		lens[i] = int64(len(p))
		total += len(p)
	}
	if len(parts) > 0 {
		fields["parts"] = lens
	}
	hdr, err := json.Marshal(fields)
	if err != nil {
		return err
	}
	buf := make([]byte, 0, 8+len(hdr)+total)
	buf = binary.BigEndian.AppendUint32(buf, uint32(len(hdr)))
	buf = append(buf, hdr...)
	buf = binary.BigEndian.AppendUint32(buf, uint32(total))
	for _, p := range parts {
		buf = append(buf, p...)
	}
	_, err = w.Write(buf)
	zero(buf)
	return err
}

func errorReply(e *opError) map[string]any {
	return map[string]any{"ok": false, "error": e.code, "message": e.message}
}
