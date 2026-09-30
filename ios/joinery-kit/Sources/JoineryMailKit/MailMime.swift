import Foundation

/// An RFC 5322 / MIME message parser — a port of the browser's
/// `mailbox_mime.js` (specs/fortress_mobile_apps.md § R7, WP7).
///
/// A message sealed at the relay reaches the server as ciphertext it cannot
/// read, so the first of the owner's devices to open it does the parse the
/// server would have done: headers, bodies and attachments, sealed back under
/// the row's DEK. `parse` never throws: a part that cannot be read becomes an
/// opaque attachment or is dropped.
///
/// What it understands, as the browser does: header folding; CRLF and bare-LF
/// line endings; RFC 2047 encoded words (B and Q, adjacent words joined);
/// multipart nested to `maxDepth` (deeper is kept whole as an attachment);
/// base64, quoted-printable and the identity encodings; RFC 2231 parameters and
/// RFC 2047-in-quotes filenames. mimePart numbers follow IMAP ('1' for a single
/// part; a multipart's children count from 1) — an attachment's AD is built
/// from them. message/rfc822 is not descended into. Charsets decode as the
/// WHATWG Encoding standard labels them (a declared iso-8859-1 is windows-1252),
/// as TextDecoder does. The vectors in plugins/mailbox/tests/fixtures/mime/ are
/// the oracle.
public enum MailMime {
    public static let maxDepth = 30
    static let CR: UInt8 = 13, LF: UInt8 = 10, SP: UInt8 = 32, TAB: UInt8 = 9, DASH: UInt8 = 45, EQ: UInt8 = 61

    public struct Attachment: Equatable {
        public let filename: String
        public let contentType: String
        public let contentID: String
        public let mimePart: String
        public let inline: Bool
        public let bytes: Data
    }

    public struct Parsed: Equatable {
        public var headers = "", from = "", to = "", cc = "", subject = "", date = ""
        public var messageID = "", inReplyTo = "", references = ""
        public var textPlain = "", textHTML = ""
        public var attachments: [Attachment] = []
    }

    // MARK: Bytes → text

    static let charsetAliases: [String: String] = [
        "utf8": "utf-8", "utf-8": "utf-8", "unicode-1-1-utf-8": "utf-8",
        "ascii": "us-ascii", "us_ascii": "us-ascii", "ansi_x3.4-1968": "us-ascii",
        "latin1": "iso-8859-1", "latin-1": "iso-8859-1", "iso8859-1": "iso-8859-1",
        "iso_8859-1": "iso-8859-1", "iso-latin-1": "iso-8859-1",
        "cp1252": "windows-1252", "win-1252": "windows-1252",
        "ks_c_5601-1987": "euc-kr", "x-sjis": "shift_jis", "sjis": "shift_jis",
        "gb2312": "gbk", "x-gbk": "gbk", "cp936": "gbk", "big5-hkscs": "big5",
    ]

    /// Labels the WHATWG Encoding standard decodes as windows-1252.
    static let windows1252Labels: Set<String> = [
        "ansi_x3.4-1968", "ascii", "cp1252", "cp819", "csisolatin1", "ibm819", "iso-8859-1", "iso-ir-100",
        "iso8859-1", "iso88591", "iso_8859-1", "iso_8859-1:1987", "l1", "latin1", "us-ascii", "windows-1252",
        "x-cp1252",
    ]

    /// A decoder for a label, nil when unknown (the caller falls back to UTF-8).
    static func encoding(for label: String) -> String.Encoding? {
        if label == "utf-8" || label == "utf8" { return .utf8 }
        if windows1252Labels.contains(label) { return .windowsCP1252 }
        if label == "utf-16le" || label == "utf-16" { return .utf16LittleEndian }
        if label == "utf-16be" { return .utf16BigEndian }
        // gbk and gb2312 decode as GB 18030 (a superset), as TextDecoder does.
        let iana = (label == "gbk" || label == "gb2312") ? "gb18030" : label
        let cf = CFStringConvertIANACharSetNameToEncoding(iana as CFString)
        guard cf != kCFStringEncodingInvalidId else { return nil }
        return String.Encoding(rawValue: CFStringConvertEncodingToNSStringEncoding(cf))
    }

    static func hasHighBytes<C: Collection>(_ b: C) -> Bool where C.Element == UInt8 { b.contains { $0 > 127 } }

    static func isValidUTF8<C: Collection>(_ b: C) -> Bool where C.Element == UInt8 {
        String(bytes: b, encoding: .utf8) != nil
    }

    static func utf8Lenient<C: Collection>(_ b: C) -> String where C.Element == UInt8 {
        String(decoding: Array(b), as: UTF8.self)
    }

    /// Bytes to a string under a charset label; an unknown label is UTF-8.
    static func decodeText<C: Collection>(_ bytes: C, _ charset: String) -> String where C.Element == UInt8 {
        var label = charset.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if label.hasPrefix("\"") || label.hasPrefix("'") { label.removeFirst() }
        if label.hasSuffix("\"") || label.hasSuffix("'") { label.removeLast() }
        label = charsetAliases[label] ?? label
        if label.isEmpty || label == "us-ascii" {
            if !hasHighBytes(bytes) { return latin1(bytes) }
            label = isValidUTF8(bytes) ? "utf-8" : "windows-1252"
        }
        guard let enc = encoding(for: label) else { return utf8Lenient(bytes) }
        if enc == .utf8 { return utf8Lenient(bytes) }
        if enc == .windowsCP1252 { return windows1252(bytes) }
        return String(bytes: bytes, encoding: enc) ?? utf8Lenient(bytes)
    }

    /// WHATWG windows-1252: the five undefined bytes map to C1 controls.
    static func windows1252<C: Collection>(_ bytes: C) -> String where C.Element == UInt8 {
        let high: [UInt32] = [0x20AC, 0x81, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160,
                              0x2039, 0x0152, 0x8D, 0x017D, 0x8F, 0x90, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022,
                              0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x9D, 0x017E, 0x0178]
        var s = String.UnicodeScalarView()
        for b in bytes {
            let v: UInt32 = (b >= 0x80 && b <= 0x9F) ? high[Int(b) - 0x80] : UInt32(b)
            s.append(Unicode.Scalar(v)!)
        }
        return String(s)
    }

    /// Each byte as one character: for ASCII syntax that must not be re-coded.
    static func latin1<C: Collection>(_ bytes: C) -> String where C.Element == UInt8 {
        var s = String.UnicodeScalarView()
        for b in bytes { s.append(Unicode.Scalar(b)) }
        return String(s)
    }

    /// A header block's text: UTF-8 when it is valid UTF-8 (RFC 6532), else 1252.
    static func headerText(_ bytes: ArraySlice<UInt8>) -> String {
        if !hasHighBytes(bytes) { return latin1(bytes) }
        return isValidUTF8(bytes) ? utf8Lenient(bytes) : windows1252(bytes)
    }

    static func latin1Bytes(_ s: String) -> [UInt8] { s.unicodeScalars.map { UInt8(truncatingIfNeeded: $0.value) } }

    // MARK: Transfer encodings

    static let b64: [Int16] = {
        var t = [Int16](repeating: -1, count: 256)
        for (i, c) in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/".utf8.enumerated() { t[Int(c)] = Int16(i) }
        t[Int(UInt8(ascii: "-"))] = 62   // the URL-safe alphabet, seen in the wild
        t[Int(UInt8(ascii: "_"))] = 63
        return t
    }()

    /// Base64 to bytes: whitespace and characters outside the alphabet skipped;
    /// '=' closes the current quantum, so concatenated padded blocks still decode.
    static func decodeBase64<C: Collection>(_ src: C) -> [UInt8] where C.Element == UInt8 {
        var out: [UInt8] = []
        out.reserveCapacity(src.count * 3 / 4 + 3)
        var acc = 0, n = 0
        for c in src {
            if c == EQ {
                if n == 2 { out.append(UInt8((acc >> 4) & 255)) }
                else if n == 3 { out.append(UInt8((acc >> 10) & 255)); out.append(UInt8((acc >> 2) & 255)) }
                acc = 0; n = 0
                continue
            }
            let v = b64[Int(c)]
            if v < 0 { continue }
            acc = (acc << 6) | Int(v)
            n += 1
            if n == 4 {
                out.append(UInt8((acc >> 16) & 255)); out.append(UInt8((acc >> 8) & 255)); out.append(UInt8(acc & 255))
                acc = 0; n = 0
            }
        }
        if n == 2 { out.append(UInt8((acc >> 4) & 255)) }
        else if n == 3 { out.append(UInt8((acc >> 10) & 255)); out.append(UInt8((acc >> 2) & 255)) }
        return out
    }

    static func hexVal(_ c: UInt8) -> Int {
        if c >= 48 && c <= 57 { return Int(c) - 48 }
        if c >= 65 && c <= 70 { return Int(c) - 55 }
        if c >= 97 && c <= 102 { return Int(c) - 87 }
        return -1
    }

    /// Quoted-printable to bytes: =XX, soft line breaks, and the trailing
    /// whitespace RFC 2045 says a decoder removes. A stray '=' stays itself.
    static func decodeQuotedPrintable(_ s: ArraySlice<UInt8>) -> [UInt8] {
        let src = Array(s)
        var out: [UInt8] = []
        out.reserveCapacity(src.count)
        var lineStart = 0
        var i = 0
        func trimLine() { while out.count > lineStart, out.last == SP || out.last == TAB { out.removeLast() } }
        while i < src.count {
            let c = src[i]
            if c == EQ {
                let h1 = i + 1 < src.count ? hexVal(src[i + 1]) : -1
                let h2 = i + 2 < src.count ? hexVal(src[i + 2]) : -1
                if h1 >= 0 && h2 >= 0 { out.append(UInt8((h1 << 4) | h2)); i += 3; continue }
                var j = i + 1
                while j < src.count && (src[j] == SP || src[j] == TAB) { j += 1 }
                if j >= src.count { i = j + 1; continue }
                if src[j] == CR && j + 1 < src.count && src[j + 1] == LF { i = j + 2; lineStart = out.count; continue }
                if src[j] == LF { i = j + 1; lineStart = out.count; continue }
                out.append(c)
                i += 1
                continue
            }
            if c == CR || c == LF {
                trimLine()
                out.append(c)
                if c == CR && i + 1 < src.count && src[i + 1] == LF { out.append(LF); i += 1 }
                lineStart = out.count
                i += 1
                continue
            }
            out.append(c)
            i += 1
        }
        trimLine()
        return out
    }

    static func decodeTransfer(_ bytes: ArraySlice<UInt8>, _ cte: String?) -> [UInt8] {
        let enc = (cte ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if enc == "base64" { return decodeBase64(bytes) }
        if enc == "quoted-printable" { return decodeQuotedPrintable(bytes) }
        return Array(bytes)
    }

    // MARK: RFC 2047 encoded words

    static func qWordBytes(_ text: String) -> [UInt8] {
        var out: [UInt8] = []
        let u = Array(text.unicodeScalars)
        var i = 0
        while i < u.count {
            let ch = u[i]
            if ch == "_" { out.append(32); i += 1; continue }
            if ch == "=" && i + 2 < u.count, u[i + 1].isASCII, u[i + 2].isASCII {
                let h1 = hexVal(UInt8(u[i + 1].value)), h2 = hexVal(UInt8(u[i + 2].value))
                if h1 >= 0 && h2 >= 0 { out.append(UInt8((h1 << 4) | h2)); i += 3; continue }
            }
            if ch.value < 128 { out.append(UInt8(ch.value)) } else { out.append(contentsOf: Array(String(ch).utf8)) }
            i += 1
        }
        return out
    }

    static let encodedWord = try! NSRegularExpression(pattern: "=\\?([^?\\s]+)\\?([BbQq])\\?([^?]*)\\?=")

    /// Decode every encoded word; words separated only by whitespace are joined
    /// without it, and adjacent words in one charset decode as one byte run.
    static func decodeWords(_ value: String) -> String {
        guard value.contains("=?") else { return value }
        let ns = value as NSString
        var out = ""
        var last = 0
        var pending: (charset: String, bytes: [UInt8])? = nil
        func flush() {
            if let p = pending { out += decodeText(p.bytes, p.charset) }
            pending = nil
        }
        for m in encodedWord.matches(in: value, range: NSRange(location: 0, length: ns.length)) {
            let between = ns.substring(with: NSRange(location: last, length: m.range.location - last))
            let joined = pending != nil && between.unicodeScalars.allSatisfy { " \t\r\n".unicodeScalars.contains($0) }
            if !joined {
                flush()
                out += between
            }
            let charset = ns.substring(with: m.range(at: 1)).components(separatedBy: "*")[0].lowercased()
            let text = ns.substring(with: m.range(at: 3))
            let bytes = ns.substring(with: m.range(at: 2)).uppercased() == "B"
                ? decodeBase64(latin1Bytes(text)) : qWordBytes(text)
            if let p = pending, p.charset != charset { flush() }
            if pending == nil { pending = (charset, []) }
            pending!.bytes += bytes
            last = m.range.location + m.range.length
        }
        flush()
        out += ns.substring(from: last)
        return out
    }

    // MARK: Headers

    static let headerStart = try! NSRegularExpression(pattern: "^[!-9;-~]+[ \\t]*:")
    static let headerName = try! NSRegularExpression(pattern: "^[!-9;-~]+[ \\t]*$")

    static func matches(_ re: NSRegularExpression, _ s: String) -> Bool {
        re.firstMatch(in: s, range: NSRange(location: 0, length: (s as NSString).length)) != nil
    }

    static func indexOfByte(_ bytes: [UInt8], _ b: UInt8, _ from: Int, _ end: Int) -> Int {
        var i = from
        while i < end { if bytes[i] == b { return i }; i += 1 }
        return -1
    }

    /// Where an entity's header block ends and its body starts.
    static func splitHeaders(_ bytes: [UInt8], _ start: Int, _ end: Int) -> (headerEnd: Int, bodyStart: Int) {
        let firstNl = indexOfByte(bytes, LF, start, end)
        let firstEnd = min(firstNl < 0 ? end : firstNl, start + 1000)
        let first = latin1(bytes[start..<max(start, firstEnd)])
        if !first.isEmpty && first != "\r" && !matches(headerStart, first) && !first.hasPrefix("From ") {
            return (start, start)
        }
        var i = start
        while i < end {
            if bytes[i] == LF { return (i, i + 1) }
            if bytes[i] == CR && i + 1 < end && bytes[i + 1] == LF { return (i, i + 2) }
            if bytes[i] == CR && i + 1 == end { return (i, end) }
            let nl = indexOfByte(bytes, LF, i, end)
            if nl < 0 { return (end, end) }
            i = nl + 1
        }
        return (end, end)
    }

    struct Field { let name: String; var value: String }

    /// Header text to fields (lowercase name, unfolded raw value), in order.
    static func parseHeaderFields(_ text: String) -> [Field] {
        var fields: [Field] = []
        var cur: Int? = nil
        for var line in text.components(separatedBy: "\n") {
            if line.hasSuffix("\r") { line.removeLast() }
            if line.isEmpty { continue }
            let first = line.unicodeScalars.first!
            if (first == " " || first == "\t"), let c = cur {
                fields[c].value += line
                continue
            }
            guard let colon = line.firstIndex(of: ":"), colon != line.startIndex,
                  matches(headerName, String(line[..<colon])) else {
                cur = nil   // an mbox "From " line or plain garbage
                continue
            }
            fields.append(Field(name: line[..<colon].trimmingCharacters(in: .whitespaces).lowercased(),
                                value: String(line[line.index(after: colon)...])))
            cur = fields.count - 1
        }
        return fields
    }

    static func firstField(_ fields: [Field], _ name: String) -> String? {
        let n = name.lowercased()
        return fields.first { $0.name == n }.map { jsTrim($0.value) }
    }

    /// JavaScript's String.prototype.trim (Unicode white space and line terminators).
    static func jsTrim(_ s: String) -> String {
        let ws: (Unicode.Scalar) -> Bool = { ($0.properties.isWhitespace && $0 != "\u{85}") || $0 == "\u{FEFF}" }
        var u = Array(s.unicodeScalars)
        while let f = u.first, ws(f) { u.removeFirst() }
        while let l = u.last, ws(l) { u.removeLast() }
        return String(String.UnicodeScalarView(u))
    }

    static func splitOutsideQuotes(_ s: String, _ delim: Character) -> [String] {
        var parts: [String] = []
        var cur = ""
        var inQ = false
        var it = s.makeIterator()
        while let ch = it.next() {
            if inQ {
                cur.append(ch)
                if ch == "\\", let nx = it.next() { cur.append(nx) }
                else if ch == "\"" { inQ = false }
                continue
            }
            if ch == "\"" { inQ = true; cur.append(ch); continue }
            if ch == delim { parts.append(cur); cur = ""; continue }
            cur.append(ch)
        }
        parts.append(cur)
        return parts
    }

    static func unescapeQuoted(_ v: String) -> String {
        var out = ""
        var it = v.makeIterator()
        while let ch = it.next() {
            if ch == "\\", let nx = it.next() { out.append(nx) } else { out.append(ch) }
        }
        return out
    }

    static func unquote(_ raw: String) -> String {
        let v = jsTrim(raw)
        if v.count >= 2 && v.hasPrefix("\"") && v.hasSuffix("\"") { return unescapeQuoted(String(v.dropFirst().dropLast())) }
        if v.hasPrefix("\"") { return unescapeQuoted(String(v.dropFirst())) }   // unterminated: take the rest
        return v
    }

    static func percentBytes(_ s: String) -> [UInt8] {
        var out: [UInt8] = []
        let u = Array(s.unicodeScalars)
        var i = 0
        while i < u.count {
            let c = u[i]
            if c == "%" && i + 2 < u.count, u[i + 1].isASCII, u[i + 2].isASCII {
                let h1 = hexVal(UInt8(u[i + 1].value)), h2 = hexVal(UInt8(u[i + 2].value))
                if h1 >= 0 && h2 >= 0 { out.append(UInt8((h1 << 4) | h2)); i += 3; continue }
            }
            if c.value < 128 { out.append(UInt8(c.value)) } else { out.append(contentsOf: Array(String(c).utf8)) }
            i += 1
        }
        return out
    }

    static let extKey = try! NSRegularExpression(pattern: "^([^*]+)\\*(?:(\\d+)\\*?)?$")
    static let comment = try! NSRegularExpression(pattern: "\\([^)]*\\)")

    /// "type/sub; a=b; c*0*=..." → (lowercased value, decoded params), with
    /// RFC 2231 continuations and charsets assembled and RFC 2047 words decoded.
    static func parseStructured(_ raw: String) -> (value: String, params: [String: String]) {
        let pieces = splitOutsideQuotes(raw, ";")
        let p0 = pieces[0] as NSString
        let value = jsTrim(comment.stringByReplacingMatches(in: pieces[0], range: NSRange(location: 0, length: p0.length),
                                                            withTemplate: "")).lowercased()
        var plain: [String: String] = [:]
        var ext: [String: [(index: Int, encoded: Bool, value: String)]] = [:]
        for p in pieces.dropFirst() {
            guard let eq = p.firstIndex(of: "="), eq != p.startIndex else { continue }
            let key = jsTrim(String(p[..<eq])).lowercased()
            let uq = unquote(String(p[p.index(after: eq)...]))
            let kns = key as NSString
            if key.contains("*"), let m = extKey.firstMatch(in: key, range: NSRange(location: 0, length: kns.length)) {
                let base = kns.substring(with: m.range(at: 1))
                let idx = m.range(at: 2).location == NSNotFound ? 0 : Int(kns.substring(with: m.range(at: 2))) ?? 0
                ext[base, default: []].append((idx, key.hasSuffix("*"), uq))
            } else if plain[key] == nil {
                plain[key] = decodeWords(uq)
            }
        }
        var params = plain
        for (k, raw) in ext {
            let segs = raw.enumerated().sorted { $0.element.index != $1.element.index
                ? $0.element.index < $1.element.index : $0.offset < $1.offset }.map(\.element)
            var charset = ""
            var bytes: [UInt8] = []
            var anyEncoded = false
            for (s, seg) in segs.enumerated() {
                var v = seg.value
                if seg.encoded {
                    anyEncoded = true
                    if s == 0, let q1 = v.firstIndex(of: "'"),
                       let q2 = v[v.index(after: q1)...].firstIndex(of: "'") {
                        charset = String(v[..<q1])
                        v = String(v[v.index(after: q2)...])
                    }
                    bytes += percentBytes(v)
                } else {
                    bytes += Array(v.utf8)
                }
            }
            let text = decodeText(bytes, anyEncoded ? (charset.isEmpty ? "utf-8" : charset) : "utf-8")
            params[k] = anyEncoded ? text : decodeWords(text)
        }
        return (value, params)
    }

    // MARK: Multipart

    /// The multipart body's parts as [start, end) ranges; nil when no delimiter appears.
    static func splitMultipart(_ bytes: [UInt8], _ start: Int, _ end: Int, _ boundary: String) -> [(Int, Int)]? {
        let delim = latin1Bytes("--" + boundary)
        let dl = delim.count
        var ranges: [(Int, Int)] = []
        var partStart = -1
        var found = false
        var i = start
        while i + dl <= end {
            var match = true
            for k in 0..<dl where bytes[i + k] != delim[k] { match = false; break }
            let nl = indexOfByte(bytes, LF, i, end)
            let lineEnd = nl < 0 ? end : nl
            if match {
                var j = i + dl
                var close = false
                if j + 1 < lineEnd && bytes[j] == DASH && bytes[j + 1] == DASH { close = true; j += 2 }
                var rest = true
                var r = j
                while r < lineEnd {
                    let c = bytes[r]
                    if c != SP && c != TAB && c != CR { rest = false; break }
                    r += 1
                }
                if rest {
                    found = true
                    if partStart >= 0 {
                        var pe = i
                        if pe > partStart && bytes[pe - 1] == LF { pe -= 1 }
                        if pe > partStart && bytes[pe - 1] == CR { pe -= 1 }
                        ranges.append((partStart, max(partStart, pe)))
                    }
                    if close { return ranges }
                    partStart = nl < 0 ? end : nl + 1
                }
            }
            if nl < 0 { break }
            i = nl + 1
        }
        if !found { return nil }
        if partStart >= 0 && partStart < end { ranges.append((partStart, end)) }
        return ranges
    }

    // MARK: The walk

    struct Leaf {
        var type: String, charset: String, disposition: String
        var hasName: Bool, filename: String, contentID: String, mimePart: String, inRelated: Bool
        var bytes: [UInt8]
    }

    static let typeShape = try! NSRegularExpression(pattern: "^[^/\\s]+/[^/\\s]+$")
    static let cidTrim = try! NSRegularExpression(pattern: "^[\\s<]+|[\\s>]+$")

    static func walk(_ bytes: [UInt8], _ start: Int, _ end: Int, _ partNo: String, _ depth: Int,
                     _ ancestors: [String], _ leaves: inout [Leaf]) {
        let split = splitHeaders(bytes, start, end)
        let fields = parseHeaderFields(headerText(bytes[start..<split.headerEnd]))
        let parent = ancestors.last ?? ""

        let ctRaw = firstField(fields, "content-type")
        var ct = parseStructured(ctRaw ?? "")
        var type = ct.value
        if ctRaw == nil || !matches(typeShape, type) {
            type = (ctRaw == nil && parent == "digest") ? "message/rfc822" : "text/plain"
            if (ct.params["charset"] ?? "").isEmpty { ct.params["charset"] = "us-ascii" }
        }

        if type.hasPrefix("multipart/") && depth < maxDepth {
            let ranges = ct.params["boundary"].flatMap { splitMultipart(bytes, split.bodyStart, end, $0) }
            if let ranges {
                let inner = ancestors + [String(type.dropFirst(10))]
                for (i, r) in ranges.enumerated() {
                    let childNo = partNo.isEmpty ? String(i + 1) : partNo + "." + String(i + 1)
                    walk(bytes, r.0, r.1, childNo, depth + 1, inner, &leaves)
                }
                return
            }
            // No boundary, or none present: show the body as text.
            type = "text/plain"
        }

        let disp = parseStructured(firstField(fields, "content-disposition") ?? "")
        let rawCid = firstField(fields, "content-id") ?? ""
        let cid = cidTrim.stringByReplacingMatches(in: rawCid, range: NSRange(location: 0, length: (rawCid as NSString).length),
                                                   withTemplate: "")
        let dispName = disp.params["filename"] ?? ""
        let ctName = ct.params["name"] ?? ""
        var filename = !dispName.isEmpty ? dispName : ctName
        if filename.isEmpty && (type == "message/rfc822" || type == "message/global") { filename = "message.eml" }
        leaves.append(Leaf(type: type, charset: ct.params["charset"] ?? "", disposition: disp.value,
                           hasName: !dispName.isEmpty || !ctName.isEmpty, filename: filename, contentID: cid,
                           mimePart: partNo.isEmpty ? "1" : partNo, inRelated: ancestors.contains("related"),
                           bytes: decodeTransfer(bytes[split.bodyStart..<max(split.bodyStart, end)],
                                                 firstField(fields, "content-transfer-encoding"))))
    }

    public static func parse(_ data: Data) -> Parsed {
        let bytes = [UInt8](data)
        var result = Parsed()
        let split = splitHeaders(bytes, 0, bytes.count)
        result.headers = headerText(bytes[0..<split.headerEnd])
        let fields = parseHeaderFields(result.headers)
        func dec(_ n: String) -> String { firstField(fields, n).map { jsTrim(decodeWords($0)) } ?? "" }
        func raw(_ n: String) -> String { firstField(fields, n) ?? "" }
        result.from = dec("from")
        result.to = dec("to")
        result.cc = dec("cc")
        result.subject = dec("subject")
        result.date = raw("date")
        result.messageID = raw("message-id")
        result.inReplyTo = raw("in-reply-to")
        result.references = raw("references")

        var leaves: [Leaf] = []
        walk(bytes, 0, bytes.count, "", 0, [], &leaves)

        var plainAt = -1, htmlAt = -1
        for (i, l) in leaves.enumerated() {
            if l.disposition == "attachment" || l.hasName { continue }
            if plainAt < 0 && l.type == "text/plain" { plainAt = i }
            else if htmlAt < 0 && l.type == "text/html" { htmlAt = i }
        }
        for (j, leaf) in leaves.enumerated() {
            if j == plainAt { result.textPlain = decodeText(leaf.bytes, leaf.charset); continue }
            if j == htmlAt { result.textHTML = decodeText(leaf.bytes, leaf.charset); continue }
            result.attachments.append(Attachment(
                filename: leaf.filename, contentType: leaf.type, contentID: leaf.contentID, mimePart: leaf.mimePart,
                inline: leaf.disposition == "inline" || (!leaf.contentID.isEmpty && leaf.inRelated),
                bytes: Data(leaf.bytes)))
        }
        return result
    }

    /// The first top-level header called `name`, unfolded and decoded, or ''.
    public static func headerValue(_ parsed: Parsed, _ name: String) -> String {
        firstField(parseHeaderFields(parsed.headers), name).map { jsTrim(decodeWords($0)) } ?? ""
    }
}
